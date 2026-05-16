<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Schema\ActivityLogTable;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * @group integration
 */
final class ActivityLoggerTest extends AbstractSchemaTestCase
{
    private ActivityLogger $logger;

    public function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_activity_log');
        global $wpdb;
        $wpdb->query('TRUNCATE ' . ActivityLogTable::tableName());
        $this->logger = new ActivityLogger();
    }

    public function testLogPersistsRowWithEncodedDetails(): void
    {
        $this->logger->log(
            userId: 7,
            siteId: 42,
            eventType: 'site.connected',
            details: ['url' => 'https://example.test'],
        );

        global $wpdb;
        $rows = $wpdb->get_results('SELECT * FROM ' . ActivityLogTable::tableName(), ARRAY_A);

        self::assertCount(1, $rows);
        self::assertSame('7',   $rows[0]['user_id']);
        self::assertSame('42',  $rows[0]['site_id']);
        self::assertSame('site.connected', $rows[0]['event_type']);
        self::assertSame(['url' => 'https://example.test'], json_decode($rows[0]['details'], true));
        self::assertNotEmpty($rows[0]['created_at']);
    }

    public function testLogAcceptsNullUserIdAndSiteIdForSystemEvents(): void
    {
        $this->logger->log(null, null, 'system.boot', null);

        global $wpdb;
        $rows = $wpdb->get_results('SELECT * FROM ' . ActivityLogTable::tableName(), ARRAY_A);

        self::assertCount(1, $rows);
        self::assertNull($rows[0]['user_id']);
        self::assertNull($rows[0]['site_id']);
        self::assertSame('system.boot', $rows[0]['event_type']);
        self::assertNull($rows[0]['details']);
    }
}
