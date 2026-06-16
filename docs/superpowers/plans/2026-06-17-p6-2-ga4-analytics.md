# P6.2 — GA4 Analytics Report Section — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a GA4 **Analytics** section (totals + top pages + traffic channels) to the client maintenance report across all three surfaces (on-screen, branded PDF, P5.3 stored queue), sourced from background-cached calendar-month snapshots of the Google Analytics 4 Data API.

**Architecture:** An agency-level Google service account (JSON key in env `DEFYN_GA4_SERVICE_ACCOUNT_JSON`) authenticates via RS256 JWT-bearer (using the already-bundled `firebase/php-jwt`) to the GA4 Data API. A weekly background fan-out fetches current + previous calendar month per site (those with a `ga4_property_id`) into a `wp_defyn_site_analytics` snapshot table. `ReportService::compose` reads the month-matching snapshot — never calls GA4 synchronously. Mirrors P6.1's best-effort snapshot model and P5.3's per-site setting endpoint.

**Tech Stack:** PHP 8.1 (WordPress plugin, PHPUnit/wp-phpunit), `firebase/php-jwt ^7.0` (already a prod dep — RS256), `wp_remote_post`, Action Scheduler; React 18 + TS + TanStack Query v5 + Zod + Vitest + MSW (apps/web, Node 22, pnpm). Connector UNCHANGED (v0.1.7). Dashboard v0.20.0 → v0.21.0. Schema v14 → v15.

**Branch:** `p6-2-ga4-analytics` (off `main` @ 6bc172d). Spec: `docs/superpowers/specs/2026-06-17-p6-2-ga4-analytics-design.md`.

**Baselines going in:** PHP 863 pass / 1 fail (only `UninstallTest`). SPA 433 pass / 4 carry-forward (`SiteDetail`×2 + `SiteCoreCard`×2). DB online on port 10166 (no standalone-mysqld dance). Full PHP suite: `COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit` (tolerate ONLY `UninstallTest`). SPA: `export FNM_DIR="$HOME/.fnm"; eval "$(fnm env --shell bash)"; fnm use 22` then `pnpm test -- --run`; `pnpm build` must be tsc-clean.

**Guardrail #15 (test isolation):** every test that seeds `defyn_sites` must, in `setUp()`, `parent::setUp()` then `SET autocommit=1` + `DELETE FROM` the tables it touches (`defyn_site_analytics`, `defyn_sites`, `defyn_activity_log` where asserted) + `Activation::ensureSchema()`. Real `defyn_sites` cols: `id,user_id,url,label,status,created_at,updated_at,wp_version` (+ now `ga4_property_id`). `Site` owner prop = `->userId`. `ActivityLogger` is in `Services\`, `log(?userId,?siteId,event,?details,?ip)`.

All file paths are relative to `packages/dashboard-plugin/` (PHP) or `apps/web/` (SPA) unless absolute.

---

## File Structure

**New (PHP):**
- `src/Schema/SiteAnalyticsTable.php` — the `wp_defyn_site_analytics` snapshot table.
- `src/Models/SiteAnalytics.php` — immutable snapshot DTO.
- `src/Services/SiteAnalyticsRepository.php` — upsert/find snapshots.
- `src/Services/Ga4Client.php` — GA4 Data API client (NOT final, injectable HTTP + SA-JSON seam).
- `src/Services/AnalyticsScanService.php` — per-site fetch orchestrator (best-effort).
- `src/Services/EngagementFormat.php` — pure `format(int $seconds): string`.
- `src/Jobs/AnalyticsSync.php` + `src/Jobs/AnalyticsSyncAll.php` — AS leaf + weekly fan-out.
- `src/Rest/SitesAnalyticsController.php` + `src/Rest/SitesGa4PropertyController.php` + `src/Rest/SitesAnalyticsRefreshController.php`.

**Modified (PHP):**
- `src/Activation.php` (SCHEMA_VERSION 14→15, TABLES, guarded ALTER, 5th self-heal guard), `src/Jobs/Scheduler.php` (SCHEDULES), `defyn-dashboard.php` (env→define + version bump), `src/Plugin.php` (2 add_action), `src/Services/ReportService.php` (8th dep + analytics key), `src/Services/ReportPdfService.php` (analyticsHtml), `src/Rest/Middleware/RateLimit.php` (3 buckets), `src/Rest/RestRouter.php` (3 routes), `src/Models/Site.php` (ga4_property_id), `src/Services/SitesRepository.php` (setGa4PropertyId + hydration).

**New (SPA):** `src/lib/engagement.ts`, `src/components/report/ReportAnalytics.tsx`, `src/lib/queries/useSiteAnalytics.ts`, `src/lib/mutations/useSetGa4Property.ts`, `src/lib/mutations/useRefreshAnalytics.ts`, `src/components/sites/SiteAnalyticsPanel.tsx`, plus tests.

**Modified (SPA):** `src/types/api.ts`, `src/pages/SiteReport.tsx`, `src/routes/SiteDetail.tsx`, `src/test/handlers.ts`, + the 2 existing report fixtures.

---

## Task 1: Schema v15 — `SiteAnalyticsTable` + `ga4_property_id` column + version-pin ripple

**Files:**
- Create: `src/Schema/SiteAnalyticsTable.php`
- Modify: `src/Activation.php`
- Test: `tests/Integration/Schema/SiteAnalyticsSchemaTest.php` + ripple across existing schema tests

- [ ] **Step 1: Create `src/Schema/SiteAnalyticsTable.php`** (mirror `SitePerformanceTable`):
```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Schema;

/**
 * P6.2 — wp_defyn_site_analytics.
 *
 * One row per (site, calendar-month) GA4 snapshot: headline totals + the
 * top-pages and channels breakdowns (stored as JSON). All metric columns are
 * NULL-able so a partial/zero-traffic month still stores a row. Lookups are by
 * (site_id, period_start) for the report's month match and the latest-for-site
 * panel headline.
 */
final class SiteAnalyticsTable implements SchemaTable
{
    public static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'defyn_site_analytics';
    }

    public static function createSql(): string
    {
        global $wpdb;
        $table   = self::tableName();
        $charset = $wpdb->get_charset_collate();

        return "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            site_id BIGINT UNSIGNED NOT NULL,
            period_start DATE NOT NULL,
            period_end DATE NOT NULL,
            sessions INT UNSIGNED NULL,
            total_users INT UNSIGNED NULL,
            screen_page_views INT UNSIGNED NULL,
            avg_session_duration DECIMAL(10,2) NULL,
            top_pages LONGTEXT NULL,
            channels LONGTEXT NULL,
            fetched_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_analytics_site_period (site_id, period_start)
        ) {$charset};";
    }
}
```

- [ ] **Step 2: Edit `src/Activation.php`:**
  - `public const SCHEMA_VERSION = 14;` → `15`.
  - Add `SiteAnalyticsTable::class,` to the end of the `TABLES` const array (after `SitePerformanceTable::class,`).
  - Add a guarded idempotent ALTER method (mirror the `client_email` one) and call it from wherever `addClientEmailColumn` is called (the schema-ensure path):
```php
    private static function addGa4PropertyColumn(\wpdb $wpdb): void
    {
        $table  = SitesTable::tableName();
        $exists = $wpdb->get_var($wpdb->prepare(
            "SHOW COLUMNS FROM `{$table}` LIKE %s",
            'ga4_property_id'
        ));
        if ($exists !== null) {
            return;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL — column DDL cannot be parameterized.
        $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN ga4_property_id VARCHAR(32) NULL");
    }
```
  (Find where `addClientEmailColumn($wpdb)` is invoked and add `self::addGa4PropertyColumn($wpdb);` right after it.)

- [ ] **Step 3: Write the schema test** `tests/Integration/Schema/SiteAnalyticsSchemaTest.php`:
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Schema;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Schema\SiteAnalyticsTable;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SiteAnalyticsSchemaTest extends AbstractSchemaTestCase
{
    public function testTableExistsAfterActivation(): void
    {
        global $wpdb;
        Activation::ensureSchema();
        $table = SiteAnalyticsTable::tableName();
        self::assertSame($table, $wpdb->get_var("SHOW TABLES LIKE '{$table}'"));
    }

    public function testGa4PropertyColumnExists(): void
    {
        global $wpdb;
        Activation::ensureSchema();
        $table = SitesTable::tableName();
        self::assertNotNull($wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'ga4_property_id')));
    }

    public function testSchemaVersionIs15(): void
    {
        self::assertSame(15, Activation::SCHEMA_VERSION);
    }
}
```

- [ ] **Step 4: Version-pin ripple.** Run `grep -rn "assertSame(14, Activation::SCHEMA_VERSION)\|assertSame(14, \\\\Defyn" tests/` and bump EVERY `14` → `15`. (P5.3 had ~11 across ~9 files; expect similar.)

- [ ] **Step 5: Run** `composer test:integration -- --filter "SiteAnalyticsSchema|SchemaVersion"` → green. Then full suite tolerating only `UninstallTest`.

- [ ] **Step 6: Commit** — **stage the parent `tests/Integration/` dir, not just `tests/Integration/Schema/`** (P5.3 add-path miss):
```bash
cd "/Users/pradeep/Local Sites/defynWP"
git add packages/dashboard-plugin/src/Schema/SiteAnalyticsTable.php packages/dashboard-plugin/src/Activation.php packages/dashboard-plugin/tests/Integration/
git commit -m "feat(p6-2): schema v15 — site_analytics table + ga4_property_id column"
```

---

## Task 2: `Models\SiteAnalytics` DTO

**Files:** Create `src/Models/SiteAnalytics.php`; Test `tests/Unit/Models/SiteAnalyticsTest.php`.

- [ ] **Step 1: Write the failing test** `tests/Unit/Models/SiteAnalyticsTest.php`:
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Unit\Models;

use Defyn\Dashboard\Models\SiteAnalytics;
use PHPUnit\Framework\TestCase;

final class SiteAnalyticsTest extends TestCase
{
    public function testFromRowDecodesJsonAndToJsonRoundTrips(): void
    {
        $row = [
            'id' => '5', 'site_id' => '3',
            'period_start' => '2026-06-01', 'period_end' => '2026-06-30',
            'sessions' => '12480', 'total_users' => '9210', 'screen_page_views' => '31540',
            'avg_session_duration' => '108.50',
            'top_pages' => json_encode([['path'=>'/','title'=>'Home','views'=>8420]]),
            'channels'  => json_encode([['channel'=>'Organic Search','sessions'=>5200]]),
            'fetched_at' => '2026-06-30 03:00:00', 'created_at' => '2026-06-30 03:00:05',
        ];
        $a = SiteAnalytics::fromRow($row);
        self::assertSame(12480, $a->sessions);
        self::assertSame(108.5, $a->avgSessionDuration);
        self::assertSame('Home', $a->topPages[0]['title']);
        self::assertSame('Organic Search', $a->channels[0]['channel']);

        $json = $a->toJson();
        self::assertSame('2026-06-01', $json['period_start']);
        self::assertSame(9210, $json['total_users']);
        self::assertSame([['path'=>'/','title'=>'Home','views'=>8420]], $json['top_pages']);
    }

    public function testFromRowToleratesNullJsonAndMetrics(): void
    {
        $row = [
            'id' => '1', 'site_id' => '1', 'period_start' => '2026-05-01', 'period_end' => '2026-05-31',
            'sessions' => null, 'total_users' => null, 'screen_page_views' => null, 'avg_session_duration' => null,
            'top_pages' => null, 'channels' => null,
            'fetched_at' => '2026-06-01 03:00:00', 'created_at' => '2026-06-01 03:00:00',
        ];
        $a = SiteAnalytics::fromRow($row);
        self::assertNull($a->sessions);
        self::assertSame([], $a->topPages);
        self::assertSame([], $a->channels);
    }
}
```

- [ ] **Step 2: Run red** `composer test:unit -- --filter SiteAnalyticsTest` → FAIL.

- [ ] **Step 3: Create `src/Models/SiteAnalytics.php`:**
```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Models;

/**
 * P6.2 — one (site, calendar-month) GA4 snapshot. Immutable. top_pages/channels
 * are decoded JSON arrays; metric counts are null-tolerant (zero-traffic months
 * still store a row).
 */
final class SiteAnalytics
{
    /**
     * @param list<array{path:string,title:string,views:int}> $topPages
     * @param list<array{channel:string,sessions:int}>        $channels
     */
    public function __construct(
        public readonly int $id,
        public readonly int $siteId,
        public readonly string $periodStart,
        public readonly string $periodEnd,
        public readonly ?int $sessions,
        public readonly ?int $totalUsers,
        public readonly ?int $screenPageViews,
        public readonly ?float $avgSessionDuration,
        public readonly array $topPages,
        public readonly array $channels,
        public readonly string $fetchedAt,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        $int = static fn ($v): ?int => $v === null || $v === '' ? null : (int) $v;
        $flt = static fn ($v): ?float => $v === null || $v === '' ? null : (float) $v;
        $arr = static function ($v): array {
            if (!is_string($v) || $v === '') {
                return [];
            }
            $decoded = json_decode($v, true);
            return is_array($decoded) ? $decoded : [];
        };

        return new self(
            id: (int) $row['id'],
            siteId: (int) $row['site_id'],
            periodStart: (string) $row['period_start'],
            periodEnd: (string) $row['period_end'],
            sessions: $int($row['sessions'] ?? null),
            totalUsers: $int($row['total_users'] ?? null),
            screenPageViews: $int($row['screen_page_views'] ?? null),
            avgSessionDuration: $flt($row['avg_session_duration'] ?? null),
            topPages: $arr($row['top_pages'] ?? null),
            channels: $arr($row['channels'] ?? null),
            fetchedAt: (string) $row['fetched_at'],
        );
    }

    /** @return array<string,mixed> */
    public function toJson(): array
    {
        return [
            'id'                   => $this->id,
            'site_id'              => $this->siteId,
            'period_start'         => $this->periodStart,
            'period_end'           => $this->periodEnd,
            'sessions'             => $this->sessions,
            'total_users'          => $this->totalUsers,
            'screen_page_views'    => $this->screenPageViews,
            'avg_session_duration' => $this->avgSessionDuration,
            'top_pages'            => $this->topPages,
            'channels'             => $this->channels,
            'fetched_at'           => $this->fetchedAt,
        ];
    }
}
```

- [ ] **Step 4: Run green** → pass. **Step 5: Commit** `feat(p6-2): SiteAnalytics DTO`.

---

## Task 3: `Services\SiteAnalyticsRepository`

**Files:** Create `src/Services/SiteAnalyticsRepository.php`; Test `tests/Integration/Services/SiteAnalyticsRepositoryTest.php`.

- [ ] **Step 1: Write the failing test:**
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\SiteAnalyticsRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SiteAnalyticsRepositoryTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb->query('SET autocommit = 1');
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'defyn_site_analytics');
    }

    private function data(int $sessions): array
    {
        return [
            'sessions' => $sessions, 'users' => 9210, 'pageviews' => 31540, 'avg_engagement' => 108.5,
            'top_pages' => [['path'=>'/','title'=>'Home','views'=>8420]],
            'channels'  => [['channel'=>'Organic Search','sessions'=>5200]],
        ];
    }

    public function testUpsertReplacesByTuple(): void
    {
        $repo = new SiteAnalyticsRepository();
        $repo->upsertForSiteAndPeriod(3, '2026-06-01', '2026-06-30', $this->data(100), '2026-06-30 03:00:00', '2026-06-30 03:00:00');
        $repo->upsertForSiteAndPeriod(3, '2026-06-01', '2026-06-30', $this->data(200), '2026-06-30 04:00:00', '2026-06-30 04:00:00');

        global $wpdb;
        $count = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'defyn_site_analytics WHERE site_id = 3');
        self::assertSame(1, $count); // replaced, not duplicated
        $snap = $repo->findForSiteAndMonth(3, '2026-06-01');
        self::assertSame(200, $snap->sessions);
        self::assertSame('Home', $snap->topPages[0]['title']);
    }

    public function testFindForSiteAndMonthMissesOtherMonth(): void
    {
        $repo = new SiteAnalyticsRepository();
        $repo->upsertForSiteAndPeriod(3, '2026-06-01', '2026-06-30', $this->data(100), '2026-06-30 03:00:00', '2026-06-30 03:00:00');
        self::assertNull($repo->findForSiteAndMonth(3, '2026-05-01'));
    }

    public function testLatestForSiteReturnsNewestMonth(): void
    {
        $repo = new SiteAnalyticsRepository();
        $repo->upsertForSiteAndPeriod(3, '2026-05-01', '2026-05-31', $this->data(50), '2026-06-01 03:00:00', '2026-06-01 03:00:00');
        $repo->upsertForSiteAndPeriod(3, '2026-06-01', '2026-06-30', $this->data(90), '2026-07-01 03:00:00', '2026-07-01 03:00:00');
        self::assertSame('2026-06-01', $repo->latestForSite(3)->periodStart);
        self::assertNull($repo->latestForSite(999));
    }
}
```

- [ ] **Step 2: Run red** → FAIL. **Step 3: Create `src/Services/SiteAnalyticsRepository.php`:**
```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Models\SiteAnalytics;
use Defyn\Dashboard\Schema\SiteAnalyticsTable;

/**
 * P6.2 — store/read GA4 calendar-month snapshots. upsert is delete-then-insert
 * keyed on the logical tuple (site_id, period_start, period_end), so a re-sync
 * of the same month replaces rather than duplicates.
 */
final class SiteAnalyticsRepository
{
    /** @param array{sessions:?int,users:?int,pageviews:?int,avg_engagement:?float,top_pages:array,channels:array} $data */
    public function upsertForSiteAndPeriod(int $siteId, string $periodStart, string $periodEnd, array $data, string $fetchedAt, string $now): int
    {
        global $wpdb;
        $table = SiteAnalyticsTable::tableName();
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE site_id = %d AND period_start = %s AND period_end = %s",
            $siteId, $periodStart, $periodEnd
        ));
        $wpdb->insert($table, [
            'site_id'              => $siteId,
            'period_start'         => $periodStart,
            'period_end'           => $periodEnd,
            'sessions'             => $data['sessions'] ?? null,
            'total_users'          => $data['users'] ?? null,
            'screen_page_views'    => $data['pageviews'] ?? null,
            'avg_session_duration' => $data['avg_engagement'] ?? null,
            'top_pages'            => json_encode(array_values($data['top_pages'] ?? [])),
            'channels'             => json_encode(array_values($data['channels'] ?? [])),
            'fetched_at'           => $fetchedAt,
            'created_at'           => $now,
        ]);
        return (int) $wpdb->insert_id;
    }

    public function findForSiteAndMonth(int $siteId, string $periodStart): ?SiteAnalytics
    {
        global $wpdb;
        $table = SiteAnalyticsTable::tableName();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE site_id = %d AND period_start = %s LIMIT 1",
            $siteId, $periodStart
        ), ARRAY_A);
        return $row ? SiteAnalytics::fromRow($row) : null;
    }

    public function latestForSite(int $siteId): ?SiteAnalytics
    {
        global $wpdb;
        $table = SiteAnalyticsTable::tableName();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE site_id = %d ORDER BY period_start DESC, id DESC LIMIT 1",
            $siteId
        ), ARRAY_A);
        return $row ? SiteAnalytics::fromRow($row) : null;
    }
}
```

- [ ] **Step 4: Run green** → pass; full suite tolerating only `UninstallTest`. **Step 5: Commit** `feat(p6-2): SiteAnalyticsRepository (upsert/find/latest)`.

---

## Task 4: `Services\Ga4Client`

**Files:** Create `src/Services/Ga4Client.php`; Test `tests/Integration/Services/Ga4ClientTest.php`.

The client does two HTTP calls through the injected `$http` seam: (1) token exchange at `token_uri`, (2) `batchRunReports`. The SA JSON is a second optional ctor param (defaults to the env constant) so tests can inject a JSON with a generated RSA key — no network, no constant-redefine pain.

- [ ] **Step 1: Write the failing test** (generate a throwaway RSA key so `JWT::encode` really signs; stub `$http` by URL):
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\Ga4Client;
use PHPUnit\Framework\TestCase;

final class Ga4ClientTest extends TestCase
{
    private function serviceAccountJson(): string
    {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $pem);
        return json_encode([
            'client_email' => 'svc@proj.iam.gserviceaccount.com',
            'private_key'  => $pem,
            'token_uri'    => 'https://oauth2.googleapis.com/token',
        ]);
    }

    private function batchJson(): array
    {
        return ['reports' => [
            ['rows' => [['metricValues' => [['value'=>'12480'],['value'=>'9210'],['value'=>'31540'],['value'=>'108.5']]]]],
            ['rows' => [
                ['dimensionValues'=>[['value'=>'/'],['value'=>'Home']],'metricValues'=>[['value'=>'8420']]],
                ['dimensionValues'=>[['value'=>'/services'],['value'=>'Services']],'metricValues'=>[['value'=>'5110']]],
            ]],
            ['rows' => [
                ['dimensionValues'=>[['value'=>'Organic Search']],'metricValues'=>[['value'=>'5200']]],
                ['dimensionValues'=>[['value'=>'Direct']],'metricValues'=>[['value'=>'3800']]],
            ]],
        ]];
    }

    /** @param array<string,mixed> $body */
    private function wpResponse(int $status, array $body): array
    {
        return ['response' => ['code' => $status], 'body' => json_encode($body)];
    }

    public function testFetchReportNormalizesBatch(): void
    {
        $batch = $this->batchJson();
        $http = function (string $url, array $args) use ($batch) {
            if (str_contains($url, 'oauth2.googleapis.com')) {
                return $this->wpResponse(200, ['access_token' => 'tok-123']);
            }
            return $this->wpResponse(200, $batch);
        };
        $client = new Ga4Client($http, $this->serviceAccountJson());
        $out = $client->fetchReport('123456789', '2026-06-01', '2026-06-30');

        self::assertSame(12480, $out['sessions']);
        self::assertSame(9210, $out['users']);
        self::assertSame(31540, $out['pageviews']);
        self::assertSame(108.5, $out['avg_engagement']);
        self::assertCount(2, $out['top_pages']);
        self::assertSame('Home', $out['top_pages'][0]['title']);
        self::assertSame('Organic Search', $out['channels'][0]['channel']);
        self::assertSame(5200, $out['channels'][0]['sessions']);
    }

    public function testNullWhenNoServiceAccount(): void
    {
        $client = new Ga4Client(fn () => $this->wpResponse(200, []), null);
        self::assertNull($client->fetchReport('123', '2026-06-01', '2026-06-30'));
    }

    public function testNullWhenTokenExchangeFails(): void
    {
        $http = fn (string $url) => str_contains($url, 'oauth2')
            ? $this->wpResponse(401, ['error' => 'invalid_grant'])
            : $this->wpResponse(200, $this->batchJson());
        $client = new Ga4Client($http, $this->serviceAccountJson());
        self::assertNull($client->fetchReport('123', '2026-06-01', '2026-06-30'));
    }

    public function testNullWhenBatchGarbage(): void
    {
        $http = fn (string $url) => str_contains($url, 'oauth2')
            ? $this->wpResponse(200, ['access_token' => 'tok'])
            : $this->wpResponse(200, ['nonsense' => true]);
        $client = new Ga4Client($http, $this->serviceAccountJson());
        self::assertNull($client->fetchReport('123', '2026-06-01', '2026-06-30'));
    }
}
```
Note: `wp_remote_retrieve_response_code`/`wp_remote_retrieve_body` read the `['response']['code']` / `['body']` shape returned by the stub — that's exactly what real `wp_remote_post` returns, so the stub is faithful.

- [ ] **Step 2: Run red** `composer test:integration -- --filter Ga4ClientTest` → FAIL.

- [ ] **Step 3: Create `src/Services/Ga4Client.php`:**
```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

use Firebase\JWT\JWT;

/**
 * P6.2 — GA4 Data API client. Service-account JWT-bearer auth (RS256 via
 * firebase/php-jwt — already a prod dep), then one batchRunReports call for the
 * three report requests. Best-effort: returns null on any failure (no SA env,
 * token error, non-200, malformed body) so the weekly fan-out and the report
 * never break. NOT final — tests subclass / inject the HTTP + SA-JSON seams.
 */
class Ga4Client
{
    private const SCOPE     = 'https://www.googleapis.com/auth/analytics.readonly';
    private const DATA_API  = 'https://analyticsdata.googleapis.com/v1beta';

    /** @var callable(string,array):mixed */
    private $http;
    private ?string $serviceAccountJson;

    public function __construct(?callable $http = null, ?string $serviceAccountJson = null)
    {
        $this->http = $http ?? static fn (string $url, array $args) => wp_remote_post($url, $args);
        $this->serviceAccountJson = $serviceAccountJson
            ?? (defined('DEFYN_GA4_SERVICE_ACCOUNT_JSON') && DEFYN_GA4_SERVICE_ACCOUNT_JSON !== ''
                ? (string) DEFYN_GA4_SERVICE_ACCOUNT_JSON : null);
    }

    /**
     * @return array{sessions:int,users:int,pageviews:int,avg_engagement:float,top_pages:list<array{path:string,title:string,views:int}>,channels:list<array{channel:string,sessions:int}>}|null
     */
    public function fetchReport(string $propertyId, string $fromDate, string $toDate): ?array
    {
        $token = $this->accessToken();
        if ($token === null) {
            return null;
        }
        $endpoint = self::DATA_API . '/properties/' . rawurlencode($propertyId) . ':batchRunReports';
        $payload  = [
            'requests' => [
                ['dateRanges' => [['startDate' => $fromDate, 'endDate' => $toDate]],
                 'metrics' => [['name' => 'sessions'], ['name' => 'totalUsers'], ['name' => 'screenPageViews'], ['name' => 'averageSessionDuration']]],
                ['dateRanges' => [['startDate' => $fromDate, 'endDate' => $toDate]],
                 'dimensions' => [['name' => 'pagePath'], ['name' => 'pageTitle']],
                 'metrics' => [['name' => 'screenPageViews']],
                 'orderBys' => [['metric' => ['metricName' => 'screenPageViews'], 'desc' => true]],
                 'limit' => 10],
                ['dateRanges' => [['startDate' => $fromDate, 'endDate' => $toDate]],
                 'dimensions' => [['name' => 'sessionDefaultChannelGroup']],
                 'metrics' => [['name' => 'sessions']],
                 'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
                 'limit' => 10],
            ],
        ];
        $res = ($this->http)($endpoint, [
            'timeout'     => 30,
            'redirection' => 0,
            'headers'     => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'body'        => json_encode($payload),
        ]);
        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
            return null;
        }
        $data = json_decode((string) wp_remote_retrieve_body($res), true);
        $reports = $data['reports'] ?? null;
        if (!is_array($reports) || count($reports) < 3) {
            return null;
        }

        $tot = $reports[0]['rows'][0]['metricValues'] ?? [];
        $topPages = [];
        foreach ($reports[1]['rows'] ?? [] as $r) {
            $topPages[] = [
                'path'  => (string) ($r['dimensionValues'][0]['value'] ?? ''),
                'title' => (string) ($r['dimensionValues'][1]['value'] ?? ''),
                'views' => (int) ($r['metricValues'][0]['value'] ?? 0),
            ];
        }
        $channels = [];
        foreach ($reports[2]['rows'] ?? [] as $r) {
            $channels[] = [
                'channel'  => (string) ($r['dimensionValues'][0]['value'] ?? ''),
                'sessions' => (int) ($r['metricValues'][0]['value'] ?? 0),
            ];
        }

        return [
            'sessions'       => (int) ($tot[0]['value'] ?? 0),
            'users'          => (int) ($tot[1]['value'] ?? 0),
            'pageviews'      => (int) ($tot[2]['value'] ?? 0),
            'avg_engagement' => (float) ($tot[3]['value'] ?? 0),
            'top_pages'      => $topPages,
            'channels'       => $channels,
        ];
    }

    private function accessToken(): ?string
    {
        $sa = $this->serviceAccount();
        if ($sa === null) {
            return null;
        }
        $now = time();
        try {
            $assertion = JWT::encode([
                'iss'   => $sa['client_email'],
                'scope' => self::SCOPE,
                'aud'   => $sa['token_uri'],
                'iat'   => $now,
                'exp'   => $now + 3600,
            ], $sa['private_key'], 'RS256');
        } catch (\Throwable $e) {
            return null;
        }
        $res = ($this->http)($sa['token_uri'], [
            'timeout'     => 30,
            'redirection' => 0,
            'body'        => ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion],
        ]);
        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
            return null;
        }
        $body = json_decode((string) wp_remote_retrieve_body($res), true);
        $token = $body['access_token'] ?? null;
        return is_string($token) && $token !== '' ? $token : null;
    }

    /** @return array{client_email:string,private_key:string,token_uri:string}|null */
    private function serviceAccount(): ?array
    {
        if ($this->serviceAccountJson === null) {
            return null;
        }
        $sa = json_decode($this->serviceAccountJson, true);
        if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) {
            return null;
        }
        return [
            'client_email' => (string) $sa['client_email'],
            'private_key'  => (string) $sa['private_key'],
            'token_uri'    => isset($sa['token_uri']) && $sa['token_uri'] !== '' ? (string) $sa['token_uri'] : 'https://oauth2.googleapis.com/token',
        ];
    }
}
```

- [ ] **Step 4: Run green** → all 4 pass. **Step 5: Commit** `feat(p6-2): Ga4Client (service-account JWT + batchRunReports, best-effort)`.

---

## Task 5: `DEFYN_GA4_SERVICE_ACCOUNT_JSON` env→define bootstrap

**Files:** Modify `defyn-dashboard.php`.

- [ ] **Step 1:** After the `DEFYN_PAGESPEED_API_KEY` block (~line 84), add:
```php
// Google Analytics 4 service-account key (P6.2): the JSON key for an agency
// service account granted Viewer on the operator's GA4 properties. Optional —
// when absent, Ga4Client cleanly returns null so analytics stays inert-but-safe.
// NEVER logged.
if (!defined('DEFYN_GA4_SERVICE_ACCOUNT_JSON')) {
    $envGa4 = getenv('DEFYN_GA4_SERVICE_ACCOUNT_JSON');
    if ($envGa4 !== false && $envGa4 !== '') {
        define('DEFYN_GA4_SERVICE_ACCOUNT_JSON', $envGa4);
    }
}
```

- [ ] **Step 2:** Run the full PHP suite (no new test — bootstrap only; existing suite must stay green tolerating only `UninstallTest`). **Step 3: Commit** `feat(p6-2): DEFYN_GA4_SERVICE_ACCOUNT_JSON env bootstrap`.

---

## Task 6: `Services\AnalyticsScanService` (best-effort, never throws)

> **EXECUTION ORDER: run Task 9 (Site model `ga4PropertyId`) BEFORE this task** — `AnalyticsScanService` reads `$site->ga4PropertyId`.

**Files:** Create `src/Services/AnalyticsScanService.php`; Test `tests/Integration/Services/AnalyticsScanServiceTest.php`.

- [ ] **Step 1: Write the failing test** (a fake Ga4Client subclass returns canned data or null):
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\AnalyticsScanService;
use Defyn\Dashboard\Services\Ga4Client;
use Defyn\Dashboard\Services\SiteAnalyticsRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class AnalyticsScanServiceTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb->query('SET autocommit = 1');
        foreach (['defyn_site_analytics','defyn_sites','defyn_activity_log'] as $t) {
            $wpdb->query('DELETE FROM ' . $wpdb->prefix . $t);
        }
    }

    private function seedSite(?string $propertyId): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix.'defyn_sites', [
            'user_id'=>1,'url'=>'https://acme.test','label'=>'Acme','status'=>'active','wp_version'=>'6.9.4',
            'ga4_property_id'=>$propertyId,
            'created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $wpdb->insert_id;
    }

    public function testSkipsWhenNoProperty(): void
    {
        $siteId = $this->seedSite(null);
        (new AnalyticsScanService())->scan($siteId, $this->client(['sessions'=>1,'users'=>1,'pageviews'=>1,'avg_engagement'=>1.0,'top_pages'=>[],'channels'=>[]]));
        self::assertNull((new SiteAnalyticsRepository())->latestForSite($siteId));
    }

    public function testStoresCurrentAndPreviousMonth(): void
    {
        $siteId = $this->seedSite('123456789');
        (new AnalyticsScanService())->scan($siteId, $this->client(['sessions'=>500,'users'=>400,'pageviews'=>1200,'avg_engagement'=>90.0,'top_pages'=>[],'channels'=>[]]));
        global $wpdb;
        $count = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'defyn_site_analytics WHERE site_id = ' . $siteId);
        self::assertSame(2, $count); // current + previous month
    }

    public function testNeverThrowsWhenClientReturnsNull(): void
    {
        $siteId = $this->seedSite('123456789');
        (new AnalyticsScanService())->scan($siteId, $this->client(null));
        self::assertNull((new SiteAnalyticsRepository())->latestForSite($siteId)); // no rows, no exception
    }

    private function client(?array $ret): Ga4Client
    {
        return new class($ret) extends Ga4Client {
            public function __construct(private readonly ?array $ret) { parent::__construct(fn () => null, 'x'); }
            public function fetchReport(string $p, string $f, string $t): ?array { return $this->ret; }
        };
    }
}
```

- [ ] **Step 2: Run red** → FAIL. **Step 3: Create `src/Services/AnalyticsScanService.php`:**
```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

/**
 * P6.2 — per-site GA4 fetch orchestrator. Resolves a site, fetches the current
 * + previous calendar month via Ga4Client, upserts each month's snapshot, then
 * emits ONE `site.analytics_synced` activity event. Best-effort (guardrail #2):
 * a missing site / empty property ID is a skip; a null fetch for a month simply
 * stores nothing for that month; NEVER throws into the weekly fan-out. Mirrors
 * PerformanceScanService.
 */
final class AnalyticsScanService
{
    public function __construct(
        private readonly ?SiteAnalyticsRepository $repo = null,
        private readonly ?SitesRepository $sites = null,
    ) {
    }

    public function scan(int $siteId, ?Ga4Client $client = null): void
    {
        $site = ($this->sites ?? new SitesRepository())->findById($siteId);
        if ($site === null) {
            return;
        }
        $propertyId = $site->ga4PropertyId;
        if ($propertyId === null || $propertyId === '') {
            return; // not connected — skip, no row, no event
        }

        $client = $client ?? new Ga4Client();
        $repo   = $this->repo ?? new SiteAnalyticsRepository();
        $now    = gmdate('Y-m-d H:i:s');
        $synced = 0;

        foreach (self::monthBounds(time()) as [$start, $end]) {
            $data = $client->fetchReport($propertyId, $start, $end);
            if ($data === null) {
                continue;
            }
            $repo->upsertForSiteAndPeriod($siteId, $start, $end, $data, $now, $now);
            $synced++;
        }

        if ($synced > 0) {
            (new ActivityLogger())->log($site->userId, $siteId, 'site.analytics_synced', ['months_synced' => $synced]);
        }
    }

    /**
     * Current and previous calendar month, as [start(Y-m-01), end(Y-m-t)] pairs (UTC).
     * @return list<array{0:string,1:string}>
     */
    public static function monthBounds(int $nowTs): array
    {
        $curStart = gmdate('Y-m-01', $nowTs);
        $curEnd   = gmdate('Y-m-t', $nowTs);
        $prevTs   = strtotime($curStart . ' -1 day UTC'); // any day in the previous month
        $prevStart = gmdate('Y-m-01', $prevTs);
        $prevEnd   = gmdate('Y-m-t', $prevTs);
        return [[$curStart, $curEnd], [$prevStart, $prevEnd]];
    }
}
```

- [ ] **Step 4: Run green** → pass. **Step 5: Commit** `feat(p6-2): AnalyticsScanService (current+prev month, best-effort)`.

---

## Task 7: Jobs + Scheduler + Plugin wiring + self-heal guard

**Files:** Create `src/Jobs/AnalyticsSync.php` + `src/Jobs/AnalyticsSyncAll.php`; Modify `src/Jobs/Scheduler.php`, `src/Plugin.php`, `src/Activation.php`; Test `tests/Integration/Jobs/AnalyticsSyncAllTest.php`.

- [ ] **Step 1: Write the failing fan-out test** (mirror how `PerformanceScanAllTest` asserts via `as_next_scheduled_action`):
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Jobs;

use Defyn\Dashboard\Jobs\AnalyticsSync;
use Defyn\Dashboard\Jobs\AnalyticsSyncAll;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class AnalyticsSyncAllTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb->query('SET autocommit = 1');
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'defyn_sites');
    }

    public function testFansOutOnePerSchedulableSite(): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix.'defyn_sites', [
            'user_id'=>1,'url'=>'https://a.test','label'=>'A','status'=>'active','wp_version'=>'6.9',
            'created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s'),
        ]);
        $siteId = (int) $wpdb->insert_id;

        (new AnalyticsSyncAll())->handle();

        self::assertNotFalse(as_next_scheduled_action(AnalyticsSync::HOOK, [$siteId], 'defyn'));
    }
}
```

- [ ] **Step 2: Run red** → FAIL. **Step 3: Create both jobs:**

`src/Jobs/AnalyticsSync.php`:
```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Services\AnalyticsScanService;

/** P6.2 — AS leaf for `defyn_analytics_sync`. Thin delegate. Mirrors PerformanceScan. */
final class AnalyticsSync
{
    public const HOOK = 'defyn_analytics_sync';

    public function __construct(private readonly ?AnalyticsScanService $service = null)
    {
    }

    public function handle(int $siteId): void
    {
        ($this->service ?? new AnalyticsScanService())->scan($siteId);
    }
}
```

`src/Jobs/AnalyticsSyncAll.php`:
```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Services\SitesRepository;

/**
 * P6.2 — weekly recurring fan-out: enqueue one `defyn_analytics_sync` per
 * schedulable site. SYSTEM cron → findAllSchedulable() is correct (whole fleet,
 * NOT a per-operator endpoint — do not "fix" to findAllForUser). Mirrors
 * PerformanceScanAll.
 */
final class AnalyticsSyncAll
{
    public const HOOK = 'defyn_analytics_sync_all';

    public function __construct(private readonly ?SitesRepository $repo = null)
    {
    }

    public function handle(): void
    {
        if (!function_exists('as_schedule_single_action')) {
            return;
        }
        foreach (($this->repo ?? new SitesRepository())->findAllSchedulable() as $siteId) {
            as_schedule_single_action(time(), AnalyticsSync::HOOK, [$siteId], 'defyn');
        }
    }
}
```

- [ ] **Step 4: Edit `src/Jobs/Scheduler.php`** — add to `SCHEDULES`:
```php
        AnalyticsSyncAll::HOOK => WEEK_IN_SECONDS, // 7 days
```
(add `use Defyn\Dashboard\Jobs\AnalyticsSyncAll;` if the file uses imports, or reference as already-namespaced — match the file's existing style; the other `*All::HOOK` entries show the convention).

- [ ] **Step 5: Edit `src/Plugin.php`** — in `boot()`, beside the `PerformanceScan`/`PerformanceScanAll` `add_action` lines:
```php
        add_action(\Defyn\Dashboard\Jobs\AnalyticsSync::HOOK, [new \Defyn\Dashboard\Jobs\AnalyticsSync(), 'handle']);
        add_action(\Defyn\Dashboard\Jobs\AnalyticsSyncAll::HOOK, [new \Defyn\Dashboard\Jobs\AnalyticsSyncAll(), 'handle']);
```
(match the exact registration form used for the performance hooks).

- [ ] **Step 6: Edit `src/Activation.php`** — add the 5th ensure-scheduled guard in `maybeRunSelfHeal()` after the PerformanceScanAll one:
```php
        // P6.2 — ensure the weekly analytics-sync schedule exists on a silent upgrade.
        if (function_exists('as_next_scheduled_action')
            && as_next_scheduled_action(\Defyn\Dashboard\Jobs\AnalyticsSyncAll::HOOK, [], 'defyn') === false) {
            \Defyn\Dashboard\Jobs\Scheduler::installRecurringSchedules();
        }
```

- [ ] **Step 7: Run green** `composer test:integration -- --filter AnalyticsSyncAll` → pass; full suite tolerating only `UninstallTest` (confirm NO bootstrap fatal — the self-heal guard references `AnalyticsSyncAll` which now exists). **Step 8: Commit** `feat(p6-2): AnalyticsSync + AnalyticsSyncAll weekly fan-out + self-heal guard`.

---

## Task 8: `Services\EngagementFormat` pure helper

**Files:** Create `src/Services/EngagementFormat.php`; Test `tests/Unit/Services/EngagementFormatTest.php`.

- [ ] **Step 1: Write the failing test:**
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Unit\Services;

use Defyn\Dashboard\Services\EngagementFormat;
use PHPUnit\Framework\TestCase;

final class EngagementFormatTest extends TestCase
{
    /** @dataProvider cases */
    public function testFormat(float $seconds, string $expected): void
    {
        self::assertSame($expected, EngagementFormat::format($seconds));
    }

    public static function cases(): array
    {
        return [
            '1m 48s'  => [108.0, '1m 48s'],
            '0m 42s'  => [42.0, '0m 42s'],
            '2m 0s'   => [120.0, '2m 0s'],
            'rounds'  => [108.7, '1m 48s'],
            'zero'    => [0.0, '0m 0s'],
        ];
    }
}
```

- [ ] **Step 2: Run red** → FAIL. **Step 3: Create `src/Services/EngagementFormat.php`:**
```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

/** P6.2 — pure: average-engagement seconds → "Xm Ys". Mirrored by the TS helper. */
final class EngagementFormat
{
    public static function format(float $seconds): string
    {
        $total = (int) floor($seconds);
        return intdiv($total, 60) . 'm ' . ($total % 60) . 's';
    }
}
```

- [ ] **Step 4: Run green** → pass. **Step 5: Commit** `feat(p6-2): EngagementFormat helper`.

---

## Task 9: `Site` model + `SitesRepository::setGa4PropertyId` + hydration

> **EXECUTION ORDER: run this task BEFORE Task 6.** AnalyticsScanService reads `$site->ga4PropertyId`.

**Files:** Modify `src/Models/Site.php`, `src/Services/SitesRepository.php`; Test `tests/Integration/Services/SitesRepositoryGa4Test.php`.

- [ ] **Step 1: Write the failing test:**
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SitesRepositoryGa4Test extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb->query('SET autocommit = 1');
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'defyn_sites');
    }

    public function testSetAndReadGa4PropertyId(): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix.'defyn_sites', [
            'user_id'=>1,'url'=>'https://a.test','label'=>'A','status'=>'active','wp_version'=>'6.9',
            'created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s'),
        ]);
        $siteId = (int) $wpdb->insert_id;
        $repo = new SitesRepository();

        self::assertNull($repo->findById($siteId)->ga4PropertyId);
        $repo->setGa4PropertyId($siteId, '123456789');
        self::assertSame('123456789', $repo->findById($siteId)->ga4PropertyId);
        self::assertSame('123456789', $repo->findById($siteId)->toJson()['ga4_property_id']);
        $repo->setGa4PropertyId($siteId, null);
        self::assertNull($repo->findById($siteId)->ga4PropertyId);
    }
}
```

- [ ] **Step 2: Run red** → FAIL. **Step 3: Edit `src/Models/Site.php`:**
  - Add a readonly ctor prop (after `clientEmail`):
```php
        // P6.2 — numeric GA4 property ID for the analytics report section.
        public readonly ?string $ga4PropertyId = null,
```
  - In `fromRow()`, after the `clientEmail` mapping:
```php
            ga4PropertyId: isset($row['ga4_property_id']) && $row['ga4_property_id'] !== null ? (string) $row['ga4_property_id'] : null,
```
  - In `toJson()`, after `'client_email'`:
```php
            'ga4_property_id'             => $this->ga4PropertyId,
```

- [ ] **Step 4: Edit `src/Services/SitesRepository.php`** — add (beside `setClientEmail`):
```php
    public function setGa4PropertyId(int $siteId, ?string $propertyId): void
    {
        global $wpdb;
        $wpdb->update(SitesTable::tableName(), ['ga4_property_id' => $propertyId], ['id' => $siteId]);
    }
```

- [ ] **Step 5: Run green** → pass; full suite tolerating only `UninstallTest`. **Step 6: Commit** `feat(p6-2): Site.ga4PropertyId + SitesRepository::setGa4PropertyId`.

---

## Task 10: `ReportService::compose` — `analytics` key

**Files:** Modify `src/Services/ReportService.php`; Test append to `tests/Integration/Services/ReportServiceTest.php`.

- [ ] **Step 1: Append failing tests** (the file's `seedSite(int $userId, string $url, string $label, string $wpVersion): int` does NOT set `ga4_property_id`; set it via `SitesRepository::setGa4PropertyId` after seeding; add `defyn_site_analytics` to the setUp purge list):
```php
    public function testAnalyticsNotConnectedWhenNoProperty(): void
    {
        $siteId = $this->seedSite(1, 'https://acme.test', 'Acme', '6.9.4');
        $report = (new \Defyn\Dashboard\Services\ReportService())->compose($siteId, 1, '2026-06-01 00:00:00', '2026-06-30 23:59:59');
        self::assertSame('not_connected', $report['analytics']['state']);
        self::assertNull($report['analytics']['totals']);
    }

    public function testAnalyticsReadyWhenMonthSnapshotExists(): void
    {
        $siteId = $this->seedSite(1, 'https://acme.test', 'Acme', '6.9.4');
        (new \Defyn\Dashboard\Services\SitesRepository())->setGa4PropertyId($siteId, '123456789');
        (new \Defyn\Dashboard\Services\SiteAnalyticsRepository())->upsertForSiteAndPeriod(
            $siteId, '2026-06-01', '2026-06-30',
            ['sessions'=>12480,'users'=>9210,'pageviews'=>31540,'avg_engagement'=>108.5,
             'top_pages'=>[['path'=>'/','title'=>'Home','views'=>8420]],
             'channels'=>[['channel'=>'Organic Search','sessions'=>5200]]],
            '2026-06-30 03:00:00', '2026-06-30 03:00:00'
        );
        $report = (new \Defyn\Dashboard\Services\ReportService())->compose($siteId, 1, '2026-06-01 00:00:00', '2026-06-30 23:59:59');
        self::assertSame('ready', $report['analytics']['state']);
        self::assertSame(12480, $report['analytics']['totals']['sessions']);
        self::assertSame('Home', $report['analytics']['top_pages'][0]['title']);
        self::assertSame('2026-06-01', $report['analytics']['period']['start']);
    }

    public function testAnalyticsPendingWhenConnectedButNoSnapshot(): void
    {
        $siteId = $this->seedSite(1, 'https://acme.test', 'Acme', '6.9.4');
        (new \Defyn\Dashboard\Services\SitesRepository())->setGa4PropertyId($siteId, '123456789');
        $report = (new \Defyn\Dashboard\Services\ReportService())->compose($siteId, 1, '2026-06-01 00:00:00', '2026-06-30 23:59:59');
        self::assertSame('pending', $report['analytics']['state']);
    }

    public function testAnalyticsPendingWhenRangeNotCalendarMonth(): void
    {
        $siteId = $this->seedSite(1, 'https://acme.test', 'Acme', '6.9.4');
        (new \Defyn\Dashboard\Services\SitesRepository())->setGa4PropertyId($siteId, '123456789');
        (new \Defyn\Dashboard\Services\SiteAnalyticsRepository())->upsertForSiteAndPeriod(
            $siteId, '2026-06-01', '2026-06-30',
            ['sessions'=>1,'users'=>1,'pageviews'=>1,'avg_engagement'=>1.0,'top_pages'=>[],'channels'=>[]],
            '2026-06-30 03:00:00', '2026-06-30 03:00:00'
        );
        $report = (new \Defyn\Dashboard\Services\ReportService())->compose($siteId, 1, '2026-06-10 00:00:00', '2026-06-20 23:59:59');
        self::assertSame('pending', $report['analytics']['state']);
    }
```
Also add `'defyn_site_analytics'` to the file's `setUp` purge array.

- [ ] **Step 2: Run red** → FAIL. **Step 3: Edit `src/Services/ReportService.php`:**
  - Add the 8th ctor param after `?SitePerformanceRepository $performance = null,`:
```php
        private readonly ?SiteAnalyticsRepository $analytics = null,
```
  - In `compose()`, the Site is already loaded (it builds `'site' => [...]`). Capture/locate that loaded `$site` variable. After `$performance = $this->buildPerformance(...);` add:
```php
        $analytics = $this->buildAnalytics($site, $fromUtc, $toUtc);
```
  (If compose does NOT keep the loaded Site in a variable, add `$site = ($this->sites ?? new SitesRepository())->findById($siteId);` near the top reuse — verify; it almost certainly already loads it.)
  - Add `'analytics' => $analytics,` to the returned array (after `'performance' => $performance,`).
  - Add the builder (mirrors `buildPerformance` but state-machine; reads ONLY cached snapshots):
```php
    /**
     * P6.2 — GA4 analytics for the report's calendar month. Reads ONLY cached
     * snapshots (never calls GA4 — no-sync-fetch guardrail). States:
     *   not_connected — site has no ga4_property_id
     *   pending       — connected but no month-snapshot, or the range isn't a clean calendar month
     *   ready         — snapshot found for the report's calendar month
     * @return array<string,mixed>
     */
    private function buildAnalytics(\Defyn\Dashboard\Models\Site $site, string $fromUtc, string $toUtc): array
    {
        $empty = ['period' => null, 'totals' => null, 'top_pages' => [], 'channels' => []];

        if ($site->ga4PropertyId === null || $site->ga4PropertyId === '') {
            return ['state' => 'not_connected'] + $empty;
        }

        $fromDate = substr($fromUtc, 0, 10);
        $toDate   = substr($toUtc, 0, 10);
        $monthStart = substr($fromDate, 0, 7) . '-01';
        $monthEnd   = gmdate('Y-m-t', strtotime($monthStart . ' UTC'));
        if ($fromDate !== $monthStart || $toDate !== $monthEnd) {
            return ['state' => 'pending'] + $empty;
        }

        $snap = ($this->analytics ?? new SiteAnalyticsRepository())->findForSiteAndMonth($site->id, $monthStart);
        if ($snap === null) {
            return ['state' => 'pending'] + $empty;
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
        ];
    }
```
  - No `use` needed for `SiteAnalyticsRepository` (same namespace `Defyn\Dashboard\Services`). `Models\Site` is referenced fully-qualified in the signature.

- [ ] **Step 4: Run green** `composer test:integration -- --filter ReportServiceTest` → all pass (existing + 4 new). Full suite tolerating only `UninstallTest`. **Step 5: Commit** `feat(p6-2): ReportService.compose adds analytics (not_connected/pending/ready)`.

---

## Task 11: `ReportPdfService` Analytics section

**Files:** Modify `src/Services/ReportPdfService.php`; Test append to `tests/Integration/Services/ReportPdfServiceTest.php`.

- [ ] **Step 1: Append failing tests** (the `sampleReport()` fixture lacks `analytics` → must render "not connected" without error):
```php
    public function testRendersAnalyticsReadySection(): void
    {
        $report = $this->sampleReport();
        $report['analytics'] = [
            'state' => 'ready',
            'period' => ['start' => '2026-06-01', 'end' => '2026-06-30'],
            'totals' => ['sessions' => 12480, 'users' => 9210, 'pageviews' => 31540, 'avg_engagement_seconds' => 108.5],
            'top_pages' => [['path' => '/', 'title' => 'Home', 'views' => 8420]],
            'channels'  => [['channel' => 'Organic Search', 'sessions' => 5200]],
        ];
        $html = (new ReportPdfService(static fn ($u): ?string => null))->debugHtml($report, $this->branding());
        self::assertStringContainsString('Analytics', $html);
        self::assertStringContainsString('12,480', $html);   // formatted session count
        self::assertStringContainsString('1m 48s', $html);   // avg engagement
        self::assertStringContainsString('Organic Search', $html);
    }

    public function testRendersAnalyticsNotConnectedWhenKeyMissing(): void
    {
        $report = $this->sampleReport(); // no 'analytics' key
        $html = (new ReportPdfService(static fn ($u): ?string => null))->debugHtml($report, $this->branding());
        self::assertStringContainsString('Analytics not connected', $html);
    }

    public function testEscapesAnalyticsStrings(): void
    {
        $report = $this->sampleReport();
        $report['analytics'] = [
            'state' => 'ready', 'period' => ['start' => '2026-06-01', 'end' => '2026-06-30'],
            'totals' => ['sessions' => 1, 'users' => 1, 'pageviews' => 1, 'avg_engagement_seconds' => 1.0],
            'top_pages' => [['path' => '/x', 'title' => '<script>bad</script>', 'views' => 1]],
            'channels'  => [['channel' => 'Direct', 'sessions' => 1]],
        ];
        $html = (new ReportPdfService(static fn ($u): ?string => null))->debugHtml($report, $this->branding());
        self::assertStringNotContainsString('<script>bad</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }
```

- [ ] **Step 2: Run red** → FAIL. **Step 3: Edit `src/Services/ReportPdfService.php`:**
  - In `buildHtml()`, add `$analytics = $this->analyticsHtml($report);` and place `{$analytics}` right after `{$performance}` in the template.
  - Add the section method (mirror `performanceHtml`; format sessions/users/pageviews with `number_format`, avg via `EngagementFormat::format`, every value `esc()`'d):
```php
    /** @param array<string,mixed> $report */
    private function analyticsHtml(array $report): string
    {
        $a     = $report['analytics'] ?? ['state' => 'not_connected'];
        $state = $a['state'] ?? 'not_connected';
        if ($state === 'not_connected') {
            return $this->sectionWithBody('Analytics', '<p class="muted">Analytics not connected.</p>');
        }
        if ($state !== 'ready') {
            return $this->sectionWithBody('Analytics', '<p class="muted">Analytics not yet available for this period.</p>');
        }

        $t = $a['totals'] ?? [];
        $avg = \Defyn\Dashboard\Services\EngagementFormat::format((float) ($t['avg_engagement_seconds'] ?? 0));
        $kpis = '<table class="stats"><tr>'
            . '<td><div class="stat-num">' . $this->esc(number_format((int) ($t['sessions'] ?? 0)))  . '</div><div class="stat-label">Sessions</div></td>'
            . '<td><div class="stat-num">' . $this->esc(number_format((int) ($t['users'] ?? 0)))     . '</div><div class="stat-label">Users</div></td>'
            . '<td><div class="stat-num">' . $this->esc(number_format((int) ($t['pageviews'] ?? 0))) . '</div><div class="stat-label">Pageviews</div></td>'
            . '<td><div class="stat-num">' . $this->esc($avg) . '</div><div class="stat-label">Avg engaged</div></td>'
            . '</tr></table>';

        $pageRows = '';
        foreach (($a['top_pages'] ?? []) as $p) {
            $path  = $this->esc((string) ($p['path'] ?? ''));
            $title = $this->esc((string) ($p['title'] ?? ''));
            $views = $this->esc(number_format((int) ($p['views'] ?? 0)));
            $pageRows .= "<tr><td>{$path} <span style=\"color:#999\">{$title}</span></td><td>{$views}</td></tr>";
        }
        $topPages = $pageRows === '' ? '' :
            '<table class="data"><thead><tr><th>Top pages</th><th>Views</th></tr></thead><tbody>' . $pageRows . '</tbody></table>';

        $chanRows = '';
        foreach (($a['channels'] ?? []) as $c) {
            $chan = $this->esc((string) ($c['channel'] ?? ''));
            $sess = $this->esc(number_format((int) ($c['sessions'] ?? 0)));
            $chanRows .= "<tr><td>{$chan}</td><td>{$sess}</td></tr>";
        }
        $channels = $chanRows === '' ? '' :
            '<table class="data"><thead><tr><th>Traffic channels</th><th>Sessions</th></tr></thead><tbody>' . $chanRows . '</tbody></table>';

        $period = $this->esc((string) ($a['period']['start'] ?? '') . ' – ' . (string) ($a['period']['end'] ?? ''));
        $body = '<p class="muted">Google Analytics 4 &middot; ' . $period . '</p>' . $kpis . $topPages . $channels;
        return $this->sectionWithBody('Analytics', $body);
    }
```

- [ ] **Step 4: Run green** `composer test:integration -- --filter ReportPdfServiceTest` → all pass (existing + 3 new). Full suite tolerating only `UninstallTest`. **Step 5: Commit** `feat(p6-2): ReportPdfService Analytics section (KPI strip + top pages + channels)`.

---

## Task 12: RateLimit buckets

**Files:** Modify `src/Rest/Middleware/RateLimit.php`; Test `tests/Integration/Rest/RateLimitAnalyticsTest.php`.

- [ ] **Step 1: Write the failing test** (model on `RateLimitReportsTest`/`RateLimitPerformanceTest`: real JWT via `TokenService::issueAccess`, `set_url_params(['id'=>…])`, flush the 3 new transient prefixes in setUp):
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Rest\Middleware\RateLimit;
use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;

final class RateLimitAnalyticsTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
        global $wpdb;
        foreach (['analyticsRead','analyticsRefresh','ga4Property'] as $b) {
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_defyn_rl_{$b}_%'");
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_defyn_rl_{$b}_%'");
        }
        wp_cache_flush();
        do_action('rest_api_init');
    }

    private function req(string $jwt, int $siteId): WP_REST_Request
    {
        $r = new WP_REST_Request('GET', '/defyn/v1/sites/1/analytics');
        $r->set_header('Authorization', 'Bearer ' . $jwt);
        $r->set_url_params(['id' => (string) $siteId]);
        return $r;
    }

    public function testAnalyticsReadAllows30Then429(): void
    {
        $uid = self::factory()->user->create();
        $jwt = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($uid);
        for ($i = 1; $i <= 30; $i++) {
            self::assertTrue(RateLimit::analyticsRead($this->req($jwt, 91)));
        }
        $res = RateLimit::analyticsRead($this->req($jwt, 91));
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame('analytics.rate_limited', $res->get_error_code());
        self::assertSame(429, $res->get_error_data()['status']);
    }

    public function testAnalyticsRefreshAllows6Then429(): void
    {
        $uid = self::factory()->user->create();
        $jwt = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($uid);
        for ($i = 1; $i <= 6; $i++) {
            self::assertTrue(RateLimit::analyticsRefresh($this->req($jwt, 92)));
        }
        $res = RateLimit::analyticsRefresh($this->req($jwt, 92));
        self::assertSame('analytics.rate_limited', $res->get_error_code());
    }

    public function testGa4PropertyAllows10Then429(): void
    {
        $uid = self::factory()->user->create();
        $jwt = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($uid);
        for ($i = 1; $i <= 10; $i++) {
            self::assertTrue(RateLimit::ga4Property($this->req($jwt, 93)));
        }
        $res = RateLimit::ga4Property($this->req($jwt, 93));
        self::assertSame('sites.rate_limited', $res->get_error_code());
    }

    public function testAnalyticsReadMissingAuth401(): void
    {
        $r = new WP_REST_Request('GET', '/defyn/v1/sites/91/analytics');
        $r->set_url_params(['id' => '91']);
        $res = RateLimit::analyticsRead($r);
        self::assertSame('auth.missing_token', $res->get_error_code());
        self::assertSame(401, $res->get_error_data()['status']);
    }
}
```

- [ ] **Step 2: Run red** → FAIL. **Step 3: Add to `src/Rest/Middleware/RateLimit.php`** — 3 const pairs (near the performance ones):
```php
    // P6.2 — read latest analytics snapshot. Per-MINUTE. Key: defyn_rl_analyticsRead_%d_%d.
    public const ANALYTICS_READ_LIMIT  = 30;
    public const ANALYTICS_READ_WINDOW = MINUTE_IN_SECONDS;

    // P6.2 — on-demand analytics refresh (enqueues a GA4 fetch). Per-HOUR. Key: defyn_rl_analyticsRefresh_%d_%d.
    public const ANALYTICS_REFRESH_LIMIT  = 6;
    public const ANALYTICS_REFRESH_WINDOW = HOUR_IN_SECONDS;

    // P6.2 — set the GA4 property ID. Per-HOUR write, mirrors clientEmail. Key: defyn_rl_ga4Property_%d_%d.
    public const GA4_PROPERTY_LIMIT  = 10;
    public const GA4_PROPERTY_WINDOW = HOUR_IN_SECONDS;
```
  and 3 methods (copy `performanceRead`'s body exactly, swap key/const/message; `analyticsRead`+`analyticsRefresh` → code `analytics.rate_limited`, `ga4Property` → `sites.rate_limited`):
```php
    public static function analyticsRead(WP_REST_Request $request)
    {
        $authResult = RequireAuth::check($request);
        if (is_wp_error($authResult)) { return $authResult; }
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request['id'];
        $key = sprintf('defyn_rl_analyticsRead_%d_%d', $userId, $siteId);
        $count = (int) (get_transient($key) ?: 0);
        if ($count >= self::ANALYTICS_READ_LIMIT) {
            return new \WP_Error('analytics.rate_limited', 'Too many requests. Try again shortly.', ['status' => 429]);
        }
        set_transient($key, $count + 1, self::ANALYTICS_READ_WINDOW);
        return true;
    }

    public static function analyticsRefresh(WP_REST_Request $request)
    {
        $authResult = RequireAuth::check($request);
        if (is_wp_error($authResult)) { return $authResult; }
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request['id'];
        $key = sprintf('defyn_rl_analyticsRefresh_%d_%d', $userId, $siteId);
        $count = (int) (get_transient($key) ?: 0);
        if ($count >= self::ANALYTICS_REFRESH_LIMIT) {
            return new \WP_Error('analytics.rate_limited', 'Refresh requested too often. Try again later.', ['status' => 429]);
        }
        set_transient($key, $count + 1, self::ANALYTICS_REFRESH_WINDOW);
        return true;
    }

    public static function ga4Property(WP_REST_Request $request)
    {
        $authResult = RequireAuth::check($request);
        if (is_wp_error($authResult)) { return $authResult; }
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request['id'];
        $key = sprintf('defyn_rl_ga4Property_%d_%d', $userId, $siteId);
        $count = (int) (get_transient($key) ?: 0);
        if ($count >= self::GA4_PROPERTY_LIMIT) {
            return new \WP_Error('sites.rate_limited', 'Too many updates. Try again in an hour.', ['status' => 429]);
        }
        set_transient($key, $count + 1, self::GA4_PROPERTY_WINDOW);
        return true;
    }
```

- [ ] **Step 4: Run green** → pass. **Step 5: Commit** `feat(p6-2): RateLimit buckets analyticsRead/analyticsRefresh/ga4Property`.

---

## Task 13: Analytics REST endpoints + routes + CORS

**Files:** Create `src/Rest/SitesAnalyticsController.php`, `src/Rest/SitesGa4PropertyController.php`, `src/Rest/SitesAnalyticsRefreshController.php`; Modify `src/Rest/RestRouter.php`; Test `tests/Integration/Rest/SitesAnalyticsTest.php` + `tests/Integration/Rest/AnalyticsCorsTest.php`.

- [ ] **Step 1: Write the failing functional test** `tests/Integration/Rest/SitesAnalyticsTest.php` (controllers direct; setUp purge `defyn_site_analytics`+`defyn_sites`; seedSite with `user_id=1`):
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Services\SiteAnalyticsRepository;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SitesAnalyticsTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb->query('SET autocommit = 1');
        foreach (['defyn_site_analytics','defyn_sites'] as $t) {
            $wpdb->query('DELETE FROM ' . $wpdb->prefix . $t);
        }
    }

    private function seedSite(): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix.'defyn_sites', [
            'user_id'=>1,'url'=>'https://acme.test','label'=>'Acme','status'=>'active','wp_version'=>'6.9.4',
            'created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $wpdb->insert_id;
    }

    private function req(string $method, int $userId, int $siteId): \WP_REST_Request
    {
        $r = new \WP_REST_Request($method, '/x');
        $r->set_param('_authenticated_user_id', $userId);
        $r->set_param('id', $siteId);
        return $r;
    }

    public function testGetNonOwned404(): void
    {
        $res = (new \Defyn\Dashboard\Rest\SitesAnalyticsController())->handle($this->req('GET', 1, 999999));
        self::assertSame(404, $res->get_status());
        self::assertSame('sites.not_found', $res->get_data()['error']['code']);
    }

    public function testGetLatestNullAndPropertyWhenNone(): void
    {
        $siteId = $this->seedSite();
        $res = (new \Defyn\Dashboard\Rest\SitesAnalyticsController())->handle($this->req('GET', 1, $siteId));
        self::assertSame(200, $res->get_status());
        self::assertNull($res->get_data()['data']['latest']);
        self::assertNull($res->get_data()['data']['ga4_property_id']);
    }

    public function testGetReturnsSnapshotAndProperty(): void
    {
        $siteId = $this->seedSite();
        (new SitesRepository())->setGa4PropertyId($siteId, '123456789');
        (new SiteAnalyticsRepository())->upsertForSiteAndPeriod($siteId, '2026-06-01', '2026-06-30',
            ['sessions'=>12480,'users'=>9210,'pageviews'=>31540,'avg_engagement'=>108.5,'top_pages'=>[],'channels'=>[]],
            '2026-06-30 03:00:00', '2026-06-30 03:00:00');
        $res = (new \Defyn\Dashboard\Rest\SitesAnalyticsController())->handle($this->req('GET', 1, $siteId));
        self::assertSame(12480, $res->get_data()['data']['latest']['sessions']);
        self::assertSame('123456789', $res->get_data()['data']['ga4_property_id']);
    }

    public function testSetPropertyValidatesAndStores(): void
    {
        $siteId = $this->seedSite();
        $req = $this->req('POST', 1, $siteId);
        $req->set_body(json_encode(['ga4_property_id' => '123456789']));
        $req->set_header('Content-Type', 'application/json');
        $res = (new \Defyn\Dashboard\Rest\SitesGa4PropertyController())->handle($req);
        self::assertSame(200, $res->get_status());
        self::assertSame('123456789', $res->get_data()['data']['ga4_property_id']);
        self::assertSame('123456789', (new SitesRepository())->findById($siteId)->ga4PropertyId);
    }

    public function testSetPropertyRejectsNonNumeric(): void
    {
        $siteId = $this->seedSite();
        $req = $this->req('POST', 1, $siteId);
        $req->set_body(json_encode(['ga4_property_id' => 'G-ABC123']));
        $req->set_header('Content-Type', 'application/json');
        $res = (new \Defyn\Dashboard\Rest\SitesGa4PropertyController())->handle($req);
        self::assertSame(400, $res->get_status());
        self::assertSame('analytics.invalid_property_id', $res->get_data()['error']['code']);
    }

    public function testSetPropertyEmptyClears(): void
    {
        $siteId = $this->seedSite();
        (new SitesRepository())->setGa4PropertyId($siteId, '123');
        $req = $this->req('POST', 1, $siteId);
        $req->set_body(json_encode(['ga4_property_id' => '']));
        $req->set_header('Content-Type', 'application/json');
        $res = (new \Defyn\Dashboard\Rest\SitesGa4PropertyController())->handle($req);
        self::assertSame(200, $res->get_status());
        self::assertNull($res->get_data()['data']['ga4_property_id']);
        self::assertNull((new SitesRepository())->findById($siteId)->ga4PropertyId);
    }

    public function testRefreshOwned202(): void
    {
        $siteId = $this->seedSite();
        $res = (new \Defyn\Dashboard\Rest\SitesAnalyticsRefreshController())->handle($this->req('POST', 1, $siteId));
        self::assertSame(202, $res->get_status());
        self::assertTrue($res->get_data()['data']['scheduled']);
    }

    public function testRefreshNonOwned404(): void
    {
        $res = (new \Defyn\Dashboard\Rest\SitesAnalyticsRefreshController())->handle($this->req('POST', 1, 999999));
        self::assertSame(404, $res->get_status());
    }
}
```

- [ ] **Step 2: Run red** → FAIL. **Step 3: Create the 3 controllers:**

`src/Rest/SitesAnalyticsController.php`:
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\SiteAnalyticsRepository;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

/** P6.2 — GET /sites/{id}/analytics — latest snapshot + connection status. */
final class SitesAnalyticsController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');
        $site = (new SitesRepository())->findByIdForUser($siteId, $userId);
        if ($site === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }
        $latest = (new SiteAnalyticsRepository())->latestForSite($siteId);
        return new WP_REST_Response([
            'data'  => ['latest' => $latest?->toJson(), 'ga4_property_id' => $site->ga4PropertyId],
            'error' => null,
        ], 200);
    }
}
```

`src/Rest/SitesGa4PropertyController.php`:
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

/** P6.2 — POST /sites/{id}/ga4-property — set/clear the numeric GA4 property ID. */
final class SitesGa4PropertyController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');
        $body   = $request->get_json_params() ?: [];
        $sites  = new SitesRepository();
        if ($sites->findByIdForUser($siteId, $userId) === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }
        $raw = is_string($body['ga4_property_id'] ?? null) ? trim((string) $body['ga4_property_id']) : '';
        if ($raw !== '' && preg_match('/^\d{1,32}$/', $raw) !== 1) {
            return ErrorResponse::create(400, 'analytics.invalid_property_id', 'A numeric GA4 property ID (not the G- measurement ID) or empty value is required.');
        }
        $sites->setGa4PropertyId($siteId, $raw === '' ? null : $raw);
        return new WP_REST_Response(['data' => ['ga4_property_id' => $raw === '' ? null : $raw], 'error' => null], 200);
    }
}
```

`src/Rest/SitesAnalyticsRefreshController.php`:
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Jobs\AnalyticsSync;
use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

/** P6.2 — POST /sites/{id}/analytics/refresh — enqueue an on-demand GA4 sync (202). */
final class SitesAnalyticsRefreshController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');
        if ((new SitesRepository())->findByIdForUser($siteId, $userId) === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(AnalyticsSync::HOOK, [$siteId], 'defyn');
        }
        return new WP_REST_Response(['data' => ['scheduled' => true], 'error' => null], 202);
    }
}
```

- [ ] **Step 4: Register routes in `src/Rest/RestRouter.php`** — add 3 `use` imports (with the other `SitesPerformance*` imports) and, after the performance route block, add (two-call form; routes don't collide):
```php
        // P6.2 — GET /sites/{id}/analytics (latest snapshot + connection, read bucket)
        register_rest_route(self::NAMESPACE, '/sites/(?P<id>\d+)/analytics', [
            'methods'             => 'GET',
            'callback'            => [new SitesAnalyticsController(), 'handle'],
            'permission_callback' => [RateLimit::class, 'analyticsRead'],
        ]);

        // P6.2 — POST /sites/{id}/analytics/refresh (enqueue GA4 sync, 202)
        register_rest_route(self::NAMESPACE, '/sites/(?P<id>\d+)/analytics/refresh', [
            'methods'             => 'POST',
            'callback'            => [new SitesAnalyticsRefreshController(), 'handle'],
            'permission_callback' => [RateLimit::class, 'analyticsRefresh'],
        ]);

        // P6.2 — POST /sites/{id}/ga4-property (set/clear the property ID)
        register_rest_route(self::NAMESPACE, '/sites/(?P<id>\d+)/ga4-property', [
            'methods'             => 'POST',
            'callback'            => [new SitesGa4PropertyController(), 'handle'],
            'permission_callback' => [RateLimit::class, 'ga4Property'],
        ]);
```

- [ ] **Step 5: CORS test** — copy `tests/Integration/Rest/PerformanceCorsTest.php` → `AnalyticsCorsTest.php`, swapping the `routes()` provider to `GET /defyn/v1/sites/1/analytics`, `POST /defyn/v1/sites/1/analytics/refresh`, `POST /defyn/v1/sites/1/ga4-property`, and the `resolutionRoutes()` provider to `…/999999/…` asserting **404 `sites.not_found`** (not `rest_no_route`). Keep the same `Cors::apply` + `testRouteResolvesToControllerNotFound` structure.

- [ ] **Step 6: Run green** `composer test:integration -- --filter "SitesAnalytics|AnalyticsCors"` → all pass. Full suite tolerating only `UninstallTest`. **Step 7: Commit** `feat(p6-2): analytics endpoints (get/set-property/refresh) + routes + CORS`.

---

## Task 14: Dashboard v0.21.0 bump

**Files:** Modify `defyn-dashboard.php`.

- [ ] **Step 1:** Line 6 `Version: 0.20.0` → `0.21.0`; the `DEFYN_DASHBOARD_VERSION` define `'0.20.0'` → `'0.21.0'`.
- [ ] **Step 2:** `grep -rn "0\.20\.0" packages/dashboard-plugin/src packages/dashboard-plugin/tests` → no matches; `grep -n "0.21.0" packages/dashboard-plugin/defyn-dashboard.php` → 2 lines. **Step 3: Commit** `chore(p6-2): bump dashboard plugin to v0.21.0`.

---

## Task 15: SPA — report `analytics` schema + `ReportAnalytics` section

**Files:** Modify `apps/web/src/types/api.ts`, `apps/web/src/pages/SiteReport.tsx`, `apps/web/src/test/handlers.ts`, `apps/web/tests/SiteReport.test.tsx`, `apps/web/tests/useSiteReport.test.tsx`; Create `apps/web/src/lib/engagement.ts`, `apps/web/src/components/report/ReportAnalytics.tsx`, `apps/web/tests/ReportAnalytics.test.tsx`, `apps/web/tests/engagement.test.ts`.

- [ ] **Step 1: Node 22; write failing tests.** `tests/engagement.test.ts`:
```ts
import { describe, it, expect } from 'vitest';
import { formatEngagement } from '@/lib/engagement';

describe('formatEngagement', () => {
  it('formats seconds as Xm Ys', () => {
    expect(formatEngagement(108)).toBe('1m 48s');
    expect(formatEngagement(42)).toBe('0m 42s');
    expect(formatEngagement(120)).toBe('2m 0s');
    expect(formatEngagement(0)).toBe('0m 0s');
  });
});
```
`tests/ReportAnalytics.test.tsx` — mirror `ReportPerformance.test.tsx`'s harness. Render `<ReportAnalytics analytics={...} />` with a `ready` state (sessions 12480, a top page "Home", a channel "Organic Search") → assert "12,480"/"Home"/"Organic Search" render and "1m 48s". Then `{state:'not_connected'}` → "Analytics not connected"; `{state:'pending'}` → "not yet available". (Use `getAllByText` where a value may appear more than once.) Run red.

- [ ] **Step 2: Create `apps/web/src/lib/engagement.ts`:**
```ts
export function formatEngagement(seconds: number): string {
  const total = Math.floor(seconds);
  return `${Math.floor(total / 60)}m ${total % 60}s`;
}
```

- [ ] **Step 3: Extend the report schema** in `apps/web/src/types/api.ts` — add (near `siteReportSchema`, which is the one `useSiteReport` parses — NOT `reportSchema`):
```ts
export const reportAnalyticsSchema = z.object({
  state: z.enum(['not_connected', 'pending', 'ready']),
  period: z.object({ start: z.string(), end: z.string() }).nullable(),
  totals: z.object({
    sessions: z.number().nullable(),
    users: z.number().nullable(),
    pageviews: z.number().nullable(),
    avg_engagement_seconds: z.number().nullable(),
  }).nullable(),
  top_pages: z.array(z.object({ path: z.string(), title: z.string(), views: z.number() })),
  channels: z.array(z.object({ channel: z.string(), sessions: z.number() })),
});
export type ReportAnalyticsData = z.infer<typeof reportAnalyticsSchema>;
```
Add `analytics: reportAnalyticsSchema,` as a field INSIDE the `siteReportSchema` object.

- [ ] **Step 4: Create `apps/web/src/components/report/ReportAnalytics.tsx`** — mirror `ReportPerformance.tsx`'s shell. Props `{ analytics: ReportAnalyticsData }`. `not_connected` → "Analytics not connected."; `pending` → "Analytics not yet available for this period."; `ready` → a KPI strip (Sessions/Users/Pageviews via `toLocaleString()`, Avg engaged via `formatEngagement`) + a Top Pages table + a Channels table. Print-friendly plain markup like the other report sections. Mount in `pages/SiteReport.tsx` right after `<ReportPerformance …/>`: `<ReportAnalytics analytics={data.analytics} />`.

- [ ] **Step 5: Update fixtures.** Add an `analytics` object to the `GET /sites/:id/report` MSW handler in `src/test/handlers.ts` AND to the two existing report fixtures (`SiteReport.test.tsx` `populatedReport()`/`emptyReport()` + the `useSiteReport.test.tsx` `server.use()` override) — `analytics` is now a REQUIRED field so any report fixture missing it fails `siteReportSchema.parse`. Use `{state:'ready', period:{start:'2026-06-01',end:'2026-06-30'}, totals:{sessions:1200,users:900,pageviews:3400,avg_engagement_seconds:95}, top_pages:[{path:'/',title:'Home',views:800}], channels:[{channel:'Direct',sessions:600}]}` for populated and `{state:'not_connected',period:null,totals:null,top_pages:[],channels:[]}` for empty.

- [ ] **Step 6: Run green** `pnpm test -- --run "ReportAnalytics|engagement"` PASS; full `pnpm test -- --run` → only the 4 carry-forwards; **`pnpm build` clean (tsc)**. **Step 7: Commit** `feat(p6-2): SPA report Analytics section + engagement helper`.

---

## Task 16: SPA — Site-detail Analytics panel (property ID + refresh)

**Files:** Modify `apps/web/src/types/api.ts`, `apps/web/src/routes/SiteDetail.tsx`, `apps/web/src/test/handlers.ts`; Create `apps/web/src/lib/queries/useSiteAnalytics.ts`, `apps/web/src/lib/mutations/useSetGa4Property.ts`, `apps/web/src/lib/mutations/useRefreshAnalytics.ts`, `apps/web/src/components/sites/SiteAnalyticsPanel.tsx`, `apps/web/tests/components/sites/SiteAnalyticsPanel.test.tsx`.

- [ ] **Step 1: Write the failing test** `SiteAnalyticsPanel.test.tsx` — mirror `SitePerformancePanel.test.tsx`. Drive via MSW: not-connected (no property) shows a property-ID input + "Save"; connected shows the property + "Refresh analytics now"; saving a property calls the set mutation. Run red. **The full suite must RUN TO COMPLETION (no hang) — a hang = render loop.**

- [ ] **Step 2: Add schemas to `apps/web/src/types/api.ts`:**
```ts
export const siteAnalyticsSnapshotSchema = z.object({
  id: z.number(), site_id: z.number(),
  period_start: z.string(), period_end: z.string(),
  sessions: z.number().nullable(), total_users: z.number().nullable(),
  screen_page_views: z.number().nullable(), avg_session_duration: z.number().nullable(),
  top_pages: z.array(z.object({ path: z.string(), title: z.string(), views: z.number() })),
  channels: z.array(z.object({ channel: z.string(), sessions: z.number() })),
  fetched_at: z.string(),
});
export type SiteAnalyticsSnapshot = z.infer<typeof siteAnalyticsSnapshotSchema>;
export const siteAnalyticsResponseSchema = z.object({
  data: z.object({ latest: siteAnalyticsSnapshotSchema.nullable(), ga4_property_id: z.string().nullable() }),
  error: z.null(),
});
```

- [ ] **Step 3: Create `apps/web/src/lib/queries/useSiteAnalytics.ts`** (mirror `useSitePerformance`):
```ts
import { useQuery } from '@tanstack/react-query';
import { siteAnalyticsResponseSchema } from '@/types/api';
import { apiClient } from '@/lib/apiClient';

export function useSiteAnalytics(siteId: number, options?: { refetchInterval?: number | false }) {
  return useQuery({
    queryKey: ['siteAnalytics', siteId],
    queryFn: async () => siteAnalyticsResponseSchema.parse(await apiClient.get(`/sites/${siteId}/analytics`)).data,
    staleTime: 30_000,
    refetchInterval: options?.refetchInterval ?? false,
  });
}
```

- [ ] **Step 4: Create `apps/web/src/lib/mutations/useSetGa4Property.ts`:**
```ts
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';

export function useSetGa4Property(siteId: number) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (propertyId: string) =>
      apiClient.post(`/sites/${siteId}/ga4-property`, { ga4_property_id: propertyId }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['siteAnalytics', siteId] }),
  });
}
```
(Match `apiClient.post`'s real signature — verify against `useScanSiteSecurity`/`useMeasurePerformance`; if `post` takes only a path, send the body via whatever shape those hooks use.)

- [ ] **Step 5: Create `apps/web/src/lib/mutations/useRefreshAnalytics.ts`** — a VERBATIM structural clone of `useMeasurePerformance.ts`, substituting: query hook → `useSiteAnalytics`; timestamp field → `query.data?.latest?.fetched_at`; queryKey → `['siteAnalytics', siteId]`; POST path → `/sites/${siteId}/analytics/refresh`. Keep `isPolling` state + `preScanRef` + `refetchInterval: isPolling ? 5_000 : false` + the two `useEffect`s keyed on `[query.data?.latest?.fetched_at, isPolling]` and `[isPolling]` + the hard 60s `setTimeout`. **Primitives only in dep arrays — P2.10 render-loop guard.** Return `{ refresh, isPending, isPolling, error }`.

- [ ] **Step 6: Create `apps/web/src/components/sites/SiteAnalyticsPanel.tsx`** (mirror `SitePerformancePanel`): header "Analytics". Not-connected (`data?.ga4_property_id` null): a labeled input "GA4 Property ID (numeric — not the G- measurement ID)" + Save → `useSetGa4Property`. Connected: show the property (editable/clearable), the latest snapshot headline (sessions/users for `latest.period_start`'s month + "Last synced {latest.fetched_at}" or "No data synced yet"), and "Refresh analytics now" → `useRefreshAnalytics.refresh` (disabled while `isPending || isPolling`). Mount `<SiteAnalyticsPanel siteId={siteId} />` in `routes/SiteDetail.tsx` near `<SitePerformancePanel>`, gated `status !== 'pending'`.

- [ ] **Step 7: MSW** — add to `src/test/handlers.ts`: `GET /sites/:id/analytics` → `{data:{latest:<snapshot|null>, ga4_property_id:<string|null>}, error:null}`; `POST /sites/:id/ga4-property` → `{data:{ga4_property_id:<echo>}, error:null}`; `POST /sites/:id/analytics/refresh` → 202 `{data:{scheduled:true}, error:null}`.

- [ ] **Step 8: Run green** `pnpm test -- --run SiteAnalyticsPanel` PASS (RUNS to completion, no hang); full `pnpm test -- --run` → only 4 carry-forwards; **`pnpm build` clean**. **Step 9: Commit** `feat(p6-2): SiteAnalyticsPanel + useSiteAnalytics/useSetGa4Property/useRefreshAnalytics (bounded poll)`.

---

## Task 17: Release — build, ship, smoke, tag, MEMORY

**Files:** build artifacts only.

- [ ] **Step 1:** Full PHP suite → only `UninstallTest`. **Step 2:** Full SPA suite (Node 22) → only the 4 carry-forwards; `cd apps/web && pnpm build` clean.
- [ ] **Step 3: dompdf-preserving zip:**
```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && composer install --no-dev --classmap-authoritative
cd "/Users/pradeep/Local Sites/defynWP/packages"
rm -f "/Users/pradeep/Local Sites/defynWP/dist/defyn-dashboard-0.21.0.zip"
zip -rq "/Users/pradeep/Local Sites/defynWP/dist/defyn-dashboard-0.21.0.zip" dashboard-plugin \
  -x 'dashboard-plugin/tests/*' '*wp-tests-config.php' 'dashboard-plugin/.phpunit.result.cache' \
     'dashboard-plugin/test-output.log' 'dashboard-plugin/phpunit.xml' 'dashboard-plugin/composer.lock' \
     'dashboard-plugin/.github/*' 'dashboard-plugin/.gitignore'
cd "/Users/pradeep/Local Sites/defynWP"
unzip -l dist/defyn-dashboard-0.21.0.zip | grep -cE "deprecation-contracts/function\.php|polyfill-php83/bootstrap\.php"  # MUST be 2
unzip -l dist/defyn-dashboard-0.21.0.zip | grep -c "json-machine/src/Items\.php"          # >=1
unzip -l dist/defyn-dashboard-0.21.0.zip | grep -c "dompdf/src/Dompdf\.php"                # >=1
unzip -l dist/defyn-dashboard-0.21.0.zip | grep -c "src/Schema/SiteAnalyticsTable\.php"    # >=1
unzip -l dist/defyn-dashboard-0.21.0.zip | grep -c "src/Schema/SitePerformanceTable\.php"  # >=1
unzip -p dist/defyn-dashboard-0.21.0.zip dashboard-plugin/defyn-dashboard.php | grep -m1 DEFYN_DASHBOARD_VERSION
cd packages/dashboard-plugin && composer install
```
- [ ] **Step 4: Merge + push** `git checkout main && git merge --ff-only p6-2-ga4-analytics && git push origin main`.
- [ ] **Step 5: Kinsta install (MANUAL — pause for "installed").** Upload `dist/defyn-dashboard-0.21.0.zip` via "Replace current with uploaded version"; clear MyKinsta cache. Schema v15 self-heals.
- [ ] **Step 6: Indirect curl smoke** (login field `access_token`; backend `defynwp.defyn.agency`): `GET /sites/1/analytics` no-auth → 401; `GET /sites/999999/analytics` auth → 404 `sites.not_found` (proves route + v15 live); `POST /sites/999999/ga4-property` auth → 404; `POST /sites/999999/analytics/refresh` auth → 404; bogus route contrast → `rest.route_not_found`; verify the deployed SPA bundle contains 'Analytics' / 'GA4 Property ID' / 'Refresh analytics now'. Happy paths foreclosed by zero-sites prod + no-SA-key.
- [ ] **Step 7: Tag** `git tag p6-2-ga4-analytics-complete && git push origin p6-2-ga4-analytics-complete`.
- [ ] **Step 8: MEMORY** — append a P6.2-complete entry to `project_defyn_roadmap.md` (v0.21.0, tag, schema v15, the GA4 service-account model + calendar-month snapshots + analytics section through all 3 surfaces + the 3 endpoints + the Site-detail panel, smoke results) + refresh `MEMORY.md`. **Phase 6 COMPLETE; NEXT = operator's choice (no locked phases remain).** **Operator action (required for live data):** create a Google service account, grant it Viewer on the GA4 properties, set `DEFYN_GA4_SERVICE_ACCOUNT_JSON` on Kinsta, assign property IDs per site.

---

## Self-Review (completed during planning)

- **Spec coverage:** connection model (env SA + per-site property) → Tasks 5/9/13; schema v15 → Task 1; DTO/repo → Tasks 2–3; Ga4Client (JWT-bearer + batchRunReports) → Task 4; scan service → Task 6; weekly fan-out + self-heal → Task 7; engagement helper → Task 8 (PHP) + Task 15 (TS); report `analytics` (3 surfaces) → Task 10 (compose) + Task 11 (PDF) + Task 15 (on-screen) + P5.3 queue inherits free; rate limits → Task 12; endpoints + CORS → Task 13; version → Task 14; Site-detail panel + refresh → Task 16; release → Task 17. ✅
- **Execution-order note:** Task 9 (Site model `ga4PropertyId`) MUST run before Task 6 (AnalyticsScanService reads it) and Task 10 (compose reads it). The controller dispatches Task 9 before Task 6. ✅
- **Type consistency:** `Ga4Client::fetchReport` returns `{sessions,users,pageviews,avg_engagement,top_pages,channels}` → consumed by `SiteAnalyticsRepository::upsertForSiteAndPeriod` (maps `users`→`total_users`, `pageviews`→`screen_page_views`, `avg_engagement`→`avg_session_duration`) → `SiteAnalytics` DTO props → `compose`'s `analytics.totals` (`avg_engagement_seconds`) → PDF/SPA. Consistent throughout. ✅
- **Guardrails embedded:** best-effort null (Task 4/6), no-sync-fetch (Task 10 reads cache only), self-heal 5th guard (Task 7), esc() everything (Task 11), ownership-404 + rate limit + CORS (Task 13), bounded poll (Task 16), findAllSchedulable correct for the system cron (Task 7). ✅
- **Implementer verifications flagged:** `firebase/php-jwt` namespace `Firebase\JWT\JWT::encode($payload,$key,'RS256')` (confirmed); `Ga4Client` NON-final; `siteReportSchema` is the report-payload schema; `apiClient.post` body signature; the existing `seedSite`/`sampleReport` signatures (append, keep green); `PerformanceCorsTest` structure to mirror.
