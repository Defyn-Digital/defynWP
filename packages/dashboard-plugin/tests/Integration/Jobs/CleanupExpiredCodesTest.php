<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Jobs;

use Defyn\Dashboard\Jobs\CleanupExpiredCodes;
use Defyn\Dashboard\Schema\ConnectionCodesTable;
use Defyn\Dashboard\Services\ConnectionCodesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * F7 — CleanupExpiredCodes recurring AS job (hourly sweep).
 *
 * Thin shim that delegates to ConnectionCodesRepository::deleteExpiredAndConsumed.
 * Per spec § 6.3.
 *
 * @group integration
 */
final class CleanupExpiredCodesTest extends AbstractSchemaTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_connection_codes');
        global $wpdb;
        $wpdb->query('TRUNCATE ' . ConnectionCodesTable::tableName());
    }

    public function testHookNameIsDefynCleanupExpiredCodes(): void
    {
        self::assertSame('defyn_cleanup_expired_codes', CleanupExpiredCodes::HOOK);
    }

    public function testHandleDelegatesToRepositoryAndSweepsRows(): void
    {
        global $wpdb;
        $table = ConnectionCodesTable::tableName();
        $now   = gmdate('Y-m-d H:i:s');

        // Insert an expired row that the job must sweep.
        $wpdb->insert($table, [
            'code'        => 'EXPIRED01234567890123456789ABCD',
            'site_url'    => 'https://a.test',
            'site_nonce'  => 'nonce-a',
            'expires_at'  => gmdate('Y-m-d H:i:s', time() - 3600),
            'consumed_at' => null,
            'created_at'  => $now,
        ]);

        (new CleanupExpiredCodes(new ConnectionCodesRepository()))->handle();

        $remaining = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        self::assertSame(0, $remaining);
    }
}
