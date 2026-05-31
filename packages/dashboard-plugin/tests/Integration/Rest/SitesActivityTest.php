<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Schema\ActivityLogTable;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

/**
 * @group integration
 */
final class SitesActivityTest extends AbstractSchemaTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_sites');
        $this->freshlyActivate('defyn_activity_log');
        global $wpdb;
        $wpdb->query('TRUNCATE ' . SitesTable::tableName());
        $wpdb->query('TRUNCATE ' . ActivityLogTable::tableName());
        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
        do_action('rest_api_init');
    }

    /** @param array<string, mixed>|null $details */
    private function insertEvent(
        int $userId,
        ?int $siteId,
        string $eventType,
        ?array $details = null,
        ?string $createdAt = null,
    ): void {
        global $wpdb;
        $wpdb->insert(
            ActivityLogTable::tableName(),
            [
                'user_id'    => $userId,
                'site_id'    => $siteId,
                'event_type' => $eventType,
                'details'    => $details === null ? null : json_encode($details),
                'ip_address' => null,
                'created_at' => $createdAt ?? gmdate('Y-m-d H:i:s'),
            ],
            [
                '%d',
                $siteId === null ? '%s' : '%d',
                '%s',
                '%s',
                '%s',
                '%s',
            ],
        );
    }

    private function makeSite(int $userId, string $url = 'https://x.test'): int
    {
        return (new SitesRepository())->insertPending(
            $userId,
            $url,
            'X',
            base64_encode(random_bytes(32)),
            'cipher',
        );
    }

    /** @param array<string, scalar> $query */
    private function dispatch(string $url, int $authUserId, array $query = []): \WP_REST_Response
    {
        $token = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($authUserId);
        $req = new WP_REST_Request('GET', $url);
        $req->set_header('Authorization', 'Bearer ' . $token);
        foreach ($query as $k => $v) {
            $req->set_param($k, $v);
        }
        return rest_do_request($req);
    }

    public function testOwnerSeesSiteEvents(): void
    {
        $owner = self::factory()->user->create();
        $site  = $this->makeSite($owner);

        $this->insertEvent($owner, $site, 'site.connected', null, '2026-05-29 00:00:00');
        $this->insertEvent($owner, $site, 'site.synced',    null, '2026-05-30 00:00:00');

        $response = $this->dispatch('/defyn/v1/sites/' . $site . '/activity', $owner);

        self::assertSame(200, $response->get_status());
        $body = $response->get_data();
        self::assertArrayHasKey('events', $body);
        self::assertCount(2, $body['events']);
        self::assertSame(2, $body['total']);
    }

    public function testNonOwnerGets404(): void
    {
        $owner    = self::factory()->user->create();
        $stranger = self::factory()->user->create();
        $site     = $this->makeSite($owner);

        $this->insertEvent($owner, $site, 'site.synced', null, '2026-05-30 00:00:00');

        $response = $this->dispatch('/defyn/v1/sites/' . $site . '/activity', $stranger);

        self::assertSame(404, $response->get_status());
        $body = $response->get_data();
        self::assertSame('sites.not_found', $body['error']['code']);
    }

    public function testPagination(): void
    {
        $owner = self::factory()->user->create();
        $site  = $this->makeSite($owner);

        for ($i = 1; $i <= 5; $i++) {
            $this->insertEvent($owner, $site, 'site.synced', null, sprintf('2026-05-%02d 00:00:00', $i));
        }

        $response = $this->dispatch('/defyn/v1/sites/' . $site . '/activity', $owner, [
            'per_page' => 2,
            'page'     => 1,
        ]);

        self::assertSame(200, $response->get_status());
        $body = $response->get_data();
        self::assertCount(2, $body['events']);
        self::assertSame(5, $body['total']);
        self::assertSame(1, $body['page']);
        self::assertSame(2, $body['per_page']);
    }

    public function testUnauthenticatedReturns401(): void
    {
        $owner = self::factory()->user->create();
        $site  = $this->makeSite($owner);

        $req = new WP_REST_Request('GET', '/defyn/v1/sites/' . $site . '/activity');
        $response = rest_do_request($req);

        self::assertSame(401, $response->get_status());
        $body = $response->get_data();
        self::assertArrayHasKey('error', $body);
        self::assertSame('auth.missing_token', $body['error']['code']);
    }
}
