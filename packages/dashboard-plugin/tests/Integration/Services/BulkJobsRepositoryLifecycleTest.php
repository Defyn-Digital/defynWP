<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\BulkJobsRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * P2.9 — lifecycle marks + refreshJobTimestamps + list filters.
 *
 * @group integration
 */
final class BulkJobsRepositoryLifecycleTest extends AbstractSchemaTestCase
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

    /**
     * @param list<array{site_id: int, slug: string}> $pairs
     * @return array{0: int, 1: list<array{site_id: int, slug: string, item_id: int}>}
     */
    private function makeJobWithItems(int $userId, string $kind, array $pairs, string $createdAt = '2026-06-09 21:00:00'): array
    {
        $jobId = $this->repo->createJob($userId, $kind, count($pairs), 0, $createdAt);
        $items = $this->repo->createItems($jobId, $pairs, $createdAt);
        return [$jobId, $items];
    }

    private function itemRow(int $itemId): array
    {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}defyn_bulk_job_items WHERE id = %d", $itemId),
            ARRAY_A
        );
    }

    private function jobRow(int $jobId): array
    {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}defyn_bulk_jobs WHERE id = %d", $jobId),
            ARRAY_A
        );
    }

    public function testMarkItemStartedTransitionsStateSetsStartedAtAndJobStartedAt(): void
    {
        [$jobId, $items] = $this->makeJobWithItems(1, 'plugin_update', [['site_id' => 1, 'slug' => 'a']]);
        $itemId = $items[0]['item_id'];

        $this->assertNull($this->jobRow($jobId)['started_at']);

        $this->repo->markItemStarted($itemId, '2026-06-09 21:01:00');

        $item = $this->itemRow($itemId);
        $this->assertSame('started', $item['state']);
        $this->assertSame('2026-06-09 21:01:00', $item['started_at']);
        $this->assertSame('2026-06-09 21:01:00', $this->jobRow($jobId)['started_at']);

        // No-op from a terminal state (retry re-entries are already-started
        // and terminal items must not be revived).
        $this->repo->markItemSucceeded($itemId, '2026-06-09 21:02:00');
        $this->repo->markItemStarted($itemId, '2026-06-09 21:03:00');
        $this->assertSame('succeeded', $this->itemRow($itemId)['state']);
    }

    public function testMarkItemSucceededSetsCompletedAt(): void
    {
        [, $items] = $this->makeJobWithItems(1, 'plugin_update', [['site_id' => 1, 'slug' => 'a']]);
        $itemId = $items[0]['item_id'];

        $this->repo->markItemStarted($itemId, '2026-06-09 21:01:00');
        $this->repo->markItemSucceeded($itemId, '2026-06-09 21:02:00');

        $item = $this->itemRow($itemId);
        $this->assertSame('succeeded', $item['state']);
        $this->assertSame('2026-06-09 21:02:00', $item['completed_at']);
        $this->assertNull($item['error_message']);
    }

    public function testMarkItemFailedSetsErrorMessageAndTruncatesTo1000Chars(): void
    {
        [, $items] = $this->makeJobWithItems(1, 'plugin_update', [['site_id' => 1, 'slug' => 'a']]);
        $itemId = $items[0]['item_id'];

        $this->repo->markItemStarted($itemId, '2026-06-09 21:01:00');
        $this->repo->markItemFailed($itemId, '2026-06-09 21:02:00', str_repeat('x', 1500));

        $item = $this->itemRow($itemId);
        $this->assertSame('failed', $item['state']);
        $this->assertSame('2026-06-09 21:02:00', $item['completed_at']);
        $this->assertSame(1000, strlen($item['error_message']));
    }

    public function testMarkItemCancelledOnlyAllowedFromQueued(): void
    {
        [, $items] = $this->makeJobWithItems(1, 'plugin_update', [
            ['site_id' => 1, 'slug' => 'a'],
            ['site_id' => 1, 'slug' => 'b'],
        ]);

        // queued → cancelled OK
        $this->repo->markItemCancelled($items[0]['item_id'], '2026-06-09 21:01:00');
        $this->assertSame('cancelled', $this->itemRow($items[0]['item_id'])['state']);

        // started → cancel is a silent no-op (guardrail #6)
        $this->repo->markItemStarted($items[1]['item_id'], '2026-06-09 21:01:00');
        $this->repo->markItemCancelled($items[1]['item_id'], '2026-06-09 21:02:00');
        $this->assertSame('started', $this->itemRow($items[1]['item_id'])['state']);
    }

    public function testResetItemForRetryRequeuesFailedItemAndIgnoresOthers(): void
    {
        [, $items] = $this->makeJobWithItems(1, 'plugin_update', [
            ['site_id' => 1, 'slug' => 'a'],
            ['site_id' => 1, 'slug' => 'b'],
        ]);
        $failedId    = $items[0]['item_id'];
        $succeededId = $items[1]['item_id'];

        $this->repo->markItemStarted($failedId, '2026-06-09 21:01:00');
        $this->repo->markItemFailed($failedId, '2026-06-09 21:02:00', 'boom');
        $this->repo->markItemStarted($succeededId, '2026-06-09 21:01:00');
        $this->repo->markItemSucceeded($succeededId, '2026-06-09 21:02:00');

        $this->repo->resetItemForRetry($failedId, '2026-06-09 21:05:00');

        $item = $this->itemRow($failedId);
        $this->assertSame('queued', $item['state']);
        $this->assertNull($item['error_message']);
        $this->assertNull($item['started_at']);
        $this->assertNull($item['completed_at']);

        // succeeded item is NOT resettable
        $this->repo->resetItemForRetry($succeededId, '2026-06-09 21:05:00');
        $this->assertSame('succeeded', $this->itemRow($succeededId)['state']);
    }

    public function testRefreshJobTimestampsSetsCompletedAtWhenAllTerminal(): void
    {
        [$jobId, $items] = $this->makeJobWithItems(1, 'plugin_update', [
            ['site_id' => 1, 'slug' => 'a'],
            ['site_id' => 1, 'slug' => 'b'],
        ]);

        $this->repo->markItemStarted($items[0]['item_id'], '2026-06-09 21:01:00');
        $this->repo->markItemSucceeded($items[0]['item_id'], '2026-06-09 21:02:00');
        $this->assertNull($this->jobRow($jobId)['completed_at'], 'one item still queued — not complete');

        $this->repo->markItemStarted($items[1]['item_id'], '2026-06-09 21:03:00');
        $this->repo->markItemFailed($items[1]['item_id'], '2026-06-09 21:04:00', 'boom');

        $this->assertSame('2026-06-09 21:04:00', $this->jobRow($jobId)['completed_at']);
    }

    public function testResetItemForRetryClearsJobCompletedAt(): void
    {
        [$jobId, $items] = $this->makeJobWithItems(1, 'plugin_update', [['site_id' => 1, 'slug' => 'a']]);
        $itemId = $items[0]['item_id'];

        $this->repo->markItemStarted($itemId, '2026-06-09 21:01:00');
        $this->repo->markItemFailed($itemId, '2026-06-09 21:02:00', 'boom');
        $this->assertNotNull($this->jobRow($jobId)['completed_at']);

        $this->repo->resetItemForRetry($itemId, '2026-06-09 21:05:00');

        $this->assertNull($this->jobRow($jobId)['completed_at'], 'guardrail #8 — retry clears completed_at');
    }

    public function testFindAllForUserWithStatusFilterActive(): void
    {
        [$activeJob] = $this->makeJobWithItems(1, 'plugin_update', [['site_id' => 1, 'slug' => 'a']]);
        [$doneJob, $doneItems] = $this->makeJobWithItems(1, 'plugin_update', [['site_id' => 1, 'slug' => 'b']]);
        $this->repo->markItemStarted($doneItems[0]['item_id'], '2026-06-09 21:01:00');
        $this->repo->markItemSucceeded($doneItems[0]['item_id'], '2026-06-09 21:02:00');

        $rows = $this->repo->findAllForUser(1, 'active', 20, 0);

        $this->assertCount(1, $rows);
        $this->assertSame((string) $activeJob, $rows[0]['id']);
        $this->assertNotSame((string) $doneJob, $rows[0]['id']);
    }

    public function testFindAllForUserWithStatusFilterCompleted(): void
    {
        $this->makeJobWithItems(1, 'plugin_update', [['site_id' => 1, 'slug' => 'a']]);
        [$doneJob, $doneItems] = $this->makeJobWithItems(1, 'plugin_update', [['site_id' => 1, 'slug' => 'b']]);
        $this->repo->markItemStarted($doneItems[0]['item_id'], '2026-06-09 21:01:00');
        $this->repo->markItemSucceeded($doneItems[0]['item_id'], '2026-06-09 21:02:00');

        $rows = $this->repo->findAllForUser(1, 'completed', 20, 0);

        $this->assertCount(1, $rows);
        $this->assertSame((string) $doneJob, $rows[0]['id']);
    }

    public function testFindAllForUserOrdersNewestFirstPaginatesAndScopesToUser(): void
    {
        [$oldJob] = $this->makeJobWithItems(1, 'plugin_update', [['site_id' => 1, 'slug' => 'a']], '2026-06-09 20:00:00');
        [$newJob] = $this->makeJobWithItems(1, 'theme_update', [['site_id' => 1, 'slug' => 'b']], '2026-06-09 22:00:00');
        $this->makeJobWithItems(2, 'plugin_update', [['site_id' => 9, 'slug' => 'x']]); // foreign user

        $pageOne = $this->repo->findAllForUser(1, null, 1, 0);
        $pageTwo = $this->repo->findAllForUser(1, null, 1, 1);

        $this->assertSame((string) $newJob, $pageOne[0]['id']);
        $this->assertSame((string) $oldJob, $pageTwo[0]['id']);
        $this->assertCount(2, $this->repo->findAllForUser(1, null, 20, 0));
    }

    public function testCountAllForUserMatchesFilters(): void
    {
        $this->makeJobWithItems(1, 'plugin_update', [['site_id' => 1, 'slug' => 'a']]);
        [, $doneItems] = $this->makeJobWithItems(1, 'plugin_update', [['site_id' => 1, 'slug' => 'b']]);
        $this->repo->markItemStarted($doneItems[0]['item_id'], '2026-06-09 21:01:00');
        $this->repo->markItemSucceeded($doneItems[0]['item_id'], '2026-06-09 21:02:00');

        $this->assertSame(2, $this->repo->countAllForUser(1, null));
        $this->assertSame(1, $this->repo->countAllForUser(1, 'active'));
        $this->assertSame(1, $this->repo->countAllForUser(1, 'completed'));
        $this->assertSame(0, $this->repo->countAllForUser(2, null));
    }

    public function testFindQueuedItemsForJobReturnsItemIdSiteIdSlug(): void
    {
        [$jobId, $items] = $this->makeJobWithItems(1, 'plugin_update', [
            ['site_id' => 3, 'slug' => 'akismet'],
            ['site_id' => 4, 'slug' => 'yoast'],
        ]);
        $this->repo->markItemStarted($items[1]['item_id'], '2026-06-09 21:01:00');

        $queued = $this->repo->findQueuedItemsForJob($jobId);

        $this->assertSame([
            ['item_id' => $items[0]['item_id'], 'site_id' => 3, 'slug' => 'akismet'],
        ], $queued);
    }

    public function testCountItemsByStateForJobAndGroupedCounts(): void
    {
        [$jobA, $itemsA] = $this->makeJobWithItems(1, 'plugin_update', [
            ['site_id' => 1, 'slug' => 'a'],
            ['site_id' => 1, 'slug' => 'b'],
        ]);
        [$jobB] = $this->makeJobWithItems(1, 'theme_update', [['site_id' => 2, 'slug' => 'c']]);
        $this->repo->markItemStarted($itemsA[0]['item_id'], '2026-06-09 21:01:00');
        $this->repo->markItemSucceeded($itemsA[0]['item_id'], '2026-06-09 21:02:00');

        $this->assertSame(1, $this->repo->countItemsByStateForJob($jobA, 'queued'));
        $this->assertSame(1, $this->repo->countItemsByStateForJob($jobA, 'succeeded'));
        $this->assertSame(0, $this->repo->countItemsByStateForJob($jobA, 'started'));

        global $wpdb;
        $before  = (int) $wpdb->num_queries;
        $grouped = $this->repo->countsByStateForJobs([$jobA, $jobB]);
        $this->assertSame(1, (int) $wpdb->num_queries - $before, 'grouped counts must be ONE query');

        $this->assertSame(1, $grouped[$jobA]['queued']);
        $this->assertSame(1, $grouped[$jobA]['succeeded']);
        $this->assertSame(1, $grouped[$jobB]['queued']);
        $this->assertSame([], $this->repo->countsByStateForJobs([]));
    }
}
