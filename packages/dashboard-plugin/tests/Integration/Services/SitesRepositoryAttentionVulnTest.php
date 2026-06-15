<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Services\SiteVulnerabilitiesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SitesRepositoryAttentionVulnTest extends AbstractSchemaTestCase
{
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
    }

    public function testHasVulnerabilitiesReasonSurfaces(): void
    {
        $sites = new SitesRepository();
        $id = $this->seedSite();
        (new SiteVulnerabilitiesRepository())->replaceForSite($id, [[
            'type'               => 'plugin',
            'slug'               => 'elementor',
            'component_name'     => 'Elementor',
            'installed_version'  => '3.18.0',
            'severity'           => 'high',
            'cvss_score'         => null,
            'cve'                => null,
            'fixed_in'           => '3.18.3',
            'title'              => 'x',
            'source_id'          => 's',
        ]], '2026-06-15 00:00:00');

        $rows = $sites->findSitesNeedingAttention(1);
        $match = array_values(array_filter($rows, static fn ($r) => (int) $r['site_id'] === $id));
        self::assertNotEmpty($match, 'site with a vulnerability finding must appear in needing-attention');
        self::assertContains('has_vulnerabilities', $match[0]['reasons']);
    }

    private function seedSite(): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'    => 1,
            'url'        => 'https://vuln-attention.test',
            'label'      => 'Vuln Attention',
            'status'     => 'active',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $wpdb->insert_id;
    }
}
