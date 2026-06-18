<?php
declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * P6.4 spike — proves dompdf (3.1.5 + php-svg-lib) renders the inline-SVG
 * primitives the trend sparkline will emit, with the SAME Options as
 * ReportPdfService. Gate before building ReportPdfService::sparklineSvg.
 *
 * Mirrors ReportPdfServiceTest's base class (AbstractSchemaTestCase) for
 * project-convention parity; the test itself is pure (no DB).
 */
final class DompdfSvgSpikeTest extends AbstractSchemaTestCase
{
    public function testDompdfRendersInlineSvgPrimitivesToPdf(): void
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $svg = '<svg width="200" height="56" viewBox="0 0 200 56" xmlns="http://www.w3.org/2000/svg">'
            . '<line x1="6" y1="28" x2="194" y2="28" stroke="#e5e7eb" stroke-width="1"/>'
            . '<line x1="6" y1="8.8" x2="194" y2="8.8" stroke="#e5e7eb" stroke-width="1"/>'
            . '<polyline fill="none" stroke="#d97706" stroke-width="2" points="10,29 100,20 190,24"/>'
            . '<polyline fill="none" stroke="#16a34a" stroke-width="2" points="10,10 100,9 190,8"/>'
            . '<circle cx="190" cy="24" r="2.6" fill="#d97706"/>'
            . '<circle cx="190" cy="8" r="2.6" fill="#16a34a"/>'
            . '</svg>';

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml('<html><body><h1>spike</h1>' . $svg . '</body></html>');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $pdf = (string) $dompdf->output();

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf));
    }
}
