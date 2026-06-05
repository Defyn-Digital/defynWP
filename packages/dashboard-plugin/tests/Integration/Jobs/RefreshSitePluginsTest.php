<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Jobs;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Jobs\RefreshSitePlugins;
use Defyn\Dashboard\Schema\ActivityLogTable;
use Defyn\Dashboard\Schema\SitePluginsTable;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @group integration
 */
final class RefreshSitePluginsTest extends AbstractSchemaTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_sites');
        $this->freshlyActivate('defyn_site_plugins');
        $this->freshlyActivate('defyn_activity_log');
        global $wpdb;
        $wpdb->query('TRUNCATE ' . SitesTable::tableName());
        $wpdb->query('TRUNCATE ' . SitePluginsTable::tableName());
        $wpdb->query('TRUNCATE ' . ActivityLogTable::tableName());
    }

    /** @return int site_id */
    private function makeActiveSite(): int
    {
        $repo  = new SitesRepository();
        $vault = new Vault(DEFYN_VAULT_KEY);
        $priv  = base64_encode(random_bytes(64));
        $id    = $repo->insertPending(
            userId: 1,
            url: 'https://demo.test',
            label: 'Demo',
            ourPublicKey: base64_encode(random_bytes(32)),
            ourPrivateKeyEncrypted: $vault->encrypt($priv),
        );
        $repo->markActive($id, base64_encode(random_bytes(32)));
        return $id;
    }

    public function testJobCallsConnectorAndPersistsPayload(): void
    {
        $siteId = $this->makeActiveSite();

        $mock = new MockHttpClient(function ($method, $url, $options) {
            return new MockResponse(
                (string) json_encode([
                    'plugins' => [
                        ['slug' => 'a.php', 'name' => 'A', 'version' => '1.0', 'update_available' => true, 'update_version' => '1.1'],
                    ],
                    'truncated'   => false,
                    'server_time' => time(),
                ]),
                [
                    'http_code'        => 200,
                    'response_headers' => ['content-type: application/json'],
                ],
            );
        });

        (new RefreshSitePlugins(httpClient: new SignedHttpClient($mock)))->handle($siteId);

        global $wpdb;
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . SitePluginsTable::tableName() . ' WHERE site_id = %d',
                $siteId
            )
        );
        self::assertSame(1, $count);

        $synced = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM " . ActivityLogTable::tableName() .
            " WHERE event_type = 'plugin_inventory.synced'"
        );
        self::assertSame(1, $synced);
    }

    public function testJobLogsSyncFailedOnTransportError(): void
    {
        $siteId = $this->makeActiveSite();

        // Simulate a transport failure (timeout, DNS, TLS, etc.) by throwing
        // TransportException from the callable. SignedHttpClient's sendSigned()
        // catches Throwable and returns ['status' => 0, 'body' => [], 'error' => msg],
        // which RefreshSitePlugins routes into the plugin_inventory.sync_failed log.
        // Mirrors SyncServiceTest::testTransportErrorMarksSiteErrorAndRecordsMessage.
        $mock = new MockHttpClient(function ($method, $url, $options) {
            throw new TransportException('connection refused');
        });

        (new RefreshSitePlugins(httpClient: new SignedHttpClient($mock)))->handle($siteId);

        global $wpdb;
        $failed = $wpdb->get_row(
            "SELECT details FROM " . ActivityLogTable::tableName() .
            " WHERE event_type = 'plugin_inventory.sync_failed' ORDER BY id DESC LIMIT 1",
            ARRAY_A,
        );
        self::assertNotNull($failed);
        $details = json_decode((string) $failed['details'], true);
        self::assertSame('refresh', $details['source']);
    }

    public function testJobLogsSyncFailedOnHttpError(): void
    {
        $siteId = $this->makeActiveSite();

        $mock = new MockHttpClient(function ($method, $url, $options) {
            return new MockResponse(
                (string) json_encode(['error' => ['code' => 'connector.refresh_failed', 'message' => 'wp_update_plugins() failed']]),
                ['http_code' => 502, 'response_headers' => ['content-type: application/json']],
            );
        });

        (new RefreshSitePlugins(httpClient: new SignedHttpClient($mock)))->handle($siteId);

        global $wpdb;
        $failed = $wpdb->get_row(
            "SELECT details FROM " . ActivityLogTable::tableName() .
            " WHERE event_type = 'plugin_inventory.sync_failed' ORDER BY id DESC LIMIT 1",
            ARRAY_A,
        );
        self::assertNotNull($failed);
        $details = json_decode((string) $failed['details'], true);
        self::assertSame('refresh', $details['source']);
        self::assertStringContainsString('502', $details['error']);
    }
}
