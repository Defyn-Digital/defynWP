# P6.5 — Analytics Monthly Trend Sparkline Design

**Date:** 2026-06-18
**Status:** Approved (design)
**Phase:** P6.5 — post-roadmap, operator's choice. The analytics-side counterpart to P6.4 (which gave the Performance report section an inline-SVG trend line over its weekly history); P6.5 gives the GA4 Analytics section a monthly **sessions** trend line over the months already accumulated.
**Dashboard:** v0.24.0 → **v0.25.0**. **Schema unchanged (v16). Connector unchanged (v0.1.7). No new GA4 fetch, no new composer dep, no charting library.**

---

## 1. Goal

Draw a small inline-SVG **monthly sessions** trend line in the analytics report section, on both the on-screen report and the branded PDF, reading the monthly snapshots the weekly GA4 sync has **already accumulated** in `wp_defyn_site_analytics`. Pure read + render over existing data — no new GA4 API call, no schema change.

---

## 2. Why it is zero-fetch / zero-schema (verified)

P6.2 stores GA4 data as single calendar-month snapshots (one row per `(site_id, period_start, period_end)`). The weekly `Jobs\AnalyticsSyncAll → AnalyticsSync` fetches **current + previous** calendar month every run and upserts each (delete-then-insert per period), so **older months persist** — the table naturally accumulates one new month per month, and a freshly-connected site has **2 months on day 1** (current + previous). `wp_defyn_site_analytics` already holds these rows, so a multi-month read needs only a **new repository query** — no schema change, no GA4 refetch. (Confirmed: `Activation::SCHEMA_VERSION = 16`, dashboard v0.24.0.)

---

## 3. Decisions (locked in brainstorm)

1. **Data source = accumulate.** Read whatever monthly rows the weekly sync has already stored. **No backfill** (an immediate rich N-month fetch from GA4 would require a real `Ga4Client::fetchReport` + parser refactor since they are single-date-range today — deferred follow-up).
2. **Metric = sessions only** (the headline analytics KPI). One line. (Users is always a correlated subset of sessions — low marginal signal; deferred.)
3. **Y-axis = floor at 0, top = series max** (relative auto-scale). Honest for unbounded counts; a 2-point early trend reads as a real-but-modest change, not full-height drama. NOT auto-fit min→max (which pins the lowest month to the bottom and exaggerates).
4. **Look = one blue (`#2563eb`) line, no gridlines**, end-point dot, a legend ("Sessions <latest> · N months <first>→<last>"). Sits **between the KPI strip and the Top-pages table**; the existing top-pages + channels tables stay unchanged.
5. **Surfaces = the per-site report (on-screen + PDF) only.** The Site-detail `SiteAnalyticsPanel` and the `/insights` fleet page are out of scope.
6. **Reuse by generalizing P6.4's pure helpers** (add a `max` parameter, default 100) rather than writing parallel analytics-only math — DRY, single source of truth, backward-compatible.

---

## 4. Backend

### 4.1 `SiteAnalyticsRepository::findRecentForSite(int $siteId, int $limit): array`
New method (the repo currently has only `latestForSite` / `findForSiteAndMonth` / `upsertForSiteAndPeriod` / `findFleetForUser` — no range/recent-months). SQL:
```sql
SELECT * FROM {analytics} WHERE site_id = %d ORDER BY period_start DESC LIMIT %d
```
then **reverse to oldest→newest** in PHP. Returns `SiteAnalytics[]` (the existing DTO). Used with `$limit = 12` (a year cap).

### 4.2 `ReportService::buildAnalytics` — add a `history` key
In the **`ready`** state, add `history` to the returned array:
```php
'history' => [ ['period_start' => '2026-01-01', 'sessions' => 4200], … ],  // oldest→newest
```
built from `findRecentForSite($site->id, 12)`, mapping each snapshot to `{period_start, sessions}` (sessions only). Reads cached rows — **NEVER calls GA4** (the no-sync-fetch guardrail from P6.2). The `not_connected` and `pending` states emit `history: []`. The history is "the most recent ≤12 months in the table as of now," independent of the report's selected calendar month.

> The `ready` payload keeps its existing keys (`state`, `period`, `totals`, `top_pages`, `channels`) and gains `history`.

---

## 5. Generalize the sparkline helpers (P6.4 reuse)

P6.4's `sparkY`/`buildSparkPoints` (TS) and `sparkY`/`sparkPoints` (PHP) hard-code a 0–100 y-axis via `(100 - value)/100`. Generalize to `(max - value)/max` with a `max` parameter **defaulting to 100** — both sparklines stay **floored at 0**, and all P6.4 call sites (which pass no `max`) are unchanged.

### 5.1 TS `apps/web/src/lib/sparkline.ts`
- `sparkY(value: number, max = 100, height = 56, pad = 4): number` — clamp value to `[0, max]`, return `pad + ((max - clamped)/max) * (height - 2*pad)`.
- `buildSparkPoints(scores: (number|null)[], max = 100, width = 200, height = 56, padX = 10): string` — same null-filter + even x-spacing; uses `sparkY(v, max, height)`. Returns `''` for <2 non-null points.
- **Backward-compatibility:** `max` is inserted as the **2nd positional param** with default 100. P6.4's `TrendSparkline` calls `buildSparkPoints(mobile)` and `sparkY(50)` (no `max`) → resolve to `max=100` → byte-identical output; P6.4 tests stay green.

### 5.2 PHP `ReportPdfService`
- `sparkY(int $value, int $max = 100): float` — clamp `[0,$max]`, return `round($pad + ($max - $value)/$max * (...), 1)` (keep the existing viewBox `0 0 200 56`, pad 4, drawable 48). Default `max=100` keeps performance unchanged.
- `sparkPoints(array $values, int $max = 100): array` — same null-filter + even spacing; passes `$max` to `sparkY`. Default keeps performance's `sparklineSvg` unchanged.
- **New `analyticsSparklineSvg(array $history): string`** — extract the `sessions` series; compute `$max = max(non-null sessions)`; **return `''` when fewer than 2 non-null points OR `$max <= 0`**; else draw ONE `<polyline fill="none" stroke="#2563eb" stroke-width="2">` (via `sparkPoints($sessions, $max)`) + an end-dot `<circle r="2.6" fill="#2563eb">`, **no gridlines**, inside `<svg width="200" height="56" viewBox="0 0 200 56">`. dompdf-safe primitives only (proven by the P6.4 spike — no re-spike needed).

---

## 6. Renderers (sparkline above the existing tables)

### 6.1 PDF `ReportPdfService::analyticsHtml`
In the `ready` branch, insert the sparkline **after the KPI strip (`$kpis`), before Top pages (`$topPages`)**:
```php
$spark      = $this->analyticsSparklineSvg($a['history'] ?? []);
$sparkBlock = $spark === '' ? '' : '<p class="muted" style="margin-bottom:2px">Sessions trend</p>' . $spark;
$body = '<p class="muted">Google Analytics 4 &middot; ' . $period . '</p>' . $kpis . $sparkBlock . $topPages . $channels;
```
The top-pages + channels tables are unchanged.

### 6.2 SPA `reportAnalyticsSchema` (`types/api.ts`)
Add a `history` field:
```typescript
history: z.array(z.object({ period_start: z.string(), sessions: z.number().nullable() })),
```
The backend always emits `history` (`[]` when not `ready`), so the field is required (not optional).

### 6.3 SPA `components/report/AnalyticsTrendSparkline.tsx` (new)
Presentational. Takes `history: { period_start: string; sessions: number | null }[]`. Computes `max = Math.max(...non-null sessions)`; **returns `null`** when fewer than 2 non-null points (or `max <= 0`). Renders one blue (`#2563eb`) `<polyline>` via `buildSparkPoints(sessions, max)` + an end-dot `<circle r={2.6}>`, **no gridlines**, in `viewBox="0 0 200 56"`, plus an HTML legend ("Sessions <latest> · N months <first> → <last>"). Mounted in `ReportAnalytics.tsx`'s `state === 'ready'` branch, **between the KPI grid and the Top-pages block**.

---

## 7. Version

`defyn-dashboard.php` v0.24.0 → **v0.25.0** (header line + `DEFYN_DASHBOARD_VERSION`). **No** `Activation::SCHEMA_VERSION` change (stays 16).

---

## 8. Testing

**PHP (carry-forward baseline: only `UninstallTest`):**
- `SiteAnalyticsRepository::findRecentForSite` — seed 3 months → assert oldest→newest order; assert the `LIMIT` cap (seed >12 → returns ≤12, the most recent).
- `ReportService::buildAnalytics` — `ready` payload contains `history` (oldest→newest `{period_start, sessions}`); `pending` and `not_connected` emit `history: []`.
- Generalized `sparkY`/`sparkPoints` — new relative cases (e.g. `sparkY(5000, 10000) = 28` middle), AND the P6.4 default-`max=100` cases stay green.
- `analyticsSparklineSvg` — contains `<svg`+`<polyline`+`stroke="#2563eb"` for a ≥2-point session history; returns `''` (no `<svg`) for <2 points; the existing `analyticsHtml` not_connected/pending tests stay green (no svg in those states).

**SPA (carry-forward baseline: 4 — SiteDetail×2 + SiteCoreCard×2):**
- `lib/sparkline.ts` — new `max`-param relative cases; the existing P6.4 cases (default `max=100`) unchanged.
- `AnalyticsTrendSparkline` — one polyline for a ≥2-point session history; `null` for <2 points; relative scaling (max → top, 0 → bottom).
- `ReportAnalytics` — the sparkline appears in the `ready` state with history; the existing top-pages/channels tables still render.
- `reportAnalyticsSchema` — parses a payload with `history`.

---

## 9. Build order (≈8 tasks)

1. `SiteAnalyticsRepository::findRecentForSite` + test.
2. `ReportService::buildAnalytics` `history` key (ready / empty otherwise) + test.
3. Generalize TS `lib/sparkline.ts` (`max` param, default 100) + tests (new relative + P6.4 unchanged).
4. Generalize PHP `sparkY`/`sparkPoints` (`max` default 100) + new `analyticsSparklineSvg` + insert into `analyticsHtml` + `ReportPdfServiceTest` cases.
5. Dashboard `v0.25.0` bump.
6. SPA `reportAnalyticsSchema` `history` field + MSW/fixture update + test.
7. SPA `AnalyticsTrendSparkline.tsx` + mount in `ReportAnalytics.tsx` + tests.
8. Release: full PHP + SPA suites, `pnpm build` (tsc), **local sample-PDF eyeball** (synthetic ≥2-month session history → confirm the blue line draws in the PDF), dompdf-preserving zip `dist/defyn-dashboard-0.25.0.zip` (verify the same vendor set incl. `dompdf/php-svg-lib`), merge to main, manual Kinsta install (pause for "installed"), indirect curl smoke (`GET /sites/999999/report.pdf` auth → 404 `sites.not_found`; bogus-route contrast; deployed SPA bundle contains the analytics-sparkline literal — `#2563eb` and/or `Sessions trend`), Cloudflare deploy verify, tag `p6-5-analytics-trend-complete`, MEMORY.

---

## 10. Out of scope (YAGNI)

- Backfill (immediate multi-month fetch from GA4) — real `Ga4Client` + scan refactor; deferred follow-up.
- Sessions + Users multi-line.
- The Site-detail `SiteAnalyticsPanel` trend; the `/insights` fleet page; daily granularity; any new GA4 fetch; any schema change.
- No dompdf-SVG spike (P6.4 proved it; same primitives).
