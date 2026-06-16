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
