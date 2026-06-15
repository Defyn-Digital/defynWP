# P5.2 — Server-side Branded PDF + White-label Config — Design

**Status:** Approved 2026-06-15
**Phase:** 5 (Reporting) — second slice (after P5.1 Maintenance Report MVP). NEXT-after = P5.3 (scheduled monthly email). NO Backups (locked).
**Branch:** `p5-2-branded-pdf` off `main` (tip `8bfda7b`, P5.1 merged).
**Versions:** dashboard `v0.17.0` → `v0.18.0`; connector **unchanged** (`v0.1.7`); **schema unchanged** (v12 — branding lives in `user_meta`, WP-native, no table).

---

## 1. Goal

Turn the P5.1 on-screen report into a **real one-click PDF download** the operator sends the client, and make it **white-label** — per-operator agency name, accent colour, and logo, with a branded cover page. The PDF is generated **server-side** so the next slice (P5.3 scheduled email) can reuse the exact generator to attach the PDF — building it client-side now would be throwaway.

## 2. Approved decisions (from brainstorm)

| Decision | Choice |
| --- | --- |
| PDF generation | **Server-side PHP via `dompdf/dompdf`** — a new `GET /sites/{id}/report.pdf` renders an HTML template → PDF. Forward-compatible with P5.3's email attachment. |
| Branding config | **Per-operator, in `user_meta`**, surfaced on the existing P3.3 `/settings` page (agency-wide, applies to every client report). Replaces the hardcoded `'Defyn Digital'` constant as the source of truth; the constant becomes the fallback. |
| Logo | **Operator pastes an `https://` logo URL.** We fetch + inline it (SSRF-safe — see §5); dompdf never fetches remote URLs. |
| Cover page | **Yes** — a branded cover page + content pages (matches the Cuscal sample). |

## 3. Architecture

`GET /sites/{id}/report.pdf?from&to` → ownership + date validation (shared with P5.1's JSON endpoint) → `ReportService::compose` (reused, the same payload) → load branding from `user_meta` → `ReportPdfService::render(report, branding)` → return the PDF bytes as an attachment. Branding is read/written through the existing `SettingsController` + a new `BrandingService`. The SPA gains a "Download PDF" button (auth'd blob fetch) and a "Report branding" settings card. No new tables.

Build order (risky infra first): dompdf dep + `ReportPdfService` + HTML template + the binary endpoint (using the existing constant branding) → then `BrandingService` + settings GET/POST + the settings UI + wiring the PDF to read branding.

## 4. PDF engine — `Services\ReportPdfService`

```
render(array $report, array $branding): string   // returns raw PDF bytes
```
- New composer dependency **`dompdf/dompdf`** (`^3.0`). dompdf options: A4 portrait, `isRemoteEnabled = false` (we inline images ourselves), default DejaVu font (bundled, unicode-safe).
- Builds an HTML string from `$report` (the `ReportService::compose` payload) + `$branding` (`{agency_name, accent_color, logo_url_or_inlined}`):
  - **Cover page** — a full-height block with `page-break-after: always`: logo (if present), "Website Maintenance Report", `report.site.url`, the `period.from – period.to`, agency name, an accent-coloured band.
  - **Content pages** — a branded header strip (logo mark + agency name + site + period) atop the four sections (Overview stat cards · Updates table · Uptime cards + incidents · Security findings + scan history), styled with inline CSS + the accent colour. A small footer ("Prepared by {agency}").
- The HTML template lives in a dedicated method (or `templates/report-pdf-html.php`) — the plugin's first HTML surface; keep it isolated + escaped (`esc_html`/`esc_url` on all interpolated report values — the report contains user/site-derived strings like plugin names + incident reasons).
- **Zip-build:** after `composer install --no-dev --classmap-authoritative`, dompdf + its transitive deps (`sabberworm/php-css-parser`, `masterminds/html5`, `phenx/php-font-lib`, `phenx/php-svg-lib`) land in `vendor/` and **must be preserved** — the existing "exclude only tests/dev tooling, NEVER `vendor/*`" rule covers this. The release verify-grep gains a `dompdf/src/Dompdf.php` presence check; zip size grows (~+2–3 MB).

## 5. SSRF-safe logo handling

The operator-supplied `logo_url` is **never handed to dompdf** (`isRemoteEnabled` stays false). Instead, when rendering, `ReportPdfService` (or a small `LogoFetcher` helper) fetches it itself:
- `wp_remote_get($logoUrl, ['redirection' => 0, 'timeout' => 5])` — `https://` only (validated at save time + re-checked here), no redirects (so the URL can't bounce to an internal host).
- Cap the body at **~512 KB**; require `Content-Type` ∈ `{image/png, image/jpeg, image/gif}` (no SVG — XML/script risk).
- Base64-embed as a `data:` URI in the HTML `<img src>`.
- **Best-effort:** any failure (non-image, too large, fetch error, timeout) → render the PDF **without** the logo (never break the document). Logged at most to `error_log`.

## 6. Branding config — `Services\BrandingService` over `user_meta`

Three keys (per WP user / operator):
- `defyn_report_agency_name` (string)
- `defyn_report_accent_color` (string, `#RRGGBB`)
- `defyn_report_logo_url` (string, `https://…` or empty)

```
BrandingService::get(int $userId): array    // {agency_name, accent_color, logo_url} with constant defaults applied
BrandingService::set(int $userId, array $partial): void
```
Defaults when unset: `agency_name = 'Defyn Digital'`, `accent_color = '#26215C'`, `logo_url = ''` (matching the current `reportBranding.ts` constants).

### Settings endpoints (extend P3.3 `SettingsController`)
- `GET /defyn/v1/settings` (existing, `handleGet`, `RateLimit::settings` 30/min) — **also** returns `report_branding: {agency_name, accent_color, logo_url}` alongside the existing `slack_webhook_url`.
- `POST /defyn/v1/settings/report-branding` (new, `handleSetBranding`, `RateLimit::settingsWrite`) — body `{agency_name?, accent_color?, logo_url?}`. Validation:
  - `agency_name` — trim, `sanitize_text_field`, ≤ 100 chars (else 400 `settings.invalid_branding`); empty → reset to default.
  - `accent_color` — strict `/^#[0-9a-fA-F]{6}$/` (else 400 `settings.invalid_branding`); empty → reset to default.
  - `logo_url` — empty (clear) or `https://` URL ≤ 500 chars (else 400 `settings.invalid_branding`).
  - Stores via `BrandingService::set`, logs `settings.report_branding_updated` (`{cleared_logo: bool}` only — no values that could be PII). Returns the updated `report_branding`.

## 7. Binary download endpoint — `Rest\SitesReportPdfController`

`GET /defyn/v1/sites/(?P<id>\d+)/report.pdf` (note: register the `.pdf` route carefully — WP REST route regex; the path segment is literal `report.pdf`).
- Ownership-gated 404 `sites.not_found` (before work).
- Date validation **shared with P5.1** — extract P5.1's `SitesReportController` date logic into a small reusable `ReportRange` helper (default trailing-30d, strict `YYYY-MM-DD`, `from<=to`, span ≤ 366d → the same `report.invalid_range` / `report.range_too_large` 400s). Both controllers use it (DRY — removes the duplication a copy would create).
- Compose via `ReportService`, load branding via `BrandingService::get($userId)`, render via `ReportPdfService::render`.
- Return the bytes as a download: set `Content-Type: application/pdf` + `Content-Disposition: attachment; filename="maintenance-report-{sanitized-host}-{from}-{to}.pdf"`, echo the bytes, `exit` — the standard WP pattern for serving binary from a REST callback (bypasses REST's JSON serialization), done **only after** auth/ownership/validation pass. (Errors before that still return the normal JSON `ErrorResponse`.)
- `RateLimit::siteReportPdf` — **10/MINUTE** per (user, site), key `defyn_rl_siteReportPdf_%d_%d`, 429 `report.rate_limited` (tighter than the 30/min JSON read — PDF render is heavier).
- Registered in `RestRouter`; CORS-tested.

## 8. SPA

### Settings — "Report branding" card
- `useReportBranding` query (reads `report_branding` from `GET /settings`) + `useSaveReportBranding` mutation (`POST /settings/report-branding`). Mirror the existing Slack-webhook card hooks.
- A card on the settings page: agency-name input, accent-colour input (`<input type="color">` or a hex text field), logo-URL input, with client-side validation mirroring the backend (hex, https) + a "Saved" confirmation. Seed inputs once from the query (the P3.3 primitive-keyed `useEffect` pattern to avoid the P2.10 loop).

### SiteReport — "Download PDF"
- A **"Download PDF"** button next to the existing **Print** button (keep both — Print for a quick local copy, Download PDF for the branded deliverable).
- New `lib/downloadReportPdf.ts`: `fetch` the `.pdf` endpoint **with the JWT `Authorization` header** (the SPA has no blob-download today — this is new), read `response.blob()`, `URL.createObjectURL`, create a temporary `<a download={filename}>`, click, revoke. Handle non-200 (show an error toast/message). Filename from the `Content-Disposition` or built client-side.
- The on-screen report keeps using the `reportBranding.ts` constants for its header (the configured branding affects the **PDF**; making the on-screen header read the configured branding too is a nice-to-have, optional this slice).

## 9. Testing

- **PHP `BrandingService`:** get returns defaults when unset; set persists; partial set leaves others; defaults applied.
- **PHP `SettingsController` branding:** GET includes `report_branding`; POST valid → 200 + stored + activity log; invalid hex / non-https / over-length → 400 `settings.invalid_branding`; empty resets to default.
- **PHP `ReportPdfService::render`:** returns non-empty bytes starting `%PDF-` from a sample `ReportService`-shaped payload + branding; renders successfully with `logo_url` empty; renders without the logo when the (mocked) fetch fails / returns non-image / oversize; HTML-escapes report-derived strings (a plugin name containing `<script>` doesn't break the doc).
- **PHP `SitesReportPdfController`:** 200 `application/pdf` + `Content-Disposition: attachment` for an owned site; 404 non-owned; 401 no-auth; 400 bad/oversized range (shared validator); 429 rate-limit. (Asserting binary from a REST `exit`-style download may need the test to capture output — if the harness can't intercept `exit`, refactor the controller so the byte-emitting + `exit` is a thin, separately-tested seam and the testable method returns `[headers, bytes]`.)
- **PHP `ReportRange` helper:** the extracted date validation (default range, from>to, malformed, too-large) — and the P5.1 `SitesReportController` still green after the refactor.
- **SPA:** branding card renders + saves (mutation called with the inputs) + client validation; `downloadReportPdf` fetches with auth + triggers the object-URL download (spy on `createObjectURL` + the anchor click). Carry-forward SPA 4.

## 10. Release

Dashboard `v0.18.0`; connector unchanged; schema v12 unchanged. `composer require dompdf/dompdf`. Symfony + json-machine + **dompdf**-preserving zip → repo-root `dist/defyn-dashboard-0.18.0.zip` (verify dompdf present + the existing symfony/json-machine checks; size ~+2–3 MB). SPA build + Cloudflare auto-deploy. Merge to main, manual Kinsta install + cache clear, indirect curl smoke (`GET /sites/1/report.pdf` no-auth 401; `GET /sites/999999/report.pdf` auth 404; `GET /settings` auth 200 includes `report_branding`; `POST /settings/report-branding` invalid hex 400 `settings.invalid_branding`). Tag `p5-2-branded-pdf-complete`, MEMORY. Happy PDF download foreclosed by zero-sites prod (covered by green tests). NEXT = P5.3 (scheduled monthly email reusing `ReportPdfService`).

## 11. Guardrails

1. **No schema/connector change** — branding in `user_meta`; PDF is read-only over composed data.
2. **dompdf `isRemoteEnabled = false`** — the operator's logo URL is fetched by US (validated https, no-redirect, size-capped, image-content-type-checked) and inlined as a data URI; never fetched by dompdf. Best-effort: logo failure never breaks the PDF.
3. **All report-derived strings escaped** in the HTML template (`esc_html`/`esc_url`) — plugin names, incident reasons, site URL.
4. **Shared date validator** (`ReportRange`) — the `.pdf` and JSON `/report` endpoints validate identically; no drift.
5. **Binary download only after auth/ownership/validation** — the `header()`+`echo`+`exit` path is reached solely on the success branch; all error branches return normal JSON.
6. **Branding values sanitized + bounded** — agency_name `sanitize_text_field` ≤ 100, accent strict `#RRGGBB`, logo_url https ≤ 500; `report_branding_updated` activity logs no values.
7. **PDF rate-limited 10/min** (`siteReportPdf`), ownership-gated, CORS-tested.
8. **Zip preserves dompdf** — verify in the release build, or the plugin fatals on the PDF endpoint.
