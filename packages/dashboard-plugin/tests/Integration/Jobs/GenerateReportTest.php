<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Jobs;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Jobs\GenerateReport;
use Defyn\Dashboard\Services\ReportsRepository;
use Defyn\Dashboard\Services\ReportPdfService;
use Defyn\Dashboard\Services\ReportStorage;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class GenerateReportTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Activation::ensureSchema();
        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL
        $wpdb->query('SET autocommit = 1');
        foreach ([
            'defyn_reports',
            'defyn_site_vulnerabilities',
            'defyn_site_plugins',
            'defyn_site_themes',
            'defyn_sites',
            'defyn_vulnerabilities',
            'defyn_activity_log',
            'defyn_incidents',
        ] as $t) {
            $wpdb->query("DELETE FROM {$wpdb->prefix}{$t}");
        }
        // phpcs:enable WordPress.DB.PreparedSQL
    }

    public function testSuccessMarksReadyAndStoresFile(): void
    {
        $siteId = $this->seedSite();
        $repo = new ReportsRepository();
        $id = $repo->create($siteId, 'T', '2026-05-01', '2026-05-31', '2026-06-01 00:00:00');
        (new GenerateReport())->handle($id);
        $r = $repo->findByIdForSite($id, $siteId);
        self::assertSame('ready', $r->status);
        self::assertNotNull($r->fileName);
        self::assertStringStartsWith('%PDF-', (new ReportStorage())->read($r->fileName));
        (new ReportStorage())->delete($r->fileName);
    }

    public function testRenderFailureMarksFailedAndDoesNotThrow(): void
    {
        $siteId = $this->seedSite();
        $repo = new ReportsRepository();
        $id = $repo->create($siteId, 'T', '2026-05-01', '2026-05-31', '2026-06-01 00:00:00');
        $boom = new class extends ReportPdfService {
            public function render(array $report, array $branding): string
            {
                throw new \RuntimeException('boom');
            }
        };
        (new GenerateReport(null, null, $boom))->handle($id);
        $r = $repo->findByIdForSite($id, $siteId);
        self::assertSame('failed', $r->status);
        self::assertStringContainsString('boom', $r->errorMessage);
    }

    public function testMissingRowIsNoop(): void
    {
        (new GenerateReport())->handle(999999); // must not throw
        $this->expectNotToPerformAssertions();
    }

    /**
     * Insert a minimal site row (with wp_version so ReportService::compose works)
     * and return its id. Columns mirror the real defyn_sites schema.
     */
    private function seedSite(int $id = 1): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'id'         => $id,
            'user_id'    => 1,
            'url'        => 'https://acme.test',
            'label'      => 'Acme',
            'status'     => 'active',
            'wp_version' => '6.9.4',
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return $id;
    }
}
