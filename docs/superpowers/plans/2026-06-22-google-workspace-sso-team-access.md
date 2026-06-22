# Google Workspace SSO + Shared Team Fleet — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let anyone with a `@defyn.com.au` Google Workspace account sign in via Google SSO and co-manage one shared team fleet, with per-person activity attribution.

**Architecture:** A new `POST /auth/google` endpoint verifies a Google ID token (firebase/php-jwt against Google's JWKS) and gates on the `hd=defyn.com.au` Workspace claim, then find-or-creates a WP user and issues the *existing* JWTs (identical to `/auth/login`). The shared fleet is achieved by deleting the `WHERE user_id = %d` filter from ~14 repository read-methods (signatures unchanged → no controller/service churn); `user_id` stays as a created-by stamp so activity attribution is free. Report branding moves from per-user meta to a shared option. Break-glass `/auth/login` stays but becomes domain-gated and is removed from the SPA UI.

**Tech Stack:** PHP 8.1 (WP plugin, PHPUnit/wp-phpunit), firebase/php-jwt ^7 (already present — no new composer dep), React 18 + TS + Vitest + MSW, `@react-oauth/google` (new SPA dep). Dashboard `0.29.0 → 0.30.0`. **No DB schema change.**

**Spec:** `docs/superpowers/specs/2026-06-22-google-workspace-sso-team-access-design.md`

**Branch:** `google-sso-team-access` (already created).

---

## File Structure

**New (dashboard):**
- `src/Auth/DomainPolicy.php` — pure allowed-domain helper (used by Google verifier + login gate).
- `src/Auth/GoogleIdTokenVerifier.php` — verifies a Google ID token; injectable decode seam.
- `src/Auth/Exceptions/GoogleAuthException.php` — carries an error `code` + HTTP `status`.
- `src/Auth/UserProvisioner.php` — find-or-create a WP user from verified Google claims.
- `src/Rest/AuthGoogleController.php` — `POST /auth/google`.
- `tests/Unit/Auth/DomainPolicyTest.php`, `tests/Unit/Auth/GoogleIdTokenVerifierTest.php`, `tests/Integration/Auth/UserProvisionerTest.php`, `tests/Integration/Rest/AuthGoogleControllerTest.php`.

**Modified (dashboard):**
- `defyn-dashboard.php` — bootstrap `DEFYN_GOOGLE_CLIENT_ID`; version `0.29.0 → 0.30.0`.
- `src/Rest/AuthLoginController.php` — domain-gate after `PasswordVerifier::verify`.
- `src/Rest/Middleware/RateLimit.php` — add `googleAuth` bucket.
- `src/Rest/RestRouter.php` — register `/auth/google`.
- 8 repository files — drop `user_id` filter from the listed read-methods (de-scope).
- `src/Services/BrandingService.php` — store in shared options; `migrateLegacyToShared()`.
- `src/Plugin.php` — call the one-time branding migration on boot.

**New/Modified (SPA):**
- `apps/web/package.json` — add `@react-oauth/google`.
- `apps/web/src/lib/auth.tsx` — add `loginWithGoogle(credential)`.
- `apps/web/src/routes/Login.tsx` — Google-only login (remove email/password form).
- `apps/web/src/test/handlers.ts` — add `/auth/google` MSW handler.
- `apps/web/tests/Login.test.tsx` — Google sign-in tests.

---

# PART 1 — Backend: Google SSO sign-in

### Task 1: `DomainPolicy` (pure allowed-domain helper)

**Files:**
- Create: `packages/dashboard-plugin/src/Auth/DomainPolicy.php`
- Test: `packages/dashboard-plugin/tests/Unit/Auth/DomainPolicyTest.php`

- [ ] **Step 1: Write the failing test**
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Unit\Auth;

use Defyn\Dashboard\Auth\DomainPolicy;
use PHPUnit\Framework\TestCase;

final class DomainPolicyTest extends TestCase
{
    public function testAllowedEmailMatchesDomainCaseInsensitively(): void
    {
        $this->assertTrue(DomainPolicy::isAllowedEmail('pradeep@defyn.com.au'));
        $this->assertTrue(DomainPolicy::isAllowedEmail('Devs@DEFYN.COM.AU'));
    }

    public function testRejectsOtherDomainsAndMalformed(): void
    {
        $this->assertFalse(DomainPolicy::isAllowedEmail('x@gmail.com'));
        $this->assertFalse(DomainPolicy::isAllowedEmail('x@evildefyn.com.au'));
        $this->assertFalse(DomainPolicy::isAllowedEmail('defyn.com.au'));
        $this->assertFalse(DomainPolicy::isAllowedEmail(''));
    }

    public function testHdMatch(): void
    {
        $this->assertTrue(DomainPolicy::isAllowedHd('defyn.com.au'));
        $this->assertFalse(DomainPolicy::isAllowedHd('gmail.com'));
        $this->assertFalse(DomainPolicy::isAllowedHd(''));
    }
}
```

- [ ] **Step 2: Run it (fails — class missing)**
Run: `cd packages/dashboard-plugin && vendor/bin/phpunit tests/Unit/Auth/DomainPolicyTest.php`
Expected: FAIL ("Class DomainPolicy not found").

- [ ] **Step 3: Implement**
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Auth;

/** The single allowed sign-in domain. Pure — no WP. */
final class DomainPolicy
{
    public const ALLOWED = 'defyn.com.au';

    public static function isAllowedEmail(string $email): bool
    {
        $email = strtolower(trim($email));
        return $email !== '' && str_ends_with($email, '@' . self::ALLOWED);
    }

    public static function isAllowedHd(string $hd): bool
    {
        return strtolower(trim($hd)) === self::ALLOWED;
    }
}
```
(`str_ends_with` requires PHP 8.0+; plugin requires 8.1 — fine.)

- [ ] **Step 4: Run it (passes)**
Run: `vendor/bin/phpunit tests/Unit/Auth/DomainPolicyTest.php` → PASS.

- [ ] **Step 5: Commit**
```bash
git add packages/dashboard-plugin/src/Auth/DomainPolicy.php packages/dashboard-plugin/tests/Unit/Auth/DomainPolicyTest.php
git commit -m "feat(auth): DomainPolicy allowed-domain helper"
```

---

### Task 2: `GoogleAuthException`

**Files:**
- Create: `packages/dashboard-plugin/src/Auth/Exceptions/GoogleAuthException.php`

- [ ] **Step 1: Implement (no test — trivial value object; covered via verifier tests)**
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Auth\Exceptions;

use RuntimeException;

/** Thrown by GoogleIdTokenVerifier. `errorCode` is the REST error code; `status` the HTTP status. */
final class GoogleAuthException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message
    ) {
        parent::__construct($message);
    }
}
```

- [ ] **Step 2: Commit**
```bash
git add packages/dashboard-plugin/src/Auth/Exceptions/GoogleAuthException.php
git commit -m "feat(auth): GoogleAuthException (code + status)"
```

---

### Task 3: `GoogleIdTokenVerifier` (verify claims; injectable decode seam)

**Files:**
- Create: `packages/dashboard-plugin/src/Auth/GoogleIdTokenVerifier.php`
- Test: `packages/dashboard-plugin/tests/Unit/Auth/GoogleIdTokenVerifierTest.php`

The class does signature/exp verification in `decode()` (overridable so tests skip real RSA), and claim validation in `verify()`. Mirrors the "NOT final + injectable" seam used by `PageSpeedClient`/`Ga4Client`.

- [ ] **Step 1: Write the failing test** (a test subclass stubs `decode()` to return canned claims)
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Unit\Auth;

use Defyn\Dashboard\Auth\GoogleIdTokenVerifier;
use Defyn\Dashboard\Auth\Exceptions\GoogleAuthException;
use PHPUnit\Framework\TestCase;

final class GoogleIdTokenVerifierTest extends TestCase
{
    private function verifier(array $claims): GoogleIdTokenVerifier
    {
        return new class('client-123.apps.googleusercontent.com', $claims) extends GoogleIdTokenVerifier {
            public function __construct(string $clientId, private array $stub) { parent::__construct($clientId); }
            protected function decode(string $idToken): array { return $this->stub; }
        };
    }

    private function goodClaims(array $over = []): array
    {
        return array_merge([
            'iss' => 'https://accounts.google.com',
            'aud' => 'client-123.apps.googleusercontent.com',
            'email' => 'pradeep@defyn.com.au',
            'email_verified' => true,
            'hd' => 'defyn.com.au',
            'sub' => '11122233344455566677',
            'name' => 'Pradeep',
        ], $over);
    }

    public function testValidWorkspaceTokenReturnsClaims(): void
    {
        $claims = $this->verifier($this->goodClaims())->verify('jwt');
        $this->assertSame('pradeep@defyn.com.au', $claims['email']);
        $this->assertSame('11122233344455566677', $claims['sub']);
    }

    public function testRejectsWrongAud(): void
    {
        $this->expectException(GoogleAuthException::class);
        $this->verifier($this->goodClaims(['aud' => 'someone-else']))->verify('jwt');
    }

    public function testRejectsWrongIssuer(): void
    {
        $this->expectException(GoogleAuthException::class);
        $this->verifier($this->goodClaims(['iss' => 'evil.example.com']))->verify('jwt');
    }

    public function testRejectsUnverifiedEmail(): void
    {
        $this->expectException(GoogleAuthException::class);
        $this->verifier($this->goodClaims(['email_verified' => false]))->verify('jwt');
    }

    public function testRejectsWrongHdWith403(): void
    {
        try {
            $this->verifier($this->goodClaims(['hd' => 'gmail.com', 'email' => 'x@gmail.com']))->verify('jwt');
            $this->fail('expected GoogleAuthException');
        } catch (GoogleAuthException $e) {
            $this->assertSame(403, $e->status);
            $this->assertSame('auth.google_domain', $e->errorCode);
        }
    }

    public function testRejectsHdMismatchEmail(): void
    {
        // hd says defyn but email domain differs → reject 403
        $this->expectException(GoogleAuthException::class);
        $this->verifier($this->goodClaims(['email' => 'x@gmail.com']))->verify('jwt');
    }
}
```

- [ ] **Step 2: Run it (fails)** — `vendor/bin/phpunit tests/Unit/Auth/GoogleIdTokenVerifierTest.php` → FAIL (class missing).

- [ ] **Step 3: Implement**
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Auth;

use Defyn\Dashboard\Auth\Exceptions\GoogleAuthException;
use Firebase\JWT\JWT;
use Firebase\JWT\JWK;
use Throwable;

/**
 * Verifies a Google-issued ID token (OIDC) and enforces the Workspace domain.
 * `decode()` (signature + expiry against Google's JWKS) is protected so unit
 * tests can stub it with canned claims instead of real RSA.
 */
class GoogleIdTokenVerifier
{
    private const CERTS_URL = 'https://www.googleapis.com/oauth2/v3/certs';
    private const CERTS_TRANSIENT = 'defyn_google_jwks';
    private const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    public function __construct(private readonly string $clientId) {}

    /** @return array<string,mixed> verified claims @throws GoogleAuthException */
    public function verify(string $idToken): array
    {
        try {
            $claims = $this->decode($idToken);
        } catch (Throwable $e) {
            throw new GoogleAuthException('auth.google_invalid', 401, 'Google token is invalid or expired.');
        }

        if (!in_array((string) ($claims['iss'] ?? ''), self::ISSUERS, true)) {
            throw new GoogleAuthException('auth.google_invalid', 401, 'Unexpected token issuer.');
        }
        if ((string) ($claims['aud'] ?? '') !== $this->clientId) {
            throw new GoogleAuthException('auth.google_invalid', 401, 'Token audience mismatch.');
        }
        $verified = $claims['email_verified'] ?? false;
        if ($verified !== true && $verified !== 'true') {
            throw new GoogleAuthException('auth.google_invalid', 401, 'Email is not verified.');
        }

        $email = (string) ($claims['email'] ?? '');
        $hd = (string) ($claims['hd'] ?? '');
        if (!DomainPolicy::isAllowedHd($hd) || !DomainPolicy::isAllowedEmail($email)) {
            throw new GoogleAuthException('auth.google_domain', 403, 'Only ' . DomainPolicy::ALLOWED . ' accounts may sign in.');
        }

        return $claims;
    }

    /**
     * Decode + verify the JWT signature against Google's rotating JWKS.
     * firebase/php-jwt throws on bad signature/expiry. Overridable in tests.
     * @return array<string,mixed>
     */
    protected function decode(string $idToken): array
    {
        $keys = JWK::parseKeySet($this->googleCerts());
        return (array) JWT::decode($idToken, $keys);
    }

    /** @return array<string,mixed> the raw JWKS document, cached in a transient. */
    protected function googleCerts(): array
    {
        $cached = get_transient(self::CERTS_TRANSIENT);
        if (is_array($cached) && isset($cached['keys'])) {
            return $cached;
        }
        $res = wp_remote_get(self::CERTS_URL, ['timeout' => 10]);
        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
            throw new GoogleAuthException('auth.google_invalid', 401, 'Could not fetch Google certificates.');
        }
        $json = json_decode((string) wp_remote_retrieve_body($res), true);
        if (!is_array($json) || !isset($json['keys'])) {
            throw new GoogleAuthException('auth.google_invalid', 401, 'Malformed Google certificates.');
        }
        set_transient(self::CERTS_TRANSIENT, $json, HOUR_IN_SECONDS);
        return $json;
    }
}
```

- [ ] **Step 4: Run it (passes)** — `vendor/bin/phpunit tests/Unit/Auth/GoogleIdTokenVerifierTest.php` → PASS.

- [ ] **Step 5: Commit**
```bash
git add packages/dashboard-plugin/src/Auth/GoogleIdTokenVerifier.php packages/dashboard-plugin/tests/Unit/Auth/GoogleIdTokenVerifierTest.php
git commit -m "feat(auth): GoogleIdTokenVerifier with injectable decode seam"
```

---

### Task 4: `UserProvisioner` (find-or-create WP user from claims)

**Files:**
- Create: `packages/dashboard-plugin/src/Auth/UserProvisioner.php`
- Test: `packages/dashboard-plugin/tests/Integration/Auth/UserProvisionerTest.php` (real WP — uses wp-phpunit)

- [ ] **Step 1: Write the failing test**
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Auth;

use Defyn\Dashboard\Auth\UserProvisioner;
use WP_UnitTestCase;

final class UserProvisionerTest extends WP_UnitTestCase
{
    public function testLinksExistingUserByEmailAndStoresSub(): void
    {
        $existing = self::factory()->user->create(['user_email' => 'devs@defyn.com.au']);
        $id = (new UserProvisioner())->findOrCreate([
            'email' => 'devs@defyn.com.au', 'name' => 'Devs', 'sub' => 'sub-1',
        ]);
        $this->assertSame($existing, $id);
        $this->assertSame('sub-1', get_user_meta($id, 'defyn_google_sub', true));
    }

    public function testCreatesNewUserWhenEmailUnknown(): void
    {
        $id = (new UserProvisioner())->findOrCreate([
            'email' => 'newperson@defyn.com.au', 'name' => 'New Person', 'sub' => 'sub-2',
        ]);
        $this->assertGreaterThan(0, $id);
        $user = get_userdata($id);
        $this->assertSame('newperson@defyn.com.au', $user->user_email);
        $this->assertSame('New Person', $user->display_name);
        $this->assertSame('sub-2', get_user_meta($id, 'defyn_google_sub', true));
    }
}
```

- [ ] **Step 2: Run it (fails)** — see Environment note at end for the mysqld/integration runner. Expected FAIL (class missing).

- [ ] **Step 3: Implement**
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Auth;

/** Find-or-create a WordPress user from verified Google claims. */
final class UserProvisioner
{
    /**
     * @param array{email:string,name?:string,sub?:string} $claims
     * @return int WP user id
     */
    public function findOrCreate(array $claims): int
    {
        $email = (string) $claims['email'];
        $existing = get_user_by('email', $email);
        if ($existing instanceof \WP_User) {
            $userId = (int) $existing->ID;
        } else {
            $userId = wp_insert_user([
                'user_login'   => $email,
                'user_email'   => $email,
                'user_pass'    => wp_generate_password(64, true, true),
                'display_name' => (string) ($claims['name'] ?? $email),
                'role'         => 'subscriber',
            ]);
            if (is_wp_error($userId) || (int) $userId <= 0) {
                // wp_insert_user can fail if user_login collides; fall back to email lookup
                $byEmail = get_user_by('email', $email);
                $userId = $byEmail instanceof \WP_User ? (int) $byEmail->ID : 0;
            }
            $userId = (int) $userId;
        }
        if ($userId > 0 && isset($claims['sub'])) {
            update_user_meta($userId, 'defyn_google_sub', (string) $claims['sub']);
        }
        return $userId;
    }
}
```

- [ ] **Step 4: Run it (passes)** — `vendor/bin/phpunit tests/Integration/Auth/UserProvisionerTest.php` → PASS.

- [ ] **Step 5: Commit**
```bash
git add packages/dashboard-plugin/src/Auth/UserProvisioner.php packages/dashboard-plugin/tests/Integration/Auth/UserProvisionerTest.php
git commit -m "feat(auth): UserProvisioner find-or-create from Google claims"
```

---

### Task 5: Bootstrap `DEFYN_GOOGLE_CLIENT_ID`

**Files:**
- Modify: `packages/dashboard-plugin/defyn-dashboard.php` (after the GA4 block ~line 95)

- [ ] **Step 1: Add the bootstrap block** (mirror the PageSpeed/GA4 pattern)
```php
// Google Workspace SSO (2026-06-22): the OAuth client ID used to verify the
// `aud` of incoming Google ID tokens. Public value (also in the SPA). When
// absent, /auth/google returns 503 auth.google_not_configured; the plugin still
// loads and email/password break-glass login is unaffected.
if (!defined('DEFYN_GOOGLE_CLIENT_ID')) {
    $envGoogleClientId = getenv('DEFYN_GOOGLE_CLIENT_ID');
    if ($envGoogleClientId !== false && $envGoogleClientId !== '') {
        define('DEFYN_GOOGLE_CLIENT_ID', $envGoogleClientId);
    }
}
```

- [ ] **Step 2: Commit**
```bash
git add packages/dashboard-plugin/defyn-dashboard.php
git commit -m "feat(auth): bootstrap DEFYN_GOOGLE_CLIENT_ID env constant"
```

---

### Task 6: `RateLimit::googleAuth` bucket

**Files:**
- Modify: `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php`
- Test: `packages/dashboard-plugin/tests/Integration/Rest/Middleware/RateLimitTest.php` (append if exists; else create)

- [ ] **Step 1: Write the failing test** (mirror the existing login limiter test; verify 30/min per IP)
```php
public function testGoogleAuthLimitsPerIp(): void
{
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    $req = new \WP_REST_Request('POST', '/defyn/v1/auth/google');
    for ($i = 0; $i < 30; $i++) {
        $this->assertTrue(\Defyn\Dashboard\Rest\Middleware\RateLimit::googleAuth($req));
    }
    $err = \Defyn\Dashboard\Rest\Middleware\RateLimit::googleAuth($req);
    $this->assertInstanceOf(\WP_Error::class, $err);
    $this->assertSame(429, $err->get_error_data()['status']);
}
```

- [ ] **Step 2: Run it (fails)** — method missing.

- [ ] **Step 3: Implement** (add near `login()`; mirror its structure)
```php
public const GOOGLE_AUTH_LIMIT  = 30;
public const GOOGLE_AUTH_WINDOW = 60;

public static function googleAuth(WP_REST_Request $request)
{
    $ip = self::clientIp();
    $key = 'defyn_rl_google_' . $ip;
    $count = (int) (get_transient($key) ?: 0);
    if ($count >= self::GOOGLE_AUTH_LIMIT) {
        return new WP_Error('auth.rate_limited', 'Too many sign-in attempts. Try again in a minute.', ['status' => 429]);
    }
    set_transient($key, $count + 1, self::GOOGLE_AUTH_WINDOW);
    return true;
}
```

- [ ] **Step 4: Run it (passes).**

- [ ] **Step 5: Commit**
```bash
git add packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php packages/dashboard-plugin/tests/Integration/Rest/Middleware/RateLimitTest.php
git commit -m "feat(auth): RateLimit::googleAuth 30/min per-IP bucket"
```

---

### Task 7: `AuthGoogleController` + route

**Files:**
- Create: `packages/dashboard-plugin/src/Rest/AuthGoogleController.php`
- Modify: `packages/dashboard-plugin/src/Rest/RestRouter.php` (after the `/auth/login` block)
- Test: `packages/dashboard-plugin/tests/Integration/Rest/AuthGoogleControllerTest.php`

- [ ] **Step 1: Write the failing test** (inject a stub verifier so no real Google)
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Rest\AuthGoogleController;
use Defyn\Dashboard\Auth\GoogleIdTokenVerifier;
use Defyn\Dashboard\Auth\Exceptions\GoogleAuthException;
use WP_UnitTestCase;
use WP_REST_Request;

final class AuthGoogleControllerTest extends WP_UnitTestCase
{
    private function controllerReturning(array $claims): AuthGoogleController
    {
        $verifier = new class('cid', $claims) extends GoogleIdTokenVerifier {
            public function __construct(string $c, private array $claims) { parent::__construct($c); }
            public function verify(string $idToken): array { return $this->claims; }
        };
        return new AuthGoogleController($verifier);
    }

    public function testSuccessIssuesAccessTokenAndSetsRefreshCookie(): void
    {
        if (!defined('DEFYN_GOOGLE_CLIENT_ID')) define('DEFYN_GOOGLE_CLIENT_ID', 'cid');
        if (!defined('DEFYN_JWT_SECRET')) define('DEFYN_JWT_SECRET', str_repeat('a', 40));
        $req = new WP_REST_Request('POST', '/defyn/v1/auth/google');
        $req->set_body(json_encode(['credential' => 'fake.jwt']));
        $req->set_header('Content-Type', 'application/json');
        $resp = $this->controllerReturning([
            'email' => 'devs@defyn.com.au', 'name' => 'Devs', 'sub' => 's1',
        ])->handle($req);
        $data = $resp->get_data();
        $this->assertArrayHasKey('access_token', $data);
        $cookie = $resp->get_headers()['Set-Cookie'] ?? '';
        $this->assertStringContainsString('defyn_refresh=', is_array($cookie) ? implode(';', $cookie) : $cookie);
    }

    public function testDomainRejectionReturns403(): void
    {
        if (!defined('DEFYN_GOOGLE_CLIENT_ID')) define('DEFYN_GOOGLE_CLIENT_ID', 'cid');
        $verifier = new class('cid') extends GoogleIdTokenVerifier {
            public function verify(string $idToken): array {
                throw new GoogleAuthException('auth.google_domain', 403, 'nope');
            }
        };
        $req = new WP_REST_Request('POST', '/defyn/v1/auth/google');
        $req->set_body(json_encode(['credential' => 'fake'])); $req->set_header('Content-Type', 'application/json');
        $resp = (new AuthGoogleController($verifier))->handle($req);
        $this->assertSame(403, $resp->get_status());
        $this->assertSame('auth.google_domain', $resp->get_data()['error']['code']);
    }

    public function testMissingCredentialReturns400(): void
    {
        if (!defined('DEFYN_GOOGLE_CLIENT_ID')) define('DEFYN_GOOGLE_CLIENT_ID', 'cid');
        $req = new WP_REST_Request('POST', '/defyn/v1/auth/google');
        $req->set_body(json_encode([])); $req->set_header('Content-Type', 'application/json');
        $resp = $this->controllerReturning([])->handle($req);
        $this->assertSame(400, $resp->get_status());
    }
}
```

- [ ] **Step 2: Run it (fails).**

- [ ] **Step 3: Implement the controller** (mirror `AuthLoginController` token issuance exactly)
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Auth\GoogleIdTokenVerifier;
use Defyn\Dashboard\Auth\UserProvisioner;
use Defyn\Dashboard\Auth\RefreshTokenStore;
use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Auth\Exceptions\GoogleAuthException;
use Defyn\Dashboard\Services\ActivityLogger;
use WP_REST_Request;
use WP_REST_Response;

final class AuthGoogleController
{
    public function __construct(private ?GoogleIdTokenVerifier $verifier = null) {}

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        if (!defined('DEFYN_GOOGLE_CLIENT_ID')) {
            return ErrorResponse::create(503, 'auth.google_not_configured', 'Google sign-in is not configured.');
        }
        $body = $request->get_json_params() ?: [];
        $credential = is_string($body['credential'] ?? null) ? trim((string) $body['credential']) : '';
        if ($credential === '') {
            return ErrorResponse::create(400, 'auth.google_missing_credential', 'A Google credential is required.');
        }

        $verifier = $this->verifier ?? new GoogleIdTokenVerifier((string) DEFYN_GOOGLE_CLIENT_ID);
        try {
            $claims = $verifier->verify($credential);
        } catch (GoogleAuthException $e) {
            return ErrorResponse::create($e->status, $e->errorCode, $e->getMessage());
        }

        $userId = (new UserProvisioner())->findOrCreate([
            'email' => (string) ($claims['email'] ?? ''),
            'name'  => (string) ($claims['name'] ?? ''),
            'sub'   => (string) ($claims['sub'] ?? ''),
        ]);
        if ($userId <= 0) {
            return ErrorResponse::create(500, 'auth.google_provision_failed', 'Could not provision your account.');
        }

        $tokens  = new TokenService(DEFYN_JWT_SECRET);
        $access  = $tokens->issueAccess($userId);
        $refresh = $tokens->issueRefresh($userId);
        $claimsR = $tokens->decode($refresh);
        (new RefreshTokenStore())->remember($userId, (string) $claimsR['jti'], (int) $claimsR['exp']);

        $ip = $request->get_header('X-Forwarded-For') ?: (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        (new ActivityLogger())->log($userId, null, 'auth.login', ['method' => 'google'], (string) $ip);

        $response = new WP_REST_Response(['access_token' => $access, 'error' => null], 200);
        $response->header('Set-Cookie', AuthLoginController::buildRefreshCookie($refresh, (int) $claimsR['exp']));
        return $response;
    }
}
```
(Confirm the exact `ActivityLogger::log` signature + `ErrorResponse::create` exist as used in `AuthLoginController` — mirror them precisely; adjust the IP-extraction line to match `AuthLoginController`'s.)

- [ ] **Step 4: Register the route** in `RestRouter::register()` right after the `/auth/login` block:
```php
register_rest_route(self::NAMESPACE, '/auth/google', [
    'methods'             => 'POST',
    'callback'            => [new AuthGoogleController(), 'handle'],
    'permission_callback' => [RateLimit::class, 'googleAuth'],
]);
```
Add `use Defyn\Dashboard\Rest\AuthGoogleController;` if the file uses imports (match existing style).

- [ ] **Step 5: Run the controller test (passes).**

- [ ] **Step 6: Commit**
```bash
git add packages/dashboard-plugin/src/Rest/AuthGoogleController.php packages/dashboard-plugin/src/Rest/RestRouter.php packages/dashboard-plugin/tests/Integration/Rest/AuthGoogleControllerTest.php
git commit -m "feat(auth): POST /auth/google endpoint + route"
```

---

### Task 8: Domain-gate the break-glass `/auth/login`

**Files:**
- Modify: `packages/dashboard-plugin/src/Rest/AuthLoginController.php` (after `PasswordVerifier::verify` returns `$userId`)
- Test: `packages/dashboard-plugin/tests/Integration/Rest/AuthLoginControllerTest.php` (append)

- [ ] **Step 1: Write the failing test**
```php
public function testRejectsNonDefynDomainWith403(): void
{
    if (!defined('DEFYN_JWT_SECRET')) define('DEFYN_JWT_SECRET', str_repeat('a', 40));
    $uid = self::factory()->user->create(['user_email' => 'outsider@gmail.com', 'user_pass' => 'pw']);
    $controller = new \Defyn\Dashboard\Rest\AuthLoginController(); // uses real PasswordVerifier
    $req = new \WP_REST_Request('POST', '/defyn/v1/auth/login');
    $req->set_body(json_encode(['email' => 'outsider@gmail.com', 'password' => 'pw']));
    $req->set_header('Content-Type', 'application/json');
    $resp = $controller->handle($req);
    $this->assertSame(403, $resp->get_status());
    $this->assertSame('auth.domain_forbidden', $resp->get_data()['error']['code']);
}
```
(If `PasswordVerifier` is injected, stub it to return `$uid`; otherwise rely on real `wp_authenticate` with the factory password. Match the controller's existing constructor seam.)

- [ ] **Step 2: Run it (fails — currently issues a token).**

- [ ] **Step 3: Implement** — immediately after the line that sets `$userId` from `PasswordVerifier::verify(...)`, insert:
```php
$user = get_userdata($userId);
if (!$user || !\Defyn\Dashboard\Auth\DomainPolicy::isAllowedEmail((string) $user->user_email)) {
    return ErrorResponse::create(403, 'auth.domain_forbidden', 'This account is not permitted to sign in.');
}
```

- [ ] **Step 4: Run it (passes); re-run the existing login success test to confirm a `@defyn.com.au` user still logs in.**

- [ ] **Step 5: Commit**
```bash
git add packages/dashboard-plugin/src/Rest/AuthLoginController.php packages/dashboard-plugin/tests/Integration/Rest/AuthLoginControllerTest.php
git commit -m "feat(auth): domain-gate break-glass /auth/login to defyn.com.au"
```

---

### Task 9: CORS coverage for `/auth/google`

**Files:**
- Test: the CORS test file (grep `Access-Control-Allow-Origin` under `tests/`; append a case mirroring an existing route).

- [ ] **Step 1:** `grep -rn "Access-Control-Allow-Origin" packages/dashboard-plugin/tests`. If a CORS test exists, add a case asserting `/auth/google` gets the header via `Cors::apply` (it does automatically — the test just locks it). If NO CORS test exists, skip this task (Cors::apply covers every `/defyn/v1/*` route globally) and note it.

- [ ] **Step 2: Commit (if a test was added)**
```bash
git add packages/dashboard-plugin/tests
git commit -m "test(auth): CORS coverage for /auth/google"
```

---

# PART 2 — Backend: Shared-fleet de-scoping + team branding

**De-scope rule (apply to every method below):** delete the `user_id` predicate from the SQL and its `$wpdb->prepare(...)` argument, keep the method name + signature unchanged, and add a one-line comment: `// Team-shared fleet: per-user filter intentionally removed (2026-06-22 SSO spec).` Each task first extends a test to seed a row created by user **A** and assert the method (called with user **B**'s id, or any id) returns it.

### Task 10: De-scope `SitesRepository`

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/SitesRepository.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/SitesRepositoryTest.php`

Methods to de-scope (remove `WHERE user_id = %d` / `AND user_id = %d`): `findByIdForUser`, `findAllForUser`, `countAllForUser`, `findSitesNeedingAttention`, `countPendingPlugins`, `countPendingThemes`, `countPendingCoresMinor`, `countPendingCoresMajor`, `countSitesWithAnyUpdate`. (Leave `findById` — already user-agnostic — untouched.)

- [ ] **Step 1: Write the failing test** (worked example for `findByIdForUser` + `findAllForUser`; add similar asserts for the counts)
```php
public function testFleetIsTeamWideAcrossUsers(): void
{
    $userA = 11; $userB = 22;
    $siteId = $this->seedSite(['user_id' => $userA, 'url' => 'https://a.example', 'status' => 'active']);
    $repo = new \Defyn\Dashboard\Services\SitesRepository();
    // user B can resolve user A's site
    $this->assertNotNull($repo->findByIdForUser($siteId, $userB));
    // and it appears in user B's fleet list + counts
    $this->assertCount(1, $repo->findAllForUser($userB));
    $this->assertSame(1, $repo->countAllForUser($userB));
}
```
(Use the test class's existing `seedSite()` helper — real cols `id,user_id,url,label,status,created_at,updated_at,wp_version`.)

- [ ] **Step 2: Run it (fails — currently user-scoped).**

- [ ] **Step 3: Implement** — in each listed method, remove the `user_id` clause and its prepare arg. Worked example for `findByIdForUser` (lines ~76–83):
```php
public function findByIdForUser(int $id, int $userId): ?Site
{
    global $wpdb;
    $table = $wpdb->prefix . 'defyn_sites';
    // Team-shared fleet: per-user filter intentionally removed (2026-06-22 SSO spec).
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);
    return $row ? Site::fromRow($row) : null;
}
```
Apply the analogous edit to the other 8 methods (drop `AND user_id = %d` and the matching `$userId` prepare argument; for `findAllForUser` keep the `?string $filter` logic intact, only remove the user predicate).

- [ ] **Step 4: Run the whole `SitesRepositoryTest` (passes — including pre-existing tests; some old tests may have asserted isolation between users — UPDATE those to the team-wide expectation).**

- [ ] **Step 5: Commit**
```bash
git add packages/dashboard-plugin/src/Services/SitesRepository.php packages/dashboard-plugin/tests/Integration/Services/SitesRepositoryTest.php
git commit -m "feat(fleet): de-scope SitesRepository to team-wide visibility"
```

### Task 11: De-scope the remaining repositories

**Files (modify each + its test):**
- `SitePluginsRepository::findAllPendingUpdatesForUser`
- `ThemesRepository::findAllPendingUpdatesForUser`
- `SitePerformanceRepository::findFleetForUser`
- `SiteAnalyticsRepository::findFleetForUser`
- `BrokenLinksRepository::countSitesWithBrokenLinksForUser`
- `SiteVulnerabilitiesRepository::findFleetSummariesForUser`
- `IncidentsRepository::findForUserSince`, `findOpenForUser`
- `ActivityLogRepository::paginateForUser`, `countForUser`, `tailForUser`
- `BulkJobsRepository::findByIdForUser`, `findAllForUser`, `countAllForUser`

- [ ] **Step 1 (per repo): extend its test** to seed a row under user A and assert the method (called with user B / any id) returns it. Mirror Task 10's pattern using each test's own seed helper.
- [ ] **Step 2: run → fails.**
- [ ] **Step 3: remove the `user_id` predicate + prepare arg, keep signatures, add the standard comment.** For `ActivityLogRepository` the queries also filter by `site_id`/`event_type` — keep those, only drop `user_id`. For `BulkJobsRepository::findByIdForUser` drop the `AND user_id` so any team member can cancel/retry any job.
- [ ] **Step 4: run → passes; update any old isolation-asserting tests to team-wide.**
- [ ] **Step 5: commit per repo** (e.g. `git commit -m "feat(fleet): de-scope <Repo> to team-wide"`), so each is an independent, revertible change.

### Task 12: Team-wide report branding + one-time migration

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/BrandingService.php`
- Modify: `packages/dashboard-plugin/src/Plugin.php` (boot — run the guarded migration)
- Test: `packages/dashboard-plugin/tests/Integration/Services/BrandingServiceTest.php`

- [ ] **Step 1: Write the failing test**
```php
public function testBrandingIsSharedAcrossUsers(): void
{
    $svc = new \Defyn\Dashboard\Services\BrandingService();
    $svc->set(11, ['agency_name' => 'Defyn']);
    // a DIFFERENT user reads the same shared brand
    $this->assertSame('Defyn', $svc->get(22)['agency_name']);
}

public function testMigratesLegacyOwnerMetaIntoSharedOption(): void
{
    update_user_meta(1, 'defyn_report_agency_name', 'Legacy Brand');
    delete_option('defyn_branding_migrated');
    delete_option('defyn_report_agency_name');
    \Defyn\Dashboard\Services\BrandingService::migrateLegacyToShared();
    $this->assertSame('Legacy Brand', (new \Defyn\Dashboard\Services\BrandingService())->get(99)['agency_name']);
    $this->assertSame('1', (string) get_option('defyn_branding_migrated'));
}
```

- [ ] **Step 2: run → fails.**

- [ ] **Step 3: Implement** — switch `get`/`set` to `get_option`/`update_option` (signatures unchanged; `$userId` becomes vestigial, comment it), and add the migration:
```php
public function get(int $userId): array // $userId retained for API compat; branding is team-wide
{
    return [
        'agency_name'  => (string) get_option(self::KEY_AGENCY, ''),
        'accent_color' => (string) get_option(self::KEY_ACCENT, '#26215C'),
        'logo_url'     => (string) get_option(self::KEY_LOGO, ''),
    ];
}

public function set(int $userId, array $partial): void
{
    if (array_key_exists('agency_name', $partial)) {
        update_option(self::KEY_AGENCY, (string) $partial['agency_name']);
    }
    if (array_key_exists('accent_color', $partial)) {
        update_option(self::KEY_ACCENT, (string) $partial['accent_color']);
    }
    if (array_key_exists('logo_url', $partial)) {
        update_option(self::KEY_LOGO, (string) $partial['logo_url']);
    }
}

/** One-time: copy the legacy owner's per-user branding into the shared options. */
public static function migrateLegacyToShared(): void
{
    if (get_option('defyn_branding_migrated')) {
        return;
    }
    foreach ([self::KEY_AGENCY, self::KEY_ACCENT, self::KEY_LOGO] as $key) {
        $legacy = get_user_meta(1, $key, true);
        if (is_string($legacy) && $legacy !== '' && get_option($key, '') === '') {
            update_option($key, $legacy);
        }
    }
    update_option('defyn_branding_migrated', '1');
}
```
(The three `KEY_*` consts already exist; they now name options instead of meta keys — same string values are fine.)

- [ ] **Step 4:** In `Plugin.php` boot (alongside the existing schema self-heal on `plugins_loaded`), call `BrandingService::migrateLegacyToShared();` once. Run the suite.

- [ ] **Step 5: Commit**
```bash
git add packages/dashboard-plugin/src/Services/BrandingService.php packages/dashboard-plugin/src/Plugin.php packages/dashboard-plugin/tests/Integration/Services/BrandingServiceTest.php
git commit -m "feat(fleet): team-wide report branding + one-time legacy migration"
```

---

# PART 3 — Dashboard version bump

### Task 13: Bump dashboard `0.29.0 → 0.30.0`

**Files:**
- Modify: `packages/dashboard-plugin/defyn-dashboard.php` (header line 6 + `DEFYN_DASHBOARD_VERSION` line 46)
- Modify: `packages/dashboard-plugin/readme.txt` (`Stable tag:` if present)

- [ ] **Step 1:** Change both `0.29.0` occurrences to `0.30.0`. `grep -rn "0\.29\.0" packages/dashboard-plugin/tests` and bump any version-pin test. **Do NOT change `SchemaVersion`** (no schema change).
- [ ] **Step 2: Commit**
```bash
git add packages/dashboard-plugin/defyn-dashboard.php packages/dashboard-plugin/readme.txt
git commit -m "chore(dashboard): v0.30.0 — Google SSO + shared team fleet"
```

---

# PART 4 — SPA: Google sign-in

### Task 14: Add `@react-oauth/google` + `loginWithGoogle`

**Files:**
- Modify: `apps/web/package.json` (add dep) — run `pnpm add @react-oauth/google`
- Modify: `apps/web/src/lib/auth.tsx`
- Test: `apps/web/tests/auth.loginWithGoogle.test.tsx` (new)

- [ ] **Step 1: Write the failing test** (relies on the MSW `/auth/google` handler from Task 16 — co-author both if running in order)
```tsx
import { describe, it, expect } from 'vitest';
import { renderHook, act, waitFor } from '@testing-library/react';
import { AuthProvider, useAuth } from '../src/lib/auth';

describe('loginWithGoogle', () => {
  it('exchanges a Google credential for a session', async () => {
    const wrapper = ({ children }: { children: React.ReactNode }) => <AuthProvider>{children}</AuthProvider>;
    const { result } = renderHook(() => useAuth(), { wrapper });
    await act(async () => { await result.current.loginWithGoogle('google-credential'); });
    await waitFor(() => expect(result.current.status).toBe('authenticated'));
    expect(result.current.user?.email).toBe('admin@defyn.test');
  });
});
```

- [ ] **Step 2: run → fails (`loginWithGoogle` undefined).**

- [ ] **Step 3: Implement** — extend the AuthContext: add to `AuthContextValue`:
```ts
loginWithGoogle: (credential: string) => Promise<void>;
```
and the callback (mirror `login`):
```ts
const loginWithGoogle = React.useCallback(async (credential: string) => {
  setState((s) => ({ ...s, status: 'authenticating' }));
  try {
    const { access_token } = await apiClient.post<LoginResponse>('/auth/google', { credential });
    setAccessToken(access_token);
    const user = await apiClient.get<User>('/auth/me');
    setState({ status: 'authenticated', user });
  } catch (e) {
    clearAccessToken();
    setState({ status: 'unauthenticated', user: null });
    throw e;
  }
}, []);
```
Add `loginWithGoogle` to the context `value`. Keep `login` (used by tests / hidden break-glass).

- [ ] **Step 4: run → passes.**

- [ ] **Step 5: Commit**
```bash
git add apps/web/package.json apps/web/pnpm-lock.yaml apps/web/src/lib/auth.tsx apps/web/tests/auth.loginWithGoogle.test.tsx
git commit -m "feat(spa): AuthContext.loginWithGoogle + @react-oauth/google dep"
```

### Task 15: Google-only Login screen

**Files:**
- Modify: `apps/web/src/routes/Login.tsx`
- Modify: `apps/web/src/main.tsx` (or wherever providers wrap the app) — wrap with `GoogleOAuthProvider` using `import.meta.env.VITE_GOOGLE_CLIENT_ID`
- Test: `apps/web/tests/Login.test.tsx` (rewrite for Google)

- [ ] **Step 1: Write the failing test** — mock `@react-oauth/google` so `<GoogleLogin>` renders a button that fires `onSuccess`:
```tsx
import { vi } from 'vitest';
vi.mock('@react-oauth/google', () => ({
  GoogleOAuthProvider: ({ children }: any) => <>{children}</>,
  GoogleLogin: ({ onSuccess }: any) => (
    <button onClick={() => onSuccess({ credential: 'google-credential' })}>Sign in with Google</button>
  ),
}));
// render Login within AuthProvider + MemoryRouter (mock useNavigate as navigateMock),
// click the button, assert navigation after loginWithGoogle resolves.
it('signs in with Google and navigates home', async () => {
  renderLogin();
  await userEvent.click(screen.getByRole('button', { name: /sign in with google/i }));
  await waitFor(() => expect(navigateMock).toHaveBeenCalledWith('/'));
});
```

- [ ] **Step 2: run → fails.**

- [ ] **Step 3: Implement** — replace the email/password form in `Login.tsx` with:
```tsx
import { GoogleLogin } from '@react-oauth/google';
// inside the Card:
<GoogleLogin
  onSuccess={async (cred) => {
    setServerError(null);
    try {
      if (!cred.credential) throw new Error('No credential');
      await auth.loginWithGoogle(cred.credential);
      navigate('/');
    } catch (e) {
      setServerError(e instanceof ApiError ? e.message : 'Sign-in failed. Please try again.');
    }
  }}
  onError={() => setServerError('Google sign-in was cancelled or failed.')}
/>
```
Remove `useForm`, the email/password inputs, and the submit button. Keep the `serverError` Alert + the Card shell. Wrap the app once in `GoogleOAuthProvider clientId={import.meta.env.VITE_GOOGLE_CLIENT_ID}` (in `main.tsx`). Add `VITE_GOOGLE_CLIENT_ID?: string` to `apps/web/src/vite-env.d.ts` if a typed env interface exists.

- [ ] **Step 4: run → passes; run the full SPA suite to confirm no other test imported the removed form.**

- [ ] **Step 5: Commit**
```bash
git add apps/web/src/routes/Login.tsx apps/web/src/main.tsx apps/web/tests/Login.test.tsx apps/web/src/vite-env.d.ts
git commit -m "feat(spa): Google-only login screen (remove password form from UI)"
```

### Task 16: MSW `/auth/google` handler

**Files:**
- Modify: `apps/web/src/test/handlers.ts`

- [ ] **Step 1: Add the handler** (mirror `/auth/login`):
```ts
http.post('*/wp-json/defyn/v1/auth/google', async ({ request }) => {
  const body = (await request.json()) as { credential?: string };
  if (!body.credential) {
    return HttpResponse.json({ error: { code: 'auth.google_missing_credential', message: 'A Google credential is required.' } }, { status: 400 });
  }
  if (body.credential === 'wrong-domain') {
    return HttpResponse.json({ error: { code: 'auth.google_domain', message: 'Only defyn.com.au accounts may sign in.' } }, { status: 403 });
  }
  return HttpResponse.json({ access_token: 'fake.access.token' }, { status: 200 });
}),
```

- [ ] **Step 2: Run the suite (Task 14/15 tests now fully green).**

- [ ] **Step 3: Commit**
```bash
git add apps/web/src/test/handlers.ts
git commit -m "test(spa): MSW handler for /auth/google"
```

---

# PART 5 — Release

### Task 17: Full suites + build

- [ ] **Step 1: PHP** — `cd packages/dashboard-plugin && COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit`. Green except the known `UninstallTest` carry-forward. (See Environment note for the standalone mysqld dance if the DB is offline.) Fix any de-scope test that still asserts user-isolation.
- [ ] **Step 2: SPA** — `cd apps/web && export FNM_DIR="$HOME/.fnm"; eval "$(fnm env --shell bash)"; fnm use 22 && pnpm test -- --run` → 0 failures (suite is fully green as of `38f1f43`). Then `pnpm build` (tsc clean).
- [ ] **Step 3: Commit any fixes.**

### Task 18: Build the dompdf-preserving dashboard zip → `dist/defyn-dashboard-0.30.0.zip`

- [ ] **Step 1:** Use the established clean recipe (top folder `dashboard-plugin/`; exclude `.gitignore`/`tests/`/`phpunit.xml`/`.phpunit.result.cache`/`wp-tests-config.php`; KEEP `vendor/`):
```
cd "/Users/pradeep/Local Sites/defynWP"
( cd packages/dashboard-plugin && composer install --no-dev --classmap-authoritative --no-interaction )
mkdir -p .claude-tmp/dbuild_v300/dashboard-plugin
rsync -a --exclude 'tests/' --exclude 'phpunit.xml' --exclude 'phpunit.xml.dist' --exclude '.phpunit.result.cache' \
  --exclude 'wp-tests-config.php' --exclude 'node_modules/' --exclude '.git/' --exclude '.gitignore' \
  --exclude '.claude-tmp/' --exclude '.DS_Store' \
  packages/dashboard-plugin/ .claude-tmp/dbuild_v300/dashboard-plugin/
( cd .claude-tmp/dbuild_v300 && zip -rqX "../../dist/defyn-dashboard-0.30.0.zip" dashboard-plugin )
( cd packages/dashboard-plugin && composer install --no-interaction )
```
- [ ] **Step 2: Verify** the zip: contains `src/Auth/GoogleIdTokenVerifier.php`, `src/Rest/AuthGoogleController.php`, `vendor/firebase/php-jwt/src/JWT.php`, `vendor/dompdf/dompdf/src/Dompdf.php`; header shows `Version: 0.30.0`; NO `tests/`/`.gitignore`/`wp-tests-config.php`. Do NOT use `rm` (blocked) — `mv` stale zips to `.claude-tmp/`.

### Task 19: Merge, deploy, operator setup, smoke, tag, MEMORY

- [ ] **Step 1: Merge** `google-sso-team-access` → `main` (`--no-ff`) + push. SPA auto-deploys from main.
- [ ] **Step 2: OPERATOR (manual — provide exact clicks):** Google Cloud Console → APIs & Services → **Credentials** → Create credentials → **OAuth client ID** → **Web application** → Authorized JavaScript origins: `https://app.defynwp.defyn.agency` (+ `http://localhost:5173`). Set **OAuth consent screen → Internal**. Copy the **Client ID**. Then set `DEFYN_GOOGLE_CLIENT_ID` (Kinsta env) and `VITE_GOOGLE_CLIENT_ID` (Cloudflare build env → redeploy). Install `dist/defyn-dashboard-0.30.0.zip` (WP → Upload → **Replace current**, NEVER delete). PAUSE for "installed".
- [ ] **Step 3: Indirect smoke (curl, no UI):** `POST /auth/google` with no body → 400 `auth.google_missing_credential`; with a bogus credential → 401 `auth.google_invalid`; confirm `/auth/login` with a non-`defyn.com.au` user → 403 `auth.domain_forbidden` (and your own `pradeep@defyn.com.au` break-glass still 200). Confirm the deployed SPA bundle string-contains "Sign in with Google". (Happy Google path needs a real browser sign-in — operator verifies a second `@defyn.com.au` teammate sees the shared fleet.)
- [ ] **Step 4: Tag** `git tag google-sso-team-access-complete && git push --tags`.
- [ ] **Step 5: MEMORY** — record: SSO live, de-scoped fleet (team-wide), branding team-wide, `DEFYN_GOOGLE_CLIENT_ID`/`VITE_GOOGLE_CLIENT_ID` env, dashboard v0.30.0, the `findByIdForUser`-now-team-wide gotcha for future sessions.

---

## Self-Review notes (author)
- **Spec coverage:** Sign-in (T1–T7), domain-gate login (T8), CORS (T9), de-scope all 14 methods (T10–T11), team branding + migration (T12), no-schema (T13), SPA Google login (T14–T16), security/rollout (T19). ✔
- **Vestigial `$userId` params** on de-scoped methods + `BrandingService` are a deliberate, commented trade-off (minimal blast radius on a live app); a future `team_id` refactor renames them to `...ForTeam`. Flag to the code-quality reviewer so it's not mistaken for a bug.
- **Type consistency:** `GoogleAuthException(errorCode, status, message)`, `GoogleIdTokenVerifier::verify(): array`, `UserProvisioner::findOrCreate(array): int`, `loginWithGoogle(credential): Promise<void>` — used consistently across tasks.
- **Verify before coding:** the exact `ErrorResponse::create` + `ActivityLogger::log` signatures and `AuthLoginController`'s IP-extraction line (mirror them); whether a CORS test file exists; the `Plugin.php` boot hook used by the schema self-heal (attach the branding migration there).

---

## Environment notes (carry-forward)
- **DB offline:** if PHPUnit errors on DB connection, start the standalone mysqld 8.0.35 (`~/Library/Application Support/lightning-services/mysql-8.0.35+4/bin/darwin/bin/mysqld`) against the datadir `packages/dashboard-plugin/wp-tests-config.php` expects (READ it; never modify; port ~10166), run, `mysqladmin` shutdown after. Tolerate ONLY `UninstallTest` (baseline 1016/1).
- **SPA:** Node 22 via fnm; `pnpm test -- --run`; suite fully green (519/0) as of `38f1f43` — NO carry-forward failures anymore. `pnpm build` runs tsc (a vitest-green test can still fail the typecheck).
- **`rm` is blocked** — `mv` to `.claude-tmp/`. Avoid `git commit -q`.
