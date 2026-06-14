<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Services\SiteVulnerabilitiesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SiteVulnerabilitiesRepositoryTest extends AbstractSchemaTestCase
{
    private SiteVulnerabilitiesRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        // replaceForSite() uses an explicit COMMIT that escapes WP_UnitTestCase
        // rollback (guardrail #15) — purge for a clean slate, mirroring VulnFeedServiceTest.
        $this->freshlyActivate('defyn_site_vulnerabilities');
        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL
        $wpdb->query('SET autocommit = 1');
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_site_vulnerabilities");
        // phpcs:enable WordPress.DB.PreparedSQL
        Activation::ensureSchema();
        $this->repo = new SiteVulnerabilitiesRepository();
    }

    public function testReplaceForSiteThenFindSortedBySeverity(): void
    {
        $now = '2026-06-15 02:00:00';
        $this->repo->replaceForSite(7, [
            $this->finding('plugin', 'akismet', 'Akismet', 'low'),
            $this->finding('plugin', 'wp-file-manager', 'WP File Manager', 'critical'),
            $this->finding('plugin', 'elementor', 'Elementor', 'high'),
        ], $now);

        $found = $this->repo->findForSite(7);
        self::assertCount(3, $found);
        self::assertSame('critical', $found[0]->severity);
        self::assertSame('high', $found[1]->severity);
        self::assertSame('low', $found[2]->severity);
    }

    public function testReplaceForSiteWipesStaleFindings(): void
    {
        $now = '2026-06-15 02:00:00';
        $this->repo->replaceForSite(7, [$this->finding('plugin', 'elementor', 'Elementor', 'high')], $now);
        $this->repo->replaceForSite(7, [], $now); // re-scan finds nothing
        self::assertCount(0, $this->repo->findForSite(7));
    }

    /** @return array<string,mixed> */
    private function finding(string $type, string $slug, string $name, string $severity): array
    {
        return [
            'type' => $type, 'slug' => $slug, 'component_name' => $name, 'installed_version' => '1.0.0',
            'severity' => $severity, 'cvss_score' => null, 'cve' => null, 'fixed_in' => '2.0.0',
            'title' => 'x', 'source_id' => 'src',
        ];
    }
}
