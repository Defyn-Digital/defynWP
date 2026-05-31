<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Models\ActivityEvent;
use Defyn\Dashboard\Schema\ActivityLogTable;
use Defyn\Dashboard\Schema\SitesTable;

/**
 * Sole SQL touch-point for wp_defyn_activity_log (F9 Task 1). Writers
 * (ActivityLogger, F9 Task 2 onward) delegate INSERTs here; controllers
 * issue paginated SELECTs via paginateForUser/countForUser.
 *
 * User-scoped reads: an event is "for" user U if it has user_id=U OR its
 * site_id belongs to a site owned by U. Anti-leak: events for sites owned
 * by other users never surface in U's feed. The site-ownership branch is
 * enforced via a subquery against wp_defyn_sites — the SQL is the
 * security boundary.
 */
final class ActivityLogRepository
{
    public const MAX_PER_PAGE = 200;

    /**
     * Insert a new event row. Returns the inserted id.
     *
     * @param array<string, mixed>|null $details
     */
    public function insert(
        ?int $userId,
        ?int $siteId,
        string $eventType,
        ?array $details = null,
        ?string $ipAddress = null,
    ): int {
        global $wpdb;
        $wpdb->insert(
            ActivityLogTable::tableName(),
            [
                'user_id'    => $userId,
                'site_id'    => $siteId,
                'event_type' => $eventType,
                'details'    => $details === null ? null : json_encode($details, JSON_THROW_ON_ERROR),
                'ip_address' => $ipAddress,
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
        return (int) $wpdb->insert_id;
    }

    /**
     * Newest-first user-scoped feed. Optional filters: site_id, event_type.
     * Defends against weird input by clamping perPage to [1, MAX_PER_PAGE]
     * and page to >= 1.
     *
     * @return list<ActivityEvent>
     */
    public function paginateForUser(
        int $userId,
        ?int $siteId,
        ?string $eventType,
        int $page,
        int $perPage,
    ): array {
        global $wpdb;
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));
        $page    = max(1, $page);
        $offset  = ($page - 1) * $perPage;

        [$where, $args] = $this->buildWhere($userId, $siteId, $eventType);
        $table = ActivityLogTable::tableName();
        // ORDER BY id DESC is a stable tiebreaker for same-second events.
        $sql = "SELECT a.* FROM {$table} a {$where} "
             . "ORDER BY a.created_at DESC, a.id DESC LIMIT %d OFFSET %d";
        $args[] = $perPage;
        $args[] = $offset;

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A) ?: [];
        return array_map([ActivityEvent::class, 'fromRow'], $rows);
    }

    public function countForUser(int $userId, ?int $siteId, ?string $eventType): int
    {
        global $wpdb;
        [$where, $args] = $this->buildWhere($userId, $siteId, $eventType);
        $table = ActivityLogTable::tableName();
        $sql = "SELECT COUNT(*) FROM {$table} a {$where}";
        return (int) $wpdb->get_var($wpdb->prepare($sql, ...$args));
    }

    /**
     * Build the user-scoped WHERE clause + bound args list. Centralised so
     * paginate + count share exactly the same scoping (anti-drift).
     *
     * @return array{0: string, 1: list<scalar>}
     */
    private function buildWhere(int $userId, ?int $siteId, ?string $eventType): array
    {
        $sitesTable = SitesTable::tableName();
        // An event belongs to user U if user_id=U OR site_id belongs to one of U's sites.
        $clauses = ["(a.user_id = %d OR a.site_id IN (SELECT id FROM {$sitesTable} WHERE user_id = %d))"];
        $args    = [$userId, $userId];

        if ($siteId !== null) {
            $clauses[] = "a.site_id = %d";
            $args[]    = $siteId;
        }
        if ($eventType !== null) {
            $clauses[] = "a.event_type = %s";
            $args[]    = $eventType;
        }

        return ['WHERE ' . implode(' AND ', $clauses), $args];
    }
}
