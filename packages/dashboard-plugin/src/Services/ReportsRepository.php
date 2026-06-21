<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Services;
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables: direct queries are required and results are request-scoped.

use Defyn\Dashboard\Models\Report;
use Defyn\Dashboard\Schema\ReportsTable;

final class ReportsRepository
{
    public function create(int $siteId, string $title, string $from, string $to, string $now): int
    {
        global $wpdb;
        $wpdb->insert(ReportsTable::tableName(), [
            'site_id' => $siteId, 'title' => $title, 'range_from' => $from, 'range_to' => $to,
            'status' => 'generating', 'created_at' => $now,
        ]);
        return (int) $wpdb->insert_id;
    }

    /** @return Report[] newest first */
    public function findForSite(int $siteId, int $limit, int $offset): array
    {
        global $wpdb;
        $t = ReportsTable::tableName();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t} WHERE site_id = %d ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
            $siteId, $limit, $offset
        ), ARRAY_A) ?: [];
        return array_map([Report::class, 'fromRow'], $rows);
    }

    public function countForSite(int $siteId): int
    {
        global $wpdb;
        $t = ReportsTable::tableName();
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE site_id = %d", $siteId));
    }

    public function findByIdForSite(int $reportId, int $siteId): ?Report
    {
        global $wpdb;
        $t = ReportsTable::tableName();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$t} WHERE id = %d AND site_id = %d", $reportId, $siteId
        ), ARRAY_A);
        return $row ? Report::fromRow($row) : null;
    }

    public function markReady(int $id, string $fileName, int $size, string $generatedAt): void
    {
        global $wpdb;
        $wpdb->update(ReportsTable::tableName(),
            ['status' => 'ready', 'file_name' => $fileName, 'file_size' => $size, 'generated_at' => $generatedAt, 'error_message' => null],
            ['id' => $id]);
    }

    public function markFailed(int $id, string $error): void
    {
        global $wpdb;
        $wpdb->update(ReportsTable::tableName(),
            ['status' => 'failed', 'error_message' => mb_substr($error, 0, 60000)],
            ['id' => $id]);
    }

    public function markSent(int $id, string $recipient, string $sentAt, string $method = 'manual'): void
    {
        global $wpdb;
        $wpdb->update(ReportsTable::tableName(),
            ['status' => 'sent', 'recipient_email' => $recipient, 'sent_at' => $sentAt, 'sent_method' => $method],
            ['id' => $id]);
    }

    public function delete(int $id): void
    {
        global $wpdb;
        $wpdb->delete(ReportsTable::tableName(), ['id' => $id]);
    }

    public function existsForSiteAndMonth(int $siteId, string $from, string $to): bool
    {
        global $wpdb;
        $t = ReportsTable::tableName();
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$t} WHERE site_id = %d AND range_from = %s AND range_to = %s",
            $siteId, $from, $to
        )) > 0;
    }
}
