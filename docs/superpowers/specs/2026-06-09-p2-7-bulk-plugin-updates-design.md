# P2.7 — Bulk plugin updates across fleet (Design Spec)

**Date:** 2026-06-09
**Status:** Approved (brainstorming complete §1→§8)
**Predecessor:** P2.6 — Sync all sites bulk action, tag `p2-6-sync-all-sites-complete` (commit `1379f24`). Dashboard v0.7.1 live in prod.
**Successor candidates:** P2.7.1 (minor-only filter), P2.8 (bulk theme updates)
**Spec scope:** Add a "Bulk update plugins (N)" button to the Operator Overview at `/overview`. Operator reviews + selectively unchecks (site, plugin) pairs in a destructive-tier confirmation dialog, then launches a fan-out of the existing P2.2 `defyn_update_site_plugin` AS job per confirmed pair. Two new REST endpoints, one new dashboard release (v0.8.0), no connector changes, no schema changes.

---

## §1. Architecture overview

**Goal:** ship a single "Bulk update plugins" button on the Overview header that lets the operator confirm + fan-out plugin updates across every site they own, in a single action. Operator reviews the full list of `(site, plugin, current → target)` triples in a confirmation dialog and can uncheck any pair before launching.

**Two new REST endpoints:**

1. **`GET /defyn/v1/overview/pending-plugin-updates`** — enumerates eligible pairs for the dialog. Returns a flat list of `{site_id, site_label, slug, plugin_name, current_version, target_version}` rows. One INNER JOIN against `defyn_sites` + `defyn_site_plugins` filtered by `user_id` + `update_available = 1`. Rate limit **30/MINUTE** per user (matches `/overview` since the SPA may fetch it on dialog open).
2. **`POST /defyn/v1/overview/bulk-update-plugins`** — body `{updates: [{site_id, slug}, ...]}`. Server validates ownership + `update_available = 1` for each pair (silently skips invalid pairs, reports them in the response), fan-outs `as_schedule_single_action('defyn_update_site_plugin', [$siteId, $slug, 0], 'defyn')` per valid pair, emits ONE fleet-scoped `overview.bulk_plugin_update_requested` activity event with `site_id = null` and `details: {scheduled_count, skipped_count, pairs: [{site_id, slug}, ...]}`. Rate limit **5/HOUR** per user (`RateLimit::bulkPluginUpdate`). Bypasses the underlying per-(user, site, slug) `pluginsUpdate` 6/HOUR bucket — operator's explicit dialog confirmation IS the safety.

**Persistence model:** no new schema, no job entity. Aggregate progress visibility comes from the existing Recent Activity widget on Overview (polling at 60s), same as P2.6. The per-pair `plugin_update.requested|started|succeeded|failed` triplet continues to fire from each fanned-out `UpdateSitePlugin` AS job (P2.2 + P2.2.1 plumbing — unchanged).

**Inventory freshness:** trust the existing inventory. If a pair was already updated externally between dialog-open and confirm, the per-pair `UpdateSitePlugin` AS job's existing `no_update_available` 409 handler emits `plugin_update.no_update_available` and exits cleanly. No preflight refresh.

**SPA side:**
- ONE new `BulkUpdatePluginsButton` component on `Overview.tsx` header (alongside the existing `SyncAllSitesButton`).
- ONE new `ConfirmBulkUpdatePluginsDialog` — RED-tier destructive primary button (`variant="destructive"`), Cancel default focus, per-site collapsible groups with per-row checkboxes, all pre-checked by default, long lists collapse behind a "show all N sites ▾" disclosure.
- ONE new `PendingPluginUpdatesGroup` sub-component — per-site group with grouped checkbox + child rows.
- ONE new TanStack query hook `usePendingPluginUpdates()` — enabled-only-on-dialog-open (NOT polling).
- ONE new TanStack mutation hook `useBulkUpdatePlugins()` — invalidates `['overview']` + `['pendingPluginUpdates']` on success, NOT `['sites']`.

**Conditional rendering:** the bulk button is **hidden entirely** (not just disabled) when `pending_updates.plugins === 0`. Different from P2.6's "disabled at totalSites=0" because here the absence of pending updates means the operator has nothing to bulk-update — surfacing a disabled button would add visual noise.

**Schema:** stays at **v6**. No new tables, no new columns.

**Connector:** no changes. Stays at **v0.1.7**. The existing `UpdateSitePlugin` AS job from P2.2 handles each fanned-out pair without modification.

**Dashboard:** **v0.7.1 → v0.8.0** (minor bump — additive endpoints + new destructive bulk operation crosses a meaningful threshold).

---

## §2. Dashboard REST contract

### 2.1 GET `/defyn/v1/overview/pending-plugin-updates`

| Field | Value |
|---|---|
| Auth | Bearer JWT |
| Rate limit | `RateLimit::overviewPendingPluginUpdates` — **30/MINUTE per user** (`OVERVIEW_PENDING_PLUGIN_UPDATES_LIMIT = 30`, window `MINUTE_IN_SECONDS`) |
| Cache headers | `Cache-Control: no-store` (inherited from `RestRouter::applyNoCacheHeaders`) |
| Body | empty / not required |

**Response (200):**
```json
{
  "pending_updates": [
    {
      "site_id": 1,
      "site_label": "SmartCoding",
      "slug": "akismet",
      "plugin_name": "Akismet Anti-Spam",
      "current_version": "5.3",
      "target_version": "5.3.1"
    }
  ],
  "generated_at": "2026-06-09 23:15:00"
}
```

`generated_at` is `gmdate('Y-m-d H:i:s')` — same UTC format as `/overview`'s `generated_at`.

**SQL (in new `SitePluginsRepository::findAllPendingUpdatesForUser`):**

```sql
SELECT s.id AS site_id, s.label AS site_label,
       sp.slug, sp.name AS plugin_name,
       sp.version AS current_version, sp.update_version AS target_version
FROM {sites} s
INNER JOIN {site_plugins} sp ON sp.site_id = s.id
WHERE s.user_id = %d
  AND sp.update_available = 1
ORDER BY s.label, sp.name
```

### 2.2 POST `/defyn/v1/overview/bulk-update-plugins`

| Field | Value |
|---|---|
| Auth | Bearer JWT |
| Rate limit | `RateLimit::bulkPluginUpdate` — **5/HOUR per user** (`BULK_PLUGIN_UPDATE_LIMIT = 5`, window `HOUR_IN_SECONDS`) |
| Body | `{updates: [{site_id: int, slug: string}, ...]}` (must be non-empty array) |
| Cache headers | `Cache-Control: no-store` |

**Response (202 — success, scheduled_count > 0):**
```json
{
  "scheduled_count": 47,
  "skipped_count": 3,
  "scheduled_pairs": [{"site_id": 1, "slug": "akismet"}],
  "skipped_pairs": [
    {"site_id": 1, "slug": "yoast", "reason": "no_update_available"},
    {"site_id": 5, "slug": "elementor", "reason": "site_not_owned"},
    {"site_id": 8, "slug": "wpml", "reason": "plugin_not_found"}
  ],
  "scheduled_at": "2026-06-09 23:15:42"
}
```

**Response (200 — all pairs skipped, no jobs scheduled):** same envelope shape with `scheduled_count: 0`, `scheduled_pairs: []`, full `skipped_pairs` array. NO activity event emitted (matches P2.6 guardrail #4 pattern).

**Response (400 — empty / malformed body):** `{error: {code: "bulk.empty_updates", message: "updates array must be non-empty"}}`

**Response (429 — over rate limit):** `{error: {code: "bulk.rate_limited", message: "Too many bulk update requests. Try again in an hour."}}`

**Skip reasons (exact strings):**

| Reason | Trigger |
|---|---|
| `site_not_owned` | `SitesRepository::findByIdForUser($siteId, $userId)` returns null |
| `plugin_not_found` | `SitePluginsRepository::findRowForSiteAndSlug($siteId, $slug)` returns null |
| `no_update_available` | row exists but `update_available = 0` |

All skip cases are silent — the operator sees them in the response but no 4xx is returned (the bulk is a "best-effort fan-out").

### 2.3 Controller flow (PHP pseudocode)

```php
final class OverviewBulkUpdatePluginsController
{
    public function __construct(
        private readonly SitesRepository $sites = new SitesRepository(),
        private readonly SitePluginsRepository $plugins = new SitePluginsRepository(),
        private readonly ActivityLogger $logger = new ActivityLogger(),
    ) {}

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        // Defensive STDOUT guard — carry-forward from P2.2 plan-bug #4.
        ob_start();
        try {
            $userId = (int) $request->get_param('_authenticated_user_id');
            $body   = $request->get_json_params();
            $updates = $body['updates'] ?? [];

            if (!is_array($updates) || count($updates) === 0) {
                return ErrorResponse::create(400, 'bulk.empty_updates', 'updates array must be non-empty');
            }

            $scheduled = [];
            $skipped   = [];

            foreach ($updates as $pair) {
                $siteId = (int) ($pair['site_id'] ?? 0);
                $slug   = (string) ($pair['slug'] ?? '');

                if ($this->sites->findByIdForUser($siteId, $userId) === null) {
                    $skipped[] = ['site_id' => $siteId, 'slug' => $slug, 'reason' => 'site_not_owned'];
                    continue;
                }
                $row = $this->plugins->findRowForSiteAndSlug($siteId, $slug);
                if ($row === null) {
                    $skipped[] = ['site_id' => $siteId, 'slug' => $slug, 'reason' => 'plugin_not_found'];
                    continue;
                }
                if ((int) ($row['update_available'] ?? 0) !== 1) {
                    $skipped[] = ['site_id' => $siteId, 'slug' => $slug, 'reason' => 'no_update_available'];
                    continue;
                }

                as_schedule_single_action(time(), 'defyn_update_site_plugin', [$siteId, $slug, 0], 'defyn');
                $scheduled[] = ['site_id' => $siteId, 'slug' => $slug];
            }

            if (count($scheduled) > 0) {
                $this->logger->log(
                    $userId,
                    null,                                              // fleet-scoped — plan-bug trap #4
                    'overview.bulk_plugin_update_requested',           // exact string — plan-bug trap #3
                    [
                        'scheduled_count' => count($scheduled),
                        'skipped_count'   => count($skipped),
                        'pairs'           => array_values($scheduled),
                    ]
                );
            }

            return new WP_REST_Response(
                [
                    'scheduled_count' => count($scheduled),
                    'skipped_count'   => count($skipped),
                    'scheduled_pairs' => array_values($scheduled),
                    'skipped_pairs'   => array_values($skipped),
                    'scheduled_at'    => gmdate('Y-m-d H:i:s'),
                ],
                count($scheduled) > 0 ? 202 : 200
            );
        } finally {
            ob_end_clean();
        }
    }
}
```

### 2.4 File structure (Dashboard)

| File | Responsibility |
|---|---|
| `src/Rest/OverviewPendingPluginUpdatesController.php` (new) | GET endpoint — flat list of eligible pairs |
| `src/Rest/OverviewBulkUpdatePluginsController.php` (new) | POST endpoint — validate + fan-out + fleet activity event |
| `src/Rest/Middleware/RateLimit.php` (extend) | Add `OVERVIEW_PENDING_PLUGIN_UPDATES_LIMIT/WINDOW` + `BULK_PLUGIN_UPDATE_LIMIT/WINDOW` constants + 2 new permission methods |
| `src/Rest/RestRouter.php` (extend) | Register the 2 new routes (immediately after `/overview/sync-all`, BEFORE `/activity`) |
| `src/Services/SitePluginsRepository.php` (extend) | Add `findAllPendingUpdatesForUser(int $userId): array` for the GET endpoint |

### 2.5 Activity event contract

| Field | Value |
|---|---|
| `event_type` | `overview.bulk_plugin_update_requested` (exact match) |
| `user_id` | the authenticated operator |
| `site_id` | `null` (fleet-scoped, mirror of P2.6 `overview.sync_all_requested`) |
| `details` | `{scheduled_count: int, skipped_count: int, pairs: [{site_id: int, slug: string}, ...]}` |

This is the ONLY new event type P2.7 introduces. Per-pair `plugin_update.requested|started|succeeded|failed|no_update_available` continue to fire from `UpdateSitePlugin` exactly as today.

### 2.6 Tests (~12 PHP)

**`OverviewPendingPluginUpdatesControllerTest`:**
- `testAuthRequiredReturns401WhenNoBearerToken`
- `testHappyPath200WithFlatList`
- `testRateLimit429AfterThirtyFirstCall` (30/MINUTE — mirrors P2.5)
- `testOwnershipScopingExcludesOtherUsersSites`

**`OverviewBulkUpdatePluginsControllerTest`:**
- `testAuthRequiredReturns401WhenNoBearerToken`
- `testHappyPath202WithScheduledPairs` — seeds 3 valid pairs, asserts 202 + scheduled_pairs matches
- `testEmptyUpdatesReturns400`
- `testRateLimit429AfterSixthCall` ← **critical: NOT seventh, NOT eleventh, NOT thirty-first**
- `testSkipsPairsNotOwnedOrWithoutUpdate` — seeds 3 invalid pairs (one per skip reason), asserts each maps to the correct `reason` string
- `testFanOutSchedulesPerPair` — uses `as_get_scheduled_actions(['hook' => 'defyn_update_site_plugin'])` to assert N pending actions
- `testActivityEventEmittedWithCorrectDetails` — asserts `overview.bulk_plugin_update_requested` row with `site_id = null` and correct `details` JSON
- `testZeroValidPairsReturns200AndNoActivityEvent` — all 3 skip reasons fire, no log row written

**`SitePluginsRepositoryPendingUpdatesTest`:**
- `testFindAllPendingUpdatesForUserReturnsCorrectRowsAcrossSites`
- `testFindAllPendingUpdatesForUserExcludesOtherUsers`

### 2.7 Version bump

- `packages/dashboard-plugin/defyn-dashboard.php`: `Version: 0.7.1` → `Version: 0.8.0` (also bump `DEFYN_DASHBOARD_VERSION` constant if defined).
- `packages/dashboard-plugin/composer.json`: `"version": "0.8.0"`.
- `packages/dashboard-plugin/readme.txt`: `Stable tag: 0.8.0` + changelog:

```
= 0.8.0 =
* Bulk plugin updates across fleet: POST /defyn/v1/overview/bulk-update-plugins fan-outs the existing P2.2 UpdateSitePlugin AS job per confirmed (site, plugin) pair. 5/hour rate limit. Single overview.bulk_plugin_update_requested activity event captures the fleet-scoped intent.
* New GET /defyn/v1/overview/pending-plugin-updates returns a flat list of eligible (site, plugin) pairs for the SPA's confirmation dialog. 30/minute rate limit.
* Minor version bump because the destructive bulk operation crosses a meaningful threshold relative to v0.7.1's read-side sync-all.
```

---

## §3. SPA UI — Bulk update button + dialog

### 3.1 Placement

Overview header gains a second bulk-action button alongside `SyncAllSitesButton`:

```
┌─────────────────────────────────────────────────────────────────────┐
│ Overview          Last refreshed: 2 minutes ago                     │
│                   [↻ Sync all sites]  [⚙ Bulk update plugins (47)]  │
├─────────────────────────────────────────────────────────────────────┤
│ [Plugin updates: 47] [Theme updates: 3] [WP core updates: 1/0]      │
│ [Sites needing attention]     [Recent activity]                     │
└─────────────────────────────────────────────────────────────────────┘
```

The right column becomes a stacked group: timestamp + **two buttons in a row** (`Sync all` + `Bulk update`). The bulk button shows the **dynamic count** in its label so the operator sees scope without opening the dialog.

**Conditional rendering:** the bulk button is **hidden entirely** (not just disabled) when `pending_updates.plugins === 0`. The Sync All button stays visible (always meaningful — sync is fan-out for fresh inventory).

### 3.2 Button states

| State | Visual |
|---|---|
| Idle | `⚙ Bulk update plugins (47)` — outline variant + Settings icon + dynamic count from `pending_updates.plugins` |
| Pending (mutation in flight) | Spinner + `Scheduling 47 updates…` (disabled) |
| Success (just fired, < 3s) | Brief `✓ 47 scheduled, 0 skipped` then revert to idle |
| Error | Toast `Couldn't schedule bulk update. Try again in a minute.` |

The 202 returns nearly instantly (just enqueues AS jobs). After ~250ms the request completes; the success label stays visible for ~3s via `setTimeout`, then reverts.

### 3.3 Confirm dialog content (RED-tier — destructive)

```
┌───────────────────────────────────────────────────────────────────┐
│ 🛑 Bulk update 47 plugins across 12 sites?                  [×]  │
├───────────────────────────────────────────────────────────────────┤
│  This will run the plugin upgrader on every checked pair below.   │
│  Each site briefly enters maintenance mode during its update.     │
│  Uncheck any pair you want to skip — server fans out exactly      │
│  what's checked. Already-updated rows are silently no-op'd.       │
├───────────────────────────────────────────────────────────────────┤
│  ☑ SmartCoding (smartcoding.com.au) — 5 plugins                  │
│     ☑ Akismet Anti-Spam        5.3       → 5.3.1                 │
│     ☑ Yoast SEO                22.5      → 22.6                  │
│     ☑ Elementor                3.18.2    → 3.19.0                │
│     ☑ WPML                     4.6.10    → 4.7.0                 │
│     ☑ Contact Form 7           5.9       → 6.0                   │
│                                                                   │
│  ☑ AcmeBlog (acmeblog.com) — 3 plugins                           │
│     ☑ Jetpack                  13.1      → 13.2                  │
│     ☑ Akismet Anti-Spam        5.3       → 5.3.1                 │
│     ...                                                            │
│                                                                   │
│  [show all 12 sites ▾]                                            │
├───────────────────────────────────────────────────────────────────┤
│ 47 selected of 47 available                                       │
│                              [Cancel]  [🛑 Bulk update 47 plugins]│
└───────────────────────────────────────────────────────────────────┘
```

**Key behaviors:**
- **All checkboxes pre-checked** when dialog opens. Operator unchecks specifically to skip.
- **Per-site group checkbox** toggles all plugins in that site simultaneously.
- **Footer counter** updates live: "X selected of Y available". Computed via `useMemo` from the controlled checkbox state map.
- **Primary button uses shadcn `variant="destructive"`** (`bg-red-600 hover:bg-red-700 text-white`) — matches P2.4.1's major core update button exactly. Distinct from P2.6's neutral sync (read-side) and P2.4 minor-core's amber (recoverable single-target).
- **Primary button disabled** when 0 pairs selected.
- **Primary button label includes the dynamic SELECTED count**, NOT the total available count. Selecting 30 of 47 makes the label "Bulk update 30 plugins".
- **Cancel has default focus** (mirror of P2.4 `ConfirmUpdateCoreDialog` lines 54-63 — `cancelRef` + `useEffect`).
- **Long lists collapse:** show first 3 sites expanded, rest behind a "show all N sites ▾" disclosure so the dialog doesn't dominate the viewport.

### 3.4 SPA files

| Path | Responsibility |
|---|---|
| `src/components/overview/BulkUpdatePluginsButton.tsx` (new) | Button + dialog state + mutation invocation + spinner + success transition |
| `src/components/overview/ConfirmBulkUpdatePluginsDialog.tsx` (new) | Modal — title, body, checkbox state, footer counter, RED primary button, Cancel default focus |
| `src/components/overview/PendingPluginUpdatesGroup.tsx` (new) | Sub-component: per-site collapsible group with grouped checkbox + child rows |
| `src/lib/queries/usePendingPluginUpdates.ts` (new) | TanStack query — fetches `/overview/pending-plugin-updates`, `enabled: dialogOpen` (NOT polling) |
| `src/lib/mutations/useBulkUpdatePlugins.ts` (new) | TanStack mutation — POSTs to `/overview/bulk-update-plugins`, invalidates `['overview']` + `['pendingPluginUpdates']` on success |
| `src/types/api.ts` (extend) | Add `pendingPluginUpdatesSchema` + `bulkUpdatePluginsResponseSchema` + corresponding `z.infer` types |
| `src/test/handlers.ts` (extend) | New MSW handlers for both endpoints |
| `src/routes/Overview.tsx` (extend) | Render `<BulkUpdatePluginsButton pendingCount={data.pending_updates.plugins} />` next to `SyncAllSitesButton` |

### 3.5 Mutation hook contract

```ts
interface BulkUpdatePluginsRequest {
  updates: { site_id: number; slug: string }[];
}

export function useBulkUpdatePlugins() {
  const queryClient = useQueryClient();
  return useMutation<BulkUpdatePluginsResponse, Error, BulkUpdatePluginsRequest>({
    mutationFn: async ({ updates }) => {
      const data = await apiClient.post<unknown>('/overview/bulk-update-plugins', { updates });
      return bulkUpdatePluginsResponseSchema.parse(data);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['overview'] });
      queryClient.invalidateQueries({ queryKey: ['pendingPluginUpdates'] });
      // NOT ['sites'] — per-site state hasn't changed yet, only AS jobs queued.
      // Same reasoning as P2.6 plan-bug trap #11.
    },
  });
}
```

### 3.6 SPA tests (~9)

- `BulkUpdatePluginsButton.test.tsx`:
  - `rendersIdleStateWithDynamicCount` — label includes "Bulk update plugins (47)"
  - `hiddenWhenPendingCountZero` — button NOT in DOM when prop is 0
  - `opensConfirmDialogOnClick` — clicking button reveals dialog with title "Bulk update {N} plugins across {M} sites?"
  - `showsPendingLabelWhilePostInFlight` — "Scheduling {N} updates…" visible during MSW-delayed POST
- `ConfirmBulkUpdatePluginsDialog.test.tsx`:
  - `cancelHasDefaultFocus` — on open, Cancel button has focus
  - `primaryButtonUsesDestructiveVariant` — class includes `bg-red-600`
  - `primaryButtonDisabledWhenZeroSelected` — uncheck all → button disabled
  - `footerCounterUpdatesLive` — uncheck 2 → "45 selected of 47 available"
- `useBulkUpdatePlugins.test.tsx`:
  - `postsToBulkUpdateEndpointWithCorrectBody` — verifies `{updates: [...]}` shape
  - `invalidatesOverviewAndPendingQueriesOnSuccessButNotSites` — positive + negative assertions

---

## §4. Testing strategy

Total: **~21 new tests** (12 PHP + 9 SPA), enumerated inline in §2.6 + §3.6.

**Coverage gate:** ≥80% on new modules. CI auto-discovers.

**Explicitly NOT tested for P2.7:**
- Behavior of each per-site `UpdateSitePlugin` AS job — already proven by P2.2 + ongoing prod smoke (`jetpack-social` 8.0.1→9.0.0 worked end-to-end at P2.2 ship).
- Real Action Scheduler tick processing — we assert the JOBS ARE SCHEDULED via `as_get_scheduled_actions`, not that they run.
- Per-plugin `plugin_update.{requested|started|succeeded|failed|no_update_available}` triplet — already P2.2 + P2.2.1 coverage.
- Connector `Plugin_Upgrader` paths — already P2.2 + P2.2.1 coverage.
- Concurrent bulk fan-outs from the same operator — bounded naturally by the 5/HOUR rate limit.

---

## §5. Manual smoke flow

### 5.1 Pre-smoke setup

```bash
cd packages/dashboard-plugin
composer install --no-dev --classmap-authoritative
zip -rq ~/Desktop/defyn-dashboard-v0.8.0-$(date +%Y-%m-%d).zip . \
  -x "tests/*" "node_modules/*" "*.git*" "phpunit.xml*" "*.lock" \
     "vendor/wordpress/*" "vendor/johnpbloch/*" \
     "*wp-tests-config.php" "*.phpunit.result.cache"
composer install
```

Target zip size: **~552KB**.

1. Install on `defynwp.defyn.agency` via "Replace current with uploaded version".
2. **MyKinsta → Tools → Clear cache** (busts OPcache + Redis — every P2.x phase carry-forward).
3. Build SPA: `cd apps/web && pnpm build`. Push branch + main → Cloudflare auto-deploys.

### 5.2 Smoke matrix — 8 steps

**Prerequisite:** prod must have SmartCoding (or some site with pending plugin updates) registered for `user_id=1`. P2.6 smoke ran in zero-sites state; P2.7 needs at least one connected site with `update_available = 1` plugins to exercise the full happy path.

```bash
TOKEN=$(curl -s -X POST https://defynwp.defyn.agency/wp-json/defyn/v1/auth/login \
  -H "Content-Type: application/json" \
  --data '{"email":"pradeep@defyn.com.au","password":"<password>"}' \
  | python3 -c "import sys,json; print(json.load(sys.stdin)['access_token'])")
```

| # | Action | Expected |
|---|---|---|
| 1 | `curl -H "Authorization: Bearer $TOKEN" "https://defynwp.defyn.agency/wp-json/defyn/v1/overview/pending-plugin-updates?_=$RANDOM"` | 200 + `{pending_updates: [{site_id, site_label, slug, plugin_name, current_version, target_version}], generated_at}`. Shape matches §2.1. |
| 2 | Same GET WITHOUT `Authorization` header | 401 `auth.missing_token` |
| 3 | `curl -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" --data '{"updates":[]}' "https://.../overview/bulk-update-plugins?_=$RANDOM"` | 400 `bulk.empty_updates` |
| 4 | POST with 1 valid pair, e.g. `{"updates":[{"site_id":1,"slug":"akismet"}]}` | 202 + `{scheduled_count:1, skipped_count:0, scheduled_pairs:[{site_id:1,slug:"akismet"}], skipped_pairs:[], scheduled_at}` |
| 5 | POST with 1 invalid pair: `{"updates":[{"site_id":1,"slug":"does-not-exist"}]}` | 200 + `{scheduled_count:0, skipped_count:1, skipped_pairs:[{site_id:1,slug:"does-not-exist",reason:"plugin_not_found"}], scheduled_at}`. **Activity log: zero new `overview.bulk_plugin_update_requested` rows.** |
| 6 | 6× POST from same user within 1 hour (use `?_=$RANDOM` to defeat Kinsta edge cache) | 6th returns 429 `bulk.rate_limited` |
| 7 | `curl -H "Authorization: Bearer $TOKEN" "https://.../activity?per_page=10&_=$RANDOM"` after step 4, within ~30-90s | `overview.bulk_plugin_update_requested` event (fleet — `site_id:null`, `details:{scheduled_count:1, pairs:[{site_id:1,slug:"akismet"}]}`) + downstream `plugin_update.requested → started → succeeded\|failed` triplet for akismet |
| 8 | SPA at `/overview` → click "Bulk update plugins (N)" → uncheck 2 pairs → confirm | Dialog shows N pairs (all checked), unchecking decrements footer counter, RED primary updates label to "Bulk update {N-2} plugins", click → brief spinner → success label → activity widget shows the new fleet event within 60s |

### 5.3 Cleanup

None. Bulk update is the same per-site upgrade path as P2.2's per-row button — `last_update_attempt_at` advances naturally on each pair. The 5/hour rate-limit transient expires within an hour.

### 5.4 Tag + push

```bash
git tag -a p2-7-bulk-plugin-updates-complete -m "P2.7 — Bulk plugin updates across fleet shipped"
git push origin p2-7-bulk-plugin-updates-complete
```

Push only after all 8 smoke steps green.

---

## §6. Out of scope (deferred)

| Deferred | What |
|---|---|
| **P2.7.1** | "Minor only" filter — currently any-update is bulk-eligible. If operators routinely need to skip major bumps, add a client-side semver comparison + a "Skip major bumps" toggle in the dialog. |
| **P2.8 (next bulk action)** | "Bulk update themes" — same fan-out pattern but on the `defyn_update_site_theme` AS job. Should reuse 80% of P2.7's controller + dialog code via shared base class (refactor opportunity). |
| **P2.x** | First-class bulk-job entity (`wp_defyn_bulk_jobs` table) for cancel + resume + history. Only if operators report needing it. |
| **P2.x** | Filtered drill-in `/overview/plugins` route (deferred from P2.5 § 7 list). A real list view with per-row Update buttons — different problem from bulk-fan-out. |
| **Future** | Per-user configurable bulk-action thresholds (e.g. "auto-pause if > 100 pairs"). |
| **Future** | Real-time progress widget (WebSocket) — currently the 60s activity poll is "progress UI". |
| **Future** | Email notification when bulk completes — currently no completion event (no entity tracking aggregate state). |
| **Future** | Bulk-cancel mid-flight — currently fire-and-forget. |

---

## §7. Plan-author notes (carry-overs for writing-plans)

**Branch off `p2-6-sync-all-sites`** (current tip `1379f24`, which is now also `main`). Branch name: `p2-7-bulk-plugin-updates`.

**Plan-bug traps to internalise:**

1. **`RateLimit::BULK_PLUGIN_UPDATE_LIMIT = 5`** per HOUR. Window `HOUR_IN_SECONDS`. Test method MUST be `testRateLimit429AfterSixthCall`. Distinct from P2.6's `OVERVIEW_SYNC_ALL_LIMIT = 10` and P2.5's `OVERVIEW_LIMIT = 30/MINUTE`.

2. **`RateLimit::OVERVIEW_PENDING_PLUGIN_UPDATES_LIMIT = 30`** per **MINUTE**. Window `MINUTE_IN_SECONDS` — NOT HOUR. Test method `testRateLimit429AfterThirtyFirstCall`.

3. **Activity event name MUST be EXACTLY** `overview.bulk_plugin_update_requested`. Not `overview.bulk_plugin_updates_requested` (plural typo), not `plugin.bulk_update_requested`, not `bulk_plugin_update.requested`. The smoke step 7 grep + test `testActivityEventEmittedWithCorrectDetails` assert this exact string.

4. **`site_id = null` on the activity event** (fleet-scoped — mirror of P2.6 pattern). The `pairs[]` array goes inside `details` JSON. `ActivityLogger::log(?int $userId, ?int $siteId, string $eventType, ?array $details = null, ?string $ipAddress = null): void` — pass null as second positional arg.

5. **Activity event MUST NOT fire when `scheduled_count === 0`** (all pairs skipped) — same as P2.6 guardrail #4. Test `testZeroValidPairsReturns200AndNoActivityEvent` asserts no row written.

6. **Endpoint returns 202 ONLY when `scheduled_count > 0`; 200 with the same envelope shape when 0** (all pairs skipped). 400 for empty body. Three distinct response shapes for three distinct outcomes.

7. **AS hook is `defyn_update_site_plugin`** (existing P2.2 hook — `UpdateSitePlugin::HOOK`). Args `[$siteId, $slug, 0]` where the 0 is `attempt`. The 4th group arg `'defyn'` per codebase convention (P2.6 spec-reviewer confirmed 15/18 existing call sites use it).

8. **Bulk endpoint BYPASSES the per-(user, site, slug) `pluginsUpdate` 6/HOUR bucket.** Operator's explicit dialog confirmation IS the safety. Do NOT add preflight bucket checks. The bulk endpoint's own 5/HOUR cap is the rate-limit boundary.

9. **Confirm dialog primary button is RED-tier** — shadcn `variant="destructive"` (`bg-red-600 hover:bg-red-700 text-white`). This matches P2.4.1's major core update button exactly. Distinct from P2.6's neutral `Sync all sites` (read-side action). Distinct from P2.4 minor-core's amber (recoverable single-target).

10. **Cancel button has default focus** — mirror P2.4 `ConfirmUpdateCoreDialog` lines 54-63 (`cancelRef = useRef<HTMLButtonElement>(null)` + `useEffect(() => { if (open) cancelRef.current?.focus() }, [open])`).

11. **Mutation hook invalidates `['overview']` AND `['pendingPluginUpdates']` on success** — NOT `['sites']`. Per-site plugin states refresh naturally as each `UpdateSitePlugin` AS job executes. Same reasoning as P2.6 plan-bug trap #11.

12. **`usePendingPluginUpdates` is enabled-only-on-dialog-open** — NOT polling. Set `enabled: dialogOpen` on the TanStack query. Otherwise we'd hit the 30/MIN bucket from the SPA's existing 60s `/overview` poll noise.

13. **Defensive `ob_start()` + `ob_end_clean()` in try/finally** in BOTH new controllers — P2.2 plan-bug #4 carry-forward.

14. **`RestRouter` registration** for the two new routes goes **immediately after** the existing `/overview/sync-all` POST registration and **BEFORE** `/activity` (plan-bug trap from P2.6).

15. **Per-site group checkbox in dialog** toggles all child checkboxes. The footer counter "X selected of Y available" must update via React state, computed via `useMemo` (not derived on every render).

16. **Long lists collapse:** first 3 sites expanded, rest behind a disclosure. Test asserts `getAllByTestId('plugin-group').length` equals `min(3, totalSites)` when collapsed.

17. **Dashboard zip build:** `composer install --no-dev --classmap-authoritative` FIRST. Exclude `vendor/wordpress/*` + `vendor/johnpbloch/*` + `*wp-tests-config.php` + `*.phpunit.result.cache` (P2.6 carry-forward). Target ~552KB.

18. **MyKinsta cache clear** after install — every P2.x phase carry-forward.

19. **Final smoke matrix § 5.2 verbatim — 8 steps.** Tag only after all 8 pass. Note: prod must have SmartCoding (or equivalent) registered for `user_id=1` before smoke can exercise the full happy path.

**Estimated plan size: ~13 TDD tasks** across 3 phases:

- **Phase A — Dashboard (6 tasks):**
  - Task 1: `SitePluginsRepository::findAllPendingUpdatesForUser` + 2 tests
  - Task 2: `RateLimit::overviewPendingPluginUpdates` (30/MIN) constants + method + `OverviewPendingPluginUpdatesController` + route + 4 tests
  - Task 3: `RateLimit::bulkPluginUpdate` (5/HR) constants + method + `OverviewBulkUpdatePluginsController` + route + 8 tests
  - Task 4: Dashboard v0.8.0 release bump + CORS regressions for both routes
- **Phase B — SPA (5 tasks):**
  - Task 5: Zod schemas + MSW handlers for both endpoints
  - Task 6: `usePendingPluginUpdates` query hook + tests
  - Task 7: `useBulkUpdatePlugins` mutation hook + tests
  - Task 8: `PendingPluginUpdatesGroup` sub-component + `ConfirmBulkUpdatePluginsDialog` + tests
  - Task 9: `BulkUpdatePluginsButton` + Overview.tsx integration + tests
- **Phase C — Ship (2 tasks):**
  - Task 10: Build zips + 8-step smoke matrix
  - Task 11: Tag + push + MEMORY

---

## §8. Acceptance criteria

P2.7 is shipped when:

- [ ] ~21 new tests green in CI (12 PHP + 9 SPA)
- [ ] Dashboard v0.8.0 zip built per § 5.1 discipline
- [ ] Production install via "Replace current with uploaded version" succeeds; MyKinsta cache cleared
- [ ] SPA built via `pnpm build` + pushed to main → Cloudflare auto-deploys
- [ ] Smoke matrix § 5.2 steps 1-8 all green (prerequisite: SmartCoding registered for `user_id=1`)
- [ ] Tag `p2-7-bulk-plugin-updates-complete` pushed
- [ ] MEMORY.md updated with any plan-bug lessons surfaced during execution
