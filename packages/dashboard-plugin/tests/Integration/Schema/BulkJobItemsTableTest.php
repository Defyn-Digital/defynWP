<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Schema;

use Defyn\Dashboard\Schema\BulkJobItemsTable;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class BulkJobItemsTableTest extends AbstractSchemaTestCase
{
    public function testTableNameIsPrefixed(): void
    {
        global $wpdb;
        $this->assertSame($wpdb->prefix . 'defyn_bulk_job_items', BulkJobItemsTable::tableName());
    }

    public function testCreateSqlHasAllRequiredColumns(): void
    {
        $sql = BulkJobItemsTable::createSql();

        foreach ([
            'id', 'job_id', 'site_id', 'resource_slug', 'state',
            'error_message', 'started_at', 'completed_at', 'created_at',
        ] as $column) {
            $this->assertStringContainsString($column, $sql, "createSql must declare {$column}");
        }
    }

    public function testTableExistsAfterActivation(): void
    {
        \Defyn\Dashboard\Activation::activate();
        $this->assertTableExists(BulkJobItemsTable::tableName());
    }

    public function testStateDefaultsToQueuedAndIndexesExist(): void
    {
        \Defyn\Dashboard\Activation::activate();
        $cols = $this->describeTable(BulkJobItemsTable::tableName());

        $this->assertSame('queued', $cols['state']['Default']);
        $this->assertSame('NO', $cols['state']['Null']);
        $this->assertSame('YES', $cols['error_message']['Null']);
        $this->assertSame('YES', $cols['started_at']['Null']);
        $this->assertSame('YES', $cols['completed_at']['Null']);

        $this->assertHasIndex(BulkJobItemsTable::tableName(), 'idx_job_state');
        $this->assertHasIndex(BulkJobItemsTable::tableName(), 'idx_state_completed');
    }
}
