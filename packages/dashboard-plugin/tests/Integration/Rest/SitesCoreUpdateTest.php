<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Rest\Middleware\RateLimit;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

/**
 * @group integration
 */
final class SitesCoreUpdateTest extends AbstractSchemaTestCase
{
    private int $userId;
    private string $token;
    private int $siteId;

    protected function setUp(): void
    {
        parent::setUp();
        \Defyn\Dashboard\Activation::activate();

        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_defyn_rl_%' OR option_name LIKE '_transient_timeout_defyn_rl_%'");

        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }

        do_action('rest_api_init');

        $this->userId = self::factory()->user->create();
        $this->token = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($this->userId);

        $wpdb->insert(SitesTable::tableName(), [
            'user_id'                => $this->userId,
            'url'                    => 'https://smartcoding.test',
            'label'                  => 'Smart',
            'status'                 => 'active',
            'our_private_key'        => '',
            'wp_version'             => '7.0',
            'php_version'            => '8.3.31',
            'plugin_counts'          => '{"installed":0,"active":0}',
            'theme_counts'           => '{"installed":0,"active":0}',
            'ssl_status'             => 'enabled',
            'ssl_expires_at'         => null,
            'last_sync_at'           => '2026-06-07 04:00:00',
            'last_contact_at'        => '2026-06-07 04:00:00',
            'created_at'             => '2026-06-07 00:00:00',
            'updated_at'             => '2026-06-07 04:00:00',
            'core_update_available'  => 1,
            'core_update_version'    => '7.0.1',
            'core_update_state'      => 'idle',
            'last_core_update_error' => null,
        ]);
        $this->siteId = (int) $wpdb->insert_id;
    }

    public function testHappyPathReturns202QueuedState(): void
    {
        $scheduled = [];
        \add_filter('pre_as_schedule_single_action', function ($pre, $when, $hook, $args) use (&$scheduled) {
            $scheduled[] = ['hook' => $hook, 'args' => $args];
            return 999;
        }, 10, 4);

        $response = rest_do_request($this->signed('POST', "/defyn/v1/sites/{$this->siteId}/core/update"));

        $this->assertSame(202, $response->get_status());
        $body = $response->get_data();
        $this->assertTrue($body['scheduled']);
        $this->assertSame($this->siteId, $body['site_id']);
        $this->assertSame('queued', $body['core_update_state']);

        $row = (new SitesRepository())->findById($this->siteId);
        $this->assertSame('queued', $row->coreUpdateState);

        $this->assertSame('defyn_update_site_core', $scheduled[0]['hook']);
        $this->assertSame([$this->siteId, 0], $scheduled[0]['args']);

        global $wpdb;
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}defyn_activity_log
                 WHERE event_type = 'core_update.requested' AND site_id = %d",
                $this->siteId
            )
        );
        $this->assertSame(1, $count);
    }

    public function testNotOwnedReturns404(): void
    {
        $response = rest_do_request($this->signed('POST', "/defyn/v1/sites/99999/core/update"));
        $this->assertSame(404, $response->get_status());
        $this->assertSame('sites.not_found', $response->get_data()['error']['code']);
    }

    public function testNoUpdateAvailableReturns409(): void
    {
        global $wpdb;
        $wpdb->update(SitesTable::tableName(),
            ['core_update_available' => 0, 'core_update_version' => null],
            ['id' => $this->siteId]);

        $response = rest_do_request($this->signed('POST', "/defyn/v1/sites/{$this->siteId}/core/update"));
        $this->assertSame(409, $response->get_status());
        $this->assertSame('core.no_update_available_for_site', $response->get_data()['error']['code']);
    }

    public function testUpdateInProgressReturns409(): void
    {
        global $wpdb;
        $wpdb->update(SitesTable::tableName(),
            ['core_update_state' => 'updating'],
            ['id' => $this->siteId]);

        $response = rest_do_request($this->signed('POST', "/defyn/v1/sites/{$this->siteId}/core/update"));
        $this->assertSame(409, $response->get_status());
        $this->assertSame('core.update_in_progress', $response->get_data()['error']['code']);
    }

    public function testMajorBumpReturns409DashboardSideFastFail(): void
    {
        global $wpdb;
        $wpdb->update(SitesTable::tableName(),
            ['wp_version' => '7.0', 'core_update_version' => '8.0'],
            ['id' => $this->siteId]);

        $scheduled = [];
        \add_filter('pre_as_schedule_single_action', function ($pre, $when, $hook, $args) use (&$scheduled) {
            $scheduled[] = ['hook' => $hook, 'args' => $args];
            return 999;
        }, 10, 4);

        $response = rest_do_request($this->signed('POST', "/defyn/v1/sites/{$this->siteId}/core/update"));

        $this->assertSame(409, $response->get_status());
        $this->assertSame('core.major_update_blocked', $response->get_data()['error']['code']);

        $this->assertEmpty($scheduled);

        $row = (new SitesRepository())->findById($this->siteId);
        $this->assertSame('idle', $row->coreUpdateState);
    }

    public function testRateLimit429AfterFourthCall(): void
    {
        global $wpdb;
        for ($i = 1; $i <= 3; $i++) {
            $res = rest_do_request($this->signed('POST', "/defyn/v1/sites/{$this->siteId}/core/update"));
            $this->assertSame(202, $res->get_status(), "call {$i} should pass");
            $wpdb->update(SitesTable::tableName(), ['core_update_state' => 'idle'], ['id' => $this->siteId]);
        }

        $res = rest_do_request($this->signed('POST', "/defyn/v1/sites/{$this->siteId}/core/update"));
        $this->assertSame(429, $res->get_status());
        $this->assertSame('core.rate_limited', $res->get_data()['error']['code']);
    }

    public function testCoreUpdateBucketSeparateFromPluginsUpdate(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $r = new WP_REST_Request('POST', "/defyn/v1/sites/{$this->siteId}/plugins/plugin-{$i}/update");
            $r->set_header('Authorization', 'Bearer ' . $this->token);
            $r->set_param('id', $this->siteId);
            $r->set_param('slug', 'plugin-' . $i);
            $r->set_param('_authenticated_user_id', $this->userId);
            $this->assertTrue(RateLimit::pluginsUpdate($r));
        }

        $r = new WP_REST_Request('POST', "/defyn/v1/sites/{$this->siteId}/core/update");
        $r->set_header('Authorization', 'Bearer ' . $this->token);
        $r->set_param('id', $this->siteId);
        $r->set_param('_authenticated_user_id', $this->userId);
        $this->assertTrue(RateLimit::coreUpdate($r));
    }

    private function signed(string $method, string $path): WP_REST_Request
    {
        $request = new WP_REST_Request($method, $path);
        $request->set_header('Authorization', 'Bearer ' . $this->token);
        return $request;
    }
}
