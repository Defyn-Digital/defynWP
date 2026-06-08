# P2.6 — "Sync all sites now" Bulk Action (Design Spec)

**Date:** 2026-06-08
**Status:** Approved (brainstorming complete §1→§8)
**Predecessor:** P2.5 — Operator overview dashboard, tag `p2-5-overview-dashboard-complete` (commit `d601333`) + relative-time polish (commit `1332816`). Dashboard v0.7.0 live in prod.
**Successor:** P2.7 — Bulk plugin updates (deferred)
**Spec scope:** Add ONE bulk action to the Operator Overview at `/overview` — a "Sync all sites now" button that fan-outs the existing `SyncSite` Action Scheduler job for every site the user owns. Read-side action only (no inventory writes — sync itself doesn't change anything; per-site sync events surface naturally in the existing activity feed). Smallest deliverable from P2.5 § 7's "P2.6 / Bulk actions" deferred list.

---

## §1. Architecture overview

**What ships:** a single "Sync all sites now" button on the Overview page header (top-right, beside the existing "Last refreshed: X ago" text). Click → confirmation dialog ("Sync all 12 sites now?") → POST → server fan-outs the existing `SyncSite` AS job for every owned site → activity feed gradually surfaces per-site `site.synced` triplets via the natural 60s poll.

**Server side:**
- ONE new REST endpoint `POST /defyn/v1/overview/sync-all`.
- ONE new `Rest/OverviewSyncAllController.php` — thin controller, inlines the fan-out loop (no service class — only ~6 lines of logic).
- Reuses existing `SitesRepository::findAllForUser($userId)` (no filter) + existing `Jobs\SyncSite` AS hook `defyn_sync_site` (in place since P2.1).
- New `RateLimit::OVERVIEW_SYNC_ALL_LIMIT = 10` per hour per user (same shape as `coreAllowMajor` from P2.4.1).
- Single `overview.sync_all_requested` activity event logged once at fan-out time with `details: {scheduled_count, site_ids[]}`. `site_id = null` because the event spans the fleet.
- Per-site `site.synced`, `plugin_inventory.synced`, etc. continue to fire from each `SyncSite` execution — no new per-site triplet introduced.

**SPA side:**
- ONE new TanStack mutation hook `useSyncAllSites()` — invalidates `['overview']` on success.
- ONE new `SyncAllSitesButton` component on `Overview.tsx`.
- ONE new `ConfirmSyncAllDialog` component.
- Button shows spinner + "Syncing N sites…" for ~3 seconds after the 202 response, then reverts to idle.
- Activity widget (already polling 60s) shows the new event naturally — no special progress UI.

**Offline site behavior:** sites with `last_contact_at > 15min` are included anyway. The per-site `SyncSite` attempt fails fast (connector unreachable) and emits its own `site.sync.failed` event — operator gets fresh diagnostic signal. Server does NOT pre-filter.

**Schema:** stays at **v6**. No new tables, no new columns.

**Connector:** no changes. Stays at **v0.1.7**.

**Dashboard:** **v0.7.0 → v0.7.1** (patch bump — additive endpoint only). One additive Zod schema field: `total_sites: int` on the `/overview` response so the SPA button can display "Sync all 12 sites".

---

## §2. Dashboard REST — `POST /defyn/v1/overview/sync-all`

### 2.1 Route

```
POST /defyn/v1/overview/sync-all
```

| Field | Value |
|---|---|
| Auth | Bearer JWT (same as `/overview`) |
| Rate limit | `RateLimit::overviewSyncAll` — **10/hour per user** (`OVERVIEW_SYNC_ALL_LIMIT = 10`, window `HOUR_IN_SECONDS`) |
| Body | empty / not required |
| Cache headers | `Cache-Control: no-store` (inherited from `RestRouter::applyNoCacheHeaders`) |

### 2.2 Response shape

**202 (sites exist):**
```json
{
  "scheduled_count": 12,
  "site_ids": [1, 2, 3, 5, 8, 9, 11, 12, 14, 17, 18, 21],
  "scheduled_at": "2026-06-08 09:30:42"
}
```

**200 (zero sites — no-op success):**
```json
{
  "scheduled_count": 0,
  "site_ids": [],
  "scheduled_at": "2026-06-08 09:30:42"
}
```

`scheduled_at` is `gmdate('Y-m-d H:i:s')` — same UTC format as `/overview`'s `generated_at`.

### 2.3 Controller flow

```php
final class OverviewSyncAllController {
    public function handle(WP_REST_Request $request): WP_REST_Response {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $sites  = $this->sites->findAllForUser($userId);   // no filter — ALL owned sites
        $ids    = array_map(static fn($s) => $s->id, $sites);

        foreach ($ids as $id) {
            as_schedule_single_action(time(), 'defyn_sync_site', [$id]);
        }

        if (count($ids) > 0) {
            (new ActivityLogger())->log(
                $userId,
                null,                                       // fleet-scoped — null site_id
                'overview.sync_all_requested',
                ['scheduled_count' => count($ids), 'site_ids' => $ids]
            );
        }

        return new WP_REST_Response([
            'scheduled_count' => count($ids),
            'site_ids'        => $ids,
            'scheduled_at'    => gmdate('Y-m-d H:i:s'),
        ], count($ids) > 0 ? 202 : 200);
    }
}
```

### 2.4 File structure

| File | Responsibility |
|---|---|
| `src/Rest/OverviewSyncAllController.php` (new) | Auth, fan-out, activity log, 202/200 response |
| `src/Rest/Middleware/RateLimit.php` (extend) | `OVERVIEW_SYNC_ALL_LIMIT = 10` + `OVERVIEW_SYNC_ALL_WINDOW = HOUR_IN_SECONDS` constants + `overviewSyncAll()` static method |
| `src/Rest/RestRouter.php` (extend) | Register new POST route |
| `src/Services/SitesRepository.php` (extend) | Add `countAllForUser(int $userId): int` for the new `total_sites` field on `/overview` |
| `src/Services/OverviewService.php` (extend) | Add `total_sites` to the response composition |

### 2.5 Activity event contract

| Field | Value |
|---|---|
| `event_type` | `overview.sync_all_requested` (exact match) |
| `user_id` | the authenticated operator |
| `site_id` | `null` (fleet-scoped, not site-scoped) |
| `details` | `{scheduled_count: int, site_ids: int[]}` |

This is the ONLY new event type P2.6 introduces. Per-site `site.synced`, `plugin_inventory.synced`, `theme_inventory.synced`, `core_inventory.refreshed`, `site.health_ok`, `site.sync.failed` continue to fire from `SyncSite` exactly as today.

### 2.6 `/overview` response extension

```json
{
  "pending_updates": { ... },
  "sites_needing_attention": [ ... ],
  "recent_activity": [ ... ],
  "total_sites": 12,           ← NEW additive field
  "generated_at": "..."
}
```

`SitesRepository::countAllForUser(int $userId): int` runs `SELECT COUNT(*) FROM {$sitesTable} WHERE user_id = %d`. Trivial.

`OverviewService::compose()` calls it and adds the field. The SPA's `overviewSchema` gains `total_sites: z.number().int().nonnegative()`.

### 2.7 Dashboard tests (~7 PHP)

- `OverviewSyncAllControllerTest`:
  - `testAuthRequiredReturns401WhenNoBearerToken`
  - `testHappyPath202WithFullEnvelopeShape` — seeds 3 sites, asserts `scheduled_count: 3` + `site_ids` matches
  - `testZeroSitesReturns200WithEmptyArrays` — no-op success path
  - `testRateLimit429AfterEleventhCall` — 10/hr bucket → 11th returns 429
  - `testOwnershipScopingExcludesOtherUsersSites` — user A's fan-out doesn't include user B's sites
  - `testFanOutSchedulesSyncSiteJobPerSite` — uses `as_get_scheduled_actions(['hook' => 'defyn_sync_site'])` to assert N pending actions after the POST
  - `testActivityEventEmittedWithCorrectDetails` — asserts `overview.sync_all_requested` row in `wp_defyn_activity_log` with the right `details` JSON
- `SitesRepositoryCountAllTest` (extend `SitesRepositoryOverviewTest` from P2.5):
  - `testCountAllForUserReturnsCorrectCount`
  - `testCountAllForUserExcludesOtherUsersSites`

### 2.8 Version bump

- `packages/dashboard-plugin/defyn-dashboard.php`: `Version: 0.7.0` → `Version: 0.7.1`
- `packages/dashboard-plugin/composer.json`: `"version": "0.7.1"`
- `packages/dashboard-plugin/readme.txt`: `Stable tag: 0.7.1` + changelog:

```
= 0.7.1 =
* Bulk action on /overview: POST /defyn/v1/overview/sync-all fan-outs the existing SyncSite job for every site the operator owns. 10/hour rate limit. Single overview.sync_all_requested activity event captures the fleet-scoped intent.
* /overview response gains total_sites field for the bulk-action UI counter.
```

---

## §3. SPA UI — `SyncAllSitesButton` on the Overview header

### 3.1 Placement

```
┌─────────────────────────────────────────────────────────────────────┐
│ Overview                          Last refreshed: 2 minutes ago     │
│                                   [↻ Sync all sites]                 │
├─────────────────────────────────────────────────────────────────────┤
│ [Plugin updates] [Theme updates] [WP core updates]                  │
│ [Sites needing attention]     [Recent activity]                      │
└─────────────────────────────────────────────────────────────────────┘
```

The header strip becomes a 2-column flex row:
- Left: "Overview" h1
- Right column (stacked): "Last refreshed: X ago" text + `<SyncAllSitesButton />`

Button uses the existing `Button` primitive: **ghost variant + small size + leading refresh icon** (lucide `RefreshCw`).

### 3.2 Files

| Path | Responsibility |
|---|---|
| `src/components/overview/SyncAllSitesButton.tsx` (new) | Button + confirm dialog state + mutation invocation + in-flight spinner |
| `src/components/overview/ConfirmSyncAllDialog.tsx` (new) | Modal: "Sync all N sites now?" + Cancel (default focus) + "Sync all N sites" neutral-tier action |
| `src/lib/mutations/useSyncAllSites.ts` (new) | TanStack mutation — POSTs to `/overview/sync-all`, validates response with Zod, invalidates `['overview']` on success |
| `src/types/api.ts` (extend) | Add `syncAllSitesResponseSchema` Zod for 202 envelope + extend `overviewSchema` with `total_sites: z.number().int().nonnegative()` |
| `src/test/handlers.ts` (extend) | MSW handler for `POST /overview/sync-all` returning synthetic `{scheduled_count, site_ids, scheduled_at}` + extend overview mock to include `total_sites` |
| `src/routes/Overview.tsx` (extend) | Render `<SyncAllSitesButton totalSites={data.total_sites} />` in the header strip |

### 3.3 Button states

| State | Visual |
|---|---|
| Idle | `↻ Sync all sites` (ghost button, foreground text) |
| Pending (mutation in flight) | Spinner + `Syncing N sites…` (disabled) |
| Success (just fired, < 3s) | Brief `✓ Sync started for N sites` then revert to idle |
| Error (4xx/5xx) | Toast `Failed to start bulk sync` + button reverts to idle |

The 202 returns nearly instantly (just enqueues AS jobs — no actual sync wait). The spinner is a "request in flight" indicator, not a "fan-out is done" indicator. After ~250ms the request completes; we keep the success label visible for ~3s for visual confirmation before reverting to idle.

Implementation note: use `setTimeout` to revert the success label, OR drive the state via `useMutation`'s `isSuccess` + a separate `useEffect` with a `data?.scheduled_at` dependency that triggers the 3s timer.

### 3.4 Confirm dialog content

```
┌───────────────────────────────────────────────────────────────┐
│ Sync all 12 sites now?                                  [×]   │
├───────────────────────────────────────────────────────────────┤
│                                                                │
│  This will queue a fresh sync to every connected site.         │
│  Offline sites are included — their sync will fail fast and    │
│  surface as a fresh sync.failed event in the activity feed.    │
│                                                                │
├───────────────────────────────────────────────────────────────┤
│                              [Cancel]  [Sync all 12 sites]    │
└───────────────────────────────────────────────────────────────┘
```

- **Cancel** has default focus (same convention as `ConfirmUpdateCoreDialog` from P2.4).
- Primary button uses the **neutral foreground color** (`bg-foreground text-background` / shadcn default Button variant) — NOT red. This is a read-side action, not a destructive write.
- "12" is sourced from the `totalSites` prop (from `useOverview().data.total_sites`).
- Dialog uses shadcn `<Dialog>` primitives — same convention as `ConfirmUpdateCoreDialog`.

### 3.5 SPA tests (~5)

- `SyncAllSitesButton.test.tsx`:
  - `rendersIdleStateWithTotalSitesCount` — button label includes "Sync all sites"
  - `opensConfirmDialogOnClick` — Click button → dialog visible with "Sync all 12 sites now?" title
  - `firesMutationOnConfirm` — Click confirm → POST to `/overview/sync-all` fires
  - `showsSpinnerWhilePending` — Mutation pending → spinner + "Syncing N sites…" label
- `ConfirmSyncAllDialog.test.tsx`:
  - `cancelHasDefaultFocus` — On open, Cancel button has `aria-focused`
  - `confirmButtonLabelIncludesTotalCount` — "Sync all 12 sites" (NOT "Sync all sites")
- `useSyncAllSites.test.tsx`:
  - `postsToSyncAllEndpoint`
  - `invalidatesOverviewQueryOnSuccess`
- `Overview.test.tsx` (extend):
  - `rendersSyncAllSitesButtonInHeader`

---

## §4. Testing strategy

Total: **~12 new tests** (~7 PHP + ~5 SPA) listed inline in §2.7 + §3.5.

**Coverage gate:** ≥80% on new modules. CI auto-discovers.

**Explicitly NOT tested for P2.6:**
- The behavior of each per-site `SyncSite` AS job — already proven by P2.1-P2.5 smoke + CI.
- Real Action Scheduler tick processing — we assert the JOBS ARE SCHEDULED via `as_get_scheduled_actions`, not that they run.
- Per-site sync events (`site.synced`, etc.) — same reasoning.
- Concurrent fan-outs — bounded naturally by the 10/hr rate limit.

---

## §5. Manual smoke flow

### 5.1 Pre-smoke setup

1. Build dashboard zip (same `composer install --no-dev --classmap-authoritative` discipline established in P2.4.1/P2.5):
   ```bash
   cd packages/dashboard-plugin
   composer install --no-dev --classmap-authoritative
   zip -rq ~/Desktop/defyn-dashboard-v0.7.1-$(date +%Y-%m-%d).zip . \
     -x "tests/*" "node_modules/*" "*.git*" "phpunit.xml*" "*.lock"
   composer install
   ```
   Target zip size: ~552KB.
2. Install on `defynwp.defyn.agency` via "Replace current with uploaded version".
3. **MyKinsta → Tools → Clear cache** (busts OPcache + Redis — plan-bug trap from P2.4.1).
4. Build SPA: `cd apps/web && pnpm build`. Push branch + main → Cloudflare auto-deploys.

### 5.2 Smoke matrix — 6 steps

```bash
TOKEN=$(curl -s -X POST https://defynwp.defyn.agency/wp-json/defyn/v1/auth/login \
  -H "Content-Type: application/json" \
  --data '{"email":"pradeep@defyn.com.au","password":"DefynWP-ifirCh5pXm5bTOj0"}' \
  | python3 -c "import sys,json; print(json.load(sys.stdin)['access_token'])")
```

| # | Action | Expected |
|---|---|---|
| 1 | `curl -X POST -H "Authorization: Bearer $TOKEN" "https://defynwp.defyn.agency/wp-json/defyn/v1/overview/sync-all"` | 202 + `{scheduled_count: 1, site_ids: [1], scheduled_at: "..."}` for SmartCoding |
| 2 | Same POST WITHOUT `Authorization` header | 401 `auth.missing_token` or `auth.required` |
| 3 | 11× POST from same user within 1 hour | 11th returns 429 `overview.rate_limited` |
| 4 | `curl -H "Authorization: Bearer $TOKEN" "https://defynwp.defyn.agency/wp-json/defyn/v1/activity?per_page=5"` after step 1 | `overview.sync_all_requested` event present with `details: {scheduled_count: 1, site_ids: [1]}` and `site_id: null` |
| 5 | SPA at `/overview` → click "Sync all sites" → confirm dialog → click "Sync all 1 sites" | Spinner briefly, then revert. Within ~60-90s the activity widget shows `overview.sync_all_requested` + downstream `site.synced` / `plugin_inventory.synced` triplet for SmartCoding |
| 6 | SPA: same flow but press Cancel on the dialog | Dialog closes, no POST fires, no new activity event |

### 5.3 Cleanup

None. Bulk sync is a read-side fan-out — SmartCoding's `last_sync_at` advances naturally and no synthetic state was introduced. The rate-limit transient expires on its own within an hour.

### 5.4 Tag + push

```
git tag -a p2-6-sync-all-sites-complete -m "P2.6 — Sync all sites bulk action shipped"
git push origin p2-6-sync-all-sites-complete
```

Push only after all 6 smoke steps green.

---

## §6. Out of scope (deferred)

| Deferred | What |
|---|---|
| **P2.7 (next phase)** | "Update all minor plugins across fleet" — needs confirmation dialog with X-plugins-on-Y-sites preview, partial-failure handling, progress UI, optional per-plugin exclusion list |
| **P2.7** | Filtered drill-in views (`/overview/plugins` route with per-row Update buttons) — replaces today's `/sites?filter=` reuse |
| **Future** | Per-user configurable attention thresholds (SSL grace days, offline minutes, sync-staleness hours) |
| **Future** | "Sync these specific sites" multi-select on overview — currently it's all-or-nothing |
| **Future** | Cancel an in-flight bulk sync — currently fire-and-forget |
| **Future** | Live per-site progress widget — currently the activity feed at 60s polling IS the progress UI |
| **Future** | "Sync all sites" button on `/sites` route too — currently only on `/overview` |

---

## §7. Plan-author notes (carry-overs for writing-plans)

**Branch off `p2-5-overview-dashboard`** (current tip `38fbff7`). Branch name: `p2-6-sync-all-sites`.

**Plan-bug traps to internalise:**

1. **`RateLimit::OVERVIEW_SYNC_ALL_LIMIT = 10`** per HOUR. Window `HOUR_IN_SECONDS`. Test method MUST be `testRateLimit429AfterEleventhCall`. Same shape as `coreAllowMajor` from P2.4.1, NOT the per-minute `overview` bucket.

2. **Activity event name MUST be EXACTLY** `overview.sync_all_requested`. Not `site.bulk_sync_requested`, not `overview.bulk_sync`, not `overview.sync_all`. Exact string. Smoke step 4 + test `testActivityEventEmittedWithCorrectDetails` assert this.

3. **`site_id` on the activity event is `null`** (fleet-scoped). `site_ids[]` goes inside `details` (JSON column). `ActivityLogger::log(?int $userId, ?int $siteId, string $eventType, ?array $details = null, ?string $ipAddress = null): void` — pass null as second arg.

4. **No `OverviewSyncAllService` extension.** The controller is thin enough to inline the fan-out loop. Don't over-engineer. Compare scope to `SitesPingController` for reference.

5. **`/overview` response gains a `total_sites: int` field.** Additive Zod extension on `overviewSchema`. Backend computes via `SitesRepository::countAllForUser(int $userId)` — ONE new count method.

6. **Confirm dialog primary button is NEUTRAL color** (`bg-foreground text-background` / shadcn default Button variant). NOT red. This is a read-side action, not a destructive write. Plan-bug trap from P2.4.1 (where the red `bg-red-600` was correct for an actually-destructive major upgrade).

7. **Cancel button has default focus** (same convention as P2.4 ConfirmUpdateCoreDialog). Test `cancelHasDefaultFocus` asserts this.

8. **Mutation hook invalidates `['overview']`** on success. Don't invalidate `['sites']` — per-site state refreshes naturally as `SyncSite` executes per-site.

9. **`as_schedule_single_action(time(), 'defyn_sync_site', [$siteId])`** — the existing P2.1 pattern. Don't introduce a new hook name. The test asserts via `as_get_scheduled_actions(['hook' => 'defyn_sync_site'])`.

10. **Dashboard zip build:** `composer install --no-dev --classmap-authoritative` FIRST (NOT just `dump-autoload`). Exclude `vendor/wordpress/*` + `vendor/johnpbloch/*` + dev packages. Target ~552KB.

11. **MyKinsta cache clear** after install (carry-forward from every P2.x phase). Without it the new route may 404 for hours.

12. **Final smoke matrix § 5.2 verbatim — 6 steps.** Tag only after all 6 pass.

**Estimated plan size:** **~7 TDD tasks** across 3 phases:

- Phase A — Dashboard (4 tasks): `SitesRepository::countAllForUser` + `OverviewService.total_sites`; `OverviewSyncAllController` + `RateLimit::overviewSyncAll` + route registration; dashboard v0.7.1 release bump + CORS regression.
- Phase B — SPA (2-3 tasks): Zod extensions + MSW + `useSyncAllSites`; `SyncAllSitesButton` + `ConfirmSyncAllDialog` + `Overview.tsx` integration.
- Phase C — Ship (1 task — combined): build zip + build SPA + push main + 6-step smoke + tag.

Tight scope; mirrors P2.5's pattern at smaller scale.

---

## §8. Acceptance criteria

P2.6 is shipped when:

- [ ] ~12 new tests green in CI (~7 PHP + ~5 SPA)
- [ ] Dashboard v0.7.1 zip built per § 5.1 discipline
- [ ] Production install via "Replace current with uploaded version" succeeds; MyKinsta cache cleared
- [ ] SPA built via `pnpm build` + pushed to main → Cloudflare auto-deploys
- [ ] Smoke matrix § 5.2 steps 1-6 all green
- [ ] Tag `p2-6-sync-all-sites-complete` pushed
- [ ] MEMORY.md updated with any plan-bug lessons surfaced during execution
