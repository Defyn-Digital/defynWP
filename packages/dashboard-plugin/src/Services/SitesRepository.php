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
        $wpdb->insert(
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
     * @param array{
     *   wp_version: string,
     *   php_version: string,
     *   active_theme: array<string, mixed>,
     *   plugin_counts: array<string, int>,
     *   theme_counts: array<string, int>,
     *   ssl_status: string,
     *   ssl_expires_at: ?string,
     *   server_time?: int
     * } $info
     */
    public function markSynced(int $id, array $info): void
    {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->update(
            SitesTable::tableName(),
            [
                // A successful /status pull proves the site is alive and the
                // dashboard ↔ connector trust still works, so clear any prior
                // error/offline state. Without this the SPA would show stale
                // `status=error` + `last_error` forever after a single bad
                // sync (e.g. transient 404/401 while WP.com Batcache warmed).
                'status'          => 'active',
                'last_error'      => '',
                'wp_version'      => $info['wp_version'],
                'php_version'     => $info['php_version'],
                'active_theme'    => (string) wp_json_encode($info['active_theme']),
                'plugin_counts'   => (string) wp_json_encode($info['plugin_counts']),
                'theme_counts'    => (string) wp_json_encode($info['theme_counts']),
                'ssl_status'      => $info['ssl_status'],
                'ssl_expires_at'  => $info['ssl_expires_at'],
                'last_sync_at'    => $now,
                'last_contact_at' => $now,
                'updated_at'      => $now,
            ],
            ['id' => $id],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'],
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
}
