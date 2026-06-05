<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Jobs;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Jobs\SyncSite;
use Defyn\Dashboard\Schema\ActivityLogTable;
use Defyn\Dashboard\Schema\SitePluginsTable;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Services\SyncService;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @group integration
 */
final class SyncSitePluginsIntegrationTest extends AbstractSchemaTestCase
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

    /** Active site with real Vault-encrypted dashboard key. */
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

    /**
     * Build a MockHttpClient that responds differently per route.
     *
     * @param array<string, array{status:int,body?:string}> $byPath  canonical-path → response
     */
    private function makeMock(array $byPath): MockHttpClient
    {
        return new MockHttpClient(function ($method, $url, $options) use ($byPath) {
            foreach ($byPath as $path => $cfg) {
                if (str_contains($url, $path)) {
                    return new MockResponse(
                        $cfg['body'] ?? '',
                        [
                            'http_code'        => $cfg['status'],
                            'response_headers' => ['content-type: application/json'],
                        ],
                    );
                }
            }
            // Unmatched URL — return 404
            return new MockResponse('', ['http_code' => 404]);
        });
    }

    public function testStatusSyncAlsoPullsPluginsList(): void
    {
        $siteId = $this->makeActiveSite();

        $statusBody = (string) json_encode([
            'wp_version'    => '6.5.0',
            'php_version'   => '8.2.0',
            'active_theme'  => ['name' => 'T', 'version' => '1', 'parent' => null],
            'plugin_counts' => ['installed' => 1, 'active' => 1],
            'theme_counts'  => ['installed' => 1, 'active' => 1],
            'ssl_status'    => 'enabled',
            'ssl_expires_at'=> null,
            'server_time'   => time(),
        ]);

        $pluginsBody = (string) json_encode([
            'plugins' => [
                ['slug' => 'a.php', 'name' => 'A', 'version' => '1', 'update_available' => false, 'update_version' => null],
            ],
            'truncated'   => false,
            'server_time' => time(),
        ]);

        $mock = $this->makeMock([
            '/defyn-connector/v1/status'  => ['status' => 200, 'body' => $statusBody],
            '/defyn-connector/v1/plugins' => ['status' => 200, 'body' => $pluginsBody],
        ]);

        $httpClient = new SignedHttpClient($mock);
        (new SyncSite(
            statusService: new SyncService($httpClient),
            httpClient: $httpClient,
        ))->handle($siteId);

        global $wpdb;
        $rowCount = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . SitePluginsTable::tableName() . ' WHERE site_id = %d',
                $siteId
            )
        );
        self::assertSame(1, $rowCount, 'one plugin row should be persisted');

        $syncedCount = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM " . ActivityLogTable::tableName() .
            " WHERE event_type = 'plugin_inventory.synced'"
        );
        self::assertSame(1, $syncedCount);
    }

    public function testPluginsFailureDoesNotMarkSiteAsError(): void
    {
        $siteId = $this->makeActiveSite();

        $statusBody = (string) json_encode([
            'wp_version'    => '6.5.0',
            'php_version'   => '8.2',
            'active_theme'  => ['name' => 'T', 'version' => '1', 'parent' => null],
            'plugin_counts' => ['installed' => 0, 'active' => 0],
            'theme_counts'  => ['installed' => 1, 'active' => 1],
            'ssl_status'    => 'enabled',
            'ssl_expires_at'=> null,
            'server_time'   => time(),
        ]);

        $mock = $this->makeMock([
            '/defyn-connector/v1/status'  => ['status' => 200, 'body' => $statusBody],
            // /plugins responds 404 → simulates connector predating v0.1.3
            '/defyn-connector/v1/plugins' => ['status' => 404, 'body' => (string) json_encode(['error' => ['code' => 'rest_no_route', 'message' => 'No route']])],
        ]);

        $httpClient = new SignedHttpClient($mock);
        (new SyncSite(
            statusService: new SyncService($httpClient),
            httpClient: $httpClient,
        ))->handle($siteId);

        $site = (new SitesRepository())->findById($siteId);
        self::assertNotNull($site);
        self::assertSame('active', $site->status, '/plugins failure should not mark site as error');

        global $wpdb;
        $failed = $wpdb->get_row(
            "SELECT details FROM " . ActivityLogTable::tableName() .
            " WHERE event_type = 'plugin_inventory.sync_failed' ORDER BY id DESC LIMIT 1",
            ARRAY_A,
        );
        self::assertNotNull($failed);
        $details = json_decode((string) $failed['details'], true);
        self::assertSame('connector_below_v0.1.3', $details['error']);
        self::assertSame('background', $details['source']);
    }
}
