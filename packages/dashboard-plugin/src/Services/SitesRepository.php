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
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'],
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
}
