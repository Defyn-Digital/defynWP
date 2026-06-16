<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\PerformanceScanService;
use Defyn\Dashboard\Services\PageSpeedClient;
use Defyn\Dashboard\Services\SitePerformanceRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class PerformanceScanServiceTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Defyn\Dashboard\Activation::ensureSchema();
        global $wpdb;
        $wpdb->query('SET autocommit = 1');
        foreach (['defyn_site_performance', 'defyn_sites', 'defyn_activity_log'] as $t) {
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

    public function testStoresMobileAndDesktopSnapshot(): void
    {
        $siteId = $this->seedSite();
        $client = new class extends PageSpeedClient {
            public function fetch(string $url, string $strategy): ?array {
                return ['score' => $strategy === 'mobile' ? 82 : 96, 'lcp_ms' => 2100, 'cls' => 0.14, 'inp_ms' => 180];
            }
        };
        (new PerformanceScanService())->scan($siteId, $client);
        $latest = (new SitePerformanceRepository())->latestForSite($siteId);
        self::assertSame(82, $latest->mobileScore);
        self::assertSame(96, $latest->desktopScore);
        global $wpdb;
        self::assertSame(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}defyn_activity_log WHERE event_type = 'site.performance_measured'"));
    }

    public function testBothFailSkipsRowAndEvent(): void
    {
        $siteId = $this->seedSite();
        $client = new class extends PageSpeedClient {
            public function fetch(string $url, string $strategy): ?array { return null; }
        };
        (new PerformanceScanService())->scan($siteId, $client);
        self::assertNull((new SitePerformanceRepository())->latestForSite($siteId));
        global $wpdb;
        self::assertSame(0, (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}defyn_activity_log WHERE event_type = 'site.performance_measured'"));
    }

    public function testMissingSiteIsNoop(): void
    {
        (new PerformanceScanService())->scan(999999); // must not throw
        $this->expectNotToPerformAssertions();
    }
}
