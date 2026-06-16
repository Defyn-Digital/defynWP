# P6.2 — GA4 Analytics in the Client Maintenance Report — Design Spec

**Date:** 2026-06-17
**Phase:** 6 (Analytics & Performance) — slice 2 of 2 (P6.1 Performance shipped v0.20.0; this is the GA4 half)
**Status:** Approved (brainstorm complete, ready for implementation plan)
**Branch:** `p6-2-ga4-analytics` (off `main` @ 6bc172d)
**Dashboard version:** v0.20.0 → **v0.21.0**
**Connector:** UNCHANGED (v0.1.7) — GA4 is dashboard-side (Google's API), nothing touches the managed WP site
**Schema:** v14 → **v15** (new `wp_defyn_site_analytics` table + `wp_defyn_sites.ga4_property_id` column)

---

## 1. Goal

Add an **Analytics** section to the client maintenance report, sourced from **Google Analytics 4 (GA4)**, flowing through all three existing report surfaces (on-screen `SiteReport`, branded PDF via `ReportPdfService`, and the P5.3 stored/emailed queue via `ReportService::compose`). The section shows, for the report's calendar month:

- **Headline totals:** sessions, total users, pageviews, average engagement time
- **Top 10 pages** by views (path + title)
- **Traffic channels** (sessions by default channel group)

No daily-trend chart in this slice (deferred — would add inline-SVG charting to the PDF).

This completes Phase 6.

---

## 2. Connection model (the pivotal decision)

**Agency-level service account in env + per-site property ID.** Chosen over interactive per-operator OAuth (most code: redirect callback, CSRF state, token-refresh rotation) and per-operator pasted service-account JSON (stores a Google private key in the DB).

### 2.1 Credentials
- The operator creates **one** Google Cloud service account, grants it **Viewer** on their GA4 properties (Google-side IAM, done once by the operator), and sets the SA's JSON key as a Kinsta environment variable **`DEFYN_GA4_SERVICE_ACCOUNT_JSON`**.
- `defyn-dashboard.php` gets a new env→define block mirroring `DEFYN_PAGESPEED_API_KEY` / `DEFYN_WORDFENCE_API_KEY` / `DEFYN_VAULT_KEY`: if the env var is set, `define('DEFYN_GA4_SERVICE_ACCOUNT_JSON', …)`. **The value is NEVER logged.** When absent, the whole feature cleanly no-ops (inert-but-safe) — the GA4 client returns null, snapshots never populate, the report shows "Analytics not connected".
- **No Google secret passes through the SPA, REST API, or database.** The operator sets it directly on Kinsta (same as the other `DEFYN_*` secrets). This honours the standing security constraint that Google OAuth/credential secrets are never entered through a UI I drive.

### 2.2 Per-site property assignment
- A new nullable column **`wp_defyn_sites.ga4_property_id VARCHAR(32)`** holds the **numeric GA4 Property ID** (e.g. `123456789`) — explicitly NOT the `G-XXXXXXX` measurement ID. The UI label and validation make this clear.
- Set/cleared on the **Site detail page** via a small `SiteAnalyticsPanel` (mirrors P6.1 `SitePerformancePanel` and P5.3's `client_email` row). Empty/blank clears it.
- Validation: digits only (`/^\d{1,32}$/`); empty string clears; anything else → 400 `analytics.invalid_property_id`.

### 2.3 Auth flow (`Ga4Client`)
Standard Google service-account JWT-bearer flow, all server-side, best-effort:
1. Parse `DEFYN_GA4_SERVICE_ACCOUNT_JSON` → `{client_email, private_key, token_uri}`.
2. Build a signed JWT (RS256, signed with `private_key` via the **already-bundled `firebase/php-jwt ^7.0`** — NO new composer dep) with claims `{iss: client_email, scope: "https://www.googleapis.com/auth/analytics.readonly", aud: token_uri, iat, exp: iat+3600}`.
3. `wp_remote_post($token_uri, grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer + assertion=<jwt>)` → `{access_token}`. (`redirection => 0`, short timeout.)
4. `wp_remote_post("https://analyticsdata.googleapis.com/v1beta/properties/{propertyId}:batchRunReports", Authorization: Bearer <access_token>, body = the 3 report requests in ONE batch)`.
5. Parse the batch response into a normalized array; return null on `is_wp_error` / non-200 / malformed JSON / missing rows.

`Ga4Client` is **NOT `final`** — its tests subclass it to stub the HTTP seam with canned GA4 JSON (no network in tests), exactly like P6.1's `PageSpeedClient` and P5.2's `ReportPdfService`. The HTTP transport is an injectable `?callable $http = null` (default `wp_remote_post`).

---

## 3. GA4 Data API queries (one `batchRunReports` call)

`POST .../properties/{id}:batchRunReports` with a `dateRanges: [{startDate, endDate}]` (the snapshot's month bounds, `YYYY-MM-DD`) and three `reportRequests`:

| # | dimensions | metrics | order/limit | → produces |
|---|-----------|---------|-------------|-----------|
| 1 | (none) | `sessions`, `totalUsers`, `screenPageViews`, `averageSessionDuration` | — | **totals** |
| 2 | `pagePath`, `pageTitle` | `screenPageViews` | order by views desc, **limit 10** | **top_pages** |
| 3 | `sessionDefaultChannelGroup` | `sessions` | order by sessions desc, limit 10 | **channels** |

`averageSessionDuration` is returned in **seconds** (float); the report formats it as `Xm Ys` (a pure helper, both PHP and TS). All metric values arrive as strings → cast to int (counts) / float (duration).

---

## 4. Caching model (calendar-month snapshots, background)

### 4.1 Schema v15
**New table `wp_defyn_site_analytics`:**

| column | type | notes |
|--------|------|-------|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT PK | |
| `site_id` | BIGINT UNSIGNED NOT NULL | |
| `period_start` | DATE NOT NULL | first day of the calendar month (UTC) |
| `period_end` | DATE NOT NULL | last day of the calendar month (UTC) |
| `sessions` | INT UNSIGNED NULL | |
| `total_users` | INT UNSIGNED NULL | |
| `screen_page_views` | INT UNSIGNED NULL | |
| `avg_session_duration` | DECIMAL(10,2) NULL | seconds |
| `top_pages` | LONGTEXT NULL | JSON array `[{path,title,views}]` |
| `channels` | LONGTEXT NULL | JSON array `[{channel,sessions}]` |
| `fetched_at` | DATETIME NOT NULL | |
| `created_at` | DATETIME NOT NULL | |

Indexes: `KEY idx_analytics_site_period (site_id, period_start)`. Upsert/dedup key is the logical tuple `(site_id, period_start, period_end)` — the repository deletes any existing row for that tuple before insert (mirrors the snapshot-replace pattern; avoids a UNIQUE constraint that complicates dbDelta).

**New column** `wp_defyn_sites.ga4_property_id VARCHAR(32) NULL` (guarded idempotent ALTER, like P5.3's `client_email`).

`Activation::SCHEMA_VERSION` 14 → 15; add `SiteAnalyticsTable` to the `TABLES` list (so the Uninstaller drops it generically). **Version-pin ripple:** bump every `assertSame(14, Activation::SCHEMA_VERSION)` across the schema test files to 15 (and `git add` the parent `tests/Integration/` dir, not just `tests/Integration/Schema/`, per the P5.3 add-path miss).

### 4.2 Models + repository
- `Models\SiteAnalytics` — immutable DTO: `id, siteId, periodStart, periodEnd, sessions, totalUsers, screenPageViews, avgSessionDuration, topPages (array), channels (array), fetchedAt`. `fromRow` JSON-decodes `top_pages`/`channels` (null-tolerant). `toJson` emits all fields.
- `Services\SiteAnalyticsRepository`:
  - `upsertForSiteAndPeriod(int $siteId, string $periodStart, string $periodEnd, array $data, string $fetchedAt, string $now): int` — delete-then-insert for the tuple.
  - `findForSiteAndMonth(int $siteId, string $periodStart): ?SiteAnalytics` — exact match on `(site_id, period_start)`.
  - `latestForSite(int $siteId): ?SiteAnalytics` — newest by `period_start DESC, id DESC` (drives the Site-detail panel).

### 4.3 Scan service + jobs
- `Services\AnalyticsScanService::scan(int $siteId, ?Ga4Client $client = null): void`:
  1. `SitesRepository::findById($siteId)` → if null, return.
  2. If `site->ga4PropertyId` is null/empty → **skip** (no row, no event).
  3. Compute current-month + previous-month bounds (pure `monthBounds()` helper, calendar-correct incl. year rollover).
  4. For each month: `Ga4Client::fetchReport(propertyId, start, end)` → if non-null, `SiteAnalyticsRepository::upsertForSiteAndPeriod(...)`.
  5. If at least one month succeeded, emit `site.analytics_synced` activity event `{months_synced}`.
  6. **Best-effort, never throws** — wrapped so one site's failure can't break the weekly fan-out.
- `Jobs\AnalyticsSync` (hook `defyn_analytics_sync`) — thin wrapper → `AnalyticsScanService::scan`. Mirrors `PerformanceScan`.
- `Jobs\AnalyticsSyncAll` (hook `defyn_analytics_sync_all`) — recurring; fan-out per `SitesRepository::findAllSchedulable()` via `as_schedule_single_action(time(), AnalyticsSync::HOOK, [$siteId], 'defyn')`. Mirrors `PerformanceScanAll`.
- `Scheduler::SCHEDULES` += `AnalyticsSyncAll::HOOK => WEEK_IN_SECONDS`.
- `Plugin::boot` += `add_action` for both hooks.
- `Activation::maybeRunSelfHeal` += a **5th ensure-scheduled guard** keyed on `AnalyticsSyncAll::HOOK` (the class exists by the time this guard is added, so no defer dance — same as P6.1's 4th guard).

---

## 5. Report integration (all 3 surfaces, layout A)

### 5.1 `ReportService::compose`
Gains an **8th** ctor dep `?SiteAnalyticsRepository $analytics = null` and an `analytics` key in the return array:

```
'analytics' => [
  'state'   => 'not_connected' | 'pending' | 'ready',
  'period'  => ['start' => 'YYYY-MM-DD', 'end' => 'YYYY-MM-DD'] | null,
  'totals'  => ['sessions'=>int, 'users'=>int, 'pageviews'=>int, 'avg_engagement_seconds'=>float] | null,
  'top_pages' => [['path'=>string,'title'=>string,'views'=>int], …] ,   // [] unless ready
  'channels'  => [['channel'=>string,'sessions'=>int], …],               // [] unless ready
]
```

State resolution:
- **`not_connected`** — `site->ga4PropertyId` is null/empty. (Compose needs the Site; it already loads it.)
- The report's range is mapped to a calendar month: if `from`/`to` exactly equal a single calendar month's first/last day, look up `findForSiteAndMonth(siteId, monthStart)`.
  - snapshot found → **`ready`** (totals/top_pages/channels populated, `period` set).
  - no snapshot, or the range is NOT a clean calendar month → **`pending`** (connected but data not available for this range; `totals` null, arrays empty).

This keeps the no-sync-fetch guardrail: compose only reads cached snapshots, never calls GA4.

### 5.2 `ReportPdfService` — `analyticsHtml` (stacked, layout A)
A new section method mirroring `performanceHtml`/`securityHtml` + `sectionWithBody`:
- `not_connected` → "Analytics not connected." (muted).
- `pending` → "Analytics not yet available for this period." (muted).
- `ready` → a `.stats` KPI strip (Sessions / Users / Pageviews / Avg engaged) + a Top Pages `table.data` + a Channels `table.data`.
- **Every interpolated value `esc()`'d** (a page title or channel name is attacker-influenced data); avg-engagement formatted `Xm Ys`. Tolerates a missing `analytics` key (treats as `not_connected`) so pre-existing PDF tests stay green.
Placed after the Performance section in `buildHtml`.

### 5.3 SPA `ReportAnalytics.tsx`
- `reportAnalyticsSchema` added to **`siteReportSchema`** (NOT `reportSchema` — the P5.3 stored-queue entity) in `apps/web/src/types/api.ts`.
- `components/report/ReportAnalytics.tsx` — stacked layout (KPI strip + 2 tables); `not_connected`/`pending` render the neutral line. Mounted in `pages/SiteReport.tsx` after `<ReportPerformance>`.
- A pure TS `formatEngagement(seconds)` helper (`Xm Ys`), format mirrored from the PHP helper.
- The MSW report fixture + the 2 existing report fixtures (`SiteReport.test.tsx`, `useSiteReport.test.tsx`) gain an `analytics` object (required field, same lesson as P6.1).

### 5.4 P5.3 stored/emailed queue
Inherits the Analytics section **for free** — `Jobs\GenerateReport` composes through the same `ReportService::compose` and renders through the same `ReportPdfService`. No P5.3 change.

---

## 6. REST endpoints (3)

All ownership-gated **404 `sites.not_found` first**, per-action RateLimit, CORS-tested, route-resolution-tested (404 not `rest_no_route`). Enveloped `{data, error}` except the scan endpoint (direct, like P6.1).

| method + path | controller | rate limit | success |
|---|---|---|---|
| `GET /defyn/v1/sites/{id}/analytics` | `SitesAnalyticsController` | `analyticsRead` 30/min | 200 `{data:{latest: SiteAnalytics::toJson \| null, ga4_property_id: string\|null}, error:null}` |
| `POST /defyn/v1/sites/{id}/ga4-property` | `SitesGa4PropertyController` | `ga4Property` 10/hr | 200 `{data:{ga4_property_id}, error:null}`; 400 `analytics.invalid_property_id` |
| `POST /defyn/v1/sites/{id}/analytics/refresh` | `SitesAnalyticsRefreshController` | `analyticsRefresh` 6/hr | 202 `{data:{scheduled:true}, error:null}` (enqueues `AnalyticsSync`) |

`SitesRepository` gains `setGa4PropertyId(int $siteId, ?string $propertyId): void`. `Site` DTO + `toJson` gain `ga4_property_id`. RateLimit buckets keyed `defyn_rl_analyticsRead_%d_%d` / `defyn_rl_ga4Property_%d_%d` / `defyn_rl_analyticsRefresh_%d_%d`, 429 code `analytics.rate_limited` (read/refresh) and `sites.rate_limited` (ga4Property, mirroring client-email's bucket convention).

---

## 7. SPA — Site detail panel

`components/sites/SiteAnalyticsPanel.tsx` (mirrors `SitePerformancePanel`):
- Header "Analytics".
- **Not connected:** a property-ID input + Save (label clarifies: numeric GA4 Property ID, not the `G-` measurement ID) + a hint that live data also needs `DEFYN_GA4_SERVICE_ACCOUNT_JSON` set + the service account granted Viewer.
- **Connected:** shows the property ID (editable/clearable), the latest snapshot's headline (sessions / users for its month + "Last synced {fetched_at}"), and a **Refresh analytics now** button.
- Hooks: `useSiteAnalytics(siteId)` query, `useSetGa4Property(siteId)` mutation (invalidates `['siteAnalytics', siteId]`), `useRefreshAnalytics(siteId)` mutation with a **bounded poll** cloned from P6.1 `useMeasurePerformance` / P4.1 `useScanSiteSecurity` (isPolling state + `fetched_at`-primitive-keyed effects + hard 60s timeout — never unbounded, P2.10 render-loop guard).
- Mounted on `routes/SiteDetail.tsx` near `SitePerformancePanel`, gated `status !== 'pending'`.
- MSW handlers for all 3 endpoints.

---

## 8. Error handling (best-effort everywhere)

Every external failure degrades to a **state**, never an exception or a broken report:
- No `DEFYN_GA4_SERVICE_ACCOUNT_JSON` → `Ga4Client` returns null → snapshots never populate → report shows `not_connected`/`pending`.
- Bad property ID / API error / quota / malformed JSON → `fetchReport` returns null → that month's snapshot simply isn't written.
- `AnalyticsScanService` wraps per-site work so one failure can't break the weekly fan-out (guardrail mirrors P6.1 #2).
- Compose only reads cached snapshots → a report request **never** calls GA4 synchronously (guardrail mirrors P6.1 #1).
- `ReportPdfService` tolerates a missing/empty `analytics` key.

---

## 9. Testing

- `Ga4ClientTest` — canned GA4 `batchRunReports` JSON through the injected HTTP seam (no network); asserts the normalized parse + null on error/garbage; asserts the JWT assertion is built (claims) without hitting Google.
- `SiteAnalyticsRepositoryTest` — upsert/replace per tuple, `findForSiteAndMonth`, `latestForSite` (guardrail #15 setUp purge of `defyn_site_analytics` + `defyn_sites`).
- `AnalyticsScanServiceTest` — skip-when-no-property, both-months-fetched, never-throws-on-client-failure, emits event.
- `ReportServiceTest` — appends `analytics` state cases (not_connected / pending / ready by calendar-month match).
- `ReportPdfServiceTest` — analytics section renders + escapes + "not connected"/"not yet available" tolerance.
- `RateLimitAnalyticsTest` + `AnalyticsCorsTest` + `SitesAnalyticsTest` (controllers direct + route-resolution 404).
- SPA: `ReportAnalytics.test.tsx`, `SiteAnalyticsPanel.test.tsx`, `formatEngagement` helper test; `pnpm build` tsc-clean; no render-loop hang.
- Carry-forward unchanged: PHP `UninstallTest` only; SPA the 4 (`SiteDetail`×2 + `SiteCoreCard`×2).

---

## 10. Versioning & release

- Dashboard **v0.20.0 → v0.21.0** (plugin header line + `DEFYN_DASHBOARD_VERSION`).
- Connector **UNCHANGED (v0.1.7)**.
- dompdf-preserving zip — verify list now also includes `src/Schema/SiteAnalyticsTable.php` alongside symfony deprecation-contracts/polyfill-php83 (2) + json-machine `Items.php` (≥1) + dompdf `Dompdf.php` (≥1) + `src/Schema/SitePerformanceTable.php` (≥1).
- Merge to main → Cloudflare auto-deploys SPA. **Manual Kinsta install** of `dist/defyn-dashboard-0.21.0.zip` (schema v15 self-heals).
- Indirect curl smoke: `GET /sites/1/analytics` no-auth 401; `GET /sites/999999/analytics` auth 404 `sites.not_found` (proves route + v15 live); `POST /sites/999999/ga4-property` auth 404; `POST /sites/999999/analytics/refresh` auth 404; deployed SPA bundle has "Analytics" / "GA4 Property ID" / "Refresh analytics now". Happy populated path foreclosed by zero-sites prod + no-SA-key. **Operator action (required for live data):** create the service account, grant Viewer, set `DEFYN_GA4_SERVICE_ACCOUNT_JSON` on Kinsta, assign property IDs.
- Tag `p6-2-ga4-analytics-complete`; record to MEMORY; **NEXT = operator's choice (Phase 6 complete; no locked phases remain).**

---

## 11. Out of scope (YAGNI / deferred)

- Daily-trend sparkline (inline-SVG charting in the PDF).
- Interactive per-operator OAuth (a future slice if multi-tenant operators each need their own Google account).
- Realtime/exact-arbitrary-range analytics (only calendar-month snapshots are cached).
- A fleet-wide `/analytics` page (this slice is per-site report + Site-detail panel only).
- GA4 events/conversions, demographics, device breakdown.

---

## 12. Reused surfaces (verified during explore)

- **Vault** (`Crypto/Vault.php`) — *not actually needed* in the chosen env model (no secret stored in DB); noted as the fallback had we picked the paste-JSON model.
- **env→define bootstrap** (`defyn-dashboard.php`) — the `DEFYN_*` pattern for `DEFYN_GA4_SERVICE_ACCOUNT_JSON`.
- **`firebase/php-jwt ^7.0`** — already a prod dep; RS256 SA-JWT signing, no new composer dependency.
- **Report pipeline** — `ReportService::compose` (7 deps post-P6.1 → 8), `ReportPdfService` sub-section + `sectionWithBody` + `esc`, SPA `components/report/*` + `siteReportSchema`.
- **Best-effort background-fetch pattern** — P6.1 `PerformanceScanService`/`PageSpeedClient` (NOT-final + injectable seam + null-on-failure).
- **Weekly fan-out + self-heal guard** — P6.1 `PerformanceScanAll`→`PerformanceScan`; P4.1 `SecurityScanAll`.
- **Per-site setting endpoint** — P5.3 `client_email` (`SitesClientEmailController` + `setClientEmail`) is the exact template for `ga4-property`.
- **Bounded poll** — P6.1 `useMeasurePerformance` / P4.1 `useScanSiteSecurity` (primitive-keyed effects, no render loop).
- **RateLimit / CORS / route-resolution test conventions** — P6.1.
