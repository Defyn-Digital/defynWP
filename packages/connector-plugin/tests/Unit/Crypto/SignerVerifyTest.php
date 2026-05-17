<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Unit\Crypto;

use Defyn\Connector\Crypto\NonceStore;
use Defyn\Connector\Crypto\Signer;
use Defyn\Connector\Crypto\VerificationResult;
use PHPUnit\Framework\TestCase;

final class SignerVerifyTest extends TestCase
{
    private function nonceStore(): NonceStore
    {
        return new class implements NonceStore {
            /** @var array<string,bool> */
            private array $seen = [];
            public function remember(string $nonce, int $ttlSeconds): bool {
                if (isset($this->seen[$nonce])) return false;
                $this->seen[$nonce] = true;
                return true;
            }
        };
    }

    /** @return array{public: string, private: string} */
    private function freshKeyPair(): array
    {
        $kp = sodium_crypto_sign_keypair();
        return [
            'public'  => base64_encode(sodium_crypto_sign_publickey($kp)),
            'private' => sodium_crypto_sign_secretkey($kp),
        ];
    }

    public function testValidSignedRequestReturnsValid(): void
    {
        $kp        = $this->freshKeyPair();
        $timestamp = (string) time();
        $nonce     = bin2hex(random_bytes(16));
        $body      = '{"x":1}';
        $canonical = Signer::canonical('POST', '/x', $timestamp, $nonce, $body);
        $sig       = base64_encode(sodium_crypto_sign_detached($canonical, $kp['private']));

        $headers = [
            'X-Defyn-Timestamp' => $timestamp,
            'X-Defyn-Nonce'     => $nonce,
            'X-Defyn-Signature' => $sig,
        ];

        $this->assertSame(
            VerificationResult::VALID,
            Signer::verifyRequest($kp['public'], 'POST', '/x', $body, $headers, $this->nonceStore())
        );
    }

    public function testMissingHeadersReturnsMissingHeaders(): void
    {
        $kp = $this->freshKeyPair();
        $this->assertSame(
            VerificationResult::MISSING_HEADERS,
            Signer::verifyRequest($kp['public'], 'GET', '/x', '', [], $this->nonceStore())
        );
    }

    public function testExpiredTimestampReturnsExpired(): void
    {
        $kp        = $this->freshKeyPair();
        $timestamp = (string) (time() - 1000);
        $nonce     = 'n1';
        $canonical = Signer::canonical('GET', '/x', $timestamp, $nonce, '');
        $sig       = base64_encode(sodium_crypto_sign_detached($canonical, $kp['private']));

        $this->assertSame(
            VerificationResult::EXPIRED_TIMESTAMP,
            Signer::verifyRequest($kp['public'], 'GET', '/x', '', [
                'X-Defyn-Timestamp' => $timestamp,
                'X-Defyn-Nonce'     => $nonce,
                'X-Defyn-Signature' => $sig,
            ], $this->nonceStore())
        );
    }

    public function testReplayedNonceReturnsReplayed(): void
    {
        $kp        = $this->freshKeyPair();
        $timestamp = (string) time();
        $nonce     = 'replay-me';
        $canonical = Signer::canonical('GET', '/x', $timestamp, $nonce, '');
        $sig       = base64_encode(sodium_crypto_sign_detached($canonical, $kp['private']));

        $headers = [
            'X-Defyn-Timestamp' => $timestamp,
            'X-Defyn-Nonce'     => $nonce,
            'X-Defyn-Signature' => $sig,
        ];

        $store = $this->nonceStore();
        $this->assertSame(VerificationResult::VALID, Signer::verifyRequest($kp['public'], 'GET', '/x', '', $headers, $store));
        $this->assertSame(VerificationResult::REPLAYED_NONCE, Signer::verifyRequest($kp['public'], 'GET', '/x', '', $headers, $store));
    }

    public function testTamperedBodyReturnsInvalid(): void
    {
        $kp        = $this->freshKeyPair();
        $timestamp = (string) time();
        $nonce     = bin2hex(random_bytes(16));
        $canonical = Signer::canonical('POST', '/x', $timestamp, $nonce, 'original');
        $sig       = base64_encode(sodium_crypto_sign_detached($canonical, $kp['private']));

        $this->assertSame(
            VerificationResult::INVALID_SIGNATURE,
            Signer::verifyRequest($kp['public'], 'POST', '/x', 'tampered', [
                'X-Defyn-Timestamp' => $timestamp,
                'X-Defyn-Nonce'     => $nonce,
                'X-Defyn-Signature' => $sig,
            ], $this->nonceStore())
        );
    }
}
