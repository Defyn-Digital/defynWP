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
    public const SCHEMA_VERSION = 2;
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

    public static function activate(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        foreach (self::TABLES as $table) {
            dbDelta($table::createSql());
        }

        // P2.1: SchemaVersion is the canonical migration cursor; we coalesce with
        // any in-DB value via max() so a future install starting at v3 isn't
        // silently downgraded if an older copy of this code re-runs activate.
        SchemaVersion::set(max(SchemaVersion::current(), self::SCHEMA_VERSION));

        // F7 — install recurring AS schedules (fan-out + cleanup). Runs AFTER
        // schema setup so the AS tables (provided by WC AS) are guaranteed to
        // be loaded by the time recurring rows are inserted. Idempotent — safe
        // on re-activation (see Scheduler::installRecurringSchedules()).
        Scheduler::installRecurringSchedules();
    }
}
