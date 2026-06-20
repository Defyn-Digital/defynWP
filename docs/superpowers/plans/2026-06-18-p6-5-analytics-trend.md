# P6.5 — Analytics Monthly Trend Sparkline Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Draw a monthly **sessions** trend line (inline SVG, relative floor-at-0 scale) in the GA4 analytics report section — on the on-screen report and the branded PDF — reading the months the weekly sync has already accumulated.

**Architecture:** Pure read + render over the existing `wp_defyn_site_analytics` rows (the weekly sync keeps current+prev month; older months persist). A new repo recent-months query feeds an `analytics.history` key in `ReportService::compose`; the P6.4 sparkline helpers are **generalized** with a backward-compatible `max` parameter (default 100); a new blue, gridline-less, relative-scaled sparkline renders in both surfaces. **No backend schema change, no new GA4 fetch, no new endpoint, no connector change.**

**Tech Stack:** PHP 8.1 (WP plugin, PHPUnit/wp-phpunit, dompdf 3.1.5 + php-svg-lib), React 18 + TypeScript + Vitest (pnpm, Node 22 via fnm). Dashboard v0.24.0 → v0.25.0. Schema unchanged (v16). Connector unchanged (v0.1.7). No new composer dep, no charting library.

**Spec:** `docs/superpowers/specs/2026-06-18-p6-5-analytics-trend-design.md`
**Branch:** `p6-5-analytics-trend` (already created, off main @ 1dabed2).

---

## Conventions

- **PHP tests** from `packages/dashboard-plugin`: `COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit --filter <Name>`. Full suite: `COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit` (tolerate only `UninstallTest`; baseline after P6.4 = 947 pass / 1).
- **DB-OFFLINE fallback** (the Local `defynWP` DB may be stopped): if phpunit errors on a DB connection, start standalone `mysqld` 8.0.35 (`…/lightning-services/mysql-8.0.35+4/bin/darwin/bin/mysqld`) against `~/Library/Application Support/Local/run/50bJKdbjK/mysql/data` on port 10166 with its OWN socket `/tmp/defyn_test_mysqld.sock`, **no** `--skip-grant-tables` (8.0 forces `skip_networking` with it, killing TCP), root/root, `mysqladmin shutdown` clean. NEVER modify the gitignored `wp-tests-config.php`. If `/tmp` ENOSPC → set `CLAUDE_CODE_TMPDIR` to a project-local `.claude-tmp/`.
- **SPA tests** from `apps/web` (Node 22): `export FNM_DIR="$HOME/.fnm"; eval "$(fnm env --shell bash)"; fnm use 22; pnpm test -- --run <path>`. Tests live FLAT in `apps/web/tests/`. Carry-forward 4: `tests/SiteDetail.test.tsx` ×2 + `tests/components/sites/SiteCoreCard.test.tsx` ×2. `pnpm build` runs `tsc`.
- Commit after each task with the message shown in its final step.

---

### Task 1: `SiteAnalyticsRepository::findRecentForSite`

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/SiteAnalyticsRepository.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/SiteAnalyticsRepositoryTest.php`

- [ ] **Step 1: Read the current repo + its test scaffold.** Read `SiteAnalyticsRepository.php` to confirm: the table class is referenced as `SiteAnalyticsTable::tableName()`, rows are hydrated via `SiteAnalytics::fromRow(...)`, and `latestForSite` already does `SELECT * … ORDER BY period_start DESC, id DESC LIMIT 1`. Read `SiteAnalyticsRepositoryTest.php` for its `setUp` + `seedSite` helper (the same one P6.3's `findFleetForUser` tests use) + the `upsertForSiteAndPeriod` signature.

- [ ] **Step 2: Write the failing tests** — append to the `SiteAnalyticsRepositoryTest` class:

```php
public function testFindRecentForSiteReturnsOldestToNewest(): void
{
    $this->seedSite(1, 7, 'https://a.example', 'Alpha');
    $repo = new SiteAnalyticsRepository();
    foreach ([['2026-03-01', '2026-03-31', 300], ['2026-04-01', '2026-04-30', 400], ['2026-05-01', '2026-05-31', 500]] as [$ps, $pe, $sess]) {
        $repo->upsertForSiteAndPeriod(1, $ps, $pe,
            ['sessions' => $sess, 'users' => 0, 'pageviews' => 0, 'avg_engagement' => 0.0, 'top_pages' => [], 'channels' => []],
            '2026-06-01 00:00:00', '2026-06-01 00:00:00');
    }
    $rows = $repo->findRecentForSite(1, 12);
    $this->assertCount(3, $rows);
    $this->assertSame(['2026-03-01', '2026-04-01', '2026-05-01'], array_map(static fn ($m) => $m->periodStart, $rows));
    $this->assertSame([300, 400, 500], array_map(static fn ($m) => $m->sessions, $rows));
}

public function testFindRecentForSiteCapsAtLimit(): void
{
    $this->seedSite(1, 7, 'https://a.example', 'Alpha');
    $repo = new SiteAnalyticsRepository();
    for ($i = 0; $i < 14; $i++) {
        $ts = strtotime("2025-01-01 +{$i} months UTC");
        $repo->upsertForSiteAndPeriod(1, gmdate('Y-m-01', $ts), gmdate('Y-m-t', $ts),
            ['sessions' => $i + 1, 'users' => 0, 'pageviews' => 0, 'avg_engagement' => 0.0, 'top_pages' => [], 'channels' => []],
            '2026-06-01 00:00:00', '2026-06-01 00:00:00');
    }
    $rows = $repo->findRecentForSite(1, 12);
    $this->assertCount(12, $rows);                  // capped at 12 of the 14 months
    $this->assertSame(3, $rows[0]->sessions);       // most-recent 12 = months i=2..13 (sessions 3..14), oldest→newest
    $this->assertSame(14, $rows[11]->sessions);
}
```

> If `upsertForSiteAndPeriod`'s metric-array keys differ from what you read in Step 1, match the real ones — the assertions (oldest→newest order + the 12 cap) are what matter.

- [ ] **Step 3: Run red** — `COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit --filter SiteAnalyticsRepositoryTest` → FAIL (`undefined method findRecentForSite`). (DB-OFFLINE fallback if it errors on a connection.)

- [ ] **Step 4: Implement** — add to `SiteAnalyticsRepository.php` after `latestForSite`:

```php
/**
 * P6.5 — the most-recent $limit monthly snapshots for a site, returned
 * oldest→newest (for the analytics trend sparkline). Reads accumulated rows —
 * no GA4 call.
 *
 * @return SiteAnalytics[] oldest→newest
 */
public function findRecentForSite(int $siteId, int $limit): array
{
    global $wpdb;
    $table = SiteAnalyticsTable::tableName();
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table} WHERE site_id = %d ORDER BY period_start DESC, id DESC LIMIT %d",
        $siteId, $limit
    ), ARRAY_A) ?: [];
    return array_reverse(array_map([SiteAnalytics::class, 'fromRow'], $rows));
}
```

- [ ] **Step 5: Run green** — `COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit --filter SiteAnalyticsRepositoryTest` → PASS (shut down the standalone mysqld if started).

- [ ] **Step 6: Commit**

```bash
git add packages/dashboard-plugin/src/Services/SiteAnalyticsRepository.php packages/dashboard-plugin/tests/Integration/Services/SiteAnalyticsRepositoryTest.php
git commit -m "feat(p6-5): SiteAnalyticsRepository::findRecentForSite recent-months query"
```

---

### Task 2: `ReportService::buildAnalytics` — add the `history` key

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/ReportService.php` (the `buildAnalytics` method, lines ~69-102)
- Test: `packages/dashboard-plugin/tests/Integration/Services/ReportServiceTest.php`

Current shape (already read): `buildAnalytics` returns `['state' => …] + $empty` for `not_connected`/`pending` (where `$empty = ['period' => null, 'totals' => null, 'top_pages' => [], 'channels' => []]`), and a `ready` array (lines 90-101). Line 85 reads the snapshot via `($this->analytics ?? new SiteAnalyticsRepository())->findForSiteAndMonth(...)`.

- [ ] **Step 1: Read `ReportServiceTest.php`** to see how it constructs a connected `Site` (sets `ga4_property_id`) + seeds analytics rows + invokes `compose`, plus its `setUp`/`seedSite`.

- [ ] **Step 2: Write the failing tests** — add to `ReportServiceTest` (match its real `compose` signature + seeding helpers):

```php
public function testAnalyticsReadyIncludesSessionsHistoryOldestToNewest(): void
{
    $this->seedSite(1, 7, 'https://a.example', 'Alpha');
    (new \Defyn\Dashboard\Services\SitesRepository())->setGa4PropertyId(1, '111');
    $ar = new \Defyn\Dashboard\Services\SiteAnalyticsRepository();
    foreach ([['2026-04-01', '2026-04-30', 400], ['2026-05-01', '2026-05-31', 500], ['2026-06-01', '2026-06-30', 980]] as [$ps, $pe, $sess]) {
        $ar->upsertForSiteAndPeriod(1, $ps, $pe,
            ['sessions' => $sess, 'users' => 0, 'pageviews' => 0, 'avg_engagement' => 0.0, 'top_pages' => [], 'channels' => []],
            '2026-06-20 00:00:00', '2026-06-20 00:00:00');
    }
    $report = (new ReportService())->compose(1, 7, '2026-06-01 00:00:00', '2026-06-30 23:59:59');
    $a = $report['analytics'];
    $this->assertSame('ready', $a['state']);
    $this->assertArrayHasKey('history', $a);
    $this->assertSame(
        [['period_start' => '2026-04-01', 'sessions' => 400], ['period_start' => '2026-05-01', 'sessions' => 500], ['period_start' => '2026-06-01', 'sessions' => 980]],
        $a['history'],
    );
}

public function testAnalyticsNotConnectedAndPendingEmitEmptyHistory(): void
{
    $this->seedSite(2, 7, 'https://b.example', 'Bravo');
    $report = (new ReportService())->compose(2, 7, '2026-06-01 00:00:00', '2026-06-30 23:59:59');
    $this->assertSame('not_connected', $report['analytics']['state']);
    $this->assertSame([], $report['analytics']['history']);

    (new \Defyn\Dashboard\Services\SitesRepository())->setGa4PropertyId(2, '222');
    $report2 = (new ReportService())->compose(2, 7, '2026-06-05 00:00:00', '2026-06-20 23:59:59'); // non-calendar-month → pending
    $this->assertSame('pending', $report2['analytics']['state']);
    $this->assertSame([], $report2['analytics']['history']);
}
```

> If `compose(...)`'s exact signature differs (param order / user-id), match the real one. The setter for the GA4 property may be named differently — read `SitesRepository` and use the real method (`setGa4PropertyId` per P6.2). The key assertions stay: `history` present + oldest→newest in `ready`, `[]` otherwise.

- [ ] **Step 3: Run red** — `COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit --filter ReportServiceTest` → FAIL (`history` key missing).

- [ ] **Step 4: Implement** in `buildAnalytics`:

(a) Add `history` to the shared `$empty` (line ~71) so the non-ready states carry it:
```php
$empty = ['period' => null, 'totals' => null, 'top_pages' => [], 'channels' => [], 'history' => []];
```

(b) Capture the repo once and build history in the `ready` path. Replace the snapshot fetch + the `ready` return (lines ~85-101) with:
```php
$repo = $this->analytics ?? new SiteAnalyticsRepository();
$snap = $repo->findForSiteAndMonth($site->id, $monthStart);
if ($snap === null) {
    return ['state' => 'pending'] + $empty;
}

$history = [];
foreach ($repo->findRecentForSite($site->id, 12) as $h) {
    $history[] = ['period_start' => $h->periodStart, 'sessions' => $h->sessions];
}

return [
    'state'  => 'ready',
    'period' => ['start' => $snap->periodStart, 'end' => $snap->periodEnd],
    'totals' => [
        'sessions'               => $snap->sessions,
        'users'                  => $snap->totalUsers,
        'pageviews'              => $snap->screenPageViews,
        'avg_engagement_seconds' => $snap->avgSessionDuration,
    ],
    'top_pages' => $snap->topPages,
    'channels'  => $snap->channels,
    'history'   => $history,
];
```
This reads only cached rows — **no GA4 call** (the no-sync-fetch guardrail). `12` is the year cap.

- [ ] **Step 5: Run green** — `COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit --filter ReportServiceTest` → PASS (the pre-existing ReportServiceTest cases stay green — `history` is additive).

- [ ] **Step 6: Commit**

```bash
git add packages/dashboard-plugin/src/Services/ReportService.php packages/dashboard-plugin/tests/Integration/Services/ReportServiceTest.php
git commit -m "feat(p6-5): analytics.history (recent monthly sessions) in ReportService::compose"
```

---

### Task 3: Generalize the TS sparkline helper (`max` param)

**Files:**
- Modify: `apps/web/src/lib/sparkline.ts`
- Test: `apps/web/tests/lib/sparkline.test.ts` (extend the existing P6.4 file)

Current (already read): `sparkY(score, height=56, pad=4)` formula `(100-clamped)/100`; `buildSparkPoints(scores, width=200, height=56, padX=10)` with internal call `sparkY(v, height)`.

- [ ] **Step 1: Write the failing tests** — append to `tests/lib/sparkline.test.ts` (keep all existing P6.4 cases):

```typescript
describe('sparkY relative max', () => {
  it('scales to an arbitrary max (floor at 0)', () => {
    expect(sparkY(10000, 10000)).toBe(4);   // max → top
    expect(sparkY(0, 10000)).toBe(52);       // 0 → bottom
    expect(sparkY(5000, 10000)).toBe(28);    // half → middle
  });
  it('still defaults to a 0..100 scale (P6.4 behaviour)', () => {
    expect(sparkY(50)).toBe(28);
  });
});

describe('buildSparkPoints relative max', () => {
  it('spaces survivors evenly with a custom max', () => {
    expect(buildSparkPoints([0, 5000, 10000], 10000)).toBe('10,52 100,28 190,4');
  });
  it('still defaults to a 0..100 scale (P6.4 behaviour)', () => {
    expect(buildSparkPoints([100, 50, 0])).toBe('10,4 100,28 190,52');
  });
});
```

- [ ] **Step 2: Run red** — `pnpm test -- --run tests/lib/sparkline.test.ts` → FAIL (the relative-max cases fail; `sparkY(50)` currently ignores a 2nd arg).

- [ ] **Step 3: Implement** — replace the two functions in `apps/web/src/lib/sparkline.ts` (keep the `VIEW_*`/`PAD_*` consts + the file header comment):

```typescript
/**
 * Value 0..max → y coordinate (clamped, floored at 0). Used for points and dots.
 * `max` defaults to 100 (the P6.4 PageSpeed-score scale); analytics passes the
 * series maximum for a relative scale.
 */
export function sparkY(value: number, max: number = 100, height: number = VIEW_H, pad: number = PAD_Y): number {
  const safeMax = max <= 0 ? 1 : max;
  const clamped = Math.max(0, Math.min(safeMax, value));
  return Math.round((pad + ((safeMax - clamped) / safeMax) * (height - 2 * pad)) * 10) / 10;
}

/**
 * SVG `points` attribute for ONE series. Nulls are filtered out, surviving
 * points are spaced evenly across the width. `max` defaults to 100. Returns ''
 * when fewer than 2 non-null points (a single point is not a trend line).
 */
export function buildSparkPoints(
  scores: (number | null)[],
  max: number = 100,
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
      return `${x},${sparkY(v, max, height)}`;
    })
    .join(' ');
}
```
**Critical:** `max` is inserted as the **2nd positional param** of both functions, and `buildSparkPoints`'s internal call is now `sparkY(v, max, height)` (was `sparkY(v, height)`). P6.4's `TrendSparkline` calls `buildSparkPoints(mobile)` and `sparkY(50)`/`sparkY(90)` with no `max` → default 100 → identical output.

- [ ] **Step 4: Run green** — `pnpm test -- --run tests/lib/sparkline.test.ts` → PASS (new + all P6.4 cases). Then `pnpm build` → tsc clean (the P6.4 `TrendSparkline` still compiles against the new signatures).

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/lib/sparkline.ts apps/web/tests/lib/sparkline.test.ts
git commit -m "feat(p6-5): generalize sparkline helpers with a max param (default 100)"
```

---

### Task 4: Generalize PHP spark helpers + `analyticsSparklineSvg` + PDF section

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/ReportPdfService.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/ReportPdfServiceTest.php`

- [ ] **Step 1: Read the current code** — the P6.4 spark helpers (`sparklineSvg`/`sparkPoints`/`sparkY`/`sparkPointsAttr`, ~lines 387-457) and `analyticsHtml` (~lines 307-348). Confirm: `analyticsHtml` aliases `$a = $report['analytics']`, the `ready` branch builds `$kpis`/`$topPages`/`$channels` then `$body = '<p class="muted">Google Analytics 4 &middot; ' . $period . '</p>' . $kpis . $topPages . $channels;`. Confirm `sparkPointsAttr(array $pts): string` joins `"$x,$y"` pairs with spaces.

- [ ] **Step 2: Write the failing tests** — add to `ReportPdfServiceTest` (match the file's `debugHtml($report, $branding)` + `sampleReport()`/`branding()` helpers):

```php
public function testAnalyticsSparklineRendersWhenHistoryHasTwoMonths(): void
{
    $svc = new ReportPdfService();
    $report = $this->sampleReport();
    $report['analytics'] = [
        'state'  => 'ready',
        'period' => ['start' => '2026-06-01', 'end' => '2026-06-30'],
        'totals' => ['sessions' => 980, 'users' => 670, 'pageviews' => 2450, 'avg_engagement_seconds' => 123.0],
        'top_pages' => [], 'channels' => [],
        'history'   => [
            ['period_start' => '2026-05-01', 'sessions' => 500],
            ['period_start' => '2026-06-01', 'sessions' => 980],
        ],
    ];
    $html = $svc->debugHtml($report, $this->branding());
    $this->assertStringContainsString('<svg', $html);
    $this->assertStringContainsString('<polyline', $html);
    $this->assertStringContainsString('stroke="#2563eb"', $html); // analytics sessions line
}

public function testAnalyticsSparklineAbsentWhenSingleMonth(): void
{
    $svc = new ReportPdfService();
    $report = $this->sampleReport();
    $report['analytics'] = [
        'state'  => 'ready',
        'period' => ['start' => '2026-06-01', 'end' => '2026-06-30'],
        'totals' => ['sessions' => 980, 'users' => 670, 'pageviews' => 2450, 'avg_engagement_seconds' => 123.0],
        'top_pages' => [], 'channels' => [],
        'history'   => [['period_start' => '2026-06-01', 'sessions' => 980]],
    ];
    $html = $svc->debugHtml($report, $this->branding());
    $this->assertStringNotContainsString('stroke="#2563eb"', $html); // <2 points = no analytics sparkline
}
```

> Use the same `branding()` + `debugHtml`/`sampleReport` the existing tests use (read them). The existing not_connected/pending analytics tests stay green (no `history` → no `#2563eb`).

- [ ] **Step 3: Run red** — `COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit --filter ReportPdfServiceTest` → FAIL.

- [ ] **Step 4: Implement**

(a) Generalize `sparkY` + `sparkPoints` (add `$max = 100`; performance stays unchanged):
```php
/** Value 0..max → y in [4,52] (floor at 0). $max defaults to 100 (PageSpeed scale). */
private function sparkY(int $value, int $max = 100): float
{
    $max   = $max <= 0 ? 1 : $max;
    $value = max(0, min($max, $value));
    return round(4.0 + ($max - $value) / $max * 48.0, 1);
}

/**
 * Non-null values → [x,y] points, evenly spaced across the width.
 * @param array<int,int|null> $values
 * @return array<int,array{0:float,1:float}>
 */
private function sparkPoints(array $values, int $max = 100): array
{
    $vals = array_values(array_filter($values, static fn ($s) => $s !== null));
    $n = count($vals);
    $pts = [];
    foreach ($vals as $i => $v) {
        $x = $n <= 1 ? 10.0 : 10.0 + ($i / ($n - 1)) * 180.0;
        $pts[] = [round($x, 1), $this->sparkY((int) $v, $max)];
    }
    return $pts;
}
```

> If the existing `sparkPoints` builds its x differently (read it in Step 1), keep its exact x-math and only thread `$max` into the `sparkY(...)` call — the P6.4 performance tests pin the existing point strings and must stay green.

(b) Add the new analytics sparkline helper next to the others:
```php
/**
 * P6.5 — relative-scaled (floor 0 → series max) monthly sessions trend line.
 * Returns '' unless >=2 non-null sessions and max>0. One blue line, no gridlines.
 *
 * @param array<int,array<string,mixed>> $history oldest→newest {period_start, sessions}
 */
private function analyticsSparklineSvg(array $history): string
{
    $sessions = [];
    foreach ($history as $h) {
        $sessions[] = isset($h['sessions']) && $h['sessions'] !== null ? (int) $h['sessions'] : null;
    }
    $nonNull = array_values(array_filter($sessions, static fn ($s) => $s !== null));
    if (count($nonNull) < 2) {
        return '';
    }
    $max = max($nonNull);
    if ($max <= 0) {
        return '';
    }
    $pts  = $this->sparkPoints($sessions, $max);
    $last = $pts[count($pts) - 1];
    return '<svg width="200" height="56" viewBox="0 0 200 56" xmlns="http://www.w3.org/2000/svg">'
        . '<polyline fill="none" stroke="#2563eb" stroke-width="2" points="' . $this->sparkPointsAttr($pts) . '"/>'
        . '<circle cx="' . $last[0] . '" cy="' . $last[1] . '" r="2.6" fill="#2563eb"/>'
        . '</svg>';
}
```

(c) Insert into `analyticsHtml`'s `ready` branch — change the `$body` assembly line to put the sparkline after `$kpis`, before `$topPages`:
```php
$spark      = $this->analyticsSparklineSvg($a['history'] ?? []);
$sparkBlock = $spark === '' ? '' : '<p class="muted" style="margin-bottom:2px">Sessions trend</p>' . $spark;
$body = '<p class="muted">Google Analytics 4 &middot; ' . $period . '</p>' . $kpis . $sparkBlock . $topPages . $channels;
```
(`$a` is the local `$report['analytics']` already in scope.)

- [ ] **Step 5: Run green** — `COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit --filter ReportPdfServiceTest` → PASS (new + all P6.4 performance-sparkline + analytics tests stay green).

- [ ] **Step 6: Commit**

```bash
git add packages/dashboard-plugin/src/Services/ReportPdfService.php packages/dashboard-plugin/tests/Integration/Services/ReportPdfServiceTest.php
git commit -m "feat(p6-5): analytics sessions sparkline in the PDF report section"
```

---

### Task 5: Dashboard version bump v0.25.0

**Files:**
- Modify: `packages/dashboard-plugin/defyn-dashboard.php:6` and `:46`

- [ ] **Step 1: Edit header** — line 6 `* Version:           0.24.0` → `* Version:           0.25.0`.
- [ ] **Step 2: Edit constant** — line 46 `define('DEFYN_DASHBOARD_VERSION', '0.24.0');` → `define('DEFYN_DASHBOARD_VERSION', '0.25.0');`.

> Do NOT touch `Activation::SCHEMA_VERSION` (stays 16 — no schema change).

- [ ] **Step 3: Verify** — `grep -n "0.25.0" packages/dashboard-plugin/defyn-dashboard.php` → 2 matches.
- [ ] **Step 4: Commit**

```bash
git add packages/dashboard-plugin/defyn-dashboard.php
git commit -m "chore(p6-5): bump dashboard plugin to v0.25.0"
```

---

### Task 6: SPA — `reportAnalyticsSchema` gains `history`

**Files:**
- Modify: `apps/web/src/types/api.ts` (the `reportAnalyticsSchema`, ~lines 190-203)
- Modify: `apps/web/src/test/handlers.ts` (the report MSW fixture's `analytics` object — add `history: []`, belt-and-braces)
- Test: a parse test in `apps/web/tests/` (beside the project's existing api/schema tests)

- [ ] **Step 1: Read the current schema** — confirm `reportAnalyticsSchema` shape (`state` enum + `period` + `totals` + `top_pages` + `channels`) and where the report MSW fixture's `analytics` object lives in `src/test/handlers.ts`.

- [ ] **Step 2: Write the failing test** — add (flat in `apps/web/tests/`, matching where an api/schema test lives):

```typescript
import { describe, it, expect } from 'vitest';
import { reportAnalyticsSchema } from '@/types/api';

describe('reportAnalyticsSchema history', () => {
  it('parses a ready payload with a sessions history', () => {
    const parsed = reportAnalyticsSchema.parse({
      state: 'ready',
      period: { start: '2026-06-01', end: '2026-06-30' },
      totals: { sessions: 980, users: 670, pageviews: 2450, avg_engagement_seconds: 123 },
      top_pages: [],
      channels: [],
      history: [
        { period_start: '2026-05-01', sessions: 500 },
        { period_start: '2026-06-01', sessions: 980 },
      ],
    });
    expect(parsed.history).toHaveLength(2);
    expect(parsed.history[1].sessions).toBe(980);
  });

  it('defaults history to [] when absent (backward-compatible)', () => {
    const parsed = reportAnalyticsSchema.parse({
      state: 'not_connected', period: null, totals: null, top_pages: [], channels: [],
    });
    expect(parsed.history).toEqual([]);
  });
});
```

- [ ] **Step 3: Run red** — `pnpm test -- --run <the new test path>` → FAIL (`history` not in schema).

- [ ] **Step 4: Implement** — add the field to `reportAnalyticsSchema` (before the closing `});`):

```typescript
  history: z.array(z.object({ period_start: z.string(), sessions: z.number().nullable() })).default([]),
```
`.default([])` keeps existing raw payloads/MSW fixtures that omit `history` parsing cleanly (→ `[]`). Also add `history: []` to the `analytics` object in the report MSW fixture in `apps/web/src/test/handlers.ts` (belt-and-braces).

> Note: `.default([])` makes `history` present in the inferred `ReportAnalyticsData` output type, so TS object **literals** typed as `ReportAnalyticsData` must include it — handled in Task 7's component tests.

- [ ] **Step 5: Run green** — `pnpm test -- --run <the new test path>` → PASS. Then `pnpm build` → tsc clean.

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/types/api.ts apps/web/src/test/handlers.ts <the new test file>
git commit -m "feat(p6-5): reportAnalyticsSchema history field (default [])"
```

---

### Task 7: SPA — `AnalyticsTrendSparkline.tsx` + mount in `ReportAnalytics.tsx`

**Files:**
- Create: `apps/web/src/components/report/AnalyticsTrendSparkline.tsx`
- Modify: `apps/web/src/components/report/ReportAnalytics.tsx` (import + destructure `history` at line ~25 + mount between KPI grid (line ~46) and Top-pages (line ~48))
- Test: `apps/web/tests/AnalyticsTrendSparkline.test.tsx`
- Test: `apps/web/tests/ReportAnalytics.test.tsx` (extend the existing file)

- [ ] **Step 1: Read P6.4's `TrendSparkline.tsx`** as the structural template (it imports `buildSparkPoints`, renders polylines + dots, returns null when empty).

- [ ] **Step 2: Write the failing tests**

`apps/web/tests/AnalyticsTrendSparkline.test.tsx`:
```tsx
import { describe, it, expect } from 'vitest';
import { render } from '@testing-library/react';
import { AnalyticsTrendSparkline } from '@/components/report/AnalyticsTrendSparkline';

const threeMonths = [
  { period_start: '2026-04-01', sessions: 400 },
  { period_start: '2026-05-01', sessions: 500 },
  { period_start: '2026-06-01', sessions: 980 },
];

describe('AnalyticsTrendSparkline', () => {
  it('renders one polyline for a >=2-point sessions history', () => {
    const { container } = render(<AnalyticsTrendSparkline history={threeMonths} />);
    expect(container.querySelectorAll('polyline')).toHaveLength(1);
    expect(container.querySelector('polyline')?.getAttribute('stroke')).toBe('#2563eb');
  });

  it('renders nothing when fewer than 2 non-null sessions', () => {
    const { container } = render(
      <AnalyticsTrendSparkline history={[{ period_start: '2026-06-01', sessions: 980 }]} />,
    );
    expect(container.firstChild).toBeNull();
  });

  it('renders nothing when all sessions are zero (max <= 0)', () => {
    const { container } = render(
      <AnalyticsTrendSparkline
        history={[{ period_start: '2026-05-01', sessions: 0 }, { period_start: '2026-06-01', sessions: 0 }]}
      />,
    );
    expect(container.firstChild).toBeNull();
  });
});
```

Extend `apps/web/tests/ReportAnalytics.test.tsx`:
```tsx
it('renders the sessions sparkline in the ready state with history', () => {
  const analytics = {
    state: 'ready' as const,
    period: { start: '2026-06-01', end: '2026-06-30' },
    totals: { sessions: 980, users: 670, pageviews: 2450, avg_engagement_seconds: 123 },
    top_pages: [],
    channels: [],
    history: [
      { period_start: '2026-05-01', sessions: 500 },
      { period_start: '2026-06-01', sessions: 980 },
    ],
  };
  const { container, getByText } = render(<ReportAnalytics analytics={analytics} />);
  expect(container.querySelector('polyline')).not.toBeNull();   // sparkline present
  expect(getByText('Channels')).toBeInTheDocument();            // existing tables still render
});
```

> **Existing `ReportAnalytics.test.tsx` fixtures:** any object literal typed as the analytics prop now needs `history` (the `.default([])` makes it present in the inferred type). Add `history: []` to the existing `ready`/`pending`/`not_connected` fixtures so the file type-checks.

- [ ] **Step 3: Run red** — `pnpm test -- --run tests/AnalyticsTrendSparkline.test.tsx tests/ReportAnalytics.test.tsx` → FAIL (component missing).

- [ ] **Step 4: Implement the component** — create `apps/web/src/components/report/AnalyticsTrendSparkline.tsx`:

```tsx
import type { ReportAnalyticsData } from '@/types/api';
import { buildSparkPoints } from '@/lib/sparkline';

interface AnalyticsTrendSparklineProps {
  history: ReportAnalyticsData['history'];
}

const SESSIONS = '#2563eb';

function endDot(points: string): { cx: number; cy: number } | null {
  if (points === '') {
    return null;
  }
  const parts = points.split(' ');
  const [cx, cy] = parts[parts.length - 1].split(',').map(Number);
  return { cx, cy };
}

// P6.5 — relative-scaled (floor 0 → series max) monthly sessions trend line.
// Renders nothing unless at least 2 non-null sessions and max > 0.
export function AnalyticsTrendSparkline({ history }: AnalyticsTrendSparklineProps) {
  const sessions = history.map((h) => h.sessions);
  const nonNull = sessions.filter((s): s is number => s !== null);
  if (nonNull.length < 2) {
    return null;
  }
  const max = Math.max(...nonNull);
  if (max <= 0) {
    return null;
  }

  const points = buildSparkPoints(sessions, max);
  if (points === '') {
    return null;
  }
  const dot = endDot(points);
  const latest = nonNull[nonNull.length - 1];
  const first = history[0]?.period_start ?? '';
  const last = history[history.length - 1]?.period_start ?? '';

  return (
    <div className="space-y-2">
      <h3 className="text-sm font-semibold text-zinc-700">Sessions trend</h3>
      <div className="flex items-center gap-4">
        <svg
          viewBox="0 0 200 56"
          width={320}
          height={90}
          className="rounded border border-zinc-200 bg-zinc-50"
          role="img"
          aria-label="Monthly sessions trend"
        >
          <polyline fill="none" stroke={SESSIONS} strokeWidth={2} points={points} />
          {dot && <circle cx={dot.cx} cy={dot.cy} r={2.6} fill={SESSIONS} />}
        </svg>
        <div className="text-xs leading-relaxed text-zinc-600">
          <div>
            <span className="inline-block h-0.5 w-3 align-middle" style={{ background: SESSIONS }} /> Sessions{' '}
            <b>{latest.toLocaleString()}</b>
          </div>
          <div className="mt-1 text-zinc-400">
            {history.length} months · {first.slice(0, 7)} → {last.slice(0, 7)}
          </div>
        </div>
      </div>
    </div>
  );
}
```

- [ ] **Step 5: Mount in `ReportAnalytics.tsx`** — add the import at the top:
```tsx
import { AnalyticsTrendSparkline } from '@/components/report/AnalyticsTrendSparkline';
```
Add `history` to the destructure (line ~25):
```tsx
const { state, totals, top_pages, channels, history } = analytics;
```
Inside the `state === 'ready'` block, insert immediately AFTER the KPI grid `</div>` (line ~46) and BEFORE the Top-pages `<div className="space-y-2">` (line ~48):
```tsx
          <AnalyticsTrendSparkline history={history} />
```

- [ ] **Step 6: Run green** — `pnpm test -- --run tests/AnalyticsTrendSparkline.test.tsx tests/ReportAnalytics.test.tsx` → PASS. Then full suite `pnpm test -- --run` (only the 4 carry-forwards fail) + `pnpm build` → tsc clean.

- [ ] **Step 7: Commit**

```bash
git add apps/web/src/components/report/AnalyticsTrendSparkline.tsx apps/web/src/components/report/ReportAnalytics.tsx apps/web/tests/AnalyticsTrendSparkline.test.tsx apps/web/tests/ReportAnalytics.test.tsx
git commit -m "feat(p6-5): AnalyticsTrendSparkline on the on-screen report"
```

---

### Task 8: Release v0.25.0

**Files:**
- Create: `dist/defyn-dashboard-0.25.0.zip`
- Modify: memory files (Step 8)

- [ ] **Step 1: Full PHP suite** — from `packages/dashboard-plugin`: `COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit` → green except `UninstallTest` (baseline 947/1 + the new repo/compose/PDF tests). (DB-OFFLINE fallback if needed.)

- [ ] **Step 2: Full SPA suite + build** — from `apps/web` (Node 22): `pnpm test -- --run` → green except the 4 carry-forwards. Then `pnpm build` → tsc clean; note the built entry bundle filename under `apps/web/dist/assets/`.

- [ ] **Step 3: Local sample-PDF proof (the real PDF visual check — prod is zero-sites + no GA4)**

From `packages/dashboard-plugin`, write a throwaway `.claude-tmp/p65-eyeball.php` (project-local, NOT /tmp) that renders a report with a connected `ready` analytics state + a ≥3-month sessions history:

```php
<?php
require __DIR__ . '/vendor/autoload.php';
$report = [
  'site' => ['label' => 'Demo', 'url' => 'https://demo.example'],
  'period' => ['from' => '2026-06-01', 'to' => '2026-06-30'],
  'updates' => ['plugins' => [], 'themes' => [], 'core' => null],
  'security' => ['summary' => [], 'findings' => []],
  'incidents' => [],
  'performance' => ['latest' => null, 'history' => []],
  'analytics' => [
    'state'  => 'ready',
    'period' => ['start' => '2026-06-01', 'end' => '2026-06-30'],
    'totals' => ['sessions' => 980, 'users' => 670, 'pageviews' => 2450, 'avg_engagement_seconds' => 123.0],
    'top_pages' => [], 'channels' => [],
    'history' => [
      ['period_start' => '2026-04-01', 'sessions' => 420],
      ['period_start' => '2026-05-01', 'sessions' => 510],
      ['period_start' => '2026-06-01', 'sessions' => 980]],
  ],
];
$svc = new \Defyn\Dashboard\Services\ReportPdfService();
$branding = ['agency_name' => 'Defyn', 'accent_color' => '#26215C', 'logo_url' => ''];
$html = $svc->debugHtml($report, $branding);
$pdf  = $svc->render($report, $branding);
echo 'HTML has analytics polyline #2563eb: ' . (str_contains($html, 'stroke="#2563eb"') ? 'YES' : 'NO') . "\n";
echo 'PDF %PDF header: ' . (str_starts_with($pdf, '%PDF') ? 'YES' : 'NO') . ' bytes=' . strlen($pdf) . "\n";
file_put_contents(__DIR__ . '/.claude-tmp/p65-sample.pdf', $pdf);
```
Run `php .claude-tmp/p65-eyeball.php`. **Confirm both echo lines say YES.** If the `$report` shape causes a fatal, read `ReportPdfService::buildHtml`/`render` and trim `$report` to exactly the keys they need (analytics is what matters; `debugHtml`/`render` may have different shapes — match the test sampleReport). `open .claude-tmp/p65-sample.pdf` optionally. Delete both `.claude-tmp/p65-*.php`/`.pdf` after.

- [ ] **Step 4: Build the dompdf-preserving zip** — from `packages/dashboard-plugin`: `composer install --no-dev --classmap-authoritative`, then build `dist/defyn-dashboard-0.25.0.zip` from `packages/` (top folder `dashboard-plugin/`), excluding ONLY tests + dev tooling (`tests/*`, `*wp-tests-config.php`, `.phpunit.result.cache`, `test-output.log`, `phpunit.xml`, `composer.lock`, `.github/*`, `.gitignore`) — never any `vendor/*` subdir.

- [ ] **Step 5: Verify the zip retains the vendor set (incl. the SVG renderer)**

```bash
unzip -l dist/defyn-dashboard-0.25.0.zip | grep -E "symfony/deprecation-contracts/function\.php|polyfill-php83/bootstrap\.php|json-machine/src/Items\.php|dompdf/src/Dompdf\.php|dompdf/php-svg-lib|firebase/php-jwt/src/JWT\.php|Schema/SitePerformanceTable\.php|Schema/SiteAnalyticsTable\.php"
```
Expected: dompdf + **php-svg-lib** (the SVG renderer), symfony×2, json-machine, php-jwt, both Schema lines present. Then `composer install` to restore dev autoload. Confirm `git status` shows only the gitignored `dist/` zip + `.claude-tmp/` — no tracked-file changes.

- [ ] **Step 6: Merge to main**

```bash
git checkout main
git merge --no-ff p6-5-analytics-trend -m "merge: P6.5 analytics monthly trend sparkline"
git push origin main
```
(SPA auto-deploys to Cloudflare Pages from `main`.)

- [ ] **Step 7: Manual Kinsta install — PAUSE** — tell the operator the zip is at `dist/defyn-dashboard-0.25.0.zip`; ask them to upload via WP Admin (Replace current) + MyKinsta → Tools → Clear cache. **Wait for "installed"** before smoking.

- [ ] **Step 8: Indirect curl smoke (after "installed") + tag + MEMORY**

Backend `defynwp.defyn.agency`; login field `access_token`; creds (curl only) `pradeep@defyn.com.au` / `DefynWP-ifirCh5pXm5bTOj0`.
1. `GET /defyn/v1/sites/999999/report.pdf` no auth → **401** (`auth.missing_token`).
2. Login → same authed → **404** `sites.not_found` (route + v0.25.0 live; happy populated PDF foreclosed by zero-sites prod + no GA4 — the Step-3 local eyeball is the PDF proof).
3. Bogus route `GET /defyn/v1/sites/999999/reportz.pdf` authed → `rest.route_not_found` contrast.

Cloudflare deploy verify: fetch the deployed SPA bundle, confirm it contains `#2563eb` and/or `Sessions trend`.

Then:
```bash
git tag p6-5-analytics-trend-complete
git push origin p6-5-analytics-trend-complete
```
Append a P6.5-complete entry to `~/.claude/projects/-Users-pradeep-Local-Sites-defynWP/memory/project_defyn_roadmap.md` + refresh the `MEMORY.md` roadmap pointer (dashboard v0.25.0, schema still v16, connector v0.1.7, accumulate-only sessions trend on the analytics report + PDF, generalized sparkline helpers, tag `p6-5-analytics-trend-complete`, NEXT = operator's choice; backfill still deferred).

---

## Self-Review (completed by plan author)

**1. Spec coverage:** §4.1 (recent-months query) → Task 1; §4.2 (compose history) → Task 2; §5.1 (TS generalize) → Task 3; §5.2 (PHP generalize + `analyticsSparklineSvg`) + §6.1 (PDF section) → Task 4; §7 (version) → Task 5; §6.2 (schema) → Task 6; §6.3 (component + mount) → Task 7; §8 testing distributed across tasks; §9 build order → Tasks 1-8.

**2. Placeholder scan:** No TBD/TODO. Every code step is complete. The "match the real `compose()`/`branding()`/`sampleReport()`/setter name" notes reference concrete sibling files the implementer reads in each task's Step 1 — deliberate (project-specific scaffolds, not placeholders).

**3. Type consistency:** `findRecentForSite(int, int): SiteAnalytics[]` (Task 1) feeds `buildAnalytics`'s `history` of `{period_start, sessions}` (Task 2) = `reportAnalyticsSchema.history` `{ period_start: string, sessions: number|null }` (Task 6) = `AnalyticsTrendSparkline` prop `ReportAnalyticsData['history']` (Task 7) = PHP `analyticsSparklineSvg`'s `$history` (Task 4). The generalized `sparkY(value, max=100, …)` / `buildSparkPoints(scores, max=100, …)` (TS, Task 3) and `sparkY(int $value, int $max=100)` / `sparkPoints(array $values, int $max=100)` (PHP, Task 4) share the same `max`-default-100 contract; P6.4 call sites pass no `max` → unchanged. Analytics colour `#2563eb`, viewBox `0 0 200 56`, end-dot `r=2.6`, the "Sessions trend" label, and the ≥2-non-null-points + `max>0` guard are identical between the PHP `analyticsSparklineSvg` and the TS `AnalyticsTrendSparkline`.
