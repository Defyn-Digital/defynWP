<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Unit\Http;

use Defyn\Dashboard\Crypto\Signer;
use Defyn\Dashboard\Http\SignedHttpClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SignedHttpClientSignedRequestsTest extends TestCase
{
    public function testSignedGetAttachesThreeSigningHeaders(): void
    {
        $kp      = sodium_crypto_sign_keypair();
        $privB64 = base64_encode(sodium_crypto_sign_secretkey($kp));
        $pubB64  = base64_encode(sodium_crypto_sign_publickey($kp));

        $capturedHeaders = [];
        $mock = new MockHttpClient(function ($method, $url, $options) use (&$capturedHeaders) {
            $capturedHeaders = $options['headers'] ?? [];
            return new MockResponse(json_encode(['ok' => true]), ['http_code' => 200]);
        });

        $client = new SignedHttpClient($mock);
        $result = $client->signedGet('https://site.test/wp-json/defyn-connector/v1/heartbeat', $privB64, '/defyn-connector/v1/heartbeat');

        $this->assertSame(200, $result['status']);

        $flat = [];
        foreach ($capturedHeaders as $h) {
            [$name, $value] = explode(': ', $h, 2);
            $flat[$name] = $value;
        }
        $this->assertArrayHasKey('X-Defyn-Timestamp', $flat);
        $this->assertArrayHasKey('X-Defyn-Nonce', $flat);
        $this->assertArrayHasKey('X-Defyn-Signature', $flat);

        // Reverse-verify the signature with the matching public key
        $canon = Signer::canonical('GET', '/defyn-connector/v1/heartbeat', $flat['X-Defyn-Timestamp'], $flat['X-Defyn-Nonce'], '');
        $sig   = base64_decode($flat['X-Defyn-Signature'], true);
        $pub   = base64_decode($pubB64, true);
        $this->assertTrue(sodium_crypto_sign_verify_detached($sig, $canon, $pub));
    }

    public function testSignedPostJsonSignsSerializedBody(): void
    {
        $kp      = sodium_crypto_sign_keypair();
        $privB64 = base64_encode(sodium_crypto_sign_secretkey($kp));
        $pubB64  = base64_encode(sodium_crypto_sign_publickey($kp));

        $capturedHeaders = [];
        $capturedBody    = '';
        $mock = new MockHttpClient(function ($method, $url, $options) use (&$capturedHeaders, &$capturedBody) {
            $capturedHeaders = $options['headers'] ?? [];
            $capturedBody    = $options['body'] ?? '';
            return new MockResponse('', ['http_code' => 204]);
        });

        $client = new SignedHttpClient($mock);
        $body   = ['foo' => 'bar'];
        $result = $client->signedPostJson('https://site.test/wp-json/defyn-connector/v1/disconnect', $body, $privB64, '/defyn-connector/v1/disconnect');

        $this->assertSame(204, $result['status']);

        $flat = [];
        foreach ($capturedHeaders as $h) {
            [$name, $value] = explode(': ', $h, 2);
            $flat[$name] = $value;
        }
        $canon = Signer::canonical('POST', '/defyn-connector/v1/disconnect', $flat['X-Defyn-Timestamp'], $flat['X-Defyn-Nonce'], $capturedBody);
        $sig   = base64_decode($flat['X-Defyn-Signature'], true);
        $pub   = base64_decode($pubB64, true);
        $this->assertTrue(sodium_crypto_sign_verify_detached($sig, $canon, $pub));
    }
}
