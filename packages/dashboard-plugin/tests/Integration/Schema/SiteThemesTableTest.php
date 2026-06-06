<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Schema;

use Defyn\Dashboard\Schema\SiteThemesTable;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SiteThemesTableTest extends AbstractSchemaTestCase
{
    public function testTableNameIsPrefixed(): void
    {
        global $wpdb;
        $this->assertSame($wpdb->prefix . 'defyn_site_themes', SiteThemesTable::tableName());
    }

    public function testCreateSqlHasAllRequiredColumns(): void
    {
        $sql = SiteThemesTable::createSql();

        foreach ([
            'id', 'site_id', 'slug', 'name', 'version', 'parent_slug', 'is_active',
            'update_available', 'update_version', 'update_state', 'last_update_error',
            'last_update_attempt_at', 'last_seen_at', 'created_at', 'updated_at',
        ] as $column) {
            $this->assertStringContainsString($column, $sql, "createSql must declare {$column}");
        }
    }

    public function testCreateSqlHasUniqueSiteSlug(): void
    {
        $sql = SiteThemesTable::createSql();
        $this->assertMatchesRegularExpression('/UNIQUE\s+KEY\s+\w*site_slug\s*\(site_id,\s*slug\)/i', $sql);
    }

    public function testTableExistsAfterActivation(): void
    {
        \Defyn\Dashboard\Activation::activate();
        $this->assertTableExists(SiteThemesTable::tableName());
    }

    public function testColumnTypesAreCorrect(): void
    {
        \Defyn\Dashboard\Activation::activate();
        $cols = $this->describeTable(SiteThemesTable::tableName());

        $this->assertSame('NO', $cols['is_active']['Null']);
        $this->assertSame('0', $cols['is_active']['Default']);
        $this->assertSame('YES', $cols['parent_slug']['Null']);
        $this->assertSame('YES', $cols['version']['Null']);
        $this->assertSame('YES', $cols['update_version']['Null']);
        $this->assertSame('YES', $cols['last_update_error']['Null']);
        $this->assertSame('YES', $cols['last_update_attempt_at']['Null']);
    }

    public function testSecondaryIndexesExist(): void
    {
        \Defyn\Dashboard\Activation::activate();
        $this->assertHasIndex(SiteThemesTable::tableName(), 'idx_site_id');
        $this->assertHasIndex(SiteThemesTable::tableName(), 'idx_update_available');
    }
}
