# P5.1 — Client Maintenance Report (MVP) — Design

**Status:** Approved 2026-06-15
**Phase:** 5 (Reporting) — roadmap item 3, the final locked subsystem (Monitoring DONE → Security DONE → **Reporting**; NO Backups). This is the FIRST Reporting slice.
**Branch:** `p5-1-maintenance-report` off `main` (tip `6877a12`, Phase 4 complete).
**Versions:** dashboard `v0.16.0` → `v0.17.0`; connector **unchanged** (`v0.1.7`); **schema unchanged** (v12, no migration) — all data already exists.
**Reference:** the operator's existing client report — `Website Maintenance Report-cuscal.com-2026-05-02-2026-06-01.pdf` (12-page per-site, date-ranged report: Cover, Overview, Updates history, Uptime, Analytics, Security, Performance).

---

## 1. Goal

Let the operator produce a branded, client-facing **website maintenance report** for one site over a chosen date range, populated entirely from data DefynWP already collects. The MVP renders an on-screen, print-styled report the operator shares with the client via the browser's **Save as PDF**.

## 2. What the sample has vs what we have (scope boundary)

| Sample section | DefynWP data source | P5.1 |
| --- | --- | --- |
| Cover + Overview | site URL/label, `wp_version`, update count, uptime %, open findings | ✅ in (skip IP address) |
| **Updates history** (component · version A→B · date) | `plugin_update.succeeded` / `theme_update.succeeded` / `core_update.succeeded` activity events (`details: {slug, previous_version, new_version}`, `created_at`) | ✅ in |
| **Uptime** (up/down history + reason + duration + %) | `wp_defyn_incidents` + `MonitoringService::uptimePercent` | ✅ in |
| Analytics (GA users/views) | — no Google Analytics integration | ❌ out (future GA4 phase) |
| **Security** (scan history + vuln list + severity) | vuln snapshot (`SiteVulnerabilitiesRepository::findForSite`, dismissed-excluded) + `site.vulnerabilities_detected` events | ✅ in (skip malware / web-trust) |
| Performance (PageSpeed/YSlow) | only `last_response_time_ms` (thin) | ❌ out (future PageSpeed phase) |

**P5.1 sections = Overview + Updates + Uptime + Security.** Analytics and Performance/PageSpeed are explicitly out — they require new external data sources and become their own future phases if wanted.

## 3. Approved decisions (from brainstorm)

| Decision | Choice |
| --- | --- |
| Deliverable | **On-screen print-styled report + browser "Save as PDF"** (`window.print()` + `@media print`). A true one-click server PDF is deferred to **P5.2**. |
| Sections | **Overview + Updates + Uptime + Security** (Analytics + PageSpeed out). |
| Branding | **Minimal** — agency name ("Defyn Digital") + accent colour as a config constant + client URL + date range. Full white-label (logo upload, per-operator/per-client branding, cover page) deferred to **P5.2**. |
| Date range | **Month presets** (This month / Last month / Last 30 days) **+ custom from–to**. |
| Architecture | **One backend aggregator (`ReportService::compose`) + one read endpoint + a print-styled SPA route** — the established `OverviewService`/`MonitoringService`/`SecurityService` pattern. |

## 4. Architecture

`GET /defyn/v1/sites/{id}/report?from&to` → `ReportService::compose(siteId, userId, fromUtc, toUtc)` aggregates the four sections into one enveloped payload. The SPA renders it at `/sites/:id/report` in a print-optimized layout. No new tables; no connector change.

Rejected alternatives: (a) compose client-side from existing endpoints — no existing per-site "updates/uptime in a date range" endpoint exists, so new backend is needed regardless, and assembling 3–4 calls in the browser is messier than one composed payload; (b) a PHP-rendered HTML report page — splits the UI stack (our UI is the JWT-auth'd React SPA) and loses date-range interactivity.

## 5. Backend — `Services/ReportService.php`

```
compose(int $siteId, int $userId, string $fromUtc, string $toUtc): array
```
Ownership is enforced in the controller (`SitesRepository::findByIdForUser`), so `compose` assumes an owned site. Returns:

```jsonc
{
  "site": { "id", "label", "url", "wp_version" },
  "period": { "from": "2026-05-16", "to": "2026-06-15" },
  "overview": {
    "updates_applied": 14,
    "uptime_range_percent": 99.7,      // over [from,to]
    "open_findings": 1,
    "wp_version": "6.9.4"
  },
  "updates": [
    { "type": "plugin", "slug": "wordpress-seo", "component_name": "Yoast SEO",
      "previous_version": "27.1.1", "new_version": "27.5", "applied_at": "2026-05-31 04:12:00" }
    // type ∈ plugin|theme|core; core → component_name "WordPress", slug "wordpress"
  ],
  "uptime": {
    "range_percent": 99.7,             // uptimePercent(incidents, fromTs, toTs)
    "last_24h_percent": 100.0,
    "last_7d_percent": 100.0,
    "last_30d_percent": 99.7,
    "incidents": [
      { "started_at": "2026-05-22 02:01:00", "ended_at": "2026-05-22 02:08:00",
        "duration_seconds": 420, "reason": "502 Bad Gateway", "ongoing": false }
    ]
  },
  "security": {
    "last_scan_at": "2026-06-14 05:35:00",
    "open_findings": [
      { "severity": "high", "component_name": "User Activity Log", "type": "plugin",
        "installed_version": "2.2", "cve": null, "title": "…", "fixed_in": null }
    ],
    "severity_counts": { "critical": 0, "high": 1, "medium": 0, "low": 0 },
    "scans": [
      { "scanned_at": "2026-06-14 05:35:00", "total": 1, "critical": 0, "high": 1, "medium": 0, "low": 0 }
    ]
  }
}
```

Data assembly:
- **updates** — `ActivityLogRepository::findUpdatesForSiteInRange($siteId, $fromUtc, $toUtc)`: `WHERE site_id = %d AND event_type IN ('plugin_update.succeeded','theme_update.succeeded','core_update.succeeded') AND created_at BETWEEN %s AND %s ORDER BY created_at DESC`. Decode `details` JSON for `previous_version`/`new_version`/`slug`; derive `type` from the event-type prefix; resolve `component_name` from `site_plugins`/`site_themes` (`name` by slug; core → "WordPress", slug "wordpress"; fallback to the slug if no inventory row). `updates_applied` = count.
- **uptime** — `IncidentsRepository::findForSiteInRange($siteId, $fromUtc, $toUtc)` (incidents overlapping the window) → list of down-periods `{started_at, ended_at, duration_seconds, reason:lastError, ongoing:(ended_at===null)}`. `range_percent` = `MonitoringService::uptimePercent($incidents, $fromTs, $toTs)`; the trailing 24h/7d/30d reuse the same pure function over those windows (matching the existing `/monitoring` semantics).
- **security** — current open findings via `SiteVulnerabilitiesRepository::findForSite($siteId)` filtered to `!dismissed` (P4.3b); `severity_counts` derived from those; `last_scan_at` from `Site->lastSecurityScanAt`; `scans` from `ActivityLogRepository::findSecurityScansForSiteInRange($siteId, $fromUtc, $toUtc)` reading `site.vulnerabilities_detected` events (`details: {total, critical, high, medium, low}`, `created_at`).
- **overview** — `updates_applied` (count from updates), `uptime_range_percent` (= uptime.range_percent), `open_findings` (count of non-dismissed findings), `wp_version` (`Site->wpVersion`).

## 6. Backend — repository helpers (read-only, indexed)

- `ActivityLogRepository::findUpdatesForSiteInRange(int $siteId, string $fromUtc, string $toUtc): array` — event-type + site + `created_at` range (uses the existing `site_id`, `event_type`, `created_at` indexes on `wp_defyn_activity_log`).
- `ActivityLogRepository::findSecurityScansForSiteInRange(int $siteId, string $fromUtc, string $toUtc): array` — `site.vulnerabilities_detected` events in range.
- `IncidentsRepository::findForSiteInRange(int $siteId, string $fromUtc, string $toUtc): array` — incidents whose `[started_at, ended_at|now]` overlaps `[from, to]` (mirror the overlap logic in the P3.2 `findForUserSince`/MonitoringService).

## 7. REST — `Rest/SitesReportController.php`

`GET /defyn/v1/sites/(?P<id>\d+)/report`

- Ownership-gated: `findByIdForUser` → 404 `sites.not_found`.
- Query params `from`, `to` (`YYYY-MM-DD`). Validation:
  - both absent → default trailing 30 days (`to` = today UTC, `from` = today − 30d);
  - malformed date → 400 `report.invalid_range`;
  - `from > to` → 400 `report.invalid_range`;
  - clamp a span over a sane maximum (e.g. 366 days) → 400 `report.range_too_large`.
  - normalize to full-day UTC bounds: `from 00:00:00` … `to 23:59:59`.
- Envelope `{ data: {…compose…}, error: null }`, 200.
- `RateLimit::siteReport` — **30/MINUTE** read bucket (mirrors `siteVulnerabilities`/`security` read buckets).
- Registered in `RestRouter`; added to the CORS allow-list regression test.

## 8. SPA

### Schema + hook
- `reportSchema` (Zod) in `apps/web/src/types/api.ts` mirroring §5 + `Report` type + MSW handler/fixture.
- `useSiteReport(siteId, from, to)` query hook (`['siteReport', siteId, from, to]`), enabled when from/to set.

### Route + page — `/sites/:id/report`
- A `SiteReport` page composed of section components: `ReportHeader` (agency name + accent band + client URL + date range), `ReportOverview` (4 stat cards), `ReportUpdates` (table: component · type · version A→B · date; empty state "No updates applied this period"), `ReportUptime` (range % + 24h/7d/30d cards + incident history; empty state "No downtime this period"), `ReportSecurity` (last-scan line + open-findings list grouped by severity + scan-history mini table; empty states).
- **Date-range picker** — presets *This month / Last month / Last 30 days* (computed client-side to from/to `YYYY-MM-DD`) + a custom from–to control. Selecting a range updates the query.
- **"Print / Save as PDF"** button → `window.print()`.
- **`@media print` stylesheet** — hide the app shell (nav header, sidebar/links, the date-picker + print button themselves), set a print-friendly width, and add `break-inside: avoid` / page-break hints between sections so the browser-generated PDF reads as clean pages.
- Branding constant: `REPORT_AGENCY_NAME = 'Defyn Digital'` + an accent colour token (a single config module); per-operator white-label deferred to P5.2.

### Entry point
- A **"Report"** button on the Site detail header → navigates to `/sites/:id/report` defaulting to the last-30-days preset.
- Route added to the authed (`RequireAuth`) outlet in `App.tsx`.

## 9. Testing

- **PHP `ReportService`:** updates filtered by range + the 3 event types; slug→name resolution (plugin/theme from inventory, core→WordPress, missing-inventory→slug fallback); `details` JSON decode for versions; uptime range_percent via `uptimePercent` over [from,to] + the trailing windows; security open-findings exclude dismissed; scan-history from `vulnerabilities_detected` events; overview counts. Seed via `$wpdb->insert` (no `SitesRepository::create`) + `ActivityLogRepository::insert` + `IncidentsRepository::open/close` + `SiteVulnerabilitiesRepository::replaceForSite`. **Guardrail #15:** purge the explicit-COMMIT tables (`defyn_site_vulnerabilities`, `defyn_sites`, plus the activity/incident tables) in `setUp`.
- **PHP repo helpers:** range boundaries (inclusive), event-type filter, incident overlap.
- **PHP controller:** 200 envelope; default-range when params absent; 400 `report.invalid_range` (malformed / from>to); 400 `report.range_too_large`; 404 non-owned; 401 no-auth; 429 rate-limit; CORS.
- **SPA:** report renders all 4 sections from a fixture; presets compute correct from/to; empty-period states; the Print button calls `window.print()` (spy). Carry-forward SPA 4 (SiteDetail×2 + SiteCoreCard×2).

## 10. Release

Dashboard `v0.17.0`; connector unchanged; schema v12 unchanged. SPA build + Cloudflare auto-deploy from main. Symfony + json-machine-preserving zip → repo-root `dist/defyn-dashboard-0.17.0.zip`. Merge to main, manual Kinsta install + cache clear, indirect curl smoke (`GET /sites/1/report` no-auth 401, `GET /sites/999999/report` auth 404 `sites.not_found`, `GET /sites/1/report?from=2026-06-15&to=2026-06-01` 400 `report.invalid_range`), SPA `/sites/:id/report` route serves 200, tag `p5-1-maintenance-report-complete`, MEMORY. Happy populated report foreclosed by zero-sites + no-update-history prod state (covered by green tests).

## 11. Guardrails

1. **No schema change, no connector change** — all data already exists; read-only aggregation.
2. **Uptime % headline is computed over the reported range** (`uptimePercent(incidents, fromTs, toTs)`), not trailing-from-now — a monthly report reflects the reported month.
3. **Security findings exclude dismissed** (P4.3b) — the report shows the same "active" findings the operator sees.
4. **On-screen + browser print only** — no PHP/JS PDF library this slice (defers the zip-build weight); the report page is print-styled so the browser PDF is clean.
5. **Minimal branding via a config constant** — no per-operator settings surface this slice (deferred to P5.2).
6. **Range validated + clamped** — default trailing 30d, `from <= to`, max span, full-day UTC bounds; 400 on violation.
7. **Read bucket 30/min** (`RateLimit::siteReport`), ownership-gated, CORS-tested.
8. **Empty-period states everywhere** — a site with no updates/incidents/findings in range renders clean "nothing this period" sections, not blank.
