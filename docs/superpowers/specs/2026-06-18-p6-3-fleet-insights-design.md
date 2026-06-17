# P6.3 — Fleet Insights (`/insights`) Design

**Date:** 2026-06-18
**Status:** Approved (design)
**Phase:** P6.3 — post-roadmap, operator's choice. Closes the Phase-6 (Analytics & Performance) arc at the fleet level: P6.1 (PageSpeed) and P6.2 (GA4) added per-site panels + per-site report sections; P6.3 adds the cross-fleet rollup view.
**Dashboard:** v0.22.0 → **v0.23.0**. **Schema unchanged (v16). Connector unchanged (v0.1.7).**

---

## 1. Goal

A single read-only **`/insights`** SPA page that surfaces **Performance** (PageSpeed) and **Analytics** (GA4) across the operator's whole fleet, worst-first, with each table row drilling into that site's detail page. It is a pure read over the weekly snapshots P6.1/P6.2 already collect — **no new DB tables/columns, no new background jobs, no connector change, no report/PDF change.**

This mirrors the existing P4.2 `/security` fleet page 1:1 in structure (summary strip on top + per-site table below, scoped to the authenticated operator) but reads the performance + analytics snapshot tables instead of vulnerabilities.

---

## 2. Decisions (locked during brainstorm)

1. **One combined page `/insights`**, not two separate `/performance` + `/analytics` pages. One nav link, one round-trip, smallest SPA surface. Both lenses stacked as two sections on one page.
2. **Read-only.** The page displays the latest snapshots already gathered by the existing weekly `PerformanceScanAll` / `AnalyticsSyncAll` jobs. **No "Refresh-all" / "Measure-all" button** (per-site "Measure now" / "Refresh analytics" already exist on Site detail). This deliberately diverges from `/security`, which has a `scan-all` button.
3. **Worst-first triage sort.** Performance: lowest mobile score on top, never-measured sites last. Analytics: lowest sessions on top among connected sites, not-connected / no-data sites last. Columns remain client-sortable in the SPA; the default order is set by the backend.
4. **One endpoint** `GET /insights` returning both datasets, composed by one `InsightsService` — not two endpoints.

---

## 3. Backend architecture

### 3.1 `InsightsService::compose(int $userId): array`

New `Defyn\Dashboard\Services\InsightsService`. Constructor takes the two repositories (injectable for tests, instantiated by default — mirror `SecurityService`'s ctor seam). Returns a **direct payload (no `{data}` envelope)**, matching `/security`:

```
[
  'performance' => [
    'summary' => [
      'total_sites'  => int,
      'measured'     => int,   // sites with ≥1 performance snapshot
      'avg_mobile'   => ?int,  // mean mobile_score over measured sites, null if none
      'avg_desktop'  => ?int,
      'slow_sites'   => int,   // measured sites with mobile_score < 50
    ],
    'sites' => [ /* PerformanceFleetRow[] (see 3.2) */ ],
  ],
  'analytics' => [
    'summary' => [
      'total_sites'    => int,
      'connected'      => int,  // sites with a non-empty ga4_property_id
      'total_sessions' => int,  // sum of sessions over sites with a snapshot
      'total_users'    => int,
    ],
    'sites' => [ /* AnalyticsFleetRow[] (see 3.3) */ ],
  ],
  'generated_at' => string,     // gmdate('c')
]
```

Summaries and ordering are computed in PHP from the repository rows. The service is the only place that knows the worst-first ordering and the `slow_sites`/`connected` thresholds.

**`SLOW_SCORE_THRESHOLD = 50`** is a named constant on the service (a mobile PSI score below 50 = "slow site needing attention"; consistent with the PSI "poor" band 0–49).

### 3.2 `SitePerformanceRepository::findFleetForUser(int $userId): array`

New method (the repo is currently per-site only). Returns one row per owned site — the **latest** performance snapshot per site, or nulls when never measured.

```
list<array{
  site_id:int, label:string, url:string,
  mobile_score:?int, desktop_score:?int, mobile_lcp_ms:?int,
  fetched_at:?string
}>
```

SQL shape — `wp_defyn_sites` LEFT JOIN the latest performance row per site, selecting the row by a correlated `id` subquery (greatest-n-per-group; the subquery uses `idx_perf_site_fetched (site_id, fetched_at)`):

```sql
SELECT s.id AS site_id, s.label AS label, s.url AS url,
       p.mobile_score, p.desktop_score, p.mobile_lcp_ms, p.fetched_at
  FROM {sites} s
  LEFT JOIN {perf} p
    ON p.site_id = s.id
   AND p.id = (
       SELECT p2.id FROM {perf} p2
        WHERE p2.site_id = s.id
        ORDER BY p2.fetched_at DESC, p2.id DESC
        LIMIT 1
   )
 WHERE s.user_id = %d
 ORDER BY s.id ASC
```

**`ONLY_FULL_GROUP_BY`-safe** — there is no `GROUP BY`, so the non-aggregated `p.*` columns are valid (this is the trap the security rollup sidestepped by selecting only aggregates). The correlated subquery returns **exactly one** `id` per site (the `ORDER BY fetched_at DESC, id DESC LIMIT 1` makes it deterministic even on a duplicate-`fetched_at` tie). Chosen over a derived `MAX(fetched_at)` join (which would force a `GROUP BY` and reintroduce the full-group-by violation) and a PHP-side two-query merge (keeps it one query). Ownership scoped `WHERE s.user_id = %d`, mirroring `SiteVulnerabilitiesRepository::findFleetSummariesForUser`. **Service** does the worst-first ordering (measured sites by `mobile_score` ASC, never-measured last) — keeps SQL simple and ordering testable in isolation.

### 3.3 `SiteAnalyticsRepository::findFleetForUser(int $userId): array`

New method. Returns one row per owned site — the **latest** analytics snapshot per site (most recent `period_start`), plus `ga4_property_id` so the UI distinguishes "not connected" (no property) from "connected, no data yet" (property set, no snapshot).

```
list<array{
  site_id:int, label:string, url:string, ga4_property_id:?string,
  sessions:?int, total_users:?int, screen_page_views:?int,
  avg_session_duration:?float, period_start:?string, period_end:?string,
  fetched_at:?string
}>
```

SQL shape — same correlated-`id` greatest-n-per-group (the subquery uses `idx_analytics_site_period (site_id, period_start)`):

```sql
SELECT s.id AS site_id, s.label, s.url, s.ga4_property_id,
       a.sessions, a.total_users, a.screen_page_views, a.avg_session_duration,
       a.period_start, a.period_end, a.fetched_at
  FROM {sites} s
  LEFT JOIN {analytics} a
    ON a.site_id = s.id
   AND a.id = (
       SELECT a2.id FROM {analytics} a2
        WHERE a2.site_id = s.id
        ORDER BY a2.period_start DESC, a2.id DESC
        LIMIT 1
   )
 WHERE s.user_id = %d
 ORDER BY s.id ASC
```

Same `ONLY_FULL_GROUP_BY`-safe correlated form as 3.2 (latest snapshot = most recent `period_start`, `id DESC` tiebreaker). **Service** ordering: connected-with-data by `sessions` ASC (worst first), then sites with a property but no snapshot, then not-connected sites last.

### 3.4 REST + plumbing

- **`Rest\InsightsController::handle(WP_REST_Request): WP_REST_Response`** — resolves `$userId` from `_authenticated_user_id`, calls `InsightsService::compose($userId)`, returns the direct payload. Ownership is implicit (both queries scope to the authenticated user; there is no `{id}` path param, so no per-resource 404).
- **`RateLimit::insights`** — clone of `RateLimit::security`: **30/min** per-user, key `defyn_rl_insights_%d`, constants `INSIGHTS_LIMIT = 30` / `INSIGHTS_WINDOW = MINUTE_IN_SECONDS`, error code `insights.rate_limited` (429).
- **Route registration** in `RestRouter` (two-call `register_rest_route` form): `GET /defyn/v1/insights` → `[new InsightsController(), 'handle']`, `permission_callback => [RateLimit::class, 'insights']`. Plus the OPTIONS/CORS preflight registration the other routes use.
- **`defyn-dashboard.php`** version `0.22.0 → 0.23.0` (header line 6 + `DEFYN_DASHBOARD_VERSION` line 46). **No `Activation::SCHEMA_VERSION` change.**

---

## 4. SPA

- **`routes/Insights.tsx`** — structure mirrors `routes/Security.tsx`: `max-w-5xl` container, header with title + `← Overview` link, loading / error / `total_sites === 0` empty states, then two stacked sections (Performance, Analytics), each = a summary strip + a fleet table inside a bordered card.
- **Components** under `components/insights/`:
  - `InsightsPerformanceStrip` (4 tiles: avg mobile, avg desktop, slow sites, measured/total)
  - `InsightsPerformanceTable` (cols: Site · Mobile · Desktop · LCP(m) · Measured; "Not yet measured" row when `fetched_at === null`)
  - `InsightsAnalyticsStrip` (3 tiles: total sessions, total users, connected/total)
  - `InsightsAnalyticsTable` (cols: Site · Sessions · Users · Views · Avg engmt · Period; "Not connected — add a GA4 Property ID" row when `ga4_property_id` is null/empty; "Connected — no data yet" when property set but `fetched_at === null`)
  - Each table row is a click-through (`navigate('/sites/:id')`).
- **Helpers:** reuse existing `lib/coreWebVitals.ts` `rateCwv('lcp', value)` for LCP coloring and `lib/engagement.ts` `formatEngagement(seconds)` for avg engagement. Add a small pure `lib/psiBand.ts` `psiBand(score: number | null): 'good' | 'needs-improvement' | 'poor' | 'unknown'` (0–49 poor / 50–89 needs-improvement / 90–100 good) for the colored score chips.
- **Data:** `useInsights()` query hook (`queryKey: ['insights']`, `staleTime: 30_000`); Zod `insightsSchema` in `types/api.ts` (with `performanceFleetRowSchema` + `analyticsFleetRowSchema`, all metric fields nullable); MSW handler for `/insights`.
- **Nav:** `components/nav/InsightsNavLink.tsx` (mirror `SecurityNavLink`), added to `routes/Overview.tsx` alongside `SecurityNavLink`; `/insights` route added to `App.tsx` inside the authenticated outlet.

---

## 5. Testing

**PHP (carry-forward baseline: only `UninstallTest`):**
- `SitePerformanceRepository::findFleetForUser` — seed 3 sites (one measured twice → assert latest row wins; one measured once; one never measured → null cols); assert ownership scoping (another user's site excluded).
- `SiteAnalyticsRepository::findFleetForUser` — seed sites with two month snapshots (latest period wins), property-but-no-snapshot, and no-property; assert null states + `ga4_property_id` passthrough + ownership scope.
- `InsightsService::compose` — summary math (avg over measured only, `slow_sites` threshold, `connected` count, `total_sessions` sum) + worst-first ordering for both sections.
- `InsightsController` — 401 unauthenticated; authenticated payload shape (top-level `performance`/`analytics`/`generated_at`).
- CORS + route-resolution test (`GET /insights` resolves, not `rest_no_route`).
- `RateLimit::insights` bucket (allows under limit, 429 `insights.rate_limited` over limit).

**SPA (carry-forward baseline: 4 — SiteDetail×2 + SiteCoreCard×2):**
- `useInsights` hook test (parses fixture).
- `Insights` page render: worst-first order, "Not yet measured" + "Not connected" empty rows, summary tiles, row click-through.
- `insightsSchema` Zod parse; `psiBand` unit tests (band boundaries 49/50/89/90, null).
- MSW handler returns a representative fixture.

---

## 6. Out of scope (YAGNI)

- No Refresh-all / Measure-all button (read-only page).
- No daily-trend / sparkline (deferred; avoids inline-SVG charting).
- No PDF / report-section change (this is a fleet screen, not a report surface).
- No new DB tables/columns, no schema bump, no new background jobs, no connector change.

---

## 7. Build order (≈10 tasks)

1. `SitePerformanceRepository::findFleetForUser` + test.
2. `SiteAnalyticsRepository::findFleetForUser` + test.
3. `InsightsService::compose` (summaries + worst-first ordering) + test.
4. `RateLimit::insights` bucket + test.
5. `InsightsController` + `GET /insights` route registration + CORS/route-resolution test.
6. Dashboard `v0.23.0` version bump.
7. SPA: `insightsSchema` Zod + `psiBand.ts` helper + MSW handler (+ tests).
8. SPA: `useInsights` query hook (+ test).
9. SPA: `components/insights/*` (two strips + two tables) + `routes/Insights.tsx` + `InsightsNavLink` + `App.tsx` route + Overview nav (+ render test).
10. Release: full PHP + SPA suites, `pnpm build` (tsc), dompdf-preserving zip `dist/defyn-dashboard-0.23.0.zip`, merge to main, manual Kinsta install (pause for "installed"), indirect curl smoke (`GET /insights` 401 unauth + 200 authed shape + bogus-route contrast), Cloudflare auto-deploy verify, tag `p6-3-fleet-insights-complete`, MEMORY.
