<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\DisconnectService;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * F8 — DisconnectService.
 *
 * Soft disconnect: sign POST /disconnect to the connector AND delete the
 * dashboard row. Connector-call failure (transport error, 4xx/5xx, decrypt
 * failure) must NOT block the row deletion — the operator must never be
 * stranded with an unrecoverable site when the managed WP is offline or its
 * connector is broken. Non-owners get a no-op (no connector call, no delete).
 *
 * @group integration
 */
final class DisconnectServiceTest extends AbstractSchemaTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_sites');
        global $wpdb;
        $wpdb->query('TRUNCATE ' . SitesTable::tableName());
    }

    /** Creates an active site with a real Vault-encrypted dashboard private key. */
    private function makeActiveSite(): int
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

    public function testSuccessfulConnectorCallDeletesRow(): void
    {
        $siteId = $this->makeActiveSite();

        $mock = new MockHttpClient(fn () => new MockResponse('', ['http_code' => 204]));

        $result = (new DisconnectService(new SignedHttpClient($mock)))->disconnect($siteId, 1);

        self::assertTrue($result);
        self::assertNull((new SitesRepository())->findById($siteId));
    }

    public function testConnectorTransportFailureStillDeletesRow(): void
    {
        $siteId = $this->makeActiveSite();

        $mock = new MockHttpClient(function ($method, $url, $options) {
            throw new TransportException('host unreachable');
        });

        $result = (new DisconnectService(new SignedHttpClient($mock)))->disconnect($siteId, 1);

        // Soft-disconnect design: row deleted regardless of connector outcome.
        self::assertTrue($result);
        self::assertNull((new SitesRepository())->findById($siteId));
    }

    public function testNonOwnerCannotDisconnect(): void
    {
        $siteId = $this->makeActiveSite();

        $mock = new MockHttpClient(fn () => new MockResponse('', ['http_code' => 204]));

        $result = (new DisconnectService(new SignedHttpClient($mock)))->disconnect($siteId, 999);

        self::assertFalse($result);
        self::assertNotNull((new SitesRepository())->findById($siteId));
    }
}
