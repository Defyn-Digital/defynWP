<?php

declare(strict_types=1);

namespace Defyn\Dashboard;

use Defyn\Dashboard\Jobs\Scheduler;
use Defyn\Dashboard\Schema\ActivityLogTable;
use Defyn\Dashboard\Schema\ConnectionCodesTable;
use Defyn\Dashboard\Schema\SchemaTable;
use Defyn\Dashboard\Schema\SchemaVersion;
use Defyn\Dashboard\Schema\SitePluginsTable;
use Defyn\Dashboard\Schema\SitesTable;

/**
 * Runs on plugin activation. Creates the three custom tables required by spec § 4.1.
 *
 * Schema definitions live in Schema\*Table classes; this class orchestrates
 * the dbDelta calls and manages the schema version option.
 */
final class Activation
{
    public const SCHEMA_VERSION = 3;
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
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        foreach (self::TABLES as $table) {
            dbDelta($table::createSql());
        }

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
}
