<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Services\SyncService;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * F6 — SyncService.
 *
 * Orchestrates a signed /status pull: decrypts the per-site private key
 * via Vault, issues a signed GET against the connector, and persists the
 * payload via SitesRepository::markSynced. Every failure mode (decrypt
 * failure, transport error, non-2xx response, malformed payload) flows
 * through markError so the dashboard always reflects the latest state.
 *
 * @group integration
 */
final class SyncServiceTest extends AbstractSchemaTestCase
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

    public function testSuccessfulSyncPersistsRuntimeInfo(): void
    {
        $siteId = $this->makeSite();

        $mock = new MockHttpClient(function ($method, $url, $options) {
            $payload = [
                'wp_version'     => '6.7.1',
                'php_version'    => '8.2.18',
                'active_theme'   => ['name' => 'Theme', 'version' => '1.0', 'parent' => null],
                'plugin_counts'  => ['installed' => 10, 'active' => 5],
                'theme_counts'   => ['installed' => 2, 'active' => 1],
                'ssl_status'     => 'enabled',
                'ssl_expires_at' => null,
                'server_time'    => time(),
            ];
            return new MockResponse(json_encode($payload), ['http_code' => 200]);
        });

        (new SyncService(new SignedHttpClient($mock)))->sync($siteId);

        $site = (new SitesRepository())->findById($siteId);
        self::assertNotNull($site);
        self::assertSame('6.7.1', $site->wpVersion);
        self::assertSame('active', $site->status);
        self::assertSame('enabled', $site->sslStatus);
    }

    public function testTransportErrorMarksSiteErrorAndRecordsMessage(): void
    {
        $siteId = $this->makeSite();

        $mock = new MockHttpClient(function ($method, $url, $options) {
            throw new TransportException('connection refused');
        });

        (new SyncService(new SignedHttpClient($mock)))->sync($siteId);

        $site = (new SitesRepository())->findById($siteId);
        self::assertNotNull($site);
        self::assertSame('error', $site->status);
        self::assertStringContainsString('connection refused', (string) $site->lastError);
    }

    public function testNon2xxResponseMarksSiteError(): void
    {
        $siteId = $this->makeSite();

        $mock = new MockHttpClient(fn () => new MockResponse(
            json_encode(['error' => ['code' => 'connector.signature_invalid', 'message' => 'bad sig']]),
            ['http_code' => 401],
        ));

        (new SyncService(new SignedHttpClient($mock)))->sync($siteId);

        $site = (new SitesRepository())->findById($siteId);
        self::assertNotNull($site);
        self::assertSame('error', $site->status);
    }
}
