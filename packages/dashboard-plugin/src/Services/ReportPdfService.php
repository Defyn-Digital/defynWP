<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * P5.2 — renders the ReportService::compose payload into a branded PDF via dompdf.
 * dompdf NEVER fetches remote resources (isRemoteEnabled=false); the logo is fetched
 * + validated by us and inlined as a data URI (see $logoFetcher / Task 3).
 */
// Not final — subclassed in GenerateReport's failure-seam test (mirrors SitesReportPdfController).
class ReportPdfService
{
    private const LOGO_MAX_BYTES = 512 * 1024;
    private const LOGO_TYPES = ['image/png', 'image/jpeg', 'image/gif'];

    /** @var callable(string):?string returns a validated data: URI or null */
    private $logoFetcher;

    public function __construct(?callable $logoFetcher = null)
    {
        $this->logoFetcher = $logoFetcher ?? [self::class, 'defaultLogoFetcher'];
    }

    /** Pure: validate a fetched logo response → data: URI or null. No network. */
    public static function validateLogoResponse(int $status, ?string $contentType, string $body, string $url): ?string
    {
        if ($status !== 200) {
            return null;
        }
        if (stripos($url, 'https://') !== 0) {
            return null;
        }
        $ct = strtolower(trim(explode(';', (string) $contentType)[0]));
        if (!in_array($ct, self::LOGO_TYPES, true)) {
            return null;
        }
        if (strlen($body) === 0 || strlen($body) > self::LOGO_MAX_BYTES) {
            return null;
        }
        return 'data:' . $ct . ';base64,' . base64_encode($body);
    }

    /** Default fetcher — wp_remote_get (no redirects) → validateLogoResponse. Best-effort, never throws. */
    public static function defaultLogoFetcher(string $url): ?string
    {
        try {
            if (stripos($url, 'https://') !== 0) {
                return null;
            }
            $res = wp_remote_get($url, ['redirection' => 0, 'timeout' => 5]);
            if (is_wp_error($res)) {
                return null;
            }
            $status = (int) wp_remote_retrieve_response_code($res);
            $ct     = wp_remote_retrieve_header($res, 'content-type');
            $body   = (string) wp_remote_retrieve_body($res);
            return self::validateLogoResponse($status, is_string($ct) ? $ct : null, $body, $url);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param array<string,mixed> $report
     * @param array{agency_name:string,accent_color:string,logo_url:string} $branding
     */
    public function render(array $report, array $branding): string
    {
        $logo = $this->resolveSiteLogo($report);

        $html = $this->buildHtml($report, $branding, $logo);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * Test seam — returns the HTML that would be passed to dompdf (threads the logo).
     *
     * @param array<string,mixed> $report
     */
    public function debugHtml(array $report, array $branding): string
    {
        return $this->buildHtml($report, $branding, $this->resolveSiteLogo($report));
    }

    /**
     * Fetch + validate the client site's own icon ($report['site']['logo_url'])
     * into a data: URI, or null. Best-effort: the fetcher never throws.
     *
     * @param array<string,mixed> $report
     */
    private function resolveSiteLogo(array $report): ?string
    {
        $logoUrl = $report['site']['logo_url'] ?? null;

        return ($logoUrl !== null && $logoUrl !== '') ? ($this->logoFetcher)((string) $logoUrl) : null;
    }

    /** @param array<string,mixed> $report */
    private function buildHtml(array $report, array $branding, ?string $logoDataUri): string
    {
        $accent = $this->safeAccent((string) ($branding['accent_color'] ?? '#26215C'));
        $agency = $this->esc((string) ($branding['agency_name'] ?? ''));

        $rawLabel = (string) ($report['site']['label'] ?? '');
        $rawUrl   = (string) ($report['site']['url'] ?? '');
        $label = $this->esc($rawLabel !== '' ? $rawLabel : $rawUrl);
        $url   = $this->esc($rawUrl);
        $from  = $this->esc((string) ($report['period']['from'] ?? ''));
        $to    = $this->esc((string) ($report['period']['to'] ?? ''));
        $today = $this->esc($this->todayYmd());

        $cover = $this->coverHtml($accent, $agency, $label, $url, $from, $to, $today, $logoDataUri);

        $overview    = $this->overviewHtml($report);
        $performance = $this->performanceHtml($report);
        $analytics   = $this->analyticsHtml($report);
        $updates     = $this->updatesHtml($report);
        $uptime      = $this->uptimeHtml($report);
        $security    = $this->securityHtml($report);
        $brokenLinks = $this->brokenLinksHtml($report);

        return <<<HTML
<html><head><meta charset="utf-8"><style>
  body { font-family: 'DejaVu Sans', sans-serif; color:#1e293b; font-size:11px; }
  .cover { background:{$accent}; color:#fff; padding:38px 32px; page-break-after: always; }
  .cover-rule { border:0; border-top:1px solid rgba(255,255,255,0.25); margin:22px 0 16px; }
  .cover-pair-label { font-size:9px; text-transform:uppercase; letter-spacing:2px; color:rgba(255,255,255,0.6); }
  .cover-pair-value { font-size:13px; color:#fff; }
  .body-pad { padding:24px 28px 8px; }
  .card { border:1px solid #e2e8f0; border-radius:8px; padding:16px 18px; margin-bottom:16px; }
  .card h2 { color:{$accent}; font-size:14px; margin:0 0 4px; }
  .card .divider { border:0; border-top:1px solid #e2e8f0; margin:6px 0 12px; }
  .muted { color:#64748b; }
  .stats { width:100%; border-collapse:collapse; }
  .stats td { text-align:center; padding:10px; }
  .stat-num { font-size:20px; font-weight:bold; color:{$accent}; }
  .stat-label { font-size:10px; color:#64748b; text-transform:uppercase; letter-spacing:1px; }
  .kpi { border:1px solid #e2e8f0; border-radius:8px; margin-bottom:16px; }
  table.data { width:100%; border-collapse:collapse; }
  table.data th { text-align:left; font-size:10px; color:#64748b; text-transform:uppercase; letter-spacing:1px; border-bottom:2px solid {$accent}; padding:6px 4px; }
  table.data td { padding:6px 4px; border-bottom:1px solid #eee; }
</style></head><body>
  {$cover}
  <div class="body-pad">
  {$overview}
  {$performance}
  {$analytics}
  {$updates}
  {$uptime}
  {$security}
  {$brokenLinks}
  </div>
</body></html>
HTML;
    }

    /**
     * Navy cover band featuring the client SITE: logo (or monogram) + label hero +
     * URL, then a divider and Period / Prepared labelled pairs. Agency line renders
     * only when a non-empty agency name was supplied. All values pre-escaped.
     */
    private function coverHtml(
        string $accent,
        string $agency,
        string $label,
        string $url,
        string $from,
        string $to,
        string $today,
        ?string $logoDataUri
    ): string {
        $badge = $logoDataUri !== null
            ? '<img src="' . $logoDataUri . '" style="width:48px;height:48px;border-radius:8px;object-fit:cover" alt=""/>'
            : '<div style="width:48px;height:48px;border-radius:8px;background:rgba(255,255,255,0.15);text-align:center;line-height:48px;font-size:24px;font-weight:bold;color:#fff">'
                . $this->monogram($label) . '</div>';

        $agencyLine = $agency !== ''
            ? '<p style="margin:14px 0 0;font-size:10px;color:rgba(255,255,255,0.6)">Prepared by ' . $agency . '</p>'
            : '';

        return <<<HTML
  <div class="cover">
    <table style="width:100%;border-collapse:collapse"><tr>
      <td style="width:48px;vertical-align:middle">{$badge}</td>
      <td style="vertical-align:middle;padding-left:14px">
        <p style="margin:0;font-size:9px;text-transform:uppercase;letter-spacing:2px;color:rgba(255,255,255,0.6)">Website Maintenance Report</p>
        <p style="margin:2px 0 0;font-size:20px;font-weight:bold;color:#fff">{$label}</p>
        <p style="margin:2px 0 0;font-size:11px;color:rgba(255,255,255,0.75)">{$url}</p>
      </td>
    </tr></table>
    <hr class="cover-rule"/>
    <table style="width:100%;border-collapse:collapse"><tr>
      <td style="width:50%;vertical-align:top">
        <div class="cover-pair-label">Period</div>
        <div class="cover-pair-value">{$from} &ndash; {$to}</div>
      </td>
      <td style="width:50%;vertical-align:top">
        <div class="cover-pair-label">Prepared</div>
        <div class="cover-pair-value">{$today}</div>
      </td>
    </tr></table>
    {$agencyLine}
  </div>
HTML;
    }

    /** First letter of the label (uppercased) for the monogram fallback, or '•'. */
    private function monogram(string $escapedLabel): string
    {
        // $escapedLabel is already HTML-escaped; take the first character safely.
        $first = mb_substr(html_entity_decode($escapedLabel, ENT_QUOTES, 'UTF-8'), 0, 1);
        $first = $first === '' ? '•' : mb_strtoupper($first);

        return $this->esc($first);
    }

    /** Today's date (UTC, Y-m-d) for the "Prepared" cover pair. */
    private function todayYmd(): string
    {
        return function_exists('current_time') ? (string) current_time('Y-m-d') : gmdate('Y-m-d');
    }

    /** @param array<string,mixed> $report */
    private function overviewHtml(array $report): string
    {
        $overview = $report['overview'] ?? [];
        $updatesApplied = (int) ($overview['updates_applied'] ?? 0);
        $uptimePercent  = $this->esc($this->formatPercent((float) ($overview['uptime_range_percent'] ?? 0)));
        $openFindings   = (int) ($overview['open_findings'] ?? 0);
        $wpVersion      = $this->esc((string) ($overview['wp_version'] ?? ''));

        // KPI summary strip: the 4 overview stats in a bordered card, no per-stat divider.
        return <<<HTML
  <div class="kpi">
    <p style="margin:12px 18px 0;font-size:10px;text-transform:uppercase;letter-spacing:1px;color:#64748b">Overview</p>
    <table class="stats"><tr>
      <td><div class="stat-num">{$updatesApplied}</div><div class="stat-label">Updates applied</div></td>
      <td><div class="stat-num">{$uptimePercent}</div><div class="stat-label">Uptime</div></td>
      <td><div class="stat-num">{$openFindings}</div><div class="stat-label">Open findings</div></td>
      <td><div class="stat-num">{$wpVersion}</div><div class="stat-label">WordPress</div></td>
    </tr></table>
  </div>
HTML;
    }

    /** @param array<string,mixed> $report */
    private function updatesHtml(array $report): string
    {
        $updates = $report['updates'] ?? [];

        if ($updates === []) {
            return $this->sectionWithBody('Updates', '<p class="muted">No updates applied this period.</p>');
        }

        $rows = '';
        foreach ($updates as $u) {
            $name = $this->esc((string) ($u['component_name'] ?? ''));
            $type = $this->esc((string) ($u['type'] ?? ''));
            $prev = $this->esc((string) ($u['previous_version'] ?? ''));
            $new  = $this->esc((string) ($u['new_version'] ?? ''));
            $when = $this->esc((string) ($u['applied_at'] ?? ''));
            $rows .= "<tr><td>{$name}</td><td>{$type}</td><td>{$prev} &rarr; {$new}</td><td>{$when}</td></tr>";
        }

        $body = '<table class="data"><thead><tr><th>Component</th><th>Type</th><th>Version</th><th>Applied</th></tr></thead><tbody>'
            . $rows . '</tbody></table>';

        return $this->sectionWithBody('Updates', $body);
    }

    /** @param array<string,mixed> $report */
    private function uptimeHtml(array $report): string
    {
        $uptime = $report['uptime'] ?? [];
        $range = $this->esc($this->formatPercent((float) ($uptime['range_percent'] ?? 0)));
        $h24   = $this->esc($this->formatPercent((float) ($uptime['last_24h_percent'] ?? 0)));
        $d7    = $this->esc($this->formatPercent((float) ($uptime['last_7d_percent'] ?? 0)));
        $d30   = $this->esc($this->formatPercent((float) ($uptime['last_30d_percent'] ?? 0)));

        $summary = "<p>Period uptime: <strong>{$range}</strong> &middot; 24h {$h24} &middot; 7d {$d7} &middot; 30d {$d30}</p>";

        $incidents = $uptime['incidents'] ?? [];
        if ($incidents === []) {
            return $this->sectionWithBody('Uptime', $summary . '<p class="muted">No downtime this period.</p>');
        }

        $rows = '';
        foreach ($incidents as $i) {
            $reason  = $this->esc((string) ($i['reason'] ?? ''));
            $dur     = $this->esc($this->humanDuration((int) ($i['duration_seconds'] ?? 0)));
            $started = $this->esc((string) ($i['started_at'] ?? ''));
            $rows .= "<tr><td>{$reason}</td><td>{$dur}</td><td>{$started}</td></tr>";
        }

        $body = $summary
            . '<table class="data"><thead><tr><th>Reason</th><th>Duration</th><th>Started</th></tr></thead><tbody>'
            . $rows . '</tbody></table>';

        return $this->sectionWithBody('Uptime', $body);
    }

    /** @param array<string,mixed> $report */
    private function securityHtml(array $report): string
    {
        $security = $report['security'] ?? [];
        $lastScan = $security['last_scan_at'] ?? null;
        $lastScanLabel = $lastScan === null ? 'Never' : $this->esc((string) $lastScan);
        $body = "<p>Last scan: <strong>{$lastScanLabel}</strong></p>";

        $findings = $security['open_findings'] ?? [];
        if ($findings === []) {
            $body .= '<p class="muted">No open findings.</p>';
        } else {
            $rows = '';
            foreach ($findings as $f) {
                $name      = $this->esc((string) ($f['component_name'] ?? ''));
                $installed = $this->esc((string) ($f['installed_version'] ?? ''));
                $severity  = $this->esc((string) ($f['severity'] ?? ''));
                $fixedIn   = $this->esc((string) ($f['fixed_in'] ?? ''));
                $rows .= "<tr><td>{$name}</td><td>{$installed}</td><td>{$severity}</td><td>{$fixedIn}</td></tr>";
            }
            $body .= '<table class="data"><thead><tr><th>Component</th><th>Installed</th><th>Severity</th><th>Fixed in</th></tr></thead><tbody>'
                . $rows . '</tbody></table>';
        }

        $scans = $security['scans'] ?? [];
        if ($scans !== []) {
            $rows = '';
            foreach ($scans as $s) {
                $when = $this->esc((string) ($s['scanned_at'] ?? ''));
                $tot  = (int) ($s['total'] ?? 0);
                $crit = (int) ($s['critical'] ?? 0);
                $high = (int) ($s['high'] ?? 0);
                $med  = (int) ($s['medium'] ?? 0);
                $low  = (int) ($s['low'] ?? 0);
                $rows .= "<tr><td>{$when}</td><td>{$tot}</td><td>{$crit}</td><td>{$high}</td><td>{$med}</td><td>{$low}</td></tr>";
            }
            $body .= '<table class="data"><thead><tr><th>Scanned</th><th>Total</th><th>Critical</th><th>High</th><th>Medium</th><th>Low</th></tr></thead><tbody>'
                . $rows . '</tbody></table>';
        }

        return $this->sectionWithBody('Security', $body);
    }

    /** @param array<string,mixed> $report */
    private function brokenLinksHtml(array $report): string
    {
        $bl    = $report['broken_links'] ?? [
            'state'  => 'not_checked',
            'counts' => ['broken' => 0, 'warning' => 0, 'total' => 0, 'internal' => 0, 'external' => 0],
            'items'  => [],
        ];
        $state = (string) ($bl['state'] ?? 'not_checked');

        if ($state === 'not_checked') {
            return $this->sectionWithBody('Broken links', '<p class="muted">Not yet checked.</p>');
        }

        if ($state === 'clean') {
            return $this->sectionWithBody('Broken links', '<p class="muted">No broken links found.</p>');
        }

        // state === 'issues'
        $counts  = $bl['counts'] ?? [];
        $broken  = (int) ($counts['broken']  ?? 0);
        $warning = (int) ($counts['warning'] ?? 0);
        $strip   = '<p>' . $broken . ' broken &middot; ' . $warning . ' warnings</p>';

        $rows = '';
        foreach (($bl['items'] ?? []) as $item) {
            $severity   = $this->esc((string) ($item['severity']    ?? ''));
            $statusCode = $item['status_code'] !== null ? $this->esc((string) $item['status_code']) : '—';
            $url        = $this->esc((string) ($item['url']         ?? ''));
            $sourceUrl  = $this->esc((string) ($item['source_url']  ?? ''));
            $rows .= "<tr><td>{$severity}</td><td>{$statusCode}</td><td>{$url}</td><td>{$sourceUrl}</td></tr>";
        }

        $table = $rows !== ''
            ? '<table class="data"><thead><tr><th>Severity</th><th>Status</th><th>Link</th><th>On page</th></tr></thead><tbody>'
                . $rows . '</tbody></table>'
            : '';

        return $this->sectionWithBody('Broken links', $strip . $table);
    }

    /** @param array<string,mixed> $report */
    private function performanceHtml(array $report): string
    {
        $perf   = $report['performance'] ?? ['latest' => null, 'history' => []];
        $latest = $perf['latest'] ?? null;
        if ($latest === null) {
            return $this->sectionWithBody('Performance', '<p class="muted">Not yet measured.</p>');
        }
        $m = $latest['mobile'];  $d = $latest['desktop'];
        $when = $this->esc((string) ($latest['fetched_at'] ?? ''));
        $scoreRow = '<table class="stats"><tr>'
            . '<td><div class="stat-num">' . (int) ($m['score'] ?? 0) . '</div><div class="stat-label">Mobile</div></td>'
            . '<td><div class="stat-num">' . (int) ($d['score'] ?? 0) . '</div><div class="stat-label">Desktop</div></td>'
            . '</tr></table>';

        $cwv = '<table class="data"><thead><tr><th>Core Web Vital (mobile)</th><th>Value</th><th>Rating</th></tr></thead><tbody>'
            . $this->cwvRow('Largest Contentful Paint', $m['lcp_ms'] ?? null, 'lcp', 'ms')
            . $this->cwvRow('Cumulative Layout Shift', $m['cls'] ?? null, 'cls', '')
            . $this->cwvRow('Interaction to Next Paint', $m['inp_ms'] ?? null, 'inp', 'ms')
            . '</tbody></table>';

        $rows = '';
        foreach (($perf['history'] ?? []) as $h) {
            $rows .= '<tr><td>' . $this->esc((string) ($h['fetched_at'] ?? '')) . '</td><td>' . (int) ($h['mobile_score'] ?? 0) . '</td><td>' . (int) ($h['desktop_score'] ?? 0) . '</td></tr>';
        }
        $trend = $rows === '' ? '' : '<table class="data"><thead><tr><th>Measured</th><th>Mobile</th><th>Desktop</th></tr></thead><tbody>' . $rows . '</tbody></table>';

        $spark      = $this->svgImg($this->sparklineSvg($perf['history'] ?? []));
        $sparkBlock = $spark === '' ? '' : '<p class="muted" style="margin-bottom:2px">Score trend (0&ndash;100)</p>' . $spark;
        $body = '<p class="muted">PageSpeed Insights (lab) &middot; measured ' . $when . '</p>' . $scoreRow . $cwv . $sparkBlock . $trend;
        return $this->sectionWithBody('Performance', $body);
    }

    /** @param array<string,mixed> $report */
    private function analyticsHtml(array $report): string
    {
        $a     = $report['analytics'] ?? ['state' => 'not_connected'];
        $state = $a['state'] ?? 'not_connected';
        if ($state === 'not_connected') {
            return $this->sectionWithBody('Analytics', '<p class="muted">Analytics not connected.</p>');
        }
        if ($state !== 'ready') {
            return $this->sectionWithBody('Analytics', '<p class="muted">Analytics not yet available for this period.</p>');
        }

        $t = $a['totals'] ?? [];
        $avg = \Defyn\Dashboard\Services\EngagementFormat::format((float) ($t['avg_engagement_seconds'] ?? 0));
        $kpis = '<table class="stats"><tr>'
            . '<td><div class="stat-num">' . $this->esc(number_format((int) ($t['sessions'] ?? 0)))  . '</div><div class="stat-label">Sessions</div></td>'
            . '<td><div class="stat-num">' . $this->esc(number_format((int) ($t['users'] ?? 0)))     . '</div><div class="stat-label">Users</div></td>'
            . '<td><div class="stat-num">' . $this->esc(number_format((int) ($t['pageviews'] ?? 0))) . '</div><div class="stat-label">Pageviews</div></td>'
            . '<td><div class="stat-num">' . $this->esc($avg) . '</div><div class="stat-label">Avg engaged</div></td>'
            . '</tr></table>';

        $pageRows = '';
        foreach (($a['top_pages'] ?? []) as $p) {
            $path  = $this->esc((string) ($p['path'] ?? ''));
            $title = $this->esc((string) ($p['title'] ?? ''));
            $views = $this->esc(number_format((int) ($p['views'] ?? 0)));
            $pageRows .= "<tr><td>{$path} <span style=\"color:#999\">{$title}</span></td><td>{$views}</td></tr>";
        }
        $topPages = $pageRows === '' ? '' :
            '<table class="data"><thead><tr><th>Top pages</th><th>Views</th></tr></thead><tbody>' . $pageRows . '</tbody></table>';

        $chanRows = '';
        foreach (($a['channels'] ?? []) as $c) {
            $chan = $this->esc((string) ($c['channel'] ?? ''));
            $sess = $this->esc(number_format((int) ($c['sessions'] ?? 0)));
            $chanRows .= "<tr><td>{$chan}</td><td>{$sess}</td></tr>";
        }
        $channels = $chanRows === '' ? '' :
            '<table class="data"><thead><tr><th>Traffic channels</th><th>Sessions</th></tr></thead><tbody>' . $chanRows . '</tbody></table>';

        $period = $this->esc((string) ($a['period']['start'] ?? '') . ' – ' . (string) ($a['period']['end'] ?? ''));
        $spark      = $this->svgImg($this->analyticsSparklineSvg($a['history'] ?? []));
        $sparkBlock = $spark === '' ? '' : '<p class="muted" style="margin-bottom:2px">Sessions trend</p>' . $spark;
        $body = '<p class="muted">Google Analytics 4 &middot; ' . $period . '</p>' . $kpis . $sparkBlock . $topPages . $channels;
        return $this->sectionWithBody('Analytics', $body);
    }

    private function cwvRow(string $label, int|float|null $value, string $metric, string $unit): string
    {
        $rating = \Defyn\Dashboard\Services\CoreWebVitals::rate($metric, $value);
        $shown  = $value === null ? '—' : ($metric === 'cls' ? (string) $value : (string) (int) $value . ($unit !== '' ? ' ' . $unit : ''));
        $word   = match ($rating) { 'good' => 'Good', 'needs-improvement' => 'Needs work', 'poor' => 'Poor', default => '—' };
        return '<tr><td>' . $this->esc($label) . '</td><td>' . $this->esc($shown) . '</td><td>' . $this->esc($word) . '</td></tr>';
    }

    private function sectionWithBody(string $heading, string $body): string
    {
        $heading = $this->esc($heading);

        return "  <div class=\"card\">\n    <h2>{$heading}</h2>\n    <hr class=\"divider\"/>\n    {$body}\n  </div>\n";
    }

    private function formatPercent(float $value): string
    {
        return number_format($value, 1) . '%';
    }

    private function humanDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' sec';
        }

        return intdiv($seconds, 60) . ' min';
    }

    /** Strict #RRGGBB or the default — never interpolate untrusted colour into CSS. */
    private function safeAccent(string $accent): string
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $accent) === 1 ? $accent : '#26215C';
    }

    /**
     * P6.4 — inline-SVG trend line of the weekly mobile+desktop scores. Returns ''
     * unless at least one series has >=2 non-null points (a single point is not a
     * trend). dompdf renders this via the bundled php-svg-lib. Coordinates are
     * floats computed from our own integer scores — not attacker data.
     *
     * @param array<int,array<string,mixed>> $history oldest→newest history points
     */
    private function sparklineSvg(array $history): string
    {
        $mobile  = [];
        $desktop = [];
        foreach ($history as $h) {
            $mobile[]  = isset($h['mobile_score'])  && $h['mobile_score']  !== null ? (int) $h['mobile_score']  : null;
            $desktop[] = isset($h['desktop_score']) && $h['desktop_score'] !== null ? (int) $h['desktop_score'] : null;
        }
        $mPts = $this->sparkPoints($mobile);
        $dPts = $this->sparkPoints($desktop);
        if (count($mPts) < 2 && count($dPts) < 2) {
            return '';
        }

        $g50 = $this->sparkY(50);
        $g90 = $this->sparkY(90);
        $svg  = '<svg width="200" height="56" viewBox="0 0 200 56" xmlns="http://www.w3.org/2000/svg">';
        $svg .= '<line x1="6" y1="' . $g50 . '" x2="194" y2="' . $g50 . '" stroke="#e5e7eb" stroke-width="1"/>';
        $svg .= '<line x1="6" y1="' . $g90 . '" x2="194" y2="' . $g90 . '" stroke="#e5e7eb" stroke-width="1"/>';
        if (count($mPts) >= 2) {
            $svg .= '<polyline fill="none" stroke="#d97706" stroke-width="2" points="' . $this->sparkPointsAttr($mPts) . '"/>';
            $last = $mPts[count($mPts) - 1];
            $svg .= '<circle cx="' . $last[0] . '" cy="' . $last[1] . '" r="2.6" fill="#d97706"/>';
        }
        if (count($dPts) >= 2) {
            $svg .= '<polyline fill="none" stroke="#16a34a" stroke-width="2" points="' . $this->sparkPointsAttr($dPts) . '"/>';
            $last = $dPts[count($dPts) - 1];
            $svg .= '<circle cx="' . $last[0] . '" cy="' . $last[1] . '" r="2.6" fill="#16a34a"/>';
        }
        $svg .= '</svg>';
        return $svg;
    }

    /**
     * Wrap a raw sparkline <svg> as a data-URI <img> so dompdf actually paints it.
     * dompdf 3.1.5 silently drops inline <svg> elements (no error, no output), but
     * php-svg-lib DOES render SVG referenced via <img src="data:image/svg+xml;base64,…">.
     * Returns '' for an empty SVG (caller suppresses the whole block).
     */
    private function svgImg(string $svg): string
    {
        if ($svg === '') {
            return '';
        }
        return '<img src="data:image/svg+xml;base64,' . base64_encode($svg)
            . '" style="width:200px;height:56px" alt=""/>';
    }

    /**
     * P6.5 — relative-scaled (floor 0 → series max) monthly sessions trend line.
     * Returns '' unless >=2 non-null sessions and max>0. One blue line, no gridlines.
     * dompdf-safe primitives only (<svg>/<polyline>/<circle>, solid strokes).
     *
     * @param array<int,array<string,mixed>> $history oldest→newest {period_start, sessions}
     */
    private function analyticsSparklineSvg(array $history): string
    {
        $sessions = [];
        foreach ($history as $h) {
            $sessions[] = isset($h['sessions']) && $h['sessions'] !== null ? (int) $h['sessions'] : null;
        }
        $nonNull = array_values(array_filter($sessions, static fn ($s) => $s !== null));
        if (count($nonNull) < 2) {
            return '';
        }
        $max = max($nonNull);
        if ($max <= 0) {
            return '';
        }
        $pts  = $this->sparkPoints($sessions, $max);
        $last = $pts[count($pts) - 1];
        return '<svg width="200" height="56" viewBox="0 0 200 56" xmlns="http://www.w3.org/2000/svg">'
            . '<polyline fill="none" stroke="#2563eb" stroke-width="2" points="' . $this->sparkPointsAttr($pts) . '"/>'
            . '<circle cx="' . $last[0] . '" cy="' . $last[1] . '" r="2.6" fill="#2563eb"/>'
            . '</svg>';
    }

    /**
     * Non-null values → [x,y] points, evenly spaced across the width.
     * viewBox 200x56, x in [10,190], y from sparkY(). $max threads the y-scale
     * ($max=100 = PageSpeed; pass a series max for relative-scaled lines).
     * @param array<int,int|null> $values
     * @return array<int,array{0:float,1:float}>
     */
    private function sparkPoints(array $values, int $max = 100): array
    {
        $vals = array_values(array_filter($values, static fn ($s) => $s !== null));
        $n = count($vals);
        $pts = [];
        foreach ($vals as $i => $v) {
            $x = $n <= 1 ? 10.0 : 10.0 + ($i / ($n - 1)) * 180.0;
            $pts[] = [round($x, 1), $this->sparkY((int) $v, $max)];
        }
        return $pts;
    }

    /**
     * Value 0..max → y in [4,52] (higher value = higher on chart; floor at 0).
     * $max defaults to 100 (PageSpeed scale) so the performance sparkline stays byte-identical.
     */
    private function sparkY(int $value, int $max = 100): float
    {
        $max   = $max <= 0 ? 1 : $max;
        $value = max(0, min($max, $value));
        return round(4.0 + ($max - $value) / $max * 48.0, 1);
    }

    /** @param array<int,array{0:float,1:float}> $pts */
    private function sparkPointsAttr(array $pts): string
    {
        return implode(' ', array_map(static fn (array $p): string => $p[0] . ',' . $p[1], $pts));
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
