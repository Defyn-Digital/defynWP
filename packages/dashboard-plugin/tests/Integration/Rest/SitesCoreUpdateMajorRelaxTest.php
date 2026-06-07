<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

/**
 * P2.4.1 — Verify preflight #4 respects the per-site coreAllowMajor flag.
 *
 * @group integration
 */
final class SitesCoreUpdateMajorRelaxTest extends AbstractSchemaTestCase
{
    private int $userId;
    private string $token;

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
        $this->token  = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($this->userId);
    }

    public function testMajorBumpProceedsWhenAllowMajorFlagIsOn(): void
    {
        $scheduled = [];
        \add_filter('pre_as_schedule_single_action', function ($pre, $when, $hook, $args) use (&$scheduled) {
            $scheduled[] = ['hook' => $hook, 'args' => $args];
            return 999;
        }, 10, 4);

        $siteId = $this->seedSiteWithMajorUpdate();
        (new SitesRepository())->setCoreAllowMajor($siteId, true);

        $response = rest_do_request($this->signed('POST', "/defyn/v1/sites/{$siteId}/core/update"));

        $this->assertSame(202, $response->get_status());
        $this->assertTrue($response->get_data()['scheduled']);
    }

    public function testMajorBumpStillReturns409WhenFlagIsOff(): void
    {
        $siteId = $this->seedSiteWithMajorUpdate();
        // Flag is OFF by default — no setCoreAllowMajor call.

        $response = rest_do_request($this->signed('POST', "/defyn/v1/sites/{$siteId}/core/update"));

        $this->assertSame(409, $response->get_status());
        $this->assertSame('core.major_update_blocked', $response->get_data()['error']['code']);
    }

    private function seedSiteWithMajorUpdate(): int
    {
        global $wpdb;
        $wpdb->insert(SitesTable::tableName(), [
            'user_id'                => $this->userId,
            'url'                    => 'https://example.com',
            'label'                  => 'Example',
            'status'                 => 'active',
            'our_private_key'        => '',
            'wp_version'             => '7.4',
            'php_version'            => '8.3.31',
            'plugin_counts'          => '{"installed":0,"active":0}',
            'theme_counts'           => '{"installed":0,"active":0}',
            'ssl_status'             => 'enabled',
            'ssl_expires_at'         => null,
            'last_sync_at'           => '2026-06-07 04:00:00',
            'last_contact_at'        => '2026-06-07 04:00:00',
            'created_at'             => gmdate('Y-m-d H:i:s'),
            'updated_at'             => gmdate('Y-m-d H:i:s'),
            'core_update_available'  => 1,
            'core_update_version'    => '8.0',
            'core_update_state'      => 'idle',
            'last_core_update_error' => null,
        ]);
        return (int) $wpdb->insert_id;
    }

    private function signed(string $method, string $path): WP_REST_Request
    {
        $request = new WP_REST_Request($method, $path);
        $request->set_header('Authorization', 'Bearer ' . $this->token);
        return $request;
    }
}
