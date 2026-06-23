<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Services\SiteVulnerabilitiesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SiteVulnerabilitiesFleetTest extends AbstractSchemaTestCase
{
    private SiteVulnerabilitiesRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        Activation::ensureSchema();
        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL
        $wpdb->query('SET autocommit = 1');
        foreach (['defyn_site_vulnerabilities', 'defyn_sites'] as $t) {
            $wpdb->query("DELETE FROM {$wpdb->prefix}{$t}");
        }
        // phpcs:enable WordPress.DB.PreparedSQL
        $this->repo = new SiteVulnerabilitiesRepository();
    }

    public function testFleetSummariesCountAndScopeByUser(): void
    {
        $atRisk      = $this->seedSite(1, 'https://a.test', 'Acme', '2026-06-15 02:00:00');
        $clean       = $this->seedSite(1, 'https://b.test', 'Beta', '2026-06-15 02:00:00');
        $neverScan   = $this->seedSite(1, 'https://c.test', 'Gamma', null);
        $otherOwner  = $this->seedSite(2, 'https://x.test', 'NotMine', '2026-06-15 02:00:00');

        $this->repo->replaceForSite($atRisk, [
            $this->finding('wordfence', 'crit', 'critical'),
            $this->finding('elementor', 'high', 'high'),
            $this->finding('wpforms', 'high', 'high'),
            $this->finding('akismet', 'low', 'low'),
        ], '2026-06-15 02:00:00');
        $this->repo->replaceForSite($otherOwner, [$this->finding('x', 'crit', 'critical')], '2026-06-15 02:00:00');

        // Team-wide: per-user filter removed (2026-06-22 SSO spec).
        $rows = $this->repo->findFleetSummariesForUser(1);

        // Team-wide: both users see all sites fleet-wide.
        self::assertCount(4, $rows, 'team-wide: all 4 sites visible regardless of owner');
        $by = [];
        foreach ($rows as $r) { $by[$r['site_id']] = $r; }

        self::assertSame(1, $by[$atRisk]['critical']);
        self::assertSame(2, $by[$atRisk]['high']);
        self::assertSame(0, $by[$atRisk]['medium']);
        self::assertSame(1, $by[$atRisk]['low']);
        self::assertSame(4, $by[$atRisk]['total']);
        self::assertSame('Acme', $by[$atRisk]['label']);

        self::assertSame(0, $by[$clean]['total']);
        self::assertNotNull($by[$clean]['last_security_scan_at'], 'clean = scanned');

        self::assertSame(0, $by[$neverScan]['total']);
        self::assertNull($by[$neverScan]['last_security_scan_at'], 'never-scanned = null scan time');

        self::assertSame(1, $by[$otherOwner]['critical'], 'other-owner site is now visible fleet-wide');
    }

    public function testFindFleetSummariesIsTeamWide(): void
    {
        // Team-wide: per-user filter removed (2026-06-22 SSO spec).
        // Seed one site owned by user 11; call findFleetSummariesForUser(22).
        $siteId = $this->seedSite(11, 'https://cross.test', 'CrossSite', '2026-06-22 02:00:00');

        $result = $this->repo->findFleetSummariesForUser(22);
        self::assertCount(1, $result);
        self::assertSame($siteId, $result[0]['site_id']);
    }

    /** @return array<string,mixed> */
    private function finding(string $slug, string $name, string $severity): array
    {
        return [
            'type' => 'plugin', 'slug' => $slug, 'component_name' => $name, 'installed_version' => '1.0',
            'severity' => $severity, 'cvss_score' => null, 'cve' => null, 'fixed_in' => '2.0',
            'title' => 'x', 'source_id' => 's',
        ];
    }

    private function seedSite(int $userId, string $url, string $label, ?string $lastScan): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'               => $userId,
            'url'                   => $url,
            'label'                 => $label,
            'status'                => 'active',
            'last_security_scan_at' => $lastScan,
            'created_at'            => gmdate('Y-m-d H:i:s'),
            'updated_at'            => gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $wpdb->insert_id;
    }
}
