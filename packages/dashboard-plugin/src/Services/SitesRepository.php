<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Models\Site;
use Defyn\Dashboard\Schema\SitesTable;

/**
 * Thin wrapper over wpdb for wp_defyn_sites — the only class that issues
 * raw SQL for that table. Controllers + AS jobs call this; tests assert
 * persistence through it. Other classes never touch wpdb for sites directly.
 */
final class SitesRepository
{
    public function insertPending(
        int    $userId,
        string $url,
        string $label,
        string $ourPublicKey,
        string $ourPrivateKeyEncrypted,
    ): int {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $result = $wpdb->insert(
            SitesTable::tableName(),
            [
                'user_id'         => $userId,
                'url'             => $url,
                'label'           => $label,
                'status'          => 'pending',
                'our_public_key'  => $ourPublicKey,
                'our_private_key' => $ourPrivateKeyEncrypted,
                'created_at'      => $now,
                'updated_at'      => $now,
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s'],
        );
        if ($result === false || (int) $wpdb->insert_id === 0) {
            // Don't swallow MySQL errors silently — the caller turns this into a
            // 500 with the actual driver message so production misconfigurations
            // surface instead of returning {site_id: 0} forever.
            throw new \RuntimeException(
                $wpdb->last_error !== ''
                    ? 'wp_defyn_sites insert failed: ' . $wpdb->last_error
                    : 'wp_defyn_sites insert failed without a MySQL error message.'
            );
        }
        return (int) $wpdb->insert_id;
    }

    public function findById(int $id): ?Site
    {
        global $wpdb;
        $table = SitesTable::tableName();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);
        return $row ? Site::fromRow($row) : null;
    }

    public function findByIdForUser(int $id, int $userId): ?Site
    {
        $site = $this->findById($id);
        if ($site === null || $site->userId !== $userId) {
            return null;
        }
        return $site;
    }

    /**
     * User-scoped delete. Returns true if a row was deleted (caller is the owner),
     * false if not found OR not owned. Caller must NOT echo "deleted" on false —
     * use the same 404 envelope as an unowned-site lookup.
     */
    public function deleteForUser(int $id, int $userId): bool
    {
        global $wpdb;
        $affected = $wpdb->delete(
            SitesTable::tableName(),
            ['id' => $id, 'user_id' => $userId],
            ['%d', '%d'],
        );
        return (int) $affected === 1;
    }

    /** @return list<Site> */
    public function findAllForUser(int $userId): array
    {
        global $wpdb;
        $table = SitesTable::tableName();
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} WHERE user_id = %d ORDER BY id ASC", $userId),
            ARRAY_A,
        );
        return array_map([Site::class, 'fromRow'], $rows ?: []);
    }

    /**
     * Site IDs eligible for background sync/ping. Active + offline + error (all
     * have a completed handshake and a private key on file; even error sites
     * might recover). Excludes pending (handshake not yet complete).
     *
     * TODO (F10+): paginate when sites > 500 — current naive LIMIT keeps the
     * fan-out within Kinsta's 300s PHP budget.
     *
     * @return list<int>
     */
    public function findAllSchedulable(int $limit = 500): array
    {
        global $wpdb;
        $table = SitesTable::tableName();
        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE status IN ('active', 'offline', 'error') ORDER BY id ASC LIMIT %d",
                $limit,
            ),
        );
        return array_map('intval', $rows ?: []);
    }

    public function existsForUser(int $userId, string $url): bool
    {
        global $wpdb;
        $table = SitesTable::tableName();
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND LOWER(url) = %s",
                $userId,
                strtolower($url),
            ),
        );
        return $count > 0;
    }

    public function markActive(int $id, string $sitePublicKey): void
    {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->update(
            SitesTable::tableName(),
            [
                'status'          => 'active',
                'site_public_key' => $sitePublicKey,
                'last_contact_at' => $now,
                'updated_at'      => $now,
            ],
            ['id' => $id],
            ['%s', '%s', '%s', '%s'],
            ['%d'],
        );
    }

    public function markError(int $id, string $message): void
    {
        global $wpdb;
        $wpdb->update(
            SitesTable::tableName(),
            [
                'status'     => 'error',
                'last_error' => $message,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['id' => $id],
            ['%s', '%s', '%s'],
            ['%d'],
        );
    }

    /**
     * Persist runtime info from a successful /status pull (spec § 5.1).
     * JSON-encodes the structured fields and bumps last_sync_at + last_contact_at.
     *
     * P2.3 v4 migration: active_theme moved to wp_defyn_site_themes table;
     * still accepted in $info for backward compatibility but no longer persisted here.
     *
     * P2.4: Propagates core sub-object from connector /status payload + performs
     * day-1 single-row heal when incoming says "no update available" but row is
     * stuck in `failed` state.
     *
     * @param array{
     *   wp_version: string,
     *   php_version: string,
     *   active_theme?: array<string, mixed>,
     *   plugin_counts: array<string, int>,
     *   theme_counts: array<string, int>,
     *   ssl_status: string,
     *   ssl_expires_at: ?string,
     *   server_time?: int,
     *   core?: array<string, mixed>
     * } $info
     */
    public function markSynced(int $id, array $info): void
    {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');

        $updates = [
            // A successful /status pull proves the site is alive and the
            // dashboard ↔ connector trust still works, so clear any prior
            // error/offline state. Without this the SPA would show stale
            // `status=error` + `last_error` forever after a single bad
            // sync (e.g. transient 404/401 while WP.com Batcache warmed).
            'status'          => 'active',
            'last_error'      => '',
            'wp_version'      => $info['wp_version'],
            'php_version'     => $info['php_version'],
            'plugin_counts'   => (string) wp_json_encode($info['plugin_counts']),
            'theme_counts'    => (string) wp_json_encode($info['theme_counts']),
            'ssl_status'      => $info['ssl_status'],
            'ssl_expires_at'  => $info['ssl_expires_at'],
            'last_sync_at'    => $now,
            'last_contact_at' => $now,
            'updated_at'      => $now,
        ];

        // P2.4 — propagate the core sub-object from the connector /status payload.
        $coreInfo = $info['core'] ?? null;
        if (is_array($coreInfo)) {
            $updates['core_update_available'] = !empty($coreInfo['update_available']) ? 1 : 0;
            $updates['core_update_version']   = $coreInfo['update_version'] ?? null;

            // Day-1 single-row heal — if incoming says "no update available"
            // but the existing row is stuck in `failed`, reset to idle + clear
            // the stale error. Ships from day 1 (not retrofitted like P2.2.1).
            $existing = $this->findById($id);
            if (
                $existing !== null
                && $existing->coreUpdateState === 'failed'
                && empty($coreInfo['update_available'])
            ) {
                $updates['core_update_state']      = 'idle';
                $updates['last_core_update_error'] = null;
            }
        }

        $wpdb->update(SitesTable::tableName(), $updates, ['id' => $id]);
    }

    /**
     * P2.4 — operator pressed "Update WordPress core". Flip the row to queued
     * + clear any prior error. Called from SitesCoreUpdateController.
     */
    public function markCoreUpdateRequested(int $siteId, string $now): void
    {
        global $wpdb;
        $wpdb->update(
            SitesTable::tableName(),
            [
                'core_update_state'           => 'queued',
                'last_core_update_error'      => null,
                'last_core_update_attempt_at' => $now,
                'updated_at'                  => $now,
            ],
            ['id' => $siteId],
            ['%s', '%s', '%s', '%s'],
            ['%d'],
        );
    }

    /**
     * P2.4 — AS job started executing the upgrade. Called from UpdateSiteCore.
     */
    public function markCoreUpdating(int $siteId, string $now): void
    {
        global $wpdb;
        $wpdb->update(
            SitesTable::tableName(),
            [
                'core_update_state' => 'updating',
                'updated_at'        => $now,
            ],
            ['id' => $siteId],
            ['%s', '%s'],
            ['%d'],
        );
    }

    /**
     * P2.4 — upgrade succeeded. Bump wp_version + clear the update-available
     * badge.
     */
    public function markCoreUpdateSucceeded(int $siteId, string $newVersion, string $now): void
    {
        global $wpdb;
        $wpdb->update(
            SitesTable::tableName(),
            [
                'wp_version'             => $newVersion,
                'core_update_state'      => 'idle',
                'core_update_available'  => 0,
                'core_update_version'    => null,
                'last_core_update_error' => null,
                'updated_at'             => $now,
            ],
            ['id' => $siteId],
            ['%s', '%s', '%d', '%s', '%s', '%s'],
            ['%d'],
        );
    }

    /**
     * P2.4 — upgrade failed (terminal). Truncates the error to 1000 chars to
     * match the VARCHAR(1000) column.
     */
    public function markCoreUpdateFailed(int $siteId, string $errorMessage, string $now): void
    {
        global $wpdb;
        $wpdb->update(
            SitesTable::tableName(),
            [
                'core_update_state'           => 'failed',
                'last_core_update_error'      => substr($errorMessage, 0, 1000),
                'last_core_update_attempt_at' => $now,
                'updated_at'                  => $now,
            ],
            ['id' => $siteId],
            ['%s', '%s', '%s', '%s'],
            ['%d'],
        );
    }

    /**
     * Mark the site as offline after a failed health check (Task 14).
     * 'offline' is a new status value alongside F1's pending/active/error;
     * it fits the existing VARCHAR(20) so no schema bump is needed.
     */
    public function markOffline(int $id, string $message): void
    {
        global $wpdb;
        $wpdb->update(
            SitesTable::tableName(),
            [
                'status'     => 'offline',
                'last_error' => $message,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['id' => $id],
            ['%s', '%s', '%s'],
            ['%d'],
        );
    }

    /**
     * Happy-path heartbeat tick: bump last_contact_at + updated_at only.
     * Does NOT touch status — caller has already confirmed the site is healthy
     * and not transitioning out of 'offline' (use markRecovered for that).
     */
    public function markContactAt(int $id): void
    {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->update(
            SitesTable::tableName(),
            [
                'last_contact_at' => $now,
                'updated_at'      => $now,
            ],
            ['id' => $id],
            ['%s', '%s'],
            ['%d'],
        );
    }

    /**
     * Recovery transition: flips a previously 'offline' site back to 'active',
     * clears the stale last_error (important for SPA UX — no ghost error after
     * recovery), and bumps last_contact_at + updated_at.
     */
    public function markRecovered(int $id): void
    {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->update(
            SitesTable::tableName(),
            [
                'status'          => 'active',
                'last_error'      => '',
                'last_contact_at' => $now,
                'updated_at'      => $now,
            ],
            ['id' => $id],
            ['%s', '%s', '%s', '%s'],
            ['%d'],
        );
    }

    /**
     * P2.4.1 — set the core_allow_major flag for a site (allow/block major version updates).
     */
    public function setCoreAllowMajor(int $siteId, bool $allow): void
    {
        global $wpdb;
        $wpdb->update(
            SitesTable::tableName(),
            ['core_allow_major' => $allow ? 1 : 0],
            ['id' => $siteId],
            ['%d'],
            ['%d'],
        );
    }

    /**
     * P2.5 — count of pending plugin updates across all sites owned by $userId.
     */
    public function countPendingPlugins(int $userId): int
    {
        global $wpdb;
        $sitesTable   = SitesTable::tableName();
        $pluginsTable = $wpdb->prefix . 'defyn_site_plugins';

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$pluginsTable} sp
             INNER JOIN {$sitesTable} s ON s.id = sp.site_id
             WHERE s.user_id = %d
               AND sp.update_available = 1",
            $userId
        ));
    }

    /**
     * P2.5 — count of pending theme updates across all sites owned by $userId.
     */
    public function countPendingThemes(int $userId): int
    {
        global $wpdb;
        $sitesTable  = SitesTable::tableName();
        $themesTable = $wpdb->prefix . 'defyn_site_themes';

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$themesTable} st
             INNER JOIN {$sitesTable} s ON s.id = st.site_id
             WHERE s.user_id = %d
               AND st.update_available = 1",
            $userId
        ));
    }

    /**
     * P2.5 — count of pending MINOR core updates (major.minor segments match).
     * A bump is "minor" when wp_version major.minor === core_update_version major.minor.
     */
    public function countPendingCoresMinor(int $userId): int
    {
        global $wpdb;
        $sitesTable = SitesTable::tableName();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$sitesTable}
             WHERE user_id = %d
               AND core_update_available = 1
               AND core_update_version IS NOT NULL
               AND SUBSTRING_INDEX(wp_version, '.', 2) = SUBSTRING_INDEX(core_update_version, '.', 2)",
            $userId
        ));
    }

    /**
     * P2.5 — count of pending MAJOR core updates (major or minor segments differ).
     */
    public function countPendingCoresMajor(int $userId): int
    {
        global $wpdb;
        $sitesTable = SitesTable::tableName();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$sitesTable}
             WHERE user_id = %d
               AND core_update_available = 1
               AND core_update_version IS NOT NULL
               AND SUBSTRING_INDEX(wp_version, '.', 2) != SUBSTRING_INDEX(core_update_version, '.', 2)",
            $userId
        ));
    }

    /**
     * P2.5 — count of sites owned by $userId that have ANY pending update
     * (plugin OR theme OR core). Uses UNION to deduplicate sites that
     * have multiple kinds of pending updates.
     */
    public function countSitesWithAnyUpdate(int $userId): int
    {
        global $wpdb;
        $sitesTable   = SitesTable::tableName();
        $pluginsTable = $wpdb->prefix . 'defyn_site_plugins';
        $themesTable  = $wpdb->prefix . 'defyn_site_themes';

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT site_id) FROM (
                SELECT sp.site_id FROM {$pluginsTable} sp
                  INNER JOIN {$sitesTable} s ON s.id = sp.site_id
                  WHERE s.user_id = %d AND sp.update_available = 1
                UNION
                SELECT st.site_id FROM {$themesTable} st
                  INNER JOIN {$sitesTable} s ON s.id = st.site_id
                  WHERE s.user_id = %d AND st.update_available = 1
                UNION
                SELECT id FROM {$sitesTable}
                  WHERE user_id = %d AND core_update_available = 1
             ) AS combined",
            $userId, $userId, $userId
        ));
    }
}
