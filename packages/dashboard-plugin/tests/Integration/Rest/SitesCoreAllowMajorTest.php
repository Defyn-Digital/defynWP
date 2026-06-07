<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

/**
 * P2.4.1 — POST /defyn/v1/sites/{id}/core/allow-major.
 *
 * @group integration
 */
final class SitesCoreAllowMajorTest extends AbstractSchemaTestCase
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

    public function testHappyPath200OnEnable(): void
    {
        $siteId = $this->seedSite($this->userId);

        $response = rest_do_request($this->buildRequest($siteId, ['allow' => true]));

        $this->assertSame(200, $response->get_status());
        $body = $response->get_data();
        $this->assertSame($siteId, $body['site_id']);
        $this->assertTrue($body['core_allow_major']);
    }

    public function testHappyPath200OnDisable(): void
    {
        $siteId = $this->seedSite($this->userId);

        rest_do_request($this->buildRequest($siteId, ['allow' => true]));
        $response = rest_do_request($this->buildRequest($siteId, ['allow' => false]));

        $this->assertSame(200, $response->get_status());
        $this->assertFalse($response->get_data()['core_allow_major']);
    }

    public function testNotOwnedReturns404(): void
    {
        $otherUserId = self::factory()->user->create();
        $siteId      = $this->seedSite($otherUserId);

        $response = rest_do_request($this->buildRequest($siteId, ['allow' => true]));

        $this->assertSame(404, $response->get_status());
        $this->assertSame('sites.not_found', $response->get_data()['error']['code']);
    }

    public function testInvalidPayloadReturns400(): void
    {
        $siteId = $this->seedSite($this->userId);

        // Missing allow key.
        $response = rest_do_request($this->buildRequest($siteId, ['something_else' => 'value']));
        $this->assertSame(400, $response->get_status());

        // Non-bool value.
        $response2 = rest_do_request($this->buildRequest($siteId, ['allow' => 'yes']));
        $this->assertSame(400, $response2->get_status());
    }

    public function testRateLimit429AfterEleventhCall(): void
    {
        $siteId = $this->seedSite($this->userId);

        // 10 successful calls.
        for ($i = 0; $i < 10; $i++) {
            $resp = rest_do_request($this->buildRequest($siteId, ['allow' => ($i % 2 === 0)]));
            $this->assertSame(200, $resp->get_status(), 'call #' . ($i + 1) . ' should be 200');
        }

        // 11th call must be 429.
        $resp = rest_do_request($this->buildRequest($siteId, ['allow' => true]));
        $this->assertSame(429, $resp->get_status());
        $this->assertSame('core.rate_limited', $resp->get_data()['error']['code']);
    }

    public function testActivityLogEventEmitted(): void
    {
        global $wpdb;
        $siteId = $this->seedSite($this->userId);

        rest_do_request($this->buildRequest($siteId, ['allow' => true]));

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}defyn_activity_log
             WHERE site_id = %d AND event_type = %s
             ORDER BY id DESC LIMIT 1",
            $siteId,
            'core_allow_major.toggled'
        ), ARRAY_A);

        $this->assertNotNull($row);
        $details = json_decode($row['details'] ?? '{}', true);
        $this->assertTrue($details['enabled']);
    }

    private function seedSite(int $userId): int
    {
        global $wpdb;
        $wpdb->insert(SitesTable::tableName(), [
            'user_id'                => $userId,
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
            'core_update_available'  => 0,
            'core_update_version'    => null,
            'core_update_state'      => 'idle',
            'last_core_update_error' => null,
        ]);
        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function buildRequest(int $siteId, array $body): WP_REST_Request
    {
        $req = new WP_REST_Request('POST', '/defyn/v1/sites/' . $siteId . '/core/allow-major');
        $req->set_header('Authorization', 'Bearer ' . $this->token);
        $req->set_header('Content-Type', 'application/json');
        $req->set_body((string) json_encode($body));
        $req->set_param('id', $siteId);
        return $req;
    }
}
