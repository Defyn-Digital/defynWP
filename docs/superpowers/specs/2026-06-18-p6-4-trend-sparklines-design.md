# P6.4 — Performance Trend Sparklines Design

**Date:** 2026-06-18
**Status:** Approved (design)
**Phase:** P6.4 — post-roadmap, operator's choice. Extends the Phase-6 reporting surfaces: P6.1 added the per-site Performance report section (latest scores + CWV + a weekly-history *table*); P6.4 draws that weekly history as a trend sparkline.
**Dashboard:** v0.23.0 → **v0.24.0**. **Schema unchanged (v16). Connector unchanged (v0.1.7).**

---

## 1. Goal

Draw the weekly PageSpeed scores (mobile + desktop) already present in the per-site report as a small inline-SVG **trend line**, in both the on-screen report and the branded PDF. This was deferred from P6.1/P6.2 "to avoid inline-SVG PDF charting"; that constraint is re-examined here (dompdf 3.1.5 ships `php-svg-lib`) and de-risked with a spike.

**This is a pure rendering change — NO backend, schema, REST endpoint, repository, or connector change.**

---

## 2. Why it is zero-backend (verified)

`Services\ReportService::compose` already builds a `performance.history` array — oldest→newest, range-filtered via `SitePerformanceRepository::findForSiteInRange` — with per-point fields `{fetched_at, mobile_score, desktop_score}` (scores nullable int). It is already in the SPA `reportPerformanceSchema.history` (`z.array(z.object({fetched_at: z.string(), mobile_score: z.number().nullable(), desktop_score: z.number().nullable()}))`) and is already rendered — as a plain `<table>` — in both `components/report/ReportPerformance.tsx` and `Services\ReportPdfService::performanceHtml`. P6.4 only changes how that existing array is *drawn*. `ReportService`, the Zod schema, the repositories, and the REST layer are untouched.

---

## 3. Decisions (locked in brainstorm)

1. **Scope = performance-only.** Reuse the existing weekly history; **no** analytics trend (analytics has single-calendar-month snapshots only — a trend there would need a new GA4 multi-month fetch + storage + schema; deferred to a follow-up).
2. **Chart tech = inline SVG line**, shared in spirit by React + the PDF (each renders its own markup from the same `history` array). dompdf renders it via the bundled `php-svg-lib`.
3. **Layout = sparkline ABOVE the existing weekly-history table (keep the table).** Chart for the glance, table for exact values.
4. **One combined chart** — mobile + desktop as two lines in a single sparkline.
5. **Fixed 0–100 y-axis** (scores are 0–100; relative scaling would exaggerate noise), faint **solid** gridlines at the 50 and 90 PSI band boundaries, end-point dots, latest-value legend.
6. **De-risk first:** a spike confirms dompdf renders the SVG before the real section is built.

---

## 4. The sparkline

### 4.1 Markup constraints (dompdf-safe)
Use ONLY well-supported SVG primitives so the same markup renders in browsers AND dompdf's `php-svg-lib`: `<svg>`, `<polyline>`, `<line>`, `<circle>`, with **solid** `stroke`/`fill` attributes. **No** `stroke-dasharray`, **no** CSS-in-SVG / `<style>`, **no** `<text>` inside the SVG (labels live in surrounding HTML, not the SVG, to avoid font/positioning quirks in dompdf).

### 4.2 Geometry
- `viewBox="0 0 200 56"` (a small, fixed coordinate space); rendered ~320×90 on screen, scaled to fit the PDF column.
- y maps score 0→100 onto the drawable band (e.g. y = `pad + (100 - score)/100 * (h - 2*pad)`), so **higher score = higher on the chart**.
- x maps point index evenly across the width.
- Two `<polyline fill="none">`: mobile `stroke="#d97706"` (amber), desktop `stroke="#16a34a"` (green), `stroke-width="2"`.
- Two faint solid `<line stroke="#e5e7eb">` gridlines at score 50 and score 90.
- End-point `<circle r≈2.6>` at the latest point of each series, filled the series colour.
- Surrounding HTML legend (outside the SVG): `Mobile <b>{latest}</b>` / `Desktop <b>{latest}</b>` + a faint `{firstDate} → {lastDate}` caption.

### 4.3 Null / sparse handling + guard
- **Per series**, plot only the points whose score for that device is non-null (filter nulls → the line connects measured points).
- **Render the sparkline only when at least one series has ≥2 non-null points.** Fewer than 2 points is not a trend → render nothing (the weekly table still shows whatever points exist). A device whose series has <2 non-null points simply gets no line/dot (the other device may still draw).

---

## 5. Components & files

### 5.1 PHP (PDF)
- **`Services\ReportPdfService`** — new private helper `sparklineSvg(array $history): string` that returns the `<svg>…</svg>` string (or `''` when the ≥2-point guard fails). Coordinates are floats computed from our own integer scores (not attacker data); any interpolated string is still `esc()`'d per the existing guardrail. `performanceHtml` inserts `sparklineSvg($perf['history'] ?? [])` (wrapped in a small labelled block) **above** the existing weekly-history `<table>`. The latest-value legend reuses the values already computed for the section.
- The existing dompdf setup is unchanged: `Options` with `isRemoteEnabled=false`, `defaultFont='DejaVu Sans'`; `loadHtml` → `render` → `output`.

### 5.2 SPA (on-screen)
- **`lib/sparkline.ts`** — pure `buildSparkPoints(scores: (number | null)[], width: number, height: number, pad: number): string` returning the SVG `points` attribute string for one series (null scores filtered; maps value→y, index→x across the surviving points). Plus a tiny `sparkY(score, height, pad)` for gridline/dot positioning. No React, no DOM — unit-testable.
- **`components/report/TrendSparkline.tsx`** — presentational: takes `history: ReportPerformanceData['history']`, renders the `<svg>` (two polylines + 2 gridlines + end dots) + the legend; **returns `null` when neither series has ≥2 non-null points**. Uses `lib/sparkline.ts` for coordinates. No charting dependency added.
- Mounted in **`components/report/ReportPerformance.tsx`** above the existing weekly-history table (the table stays).

### 5.3 Version
- `defyn-dashboard.php` v0.23.0 → **v0.24.0** (header line + `DEFYN_DASHBOARD_VERSION`). **No** `Activation::SCHEMA_VERSION` change (stays 16).

---

## 6. De-risk spike (Task 1, before the real section)

A focused PHP test constructs a `Dompdf` directly — with the **same `Options` as `ReportPdfService`** (`isRemoteEnabled=false`, `defaultFont='DejaVu Sans'`) — and renders a hand-written HTML fragment containing the exact SVG primitives we will emit (`<svg>` with `<polyline>`, `<line>`, `<circle>`, solid strokes), asserting it returns `%PDF`-prefixed bytes **without throwing**. (It uses a standalone SVG fragment, not `ReportPdfService::sparklineSvg`, because that method does not exist yet at this task — the spike is the gate that decides whether it gets built.) This catches the failure mode the deferral feared (dompdf/​php-svg-lib choking on the SVG markup). Note: a passing spike proves dompdf *accepts and processes* the markup; the *visual* correctness of the PDF line is confirmed by a one-time local manual render in the release task (synthetic ≥2-point history → open the PDF → eyeball the line). If the spike fails, fall back to a CSS-only bar sparkline (divs with % heights) — but `php-svg-lib` being installed makes success the expected outcome.

---

## 7. Testing

**PHP (carry-forward baseline: only `UninstallTest`):**
- Spike: dompdf renders the sample SVG fragment → `%PDF`, no exception (§6).
- `ReportPdfServiceTest` — `performanceHtml` contains `<svg` + `<polyline` when `performance.history` has ≥2 non-null points for a series; and contains **no** `<svg` when <2 points (guard). Because the existing `sampleReport` fixture has 0–1 history points, the current PDF tests stay green unchanged; a new fixture/case supplies the ≥2-point history.

**SPA (carry-forward baseline: 4 — SiteDetail×2 + SiteCoreCard×2):**
- `lib/sparkline.ts` — `buildSparkPoints` coordinate mapping (known scores → expected coords), null filtering, and the empty result when <2 points.
- `TrendSparkline` — renders two `<polyline>`s for two-device ≥2-point history; renders nothing (null) for <2 points; renders only the populated series when one device is all-null.
- `ReportPerformance` — the sparkline appears when history is present; the existing weekly table still renders.

---

## 8. Surfaces & out of scope

**In scope:** the per-site report — on-screen (`pages/SiteReport.tsx` → `ReportPerformance`) + PDF (`ReportPdfService` via `GET /sites/{id}/report.pdf`).

**Out of scope (YAGNI):**
- No analytics trend (needs a GA4 multi-month build — separate follow-up).
- No sparkline on the `/insights` fleet page (rows are latest-snapshot only — would need a fleet history API).
- No sparkline on the Site-detail `SitePerformancePanel` (latest-only).
- No new data, schema, endpoint, job, connector change, or charting library.
- No daily granularity (the scan cadence is weekly; "trend over the weekly history in the report range" is what we draw).
- No axis tick labels beyond the latest-value legend + first/last-date caption.

---

## 9. Build order (≈6 tasks)

1. **Spike:** dompdf-renders-SVG test (`%PDF`, no throw). Gate before building the real section.
2. **PHP:** `ReportPdfService::sparklineSvg` helper + insert into `performanceHtml` (≥2-point guard) + `ReportPdfServiceTest` cases.
3. **Version:** dashboard `v0.24.0` bump.
4. **SPA:** `lib/sparkline.ts` pure helper + unit tests.
5. **SPA:** `components/report/TrendSparkline.tsx` + mount in `ReportPerformance.tsx` + render tests.
6. **Release:** full PHP + SPA suites, `pnpm build` (tsc), **local sample-PDF eyeball** (synthetic ≥2-point history → confirm the line draws), dompdf-preserving zip `dist/defyn-dashboard-0.24.0.zip` (verify the same vendor set incl. `dompdf/src/Dompdf.php` + `dompdf/php-svg-lib`), merge to main, manual Kinsta install (pause for "installed"), indirect curl smoke (`GET /sites/999999/report.pdf` auth → 404 `sites.not_found`; bogus route contrast; deployed SPA bundle contains the sparkline component), Cloudflare deploy verify, tag `p6-4-trend-sparklines-complete`, MEMORY.
