<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Schema;

/**
 * P3.1 — wp_defyn_incidents (spec § 1).
 *
 * One row per down-event per site. ended_at/duration_seconds are NULL while
 * the incident is open (site still unreachable). Alert timestamps track
 * whether the operator notification has been dispatched for this incident.
 *
 * NOTE (plan-bug trap): no DESC in index definitions — dbDelta's parser does
 * not support it; ordering is enforced via ORDER BY in query methods.
 */
final class IncidentsTable implements SchemaTable
{
    public static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'defyn_incidents';
    }

    public static function createSql(): string
    {
        global $wpdb;
        $table   = self::tableName();
        $charset = $wpdb->get_charset_collate();

        return "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            site_id BIGINT UNSIGNED NOT NULL,
            started_at DATETIME NOT NULL,
            ended_at DATETIME NULL DEFAULT NULL,
            duration_seconds INT UNSIGNED NULL DEFAULT NULL,
            last_error TEXT NULL,
            down_alert_sent_at DATETIME NULL DEFAULT NULL,
            up_alert_sent_at DATETIME NULL DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_incidents_site (site_id, started_at),
            KEY idx_incidents_open (site_id, ended_at)
        ) {$charset};";
    }
}
