<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Schema\ActivityLogTable;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\Connection;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @group integration
 */
final class ConnectionTest extends AbstractSchemaTestCase
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
    }

    public function testValidHandshakeMarksSiteActiveAndLogsConnection(): void
    {
        // Connector's K_site keypair (test-side: we sign with this so the dashboard verifies).
        $kSitePair  = sodium_crypto_sign_keypair();
        $kSitePub   = sodium_crypto_sign_publickey($kSitePair);
        $kSitePriv  = sodium_crypto_sign_secretkey($kSitePair);

        $id = $this->repo->insertPending(7, 'https://example.test', 'Test', 'OURPUB==', 'OURENC==');
        $siteNonce = base64_encode(random_bytes(32));  // kept for documentation; not used in signature

        // Capture the request body so we can sign exactly what the dashboard sent.
        $capturedBody = null;
        $mock = new MockHttpClient(function (string $method, string $url, array $opts) use (&$capturedBody, $kSitePub, $kSitePriv): MockResponse {
            $capturedBody = json_decode($opts['body'], true);
            // F5: sign JUST callback_challenge (NOT challenge + site_nonce). See plan Task 8 IMPORTANT note.
            $signature = sodium_crypto_sign_detached($capturedBody['callback_challenge'], $kSitePriv);
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
            httpClient: new SignedHttpClient($mock),
            repo:       $this->repo,
            logger:     new ActivityLogger(),
            dashboardPublicKey: 'OURPUB==',
        );

        $connection->complete($id, 'ABCDEFGH2345', 'https://example.test');

        $site = $this->repo->findById($id);
        self::assertSame('active', $site->status);
        self::assertSame(base64_encode($kSitePub), $site->sitePublicKey);
        self::assertNotNull($site->lastContactAt);

        // Activity log row written.
        global $wpdb;
        $logs = $wpdb->get_results('SELECT * FROM ' . ActivityLogTable::tableName(), ARRAY_A);
        self::assertCount(1, $logs);
        self::assertSame('site.connected', $logs[0]['event_type']);
        self::assertSame((string) $id, $logs[0]['site_id']);

        // Sanity: the dashboard sent the expected body shape.
        self::assertSame('ABCDEFGH2345', $capturedBody['code']);
        self::assertSame('OURPUB==', $capturedBody['dashboard_public_key']);
        self::assertNotEmpty($capturedBody['callback_challenge']);
    }

    public function testInvalidSignatureMarksErrorAndLogsRejection(): void
    {
        $id = $this->repo->insertPending(7, 'https://example.test', '', 'OURPUB==', 'OURENC==');

        // Return a syntactically valid but cryptographically WRONG signature.
        $wrongPair = sodium_crypto_sign_keypair();
        $wrongPub  = sodium_crypto_sign_publickey($wrongPair);
        $mock = new MockHttpClient(function () use ($wrongPub): MockResponse {
            return new MockResponse(
                json_encode([
                    'site_public_key'     => base64_encode($wrongPub),
                    'challenge_signature' => base64_encode(str_repeat("\x00", 64)),  // garbage sig
                ]),
                ['http_code' => 200, 'response_headers' => ['Content-Type' => 'application/json']],
            );
        });

        (new Connection(new SignedHttpClient($mock), $this->repo, new ActivityLogger(), 'OURPUB=='))
            ->complete($id, 'CODE', 'https://example.test');

        $site = $this->repo->findById($id);
        self::assertSame('error', $site->status);
        self::assertSame('Challenge signature invalid', $site->lastError);

        global $wpdb;
        $logs = $wpdb->get_results('SELECT * FROM ' . ActivityLogTable::tableName(), ARRAY_A);
        self::assertCount(1, $logs);
        self::assertSame('site.connection_rejected', $logs[0]['event_type']);
    }

    public function testConnectorErrorResponseSurfacesEnvelopeMessage(): void
    {
        $id = $this->repo->insertPending(7, 'https://example.test', '', 'OURPUB==', 'OURENC==');

        $mock = new MockHttpClient([
            new MockResponse(
                json_encode(['error' => ['code' => 'connector.code_expired', 'message' => 'Connection code has expired. Generate a new one.']]),
                ['http_code' => 410, 'response_headers' => ['Content-Type' => 'application/json']],
            ),
        ]);

        (new Connection(new SignedHttpClient($mock), $this->repo, new ActivityLogger(), 'OURPUB=='))
            ->complete($id, 'CODE', 'https://example.test');

        $site = $this->repo->findById($id);
        self::assertSame('error', $site->status);
        self::assertStringContainsString('Connection code has expired', $site->lastError);

        global $wpdb;
        $logs = $wpdb->get_results('SELECT * FROM ' . ActivityLogTable::tableName(), ARRAY_A);
        self::assertSame('site.error', $logs[0]['event_type']);
    }

    public function testTransportErrorMarksSiteError(): void
    {
        $id = $this->repo->insertPending(7, 'https://nowhere.test', '', 'OURPUB==', 'OURENC==');

        $mock = new MockHttpClient(static function (): MockResponse {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('Could not resolve host');
        });

        (new Connection(new SignedHttpClient($mock), $this->repo, new ActivityLogger(), 'OURPUB=='))
            ->complete($id, 'CODE', 'https://nowhere.test');

        $site = $this->repo->findById($id);
        self::assertSame('error', $site->status);
        self::assertStringContainsString('Could not resolve host', $site->lastError);
    }

    public function testCompleteShortCircuitsIfSiteAlreadyActive(): void
    {
        // Pre-populate an active site (simulating a prior successful handshake).
        $id = $this->repo->insertPending(7, 'https://example.test', '', 'OURPUB==', 'OURENC==');
        $this->repo->markActive($id, 'EXISTING_SITE_PUB==');

        // MockHttpClient that explodes if invoked — proves complete() short-circuited.
        $callCount = 0;
        $mock = new MockHttpClient(function () use (&$callCount): MockResponse {
            $callCount++;
            return new MockResponse('', ['http_code' => 200]);
        });

        (new Connection(new SignedHttpClient($mock), $this->repo, new ActivityLogger(), 'OURPUB=='))
            ->complete($id, 'CODE', 'https://example.test');

        // No HTTP call was made.
        self::assertSame(0, $callCount);

        // Status preserved.
        $site = $this->repo->findById($id);
        self::assertSame('active', $site->status);
        self::assertSame('EXISTING_SITE_PUB==', $site->sitePublicKey);

        // No new activity log row (existing rows from markActive are not from this test path).
        global $wpdb;
        $logs = $wpdb->get_results('SELECT * FROM ' . ActivityLogTable::tableName(), ARRAY_A);
        self::assertCount(0, $logs);
    }
}
