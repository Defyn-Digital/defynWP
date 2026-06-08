<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Models\Plugin;
use Defyn\Dashboard\Schema\SitePluginsTable;

/**
 * P2.1 — wp_defyn_site_plugins read + delta write (spec § 6.3, § 7.1).
 */
final class SitePluginsRepository
{
    /** @return list<Plugin> */
    public function findAllForSite(int $siteId): array
    {
        global $wpdb;
        $table = SitePluginsTable::tableName();
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE site_id = %d ORDER BY slug ASC",
                $siteId
            ),
            ARRAY_A,
        );
        return array_map([Plugin::class, 'fromRow'], $rows ?: []);
    }

    public function lastSyncedAtForSite(int $siteId): ?string
    {
        global $wpdb;
        $table = SitePluginsTable::tableName();
        $row   = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT MAX(last_seen_at) FROM {$table} WHERE site_id = %d",
                $siteId
            ),
        );
        return $row !== null ? (string) $row : null;
    }

    /**
     * @param list<array{slug:string,name:string,version:?string,update_available:bool,update_version:?string,tested_up_to:?string}> $incoming
     */
    public function replaceForSite(int $siteId, array $incoming, string $now): void
    {
        global $wpdb;
        $table = SitePluginsTable::tableName();

        $existingRows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT slug, name, version, update_available, update_version, tested_up_to
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
            foreach ($incoming as $p) {
                $slug    = (string) $p['slug'];
                $present = $existingBySlug[$slug] ?? null;

                if ($present === null) {
                    $wpdb->insert(
                        $table,
                        [
                            'site_id'          => $siteId,
                            'slug'             => $slug,
                            'name'             => $p['name'],
                            'version'          => $p['version'],
                            'update_available' => $p['update_available'] ? 1 : 0,
                            'update_version'   => $p['update_version'],
                            'tested_up_to'     => isset($p['tested_up_to']) && $p['tested_up_to'] !== '' ? (string) $p['tested_up_to'] : null,
                            'last_seen_at'     => $now,
                            'created_at'       => $now,
                            'updated_at'       => $now,
                        ],
                        ['%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s'],
                    );
                    continue;
                }

                $hasChanged = (
                    $present['name']                 !== $p['name']           ||
                    $present['version']              !== $p['version']        ||
                    ((int) $present['update_available']) !== ($p['update_available'] ? 1 : 0) ||
                    $present['update_version']       !== $p['update_version'] ||
                    $present['tested_up_to']         !== (isset($p['tested_up_to']) && $p['tested_up_to'] !== '' ? (string) $p['tested_up_to'] : null)
                );

                if ($hasChanged) {
                    $wpdb->update(
                        $table,
                        [
                            'name'             => $p['name'],
                            'version'          => $p['version'],
                            'update_available' => $p['update_available'] ? 1 : 0,
                            'update_version'   => $p['update_version'],
                            'tested_up_to'     => isset($p['tested_up_to']) && $p['tested_up_to'] !== '' ? (string) $p['tested_up_to'] : null,
                            'last_seen_at'     => $now,
                            'updated_at'       => $now,
                        ],
                        ['site_id' => $siteId, 'slug' => $slug],
                        ['%s', '%s', '%d', '%s', '%s', '%s', '%s'],
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

    /**
     * P2.2 — fetch the raw row for a (site_id, slug) pair. Used by controller guards
     * (e.g. PluginUpdateController) before queuing an update.
     *
     * @return array<string, string|null>|null
     */
    public function findRowForSiteAndSlug(int $siteId, string $slug): ?array
    {
        global $wpdb;
        $table = SitePluginsTable::tableName();
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
     * P2.2 — transition update_state to 'queued', clear any prior error,
     * stamp last_update_attempt_at. Called when an update is enqueued.
     */
    public function markUpdateRequested(int $siteId, string $slug, string $now): void
    {
        global $wpdb;
        $wpdb->update(
            SitePluginsTable::tableName(),
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

    /**
     * P2.2 — transition update_state to 'updating' as the AS job starts work.
     */
    public function markUpdating(int $siteId, string $slug, string $now): void
    {
        global $wpdb;
        $wpdb->update(
            SitePluginsTable::tableName(),
            ['update_state' => 'updating', 'updated_at' => $now],
            ['site_id' => $siteId, 'slug' => $slug],
            ['%s', '%s'],
            ['%d', '%s'],
        );
    }

    /**
     * P2.2 — successful update: clear the badge, bump version, drop any
     * lingering error, and return state to 'idle'.
     */
    public function markUpdateSucceeded(int $siteId, string $slug, string $newVersion, string $now): void
    {
        global $wpdb;
        $wpdb->update(
            SitePluginsTable::tableName(),
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

    /**
     * P2.2 — failed update: record truncated error (max 1000 chars per spec),
     * keep version untouched, set state to 'failed'.
     */
    public function markUpdateFailed(int $siteId, string $slug, string $errorMessage, string $now): void
    {
        global $wpdb;
        $wpdb->update(
            SitePluginsTable::tableName(),
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
     * P2.2.1 — clears stuck-failed rows whose upgrade actually succeeded.
     *
     * The headline case: P2.2 connector pre-`7a05d48` returned HTTP 200 but
     * with a body shape the dashboard's UpdateSitePlugin couldn't parse —
     * we marked the row failed even though Plugin_Upgrader had successfully
     * advanced the version on disk. On the next inventory sync, the row
     * comes back with the new version + update_available=false, but
     * update_state stays 'failed' from the prior write. The SPA then
     * renders a confusing Retry-on-no-update state.
     *
     * Auto-heal rule: if a row is currently `failed` AND no update is
     * available, the previous target must have landed (otherwise the
     * connector would still report it as upgradeable). Reset state to
     * idle, clear the stale error.
     *
     * Conservative — does NOT touch rows where update_available=1, because
     * there a `failed` state still has semantic value (the operator needs
     * to see the prior error before clicking Retry on a fresh target).
     * Returns the rows-affected count.
     */
    public function healDanglingFailedStates(int $siteId, string $now): int
    {
        global $wpdb;
        $table = SitePluginsTable::tableName();
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
     * P2.7 — flat list of every (site, plugin) pair with update_available=1 across
     * all sites owned by $userId. Drives the SPA's "Bulk update plugins" confirm
     * dialog. ORDER BY site label, then plugin name for a stable display order.
     *
     * Returns rows with keys: site_id, site_label, slug, plugin_name,
     * current_version, target_version.
     *
     * @return list<array{site_id:int,site_label:string,slug:string,plugin_name:string,current_version:string,target_version:?string}>
     */
    public function findAllPendingUpdatesForUser(int $userId): array
    {
        global $wpdb;
        $sitesTable   = $wpdb->prefix . 'defyn_sites';
        $pluginsTable = $wpdb->prefix . 'defyn_site_plugins';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT s.id AS site_id, s.label AS site_label,
                    sp.slug, sp.name AS plugin_name,
                    sp.version AS current_version, sp.update_version AS target_version
             FROM {$sitesTable} s
             INNER JOIN {$pluginsTable} sp ON sp.site_id = s.id
             WHERE s.user_id = %d
               AND sp.update_available = 1
             ORDER BY s.label, sp.name",
            $userId
        ), ARRAY_A);

        if (!is_array($rows)) {
            return [];
        }

        return array_map(static fn(array $row) => [
            'site_id'         => (int) $row['site_id'],
            'site_label'      => (string) $row['site_label'],
            'slug'            => (string) $row['slug'],
            'plugin_name'     => (string) $row['plugin_name'],
            'current_version' => (string) $row['current_version'],
            'target_version'  => $row['target_version'] !== null ? (string) $row['target_version'] : null,
        ], $rows);
    }
}
