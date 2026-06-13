<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Services\BulkJobsRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

/**
 * P2.9 — Tests for GET /defyn/v1/jobs/{id}.
 *
 * @group integration
 */
final class JobsDetailControllerTest extends AbstractSchemaTestCase
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
        $this->freshlyActivate('defyn_sites');
        $this->freshlyActivate('defyn_site_plugins');
        $this->freshlyActivate('defyn_site_themes');

        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL
        $wpdb->query('SET autocommit = 1');
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_bulk_job_items");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_bulk_jobs");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_site_plugins");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_site_themes");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_sites");
        // phpcs:enable WordPress.DB.PreparedSQL

        for ($i = 1; $i <= 10; $i++) {
            delete_transient("defyn_rl_jobsDetail_{$i}");
        }

        do_action('rest_api_init');

        $this->repo = new BulkJobsRepository();
    }

    private function token(int $userId): string
    {
        return (new TokenService(DEFYN_JWT_SECRET))->issueAccess($userId);
    }

    private function detailRequest(int $jobId, string $token): WP_REST_Request
    {
        $request = new WP_REST_Request('GET', "/defyn/v1/jobs/{$jobId}");
        $request->set_header('Authorization', 'Bearer ' . $token);
        return $request;
    }

    private function seedSite(int $userId, string $label): int
    {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'    => $userId,
            'url'        => 'https://ex-' . uniqid() . '.com',
            'label'      => $label,
            'status'     => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return (int) $wpdb->insert_id;
    }

    private function seedPlugin(int $siteId, string $slug, string $name, string $version, ?string $updateVersion): void
    {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert($wpdb->prefix . 'defyn_site_plugins', [
            'site_id'          => $siteId,
            'slug'             => $slug,
            'name'             => $name,
            'version'          => $version,
            'update_available' => $updateVersion !== null ? 1 : 0,
            'update_version'   => $updateVersion,
            'last_seen_at'     => $now,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
    }

    private function seedTheme(int $siteId, string $slug, string $name, string $version, ?string $updateVersion): void
    {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert($wpdb->prefix . 'defyn_site_themes', [
            'site_id'          => $siteId,
            'slug'             => $slug,
            'name'             => $name,
            'version'          => $version,
            'update_available' => $updateVersion !== null ? 1 : 0,
            'update_version'   => $updateVersion,
            'last_seen_at'     => $now,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
    }

    public function testAuthRequiredReturns401WhenNoBearerToken(): void
    {
        $response = rest_do_request(new WP_REST_Request('GET', '/defyn/v1/jobs/1'));
        $this->assertSame(401, $response->get_status());
    }

    public function testMissingJobReturns404NotFound(): void
    {
        $response = rest_do_request($this->detailRequest(999999, $this->token(1)));

        $this->assertSame(404, $response->get_status());
        $this->assertSame('jobs.not_found', $response->get_data()['error']['code'] ?? null);
    }

    public function testForeignJobReturns404NotFound(): void
    {
        $jobId = $this->repo->createJob(2, 'plugin_update', 1, 0, '2026-06-09 21:00:00');
        $this->repo->createItems($jobId, [['site_id' => 1, 'slug' => 'a']], '2026-06-09 21:00:00');

        // Guardrail #7: foreign job is indistinguishable from missing (404, not 403).
        $response = rest_do_request($this->detailRequest($jobId, $this->token(1)));

        $this->assertSame(404, $response->get_status());
        $this->assertSame('jobs.not_found', $response->get_data()['error']['code'] ?? null);
    }

    public function testHappyPathPluginJobResolvesResourceFields(): void
    {
        $siteId = $this->seedSite(1, 'SmartCoding');
        $this->seedPlugin($siteId, 'akismet', 'Akismet Anti-Spam', '5.3', '5.3.1');

        $jobId = $this->repo->createJob(1, 'plugin_update', 1, 0, '2026-06-09 20:59:15');
        $items = $this->repo->createItems($jobId, [['site_id' => $siteId, 'slug' => 'akismet']], '2026-06-09 20:59:15');
        $this->repo->markItemStarted($items[0]['item_id'], '2026-06-09 21:00:02');
        $this->repo->markItemSucceeded($items[0]['item_id'], '2026-06-09 21:00:11');

        $response = rest_do_request($this->detailRequest($jobId, $this->token(1)));
        $body     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame($jobId, $body['job']['id']);
        $this->assertSame('completed', $body['job']['state']);
        $this->assertSame(1, $body['job']['succeeded_count']);

        $item = $body['items'][0];
        $this->assertSame($siteId, $item['site_id']);
        $this->assertSame('SmartCoding', $item['site_label']);
        $this->assertSame('akismet', $item['resource_slug']);
        $this->assertSame('Akismet Anti-Spam', $item['resource_name']);
        $this->assertSame('5.3', $item['current_version']);
        $this->assertSame('5.3.1', $item['target_version']);
        $this->assertSame('succeeded', $item['state']);
        $this->assertNull($item['error_message']);
        $this->assertSame('2026-06-09 21:00:02', $item['started_at']);
        $this->assertSame('2026-06-09 21:00:11', $item['completed_at']);
    }

    public function testDeletedResourceAndSiteFallBackToSlugAndPlaceholderLabel(): void
    {
        // NO site row + NO plugin row seeded — both LEFT JOINs miss.
        $jobId = $this->repo->createJob(1, 'plugin_update', 1, 0, '2026-06-09 21:00:00');
        $this->repo->createItems($jobId, [['site_id' => 42, 'slug' => 'ghost-plugin']], '2026-06-09 21:00:00');

        $body = rest_do_request($this->detailRequest($jobId, $this->token(1)))->get_data();
        $item = $body['items'][0];

        $this->assertSame('ghost-plugin', $item['resource_name'], 'falls back to slug per spec § 2.2');
        $this->assertNull($item['current_version']);
        $this->assertNull($item['target_version']);
        $this->assertSame('Site #42', $item['site_label']);
    }

    public function testThemeJobResolvesAgainstThemesTable(): void
    {
        $siteId = $this->seedSite(1, 'AcmeBlog');
        $this->seedTheme($siteId, 'astra', 'Astra', '4.6.3', '4.7.0');

        $jobId = $this->repo->createJob(1, 'theme_update', 1, 0, '2026-06-09 21:00:00');
        $this->repo->createItems($jobId, [['site_id' => $siteId, 'slug' => 'astra']], '2026-06-09 21:00:00');

        $body = rest_do_request($this->detailRequest($jobId, $this->token(1)))->get_data();
        $item = $body['items'][0];

        $this->assertSame('Astra', $item['resource_name']);
        $this->assertSame('4.6.3', $item['current_version']);
        $this->assertSame('4.7.0', $item['target_version']);
        $this->assertSame('queued', $item['state']);
    }
}
