# P2.2 Plugin Updates Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an operator click "Update" on a plugin row in the SPA and have DefynWP actually run the WordPress upgrade on the managed site. Output: connector v0.1.4 + dashboard v0.3.0.

**Architecture:** SPA → dashboard `POST /defyn/v1/sites/{id}/plugins/{slug}/update` (Bearer + 6/hour rate limit per user+site+slug) writes optimistic `update_state='queued'` to `wp_defyn_site_plugins` and schedules a `defyn_update_site_plugin` AS job. The AS job decrypts the per-site Ed25519 key, calls the connector's new `POST /defyn-connector/v1/plugins/{slug}/update` with a 120s HTTP timeout, and branches on the response (200 → mark idle + new version; 409 → exponential-backoff retry; other → mark failed). The connector acquires a per-site transient lock, runs WP's stock `Plugin_Upgrader`, captures upgrader-skin messages, and returns the result. SPA polls the plugins list every 2s while a row is in `queued`/`updating`, settles on `idle` or `failed`, with a hard 5-min cap.

**Tech Stack:** PHP 8.1+ (PHPUnit, `WP_UnitTestCase` / `AbstractSchemaTestCase`), Action Scheduler, Symfony HttpClient (`MockHttpClient` for tests), WordPress REST API + `Plugin_Upgrader`, React 18 + TypeScript + TanStack Query v5 + Zod + Tailwind + shadcn/ui + Vitest + React Testing Library + MSW.

**Spec:** [`docs/superpowers/specs/2026-06-06-p2-2-plugin-updates-design.md`](../specs/2026-06-06-p2-2-plugin-updates-design.md)

---

## Workflow conventions

- **Branch:** Work directly on `main` (matches F-series + P2.1 cadence). Each Task = one atomic commit.
- **Test discipline (TDD):** Step 1 writes the failing test. Step 2 runs it and confirms it fails. Step 3 writes the implementation. Step 4 confirms it passes. Step 5 commits.
- **Test runners:**
  - Connector PHP: `cd packages/connector-plugin && composer test`
  - Dashboard PHP: `cd packages/dashboard-plugin && composer test`
  - SPA: `cd apps/web && pnpm test -- --run`
- **Commit message format:** `<type>(p2-2): <description>` where `<type>` ∈ {feat, fix, refactor, docs, test, chore}.
- **All edits adhere to** `~/.claude/rules/common/coding-style.md` — immutability, KISS, DRY, YAGNI, error handling, no console.log.
- **Cache headers:** the existing `RestRouter::applyNoCacheHeaders` filter (connector v0.1.2 / dashboard v0.1.1) covers the new endpoints. Regression test in Task 5.
- **Signed-request canonical string:** ALWAYS use `Signer::canonical($method, $path, $ts, $nonce, $bodyHash)` — never inline format. (Plan-bug lesson from P2.1 Task 2 — caught + fixed in `89aa6d0`.)
- **Cache-header tests:** must manually invoke `apply_filters('rest_post_dispatch', $res, rest_get_server(), $req)` because `rest_do_request()` skips that filter pipeline. (Plan-bug lesson from P2.1 Task 4 — caught + fixed in `2770cd0`.)
- **`ActivityLogger::log()` signature:** `log(?int $userId, ?int $siteId, string $eventType, ?array $details, ?string $ip)` — `$userId` FIRST. (Plan-bug lesson from P2.1 Task 7 — caught + fixed in `4c65168`.)
- **`SignedHttpClient::signedPostJson` shape:** `(string $url, array $body, string $privateKeyBase64, string $canonicalPath, int $timeoutSeconds = 30): array` returning `['status' => int, 'body' => array, 'error' => string]`. Use Vault decryption + URL building + branch on `error` then `status` (pattern from `SyncService`). (Plan-bug lesson from P2.1 Task 8 — caught + fixed inline.)

---

## File structure overview

### Connector plugin (v0.1.4) — new files

| Path | Responsibility |
|---|---|
| `src/SiteInfo/CapturingUpgraderSkin.php` | Subclass of `WP_Upgrader_Skin` that captures feedback + errors into memory |
| `src/SiteInfo/PluginUpgraderService.php` | Resolve slug → plugin_file, verify update available, drive `Plugin_Upgrader::upgrade()`, return result |
| `src/SiteInfo/PluginUpgradeException.php` | Base class for the three custom exceptions below |
| `src/SiteInfo/UnknownSlugException.php` | Slug not in `get_plugins()` |
| `src/SiteInfo/NoUpdateAvailableException.php` | WP's `update_plugins` transient says no upgrade pending |
| `src/SiteInfo/UpgradeFailedException.php` | `Plugin_Upgrader::upgrade()` returned `false` / `WP_Error` |
| `src/Rest/PluginUpdateController.php` | `POST /plugins/{slug}/update` — slug validation + per-site transient lock + service dispatch + exception → envelope mapping |
| `tests/Unit/SiteInfo/CapturingUpgraderSkinTest.php` | Captures messages from `feedback()` and `error()` |
| `tests/Integration/SiteInfo/PluginUpgraderServiceTest.php` | Slug resolution + success/failure paths with a fake upgrader |
| `tests/Integration/Rest/PluginUpdateTest.php` | Signed POST E2E — all error codes + success |
| `tests/Integration/Rest/PluginUpdateLockTest.php` | Concurrent calls + lock-cleanup on success and on uncaught exception |
| `tests/Integration/Rest/PluginUpdateCacheHeadersTest.php` | `Cache-Control: no-store` regression |

### Connector plugin — modified files

| Path | What changes |
|---|---|
| `src/Rest/RestRouter.php` | Register `POST /plugins/(?P<slug>[a-z0-9-]{1,80})/update` |
| `defyn-connector.php` | Version `0.1.3` → `0.1.4` |
| `readme.txt` | Stable tag + changelog entry |

### Dashboard plugin (v0.3.0) — new files

| Path | Responsibility |
|---|---|
| `src/Jobs/UpdateSitePlugin.php` | AS hook handler for `defyn_update_site_plugin($siteId, $slug, $attempt)` |
| `src/Rest/SitesPluginsUpdateController.php` | `POST /sites/{id}/plugins/{slug}/update` — auth + guards + optimistic write + AS schedule |
| `tests/Integration/Schema/SchemaV3MigrationTest.php` | Three new columns + index on `wp_defyn_site_plugins` |
| `tests/Unit/Services/SitePluginsRepositoryUpdateStateTest.php` | New `findRowForSiteAndSlug` + four mark* methods |
| `tests/Unit/Http/SignedHttpClientTimeoutTest.php` | Optional timeoutSeconds param threads through to Symfony HttpClient options |
| `tests/Integration/Jobs/UpdateSitePluginTest.php` | AS job success / 409 retry / non-409 failure |
| `tests/Integration/Rest/SitesPluginsUpdateTest.php` | REST E2E — all 5 error codes + 202 success |
| `tests/Integration/Rest/SitesPluginsUpdateCorsTest.php` | CORS + envelope normalisation |
| `tests/Integration/Rest/RateLimitPluginsUpdateTest.php` | 7th call in 1 hour returns 429 |
| `tests/Integration/PluginBootASHookTest.php` | `defyn_update_site_plugin` hook registered on boot |

### Dashboard plugin — modified files

| Path | What changes |
|---|---|
| `src/Schema/SitePluginsTable.php` | Add `update_state` + `last_update_error` + `last_update_attempt_at` columns + `KEY update_state` |
| `src/Activation.php` | Bump `SCHEMA_VERSION` from `2` to `3` |
| `src/Services/SitePluginsRepository.php` | Add `findRowForSiteAndSlug`, `markUpdateRequested`, `markUpdating`, `markUpdateSucceeded`, `markUpdateFailed` |
| `src/Http/SignedHttpClient.php` | Add `int $timeoutSeconds = 30` final param on `signedPostJson` and `signedGet` |
| `src/Rest/Middleware/RateLimit.php` | Add static `pluginsUpdate` permission_callback (6/hour per (user, site, slug)) |
| `src/Rest/RestRouter.php` | Register `POST /sites/(?P<id>\d+)/plugins/(?P<slug>[a-z0-9-]{1,80})/update` |
| `src/Plugin.php` | Register `defyn_update_site_plugin` AS hook handler |
| `tests/Integration/AbstractSchemaTestCase.php` | Add `describeTable()` + `assertHasIndex()` helpers |
| `defyn-dashboard.php` | Version `0.2.0` → `0.3.0` |
| `readme.txt` | Stable tag + changelog entry |

### SPA — new files

| Path | Responsibility |
|---|---|
| `src/components/sites/SitePluginUpdateConfirmDialog.tsx` | shadcn AlertDialog with version diff + safety paragraph |
| `src/components/ui/tooltip.tsx` | Manually-written shadcn Tooltip primitive (mirrors switch.tsx pattern) |
| `src/lib/mutations/useUpdateSitePlugin.ts` | mutation hook + 2s polling that stops on idle/failed (5min cap) |

### SPA — modified files

| Path | What changes |
|---|---|
| `src/types/api/plugins.ts` | Extend `pluginSchema` with `update_state`, `last_update_error`, `last_update_attempt_at`. Add `updateSitePluginResponseSchema`. |
| `src/test/handlers.ts` | New MSW POST handler for `/sites/:id/plugins/:slug/update` that simulates queued → updating → idle progression with version bump |
| `src/components/sites/SitePluginsRow.tsx` | Three visual states (idle / in-flight / failed); Retry label flip + ⚠ icon + Tooltip on failed |
| `apps/web/package.json` | Add `@radix-ui/react-tooltip` (shadcn Tooltip primitive backing) |

---

## Task list (20 tasks)

### Task 1: `CapturingUpgraderSkin` — capture upgrader messages

**Files:**
- Create: `packages/connector-plugin/src/SiteInfo/CapturingUpgraderSkin.php`
- Test: `packages/connector-plugin/tests/Unit/SiteInfo/CapturingUpgraderSkinTest.php`

The connector needs a `WP_Upgrader_Skin` subclass that records `feedback()` + `error()` calls so we can report the last error message back to the dashboard. We don't echo to STDOUT.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Defyn\Connector\Tests\Unit\SiteInfo;

use Defyn\Connector\SiteInfo\CapturingUpgraderSkin;
use PHPUnit\Framework\TestCase;

final class CapturingUpgraderSkinTest extends TestCase
{
    public function testFeedbackAccumulates(): void
    {
        $skin = new CapturingUpgraderSkin();
        $skin->feedback('Downloading update from %s.', 'https://example.test/plugin.zip');
        $skin->feedback('Unpacking the update.');

        $this->assertSame([
            'Downloading update from https://example.test/plugin.zip.',
            'Unpacking the update.',
        ], $skin->messages());
    }

    public function testErrorWithStringAccumulates(): void
    {
        $skin = new CapturingUpgraderSkin();
        $skin->error('Could not copy file.');

        $this->assertSame(['Could not copy file.'], $skin->errors());
        $this->assertSame('Could not copy file.', $skin->lastErrorMessage());
    }

    public function testErrorWithWpErrorAccumulatesEveryMessage(): void
    {
        $skin = new CapturingUpgraderSkin();
        $wpError = new \WP_Error('download_failed', 'Download failed.');
        $wpError->add('extract_failed', 'Extraction failed.');
        $skin->error($wpError);

        $this->assertSame(['Download failed.', 'Extraction failed.'], $skin->errors());
        $this->assertSame('Extraction failed.', $skin->lastErrorMessage());
    }

    public function testLastErrorMessageIsNullBeforeAnyError(): void
    {
        $skin = new CapturingUpgraderSkin();
        $this->assertNull($skin->lastErrorMessage());
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

```
cd packages/connector-plugin && composer test -- --filter CapturingUpgraderSkinTest
```

Expected: FAIL with `Class "Defyn\Connector\SiteInfo\CapturingUpgraderSkin" not found`.

- [ ] **Step 3: Implement**

```php
<?php
declare(strict_types=1);

namespace Defyn\Connector\SiteInfo;

if (!class_exists(\WP_Upgrader_Skin::class)) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader-skin.php';
}

/**
 * Silent upgrader skin that records every feedback() and error() call.
 *
 * We need a skin to pass to WP's Plugin_Upgrader, but we don't want it to
 * echo HTML to the request body (Plugin_Upgrader's default skin does that
 * because it expects to run inside wp-admin). This subclass collects each
 * message into an in-memory array so the caller can fish out the last error
 * and surface it to the dashboard.
 */
final class CapturingUpgraderSkin extends \WP_Upgrader_Skin
{
    /** @var list<string> */
    private array $messages = [];

    /** @var list<string> */
    private array $errors = [];

    /**
     * @param string|\WP_Error $feedback
     */
    public function feedback($feedback, ...$args): void
    {
        if ($feedback instanceof \WP_Error) {
            foreach ($feedback->get_error_messages() as $message) {
                $this->messages[] = (string) $message;
            }
            return;
        }
        if (!is_string($feedback) || $feedback === '') {
            return;
        }
        $this->messages[] = $args === [] ? $feedback : vsprintf($feedback, $args);
    }

    /**
     * @param string|\WP_Error $errors
     */
    public function error($errors): void
    {
        if ($errors instanceof \WP_Error) {
            foreach ($errors->get_error_messages() as $message) {
                $this->errors[] = (string) $message;
            }
            return;
        }
        if (is_string($errors) && $errors !== '') {
            $this->errors[] = $errors;
        }
    }

    /** @return list<string> */
    public function messages(): array
    {
        return $this->messages;
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function lastErrorMessage(): ?string
    {
        if ($this->errors === []) {
            return null;
        }
        return $this->errors[array_key_last($this->errors)];
    }
}
```

- [ ] **Step 4: Run the test, verify it passes**

```
cd packages/connector-plugin && composer test -- --filter CapturingUpgraderSkinTest
```

Expected: PASS (4/4).

- [ ] **Step 5: Commit**

```bash
git add packages/connector-plugin/src/SiteInfo/CapturingUpgraderSkin.php \
        packages/connector-plugin/tests/Unit/SiteInfo/CapturingUpgraderSkinTest.php
git commit -m "feat(p2-2): CapturingUpgraderSkin records upgrader feedback + errors"
```

---

### Task 2: `PluginUpgraderService` — drive `Plugin_Upgrader`

**Files:**
- Create: `packages/connector-plugin/src/SiteInfo/PluginUpgradeException.php`
- Create: `packages/connector-plugin/src/SiteInfo/UnknownSlugException.php`
- Create: `packages/connector-plugin/src/SiteInfo/NoUpdateAvailableException.php`
- Create: `packages/connector-plugin/src/SiteInfo/UpgradeFailedException.php`
- Create: `packages/connector-plugin/src/SiteInfo/PluginUpgraderService.php`
- Test: `packages/connector-plugin/tests/Integration/SiteInfo/PluginUpgraderServiceTest.php`

Resolves a slug to its `plugin_file`, verifies WordPress knows about an update, then runs `Plugin_Upgrader::upgrade()` through a `CapturingUpgraderSkin`. Returns the spec-shape array on success, throws one of three exceptions on failure.

Tests use real `WP_UnitTestCase` (gives us a working `get_plugins()` + `get_site_transient`). The service receives the upgrader instance via constructor injection so the test can swap in a stub that returns success/false/WP_Error.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\SiteInfo;

use Defyn\Connector\SiteInfo\CapturingUpgraderSkin;
use Defyn\Connector\SiteInfo\NoUpdateAvailableException;
use Defyn\Connector\SiteInfo\PluginUpgraderService;
use Defyn\Connector\SiteInfo\UnknownSlugException;
use Defyn\Connector\SiteInfo\UpgradeFailedException;
use WP_UnitTestCase;

final class PluginUpgraderServiceTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_site_transient('update_plugins');
    }

    public function testUnknownSlugThrows(): void
    {
        $service = new PluginUpgraderService(fn () => $this->fail('upgrader factory should not be called for unknown slug'));

        $this->expectException(UnknownSlugException::class);
        $this->expectExceptionMessage('definitely-not-installed');
        $service->upgrade('definitely-not-installed');
    }

    public function testNoUpdateAvailableThrows(): void
    {
        // hello.php is shipped with WP for tests.
        // No update_plugins transient → no update available
        $service = new PluginUpgraderService(fn () => $this->fail('upgrader factory should not be called'));

        $this->expectException(NoUpdateAvailableException::class);
        $this->expectExceptionMessage('hello');
        $service->upgrade('hello');
    }

    public function testUpgradeFailedWhenUpgraderReturnsFalse(): void
    {
        $this->seedUpdateAvailable('hello', 'hello.php', '1.7.3');

        $service = new PluginUpgraderService(function (CapturingUpgraderSkin $skin) {
            $skin->error('Could not copy file.');
            return new class { public function upgrade(string $pluginFile) { return false; } };
        });

        $this->expectException(UpgradeFailedException::class);
        $this->expectExceptionMessage('Could not copy file.');
        $service->upgrade('hello');
    }

    public function testUpgradeFailedWhenUpgraderReturnsWpError(): void
    {
        $this->seedUpdateAvailable('hello', 'hello.php', '1.7.3');

        $service = new PluginUpgraderService(fn () => new class {
            public function upgrade(string $pluginFile) {
                return new \WP_Error('download_failed', 'HTTP 404 from update_uri.');
            }
        });

        $this->expectException(UpgradeFailedException::class);
        $this->expectExceptionMessage('HTTP 404 from update_uri.');
        $service->upgrade('hello');
    }

    public function testUpgradeSucceedsAndReturnsExpectedShape(): void
    {
        $this->seedUpdateAvailable('hello', 'hello.php', '1.7.3');

        // Stub returns true; reading the new version after the call would normally
        // require WP to have actually swapped files. For the test we just verify
        // shape + that previous_version came from get_plugins() BEFORE the call.
        $service = new PluginUpgraderService(fn () => new class {
            public function upgrade(string $pluginFile) { return true; }
        });

        $before = time();
        $result = $service->upgrade('hello');
        $after = time();

        $this->assertTrue($result['success']);
        $this->assertSame('hello', $result['slug']);
        $this->assertSame('1.6.2', $result['previous_version']); // hello.php ships at 1.6.2 in WP test fixtures
        $this->assertSame('1.6.2', $result['new_version']); // stub didn't change files, so re-read returns the same
        $this->assertIsInt($result['server_time']);
        $this->assertGreaterThanOrEqual($before, $result['server_time']);
        $this->assertLessThanOrEqual($after, $result['server_time']);
    }

    /**
     * Stand up the update_plugins transient shape WP expects so
     * isset($updates->response[$pluginFile]) is true.
     */
    private function seedUpdateAvailable(string $folder, string $pluginFile, string $newVersion): void
    {
        $update = new \stdClass();
        $update->response = [
            $folder . '/' . $pluginFile => (object) [
                'slug'        => $folder,
                'new_version' => $newVersion,
                'package'     => 'https://example.test/plugin.zip',
            ],
        ];
        set_site_transient('update_plugins', $update);
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

```
cd packages/connector-plugin && composer test -- --filter PluginUpgraderServiceTest
```

Expected: FAIL with `Class "Defyn\Connector\SiteInfo\PluginUpgraderService" not found`.

- [ ] **Step 3: Implement — exceptions first**

```php
<?php
// src/SiteInfo/PluginUpgradeException.php
declare(strict_types=1);

namespace Defyn\Connector\SiteInfo;

abstract class PluginUpgradeException extends \RuntimeException {}
```

```php
<?php
// src/SiteInfo/UnknownSlugException.php
declare(strict_types=1);

namespace Defyn\Connector\SiteInfo;

final class UnknownSlugException extends PluginUpgradeException
{
    public function __construct(string $slug)
    {
        parent::__construct($slug);
    }
}
```

```php
<?php
// src/SiteInfo/NoUpdateAvailableException.php
declare(strict_types=1);

namespace Defyn\Connector\SiteInfo;

final class NoUpdateAvailableException extends PluginUpgradeException
{
    public function __construct(string $slug)
    {
        parent::__construct($slug);
    }
}
```

```php
<?php
// src/SiteInfo/UpgradeFailedException.php
declare(strict_types=1);

namespace Defyn\Connector\SiteInfo;

final class UpgradeFailedException extends PluginUpgradeException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
```

Then the service:

```php
<?php
// src/SiteInfo/PluginUpgraderService.php
declare(strict_types=1);

namespace Defyn\Connector\SiteInfo;

/**
 * Runs WordPress's Plugin_Upgrader on the requested slug.
 *
 * Slug resolution: WordPress identifies plugins by their main file
 * (e.g. "akismet/akismet.php"). Operators (and the dashboard) only know
 * the folder name. We map folder → main file via get_plugins().
 *
 * The upgrader factory is constructor-injected so tests can swap in a
 * stub that returns true / false / WP_Error without touching disk.
 * In production the factory returns a real \Plugin_Upgrader instance.
 */
final class PluginUpgraderService
{
    /** @var callable(CapturingUpgraderSkin): object */
    private $upgraderFactory;

    /**
     * @param callable(CapturingUpgraderSkin): object|null $upgraderFactory
     */
    public function __construct(?callable $upgraderFactory = null)
    {
        $this->upgraderFactory = $upgraderFactory ?? self::defaultUpgraderFactory();
    }

    /**
     * @return array{success: true, slug: string, previous_version: string, new_version: string, server_time: int}
     */
    public function upgrade(string $slug): array
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $pluginFile = null;
        $previousVersion = '';
        foreach (get_plugins() as $file => $data) {
            $folder = strtok($file, '/');
            if ($folder === $slug) {
                $pluginFile = $file;
                $previousVersion = (string) ($data['Version'] ?? '');
                break;
            }
        }
        if ($pluginFile === null) {
            throw new UnknownSlugException($slug);
        }

        $updates = get_site_transient('update_plugins');
        if (!isset($updates->response[$pluginFile])) {
            throw new NoUpdateAvailableException($slug);
        }

        $skin     = new CapturingUpgraderSkin();
        $upgrader = ($this->upgraderFactory)($skin);
        $result   = $upgrader->upgrade($pluginFile);

        if ($result === false) {
            $message = $skin->lastErrorMessage() ?? 'Plugin_Upgrader returned false without a message.';
            throw new UpgradeFailedException($message);
        }
        if (is_wp_error($result)) {
            throw new UpgradeFailedException((string) $result->get_error_message());
        }

        // Re-read the version after the upgrade. In production this picks up the
        // new version from disk; under test the stub doesn't actually swap files,
        // so we'll see the same version back.
        $newVersion = $previousVersion;
        foreach (get_plugins() as $file => $data) {
            if ($file === $pluginFile) {
                $newVersion = (string) ($data['Version'] ?? $previousVersion);
                break;
            }
        }

        return [
            'success'          => true,
            'slug'             => $slug,
            'previous_version' => $previousVersion,
            'new_version'      => $newVersion,
            'server_time'      => time(),
        ];
    }

    /** @return callable(CapturingUpgraderSkin): object */
    private static function defaultUpgraderFactory(): callable
    {
        return static function (CapturingUpgraderSkin $skin): object {
            if (!class_exists(\Plugin_Upgrader::class)) {
                require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
                require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
            }
            return new \Plugin_Upgrader($skin);
        };
    }
}
```

- [ ] **Step 4: Run the test, verify it passes**

```
cd packages/connector-plugin && composer test -- --filter PluginUpgraderServiceTest
```

Expected: PASS (5/5).

- [ ] **Step 5: Commit**

```bash
git add packages/connector-plugin/src/SiteInfo/PluginUpgraderService.php \
        packages/connector-plugin/src/SiteInfo/PluginUpgradeException.php \
        packages/connector-plugin/src/SiteInfo/UnknownSlugException.php \
        packages/connector-plugin/src/SiteInfo/NoUpdateAvailableException.php \
        packages/connector-plugin/src/SiteInfo/UpgradeFailedException.php \
        packages/connector-plugin/tests/Integration/SiteInfo/PluginUpgraderServiceTest.php
git commit -m "feat(p2-2): PluginUpgraderService runs Plugin_Upgrader on a slug"
```

---

### Task 3: `PluginUpdateController` — signed POST endpoint with per-site lock

**Files:**
- Create: `packages/connector-plugin/src/Rest/PluginUpdateController.php`
- Test: `packages/connector-plugin/tests/Integration/Rest/PluginUpdateTest.php`

The controller validates the slug, acquires a per-site transient lock, calls the service, and maps exceptions to spec-shape error envelopes. The lock pattern uses try/finally so a thrown exception still releases the lock.

This task covers the happy path + all error envelopes EXCEPT the lock-collision case (covered in Task 4). The route registration happens in Task 5 — for this task we'll invoke the controller via `rest_do_request` after registering the route inside the test setup. Task 5 moves the registration to `RestRouter::register()`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Rest;

use Defyn\Connector\Crypto\Signer;
use Defyn\Connector\Persistence\ConnectorState;
use Defyn\Connector\Rest\PluginUpdateController;
use WP_REST_Request;
use WP_UnitTestCase;

final class PluginUpdateTest extends WP_UnitTestCase
{
    private string $privateKeyBase64;
    private string $publicKeyBase64;

    protected function setUp(): void
    {
        parent::setUp();
        delete_site_transient('update_plugins');
        delete_transient('defyn_connector_upgrade_in_flight');

        // Generate an Ed25519 keypair and set the connector state to "connected"
        // so VerifySignatureMiddleware doesn't short-circuit.
        $keypair = sodium_crypto_sign_keypair();
        $secret  = sodium_crypto_sign_secretkey($keypair);
        $public  = sodium_crypto_sign_publickey($keypair);
        $this->privateKeyBase64 = base64_encode($secret);
        $this->publicKeyBase64  = base64_encode($public);

        (new ConnectorState())->update([
            'state'                 => 'connected',
            'dashboard_public_key'  => $this->publicKeyBase64,
            'connected_at'          => gmdate('Y-m-d H:i:s'),
        ]);

        // Register the route under test (will be moved to RestRouter in Task 5).
        register_rest_route('defyn-connector/v1', '/plugins/(?P<slug>[a-z0-9-]{1,80})/update', [
            'methods'             => 'POST',
            'callback'            => [new PluginUpdateController(), 'handle'],
            'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
        ]);
    }

    public function testUnknownSlugReturns404(): void
    {
        $this->seedUpdateAvailable('hello', 'hello.php', '1.7.3');
        $res = $this->sendSigned('definitely-not-installed');

        $this->assertSame(404, $res->get_status());
        $this->assertSame('plugins.unknown_slug', $res->get_data()['error']['code']);
    }

    public function testNoUpdateAvailableReturns409(): void
    {
        // hello.php is present in test fixtures; transient is empty (setUp)
        $res = $this->sendSigned('hello');

        $this->assertSame(409, $res->get_status());
        $this->assertSame('plugins.no_update_available', $res->get_data()['error']['code']);
    }

    public function testSuccessReturns200WithExpectedShape(): void
    {
        $this->seedUpdateAvailable('hello', 'hello.php', '1.7.3');

        // Replace the service factory with one whose upgrader stub returns true
        $controller = new PluginUpdateController(
            new \Defyn\Connector\SiteInfo\PluginUpgraderService(
                fn () => new class { public function upgrade(string $pluginFile) { return true; } }
            )
        );
        register_rest_route('defyn-connector/v1', '/plugins/(?P<slug>[a-z0-9-]{1,80})/update', [
            'methods'             => 'POST',
            'callback'            => [$controller, 'handle'],
            'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
        ], true);

        $res = $this->sendSigned('hello');

        $this->assertSame(200, $res->get_status());
        $data = $res->get_data();
        $this->assertTrue($data['success']);
        $this->assertSame('hello', $data['slug']);
        $this->assertSame('1.6.2', $data['previous_version']);
        $this->assertIsInt($data['server_time']);
    }

    public function testUpgradeFailureReturns502(): void
    {
        $this->seedUpdateAvailable('hello', 'hello.php', '1.7.3');

        $controller = new PluginUpdateController(
            new \Defyn\Connector\SiteInfo\PluginUpgraderService(
                function (\Defyn\Connector\SiteInfo\CapturingUpgraderSkin $skin) {
                    $skin->error('Could not copy file. /wp-content/upgrade/hello/hello.php');
                    return new class { public function upgrade(string $pluginFile) { return false; } };
                }
            )
        );
        register_rest_route('defyn-connector/v1', '/plugins/(?P<slug>[a-z0-9-]{1,80})/update', [
            'methods'             => 'POST',
            'callback'            => [$controller, 'handle'],
            'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
        ], true);

        $res = $this->sendSigned('hello');

        $this->assertSame(502, $res->get_status());
        $this->assertSame('plugins.update_failed', $res->get_data()['error']['code']);
        $this->assertStringContainsString('Could not copy file', $res->get_data()['error']['message']);
    }

    public function testInvalidSlugReturns404FromRouter(): void
    {
        // Invalid char in slug → WP router rejects before reaching the controller
        // (rest_no_route → 404 → normalised to rest.route_not_found by RestRouter
        // filter in production; in this isolated test we expect raw rest_no_route)
        $request = new WP_REST_Request('POST', '/defyn-connector/v1/plugins/INVALID/update');
        $res = rest_do_request($request);
        $this->assertSame(404, $res->get_status());
    }

    private function sendSigned(string $slug): \WP_REST_Response
    {
        $ts        = (string) time();
        $nonce     = bin2hex(random_bytes(16));
        $canonical = Signer::canonical('POST', '/defyn-connector/v1/plugins/' . $slug . '/update', $ts, $nonce, '');
        $sig       = base64_encode(sodium_crypto_sign_detached(
            $canonical,
            base64_decode($this->privateKeyBase64)
        ));

        $request = new WP_REST_Request('POST', '/defyn-connector/v1/plugins/' . $slug . '/update');
        $request->set_header('X-Defyn-Timestamp', $ts);
        $request->set_header('X-Defyn-Nonce', $nonce);
        $request->set_header('X-Defyn-Signature', $sig);

        return rest_do_request($request);
    }

    private function seedUpdateAvailable(string $folder, string $pluginFile, string $newVersion): void
    {
        $update = new \stdClass();
        $update->response = [
            $folder . '/' . $pluginFile => (object) [
                'slug'        => $folder,
                'new_version' => $newVersion,
                'package'     => 'https://example.test/plugin.zip',
            ],
        ];
        set_site_transient('update_plugins', $update);
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

```
cd packages/connector-plugin && composer test -- --filter PluginUpdateTest
```

Expected: FAIL with `Class "Defyn\Connector\Rest\PluginUpdateController" not found`.

- [ ] **Step 3: Implement**

```php
<?php
declare(strict_types=1);

namespace Defyn\Connector\Rest;

use Defyn\Connector\Rest\Responses\ErrorResponse;
use Defyn\Connector\SiteInfo\NoUpdateAvailableException;
use Defyn\Connector\SiteInfo\PluginUpgraderService;
use Defyn\Connector\SiteInfo\UnknownSlugException;
use Defyn\Connector\SiteInfo\UpgradeFailedException;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /defyn-connector/v1/plugins/{slug}/update — signed.
 *
 * Acquires a per-site transient lock so two concurrent upgrade requests
 * on the same install serialise (second returns 409). The lock is
 * released in a finally block so a thrown exception still releases it.
 *
 * Spec: docs/superpowers/specs/2026-06-06-p2-2-plugin-updates-design.md §3
 */
final class PluginUpdateController
{
    private const LOCK_KEY = 'defyn_connector_upgrade_in_flight';
    private const LOCK_TTL = 600; // 10 min

    public function __construct(private readonly PluginUpgraderService $service = new PluginUpgraderService())
    {
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $slug = (string) $request->get_param('slug');

        $existingLock = get_transient(self::LOCK_KEY);
        if ($existingLock !== false) {
            return ErrorResponse::create(
                409,
                'plugins.update_in_progress',
                sprintf('Another upgrade is in progress (%s).', $existingLock)
            );
        }

        set_transient(self::LOCK_KEY, $slug, self::LOCK_TTL);

        try {
            $result = $this->service->upgrade($slug);
            return new WP_REST_Response($result, 200);
        } catch (UnknownSlugException $e) {
            return ErrorResponse::create(404, 'plugins.unknown_slug', sprintf('Plugin "%s" is not installed.', $e->getMessage()));
        } catch (NoUpdateAvailableException $e) {
            return ErrorResponse::create(409, 'plugins.no_update_available', sprintf('No update available for "%s".', $e->getMessage()));
        } catch (UpgradeFailedException $e) {
            return ErrorResponse::create(502, 'plugins.update_failed', $e->getMessage());
        } finally {
            delete_transient(self::LOCK_KEY);
        }
    }
}
```

- [ ] **Step 4: Run the test, verify it passes**

```
cd packages/connector-plugin && composer test -- --filter PluginUpdateTest
```

Expected: PASS (5/5).

- [ ] **Step 5: Commit**

```bash
git add packages/connector-plugin/src/Rest/PluginUpdateController.php \
        packages/connector-plugin/tests/Integration/Rest/PluginUpdateTest.php
git commit -m "feat(p2-2): POST /defyn-connector/v1/plugins/{slug}/update signed endpoint"
```

---

### Task 4: Lock collision + cleanup-on-exception regression

**Files:**
- Test: `packages/connector-plugin/tests/Integration/Rest/PluginUpdateLockTest.php`

Two concurrent calls: the second hits `plugins.update_in_progress` 409. The lock is released even when the service throws (which means the controller's `finally` is honoured), so a follow-up call lands clean.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Rest;

use Defyn\Connector\Crypto\Signer;
use Defyn\Connector\Persistence\ConnectorState;
use Defyn\Connector\Rest\PluginUpdateController;
use Defyn\Connector\SiteInfo\PluginUpgraderService;
use WP_REST_Request;
use WP_UnitTestCase;

final class PluginUpdateLockTest extends WP_UnitTestCase
{
    private string $privateKeyBase64;

    protected function setUp(): void
    {
        parent::setUp();
        delete_site_transient('update_plugins');
        delete_transient('defyn_connector_upgrade_in_flight');

        $keypair = sodium_crypto_sign_keypair();
        $this->privateKeyBase64 = base64_encode(sodium_crypto_sign_secretkey($keypair));

        (new ConnectorState())->update([
            'state'                => 'connected',
            'dashboard_public_key' => base64_encode(sodium_crypto_sign_publickey($keypair)),
            'connected_at'         => gmdate('Y-m-d H:i:s'),
        ]);

        $this->seedUpdateAvailable('hello', 'hello.php', '1.7.3');
    }

    public function testLockReleasedOnSuccess(): void
    {
        $this->registerWithSuccessfulStub();

        $res1 = $this->sendSigned('hello');
        $this->assertSame(200, $res1->get_status());

        $this->assertFalse(get_transient('defyn_connector_upgrade_in_flight'));

        $res2 = $this->sendSigned('hello');
        $this->assertSame(200, $res2->get_status());
    }

    public function testLockReleasedOnFailure(): void
    {
        $this->registerWithFailingStub();

        $res1 = $this->sendSigned('hello');
        $this->assertSame(502, $res1->get_status());

        $this->assertFalse(get_transient('defyn_connector_upgrade_in_flight'));
    }

    public function testSecondCallWhileLockHeldReturns409(): void
    {
        // Simulate a held lock by setting the transient by hand
        set_transient('defyn_connector_upgrade_in_flight', 'other-plugin', 600);
        $this->registerWithSuccessfulStub();

        $res = $this->sendSigned('hello');

        $this->assertSame(409, $res->get_status());
        $this->assertSame('plugins.update_in_progress', $res->get_data()['error']['code']);
        $this->assertStringContainsString('other-plugin', $res->get_data()['error']['message']);
    }

    private function registerWithSuccessfulStub(): void
    {
        $controller = new PluginUpdateController(
            new PluginUpgraderService(fn () => new class { public function upgrade(string $pluginFile) { return true; } })
        );
        register_rest_route('defyn-connector/v1', '/plugins/(?P<slug>[a-z0-9-]{1,80})/update', [
            'methods'             => 'POST',
            'callback'            => [$controller, 'handle'],
            'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
        ], true);
    }

    private function registerWithFailingStub(): void
    {
        $controller = new PluginUpdateController(
            new PluginUpgraderService(function (\Defyn\Connector\SiteInfo\CapturingUpgraderSkin $skin) {
                $skin->error('Synthetic test failure.');
                return new class { public function upgrade(string $pluginFile) { return false; } };
            })
        );
        register_rest_route('defyn-connector/v1', '/plugins/(?P<slug>[a-z0-9-]{1,80})/update', [
            'methods'             => 'POST',
            'callback'            => [$controller, 'handle'],
            'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
        ], true);
    }

    private function sendSigned(string $slug): \WP_REST_Response
    {
        $ts        = (string) time();
        $nonce     = bin2hex(random_bytes(16));
        $canonical = Signer::canonical('POST', '/defyn-connector/v1/plugins/' . $slug . '/update', $ts, $nonce, '');
        $sig       = base64_encode(sodium_crypto_sign_detached($canonical, base64_decode($this->privateKeyBase64)));

        $request = new WP_REST_Request('POST', '/defyn-connector/v1/plugins/' . $slug . '/update');
        $request->set_header('X-Defyn-Timestamp', $ts);
        $request->set_header('X-Defyn-Nonce', $nonce);
        $request->set_header('X-Defyn-Signature', $sig);
        return rest_do_request($request);
    }

    private function seedUpdateAvailable(string $folder, string $pluginFile, string $newVersion): void
    {
        $update = new \stdClass();
        $update->response = [
            $folder . '/' . $pluginFile => (object) ['slug' => $folder, 'new_version' => $newVersion, 'package' => 'https://example.test/plugin.zip'],
        ];
        set_site_transient('update_plugins', $update);
    }
}
```

- [ ] **Step 2: Run the test, verify it passes (Task 3's implementation already satisfies the contract)**

```
cd packages/connector-plugin && composer test -- --filter PluginUpdateLockTest
```

Expected: PASS (3/3). If a test fails, the `finally` in Task 3 is broken — fix it there before continuing.

- [ ] **Step 3: No new code — lock implementation in Task 3 already satisfies these tests.**

- [ ] **Step 4: Run the entire connector suite to make sure no regressions**

```
cd packages/connector-plugin && composer test
```

Expected: ALL PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/connector-plugin/tests/Integration/Rest/PluginUpdateLockTest.php
git commit -m "test(p2-2): per-site lock collision + cleanup-on-exception regression"
```

---

### Task 5: Cache headers regression + register route in RestRouter

**Files:**
- Modify: `packages/connector-plugin/src/Rest/RestRouter.php`
- Test: `packages/connector-plugin/tests/Integration/Rest/PluginUpdateCacheHeadersTest.php`

Two related concerns: (1) the route should live in `RestRouter::register()` (where the rest of the connector's routes are wired), and (2) it must emit `Cache-Control: no-store` like every other defyn-connector/v1 route. The cache-headers test must manually invoke `apply_filters('rest_post_dispatch', ...)` because `rest_do_request()` skips that pipeline (P2.1 Task 4 lesson).

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Rest;

use Defyn\Connector\Crypto\Signer;
use Defyn\Connector\Persistence\ConnectorState;
use Defyn\Connector\Rest\PluginUpdateController;
use Defyn\Connector\Rest\RestRouter;
use Defyn\Connector\SiteInfo\PluginUpgraderService;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

final class PluginUpdateCacheHeadersTest extends WP_UnitTestCase
{
    private string $privateKeyBase64;

    protected function setUp(): void
    {
        parent::setUp();
        delete_site_transient('update_plugins');
        delete_transient('defyn_connector_upgrade_in_flight');

        $keypair = sodium_crypto_sign_keypair();
        $this->privateKeyBase64 = base64_encode(sodium_crypto_sign_secretkey($keypair));

        (new ConnectorState())->update([
            'state'                => 'connected',
            'dashboard_public_key' => base64_encode(sodium_crypto_sign_publickey($keypair)),
            'connected_at'         => gmdate('Y-m-d H:i:s'),
        ]);

        // Set up update_plugins transient so the controller reaches the upgrader
        $update = new \stdClass();
        $update->response = ['hello/hello.php' => (object) ['slug' => 'hello', 'new_version' => '1.7.3', 'package' => 'https://example.test/plugin.zip']];
        set_site_transient('update_plugins', $update);

        // RestRouter::register() must have registered the route during this run
        (new RestRouter())->register();

        // Patch the existing route's callback with a success-stubbed controller
        $controller = new PluginUpdateController(
            new PluginUpgraderService(fn () => new class { public function upgrade(string $pluginFile) { return true; } })
        );
        register_rest_route('defyn-connector/v1', '/plugins/(?P<slug>[a-z0-9-]{1,80})/update', [
            'methods'             => 'POST',
            'callback'            => [$controller, 'handle'],
            'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
        ], true);
    }

    public function testSuccessResponseGetsNoStoreHeaders(): void
    {
        $request = $this->makeSignedRequest('hello');
        $response = rest_do_request($request);
        $this->assertSame(200, $response->get_status());

        // rest_do_request skips rest_post_dispatch — invoke the filter ourselves
        $filtered = apply_filters('rest_post_dispatch', $response, rest_get_server(), $request);

        $this->assertInstanceOf(WP_REST_Response::class, $filtered);
        $this->assertStringContainsString('no-store', $filtered->get_headers()['Cache-Control'] ?? '');
        $this->assertStringContainsString('no-cache', $filtered->get_headers()['Cache-Control'] ?? '');
        $this->assertStringContainsString('private', $filtered->get_headers()['Cache-Control'] ?? '');
        $this->assertSame('no-cache', $filtered->get_headers()['Pragma'] ?? '');
        $this->assertSame('0', $filtered->get_headers()['Expires'] ?? '');
    }

    private function makeSignedRequest(string $slug): WP_REST_Request
    {
        $ts        = (string) time();
        $nonce     = bin2hex(random_bytes(16));
        $canonical = Signer::canonical('POST', '/defyn-connector/v1/plugins/' . $slug . '/update', $ts, $nonce, '');
        $sig       = base64_encode(sodium_crypto_sign_detached($canonical, base64_decode($this->privateKeyBase64)));

        $request = new WP_REST_Request('POST', '/defyn-connector/v1/plugins/' . $slug . '/update');
        $request->set_header('X-Defyn-Timestamp', $ts);
        $request->set_header('X-Defyn-Nonce', $nonce);
        $request->set_header('X-Defyn-Signature', $sig);
        return $request;
    }
}
```

- [ ] **Step 2: Run the test, verify it fails (the route isn't in RestRouter yet)**

```
cd packages/connector-plugin && composer test -- --filter PluginUpdateCacheHeadersTest
```

Expected: FAIL — route not found OR cache headers absent.

- [ ] **Step 3: Modify `RestRouter::register()` to add the new route**

In `packages/connector-plugin/src/Rest/RestRouter.php`, inside `register()`, alongside the existing `/plugins` and `/plugins/refresh` registrations:

```php
register_rest_route(self::NAMESPACE, '/plugins/(?P<slug>[a-z0-9-]{1,80})/update', [
    'methods'             => 'POST',
    'callback'            => [new PluginUpdateController(), 'handle'],
    'permission_callback' => [VerifySignatureMiddleware::class, 'check'],
]);
```

Add the matching `use Defyn\Connector\Rest\PluginUpdateController;` at the top.

- [ ] **Step 4: Run the test, verify it passes**

```
cd packages/connector-plugin && composer test -- --filter PluginUpdateCacheHeadersTest
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/connector-plugin/src/Rest/RestRouter.php \
        packages/connector-plugin/tests/Integration/Rest/PluginUpdateCacheHeadersTest.php
git commit -m "feat(p2-2): register /plugins/{slug}/update in RestRouter + cache-header regression"
```

---

### Task 6: Connector v0.1.4 — version bump + changelog

**Files:**
- Modify: `packages/connector-plugin/defyn-connector.php`
- Modify: `packages/connector-plugin/readme.txt`

- [ ] **Step 1: No test — header-only bump.**

- [ ] **Step 2: Run the full connector suite to confirm baseline green**

```
cd packages/connector-plugin && composer test
```

Expected: ALL PASS.

- [ ] **Step 3: Bump version + add changelog entry**

In `defyn-connector.php`, change the `Version:` header line:

```
 * Version:           0.1.4
```

In `readme.txt`, change the `Stable tag:` line and add a changelog block:

```
Stable tag: 0.1.4
```

```
== Changelog ==

= 0.1.4 =
* Feature: new POST /plugins/{slug}/update signed endpoint runs Plugin_Upgrader for the requested plugin and returns the new version. Per-site transient lock prevents concurrent upgrades on the same install (P2.2).

= 0.1.3 =
* Feature: new `/plugins` (GET) and `/plugins/refresh` (POST) signed endpoints expose the site's plugin inventory + update-available flags. Lays the read foundation for dashboard-driven plugin management (P2.1).
```

Keep the existing 0.1.2 / 0.1.1 / 0.1.0 entries below.

- [ ] **Step 4: Run the full connector suite again to confirm no regression**

```
cd packages/connector-plugin && composer test
```

Expected: ALL PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/connector-plugin/defyn-connector.php packages/connector-plugin/readme.txt
git commit -m "chore(p2-2): connector v0.1.4 — release version bump"
```

---

### Task 7: Schema v3 — three new columns on `wp_defyn_site_plugins`

**Files:**
- Modify: `packages/dashboard-plugin/src/Schema/SitePluginsTable.php`
- Modify: `packages/dashboard-plugin/src/Activation.php`
- Modify: `packages/dashboard-plugin/tests/Integration/AbstractSchemaTestCase.php`
- Test: `packages/dashboard-plugin/tests/Integration/Schema/SchemaV3MigrationTest.php`

Add `update_state` (enum), `last_update_error` (TEXT NULL), `last_update_attempt_at` (DATETIME NULL), plus `KEY update_state`. Bump `SCHEMA_VERSION` to `3`. dbDelta is additive — existing rows get defaults.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Schema;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Schema\SchemaVersion;
use Defyn\Dashboard\Schema\SitePluginsTable;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SchemaV3MigrationTest extends AbstractSchemaTestCase
{
    public function testFreshActivationCreatesNewColumns(): void
    {
        $this->freshlyActivate(['defyn_site_plugins']);

        $columns = $this->describeTable(SitePluginsTable::tableName());

        $this->assertArrayHasKey('update_state', $columns);
        $this->assertSame("enum('idle','queued','updating','failed')", strtolower($columns['update_state']['Type']));
        $this->assertSame('NO', $columns['update_state']['Null']);
        $this->assertSame('idle', $columns['update_state']['Default']);

        $this->assertArrayHasKey('last_update_error', $columns);
        $this->assertSame('text', strtolower($columns['last_update_error']['Type']));
        $this->assertSame('YES', $columns['last_update_error']['Null']);

        $this->assertArrayHasKey('last_update_attempt_at', $columns);
        $this->assertSame('datetime', strtolower($columns['last_update_attempt_at']['Type']));
        $this->assertSame('YES', $columns['last_update_attempt_at']['Null']);
    }

    public function testUpdateStateIndexExists(): void
    {
        $this->freshlyActivate(['defyn_site_plugins']);
        $this->assertHasIndex(SitePluginsTable::tableName(), 'update_state');
    }

    public function testSchemaVersionIsAtLeastThree(): void
    {
        Activation::activate();
        $this->assertGreaterThanOrEqual(3, SchemaVersion::current());
    }

    public function testSchemaVersionConstantIsThree(): void
    {
        $this->assertSame(3, Activation::SCHEMA_VERSION);
    }
}
```

Add `describeTable()` + `assertHasIndex()` to `AbstractSchemaTestCase`:

```php
// In packages/dashboard-plugin/tests/Integration/AbstractSchemaTestCase.php

/** @return array<string, array<string, string|null>> keyed by column name */
protected function describeTable(string $table): array
{
    global $wpdb;
    $rows = $wpdb->get_results("DESCRIBE {$table}", ARRAY_A);
    $out  = [];
    foreach ($rows ?: [] as $row) {
        $out[$row['Field']] = $row;
    }
    return $out;
}

protected function assertHasIndex(string $table, string $indexName): void
{
    global $wpdb;
    $rows = $wpdb->get_results("SHOW INDEX FROM {$table} WHERE Key_name = '{$indexName}'", ARRAY_A);
    $this->assertNotEmpty($rows, "Expected index `{$indexName}` on `{$table}`");
}
```

- [ ] **Step 2: Run the test, verify it fails**

```
cd packages/dashboard-plugin && composer test -- --filter SchemaV3MigrationTest
```

Expected: FAIL — `update_state` column missing OR SCHEMA_VERSION still 2.

- [ ] **Step 3: Add columns to `SitePluginsTable::createSql()`**

Modify `packages/dashboard-plugin/src/Schema/SitePluginsTable.php`. The full new method:

```php
public static function createSql(): string
{
    global $wpdb;
    $table   = self::tableName();
    $charset = $wpdb->get_charset_collate();

    return "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        site_id BIGINT UNSIGNED NOT NULL,
        slug VARCHAR(191) NOT NULL,
        name VARCHAR(191) NOT NULL,
        version VARCHAR(40) NULL,
        update_available TINYINT(1) NOT NULL DEFAULT 0,
        update_version VARCHAR(40) NULL,
        update_state ENUM('idle','queued','updating','failed') NOT NULL DEFAULT 'idle',
        last_update_error TEXT NULL,
        last_update_attempt_at DATETIME NULL,
        last_seen_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY site_slug (site_id, slug),
        KEY update_available (update_available),
        KEY update_state (update_state),
        KEY site_id (site_id)
    ) {$charset};";
}
```

Bump the constant in `packages/dashboard-plugin/src/Activation.php`:

```php
public const SCHEMA_VERSION = 3;
```

- [ ] **Step 4: Run the test, verify it passes**

```
cd packages/dashboard-plugin && composer test -- --filter SchemaV3MigrationTest
```

Expected: PASS (4/4).

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Schema/SitePluginsTable.php \
        packages/dashboard-plugin/src/Activation.php \
        packages/dashboard-plugin/tests/Integration/Schema/SchemaV3MigrationTest.php \
        packages/dashboard-plugin/tests/Integration/AbstractSchemaTestCase.php
git commit -m "feat(p2-2): schema v3 — update_state + last_update_error columns"
```

---

### Task 8: `SitePluginsRepository` — update-state methods

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/SitePluginsRepository.php`
- Test: `packages/dashboard-plugin/tests/Unit/Services/SitePluginsRepositoryUpdateStateTest.php`

Add five new methods: one read (`findRowForSiteAndSlug`) used by the controller's guard, four writes for the AS job + controller. All idempotent (UPDATEs against a UNIQUE (site_id, slug)).

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Unit\Services;

use Defyn\Dashboard\Schema\SitePluginsTable;
use Defyn\Dashboard\Services\SitePluginsRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SitePluginsRepositoryUpdateStateTest extends AbstractSchemaTestCase
{
    private SitePluginsRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate(['defyn_site_plugins']);
        $this->repo = new SitePluginsRepository();

        global $wpdb;
        $wpdb->insert(SitePluginsTable::tableName(), [
            'site_id'          => 7,
            'slug'             => 'akismet',
            'name'             => 'Akismet',
            'version'          => '5.7',
            'update_available' => 1,
            'update_version'   => '5.8',
            'update_state'     => 'idle',
            'last_seen_at'     => '2026-06-06 00:00:00',
            'created_at'       => '2026-06-06 00:00:00',
            'updated_at'       => '2026-06-06 00:00:00',
        ]);
    }

    public function testFindRowForSiteAndSlugReturnsRow(): void
    {
        $row = $this->repo->findRowForSiteAndSlug(7, 'akismet');
        $this->assertNotNull($row);
        $this->assertSame('akismet', $row['slug']);
        $this->assertSame('5.7', $row['version']);
        $this->assertSame('1', $row['update_available']);
    }

    public function testFindRowForSiteAndSlugReturnsNullWhenMissing(): void
    {
        $this->assertNull($this->repo->findRowForSiteAndSlug(7, 'not-there'));
        $this->assertNull($this->repo->findRowForSiteAndSlug(99, 'akismet'));
    }

    public function testMarkUpdateRequestedSetsQueuedAndClearsError(): void
    {
        global $wpdb;
        $wpdb->update(SitePluginsTable::tableName(),
            ['update_state' => 'failed', 'last_update_error' => 'old error'],
            ['site_id' => 7, 'slug' => 'akismet']);

        $this->repo->markUpdateRequested(7, 'akismet', '2026-06-06 09:00:00');
        $row = $this->repo->findRowForSiteAndSlug(7, 'akismet');

        $this->assertSame('queued', $row['update_state']);
        $this->assertNull($row['last_update_error']);
        $this->assertSame('2026-06-06 09:00:00', $row['last_update_attempt_at']);
    }

    public function testMarkUpdatingFlipsState(): void
    {
        $this->repo->markUpdating(7, 'akismet', '2026-06-06 09:00:30');
        $row = $this->repo->findRowForSiteAndSlug(7, 'akismet');
        $this->assertSame('updating', $row['update_state']);
    }

    public function testMarkUpdateSucceededClearsBadgeAndBumpsVersion(): void
    {
        $this->repo->markUpdateSucceeded(7, 'akismet', '5.8', '2026-06-06 09:01:00');
        $row = $this->repo->findRowForSiteAndSlug(7, 'akismet');

        $this->assertSame('idle', $row['update_state']);
        $this->assertSame('5.8', $row['version']);
        $this->assertSame('0', $row['update_available']);
        $this->assertNull($row['update_version']);
        $this->assertNull($row['last_update_error']);
    }

    public function testMarkUpdateFailedTruncatesLongError(): void
    {
        $long = str_repeat('A', 1200);
        $this->repo->markUpdateFailed(7, 'akismet', $long, '2026-06-06 09:01:00');
        $row = $this->repo->findRowForSiteAndSlug(7, 'akismet');

        $this->assertSame('failed', $row['update_state']);
        $this->assertSame(1000, strlen($row['last_update_error']));
        $this->assertSame('2026-06-06 09:01:00', $row['last_update_attempt_at']);
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

```
cd packages/dashboard-plugin && composer test -- --filter SitePluginsRepositoryUpdateStateTest
```

Expected: FAIL — methods don't exist.

- [ ] **Step 3: Extend `SitePluginsRepository`**

Add to `packages/dashboard-plugin/src/Services/SitePluginsRepository.php`:

```php
/** @return array<string, string|null>|null */
public function findRowForSiteAndSlug(int $siteId, string $slug): ?array
{
    global $wpdb;
    $table = SitePluginsTable::tableName();
    $row = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM {$table} WHERE site_id = %d AND slug = %s", $siteId, $slug),
        ARRAY_A,
    );
    return $row ?: null;
}

public function markUpdateRequested(int $siteId, string $slug, string $now): void
{
    global $wpdb;
    $wpdb->update(
        SitePluginsTable::tableName(),
        [
            'update_state'           => 'queued',
            'last_update_error'      => null,
            'last_update_attempt_at' => $now,
            'updated_at'             => $now,
        ],
        ['site_id' => $siteId, 'slug' => $slug],
        ['%s', '%s', '%s', '%s'],
        ['%d', '%s'],
    );
}

public function markUpdating(int $siteId, string $slug, string $now): void
{
    global $wpdb;
    $wpdb->update(
        SitePluginsTable::tableName(),
        ['update_state' => 'updating', 'updated_at' => $now],
        ['site_id' => $siteId, 'slug' => $slug],
        ['%s', '%s'],
        ['%d', '%s'],
    );
}

public function markUpdateSucceeded(int $siteId, string $slug, string $newVersion, string $now): void
{
    global $wpdb;
    $wpdb->update(
        SitePluginsTable::tableName(),
        [
            'update_state'      => 'idle',
            'version'           => $newVersion,
            'update_available'  => 0,
            'update_version'    => null,
            'last_update_error' => null,
            'updated_at'        => $now,
        ],
        ['site_id' => $siteId, 'slug' => $slug],
        ['%s', '%s', '%d', '%s', '%s', '%s'],
        ['%d', '%s'],
    );
}

public function markUpdateFailed(int $siteId, string $slug, string $errorMessage, string $now): void
{
    global $wpdb;
    $wpdb->update(
        SitePluginsTable::tableName(),
        [
            'update_state'           => 'failed',
            'last_update_error'      => substr($errorMessage, 0, 1000),
            'last_update_attempt_at' => $now,
            'updated_at'             => $now,
        ],
        ['site_id' => $siteId, 'slug' => $slug],
        ['%s', '%s', '%s', '%s'],
        ['%d', '%s'],
    );
}
```

- [ ] **Step 4: Run the test, verify it passes**

```
cd packages/dashboard-plugin && composer test -- --filter SitePluginsRepositoryUpdateStateTest
```

Expected: PASS (6/6).

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Services/SitePluginsRepository.php \
        packages/dashboard-plugin/tests/Unit/Services/SitePluginsRepositoryUpdateStateTest.php
git commit -m "feat(p2-2): SitePluginsRepository update-state read + write methods"
```

---

### Task 9: `SignedHttpClient` — optional timeout parameter

**Files:**
- Modify: `packages/dashboard-plugin/src/Http/SignedHttpClient.php`
- Test: `packages/dashboard-plugin/tests/Unit/Http/SignedHttpClientTimeoutTest.php`

Add `int $timeoutSeconds = 30` as the last parameter on `signedPostJson` and `signedGet`. Default keeps every existing callsite (F5, F6, P2.1) at 30 s. Symfony HttpClient accepts `'timeout'` in the options array.

- [ ] **Step 1: Write the failing test**

```php
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
        $this->assertSame(120, $observed['timeout']);
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

        $this->assertSame(30, $observed['timeout']);
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

        $this->assertSame(60, $observed['timeout']);
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

```
cd packages/dashboard-plugin && composer test -- --filter SignedHttpClientTimeoutTest
```

Expected: FAIL — `timeoutSeconds` named arg not recognised OR `timeout` not in options.

- [ ] **Step 3: Modify `SignedHttpClient`**

In `packages/dashboard-plugin/src/Http/SignedHttpClient.php`, change the signatures and pass `timeout` in the options array. Preserve the existing canonical-string + signature logic — the smallest possible edit is to add the parameter and add `'timeout' => $timeoutSeconds` to the options array passed to `$this->httpClient->request(...)`.

Conceptual diff:

```php
public function signedPostJson(
    string $url,
    array $body,
    string $privateKeyBase64,
    string $canonicalPath,
    int $timeoutSeconds = 30,  // <-- NEW
): array {
    // …existing canonical-string + signature setup unchanged…

    try {
        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                'Content-Type'      => 'application/json',
                'X-Defyn-Timestamp' => $timestamp,
                'X-Defyn-Nonce'     => $nonce,
                'X-Defyn-Signature' => $signature,
            ],
            'body'    => $jsonBody,
            'timeout' => $timeoutSeconds,  // <-- NEW
        ]);
        // …rest unchanged…
    } catch (\Throwable $e) {
        return ['status' => 0, 'body' => [], 'error' => $e->getMessage()];
    }
}

public function signedGet(
    string $url,
    string $privateKeyBase64,
    string $canonicalPath,
    int $timeoutSeconds = 30,  // <-- NEW
): array {
    // …existing canonical-string + signature setup unchanged…

    try {
        $response = $this->httpClient->request('GET', $url, [
            'headers' => [
                'X-Defyn-Timestamp' => $timestamp,
                'X-Defyn-Nonce'     => $nonce,
                'X-Defyn-Signature' => $signature,
            ],
            'timeout' => $timeoutSeconds,  // <-- NEW
        ]);
        // …rest unchanged…
    } catch (\Throwable $e) {
        return ['status' => 0, 'body' => [], 'error' => $e->getMessage()];
    }
}
```

- [ ] **Step 4: Run the test, verify it passes; also run the full dashboard suite to confirm no regression**

```
cd packages/dashboard-plugin && composer test -- --filter SignedHttpClientTimeoutTest
cd packages/dashboard-plugin && composer test
```

Expected: PASS on both. All existing F5/F6/P2.1 callers continue to work with the default 30 s.

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Http/SignedHttpClient.php \
        packages/dashboard-plugin/tests/Unit/Http/SignedHttpClientTimeoutTest.php
git commit -m "feat(p2-2): SignedHttpClient accepts optional timeoutSeconds (default 30)"
```

---

### Task 10: `UpdateSitePlugin` AS job — success path

**Files:**
- Create: `packages/dashboard-plugin/src/Jobs/UpdateSitePlugin.php`
- Test: `packages/dashboard-plugin/tests/Integration/Jobs/UpdateSitePluginTest.php`

The AS job handler: decrypt the per-site key, call connector with 120 s timeout, branch on response. This task covers the happy path. Tasks 11 and 12 add the retry + failure branches.

The job's constructor takes injectable dependencies so the test can supply a `MockHttpClient`. Pattern matches `RefreshSitePlugins` (P2.1 Task 8).

- [ ] **Step 1: Write the failing test (success branch only)**

```php
<?php
declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Jobs;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Jobs\UpdateSitePlugin;
use Defyn\Dashboard\Schema\SitePluginsTable;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\SitePluginsRepository;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class UpdateSitePluginTest extends AbstractSchemaTestCase
{
    private string $privateKeyBase64;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate(['defyn_sites', 'defyn_site_plugins', 'defyn_activity_log']);

        // Seed a site
        $keypair = sodium_crypto_sign_keypair();
        $this->privateKeyBase64 = base64_encode(sodium_crypto_sign_secretkey($keypair));
        $vault = new Vault();
        $encryptedKey = $vault->encrypt($this->privateKeyBase64);

        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'           => 1,
            'url'               => 'https://smartcoding.test',
            'label'             => 'Smart',
            'status'            => 'active',
            'site_private_key'  => $encryptedKey,
            'dashboard_pub'     => base64_encode(sodium_crypto_sign_publickey($keypair)),
            'created_at'        => '2026-06-06 00:00:00',
        ]);

        // Seed a plugin row
        $wpdb->insert(SitePluginsTable::tableName(), [
            'site_id'          => 1,
            'slug'             => 'akismet',
            'name'             => 'Akismet',
            'version'          => '5.7',
            'update_available' => 1,
            'update_version'   => '5.8',
            'update_state'     => 'queued',
            'last_seen_at'     => '2026-06-06 00:00:00',
            'created_at'       => '2026-06-06 00:00:00',
            'updated_at'       => '2026-06-06 00:00:00',
        ]);
    }

    public function testSuccessPathMarksIdleAndBumpsVersion(): void
    {
        $successBody = json_encode([
            'success'          => true,
            'slug'             => 'akismet',
            'previous_version' => '5.7',
            'new_version'      => '5.8',
            'server_time'      => time(),
        ]);

        $captured = null;
        $factory = function (string $method, string $url, array $options) use (&$captured, $successBody) {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];
            return new MockResponse($successBody, ['http_code' => 200]);
        };
        $httpClient = new SignedHttpClient(new MockHttpClient($factory));

        $job = new UpdateSitePlugin(
            new SitesRepository(),
            new SitePluginsRepository(),
            $httpClient,
            new ActivityLogger(),
            new Vault(),
        );

        $job->handle(1, 'akismet', 0);

        // Connector was called with 120s timeout
        $this->assertSame('POST', $captured['method']);
        $this->assertSame(120, $captured['options']['timeout']);
        $this->assertStringEndsWith('/wp-json/defyn-connector/v1/plugins/akismet/update', $captured['url']);

        // Row was marked idle + bumped to 5.8
        $repo = new SitePluginsRepository();
        $row = $repo->findRowForSiteAndSlug(1, 'akismet');
        $this->assertSame('idle', $row['update_state']);
        $this->assertSame('5.8', $row['version']);
        $this->assertSame('0', $row['update_available']);
        $this->assertNull($row['update_version']);

        // plugin_update.started + plugin_update.succeeded in activity log
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT event_type, JSON_EXTRACT(details, '$.slug') AS slug FROM {$wpdb->prefix}defyn_activity_log
             WHERE site_id = 1 ORDER BY id ASC",
            ARRAY_A,
        );
        $types = array_column($rows, 'event_type');
        $this->assertSame(['plugin_update.started', 'plugin_update.succeeded'], $types);
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

```
cd packages/dashboard-plugin && composer test -- --filter UpdateSitePluginTest
```

Expected: FAIL — `UpdateSitePlugin` class not found.

- [ ] **Step 3: Implement the job (success branch only — retry + failure in Tasks 11 + 12)**

```php
<?php
declare(strict_types=1);

namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\SitePluginsRepository;
use Defyn\Dashboard\Services\SitesRepository;

/**
 * Action Scheduler handler for `defyn_update_site_plugin($siteId, $slug, $attempt)`.
 *
 * Decrypts the per-site Ed25519 key, calls the connector's signed
 * /plugins/{slug}/update endpoint with a 120 s timeout, branches on the
 * response.
 *
 * Spec: docs/superpowers/specs/2026-06-06-p2-2-plugin-updates-design.md §6.3
 */
final class UpdateSitePlugin
{
    public const HOOK = 'defyn_update_site_plugin';
    public const TIMEOUT_SECONDS = 120;

    public function __construct(
        private readonly SitesRepository $sites = new SitesRepository(),
        private readonly SitePluginsRepository $repo = new SitePluginsRepository(),
        private readonly SignedHttpClient $http = new SignedHttpClient(),
        private readonly ActivityLogger $log = new ActivityLogger(),
        private readonly Vault $vault = new Vault(),
    ) {
    }

    public function handle(int $siteId, string $slug, int $attempt = 0): void
    {
        $site = $this->sites->findById($siteId);
        if ($site === null) {
            return;
        }

        $now = gmdate('Y-m-d H:i:s');
        $row = $this->repo->findRowForSiteAndSlug($siteId, $slug);
        if ($row === null) {
            return;
        }

        $this->repo->markUpdating($siteId, $slug, $now);
        $this->log->log(null, $siteId, 'plugin_update.started', [
            'slug'           => $slug,
            'current_version'=> $row['version'] ?? null,
            'target_version' => $row['update_version'] ?? null,
        ]);

        $privateKey = $this->vault->decrypt((string) $site['site_private_key']);
        $url = rtrim((string) $site['url'], '/') . '/wp-json/defyn-connector/v1/plugins/' . $slug . '/update';
        $canonicalPath = '/defyn-connector/v1/plugins/' . $slug . '/update';

        $response = $this->http->signedPostJson(
            $url,
            [],
            $privateKey,
            $canonicalPath,
            timeoutSeconds: self::TIMEOUT_SECONDS,
        );

        if ($response['status'] === 200 && !empty($response['body']['success'])) {
            $this->repo->markUpdateSucceeded(
                $siteId,
                $slug,
                (string) ($response['body']['new_version'] ?? $row['version']),
                $now,
            );
            $this->log->log(null, $siteId, 'plugin_update.succeeded', [
                'slug'             => $slug,
                'previous_version' => (string) ($response['body']['previous_version'] ?? $row['version']),
                'new_version'      => (string) ($response['body']['new_version'] ?? ''),
            ]);
            return;
        }

        // Tasks 11 + 12 add retry + failure branches here.
        $this->repo->markUpdateFailed(
            $siteId,
            $slug,
            sprintf('Connector returned HTTP %d.', $response['status']),
            $now,
        );
        $this->log->log(null, $siteId, 'plugin_update.failed', [
            'slug'              => $slug,
            'error_message'     => sprintf('Connector returned HTTP %d.', $response['status']),
            'attempted_version' => $row['update_version'] ?? null,
        ]);
    }
}
```

- [ ] **Step 4: Run the test, verify it passes**

```
cd packages/dashboard-plugin && composer test -- --filter UpdateSitePluginTest
```

Expected: PASS (1/1).

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Jobs/UpdateSitePlugin.php \
        packages/dashboard-plugin/tests/Integration/Jobs/UpdateSitePluginTest.php
git commit -m "feat(p2-2): UpdateSitePlugin AS job — success path"
```

---

### Task 11: `UpdateSitePlugin` — 409 retry with exponential backoff

**Files:**
- Modify: `packages/dashboard-plugin/src/Jobs/UpdateSitePlugin.php`
- Test: same file as Task 10 — append two new test methods

When the connector returns 409 `plugins.update_in_progress`, schedule self at `now + 60 * 2^attempt` seconds, log `plugin_update.retry`. After 5 retries (attempt 5 inclusive), mark failed.

- [ ] **Step 1: Append failing tests to `UpdateSitePluginTest`**

```php
public function testInProgress409ReschedulesWithExponentialBackoff(): void
{
    $body = json_encode(['error' => ['code' => 'plugins.update_in_progress', 'message' => 'busy']]);
    $factory = fn () => new MockResponse($body, ['http_code' => 409]);
    $job = new UpdateSitePlugin(
        new SitesRepository(),
        new SitePluginsRepository(),
        new SignedHttpClient(new MockHttpClient($factory)),
        new ActivityLogger(),
        new Vault(),
    );

    $scheduled = [];
    \add_filter('pre_as_schedule_single_action', function ($pre, $when, $hook, $args) use (&$scheduled) {
        $scheduled[] = ['when' => $when, 'hook' => $hook, 'args' => $args];
        return 999; // pretend AS returned an action ID
    }, 10, 4);

    $job->handle(1, 'akismet', 0);
    $job->handle(1, 'akismet', 1);
    $job->handle(1, 'akismet', 2);

    $this->assertCount(3, $scheduled);

    // Backoff: 60, 120, 240 seconds from now
    $now = time();
    $this->assertEqualsWithDelta($now + 60, $scheduled[0]['when'], 5);
    $this->assertEqualsWithDelta($now + 120, $scheduled[1]['when'], 5);
    $this->assertEqualsWithDelta($now + 240, $scheduled[2]['when'], 5);

    // Each schedule increments the attempt arg
    $this->assertSame([1, 'akismet', 1], $scheduled[0]['args']);
    $this->assertSame([1, 'akismet', 2], $scheduled[1]['args']);
    $this->assertSame([1, 'akismet', 3], $scheduled[2]['args']);

    // Row stays in 'updating' across retries (don't flip to failed yet)
    $row = (new SitePluginsRepository())->findRowForSiteAndSlug(1, 'akismet');
    $this->assertSame('updating', $row['update_state']);

    // plugin_update.retry events logged
    global $wpdb;
    $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}defyn_activity_log WHERE event_type = 'plugin_update.retry'");
    $this->assertSame(3, $count);
}

public function testFifthRetryMarksFailed(): void
{
    $body = json_encode(['error' => ['code' => 'plugins.update_in_progress', 'message' => 'busy']]);
    $factory = fn () => new MockResponse($body, ['http_code' => 409]);
    $job = new UpdateSitePlugin(
        new SitesRepository(),
        new SitePluginsRepository(),
        new SignedHttpClient(new MockHttpClient($factory)),
        new ActivityLogger(),
        new Vault(),
    );

    // attempt = 5 means we've exhausted retries
    $job->handle(1, 'akismet', 5);

    $row = (new SitePluginsRepository())->findRowForSiteAndSlug(1, 'akismet');
    $this->assertSame('failed', $row['update_state']);
    $this->assertStringContainsString('busy after 5 retries', $row['last_update_error']);
}
```

- [ ] **Step 2: Run the tests, verify they fail (retry branch missing)**

```
cd packages/dashboard-plugin && composer test -- --filter UpdateSitePluginTest
```

Expected: FAIL — `pre_as_schedule_single_action` not invoked OR row state wrong.

- [ ] **Step 3: Implement the 409 branch in `UpdateSitePlugin::handle()`**

Add this branch BEFORE the final `markUpdateFailed` block:

```php
if (
    $response['status'] === 409
    && ($response['body']['error']['code'] ?? '') === 'plugins.update_in_progress'
) {
    if ($attempt >= 5) {
        $this->repo->markUpdateFailed(
            $siteId,
            $slug,
            'Site is busy after 5 retries.',
            $now,
        );
        $this->log->log(null, $siteId, 'plugin_update.failed', [
            'slug'              => $slug,
            'error_message'     => 'Site is busy after 5 retries.',
            'attempted_version' => $row['update_version'] ?? null,
        ]);
        return;
    }

    $delay   = 60 * (2 ** $attempt); // 60, 120, 240, 480, 960
    $nextRun = time() + $delay;
    \as_schedule_single_action($nextRun, self::HOOK, [$siteId, $slug, $attempt + 1]);
    $this->log->log(null, $siteId, 'plugin_update.retry', [
        'slug'         => $slug,
        'attempt'      => $attempt,
        'next_run_at'  => gmdate('Y-m-d H:i:s', $nextRun),
    ]);
    return;
}
```

- [ ] **Step 4: Run the tests, verify they pass**

```
cd packages/dashboard-plugin && composer test -- --filter UpdateSitePluginTest
```

Expected: PASS (3/3).

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Jobs/UpdateSitePlugin.php \
        packages/dashboard-plugin/tests/Integration/Jobs/UpdateSitePluginTest.php
git commit -m "feat(p2-2): UpdateSitePlugin — 409 retry with exponential backoff (max 5)"
```

---

### Task 12: `UpdateSitePlugin` — non-409 failure paths

**Files:**
- Modify: `packages/dashboard-plugin/src/Jobs/UpdateSitePlugin.php`
- Test: same file — append two more methods

Two final branches: (a) transport error (`status === 0`, `error` populated) — use `$response['error']`; (b) 4xx/5xx with an error envelope from the connector — use `$response['body']['error']['message']`.

- [ ] **Step 1: Append failing tests**

```php
public function testTransportErrorMarksFailed(): void
{
    // Symfony MockHttpClient with a factory closure that throws
    $factory = fn () => throw new \Symfony\Component\HttpClient\Exception\TransportException('Connection refused');
    $job = new UpdateSitePlugin(
        new SitesRepository(),
        new SitePluginsRepository(),
        new SignedHttpClient(new MockHttpClient($factory)),
        new ActivityLogger(),
        new Vault(),
    );

    $job->handle(1, 'akismet', 0);

    $row = (new SitePluginsRepository())->findRowForSiteAndSlug(1, 'akismet');
    $this->assertSame('failed', $row['update_state']);
    $this->assertStringContainsString('Connection refused', $row['last_update_error']);
}

public function testUpgradeFailedFromConnectorMarksFailed(): void
{
    $body = json_encode(['error' => ['code' => 'plugins.update_failed', 'message' => 'Could not copy file. /wp-content/upgrade/akismet/akismet.php']]);
    $factory = fn () => new MockResponse($body, ['http_code' => 502]);
    $job = new UpdateSitePlugin(
        new SitesRepository(),
        new SitePluginsRepository(),
        new SignedHttpClient(new MockHttpClient($factory)),
        new ActivityLogger(),
        new Vault(),
    );

    $job->handle(1, 'akismet', 0);

    $row = (new SitePluginsRepository())->findRowForSiteAndSlug(1, 'akismet');
    $this->assertSame('failed', $row['update_state']);
    $this->assertStringContainsString('Could not copy file', $row['last_update_error']);

    global $wpdb;
    $msg = $wpdb->get_var("SELECT JSON_EXTRACT(details, '$.error_message') FROM {$wpdb->prefix}defyn_activity_log WHERE event_type = 'plugin_update.failed' ORDER BY id DESC LIMIT 1");
    $this->assertStringContainsString('Could not copy file', $msg);
}
```

- [ ] **Step 2: Run the tests, verify they fail**

```
cd packages/dashboard-plugin && composer test -- --filter UpdateSitePluginTest
```

Expected: FAIL — current failure branch uses generic "HTTP %d" message instead of the connector's error message.

- [ ] **Step 3: Replace the final `markUpdateFailed` block in `handle()`**

```php
// Replace the existing generic "Connector returned HTTP %d" block with:
$errorMessage = $response['body']['error']['message']
    ?? ($response['error'] !== '' ? $response['error'] : sprintf('Connector returned HTTP %d.', $response['status']));

$this->repo->markUpdateFailed($siteId, $slug, $errorMessage, $now);
$this->log->log(null, $siteId, 'plugin_update.failed', [
    'slug'              => $slug,
    'error_message'     => $errorMessage,
    'attempted_version' => $row['update_version'] ?? null,
]);
```

- [ ] **Step 4: Run the tests, verify all pass**

```
cd packages/dashboard-plugin && composer test -- --filter UpdateSitePluginTest
```

Expected: PASS (5/5).

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Jobs/UpdateSitePlugin.php \
        packages/dashboard-plugin/tests/Integration/Jobs/UpdateSitePluginTest.php
git commit -m "feat(p2-2): UpdateSitePlugin — surface connector error messages on failure"
```

---

### Task 13: Wire `defyn_update_site_plugin` AS hook in `Plugin::boot()`

**Files:**
- Modify: `packages/dashboard-plugin/src/Plugin.php`
- Test: `packages/dashboard-plugin/tests/Integration/PluginBootASHookTest.php`

Confirm the AS hook is registered so the AS runtime can dispatch the handler.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration;

use Defyn\Dashboard\Jobs\UpdateSitePlugin;
use Defyn\Dashboard\Plugin;
use WP_UnitTestCase;

final class PluginBootASHookTest extends WP_UnitTestCase
{
    public function testUpdateSitePluginHookRegistered(): void
    {
        Plugin::instance()->boot();
        $this->assertNotFalse(has_action(UpdateSitePlugin::HOOK));
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

```
cd packages/dashboard-plugin && composer test -- --filter PluginBootASHookTest
```

Expected: FAIL — hook not registered.

- [ ] **Step 3: Add the hook registration in `Plugin::boot()`**

In `packages/dashboard-plugin/src/Plugin.php`, alongside the existing `add_action(SyncSite::HOOK, ...)` block:

```php
add_action(UpdateSitePlugin::HOOK, static function (int $siteId, string $slug, int $attempt = 0): void {
    (new UpdateSitePlugin())->handle($siteId, $slug, $attempt);
}, 10, 3);
```

Add the matching `use Defyn\Dashboard\Jobs\UpdateSitePlugin;` at the top.

- [ ] **Step 4: Run the test, verify it passes**

```
cd packages/dashboard-plugin && composer test -- --filter PluginBootASHookTest
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Plugin.php \
        packages/dashboard-plugin/tests/Integration/PluginBootASHookTest.php
git commit -m "feat(p2-2): register defyn_update_site_plugin AS hook"
```

---

### Task 14: `RateLimit::pluginsUpdate`

**Files:**
- Modify: `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php`
- Test: `packages/dashboard-plugin/tests/Integration/Rest/RateLimitPluginsUpdateTest.php`

Mirror `RateLimit::pluginsRefresh` (P2.1 Task 11). 6/hour per (user, site, slug). Chains `RequireAuth::check` internally.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Rest\Middleware\RateLimit;
use WP_REST_Request;
use WP_UnitTestCase;

final class RateLimitPluginsUpdateTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Reset rate-limit transients
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_defyn_rl_%'");
    }

    public function testSeventhRequestInOneHourReturns429(): void
    {
        $userId = 42;
        $siteId = 7;
        $slug = 'akismet';

        $request = new WP_REST_Request('POST', '/defyn/v1/sites/' . $siteId . '/plugins/' . $slug . '/update');
        $request->set_param('id', $siteId);
        $request->set_param('slug', $slug);
        $request->set_param('_authenticated_user_id', $userId);

        // Simulate 6 successful permission checks (filling the bucket)
        for ($i = 1; $i <= 6; $i++) {
            $result = RateLimit::pluginsUpdate($request);
            $this->assertTrue($result, "Call {$i} should pass; got " . var_export($result, true));
        }

        // 7th should fail with WP_Error mapping to 429
        $result = RateLimit::pluginsUpdate($request);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('plugins.rate_limited', $result->get_error_code());
        $data = $result->get_error_data();
        $this->assertSame(429, $data['status']);
    }

    public function testDifferentSlugsHaveSeparateBuckets(): void
    {
        $userId = 42;
        $siteId = 7;

        for ($i = 1; $i <= 6; $i++) {
            $r = new WP_REST_Request('POST', '/defyn/v1/sites/7/plugins/akismet/update');
            $r->set_param('id', $siteId);
            $r->set_param('slug', 'akismet');
            $r->set_param('_authenticated_user_id', $userId);
            RateLimit::pluginsUpdate($r);
        }

        // 7th akismet call → 429
        $r = new WP_REST_Request('POST', '/defyn/v1/sites/7/plugins/akismet/update');
        $r->set_param('id', $siteId);
        $r->set_param('slug', 'akismet');
        $r->set_param('_authenticated_user_id', $userId);
        $this->assertInstanceOf(\WP_Error::class, RateLimit::pluginsUpdate($r));

        // First jetpack call → still passes
        $r = new WP_REST_Request('POST', '/defyn/v1/sites/7/plugins/jetpack/update');
        $r->set_param('id', $siteId);
        $r->set_param('slug', 'jetpack');
        $r->set_param('_authenticated_user_id', $userId);
        $this->assertTrue(RateLimit::pluginsUpdate($r));
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

```
cd packages/dashboard-plugin && composer test -- --filter RateLimitPluginsUpdateTest
```

Expected: FAIL — `RateLimit::pluginsUpdate()` doesn't exist.

- [ ] **Step 3: Add the static method to `RateLimit`**

Look at the existing `pluginsRefresh` method for the canonical pattern. Add this beside it in `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php`:

```php
public static function pluginsUpdate(WP_REST_Request $request)
{
    $auth = RequireAuth::check($request);
    if (is_wp_error($auth)) {
        return $auth;
    }

    $userId = (int) $request->get_param('_authenticated_user_id');
    $siteId = (int) $request->get_param('id');
    $slug   = (string) $request->get_param('slug');

    $key = sprintf('defyn_rl_pluginsUpdate_%d_%d_%s', $userId, $siteId, md5($slug));
    $count = (int) get_transient($key);

    if ($count >= 6) {
        return new \WP_Error(
            'plugins.rate_limited',
            'Too many update requests for this plugin. Try again in an hour.',
            ['status' => 429],
        );
    }

    set_transient($key, $count + 1, HOUR_IN_SECONDS);
    return true;
}
```

- [ ] **Step 4: Run the tests, verify they pass**

```
cd packages/dashboard-plugin && composer test -- --filter RateLimitPluginsUpdateTest
```

Expected: PASS (2/2).

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php \
        packages/dashboard-plugin/tests/Integration/Rest/RateLimitPluginsUpdateTest.php
git commit -m "feat(p2-2): RateLimit::pluginsUpdate — 6/hour per user+site+slug"
```

---

### Task 15: `SitesPluginsUpdateController` + REST route

**Files:**
- Create: `packages/dashboard-plugin/src/Rest/SitesPluginsUpdateController.php`
- Modify: `packages/dashboard-plugin/src/Rest/RestRouter.php`
- Test: `packages/dashboard-plugin/tests/Integration/Rest/SitesPluginsUpdateTest.php`

The dashboard REST endpoint. Guards: owner check, plugin-in-inventory check, `update_available=1`, not already in flight. Optimistic write to `update_state='queued'`, log `plugin_update.requested`, schedule AS job, return 202.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Schema\SitePluginsTable;
use Defyn\Dashboard\Services\SitePluginsRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

final class SitesPluginsUpdateTest extends AbstractSchemaTestCase
{
    private int $userId;
    private string $token;
    private int $siteId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate(['defyn_sites', 'defyn_site_plugins', 'defyn_activity_log']);

        (new \Defyn\Dashboard\Rest\RestRouter())->register();

        $this->userId = self::factory()->user->create();
        $this->token = \Defyn\Dashboard\Auth\JwtIssuer::issue($this->userId);

        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'          => $this->userId,
            'url'              => 'https://smartcoding.test',
            'label'            => 'Smart',
            'status'           => 'active',
            'site_private_key' => '',
            'dashboard_pub'    => '',
            'created_at'       => '2026-06-06 00:00:00',
        ]);
        $this->siteId = (int) $wpdb->insert_id;

        $wpdb->insert(SitePluginsTable::tableName(), [
            'site_id'          => $this->siteId,
            'slug'             => 'akismet',
            'name'             => 'Akismet',
            'version'          => '5.7',
            'update_available' => 1,
            'update_version'   => '5.8',
            'update_state'     => 'idle',
            'last_seen_at'     => '2026-06-06 00:00:00',
            'created_at'       => '2026-06-06 00:00:00',
            'updated_at'       => '2026-06-06 00:00:00',
        ]);
    }

    public function testSuccessReturns202AndSchedulesJob(): void
    {
        $scheduled = [];
        \add_filter('pre_as_schedule_single_action', function ($pre, $when, $hook, $args) use (&$scheduled) {
            $scheduled[] = ['hook' => $hook, 'args' => $args];
            return 999;
        }, 10, 4);

        $response = rest_do_request($this->signed('POST', "/defyn/v1/sites/{$this->siteId}/plugins/akismet/update"));

        $this->assertSame(202, $response->get_status());
        $body = $response->get_data();
        $this->assertTrue($body['scheduled']);
        $this->assertSame($this->siteId, $body['site_id']);
        $this->assertSame('akismet', $body['slug']);

        // Optimistic write happened
        $row = (new SitePluginsRepository())->findRowForSiteAndSlug($this->siteId, 'akismet');
        $this->assertSame('queued', $row['update_state']);

        // AS scheduled
        $this->assertSame('defyn_update_site_plugin', $scheduled[0]['hook']);
        $this->assertSame([$this->siteId, 'akismet', 0], $scheduled[0]['args']);

        // Activity log written with user_id
        global $wpdb;
        $count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}defyn_activity_log
             WHERE event_type = 'plugin_update.requested' AND site_id = {$this->siteId}"
        );
        $this->assertSame(1, $count);
    }

    public function testSiteNotOwnedReturns404(): void
    {
        $response = rest_do_request($this->signed('POST', "/defyn/v1/sites/99999/plugins/akismet/update"));
        $this->assertSame(404, $response->get_status());
        $this->assertSame('sites.not_found', $response->get_data()['error']['code']);
    }

    public function testPluginNotInInventoryReturns404(): void
    {
        $response = rest_do_request($this->signed('POST', "/defyn/v1/sites/{$this->siteId}/plugins/not-installed/update"));
        $this->assertSame(404, $response->get_status());
        $this->assertSame('plugins.not_found_in_inventory', $response->get_data()['error']['code']);
    }

    public function testNoUpdateAvailableReturns409(): void
    {
        global $wpdb;
        $wpdb->update(SitePluginsTable::tableName(),
            ['update_available' => 0, 'update_version' => null],
            ['site_id' => $this->siteId, 'slug' => 'akismet']);

        $response = rest_do_request($this->signed('POST', "/defyn/v1/sites/{$this->siteId}/plugins/akismet/update"));
        $this->assertSame(409, $response->get_status());
        $this->assertSame('plugins.no_update_available', $response->get_data()['error']['code']);
    }

    public function testAlreadyInProgressReturns409(): void
    {
        global $wpdb;
        $wpdb->update(SitePluginsTable::tableName(),
            ['update_state' => 'queued'],
            ['site_id' => $this->siteId, 'slug' => 'akismet']);

        $response = rest_do_request($this->signed('POST', "/defyn/v1/sites/{$this->siteId}/plugins/akismet/update"));
        $this->assertSame(409, $response->get_status());
        $this->assertSame('plugins.update_already_in_progress', $response->get_data()['error']['code']);
    }

    private function signed(string $method, string $path): WP_REST_Request
    {
        $request = new WP_REST_Request($method, $path);
        $request->set_header('Authorization', 'Bearer ' . $this->token);
        return $request;
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

```
cd packages/dashboard-plugin && composer test -- --filter SitesPluginsUpdateTest
```

Expected: FAIL — `SitesPluginsUpdateController` not found / route missing.

- [ ] **Step 3: Implement the controller + register the route**

```php
<?php
// packages/dashboard-plugin/src/Rest/SitesPluginsUpdateController.php
declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Jobs\UpdateSitePlugin;
use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\SitePluginsRepository;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /defyn/v1/sites/{id}/plugins/{slug}/update
 *
 * Bearer + RateLimit::pluginsUpdate (chains RequireAuth::check). Owner check.
 * Plugin must exist in inventory, update_available=1, not already in flight.
 *
 * Spec: docs/superpowers/specs/2026-06-06-p2-2-plugin-updates-design.md §7
 */
final class SitesPluginsUpdateController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');
        $slug   = (string) $request->get_param('slug');

        if ((new SitesRepository())->findByIdForUser($siteId, $userId) === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }

        $repo = new SitePluginsRepository();
        $row = $repo->findRowForSiteAndSlug($siteId, $slug);
        if ($row === null) {
            return ErrorResponse::create(404, 'plugins.not_found_in_inventory',
                sprintf('Plugin "%s" is not in this site\'s inventory.', $slug));
        }

        if ((int) $row['update_available'] === 0) {
            return ErrorResponse::create(409, 'plugins.no_update_available',
                sprintf('No update available for "%s".', $slug));
        }

        if (in_array($row['update_state'], ['queued', 'updating'], true)) {
            return ErrorResponse::create(409, 'plugins.update_already_in_progress',
                sprintf('Update for "%s" is already in progress.', $slug));
        }

        $now = gmdate('Y-m-d H:i:s');
        $repo->markUpdateRequested($siteId, $slug, $now);

        (new ActivityLogger())->log($userId, $siteId, 'plugin_update.requested', [
            'slug'            => $slug,
            'current_version' => $row['version'] ?? null,
            'target_version'  => $row['update_version'] ?? null,
        ]);

        \as_schedule_single_action(time(), UpdateSitePlugin::HOOK, [$siteId, $slug, 0]);

        return new WP_REST_Response([
            'scheduled' => true,
            'site_id'   => $siteId,
            'slug'      => $slug,
        ], 202);
    }
}
```

Then in `packages/dashboard-plugin/src/Rest/RestRouter.php`, alongside the existing `/sites/{id}/plugins/refresh` registration:

```php
register_rest_route(self::NAMESPACE, '/sites/(?P<id>\d+)/plugins/(?P<slug>[a-z0-9-]{1,80})/update', [
    'methods'             => 'POST',
    'callback'            => [new SitesPluginsUpdateController(), 'handle'],
    'permission_callback' => [RateLimit::class, 'pluginsUpdate'],
]);
```

Add `use Defyn\Dashboard\Rest\SitesPluginsUpdateController;` at the top.

- [ ] **Step 4: Run the test, verify it passes**

```
cd packages/dashboard-plugin && composer test -- --filter SitesPluginsUpdateTest
```

Expected: PASS (5/5).

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Rest/SitesPluginsUpdateController.php \
        packages/dashboard-plugin/src/Rest/RestRouter.php \
        packages/dashboard-plugin/tests/Integration/Rest/SitesPluginsUpdateTest.php
git commit -m "feat(p2-2): POST /defyn/v1/sites/{id}/plugins/{slug}/update"
```

---

### Task 16: Dashboard v0.3.0 — version bump + CORS regression

**Files:**
- Modify: `packages/dashboard-plugin/defyn-dashboard.php`
- Modify: `packages/dashboard-plugin/readme.txt`
- Test: `packages/dashboard-plugin/tests/Integration/Rest/SitesPluginsUpdateCorsTest.php`

Bump dashboard version to 0.3.0 and add a CORS regression test for the new endpoint (mirrors P2.1 Task 12 CORS check). The uninstaller already drops `wp_defyn_site_plugins` (P2.1 left it that way), so no new entries needed.

- [ ] **Step 1: Write the CORS test**

```php
<?php
declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Rest\RestRouter;
use WP_REST_Request;
use WP_UnitTestCase;

final class SitesPluginsUpdateCorsTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new RestRouter())->register();
    }

    public function testOptionsRequestGetsCorsHeaders(): void
    {
        $request = new WP_REST_Request('OPTIONS', '/defyn/v1/sites/1/plugins/akismet/update');
        $request->set_header('Origin', 'https://app.defynwp.defyn.agency');
        $request->set_header('Access-Control-Request-Method', 'POST');
        $request->set_header('Access-Control-Request-Headers', 'authorization');

        $response = rest_do_request($request);
        $filtered = apply_filters('rest_post_dispatch', $response, rest_get_server(), $request);

        $this->assertStringContainsString('app.defynwp.defyn.agency',
            $filtered->get_headers()['Access-Control-Allow-Origin'] ?? '');
    }

    public function testUnauthenticatedPostReturnsEnvelopeShape(): void
    {
        $request = new WP_REST_Request('POST', '/defyn/v1/sites/1/plugins/akismet/update');
        $response = rest_do_request($request);

        $data = $response->get_data();
        $this->assertArrayHasKey('error', $data);
        $this->assertArrayHasKey('code', $data['error']);
        $this->assertArrayHasKey('message', $data['error']);
    }
}
```

- [ ] **Step 2: Run the test, verify it passes (existing CORS + envelope filters already cover the new route — this is regression coverage)**

```
cd packages/dashboard-plugin && composer test -- --filter SitesPluginsUpdateCorsTest
```

Expected: PASS (2/2).

- [ ] **Step 3: Bump version + add changelog**

In `defyn-dashboard.php`:

```
 * Version:           0.3.0
```

In `readme.txt`:

```
Stable tag: 0.3.0
```

```
== Changelog ==

= 0.3.0 =
* Feature: operator can update individual plugins on managed sites from the DefynWP dashboard. New POST /defyn/v1/sites/{id}/plugins/{slug}/update schedules an AS job that calls the connector's new /plugins/{slug}/update endpoint with a 120 s HTTP timeout, branches on success/409/failure, and writes the result back to wp_defyn_site_plugins. Schema bump v2 → v3 adds update_state, last_update_error, last_update_attempt_at columns (P2.2).

= 0.2.0 =
* Feature: per-site plugin inventory surfaces every installed plugin with update_available + update_version flags. Background sync extension picks up the inventory automatically; new POST /defyn/v1/sites/{id}/plugins/refresh forces an immediate refresh (P2.1).
```

- [ ] **Step 4: Run the full dashboard suite to confirm baseline green**

```
cd packages/dashboard-plugin && composer test
```

Expected: ALL PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/defyn-dashboard.php \
        packages/dashboard-plugin/readme.txt \
        packages/dashboard-plugin/tests/Integration/Rest/SitesPluginsUpdateCorsTest.php
git commit -m "chore(p2-2): dashboard v0.3.0 — release version bump + CORS regression"
```

---

### Task 17: SPA Zod schema + MSW handler

**Files:**
- Modify: `apps/web/src/types/api/plugins.ts`
- Modify: `apps/web/src/test/handlers.ts`
- Test: `apps/web/tests/types/api/plugins.test.ts` (new)

Extend `pluginSchema` with three new fields. Add the new mutation response schema. MSW handler simulates the queued → updating → idle progression with a small delay so the polling tests have real state transitions to observe.

- [ ] **Step 1: Write the failing test**

```ts
// apps/web/tests/types/api/plugins.test.ts
import { describe, it, expect } from 'vitest';
import { pluginSchema, updateSitePluginResponseSchema } from '@/types/api/plugins';

describe('pluginSchema (P2.2 extension)', () => {
  it('accepts update_state, last_update_error, last_update_attempt_at', () => {
    const parsed = pluginSchema.parse({
      slug: 'akismet',
      name: 'Akismet',
      version: '5.7',
      update_available: true,
      update_version: '5.8',
      update_state: 'idle',
      last_update_error: null,
      last_update_attempt_at: null,
    });
    expect(parsed.update_state).toBe('idle');
  });

  it('rejects an unknown update_state value', () => {
    expect(() =>
      pluginSchema.parse({
        slug: 'akismet',
        name: 'Akismet',
        version: '5.7',
        update_available: true,
        update_version: '5.8',
        update_state: 'mystery',
        last_update_error: null,
        last_update_attempt_at: null,
      }),
    ).toThrow();
  });

  it('rejects a payload missing update_state', () => {
    expect(() =>
      pluginSchema.parse({
        slug: 'akismet',
        name: 'Akismet',
        version: '5.7',
        update_available: true,
        update_version: '5.8',
        last_update_error: null,
        last_update_attempt_at: null,
      }),
    ).toThrow();
  });
});

describe('updateSitePluginResponseSchema', () => {
  it('accepts the 202 success shape', () => {
    const parsed = updateSitePluginResponseSchema.parse({
      scheduled: true,
      site_id: 1,
      slug: 'akismet',
    });
    expect(parsed.scheduled).toBe(true);
  });

  it('rejects scheduled: false (literal true required)', () => {
    expect(() =>
      updateSitePluginResponseSchema.parse({
        scheduled: false,
        site_id: 1,
        slug: 'akismet',
      }),
    ).toThrow();
  });
});
```

- [ ] **Step 2: Run the test, verify it fails**

```
cd apps/web && pnpm test -- --run tests/types/api/plugins.test.ts
```

Expected: FAIL — `update_state` not on schema OR `updateSitePluginResponseSchema` not exported.

- [ ] **Step 3: Extend `pluginSchema` + add the new response schema**

In `apps/web/src/types/api/plugins.ts`:

```ts
export const pluginSchema = z.object({
  slug: z.string(),
  name: z.string(),
  version: z.string().nullable(),
  update_available: z.boolean(),
  update_version: z.string().nullable(),
  update_state: z.enum(['idle', 'queued', 'updating', 'failed']),
  last_update_error: z.string().nullable(),
  last_update_attempt_at: z.string().nullable(),
});

export const updateSitePluginResponseSchema = z.object({
  scheduled: z.literal(true),
  site_id: z.number(),
  slug: z.string(),
});

export type UpdateSitePluginResponse = z.infer<typeof updateSitePluginResponseSchema>;
```

Add the MSW handler in `apps/web/src/test/handlers.ts`. Append:

```ts
// P2.2 — simulate POST /sites/:id/plugins/:slug/update
http.post(`${API_BASE}/sites/:id/plugins/:slug/update`, async ({ params }) => {
  const siteId = Number(params.id);
  const slug = String(params.slug);

  const idx = mockSitePlugins[siteId]?.findIndex((p) => p.slug === slug);
  if (idx === undefined || idx === -1) {
    return HttpResponse.json(
      { error: { code: 'plugins.not_found_in_inventory', message: 'Plugin not in inventory.' } },
      { status: 404 },
    );
  }

  // Optimistic queued
  mockSitePlugins[siteId][idx] = { ...mockSitePlugins[siteId][idx], update_state: 'queued' };

  // Schedule deferred transitions: queued → updating @ 50ms, updating → idle @ 200ms
  setTimeout(() => {
    if (mockSitePlugins[siteId]?.[idx]?.update_state === 'queued') {
      mockSitePlugins[siteId][idx] = { ...mockSitePlugins[siteId][idx], update_state: 'updating' };
    }
  }, 50);
  setTimeout(() => {
    const cur = mockSitePlugins[siteId]?.[idx];
    if (!cur || cur.update_state !== 'updating') return;
    mockSitePlugins[siteId][idx] = {
      ...cur,
      update_state: 'idle',
      version: cur.update_version ?? cur.version,
      update_available: false,
      update_version: null,
    };
  }, 200);

  return HttpResponse.json({ scheduled: true, site_id: siteId, slug }, { status: 202 });
}),
```

Also ensure `mockSitePlugins` rows seeded elsewhere in `handlers.ts` carry the new fields — add defaults `update_state: 'idle', last_update_error: null, last_update_attempt_at: null` to every existing seed.

- [ ] **Step 4: Run the test, verify it passes**

```
cd apps/web && pnpm test -- --run tests/types/api/plugins.test.ts
```

Expected: PASS (5/5).

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/types/api/plugins.ts \
        apps/web/src/test/handlers.ts \
        apps/web/tests/types/api/plugins.test.ts
git commit -m "feat(p2-2): plugin Zod schema extension + MSW update handler"
```

---

### Task 18: `useUpdateSitePlugin` mutation hook + polling

**Files:**
- Create: `apps/web/src/lib/mutations/useUpdateSitePlugin.ts`
- Test: `apps/web/tests/lib/mutations/useUpdateSitePlugin.test.tsx`

Per-slug mutation that POSTs to `/sites/{id}/plugins/{slug}/update` then polls `useSitePlugins` every 2 s while in flight. Settles on `idle`/`failed`. Hard 5 min cap as a safety net.

- [ ] **Step 1: Write the failing test**

```tsx
// apps/web/tests/lib/mutations/useUpdateSitePlugin.test.tsx
import { describe, it, expect, beforeEach } from 'vitest';
import { renderHook, act, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useUpdateSitePlugin } from '@/lib/mutations/useUpdateSitePlugin';
import { useSitePlugins } from '@/lib/queries/useSitePlugins';
import { mockSitePlugins, resetMockSitePlugins } from '@/test/handlers';
import { setAccessToken } from '@/lib/apiClient';

function makeWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return ({ children }: { children: React.ReactNode }) =>
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

describe('useUpdateSitePlugin', () => {
  beforeEach(() => {
    resetMockSitePlugins();
    setAccessToken('fake');
    mockSitePlugins[1] = [
      {
        slug: 'akismet', name: 'Akismet', version: '5.7',
        update_available: true, update_version: '5.8',
        update_state: 'idle', last_update_error: null, last_update_attempt_at: null,
      },
    ];
  });

  it('fires POST, transitions row to idle, stops polling', async () => {
    const Wrap = makeWrapper();

    const { result: list } = renderHook(() => useSitePlugins(1), { wrapper: Wrap });
    const { result: mut } = renderHook(() => useUpdateSitePlugin(1, 'akismet'), { wrapper: Wrap });

    await waitFor(() => expect(list.current.data).toBeDefined());

    act(() => { mut.current.update(); });
    await waitFor(() => expect(mut.current.isPolling).toBe(true));

    // After MSW's deferred transitions complete + polling tick observes idle
    await waitFor(
      () => expect(mut.current.isPolling).toBe(false),
      { timeout: 4000 },
    );

    const row = list.current.data?.plugins.find((p) => p.slug === 'akismet');
    expect(row?.version).toBe('5.8');
    expect(row?.update_available).toBe(false);
  });
});
```

- [ ] **Step 2: Run the test, verify it fails**

```
cd apps/web && pnpm test -- --run tests/lib/mutations/useUpdateSitePlugin.test.tsx
```

Expected: FAIL — hook doesn't exist.

- [ ] **Step 3: Implement the hook**

```ts
// apps/web/src/lib/mutations/useUpdateSitePlugin.ts
import { useEffect, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { useSitePlugins } from '@/lib/queries/useSitePlugins';
import { updateSitePluginResponseSchema } from '@/types/api/plugins';

const POLL_INTERVAL_MS = 2000;
const HARD_CAP_MS = 5 * 60 * 1000;

export function useUpdateSitePlugin(siteId: number, slug: string) {
  const queryClient = useQueryClient();
  const [isPolling, setIsPolling] = useState(false);

  const mutation = useMutation({
    mutationFn: async () => {
      const res = await apiClient.post(`/sites/${siteId}/plugins/${slug}/update`);
      const parsed = updateSitePluginResponseSchema.parse(res.data);
      setIsPolling(true);
      return parsed;
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['sites', siteId, 'plugins'] }),
  });

  // useSitePlugins is the source of truth for row state; refetchInterval drives polling.
  const { data: list } = useSitePlugins(siteId, {
    refetchInterval: isPolling ? POLL_INTERVAL_MS : false,
  });

  const rowState = list?.plugins.find((p) => p.slug === slug)?.update_state;

  useEffect(() => {
    if (!isPolling) return;
    if (rowState === 'idle' || rowState === 'failed') {
      setIsPolling(false);
      return;
    }
    const cap = setTimeout(() => setIsPolling(false), HARD_CAP_MS);
    return () => clearTimeout(cap);
  }, [isPolling, rowState]);

  return {
    update: mutation.mutate,
    isPending: mutation.isPending,
    isPolling,
  };
}
```

- [ ] **Step 4: Run the test, verify it passes**

```
cd apps/web && pnpm test -- --run tests/lib/mutations/useUpdateSitePlugin.test.tsx
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/lib/mutations/useUpdateSitePlugin.ts \
        apps/web/tests/lib/mutations/useUpdateSitePlugin.test.tsx
git commit -m "feat(p2-2): useUpdateSitePlugin mutation hook + 2s polling"
```

---

### Task 19: shadcn Tooltip primitive + `SitePluginsRow` three-state rendering

**Files:**
- Create: `apps/web/src/components/ui/tooltip.tsx`
- Modify: `apps/web/package.json` (add `@radix-ui/react-tooltip`)
- Modify: `apps/web/src/components/sites/SitePluginsRow.tsx`
- Test: `apps/web/tests/components/sites/SitePluginsRow.test.tsx` (extend existing or create)

The row must render four visual states (idle non-upgradable, idle upgradable, in-flight, failed). Failed state shows a ⚠ icon with a Tooltip + flips the button label to "Retry". In-flight state disables the button and shows a spinner.

- [ ] **Step 1: Add the Radix package**

```bash
cd apps/web && pnpm add @radix-ui/react-tooltip
```

- [ ] **Step 2: Write the failing test**

```tsx
// apps/web/tests/components/sites/SitePluginsRow.test.tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { SitePluginsRow } from '@/components/sites/SitePluginsRow';
import type { Plugin } from '@/types/api/plugins';

const base: Plugin = {
  slug: 'akismet', name: 'Akismet', version: '5.7',
  update_available: false, update_version: null,
  update_state: 'idle', last_update_error: null, last_update_attempt_at: null,
};

function wrap(p: Plugin) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <table><tbody>
        <SitePluginsRow plugin={p} siteId={1} />
      </tbody></table>
    </QueryClientProvider>,
  );
}

describe('SitePluginsRow', () => {
  it('idle non-upgradable renders a dash and no button', () => {
    wrap(base);
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
    expect(screen.getByText('—')).toBeInTheDocument();
  });

  it('idle upgradable renders the badge + Update button', () => {
    wrap({ ...base, update_available: true, update_version: '5.8' });
    expect(screen.getByText('→ 5.8')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /^Update$/ })).toBeInTheDocument();
  });

  it('queued and updating render the same disabled Updating… button', () => {
    const queued = wrap({ ...base, update_available: true, update_version: '5.8', update_state: 'queued' });
    expect(screen.getByRole('button', { name: /Updating/i })).toBeDisabled();
    queued.unmount();

    wrap({ ...base, update_available: true, update_version: '5.8', update_state: 'updating' });
    expect(screen.getByRole('button', { name: /Updating/i })).toBeDisabled();
  });

  it('failed state shows warning icon and Retry button', async () => {
    const user = userEvent.setup();
    wrap({
      ...base,
      update_available: true, update_version: '5.8',
      update_state: 'failed',
      last_update_error: 'Could not copy file. /wp-content/upgrade/akismet/akismet.php',
    });
    expect(screen.getByRole('button', { name: /^Retry$/ })).toBeInTheDocument();

    const warningIcon = screen.getByLabelText(/update failed/i);
    expect(warningIcon).toBeInTheDocument();

    // Tooltip surfaces the error on hover/focus
    await user.hover(warningIcon);
    expect(await screen.findByText(/Could not copy file/i)).toBeInTheDocument();
  });
});
```

- [ ] **Step 3: Run the test, verify it fails**

```
cd apps/web && pnpm test -- --run tests/components/sites/SitePluginsRow.test.tsx
```

Expected: FAIL — Tooltip not implemented OR row doesn't switch state.

- [ ] **Step 4: Create the Tooltip primitive**

```tsx
// apps/web/src/components/ui/tooltip.tsx
import * as React from 'react';
import * as TooltipPrimitive from '@radix-ui/react-tooltip';
import { cn } from '@/lib/cn';

export const TooltipProvider = TooltipPrimitive.Provider;
export const Tooltip = TooltipPrimitive.Root;
export const TooltipTrigger = TooltipPrimitive.Trigger;

export const TooltipContent = React.forwardRef<
  React.ElementRef<typeof TooltipPrimitive.Content>,
  React.ComponentPropsWithoutRef<typeof TooltipPrimitive.Content>
>(({ className, sideOffset = 4, ...props }, ref) => (
  <TooltipPrimitive.Portal>
    <TooltipPrimitive.Content
      ref={ref}
      sideOffset={sideOffset}
      className={cn(
        'z-50 max-w-xs overflow-hidden rounded-md bg-zinc-900 px-3 py-1.5 text-xs text-white shadow-md',
        className,
      )}
      {...props}
    />
  </TooltipPrimitive.Portal>
));
TooltipContent.displayName = 'TooltipContent';
```

Rewrite `SitePluginsRow.tsx`:

```tsx
import { AlertCircle, Loader2 } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { SitePluginUpdateConfirmDialog } from '@/components/sites/SitePluginUpdateConfirmDialog';
import { useUpdateSitePlugin } from '@/lib/mutations/useUpdateSitePlugin';
import type { Plugin } from '@/types/api/plugins';

interface Props {
  plugin: Plugin;
  siteId: number;
}

export function SitePluginsRow({ plugin, siteId }: Props) {
  const [confirmOpen, setConfirmOpen] = useState(false);
  const { update, isPolling } = useUpdateSitePlugin(siteId, plugin.slug);

  const inFlight = plugin.update_state === 'queued' || plugin.update_state === 'updating' || isPolling;
  const failed = plugin.update_state === 'failed';
  const rowClasses = inFlight ? 'opacity-70 bg-zinc-50' : failed ? 'bg-red-50' : '';

  const actionCell = !plugin.update_available ? (
    <span className="text-zinc-300">—</span>
  ) : (
    <div className="flex items-center gap-2">
      <span className="bg-amber-200 px-1.5 py-0.5 rounded text-xs">→ {plugin.update_version}</span>
      {failed && (
        <TooltipProvider>
          <Tooltip>
            <TooltipTrigger asChild>
              <span aria-label="Update failed" className="text-red-600 cursor-help">
                <AlertCircle className="w-4 h-4" />
              </span>
            </TooltipTrigger>
            <TooltipContent>
              {(plugin.last_update_error ?? 'Update failed.').slice(0, 200)}
              {(plugin.last_update_error ?? '').length > 200 ? '…' : ''}
            </TooltipContent>
          </Tooltip>
        </TooltipProvider>
      )}
      <Button
        size="sm"
        disabled={inFlight}
        onClick={() => setConfirmOpen(true)}
      >
        {inFlight ? (
          <><Loader2 className="w-3 h-3 animate-spin mr-1" />Updating…</>
        ) : failed ? (
          'Retry'
        ) : (
          'Update'
        )}
      </Button>
      <SitePluginUpdateConfirmDialog
        plugin={plugin}
        open={confirmOpen}
        onOpenChange={setConfirmOpen}
        onConfirm={() => { setConfirmOpen(false); update(); }}
      />
    </div>
  );

  return (
    <tr className={`border-b ${rowClasses}`}>
      <td className="py-2">
        <div className="font-medium">{plugin.name}</div>
        <div className="text-xs text-zinc-500 font-mono">{plugin.slug}</div>
      </td>
      <td className="py-2 font-mono text-xs">{plugin.version ?? '—'}</td>
      <td className="py-2">{actionCell}</td>
    </tr>
  );
}
```

- [ ] **Step 5: Run the test + commit**

```
cd apps/web && pnpm test -- --run tests/components/sites/SitePluginsRow.test.tsx
```

Expected: PASS (4/4).

```bash
git add apps/web/src/components/ui/tooltip.tsx \
        apps/web/src/components/sites/SitePluginsRow.tsx \
        apps/web/package.json apps/web/pnpm-lock.yaml \
        apps/web/tests/components/sites/SitePluginsRow.test.tsx
git commit -m "feat(p2-2): SitePluginsRow three-state rendering + Retry label flip"
```

---

### Task 20: `SitePluginUpdateConfirmDialog` + build zips + manual smoke + tag

**Files:**
- Create: `apps/web/src/components/sites/SitePluginUpdateConfirmDialog.tsx`
- Test: `apps/web/tests/components/sites/SitePluginUpdateConfirmDialog.test.tsx`

**Step A — implement the confirm dialog**

- [ ] **Step A.1: Write the failing test**

```tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { SitePluginUpdateConfirmDialog } from '@/components/sites/SitePluginUpdateConfirmDialog';
import type { Plugin } from '@/types/api/plugins';

const plugin: Plugin = {
  slug: 'akismet', name: 'Akismet', version: '5.7',
  update_available: true, update_version: '5.8',
  update_state: 'idle', last_update_error: null, last_update_attempt_at: null,
};

describe('SitePluginUpdateConfirmDialog', () => {
  it('renders the version diff when open', () => {
    render(<SitePluginUpdateConfirmDialog plugin={plugin} open onOpenChange={() => {}} onConfirm={() => {}} />);
    expect(screen.getByText(/Update Akismet/i)).toBeInTheDocument();
    expect(screen.getByText('5.7')).toBeInTheDocument();
    expect(screen.getByText('5.8')).toBeInTheDocument();
    expect(screen.getByText(/maintenance mode/i)).toBeInTheDocument();
  });

  it('calls onConfirm when Update clicked', async () => {
    const user = userEvent.setup();
    let confirmed = false;
    render(<SitePluginUpdateConfirmDialog plugin={plugin} open onOpenChange={() => {}} onConfirm={() => { confirmed = true; }} />);
    await user.click(screen.getByRole('button', { name: /^Update$/ }));
    expect(confirmed).toBe(true);
  });

  it('calls onOpenChange(false) when Cancel clicked', async () => {
    const user = userEvent.setup();
    let opened = true;
    render(<SitePluginUpdateConfirmDialog plugin={plugin} open onOpenChange={(o) => { opened = o; }} onConfirm={() => {}} />);
    await user.click(screen.getByRole('button', { name: /Cancel/ }));
    expect(opened).toBe(false);
  });
});
```

- [ ] **Step A.2: Run test → FAIL → implement → PASS:**

```tsx
// apps/web/src/components/sites/SitePluginUpdateConfirmDialog.tsx
import {
  AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent,
  AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import type { Plugin } from '@/types/api/plugins';

interface Props {
  plugin: Plugin;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onConfirm: () => void;
}

export function SitePluginUpdateConfirmDialog({ plugin, open, onOpenChange, onConfirm }: Props) {
  return (
    <AlertDialog open={open} onOpenChange={onOpenChange}>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>Update {plugin.name}</AlertDialogTitle>
          <AlertDialogDescription className="font-mono text-xs text-zinc-500">
            {plugin.slug}
          </AlertDialogDescription>
        </AlertDialogHeader>
        <div className="my-3 text-sm flex items-center gap-2">
          <code className="bg-zinc-100 px-1.5 py-0.5 rounded">{plugin.version}</code>
          <span className="text-zinc-400">→</span>
          <code className="bg-blue-100 px-1.5 py-0.5 rounded font-semibold">{plugin.update_version}</code>
        </div>
        <div className="bg-amber-50 border-l-2 border-amber-500 p-2 my-3 text-xs space-y-1">
          <p>The site goes into maintenance mode for the duration (~1–2 min).</p>
          <p>If the upgrade fails to download or install, the existing version stays in place.</p>
        </div>
        <AlertDialogFooter>
          <AlertDialogCancel onClick={() => onOpenChange(false)}>Cancel</AlertDialogCancel>
          <AlertDialogAction onClick={onConfirm}>Update</AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
}
```

```
cd apps/web && pnpm test -- --run tests/components/sites/SitePluginUpdateConfirmDialog.test.tsx
```

Expected: PASS (3/3).

- [ ] **Step A.3: Commit:**

```bash
git add apps/web/src/components/sites/SitePluginUpdateConfirmDialog.tsx \
        apps/web/tests/components/sites/SitePluginUpdateConfirmDialog.test.tsx
git commit -m "feat(p2-2): SitePluginUpdateConfirmDialog with accurate maintenance copy"
```

**Step B — full SPA suite green + build zips + manual smoke**

- [ ] **Step B.1: Run all suites:**

```
cd packages/connector-plugin && composer test
cd packages/dashboard-plugin && composer test
cd apps/web && pnpm test -- --run
cd apps/web && pnpm lint
```

Expected: ALL PASS.

- [ ] **Step B.2: Build connector zip:**

```bash
cd packages/connector-plugin
composer install --no-dev --optimize-autoloader
zip -r ~/Desktop/defyn-connector-v0.1.4-$(date +%Y-%m-%d).zip . \
  -x "tests/*" "node_modules/*" "*.git*"
composer install
```

- [ ] **Step B.3: Build dashboard zip:**

```bash
cd packages/dashboard-plugin
composer install --no-dev --optimize-autoloader
zip -r ~/Desktop/defyn-dashboard-v0.3.0-$(date +%Y-%m-%d).zip . \
  -x "tests/*" "node_modules/*" "*.git*"
composer install
```

- [ ] **Step B.4: Manual smoke playbook (operator + Chrome MCP if available):**

1. Upload the connector zip to SmartCoding via Plugins → Add New → Upload, replace current with uploaded.
2. Upload the dashboard zip to defynwp.defyn.agency via Plugins → Add New → Upload, replace current with uploaded.
3. **Deactivate + reactivate the dashboard plugin** via Plugins screen (P2.1 ops note — register_activation_hook doesn't always fire on "Replace current with uploaded").
4. **Curl smoke (replace SLUG with a plugin that has update_available):**

   ```bash
   TOKEN=$(curl -s -X POST https://defynwp.defyn.agency/wp-json/defyn/v1/auth/login \
     -H "Content-Type: application/json" \
     --data '{"email":"pradeep@defyn.com.au","password":"DefynWP-ifirCh5pXm5bTOj0"}' \
     | python3 -c "import sys,json; print(json.load(sys.stdin)['access_token'])")

   # Find a plugin with update_available
   curl -s -H "Authorization: Bearer $TOKEN" \
     "https://defynwp.defyn.agency/wp-json/defyn/v1/sites/1/plugins" \
     | python3 -c "import sys,json; [print(p['slug'], p['update_available'], p.get('update_version')) for p in json.load(sys.stdin)['plugins'] if p['update_available']]"

   # Trigger update on one (replace SLUG)
   curl -s -X POST -H "Authorization: Bearer $TOKEN" \
     "https://defynwp.defyn.agency/wp-json/defyn/v1/sites/1/plugins/SLUG/update"
   # → expect {"scheduled":true,"site_id":1,"slug":"SLUG"}

   # Tick wp-cron to let AS run
   for i in 1 2 3 4; do
     curl -s "https://defynwp.defyn.agency/wp-cron.php?doing_wp_cron=1" -o /dev/null
     sleep 10
   done

   # Re-fetch the plugin list — SLUG should now show new version, badge cleared
   curl -s -H "Authorization: Bearer $TOKEN" \
     "https://defynwp.defyn.agency/wp-json/defyn/v1/sites/1/plugins?_=$(date +%s)" \
     | python3 -c "import sys,json; [print(p['slug'], p['version'], p['update_state']) for p in json.load(sys.stdin)['plugins'] if p['slug']=='SLUG']"

   # Activity log should show requested → started → succeeded
   curl -s -H "Authorization: Bearer $TOKEN" \
     "https://defynwp.defyn.agency/wp-json/defyn/v1/sites/1/activity?per_page=6"
   ```

5. **SPA smoke:** open `https://app.defynwp.defyn.agency/sites/1` after a hard reload (Cloudflare Pages will have picked up the SPA build from this push). Click Update on a plugin row → confirm modal → observe Updating… → row settles on new version with badge cleared.

- [ ] **Step B.5: If smoke green: tag + push:**

```bash
cd /Users/pradeep/Local\ Sites/defynWP
git tag -a "p2-2-plugin-updates-complete" -m "$(cat <<'EOF'
P2.2 Plugin Updates — shipped

Operator can update individual plugins on managed sites from DefynWP.

Verified end-to-end against production 2026-06-06:
- Connector v0.1.4 on smartcoding.com.au
- Dashboard v0.3.0 on defynwp.defyn.agency
- SPA bundle deployed via Cloudflare Pages
- Per-row Update button → confirm modal → AS job → connector Plugin_Upgrader
  → row updates with new version
- Activity log records requested → started → succeeded sequence
- Per-site transient lock serialises concurrent upgrades on the same install

Tests: ~25-30 new tests across connector + dashboard + SPA, all green.

Ops note: dashboard upgrade still requires manual deactivate+reactivate to
fire register_activation_hook for the v3 schema migration. The schema
self-heal follow-up filed during P2.1 remains pending — runbook continues
to include deactivate+reactivate for any version that bumps
Activation::SCHEMA_VERSION.
EOF
)"
git push origin "p2-2-plugin-updates-complete"
```

- [ ] **Step B.6: Final status check**

```bash
git status
```

Expected: working tree clean.

If smoke uncovered issues, write `fix(p2-2):` commits before tagging. Otherwise the only commit in this task is whatever the dialog implementation produced in Step A.

---

## Self-review checklist

After implementing all 20 tasks, run this checklist before declaring P2.2 complete:

- [ ] All test suites green: connector + dashboard + SPA + lint
- [ ] No `console.log` / `var_dump` / `print_r` in source code (grep)
- [ ] All new error codes use the `{error:{code,message}}` envelope shape (spec §9)
- [ ] Activity log details for `plugin_update.*` match spec §10 shape
- [ ] No placeholder strings ("TODO", "FIXME", "XXX") in source
- [ ] Each commit is atomic (one task = one commit, except Task 20 which has the dialog commit + optional fix commits)
- [ ] Manual smoke playbook documented results (success on at least one real plugin)
- [ ] Tag pushed: `p2-2-plugin-updates-complete`

---

## Spec-coverage matrix (sanity check)

| Spec section | Covered by task(s) |
|---|---|
| §3.1 Connector endpoint shape | Tasks 3, 5 |
| §3.2 Per-site transient lock | Tasks 3, 4 |
| §3.3 Plugin_Upgrader integration | Tasks 1, 2 |
| §3.4 Connector version + changelog | Task 6 |
| §4.1 PluginUpgraderService | Task 2 |
| §4.2 CapturingUpgraderSkin | Task 1 |
| §4.3 PluginUpdateController | Task 3 |
| §4.4 RestRouter route | Task 5 |
| §5.1 Schema delta v3 | Task 7 |
| §5.2 Migration mechanism | Task 7 |
| §6.1 Repository extensions | Task 8 |
| §6.2 SignedHttpClient timeout | Task 9 |
| §6.3 UpdateSitePlugin AS job | Tasks 10, 11, 12 |
| §6.4 Plugin::boot AS hook | Task 13 |
| §7.1 Dashboard REST endpoint | Task 15 |
| §7.2 RateLimit::pluginsUpdate | Task 14 |
| §7.3 Dashboard RestRouter route | Task 15 |
| §8.1 SitePluginsRow three states | Task 19 |
| §8.2 SitePluginUpdateConfirmDialog | Task 20 |
| §8.3 useUpdateSitePlugin hook | Task 18 |
| §8.4 Zod schemas | Task 17 |
| §8.5 SitePluginsPanel integration | Task 19 (row owns the dialog state) |
| §9 Error envelope codes (all 9) | Tasks 3, 14, 15 |
| §10 Activity events (all 5) | Tasks 10, 11, 12, 15 |
| §11 Multisite (no special handling) | — (no code) |
| §12 Concurrency rationale | Task 4 (lock test) |
| §13 Testing strategy | All tasks |
| §14 Versioning | Tasks 6, 16 |
| §15 Open questions | — (notes only) |
| §16 Premium plugins assumption | — (no code) |

Coverage: complete.
