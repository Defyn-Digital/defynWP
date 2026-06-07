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
 * P2.4.1 — Verify UpdateSiteCore threads allow_major into the connector body
 * and into the core_update.started activity event.
 *
 * @group integration
 */
final class UpdateSiteCoreAllowMajorTest extends AbstractSchemaTestCase
{
    private int $siteId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_sites');
        $this->freshlyActivate('defyn_activity_log');

        $keypair    = sodium_crypto_sign_keypair();
        $privateKey = base64_encode(sodium_crypto_sign_secretkey($keypair));
        $vault      = new Vault(DEFYN_VAULT_KEY);
        $encrypted  = $vault->encrypt($privateKey);

        global $wpdb;
        $wpdb->insert(SitesTable::tableName(), [
            'user_id'                => 1,
            'url'                    => 'https://example.com',
            'label'                  => 'Example',
            'status'                 => 'active',
            'our_private_key'        => $encrypted,
            'site_public_key'        => base64_encode(sodium_crypto_sign_publickey($keypair)),
            'wp_version'             => '7.4',
            'php_version'            => '8.3.31',
            'plugin_counts'          => '{"installed":0,"active":0}',
            'theme_counts'           => '{"installed":0,"active":0}',
            'ssl_status'             => 'enabled',
            'ssl_expires_at'         => null,
            'last_sync_at'           => '2026-06-07 04:00:00',
            'last_contact_at'        => '2026-06-07 04:00:00',
            'created_at'             => '2026-06-07 00:00:00',
            'updated_at'             => '2026-06-07 04:00:00',
            'core_update_available'  => 1,
            'core_update_version'    => '8.0',
            'core_update_state'      => 'queued',
            'last_core_update_error' => null,
        ]);
        $this->siteId = (int) $wpdb->insert_id;
    }

    public function testJobPassesAllowMajorTrueWhenFlagIsOn(): void
    {
        (new SitesRepository())->setCoreAllowMajor($this->siteId, true);

        $capturedBody = null;
        $factory      = function (string $method, string $url, array $options) use (&$capturedBody) {
            $capturedBody = json_decode($options['body'] ?? '{}', true);
            return new MockResponse(json_encode([
                'success'          => true,
                'previous_version' => '7.4',
                'new_version'      => '8.0',
                'server_time'      => time(),
            ]), ['http_code' => 200]);
        };

        $this->runJob($factory);

        $this->assertIsArray($capturedBody);
        $this->assertArrayHasKey('allow_major', $capturedBody);
        $this->assertTrue($capturedBody['allow_major']);
    }

    public function testJobPassesAllowMajorFalseWhenFlagIsOff(): void
    {
        // Flag is OFF by default — no setCoreAllowMajor call.

        $capturedBody = null;
        $factory      = function (string $method, string $url, array $options) use (&$capturedBody) {
            $capturedBody = json_decode($options['body'] ?? '{}', true);
            return new MockResponse(json_encode([
                'success'          => true,
                'previous_version' => '7.4',
                'new_version'      => '7.4.1',
                'server_time'      => time(),
            ]), ['http_code' => 200]);
        };

        $this->runJob($factory);

        $this->assertIsArray($capturedBody);
        $this->assertArrayHasKey('allow_major', $capturedBody);
        $this->assertFalse($capturedBody['allow_major']);
    }

    public function testStartedActivityEventIncludesAllowMajor(): void
    {
        (new SitesRepository())->setCoreAllowMajor($this->siteId, true);

        $factory = static fn () => new MockResponse(json_encode([
            'success'          => true,
            'previous_version' => '7.4',
            'new_version'      => '8.0',
            'server_time'      => time(),
        ]), ['http_code' => 200]);

        $this->runJob($factory);

        global $wpdb;
        $activity = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}defyn_activity_log
                 WHERE site_id = %d AND event_type = 'core_update.started'
                 ORDER BY id DESC LIMIT 1",
                $this->siteId
            ),
            ARRAY_A
        );
        $this->assertNotNull($activity);
        $details = json_decode($activity['details'] ?? '{}', true);
        $this->assertArrayHasKey('allow_major', $details);
        $this->assertTrue($details['allow_major']);
    }

    /**
     * @param callable $factory MockHttpClient factory
     */
    private function runJob(callable $factory): void
    {
        $job = new UpdateSiteCore(
            new SitesRepository(),
            new SignedHttpClient(new MockHttpClient($factory)),
            new ActivityLogger(),
        );
        $job->handle($this->siteId, 0);
    }
}
