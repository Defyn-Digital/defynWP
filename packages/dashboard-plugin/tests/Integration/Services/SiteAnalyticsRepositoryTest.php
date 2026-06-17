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
