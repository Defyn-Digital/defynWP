<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Schema\SiteThemesTable;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

/**
 * @group integration
 */
final class SitesThemesIndexTest extends AbstractSchemaTestCase
{
    private int $userId;
    private string $token;
    private int $siteId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_sites');
        $this->freshlyActivate('defyn_site_themes');
        global $wpdb;
        $wpdb->query('TRUNCATE ' . SitesTable::tableName());
        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
        do_action('rest_api_init');

        $this->userId = self::factory()->user->create();
        $this->token = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($this->userId);

        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'    => $this->userId,
            'url'        => 'https://smartcoding.test',
            'label'      => 'Smart',
            'status'     => 'active',
            'created_at' => '2026-06-06 00:00:00',
            'updated_at' => '2026-06-06 00:00:00',
        ]);
        $this->siteId = (int) $wpdb->insert_id;
    }

    public function testReturnsThemesPayload(): void
    {
        global $wpdb;
        $wpdb->insert(SiteThemesTable::tableName(), [
            'site_id'                => $this->siteId,
            'slug'                   => 'twentytwentyfive',
            'name'                   => 'Twenty Twenty-Five',
            'version'                => '1.2',
            'parent_slug'            => null,
            'is_active'              => 1,
            'update_available'       => 1,
            'update_version'         => '1.3',
            'update_state'           => 'idle',
            'last_update_error'      => null,
            'last_update_attempt_at' => null,
            'last_seen_at'           => '2026-06-06 05:00:00',
            'created_at'             => '2026-06-05 09:00:00',
            'updated_at'             => '2026-06-06 05:00:00',
        ]);

        $response = rest_do_request($this->signed('GET', "/defyn/v1/sites/{$this->siteId}/themes"));

        self::assertSame(200, $response->get_status());
        $data = $response->get_data();
        self::assertCount(1, $data['themes']);
        self::assertSame('twentytwentyfive', $data['themes'][0]['slug']);
        self::assertTrue($data['themes'][0]['is_active']);
        self::assertSame('2026-06-06 05:00:00', $data['last_synced_at']);
    }

    public function testEmptySiteReturnsNullLastSyncedAt(): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'    => $this->userId,
            'url'        => 'https://other.test',
            'label'      => 'Other',
            'status'     => 'active',
            'created_at' => '2026-06-06 00:00:00',
            'updated_at' => '2026-06-06 00:00:00',
        ]);
        $otherId = (int) $wpdb->insert_id;

        $response = rest_do_request($this->signed('GET', "/defyn/v1/sites/{$otherId}/themes"));
        self::assertSame(200, $response->get_status());
        $data = $response->get_data();
        self::assertSame([], $data['themes']);
        self::assertNull($data['last_synced_at']);
    }

    public function testNotOwnedReturns404(): void
    {
        $response = rest_do_request($this->signed('GET', "/defyn/v1/sites/99999/themes"));
        self::assertSame(404, $response->get_status());
        self::assertSame('sites.not_found', $response->get_data()['error']['code']);
    }

    public function testUnauthenticatedReturns401(): void
    {
        $request = new WP_REST_Request('GET', "/defyn/v1/sites/{$this->siteId}/themes");
        $response = rest_do_request($request);
        self::assertSame(401, $response->get_status());
    }

    private function signed(string $method, string $path): WP_REST_Request
    {
        $request = new WP_REST_Request($method, $path);
        $request->set_header('Authorization', 'Bearer ' . $this->token);
        return $request;
    }
}
