<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Schema\ActivityLogTable;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * F9 Task 2 — ActivityLogger IP capture.
 *
 * Verifies the optional 5th $ipAddress parameter on log() round-trips
 * to wp_defyn_activity_log.ip_address. REST controllers populate this
 * from $_SERVER['REMOTE_ADDR']; AS background jobs leave it null
 * because they have no request context.
 *
 * @group integration
 */
final class ActivityLoggerIpCaptureTest extends AbstractSchemaTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_activity_log');
        global $wpdb;
        $wpdb->query('TRUNCATE ' . ActivityLogTable::tableName());
    }

    public function testLogWithIpStoresIpAddress(): void
    {
        (new ActivityLogger())->log(1, 5, 'site.synced', ['wp_version' => '6.9.4'], '203.0.113.42');

        global $wpdb;
        $row = $wpdb->get_row('SELECT * FROM ' . ActivityLogTable::tableName(), ARRAY_A);
        self::assertSame('203.0.113.42', $row['ip_address']);
        self::assertSame('site.synced', $row['event_type']);
    }

    public function testLogWithoutIpLeavesItNull(): void
    {
        (new ActivityLogger())->log(1, 5, 'site.health_ok');

        global $wpdb;
        $row = $wpdb->get_row('SELECT * FROM ' . ActivityLogTable::tableName(), ARRAY_A);
        self::assertNull($row['ip_address']);
    }
}
