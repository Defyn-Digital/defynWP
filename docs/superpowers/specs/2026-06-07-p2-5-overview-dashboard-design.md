# P2.5 — Operator Overview Dashboard (Design Spec)

**Date:** 2026-06-07
**Status:** Approved (brainstorming complete §1→§10)
**Predecessor:** P2.4.1 (major-version core updates), tag `p2-4-1-major-core-updates-complete`, commit `2e9e8b3`. Connector v0.1.7 + dashboard v0.6.0 + SPA red-tier major-update UI live in prod.
**Successor:** P2.6 — Bulk actions on overview (deferred)
**Spec scope:** Introduce a cross-site aggregate dashboard at `/overview` so an operator managing 10+ WordPress sites can see at a glance what needs their attention without drilling site-by-site. Read-only MVP. Bulk actions deferred.

---

## §1. Architecture overview

A new `/overview` route in the SPA becomes the post-login landing page. It renders three widgets in **Layout A: hero strip + bottom split** — three big-number count cards across the top, then two equal-width panels below for sites-needing-attention and recent-activity.

| Widget | Source |
|---|---|
| **Pending updates summary** (3 big numbers) | `pending_updates: {plugins, themes, cores_minor, cores_major, sites_with_any_update}` |
| **Sites needing attention** (list with reason chips) | `sites_needing_attention: [{site_id, url, label, reasons[], last_contact_at, ssl_expires_at}]` |
| **Recent activity** (last 25 cross-site events) | `recent_activity: [{id, site_id, site_label, event_type, details, created_at}]` |

**Dashboard side:**
- ONE new REST endpoint `GET /defyn/v1/overview` (per-minute rate limited).
- ONE new `Services/OverviewService.php` composes the response.
- ONE new `Rest/OverviewController.php` wraps auth + JSON.
- `Services/SitesRepository.php` gains aggregate-count + attention-list methods.
- `Services/ActivityLogRepository.php` gains a `tailForUser()` method if not already present.

**Aggregation strategy: live SQL per request.** 5 indexed SELECT queries against existing tables (`wp_defyn_sites`, `wp_defyn_site_plugins`, `wp_defyn_site_themes`, `wp_defyn_activity_log`). No new schema, no cache invalidation. Expected ~50ms total at 50 sites × 500 plugins each. WP transient caching deliberately rejected (already have 60s SPA refetch; stacking caches confuses debugging).

**SPA side:**
- ONE new TanStack hook `useOverview()` polling every 60s while tab active.
- THREE new presentational components for the widgets + ONE chip helper.
- ONE new `/overview` route. Top nav extended with "Overview" link.
- After login redirect target switches from `/sites` to `/overview` (or to `/sites/new` if zero sites connected).
- Count card click navigates to `/sites?filter=has-plugin-updates|has-theme-updates|has-core-update` — ONE additive query-string filter added to existing `SitesList`.

**Schema: stays at v6.** No new tables, no new columns. P2.5 is pure read-side aggregation.

---

## §2. Schema — stays at v6

No new tables, no new columns. The five SQL queries that power `/overview` use existing surfaces:

| Query | Tables | Existing indexes used |
|---|---|---|
| Pending plugin updates count | `wp_defyn_site_plugins JOIN wp_defyn_sites` (user filter) | `idx_update_available` (P2.1) + `idx_site_id` |
| Pending theme updates count | `wp_defyn_site_themes JOIN wp_defyn_sites` | `idx_update_available` (P2.3) + `idx_site_id` |
| Pending core updates count | `wp_defyn_sites` only | `idx_core_update_available` (P2.4) |
| Sites needing attention | `wp_defyn_sites` + 2 sub-aggregates for `update_state='failed'` | existing site indexes |
| Recent activity tail (last 25) | `wp_defyn_activity_log JOIN wp_defyn_sites` (user filter via site ownership) | `idx_activity_site_created_at` (F9) |

**Index strategy for activity-log JOIN:** the user-ownership filter MUST be expressed as an `EXISTS` or subquery so MySQL leverages `idx_activity_site_created_at`. A plain `LEFT JOIN` would force a full-scan plan at scale. Plan-author trap #2.

`SCHEMA_VERSION` stays at `6`. No migration test needed.

---

## §3. Dashboard REST — new `/overview` endpoint

### 3.1 Route

```
GET /defyn/v1/overview
```

- **Auth:** Bearer JWT (same as `/sites`).
- **Rate limit:** `RateLimit::overview` — **30/minute** per user. Window = `MINUTE_IN_SECONDS`. First per-minute bucket in the project — all prior buckets are per-hour. Plan-author trap #1.
- **Cache headers:** `Cache-Control: no-store` inherited from `RestRouter::applyNoCacheHeaders`.

### 3.2 Response shape

```json
{
  "pending_updates": {
    "plugins": 47,
    "themes": 3,
    "cores_minor": 1,
    "cores_major": 0,
    "sites_with_any_update": 9
  },
  "sites_needing_attention": [
    {
      "site_id": 1,
      "url": "https://smartcoding.com.au",
      "label": "SmartCoding",
      "reasons": ["sync_stale", "ssl_expiring"],
      "last_contact_at": "2026-06-07 09:30:00",
      "ssl_expires_at": "2026-06-25 00:00:00"
    }
  ],
  "recent_activity": [
    {
      "id": 12345,
      "site_id": 1,
      "site_label": "SmartCoding",
      "event_type": "plugin_update.succeeded",
      "details": { "slug": "akismet", "from": "5.7", "to": "5.8" },
      "created_at": "2026-06-07 10:44:05"
    }
  ],
  "generated_at": "2026-06-07 11:30:00"
}
```

**Notes:**
- Cores split into `cores_minor` and `cores_major` because UI handles them differently (major requires per-site `core_allow_major` flag from P2.4.1).
- `generated_at` is REQUIRED — SPA displays "Last refreshed: 2 minutes ago" from this server-side timestamp. The hook computes relative-time from this field, NOT from React Query's `dataUpdatedAt`. Plan-author trap #9.
- `sites_needing_attention` is capped at **50 rows** (LIMIT 50). At scale this list is meant for triage, not exhaustive enumeration.
- `recent_activity` is capped at **25 events** (LIMIT 25 ORDER BY created_at DESC).

### 3.3 File structure

| File | Responsibility |
|---|---|
| `src/Rest/OverviewController.php` (new) | Auth check, build response from service, emit JSON |
| `src/Services/OverviewService.php` (new) | Compose the 3-section response by orchestrating SitesRepository + ActivityLogRepository |
| `src/Services/SitesRepository.php` (extend) | Add `countPendingPlugins/Themes/Cores(int $userId): array`, `findSitesNeedingAttention(int $userId): array` |
| `src/Services/ActivityLogRepository.php` (extend) | Add `tailForUser(int $userId, int $limit = 25): array` |
| `src/Rest/Middleware/RateLimit.php` (extend) | Add `OVERVIEW_LIMIT = 30` + `OVERVIEW_WINDOW = MINUTE_IN_SECONDS` + `overview()` static method |
| `src/Rest/RestRouter.php` (extend) | Register `/overview` route |

### 3.4 Attention criteria — hardcoded thresholds

| Reason | SQL condition | Threshold |
|---|---|---|
| `offline` | `last_contact_at < NOW() - INTERVAL 15 MINUTE` | 15 minutes |
| `failed_update` | EXISTS row in `_site_plugins` OR `_site_themes` with `update_state='failed'` OR `_sites.core_update_state='failed'` | n/a |
| `ssl_expiring` | `ssl_expires_at < NOW() + INTERVAL 30 DAY` | 30 days |
| `sync_stale` | `last_sync_at < NOW() - INTERVAL 24 HOUR` | 24 hours |

Per-user configurable thresholds are deferred to P2.6. Plan-author trap #4.

### 3.5 `SitesList` filter extension

`useSites()` query hook extends with an optional `filter` parameter. When the URL has `?filter=has-plugin-updates`, the dashboard's `findAllForUser` filters to sites where at least one row in `wp_defyn_site_plugins` has `update_available=1`. Same shape for `has-theme-updates` and `has-core-update`. Backward-compat: no filter = today's behavior unchanged.

The filter is parsed from URL state, NOT local component state, so deep-linking and back-button navigation work. Plan-author trap #6 + #7.

### 3.6 Dashboard tests (~12 PHP tests)

- `OverviewControllerTest`:
  - `testAuthRequiredReturns401WhenNoBearerToken`
  - `testHappyPath200WithFullEnvelopeShape`
  - `testRateLimit429AfterThirtyFirstCall` (30/minute bucket)
  - `testOwnershipScopingExcludesOtherUsersSites`
  - `testNoStoreCacheHeader`
- `OverviewServiceTest`:
  - `testOfflineReasonOnlyForSitesPast15MinThreshold`
  - `testFailedUpdateReasonForAnyPluginThemeOrCoreFailure`
  - `testSslExpiringReasonForCertsWithin30Days`
  - `testSyncStaleReasonForSitesPast24HrThreshold`
  - `testMultipleReasonsCombinedInSameSiteRow`
- `SitesRepositoryOverviewTest`:
  - `testCountPendingPluginsReturnsCorrectCount`
  - `testFindSitesNeedingAttentionTruncatesAtFifty`
- `ActivityLogRepositoryOverviewTest`:
  - `testTailForUserReturnsTwentyFiveOrderedByCreatedAtDesc`

### 3.7 Version + readme

- `defyn-dashboard.php`: `Version: 0.6.0` → `Version: 0.7.0`
- `readme.txt`: stable tag bump + changelog entry

```
= 0.7.0 =
* Operator overview dashboard via GET /defyn/v1/overview — pending updates summary, sites needing attention, recent activity feed.
* SitesList gains ?filter=has-plugin-updates|has-theme-updates|has-core-update query-string filter.
```

---

## §4. SPA UI (apps/web) — Layout A: hero strip + bottom split

### 4.1 New route + nav

`/overview` becomes the default post-login landing. The current `LoginPage` redirects to `/sites` after successful auth — change target to `/overview`.

Top nav (already exists as thin chrome above SiteDetail/SitesList) gains a new link: **Overview · Sites · Activity**. Currently just "Sites" + "Activity" implied. Overview link is the active state on `/overview`, Sites highlights on `/sites/*`, Activity highlights on `/activity`.

### 4.2 File structure

| Path | Responsibility |
|---|---|
| `src/routes/Overview.tsx` (new) | Top-level route component. Renders 3 widgets in Layout A grid (`grid-cols-3 gap-4` at `md:` and above, stacked on mobile). Calls `useOverview()`. |
| `src/components/overview/PendingUpdatesWidget.tsx` (new) | Three big-number count cards (plugins/themes/cores). Click navigates to filtered Sites view. |
| `src/components/overview/SitesNeedingAttentionWidget.tsx` (new) | Left-bottom panel — list of sites with reason chips. Rows link to `/sites/{id}`. |
| `src/components/overview/RecentActivityWidget.tsx` (new) | Right-bottom panel — last 25 cross-site events. Rows link to site detail when applicable. |
| `src/components/overview/AttentionReasonChip.tsx` (new) | Reusable chip renderer. Red for `offline` / `failed_update`, amber for `ssl_expiring` / `sync_stale`. Plan-author trap #8. |
| `src/lib/queries/useOverview.ts` (new) | `useQuery({queryKey: ['overview'], queryFn, refetchInterval: 60_000, refetchIntervalInBackground: false})` |
| `src/types/api.ts` (extend) | Add `overviewSchema` Zod mirroring REST shape |
| `src/test/handlers.ts` (extend) | Add MSW handler for `GET /overview` with realistic synthetic payload |
| `src/routes/SitesList.tsx` (extend) | Parse `?filter=` query string, pass to `useSites({filter})` |
| `src/lib/queries/useSites.ts` (extend) | Accept optional `filter` param, append to API call as query string |

### 4.3 Empty + loading + error states

- **Loading:** skeleton hero (3 grey blocks for counts) + 2 grey panel blocks below. Tailwind `animate-pulse`.
- **Empty (zero sites connected):** Overview redirects to `/sites/new` (existing add-site flow). Plan-author trap #5 — do NOT render "0 of 0" everywhere.
- **All zeros (sites exist, nothing pending, no attention):** counts show `0`, "Sites needing attention" widget shows "All sites healthy ✓", activity widget still shows last events even if no actionable items.
- **API error (network / 401 / 500):** standard error toast + "Try again" button in place of widgets. JWT expiry redirects to login (handled by existing `apiClient`).

### 4.4 Visual details

- Count cards use existing Tailwind `Card` primitive. Big number `text-3xl font-bold`, label `text-xs uppercase text-muted-foreground tracking-wide`, sub-line `text-xs text-muted-foreground`.
- Each count card is a clickable `<Link>` to `/sites?filter=has-plugin-updates` (and equivalents). For P2.5 NO filtered drill-in views like `/overview/plugins` — that's P2.6.
- Attention rows: full row is a `<Link>` to `/sites/{id}`. Chips render via `AttentionReasonChip` with `reason` prop.
- Activity rows: link to `/sites/{id}` when the event has a `site_id`. Events without a site (e.g. login — not in scope) render without a link.
- "Last refreshed: X ago" timestamp at top-right of the Overview page, computed from `generated_at` server field, NOT React Query's `dataUpdatedAt`. Plan-author trap #9.

### 4.5 SPA tests (~12 Vitest tests)

- `Overview.test.tsx`:
  - `rendersAllThreeWidgetsWhenMSWReturnsCanonicalPayload`
  - `redirectsToSitesNewWhenZeroSitesConnected`
  - `rendersErrorStateWhenMSWReturns500`
- `PendingUpdatesWidget.test.tsx`:
  - `rendersThreeCountCardsWithCorrectNumbers`
  - `clickingPluginCardNavigatesToFilteredSitesView`
- `SitesNeedingAttentionWidget.test.tsx`:
  - `rendersOneRowPerSiteWithChipsForEachReason`
  - `rendersAllHealthyMessageWhenListIsEmpty`
  - `rowClickNavigatesToSiteDetail`
- `RecentActivityWidget.test.tsx`:
  - `rendersEventsInReverseChronologicalOrder`
  - `rendersTwentyFiveEventsMax`
- `useOverview.test.tsx`:
  - `validatesResponseAgainstZodSchema`
  - `refetchesAtSixtySecondInterval`
- `AttentionReasonChip.test.tsx`:
  - `redPaletteForOfflineAndFailedUpdate`
  - `amberPaletteForSslExpiringAndSyncStale`

### 4.6 Routing edge cases

- Zero sites connected → redirect to `/sites/new` on login (skip `/overview`).
- 1+ sites but all healthy → render Overview with counts at 0 + "All sites healthy ✓" panel + activity feed.
- Logged-out user navigating to `/overview` → redirect to `/login?next=/overview` (existing auth-guard behavior).

---

## §5. Testing strategy

Total: ~24 new tests. Listed inline in §3.6 + §4.5.

**Coverage gate:** ≥80% on new modules. CI auto-discovers.

**What we explicitly do NOT test for P2.5:**
- The filtered SitesList view (`?filter=has-plugin-updates`) integration — covered by smoke step 8 only since it's an additive query-string read on an existing route
- The "all zeros" healthy-state branch — covered by a single component test (`rendersAllHealthyMessageWhenListIsEmpty`), not a full integration
- Activity-feed live updates (WebSocket / SSE) — out of scope
- Per-user threshold customization — out of scope (P2.6)

---

## §6. Manual smoke flow

Run after CI green, before tagging `p2-5-overview-dashboard-complete`.

### 6.1 Pre-smoke setup

1. Build dashboard zip (same lessons from P2.4.1):
   ```
   cd packages/dashboard-plugin
   composer install --no-dev --classmap-authoritative
   zip -rq ~/Desktop/defyn-dashboard-v0.7.0-$(date +%Y-%m-%d).zip . \
     -x "tests/*" "node_modules/*" "*.git*" "phpunit.xml*" "*.lock"
   composer install
   ```
   Target zip size: ~570KB.
2. Install on production via "Replace current with uploaded version" on `defynwp.defyn.agency`.
3. **Clear MyKinsta cache** (Tools → Clear cache) — busts OPcache + Redis. Without this the new `/overview` route may 404 even though the file is in place. Plan-author trap #12.
4. Build SPA: `cd apps/web && pnpm build`. Push to main → Cloudflare auto-deploys.

### 6.2 Smoke matrix — 8 steps

```bash
TOKEN=$(curl -s -X POST https://defynwp.defyn.agency/wp-json/defyn/v1/auth/login \
  -H "Content-Type: application/json" \
  --data '{"email":"pradeep@defyn.com.au","password":"DefynWP-ifirCh5pXm5bTOj0"}' \
  | python3 -c "import sys,json; print(json.load(sys.stdin)['access_token'])")
```

| # | Action | Expected |
|---|---|---|
| 1 | `curl -H "Authorization: Bearer $TOKEN" "https://defynwp.defyn.agency/wp-json/defyn/v1/overview"` | 200 + full envelope shape (pending_updates, sites_needing_attention, recent_activity, generated_at). All three sub-objects present even if empty. |
| 2 | Same call WITHOUT `Authorization` header | 401 `auth.required` |
| 3 | 31× same call from same user in 1 minute | 31st returns 429 `overview.rate_limited` |
| 4 | SPA: log out, log in fresh | Lands on `/overview`, NOT `/sites`. Top nav shows Overview as active. |
| 5 | SPA at `/overview` with current state | Counts render (likely 0 0 0), attention list either empty + "All sites healthy ✓" or shows SmartCoding if it has lingering state. Activity feed shows the last 25 events. |
| 6 | Synthetic inject: `UPDATE wp_defyn_sites SET last_contact_at = '2025-01-01 00:00:00' WHERE id = 1;` then refresh SPA | "Sites needing attention" widget shows SmartCoding row with `offline` chip (red). |
| 7 | Trigger a plugin update from `/sites/1` (existing P2.2 flow), wait ~30s, navigate back to `/overview` | Activity widget shows the new `plugin_update.requested → started → succeeded\|failed` triplet at the top of the list (most recent). |
| 8 | Click the "Plugin updates" count card on `/overview` | Navigates to `/sites?filter=has-plugin-updates`. Sites list filters to only sites where update_available=1 on any plugin. |

### 6.3 Cleanup

```sql
UPDATE wp_defyn_sites
SET last_contact_at = NOW()
WHERE id = 1;
```

Restores SmartCoding to "online" so step 6's synthetic injection doesn't leave a phantom offline state.

### 6.4 Tag + push

```
git tag -a p2-5-overview-dashboard-complete -m "P2.5 — Operator overview dashboard shipped"
git push origin p2-5-overview-dashboard-complete
```

Push only after all 8 smoke steps + cleanup pass.

---

## §7. Deliberately out of scope (deferred)

| Deferred to | What's NOT in P2.5 |
|---|---|
| **P2.6 (next phase)** | Bulk actions — "Update all minor plugins across fleet", "Sync all sites now" buttons on overview |
| **P2.6** | Filtered drill-in views (`/overview/plugins`, etc.) — count cards link to existing SitesList with query-string filter for now |
| **P2.6** | Per-user configurable attention thresholds (SSL grace days, offline threshold, sync-stale window) |
| **Future** | WebSocket / SSE live updates — 60s TanStack refetch is sufficient for MVP |
| **Future** | Activity-feed pagination inside the widget — capped at 25; full feed remains at `/activity` |
| **Future** | Multi-operator support (assignment, mentions, role-based filtering) — single-operator assumed for now |
| **Future** | Push notifications / digest emails when sites enter attention list — needs a notification subsystem |
| **Future** | Aggregate cache table (schema v7) — live SQL is fast enough at current scale; revisit if measured >200ms |
| **Future** | "Snooze attention reason" — operator dismisses a reason for N days |

---

## §8. Plan-author notes (carry-overs for writing-plans)

**Branch off `p2-4-1-major-core-updates`** (current tip `2e9e8b3`). Main was fast-forwarded to that same commit during P2.4.1 ship, so either base is equivalent. Branch name: `p2-5-overview-dashboard`.

**Plan-bug traps to brief implementers about up front** (learned from P2.1→P2.4.1 execution):

1. **`RateLimit::OVERVIEW_LIMIT = 30` PER MINUTE** — NOT per hour. Window: `MINUTE_IN_SECONDS`. Test method MUST be `testRateLimit429AfterThirtyFirstCall`. First per-minute bucket in the project.

2. **Activity-log JOIN uses `idx_activity_site_created_at`** — express user-ownership filter as EXISTS or sub-query, NOT plain LEFT JOIN, or MySQL falls back to full-scan.

3. **Layout A grid responsive collapse** — `grid-cols-3 gap-4` at `md:` and above. Below `md:` stacks vertically (mobile behavior).

4. **Attention thresholds hardcoded** — 15 min offline, 30 day SSL, 24 hr sync-stale. Tests assert exact thresholds. Per-user config is P2.6.

5. **Empty-state guard** — zero sites → redirect to `/sites/new`, NOT render "0 of 0".

6. **Count cards click target** — `/sites?filter=has-plugin-updates|has-theme-updates|has-core-update`. URL query param parsed in component mount.

7. **`SitesList` filter parsing** — URL state, NOT local component state. Back-button navigates correctly. Filter is OPTIONAL on `useSites()` — no filter = existing behavior.

8. **`AttentionReasonChip` palette** — red for `offline` / `failed_update`, amber for `ssl_expiring` / `sync_stale`. Test asserts exact class names (`bg-red-100 text-red-800` / `bg-amber-100 text-amber-800` or whatever shadcn convention the codebase uses).

9. **`generated_at` is server-side timestamp** — SPA "Last refreshed" computes from this field, NOT React Query's `dataUpdatedAt`. Both should be in sync but server is canonical for tests.

10. **No connector changes.** Connector stays at v0.1.7. Smoke does NOT require connector reinstall.

11. **Dashboard zip build:** `composer install --no-dev --classmap-authoritative` FIRST (NOT just `dump-autoload`). Exclude `vendor/wordpress/*` + `vendor/johnpbloch/*` + dev packages. Target ~570KB.

12. **OPcache + Redis cache discipline:** after dashboard plugin replace on Kinsta, hit MyKinsta "Clear cache". Without it, new routes may 404 / behave on stale code for ~hours.

13. **Final smoke matrix is § 6.2 verbatim — 8 steps.** Tag only after all 8 pass + § 6.3 cleanup applied.

**Estimated plan size:** ~12 TDD tasks across 4 phases (Dashboard REST + service + repository, Dashboard release bump + CORS, SPA Zod + hook + widgets, SPA route + nav + SitesList filter, Ship). Mirrors P2.4.1's pattern at similar scale.

---

## §9. Acceptance criteria (recap)

P2.5 is shipped when:

- [ ] ~24 new tests green in CI (~12 PHP + ~12 SPA)
- [ ] Dashboard v0.7.0 zip built per § 6.1 discipline
- [ ] Production install via "Replace current" succeeds; MyKinsta cache cleared
- [ ] SPA built via `pnpm build` + pushed to main → Cloudflare auto-deploys
- [ ] Smoke matrix § 6.2 steps 1-8 all green
- [ ] Cleanup § 6.3 applied
- [ ] Tag `p2-5-overview-dashboard-complete` pushed
- [ ] MEMORY.md updated with any plan-bug lessons surfaced during execution
