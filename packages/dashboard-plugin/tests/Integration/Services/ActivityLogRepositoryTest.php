<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Schema\ActivityLogTable;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\ActivityLogRepository;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * F9 Task 1 — ActivityLogRepository read paths.
 *
 * User-scoping contract under test: an event belongs to user U if
 * user_id=U OR its site_id is owned by U (via wp_defyn_sites). Tests
 * create real site fixtures so the user-scoping subquery resolves —
 * site_id values referenced by events MUST exist in wp_defyn_sites,
 * owned by the expected user.
 *
 * @group integration
 */
final class ActivityLogRepositoryTest extends AbstractSchemaTestCase
{
    private SitesRepository $sites;

    public function setUp(): void
    {
        parent::setUp();
        // Both tables required for user-scoping subquery against sites.
        $this->freshlyActivate('defyn_activity_log');
        $this->freshlyActivate('defyn_sites');

        global $wpdb;
        $wpdb->query('TRUNCATE ' . ActivityLogTable::tableName());
        $wpdb->query('TRUNCATE ' . SitesTable::tableName());

        $this->sites = new SitesRepository();
    }

    /**
     * Create a site fixture owned by $userId, returning the new site id.
     * Used to satisfy the user-scoping subquery `site_id IN (SELECT id
     * FROM wp_defyn_sites WHERE user_id = U)`.
     */
    private function makeSite(int $userId, string $url = 'https://example.test'): int
    {
        return $this->sites->insertPending(
            userId: $userId,
            url: $url,
            label: 'Test',
            ourPublicKey: base64_encode(random_bytes(32)),
            ourPrivateKeyEncrypted: 'cipher',
        );
    }

    /**
     * Insert a raw row into wp_defyn_activity_log. Uses an explicit
     * created_at when provided so we can assert ordering deterministically.
     *
     * @param array<string, mixed>|null $details
     */
    private function insertRow(
        ?int $userId,
        ?int $siteId,
        string $eventType,
        ?array $details = null,
        ?string $ip = null,
        ?string $createdAt = null,
    ): int {
        global $wpdb;
        $wpdb->insert(
            ActivityLogTable::tableName(),
            [
                'user_id'    => $userId,
                'site_id'    => $siteId,
                'event_type' => $eventType,
                'details'    => $details === null ? null : json_encode($details),
                'ip_address' => $ip,
                'created_at' => $createdAt ?? gmdate('Y-m-d H:i:s'),
            ],
            [
                $userId === null ? '%s' : '%d',
                $siteId === null ? '%s' : '%d',
                '%s',
                '%s',
                '%s',
                '%s',
            ],
        );
        return (int) $wpdb->insert_id;
    }

    public function testPaginateReturnsNewestFirst(): void
    {
        $siteId = $this->makeSite(1, 'https://a.test');
        $this->insertRow(1, $siteId, 'site.connected', ['url' => 'https://a.test'], null, '2026-05-30 00:00:00');
        $this->insertRow(1, $siteId, 'site.synced', ['wp_version' => '6.9.4'], null, '2026-05-31 00:00:00');

        $events = (new ActivityLogRepository())->paginateForUser(1, null, null, 1, 50);

        self::assertCount(2, $events);
        self::assertSame('site.synced', $events[0]->eventType);
        self::assertSame('site.connected', $events[1]->eventType);
        self::assertSame(['wp_version' => '6.9.4'], $events[0]->details);
    }

    public function testFilterByEventType(): void
    {
        $siteId = $this->makeSite(1, 'https://a.test');
        $this->insertRow(1, $siteId, 'site.connected');
        $this->insertRow(1, $siteId, 'site.synced');
        $this->insertRow(1, $siteId, 'site.health_ok');

        $events = (new ActivityLogRepository())->paginateForUser(1, null, 'site.synced', 1, 50);
        self::assertCount(1, $events);
        self::assertSame('site.synced', $events[0]->eventType);
    }

    public function testFilterBySite(): void
    {
        $siteA = $this->makeSite(1, 'https://a.test');
        $siteB = $this->makeSite(1, 'https://b.test');
        $this->insertRow(1, $siteA, 'site.synced');
        $this->insertRow(1, $siteB, 'site.synced');
        // auth.login has no site_id — caught by the user_id=1 branch.
        $this->insertRow(1, null, 'auth.login');

        $events = (new ActivityLogRepository())->paginateForUser(1, $siteA, null, 1, 50);
        self::assertCount(1, $events);
        self::assertSame($siteA, $events[0]->siteId);
    }

    public function testPaginationOffset(): void
    {
        $siteId = $this->makeSite(1, 'https://a.test');
        // 10 events with strictly increasing created_at — newest first.
        for ($i = 0; $i < 10; $i++) {
            $this->insertRow(
                1,
                $siteId,
                'site.synced',
                null,
                null,
                '2026-05-' . sprintf('%02d', 20 + $i) . ' 00:00:00',
            );
        }

        $repo = new ActivityLogRepository();
        $page1 = $repo->paginateForUser(1, null, null, 1, 3);
        $page2 = $repo->paginateForUser(1, null, null, 2, 3);

        self::assertCount(3, $page1);
        self::assertCount(3, $page2);
        self::assertNotSame($page1[0]->id, $page2[0]->id);
    }

    public function testCountForUser(): void
    {
        $siteUser1 = $this->makeSite(1, 'https://a.test');
        $siteUser2 = $this->makeSite(2, 'https://b.test');

        $this->insertRow(1, $siteUser1, 'site.synced');
        $this->insertRow(1, $siteUser1, 'site.synced');
        $this->insertRow(2, $siteUser2, 'site.synced');

        $repo = new ActivityLogRepository();
        self::assertSame(2, $repo->countForUser(1, null, null));
        self::assertSame(1, $repo->countForUser(2, null, null));
    }

    public function testEventsForOtherUsersSiteDoNotLeak(): void
    {
        // Defense in depth: an event with user_id=2 + site_id of user 2's
        // site must NOT appear for user 1's feed.
        $siteUser2 = $this->makeSite(2, 'https://b.test');
        $this->insertRow(2, $siteUser2, 'site.synced');

        $events = (new ActivityLogRepository())->paginateForUser(1, null, null, 1, 50);
        self::assertCount(0, $events);
    }
}
