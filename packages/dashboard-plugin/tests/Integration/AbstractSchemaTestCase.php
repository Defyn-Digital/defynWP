<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration;

use Defyn\Dashboard\Activation;
use WP_UnitTestCase;

/**
 * Base class for tests that exercise schema creation via Activation.
 *
 * Provides:
 *   - assertTableExists() — uses DESCRIBE rather than SHOW TABLES because
 *     WP_UnitTestCase::start_transaction() rewrites CREATE TABLE to
 *     CREATE TEMPORARY TABLE for isolation, and SHOW TABLES does not list
 *     temp tables. DESCRIBE works on both regular and temporary tables.
 *   - freshlyActivate() — drops the named table, deletes the schema option,
 *     and re-runs Activation::activate(). Use when a test needs a guaranteed
 *     clean slate beyond what WP_UnitTestCase's transaction rollback provides.
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

    /**
     * Drop the given table, clear the schema-version option, and re-run activation.
     * Forces a clean slate even if state leaked from a previous PHPUnit invocation.
     */
    protected function freshlyActivate(string $unprefixedTableName): void
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL — table names cannot be parameterized.
        $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}{$unprefixedTableName}`");
        delete_option(Activation::SCHEMA_OPTION);

        Activation::activate();
    }
}
