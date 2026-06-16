# P5.3 — Client Report Queue — Design

**Date:** 2026-06-16
**Phase:** Reporting (Phase 5), slice 3 — **closes the locked roadmap** (Monitoring → Security → Reporting; no Backups).
**Status:** approved design, pre-plan.

## Goal

Give the operator a ManageWP-style **client-report queue**: branded maintenance-report PDFs are **generated and stored** (monthly on a schedule + on demand), listed per site with date/title/size/status, **emailed to the client when the operator chooses to send**, and **deleted manually** afterwards. Reports are *created and held ready to send* — the system never auto-sends.

## Scope

- **In:** persistent `Report` entity (stored PDF snapshot per site); async generation via Action Scheduler (manual + recurring monthly); per-site list + download + send + delete; per-site stored client email (editable at send); branded email with the PDF attached.
- **Out / non-goals:** auto-send (operator always clicks Send); auto-prune/retention jobs (delete is manual only); a fleet-wide reports page (per-site only this slice); report templates / custom sections / GA4 + PageSpeed data (deferred-forever-unless-asked); connector changes (none).
- **One cohesive slice** (~20 tasks, comparable to P2.9). Dashboard-plugin only. **Schema v12 → v13.** Connector unchanged (v0.1.7).

## Reuse (already shipped this phase)

- `Services\ReportService::compose(siteId, userId, fromUtc, toUtc): array` (P5.1) — the report payload.
- `Services\ReportPdfService::render(report, branding): string` (P5.2) — payload → branded PDF bytes.
- `Services\BrandingService::get(userId): array` (P5.2) — agency name / accent / logo.
- `Rest\Support\ReportRange::resolve(?from, ?to): array` + `InvalidReportRange` (P5.2) — date validation.
- The P5.2 binary `emit()` download seam (header+echo+exit, overridable in tests).
- The `Jobs\SslCheckAll`→`Jobs\SslCheck` recurring daily fan-out + the `Activation::maybeRunSelfHeal` ensure-scheduled guard pattern (P3.3 / P4.1).
- The `wp_mail`/`Notify\EmailNotifier` email path (P3.1).
- The P3.3 per-site setting pattern (mute / allow-major rows + tiny POST endpoints).

## Architecture

A `Report` row represents one generated, stored PDF snapshot for a site over a date range. Generation is **always async** through Action Scheduler so manual and scheduled paths are identical and never risk a request timeout:

```
create report row (status=generating)
   └─> AS job GenerateReport[reportId]
          ReportService::compose → BrandingService::get → ReportPdfService::render
          → ReportStorage::store (random-named file) → markReady(file_name,size)
          (Throwable → markFailed(error))   # never throws into the AS loop
operator clicks Send → ReportMailer (wp_mail + attachment) → markSent(recipient, sent_at)
operator clicks Delete → ReportStorage::delete(file) + ReportsRepository::delete(row)
```

The **monthly** recurring job `GenerateMonthlyReportsAll` runs ~every 30 days; on each run it computes each schedulable site's **previous calendar month** and, if no report already exists for that site+month (dedup), creates a row + enqueues `GenerateReport`. The 30-day interval only needs to fire ≥once per month; the per-month dedup + calendar-month range keep it calendar-correct.

## Data model (schema v13)

### New table `wp_defyn_reports`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED PK AUTO_INCREMENT | |
| `site_id` | BIGINT UNSIGNED, indexed | owning site (ownership resolved via `defyn_sites`) |
| `title` | VARCHAR(160) | default `Website Maintenance Report` |
| `range_from` | DATE | reported period start |
| `range_to` | DATE | reported period end |
| `status` | VARCHAR(20) | `generating` \| `ready` \| `failed` \| `sent` |
| `file_name` | VARCHAR(255) NULL | random stored basename; **NULL until rendered; NEVER serialized to JSON** |
| `file_size` | INT UNSIGNED NULL | bytes; NULL until rendered |
| `recipient_email` | VARCHAR(255) NULL | set on send |
| `error_message` | TEXT NULL | set on failed |
| `generated_at` | DATETIME NULL | render completion (UTC) |
| `sent_at` | DATETIME NULL | send time (UTC) |
| `created_at` | DATETIME | row insert (UTC) |

Index: `idx_reports_site_created (site_id, created_at)` for the newest-first list query.

### New column on `wp_defyn_sites`

- `client_email VARCHAR(255) NULL` — the per-site default report recipient. Guarded idempotent ALTER in `maybeRunSelfHeal` (same shape as the P3.3 `alerts_muted` add). Surfaced on `Site::toJson()` (and `siteSchema` in the SPA) so the Send dialog can pre-fill it.

### Migration

- `Activation::SCHEMA_VERSION` 12 → 13; add `wp_defyn_reports` to `TABLES`; create-table via dbDelta; guarded `ALTER TABLE ... ADD COLUMN client_email`. The `assertSame(12, Activation::SCHEMA_VERSION)` pins across the schema test files bump to 13 (the same multi-file ripple P4.3b absorbed on v11→v12 — count them, don't assume one line).
- `wp_defyn_reports` uses plain dbDelta + `$wpdb->insert` (NO explicit `START TRANSACTION`/`COMMIT`) → standard `WP_UnitTestCase` rollback applies; report rows roll back cleanly between tests. Tests that seed `defyn_sites` still apply the guardrail-#15 purge for that table.

### PDF storage

- Directory `wp-content/uploads/defyn-reports/` (created on demand, 0755).
- Filename `report-{id}-{token}.pdf` where `token = wp_generate_password(32, false, false)` (random, unguessable, `[A-Za-z0-9]`).
- Served **only** through the auth + ownership-gated download endpoint; the public uploads URL is **never** emitted anywhere (not in JSON, not in the SPA).
- Best-effort `.htaccess` (`Deny from all`) + `index.html` written once into the dir for Apache hosts. On Kinsta/nginx `.htaccess` is ignored — the random filename + authed-only serving is the real protection. A maintenance report is low-sensitivity (update/uptime/security-scan summaries; no credentials, no PII), so this posture is acceptable.
- Uninstaller recursively deletes `defyn-reports/` (in addition to dropping the table).

## Domain & services

- `Models\Report` — immutable readonly DTO. `toJson(): array` emits `{id, site_id, title, range_from, range_to, status, file_size, recipient_email, generated_at, sent_at, created_at}`. **`file_name` is server-only and never serialized.**
- `Services\ReportsRepository`:
  - `create(int $siteId, string $title, string $from, string $to, string $now): int` — inserts a `generating` row, returns id.
  - `findForSite(int $siteId, int $limit, int $offset): Report[]` + `countForSite(int $siteId): int` (newest first).
  - `findByIdForSite(int $reportId, int $siteId): ?Report`.
  - `markReady(int $id, string $fileName, int $size, string $generatedAt): void`.
  - `markFailed(int $id, string $error): void`.
  - `markSent(int $id, string $recipient, string $sentAt): void`.
  - `delete(int $id): void` (row only; the controller/job deletes the file via `ReportStorage`).
  - `existsForSiteAndMonth(int $siteId, string $from, string $to): bool` — dedup for the monthly job (match on `site_id, range_from, range_to`).
- `Services\ReportStorage`:
  - `store(int $reportId, string $bytes): array{file_name:string, size:int}` — ensures the dir, writes a random-named file, returns name + size.
  - `path(string $fileName): string`, `read(string $fileName): ?string`, `delete(string $fileName): void`, `ensureDir(): void` (mkdir + best-effort deny guards).
  - Centralizes all filesystem access (jobs/controllers never touch the FS directly).
- `Services\ReportMailer`:
  - `send(string $to, string $subject, string $body, string $attachmentPath): bool` — wraps `wp_mail` with the attachment; injectable seam (default `wp_mail`, overridable in tests). Returns the `wp_mail` boolean.

## Jobs

- `Jobs\GenerateReport` — AS single action, hook `defyn_generate_report`, arg `[reportId]`. Loads the report row + resolves the site + owner; `ReportService::compose(siteId, ownerId, range_from 00:00:00, range_to 23:59:59)` → `BrandingService::get(ownerId)` → `ReportPdfService::render` → `ReportStorage::store` → `markReady`. Any `Throwable` → `markFailed($e->getMessage())` (never rethrows). Emits `report.generated` / `report.generation_failed` activity (`details: {report_id, range_from, range_to}`). If the report row is gone (deleted before the job ran), no-op.
- `Jobs\GenerateMonthlyReportsAll` — AS recurring action, hook `defyn_generate_monthly_reports_all`, interval `MONTH_IN_SECONDS`. For each site from `SitesRepository::findAllSchedulable()` (system cron → whole fleet, same as `SecurityScanAll`): compute the **previous calendar month** `[first-day, last-day]` (UTC); if `existsForSiteAndMonth` is false, `ReportsRepository::create(...)` + `as_enqueue_async_action(defyn_generate_report, [reportId], 'defyn')`. **Tenant safety:** the monthly job is a system cron and each report is owned-by-site, so generating every site's report is correct; there is no per-operator "generate all" endpoint in this slice, so no cross-tenant surface exists.
- Both hooks registered in `Plugin::boot`. `Activation::maybeRunSelfHeal` gains an ensure-scheduled guard keyed on `GenerateMonthlyReportsAll::HOOK` (installs the recurring schedule on silent upgrade — the existing SSL/security guards do not cover a brand-new hook).

## REST endpoints

All under `defyn/v1`, all `RequireAuth` + ownership-gated (404 `sites.not_found` **before** any work; report-scoped 404 `reports.not_found`), all CORS-tested.

1. **POST `/sites/{id}/reports`** — body `{from?, to?}` validated via `ReportRange::resolve` (→ 400 `report.invalid_range` / `report.range_too_large`). Creates a `generating` row + `as_enqueue_async_action`. **202** `{data:{report}, error:null}`. `RateLimit::reportsGenerate` 10/hr.
2. **GET `/sites/{id}/reports?page=`** — paginated (newest first, per_page e.g. 20). **200** `{data:{reports:[…toJson], total, page, per_page}, error:null}`. `RateLimit::reportsList` 30/min.
3. **GET `/sites/{id}/reports/{report_id}/download`** — binary download via the P5.2 `emit()` seam (read the stored file). 404 `reports.not_found` if missing/unowned; 409 `reports.not_ready` if status ∉ {ready, sent} or the file is absent. Filename `Website-Maintenance-Report-{host}-{range_from}-to-{range_to}.pdf`. `RateLimit::reportsDownload` 30/min.
4. **POST `/sites/{id}/reports/{report_id}/send`** — body `{recipient_email, note?}`. `is_email` (→ 400 `reports.invalid_recipient`); status must be `ready`|`sent` (→ 400 `reports.not_sendable`); `ReportMailer::send` with the stored PDF; on true → `markSent(recipient, now)` + emit `report.sent` → **200** `{data:{report}, error:null}`; on false → **502** `reports.send_failed` (status unchanged). `RateLimit::reportsSend` 10/hr.
5. **DELETE `/sites/{id}/reports/{report_id}`** — `ReportStorage::delete(file_name)` (if any) + `ReportsRepository::delete` + emit `report.deleted` → **200** `{data:{deleted:true}, error:null}`. `RateLimit::reportsDelete` 30/hr.
6. **POST `/sites/{id}/client-email`** — body `{client_email}` (`is_email` or empty-to-clear → 400 `sites.invalid_client_email`). Sets `wp_defyn_sites.client_email`. Mirrors the P3.3 mute / allow-major per-site setting controllers. **200** `{data:{client_email}, error:null}`. `RateLimit::clientEmail` 10/hr.

## Email

- Subject: `{agency} — Website Maintenance Report ({range_from} – {range_to})` (agency from `BrandingService`).
- Body: a short branded message — e.g. *"Please find attached the website maintenance report for {site host}, covering {range_from} – {range_to}."* — with the optional operator `note` prepended, signed off with `{agency}`. From-name = agency; from-address = WP default (`wp_mail` default). HTML-escape any interpolated values.
- Attachment: the stored PDF (`ReportStorage::path(file_name)`).
- The operator's explicit Send click is the authorization for the outbound email.

## SPA (per-site, on Site detail)

- New `components/reports/SiteReportsPanel.tsx` mounted on `routes/SiteDetail.tsx` below the existing panels. Header: a **Generate report** button (opens `GenerateReportDialog` — range presets + custom, reusing `lib/reportRange.ts`) and an inline **Client email** field (set/clear the per-site default recipient).
- Table: Date (`created_at`) · Title · Size (`file_size` formatted, `—` when null) · Status badge · row actions Download / Send / Delete. Download + Send disabled unless status ∈ {ready, sent}.
- `ReportStatusBadge` — generating (amber + spinner icon), ready (blue), sent (green), failed (red).
- `SendReportDialog` — recipient pre-filled from `client_email`, editable + `is_email`-validated client-side; optional note; confirm → POST send.
- `DeleteReportDialog` — neutral confirm (not red).
- `useSiteReports(siteId)` — list query; **polls every 5s only while any report is `generating`**, stops when all terminal (TanStack v5 `(query) => …` `refetchInterval` signature, like the P2.9 jobs hooks). Mutations: `useGenerateReport`, `useSendReport`, `useDeleteReport`, `useSetClientEmail` (invalidate `['siteReports', siteId]` / `['site', siteId]`). Download via `apiClient.getBlob` + a `downloadStoredReport(siteId, reportId, filename)` helper (P5.2 object-URL `<a download>` pattern).
- The P5.1 on-screen **Report** preview/print button stays — the queue is the new persistent layer; the preview is the live view.
- Render-loop guard (P2.10): any seed effects key on **primitive** fields, never object refs; the list derives via the query ref + `useMemo`.

## Error handling & security

- Generation failure → `failed` status + `error_message`, surfaced as a red badge; operator deletes + regenerates. The AS job try/catches → `markFailed`, never throwing into the fan-out (so one bad site can't break the monthly run).
- Private files: random filename, authed-only serving, path never emitted, best-effort Apache deny, uninstall cleanup.
- Email: operator-triggered; recipient `is_email`-validated; `wp_mail` failure surfaced (502), status stays `ready`.
- Ownership-404 runs before any work; report-scoped 404 `reports.not_found`; per-action rate limits.
- No schema-leak: `file_name` is server-only.

## Testing

- **PHP:** `ReportsRepository` (create/find/list+count/lifecycle marks/delete/dedup); `ReportStorage` (store writes a random-named file + returns size, read, delete, ensureDir); `GenerateReport` (success → ready + file present against a seeded site with real `ReportService`/`ReportPdfService`; a throwing render → failed + error_message, no throw); `GenerateMonthlyReportsAll` (creates previous-calendar-month rows per schedulable site + dedup-skips an existing month); the 6 controllers (auth 401 / ownership 404 / report 404 / validation 400 / rate-limit 429 / CORS — download success via an `emit()`-overriding subclass capturing `%PDF-` bytes; send success via an injected `wp_mail` seam asserting the attachment path + `markSent`); schema v13 migration + the version-pin bumps; uninstall drops the table **and** the directory.
- **SPA:** `useSiteReports` poll-starts-while-generating / poll-stops-when-terminal; generate/send/delete/setClientEmail mutations; `SiteReportsPanel` states (empty / generating / ready / sent / failed); `SendReportDialog` recipient validation + pre-fill; `ReportStatusBadge`.
- Carry-forward tolerances unchanged: PHP `UninstallTest`; SPA 4 (`SiteDetail` ×2 + `SiteCoreCard` ×2).

## Guardrails

1. **Generation is always async** (manual + scheduled use the same `GenerateReport` job) — no synchronous render in a request.
2. **`file_name` is server-only** — never in `toJson`, never in the SPA, never a public URL.
3. **AS job never throws into the loop** — `GenerateReport` try/catches → `markFailed`; one bad site can't break the monthly fan-out.
4. **Monthly = previous calendar month + dedup** (`existsForSiteAndMonth`) — calendar-correct despite the 30-day interval.
5. **Self-heal ensure-scheduled guard keyed on the NEW hook** — `GenerateMonthlyReportsAll::HOOK` (existing SSL/security guards don't cover it).
6. **Ownership-404 before any work; report-scoped 404**; per-action rate limits; CORS-tested.
7. **Send is operator-triggered only** — no auto-send; recipient validated; `wp_mail` failure surfaced, status unchanged.
8. **No connector change, no version-pin undercount** — count the `SCHEMA_VERSION` assertions across the schema test files when bumping 12→13.
9. **Uninstaller wipes the file directory** in addition to dropping the table.
10. **Render-loop guard** — SPA seed effects key on primitives (P2.10).

## Release

Dashboard `v0.18.0` → `v0.19.0`. dompdf already shipped (P5.2) — the zip-build verify list is unchanged (symfony + json-machine + dompdf). After all tasks: full PHP + SPA suites green, `pnpm build`, dompdf-preserving zip → `dist/defyn-dashboard-0.19.0.zip`, merge to main, **manual Kinsta install** (schema v13 self-heals on first load), indirect curl smoke (generate 202 / list 200 / download+send+delete 404 paths / client-email 400 invalid / ownership 401-404), tag `p5-3-client-report-queue-complete`, MEMORY. **NEXT after this = Reporting phase CLOSED; the whole locked roadmap is done.**
