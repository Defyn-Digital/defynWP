<?php

declare(strict_types=1);

namespace Defyn\Dashboard;

use Defyn\Dashboard\Jobs\Scheduler;
use Defyn\Dashboard\Schema\ActivityLogTable;
use Defyn\Dashboard\Schema\BulkJobItemsTable;
use Defyn\Dashboard\Schema\BulkJobsTable;
use Defyn\Dashboard\Schema\IncidentsTable;
use Defyn\Dashboard\Schema\ConnectionCodesTable;
use Defyn\Dashboard\Schema\SchemaTable;
use Defyn\Dashboard\Schema\SchemaVersion;
use Defyn\Dashboard\Schema\SitePluginsTable;
use Defyn\Dashboard\Schema\SiteThemesTable;
use Defyn\Dashboard\Schema\SitesTable;

/**
 * Runs on plugin activation. Creates the three custom tables required by spec § 4.1.
 *
 * Schema definitions live in Schema\*Table classes; this class orchestrates
 * the dbDelta calls and manages the schema version option.
 */
final class Activation
{
    public const SCHEMA_VERSION = 9;
    public const SCHEMA_OPTION  = 'defyn_dashboard_schema_version';

    /**
     * All SchemaTable implementations to run through dbDelta on activation.
     *
     * @var array<class-string<SchemaTable>>
     */
    public const TABLES = [
        SitesTable::class,
        ConnectionCodesTable::class,
        ActivityLogTable::class,
        SitePluginsTable::class,
        SiteThemesTable::class,
        BulkJobsTable::class,
        BulkJobItemsTable::class,
        IncidentsTable::class,
    ];

    /** Throttle key for {@see maybeRunSelfHeal} — checked at most once per hour. */
    private const SELF_HEAL_THROTTLE = 'defyn_dashboard_schema_check';

    public static function activate(): void
    {
        self::ensureSchema();

        // F7 — install recurring AS schedules (fan-out + cleanup). Runs AFTER
        // schema setup so the AS tables (provided by WC AS) are guaranteed to
        // be loaded by the time recurring rows are inserted. Idempotent — safe
        // on re-activation (see Scheduler::installRecurringSchedules()).
        Scheduler::installRecurringSchedules();
    }

    /**
     * Idempotent schema installer — runs dbDelta on every TABLE entry and
     * bumps the SchemaVersion option to current. Safe to call repeatedly.
     */
    public static function ensureSchema(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        foreach (self::TABLES as $table) {
            dbDelta($table::createSql());
        }

        // P2.3 — drop the legacy wp_defyn_sites.active_theme LONGTEXT column.
        // dbDelta cannot remove columns; we run a guarded ALTER directly. The
        // SHOW COLUMNS check makes re-running ensureSchema idempotent — once
        // the column is gone, the second call is a no-op.
        self::dropLegacyActiveThemeColumn();

        // P2.4 — add the 5 new core-update columns + index to wp_defyn_sites.
        // Guarded ALTERs make this idempotent. Same pattern as
        // dropLegacyActiveThemeColumn.
        self::addCoreUpdateColumns();

        // P2.4.1 — add core_allow_major to wp_defyn_sites, tested_up_to to
        // wp_defyn_site_plugins and wp_defyn_site_themes. Guarded ALTERs.
        self::addCoreAllowMajorColumn($wpdb);
        self::addPluginsTestedUpToColumn($wpdb);
        self::addThemesTestedUpToColumn($wpdb);

        // P3.1 — add consecutive_failures to wp_defyn_sites. Guarded ALTER.
        self::addConsecutiveFailuresColumn($wpdb);

        // P3.2 — add last_response_time_ms to wp_defyn_sites. Guarded ALTER.
        self::addResponseTimeColumn($wpdb);

        // P2.1: SchemaVersion is the canonical migration cursor; we coalesce
        // with any in-DB value via max() so a future install starting at v3
        // isn't silently downgraded if an older copy of this code runs ensureSchema.
        SchemaVersion::set(max(SchemaVersion::current(), self::SCHEMA_VERSION));
    }

    /**
     * P2.2.1 — runs from `plugins_loaded` on every request, throttled to one
     * actual check per hour. Re-installs schema if either trigger fires:
     *   - SchemaVersion is behind (someone bumped the constant without
     *     reactivating the plugin)
     *   - The canonical sites table is missing (Uninstaller fired during
     *     a "Replace current with uploaded version" upgrade and dropped
     *     everything — the recovery path the operator used to do manually
     *     by deact+reactivating)
     *
     * Eliminates the long-standing "must deact + react after every release"
     * runbook step. dbDelta is additive + idempotent so a no-op call is cheap;
     * the transient throttle keeps it off the hot path.
     */
    public static function maybeRunSelfHeal(): void
    {
        // Bump throttle FIRST so a failing check doesn't stampede when
        // multiple requests land before the first one finishes ensureSchema.
        if (get_transient(self::SELF_HEAL_THROTTLE)) {
            return;
        }
        set_transient(self::SELF_HEAL_THROTTLE, 1, HOUR_IN_SECONDS);

        if (SchemaVersion::current() < self::SCHEMA_VERSION || !self::canonicalTableExists()) {
            self::ensureSchema();
        }
    }

    private static function canonicalTableExists(): bool
    {
        global $wpdb;
        $table  = SitesTable::tableName();
        // phpcs:ignore WordPress.DB.PreparedSQL — table name from a class constant
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        return $exists !== null;
    }

    private static function dropLegacyActiveThemeColumn(): void
    {
        global $wpdb;
        $sitesTable = SitesTable::tableName();
        $exists = $wpdb->get_var($wpdb->prepare(
            "SHOW COLUMNS FROM `{$sitesTable}` LIKE %s",
            'active_theme'
        ));
        if ($exists !== null) {
            // phpcs:ignore WordPress.DB.PreparedSQL — column DDL cannot be parameterized.
            $wpdb->query("ALTER TABLE `{$sitesTable}` DROP COLUMN active_theme");
        }
    }

    private static function addCoreUpdateColumns(): void
    {
        global $wpdb;
        $sitesTable = SitesTable::tableName();

        $columns = [
            'core_update_available'       => 'TINYINT(1) NOT NULL DEFAULT 0',
            'core_update_version'         => 'VARCHAR(20) NULL',
            'core_update_state'           => "VARCHAR(20) NOT NULL DEFAULT 'idle'",
            'last_core_update_error'      => 'VARCHAR(1000) NULL',
            'last_core_update_attempt_at' => 'DATETIME NULL',
        ];
        foreach ($columns as $name => $definition) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SHOW COLUMNS FROM `{$sitesTable}` LIKE %s",
                $name
            ));
            if ($exists === null) {
                // phpcs:ignore WordPress.DB.PreparedSQL — column DDL cannot be parameterized.
                $wpdb->query("ALTER TABLE `{$sitesTable}` ADD COLUMN {$name} {$definition}");
            }
        }

        $hasIndex = $wpdb->get_row($wpdb->prepare(
            "SHOW INDEX FROM `{$sitesTable}` WHERE Key_name = %s",
            'idx_core_update_available'
        ));
        if ($hasIndex === null) {
            // phpcs:ignore WordPress.DB.PreparedSQL — index DDL cannot be parameterized.
            $wpdb->query("ALTER TABLE `{$sitesTable}` ADD INDEX idx_core_update_available (core_update_available)");
        }
    }

    private static function addCoreAllowMajorColumn(\wpdb $wpdb): void
    {
        $table  = SitesTable::tableName();
        $exists = $wpdb->get_var($wpdb->prepare(
            "SHOW COLUMNS FROM `{$table}` LIKE %s",
            'core_allow_major'
        ));
        if ($exists !== null) {
            return;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL — column DDL cannot be parameterized.
        $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN core_allow_major TINYINT(1) NOT NULL DEFAULT 0 AFTER last_core_update_attempt_at");
    }

    private static function addPluginsTestedUpToColumn(\wpdb $wpdb): void
    {
        $table  = SitePluginsTable::tableName();
        $exists = $wpdb->get_var($wpdb->prepare(
            "SHOW COLUMNS FROM `{$table}` LIKE %s",
            'tested_up_to'
        ));
        if ($exists !== null) {
            return;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL — column DDL cannot be parameterized.
        $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN tested_up_to VARCHAR(20) NULL AFTER update_version");
    }

    private static function addThemesTestedUpToColumn(\wpdb $wpdb): void
    {
        $table  = SiteThemesTable::tableName();
        $exists = $wpdb->get_var($wpdb->prepare(
            "SHOW COLUMNS FROM `{$table}` LIKE %s",
            'tested_up_to'
        ));
        if ($exists !== null) {
            return;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL — column DDL cannot be parameterized.
        $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN tested_up_to VARCHAR(20) NULL AFTER update_version");
    }

    private static function addConsecutiveFailuresColumn(\wpdb $wpdb): void
    {
        $table  = SitesTable::tableName();
        $exists = $wpdb->get_var($wpdb->prepare(
            "SHOW COLUMNS FROM `{$table}` LIKE %s",
            'consecutive_failures'
        ));
        if ($exists !== null) {
            return;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL — column DDL cannot be parameterized.
        $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN consecutive_failures INT NOT NULL DEFAULT 0");
    }

    private static function addResponseTimeColumn(\wpdb $wpdb): void
    {
        $table  = SitesTable::tableName();
        $exists = $wpdb->get_var($wpdb->prepare(
            "SHOW COLUMNS FROM `{$table}` LIKE %s",
            'last_response_time_ms'
        ));
        if ($exists !== null) {
            return;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL — column DDL cannot be parameterized.
        $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN last_response_time_ms INT UNSIGNED NULL");
    }
}
