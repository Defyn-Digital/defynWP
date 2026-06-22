<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Models\Site;
use Defyn\Dashboard\Services\BrokenLinksRepository;
use Defyn\Dashboard\Services\BrokenLinkScanService;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * P7.1 — integration tests for BrokenLinkScanService.
 *
 * Uses the $caller seam (3rd constructor arg) to inject fake connector responses
 * without making real HTTP calls. All three tests use a locally-seeded site row.
 */
final class BrokenLinkScanServiceTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Activation::activate();
        global $wpdb;
        $wpdb->query('SET autocommit = 1');
        foreach (['defyn_site_broken_links', 'defyn_activity_log', 'defyn_sites'] as $t) {
            $wpdb->query('DELETE FROM ' . $wpdb->prefix . $t);
        }
    }

    /** Insert a minimal site row (mirrors BrokenLinksRepositoryTest::seedSite). */
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

    /**
     * Happy-path: connector returns 2 findings (one 404 = broken, one 503 = warning).
     * Expects: counts {broken:1, warning:1, total:2}, last_link_scan_at set, ONE activity row.
     */
    public function testSuccessPersistsRowsSetsScannedAtAndLogsEvent(): void
    {
        $this->seedSite(1);

        $caller = static fn (Site $s): array => [
            'status' => 200,
            'body'   => [
                'links' => [
                    [
                        'url'             => 'https://x/a',
                        'status'          => 404,
                        'transport_error' => false,
                        'link_type'       => 'external',
                        'source_url'      => 'https://site/p',
                        'source_title'    => 'P',
                        'anchor_text'     => 'a',
                    ],
                    [
                        'url'             => 'https://y/b',
                        'status'          => 503,
                        'transport_error' => false,
                        'link_type'       => 'external',
                        'source_url'      => 'https://site/p',
                        'source_title'    => 'P',
                        'anchor_text'     => 'b',
                    ],
                ],
                'truncated' => false,
            ],
            'error' => '',
        ];

        (new BrokenLinkScanService(caller: $caller))->scan(1);

        $repo   = new BrokenLinksRepository();
        $counts = $repo->countsForSite(1);
        self::assertSame(1, $counts['broken'],  'Expected 1 broken link (404)');
        self::assertSame(1, $counts['warning'], 'Expected 1 warning link (503)');
        self::assertSame(2, $counts['total'],   'Expected 2 total links');

        $site = (new SitesRepository())->findById(1);
        self::assertNotNull($site);
        self::assertNotNull($site->lastLinkScanAt, 'last_link_scan_at must be set after a successful scan');

        global $wpdb;
        $eventCount = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}defyn_activity_log WHERE event_type = 'links.scan_completed'"
        );
        self::assertSame(1, $eventCount, 'Expected exactly ONE links.scan_completed activity event');

        $failedCount = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}defyn_activity_log WHERE event_type = 'links.scan_failed'"
        );
        self::assertSame(0, $failedCount, 'No links.scan_failed event should be logged on a successful scan');
    }

    /**
     * Connector transport error: scan returns error string.
     * Expects: zero link rows, last_link_scan_at IS set, ONE links.scan_failed row, ZERO links.scan_completed rows.
     */
    public function testConnectorErrorStoresNothingButStillSetsScannedAt(): void
    {
        $this->seedSite(1);

        $caller = static fn (Site $s): array => [
            'status' => 0,
            'body'   => [],
            'error'  => 'down',
        ];

        (new BrokenLinkScanService(caller: $caller))->scan(1);

        $counts = (new BrokenLinksRepository())->countsForSite(1);
        self::assertSame(0, $counts['total'], 'No link rows should be stored on connector error');

        $site = (new SitesRepository())->findById(1);
        self::assertNotNull($site);
        self::assertNotNull($site->lastLinkScanAt, 'last_link_scan_at must be set even when connector errors');

        global $wpdb;
        $failedCount = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}defyn_activity_log WHERE event_type = 'links.scan_failed'"
        );
        self::assertSame(1, $failedCount, 'Expected exactly ONE links.scan_failed activity event on connector error');

        $completedCount = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}defyn_activity_log WHERE event_type = 'links.scan_completed'"
        );
        self::assertSame(0, $completedCount, 'No links.scan_completed event should be logged when connector errors');
    }

    /**
     * Old connector (no route): 404 response with rest_no_route body.
     * Expects: nothing stored, scannedAt set, ONE links.scan_failed row, ZERO links.scan_completed rows.
     */
    public function testOldConnector404StoresNothing(): void
    {
        $this->seedSite(1);

        $caller = static fn (Site $s): array => [
            'status' => 404,
            'body'   => ['error' => ['code' => 'rest_no_route']],
            'error'  => '',
        ];

        (new BrokenLinkScanService(caller: $caller))->scan(1);

        $counts = (new BrokenLinksRepository())->countsForSite(1);
        self::assertSame(0, $counts['total'], 'No link rows should be stored when connector returns 404');

        $site = (new SitesRepository())->findById(1);
        self::assertNotNull($site);
        self::assertNotNull($site->lastLinkScanAt, 'last_link_scan_at must be set even when connector returns 404');

        global $wpdb;
        $failedCount = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}defyn_activity_log WHERE event_type = 'links.scan_failed'"
        );
        self::assertSame(1, $failedCount, 'Expected exactly ONE links.scan_failed activity event when connector returns 404');

        $completedCount = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}defyn_activity_log WHERE event_type = 'links.scan_completed'"
        );
        self::assertSame(0, $completedCount, 'No links.scan_completed event should be logged when connector returns 404');
    }
}
