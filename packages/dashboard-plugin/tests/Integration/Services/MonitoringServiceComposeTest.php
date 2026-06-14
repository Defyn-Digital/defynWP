<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\IncidentsRepository;
use Defyn\Dashboard\Services\MonitoringService;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/** @group integration */
final class MonitoringServiceComposeTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        global $wpdb;
        // Purge custom tables BEFORE the parent starts its transaction, with an
        // explicit commit so the deletes survive this test's rollback. Mirrors
        // SitesRepositoryOverviewTest — necessary because freshlyActivate()'s
        // DROP/CREATE DDL implicit-commits rows leaked by other integration
        // tests, which would otherwise count toward findAllForUser(1).
        // phpcs:disable WordPress.DB.PreparedSQL
        $wpdb->query('SET autocommit = 1');
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_incidents");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_sites");
        // phpcs:enable WordPress.DB.PreparedSQL

        parent::setUp();
        $this->freshlyActivate('defyn_sites');
        $this->freshlyActivate('defyn_incidents');
    }

    public function testComposeBuildsSummaryAndSites(): void
    {
        $sites = new SitesRepository();
        $incidents = new IncidentsRepository();

        $up   = $sites->insertPending(userId: 1, url: 'https://up.test', label: 'Up', ourPublicKey: 'p', ourPrivateKeyEncrypted: 'e');
        $sites->markActive($up, 'pk');
        $sites->recordResponseTime($up, 200);

        $down = $sites->insertPending(userId: 1, url: 'https://down.test', label: 'Down', ourPublicKey: 'p', ourPrivateKeyEncrypted: 'e');
        $sites->markOffline($down, 'boom');
        $sites->recordResponseTime($down, null);
        $incidents->open($down, gmdate('Y-m-d H:i:s', time() - 600), 'boom'); // open

        $payload = (new MonitoringService())->compose(1);

        self::assertSame(2, $payload['summary']['total']);
        self::assertSame(1, $payload['summary']['up']);
        self::assertSame(1, $payload['summary']['down']);
        self::assertSame(200, $payload['summary']['slowest_ms']);
        self::assertNotNull($payload['summary']['fleet_uptime_30d']);
        self::assertCount(2, $payload['sites']);

        $downRow = array_values(array_filter($payload['sites'], fn ($s) => $s['site_id'] === $down))[0];
        self::assertSame('offline', $downRow['status']);
        self::assertNull($downRow['last_response_time_ms']);
        self::assertNotNull($downRow['open_incident_started_at']);
        self::assertLessThan(100.0, $downRow['uptime_7d']);
    }

    public function testComposeEmptyFleetNullsAggregates(): void
    {
        $payload = (new MonitoringService())->compose(999);
        self::assertSame(0, $payload['summary']['total']);
        self::assertNull($payload['summary']['fleet_uptime_30d']);
        self::assertNull($payload['summary']['slowest_ms']);
        self::assertSame([], $payload['sites']);
    }
}
