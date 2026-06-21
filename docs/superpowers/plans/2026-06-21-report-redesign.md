# Client Report Redesign — Design + Implementation Plan

> **For agentic workers:** execute task-by-task with subagent-driven-development. Steps use `- [ ]`.

**Goal:** Redesign the per-site maintenance report (on-screen **and** branded PDF) to be cleaner and more client-ready, remove the hardcoded "Defyn Digital" branding, and personalise each report with the **client site's own logo**.

**Approved visual direction:** the inline mockup shown to the user — a navy cover band featuring the *site* (logo + name + period + "prepared" date), a KPI summary strip with status colours, and section cards with icon headers + status pills. The on-screen report uses the new Branded-Navy design tokens; the PDF mirrors the layout with print-safe HTML.

**Tech stack:** dashboard plugin (PHP 8.1, dompdf + php-svg-lib) + SPA (React/TS/Tailwind v4/shadcn, Vitest). Connector UNCHANGED (v0.1.7). Dashboard **v0.25.0 → v0.26.0**. **No schema change** (site logo is transient-cached, not a column). Ships via dashboard zip → Kinsta (manual install) + SPA → Cloudflare.

**Branch:** `report-redesign` (off main).

---

## Key decisions

1. **Per-site logo source = WordPress REST index.** `GET {siteUrl}/wp-json/` returns `site_icon_url` (core field since WP 5.9). The dashboard fetches it, caches the resolved URL in a transient (24h), and exposes it as `site.logo_url` in the report payload. No connector change, no HTML parsing, no DB column.
2. **Monogram fallback.** When a site has no icon (or fetch fails), both on-screen and PDF show a clean monogram — the site label's first letter on the accent colour — so the header is never blank.
3. **"Defyn Digital" removed everywhere.** The default agency name becomes empty. The agency name shows **only** if the operator has explicitly set one in Settings → Report Branding; otherwise the report is purely about the client site.
4. **PDF inlines the site logo** via the existing SSRF-safe `defaultLogoFetcher`/`validateLogoResponse` (HTTPS-only, image MIME, ≤512 KB) — reused for the site icon URL. Best-effort; null → monogram.

## Test-runner notes
- SPA: Node 22 via fnm; `pnpm test -- --run`; `pnpm build` must be tsc-clean. Carry-forward 4 (`SiteDetail` ×2 + `SiteCoreCard` ×2).
- PHP: `COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit`; tolerate only the known carry-forward set (UninstallTest + version-pin tests on a version bump). DB-offline → standalone mysqld per the carry-forward env notes.
- dompdf gotcha (P6.5): in-PDF SVG/images must be data-URI `<img>`, never inline `<svg>`. The site logo is already an `<img>` data-URI.

---

## Task 1 — `SiteLogoResolver` service (PHP, backend)

**Files:** Create `packages/dashboard-plugin/src/Services/SiteLogoResolver.php`; Test `packages/dashboard-plugin/tests/Integration/Services/SiteLogoResolverTest.php`.

A small service that resolves a site's icon URL, cached.

- Constructor takes an optional `?callable $fetcher` (default uses `wp_remote_get`) so tests can inject a fake — mirror `ReportPdfService`'s injectable-fetcher pattern.
- `resolve(int $siteId, string $siteUrl): ?string`:
  1. Transient cache key `defyn_site_logo_{siteId}`. If set: return `'' === $cached ? null : $cached` (empty string is the "resolved to nothing" sentinel so we don't refetch every render).
  2. Else fetch `rtrim($siteUrl,'/') . '/wp-json/'` with the fetcher (timeout 5, redirection ≤2). Guard: site URL must be `https://`.
  3. Decode JSON; read `site_icon_url` (string). Accept only a non-empty `https://` URL.
  4. `set_transient($key, $resolved ?? '', DAY_IN_SECONDS)`; return `$resolved` (or null).
  5. Wrap in try/catch `\Throwable` → cache `''`, return null (best-effort, never throws).
- Tests: (a) valid index JSON with `site_icon_url` → returns it + writes transient; (b) missing/empty `site_icon_url` → null + caches `''`; (c) cached `''` → returns null without calling the fetcher; (d) non-https site URL → null; (e) fetcher throws → null.

- [ ] Write tests → fail → implement → pass → commit `feat(report): SiteLogoResolver (cached site_icon_url, best-effort)`

## Task 2 — `ReportService::compose` adds `site.logo_url`

**Files:** Modify `packages/dashboard-plugin/src/Services/ReportService.php`; Modify `tests/Integration/Services/ReportServiceTest.php`.

- Inject `SiteLogoResolver` into `ReportService` (constructor; default-construct if not provided, matching existing optional-dep style).
- In `compose()`, the `site` array gains `'logo_url' => $this->logoResolver->resolve($site->id, $site->url)` (string|null).
- Test: assert the `site` payload includes `logo_url` (stub the resolver to return a known URL; assert it surfaces; and a null case).

- [ ] Write test → fail → implement → pass → commit `feat(report): expose site.logo_url in report payload`

## Task 3 — Remove "Defyn Digital" defaults (PHP)

**Files:** Modify `BrandingService.php`, `ReportSendService.php`; Modify `ReportSendServiceTest.php` (+ any BrandingService test).

- `BrandingService::DEFAULT_AGENCY` → `''` (so `get()` returns `''` when user_meta is empty; the agency name simply isn't shown).
- `ReportSendService.php:30` — `$agency = (string) ($branding['agency_name'] ?? '');`. The email subject/body must still read sensibly with no agency: use the **site label** as the lead (e.g. subject `"{siteLabel} — maintenance report"`); only prefix/suffix the agency when non-empty. Read the current subject/body build and adjust so an empty agency produces clean copy.
- Update tests that asserted `'Defyn Digital'` to the new behaviour (empty agency → site-led copy).

- [ ] Update tests → fail → implement → pass → commit `refactor(report): drop hardcoded "Defyn Digital" agency default (backend)`

## Task 4 — `ReportPdfService` redesign + site logo + monogram

**Files:** Modify `packages/dashboard-plugin/src/Services/ReportPdfService.php`; Modify `tests/Integration/Services/ReportPdfServiceTest.php`.

Redesign the print HTML to match the approved mockup, print-safe (dompdf):
- **`render()`**: resolve the logo to inline. The logo URL now comes from `$report['site']['logo_url']` (the site icon), NOT branding. Fetch via the existing `$this->logoFetcher` → data-URI `<img>`; null → monogram.
- **Cover** (replace lines ~103–151): navy band (`safeAccent($branding['accent_color'] ?? '#26215C')`):
  - left: the site logo `<img style="width:48px;height:48px;border-radius:8px">` OR a monogram box (accent-tinted square with the site label's first letter, white) when no logo;
  - site label as the hero (`font-size:20px;font-weight:bold;color:#fff`), the URL beneath, then a divider and `Period` + `Prepared` ({today}) as labelled pairs.
  - Agency name: render a small line ONLY when `$branding['agency_name']` is non-empty (e.g. `Prepared by {agency}` in the footer or under the band). Never "Defyn Digital".
  - Remove the `?? 'Defyn Digital'` fallback (→ `''`).
- **Sections** (`overviewHtml`/`updatesHtml`/`uptimeHtml`/`securityHtml`/`performanceHtml`/`analyticsHtml`): wrap each in a bordered "card" with a bold heading + subtle divider; the Overview becomes a KPI strip (the 4 stats as cells with a muted label + large value); keep the existing data + the data-URI `<img>` sparklines (P6.5) intact. Use a restrained print palette (navy headings, gray rules, green/amber/red only for status). Keep all values `esc()`'d.
- Update `ReportPdfServiceTest`: the `sampleReport` site array now needs `logo_url`; assert the cover contains the site label + (with a stubbed logo fetcher) the data-URI `<img>`, and contains NO "Defyn Digital"; the monogram path renders the site initial when `logo_url` is null. Keep the existing sparkline-img assertions green.

- [ ] Update tests → fail → implement → pass → commit `feat(report): redesigned PDF cover + sections with per-site logo`

## Task 5 — dashboard version bump

**Files:** `packages/dashboard-plugin/defyn-dashboard.php` (header line + `DEFYN_DASHBOARD_VERSION`).

- `0.25.0` → `0.26.0` (both the plugin header `Version:` and the `define('DEFYN_DASHBOARD_VERSION', …)`).

- [ ] commit `chore(report): dashboard v0.26.0`

## Task 6 — SPA schema + branding constant + fixtures

**Files:** Modify `apps/web/src/types/api.ts` (siteReportSchema.site), `apps/web/src/lib/reportBranding.ts`, `apps/web/src/test/handlers.ts`.

- `siteReportSchema.site` gains `logo_url: z.string().nullable()`.
- `reportBranding.ts`: remove `REPORT_AGENCY_NAME` (the header reads branding via `useReportBranding()`); keep `REPORT_ACCENT = '#26215C'` as the accent default.
- `handlers.ts`: report fixture `site` gains `logo_url` (use `null` in the default fixture so the monogram path is exercised; one case can set a URL). Change the `report_branding` fixtures' `agency_name: 'Defyn Digital'` → `''` (empty = unset).

- [ ] Update + commit `feat(report): SPA report schema logo_url + drop Defyn Digital fixtures`

## Task 7 — `ReportHeader` redesign (site logo + monogram + branding)

**Files:** Modify `apps/web/src/components/report/ReportHeader.tsx`; update `apps/web/tests/SiteReport.test.tsx` assertions as needed.

- Props gain access to `site.logo_url`. Read operator branding via `useReportBranding()` (agency name + accent). Accent default `REPORT_ACCENT`.
- Cover band (navy, accent-driven): left = the site logo `<img>` if `logo_url`, else a **monogram** (rounded square, translucent-white bg, the site label's first letter); the site label as the hero; url; a divider; `Period {from} – {to}` and `Prepared {today}` as labelled pairs; agency name shown ONLY when branding agency_name is non-empty.
- No `REPORT_AGENCY_NAME`. Follow the mockup proportions/typography.

- [ ] implement → tests green → commit `feat(report): personalised report header with site logo + monogram`

## Task 8 — Report sections restyled to design tokens

**Files:** Modify `ReportOverview.tsx`, `ReportUptime.tsx`, `ReportUpdates.tsx`, `ReportSecurity.tsx`, `ReportPerformance.tsx`, `ReportAnalytics.tsx`, `TrendSparkline.tsx`, `AnalyticsTrendSparkline.tsx`; update `apps/web/tests/ReportAnalytics.test.tsx` + `ReportPerformance.test.tsx` if assertions depend on removed classes (keep behaviour assertions).

- Replace raw `zinc-*`/`white`/`slate-*` with design tokens: cards become `rounded-lg border border-border bg-card`; muted text `text-muted-foreground`; headings get a small lucide icon + bold label (icon `text-primary`); status uses semantic colours (success/warning/destructive) via tokens, NOT raw red/amber.
- `ReportOverview` → a KPI strip (metric cards: muted label + large value; colour the value green/amber/red by health where it makes sense — uptime, findings, performance).
- Section headers: icon + title + optional status pill (e.g. Uptime "Healthy", Security severity counts). Match the mockup.
- Sparklines: swap hardcoded `zinc-*` chrome to tokens; keep the data line colours (amber/green/blue) as-is.
- Keep every component's data logic + the `report-section` class (print CSS depends on it).

- [ ] implement → tests green (`pnpm test -- --run tests/ReportAnalytics.test.tsx tests/ReportPerformance.test.tsx`) → commit `feat(report): restyle report sections to the design system`

## Task 9 — `SiteReport` page chrome

**Files:** Modify `apps/web/src/pages/SiteReport.tsx`; keep `report-print.css`; update `apps/web/tests/SiteReport.test.tsx`.

- Controls bar: use the shadcn `Button` primitive (outline for presets/back/download, primary for Print); tidy spacing; keep the `report-controls` class (hidden on print) and all existing behaviour (presets, date inputs, Download PDF, Print, Back to site).
- Wrap the rendered report in a centered "page" surface (`bg-muted/40` page bg, white max-w-3xl report sheet) so it reads like paper both on screen and print. Keep `report-print-root`/`report-section` classes.
- Keep section render order; ensure print CSS still hides controls + avoids section breaks.

- [ ] implement → tests green → commit `feat(report): polished report page chrome + controls`

## Task 10 — Full verification + ship

**Files:** none (build + release).

- [ ] PHP full suite (tolerate carry-forward only); SPA full suite (4 carry-forward); `pnpm build` tsc-clean.
- [ ] **Local PDF eyeball:** render a sample report (connected-site shape, with a stubbed `logo_url`) through `ReportPdfService` to a `.claude-tmp/` PDF; confirm the new cover (site label + logo/monogram) + no "Defyn Digital"; reuse the P6.5 throwaway-script approach.
- [ ] dompdf-preserving zip `dist/defyn-dashboard-0.26.0.zip` (composer install --no-dev --classmap-authoritative → zip from `packages/` top folder `dashboard-plugin/` → exclude ONLY tests/dev tooling, NEVER `vendor/*` → verify symfony deprecation-contracts/function.php + polyfill-php83/bootstrap.php + dompdf/src/Dompdf.php + json-machine Items.php present → restore dev autoload).
- [ ] Merge `report-redesign` → `main`; push (SPA auto-deploys via Cloudflare).
- [ ] **PAUSE** — user installs `dist/defyn-dashboard-0.26.0.zip` on Kinsta (WP Admin) + clears Kinsta cache.
- [ ] Smoke: indirect curl (401/404 on the report endpoints) + **generate a real report for SmartCoding** and eyeball the new on-screen design + the site logo (the user does the UI/login; or verify the deployed bundle strings). Cloudflare bundle contains the new header.
- [ ] Tag `report-redesign-complete` + push; update MEMORY.

---

## Self-review
- Site logo: connector-free (WP REST index `site_icon_url`), transient-cached, monogram fallback. ✓
- "Defyn Digital" removed: BrandingService default, ReportPdfService, ReportSendService, reportBranding.ts, handlers fixtures (Tasks 3/4/6). ✓
- Redesign covers on-screen (Tasks 7/8/9) + PDF (Task 4), matching the approved mockup. ✓
- No schema/connector change; dashboard v0.26.0; dompdf img-data-URI rule respected. ✓
- Tests updated across ReportService/ReportPdfService/ReportSendService (PHP) + SiteReport/ReportAnalytics/ReportPerformance + handlers (SPA). ✓
