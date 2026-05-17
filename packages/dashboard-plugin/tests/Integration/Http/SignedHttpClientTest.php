<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Http;

use Defyn\Dashboard\Http\SignedHttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class SignedHttpClientTest extends WP_UnitTestCase
{
    public function testPostJsonReturnsStatusAndDecodedBody(): void
    {
        $mock = new MockHttpClient([
            new MockResponse(
                json_encode(['ok' => true, 'site_public_key' => 'PUB==']),
                ['http_code' => 200, 'response_headers' => ['Content-Type' => 'application/json']],
            ),
        ]);
        $client = new SignedHttpClient($mock);

        $result = $client->postJson('https://example.test/api/connect', ['code' => 'X']);

        self::assertSame(200, $result['status']);
        self::assertSame(['ok' => true, 'site_public_key' => 'PUB=='], $result['body']);
    }

    public function testPostJsonSurfacesNon2xxBody(): void
    {
        $mock = new MockHttpClient([
            new MockResponse(
                json_encode(['error' => ['code' => 'connector.code_expired', 'message' => 'expired']]),
                ['http_code' => 410, 'response_headers' => ['Content-Type' => 'application/json']],
            ),
        ]);
        $client = new SignedHttpClient($mock);

        $result = $client->postJson('https://example.test/api/connect', ['code' => 'X']);

        self::assertSame(410, $result['status']);
        self::assertSame('connector.code_expired', $result['body']['error']['code']);
    }

    public function testPostJsonReturnsStatusZeroOnTransportError(): void
    {
        // A factory that throws on call simulates DNS / TCP errors.
        $mock = new MockHttpClient(static function (): MockResponse {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('DNS failure');
        });
        $client = new SignedHttpClient($mock);

        $result = $client->postJson('https://nowhere.test/api/connect', ['code' => 'X']);

        self::assertSame(0, $result['status']);
        self::assertSame([], $result['body']);
        self::assertNotEmpty($result['error']);
    }
}
