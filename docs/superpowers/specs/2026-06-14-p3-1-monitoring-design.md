# P3.1 — Site Monitoring: Incidents + Email Alerts — Design

**Status:** Approved (brainstorm 2026-06-14)
**Phase:** P3.1 — first slice of Phase 3 (Monitoring). Roadmap: Monitoring → Security scanning → Reporting (no Backups).
**Branch:** `p3-1-monitoring` (off `main` @ c599457)
**Footprint:** Dashboard plugin + SPA only. **Connector unchanged** (v0.1.7). Schema **v7 → v8**.

---

## 1. Goal

Turn the foundation's existing-but-silent heartbeat loop into an actionable uptime-monitoring slice: **record confirmed downtime as incidents**, **email the operator** when a site goes down and when it recovers, and **surface incidents in the SPA** (a fleet rollup on the Overview + a per-site history panel on Site detail).

## 2. What already exists (reused, not rebuilt)

The foundation runs a recurring health loop every 5 minutes:

- `Jobs\Scheduler` schedules `HealthPingAll::HOOK` every 300s.
- `Jobs\HealthPingAll` fans out one `Jobs\HealthPing` per site.
- `Services\HealthService::ping(siteId)` does a **signed** GET to the connector's `/heartbeat`. On success it calls `SitesRepository::markContactAt` (or `markRecovered` if the site was `offline`); on any failure (transport / non-2xx / decrypt) it calls `markOffline($id, $message)`. Every branch already writes an activity event: `site.health_ok`, `site.recovered`, or `site.health_failed`.
- The Site model already carries `status` (`active`/`offline`), `lastContactAt`, `lastError`, `sslStatus`, `sslExpiresAt`.
- `SitesRepository::findSitesNeedingAttention` already reads 15-min-offline + 30-day-SSL **passively** for the Overview.

P3.1 **layers incident + alert logic onto the existing fail/success branches of `HealthService::ping()`** — it does not add a new pinger or touch the connector.

## 3. Scope

**In scope (P3.1):**
- Confirmed-downtime **incidents** persisted as history (open/close lifecycle).
- **Email** alert on incident open and on incident close (one per edge).
- **Overview** "Open incidents" rollup widget.
- **Site detail** "Incident history" panel.

**Out of scope (later slices, P3.2+):**
- Slack / other channels (the `Notifier` interface makes this a drop-in).
- Uptime-% charts and per-check time-series history.
- Response-time / latency capture.
- SSL-expiry alerting (a different signal; passive Overview surfacing already exists).
- A dedicated `/monitoring` fleet route.
- Per-site monitoring mute / configurable recipient UI.

## 4. Detection & the confirm-down state machine

Checks run every 5 min via the existing loop. To avoid alerting on a single transient blip, a downtime is **confirmed after 2 consecutive failed checks** (~10 min).

New per-site counter: `wp_defyn_sites.consecutive_failures INT DEFAULT 0`.

State transitions, applied inside `HealthService::ping()` after the existing status persistence:

- **Failed ping:** `consecutive_failures++`. If `consecutive_failures >= 2` **and** the site has no open incident → **open an incident** (`started_at = now`, `last_error = <message>`) and **send the down alert**.
- **Successful ping:** if the site has an open incident → **close it** (`ended_at = now`, `duration_seconds = ended_at − started_at`) and **send the recovered alert**. Then set `consecutive_failures = 0`.

Notes:
- The site's instantaneous `status` (`active`/`offline`) keeps flipping on the **first** fail/success exactly as today — the live status chip stays responsive. The **incident** is the confirmed, de-noised layer that drives alerts and history.
- **Exactly one** down email per incident and one recovered email per incident — never repeated while the site stays down (`down_alert_sent_at` / `up_alert_sent_at` guard against re-send).
- A single failure followed by a success (1 fail, then recover) → counter resets, **no incident, no email**.
- Flap (confirmed down → up → confirmed down) = two separate incidents.
- A site already `offline` when P3.1 deploys is **not** backfilled; its first confirmed failure after deploy opens the first incident.

## 5. Data model

### New table `wp_defyn_incidents`

| column | type | notes |
|---|---|---|
| `id` | BIGINT UNSIGNED PK AUTO_INCREMENT | |
| `site_id` | BIGINT UNSIGNED, FK → `wp_defyn_sites.id` ON DELETE CASCADE | |
| `started_at` | DATETIME | when the incident opened (confirmed down) |
| `ended_at` | DATETIME NULL | NULL while open |
| `duration_seconds` | INT UNSIGNED NULL | NULL while open; set on close |
| `last_error` | TEXT | the failure message that opened the incident; set at open, not updated during the outage |
| `down_alert_sent_at` | DATETIME NULL | stamp once the down email is sent |
| `up_alert_sent_at` | DATETIME NULL | stamp once the recovered email is sent |
| `created_at` | DATETIME | |

Indexes: `idx_incidents_site (site_id, started_at)`, partial-open lookups via `WHERE ended_at IS NULL`. **At most one open incident per site** (enforced in app logic: only open when none open).

### Altered table `wp_defyn_sites`

- Add `consecutive_failures INT NOT NULL DEFAULT 0`.

### Schema migration v7 → v8

Add the `wp_defyn_incidents` table + the `consecutive_failures` column via the established **schema self-heal** mechanism (runs on `plugins_loaded`, throttled). `Uninstaller` drops the new table. No connector schema change.

## 6. Components

### `Services\IncidentService` (new)

Pure-ish orchestration of the state machine, called by `HealthService`:
- `recordFailure(Site $site, string $message): void` — increments counter; opens incident + alerts on the 2nd consecutive failure.
- `recordSuccess(Site $site): void` — closes any open incident + alerts; resets counter.

Depends on `IncidentsRepository`, `SitesRepository` (counter), `Notifier`, `ActivityLogger`. `final`; constructor-injectable deps with production defaults (mirrors `HealthService`).

### `Services\IncidentsRepository` (new)

`findOpenForSite(int $siteId): ?Incident`, `open(int $siteId, string $startedAt, string $error): int`, `close(int $incidentId, string $endedAt, int $durationSeconds): void`, `markDownAlertSent(int $id, string $at)`, `markUpAlertSent(int $id, string $at)`, `findForSite(int $siteId, int $limit, int $offset): array`, `findOpenForUser(int $userId): array` (Overview rollup, JOIN sites for label/url). Immutable `Models\Incident` DTO with `fromRow`/`toJson`.

### `Notify\Notifier` (interface) + `Notify\EmailNotifier` (new)

```
interface Notifier {
    public function notifyDown(Site $site, Incident $incident): void;
    public function notifyRecovered(Site $site, Incident $incident): void;
}
```

`EmailNotifier` uses `wp_mail`. **Recipient = the site owner's user email** (`get_userdata($site->userId)->user_email`). Subject: `🔴 {label} is down` / `✅ {label} recovered — down {duration}`. Body: site label + URL, started (+ ended/duration for recovery), last error, link to the site in the SPA. Email is **best-effort**: a `wp_mail` returning false is logged but never throws into the ping loop; the incident record is the source of truth.

### `HealthService` integration

In the existing fail branches, after `markOffline`, call `$incidents->recordFailure($site, $message)`. In the success branches, after `markRecovered`/`markContactAt`, call `$incidents->recordSuccess($site)`. The existing `site.health_*` activity events stay; incidents add `site.incident_opened` / `site.incident_closed`.

## 7. Activity log

Two new event types, emitted by `IncidentService` (not per-ping):
- `site.incident_opened` — `details: { incident_id, started_at, error }`
- `site.incident_closed` — `details: { incident_id, duration_seconds }`

These surface in the Overview recent-activity tail and a site's activity.

## 8. REST API

- `GET /defyn/v1/sites/{id}/incidents?limit&offset` — paginated incident history for a site (ownership-checked). 30/min bucket (read).
- `/defyn/v1/overview` response gains `open_incidents: [{ site_id, site_label, started_at }]` (from `findOpenForUser`). No new endpoint — additive field on the existing Overview payload.

## 9. SPA

- **`IncidentHistoryPanel`** on Site detail: lists incidents — ongoing one highlighted (🔴 started X, duration ticking), closed ones with start→end + duration. Empty state "No incidents recorded." `useSiteIncidents(siteId)` query hook + Zod `incidentSchema` + MSW handler.
- **`OpenIncidentsWidget`** on Overview: red rollup when `open_incidents.length > 0` ("N sites down" + per-site "down Xm since"); hidden when empty. Reads the extended `/overview` payload (extend the existing `overviewSchema` + `useOverview`).
- Patterns mirror existing panels/widgets (SitePluginsPanel, SitesNeedingAttentionWidget). React Query, no new polling beyond the Overview's existing cadence + SiteDetail's existing refetch.

## 10. Error handling & edge cases

- **wp_mail failure:** logged (activity or error_log), incident still recorded; alert stamp left null so a future transition can retry conceptually (P3.1 does not auto-retry — best-effort, documented).
- **Site deleted mid-incident:** incident rows cascade-delete (FK) + uninstall drops the table.
- **Concurrent pings:** AS runs jobs serially per hook; `open` only fires when `findOpenForSite` returns null, so no double-open.
- **Clock:** all timestamps UTC (`UTC_TIMESTAMP()` / gmdate), consistent with existing repos.
- **Counter drift:** `recordSuccess` always resets to 0, self-healing any stuck counter.

## 11. Testing

**PHP (dashboard):**
- `IncidentService` state machine: opens on 2nd consecutive failure (not 1st); does not double-open; closes + resets on success; counter increments/reset; alert send invoked once per edge.
- `IncidentsRepository`: open/close/find/markAlertSent SQL, single-open invariant, `findOpenForUser` JOIN.
- `EmailNotifier`: subject/body composition + recipient resolution (wp_mail mocked); best-effort on failure.
- `HealthService` integration: fail/success branches call the right `IncidentService` method.
- Schema v8 migration (table + column present); Uninstaller drops the table.
- `SitesIncidentsController` (200/401/404/ownership + pagination) + RateLimit bucket; `/overview` `open_incidents` integration.

**SPA:**
- Zod `incidentSchema` + extended `overviewSchema`; MSW handlers.
- `useSiteIncidents` hook; `IncidentHistoryPanel` render states (ongoing / closed / empty); `OpenIncidentsWidget` (down / hidden-when-empty).

Carry-forward tolerated: the 4 documented SiteDetail×2 + SiteCoreCard×2 SPA failures. Suite runs under Node 22/24 (pinned) — full route suite must run green (the P2.10 render-loop lesson: do not dismiss a hanging test as environment).

## 12. Release

- Dashboard version bump (v0.9.0 → **v0.10.0**); CORS regression note for the one new route.
- **Connector unchanged** (v0.1.7) — no zip rebuild, no re-handshake.
- Build dashboard zip with the **symfony-preserving** exclusion list (memory gotcha); install via WP Admin "Replace current"; clear Kinsta cache (OPcache/Redis) post-install.
- SPA auto-deploys via Cloudflare Pages from `main`.
- Production smoke: schema v8 migration confirmed; `GET /sites/{id}/incidents` envelope + 401 + 404; `/overview` carries `open_incidents`; deployed bundle contains the new component strings. Happy down→email→recover path verified by synthetic failure if a site is reachable; email send verified via wp_mail log.
- Tag `p3-1-monitoring-complete`.

## 13. Guardrails (plan-bug traps to surface in the plan)

1. Confirm-down threshold is **2 consecutive failures** — open only on the 2nd, never the 1st.
2. **One email per edge** — guard with `down_alert_sent_at`/`up_alert_sent_at`; never re-send while down.
3. Keep the existing instantaneous `status` flip untouched; incidents are a separate, additive layer.
4. `recordSuccess` always resets `consecutive_failures` to 0 (self-heal).
5. Single open incident per site — `open` only when `findOpenForSite` is null.
6. Email is **best-effort** — never throw into `HealthService::ping()` / the AS loop.
7. Recipient = **site owner's** user email, not a hardcoded address.
8. `OpenIncidentsWidget` is **hidden** when there are no open incidents (not rendered empty).
9. UTC everywhere; `duration_seconds` computed on close only.
10. Schema via self-heal (v8); Uninstaller drops `wp_defyn_incidents`; FK cascade on site delete.
11. Connector is NOT touched — no version bump, no zip, no re-handshake.
