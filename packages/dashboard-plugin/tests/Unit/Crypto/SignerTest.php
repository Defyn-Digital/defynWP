<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Unit\Crypto;

use Defyn\Dashboard\Crypto\Signer;
use PHPUnit\Framework\TestCase;

final class SignerTest extends TestCase
{
    public function testCanonicalProducesSpecFormat(): void
    {
        // Spec § 5.2: METHOD\nPATH\nTIMESTAMP\nNONCE\nsha256(BODY)
        $canonical = Signer::canonical('GET', '/wp-json/defyn-connector/v1/status', '1776494192', 'abc123', '');

        $expectedBodyHash = hash('sha256', '');
        self::assertSame(
            "GET\n/wp-json/defyn-connector/v1/status\n1776494192\nabc123\n{$expectedBodyHash}",
            $canonical
        );
    }

    public function testCanonicalUppercasesMethod(): void
    {
        $upper = Signer::canonical('GET', '/x', '1', 'n', '');
        $lower = Signer::canonical('get', '/x', '1', 'n', '');

        self::assertSame($upper, $lower, 'method must be normalized to uppercase');
    }

    public function testCanonicalHashesBodyContent(): void
    {
        $body = '{"hello":"world"}';
        $canonical = Signer::canonical('POST', '/x', '1', 'n', $body);

        self::assertStringEndsWith("\n" . hash('sha256', $body), $canonical);
    }

    public function testCanonicalIsDeterministic(): void
    {
        $a = Signer::canonical('POST', '/x', '1', 'n', 'body');
        $b = Signer::canonical('POST', '/x', '1', 'n', 'body');

        self::assertSame($a, $b);
    }

    public function testSignRequestReturnsThreeExpectedHeaders(): void
    {
        $pair = \Defyn\Dashboard\Crypto\KeyPair::generate();
        $signer = new Signer($pair->privateKey);

        $headers = $signer->signRequest('POST', '/wp-json/defyn-connector/v1/connect', '{"x":1}');

        self::assertArrayHasKey('X-Defyn-Timestamp', $headers);
        self::assertArrayHasKey('X-Defyn-Nonce', $headers);
        self::assertArrayHasKey('X-Defyn-Signature', $headers);
    }

    public function testSignRequestTimestampIsRecent(): void
    {
        $pair = \Defyn\Dashboard\Crypto\KeyPair::generate();
        $signer = new Signer($pair->privateKey);

        $headers = $signer->signRequest('GET', '/x', '');
        $ts = (int) $headers['X-Defyn-Timestamp'];

        self::assertGreaterThanOrEqual(time() - 5, $ts, 'timestamp should be recent');
        self::assertLessThanOrEqual(time() + 5, $ts);
    }

    public function testSignRequestNonceIsUniqueAcrossCalls(): void
    {
        $pair = \Defyn\Dashboard\Crypto\KeyPair::generate();
        $signer = new Signer($pair->privateKey);

        $a = $signer->signRequest('GET', '/x', '');
        $b = $signer->signRequest('GET', '/x', '');

        self::assertNotSame($a['X-Defyn-Nonce'], $b['X-Defyn-Nonce']);
    }

    public function testSignRequestSignatureIsBase64Of64Bytes(): void
    {
        $pair = \Defyn\Dashboard\Crypto\KeyPair::generate();
        $signer = new Signer($pair->privateKey);

        $headers = $signer->signRequest('GET', '/x', '');

        $raw = base64_decode($headers['X-Defyn-Signature'], true);
        self::assertNotFalse($raw, 'signature should be valid base64');
        self::assertSame(64, strlen($raw), 'Ed25519 signatures are 64 bytes');
    }

    public function testSignRequestProducesDifferentSignaturesForSameInput(): void
    {
        // Different timestamp/nonce per call → different canonical → different signature.
        $pair = \Defyn\Dashboard\Crypto\KeyPair::generate();
        $signer = new Signer($pair->privateKey);

        $a = $signer->signRequest('GET', '/x', 'body');
        $b = $signer->signRequest('GET', '/x', 'body');

        self::assertNotSame($a['X-Defyn-Signature'], $b['X-Defyn-Signature']);
    }

    public function testConstructorRejectsInvalidPrivateKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('64-byte Ed25519 secret key');

        new Signer('not-base64-or-wrong-length');
    }
}
