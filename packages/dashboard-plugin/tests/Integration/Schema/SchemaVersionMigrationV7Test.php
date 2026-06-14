<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Schema;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Schema\BulkJobItemsTable;
use Defyn\Dashboard\Schema\BulkJobsTable;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SchemaVersionMigrationV7Test extends AbstractSchemaTestCase
{
    public function testSchemaVersionConstantIsSeven(): void
    {
        // Schema was at v7 when this test was written. SCHEMA_VERSION tracks the
        // current version — update the assertion as the schema evolves.
        $this->assertSame(10, Activation::SCHEMA_VERSION);
    }

    public function testActivationCreatesBulkJobsAndItemsTables(): void
    {
        Activation::ensureSchema();

        $this->assertTableExists(BulkJobsTable::tableName());
        $this->assertTableExists(BulkJobItemsTable::tableName());
    }

    public function testV7MigrationIsIdempotent(): void
    {
        Activation::ensureSchema();
        Activation::ensureSchema(); // second call must not error
        // Schema was at v7 when this test was written. SCHEMA_VERSION tracks the
        // current version — update the assertion as the schema evolves.
        $this->assertSame(10, Activation::SCHEMA_VERSION);
    }
}
