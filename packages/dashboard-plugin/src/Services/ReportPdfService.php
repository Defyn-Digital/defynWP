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
final class ReportPdfService
{
    /** @var callable(string):?string returns a validated data: URI or null */
    private $logoFetcher;

    public function __construct(?callable $logoFetcher = null)
    {
        $this->logoFetcher = $logoFetcher ?? static fn (string $url): ?string => null; // real fetcher: Task 3
    }

    /**
     * @param array<string,mixed> $report
     * @param array{agency_name:string,accent_color:string,logo_url:string} $branding
     */
    public function render(array $report, array $branding): string
    {
        $logo = ($branding['logo_url'] ?? '') !== ''
            ? ($this->logoFetcher)($branding['logo_url'])
            : null;

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
        $logo = ($branding['logo_url'] ?? '') !== '' ? ($this->logoFetcher)($branding['logo_url']) : null;

        return $this->buildHtml($report, $branding, $logo);
    }

    /** @param array<string,mixed> $report */
    private function buildHtml(array $report, array $branding, ?string $logoDataUri): string
    {
        $accent = $this->safeAccent((string) ($branding['accent_color'] ?? '#26215C'));
        $agency = $this->esc((string) ($branding['agency_name'] ?? 'Defyn Digital'));
        $url    = $this->esc((string) ($report['site']['url'] ?? ''));
        $from   = $this->esc((string) ($report['period']['from'] ?? ''));
        $to     = $this->esc((string) ($report['period']['to'] ?? ''));
        $logoImg = $logoDataUri !== null ? '<img src="' . $logoDataUri . '" style="max-height:60px;max-width:200px">' : '';

        $overview = $this->overviewHtml($report);
        $updates  = $this->updatesHtml($report);
        $uptime   = $this->uptimeHtml($report);
        $security = $this->securityHtml($report);

        return <<<HTML
<html><head><meta charset="utf-8"><style>
  body { font-family: 'DejaVu Sans', sans-serif; color:#222; font-size:11px; }
  .cover { text-align:center; padding-top:160px; page-break-after: always; }
  .band { background: {$accent}; height:8px; }
  .section { padding:18px 28px; }
  .section h2 { color:{$accent}; font-size:15px; margin:0 0 10px; }
  .muted { color:#888; }
  .stats { width:100%; border-collapse:collapse; }
  .stats td { text-align:center; padding:10px; }
  .stat-num { font-size:20px; font-weight:bold; color:{$accent}; }
  .stat-label { font-size:10px; color:#888; text-transform:uppercase; letter-spacing:1px; }
  table.data { width:100%; border-collapse:collapse; }
  table.data th { text-align:left; font-size:10px; color:#888; text-transform:uppercase; letter-spacing:1px; border-bottom:2px solid {$accent}; padding:6px 4px; }
  table.data td { padding:6px 4px; border-bottom:1px solid #eee; }
</style></head><body>
  <div class="cover">
    {$logoImg}
    <p style="color:{$accent};font-weight:bold;font-size:13px;letter-spacing:2px;">{$agency}</p>
    <p style="text-transform:uppercase;color:#888;letter-spacing:2px;font-size:11px;">Website Maintenance Report</p>
    <p style="font-size:18px;font-weight:bold;">{$url}</p>
    <p style="color:#666;">{$from} &ndash; {$to}</p>
  </div>
  <div class="band"></div>
  {$overview}
  {$updates}
  {$uptime}
  {$security}
</body></html>
HTML;
    }

    /** @param array<string,mixed> $report */
    private function overviewHtml(array $report): string
    {
        $overview = $report['overview'] ?? [];
        $updatesApplied = (int) ($overview['updates_applied'] ?? 0);
        $uptimePercent  = $this->esc($this->formatPercent((float) ($overview['uptime_range_percent'] ?? 0)));
        $openFindings   = (int) ($overview['open_findings'] ?? 0);
        $wpVersion      = $this->esc((string) ($overview['wp_version'] ?? ''));

        return <<<HTML
  <div class="section">
    <h2>Overview</h2>
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

    private function sectionWithBody(string $heading, string $body): string
    {
        $heading = $this->esc($heading);

        return "  <div class=\"section\">\n    <h2>{$heading}</h2>\n    {$body}\n  </div>\n";
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

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
