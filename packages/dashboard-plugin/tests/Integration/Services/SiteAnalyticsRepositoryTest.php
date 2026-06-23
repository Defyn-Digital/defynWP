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
        \Defyn\Dashboard\Activation::ensureSchema();
        global $wpdb;
        $wpdb->query('SET autocommit = 1');
        foreach (['defyn_site_analytics', 'defyn_sites'] as $t) {
            $wpdb->query('DELETE FROM ' . $wpdb->prefix . $t);
        }
    }

    private function seedSite(int $id = 1, int $userId = 1, string $url = 'https://acme.test', string $label = 'Acme'): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'id' => $id, 'user_id' => $userId, 'url' => $url, 'label' => $label,
            'status' => 'active', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00', 'wp_version' => '6.9.4',
        ]);
        return $id;
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

    public function testFindFleetForUserIsTeamWide(): void
    {
        // Team-wide: per-user filter removed (2026-06-22 SSO spec).
        $this->seedSite(1, 7, 'https://a.example', 'Alpha');
        $this->seedSite(2, 9, 'https://x.example', 'Other'); // different owner — still visible fleet-wide

        $repo = new SiteAnalyticsRepository();

        // Team-wide: both users see all sites fleet-wide.
        $rowsFor7 = $repo->findFleetForUser(7);
        $this->assertCount(2, $rowsFor7);

        $rowsFor9 = $repo->findFleetForUser(9);
        $this->assertCount(2, $rowsFor9);
    }

    public function testFindFleetForUserCrossOwnerVisible(): void
    {
        // Team-wide: per-user filter removed (2026-06-22 SSO spec).
        // Seed a site owned by user 11, add analytics, then query as user 22.
        $this->seedSite(11, 11, 'https://team.example', 'TeamSite');
        $repo = new SiteAnalyticsRepository();
        $repo->upsertForSiteAndPeriod(11, '2026-05-01', '2026-05-31', $this->data(300),
            '2026-06-01 00:00:00', '2026-06-01 00:00:00');

        $result = $repo->findFleetForUser(22);
        $this->assertCount(1, $result);
        $this->assertSame(11, $result[0]['site_id']);
    }

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
}
