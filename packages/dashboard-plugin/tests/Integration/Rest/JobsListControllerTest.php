<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Services\BulkJobsRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

/**
 * P2.9 — Tests for GET /defyn/v1/jobs.
 *
 * @group integration
 */
final class JobsListControllerTest extends AbstractSchemaTestCase
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
            delete_transient("defyn_rl_jobsList_{$i}");
        }

        do_action('rest_api_init');

        $this->repo = new BulkJobsRepository();
    }

    private function token(int $userId): string
    {
        return (new TokenService(DEFYN_JWT_SECRET))->issueAccess($userId);
    }

    private function listRequest(string $token, array $query = []): WP_REST_Request
    {
        $request = new WP_REST_Request('GET', '/defyn/v1/jobs');
        $request->set_header('Authorization', 'Bearer ' . $token);
        if ($query !== []) {
            $request->set_query_params($query);
        }
        return $request;
    }

    public function testAuthRequiredReturns401WhenNoBearerToken(): void
    {
        $response = rest_do_request(new WP_REST_Request('GET', '/defyn/v1/jobs'));
        $this->assertSame(401, $response->get_status());
    }

    public function testEmptyListReturns200WithZeroTotal(): void
    {
        $response = rest_do_request($this->listRequest($this->token(1)));
        $body     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame([], $body['jobs']);
        $this->assertSame(0, $body['total']);
        $this->assertSame(1, $body['page']);
        $this->assertSame(20, $body['per_page']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $body['generated_at']);
    }

    public function testListReturnsJobsWithDerivedCountsAndState(): void
    {
        $jobId = $this->repo->createJob(1, 'plugin_update', 3, 1, '2026-06-09 21:00:00');
        $items = $this->repo->createItems($jobId, [
            ['site_id' => 1, 'slug' => 'a'],
            ['site_id' => 1, 'slug' => 'b'],
            ['site_id' => 2, 'slug' => 'c'],
        ], '2026-06-09 21:00:00');
        $this->repo->markItemStarted($items[0]['item_id'], '2026-06-09 21:01:00');
        $this->repo->markItemSucceeded($items[0]['item_id'], '2026-06-09 21:02:00');
        $this->repo->markItemStarted($items[1]['item_id'], '2026-06-09 21:01:00');
        $this->repo->markItemFailed($items[1]['item_id'], '2026-06-09 21:03:00', 'boom');

        $response = rest_do_request($this->listRequest($this->token(1)));
        $body     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame(1, $body['total']);
        $job = $body['jobs'][0];
        $this->assertSame($jobId, $job['id']);
        $this->assertSame('plugin_update', $job['kind']);
        $this->assertSame(3, $job['scheduled_count']);
        $this->assertSame(1, $job['skipped_count']);
        $this->assertSame(1, $job['succeeded_count']);
        $this->assertSame(1, $job['failed_count']);
        $this->assertSame(0, $job['cancelled_count']);
        $this->assertSame(1, $job['queued_count']);
        $this->assertSame(0, $job['started_count']);
        $this->assertSame('in_progress', $job['state']);
        $this->assertSame('2026-06-09 21:01:00', $job['started_at']);
        $this->assertNull($job['completed_at']);
        $this->assertSame('2026-06-09 21:00:00', $job['created_at']);
    }

    public function testStatusFilterActiveAndCompleted(): void
    {
        $activeJob = $this->repo->createJob(1, 'plugin_update', 1, 0, '2026-06-09 21:00:00');
        $this->repo->createItems($activeJob, [['site_id' => 1, 'slug' => 'a']], '2026-06-09 21:00:00');

        $doneJob   = $this->repo->createJob(1, 'theme_update', 1, 0, '2026-06-09 21:05:00');
        $doneItems = $this->repo->createItems($doneJob, [['site_id' => 1, 'slug' => 'b']], '2026-06-09 21:05:00');
        $this->repo->markItemStarted($doneItems[0]['item_id'], '2026-06-09 21:06:00');
        $this->repo->markItemSucceeded($doneItems[0]['item_id'], '2026-06-09 21:07:00');

        $token = $this->token(1);

        $active = rest_do_request($this->listRequest($token, ['status' => 'active']))->get_data();
        $this->assertSame(1, $active['total']);
        $this->assertSame($activeJob, $active['jobs'][0]['id']);

        $completed = rest_do_request($this->listRequest($token, ['status' => 'completed']))->get_data();
        $this->assertSame(1, $completed['total']);
        $this->assertSame($doneJob, $completed['jobs'][0]['id']);
        $this->assertSame('completed', $completed['jobs'][0]['state']);

        $all = rest_do_request($this->listRequest($token, ['status' => 'all']))->get_data();
        $this->assertSame(2, $all['total']);
    }

    public function testPaginationRespectsPageAndPerPage(): void
    {
        foreach (['20:00:00', '21:00:00', '22:00:00'] as $time) {
            $jobId = $this->repo->createJob(1, 'plugin_update', 1, 0, "2026-06-09 {$time}");
            $this->repo->createItems($jobId, [['site_id' => 1, 'slug' => 'a']], "2026-06-09 {$time}");
        }

        $token = $this->token(1);

        $pageOne = rest_do_request($this->listRequest($token, ['page' => '1', 'per_page' => '2']))->get_data();
        $this->assertCount(2, $pageOne['jobs']);
        $this->assertSame(3, $pageOne['total']);
        $this->assertSame('2026-06-09 22:00:00', $pageOne['jobs'][0]['created_at'], 'newest first');

        $pageTwo = rest_do_request($this->listRequest($token, ['page' => '2', 'per_page' => '2']))->get_data();
        $this->assertCount(1, $pageTwo['jobs']);
        $this->assertSame(2, $pageTwo['page']);
        $this->assertSame('2026-06-09 20:00:00', $pageTwo['jobs'][0]['created_at']);
    }

    public function testForeignUsersJobsExcluded(): void
    {
        $foreignJob = $this->repo->createJob(2, 'plugin_update', 1, 0, '2026-06-09 21:00:00');
        $this->repo->createItems($foreignJob, [['site_id' => 9, 'slug' => 'x']], '2026-06-09 21:00:00');

        $body = rest_do_request($this->listRequest($this->token(1)))->get_data();

        $this->assertSame(0, $body['total']);
        $this->assertSame([], $body['jobs']);
    }

    public function testRateLimit429AfterThirtyFirstCall(): void
    {
        $token = $this->token(1);

        for ($i = 1; $i <= 30; $i++) {
            $response = rest_do_request($this->listRequest($token));
            $this->assertSame(200, $response->get_status(), "call #{$i} should be 200");
        }

        $response = rest_do_request($this->listRequest($token));
        $this->assertSame(429, $response->get_status(), 'call #31 should be 429');
        $this->assertSame('jobs.rate_limited', $response->get_data()['error']['code'] ?? null);
    }
}
