<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * F6 — SitesRepository::markSynced + markOffline.
 *
 * markSynced persists runtime info from a successful /status pull
 * (wp_version, php_version, active_theme JSON, plugin_counts JSON,
 * theme_counts JSON, ssl_status, ssl_expires_at) and bumps last_sync_at
 * + last_contact_at.
 *
 * markOffline flips status to 'offline' and records last_error — called
 * by HealthService when a heartbeat fails. 'offline' is a new status value
 * beyond F1's pending/active/error and fits the existing VARCHAR(20).
 *
 * @group integration
 */
final class SitesRepositoryF6Test extends AbstractSchemaTestCase
{
    private SitesRepository $repo;

    public function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_sites');
        global $wpdb;
        $wpdb->query('TRUNCATE ' . SitesTable::tableName());
        $this->repo = new SitesRepository();
    }

    public function testMarkSyncedPersistsRuntimeInfo(): void
    {
        $id = $this->repo->insertPending(
            userId: 1,
            url: 'https://a.test',
            label: 'A',
            ourPublicKey: base64_encode(random_bytes(32)),
            ourPrivateKeyEncrypted: 'cipher',
        );
        $this->repo->markActive($id, base64_encode(random_bytes(32)));

        $info = [
            'wp_version'     => '6.7.1',
            'php_version'    => '8.2.18',
            'active_theme'   => ['name' => 'Twenty Twenty-Four', 'version' => '1.2', 'parent' => null],
            'plugin_counts'  => ['installed' => 12, 'active' => 8],
            'theme_counts'   => ['installed' => 3, 'active' => 1],
            'ssl_status'     => 'enabled',
            'ssl_expires_at' => null,
            'server_time'    => time(),
        ];

        $this->repo->markSynced($id, $info);

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . SitesTable::tableName() . ' WHERE id = %d', $id),
            ARRAY_A,
        );

        self::assertSame('6.7.1', $row['wp_version']);
        self::assertSame('8.2.18', $row['php_version']);
        self::assertSame('enabled', $row['ssl_status']);
        self::assertNotEmpty($row['last_sync_at']);
        self::assertNotEmpty($row['last_contact_at']);

        $activeTheme = json_decode((string) $row['active_theme'], true);
        self::assertSame('Twenty Twenty-Four', $activeTheme['name']);

        $pluginCounts = json_decode((string) $row['plugin_counts'], true);
        self::assertSame(12, $pluginCounts['installed']);
        self::assertSame(8, $pluginCounts['active']);

        $themeCounts = json_decode((string) $row['theme_counts'], true);
        self::assertSame(3, $themeCounts['installed']);
    }

    public function testMarkSyncedAcceptsSslExpiresAt(): void
    {
        $id = $this->repo->insertPending(1, 'https://b.test', 'B', 'pub', 'cipher');

        $this->repo->markSynced($id, [
            'wp_version'     => '6.7',
            'php_version'    => '8.2',
            'active_theme'   => [],
            'plugin_counts'  => [],
            'theme_counts'   => [],
            'ssl_status'     => 'enabled',
            'ssl_expires_at' => '2027-01-01 00:00:00',
        ]);

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT ssl_expires_at FROM ' . SitesTable::tableName() . ' WHERE id = %d', $id),
            ARRAY_A,
        );
        self::assertSame('2027-01-01 00:00:00', $row['ssl_expires_at']);
    }

    /**
     * Recovery contract: a successful sync after an error/offline state must
     * clear `status` back to `active` and wipe `last_error`. Without this
     * the SPA would display a stale error indefinitely even though syncs
     * are succeeding. Found live on smartcoding.com.au (2026-06-04): site
     * stuck at `status=error, last_error="Connector returned status 401"`
     * after five consecutive successful syncs because WP.com Batcache had
     * served a stale 401 once.
     */
    public function testMarkSyncedClearsErrorStateOnRecovery(): void
    {
        $id = $this->repo->insertPending(
            userId: 1,
            url: 'https://recover.test',
            label: 'Recover',
            ourPublicKey: base64_encode(random_bytes(32)),
            ourPrivateKeyEncrypted: 'cipher',
        );
        $this->repo->markActive($id, base64_encode(random_bytes(32)));
        $this->repo->markError($id, 'Connector returned status 401');

        // Sanity: error state landed.
        $errored = $this->repo->findById($id);
        self::assertNotNull($errored);
        self::assertSame('error', $errored->status);
        self::assertSame('Connector returned status 401', $errored->lastError);

        // Successful sync arrives.
        $this->repo->markSynced($id, [
            'wp_version'     => '7.0',
            'php_version'    => '8.3.31',
            'active_theme'   => ['name' => 'Smart Coding', 'version' => '1.0.29', 'parent' => null],
            'plugin_counts'  => ['installed' => 21, 'active' => 20],
            'theme_counts'   => ['installed' => 8, 'active' => 1],
            'ssl_status'     => 'enabled',
            'ssl_expires_at' => null,
        ]);

        $recovered = $this->repo->findById($id);
        self::assertNotNull($recovered);
        self::assertSame('active', $recovered->status, 'sync should clear error status');
        self::assertSame('', $recovered->lastError ?? '', 'sync should clear last_error');
        self::assertSame('7.0', $recovered->wpVersion);
    }

    public function testMarkOfflineFlipsStatusAndRecordsError(): void
    {
        $id = $this->repo->insertPending(
            userId: 1,
            url: 'https://a.test',
            label: 'A',
            ourPublicKey: base64_encode(random_bytes(32)),
            ourPrivateKeyEncrypted: 'cipher',
        );
        $this->repo->markActive($id, base64_encode(random_bytes(32)));

        $this->repo->markOffline($id, 'connection refused');

        $site = $this->repo->findById($id);
        self::assertNotNull($site);
        self::assertSame('offline', $site->status);
        self::assertSame('connection refused', $site->lastError);
    }
}
