<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Services;
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables: direct queries are required and results are request-scoped.

use Defyn\Dashboard\Schema\VulnerabilitiesTable;

final class VulnerabilitiesRepository
{
    /**
     * Replace all cached ranges for one feed vuln (source_id) with the given set.
     * Idempotent per source: a DELETE-then-INSERT inside a transaction.
     *
     * @param list<array{type:string,slug:string,title:?string,severity:string,cvss_score:?float,cve:?string,from_version:?string,from_inclusive:bool,to_version:?string,to_inclusive:bool,fixed_in:?string,updated_at:string}> $rows
     */
    public function upsertForSource(string $sourceId, array $rows): void
    {
        global $wpdb;
        $table = VulnerabilitiesTable::tableName();

        $wpdb->query('START TRANSACTION');
        try {
            $wpdb->delete($table, ['source_id' => $sourceId], ['%s']);
            foreach ($rows as $r) {
                $wpdb->insert($table, [
                    'source_id'      => $sourceId,
                    'type'           => $r['type'],
                    'slug'           => $r['slug'],
                    'title'          => $r['title'],
                    'severity'       => $r['severity'],
                    'cvss_score'     => $r['cvss_score'],
                    'cve'            => $r['cve'],
                    'from_version'   => $r['from_version'],
                    'from_inclusive' => $r['from_inclusive'] ? 1 : 0,
                    'to_version'     => $r['to_version'],
                    'to_inclusive'   => $r['to_inclusive'] ? 1 : 0,
                    'fixed_in'       => $r['fixed_in'],
                    'updated_at'     => $r['updated_at'],
                ]);
            }
            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    /** @return list<array<string,mixed>> raw rows for the matcher */
    public function findByTypeAndSlug(string $type, string $slug): array
    {
        global $wpdb;
        $table = VulnerabilitiesTable::tableName();
        $rows  = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} WHERE type = %s AND slug = %s", $type, $slug),
            ARRAY_A
        );
        return $rows ?: [];
    }

    public function countAll(): int
    {
        global $wpdb;
        $table = VulnerabilitiesTable::tableName();
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }
}
