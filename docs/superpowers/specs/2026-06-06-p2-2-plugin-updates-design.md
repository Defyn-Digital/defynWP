# P2.2 — Plugin updates design

> Phase 2 sub-phase 2 (P2.2). Builds on the read foundation shipped in [P2.1](2026-06-04-p2-1-plugin-inventory-design.md): every managed site exposes its plugin inventory with `update_available` flags. P2.2 closes the loop — operator clicks "Update" on a plugin row in the SPA and DefynWP actually runs the WordPress upgrade on the managed site. First operator-actionable feature that fulfils the foundation spec's [§12 "Actually doing anything with a managed site"](2026-04-18-defyn-foundation-design.md).
>
> Lays the write foundation under P2.3 (themes/core updates) and P2.4 (fleet-wide upgrades).

---

## 1. Goal

### 1.1 What "P2.2 done" looks like

- An operator clicks **Update** on a plugin row in `SitePluginsPanel`.
- A shadcn `AlertDialog` confirms the version diff (e.g., `1.1.0 → 2.0.0`).
- On confirm, the row immediately enters an in-flight state (`Updating…`, spinner, dimmed).
- 30–120 s later the row settles: either the new version is shown and the update badge is gone (success), or a ⚠ icon + `Retry` button surfaces with the captured error (failure).
- The activity log records `plugin_update.requested`, `.started`, `.succeeded`/`.failed` events.
- The dashboard does not block the SPA — the upgrade runs in an Action Scheduler job on the dashboard side, which calls the connector synchronously with an extended HTTP timeout.
- The connector runs WordPress's stock `Plugin_Upgrader`, captures the upgrader's skin messages, and reports back.
- A second concurrent upgrade attempt on the same site is serialized via a connector-side transient lock; the dashboard retries with exponential backoff up to 5 times.

### 1.2 Out of scope for P2.2

Explicitly deferred to later sub-phases:

- **Update all (N)** — multi-row SPA-side action that fires N single requests. Deferred to **P2.2.1** once we have real telemetry on operator behavior.
- **Themes** and **WP core** updates — covered in **P2.3**.
- **Fleet-wide upgrades** across many sites — **P2.4**.
- **Auto-update flag** per plugin.
- **Pre-upgrade backups** — relies on Kinsta's site-level backups and WP's own download-rollback behavior.
- **Custom maintenance-mode page** — WP's stock `.maintenance` file handles this for ~1–2 min.
- **Cancel an in-flight upgrade** — once `Plugin_Upgrader` is mid-extraction, cancellation is unsafe. Operator waits.
- **Pre-flight checks** ("disk space?", "PHP version compatibility?") — we trust WP's own checks.

---

## 2. Architecture overview

```
                 SPA (apps/web)
                    │
                    │ POST /defyn/v1/sites/{id}/plugins/{slug}/update
                    │   (Bearer JWT)
                    ▼
   ┌──────────────────────────────────────────────────────────┐
   │  Dashboard — SitesPluginsUpdateController                │
   │    • RateLimit::pluginsUpdate (6/hour per user+site+slug)│
   │    • Owner check (404 if not owner)                      │
   │    • Plugin must exist in wp_defyn_site_plugins          │
   │    • update_available must be 1                          │
   │    • update_state must not already be queued/updating    │
   │    • Optimistic write: update_state='queued'             │
   │    • Activity log: plugin_update.requested               │
   │    • Schedule AS: defyn_update_site_plugin($siteId,$slug)│
   │    • Return 202 {scheduled, site_id, slug}               │
   └────────────────────────────┬─────────────────────────────┘
                                │
                                ▼ wp-cron / Action Scheduler picks up
   ┌──────────────────────────────────────────────────────────┐
   │  Dashboard — UpdateSitePlugin (AS handler)               │
   │    • Set update_state='updating'                         │
   │    • Activity log: plugin_update.started                 │
   │    • Vault::decrypt(site_private_key)                    │
   │    • SignedHttpClient::signedPostJson(                   │
   │        $url, [], $key, $canonicalPath,                   │
   │        timeoutSeconds: 120)                              │
   │    • Branch on response:                                 │
   │      ─ 200 ok →                                          │
   │          update_state='idle'                             │
   │          version = new_version                           │
   │          update_available = 0                            │
   │          last_update_error = NULL                        │
   │          activity log: plugin_update.succeeded           │
   │      ─ 409 plugins.update_in_progress →                  │
   │          activity log: plugin_update.retry               │
   │          as_schedule_single_action(+60s * 2^attempt)     │
   │          max 5 retries, then fail                        │
   │      ─ anything else →                                   │
   │          update_state='failed'                           │
   │          last_update_error = response['error_message']   │
   │          activity log: plugin_update.failed              │
   └────────────────────────────┬─────────────────────────────┘
                                │ signed HTTPS, ±300s window
                                ▼
   ┌──────────────────────────────────────────────────────────┐
   │  Connector — PluginUpdateController                      │
   │    • VerifySignatureMiddleware (F6)                      │
   │    • Slug pattern validation: ^[a-z0-9-]{1,80}$          │
   │    • Try set_transient('defyn_connector_upgrade_in_     │
   │      flight', $slug, 600). If existing → 409.            │
   │    • PluginUpgraderService::upgrade($slug)               │
   │    • finally: delete_transient(...)                      │
   │    • Return 200 with                                     │
   │      {success, slug, previous_version, new_version,      │
   │       server_time}                                       │
   └──────────────────────────────────────────────────────────┘
```

**Trust boundary unchanged:** every cross-tier hop is Ed25519-signed with ±300 s window + nonce store (foundation F2/F5/F6 contract). Same `Signer::canonical()` shape as `/plugins`.

---

## 3. Connector — new REST endpoint

### 3.1 POST `/defyn-connector/v1/plugins/{slug}/update`

**Auth:** Signed (`VerifySignatureMiddleware`).
**Body:** Empty (the slug is in the path; nothing else needs to be communicated).
**Path param:** `slug` must match `^[a-z0-9-]{1,80}$`. Out-of-range → 400 `plugins.invalid_slug`.

**Success response (200):**

```json
{
  "success": true,
  "slug": "gbposter-for-google-business-profile",
  "previous_version": "1.1.0",
  "new_version": "2.0.0",
  "server_time": 1796736000
}
```

**Errors:**

| Code | HTTP | When |
| --- | --- | --- |
| `plugins.invalid_slug` | 400 | Path slug fails the regex |
| `plugins.unknown_slug` | 404 | Slug not in `get_plugins()` |
| `plugins.no_update_available` | 409 | `get_site_transient('update_plugins')` says no upgrade pending (defensive — dashboard shouldn't send this) |
| `plugins.update_in_progress` | 409 | Lock transient already held; another upgrade on this site is mid-flight |
| `plugins.update_failed` | 502 | `Plugin_Upgrader::upgrade()` returned a `WP_Error` or the upgrader skin captured an error message |
| `connector.signature_*` | 401 | Signature middleware rejections (foundation) |

**Cache headers:** standard `Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private` via the existing `applyNoCacheHeaders` filter in `RestRouter`.

### 3.2 Locking — per-site transient

WordPress's `Plugin_Upgrader` touches `.maintenance`, manipulates `wp_cache_*`, and writes to `wp-content/plugins/{slug}/`. Two simultaneous upgrades on the same install can corrupt the filesystem or leave `.maintenance` stuck. Solution:

```php
// At controller entry, before doing any real work
$existing = get_transient('defyn_connector_upgrade_in_flight');
if ($existing !== false) {
    return ErrorResponse::create(409, 'plugins.update_in_progress',
        sprintf('Another upgrade is in progress (%s).', $existing));
}
set_transient('defyn_connector_upgrade_in_flight', $slug, 600); // 10 min TTL

try {
    $result = $this->upgrader->upgrade($slug);
    // …shape response from $result
} finally {
    delete_transient('defyn_connector_upgrade_in_flight');
}
```

**TTL = 600 s** covers the worst legitimate upgrade plus a margin; a process crash that holds the lock auto-expires.

**Lock granularity = per-site, NOT per-slug.** Two different plugins requested in quick succession serialize. Justification: see §12.

### 3.3 `Plugin_Upgrader` integration

```php
// In PluginUpgraderService::upgrade(string $slug): array
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';

// 1. Resolve slug → plugin_file
$plugins = get_plugins();
$pluginFile = null;
foreach ($plugins as $file => $data) {
    $folder = strtok($file, '/');
    if ($folder === $slug) {
        $pluginFile = $file;
        $previousVersion = $data['Version'];
        break;
    }
}
if ($pluginFile === null) {
    throw new UnknownSlugException($slug);
}

// 2. Verify WP knows about an update
$updates = get_site_transient('update_plugins');
if (empty($updates->response[$pluginFile])) {
    throw new NoUpdateAvailableException($slug);
}

// 3. Run the upgrade with a silent skin that captures messages
$skin = new \Defyn\Connector\SiteInfo\CapturingUpgraderSkin();
$upgrader = new \Plugin_Upgrader($skin);
$result = $upgrader->upgrade($pluginFile);

// 4. Branch on result
if ($result === false || is_wp_error($result)) {
    $message = is_wp_error($result) ? $result->get_error_message() : $skin->lastErrorMessage();
    throw new UpgradeFailedException($message);
}

// 5. Re-read version
$newPlugins = get_plugins();
$newVersion = $newPlugins[$pluginFile]['Version'] ?? $previousVersion;

return [
    'success' => true,
    'slug' => $slug,
    'previous_version' => $previousVersion,
    'new_version' => $newVersion,
    'server_time' => time(),
];
```

`CapturingUpgraderSkin` extends `\WP_Upgrader_Skin` and overrides `feedback($string, ...$args)` + `error($errors)` to collect messages into an in-memory array. We only echo back the last error message to the dashboard — full transcript stays in the connector's PHP error log.

### 3.4 Connector version + changelog

Connector v0.1.3 → **0.1.4** (patch — single new endpoint mirroring P2.1's pattern; no schema, no new dependency, no breaking change).

`readme.txt` changelog entry:

```
= 0.1.4 =
* Feature: new POST /plugins/{slug}/update signed endpoint runs Plugin_Upgrader for the requested plugin and returns the new version. Per-site transient lock prevents concurrent upgrades on the same install (P2.2).
```

---

## 4. Connector — new code

### 4.1 `Defyn\Connector\SiteInfo\PluginUpgraderService`

Pure-service class (no WP hooks). Public surface:

```php
public function upgrade(string $slug): array
```

Returns the success array shape (§3.3). Throws three custom exception classes — `UnknownSlugException`, `NoUpdateAvailableException`, `UpgradeFailedException` — that the controller maps to error envelopes. All three extend a shared `PluginUpgradeException` base.

### 4.2 `Defyn\Connector\SiteInfo\CapturingUpgraderSkin`

Extends `\WP_Upgrader_Skin`. Overrides:

- `feedback($feedback, ...$args)` → append to `$this->messages[]`
- `error($errors)` → append to `$this->errors[]`
- `lastErrorMessage(): ?string` → public accessor

### 4.3 `Defyn\Connector\Rest\PluginUpdateController`

Mirrors `PluginsRefreshController` shape (P2.1 Task 3). `handle(WP_REST_Request $request): WP_REST_Response` does:

1. Read `$slug` from path, validate.
2. Lock check + acquire.
3. Try/finally around `PluginUpgraderService::upgrade()`.
4. Map exceptions to error envelopes (`ErrorResponse::create(...)`).
5. On success, return `WP_REST_Response($result, 200)`.

### 4.4 `Defyn\Connector\Rest\RestRouter` — register the route

One new `register_rest_route` call:

```php
register_rest_route(self::NAMESPACE, '/plugins/(?P<slug>[a-z0-9-]{1,80})/update', [
    'methods'             => 'POST',
    'callback'            => [new PluginUpdateController(), 'handle'],
    'permission_callback' => [VerifySignatureMiddleware::class, 'check'],
]);
```

The regex in the route doubles as input validation (WP rejects non-matching slugs with `rest_no_route` → 404).

---

## 5. Dashboard — schema migration v2 → v3

### 5.1 Schema delta

Add three columns + one index to `wp_defyn_site_plugins`:

```sql
ALTER TABLE wp_defyn_site_plugins
  ADD COLUMN update_state ENUM('idle','queued','updating','failed') NOT NULL DEFAULT 'idle',
  ADD COLUMN last_update_error TEXT NULL,
  ADD COLUMN last_update_attempt_at DATETIME NULL,
  ADD KEY update_state (update_state);
```

Encoded as `dbDelta`-compatible `CREATE TABLE` in `Schema\SitePluginsTable::createSql()` (additive — existing rows get the defaults safely).

| Column | Type | Purpose |
| --- | --- | --- |
| `update_state` | enum, default `idle` | 4-state machine: `idle / queued / updating / failed`. KEY for future fleet queries (`WHERE update_state='failed'`). |
| `last_update_error` | TEXT, nullable | Last error message, capped at 1000 chars by the writer. NULL when state ≠ `failed`. Cleared on next successful upgrade. |
| `last_update_attempt_at` | DATETIME, nullable | Timestamp of last attempt regardless of outcome. NULL until first attempt. |

### 5.2 Migration mechanism

`Activation::SCHEMA_VERSION` bumps `2 → 3`. `Activation::activate()` runs `dbDelta()` over the new `createSql()`. The schema self-heal follow-up filed yesterday (see MEMORY ops note) would remove the manual deactivate+reactivate dance; until that lands, the deploy runbook MUST include it for the dashboard upgrade.

### 5.3 No changes to `wp_defyn_sites`

P2.2 doesn't touch the sites table. Site-level state stays as F1/F6 left it.

---

## 6. Dashboard — sync flow + supporting code

### 6.1 `SitePluginsRepository` extensions

Four new write methods (idempotent; all assume the `(site_id, slug)` row already exists from P2.1's sync):

```php
public function markUpdateRequested(int $siteId, string $slug, string $now): void;
public function markUpdating(int $siteId, string $slug, string $now): void;
public function markUpdateSucceeded(int $siteId, string $slug, string $newVersion, string $now): void;
public function markUpdateFailed(int $siteId, string $slug, string $errorMessage, string $now): void;
```

- `markUpdateRequested`: `update_state='queued'`, `last_update_attempt_at=$now`, `last_update_error=NULL`
- `markUpdating`: `update_state='updating'`
- `markUpdateSucceeded`: `update_state='idle'`, `version=$newVersion`, `update_available=0`, `update_version=NULL`, `last_update_error=NULL`
- `markUpdateFailed`: `update_state='failed'`, `last_update_error=substr($message, 0, 1000)`

Plus one read method used by the controller's guard:

```php
public function findRowForSiteAndSlug(int $siteId, string $slug): ?array;
```

Returns the raw row array or null. Used to check `update_available` and current `update_state` before scheduling.

### 6.2 `SignedHttpClient` — extended with optional timeout

Add `int $timeoutSeconds = 30` as the last parameter on `signedPostJson` and `signedGet`. Default keeps every existing callsite (F5 ConnectController, F6 SyncSite, F6 HealthPing, P2.1 RefreshSitePlugins) at the current 30 s. `UpdateSitePlugin::handle` passes `timeoutSeconds: 120`.

Implementation: pass the value into Symfony HttpClient's `'timeout'` option. Foundation F2 test scaffold (`MockHttpClient`) accepts the param transparently.

### 6.3 `UpdateSitePlugin` Action Scheduler job

New file `packages/dashboard-plugin/src/Jobs/UpdateSitePlugin.php`. Constructor takes the same injectable dependencies as `RefreshSitePlugins` (`SitesRepository`, `SitePluginsRepository`, `SignedHttpClient`, `ActivityLogger`, `Vault`). `HOOK = 'defyn_update_site_plugin'`.

Handler signature: `handle(int $siteId, string $slug, int $attempt = 0): void`. AS schedules pass the third arg on retries via `as_schedule_single_action($when, self::HOOK, [$siteId, $slug, $attempt + 1])`.

Flow (already diagrammed in §2):

1. Load site row, decrypt private key.
2. `repo->markUpdating(...)`, `log->log(null, $siteId, 'plugin_update.started', [...])`.
3. Call connector: `signedPostJson($url, [], $key, $canonicalPath, timeoutSeconds: 120)`.
4. Branch on response:
   - **Status 200 + body.success** → `markUpdateSucceeded`, log `plugin_update.succeeded` with `{slug, previous_version, new_version}`.
   - **Status 409 + body.error.code == 'plugins.update_in_progress'** → log `plugin_update.retry` with `{slug, attempt, next_run_at}`; if `attempt < 5`, schedule self at `now + 60 * 2^attempt` seconds; if `attempt >= 5`, `markUpdateFailed` with `"Site is busy after 5 retries."` and log `plugin_update.failed`.
   - **Any other failure** (HTTP error, transport error, 500/502 from connector with a different error code) → `markUpdateFailed` with `$response['body']['error']['message'] ?? $response['error']`, log `plugin_update.failed`.

Retry backoff: 60 s → 120 s → 240 s → 480 s → 960 s, max 5 attempts (total budget ~32 min).

### 6.4 `Plugin::boot()` — register the new AS hook

```php
add_action('defyn_update_site_plugin', static function (int $siteId, string $slug, int $attempt = 0): void {
    (new UpdateSitePlugin())->handle($siteId, $slug, $attempt);
}, 10, 3);
```

---

## 7. Dashboard — new REST endpoint

### 7.1 POST `/defyn/v1/sites/{id}/plugins/{slug}/update`

**Auth:** Bearer JWT via `RateLimit::pluginsUpdate` (which itself chains `RequireAuth::check` per P2.1 Task 11 pattern).
**Body:** Empty.
**Path params:** `id` integer; `slug` validated regex `^[a-z0-9-]{1,80}$` via the route definition.

**Success response (202):**

```json
{
  "scheduled": true,
  "site_id": 1,
  "slug": "gbposter-for-google-business-profile"
}
```

**Errors:**

| Code | HTTP | When |
| --- | --- | --- |
| `sites.not_found` | 404 | Site doesn't exist or not owned by the authenticated user (anti-enumeration consistent with P2.1) |
| `plugins.not_found_in_inventory` | 404 | `(site_id, slug)` row missing from `wp_defyn_site_plugins` |
| `plugins.no_update_available` | 409 | Row exists but `update_available=0` |
| `plugins.update_already_in_progress` | 409 | Row's `update_state` is `queued` or `updating` |
| `plugins.rate_limited` | 429 | RateLimit::pluginsUpdate exceeded |

Controller order of operations:

1. Auth + rate limit (middleware).
2. `SitesRepository::findByIdForUser($siteId, $userId)` → 404 if null.
3. `SitePluginsRepository::findRowForSiteAndSlug($siteId, $slug)` → 404 if null.
4. If `(int) $row['update_available'] === 0` → 409 (wpdb returns string from TINYINT — cast before compare).
5. If `$row['update_state']` in `('queued', 'updating')` → 409.
6. `repo->markUpdateRequested($siteId, $slug, $now)`.
7. `log->log($userId, $siteId, 'plugin_update.requested', ['slug' => $slug, 'current_version' => $row['version'], 'target_version' => $row['update_version']])`.
8. `as_schedule_single_action(time(), 'defyn_update_site_plugin', [$siteId, $slug, 0])`.
9. Return 202.

### 7.2 `RateLimit::pluginsUpdate`

New static method mirroring `RateLimit::pluginsRefresh` (P2.1 Task 11). Window = 1 hour. Limit = 6 attempts per `(user_id, site_id, slug)`. Storage = same WP transient store the other RateLimit methods use.

Internally chains `RequireAuth::check` first so the route definition reads as `'permission_callback' => [RateLimit::class, 'pluginsUpdate']` — no separate auth callback.

### 7.3 `RestRouter` registration

```php
register_rest_route(self::NAMESPACE, '/sites/(?P<id>\d+)/plugins/(?P<slug>[a-z0-9-]{1,80})/update', [
    'methods'             => 'POST',
    'callback'            => [new SitesPluginsUpdateController(), 'handle'],
    'permission_callback' => [RateLimit::class, 'pluginsUpdate'],
]);
```

---

## 8. SPA — UI design

### 8.1 `SitePluginsRow` — three visual states

Existing P2.1 row renders one shape (idle). P2.2 extends it to three:

**Idle (existing P2.1 behavior, no change for non-upgradable rows):**

```
| Plugin name + slug | Version | — or [badge + Update button] |
```

Update column (formerly just "Update" badge) now shows `update_available=1`:

```jsx
<span className="...badge...">→ {plugin.update_version}</span>
<Button size="sm" onClick={onClickUpdate}>Update</Button>
```

**In-flight (`update_state` in `'queued'` or `'updating'`):** row gets `opacity-70 bg-zinc-50`; button becomes `disabled` with a spinning `Loader2` icon + label `Updating…`.

**Failed (`update_state === 'failed'`):** row gets `bg-red-50`; renders an inline ⚠ icon with a shadcn `Tooltip` containing `plugin.last_update_error` (truncated to ~200 chars + ellipsis); button label becomes `Retry`.

State logic lives in `SitePluginsRow.tsx`; chosen via small `useMemo` returning a discriminated union.

### 8.2 `SitePluginUpdateConfirmDialog`

New component. Wraps shadcn `AlertDialog`. Trigger comes from the row's Update/Retry click. Props: `plugin: Plugin`, `siteId: number`, `open: boolean`, `onOpenChange: (open: boolean) => void`.

Copy (from §3b decision, with accurate wording):

```jsx
<AlertDialogHeader>
  <AlertDialogTitle>Update {plugin.name}</AlertDialogTitle>
  <AlertDialogDescription className="font-mono text-xs text-muted-foreground">
    {plugin.slug}
  </AlertDialogDescription>
</AlertDialogHeader>
<div className="my-3 text-sm">
  <code className="bg-zinc-100 px-1.5 py-0.5 rounded">{plugin.version}</code>
  <span className="mx-2 text-zinc-400">→</span>
  <code className="bg-blue-100 px-1.5 py-0.5 rounded font-semibold">{plugin.update_version}</code>
</div>
<div className="bg-amber-50 border-l-2 border-amber-500 p-2 my-3 text-xs space-y-1">
  <p>The site goes into maintenance mode for the duration (~1–2 min).</p>
  <p>If the upgrade fails to download or install, the existing version stays in place.</p>
</div>
<AlertDialogFooter>
  <AlertDialogCancel>Cancel</AlertDialogCancel>
  <AlertDialogAction onClick={() => mutation.mutate()}>Update</AlertDialogAction>
</AlertDialogFooter>
```

### 8.3 `useUpdateSitePlugin` mutation hook

New file `apps/web/src/lib/mutations/useUpdateSitePlugin.ts`. Mirrors `useRefreshSitePlugins` (P2.1 Task 15) structure:

```ts
export function useUpdateSitePlugin(siteId: number, slug: string) {
  const queryClient = useQueryClient();
  const [isPolling, setIsPolling] = useState(false);

  const mutation = useMutation({
    mutationFn: async () => {
      const res = await apiClient.post(
        `/sites/${siteId}/plugins/${slug}/update`,
      );
      setIsPolling(true);
      return res.data;
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['sites', siteId, 'plugins'] }),
  });

  // Read the cached list so we can react to update_state transitions.
  // useSitePlugins is responsible for triggering the polling refetch.
  const { data: list } = useSitePlugins(siteId, {
    refetchInterval: isPolling ? 2000 : false,
  });

  // Stop polling when the row settles (idle or failed). Hard 5 min cap as a
  // safety net — if the AS job hangs or the connector loses the response, the
  // SPA stops burning network and the user can refresh manually.
  const rowState = list?.plugins.find((p) => p.slug === slug)?.update_state;
  useEffect(() => {
    if (!isPolling) return;
    if (rowState === 'idle' || rowState === 'failed') {
      setIsPolling(false);
      return;
    }
    const cap = setTimeout(() => setIsPolling(false), 5 * 60 * 1000);
    return () => clearTimeout(cap);
  }, [isPolling, rowState]);

  return { update: mutation.mutate, isPending: mutation.isPending, isPolling };
}
```

### 8.4 Zod schemas (`apps/web/src/types/api/plugins.ts`)

Extend `pluginSchema`:

```ts
export const pluginSchema = z.object({
  slug: z.string(),
  name: z.string(),
  version: z.string().nullable(),
  update_available: z.boolean(),
  update_version: z.string().nullable(),
  update_state: z.enum(['idle', 'queued', 'updating', 'failed']),  // NEW
  last_update_error: z.string().nullable(),                        // NEW
  last_update_attempt_at: z.string().nullable(),                   // NEW
});

export const updateSitePluginResponseSchema = z.object({           // NEW
  scheduled: z.literal(true),
  site_id: z.number(),
  slug: z.string(),
});
```

### 8.5 `SitePluginsPanel` integration

Existing P2.1 panel: pass `mutation = useUpdateSitePlugin(siteId, row.slug)` down per row → row owns its confirm dialog state. Panel's existing `useSitePlugins` query auto-tracks each row's state since they all share the same query cache.

No layout changes — the SPA design picked the merged-column layout in §3a. The existing "Updates only" toggle and refresh button continue to work as in P2.1.

---

## 9. Error envelope codes (full list, added in P2.2)

| Plugin | Code | HTTP |
| --- | --- | --- |
| Connector | `plugins.invalid_slug` | 400 |
| Connector | `plugins.unknown_slug` | 404 |
| Connector | `plugins.no_update_available` | 409 |
| Connector | `plugins.update_in_progress` | 409 |
| Connector | `plugins.update_failed` | 502 |
| Dashboard | `plugins.not_found_in_inventory` | 404 |
| Dashboard | `plugins.no_update_available` | 409 |
| Dashboard | `plugins.update_already_in_progress` | 409 |
| Dashboard | `plugins.rate_limited` | 429 |

All wrap in the existing `{error: {code, message}}` envelope; cache headers stay `no-store`.

---

## 10. Activity log events (added in P2.2)

| event_type | written by | user_id | details shape |
| --- | --- | --- | --- |
| `plugin_update.requested` | `SitesPluginsUpdateController` | yes | `{slug, current_version, target_version}` |
| `plugin_update.started` | `UpdateSitePlugin` job | null | `{slug, current_version, target_version}` |
| `plugin_update.succeeded` | `UpdateSitePlugin` job | null | `{slug, previous_version, new_version}` |
| `plugin_update.failed` | `UpdateSitePlugin` job | null | `{slug, error_message, attempted_version}` |
| `plugin_update.retry` | `UpdateSitePlugin` job | null | `{slug, attempt, next_run_at}` |

No new columns on `wp_defyn_activity_log` — existing JSON details column carries the per-event payload.

---

## 11. Multisite considerations

Identical to P2.1 §10: the connector's `get_plugins()` and `Plugin_Upgrader` operate at the install level (not per-subsite). For a WP multisite, upgrading a network-activated plugin upgrades it for every subsite simultaneously — that's the WordPress contract, not something P2.2 changes. The dashboard's `wp_defyn_site_plugins` row represents the install, not a subsite.

Per-subsite breakouts and subsite-specific updates remain explicit Phase 2 multisite work.

---

## 12. Concurrency — why per-site lock

Spec lock granularity is **per-site, not per-slug**. Rationale (from §3 brainstorm Q3):

1. **WP filesystem state isn't compartmentalized.** `Plugin_Upgrader` writes `.maintenance` for the whole install. Two concurrent upgrades may race on `.maintenance` removal — one finishes and removes the file while the other is still extracting, leaving the site briefly "live" mid-upgrade.
2. **`wp_cache_*` state can collide.** WP's object cache and plugin update transient are global; concurrent writes can leave inconsistent state.
3. **VPS resource bound.** Two simultaneous upgrades on a 1–2 GB RAM Kinsta instance can OOM-kill PHP, leaving the site in a half-upgraded state.

The serialization cost (e.g., 3 plugins × 30 s each = 90 s instead of 30 s parallel) is acceptable in exchange for the safety story.

**Dashboard-side de-dup:** the controller's guard (§7.1 step 5) also blocks "Update" while `update_state` is `queued/updating` — so the SPA can never queue duplicate AS jobs for the same (site, slug). The connector lock catches concurrent attempts across different operators on the same site.

---

## 13. Testing strategy

Same TDD discipline as P2.1. Target ~25–30 new tests.

### 13.1 Connector tests

- **`Unit/SiteInfo/PluginUpgraderServiceTest`** — mock `get_plugins()`, `get_site_transient`, and `Plugin_Upgrader` via a test fake. Covers: slug resolution, success path, unknown slug, no update available, upgrade returns false, upgrade returns WP_Error.
- **`Integration/Rest/PluginUpdateTest`** — signed-request E2E within `WP_UnitTestCase`. Covers all 6 error codes + success.
- **`Integration/Rest/PluginUpdateLockTest`** — fires two consecutive controller calls in the same test, second returns 409 `plugins.update_in_progress`. Tests lock cleanup on success AND uncaught exception paths.
- **`Integration/Rest/PluginUpdateCacheHeadersTest`** — regression: `Cache-Control: no-store` set via `apply_filters('rest_post_dispatch', …)` (P2.1 Task 4 pattern).

### 13.2 Dashboard tests

- **`Unit/Services/SitePluginsRepositoryUpdateStateTest`** — `markUpdateRequested`, `markUpdating`, `markUpdateSucceeded`, `markUpdateFailed` write-then-read; covers `version`/`update_available` mutations on success.
- **`Integration/Schema/SchemaV3MigrationTest`** — fresh activation has the three new columns + index; from-v2 install migrates via dbDelta cleanly.
- **`Integration/Jobs/UpdateSitePluginTest`** — uses Symfony `MockHttpClient` per P2.1 Task 8 pattern. Covers: success, 409 retry (verifies `as_schedule_single_action` called with correct backoff), 5-retry exhaustion, transport error, non-409 HTTP error.
- **`Integration/Rest/SitesPluginsUpdateTest`** — REST flow E2E. Covers all 5 error codes + 202 success + optimistic `update_state='queued'` write.
- **`Integration/Rest/RateLimitPluginsUpdateTest`** — 7th request in 1 hour returns 429.
- **`Integration/Rest/SitesPluginsUpdateCorsTest`** — ensures CORS headers + envelope normalization apply (same defensive pattern as foundation).

### 13.3 SPA tests

- **`types/api/plugins.test.ts`** — new fields parse correctly; missing fields fail Zod validation (regression).
- **`lib/mutations/useUpdateSitePlugin.test.ts`** — fires POST via MSW, starts polling, stops when `update_state` settles to `idle`/`failed`, hard-cap timeout works.
- **`components/sites/SitePluginsRow.test.tsx`** — renders 4 visual states (idle non-upgradable, idle upgradable, in-flight, failed); button label flips Update/Retry; tooltip surfaces error.
- **`components/sites/SitePluginUpdateConfirmDialog.test.tsx`** — version diff renders correctly; Confirm calls `mutation.mutate`; Cancel calls `onOpenChange(false)`.
- **`components/sites/SitePluginsPanel.test.tsx`** — extended: existing tests stay green; new tests cover row state propagation.
- **`routes/SiteDetail.test.tsx`** — extended: a site row with a failed plugin renders without breaking existing assertions; the "Updates only" toggle still works alongside the new state filtering.

### 13.4 Manual smoke (final task)

Same six-step playbook as P2.1:

1. Build connector + dashboard zips.
2. Upload connector v0.1.4 to SmartCoding; upload dashboard v0.3.0 to defynwp.defyn.agency.
3. Deactivate + reactivate dashboard plugin (until schema self-heal ships).
4. Curl `POST /defyn/v1/sites/1/plugins/gbposter-for-google-business-profile/update`; tick wp-cron; expect 202 → activity log shows `requested → started → succeeded`; row eventually shows version 2.0.0 with badge cleared.
5. Open SPA at https://app.defynwp.defyn.agency/sites/1; click Update on Jetpack Social; confirm modal; observe in-flight state → completion.
6. If smoke green: tag `p2-2-plugin-updates-complete` and push.

---

## 14. Versioning

| Component | From | To | Why |
| --- | --- | --- | --- |
| Connector | 0.1.3 | **0.1.4** | One new endpoint mirroring P2.1 pattern; no schema, no breaking change → patch |
| Dashboard | 0.2.0 | **0.3.0** | Schema migration v2→v3 + new error codes + new activity events + new AS hook → minor |
| SPA | not versioned | — | Continuous deployment from main via Cloudflare Pages |

---

## 15. Open questions / decisions revisited

- **Backoff cap of 5 retries** — chosen for ~32 min total budget. If a site is busy for that long, something's wrong and a human should investigate. Revisit if real-world data shows operators hitting the cap on healthy sites.
- **5 min hard polling cap on the SPA** — covers worst-case legitimate upgrade. If a real upgrade takes longer, SPA stops polling but the AS job keeps going; user just needs to refresh the page to see final state.
- **Per-site lock TTL = 600 s** — comfortable headroom over WP's stock upgrader budget. Process crash that holds the lock auto-expires.
- **Error message char cap of 1000** — protects DB; full transcript stays in the connector PHP error log for ops debugging.

---

## 16. Premium plugins — assumed transparent

We do not special-case premium plugins (Rank Math PRO, WPML, Jetpack, anything with a custom `update_uri`). If WordPress's stock `update_plugins` transient reports `update_available=true`, DefynWP attempts the upgrade. `Plugin_Upgrader` follows whatever update_uri the plugin author registered. If a paywalled plugin's update endpoint returns 403 because the license expired, the upgrade fails with the captured skin message as `last_update_error`. Operator sees the tooltip, renews the license, retries.

No premium allowlist, no license pre-check, no payment integration. P2.2 is a thin layer over WP's native upgrade pipeline.
