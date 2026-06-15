<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Schema\DismissedVulnerabilitiesTable;
use Defyn\Dashboard\Services\DismissedVulnerabilitiesRepository;
use Defyn\Dashboard\Services\SiteVulnerabilitiesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SiteVulnerabilitiesRepositoryDismissTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Activation::ensureSchema();
        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL
        $wpdb->query('SET autocommit = 1');
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'defyn_site_vulnerabilities');
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'defyn_sites');
        $wpdb->query('DELETE FROM ' . DismissedVulnerabilitiesTable::tableName());
        // phpcs:enable WordPress.DB.PreparedSQL
    }

    public function testFindForSiteTagsDismissedFlag(): void
    {
        $repo = new SiteVulnerabilitiesRepository();
        $repo->replaceForSite(7, [
            $this->finding('plugin', 'elementor', 'src-ele', 'high'),
            $this->finding('plugin', 'wp-file-manager', 'src-wfm', 'critical'),
        ], '2026-06-15 00:00:00');

        (new DismissedVulnerabilitiesRepository())->dismiss(7, 'plugin', 'elementor', 'src-ele', 1, '2026-06-15 00:00:00');

        $found = $repo->findForSite(7);
        $byslug = [];
        foreach ($found as $f) { $byslug[$f->slug] = $f; }

        self::assertTrue($byslug['elementor']->dismissed, 'dismissed finding flagged true');
        self::assertFalse($byslug['wp-file-manager']->dismissed, 'non-dismissed finding flagged false');
    }

    private function finding(string $type, string $slug, string $sourceId, string $severity): array
    {
        return ['type'=>$type,'slug'=>$slug,'component_name'=>ucfirst($slug),'installed_version'=>'6.0',
                'severity'=>$severity,'cvss_score'=>null,'cve'=>null,'fixed_in'=>'9.9','title'=>'x','source_id'=>$sourceId];
    }
}
