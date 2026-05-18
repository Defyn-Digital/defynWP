<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\HealthService;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * F6 — HealthService.
 *
 * Lightweight liveness probe against the connector's /heartbeat. Success
 * advances last_contact_at; if the site was previously 'offline' it flips
 * back to 'active' (recovery). Any failure (transport or non-2xx) flips
 * status to 'offline' and records the message.
 *
 * @group integration
 */
final class HealthServiceTest extends AbstractSchemaTestCase
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

    public function testSuccessfulPingAdvancesLastContact(): void
    {
        $siteId = $this->makeActiveSite();
        $before = (new SitesRepository())->findById($siteId)->lastContactAt;

        // gmdate('Y-m-d H:i:s') has 1-second resolution; without the pause the
        // bumped timestamp may equal the original and produce a false negative.
        sleep(1);

        $mock = new MockHttpClient(fn () => new MockResponse(
            json_encode(['ok' => true, 'server_time' => time()]),
            ['http_code' => 200],
        ));

        (new HealthService(new SignedHttpClient($mock)))->ping($siteId);

        $site = (new SitesRepository())->findById($siteId);
        self::assertNotNull($site);
        self::assertSame('active', $site->status);
        self::assertNotSame($before, $site->lastContactAt);
    }

    public function testTransportFailureFlipsToOffline(): void
    {
        $siteId = $this->makeActiveSite();

        $mock = new MockHttpClient(function ($method, $url, $options) {
            throw new TransportException('host unreachable');
        });

        (new HealthService(new SignedHttpClient($mock)))->ping($siteId);

        $site = (new SitesRepository())->findById($siteId);
        self::assertNotNull($site);
        self::assertSame('offline', $site->status);
        self::assertStringContainsString('host unreachable', (string) $site->lastError);
    }

    public function testRecoveryFlipsOfflineBackToActive(): void
    {
        $siteId = $this->makeActiveSite();
        (new SitesRepository())->markOffline($siteId, 'previously offline');

        $mock = new MockHttpClient(fn () => new MockResponse(
            json_encode(['ok' => true, 'server_time' => time()]),
            ['http_code' => 200],
        ));

        (new HealthService(new SignedHttpClient($mock)))->ping($siteId);

        $site = (new SitesRepository())->findById($siteId);
        self::assertNotNull($site);
        self::assertSame('active', $site->status);
        self::assertSame('', (string) $site->lastError);
    }
}
