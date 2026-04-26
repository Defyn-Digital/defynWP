# DefynWP Foundation F2 — Crypto Primitives Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Three pure-PHP crypto utilities under `Defyn\Dashboard\Crypto\` — `KeyPair` (Ed25519 keypair generation), `Signer` (sign/verify HTTP requests with replay protection), and `Vault` (authenticated symmetric encryption for at-rest private keys) — fully unit-tested, no HTTP, no WordPress hooks. Becomes the cryptographic foundation that F4 (connector handshake) and F6 (signed `/status` + `/heartbeat`) build on.

**Architecture:** Three single-responsibility classes plus one supporting interface (`NonceStore`) and one constants class (`VerificationResult`). All use PHP's built-in libsodium — zero new runtime dependencies. PHP 7.4-compatible (no enums, no constructor promotion, no readonly properties — keeps the floor stable until a deliberate version-bump task lands later).

**Tech Stack:** PHP 7.4+ (sodium ext bundled since 7.2) · PHPUnit 9.x · existing F1 test harness · pure unit tests (no `WP_UnitTestCase`, no DB)

---

## About this plan

This is **F2 of 10 sub-phases** in the DefynWP Foundation. F1 (Scaffolding) is merged to main. F2 builds the pure-crypto layer; F3 (auth + login REST) is next.

**Source spec:** [`docs/superpowers/specs/2026-04-18-defyn-foundation-design.md`](../specs/2026-04-18-defyn-foundation-design.md) — §5.2 (signing protocol) and §4.3 (private keys encrypted at rest).

**Definition of "F2 done":**
1. `Defyn\Dashboard\Crypto\KeyPair::generate()` produces a fresh Ed25519 keypair (32-byte public, 64-byte secret, base64-encoded)
2. `Defyn\Dashboard\Crypto\Signer` can sign a request (METHOD, PATH, BODY) into the three `X-Defyn-*` headers and verify them, returning a `VerificationResult` constant for each outcome
3. Verification correctly rejects: tampered body, tampered signature, expired/future timestamps (±300s window), replayed nonces, missing headers
4. `Defyn\Dashboard\Crypto\Vault` round-trips arbitrary plaintext through authenticated symmetric encryption with a 32-byte key; tampered ciphertext or wrong-key decryption throws
5. Full PHPUnit suite passes (~25 unit tests added on top of F1's 10 tests = ~35 total)
6. CI green on PHP 7.4 + 8.2

---

## Important deviation from the spec — encryption cipher

Spec §4.3 says "AES-256" for at-rest encryption of private keys. **This plan uses `sodium_crypto_secretbox` (XSalsa20-Poly1305) instead** for these reasons:

- **Same security level**: both provide authenticated encryption with comparable strength (256-bit security)
- **Already have libsodium**: we use it for Ed25519 in `Signer`; mixing in OpenSSL for AES adds a second crypto API for no benefit
- **Authenticated by default**: `secretbox` includes the Poly1305 MAC; AES alone (e.g., AES-CBC) needs a separate MAC and is a known footgun
- **Simpler API**: `sodium_crypto_secretbox($plaintext, $nonce, $key)` vs OpenSSL's flag-soup

If the user wants strict AES-256 compliance, the spec note in §4.3 should be updated to "AES-256 or equivalent (XSalsa20-Poly1305)" — that's a separate spec edit. For F2, the implementation uses sodium and the deviation is documented in `Vault`'s docblock.

---

## File structure after F2

```
packages/dashboard-plugin/
├── src/
│   ├── Crypto/                              # NEW (this plan)
│   │   ├── KeyPair.php                      # Ed25519 keypair generation
│   │   ├── Signer.php                       # sign + verify requests
│   │   ├── NonceStore.php                   # interface for replay protection
│   │   ├── InMemoryNonceStore.php           # in-memory NonceStore (used by tests, dev)
│   │   ├── VerificationResult.php           # final class with string constants
│   │   └── Vault.php                        # symmetric authenticated encryption
│   ├── Plugin.php                           # unchanged from F1
│   ├── Activation.php                       # unchanged
│   ├── Uninstaller.php                      # unchanged
│   └── Schema/                              # unchanged (3 table classes + interface)
└── tests/
    └── Unit/
        ├── SmokeTest.php                    # unchanged
        └── Crypto/                          # NEW
            ├── KeyPairTest.php
            ├── SignerTest.php
            ├── InMemoryNonceStoreTest.php
            └── VaultTest.php
```

**Why one file per public type?** Each class has one responsibility; tests pair 1:1 with the class under test. The Crypto namespace will grow when F4 adds higher-level orchestration; keeping each primitive in its own file means F4's additions don't bloat any existing file.

---

## Prerequisites

Same as F1. Specifically:

- F1 merged to main (verified — currently at commit `5546a9f`)
- Branch `f2-crypto` checked out (this plan assumes you're on it)
- PHP 8.5.5 on PATH at `/usr/local/bin/php` (libsodium bundled — `php -m | grep sodium` should show `sodium`)
- Composer 2.x at `/Users/pradeep/.local/bin/composer`
- `vendor/` populated from F1's `composer install`

Verify libsodium is available:

```bash
/usr/local/bin/php -r "var_dump(function_exists('sodium_crypto_sign_keypair'));"
```

Expected: `bool(true)`. If `false`, sodium isn't loaded — abort and fix PHP install.

---

## Tasks

### Task 1: Crypto namespace skeleton + Composer scripts

**Why first:** every later task needs the directory structure to exist and a quick way to run tests. Adds the `composer test` shortcut the F1 final review recommended.

**Files:**
- Create: `packages/dashboard-plugin/src/Crypto/.gitkeep` (placeholder so empty dir is tracked)
- Create: `packages/dashboard-plugin/tests/Unit/Crypto/.gitkeep`
- Modify: `packages/dashboard-plugin/composer.json` (add `scripts` section)

- [ ] **Step 1: Create the directories**

```bash
mkdir -p "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin/src/Crypto"
mkdir -p "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin/tests/Unit/Crypto"
touch "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin/src/Crypto/.gitkeep"
touch "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin/tests/Unit/Crypto/.gitkeep"
```

- [ ] **Step 2: Add a `scripts` section to composer.json**

Read the current file first:

```bash
cat "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin/composer.json"
```

Then edit it to add a `scripts` section after the `config` block. The complete new file should be:

```json
{
    "name": "defyn/dashboard-plugin",
    "description": "DefynWP — central dashboard plugin (backend brain).",
    "type": "wordpress-plugin",
    "license": "proprietary",
    "require": {
        "php": ">=7.4"
    },
    "require-dev": {
        "phpunit/phpunit": "^9.6",
        "wp-phpunit/wp-phpunit": "^6.4",
        "yoast/phpunit-polyfills": "^2.0",
        "johnpbloch/wordpress-core": "^6.4",
        "johnpbloch/wordpress-core-installer": "^2.0"
    },
    "autoload": {
        "psr-4": {
            "Defyn\\Dashboard\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Defyn\\Dashboard\\Tests\\": "tests/"
        }
    },
    "extra": {
        "wordpress-install-dir": "vendor/wordpress"
    },
    "config": {
        "allow-plugins": {
            "composer/installers": true,
            "johnpbloch/wordpress-core-installer": true
        },
        "sort-packages": true
    },
    "scripts": {
        "test": "phpunit",
        "test:unit": "phpunit --testsuite unit",
        "test:integration": "phpunit --testsuite integration"
    },
    "minimum-stability": "stable"
}
```

(Only change vs current file: added `"scripts": { "test": "phpunit", "test:unit": "phpunit --testsuite unit", "test:integration": "phpunit --testsuite integration" },` between `config` and `minimum-stability`.)

- [ ] **Step 3: Run `composer dump-autoload` to refresh** (no new packages, but the autoload regen never hurts)

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && /Users/pradeep/.local/bin/composer dump-autoload
```

Expected: `Generating autoload files` + a "0 ms" or similar timing line.

- [ ] **Step 4: Verify the new `composer test` shortcut works**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && /Users/pradeep/.local/bin/composer test 2>&1 | tail -8
```

Expected: still `OK (10 tests, 48 assertions)` — the F1 suite, now invokable via `composer test`.

- [ ] **Step 5: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git add packages/dashboard-plugin/composer.json packages/dashboard-plugin/src/Crypto/.gitkeep packages/dashboard-plugin/tests/Unit/Crypto/.gitkeep && git commit -m "$(cat <<'EOF'
F2: Crypto namespace skeleton + composer test scripts

Adds packages/dashboard-plugin/src/Crypto/ and tests/Unit/Crypto/ as
the homes for KeyPair, Signer, NonceStore, VerificationResult, and
Vault. Adds composer scripts (test, test:unit, test:integration) per
F1 final-review suggestion so contributors don't need to memorize
./vendor/bin/phpunit.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: TDD `KeyPair`

**Why:** the simplest crypto class — generates an Ed25519 keypair and exposes the two halves as base64 strings. Foundation for everything else.

**Files:**
- Create: `packages/dashboard-plugin/tests/Unit/Crypto/KeyPairTest.php`
- Create: `packages/dashboard-plugin/src/Crypto/KeyPair.php`

- [ ] **Step 1: Write the failing test**

Write `packages/dashboard-plugin/tests/Unit/Crypto/KeyPairTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Unit\Crypto;

use Defyn\Dashboard\Crypto\KeyPair;
use PHPUnit\Framework\TestCase;

final class KeyPairTest extends TestCase
{
    public function testGenerateProducesPublicAndPrivateKeys(): void
    {
        $pair = KeyPair::generate();

        self::assertNotEmpty($pair->publicKey);
        self::assertNotEmpty($pair->privateKey);
    }

    public function testKeysAreBase64Encoded(): void
    {
        $pair = KeyPair::generate();

        // base64_decode with strict=true returns false on invalid input.
        self::assertNotFalse(base64_decode($pair->publicKey, true), 'publicKey must be valid base64');
        self::assertNotFalse(base64_decode($pair->privateKey, true), 'privateKey must be valid base64');
    }

    public function testEd25519KeyLengthsAreCorrect(): void
    {
        $pair = KeyPair::generate();

        // Ed25519: 32-byte public key, 64-byte secret key (libsodium concatenates seed + public).
        self::assertSame(32, strlen(base64_decode($pair->publicKey, true)), 'Ed25519 public keys are 32 bytes');
        self::assertSame(64, strlen(base64_decode($pair->privateKey, true)), 'Ed25519 secret keys are 64 bytes (libsodium format)');
    }

    public function testEachCallProducesDifferentKeys(): void
    {
        $a = KeyPair::generate();
        $b = KeyPair::generate();

        self::assertNotSame($a->publicKey, $b->publicKey);
        self::assertNotSame($a->privateKey, $b->privateKey);
    }
}
```

- [ ] **Step 2: Run the test — verify it FAILS**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && /usr/local/bin/php ./vendor/bin/phpunit --testsuite unit --filter KeyPairTest 2>&1 | tail -10
```

Expected: 4 errors — `Class "Defyn\Dashboard\Crypto\KeyPair" not found`. Capture the failure output.

- [ ] **Step 3: Implement `KeyPair`**

Write `packages/dashboard-plugin/src/Crypto/KeyPair.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Crypto;

/**
 * Ed25519 keypair, base64-encoded for safe storage and transport.
 *
 * Use ::generate() to create a fresh pair. Both halves are exposed as
 * public properties — they're immutable by convention (the constructor
 * sets them once, and there's no setter).
 *
 * Sizes (after base64 decoding):
 *   - publicKey:  32 bytes
 *   - privateKey: 64 bytes (libsodium's "secret key" format = seed + public)
 */
final class KeyPair
{
    /** @var string base64-encoded 32-byte Ed25519 public key */
    public $publicKey;

    /** @var string base64-encoded 64-byte Ed25519 secret key (libsodium format) */
    public $privateKey;

    public function __construct(string $publicKey, string $privateKey)
    {
        $this->publicKey = $publicKey;
        $this->privateKey = $privateKey;
    }

    public static function generate(): self
    {
        $pair = sodium_crypto_sign_keypair();

        return new self(
            base64_encode(sodium_crypto_sign_publickey($pair)),
            base64_encode(sodium_crypto_sign_secretkey($pair))
        );
    }
}
```

> **PHP 7.4 compatibility note:** uses positional `new self($pub, $priv)` (no named args), explicit property declarations (no constructor promotion), and `@var` PHPDoc on properties (no `string` type before colon — typed properties without default would also work, but PHPDoc keeps the file uniform). All language features used here are 7.4-compatible.

- [ ] **Step 4: Run the test — verify it PASSES**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && /usr/local/bin/php ./vendor/bin/phpunit --testsuite unit --filter KeyPairTest 2>&1 | tail -10
```

Expected: `OK (4 tests, 8 assertions)`.

- [ ] **Step 5: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git add packages/dashboard-plugin/src/Crypto/KeyPair.php packages/dashboard-plugin/tests/Unit/Crypto/KeyPairTest.php && git commit -m "$(cat <<'EOF'
F2: TDD Crypto/KeyPair — Ed25519 keypair generation

KeyPair::generate() wraps sodium_crypto_sign_keypair and base64-encodes
both halves for safe storage in wp_defyn_sites columns. 32-byte public
key, 64-byte secret key per libsodium's Ed25519 format.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: TDD `Signer::canonical()` (the canonical string builder)

**Why:** The canonical string is the foundation of both signing and verification. Both sides MUST agree byte-for-byte. Building and testing it standalone — before any signing logic — pins the format spec exactly.

**Files:**
- Create: `packages/dashboard-plugin/tests/Unit/Crypto/SignerTest.php`
- Create: `packages/dashboard-plugin/src/Crypto/Signer.php`

- [ ] **Step 1: Write the failing test**

Write `packages/dashboard-plugin/tests/Unit/Crypto/SignerTest.php`:

```php
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
}
```

- [ ] **Step 2: Run the test — verify it FAILS**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && /usr/local/bin/php ./vendor/bin/phpunit --testsuite unit --filter SignerTest 2>&1 | tail -10
```

Expected: 4 errors — `Class "Defyn\Dashboard\Crypto\Signer" not found`. Capture the failure output.

- [ ] **Step 3: Create the minimal `Signer` with just `canonical()`**

Write `packages/dashboard-plugin/src/Crypto/Signer.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Crypto;

/**
 * Sign and verify HTTP requests using Ed25519 + a canonical string format.
 *
 * Canonical string per spec § 5.2:
 *   METHOD + "\n" + PATH + "\n" + TIMESTAMP + "\n" + NONCE + "\n" + sha256(BODY)
 *
 * Both sides MUST produce identical canonical strings — that's why this method
 * is public (so both signer and verifier call the exact same code, and tests
 * can pin the format byte-for-byte).
 */
final class Signer
{
    public static function canonical(
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $body
    ): string {
        return strtoupper($method) . "\n"
            . $path . "\n"
            . $timestamp . "\n"
            . $nonce . "\n"
            . hash('sha256', $body);
    }
}
```

- [ ] **Step 4: Run the test — verify it PASSES**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && /usr/local/bin/php ./vendor/bin/phpunit --testsuite unit --filter SignerTest 2>&1 | tail -10
```

Expected: `OK (4 tests, 6 assertions)`.

- [ ] **Step 5: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git add packages/dashboard-plugin/src/Crypto/Signer.php packages/dashboard-plugin/tests/Unit/Crypto/SignerTest.php && git commit -m "$(cat <<'EOF'
F2: TDD Signer::canonical — request canonicalization for sign/verify

Public so signer and verifier provably call the exact same code. Tests
pin the format byte-for-byte against spec § 5.2. METHOD is normalized
to uppercase; body is sha256'd not included raw.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: TDD `Signer::signRequest()` — produces the three headers

**Files:**
- Modify: `packages/dashboard-plugin/tests/Unit/Crypto/SignerTest.php` (add tests)
- Modify: `packages/dashboard-plugin/src/Crypto/Signer.php` (add constructor + signRequest)

- [ ] **Step 1: Add failing tests for `signRequest`**

Append these test methods to the existing `SignerTest` class. Insert them inside the class, after `testCanonicalIsDeterministic`. Don't replace the existing tests:

```php
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
```

- [ ] **Step 2: Run the new tests — verify they FAIL**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && /usr/local/bin/php ./vendor/bin/phpunit --testsuite unit --filter SignerTest 2>&1 | tail -15
```

Expected: 5 new errors — Signer has no constructor, no signRequest method. Capture the output.

- [ ] **Step 3: Implement `signRequest`**

Replace the existing `Signer.php` with:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Crypto;

use InvalidArgumentException;

/**
 * Sign and verify HTTP requests using Ed25519 + a canonical string format.
 *
 * Canonical string per spec § 5.2:
 *   METHOD + "\n" + PATH + "\n" + TIMESTAMP + "\n" + NONCE + "\n" + sha256(BODY)
 *
 * Both sides MUST produce identical canonical strings — that's why canonical()
 * is public (so both signer and verifier call the exact same code, and tests
 * can pin the format byte-for-byte).
 */
final class Signer
{
    /** @var string base64-encoded 64-byte Ed25519 secret key */
    private $privateKeyBase64;

    public function __construct(string $privateKeyBase64)
    {
        $raw = base64_decode($privateKeyBase64, true);
        if ($raw === false || strlen($raw) !== 64) {
            throw new InvalidArgumentException('Signer requires a base64-encoded 64-byte Ed25519 secret key.');
        }
        $this->privateKeyBase64 = $privateKeyBase64;
    }

    /**
     * @return array{X-Defyn-Timestamp: string, X-Defyn-Nonce: string, X-Defyn-Signature: string}
     */
    public function signRequest(string $method, string $path, string $body): array
    {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));  // 32-char hex nonce
        $canonical = self::canonical($method, $path, $timestamp, $nonce, $body);

        $signature = sodium_crypto_sign_detached($canonical, base64_decode($this->privateKeyBase64, true));

        return [
            'X-Defyn-Timestamp' => $timestamp,
            'X-Defyn-Nonce'     => $nonce,
            'X-Defyn-Signature' => base64_encode($signature),
        ];
    }

    public static function canonical(
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $body
    ): string {
        return strtoupper($method) . "\n"
            . $path . "\n"
            . $timestamp . "\n"
            . $nonce . "\n"
            . hash('sha256', $body);
    }
}
```

- [ ] **Step 4: Run the tests — verify they PASS**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && /usr/local/bin/php ./vendor/bin/phpunit --testsuite unit --filter SignerTest 2>&1 | tail -10
```

Expected: `OK (9 tests, 13 assertions)` — 4 canonical tests + 5 signRequest tests.

- [ ] **Step 5: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git add packages/dashboard-plugin/src/Crypto/Signer.php packages/dashboard-plugin/tests/Unit/Crypto/SignerTest.php && git commit -m "$(cat <<'EOF'
F2: TDD Signer::signRequest — produces the three X-Defyn-* headers

Constructor validates the private key is a base64-encoded 64-byte
Ed25519 secret. signRequest() generates a fresh timestamp + 16-byte
random nonce per call, builds the canonical string, signs it with
sodium_crypto_sign_detached, and returns the three headers.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: `NonceStore` interface + `InMemoryNonceStore` + `VerificationResult`

**Why:** verifyRequest needs (1) somewhere to remember nonces it's seen (for replay protection) and (2) a return-value vocabulary richer than bool. We define both before tackling verification logic.

**Files:**
- Create: `packages/dashboard-plugin/src/Crypto/NonceStore.php`
- Create: `packages/dashboard-plugin/src/Crypto/InMemoryNonceStore.php`
- Create: `packages/dashboard-plugin/src/Crypto/VerificationResult.php`
- Create: `packages/dashboard-plugin/tests/Unit/Crypto/InMemoryNonceStoreTest.php`

- [ ] **Step 1: Write the failing test for `InMemoryNonceStore`**

Write `packages/dashboard-plugin/tests/Unit/Crypto/InMemoryNonceStoreTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Unit\Crypto;

use Defyn\Dashboard\Crypto\InMemoryNonceStore;
use PHPUnit\Framework\TestCase;

final class InMemoryNonceStoreTest extends TestCase
{
    public function testFirstRememberReturnsTrueIndicatingFreshlyStored(): void
    {
        $store = new InMemoryNonceStore();
        self::assertTrue($store->remember('abc', 600), 'first remember should return true (newly stored)');
    }

    public function testSecondRememberOfSameNonceReturnsFalseIndicatingReplay(): void
    {
        $store = new InMemoryNonceStore();
        $store->remember('abc', 600);
        self::assertFalse($store->remember('abc', 600), 'second remember of same nonce should return false (replay)');
    }

    public function testDifferentNoncesAreIndependent(): void
    {
        $store = new InMemoryNonceStore();
        self::assertTrue($store->remember('abc', 600));
        self::assertTrue($store->remember('xyz', 600), 'different nonce should also return true');
    }
}
```

- [ ] **Step 2: Run — verify it FAILS**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && /usr/local/bin/php ./vendor/bin/phpunit --testsuite unit --filter InMemoryNonceStoreTest 2>&1 | tail -10
```

Expected: 3 errors — class not found.

- [ ] **Step 3: Define the `NonceStore` interface**

Write `packages/dashboard-plugin/src/Crypto/NonceStore.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Crypto;

/**
 * Store of recently-seen nonces, used for replay protection in Signer::verifyRequest.
 *
 * F2 ships InMemoryNonceStore (good for tests + dev). F4+ will add a
 * WP-transient-backed implementation when REST controllers wire signed
 * requests into the plugin.
 */
interface NonceStore
{
    /**
     * Atomically check-and-store a nonce.
     *
     * @return bool true if the nonce was newly stored (not a replay), false if
     *              it was already present (i.e. a replay attempt).
     */
    public function remember(string $nonce, int $ttlSeconds): bool;
}
```

- [ ] **Step 4: Implement `InMemoryNonceStore`**

Write `packages/dashboard-plugin/src/Crypto/InMemoryNonceStore.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Crypto;

/**
 * Process-local nonce store. Used by tests and any code path that doesn't
 * need cross-request replay protection.
 *
 * NOT suitable for production REST handling — those need WP-transient or
 * Redis backing so two PHP processes don't accept the same nonce twice.
 * F4 introduces a transient-backed implementation.
 */
final class InMemoryNonceStore implements NonceStore
{
    /** @var array<string, int>  nonce => unix-timestamp-when-it-expires */
    private $seen = [];

    public function remember(string $nonce, int $ttlSeconds): bool
    {
        $now = time();
        // Sweep expired entries lazily; cheap when the store is small.
        foreach ($this->seen as $existingNonce => $expiresAt) {
            if ($expiresAt <= $now) {
                unset($this->seen[$existingNonce]);
            }
        }

        if (isset($this->seen[$nonce])) {
            return false;  // replay
        }

        $this->seen[$nonce] = $now + $ttlSeconds;
        return true;
    }
}
```

- [ ] **Step 5: Define `VerificationResult` constants class**

Write `packages/dashboard-plugin/src/Crypto/VerificationResult.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Crypto;

/**
 * Outcomes from Signer::verifyRequest. String values rather than enum
 * for PHP 7.4 compatibility (enums require 8.1).
 *
 * Use the constants — never compare against the literal strings — so a
 * future refactor can change them without breaking call sites.
 */
final class VerificationResult
{
    public const VALID = 'valid';
    public const INVALID_SIGNATURE = 'invalid_signature';
    public const EXPIRED_TIMESTAMP = 'expired_timestamp';
    public const REPLAYED_NONCE = 'replayed_nonce';
    public const MISSING_HEADERS = 'missing_headers';

    private function __construct() {}  // never instantiated
}
```

- [ ] **Step 6: Run the test — verify it PASSES**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && /usr/local/bin/php ./vendor/bin/phpunit --testsuite unit --filter InMemoryNonceStoreTest 2>&1 | tail -10
```

Expected: `OK (3 tests, 4 assertions)`.

- [ ] **Step 7: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git add packages/dashboard-plugin/src/Crypto/NonceStore.php packages/dashboard-plugin/src/Crypto/InMemoryNonceStore.php packages/dashboard-plugin/src/Crypto/VerificationResult.php packages/dashboard-plugin/tests/Unit/Crypto/InMemoryNonceStoreTest.php && git commit -m "$(cat <<'EOF'
F2: NonceStore interface + InMemoryNonceStore + VerificationResult

NonceStore.remember() atomically check-and-stores a nonce — returns true
if newly stored (good), false if replay. InMemoryNonceStore is the
process-local impl used by tests and dev. F4 will add a transient-backed
implementation when REST handlers wire signed verification in.

VerificationResult is a final class with public string constants rather
than an enum to keep PHP 7.4 compatibility. A future PHP-version-bump
task can promote it to an enum.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: TDD `Signer::verifyRequest()` — happy path only

**Why:** small green step before tackling the four failure modes. Get the API shape right.

**Files:**
- Modify: `packages/dashboard-plugin/tests/Unit/Crypto/SignerTest.php` (add test)
- Modify: `packages/dashboard-plugin/src/Crypto/Signer.php` (add verifyRequest)

- [ ] **Step 1: Add the failing test**

Append this test to the existing `SignerTest` class:

```php
    public function testVerifyRequestReturnsValidForGoodSignedRequest(): void
    {
        $pair = \Defyn\Dashboard\Crypto\KeyPair::generate();
        $signer = new Signer($pair->privateKey);
        $store = new \Defyn\Dashboard\Crypto\InMemoryNonceStore();

        $headers = $signer->signRequest('POST', '/x', 'body');

        $result = Signer::verifyRequest(
            $pair->publicKey,
            'POST',
            '/x',
            'body',
            $headers,
            $store
        );

        self::assertSame(\Defyn\Dashboard\Crypto\VerificationResult::VALID, $result);
    }
```

- [ ] **Step 2: Run — verify it FAILS**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && /usr/local/bin/php ./vendor/bin/phpunit --testsuite unit --filter SignerTest 2>&1 | tail -10
```

Expected: 1 new error — `Signer::verifyRequest does not exist`.

- [ ] **Step 3: Add the minimal `verifyRequest` (returns VALID for a good signature only)**

Edit `packages/dashboard-plugin/src/Crypto/Signer.php`. Add the import + method to the existing class:

Add to the `use` block at the top:
```php
use InvalidArgumentException;
```
(Already present — verify it's there.)

Add this method to the `Signer` class, after `signRequest()`:

```php
    /**
     * Verify a signed request. Returns one of the VerificationResult constants.
     *
     * F2 implements only the happy path. Tasks 7 (this plan) extends with
     * MISSING_HEADERS, EXPIRED_TIMESTAMP, INVALID_SIGNATURE, REPLAYED_NONCE.
     *
     * @param array<string, string> $headers must contain X-Defyn-Timestamp,
     *                                       X-Defyn-Nonce, X-Defyn-Signature
     */
    public static function verifyRequest(
        string $publicKeyBase64,
        string $method,
        string $path,
        string $body,
        array $headers,
        NonceStore $nonceStore,
        int $maxAgeSeconds = 300,
        ?int $now = null
    ): string {
        $publicKey = base64_decode($publicKeyBase64, true);
        $signature = base64_decode($headers['X-Defyn-Signature'], true);
        $canonical = self::canonical(
            $method,
            $path,
            $headers['X-Defyn-Timestamp'],
            $headers['X-Defyn-Nonce'],
            $body
        );

        if (!sodium_crypto_sign_verify_detached($signature, $canonical, $publicKey)) {
            return VerificationResult::INVALID_SIGNATURE;
        }

        return VerificationResult::VALID;
    }
```

- [ ] **Step 4: Run the test — verify it PASSES**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && /usr/local/bin/php ./vendor/bin/phpunit --testsuite unit --filter SignerTest 2>&1 | tail -10
```

Expected: `OK (10 tests, 14 assertions)`.

- [ ] **Step 5: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git add packages/dashboard-plugin/src/Crypto/Signer.php packages/dashboard-plugin/tests/Unit/Crypto/SignerTest.php && git commit -m "$(cat <<'EOF'
F2: TDD Signer::verifyRequest — happy path skeleton

Returns VALID for a correctly-signed request. Header validation,
timestamp window, and replay rejection added in the next task.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: TDD `verifyRequest` — full validation (4 failure modes)

**Why:** Now we add header validation, timestamp window, replay rejection. Each failure mode gets a dedicated test with a specific scenario.

**Files:**
- Modify: `packages/dashboard-plugin/tests/Unit/Crypto/SignerTest.php`
- Modify: `packages/dashboard-plugin/src/Crypto/Signer.php`

- [ ] **Step 1: Add failing tests for the 4 failure modes**

Append these test methods to `SignerTest`:

```php
    public function testVerifyRequestReturnsMissingHeadersWhenTimestampIsAbsent(): void
    {
        $pair = \Defyn\Dashboard\Crypto\KeyPair::generate();
        $signer = new Signer($pair->privateKey);
        $store = new \Defyn\Dashboard\Crypto\InMemoryNonceStore();
        $headers = $signer->signRequest('GET', '/x', '');
        unset($headers['X-Defyn-Timestamp']);

        $result = Signer::verifyRequest($pair->publicKey, 'GET', '/x', '', $headers, $store);

        self::assertSame(\Defyn\Dashboard\Crypto\VerificationResult::MISSING_HEADERS, $result);
    }

    public function testVerifyRequestReturnsMissingHeadersWhenNonceIsAbsent(): void
    {
        $pair = \Defyn\Dashboard\Crypto\KeyPair::generate();
        $signer = new Signer($pair->privateKey);
        $store = new \Defyn\Dashboard\Crypto\InMemoryNonceStore();
        $headers = $signer->signRequest('GET', '/x', '');
        unset($headers['X-Defyn-Nonce']);

        $result = Signer::verifyRequest($pair->publicKey, 'GET', '/x', '', $headers, $store);

        self::assertSame(\Defyn\Dashboard\Crypto\VerificationResult::MISSING_HEADERS, $result);
    }

    public function testVerifyRequestReturnsMissingHeadersWhenSignatureIsAbsent(): void
    {
        $pair = \Defyn\Dashboard\Crypto\KeyPair::generate();
        $signer = new Signer($pair->privateKey);
        $store = new \Defyn\Dashboard\Crypto\InMemoryNonceStore();
        $headers = $signer->signRequest('GET', '/x', '');
        unset($headers['X-Defyn-Signature']);

        $result = Signer::verifyRequest($pair->publicKey, 'GET', '/x', '', $headers, $store);

        self::assertSame(\Defyn\Dashboard\Crypto\VerificationResult::MISSING_HEADERS, $result);
    }

    public function testVerifyRequestReturnsExpiredTimestampWhenTooOld(): void
    {
        $pair = \Defyn\Dashboard\Crypto\KeyPair::generate();
        $signer = new Signer($pair->privateKey);
        $store = new \Defyn\Dashboard\Crypto\InMemoryNonceStore();

        // Sign at a fixed timestamp 1 hour in the past relative to "now".
        $headers = $signer->signRequest('GET', '/x', '');
        $now = time() + 3700;  // verifier sees "now" as 1h+ after signing

        $result = Signer::verifyRequest($pair->publicKey, 'GET', '/x', '', $headers, $store, 300, $now);

        self::assertSame(\Defyn\Dashboard\Crypto\VerificationResult::EXPIRED_TIMESTAMP, $result);
    }

    public function testVerifyRequestReturnsExpiredTimestampWhenTooFarInFuture(): void
    {
        $pair = \Defyn\Dashboard\Crypto\KeyPair::generate();
        $signer = new Signer($pair->privateKey);
        $store = new \Defyn\Dashboard\Crypto\InMemoryNonceStore();

        $headers = $signer->signRequest('GET', '/x', '');
        $now = time() - 3700;  // verifier's clock is 1h+ behind signer

        $result = Signer::verifyRequest($pair->publicKey, 'GET', '/x', '', $headers, $store, 300, $now);

        self::assertSame(\Defyn\Dashboard\Crypto\VerificationResult::EXPIRED_TIMESTAMP, $result);
    }

    public function testVerifyRequestReturnsInvalidSignatureWhenBodyTampered(): void
    {
        $pair = \Defyn\Dashboard\Crypto\KeyPair::generate();
        $signer = new Signer($pair->privateKey);
        $store = new \Defyn\Dashboard\Crypto\InMemoryNonceStore();

        $headers = $signer->signRequest('POST', '/x', '{"a":1}');

        $result = Signer::verifyRequest(
            $pair->publicKey,
            'POST',
            '/x',
            '{"a":2}',  // body changed after signing
            $headers,
            $store
        );

        self::assertSame(\Defyn\Dashboard\Crypto\VerificationResult::INVALID_SIGNATURE, $result);
    }

    public function testVerifyRequestReturnsInvalidSignatureWhenSignatureTampered(): void
    {
        $pair = \Defyn\Dashboard\Crypto\KeyPair::generate();
        $signer = new Signer($pair->privateKey);
        $store = new \Defyn\Dashboard\Crypto\InMemoryNonceStore();

        $headers = $signer->signRequest('GET', '/x', '');
        // Flip the last base64 character in a deterministic way: "A" → "B" or "B" → "A".
        $sig = $headers['X-Defyn-Signature'];
        $last = substr($sig, -1);
        $headers['X-Defyn-Signature'] = substr($sig, 0, -1) . ($last === 'A' ? 'B' : 'A');

        $result = Signer::verifyRequest($pair->publicKey, 'GET', '/x', '', $headers, $store);

        self::assertSame(\Defyn\Dashboard\Crypto\VerificationResult::INVALID_SIGNATURE, $result);
    }

    public function testVerifyRequestReturnsReplayedNonceOnSecondVerification(): void
    {
        $pair = \Defyn\Dashboard\Crypto\KeyPair::generate();
        $signer = new Signer($pair->privateKey);
        $store = new \Defyn\Dashboard\Crypto\InMemoryNonceStore();

        $headers = $signer->signRequest('GET', '/x', '');

        $first = Signer::verifyRequest($pair->publicKey, 'GET', '/x', '', $headers, $store);
        self::assertSame(\Defyn\Dashboard\Crypto\VerificationResult::VALID, $first, 'first verify should be VALID');

        $second = Signer::verifyRequest($pair->publicKey, 'GET', '/x', '', $headers, $store);
        self::assertSame(\Defyn\Dashboard\Crypto\VerificationResult::REPLAYED_NONCE, $second, 'replay must be detected');
    }
```

- [ ] **Step 2: Run — verify the new tests FAIL**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && /usr/local/bin/php ./vendor/bin/phpunit --testsuite unit --filter SignerTest 2>&1 | tail -20
```

Expected: 7-8 failures (the missing-header tests will fail with PHP undefined-index notices that PHPUnit promotes to errors via `failOnWarning="true"`; tampering tests may "pass" by accident if signature comparison happens before nonce storage; expired and replay tests will fail). Capture the output.

- [ ] **Step 3: Implement the full validation logic**

Replace `Signer::verifyRequest` (only that method, leave constructor + signRequest + canonical alone) with:

```php
    /**
     * Verify a signed request. Returns one of the VerificationResult constants.
     *
     * Order of checks (each cheap rejects before expensive ones):
     *   1. All three headers present
     *   2. Timestamp within ±$maxAgeSeconds of $now
     *   3. Signature valid against canonical string
     *   4. Nonce not previously seen (and store it now if not)
     *
     * @param array<string, string> $headers must contain X-Defyn-Timestamp,
     *                                       X-Defyn-Nonce, X-Defyn-Signature
     * @param int|null $now overrideable for tests; defaults to time()
     */
    public static function verifyRequest(
        string $publicKeyBase64,
        string $method,
        string $path,
        string $body,
        array $headers,
        NonceStore $nonceStore,
        int $maxAgeSeconds = 300,
        ?int $now = null
    ): string {
        if (!isset($headers['X-Defyn-Timestamp'], $headers['X-Defyn-Nonce'], $headers['X-Defyn-Signature'])) {
            return VerificationResult::MISSING_HEADERS;
        }

        $timestamp = $headers['X-Defyn-Timestamp'];
        $nonce     = $headers['X-Defyn-Nonce'];
        $sigB64    = $headers['X-Defyn-Signature'];

        $now = $now ?? time();
        $age = abs($now - (int) $timestamp);
        if ($age > $maxAgeSeconds) {
            return VerificationResult::EXPIRED_TIMESTAMP;
        }

        $publicKey = base64_decode($publicKeyBase64, true);
        $signature = base64_decode($sigB64, true);
        if ($publicKey === false || $signature === false) {
            return VerificationResult::INVALID_SIGNATURE;
        }

        $canonical = self::canonical($method, $path, $timestamp, $nonce, $body);
        if (!sodium_crypto_sign_verify_detached($signature, $canonical, $publicKey)) {
            return VerificationResult::INVALID_SIGNATURE;
        }

        // Signature is valid. Now check (and atomically store) the nonce so a
        // genuine signed request can't be replayed by an attacker who captured it.
        // TTL = 2 × maxAgeSeconds gives buffer for clock skew while keeping the
        // store bounded in size.
        if (!$nonceStore->remember($nonce, $maxAgeSeconds * 2)) {
            return VerificationResult::REPLAYED_NONCE;
        }

        return VerificationResult::VALID;
    }
```

- [ ] **Step 4: Run all Signer tests — verify GREEN**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && /usr/local/bin/php ./vendor/bin/phpunit --testsuite unit --filter SignerTest 2>&1 | tail -10
```

Expected: `OK (18 tests, 23 assertions)` — 4 canonical + 5 signRequest + 1 happy-path + 8 failure-mode = 18 tests.

- [ ] **Step 5: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git add packages/dashboard-plugin/src/Crypto/Signer.php packages/dashboard-plugin/tests/Unit/Crypto/SignerTest.php && git commit -m "$(cat <<'EOF'
F2: TDD Signer::verifyRequest — full validation (4 failure modes)

Adds MISSING_HEADERS (any of 3 absent), EXPIRED_TIMESTAMP (±300s
default window, configurable + overrideable now-clock for tests),
INVALID_SIGNATURE (body tamper, signature tamper, wrong-key implicit),
and REPLAYED_NONCE (nonce store atomically check-and-stores).

Order matters: cheap rejects first (headers → timestamp → signature →
nonce). Nonce only stored AFTER signature is valid — prevents an
attacker from polluting the store with garbage nonces.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 8: TDD `Vault` — generate key + encrypt/decrypt round-trip

**Why:** The Vault wraps libsodium's authenticated symmetric encryption. F4 will use it to encrypt per-site dashboard private keys before storing in `wp_defyn_sites.our_private_key`.

**Files:**
- Create: `packages/dashboard-plugin/tests/Unit/Crypto/VaultTest.php`
- Create: `packages/dashboard-plugin/src/Crypto/Vault.php`

- [ ] **Step 1: Write the failing tests for round-trip**

Write `packages/dashboard-plugin/tests/Unit/Crypto/VaultTest.php`:

```php
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
}
```

- [ ] **Step 2: Run — verify FAIL**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && /usr/local/bin/php ./vendor/bin/phpunit --testsuite unit --filter VaultTest 2>&1 | tail -10
```

Expected: 4 errors — class not found.

- [ ] **Step 3: Implement `Vault`**

Write `packages/dashboard-plugin/src/Crypto/Vault.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Crypto;

use InvalidArgumentException;
use RuntimeException;

/**
 * Authenticated symmetric encryption for at-rest secrets (e.g. per-site
 * dashboard private keys stored in wp_defyn_sites.our_private_key).
 *
 * Uses sodium_crypto_secretbox (XSalsa20 + Poly1305 MAC), not raw AES-256.
 * Spec § 4.3 says "AES-256"; we use sodium because:
 *   - Equivalent security (256-bit confidentiality + 128-bit MAC)
 *   - Authenticated by default (catches tampering automatically)
 *   - Already a libsodium project (Signer uses Ed25519 from same library)
 *   - Smaller misuse surface than OpenSSL's AES-256-GCM
 *
 * Envelope format:  base64( nonce || ciphertext )
 *   - nonce: 24 random bytes per encrypt
 *   - ciphertext: includes the 16-byte Poly1305 MAC at the start (sodium handles)
 */
final class Vault
{
    private const NONCE_BYTES = 24;  // SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
    private const KEY_BYTES   = 32;  // SODIUM_CRYPTO_SECRETBOX_KEYBYTES

    /** @var string raw 32-byte key */
    private $key;

    public function __construct(string $keyBase64)
    {
        $raw = base64_decode($keyBase64, true);
        if ($raw === false || strlen($raw) !== self::KEY_BYTES) {
            throw new InvalidArgumentException(
                'Vault requires a base64-encoded ' . self::KEY_BYTES . '-byte key.'
            );
        }
        $this->key = $raw;
    }

    /** Returns a base64-encoded fresh 32-byte key suitable for the constructor. */
    public static function generateKey(): string
    {
        return base64_encode(sodium_crypto_secretbox_keygen());
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(self::NONCE_BYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return base64_encode($nonce . $ciphertext);
    }

    public function decrypt(string $envelopeBase64): string
    {
        $bytes = base64_decode($envelopeBase64, true);
        if ($bytes === false) {
            throw new RuntimeException('Vault envelope is not valid base64.');
        }
        if (strlen($bytes) < self::NONCE_BYTES + 1) {
            throw new RuntimeException('Vault envelope is too short to contain a nonce + ciphertext.');
        }

        $nonce = substr($bytes, 0, self::NONCE_BYTES);
        $ciphertext = substr($bytes, self::NONCE_BYTES);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);
        if ($plaintext === false) {
            // Either tampered ciphertext, or wrong key. We can't tell which from sodium's API.
            throw new RuntimeException('Vault decryption failed (tampered ciphertext or wrong key).');
        }

        return $plaintext;
    }
}
```

- [ ] **Step 4: Run — verify GREEN**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && /usr/local/bin/php ./vendor/bin/phpunit --testsuite unit --filter VaultTest 2>&1 | tail -10
```

Expected: `OK (4 tests, 5 assertions)`.

- [ ] **Step 5: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git add packages/dashboard-plugin/src/Crypto/Vault.php packages/dashboard-plugin/tests/Unit/Crypto/VaultTest.php && git commit -m "$(cat <<'EOF'
F2: TDD Vault — authenticated symmetric encryption (round-trip)

Wraps sodium_crypto_secretbox (XSalsa20-Poly1305). Envelope format is
base64(nonce || ciphertext). Random 24-byte nonce per call gives
ciphertext indistinguishability.

Spec § 4.3 says AES-256; we deviated to sodium for equivalent security
in fewer lines and a harder-to-misuse API. Documented in Vault's
docblock.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 9: TDD `Vault` — tamper detection, wrong-key rejection, input validation

**Why:** The round-trip test only proves happy-path. Authenticated encryption's whole point is that it FAILS LOUDLY on tampering — pin that.

**Files:**
- Modify: `packages/dashboard-plugin/tests/Unit/Crypto/VaultTest.php`

- [ ] **Step 1: Append failing tests for the failure modes**

Add these methods to the existing `VaultTest` class:

```php
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

        // Anything shorter than NONCE_BYTES (24) + 1 should fail the length check.
        $vault->decrypt(base64_encode(str_repeat('a', 10)));
    }
```

- [ ] **Step 2: Run — verify the new tests PASS** (the implementation already handles all these cases per Task 8's `Vault.php`)

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && /usr/local/bin/php ./vendor/bin/phpunit --testsuite unit --filter VaultTest 2>&1 | tail -10
```

Expected: `OK (10 tests, 5 assertions)` — 4 round-trip + 6 failure-mode (the failure-mode tests use `expectException` which doesn't bump the assertion count the same way).

If any of the failure-mode tests fail unexpectedly, the implementation in Task 8 is missing a guard — fix it there and re-run.

- [ ] **Step 3: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git add packages/dashboard-plugin/tests/Unit/Crypto/VaultTest.php && git commit -m "$(cat <<'EOF'
F2: TDD Vault — tamper, wrong-key, malformed-input rejection

Pins the exception contract: tampered ciphertext, wrong key, malformed
base64 (key or envelope), and too-short envelope all throw with clear
messages. The whole point of authenticated encryption is to FAIL LOUDLY
on tampering — these tests prove that's what happens.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 10: F2 acceptance — full suite + tag

**Why:** prove every F2 test added does what the plan promised, prove no F1 tests regressed, then tag the phase complete.

**Files:** none (acceptance + tag only)

- [ ] **Step 1: Run the full suite**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && /usr/local/bin/php ./vendor/bin/phpunit 2>&1 | tail -8
```

Expected: roughly `OK (35 tests, 71 assertions)`:
- F1 leftovers: 1 SmokeTest unit + 9 integration = 10 tests, 48 assertions
- F2 additions: 4 KeyPair + 18 Signer + 3 InMemoryNonceStore + 10 Vault = 25 tests, ~23 assertions
- Total: ~35 tests, ~71 assertions

The exact assertion count may differ by 1-2 — that's fine. The important thing is `OK` and zero failures/errors.

- [ ] **Step 2: Tag F2 complete**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git tag -a f2-crypto-complete -m "F2: Crypto primitives complete — KeyPair, Signer (sign + verify with replay protection), Vault (authenticated symmetric encryption). All unit-tested, no HTTP, no WordPress hooks. Foundation for F4 (handshake) and F6 (signed status/heartbeat)."
```

- [ ] **Step 3: Verify the tag**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git tag --list "f*" && git log --oneline f2-crypto-complete | head -10
```

Expected: both `f1-scaffolding-complete` and `f2-crypto-complete` listed; the F2 tag points at the latest commit on the branch.

---

## F2 Verification Checklist (Definition of Done)

Before merging F2 into main, verify all of these:

- [ ] `KeyPair::generate()` produces an Ed25519 keypair with correct base64-decoded sizes (32 + 64)
- [ ] `Signer::canonical()` produces the exact spec § 5.2 format (METHOD\nPATH\nTS\nNONCE\nsha256(BODY))
- [ ] `Signer::signRequest()` produces all 3 X-Defyn-* headers with valid Ed25519 signature
- [ ] `Signer::verifyRequest()` returns `VALID` for a fresh, well-signed request
- [ ] `Signer::verifyRequest()` rejects: missing headers, expired/future timestamps, body or signature tampering, replayed nonces
- [ ] `Vault::encrypt()`/`decrypt()` round-trip preserves plaintext
- [ ] `Vault::decrypt()` throws on tampered ciphertext, wrong key, malformed envelope
- [ ] Full PHPUnit suite passes (~35 tests)
- [ ] Tag `f2-crypto-complete` exists pointing at the final F2 commit

When all boxes are checked, F2 is done. Invoke `superpowers:finishing-a-development-branch` to merge to main, then re-invoke `superpowers:writing-plans` for F3 (auth + login REST + SPA scaffold).

---

## Notes for F3 (forward-looking)

- F3 will create the first WP-aware code that USES F2 — specifically, JWT signing for the dashboard's own auth REST. Different cipher (HS256 / RS256) but same hygiene principles (base64-encoded keys, time-window validation, etc.). F2's clean abstractions should make F3's integration painless.
- A WP-transient-backed `NonceStore` implementation will be added in F4 (when REST handlers actually need cross-process replay protection). Today's `InMemoryNonceStore` is fine for unit tests and will remain so.
- If we eventually bump PHP to 8.1+, `VerificationResult` should be promoted from a constants class to a real backed enum.
- Vault is currently single-key. When key rotation becomes a concern (probably never in this product, but worth noting), the envelope format would need a key-id prefix. For now, one key per environment via `.env`, keep it simple.
