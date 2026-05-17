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
}
