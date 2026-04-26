<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration;

use WP_UnitTestCase;

/**
 * Base class for tests that exercise schema creation via Activation.
 *
 * Provides:
 *   - assertTableExists() — uses DESCRIBE rather than SHOW TABLES because
 *     WP_UnitTestCase::start_transaction() rewrites CREATE TABLE to
 *     CREATE TEMPORARY TABLE for isolation, and SHOW TABLES does not list
 *     temp tables. DESCRIBE works on both regular and temporary tables.
 *
 * Subclasses are expected to drop the table + delete the schema option
 * in their own setUp logic if they want a guaranteed clean slate beyond
 * what WP_UnitTestCase's transaction rollback provides.
 */
abstract class AbstractSchemaTestCase extends WP_UnitTestCase
{
    /**
     * Assert a table exists by attempting to DESCRIBE it.
     *
     * If the table doesn't exist, MySQL emits ERROR 1146; wpdb suppresses it
     * and DESCRIBE returns an empty array — which fails the assertion with a
     * clear message naming the missing table.
     */
    protected function assertTableExists(string $tableName): void
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL — table names cannot be parameterized.
        $columns = $wpdb->get_col("DESCRIBE `{$tableName}`");

        self::assertNotEmpty(
            $columns,
            "Expected table {$tableName} to exist after activation; DESCRIBE returned no columns."
        );
    }
}
