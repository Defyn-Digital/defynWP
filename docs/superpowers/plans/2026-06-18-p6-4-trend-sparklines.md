# P6.4 — Performance Trend Sparklines Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Draw the weekly PageSpeed scores (mobile + desktop) already present in the per-site report as a small inline-SVG trend line, on both the on-screen report and the branded PDF.

**Architecture:** Pure rendering change. `performance.history` (`{fetched_at, mobile_score, desktop_score}[]`, oldest→newest) already flows through `ReportService::compose` → `reportPerformanceSchema` → and is already rendered as a plain table in both `ReportPerformance.tsx` and `ReportPdfService::performanceHtml`. P6.4 adds an inline-SVG sparkline above that table in each renderer. **No backend, schema, REST, repository, or connector change.** A spike confirms dompdf renders the SVG before the real PDF section is built.

**Tech Stack:** PHP 8.1 (WP plugin, PHPUnit/wp-phpunit, dompdf 3.1.5 + php-svg-lib), React 18 + TypeScript + Vitest (pnpm, Node 22 via fnm). Dashboard v0.23.0 → v0.24.0. Schema unchanged (v16). Connector unchanged (v0.1.7). No new composer dep, no charting library.

**Spec:** `docs/superpowers/specs/2026-06-18-p6-4-trend-sparklines-design.md`
**Branch:** `p6-4-trend-sparklines` (already created, off main @ bca9a87).

---

## Conventions

- **PHP tests** from `packages/dashboard-plugin`: `COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit --filter <Name>`. Full suite: `COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit` (tolerate only `UninstallTest`; baseline after P6.3 = 944 pass / 1).
- **DB-OFFLINE fallback** (the Local `defynWP` DB may be stopped — though the PDF tests are pure and need no DB): if phpunit errors on a DB connection, start standalone `mysqld` 8.0.35 (`…/lightning-services/mysql-8.0.35+4/bin/darwin/bin/mysqld`) against `~/Library/Application Support/Local/run/50bJKdbjK/mysql/data` on port 10166 with its OWN socket `/tmp/defyn_test_mysqld.sock`, **no** `--skip-grant-tables` (forces skip_networking in 8.0), root/root, `mysqladmin shutdown` clean. NEVER modify the gitignored `wp-tests-config.php`. If `/tmp` hits ENOSPC, set `CLAUDE_CODE_TMPDIR` to a project-local scratch dir.
- **SPA tests** from `apps/web` (Node 22): `export FNM_DIR="$HOME/.fnm"; eval "$(fnm env --shell bash)"; fnm use 22; pnpm test -- --run <path>`. Tests live FLAT in `apps/web/tests/` (e.g. `tests/ReportPerformance.test.tsx`). Carry-forward 4: `tests/SiteDetail.test.tsx` ×2 + `tests/components/sites/SiteCoreCard.test.tsx` ×2. `pnpm build` runs `tsc`.
- Commit after each task with the message in its final step.

---

### Task 1: Spike — confirm dompdf renders inline SVG (the gate)

The P6.1/P6.2 specs deferred this "to avoid inline-SVG PDF charting." dompdf is 3.1.5 with `php-svg-lib` installed, so it *should* render inline SVG. This spike proves dompdf **accepts and processes** the exact SVG primitives we will emit before we build on them. If it throws, stop and fall back to CSS bars.

**Files:**
- Test: `packages/dashboard-plugin/tests/Integration/Services/DompdfSvgSpikeTest.php`

- [ ] **Step 1: Read the sibling test's base class**

Open `packages/dashboard-plugin/tests/Integration/Services/ReportPdfServiceTest.php` and note its `class … extends …` declaration + namespace. Mirror that base class for the spike (the PDF tests are pure — no DB — but match the project's conventions/bootstrap exactly).

- [ ] **Step 2: Write the spike test**

Create `DompdfSvgSpikeTest.php` (adjust namespace + base class to match `ReportPdfServiceTest`):

```php
<?php
declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use WP_UnitTestCase;

/**
 * P6.4 spike — proves dompdf (3.1.5 + php-svg-lib) renders the inline-SVG
 * primitives the trend sparkline will emit, with the SAME Options as
 * ReportPdfService. Gate before building ReportPdfService::sparklineSvg.
 */
final class DompdfSvgSpikeTest extends WP_UnitTestCase
{
    public function testDompdfRendersInlineSvgPrimitivesToPdf(): void
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $svg = '<svg width="200" height="56" viewBox="0 0 200 56" xmlns="http://www.w3.org/2000/svg">'
            . '<line x1="6" y1="28" x2="194" y2="28" stroke="#e5e7eb" stroke-width="1"/>'
            . '<line x1="6" y1="8.8" x2="194" y2="8.8" stroke="#e5e7eb" stroke-width="1"/>'
            . '<polyline fill="none" stroke="#d97706" stroke-width="2" points="10,29 100,20 190,24"/>'
            . '<polyline fill="none" stroke="#16a34a" stroke-width="2" points="10,10 100,9 190,8"/>'
            . '<circle cx="190" cy="24" r="2.6" fill="#d97706"/>'
            . '<circle cx="190" cy="8" r="2.6" fill="#16a34a"/>'
            . '</svg>';

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml('<html><body><h1>spike</h1>' . $svg . '</body></html>');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $pdf = (string) $dompdf->output();

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf));
    }
}
```

- [ ] **Step 3: Run the spike**

Run: `COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit --filter DompdfSvgSpikeTest`
Expected: **PASS** (dompdf renders the SVG → `%PDF` bytes, no exception). **If it throws or fails, STOP and escalate** — the SVG approach is not viable and the plan needs the CSS-bar fallback.

- [ ] **Step 4: Commit**

```bash
git add packages/dashboard-plugin/tests/Integration/Services/DompdfSvgSpikeTest.php
git commit -m "test(p6-4): spike — dompdf renders inline SVG primitives"
```

---

### Task 2: PHP — `sparklineSvg` helper + insert into `performanceHtml`

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/ReportPdfService.php` (the `performanceHtml` method around lines 274-303; new private helpers before `esc()` at line 385)
- Test: `packages/dashboard-plugin/tests/Integration/Services/ReportPdfServiceTest.php`

- [ ] **Step 1: Read the current `performanceHtml` + the test fixture**

Read `ReportPdfService::performanceHtml` (lines 274-303). Note: `$trend` (the history `<table>`) is built at line 299 and concatenated into `$body` at line 301 as `… . $scoreRow . $cwv . $trend`. Read `ReportPdfServiceTest.php` to see how it overrides `$report['performance']` inline (it already has a 2-point-history case ~lines 90-98 and a `['latest'=>null,'history'=>[]]` case) and that it asserts on `$svc->debugHtml(...)`.

- [ ] **Step 2: Write the failing tests**

Add these methods to `ReportPdfServiceTest` (match its existing `$svc` construction + `debugHtml` + `branding()` usage). They build the report's `performance` inline so they don't depend on `sampleReport()`'s default history:

```php
public function testPerformanceSparklineRendersWhenHistoryHasTwoPoints(): void
{
    $svc = new ReportPdfService();
    $report = $this->sampleReport();
    $report['performance'] = [
        'latest' => [
            'fetched_at' => '2026-06-16 03:00:00',
            'mobile'  => ['score' => 58, 'lcp_ms' => 4600, 'cls' => 0.10, 'inp_ms' => 250],
            'desktop' => ['score' => 91, 'lcp_ms' => 2400, 'cls' => 0.05, 'inp_ms' => 120],
        ],
        'history' => [
            ['fetched_at' => '2026-05-19 03:00:00', 'mobile_score' => 48, 'desktop_score' => 88],
            ['fetched_at' => '2026-06-16 03:00:00', 'mobile_score' => 58, 'desktop_score' => 91],
        ],
    ];
    $html = $svc->debugHtml($report, $this->branding());
    $this->assertStringContainsString('<svg', $html);
    $this->assertStringContainsString('<polyline', $html);
    $this->assertStringContainsString('stroke="#d97706"', $html); // mobile line
    $this->assertStringContainsString('stroke="#16a34a"', $html); // desktop line
    // The existing weekly-history table still renders.
    $this->assertStringContainsString('<th>Mobile</th>', $html);
}

public function testPerformanceSparklineAbsentWhenSingleHistoryPoint(): void
{
    $svc = new ReportPdfService();
    $report = $this->sampleReport();
    $report['performance'] = [
        'latest' => [
            'fetched_at' => '2026-06-16 03:00:00',
            'mobile'  => ['score' => 58, 'lcp_ms' => 4600, 'cls' => 0.10, 'inp_ms' => 250],
            'desktop' => ['score' => 91, 'lcp_ms' => 2400, 'cls' => 0.05, 'inp_ms' => 120],
        ],
        'history' => [
            ['fetched_at' => '2026-06-16 03:00:00', 'mobile_score' => 58, 'desktop_score' => 91],
        ],
    ];
    $html = $svc->debugHtml($report, $this->branding());
    $this->assertStringNotContainsString('<svg', $html); // <2 points = not a trend
}
```

> If the existing test class does not have a `branding()` helper, use whatever branding array the other tests pass to `debugHtml`/`render` (read the file). Keep the assertions identical.

- [ ] **Step 3: Run red**

Run: `COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit --filter ReportPdfServiceTest`
Expected: FAIL — the two new tests fail (no `<svg` yet for the 2-point case).

- [ ] **Step 4: Implement the helpers + insertion**

In `ReportPdfService.php`, add these four private helpers just above the `esc()` method (line ~385):

```php
/**
 * P6.4 — inline-SVG trend line of the weekly mobile+desktop scores. Returns ''
 * unless at least one series has >=2 non-null points (a single point is not a
 * trend). dompdf renders this via the bundled php-svg-lib. Coordinates are
 * floats computed from our own integer scores — not attacker data.
 *
 * @param array<int,array<string,mixed>> $history oldest→newest history points
 */
private function sparklineSvg(array $history): string
{
    $mobile  = [];
    $desktop = [];
    foreach ($history as $h) {
        $mobile[]  = isset($h['mobile_score'])  && $h['mobile_score']  !== null ? (int) $h['mobile_score']  : null;
        $desktop[] = isset($h['desktop_score']) && $h['desktop_score'] !== null ? (int) $h['desktop_score'] : null;
    }
    $mPts = $this->sparkPoints($mobile);
    $dPts = $this->sparkPoints($desktop);
    if (count($mPts) < 2 && count($dPts) < 2) {
        return '';
    }

    $g50 = $this->sparkY(50);
    $g90 = $this->sparkY(90);
    $svg  = '<svg width="200" height="56" viewBox="0 0 200 56" xmlns="http://www.w3.org/2000/svg">';
    $svg .= '<line x1="6" y1="' . $g50 . '" x2="194" y2="' . $g50 . '" stroke="#e5e7eb" stroke-width="1"/>';
    $svg .= '<line x1="6" y1="' . $g90 . '" x2="194" y2="' . $g90 . '" stroke="#e5e7eb" stroke-width="1"/>';
    if (count($mPts) >= 2) {
        $svg .= '<polyline fill="none" stroke="#d97706" stroke-width="2" points="' . $this->sparkPointsAttr($mPts) . '"/>';
        $last = $mPts[count($mPts) - 1];
        $svg .= '<circle cx="' . $last[0] . '" cy="' . $last[1] . '" r="2.6" fill="#d97706"/>';
    }
    if (count($dPts) >= 2) {
        $svg .= '<polyline fill="none" stroke="#16a34a" stroke-width="2" points="' . $this->sparkPointsAttr($dPts) . '"/>';
        $last = $dPts[count($dPts) - 1];
        $svg .= '<circle cx="' . $last[0] . '" cy="' . $last[1] . '" r="2.6" fill="#16a34a"/>';
    }
    $svg .= '</svg>';
    return $svg;
}

/**
 * Non-null scores → [x,y] points, evenly spaced across the width.
 * viewBox 200x56, x in [10,190], y from sparkY().
 * @param array<int,int|null> $scores
 * @return array<int,array{0:float,1:float}>
 */
private function sparkPoints(array $scores): array
{
    $vals = array_values(array_filter($scores, static fn ($s) => $s !== null));
    $n = count($vals);
    $pts = [];
    foreach ($vals as $i => $v) {
        $x = $n <= 1 ? 10.0 : 10.0 + ($i / ($n - 1)) * 180.0;
        $pts[] = [round($x, 1), $this->sparkY((int) $v)];
    }
    return $pts;
}

/** Score 0..100 → y in [4,52] (higher score = higher on chart). */
private function sparkY(int $score): float
{
    $score = max(0, min(100, $score));
    return round(4.0 + (100 - $score) / 100 * 48.0, 1);
}

/** @param array<int,array{0:float,1:float}> $pts */
private function sparkPointsAttr(array $pts): string
{
    return implode(' ', array_map(static fn (array $p): string => $p[0] . ',' . $p[1], $pts));
}
```

Then in `performanceHtml`, change the `$body` assembly (line 301) to insert the sparkline between `$cwv` and `$trend`. Replace line 301:

```php
        $body = '<p class="muted">PageSpeed Insights (lab) &middot; measured ' . $when . '</p>' . $scoreRow . $cwv . $trend;
```

with:

```php
        $spark      = $this->sparklineSvg($perf['history'] ?? []);
        $sparkBlock = $spark === '' ? '' : '<p class="muted" style="margin-bottom:2px">Score trend (0&ndash;100)</p>' . $spark;
        $body = '<p class="muted">PageSpeed Insights (lab) &middot; measured ' . $when . '</p>' . $scoreRow . $cwv . $sparkBlock . $trend;
```

- [ ] **Step 5: Run green**

Run: `COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit --filter ReportPdfServiceTest`
Expected: PASS (the two new tests + all pre-existing ones — the latest=null and default-fixture cases keep their behaviour; the SVG only appears for ≥2-point histories).

- [ ] **Step 6: Commit**

```bash
git add packages/dashboard-plugin/src/Services/ReportPdfService.php packages/dashboard-plugin/tests/Integration/Services/ReportPdfServiceTest.php
git commit -m "feat(p6-4): performance trend sparkline in the PDF report section"
```

---

### Task 3: Dashboard version bump v0.24.0

**Files:**
- Modify: `packages/dashboard-plugin/defyn-dashboard.php:6` and `:46`

- [ ] **Step 1: Edit header** — line 6 `* Version:           0.23.0` → `* Version:           0.24.0`.
- [ ] **Step 2: Edit constant** — line 46 `define('DEFYN_DASHBOARD_VERSION', '0.23.0');` → `define('DEFYN_DASHBOARD_VERSION', '0.24.0');`.

> Do NOT touch `Activation::SCHEMA_VERSION` (stays 16 — no schema change).

- [ ] **Step 3: Verify** — `grep -n "0.24.0" packages/dashboard-plugin/defyn-dashboard.php` → 2 matches.
- [ ] **Step 4: Commit**

```bash
git add packages/dashboard-plugin/defyn-dashboard.php
git commit -m "chore(p6-4): bump dashboard plugin to v0.24.0"
```

---

### Task 4: SPA — pure `lib/sparkline.ts` helper

**Files:**
- Create: `apps/web/src/lib/sparkline.ts`
- Test: `apps/web/tests/sparkline.test.ts` (match where the project's `lib` tests live — see Step 1)

- [ ] **Step 1: Confirm test location**

Check where an existing `lib`-helper test lives (e.g. `apps/web/tests/reportRange.test.ts` or a `tests/lib/` dir). Put `sparkline.test.ts` in the same place. (The P6.3 helpers landed under `apps/web/tests/lib/`; match whatever the repo actually uses.)

- [ ] **Step 2: Write the failing test**

```typescript
import { describe, it, expect } from 'vitest';
import { buildSparkPoints, sparkY } from '@/lib/sparkline';

describe('sparkY', () => {
  it('maps score 100 to the top pad and 0 to the bottom', () => {
    expect(sparkY(100)).toBe(4);   // top
    expect(sparkY(0)).toBe(52);    // bottom (4 + 48)
    expect(sparkY(50)).toBe(28);   // middle (4 + 24)
  });
  it('clamps out-of-range scores', () => {
    expect(sparkY(150)).toBe(4);
    expect(sparkY(-10)).toBe(52);
  });
});

describe('buildSparkPoints', () => {
  it('spaces survivors evenly across x [10,190]', () => {
    // 3 points → x = 10, 100, 190
    expect(buildSparkPoints([100, 50, 0])).toBe('10,4 100,28 190,52');
  });
  it('filters null scores before spacing', () => {
    // nulls dropped → 2 survivors at x = 10, 190
    expect(buildSparkPoints([100, null, 0])).toBe('10,4 190,52');
  });
  it('returns empty string when fewer than 2 non-null points', () => {
    expect(buildSparkPoints([42])).toBe('');
    expect(buildSparkPoints([null, null])).toBe('');
    expect(buildSparkPoints([])).toBe('');
  });
});
```

- [ ] **Step 3: Run red** — `pnpm test -- --run tests/sparkline.test.ts` → FAIL (module missing).

- [ ] **Step 4: Implement**

Create `apps/web/src/lib/sparkline.ts`:

```typescript
// P6.4 — pure SVG-coordinate helpers for the performance trend sparkline.
// viewBox 200x56: x in [10,190], y in [4,52] (higher score = higher on chart).
const VIEW_W = 200;
const VIEW_H = 56;
const PAD_X = 10;
const PAD_Y = 4;

/** Score 0..100 → y coordinate (clamped). Used for points, gridlines, and dots. */
export function sparkY(score: number, height: number = VIEW_H, pad: number = PAD_Y): number {
  const clamped = Math.max(0, Math.min(100, score));
  return Math.round((pad + ((100 - clamped) / 100) * (height - 2 * pad)) * 10) / 10;
}

/**
 * SVG `points` attribute for ONE series. Nulls are filtered out, surviving
 * points are spaced evenly across the width. Returns '' when fewer than 2
 * non-null points (a single point is not a trend line).
 */
export function buildSparkPoints(
  scores: (number | null)[],
  width: number = VIEW_W,
  height: number = VIEW_H,
  padX: number = PAD_X,
): string {
  const vals = scores.filter((s): s is number => s !== null);
  if (vals.length < 2) {
    return '';
  }
  return vals
    .map((v, i) => {
      const x = Math.round((padX + (i / (vals.length - 1)) * (width - 2 * padX)) * 10) / 10;
      return `${x},${sparkY(v, height)}`;
    })
    .join(' ');
}
```

- [ ] **Step 5: Run green** — `pnpm test -- --run tests/sparkline.test.ts` → PASS. Then `pnpm build` → tsc clean.

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/lib/sparkline.ts apps/web/tests/sparkline.test.ts
git commit -m "feat(p6-4): SPA sparkline coordinate helper"
```

---

### Task 5: SPA — `TrendSparkline.tsx` + mount in `ReportPerformance.tsx`

**Files:**
- Create: `apps/web/src/components/report/TrendSparkline.tsx`
- Modify: `apps/web/src/components/report/ReportPerformance.tsx` (import + mount above the history table at line 113)
- Test: `apps/web/tests/TrendSparkline.test.tsx`
- Test: `apps/web/tests/ReportPerformance.test.tsx` (extend the existing file)

- [ ] **Step 1: Write the failing tests**

`apps/web/tests/TrendSparkline.test.tsx` (clone the render/wrapper boilerplate from the existing `tests/ReportPerformance.test.tsx`):

```tsx
import { describe, it, expect } from 'vitest';
import { render } from '@testing-library/react';
import { TrendSparkline } from '@/components/report/TrendSparkline';

const twoDevice = [
  { fetched_at: '2026-05-19 03:00:00', mobile_score: 48, desktop_score: 88 },
  { fetched_at: '2026-06-16 03:00:00', mobile_score: 58, desktop_score: 91 },
];

describe('TrendSparkline', () => {
  it('renders two polylines for a two-device ≥2-point history', () => {
    const { container } = render(<TrendSparkline history={twoDevice} />);
    expect(container.querySelectorAll('polyline')).toHaveLength(2);
  });

  it('renders nothing when fewer than 2 points', () => {
    const { container } = render(
      <TrendSparkline history={[{ fetched_at: 'x', mobile_score: 58, desktop_score: 91 }]} />,
    );
    expect(container.firstChild).toBeNull();
  });

  it('renders only the populated series when one device is all-null', () => {
    const { container } = render(
      <TrendSparkline
        history={[
          { fetched_at: 'a', mobile_score: 40, desktop_score: null },
          { fetched_at: 'b', mobile_score: 60, desktop_score: null },
        ]}
      />,
    );
    expect(container.querySelectorAll('polyline')).toHaveLength(1);
  });
});
```

Extend `apps/web/tests/ReportPerformance.test.tsx` with a case asserting the sparkline appears alongside the kept table when history has ≥2 points (use the file's existing render approach + a `performance` fixture with `latest` set + a 2-point `history`):

```tsx
it('renders the trend sparkline above the weekly history table', () => {
  const performance = {
    latest: {
      fetched_at: '2026-06-16 03:00:00',
      mobile: { score: 58, lcp_ms: 4600, cls: 0.1, inp_ms: 250 },
      desktop: { score: 91, lcp_ms: 2400, cls: 0.05, inp_ms: 120 },
    },
    history: [
      { fetched_at: '2026-05-19 03:00:00', mobile_score: 48, desktop_score: 88 },
      { fetched_at: '2026-06-16 03:00:00', mobile_score: 58, desktop_score: 91 },
    ],
  };
  const { container, getByText } = render(<ReportPerformance performance={performance} />);
  expect(container.querySelector('svg')).not.toBeNull();      // sparkline present
  expect(container.querySelectorAll('polyline')).toHaveLength(2);
  expect(getByText('Trend')).toBeInTheDocument();             // existing table heading still there
});
```

> Match the existing `ReportPerformance.test.tsx` imports/structure. It's presentational, so it likely renders `<ReportPerformance performance={…} />` directly with no providers.

- [ ] **Step 2: Run red** — `pnpm test -- --run tests/TrendSparkline.test.tsx tests/ReportPerformance.test.tsx` → FAIL (component missing).

- [ ] **Step 3: Implement `TrendSparkline.tsx`**

```tsx
import type { ReportPerformanceData } from '@/types/api';
import { buildSparkPoints, sparkY } from '@/lib/sparkline';

interface TrendSparklineProps {
  history: ReportPerformanceData['history'];
}

const MOBILE = '#d97706';
const DESKTOP = '#16a34a';

function latestNonNull(scores: (number | null)[]): number | null {
  for (let i = scores.length - 1; i >= 0; i -= 1) {
    if (scores[i] !== null) {
      return scores[i];
    }
  }
  return null;
}

function endDot(points: string): { cx: number; cy: number } | null {
  if (points === '') {
    return null;
  }
  const parts = points.split(' ');
  const [cx, cy] = parts[parts.length - 1].split(',').map(Number);
  return { cx, cy };
}

// P6.4 — inline-SVG trend line of the weekly mobile+desktop scores. Renders
// nothing unless at least one series has >=2 non-null points.
export function TrendSparkline({ history }: TrendSparklineProps) {
  const mobile = history.map((h) => h.mobile_score);
  const desktop = history.map((h) => h.desktop_score);
  const mPoints = buildSparkPoints(mobile);
  const dPoints = buildSparkPoints(desktop);
  if (mPoints === '' && dPoints === '') {
    return null;
  }

  const mLast = latestNonNull(mobile);
  const dLast = latestNonNull(desktop);
  const mDot = endDot(mPoints);
  const dDot = endDot(dPoints);
  const first = history[0]?.fetched_at ?? '';
  const last = history[history.length - 1]?.fetched_at ?? '';

  return (
    <div className="space-y-2">
      <h3 className="text-sm font-semibold text-zinc-700">Score trend</h3>
      <div className="flex items-center gap-4">
        <svg
          viewBox="0 0 200 56"
          width={320}
          height={90}
          className="rounded border border-zinc-200 bg-zinc-50"
          role="img"
          aria-label="Performance score trend"
        >
          <line x1={6} y1={sparkY(50)} x2={194} y2={sparkY(50)} stroke="#e5e7eb" strokeWidth={1} />
          <line x1={6} y1={sparkY(90)} x2={194} y2={sparkY(90)} stroke="#e5e7eb" strokeWidth={1} />
          {mPoints !== '' && <polyline fill="none" stroke={MOBILE} strokeWidth={2} points={mPoints} />}
          {dPoints !== '' && <polyline fill="none" stroke={DESKTOP} strokeWidth={2} points={dPoints} />}
          {mDot && <circle cx={mDot.cx} cy={mDot.cy} r={2.6} fill={MOBILE} />}
          {dDot && <circle cx={dDot.cx} cy={dDot.cy} r={2.6} fill={DESKTOP} />}
        </svg>
        <div className="text-xs leading-relaxed text-zinc-600">
          <div>
            <span className="inline-block h-0.5 w-3 align-middle" style={{ background: MOBILE }} /> Mobile{' '}
            <b>{mLast === null ? '—' : mLast}</b>
          </div>
          <div>
            <span className="inline-block h-0.5 w-3 align-middle" style={{ background: DESKTOP }} /> Desktop{' '}
            <b>{dLast === null ? '—' : dLast}</b>
          </div>
          <div className="mt-1 text-zinc-400">
            {first} → {last}
          </div>
        </div>
      </div>
    </div>
  );
}
```

- [ ] **Step 4: Mount in `ReportPerformance.tsx`**

Add the import at the top (beside the other imports):

```tsx
import { TrendSparkline } from '@/components/report/TrendSparkline';
```

Then, inside the `latest !== null` branch, insert the sparkline immediately BEFORE the existing `{history.length > 0 && (` block (line 113) — so it sits above the weekly history table (which stays unchanged):

```tsx
          <TrendSparkline history={history} />

          {history.length > 0 && (
            <div className="space-y-2">
              <h3 className="text-sm font-semibold text-zinc-700">Trend</h3>
              {/* …existing weekly history table unchanged… */}
```

- [ ] **Step 5: Run green** — `pnpm test -- --run tests/TrendSparkline.test.tsx tests/ReportPerformance.test.tsx` → PASS. Then `pnpm build` → tsc clean.

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/components/report/TrendSparkline.tsx apps/web/src/components/report/ReportPerformance.tsx apps/web/tests/TrendSparkline.test.tsx apps/web/tests/ReportPerformance.test.tsx
git commit -m "feat(p6-4): TrendSparkline component on the on-screen report"
```

---

### Task 6: Release v0.24.0

**Files:**
- Create: `dist/defyn-dashboard-0.24.0.zip`
- Modify: memory files (Step 8)

- [ ] **Step 1: Full PHP suite**

`COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit` → green except `UninstallTest` (baseline 944/1 + the new spike + 2 new PDF tests).

- [ ] **Step 2: Full SPA suite + build**

From `apps/web` (Node 22): `pnpm test -- --run` → green except the 4 carry-forwards. Then `pnpm build` → tsc clean; note the built entry bundle filename under `apps/web/dist/assets/`.

- [ ] **Step 3: LOCAL sample-PDF eyeball (the real visual proof — prod is zero-sites)**

From `packages/dashboard-plugin`, write a throwaway script `/tmp/p64-eyeball.php` that renders a report with a multi-point history through the real service and writes a PDF:

```php
<?php
require __DIR__ . '/vendor/autoload.php';
$report = [
  'site' => ['label' => 'Demo', 'url' => 'https://demo.example'],
  'range' => ['from' => '2026-05-01', 'to' => '2026-06-16'],
  'updates' => ['plugins' => [], 'themes' => [], 'core' => null],
  'security' => ['summary' => [], 'findings' => []],
  'incidents' => [],
  'performance' => [
    'latest' => ['fetched_at' => '2026-06-16 03:00:00',
      'mobile'  => ['score' => 58, 'lcp_ms' => 4600, 'cls' => 0.10, 'inp_ms' => 250],
      'desktop' => ['score' => 91, 'lcp_ms' => 2400, 'cls' => 0.05, 'inp_ms' => 120]],
    'history' => [
      ['fetched_at' => '2026-05-05', 'mobile_score' => 42, 'desktop_score' => 86],
      ['fetched_at' => '2026-05-19', 'mobile_score' => 49, 'desktop_score' => 90],
      ['fetched_at' => '2026-06-02', 'mobile_score' => 45, 'desktop_score' => 88],
      ['fetched_at' => '2026-06-16', 'mobile_score' => 58, 'desktop_score' => 91]],
  ],
  'analytics' => ['state' => 'not_connected'],
];
$pdf = (new \Defyn\Dashboard\Services\ReportPdfService())->render($report, ['agency_name' => 'Defyn', 'accent_color' => '#26215C', 'logo_url' => '']);
file_put_contents('/tmp/p64-sample.pdf', $pdf);
echo "wrote /tmp/p64-sample.pdf (" . strlen($pdf) . " bytes)\n";
```

Run `php /tmp/p64-eyeball.php` then `open /tmp/p64-sample.pdf`. **Confirm the Performance section shows the sparkline line(s) drawn** (amber mobile + green desktop, rising toward the end). If the `$report` shape causes a fatal (`render`/`buildHtml` expects other keys), read `ReportPdfService::buildHtml` and trim the script to exactly the keys it needs — `performance` is the only section that matters here. Delete `/tmp/p64-eyeball.php` + `/tmp/p64-sample.pdf` after eyeballing.

- [ ] **Step 4: Build the dompdf-preserving zip**

`composer install --no-dev --classmap-authoritative`, then build `dist/defyn-dashboard-0.24.0.zip` from `packages/` (top folder `dashboard-plugin/`), excluding ONLY tests + dev tooling (`tests/*`, `*wp-tests-config.php`, `.phpunit.result.cache`, `test-output.log`, `phpunit.xml`, `composer.lock`, `.github/*`, `.gitignore`) — never any `vendor/*` subdir.

- [ ] **Step 5: Verify the zip retains the vendor set (incl. the SVG renderer)**

```bash
unzip -l dist/defyn-dashboard-0.24.0.zip | grep -E "symfony/deprecation-contracts/function\.php|polyfill-php83/bootstrap\.php|json-machine/src/Items\.php|dompdf/src/Dompdf\.php|dompdf/php-svg-lib|firebase/php-jwt/src/JWT\.php|Schema/SitePerformanceTable\.php|Schema/SiteAnalyticsTable\.php"
```
Expected: the dompdf + **php-svg-lib** (the SVG renderer — critical for the PDF sparkline), symfony×2, json-machine, php-jwt, and both Schema lines all present. Then `composer install` to restore dev autoload. Confirm `git status` shows only the new `dist/` zip (gitignored) + any `.claude-tmp/` scratch — no tracked-file changes.

- [ ] **Step 6: Merge to main**

```bash
git checkout main
git merge --no-ff p6-4-trend-sparklines -m "merge: P6.4 performance trend sparklines"
git push origin main
```
(SPA auto-deploys to Cloudflare Pages from `main`.)

- [ ] **Step 7: Manual Kinsta install — PAUSE**

Tell the operator the zip is at `dist/defyn-dashboard-0.24.0.zip`; ask them to upload via WP Admin (Replace current) + MyKinsta → Tools → Clear cache. **Wait for "installed"** before smoking.

- [ ] **Step 8: Indirect curl smoke (after "installed") + tag + MEMORY**

Backend `defynwp.defyn.agency`; login field `access_token`; creds (curl only) `pradeep@defyn.com.au` / `DefynWP-ifirCh5pXm5bTOj0`.
1. `GET /defyn/v1/sites/999999/report.pdf` no auth → **401**.
2. Login → same authed → **404** `sites.not_found` (route + v0.24.0 live; the happy populated PDF is foreclosed by zero-sites prod — the Step-3 local eyeball is the PDF proof).
3. Bogus route contrast → `rest.route_not_found`.

Cloudflare deploy verify: fetch the deployed SPA bundle and confirm it contains the sparkline (grep a stable literal — the mobile polyline colour `#d97706` and/or `Score trend`; avoid the too-generic `Mobile`).

Then:
```bash
git tag p6-4-trend-sparklines-complete
git push origin p6-4-trend-sparklines-complete
```
Append a P6.4-complete entry to `~/.claude/projects/-Users-pradeep-Local-Sites-defynWP/memory/project_defyn_roadmap.md` + refresh the `MEMORY.md` roadmap pointer (dashboard v0.24.0, schema still v16, connector v0.1.7, pure-rendering sparkline on the report + PDF, tag `p6-4-trend-sparklines-complete`, NEXT = operator's choice).

---

## Self-Review (completed by plan author)

**1. Spec coverage:** §4 sparkline geometry → Tasks 2 (PHP) + 4/5 (SPA, same 0–100 / viewBox-200×56 / x[10,190] math); §4.3 null/≥2-point guard → both renderers + tests; §5 files → Tasks 2/4/5; §5.3 version → Task 3; §6 spike → Task 1; §7 testing → distributed; §8 surfaces (report on-screen + PDF only) → Tasks 2+5; §9 build order → Tasks 1–6.

**2. Placeholder scan:** No TBD/TODO. Every code step is complete. The test files that say "match the existing render helper / branding()" reference concrete sibling files (`ReportPerformance.test.tsx`, `ReportPdfServiceTest.php`) the implementer reads — deliberate, since the wrapper/`branding()` helpers are project-specific.

**3. Type consistency:** PHP `sparkY(int):float`, `sparkPoints(array):array`, `sparkPointsAttr(array):string`, `sparklineSvg(array):string` consistent across Task 2. TS `sparkY(number,…):number` + `buildSparkPoints((number|null)[],…):string` consistent across Tasks 4–5; `TrendSparkline` consumes both. The same geometry (viewBox 200×56, x∈[10,190] via pad 10, y∈[4,52] via pad 4, gridlines at score 50/90, colours `#d97706`/`#16a34a`) matches between PHP and TS. The `≥2 non-null points` guard is identical in `sparklineSvg` (PHP) and `buildSparkPoints` returning `''` (TS).
