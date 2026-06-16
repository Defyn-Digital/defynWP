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

    /** @param array<string,mixed> $report */
    private function buildHtml(array $report, array $branding, ?string $logoDataUri): string
    {
        $accent = $this->safeAccent((string) ($branding['accent_color'] ?? '#26215C'));
        $agency = $this->esc((string) ($branding['agency_name'] ?? 'Defyn Digital'));
        $url    = $this->esc((string) ($report['site']['url'] ?? ''));
        $from   = $this->esc((string) ($report['period']['from'] ?? ''));
        $to     = $this->esc((string) ($report['period']['to'] ?? ''));
        $logoImg = $logoDataUri !== null ? '<img src="' . $logoDataUri . '" style="max-height:60px;max-width:200px">' : '';

        return <<<HTML
<html><head><meta charset="utf-8"><style>
  body { font-family: 'DejaVu Sans', sans-serif; color:#222; font-size:11px; }
  .cover { text-align:center; padding-top:160px; page-break-after: always; }
  .band { background: {$accent}; height:8px; }
</style></head><body>
  <div class="cover">
    {$logoImg}
    <p style="color:{$accent};font-weight:bold;font-size:13px;letter-spacing:2px;">{$agency}</p>
    <p style="text-transform:uppercase;color:#888;letter-spacing:2px;font-size:11px;">Website Maintenance Report</p>
    <p style="font-size:18px;font-weight:bold;">{$url}</p>
    <p style="color:#666;">{$from} &ndash; {$to}</p>
  </div>
  <div class="band"></div>
  <!-- content sections: Task 2 -->
</body></html>
HTML;
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
