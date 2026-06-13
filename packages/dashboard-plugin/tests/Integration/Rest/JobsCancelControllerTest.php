<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Services\BulkJobsRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

/**
 * P2.9 — Tests for POST /defyn/v1/jobs/{id}/cancel.
 *
 * @group integration
 */
final class JobsCancelControllerTest extends AbstractSchemaTestCase
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
            delete_transient("defyn_rl_jobsCancel_{$i}");
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

    private function cancelRequest(int $jobId, string $token): WP_REST_Request
    {
        $request = new WP_REST_Request('POST', "/defyn/v1/jobs/{$jobId}/cancel");
        $request->set_header('Authorization', 'Bearer ' . $token);
        return $request;
    }

    public function testAuthRequiredReturns401WhenNoBearerToken(): void
    {
        $response = rest_do_request(new WP_REST_Request('POST', '/defyn/v1/jobs/1/cancel'));
        $this->assertSame(401, $response->get_status());
    }

    public function testForeignJobReturns404NotFound(): void
    {
        $jobId = $this->repo->createJob(2, 'plugin_update', 1, 0, '2026-06-09 21:00:00');
        $this->repo->createItems($jobId, [['site_id' => 1, 'slug' => 'a']], '2026-06-09 21:00:00');

        $response = rest_do_request($this->cancelRequest($jobId, $this->token(1)));

        $this->assertSame(404, $response->get_status());
        $this->assertSame('jobs.not_found', $response->get_data()['error']['code'] ?? null);
    }

    public function testCancelUnschedulesQueuedItemsAndMarksThemCancelled(): void
    {
        $jobId = $this->repo->createJob(1, 'plugin_update', 3, 0, '2026-06-09 21:00:00');
        $items = $this->repo->createItems($jobId, [
            ['site_id' => 5, 'slug' => 'akismet'],
            ['site_id' => 5, 'slug' => 'yoast'],
            ['site_id' => 6, 'slug' => 'elementor'],
        ], '2026-06-09 21:00:00');

        // Schedule the matching AS actions exactly like the bulk controller does (4-tuple + 'defyn' group).
        foreach ($items as $item) {
            as_schedule_single_action(
                time() + 60,
                'defyn_update_site_plugin',
                [$item['site_id'], $item['slug'], 0, $item['item_id']],
                'defyn'
            );
        }
        // Third item is already running — must NOT be cancellable.
        $this->repo->markItemStarted($items[2]['item_id'], '2026-06-09 21:01:00');

        $response = rest_do_request($this->cancelRequest($jobId, $this->token(1)));
        $body     = $response->get_data();

        $this->assertSame(200, $response->get_status(), 'cancel is synchronous — 200, not 202 (guardrail #13)');
        $this->assertSame(2, $body['cancelled_count']);
        $this->assertSame(1, $body['still_running_count']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $body['cancelled_at']);

        global $wpdb;
        $states = $wpdb->get_col($wpdb->prepare(
            "SELECT state FROM {$wpdb->prefix}defyn_bulk_job_items WHERE job_id = %d ORDER BY id ASC",
            $jobId
        ));
        $this->assertSame(['cancelled', 'cancelled', 'started'], $states);

        // Guardrail #4 — the EXACT 4-tuples were unscheduled.
        foreach ([0, 1] as $i) {
            $pending = as_get_scheduled_actions([
                'hook'   => 'defyn_update_site_plugin',
                'args'   => [$items[$i]['site_id'], $items[$i]['slug'], 0, $items[$i]['item_id']],
                'status' => \ActionScheduler_Store::STATUS_PENDING,
            ]);
            $this->assertCount(0, $pending, "queued item #{$i} should be unscheduled");
        }
        $stillPending = as_get_scheduled_actions([
            'hook'   => 'defyn_update_site_plugin',
            'args'   => [$items[2]['site_id'], $items[2]['slug'], 0, $items[2]['item_id']],
            'status' => \ActionScheduler_Store::STATUS_PENDING,
        ]);
        $this->assertCount(1, $stillPending, 'started item keeps its AS action');
    }

    public function testCancelOnFinishedJobIsIdempotentNoOp(): void
    {
        $jobId = $this->repo->createJob(1, 'theme_update', 1, 0, '2026-06-09 21:00:00');
        $items = $this->repo->createItems($jobId, [['site_id' => 1, 'slug' => 'astra']], '2026-06-09 21:00:00');
        $this->repo->markItemStarted($items[0]['item_id'], '2026-06-09 21:01:00');
        $this->repo->markItemSucceeded($items[0]['item_id'], '2026-06-09 21:02:00');

        $response = rest_do_request($this->cancelRequest($jobId, $this->token(1)));
        $body     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame(0, $body['cancelled_count']);
        $this->assertSame(0, $body['still_running_count']);
    }

    public function testRateLimit429AfterSixthCall(): void
    {
        $jobId = $this->repo->createJob(1, 'plugin_update', 1, 0, '2026-06-09 21:00:00');
        $this->repo->createItems($jobId, [['site_id' => 1, 'slug' => 'a']], '2026-06-09 21:00:00');
        $token = $this->token(1);

        for ($i = 1; $i <= 5; $i++) {
            $response = rest_do_request($this->cancelRequest($jobId, $token));
            $this->assertNotSame(429, $response->get_status(), "call #{$i} should not be 429");
        }

        $response = rest_do_request($this->cancelRequest($jobId, $token));
        $this->assertSame(429, $response->get_status(), 'call #6 should be 429');
        $this->assertSame('jobs.rate_limited', $response->get_data()['error']['code'] ?? null);
    }
}
