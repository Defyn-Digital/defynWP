# P5.4 — Automated Scheduled Report-Send — Design Spec

**Date:** 2026-06-17
**Phase:** Reporting (P5.x) — slice 4, operator's-choice work after Phase 6 closed
**Status:** Approved (brainstorm complete, ready for implementation plan)
**Branch:** `p5-4-auto-send-reports` (off `main` @ 1860e8d, tag `p6-2-ga4-analytics-complete`)
**Dashboard version:** v0.21.0 → **v0.22.0**
**Connector:** UNCHANGED (v0.1.7) — entirely dashboard-side
**Schema:** v15 → **v16** (two guarded ALTERs: `wp_defyn_sites.auto_send_reports` + `wp_defyn_reports.sent_method`)

---

## 1. Goal

Close the P5.3 reporting loop. Today P5.3 **generates** branded PDF reports per site — manual create + a recurring `Jobs\GenerateMonthlyReportsAll` (MONTH_IN_SECONDS) that creates + enqueues a `Jobs\GenerateReport` per schedulable site, producing stored `ready` reports — but the operator still **sends** each one by hand. P5.4 adds **auto-send on generation completion**: an opted-in site with a client email gets its report emailed automatically, zero manual steps. The "set and forget" client-reporting feature.

Auto-send is **outward-facing** (it emails clients), so it is **explicit per-site opt-in, OFF by default**, with the operator in control.

---

## 2. Opt-in model (per-site toggle)

- New column **`wp_defyn_sites.auto_send_reports TINYINT(1) NOT NULL DEFAULT 0`** — OFF by default. Guarded idempotent ALTER mirroring P5.3's `client_email` / P6.2's `ga4_property_id`.
- `Models\Site` gains a readonly `autoSendReports: bool` prop (+ `fromRow` cast `(bool) (int)` + `toJson` key `auto_send_reports`).
- `Services\SitesRepository::setAutoSendReports(int $siteId, bool $on): void`.
- **A site auto-sends only when BOTH `auto_send_reports` is ON AND `client_email` is a valid non-empty email.** A missing recipient is a silent skip (generate-only), never an error.

Chosen over a global operator default (a single switch that emails every client at once is a footgun) and a global+per-site hybrid (more complexity than a first slice needs).

---

## 3. Fire path (chain after `markReady`)

`Jobs\GenerateReport::handle(int $reportId)` currently: load row → resolve site → (try) compose → render → store → `markReady` → log `report.generated` (catch → `markFailed` + `report.generation_failed`, never rethrows).

**P5.4 adds a best-effort auto-send block AFTER the existing try/catch completes** (so generation success is already committed and a send failure cannot undo it):

```
after handle()'s existing logic, if the report ended up `ready`:
  reload the Report (now status=ready, fileName set) + the already-loaded Site
  if site.autoSendReports AND is_email(site.clientEmail):
    try:
      ReportSendService::send(report, site, site.clientEmail, note=null, method='auto')
    catch Throwable:
      swallow — the report stays `ready`; operator can still send manually
```

- **No new job, no new recurring schedule, no 6th self-heal guard.** The monthly `GenerateMonthlyReportsAll` already produces the `ready` reports; a manual "Generate" on an opted-in site flows through the same `GenerateReport` handler.
- Fires for **any** report that finishes generating on an opted-in site (monthly cron **and** a manual one-off). Semantics: "auto-send ON for this site = this client's generated reports get emailed."
- `GenerateReport` gains a 4th injected dep `?ReportSendService $sender = null` (default `new ReportSendService()`) so tests drive the chain without real mail. It also needs to read the auto-send flag from the loaded `$site` (already loaded in `handle`).
- **Idempotency:** the send only runs in the single `GenerateReport` execution that just marked the report `ready`. `GenerateMonthlyReportsAll` already dedups by `existsForSiteAndMonth`, so a month's report is generated (and thus auto-sent) once. `markSent` flips status to `sent`; `ReportSendService` only sends reports whose status is `ready`/`sent` with a stored file (same guard as the manual controller) — a re-entrant call on an already-`sent` report is a no-op at the caller level (the chain only fires on the fresh `ready`).

---

## 4. Shared `Services\ReportSendService` (DRY extraction)

Today `Rest\SitesReportSendController::handle` builds the email inline (subject/body via `BrandingService`, attachment path via `ReportStorage::path`, send via `ReportMailer::send`, then `markSent` + `report.sent` event). To prevent manual/auto drift, **extract that into `Services\ReportSendService`**:

```php
final class ReportSendService {
    public function __construct(private readonly ?ReportMailer $mailer = null, ...) {}

    /** Builds the branded email, sends the stored PDF, marks the report sent, logs the event.
     *  @param 'manual'|'auto' $method
     *  @return bool  true on wp_mail success (report marked sent); false leaves status unchanged. */
    public function send(Report $report, Site $site, string $to, ?string $note, string $method): bool;
}
```

- Subject/body identical to today's manual flow (`{agency} — Website Maintenance Report ({from} – {to})` + optional note + standard body + agency signature). Auto-send passes `note = null`.
- On `wp_mail` success: `ReportsRepository::markSent($report->id, $to, $now, $method)` + `ActivityLogger::log($site->userId, $site->id, $event, ['report_id'=>…, 'recipient'=>$to])` where **`$event` = `report.sent` (manual) / `report.auto_sent` (auto)**.
- On failure: returns `false`, status unchanged (stays `ready`) — the manual controller maps that to its existing 502; the auto chain swallows it.
- **`SitesReportSendController` is refactored to call `ReportSendService::send(…, method='manual')`** and keep its exact current behavior (200 / 502 / 400 / 404, `report.sent` event). Its existing P5.3 tests must stay green.

---

## 5. Distinguishing auto vs manual (distinct badge)

- New column **`wp_defyn_reports.sent_method VARCHAR(10) NULL`** — `'manual'` | `'auto'` | NULL (never-sent). Same v16 migration as the site toggle.
- `ReportsRepository::markSent` extended to `markSent(int $id, string $recipient, string $sentAt, string $method = 'manual')` — backward-compatible default keeps any other caller as manual; it now also writes `sent_method`.
- `Models\Report` gains `sentMethod: ?string` (+ `fromRow` + `toJson` key `sent_method`). `file_name` stays server-only (never serialized — P5.3 guardrail).
- SPA report status badge gains a violet **"Auto-sent"** variant rendered when `report.sent_method === 'auto'` (else `'sent'` → green "Sent"). Recipient subtext on an auto row reads "to {email} · auto".

---

## 6. REST endpoint + SPA

- **`POST /defyn/v1/sites/{id}/auto-send`** → `Rest\SitesAutoSendController` (mirrors P5.3's `SitesClientEmailController`): ownership-404 `sites.not_found` first; body `{auto_send: bool}` (strict boolean parse); `setAutoSendReports`; returns `{data:{auto_send_reports: bool}, error:null}`. RateLimit bucket **`autoSend` 10/HR** (key `defyn_rl_autoSend_%d_%d`, 429 `sites.rate_limited`, mirroring the client-email bucket). CORS-tested + route-resolution-tested (404 not `rest_no_route`).
- SPA `SiteReportsPanel` (the P5.3 per-site reports panel — verify the real path) gains the **auto-send toggle** beside the client-email field: reads `site.auto_send_reports`, flips via a new `useSetAutoSend(siteId)` mutation (invalidates `['site', siteId]` / however the site detail query is keyed). The site Zod schema gains `auto_send_reports: z.boolean()`. The report Zod schema (`reportSchema`, api.ts:233 — the P5.3 stored-queue entity, NOT `siteReportSchema`) gains `sent_method: z.string().nullable()`. The existing `useSiteReports` poll already surfaces the status flip to `sent` — **no new poll, no bounded-poll hook**.

---

## 7. Error handling (best-effort, never breaks generation)

- No recipient (client_email empty/invalid) → **skip** the send; report stays `ready`; no error, no event.
- `wp_mail` failure → `ReportSendService::send` returns false; the auto chain swallows it; report stays `ready`; the operator can manually send/retry (the existing manual path).
- A throwing mailer/seam inside the auto chain is caught in `GenerateReport` → swallowed; generation already committed.
- The `sent` status + the chain-fires-once-on-`ready` invariant prevent double-send.
- One `report.auto_sent` activity event per successful auto-send.

---

## 8. Testing

- `ReportSendServiceTest` — builds the email + sends via an injected mailer seam; success → `markSent(method)` + the correct event (`report.sent` vs `report.auto_sent`); failure → returns false, status unchanged. No real mail.
- `GenerateReportTest` (extend) — opted-in + valid client_email → auto-sends (report `sent`, `sent_method='auto'`, `report.auto_sent` logged); opted-in + no client_email → skip (stays `ready`, no event); NOT opted-in → skip; send-failure → stays `ready`, NO throw out of the job; generation-failure path unchanged (no auto-send attempted).
- `SitesReportSendControllerTest` (P5.3) — stays green after the `ReportSendService` refactor (200/502/400/404, `report.sent`).
- `SitesAutoSendTest` + `AutoSendCorsTest` — controller ownership-404 + toggle persists + invalid-body 400; CORS + route-resolution.
- `RateLimitAutoSendTest` — `autoSend` 10/HR → 429 `sites.rate_limited`; missing-auth → 401.
- Schema test — v16, both columns exist; version-pin ripple bumped to 16.
- SPA — `SiteReportsPanel` toggle (reads flag, fires mutation) + the "Auto-sent" badge variant; `pnpm build` tsc-clean.
- Carry-forward unchanged: PHP `UninstallTest` only; SPA the 4 (`SiteDetail`×2 + `SiteCoreCard`×2).

---

## 9. Versioning & release

- Dashboard **v0.21.0 → v0.22.0** (header line + `DEFYN_DASHBOARD_VERSION`).
- Connector **UNCHANGED (v0.1.7)**.
- dompdf-preserving zip — verify list unchanged: symfony `deprecation-contracts/function.php` + `polyfill-php83/bootstrap.php` (2) + json-machine `Items.php` (≥1) + dompdf `Dompdf.php` (≥1) + `src/Schema/SitePerformanceTable.php` (≥1) + `src/Schema/SiteAnalyticsTable.php` (≥1).
- Merge to main → Cloudflare auto-deploys SPA. **Manual Kinsta install** of `dist/defyn-dashboard-0.22.0.zip` (schema v16 self-heals).
- Indirect curl smoke: `POST /sites/1/auto-send` no-auth → 401; `POST /sites/999999/auto-send` auth → 404 `sites.not_found` (proves route + v16 live); bogus route → `rest.route_not_found`; deployed SPA bundle has "Auto-send" / "Auto-sent". **Happy auto-send path foreclosed by zero-sites prod + opt-in-OFF default** — auto-send stays inert in prod, so the smoke never emails a real client (consistent with the safety posture).
- Tag `p5-4-auto-send-complete`; record to MEMORY; **NEXT = operator's choice (no locked phases remain).**

---

## 10. Out of scope (YAGNI / deferred)

- Global operator default for auto-send (per-site only).
- Monthly-only-vs-any-generation distinction (auto-send ON = every generated report for that site is emailed).
- Automatic send-retry queue (a failed auto-send stays `ready` for manual retry).
- Per-site custom email templates / scheduling cadence (cadence stays the existing monthly job).
- A fleet-wide auto-send toggle or `/reports` rollup page.

---

## 11. Reused surfaces (verified during explore)

- **Whole P5.3 report queue:** `wp_defyn_reports` (schema v13), `Models\Report`, `Services\ReportsRepository` (`markReady`/`markSent`/`markFailed`/`findByIdForSite`/`existsForSiteAndMonth`), `Services\ReportStorage::path`, `Services\ReportMailer::send` (wp_mail+attachment seam, returns bool).
- **`Jobs\GenerateReport`** (compose→render→store→markReady, Throwable→markFailed never-rethrows) — the chain point. **`Jobs\GenerateMonthlyReportsAll`** (MONTH_IN_SECONDS, dedup) — already exists, NO new recurring job.
- **`Rest\SitesReportSendController`** — the inline email-build to extract into `ReportSendService`.
- **Per-site recipient:** `wp_defyn_sites.client_email` + `Site->clientEmail` + `SitesClientEmailController` (P5.3) — the exact template for `auto_send_reports` + `SitesAutoSendController`.
- **`BrandingService::get`**, **`ActivityLogger::log(?userId,?siteId,event,?details,?ip)`**, the guarded-ALTER + 5-guard self-heal + version-pin ripple, per-action RateLimit, CORS + route-resolution test convention, the dompdf-preserving zip-build.
