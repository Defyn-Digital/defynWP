<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Jobs;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Jobs\GenerateMonthlyReportsAll;
use Defyn\Dashboard\Services\ReportsRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * P5.3 — GenerateMonthlyReportsAll recurring fan-out master.
 *
 * Every ~30 days: for each schedulable site, compute the PREVIOUS calendar
 * month, and (unless a report already exists for that site+month — dedup)
 * create a `generating` row + enqueue the per-site GenerateReport leaf job.
 * Mirrors SecurityScanAll's fan-out shape.
 *
 * @group integration
 */
final class GenerateMonthlyReportsAllTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Activation::ensureSchema();
        global $wpdb;
        $wpdb->query('SET autocommit = 1');
        foreach (['defyn_reports', 'defyn_sites'] as $t) {
            $wpdb->query('DELETE FROM ' . $wpdb->prefix . $t);
        }
    }

    /** Insert a minimal site row and return its id. Columns mirror the real defyn_sites schema. */
    private function seedSite(int $id = 1): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'id'         => $id,
            'user_id'    => 1,
            'url'        => 'https://acme.test',
            'label'      => 'Acme',
            'status'     => 'active',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
        return $id;
    }

    public function testPreviousMonthRangeIsCalendarMonth(): void
    {
        self::assertSame(['2026-05-01', '2026-05-31'], \Defyn\Dashboard\Jobs\GenerateMonthlyReportsAll::previousMonthRange('2026-06-16'));
        self::assertSame(['2026-01-01', '2026-01-31'], \Defyn\Dashboard\Jobs\GenerateMonthlyReportsAll::previousMonthRange('2026-02-10'));
        self::assertSame(['2025-12-01', '2025-12-31'], \Defyn\Dashboard\Jobs\GenerateMonthlyReportsAll::previousMonthRange('2026-01-05'));
    }

    public function testCreatesPreviousMonthRowOncePerSite(): void
    {
        $siteId = $this->seedSite();
        [$from, $to] = \Defyn\Dashboard\Jobs\GenerateMonthlyReportsAll::previousMonthRange(gmdate('Y-m-d'));
        $repo = new \Defyn\Dashboard\Services\ReportsRepository();
        self::assertFalse($repo->existsForSiteAndMonth($siteId, $from, $to));
        (new \Defyn\Dashboard\Jobs\GenerateMonthlyReportsAll())->handle();
        self::assertTrue($repo->existsForSiteAndMonth($siteId, $from, $to));
        self::assertSame(1, $repo->countForSite($siteId));
        (new \Defyn\Dashboard\Jobs\GenerateMonthlyReportsAll())->handle(); // dedup
        self::assertSame(1, $repo->countForSite($siteId));
    }
}
