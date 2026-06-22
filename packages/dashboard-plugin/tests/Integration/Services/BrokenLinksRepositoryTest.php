<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Services\BrokenLinksRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class BrokenLinksRepositoryTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Activation::activate();
        global $wpdb;
        $wpdb->query('SET autocommit = 1');
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'defyn_site_broken_links');
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'defyn_sites');
    }

    /** Insert a minimal site row and return its id. Columns mirror the real defyn_sites schema. */
    private function seedSite(int $id = 1, int $userId = 1): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'id'         => $id,
            'user_id'    => $userId,
            'url'        => 'https://acme.test',
            'label'      => 'Acme',
            'status'     => 'active',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
        return $id;
    }

    public function testUpsertInsertsThenUpdatesKeepingFirstDetected(): void
    {
        $this->seedSite(1);
        $repo = new BrokenLinksRepository();

        $finding = [
            'url'          => 'https://acme.test/broken-page',
            'source_url'   => 'https://acme.test/about',
            'source_title' => 'About Us',
            'anchor_text'  => 'Click here',
            'status_code'  => 404,
            'severity'     => 'broken',
            'reason'       => 'not_found',
            'link_type'    => 'external',
        ];

        // First upsert → INSERT
        $repo->upsertForSite(1, $finding, '2026-06-01 00:00:00');

        // Second upsert with same url+source but different scan time → UPDATE
        $finding['status_code'] = 410;
        $finding['reason']      = 'gone';
        $repo->upsertForSite(1, $finding, '2026-06-08 00:00:00');

        $rows = $repo->findForSite(1);
        self::assertCount(1, $rows, 'Upsert must produce exactly 1 row for the same url+source pair.');
        self::assertSame('2026-06-01 00:00:00', $rows[0]['first_detected_at'], 'first_detected_at must not be changed on update.');
        self::assertSame('2026-06-08 00:00:00', $rows[0]['last_detected_at'], 'last_detected_at must reflect the latest scan.');
        self::assertSame(410, $rows[0]['status_code'], 'Mutable fields must be updated.');
        self::assertSame('gone', $rows[0]['reason']);
    }

    public function testUpsertWithNullStatusCode(): void
    {
        $this->seedSite(1);
        $repo = new BrokenLinksRepository();

        $finding = [
            'url'        => 'https://acme.test/no-response',
            'source_url' => 'https://acme.test/',
            'severity'   => 'broken',
            'reason'     => 'timeout',
            'link_type'  => 'internal',
            // status_code intentionally absent → null
        ];

        $repo->upsertForSite(1, $finding, '2026-06-01 00:00:00');
        $rows = $repo->findForSite(1);
        self::assertCount(1, $rows);
        self::assertNull($rows[0]['status_code'], 'status_code must be null when not provided.');
    }

    public function testPruneRemovesRowsOlderThanScan(): void
    {
        $this->seedSite(1);
        $repo = new BrokenLinksRepository();

        $finding = [
            'url'         => 'https://acme.test/old',
            'source_url'  => 'https://acme.test/',
            'severity'    => 'broken',
            'reason'      => 'not_found',
            'link_type'   => 'external',
            'status_code' => 404,
        ];

        $repo->upsertForSite(1, $finding, '2026-06-01 00:00:00');
        self::assertCount(1, $repo->findForSite(1));

        $deleted = $repo->pruneStaleForSite(1, '2026-06-08 00:00:00');
        self::assertSame(1, $deleted, 'pruneStaleForSite should return the count of deleted rows.');
        self::assertEmpty($repo->findForSite(1), 'Row older than scan date should be pruned.');
    }

    public function testPruneKeepsRowsWithCurrentScanDate(): void
    {
        $this->seedSite(1);
        $repo   = new BrokenLinksRepository();

        $finding = [
            'url'         => 'https://acme.test/current',
            'source_url'  => 'https://acme.test/',
            'severity'    => 'broken',
            'reason'      => 'not_found',
            'link_type'   => 'external',
            'status_code' => 404,
        ];

        $scanAt = '2026-06-08 00:00:00';
        $repo->upsertForSite(1, $finding, $scanAt);

        // prune with the same scanAt — row has last_detected_at == scanAt, not < scanAt
        $deleted = $repo->pruneStaleForSite(1, $scanAt);
        self::assertSame(0, $deleted, 'Rows detected in the current scan should NOT be pruned.');
        self::assertCount(1, $repo->findForSite(1));
    }

    public function testCountsForSite(): void
    {
        $this->seedSite(1);
        $repo   = new BrokenLinksRepository();
        $scanAt = '2026-06-01 00:00:00';

        // 2 broken + 1 warning; mix internal/external
        $repo->upsertForSite(1, [
            'url' => 'https://acme.test/broken1', 'source_url' => 'https://acme.test/',
            'severity' => 'broken', 'reason' => 'not_found', 'link_type' => 'external', 'status_code' => 404,
        ], $scanAt);
        $repo->upsertForSite(1, [
            'url' => 'https://acme.test/broken2', 'source_url' => 'https://acme.test/blog',
            'severity' => 'broken', 'reason' => 'not_found', 'link_type' => 'internal', 'status_code' => 404,
        ], $scanAt);
        $repo->upsertForSite(1, [
            'url' => 'https://redirect.test/', 'source_url' => 'https://acme.test/',
            'severity' => 'warning', 'reason' => 'redirect', 'link_type' => 'external', 'status_code' => 301,
        ], $scanAt);

        $counts = $repo->countsForSite(1);
        self::assertSame(2, $counts['broken']);
        self::assertSame(1, $counts['warning']);
        self::assertSame(3, $counts['total']);
        self::assertSame(1, $counts['internal']);
        self::assertSame(2, $counts['external']);
    }

    public function testCountsForSiteReturnsZerosWhenNoRows(): void
    {
        $this->seedSite(1);
        $repo   = new BrokenLinksRepository();
        $counts = $repo->countsForSite(1);
        self::assertSame(0, $counts['broken']);
        self::assertSame(0, $counts['warning']);
        self::assertSame(0, $counts['total']);
        self::assertSame(0, $counts['internal']);
        self::assertSame(0, $counts['external']);
    }

    public function testCountSitesWithBrokenLinksForUserCountsOnlyBroken(): void
    {
        // site 1 (user 1) → only a warning row
        $this->seedSite(1, 1);
        // site 2 (user 1) → a broken row
        $this->seedSite(2, 1);

        $repo   = new BrokenLinksRepository();
        $scanAt = '2026-06-01 00:00:00';

        $repo->upsertForSite(1, [
            'url' => 'https://acme.test/warn', 'source_url' => 'https://acme.test/',
            'severity' => 'warning', 'reason' => 'redirect', 'link_type' => 'external', 'status_code' => 301,
        ], $scanAt);

        $repo->upsertForSite(2, [
            'url' => 'https://other.test/404', 'source_url' => 'https://other.test/',
            'severity' => 'broken', 'reason' => 'not_found', 'link_type' => 'external', 'status_code' => 404,
        ], $scanAt);

        self::assertSame(
            1,
            $repo->countSitesWithBrokenLinksForUser(1),
            'Only site 2 has broken links; site 1 has only a warning.'
        );
    }

    public function testCountSitesWithBrokenLinksIsTeamWide(): void
    {
        // Team-wide: per-user filter removed (2026-06-22 SSO spec).
        // Seed a site owned by user 11 with a broken link; query as user 22.
        $this->seedSite(50, 11);
        $repo   = new BrokenLinksRepository();
        $scanAt = '2026-06-22 00:00:00';

        $repo->upsertForSite(50, [
            'url'         => 'https://team.test/404',
            'source_url'  => 'https://team.test/',
            'severity'    => 'broken',
            'reason'      => 'not_found',
            'link_type'   => 'external',
            'status_code' => 404,
        ], $scanAt);

        // Team-wide: both users see all sites fleet-wide.
        self::assertSame(
            1,
            $repo->countSitesWithBrokenLinksForUser(22),
            'Cross-owner broken-link count must be visible fleet-wide.'
        );
    }

    public function testFindForSiteBrokenFirst(): void
    {
        $this->seedSite(1);
        $repo   = new BrokenLinksRepository();
        $scanAt = '2026-06-01 00:00:00';

        // Insert warning first, then broken — order should be broken first in results
        $repo->upsertForSite(1, [
            'url' => 'https://acme.test/warn', 'source_url' => 'https://acme.test/',
            'severity' => 'warning', 'reason' => 'redirect', 'link_type' => 'external', 'status_code' => 301,
        ], $scanAt);

        $repo->upsertForSite(1, [
            'url' => 'https://acme.test/broken', 'source_url' => 'https://acme.test/',
            'severity' => 'broken', 'reason' => 'not_found', 'link_type' => 'external', 'status_code' => 404,
        ], $scanAt);

        $rows = $repo->findForSite(1);
        self::assertCount(2, $rows);
        self::assertSame('broken', $rows[0]['severity'], 'Broken links must come first in findForSite results.');
    }

    public function testFindForSiteCastsTypes(): void
    {
        $this->seedSite(1);
        $repo = new BrokenLinksRepository();

        $repo->upsertForSite(1, [
            'url' => 'https://acme.test/page', 'source_url' => 'https://acme.test/',
            'severity' => 'broken', 'reason' => 'not_found', 'link_type' => 'external', 'status_code' => 404,
        ], '2026-06-01 00:00:00');

        $rows = $repo->findForSite(1);
        self::assertCount(1, $rows);
        self::assertIsInt($rows[0]['id']);
        self::assertIsInt($rows[0]['site_id']);
        self::assertIsInt($rows[0]['status_code']);
    }

    public function testFindTopForReport(): void
    {
        $this->seedSite(1);
        $repo   = new BrokenLinksRepository();
        $scanAt = '2026-06-01 00:00:00';

        for ($i = 1; $i <= 5; $i++) {
            $repo->upsertForSite(1, [
                'url'         => "https://acme.test/page{$i}",
                'source_url'  => 'https://acme.test/',
                'severity'    => 'broken',
                'reason'      => 'not_found',
                'link_type'   => 'external',
                'status_code' => 404,
            ], $scanAt);
        }

        $top3 = $repo->findTopForReport(1, 3);
        self::assertCount(3, $top3, 'findTopForReport should respect the limit parameter.');
    }
}
