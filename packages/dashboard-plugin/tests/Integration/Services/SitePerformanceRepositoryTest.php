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

    private function seedSite(int $id = 1, int $userId = 1, string $url = 'https://acme.test', string $label = 'Acme'): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'id' => $id, 'user_id' => $userId, 'url' => $url, 'label' => $label,
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
}
