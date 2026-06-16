# P6.1 — Performance (PageSpeed) Reporting Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a Performance section to the maintenance report, backed by a weekly Google PageSpeed Insights snapshot per site (mobile + desktop score + Core Web Vitals), flowing through all three report surfaces (on-screen, PDF, stored queue).

**Architecture:** A new `SitePerformance` snapshot (table `wp_defyn_site_performance`, schema v14) is fetched **weekly + on-demand** by a recurring Action Scheduler fan-out (mirrors the daily security scan, `WEEK_IN_SECONDS`) + a best-effort `PageSpeedClient` (never blocks a web request). `ReportService::compose` gains a `performance` key (latest snapshot + weekly trend over range) that the PDF + on-screen report render; the P5.3 stored-report queue inherits it for free.

**Tech Stack:** PHP 8.1 (WP plugin, PHPUnit/wp-phpunit), Action Scheduler, `wp_remote_get` (no new composer dep), React 18 + TS + TanStack Query v5 + Zod + Vitest/MSW (pnpm, Node 22).

**Branch:** `p6-1-performance` (off main @ `52c9df0`). Connector UNCHANGED (v0.1.7). Dashboard v0.19.0 → **v0.20.0**.

**Carry-forward tolerances:** PHP 1 (`UninstallTest::testUninstallDropsAllTables`); SPA 4 (`SiteDetail` ×2 + `SiteCoreCard` ×2). Baseline after P5.3: PHP 831/1, SPA 427/4.

**Test-isolation (guardrail #15):** `wp_defyn_site_performance` uses plain dbDelta + `$wpdb->insert` (rolls back under `WP_UnitTestCase`), BUT tests seeding `defyn_sites` still purge it in `setUp`. Copy the `setUp` (`SET autocommit=1` + `DELETE FROM defyn_site_performance` + `defyn_sites`, + `Activation::ensureSchema()`) and `seedSite` (real cols `id,user_id,url,label,status,created_at,updated_at,wp_version` — NOT `owner_user_id`/`public_key`) from `tests/Integration/Services/ReportsRepositoryTest.php`. `ActivityLogger` lives in `Defyn\Dashboard\Services\` (`log(?int $userId, ?int $siteId, string $event, ?array $details, ?string $ip = null)`).

**DB-OFFLINE NOTE (every PHP task):** the Local "defynWP" site DB is stopped. If `composer test:integration` errors on a DB connection, start a standalone `mysqld` against the existing `defyn_test` datadir (`~/Library/Application Support/Local/run/50bJKdbjK/mysql/data`, port 10166, its own socket), run the tests, shut it down cleanly afterward. Never modify the gitignored `wp-tests-config.php`.

Full PHP suite: `COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit` (tolerate only `UninstallTest`). Filtered: `composer test:integration -- --filter <Name>` / `composer test:unit -- --filter <Name>`. SPA (Node 22): `export FNM_DIR="$HOME/.fnm"; eval "$(fnm env --shell bash)"; fnm use 22` then `pnpm test -- --run <pat>`; `pnpm build` typechecks.

---

## Task 1: Schema v14 — `SitePerformanceTable` + version-pin ripple

**Files:**
- Create: `packages/dashboard-plugin/src/Schema/SitePerformanceTable.php`
- Modify: `packages/dashboard-plugin/src/Activation.php` (SCHEMA_VERSION, TABLES)
- Test: `packages/dashboard-plugin/tests/Integration/Schema/PerformanceSchemaTest.php`; bump all `assertSame(13, …)` schema pins.

- [ ] **Step 1: Write the failing test** `tests/Integration/Schema/PerformanceSchemaTest.php` (mirror `tests/Integration/Schema/ReportsSchemaTest.php`):
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Schema;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Schema\SitePerformanceTable;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class PerformanceSchemaTest extends AbstractSchemaTestCase
{
    public function testSchemaVersionIs14(): void
    {
        self::assertSame(14, Activation::SCHEMA_VERSION);
    }

    public function testPerformanceTableExistsWithColumns(): void
    {
        global $wpdb;
        $t = SitePerformanceTable::tableName();
        $cols = $wpdb->get_col("SHOW COLUMNS FROM `{$t}`"); // phpcs:ignore
        foreach (['id','site_id','mobile_score','mobile_lcp_ms','mobile_cls','mobile_inp_ms','desktop_score','desktop_lcp_ms','desktop_cls','desktop_inp_ms','fetched_at','created_at'] as $c) {
            self::assertContains($c, $cols, "missing column {$c}");
        }
    }
}
```
Run red: `composer test:integration -- --filter PerformanceSchemaTest` → FAIL.

- [ ] **Step 2: Create `src/Schema/SitePerformanceTable.php`** (mirror `src/Schema/ReportsTable.php` — `implements SchemaTable`, uppercase types, two-space `PRIMARY KEY`, `KEY` not `INDEX`):
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Schema;

final class SitePerformanceTable implements SchemaTable
{
    public static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'defyn_site_performance';
    }

    public static function createSql(): string
    {
        global $wpdb;
        $table   = self::tableName();
        $charset = $wpdb->get_charset_collate();
        return "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            site_id BIGINT UNSIGNED NOT NULL,
            mobile_score TINYINT UNSIGNED NULL,
            mobile_lcp_ms INT UNSIGNED NULL,
            mobile_cls DECIMAL(6,3) NULL,
            mobile_inp_ms INT UNSIGNED NULL,
            desktop_score TINYINT UNSIGNED NULL,
            desktop_lcp_ms INT UNSIGNED NULL,
            desktop_cls DECIMAL(6,3) NULL,
            desktop_inp_ms INT UNSIGNED NULL,
            fetched_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_perf_site_fetched (site_id, fetched_at)
        ) {$charset};";
    }
}
```

- [ ] **Step 3: Edit `src/Activation.php`** — `SCHEMA_VERSION = 13` → `14`; add `use Defyn\Dashboard\Schema\SitePerformanceTable;`; add `SitePerformanceTable::class,` to `TABLES` (after `ReportsTable::class,`). (No guarded ALTER — this is a pure new table.)

- [ ] **Step 4: Bump every schema-version pin.** `grep -rln "assertSame(13" tests/` → update each `assertSame(13, Activation::SCHEMA_VERSION)` (the SchemaVersionMigration + per-feature schema tests, incl. any in the parent `tests/Integration/` dir) to `14`. After: `grep -rn "assertSame(13, Activation::SCHEMA_VERSION" tests/` → no matches. **Stage the parent `tests/Integration/` dir too, not only `tests/Integration/Schema/`** (the P5.3 add-path miss left `SecurityScanningSchemaTest.php` orphaned).

- [ ] **Step 5: Run green** → `composer test:integration -- --filter "PerformanceSchemaTest|SchemaVersion"` PASS; full suite → only `UninstallTest`.

- [ ] **Step 6: Commit**
```bash
git add packages/dashboard-plugin/src/Schema/SitePerformanceTable.php packages/dashboard-plugin/src/Activation.php packages/dashboard-plugin/tests/Integration/
git commit -m "feat(p6-1): schema v14 — wp_defyn_site_performance table"
```

---

## Task 2: `Models\SitePerformance` DTO

**Files:**
- Create: `packages/dashboard-plugin/src/Models/SitePerformance.php`
- Test: `packages/dashboard-plugin/tests/Unit/Models/SitePerformanceTest.php`

- [ ] **Step 1: Write the failing test** `tests/Unit/Models/SitePerformanceTest.php`:
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Unit\Models;

use Defyn\Dashboard\Models\SitePerformance;
use PHPUnit\Framework\TestCase;

final class SitePerformanceTest extends TestCase
{
    public function testFromRowAndToJson(): void
    {
        $p = SitePerformance::fromRow([
            'id' => '5', 'site_id' => '2',
            'mobile_score' => '82', 'mobile_lcp_ms' => '2100', 'mobile_cls' => '0.140', 'mobile_inp_ms' => '180',
            'desktop_score' => '96', 'desktop_lcp_ms' => '900', 'desktop_cls' => '0.010', 'desktop_inp_ms' => '60',
            'fetched_at' => '2026-06-14 03:00:00', 'created_at' => '2026-06-14 03:00:05',
        ]);
        self::assertSame(5, $p->id);
        self::assertSame(82, $p->mobileScore);
        self::assertSame(0.14, $p->mobileCls);
        $json = $p->toJson();
        self::assertSame(82, $json['mobile_score']);
        self::assertSame(96, $json['desktop_score']);
        self::assertSame('2026-06-14 03:00:00', $json['fetched_at']);
    }

    public function testNullMetricsSurviveRoundTrip(): void
    {
        $p = SitePerformance::fromRow([
            'id' => '1', 'site_id' => '2',
            'mobile_score' => '70', 'mobile_lcp_ms' => '3000', 'mobile_cls' => '0.050', 'mobile_inp_ms' => '210',
            'desktop_score' => null, 'desktop_lcp_ms' => null, 'desktop_cls' => null, 'desktop_inp_ms' => null,
            'fetched_at' => '2026-06-14 03:00:00', 'created_at' => '2026-06-14 03:00:05',
        ]);
        self::assertNull($p->desktopScore);
        self::assertNull($p->toJson()['desktop_score']);
    }
}
```
Run red: `composer test:unit -- --filter SitePerformanceTest` → FAIL.

- [ ] **Step 2: Create `src/Models/SitePerformance.php`** (mirror `src/Models/Report.php` readonly-DTO style):
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Models;

final class SitePerformance
{
    public function __construct(
        public readonly int $id,
        public readonly int $siteId,
        public readonly ?int $mobileScore,
        public readonly ?int $mobileLcpMs,
        public readonly ?float $mobileCls,
        public readonly ?int $mobileInpMs,
        public readonly ?int $desktopScore,
        public readonly ?int $desktopLcpMs,
        public readonly ?float $desktopCls,
        public readonly ?int $desktopInpMs,
        public readonly string $fetchedAt,
        public readonly string $createdAt,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        $int = static fn ($v): ?int => $v === null ? null : (int) $v;
        $flt = static fn ($v): ?float => $v === null ? null : (float) $v;
        return new self(
            (int) $row['id'],
            (int) $row['site_id'],
            $int($row['mobile_score'] ?? null),
            $int($row['mobile_lcp_ms'] ?? null),
            $flt($row['mobile_cls'] ?? null),
            $int($row['mobile_inp_ms'] ?? null),
            $int($row['desktop_score'] ?? null),
            $int($row['desktop_lcp_ms'] ?? null),
            $flt($row['desktop_cls'] ?? null),
            $int($row['desktop_inp_ms'] ?? null),
            (string) $row['fetched_at'],
            (string) $row['created_at'],
        );
    }

    /** @return array<string,mixed> */
    public function toJson(): array
    {
        return [
            'id' => $this->id,
            'site_id' => $this->siteId,
            'mobile_score' => $this->mobileScore,
            'mobile_lcp_ms' => $this->mobileLcpMs,
            'mobile_cls' => $this->mobileCls,
            'mobile_inp_ms' => $this->mobileInpMs,
            'desktop_score' => $this->desktopScore,
            'desktop_lcp_ms' => $this->desktopLcpMs,
            'desktop_cls' => $this->desktopCls,
            'desktop_inp_ms' => $this->desktopInpMs,
            'fetched_at' => $this->fetchedAt,
            'created_at' => $this->createdAt,
        ];
    }
}
```

- [ ] **Step 3: Run green** → PASS. **Step 4: Commit** `feat(p6-1): SitePerformance DTO`.

---

## Task 3: `Services\SitePerformanceRepository`

**Files:**
- Create: `packages/dashboard-plugin/src/Services/SitePerformanceRepository.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/SitePerformanceRepositoryTest.php`

- [ ] **Step 1: Write the failing test** (copy `setUp` purge + `seedSite` from `tests/Integration/Services/ReportsRepositoryTest.php`; purge `defyn_site_performance` + `defyn_sites`):
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\SitePerformanceRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SitePerformanceRepositoryTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Defyn\Dashboard\Activation::ensureSchema();
        global $wpdb;
        $wpdb->query('SET autocommit = 1');
        foreach (['defyn_site_performance','defyn_sites'] as $t) {
            $wpdb->query('DELETE FROM ' . $wpdb->prefix . $t);
        }
    }

    private function seedSite(int $id = 1): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'id' => $id, 'user_id' => 1, 'url' => 'https://acme.test', 'label' => 'Acme',
            'status' => 'active', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00', 'wp_version' => '6.9.4',
        ]);
        return $id;
    }

    private function m(int $score): array { return ['score' => $score, 'lcp_ms' => 2100, 'cls' => 0.14, 'inp_ms' => 180]; }
    private function d(int $score): array { return ['score' => $score, 'lcp_ms' => 900, 'cls' => 0.01, 'inp_ms' => 60]; }

    public function testStoreAndLatest(): void
    {
        $siteId = $this->seedSite();
        $repo = new SitePerformanceRepository();
        $repo->store($siteId, $this->m(70), $this->d(90), '2026-06-07 03:00:00', '2026-06-07 03:00:05');
        $repo->store($siteId, $this->m(82), $this->d(96), '2026-06-14 03:00:00', '2026-06-14 03:00:05');
        $latest = $repo->latestForSite($siteId);
        self::assertNotNull($latest);
        self::assertSame(82, $latest->mobileScore);
        self::assertNull($repo->latestForSite(999));
    }

    public function testStoreNullStrategy(): void
    {
        $siteId = $this->seedSite();
        $repo = new SitePerformanceRepository();
        $repo->store($siteId, $this->m(70), null, '2026-06-14 03:00:00', '2026-06-14 03:00:05');
        $latest = $repo->latestForSite($siteId);
        self::assertSame(70, $latest->mobileScore);
        self::assertNull($latest->desktopScore);
    }

    public function testFindForSiteInRangeOldestFirst(): void
    {
        $siteId = $this->seedSite();
        $repo = new SitePerformanceRepository();
        $repo->store($siteId, $this->m(70), $this->d(90), '2026-05-31 03:00:00', '2026-05-31 03:00:05');
        $repo->store($siteId, $this->m(82), $this->d(96), '2026-06-14 03:00:00', '2026-06-14 03:00:05');
        $repo->store($siteId, $this->m(60), $this->d(80), '2026-04-01 03:00:00', '2026-04-01 03:00:05'); // out of range
        $rows = $repo->findForSiteInRange($siteId, '2026-05-01 00:00:00', '2026-06-30 23:59:59');
        self::assertCount(2, $rows);
        self::assertSame(70, $rows[0]->mobileScore); // oldest first
        self::assertSame(82, $rows[1]->mobileScore);
    }
}
```
Run red → FAIL.

- [ ] **Step 2: Create `src/Services/SitePerformanceRepository.php`:**
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Models\SitePerformance;
use Defyn\Dashboard\Schema\SitePerformanceTable;

final class SitePerformanceRepository
{
    /**
     * @param array{score:int,lcp_ms:?int,cls:?float,inp_ms:?int}|null $mobile
     * @param array{score:int,lcp_ms:?int,cls:?float,inp_ms:?int}|null $desktop
     */
    public function store(int $siteId, ?array $mobile, ?array $desktop, string $fetchedAt, string $now): int
    {
        global $wpdb;
        $wpdb->insert(SitePerformanceTable::tableName(), [
            'site_id'        => $siteId,
            'mobile_score'   => $mobile['score']  ?? null,
            'mobile_lcp_ms'  => $mobile['lcp_ms'] ?? null,
            'mobile_cls'     => $mobile['cls']    ?? null,
            'mobile_inp_ms'  => $mobile['inp_ms'] ?? null,
            'desktop_score'  => $desktop['score']  ?? null,
            'desktop_lcp_ms' => $desktop['lcp_ms'] ?? null,
            'desktop_cls'    => $desktop['cls']    ?? null,
            'desktop_inp_ms' => $desktop['inp_ms'] ?? null,
            'fetched_at'     => $fetchedAt,
            'created_at'     => $now,
        ]);
        return (int) $wpdb->insert_id;
    }

    public function latestForSite(int $siteId): ?SitePerformance
    {
        global $wpdb;
        $t = SitePerformanceTable::tableName();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$t} WHERE site_id = %d ORDER BY fetched_at DESC, id DESC LIMIT 1", $siteId
        ), ARRAY_A);
        return $row ? SitePerformance::fromRow($row) : null;
    }

    /** @return SitePerformance[] oldest→newest */
    public function findForSiteInRange(int $siteId, string $fromUtc, string $toUtc): array
    {
        global $wpdb;
        $t = SitePerformanceTable::tableName();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t} WHERE site_id = %d AND fetched_at BETWEEN %s AND %s ORDER BY fetched_at ASC, id ASC",
            $siteId, $fromUtc, $toUtc
        ), ARRAY_A) ?: [];
        return array_map([SitePerformance::class, 'fromRow'], $rows);
    }
}
```

- [ ] **Step 3: Run green** → PASS; full suite → only `UninstallTest`. **Step 4: Commit** `feat(p6-1): SitePerformanceRepository (store + latest + in-range trend)`.

---

## Task 4: `Services\PageSpeedClient`

**Files:**
- Create: `packages/dashboard-plugin/src/Services/PageSpeedClient.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/PageSpeedClientTest.php`

- [ ] **Step 1: Write the failing test** (canned Lighthouse JSON through the injected HTTP seam — NO network):
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\PageSpeedClient;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class PageSpeedClientTest extends AbstractSchemaTestCase
{
    private function lighthouseJson(float $score, int $lcp, float $cls, int $inp, string $inpKey = 'interaction-to-next-paint'): string
    {
        return json_encode(['lighthouseResult' => [
            'categories' => ['performance' => ['score' => $score]],
            'audits' => [
                'largest-contentful-paint' => ['numericValue' => $lcp],
                'cumulative-layout-shift'  => ['numericValue' => $cls],
                $inpKey                    => ['numericValue' => $inp],
            ],
        ]]);
    }

    public function testParsesLighthouse(): void
    {
        $client = new PageSpeedClient(fn ($url, $args) => ['response' => ['code' => 200], 'body' => $this->lighthouseJson(0.82, 2100, 0.14, 180)]);
        $res = $client->fetch('https://acme.test', 'mobile');
        self::assertSame(82, $res['score']);
        self::assertSame(2100, $res['lcp_ms']);
        self::assertSame(0.14, $res['cls']);
        self::assertSame(180, $res['inp_ms']);
    }

    public function testFallsBackToExperimentalInp(): void
    {
        $client = new PageSpeedClient(fn ($url, $args) => ['response' => ['code' => 200], 'body' => $this->lighthouseJson(0.9, 800, 0.02, 90, 'experimental-interaction-to-next-paint')]);
        self::assertSame(90, $client->fetch('https://acme.test', 'desktop')['inp_ms']);
    }

    public function testNullOnHttpError(): void
    {
        $wpErr = new \WP_Error('http_request_failed', 'boom');
        self::assertNull((new PageSpeedClient(fn ($url, $args) => $wpErr))->fetch('https://acme.test', 'mobile'));
        self::assertNull((new PageSpeedClient(fn ($url, $args) => ['response' => ['code' => 500], 'body' => '']))->fetch('https://acme.test', 'mobile'));
        self::assertNull((new PageSpeedClient(fn ($url, $args) => ['response' => ['code' => 200], 'body' => 'not json']))->fetch('https://acme.test', 'mobile'));
    }
}
```
(`wp_remote_retrieve_response_code`/`wp_remote_retrieve_body` read `$res['response']['code']`/`$res['body']`; `is_wp_error` catches the `WP_Error` case.) Run red → FAIL.

- [ ] **Step 2: Create `src/Services/PageSpeedClient.php`** (declare `class PageSpeedClient` — **NOT `final`**; Task 6's test subclasses it to override `fetch`):
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Services;

// Not final — subclassed in PerformanceScanService's test to stub fetch().
class PageSpeedClient
{
    private const ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    /** @var callable(string,array):mixed */
    private $http;

    public function __construct(?callable $http = null)
    {
        $this->http = $http ?? static fn (string $url, array $args) => wp_remote_get($url, $args);
    }

    /** @return array{score:int,lcp_ms:?int,cls:?float,inp_ms:?int}|null */
    public function fetch(string $url, string $strategy): ?array
    {
        $query = [
            'url'      => $url,
            'strategy' => $strategy === 'desktop' ? 'desktop' : 'mobile',
            'category' => 'performance',
        ];
        if (defined('DEFYN_PAGESPEED_API_KEY') && DEFYN_PAGESPEED_API_KEY !== '') {
            $query['key'] = DEFYN_PAGESPEED_API_KEY;
        }
        $endpoint = self::ENDPOINT . '?' . http_build_query($query);

        $res = ($this->http)($endpoint, ['timeout' => 60, 'redirection' => 0]);
        if (is_wp_error($res)) {
            return null;
        }
        if ((int) wp_remote_retrieve_response_code($res) !== 200) {
            return null;
        }
        $data = json_decode((string) wp_remote_retrieve_body($res), true);
        if (!is_array($data) || !isset($data['lighthouseResult'])) {
            return null;
        }
        $lh    = $data['lighthouseResult'];
        $score = $lh['categories']['performance']['score'] ?? null;
        if ($score === null) {
            return null;
        }
        $audits = $lh['audits'] ?? [];
        $num = static function (array $audits, string $id): ?int {
            return isset($audits[$id]['numericValue']) ? (int) round((float) $audits[$id]['numericValue']) : null;
        };
        $cls = isset($audits['cumulative-layout-shift']['numericValue'])
            ? round((float) $audits['cumulative-layout-shift']['numericValue'], 3) : null;
        $inp = $num($audits, 'interaction-to-next-paint') ?? $num($audits, 'experimental-interaction-to-next-paint');

        return [
            'score'  => (int) round((float) $score * 100),
            'lcp_ms' => $num($audits, 'largest-contentful-paint'),
            'cls'    => $cls,
            'inp_ms' => $inp,
        ];
    }
}
```

- [ ] **Step 3: Run green** → PASS; full suite → only `UninstallTest`. **Step 4: Commit** `feat(p6-1): PageSpeedClient (Lighthouse parse, injectable seam, best-effort)`.

---

## Task 5: `DEFYN_PAGESPEED_API_KEY` env→define bootstrap

**Files:** Modify `packages/dashboard-plugin/defyn-dashboard.php`.

- [ ] **Step 1:** Find the `DEFYN_WORDFENCE_API_KEY` block (~lines 67–75) and add an analogous block right after it:
```php
// PageSpeed Insights API key (P6.1): raises the PSI quota for the weekly performance
// scan. Optional — PSI works keyless at low volume; when absent the scan still runs.
if (!defined('DEFYN_PAGESPEED_API_KEY')) {
    $envPsKey = getenv('DEFYN_PAGESPEED_API_KEY');
    if ($envPsKey !== false && $envPsKey !== '') {
        define('DEFYN_PAGESPEED_API_KEY', $envPsKey);
    }
}
```
- [ ] **Step 2:** `php -l packages/dashboard-plugin/defyn-dashboard.php` → clean. Full suite → only `UninstallTest`. **Step 3: Commit** `feat(p6-1): DEFYN_PAGESPEED_API_KEY env bootstrap`.

---

## Task 6: `Services\PerformanceScanService`

**Files:**
- Create: `packages/dashboard-plugin/src/Services/PerformanceScanService.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/PerformanceScanServiceTest.php`

- [ ] **Step 1: Write the failing test** (seedSite + a fake `PageSpeedClient` subclass injected; copy `setUp`/`seedSite` from `ReportsRepositoryTest`, also purge `defyn_activity_log`):
```php
    public function testStoresMobileAndDesktopSnapshot(): void
    {
        $siteId = $this->seedSite();
        $client = new class extends \Defyn\Dashboard\Services\PageSpeedClient {
            public function fetch(string $url, string $strategy): ?array {
                return ['score' => $strategy === 'mobile' ? 82 : 96, 'lcp_ms' => 2100, 'cls' => 0.14, 'inp_ms' => 180];
            }
        };
        (new \Defyn\Dashboard\Services\PerformanceScanService())->scan($siteId, $client);
        $latest = (new \Defyn\Dashboard\Services\SitePerformanceRepository())->latestForSite($siteId);
        self::assertSame(82, $latest->mobileScore);
        self::assertSame(96, $latest->desktopScore);
        global $wpdb;
        self::assertSame(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}defyn_activity_log WHERE event_type = 'site.performance_measured'"));
    }

    public function testBothFailSkipsRowAndEvent(): void
    {
        $siteId = $this->seedSite();
        $client = new class extends \Defyn\Dashboard\Services\PageSpeedClient {
            public function fetch(string $url, string $strategy): ?array { return null; }
        };
        (new \Defyn\Dashboard\Services\PerformanceScanService())->scan($siteId, $client);
        self::assertNull((new \Defyn\Dashboard\Services\SitePerformanceRepository())->latestForSite($siteId));
        global $wpdb;
        self::assertSame(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}defyn_activity_log WHERE event_type = 'site.performance_measured'"));
    }

    public function testMissingSiteIsNoop(): void
    {
        (new \Defyn\Dashboard\Services\PerformanceScanService())->scan(999999); // must not throw
        $this->expectNotToPerformAssertions();
    }
```
Run red → FAIL.

- [ ] **Step 2: Create `src/Services/PerformanceScanService.php`** (mirror `VulnerabilityScanService` resolve-site + best-effort shape; `Site->userId` owner, `Site->url`):
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Services;

final class PerformanceScanService
{
    public function __construct(
        private readonly ?SitePerformanceRepository $repo = null,
        private readonly ?SitesRepository $sites = null,
    ) {
    }

    public function scan(int $siteId, ?PageSpeedClient $client = null): void
    {
        $site = ($this->sites ?? new SitesRepository())->findById($siteId);
        if ($site === null) {
            return;
        }
        $client  = $client ?? new PageSpeedClient();
        $mobile  = $client->fetch($site->url, 'mobile');
        $desktop = $client->fetch($site->url, 'desktop');
        if ($mobile === null && $desktop === null) {
            return; // both failed — skip this cycle, best-effort
        }
        $now = gmdate('Y-m-d H:i:s');
        ($this->repo ?? new SitePerformanceRepository())->store($siteId, $mobile, $desktop, $now, $now);
        (new ActivityLogger())->log($site->userId, $siteId, 'site.performance_measured', [
            'mobile_score'  => $mobile['score']  ?? null,
            'desktop_score' => $desktop['score'] ?? null,
        ]);
    }
}
```
(VERIFY `SitesRepository::findById(int): ?Site` + `Site->userId` + `Site->url` — all confirmed present in P5.3.)

- [ ] **Step 3: Run green** → PASS; full suite → only `UninstallTest`. **Step 4: Commit** `feat(p6-1): PerformanceScanService (fetch mobile+desktop, best-effort, never throws)`.

---

## Task 7: Jobs + Scheduler + Plugin wiring + self-heal guard

**Files:**
- Create: `packages/dashboard-plugin/src/Jobs/PerformanceScan.php`, `packages/dashboard-plugin/src/Jobs/PerformanceScanAll.php`
- Modify: `packages/dashboard-plugin/src/Jobs/Scheduler.php`, `packages/dashboard-plugin/src/Plugin.php`, `packages/dashboard-plugin/src/Activation.php`
- Test: `packages/dashboard-plugin/tests/Integration/Jobs/PerformanceScanAllTest.php`

- [ ] **Step 1: Write the failing test** — READ `tests/Integration/Jobs/SecurityScanAllTest.php` (if present) and MIRROR exactly how it asserts the fan-out; otherwise assert via the AS store. Minimal version (seedSite is `status 'active'` ⇒ schedulable):
```php
    public function testFansOutPerSchedulableSite(): void
    {
        $siteId = $this->seedSite();
        (new \Defyn\Dashboard\Jobs\PerformanceScanAll())->handle();
        $scheduled = as_get_scheduled_actions([
            'hook' => \Defyn\Dashboard\Jobs\PerformanceScan::HOOK, 'group' => 'defyn', 'status' => \ActionScheduler_Store::STATUS_PENDING,
        ], 'ids');
        self::assertNotEmpty($scheduled);
    }
```
Run red → FAIL.

- [ ] **Step 2: Create `src/Jobs/PerformanceScan.php`** (mirror `src/Jobs/SecurityScan.php`):
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Services\PerformanceScanService;

final class PerformanceScan
{
    public const HOOK = 'defyn_performance_scan';

    public function __construct(private readonly ?PerformanceScanService $service = null)
    {
    }

    public function handle(int $siteId): void
    {
        ($this->service ?? new PerformanceScanService())->scan($siteId);
    }
}
```

- [ ] **Step 3: Create `src/Jobs/PerformanceScanAll.php`** (mirror `src/Jobs/SecurityScanAll.php` minus the feed refresh):
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Services\SitesRepository;

final class PerformanceScanAll
{
    public const HOOK = 'defyn_performance_scan_all';

    public function __construct(private readonly ?SitesRepository $repo = null)
    {
    }

    public function handle(): void
    {
        if (!function_exists('as_schedule_single_action')) {
            return;
        }
        foreach (($this->repo ?? new SitesRepository())->findAllSchedulable() as $siteId) {
            as_schedule_single_action(time(), PerformanceScan::HOOK, [$siteId], 'defyn');
        }
    }
}
```

- [ ] **Step 4: Scheduler cadence** — in `src/Jobs/Scheduler.php`, add to `SCHEDULES`: `PerformanceScanAll::HOOK => WEEK_IN_SECONDS,` (`use` it if the file uses imports; same-namespace classes resolve bare). `WEEK_IN_SECONDS` = 604800 (WP core constant).

- [ ] **Step 5: Plugin wiring** — in `src/Plugin.php`, add `use Defyn\Dashboard\Jobs\PerformanceScan;` + `use Defyn\Dashboard\Jobs\PerformanceScanAll;` and, after the `SecurityScan::HOOK` block:
```php
        add_action(PerformanceScanAll::HOOK, static function (): void {
            (new PerformanceScanAll())->handle();
        });
        add_action(PerformanceScan::HOOK, static function (int $siteId): void {
            (new PerformanceScan())->handle($siteId);
        });
```

- [ ] **Step 6: Self-heal guard** — in `src/Activation.php` `maybeRunSelfHeal()`, after the `GenerateMonthlyReportsAll::HOOK` guard, add a 4th guard (the class exists now, so no defer):
```php
        // P6.1 — ensure the weekly performance schedule exists on a silent upgrade.
        if (function_exists('as_next_scheduled_action')
            && as_next_scheduled_action(\Defyn\Dashboard\Jobs\PerformanceScanAll::HOOK, [], 'defyn') === false) {
            \Defyn\Dashboard\Jobs\Scheduler::installRecurringSchedules();
        }
```

- [ ] **Step 7: Run green** → `composer test:integration -- --filter "PerformanceScanAll|Scheduler"` PASS; full suite → only `UninstallTest`. **Step 8: Commit**
```bash
git add packages/dashboard-plugin/src/Jobs/PerformanceScan.php packages/dashboard-plugin/src/Jobs/PerformanceScanAll.php packages/dashboard-plugin/src/Jobs/Scheduler.php packages/dashboard-plugin/src/Plugin.php packages/dashboard-plugin/src/Activation.php packages/dashboard-plugin/tests/Integration/Jobs/PerformanceScanAllTest.php
git commit -m "feat(p6-1): weekly PerformanceScanAll fan-out + scan job + self-heal schedule"
```

---

## Task 8: CWV-rating helper (pure PHP)

**Files:**
- Create: `packages/dashboard-plugin/src/Services/CoreWebVitals.php`
- Test: `packages/dashboard-plugin/tests/Unit/Services/CoreWebVitalsTest.php`

- [ ] **Step 1: Write the failing unit test:**
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Unit\Services;

use Defyn\Dashboard\Services\CoreWebVitals;
use PHPUnit\Framework\TestCase;

final class CoreWebVitalsTest extends TestCase
{
    public function testRate(): void
    {
        self::assertSame('good', CoreWebVitals::rate('lcp', 2000));
        self::assertSame('needs-improvement', CoreWebVitals::rate('lcp', 3000));
        self::assertSame('poor', CoreWebVitals::rate('lcp', 5000));
        self::assertSame('good', CoreWebVitals::rate('cls', 0.05));
        self::assertSame('needs-improvement', CoreWebVitals::rate('cls', 0.2));
        self::assertSame('poor', CoreWebVitals::rate('cls', 0.5));
        self::assertSame('good', CoreWebVitals::rate('inp', 150));
        self::assertSame('poor', CoreWebVitals::rate('inp', 600));
        self::assertSame('unknown', CoreWebVitals::rate('lcp', null));
    }
}
```
Run red → FAIL.

- [ ] **Step 2: Create `src/Services/CoreWebVitals.php`:**
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Services;

/** Pure Core Web Vitals rating against Google's good/needs-improvement/poor thresholds. */
final class CoreWebVitals
{
    private const THRESHOLDS = [
        'lcp' => [2500.0, 4000.0],   // ms
        'cls' => [0.1, 0.25],        // unitless
        'inp' => [200.0, 500.0],     // ms
    ];

    public static function rate(string $metric, int|float|null $value): string
    {
        if ($value === null || !isset(self::THRESHOLDS[$metric])) {
            return 'unknown';
        }
        [$good, $ni] = self::THRESHOLDS[$metric];
        if ($value <= $good) {
            return 'good';
        }
        return $value <= $ni ? 'needs-improvement' : 'poor';
    }
}
```

- [ ] **Step 3: Run green** → PASS. **Step 4: Commit** `feat(p6-1): CoreWebVitals rating helper`.

---

## Task 9: `ReportService::compose` performance section

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/ReportService.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/ReportServiceTest.php` (append)

- [ ] **Step 1: Append the failing test** (use the file's existing `seedSite` signature + ensure its `setUp` purges `defyn_site_performance`):
```php
    public function testComposeIncludesPerformanceLatestAndHistory(): void
    {
        $siteId = $this->seedSite(1, 'https://acme.test', 'Acme', '6.9.4'); // match the file's seedSite signature
        $perf = new \Defyn\Dashboard\Services\SitePerformanceRepository();
        $m = ['score' => 70, 'lcp_ms' => 2200, 'cls' => 0.1, 'inp_ms' => 150];
        $d = ['score' => 90, 'lcp_ms' => 800, 'cls' => 0.02, 'inp_ms' => 60];
        $perf->store($siteId, $m, $d, '2026-05-10 03:00:00', '2026-05-10 03:00:05');
        $perf->store($siteId, ['score'=>82]+$m, ['score'=>96]+$d, '2026-05-31 03:00:00', '2026-05-31 03:00:05');
        $perf->store($siteId, ['score'=>40]+$m, ['score'=>70]+$d, '2026-03-01 03:00:00', '2026-03-01 03:00:05'); // out of range

        $report = (new \Defyn\Dashboard\Services\ReportService())->compose($siteId, 1, '2026-05-01 00:00:00', '2026-05-31 23:59:59');
        self::assertSame(82, $report['performance']['latest']['mobile']['score']); // latest = newest overall
        self::assertCount(2, $report['performance']['history']); // only the 2 in-range, oldest first
        self::assertSame(70, $report['performance']['history'][0]['mobile_score']);
    }

    public function testComposePerformanceNullWhenNeverMeasured(): void
    {
        $siteId = $this->seedSite(2, 'https://b.test', 'B', '6.9.4');
        $report = (new \Defyn\Dashboard\Services\ReportService())->compose($siteId, 1, '2026-05-01 00:00:00', '2026-05-31 23:59:59');
        self::assertNull($report['performance']['latest']);
        self::assertSame([], $report['performance']['history']);
    }
```
Run red → FAIL.

- [ ] **Step 2: Edit `ReportService.php`:**
  - Add a 7th ctor param: `private readonly ?SitePerformanceRepository $performance = null,`.
  - In `compose`, after `$security = …`, add: `$performance = $this->buildPerformance($siteId, $fromUtc, $toUtc);`.
  - Add `'performance' => $performance,` to the returned array (after `'security' => $security,`).
  - Add the builder method:
```php
    /** @return array<string,mixed> */
    private function buildPerformance(int $siteId, string $fromUtc, string $toUtc): array
    {
        $repo   = $this->performance ?? new SitePerformanceRepository();
        $latest = $repo->latestForSite($siteId);
        $latestJson = $latest === null ? null : [
            'fetched_at' => $latest->fetchedAt,
            'mobile'  => ['score' => $latest->mobileScore,  'lcp_ms' => $latest->mobileLcpMs,  'cls' => $latest->mobileCls,  'inp_ms' => $latest->mobileInpMs],
            'desktop' => ['score' => $latest->desktopScore, 'lcp_ms' => $latest->desktopLcpMs, 'cls' => $latest->desktopCls, 'inp_ms' => $latest->desktopInpMs],
        ];
        $history = [];
        foreach ($repo->findForSiteInRange($siteId, $fromUtc, $toUtc) as $p) {
            $history[] = ['fetched_at' => $p->fetchedAt, 'mobile_score' => $p->mobileScore, 'desktop_score' => $p->desktopScore];
        }
        return ['latest' => $latestJson, 'history' => $history];
    }
```

- [ ] **Step 3: Run green** → `composer test:integration -- --filter ReportServiceTest` PASS (existing + new); full suite → only `UninstallTest`. **Step 4: Commit** `feat(p6-1): ReportService.compose adds performance (latest + weekly trend)`.

---

## Task 10: `ReportPdfService` Performance section

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/ReportPdfService.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/ReportPdfServiceTest.php` (append)

- [ ] **Step 1: Append a failing test** (the `debugHtml` seam returns the HTML):
```php
    public function testRendersPerformanceSection(): void
    {
        $report = $this->sampleReport();
        $report['performance'] = [
            'latest' => [
                'fetched_at' => '2026-06-14 03:00:00',
                'mobile'  => ['score' => 82, 'lcp_ms' => 2100, 'cls' => 0.14, 'inp_ms' => 180],
                'desktop' => ['score' => 96, 'lcp_ms' => 900,  'cls' => 0.01, 'inp_ms' => 60],
            ],
            'history' => [
                ['fetched_at' => '2026-05-31 03:00:00', 'mobile_score' => 78, 'desktop_score' => 94],
                ['fetched_at' => '2026-06-14 03:00:00', 'mobile_score' => 82, 'desktop_score' => 96],
            ],
        ];
        $html = (new ReportPdfService(static fn ($u): ?string => null))->debugHtml($report, $this->branding());
        self::assertStringContainsString('Performance', $html);
        self::assertStringContainsString('82', $html);
        self::assertStringContainsString('Needs', $html); // CLS 0.14 → needs-improvement label
    }

    public function testRendersPerformanceNotMeasured(): void
    {
        $report = $this->sampleReport();
        $report['performance'] = ['latest' => null, 'history' => []];
        $html = (new ReportPdfService(static fn ($u): ?string => null))->debugHtml($report, $this->branding());
        self::assertStringContainsString('Not yet measured', $html);
    }
```
(The existing `sampleReport()` fixture lacks `performance` — `performanceHtml` MUST tolerate a missing key as "not measured" so the other PDF tests stay green.) Run red → FAIL.

- [ ] **Step 2: Edit `ReportPdfService.php`:**
  - In `buildHtml`, add `$performance = $this->performanceHtml($report);` and place `{$performance}` after `{$overview}`.
  - Add the section method (mirror the existing `securityHtml`/`sectionWithBody`/`esc` style + the existing `.stats`/`.stat-num`/`table.data`/`.muted` CSS classes):
```php
    /** @param array<string,mixed> $report */
    private function performanceHtml(array $report): string
    {
        $perf   = $report['performance'] ?? ['latest' => null, 'history' => []];
        $latest = $perf['latest'] ?? null;
        if ($latest === null) {
            return $this->sectionWithBody('Performance', '<p class="muted">Not yet measured.</p>');
        }
        $m = $latest['mobile'];  $d = $latest['desktop'];
        $when = $this->esc((string) ($latest['fetched_at'] ?? ''));
        $scoreRow = '<table class="stats"><tr>'
            . '<td><div class="stat-num">' . (int) ($m['score'] ?? 0) . '</div><div class="stat-label">Mobile</div></td>'
            . '<td><div class="stat-num">' . (int) ($d['score'] ?? 0) . '</div><div class="stat-label">Desktop</div></td>'
            . '</tr></table>';

        $cwv = '<table class="data"><thead><tr><th>Core Web Vital (mobile)</th><th>Value</th><th>Rating</th></tr></thead><tbody>'
            . $this->cwvRow('Largest Contentful Paint', $m['lcp_ms'] ?? null, 'lcp', 'ms')
            . $this->cwvRow('Cumulative Layout Shift', $m['cls'] ?? null, 'cls', '')
            . $this->cwvRow('Interaction to Next Paint', $m['inp_ms'] ?? null, 'inp', 'ms')
            . '</tbody></table>';

        $rows = '';
        foreach (($perf['history'] ?? []) as $h) {
            $rows .= '<tr><td>' . $this->esc((string) ($h['fetched_at'] ?? '')) . '</td><td>' . (int) ($h['mobile_score'] ?? 0) . '</td><td>' . (int) ($h['desktop_score'] ?? 0) . '</td></tr>';
        }
        $trend = $rows === '' ? '' : '<table class="data"><thead><tr><th>Measured</th><th>Mobile</th><th>Desktop</th></tr></thead><tbody>' . $rows . '</tbody></table>';

        $body = '<p class="muted">PageSpeed Insights (lab) &middot; measured ' . $when . '</p>' . $scoreRow . $cwv . $trend;
        return $this->sectionWithBody('Performance', $body);
    }

    private function cwvRow(string $label, int|float|null $value, string $metric, string $unit): string
    {
        $rating = \Defyn\Dashboard\Services\CoreWebVitals::rate($metric, $value);
        $shown  = $value === null ? '—' : ($metric === 'cls' ? (string) $value : (string) (int) $value . ($unit !== '' ? ' ' . $unit : ''));
        $word   = match ($rating) { 'good' => 'Good', 'needs-improvement' => 'Needs work', 'poor' => 'Poor', default => '—' };
        return '<tr><td>' . $this->esc($label) . '</td><td>' . $this->esc($shown) . '</td><td>' . $this->esc($word) . '</td></tr>';
    }
```

- [ ] **Step 3: Run green** → `composer test:integration -- --filter ReportPdfServiceTest` PASS (existing escaping/section tests still pass — `performanceHtml` tolerates the missing key); full suite → only `UninstallTest`. **Step 4: Commit** `feat(p6-1): ReportPdfService Performance section (scores + CWV + trend)`.

---

## Task 11: RateLimit buckets

**Files:** Modify `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php`; Test `packages/dashboard-plugin/tests/Integration/Rest/RateLimitPerformanceTest.php`.

- [ ] **Step 1: Write the failing test** for `performanceScan` (6/hr) — MIRROR the existing per-(user,site) bucket test (`RateLimitReportsTest` uses a real JWT via `TokenService::issueAccess` + `set_url_params(['id'=>…])`; copy that exactly). Assert the 7th call returns a `WP_Error` with status 429 + code `performance.rate_limited`. Run red → FAIL.

- [ ] **Step 2: Add 2 methods** to `RateLimit.php`, copied from the `siteReport` method shape:
  - `performanceScan` — `PERFORMANCE_SCAN_LIMIT = 6`, `HOUR_IN_SECONDS`, key `defyn_rl_performanceScan_%d_%d`, code `performance.rate_limited`.
  - `performanceRead` — `PERFORMANCE_READ_LIMIT = 30`, `MINUTE_IN_SECONDS`, key `defyn_rl_performanceRead_%d_%d`, code `performance.rate_limited`.

- [ ] **Step 3: Run green** → PASS; full suite → only `UninstallTest`. **Step 4: Commit** `feat(p6-1): RateLimit buckets for performance scan + read`.

---

## Task 12: Performance REST endpoints + routes + CORS

**Files:**
- Create: `packages/dashboard-plugin/src/Rest/SitesPerformanceScanController.php`, `packages/dashboard-plugin/src/Rest/SitesPerformanceController.php`
- Modify: `packages/dashboard-plugin/src/Rest/RestRouter.php`
- Test: `packages/dashboard-plugin/tests/Integration/Rest/SitesPerformanceTest.php` + `PerformanceCorsTest.php` (copy `ReportsCorsTest.php`)

- [ ] **Step 1: Write the failing test** `SitesPerformanceTest.php` (call controllers DIRECTLY; copy `setUp`/`seedSite` from `ReportsRepositoryTest`):
```php
    public function testScanNonOwnedReturns404(): void
    {
        $req = new \WP_REST_Request('POST', '/x');
        $req->set_param('_authenticated_user_id', 1);
        $req->set_param('id', 999999);
        $res = (new \Defyn\Dashboard\Rest\SitesPerformanceScanController())->handle($req);
        self::assertSame(404, $res->get_status());
        self::assertSame('sites.not_found', $res->get_data()['error']['code']);
    }

    public function testScanOwnedReturns202(): void
    {
        $siteId = $this->seedSite();
        $req = new \WP_REST_Request('POST', '/x');
        $req->set_param('_authenticated_user_id', 1);
        $req->set_param('id', $siteId);
        $res = (new \Defyn\Dashboard\Rest\SitesPerformanceScanController())->handle($req);
        self::assertSame(202, $res->get_status());
        self::assertTrue($res->get_data()['data']['scheduled']);
    }

    public function testGetLatestNullWhenNone(): void
    {
        $siteId = $this->seedSite();
        $req = new \WP_REST_Request('GET', '/x');
        $req->set_param('_authenticated_user_id', 1);
        $req->set_param('id', $siteId);
        $res = (new \Defyn\Dashboard\Rest\SitesPerformanceController())->handle($req);
        self::assertSame(200, $res->get_status());
        self::assertNull($res->get_data()['data']['latest']);
    }

    public function testGetLatestReturnsSnapshot(): void
    {
        $siteId = $this->seedSite();
        (new \Defyn\Dashboard\Services\SitePerformanceRepository())->store($siteId, ['score'=>82,'lcp_ms'=>2100,'cls'=>0.14,'inp_ms'=>180], ['score'=>96,'lcp_ms'=>900,'cls'=>0.01,'inp_ms'=>60], '2026-06-14 03:00:00', '2026-06-14 03:00:05');
        $req = new \WP_REST_Request('GET', '/x');
        $req->set_param('_authenticated_user_id', 1);
        $req->set_param('id', $siteId);
        $res = (new \Defyn\Dashboard\Rest\SitesPerformanceController())->handle($req);
        self::assertSame(82, $res->get_data()['data']['latest']['mobile_score']);
    }
```
Run red → FAIL.

- [ ] **Step 2: Create `src/Rest/SitesPerformanceScanController.php`:**
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Jobs\PerformanceScan;
use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

final class SitesPerformanceScanController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');
        if ((new SitesRepository())->findByIdForUser($siteId, $userId) === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(PerformanceScan::HOOK, [$siteId], 'defyn');
        }
        return new WP_REST_Response(['data' => ['scheduled' => true], 'error' => null], 202);
    }
}
```

- [ ] **Step 3: Create `src/Rest/SitesPerformanceController.php`:**
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\SitePerformanceRepository;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

final class SitesPerformanceController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');
        if ((new SitesRepository())->findByIdForUser($siteId, $userId) === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }
        $latest = (new SitePerformanceRepository())->latestForSite($siteId);
        return new WP_REST_Response(['data' => ['latest' => $latest?->toJson()], 'error' => null], 200);
    }
}
```

- [ ] **Step 4: Register routes** in `src/Rest/RestRouter.php` (after the report routes; add the two `use` imports; two-call form per convention):
```php
        register_rest_route(self::NAMESPACE, '/sites/(?P<id>\d+)/performance', [
            'methods' => 'GET', 'callback' => [new SitesPerformanceController(), 'handle'],
            'permission_callback' => [RateLimit::class, 'performanceRead'],
        ]);
        register_rest_route(self::NAMESPACE, '/sites/(?P<id>\d+)/performance/scan', [
            'methods' => 'POST', 'callback' => [new SitesPerformanceScanController(), 'handle'],
            'permission_callback' => [RateLimit::class, 'performanceScan'],
        ]);
```

- [ ] **Step 5: CORS test** — copy `tests/Integration/Rest/ReportsCorsTest.php` → `PerformanceCorsTest.php`, asserting `Cors::apply` for `GET /defyn/v1/sites/1/performance` + `POST /defyn/v1/sites/1/performance/scan`, plus a route-resolution check (authed `GET /sites/999999/performance` → 404 `sites.not_found`, NOT `rest_no_route`).

- [ ] **Step 6: Run green** → `composer test:integration -- --filter "SitesPerformance|PerformanceCors"` PASS; full suite → only `UninstallTest`. **Step 7: Commit** `feat(p6-1): performance scan + read endpoints + routes + CORS`.

---

## Task 13: Dashboard v0.20.0 bump

**Files:** Modify `packages/dashboard-plugin/defyn-dashboard.php`.

- [ ] **Step 1:** line 6 `Version: 0.19.0` → `0.20.0`; line 46 `define('DEFYN_DASHBOARD_VERSION', '0.19.0')` → `'0.20.0'`.
- [ ] **Step 2:** `grep -rn "0\.19\.0" packages/dashboard-plugin/src packages/dashboard-plugin/tests` → no matches; `grep -n "0.20.0" packages/dashboard-plugin/defyn-dashboard.php` → 2 lines. **Step 3: Commit** `chore(p6-1): bump dashboard plugin to v0.20.0`.

---

## Task 14: SPA — report `performance` schema + `ReportPerformance` section

**Files:**
- Modify: `apps/web/src/types/api.ts` (the report-payload schema — P5.3 renamed P5.1's type to `siteReportSchema`/`SiteReport`; verify), `apps/web/src/pages/SiteReport.tsx`, the MSW report fixture in `apps/web/src/test/handlers.ts`
- Create: `apps/web/src/lib/coreWebVitals.ts`, `apps/web/src/components/report/ReportPerformance.tsx`
- Test: `apps/web/tests/ReportPerformance.test.tsx` + `apps/web/tests/coreWebVitals.test.ts`

- [ ] **Step 1: Node 22 + write failing tests.** `coreWebVitals.test.ts`:
```ts
import { describe, it, expect } from 'vitest';
import { rateCwv } from '@/lib/coreWebVitals';

describe('rateCwv', () => {
  it('rates by threshold', () => {
    expect(rateCwv('lcp', 2000)).toBe('good');
    expect(rateCwv('lcp', 3000)).toBe('needs-improvement');
    expect(rateCwv('lcp', 5000)).toBe('poor');
    expect(rateCwv('cls', 0.05)).toBe('good');
    expect(rateCwv('inp', 600)).toBe('poor');
    expect(rateCwv('lcp', null)).toBe('unknown');
  });
});
```
`ReportPerformance.test.tsx` — render with a `performance` prop (latest mobile 82 / desktop 96 + CWV + history); assert the scores render + a CWV rating label; render with `{latest:null}` → "Not yet measured". (Match the existing `components/report/*` test harness.) Run red → FAIL.

- [ ] **Step 2: Create `apps/web/src/lib/coreWebVitals.ts`:**
```ts
export type CwvRating = 'good' | 'needs-improvement' | 'poor' | 'unknown';
const THRESHOLDS: Record<string, [number, number]> = {
  lcp: [2500, 4000],
  cls: [0.1, 0.25],
  inp: [200, 500],
};
export function rateCwv(metric: 'lcp' | 'cls' | 'inp', value: number | null): CwvRating {
  if (value === null || !(metric in THRESHOLDS)) return 'unknown';
  const [good, ni] = THRESHOLDS[metric];
  if (value <= good) return 'good';
  return value <= ni ? 'needs-improvement' : 'poor';
}
```

- [ ] **Step 3: Extend the report schema** in `apps/web/src/types/api.ts` — add to the report-payload object schema (the one `useSiteReport` parses; confirm whether it's `siteReportSchema`):
```ts
export const reportPerformanceSchema = z.object({
  latest: z.object({
    fetched_at: z.string(),
    mobile: z.object({ score: z.number().nullable(), lcp_ms: z.number().nullable(), cls: z.number().nullable(), inp_ms: z.number().nullable() }),
    desktop: z.object({ score: z.number().nullable(), lcp_ms: z.number().nullable(), cls: z.number().nullable(), inp_ms: z.number().nullable() }),
  }).nullable(),
  history: z.array(z.object({ fetched_at: z.string(), mobile_score: z.number().nullable(), desktop_score: z.number().nullable() })),
});
// add `performance: reportPerformanceSchema` to the report-payload object schema.
```
Update the MSW report fixture in `src/test/handlers.ts` to include a `performance` object so existing report tests stay green.

- [ ] **Step 4: Create `apps/web/src/components/report/ReportPerformance.tsx`** — mirror an existing `components/report/ReportSecurity.tsx` shell: a "Performance" section with mobile + desktop score blocks, a CWV table (using `rateCwv` → a colored label per row), and a small weekly trend (a list/sparkline of `history`); `latest === null` → "Not yet measured." Render it in `pages/SiteReport.tsx` after `ReportOverview`.

- [ ] **Step 5: Run green** → `pnpm test -- --run "ReportPerformance|coreWebVitals"` PASS; full `pnpm test -- --run` → only the 4 carry-forwards; `pnpm build` clean. **Step 6: Commit** `feat(p6-1): SPA report Performance section + CWV helper`.

---

## Task 15: SPA — Site-detail performance panel (latest + Measure now)

**Files:**
- Modify: `apps/web/src/types/api.ts` (`sitePerformanceSchema`), `apps/web/src/routes/SiteDetail.tsx`, `apps/web/src/test/handlers.ts`
- Create: `apps/web/src/lib/queries/useSitePerformance.ts`, `apps/web/src/lib/mutations/useMeasurePerformance.ts`, `apps/web/src/components/sites/SitePerformancePanel.tsx`
- Test: `apps/web/tests/SitePerformancePanel.test.tsx`

- [ ] **Step 1: Write the failing test** — mock `useSitePerformance` (latest snapshot) + spy `useMeasurePerformance`; assert the panel shows mobile/desktop score, "Measure now" calls the mutation, and a `{latest:null}` shows "Not yet measured." (Mirror `tests/components/sites/SiteSecurityPanel.test.tsx`.) Run red → FAIL.

- [ ] **Step 2: Add `sitePerformanceSchema`** to `api.ts`:
```ts
export const sitePerformanceSchema = z.object({
  id: z.number(), site_id: z.number(),
  mobile_score: z.number().nullable(), mobile_lcp_ms: z.number().nullable(), mobile_cls: z.number().nullable(), mobile_inp_ms: z.number().nullable(),
  desktop_score: z.number().nullable(), desktop_lcp_ms: z.number().nullable(), desktop_cls: z.number().nullable(), desktop_inp_ms: z.number().nullable(),
  fetched_at: z.string(), created_at: z.string(),
});
export const sitePerformanceResponseSchema = z.object({ data: z.object({ latest: sitePerformanceSchema.nullable() }), error: z.null() });
```
MSW: `GET /sites/:id/performance` → `{data:{latest:<snapshot|null>}, error:null}`; `POST /sites/:id/performance/scan` → 202 `{data:{scheduled:true}, error:null}`.

- [ ] **Step 3: Create the hooks** (`apiClient.get` returns the raw envelope):
```ts
// useSitePerformance.ts
export function useSitePerformance(siteId: number) {
  return useQuery({
    queryKey: ['sitePerformance', siteId],
    queryFn: async () => sitePerformanceResponseSchema.parse(await apiClient.get(`/sites/${siteId}/performance`)).data,
  });
}
```
`useMeasurePerformance(siteId)` — `mutationFn: () => apiClient.post(\`/sites/${siteId}/performance/scan\`)`; `onSuccess` → `qc.invalidateQueries({queryKey:['sitePerformance', siteId]})`. **Bounded poll** (mirror P4.1 `useScanSiteSecurity` poll-vs-previous-timestamp): capture the pre-scan `latest?.fetched_at`; the query's `refetchInterval` returns 5000 only while the latest `fetched_at` equals the captured value AND a poll-count ref is under a cap (~18 ⇒ ~90s), else `false`. It can NEVER poll forever. Any seed effect keys on primitives (P2.10).

- [ ] **Step 4: Create `apps/web/src/components/sites/SitePerformancePanel.tsx`** (mirror `SiteSecurityPanel` shell): header "Performance", latest mobile + desktop score (+ "Last measured {fetched_at}" / "Not yet measured"), a **Measure now** button → `useMeasurePerformance`. Mount `<SitePerformancePanel siteId={siteId} />` in `routes/SiteDetail.tsx` near the security panel.

- [ ] **Step 5: Run green** → `pnpm test -- --run SitePerformancePanel` PASS; full `pnpm test -- --run` → only 4 carry-forwards (NO hang — bounded poll, primitive seeds); `pnpm build` clean. **Step 6: Commit** `feat(p6-1): SitePerformancePanel + useSitePerformance/useMeasurePerformance (bounded poll)`.

---

## Task 16: Release — build, ship, smoke, tag, MEMORY

**Files:** build artifacts only.

- [ ] **Step 1:** Full PHP suite → only `UninstallTest`. **Step 2:** Full SPA suite (Node 22) → only the 4 carry-forwards; `cd apps/web && pnpm build` clean.
- [ ] **Step 3: dompdf-preserving zip** (verify list unchanged):
```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && composer install --no-dev --classmap-authoritative
cd "/Users/pradeep/Local Sites/defynWP/packages"
rm -f "/Users/pradeep/Local Sites/defynWP/dist/defyn-dashboard-0.20.0.zip"
zip -rq "/Users/pradeep/Local Sites/defynWP/dist/defyn-dashboard-0.20.0.zip" dashboard-plugin \
  -x 'dashboard-plugin/tests/*' '*wp-tests-config.php' 'dashboard-plugin/.phpunit.result.cache' \
     'dashboard-plugin/test-output.log' 'dashboard-plugin/phpunit.xml' 'dashboard-plugin/composer.lock' \
     'dashboard-plugin/.github/*' 'dashboard-plugin/.gitignore'
cd "/Users/pradeep/Local Sites/defynWP"
unzip -l dist/defyn-dashboard-0.20.0.zip | grep -cE "deprecation-contracts/function\.php|polyfill-php83/bootstrap\.php"  # MUST be 2
unzip -l dist/defyn-dashboard-0.20.0.zip | grep -c "json-machine/src/Items\.php"  # >=1
unzip -l dist/defyn-dashboard-0.20.0.zip | grep -c "dompdf/src/Dompdf\.php"  # >=1
unzip -l dist/defyn-dashboard-0.20.0.zip | grep -c "src/Schema/SitePerformanceTable\.php"  # >=1
unzip -p dist/defyn-dashboard-0.20.0.zip dashboard-plugin/defyn-dashboard.php | grep -m1 DEFYN_DASHBOARD_VERSION
cd packages/dashboard-plugin && composer install
```
- [ ] **Step 4: Merge + push** `git checkout main && git merge --ff-only p6-1-performance && git push origin main`.
- [ ] **Step 5: Kinsta install (MANUAL — pause for "installed").** Upload `dist/defyn-dashboard-0.20.0.zip` via "Replace current with uploaded version"; clear MyKinsta cache. Schema v14 self-heals.
- [ ] **Step 6: Indirect curl smoke** (login field `access_token`; backend `defynwp.defyn.agency`): `GET /sites/1/performance` no-auth → 401; `GET /sites/999999/performance` auth → 404 `sites.not_found` (proves route + v14 live); `POST /sites/999999/performance/scan` auth → 404; happy paths foreclosed by zero-sites prod; verify the deployed SPA bundle contains 'Performance' / 'Measure now' / 'Not yet measured'.
- [ ] **Step 7: Tag** `git tag p6-1-performance-complete && git push origin p6-1-performance-complete`.
- [ ] **Step 8: MEMORY** — append a P6.1-complete entry to `project_defyn_roadmap.md` (v0.20.0, tag, schema v14, the weekly PageSpeed snapshot + report Performance section through all 3 surfaces, on-demand measure, smoke results) + refresh `MEMORY.md`. **Set NEXT = P6.2 (GA4 Analytics — the OAuth slice).** **Operator action (optional):** set `DEFYN_PAGESPEED_API_KEY` on Kinsta for quota headroom.

---

## Self-Review (completed during planning)

- **Spec coverage:** data source + best-effort fetch → Task 4; schema v14 → Task 1; DTO/repo → Tasks 2–3; env key → Task 5; scan service → Task 6; weekly fan-out + self-heal → Task 7; CWV thresholds → Task 8 (PHP) + Task 14 (TS); report `performance` (3 surfaces) → Task 9 (compose) + Task 10 (PDF) + Task 14 (on-screen) + P5.3 stored-queue inherits free; rate limits → Task 11; endpoints + CORS → Task 12; version → Task 13; Site-detail panel + Measure-now → Task 15; release → Task 16. ✅
- **Type consistency:** `SitePerformance` props (`mobileScore`, `mobileLcpMs`, `mobileCls`, `mobileInpMs`, desktop_*, `fetchedAt`, `createdAt`) consistent across `fromRow`/`toJson`/repo/compose. The fetch result shape `{score, lcp_ms, cls, inp_ms}` consistent across `PageSpeedClient` → `PerformanceScanService` → `SitePerformanceRepository::store`. `PerformanceScan::HOOK`/`PerformanceScanAll::HOOK` consistent across Tasks 7/12. CWV ratings (`good`/`needs-improvement`/`poor`/`unknown`) + thresholds identical in the PHP (Task 8) and TS (Task 14) helpers. Error codes `sites.not_found`/`performance.rate_limited` consistent. ✅
- **Open verifications flagged inline:** `PageSpeedClient` NON-final (Task 4) for the Task-6 subclass seam; `SitesRepository::findById` + `Site->userId`/`->url` (confirmed in P5.3); the report-payload Zod schema name (`siteReportSchema` after P5.3's rename); how `SecurityScanAllTest` asserts the fan-out (mirror in Task 7); the existing `ReportServiceTest`/`ReportPdfServiceTest` `seedSite`/`sampleReport` signatures (append, keep green); `as_get_scheduled_actions` availability in tests.
- **No-placeholder scan:** every code step has complete code; no TBD / "similar to Task N". ✅
