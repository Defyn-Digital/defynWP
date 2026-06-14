# P3.3 — Monitoring: Alerting Expansion & Config — Design

**Status:** Approved (brainstorm 2026-06-14)
**Phase:** P3.3 — third and **final** slice of Phase 3 (Monitoring). Completes Monitoring (P3.1 Incidents+Email + P3.2 Visibility already shipped). Roadmap: Monitoring → Security scanning → Reporting (no Backups).
**Branch:** `p3-3-monitoring-alerting` (off `main` @ the P3.2 merge)
**Footprint:** Dashboard plugin + SPA only. **Connector unchanged** (v0.1.7). Schema **v9 → v10**. Dashboard **v0.11.0 → v0.12.0**.

---

## 1. Goal

Round out monitoring alerting: add a **Slack** channel alongside email, add **proactive SSL-expiry alerts**, and give the operator **control** — a global Slack webhook + a per-site mute. Email behaviour is unchanged; everything here is additive.

## 2. What already exists (reused, not rebuilt)

- **P3.1 alerting:** `Notify\Notifier` interface (`notifyDown(Site,Incident)` / `notifyRecovered(Site,Incident)`, both `void`), `Notify\EmailNotifier` (resolves recipient via `get_userdata($site->userId)->user_email`; **best-effort** — `try { wp_mail } catch (Throwable) { error_log }`, never throws). `Services\IncidentService` injects the notifier (`?Notifier $notifier = null`, default `new EmailNotifier()`) and uses `safeNotify(callable): bool` to stamp `*_alert_sent_at` only on a successful send.
- **P2.4.1 per-site toggle:** `Rest\SitesCoreAllowMajorController::handle()` (ownership via `findByIdForUser` → 404; parse bool body; `SitesRepository::setCoreAllowMajor`; `ActivityLogger::log`; 200 `{site_id, …}`), `RateLimit::coreAllowMajor` (10/hr per user+site, key `defyn_rl_coreAllowMajor_%d_%d`), route `POST /sites/{id}/core/allow-major`. SPA `SiteMajorUpdatesSettingsRow` + `useToggleCoreAllowMajor` (shadcn `Switch`, invalidates `['sites', id]`).
- **SSL data:** `Site->sslStatus` + `Site->sslExpiresAt` (DATETIME UTC `Y-m-d H:i:s`), refreshed by `SyncService`→`SitesRepository::markSynced` from the connector `/status`. `findSitesNeedingAttention` already reads a **passive** 30-day SSL threshold for the Overview.
- **Fan-out jobs:** `Jobs\Scheduler::SCHEDULES` (recurring hooks → intervals), `Jobs\HealthPingAll` (loops `findAllSchedulable()`, schedules a per-site `HealthPing`). Template for a daily SSL check.
- **Schema self-heal:** `Activation::SCHEMA_VERSION` (=9), `TABLES`, guarded-ALTER helpers (e.g. `addResponseTimeColumn`). `ActivityLogger::log(?userId, ?siteId, eventType, ?details, ?ip)`.
- **No global settings surface exists yet** — no `/settings` route, no settings table, no `defyn_*` wp_options. P3.3 introduces the first one (operator config via **user_meta**, not a new table).

## 3. Scope

**In scope (P3.3):**
- **Slack notifier** — a 2nd `Notifier` impl + a `MultiNotifier` composite (email + Slack fan-out).
- **Proactive SSL-expiry alert** — a daily check, fires once at 14 days, de-duped, resets on renewal.
- **Global Slack webhook config** — per-operator (user_meta) + a new `/settings` SPA page.
- **Per-site mute** — one toggle on Site detail (incidents still recorded; notifications suppressed).

**Out of scope (deferred / YAGNI):**
- Per-channel selection UI (email is always on; Slack is additive when configured).
- Per-site channel/recipient override (mute only).
- Multiple SSL thresholds / escalation (single 14-day).
- Any connector change. SMS/PagerDuty/other channels.

## 4. Notifier interface + fan-out

Extend the P3.1 interface with a third method (the SSL signal isn't incident-shaped):

```php
interface Notifier {
    public function notifyDown(Site $site, Incident $incident): void;
    public function notifyRecovered(Site $site, Incident $incident): void;
    public function notifySslExpiring(Site $site, string $expiresAtUtc, int $daysLeft): void; // NEW
}
```

Both `EmailNotifier` and the new `Notify\SlackNotifier` implement all three.

- **`SlackNotifier`** resolves the **site owner's** webhook via `get_user_meta($site->userId, 'defyn_slack_webhook_url', true)`. If empty/falsy → **no-op** (the common pre-config case). Else builds a small JSON payload (text + a couple of context fields: label, URL, the relevant detail) and POSTs via `wp_remote_post($webhook, ['body' => wp_json_encode($payload), 'headers' => ['Content-Type'=>'application/json'], 'timeout'=>5])`. **Best-effort:** wrap in `try/catch (Throwable)` + a non-2xx check; log + return on failure; **never throw**.
- **`MultiNotifier`** (new) implements `Notifier`, constructor-injects an ordered list defaulting to `[new EmailNotifier(), new SlackNotifier()]`, and for each interface method calls every inner notifier in sequence, each wrapped so one channel's failure can't block another.
- **`IncidentService`** default notifier changes `new EmailNotifier()` → `new MultiNotifier()`. The `safeNotify`/stamp logic is **unchanged** (one `safeNotify` around the composite call).

## 5. Mute gate

`IncidentService` consults `site.alertsMuted` **before** each notify call: muted → skip `safeNotify(notifyDown/notifyRecovered)` entirely, but **still** open/close the incident, write `site.incident_opened`/`closed`, and reset the counter. Muting silences channels without losing history. The SSL job applies the same gate (stamp logic runs; send is skipped).

## 6. Data model — schema v9 → v10

Two additive columns on `wp_defyn_sites` via guarded ALTERs (mirror `addResponseTimeColumn`):

| column | type | notes |
|---|---|---|
| `alerts_muted` | TINYINT NOT NULL DEFAULT 0 | per-site mute (1 = no notifications) |
| `ssl_alert_sent_at` | DATETIME NULL | de-dup stamp; set when the 14-day SSL alert fires, cleared on renewal |

**The Slack webhook is per-operator `user_meta` (`defyn_slack_webhook_url`) — NOT a column, NOT a new table.** Bump `Activation::SCHEMA_VERSION` 9→10 + two guarded ALTER helpers + calls in `ensureSchema()`. Uninstaller unaffected (columns drop with the table); **also** `delete_user_meta` cleanup of `defyn_slack_webhook_url` is added to the Uninstaller for hygiene. `Site` DTO gains `alertsMuted` (bool) + `sslAlertSentAt` (?string) as final constructor params + `fromRow` mappings. Connector schema unchanged.

## 7. SSL-expiry alert — daily fan-out job

New `Jobs\SslCheckAll` (recurring, added to `Scheduler::SCHEDULES` at **86400**s) loops `findAllSchedulable()` and schedules a per-site `Jobs\SslCheck`. Both hooks registered in `Plugin::boot` like `HealthPingAll`/`HealthPing`.

`SslCheck::handle(int $siteId)` (via a new `Services\SslAlertService` for testability):
- Load the site. Compute `daysLeft` from `ssl_expires_at` (UTC) vs now.
- **Fire condition:** `ssl_expires_at` not null AND within 14 days AND `ssl_alert_sent_at` is null.
  - If site **not muted** → `notifySslExpiring($site, $expiresAt, $daysLeft)` via the same `MultiNotifier` (email + Slack), best-effort.
  - Either way (muted or not) → stamp `ssl_alert_sent_at = now`, emit `site.ssl_expiring` activity (`details: {expires_at, days_left}`). (Stamping while muted prevents a backlog firing on unmute.)
- **Reset condition:** `ssl_expires_at` is null OR beyond 14 days → if `ssl_alert_sent_at` is set, clear it (cert renewed/removed).
- Idempotent: same-day re-run with the stamp set is a no-op. UTC throughout.

`SitesRepository` gains `markSslAlertSent(int $id, string $nowUtc)` and `clearSslAlertSent(int $id)`. The 14-day threshold is a `SslAlertService::THRESHOLD_DAYS = 14` constant.

## 8. REST API — three new endpoints

- `GET /defyn/v1/settings` — **30/min** (`RateLimit::settings`). Returns `{ "slack_webhook_url": "https://hooks.slack.com/…"|null }` for the **current** operator (their own user_meta). The webhook is the operator's own secret scoped to them; returning it lets the field pre-fill.
- `POST /defyn/v1/settings/slack-webhook` — **10/hr** (`RateLimit::settingsWrite`). Body `{ "webhook_url": string }`. Empty string → clears (delete_user_meta). Non-empty must match `^https://hooks\.slack\.com/` (host allowlist — SSRF guard) else `400 settings.invalid_webhook`. `update_user_meta`, emit `settings.slack_webhook_updated` (**no URL in the log**), return `{ slack_webhook_url }`.
- `POST /defyn/v1/sites/{id}/alerts/mute` — **10/hr** (`RateLimit::alertsMute`, key `defyn_rl_alertsMute_%d_%d`). Body `{ "muted": bool }`, ownership-checked (404 `sites.not_found`), `SitesRepository::setAlertsMuted`, emit `site.alerts_muted`/`site.alerts_unmuted`, return `{ site_id, alerts_muted }`. **Exact mirror of `SitesCoreAllowMajorController`.**

`siteSchema` (the existing `GET /sites/{id}` + list payloads) gains `alerts_muted: bool` (additive; `Site::toJson` includes it). `ssl_alert_sent_at` stays internal (not surfaced).

## 9. SPA

- **New `/settings` route** under `RequireAuth` (`App.tsx`) + **`SettingsNavLink`** in the Overview header beside `MonitoringNavLink`. `routes/Settings.tsx` → a **Notifications** card: a Slack webhook URL `<input>` + Save button, the "✓ Email alerts always go to your account address" helper, client-side validation mirroring the backend (empty or `hooks.slack.com`). `useSettings()` query (`['settings']`) + `useSaveSlackWebhook()` mutation (invalidates `['settings']`). New `settingsSchema` (Zod) + MSW handler.
- **`SiteMuteAlertsSettingsRow`** on Site detail (clone of `SiteMajorUpdatesSettingsRow`): shadcn `Switch` bound to `site.alerts_muted`, label "Mute alerts for this site" + helper "Incidents & SSL are still tracked — no notifications are sent." `useToggleMuteAlerts(siteId)` mutation invalidates `['sites', id]`. `siteSchema` gains `alerts_muted: z.boolean()`.

## 10. Error handling & edge cases

- **Notify best-effort end-to-end:** `SlackNotifier` swallows `wp_remote_post`/non-2xx failures (logged, never thrown); `MultiNotifier` isolates each channel; a Slack outage never blocks email or the ping/SSL loop.
- **Empty webhook** → `SlackNotifier` no-ops cleanly.
- **Muted site** → incidents recorded + `ssl_alert_sent_at` stamped; only the send is skipped (unmute doesn't replay history).
- **SSL de-dup idempotent**; renewal clears the stamp; null `ssl_expires_at` is treated as "no alert" + clears any stamp.
- **SSRF guard:** webhook host allowlist (`hooks.slack.com`) enforced on write.
- **UTC everywhere** (`gmdate`).

## 11. Testing

**PHP (dashboard):**
- `SlackNotifier`: payload composition for down/recovery/ssl, owner-webhook resolution, **no-op when webhook empty**, best-effort on transport failure + non-2xx (`wp_remote_post` mocked via filter).
- `MultiNotifier`: calls every inner notifier; a throw in one does not stop the others (interface 3 methods).
- `EmailNotifier`: implements `notifySslExpiring` (subject/body).
- `IncidentService`: **mute gate** — muted site records the incident + events but invokes no notify; unmuted still notifies (existing P3.1 tests stay green).
- `SslAlertService`/`SslCheck`: fires once within 14d + stamps + activity; no-op when already stamped; clears stamp on renewal / null expiry; **skips send when muted but still stamps**.
- `SitesRepository`: `setAlertsMuted`, `markSslAlertSent`, `clearSslAlertSent`; `Site` DTO + `toJson` carry `alerts_muted`.
- `SettingsController` GET (current operator) + POST (host validation: accept `hooks.slack.com`, accept empty-clears, reject other host 400) + 401; `SitesAlertsMuteController` (200/401/404/ownership); `RateLimit::settings`/`settingsWrite`/`alertsMute` buckets; 3 CORS regressions; `Scheduler` registers the daily `SslCheckAll` hook; schema v10 columns + guarded ALTER idempotent + version-pin tests → 10.

**SPA (apps/web):**
- `settingsSchema` parse; `Settings` page (loads webhook, saves, rejects non-Slack host inline); `useSettings`/`useSaveSlackWebhook`; `SiteMuteAlertsSettingsRow` toggle; `SettingsNavLink`. MSW handlers. Carry-forward tolerated (SiteDetail×2 + SiteCoreCard×2); full route suite green under **Node 22** (render-loop lesson).

## 12. Release

- Dashboard **v0.11.0 → v0.12.0**; CORS note for the 3 new routes.
- **Connector unchanged** (v0.1.7) — no zip rebuild, no re-handshake.
- Schema v10 via self-heal; Uninstaller drops columns (table-level) + `delete_user_meta` cleanup.
- Symfony-preserving zip exclusion list. SPA auto-deploys via Cloudflare.
- Production smoke (API curl only; login JWT field is **`access_token`**): schema v10 (`GET /settings` 200 + 401); `POST /settings/slack-webhook` rejects a non-Slack host (400) + accepts empty-clear; `POST /sites/{id}/alerts/mute` 404 on non-owned; `/settings` SPA route 200 + deployed bundle has the new strings.
- Tag `p3-3-monitoring-alerting-complete`. **Monitoring phase COMPLETE** → next subsystem is Security scanning.

## 13. Guardrails (plan-bug traps to surface in the plan)

1. Slack/email both via the `Notify\Notifier` interface — add Slack as a **`MultiNotifier` composite**, do NOT change `IncidentService`'s notify/stamp flow beyond swapping the default notifier.
2. Every notifier is **best-effort** — `SlackNotifier` + `MultiNotifier` never throw into the ping/SSL loop.
3. `SlackNotifier` **no-ops** when the owner's webhook is empty; resolves the **site owner's** user_meta (not the current request user).
4. **Mute gate:** muted site still records incidents + SSL stamp + activity; only the send is suppressed.
5. **SSL alert fires once** per expiry episode (`ssl_alert_sent_at` guard); **clears on renewal**; idempotent same-day re-run.
6. SSL threshold = **14 days** (`THRESHOLD_DAYS` constant); distinct from the existing passive 30-day Overview flag (do not touch that).
7. Slack webhook lives in **per-operator user_meta** (`defyn_slack_webhook_url`) — NOT a column, NOT a global wp_option; **never logged**.
8. Webhook write enforces an **`hooks.slack.com` host allowlist** (SSRF guard); empty clears.
9. Schema via self-heal (v10, guarded idempotent ALTERs); Uninstaller adds `delete_user_meta` cleanup; version-pin tests → 10.
10. New daily `SslCheckAll`→`SslCheck` fan-out (86400s) mirrors `HealthPingAll`→`HealthPing`; registered in `Plugin::boot` + `Scheduler::SCHEDULES`.
11. Per-site mute endpoint is an **exact mirror** of `core/allow-major` (controller + repo setter + 10/hr bucket + SPA SettingsRow + toggle hook).
12. **Connector NOT touched** — no version bump, no zip, no re-handshake. UTC everywhere; full SPA route suite green under Node 22.
