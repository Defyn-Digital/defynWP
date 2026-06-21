<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Models\Report;
use Defyn\Dashboard\Schema\ReportsTable;
use Defyn\Dashboard\Services\ReportMailer;
use Defyn\Dashboard\Services\ReportSendService;
use Defyn\Dashboard\Services\ReportsRepository;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class ReportSendServiceTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Defyn\Dashboard\Activation::ensureSchema();
        global $wpdb;
        $wpdb->query('SET autocommit = 1');
        foreach (['defyn_reports','defyn_sites','defyn_activity_log'] as $t) {
            $wpdb->query('DELETE FROM ' . $wpdb->prefix . $t);
        }
    }

    private function seedSite(): \Defyn\Dashboard\Models\Site
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix.'defyn_sites', [
            'user_id'=>1,'url'=>'https://acme.test','label'=>'Acme','status'=>'active','wp_version'=>'6.9',
            'created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s'),
        ]);
        return (new SitesRepository())->findById((int) $wpdb->insert_id);
    }

    private function seedReport(int $siteId): Report
    {
        global $wpdb;
        $wpdb->insert(ReportsTable::tableName(), [
            'site_id'=>$siteId,'title'=>'May 2026','range_from'=>'2026-05-01','range_to'=>'2026-05-31',
            'status'=>'ready','file_name'=>'report-x.pdf','file_size'=>10,'created_at'=>gmdate('Y-m-d H:i:s'),
        ]);
        return (new ReportsRepository())->findByIdForSite((int) $wpdb->insert_id, $siteId);
    }

    private function service(bool $mailOk): ReportSendService
    {
        return new ReportSendService(new ReportMailer(fn ($to,$s,$b,$h,$a): bool => $mailOk));
    }

    public function testAutoSendMarksSentAndLogsAutoEvent(): void
    {
        $site = $this->seedSite();
        $report = $this->seedReport($site->id);
        $ok = $this->service(true)->send($report, $site, 'c@acme.com', null, 'auto');
        self::assertTrue($ok);
        $r = (new ReportsRepository())->findByIdForSite($report->id, $site->id);
        self::assertSame('sent', $r->status);
        self::assertSame('auto', $r->sentMethod);
        global $wpdb;
        $ev = $wpdb->get_var("SELECT event_type FROM {$wpdb->prefix}defyn_activity_log ORDER BY id DESC LIMIT 1");
        self::assertSame('report.auto_sent', $ev);
    }

    public function testManualSendLogsSentEvent(): void
    {
        $site = $this->seedSite();
        $report = $this->seedReport($site->id);
        $this->service(true)->send($report, $site, 'c@acme.com', 'hi', 'manual');
        global $wpdb;
        $ev = $wpdb->get_var("SELECT event_type FROM {$wpdb->prefix}defyn_activity_log ORDER BY id DESC LIMIT 1");
        self::assertSame('report.sent', $ev);
    }

    public function testFailedMailReturnsFalseAndLeavesStatus(): void
    {
        $site = $this->seedSite();
        $report = $this->seedReport($site->id);
        $ok = $this->service(false)->send($report, $site, 'c@acme.com', null, 'auto');
        self::assertFalse($ok);
        self::assertSame('ready', (new ReportsRepository())->findByIdForSite($report->id, $site->id)->status);
    }

    public function testSubjectIsSiteLedWhenNoAgencyConfigured(): void
    {
        $site = $this->seedSite();
        $report = $this->seedReport($site->id);
        $subject = '';
        $body = '';
        $svc = new ReportSendService(new ReportMailer(
            function ($to, $s, $b, $h, $a) use (&$subject, &$body): bool {
                $subject = $s;
                $body = $b;
                return true;
            }
        ));
        $svc->send($report, $site, 'c@acme.com', null, 'manual');
        // Site-led: leads with the site label, never the hardcoded agency.
        self::assertStringContainsString('Acme', $subject);
        self::assertStringStartsWith('Acme', $subject);
        self::assertStringNotContainsString('Defyn Digital', $subject);
        self::assertStringNotContainsString('Defyn Digital', $body);
        // Period info still present.
        self::assertStringContainsString('2026-05-01', $subject);
        self::assertStringContainsString('2026-05-31', $subject);
    }

    public function testAgencyAppearsInSubjectAndBodyWhenConfigured(): void
    {
        $site = $this->seedSite();
        update_user_meta($site->userId, 'defyn_report_agency_name', 'Acme Agency');
        $report = $this->seedReport($site->id);
        $subject = '';
        $body = '';
        $svc = new ReportSendService(new ReportMailer(
            function ($to, $s, $b, $h, $a) use (&$subject, &$body): bool {
                $subject = $s;
                $body = $b;
                return true;
            }
        ));
        $svc->send($report, $site, 'c@acme.com', null, 'manual');
        // Still site-led, with the configured agency surfaced.
        self::assertStringStartsWith('Acme', $subject);
        self::assertStringContainsString('Acme Agency', $subject);
        self::assertStringContainsString('Acme Agency', $body);
    }
}
