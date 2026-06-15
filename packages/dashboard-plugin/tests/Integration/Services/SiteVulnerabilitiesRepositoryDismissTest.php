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

    public function testFleetSummaryExcludesDismissedFromCounts(): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix.'defyn_sites', [
            'user_id'=>1,'url'=>'https://f.test','label'=>'Fleet','status'=>'active',
            'last_security_scan_at'=>'2026-06-15 00:00:00',
            'created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s'),
        ]);
        $siteId = (int) $wpdb->insert_id;

        $repo = new SiteVulnerabilitiesRepository();
        $repo->replaceForSite($siteId, [
            $this->finding('plugin', 'elementor', 'src-ele', 'high'),
            $this->finding('plugin', 'wp-file-manager', 'src-wfm', 'critical'),
        ], '2026-06-15 00:00:00');
        (new DismissedVulnerabilitiesRepository())->dismiss($siteId, 'plugin', 'wp-file-manager', 'src-wfm', 1, '2026-06-15 00:00:00');

        $rows = $repo->findFleetSummariesForUser(1);
        $row = null;
        foreach ($rows as $r) { if ((int) $r['site_id'] === $siteId) { $row = $r; } }

        self::assertNotNull($row);
        self::assertSame(0, (int) $row['critical'], 'dismissed critical excluded');
        self::assertSame(1, (int) $row['high'], 'non-dismissed high still counted');
        self::assertSame(1, (int) $row['total'], 'total counts only non-dismissed');
        self::assertNotNull($row['last_security_scan_at'], 'site still reads scanned (clean), not never-scanned');
    }

    private function finding(string $type, string $slug, string $sourceId, string $severity): array
    {
        return ['type'=>$type,'slug'=>$slug,'component_name'=>ucfirst($slug),'installed_version'=>'6.0',
                'severity'=>$severity,'cvss_score'=>null,'cve'=>null,'fixed_in'=>'9.9','title'=>'x','source_id'=>$sourceId];
    }
}
