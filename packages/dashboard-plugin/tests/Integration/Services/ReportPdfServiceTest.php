<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\ReportPdfService;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class ReportPdfServiceTest extends AbstractSchemaTestCase
{
    private function sampleReport(): array
    {
        return [
            'site' => ['id'=>1,'label'=>'Acme','url'=>'https://acme.test','wp_version'=>'6.9.4'],
            'period' => ['from'=>'2026-05-16','to'=>'2026-06-15'],
            'overview' => ['updates_applied'=>2,'uptime_range_percent'=>99.7,'open_findings'=>1,'wp_version'=>'6.9.4'],
            'updates' => [
                ['type'=>'plugin','slug'=>'akismet','component_name'=>'Akismet','previous_version'=>'5.3','new_version'=>'5.4','applied_at'=>'2026-05-31 04:12:00'],
            ],
            'uptime' => ['range_percent'=>99.7,'last_24h_percent'=>100.0,'last_7d_percent'=>100.0,'last_30d_percent'=>99.7,
                'incidents'=>[['started_at'=>'2026-05-22 02:01:00','ended_at'=>'2026-05-22 02:08:00','duration_seconds'=>420,'reason'=>'502 Bad Gateway','ongoing'=>false]]],
            'security' => ['last_scan_at'=>'2026-06-14 05:35:00',
                'open_findings'=>[['type'=>'plugin','slug'=>'wp-file-manager','component_name'=>'WP File Manager','installed_version'=>'6.0','severity'=>'high','cvss_score'=>null,'cve'=>null,'fixed_in'=>'6.9','title'=>'x','source_id'=>'s','dismissed'=>false]],
                'severity_counts'=>['critical'=>0,'high'=>1,'medium'=>0,'low'=>0],
                'scans'=>[['scanned_at'=>'2026-06-14 05:35:00','total'=>1,'critical'=>0,'high'=>1,'medium'=>0,'low'=>0]]],
        ];
    }

    private function branding(): array
    {
        return ['agency_name'=>'Defyn Digital','accent_color'=>'#26215C','logo_url'=>''];
    }

    public function testRenderReturnsPdfBytes(): void
    {
        $svc = new ReportPdfService(static fn (string $url): ?string => null); // no network
        $pdf = $svc->render($this->sampleReport(), $this->branding());

        self::assertNotEmpty($pdf);
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertGreaterThan(1000, strlen($pdf));
    }
}
