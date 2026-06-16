<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\ActivityLogRepository;
use Defyn\Dashboard\Services\IncidentsRepository;
use Defyn\Dashboard\Services\ReportService;
use Defyn\Dashboard\Services\SitePluginsRepository;
use Defyn\Dashboard\Services\SiteVulnerabilitiesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class ReportServiceTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL
        $wpdb->query('SET autocommit = 1');
        foreach (['defyn_sites','defyn_activity_log','defyn_incidents','defyn_site_vulnerabilities','defyn_site_plugins'] as $t) {
            $wpdb->query('DELETE FROM ' . $wpdb->prefix . $t);
        }
        // phpcs:enable WordPress.DB.PreparedSQL
    }

    public function testComposeAggregatesAllSections(): void
    {
        $siteId = $this->seedSite(1, 'https://acme.test', 'Acme', '6.9.4');
        // ActivityLogRepository::insert stamps created_at = now(UTC); the range must
        // include "now" so the seeded update/scan events fall inside it regardless of
        // the wall-clock date the suite runs on. (today-end is always >= now.)
        $from = '2026-05-01 00:00:00';
        $to   = gmdate('Y-m-d 23:59:59');

        $log = new ActivityLogRepository();
        $log->insert(null, $siteId, 'plugin_update.succeeded', ['slug'=>'akismet','previous_version'=>'5.3','new_version'=>'5.4'], null);
        $log->insert(null, $siteId, 'core_update.succeeded', ['previous_version'=>'6.9.3','new_version'=>'6.9.4'], null);
        $log->insert(null, $siteId, 'site.vulnerabilities_detected', ['total'=>1,'critical'=>0,'high'=>1,'medium'=>0,'low'=>0], null);

        (new SitePluginsRepository())->replaceForSite($siteId, [
            ['slug'=>'akismet','name'=>'Akismet','version'=>'5.4','update_available'=>false,'update_version'=>null,'tested_up_to'=>null],
        ], '2026-06-15 00:00:00');

        $inc = new IncidentsRepository();
        $id = $inc->open($siteId, '2026-05-22 02:01:00', '502 Bad Gateway');
        $inc->close($id, '2026-05-22 02:08:00', 420);

        (new SiteVulnerabilitiesRepository())->replaceForSite($siteId, [
            ['type'=>'plugin','slug'=>'wp-file-manager','component_name'=>'WP File Manager','installed_version'=>'6.0',
             'severity'=>'high','cvss_score'=>null,'cve'=>null,'fixed_in'=>'6.9','title'=>'x','source_id'=>'src-wfm'],
        ], '2026-06-14 05:35:00');

        $report = (new ReportService())->compose($siteId, 1, $from, $to);

        self::assertSame(2, $report['overview']['updates_applied']);
        self::assertSame(1, $report['overview']['open_findings']);
        self::assertSame('6.9.4', $report['overview']['wp_version']);
        $names = array_column($report['updates'], 'component_name');
        self::assertContains('Akismet', $names);
        self::assertContains('WordPress', $names);
        self::assertCount(1, $report['uptime']['incidents']);
        self::assertSame('502 Bad Gateway', $report['uptime']['incidents'][0]['reason']);
        self::assertLessThan(100.0, $report['uptime']['range_percent']);
        self::assertCount(1, $report['security']['open_findings']);
        self::assertSame(1, $report['security']['severity_counts']['high']);
        self::assertCount(1, $report['security']['scans']);
        self::assertSame('2026-06-14 05:35:00', $report['security']['last_scan_at']);
    }

    private function seedSite(int $userId, string $url, string $label, string $wpVersion): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix.'defyn_sites', [
            'user_id'=>$userId,'url'=>$url,'label'=>$label,'status'=>'active','wp_version'=>$wpVersion,
            'last_security_scan_at'=>'2026-06-14 05:35:00',
            'created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $wpdb->insert_id;
    }
}
