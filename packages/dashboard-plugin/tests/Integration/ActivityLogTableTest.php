<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration;

/**
 * @group integration
 */
final class ActivityLogTableTest extends AbstractSchemaTestCase
{
    public function testActivationCreatesActivityLogTable(): void
    {
        global $wpdb;

        $this->freshlyActivate('defyn_activity_log');

        $this->assertTableExists($wpdb->prefix . 'defyn_activity_log');
    }

    public function testActivityLogTableHasRequiredColumns(): void
    {
        global $wpdb;

        $this->freshlyActivate('defyn_activity_log');

        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}defyn_activity_log", 0);

        // Spec § 4.1 — required columns
        $required = ['id', 'user_id', 'site_id', 'event_type', 'details', 'ip_address', 'created_at'];

        foreach ($required as $column) {
            self::assertContains($column, $columns, "Activity log table missing column: {$column}");
        }
    }
}
