# P2.1 — Plugin inventory design

> Phase 2 sub-phase 1 (P2.1). First step toward "manage everything from DefynWP" per the [foundation design](2026-04-18-defyn-foundation-design.md) §12 "Actually doing anything with a managed site". Lays the read foundation under P2.2 (plugin updates), P2.3 (theme/core updates), and fleet-wide reporting.

**Authored:** 2026-06-04
**Builds on:** foundation-complete tag (`caf5f79`) + dashboard v0.1.1 (`33af533`) + connector v0.1.2 (`e70f467`)
**Output versions:** connector **v0.1.3**, dashboard **v0.2.0**

---

## 1. Goal

Surface every plugin on each connected site (`slug`, `name`, `version`, `update_available`, `update_version`) in the dashboard SPA. Read-only — P2.1 makes no write or mutation calls into managed sites. The "update available" flag is what makes this immediately useful as more than the aggregate counts the foundation already surfaces.

### 1.1 What "P2.1 done" looks like

- ✅ Operator opens SmartCoding's site detail in the SPA. A **Plugins** panel renders below the existing Activity panel.
- ✅ Panel shows a row per installed plugin with name, current version, and a clear badge when an update is waiting (with target version).
- ✅ Header summary: `N installed · M updates available`.
- ✅ Filter toggle: **Updates only** restricts the table client-side.
- ✅ A refresh button (`↻`) forces the dashboard to call the managed site's connector right now, repolling at 2s until `last_synced_at` advances.
- ✅ The list also captures during the existing 30-minute background sync, so opening a site for the first time after install shows current data within 30 minutes without any manual action.
- ✅ Activity log writes `plugin_inventory.synced` / `plugin_inventory.sync_failed` / `plugin_inventory.refresh_requested` events.

### 1.2 Out of scope for P2.1

Explicitly deferred to later phases:

- **Updating** plugins from the dashboard (P2.2)
- **Themes** and **WP core** version surfaces beyond what `/status` already returns (P2.3)
- **Auto-update flag** per plugin
- **Per-plugin last-updated timestamp** (requires retaining sync deltas)
- **Active/inactive** state surface — counted but not per-row
- **Per-subsite breakouts** for WP multisite — Phase 2 multisite work
- **Search box** on the plugins panel — fine for 20–100 plugins without; ship with toggle filter, add search when fleet view exists
- **Tabs** on `SiteDetail` — premature complexity until P2.2 has its own things to put behind tabs

---

## 2. Architecture overview

```
                   ┌────────────────────────────┐
                   │  DefynWP Dashboard (Kinsta)│
                   │                            │
   ┌───────────┐   │  ┌─────────────────────┐  │
   │   SPA     │──▶│  │ REST /defyn/v1/...  │  │
   │ React app │   │  │   GET  /sites/{id}/ │  │
   │ Site      │   │  │        plugins      │  │
   │ Detail    │◀──│  │   POST /sites/{id}/ │  │
   │  Plugins  │   │  │        plugins/     │  │
   │  panel    │   │  │        refresh      │  │
   │  + ↻      │   │  └──────────┬──────────┘  │
   └───────────┘   │             │             │
                   │             ▼             │
                   │  ┌─────────────────────┐  │
                   │  │ SyncPluginsService  │  │
                   │  └──────────┬──────────┘  │
                   │             │             │
                   │             ▼             │
                   │  ┌─────────────────────┐  │
                   │  │ wp_defyn_site_      │  │
                   │  │   plugins (new)     │  │
                   │  └─────────────────────┘  │
                   │                            │
                   │  Action Scheduler:         │
                   │  - defyn_sync_site         │
                   │    (extended)              │
                   │  - defyn_refresh_site_     │
                   │    plugins (new)           │
                   └──────────────┬─────────────┘
                                  │ signed HTTPS
                                  ▼
                   ┌────────────────────────────┐
                   │  Managed site (connector)  │
                   │                            │
                   │  REST /defyn-connector/v1/ │
                   │   GET  /plugins (new)      │
                   │   POST /plugins/refresh    │
                   │                  (new)     │
                   │                            │
                   │  PluginListCollector (new) │
                   │    get_plugins()           │
                   │  + get_site_transient(     │
                   │     'update_plugins')      │
                   └────────────────────────────┘
```

---

## 3. Connector — new REST endpoints

Both endpoints register under the existing `defyn-connector/v1` namespace, sit behind `VerifySignatureMiddleware::check` (same gate as `/status`/`/heartbeat`/`/disconnect`), and emit the `Cache-Control: no-store` headers via `RestRouter::applyNoCacheHeaders` (already implemented in v0.1.2). Connector version bump v0.1.2 → **v0.1.3**.

### 3.1 GET `/defyn-connector/v1/plugins`

Signed. Returns the cached inventory — does NOT force a wp.org refresh of the update transient.

**Response 200:**
```json
{
  "plugins": [
    {
      "slug": "akismet/akismet.php",
      "name": "Akismet Anti-spam",
      "version": "5.3.1",
      "update_available": true,
      "update_version": "5.3.5"
    }
  ],
  "truncated": false,
  "server_time": 1780580000
}
```

**Field semantics:**
- `slug`: WP plugin path identifier (`<dir>/<file>.php` or `<file>.php` for single-file plugins). Stable across upgrades.
- `name`: Human-readable plugin name from the plugin header. Connector skips plugins with empty `Name` (defensive — `get_plugins()` shouldn't return such rows).
- `version`: Plugin header `Version` field. May be empty string → connector normalizes to `null`.
- `update_available`: Derived from `get_site_transient('update_plugins')->response` keyed by `slug`. `true` iff wp.org reports a higher version.
- `update_version`: New version string from the same transient, `null` when no update.
- `truncated`: `true` iff list was capped at 500 — see §3.3.

**Error responses** (all spec § 9.1 envelope shape):
| Code | HTTP | Cause |
|---|---|---|
| `connector.signature_missing` / `signature_*` | 401 | F6 middleware gate |
| `connector.not_connected` | 404 | Connector state ≠ `connected` |
| `connector.plugins_unavailable` | 500 | `get_plugins()` returned falsy (filesystem unreadable) |

### 3.2 POST `/defyn-connector/v1/plugins/refresh`

Signed. Forces a fresh wp.org poll via `wp_update_plugins()`, then returns the same payload as `GET /plugins`. Body is empty (`{}` accepted but ignored).

**Why POST not GET:** non-idempotent (triggers a network call + transient write); semantically a write operation even though the response is read-only.

**Response 200:** same shape as §3.1 — the post-refresh payload, so the dashboard can sync without a second roundtrip.

**Timing:** 5–30 seconds typical (one wp.org HTTP call). Within Kinsta's PHP-FPM default timeout. The dashboard schedules an AS job (§8.2) so the HTTP timeout boundary is the connector's, not the SPA's.

**Errors:** same set as §3.1 plus:

| Code | HTTP | Cause |
|---|---|---|
| `connector.refresh_failed` | 502 | `wp_update_plugins()` threw / wp.org unreachable |

### 3.3 Truncation policy

`Defyn\Connector\SiteInfo\PluginListCollector::collect()` caps the list at **500 plugins**. Sites with more get `truncated: true` and the first 500 by slug-ascending order. P2.1 does not paginate — 500 plugins is well past the practical upper bound for any real WordPress install (typical: 10–60). Pagination is a later phase if real fleets surface sites that exceed.

---

## 4. Connector — new code

### 4.1 `Defyn\Connector\SiteInfo\PluginListCollector`

New class alongside the existing `Collector` (which keeps doing `/status`). Mirrors the F6 Collector pattern:

```
final class PluginListCollector
{
    /** @return array{plugins: list<array{...}>, truncated: bool} */
    public function collect(): array
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all       = get_plugins();
        $updates   = get_site_transient('update_plugins');
        $byPath    = is_object($updates) && isset($updates->response)
                        ? (array) $updates->response : [];

        $plugins = [];
        foreach ($all as $slug => $header) {
            $name = (string) ($header['Name'] ?? '');
            if ($name === '') continue;
            $version       = (string) ($header['Version'] ?? '');
            $upd           = $byPath[$slug] ?? null;
            $plugins[] = [
                'slug'             => (string) $slug,
                'name'             => $name,
                'version'          => $version !== '' ? $version : null,
                'update_available' => $upd !== null,
                'update_version'   => $upd && isset($upd->new_version)
                                          ? (string) $upd->new_version : null,
            ];
        }

        ksort($plugins);  // stable order by slug (truncation-friendly)

        $truncated = count($plugins) > 500;
        if ($truncated) $plugins = array_slice($plugins, 0, 500);

        return ['plugins' => $plugins, 'truncated' => $truncated];
    }
}
```

### 4.2 `Defyn\Connector\Rest\PluginsListController` (new)

```
final class PluginsListController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $data = (new PluginListCollector())->collect();
        $data['server_time'] = time();
        return new WP_REST_Response($data, 200);
    }
}
```

### 4.3 `Defyn\Connector\Rest\PluginsRefreshController` (new)

```
final class PluginsRefreshController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        // wp_update_plugins() lives in wp-includes/update.php (loaded by default)
        if (!function_exists('wp_update_plugins')) {
            return ErrorResponse::create(502, 'connector.refresh_failed',
                'WP update subsystem unavailable.');
        }

        try {
            wp_update_plugins();  // forces a wp.org HTTP call + transient write
        } catch (\Throwable $e) {
            return ErrorResponse::create(502, 'connector.refresh_failed',
                'wp_update_plugins() failed: ' . $e->getMessage());
        }

        $data = (new PluginListCollector())->collect();
        $data['server_time'] = time();
        return new WP_REST_Response($data, 200);
    }
}
```

### 4.4 `Defyn\Connector\Rest\RestRouter::register()` — add two routes

```
register_rest_route(self::NAMESPACE, '/plugins', [
    'methods'             => 'GET',
    'callback'            => [new PluginsListController(), 'handle'],
    'permission_callback' => [VerifySignatureMiddleware::class, 'check'],
]);

register_rest_route(self::NAMESPACE, '/plugins/refresh', [
    'methods'             => 'POST',
    'callback'            => [new PluginsRefreshController(), 'handle'],
    'permission_callback' => [VerifySignatureMiddleware::class, 'check'],
]);
```

No changes to existing routes. The existing `applyNoCacheHeaders` filter already covers `/defyn-connector/v1/*` so no additional cache-header wiring needed.

### 4.5 Connector version + changelog

- `defyn-connector.php`: Version `0.1.2` → `0.1.3`
- `readme.txt`: Stable tag `0.1.2` → `0.1.3`; new `= 0.1.3 =` entry:
  > Feature: new `/plugins` (GET) and `/plugins/refresh` (POST) signed endpoints expose the site's plugin inventory + update-available flags. Lays the read foundation for dashboard-driven plugin management.

---

## 5. Dashboard — schema

### 5.1 New table `wp_defyn_site_plugins`

```sql
CREATE TABLE wp_defyn_site_plugins (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  site_id BIGINT UNSIGNED NOT NULL,
  slug VARCHAR(191) NOT NULL,
  name VARCHAR(191) NOT NULL,
  version VARCHAR(40) NULL,
  update_available TINYINT(1) NOT NULL DEFAULT 0,
  update_version VARCHAR(40) NULL,
  last_seen_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY site_slug (site_id, slug),
  KEY update_available (update_available),
  KEY site_id (site_id)
) {$charset};
```

**Why these indexes:**
- `UNIQUE (site_id, slug)` — natural key. Makes the delta-sync's upsert pattern straightforward.
- `KEY (update_available)` — fleet view: `SELECT COUNT(*) FROM wp_defyn_site_plugins WHERE update_available = 1`.
- `KEY (site_id)` — site-detail lookup: `WHERE site_id = ?`.

`slug` at VARCHAR(191) for utf8mb4 InnoDB index compatibility without ROW_FORMAT=DYNAMIC fiddling.

### 5.2 Migration mechanism

Add new `Defyn\Dashboard\Schema\SitePluginsTable` implementing the existing `SchemaTable` interface. Wire it into `Activation::activate()`'s dbDelta call. dbDelta is idempotent — handles both fresh installs and upgrades.

**Upgrade detection:** add a `defyn_schema_version` option. Current foundation = `1`. P2.1 bumps to `2`. On `plugins_loaded`, the dashboard plugin reads the option; if `< 2`, runs the migration (creates `wp_defyn_site_plugins` via dbDelta), then writes `2` to the option. Idempotent — safe to run on every request, but the version check short-circuits after the first.

### 5.3 No changes to `wp_defyn_sites`

The existing `plugin_counts` JSON column stays. P2.1's table is additive, not a replacement. `plugin_counts` continues to power the existing aggregate fields in `SitesShowController` for backwards compatibility.

---

## 6. Dashboard — sync flow

### 6.1 Background path (extends F7's `defyn_sync_site`)

The existing `Defyn\Dashboard\Jobs\SyncSite::handle(int $siteId)` already calls signed `/status` and runs `SyncService`. We extend it so that AFTER `/status` succeeds, it ALSO calls signed `/plugins` and runs the new `SyncPluginsService`.

```
SyncSite::handle($siteId):
    site = repo->findById($siteId)
    if (site is null OR not connected) return

    statusResult = SignedHttpClient::signedGet(site, '/status')
    if (statusResult ok):
        SyncService::sync($siteId, statusResult->data)
        // NEW (P2.1):
        pluginsResult = SignedHttpClient::signedGet(site, '/plugins')
        if (pluginsResult ok):
            SyncPluginsService::sync($siteId, pluginsResult->data, source: 'background')
        else:
            activityLog->log($siteId, 'plugin_inventory.sync_failed', {
                error: pluginsResult->errorMessage,
                source: 'background',
            }, dedupKey: 'connector_below_v0.1.3' if pluginsResult->code === 'rest.route_not_found' else null)
    else:
        // Existing F6 behavior — repo->markError($siteId, statusResult->errorMessage)
```

A `/plugins` failure does NOT mark the site as error (only `/status` failure does that, per F6 semantics). Plugin inventory is supplementary; a failure leaves the existing inventory rows intact and logs `plugin_inventory.sync_failed`.

### 6.2 Refresh path (new AS job)

Triggered by `POST /defyn/v1/sites/{id}/plugins/refresh`. Schedules `defyn_refresh_site_plugins(siteId)` AS hook → `Defyn\Dashboard\Jobs\RefreshSitePlugins::handle($siteId)`:

```
RefreshSitePlugins::handle($siteId):
    site = repo->findById($siteId)
    if (site is null OR not connected): return

    result = SignedHttpClient::signedPostJson(site, '/plugins/refresh', body: [])
    if (result ok):
        SyncPluginsService::sync($siteId, result->data, source: 'refresh')
    else:
        activityLog->log($siteId, 'plugin_inventory.sync_failed', {
            error: result->errorMessage,
            source: 'refresh',
        })
```

### 6.3 `SyncPluginsService::sync(int $siteId, array $payload, string $source)`

```
Defyn\Dashboard\Services\SyncPluginsService

public function sync(int $siteId, array $payload, string $source): void
{
    $incoming = $payload['plugins'] ?? [];

    global $wpdb;
    $table = SitePluginsTable::tableName();

    $existing = $wpdb->get_results($wpdb->prepare(
        "SELECT slug, name, version, update_available, update_version
         FROM {$table} WHERE site_id = %d", $siteId
    ), ARRAY_A);

    $existingBySlug = array_column($existing, null, 'slug');
    $incomingSlugs  = array_column($incoming, 'slug');
    $now            = gmdate('Y-m-d H:i:s');

    $wpdb->query('START TRANSACTION');
    try {
        foreach ($incoming as $p) {
            $slug = $p['slug'];
            if (!isset($existingBySlug[$slug])) {
                // INSERT new
                $wpdb->insert($table, [
                    'site_id'          => $siteId,
                    'slug'             => $slug,
                    'name'             => $p['name'],
                    'version'          => $p['version'],
                    'update_available' => $p['update_available'] ? 1 : 0,
                    'update_version'   => $p['update_version'],
                    'last_seen_at'     => $now,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ], ['%d','%s','%s','%s','%d','%s','%s','%s','%s']);
            } else {
                $current = $existingBySlug[$slug];
                $hasChanged = (
                    $current['name']             !== $p['name']           ||
                    $current['version']          !== $p['version']        ||
                    (int) $current['update_available'] !== ($p['update_available'] ? 1 : 0) ||
                    $current['update_version']   !== $p['update_version']
                );
                if ($hasChanged) {
                    $wpdb->update($table, [
                        'name'             => $p['name'],
                        'version'          => $p['version'],
                        'update_available' => $p['update_available'] ? 1 : 0,
                        'update_version'   => $p['update_version'],
                        'last_seen_at'     => $now,
                        'updated_at'       => $now,
                    ], ['site_id' => $siteId, 'slug' => $slug],
                       ['%s','%s','%d','%s','%s','%s'],
                       ['%d','%s']);
                } else {
                    // unchanged — just bump last_seen_at so we don't DELETE
                    $wpdb->update($table,
                        ['last_seen_at' => $now],
                        ['site_id' => $siteId, 'slug' => $slug],
                        ['%s'], ['%d','%s']);
                }
            }
        }

        // DELETE rows whose slug is NOT in $incomingSlugs
        $toDelete = array_diff(array_column($existing, 'slug'), $incomingSlugs);
        foreach ($toDelete as $slug) {
            $wpdb->delete($table, ['site_id' => $siteId, 'slug' => $slug], ['%d','%s']);
        }

        $wpdb->query('COMMIT');
    } catch (\Throwable $e) {
        $wpdb->query('ROLLBACK');
        throw $e;
    }

    $updatesAvailable = count(array_filter($incoming, fn($p) => $p['update_available']));
    (new ActivityLogger())->log($siteId, null, 'plugin_inventory.synced', [
        'plugin_count'             => count($incoming),
        'updates_available_count'  => $updatesAvailable,
        'source'                   => $source,
    ]);
}
```

**Why a transaction:** delta sync touches multiple rows; we want all-or-nothing so a partial failure doesn't leave torn state. InnoDB MySQL on Kinsta supports this.

---

## 7. Dashboard — new REST endpoints

### 7.1 GET `/defyn/v1/sites/{id}/plugins`

- **Auth:** Bearer JWT + user-scoped (404 if `findByIdForUser` returns null)
- **Returns:** `{ plugins: [...], total: int, last_synced_at: ISO8601 | null }`
- **Body** is read straight from `wp_defyn_site_plugins` for the site; `last_synced_at` is the MAX(`last_seen_at`) across the site's rows (or null if zero rows)

```
SitesPluginsListController::handle:
    $siteId = (int) $request['id'];
    $userId = (int) $request['_authenticated_user_id'];
    $site = $repo->findByIdForUser($siteId, $userId);
    if ($site === null) return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');

    $rows = $pluginsRepo->findAllForSite($siteId);  // array<Plugin>
    $lastSyncedAt = $pluginsRepo->lastSyncedAtForSite($siteId);  // ?string

    return new WP_REST_Response([
        'plugins'        => array_map(fn($p) => $p->toJson(), $rows),
        'total'          => count($rows),
        'last_synced_at' => $lastSyncedAt,
    ], 200);
```

**Cache headers:** the existing `RestRouter::applyNoCacheHeaders` filter from dashboard v0.1.1 covers this automatically.

### 7.2 POST `/defyn/v1/sites/{id}/plugins/refresh`

- **Auth:** Bearer JWT + user-scoped
- **Rate limit:** new `RateLimit::pluginsRefresh` — **6 requests / minute / user / site**. Reuses F3a's transient-backed limiter.
- **Returns:** `202 { scheduled: true, site_id: int }` immediately
- **Side effect:** schedules `defyn_refresh_site_plugins($siteId)` AS job + writes `plugin_inventory.refresh_requested` activity event

```
SitesPluginsRefreshController::handle:
    $siteId = (int) $request['id'];
    $userId = (int) $request['_authenticated_user_id'];
    $site = $repo->findByIdForUser($siteId, $userId);
    if ($site === null) return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');

    as_schedule_single_action(time(), 'defyn_refresh_site_plugins', [$siteId], 'defyn');

    (new ActivityLogger())->log($siteId, $userId, 'plugin_inventory.refresh_requested', null);

    return new WP_REST_Response(['scheduled' => true, 'site_id' => $siteId], 202);
```

---

## 8. Dashboard — Action Scheduler jobs

### 8.1 `defyn_sync_site` — extended (no new hook)

The existing F7 hook gets its body extended (§6.1). Existing job test (`tests/Integration/Jobs/SyncSiteTest.php`) gets a new assertion: after a successful sync, the plugins inventory is also synced.

### 8.2 `defyn_refresh_site_plugins` — new

Registered in `Plugin::boot()`:

```
add_action('defyn_refresh_site_plugins', [new Jobs\RefreshSitePlugins(), 'handle']);
```

Single-action job, scheduled by `SitesPluginsRefreshController` on demand.

### 8.3 No extension of `defyn_sync_all_sites`

The master fan-out already enqueues `defyn_sync_site($siteId)` per site. Since `SyncSite::handle` now also handles plugins, the master gets plugin sync for free with zero extra code.

---

## 9. Activity log — new event types

| Event type | Emitted by | Details payload |
|---|---|---|
| `plugin_inventory.synced` | `SyncPluginsService::sync` | `{plugin_count: int, updates_available_count: int, source: "background" \| "refresh"}` |
| `plugin_inventory.sync_failed` | `SyncSite` or `RefreshSitePlugins` on connector error | `{error: string, source: "background" \| "refresh"}` |
| `plugin_inventory.refresh_requested` | `SitesPluginsRefreshController` immediately on user click | `null` |

No schema change to `wp_defyn_activity_log` — its `event_type` and `details` columns already handle the new shapes.

**Dedup for `connector_below_v0.1.3`:** when SyncSite catches a 404 `rest.route_not_found` from `/plugins`, it MAY log `plugin_inventory.sync_failed` with `error: "connector_below_v0.1.3"`. To avoid log noise on every 30-min tick, the logger checks for an existing event with the same site+code in the last 24 hours and suppresses duplicates. This is the only dedup logic in P2.1 — happy-path `synced` events are NOT deduped (every sync gets a row, even if nothing changed).

---

## 10. SPA — new files + integration

### 10.1 Panel placement + layout

`SiteDetail.tsx` gains one new section, rendered after `SiteActivityPanel`:

```jsx
{data.status !== 'pending' && <SitePluginsPanel siteId={siteId} />}
```

Container width changes: `max-w-xl` → `max-w-3xl` on the outer `<div>`. This widens all sections proportionally; verified safe by reading the existing JSX (no fixed widths on inner sections).

### 10.2 Component tree

```
<SitePluginsPanel siteId={siteId} />
   ├── header
   │     ├── title "Plugins"
   │     ├── summary text "21 installed · 3 updates available"
   │     ├── "Updates only" toggle (shadcn Switch)
   │     └── refresh button (shadcn Button + Lucide RefreshCw icon)
   │            └── spinner when mutation pending
   ├── subheader "Last synced ..."
   ├── empty/error states (described in §10.4)
   └── table
         └── for each filtered plugin: <SitePluginsRow plugin={p} />
                 ├── name + slug (two-line cell)
                 ├── version
                 └── update cell:
                       - if update_available: <Badge>→ {update_version}</Badge>
                       - else: "—"
                 └── actions cell (hidden in P2.1; reserved for P2.2)
```

### 10.3 Data fetching

`apps/web/src/lib/queries/useSitePlugins.ts`:

```ts
export function useSitePlugins(
  siteId: number,
  opts?: { refetchInterval?: number | false }
) {
  return useQuery({
    queryKey: ['sites', siteId, 'plugins'],
    queryFn: async () => {
      const data = await apiClient.get<unknown>(`/sites/${siteId}/plugins`);
      return sitePluginsListResponseSchema.parse(data);
    },
    staleTime: 60_000,
    refetchInterval: opts?.refetchInterval ?? false,
  });
}
```

Zod schema in `apps/web/src/types/api/plugins.ts`:

```ts
export const pluginSchema = z.object({
  slug: z.string(),
  name: z.string(),
  version: z.string().nullable(),
  update_available: z.boolean(),
  update_version: z.string().nullable(),
});

export const sitePluginsListResponseSchema = z.object({
  plugins: z.array(pluginSchema),
  total: z.number().int(),
  last_synced_at: z.string().nullable(),
});
```

### 10.4 Refresh flow + states

`apps/web/src/lib/mutations/useRefreshSitePlugins.ts`:

```
useRefreshSitePlugins(siteId):
  triggerAt = ref(null)
  isPolling = state(false)

  refresh = mutation:
    onMutate: triggerAt.current = new Date().toISOString()
    fn: apiClient.post(`/sites/${siteId}/plugins/refresh`)
    onSuccess: queryClient.invalidateQueries(['sites', siteId, 'plugins'])
               setIsPolling(true)
    onError:   show toast with error.message

  useSitePlugins(siteId, { refetchInterval: isPolling ? 2000 : false })
    .onData((data):
      if (isPolling && data.last_synced_at > triggerAt.current):
        setIsPolling(false))

  // Hard timeout 60s
  useEffect:
    if (isPolling):
      const t = setTimeout(() => setIsPolling(false), 60_000)
      return () => clearTimeout(t)

  return { refresh, isPolling, isPending: mutation.isPending }
```

**Panel states:**

| State | Render |
|---|---|
| Initial load (`isLoading`) | Skeleton table rows (5 rows × 3 cells) |
| Has data, plugins empty (`total === 0`) | "No plugins installed on this site." |
| Has data, ≥ 1 plugin | The filtered table |
| `isPending` or `isPolling` | Refresh icon swaps to spinner; rows render with `opacity-50`; row data still readable |
| `useSitePlugins` errors with `error.code === 'sites.not_found'` | Should not happen on this route (site exists); fall through to error alert |
| Last synced never (`last_synced_at === null`) and `total === 0` | Banner: "Plugin inventory not yet captured. The first background sync runs within 30 minutes — or hit ↻ to fetch now." |
| Recent activity log event with `error: "connector_below_v0.1.3"` | Banner: "Plugins require connector plugin v0.1.3+ on this site. [Update guide →]" — refresh button hidden |

The connector-version banner reads from the activity feed (`SiteActivityPanel` already exposes it via `useSiteActivity`). The panel checks for the most recent `plugin_inventory.sync_failed` event with `error: connector_below_v0.1.3` in the last 24h; if found, render the banner.

### 10.5 New SPA files

| File | Purpose |
|---|---|
| `apps/web/src/components/sites/SitePluginsPanel.tsx` | The container component — header, filter toggle, refresh button, states |
| `apps/web/src/components/sites/SitePluginsRow.tsx` | Single-row component |
| `apps/web/src/lib/queries/useSitePlugins.ts` | List query |
| `apps/web/src/lib/mutations/useRefreshSitePlugins.ts` | Refresh mutation + polling |
| `apps/web/src/types/api/plugins.ts` | Zod schemas |
| `apps/web/src/routes/SiteDetail.tsx` | **Modify:** insert `<SitePluginsPanel />` + widen container |

---

## 11. Error envelopes

All new error responses ride the existing `Defyn\Dashboard\Rest\Responses\ErrorResponse::create` shape:

```json
{ "error": { "code": "plugins.something", "message": "..." } }
```

| Code | HTTP | Where |
|---|---|---|
| `sites.not_found` | 404 | Both new dashboard endpoints when site doesn't exist for the user |
| `plugins.rate_limited` | 429 | `POST /sites/{id}/plugins/refresh` when limiter trips |
| `plugins.refresh_failed` | 502 | Connector returned `connector.refresh_failed` — dashboard normalizes |
| (existing F10 envelope) | 404 | `rest.route_not_found` if SPA hits a typo |

---

## 12. Multisite

For a managed site running WordPress multisite:
- `PluginListCollector` returns the union of network-active + per-blog-active plugins (mirrors existing F6 `Collector` behavior)
- A plugin appears once in the inventory regardless of how many subsites it activates
- No per-subsite breakout in P2.1
- The connector reports `is_multisite` nowhere in P2.1 — future phase if relevant

---

## 13. Concurrency, rate limits, edge cases

| Concern | Resolution |
|---|---|
| Refresh + background sync overlap | UNIQUE(site_id, slug) makes writes idempotent. Both `plugin_inventory.synced` events get logged; `source` field distinguishes |
| Refresh button spam | Dashboard rate limit (§7.2). SPA disables button while in flight. |
| `wp_update_plugins()` slow (15+ seconds) | AS job runs asynchronously; SPA polls. Connector-side PHP timeout governs upper bound. |
| Connector below v0.1.3 | Dashboard catches `rest.route_not_found` from `/plugins`. Logs `connector_below_v0.1.3` once per 24h per site. SPA renders update banner. |
| Connector disconnected during sync | Connector returns `connector.not_connected` 404. Dashboard logs `sync_failed`. Stored rows preserved (no DELETE). |
| Plugin payload exceeds POST_MAX_SIZE | GET request; size limit is on dashboard's HTTP client. 500-plugin cap prevents this in practice. |
| Plugin slug containing odd chars | VARCHAR(191) + UTF-8 handles any WP-valid slug. |
| Concurrent dbDelta on plugin install | Schema migration's option-version guard prevents double-execution. |
| Migration rollback | Removing the table is not part of P2.1. If the user disables the plugin, `uninstall.php` (existing) wipes everything; no special handling for P2.1's table needed beyond adding it to the wipe list. |

---

## 14. Testing strategy

Same TDD discipline as F1-F10. Each new file gets a test before implementation lands.

### 14.1 Connector

| File | What it covers |
|---|---|
| `tests/Integration/SiteInfo/PluginListCollectorTest.php` | `PluginListCollector::collect()` — empty plugins, all active, network-active merge, update_available derived from transient, truncate at 500, skips empty-name plugins, version-empty normalization |
| `tests/Integration/Rest/PluginsListTest.php` | `GET /plugins` — 401 unsigned, 401 wrong signature, 404 when state ≠ connected, 200 with expected payload when signed |
| `tests/Integration/Rest/PluginsRefreshTest.php` | `POST /plugins/refresh` — invokes `wp_update_plugins()` (mocked), returns updated payload, 502 when `wp_update_plugins` throws |
| `tests/Integration/Rest/PluginsCacheHeadersTest.php` | Both endpoints ship `Cache-Control: no-store` headers (regression guard for the v0.1.2 Batcache bug) |

### 14.2 Dashboard

| File | What it covers |
|---|---|
| `tests/Integration/Schema/SitePluginsTableTest.php` | dbDelta creates table, indexes exist, schema-version option bumps on migration |
| `tests/Integration/Services/SyncPluginsServiceTest.php` | Delta cases: empty→empty (no-op), empty→incoming (all INSERT), unchanged (no UPDATE), changed version (UPDATE only diffed), removed (DELETE), mixed scenario covering all four |
| `tests/Integration/Services/SyncPluginsServiceFailureTest.php` | Connector pre-v0.1.3 → logs `connector_below_v0.1.3` once per 24h, preserves existing rows |
| `tests/Integration/Rest/SitesPluginsListTest.php` | `GET /sites/{id}/plugins` — 200 for owner, 404 for non-owner, 401 for no JWT, body matches stored rows |
| `tests/Integration/Rest/SitesPluginsRefreshTest.php` | `POST /sites/{id}/plugins/refresh` — 202, schedules AS job, writes activity log event, rate-limit returns 429 after 6 |
| `tests/Integration/Jobs/RefreshSitePluginsTest.php` | AS job calls connector, runs SyncPluginsService, logs `plugin_inventory.synced` |
| `tests/Integration/Jobs/SyncSitePluginsCalledFromSyncSiteTest.php` | Extended `defyn_sync_site` also calls plugins sync after status sync |

### 14.3 SPA

| File | What it covers |
|---|---|
| `apps/web/src/components/sites/__tests__/SitePluginsPanel.test.tsx` | Renders skeleton on initial load, rows on data arrival, update badges only on plugins with update_available, "Updates only" toggle filters client-side, refresh button disables during mutation, empty state when 0 plugins, "below v0.1.3" banner |
| `apps/web/src/components/sites/__tests__/SitePluginsRow.test.tsx` | Row structure — name + slug two-line, version cell, badge with target version, hidden actions slot |
| `apps/web/src/lib/queries/__tests__/useSitePlugins.test.ts` | Hook calls correct path, parses with Zod, refetchInterval pass-through |
| `apps/web/src/lib/mutations/__tests__/useRefreshSitePlugins.test.ts` | Mutation POSTs, sets isPolling, polling stops when last_synced_at advances past triggerAt, hard timeout at 60s |
| `apps/web/src/test/handlers.ts` | MSW handlers for `GET /sites/:id/plugins` and `POST /sites/:id/plugins/refresh` |

### 14.4 Manual smoke (final phase task)

Mirrors F5-Task-21 / F10-Task-8.

1. Run all unit + integration tests green (CI matrix).
2. Build connector v0.1.3 zip + dashboard v0.2.0 zip on operator's machine.
3. Operator uploads connector v0.1.3 to SmartCoding via wp-admin Plugins → Add → Upload → Replace.
4. Operator uploads dashboard v0.2.0 to defynwp.defyn.agency via wp-admin or Kinsta File Manager.
5. Run schema migration (auto on next request once plugin loaded).
6. Trigger `https://defynwp.defyn.agency/wp-cron.php?doing_wp_cron=1` 2-3 times to flush AS queue.
7. Curl `GET /defyn/v1/sites/1/plugins` with operator JWT → verify body shows ~21 plugin rows with correct shape.
8. Curl `POST /defyn/v1/sites/1/plugins/refresh` → 202; poll `GET /sites/1/plugins` every 2s; verify `last_synced_at` advances within 30s.
9. Open SPA → SmartCoding site detail → verify Plugins panel renders, "Updates only" toggle works, click refresh and observe polling.
10. Tail activity feed; verify `plugin_inventory.refresh_requested` + `plugin_inventory.synced (source: refresh)` events.

---

## 15. Version bumps shipping at end of P2.1

- **Connector:** v0.1.2 → **v0.1.3** — new `/plugins` + `/plugins/refresh` endpoints
- **Dashboard:** v0.1.1 → **v0.2.0** — new table + new endpoints + new AS job; minor bump for new public REST surface

`foundation-complete` tag stays where it is. P2.1 ships its own tag `p2-1-plugin-inventory-complete` at the end.

---

## 16. Open questions / decisions to revisit

None at the time of writing. Items likely to surface during implementation planning (writing-plans phase):

- Whether to retry `/plugins` once on transient network failure during background sync (today: no retry, fails fast and logs)
- Whether `SitePluginsRefreshController` should also dispatch an immediate (in-request) sync rather than scheduling an AS job, given refresh is operator-triggered and may want sub-second latency — *current design defers to AS for consistency with F7's pattern*
- Whether to surface `truncated: true` in the SPA when a site has > 500 plugins — *current design: silent for P2.1, document in admin docs only*
- Exact response-shape field the dashboard's `SignedHttpClient` exposes for the connector's error envelope (`->code` vs `->errorCode` vs `->error['code']`). Pseudocode in §6.1 / §6.2 uses `code` for brevity; writing-plans phase resolves the actual property name from the existing SignedHttpClient interface.

---

## Appendix A — Files added / modified

**Connector plugin (v0.1.3):**
- `src/SiteInfo/PluginListCollector.php` (NEW)
- `src/Rest/PluginsListController.php` (NEW)
- `src/Rest/PluginsRefreshController.php` (NEW)
- `src/Rest/RestRouter.php` (MODIFY — register 2 new routes)
- `defyn-connector.php` (MODIFY — version bump)
- `readme.txt` (MODIFY — stable tag + changelog)
- 4 new test files (§14.1)

**Dashboard plugin (v0.2.0):**
- `src/Schema/SitePluginsTable.php` (NEW)
- `src/Models/Plugin.php` (NEW — value object)
- `src/Services/SitePluginsRepository.php` (NEW)
- `src/Services/SyncPluginsService.php` (NEW)
- `src/Jobs/RefreshSitePlugins.php` (NEW)
- `src/Jobs/SyncSite.php` (MODIFY — also sync plugins after status)
- `src/Rest/SitesPluginsListController.php` (NEW)
- `src/Rest/SitesPluginsRefreshController.php` (NEW)
- `src/Rest/RestRouter.php` (MODIFY — register 2 new routes)
- `src/Rest/Middleware/RateLimit.php` (MODIFY — add `pluginsRefresh` method)
- `src/Activation.php` (MODIFY — call `SitePluginsTable::createSql` via dbDelta + schema-version migration logic)
- `src/Plugin.php` (MODIFY — register new AS hook for `defyn_refresh_site_plugins`)
- `src/Uninstaller.php` (MODIFY — drop new table on uninstall)
- `defyn-dashboard.php` (MODIFY — version bump)
- 7 new test files (§14.2)

**SPA:**
- `apps/web/src/components/sites/SitePluginsPanel.tsx` (NEW)
- `apps/web/src/components/sites/SitePluginsRow.tsx` (NEW)
- `apps/web/src/lib/queries/useSitePlugins.ts` (NEW)
- `apps/web/src/lib/mutations/useRefreshSitePlugins.ts` (NEW)
- `apps/web/src/types/api/plugins.ts` (NEW)
- `apps/web/src/routes/SiteDetail.tsx` (MODIFY — render panel + widen container)
- `apps/web/src/test/handlers.ts` (MODIFY — add MSW handlers)
- 4 new test files (§14.3)

---

## Appendix B — Definition of "P2.1 done"

- ✅ Plan written, executed via superpowers:subagent-driven-development with TDD throughout
- ✅ All new tests green; existing F1-F10 tests still green
- ✅ Connector v0.1.3 + dashboard v0.2.0 zips built
- ✅ Manual smoke (§14.4) walked end-to-end against SmartCoding
- ✅ One commit per logical surface (connector, dashboard, SPA) — ~3 commits on `main`
- ✅ Tag `p2-1-plugin-inventory-complete` pushed to origin
- ✅ MEMORY note updated to reflect P2.1 shipped
