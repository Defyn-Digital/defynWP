<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Schema;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/** P3.3 — schema v10: alerts_muted + ssl_alert_sent_at on wp_defyn_sites. @group integration */
final class AlertConfigColumnsTest extends AbstractSchemaTestCase
{
    public function testSchemaVersionConstantIsTen(): void
    {
        self::assertSame(10, Activation::SCHEMA_VERSION);
    }

    public function testAlertConfigColumnsExistAfterEnsureSchema(): void
    {
        $this->freshlyActivate('defyn_sites');
        Activation::ensureSchema();

        global $wpdb;
        $table = SitesTable::tableName();
        self::assertSame('alerts_muted', $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'alerts_muted')));
        self::assertSame('ssl_alert_sent_at', $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'ssl_alert_sent_at')));
    }

    public function testGuardedAltersAreIdempotent(): void
    {
        $this->freshlyActivate('defyn_sites');
        Activation::ensureSchema();
        Activation::ensureSchema();

        global $wpdb;
        $table = SitesTable::tableName();
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_NAME = %s AND COLUMN_NAME IN ('alerts_muted','ssl_alert_sent_at')",
            $table
        ));
        self::assertSame('2', (string) $count);
    }
}
