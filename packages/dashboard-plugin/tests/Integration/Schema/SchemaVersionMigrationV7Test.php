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
        $this->assertSame(7, Activation::SCHEMA_VERSION);
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
        $this->assertSame(7, Activation::SCHEMA_VERSION);
    }
}
