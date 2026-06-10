<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Schema;

use Defyn\Dashboard\Schema\BulkJobsTable;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class BulkJobsTableTest extends AbstractSchemaTestCase
{
    public function testTableNameIsPrefixed(): void
    {
        global $wpdb;
        $this->assertSame($wpdb->prefix . 'defyn_bulk_jobs', BulkJobsTable::tableName());
    }

    public function testCreateSqlHasAllRequiredColumns(): void
    {
        $sql = BulkJobsTable::createSql();

        foreach ([
            'id', 'user_id', 'kind', 'scheduled_count', 'skipped_count',
            'started_at', 'completed_at', 'created_at',
        ] as $column) {
            $this->assertStringContainsString($column, $sql, "createSql must declare {$column}");
        }
    }

    public function testTableExistsAfterActivation(): void
    {
        \Defyn\Dashboard\Activation::activate();
        $this->assertTableExists(BulkJobsTable::tableName());
    }

    public function testColumnTypesAndIndex(): void
    {
        \Defyn\Dashboard\Activation::activate();
        $cols = $this->describeTable(BulkJobsTable::tableName());

        $this->assertSame('NO', $cols['user_id']['Null']);
        $this->assertSame('NO', $cols['kind']['Null']);
        $this->assertSame('0', $cols['scheduled_count']['Default']);
        $this->assertSame('0', $cols['skipped_count']['Default']);
        $this->assertSame('YES', $cols['started_at']['Null']);
        $this->assertSame('YES', $cols['completed_at']['Null']);
        $this->assertSame('NO', $cols['created_at']['Null']);

        $this->assertHasIndex(BulkJobsTable::tableName(), 'idx_user_created');
    }
}
