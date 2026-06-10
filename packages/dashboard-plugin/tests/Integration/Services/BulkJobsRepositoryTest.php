<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\BulkJobsRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * P2.9 — create + find half of BulkJobsRepository.
 *
 * Guardrail #15: freshlyActivate + explicit purge of both bulk tables in setUp.
 *
 * @group integration
 */
final class BulkJobsRepositoryTest extends AbstractSchemaTestCase
{
    private BulkJobsRepository $repo;

    public function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_bulk_jobs');
        $this->freshlyActivate('defyn_bulk_job_items');

        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL
        $wpdb->query('SET autocommit = 1');
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_bulk_job_items");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_bulk_jobs");
        // phpcs:enable WordPress.DB.PreparedSQL

        $this->repo = new BulkJobsRepository();
    }

    public function testCreateJobReturnsInsertedId(): void
    {
        $jobId = $this->repo->createJob(1, 'plugin_update', 3, 1, '2026-06-09 21:00:00');

        $this->assertGreaterThan(0, $jobId);

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}defyn_bulk_jobs WHERE id = %d", $jobId),
            ARRAY_A
        );
        $this->assertSame('1', $row['user_id']);
        $this->assertSame('plugin_update', $row['kind']);
        $this->assertSame('3', $row['scheduled_count']);
        $this->assertSame('1', $row['skipped_count']);
        $this->assertNull($row['started_at']);
        $this->assertNull($row['completed_at']);
        $this->assertSame('2026-06-09 21:00:00', $row['created_at']);
    }

    public function testCreateItemsInsertsAllPairs(): void
    {
        $jobId = $this->repo->createJob(1, 'plugin_update', 2, 0, '2026-06-09 21:00:00');
        $this->repo->createItems($jobId, [
            ['site_id' => 1, 'slug' => 'akismet'],
            ['site_id' => 2, 'slug' => 'yoast'],
        ], '2026-06-09 21:00:00');

        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}defyn_bulk_job_items WHERE job_id = %d ORDER BY id ASC",
                $jobId
            ),
            ARRAY_A
        );
        $this->assertCount(2, $rows);
        $this->assertSame('akismet', $rows[0]['resource_slug']);
        $this->assertSame('queued', $rows[0]['state']);
        $this->assertSame('yoast', $rows[1]['resource_slug']);
        $this->assertSame('2', $rows[1]['site_id']);
    }

    public function testCreateItemsReturnsPairsEnrichedWithItemIds(): void
    {
        $jobId    = $this->repo->createJob(1, 'theme_update', 2, 0, '2026-06-09 21:00:00');
        $enriched = $this->repo->createItems($jobId, [
            ['site_id' => 1, 'slug' => 'astra'],
            ['site_id' => 1, 'slug' => 'blocksy'],
        ], '2026-06-09 21:00:00');

        $this->assertCount(2, $enriched);
        foreach ($enriched as $pair) {
            $this->assertArrayHasKey('site_id', $pair);
            $this->assertArrayHasKey('slug', $pair);
            $this->assertArrayHasKey('item_id', $pair);
            $this->assertGreaterThan(0, $pair['item_id']);
        }
        $this->assertSame('astra', $enriched[0]['slug']);
        $this->assertSame('blocksy', $enriched[1]['slug']);
        $this->assertNotSame($enriched[0]['item_id'], $enriched[1]['item_id']);
    }

    public function testCreateItemsUsesSingleInsertStatement(): void
    {
        $jobId = $this->repo->createJob(1, 'plugin_update', 5, 0, '2026-06-09 21:00:00');

        global $wpdb;
        $before = (int) $wpdb->num_queries;

        $this->repo->createItems($jobId, [
            ['site_id' => 1, 'slug' => 'a'],
            ['site_id' => 1, 'slug' => 'b'],
            ['site_id' => 2, 'slug' => 'c'],
            ['site_id' => 2, 'slug' => 'd'],
            ['site_id' => 3, 'slug' => 'e'],
        ], '2026-06-09 21:00:00');

        $delta = (int) $wpdb->num_queries - $before;

        // Guardrail #5: 1 multi-row INSERT + 1 read-back SELECT. The delta
        // must NOT scale with pair count (5 pairs here).
        $this->assertSame(2, $delta, "createItems issued {$delta} queries for 5 pairs; expected 2");
    }

    public function testCreateItemsWithEmptyPairsReturnsEmptyArrayWithoutQueries(): void
    {
        $jobId = $this->repo->createJob(1, 'plugin_update', 0, 0, '2026-06-09 21:00:00');

        global $wpdb;
        $before = (int) $wpdb->num_queries;
        $result = $this->repo->createItems($jobId, [], '2026-06-09 21:00:00');

        $this->assertSame([], $result);
        $this->assertSame($before, (int) $wpdb->num_queries);
    }

    public function testFindByIdForUserReturnsRow(): void
    {
        $jobId = $this->repo->createJob(7, 'plugin_update', 1, 0, '2026-06-09 21:00:00');

        $row = $this->repo->findByIdForUser($jobId, 7);

        $this->assertNotNull($row);
        $this->assertSame((string) $jobId, $row['id']);
        $this->assertSame('plugin_update', $row['kind']);
    }

    public function testFindByIdForUserReturnsNullForForeignUser(): void
    {
        $jobId = $this->repo->createJob(7, 'plugin_update', 1, 0, '2026-06-09 21:00:00');

        $this->assertNull($this->repo->findByIdForUser($jobId, 8)); // guardrail #7
        $this->assertNull($this->repo->findByIdForUser(999999, 7));
    }

    public function testFindItemsForJobReturnsRowsInIdOrder(): void
    {
        $jobId = $this->repo->createJob(1, 'plugin_update', 3, 0, '2026-06-09 21:00:00');
        $this->repo->createItems($jobId, [
            ['site_id' => 1, 'slug' => 'a'],
            ['site_id' => 1, 'slug' => 'b'],
            ['site_id' => 1, 'slug' => 'c'],
        ], '2026-06-09 21:00:00');

        $items = $this->repo->findItemsForJob($jobId);

        $this->assertCount(3, $items);
        $this->assertSame(['a', 'b', 'c'], array_column($items, 'resource_slug'));
    }

    public function testFindItemForJobScopesToJob(): void
    {
        $jobA   = $this->repo->createJob(1, 'plugin_update', 1, 0, '2026-06-09 21:00:00');
        $jobB   = $this->repo->createJob(1, 'plugin_update', 1, 0, '2026-06-09 21:00:00');
        $itemsA = $this->repo->createItems($jobA, [['site_id' => 1, 'slug' => 'a']], '2026-06-09 21:00:00');

        $this->assertNotNull($this->repo->findItemForJob($jobA, $itemsA[0]['item_id']));
        $this->assertNull($this->repo->findItemForJob($jobB, $itemsA[0]['item_id']));
        $this->assertNull($this->repo->findItemForJob($jobA, 999999));
    }
}
