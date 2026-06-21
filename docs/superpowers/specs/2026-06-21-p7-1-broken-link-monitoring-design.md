# P7.1 — Broken-Link Monitoring — Design Spec

**Date:** 2026-06-21
**Status:** Approved (pending written-spec review)
**Baselines:** connector v0.1.7 → **v0.1.8**; dashboard v0.26.0 → **v0.27.0**; schema **v16 → v17**.

---

## 1. Summary

Add a third background scanner (alongside Security/P4.1 and Performance/P6.1): a **broken-link monitor**. For each managed site, the connector enumerates published posts/pages, extracts their `<a href>` links, HTTP-checks each, and reports the bad ones to the dashboard. The dashboard stores them, surfaces them on Site Detail + Overview, and adds a **Broken links** section to the client report (on-screen + PDF).

This is the user-requested "link monitoring" feature. It is distinct from existing **uptime** monitoring (homepage up/down + SSL): this checks the *content links* of a site for dead destinations.

## 2. Goals / Non-Goals

**Goals**
- Detect broken **internal + external** links across a site's published content.
- Weekly automatic scan + an on-demand "Check links now" action.
- Per-site panel, an Overview attention reason, and a report section.
- Best-effort and bounded — never overload a managed site, never throw into the weekly fan-out, never block a web request.

**Non-Goals (v1 — YAGNI; natural follow-ups)**
- No per-link "ignore/dismiss" (cf. Security's later P4.3b). v1 simply re-derives state each scan.
- No standalone fleet **Broken links** page (cf. Security's later P4.2). v1 surfaces fleet state only via the Overview attention reason.
- No checking of links inside widgets, menus, theme templates, or custom-field/meta content — **published `post`/`page` `post_content` only** in v1.
- No anchor-fragment (`#…` deep-link) validation, no `mailto:`/`tel:` validation.

## 3. Classification (the product rule)

The connector follows redirects and uses the **final** response. It reports a link only when the final status is **not healthy** (final HTTP status `>= 400`, or a transport error → null status). Every reported link is classified by a single pure dashboard helper `LinkClassifier::classify(?int $status, bool $transportError): array` → `{severity, reason}`:

| Final result | severity | reason |
|---|---|---|
| `404`, `410` | `broken` | `not_found` |
| `5xx` | `warning` | `server_error` |
| `403`, `401`, `429` | `warning` | `blocked` |
| other `4xx` | `warning` | `client_error` |
| transport error / timeout / DNS (status null) | `warning` | `unreachable` |

Rationale: only definitive `404/410` are hard "broken". Everything else is a softer **warning** because external hosts routinely bot-block (`403`), rate-limit (`429`), or transiently fail — flagging those as hard-broken would cry wolf. Healthy `2xx`/redirect-to-`2xx` links are never reported.

`link_type` is `internal` (same host as the site's `home_url`) or `external`.

## 4. Architecture

### 4.1 Connector (v0.1.7 → v0.1.8)

New namespace `Defyn\Connector\Links` — three small, single-purpose units:

- **`LinkExtractor`** (pure). `extract(string $postContent, string $homeUrl): array` → unique-per-source list of `{url, anchor_text}`. Parses `<a href>` via `DOMDocument` (libxml internal-errors suppressed). Resolves relative/root-relative/protocol-relative URLs against `home_url`. Keeps only `http`/`https` schemes; drops `mailto:`, `tel:`, `javascript:`, pure `#fragment`, and empty hrefs. Strips the `#fragment` from otherwise-valid URLs before dedup.
- **`LinkChecker`** (best-effort). `check(string $url): array` → `{status:?int, transport_error:bool}`. Uses `wp_remote_head($url, [timeout, redirection, user-agent])`; on `405`/`501`/WP_Error falls back to `wp_remote_get` (range-limited). Never throws. `redirection => 5`.
- **`BrokenLinkScanner`** (orchestrator). `scan(): array` → `{links:[…], scanned_posts:int, total_posts:int, checked_links:int, truncated:bool}`.
  - Query: `get_posts` `post_type in (post, page)`, `post_status=publish`, ordered by `modified DESC`, capped at `MAX_POSTS = 500`.
  - Per post: `LinkExtractor::extract`; collect occurrences `{url, anchor_text, source_post_id, source_url=get_permalink, source_title}`.
  - **Dedup the HTTP check by URL** (check each unique URL once via `LinkChecker`), capped at `MAX_LINKS = 3000` unique URLs.
  - Emit a finding **per occurrence** (so a dead URL on 3 pages → 3 rows) for every URL whose check is not-healthy, capped at `MAX_FINDINGS = 2000`.
  - Wall-clock budget `MAX_SECONDS = 90` (checked between URLs) so the call fits inside the dashboard's request timeout. Any cap hit → `truncated=true`.
  - Each finding row: `{url, status, transport_error, link_type, source_url, source_title, anchor_text}`.

New signed endpoint **`POST /defyn-connector/v1/links/scan`** → `Rest\LinksScanController` (registered in `Rest\RestRouter`). Runs `BrokenLinkScanner::scan()` synchronously and returns the result. Same signature/middleware/cache-headers (`no-store`) as the existing connector controllers. Version bump in `defyn-connector.php`.

> The connector does the work synchronously in one request (matching the existing connector request/response model). The dashboard side is what's async — see 4.2. The caps + 90s budget keep one request safe even on large sites; `truncated` tells the operator the scan was partial.

### 4.2 Dashboard (v0.26.0 → v0.27.0, schema v16 → v17)

**Schema v17 — `src/Schema/SiteBrokenLinksTable.php`** (`wp_defyn_site_broken_links`):

| column | type | notes |
|---|---|---|
| `id` | BIGINT UNSIGNED PK AI | |
| `site_id` | BIGINT UNSIGNED | the managed site |
| `url` | VARCHAR(2048) | the (possibly broken) link |
| `url_hash` | CHAR(40) | `sha1(url)` — for the upsert key + index (2048 can't be a key) |
| `source_url` | VARCHAR(2048) | the page the link is on |
| `source_hash` | CHAR(40) | `sha1(source_url)` |
| `source_title` | VARCHAR(255) NULL | |
| `anchor_text` | VARCHAR(255) NULL | |
| `status_code` | SMALLINT UNSIGNED NULL | null = transport error |
| `severity` | VARCHAR(10) | `broken` \| `warning` |
| `reason` | VARCHAR(20) | see §3 |
| `link_type` | VARCHAR(10) | `internal` \| `external` |
| `first_detected_at` | DATETIME | preserved across scans |
| `last_detected_at` | DATETIME | bumped each scan it's still found |

Indexes: `(site_id)`, `(site_id, severity)`, unique `(site_id, url_hash, source_hash)`.

Plus an `ALTER` on `wp_defyn_sites`: add `last_link_scan_at DATETIME NULL`. Activation `SCHEMA_VERSION = 17`; the usual schema-test version-pin ripple (git-add the whole `tests/Integration/` dir). Uninstaller drops the new table.

**`src/Services/BrokenLinksRepository.php`**
- `upsertForSite(int $siteId, array $finding, string $scanAt)` — INSERT … ON DUPLICATE KEY UPDATE (`status_code`, `severity`, `reason`, `link_type`, `source_title`, `anchor_text`, `last_detected_at`); `first_detected_at` kept on update.
- `pruneStaleForSite(int $siteId, string $scanAt)` — `DELETE … WHERE site_id=%d AND last_detected_at < %s` (links no longer found = fixed → disappear).
- `findForSite(int $siteId): array` — ordered `severity` (broken first) then `last_detected_at DESC`.
- `countsForSite(int $siteId): array` → `{broken, warning, total, internal, external}`.
- `countSitesWithBrokenLinksForUser(int $userId): int` — `EXISTS` against owned sites with ≥1 `broken` row (Overview).
- `findTopForReport(int $siteId, int $limit): array` — broken-first, capped (report section).

**`src/Services/BrokenLinkScanService.php`** — `scan(int $siteId): array`, best-effort, mirrors `PerformanceScanService`:
1. Load site; build signed client; `POST {connectorUrl}/links/scan` via `SignedHttpClient` with **`timeoutSeconds = 120`**.
2. On WP_Error / non-200 / unparseable → return `{ok:false}`, set `last_link_scan_at` anyway (so "last checked" advances), log nothing noisy. **Never throws.**
3. On success: for each finding, classify via `LinkClassifier`, `upsertForSite` with `scanAt = now`; then `pruneStaleForSite(scanAt)`; set `last_link_scan_at`.
4. Emit one `links.scan_completed` activity event `{broken, warning, truncated}` (site-scoped). No event on transport failure (guardrail: no log noise on no-op/failure).

**`src/Services/LinkClassifier.php`** (pure) — §3 table. Unit-tested in isolation.

**Jobs** (`src/Jobs/`), mirroring `PerformanceScan` / `PerformanceScanAll`:
- `LinkScan` (per-site) → `BrokenLinkScanService::scan`.
- `LinkScanAll` (weekly fan-out) → enqueue `LinkScan` per owned+connected site; never let one site break the loop.
- `Scheduler` weekly recurring `LinkScanAll::HOOK`; **self-heal ensure-scheduled guard** keyed on `LinkScanAll::HOOK` (a new guard block alongside the Performance/Security/Reports guards). `Plugin::boot` registers both AS hooks.

**REST** (`src/Rest/`), ownership-404 first, CORS-tested, route-resolution (404 not `rest_no_route`):
- `GET /defyn/v1/sites/{id}/broken-links` — `RateLimit::linksRead` (30/MIN). Returns `{counts, last_link_scan_at, links:[…]}`.
- `POST /defyn/v1/sites/{id}/links/scan` — `RateLimit::linksScan` (6/HR). Enqueues `LinkScan`, returns **202** `{enqueued:true}`.
- Two new `RateLimit` static methods.

**Overview** — `OverviewService` + `SitesRepository`: add `has_broken_links` to the attention-reason set, counting sites with ≥1 `broken` row (reuse `BrokenLinksRepository::countSitesWithBrokenLinksForUser`). Additive to the existing pending-updates / vulnerabilities reasons.

**Report** — `ReportService::compose` adds a `broken_links` key:
```
{ state: 'not_checked' | 'clean' | 'issues',
  last_scanned: ?string,
  counts: {broken, warning, total, internal, external},
  items: [ {url, status_code, severity, reason, link_type, source_url} ]  // top N=20, broken first
}
```
- `not_checked` when `last_link_scan_at` is null; `clean` when scanned but `total==0`; else `issues`.
- `ReportPdfService` renders a **Broken links** section: a counts strip + a capped table; **tolerates a missing/empty `broken_links` key** as "Not yet checked" so existing PDF tests (whose sample report lacks the key) stay green. Every interpolated value `esc()`'d. No new dompdf primitive (plain table — no chart).

### 4.3 SPA (apps/web)

- **`types/api.ts`** — new `siteBrokenLinksSchema` (list endpoint) + extend `siteReportSchema` with the `broken_links` object above (required; backend always emits it). MSW handlers + fixtures.
- **Hooks** — `useSiteBrokenLinks(siteId)` (query, `staleTime` like the others); `useScanSiteLinks(siteId)` (mutation → **bounded poll** on `last_link_scan_at` vs pre-scan value, stop on change or a ~90s/cap, **never unbounded** — mirror `useScanSiteSecurity`/`useMeasurePerformance`; seed effects key on primitives → no render loop, cf. the P2.10 lesson).
- **`components/sites/SiteBrokenLinksPanel.tsx`** — header with counts + "Check links now" button + last-scanned; body grouped by `source_url`, each row: severity pill (broken=destructive, warning=warning tokens), the URL (truncating), `status_code`, internal/external chip, anchor text. Empty states: "Not checked yet" / "No broken links found 🎉". Uses the design-system tokens (post-redesign).
- **`components/report/ReportBrokenLinks.tsx`** — the report section (counts + capped list), mounted in the on-screen report between Security and Performance (or after Performance — implementer picks a sensible order; document it). Tolerates `state==='not_checked'`.
- **Overview** — add the `has_broken_links` case to the attention-reason chip component + the Zod attention enum.
- **SiteDetail** — mount `SiteBrokenLinksPanel` in the panel stack.

## 5. Security & Safety

- **No dashboard-side SSRF:** the dashboard never fetches arbitrary link URLs; the **connector** does (links are the site's own author-controlled content, equivalent to the site rendering them). The dashboard only talks to the connector over the existing signed channel.
- **Connector guard:** `LinkChecker` only requests `http`/`https`; it does not special-case private IPs in v1 (author content on a public site), but the caps + timeouts bound abuse. (A private-IP skip is a possible hardening follow-up.)
- **Bounded everywhere:** connector caps (posts/links/findings/seconds); dashboard AS job (async, off the web request); per-action rate limits; report list capped at 20.
- **No secrets** introduced. No new env keys.

## 6. Versioning & Rollout

- Connector **v0.1.8** — operator re-installs the connector zip on each managed site (in-place plugin replace; connectors hold no critical data, so no wipe risk). Until a site is on v0.1.8 its `/links/scan` 404s → `BrokenLinkScanService` treats it as a best-effort failure (sets `last_link_scan_at`, stores nothing) — the feature is inert-but-safe on old connectors.
- Dashboard **v0.27.0** — operator installs via **Replace in place, never delete** (per the install-never-delete rule). Schema v17 auto-applies via dbDelta + self-heal.
- Because prod was wiped, SmartCoding will be reconnected anyway; reconnect it with the **v0.1.8** connector so link scanning works end-to-end on day one.

## 7. Testing

- **Connector:** `LinkExtractor` (relative/root-relative/protocol-relative resolution, scheme filtering, fragment strip, dedup); `LinkChecker` (HEAD→GET fallback, WP_Error → transport_error) via stubbed `wp_remote_*`; `BrokenLinkScanner` caps + truncated flag (subclass/seam the checker).
- **Dashboard:** `LinkClassifier` table (parameterized); `BrokenLinksRepository` upsert-preserves-first / prune-removes-fixed / counts; `BrokenLinkScanService` best-effort (connector 404 → no throw, `last_link_scan_at` set; success → rows + event); `ReportService::compose` three states; `ReportPdfService` section present + tolerates missing key; both REST endpoints (401, ownership-404, rate-limit-429, CORS, 202 enqueue); Overview `has_broken_links`. Tolerate only `UninstallTest` in the full suite.
- **SPA:** schema parse; panel states (broken/warning/empty/not-checked); bounded-poll hook (no render loop — runs green under Node 22 *and* 25); `ReportBrokenLinks` renders in `issues` + degrades in `not_checked`; full suite + `pnpm build` (tsc) green; carry-forward 4 (SiteDetail×2 + SiteCoreCard×2).
- **Local PDF eyeball** before release: render a report with an `issues` broken_links section through `ReportPdfService::render` to `.claude-tmp/` and confirm the section + table draw.

## 8. Rollout smoke (prod, curl-indirect)

`POST /sites/999999/links/scan` no-auth → 401; auth → 404 `sites.not_found`; `GET /sites/999999/broken-links` auth → 404; bogus route → `rest.route_not_found`. Happy path validated once SmartCoding is reconnected on v0.1.8 (operator-driven; UI password entry stays prohibited for the assistant). Cloudflare bundle string check + tag `p7-1-broken-links-complete` + MEMORY.

## 9. Task outline (for writing-plans)

Bottom-up, ~18 tasks: connector `LinkExtractor` → `LinkChecker` → `BrokenLinkScanner` → `LinksScanController`+router → connector v0.1.8 bump → schema v17 table+ALTER+version-pin → `LinkClassifier` → `BrokenLinksRepository` → `BrokenLinkScanService` → `LinkScan`/`LinkScanAll`+Scheduler+self-heal+Plugin → RateLimit buckets → REST endpoints+routes+CORS → Overview `has_broken_links` → `ReportService` `broken_links` → `ReportPdfService` section → dashboard v0.27.0 bump → SPA schemas+MSW+hooks → SPA panel+report-section+Overview-chip+SiteDetail mount → release (suites, zips, local PDF eyeball, merge, manual installs, smoke, tag, MEMORY).
