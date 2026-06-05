<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Unit\Http;

use Defyn\Dashboard\Http\SignedHttpClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SignedHttpClientTimeoutTest extends TestCase
{
    public function testSignedPostJsonPassesCustomTimeoutToHttpClient(): void
    {
        $observed = null;
        $factory = function (string $method, string $url, array $options) use (&$observed) {
            $observed = $options;
            return new MockResponse('{}', ['http_code' => 200]);
        };

        $client = new SignedHttpClient(new MockHttpClient($factory));
        $keypair = sodium_crypto_sign_keypair();
        $privateKey = base64_encode(sodium_crypto_sign_secretkey($keypair));

        $client->signedPostJson(
            'https://example.test/wp-json/defyn-connector/v1/plugins/foo/update',
            [],
            $privateKey,
            '/defyn-connector/v1/plugins/foo/update',
            timeoutSeconds: 120,
        );

        $this->assertNotNull($observed);
        $this->assertEquals(120, $observed['timeout']);
    }

    public function testSignedPostJsonDefaultTimeoutIsThirtySeconds(): void
    {
        $observed = null;
        $factory = function (string $method, string $url, array $options) use (&$observed) {
            $observed = $options;
            return new MockResponse('{}', ['http_code' => 200]);
        };

        $client = new SignedHttpClient(new MockHttpClient($factory));
        $keypair = sodium_crypto_sign_keypair();
        $privateKey = base64_encode(sodium_crypto_sign_secretkey($keypair));

        $client->signedPostJson(
            'https://example.test/wp-json/defyn-connector/v1/status',
            ['hello' => 'world'],
            $privateKey,
            '/defyn-connector/v1/status',
        );

        $this->assertEquals(30, $observed['timeout']);
    }

    public function testSignedGetPassesCustomTimeout(): void
    {
        $observed = null;
        $factory = function (string $method, string $url, array $options) use (&$observed) {
            $observed = $options;
            return new MockResponse('{}', ['http_code' => 200]);
        };

        $client = new SignedHttpClient(new MockHttpClient($factory));
        $keypair = sodium_crypto_sign_keypair();
        $privateKey = base64_encode(sodium_crypto_sign_secretkey($keypair));

        $client->signedGet(
            'https://example.test/wp-json/defyn-connector/v1/plugins',
            $privateKey,
            '/defyn-connector/v1/plugins',
            timeoutSeconds: 60,
        );

        $this->assertEquals(60, $observed['timeout']);
    }
}
