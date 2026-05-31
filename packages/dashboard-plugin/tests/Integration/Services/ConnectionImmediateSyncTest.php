<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Jobs\SyncSite;
use Defyn\Dashboard\Schema\ActivityLogTable;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\Connection;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * F7 — immediate sync-after-handshake UX improvement.
 *
 * After a successful F5 handshake (Connection::complete flips a pending site
 * to `active`), the dashboard schedules a one-shot `defyn_sync_site` AS job
 * so runtime info (wp_version, php_version, ssl_status) appears within seconds
 * instead of waiting up to 30 minutes for the next sync_all tick.
 *
 * @group integration
 */
final class ConnectionImmediateSyncTest extends AbstractSchemaTestCase
{
    private SitesRepository $repo;

    public function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_sites');
        $this->freshlyActivate('defyn_activity_log');
        global $wpdb;
        $wpdb->query('TRUNCATE ' . SitesTable::tableName());
        $wpdb->query('TRUNCATE ' . ActivityLogTable::tableName());
        $this->repo = new SitesRepository();

        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(SyncSite::HOOK, null, 'defyn');
        }
    }

    public function testSuccessfulHandshakeEnqueuesImmediateSync(): void
    {
        // ===== F5 happy-path fixture (mirrors ConnectionTest::testValidHandshake…) =====

        // Connector's K_site keypair — test side signs so the dashboard verifies.
        $kSitePair = sodium_crypto_sign_keypair();
        $kSitePub  = sodium_crypto_sign_publickey($kSitePair);
        $kSitePriv = sodium_crypto_sign_secretkey($kSitePair);

        $siteId = $this->repo->insertPending(7, 'https://example.test', 'Test', 'OURPUB==', 'OURENC==');

        $mock = new MockHttpClient(function (string $method, string $url, array $opts) use ($kSitePub, $kSitePriv): MockResponse {
            $body = json_decode($opts['body'], true);
            // F5 canonical-string-to-sign: just callback_challenge.
            $signature = sodium_crypto_sign_detached($body['callback_challenge'], $kSitePriv);
            return new MockResponse(
                json_encode([
                    'site_public_key'     => base64_encode($kSitePub),
                    'challenge_signature' => base64_encode($signature),
                    'site_url'            => 'https://example.test',
                    'site_name'           => 'Test',
                ]),
                ['http_code' => 200, 'response_headers' => ['Content-Type' => 'application/json']],
            );
        });

        $connection = new Connection(
            httpClient:         new SignedHttpClient($mock),
            repo:               $this->repo,
            logger:             new ActivityLogger(),
            dashboardPublicKey: 'OURPUB==',
        );

        $connection->complete($siteId, 'ABCDEFGH2345', 'https://example.test');

        // ===== F5 invariant preserved =====
        self::assertSame('active', $this->repo->findById($siteId)->status);

        // ===== F7 NEW assertion — one-shot sync_site job enqueued =====
        self::assertNotFalse(
            as_next_scheduled_action(SyncSite::HOOK, [$siteId], 'defyn'),
            'Successful handshake must enqueue an immediate sync_site AS job',
        );
    }

    public function testFailedHandshakeDoesNotEnqueueImmediateSync(): void
    {
        // Guard regression: only the happy path enqueues. A signature-rejected
        // handshake must NOT schedule a doomed sync job.
        $siteId = $this->repo->insertPending(7, 'https://example.test', '', 'OURPUB==', 'OURENC==');

        $wrongPair = sodium_crypto_sign_keypair();
        $wrongPub  = sodium_crypto_sign_publickey($wrongPair);
        $mock = new MockHttpClient(function () use ($wrongPub): MockResponse {
            return new MockResponse(
                json_encode([
                    'site_public_key'     => base64_encode($wrongPub),
                    'challenge_signature' => base64_encode(str_repeat("\x00", 64)),
                ]),
                ['http_code' => 200, 'response_headers' => ['Content-Type' => 'application/json']],
            );
        });

        (new Connection(new SignedHttpClient($mock), $this->repo, new ActivityLogger(), 'OURPUB=='))
            ->complete($siteId, 'CODE', 'https://example.test');

        self::assertSame('error', $this->repo->findById($siteId)->status);
        self::assertFalse(
            as_next_scheduled_action(SyncSite::HOOK, [$siteId], 'defyn'),
            'Failed handshake must NOT enqueue a sync_site job',
        );
    }
}
