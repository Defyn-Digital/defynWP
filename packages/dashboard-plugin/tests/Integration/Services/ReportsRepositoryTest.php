<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Services\ReportsRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class ReportsRepositoryTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Activation::ensureSchema();
        global $wpdb;
        $wpdb->query('SET autocommit = 1');
        foreach (['defyn_reports', 'defyn_sites'] as $t) {
            $wpdb->query('DELETE FROM ' . $wpdb->prefix . $t);
        }
    }

    /** Insert a minimal site row and return its id. Columns mirror the real defyn_sites schema. */
    private function seedSite(int $id = 1): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'id'         => $id,
            'user_id'    => 1,
            'url'        => 'https://acme.test',
            'label'      => 'Acme',
            'status'     => 'active',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
        return $id;
    }

    public function testCreateThenFindAndCount(): void
    {
        $siteId = $this->seedSite();
        $repo = new ReportsRepository();
        $id = $repo->create($siteId, 'Website Maintenance Report', '2026-05-01', '2026-05-31', '2026-06-01 00:00:00');
        self::assertGreaterThan(0, $id);
        self::assertSame(1, $repo->countForSite($siteId));
        $rows = $repo->findForSite($siteId, 20, 0);
        self::assertCount(1, $rows);
        self::assertSame('generating', $rows[0]->status);
        self::assertSame($id, $repo->findByIdForSite($id, $siteId)->id);
        self::assertNull($repo->findByIdForSite($id, 999));
    }

    public function testLifecycleMarks(): void
    {
        $siteId = $this->seedSite();
        $repo = new ReportsRepository();
        $id = $repo->create($siteId, 'T', '2026-05-01', '2026-05-31', '2026-06-01 00:00:00');
        $repo->markReady($id, 'report-1-tok.pdf', 1234, '2026-06-01 02:00:00');
        self::assertSame('ready', $repo->findByIdForSite($id, $siteId)->status);
        self::assertSame(1234, $repo->findByIdForSite($id, $siteId)->fileSize);
        $repo->markSent($id, 'client@acme.test', '2026-06-02 09:00:00');
        self::assertSame('sent', $repo->findByIdForSite($id, $siteId)->status);
        self::assertSame('client@acme.test', $repo->findByIdForSite($id, $siteId)->recipientEmail);
        $repo->delete($id);
        self::assertNull($repo->findByIdForSite($id, $siteId));
    }

    public function testMarkFailedStoresError(): void
    {
        $siteId = $this->seedSite();
        $repo = new ReportsRepository();
        $id = $repo->create($siteId, 'T', '2026-05-01', '2026-05-31', '2026-06-01 00:00:00');
        $repo->markFailed($id, 'render boom');
        $r = $repo->findByIdForSite($id, $siteId);
        self::assertSame('failed', $r->status);
        self::assertSame('render boom', $r->errorMessage);
    }

    public function testExistsForSiteAndMonth(): void
    {
        $siteId = $this->seedSite();
        $repo = new ReportsRepository();
        self::assertFalse($repo->existsForSiteAndMonth($siteId, '2026-05-01', '2026-05-31'));
        $repo->create($siteId, 'T', '2026-05-01', '2026-05-31', '2026-06-01 00:00:00');
        self::assertTrue($repo->existsForSiteAndMonth($siteId, '2026-05-01', '2026-05-31'));
        self::assertFalse($repo->existsForSiteAndMonth($siteId, '2026-04-01', '2026-04-30'));
    }
}
