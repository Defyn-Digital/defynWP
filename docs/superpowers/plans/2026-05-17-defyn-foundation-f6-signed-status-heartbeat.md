# F6 — Signed `/status` + `/heartbeat` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wire mutual-authenticated, signed inbound (connector) and outbound (dashboard) REST calls so the dashboard can pull a managed site's `/status` snapshot and `/heartbeat` liveness ping. Land the three small F5 carry-forwards first so this phase builds on a clean surface.

**Architecture:** Connector exposes three new authenticated endpoints (`GET /status`, `GET /heartbeat`, `POST /disconnect`) protected by a `VerifySignatureMiddleware` that enforces spec § 5.2 canonical-string Ed25519 verification (timestamp window ±300s, nonce replay via WP transients). Dashboard upgrades the F5 `SignedHttpClient` placeholder to actually sign outbound calls using each site's decrypted `our_private_key`, then orchestrates calls through two new services (`SyncService`, `HealthService`) invoked by two new Action Scheduler jobs (`defyn_sync_site`, `defyn_health_ping`) wired to two new REST endpoints (`POST /sites/{id}/sync`, `POST /sites/{id}/ping`).

**Tech Stack:** PHP 8.1+, libsodium (`sodium_crypto_sign_*`), WP REST API + transients, Symfony http-client 6.4 LTS, Action Scheduler 3.7+. SPA is untouched in F6 (next phase wires sync/ping buttons).

**Spec source:** `docs/superpowers/specs/2026-04-18-defyn-foundation-design.md` — primarily § 5 (connector REST endpoints), § 5.2 (request signing protocol), § 6.3 (Sync + Health services), § 8.2 (mutual-authentication security properties), § 9.1 (error envelope), § 11 (signed `/status` + `/heartbeat` deliverable).

**Branch:** Off main as `f6-signed-status-heartbeat`. Last shipped commit `426b23f` (F5 merge).

---

### Task 1: F5 carry-forward — wrap `Signer::sign()` in `ConnectController`

**Why:** F5 carry-forward 2. Corrupt `site_private_key` (rare; manual DB edit) makes `Defyn\Connector\Crypto\Signer::sign()` throw uncaught `InvalidArgumentException`, returning a generic WP 500 rather than the spec § 9.1 envelope.

**Files:**
- Modify: `packages/connector-plugin/src/Rest/ConnectController.php`
- Test: `packages/connector-plugin/tests/Integration/Rest/ConnectControllerSigningFailureTest.php` (NEW)

- [ ] **Step 1: Write the failing test**

Create `packages/connector-plugin/tests/Integration/Rest/ConnectControllerSigningFailureTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Rest;

use Defyn\Connector\Storage\ConnectorState;
use Defyn\Connector\Tests\Integration\RestTestCase;

final class ConnectControllerSigningFailureTest extends RestTestCase
{
    public function test_returns_signing_failed_envelope_when_private_key_corrupt(): void
    {
        $state = new ConnectorState();
        $state->update([
            'state'             => 'awaiting-handshake',
            'connection_code'   => 'ABCDEFGHJKMN',
            'site_nonce'        => str_repeat('a', 64),
            'code_expires_at'   => time() + 60,
            'site_private_key'  => 'not-a-valid-base64-key',
            'site_public_key'   => base64_encode(random_bytes(32)),
        ]);

        $response = $this->postJson('/defyn-connector/v1/connect', [
            'code'                 => 'ABCDEFGHJKMN',
            'dashboard_public_key' => base64_encode(random_bytes(32)),
            'callback_challenge'   => base64_encode(random_bytes(32)),
        ]);

        $this->assertSame(500, $response['status']);
        $this->assertSame('connector.signing_failed', $response['body']['error']['code']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd packages/connector-plugin && vendor/bin/phpunit --filter ConnectControllerSigningFailureTest`
Expected: FAIL (test throws `InvalidArgumentException` instead of returning 500 envelope).

- [ ] **Step 3: Wrap the Signer::sign call in ConnectController**

In `packages/connector-plugin/src/Rest/ConnectController.php`, locate the `Signer::sign(...)` call (currently bare) and replace with:

```php
try {
    $signature = Signer::sign($challengeB64, $privateKeyBase64);
} catch (\InvalidArgumentException $e) {
    return ErrorResponse::create(
        500,
        'connector.signing_failed',
        'Site keypair is corrupted; reset the connector and re-handshake.'
    );
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd packages/connector-plugin && vendor/bin/phpunit --filter ConnectControllerSigningFailureTest`
Expected: PASS

- [ ] **Step 5: Run full connector suite to verify no regression**

Run: `cd packages/connector-plugin && vendor/bin/phpunit`
Expected: All existing tests pass.

- [ ] **Step 6: Commit**

```bash
git add packages/connector-plugin/src/Rest/ConnectController.php \
        packages/connector-plugin/tests/Integration/Rest/ConnectControllerSigningFailureTest.php
git commit -m "F6: connector — wrap Signer::sign in ConnectController, return signing_failed envelope (F5 carry-forward 2)"
```

---

### Task 2: F5 carry-forward — 12-char code length check in `SitesCreateController`

**Why:** F5 carry-forward 3. SPA `createSiteSchema.code` enforces 12 chars, but server only checks `code === ''`. A non-empty but wrong-length code currently bypasses validation and reaches Action Scheduler.

**Files:**
- Modify: `packages/dashboard-plugin/src/Rest/SitesCreateController.php`
- Test: `packages/dashboard-plugin/tests/Integration/Rest/SitesCreateCodeLengthTest.php` (NEW)

- [ ] **Step 1: Write the failing test**

Create `packages/dashboard-plugin/tests/Integration/Rest/SitesCreateCodeLengthTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Tests\Integration\AuthenticatedRestTestCase;

final class SitesCreateCodeLengthTest extends AuthenticatedRestTestCase
{
    public function test_rejects_short_code_with_invalid_code_envelope(): void
    {
        $response = $this->postJsonAuthenticated('/defyn/v1/sites', [
            'url'   => 'https://example.com',
            'label' => 'Example',
            'code'  => 'ABC123',  // 6 chars, not 12
        ]);

        $this->assertSame(400, $response['status']);
        $this->assertSame('sites.invalid_code', $response['body']['error']['code']);
    }

    public function test_rejects_long_code_with_invalid_code_envelope(): void
    {
        $response = $this->postJsonAuthenticated('/defyn/v1/sites', [
            'url'   => 'https://example.com',
            'label' => 'Example',
            'code'  => 'ABCDEFGHJKMNPQRSTV',  // 18 chars
        ]);

        $this->assertSame(400, $response['status']);
        $this->assertSame('sites.invalid_code', $response['body']['error']['code']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit --filter SitesCreateCodeLengthTest`
Expected: FAIL (`sites.invalid_code` code does not exist yet; a different envelope is returned).

- [ ] **Step 3: Add length guard in SitesCreateController**

In `packages/dashboard-plugin/src/Rest/SitesCreateController.php`, locate:

```php
if ($url === '' || $code === '') {
    return ErrorResponse::create(400, 'sites.missing_fields', 'Fields url and code are required.');
}
```

Add immediately AFTER it (before the UrlValidator block):

```php
if (strlen($code) !== 12) {
    return ErrorResponse::create(400, 'sites.invalid_code', 'Connection code must be 12 characters.');
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit --filter SitesCreateCodeLengthTest`
Expected: PASS (both length variants).

- [ ] **Step 5: Run full dashboard suite to verify no regression**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit`
Expected: All existing tests pass. If any existing test was passing a short/long code, fix the fixture, not the controller.

- [ ] **Step 6: Commit**

```bash
git add packages/dashboard-plugin/src/Rest/SitesCreateController.php \
        packages/dashboard-plugin/tests/Integration/Rest/SitesCreateCodeLengthTest.php
git commit -m "F6: dashboard — enforce 12-char connection code length in SitesCreateController (F5 carry-forward 3)"
```

---

### Task 3: F5 carry-forward — `connected` render branch in connector `SettingsPage`

**Why:** F5 carry-forward 1. Currently when `state=connected` the page falls through to the default "Generate Connection Code" form. Clicking Generate would silently overwrite the active connection — the most dangerous side effect of F5's omission. Add a read-only display + a separate Disconnect button. (Note: the signed `POST /disconnect` REST endpoint lands in Task 10; this UI affordance reuses the existing local admin-post reset hook for now, which is honest about being a local-only force-reset and not a remote signed disconnect.)

**Files:**
- Modify: `packages/connector-plugin/src/Admin/SettingsPage.php`
- Test: `packages/connector-plugin/tests/Integration/Admin/SettingsPageConnectedRenderTest.php` (NEW)

- [ ] **Step 1: Write the failing test**

Create `packages/connector-plugin/tests/Integration/Admin/SettingsPageConnectedRenderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Admin;

use Defyn\Connector\Admin\SettingsPage;
use Defyn\Connector\Storage\ConnectorState;
use WP_UnitTestCase;

final class SettingsPageConnectedRenderTest extends WP_UnitTestCase
{
    public function test_connected_branch_shows_dashboard_pubkey_and_timestamp_not_generate_form(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $dashboardPubKey = base64_encode(str_repeat('A', 32));
        $connectedAt     = '2026-05-17 12:00:00';

        (new ConnectorState())->update([
            'state'                => 'connected',
            'dashboard_public_key' => $dashboardPubKey,
            'connected_at'         => $connectedAt,
            'site_public_key'      => base64_encode(random_bytes(32)),
        ]);

        ob_start();
        (new SettingsPage())->render();
        $html = ob_get_clean();

        // Must include dashboard pubkey fingerprint (first 12 chars of base64)
        $this->assertStringContainsString(substr($dashboardPubKey, 0, 12), $html);
        // Must include the handshake timestamp
        $this->assertStringContainsString($connectedAt, $html);
        // Must include a Disconnect button labeled clearly
        $this->assertStringContainsString('Disconnect', $html);
        // Must NOT show the Generate form (which would clobber the connection)
        $this->assertStringNotContainsString('Generate Connection Code', $html);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd packages/connector-plugin && vendor/bin/phpunit --filter SettingsPageConnectedRenderTest`
Expected: FAIL — assertion fails because "Generate Connection Code" IS present (falls through to default branch).

- [ ] **Step 3: Add `connected` branch in `SettingsPage::render()`**

In `packages/connector-plugin/src/Admin/SettingsPage.php`, after the `code-consumed` branch and BEFORE the default unconfigured form, add:

```php
if ($current === 'connected') {
    $dashboardPubKey = (string) $state->get('dashboard_public_key', '');
    $connectedAt     = (string) $state->get('connected_at', '');
    $fingerprint     = $dashboardPubKey === '' ? '' : substr($dashboardPubKey, 0, 12) . '…';

    echo '<p>' . esc_html__('This site is connected to a DefynWP dashboard.', 'defyn-connector') . '</p>';
    echo '<table class="form-table"><tbody>';
    echo '<tr><th>' . esc_html__('Dashboard key fingerprint', 'defyn-connector') . '</th>';
    echo '<td><code>' . esc_html($fingerprint) . '</code></td></tr>';
    echo '<tr><th>' . esc_html__('Connected at', 'defyn-connector') . '</th>';
    echo '<td>' . esc_html($connectedAt) . '</td></tr>';
    echo '</tbody></table>';

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" '
        . 'onsubmit="return confirm(\'' . esc_js(__('Disconnect this site from the dashboard? You will need a new connection code to reconnect.', 'defyn-connector')) . '\');">';
    echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_RESET) . '">';
    wp_nonce_field(self::NONCE_RESET);
    echo '<p><button type="submit" class="button button-secondary">' . esc_html__('Disconnect', 'defyn-connector') . '</button></p>';
    echo '</form>';
    echo '</div>';
    return;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd packages/connector-plugin && vendor/bin/phpunit --filter SettingsPageConnectedRenderTest`
Expected: PASS

- [ ] **Step 5: Run full connector suite**

Run: `cd packages/connector-plugin && vendor/bin/phpunit`
Expected: All tests pass.

- [ ] **Step 6: Commit**

```bash
git add packages/connector-plugin/src/Admin/SettingsPage.php \
        packages/connector-plugin/tests/Integration/Admin/SettingsPageConnectedRenderTest.php
git commit -m "F6: connector — add connected render branch to SettingsPage (F5 carry-forward 1)"
```

---

### Task 4: Connector `NonceStore` (WP transient backed)

**Why:** Connector needs its own replay-protection store mirroring `Defyn\Dashboard\Crypto\NonceStore` from F2. Per established F4/F5 pattern, the two plugins duplicate crypto intentionally. WP transients survive across PHP-FPM workers — the right backing store for production.

**Files:**
- Create: `packages/connector-plugin/src/Crypto/NonceStore.php`
- Create: `packages/connector-plugin/src/Crypto/TransientNonceStore.php`
- Test: `packages/connector-plugin/tests/Unit/Crypto/NonceStoreTest.php` (NEW)

- [ ] **Step 1: Write the failing test**

Create `packages/connector-plugin/tests/Unit/Crypto/NonceStoreTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Unit\Crypto;

use Defyn\Connector\Crypto\TransientNonceStore;
use WP_UnitTestCase;

final class NonceStoreTest extends WP_UnitTestCase
{
    public function test_first_remember_returns_true(): void
    {
        $store = new TransientNonceStore();
        $this->assertTrue($store->remember('abc123', 60));
    }

    public function test_second_remember_of_same_nonce_returns_false(): void
    {
        $store = new TransientNonceStore();
        $store->remember('abc123', 60);
        $this->assertFalse($store->remember('abc123', 60));
    }

    public function test_different_nonces_dont_collide(): void
    {
        $store = new TransientNonceStore();
        $this->assertTrue($store->remember('abc123', 60));
        $this->assertTrue($store->remember('def456', 60));
    }

    public function test_keys_are_prefixed_to_avoid_collisions(): void
    {
        $store = new TransientNonceStore();
        $store->remember('payload', 60);
        // The raw "payload" string must NOT be usable as a transient key directly
        $this->assertFalse(get_transient('payload'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd packages/connector-plugin && vendor/bin/phpunit --filter NonceStoreTest`
Expected: FAIL (classes don't exist).

- [ ] **Step 3: Create the NonceStore interface**

Create `packages/connector-plugin/src/Crypto/NonceStore.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Crypto;

/**
 * Replay-protection store for signed-request nonces.
 *
 * Mirrors Defyn\Dashboard\Crypto\NonceStore from F2 (intentional duplication —
 * two-plugin architecture per spec § 8.2). Production implementation is
 * TransientNonceStore (WP transients). Tests can substitute an in-memory
 * stub via the same interface.
 */
interface NonceStore
{
    /**
     * Atomically record the nonce. Returns true if it was new (and stored),
     * false if it had been seen before within the TTL window.
     */
    public function remember(string $nonce, int $ttlSeconds): bool;
}
```

- [ ] **Step 4: Create the TransientNonceStore implementation**

Create `packages/connector-plugin/src/Crypto/TransientNonceStore.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Crypto;

/**
 * WP transient backed nonce store.
 *
 * Transients survive across PHP-FPM workers and (with an object cache plugin)
 * are O(1) atomic via memcached / redis. Falls back to wp_options when no
 * object cache is configured — fine for the modest volume of signed
 * /status + /heartbeat requests we'll see.
 */
final class TransientNonceStore implements NonceStore
{
    private const PREFIX = 'defyn_conn_nonce_';

    public function remember(string $nonce, int $ttlSeconds): bool
    {
        // Hash user-supplied bytes — never use raw input as a DB key.
        $key = self::PREFIX . md5($nonce);

        if (get_transient($key) !== false) {
            return false;
        }
        set_transient($key, 1, $ttlSeconds);
        return true;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd packages/connector-plugin && vendor/bin/phpunit --filter NonceStoreTest`
Expected: PASS (all 4 cases).

- [ ] **Step 6: Commit**

```bash
git add packages/connector-plugin/src/Crypto/NonceStore.php \
        packages/connector-plugin/src/Crypto/TransientNonceStore.php \
        packages/connector-plugin/tests/Unit/Crypto/NonceStoreTest.php
git commit -m "F6: connector — add NonceStore interface + TransientNonceStore impl (mirrors dashboard F2)"
```

---

### Task 5: Extend connector `Signer` with `canonical()` + `verifyRequest()`

**Why:** Connector's existing `Signer::sign()` only signs raw bytes (F5's challenge). For F6 we need to verify inbound dashboard signatures — same canonical format as `Defyn\Dashboard\Crypto\Signer` so both sides produce the byte-identical canonical string per spec § 5.2.

**Files:**
- Modify: `packages/connector-plugin/src/Crypto/Signer.php`
- Create: `packages/connector-plugin/src/Crypto/VerificationResult.php` (NEW)
- Test: `packages/connector-plugin/tests/Unit/Crypto/SignerCanonicalTest.php` (NEW)
- Test: `packages/connector-plugin/tests/Unit/Crypto/SignerVerifyTest.php` (NEW)

- [ ] **Step 1: Create VerificationResult constants**

Create `packages/connector-plugin/src/Crypto/VerificationResult.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Crypto;

/**
 * Outcome constants returned by Signer::verifyRequest().
 *
 * Mirror Defyn\Dashboard\Crypto\VerificationResult — kept independent per the
 * two-plugin pattern. Order matters in Signer::verifyRequest (cheap checks
 * reject before expensive ones).
 */
final class VerificationResult
{
    public const VALID              = 'valid';
    public const INVALID_SIGNATURE  = 'invalid_signature';
    public const EXPIRED_TIMESTAMP  = 'expired_timestamp';
    public const REPLAYED_NONCE     = 'replayed_nonce';
    public const MISSING_HEADERS    = 'missing_headers';
}
```

- [ ] **Step 2: Write canonical() failing test**

Create `packages/connector-plugin/tests/Unit/Crypto/SignerCanonicalTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Unit\Crypto;

use Defyn\Connector\Crypto\Signer;
use PHPUnit\Framework\TestCase;

final class SignerCanonicalTest extends TestCase
{
    public function test_canonical_format_matches_spec_section_5_2(): void
    {
        $canonical = Signer::canonical('GET', '/defyn-connector/v1/status', '1716000000', 'nonce-xyz', '');
        $expected  = "GET\n/defyn-connector/v1/status\n1716000000\nnonce-xyz\n" . hash('sha256', '');

        $this->assertSame($expected, $canonical);
    }

    public function test_method_is_uppercased(): void
    {
        $canonical = Signer::canonical('get', '/x', '1', 'n', '');
        $this->assertStringStartsWith("GET\n", $canonical);
    }

    public function test_body_is_hashed_not_included_raw(): void
    {
        $body = '{"hello":"world"}';
        $canonical = Signer::canonical('POST', '/x', '1', 'n', $body);
        $this->assertStringEndsWith(hash('sha256', $body), $canonical);
        $this->assertStringNotContainsString($body, $canonical);
    }
}
```

- [ ] **Step 3: Write verifyRequest() failing test**

Create `packages/connector-plugin/tests/Unit/Crypto/SignerVerifyTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Unit\Crypto;

use Defyn\Connector\Crypto\NonceStore;
use Defyn\Connector\Crypto\Signer;
use Defyn\Connector\Crypto\VerificationResult;
use PHPUnit\Framework\TestCase;

final class SignerVerifyTest extends TestCase
{
    /** @var array<string,bool> */
    private array $seen = [];

    private function nonceStore(): NonceStore
    {
        return new class($this->seen) implements NonceStore {
            public function __construct(private array &$seen) {}
            public function remember(string $nonce, int $ttlSeconds): bool {
                if (isset($this->seen[$nonce])) return false;
                $this->seen[$nonce] = true;
                return true;
            }
        };
    }

    private function freshKeyPair(): array
    {
        $kp = sodium_crypto_sign_keypair();
        return [
            'public'  => base64_encode(sodium_crypto_sign_publickey($kp)),
            'private' => sodium_crypto_sign_secretkey($kp),
        ];
    }

    public function test_valid_signed_request_returns_valid(): void
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

    public function test_missing_headers_returns_missing_headers(): void
    {
        $kp = $this->freshKeyPair();
        $this->assertSame(
            VerificationResult::MISSING_HEADERS,
            Signer::verifyRequest($kp['public'], 'GET', '/x', '', [], $this->nonceStore())
        );
    }

    public function test_expired_timestamp_returns_expired(): void
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

    public function test_replayed_nonce_returns_replayed(): void
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

    public function test_tampered_body_returns_invalid(): void
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
```

- [ ] **Step 4: Run both tests to verify they fail**

Run: `cd packages/connector-plugin && vendor/bin/phpunit --filter "SignerCanonicalTest|SignerVerifyTest"`
Expected: FAIL (methods don't exist).

- [ ] **Step 5: Extend Signer with canonical() + verifyRequest()**

In `packages/connector-plugin/src/Crypto/Signer.php`, ADD these two static methods (don't touch the existing `sign()`):

```php
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

/**
 * Verify a signed inbound request. Returns one of VerificationResult constants.
 *
 * Check order (cheap rejects first):
 *   1. All three headers present + well-formed
 *   2. Timestamp within ±$maxAgeSeconds of $now
 *   3. Key + signature decode + length sanity
 *   4. Signature valid against canonical(method, path, timestamp, nonce, body)
 *   5. Nonce not previously seen (and stored atomically here)
 *
 * @param array<string, string> $headers must contain X-Defyn-Timestamp, X-Defyn-Nonce, X-Defyn-Signature
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

    if (!ctype_digit($timestamp) || $nonce === '' || $sigB64 === '') {
        return VerificationResult::MISSING_HEADERS;
    }

    $now = $now ?? time();
    if (abs($now - (int) $timestamp) > $maxAgeSeconds) {
        return VerificationResult::EXPIRED_TIMESTAMP;
    }

    $publicKey = base64_decode($publicKeyBase64, true);
    $signature = base64_decode($sigB64, true);
    if ($publicKey === false || $signature === false
        || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
        || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
        return VerificationResult::INVALID_SIGNATURE;
    }

    $canonical = self::canonical($method, $path, $timestamp, $nonce, $body);
    if (!sodium_crypto_sign_verify_detached($signature, $canonical, $publicKey)) {
        return VerificationResult::INVALID_SIGNATURE;
    }

    if (!$nonceStore->remember($nonce, $maxAgeSeconds * 2)) {
        return VerificationResult::REPLAYED_NONCE;
    }

    return VerificationResult::VALID;
}
```

Update the file's docblock to note that F6 added canonical-string verification (the existing F5 docblock says F6 *will* add it).

- [ ] **Step 6: Run tests to verify they pass**

Run: `cd packages/connector-plugin && vendor/bin/phpunit --filter "SignerCanonicalTest|SignerVerifyTest"`
Expected: PASS (all cases).

- [ ] **Step 7: Run full connector suite**

Run: `cd packages/connector-plugin && vendor/bin/phpunit`
Expected: All tests pass — existing `Signer::sign()` callers unaffected.

- [ ] **Step 8: Commit**

```bash
git add packages/connector-plugin/src/Crypto/Signer.php \
        packages/connector-plugin/src/Crypto/VerificationResult.php \
        packages/connector-plugin/tests/Unit/Crypto/SignerCanonicalTest.php \
        packages/connector-plugin/tests/Unit/Crypto/SignerVerifyTest.php
git commit -m "F6: connector — extend Signer with canonical() + verifyRequest() (spec § 5.2)"
```

---

### Task 6: Connector `VerifySignatureMiddleware`

**Why:** WP REST `permission_callback` hook that gates the three new endpoints (Tasks 8-10). Maps `VerificationResult` constants to spec-shaped 401/404 WP_Errors. `RestRouter::normalizeErrorEnvelope` (existing from F5) rewraps to `{error:{code,message}}`.

**Files:**
- Create: `packages/connector-plugin/src/Rest/Middleware/VerifySignatureMiddleware.php`
- Test: `packages/connector-plugin/tests/Integration/Rest/VerifySignatureMiddlewareTest.php` (NEW)

- [ ] **Step 1: Write the failing test (defer green path to Task 9)**

Create `packages/connector-plugin/tests/Integration/Rest/VerifySignatureMiddlewareTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Rest;

use Defyn\Connector\Crypto\Signer;
use Defyn\Connector\Storage\ConnectorState;
use Defyn\Connector\Tests\Integration\RestTestCase;

final class VerifySignatureMiddlewareTest extends RestTestCase
{
    private string $dashboardPrivateKeyRaw = '';
    private string $dashboardPublicKeyB64  = '';

    protected function setUp(): void
    {
        parent::setUp();
        $kp = sodium_crypto_sign_keypair();
        $this->dashboardPrivateKeyRaw = sodium_crypto_sign_secretkey($kp);
        $this->dashboardPublicKeyB64  = base64_encode(sodium_crypto_sign_publickey($kp));

        (new ConnectorState())->update([
            'state'                => 'connected',
            'dashboard_public_key' => $this->dashboardPublicKeyB64,
        ]);
    }

    private function signedHeaders(string $method, string $path, string $body = ''): array
    {
        $ts    = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $canon = Signer::canonical($method, $path, $ts, $nonce, $body);
        $sig   = base64_encode(sodium_crypto_sign_detached($canon, $this->dashboardPrivateKeyRaw));

        return [
            'X-Defyn-Timestamp' => $ts,
            'X-Defyn-Nonce'     => $nonce,
            'X-Defyn-Signature' => $sig,
        ];
    }

    public function test_valid_signature_passes_through_to_heartbeat(): void
    {
        $headers  = $this->signedHeaders('GET', '/defyn-connector/v1/heartbeat');
        $response = $this->getJson('/defyn-connector/v1/heartbeat', $headers);

        $this->assertSame(200, $response['status']);
        $this->assertTrue($response['body']['ok']);
    }

    public function test_missing_headers_returns_signature_missing(): void
    {
        $response = $this->getJson('/defyn-connector/v1/heartbeat', []);
        $this->assertSame(401, $response['status']);
        $this->assertSame('connector.signature_missing', $response['body']['error']['code']);
    }

    public function test_expired_timestamp_returns_signature_expired(): void
    {
        $ts    = (string) (time() - 1000);
        $nonce = bin2hex(random_bytes(16));
        $canon = Signer::canonical('GET', '/defyn-connector/v1/heartbeat', $ts, $nonce, '');
        $sig   = base64_encode(sodium_crypto_sign_detached($canon, $this->dashboardPrivateKeyRaw));

        $response = $this->getJson('/defyn-connector/v1/heartbeat', [
            'X-Defyn-Timestamp' => $ts,
            'X-Defyn-Nonce'     => $nonce,
            'X-Defyn-Signature' => $sig,
        ]);

        $this->assertSame(401, $response['status']);
        $this->assertSame('connector.signature_expired', $response['body']['error']['code']);
    }

    public function test_replayed_nonce_returns_signature_replay(): void
    {
        $headers = $this->signedHeaders('GET', '/defyn-connector/v1/heartbeat');

        $first  = $this->getJson('/defyn-connector/v1/heartbeat', $headers);
        $second = $this->getJson('/defyn-connector/v1/heartbeat', $headers);

        $this->assertSame(200, $first['status']);
        $this->assertSame(401, $second['status']);
        $this->assertSame('connector.signature_replay', $second['body']['error']['code']);
    }

    public function test_unconnected_state_returns_not_connected(): void
    {
        (new ConnectorState())->update(['state' => 'unconfigured', 'dashboard_public_key' => '']);

        $headers  = $this->signedHeaders('GET', '/defyn-connector/v1/heartbeat');
        $response = $this->getJson('/defyn-connector/v1/heartbeat', $headers);

        $this->assertSame(404, $response['status']);
        $this->assertSame('connector.not_connected', $response['body']['error']['code']);
    }
}
```

Note: green path of these tests requires the `/heartbeat` route (Task 9). Implementer can either (a) keep this test red until Task 9, or (b) temporarily wire a stub route during Task 6 and remove it in Task 9. Choice recorded in self-review.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd packages/connector-plugin && vendor/bin/phpunit --filter VerifySignatureMiddlewareTest`
Expected: FAIL (middleware class doesn't exist; route doesn't exist).

- [ ] **Step 3: Create VerifySignatureMiddleware**

Create `packages/connector-plugin/src/Rest/Middleware/VerifySignatureMiddleware.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Rest\Middleware;

use Defyn\Connector\Crypto\Signer;
use Defyn\Connector\Crypto\TransientNonceStore;
use Defyn\Connector\Crypto\VerificationResult;
use Defyn\Connector\Storage\ConnectorState;
use WP_Error;
use WP_REST_Request;

/**
 * Gates inbound /status, /heartbeat, /disconnect requests by verifying the
 * dashboard's Ed25519 signature per spec § 5.2.
 *
 * Returns WP_Error (with HTTP status) so RestRouter::normalizeErrorEnvelope
 * (existing from F5) can rewrap to the {error:{code,message}} envelope —
 * permission_callbacks can only return bool|WP_Error per WP REST contract.
 */
final class VerifySignatureMiddleware
{
    /**
     * @return true|WP_Error
     */
    public static function check(WP_REST_Request $request)
    {
        $state = new ConnectorState();
        if ($state->get('state', 'unconfigured') !== 'connected') {
            return new WP_Error(
                'connector.not_connected',
                'Connector is not currently connected to a dashboard.',
                ['status' => 404]
            );
        }

        $publicKey = (string) $state->get('dashboard_public_key', '');
        if ($publicKey === '') {
            // Defense in depth — state==connected with empty key is impossible
            // by construction, but treat it as not_connected anyway.
            return new WP_Error(
                'connector.not_connected',
                'Dashboard public key is missing.',
                ['status' => 404]
            );
        }

        $method = strtoupper($request->get_method());
        $path   = $request->get_route();
        $body   = $request->get_body();
        $headers = [
            'X-Defyn-Timestamp' => (string) ($request->get_header('x_defyn_timestamp') ?? ''),
            'X-Defyn-Nonce'     => (string) ($request->get_header('x_defyn_nonce') ?? ''),
            'X-Defyn-Signature' => (string) ($request->get_header('x_defyn_signature') ?? ''),
        ];

        $result = Signer::verifyRequest(
            $publicKey,
            $method,
            $path,
            $body,
            $headers,
            new TransientNonceStore()
        );

        return match ($result) {
            VerificationResult::VALID             => true,
            VerificationResult::MISSING_HEADERS   => new WP_Error('connector.signature_missing', 'Required signing headers are missing or malformed.', ['status' => 401]),
            VerificationResult::EXPIRED_TIMESTAMP => new WP_Error('connector.signature_expired', 'Signed request timestamp is outside the accepted window.', ['status' => 401]),
            VerificationResult::REPLAYED_NONCE    => new WP_Error('connector.signature_replay', 'Nonce has already been used.', ['status' => 401]),
            VerificationResult::INVALID_SIGNATURE => new WP_Error('connector.signature_invalid', 'Signature does not match dashboard public key.', ['status' => 401]),
        };
    }
}
```

- [ ] **Step 4: Commit (test file stays red until Task 9 makes the route exist)**

```bash
git add packages/connector-plugin/src/Rest/Middleware/VerifySignatureMiddleware.php \
        packages/connector-plugin/tests/Integration/Rest/VerifySignatureMiddlewareTest.php
git commit -m "F6: connector — add VerifySignatureMiddleware (gates /status, /heartbeat, /disconnect)"
```

---

### Task 7: Connector `SiteInfo\Collector`

**Why:** Gathers the `/status` payload from WordPress runtime per spec § 5.1: WP version, PHP version, active theme, plugin counts, theme counts, SSL status, server time.

**Files:**
- Create: `packages/connector-plugin/src/SiteInfo/Collector.php`
- Test: `packages/connector-plugin/tests/Integration/SiteInfo/CollectorTest.php` (NEW)

- [ ] **Step 1: Write the failing test**

Create `packages/connector-plugin/tests/Integration/SiteInfo/CollectorTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\SiteInfo;

use Defyn\Connector\SiteInfo\Collector;
use WP_UnitTestCase;

final class CollectorTest extends WP_UnitTestCase
{
    public function test_returns_expected_keys(): void
    {
        $info = (new Collector())->collect();

        $this->assertArrayHasKey('wp_version', $info);
        $this->assertArrayHasKey('php_version', $info);
        $this->assertArrayHasKey('active_theme', $info);
        $this->assertArrayHasKey('plugin_counts', $info);
        $this->assertArrayHasKey('theme_counts', $info);
        $this->assertArrayHasKey('ssl_status', $info);
        $this->assertArrayHasKey('ssl_expires_at', $info);
        $this->assertArrayHasKey('server_time', $info);
    }

    public function test_wp_version_matches_bloginfo(): void
    {
        $info = (new Collector())->collect();
        $this->assertSame(get_bloginfo('version'), $info['wp_version']);
    }

    public function test_php_version_matches_phpversion(): void
    {
        $info = (new Collector())->collect();
        $this->assertSame(phpversion(), $info['php_version']);
    }

    public function test_active_theme_has_name_and_version(): void
    {
        $info = (new Collector())->collect();
        $this->assertIsArray($info['active_theme']);
        $this->assertArrayHasKey('name', $info['active_theme']);
        $this->assertArrayHasKey('version', $info['active_theme']);
        $this->assertArrayHasKey('parent', $info['active_theme']);
    }

    public function test_plugin_counts_shape(): void
    {
        $info = (new Collector())->collect();
        $this->assertArrayHasKey('installed', $info['plugin_counts']);
        $this->assertArrayHasKey('active', $info['plugin_counts']);
        $this->assertIsInt($info['plugin_counts']['installed']);
        $this->assertIsInt($info['plugin_counts']['active']);
        $this->assertGreaterThanOrEqual($info['plugin_counts']['active'], $info['plugin_counts']['installed']);
    }

    public function test_server_time_is_unix_timestamp(): void
    {
        $before = time();
        $info   = (new Collector())->collect();
        $after  = time();

        $this->assertIsInt($info['server_time']);
        $this->assertGreaterThanOrEqual($before, $info['server_time']);
        $this->assertLessThanOrEqual($after, $info['server_time']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd packages/connector-plugin && vendor/bin/phpunit --filter CollectorTest`
Expected: FAIL (class doesn't exist).

- [ ] **Step 3: Create the Collector**

Create `packages/connector-plugin/src/SiteInfo/Collector.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\SiteInfo;

/**
 * Gathers the /status payload per spec § 5.1.
 *
 * Pure-read; never mutates WP state. Loads admin includes only when needed for
 * plugin enumeration (get_plugins() lives in wp-admin/includes/plugin.php).
 */
final class Collector
{
    /**
     * @return array{
     *   wp_version: string,
     *   php_version: string,
     *   active_theme: array{name: string, version: string, parent: ?string},
     *   plugin_counts: array{installed: int, active: int},
     *   theme_counts: array{installed: int, active: int},
     *   ssl_status: string,
     *   ssl_expires_at: ?int,
     *   server_time: int
     * }
     */
    public function collect(): array
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $theme  = wp_get_theme();
        $parent = $theme->parent();

        $allPlugins    = get_plugins();
        $activePlugins = (array) get_option('active_plugins', []);
        if (is_multisite() && function_exists('get_site_option')) {
            $networkActive = array_keys((array) get_site_option('active_sitewide_plugins', []));
            $activePlugins = array_unique(array_merge($activePlugins, $networkActive));
        }

        $allThemes = wp_get_themes();

        return [
            'wp_version'   => (string) get_bloginfo('version'),
            'php_version'  => phpversion(),
            'active_theme' => [
                'name'    => (string) $theme->get('Name'),
                'version' => (string) $theme->get('Version'),
                'parent'  => $parent ? (string) $parent->get('Name') : null,
            ],
            'plugin_counts' => [
                'installed' => count($allPlugins),
                'active'    => count($activePlugins),
            ],
            'theme_counts' => [
                'installed' => count($allThemes),
                'active'    => 1,
            ],
            'ssl_status'     => $this->detectSslStatus(),
            'ssl_expires_at' => null,  // Cert-expiry parsing deferred to later phase
            'server_time'    => time(),
        ];
    }

    private function detectSslStatus(): string
    {
        $siteUrl = (string) get_option('siteurl', '');
        return str_starts_with($siteUrl, 'https://') ? 'enabled' : 'disabled';
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd packages/connector-plugin && vendor/bin/phpunit --filter CollectorTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add packages/connector-plugin/src/SiteInfo/Collector.php \
        packages/connector-plugin/tests/Integration/SiteInfo/CollectorTest.php
git commit -m "F6: connector — add SiteInfo\\Collector (assembles /status payload per spec § 5.1)"
```

---

### Task 8: Connector `GET /status` endpoint

**Why:** Spec § 5.1 — exposes the site snapshot to authenticated dashboard calls. Wires `VerifySignatureMiddleware` (Task 6) as permission_callback and returns `Collector::collect()` (Task 7).

**Files:**
- Create: `packages/connector-plugin/src/Rest/StatusController.php`
- Modify: `packages/connector-plugin/src/Rest/RestRouter.php`
- Test: `packages/connector-plugin/tests/Integration/Rest/StatusTest.php` (NEW)

- [ ] **Step 1: Write the failing test**

Create `packages/connector-plugin/tests/Integration/Rest/StatusTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Rest;

use Defyn\Connector\Crypto\Signer;
use Defyn\Connector\Storage\ConnectorState;
use Defyn\Connector\Tests\Integration\RestTestCase;

final class StatusTest extends RestTestCase
{
    public function test_signed_request_returns_site_snapshot(): void
    {
        $kp      = sodium_crypto_sign_keypair();
        $privRaw = sodium_crypto_sign_secretkey($kp);
        $pubB64  = base64_encode(sodium_crypto_sign_publickey($kp));

        (new ConnectorState())->update([
            'state'                => 'connected',
            'dashboard_public_key' => $pubB64,
        ]);

        $ts    = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $canon = Signer::canonical('GET', '/defyn-connector/v1/status', $ts, $nonce, '');
        $sig   = base64_encode(sodium_crypto_sign_detached($canon, $privRaw));

        $response = $this->getJson('/defyn-connector/v1/status', [
            'X-Defyn-Timestamp' => $ts,
            'X-Defyn-Nonce'     => $nonce,
            'X-Defyn-Signature' => $sig,
        ]);

        $this->assertSame(200, $response['status']);
        $this->assertArrayHasKey('wp_version', $response['body']);
        $this->assertArrayHasKey('plugin_counts', $response['body']);
        $this->assertSame(get_bloginfo('version'), $response['body']['wp_version']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd packages/connector-plugin && vendor/bin/phpunit --filter StatusTest`
Expected: FAIL (route doesn't exist).

- [ ] **Step 3: Create StatusController**

Create `packages/connector-plugin/src/Rest/StatusController.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Rest;

use Defyn\Connector\SiteInfo\Collector;
use WP_REST_Request;
use WP_REST_Response;

final class StatusController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response((new Collector())->collect(), 200);
    }
}
```

- [ ] **Step 4: Register the route in RestRouter**

In `packages/connector-plugin/src/Rest/RestRouter.php`, ADD inside `register()` alongside existing `/connect`:

```php
register_rest_route(self::NAMESPACE, '/status', [
    'methods'             => 'GET',
    'callback'            => [new StatusController(), 'handle'],
    'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
]);
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd packages/connector-plugin && vendor/bin/phpunit --filter StatusTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add packages/connector-plugin/src/Rest/StatusController.php \
        packages/connector-plugin/src/Rest/RestRouter.php \
        packages/connector-plugin/tests/Integration/Rest/StatusTest.php
git commit -m "F6: connector — add signed GET /status endpoint (spec § 5.1)"
```

---

### Task 9: Connector `GET /heartbeat` endpoint

**Why:** Spec § 5.1 — lightweight liveness probe. Returns `{ok: true, server_time: <unix>}`. Used by dashboard's HealthService to verify connectivity without paying the SiteInfo collection cost. Completes the VerifySignatureMiddleware integration tests from Task 6.

**Files:**
- Create: `packages/connector-plugin/src/Rest/HeartbeatController.php`
- Modify: `packages/connector-plugin/src/Rest/RestRouter.php`
- Test: `packages/connector-plugin/tests/Integration/Rest/HeartbeatTest.php` (NEW)

- [ ] **Step 1: Write the failing test**

Create `packages/connector-plugin/tests/Integration/Rest/HeartbeatTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Rest;

use Defyn\Connector\Crypto\Signer;
use Defyn\Connector\Storage\ConnectorState;
use Defyn\Connector\Tests\Integration\RestTestCase;

final class HeartbeatTest extends RestTestCase
{
    public function test_signed_request_returns_ok_and_server_time(): void
    {
        $kp      = sodium_crypto_sign_keypair();
        $privRaw = sodium_crypto_sign_secretkey($kp);
        $pubB64  = base64_encode(sodium_crypto_sign_publickey($kp));

        (new ConnectorState())->update([
            'state'                => 'connected',
            'dashboard_public_key' => $pubB64,
        ]);

        $ts    = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $canon = Signer::canonical('GET', '/defyn-connector/v1/heartbeat', $ts, $nonce, '');
        $sig   = base64_encode(sodium_crypto_sign_detached($canon, $privRaw));

        $before   = time();
        $response = $this->getJson('/defyn-connector/v1/heartbeat', [
            'X-Defyn-Timestamp' => $ts,
            'X-Defyn-Nonce'     => $nonce,
            'X-Defyn-Signature' => $sig,
        ]);
        $after    = time();

        $this->assertSame(200, $response['status']);
        $this->assertTrue($response['body']['ok']);
        $this->assertIsInt($response['body']['server_time']);
        $this->assertGreaterThanOrEqual($before, $response['body']['server_time']);
        $this->assertLessThanOrEqual($after, $response['body']['server_time']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd packages/connector-plugin && vendor/bin/phpunit --filter HeartbeatTest`
Expected: FAIL (route doesn't exist).

- [ ] **Step 3: Create HeartbeatController**

Create `packages/connector-plugin/src/Rest/HeartbeatController.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Rest;

use WP_REST_Request;
use WP_REST_Response;

final class HeartbeatController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response(['ok' => true, 'server_time' => time()], 200);
    }
}
```

- [ ] **Step 4: Register the route**

In `packages/connector-plugin/src/Rest/RestRouter.php`, ADD inside `register()`:

```php
register_rest_route(self::NAMESPACE, '/heartbeat', [
    'methods'             => 'GET',
    'callback'            => [new HeartbeatController(), 'handle'],
    'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
]);
```

- [ ] **Step 5: Run heartbeat + middleware integration tests together**

Run: `cd packages/connector-plugin && vendor/bin/phpunit --filter "HeartbeatTest|VerifySignatureMiddlewareTest"`
Expected: ALL PASS — Task 6's deferred tests now green.

- [ ] **Step 6: Commit**

```bash
git add packages/connector-plugin/src/Rest/HeartbeatController.php \
        packages/connector-plugin/src/Rest/RestRouter.php \
        packages/connector-plugin/tests/Integration/Rest/HeartbeatTest.php
git commit -m "F6: connector — add signed GET /heartbeat endpoint (spec § 5.1)"
```

---

### Task 10: Connector `POST /disconnect` endpoint

**Why:** Spec § 5.1 — dashboard-initiated tear-down. Wipes `dashboard_public_key` and `connected_at`, transitions state back to `unconfigured`. Per F4 reset-handler precedent, KEEPS the site keypair so the operator can re-handshake without re-activating the plugin. Returns 204.

**Files:**
- Create: `packages/connector-plugin/src/Rest/DisconnectController.php`
- Modify: `packages/connector-plugin/src/Rest/RestRouter.php`
- Test: `packages/connector-plugin/tests/Integration/Rest/DisconnectTest.php` (NEW)

- [ ] **Step 1: Write the failing test**

Create `packages/connector-plugin/tests/Integration/Rest/DisconnectTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Rest;

use Defyn\Connector\Crypto\Signer;
use Defyn\Connector\Storage\ConnectorState;
use Defyn\Connector\Tests\Integration\RestTestCase;

final class DisconnectTest extends RestTestCase
{
    public function test_signed_disconnect_wipes_dashboard_keys_keeps_site_keypair(): void
    {
        $kp      = sodium_crypto_sign_keypair();
        $privRaw = sodium_crypto_sign_secretkey($kp);
        $pubB64  = base64_encode(sodium_crypto_sign_publickey($kp));

        $sitePubKey  = base64_encode(random_bytes(32));
        $sitePrivKey = base64_encode(random_bytes(64));

        (new ConnectorState())->update([
            'state'                => 'connected',
            'dashboard_public_key' => $pubB64,
            'connected_at'         => '2026-05-17 10:00:00',
            'site_public_key'      => $sitePubKey,
            'site_private_key'     => $sitePrivKey,
        ]);

        $ts    = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $canon = Signer::canonical('POST', '/defyn-connector/v1/disconnect', $ts, $nonce, '');
        $sig   = base64_encode(sodium_crypto_sign_detached($canon, $privRaw));

        $response = $this->postJson('/defyn-connector/v1/disconnect', [], [
            'X-Defyn-Timestamp' => $ts,
            'X-Defyn-Nonce'     => $nonce,
            'X-Defyn-Signature' => $sig,
        ]);

        $this->assertSame(204, $response['status']);

        $state = new ConnectorState();
        $this->assertSame('unconfigured', $state->get('state'));
        $this->assertSame('', (string) $state->get('dashboard_public_key', ''));
        $this->assertSame('', (string) $state->get('connected_at', ''));
        // Site keypair preserved per F4 reset-handler precedent
        $this->assertSame($sitePubKey, $state->get('site_public_key'));
        $this->assertSame($sitePrivKey, $state->get('site_private_key'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd packages/connector-plugin && vendor/bin/phpunit --filter DisconnectTest`
Expected: FAIL (route doesn't exist).

- [ ] **Step 3: Create DisconnectController**

Create `packages/connector-plugin/src/Rest/DisconnectController.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Rest;

use Defyn\Connector\Storage\ConnectorState;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Dashboard-initiated disconnect. Wipes dashboard-side trust material but
 * keeps the site's own keypair (operator can immediately re-handshake by
 * generating a new code via SettingsPage). Per F4 reset-handler precedent.
 */
final class DisconnectController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        (new ConnectorState())->update([
            'state'                => 'unconfigured',
            'dashboard_public_key' => '',
            'connected_at'         => '',
            'connection_code'      => '',
            'site_nonce'           => '',
            'code_created_at'      => 0,
            'code_expires_at'      => 0,
        ]);

        return new WP_REST_Response(null, 204);
    }
}
```

- [ ] **Step 4: Register the route**

In `packages/connector-plugin/src/Rest/RestRouter.php`, ADD inside `register()`:

```php
register_rest_route(self::NAMESPACE, '/disconnect', [
    'methods'             => 'POST',
    'callback'            => [new DisconnectController(), 'handle'],
    'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
]);
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd packages/connector-plugin && vendor/bin/phpunit --filter DisconnectTest`
Expected: PASS

- [ ] **Step 6: Run full connector suite**

Run: `cd packages/connector-plugin && vendor/bin/phpunit`
Expected: All connector tests pass.

- [ ] **Step 7: Commit**

```bash
git add packages/connector-plugin/src/Rest/DisconnectController.php \
        packages/connector-plugin/src/Rest/RestRouter.php \
        packages/connector-plugin/tests/Integration/Rest/DisconnectTest.php
git commit -m "F6: connector — add signed POST /disconnect endpoint (wipes dashboard trust, keeps site keypair)"
```

---

### Task 11: Extend dashboard `SignedHttpClient` to actually sign

**Why:** F5 placeholder did plain JSON POST with no signing. F6 adds `signedPostJson()` + `signedGet()` that use F2's `Defyn\Dashboard\Crypto\Signer::signRequest()` with a per-site decrypted private key. Existing `postJson()` stays (F5 handshake still uses it — at handshake-time the dashboard doesn't yet have the site's pubkey; chicken/egg).

**Files:**
- Modify: `packages/dashboard-plugin/src/Http/SignedHttpClient.php`
- Test: `packages/dashboard-plugin/tests/Unit/Http/SignedHttpClientSignedRequestsTest.php` (NEW)

- [ ] **Step 1: Write the failing test**

Create `packages/dashboard-plugin/tests/Unit/Http/SignedHttpClientSignedRequestsTest.php`:

```php
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
    public function test_signedGet_attaches_three_signing_headers(): void
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

    public function test_signedPostJson_signs_serialized_body(): void
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit --filter SignedHttpClientSignedRequestsTest`
Expected: FAIL (methods don't exist).

- [ ] **Step 3: Extend SignedHttpClient**

In `packages/dashboard-plugin/src/Http/SignedHttpClient.php`, ADD two new methods alongside the existing `postJson()`. Keep `postJson()` intact (F5 handshake still uses it because at handshake-time the dashboard does not yet have the site's pubkey).

```php
/**
 * Signed GET. Caller supplies the per-site Ed25519 private key (base64) and
 * the canonical path (the part after host, e.g. /defyn-connector/v1/status).
 *
 * @return array{status: int, body: array<string, mixed>, error: string}
 */
public function signedGet(string $url, string $privateKeyBase64, string $canonicalPath): array
{
    $signer  = new Signer($privateKeyBase64);
    $headers = $signer->signRequest('GET', $canonicalPath, '');
    return $this->sendSigned('GET', $url, null, $headers);
}

/**
 * Signed POST with a JSON body. Body is serialized once and signed over the
 * exact bytes that go on the wire (otherwise the connector recomputes a
 * different hash and rejects).
 *
 * @param array<string, mixed> $body
 * @return array{status: int, body: array<string, mixed>, error: string}
 */
public function signedPostJson(string $url, array $body, string $privateKeyBase64, string $canonicalPath): array
{
    $serialized = json_encode($body, JSON_UNESCAPED_SLASHES);
    if ($serialized === false) {
        return ['status' => 0, 'body' => [], 'error' => 'Failed to serialize body'];
    }

    $signer  = new Signer($privateKeyBase64);
    $headers = array_merge(
        ['Content-Type' => 'application/json'],
        $signer->signRequest('POST', $canonicalPath, $serialized)
    );
    return $this->sendSigned('POST', $url, $serialized, $headers);
}

/**
 * @param string|null $body raw body bytes (already-serialized JSON for POSTs)
 * @param array<string, string> $headers
 * @return array{status: int, body: array<string, mixed>, error: string}
 */
private function sendSigned(string $method, string $url, ?string $body, array $headers): array
{
    $client = $this->httpClient ?? HttpClient::create([
        'timeout'      => 10,
        'max_duration' => 30,
    ]);

    $options = ['headers' => $headers];
    if ($body !== null) {
        $options['body'] = $body;
    }

    try {
        $response = $client->request($method, $url, $options);
        $status   = $response->getStatusCode();
        $raw      = $response->getContent(throw: false);
        $decoded  = $raw === '' ? [] : (json_decode($raw, true) ?? []);
        return ['status' => $status, 'body' => $decoded, 'error' => ''];
    } catch (Throwable $e) {
        return ['status' => 0, 'body' => [], 'error' => $e->getMessage()];
    }
}
```

Add `use Defyn\Dashboard\Crypto\Signer;` at the top of the file. Update the class-level docblock: replace "F5: plain JSON POST with no signing" with a note that F6 added `signedGet` + `signedPostJson` and the F5 `postJson` is preserved for the handshake step (which signs at the application layer via callback_challenge).

- [ ] **Step 4: Run test to verify it passes**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit --filter SignedHttpClientSignedRequestsTest`
Expected: PASS

- [ ] **Step 5: Run existing SignedHttpClient tests to check no regression**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit --filter SignedHttpClient`
Expected: All tests (existing + new) pass.

- [ ] **Step 6: Commit**

```bash
git add packages/dashboard-plugin/src/Http/SignedHttpClient.php \
        packages/dashboard-plugin/tests/Unit/Http/SignedHttpClientSignedRequestsTest.php
git commit -m "F6: dashboard — extend SignedHttpClient with signedGet + signedPostJson (spec § 5.2)"
```

---

### Task 12: Extend `SitesRepository` with `markSynced` + `markOffline` (+ schema columns if missing)

**Why:** `SyncService` and `HealthService` (Tasks 13-14) need persistence helpers. Per repository pattern (F1), all `wp_defyn_sites` writes flow through `SitesRepository`. New `offline` status fits the existing VARCHAR(20) column — no migration needed for status. New runtime-info columns DO need a migration if the F1 schema didn't include them — check first.

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/SitesRepository.php`
- Modify (conditional): `packages/dashboard-plugin/src/Schema/SitesTable.php`
- Modify (conditional): `packages/dashboard-plugin/src/Models/Site.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/SitesRepositoryF6Test.php` (NEW)

- [ ] **Step 1: Inspect existing schema first**

Run: `cat packages/dashboard-plugin/src/Schema/SitesTable.php`

Note which of these columns already exist: `wp_version`, `php_version`, `active_theme`, `plugin_counts`, `theme_counts`, `ssl_status`, `ssl_expires_at`, `last_sync_at`.

- [ ] **Step 2: Write the failing test**

Create `packages/dashboard-plugin/tests/Integration/Services/SitesRepositoryF6Test.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SitesRepositoryF6Test extends AbstractSchemaTestCase
{
    public function test_markSynced_persists_runtime_info(): void
    {
        $repo = new SitesRepository();
        $id   = $repo->insertPending(1, 'https://a.test', 'A', base64_encode(random_bytes(32)), 'cipher');
        $repo->markActive($id, base64_encode(random_bytes(32)));

        $info = [
            'wp_version'     => '6.7.1',
            'php_version'    => '8.2.18',
            'active_theme'   => ['name' => 'Twenty Twenty-Four', 'version' => '1.2', 'parent' => null],
            'plugin_counts'  => ['installed' => 12, 'active' => 8],
            'theme_counts'   => ['installed' => 3, 'active' => 1],
            'ssl_status'     => 'enabled',
            'ssl_expires_at' => null,
            'server_time'    => time(),
        ];
        $repo->markSynced($id, $info);

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . SitesTable::tableName() . ' WHERE id = %d', $id),
            ARRAY_A
        );

        $this->assertSame('6.7.1', $row['wp_version']);
        $this->assertSame('8.2.18', $row['php_version']);
        $this->assertSame('enabled', $row['ssl_status']);
        $this->assertNotEmpty($row['last_sync_at']);
        $this->assertNotEmpty($row['last_contact_at']);
        $this->assertNotEmpty($row['active_theme']);   // JSON-encoded
        $this->assertNotEmpty($row['plugin_counts']);  // JSON-encoded
    }

    public function test_markOffline_flips_status_and_records_error(): void
    {
        $repo = new SitesRepository();
        $id   = $repo->insertPending(1, 'https://a.test', 'A', base64_encode(random_bytes(32)), 'cipher');
        $repo->markActive($id, base64_encode(random_bytes(32)));

        $repo->markOffline($id, 'connection refused');

        $site = $repo->findById($id);
        $this->assertSame('offline', $site->status);
        $this->assertSame('connection refused', $site->lastError);
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit --filter SitesRepositoryF6Test`
Expected: FAIL (methods don't exist; possibly columns missing).

- [ ] **Step 4 (conditional): Add missing columns to SitesTable schema**

If Step 1 showed any of these missing, add them to the `CREATE TABLE` definition:
- `wp_version VARCHAR(32) NULL`
- `php_version VARCHAR(32) NULL`
- `active_theme TEXT NULL`        (JSON-encoded)
- `plugin_counts TEXT NULL`       (JSON-encoded)
- `theme_counts TEXT NULL`        (JSON-encoded)
- `ssl_status VARCHAR(32) NULL`
- `ssl_expires_at BIGINT(20) UNSIGNED NULL`  (unix timestamp)
- `last_sync_at DATETIME NULL`

Bump the schema version constant if the scaffold uses `dbDelta` versioning. Update the F1 schema test if it asserts a complete column list.

- [ ] **Step 5: Add markSynced + markOffline to SitesRepository**

In `packages/dashboard-plugin/src/Services/SitesRepository.php`, ADD:

```php
/**
 * @param array{
 *   wp_version: string,
 *   php_version: string,
 *   active_theme: array<string, mixed>,
 *   plugin_counts: array<string, int>,
 *   theme_counts: array<string, int>,
 *   ssl_status: string,
 *   ssl_expires_at: ?int,
 *   server_time?: int
 * } $info
 */
public function markSynced(int $id, array $info): void
{
    global $wpdb;
    $now = gmdate('Y-m-d H:i:s');
    $wpdb->update(
        SitesTable::tableName(),
        [
            'wp_version'      => $info['wp_version'],
            'php_version'     => $info['php_version'],
            'active_theme'    => wp_json_encode($info['active_theme']),
            'plugin_counts'   => wp_json_encode($info['plugin_counts']),
            'theme_counts'    => wp_json_encode($info['theme_counts']),
            'ssl_status'      => $info['ssl_status'],
            'ssl_expires_at'  => $info['ssl_expires_at'],
            'last_sync_at'    => $now,
            'last_contact_at' => $now,
            'updated_at'      => $now,
        ],
        ['id' => $id],
        ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s'],
        ['%d'],
    );
}

public function markOffline(int $id, string $message): void
{
    global $wpdb;
    $wpdb->update(
        SitesTable::tableName(),
        [
            'status'     => 'offline',
            'last_error' => $message,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ],
        ['id' => $id],
        ['%s', '%s', '%s'],
        ['%d'],
    );
}
```

If Step 4 added new columns, also update `Site::fromRow()` to map them onto new public properties (e.g. `$wpVersion`, `$phpVersion`, `$sslStatus`, `$lastSyncAt`, `$activeTheme` decoded JSON, etc.).

- [ ] **Step 6: Run test to verify it passes**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit --filter SitesRepositoryF6Test`
Expected: PASS

- [ ] **Step 7: Run full dashboard suite (catches Model/schema regressions)**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit`
Expected: All tests pass.

- [ ] **Step 8: Commit**

```bash
git add packages/dashboard-plugin/src/Services/SitesRepository.php \
        packages/dashboard-plugin/src/Schema/SitesTable.php \
        packages/dashboard-plugin/src/Models/Site.php \
        packages/dashboard-plugin/tests/Integration/Services/SitesRepositoryF6Test.php
# (omit any of the above that you didn't actually modify)
git commit -m "F6: dashboard — extend SitesRepository with markSynced + markOffline (and any schema columns)"
```

---

### Task 13: `SyncService`

**Why:** Orchestrates the signed `GET /status` call: pulls per-site keypair, decrypts private key via Vault, sends signed request, parses response, persists via `markSynced`. On failure: marks site `error`. On success: writes runtime info.

**Files:**
- Create: `packages/dashboard-plugin/src/Services/SyncService.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/SyncServiceTest.php` (NEW)

- [ ] **Step 1: Write the failing test**

Create `packages/dashboard-plugin/tests/Integration/Services/SyncServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Services\SyncService;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SyncServiceTest extends AbstractSchemaTestCase
{
    private function makeSite(): int
    {
        $repo  = new SitesRepository();
        $vault = new Vault(DEFYN_VAULT_KEY);
        $priv  = base64_encode(random_bytes(64));
        $id    = $repo->insertPending(1, 'https://site.test', 'Site', base64_encode(random_bytes(32)), $vault->encrypt($priv));
        $repo->markActive($id, base64_encode(random_bytes(32)));
        return $id;
    }

    public function test_successful_sync_persists_runtime_info(): void
    {
        $siteId = $this->makeSite();

        $mock = new MockHttpClient(function ($method, $url, $options) {
            $payload = [
                'wp_version'     => '6.7.1',
                'php_version'    => '8.2.18',
                'active_theme'   => ['name' => 'Theme', 'version' => '1.0', 'parent' => null],
                'plugin_counts'  => ['installed' => 10, 'active' => 5],
                'theme_counts'   => ['installed' => 2, 'active' => 1],
                'ssl_status'     => 'enabled',
                'ssl_expires_at' => null,
                'server_time'    => time(),
            ];
            return new MockResponse(json_encode($payload), ['http_code' => 200]);
        });

        (new SyncService(new SignedHttpClient($mock)))->sync($siteId);

        $site = (new SitesRepository())->findById($siteId);
        $this->assertSame('6.7.1', $site->wpVersion);
        $this->assertSame('active', $site->status);
        $this->assertSame('enabled', $site->sslStatus);
    }

    public function test_transport_error_marks_site_error_and_records_message(): void
    {
        $siteId = $this->makeSite();

        $mock = new MockHttpClient(function ($method, $url, $options) {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('connection refused');
        });

        (new SyncService(new SignedHttpClient($mock)))->sync($siteId);

        $site = (new SitesRepository())->findById($siteId);
        $this->assertSame('error', $site->status);
        $this->assertStringContainsString('connection refused', $site->lastError);
    }

    public function test_non_2xx_response_marks_site_error(): void
    {
        $siteId = $this->makeSite();

        $mock = new MockHttpClient(fn() => new MockResponse(
            json_encode(['error' => ['code' => 'connector.signature_invalid', 'message' => 'bad sig']]),
            ['http_code' => 401]
        ));

        (new SyncService(new SignedHttpClient($mock)))->sync($siteId);

        $site = (new SitesRepository())->findById($siteId);
        $this->assertSame('error', $site->status);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit --filter SyncServiceTest`
Expected: FAIL (class doesn't exist).

- [ ] **Step 3: Create SyncService**

Create `packages/dashboard-plugin/src/Services/SyncService.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Http\SignedHttpClient;

/**
 * Pull a site's `/status` snapshot and persist it. Failures mark the site as
 * status=error with the transport / response message; successes write all
 * runtime info via SitesRepository::markSynced.
 *
 * Vault key must be configured (constant DEFYN_VAULT_KEY) — same precondition
 * as F5 site creation.
 *
 * TODO (later phase): also write an activity log row (`site.synced` /
 * `site.sync_failed`). Activity log table is not yet defined; this service
 * establishes only the persistence behavior in F6.
 */
final class SyncService
{
    public function __construct(
        private readonly SignedHttpClient $httpClient = new SignedHttpClient(),
        private readonly ?SitesRepository $repo = null,
    ) {}

    public function sync(int $siteId): void
    {
        $repo = $this->repo ?? new SitesRepository();
        $site = $repo->findById($siteId);
        if ($site === null) {
            return;  // Site deleted between scheduling + execution — no-op.
        }

        $vault = new Vault(DEFYN_VAULT_KEY);
        try {
            $privateKey = $vault->decrypt($site->ourPrivateKey);
        } catch (\Throwable $e) {
            $repo->markError($siteId, 'Failed to decrypt site keypair.');
            return;
        }

        $url           = rtrim($site->url, '/') . '/wp-json/defyn-connector/v1/status';
        $canonicalPath = '/defyn-connector/v1/status';

        $response = $this->httpClient->signedGet($url, $privateKey, $canonicalPath);

        if ($response['error'] !== '') {
            $repo->markError($siteId, $response['error']);
            return;
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $repo->markError($siteId, 'Connector returned status ' . $response['status']);
            return;
        }

        $info = $response['body'];
        if (!isset($info['wp_version'], $info['php_version'])) {
            $repo->markError($siteId, 'Connector returned malformed /status payload.');
            return;
        }

        $repo->markSynced($siteId, $info);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit --filter SyncServiceTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Services/SyncService.php \
        packages/dashboard-plugin/tests/Integration/Services/SyncServiceTest.php
git commit -m "F6: dashboard — add SyncService (signed GET /status, persist via markSynced)"
```

---

### Task 14: `HealthService`

**Why:** Lightweight liveness check. Signed `GET /heartbeat`, updates `last_contact_at`. On any failure flips status to `offline` (new value) + records `last_error`. On success: if site was `offline`, flips back to `active`.

**Files:**
- Create: `packages/dashboard-plugin/src/Services/HealthService.php`
- Modify: `packages/dashboard-plugin/src/Services/SitesRepository.php` (add `markContactAt` + `markRecovered`)
- Test: `packages/dashboard-plugin/tests/Integration/Services/HealthServiceTest.php` (NEW)

- [ ] **Step 1: Write the failing test**

Create `packages/dashboard-plugin/tests/Integration/Services/HealthServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Services\HealthService;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HealthServiceTest extends AbstractSchemaTestCase
{
    private function makeActiveSite(): int
    {
        $repo  = new SitesRepository();
        $vault = new Vault(DEFYN_VAULT_KEY);
        $priv  = base64_encode(random_bytes(64));
        $id    = $repo->insertPending(1, 'https://site.test', 'Site', base64_encode(random_bytes(32)), $vault->encrypt($priv));
        $repo->markActive($id, base64_encode(random_bytes(32)));
        return $id;
    }

    public function test_successful_ping_advances_last_contact(): void
    {
        $siteId = $this->makeActiveSite();
        $before = (new SitesRepository())->findById($siteId)->lastContactAt;

        sleep(1);  // gmdate granularity is 1 second

        $mock = new MockHttpClient(fn() => new MockResponse(
            json_encode(['ok' => true, 'server_time' => time()]),
            ['http_code' => 200]
        ));

        (new HealthService(new SignedHttpClient($mock)))->ping($siteId);

        $after = (new SitesRepository())->findById($siteId)->lastContactAt;
        $this->assertNotSame($before, $after);
    }

    public function test_transport_failure_flips_to_offline(): void
    {
        $siteId = $this->makeActiveSite();

        $mock = new MockHttpClient(function ($method, $url, $options) {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('host unreachable');
        });

        (new HealthService(new SignedHttpClient($mock)))->ping($siteId);

        $site = (new SitesRepository())->findById($siteId);
        $this->assertSame('offline', $site->status);
        $this->assertStringContainsString('host unreachable', $site->lastError);
    }

    public function test_recovery_flips_offline_back_to_active(): void
    {
        $siteId = $this->makeActiveSite();
        (new SitesRepository())->markOffline($siteId, 'previously offline');

        $mock = new MockHttpClient(fn() => new MockResponse(
            json_encode(['ok' => true, 'server_time' => time()]),
            ['http_code' => 200]
        ));

        (new HealthService(new SignedHttpClient($mock)))->ping($siteId);

        $site = (new SitesRepository())->findById($siteId);
        $this->assertSame('active', $site->status);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit --filter HealthServiceTest`
Expected: FAIL (class doesn't exist).

- [ ] **Step 3: Add `markContactAt` + `markRecovered` helpers to SitesRepository**

In `packages/dashboard-plugin/src/Services/SitesRepository.php`, ADD:

```php
public function markContactAt(int $id): void
{
    global $wpdb;
    $now = gmdate('Y-m-d H:i:s');
    $wpdb->update(
        SitesTable::tableName(),
        ['last_contact_at' => $now, 'updated_at' => $now],
        ['id' => $id],
        ['%s', '%s'],
        ['%d'],
    );
}

public function markRecovered(int $id): void
{
    global $wpdb;
    $now = gmdate('Y-m-d H:i:s');
    $wpdb->update(
        SitesTable::tableName(),
        ['status' => 'active', 'last_error' => '', 'last_contact_at' => $now, 'updated_at' => $now],
        ['id' => $id],
        ['%s', '%s', '%s', '%s'],
        ['%d'],
    );
}
```

- [ ] **Step 4: Create HealthService**

Create `packages/dashboard-plugin/src/Services/HealthService.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Http\SignedHttpClient;

/**
 * Signed liveness probe against the connector's /heartbeat.
 *
 * Success: advances last_contact_at; if site was 'offline', flips back to
 * 'active' (recovery).
 * Failure (transport or non-2xx): flips status to 'offline' + records message.
 *
 * TODO (later phase): activity-log rows for site.health_ok / site.health_fail.
 */
final class HealthService
{
    public function __construct(
        private readonly SignedHttpClient $httpClient = new SignedHttpClient(),
        private readonly ?SitesRepository $repo = null,
    ) {}

    public function ping(int $siteId): void
    {
        $repo = $this->repo ?? new SitesRepository();
        $site = $repo->findById($siteId);
        if ($site === null) {
            return;
        }

        $vault = new Vault(DEFYN_VAULT_KEY);
        try {
            $privateKey = $vault->decrypt($site->ourPrivateKey);
        } catch (\Throwable $e) {
            $repo->markOffline($siteId, 'Failed to decrypt site keypair.');
            return;
        }

        $url           = rtrim($site->url, '/') . '/wp-json/defyn-connector/v1/heartbeat';
        $canonicalPath = '/defyn-connector/v1/heartbeat';

        $response = $this->httpClient->signedGet($url, $privateKey, $canonicalPath);

        if ($response['error'] !== '') {
            $repo->markOffline($siteId, $response['error']);
            return;
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $repo->markOffline($siteId, 'Connector returned status ' . $response['status']);
            return;
        }

        if ($site->status === 'offline') {
            $repo->markRecovered($siteId);
        } else {
            $repo->markContactAt($siteId);
        }
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit --filter HealthServiceTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add packages/dashboard-plugin/src/Services/HealthService.php \
        packages/dashboard-plugin/src/Services/SitesRepository.php \
        packages/dashboard-plugin/tests/Integration/Services/HealthServiceTest.php
git commit -m "F6: dashboard — add HealthService + markContactAt/markRecovered helpers"
```

---

### Task 15: AS jobs `SyncSite` + `HealthPing` + Plugin::boot wiring

**Why:** REST endpoints (Task 16) schedule background work via Action Scheduler — same pattern as F5's `CompleteConnection`. Two thin wrappers that delegate to `SyncService` / `HealthService`. Hooks registered in `Plugin::boot()`.

**Files:**
- Create: `packages/dashboard-plugin/src/Jobs/SyncSite.php`
- Create: `packages/dashboard-plugin/src/Jobs/HealthPing.php`
- Modify: `packages/dashboard-plugin/src/Plugin.php`
- Test: `packages/dashboard-plugin/tests/Integration/Jobs/SyncSiteTest.php` (NEW)
- Test: `packages/dashboard-plugin/tests/Integration/Jobs/HealthPingTest.php` (NEW)

- [ ] **Step 1: Write the failing tests**

Create `packages/dashboard-plugin/tests/Integration/Jobs/SyncSiteTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Jobs;

use Defyn\Dashboard\Jobs\SyncSite;
use Defyn\Dashboard\Services\SyncService;
use PHPUnit\Framework\TestCase;

final class SyncSiteTest extends TestCase
{
    public function test_hook_name_is_defyn_sync_site(): void
    {
        $this->assertSame('defyn_sync_site', SyncSite::HOOK);
    }

    public function test_handle_delegates_to_sync_service(): void
    {
        $captured = null;
        $fake = new class($captured) extends SyncService {
            public function __construct(private mixed &$captured) { /* no parent */ }
            public function sync(int $siteId): void { $this->captured = $siteId; }
        };

        (new SyncSite($fake))->handle(42);
        $this->assertSame(42, $captured);
    }
}
```

Create `packages/dashboard-plugin/tests/Integration/Jobs/HealthPingTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Jobs;

use Defyn\Dashboard\Jobs\HealthPing;
use Defyn\Dashboard\Services\HealthService;
use PHPUnit\Framework\TestCase;

final class HealthPingTest extends TestCase
{
    public function test_hook_name_is_defyn_health_ping(): void
    {
        $this->assertSame('defyn_health_ping', HealthPing::HOOK);
    }

    public function test_handle_delegates_to_health_service(): void
    {
        $captured = null;
        $fake = new class($captured) extends HealthService {
            public function __construct(private mixed &$captured) { /* no parent */ }
            public function ping(int $siteId): void { $this->captured = $siteId; }
        };

        (new HealthPing($fake))->handle(99);
        $this->assertSame(99, $captured);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit --filter "SyncSiteTest|HealthPingTest"`
Expected: FAIL (classes don't exist).

- [ ] **Step 3: Create SyncSite**

Create `packages/dashboard-plugin/src/Jobs/SyncSite.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Services\SyncService;

/**
 * Action Scheduler hook target. Plugin::boot() registers HOOK -> handle().
 * Tests inject a fake SyncService; production constructs a real one.
 */
final class SyncSite
{
    public const HOOK = 'defyn_sync_site';

    public function __construct(
        private readonly SyncService $service = new SyncService(),
    ) {}

    public function handle(int $siteId): void
    {
        $this->service->sync($siteId);
    }
}
```

- [ ] **Step 4: Create HealthPing**

Create `packages/dashboard-plugin/src/Jobs/HealthPing.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Services\HealthService;

final class HealthPing
{
    public const HOOK = 'defyn_health_ping';

    public function __construct(
        private readonly HealthService $service = new HealthService(),
    ) {}

    public function handle(int $siteId): void
    {
        $this->service->ping($siteId);
    }
}
```

- [ ] **Step 5: Register the AS hooks in Plugin::boot**

In `packages/dashboard-plugin/src/Plugin.php`, add inside `boot()` near the existing F5 `defyn_complete_connection` registration:

```php
add_action(\Defyn\Dashboard\Jobs\SyncSite::HOOK, function (int $siteId): void {
    (new \Defyn\Dashboard\Jobs\SyncSite())->handle($siteId);
}, 10, 1);

add_action(\Defyn\Dashboard\Jobs\HealthPing::HOOK, function (int $siteId): void {
    (new \Defyn\Dashboard\Jobs\HealthPing())->handle($siteId);
}, 10, 1);
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit --filter "SyncSiteTest|HealthPingTest"`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add packages/dashboard-plugin/src/Jobs/SyncSite.php \
        packages/dashboard-plugin/src/Jobs/HealthPing.php \
        packages/dashboard-plugin/src/Plugin.php \
        packages/dashboard-plugin/tests/Integration/Jobs/SyncSiteTest.php \
        packages/dashboard-plugin/tests/Integration/Jobs/HealthPingTest.php
git commit -m "F6: dashboard — add SyncSite + HealthPing AS jobs and wire hooks in Plugin::boot"
```

---

### Task 16: Dashboard REST endpoints `POST /sites/{id}/sync` and `POST /sites/{id}/ping`

**Why:** SPA-callable triggers for sync + health-ping. Both Bearer-authenticated, user-scoped (404 if not owner), schedule the AS job immediately, return 202. Same pattern as F5's site creation flow.

**Files:**
- Create: `packages/dashboard-plugin/src/Rest/SitesSyncController.php`
- Create: `packages/dashboard-plugin/src/Rest/SitesPingController.php`
- Modify: `packages/dashboard-plugin/src/Rest/RestRouter.php`
- Test: `packages/dashboard-plugin/tests/Integration/Rest/SitesSyncTest.php` (NEW)
- Test: `packages/dashboard-plugin/tests/Integration/Rest/SitesPingTest.php` (NEW)

- [ ] **Step 1: Write the failing test for sync route**

Create `packages/dashboard-plugin/tests/Integration/Rest/SitesSyncTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Jobs\SyncSite;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AuthenticatedRestTestCase;

final class SitesSyncTest extends AuthenticatedRestTestCase
{
    public function test_owner_request_schedules_sync_job_returns_202(): void
    {
        $repo  = new SitesRepository();
        $vault = new Vault(DEFYN_VAULT_KEY);
        $id    = $repo->insertPending($this->authenticatedUserId, 'https://x.test', 'X', base64_encode(random_bytes(32)), $vault->encrypt(base64_encode(random_bytes(64))));
        $repo->markActive($id, base64_encode(random_bytes(32)));

        $response = $this->postJsonAuthenticated("/defyn/v1/sites/{$id}/sync", []);

        $this->assertSame(202, $response['status']);
        if (function_exists('as_next_scheduled_action')) {
            $this->assertNotFalse(as_next_scheduled_action(SyncSite::HOOK, [$id], 'defyn'));
        }
    }

    public function test_non_owner_returns_404(): void
    {
        $repo  = new SitesRepository();
        $vault = new Vault(DEFYN_VAULT_KEY);
        $otherUser = self::factory()->user->create();
        $id = $repo->insertPending($otherUser, 'https://x.test', 'X', base64_encode(random_bytes(32)), $vault->encrypt(base64_encode(random_bytes(64))));

        $response = $this->postJsonAuthenticated("/defyn/v1/sites/{$id}/sync", []);
        $this->assertSame(404, $response['status']);
    }
}
```

- [ ] **Step 2: Write the failing test for ping route**

Create `packages/dashboard-plugin/tests/Integration/Rest/SitesPingTest.php` (same shape as SitesSyncTest, swapping `sync` → `ping` and `SyncSite::HOOK` → `HealthPing::HOOK`):

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Jobs\HealthPing;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AuthenticatedRestTestCase;

final class SitesPingTest extends AuthenticatedRestTestCase
{
    public function test_owner_request_schedules_ping_job_returns_202(): void
    {
        $repo  = new SitesRepository();
        $vault = new Vault(DEFYN_VAULT_KEY);
        $id    = $repo->insertPending($this->authenticatedUserId, 'https://x.test', 'X', base64_encode(random_bytes(32)), $vault->encrypt(base64_encode(random_bytes(64))));
        $repo->markActive($id, base64_encode(random_bytes(32)));

        $response = $this->postJsonAuthenticated("/defyn/v1/sites/{$id}/ping", []);

        $this->assertSame(202, $response['status']);
        if (function_exists('as_next_scheduled_action')) {
            $this->assertNotFalse(as_next_scheduled_action(HealthPing::HOOK, [$id], 'defyn'));
        }
    }

    public function test_non_owner_returns_404(): void
    {
        $repo  = new SitesRepository();
        $vault = new Vault(DEFYN_VAULT_KEY);
        $otherUser = self::factory()->user->create();
        $id = $repo->insertPending($otherUser, 'https://x.test', 'X', base64_encode(random_bytes(32)), $vault->encrypt(base64_encode(random_bytes(64))));

        $response = $this->postJsonAuthenticated("/defyn/v1/sites/{$id}/ping", []);
        $this->assertSame(404, $response['status']);
    }
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit --filter "SitesSyncTest|SitesPingTest"`
Expected: FAIL (routes don't exist).

- [ ] **Step 4: Create SitesSyncController**

Create `packages/dashboard-plugin/src/Rest/SitesSyncController.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Jobs\SyncSite;
use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

final class SitesSyncController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');

        $site = (new SitesRepository())->findByIdForUser($siteId, $userId);
        if ($site === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }

        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time(), SyncSite::HOOK, [$siteId], 'defyn');
        }

        return new WP_REST_Response(['site_id' => $siteId, 'scheduled' => true], 202);
    }
}
```

- [ ] **Step 5: Create SitesPingController**

Create `packages/dashboard-plugin/src/Rest/SitesPingController.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Jobs\HealthPing;
use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

final class SitesPingController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');

        $site = (new SitesRepository())->findByIdForUser($siteId, $userId);
        if ($site === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }

        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time(), HealthPing::HOOK, [$siteId], 'defyn');
        }

        return new WP_REST_Response(['site_id' => $siteId, 'scheduled' => true], 202);
    }
}
```

- [ ] **Step 6: Register the two new routes in RestRouter**

In `packages/dashboard-plugin/src/Rest/RestRouter.php`, ADD inside `register()`:

```php
register_rest_route(self::NAMESPACE, '/sites/(?P<id>\d+)/sync', [
    'methods'             => 'POST',
    'callback'            => [new SitesSyncController(), 'handle'],
    'permission_callback' => [RequireAuth::class, 'check'],
]);

register_rest_route(self::NAMESPACE, '/sites/(?P<id>\d+)/ping', [
    'methods'             => 'POST',
    'callback'            => [new SitesPingController(), 'handle'],
    'permission_callback' => [RequireAuth::class, 'check'],
]);
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit --filter "SitesSyncTest|SitesPingTest"`
Expected: PASS

- [ ] **Step 8: Run full dashboard suite**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit`
Expected: All tests pass.

- [ ] **Step 9: Commit**

```bash
git add packages/dashboard-plugin/src/Rest/SitesSyncController.php \
        packages/dashboard-plugin/src/Rest/SitesPingController.php \
        packages/dashboard-plugin/src/Rest/RestRouter.php \
        packages/dashboard-plugin/tests/Integration/Rest/SitesSyncTest.php \
        packages/dashboard-plugin/tests/Integration/Rest/SitesPingTest.php
git commit -m "F6: dashboard — add POST /sites/{id}/sync and /ping REST endpoints"
```

---

### Task 17: Update both plugin READMEs

**Why:** Document new endpoints, signing protocol, error codes, AS hooks. Same pattern as F5 closeout.

**Files:**
- Modify: `packages/connector-plugin/README.md`
- Modify: `packages/dashboard-plugin/README.md`

- [ ] **Step 1: Update connector README**

Add a "F6: Signed endpoints" section to `packages/connector-plugin/README.md` documenting:

- The three new endpoints: `GET /defyn-connector/v1/status`, `GET /defyn-connector/v1/heartbeat`, `POST /defyn-connector/v1/disconnect`
- The signing protocol: X-Defyn-Timestamp + X-Defyn-Nonce + X-Defyn-Signature headers + canonical-string format per spec § 5.2
- Verification rules: ±300s timestamp window, 10-min nonce TTL, requires state=connected
- New error codes: `connector.signature_missing`, `connector.signature_expired`, `connector.signature_replay`, `connector.signature_invalid`, `connector.not_connected`, `connector.signing_failed`
- The new `connected` admin UI branch (F5 carry-forward 1)
- The new `Crypto\NonceStore` + `TransientNonceStore` and extended `Crypto\Signer::canonical()` + `verifyRequest()`

- [ ] **Step 2: Update dashboard README**

Add a "F6: Sync + Health" section to `packages/dashboard-plugin/README.md` documenting:

- The two new REST endpoints: `POST /defyn/v1/sites/{id}/sync`, `POST /defyn/v1/sites/{id}/ping`
- The two new services: `SyncService` (signed GET /status → markSynced), `HealthService` (signed GET /heartbeat → markContactAt / markOffline / markRecovered)
- The two new AS hooks: `defyn_sync_site`, `defyn_health_ping`
- The `SignedHttpClient` upgrade: `signedGet()` + `signedPostJson()` now implement spec § 5.2 signing using each site's decrypted private key
- New error codes: `sites.invalid_code` (F5 carry-forward 3), `sites.not_found` (sync/ping route guards)
- The new `offline` status value (no migration needed — fits existing VARCHAR(20))

- [ ] **Step 3: Commit**

```bash
git add packages/connector-plugin/README.md packages/dashboard-plugin/README.md
git commit -m "F6: docs — README updates for signed status/heartbeat and admin UI changes"
```

---

### Task 18: Manual smoke + merge

**Why:** Verify the full F6 flow end-to-end against the live local stack (Local-by-Flywheel site at `http://localhost:10139/`), then merge `f6-signed-status-heartbeat` into `main`.

**Pre-checks:**
- F5 smoke state preserved: connector + dashboard in `connected` state, site row exists with `our_private_key` (Vault-encrypted), connector has `dashboard_public_key=x8QDMvXHg7C+XDUjCYirwSftrTHAvUrUC4c7DmIbVmU=` (the test fixture from F5 smoke).
- MySQL up; WP-CLI reachable via Local's bundled PHP with the `mysqli.default_socket` override.
- Test user's Bearer JWT available (from F5 smoke or fresh `POST /defyn/v1/auth/login`).

- [ ] **Step 1: Verify F5 state still loads**

Run: `wp db query 'SELECT id, url, status, our_public_key FROM wp_defyn_sites' --skip-column-names` (using the Local bundled-PHP override).
Expected: Site row from F5 with status=active.

- [ ] **Step 2: Trigger sync via REST**

```bash
SITE_ID=<id-from-step-1>
JWT=<bearer-from-f5-smoke-or-fresh-login>
curl -sS -X POST "http://localhost:10139/wp-json/defyn/v1/sites/$SITE_ID/sync" \
  -H "Authorization: Bearer $JWT" \
  -H "Content-Type: application/json" \
  -d '{}'
```
Expected output: `{"site_id":<id>,"scheduled":true}` with HTTP 202.

- [ ] **Step 3: Run the AS queue + verify persistence**

Run: `wp action-scheduler run --group=defyn --batch-size=5`
Then: `wp db query 'SELECT wp_version, php_version, ssl_status, last_sync_at FROM wp_defyn_sites WHERE id=$SITE_ID'`
Expected: `wp_version`, `php_version`, `ssl_status`, `last_sync_at` all populated.

- [ ] **Step 4: Trigger heartbeat ping**

```bash
curl -sS -X POST "http://localhost:10139/wp-json/defyn/v1/sites/$SITE_ID/ping" \
  -H "Authorization: Bearer $JWT" \
  -H "Content-Type: application/json" -d '{}'
```
Expected: `{"site_id":<id>,"scheduled":true}` HTTP 202. After AS runs (`wp action-scheduler run --group=defyn`), `last_contact_at` advances.

- [ ] **Step 5: Negative test — malformed signed request to connector**

```bash
curl -i "http://localhost:10139/wp-json/defyn-connector/v1/heartbeat" \
  -H "X-Defyn-Timestamp: 1" \
  -H "X-Defyn-Nonce: nope" \
  -H "X-Defyn-Signature: invalid"
```
Expected: HTTP 401 with body `{"error":{"code":"connector.signature_expired","message":"..."}}` (timestamp 1 is far outside the window).

- [ ] **Step 6: Verify the F5 carry-forward 1 fix in admin UI**

Open `http://localhost:10139/wp-admin/options-general.php?page=defyn-connector` in browser.
Expected:
- Dashboard key fingerprint shown (first 12 chars of `x8QDMvXHg7C+...`)
- Connected timestamp shown
- "Disconnect" button visible (NOT "Generate Connection Code")

- [ ] **Step 7: Run both plugin test suites one last time**

```bash
cd packages/connector-plugin && vendor/bin/phpunit && cd ../..
cd packages/dashboard-plugin && vendor/bin/phpunit && cd ../..
```
Expected: All green.

- [ ] **Step 8: Open PR + merge**

```bash
git push -u origin f6-signed-status-heartbeat
gh pr create --title "F6: Signed /status + /heartbeat" --body "$(cat <<'EOF'
## Summary
- Three new connector REST endpoints (signed): GET /status, GET /heartbeat, POST /disconnect
- VerifySignatureMiddleware enforcing spec § 5.2 (Ed25519 + ±300s window + nonce replay)
- Connector NonceStore (WP transient backed) and extended Signer (canonical + verifyRequest)
- SiteInfo Collector assembling the /status payload
- Dashboard SignedHttpClient upgraded with signedGet + signedPostJson
- Dashboard SyncService + HealthService + AS jobs (defyn_sync_site, defyn_health_ping)
- Two new dashboard REST endpoints: POST /sites/{id}/sync, POST /sites/{id}/ping
- F5 carry-forwards: ConnectController signing try/catch, SitesCreateController 12-char code length, SettingsPage `connected` render branch

## Test plan
- [x] All connector tests pass
- [x] All dashboard tests pass
- [x] Manual smoke: signed sync + heartbeat against live local stack
- [x] Manual smoke: malformed signed request returns spec § 9.1 envelope
- [x] Manual smoke: admin Settings page shows `connected` branch
EOF
)"
# After CI / review approval:
gh pr merge --merge
```

- [ ] **Step 9: Verify post-merge state**

```bash
git checkout main && git pull
git log --oneline -3
```
Expected: Top commit is the F6 merge.

---

## Self-Review Checklist

After all 18 tasks complete, the controller (you) should verify:

- [ ] **Spec coverage:** Every spec § 5 endpoint, § 5.2 signing rule, § 6.3 sync/health service, § 11 deliverable maps to a task.
- [ ] **No placeholders:** No TBD / TODO / "fill in later" / "similar to Task N" anywhere.
- [ ] **Type consistency:** Method names (`signedGet`, `signedPostJson`, `markSynced`, `markOffline`, `markContactAt`, `markRecovered`) match across tasks. Constant names (`SyncSite::HOOK`, `HealthPing::HOOK`) consistent.
- [ ] **Two-plugin duplication intentional:** Connector and dashboard maintain separate `Crypto\Signer`, `Crypto\NonceStore`, `Crypto\VerificationResult` — same shape, independent code.
- [ ] **F5 carry-forwards landed first:** Tasks 1-3 ship the fixes that F6 builds on (UI safety, signing safety, validation safety).
