<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Jobs;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Jobs\RefreshSiteCore;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RefreshSiteCoreTest extends AbstractSchemaTestCase
{
    private int $siteId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_sites');
        $this->freshlyActivate('defyn_activity_log');

        $keypair = sodium_crypto_sign_keypair();
        $privateKey = base64_encode(sodium_crypto_sign_secretkey($keypair));
        $vault = new Vault(DEFYN_VAULT_KEY);
        $encrypted = $vault->encrypt($privateKey);

        global $wpdb;
        $wpdb->insert(SitesTable::tableName(), [
            'user_id'         => 1,
            'url'             => 'https://smartcoding.test',
            'label'           => 'Smart',
            'status'          => 'active',
            'our_private_key' => $encrypted,
            'site_public_key' => base64_encode(sodium_crypto_sign_publickey($keypair)),
            'wp_version'      => '7.0',
            'php_version'     => '8.3.31',
            'plugin_counts'   => '{"installed":0,"active":0}',
            'theme_counts'    => '{"installed":0,"active":0}',
            'ssl_status'      => 'enabled',
            'ssl_expires_at'  => null,
            'last_sync_at'    => '2026-06-07 04:00:00',
            'last_contact_at' => '2026-06-07 04:00:00',
            'created_at'      => '2026-06-06 00:00:00',
            'updated_at'      => '2026-06-07 04:00:00',
        ]);
        $this->siteId = (int) $wpdb->insert_id;
    }

    public function testSuccessPathWritesCoreColumnsAndLogsEvent(): void
    {
        $body = json_encode([
            'update_available'       => true,
            'update_version'         => '7.0.1',
            'is_minor_update'        => true,
            'is_auto_update_enabled' => false,
            'server_time'            => time(),
        ]);

        $captured = null;
        $factory = function (string $method, string $url, array $options) use (&$captured, $body) {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];
            return new MockResponse($body, ['http_code' => 200]);
        };

        $job = new RefreshSiteCore(
            new SitesRepository(),
            new SignedHttpClient(new MockHttpClient($factory)),
            new ActivityLogger(),
        );

        $job->handle($this->siteId);

        $this->assertSame('POST', $captured['method']);
        $this->assertStringEndsWith('/wp-json/defyn-connector/v1/core/refresh', $captured['url']);

        $row = (new SitesRepository())->findById($this->siteId);
        $this->assertTrue($row->coreUpdateAvailable);
        $this->assertSame('7.0.1', $row->coreUpdateVersion);

        global $wpdb;
        $event = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT event_type, details FROM {$wpdb->prefix}defyn_activity_log
                 WHERE site_id = %d AND event_type = 'core_inventory.refreshed'
                 ORDER BY id DESC LIMIT 1",
                $this->siteId
            ),
            ARRAY_A,
        );
        $this->assertNotNull($event);
        $details = json_decode((string) $event['details'], true);
        $this->assertSame(true, $details['update_available']);
        $this->assertSame('refresh', $details['source']);
    }

    public function testTransportFailureLogsRefreshFailed(): void
    {
        $factory = fn () => throw new \Symfony\Component\HttpClient\Exception\TransportException('Connection refused');

        $job = new RefreshSiteCore(
            new SitesRepository(),
            new SignedHttpClient(new MockHttpClient($factory)),
            new ActivityLogger(),
        );

        $job->handle($this->siteId);

        global $wpdb;
        $event = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT details FROM {$wpdb->prefix}defyn_activity_log
                 WHERE site_id = %d AND event_type = 'site.core_refresh_failed'
                 ORDER BY id DESC LIMIT 1",
                $this->siteId
            ),
            ARRAY_A,
        );
        $this->assertNotNull($event);
        $details = json_decode((string) $event['details'], true);
        $this->assertStringContainsString('Connection refused', (string) $details['error']);

        $row = (new SitesRepository())->findById($this->siteId);
        $this->assertFalse($row->coreUpdateAvailable);
        $this->assertNull($row->coreUpdateVersion);
    }
}
