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
 *   - assertTableDoesNotExist() — same DESCRIBE technique, but suppresses
 *     wpdb's loud error output when the table is missing (since that's the
 *     expected case here, not an exceptional one).
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
     * Assert a table does NOT exist. Suppresses wpdb's error logging during the
     * DESCRIBE since "table missing" is the expected case here, not an error.
     *
     * Uses suppress_errors() (not hide_errors() — the latter only stops the HTML
     * error block, not the noisy "WordPress database error..." log lines).
     */
    protected function assertTableDoesNotExist(string $tableName): void
    {
        global $wpdb;

        $wasSuppressed = $wpdb->suppress_errors(true);

        // phpcs:ignore WordPress.DB.PreparedSQL — table names cannot be parameterized.
        $columns = $wpdb->get_col("DESCRIBE `{$tableName}`");

        $wpdb->suppress_errors($wasSuppressed);

        self::assertEmpty(
            $columns,
            "Expected table {$tableName} to NOT exist; DESCRIBE returned columns."
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

        // P2.1: SitePluginsTable joins Activation::TABLES in Task 12. Until then,
        // create it directly so tests using freshlyActivate('defyn_site_plugins') work.
        // After Task 12 this becomes harmless (dbDelta is idempotent).
        if ($unprefixedTableName === 'defyn_site_plugins') {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta(\Defyn\Dashboard\Schema\SitePluginsTable::createSql());
        }
    }

    /**
     * Return DESCRIBE output keyed by column name.
     *
     * Each value is the raw row returned by `DESCRIBE <table>`, with keys
     * `Field`, `Type`, `Null`, `Key`, `Default`, `Extra`.
     *
     * @return array<string, array<string, string|null>>
     */
    protected function describeTable(string $table): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL — table name cannot be parameterized.
        $rows = $wpdb->get_results("DESCRIBE `{$table}`", ARRAY_A);
        $out  = [];
        foreach ($rows ?: [] as $row) {
            $out[$row['Field']] = $row;
        }
        return $out;
    }

    /**
     * Assert that the given table carries an index with the given Key_name.
     *
     * Uses SHOW INDEX FROM; works for both regular and TEMPORARY tables.
     */
    protected function assertHasIndex(string $table, string $indexName): void
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL — table name cannot be parameterized; index name is parameterized via prepare().
        $rows = $wpdb->get_results(
            $wpdb->prepare("SHOW INDEX FROM `{$table}` WHERE Key_name = %s", $indexName),
            ARRAY_A
        );

        self::assertNotEmpty(
            $rows,
            "Expected index `{$indexName}` on `{$table}`"
        );
    }
}
