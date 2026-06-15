# P4.2 — Security Fleet Page — Design

**Status:** Approved (brainstorm 2026-06-15)
**Phase:** P4.2 — second slice of Phase 4 (Security scanning). Roadmap: Monitoring (DONE) → Security scanning [P4.1 DONE → **P4.2** → P4.3] → Reporting (no Backups).
**Branch:** `p4-2-security-fleet` (off `main` @ the P4.1 merge `db53c89`)
**Footprint:** Dashboard plugin + SPA only. **Connector unchanged** (v0.1.7). **Schema unchanged (v11)** — data already in `wp_defyn_site_vulnerabilities`. Dashboard **v0.13.0 → v0.14.0**.

---

## 1. Goal

Give the operator a single cross-fleet view of every managed site's vulnerability posture — a dedicated `/security` page (beside Monitoring / Jobs) with a KPI summary strip and a site-centric table (worst-severity first), plus a fleet "Scan all sites" action. Detection data is the P4.1 per-site findings (`wp_defyn_site_vulnerabilities`), rolled up. **No new detection logic, no schema change** — this is a read/rollup slice + a fan-out action.

## 2. What already exists (reused, not rebuilt)

- **Per-site findings** in `wp_defyn_site_vulnerabilities` (P4.1): `site_id, type, slug, component_name, installed_version, severity ∈ {critical,high,medium,low,unknown}, cvss_score, cve, fixed_in, title, source_id, scanned_at, created_at`. Per-site read: `SiteVulnerabilitiesRepository::findForSite(int): SiteVulnerability[]`.
- **Site-level scan timestamp** `wp_defyn_sites.last_security_scan_at` (P4.1) — null = never scanned; set (even with zero findings) = scanned. Surfaced on `Site->lastSecurityScanAt`.
- **Fleet read endpoint pattern:** P3.2 `MonitoringController` → `MonitoringService::compose(int $userId): array`, **direct payload (no `{data}` envelope)**, 30/min `RateLimit::monitoring`. The exact template for `GET /security`.
- **Fleet fan-out action pattern:** P2.6 `OverviewSyncAllController` (`POST /overview/sync-all`, 10/hr) — fan-outs an AS job per owned site, emits ONE fleet-scoped activity event (`site_id=null`) only when scheduled_count > 0, returns 202 when sites > 0 / 200 no-op when 0. The template for `POST /security/scan-all`.
- **The scan job** `Jobs\SecurityScan` (P4.1) + `VulnFeedService::refreshIfStale()` (P4.1, best-effort, no-ops without a key). `SitesRepository::findAllSchedulable(): int[]` lists schedulable site IDs.
- **SPA fleet-page pattern:** `routes/Monitoring.tsx` + `lib/queries/useMonitoring.ts` + `components/nav/MonitoringNavLink.tsx` (nav links placed in `Overview.tsx`'s header) + `App.tsx` router. P2.6 `SyncAllSitesButton` + `ConfirmSyncAllDialog` + `useSyncAllSites` for the Scan-all action.
- `ActivityLogger::log(?userId, ?siteId, eventType, ?details)` (in `Defyn\Dashboard\Services\`). `RateLimit` per-min(read)/per-hour(write) buckets.

## 3. Scope

**In scope (P4.2):**
- A fleet rollup query + `SecurityService::compose` → fleet payload (summary + per-site rows).
- `GET /defyn/v1/security` (30/min) read endpoint.
- `POST /defyn/v1/security/scan-all` (5/hr) fleet fan-out action.
- SPA `/security` route: KPI strip + site-centric table (all sites, status-sorted) + a "Scan all sites" button + confirm dialog + nav link.

**Out of scope (later / not needed):**
- Any schema change, any connector change, any new detection logic.
- A finding-centric "inbox" view (rejected in brainstorm — the per-site panel already lists findings).
- A fleet-wide live poll after Scan-all (the scan runs async via AS jobs; we invalidate + toast, table reflects on next load). Per-site live polling already exists in `SiteSecurityPanel`.
- Alerting on new findings + per-finding ignore/dismiss + per-site mute → **P4.3**.

## 4. Data access — `SiteVulnerabilitiesRepository::findFleetSummariesForUser`

New method (one query, no N+1):
```php
/** @return list<array{site_id:int,label:string,url:string,last_security_scan_at:?string,critical:int,high:int,medium:int,low:int,total:int}> */
public function findFleetSummariesForUser(int $userId): array
```
- `wp_defyn_sites s` (WHERE `s.user_id = %d`) **LEFT JOIN** `wp_defyn_site_vulnerabilities sv ON sv.site_id = s.id`, `GROUP BY s.id`.
- Per-site severity counts via conditional sums: `SUM(sv.severity = 'critical')` etc.; `total = COUNT(sv.id)`. A clean (scanned, no findings) site → all-zero counts; a never-scanned site → all-zero counts **and** `last_security_scan_at` null (the distinguishing field). `unknown`-severity findings still count toward `total` but not the four severity buckets (acceptable; they render under a separate group on the per-site panel, and the fleet table shows `total`).
- Returns ALL the user's sites (clean + never-scanned included), unsorted (sorting is `compose`'s job).

## 5. Composition — `Services\SecurityService::compose`

```php
public function compose(int $userId): array
```
Returns the **direct** fleet payload (no envelope, mirrors `/monitoring`):
```jsonc
{
  "summary": {
    "total_sites": 14,
    "scanned_sites": 11,        // last_security_scan_at not null
    "sites_at_risk": 3,         // total > 0
    "critical": 2, "high": 5, "medium": 1, "low": 4   // summed across the fleet
  },
  "sites": [
    { "site_id": 7, "label": "Acme Blog", "url": "https://acme-blog.com",
      "last_security_scan_at": "2026-06-15 02:00:00",
      "counts": { "critical": 1, "high": 2, "medium": 0, "low": 1, "total": 4 } }
    // ...
  ],
  "generated_at": "2026-06-15 03:00:00"
}
```
- **Sort order** (computed in `compose`, small N): (1) at-risk sites (`total > 0`) by `critical desc, high desc, medium desc, low desc, total desc, label asc`; then (2) scanned-clean sites (`last_security_scan_at != null && total == 0`) by `label asc`; then (3) never-scanned sites (`last_security_scan_at == null`) by `label asc`.
- `summary` aggregates over the same rows. Constructor-injectable repo dep with a prod default (mirror `MonitoringService`).

## 6. REST API — two new endpoints

- **`GET /defyn/v1/security`** — `SecurityController` (clone `MonitoringController`): `return new WP_REST_Response($this->service->compose($userId), 200)`. **30/min** `RateLimit::security` (per-user; mirror `RateLimit::monitoring`). 401 without auth (via the rate-limit/permission callback → `RequireAuth::check`). Direct payload.
- **`POST /defyn/v1/security/scan-all`** — `SecurityScanAllController` (clone `OverviewSyncAllController`): **5/hr** `RateLimit::securityScanAll` (per-user). Calls `VulnFeedService::refreshIfStale()` once (best-effort, no-ops without a key), then for each `SitesRepository::findAllSchedulable()` ID schedules `as_schedule_single_action(time(), SecurityScan::HOOK, [$siteId], 'defyn')`. Emits ONE `security.scan_all_requested` activity event (`userId`, `site_id=null`, `details:{scheduled_count, site_ids[]}`) **only when scheduled_count > 0**. Returns **202** `{scheduled:true, scheduled_count, site_ids[]}` when sites > 0, else **200** `{scheduled:false, scheduled_count:0, site_ids:[]}` (no-op, no activity row). Both routes registered in `RestRouter`; CORS regression for both.

## 7. SPA

- **`/security` route** (`routes/Security.tsx`, mirror `Monitoring.tsx`): a **KPI summary strip** (Sites at risk · Critical · High · Scanned X/Y) + a **site-centric table** — columns: Site (label + muted url, `<Link>` to `/sites/{id}`) · Findings (severity-count chips: red crit/high, amber medium, slate low; `✓ clean` chip for zero-findings-scanned; `not yet scanned` muted for null scan time) · Total · Scanned (relative time via the existing `parseUtc`/relative helper). Rows already arrive status-sorted from the API. Header carries the **"Scan all sites"** button. States: loading, error, **empty** (`total_sites === 0` → "No sites yet"), **all-clear** (sites exist, `sites_at_risk === 0` → green "No known vulnerabilities across the fleet" banner **above** the table; the table still lists every site so clean/never-scanned rows stay visible).
- **`SecurityNavLink`** (`components/nav/SecurityNavLink.tsx`) added to the Overview header beside `MonitoringNavLink`/`JobsNavLink`.
- **`ScanAllSitesSecurityButton`** + **`ConfirmScanAllSecurityDialog`** (neutral primary, P2.6 `ConfirmSyncAllDialog` style — NOT red; it's a benign read-scan). Button disabled when `total_sites === 0`. **`useScanAllSecurity`** mutation → POST `/security/scan-all`, on success invalidate `['security']` + surface a "Scan queued for N sites" message (no fleet-wide poll).
- **`useSecurity`** query (`lib/queries/useSecurity.ts`): GET `/security`, parse with Zod `securitySchema`, `staleTime: 30_000`. New `securitySchema` + `fleetSiteSecuritySchema` in `src/types/api.ts`. MSW handlers for both endpoints.

## 8. Error handling & edge cases

- **Empty fleet** (`total_sites === 0`): API returns zeroed summary + empty `sites`; SPA shows "No sites yet"; Scan-all button disabled; POST scan-all returns 200 no-op (no activity row).
- **All sites clean / never-scanned**: `sites_at_risk === 0`; table still lists every site (clean + never-scanned rows), with an all-clear banner. Never-scanned vs scanned-clean distinguished by `last_security_scan_at` (null vs set), rendered as different chips.
- **Feed/scan best-effort**: `scan-all` never throws — `refreshIfStale()` is best-effort (no-ops without a key); a missing AS function guards the loop (mirror P2.6).
- **`unknown`-severity findings**: counted in `total` (so the site is "at risk") but not in the 4 severity buckets; the per-site panel shows them under their own group.
- UTC throughout (`gmdate`). Rate-limit 429s mirror existing buckets.

## 9. Testing

**PHP (dashboard):**
- `findFleetSummariesForUser`: a user with (a) an at-risk site (mixed severities), (b) a scanned-clean site, (c) a never-scanned site, (d) a site owned by ANOTHER user (excluded) → correct per-site counts; clean/never-scanned both zero-count but distinguished by `last_security_scan_at`; no N+1 (single query). **Test isolation (guardrail #15):** `SiteVulnerabilitiesRepository::replaceForSite` uses explicit `COMMIT` that escapes `WP_UnitTestCase` rollback — purge `defyn_site_vulnerabilities` + `defyn_sites` in `setUp` (`freshlyActivate` + `SET autocommit=1` + `DELETE`). Seed sites via direct `$wpdb->insert` (no `SitesRepository::create()`).
- `SecurityService::compose`: summary aggregates (total_sites, scanned_sites, sites_at_risk, fleet severity sums); **sort order** (at-risk worst-first → clean → never-scanned); `generated_at` present.
- `SecurityController` (200 direct payload, 401 no-auth) + `RateLimit::security` 30/min bucket.
- `SecurityScanAllController`: 202 + scheduled_count + one `SecurityScan` action per schedulable site (assert via `as_next_scheduled_action`); 200 no-op + ZERO activity rows when 0 sites; exactly ONE `security.scan_all_requested` row when count > 0; `RateLimit::securityScanAll` 5/hr bucket; CORS regression for both routes.

**SPA (apps/web, Node 22):**
- `securitySchema`/`fleetSiteSecuritySchema` parse a fleet payload (at-risk + clean + never-scanned rows); `useSecurity`/`useScanAllSecurity`; `Security.tsx` render states (table with severity chips / clean chip / not-yet-scanned chip / empty "No sites yet" / all-clear banner); the confirm dialog fires the POST. Carry-forward tolerated: the documented 4 (SiteDetail×2 + SiteCoreCard×2); full route suite green under Node 22 (render-loop lesson).

## 10. Release

- Dashboard version bump **v0.13.0 → v0.14.0**; CORS note for the 2 new routes.
- **Connector unchanged** (v0.1.7) — no zip rebuild, no re-handshake. **Schema unchanged (v11)** — no migration, no version-pin test bumps.
- Symfony-preserving zip (top-level `dashboard-plugin/` folder; the `halaxa/json-machine` prod dep from P4.1 stays); SPA auto-deploys via Cloudflare from `main`.
- Production smoke (API curl only; login JWT field is **`access_token`**): `GET /security` 200 direct payload (`total_sites`, `summary`, `sites:[]` at zero-sites — proves the endpoint live) + 401 no-auth; `POST /security/scan-all` 200 no-op at zero sites (+ 0 activity rows) / 429 rate-limit; `/security` SPA route 200 + deployed bundle has the new "Sites at risk"/"Scan all sites"/"No sites yet" strings. Happy populated path (202 + at-risk table) foreclosed by the zero-sites prod state (covered by green PHP/SPA tests).
- Tag `p4-2-security-fleet-complete`. (Next: **P4.3** — alerting on new findings + per-finding ignore/dismiss + per-site mute.)

## 11. Guardrails (plan-bug traps to surface in the plan)

1. **No schema change, no connector change** — `findFleetSummariesForUser` reads existing P4.1 tables; version-pin tests stay at v11 (do NOT bump).
2. `findFleetSummariesForUser` is **one GROUP BY query** (conditional SUMs), never N+1; returns ALL the user's sites incl. clean + never-scanned; ownership-scoped via `s.user_id`.
3. `compose` **sort order** is the contract: at-risk (worst-severity first) → scanned-clean → never-scanned. Never-scanned vs clean is `last_security_scan_at` null vs set.
4. `GET /security` is a **direct payload** (no `{data}` envelope), mirroring `/monitoring` — NOT the site-scoped enveloped shape.
5. `POST /security/scan-all` emits the fleet activity event **only when scheduled_count > 0** (no-op = no activity noise, P2.6 guardrail); 202 when sites > 0 / 200 when 0.
6. `scan-all` calls `refreshIfStale()` **once** before the fan-out (not per-site); best-effort, never throws; missing-AS guard on the loop.
7. Rate buckets: read **30/min** (`security`), write **5/hr** (`securityScanAll`) — distinct from P4.1's per-site `siteVulnerabilities`/`securityScan`.
8. Scan-all confirm dialog is **neutral** (not red) — it's a benign read-only scan, not a destructive update.
9. No fleet-wide live poll; Scan-all invalidates `['security']` + toasts. UTC everywhere; full SPA route suite green under Node 22.
