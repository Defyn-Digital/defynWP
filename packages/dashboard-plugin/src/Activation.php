<?php

declare(strict_types=1);

namespace Defyn\Dashboard;

use Defyn\Dashboard\Schema\SitesTable;

/**
 * Runs on plugin activation. Creates the three custom tables required by spec § 4.1.
 *
 * Schema definitions live in Schema\*Table classes; this class orchestrates
 * the dbDelta calls and manages the schema version option.
 */
final class Activation
{
    public const SCHEMA_VERSION = 1;
    public const SCHEMA_OPTION  = 'defyn_dashboard_schema_version';

    public static function activate(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta(SitesTable::createSql());

        update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION);
    }
}
