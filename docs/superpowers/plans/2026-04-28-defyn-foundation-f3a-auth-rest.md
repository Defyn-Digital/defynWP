# DefynWP Foundation F3a — Auth REST Backend Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Four REST endpoints (`POST /auth/login`, `POST /auth/refresh`, `POST /auth/logout`, `GET /auth/me`) on the existing `Defyn\Dashboard` WP plugin, with JWT access tokens (15-min TTL), rotating refresh tokens (30-day TTL stored in user_meta as JTI list), per-IP rate limiting on login, and CORS allowing the SPA origin. End state: a curl-driven test can log in, get an access token, hit `/me`, refresh, and log out — all backed by WP's own `wp_users` table.

**Architecture:** JWT auth (HS256, secret in env) backed by WP user system. Access tokens are stateless 15-min JWTs; refresh tokens are JWTs whose JTI is also tracked in `user_meta` (`defyn_refresh_jtis`) so revocation works. Refresh rotation: every refresh issues a new pair, old refresh JTI removed from user_meta. Rate limiter uses WP transients keyed by IP. CORS middleware fronts every `defyn/v1/*` route.

**Tech Stack:** PHP 7.4+ · WordPress REST API · firebase/php-jwt · PHPUnit + wp-phpunit (existing F1 harness) · WP transients for rate limiting · `wp_check_password` for credential validation

---

## About this plan

This is **F3a of the F3 split** (F3a = backend auth REST; F3b = SPA scaffold + login page, separate plan after F3a ships). Built on F1 + F2 (both on main).

**Source spec:** [`docs/superpowers/specs/2026-04-18-defyn-foundation-design.md`](../specs/2026-04-18-defyn-foundation-design.md) — § 6.1 (REST API) and § 6.2 (auth model).

**Definition of "F3a done":**
1. `POST /wp-json/defyn/v1/auth/login` accepts `{email, password}`, validates against `wp_users` via `wp_check_password`, returns 200 with `{access_token}` body + `Set-Cookie: defyn_refresh=<jwt>; HttpOnly; Secure; Path=/wp-json/defyn/v1/auth; SameSite=None`. Bad creds → 401. Rate-limited (5/min/IP) → 429.
2. `POST /wp-json/defyn/v1/auth/refresh` reads refresh JWT from cookie, validates JTI is in user's `defyn_refresh_jtis` list, returns new access token + Set-Cookie with rotated refresh JWT, removes old JTI, adds new JTI. Invalid/revoked refresh → 401.
3. `POST /wp-json/defyn/v1/auth/logout` reads refresh JWT from cookie, removes its JTI from user_meta, returns 204 + Set-Cookie clearing the refresh cookie.
4. `GET /wp-json/defyn/v1/auth/me` requires `Authorization: Bearer <access>`, returns `{id, email, display_name}` for the authenticated user. Missing/invalid token → 401.
5. CORS: every `defyn/v1/*` response includes `Access-Control-Allow-Origin: <configured>` + `Access-Control-Allow-Credentials: true`. OPTIONS preflight returns 204 with `Access-Control-Allow-Methods` + `Access-Control-Allow-Headers: Authorization, Content-Type`.
6. Full PHPUnit suite passes (unit + integration ~30 new tests on top of F1+F2's 50 = ~80 total).
7. CI green on PHP 7.4 + 8.2.

---

## File structure after F3a

```
packages/dashboard-plugin/
├── composer.json                                  # MODIFIED — add firebase/php-jwt
├── defyn-dashboard.php                            # MODIFIED — add jwt secret env loading
├── src/
│   ├── Plugin.php                                 # MODIFIED — wire REST routes
│   ├── Activation.php                             # unchanged from F2
│   ├── Auth/                                      # NEW
│   │   ├── TokenService.php                       # encode/decode JWTs
│   │   ├── RefreshTokenStore.php                  # user_meta-backed JTI list
│   │   ├── PasswordVerifier.php                   # wraps wp_authenticate / wp_check_password
│   │   ├── AuthenticatedUser.php                  # value object
│   │   └── Exceptions/
│   │       ├── InvalidTokenException.php
│   │       └── InvalidCredentialsException.php
│   ├── Rest/                                      # NEW
│   │   ├── RestRouter.php                         # registers all routes via rest_api_init
│   │   ├── AuthLoginController.php
│   │   ├── AuthRefreshController.php
│   │   ├── AuthLogoutController.php
│   │   ├── AuthMeController.php
│   │   ├── Middleware/
│   │   │   ├── RateLimit.php                      # transient-backed IP counter
│   │   │   ├── Cors.php                           # CORS headers + preflight
│   │   │   └── RequireAuth.php                    # bearer token validator
│   │   └── Responses/
│   │       └── ErrorResponse.php                  # consistent {error: {code, message}} shape
│   ├── Crypto/                                    # unchanged from F2
│   └── Schema/                                    # unchanged from F1
└── tests/
    ├── Unit/
    │   └── Auth/
    │       ├── TokenServiceTest.php
    │       └── RefreshTokenStoreTest.php          # uses real wpdb via wp-phpunit
    └── Integration/
        └── Rest/
            ├── AuthLoginTest.php
            ├── AuthRefreshTest.php
            ├── AuthLogoutTest.php
            ├── AuthMeTest.php
            └── CorsAndRateLimitTest.php
```

---

## Prerequisites

- F1 + F2 merged to main; on branch `f3a-auth` (already checked out)
- PHP 8.5.5 on PATH; Composer 2.9.7 on PATH
- Local MySQL up on 127.0.0.1:10140; `defyn_test` DB ready

Verify before starting:

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && /usr/local/bin/php ./vendor/bin/phpunit 2>&1 | tail -3
```

Expected: `OK (50 tests, 107 assertions)` from F2 baseline.

---

## Tasks

### Task 1: Add `firebase/php-jwt` + `defyn_jwt_secret` env loading

**Why first:** every later task uses JWT encode/decode. Add the dep and the secret-loading mechanism before any code consumes them.

**Files:**
- Modify: `packages/dashboard-plugin/composer.json` (require firebase/php-jwt)
- Modify: `packages/dashboard-plugin/defyn-dashboard.php` (define jwt secret constant from env)

- [ ] **Step 1: Add the dep**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && /Users/pradeep/.local/bin/composer require firebase/php-jwt:^6.0
```

> firebase/php-jwt v6.x supports PHP 7.4+ (the package itself uses no 8.0 syntax until v6.10). If composer resolves to a version that complains about PHP 7.4, pin to `^6.0 <6.10` instead.

Expected: 1 package added, vendor updated, composer.lock changed.

- [ ] **Step 2: Add JWT secret loading to plugin bootstrap**

Read the current `defyn-dashboard.php` first, then edit. Insert this block AFTER the `require_once $autoload;` line and BEFORE the `define('DEFYN_DASHBOARD_VERSION'...)` line:

```php
// JWT secret: required for auth REST endpoints in F3a+. Loaded from environment
// (Bedrock's .env in production; wp-config.php define() in plain WP).
// Plugin still loads if absent — we only fatal at the auth endpoints, with a
// clear admin-notice fallback so the operator can fix the config without losing
// access to wp-admin.
if (!defined('DEFYN_JWT_SECRET')) {
    $envSecret = getenv('DEFYN_JWT_SECRET');
    if ($envSecret !== false && $envSecret !== '') {
        define('DEFYN_JWT_SECRET', $envSecret);
    }
}
```

Then, BELOW the existing `define('DEFYN_DASHBOARD_DIR', __DIR__);` line, ADD:

```php
// CORS: allow the SPA origin. Override via env (DEFYN_SPA_ORIGIN) for prod.
if (!defined('DEFYN_SPA_ORIGIN')) {
    $envOrigin = getenv('DEFYN_SPA_ORIGIN');
    define('DEFYN_SPA_ORIGIN', ($envOrigin !== false && $envOrigin !== '') ? $envOrigin : 'http://localhost:5173');
}
```

(The default `http://localhost:5173` is Vite's dev server. Production sets this via `DEFYN_SPA_ORIGIN=https://app.defyn.dev` in Bedrock's `.env`.)

- [ ] **Step 3: Verify the existing test suite still passes**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && /usr/local/bin/php ./vendor/bin/phpunit 2>&1 | tail -3
```

Expected: still `OK (50 tests, 107 assertions)`. The dep and constants are inert until later tasks consume them.

- [ ] **Step 4: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git add packages/dashboard-plugin/composer.json packages/dashboard-plugin/composer.lock packages/dashboard-plugin/defyn-dashboard.php && git commit -m "$(cat <<'EOF'
F3a: add firebase/php-jwt + DEFYN_JWT_SECRET / DEFYN_SPA_ORIGIN env loading

JWT lib for the four auth endpoints. Constants loaded from env so
production sets via Bedrock .env (DEFYN_JWT_SECRET, DEFYN_SPA_ORIGIN);
local dev defaults to a Vite localhost:5173 SPA origin and requires
DEFYN_JWT_SECRET to be set explicitly.

Plugin still loads if DEFYN_JWT_SECRET is missing — fatal happens at
auth endpoints only, with admin-notice fallback so wp-admin stays
reachable.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: TDD `TokenService` — JWT encode/decode/expiry

**Why:** the only class that knows about JWT internals. Pure unit-testable (no WP, no DB).

**Files:**
- Create: `packages/dashboard-plugin/src/Auth/TokenService.php`
- Create: `packages/dashboard-plugin/src/Auth/Exceptions/InvalidTokenException.php`
- Create: `packages/dashboard-plugin/tests/Unit/Auth/TokenServiceTest.php`

- [ ] **Step 1: Write the failing test**

Write `packages/dashboard-plugin/tests/Unit/Auth/TokenServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Unit\Auth;

use Defyn\Dashboard\Auth\Exceptions\InvalidTokenException;
use Defyn\Dashboard\Auth\TokenService;
use PHPUnit\Framework\TestCase;

final class TokenServiceTest extends TestCase
{
    private const SECRET = 'unit-test-secret-32-chars-minimum';

    public function testIssueAccessProducesDecodableToken(): void
    {
        $svc = new TokenService(self::SECRET);
        $token = $svc->issueAccess(42);

        $claims = $svc->decode($token);

        self::assertSame(42, $claims['sub']);
        self::assertSame('access', $claims['typ']);
    }

    public function testIssueRefreshIncludesUniqueJti(): void
    {
        $svc = new TokenService(self::SECRET);
        $a = $svc->decode($svc->issueRefresh(42));
        $b = $svc->decode($svc->issueRefresh(42));

        self::assertNotSame($a['jti'], $b['jti'], 'each refresh must have a unique JTI');
        self::assertSame('refresh', $a['typ']);
    }

    public function testAccessTokenExpiresIn15Minutes(): void
    {
        $svc = new TokenService(self::SECRET);
        $now = 1_700_000_000;
        $token = $svc->issueAccess(42, $now);
        $claims = $svc->decode($token);

        self::assertSame($now + 15 * 60, $claims['exp']);
    }

    public function testRefreshTokenExpiresIn30Days(): void
    {
        $svc = new TokenService(self::SECRET);
        $now = 1_700_000_000;
        $token = $svc->issueRefresh(42, $now);
        $claims = $svc->decode($token);

        self::assertSame($now + 30 * 24 * 60 * 60, $claims['exp']);
    }

    public function testDecodeRejectsTokenSignedWithDifferentSecret(): void
    {
        $svcA = new TokenService('secret-a-32-bytes-minimum-padding');
        $svcB = new TokenService('secret-b-32-bytes-minimum-padding');
        $token = $svcA->issueAccess(42);

        $this->expectException(InvalidTokenException::class);
        $svcB->decode($token);
    }

    public function testDecodeRejectsExpiredToken(): void
    {
        $svc = new TokenService(self::SECRET);
        $past = 1_700_000_000;
        $token = $svc->issueAccess(42, $past);

        // Decode 1 hour later; access TTL is 15 min so this is well past.
        $this->expectException(InvalidTokenException::class);
        $svc->decode($token, $past + 3600);
    }

    public function testDecodeRejectsMalformedToken(): void
    {
        $svc = new TokenService(self::SECRET);

        $this->expectException(InvalidTokenException::class);
        $svc->decode('not.a.jwt');
    }

    public function testConstructorRejectsShortSecret(): void
    {
        // HS256 requires at least 32 bytes of secret entropy.
        $this->expectException(\InvalidArgumentException::class);
        new TokenService('too-short');
    }
}
```

- [ ] **Step 2: Run — verify FAIL**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && /usr/local/bin/php ./vendor/bin/phpunit --testsuite unit --filter TokenServiceTest 2>&1 | tail -10
```

Expected: 8 errors — class not found.

- [ ] **Step 3: Write the InvalidTokenException class**

Write `packages/dashboard-plugin/src/Auth/Exceptions/InvalidTokenException.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Auth\Exceptions;

use RuntimeException;

/**
 * Thrown when a JWT cannot be decoded — invalid signature, malformed structure,
 * expired, or wrong claim type. Caller maps to HTTP 401.
 */
final class InvalidTokenException extends RuntimeException
{
}
```

- [ ] **Step 4: Write TokenService**

Write `packages/dashboard-plugin/src/Auth/TokenService.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Auth;

use Defyn\Dashboard\Auth\Exceptions\InvalidTokenException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use InvalidArgumentException;
use Throwable;

/**
 * Issues and decodes JWT access + refresh tokens (HS256).
 *
 * Pure unit — no WP, no DB. RefreshTokenStore handles per-user JTI persistence.
 *
 * Token shapes:
 *   access:  { sub: int (user_id), typ: 'access',  iat: int, exp: int }   TTL 15 min
 *   refresh: { sub: int (user_id), typ: 'refresh', iat: int, exp: int, jti: string }   TTL 30 days
 */
final class TokenService
{
    public const ACCESS_TTL_SECONDS  = 15 * 60;
    public const REFRESH_TTL_SECONDS = 30 * 24 * 60 * 60;
    public const TYPE_ACCESS         = 'access';
    public const TYPE_REFRESH        = 'refresh';

    private const ALG = 'HS256';

    /** @var string */
    private $secret;

    public function __construct(string $secret)
    {
        if (strlen($secret) < 32) {
            throw new InvalidArgumentException('JWT secret must be at least 32 bytes.');
        }
        $this->secret = $secret;
    }

    public function issueAccess(int $userId, ?int $now = null): string
    {
        $now = $now ?? time();
        return JWT::encode([
            'sub' => $userId,
            'typ' => self::TYPE_ACCESS,
            'iat' => $now,
            'exp' => $now + self::ACCESS_TTL_SECONDS,
        ], $this->secret, self::ALG);
    }

    public function issueRefresh(int $userId, ?int $now = null): string
    {
        $now = $now ?? time();
        return JWT::encode([
            'sub' => $userId,
            'typ' => self::TYPE_REFRESH,
            'iat' => $now,
            'exp' => $now + self::REFRESH_TTL_SECONDS,
            'jti' => self::generateJti(),
        ], $this->secret, self::ALG);
    }

    /**
     * Decode a token. Returns claims as an associative array.
     *
     * @throws InvalidTokenException on malformed, bad-signature, or expired token.
     */
    public function decode(string $token, ?int $now = null): array
    {
        if ($now !== null) {
            JWT::$timestamp = $now;
        }
        try {
            $decoded = (array) JWT::decode($token, new Key($this->secret, self::ALG));
        } catch (Throwable $e) {
            throw new InvalidTokenException($e->getMessage(), 0, $e);
        } finally {
            JWT::$timestamp = null;  // restore real-clock decoding for other callers
        }

        return $decoded;
    }

    private static function generateJti(): string
    {
        // 16 random bytes hex = 32-char unique-enough ID. Not cryptographically
        // sensitive (signature provides authenticity) — just unique.
        return bin2hex(random_bytes(16));
    }
}
```

- [ ] **Step 5: Run — verify GREEN**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && /usr/local/bin/php ./vendor/bin/phpunit --testsuite unit --filter TokenServiceTest 2>&1 | tail -10
```

Expected: `OK (8 tests, 9+ assertions)`.

- [ ] **Step 6: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git add packages/dashboard-plugin/src/Auth/TokenService.php packages/dashboard-plugin/src/Auth/Exceptions/InvalidTokenException.php packages/dashboard-plugin/tests/Unit/Auth/TokenServiceTest.php && git commit -m "$(cat <<'EOF'
F3a: TDD Auth/TokenService — JWT encode/decode for access + refresh

HS256 with 32-byte minimum secret. Access TTL 15 min, refresh TTL
30 days, refresh tokens carry a unique JTI for revocation. Decode
throws InvalidTokenException on bad signature, malformed structure,
or expiry — caller maps to HTTP 401.

Pure unit — no WP, no DB. RefreshTokenStore (next task) handles
JTI persistence in user_meta.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: TDD `RefreshTokenStore` — JTI list in user_meta

**Why:** revocation requires us to remember which refresh JTIs are still "active" per user. `user_meta` is the simplest store (no new schema/migration), and at 1-5 users with ≤10 active devices each, the size is trivial.

**Files:**
- Create: `packages/dashboard-plugin/src/Auth/RefreshTokenStore.php`
- Create: `packages/dashboard-plugin/tests/Integration/Auth/RefreshTokenStoreTest.php`

- [ ] **Step 1: Write the failing test**

Write `packages/dashboard-plugin/tests/Integration/Auth/RefreshTokenStoreTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Auth;

use Defyn\Dashboard\Auth\RefreshTokenStore;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class RefreshTokenStoreTest extends WP_UnitTestCase
{
    public function testRememberAddsJtiForUser(): void
    {
        $userId = self::factory()->user->create();
        $store = new RefreshTokenStore();

        $store->remember($userId, 'jti-1', time() + 3600);

        self::assertTrue($store->isActive($userId, 'jti-1'));
    }

    public function testIsActiveReturnsFalseForUnknownJti(): void
    {
        $userId = self::factory()->user->create();
        $store = new RefreshTokenStore();

        self::assertFalse($store->isActive($userId, 'unknown-jti'));
    }

    public function testRevokeRemovesJti(): void
    {
        $userId = self::factory()->user->create();
        $store = new RefreshTokenStore();

        $store->remember($userId, 'jti-1', time() + 3600);
        $store->revoke($userId, 'jti-1');

        self::assertFalse($store->isActive($userId, 'jti-1'));
    }

    public function testIsActiveReturnsFalseForExpiredJti(): void
    {
        $userId = self::factory()->user->create();
        $store = new RefreshTokenStore();

        $store->remember($userId, 'jti-1', time() - 1);  // already expired

        self::assertFalse($store->isActive($userId, 'jti-1'));
    }

    public function testJtisAreScopedPerUser(): void
    {
        $u1 = self::factory()->user->create();
        $u2 = self::factory()->user->create();
        $store = new RefreshTokenStore();

        $store->remember($u1, 'jti-shared', time() + 3600);

        self::assertTrue($store->isActive($u1, 'jti-shared'));
        self::assertFalse($store->isActive($u2, 'jti-shared'), 'JTIs are scoped per user');
    }

    public function testRememberMultipleJtisForSameUser(): void
    {
        $userId = self::factory()->user->create();
        $store = new RefreshTokenStore();

        $store->remember($userId, 'jti-1', time() + 3600);
        $store->remember($userId, 'jti-2', time() + 3600);
        $store->remember($userId, 'jti-3', time() + 3600);

        self::assertTrue($store->isActive($userId, 'jti-1'));
        self::assertTrue($store->isActive($userId, 'jti-2'));
        self::assertTrue($store->isActive($userId, 'jti-3'));
    }
}
```

- [ ] **Step 2: Run — verify FAIL**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && /usr/local/bin/php ./vendor/bin/phpunit --testsuite integration --filter RefreshTokenStoreTest 2>&1 | tail -10
```

Expected: 6 errors — class not found.

- [ ] **Step 3: Implement**

Write `packages/dashboard-plugin/src/Auth/RefreshTokenStore.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Auth;

/**
 * Tracks which refresh-token JTIs are still active per user.
 *
 * Storage: a single `defyn_refresh_jtis` user_meta key per user, holding a JSON
 * array of `[jti, expires_at]` tuples. Expired entries are swept on every read.
 *
 * Why user_meta and not a custom table:
 *   - Foundation is single-tenant — a handful of users with ≤10 devices each.
 *   - No schema migration risk this phase. F4+ may add a custom table if scale demands.
 */
final class RefreshTokenStore
{
    public const META_KEY = 'defyn_refresh_jtis';

    public function remember(int $userId, string $jti, int $expiresAt): void
    {
        $list = $this->loadAndPrune($userId);
        $list[] = ['jti' => $jti, 'expires_at' => $expiresAt];
        update_user_meta($userId, self::META_KEY, $list);
    }

    public function isActive(int $userId, string $jti): bool
    {
        $list = $this->loadAndPrune($userId);
        foreach ($list as $entry) {
            if ($entry['jti'] === $jti) {
                return true;
            }
        }
        return false;
    }

    public function revoke(int $userId, string $jti): void
    {
        $list = $this->loadAndPrune($userId);
        $list = array_values(array_filter($list, static function ($entry) use ($jti) {
            return $entry['jti'] !== $jti;
        }));
        update_user_meta($userId, self::META_KEY, $list);
    }

    /**
     * Read the user's JTI list, drop expired entries, persist the pruned list back.
     *
     * @return array<int, array{jti: string, expires_at: int}>
     */
    private function loadAndPrune(int $userId): array
    {
        $raw = get_user_meta($userId, self::META_KEY, true);
        if (!is_array($raw)) {
            return [];
        }

        $now = time();
        $alive = array_values(array_filter($raw, static function ($entry) use ($now) {
            return is_array($entry)
                && isset($entry['jti'], $entry['expires_at'])
                && (int) $entry['expires_at'] > $now;
        }));

        if (count($alive) !== count($raw)) {
            // Persist pruning so the meta row stays bounded.
            update_user_meta($userId, self::META_KEY, $alive);
        }

        return $alive;
    }
}
```

- [ ] **Step 4: Run — verify GREEN**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && /usr/local/bin/php ./vendor/bin/phpunit --testsuite integration --filter RefreshTokenStoreTest 2>&1 | tail -10
```

Expected: `OK (6 tests, 8+ assertions)`.

- [ ] **Step 5: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git add packages/dashboard-plugin/src/Auth/RefreshTokenStore.php packages/dashboard-plugin/tests/Integration/Auth/RefreshTokenStoreTest.php && git commit -m "$(cat <<'EOF'
F3a: TDD Auth/RefreshTokenStore — JTI list in user_meta

remember/isActive/revoke methods, JSON-array of {jti, expires_at} tuples
per user. Lazy expiry sweep on every read keeps the meta row bounded.

user_meta over custom table because foundation is single-tenant —
≤10 active devices per user is trivial. F4+ can migrate if scale grows.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: TDD `PasswordVerifier` — wraps WP credential validation

**Why:** isolates the `wp_authenticate` call so REST controllers can be tested without going through WP's full auth filter chain. Also gives us a single hook point to add MFA in a future phase.

**Files:**
- Create: `packages/dashboard-plugin/src/Auth/PasswordVerifier.php`
- Create: `packages/dashboard-plugin/src/Auth/Exceptions/InvalidCredentialsException.php`
- Create: `packages/dashboard-plugin/tests/Integration/Auth/PasswordVerifierTest.php`

- [ ] **Step 1: Write the failing test**

Write `packages/dashboard-plugin/tests/Integration/Auth/PasswordVerifierTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Auth;

use Defyn\Dashboard\Auth\Exceptions\InvalidCredentialsException;
use Defyn\Dashboard\Auth\PasswordVerifier;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class PasswordVerifierTest extends WP_UnitTestCase
{
    public function testVerifyReturnsUserIdForValidCredentials(): void
    {
        $userId = self::factory()->user->create([
            'user_email' => 'test@defyn.test',
            'user_login' => 'testuser',
            'user_pass'  => 'correct-horse-battery-staple',
        ]);
        $verifier = new PasswordVerifier();

        $result = $verifier->verify('test@defyn.test', 'correct-horse-battery-staple');

        self::assertSame($userId, $result);
    }

    public function testVerifyAcceptsLoginInsteadOfEmail(): void
    {
        $userId = self::factory()->user->create([
            'user_email' => 'test2@defyn.test',
            'user_login' => 'testuser2',
            'user_pass'  => 'super-secret-password',
        ]);
        $verifier = new PasswordVerifier();

        $result = $verifier->verify('testuser2', 'super-secret-password');

        self::assertSame($userId, $result);
    }

    public function testVerifyThrowsOnWrongPassword(): void
    {
        self::factory()->user->create([
            'user_email' => 'test3@defyn.test',
            'user_pass'  => 'right-password',
        ]);
        $verifier = new PasswordVerifier();

        $this->expectException(InvalidCredentialsException::class);
        $verifier->verify('test3@defyn.test', 'wrong-password');
    }

    public function testVerifyThrowsOnUnknownUser(): void
    {
        $verifier = new PasswordVerifier();

        $this->expectException(InvalidCredentialsException::class);
        $verifier->verify('nonexistent@defyn.test', 'whatever');
    }

    public function testVerifyThrowsOnEmptyCredentials(): void
    {
        $verifier = new PasswordVerifier();

        $this->expectException(InvalidCredentialsException::class);
        $verifier->verify('', '');
    }
}
```

- [ ] **Step 2: Run — verify FAIL**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && /usr/local/bin/php ./vendor/bin/phpunit --testsuite integration --filter PasswordVerifierTest 2>&1 | tail -10
```

Expected: 5 errors — class not found.

- [ ] **Step 3: Write the exception**

Write `packages/dashboard-plugin/src/Auth/Exceptions/InvalidCredentialsException.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Auth\Exceptions;

use RuntimeException;

/**
 * Thrown when login credentials don't match a known user. Caller maps to HTTP 401.
 *
 * Note: this exception's message is intentionally generic ("Invalid credentials")
 * — never leak whether the failure was unknown user vs wrong password (timing
 * attacks + enumeration).
 */
final class InvalidCredentialsException extends RuntimeException
{
}
```

- [ ] **Step 4: Write PasswordVerifier**

Write `packages/dashboard-plugin/src/Auth/PasswordVerifier.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Auth;

use Defyn\Dashboard\Auth\Exceptions\InvalidCredentialsException;

/**
 * Validates email-or-login + password against WP's user table.
 *
 * Wraps wp_authenticate so REST controllers can be tested via this seam
 * without going through WP's full authentication filter chain. Future-me
 * can add MFA here without touching every controller.
 */
final class PasswordVerifier
{
    /**
     * @return int the user_id on success
     * @throws InvalidCredentialsException on any failure (unknown user, wrong password, empty creds)
     */
    public function verify(string $emailOrLogin, string $password): int
    {
        if ($emailOrLogin === '' || $password === '') {
            throw new InvalidCredentialsException('Invalid credentials');
        }

        $user = wp_authenticate($emailOrLogin, $password);

        if (is_wp_error($user) || !($user instanceof \WP_User) || $user->ID === 0) {
            throw new InvalidCredentialsException('Invalid credentials');
        }

        return (int) $user->ID;
    }
}
```

- [ ] **Step 5: Run — verify GREEN**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && /usr/local/bin/php ./vendor/bin/phpunit --testsuite integration --filter PasswordVerifierTest 2>&1 | tail -10
```

Expected: `OK (5 tests, 3+ assertions)`.

- [ ] **Step 6: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git add packages/dashboard-plugin/src/Auth/PasswordVerifier.php packages/dashboard-plugin/src/Auth/Exceptions/InvalidCredentialsException.php packages/dashboard-plugin/tests/Integration/Auth/PasswordVerifierTest.php && git commit -m "$(cat <<'EOF'
F3a: TDD Auth/PasswordVerifier — wraps wp_authenticate

Verify by email or login + password. Throws InvalidCredentialsException
with a generic "Invalid credentials" message (no user-vs-password leak)
on any failure.

Future MFA hook lives here; REST controllers stay decoupled from
WP's auth filter chain.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: Add `RestRouter` skeleton + wire into `Plugin::boot`

**Why:** before any controller can register a route, we need the router that registers them all in one place via `rest_api_init`. Setting up the skeleton + plugin wiring first keeps each subsequent controller commit small.

**Files:**
- Create: `packages/dashboard-plugin/src/Rest/RestRouter.php`
- Modify: `packages/dashboard-plugin/src/Plugin.php` (call $router->register())

- [ ] **Step 1: Write the RestRouter skeleton**

Write `packages/dashboard-plugin/src/Rest/RestRouter.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

/**
 * Single registration point for every REST route in the plugin.
 *
 * Plugin::boot() instantiates this and calls register() on `rest_api_init`.
 * Adding a new endpoint = adding one line to register().
 */
final class RestRouter
{
    public const NAMESPACE = 'defyn/v1';

    public function register(): void
    {
        // Auth endpoints — Tasks 6-9 add controllers and wire them in here.
    }
}
```

- [ ] **Step 2: Wire into Plugin::boot**

Read the current `Plugin.php`. Then replace `boot()` so it instantiates and registers the router. Final file:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard;

use Defyn\Dashboard\Rest\RestRouter;

/**
 * Singleton bootstrap. Wires up activation hooks now;
 * additional services (REST controllers, Action Scheduler jobs, etc.) added in later F-phases.
 */
final class Plugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function boot(): void
    {
        register_activation_hook(DEFYN_DASHBOARD_FILE, [Activation::class, 'activate']);

        add_action('rest_api_init', static function (): void {
            (new RestRouter())->register();
        });
    }
}
```

- [ ] **Step 3: Verify the existing suite still passes** (registration is a no-op until controllers are added):

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && /usr/local/bin/php ./vendor/bin/phpunit 2>&1 | tail -3
```

Expected: same green count as before.

- [ ] **Step 4: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git add packages/dashboard-plugin/src/Rest/RestRouter.php packages/dashboard-plugin/src/Plugin.php && git commit -m "$(cat <<'EOF'
F3a: RestRouter skeleton + Plugin::boot wiring

Single registration point for every REST route. Plugin::boot now adds
a rest_api_init listener that calls RestRouter::register(). Tasks 6-9
will add the four auth controllers and wire them through this method.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: TDD `POST /auth/login` — happy path + bad creds

**Why:** the entry-point endpoint. Once login works, every other auth flow has tokens to operate on.

**Files:**
- Create: `packages/dashboard-plugin/src/Rest/AuthLoginController.php`
- Create: `packages/dashboard-plugin/src/Rest/Responses/ErrorResponse.php`
- Modify: `packages/dashboard-plugin/src/Rest/RestRouter.php` (register login route)
- Create: `packages/dashboard-plugin/tests/Integration/Rest/AuthLoginTest.php`

- [ ] **Step 1: Write the failing test**

Write `packages/dashboard-plugin/tests/Integration/Rest/AuthLoginTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class AuthLoginTest extends WP_UnitTestCase
{
    private const PASSWORD = 'correct-horse-battery-staple';

    public function setUp(): void
    {
        parent::setUp();
        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
        // Ensure REST routes are registered for the test server.
        do_action('rest_api_init');
    }

    public function testLoginWithValidCredentialsReturns200AndAccessToken(): void
    {
        self::factory()->user->create([
            'user_email' => 'login@defyn.test',
            'user_pass'  => self::PASSWORD,
        ]);

        $request = new WP_REST_Request('POST', '/defyn/v1/auth/login');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode(['email' => 'login@defyn.test', 'password' => self::PASSWORD]));

        $response = rest_do_request($request);

        self::assertSame(200, $response->get_status());
        $data = $response->get_data();
        self::assertArrayHasKey('access_token', $data);
        self::assertNotEmpty($data['access_token']);
    }

    public function testLoginWithBadPasswordReturns401(): void
    {
        self::factory()->user->create([
            'user_email' => 'login2@defyn.test',
            'user_pass'  => self::PASSWORD,
        ]);

        $request = new WP_REST_Request('POST', '/defyn/v1/auth/login');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode(['email' => 'login2@defyn.test', 'password' => 'wrong']));

        $response = rest_do_request($request);

        self::assertSame(401, $response->get_status());
        $data = $response->get_data();
        self::assertArrayHasKey('error', $data);
        self::assertSame('auth.invalid_credentials', $data['error']['code']);
    }

    public function testLoginWithMissingFieldsReturns400(): void
    {
        $request = new WP_REST_Request('POST', '/defyn/v1/auth/login');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode(['email' => 'noone@defyn.test']));  // password missing

        $response = rest_do_request($request);

        self::assertSame(400, $response->get_status());
    }
}
```

- [ ] **Step 2: Run — verify FAIL**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && /usr/local/bin/php ./vendor/bin/phpunit --testsuite integration --filter AuthLoginTest 2>&1 | tail -10
```

Expected: 3 failures — route doesn't exist, all return 404.

- [ ] **Step 3: Write the ErrorResponse helper**

Write `packages/dashboard-plugin/src/Rest/Responses/ErrorResponse.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest\Responses;

use WP_REST_Response;

/**
 * Builds a consistent error envelope: { error: { code, message, details? } }.
 * HTTP status is the constructor's $status arg.
 */
final class ErrorResponse
{
    public static function create(int $status, string $code, string $message, ?array $details = null): WP_REST_Response
    {
        $body = [
            'error' => [
                'code'    => $code,
                'message' => $message,
            ],
        ];
        if ($details !== null) {
            $body['error']['details'] = $details;
        }
        return new WP_REST_Response($body, $status);
    }
}
```

- [ ] **Step 4: Write AuthLoginController**

Write `packages/dashboard-plugin/src/Rest/AuthLoginController.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Auth\Exceptions\InvalidCredentialsException;
use Defyn\Dashboard\Auth\PasswordVerifier;
use Defyn\Dashboard\Auth\RefreshTokenStore;
use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /defyn/v1/auth/login
 *
 * Body: { email: string, password: string }
 *
 * Success (200): { access_token: string }  — refresh in Set-Cookie header
 * Bad creds (401): { error: { code: 'auth.invalid_credentials', message } }
 * Missing fields (400): { error: { code: 'auth.missing_fields', message } }
 */
final class AuthLoginController
{
    public static function args(): array
    {
        return [
            'email'    => ['type' => 'string', 'required' => true],
            'password' => ['type' => 'string', 'required' => true],
        ];
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $email    = (string) $request->get_param('email');
        $password = (string) $request->get_param('password');

        if ($email === '' || $password === '') {
            return ErrorResponse::create(400, 'auth.missing_fields', 'Email and password are required.');
        }

        try {
            $userId = (new PasswordVerifier())->verify($email, $password);
        } catch (InvalidCredentialsException $e) {
            return ErrorResponse::create(401, 'auth.invalid_credentials', 'Invalid email or password.');
        }

        $tokens = new TokenService(DEFYN_JWT_SECRET);
        $access = $tokens->issueAccess($userId);
        $refresh = $tokens->issueRefresh($userId);
        $refreshClaims = $tokens->decode($refresh);

        // Track JTI so logout/revocation works.
        (new RefreshTokenStore())->remember($userId, $refreshClaims['jti'], (int) $refreshClaims['exp']);

        $response = new WP_REST_Response(['access_token' => $access], 200);
        self::setRefreshCookie($refresh, (int) $refreshClaims['exp']);

        return $response;
    }

    private static function setRefreshCookie(string $jwt, int $expiresAt): void
    {
        // HttpOnly + Secure + SameSite=None for cross-origin SPA. Path scoped to auth routes.
        $cookie = sprintf(
            'defyn_refresh=%s; Path=/wp-json/defyn/v1/auth; Expires=%s; HttpOnly; Secure; SameSite=None',
            $jwt,
            gmdate('D, d M Y H:i:s', $expiresAt) . ' GMT'
        );
        // headers_sent() is the proper guard in WP REST handler context.
        if (!headers_sent()) {
            header('Set-Cookie: ' . $cookie, false);
        }
    }
}
```

- [ ] **Step 5: Wire login into RestRouter**

Edit `packages/dashboard-plugin/src/Rest/RestRouter.php`. Replace its full contents with:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

/**
 * Single registration point for every REST route in the plugin.
 *
 * Plugin::boot() instantiates this and calls register() on `rest_api_init`.
 * Adding a new endpoint = adding one line to register().
 */
final class RestRouter
{
    public const NAMESPACE = 'defyn/v1';

    public function register(): void
    {
        register_rest_route(self::NAMESPACE, '/auth/login', [
            'methods'             => 'POST',
            'callback'            => [new AuthLoginController(), 'handle'],
            'permission_callback' => '__return_true',  // public endpoint
            'args'                => AuthLoginController::args(),
        ]);
    }
}
```

- [ ] **Step 6: Run — verify GREEN**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && /usr/local/bin/php ./vendor/bin/phpunit --testsuite integration --filter AuthLoginTest 2>&1 | tail -10
```

Expected: `OK (3 tests, ...)`.

- [ ] **Step 7: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git add packages/dashboard-plugin/src/Rest/AuthLoginController.php packages/dashboard-plugin/src/Rest/Responses/ErrorResponse.php packages/dashboard-plugin/src/Rest/RestRouter.php packages/dashboard-plugin/tests/Integration/Rest/AuthLoginTest.php && git commit -m "$(cat <<'EOF'
F3a: TDD POST /auth/login — happy path + bad creds + missing fields

Returns 200 + {access_token} on success with refresh JWT in
Set-Cookie (HttpOnly, Secure, SameSite=None, scoped to /auth path).
Returns 401 on bad creds (generic message — no user-vs-password leak).
Returns 400 on missing fields.

Refresh JTI tracked in user_meta via RefreshTokenStore so subsequent
logout/refresh-rotation can revoke specific tokens.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: TDD `GET /auth/me` + `RequireAuth` middleware

**Why:** smallest controller after login; gives us a way to verify access tokens work end-to-end before tackling the more complex refresh flow.

**Files:**
- Create: `packages/dashboard-plugin/src/Rest/Middleware/RequireAuth.php`
- Create: `packages/dashboard-plugin/src/Rest/AuthMeController.php`
- Modify: `packages/dashboard-plugin/src/Rest/RestRouter.php`
- Create: `packages/dashboard-plugin/tests/Integration/Rest/AuthMeTest.php`

- [ ] **Step 1: Write the failing test**

Write `packages/dashboard-plugin/tests/Integration/Rest/AuthMeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class AuthMeTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
        do_action('rest_api_init');
    }

    public function testMeWithValidAccessTokenReturns200AndUserInfo(): void
    {
        $userId = self::factory()->user->create([
            'user_email'   => 'me@defyn.test',
            'display_name' => 'Test Me',
        ]);
        $access = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($userId);

        $request = new WP_REST_Request('GET', '/defyn/v1/auth/me');
        $request->set_header('Authorization', 'Bearer ' . $access);

        $response = rest_do_request($request);

        self::assertSame(200, $response->get_status());
        $data = $response->get_data();
        self::assertSame($userId, $data['id']);
        self::assertSame('me@defyn.test', $data['email']);
        self::assertSame('Test Me', $data['display_name']);
    }

    public function testMeWithoutAuthHeaderReturns401(): void
    {
        $request = new WP_REST_Request('GET', '/defyn/v1/auth/me');

        $response = rest_do_request($request);

        self::assertSame(401, $response->get_status());
    }

    public function testMeWithMalformedAuthHeaderReturns401(): void
    {
        $request = new WP_REST_Request('GET', '/defyn/v1/auth/me');
        $request->set_header('Authorization', 'Basic abc123');  // wrong scheme

        $response = rest_do_request($request);

        self::assertSame(401, $response->get_status());
    }

    public function testMeWithExpiredTokenReturns401(): void
    {
        $userId = self::factory()->user->create();
        $expiredAt = time() - 7200;  // signed 2 hours ago, access TTL is 15 min
        $access = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($userId, $expiredAt);

        $request = new WP_REST_Request('GET', '/defyn/v1/auth/me');
        $request->set_header('Authorization', 'Bearer ' . $access);

        $response = rest_do_request($request);

        self::assertSame(401, $response->get_status());
    }

    public function testMeWithRefreshTokenInsteadOfAccessReturns401(): void
    {
        // Refresh tokens cannot be used as access tokens (typ claim mismatch).
        $userId = self::factory()->user->create();
        $refresh = (new TokenService(DEFYN_JWT_SECRET))->issueRefresh($userId);

        $request = new WP_REST_Request('GET', '/defyn/v1/auth/me');
        $request->set_header('Authorization', 'Bearer ' . $refresh);

        $response = rest_do_request($request);

        self::assertSame(401, $response->get_status());
    }
}
```

- [ ] **Step 2: Run — verify FAIL**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && /usr/local/bin/php ./vendor/bin/phpunit --testsuite integration --filter AuthMeTest 2>&1 | tail -10
```

Expected: 5 failures (404 routes don't exist).

- [ ] **Step 3: Write RequireAuth middleware**

Write `packages/dashboard-plugin/src/Rest/Middleware/RequireAuth.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest\Middleware;

use Defyn\Dashboard\Auth\Exceptions\InvalidTokenException;
use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Validates the Authorization: Bearer <jwt> header.
 *
 * Used as a permission_callback in route registration. On success, returns true
 * AND stashes the user_id on the request via $request->set_param('_authenticated_user_id', $id)
 * so the controller can read it. On failure, returns a WP_REST_Response (which WP propagates
 * directly as the response) instead of a bool, short-circuiting the controller.
 */
final class RequireAuth
{
    /** @return true|WP_REST_Response */
    public static function check(WP_REST_Request $request)
    {
        $header = $request->get_header('Authorization');
        if (!is_string($header) || !preg_match('/^Bearer\s+(.+)$/', $header, $m)) {
            return ErrorResponse::create(401, 'auth.missing_token', 'Authorization: Bearer <token> required.');
        }
        $token = trim($m[1]);

        try {
            $claims = (new TokenService(DEFYN_JWT_SECRET))->decode($token);
        } catch (InvalidTokenException $e) {
            return ErrorResponse::create(401, 'auth.invalid_token', 'Token is invalid or expired.');
        }

        if (($claims['typ'] ?? '') !== TokenService::TYPE_ACCESS) {
            return ErrorResponse::create(401, 'auth.wrong_token_type', 'Access token required (refresh tokens are not accepted here).');
        }

        $request->set_param('_authenticated_user_id', (int) $claims['sub']);
        return true;
    }
}
```

- [ ] **Step 4: Write AuthMeController**

Write `packages/dashboard-plugin/src/Rest/AuthMeController.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /defyn/v1/auth/me
 *
 * Auth: Bearer access token (handled by RequireAuth middleware).
 * Success (200): { id, email, display_name }
 */
final class AuthMeController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $user = get_userdata($userId);

        return new WP_REST_Response([
            'id'           => $userId,
            'email'        => $user->user_email,
            'display_name' => $user->display_name,
        ], 200);
    }
}
```

- [ ] **Step 5: Wire into RestRouter**

Edit `packages/dashboard-plugin/src/Rest/RestRouter.php`. Add the `/auth/me` route. Add `use Defyn\Dashboard\Rest\Middleware\RequireAuth;` at the top, then in `register()`:

```php
        register_rest_route(self::NAMESPACE, '/auth/me', [
            'methods'             => 'GET',
            'callback'            => [new AuthMeController(), 'handle'],
            'permission_callback' => [RequireAuth::class, 'check'],
        ]);
```

(Keep the existing `/auth/login` registration above it.)

- [ ] **Step 6: Run — verify GREEN**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && /usr/local/bin/php ./vendor/bin/phpunit --testsuite integration --filter AuthMeTest 2>&1 | tail -10
```

Expected: `OK (5 tests, ...)`.

- [ ] **Step 7: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git add packages/dashboard-plugin/src/Rest/Middleware/RequireAuth.php packages/dashboard-plugin/src/Rest/AuthMeController.php packages/dashboard-plugin/src/Rest/RestRouter.php packages/dashboard-plugin/tests/Integration/Rest/AuthMeTest.php && git commit -m "$(cat <<'EOF'
F3a: TDD GET /auth/me + RequireAuth middleware

RequireAuth validates the Authorization: Bearer header, decodes the JWT,
checks the typ='access' claim, and stashes user_id on the request.
Refresh tokens are explicitly rejected as access tokens.

AuthMe returns the authenticated user's id, email, display_name.
Tests cover: valid token → 200, missing header → 401, malformed
header → 401, expired token → 401, refresh-as-access → 401.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 8: TDD `POST /auth/refresh` — rotate refresh token

**Why:** the most complex auth flow. Reads refresh JWT from cookie, validates JTI is active, issues new pair, revokes old JTI.

**Files:**
- Create: `packages/dashboard-plugin/src/Rest/AuthRefreshController.php`
- Modify: `packages/dashboard-plugin/src/Rest/RestRouter.php`
- Create: `packages/dashboard-plugin/tests/Integration/Rest/AuthRefreshTest.php`

- [ ] **Step 1: Write the failing test**

Write `packages/dashboard-plugin/tests/Integration/Rest/AuthRefreshTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\RefreshTokenStore;
use Defyn\Dashboard\Auth\TokenService;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class AuthRefreshTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
        do_action('rest_api_init');
    }

    public function testRefreshWithValidCookieReturns200AndNewAccessToken(): void
    {
        $userId = self::factory()->user->create();
        $tokens = new TokenService(DEFYN_JWT_SECRET);
        $refresh = $tokens->issueRefresh($userId);
        $claims = $tokens->decode($refresh);
        (new RefreshTokenStore())->remember($userId, $claims['jti'], (int) $claims['exp']);

        $_COOKIE['defyn_refresh'] = $refresh;

        $request = new WP_REST_Request('POST', '/defyn/v1/auth/refresh');
        $response = rest_do_request($request);

        self::assertSame(200, $response->get_status());
        $data = $response->get_data();
        self::assertArrayHasKey('access_token', $data);
        unset($_COOKIE['defyn_refresh']);
    }

    public function testRefreshRotatesJti(): void
    {
        $userId = self::factory()->user->create();
        $tokens = new TokenService(DEFYN_JWT_SECRET);
        $refresh = $tokens->issueRefresh($userId);
        $claims = $tokens->decode($refresh);
        $oldJti = $claims['jti'];
        (new RefreshTokenStore())->remember($userId, $oldJti, (int) $claims['exp']);

        $_COOKIE['defyn_refresh'] = $refresh;
        rest_do_request(new WP_REST_Request('POST', '/defyn/v1/auth/refresh'));

        // Old JTI should now be revoked
        self::assertFalse((new RefreshTokenStore())->isActive($userId, $oldJti), 'old JTI should be revoked after rotation');
        unset($_COOKIE['defyn_refresh']);
    }

    public function testRefreshWithMissingCookieReturns401(): void
    {
        unset($_COOKIE['defyn_refresh']);
        $response = rest_do_request(new WP_REST_Request('POST', '/defyn/v1/auth/refresh'));

        self::assertSame(401, $response->get_status());
    }

    public function testRefreshWithRevokedJtiReturns401(): void
    {
        $userId = self::factory()->user->create();
        $tokens = new TokenService(DEFYN_JWT_SECRET);
        $refresh = $tokens->issueRefresh($userId);
        // Note: NOT calling RefreshTokenStore::remember — JTI is "revoked" (never tracked)

        $_COOKIE['defyn_refresh'] = $refresh;
        $response = rest_do_request(new WP_REST_Request('POST', '/defyn/v1/auth/refresh'));

        self::assertSame(401, $response->get_status());
        unset($_COOKIE['defyn_refresh']);
    }

    public function testRefreshWithAccessTokenInCookieReturns401(): void
    {
        // Cookie contains an access token (typ=access) instead of refresh.
        $userId = self::factory()->user->create();
        $access = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($userId);

        $_COOKIE['defyn_refresh'] = $access;
        $response = rest_do_request(new WP_REST_Request('POST', '/defyn/v1/auth/refresh'));

        self::assertSame(401, $response->get_status());
        unset($_COOKIE['defyn_refresh']);
    }
}
```

- [ ] **Step 2: Run — verify FAIL**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && /usr/local/bin/php ./vendor/bin/phpunit --testsuite integration --filter AuthRefreshTest 2>&1 | tail -10
```

Expected: 5 failures.

- [ ] **Step 3: Write AuthRefreshController**

Write `packages/dashboard-plugin/src/Rest/AuthRefreshController.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Auth\Exceptions\InvalidTokenException;
use Defyn\Dashboard\Auth\RefreshTokenStore;
use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /defyn/v1/auth/refresh
 *
 * Reads refresh JWT from defyn_refresh cookie. Validates JTI is in user's
 * active list. On success: revokes old JTI, issues new access + refresh
 * (with new JTI), returns 200 + new access in body + new refresh in cookie.
 */
final class AuthRefreshController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $cookie = $_COOKIE['defyn_refresh'] ?? '';
        if (!is_string($cookie) || $cookie === '') {
            return ErrorResponse::create(401, 'auth.missing_refresh', 'Refresh cookie is required.');
        }

        $tokens = new TokenService(DEFYN_JWT_SECRET);
        try {
            $claims = $tokens->decode($cookie);
        } catch (InvalidTokenException $e) {
            return ErrorResponse::create(401, 'auth.invalid_refresh', 'Refresh token is invalid or expired.');
        }

        if (($claims['typ'] ?? '') !== TokenService::TYPE_REFRESH) {
            return ErrorResponse::create(401, 'auth.wrong_token_type', 'Refresh token required.');
        }

        $userId = (int) $claims['sub'];
        $oldJti = (string) ($claims['jti'] ?? '');
        $store = new RefreshTokenStore();
        if ($oldJti === '' || !$store->isActive($userId, $oldJti)) {
            return ErrorResponse::create(401, 'auth.refresh_revoked', 'Refresh token is no longer active.');
        }

        // Rotate: revoke old JTI, issue new pair, remember new JTI.
        $store->revoke($userId, $oldJti);

        $newAccess = $tokens->issueAccess($userId);
        $newRefresh = $tokens->issueRefresh($userId);
        $newClaims = $tokens->decode($newRefresh);
        $store->remember($userId, $newClaims['jti'], (int) $newClaims['exp']);

        AuthLoginController::setRefreshCookieStatic($newRefresh, (int) $newClaims['exp']);

        return new WP_REST_Response(['access_token' => $newAccess], 200);
    }
}
```

- [ ] **Step 4: Expose `setRefreshCookie` for reuse**

Edit `packages/dashboard-plugin/src/Rest/AuthLoginController.php`. Rename the private static method `setRefreshCookie` to `setRefreshCookieStatic` and make it public so AuthRefreshController can reuse it. Update the call inside `handle()` accordingly. The method signature is the same — only the name and visibility change.

In `AuthLoginController.php`:
- Change `private static function setRefreshCookie(string $jwt, int $expiresAt): void` to `public static function setRefreshCookieStatic(string $jwt, int $expiresAt): void`
- Change the call `self::setRefreshCookie(...)` inside `handle()` to `self::setRefreshCookieStatic(...)`

- [ ] **Step 5: Wire refresh into RestRouter**

Add to `register()`:

```php
        register_rest_route(self::NAMESPACE, '/auth/refresh', [
            'methods'             => 'POST',
            'callback'            => [new AuthRefreshController(), 'handle'],
            'permission_callback' => '__return_true',  // cookie-validated inside the controller
        ]);
```

- [ ] **Step 6: Run — verify GREEN**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && /usr/local/bin/php ./vendor/bin/phpunit --testsuite integration --filter AuthRefreshTest 2>&1 | tail -10
```

Expected: `OK (5 tests, ...)`.

- [ ] **Step 7: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git add packages/dashboard-plugin/src/Rest/AuthRefreshController.php packages/dashboard-plugin/src/Rest/AuthLoginController.php packages/dashboard-plugin/src/Rest/RestRouter.php packages/dashboard-plugin/tests/Integration/Rest/AuthRefreshTest.php && git commit -m "$(cat <<'EOF'
F3a: TDD POST /auth/refresh — rotate refresh + revoke old JTI

Reads refresh JWT from cookie, validates JTI is active in user_meta,
revokes old JTI, issues new access + refresh, remembers new JTI.
Stolen-token detection: any reuse of a revoked JTI returns 401.

Test coverage: valid → 200 + rotation, revoked JTI → 401, missing
cookie → 401, access-token-in-cookie → 401.

Made AuthLoginController::setRefreshCookieStatic public so the
refresh controller can reuse cookie-emission logic.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 9: TDD `POST /auth/logout` — revoke + clear cookie

**Why:** smallest of the four auth endpoints. Reads refresh cookie, revokes the JTI, clears the cookie.

**Files:**
- Create: `packages/dashboard-plugin/src/Rest/AuthLogoutController.php`
- Modify: `packages/dashboard-plugin/src/Rest/RestRouter.php`
- Create: `packages/dashboard-plugin/tests/Integration/Rest/AuthLogoutTest.php`

- [ ] **Step 1: Write the failing test**

Write `packages/dashboard-plugin/tests/Integration/Rest/AuthLogoutTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\RefreshTokenStore;
use Defyn\Dashboard\Auth\TokenService;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class AuthLogoutTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
        do_action('rest_api_init');
    }

    public function testLogoutRevokesJtiAndReturns204(): void
    {
        $userId = self::factory()->user->create();
        $tokens = new TokenService(DEFYN_JWT_SECRET);
        $refresh = $tokens->issueRefresh($userId);
        $claims = $tokens->decode($refresh);
        $store = new RefreshTokenStore();
        $store->remember($userId, $claims['jti'], (int) $claims['exp']);

        $_COOKIE['defyn_refresh'] = $refresh;
        $response = rest_do_request(new WP_REST_Request('POST', '/defyn/v1/auth/logout'));

        self::assertSame(204, $response->get_status());
        self::assertFalse($store->isActive($userId, $claims['jti']), 'JTI should be revoked after logout');
        unset($_COOKIE['defyn_refresh']);
    }

    public function testLogoutWithoutCookieStillReturns204(): void
    {
        unset($_COOKIE['defyn_refresh']);
        $response = rest_do_request(new WP_REST_Request('POST', '/defyn/v1/auth/logout'));

        // Idempotent: logging out without a session is a no-op success.
        self::assertSame(204, $response->get_status());
    }

    public function testLogoutWithMalformedCookieStillReturns204(): void
    {
        $_COOKIE['defyn_refresh'] = 'not.a.jwt';
        $response = rest_do_request(new WP_REST_Request('POST', '/defyn/v1/auth/logout'));

        self::assertSame(204, $response->get_status());
        unset($_COOKIE['defyn_refresh']);
    }
}
```

- [ ] **Step 2: Run — verify FAIL**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && /usr/local/bin/php ./vendor/bin/phpunit --testsuite integration --filter AuthLogoutTest 2>&1 | tail -10
```

Expected: 3 failures.

- [ ] **Step 3: Write AuthLogoutController**

Write `packages/dashboard-plugin/src/Rest/AuthLogoutController.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Auth\Exceptions\InvalidTokenException;
use Defyn\Dashboard\Auth\RefreshTokenStore;
use Defyn\Dashboard\Auth\TokenService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /defyn/v1/auth/logout
 *
 * Idempotent. Always returns 204 (even with missing/malformed cookie) —
 * we don't want to leak whether a session existed. Best-effort revokes
 * the refresh JTI and clears the cookie.
 */
final class AuthLogoutController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $cookie = $_COOKIE['defyn_refresh'] ?? '';

        if (is_string($cookie) && $cookie !== '') {
            try {
                $claims = (new TokenService(DEFYN_JWT_SECRET))->decode($cookie);
                if (($claims['typ'] ?? '') === TokenService::TYPE_REFRESH) {
                    $userId = (int) ($claims['sub'] ?? 0);
                    $jti = (string) ($claims['jti'] ?? '');
                    if ($userId > 0 && $jti !== '') {
                        (new RefreshTokenStore())->revoke($userId, $jti);
                    }
                }
            } catch (InvalidTokenException $e) {
                // Malformed cookie — nothing to revoke. Still clear it below.
            }
        }

        // Clear the cookie regardless.
        if (!headers_sent()) {
            header(
                'Set-Cookie: defyn_refresh=; Path=/wp-json/defyn/v1/auth; Expires=Thu, 01 Jan 1970 00:00:00 GMT; HttpOnly; Secure; SameSite=None',
                false
            );
        }

        return new WP_REST_Response(null, 204);
    }
}
```

- [ ] **Step 4: Wire into RestRouter**

Add to `register()`:

```php
        register_rest_route(self::NAMESPACE, '/auth/logout', [
            'methods'             => 'POST',
            'callback'            => [new AuthLogoutController(), 'handle'],
            'permission_callback' => '__return_true',  // idempotent
        ]);
```

- [ ] **Step 5: Run — verify GREEN**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && /usr/local/bin/php ./vendor/bin/phpunit --testsuite integration --filter AuthLogoutTest 2>&1 | tail -10
```

Expected: `OK (3 tests, ...)`.

- [ ] **Step 6: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git add packages/dashboard-plugin/src/Rest/AuthLogoutController.php packages/dashboard-plugin/src/Rest/RestRouter.php packages/dashboard-plugin/tests/Integration/Rest/AuthLogoutTest.php && git commit -m "$(cat <<'EOF'
F3a: TDD POST /auth/logout — revoke JTI + clear cookie

Idempotent: always 204, even with missing or malformed refresh
cookie (no session-existence leak). Best-effort revokes the JTI
in user_meta and clears the cookie via Set-Cookie with past
Expires.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 10: TDD `RateLimit` middleware + apply to `/auth/login`

**Why:** prevents credential stuffing. 5 attempts per IP per minute is the standard threshold for a low-traffic admin login.

**Files:**
- Create: `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php`
- Modify: `packages/dashboard-plugin/src/Rest/RestRouter.php`
- Create: `packages/dashboard-plugin/tests/Integration/Rest/RateLimitTest.php`

- [ ] **Step 1: Write the failing test**

Write `packages/dashboard-plugin/tests/Integration/Rest/RateLimitTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class RateLimitTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
        $_SERVER['REMOTE_ADDR'] = '203.0.113.42';  // TEST-NET-3 IP
        do_action('rest_api_init');
    }

    public function tearDown(): void
    {
        // Wipe the rate-limit transient so the next test starts fresh.
        delete_transient('defyn_rl_login_203.0.113.42');
        unset($_SERVER['REMOTE_ADDR']);
        parent::tearDown();
    }

    public function testLoginAllowsFirst5AttemptsThenRateLimits6th(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $request = new WP_REST_Request('POST', '/defyn/v1/auth/login');
            $request->set_header('Content-Type', 'application/json');
            $request->set_body(json_encode(['email' => 'noone@defyn.test', 'password' => 'wrong']));
            $response = rest_do_request($request);
            self::assertNotSame(429, $response->get_status(), "attempt {$i} should not be rate-limited");
        }

        $request = new WP_REST_Request('POST', '/defyn/v1/auth/login');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode(['email' => 'noone@defyn.test', 'password' => 'wrong']));
        $response = rest_do_request($request);

        self::assertSame(429, $response->get_status(), '6th attempt should be rate-limited');
        $data = $response->get_data();
        self::assertSame('rate_limited', $data['error']['code']);
    }
}
```

- [ ] **Step 2: Run — verify FAIL**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && /usr/local/bin/php ./vendor/bin/phpunit --testsuite integration --filter RateLimitTest 2>&1 | tail -10
```

Expected: failure on the 6th-attempt assertion (no rate limiter exists, all 6 just return 401).

- [ ] **Step 3: Write the RateLimit middleware**

Write `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest\Middleware;

use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Per-IP transient-backed rate limiter.
 *
 * Use as a permission_callback wrapper that combines with the route's real
 * permission check. See RestRouter for the wiring pattern.
 */
final class RateLimit
{
    public const LOGIN_LIMIT  = 5;     // requests
    public const LOGIN_WINDOW = 60;    // seconds

    /** @return true|WP_REST_Response */
    public static function login(WP_REST_Request $request)
    {
        $ip = self::clientIp();
        $key = 'defyn_rl_login_' . $ip;
        $count = (int) (get_transient($key) ?: 0);

        if ($count >= self::LOGIN_LIMIT) {
            return ErrorResponse::create(429, 'rate_limited', 'Too many login attempts. Try again in a minute.');
        }

        set_transient($key, $count + 1, self::LOGIN_WINDOW);
        return true;
    }

    private static function clientIp(): string
    {
        // Trust REMOTE_ADDR only — never headers an attacker can spoof. F4+ may add
        // a proxy-aware variant if behind Kinsta's edge, but Kinsta strips and
        // re-emits X-Forwarded-For so trusting it requires explicit whitelisting.
        return is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
    }
}
```

- [ ] **Step 4: Wire RateLimit into the login route**

Edit `packages/dashboard-plugin/src/Rest/RestRouter.php`. Add the import for `RateLimit`, then update the `/auth/login` registration's `permission_callback` to chain rate-limit before the real check:

```php
use Defyn\Dashboard\Rest\Middleware\RateLimit;
```

In `register()`, replace the existing `/auth/login` registration with:

```php
        register_rest_route(self::NAMESPACE, '/auth/login', [
            'methods'             => 'POST',
            'callback'            => [new AuthLoginController(), 'handle'],
            'permission_callback' => [RateLimit::class, 'login'],
            'args'                => AuthLoginController::args(),
        ]);
```

- [ ] **Step 5: Run — verify GREEN**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && /usr/local/bin/php ./vendor/bin/phpunit --testsuite integration --filter RateLimitTest 2>&1 | tail -10
```

Expected: `OK (1 test, ...)`.

Also re-run AuthLoginTest to make sure it still passes (the tearDown clears the transient between tests):

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && /usr/local/bin/php ./vendor/bin/phpunit --testsuite integration --filter AuthLoginTest 2>&1 | tail -10
```

Expected: still `OK (3 tests, ...)`.

- [ ] **Step 6: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git add packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php packages/dashboard-plugin/src/Rest/RestRouter.php packages/dashboard-plugin/tests/Integration/Rest/RateLimitTest.php && git commit -m "$(cat <<'EOF'
F3a: TDD RateLimit middleware — 5 login attempts per IP per minute

Transient-backed counter keyed by REMOTE_ADDR. 6th attempt within the
60-second window returns 429 with code 'rate_limited'. Wired as the
permission_callback on POST /auth/login (only login is rate-limited
in F3a; other endpoints are protected by token validation).

Trusts REMOTE_ADDR only — proxy-aware variant deferred until we
deploy behind Kinsta's edge in F10 with explicit whitelisting.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 11: TDD `Cors` middleware + apply globally

**Why:** the SPA at `app.defyn.dev` (or `localhost:5173` in dev) is a different origin from the API. Browsers refuse to send credentials without the right CORS headers. We add it once in a middleware and apply it via a `rest_pre_serve_request` filter so EVERY `defyn/v1/*` response gets it.

**Files:**
- Create: `packages/dashboard-plugin/src/Rest/Middleware/Cors.php`
- Modify: `packages/dashboard-plugin/src/Rest/RestRouter.php` (apply via filter)
- Create: `packages/dashboard-plugin/tests/Integration/Rest/CorsTest.php`

- [ ] **Step 1: Write the failing test**

Write `packages/dashboard-plugin/tests/Integration/Rest/CorsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Rest\Middleware\Cors;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class CorsTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        if (!defined('DEFYN_SPA_ORIGIN')) {
            define('DEFYN_SPA_ORIGIN', 'http://localhost:5173');
        }
    }

    public function testApplyAddsAccessControlAllowOriginHeader(): void
    {
        // We test the middleware directly because rest_pre_serve_request fires
        // outside the WP_REST_Request lifecycle and is awkward to assert against.
        $response = new WP_REST_Response(['ok' => true], 200);
        $server = rest_get_server();
        $served = false;

        $served = Cors::apply($served, $response, null, $server);

        $headers = $response->get_headers();
        self::assertArrayHasKey('Access-Control-Allow-Origin', $headers);
        self::assertSame(DEFYN_SPA_ORIGIN, $headers['Access-Control-Allow-Origin']);
        self::assertSame('true', $headers['Access-Control-Allow-Credentials']);
    }
}
```

- [ ] **Step 2: Run — verify FAIL**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && /usr/local/bin/php ./vendor/bin/phpunit --testsuite integration --filter CorsTest 2>&1 | tail -10
```

Expected: error — Cors class doesn't exist.

- [ ] **Step 3: Write Cors middleware**

Write `packages/dashboard-plugin/src/Rest/Middleware/Cors.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest\Middleware;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Adds CORS headers to every defyn/v1/* response.
 *
 * Wired via the `rest_pre_serve_request` filter in RestRouter::register().
 * Returns the $served bool unchanged — we only add headers to the response.
 */
final class Cors
{
    /**
     * @param bool                  $served
     * @param WP_REST_Response      $response
     * @param WP_REST_Request|null  $request
     * @param WP_REST_Server        $server
     */
    public static function apply($served, $response, $request, $server): bool
    {
        // Only apply to our namespace.
        if ($request instanceof WP_REST_Request) {
            $route = $request->get_route();
            if (strpos($route, '/defyn/v1') !== 0) {
                return (bool) $served;
            }
        }

        $response->header('Access-Control-Allow-Origin', DEFYN_SPA_ORIGIN);
        $response->header('Access-Control-Allow-Credentials', 'true');
        $response->header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->header('Access-Control-Allow-Headers', 'Authorization, Content-Type');
        $response->header('Vary', 'Origin');

        return (bool) $served;
    }
}
```

- [ ] **Step 4: Wire Cors via rest_pre_serve_request**

Edit `packages/dashboard-plugin/src/Rest/RestRouter.php`. Add `use Defyn\Dashboard\Rest\Middleware\Cors;` at the top, then in `register()` add this line at the beginning (before the route registrations):

```php
        add_filter('rest_pre_serve_request', [Cors::class, 'apply'], 10, 4);
```

- [ ] **Step 5: Run — verify GREEN**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && /usr/local/bin/php ./vendor/bin/phpunit --testsuite integration --filter CorsTest 2>&1 | tail -10
```

Expected: `OK (1 test, 2 assertions)`.

- [ ] **Step 6: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git add packages/dashboard-plugin/src/Rest/Middleware/Cors.php packages/dashboard-plugin/src/Rest/RestRouter.php packages/dashboard-plugin/tests/Integration/Rest/CorsTest.php && git commit -m "$(cat <<'EOF'
F3a: TDD Cors middleware — applied globally to defyn/v1/*

Wired via rest_pre_serve_request filter. Allows DEFYN_SPA_ORIGIN
(http://localhost:5173 in dev, https://app.defyn.dev in prod) and
exposes Authorization + Content-Type for the SPA's bearer-token
flow. Only triggers for defyn/v1/* routes.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 12: F3a acceptance — full suite + tag

**Why:** prove every F3a addition works, prove F1+F2 didn't regress, then tag.

- [ ] **Step 1: Run the full suite**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && /usr/local/bin/php ./vendor/bin/phpunit 2>&1 | tail -8
```

Expected: roughly `OK (75-85 tests, 130+ assertions)`:
- F1+F2 baseline: 50 tests
- F3a additions: TokenService (8) + RefreshTokenStore (6) + PasswordVerifier (5) + AuthLogin (3) + AuthMe (5) + AuthRefresh (5) + AuthLogout (3) + RateLimit (1) + Cors (1) = 37 tests
- Total: ~87 tests

The exact assertion count may differ. The important thing is `OK` and zero failures.

- [ ] **Step 2: Tag F3a complete**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git tag -a f3a-auth-complete -m "F3a: Auth REST backend complete — POST /auth/login, POST /auth/refresh (rotating), POST /auth/logout, GET /auth/me. JWT (HS256) access + refresh tokens, JTI revocation in user_meta, per-IP rate limiting on login, CORS for the SPA. ~37 new tests. Foundation for F3b (SPA scaffold + login page)."
```

- [ ] **Step 3: Verify**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git tag --list "f*" && git log --oneline f3a-auth-complete | head -15
```

Expected: f1, f2, f3a tags listed; f3a-auth-complete points at the latest commit on the branch.

---

## F3a Verification Checklist (Definition of Done)

Before merging F3a into main, verify all of these:

- [ ] `POST /auth/login` with valid creds returns 200 + access_token + Set-Cookie
- [ ] `POST /auth/login` with bad creds returns 401 + error envelope
- [ ] `POST /auth/login` rate-limits at the 6th attempt per IP per minute (returns 429)
- [ ] `GET /auth/me` with valid Bearer access token returns 200 + user info
- [ ] `GET /auth/me` rejects refresh tokens (typ check)
- [ ] `POST /auth/refresh` rotates: new access in body, new refresh in cookie, old JTI revoked
- [ ] `POST /auth/refresh` with revoked or wrong-type JTI returns 401
- [ ] `POST /auth/logout` is idempotent: always 204, revokes JTI when present, clears cookie
- [ ] CORS headers (`Access-Control-Allow-Origin`, `-Credentials`, `-Methods`, `-Headers`, `Vary`) on every defyn/v1/* response
- [ ] Full PHPUnit suite passes (~87 tests)
- [ ] Tag `f3a-auth-complete` exists

When all boxes are checked, F3a is done. Invoke `superpowers:finishing-a-development-branch` to merge to main, then re-invoke `superpowers:writing-plans` for F3b (SPA scaffold + login page).

---

## Notes for F3b (forward-looking)

- F3b's `apiClient` will fetch with `credentials: 'include'` so the refresh cookie travels. F3a's CORS already supports this.
- F3b's auto-refresh-on-401 logic should call `POST /auth/refresh`; if that itself returns 401, redirect to /login.
- The `rate_limited` error code can be surfaced as a banner in the SPA login page.
- For local dev, the SPA at `localhost:5173` and WP at `defynwp.local` are different origins. F3a's CORS works for this; F3b's Vite dev server proxy is an alternative if cookie weirdness arises.
- `DEFYN_JWT_SECRET` MUST be set in the environment before F3a's endpoints work. F3b should detect "secret not set" and surface a clear error in the dev console (not just log it).

## Open questions (low-risk, can land in F3b or later)

- Should refresh token rotation invalidate ALL the user's other JTIs (single-active-session) or just the one being rotated? Currently we only revoke the specific one — this allows multi-device. Spec § 6.2 line 256 says "Rotation: every refresh issues a new pair; old refresh token is invalidated" which is ambiguous. Defer to user feedback.
- Login response could also include user info to save the SPA an immediate `/me` round-trip. Defer until F3b actually wants it.
- Rate limit threshold (5/min) is a guess. Real abuse data from F4+ usage will refine it.
