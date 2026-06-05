<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Jobs;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Jobs\UpdateSitePlugin;
use Defyn\Dashboard\Schema\ActivityLogTable;
use Defyn\Dashboard\Schema\SitePluginsTable;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\SitePluginsRepository;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @group integration
 */
final class UpdateSitePluginTest extends AbstractSchemaTestCase
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
        // Use a real Ed25519 secret (64 bytes) — SignedHttpClient::signedPostJson
        // will base64-decode it and call sodium_crypto_sign_detached.
        $keypair = sodium_crypto_sign_keypair();
        $priv    = base64_encode(sodium_crypto_sign_secretkey($keypair));
        $id      = $repo->insertPending(
            userId: 1,
            url: 'https://smartcoding.test',
            label: 'Smart',
            ourPublicKey: base64_encode(sodium_crypto_sign_publickey($keypair)),
            ourPrivateKeyEncrypted: $vault->encrypt($priv),
        );
        $repo->markActive($id, base64_encode(random_bytes(32)));
        return $id;
    }

    private function seedAkismetRow(int $siteId): void
    {
        global $wpdb;
        $wpdb->insert(SitePluginsTable::tableName(), [
            'site_id'          => $siteId,
            'slug'             => 'akismet',
            'name'             => 'Akismet',
            'version'          => '5.7',
            'update_available' => 1,
            'update_version'   => '5.8',
            'update_state'     => 'queued',
            'last_seen_at'     => '2026-06-06 00:00:00',
            'created_at'       => '2026-06-06 00:00:00',
            'updated_at'       => '2026-06-06 00:00:00',
        ]);
    }

    public function testSuccessPathMarksIdleAndBumpsVersion(): void
    {
        $siteId = $this->makeActiveSite();
        $this->seedAkismetRow($siteId);

        $successBody = (string) json_encode([
            'success'          => true,
            'slug'             => 'akismet',
            'previous_version' => '5.7',
            'new_version'      => '5.8',
            'server_time'      => time(),
        ]);

        $captured = null;
        $mock = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured, $successBody) {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];
            return new MockResponse(
                $successBody,
                [
                    'http_code'        => 200,
                    'response_headers' => ['content-type: application/json'],
                ],
            );
        });

        $job = new UpdateSitePlugin(
            new SitesRepository(),
            new SitePluginsRepository(),
            new SignedHttpClient($mock),
            new ActivityLogger(),
            new Vault(DEFYN_VAULT_KEY),
        );

        $job->handle($siteId, 'akismet', 0);

        // Connector was called with 120s timeout. Use assertEquals because
        // Symfony may cast the timeout option internally.
        self::assertNotNull($captured);
        self::assertSame('POST', $captured['method']);
        self::assertEquals(120, $captured['options']['timeout']);
        self::assertStringEndsWith('/wp-json/defyn-connector/v1/plugins/akismet/update', $captured['url']);

        // Row was marked idle + bumped to 5.8
        $repo = new SitePluginsRepository();
        $row  = $repo->findRowForSiteAndSlug($siteId, 'akismet');
        self::assertNotNull($row);
        self::assertSame('idle', $row['update_state']);
        self::assertSame('5.8', $row['version']);
        self::assertSame('0', $row['update_available']);
        self::assertNull($row['update_version']);

        // plugin_update.started + plugin_update.succeeded in activity log
        global $wpdb;
        $rows = $wpdb->get_results(
            'SELECT event_type FROM ' . ActivityLogTable::tableName() .
            " WHERE site_id = {$siteId} ORDER BY id ASC",
            ARRAY_A,
        );
        $types = array_column($rows, 'event_type');
        self::assertSame(['plugin_update.started', 'plugin_update.succeeded'], $types);
    }
}
