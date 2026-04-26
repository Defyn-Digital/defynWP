<?php

declare(strict_types=1);

namespace Defyn\Dashboard;

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
        // Tasks 7–9 fill this in.
    }
}
