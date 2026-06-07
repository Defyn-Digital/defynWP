<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Services\SyncService;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * P2.4 — SyncService propagates core sub-object from /status payload.
 *
 * Verifies:
 *   1. SyncService::sync() passes the core sub-object to SitesRepository::markSynced
 *   2. markSynced persists core_update_available + core_update_version from core.update_available + core.update_version
 *   3. markSynced day-1 heal logic: if incoming says "no update available" but row is stuck in "failed",
 *      reset to "idle" + clear stale error
 *
 * @group integration
 */
final class SyncServiceCoreTest extends AbstractSchemaTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_sites');
        global $wpdb;
        $wpdb->query('TRUNCATE ' . SitesTable::tableName());
    }

    /** Creates an active site with a real Vault-encrypted dashboard private key. */
    private function makeSite(): int
    {
        $repo  = new SitesRepository();
        $vault = new Vault(DEFYN_VAULT_KEY);
        $priv  = base64_encode(random_bytes(64));
        $id    = $repo->insertPending(
            userId: 1,
            url: 'https://site.test',
            label: 'Site',
            ourPublicKey: base64_encode(random_bytes(32)),
            ourPrivateKeyEncrypted: $vault->encrypt($priv),
        );
        $repo->markActive($id, base64_encode(random_bytes(32)));
        return $id;
    }

    public function testSyncWritesCoreFieldsOnSuccess(): void
    {
        $siteId = $this->makeSite();

        $mock = new MockHttpClient(function ($method, $url, $options) {
            $payload = [
                'wp_version'     => '7.0',
                'php_version'    => '8.3.31',
                'plugin_counts'  => ['installed' => 21, 'active' => 20],
                'theme_counts'   => ['installed' => 8, 'active' => 1],
                'ssl_status'     => 'enabled',
                'ssl_expires_at' => null,
                'core'           => [
                    'update_available'       => true,
                    'update_version'         => '7.0.1',
                    'is_minor_update'        => true,
                    'is_auto_update_enabled' => false,
                ],
            ];
            return new MockResponse(json_encode($payload), ['http_code' => 200]);
        });

        (new SyncService(new SignedHttpClient($mock)))->sync($siteId);

        $row = (new SitesRepository())->findById($siteId);
        $this->assertNotNull($row);
        $this->assertTrue($row->coreUpdateAvailable);
        $this->assertSame('7.0.1', $row->coreUpdateVersion);
    }

    public function testSyncHealsStuckFailedThroughTheService(): void
    {
        $siteId = $this->makeSite();

        $repo = new SitesRepository();
        $repo->markCoreUpdateFailed($siteId, 'Old error', '2026-06-07 08:00:00');

        $mock = new MockHttpClient(function ($method, $url, $options) {
            $payload = [
                'wp_version'     => '7.0.1',
                'php_version'    => '8.3.31',
                'plugin_counts'  => ['installed' => 0, 'active' => 0],
                'theme_counts'   => ['installed' => 0, 'active' => 0],
                'ssl_status'     => 'enabled',
                'ssl_expires_at' => null,
                'core'           => [
                    'update_available'       => false,
                    'update_version'         => null,
                    'is_minor_update'        => false,
                    'is_auto_update_enabled' => true,
                ],
            ];
            return new MockResponse(json_encode($payload), ['http_code' => 200]);
        });

        (new SyncService(new SignedHttpClient($mock)))->sync($siteId);

        $row = $repo->findById($siteId);
        $this->assertSame('idle', $row->coreUpdateState);
        $this->assertNull($row->lastCoreUpdateError);
        $this->assertFalse($row->coreUpdateAvailable);
        $this->assertSame('7.0.1', $row->wpVersion);
    }
}
