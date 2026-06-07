# P2.4 — WordPress Core Updates (Minor Only) Design

**Status:** Approved (2026-06-07)
**Author:** Pradeep + Claude (brainstorming session)
**Predecessors:** `2026-06-06-p2-3-themes-design.md` (P2.3 themes); inherits all P2.2.1 carry-overs
**Successor (planned):** P2.4.1 — Major-version core updates (separate phase)
**Foundation spec (canonical):** `2026-04-18-defyn-foundation-design.md`
**Scope:** WP minor-version updates only (e.g. 7.0 → 7.0.1). Major updates (e.g. 7.0 → 7.1) are explicitly blocked by both connector + dashboard with a `core.major_update_blocked` envelope, deferred to P2.4.1.

---

## 1. Architecture overview

P2.4 ships per-site WordPress core minor-version update execution. It is a deliberate mirror of P2.3 (themes) end-to-end with one structural divergence: **state writes target the existing `wp_defyn_sites` row, not a new table**. Core is a single resource per site (one `wp_version`, one update target), so the multi-row repository pattern from P2.1–P2.3 collapses to four state-machine methods on the existing `SitesRepository`.

### 1.1 Request flow

```
SPA SiteCoreCard
   │ TanStack Query: useSite (5min stale; 30s poll while core_update_state IN queued|updating)
   ▼
Dashboard REST  ──► GET /sites/{id}                       (existing — gains 5 new fields automatically)
                ──► POST /sites/{id}/core/refresh         (new, Bearer JWT + 6/hr sitesCoreRefresh)
                ──► POST /sites/{id}/core/update          (new, Bearer JWT + 3/hr coreUpdate)
   │
   │ schedules AS jobs
   ▼
AS jobs  ──► defyn_refresh_site_core  ──► SignedHttpClient ──sign──► Connector POST /core/refresh   (30s timeout)
         ──► defyn_update_site_core   ──► SignedHttpClient ──sign──► Connector POST /core/update    (300s timeout)
   │
   │ writes back via SitesRepository::markCoreUpdate*
   ▼
SyncService (extended) → wp_defyn_sites + activity log
```

### 1.2 Three timeout adjustments from P2.3

- Connector `/core/refresh`: **30s** (same as themes refresh)
- Connector `/core/update`: **300s** (vs themes' 120s — core upgrades include WP database migrations on point releases)
- Background `/status` heartbeat: **30s, unchanged** — just larger response payload now (one new `core` sub-object)

### 1.3 Reused infrastructure (no rebuilding)

From F5/F6/P2.1/P2.2/P2.3:

- `VerifySignatureMiddleware` (signing protocol, ±300s timestamp window, nonce store)
- `SignedHttpClient::signedPostJson()` with `timeoutSeconds` ctor param
- `Vault::decryptPrivateKey($siteId)`
- `Signer::signRequest()`
- `ActivityLogger::log()`
- `ErrorResponse::create()` envelope shape
- `CapturingUpgraderSkin` (works for `Core_Upgrader` too — same WP_Upgrader_Skin ABI)
- `RestRouter` default `no-store` cache headers
- `NonceStore`
- Action Scheduler hook registration in `Plugin::boot`
- Schema self-heal hook from P2.2.1 (auto-runs the v4→v5 column adds on first `plugins_loaded` after upgrade)
- `ob_start`/`ob_end_clean` STDOUT discipline on the connector controller (P2.2.1 regression)
- Shared `defyn_connector_upgrade_in_flight` transient lock — now covers core ↔ plugin and core ↔ theme collisions too

### 1.4 Component additions summary

| Layer | New module | Mirrors / extends |
|---|---|---|
| Connector | `SiteInfo\Collector::collectCoreUpdate()` (private method extension) | extends existing `Collector::collect()` |
| Connector | `Defyn\Connector\Rest\CoreRefreshController` | `ThemesRefreshController` (P2.3) |
| Connector | `Defyn\Connector\Rest\CoreUpdateController` | `ThemeUpdateController` (P2.3) |
| Connector | `Defyn\Connector\SiteInfo\CoreUpgraderService` | `ThemeUpgraderService` (P2.3) |
| Connector | 3 exception classes (`NoCoreUpdateAvailableException`, `MajorUpdateBlockedException`, `CoreUpgradeFailedException`) | mirrors theme exception triple (P2.3) |
| Dashboard | `Activation::ensureSchema()` adds `addCoreUpdateColumns()` private method | extends existing schema migrator |
| Dashboard | `SitesRepository::markCoreUpdate{Requested,Updating,Succeeded,Failed}` + extended `markSynced` | extends existing repository |
| Dashboard | `Defyn\Dashboard\Jobs\RefreshSiteCore` | `RefreshSiteThemes` (P2.3) |
| Dashboard | `Defyn\Dashboard\Jobs\UpdateSiteCore` | `UpdateSiteTheme` (P2.3) |
| Dashboard | `Defyn\Dashboard\Rest\SitesCoreRefreshController` | `SitesThemesRefreshController` (P2.3) |
| Dashboard | `Defyn\Dashboard\Rest\SitesCoreUpdateController` | `SitesThemesUpdateController` (P2.3) |
| Dashboard | `RateLimit::sitesCoreRefresh` (6/hr) + `RateLimit::coreUpdate` (**3/hr** — tighter) | new methods on existing class |
| SPA | `useRefreshSiteCore`, `useUpdateSiteCore` mutation hooks | mirror P2.3 mutations |
| SPA | `SiteCoreCard`, `ConfirmUpdateCoreDialog` | new components |

**No new model class, no new repository class, no new query hook** — core update state arrives through the existing `useSite(siteId)` query for free.

---

## 2. Schema (v4 → v5 migration)

### 2.1 Five new columns on `wp_defyn_sites`

```sql
ALTER TABLE wp_defyn_sites
  ADD COLUMN core_update_available       TINYINT(1)    NOT NULL DEFAULT 0,
  ADD COLUMN core_update_version         VARCHAR(20)   NULL,
  ADD COLUMN core_update_state           VARCHAR(20)   NOT NULL DEFAULT 'idle',
  ADD COLUMN last_core_update_error      VARCHAR(1000) NULL,
  ADD COLUMN last_core_update_attempt_at DATETIME      NULL;

ALTER TABLE wp_defyn_sites
  ADD INDEX idx_core_update_available (core_update_available);
```

### 2.2 Column semantics

| Column | Type | Semantics |
|---|---|---|
| `core_update_available` | `TINYINT(1) NOT NULL DEFAULT 0` | `1` when connector reports a minor update is available, else `0`. Mirrors `update_available` on plugin/theme rows. |
| `core_update_version` | `VARCHAR(20) NULL` | The version WP wants to upgrade TO (e.g. `'7.0.1'`). `NULL` when no update is available. |
| `core_update_state` | `VARCHAR(20) NOT NULL DEFAULT 'idle'` | State machine: `'idle'` (default) \| `'queued'` (operator pressed Update; AS job enqueued) \| `'updating'` (AS job started executing the upgrade) \| `'failed'` (last attempt failed; error available in `last_core_update_error`) |
| `last_core_update_error` | `VARCHAR(1000) NULL` | Truncated to 1000 chars (matches plugin/theme convention). `NULL` when last attempt succeeded or no attempt yet. |
| `last_core_update_attempt_at` | `DATETIME NULL` | Most recent attempt timestamp (any outcome). `NULL` if never attempted. |

### 2.3 Migration mechanics

- `Activation::SCHEMA_VERSION` bumps `4 → 5`
- `Activation::ensureSchema()` gains a private `addCoreUpdateColumns()` method that runs guarded `ALTER TABLE` statements. Same pattern as P2.3's `dropLegacyActiveThemeColumn()`:

  ```php
  private static function addCoreUpdateColumns(): void
  {
      global $wpdb;
      $sitesTable = SitesTable::tableName();
      $columns = [
          'core_update_available'       => 'TINYINT(1) NOT NULL DEFAULT 0',
          'core_update_version'         => 'VARCHAR(20) NULL',
          'core_update_state'           => "VARCHAR(20) NOT NULL DEFAULT 'idle'",
          'last_core_update_error'      => 'VARCHAR(1000) NULL',
          'last_core_update_attempt_at' => 'DATETIME NULL',
      ];
      foreach ($columns as $name => $definition) {
          $exists = $wpdb->get_var($wpdb->prepare(
              "SHOW COLUMNS FROM `{$sitesTable}` LIKE %s",
              $name
          ));
          if ($exists === null) {
              // phpcs:ignore WordPress.DB.PreparedSQL — column DDL.
              $wpdb->query("ALTER TABLE `{$sitesTable}` ADD COLUMN {$name} {$definition}");
          }
      }
      // Index — also guarded, idempotent.
      $hasIndex = $wpdb->get_row($wpdb->prepare(
          "SHOW INDEX FROM `{$sitesTable}` WHERE Key_name = %s",
          'idx_core_update_available'
      ));
      if ($hasIndex === null) {
          $wpdb->query("ALTER TABLE `{$sitesTable}` ADD INDEX idx_core_update_available (core_update_available)");
      }
  }
  ```

- `dbDelta` can't reliably add columns to existing tables with the exact spec — raw `$wpdb->query()` with `SHOW COLUMNS` guards is the proven pattern (carries from P2.3).
- **Schema self-heal hook from P2.2.1 auto-runs `ensureSchema()` on first `plugins_loaded`** after dashboard upgrade. **No manual deact + react required.**
- `Uninstaller::uninstall()` — no changes. The columns get dropped automatically when `wp_defyn_sites` is dropped on uninstall. No new tables to register.

### 2.4 No major-update column

Major-update detection lives entirely in the connector (it rejects `POST /core/update` with `core.major_update_blocked` 409 when the upgrade target is a major bump). The dashboard's preflight (§5.2 step 4) ALSO checks this against the row's `core_update_version` vs current `wp_version`, providing fast-fail without an AS roundtrip. **The row does not persist a "this is a major" flag** — the determination is made at request time from version strings.

P2.4.1 will add `core_major_update_pending` (or similar) if we decide to surface "a major is available but blocked" UX. P2.4 surfaces only minors; majors stay invisible in the SPA card.

---

## 3. Connector endpoints

One extension to the existing `/status`, two new endpoints. All use `VerifySignatureMiddleware`.

### 3.1 Extended `GET /defyn-connector/v1/status`

**Backward-compatible addition** — existing keys unchanged, three new keys grouped under a new `core` sub-object.

**Response 200 — example payload:**
```json
{
  "wp_version":     "7.0",
  "php_version":    "8.3.31",
  "active_theme":   { "name": "Smart Coding", "version": "1.0.29" },
  "plugin_counts":  { "installed": 21, "active": 20 },
  "theme_counts":   { "installed": 8, "active": 1 },
  "ssl_status":     "enabled",
  "ssl_expires_at": null,
  "server_time":    1717689600,

  "core": {
    "update_available":       true,
    "update_version":         "7.0.1",
    "is_minor_update":        true,
    "is_auto_update_enabled": false
  }
}
```

**Implementation: extend `SiteInfo\Collector::collect()`** — add private helper `collectCoreUpdate()`:

```php
private function collectCoreUpdate(): array
{
    if (!function_exists('get_core_updates')) {
        require_once ABSPATH . 'wp-admin/includes/update.php';
    }
    $updates = get_core_updates();   // reads `update_core` site transient
    $current = (string) get_bloginfo('version');

    foreach ((array) $updates as $u) {
        if (!isset($u->response) || $u->response !== 'upgrade') {
            continue;
        }
        $targetVersion = (string) $u->current;
        return [
            'update_available'        => true,
            'update_version'          => $targetVersion,
            'is_minor_update'         => self::isMinorUpgrade($current, $targetVersion),
            'is_auto_update_enabled'  => self::isMinorAutoUpdateEnabled(),
        ];
    }
    return [
        'update_available'        => false,
        'update_version'          => null,
        'is_minor_update'         => false,
        'is_auto_update_enabled'  => self::isMinorAutoUpdateEnabled(),
    ];
}

private static function isMinorUpgrade(string $current, string $target): bool
{
    [$cMaj, $cMin] = array_pad(array_slice(explode('.', $current), 0, 2), 2, '0');
    [$tMaj, $tMin] = array_pad(array_slice(explode('.', $target), 0, 2), 2, '0');
    return $cMaj === $tMaj && $cMin === $tMin;
}

private static function isMinorAutoUpdateEnabled(): bool
{
    if (!defined('WP_AUTO_UPDATE_CORE')) {
        return true;  // WP default
    }
    return in_array(WP_AUTO_UPDATE_CORE, [true, 'minor', 'minor-security'], true);
}
```

**Pure read; no `wp_version_check()` call.** This runs on every `/status` tick (every ~5 minutes per site). The `get_core_updates()` call reads from the `update_core` site transient that WP itself refreshes via cron — we don't trigger a fresh network check here.

### 3.2 `POST /defyn-connector/v1/core/refresh`

**Request:** signed, no body.

**Action:** calls `wp_version_check()` — WP's function that contacts `api.wordpress.org/core/version-check/1.7/` and refreshes the `update_core` site transient. Then returns the freshly-collected `core` payload (just the sub-object from §3.1).

**Response 200 — example payload:**
```json
{
  "update_available":       true,
  "update_version":         "7.0.1",
  "is_minor_update":        true,
  "is_auto_update_enabled": false,
  "server_time":            1717689600
}
```

**Errors:**
- 502 `core.refresh_failed` — `wp_version_check()` could not contact api.wordpress.org (mocked in tests via `pre_set_site_transient_update_core` filter returning `false`)

### 3.3 `POST /defyn-connector/v1/core/update`

**Request:** signed, no body. No `{slug}` path param (single resource).

**Service: `CoreUpgraderService`** — sibling of `ThemeUpgraderService` from P2.3, with constructor-injected upgrader factory for test stubbing:

```php
final class CoreUpgraderService
{
    public function __construct(?callable $upgraderFactory = null) { ... }

    /**
     * @return array{success:true,previous_version:string,new_version:string}
     * @throws NoCoreUpdateAvailableException | MajorUpdateBlockedException | CoreUpgradeFailedException
     */
    public function upgrade(): array { ... }
}
```

**Exception → envelope mapping:**

| Exception | HTTP | Envelope code |
|---|---|---|
| `NoCoreUpdateAvailableException` | 409 | `core.no_update_available` |
| `MajorUpdateBlockedException` | 409 | `core.major_update_blocked` |
| `CoreUpgradeFailedException` | 502 | `core.update_failed` |

**Success envelope:**
```json
{
  "success":          true,
  "previous_version": "7.0",
  "new_version":      "7.0.1",
  "server_time":      1717689600
}
```

**Flow inside `upgrade()`:**

1. Call `wp_version_check()` first — don't trust the cached transient; we're about to do something irreversible. Refresh fresh.
2. Call `get_core_updates()` — read the just-refreshed transient.
3. If no `'upgrade'` response → throw `NoCoreUpdateAvailableException` (auto-update may have already landed it; status will surface that on next sync).
4. Determine if target is a major bump using same `isMinorUpgrade($current, $target)` helper as `collectCoreUpdate`. If NOT minor → throw `MajorUpdateBlockedException` with message `"Major-version updates ({current} → {target}) require P2.4.1."`
5. Acquire shared transient lock (§3.4).
6. Instantiate `CapturingUpgraderSkin` + `Core_Upgrader($skin)` via the injected factory.
7. Run `Core_Upgrader::upgrade($update)` where `$update` is the matching transient entry.
8. On `false` return → throw `CoreUpgradeFailedException` with `$skin->lastErrorMessage()` (or generic fallback if skin didn't capture).
9. On `WP_Error` return → throw `CoreUpgradeFailedException` with `(string) $result->get_error_message()`.
10. On `true` → re-read `get_bloginfo('version')` post-upgrade for `new_version` in the response. Return success envelope.

**STDOUT discipline (P2.2.1 carry-over from day 1):** `CoreUpdateController` wraps the service call in `ob_start()` / `ob_end_clean()` inside `try/finally`. Same regression test (`testStdoutFromUpgraderDoesNotCorruptResponse`) copied verbatim with a `Core_Upgrader` stub.

### 3.4 Shared transient lock — now covers 3 resource types

**Lock key:** `defyn_connector_upgrade_in_flight` — **same key as plugins + themes use**.

**Rationale:** WP's `WP_Upgrader` infrastructure — `Plugin_Upgrader`, `Theme_Upgrader`, and `Core_Upgrader` — all acquire the same `.maintenance` file lock during `WP_Filesystem` init. Concurrent upgrades stomp on each other regardless of resource type. A single coarse-grained lock prevents all collision combinations (3 × 3 = 9 pairings). TTL: 600 seconds.

**Collision response:** `409 connector.upgrade_in_progress` (existing code from P2.2 — semantics now cover core too).

### 3.5 New connector error codes

| Code | HTTP | Endpoint |
|---|---|---|
| `core.no_update_available` | 409 | `POST /core/update` — no upgrade target in transient |
| `core.major_update_blocked` | 409 | `POST /core/update` — target is a major bump; P2.4.1 will handle |
| `core.update_failed` | 502 | `POST /core/update` — `Core_Upgrader` returned `false` or `WP_Error` |
| `core.refresh_failed` | 502 | `POST /core/refresh` — `wp_version_check()` failed |

`connector.upgrade_in_progress` from P2.2 is reused unchanged for lock collisions.

---

## 4. Dashboard services + jobs

Three changes — all extensions of existing code, no new model or repository class.

### 4.1 Extend `SitesRepository` — 4 new methods + 1 extension

Add to `packages/dashboard-plugin/src/Services/SitesRepository.php`:

```php
public function markCoreUpdateRequested(int $siteId, string $now): void
{
    global $wpdb;
    $wpdb->update(SitesTable::tableName(), [
        'core_update_state'           => 'queued',
        'last_core_update_error'      => null,
        'last_core_update_attempt_at' => $now,
        'updated_at'                  => $now,
    ], ['id' => $siteId], ['%s', '%s', '%s', '%s'], ['%d']);
}

public function markCoreUpdating(int $siteId, string $now): void
{
    global $wpdb;
    $wpdb->update(SitesTable::tableName(), [
        'core_update_state' => 'updating',
        'updated_at'        => $now,
    ], ['id' => $siteId], ['%s', '%s'], ['%d']);
}

public function markCoreUpdateSucceeded(int $siteId, string $newVersion, string $now): void
{
    global $wpdb;
    $wpdb->update(SitesTable::tableName(), [
        'wp_version'                  => $newVersion,
        'core_update_state'           => 'idle',
        'core_update_available'       => 0,
        'core_update_version'         => null,
        'last_core_update_error'      => null,
        'updated_at'                  => $now,
    ], ['id' => $siteId], ['%s', '%s', '%d', '%s', '%s', '%s'], ['%d']);
}

public function markCoreUpdateFailed(int $siteId, string $errorMessage, string $now): void
{
    global $wpdb;
    $wpdb->update(SitesTable::tableName(), [
        'core_update_state'           => 'failed',
        'last_core_update_error'      => substr($errorMessage, 0, 1000),
        'last_core_update_attempt_at' => $now,
        'updated_at'                  => $now,
    ], ['id' => $siteId], ['%s', '%s', '%s', '%s'], ['%d']);
}
```

**Extend existing `SitesRepository::markSynced`** — the method that runs on every background `/status` tick. Currently writes `wp_version`, `php_version`, etc. Add three core-related writes + the day-1 single-row heal logic:

```php
public function markSynced(int $siteId, array $info): void
{
    // ... existing writes (wp_version, php_version, plugin_counts, etc) ...

    // P2.4 — core update state.
    $coreInfo = $info['core'] ?? null;
    if (is_array($coreInfo)) {
        $updates['core_update_available'] = !empty($coreInfo['update_available']) ? 1 : 0;
        $updates['core_update_version']   = $coreInfo['update_version'] ?? null;

        // Day-1 single-row heal — if incoming says "no update available" but
        // existing row state is 'failed', reset to idle. This is the P2.2.1
        // healDanglingFailedStates equivalent for a single resource row.
        $existing = $this->findById($siteId);
        if (
            $existing !== null
            && $existing->coreUpdateState === 'failed'
            && empty($coreInfo['update_available'])
        ) {
            $updates['core_update_state']      = 'idle';
            $updates['last_core_update_error'] = null;
        }
    }

    // ... $wpdb->update($table, $updates, ['id' => $siteId]) ...
}
```

`is_minor_update` and `is_auto_update_enabled` from the `core` sub-object are **not persisted** — they're read-only metadata. The SPA dialog (§6.4) reads them directly from the per-call `/sites/{id}` response which surfaces them through the Site model's transient `coreMeta` field.

### 4.2 Extend `SyncService` — no new methods

`SyncService::sync()` already calls connector `/status` and passes the response to `SitesRepository::markSynced($siteId, $info)`. The new `core` sub-object flows through automatically once `markSynced` knows how to read it (§4.1). One new activity log event: `core_inventory.synced` — fired alongside the existing `site.synced` **only when the core-update-available state changes** (was-not-available → now-available, or was-available → now-not). Avoids logging unchanged-state syncs (one log line per ~5 minutes × N sites would flood the activity log).

### 4.3 AS jobs

**`Defyn\Dashboard\Jobs\RefreshSiteCore`** — registered on hook `defyn_refresh_site_core` in `Plugin::boot`. Mirrors `RefreshSiteThemes` from P2.3:

```php
public function handle(int $siteId): void
{
    $site = $this->sites->findById($siteId);
    if ($site === null) { return; }
    try {
        $response = $this->http->signedPostJson(
            $site->url . '/wp-json/defyn-connector/v1/core/refresh',
            [], $site->ourPrivateKey, '/defyn-connector/v1/core/refresh', timeoutSeconds: 30
        );
        $decoded = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
        $this->repo->markSynced($siteId, ['core' => $decoded]);
        $this->log->log(null, $siteId, 'core_inventory.refreshed', [
            'update_available' => $decoded['update_available'] ?? false,
            'source'           => 'refresh',
        ]);
    } catch (\Throwable $e) {
        $this->log->log(null, $siteId, 'site.core_refresh_failed', [
            'error_message' => substr($e->getMessage(), 0, 1000),
        ]);
    }
}
```

**`Defyn\Dashboard\Jobs\UpdateSiteCore`** — registered on hook `defyn_update_site_core`. Args: `[$siteId, $attempt]` (no slug — single resource). Direct mirror of `UpdateSiteTheme` with FOUR response branches instead of P2.3's three:

1. **Start:** `markCoreUpdating` + log `core_update.started` with `{previous_version, target_version, attempt}` — first event in the requested→started→succeeded|failed triplet (day-1 lesson from P2.3).

2. **Success (200 + `success:true`):** `markCoreUpdateSucceeded($siteId, $newVersion, $now)` + log `core_update.succeeded` with `{previous_version, new_version}`.

3. **409 `core.no_update_available`** (success-by-other-means — auto-update may have landed it): read the row's existing `wp_version` BEFORE the attempt into `$wpVersionBeforeAttempt`, then call `markCoreUpdateSucceeded($siteId, $wpVersionBeforeAttempt, $now)`. Log `core_update.succeeded_no_change`. The connector's 409 error envelope does NOT include a version field — we use what the row already has (the row's `wp_version` is current because `markSynced` updates it on every background tick). Mirrors P2.3 Task 15's pattern.

4. **409 `core.major_update_blocked`:** mark failed immediately, NO retry. Log `core_update.blocked_major` with the connector's message. This is a contract violation by the dashboard (it should never have queued a major after the preflight in §5.2 step 4) — we treat it as a soft failure visible to operator, not a system error.

5. **409 `connector.upgrade_in_progress`** (shared lock collision): retry with exponential backoff up to 5 attempts (60s/120s/240s/480s/960s — same cadence as P2.3). Log `core_update.retry`. After 5 attempts: mark failed with `"Site is busy after 5 retries."` + log `core_update.failed` with `error_code = retry_exhausted`.

6. **Other failures (non-2xx, parse failure, transport error):** `markCoreUpdateFailed` + log `core_update.failed` with `{error_code, error_message, attempts}`. **No retry** (different from P2.3 — for core, repeated non-lock failures probably indicate a real problem like out-of-disk-space, not a transient hiccup).

**300-second timeout:** the `signedPostJson` call uses `timeoutSeconds: 300` (vs P2.3 themes' 120) — core upgrades involve more files + WP database migrations on point releases. Production WP core 7.0 → 7.0.1 typically completes in 60–90s on Kinsta; 300s budget covers slow shared hosts.

### 4.4 Wire AS hooks + extend `SyncSite`

**`Plugin::boot`** — add two `add_action` calls beside the P2.3 theme hooks:

```php
add_action(RefreshSiteCore::HOOK, static function (int $siteId): void {
    (new RefreshSiteCore())->handle($siteId);
}, 10, 1);

add_action(UpdateSiteCore::HOOK, static function (int $siteId, int $attempt = 0): void {
    (new UpdateSiteCore())->handle($siteId, $attempt);
}, 10, 2);
```

**`SyncSite::handle`** — append ONE line to schedule `defyn_refresh_site_core` alongside the existing `defyn_refresh_site_plugins` + `defyn_refresh_site_themes` fan-out. On every recurring background tick, the dashboard refreshes plugins + themes + core in one fan-out.

---

## 5. Dashboard REST endpoints + rate limits

Two new endpoints registered on `/defyn/v1/sites/{id}/core/...` via `RestRouter::register()`. Core read state is **already in the existing `GET /sites/{id}` response** once `SitesRepository::markSynced` writes the new columns — no new GET endpoint.

### 5.1 `POST /sites/{id}/core/refresh`

**Auth:** Bearer JWT, owner-scoped.
**Body:** none.
**Rate limit:** `RateLimit::sitesCoreRefresh` — 6/hour per (user, site), **separate bucket** from `sitesPluginsRefresh` and `sitesThemesRefresh`.
**Action:** `as_schedule_single_action(time(), RefreshSiteCore::HOOK, [$siteId], 'defyn')` + log `core_inventory.refresh_requested`.
**Response 202:** `{"scheduled": true, "site_id": <id>}`.
**Errors:** 404 `sites.not_found` (not owned), 429 `core.rate_limited`.

### 5.2 `POST /sites/{id}/core/update`

**Auth:** Bearer JWT, owner-scoped.
**Body:** none. No `{slug}` path param.
**Rate limit:** `RateLimit::coreUpdate` — **3/hour per (user, site)** (tighter than themes/plugins at 6/hour — core updates are higher-impact and an operator pressing this 4 times in an hour is almost certainly a mistake).

**Preflight guards (in order):**

1. `SitesRepository::findByIdForUser($siteId, $userId)` returns null → `404 sites.not_found`
2. Row's `core_update_available === 0` → `409 core.no_update_available_for_site`
3. Row's `core_update_state IN ('queued','updating')` → `409 core.update_in_progress`
4. Row's `core_update_version` is a major bump vs current `wp_version` (same `isMinorUpgrade` helper, reimplemented dashboard-side) → `409 core.major_update_blocked`

**On success:**

1. `markCoreUpdateRequested($siteId, $now)` — writes `core_update_state='queued'` + clears prior error.
2. Log `core_update.requested` with `{from_version, to_version}` — first event in the activity triplet.
3. Schedule `defyn_update_site_core($siteId, 0)` immediately via `as_schedule_single_action`.
4. Return `202 { "scheduled": true, "site_id": <id>, "core_update_state": "queued" }`.

### 5.3 New dashboard error envelope codes

| Code | HTTP | Source |
|---|---|---|
| `core.no_update_available_for_site` | 409 | `SitesCoreUpdateController` preflight guard #2 |
| `core.update_in_progress` | 409 | `SitesCoreUpdateController` preflight guard #3 |
| `core.major_update_blocked` | 409 | `SitesCoreUpdateController` preflight guard #4 + echoed by AS job from connector |
| `core.rate_limited` | 429 | `RateLimit::coreUpdate` and `RateLimit::sitesCoreRefresh` |
| `sites.core_refresh_failed` | 502 | `RefreshSiteCore` AS job — connector returned non-2xx (also logged as `site.core_refresh_failed`) |

Reused as-is: `sites.not_found`, `auth.invalid_token`, generic `rate_limit.too_many_requests` (kept as alternate for callers not aware of `core.rate_limited`).

### 5.4 RestRouter registration

Two new `register_rest_route` entries in `RestRouter::register()`, slotted after the existing `/sites/(?P<id>\d+)/themes/(?P<slug>...)/update` block. Two `use` statements added near top: `SitesCoreRefreshController`, `SitesCoreUpdateController`.

### 5.5 CORS

The dashboard CORS filter from F3a already allows the SPA origin for `defyn/v1/*` — core endpoints inherit it. P2.3 Task 20 added the regression test; one-line additions extend coverage to the new core routes.

---

## 6. SPA UI

One new component card, two new mutation hooks, one new confirmation dialog. **No new query hook** — core update state arrives through the existing `useSite(siteId)` query, which already polls `/sites/{id}`.

### 6.1 Component tree additions to `SiteDetail`

```
SiteDetail
├── SiteHeader               (existing — Active theme chip from P2.3)
├── SiteCoreCard             NEW — sits between SiteHeader and SiteSummaryCard
├── SiteSummaryCard          (existing)
├── SitePluginsPanel         (existing)
└── SiteThemesPanel          (existing from P2.3)
```

**Placement rationale:** `SiteCoreCard` goes ABOVE `SiteSummaryCard`. Core is the highest-impact update target — when pending, it deserves above-the-fold visibility. When up-to-date, the card stays compact with just the current WP version + meta line.

### 6.2 New files

```
apps/web/src/features/sites/
├── components/
│   ├── SiteCoreCard.tsx                  NEW
│   └── ConfirmUpdateCoreDialog.tsx       NEW
├── hooks/
│   ├── useRefreshSiteCore.ts             NEW
│   └── useUpdateSiteCore.ts              NEW
└── api/
    └── site.ts                           MODIFY — extend Zod schema with 5 new core fields + 2 transient meta
```

### 6.3 `SiteCoreCard` — four visual states

Card layout sketches (one row each for clarity):

**Idle, no update available:**
```
┌─ WordPress 7.0  ──────────────────────────────────────┐
│  PHP 8.3.31 · Auto-updates ON          [ ↻ refresh ]  │
└───────────────────────────────────────────────────────┘
```

**Idle, update available:**
```
┌─ WordPress 7.0  ──────────────────────────────────────┐
│  Update available → 7.0.1  (security & maintenance)   │
│  PHP 8.3.31 · Auto-updates ON                         │
│                    [ ↻ refresh ]  [ Update to 7.0.1 ] │
└───────────────────────────────────────────────────────┘
```

**Updating (queued or updating state):**
```
┌─ WordPress 7.0 → 7.0.1  ──────────────────────────────┐
│  ⟳ Upgrading… approximately 30–90 seconds.            │
│  Site may briefly show a maintenance message.         │
└───────────────────────────────────────────────────────┘
```
(Full-width amber background, spinner, no buttons.)

**Failed:**
```
┌─ WordPress 7.0  ──────────────────────────────────────┐
│  ⚠ Last update attempt failed: Disk full ⓘ            │
│  Update available → 7.0.1                             │
│                    [ ↻ refresh ]  [ Retry update ]    │
└───────────────────────────────────────────────────────┘
```
(Red error banner, tooltip on ⓘ shows truncated `last_core_update_error`. Retry replaces Update.)

**State → render mapping:**

| `core_update_state` | `core_update_available` | Variant |
|---|---|---|
| `idle` | `0` | "Up to date" — version + meta only |
| `idle` | `1` | "Update available" — version diff + Update button |
| `queued` or `updating` | (any) | "Upgrading…" — full-width amber + spinner |
| `failed` | (any) | "Failed" — red banner + Retry button |

### 6.4 `ConfirmUpdateCoreDialog` — single stronger variant

There's no active/inactive dichotomy here (every core update has the same risk profile), but the warning copy is **stronger than the P2.3 active-theme dialog** because:

- Downtime is guaranteed (every core upgrade enters maintenance mode briefly)
- Irreversibility is total (no rollback path within WP itself)
- File system changes are broader (more files than a theme/plugin)

**Dialog content:**

```
┌─ Update WordPress 7.0 → 7.0.1? ───────────────────────────────┐
│                                                                │
│ ⚠ Site goes briefly offline during the upgrade                 │
│                                                                │
│ The frontend serves a "Briefly unavailable for scheduled       │
│ maintenance" message for 30–90 seconds. Logged-in users see    │
│ wp-admin become unavailable.                                   │
│                                                                │
│ ⚠ Downgrades require SFTP                                      │
│                                                                │
│ If 7.0.1 introduces an incompatibility, restoring 7.0 means    │
│ uploading WP core files manually. There is no in-WordPress     │
│ rollback. Make sure recent backups exist before continuing.    │
│                                                                │
│ Auto-updates ON: WordPress will install this update            │
│ automatically within ~24 hours regardless. Updating now just   │
│ does it sooner. ←── conditional: shown only when               │
│                    is_auto_update_enabled === true             │
│                                                                │
│              [ Cancel ]      [ Yes, update WordPress core ]    │
└────────────────────────────────────────────────────────────────┘
```

**Divergences from P2.3 active-theme dialog:**

- **TWO** warning banners (downtime + downgrade) instead of one
- Conditional fourth paragraph: shown when `is_auto_update_enabled === true`, omitted otherwise
- Primary button color: same `bg-amber-600 hover:bg-amber-700` as active-theme (same severity tier)
- Primary button label: `"Yes, update WordPress core"` (explicit + verbose, harder to fat-finger than just "Yes")
- Cancel is the focused default (consistent across all DefynWP confirm dialogs)

### 6.5 New mutation hooks

**`useUpdateSiteCore(siteId)`** — TanStack Query mutation:
- POSTs to `/sites/{id}/core/update`
- Optimistic write: invalidates `['sites', siteId]` immediately so card re-renders in `queued` state without waiting
- **`useSite(siteId)` polling pin**: while the mutation is in flight OR while `useSite` data has `core_update_state IN ('queued','updating')`, the `useSite` query polls every 30s. Settles back to 5min stale when state hits `idle` or `failed`. Mirrors P2.3 Task 25 pattern.
- Hard cap: 5min polling cap (matches P2.3)
- Toast on 429 / 502

**`useRefreshSiteCore(siteId)`** — same shape as `useRefreshSiteThemes` from P2.3 Task 25, retargeted to the core refresh endpoint. Toast on failure.

### 6.6 Extended `useSite` query

The existing `useSite(siteId)` query already returns the full site row. P2.4 extends the Zod schema to allow the new core fields:

```ts
// apps/web/src/features/sites/api/site.ts (modified)
export const siteSchema = z.object({
  // ... existing fields ...
  core_update_available:       z.boolean(),
  core_update_version:         z.string().nullable(),
  core_update_state:           z.enum(['idle', 'queued', 'updating', 'failed']),
  last_core_update_error:      z.string().nullable(),
  last_core_update_attempt_at: z.string().nullable(),

  // Transient meta from /status, not persisted to wp_defyn_sites.
  is_minor_update:        z.boolean().optional(),
  is_auto_update_enabled: z.boolean().optional(),
});
```

**Polling behavior:** while ANY of:
- `core_update_state` is `queued` or `updating`, OR
- any plugin row's `update_state` (from `useSitePlugins`) is `queued` or `updating`, OR
- any theme row's `update_state` (from `useSiteThemes`) is `queued` or `updating`

the relevant query polls at 30s. Each query owns its own polling decision; the three polling paths are independent.

### 6.7 Reuses from P2.1–P2.3 (no rebuilding)

- `Button`, `Card`, `Tooltip` primitives from shadcn-vue
- `ConfirmDialog` base component (P2.2 Task 20)
- `useToast`
- TanStack Query keys: `['sites', siteId]` shared with `useSite`; `useUpdateSiteCore` invalidates that key
- MSW handler registration in the existing `apps/web/src/test/handlers.ts`
- Lucide icons: `RefreshCw`, `AlertCircle`, `AlertTriangle`, `CheckCircle`

---

## 7. Testing strategy

Same TDD discipline as P2.1–P2.3: RED → GREEN → COMMIT per task. ~80 new tests total.

### 7.1 Connector tests (~20)

**Unit:**

- `tests/Unit/SiteInfo/CollectorCoreTest.php` — extends existing `CollectorTest`. Seeds `update_core` site-transient with synthetic `'upgrade'` response. Asserts:
  - `core.update_available` = true when transient has upgrade response
  - `core.update_version` matches the seeded version
  - `core.is_minor_update` = true for 7.0 → 7.0.1
  - `core.is_minor_update` = false for 7.0 → 7.1
  - `core.is_auto_update_enabled` respects `WP_AUTO_UPDATE_CORE` constant (4 sub-cases: undefined, `true`, `'minor'`, `false`) — each in a separate process via `@runInSeparateProcess`
- `tests/Unit/SiteInfo/CoreUpgraderServiceTest.php` — service unit tests with constructor-injected upgrader stub:
  - `testUpgradeWithNoUpdateThrowsNoCoreUpdateAvailable`
  - `testUpgradeWithMajorBumpThrowsMajorUpdateBlocked`
  - `testUpgradeWithFalseReturnThrowsCoreUpgradeFailed`
  - `testUpgradeWithWpErrorThrowsCoreUpgradeFailed`
  - `testUpgradeSuccessReturnsExpectedShape`

**Integration (`WP_UnitTestCase`, `@group integration`):**

- `tests/Integration/Rest/StatusCoreExtensionTest.php` — signed `GET /status` returns the new `core` sub-object with all 4 fields; existing keys still present (backward compat)
- `tests/Integration/Rest/CoreRefreshTest.php` — signed `POST /core/refresh`:
  - Success path calls `wp_version_check()`, returns refreshed `core` payload
  - Failure path: `pre_set_site_transient_update_core` filter returning `false` → 502 `core.refresh_failed`
- `tests/Integration/Rest/CoreUpdateTest.php` — 6 cases mirroring `ThemeUpdateTest`:
  - `testNoUpdateAvailableReturns409` → `core.no_update_available`
  - `testMajorBumpReturns409` → `core.major_update_blocked`
  - `testSuccessReturns200WithExpectedShape`
  - `testStdoutFromUpgraderDoesNotCorruptResponse` — verbatim copy of P2.2.1 regression with `Core_Upgrader` stub
  - `testUpgradeFailureReturns502` → `core.update_failed`
  - `testInvalidPathReturns404FromRouter` (defensive)
- `tests/Integration/Rest/CoreUpdateLockTest.php` — shared `defyn_connector_upgrade_in_flight` lock with FIVE scenarios:
  - Plugin upgrade in flight → core update returns 409 `connector.upgrade_in_progress`
  - Theme upgrade in flight → core update returns 409
  - Core upgrade in flight → plugin update returns 409 (cross-resource symmetric)
  - Core upgrade in flight → theme update returns 409
  - Lock auto-releases on success AND on exception (extends P2.3 Task 6 coverage)

### 7.2 Dashboard tests (~40)

**Schema (`tests/Unit/Schema/`):**

- `SchemaVersionMigrationV5Test.php`:
  - `testSchemaVersionConstantIsFive`
  - `testActivationAddsAllFiveCoreColumns` — fresh activation; `SHOW COLUMNS` returns each new column with correct nullability + default
  - `testActivationIsIdempotent` — second `ensureSchema()` call is a no-op
  - `testActivationMigratesV4InstallCleanly` — drop the 5 new columns from a populated table; run `ensureSchema`; columns added without data loss
  - `testIndexAddedAndIdempotent`

**Repository (`tests/Integration/Services/SitesRepositoryCoreTest.php`):**

- `testMarkCoreUpdateRequestedSetsQueuedAndClearsError`
- `testMarkCoreUpdatingFlipsState`
- `testMarkCoreUpdateSucceededBumpsVersionClearsAvailableAndError`
- `testMarkCoreUpdateFailedTruncatesLongError` (1200-char message → 1000 chars stored)
- `testMarkSyncedPropagatesCoreFieldsFromStatusPayload`
- `testMarkSyncedHealsStuckFailedWhenIncomingHasNoUpdateAvailable` — day-1 single-row heal logic
- `testMarkSyncedDoesNotHealWhenUpdateStillAvailable`

**Sync service (`tests/Integration/Services/SyncServiceCoreTest.php`):**

- `testSyncWritesCoreFieldsOnSuccess`
- `testSyncLogsCoreInventorySyncedOnStateChange` (was-not → now-yes)
- `testSyncDoesNotLogCoreInventorySyncedWhenUnchanged` (rate-control safety)

**AS jobs (`tests/Integration/Jobs/`):**

- `RefreshSiteCoreTest.php` — success (writes core columns + logs `core_inventory.refreshed`); HTTP failure (logs `site.core_refresh_failed`, does not clobber columns)
- `UpdateSiteCoreTest.php` — direct mirror of `UpdateSiteThemeTest` with 6 scenarios:
  - Success path → `markCoreUpdateSucceeded` + `core_update.succeeded` triplet ending event
  - 409 `core.no_update_available` → success-by-other-means + `core_update.succeeded_no_change`
  - 409 `core.major_update_blocked` → immediate fail, no retry, `core_update.blocked_major` event
  - 409 `connector.upgrade_in_progress` → exponential backoff retry (60s/120s/240s/480s/960s)
  - 5th retry exhaustion → `markCoreUpdateFailed` + `core_update.failed` with `error_code='retry_exhausted'`
  - 502 → `markCoreUpdateFailed` + `core_update.failed` (no retry)
  - Regression: timeout assertion that `300` is passed to `SignedHttpClient::signedPostJson`

**REST controllers (`tests/Integration/Rest/`):**

- `SitesCoreRefreshTest.php` — 202 schedules `defyn_refresh_site_core`; owner-scoped 404; rate limit 429 after 7th call/hour; separate-bucket assert (6 plugins refreshes do NOT block 1st core refresh)
- `SitesCoreUpdateTest.php`:
  - `testNotOwnedReturns404`
  - `testNoUpdateAvailableReturns409` → `core.no_update_available_for_site`
  - `testUpdateInProgressReturns409` → `core.update_in_progress`
  - `testMajorBumpReturns409` → `core.major_update_blocked` (dashboard-side fast-fail)
  - `testHappyPathReturns202QueuedState`
  - `testRateLimit429AfterFourthCall` — **3/hr bucket** (asserts the tighter budget vs themes/plugins)
  - `testCoreUpdateBucketSeparateFromPluginsUpdate`

**`Plugin::boot` wiring (`tests/Integration/PluginBootASHookCoreTest.php`):**

- `testRefreshSiteCoreHookRegistered`
- `testUpdateSiteCoreHookRegistered`
- `testSyncSiteAlsoSchedulesCoreRefresh` — asserts `SyncSite::handle` fires all 3 refresh hooks (plugins + themes + core)

### 7.3 SPA tests (~20)

**Vitest + RTL + MSW:**

- `api/site.test.ts` — extend existing Zod test: parse payload with 5 new core fields; reject `core_update_state` enum violation
- `hooks/useUpdateSiteCore.test.ts`:
  - POSTs to correct endpoint, invalidates `['sites', siteId]`
  - Optimistic transition to `queued`
  - Polling pin while `core_update_state IN ('queued','updating')`
  - Settles on `idle` or `failed`
  - 5min hard cap
- `hooks/useRefreshSiteCore.test.ts` — mirror of `useRefreshSiteThemes.test.ts`
- `components/SiteCoreCard.test.tsx` — renders all 4 states correctly:
  - idle no-update: just version + "Up to date"
  - idle update-available: version diff + Update button
  - updating: full-width amber + spinner + duration copy
  - failed: red banner + Retry + tooltip on ⓘ
- `components/ConfirmUpdateCoreDialog.test.tsx`:
  - Title renders version diff
  - BOTH warning banners present
  - "Auto-updates ON" paragraph conditional on `is_auto_update_enabled === true`
  - Amber primary button + "Yes, update WordPress core" label
  - Cancel has default focus
- `routes/SiteDetail.coreCard.test.tsx` — `SiteCoreCard` renders ABOVE `SiteSummaryCard` in DOM; uses `useSite` data; absent when site status is `pending`

### 7.4 Out-of-scope tests (YAGNI)

- Major-version updates (deferred to P2.4.1 — the 409 block path IS tested; actual major execution is not)
- WP core auto-update detection beyond `WP_AUTO_UPDATE_CORE` constant
- Backup-before-upgrade (rejected in approach C)
- Multisite network upgrades (out of scope)
- Estimated time-to-completion accuracy (the "30–90 seconds" copy is a static hint, not predictive)

### 7.5 Coverage gate

≥80% on new modules. CI workflow already runs phpunit + vitest for all three packages; auto-discovers new tests.

**Test isolation note:** the 4 `is_auto_update_enabled` sub-cases use `@runInSeparateProcess` PHPUnit annotation because `WP_AUTO_UPDATE_CORE` is a PHP constant that cannot be `undefined()` between assertions.

---

## 8. Manual smoke flow

Run after all CI tests pass, before tagging.

### 8.1 Pre-smoke setup

1. Build artifacts using P2.3 lessons (`composer dump-autoload --no-dev --classmap-authoritative` first, then zip excluding `vendor/wordpress/*` + `vendor/johnpbloch/*`):
   - `~/Desktop/defyn-connector-v0.1.6-<date>.zip` (~65KB target)
   - `~/Desktop/defyn-dashboard-v0.5.0-<date>.zip` (~550KB target)
2. Install both on production (connector at `smartcoding.com.au`, dashboard at `defynwp.defyn.agency`).
3. Verify `plugins.php` shows new versions. Schema self-heal auto-runs v4→v5 migration on first request — no manual deact+react.
4. SmartCoding handshake is in place from P2.3 smoke (`site_id=1`). No re-handshake unless v0.5.0 install accidentally drops sites table.

### 8.2 Smoke matrix (13 steps)

| # | Action | Expected | Verifies |
|---|---|---|---|
| 1 | `curl GET /sites/1` | Response includes 5 new core fields (`core_update_available=0` if no update, `core_update_state='idle'`, etc.) | Schema v5 migration applied; `markSynced` wrote columns |
| 2 | `curl POST /sites/1/core/refresh` | `202 {"scheduled":true,"site_id":1}`; AS job fires within ~60s | Refresh endpoint + AS dispatch + signed connector roundtrip |
| 3 | After job: `curl GET /sites/1` | `core_update_available` reflects production reality | End-to-end `/status` payload propagation through `markSynced` |
| 4 | `curl GET /defyn-connector/v1/status` directly (signed) | Response includes `core: {update_available, update_version, is_minor_update, is_auto_update_enabled}` | Connector status extension live |
| 5 | If no real update is available: SSH to smartcoding, downgrade WP core via `wp core update --version=<prior>`, then refresh | Update appears as available in next sync | Manufactured-update path (only if real update absent) |
| 6 | `curl POST /sites/1/core/update` | `202 {"scheduled":true,"site_id":1,"core_update_state":"queued"}`; AS job runs; row transitions `queued → updating → idle` with new `wp_version` | Full upgrade pipeline |
| 7 | During #6: load `https://smartcoding.com.au` in browser | Brief `.maintenance` 503 page (< 60s), then resumes normally | Maintenance mode; site doesn't serve corrupted PHP |
| 8 | `curl GET /sites/1/activity` | `core_update.requested → core_update.started → core_update.succeeded` triplet, in order | Activity logging correctness |
| 9 | 4× `POST /sites/1/core/update` in <1hr (with manufactured updates to dodge preflight) | 4th call returns `429 core.rate_limited` | **3/hr** bucket enforcement (tighter than themes/plugins at 6/hr) |
| 10 | Concurrent: queue a plugin update first, then fire core update | Core update returns `409 connector.upgrade_in_progress` | Cross-resource lock — core ↔ plugin |
| 11 | Manually `wp_db_query("UPDATE wp_defyn_sites SET core_update_state='failed', last_core_update_error='Old error', core_update_available=0 WHERE id=1")`, then trigger refresh | After sync: row state heals to `idle`, error cleared | Day-1 single-row heal-by-sync logic |
| 12 | SPA at `app.defynwp.defyn.agency/sites/1` → scroll to `SiteCoreCard` | Card matches API state; clicking "Update WordPress core" opens amber dialog with BOTH warning banners + conditional "Auto-updates ON" paragraph | SPA divergent confirm copy |
| 13 | Inject synthetic major-bump: temporarily set `wp_options.update_core` transient to claim `7.X.0 → 8.0`, then `POST /sites/1/core/update` | `409 core.major_update_blocked` (dashboard-side fast-fail, no AS roundtrip) | P2.4.1 boundary enforced at both connector + dashboard |

### 8.3 Tag + push

After all 13 steps pass:

```
git tag -a p2-4-core-updates-complete -m "P2.4 — WP core updates (minor only) shipped"
git push origin p2-4-core-updates-complete
```

**Push only after manual smoke is green** — same discipline as P2.1/P2.2/P2.3.

---

## 9. Deliberately out of scope (deferred)

| Deferred to | What's not in P2.4 |
|---|---|
| **P2.4.1 (planned next)** | Major-version updates (7.0 → 7.1+) — the `core.major_update_blocked` envelope is the placeholder; P2.4.1 adds compatibility pre-check + heavier confirmation UX (red, not amber) |
| **P2.5 (planned)** | Operator overview dashboard — cross-site rollups ("X plugins + Y themes + Z core updates across N sites"). Current `Home.tsx → /sites` redirect stays in place |
| **Future** | Pre-upgrade backups + rollback (needs backup management infrastructure — Phase 3+) |
| **Future** | Beta/RC channel opt-in (some operators want `wp_version_check()` to surface `update_core_dev`) |
| **Future** | Per-site `WP_AUTO_UPDATE_CORE` constant editing from the dashboard (read-only in P2.4) |
| **Future** | Multisite network upgrades (`wp core update-db` after core upgrade — separate codepath) |
| **Future** | Scheduled / deferred upgrades ("update tonight at 3am") — AS supports this trivially; UX not designed yet |
| **Future** | Exposing captured upgrader output as a debug log in the SPA (we already swallow STDOUT to avoid corrupting JSON; surfacing it is a separate UX) |

---

## 10. Implementation notes for the plan author

- Branch off `main` (after P2.3 merges) OR `p2-3-themes` (chain on the P2.3 branch). Per Pradeep's P2.3 close-out — pick during planning, not design.
- ~20 TDD tasks expected (smaller than P2.3's 29 because no new model/repository class; single-resource = smaller SPA surface)
- Reuse the P2.3 plan skeleton (`2026-06-06-p2-3-themes.md`) — task structure 1:1 mirrors with `Theme → Core` retyping and single-row state-machine simplifications:
  - **Phase 1 (Connector):** ~5 tasks — Collector extension, refresh controller, upgrader service + 3 exceptions, update controller + STDOUT regression + cross-resource lock test, RestRouter registration + cache headers
  - **Phase 2 (Connector release):** 1 task — v0.1.6 bump
  - **Phase 3 (Dashboard):** ~6 tasks — schema v5 migration (+ idempotent column adds), SitesRepository extensions, SyncService extension, RefreshSiteCore job, UpdateSiteCore job (multi-branch including 4 response paths), `Plugin::boot` wiring + SyncSite extension
  - **Phase 4 (Dashboard REST + release):** ~3 tasks — RateLimit::sitesCoreRefresh + RateLimit::coreUpdate, the 2 controllers, dashboard v0.5.0 + CORS regression
  - **Phase 5 (SPA):** ~4 tasks — Zod schema extension + MSW handlers, useUpdateSiteCore + useRefreshSiteCore, SiteCoreCard, ConfirmUpdateCoreDialog, SiteDetail integration
  - **Phase 6 (Ship):** 2 tasks — build zips + 13-step manual smoke matrix, tag + push (gated on smoke)
- Include the day-1 carry-overs from P2.3 lessons: heal-by-sync in `markSynced`, STDOUT discipline + regression test in connector controller, full `core_update.requested → started → succeeded|failed` triplet logging
- The 300-second timeout on `UpdateSiteCore`'s `signedPostJson` call is a regression-testable constant — assert it explicitly in the job test
- The `3/hr` bucket on `RateLimit::coreUpdate` is the lone divergence from P2.3 — make sure the integration test asserts `4th call → 429`, not `7th` like themes
- Final task content is §8.2 (13-step smoke matrix) verbatim — same pattern as P2.3 Task 28
