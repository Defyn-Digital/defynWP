<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Schema;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Schema\SchemaVersion;
use Defyn\Dashboard\Schema\SiteBrokenLinksTable;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class BrokenLinksSchemaTest extends AbstractSchemaTestCase
{
    public function testActivationBumpsSchemaTo17(): void
    {
        Activation::activate();
        self::assertSame(17, Activation::SCHEMA_VERSION);
        self::assertSame(17, SchemaVersion::current());
    }

    public function testBrokenLinksTableExists(): void
    {
        global $wpdb;
        Activation::activate();
        $t = SiteBrokenLinksTable::tableName();
        self::assertSame($t, $wpdb->get_var("SHOW TABLES LIKE '{$t}'")); // phpcs:ignore
    }

    public function testSitesHasLastLinkScanAtColumn(): void
    {
        global $wpdb;
        Activation::activate();
        $col = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `" . SitesTable::tableName() . "` LIKE %s", 'last_link_scan_at'));
        self::assertSame('last_link_scan_at', $col);
    }
}
