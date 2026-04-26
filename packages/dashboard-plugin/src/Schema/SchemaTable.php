<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Schema;

/**
 * Contract for a database schema definition that Activation will dbDelta.
 *
 * Each implementer corresponds to one table.
 */
interface SchemaTable
{
    /** Fully-qualified table name including the WP prefix. */
    public static function tableName(): string;

    /** dbDelta-compatible CREATE TABLE statement (uppercase, two-space `PRIMARY KEY  (col)`). */
    public static function createSql(): string;
}
