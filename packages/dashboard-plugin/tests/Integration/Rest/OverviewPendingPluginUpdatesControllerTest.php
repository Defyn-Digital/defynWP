<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

/**
 * @group integration
 */
final class OverviewPendingPluginUpdatesControllerTest extends AbstractSchemaTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
        $this->freshlyActivate('defyn_site_plugins');

        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL
        $wpdb->query('SET autocommit = 1');
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_site_plugins");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_sites");
        // phpcs:enable WordPress.DB.PreparedSQL

        // Wipe the per-user rate-limit transients so tests start fresh.
        for ($i = 1; $i <= 10; $i++) {
            delete_transient("defyn_rl_overviewPendingPluginUpdates_{$i}");
        }

        do_action('rest_api_init');
    }

    public function testAuthRequiredReturns401WhenNoBearerToken(): void
    {
        $request = new WP_REST_Request('GET', '/defyn/v1/overview/pending-plugin-updates');
        $response = rest_do_request($request);
        $this->assertSame(401, $response->get_status());
    }

    public function testHappyPath200WithFlatList(): void
    {
        $siteA = $this->seedSite(1, 'SmartCoding');
        $this->seedPlugin($siteA, 'akismet', 'Akismet Anti-Spam', '5.3', '5.3.1', true);

        $token = $this->token(1);
        $request = new WP_REST_Request('GET', '/defyn/v1/overview/pending-plugin-updates');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $response = rest_do_request($request);

        $this->assertSame(200, $response->get_status());
        $body = $response->get_data();
        $this->assertArrayHasKey('pending_updates', $body);
        $this->assertArrayHasKey('generated_at', $body);
        $this->assertCount(1, $body['pending_updates']);
        $this->assertSame('akismet', $body['pending_updates'][0]['slug']);
        $this->assertSame('SmartCoding', $body['pending_updates'][0]['site_label']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $body['generated_at']);
    }

    public function testRateLimit429AfterThirtyFirstCall(): void
    {
        $token = $this->token(1);

        for ($i = 0; $i < 30; $i++) {
            $req = new WP_REST_Request('GET', '/defyn/v1/overview/pending-plugin-updates');
            $req->set_header('Authorization', 'Bearer ' . $token);
            $resp = rest_do_request($req);
            $this->assertSame(200, $resp->get_status(), 'call #' . ($i + 1) . ' should be 200');
        }

        $req = new WP_REST_Request('GET', '/defyn/v1/overview/pending-plugin-updates');
        $req->set_header('Authorization', 'Bearer ' . $token);
        $resp = rest_do_request($req);
        $this->assertSame(429, $resp->get_status());
        $this->assertSame('overview.rate_limited', $resp->get_data()['error']['code'] ?? null);
    }

    public function testOwnershipScopingExcludesOtherUsersSites(): void
    {
        $siteOther = $this->seedSite(2, 'NotMine');
        $this->seedPlugin($siteOther, 'akismet', 'Akismet', '5.3', '5.3.1', true);

        $token = $this->token(1);
        $request = new WP_REST_Request('GET', '/defyn/v1/overview/pending-plugin-updates');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $response = rest_do_request($request);

        $this->assertSame(200, $response->get_status());
        $this->assertSame([], $response->get_data()['pending_updates']);
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

    private function seedPlugin(int $siteId, string $slug, string $name, string $version, ?string $updateVersion, bool $updateAvailable): void
    {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert($wpdb->prefix . 'defyn_site_plugins', [
            'site_id'          => $siteId,
            'slug'             => $slug,
            'name'             => $name,
            'version'          => $version,
            'update_version'   => $updateVersion,
            'update_available' => $updateAvailable ? 1 : 0,
            'last_seen_at'     => $now,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
    }

    private function token(int $userId): string
    {
        return (new TokenService(DEFYN_JWT_SECRET))->issueAccess($userId);
    }
}
