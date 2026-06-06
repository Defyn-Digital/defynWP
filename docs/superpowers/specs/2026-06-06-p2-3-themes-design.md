# P2.3 — Themes Inventory + Themes Updates Design

**Status:** Approved (2026-06-06)
**Author:** Pradeep + Claude (brainstorming session)
**Predecessors:** `2026-06-04-p2-1-plugin-inventory-design.md`, `2026-06-06-p2-2-plugin-updates-design.md`
**Successor (planned):** P2.4 — WP core updates (separate phase)
**Foundation spec (canonical):** `2026-04-18-defyn-foundation-design.md`

---

## 1. Architecture overview

P2.3 ships per-site theme inventory + operator-triggered theme upgrades. It is a deliberate mirror of P2.1 (plugin inventory) + P2.2 (plugin updates) end-to-end — same patterns, same modules, same boundaries, retyped for themes. The only architectural divergences are: (a) themes carry `is_active` + `parent_slug` columns, (b) the active-theme upgrade carries higher real-world risk and the safety lives in the SPA confirmation UX, (c) the redundant `wp_defyn_sites.active_theme` LONGTEXT column is dropped because the new themes table makes it duplicative.

### 1.1 Request flow

```
SPA SiteThemesPanel
   │ TanStack Query: useSiteThemes (5min stale; 30s poll while any row is queued|updating)
   ▼
Dashboard REST  ──► GET /sites/{id}/themes              (Bearer JWT, owner-scoped)
                ──► POST /sites/{id}/themes/refresh     (Bearer JWT, owner-scoped, rate-limited)
                ──► POST /sites/{id}/themes/{slug}/update  (Bearer JWT, owner-scoped, rate-limited)
   │
   │ schedules AS jobs
   ▼
AS jobs  ──► defyn_refresh_site_themes  ──► SignedHttpClient ──sign──► Connector POST /themes/refresh
         ──► defyn_update_site_theme    ──► SignedHttpClient ──sign──► Connector POST /themes/{slug}/update
                                                                       (120-second HTTP timeout)
   │
   │ writes back via ThemesRepository
   ▼
SyncThemesService → wp_defyn_site_themes + activity log
```

### 1.2 Reused infrastructure (no rebuilding)

From F5/F6/P2.1/P2.2:

- `VerifySignatureMiddleware` (signing protocol, ±300s timestamp window, nonce store)
- `SignedHttpClient::signedPostJson()` + 120s `timeoutSeconds` ctor param
- `Vault::decryptPrivateKey($siteId)`
- `Signer::signRequest()`
- `ActivityLogger::log()`
- `ErrorResponse::create()` envelope shape
- `CapturingUpgraderSkin` (WP_Upgrader_Skin subclass that captures error() calls)
- `RestRouter` default `no-store` cache headers
- `NonceStore`
- Action Scheduler hook registration in `Plugin::boot`
- Schema self-heal hook from P2.2.1 (auto-runs the v3→v4 dbDelta on first `plugins_loaded` after upgrade)
- The `ob_start`/`ob_end_clean` STDOUT discipline on the connector controller (P2.2.1 regression)

### 1.3 Component additions summary

| Layer | New module | Mirrors |
|---|---|---|
| Connector | `Defyn\Connector\SiteInfo\ThemeListCollector` | `PluginListCollector` (P2.1) |
| Connector | `Defyn\Connector\Rest\ThemesController` | `PluginsController` (P2.1) |
| Connector | `Defyn\Connector\Rest\ThemeUpdateController` | `PluginUpdateController` (P2.2) |
| Connector | `Defyn\Connector\SiteInfo\ThemeUpgraderService` | `PluginUpgraderService` (P2.2) |
| Dashboard | `Defyn\Dashboard\Schema\SiteThemesTable` | `SitePluginsTable` (P2.1) |
| Dashboard | `Defyn\Dashboard\Models\Theme` | `Plugin` (P2.1) |
| Dashboard | `Defyn\Dashboard\Services\ThemesRepository` | `SitePluginsRepository` (P2.1+P2.2) |
| Dashboard | `Defyn\Dashboard\Services\SyncThemesService` | `SyncPluginsService` (P2.1+P2.2.1) |
| Dashboard | `Defyn\Dashboard\Jobs\RefreshSiteThemes` | `RefreshSitePlugins` (P2.1) |
| Dashboard | `Defyn\Dashboard\Jobs\UpdateSiteTheme` | `UpdateSitePlugin` (P2.2) |
| Dashboard | `Defyn\Dashboard\Rest\SitesThemesController` | `SitesPluginsController` (P2.1) |
| Dashboard | `Defyn\Dashboard\Rest\SitesThemesUpdateController` | `SitesPluginsUpdateController` (P2.2) |
| SPA | `useSiteThemes`, `useRefreshSiteThemes`, `useUpdateSiteTheme` hooks | plugin equivalents |
| SPA | `SiteThemesPanel`, `SiteThemeRow`, `ConfirmUpdateThemeDialog` | plugin equivalents |

---

## 2. Schema (v3 → v4 migration)

### 2.1 New table: `wp_defyn_site_themes`

```sql
CREATE TABLE wp_defyn_site_themes (
    id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    site_id                BIGINT UNSIGNED NOT NULL,
    slug                   VARCHAR(80)   NOT NULL,
    name                   VARCHAR(255)  NOT NULL,
    version                VARCHAR(50)   NULL,
    parent_slug            VARCHAR(80)   NULL,
    is_active              TINYINT(1)    NOT NULL DEFAULT 0,
    update_available       TINYINT(1)    NOT NULL DEFAULT 0,
    update_version         VARCHAR(50)   NULL,
    update_state           VARCHAR(20)   NOT NULL DEFAULT 'idle',
    last_update_error      VARCHAR(1000) NULL,
    last_update_attempt_at DATETIME      NULL,
    last_seen_at           DATETIME      NOT NULL,
    created_at             DATETIME      NOT NULL,
    updated_at             DATETIME      NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_site_slug (site_id, slug),
    KEY idx_site_id (site_id),
    KEY idx_update_available (site_id, update_available)
) {$charset_collate};
```

Differences from `wp_defyn_site_plugins` (P2.1+P2.2):

- **`parent_slug VARCHAR(80) NULL`** — child theme's parent directory name; `NULL` for standalone themes
- **`is_active TINYINT(1) NOT NULL DEFAULT 0`** — exactly one row per site has `is_active = 1` (the active stylesheet); enforced by `SyncThemesService` (not DB constraint — sync writes are transactional)

All `update_*` state machine columns (`update_state`, `last_update_error`, `last_update_attempt_at`) carry the exact same semantics as in `wp_defyn_site_plugins` — there is no P2.2.1 retrofit because the heal mechanism ships in P2.3 from day 1.

### 2.2 Drop redundant column

```sql
ALTER TABLE wp_defyn_sites DROP COLUMN active_theme;
```

The `wp_defyn_sites.active_theme` LONGTEXT JSON column from F6 stored a summary of one theme `{name, version, parent}`. With the new themes table, the row where `is_active = 1` is the source of truth — keeping `active_theme` invites drift. Drop in the v4 migration; SyncService stops populating it; the SitesController response shape drops it; SPA SiteDetail reads the active theme from `useSiteThemes` instead.

The `wp_defyn_sites.theme_counts` LONGTEXT column is **retained** — dropping it would force changes in SitesRepository + Site model + SPA Site card and yield little value. Defer cleanup.

### 2.3 Migration mechanics

- `Activation::SCHEMA_VERSION` bumps `3 → 4`
- New `Defyn\Dashboard\Schema\SiteThemesTable` class provides `createSql()` + `tableName()`
- `Activation::ensureSchema()` adds `dbDelta(SiteThemesTable::createSql())` to the existing sequence
- The DROP COLUMN runs via raw `$wpdb->query()` because `dbDelta` cannot remove columns; wrap in a `SHOW COLUMNS LIKE 'active_theme'` guard so re-runs are idempotent:
  ```php
  $exists = $wpdb->get_var($wpdb->prepare(
      "SHOW COLUMNS FROM {$sitesTable} LIKE %s",
      'active_theme'
  ));
  if ($exists) {
      $wpdb->query("ALTER TABLE {$sitesTable} DROP COLUMN active_theme");
  }
  ```
- The schema self-heal hook from P2.2.1 (`Activation::maybeRunSelfHeal` on `plugins_loaded`) auto-triggers `ensureSchema` when `SchemaVersion::current() < SCHEMA_VERSION` — so the v3→v4 migration auto-runs on first request after dashboard upgrade. **No manual deact + react required.**
- `Uninstaller::uninstall()` adds `wp_defyn_site_themes` to its `DROP TABLE` list

### 2.4 Slug semantics — no normalization

Plugins required `strtok($slug, '/')` normalization (P2.2.1 carry-over) because `PluginListCollector` reported `plugin_file` ('akismet/akismet.php') but the route regex only accepted folder names. Themes do not have this problem: `wp_get_themes()` returns themes keyed by stylesheet (the theme directory name, e.g. `twentytwentyfive`), which matches both the WP `update_themes` transient key shape and the `Theme_Upgrader::upgrade($stylesheet)` API. Route regex `^[a-z0-9-]{1,80}$` matches as-is.

---

## 3. Connector endpoints

Three new signed endpoints under `/defyn-connector/v1/themes`. All use `VerifySignatureMiddleware` (the F6 middleware that protects every signed connector endpoint).

### 3.1 `GET /defyn-connector/v1/themes`

**Request:** signed, no body.

**Response 200 — example payload:**
```json
{
  "themes": [
    {
      "slug": "twentytwentyfive",
      "name": "Twenty Twenty-Five",
      "version": "1.2",
      "parent_slug": null,
      "is_active": true,
      "update_available": true,
      "update_version": "1.3"
    },
    {
      "slug": "astra-child",
      "name": "Astra Child",
      "version": "1.0.0",
      "parent_slug": "astra",
      "is_active": false,
      "update_available": false,
      "update_version": null
    }
  ],
  "server_time": 1717689600
}
```

**Implementation: `ThemeListCollector`** — mirrors `PluginListCollector` from P2.1:

```php
final class ThemeListCollector
{
    /** @return list<array{slug:string,name:string,version:?string,parent_slug:?string,is_active:bool,update_available:bool,update_version:?string}> */
    public function collect(): array
    {
        if (!function_exists('wp_get_themes')) {
            require_once ABSPATH . 'wp-admin/includes/theme.php';
        }
        $activeStylesheet = (string) get_stylesheet();
        $updates          = (object) (get_site_transient('update_themes') ?: new \stdClass());
        $updateResponses  = (array) ($updates->response ?? []);

        $out = [];
        foreach (wp_get_themes() as $stylesheet => $theme) {
            $parent    = $theme->parent();
            $hasUpdate = isset($updateResponses[$stylesheet]);
            $out[] = [
                'slug'             => (string) $stylesheet,
                'name'             => (string) $theme->get('Name'),
                'version'          => (string) $theme->get('Version'),
                'parent_slug'      => $parent ? (string) $parent->get_stylesheet() : null,
                'is_active'        => $stylesheet === $activeStylesheet,
                'update_available' => $hasUpdate,
                'update_version'   => $hasUpdate ? (string) $updateResponses[$stylesheet]['new_version'] : null,
            ];
        }
        return $out;
    }
}
```

**Controller:** `ThemesController::index` — same shape as `PluginsController`. Calls collector, wraps payload, returns 200 with `no-store` cache headers (RestRouter default).

### 3.2 `POST /defyn-connector/v1/themes/refresh`

**Request:** signed, no body.

**Action:** calls `wp_update_themes()` (refreshes `update_themes` transient via api.wordpress.org), then returns the freshly-collected list (same payload as GET).

**Errors:**
- 502 `themes.refresh_failed` — `wp_update_themes()` could not contact the WP update server (mocked in tests via `pre_set_site_transient_update_themes` filter returning false)

### 3.3 `POST /defyn-connector/v1/themes/{slug}/update`

**Request:** signed, no body. Route regex: `(?P<slug>[a-z0-9-]{1,80})`.

**Service: `ThemeUpgraderService`** — sibling of `PluginUpgraderService`:

```php
final class ThemeUpgraderService
{
    public function __construct(
        private readonly \Closure $upgraderFactory = null
    ) { /* test-injection seam, identical to PluginUpgraderService */ }

    /**
     * @return array{success:true,slug:string,previous_version:string,new_version:string}
     * @throws UnknownSlugException | NoUpdateAvailableException | UpdateFailedException
     */
    public function upgrade(string $slug): array { ... }
}
```

**Exception → envelope mapping (mirrors P2.2):**

| Exception | HTTP | Envelope code |
|---|---|---|
| `UnknownSlugException` | 404 | `themes.unknown_slug` |
| `NoUpdateAvailableException` | 409 | `themes.no_update_available` |
| `UpdateFailedException` | 502 | `themes.update_failed` |

**Success envelope:**
```json
{
  "success": true,
  "slug": "twentytwentyfive",
  "previous_version": "1.2",
  "new_version": "1.3",
  "server_time": 1717689600
}
```

**STDOUT discipline (P2.2.1 carry-over):** controller wraps the service call in `ob_start()` / `ob_end_clean()` inside a `try / finally` to absorb stray bytes that `Theme_Upgrader` (or any WP filesystem helper it delegates to) may echo. The regression test from P2.2.1 is copied verbatim with a theme-flavored stub.

**Active theme handling:** the connector does NOT refuse to upgrade the active theme. `Theme_Upgrader::upgrade()` inherits the maintenance-mode dance from `WP_Upgrader` — during the upgrade window the site briefly serves a 503 `.maintenance` page (< 5s typical), then resumes normally. The "scary" UX (warning copy, amber button) lives entirely in the dashboard SPA confirmation dialog (see §6).

### 3.4 Shared transient lock

**Lock key:** `defyn_connector_upgrade_in_flight` — **reused, not duplicated.** This is the same transient that the plugin update endpoint uses (P2.2).

**Rationale:** WP's `WP_Upgrader` infrastructure — including `Plugin_Upgrader` and `Theme_Upgrader` — calls `WP_Filesystem::init()` and acquires the same `.maintenance` lock file. Concurrent plugin + theme upgrades on the same site stomp on each other regardless of how the application-level lock is sliced. A single coarse-grained lock prevents both intra-type collisions (theme A + theme B) and inter-type collisions (theme + plugin). TTL: 600 seconds.

**Collision response:** 409 `connector.upgrade_in_progress` (the existing error code from P2.2 — semantics now cover themes too).

### 3.5 New connector error codes

| Code | HTTP | Endpoint |
|---|---|---|
| `themes.unknown_slug` | 404 | POST /themes/{slug}/update |
| `themes.no_update_available` | 409 | POST /themes/{slug}/update |
| `themes.update_failed` | 502 | POST /themes/{slug}/update |
| `themes.refresh_failed` | 502 | POST /themes/refresh |

The `connector.upgrade_in_progress` code from P2.2 is reused (no new code needed for the shared lock).

---

## 4. Dashboard services + jobs

### 4.1 `Defyn\Dashboard\Models\Theme`

Readonly DTO mirroring `Plugin`. Fields:
```
id, siteId, slug, name, version, parentSlug, isActive,
updateAvailable, updateVersion, updateState, lastUpdateError,
lastUpdateAttemptAt, lastSeenAt, createdAt, updatedAt
```
Same `fromRow()` static + `toJson()` instance method, camelCase keys in JSON.

### 4.2 `Defyn\Dashboard\Services\ThemesRepository`

Direct mirror of `SitePluginsRepository`. Public methods:

```
findAllForSite(int $siteId): list<Theme>
findRowForSiteAndSlug(int $siteId, string $slug): ?array
lastSyncedAtForSite(int $siteId): ?string
replaceForSite(int $siteId, array $incoming, string $now): void
markUpdateRequested(int $siteId, string $slug, string $now): void
markUpdating(int $siteId, string $slug, string $now): void
markUpdateSucceeded(int $siteId, string $slug, string $newVersion, string $now): void
markUpdateFailed(int $siteId, string $slug, string $errorMessage, string $now): void
healDanglingFailedStates(int $siteId, string $now): int   // P2.2.1 sweep — included from day 1
```

`replaceForSite` semantics: delta-write inside a transaction. Inserts new slugs, updates rows whose `(name, version, parent_slug, is_active, update_available, update_version)` tuple changed, refreshes `last_seen_at` on unchanged rows, deletes rows whose slug no longer appears in `$incoming`. The `is_active` column updates correctly when the active stylesheet switches between syncs (a row's `is_active` flips false; another row's flips true).

`healDanglingFailedStates` SQL (identical to plugins):
```sql
UPDATE wp_defyn_site_themes
SET update_state='idle', last_update_error=NULL, updated_at=%s
WHERE site_id=%d AND update_state='failed' AND update_available=0
```

### 4.3 `Defyn\Dashboard\Services\SyncThemesService`

Mirrors `SyncPluginsService`. Sequence per call:

1. `replaceForSite($siteId, $incoming, $now)`
2. `healDanglingFailedStates($siteId, $now)` — from day 1, not retrofitted
3. `ActivityLogger::log(null, $siteId, 'theme_inventory.synced', ['theme_count' => count($incoming), 'updates_available_count' => $count, 'source' => $source])`

**No slug normalization step.** Themes don't have the plugin-file slug-mismatch problem (per §2.4).

### 4.4 AS jobs

**`Defyn\Dashboard\Jobs\RefreshSiteThemes`** — registered on hook `defyn_refresh_site_themes` in `Plugin::boot`.

```php
public function handle(int $siteId): void
{
    $site = $this->sitesRepo->findById($siteId);
    if ($site === null) { return; }
    try {
        $response = $this->httpClient->signedPostJson(
            $site->url . '/wp-json/defyn-connector/v1/themes/refresh',
            [], $site->ourPrivateKey, timeoutSeconds: 30
        );
        $decoded = json_decode($response, true, flags: JSON_THROW_ON_ERROR);
        $this->syncThemes->sync($siteId, $decoded, 'refresh');
    } catch (\Throwable $e) {
        $this->log->log(null, $siteId, 'site.themes_refresh_failed', [
            'error_message' => substr($e->getMessage(), 0, 1000),
        ]);
        throw $e;  // let AS log the failure for retry observability
    }
}
```

**`Defyn\Dashboard\Jobs\UpdateSiteTheme`** — registered on hook `defyn_update_site_theme`. Direct mirror of `UpdateSitePlugin`. Arguments: `[$siteId, $slug, $attempt]`. Flow:

1. `markUpdating($siteId, $slug, $now)`; activity log `theme_update.started` with `{slug, attempt}` — gives operators the second event of the `requested → started → succeeded|failed` triplet that the smoke matrix asserts
2. `signedPostJson` to connector `/themes/{slug}/update` (120s timeout)
3. **200 + `success:true`:** `markUpdateSucceeded($siteId, $slug, $newVersion, $now)`; activity log `theme_update.succeeded` with `{slug, previous_version, new_version}`
4. **409 `themes.no_update_available`** (or `connector.upgrade_in_progress`): treat as success-by-other-means — call `markUpdateSucceeded($siteId, $slug, $rowVersionBeforeAttempt, $now)` where `$rowVersionBeforeAttempt` is the value of the `version` column read from the row before this attempt started (the connector says "no update available" so the on-disk version is whatever was already there). Log `theme_update.succeeded_no_change`
5. **Other non-2xx, parse failure, transport error:** if `$attempt < 5`, reschedule self via `as_schedule_single_action(time() + 2 ** ($attempt - 1), ...)` (1s, 2s, 4s, 8s, 16s backoff); else `markUpdateFailed($siteId, $slug, $error, $now)` + activity log `theme_update.failed` with `{slug, error_code, error_message, attempts}`

### 4.5 Extend `SyncSiteJob`

The recurring background `defyn_sync_site` job (F6, extended in P2.1 Task 9) currently fans out: signed `/status` call + schedules `defyn_refresh_site_plugins`. P2.3 adds one line: also schedules `defyn_refresh_site_themes($siteId)`. The recurring tick now hydrates sites + plugins + themes.

---

## 5. Dashboard REST endpoints + rate limits

All endpoints registered on `/defyn/v1/sites/{id}/themes` via `RestRouter::register()`. All use Bearer JWT auth + owner-scope check (404 `sites.not_found` if site does not belong to the caller — same pattern as P2.1/P2.2).

### 5.1 `GET /sites/{id}/themes` — read inventory

**Auth:** Bearer.
**Controller:** `SitesThemesController::index`.
**Response 200:**
```json
{
  "themes": [
    {
      "id": 1, "site_id": 2, "slug": "twentytwentyfive",
      "name": "Twenty Twenty-Five", "version": "1.2",
      "parent_slug": null, "is_active": true,
      "update_available": true, "update_version": "1.3",
      "update_state": "idle", "last_update_error": null,
      "last_update_attempt_at": null,
      "last_seen_at": "2026-06-06 05:00:00"
    }
  ],
  "last_synced_at": "2026-06-06 05:00:00"
}
```

`last_synced_at = MAX(last_seen_at)` across rows for the site, `null` if the site has never synced themes.

### 5.2 `POST /sites/{id}/themes/refresh` — force refresh

**Auth:** Bearer.
**Body:** none.
**Rate limit:** `RateLimit::sitesThemesRefresh` — 6 per hour per user (own bucket, not shared with `sitesPluginsRefresh`).
**Action:** `as_schedule_single_action(time(), 'defyn_refresh_site_themes', [$siteId])`.
**Response 202:** `{"scheduled": true, "site_id": <id>}`.
**429 envelope:** `rate_limit.too_many_requests` (existing code).

### 5.3 `POST /sites/{id}/themes/{slug}/update` — trigger upgrade

**Auth:** Bearer.
**Body:** none.
**Route regex on slug:** `^[a-z0-9-]{1,80}$`.
**Rate limit:** `RateLimit::themesUpdate` — 6 per hour per user (**separate budget from plugins update**; combined budget of 6 would block a plugin update after two theme updates, which doesn't reflect operator intent).

**Preflight guards (in order):**

1. `ThemesRepository::findRowForSiteAndSlug` returns null → 404 `themes.not_found_in_inventory`
2. Row's `update_available = 0` → 409 `themes.no_update_available_for_slug`
3. Row's `update_state IN ('queued','updating')` → 409 `themes.update_in_progress`

**On success:** `markUpdateRequested($siteId, $slug, $now)`, schedule `defyn_update_site_theme($siteId, $slug, 1)` immediately, log `theme_update.requested` with `{slug, from_version, to_version}`, return:
```json
{"scheduled": true, "site_id": 2, "slug": "twentytwentyfive", "update_state": "queued"}
```
status 202.

### 5.4 New dashboard error codes

| Code | HTTP | Source |
|---|---|---|
| `themes.not_found_in_inventory` | 404 | Update controller preflight |
| `themes.no_update_available_for_slug` | 409 | Update controller preflight |
| `themes.update_in_progress` | 409 | Update controller preflight |
| `sites.themes_invalid_payload` | 400 | Refresh AS job — connector returned malformed JSON (also logged) |
| `sites.themes_refresh_failed` | 502 | Refresh AS job — connector returned non-2xx (also logged) |

The `sites.not_found`, `auth.invalid_token`, and `rate_limit.too_many_requests` codes are reused as-is.

### 5.5 CORS

The dashboard CORS filter from F3a already allows the SPA origin for `defyn/v1/*` — themes endpoints inherit it. P2.2 Task 16 added the regression test covering this; no new CORS work needed.

---

## 6. SPA UI

### 6.1 Component tree additions to `SiteDetail`

```
SiteDetail
├── SiteHeader               (existing — reads active theme from useSiteThemes now)
├── SiteSummaryCard          (existing)
├── SitePluginsPanel         (existing, unchanged)
└── SiteThemesPanel          NEW — stacked directly below plugins
    ├── PanelHeader (title "Themes", Refresh button, last-synced timestamp)
    ├── ThemeListSkeleton    (loading)
    ├── ThemeListEmpty       (empty state, post-load)
    └── SiteThemeRow[]       NEW — four states (idle no-update, idle+available, updating, failed)
```

### 6.2 New files

```
apps/web/src/features/sites/
├── api/
│   ├── themes.ts                       — Zod schemas + fetchers
│   └── themes.msw.ts                   — MSW test handlers
├── hooks/
│   ├── useSiteThemes.ts                — query hook (5min stale; 30s poll while any row queued|updating)
│   ├── useRefreshSiteThemes.ts         — mutation hook + toast
│   └── useUpdateSiteTheme.ts           — mutation hook with confirmation gate
└── components/
    ├── SiteThemesPanel.tsx
    ├── SiteThemeRow.tsx
    └── ConfirmUpdateThemeDialog.tsx    — divergent copy for active vs inactive
```

### 6.3 `SiteThemeRow` — visual states

Visual grammar identical to `SitePluginsRow` so the page reads as one inventory grid:

```
┌────────────────────────────────────────────────────────────────────┐
│ Twenty Twenty-Five    1.2  →  1.3   [Active]            [ Update ] │  idle, update available
│ Astra Child           1.0.0          [Parent: Astra]      —        │  idle, no update
│ Twenty Twenty-Four    1.8           [Updating…]      ⟳ in progress │  updating
│ Astra                 4.5  →  4.6                      Failed ⓘ    │  failed (tooltip = last_update_error)
└────────────────────────────────────────────────────────────────────┘
```

Badges right of name+version:

- `[Active]` if `is_active` — green pill
- `[Parent: <name>]` if `parent_slug != null` — neutral pill, parent name resolved from the same theme list (no extra fetch)
- Both badges can appear together (active child theme)

State → action mapping:

| State | `update_available` | Action cell |
|---|---|---|
| `idle` | `true` | Solid "Update" button |
| `idle` | `false` | Em-dash placeholder |
| `queued`/`updating` | (any) | Spinner + "Updating…" label, button hidden |
| `failed` | (any) | "Failed" badge with ⓘ tooltip displaying `last_update_error` |

### 6.4 `ConfirmUpdateThemeDialog`

Single component, divergent copy based on `is_active`. This is the safety-via-UX decision from the brainstorming session — the backend treats active and inactive uniformly; the dialog is where risk is communicated.

**Inactive theme — low-risk variant:**
```
┌─ Update Twenty Twenty-Four? ──────────────────────────────────┐
│                                                                │
│ This will upgrade Twenty Twenty-Four from 1.8 to 1.9 on        │
│ smartcoding.com.au.                                            │
│                                                                │
│ The update typically takes 30–60 seconds.                      │
│                                                                │
│              [ Cancel ]            [ Update theme ]            │
└────────────────────────────────────────────────────────────────┘
```

**Active theme — higher-risk variant:**
```
┌─ Update Twenty Twenty-Five? ──────────────────────────────────┐
│                                                                │
│ ⚠ This is the active theme on smartcoding.com.au.              │
│                                                                │
│ A failed upgrade can take the front-end down until you fix it  │
│ manually via SFTP or WP-CLI. Make sure you have a recent       │
│ backup before continuing.                                      │
│                                                                │
│ Upgrade from 1.2 to 1.3?                                       │
│                                                                │
│              [ Cancel ]      [ Yes, update active theme ]      │
└────────────────────────────────────────────────────────────────┘
```

Divergences from inactive variant:

- amber `⚠` icon at top of body
- longer cautionary copy
- primary button color: `bg-amber-600 hover:bg-amber-700` (vs default brand blue for inactive)
- primary button label: "Yes, update active theme" (vs "Update theme")
- Cancel button stays neutral and is the focused default (operator must Tab + Enter or move mouse to confirm)

### 6.5 Polling state machine

`useSiteThemes` query becomes "hot" (`refetchInterval: 30_000`) while any row has `update_state IN ('queued','updating')`. Returns to 5-minute stale window when all rows settle to `idle` or `failed`. Same TanStack Query refetchInterval pattern as `useSitePlugins`.

### 6.6 SiteDetail header migration

The SiteDetail header currently reads `"Active theme: Twenty Twenty-Five"` from the now-dropped `wp_defyn_sites.active_theme` column. Migration:

- Header now reads from `useSiteThemes` data — finds the row with `is_active = true`, renders its `name`
- During `useSiteThemes` loading: skeleton chip
- Empty themes list (never synced): "—"

### 6.7 Reuses from P2.1+P2.2

- `Tooltip` primitive (P2.2 Task 19)
- `ConfirmDialog` base component (P2.2 Task 20) — `ConfirmUpdateThemeDialog` wraps it with the divergent copy
- TanStack Query keys: `['sites', siteId, 'themes']`
- Cache invalidation: `useUpdateSiteTheme` invalidates `['sites', siteId, 'themes']` on settle (matches `useUpdateSitePlugin`)
- `useToast` for 429 + transport errors
- MSW handler registration via `apps/web/src/test/setupHandlers.ts`

---

## 7. Testing strategy

Same TDD discipline as P2.1 + P2.2: RED → GREEN → COMMIT per task. ~100 new tests total.

### 7.1 Connector tests (~25)

**Unit:**

- `tests/Unit/SiteInfo/ThemeListCollectorTest.php` — seed synthetic themes via `wp_get_themes` filter + `update_themes` site-transient; assert collector output for: standalone theme, child theme (parent_slug populated), active theme (is_active=true), theme with update available, theme without update

**Integration (WP_UnitTestCase, `@group integration`):**

- `tests/Integration/Rest/ThemesIndexTest.php` — signed GET returns 200 with collector payload + `no-store` cache headers
- `tests/Integration/Rest/ThemesRefreshTest.php` — signed POST calls `wp_update_themes()` (mocked via `pre_set_site_transient_update_themes` filter), returns refreshed list; filter returning false → 502 `themes.refresh_failed`
- `tests/Integration/Rest/ThemeUpdateTest.php` — direct mirror of P2.2's `PluginUpdateTest`:
  - `testUnknownSlugReturns404` → `themes.unknown_slug`
  - `testNoUpdateAvailableReturns409` → `themes.no_update_available`
  - `testSuccessReturns200WithExpectedShape` (Theme_Upgrader stub via service ctor injection)
  - `testStdoutFromUpgraderDoesNotCorruptResponse` — verbatim copy of P2.2.1 regression with theme-flavored stub
  - `testUpgradeFailureReturns502` → `themes.update_failed`
  - `testInvalidSlugReturns404FromRouter` — underscore in slug → router 404
- `tests/Integration/Rest/ThemeUpdateLockTest.php` — shared `defyn_connector_upgrade_in_flight` transient:
  - Plugin upgrade in-flight → theme update returns 409 `connector.upgrade_in_progress`
  - Theme upgrade in-flight → plugin update returns 409 (cross-resource)
  - Lock auto-releases on success AND on exception (P2.2 Task 4 lock-leak regression covers themes)

### 7.2 Dashboard tests (~50)

**Schema (`tests/Unit/Schema/`):**

- `SiteThemesTableTest` — `createSql()` output includes all 15 columns (id, site_id, slug, name, version, parent_slug, is_active, update_available, update_version, update_state, last_update_error, last_update_attempt_at, last_seen_at, created_at, updated_at) + unique key (`site_id, slug`) + two secondary indexes (`site_id`, `site_id + update_available`)
- `SchemaVersionMigrationV4Test` — fresh activation creates table with all 15 columns; existing v3 install upgrades cleanly; `wp_defyn_sites.active_theme` column dropped; SHOW COLUMNS guard makes re-run idempotent

**Repository (`tests/Integration/Services/ThemesRepositoryTest.php`):**

- All `SitePluginsRepositoryTest` test cases retyped — `findAllForSite`, `replaceForSite` delta semantics, state-machine writes, `healDanglingFailedStates` (day-1 inclusion)
- New test specific to themes: `replaceForSite` correctly flips `is_active` between two rows when active stylesheet changes

**Sync service (`tests/Integration/Services/SyncThemesServiceTest.php`):**

- `testSyncPersistsThemesAndLogsEvent` — activity log `theme_inventory.synced`
- `testSyncWithEmptyListClearsRowsAndLogsZero`
- `testSyncHealsDanglingFailedRowWhenUpdateNoLongerAvailable` — P2.2.1 carry-over from day 1
- `testSyncDoesNotHealRowsWithActiveUpdate`
- `testSyncCorrectlyMarksActiveTheme` — incoming `is_active=true` on exactly one row persists; previously-active row's `is_active` flips false when active stylesheet changes

**AS jobs (`tests/Integration/Jobs/`):**

- `RefreshSiteThemesTest` — success (inventory written + `theme_inventory.synced source=refresh`); HTTP failure (logs `site.themes_refresh_failed`, does not clobber inventory)
- `UpdateSiteThemeTest` — direct mirror of `UpdateSitePluginTest`: success path, 409 success-by-other-means, 5-attempt retry exponential backoff, final-failure markUpdateFailed + activity event; regression test confirming `previous_version`/`new_version` end up in activity-log `details` JSON correctly

**REST controllers (`tests/Integration/Rest/`):**

- `SitesThemesIndexTest` — 200 with themes + `last_synced_at`; owner-scoped 404; auth required
- `SitesThemesRefreshTest` — 202 schedules `defyn_refresh_site_themes`; owner-scoped 404; rate limit 429 after 7th call/hour
- `SitesThemesUpdateTest` — preflight guard matrix (not-found 404, not-available 409, in-progress 409); happy path 202; rate limit 429 after 7th call/hour; **separate budget assertion**: 6 plugin updates do NOT block a 7th theme update

### 7.3 SPA tests (~25)

**Vitest + RTL + MSW:**

- `api/themes.test.ts` — Zod parses sample success and error envelopes; rejects mismatched shapes
- `hooks/useSiteThemes.test.ts` — 30s poll while any row queued/updating; 5min stale when all idle/failed
- `hooks/useRefreshSiteThemes.test.ts` — fires POST, invalidates `['sites', siteId, 'themes']`, toast on 429
- `hooks/useUpdateSiteTheme.test.ts` — fires POST, optimistic transition to queued; rollback on 409/500
- `components/SiteThemeRow.test.tsx` — renders all 4 states correctly; `[Active]` and `[Parent: X]` badges
- `components/ConfirmUpdateThemeDialog.test.tsx` — divergent rendering: active=true shows warning banner + "Yes, update active theme" label + amber primary; active=false shows neutral copy + "Update theme"; Cancel has default focus in both
- `components/SiteThemesPanel.test.tsx` — refresh calls `useRefreshSiteThemes`; empty state; loading skeleton; row state-machine integration
- `routes/SiteDetail.themes.test.tsx` — header chip "Active theme: X" reads from `useSiteThemes`; skeleton during loading, "—" when empty

### 7.4 Out-of-scope tests (YAGNI)

- Theme deletion (no delete endpoint)
- Theme activation / switching (out of scope)
- Cross-site bulk theme updates (out of scope)
- Backup-before-upgrade (rejected in active-theme safety decision)
- theme.json / block-theme introspection (out of scope)

### 7.5 Coverage gate

≥80% on new modules. CI workflow (`.github/workflows/test.yml`) auto-discovers new tests; no CI changes.

---

## 8. Manual smoke flow

Run after all tasks are green in CI, before tagging.

### 8.1 Pre-smoke setup

1. Build artifacts:
   - `bin/build-connector.sh` → `~/Desktop/defyn-connector-v0.1.5-<date>.zip`
   - `bin/build-dashboard.sh` → `~/Desktop/defyn-dashboard-v0.4.0-<date>.zip`
2. Install both on production (connector on `smartcoding.com.au`, dashboard on `defynwp.defyn.agency`)
3. Verify `plugins.php` shows new versions; verify `wp_defyn_site_themes` table exists (schema self-heal); verify `wp_defyn_sites.active_theme` column no longer exists
4. Re-handshake any sites that need it

### 8.2 Smoke matrix

| # | Action | Expected | Verifies |
|---|---|---|---|
| 1 | `GET /sites/{id}/themes` | `themes:[]`, `last_synced_at:null` | Schema migration; empty state |
| 2 | `POST /sites/{id}/themes/refresh` | `202 {"scheduled":true}`; AS job fires < 60s | Refresh endpoint + AS dispatch + signed connector call |
| 3 | After job: `GET /sites/{id}/themes` | List populated; one `is_active:true`; `parent_slug` set on children | End-to-end inventory |
| 4 | Install an old version of a theme to create an update | Row gets `update_available:true`, `update_version` set | Update detection |
| 5 | `POST /sites/{id}/themes/<inactive-slug>/update` | `202 update_state:queued` → AS job → row transitions `updating` → `idle` with new version | Full upgrade pipeline |
| 6 | `GET /sites/{id}/activity` | `theme_update.requested` → `theme_update.started` → `theme_update.succeeded` triplet | Activity logging |
| 7 | 7× `POST .../themes/{slug}/update` for different slugs in < 1 hour | 7th returns `429 rate_limit.too_many_requests` | RateLimit::themesUpdate |
| 8 | Concurrent: during #5 in flight, fire update for another theme | `409 themes.update_in_progress` OR `409 connector.upgrade_in_progress` | Shared lock |
| 9 | `POST /sites/{id}/themes/<active-slug>/update` | Same successful pipeline; site front-end serves correctly during upgrade window | Active-theme upgrade works |
| 10 | Mid-#9: load public homepage | Either normal OR brief `.maintenance` 503 (< 5s); not a corrupt template | Maintenance-mode safety |
| 11 | SPA → `SiteThemesPanel` → click Update on active theme | Amber confirmation dialog with "Yes, update active theme" copy | SPA divergent confirm |
| 12 | Cross-resource: queue a plugin update, then fire a theme update | Theme returns 409 from shared lock | Shared lock covers cross-resource |
| 13 | Manually `UPDATE wp_defyn_site_themes SET update_state='failed' WHERE slug=X` for an idle row, then refresh | After sync: row state heals to `idle` | P2.2.1 heal-from-day-1 |

### 8.3 Tag + push

After all 13 steps green:
```
git tag -a p2-3-themes-complete -m "P2.3 — themes inventory + themes updates shipped"
git push origin p2-3-themes-complete
```
Push only after manual smoke is green.

---

## 9. Deliberately out of scope (deferred)

| Deferred to | What's not in P2.3 |
|---|---|
| **P2.4 (planned)** | WP core updates (single-resource shape, maintenance-mode, irreversibility) |
| **P2.5 (planned)** | Operator overview dashboard (cross-site rollups; replaces the `Home → /sites` redirect) |
| **Future** | Theme activation / theme switching (operator swaps the active theme — own phase) |
| **Future** | Theme deletion from the operator UI |
| **Future** | Pre-upgrade backups + rollback |
| **Future** | Per-theme auto-update toggles |
| **Future** | theme.json / block-theme deep introspection |
| **Future** | Multisite-aware theme management (`get_site_option` paths for network-active themes) |
| **Future** | Cross-site bulk theme update ("update twentytwentyfive on all 12 sites") |

---

## 10. Implementation notes for the plan author

- Branch off `main` as `p2-3-themes`
- ~28-30 TDD tasks expected
- Reuse skeleton from `2026-06-06-p2-2-plugin-updates.md` plan structure — most tasks are direct mirrors with `Plugin → Theme` retyping and column-list adjustments
- Include `healDanglingFailedStates` in the repository task from day 1 (do not retro it like P2.2.1)
- Include the STDOUT-leak regression test in the controller task from day 1
- Schema task drops `active_theme` column in the same migration as the table create — the SHOW COLUMNS guard makes the DROP idempotent
- Smoke checklist (§8.2) becomes the final task's content
