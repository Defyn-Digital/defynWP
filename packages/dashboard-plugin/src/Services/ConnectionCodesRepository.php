<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Schema\ConnectionCodesTable;

/**
 * Thin wrapper over wpdb for wp_defyn_connection_codes — the only class that
 * issues raw SQL for that table. Controllers + AS jobs call this; tests assert
 * persistence through it.
 */
final class ConnectionCodesRepository
{
    /**
     * Sweep rows past expiry OR already consumed. Returns deleted row count.
     * Called hourly by the CleanupExpiredCodes AS job — see spec § 6.3.
     */
    public function deleteExpiredAndConsumed(): int
    {
        global $wpdb;
        $table = ConnectionCodesTable::tableName();
        $now   = gmdate('Y-m-d H:i:s');

        $count = $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL — table name is internal, not user input.
                "DELETE FROM {$table} WHERE expires_at < %s OR consumed_at IS NOT NULL",
                $now
            )
        );

        return (int) $count;
    }
}
