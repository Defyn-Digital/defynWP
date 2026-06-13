<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Services\BulkJobsRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

/**
 * P2.9 — Tests for POST /jobs/{id}/items/{item_id}/retry +
 * POST /jobs/{id}/retry-failed.
 *
 * @group integration
 */
final class JobsRetryControllersTest extends AbstractSchemaTestCase
{
    private BulkJobsRepository $repo;

    public function setUp(): void
    {
        parent::setUp();
        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
        $this->freshlyActivate('defyn_bulk_jobs');
        $this->freshlyActivate('defyn_bulk_job_items');

        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL
        $wpdb->query('SET autocommit = 1');
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_bulk_job_items");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_bulk_jobs");
        // phpcs:enable WordPress.DB.PreparedSQL

        for ($i = 1; $i <= 10; $i++) {
            delete_transient("defyn_rl_jobsRetryItem_{$i}");
            delete_transient("defyn_rl_jobsRetryFailed_{$i}");
        }

        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('defyn_update_site_plugin');
            as_unschedule_all_actions('defyn_update_site_theme');
        }

        do_action('rest_api_init');

        $this->repo = new BulkJobsRepository();
    }

    private function token(int $userId): string
    {
        return (new TokenService(DEFYN_JWT_SECRET))->issueAccess($userId);
    }

    private function post(string $path, string $token): WP_REST_Request
    {
        $request = new WP_REST_Request('POST', $path);
        $request->set_header('Authorization', 'Bearer ' . $token);
        return $request;
    }

    /** @return array{0: int, 1: list<array{site_id: int, slug: string, item_id: int}>} */
    private function jobWithFailedItem(string $kind = 'plugin_update'): array
    {
        $jobId = $this->repo->createJob(1, $kind, 2, 0, '2026-06-09 21:00:00');
        $items = $this->repo->createItems($jobId, [
            ['site_id' => 5, 'slug' => 'akismet'],
            ['site_id' => 5, 'slug' => 'yoast'],
        ], '2026-06-09 21:00:00');
        $this->repo->markItemStarted($items[0]['item_id'], '2026-06-09 21:01:00');
        $this->repo->markItemFailed($items[0]['item_id'], '2026-06-09 21:02:00', 'boom');
        return [$jobId, $items];
    }

    public function testAuthRequiredReturns401OnBothEndpoints(): void
    {
        $this->assertSame(401, rest_do_request(new WP_REST_Request('POST', '/defyn/v1/jobs/1/items/1/retry'))->get_status());
        $this->assertSame(401, rest_do_request(new WP_REST_Request('POST', '/defyn/v1/jobs/1/retry-failed'))->get_status());
    }

    public function testRetryItemHappyPath202RequeuesAndReschedules(): void
    {
        [$jobId, $items] = $this->jobWithFailedItem();
        $failedId = $items[0]['item_id'];

        $response = rest_do_request($this->post("/defyn/v1/jobs/{$jobId}/items/{$failedId}/retry", $this->token(1)));
        $body     = $response->get_data();

        $this->assertSame(202, $response->get_status(), 'retry is async re-queue — 202 (guardrail #13)');
        $this->assertSame($failedId, $body['item_id']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $body['scheduled_at']);

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}defyn_bulk_job_items WHERE id = %d", $failedId),
            ARRAY_A
        );
        $this->assertSame('queued', $row['state']);
        $this->assertNull($row['error_message']);
        $this->assertNull($row['started_at']);
        $this->assertNull($row['completed_at']);

        // Re-scheduled with the same 4-tuple shape the bulk fan-out uses.
        $pending = as_get_scheduled_actions([
            'hook'   => 'defyn_update_site_plugin',
            'args'   => [5, 'akismet', 0, $failedId],
            'status' => \ActionScheduler_Store::STATUS_PENDING,
        ]);
        $this->assertCount(1, $pending);
    }

    public function testRetryItemReturns400WhenItemNotFailed(): void
    {
        [$jobId, $items] = $this->jobWithFailedItem();
        $queuedId = $items[1]['item_id']; // still queued

        $response = rest_do_request($this->post("/defyn/v1/jobs/{$jobId}/items/{$queuedId}/retry", $this->token(1)));

        $this->assertSame(400, $response->get_status());
        $this->assertSame('jobs.item_not_retryable', $response->get_data()['error']['code'] ?? null);
    }

    public function testRetryItemReturns404ForMissingItemAndForeignJob(): void
    {
        [$jobId] = $this->jobWithFailedItem();

        $missing = rest_do_request($this->post("/defyn/v1/jobs/{$jobId}/items/999999/retry", $this->token(1)));
        $this->assertSame(404, $missing->get_status());
        $this->assertSame('jobs.item_not_found', $missing->get_data()['error']['code'] ?? null);

        $foreign = rest_do_request($this->post("/defyn/v1/jobs/{$jobId}/items/1/retry", $this->token(2)));
        $this->assertSame(404, $foreign->get_status());
        $this->assertSame('jobs.not_found', $foreign->get_data()['error']['code'] ?? null);
    }

    public function testRetryFailedHappyPath202RetriesAllFailedItems(): void
    {
        $jobId = $this->repo->createJob(1, 'theme_update', 3, 0, '2026-06-09 21:00:00');
        $items = $this->repo->createItems($jobId, [
            ['site_id' => 7, 'slug' => 'astra'],
            ['site_id' => 7, 'slug' => 'blocksy'],
            ['site_id' => 8, 'slug' => 'kadence'],
        ], '2026-06-09 21:00:00');
        foreach ([0, 1] as $i) {
            $this->repo->markItemStarted($items[$i]['item_id'], '2026-06-09 21:01:00');
            $this->repo->markItemFailed($items[$i]['item_id'], '2026-06-09 21:02:00', 'boom');
        }
        $this->repo->markItemStarted($items[2]['item_id'], '2026-06-09 21:01:00');
        $this->repo->markItemSucceeded($items[2]['item_id'], '2026-06-09 21:02:00');

        $response = rest_do_request($this->post("/defyn/v1/jobs/{$jobId}/retry-failed", $this->token(1)));
        $body     = $response->get_data();

        $this->assertSame(202, $response->get_status());
        $this->assertSame(2, $body['retried_count']);
        $this->assertSame([$items[0]['item_id'], $items[1]['item_id']], $body['retried_item_ids']);

        // Theme-kind job re-schedules the THEME hook with each item's 4-tuple.
        foreach ([0, 1] as $i) {
            $pending = as_get_scheduled_actions([
                'hook'   => 'defyn_update_site_theme',
                'args'   => [$items[$i]['site_id'], $items[$i]['slug'], 0, $items[$i]['item_id']],
                'status' => \ActionScheduler_Store::STATUS_PENDING,
            ]);
            $this->assertCount(1, $pending);
        }

        global $wpdb;
        $states = $wpdb->get_col($wpdb->prepare(
            "SELECT state FROM {$wpdb->prefix}defyn_bulk_job_items WHERE job_id = %d ORDER BY id ASC",
            $jobId
        ));
        $this->assertSame(['queued', 'queued', 'succeeded'], $states);
    }

    public function testRetryFailedNoOpReturns200WhenNothingFailed(): void
    {
        $jobId = $this->repo->createJob(1, 'plugin_update', 1, 0, '2026-06-09 21:00:00');
        $items = $this->repo->createItems($jobId, [['site_id' => 1, 'slug' => 'a']], '2026-06-09 21:00:00');
        $this->repo->markItemStarted($items[0]['item_id'], '2026-06-09 21:01:00');
        $this->repo->markItemSucceeded($items[0]['item_id'], '2026-06-09 21:02:00');

        $response = rest_do_request($this->post("/defyn/v1/jobs/{$jobId}/retry-failed", $this->token(1)));
        $body     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame(0, $body['retried_count']);
        $this->assertSame([], $body['retried_item_ids']);
    }

    public function testRetryFailedReturns404ForForeignJob(): void
    {
        [$jobId] = $this->jobWithFailedItem();

        $response = rest_do_request($this->post("/defyn/v1/jobs/{$jobId}/retry-failed", $this->token(2)));

        $this->assertSame(404, $response->get_status());
        $this->assertSame('jobs.not_found', $response->get_data()['error']['code'] ?? null);
    }
}
