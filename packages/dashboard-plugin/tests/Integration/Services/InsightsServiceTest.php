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
    protected function setUp(): void
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
