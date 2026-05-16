<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Schema\ActivityLogTable;

/**
 * Writes rows to wp_defyn_activity_log. The only writer for that table.
 *
 * Event types are free-form strings prefixed with a domain: F5 uses
 * `site.connected`, `site.connection_rejected`, `site.error`. F6+ will
 * add `sync.*` and `health.*`.
 */
final class ActivityLogger
{
    public function log(?int $userId, ?int $siteId, string $eventType, ?array $details = null): void
    {
        global $wpdb;
        $wpdb->insert(
            ActivityLogTable::tableName(),
            [
                'user_id'    => $userId,
                'site_id'    => $siteId,
                'event_type' => $eventType,
                'details'    => $details === null ? null : json_encode($details, JSON_THROW_ON_ERROR),
                'ip_address' => null,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ],
            [
                $userId === null ? '%s' : '%d',
                $siteId === null ? '%s' : '%d',
                '%s',
                '%s',
                '%s',
                '%s',
            ],
        );
    }
}
