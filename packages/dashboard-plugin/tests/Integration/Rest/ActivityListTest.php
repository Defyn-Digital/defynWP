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
final class ActivityListTest extends AbstractSchemaTestCase
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

    public function testReturnsUserScopedFeedNewestFirst(): void
    {
        $owner    = self::factory()->user->create();
        $stranger = self::factory()->user->create();

        $ownerSite    = $this->makeSite($owner,    'https://owner.test');
        $strangerSite = $this->makeSite($stranger, 'https://stranger.test');

        $this->insertEvent($owner,    $ownerSite,    'site.connected', null, '2026-05-29 00:00:00');
        $this->insertEvent($owner,    $ownerSite,    'site.synced',    null, '2026-05-30 00:00:00');
        $this->insertEvent($stranger, $strangerSite, 'site.synced',    null, '2026-05-31 00:00:00');

        $response = $this->dispatch('/defyn/v1/activity', $owner);

        self::assertSame(200, $response->get_status());
        $body = $response->get_data();
        self::assertArrayHasKey('events', $body);
        self::assertCount(2, $body['events']);
        // Newest first.
        self::assertSame('site.synced',    $body['events'][0]['event_type']);
        self::assertSame('site.connected', $body['events'][1]['event_type']);
        // user_id is never exposed to the SPA.
        self::assertArrayNotHasKey('user_id', $body['events'][0]);
    }

    public function testFiltersByEventType(): void
    {
        $owner = self::factory()->user->create();
        $site  = $this->makeSite($owner);

        $this->insertEvent($owner, $site, 'site.connected', null, '2026-05-29 00:00:00');
        $this->insertEvent($owner, $site, 'site.synced',    null, '2026-05-30 00:00:00');
        $this->insertEvent($owner, $site, 'site.synced',    null, '2026-05-30 01:00:00');

        $response = $this->dispatch('/defyn/v1/activity', $owner, ['event_type' => 'site.synced']);

        self::assertSame(200, $response->get_status());
        $body = $response->get_data();
        self::assertCount(2, $body['events']);
        foreach ($body['events'] as $ev) {
            self::assertSame('site.synced', $ev['event_type']);
        }
    }

    public function testFiltersBySiteId(): void
    {
        $owner = self::factory()->user->create();
        $siteA = $this->makeSite($owner, 'https://a.test');
        $siteB = $this->makeSite($owner, 'https://b.test');

        $this->insertEvent($owner, $siteA, 'site.synced', null, '2026-05-29 00:00:00');
        $this->insertEvent($owner, $siteB, 'site.synced', null, '2026-05-30 00:00:00');
        $this->insertEvent($owner, $siteB, 'site.synced', null, '2026-05-30 01:00:00');

        $response = $this->dispatch('/defyn/v1/activity', $owner, ['site_id' => $siteB]);

        self::assertSame(200, $response->get_status());
        $body = $response->get_data();
        self::assertCount(2, $body['events']);
        foreach ($body['events'] as $ev) {
            self::assertSame($siteB, $ev['site_id']);
        }
    }

    public function testPaginationMetadataReturnsTotalAndPage(): void
    {
        $owner = self::factory()->user->create();
        $site  = $this->makeSite($owner);

        for ($i = 1; $i <= 10; $i++) {
            $this->insertEvent($owner, $site, 'site.synced', null, sprintf('2026-05-%02d 00:00:00', $i));
        }

        $response = $this->dispatch('/defyn/v1/activity', $owner, ['per_page' => 3, 'page' => 2]);

        self::assertSame(200, $response->get_status());
        $body = $response->get_data();
        self::assertCount(3, $body['events']);
        self::assertSame(10, $body['total']);
        self::assertSame(2,  $body['page']);
        self::assertSame(3,  $body['per_page']);
    }

    public function testUnauthenticatedReturns401(): void
    {
        $req = new WP_REST_Request('GET', '/defyn/v1/activity');
        $response = rest_do_request($req);

        self::assertSame(401, $response->get_status());
        $body = $response->get_data();
        self::assertArrayHasKey('error', $body);
        self::assertSame('auth.missing_token', $body['error']['code']);
    }
}
