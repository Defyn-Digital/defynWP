<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Schema;

/**
 * P2.1 — drives idempotent dashboard schema migrations.
 *
 * Foundation (F1-F10) implicitly = version 1. P2.1 bumps to 2.
 *
 * Reuses the existing Activation::SCHEMA_OPTION literal so Activation
 * and SchemaVersion read/write the same option — one source of truth.
 */
final class SchemaVersion
{
    /** Same literal as Activation::SCHEMA_OPTION (one option, two callers). */
    public const OPTION = 'defyn_dashboard_schema_version';

    public static function current(): int
    {
        $value = get_option(self::OPTION, 1);
        return (int) $value;
    }

    public static function set(int $version): void
    {
        update_option(self::OPTION, $version, false);
    }

    public static function needsMigrationTo(int $target): bool
    {
        return self::current() < $target;
    }
}
