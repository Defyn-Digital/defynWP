<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Models\Theme;
use Defyn\Dashboard\Schema\SiteThemesTable;

/**
 * P2.3 — wp_defyn_site_themes read + delta write (spec § 4.2).
 *
 * Direct mirror of SitePluginsRepository with two theme-specific additions:
 *   - `parent_slug` is tracked in the change-detection tuple, so child→standalone
 *     theme transitions (rare, but possible) flow through `replaceForSite`.
 *   - `is_active` is tracked in the change-detection tuple, so a sync where the
 *     operator switched the active stylesheet flips both the old and new rows
 *     inside one transaction.
 *
 * `healDanglingFailedStates` ships from day 1 — no P2.2.1-style retrofit (spec § 4.2).
 */
final class ThemesRepository
{
    /** @return list<Theme> */
    public function findAllForSite(int $siteId): array
    {
        global $wpdb;
        $table = SiteThemesTable::tableName();
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE site_id = %d ORDER BY slug ASC",
                $siteId
            ),
            ARRAY_A,
        );
        return array_map([Theme::class, 'fromRow'], $rows ?: []);
    }

    public function lastSyncedAtForSite(int $siteId): ?string
    {
        global $wpdb;
        $table = SiteThemesTable::tableName();
        $row   = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT MAX(last_seen_at) FROM {$table} WHERE site_id = %d",
                $siteId
            ),
        );
        return $row !== null ? (string) $row : null;
    }

    /** @return array<string, string|null>|null */
    public function findRowForSiteAndSlug(int $siteId, string $slug): ?array
    {
        global $wpdb;
        $table = SiteThemesTable::tableName();
        $row   = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE site_id = %d AND slug = %s",
                $siteId,
                $slug
            ),
            ARRAY_A,
        );
        return $row ?: null;
    }

    /**
     * @param list<array{slug:string,name:string,version:?string,parent_slug:?string,is_active:bool,update_available:bool,update_version:?string,tested_up_to:?string}> $incoming
     */
    public function replaceForSite(int $siteId, array $incoming, string $now): void
    {
        global $wpdb;
        $table = SiteThemesTable::tableName();

        $existingRows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT slug, name, version, parent_slug, is_active, update_available, update_version, tested_up_to
                 FROM {$table} WHERE site_id = %d",
                $siteId
            ),
            ARRAY_A,
        );
        $existingBySlug = [];
        foreach ($existingRows ?: [] as $r) {
            $existingBySlug[$r['slug']] = $r;
        }

        $incomingSlugs = array_column($incoming, 'slug');

        $wpdb->query('START TRANSACTION');
        try {
            foreach ($incoming as $t) {
                $slug    = (string) $t['slug'];
                $present = $existingBySlug[$slug] ?? null;

                if ($present === null) {
                    $wpdb->insert(
                        $table,
                        [
                            'site_id'          => $siteId,
                            'slug'             => $slug,
                            'name'             => $t['name'],
                            'version'          => $t['version'],
                            'parent_slug'      => $t['parent_slug'],
                            'is_active'        => $t['is_active'] ? 1 : 0,
                            'update_available' => $t['update_available'] ? 1 : 0,
                            'update_version'   => $t['update_version'],
                            'tested_up_to'     => isset($t['tested_up_to']) && $t['tested_up_to'] !== '' ? (string) $t['tested_up_to'] : null,
                            'last_seen_at'     => $now,
                            'created_at'       => $now,
                            'updated_at'       => $now,
                        ],
                        ['%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s'],
                    );
                    continue;
                }

                $hasChanged = (
                    $present['name']                     !== $t['name']           ||
                    $present['version']                  !== $t['version']        ||
                    $present['parent_slug']              !== $t['parent_slug']    ||
                    ((int) $present['is_active'])        !== ($t['is_active'] ? 1 : 0)        ||
                    ((int) $present['update_available']) !== ($t['update_available'] ? 1 : 0) ||
                    $present['update_version']           !== $t['update_version'] ||
                    $present['tested_up_to']             !== (isset($t['tested_up_to']) && $t['tested_up_to'] !== '' ? (string) $t['tested_up_to'] : null)
                );

                if ($hasChanged) {
                    $wpdb->update(
                        $table,
                        [
                            'name'             => $t['name'],
                            'version'          => $t['version'],
                            'parent_slug'      => $t['parent_slug'],
                            'is_active'        => $t['is_active'] ? 1 : 0,
                            'update_available' => $t['update_available'] ? 1 : 0,
                            'update_version'   => $t['update_version'],
                            'tested_up_to'     => isset($t['tested_up_to']) && $t['tested_up_to'] !== '' ? (string) $t['tested_up_to'] : null,
                            'last_seen_at'     => $now,
                            'updated_at'       => $now,
                        ],
                        ['site_id' => $siteId, 'slug' => $slug],
                        ['%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s'],
                        ['%d', '%s'],
                    );
                } else {
                    $wpdb->update(
                        $table,
                        ['last_seen_at' => $now],
                        ['site_id' => $siteId, 'slug' => $slug],
                        ['%s'],
                        ['%d', '%s'],
                    );
                }
            }

            $toDelete = array_diff(array_keys($existingBySlug), $incomingSlugs);
            foreach ($toDelete as $slug) {
                $wpdb->delete(
                    $table,
                    ['site_id' => $siteId, 'slug' => $slug],
                    ['%d', '%s'],
                );
            }

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    public function markUpdateRequested(int $siteId, string $slug, string $now): void
    {
        global $wpdb;
        $wpdb->update(
            SiteThemesTable::tableName(),
            [
                'update_state'           => 'queued',
                'last_update_error'      => null,
                'last_update_attempt_at' => $now,
                'updated_at'             => $now,
            ],
            ['site_id' => $siteId, 'slug' => $slug],
            ['%s', '%s', '%s', '%s'],
            ['%d', '%s'],
        );
    }

    public function markUpdating(int $siteId, string $slug, string $now): void
    {
        global $wpdb;
        $wpdb->update(
            SiteThemesTable::tableName(),
            ['update_state' => 'updating', 'updated_at' => $now],
            ['site_id' => $siteId, 'slug' => $slug],
            ['%s', '%s'],
            ['%d', '%s'],
        );
    }

    public function markUpdateSucceeded(int $siteId, string $slug, string $newVersion, string $now): void
    {
        global $wpdb;
        $wpdb->update(
            SiteThemesTable::tableName(),
            [
                'update_state'      => 'idle',
                'version'           => $newVersion,
                'update_available'  => 0,
                'update_version'    => null,
                'last_update_error' => null,
                'updated_at'        => $now,
            ],
            ['site_id' => $siteId, 'slug' => $slug],
            ['%s', '%s', '%d', '%s', '%s', '%s'],
            ['%d', '%s'],
        );
    }

    public function markUpdateFailed(int $siteId, string $slug, string $errorMessage, string $now): void
    {
        global $wpdb;
        $wpdb->update(
            SiteThemesTable::tableName(),
            [
                'update_state'           => 'failed',
                'last_update_error'      => substr($errorMessage, 0, 1000),
                'last_update_attempt_at' => $now,
                'updated_at'             => $now,
            ],
            ['site_id' => $siteId, 'slug' => $slug],
            ['%s', '%s', '%s', '%s'],
            ['%d', '%s'],
        );
    }

    /**
     * Day-1 heal sweep — included from P2.3 start, not retrofitted like
     * SitePluginsRepository::healDanglingFailedStates was.
     *
     * Auto-heal rule: a row currently `failed` with NO update available
     * implies the prior upgrade actually landed (otherwise the connector
     * would still report it upgradeable). Reset state to idle, clear the
     * stale error. Conservative — does NOT touch rows where
     * update_available=1 (there, the failed state still has semantic value).
     * Returns rows-affected count.
     */
    public function healDanglingFailedStates(int $siteId, string $now): int
    {
        global $wpdb;
        $table = SiteThemesTable::tableName();
        $result = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table}
                 SET update_state = 'idle', last_update_error = NULL, updated_at = %s
                 WHERE site_id = %d AND update_state = 'failed' AND update_available = 0",
                $now,
                $siteId
            )
        );
        return $result === false ? 0 : (int) $result;
    }

    /**
     * P2.8 — INNER JOIN'd query feeding the operator's bulk-update-themes dialog.
     *
     * Returns rows with keys: site_id, site_label, slug, theme_name,
     * current_version, target_version.
     *
     * @return list<array{site_id:int,site_label:string,slug:string,theme_name:string,current_version:string,target_version:?string}>
     */
    public function findAllPendingUpdatesForUser(int $userId): array
    {
        global $wpdb;
        $sitesTable  = $wpdb->prefix . 'defyn_sites';
        $themesTable = $wpdb->prefix . 'defyn_site_themes';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT s.id AS site_id, s.label AS site_label,
                    st.slug, st.name AS theme_name,
                    st.version AS current_version, st.update_version AS target_version
             FROM {$sitesTable} s
             INNER JOIN {$themesTable} st ON st.site_id = s.id
             WHERE s.user_id = %d
               AND st.update_available = 1
             ORDER BY s.label, st.name",
            $userId
        ), ARRAY_A);

        if (!is_array($rows)) {
            return [];
        }

        return array_map(static fn(array $row) => [
            'site_id'         => (int) $row['site_id'],
            'site_label'      => (string) $row['site_label'],
            'slug'            => (string) $row['slug'],
            'theme_name'      => (string) $row['theme_name'],
            'current_version' => (string) $row['current_version'],
            'target_version'  => $row['target_version'] !== null ? (string) $row['target_version'] : null,
        ], $rows);
    }
}
