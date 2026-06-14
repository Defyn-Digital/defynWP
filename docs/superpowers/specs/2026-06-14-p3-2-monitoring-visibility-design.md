# P3.2 — Monitoring: Performance & Uptime Visibility — Design

**Status:** Approved (brainstorm 2026-06-14)
**Phase:** P3.2 — second slice of Phase 3 (Monitoring). Roadmap: Monitoring → Security scanning → Reporting (no Backups). Follows P3.1 (Incidents + Email Alerts, shipped — tag `p3-1-monitoring-complete`).
**Branch:** `p3-2-monitoring-visibility` (off `main` @ 0d17099)
**Footprint:** Dashboard plugin + SPA only. **Connector unchanged** (v0.1.7). Schema **v8 → v9**. Dashboard **v0.10.0 → v0.11.0**.

---

## 1. Goal

Make the monitoring data the foundation already collects **visible and historical**: capture per-ping response latency, derive per-site uptime-% from the existing incident history, and present both on a dedicated **`/monitoring`** fleet page (summary KPI strip + dense per-site table), with each row drilling into the existing Site detail page.

## 2. What already exists (reused, not rebuilt)

- The 5-minute heartbeat loop: `Jobs\Scheduler` → `Jobs\HealthPingAll` → `Jobs\HealthPing` → `Services\HealthService::ping($siteId)`, which does a **signed GET** to the connector's `/heartbeat` (`SignedHttpClient::signedGet`) and flips `status` via `SitesRepository::markContactAt` / `markRecovered` / `markOffline`.
- P3.1's `wp_defyn_incidents` table (confirmed-downtime history: `started_at`, `ended_at` NULL while open, `duration_seconds`) — **this is the source for uptime-%.**
- `Services\IncidentsRepository`, `Models\Incident`, the `Models\Site` DTO (`status`, `lastContactAt`).
- The dedicated-route pattern from P2.9: `JobsListController` + the SPA `/jobs` route + `JobsNavLink` in the Overview header.
- `Rest\Middleware\RateLimit` per-minute buckets (e.g. `overview` = 30/min); schema self-heal on `plugins_loaded` (`Activation::SCHEMA_VERSION`, `TABLES`, guarded ALTER helpers).

P3.2 **layers latency capture onto the existing ping path and adds one read-only endpoint + one SPA page.** It does not add a pinger, a job, or a connector route.

## 3. Scope

**In scope (P3.2):**
- Per-ping **response-latency capture** → `wp_defyn_sites.last_response_time_ms` (current value only).
- **Uptime-%** over **7-day and 30-day** windows, derived on-the-fly from `wp_defyn_incidents`.
- A new **`GET /defyn/v1/monitoring`** endpoint (fleet summary + per-site rows).
- A dedicated **`/monitoring` SPA route**: summary KPI strip + dense table, rows linking to `/sites/{id}`.
- A **`MonitoringNavLink`** in the Overview header (additive; the P3.1 `OpenIncidentsWidget` on Overview stays).

**Out of scope (later — P3.3 / beyond):**
- Slack / other alert channels, SSL-expiry alerting, per-site mute/config (all P3.3).
- Latency **history** / trend charts (only the current value is kept; a rollup or per-ping-sample table is a future slice if wanted).
- Any connector change. Any new background job.

## 4. Latency capture

In `HealthService::ping()`, wrap the single `signedGet` call (currently line 79) with a monotonic timer:

```php
$startedAt  = microtime(true);
$response   = $this->httpClient->signedGet($url, $privateKey, $canonicalPath);
$elapsedMs  = (int) round((microtime(true) - $startedAt) * 1000);
```

- **Success branches** (the `status === 'offline'` → `markRecovered` path and the else → `markContactAt` path): persist `$elapsedMs`.
- **Every failure branch** (missing key, decrypt failure, transport `error`, non-2xx): persist `NULL` — a down or unreachable site never shows a stale latency.

Persistence is a **new, dedicated** repository call so the existing status-flip methods stay byte-for-byte unchanged (guardrail 3):

```php
SitesRepository::recordResponseTime(int $siteId, ?int $ms): void
```

invoked exactly once per ping (the measured value on success, `null` on failure). One extra UPDATE per site per 5 minutes — negligible.

Latency is the **dashboard-measured round-trip** of the existing signed heartbeat (dashboard → connector → back). No connector change.

## 5. Data model — schema v8 → v9

One additive column on `wp_defyn_sites`:

| column | type | notes |
|---|---|---|
| `last_response_time_ms` | INT UNSIGNED NULL | last measured heartbeat round-trip in ms; NULL when the last ping failed or the site was never pinged |

Applied via the established self-heal: bump `Activation::SCHEMA_VERSION` 8 → 9 and add a guarded `addResponseTimeColumn()` helper mirroring `addConsecutiveFailuresColumn()` (idempotent `SHOW COLUMNS` check before `ALTER TABLE … ADD`). No new table. Uninstaller unaffected (column drops with the table). No connector schema change.

## 6. Uptime computation (pure + testable)

Uptime is **derived from incidents**, never stored.

New `Services\MonitoringService` with a **pure** helper:

```php
public static function uptimePercent(array $incidents, int $windowStartTs, int $nowTs): float
```

For each incident, downtime contribution within the window is:

> `max(0, min(endedTs ?? nowTs, nowTs) − max(startedTs, windowStartTs))`

`uptime% = (1 − Σ contributions / (nowTs − windowStartTs)) × 100`, clamped to `[0, 100]`, rounded to 2 dp.

- **No incidents → 100%.**
- An **open incident** (ended_at NULL) counts downtime up to "now" on each request (ticks naturally).
- An incident that started **before** the window only counts its in-window portion.

`MonitoringService` fetches, in **one** query via a new `IncidentsRepository::findForUserSince(int $userId, string $sinceUtc): array` (incidents for all the user's sites that ended within, or remain open since, the last 30 days), groups by `site_id` in PHP, and computes both 7d and 30d for each site. No N+1.

## 7. REST API — one new endpoint

`GET /defyn/v1/monitoring` — **30/min** bucket (`RateLimit::monitoring`, mirrors `overview`'s per-minute cadence), JWT-authenticated, scoped to the operator's sites. Composed by `MonitoringService` from `SitesRepository::findAllForUser` + `IncidentsRepository::findForUserSince`.

```jsonc
{
  "summary": {
    "total": 14,
    "up": 13,                         // status === 'active'
    "down": 1,                        // status === 'offline'
    "fleet_uptime_30d": 99.71,        // mean of per-site uptime_30d (null if total === 0)
    "slowest_ms": 910                 // max(last_response_time_ms) across sites; null if none recorded
  },
  "sites": [
    {
      "site_id": 2,
      "label": "SmartCoding",
      "url": "https://…",
      "status": "offline",            // existing Site.status enum: pending|active|offline|error
      "last_response_time_ms": null,
      "last_contact_at": "2026-06-14T03:11:00Z",
      "uptime_7d": 97.10,
      "uptime_30d": 99.40,
      "open_incident_started_at": "2026-06-14T03:23:00Z"  // null when no open incident; drives "down Xm"
    }
  ],
  "generated_at": "2026-06-14T03:35:00Z"
}
```

All timestamps UTC (`gmdate`). No `/overview` change, no connector route.

## 8. SPA — dedicated `/monitoring` route

- **Types:** `monitoringSchema` (Zod) covering the payload above; `Monitoring` / `MonitoringSite` types. Defensive defaults on nullable numeric fields. MSW handler for tests.
- **Query hook:** `useMonitoring()` — `['monitoring']` key, `staleTime`/refetch ≈ 30s (mirrors `useOverview`); no new polling machinery.
- **Components:**
  - `MonitoringPage` (route under `RequireAuth` in `App.tsx`): header (title + back-to-Overview), then strip, then table; loading + error + empty (`No sites yet`) states.
  - `MonitoringSummaryStrip` — 4 KPI tiles: **Up**, **Down**, **Fleet 30d**, **Slowest**. Down tile red when `down > 0`.
  - `MonitoringTable` + `MonitoringRow` — columns **Status · Site · Latency · Uptime 7d · Uptime 30d · Last check**. Status dot from `status` (active=green, offline=red, pending/error=neutral/amber). Latency colour from SPA threshold constants `LATENCY_GOOD_MS = 300`, `LATENCY_WARN_MS = 800` (good < 300 ≤ warn < 800 ≤ bad; `null` renders "—" as bad). *Last check* shows "down Xm" derived from `open_incident_started_at` when present, else a relative `last_contact_at`. Each row is a `<Link to={\`/sites/${site_id}\`}>`.
- **Navigation:** `MonitoringNavLink` added to the Overview header beside the existing **Jobs** link. The P3.1 `OpenIncidentsWidget` on Overview is **unchanged**.

A small pure helper `formatUptime(pct: number): string` and a `latencyTone(ms: number | null)` classifier are unit-tested.

## 9. Error handling & edge cases

- **Latency timing never throws into the ping loop** — plain arithmetic around the existing call; a failed ping yields `null`, not an exception.
- **Brand-new site never pinged** → `last_response_time_ms` null, `status` `pending`, no incidents → uptime 100%. Renders cleanly.
- **Open incident** → uptime ticks down to "now" each request; *Last check* shows "down Xm".
- **`total === 0`** → `fleet_uptime_30d` and `slowest_ms` null; SPA shows the empty state.
- **UTC throughout** (`gmdate`, integer timestamps for the pure function), consistent with P3.1.
- **No deploy-ordering parse risk** like P3.1 — `/monitoring` is its own page hitting its own new endpoint, so an old backend simply 404s the route until the v0.11.0 dashboard is installed; the SPA shows its error state rather than corrupting `/overview`. `monitoringSchema` still defaults nullable numerics defensively.

## 10. Testing

**PHP (dashboard):**
- `MonitoringService::uptimePercent` pure-function table: no incidents (100%) / one fully-in-window closed incident / open-incident-ticks-to-now / incident starting before window (partial) / multiple overlapping / zero-length window guard.
- `MonitoringService` composition: `up`/`down`/`total` counts, `fleet_uptime_30d` mean, `slowest_ms` max (nulls excluded), per-site 7d+30d, `open_incident_started_at` mapping.
- `IncidentsRepository::findForUserSince` query (sites JOIN, since-bound, open-incident inclusion).
- `SitesRepository::recordResponseTime` (sets int on success, null on failure) + schema v9 column present + guarded ALTER idempotent.
- `HealthService`: latency persisted on both success branches, null on each failure branch, **and existing status flips + `site.health_*` + incident calls byte-for-byte unchanged** (existing HealthService/Incident tests must stay green).
- `MonitoringController`: 200 envelope + 401 no-auth + 30/min bucket; CORS regression for the new route.

**SPA (apps/web):**
- `monitoringSchema` parse (full + nullable-field) ; `useMonitoring` hook.
- `MonitoringSummaryStrip` (down-tile red when down>0, null fleet/slowest), `MonitoringRow` render states (up / down with "down Xm" / slow latency / null latency "—"), `MonitoringPage` empty state, `latencyTone` + `formatUptime` helpers.
- Carry-forward tolerated: the 4 documented SiteDetail×2 + SiteCoreCard×2 failures. Full route suite runs green under Node 22 (the P2.10 render-loop lesson: a hanging test is a real bug, not the environment).

## 11. Release

- Dashboard version bump **v0.10.0 → v0.11.0**; CORS regression note for the one new route.
- **Connector unchanged** (v0.1.7) — no zip rebuild, no re-handshake.
- Build dashboard zip with the **symfony-preserving** exclusion list (memory gotcha: `composer install --no-dev --classmap-authoritative` first; exclude only tests/dev tooling, never any `vendor/*` subdir; verify the zip contains `vendor/symfony/deprecation-contracts/function.php` + `vendor/symfony/polyfill-php83/bootstrap.php`). Install via WP Admin "Replace current"; clear Kinsta cache (OPcache/Redis) post-install (manual user step).
- SPA auto-deploys via Cloudflare Pages from `main`.
- Production smoke (API curl only — UI login is the user's manual step): schema v9 migration confirmed (`GET /monitoring` valid envelope), `GET /monitoring` 200 + 401 no-auth + 30/min 429, deployed bundle contains the new component strings, `/monitoring` route serves 200.
- Tag `p3-2-monitoring-visibility-complete`.

## 12. Guardrails (plan-bug traps to surface in the plan)

1. Latency is captured by timing the **existing** `signedGet` call — do **not** add a second HTTP request.
2. Persist latency via a **new dedicated** `recordResponseTime` method; **do not** modify `markContactAt` / `markRecovered` / `markOffline` (status-flip semantics + `site.health_*` events stay untouched).
3. Latency is `null` on **every** failure branch (and for never-pinged sites) — never a stale value for a down site.
4. Uptime is **derived from incidents**, never stored; `uptimePercent` is a **pure** function (UTC integer timestamps) with no DB access.
5. No incidents → **100%**; an **open** incident counts downtime up to "now"; an incident starting before the window counts only its in-window portion; clamp to `[0,100]`.
6. One **single** incidents query per request grouped in PHP — **no N+1** over sites.
7. New endpoint is **read-only**, 30/**min** bucket (`RateLimit::monitoring`), ownership-scoped; mirrors `overview`.
8. `/monitoring` is **additive** — the P3.1 `OpenIncidentsWidget` on Overview is not removed; only a nav link is added.
9. `total === 0` → `fleet_uptime_30d` and `slowest_ms` are `null`; SPA renders the empty state (no divide-by-zero).
10. Schema via self-heal (v9, guarded idempotent ALTER); Uninstaller needs no change (column-only).
11. **Connector NOT touched** — no version bump, no zip, no re-handshake.
12. UTC everywhere; full SPA route suite must run green under Node 22 (render-loop lesson).
