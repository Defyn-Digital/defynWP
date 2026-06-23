<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Services;
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables: direct queries are required and results are request-scoped.

use Defyn\Dashboard\Models\SiteVulnerability;
use Defyn\Dashboard\Schema\DismissedVulnerabilitiesTable;
use Defyn\Dashboard\Schema\SiteVulnerabilitiesTable;
use Defyn\Dashboard\Schema\SitesTable;

final class SiteVulnerabilitiesRepository
{
    private const SEVERITY_RANK = "CASE severity WHEN 'critical' THEN 4 WHEN 'high' THEN 3 WHEN 'medium' THEN 2 WHEN 'low' THEN 1 ELSE 0 END";

    public function __construct(
        private readonly ?DismissedVulnerabilitiesRepository $dismissals = null,
    ) {}

    /**
     * Replace all findings for a site with the new snapshot, inside a transaction.
     *
     * @param list<array{type:string,slug:string,component_name:string,installed_version:string,severity:string,cvss_score:?float,cve:?string,fixed_in:?string,title:?string,source_id:string}> $findings
     */
    public function replaceForSite(int $siteId, array $findings, string $now): void
    {
        global $wpdb;
        $table = SiteVulnerabilitiesTable::tableName();

        $wpdb->query('START TRANSACTION');
        try {
            $wpdb->delete($table, ['site_id' => $siteId], ['%d']);
            foreach ($findings as $f) {
                $wpdb->insert($table, [
                    'site_id'           => $siteId,
                    'type'              => $f['type'],
                    'slug'              => $f['slug'],
                    'component_name'    => $f['component_name'],
                    'installed_version' => $f['installed_version'],
                    'severity'          => $f['severity'],
                    'cvss_score'        => $f['cvss_score'],
                    'cve'               => $f['cve'],
                    'fixed_in'          => $f['fixed_in'],
                    'title'             => $f['title'],
                    'source_id'         => $f['source_id'],
                    'scanned_at'        => $now,
                    'created_at'        => $now,
                ]);
            }
            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    /**
     * Return all findings for a site, sorted severity-desc (critical>high>medium>low>unknown)
     * then component_name asc.
     *
     * @return list<SiteVulnerability>
     */
    public function findForSite(int $siteId): array
    {
        global $wpdb;
        $table = SiteVulnerabilitiesTable::tableName();
        $rank  = self::SEVERITY_RANK;
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE site_id = %d ORDER BY {$rank} DESC, component_name ASC",
                $siteId
            ),
            ARRAY_A
        );
        $dismissed = ($this->dismissals ?? new DismissedVulnerabilitiesRepository())
            ->findFingerprintsForSite($siteId);

        return array_map(static function (array $row) use ($dismissed): SiteVulnerability {
            $v  = SiteVulnerability::fromRow($row);
            $fp = $v->type . '|' . $v->slug . '|' . $v->sourceId;
            return $v->withDismissed(isset($dismissed[$fp]));
        }, $rows ?: []);
    }

    /**
     * P4.2 — fleet rollup: every site owned by $userId LEFT JOIN its findings,
     * one row per site with per-severity counts. Clean (scanned, no findings) and
     * never-scanned sites both come back with zero counts; they are distinguished
     * by `last_security_scan_at` (set vs null). One GROUP BY query — no N+1.
     *
     * @return list<array{site_id:int,label:string,url:string,last_security_scan_at:?string,critical:int,high:int,medium:int,low:int,total:int}>
     */
    public function findFleetSummariesForUser(int $userId): array
    {
        global $wpdb;
        $sv        = SiteVulnerabilitiesTable::tableName();
        $sites     = SitesTable::tableName();
        $dismissed = DismissedVulnerabilitiesTable::tableName();

        // Team-shared fleet: per-user filter intentionally removed (2026-06-22 SSO spec).
        // phpcs:ignore WordPress.DB.PreparedSQL
        $rows = $wpdb->get_results(
            "SELECT s.id AS site_id, s.label AS label, s.url AS url,
                    s.last_security_scan_at AS last_security_scan_at,
                    SUM(CASE WHEN sv.severity = 'critical' THEN 1 ELSE 0 END) AS critical,
                    SUM(CASE WHEN sv.severity = 'high'     THEN 1 ELSE 0 END) AS high,
                    SUM(CASE WHEN sv.severity = 'medium'   THEN 1 ELSE 0 END) AS medium,
                    SUM(CASE WHEN sv.severity = 'low'      THEN 1 ELSE 0 END) AS low,
                    COUNT(sv.id) AS total
             FROM {$sites} s
             LEFT JOIN {$sv} sv ON sv.site_id = s.id
                 AND NOT EXISTS (
                     SELECT 1 FROM {$dismissed} d
                     WHERE d.site_id = sv.site_id AND d.type = sv.type
                       AND d.slug = sv.slug AND d.source_id = sv.source_id
                 )
             GROUP BY s.id, s.label, s.url, s.last_security_scan_at
             ORDER BY s.id ASC",
            ARRAY_A
        );

        return array_map(static fn (array $r): array => [
            'site_id'               => (int) $r['site_id'],
            'label'                 => (string) $r['label'],
            'url'                   => (string) $r['url'],
            'last_security_scan_at' => $r['last_security_scan_at'] !== null ? (string) $r['last_security_scan_at'] : null,
            'critical'              => (int) $r['critical'],
            'high'                  => (int) $r['high'],
            'medium'                => (int) $r['medium'],
            'low'                   => (int) $r['low'],
            'total'                 => (int) $r['total'],
        ], $rows ?: []);
    }
}
