<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\ActivityLogRepository;
use Defyn\Dashboard\Services\BrokenLinksRepository;
use Defyn\Dashboard\Services\IncidentsRepository;
use Defyn\Dashboard\Services\ReportService;
use Defyn\Dashboard\Services\SitePluginsRepository;
use Defyn\Dashboard\Services\SitesRepository;
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
        foreach (['defyn_sites','defyn_activity_log','defyn_incidents','defyn_site_vulnerabilities','defyn_site_plugins','defyn_site_performance','defyn_site_analytics','defyn_site_broken_links'] as $t) {
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

    public function testComposeIncludesSiteLogoUrlFromResolver(): void
    {
        $siteId = $this->seedSite(1, 'https://acme.test', 'Acme', '6.9.4');
        $resolver = new class extends \Defyn\Dashboard\Services\SiteLogoResolver {
            public function resolve(int $siteId, string $siteUrl): ?string
            {
                return 'https://acme.test/icon.png';
            }
        };
        $report = (new ReportService(logoResolver: $resolver))->compose($siteId, 1, '2026-05-01 00:00:00', '2026-05-31 23:59:59');
        self::assertSame('https://acme.test/icon.png', $report['site']['logo_url']);
    }

    public function testComposeSiteLogoUrlIsNullWhenResolverReturnsNull(): void
    {
        $siteId = $this->seedSite(1, 'https://acme.test', 'Acme', '6.9.4');
        $resolver = new class extends \Defyn\Dashboard\Services\SiteLogoResolver {
            public function resolve(int $siteId, string $siteUrl): ?string
            {
                return null;
            }
        };
        $report = (new ReportService(logoResolver: $resolver))->compose($siteId, 1, '2026-05-01 00:00:00', '2026-05-31 23:59:59');
        self::assertArrayHasKey('logo_url', $report['site']);
        self::assertNull($report['site']['logo_url']);
    }

    public function testComposeIncludesPerformanceLatestAndHistory(): void
    {
        $siteId = $this->seedSite(1, 'https://acme.test', 'Acme', '6.9.4');
        $perf = new \Defyn\Dashboard\Services\SitePerformanceRepository();
        $m = ['score' => 70, 'lcp_ms' => 2200, 'cls' => 0.1, 'inp_ms' => 150];
        $d = ['score' => 90, 'lcp_ms' => 800, 'cls' => 0.02, 'inp_ms' => 60];
        $perf->store($siteId, $m, $d, '2026-05-10 03:00:00', '2026-05-10 03:00:05');
        $perf->store($siteId, ['score'=>82]+$m, ['score'=>96]+$d, '2026-05-31 03:00:00', '2026-05-31 03:00:05');
        $perf->store($siteId, ['score'=>40]+$m, ['score'=>70]+$d, '2026-03-01 03:00:00', '2026-03-01 03:00:05'); // out of range

        $report = (new \Defyn\Dashboard\Services\ReportService())->compose($siteId, 1, '2026-05-01 00:00:00', '2026-05-31 23:59:59');
        self::assertSame(82, $report['performance']['latest']['mobile']['score']); // latest = newest overall
        self::assertCount(2, $report['performance']['history']); // only the 2 in-range, oldest first
        self::assertSame(70, $report['performance']['history'][0]['mobile_score']);
    }

    public function testComposePerformanceNullWhenNeverMeasured(): void
    {
        $siteId = $this->seedSite(1, 'https://beta.test', 'Beta', '6.9.4');
        $report = (new \Defyn\Dashboard\Services\ReportService())->compose($siteId, 1, '2026-05-01 00:00:00', '2026-05-31 23:59:59');
        self::assertNull($report['performance']['latest']);
        self::assertSame([], $report['performance']['history']);
    }

    public function testAnalyticsNotConnectedWhenNoProperty(): void
    {
        $siteId = $this->seedSite(1, 'https://acme.test', 'Acme', '6.9.4');
        $report = (new \Defyn\Dashboard\Services\ReportService())->compose($siteId, 1, '2026-06-01 00:00:00', '2026-06-30 23:59:59');
        self::assertSame('not_connected', $report['analytics']['state']);
        self::assertNull($report['analytics']['totals']);
    }

    public function testAnalyticsReadyWhenMonthSnapshotExists(): void
    {
        $siteId = $this->seedSite(1, 'https://acme.test', 'Acme', '6.9.4');
        (new \Defyn\Dashboard\Services\SitesRepository())->setGa4PropertyId($siteId, '123456789');
        (new \Defyn\Dashboard\Services\SiteAnalyticsRepository())->upsertForSiteAndPeriod(
            $siteId, '2026-06-01', '2026-06-30',
            ['sessions'=>12480,'users'=>9210,'pageviews'=>31540,'avg_engagement'=>108.5,
             'top_pages'=>[['path'=>'/','title'=>'Home','views'=>8420]],
             'channels'=>[['channel'=>'Organic Search','sessions'=>5200]]],
            '2026-06-30 03:00:00', '2026-06-30 03:00:00'
        );
        $report = (new \Defyn\Dashboard\Services\ReportService())->compose($siteId, 1, '2026-06-01 00:00:00', '2026-06-30 23:59:59');
        self::assertSame('ready', $report['analytics']['state']);
        self::assertSame(12480, $report['analytics']['totals']['sessions']);
        self::assertSame('Home', $report['analytics']['top_pages'][0]['title']);
        self::assertSame('2026-06-01', $report['analytics']['period']['start']);
    }

    public function testAnalyticsPendingWhenConnectedButNoSnapshot(): void
    {
        $siteId = $this->seedSite(1, 'https://acme.test', 'Acme', '6.9.4');
        (new \Defyn\Dashboard\Services\SitesRepository())->setGa4PropertyId($siteId, '123456789');
        $report = (new \Defyn\Dashboard\Services\ReportService())->compose($siteId, 1, '2026-06-01 00:00:00', '2026-06-30 23:59:59');
        self::assertSame('pending', $report['analytics']['state']);
    }

    public function testAnalyticsPendingWhenRangeNotCalendarMonth(): void
    {
        $siteId = $this->seedSite(1, 'https://acme.test', 'Acme', '6.9.4');
        (new \Defyn\Dashboard\Services\SitesRepository())->setGa4PropertyId($siteId, '123456789');
        (new \Defyn\Dashboard\Services\SiteAnalyticsRepository())->upsertForSiteAndPeriod(
            $siteId, '2026-06-01', '2026-06-30',
            ['sessions'=>1,'users'=>1,'pageviews'=>1,'avg_engagement'=>1.0,'top_pages'=>[],'channels'=>[]],
            '2026-06-30 03:00:00', '2026-06-30 03:00:00'
        );
        $report = (new \Defyn\Dashboard\Services\ReportService())->compose($siteId, 1, '2026-06-10 00:00:00', '2026-06-20 23:59:59');
        self::assertSame('pending', $report['analytics']['state']);
    }

    public function testAnalyticsReadyIncludesSessionsHistoryOldestToNewest(): void
    {
        $siteId = $this->seedSite(7, 'https://a.example', 'Alpha', '6.9.4');
        (new \Defyn\Dashboard\Services\SitesRepository())->setGa4PropertyId($siteId, '111');
        $ar = new \Defyn\Dashboard\Services\SiteAnalyticsRepository();
        foreach ([['2026-04-01', '2026-04-30', 400], ['2026-05-01', '2026-05-31', 500], ['2026-06-01', '2026-06-30', 980]] as [$ps, $pe, $sess]) {
            $ar->upsertForSiteAndPeriod($siteId, $ps, $pe,
                ['sessions' => $sess, 'users' => 0, 'pageviews' => 0, 'avg_engagement' => 0.0, 'top_pages' => [], 'channels' => []],
                '2026-06-20 00:00:00', '2026-06-20 00:00:00');
        }
        $report = (new \Defyn\Dashboard\Services\ReportService())->compose($siteId, 7, '2026-06-01 00:00:00', '2026-06-30 23:59:59');
        $a = $report['analytics'];
        self::assertSame('ready', $a['state']);
        self::assertArrayHasKey('history', $a);
        self::assertSame(
            [['period_start' => '2026-04-01', 'sessions' => 400], ['period_start' => '2026-05-01', 'sessions' => 500], ['period_start' => '2026-06-01', 'sessions' => 980]],
            $a['history'],
        );
    }

    public function testAnalyticsNotConnectedAndPendingEmitEmptyHistory(): void
    {
        $siteId = $this->seedSite(7, 'https://b.example', 'Bravo', '6.9.4');
        $report = (new \Defyn\Dashboard\Services\ReportService())->compose($siteId, 7, '2026-06-01 00:00:00', '2026-06-30 23:59:59');
        self::assertSame('not_connected', $report['analytics']['state']);
        self::assertSame([], $report['analytics']['history']);

        (new \Defyn\Dashboard\Services\SitesRepository())->setGa4PropertyId($siteId, '222');
        $report2 = (new \Defyn\Dashboard\Services\ReportService())->compose($siteId, 7, '2026-06-05 00:00:00', '2026-06-20 23:59:59'); // non-calendar-month → pending
        self::assertSame('pending', $report2['analytics']['state']);
        self::assertSame([], $report2['analytics']['history']);
    }

    public function testBrokenLinksNotCheckedWhenNeverScanned(): void
    {
        $siteId = $this->seedSite(1, 'https://acme.test', 'Acme', '6.9.4');
        $report = (new ReportService())->compose($siteId, 1, '2026-06-01 00:00:00', '2026-06-30 23:59:59');

        self::assertSame('not_checked', $report['broken_links']['state']);
        self::assertSame([], $report['broken_links']['items']);
        self::assertNull($report['broken_links']['last_scanned']);
    }

    public function testBrokenLinksCleanWhenScannedZero(): void
    {
        $siteId = $this->seedSite(1, 'https://acme.test', 'Acme', '6.9.4');
        (new SitesRepository())->markLinkScannedAt($siteId, '2026-06-20 10:00:00');

        $report = (new ReportService())->compose($siteId, 1, '2026-06-01 00:00:00', '2026-06-30 23:59:59');

        self::assertSame('clean', $report['broken_links']['state']);
        self::assertSame([], $report['broken_links']['items']);
        self::assertSame('2026-06-20 10:00:00', $report['broken_links']['last_scanned']);
    }

    public function testBrokenLinksIssuesListsBrokenFirst(): void
    {
        $siteId  = $this->seedSite(1, 'https://acme.test', 'Acme', '6.9.4');
        $scanAt  = '2026-06-20 10:00:00';
        (new SitesRepository())->markLinkScannedAt($siteId, $scanAt);
        $repo = new BrokenLinksRepository();
        $repo->upsertForSite($siteId, [
            'url'         => 'https://acme.test/warning-page',
            'source_url'  => 'https://acme.test/',
            'severity'    => 'warning',
            'reason'      => 'HTTP 301',
            'link_type'   => 'internal',
            'status_code' => 301,
        ], $scanAt);
        $repo->upsertForSite($siteId, [
            'url'         => 'https://acme.test/missing',
            'source_url'  => 'https://acme.test/',
            'severity'    => 'broken',
            'reason'      => 'HTTP 404',
            'link_type'   => 'internal',
            'status_code' => 404,
        ], $scanAt);

        $report = (new ReportService())->compose($siteId, 1, '2026-06-01 00:00:00', '2026-06-30 23:59:59');

        self::assertSame('issues', $report['broken_links']['state']);
        self::assertSame(1, $report['broken_links']['counts']['broken']);
        self::assertSame(1, $report['broken_links']['counts']['warning']);
        self::assertSame(2, $report['broken_links']['counts']['total']);
        self::assertSame('broken', $report['broken_links']['items'][0]['severity']);
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
