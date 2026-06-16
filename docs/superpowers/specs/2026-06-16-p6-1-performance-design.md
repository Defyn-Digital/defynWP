# P6.1 — Performance (PageSpeed) Reporting — Design

**Date:** 2026-06-16
**Phase:** 6 (Analytics & Performance) — slice 1 of 2. The FIRST work beyond the now-complete locked roadmap (Monitoring → Security → Reporting).
**Status:** approved design, pre-plan.

## Goal

Add a **Performance** section to the maintenance report, backed by Google PageSpeed Insights. A weekly background job measures each site's Performance score + Core Web Vitals (mobile + desktop) and stores a snapshot; the report shows the latest snapshot as the headline plus a short weekly trend over the report's date range. The section flows through all three report surfaces (on-screen P5.1, branded PDF P5.2, stored queue P5.3).

## Scope

- **In:** weekly PageSpeed snapshot per site (a new table + a recurring AS fan-out, mirroring the daily security scan); a `performance` key on `ReportService::compose`; a Performance section in the PDF + on-screen report (inherited by the stored queue for free); an on-demand "Measure now" endpoint + a small Site-detail panel.
- **Out / non-goals:** GA4 / Analytics (that's **P6.2** — it needs OAuth + encrypted tokens + per-site property mapping, a separate slice); field/CrUX real-user data (we use Lighthouse **lab** metrics — always present, consistent for every site); per-page or per-URL breakdowns (homepage only); historical backfill (snapshots accrue going forward); connector changes (none).
- **One slice** (~16 tasks). Dashboard-plugin only. **Schema v13 → v14.** Connector unchanged (v0.1.7). Dashboard v0.19.0 → **v0.20.0**.

## Reuse (all shipped — verify during implementation)

- The report pipeline: `Services\ReportService::compose(siteId, userId, fromUtc, toUtc): array` (P5.1) → `Services\ReportPdfService::render(report, branding)` (P5.2) → the P5.3 stored-report queue (`Jobs\GenerateReport` composes through the same service, so a new section is inherited automatically). The on-screen report = `apps/web/src/pages/SiteReport.tsx` + `apps/web/src/components/report/*`.
- env→define key bootstrap (`DEFYN_WORDFENCE_API_KEY` in `defyn-dashboard.php`) → a `DEFYN_PAGESPEED_API_KEY` mirrors it (optional; PSI works keyless at low volume).
- The recurring fan-out + self-heal pattern: `Jobs\SecurityScanAll`→`Jobs\SecurityScan` (daily) + the `Activation::maybeRunSelfHeal` ensure-scheduled guard keyed on the new hook (P4.1 / P5.3).
- The per-site on-demand scan + panel: `POST /sites/{id}/security/scan` + `SiteSecurityPanel` + `useScanSiteSecurity` (P4.1) — the performance "Measure now" mirrors it exactly.
- Schema self-heal at v13; the `findAllSchedulable()` system-cron site list.

## Architecture

A `SitePerformance` snapshot row = one weekly PageSpeed measurement for a site (both strategies in one wide row). Fetch is **always background + best-effort** so report generation never blocks on the slow PSI API:

```
weekly: Jobs\PerformanceScanAll (recurring WEEK_IN_SECONDS)
   └─> per findAllSchedulable() site: as_schedule_single_action(defyn_performance_scan, [siteId])
          Jobs\PerformanceScan -> PerformanceScanService::scan(siteId):
             PageSpeedClient::fetch(url, 'mobile')  + ::fetch(url, 'desktop')
             -> store a snapshot row (skip the week if BOTH strategies failed)
             -> emit site.performance_measured
on-demand: POST /sites/{id}/performance/scan -> enqueue the same defyn_performance_scan job (202)
report: ReportService::compose reads the LATEST snapshot + all snapshots in [from,to] (the weekly trend)
```

PSI calls are ~10–30s each; **never** called synchronously in a web request. A failed fetch is swallowed (best-effort) — the job never throws into the fan-out.

## Data source

Google PageSpeed Insights API v5: `GET https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url={siteUrl}&strategy={mobile|desktop}&category=performance` (+ optional `&key={DEFYN_PAGESPEED_API_KEY}`). From the JSON:
- score = `lighthouseResult.categories.performance.score` × 100 (round to int; null if absent).
- LCP = `lighthouseResult.audits['largest-contentful-paint'].numericValue` (ms, int).
- CLS = `lighthouseResult.audits['cumulative-layout-shift'].numericValue` (decimal).
- INP = `lighthouseResult.audits['interaction-to-next-paint'].numericValue` (ms, int) — fall back to `'experimental-interaction-to-next-paint'` if the stable audit id is absent.
- `wp_remote_get` with `timeout => 60`, `redirection => 0`. Best-effort: `is_wp_error`, non-200, or unparseable JSON → return null for that strategy.

## Data model (schema v14)

### New table `wp_defyn_site_performance`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED PK AUTO_INCREMENT | |
| `site_id` | BIGINT UNSIGNED, indexed | |
| `mobile_score` | TINYINT UNSIGNED NULL | 0–100 |
| `mobile_lcp_ms` | INT UNSIGNED NULL | |
| `mobile_cls` | DECIMAL(6,3) NULL | |
| `mobile_inp_ms` | INT UNSIGNED NULL | |
| `desktop_score` | TINYINT UNSIGNED NULL | |
| `desktop_lcp_ms` | INT UNSIGNED NULL | |
| `desktop_cls` | DECIMAL(6,3) NULL | |
| `desktop_inp_ms` | INT UNSIGNED NULL | |
| `fetched_at` | DATETIME | when the measurement was taken (UTC) |
| `created_at` | DATETIME | row insert (UTC) |

Index `idx_perf_site_fetched (site_id, fetched_at)`. One row per weekly fetch per site (both strategies); a strategy that failed leaves its columns NULL. Plain dbDelta + `$wpdb->insert` (standard `WP_UnitTestCase` rollback) — but tests seeding `defyn_sites` still purge per guardrail #15.

### Migration

`Activation::SCHEMA_VERSION` 13 → 14; add `SitePerformanceTable` to `TABLES`; create via dbDelta. The `assertSame(13, …)` schema-version pins bump to 14 (the multi-file ripple — **count the assertions across the schema test files** and bump them all; `git add` the parent `tests/Integration/` dir too, not just `tests/Integration/Schema/`, per the P5.3 add-path miss).

## Services + job

- `Services\PageSpeedClient::fetch(string $url, string $strategy): ?array{score:int,lcp_ms:?int,cls:?float,inp_ms:?int}` — the PSI call + Lighthouse-JSON parse; an injectable HTTP seam (ctor `?callable $http = null`, default `wp_remote_get`) so tests pass canned JSON, never hit the network. Reads `DEFYN_PAGESPEED_API_KEY` if defined. Returns null on any failure.
- `Services\PerformanceScanService::scan(int $siteId, ?PageSpeedClient $client = null): void` — resolve the site (owner-agnostic `findById`); `fetch(url,'mobile')` + `fetch(url,'desktop')`; if BOTH are null, skip (no row, no event); else `SitePerformanceRepository::store(...)` (NULL columns for a failed strategy) + emit `site.performance_measured` (details: `{mobile_score, desktop_score}`). Best-effort, never throws.
- `Services\SitePerformanceRepository`: `store(int $siteId, ?array $mobile, ?array $desktop, string $fetchedAt, string $now): int`; `latestForSite(int $siteId): ?SitePerformance`; `findForSiteInRange(int $siteId, string $fromUtc, string $toUtc): SitePerformance[]` (the weekly trend, oldest→newest).
- `Models\SitePerformance` immutable DTO + `toJson` (all the columns; nothing sensitive — no key, no secret).

## Jobs

- `Jobs\PerformanceScan` (hook `defyn_performance_scan`, arg `[siteId]`) — thin wrapper delegating to `PerformanceScanService::scan` (mirrors `SecurityScan`).
- `Jobs\PerformanceScanAll` (recurring, `Scheduler` cadence `WEEK_IN_SECONDS`) — fan out `as_schedule_single_action(PerformanceScan::HOOK, [siteId], 'defyn')` per `findAllSchedulable()` site (mirrors `SecurityScanAll`, minus the feed refresh).
- `Plugin::boot` registers both hooks. `Activation::maybeRunSelfHeal` gains an ensure-scheduled guard keyed on `PerformanceScanAll::HOOK` (a brand-new hook — the existing guards don't cover it).

## REST

- **`POST /defyn/v1/sites/{id}/performance/scan`** (`SitesPerformanceScanController`) — ownership-gated (404 `sites.not_found` first); `as_enqueue_async_action(PerformanceScan::HOOK, [siteId], 'defyn')`; **202** `{data:{scheduled:true}, error:null}`. `RateLimit::performanceScan` **6/HR** (key `defyn_rl_performanceScan_%d_%d`, 429 `performance.rate_limited`). CORS-tested.
- **`GET /defyn/v1/sites/{id}/performance`** (`SitesPerformanceController`) — ownership-gated; returns the latest snapshot for the Site-detail panel: **200** `{data:{latest: <toJson>|null}, error:null}`. `RateLimit::performanceRead` **30/MIN**. CORS-tested.
- (The report's performance data does NOT need its own endpoint — it rides inside `GET /sites/{id}/report` + the PDF + the stored queue via `ReportService::compose`.)

## Report pipeline integration (all 3 surfaces)

- `ReportService::compose` adds a `performance` key:
```
performance: {
  latest: { fetched_at, mobile: {score, lcp_ms, cls, inp_ms}, desktop: {score, lcp_ms, cls, inp_ms} } | null,
  history: [ { fetched_at, mobile_score, desktop_score } ]   // snapshots in [from,to], oldest→newest
}
```
`latest` is the newest snapshot regardless of range (so a report always shows current performance); `history` is filtered to the report range for the trend. `null` latest when never measured.
- `ReportPdfService` renders a **Performance** section (mobile + desktop score blocks + a Core Web Vitals table with good/needs-improvement/poor + the weekly trend). Every value HTML-escaped (guardrail #2, already the file's discipline).
- On-screen: a new `apps/web/src/components/report/ReportPerformance.tsx`, rendered in `pages/SiteReport.tsx` alongside the existing sections; `reportSchema` (the report-payload Zod schema — note P5.3 renamed P5.1's type to `siteReportSchema`/`SiteReport`) gains the `performance` object.
- The P5.3 stored-report queue gets the section automatically (it composes through `ReportService`).

### CWV thresholds (good / needs-improvement / poor)

Google's standard buckets: LCP ≤2500ms good / ≤4000ms needs-improvement / >4000ms poor; CLS ≤0.1 / ≤0.25 / >0.25; INP ≤200ms / ≤500ms / >500ms. A small pure helper maps a metric+value → a rating; used in both the PDF and the SPA component.

## SPA (Site detail)

- `apps/web/src/components/sites/SitePerformancePanel.tsx` — shows the latest mobile + desktop score (+ "Last measured {fetched_at}" / "Not yet measured") + a **Measure now** button. Mirrors `SiteSecurityPanel`'s shell. Mounted on `routes/SiteDetail.tsx`.
- `useSitePerformance(siteId)` query (latest snapshot) + `useMeasurePerformance(siteId)` mutation (POST scan → invalidate `['sitePerformance', siteId]`). After triggering, a **bounded** poll that compares the latest `fetched_at` to the pre-scan value and stops once it changes OR after a hard cap (~90s — PSI's mobile+desktop fetch can take that long), mirroring P4.1's security "poll-until-rescanned vs previous timestamp" pattern. **Never an unbounded poll.** The P2.10 render-loop guard applies to any seed effect (primitive deps only).
- The on-screen report page gets the `ReportPerformance` section (above).

## Error handling & security

- Every PSI fetch is best-effort: failure → that strategy's columns NULL (or no row if both fail); the job never throws into the weekly fan-out (one slow/broken site can't break the run).
- `DEFYN_PAGESPEED_API_KEY` (if set) is read from the env→define bridge and **never logged**; PSI works without it (lower quota).
- On-demand scan is ownership-gated (404 first) + rate-limited; the report performance data is ownership-scoped through the existing report endpoint.
- No new connector surface, no new outbound-to-client action.

## Testing

- **PHP:** `PageSpeedClient` (parses a canned Lighthouse JSON → score/LCP/CLS/INP; INP stable-vs-experimental fallback; null on wp_error/non-200/garbage — all via the injected HTTP seam, no network); `PerformanceScanService` (stores a mobile+desktop snapshot / NULLs a failed strategy / skips when both fail / never throws); `PerformanceScanAll` weekly fan-out (enqueues per schedulable site); `SitePerformanceRepository` (store + latest + in-range trend); `ReportService::compose` performance section (latest + range-filtered history + null when never measured); the 2 controllers (auth 401 / 404 / 202 / 30-min + 6-hr buckets / CORS); schema v14 migration + version-pin bumps; uninstall drops the table (generic `Activation::TABLES` iteration). CWV-rating pure helper unit test.
- **SPA:** `ReportPerformance` component (renders scores + CWV ratings + trend; "not measured" empty state); `SitePerformancePanel` (latest + Measure-now → mutation); the CWV-rating helper; `reportSchema` parses a payload with `performance`.
- Carry-forward tolerances: PHP `UninstallTest`; SPA 4 (SiteDetail×2 + SiteCoreCard×2).

## Guardrails

1. **PSI fetched only in the background** (weekly fan-out + on-demand AS job) — NEVER synchronously in a web request (report generation stays fast).
2. **Best-effort fetch** — failure NULLs the strategy / skips the row; the job never throws into the fan-out.
3. **Lab metrics, not field/CrUX** — consistent for every site; labeled as lab in the UI.
4. **Self-heal ensure-scheduled guard keyed on the NEW hook** `PerformanceScanAll::HOOK`.
5. **`DEFYN_PAGESPEED_API_KEY` optional + never logged**; keyless works.
6. **Ownership-404 before any work; per-action rate limits; CORS-tested.**
7. **No connector change; no version-pin undercount** — count the `SCHEMA_VERSION` assertions when bumping 13→14, and `git add` the parent test dir (P5.3 add-path lesson).
8. **Render-loop guard** — SPA seed effects key on primitives (P2.10); the Measure-now flow does not infinite-poll.

## Release

Dashboard v0.19.0 → **v0.20.0**. No new heavy composer dep (PSI is a plain HTTP call) — the existing dompdf-preserving zip-build verify list is unchanged. After all tasks: full PHP + SPA suites green, `pnpm build`, dompdf-preserving zip → `dist/defyn-dashboard-0.20.0.zip` (verify symfony + json-machine + dompdf), merge to main, **manual Kinsta install** (schema v14 self-heals), indirect curl smoke (`POST /sites/999999/performance/scan` 404 / `GET /sites/999999/performance` 404 / no-auth 401), tag `p6-1-performance-complete`, MEMORY. **Operator action (optional):** set `DEFYN_PAGESPEED_API_KEY` on Kinsta for quota headroom (works without). **NEXT = P6.2 (GA4 Analytics — the OAuth slice).**
