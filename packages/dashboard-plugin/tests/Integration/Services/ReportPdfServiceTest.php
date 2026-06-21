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
            'site' => ['id'=>1,'label'=>'Acme','url'=>'https://acme.test','wp_version'=>'6.9.4','logo_url'=>'https://site.test/icon.png'],
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

    /**
     * Decode every data:image/svg+xml;base64 payload in $html into one string.
     * Sparklines are embedded as data-URI <img> (dompdf does not paint inline <svg>),
     * so the raw <polyline>/stroke markup lives base64-encoded inside the src.
     */
    private function decodedSvgs(string $html): string
    {
        if (!preg_match_all('#data:image/svg\+xml;base64,([A-Za-z0-9+/=]+)#', $html, $m)) {
            return '';
        }
        return implode("\n", array_map(static fn ($b): string => (string) base64_decode($b), $m[1]));
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

    public function testRenderWithSiteLogoEmbedsDataUri(): void
    {
        // logo now comes from the site icon ($report['site']['logo_url']), NOT branding.
        $svc = new ReportPdfService(static fn (string $url): ?string => 'data:image/png;base64,AAAA');
        $html = $svc->debugHtml($this->sampleReport(), ['agency_name'=>'A','accent_color'=>'#112233','logo_url'=>'']);
        self::assertStringContainsString('<img src="data:image/png;base64,AAAA', $html);
    }

    public function testCoverRendersSiteLabelAsHero(): void
    {
        $svc = new ReportPdfService(static fn (string $url): ?string => null);
        $html = $svc->debugHtml($this->sampleReport(), $this->branding());
        // The site label is the cover hero; the URL renders beneath it.
        self::assertStringContainsString('Acme', $html);
        self::assertStringContainsString('https://acme.test', $html);
    }

    public function testCoverShowsMonogramWhenNoLogo(): void
    {
        // logo_url=null → monogram fallback (first letter of the label), no broken <img.
        $report = $this->sampleReport();
        $report['site']['logo_url'] = null;
        $svc = new ReportPdfService(static fn (string $url): ?string => null);
        $html = $svc->debugHtml($report, $this->branding());
        // Monogram = first letter of "Acme".
        self::assertStringContainsString('>A<', $html);
        // No logo data-URI img was emitted.
        self::assertStringNotContainsString('<img src="data:image/png;base64,', $html);
    }

    public function testRenderedHtmlHasNoHardcodedAgencyName(): void
    {
        $svc = new ReportPdfService(static fn (string $url): ?string => null);
        // Branding with an EMPTY agency name must not leak the old hardcoded default.
        $html = $svc->debugHtml($this->sampleReport(), ['agency_name'=>'','accent_color'=>'#26215C','logo_url'=>'']);
        self::assertStringNotContainsString('Defyn Digital', $html);
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

    public function testPerformanceSparklineRendersWhenHistoryHasTwoPoints(): void
    {
        $svc = new ReportPdfService();
        $report = $this->sampleReport();
        $report['performance'] = [
            'latest' => [
                'fetched_at' => '2026-06-16 03:00:00',
                'mobile'  => ['score' => 58, 'lcp_ms' => 4600, 'cls' => 0.10, 'inp_ms' => 250],
                'desktop' => ['score' => 91, 'lcp_ms' => 2400, 'cls' => 0.05, 'inp_ms' => 120],
            ],
            'history' => [
                ['fetched_at' => '2026-05-19 03:00:00', 'mobile_score' => 48, 'desktop_score' => 88],
                ['fetched_at' => '2026-06-16 03:00:00', 'mobile_score' => 58, 'desktop_score' => 91],
            ],
        ];
        $html = $svc->debugHtml($report, $this->branding());
        $svg = $this->decodedSvgs($html);
        $this->assertStringContainsString('data:image/svg+xml;base64,', $html); // sparkline embedded as data-URI img (dompdf paints these, not inline <svg>)
        $this->assertStringContainsString('<polyline', $svg);
        $this->assertStringContainsString('stroke="#d97706"', $svg); // mobile line
        $this->assertStringContainsString('stroke="#16a34a"', $svg); // desktop line
        $this->assertStringContainsString('<th>Mobile</th>', $html);   // existing table still renders
    }

    public function testPerformanceSparklineAbsentWhenSingleHistoryPoint(): void
    {
        $svc = new ReportPdfService();
        $report = $this->sampleReport();
        $report['performance'] = [
            'latest' => [
                'fetched_at' => '2026-06-16 03:00:00',
                'mobile'  => ['score' => 58, 'lcp_ms' => 4600, 'cls' => 0.10, 'inp_ms' => 250],
                'desktop' => ['score' => 91, 'lcp_ms' => 2400, 'cls' => 0.05, 'inp_ms' => 120],
            ],
            'history' => [
                ['fetched_at' => '2026-06-16 03:00:00', 'mobile_score' => 58, 'desktop_score' => 91],
            ],
        ];
        $html = $svc->debugHtml($report, $this->branding());
        $this->assertStringNotContainsString('data:image/svg+xml', $html); // <2 points = no sparkline img
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

    public function testAnalyticsSparklineRendersWhenHistoryHasTwoMonths(): void
    {
        $svc = new ReportPdfService();
        $report = $this->sampleReport();
        $report['analytics'] = [
            'state'  => 'ready',
            'period' => ['start' => '2026-06-01', 'end' => '2026-06-30'],
            'totals' => ['sessions' => 980, 'users' => 670, 'pageviews' => 2450, 'avg_engagement_seconds' => 123.0],
            'top_pages' => [], 'channels' => [],
            'history'   => [
                ['period_start' => '2026-05-01', 'sessions' => 500],
                ['period_start' => '2026-06-01', 'sessions' => 980],
            ],
        ];
        $html = $svc->debugHtml($report, $this->branding());
        $svg = $this->decodedSvgs($html);
        $this->assertStringContainsString('data:image/svg+xml;base64,', $html); // embedded as data-URI img
        $this->assertStringContainsString('<polyline', $svg);
        $this->assertStringContainsString('stroke="#2563eb"', $svg); // analytics sessions line
    }

    public function testBrokenLinksSectionRendersIssues(): void
    {
        $report = $this->sampleReport();
        $report['broken_links'] = [
            'state'        => 'issues',
            'last_scanned' => '2026-06-20 10:00:00',
            'counts'       => ['broken' => 1, 'warning' => 0, 'total' => 1, 'internal' => 1, 'external' => 0],
            'items'        => [
                [
                    'url'         => 'https://acme.test/missing-page',
                    'status_code' => 404,
                    'severity'    => 'broken',
                    'reason'      => 'HTTP 404',
                    'link_type'   => 'internal',
                    'source_url'  => 'https://acme.test/',
                ],
            ],
        ];
        $html = (new ReportPdfService(static fn ($u): ?string => null))->debugHtml($report, $this->branding());
        self::assertStringContainsString('Broken links', $html);
        self::assertStringContainsString('https://acme.test/missing-page', $html);
        self::assertStringContainsString('broken', $html);
    }

    public function testBrokenLinksToleratesMissingKey(): void
    {
        // sampleReport() has no 'broken_links' key — must render without error
        $svc = new ReportPdfService(static fn ($u): ?string => null);
        $html = $svc->debugHtml($this->sampleReport(), $this->branding());
        // Existing sections still render
        self::assertStringContainsString('Acme', $html);
        self::assertStringContainsString('Updates', $html);
        // Broken links section defaults to not_checked state
        self::assertStringContainsString('Broken links', $html);
        self::assertStringContainsString('Not yet checked', $html);
    }

    public function testAnalyticsSparklineAbsentWhenSingleMonth(): void
    {
        $svc = new ReportPdfService();
        $report = $this->sampleReport();
        $report['analytics'] = [
            'state'  => 'ready',
            'period' => ['start' => '2026-06-01', 'end' => '2026-06-30'],
            'totals' => ['sessions' => 980, 'users' => 670, 'pageviews' => 2450, 'avg_engagement_seconds' => 123.0],
            'top_pages' => [], 'channels' => [],
            'history'   => [['period_start' => '2026-06-01', 'sessions' => 980]],
        ];
        $html = $svc->debugHtml($report, $this->branding());
        $this->assertStringNotContainsString('stroke="#2563eb"', $this->decodedSvgs($html)); // <2 points = no analytics sparkline
    }
}
