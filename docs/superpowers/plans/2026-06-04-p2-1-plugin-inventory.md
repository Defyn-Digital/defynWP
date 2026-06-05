# P2.1 Plugin Inventory Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Surface every plugin on each connected site (name, version, update_available, update_version) in the dashboard SPA. Read-only — P2.1 makes no write operations on managed sites. Output: connector v0.1.3 + dashboard v0.2.0.

**Architecture:** New signed connector endpoints `GET /defyn-connector/v1/plugins` + `POST /defyn-connector/v1/plugins/refresh` return the plugin inventory derived from WordPress core (`get_plugins()` + the `update_plugins` transient). Dashboard stores the inventory in a new sibling table `wp_defyn_site_plugins` (one row per (site, slug), UNIQUE constraint enables idempotent delta sync). F7's existing `defyn_sync_site` job is extended to also pull `/plugins` after `/status`; a new `defyn_refresh_site_plugins` AS job handles operator-triggered immediate refresh. SPA renders a new `SitePluginsPanel` below the existing Activity panel on the site detail page, with a "Updates only" filter and a refresh button that polls until `last_synced_at` advances.

**Tech Stack:** PHP 8.1+ (PHPUnit, `WP_UnitTestCase` / `AbstractSchemaTestCase`), Action Scheduler, WordPress REST API, React 18 + TypeScript + TanStack Query v5 + Zod + Tailwind + shadcn/ui + Vitest + React Testing Library + MSW.

**Spec:** [`docs/superpowers/specs/2026-06-04-p2-1-plugin-inventory-design.md`](../specs/2026-06-04-p2-1-plugin-inventory-design.md)

---

## Workflow conventions

- **Branch:** Work directly on `main` (matches F-series cadence). Each Task = one atomic commit.
- **Test discipline (TDD):** Step 1 always writes the failing test. Step 2 runs it and confirms it fails. Step 3 writes the implementation. Step 4 confirms it passes. Step 5 commits.
- **Test runners:**
  - Connector PHP: `cd packages/connector-plugin && composer test`
  - Dashboard PHP: `cd packages/dashboard-plugin && composer test`
  - SPA: `cd apps/web && pnpm test -- --run`
- **Commit message format:** `<type>(p2-1): <description>` where `<type>` ∈ {feat, fix, refactor, docs, test, chore}.
- **All edits adhere to** `~/.claude/rules/common/coding-style.md` — immutability, KISS, DRY, YAGNI, error handling, no console.log.
- **Cache headers:** the existing `RestRouter::applyNoCacheHeaders` filter from connector v0.1.2 / dashboard v0.1.1 already covers the new endpoints — no extra wiring needed, just regression tests.

---

## File structure overview

### Connector plugin (v0.1.3) — new files

| Path | Responsibility |
|---|---|
| `src/SiteInfo/PluginListCollector.php` | Build the plugin payload from `get_plugins()` + `update_plugins` transient; truncate at 500 |
| `src/Rest/PluginsListController.php` | Thin shim: forward `GET /plugins` to `PluginListCollector::collect()` |
| `src/Rest/PluginsRefreshController.php` | Force `wp_update_plugins()` then return collector payload |
| `tests/Integration/SiteInfo/PluginListCollectorTest.php` | Collector unit tests |
| `tests/Integration/Rest/PluginsListTest.php` | Signed GET integration |
| `tests/Integration/Rest/PluginsRefreshTest.php` | Signed POST integration |
| `tests/Integration/Rest/PluginsCacheHeadersTest.php` | Cache-Control: no-store regression |

### Connector plugin — modified files

| Path | What changes |
|---|---|
| `src/Rest/RestRouter.php` | Register two new routes |
| `defyn-connector.php` | Version `0.1.2` → `0.1.3` |
| `readme.txt` | Stable tag + changelog entry |

### Dashboard plugin (v0.2.0) — new files

| Path | Responsibility |
|---|---|
| `src/Schema/SitePluginsTable.php` | dbDelta schema for `wp_defyn_site_plugins` |
| `src/Schema/SchemaVersion.php` | Reads/writes the `defyn_schema_version` option |
| `src/Models/Plugin.php` | Immutable value object mapped from a DB row |
| `src/Services/SitePluginsRepository.php` | `findAllForSite`, `lastSyncedAtForSite`, `replaceForSite` (delta sync) |
| `src/Services/SyncPluginsService.php` | Orchestrates delta sync + writes activity log event |
| `src/Jobs/RefreshSitePlugins.php` | AS hook handler for `defyn_refresh_site_plugins` |
| `src/Rest/SitesPluginsListController.php` | `GET /sites/{id}/plugins` |
| `src/Rest/SitesPluginsRefreshController.php` | `POST /sites/{id}/plugins/refresh` |
| `tests/Integration/Schema/SitePluginsTableTest.php` | Table created, indexes present |
| `tests/Integration/Schema/SchemaVersionTest.php` | Version option behavior + idempotent migration |
| `tests/Integration/Services/SyncPluginsServiceTest.php` | Delta logic across all branches |
| `tests/Integration/Jobs/RefreshSitePluginsTest.php` | AS job calls connector, runs service, logs event |
| `tests/Integration/Jobs/SyncSitePluginsIntegrationTest.php` | F7's job ALSO syncs plugins after status |
| `tests/Integration/Rest/SitesPluginsListTest.php` | GET endpoint |
| `tests/Integration/Rest/SitesPluginsRefreshTest.php` | POST endpoint + rate limit |

### Dashboard plugin — modified files

| Path | What changes |
|---|---|
| `src/Jobs/SyncSite.php` | After successful status sync, also call `/plugins` |
| `src/Rest/RestRouter.php` | Register two new routes |
| `src/Rest/Middleware/RateLimit.php` | Add `pluginsRefresh` method (6/min/user/site) |
| `src/Activation.php` | Call `SitePluginsTable::createSql` via dbDelta + bump schema version |
| `src/Plugin.php` | `add_action('defyn_refresh_site_plugins', ...)` |
| `src/Uninstaller.php` | DROP `wp_defyn_site_plugins` on uninstall |
| `defyn-dashboard.php` | Version `0.1.1` → `0.2.0` |

### SPA — new files

| Path | Responsibility |
|---|---|
| `apps/web/src/types/api/plugins.ts` | Zod schemas: `pluginSchema`, `sitePluginsListResponseSchema` |
| `apps/web/src/lib/queries/useSitePlugins.ts` | TanStack query hook |
| `apps/web/src/lib/mutations/useRefreshSitePlugins.ts` | Mutation + 2s polling until `last_synced_at` advances |
| `apps/web/src/components/sites/SitePluginsRow.tsx` | One-row component |
| `apps/web/src/components/sites/SitePluginsPanel.tsx` | Header + filter + refresh + table |
| `apps/web/tests/SitePluginsPanel.test.tsx` | Component tests |
| `apps/web/tests/useSitePlugins.test.tsx` | Hook tests |
| `apps/web/tests/useRefreshSitePlugins.test.tsx` | Mutation + polling tests |

### SPA — modified files

| Path | What changes |
|---|---|
| `apps/web/src/routes/SiteDetail.tsx` | Render `<SitePluginsPanel />`; widen container `max-w-xl` → `max-w-3xl` |
| `apps/web/src/test/handlers.ts` | MSW handlers for `GET /sites/:id/plugins` and `POST /sites/:id/plugins/refresh` |

---

# Tasks

## Task 1 — PluginListCollector

Builds the plugin payload from `get_plugins()` + the `update_plugins` transient. Sorts by slug, caps at 500, normalizes empty version → `null`, skips empty-name rows.

**Files:**
- Create: `packages/connector-plugin/src/SiteInfo/PluginListCollector.php`
- Test: `packages/connector-plugin/tests/Integration/SiteInfo/PluginListCollectorTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/connector-plugin/tests/Integration/SiteInfo/PluginListCollectorTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\SiteInfo;

use Defyn\Connector\SiteInfo\PluginListCollector;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class PluginListCollectorTest extends WP_UnitTestCase
{
    public function testReturnsEmptyListWhenNoPluginsInstalled(): void
    {
        $result = (new PluginListCollector())->collect();

        self::assertArrayHasKey('plugins', $result);
        self::assertArrayHasKey('truncated', $result);
        self::assertIsArray($result['plugins']);
        self::assertIsBool($result['truncated']);
        self::assertFalse($result['truncated'], 'test fixture has < 500 plugins');
    }

    public function testRowsAreSortedBySlugAscending(): void
    {
        $result = (new PluginListCollector())->collect();
        $slugs  = array_column($result['plugins'], 'slug');
        $sorted = $slugs;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $slugs, 'plugins must be sorted by slug ascending');
    }

    public function testDerivesUpdateAvailableFromUpdatePluginsTransient(): void
    {
        $fakeUpdates           = new \stdClass();
        $fakeUpdates->response = [
            'hello.php' => (object) ['new_version' => '99.9.9'],
        ];
        set_site_transient('update_plugins', $fakeUpdates);

        $result  = (new PluginListCollector())->collect();
        $byPath  = array_column($result['plugins'], null, 'slug');

        self::assertArrayHasKey('hello.php', $byPath, 'Hello Dolly should be installed in WP test fixture');
        self::assertTrue($byPath['hello.php']['update_available']);
        self::assertSame('99.9.9', $byPath['hello.php']['update_version']);

        delete_site_transient('update_plugins');
    }

    public function testUpdateAvailableFalseWhenNoTransientEntry(): void
    {
        delete_site_transient('update_plugins');

        $result = (new PluginListCollector())->collect();
        foreach ($result['plugins'] as $p) {
            self::assertFalse($p['update_available'], "plugin {$p['slug']} should not have an update");
            self::assertNull($p['update_version'], "plugin {$p['slug']} update_version should be null");
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd packages/connector-plugin && composer test -- --filter PluginListCollectorTest
```

Expected: FAIL — `Class "Defyn\Connector\SiteInfo\PluginListCollector" not found`.

- [ ] **Step 3: Write minimal implementation**

Create `packages/connector-plugin/src/SiteInfo/PluginListCollector.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\SiteInfo;

/**
 * P2.1 — gathers the GET /plugins payload (spec § 3.1 + § 4.1).
 *
 * Pure read; never mutates WP state. Loads admin includes lazily because
 * get_plugins() lives in wp-admin/includes/plugin.php.
 */
final class PluginListCollector
{
    public const MAX_PLUGINS = 500;

    /**
     * @return array{
     *   plugins: list<array{
     *     slug: string,
     *     name: string,
     *     version: ?string,
     *     update_available: bool,
     *     update_version: ?string
     *   }>,
     *   truncated: bool
     * }
     */
    public function collect(): array
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all     = get_plugins() ?: [];
        $updates = get_site_transient('update_plugins');
        $byPath  = is_object($updates) && isset($updates->response)
            ? (array) $updates->response
            : [];

        $plugins = [];
        foreach ($all as $slug => $header) {
            $name = (string) ($header['Name'] ?? '');
            if ($name === '') {
                continue;
            }
            $version = (string) ($header['Version'] ?? '');
            $upd     = $byPath[(string) $slug] ?? null;
            $plugins[] = [
                'slug'             => (string) $slug,
                'name'             => $name,
                'version'          => $version !== '' ? $version : null,
                'update_available' => $upd !== null,
                'update_version'   => $upd !== null && isset($upd->new_version)
                    ? (string) $upd->new_version
                    : null,
            ];
        }

        usort($plugins, static fn(array $a, array $b): int => strcmp($a['slug'], $b['slug']));

        $truncated = count($plugins) > self::MAX_PLUGINS;
        if ($truncated) {
            $plugins = array_slice($plugins, 0, self::MAX_PLUGINS);
        }

        return [
            'plugins'   => $plugins,
            'truncated' => $truncated,
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd packages/connector-plugin && composer test -- --filter PluginListCollectorTest
```

Expected: PASS — all 4 assertions green.

- [ ] **Step 5: Commit**

```bash
git add packages/connector-plugin/src/SiteInfo/PluginListCollector.php \
        packages/connector-plugin/tests/Integration/SiteInfo/PluginListCollectorTest.php
git commit -m "feat(p2-1): PluginListCollector reads plugin inventory + update transient"
```

---

## Task 2 — `GET /defyn-connector/v1/plugins` endpoint

Thin controller that forwards to `PluginListCollector::collect()` and adds `server_time`. Route is registered behind the signature middleware in `RestRouter`.

**Files:**
- Create: `packages/connector-plugin/src/Rest/PluginsListController.php`
- Modify: `packages/connector-plugin/src/Rest/RestRouter.php`
- Test: `packages/connector-plugin/tests/Integration/Rest/PluginsListTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/connector-plugin/tests/Integration/Rest/PluginsListTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Rest;

use Defyn\Connector\Activation;
use Defyn\Connector\Crypto\Signer;
use Defyn\Connector\Storage\ConnectorState;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * P2.1 — GET /defyn-connector/v1/plugins (spec § 3.1).
 *
 * @group integration
 */
final class PluginsListTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        (new ConnectorState())->reset();
        Activation::activate();
        do_action('rest_api_init');
    }

    public function testUnsignedRequestReturns401(): void
    {
        // The middleware short-circuits to 404 connector.not_connected when
        // state != connected, BEFORE checking signature headers. To test the
        // signature gate itself (this test's purpose), establish a connected
        // state first — same pattern as VerifySignatureMiddlewareTest.
        $pubRaw = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
        (new ConnectorState())->update([
            'state'                => 'connected',
            'dashboard_public_key' => base64_encode($pubRaw),
            'connected_at'         => gmdate('c'),
        ]);

        $req = new WP_REST_Request('GET', '/defyn-connector/v1/plugins');
        $res = rest_do_request($req);
        self::assertSame(401, $res->get_status());
    }

    public function testSignedRequestReturnsPluginsPayload(): void
    {
        $kp      = sodium_crypto_sign_keypair();
        $privRaw = sodium_crypto_sign_secretkey($kp);
        $pubRaw  = sodium_crypto_sign_publickey($kp);

        $state = new ConnectorState();
        $state->update([
            'state'                => 'connected',
            'dashboard_public_key' => base64_encode($pubRaw),
            'connected_at'         => gmdate('c'),
        ]);

        $timestamp = (string) time();
        $nonce     = bin2hex(random_bytes(8));
        $canonical = Signer::canonical('GET', '/defyn-connector/v1/plugins', $timestamp, $nonce, '');
        $sig       = base64_encode(sodium_crypto_sign_detached($canonical, $privRaw));

        $req = new WP_REST_Request('GET', '/defyn-connector/v1/plugins');
        $req->set_header('X-Defyn-Timestamp', $timestamp);
        $req->set_header('X-Defyn-Nonce',     $nonce);
        $req->set_header('X-Defyn-Signature', $sig);

        $res  = rest_do_request($req);
        $body = $res->get_data();

        self::assertSame(200, $res->get_status());
        self::assertArrayHasKey('plugins',     $body);
        self::assertArrayHasKey('truncated',   $body);
        self::assertArrayHasKey('server_time', $body);
        self::assertIsInt($body['server_time']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd packages/connector-plugin && composer test -- --filter PluginsListTest
```

Expected: FAIL — `testSignedRequestReturnsPluginsPayload` returns 404.

- [ ] **Step 3: Write minimal implementation**

Create `packages/connector-plugin/src/Rest/PluginsListController.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Rest;

use Defyn\Connector\SiteInfo\PluginListCollector;
use WP_REST_Request;
use WP_REST_Response;

final class PluginsListController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $data                = (new PluginListCollector())->collect();
        $data['server_time'] = time();
        return new WP_REST_Response($data, 200);
    }
}
```

Modify `packages/connector-plugin/src/Rest/RestRouter.php` — add after the `/disconnect` route registration, before the closing brace of `register()`:

```php
        register_rest_route(self::NAMESPACE, '/plugins', [
            'methods'             => 'GET',
            'callback'            => [new PluginsListController(), 'handle'],
            'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
        ]);
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd packages/connector-plugin && composer test -- --filter PluginsListTest
```

Expected: PASS — both methods green.

- [ ] **Step 5: Commit**

```bash
git add packages/connector-plugin/src/Rest/PluginsListController.php \
        packages/connector-plugin/src/Rest/RestRouter.php \
        packages/connector-plugin/tests/Integration/Rest/PluginsListTest.php
git commit -m "feat(p2-1): GET /defyn-connector/v1/plugins signed endpoint"
```

---

## Task 3 — `POST /defyn-connector/v1/plugins/refresh` endpoint

Forces a fresh `wp_update_plugins()` poll, then returns the same payload as `GET /plugins`. Wraps in try/catch and returns a `connector.refresh_failed` envelope on failure.

**Files:**
- Create: `packages/connector-plugin/src/Rest/PluginsRefreshController.php`
- Modify: `packages/connector-plugin/src/Rest/RestRouter.php`
- Test: `packages/connector-plugin/tests/Integration/Rest/PluginsRefreshTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/connector-plugin/tests/Integration/Rest/PluginsRefreshTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Rest;

use Defyn\Connector\Activation;
use Defyn\Connector\Crypto\Signer;
use Defyn\Connector\Storage\ConnectorState;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * P2.1 — POST /defyn-connector/v1/plugins/refresh (spec § 3.2).
 *
 * @group integration
 */
final class PluginsRefreshTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        (new ConnectorState())->reset();
        Activation::activate();
        do_action('rest_api_init');
    }

    public function testUnsignedRequestReturns401(): void
    {
        // Establish connected state first so the middleware reaches the
        // signature-headers check (same pattern as Task 2 + VerifySignatureMiddlewareTest).
        $pubRaw = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
        (new ConnectorState())->update([
            'state'                => 'connected',
            'dashboard_public_key' => base64_encode($pubRaw),
            'connected_at'         => gmdate('c'),
        ]);

        $req = new WP_REST_Request('POST', '/defyn-connector/v1/plugins/refresh');
        $res = rest_do_request($req);
        self::assertSame(401, $res->get_status());
    }

    public function testSignedRequestForcesUpdateCheckAndReturnsPayload(): void
    {
        $kp      = sodium_crypto_sign_keypair();
        $privRaw = sodium_crypto_sign_secretkey($kp);
        $pubRaw  = sodium_crypto_sign_publickey($kp);

        (new ConnectorState())->update([
            'state'                => 'connected',
            'dashboard_public_key' => base64_encode($pubRaw),
            'connected_at'         => gmdate('c'),
        ]);

        delete_site_transient('update_plugins');

        $timestamp = (string) time();
        $nonce     = bin2hex(random_bytes(8));
        $canonical = Signer::canonical('POST', '/defyn-connector/v1/plugins/refresh', $timestamp, $nonce, '');
        $sig       = base64_encode(sodium_crypto_sign_detached($canonical, $privRaw));

        $req = new WP_REST_Request('POST', '/defyn-connector/v1/plugins/refresh');
        $req->set_header('X-Defyn-Timestamp', $timestamp);
        $req->set_header('X-Defyn-Nonce',     $nonce);
        $req->set_header('X-Defyn-Signature', $sig);

        $res  = rest_do_request($req);
        $body = $res->get_data();

        self::assertSame(200, $res->get_status());
        self::assertArrayHasKey('plugins',     $body);
        self::assertArrayHasKey('truncated',   $body);
        self::assertArrayHasKey('server_time', $body);

        $after = get_site_transient('update_plugins');
        self::assertNotFalse($after, 'wp_update_plugins() should have populated the transient');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd packages/connector-plugin && composer test -- --filter PluginsRefreshTest
```

Expected: FAIL — 404.

- [ ] **Step 3: Write minimal implementation**

Create `packages/connector-plugin/src/Rest/PluginsRefreshController.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Rest;

use Defyn\Connector\Rest\Responses\ErrorResponse;
use Defyn\Connector\SiteInfo\PluginListCollector;
use WP_REST_Request;
use WP_REST_Response;

final class PluginsRefreshController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        if (!function_exists('wp_update_plugins')) {
            require_once ABSPATH . 'wp-includes/update.php';
        }

        if (!function_exists('wp_update_plugins')) {
            return ErrorResponse::create(
                502,
                'connector.refresh_failed',
                'WP update subsystem unavailable on this site.'
            );
        }

        try {
            wp_update_plugins();
        } catch (\Throwable $e) {
            return ErrorResponse::create(
                502,
                'connector.refresh_failed',
                'wp_update_plugins() failed: ' . $e->getMessage()
            );
        }

        $data                = (new PluginListCollector())->collect();
        $data['server_time'] = time();
        return new WP_REST_Response($data, 200);
    }
}
```

Modify `packages/connector-plugin/src/Rest/RestRouter.php` — register the second route right after `/plugins`:

```php
        register_rest_route(self::NAMESPACE, '/plugins/refresh', [
            'methods'             => 'POST',
            'callback'            => [new PluginsRefreshController(), 'handle'],
            'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
        ]);
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd packages/connector-plugin && composer test -- --filter PluginsRefreshTest
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/connector-plugin/src/Rest/PluginsRefreshController.php \
        packages/connector-plugin/src/Rest/RestRouter.php \
        packages/connector-plugin/tests/Integration/Rest/PluginsRefreshTest.php
git commit -m "feat(p2-1): POST /defyn-connector/v1/plugins/refresh signed endpoint"
```

---

## Task 4 — Connector v0.1.3 version bump + cache-headers regression test

Bumps connector to v0.1.3. Adds a regression test that locks in `Cache-Control: no-store` on the two new routes (would have caught the v0.1.2 Batcache bug).

**Files:**
- Modify: `packages/connector-plugin/defyn-connector.php`
- Modify: `packages/connector-plugin/readme.txt`
- Create: `packages/connector-plugin/tests/Integration/Rest/PluginsCacheHeadersTest.php`

- [ ] **Step 1: Write the regression test**

Create `packages/connector-plugin/tests/Integration/Rest/PluginsCacheHeadersTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Rest;

use Defyn\Connector\Activation;
use Defyn\Connector\Crypto\Signer;
use Defyn\Connector\Storage\ConnectorState;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * P2.1 — both new endpoints ship Cache-Control: no-store via the v0.1.2
 * applyNoCacheHeaders filter. Regression guard against the WP.com Batcache
 * issue that produced stale 404 responses on /status during P2.1 discovery.
 *
 * @group integration
 */
final class PluginsCacheHeadersTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        (new ConnectorState())->reset();
        Activation::activate();
        do_action('rest_api_init');
    }

    public function testGetPluginsShipsNoStoreHeader(): void
    {
        $headers = $this->signedRequestHeaders('GET', '/defyn-connector/v1/plugins');
        self::assertStringContainsString('no-store', strtolower($headers['cache-control'] ?? ''));
    }

    public function testPostPluginsRefreshShipsNoStoreHeader(): void
    {
        $headers = $this->signedRequestHeaders('POST', '/defyn-connector/v1/plugins/refresh');
        self::assertStringContainsString('no-store', strtolower($headers['cache-control'] ?? ''));
    }

    /**
     * @return array<string, string>
     */
    private function signedRequestHeaders(string $method, string $route): array
    {
        $kp      = sodium_crypto_sign_keypair();
        $privRaw = sodium_crypto_sign_secretkey($kp);
        $pubRaw  = sodium_crypto_sign_publickey($kp);

        (new ConnectorState())->update([
            'state'                => 'connected',
            'dashboard_public_key' => base64_encode($pubRaw),
            'connected_at'         => gmdate('c'),
        ]);

        $timestamp = (string) time();
        $nonce     = bin2hex(random_bytes(8));
        $canonical = Signer::canonical($method, $route, $timestamp, $nonce, '');
        $sig       = base64_encode(sodium_crypto_sign_detached($canonical, $privRaw));

        $req = new WP_REST_Request($method, $route);
        $req->set_header('X-Defyn-Timestamp', $timestamp);
        $req->set_header('X-Defyn-Nonce',     $nonce);
        $req->set_header('X-Defyn-Signature', $sig);

        // rest_do_request() calls WP_REST_Server::dispatch() directly, which
        // skips the rest_post_dispatch filter pipeline that applyNoCacheHeaders
        // is hooked on. Invoke the filter manually here to exercise the same
        // code path production HTTP traffic uses via serve_request().
        $res = rest_do_request($req);
        $res = apply_filters('rest_post_dispatch', $res, rest_get_server(), $req);
        return array_change_key_case($res->get_headers(), CASE_LOWER);
    }
}
```

- [ ] **Step 2: Run it — should PASS already (v0.1.2 filter covers all routes)**

```bash
cd packages/connector-plugin && composer test -- --filter PluginsCacheHeadersTest
```

Expected: PASS. If FAIL, the `applyNoCacheHeaders` filter has been broken — fix that before continuing.

- [ ] **Step 3: Bump version + readme**

Modify `packages/connector-plugin/defyn-connector.php`:

```php
 * Version:           0.1.3
```

Modify `packages/connector-plugin/readme.txt`:

```
Stable tag: 0.1.3
```

Add a changelog entry at the top of `== Changelog ==`:

```
= 0.1.3 =
* Feature: new `/plugins` (GET) and `/plugins/refresh` (POST) signed endpoints expose the site's plugin inventory + update-available flags. Lays the read foundation for dashboard-driven plugin management (P2.1).
```

- [ ] **Step 4: Re-run full connector suite**

```bash
cd packages/connector-plugin && composer test
```

Expected: PASS (existing tests + Tasks 1-4 tests).

- [ ] **Step 5: Commit**

```bash
git add packages/connector-plugin/defyn-connector.php \
        packages/connector-plugin/readme.txt \
        packages/connector-plugin/tests/Integration/Rest/PluginsCacheHeadersTest.php
git commit -m "chore(p2-1): connector v0.1.3 — plugin inventory endpoints"
```

---

## Task 5 — Dashboard `SchemaVersion` + `SitePluginsTable`

Adds the version-option machinery (`defyn_schema_version`, default `1`) plus the new sibling table. dbDelta runs in `Activation::activate` (wired in Task 12).

**Files:**
- Create: `packages/dashboard-plugin/src/Schema/SchemaVersion.php`
- Create: `packages/dashboard-plugin/src/Schema/SitePluginsTable.php`
- Test: `packages/dashboard-plugin/tests/Integration/Schema/SchemaVersionTest.php`
- Test: `packages/dashboard-plugin/tests/Integration/Schema/SitePluginsTableTest.php`
- Modify: `packages/dashboard-plugin/tests/Integration/AbstractSchemaTestCase.php` (add `defyn_site_plugins` to the switch)

- [ ] **Step 1: Write the failing tests**

Create `packages/dashboard-plugin/tests/Integration/Schema/SchemaVersionTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Schema;

use Defyn\Dashboard\Schema\SchemaVersion;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * @group integration
 */
final class SchemaVersionTest extends AbstractSchemaTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        delete_option(SchemaVersion::OPTION);
    }

    public function testCurrentReturnsOneWhenOptionAbsent(): void
    {
        self::assertSame(1, SchemaVersion::current());
    }

    public function testSetPersistsVersion(): void
    {
        SchemaVersion::set(2);
        self::assertSame(2, SchemaVersion::current());
    }

    public function testNeedsMigrationToTrueWhenBelowTarget(): void
    {
        SchemaVersion::set(1);
        self::assertTrue(SchemaVersion::needsMigrationTo(2));
        SchemaVersion::set(2);
        self::assertFalse(SchemaVersion::needsMigrationTo(2));
    }
}
```

Create `packages/dashboard-plugin/tests/Integration/Schema/SitePluginsTableTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Schema;

use Defyn\Dashboard\Schema\SitePluginsTable;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * @group integration
 */
final class SitePluginsTableTest extends AbstractSchemaTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_site_plugins');
    }

    public function testTableExists(): void
    {
        global $wpdb;
        $name = SitePluginsTable::tableName();
        $row  = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $name));
        self::assertSame($name, $row);
    }

    public function testUniqueIndexOnSiteIdAndSlug(): void
    {
        global $wpdb;
        $name    = SitePluginsTable::tableName();
        $indexes = $wpdb->get_results("SHOW INDEX FROM {$name}", ARRAY_A);

        $siteSlug = array_filter(
            $indexes,
            static fn(array $i): bool => $i['Key_name'] === 'site_slug'
        );
        self::assertNotEmpty($siteSlug, 'expected UNIQUE KEY site_slug');
        foreach ($siteSlug as $row) {
            self::assertSame('0', (string) $row['Non_unique'], 'site_slug must be UNIQUE');
        }
    }

    public function testUpdateAvailableIndexExists(): void
    {
        global $wpdb;
        $name    = SitePluginsTable::tableName();
        $indexes = $wpdb->get_results("SHOW INDEX FROM {$name}", ARRAY_A);

        $hits = array_filter(
            $indexes,
            static fn(array $i): bool => $i['Key_name'] === 'update_available'
        );
        self::assertNotEmpty($hits, 'expected KEY update_available for fleet queries');
    }
}
```

**Extend `AbstractSchemaTestCase::freshlyActivate`** with a transitional dbDelta for `defyn_site_plugins`. The existing method just drops the named table + reruns `Activation::activate()` — but `SitePluginsTable` isn't in `Activation::TABLES` until Task 12. Add an `if` branch so the table is created directly during the transitional period. Modify `packages/dashboard-plugin/tests/Integration/AbstractSchemaTestCase.php` — current method (lines 75-84):

```php
protected function freshlyActivate(string $unprefixedTableName): void
{
    global $wpdb;
    $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}{$unprefixedTableName}`");
    delete_option(Activation::SCHEMA_OPTION);
    Activation::activate();
}
```

Becomes:

```php
protected function freshlyActivate(string $unprefixedTableName): void
{
    global $wpdb;
    $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}{$unprefixedTableName}`");
    delete_option(Activation::SCHEMA_OPTION);
    Activation::activate();

    // P2.1: SitePluginsTable joins Activation::TABLES in Task 12. Until then,
    // create it directly so tests using freshlyActivate('defyn_site_plugins') work.
    // After Task 12 this becomes harmless (dbDelta is idempotent).
    if ($unprefixedTableName === 'defyn_site_plugins') {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta(\Defyn\Dashboard\Schema\SitePluginsTable::createSql());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
cd packages/dashboard-plugin && composer test -- --filter SchemaVersionTest
cd packages/dashboard-plugin && composer test -- --filter SitePluginsTableTest
```

Expected: FAIL — classes not found.

- [ ] **Step 3: Write minimal implementations**

Create `packages/dashboard-plugin/src/Schema/SchemaVersion.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Schema;

/**
 * P2.1 — drives idempotent dashboard schema migrations.
 *
 * Foundation (F1-F10) implicitly = version 1. P2.1 bumps to 2.
 *
 * Reuses the existing Activation::SCHEMA_OPTION literal so Activation
 * and SchemaVersion read/write the same option — one source of truth.
 */
final class SchemaVersion
{
    /** Same literal as Activation::SCHEMA_OPTION (one option, two callers). */
    public const OPTION = 'defyn_dashboard_schema_version';

    public static function current(): int
    {
        $value = get_option(self::OPTION, 1);
        return (int) $value;
    }

    public static function set(int $version): void
    {
        update_option(self::OPTION, $version, false);
    }

    public static function needsMigrationTo(int $target): bool
    {
        return self::current() < $target;
    }
}
```

Create `packages/dashboard-plugin/src/Schema/SitePluginsTable.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Schema;

/**
 * P2.1 — wp_defyn_site_plugins (spec § 5.1).
 */
final class SitePluginsTable implements SchemaTable
{
    public static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'defyn_site_plugins';
    }

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
            last_seen_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY site_slug (site_id, slug),
            KEY update_available (update_available),
            KEY site_id (site_id)
        ) {$charset};";
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
cd packages/dashboard-plugin && composer test -- --filter SchemaVersionTest
cd packages/dashboard-plugin && composer test -- --filter SitePluginsTableTest
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Schema/SchemaVersion.php \
        packages/dashboard-plugin/src/Schema/SitePluginsTable.php \
        packages/dashboard-plugin/tests/Integration/Schema/SchemaVersionTest.php \
        packages/dashboard-plugin/tests/Integration/Schema/SitePluginsTableTest.php \
        packages/dashboard-plugin/tests/Integration/AbstractSchemaTestCase.php
git commit -m "feat(p2-1): SchemaVersion option + wp_defyn_site_plugins table"
```

---

## Task 6 — `Plugin` value object + `SitePluginsRepository`

Immutable model + repository methods for read + delta write.

**Files:**
- Create: `packages/dashboard-plugin/src/Models/Plugin.php`
- Create: `packages/dashboard-plugin/src/Services/SitePluginsRepository.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/SitePluginsRepositoryTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/dashboard-plugin/tests/Integration/Services/SitePluginsRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Schema\SitePluginsTable;
use Defyn\Dashboard\Services\SitePluginsRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * @group integration
 */
final class SitePluginsRepositoryTest extends AbstractSchemaTestCase
{
    private SitePluginsRepository $repo;

    public function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_site_plugins');
        global $wpdb;
        $wpdb->query('TRUNCATE ' . SitePluginsTable::tableName());
        $this->repo = new SitePluginsRepository();
    }

    public function testFindAllForSiteReturnsEmptyArrayWhenNoRows(): void
    {
        self::assertSame([], $this->repo->findAllForSite(99));
    }

    public function testReplaceForSiteInsertsRows(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->repo->replaceForSite(1, [
            ['slug' => 'akismet/akismet.php',    'name' => 'Akismet',     'version' => '5.3.1',  'update_available' => true,  'update_version' => '5.3.5'],
            ['slug' => 'rank-math/rank-math.php','name' => 'Rank Math',   'version' => '1.0.234','update_available' => false, 'update_version' => null],
        ], $now);

        $rows = $this->repo->findAllForSite(1);
        self::assertCount(2, $rows);
        self::assertSame('akismet/akismet.php', $rows[0]->slug);
        self::assertSame('Akismet',             $rows[0]->name);
        self::assertSame('5.3.1',               $rows[0]->version);
        self::assertTrue($rows[0]->updateAvailable);
        self::assertSame('5.3.5',               $rows[0]->updateVersion);
    }

    public function testReplaceForSiteDeletesMissingRows(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->repo->replaceForSite(1, [
            ['slug' => 'a.php', 'name' => 'A', 'version' => '1.0', 'update_available' => false, 'update_version' => null],
            ['slug' => 'b.php', 'name' => 'B', 'version' => '2.0', 'update_available' => false, 'update_version' => null],
        ], $now);
        $this->repo->replaceForSite(1, [
            ['slug' => 'a.php', 'name' => 'A', 'version' => '1.0', 'update_available' => false, 'update_version' => null],
        ], $now);

        $slugs = array_map(static fn($p) => $p->slug, $this->repo->findAllForSite(1));
        self::assertSame(['a.php'], $slugs);
    }

    public function testReplaceForSiteUpdatesChangedRows(): void
    {
        $t1 = gmdate('Y-m-d H:i:s', time() - 60);
        $t2 = gmdate('Y-m-d H:i:s');

        $this->repo->replaceForSite(1, [
            ['slug' => 'a.php', 'name' => 'A', 'version' => '1.0', 'update_available' => false, 'update_version' => null],
        ], $t1);

        $this->repo->replaceForSite(1, [
            ['slug' => 'a.php', 'name' => 'A', 'version' => '1.1', 'update_available' => true, 'update_version' => '1.2'],
        ], $t2);

        $rows = $this->repo->findAllForSite(1);
        self::assertSame('1.1',  $rows[0]->version);
        self::assertTrue($rows[0]->updateAvailable);
        self::assertSame('1.2',  $rows[0]->updateVersion);
    }

    public function testLastSyncedAtForSiteReturnsMaxLastSeenAt(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->repo->replaceForSite(1, [
            ['slug' => 'a.php', 'name' => 'A', 'version' => '1.0', 'update_available' => false, 'update_version' => null],
        ], $now);
        self::assertSame($now, $this->repo->lastSyncedAtForSite(1));
        self::assertNull($this->repo->lastSyncedAtForSite(99));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd packages/dashboard-plugin && composer test -- --filter SitePluginsRepositoryTest
```

Expected: FAIL.

- [ ] **Step 3: Write minimal implementations**

Create `packages/dashboard-plugin/src/Models/Plugin.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Models;

final class Plugin
{
    public function __construct(
        public readonly int    $id,
        public readonly int    $siteId,
        public readonly string $slug,
        public readonly string $name,
        public readonly ?string $version,
        public readonly bool   $updateAvailable,
        public readonly ?string $updateVersion,
        public readonly string $lastSeenAt,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:              (int) $row['id'],
            siteId:          (int) $row['site_id'],
            slug:            (string) $row['slug'],
            name:            (string) $row['name'],
            version:         isset($row['version']) ? (string) $row['version'] : null,
            updateAvailable: (bool) (int) ($row['update_available'] ?? 0),
            updateVersion:   isset($row['update_version']) ? (string) $row['update_version'] : null,
            lastSeenAt:      (string) $row['last_seen_at'],
            createdAt:       (string) $row['created_at'],
            updatedAt:       (string) $row['updated_at'],
        );
    }

    /** @return array<string, mixed> */
    public function toJson(): array
    {
        return [
            'slug'             => $this->slug,
            'name'             => $this->name,
            'version'          => $this->version,
            'update_available' => $this->updateAvailable,
            'update_version'   => $this->updateVersion,
        ];
    }
}
```

Create `packages/dashboard-plugin/src/Services/SitePluginsRepository.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Models\Plugin;
use Defyn\Dashboard\Schema\SitePluginsTable;

/**
 * P2.1 — wp_defyn_site_plugins read + delta write (spec § 6.3, § 7.1).
 */
final class SitePluginsRepository
{
    /** @return list<Plugin> */
    public function findAllForSite(int $siteId): array
    {
        global $wpdb;
        $table = SitePluginsTable::tableName();
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE site_id = %d ORDER BY slug ASC",
                $siteId
            ),
            ARRAY_A,
        );
        return array_map([Plugin::class, 'fromRow'], $rows ?: []);
    }

    public function lastSyncedAtForSite(int $siteId): ?string
    {
        global $wpdb;
        $table = SitePluginsTable::tableName();
        $row   = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT MAX(last_seen_at) FROM {$table} WHERE site_id = %d",
                $siteId
            ),
        );
        return $row !== null ? (string) $row : null;
    }

    /**
     * @param list<array{slug:string,name:string,version:?string,update_available:bool,update_version:?string}> $incoming
     */
    public function replaceForSite(int $siteId, array $incoming, string $now): void
    {
        global $wpdb;
        $table = SitePluginsTable::tableName();

        $existingRows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT slug, name, version, update_available, update_version
                 FROM {$table} WHERE site_id = %d",
                $siteId
            ),
            ARRAY_A,
        );
        $existingBySlug = [];
        foreach ($existingRows ?: [] as $r) {
            $existingBySlug[$r['slug']] = $r;
        }

        $incomingSlugs = array_column($incoming, 'slug');

        $wpdb->query('START TRANSACTION');
        try {
            foreach ($incoming as $p) {
                $slug    = (string) $p['slug'];
                $present = $existingBySlug[$slug] ?? null;

                if ($present === null) {
                    $wpdb->insert(
                        $table,
                        [
                            'site_id'          => $siteId,
                            'slug'             => $slug,
                            'name'             => $p['name'],
                            'version'          => $p['version'],
                            'update_available' => $p['update_available'] ? 1 : 0,
                            'update_version'   => $p['update_version'],
                            'last_seen_at'     => $now,
                            'created_at'       => $now,
                            'updated_at'       => $now,
                        ],
                        ['%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s'],
                    );
                    continue;
                }

                $hasChanged = (
                    $present['name']                 !== $p['name']           ||
                    $present['version']              !== $p['version']        ||
                    ((int) $present['update_available']) !== ($p['update_available'] ? 1 : 0) ||
                    $present['update_version']       !== $p['update_version']
                );

                if ($hasChanged) {
                    $wpdb->update(
                        $table,
                        [
                            'name'             => $p['name'],
                            'version'          => $p['version'],
                            'update_available' => $p['update_available'] ? 1 : 0,
                            'update_version'   => $p['update_version'],
                            'last_seen_at'     => $now,
                            'updated_at'       => $now,
                        ],
                        ['site_id' => $siteId, 'slug' => $slug],
                        ['%s', '%s', '%d', '%s', '%s', '%s'],
                        ['%d', '%s'],
                    );
                } else {
                    $wpdb->update(
                        $table,
                        ['last_seen_at' => $now],
                        ['site_id' => $siteId, 'slug' => $slug],
                        ['%s'],
                        ['%d', '%s'],
                    );
                }
            }

            $toDelete = array_diff(array_keys($existingBySlug), $incomingSlugs);
            foreach ($toDelete as $slug) {
                $wpdb->delete(
                    $table,
                    ['site_id' => $siteId, 'slug' => $slug],
                    ['%d', '%s'],
                );
            }

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd packages/dashboard-plugin && composer test -- --filter SitePluginsRepositoryTest
```

Expected: PASS — all 5 methods green.

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Models/Plugin.php \
        packages/dashboard-plugin/src/Services/SitePluginsRepository.php \
        packages/dashboard-plugin/tests/Integration/Services/SitePluginsRepositoryTest.php
git commit -m "feat(p2-1): Plugin model + SitePluginsRepository with delta replaceForSite"
```

---

## Task 7 — `SyncPluginsService`

Orchestrator: takes connector payload → `replaceForSite` → log `plugin_inventory.synced`.

**Files:**
- Create: `packages/dashboard-plugin/src/Services/SyncPluginsService.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/SyncPluginsServiceTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/dashboard-plugin/tests/Integration/Services/SyncPluginsServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Schema\ActivityLogTable;
use Defyn\Dashboard\Schema\SitePluginsTable;
use Defyn\Dashboard\Services\SitePluginsRepository;
use Defyn\Dashboard\Services\SyncPluginsService;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * @group integration
 */
final class SyncPluginsServiceTest extends AbstractSchemaTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_site_plugins');
        $this->freshlyActivate('defyn_activity_log');
        global $wpdb;
        $wpdb->query('TRUNCATE ' . SitePluginsTable::tableName());
        $wpdb->query('TRUNCATE ' . ActivityLogTable::tableName());
    }

    public function testSyncPersistsPluginsAndLogsEvent(): void
    {
        (new SyncPluginsService())->sync(1, [
            'plugins' => [
                ['slug' => 'a.php', 'name' => 'A', 'version' => '1.0', 'update_available' => true,  'update_version' => '1.1'],
                ['slug' => 'b.php', 'name' => 'B', 'version' => '2.0', 'update_available' => false, 'update_version' => null],
            ],
        ], 'background');

        $rows = (new SitePluginsRepository())->findAllForSite(1);
        self::assertCount(2, $rows);

        global $wpdb;
        $events = $wpdb->get_results(
            'SELECT event_type, details FROM ' . ActivityLogTable::tableName() . ' ORDER BY id DESC',
            ARRAY_A,
        );
        self::assertCount(1, $events);
        self::assertSame('plugin_inventory.synced', $events[0]['event_type']);
        $details = json_decode((string) $events[0]['details'], true);
        self::assertSame(2,            $details['plugin_count']);
        self::assertSame(1,            $details['updates_available_count']);
        self::assertSame('background', $details['source']);
    }

    public function testSyncWithEmptyPluginsListClearsRowsAndLogsZero(): void
    {
        (new SyncPluginsService())->sync(1, [
            'plugins' => [['slug' => 'a.php', 'name' => 'A', 'version' => '1', 'update_available' => false, 'update_version' => null]],
        ], 'background');

        (new SyncPluginsService())->sync(1, ['plugins' => []], 'refresh');

        $rows = (new SitePluginsRepository())->findAllForSite(1);
        self::assertSame([], $rows);

        global $wpdb;
        $latest = $wpdb->get_row(
            'SELECT event_type, details FROM ' . ActivityLogTable::tableName() . ' ORDER BY id DESC LIMIT 1',
            ARRAY_A,
        );
        $details = json_decode((string) $latest['details'], true);
        self::assertSame(0,         $details['plugin_count']);
        self::assertSame(0,         $details['updates_available_count']);
        self::assertSame('refresh', $details['source']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd packages/dashboard-plugin && composer test -- --filter SyncPluginsServiceTest
```

Expected: FAIL — class not found.

- [ ] **Step 3: Write minimal implementation**

Create `packages/dashboard-plugin/src/Services/SyncPluginsService.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

/**
 * P2.1 — runs delta sync + writes the plugin_inventory.synced activity event.
 */
final class SyncPluginsService
{
    public function __construct(
        private readonly SitePluginsRepository $repo = new SitePluginsRepository(),
        private readonly ActivityLogger $log = new ActivityLogger(),
    ) {
    }

    /**
     * @param array{plugins?: list<array{slug:string,name:string,version:?string,update_available:bool,update_version:?string}>} $payload
     * @param 'background'|'refresh' $source
     */
    public function sync(int $siteId, array $payload, string $source): void
    {
        $incoming = $payload['plugins'] ?? [];
        $now      = gmdate('Y-m-d H:i:s');

        $this->repo->replaceForSite($siteId, $incoming, $now);

        $updatesAvailable = 0;
        foreach ($incoming as $p) {
            if (!empty($p['update_available'])) {
                $updatesAvailable++;
            }
        }

        $this->log->log(null, $siteId, 'plugin_inventory.synced', [
            'plugin_count'            => count($incoming),
            'updates_available_count' => $updatesAvailable,
            'source'                  => $source,
        ]);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd packages/dashboard-plugin && composer test -- --filter SyncPluginsServiceTest
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Services/SyncPluginsService.php \
        packages/dashboard-plugin/tests/Integration/Services/SyncPluginsServiceTest.php
git commit -m "feat(p2-1): SyncPluginsService — delta sync + activity log event"
```

---

## Task 8 — `RefreshSitePlugins` AS job

Calls connector's signed `POST /plugins/refresh`, hands the response to `SyncPluginsService::sync(..., 'refresh')`. Logs `plugin_inventory.sync_failed` with `source: refresh` on connector error.

**Files:**
- Create: `packages/dashboard-plugin/src/Jobs/RefreshSitePlugins.php`
- Test: `packages/dashboard-plugin/tests/Integration/Jobs/RefreshSitePluginsTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/dashboard-plugin/tests/Integration/Jobs/RefreshSitePluginsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Jobs;

use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Jobs\RefreshSitePlugins;
use Defyn\Dashboard\Schema\ActivityLogTable;
use Defyn\Dashboard\Schema\SitePluginsTable;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * @group integration
 */
final class RefreshSitePluginsTest extends AbstractSchemaTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_sites');
        $this->freshlyActivate('defyn_site_plugins');
        $this->freshlyActivate('defyn_activity_log');
        global $wpdb;
        $wpdb->query('TRUNCATE ' . SitesTable::tableName());
        $wpdb->query('TRUNCATE ' . SitePluginsTable::tableName());
        $wpdb->query('TRUNCATE ' . ActivityLogTable::tableName());
    }

    public function testJobCallsConnectorAndPersistsPayload(): void
    {
        $sites = new SitesRepository();
        $id = $sites->insertPending(1, 'https://demo.test', 'Demo', base64_encode(random_bytes(32)), 'cipher');
        $sites->markActive($id, base64_encode(random_bytes(32)));

        $stubClient = new class extends SignedHttpClient {
            public function __construct() {}
            public function signedPostJson(
                \Defyn\Dashboard\Models\Site $site,
                string $path,
                array $body,
            ): array {
                return [
                    'ok'   => true,
                    'data' => [
                        'plugins' => [
                            ['slug' => 'a.php', 'name' => 'A', 'version' => '1.0', 'update_available' => true, 'update_version' => '1.1'],
                        ],
                        'truncated'   => false,
                        'server_time' => time(),
                    ],
                ];
            }
        };

        (new RefreshSitePlugins(httpClient: $stubClient))->handle($id);

        global $wpdb;
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . SitePluginsTable::tableName() . ' WHERE site_id = %d',
                $id
            )
        );
        self::assertSame(1, $count);

        $synced = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM " . ActivityLogTable::tableName() .
            " WHERE event_type = 'plugin_inventory.synced'"
        );
        self::assertSame(1, $synced);
    }

    public function testJobLogsSyncFailedOnConnectorError(): void
    {
        $sites = new SitesRepository();
        $id = $sites->insertPending(1, 'https://demo.test', 'Demo', base64_encode(random_bytes(32)), 'cipher');
        $sites->markActive($id, base64_encode(random_bytes(32)));

        $stubClient = new class extends SignedHttpClient {
            public function __construct() {}
            public function signedPostJson(
                \Defyn\Dashboard\Models\Site $site,
                string $path,
                array $body,
            ): array {
                return [
                    'ok'           => false,
                    'status'       => 502,
                    'errorCode'    => 'connector.refresh_failed',
                    'errorMessage' => 'wp_update_plugins() failed: timeout',
                ];
            }
        };

        (new RefreshSitePlugins(httpClient: $stubClient))->handle($id);

        global $wpdb;
        $failed = $wpdb->get_row(
            "SELECT details FROM " . ActivityLogTable::tableName() .
            " WHERE event_type = 'plugin_inventory.sync_failed' ORDER BY id DESC LIMIT 1",
            ARRAY_A,
        );
        self::assertNotNull($failed);
        $details = json_decode((string) $failed['details'], true);
        self::assertSame('refresh', $details['source']);
        self::assertStringContainsString('timeout', $details['error']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd packages/dashboard-plugin && composer test -- --filter RefreshSitePluginsTest
```

Expected: FAIL — class not found.

- [ ] **Step 3: Write minimal implementation**

Create `packages/dashboard-plugin/src/Jobs/RefreshSitePlugins.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Services\SyncPluginsService;

final class RefreshSitePlugins
{
    public function __construct(
        private readonly SitesRepository $repo = new SitesRepository(),
        private readonly SignedHttpClient $httpClient = new SignedHttpClient(),
        private readonly SyncPluginsService $syncService = new SyncPluginsService(),
        private readonly ActivityLogger $log = new ActivityLogger(),
    ) {
    }

    public function handle(int $siteId): void
    {
        $site = $this->repo->findById($siteId);
        if ($site === null || $site->status === 'pending') {
            return;
        }

        $response = $this->httpClient->signedPostJson($site, '/plugins/refresh', []);

        if (!($response['ok'] ?? false)) {
            $this->log->log(null, $siteId, 'plugin_inventory.sync_failed', [
                'error'  => (string) ($response['errorMessage'] ?? 'unknown'),
                'source' => 'refresh',
            ]);
            return;
        }

        $this->syncService->sync($siteId, (array) $response['data'], 'refresh');
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd packages/dashboard-plugin && composer test -- --filter RefreshSitePluginsTest
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Jobs/RefreshSitePlugins.php \
        packages/dashboard-plugin/tests/Integration/Jobs/RefreshSitePluginsTest.php
git commit -m "feat(p2-1): RefreshSitePlugins AS job"
```

---

## Task 9 — Extend `SyncSite` to also pull `/plugins` after `/status`

A `/plugins` failure does NOT mark the site as error — only logs `plugin_inventory.sync_failed`. A `rest.route_not_found` (connector predates v0.1.3) gets the `connector_below_v0.1.3` error string.

**Files:**
- Modify: `packages/dashboard-plugin/src/Jobs/SyncSite.php`
- Test: `packages/dashboard-plugin/tests/Integration/Jobs/SyncSitePluginsIntegrationTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/dashboard-plugin/tests/Integration/Jobs/SyncSitePluginsIntegrationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Jobs;

use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Jobs\SyncSite;
use Defyn\Dashboard\Schema\ActivityLogTable;
use Defyn\Dashboard\Schema\SitePluginsTable;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * @group integration
 */
final class SyncSitePluginsIntegrationTest extends AbstractSchemaTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_sites');
        $this->freshlyActivate('defyn_site_plugins');
        $this->freshlyActivate('defyn_activity_log');
        global $wpdb;
        $wpdb->query('TRUNCATE ' . SitesTable::tableName());
        $wpdb->query('TRUNCATE ' . SitePluginsTable::tableName());
        $wpdb->query('TRUNCATE ' . ActivityLogTable::tableName());
    }

    public function testStatusSyncAlsoPullsPluginsList(): void
    {
        $sites = new SitesRepository();
        $id = $sites->insertPending(1, 'https://demo.test', 'Demo', base64_encode(random_bytes(32)), 'cipher');
        $sites->markActive($id, base64_encode(random_bytes(32)));

        $stub = new class extends SignedHttpClient {
            public function __construct() {}
            public function signedGet(\Defyn\Dashboard\Models\Site $site, string $path): array {
                if ($path === '/status') {
                    return [
                        'ok'   => true,
                        'data' => [
                            'wp_version'    => '6.5.0',
                            'php_version'   => '8.2.0',
                            'active_theme'  => ['name' => 'T', 'version' => '1', 'parent' => null],
                            'plugin_counts' => ['installed' => 1, 'active' => 1],
                            'theme_counts'  => ['installed' => 1, 'active' => 1],
                            'ssl_status'    => 'enabled',
                            'ssl_expires_at'=> null,
                            'server_time'   => time(),
                        ],
                    ];
                }
                if ($path === '/plugins') {
                    return [
                        'ok'   => true,
                        'data' => [
                            'plugins' => [
                                ['slug' => 'a.php', 'name' => 'A', 'version' => '1', 'update_available' => false, 'update_version' => null],
                            ],
                            'truncated'   => false,
                            'server_time' => time(),
                        ],
                    ];
                }
                return ['ok' => false, 'status' => 404, 'errorCode' => 'rest.route_not_found', 'errorMessage' => 'no route'];
            }
        };

        (new SyncSite(httpClient: $stub))->handle($id);

        global $wpdb;
        $rows = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . SitePluginsTable::tableName() . ' WHERE site_id = %d',
                $id
            )
        );
        self::assertSame(1, $rows);

        $synced = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM " . ActivityLogTable::tableName() .
            " WHERE event_type = 'plugin_inventory.synced'"
        );
        self::assertSame(1, $synced);
    }

    public function testPluginsFailureDoesNotMarkSiteAsError(): void
    {
        $sites = new SitesRepository();
        $id = $sites->insertPending(1, 'https://demo.test', 'Demo', base64_encode(random_bytes(32)), 'cipher');
        $sites->markActive($id, base64_encode(random_bytes(32)));

        $stub = new class extends SignedHttpClient {
            public function __construct() {}
            public function signedGet(\Defyn\Dashboard\Models\Site $site, string $path): array {
                if ($path === '/status') {
                    return ['ok' => true, 'data' => ['wp_version' => '6.5.0', 'php_version' => '8.2', 'active_theme' => ['name'=>'T','version'=>'1','parent'=>null], 'plugin_counts'=>['installed'=>0,'active'=>0],'theme_counts'=>['installed'=>1,'active'=>1],'ssl_status'=>'enabled','ssl_expires_at'=>null,'server_time'=>time()]];
                }
                return ['ok' => false, 'status' => 404, 'errorCode' => 'rest.route_not_found', 'errorMessage' => 'No route was found matching the URL and request method.'];
            }
        };

        (new SyncSite(httpClient: $stub))->handle($id);

        $site = (new SitesRepository())->findById($id);
        self::assertSame('active', $site->status, '/plugins failure should not mark site as error');

        global $wpdb;
        $failed = $wpdb->get_row(
            "SELECT details FROM " . ActivityLogTable::tableName() .
            " WHERE event_type = 'plugin_inventory.sync_failed' ORDER BY id DESC LIMIT 1",
            ARRAY_A,
        );
        self::assertNotNull($failed);
        $details = json_decode((string) $failed['details'], true);
        self::assertSame('connector_below_v0.1.3', $details['error']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd packages/dashboard-plugin && composer test -- --filter SyncSitePluginsIntegrationTest
```

Expected: FAIL — SyncSite doesn't call /plugins yet.

- [ ] **Step 3: Modify `SyncSite::handle`**

Read the current `packages/dashboard-plugin/src/Jobs/SyncSite.php`. After the successful `SyncService::sync(...)` call (F6/F7 happy path), append:

```php
        $pluginsResponse = $this->httpClient->signedGet($site, '/plugins');
        if (($pluginsResponse['ok'] ?? false)) {
            $this->pluginsSyncService->sync($siteId, (array) $pluginsResponse['data'], 'background');
        } else {
            $code  = (string) ($pluginsResponse['errorCode'] ?? '');
            $error = $code === 'rest.route_not_found'
                ? 'connector_below_v0.1.3'
                : (string) ($pluginsResponse['errorMessage'] ?? 'unknown');
            $this->log->log(null, $siteId, 'plugin_inventory.sync_failed', [
                'error'  => $error,
                'source' => 'background',
            ]);
        }
```

Update the constructor signature to inject the new `SyncPluginsService`:

```php
    public function __construct(
        private readonly SitesRepository $repo = new SitesRepository(),
        private readonly SignedHttpClient $httpClient = new SignedHttpClient(),
        private readonly SyncService $statusSyncService = new SyncService(),
        private readonly SyncPluginsService $pluginsSyncService = new SyncPluginsService(),
        private readonly ActivityLogger $log = new ActivityLogger(),
    ) {
    }
```

Add the new `use` statements at the top of the file:

```php
use Defyn\Dashboard\Services\SyncPluginsService;
```

(`ActivityLogger` use should already be present from F9; add it if not.)

- [ ] **Step 4: Run tests to verify all pass**

```bash
cd packages/dashboard-plugin && composer test -- --filter SyncSitePluginsIntegrationTest
cd packages/dashboard-plugin && composer test -- --filter SyncSiteTest
```

Expected: PASS for both.

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Jobs/SyncSite.php \
        packages/dashboard-plugin/tests/Integration/Jobs/SyncSitePluginsIntegrationTest.php
git commit -m "feat(p2-1): SyncSite also pulls /plugins after /status"
```

---

## Task 10 — `GET /defyn/v1/sites/{id}/plugins` endpoint

Reads from `SitePluginsRepository`, returns `{plugins, total, last_synced_at}`.

**Files:**
- Create: `packages/dashboard-plugin/src/Rest/SitesPluginsListController.php`
- Modify: `packages/dashboard-plugin/src/Rest/RestRouter.php`
- Test: `packages/dashboard-plugin/tests/Integration/Rest/SitesPluginsListTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/dashboard-plugin/tests/Integration/Rest/SitesPluginsListTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Schema\SitePluginsTable;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\SitePluginsRepository;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

/**
 * @group integration
 */
final class SitesPluginsListTest extends AbstractSchemaTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_sites');
        $this->freshlyActivate('defyn_site_plugins');
        global $wpdb;
        $wpdb->query('TRUNCATE ' . SitesTable::tableName());
        $wpdb->query('TRUNCATE ' . SitePluginsTable::tableName());
        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
        do_action('rest_api_init');
    }

    public function testReturns401WithoutJwt(): void
    {
        $req = new WP_REST_Request('GET', '/defyn/v1/sites/1/plugins');
        $res = rest_do_request($req);
        self::assertSame(401, $res->get_status());
    }

    public function testReturns404WhenSiteNotOwnedByUser(): void
    {
        $sites = new SitesRepository();
        $sites->insertPending(2, 'https://other.test', 'Other', base64_encode(random_bytes(32)), 'cipher');

        $jwt = (new TokenService())->issueAccessToken(['sub' => 1]);
        $req = new WP_REST_Request('GET', '/defyn/v1/sites/1/plugins');
        $req->set_header('Authorization', 'Bearer ' . $jwt);

        $res = rest_do_request($req);
        self::assertSame(404, $res->get_status());
        self::assertSame('sites.not_found', $res->get_data()['error']['code']);
    }

    public function testReturnsPluginsForOwnedSite(): void
    {
        $sites = new SitesRepository();
        $id = $sites->insertPending(1, 'https://demo.test', 'Demo', base64_encode(random_bytes(32)), 'cipher');

        (new SitePluginsRepository())->replaceForSite($id, [
            ['slug' => 'a.php', 'name' => 'A', 'version' => '1.0', 'update_available' => true,  'update_version' => '1.1'],
            ['slug' => 'b.php', 'name' => 'B', 'version' => '2.0', 'update_available' => false, 'update_version' => null],
        ], gmdate('Y-m-d H:i:s'));

        $jwt = (new TokenService())->issueAccessToken(['sub' => 1]);
        $req = new WP_REST_Request('GET', '/defyn/v1/sites/' . $id . '/plugins');
        $req->set_header('Authorization', 'Bearer ' . $jwt);

        $res  = rest_do_request($req);
        $body = $res->get_data();

        self::assertSame(200, $res->get_status());
        self::assertSame(2, $body['total']);
        self::assertCount(2, $body['plugins']);
        self::assertSame('a.php', $body['plugins'][0]['slug']);
        self::assertTrue($body['plugins'][0]['update_available']);
        self::assertNotNull($body['last_synced_at']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd packages/dashboard-plugin && composer test -- --filter SitesPluginsListTest
```

Expected: FAIL — 404 (route not registered) for the happy-path test.

- [ ] **Step 3: Write minimal implementation**

Create `packages/dashboard-plugin/src/Rest/SitesPluginsListController.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\SitePluginsRepository;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

final class SitesPluginsListController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $siteId = (int) $request['id'];
        $userId = (int) $request->get_param('_authenticated_user_id');

        $site = (new SitesRepository())->findByIdForUser($siteId, $userId);
        if ($site === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }

        $repo         = new SitePluginsRepository();
        $rows         = $repo->findAllForSite($siteId);
        $lastSyncedAt = $repo->lastSyncedAtForSite($siteId);

        return new WP_REST_Response([
            'plugins'        => array_map(static fn($p) => $p->toJson(), $rows),
            'total'          => count($rows),
            'last_synced_at' => $lastSyncedAt,
        ], 200);
    }
}
```

Modify `packages/dashboard-plugin/src/Rest/RestRouter.php` — add inside `register()` near the other `/sites/{id}/...` routes:

```php
        register_rest_route(self::NAMESPACE, '/sites/(?P<id>\d+)/plugins', [
            'methods'             => 'GET',
            'callback'            => [new SitesPluginsListController(), 'handle'],
            'permission_callback' => [RequireAuth::class, 'check'],
        ]);
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd packages/dashboard-plugin && composer test -- --filter SitesPluginsListTest
```

Expected: PASS — all 3 methods green.

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Rest/SitesPluginsListController.php \
        packages/dashboard-plugin/src/Rest/RestRouter.php \
        packages/dashboard-plugin/tests/Integration/Rest/SitesPluginsListTest.php
git commit -m "feat(p2-1): GET /defyn/v1/sites/{id}/plugins"
```

---

## Task 11 — `POST /defyn/v1/sites/{id}/plugins/refresh` + RateLimit

Schedules the AS job + writes `plugin_inventory.refresh_requested`. Rate-limited 6/min via the same pattern as `RateLimit::login`.

**Files:**
- Modify: `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php`
- Create: `packages/dashboard-plugin/src/Rest/SitesPluginsRefreshController.php`
- Modify: `packages/dashboard-plugin/src/Rest/RestRouter.php`
- Test: `packages/dashboard-plugin/tests/Integration/Rest/SitesPluginsRefreshTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/dashboard-plugin/tests/Integration/Rest/SitesPluginsRefreshTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Schema\ActivityLogTable;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

/**
 * @group integration
 */
final class SitesPluginsRefreshTest extends AbstractSchemaTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_sites');
        $this->freshlyActivate('defyn_activity_log');
        global $wpdb;
        $wpdb->query('TRUNCATE ' . SitesTable::tableName());
        $wpdb->query('TRUNCATE ' . ActivityLogTable::tableName());
        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
        do_action('rest_api_init');
    }

    public function testReturns202SchedulesJobAndLogsEvent(): void
    {
        $sites = new SitesRepository();
        $id    = $sites->insertPending(1, 'https://demo.test', 'Demo', base64_encode(random_bytes(32)), 'cipher');
        $sites->markActive($id, base64_encode(random_bytes(32)));

        $jwt = (new TokenService())->issueAccessToken(['sub' => 1]);
        $req = new WP_REST_Request('POST', '/defyn/v1/sites/' . $id . '/plugins/refresh');
        $req->set_header('Authorization', 'Bearer ' . $jwt);

        $res = rest_do_request($req);

        self::assertSame(202, $res->get_status());
        self::assertSame($id, $res->get_data()['site_id']);
        self::assertTrue($res->get_data()['scheduled']);

        $scheduled = as_get_scheduled_actions(['hook' => 'defyn_refresh_site_plugins'], 'ids');
        self::assertNotEmpty($scheduled);

        global $wpdb;
        $event = $wpdb->get_row(
            "SELECT event_type FROM " . ActivityLogTable::tableName() .
            " ORDER BY id DESC LIMIT 1",
            ARRAY_A,
        );
        self::assertSame('plugin_inventory.refresh_requested', $event['event_type']);
    }

    public function testRateLimitedAfterSixRequests(): void
    {
        $sites = new SitesRepository();
        $id    = $sites->insertPending(1, 'https://demo.test', 'Demo', base64_encode(random_bytes(32)), 'cipher');
        $sites->markActive($id, base64_encode(random_bytes(32)));

        $jwt = (new TokenService())->issueAccessToken(['sub' => 1]);

        for ($i = 0; $i < 6; $i++) {
            $req = new WP_REST_Request('POST', '/defyn/v1/sites/' . $id . '/plugins/refresh');
            $req->set_header('Authorization', 'Bearer ' . $jwt);
            $res = rest_do_request($req);
            self::assertSame(202, $res->get_status(), "request {$i} should succeed");
        }

        $req = new WP_REST_Request('POST', '/defyn/v1/sites/' . $id . '/plugins/refresh');
        $req->set_header('Authorization', 'Bearer ' . $jwt);
        $res = rest_do_request($req);
        self::assertSame(429, $res->get_status());
        self::assertSame('plugins.rate_limited', $res->get_data()['error']['code']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd packages/dashboard-plugin && composer test -- --filter SitesPluginsRefreshTest
```

Expected: FAIL — route not registered.

- [ ] **Step 3: Write minimal implementations**

Modify `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php` — add sibling method to `login`:

```php
    /**
     * P2.1: 6 requests / minute / (user, site) for the refresh button.
     */
    public static function pluginsRefresh($request)
    {
        $authResult = RequireAuth::check($request);
        if (is_wp_error($authResult) || $authResult === false) {
            return $authResult;
        }
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request['id'];

        $key   = sprintf('defyn_rl_plugins_refresh_%d_%d', $userId, $siteId);
        $count = (int) get_transient($key);
        if ($count >= 6) {
            return new \WP_Error(
                'plugins.rate_limited',
                'Refresh requested too often. Wait a minute and try again.',
                ['status' => 429],
            );
        }
        set_transient($key, $count + 1, 60);
        return true;
    }
```

Create `packages/dashboard-plugin/src/Rest/SitesPluginsRefreshController.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

final class SitesPluginsRefreshController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $siteId = (int) $request['id'];
        $userId = (int) $request->get_param('_authenticated_user_id');

        $site = (new SitesRepository())->findByIdForUser($siteId, $userId);
        if ($site === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }

        as_schedule_single_action(time(), 'defyn_refresh_site_plugins', [$siteId], 'defyn');

        (new ActivityLogger())->log($userId, $siteId, 'plugin_inventory.refresh_requested', null);

        return new WP_REST_Response(['scheduled' => true, 'site_id' => $siteId], 202);
    }
}
```

Modify `packages/dashboard-plugin/src/Rest/RestRouter.php` — register the refresh route below the list route from Task 10:

```php
        register_rest_route(self::NAMESPACE, '/sites/(?P<id>\d+)/plugins/refresh', [
            'methods'             => 'POST',
            'callback'            => [new SitesPluginsRefreshController(), 'handle'],
            'permission_callback' => [RateLimit::class, 'pluginsRefresh'],
        ]);
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd packages/dashboard-plugin && composer test -- --filter SitesPluginsRefreshTest
```

Expected: PASS — both methods green.

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php \
        packages/dashboard-plugin/src/Rest/SitesPluginsRefreshController.php \
        packages/dashboard-plugin/src/Rest/RestRouter.php \
        packages/dashboard-plugin/tests/Integration/Rest/SitesPluginsRefreshTest.php
git commit -m "feat(p2-1): POST /defyn/v1/sites/{id}/plugins/refresh + rate limit"
```

---

## Task 12 — Wire activation, AS hook, uninstaller, dashboard v0.2.0

Wires the new table into `Activation::activate` (idempotent via `SchemaVersion`), registers the AS hook in `Plugin::boot`, drops the table in `Uninstaller`, bumps version.

**Files:**
- Modify: `packages/dashboard-plugin/src/Activation.php`
- Modify: `packages/dashboard-plugin/src/Plugin.php`
- Modify: `packages/dashboard-plugin/src/Uninstaller.php`
- Modify: `packages/dashboard-plugin/defyn-dashboard.php`
- Test: `packages/dashboard-plugin/tests/Integration/SchemaMigrationOnActivationTest.php`

- [ ] **Step 1: Write the failing test**

Create `packages/dashboard-plugin/tests/Integration/SchemaMigrationOnActivationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Schema\SchemaVersion;
use Defyn\Dashboard\Schema\SitePluginsTable;

/**
 * @group integration
 */
final class SchemaMigrationOnActivationTest extends AbstractSchemaTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        delete_option(SchemaVersion::OPTION);

        global $wpdb;
        $wpdb->query('DROP TABLE IF EXISTS ' . SitePluginsTable::tableName());
    }

    public function testActivationCreatesNewTableAndBumpsVersion(): void
    {
        Activation::activate();

        global $wpdb;
        $name = SitePluginsTable::tableName();
        self::assertSame($name, $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $name)));
        self::assertSame(2, SchemaVersion::current());
    }

    public function testActivationIsIdempotent(): void
    {
        Activation::activate();
        Activation::activate();
        self::assertSame(2, SchemaVersion::current());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd packages/dashboard-plugin && composer test -- --filter SchemaMigrationOnActivationTest
```

Expected: FAIL — `SitePluginsTable` not registered.

- [ ] **Step 3: Implement**

Modify `packages/dashboard-plugin/src/Activation.php` — add `SitePluginsTable::createSql()` to the dbDelta call, and bump `SchemaVersion::set(2)` at the bottom:

```php
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta(SitesTable::createSql());
        dbDelta(ConnectionCodesTable::createSql());
        dbDelta(ActivityLogTable::createSql());
        dbDelta(SitePluginsTable::createSql()); // P2.1
        // ... existing AS schedules registration ...
        SchemaVersion::set(max(SchemaVersion::current(), 2));
```

Add at the top:

```php
use Defyn\Dashboard\Schema\SchemaVersion;
use Defyn\Dashboard\Schema\SitePluginsTable;
```

Modify `packages/dashboard-plugin/src/Plugin.php` — register the new AS hook in `boot()`:

```php
        add_action('defyn_refresh_site_plugins', static function (int $siteId): void {
            (new Jobs\RefreshSitePlugins())->handle($siteId);
        });
```

Modify `packages/dashboard-plugin/src/Uninstaller.php` — drop the table + clear schema-version option on uninstall:

```php
        $tables = [
            SitesTable::tableName(),
            ConnectionCodesTable::tableName(),
            ActivityLogTable::tableName(),
            SitePluginsTable::tableName(), // P2.1
        ];
        foreach ($tables as $t) {
            $wpdb->query("DROP TABLE IF EXISTS {$t}");
        }
        delete_option(SchemaVersion::OPTION);
```

Add at the top:

```php
use Defyn\Dashboard\Schema\SchemaVersion;
use Defyn\Dashboard\Schema\SitePluginsTable;
```

Modify `packages/dashboard-plugin/defyn-dashboard.php`:

```php
 * Version:           0.2.0
```

- [ ] **Step 4: Run the FULL dashboard test suite**

```bash
cd packages/dashboard-plugin && composer test
```

Expected: ALL PASS — new tests + every existing F1-F10 + P2.1 test green.

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Activation.php \
        packages/dashboard-plugin/src/Plugin.php \
        packages/dashboard-plugin/src/Uninstaller.php \
        packages/dashboard-plugin/defyn-dashboard.php \
        packages/dashboard-plugin/tests/Integration/SchemaMigrationOnActivationTest.php
git commit -m "chore(p2-1): dashboard v0.2.0 — wire migration, AS hook, uninstaller"
```

---

## Task 13 — SPA Zod schemas + MSW handlers

Adds the response shape contracts + mock handlers used by every SPA test.

**Files:**
- Create: `apps/web/src/types/api/plugins.ts`
- Modify: `apps/web/src/test/handlers.ts`
- Test: `apps/web/tests/pluginsSchema.test.ts`

- [ ] **Step 1: Write the failing test**

Create `apps/web/tests/pluginsSchema.test.ts`:

```ts
import { describe, expect, it } from 'vitest';
import {
  pluginSchema,
  sitePluginsListResponseSchema,
} from '@/types/api/plugins';

describe('pluginSchema', () => {
  it('accepts a plugin with an update available', () => {
    const parsed = pluginSchema.parse({
      slug: 'a.php',
      name: 'A',
      version: '1.0',
      update_available: true,
      update_version: '1.1',
    });
    expect(parsed.update_available).toBe(true);
    expect(parsed.update_version).toBe('1.1');
  });

  it('accepts version=null and update_version=null', () => {
    const parsed = pluginSchema.parse({
      slug: 'a.php',
      name: 'A',
      version: null,
      update_available: false,
      update_version: null,
    });
    expect(parsed.version).toBeNull();
  });
});

describe('sitePluginsListResponseSchema', () => {
  it('parses an empty list', () => {
    const parsed = sitePluginsListResponseSchema.parse({
      plugins: [],
      total: 0,
      last_synced_at: null,
    });
    expect(parsed.total).toBe(0);
  });

  it('parses a populated list', () => {
    const parsed = sitePluginsListResponseSchema.parse({
      plugins: [
        { slug: 'a.php', name: 'A', version: '1', update_available: false, update_version: null },
      ],
      total: 1,
      last_synced_at: '2026-06-04 11:30:00',
    });
    expect(parsed.plugins).toHaveLength(1);
    expect(parsed.last_synced_at).toBe('2026-06-04 11:30:00');
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd apps/web && pnpm test -- --run pluginsSchema.test.ts
```

Expected: FAIL — module not found.

- [ ] **Step 3: Write minimal implementations**

Create `apps/web/src/types/api/plugins.ts`:

```ts
import { z } from 'zod';

export const pluginSchema = z.object({
  slug: z.string(),
  name: z.string(),
  version: z.string().nullable(),
  update_available: z.boolean(),
  update_version: z.string().nullable(),
});

export type Plugin = z.infer<typeof pluginSchema>;

export const sitePluginsListResponseSchema = z.object({
  plugins: z.array(pluginSchema),
  total: z.number().int(),
  last_synced_at: z.string().nullable(),
});

export type SitePluginsListResponse = z.infer<typeof sitePluginsListResponseSchema>;
```

Modify `apps/web/src/test/handlers.ts` — append MSW handlers + an in-memory store:

```ts
import type { Plugin } from '@/types/api/plugins';

export const mockSitePlugins: Record<number, { plugins: Plugin[]; last_synced_at: string | null }> = {};

export function resetMockSitePlugins(): void {
  for (const key of Object.keys(mockSitePlugins)) {
    delete mockSitePlugins[Number(key)];
  }
}

handlers.push(
  http.get('*/wp-json/defyn/v1/sites/:id/plugins', ({ params }) => {
    const siteId = Number(params.id);
    const bucket = mockSitePlugins[siteId] ?? { plugins: [], last_synced_at: null };
    return HttpResponse.json(
      {
        plugins: bucket.plugins,
        total: bucket.plugins.length,
        last_synced_at: bucket.last_synced_at,
      },
      { status: 200 },
    );
  }),

  http.post('*/wp-json/defyn/v1/sites/:id/plugins/refresh', ({ params }) => {
    const siteId = Number(params.id);
    setTimeout(() => {
      const bucket = mockSitePlugins[siteId] ?? { plugins: [], last_synced_at: null };
      mockSitePlugins[siteId] = { ...bucket, last_synced_at: new Date().toISOString() };
    }, 20);
    return HttpResponse.json({ scheduled: true, site_id: siteId }, { status: 202 });
  }),
);
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd apps/web && pnpm test -- --run pluginsSchema.test.ts
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/types/api/plugins.ts \
        apps/web/src/test/handlers.ts \
        apps/web/tests/pluginsSchema.test.ts
git commit -m "feat(p2-1): SPA plugin Zod schemas + MSW handlers"
```

---

## Task 14 — `useSitePlugins` query hook

Wraps TanStack Query around `GET /sites/:id/plugins` with the Zod schema.

**Files:**
- Create: `apps/web/src/lib/queries/useSitePlugins.ts`
- Test: `apps/web/tests/useSitePlugins.test.tsx`

- [ ] **Step 1: Write the failing test**

Create `apps/web/tests/useSitePlugins.test.tsx`:

```tsx
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, expect, it, beforeEach } from 'vitest';
import type { ReactNode } from 'react';
import { useSitePlugins } from '@/lib/queries/useSitePlugins';
import { mockSitePlugins, resetMockSitePlugins } from '@/test/handlers';

function wrap() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
}

describe('useSitePlugins', () => {
  beforeEach(() => {
    resetMockSitePlugins();
  });

  it('returns plugins from the API', async () => {
    mockSitePlugins[42] = {
      plugins: [
        { slug: 'a.php', name: 'A', version: '1', update_available: true, update_version: '2' },
      ],
      last_synced_at: '2026-06-04 11:00:00',
    };

    const { result } = renderHook(() => useSitePlugins(42), { wrapper: wrap() });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data?.total).toBe(1);
    expect(result.current.data?.plugins[0].slug).toBe('a.php');
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd apps/web && pnpm test -- --run useSitePlugins.test.tsx
```

Expected: FAIL — module not found.

- [ ] **Step 3: Write minimal implementation**

Create `apps/web/src/lib/queries/useSitePlugins.ts`:

```ts
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { sitePluginsListResponseSchema } from '@/types/api/plugins';

interface UseSitePluginsOptions {
  refetchInterval?: number | false;
}

export function useSitePlugins(siteId: number, opts: UseSitePluginsOptions = {}) {
  return useQuery({
    queryKey: ['sites', siteId, 'plugins'],
    queryFn: async () => {
      const data = await apiClient.get<unknown>(`/sites/${siteId}/plugins`);
      return sitePluginsListResponseSchema.parse(data);
    },
    staleTime: 60_000,
    refetchInterval: opts.refetchInterval ?? false,
  });
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd apps/web && pnpm test -- --run useSitePlugins.test.tsx
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/lib/queries/useSitePlugins.ts \
        apps/web/tests/useSitePlugins.test.tsx
git commit -m "feat(p2-1): useSitePlugins query hook"
```

---

## Task 15 — `useRefreshSitePlugins` mutation + polling

Mutation fires POST, sets `isPolling`, polls until `last_synced_at` advances past click time. Hard timeout 60s.

**Files:**
- Create: `apps/web/src/lib/mutations/useRefreshSitePlugins.ts`
- Test: `apps/web/tests/useRefreshSitePlugins.test.tsx`

- [ ] **Step 1: Write the failing test**

Create `apps/web/tests/useRefreshSitePlugins.test.tsx`:

```tsx
import { act, renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ReactNode } from 'react';
import { useRefreshSitePlugins } from '@/lib/mutations/useRefreshSitePlugins';
import { mockSitePlugins, resetMockSitePlugins } from '@/test/handlers';

function wrap() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
}

describe('useRefreshSitePlugins', () => {
  beforeEach(() => {
    resetMockSitePlugins();
    vi.useRealTimers();
  });

  it('starts polling after a successful refresh and stops when last_synced_at advances', async () => {
    mockSitePlugins[42] = { plugins: [], last_synced_at: null };

    const { result } = renderHook(() => useRefreshSitePlugins(42), { wrapper: wrap() });

    act(() => {
      result.current.refresh();
    });

    await waitFor(() => expect(result.current.isPolling).toBe(true));
    await waitFor(() => expect(result.current.isPolling).toBe(false), { timeout: 5_000 });
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd apps/web && pnpm test -- --run useRefreshSitePlugins.test.tsx
```

Expected: FAIL — module not found.

- [ ] **Step 3: Write minimal implementation**

Create `apps/web/src/lib/mutations/useRefreshSitePlugins.ts`:

```ts
import { useEffect, useRef, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { useSitePlugins } from '@/lib/queries/useSitePlugins';

export function useRefreshSitePlugins(siteId: number) {
  const queryClient = useQueryClient();
  const triggerAtRef = useRef<string | null>(null);
  const [isPolling, setIsPolling] = useState(false);

  const query = useSitePlugins(siteId, {
    refetchInterval: isPolling ? 2_000 : false,
  });

  useEffect(() => {
    if (!isPolling) return;
    const latest = query.data?.last_synced_at;
    const trigger = triggerAtRef.current;
    if (latest && trigger && latest > trigger) {
      setIsPolling(false);
    }
  }, [query.data?.last_synced_at, isPolling]);

  useEffect(() => {
    if (!isPolling) return;
    const timeoutId = window.setTimeout(() => setIsPolling(false), 60_000);
    return () => window.clearTimeout(timeoutId);
  }, [isPolling]);

  const mutation = useMutation({
    mutationFn: async () => {
      triggerAtRef.current = new Date().toISOString();
      return apiClient.post<{ scheduled: boolean; site_id: number }>(
        `/sites/${siteId}/plugins/refresh`,
      );
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['sites', siteId, 'plugins'] });
      setIsPolling(true);
    },
  });

  return {
    refresh: () => mutation.mutate(),
    isPending: mutation.isPending,
    isPolling,
    error: mutation.error,
  };
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd apps/web && pnpm test -- --run useRefreshSitePlugins.test.tsx
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/lib/mutations/useRefreshSitePlugins.ts \
        apps/web/tests/useRefreshSitePlugins.test.tsx
git commit -m "feat(p2-1): useRefreshSitePlugins mutation + polling"
```

---

## Task 16 — `SitePluginsRow` + `SitePluginsPanel` components

The visible UI: panel header + filter toggle + refresh button + table of plugin rows.

**Files:**
- Create: `apps/web/src/components/sites/SitePluginsRow.tsx`
- Create: `apps/web/src/components/sites/SitePluginsPanel.tsx`
- Test: `apps/web/tests/SitePluginsPanel.test.tsx`

- [ ] **Step 1: Write the failing test**

Create `apps/web/tests/SitePluginsPanel.test.tsx`:

```tsx
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { beforeEach, describe, expect, it } from 'vitest';
import type { ReactElement } from 'react';
import { SitePluginsPanel } from '@/components/sites/SitePluginsPanel';
import { mockSitePlugins, resetMockSitePlugins } from '@/test/handlers';

function renderWithClient(ui: ReactElement) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={client}>{ui}</QueryClientProvider>);
}

describe('SitePluginsPanel', () => {
  beforeEach(() => resetMockSitePlugins());

  it('renders plugin rows from the API', async () => {
    mockSitePlugins[42] = {
      plugins: [
        { slug: 'akismet/akismet.php', name: 'Akismet', version: '5.3.1', update_available: true, update_version: '5.3.5' },
        { slug: 'rank-math/rank-math.php', name: 'Rank Math', version: '1.0.234', update_available: false, update_version: null },
      ],
      last_synced_at: '2026-06-04 11:30:00',
    };

    renderWithClient(<SitePluginsPanel siteId={42} />);

    await waitFor(() => expect(screen.getByText('Akismet')).toBeInTheDocument());
    expect(screen.getByText('Rank Math')).toBeInTheDocument();
    expect(screen.getByText(/5\.3\.5/)).toBeInTheDocument();
  });

  it('filters to updates-only when toggle is on', async () => {
    mockSitePlugins[42] = {
      plugins: [
        { slug: 'a.php', name: 'A', version: '1.0', update_available: false, update_version: null },
        { slug: 'b.php', name: 'B', version: '2.0', update_available: true, update_version: '2.1' },
      ],
      last_synced_at: '2026-06-04 11:30:00',
    };

    renderWithClient(<SitePluginsPanel siteId={42} />);

    await waitFor(() => expect(screen.getByText('A')).toBeInTheDocument());

    await userEvent.click(screen.getByRole('switch', { name: /updates only/i }));

    expect(screen.queryByText('A')).not.toBeInTheDocument();
    expect(screen.getByText('B')).toBeInTheDocument();
  });

  it('renders the empty state when site has zero plugins but was synced', async () => {
    mockSitePlugins[42] = { plugins: [], last_synced_at: '2026-06-04 11:30:00' };

    renderWithClient(<SitePluginsPanel siteId={42} />);

    await waitFor(() =>
      expect(screen.getByText(/no plugins installed/i)).toBeInTheDocument(),
    );
  });

  it('renders the "not yet synced" banner when last_synced_at is null', async () => {
    mockSitePlugins[42] = { plugins: [], last_synced_at: null };

    renderWithClient(<SitePluginsPanel siteId={42} />);

    await waitFor(() =>
      expect(screen.getByText(/plugin inventory not yet captured/i)).toBeInTheDocument(),
    );
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd apps/web && pnpm test -- --run SitePluginsPanel.test.tsx
```

Expected: FAIL — component not found.

- [ ] **Step 3: Write minimal implementations**

If `Switch` or `Badge` aren't in the shadcn local registry yet:

```bash
cd apps/web && pnpm dlx shadcn@latest add switch badge
```

Create `apps/web/src/components/sites/SitePluginsRow.tsx`:

```tsx
import { Badge } from '@/components/ui/badge';
import type { Plugin } from '@/types/api/plugins';

interface Props {
  plugin: Plugin;
}

export function SitePluginsRow({ plugin }: Props) {
  return (
    <tr className="border-b last:border-b-0">
      <td className="py-2">
        <div className="font-medium">{plugin.name}</div>
        <div className="text-xs text-zinc-500">{plugin.slug}</div>
      </td>
      <td className="py-2 text-sm text-zinc-700">{plugin.version ?? '—'}</td>
      <td className="py-2 text-sm">
        {plugin.update_available && plugin.update_version ? (
          <Badge variant="secondary">→ {plugin.update_version}</Badge>
        ) : (
          <span className="text-zinc-400">—</span>
        )}
      </td>
    </tr>
  );
}
```

Create `apps/web/src/components/sites/SitePluginsPanel.tsx`:

```tsx
import { useMemo, useState } from 'react';
import { RefreshCw } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { useSitePlugins } from '@/lib/queries/useSitePlugins';
import { useRefreshSitePlugins } from '@/lib/mutations/useRefreshSitePlugins';
import { SitePluginsRow } from '@/components/sites/SitePluginsRow';

interface Props {
  siteId: number;
}

export function SitePluginsPanel({ siteId }: Props) {
  const { data, isLoading } = useSitePlugins(siteId);
  const { refresh, isPending, isPolling } = useRefreshSitePlugins(siteId);
  const [updatesOnly, setUpdatesOnly] = useState(false);

  const filtered = useMemo(() => {
    const list = data?.plugins ?? [];
    return updatesOnly ? list.filter((p) => p.update_available) : list;
  }, [data?.plugins, updatesOnly]);

  const updatesCount = useMemo(
    () => (data?.plugins ?? []).filter((p) => p.update_available).length,
    [data?.plugins],
  );

  const isRefreshing = isPending || isPolling;

  return (
    <section className="space-y-3 border-t pt-4">
      <header className="flex items-center justify-between">
        <h3 className="text-lg font-semibold">Plugins</h3>
        <div className="flex items-center gap-3">
          <label className="text-sm flex items-center gap-2">
            <Switch
              checked={updatesOnly}
              onCheckedChange={setUpdatesOnly}
              aria-label="Updates only"
            />
            Updates only
          </label>
          <Button
            variant="outline"
            size="sm"
            onClick={() => refresh()}
            disabled={isRefreshing}
            aria-label="Refresh"
          >
            <RefreshCw className={isRefreshing ? 'animate-spin' : ''} size={14} />
          </Button>
        </div>
      </header>

      {!isLoading && data && (
        <p className="text-xs text-zinc-500">
          {data.total} installed · {updatesCount} updates available
          {data.last_synced_at ? <> · Last synced {data.last_synced_at}</> : null}
        </p>
      )}

      {!isLoading && data && data.total === 0 && data.last_synced_at === null && (
        <p className="text-sm text-zinc-600">
          Plugin inventory not yet captured. The first background sync runs within 30 minutes — or hit refresh to fetch now.
        </p>
      )}

      {!isLoading && data && data.total === 0 && data.last_synced_at !== null && (
        <p className="text-sm text-zinc-600">No plugins installed on this site.</p>
      )}

      {!isLoading && data && data.total > 0 && (
        <table className={`w-full text-sm ${isRefreshing ? 'opacity-50' : ''}`}>
          <thead>
            <tr className="border-b text-xs uppercase tracking-wide text-zinc-500">
              <th className="text-left py-2">Plugin</th>
              <th className="text-left py-2">Version</th>
              <th className="text-left py-2">Update</th>
            </tr>
          </thead>
          <tbody>
            {filtered.map((p) => (
              <SitePluginsRow key={p.slug} plugin={p} />
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd apps/web && pnpm test -- --run SitePluginsPanel.test.tsx
```

Expected: PASS — all 4 tests green.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/components/sites/SitePluginsRow.tsx \
        apps/web/src/components/sites/SitePluginsPanel.tsx \
        apps/web/tests/SitePluginsPanel.test.tsx \
        apps/web/src/components/ui/switch.tsx \
        apps/web/src/components/ui/badge.tsx
git commit -m "feat(p2-1): SitePluginsPanel + SitePluginsRow components"
```

---

## Task 17 — `SiteDetail` integration, build zips, manual smoke, tag

Stitches everything together: render the panel on the existing site detail page, widen the container, build the two production zips, deploy to production, walk through the manual smoke checklist, tag.

**Files:**
- Modify: `apps/web/src/routes/SiteDetail.tsx`
- Manual: build connector v0.1.3 zip + dashboard v0.2.0 zip + production deploy + smoke

- [ ] **Step 1: Modify `SiteDetail.tsx`**

In `apps/web/src/routes/SiteDetail.tsx`:

1. Change the container width on the wrapper `<div>` from `max-w-xl` to `max-w-3xl` (both the error/loading wrappers and the main one).
2. Import + render `SitePluginsPanel` after `SiteActivityPanel`:

```tsx
import { SitePluginsPanel } from '@/components/sites/SitePluginsPanel';
```

Inside the card body, immediately after `<SiteActivityPanel site={data} />`:

```tsx
          {data.status !== 'pending' && <SitePluginsPanel siteId={siteId} />}
```

- [ ] **Step 2: Run the full SPA suite**

```bash
cd apps/web && pnpm test -- --run
```

Expected: ALL PASS.

- [ ] **Step 3: Build production zips**

```bash
cd /Users/pradeep/Local\ Sites/defynWP

# Connector v0.1.3
BUILD=$(mktemp -d -t defyn-connector-build-XXXX)
ZIP=~/Desktop/defyn-connector-v0.1.3-2026-06-04.zip
rsync -a \
  --exclude='.git*' --exclude='tests/' --exclude='vendor/' --exclude='node_modules/' \
  --exclude='phpunit.xml' --exclude='.phpunit.result.cache' \
  --exclude='wp-tests-config.php' --exclude='composer.lock' \
  packages/connector-plugin/ "$BUILD/defyn-connector/"
( cd "$BUILD/defyn-connector" && composer install --no-dev --optimize-autoloader --quiet --no-interaction )
( cd "$BUILD" && zip -rq "$ZIP" defyn-connector )

# Dashboard v0.2.0
BUILD=$(mktemp -d -t defyn-dashboard-build-XXXX)
ZIP=~/Desktop/defyn-dashboard-v0.2.0-2026-06-04.zip
rsync -a \
  --exclude='.git*' --exclude='tests/' --exclude='vendor/' --exclude='node_modules/' \
  --exclude='phpunit.xml' --exclude='.phpunit.result.cache' \
  --exclude='wp-tests-config.php' --exclude='composer.lock' \
  packages/dashboard-plugin/ "$BUILD/defyn-dashboard/"
( cd "$BUILD/defyn-dashboard" && composer install --no-dev --optimize-autoloader --quiet --no-interaction )
( cd "$BUILD" && zip -rq "$ZIP" defyn-dashboard )

ls -lh ~/Desktop/defyn-connector-v0.1.3-2026-06-04.zip \
       ~/Desktop/defyn-dashboard-v0.2.0-2026-06-04.zip
```

- [ ] **Step 4: Operator deploys + walks the manual smoke (spec § 14.4)**

1. Upload `~/Desktop/defyn-connector-v0.1.3-2026-06-04.zip` to SmartCoding via `wp-admin → Plugins → Add → Upload Plugin → Replace current with uploaded`.
2. Upload `~/Desktop/defyn-dashboard-v0.2.0-2026-06-04.zip` to `defynwp.defyn.agency` the same way.
3. Hit `https://defynwp.defyn.agency/wp-cron.php?doing_wp_cron=1` ~3 times to flush AS queue.
4. With the operator JWT:
   ```bash
   curl -s -H "Authorization: Bearer $TOKEN" \
     "https://defynwp.defyn.agency/wp-json/defyn/v1/sites/1/plugins?_=$(date +%s)" | python3 -m json.tool
   ```
   Expected: ~21 plugins with `slug, name, version, update_available, update_version`; `total: 21`; `last_synced_at` non-null.
5. Trigger refresh:
   ```bash
   curl -s -X POST -H "Authorization: Bearer $TOKEN" \
     "https://defynwp.defyn.agency/wp-json/defyn/v1/sites/1/plugins/refresh"
   ```
   Expected: `202 {"scheduled":true,"site_id":1}`.
6. Poll `GET /sites/1/plugins` every 2s for ~30s; verify `last_synced_at` advances within the window.
7. Open SPA → SmartCoding site detail → verify Plugins panel renders, "Updates only" toggle filters correctly, refresh button shows spinner during polling.
8. Tail activity feed:
   ```bash
   curl -s -H "Authorization: Bearer $TOKEN" \
     "https://defynwp.defyn.agency/wp-json/defyn/v1/sites/1/activity?per_page=5"
   ```
   Expected: `plugin_inventory.refresh_requested` + `plugin_inventory.synced` events at the top.

- [ ] **Step 5: Commit `SiteDetail.tsx` change + tag + push**

```bash
cd /Users/pradeep/Local\ Sites/defynWP
git add apps/web/src/routes/SiteDetail.tsx
git commit -m "feat(p2-1): render SitePluginsPanel on SiteDetail + widen container"

git tag -a p2-1-plugin-inventory-complete -m "P2.1 — plugin inventory shipped 2026-06-XX

Connector v0.1.3 + dashboard v0.2.0 deployed to production
against smartcoding.com.au. Manual smoke walked end-to-end.

Surfaces plugin name, version, and update_available flag in the SPA;
30-min background sync + on-demand refresh button.

Foundation tasks under P2.1 done — next is P2.2 (plugin updates)."
git push origin main
git push origin p2-1-plugin-inventory-complete
```

---

## Wrap-up checklist

- [ ] All 17 tasks committed in order
- [ ] All connector + dashboard + SPA tests green
- [ ] CI green on `main` after push
- [ ] Connector v0.1.3 zip on Desktop
- [ ] Dashboard v0.2.0 zip on Desktop
- [ ] Both zips deployed to production
- [ ] Manual smoke green
- [ ] Tag `p2-1-plugin-inventory-complete` pushed
- [ ] MEMORY note updated (next session knows P2.1 shipped)

---

## Notes for the executor

- **Stay on `main` branch.** Each task = one atomic commit. Pattern matches F1-F10.
- **No `git add -A`.** Stage specific files per task to avoid pulling in unrelated diffs.
- **Run the full test suite at the END of each task** (not just the filter you're targeting) to catch regressions early.
- **If a task's expected output doesn't match what you see, STOP and re-read the spec.** Don't paper over.
- **If you discover a bug in a previous task while doing a later one, fix it in a separate `fix(p2-1):` commit** between tasks.
- **Manual smoke (Task 17 Step 4) is operator-driven** but blocking — do NOT push the tag until it's green.
