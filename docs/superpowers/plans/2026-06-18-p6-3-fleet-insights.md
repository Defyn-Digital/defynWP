# P6.3 — Fleet Insights (`/insights`) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a single read-only `/insights` page that surfaces Performance (PageSpeed) and Analytics (GA4) across the whole fleet, worst-first, each row drilling into Site detail.

**Architecture:** Mirrors the P4.2 `/security` fleet page exactly. Two new `ONLY_FULL_GROUP_BY`-safe "latest snapshot per site" repository queries → one `InsightsService::compose($userId)` returning a direct payload → one `GET /insights` controller (30/min) → SPA page cloned from `Security.tsx` with two stacked sections. **Pure read over the existing P6.1/P6.2 weekly snapshots: no schema change, no new jobs, no connector change.**

**Tech Stack:** PHP 8.1 (WP plugin, PHPUnit/wp-phpunit), React 18 + TypeScript + TanStack Query v5 + Zod + Vitest + MSW (pnpm, Node 22 via fnm). Dashboard v0.22.0 → v0.23.0. Schema unchanged (v16). Connector unchanged (v0.1.7).

**Spec:** `docs/superpowers/specs/2026-06-18-p6-3-fleet-insights-design.md`
**Branch:** `p6-3-fleet-insights` (already created, off main @ 8e0740e).

---

## Conventions for every task

- **PHP tests** run from `packages/dashboard-plugin`: `COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit --filter <Name>`. Full suite: `COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit` (tolerate only the known `UninstallTest` carry-forward).
- **DB-OFFLINE fallback:** if integration tests error on a DB connection, the Local `defynWP` DB is stopped — start a standalone mysqld against the existing `defyn_test` datadir (`~/Library/Application Support/Local/run/50bJKdbjK/mysql/data`, port 10166, its own socket), run the tests, shut it down cleanly. **Never** modify the gitignored `wp-tests-config.php`.
- **SPA tests** run from `apps/web` under Node 22: `export FNM_DIR="$HOME/.fnm"; eval "$(fnm env --shell bash)"; fnm use 22; pnpm test -- --run <path>`. Carry-forward baseline = 4 failures (`tests/SiteDetail.test.tsx` ×2 + `tests/components/sites/SiteCoreCard.test.tsx` ×2). `pnpm build` runs `tsc` — a vitest-green test can still fail the typecheck.
- Commit after each task with the message shown in its final step.

---

### Task 1: `SitePerformanceRepository::findFleetForUser`

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/SitePerformanceRepository.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/SitePerformanceRepositoryTest.php`

- [ ] **Step 1: Write the failing test**

Append these methods to the existing `SitePerformanceRepositoryTest` class. Reuse the file's existing `seedSite(...)` helper and `setUp()` (which already purges `defyn_site_performance` + `defyn_sites` and runs `Activation::ensureSchema()`). If the existing `seedSite` does not accept a `user_id`, copy the column set from `tests/Integration/Services/ReportsRepositoryTest.php::seedSite` (`id,user_id,url,label,status,created_at,updated_at,wp_version`). Seed performance rows via the repo's own `store()`.

```php
public function testFindFleetForUserReturnsLatestSnapshotPerSite(): void
{
    $this->seedSite(1, 7, 'https://a.example', 'Alpha');
    $this->seedSite(2, 7, 'https://b.example', 'Bravo');
    $this->seedSite(3, 7, 'https://c.example', 'Charlie'); // never measured

    $repo = new SitePerformanceRepository();
    // Alpha measured twice — the later fetched_at must win.
    $repo->store(1, ['score' => 30, 'lcp_ms' => 5000, 'cls' => 0.2, 'inp_ms' => 300],
                    ['score' => 80, 'lcp_ms' => 2000, 'cls' => 0.05, 'inp_ms' => 100], '2026-05-01 00:00:00', '2026-05-01 00:00:00');
    $repo->store(1, ['score' => 42, 'lcp_ms' => 4600, 'cls' => 0.1, 'inp_ms' => 250],
                    ['score' => 71, 'lcp_ms' => 2400, 'cls' => 0.08, 'inp_ms' => 120], '2026-06-01 00:00:00', '2026-06-01 00:00:00');
    $repo->store(2, ['score' => 90, 'lcp_ms' => 1800, 'cls' => 0.02, 'inp_ms' => 80], null, '2026-06-01 00:00:00', '2026-06-01 00:00:00');

    $rows = $repo->findFleetForUser(7);
    $this->assertCount(3, $rows);

    $byId = [];
    foreach ($rows as $r) { $byId[$r['site_id']] = $r; }

    $this->assertSame(42, $byId[1]['mobile_score']);   // latest snapshot, not 30
    $this->assertSame(71, $byId[1]['desktop_score']);
    $this->assertSame(4600, $byId[1]['mobile_lcp_ms']);
    $this->assertSame('2026-06-01 00:00:00', $byId[1]['fetched_at']);

    $this->assertSame(90, $byId[2]['mobile_score']);
    $this->assertNull($byId[2]['desktop_score']);      // desktop was null

    $this->assertNull($byId[3]['mobile_score']);       // never measured
    $this->assertNull($byId[3]['fetched_at']);
    $this->assertSame('Charlie', $byId[3]['label']);
}

public function testFindFleetForUserExcludesOtherOwners(): void
{
    $this->seedSite(1, 7, 'https://a.example', 'Alpha');
    $this->seedSite(2, 9, 'https://x.example', 'Other'); // different user

    $rows = (new SitePerformanceRepository())->findFleetForUser(7);
    $this->assertCount(1, $rows);
    $this->assertSame(1, $rows[0]['site_id']);
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit --filter SitePerformanceRepositoryTest`
Expected: FAIL — `Call to undefined method ...::findFleetForUser()`.

- [ ] **Step 3: Implement**

In `SitePerformanceRepository.php`, add the `SitesTable` import below the existing `use` lines:

```php
use Defyn\Dashboard\Schema\SitesTable;
```

Add this method to the class (after `findForSiteInRange`):

```php
/**
 * P6.3 — fleet rollup: every site owned by $userId LEFT JOIN its latest
 * performance snapshot (one row per site; nulls when never measured).
 * ONLY_FULL_GROUP_BY-safe — no GROUP BY; the correlated id subquery picks
 * exactly one row per site (newest fetched_at, id-tiebroken).
 *
 * @return list<array{site_id:int,label:string,url:string,mobile_score:?int,desktop_score:?int,mobile_lcp_ms:?int,fetched_at:?string}>
 */
public function findFleetForUser(int $userId): array
{
    global $wpdb;
    $perf  = SitePerformanceTable::tableName();
    $sites = SitesTable::tableName();

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT s.id AS site_id, s.label AS label, s.url AS url,
                p.mobile_score, p.desktop_score, p.mobile_lcp_ms, p.fetched_at
           FROM {$sites} s
           LEFT JOIN {$perf} p
             ON p.site_id = s.id
            AND p.id = (
                SELECT p2.id FROM {$perf} p2
                 WHERE p2.site_id = s.id
                 ORDER BY p2.fetched_at DESC, p2.id DESC
                 LIMIT 1
            )
          WHERE s.user_id = %d
          ORDER BY s.id ASC",
        $userId
    ), ARRAY_A) ?: [];

    return array_map(static fn (array $r): array => [
        'site_id'       => (int) $r['site_id'],
        'label'         => (string) $r['label'],
        'url'           => (string) $r['url'],
        'mobile_score'  => $r['mobile_score']  !== null ? (int) $r['mobile_score']  : null,
        'desktop_score' => $r['desktop_score'] !== null ? (int) $r['desktop_score'] : null,
        'mobile_lcp_ms' => $r['mobile_lcp_ms'] !== null ? (int) $r['mobile_lcp_ms'] : null,
        'fetched_at'    => $r['fetched_at']    !== null ? (string) $r['fetched_at']  : null,
    ], $rows);
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit --filter SitePerformanceRepositoryTest`
Expected: PASS (all methods).

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Services/SitePerformanceRepository.php packages/dashboard-plugin/tests/Integration/Services/SitePerformanceRepositoryTest.php
git commit -m "feat(p6-3): SitePerformanceRepository::findFleetForUser fleet rollup"
```

---

### Task 2: `SiteAnalyticsRepository::findFleetForUser`

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/SiteAnalyticsRepository.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/SiteAnalyticsRepositoryTest.php`

- [ ] **Step 1: Write the failing test**

Append to the existing `SiteAnalyticsRepositoryTest` class. Reuse its `seedSite`/`setUp`. `ga4_property_id` is set via `SitesRepository::setGa4PropertyId($siteId, $value)`. Seed analytics rows via the repo's `upsertForSiteAndPeriod`.

```php
public function testFindFleetForUserReturnsLatestMonthPerSite(): void
{
    $this->seedSite(1, 7, 'https://a.example', 'Alpha');
    $this->seedSite(2, 7, 'https://b.example', 'Bravo');   // property, no snapshot
    $this->seedSite(3, 7, 'https://c.example', 'Charlie'); // no property

    (new \Defyn\Dashboard\Services\SitesRepository())->setGa4PropertyId(1, '111111');
    (new \Defyn\Dashboard\Services\SitesRepository())->setGa4PropertyId(2, '222222');

    $repo = new SiteAnalyticsRepository();
    $repo->upsertForSiteAndPeriod(1, '2026-04-01', '2026-04-30',
        ['sessions' => 500, 'users' => 400, 'pageviews' => 1200, 'avg_engagement' => 60.0, 'top_pages' => [], 'channels' => []],
        '2026-05-01 00:00:00', '2026-05-01 00:00:00');
    $repo->upsertForSiteAndPeriod(1, '2026-05-01', '2026-05-31',
        ['sessions' => 1240, 'users' => 910, 'pageviews' => 3410, 'avg_engagement' => 72.0, 'top_pages' => [], 'channels' => []],
        '2026-06-01 00:00:00', '2026-06-01 00:00:00');

    $rows = $repo->findFleetForUser(7);
    $this->assertCount(3, $rows);
    $byId = [];
    foreach ($rows as $r) { $byId[$r['site_id']] = $r; }

    $this->assertSame('111111', $byId[1]['ga4_property_id']);
    $this->assertSame(1240, $byId[1]['sessions']);          // latest month wins
    $this->assertSame('2026-05-01', $byId[1]['period_start']);
    $this->assertSame(72.0, $byId[1]['avg_session_duration']);

    $this->assertSame('222222', $byId[2]['ga4_property_id']); // connected, no data
    $this->assertNull($byId[2]['sessions']);
    $this->assertNull($byId[2]['fetched_at']);

    $this->assertNull($byId[3]['ga4_property_id']);           // not connected
    $this->assertNull($byId[3]['sessions']);
}

public function testFindFleetForUserExcludesOtherOwners(): void
{
    $this->seedSite(1, 7, 'https://a.example', 'Alpha');
    $this->seedSite(2, 9, 'https://x.example', 'Other');
    $rows = (new SiteAnalyticsRepository())->findFleetForUser(7);
    $this->assertCount(1, $rows);
    $this->assertSame(1, $rows[0]['site_id']);
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit --filter SiteAnalyticsRepositoryTest`
Expected: FAIL — undefined method `findFleetForUser`.

- [ ] **Step 3: Implement**

In `SiteAnalyticsRepository.php`, add below the existing `use` lines:

```php
use Defyn\Dashboard\Schema\SitesTable;
```

Add this method to the class (after `latestForSite`):

```php
/**
 * P6.3 — fleet rollup: every site owned by $userId LEFT JOIN its latest GA4
 * snapshot (most recent period_start), plus ga4_property_id so the UI can tell
 * "not connected" (no property) from "connected, no data yet". Same
 * ONLY_FULL_GROUP_BY-safe correlated-id form as the performance rollup.
 *
 * @return list<array{site_id:int,label:string,url:string,ga4_property_id:?string,sessions:?int,total_users:?int,screen_page_views:?int,avg_session_duration:?float,period_start:?string,period_end:?string,fetched_at:?string}>
 */
public function findFleetForUser(int $userId): array
{
    global $wpdb;
    $analytics = SiteAnalyticsTable::tableName();
    $sites     = SitesTable::tableName();

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT s.id AS site_id, s.label AS label, s.url AS url, s.ga4_property_id AS ga4_property_id,
                a.sessions, a.total_users, a.screen_page_views, a.avg_session_duration,
                a.period_start, a.period_end, a.fetched_at
           FROM {$sites} s
           LEFT JOIN {$analytics} a
             ON a.site_id = s.id
            AND a.id = (
                SELECT a2.id FROM {$analytics} a2
                 WHERE a2.site_id = s.id
                 ORDER BY a2.period_start DESC, a2.id DESC
                 LIMIT 1
            )
          WHERE s.user_id = %d
          ORDER BY s.id ASC",
        $userId
    ), ARRAY_A) ?: [];

    return array_map(static fn (array $r): array => [
        'site_id'              => (int) $r['site_id'],
        'label'                => (string) $r['label'],
        'url'                  => (string) $r['url'],
        'ga4_property_id'      => ($r['ga4_property_id'] !== null && $r['ga4_property_id'] !== '') ? (string) $r['ga4_property_id'] : null,
        'sessions'             => $r['sessions']             !== null ? (int) $r['sessions'] : null,
        'total_users'          => $r['total_users']          !== null ? (int) $r['total_users'] : null,
        'screen_page_views'    => $r['screen_page_views']    !== null ? (int) $r['screen_page_views'] : null,
        'avg_session_duration' => $r['avg_session_duration'] !== null ? (float) $r['avg_session_duration'] : null,
        'period_start'         => $r['period_start']         !== null ? (string) $r['period_start'] : null,
        'period_end'           => $r['period_end']           !== null ? (string) $r['period_end'] : null,
        'fetched_at'           => $r['fetched_at']           !== null ? (string) $r['fetched_at'] : null,
    ], $rows);
}
```

> Note: if the existing test's `setUp` does not already purge `wp_defyn_site_analytics`, add a `DELETE FROM` for it so the new tests are isolated — mirror how the file already purges its other tables.

- [ ] **Step 4: Run to verify it passes**

Run: `COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit --filter SiteAnalyticsRepositoryTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Services/SiteAnalyticsRepository.php packages/dashboard-plugin/tests/Integration/Services/SiteAnalyticsRepositoryTest.php
git commit -m "feat(p6-3): SiteAnalyticsRepository::findFleetForUser fleet rollup"
```

---

### Task 3: `InsightsService::compose`

**Files:**
- Create: `packages/dashboard-plugin/src/Services/InsightsService.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/InsightsServiceTest.php`

- [ ] **Step 1: Write the failing test**

`InsightsService` default-constructs the two (final) repositories, so this is an integration test that seeds the DB then calls `compose`. Copy the `setUp` + `seedSite` scaffold from `SitePerformanceRepositoryTest` (purge `defyn_sites` + `defyn_site_performance` + `defyn_site_analytics`, `Activation::ensureSchema()`).

```php
<?php
declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Services\InsightsService;
use Defyn\Dashboard\Services\SitePerformanceRepository;
use Defyn\Dashboard\Services\SiteAnalyticsRepository;
use Defyn\Dashboard\Services\SitesRepository;
use WP_UnitTestCase;

final class InsightsServiceTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb->query('SET autocommit = 1');
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_site_performance");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_site_analytics");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_sites");
        Activation::ensureSchema();
    }

    private function seedSite(int $id, int $userId, string $url, string $label): void
    {
        global $wpdb;
        $wpdb->insert("{$wpdb->prefix}defyn_sites", [
            'id' => $id, 'user_id' => $userId, 'url' => $url, 'label' => $label,
            'status' => 'active', 'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00', 'wp_version' => '6.8',
        ]);
    }

    public function testComposePerformanceSummaryAndWorstFirstOrder(): void
    {
        $this->seedSite(1, 7, 'https://a.example', 'Alpha');
        $this->seedSite(2, 7, 'https://b.example', 'Bravo');
        $this->seedSite(3, 7, 'https://c.example', 'Charlie'); // never measured

        $perf = new SitePerformanceRepository();
        $perf->store(1, ['score' => 80, 'lcp_ms' => 2000, 'cls' => 0.05, 'inp_ms' => 100],
                        ['score' => 95, 'lcp_ms' => 1500, 'cls' => 0.02, 'inp_ms' => 80], '2026-06-01 00:00:00', '2026-06-01 00:00:00');
        $perf->store(2, ['score' => 40, 'lcp_ms' => 4600, 'cls' => 0.2, 'inp_ms' => 300],
                        ['score' => 70, 'lcp_ms' => 2600, 'cls' => 0.1, 'inp_ms' => 200], '2026-06-01 00:00:00', '2026-06-01 00:00:00');

        $out = (new InsightsService())->compose(7);
        $p = $out['performance'];

        $this->assertSame(3, $p['summary']['total_sites']);
        $this->assertSame(2, $p['summary']['measured']);
        $this->assertSame(60, $p['summary']['avg_mobile']);   // round((80+40)/2)
        $this->assertSame(83, $p['summary']['avg_desktop']);  // round((95+70)/2)
        $this->assertSame(1, $p['summary']['slow_sites']);    // only Bravo (40 < 50)

        // Worst-first: Bravo(40) then Alpha(80) then never-measured Charlie last.
        $this->assertSame([2, 1, 3], array_column($p['sites'], 'site_id'));
    }

    public function testComposeAnalyticsSummaryAndOrdering(): void
    {
        $this->seedSite(1, 7, 'https://a.example', 'Alpha');   // connected + data, high sessions
        $this->seedSite(2, 7, 'https://b.example', 'Bravo');   // connected + data, low sessions
        $this->seedSite(3, 7, 'https://c.example', 'Charlie'); // connected, no data
        $this->seedSite(4, 7, 'https://d.example', 'Delta');   // not connected

        $sites = new SitesRepository();
        $sites->setGa4PropertyId(1, '111');
        $sites->setGa4PropertyId(2, '222');
        $sites->setGa4PropertyId(3, '333');

        $a = new SiteAnalyticsRepository();
        $a->upsertForSiteAndPeriod(1, '2026-05-01', '2026-05-31',
            ['sessions' => 9000, 'users' => 6000, 'pageviews' => 24000, 'avg_engagement' => 120.0, 'top_pages' => [], 'channels' => []],
            '2026-06-01 00:00:00', '2026-06-01 00:00:00');
        $a->upsertForSiteAndPeriod(2, '2026-05-01', '2026-05-31',
            ['sessions' => 300, 'users' => 200, 'pageviews' => 900, 'avg_engagement' => 60.0, 'top_pages' => [], 'channels' => []],
            '2026-06-01 00:00:00', '2026-06-01 00:00:00');

        $out = (new InsightsService())->compose(7);
        $an = $out['analytics'];

        $this->assertSame(4, $an['summary']['total_sites']);
        $this->assertSame(3, $an['summary']['connected']);       // 1,2,3 have a property
        $this->assertSame(9300, $an['summary']['total_sessions']);
        $this->assertSame(6200, $an['summary']['total_users']);

        // Worst-first among connected-with-data (low sessions first): Bravo, Alpha,
        // then property-no-data Charlie, then not-connected Delta.
        $this->assertSame([2, 1, 3, 4], array_column($an['sites'], 'site_id'));
    }

    public function testComposeAvgNullWhenNothingMeasured(): void
    {
        $this->seedSite(1, 7, 'https://a.example', 'Alpha');
        $out = (new InsightsService())->compose(7);
        $this->assertNull($out['performance']['summary']['avg_mobile']);
        $this->assertNull($out['performance']['summary']['avg_desktop']);
        $this->assertSame(0, $out['performance']['summary']['measured']);
        $this->assertArrayHasKey('generated_at', $out);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit --filter InsightsServiceTest`
Expected: FAIL — class `InsightsService` not found.

- [ ] **Step 3: Implement**

Create `packages/dashboard-plugin/src/Services/InsightsService.php`:

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Services;

/**
 * P6.3 — combined fleet Performance + Analytics rollup for /insights.
 * Read-only over the P6.1/P6.2 weekly snapshots. Direct payload (no {data}
 * envelope), mirroring SecurityService. Worst-first ordering + summaries are
 * computed here; the repositories only fetch the latest snapshot per site.
 */
final class InsightsService
{
    /** A mobile PSI score below this is a "slow site needing attention". */
    private const SLOW_SCORE_THRESHOLD = 50;

    public function __construct(
        private readonly SitePerformanceRepository $performance = new SitePerformanceRepository(),
        private readonly SiteAnalyticsRepository $analytics = new SiteAnalyticsRepository(),
    ) {
    }

    public function compose(int $userId): array
    {
        return [
            'performance'  => $this->performanceSection($userId),
            'analytics'    => $this->analyticsSection($userId),
            'generated_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    private function performanceSection(int $userId): array
    {
        $rows = $this->performance->findFleetForUser($userId);

        $mobileScores = [];
        $desktopScores = [];
        $slow = 0;
        $measured = 0;
        foreach ($rows as $r) {
            if ($r['fetched_at'] === null) {
                continue;
            }
            $measured++;
            if ($r['mobile_score'] !== null) {
                $mobileScores[] = $r['mobile_score'];
                if ($r['mobile_score'] < self::SLOW_SCORE_THRESHOLD) {
                    $slow++;
                }
            }
            if ($r['desktop_score'] !== null) {
                $desktopScores[] = $r['desktop_score'];
            }
        }

        usort($rows, static function (array $a, array $b): int {
            // (1) measured before never-measured; (2) within measured, lowest mobile_score first.
            $am = $a['fetched_at'] !== null ? 0 : 1;
            $bm = $b['fetched_at'] !== null ? 0 : 1;
            if ($am !== $bm) {
                return $am <=> $bm;
            }
            if ($am === 0) {
                $cmp = ($a['mobile_score'] ?? PHP_INT_MAX) <=> ($b['mobile_score'] ?? PHP_INT_MAX);
                if ($cmp !== 0) {
                    return $cmp;
                }
            }
            return strcmp($a['label'], $b['label']);
        });

        return [
            'summary' => [
                'total_sites' => count($rows),
                'measured'    => $measured,
                'avg_mobile'  => $mobileScores === [] ? null : (int) round(array_sum($mobileScores) / count($mobileScores)),
                'avg_desktop' => $desktopScores === [] ? null : (int) round(array_sum($desktopScores) / count($desktopScores)),
                'slow_sites'  => $slow,
            ],
            'sites' => array_values($rows),
        ];
    }

    private function analyticsSection(int $userId): array
    {
        $rows = $this->analytics->findFleetForUser($userId);

        $connected = 0;
        $totalSessions = 0;
        $totalUsers = 0;
        foreach ($rows as $r) {
            if ($r['ga4_property_id'] !== null && $r['ga4_property_id'] !== '') {
                $connected++;
            }
            $totalSessions += $r['sessions'] ?? 0;
            $totalUsers    += $r['total_users'] ?? 0;
        }

        $rank = static function (array $x): int {
            $hasProp = $x['ga4_property_id'] !== null && $x['ga4_property_id'] !== '';
            if ($hasProp && $x['fetched_at'] !== null) {
                return 0; // connected with data
            }
            if ($hasProp) {
                return 1; // property but no snapshot
            }
            return 2;     // not connected
        };

        usort($rows, static function (array $a, array $b) use ($rank): int {
            $ra = $rank($a);
            $rb = $rank($b);
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            if ($ra === 0) {
                // worst-first = lowest sessions first.
                $cmp = ($a['sessions'] ?? PHP_INT_MAX) <=> ($b['sessions'] ?? PHP_INT_MAX);
                if ($cmp !== 0) {
                    return $cmp;
                }
            }
            return strcmp($a['label'], $b['label']);
        });

        return [
            'summary' => [
                'total_sites'    => count($rows),
                'connected'      => $connected,
                'total_sessions' => $totalSessions,
                'total_users'    => $totalUsers,
            ],
            'sites' => array_values($rows),
        ];
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit --filter InsightsServiceTest`
Expected: PASS (all three methods).

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Services/InsightsService.php packages/dashboard-plugin/tests/Integration/Services/InsightsServiceTest.php
git commit -m "feat(p6-3): InsightsService composes fleet performance + analytics rollup"
```

---

### Task 4: `InsightsController` + `RateLimit::insights` + route + CORS

**Files:**
- Create: `packages/dashboard-plugin/src/Rest/InsightsController.php`
- Modify: `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php`
- Modify: `packages/dashboard-plugin/src/Rest/RestRouter.php`
- Test: `packages/dashboard-plugin/tests/Integration/Rest/InsightsTest.php`
- Test: `packages/dashboard-plugin/tests/Integration/Rest/InsightsCorsTest.php`

- [ ] **Step 1: Write the failing tests**

Mirror the existing `tests/Integration/Rest/SecurityFleetTest.php` for the controller test and `tests/Integration/Rest/SecurityFleetCorsTest.php` for the CORS test — read those two files and clone them, swapping route `/security` → `/insights`, controller/service names, and the rate-limit code. The controller test MUST cover: (a) 401 when unauthenticated, (b) 200 with the `performance`/`analytics`/`generated_at` top-level keys when authenticated, (c) the route resolves (not `rest_no_route`), and (d) a 429 with code `insights.rate_limited` after `INSIGHTS_LIMIT` authenticated GETs in one minute. Use whatever authenticated-request helper `SecurityFleetTest` already uses (it sets `_authenticated_user_id` via a valid JWT or a test login helper — reuse it verbatim).

The CORS test asserts the `/insights` route is registered and emits the same CORS headers the other GET routes do (clone `SecurityFleetCorsTest` assertions exactly).

- [ ] **Step 2: Run to verify they fail**

Run: `COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit --filter "InsightsTest|InsightsCorsTest"`
Expected: FAIL — controller class missing / route not registered.

- [ ] **Step 3: Implement**

Create `packages/dashboard-plugin/src/Rest/InsightsController.php`:

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Services\InsightsService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P6.3 — GET /defyn/v1/insights. Read-only combined fleet Performance + Analytics
 * rollup. Mirrors SecurityController: direct payload, 30/min bucket, ownership
 * scoped via InsightsService::compose($userId).
 */
final class InsightsController
{
    public function __construct(
        private readonly InsightsService $service = new InsightsService(),
    ) {
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        return new WP_REST_Response($this->service->compose($userId), 200);
    }
}
```

In `RateLimit.php`, add two constants next to the `SECURITY_LIMIT`/`SECURITY_WINDOW` block:

```php
public const INSIGHTS_LIMIT  = 30;
public const INSIGHTS_WINDOW = MINUTE_IN_SECONDS;
```

And add this method next to `security()` (clone of it):

```php
/**
 * Permission callback for GET /insights. Per-user, 30/MINUTE — same read
 * weight class as security()/monitoring(). Distinct prefix `defyn_rl_insights_%d`.
 */
public static function insights(WP_REST_Request $request)
{
    $authResult = RequireAuth::check($request);
    if (is_wp_error($authResult)) {
        return $authResult;
    }

    $userId = (int) $request->get_param('_authenticated_user_id');

    $key   = sprintf('defyn_rl_insights_%d', $userId);
    $count = (int) (get_transient($key) ?: 0);

    if ($count >= self::INSIGHTS_LIMIT) {
        return new \WP_Error(
            'insights.rate_limited',
            'Too many requests. Try again shortly.',
            ['status' => 429]
        );
    }

    set_transient($key, $count + 1, self::INSIGHTS_WINDOW);
    return true;
}
```

In `RestRouter.php`, add the import beside the other `Rest\*Controller` uses (near line 11):

```php
use Defyn\Dashboard\Rest\InsightsController;
```

And register the route immediately after the `/security/scan-all` block (after line 508):

```php
        // P6.3 — GET /insights. Read-only combined fleet Performance + Analytics
        // rollup over the P6.1/P6.2 weekly snapshots. RateLimit::insights chains
        // RequireAuth::check internally and adds a per-user 30/MINUTE throttle.
        // Ownership-scoped via InsightsService::compose($userId).
        register_rest_route(self::NAMESPACE, '/insights', [
            'methods'             => 'GET',
            'callback'            => [new InsightsController(), 'handle'],
            'permission_callback' => [RateLimit::class, 'insights'],
        ]);
```

- [ ] **Step 4: Run to verify they pass**

Run: `COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit --filter "InsightsTest|InsightsCorsTest"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Rest/InsightsController.php packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php packages/dashboard-plugin/src/Rest/RestRouter.php packages/dashboard-plugin/tests/Integration/Rest/InsightsTest.php packages/dashboard-plugin/tests/Integration/Rest/InsightsCorsTest.php
git commit -m "feat(p6-3): GET /insights endpoint + 30/min bucket + route + CORS"
```

---

### Task 5: Dashboard version bump v0.23.0

**Files:**
- Modify: `packages/dashboard-plugin/defyn-dashboard.php:6` and `:46`

- [ ] **Step 1: Edit the header**

Change line 6 from `* Version:           0.22.0` to `* Version:           0.23.0`.

- [ ] **Step 2: Edit the constant**

Change line 46 from `define('DEFYN_DASHBOARD_VERSION', '0.22.0');` to `define('DEFYN_DASHBOARD_VERSION', '0.23.0');`.

> Do NOT touch `Activation::SCHEMA_VERSION` — it stays at 16 (this slice adds no schema).

- [ ] **Step 3: Verify**

Run: `grep -n "0.23.0" packages/dashboard-plugin/defyn-dashboard.php`
Expected: two matches (line 6 + line 46).

- [ ] **Step 4: Commit**

```bash
git add packages/dashboard-plugin/defyn-dashboard.php
git commit -m "chore(p6-3): bump dashboard plugin to v0.23.0"
```

---

### Task 6: SPA — `insightsSchema` + `psiBand` helper + MSW handler

**Files:**
- Modify: `apps/web/src/types/api.ts` (append after the security schemas, ~line 539)
- Create: `apps/web/src/lib/psiBand.ts`
- Modify: `apps/web/src/test/handlers.ts` (add GET `/insights` handler near the security handlers, ~line 859)
- Test: `apps/web/src/lib/__tests__/psiBand.test.ts`
- Test: `apps/web/src/types/__tests__/insightsSchema.test.ts`

> Place each new test beside the project's existing tests for that kind of module. If the repo keeps lib tests at `apps/web/src/lib/<name>.test.ts` rather than a `__tests__` dir, follow that convention — check where the `coreWebVitals` / `engagement` tests live and match it.

- [ ] **Step 1: Write the failing tests**

`psiBand.test.ts`:

```typescript
import { describe, it, expect } from 'vitest';
import { psiBand } from '@/lib/psiBand';

describe('psiBand', () => {
  it.each([
    [90, 'good'],
    [100, 'good'],
    [89, 'needs-improvement'],
    [50, 'needs-improvement'],
    [49, 'poor'],
    [0, 'poor'],
  ])('maps %i to %s', (score, expected) => {
    expect(psiBand(score)).toBe(expected);
  });

  it('maps null to unknown', () => {
    expect(psiBand(null)).toBe('unknown');
  });
});
```

`insightsSchema.test.ts`:

```typescript
import { describe, it, expect } from 'vitest';
import { insightsSchema } from '@/types/api';

describe('insightsSchema', () => {
  it('parses a representative payload with null metric fields', () => {
    const payload = {
      performance: {
        summary: { total_sites: 2, measured: 1, avg_mobile: 42, avg_desktop: null, slow_sites: 1 },
        sites: [
          { site_id: 1, label: 'A', url: 'https://a', mobile_score: 42, desktop_score: 71, mobile_lcp_ms: 4600, fetched_at: '2026-06-01 00:00:00' },
          { site_id: 2, label: 'B', url: 'https://b', mobile_score: null, desktop_score: null, mobile_lcp_ms: null, fetched_at: null },
        ],
      },
      analytics: {
        summary: { total_sites: 2, connected: 1, total_sessions: 1240, total_users: 910 },
        sites: [
          { site_id: 1, label: 'A', url: 'https://a', ga4_property_id: '111', sessions: 1240, total_users: 910, screen_page_views: 3410, avg_session_duration: 72, period_start: '2026-05-01', period_end: '2026-05-31', fetched_at: '2026-06-01 00:00:00' },
          { site_id: 2, label: 'B', url: 'https://b', ga4_property_id: null, sessions: null, total_users: null, screen_page_views: null, avg_session_duration: null, period_start: null, period_end: null, fetched_at: null },
        ],
      },
      generated_at: '2026-06-18 00:00:00',
    };
    expect(() => insightsSchema.parse(payload)).not.toThrow();
  });
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `pnpm test -- --run src/lib/__tests__/psiBand.test.ts src/types/__tests__/insightsSchema.test.ts`
Expected: FAIL — module / export missing.

- [ ] **Step 3: Implement**

Create `apps/web/src/lib/psiBand.ts`:

```typescript
export type PsiBand = 'good' | 'needs-improvement' | 'poor' | 'unknown';

/**
 * P6.3 — map a PageSpeed performance score (0–100) to its Google band:
 * 90–100 good, 50–89 needs-improvement, 0–49 poor. null → unknown.
 */
export function psiBand(score: number | null): PsiBand {
  if (score === null) return 'unknown';
  if (score >= 90) return 'good';
  if (score >= 50) return 'needs-improvement';
  return 'poor';
}
```

Append to `apps/web/src/types/api.ts` (after the security schemas block):

```typescript
// P6.3 — Fleet Insights (/insights) schemas. Read-only rollup of the latest
// per-site performance + analytics snapshots.
export const performanceFleetRowSchema = z.object({
  site_id: z.number().int().positive(),
  label: z.string(),
  url: z.string(),
  mobile_score: z.number().int().nullable(),
  desktop_score: z.number().int().nullable(),
  mobile_lcp_ms: z.number().int().nullable(),
  fetched_at: z.string().nullable(),
});
export type PerformanceFleetRow = z.infer<typeof performanceFleetRowSchema>;

export const analyticsFleetRowSchema = z.object({
  site_id: z.number().int().positive(),
  label: z.string(),
  url: z.string(),
  ga4_property_id: z.string().nullable(),
  sessions: z.number().int().nullable(),
  total_users: z.number().int().nullable(),
  screen_page_views: z.number().int().nullable(),
  avg_session_duration: z.number().nullable(),
  period_start: z.string().nullable(),
  period_end: z.string().nullable(),
  fetched_at: z.string().nullable(),
});
export type AnalyticsFleetRow = z.infer<typeof analyticsFleetRowSchema>;

export const insightsSchema = z.object({
  performance: z.object({
    summary: z.object({
      total_sites: z.number().int().nonnegative(),
      measured: z.number().int().nonnegative(),
      avg_mobile: z.number().int().nullable(),
      avg_desktop: z.number().int().nullable(),
      slow_sites: z.number().int().nonnegative(),
    }),
    sites: z.array(performanceFleetRowSchema),
  }),
  analytics: z.object({
    summary: z.object({
      total_sites: z.number().int().nonnegative(),
      connected: z.number().int().nonnegative(),
      total_sessions: z.number().int().nonnegative(),
      total_users: z.number().int().nonnegative(),
    }),
    sites: z.array(analyticsFleetRowSchema),
  }),
  generated_at: z.string(),
});
export type Insights = z.infer<typeof insightsSchema>;
```

Add a default GET `/insights` handler in `apps/web/src/test/handlers.ts` (beside the `*/wp-json/defyn/v1/security` handler). Use a representative non-empty fixture so the hook + page tests have data:

```typescript
  // P6.3 — GET /insights — representative fleet fixture; tests override via server.use().
  http.get('*/wp-json/defyn/v1/insights', () => {
    return HttpResponse.json({
      performance: {
        summary: { total_sites: 3, measured: 2, avg_mobile: 61, avg_desktop: 88, slow_sites: 1 },
        sites: [
          { site_id: 2, label: 'Bravo', url: 'https://b.example', mobile_score: 42, desktop_score: 71, mobile_lcp_ms: 4600, fetched_at: '2026-06-01 00:00:00' },
          { site_id: 1, label: 'Alpha', url: 'https://a.example', mobile_score: 80, desktop_score: 95, mobile_lcp_ms: 2100, fetched_at: '2026-06-01 00:00:00' },
          { site_id: 3, label: 'Charlie', url: 'https://c.example', mobile_score: null, desktop_score: null, mobile_lcp_ms: null, fetched_at: null },
        ],
      },
      analytics: {
        summary: { total_sites: 3, connected: 1, total_sessions: 1240, total_users: 910 },
        sites: [
          { site_id: 1, label: 'Alpha', url: 'https://a.example', ga4_property_id: '111', sessions: 1240, total_users: 910, screen_page_views: 3410, avg_session_duration: 72, period_start: '2026-05-01', period_end: '2026-05-31', fetched_at: '2026-06-01 00:00:00' },
          { site_id: 2, label: 'Bravo', url: 'https://b.example', ga4_property_id: '222', sessions: null, total_users: null, screen_page_views: null, avg_session_duration: null, period_start: null, period_end: null, fetched_at: null },
          { site_id: 3, label: 'Charlie', url: 'https://c.example', ga4_property_id: null, sessions: null, total_users: null, screen_page_views: null, avg_session_duration: null, period_start: null, period_end: null, fetched_at: null },
        ],
      },
      generated_at: '2026-06-18 00:00:00',
    });
  }),
```

> Match the existing handler style in the file (it already imports `http` + `HttpResponse` from `msw`). Insert the handler inside the same array the other `http.get(...)` handlers live in.

- [ ] **Step 4: Run to verify they pass**

Run: `pnpm test -- --run src/lib/__tests__/psiBand.test.ts src/types/__tests__/insightsSchema.test.ts`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/lib/psiBand.ts apps/web/src/types/api.ts apps/web/src/test/handlers.ts apps/web/src/lib/__tests__/psiBand.test.ts apps/web/src/types/__tests__/insightsSchema.test.ts
git commit -m "feat(p6-3): SPA insightsSchema + psiBand helper + MSW handler"
```

---

### Task 7: SPA — `useInsights` query hook

**Files:**
- Create: `apps/web/src/lib/queries/useInsights.ts`
- Test: `apps/web/src/lib/queries/__tests__/useInsights.test.tsx` (match where `useSecurity.test.tsx` lives — see Step 1)

- [ ] **Step 1: Write the failing test**

Clone `apps/web/src/lib/queries/__tests__/useSecurity.test.tsx` (or wherever `useSecurity.test.tsx` lives) into `useInsights.test.tsx`: render the hook inside a `QueryClientProvider`, wait for success, and assert it parses the MSW fixture (e.g. `result.current.data?.performance.summary.total_sites === 3` and `data.analytics.summary.connected === 1`).

- [ ] **Step 2: Run to verify it fails**

Run: `pnpm test -- --run src/lib/queries/__tests__/useInsights.test.tsx`
Expected: FAIL — module missing.

- [ ] **Step 3: Implement**

Create `apps/web/src/lib/queries/useInsights.ts`:

```typescript
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { insightsSchema } from '@/types/api';

export function useInsights() {
  return useQuery({
    queryKey: ['insights'],
    queryFn: async () => {
      const data = await apiClient.get<unknown>('/insights');
      return insightsSchema.parse(data);
    },
    staleTime: 30_000,
  });
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `pnpm test -- --run src/lib/queries/__tests__/useInsights.test.tsx`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/lib/queries/useInsights.ts apps/web/src/lib/queries/__tests__/useInsights.test.tsx
git commit -m "feat(p6-3): useInsights query hook"
```

---

### Task 8: SPA — components, page, nav, routing

**Files:**
- Create: `apps/web/src/components/insights/InsightsPerformanceStrip.tsx`
- Create: `apps/web/src/components/insights/InsightsPerformanceTable.tsx`
- Create: `apps/web/src/components/insights/InsightsAnalyticsStrip.tsx`
- Create: `apps/web/src/components/insights/InsightsAnalyticsTable.tsx`
- Create: `apps/web/src/routes/Insights.tsx`
- Create: `apps/web/src/components/nav/InsightsNavLink.tsx`
- Modify: `apps/web/src/App.tsx` (import + route)
- Modify: `apps/web/src/routes/Overview.tsx` (import + nav link)
- Test: `apps/web/src/routes/__tests__/Insights.test.tsx` (match the project's page-test location/convention)

- [ ] **Step 1: Write the failing page test**

Render `<Insights />` inside a `MemoryRouter` + `QueryClientProvider` (clone the provider/wrapper boilerplate from an existing page/hook test). Assert against the MSW fixture from Task 6:

```typescript
import { describe, it, expect } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Insights } from '@/routes/Insights';

function renderInsights() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter><Insights /></MemoryRouter>
    </QueryClientProvider>
  );
}

describe('Insights page', () => {
  it('renders both sections worst-first with empty-state rows', async () => {
    renderInsights();

    // Summary tiles render once data loads.
    await waitFor(() => expect(screen.getByTestId('kpi-slow-sites')).toHaveTextContent('1'));
    expect(screen.getByTestId('kpi-connected')).toHaveTextContent('1/3');

    // Performance worst-first: Bravo (42) appears before Alpha (80) in DOM order.
    const perfText = document.body.textContent ?? '';
    expect(perfText.indexOf('Bravo')).toBeLessThan(perfText.indexOf('Alpha'));

    // Empty states.
    expect(screen.getByText('Not yet measured')).toBeInTheDocument();
    expect(screen.getByText('Not connected — add a GA4 Property ID')).toBeInTheDocument();
    expect(screen.getByText('Connected — no data yet')).toBeInTheDocument();

    // Row links to site detail.
    const alphaLink = screen.getAllByRole('link', { name: 'Alpha' })[0];
    expect(alphaLink).toHaveAttribute('href', '/sites/1');
  });
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `pnpm test -- --run src/routes/__tests__/Insights.test.tsx`
Expected: FAIL — `Insights` route / components missing.

- [ ] **Step 3: Implement the components**

Create `apps/web/src/components/insights/InsightsPerformanceStrip.tsx`:

```tsx
import type { Insights } from '@/types/api';

interface Props {
  summary: Insights['performance']['summary'];
}

export function InsightsPerformanceStrip({ summary }: Props) {
  const tiles = [
    { label: 'Avg mobile', value: summary.avg_mobile === null ? '—' : String(summary.avg_mobile), tone: 'text-zinc-900', testid: 'kpi-avg-mobile' },
    { label: 'Avg desktop', value: summary.avg_desktop === null ? '—' : String(summary.avg_desktop), tone: 'text-zinc-900', testid: 'kpi-avg-desktop' },
    { label: 'Slow sites', value: String(summary.slow_sites), tone: summary.slow_sites > 0 ? 'text-red-600' : 'text-zinc-900', testid: 'kpi-slow-sites' },
    { label: 'Measured', value: `${summary.measured}/${summary.total_sites}`, tone: 'text-zinc-900', testid: 'kpi-measured' },
  ];
  return (
    <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
      {tiles.map((t) => (
        <div key={t.label} className="rounded-lg border border-zinc-200 p-4">
          <div data-testid={t.testid} className={`text-2xl font-semibold ${t.tone}`}>{t.value}</div>
          <div className="mt-1 text-xs uppercase tracking-wide text-zinc-500">{t.label}</div>
        </div>
      ))}
    </div>
  );
}
```

Create `apps/web/src/components/insights/InsightsAnalyticsStrip.tsx`:

```tsx
import type { Insights } from '@/types/api';

interface Props {
  summary: Insights['analytics']['summary'];
}

export function InsightsAnalyticsStrip({ summary }: Props) {
  const tiles = [
    { label: 'Total sessions', value: summary.total_sessions.toLocaleString(), testid: 'kpi-total-sessions' },
    { label: 'Total users', value: summary.total_users.toLocaleString(), testid: 'kpi-total-users' },
    { label: 'Connected', value: `${summary.connected}/${summary.total_sites}`, testid: 'kpi-connected' },
  ];
  return (
    <div className="grid grid-cols-2 gap-3 md:grid-cols-3">
      {tiles.map((t) => (
        <div key={t.label} className="rounded-lg border border-zinc-200 p-4">
          <div data-testid={t.testid} className="text-2xl font-semibold text-zinc-900">{t.value}</div>
          <div className="mt-1 text-xs uppercase tracking-wide text-zinc-500">{t.label}</div>
        </div>
      ))}
    </div>
  );
}
```

Create `apps/web/src/components/insights/InsightsPerformanceTable.tsx`:

```tsx
import { Link } from 'react-router-dom';
import type { PerformanceFleetRow } from '@/types/api';
import { psiBand, type PsiBand } from '@/lib/psiBand';
import { rateCwv, type CwvRating } from '@/lib/coreWebVitals';

const BAND_CLASS: Record<PsiBand | CwvRating, string> = {
  good: 'bg-green-50 text-green-700',
  'needs-improvement': 'bg-amber-50 text-amber-700',
  poor: 'bg-red-50 text-red-700',
  unknown: 'bg-zinc-100 text-zinc-500',
};

function ScoreChip({ score }: { score: number | null }) {
  if (score === null) return <span className="text-zinc-400">—</span>;
  return (
    <span className={`inline-flex items-center rounded px-1.5 py-0.5 text-xs font-semibold ${BAND_CLASS[psiBand(score)]}`}>
      {score}
    </span>
  );
}

function LcpCell({ ms }: { ms: number | null }) {
  if (ms === null) return <span className="text-zinc-400">—</span>;
  const rating = rateCwv('lcp', ms);
  const tone = rating === 'good' ? 'text-green-700' : rating === 'needs-improvement' ? 'text-amber-700' : 'text-red-700';
  return <span className={tone}>{(ms / 1000).toFixed(1)}s</span>;
}

function Row({ site }: { site: PerformanceFleetRow }) {
  const measured = site.fetched_at !== null;
  return (
    <tr className="border-b border-zinc-100">
      <td className="py-2 pr-3">
        <Link to={`/sites/${site.site_id}`} className="font-medium text-zinc-900 hover:underline">{site.label}</Link>
        <div className="text-xs text-zinc-400">{site.url}</div>
      </td>
      {measured ? (
        <>
          <td className="py-2 pr-3"><ScoreChip score={site.mobile_score} /></td>
          <td className="py-2 pr-3"><ScoreChip score={site.desktop_score} /></td>
          <td className="py-2 pr-3 tabular-nums"><LcpCell ms={site.mobile_lcp_ms} /></td>
          <td className="py-2 pr-1 text-sm text-zinc-500">{site.fetched_at?.slice(0, 10) ?? '—'}</td>
        </>
      ) : (
        <>
          <td className="py-2 pr-3 text-sm italic text-zinc-400" colSpan={3}>Not yet measured</td>
          <td className="py-2 pr-1 text-zinc-400">—</td>
        </>
      )}
    </tr>
  );
}

export function InsightsPerformanceTable({ sites }: { sites: PerformanceFleetRow[] }) {
  return (
    <table className="w-full text-sm">
      <thead>
        <tr className="border-b border-zinc-200 text-left text-xs uppercase tracking-wide text-zinc-500">
          <th className="py-2 pl-1 pr-3 font-medium">Site</th>
          <th className="py-2 pr-3 font-medium">Mobile</th>
          <th className="py-2 pr-3 font-medium">Desktop</th>
          <th className="py-2 pr-3 font-medium">LCP (m)</th>
          <th className="py-2 pr-1 font-medium">Measured</th>
        </tr>
      </thead>
      <tbody>
        {sites.map((s) => <Row key={s.site_id} site={s} />)}
      </tbody>
    </table>
  );
}
```

Create `apps/web/src/components/insights/InsightsAnalyticsTable.tsx`:

```tsx
import { Link } from 'react-router-dom';
import type { AnalyticsFleetRow } from '@/types/api';
import { formatEngagement } from '@/lib/engagement';

function num(n: number | null): string {
  return n === null ? '—' : n.toLocaleString();
}

function Row({ site }: { site: AnalyticsFleetRow }) {
  const connected = site.ga4_property_id !== null && site.ga4_property_id !== '';
  const hasData = site.fetched_at !== null;
  return (
    <tr className="border-b border-zinc-100">
      <td className="py-2 pr-3">
        <Link to={`/sites/${site.site_id}`} className="font-medium text-zinc-900 hover:underline">{site.label}</Link>
        <div className="text-xs text-zinc-400">{site.url}</div>
      </td>
      {connected && hasData ? (
        <>
          <td className="py-2 pr-3 tabular-nums">{num(site.sessions)}</td>
          <td className="py-2 pr-3 tabular-nums">{num(site.total_users)}</td>
          <td className="py-2 pr-3 tabular-nums">{num(site.screen_page_views)}</td>
          <td className="py-2 pr-3 tabular-nums">{site.avg_session_duration === null ? '—' : formatEngagement(site.avg_session_duration)}</td>
          <td className="py-2 pr-1 text-sm text-zinc-500">{site.period_start?.slice(0, 7) ?? '—'}</td>
        </>
      ) : (
        <>
          <td className="py-2 pr-3 text-sm italic text-zinc-400" colSpan={4}>
            {connected ? 'Connected — no data yet' : 'Not connected — add a GA4 Property ID'}
          </td>
          <td className="py-2 pr-1 text-zinc-400">—</td>
        </>
      )}
    </tr>
  );
}

export function InsightsAnalyticsTable({ sites }: { sites: AnalyticsFleetRow[] }) {
  return (
    <table className="w-full text-sm">
      <thead>
        <tr className="border-b border-zinc-200 text-left text-xs uppercase tracking-wide text-zinc-500">
          <th className="py-2 pl-1 pr-3 font-medium">Site</th>
          <th className="py-2 pr-3 font-medium">Sessions</th>
          <th className="py-2 pr-3 font-medium">Users</th>
          <th className="py-2 pr-3 font-medium">Views</th>
          <th className="py-2 pr-3 font-medium">Avg engmt</th>
          <th className="py-2 pr-1 font-medium">Period</th>
        </tr>
      </thead>
      <tbody>
        {sites.map((s) => <Row key={s.site_id} site={s} />)}
      </tbody>
    </table>
  );
}
```

Create `apps/web/src/components/nav/InsightsNavLink.tsx`:

```tsx
import { Link } from 'react-router-dom';

/**
 * P6.3 — nav link to /insights (combined fleet Performance + Analytics).
 * Rendered in the Overview header beside <SecurityNavLink />.
 */
export function InsightsNavLink() {
  return (
    <Link
      to="/insights"
      className="inline-flex items-center gap-1.5 text-sm text-zinc-600 underline-offset-4 hover:underline"
    >
      Insights
    </Link>
  );
}
```

Create `apps/web/src/routes/Insights.tsx`:

```tsx
import { Link } from 'react-router-dom';
import { useInsights } from '@/lib/queries/useInsights';
import { InsightsPerformanceStrip } from '@/components/insights/InsightsPerformanceStrip';
import { InsightsPerformanceTable } from '@/components/insights/InsightsPerformanceTable';
import { InsightsAnalyticsStrip } from '@/components/insights/InsightsAnalyticsStrip';
import { InsightsAnalyticsTable } from '@/components/insights/InsightsAnalyticsTable';

export function Insights() {
  const { data, isLoading, isError } = useInsights();

  return (
    <div className="mx-auto max-w-5xl px-4 py-6">
      <div className="mb-5 flex items-baseline gap-3">
        <h1 className="text-xl font-semibold">Insights</h1>
        <Link to="/overview" className="text-sm text-zinc-600 underline-offset-4 hover:underline">← Overview</Link>
      </div>

      {isLoading && <p className="text-sm text-zinc-500">Loading…</p>}
      {isError && <p className="text-sm text-red-600">Couldn't load insights.</p>}

      {data && (
        data.performance.summary.total_sites === 0 ? (
          <p className="text-sm text-zinc-500">No sites yet</p>
        ) : (
          <div className="space-y-8">
            <section className="space-y-4">
              <h2 className="text-sm font-semibold uppercase tracking-wide text-zinc-500">Performance</h2>
              <InsightsPerformanceStrip summary={data.performance.summary} />
              <div className="rounded-lg border border-zinc-200 p-2">
                <InsightsPerformanceTable sites={data.performance.sites} />
              </div>
            </section>
            <section className="space-y-4">
              <h2 className="text-sm font-semibold uppercase tracking-wide text-zinc-500">Analytics</h2>
              <InsightsAnalyticsStrip summary={data.analytics.summary} />
              <div className="rounded-lg border border-zinc-200 p-2">
                <InsightsAnalyticsTable sites={data.analytics.sites} />
              </div>
            </section>
          </div>
        )
      )}
    </div>
  );
}

export default Insights;
```

- [ ] **Step 4: Wire the route + nav**

In `apps/web/src/App.tsx`: add the import beside the other route imports (near line 16):

```tsx
import { Insights } from './routes/Insights';
```

and add the route inside the `<RequireAuth>` outlet, right after the `/security` line:

```tsx
        <Route path="/insights" element={<Insights />} />
```

In `apps/web/src/routes/Overview.tsx`: add the import beside the other nav imports (near line 8):

```tsx
import { InsightsNavLink } from '@/components/nav/InsightsNavLink'
```

and render it right after `<SecurityNavLink />` (near line 57):

```tsx
          <InsightsNavLink />
```

- [ ] **Step 5: Run to verify it passes**

Run: `pnpm test -- --run src/routes/__tests__/Insights.test.tsx`
Expected: PASS.

- [ ] **Step 6: Typecheck**

Run: `pnpm build`
Expected: tsc clean, build succeeds. (Fix any type error before committing — a vitest-green test can still fail the build.)

- [ ] **Step 7: Commit**

```bash
git add apps/web/src/components/insights apps/web/src/components/nav/InsightsNavLink.tsx apps/web/src/routes/Insights.tsx apps/web/src/App.tsx apps/web/src/routes/Overview.tsx apps/web/src/routes/__tests__/Insights.test.tsx
git commit -m "feat(p6-3): /insights page, fleet tables, strips, nav link + route"
```

---

### Task 9: Release v0.23.0

**Files:**
- Create: `dist/defyn-dashboard-0.23.0.zip`
- Modify: memory files (see Step 8)

- [ ] **Step 1: Full PHP suite**

Run from `packages/dashboard-plugin`: `COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit`
Expected: green except the known `UninstallTest` carry-forward. (If the DB is offline, use the standalone-mysqld fallback from the Conventions section.)

- [ ] **Step 2: Full SPA suite**

Run from `apps/web` (Node 22): `pnpm test -- --run`
Expected: green except the 4 carry-forward failures (`SiteDetail` ×2 + `SiteCoreCard` ×2). Then `pnpm build` — tsc clean.

- [ ] **Step 3: Build the dompdf-preserving dashboard zip**

From `packages/dashboard-plugin`:
```bash
composer install --no-dev --classmap-authoritative
```
Then build `dist/defyn-dashboard-0.23.0.zip` from `packages/` with the top-level folder `dashboard-plugin/`, **excluding ONLY** tests + dev tooling (`tests/*`, `*wp-tests-config.php`, `.phpunit.result.cache`, `test-output.log`, `phpunit.xml`, `composer.lock`, `.github/*`, `.gitignore`) — **never** any `vendor/*` subdir.

- [ ] **Step 4: Verify the zip retains required vendor files**

```bash
unzip -l dist/defyn-dashboard-0.23.0.zip | grep -E "symfony/deprecation-contracts/function\.php|polyfill-php83/bootstrap\.php|json-machine/src/Items\.php|dompdf/src/Dompdf\.php|firebase/php-jwt/src/JWT\.php|Schema/SitePerformanceTable\.php|Schema/SiteAnalyticsTable\.php"
```
Expected: 7 lines (all present). Then restore dev autoload for local work:
```bash
composer install
```

- [ ] **Step 5: Merge to main**

```bash
git checkout main
git merge --no-ff p6-3-fleet-insights -m "merge: P6.3 fleet insights /insights page"
git push origin main
```
(SPA auto-deploys to Cloudflare Pages from `main`.)

- [ ] **Step 6: Manual Kinsta install — PAUSE**

The dashboard plugin install on Kinsta is manual. Tell the operator the zip is at `dist/defyn-dashboard-0.23.0.zip`, ask them to upload it via WP Admin (Replace current), then **wait for "installed"** before smoking. After install, clear cache via MyKinsta → Tools → Clear cache.

- [ ] **Step 7: Indirect curl smoke (after "installed")**

Backend `defynwp.defyn.agency`; login field is `access_token`; creds (curl only) `pradeep@defyn.com.au` / `DefynWP-ifirCh5pXm5bTOj0`.
1. `GET /defyn/v1/insights` with no auth → **401** (`auth.missing_token`).
2. Login → `GET /defyn/v1/insights` with the bearer token → **200** with top-level `performance`, `analytics`, `generated_at` keys (zero-sites prod ⇒ empty `sites` arrays + zeroed summaries — that's the expected happy shape; populated rows are foreclosed by zero-sites prod).
3. Bogus route `GET /defyn/v1/insightz` authed → `rest_no_route` / `rest.route_not_found` contrast (proves the 200 above is the real registered route).

Cloudflare deploy verify: fetch the deployed SPA bundle and confirm it contains the strings `Insights`, `Not yet measured`, and `Not connected`.

- [ ] **Step 8: Tag + MEMORY**

```bash
git tag p6-3-fleet-insights-complete
git push origin p6-3-fleet-insights-complete
```
Then append a P6.3-complete entry to `~/.claude/projects/-Users-pradeep-Local-Sites-defynWP/memory/project_defyn_roadmap.md` and refresh the roadmap pointer line in `MEMORY.md` (dashboard v0.23.0, schema still v16, connector still v0.1.7, new `/insights` read-only fleet page, tag `p6-3-fleet-insights-complete`, NEXT = operator's choice).

---

## Self-Review (completed by plan author)

**1. Spec coverage:** Every spec section maps to a task — §3.2 → Task 1, §3.3 → Task 2, §3.1 → Task 3, §3.4 (controller + RateLimit + route) → Task 4 + Task 5 (version), §4 (SPA schema/helper/hook/components/page/nav) → Tasks 6–8, §5 testing distributed across tasks, §7 build order → Tasks 1–9. The spec's separate "RateLimit bucket" build-order item is folded into Task 4 (controller+ratelimit+route+CORS) since they share one cohesive REST test file.

**2. Placeholder scan:** No TBD/TODO. Every code step contains complete code. The two PHP REST tests (Task 4) and the SPA hook/page test wrappers say "clone the named existing file" rather than reproducing boilerplate verbatim — this is deliberate (the auth-request helper + provider wrapper are project-specific and must be copied exactly from the named sibling test, not reinvented).

**3. Type consistency:** Repository row shapes (Task 1/2) exactly match the Zod schemas (Task 6) and the `InsightsService` pass-through (Task 3): `performance.sites[]` = `{site_id,label,url,mobile_score,desktop_score,mobile_lcp_ms,fetched_at}`; `analytics.sites[]` = `{site_id,label,url,ga4_property_id,sessions,total_users,screen_page_views,avg_session_duration,period_start,period_end,fetched_at}`. Summary keys match between `InsightsService` and `insightsSchema` (`total_sites,measured,avg_mobile,avg_desktop,slow_sites` / `total_sites,connected,total_sessions,total_users`). `psiBand` + `rateCwv` share the `'good'|'needs-improvement'|'poor'|'unknown'` union consumed by `BAND_CLASS`.
