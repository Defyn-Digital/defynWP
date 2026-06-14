<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Models\SiteVulnerability;
use Defyn\Dashboard\Schema\SiteVulnerabilitiesTable;
use Defyn\Dashboard\Schema\SitesTable;

final class SiteVulnerabilitiesRepository
{
    private const SEVERITY_RANK = "CASE severity WHEN 'critical' THEN 4 WHEN 'high' THEN 3 WHEN 'medium' THEN 2 WHEN 'low' THEN 1 ELSE 0 END";

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
        return array_map([SiteVulnerability::class, 'fromRow'], $rows ?: []);
    }

    /**
     * Return DISTINCT site_ids (owned by $userId) that currently have >=1 finding.
     * Drives the Overview "sites with vulnerabilities" reason.
     *
     * @return list<int>
     */
    public function siteIdsWithFindingsForUser(int $userId): array
    {
        global $wpdb;
        $sv    = SiteVulnerabilitiesTable::tableName();
        $sites = SitesTable::tableName();
        $rows  = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT sv.site_id FROM {$sv} sv
             INNER JOIN {$sites} s ON s.id = sv.site_id
             WHERE s.user_id = %d",
            $userId
        ));
        return array_map('intval', $rows ?: []);
    }
}
