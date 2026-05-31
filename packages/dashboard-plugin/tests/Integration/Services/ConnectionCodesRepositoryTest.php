<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Schema\ConnectionCodesTable;
use Defyn\Dashboard\Services\ConnectionCodesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * F7 — ConnectionCodesRepository::deleteExpiredAndConsumed.
 *
 * Hourly cleanup sweep removes any code row that has either passed its
 * 15-minute expiry OR been consumed by a successful handshake. Surviving
 * rows are still-live, unconsumed codes — see spec § 6.3.
 *
 * @group integration
 */
final class ConnectionCodesRepositoryTest extends AbstractSchemaTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_connection_codes');
        global $wpdb;
        $wpdb->query('TRUNCATE ' . ConnectionCodesTable::tableName());
    }

    public function testDeleteExpiredAndConsumedRemovesOnlyTargetRows(): void
    {
        global $wpdb;
        $table = ConnectionCodesTable::tableName();
        $now   = gmdate('Y-m-d H:i:s');

        // Row 1 — expired, unconsumed. Should be deleted.
        $wpdb->insert($table, [
            'code'        => 'EXPIRED01234567890123456789ABCD',
            'site_url'    => 'https://a.test',
            'site_nonce'  => 'nonce-a',
            'expires_at'  => gmdate('Y-m-d H:i:s', time() - 3600),
            'consumed_at' => null,
            'created_at'  => $now,
        ]);

        // Row 2 — not expired, but consumed. Should be deleted.
        $wpdb->insert($table, [
            'code'        => 'CONSUMED1234567890123456789ABCD',
            'site_url'    => 'https://b.test',
            'site_nonce'  => 'nonce-b',
            'expires_at'  => gmdate('Y-m-d H:i:s', time() + 3600),
            'consumed_at' => $now,
            'created_at'  => $now,
        ]);

        // Row 3 — live and unconsumed. Should survive.
        $wpdb->insert($table, [
            'code'        => 'LIVE123456789012345678901234ABC',
            'site_url'    => 'https://c.test',
            'site_nonce'  => 'nonce-c',
            'expires_at'  => gmdate('Y-m-d H:i:s', time() + 3600),
            'consumed_at' => null,
            'created_at'  => $now,
        ]);

        $deleted = (new ConnectionCodesRepository())->deleteExpiredAndConsumed();

        self::assertSame(2, $deleted);

        $remaining = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        self::assertSame(1, $remaining);

        $survivor = $wpdb->get_var("SELECT code FROM {$table}");
        self::assertSame('LIVE123456789012345678901234ABC', $survivor);
    }

    public function testReturnsZeroWhenNoMatchingRows(): void
    {
        global $wpdb;
        $table = ConnectionCodesTable::tableName();
        $now   = gmdate('Y-m-d H:i:s');

        $wpdb->insert($table, [
            'code'        => 'LIVE123456789012345678901234ABC',
            'site_url'    => 'https://c.test',
            'site_nonce'  => 'nonce-c',
            'expires_at'  => gmdate('Y-m-d H:i:s', time() + 3600),
            'consumed_at' => null,
            'created_at'  => $now,
        ]);

        $deleted = (new ConnectionCodesRepository())->deleteExpiredAndConsumed();

        self::assertSame(0, $deleted);
    }
}
