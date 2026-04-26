<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Unit\Crypto;

use Defyn\Dashboard\Crypto\Vault;
use PHPUnit\Framework\TestCase;

final class VaultTest extends TestCase
{
    public function testGenerateKeyReturnsBase64Of32Bytes(): void
    {
        $key = Vault::generateKey();

        $raw = base64_decode($key, true);
        self::assertNotFalse($raw, 'generated key must be valid base64');
        self::assertSame(32, strlen($raw), 'sodium secretbox keys are 32 bytes');
    }

    public function testEncryptDecryptRoundTripReturnsOriginalPlaintext(): void
    {
        $vault = new Vault(Vault::generateKey());
        $plaintext = 'super secret private key bytes here';

        $envelope = $vault->encrypt($plaintext);
        $recovered = $vault->decrypt($envelope);

        self::assertSame($plaintext, $recovered);
    }

    public function testEncryptingSamePlaintextTwiceProducesDifferentCiphertexts(): void
    {
        // Random nonce per call ensures ciphertext indistinguishability.
        $vault = new Vault(Vault::generateKey());
        $a = $vault->encrypt('hello');
        $b = $vault->encrypt('hello');

        self::assertNotSame($a, $b, 'random nonce should make repeated encryptions distinct');
    }

    public function testEnvelopeIsBase64(): void
    {
        $vault = new Vault(Vault::generateKey());
        $envelope = $vault->encrypt('x');

        self::assertNotFalse(base64_decode($envelope, true), 'envelope must be valid base64');
    }

    public function testDecryptThrowsWhenCiphertextTampered(): void
    {
        $vault = new Vault(Vault::generateKey());
        $envelope = $vault->encrypt('the truth is out there');

        // Flip a byte in the middle of the envelope.
        $bytes = base64_decode($envelope, true);
        $mid = (int) (strlen($bytes) / 2);
        $bytes[$mid] = chr(ord($bytes[$mid]) ^ 0x01);
        $tampered = base64_encode($bytes);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Vault decryption failed');

        $vault->decrypt($tampered);
    }

    public function testDecryptThrowsWhenWrongKey(): void
    {
        $aVault = new Vault(Vault::generateKey());
        $envelope = $aVault->encrypt('secret');

        $bVault = new Vault(Vault::generateKey());  // different key

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Vault decryption failed');

        $bVault->decrypt($envelope);
    }

    public function testConstructorThrowsOnKeyOfWrongLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('32-byte key');

        new Vault(base64_encode('too short'));  // 9 bytes after decode
    }

    public function testConstructorThrowsOnInvalidBase64Key(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // base64_decode strict=true rejects this because '!' is not a base64 char.
        new Vault('not!valid!base64!');
    }

    public function testDecryptThrowsOnInvalidBase64Envelope(): void
    {
        $vault = new Vault(Vault::generateKey());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not valid base64');

        $vault->decrypt('not!valid!base64!');
    }

    public function testDecryptThrowsOnEnvelopeTooShort(): void
    {
        $vault = new Vault(Vault::generateKey());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('too short');

        // Anything shorter than NONCE_BYTES (24) + MAC (16) = 40 bytes should fail the length check.
        $vault->decrypt(base64_encode(str_repeat('a', 30)));
    }
}
