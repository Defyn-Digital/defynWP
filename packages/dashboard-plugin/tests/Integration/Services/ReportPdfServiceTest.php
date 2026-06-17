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

    public function testEscapesReportDerivedStrings(): void
    {
        $report = $this->sampleReport();
        $report['updates'][0]['component_name'] = '<script>alert(1)</script>Evil';
        $svc = new ReportPdfService(static fn (string $url): ?string => null);
        $pdf = $svc->render($report, $this->branding());
        self::assertStringStartsWith('%PDF-', $pdf);
    }

    public function testRendersAllFourSectionsInHtml(): void
    {
        $svc = new ReportPdfService(static fn (string $url): ?string => null);
        $html = $svc->debugHtml($this->sampleReport(), $this->branding());
        foreach (['Overview','Updates','Uptime','Security','Akismet','5.3','5.4','502 Bad Gateway','WP File Manager'] as $needle) {
            self::assertStringContainsString($needle, $html);
        }
        // escaped, not raw:
        $report = $this->sampleReport();
        $report['updates'][0]['component_name'] = '<b>x</b>';
        self::assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $svc->debugHtml($report, $this->branding()));
    }

    public function testValidateLogoResponseAcceptsSmallPngOnHttps(): void
    {
        $dataUri = ReportPdfService::validateLogoResponse(200, 'image/png', 'PNGBYTES', 'https://cdn.test/logo.png');
        self::assertNotNull($dataUri);
        self::assertStringStartsWith('data:image/png;base64,', $dataUri);
    }

    public function testValidateLogoResponseRejectsBadInputs(): void
    {
        self::assertNull(ReportPdfService::validateLogoResponse(200, 'image/png', 'x', 'http://cdn.test/logo.png')); // not https
        self::assertNull(ReportPdfService::validateLogoResponse(200, 'text/html', 'x', 'https://cdn.test/logo.png')); // not image
        self::assertNull(ReportPdfService::validateLogoResponse(404, 'image/png', 'x', 'https://cdn.test/logo.png')); // not 200
        self::assertNull(ReportPdfService::validateLogoResponse(200, 'image/png', str_repeat('a', 600*1024), 'https://cdn.test/logo.png')); // oversize
    }

    public function testRenderWithLogoEmbedsDataUri(): void
    {
        $svc = new ReportPdfService(static fn (string $url): ?string => 'data:image/png;base64,AAAA');
        $html = $svc->debugHtml($this->sampleReport(), ['agency_name'=>'A','accent_color'=>'#112233','logo_url'=>'https://cdn.test/logo.png']);
        self::assertStringContainsString('data:image/png;base64,AAAA', $html);
    }

    public function testRendersPerformanceSection(): void
    {
        $report = $this->sampleReport();
        $report['performance'] = [
            'latest' => [
                'fetched_at' => '2026-06-14 03:00:00',
                'mobile'  => ['score' => 82, 'lcp_ms' => 2100, 'cls' => 0.14, 'inp_ms' => 180],
                'desktop' => ['score' => 96, 'lcp_ms' => 900,  'cls' => 0.01, 'inp_ms' => 60],
            ],
            'history' => [
                ['fetched_at' => '2026-05-31 03:00:00', 'mobile_score' => 78, 'desktop_score' => 94],
                ['fetched_at' => '2026-06-14 03:00:00', 'mobile_score' => 82, 'desktop_score' => 96],
            ],
        ];
        $html = (new ReportPdfService(static fn ($u): ?string => null))->debugHtml($report, $this->branding());
        self::assertStringContainsString('Performance', $html);
        self::assertStringContainsString('82', $html);
        self::assertStringContainsString('Needs', $html); // CLS 0.14 → needs-improvement label "Needs work"
    }

    public function testRendersPerformanceNotMeasured(): void
    {
        $report = $this->sampleReport();
        $report['performance'] = ['latest' => null, 'history' => []];
        $html = (new ReportPdfService(static fn ($u): ?string => null))->debugHtml($report, $this->branding());
        self::assertStringContainsString('Not yet measured', $html);
    }

    public function testRendersAnalyticsReadySection(): void
    {
        $report = $this->sampleReport();
        $report['analytics'] = [
            'state' => 'ready',
            'period' => ['start' => '2026-06-01', 'end' => '2026-06-30'],
            'totals' => ['sessions' => 12480, 'users' => 9210, 'pageviews' => 31540, 'avg_engagement_seconds' => 108.5],
            'top_pages' => [['path' => '/', 'title' => 'Home', 'views' => 8420]],
            'channels'  => [['channel' => 'Organic Search', 'sessions' => 5200]],
        ];
        $html = (new ReportPdfService(static fn ($u): ?string => null))->debugHtml($report, $this->branding());
        self::assertStringContainsString('Analytics', $html);
        self::assertStringContainsString('12,480', $html);   // formatted session count
        self::assertStringContainsString('1m 48s', $html);   // avg engagement
        self::assertStringContainsString('Organic Search', $html);
    }

    public function testRendersAnalyticsNotConnectedWhenKeyMissing(): void
    {
        $report = $this->sampleReport(); // no 'analytics' key
        $html = (new ReportPdfService(static fn ($u): ?string => null))->debugHtml($report, $this->branding());
        self::assertStringContainsString('Analytics not connected', $html);
    }

    public function testEscapesAnalyticsStrings(): void
    {
        $report = $this->sampleReport();
        $report['analytics'] = [
            'state' => 'ready', 'period' => ['start' => '2026-06-01', 'end' => '2026-06-30'],
            'totals' => ['sessions' => 1, 'users' => 1, 'pageviews' => 1, 'avg_engagement_seconds' => 1.0],
            'top_pages' => [['path' => '/x', 'title' => '<script>bad</script>', 'views' => 1]],
            'channels'  => [['channel' => 'Direct', 'sessions' => 1]],
        ];
        $html = (new ReportPdfService(static fn ($u): ?string => null))->debugHtml($report, $this->branding());
        self::assertStringNotContainsString('<script>bad</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }
}
