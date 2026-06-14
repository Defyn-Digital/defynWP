<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Schema\IncidentsTable;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

/**
 * P3.1 — GET /defyn/v1/sites/{id}/incidents
 *
 * Mirrors SitesThemesIndexTest: JWT auth, ownership gate (404), pagination.
 *
 * @group integration
 */
final class SitesIncidentsTest extends AbstractSchemaTestCase
{
    private int $userId;
    private string $token;
    private int $siteId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_incidents');
        global $wpdb;
        $wpdb->query('TRUNCATE ' . SitesTable::tableName());
        $wpdb->query('DELETE FROM ' . IncidentsTable::tableName());
        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
        do_action('rest_api_init');

        $this->userId = self::factory()->user->create();
        $this->token  = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($this->userId);

        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'    => $this->userId,
            'url'        => 'https://smartcoding.test',
            'label'      => 'Smart',
            'status'     => 'active',
            'created_at' => '2026-06-14 00:00:00',
            'updated_at' => '2026-06-14 00:00:00',
        ]);
        $this->siteId = (int) $wpdb->insert_id;
    }

    // -------------------------------------------------------------------------
    // 1. 200 + envelope with incidents newest-first
    // -------------------------------------------------------------------------

    public function testReturnsIncidentsNewestFirst(): void
    {
        global $wpdb;
        $t = IncidentsTable::tableName();
        $wpdb->insert($t, [
            'site_id'    => $this->siteId,
            'started_at' => '2026-06-10 08:00:00',
            'last_error' => 'older error',
            'created_at' => '2026-06-10 08:00:00',
        ]);
        $wpdb->insert($t, [
            'site_id'    => $this->siteId,
            'started_at' => '2026-06-14 10:00:00',
            'last_error' => 'newer error',
            'created_at' => '2026-06-14 10:00:00',
        ]);

        $response = rest_do_request($this->signed('GET', "/defyn/v1/sites/{$this->siteId}/incidents"));

        self::assertSame(200, $response->get_status());
        $body = $response->get_data();
        self::assertArrayHasKey('data', $body);
        self::assertArrayHasKey('incidents', $body['data']);
        self::assertNull($body['error']);
        self::assertCount(2, $body['data']['incidents']);
        // Newest first
        self::assertSame('newer error', $body['data']['incidents'][0]['last_error']);
        self::assertSame('older error', $body['data']['incidents'][1]['last_error']);
    }

    // -------------------------------------------------------------------------
    // 2. 401 when unauthenticated
    // -------------------------------------------------------------------------

    public function testUnauthenticatedReturns401(): void
    {
        $request  = new WP_REST_Request('GET', "/defyn/v1/sites/{$this->siteId}/incidents");
        $response = rest_do_request($request);
        self::assertSame(401, $response->get_status());
    }

    // -------------------------------------------------------------------------
    // 3. 404 when site belongs to a different user
    // -------------------------------------------------------------------------

    public function testNotOwnedSiteReturns404(): void
    {
        $response = rest_do_request($this->signed('GET', '/defyn/v1/sites/99999/incidents'));
        self::assertSame(404, $response->get_status());
        self::assertSame('sites.not_found', $response->get_data()['error']['code']);
    }

    // -------------------------------------------------------------------------
    // 4. limit / offset respected
    // -------------------------------------------------------------------------

    public function testLimitAndOffsetRespected(): void
    {
        global $wpdb;
        $t = IncidentsTable::tableName();
        $wpdb->insert($t, [
            'site_id' => $this->siteId, 'started_at' => '2026-06-10 08:00:00',
            'last_error' => 'first', 'created_at' => '2026-06-10 08:00:00',
        ]);
        $wpdb->insert($t, [
            'site_id' => $this->siteId, 'started_at' => '2026-06-12 08:00:00',
            'last_error' => 'second', 'created_at' => '2026-06-12 08:00:00',
        ]);
        $wpdb->insert($t, [
            'site_id' => $this->siteId, 'started_at' => '2026-06-14 08:00:00',
            'last_error' => 'third', 'created_at' => '2026-06-14 08:00:00',
        ]);

        $request = $this->signed('GET', "/defyn/v1/sites/{$this->siteId}/incidents");
        $request->set_query_params(['limit' => '1', 'offset' => '0']);
        $response = rest_do_request($request);

        self::assertSame(200, $response->get_status());
        $incidents = $response->get_data()['data']['incidents'];
        self::assertCount(1, $incidents);
        // newest first, so the one with started_at 2026-06-14
        self::assertSame('third', $incidents[0]['last_error']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function signed(string $method, string $path): WP_REST_Request
    {
        $request = new WP_REST_Request($method, $path);
        $request->set_header('Authorization', 'Bearer ' . $this->token);
        return $request;
    }
}
