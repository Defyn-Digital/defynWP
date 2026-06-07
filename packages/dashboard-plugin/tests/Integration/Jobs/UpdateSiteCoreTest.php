<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Jobs;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Jobs\UpdateSiteCore;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @group integration
 */
final class UpdateSiteCoreTest extends AbstractSchemaTestCase
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
            'user_id'                 => 1,
            'url'                     => 'https://smartcoding.test',
            'label'                   => 'Smart',
            'status'                  => 'active',
            'our_private_key'         => $encrypted,
            'site_public_key'         => base64_encode(sodium_crypto_sign_publickey($keypair)),
            'wp_version'              => '7.0',
            'php_version'             => '8.3.31',
            'plugin_counts'           => '{"installed":0,"active":0}',
            'theme_counts'            => '{"installed":0,"active":0}',
            'ssl_status'              => 'enabled',
            'ssl_expires_at'          => null,
            'last_sync_at'            => '2026-06-07 04:00:00',
            'last_contact_at'         => '2026-06-07 04:00:00',
            'created_at'              => '2026-06-06 00:00:00',
            'updated_at'              => '2026-06-07 04:00:00',
            'core_update_available'   => 1,
            'core_update_version'     => '7.0.1',
            'core_update_state'       => 'queued',
            'last_core_update_error'  => null,
        ]);
        $this->siteId = (int) $wpdb->insert_id;
    }

    public function testSuccessPathMarksIdleAndBumpsVersion(): void
    {
        $body = json_encode([
            'success'          => true,
            'previous_version' => '7.0',
            'new_version'      => '7.0.1',
            'server_time'      => time(),
        ]);

        $captured = null;
        $factory = function (string $method, string $url, array $options) use (&$captured, $body) {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];
            return new MockResponse($body, ['http_code' => 200]);
        };

        $job = new UpdateSiteCore(
            new SitesRepository(),
            new SignedHttpClient(new MockHttpClient($factory)),
            new ActivityLogger(),
        );

        $job->handle($this->siteId, 0);

        $this->assertSame('POST', $captured['method']);
        $this->assertSame(300, (int) $captured['options']['timeout']);
        $this->assertStringEndsWith('/wp-json/defyn-connector/v1/core/update', $captured['url']);

        $row = (new SitesRepository())->findById($this->siteId);
        $this->assertSame('idle', $row->coreUpdateState);
        $this->assertSame('7.0.1', $row->wpVersion);
        $this->assertFalse($row->coreUpdateAvailable);
        $this->assertNull($row->coreUpdateVersion);
    }

    public function testTripletStartedAndSucceededEventsLoggedInOrder(): void
    {
        $body = json_encode([
            'success'          => true,
            'previous_version' => '7.0',
            'new_version'      => '7.0.1',
            'server_time'      => time(),
        ]);
        $factory = fn () => new MockResponse($body, ['http_code' => 200]);

        $job = new UpdateSiteCore(
            new SitesRepository(),
            new SignedHttpClient(new MockHttpClient($factory)),
            new ActivityLogger(),
        );

        $job->handle($this->siteId, 0);

        global $wpdb;
        $events = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT event_type FROM {$wpdb->prefix}defyn_activity_log
                 WHERE site_id = %d AND event_type LIKE 'core_update%%'
                 ORDER BY id ASC",
                $this->siteId
            )
        );
        $this->assertSame(['core_update.started', 'core_update.succeeded'], $events);
    }

    public function testTimeoutConstantIsThreeHundred(): void
    {
        $this->assertSame(300, UpdateSiteCore::TIMEOUT_SECONDS);
    }
}
