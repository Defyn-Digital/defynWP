# P4.1 — Security Scanning: Detect & Show — Design

**Status:** Approved (brainstorm 2026-06-14)
**Phase:** P4.1 — first slice of Phase 4 (Security scanning). Roadmap: Monitoring (DONE) → **Security scanning** → Reporting (no Backups).
**Branch:** `p4-1-security-scanning` (off `main` @ the P3.3 merge `c2bcdad`)
**Footprint:** Dashboard plugin + SPA only. **Connector unchanged** (v0.1.7). Schema **v10 → v11**. Dashboard **v0.12.0 → v0.13.0**.

---

## 1. Goal

Detect known vulnerabilities across the fleet by matching each managed site's already-collected plugin/theme/core versions against a vulnerability database, store the findings, and surface them — a per-site **Security** panel (severity-grouped) plus an "at-risk" rollup on the Overview. **Detection only; no alerting** (that's P4.3).

## 2. What already exists (reused, not rebuilt)

- **Inventory is fully collected dashboard-side** (no connector change needed): `wp_defyn_site_plugins` (slug, name, version, …) via `SitePluginsRepository::findAllForSite(int): Plugin[]`; `wp_defyn_site_themes` (slug, name, version, …) via `ThemesRepository::findAllForSite`; WP core version on `wp_defyn_sites.wp_version` (`Site->wpVersion`). Plugin slugs are normalized folder-only (P2.2.1).
- **Daily fan-out job pattern:** `Jobs\SslCheckAll`→`Jobs\SslCheck` (P3.3, 86400s in `Scheduler::SCHEDULES`), registered in `Plugin::boot` with a `maybeRunSelfHeal` ensure-scheduled guard. The template for `SecurityScanAll`→`SecurityScan`.
- **Overview rollup:** `SitesRepository::findSitesNeedingAttention` builds reasons via CASE/EXISTS (offline / failed_update / ssl_expiring / sync_stale); SPA `overviewAttentionReasonSchema` enum + `AttentionReasonChip` + `SitesNeedingAttentionWidget`.
- **Per-site panel pattern:** `SitePluginsPanel` / `SiteThemesPanel` / `IncidentHistoryPanel` on Site detail + the refresh-button mutation pattern (`useRefreshSitePlugins`).
- **HTTP egress:** direct `wp_remote_get` / `wp_remote_post` (best-effort, swallow-and-log house style — see `SlackNotifier`). `ActivityLogger::log(?userId, ?siteId, eventType, ?details, ?ip)`. Schema self-heal (`Activation::SCHEMA_VERSION`=10, `TABLES`, dbDelta). RateLimit per-min(read)/per-hour(write) buckets.
- **No security/vuln code exists yet** (confirmed by grep) — this is net-new.

## 3. Scope

**In scope (P4.1):**
- Ingest + cache a vulnerability feed (the **Wordfence Intelligence scanner feed** — free, keyless, downloadable JSON) into a normalized global table; refresh daily, best-effort.
- A pure **version-range matcher**; a per-site **scan** that matches plugins + themes + core and stores findings.
- A daily `SecurityScanAll`→`SecurityScan` fan-out + an on-demand "Scan now".
- A per-site **Security panel** (severity-grouped) + a **`has_vulnerabilities`** Overview attention reason.

**Out of scope (later slices):**
- A dedicated `/security` fleet page → **P4.2**.
- Alerting (email/Slack on new findings) + new-vs-seen diffing + per-finding ignore/dismiss + per-site mute → **P4.3**.
- Any connector change. Any auto-remediation (the existing update flow handles fixes).

## 4. Vulnerability feed ingestion — `Services\VulnFeedService`

- `refreshIfStale(): void` — if `get_option('defyn_vuln_feed_synced_at')` is older than ~24h (or absent), `wp_remote_get` the Wordfence Intelligence **scanner feed** (free, no API key), JSON-decode, and **upsert** normalized rows into `wp_defyn_vulnerabilities`; stamp the option. **Best-effort:** a transport/non-2xx/parse failure is logged and returns, leaving the last-good rows intact (never throws into the scan loop).
- **Feed shape is a planning research item:** the exact Wordfence scanner-feed URL + JSON schema must be confirmed against the live feed in the plan's first task. The service maps each vulnerability's affected-software entries — `(type ∈ plugin|theme|core, slug, affected_versions[] { from_version, from_inclusive, to_version, to_inclusive }, patched/fixed version)` plus `title`, `severity`, `cve`, `cvss_score` — into `wp_defyn_vulnerabilities` rows (one row per affected range). If a field is absent in the feed, store null (severity defaults to `unknown`).

## 5. Data model — schema v10 → v11 (two new tables)

### `wp_defyn_vulnerabilities` (global vuln DB — NOT per-site)
| column | type | notes |
|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | |
| `source_id` | VARCHAR(64) | the feed's vuln UUID |
| `type` | VARCHAR(10) | `plugin` \| `theme` \| `core` |
| `slug` | VARCHAR(191) | folder/stylesheet slug; `wordpress` for core |
| `title` | TEXT | |
| `severity` | VARCHAR(10) | `critical` \| `high` \| `medium` \| `low` \| `unknown` |
| `cvss_score` | DECIMAL(3,1) NULL | |
| `cve` | VARCHAR(32) NULL | |
| `from_version` | VARCHAR(32) NULL | null = from 0 |
| `from_inclusive` | TINYINT NOT NULL DEFAULT 1 | |
| `to_version` | VARCHAR(32) NULL | affected ceiling; null = unbounded |
| `to_inclusive` | TINYINT NOT NULL DEFAULT 0 | |
| `fixed_in` | VARCHAR(32) NULL | first patched version (for display) |
| `updated_at` | DATETIME | |

Index `idx_vuln_type_slug (type, slug)`; unique `uq_vuln_range (source_id, type, slug, from_version, to_version)` for idempotent upsert.

### `wp_defyn_site_vulnerabilities` (per-site findings snapshot)
| column | type | notes |
|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | |
| `site_id` | BIGINT UNSIGNED FK→sites ON DELETE CASCADE | |
| `type` | VARCHAR(10) | plugin/theme/core |
| `slug` | VARCHAR(191) | |
| `component_name` | VARCHAR(191) | display name (e.g. "Elementor") |
| `installed_version` | VARCHAR(32) | |
| `severity` | VARCHAR(10) | denormalized from the vuln |
| `cvss_score` | DECIMAL(3,1) NULL | |
| `cve` | VARCHAR(32) NULL | |
| `fixed_in` | VARCHAR(32) NULL | |
| `title` | TEXT | |
| `source_id` | VARCHAR(64) | |
| `scanned_at` | DATETIME | |
| `created_at` | DATETIME | |

Index `idx_sitevuln_site (site_id)`. Display fields are **denormalized** (snapshot) so the panel reads one table and findings survive a feed re-sync.

### Altered table `wp_defyn_sites`
- Add `last_security_scan_at DATETIME NULL` via a **guarded ALTER** (mirror P3.2 `addResponseTimeColumn`). This is the site-level "was this site scanned, and when" timestamp — **separate from the findings rows**, so a clean site (zero findings) is still distinguishable from a never-scanned site. The scan sets it at the end of every run (even with zero findings). `Site` DTO gains `lastSecurityScanAt` (?string).

Both new tables join `Activation::TABLES` (created via dbDelta, dropped by Uninstaller). Bump `SCHEMA_VERSION` 10 → 11 (two tables + one guarded column ALTER). No connector schema change.

## 6. Matcher — `Services\VulnerabilityMatcher`

A **pure** static function — the heart of the slice:
```php
public static function isAffected(string $installed, ?string $from, bool $fromInc, ?string $to, bool $toInc): bool
```
> affected iff `(from === null || version_compare(installed, from, fromInc ? '>=' : '>')) && (to === null || version_compare(installed, to, toInc ? '<=' : '<'))`.

`version_compare` handles WP/plugin version strings. If `$installed` is empty/unparseable the function returns **false** (no false positive on an uncomparable version). No DB access; exhaustively unit-tested.

## 7. Scan — `Services\VulnerabilityScanService`

`scan(int $siteId): void`:
- Load the site (`SitesRepository::findById`); null → return.
- Gather candidates: each plugin (`type=plugin`, slug, version) + each theme (`type=theme`) + core (`type=core`, slug `wordpress`, `site.wpVersion`).
- For each candidate, fetch `wp_defyn_vulnerabilities` rows by `(type, slug)` and keep those where `VulnerabilityMatcher::isAffected(...)` is true → a finding (denormalized snapshot: component_name, installed_version, severity, cvss, cve, fixed_in, title, source_id).
- **Replace-for-site**: `SiteVulnerabilitiesRepository::replaceForSite($siteId, $findings, $now)` (wipe the site's rows + insert the current snapshot with `scanned_at`). Atomic.
- **Always** stamp the site-level scan time: `SitesRepository::markSecurityScannedAt($siteId, $now)` — even when `$findings` is empty (so a clean site reads as "scanned, clean", not "never scanned").
- Emit `site.vulnerabilities_detected` activity (`details: { total, critical, high, medium, low }`).

(P4.1 stores a current snapshot; first-seen diffing for "new finding" alerts is deferred to P4.3.)

## 8. Jobs

`Jobs\SecurityScanAll` (recurring, `Scheduler::SCHEDULES` at **86400**s): calls `VulnFeedService::refreshIfStale()` **once**, then loops `findAllSchedulable()` scheduling a per-site `Jobs\SecurityScan`. `SecurityScan::handle($siteId)` → `VulnerabilityScanService::scan($siteId)`. Both hooks registered in `Plugin::boot` (mirror `SslCheckAll`/`SslCheck`); `maybeRunSelfHeal` ensure-scheduled guard installs the new recurring schedule on a silent upgrade (mirror P3.3).

## 9. REST API — two new endpoints

- `GET /defyn/v1/sites/{id}/vulnerabilities` — **30/min** (`RateLimit::siteVulnerabilities`), ownership-checked (404 `sites.not_found`). Returns `{ scanned_at: string|null, vulnerabilities: [{ type, slug, component_name, installed_version, severity, cvss_score, cve, fixed_in, title }] }`, sorted severity-desc then component. `scanned_at` comes from `site.last_security_scan_at` (null = never scanned), NOT from the findings rows. Composed by `SitesVulnerabilitiesController` reading `Site::lastSecurityScanAt` + `SiteVulnerabilitiesRepository::findForSite`.
- `POST /defyn/v1/sites/{id}/security/scan` — **6/hr** (`RateLimit::securityScan`), ownership-checked. Ensures the feed is fresh + schedules a `SecurityScan` AS job for the site; returns **202** `{ scheduled: true }` (mirrors the plugins/themes refresh pattern). The SPA polls the GET for fresh findings.
- `/overview` (existing `OverviewService` / `findSitesNeedingAttention`): add a `has_vulnerabilities` reason (`EXISTS(SELECT 1 FROM site_vulnerabilities WHERE site_id = s.id)`) + the at-risk site count. `overviewAttentionReasonSchema` gains `'has_vulnerabilities'`.

## 10. SPA

- **`SiteSecurityPanel`** on Site detail (stacks with Plugins/Themes panels): the **severity-grouped list** — Critical / High / Medium / Low section headers, each finding = `component_name` (+type) · `installed_version` → fixed-in `fixed_in` · `cve`; header "N vulnerabilities · scanned {relative}"; a **"Scan now"** button (POST mutation → poll the GET); empty state "✓ No known vulnerabilities"; "Not yet scanned" when `scanned_at` is null; loading/error states. New `vulnerabilitySchema` + `siteVulnerabilitiesSchema` (Zod) + `useSiteVulnerabilities(siteId)` query + `useScanSiteSecurity(siteId)` mutation (invalidates `['siteVulnerabilities', id]`) + MSW handlers.
- **Overview:** `SitesNeedingAttentionWidget` renders a red **"N vulnerable"** chip via the new `has_vulnerabilities` reason (`AttentionReasonChip` gains the case). **No new route in P4.1** — the dedicated `/security` fleet page is P4.2.

## 11. Error handling & edge cases

- **Feed fetch best-effort:** failure → log, keep last-good vuln rows; the scan still runs against cached data (never throws into the AS loop).
- **Never-scanned site:** panel shows "Not yet scanned" until the first daily scan or a "Scan now".
- **Unparseable installed version** → matcher returns false (no false positive); logged.
- **Feed is global** (public vuln data) — `wp_defyn_vulnerabilities` is not user-scoped; per-site findings are ownership-scoped via the site join.
- **Replace-for-site** keeps findings consistent with the latest scan; re-running a scan the same day just rewrites the snapshot.
- UTC throughout (`gmdate`).

## 12. Testing

**PHP (dashboard):**
- `VulnerabilityMatcher::isAffected` exhaustive table: null `from`/`to`, inclusive vs exclusive boundaries, equal-version edges (==from with from_inclusive on/off; ==to with to_inclusive on/off), unbounded-to, unparseable installed → false.
- `VulnFeedService`: maps a sample feed payload → normalized rows; upsert idempotency (re-ingest doesn't duplicate via the unique key); best-effort on `wp_remote_get` failure (`pre_http_request` mocked) leaves prior rows.
- `VulnerabilityScanService::scan`: seeds a site (plugins + themes + core) + seeds vuln rows; asserts the right findings are stored, replace-for-site wipes stale findings, the activity event fires with correct severity counts; clean site → zero findings BUT `last_security_scan_at` is still stamped (distinguishes scanned-clean from never-scanned). `SitesRepository::markSecurityScannedAt` + schema v11 column present + guarded ALTER idempotent.
- `SiteVulnerabilitiesRepository` (replaceForSite, findForSite sorted severity-desc).
- `SecurityScanAll` fan-out (per-schedulable-site) + `Scheduler` registers the daily hook + `Plugin` boot wiring.
- `SitesVulnerabilitiesController` (200/401/404/ownership) + `SecurityScanController` (202/404) + `RateLimit::siteVulnerabilities`/`securityScan` buckets + 2 CORS regressions.
- `findSitesNeedingAttention` `has_vulnerabilities` reason; schema v11 (both tables present) + version-pin tests → 11; Uninstaller drops the two tables (via TABLES).

**SPA (apps/web):**
- `vulnerabilitySchema`/`siteVulnerabilitiesSchema`; `useSiteVulnerabilities`/`useScanSiteSecurity`; `SiteSecurityPanel` render states (severity-grouped findings / empty / not-scanned / scanning); the new `has_vulnerabilities` attention chip. Carry-forward tolerated: the documented 4 (SiteDetail×2 + SiteCoreCard×2); full route suite green under **Node 22** (render-loop lesson).

## 13. Release

- Dashboard version bump **v0.12.0 → v0.13.0**; CORS note for the 2 new routes.
- **Connector unchanged** (v0.1.7) — no zip rebuild, no re-handshake.
- Schema v11 via self-heal; Uninstaller drops both new tables (TABLES-iteration).
- Symfony-preserving zip (top-level `dashboard-plugin/` folder); SPA auto-deploys via Cloudflare.
- Production smoke (API curl only; login JWT field is **`access_token`**): schema v11 (`GET /sites/{id}/vulnerabilities` 200 envelope + 401 + 404), `POST .../security/scan` 202/404, `/overview` reason enum carries `has_vulnerabilities`, `/sites/:id` SPA route + deployed bundle has the new "Security"/"vulnerabilities" strings.
- Tag `p4-1-security-scanning-complete`. (Next: **P4.2** dedicated `/security` fleet page; then **P4.3** alerting + ignore/dismiss config.)

## 14. Guardrails (plan-bug traps to surface in the plan)

1. **No connector change** — the matcher runs entirely dashboard-side against the already-collected inventory.
2. Feed source = **Wordfence Intelligence scanner feed** (free, **keyless**) — design keyless-first; **verify the live feed URL + JSON shape in the plan's first task** before relying on field names.
3. `VulnFeedService` is **best-effort** — never throws into the scan/AS loop; keeps last-good data on failure.
4. `VulnerabilityMatcher` is a **pure** function (version_compare-based, no DB); unparseable installed version → **false** (no false positive); inclusivity flags drive `>`/`>=` and `<`/`<=`.
5. Scan covers **plugins + themes + core** (core slug = `wordpress`, version = `site.wpVersion`).
6. Findings are a **replace-for-site snapshot** (P4.1); first-seen diffing is deferred to P4.3 — do NOT build alerting here. The scan **always** stamps `wp_defyn_sites.last_security_scan_at` (even with zero findings) so "scanned-clean" ≠ "never-scanned"; the GET's `scanned_at` reads that column, not the findings rows.
7. `wp_defyn_vulnerabilities` is **global** (public data, not user-scoped); per-site findings are ownership-scoped via the site join.
8. Schema via self-heal (v11, two tables in `TABLES` + dbDelta); Uninstaller drops both; version-pin tests → 11.
9. New daily `SecurityScanAll`→`SecurityScan` fan-out (86400s) mirrors `SslCheckAll`→`SslCheck`; registered in `Plugin::boot` + `Scheduler::SCHEDULES` + a self-heal ensure-scheduled guard.
10. Overview gains a `has_vulnerabilities` reason; **no `/security` route in P4.1** (that's P4.2).
11. Feed refresh happens **once** per scan cycle (in `SecurityScanAll` before the fan-out), not per-site. UTC everywhere; full SPA route suite green under Node 22.
