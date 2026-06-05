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
     * @param list<array{slug:string,name:string,version:?string,update_available:bool,update_version:?string}> $incoming
     */
    public function replaceForSite(int $siteId, array $incoming, string $now): void
    {
        global $wpdb;
        $table = SitePluginsTable::tableName();

        $existingRows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT slug, name, version, update_available, update_version
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
                            'last_seen_at'     => $now,
                            'created_at'       => $now,
                            'updated_at'       => $now,
                        ],
                        ['%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s'],
                    );
                    continue;
                }

                $hasChanged = (
                    $present['name']                 !== $p['name']           ||
                    $present['version']              !== $p['version']        ||
                    ((int) $present['update_available']) !== ($p['update_available'] ? 1 : 0) ||
                    $present['update_version']       !== $p['update_version']
                );

                if ($hasChanged) {
                    $wpdb->update(
                        $table,
                        [
                            'name'             => $p['name'],
                            'version'          => $p['version'],
                            'update_available' => $p['update_available'] ? 1 : 0,
                            'update_version'   => $p['update_version'],
                            'last_seen_at'     => $now,
                            'updated_at'       => $now,
                        ],
                        ['site_id' => $siteId, 'slug' => $slug],
                        ['%s', '%s', '%d', '%s', '%s', '%s'],
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
}
