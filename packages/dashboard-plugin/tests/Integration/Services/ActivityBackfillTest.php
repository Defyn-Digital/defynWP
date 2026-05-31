<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Schema\ActivityLogTable;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\DisconnectService;
use Defyn\Dashboard\Services\HealthService;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Services\SyncService;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * F9 Task 3 — backfill activity-log writes into the F6/F8 services.
 *
 * Each happy/sad path inside SyncService::sync, HealthService::ping, and
 * DisconnectService::disconnect must record exactly one activity row with the
 * expected event_type. Test polls the most recently inserted row rather than
 * asserting a specific id, mirroring the established Services/* mock pattern.
 *
 * @group integration
 */
final class ActivityBackfillTest extends AbstractSchemaTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_activity_log');
        $this->freshlyActivate('defyn_sites');
        global $wpdb;
        $wpdb->query('TRUNCATE ' . SitesTable::tableName());
        $wpdb->query('TRUNCATE ' . ActivityLogTable::tableName());
    }

    private function makeSite(int $userId = 1): int
    {
        $repo  = new SitesRepository();
        $vault = new Vault(DEFYN_VAULT_KEY);
        $priv  = base64_encode(random_bytes(64));
        $id    = $repo->insertPending(
            userId: $userId,
            url: 'https://site.test',
            label: 'Site',
            ourPublicKey: base64_encode(random_bytes(32)),
            ourPrivateKeyEncrypted: $vault->encrypt($priv),
        );
        $repo->markActive($id, base64_encode(random_bytes(32)));
        return $id;
    }

    private function lastEventType(): ?string
    {
        global $wpdb;
        $row = $wpdb->get_var('SELECT event_type FROM ' . ActivityLogTable::tableName() . ' ORDER BY id DESC LIMIT 1');
        return $row === null ? null : (string) $row;
    }

    public function testSyncServiceLogsSiteSyncedOnSuccess(): void
    {
        $siteId = $this->makeSite();

        $mock = new MockHttpClient(fn () => new MockResponse(
            json_encode([
                'wp_version'     => '6.9.4',
                'php_version'    => '8.2.27',
                'active_theme'   => null,
                'plugin_counts'  => null,
                'theme_counts'   => null,
                'ssl_status'     => 'enabled',
                'ssl_expires_at' => null,
            ]),
            ['http_code' => 200],
        ));

        (new SyncService(new SignedHttpClient($mock)))->sync($siteId);

        self::assertSame('site.synced', $this->lastEventType());
    }

    public function testSyncServiceLogsSiteSyncFailedOnTransportError(): void
    {
        $siteId = $this->makeSite();

        $mock = new MockHttpClient(function ($method, $url, $options) {
            throw new TransportException('boom');
        });

        (new SyncService(new SignedHttpClient($mock)))->sync($siteId);

        self::assertSame('site.sync_failed', $this->lastEventType());
    }

    public function testHealthServiceLogsHealthOk(): void
    {
        $siteId = $this->makeSite();

        $mock = new MockHttpClient(fn () => new MockResponse(
            json_encode(['ok' => true, 'server_time' => time()]),
            ['http_code' => 200],
        ));

        (new HealthService(new SignedHttpClient($mock)))->ping($siteId);

        self::assertSame('site.health_ok', $this->lastEventType());
    }

    public function testHealthServiceLogsHealthFailedOnTransport(): void
    {
        $siteId = $this->makeSite();

        $mock = new MockHttpClient(function ($method, $url, $options) {
            throw new TransportException('boom');
        });

        (new HealthService(new SignedHttpClient($mock)))->ping($siteId);

        self::assertSame('site.health_failed', $this->lastEventType());
    }

    public function testHealthServiceLogsRecoveredFromOffline(): void
    {
        $siteId = $this->makeSite();
        (new SitesRepository())->markOffline($siteId, 'was offline');

        $mock = new MockHttpClient(fn () => new MockResponse(
            json_encode(['ok' => true, 'server_time' => time()]),
            ['http_code' => 200],
        ));

        (new HealthService(new SignedHttpClient($mock)))->ping($siteId);

        self::assertSame('site.recovered', $this->lastEventType());
    }

    public function testDisconnectServiceLogsSiteDisconnected(): void
    {
        $siteId = $this->makeSite();

        $mock = new MockHttpClient(fn () => new MockResponse('', ['http_code' => 204]));

        (new DisconnectService(new SignedHttpClient($mock)))->disconnect($siteId, 1);

        self::assertSame('site.disconnected', $this->lastEventType());
    }
}
