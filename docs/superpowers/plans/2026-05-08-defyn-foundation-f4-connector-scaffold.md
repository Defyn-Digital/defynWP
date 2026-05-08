# DefynWP Foundation F4 — Connector Plugin Scaffold + `POST /connect` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A new, separate WP plugin at `packages/connector-plugin/` that (a) generates an Ed25519 keypair on activation, (b) lets a WP admin generate a 12-char connection code via a Settings page, (c) exposes `POST /wp-json/defyn-connector/v1/connect` that validates a posted code against locally-stored state and marks it consumed. NO Ed25519 challenge-response yet — that's F5.

**Architecture:** The connector plugin lives on each managed WordPress site and acts as a stateless agent. Single source of state on activation: one `wp_options` row keyed `defyn_connector` holding JSON `{state, site_public_key, site_private_key, connection_code, code_expires_at, code_consumed_at, ...}`. F4 ships only what F5's handshake job will call into: state storage, keypair generation, admin code-generation UI, and `POST /connect` code-validation.

**Tech Stack:** PHP 8.1+ · WordPress REST API · ext-sodium (libsodium for Ed25519) · PHPUnit 9.6 + wp-phpunit (mirroring F1's harness on dashboard-plugin) · single `wp_options` row for state (no custom DB tables — that's the spec choice for connector — see § 4.2)

> **Direction correction (spec-driven):** The spec § 8 handshake flow is **plugin-first** — the *connector* generates the code (in its Settings page), the user pastes it into the SPA's Add Site form (in F5), then the dashboard's Action Scheduler job posts it back to the *connector's* `/connect`. There is **no** `POST /defyn/v1/connect` on the dashboard — the dashboard receives the code via `POST /defyn/v1/sites` (which is F5's work). F4 is purely connector-side: scaffold + admin code generation + `/connect` code-validation.

---

## About this plan

This is **F4 of the Foundation roadmap**. Built on F1 (dashboard-plugin scaffold + 3 tables), F2 (dashboard-plugin crypto primitives), F3a (dashboard auth REST), F3b (SPA scaffold + login). F4 introduces the **second** plugin package. The dashboard plugin and SPA are unchanged in F4.

**Source spec:** [`docs/superpowers/specs/2026-04-18-defyn-foundation-design.md`](../specs/2026-04-18-defyn-foundation-design.md) — § 4.2 (connector state shape), § 5 (connector plugin), § 8 (handshake flow), § 9 (error envelope), § 11 (build order).

**Definition of "F4 done":**
1. Activating the new `connector-plugin` on a fresh WP site populates `wp_options['defyn_connector']` with a JSON blob containing a fresh Ed25519 keypair and `state = "unconfigured"`.
2. **Settings → DefynWP Connector** page exists in wp-admin. When `state = "unconfigured"`, it shows the public key and a **Generate Connection Code** button. Clicking the button produces a 12-character code displayed prominently with a 15-minute expiry; state is updated to `awaiting-handshake` with `connection_code`, `site_nonce`, `code_expires_at` set.
3. `POST /wp-json/defyn-connector/v1/connect` with body `{"code": "<12-char-code>"}`:
   - **Match + not expired + not consumed** → 200 `{"ok": true}`, state's `code_consumed_at` set to now, `state` flipped to `code-consumed` (one-shot).
   - **Code missing / wrong type** → 400 `{error: {code: "connector.missing_code", message}}`.
   - **No code generated yet** → 404 `{error: {code: "connector.no_pending_code", message}}`.
   - **Code mismatch** → 401 `{error: {code: "connector.invalid_code", message}}`.
   - **Code expired** (now ≥ `code_expires_at`) → 410 `{error: {code: "connector.code_expired", message}}`.
   - **Code already consumed** → 409 `{error: {code: "connector.code_consumed", message}}`.
4. Wire format invariant: every `defyn-connector/v1/*` failure response uses the `{error: {code, message}}` envelope, normalized at the `RestRouter` level (mirrors the dashboard-plugin pattern from F3a).
5. Full PHPUnit suite passes — ~16 new tests across unit + integration. Dashboard-plugin tests remain green (89 / 174 assertions, untouched).
6. CI green on both packages: existing `dashboard-plugin` matrix (PHP 8.1, 8.2) plus a new `connector-plugin` matrix (same PHP versions).

---

## File structure after F4

```
packages/
├── dashboard-plugin/                          # unchanged
└── connector-plugin/                          # NEW
    ├── defyn-connector.php                    # WP plugin headers + bootstrap
    ├── composer.json
    ├── phpunit.xml
    ├── README.md
    ├── .gitignore
    ├── uninstall.php                          # cleans wp_options key on full uninstall
    ├── src/
    │   ├── Plugin.php                         # singleton; wires activation + admin + REST hooks
    │   ├── Activation.php                     # generates keypair + initial state on activation
    │   ├── Crypto/
    │   │   └── KeyPair.php                    # Ed25519 generation via libsodium
    │   ├── Storage/
    │   │   └── ConnectorState.php             # JSON I/O over wp_options['defyn_connector']
    │   ├── Admin/
    │   │   ├── SettingsPage.php               # render + form handler
    │   │   └── CodeGenerator.php              # produces 12-char code + 32-byte nonce + expiry
    │   └── Rest/
    │       ├── RestRouter.php                 # registers /connect; envelope-normalization filter
    │       ├── ConnectController.php          # validates posted code
    │       └── Responses/
    │           └── ErrorResponse.php
    └── tests/
        ├── bootstrap.php
        ├── wp-tests-config.php.example        # template — git-ignored real one created locally
        ├── Unit/
        │   ├── Crypto/
        │   │   └── KeyPairTest.php
        │   ├── Storage/
        │   │   └── ConnectorStateTest.php
        │   └── Admin/
        │       └── CodeGeneratorTest.php
        └── Integration/
            ├── ActivationTest.php
            └── Rest/
                └── ConnectTest.php
```

---

## Prerequisites

- Working tree clean on `main` (last shipped phase = F3b, merge commit `934b701`).
- `php` and `composer` available on PATH (you used `brew install php` in F3a).
- A test MySQL DB (`defyn_test`) accessible via `127.0.0.1:10140` (the F1 setup is reused — `wp-tests-config.php` will be created by Task 2 mirroring `dashboard-plugin/wp-tests-config.php`).
- Branch off `main` to a fresh branch: `git switch -c f4-connector-scaffold`.

If any of these are missing, fix them first; do not start the plan with shaky preconditions.

---

## Task 1: Scaffold `packages/connector-plugin/` directory + WP plugin entry

**Files:**
- Create: `packages/connector-plugin/defyn-connector.php`
- Create: `packages/connector-plugin/composer.json`
- Create: `packages/connector-plugin/.gitignore`
- Create: `packages/connector-plugin/README.md`

- [ ] **Step 1: Create the directory and `defyn-connector.php` (plugin headers + autoloader gate)**

```php
<?php
/**
 * Plugin Name:       DefynWP Connector
 * Plugin URI:        https://defyn.dev
 * Description:       DefynWP — connector agent for managed WordPress sites. Pairs with the central DefynWP Dashboard.
 * Version:           0.1.0
 * Requires at least: 5.5
 * Requires PHP:      8.1
 * Author:            DefynWP
 * License:           Proprietary
 * Text Domain:       defyn-connector
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>DefynWP Connector:</strong> Composer dependencies missing. ';
        echo 'Run <code>composer install</code> in the plugin directory.';
        echo '</p></div>';
    });
    return;
}
require_once $autoload;

if (!extension_loaded('sodium')) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>DefynWP Connector:</strong> The PHP <code>sodium</code> extension is required.';
        echo '</p></div>';
    });
    return;
}

define('DEFYN_CONNECTOR_VERSION', '0.1.0');
define('DEFYN_CONNECTOR_FILE', __FILE__);
define('DEFYN_CONNECTOR_DIR', __DIR__);

\Defyn\Connector\Plugin::instance()->boot();
```

- [ ] **Step 2: Create `composer.json`**

```json
{
    "name": "defyn/connector-plugin",
    "description": "DefynWP — connector plugin (managed-site agent).",
    "type": "wordpress-plugin",
    "license": "proprietary",
    "require": {
        "php": ">=8.1",
        "ext-sodium": "*"
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
            "Defyn\\Connector\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Defyn\\Connector\\Tests\\": "tests/"
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

- [ ] **Step 3: Create `.gitignore`**

```
vendor/
composer.lock
.phpunit.result.cache
wp-tests-config.php
```

> Note: composer.lock is intentionally ignored for this plugin in F4 because the connector has no production dependencies — only dev tooling. Lockfile churn between contributors adds noise without adding determinism for an empty `require` block.

- [ ] **Step 4: Create `README.md` (stub, fleshed out in Task 12)**

```markdown
# DefynWP Connector

A WordPress plugin that turns a managed site into a DefynWP-managed agent. Pairs with the central DefynWP Dashboard plugin via the connection-handshake protocol.

## Install (development)

1. `composer install` in this directory.
2. Symlink or copy this directory into a target WP install's `wp-content/plugins/`.
3. Activate **DefynWP Connector** from the WP admin Plugins screen.

## Generate a connection code

1. Go to **Settings → DefynWP Connector**.
2. Click **Generate Connection Code**.
3. Paste the 12-character code into the DefynWP Dashboard SPA's "Add Site" form (the dashboard's UI lands in F5).

## Run tests

```
composer test
```

(Requires a local MySQL test database. See `tests/wp-tests-config.php.example`.)
```

- [ ] **Step 5: Commit**

```bash
git add packages/connector-plugin/defyn-connector.php packages/connector-plugin/composer.json packages/connector-plugin/.gitignore packages/connector-plugin/README.md
git commit -m "F4: connector-plugin skeleton — plugin entry + composer.json"
```

---

## Task 2: Test harness — phpunit.xml + bootstrap.php + wp-tests-config template

**Files:**
- Create: `packages/connector-plugin/phpunit.xml`
- Create: `packages/connector-plugin/tests/bootstrap.php`
- Create: `packages/connector-plugin/tests/wp-tests-config.php.example`

- [ ] **Step 1: Run `composer install` to fetch dev dependencies**

```bash
cd packages/connector-plugin
composer install --prefer-dist --no-progress
```

Expected: vendor/ populated; wp-phpunit, phpunit, yoast/phpunit-polyfills, wordpress-core present.

- [ ] **Step 2: Create `phpunit.xml`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
    bootstrap="tests/bootstrap.php"
    colors="true"
    cacheResultFile=".phpunit.result.cache"
    failOnWarning="true"
    failOnRisky="true">
    <testsuites>
        <testsuite name="unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="integration">
            <directory>tests/Integration</directory>
        </testsuite>
    </testsuites>
    <coverage>
        <include>
            <directory>src</directory>
        </include>
    </coverage>
</phpunit>
```

- [ ] **Step 3: Create `tests/bootstrap.php`**

```php
<?php
/**
 * Test bootstrap for DefynWP Connector — mirrors dashboard-plugin's pattern.
 * Loads wp-phpunit's harness, then loads the connector plugin as a muplugin
 * so its activation hook can run before tests.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

$config_path = __DIR__ . '/../wp-tests-config.php';
if (!file_exists($config_path)) {
    fwrite(STDERR, "Missing " . $config_path . " — copy tests/wp-tests-config.php.example and fill in DB creds.\n");
    exit(1);
}
putenv('WP_PHPUNIT__TESTS_CONFIG=' . $config_path);

$wp_tests_dir = __DIR__ . '/../vendor/wp-phpunit/wp-phpunit';
if (!file_exists($wp_tests_dir . '/includes/functions.php')) {
    fwrite(STDERR, "wp-phpunit not installed. Run: composer install\n");
    exit(1);
}

require_once $wp_tests_dir . '/includes/functions.php';

tests_add_filter('muplugins_loaded', static function (): void {
    require __DIR__ . '/../defyn-connector.php';
});

require $wp_tests_dir . '/includes/bootstrap.php';
```

- [ ] **Step 4: Create `tests/wp-tests-config.php.example`**

```php
<?php
/**
 * Copy this to ../wp-tests-config.php and adjust the DB section
 * so the connector plugin tests can run.
 */

define('ABSPATH', __DIR__ . '/vendor/wordpress/');
define('WP_TESTS_DOMAIN', 'defyn-connector.test');
define('WP_TESTS_EMAIL', 'admin@defyn-connector.test');
define('WP_TESTS_TITLE', 'DefynWP Connector Tests');
define('WP_PHP_BINARY', 'php');

define('DB_NAME', 'defyn_test');
define('DB_USER', 'root');
define('DB_PASSWORD', '');
define('DB_HOST', '127.0.0.1');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

$table_prefix = 'wptests_connector_';
```

> Note: a distinct `$table_prefix` (`wptests_connector_`) lets the connector tests share the `defyn_test` database with the dashboard tests without colliding on the same `wptests_*` tables.

- [ ] **Step 5: Create the local `wp-tests-config.php` (gitignored) by copying the example, then sanity-check phpunit can boot**

```bash
cp tests/wp-tests-config.php.example wp-tests-config.php
./vendor/bin/phpunit --version
```

Expected: phpunit version line, no errors.

- [ ] **Step 6: Commit**

```bash
git add packages/connector-plugin/phpunit.xml packages/connector-plugin/tests/bootstrap.php packages/connector-plugin/tests/wp-tests-config.php.example
git commit -m "F4: connector-plugin PHPUnit harness — bootstrap + config template"
```

---

## Task 3: KeyPair (Ed25519 via libsodium) — connector-side

**Files:**
- Create: `packages/connector-plugin/src/Crypto/KeyPair.php`
- Create: `packages/connector-plugin/tests/Unit/Crypto/KeyPairTest.php`
- Create: `packages/connector-plugin/src/Plugin.php` (minimal stub — plan correction; see note below)

> The dashboard-plugin already has its own `Defyn\Dashboard\Crypto\KeyPair` from F2. The connector needs an analogous class in its own namespace. Code is intentionally duplicated rather than shared — these are two independent plugins that may evolve at different rates.

> **Plan correction (backported during execution):** The first attempt to run `phpunit` after Task 3 fataled because `defyn-connector.php` (created in Task 1) calls `\Defyn\Connector\Plugin::instance()->boot()`, but `Plugin.php` was originally deferred to Task 5. The wp-phpunit bootstrap loads the plugin file via `muplugins_loaded`, which means **any** phpunit run between Task 1 and Task 5 needs `Plugin` class to exist. Fix: add a minimal `Plugin.php` stub in this task. Task 5 expands `boot()` to register the activation hook.

After the test+impl files are in place (per the steps below), also create the stub at `packages/connector-plugin/src/Plugin.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector;

/**
 * Singleton bootstrap. Stub created in Task 3 so phpunit's wp-phpunit
 * harness can boot without fataling on a missing Plugin class.
 *
 * Task 5 expands boot() to register the activation hook;
 * later tasks add REST + admin_menu hooks.
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
        // Hooks registered in Task 5+.
    }
}
```

The Step 5 `git add` should additionally include `packages/connector-plugin/src/Plugin.php`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Unit\Crypto;

use Defyn\Connector\Crypto\KeyPair;
use PHPUnit\Framework\TestCase;

/**
 * @group unit
 */
final class KeyPairTest extends TestCase
{
    public function testGenerateProducesBase64KeysOfExpectedLength(): void
    {
        $pair = KeyPair::generate();

        self::assertArrayHasKey('public_key', $pair);
        self::assertArrayHasKey('private_key', $pair);

        $publicRaw  = base64_decode($pair['public_key'], true);
        $privateRaw = base64_decode($pair['private_key'], true);

        self::assertNotFalse($publicRaw, 'public_key is not valid base64');
        self::assertNotFalse($privateRaw, 'private_key is not valid base64');

        self::assertSame(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, strlen($publicRaw));
        self::assertSame(SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, strlen($privateRaw));
    }

    public function testGenerateProducesUniqueKeysEachCall(): void
    {
        $a = KeyPair::generate();
        $b = KeyPair::generate();

        self::assertNotSame($a['public_key'], $b['public_key']);
        self::assertNotSame($a['private_key'], $b['private_key']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit --testsuite unit --filter KeyPairTest
```

Expected: Class `Defyn\Connector\Crypto\KeyPair` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Crypto;

/**
 * Ed25519 keypair generation via libsodium.
 * Returns base64-encoded keys for safe storage in wp_options JSON.
 */
final class KeyPair
{
    /**
     * @return array{public_key: string, private_key: string} base64-encoded
     */
    public static function generate(): array
    {
        $pair = sodium_crypto_sign_keypair();
        return [
            'public_key'  => base64_encode(sodium_crypto_sign_publickey($pair)),
            'private_key' => base64_encode(sodium_crypto_sign_secretkey($pair)),
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
./vendor/bin/phpunit --testsuite unit --filter KeyPairTest
```

Expected: 2 / 2 tests passing.

- [ ] **Step 5: Commit**

```bash
git add packages/connector-plugin/src/Crypto/KeyPair.php packages/connector-plugin/tests/Unit/Crypto/KeyPairTest.php
git commit -m "F4: TDD KeyPair — Ed25519 generation via libsodium"
```

---

## Task 4: ConnectorState — single `wp_options` row, JSON I/O

**Files:**
- Create: `packages/connector-plugin/src/Storage/ConnectorState.php`
- Create: `packages/connector-plugin/tests/Unit/Storage/ConnectorStateTest.php`

> This stores the entire connector state — keypair, code, expiry, etc. — as a single JSON value under one `wp_options` key (`defyn_connector`). Per spec § 4.2.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Unit\Storage;

use Defyn\Connector\Storage\ConnectorState;
use WP_UnitTestCase;

/**
 * @group unit
 *
 * Uses WP_UnitTestCase because ConnectorState reads/writes wp_options;
 * we want the real options API behavior, not a hand-rolled fake.
 */
final class ConnectorStateTest extends WP_UnitTestCase
{
    private ConnectorState $state;

    public function setUp(): void
    {
        parent::setUp();
        $this->state = new ConnectorState();
        $this->state->reset();
    }

    public function testInitiallyHasNoState(): void
    {
        self::assertFalse($this->state->exists());
        self::assertSame([], $this->state->all());
    }

    public function testSavePersistsArrayAsJson(): void
    {
        $this->state->save(['state' => 'unconfigured', 'public_key' => 'abc']);

        self::assertTrue($this->state->exists());
        self::assertSame('unconfigured', $this->state->get('state'));
        self::assertSame('abc', $this->state->get('public_key'));
    }

    public function testUpdateMergesIntoExistingState(): void
    {
        $this->state->save(['state' => 'unconfigured', 'public_key' => 'abc']);
        $this->state->update(['state' => 'awaiting-handshake', 'code' => 'XYZ']);

        $all = $this->state->all();
        self::assertSame('awaiting-handshake', $all['state']);
        self::assertSame('abc', $all['public_key']);  // preserved
        self::assertSame('XYZ', $all['code']);        // added
    }

    public function testResetClearsState(): void
    {
        $this->state->save(['state' => 'awaiting-handshake']);
        $this->state->reset();

        self::assertFalse($this->state->exists());
    }

    public function testGetReturnsDefaultForMissingKey(): void
    {
        self::assertNull($this->state->get('missing'));
        self::assertSame('fallback', $this->state->get('missing', 'fallback'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit --testsuite unit --filter ConnectorStateTest
```

Expected: Class `Defyn\Connector\Storage\ConnectorState` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Storage;

/**
 * Reads and writes the single `wp_options['defyn_connector']` JSON blob.
 *
 * Per spec § 4.2, all connector state lives in one row to avoid
 * autoload bloat from many small options.
 */
final class ConnectorState
{
    public const OPTION_KEY = 'defyn_connector';

    /** @return array<string, mixed> */
    public function all(): array
    {
        $raw = get_option(self::OPTION_KEY, '');
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function exists(): bool
    {
        return get_option(self::OPTION_KEY, null) !== null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function save(array $data): void
    {
        update_option(self::OPTION_KEY, json_encode($data, JSON_THROW_ON_ERROR), false);
    }

    /**
     * @param array<string, mixed> $patch
     */
    public function update(array $patch): void
    {
        $this->save(array_merge($this->all(), $patch));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function reset(): void
    {
        delete_option(self::OPTION_KEY);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
./vendor/bin/phpunit --testsuite unit --filter ConnectorStateTest
```

Expected: 5 / 5 tests passing.

- [ ] **Step 5: Commit**

```bash
git add packages/connector-plugin/src/Storage/ConnectorState.php packages/connector-plugin/tests/Unit/Storage/ConnectorStateTest.php
git commit -m "F4: TDD ConnectorState — single wp_options JSON row"
```

---

## Task 5: Plugin singleton + Activation hook

**Files:**
- Modify: `packages/connector-plugin/src/Plugin.php` *(stub created in Task 3 — Step 4 expands `boot()`)*
- Create: `packages/connector-plugin/src/Activation.php`
- Create: `packages/connector-plugin/uninstall.php`
- Create: `packages/connector-plugin/tests/Integration/ActivationTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration;

use Defyn\Connector\Activation;
use Defyn\Connector\Storage\ConnectorState;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class ActivationTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        (new ConnectorState())->reset();
    }

    public function testActivateGeneratesKeypairAndSetsUnconfiguredState(): void
    {
        Activation::activate();

        $state = (new ConnectorState())->all();

        self::assertSame('unconfigured', $state['state']);
        self::assertNotEmpty($state['site_public_key']);
        self::assertNotEmpty($state['site_private_key']);
        self::assertArrayHasKey('generated_at', $state);

        // Public key is base64 of 32 bytes (Ed25519 public key length).
        self::assertSame(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, strlen(base64_decode($state['site_public_key'], true)));
    }

    public function testActivateIsIdempotent_existingKeypairIsPreserved(): void
    {
        Activation::activate();
        $first = (new ConnectorState())->all();

        Activation::activate();
        $second = (new ConnectorState())->all();

        self::assertSame($first['site_public_key'], $second['site_public_key']);
        self::assertSame($first['site_private_key'], $second['site_private_key']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit --testsuite integration --filter ActivationTest
```

Expected: Class `Defyn\Connector\Activation` not found.

- [ ] **Step 3: Write `Activation.php`**

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector;

use Defyn\Connector\Crypto\KeyPair;
use Defyn\Connector\Storage\ConnectorState;

/**
 * Runs on plugin activation. Generates the site's Ed25519 keypair on first
 * activation and sets state = "unconfigured". Idempotent — repeated activations
 * (e.g. plugin update) preserve the existing keypair.
 */
final class Activation
{
    public static function activate(): void
    {
        $state = new ConnectorState();
        if ($state->exists() && $state->get('site_public_key')) {
            return;  // keypair already generated; preserve it
        }

        $pair = KeyPair::generate();
        $state->save([
            'state'            => 'unconfigured',
            'site_public_key'  => $pair['public_key'],
            'site_private_key' => $pair['private_key'],
            'generated_at'     => gmdate('c'),
        ]);
    }
}
```

- [ ] **Step 4: Expand `Plugin.php` to register the activation hook** *(replace the existing Task 3 stub with this fuller version)*

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector;

/**
 * Singleton bootstrap. Wires activation now;
 * REST + admin hooks added in later tasks of this plan.
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
        register_activation_hook(DEFYN_CONNECTOR_FILE, [Activation::class, 'activate']);
    }
}
```

- [ ] **Step 5: Write `uninstall.php`**

```php
<?php
/**
 * Runs when the plugin is fully uninstalled. Removes the wp_options blob
 * holding the keypair so the next install starts clean.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('defyn_connector');
```

- [ ] **Step 6: Run test to verify it passes**

```bash
./vendor/bin/phpunit --testsuite integration --filter ActivationTest
```

Expected: 2 / 2 tests passing.

- [ ] **Step 7: Commit**

```bash
git add packages/connector-plugin/src/Plugin.php packages/connector-plugin/src/Activation.php packages/connector-plugin/uninstall.php packages/connector-plugin/tests/Integration/ActivationTest.php
git commit -m "F4: Plugin bootstrap + Activation — generates Ed25519 keypair on first activate"
```

---

## Task 6: ErrorResponse + RestRouter scaffold + envelope normalization

**Files:**
- Create: `packages/connector-plugin/src/Rest/Responses/ErrorResponse.php`
- Create: `packages/connector-plugin/src/Rest/RestRouter.php`
- Modify: `packages/connector-plugin/src/Plugin.php` (wire `rest_api_init`)

> Pattern mirrors the dashboard-plugin's RestRouter::normalizeErrorEnvelope filter — every `defyn-connector/v1/*` failure ends up with the same `{error: {code, message}}` shape regardless of whether it was thrown from a controller (via ErrorResponse) or from a permission_callback (which can only return WP_Error).

- [ ] **Step 1: Create `ErrorResponse.php`**

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Rest\Responses;

use WP_REST_Response;

/**
 * Builds a consistent error envelope: { error: { code, message, details? } }.
 * Identical to dashboard-plugin's ErrorResponse so SPA/dashboard error handling
 * works uniformly across both surfaces.
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

- [ ] **Step 2: Create `RestRouter.php` (with route table and envelope-normalization filter — `/connect` route added in Task 10)**

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Rest;

/**
 * Single registration point for every REST route on the connector.
 *
 * Plugin::boot() instantiates this and calls register() on `rest_api_init`.
 * Adding a new endpoint = adding one line to register().
 */
final class RestRouter
{
    public const NAMESPACE = 'defyn-connector/v1';

    public function register(): void
    {
        // Normalize permission_callback failures (WP_Error only) to the same
        // {error: {code, message}} envelope our controllers use via ErrorResponse.
        add_filter('rest_request_after_callbacks', [self::class, 'normalizeErrorEnvelope'], 10, 3);

        // Routes registered in Task 10.
    }

    /**
     * @param mixed            $response
     * @param array            $handler
     * @param \WP_REST_Request $request
     * @return mixed
     */
    public static function normalizeErrorEnvelope($response, $handler, $request)
    {
        if (!$request instanceof \WP_REST_Request) {
            return $response;
        }
        if (strpos($request->get_route(), '/' . self::NAMESPACE) !== 0) {
            return $response;
        }
        if (!is_wp_error($response)) {
            return $response;
        }

        $status = (int) ($response->get_error_data()['status'] ?? 500);
        return Responses\ErrorResponse::create(
            $status,
            (string) $response->get_error_code(),
            (string) $response->get_error_message()
        );
    }
}
```

- [ ] **Step 3: Modify `Plugin.php` to wire the router on `rest_api_init`**

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector;

use Defyn\Connector\Rest\RestRouter;

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
        register_activation_hook(DEFYN_CONNECTOR_FILE, [Activation::class, 'activate']);

        add_action('rest_api_init', static function (): void {
            (new RestRouter())->register();
        });
    }
}
```

- [ ] **Step 4: Sanity-check existing tests still pass**

```bash
./vendor/bin/phpunit
```

Expected: 9 / N passing (KeyPair 2 + ConnectorState 5 + Activation 2). No new tests yet — this task is plumbing.

- [ ] **Step 5: Commit**

```bash
git add packages/connector-plugin/src/Rest/Responses/ErrorResponse.php packages/connector-plugin/src/Rest/RestRouter.php packages/connector-plugin/src/Plugin.php
git commit -m "F4: REST scaffold — RestRouter + ErrorResponse + envelope-normalization filter"
```

---

## Task 7: CodeGenerator — 12-char code + 32-byte nonce + 15-min expiry

**Files:**
- Create: `packages/connector-plugin/src/Admin/CodeGenerator.php`
- Create: `packages/connector-plugin/tests/Unit/Admin/CodeGeneratorTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Unit\Admin;

use Defyn\Connector\Admin\CodeGenerator;
use PHPUnit\Framework\TestCase;

/**
 * @group unit
 */
final class CodeGeneratorTest extends TestCase
{
    public function testGenerateProducesCodeOfLength12FromAllowedAlphabet(): void
    {
        $result = CodeGenerator::generate(now: 1_700_000_000);

        self::assertSame(12, strlen($result['code']));
        self::assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{12}$/', $result['code']);
    }

    public function testGenerateProducesUniqueCodes(): void
    {
        $a = CodeGenerator::generate(now: 1_700_000_000);
        $b = CodeGenerator::generate(now: 1_700_000_000);

        self::assertNotSame($a['code'], $b['code']);
    }

    public function testGenerateProduces32ByteNonceAsBase64(): void
    {
        $result = CodeGenerator::generate(now: 1_700_000_000);

        $nonceRaw = base64_decode($result['nonce'], true);
        self::assertNotFalse($nonceRaw);
        self::assertSame(32, strlen($nonceRaw));
    }

    public function testGenerateSetsExpires15MinutesAhead(): void
    {
        $now = 1_700_000_000;
        $result = CodeGenerator::generate(now: $now);

        self::assertSame($now + (15 * 60), $result['expires_at']);
    }

    public function testGenerateSetsCreatedAtToProvidedNow(): void
    {
        $now = 1_700_000_000;
        $result = CodeGenerator::generate(now: $now);

        self::assertSame($now, $result['created_at']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit --testsuite unit --filter CodeGeneratorTest
```

Expected: Class `Defyn\Connector\Admin\CodeGenerator` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Admin;

/**
 * Generates a connection code (human-readable) + nonce (random) + expiry.
 *
 * Alphabet excludes I, O, 0, 1 to avoid visual ambiguity when the user
 * reads the code off a screen and types it into the SPA.
 */
final class CodeGenerator
{
    public const TTL_SECONDS = 15 * 60;
    public const ALPHABET    = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    public const CODE_LENGTH = 12;
    public const NONCE_BYTES = 32;

    /**
     * @param int|null $now Override clock for tests; defaults to time().
     * @return array{code: string, nonce: string, created_at: int, expires_at: int}
     */
    public static function generate(?int $now = null): array
    {
        $now ??= time();

        $code   = '';
        $alphaLen = strlen(self::ALPHABET);
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, $alphaLen - 1)];
        }

        return [
            'code'       => $code,
            'nonce'      => base64_encode(random_bytes(self::NONCE_BYTES)),
            'created_at' => $now,
            'expires_at' => $now + self::TTL_SECONDS,
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
./vendor/bin/phpunit --testsuite unit --filter CodeGeneratorTest
```

Expected: 5 / 5 tests passing.

- [ ] **Step 5: Commit**

```bash
git add packages/connector-plugin/src/Admin/CodeGenerator.php packages/connector-plugin/tests/Unit/Admin/CodeGeneratorTest.php
git commit -m "F4: TDD CodeGenerator — 12-char code, 32-byte nonce, 15-min expiry"
```

---

## Task 8: Admin Settings page — render only

**Files:**
- Create: `packages/connector-plugin/src/Admin/SettingsPage.php`
- Modify: `packages/connector-plugin/src/Plugin.php` (wire `admin_menu`)

> This task only renders the page. The form-submit handler that actually generates a code lands in Task 9. Splitting the two tasks keeps the diffs reviewable and lets the render be tested before introducing form-state branches.

- [ ] **Step 1: Create `SettingsPage.php` with render-only logic**

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Admin;

use Defyn\Connector\Storage\ConnectorState;

/**
 * Renders Settings → DefynWP Connector. F4 supports three display states:
 *
 *   state = "unconfigured"        → "Generate Connection Code" form
 *   state = "awaiting-handshake"  → display the code + countdown until expiry
 *   state = "code-consumed"       → "Code consumed. Awaiting dashboard handshake."
 *                                    (F5 will flip to "connected" after handshake.)
 */
final class SettingsPage
{
    public const SLUG               = 'defyn-connector';
    public const ACTION_GENERATE    = 'defyn_connector_generate_code';
    public const ACTION_RESET       = 'defyn_connector_reset';
    public const NONCE_GENERATE     = 'defyn_connector_generate_nonce';
    public const NONCE_RESET        = 'defyn_connector_reset_nonce';

    public function registerMenu(): void
    {
        add_options_page(
            __('DefynWP Connector', 'defyn-connector'),
            __('DefynWP Connector', 'defyn-connector'),
            'manage_options',
            self::SLUG,
            [$this, 'render']
        );
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'defyn-connector'));
        }

        $state    = new ConnectorState();
        $current  = $state->get('state', 'unconfigured');
        $publicKey = (string) $state->get('site_public_key', '');
        $code     = (string) $state->get('connection_code', '');
        $expires  = (int)    $state->get('code_expires_at', 0);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('DefynWP Connector', 'defyn-connector') . '</h1>';

        echo '<h2>' . esc_html__('Site identity', 'defyn-connector') . '</h2>';
        echo '<p><code style="display:inline-block;max-width:100%;word-break:break-all;">' . esc_html($publicKey) . '</code></p>';

        echo '<h2>' . esc_html__('Connection', 'defyn-connector') . '</h2>';

        if ($current === 'awaiting-handshake') {
            $secondsLeft = max(0, $expires - time());
            echo '<p><strong>' . esc_html__('Connection code:', 'defyn-connector') . '</strong></p>';
            echo '<p style="font-size:2rem;font-family:monospace;">' . esc_html($code) . '</p>';
            echo '<p>' . sprintf(
                /* translators: %d: seconds remaining until the code expires */
                esc_html__('Expires in %d seconds. Paste this code into the DefynWP Dashboard "Add Site" form.', 'defyn-connector'),
                $secondsLeft
            ) . '</p>';

            self::renderResetForm();
            return;
        }

        if ($current === 'code-consumed') {
            echo '<p>' . esc_html__('Connection code consumed. Waiting for the dashboard to complete the handshake.', 'defyn-connector') . '</p>';
            self::renderResetForm();
            return;
        }

        // Default: unconfigured
        echo '<p>' . esc_html__('Generate a one-time connection code, then paste it into the DefynWP Dashboard.', 'defyn-connector') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_GENERATE) . '">';
        wp_nonce_field(self::NONCE_GENERATE);
        echo '<p><button type="submit" class="button button-primary">' . esc_html__('Generate Connection Code', 'defyn-connector') . '</button></p>';
        echo '</form>';
        echo '</div>';
    }

    private static function renderResetForm(): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_RESET) . '">';
        wp_nonce_field(self::NONCE_RESET);
        echo '<p><button type="submit" class="button">' . esc_html__('Reset / regenerate', 'defyn-connector') . '</button></p>';
        echo '</form>';
        echo '</div>';
    }
}
```

- [ ] **Step 2: Modify `Plugin.php` to wire `admin_menu`**

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector;

use Defyn\Connector\Admin\SettingsPage;
use Defyn\Connector\Rest\RestRouter;

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
        register_activation_hook(DEFYN_CONNECTOR_FILE, [Activation::class, 'activate']);

        add_action('rest_api_init', static function (): void {
            (new RestRouter())->register();
        });

        add_action('admin_menu', static function (): void {
            (new SettingsPage())->registerMenu();
        });
    }
}
```

- [ ] **Step 3: Manual smoke test (skipped in CI; for the local Flywheel site only)**

Activate the connector plugin in `wp-admin/plugins.php`, navigate to `Settings → DefynWP Connector`. Expected: page renders, public key is shown, "Generate Connection Code" button visible. (Clicking the button currently does nothing — handler arrives in Task 9.)

If you don't have the plugin symlinked into a WP install yet, skip this step — Task 9's integration test will exercise the form submission path.

- [ ] **Step 4: Run full test suite to confirm nothing broke**

```bash
./vendor/bin/phpunit
```

Expected: All previously passing tests still pass.

- [ ] **Step 5: Commit**

```bash
git add packages/connector-plugin/src/Admin/SettingsPage.php packages/connector-plugin/src/Plugin.php
git commit -m "F4: Admin Settings page — render-only with public key + Generate button"
```

---

## Task 9: Wire `admin_post_*` handlers — generate code + reset

**Files:**
- Modify: `packages/connector-plugin/src/Admin/SettingsPage.php` (add handler methods)
- Modify: `packages/connector-plugin/src/Plugin.php` (wire admin_post hooks)
- Create: `packages/connector-plugin/tests/Integration/Admin/SettingsPageTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Admin;

use Defyn\Connector\Activation;
use Defyn\Connector\Admin\SettingsPage;
use Defyn\Connector\Storage\ConnectorState;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class SettingsPageTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        (new ConnectorState())->reset();
        Activation::activate();

        $admin = self::factory()->user->create_and_get(['role' => 'administrator']);
        wp_set_current_user($admin->ID);
    }

    public function testHandleGenerateProducesCodeAndUpdatesStateToAwaitingHandshake(): void
    {
        $_POST['_wpnonce'] = wp_create_nonce(SettingsPage::NONCE_GENERATE);

        $page = new SettingsPage();
        $page->handleGenerate();

        $state = (new ConnectorState())->all();
        self::assertSame('awaiting-handshake', $state['state']);
        self::assertSame(12, strlen($state['connection_code']));
        self::assertNotEmpty($state['site_nonce']);
        self::assertGreaterThan(time(), $state['code_expires_at']);
        self::assertArrayNotHasKey('code_consumed_at', $state);
    }

    public function testHandleGenerateRejectsBadNonce(): void
    {
        $_POST['_wpnonce'] = 'not-a-real-nonce';

        $page = new SettingsPage();

        $this->expectException(\WPDieException::class);
        $page->handleGenerate();
    }

    public function testHandleResetClearsCodeFieldsButPreservesKeypair(): void
    {
        // Set up an awaiting-handshake state
        (new ConnectorState())->update([
            'state'           => 'awaiting-handshake',
            'connection_code' => 'AAAAAAAAAAAA',
            'site_nonce'      => 'abc',
            'code_expires_at' => time() + 600,
        ]);
        $beforePublicKey = (new ConnectorState())->get('site_public_key');

        $_POST['_wpnonce'] = wp_create_nonce(SettingsPage::NONCE_RESET);

        $page = new SettingsPage();
        $page->handleReset();

        $state = (new ConnectorState())->all();
        self::assertSame('unconfigured', $state['state']);
        self::assertArrayNotHasKey('connection_code', $state);
        self::assertArrayNotHasKey('site_nonce', $state);
        self::assertArrayNotHasKey('code_expires_at', $state);
        self::assertSame($beforePublicKey, $state['site_public_key']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit --testsuite integration --filter SettingsPageTest
```

Expected: `handleGenerate` / `handleReset` methods don't exist.

- [ ] **Step 3: Add the handler methods to `SettingsPage.php`**

Add this `use` import near the top of the file:

```php
use Defyn\Connector\Admin\CodeGenerator;
```

Then append these methods inside the class:

```php
public function handleGenerate(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Permission denied.', 'defyn-connector'));
    }
    check_admin_referer(self::NONCE_GENERATE);

    $generated = CodeGenerator::generate();

    (new ConnectorState())->update([
        'state'           => 'awaiting-handshake',
        'connection_code' => $generated['code'],
        'site_nonce'      => $generated['nonce'],
        'code_created_at' => $generated['created_at'],
        'code_expires_at' => $generated['expires_at'],
    ]);

    if (function_exists('wp_safe_redirect')) {
        wp_safe_redirect(admin_url('options-general.php?page=' . self::SLUG));
        // tests don't actually exit; production WP will exit() after redirect
    }
}

public function handleReset(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Permission denied.', 'defyn-connector'));
    }
    check_admin_referer(self::NONCE_RESET);

    $state = new ConnectorState();
    $existing = $state->all();
    // Preserve keypair + generated_at; drop code-related fields.
    $cleaned = [
        'state'            => 'unconfigured',
        'site_public_key'  => $existing['site_public_key'] ?? '',
        'site_private_key' => $existing['site_private_key'] ?? '',
        'generated_at'     => $existing['generated_at'] ?? gmdate('c'),
    ];
    $state->save($cleaned);

    if (function_exists('wp_safe_redirect')) {
        wp_safe_redirect(admin_url('options-general.php?page=' . self::SLUG));
    }
}
```

- [ ] **Step 4: Wire the `admin_post_*` hooks in `Plugin.php`**

Inside `boot()`, append:

```php
        add_action('admin_post_' . SettingsPage::ACTION_GENERATE, static function (): void {
            (new SettingsPage())->handleGenerate();
        });
        add_action('admin_post_' . SettingsPage::ACTION_RESET, static function (): void {
            (new SettingsPage())->handleReset();
        });
```

- [ ] **Step 5: Run test to verify it passes**

```bash
./vendor/bin/phpunit --testsuite integration --filter SettingsPageTest
```

Expected: 3 / 3 tests passing.

- [ ] **Step 6: Commit**

```bash
git add packages/connector-plugin/src/Admin/SettingsPage.php packages/connector-plugin/src/Plugin.php packages/connector-plugin/tests/Integration/Admin/SettingsPageTest.php
git commit -m "F4: TDD code-generation form handler + reset handler"
```

---

## Task 10: ConnectController — happy path (valid code → 200, mark consumed)

**Files:**
- Create: `packages/connector-plugin/src/Rest/ConnectController.php`
- Modify: `packages/connector-plugin/src/Rest/RestRouter.php` (register the route)
- Create: `packages/connector-plugin/tests/Integration/Rest/ConnectTest.php`

- [ ] **Step 1: Write the failing test (happy path only)**

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Rest;

use Defyn\Connector\Activation;
use Defyn\Connector\Storage\ConnectorState;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class ConnectTest extends WP_UnitTestCase
{
    private ConnectorState $state;

    public function setUp(): void
    {
        parent::setUp();
        $this->state = new ConnectorState();
        $this->state->reset();
        Activation::activate();
        do_action('rest_api_init');
    }

    public function testValidCodeReturns200AndMarksCodeConsumed(): void
    {
        $code = 'ABCDEFGH2345';
        $this->state->update([
            'state'           => 'awaiting-handshake',
            'connection_code' => $code,
            'site_nonce'      => 'nonce-base64',
            'code_created_at' => time(),
            'code_expires_at' => time() + 600,
        ]);

        $request = new WP_REST_Request('POST', '/defyn-connector/v1/connect');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode(['code' => $code]));

        $response = rest_do_request($request);

        self::assertSame(200, $response->get_status());
        self::assertSame(['ok' => true], $response->get_data());

        $after = $this->state->all();
        self::assertSame('code-consumed', $after['state']);
        self::assertArrayHasKey('code_consumed_at', $after);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit --testsuite integration --filter ConnectTest
```

Expected: Route `/defyn-connector/v1/connect` returns 404 (no_route) or class not found.

- [ ] **Step 3: Create `ConnectController.php`**

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Rest;

use Defyn\Connector\Rest\Responses\ErrorResponse;
use Defyn\Connector\Storage\ConnectorState;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles POST /defyn-connector/v1/connect.
 *
 * F4 scope: validates the posted code against locally-stored connector state
 * and marks it consumed. NO crypto challenge-response — that lands in F5.
 */
final class ConnectController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $body = $request->get_json_params();
        $code = is_array($body) ? ($body['code'] ?? null) : null;

        if (!is_string($code) || $code === '') {
            return ErrorResponse::create(400, 'connector.missing_code', 'Missing or invalid code field.');
        }

        $state   = new ConnectorState();
        $stored  = (string) $state->get('connection_code', '');
        $current = (string) $state->get('state', 'unconfigured');

        if ($stored === '' || $current === 'unconfigured') {
            return ErrorResponse::create(404, 'connector.no_pending_code', 'No connection code has been generated yet.');
        }

        if (!hash_equals($stored, $code)) {
            return ErrorResponse::create(401, 'connector.invalid_code', 'Connection code does not match.');
        }

        if (!empty($state->get('code_consumed_at'))) {
            return ErrorResponse::create(409, 'connector.code_consumed', 'Connection code has already been consumed.');
        }

        $expiresAt = (int) $state->get('code_expires_at', 0);
        if ($expiresAt > 0 && time() >= $expiresAt) {
            return ErrorResponse::create(410, 'connector.code_expired', 'Connection code has expired. Generate a new one.');
        }

        $state->update([
            'state'            => 'code-consumed',
            'code_consumed_at' => time(),
        ]);

        return new WP_REST_Response(['ok' => true], 200);
    }
}
```

- [ ] **Step 4: Register the route in `RestRouter.php`**

Replace the comment `// Routes registered in Task 10.` with:

```php
        register_rest_route(self::NAMESPACE, '/connect', [
            'methods'             => 'POST',
            'callback'            => [new ConnectController(), 'handle'],
            'permission_callback' => '__return_true',  // public; protected by code-validation logic in the controller
        ]);
```

- [ ] **Step 5: Run test to verify it passes**

```bash
./vendor/bin/phpunit --testsuite integration --filter ConnectTest
```

Expected: 1 / 1 passing.

- [ ] **Step 6: Commit**

```bash
git add packages/connector-plugin/src/Rest/ConnectController.php packages/connector-plugin/src/Rest/RestRouter.php packages/connector-plugin/tests/Integration/Rest/ConnectTest.php
git commit -m "F4: TDD ConnectController happy path — valid code → 200 + code-consumed state"
```

---

## Task 11: ConnectController — error paths

**Files:**
- Modify: `packages/connector-plugin/tests/Integration/Rest/ConnectTest.php` (add error-path tests)

> The controller already implements all error branches in Task 10. This task adds tests for each branch — covering the wire-format invariant + every spec'd error code.

- [ ] **Step 1: Append error-path tests to `ConnectTest.php`**

```php
public function testMissingCodeReturns400WithEnvelope(): void
{
    $this->state->update([
        'state'           => 'awaiting-handshake',
        'connection_code' => 'ABCDEFGH2345',
        'code_expires_at' => time() + 600,
    ]);

    $request = new WP_REST_Request('POST', '/defyn-connector/v1/connect');
    $request->set_header('Content-Type', 'application/json');
    $request->set_body(json_encode([]));

    $response = rest_do_request($request);

    self::assertSame(400, $response->get_status());
    self::assertSame('connector.missing_code', $response->get_data()['error']['code']);
}

public function testNoCodeGeneratedReturns404(): void
{
    // state stays at "unconfigured" from Activation
    $request = new WP_REST_Request('POST', '/defyn-connector/v1/connect');
    $request->set_header('Content-Type', 'application/json');
    $request->set_body(json_encode(['code' => 'ABCDEFGH2345']));

    $response = rest_do_request($request);

    self::assertSame(404, $response->get_status());
    self::assertSame('connector.no_pending_code', $response->get_data()['error']['code']);
}

public function testInvalidCodeReturns401(): void
{
    $this->state->update([
        'state'           => 'awaiting-handshake',
        'connection_code' => 'ABCDEFGH2345',
        'code_expires_at' => time() + 600,
    ]);

    $request = new WP_REST_Request('POST', '/defyn-connector/v1/connect');
    $request->set_header('Content-Type', 'application/json');
    $request->set_body(json_encode(['code' => 'WRONGCODE234']));

    $response = rest_do_request($request);

    self::assertSame(401, $response->get_status());
    self::assertSame('connector.invalid_code', $response->get_data()['error']['code']);
}

public function testExpiredCodeReturns410(): void
{
    $this->state->update([
        'state'           => 'awaiting-handshake',
        'connection_code' => 'ABCDEFGH2345',
        'code_expires_at' => time() - 1,
    ]);

    $request = new WP_REST_Request('POST', '/defyn-connector/v1/connect');
    $request->set_header('Content-Type', 'application/json');
    $request->set_body(json_encode(['code' => 'ABCDEFGH2345']));

    $response = rest_do_request($request);

    self::assertSame(410, $response->get_status());
    self::assertSame('connector.code_expired', $response->get_data()['error']['code']);
}

public function testAlreadyConsumedCodeReturns409(): void
{
    $this->state->update([
        'state'             => 'code-consumed',
        'connection_code'   => 'ABCDEFGH2345',
        'code_expires_at'   => time() + 600,
        'code_consumed_at'  => time() - 5,
    ]);

    $request = new WP_REST_Request('POST', '/defyn-connector/v1/connect');
    $request->set_header('Content-Type', 'application/json');
    $request->set_body(json_encode(['code' => 'ABCDEFGH2345']));

    $response = rest_do_request($request);

    self::assertSame(409, $response->get_status());
    self::assertSame('connector.code_consumed', $response->get_data()['error']['code']);
}
```

- [ ] **Step 2: Run tests — they should all pass with the existing controller**

```bash
./vendor/bin/phpunit --testsuite integration --filter ConnectTest
```

Expected: 6 / 6 passing (1 happy path + 5 error paths).

- [ ] **Step 3: Run the full suite to confirm overall green**

```bash
./vendor/bin/phpunit
```

Expected: ~16 tests across unit + integration, all passing.

- [ ] **Step 4: Commit**

```bash
git add packages/connector-plugin/tests/Integration/Rest/ConnectTest.php
git commit -m "F4: tests — ConnectController error paths (missing/invalid/expired/consumed)"
```

---

## Task 12: CI integration + README polish

**Files:**
- Modify: `.github/workflows/test.yml` (add `connector-plugin` job)
- Modify: `packages/connector-plugin/README.md` (full content)

- [ ] **Step 1: Add the `connector-plugin` job to `.github/workflows/test.yml`**

After the existing `dashboard-plugin` job, before the `web` job, append:

```yaml
  connector-plugin:
    name: connector-plugin (PHP ${{ matrix.php }})
    runs-on: ubuntu-latest
    timeout-minutes: 15
    strategy:
      fail-fast: false
      matrix:
        php: ['8.1', '8.2']

    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ALLOW_EMPTY_PASSWORD: 'yes'
          MYSQL_DATABASE: defyn_test
        ports:
          - 3306:3306
        options: >-
          --health-cmd="mysqladmin ping -h localhost"
          --health-interval=10s
          --health-timeout=5s
          --health-retries=5

    defaults:
      run:
        working-directory: packages/connector-plugin

    steps:
      - name: Checkout
        uses: actions/checkout@v4

      - name: Set up PHP ${{ matrix.php }}
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          coverage: none
          tools: composer:v2

      - name: Cache Composer
        uses: actions/cache@v4
        with:
          path: packages/connector-plugin/vendor
          key: composer-connector-${{ matrix.php }}-${{ hashFiles('packages/connector-plugin/composer.json') }}

      - name: Install Composer dependencies
        run: composer install --prefer-dist --no-progress

      - name: Wait for MySQL
        run: |
          for i in {1..60}; do
            mysqladmin ping -h 127.0.0.1 -uroot --silent && exit 0
            sleep 1
          done
          echo "MySQL did not become ready in 60s" >&2
          exit 1

      - name: Set up wp-tests-config.php
        run: |
          cat > wp-tests-config.php <<'PHPEOF'
          <?php
          define('ABSPATH', __DIR__ . '/vendor/wordpress/');
          define('WP_TESTS_DOMAIN', 'defyn-connector.test');
          define('WP_TESTS_EMAIL', 'admin@defyn-connector.test');
          define('WP_TESTS_TITLE', 'DefynWP Connector Tests');
          define('WP_PHP_BINARY', 'php');

          define('DB_NAME', 'defyn_test');
          define('DB_USER', 'root');
          define('DB_PASSWORD', '');
          define('DB_HOST', '127.0.0.1');
          define('DB_CHARSET', 'utf8mb4');
          define('DB_COLLATE', '');

          $table_prefix = 'wptests_connector_';
          PHPEOF

      - name: Run PHPUnit
        run: ./vendor/bin/phpunit
```

- [ ] **Step 2: Flesh out `packages/connector-plugin/README.md`**

```markdown
# DefynWP Connector

A WordPress plugin that turns a managed site into a DefynWP-managed agent. Pairs with the central [DefynWP Dashboard](../dashboard-plugin/) plugin.

## What it does

- Generates an Ed25519 keypair on activation, storing it in `wp_options['defyn_connector']`.
- Adds a **Settings → DefynWP Connector** page in `wp-admin`.
- Lets a WP admin generate a **12-character connection code** (15-minute expiry) to pair the site with the DefynWP Dashboard.
- Exposes `POST /wp-json/defyn-connector/v1/connect` — the endpoint the dashboard calls to validate the connection code during the handshake.

> **F4 scope:** code-validation only. Ed25519 challenge/response signing of the dashboard's `callback_challenge` is added in F5.

## Requirements

- WordPress 5.5+
- PHP 8.1+
- `ext-sodium` (for Ed25519). Standard on modern PHP.

## Install (development)

1. From `packages/connector-plugin/`, run `composer install`.
2. Symlink (or copy) the directory into a target WP install's `wp-content/plugins/`:
   ```bash
   ln -s /absolute/path/to/packages/connector-plugin <wp>/wp-content/plugins/defyn-connector
   ```
3. Activate **DefynWP Connector** from the WP admin Plugins screen.

## Generate a connection code

1. Go to **Settings → DefynWP Connector**.
2. Click **Generate Connection Code**. The page will display a 12-character code that expires in 15 minutes.
3. (F5+) Paste the code into the DefynWP Dashboard SPA's "Add Site" form.

## REST API

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/wp-json/defyn-connector/v1/connect` | Public; gated by code validation | Validates a posted code; marks it consumed. F5 will extend with crypto challenge-response. |

### Error envelope

All non-200 responses use the same envelope:

```json
{ "error": { "code": "connector.invalid_code", "message": "..." } }
```

| Code | HTTP | Meaning |
|---|---|---|
| `connector.missing_code` | 400 | Body is missing the `code` field. |
| `connector.no_pending_code` | 404 | No code has been generated on this site yet. |
| `connector.invalid_code` | 401 | Posted code does not match what the connector stored. |
| `connector.code_expired` | 410 | Code's 15-minute window has passed. |
| `connector.code_consumed` | 409 | Code was already consumed by a previous call. |

## Run tests

```bash
cp tests/wp-tests-config.php.example wp-tests-config.php
# adjust DB section if needed
composer install
composer test
```

## State shape

The plugin stores a single JSON value under `wp_options['defyn_connector']`:

```json
{
  "state": "unconfigured | awaiting-handshake | code-consumed | connected (F5+)",
  "site_public_key":  "<base64 Ed25519>",
  "site_private_key": "<base64 Ed25519>",
  "generated_at":     "<ISO 8601>",
  "connection_code":  "<12-char>",
  "site_nonce":       "<base64 32 bytes>",
  "code_created_at":  <unix ts>,
  "code_expires_at":  <unix ts>,
  "code_consumed_at": <unix ts>
}
```

## Uninstall

`uninstall.php` removes `wp_options['defyn_connector']` on full plugin uninstall, including the keypair.
```

- [ ] **Step 3: Sanity-check the workflow file syntax**

```bash
cd /Users/pradeep/Local\ Sites/defynWP
yamllint .github/workflows/test.yml || true
# yamllint may not be installed — alternative: run `gh workflow view test.yml` after pushing
```

If yamllint isn't installed, the upcoming GitHub Actions run will surface any syntax errors.

- [ ] **Step 4: Commit + push branch**

```bash
git add .github/workflows/test.yml packages/connector-plugin/README.md
git commit -m "F4: CI — add connector-plugin matrix (PHP 8.1+8.2) + README"
git push -u origin f4-connector-scaffold
```

- [ ] **Step 5: Merge to `main` (matching the F1–F3b workflow)**

```bash
git switch main
git merge --no-ff f4-connector-scaffold -m "Merge F4 (Connector Plugin Scaffold + POST /connect) into main"
git tag f4-connector-complete
git push origin main --tags
git branch -d f4-connector-scaffold
```

Wait for CI to go green on both `connector-plugin (PHP 8.1)` and `connector-plugin (PHP 8.2)` jobs before declaring F4 done.

---

## Self-review summary

**Spec coverage:**
- § 4.2 (connector state shape) → Tasks 4 + 5 + 7 + 9 (state schema, activation populates, code generation, form handler)
- § 5.3 (admin UI states: not connected / connected) → Task 8 (render) + Task 9 (form handler). F4 ships "not connected" and "awaiting handshake" / "code consumed" intermediate states; "connected" arrives in F5 after handshake completes.
- § 5.4 (file structure) → File structure table at top of plan; F5 will add `Crypto/Signer.php`, `Rest/VerifySignatureMiddleware.php`, `Status/Heartbeat/Disconnect` controllers, etc.
- § 5.5 (WP 5.5+, PHP 7.2+) → Plugin headers in Task 1 require PHP 8.1+ instead (matches dashboard-plugin floor for codebase consistency)
- § 8 step 7 (connector validates + responds) → Task 10 implements the code-validation portion; Task 11 covers all the spec'd error responses. The crypto challenge-response part of step 7 (sign callback_challenge with K_site, return signature + site_url + site_name) is **deferred to F5** as called out in the F4 description in § 11.
- § 9.1 (error envelope) → ErrorResponse + RestRouter normalization filter (Task 6); Task 11 verifies every spec'd error code uses it.
- § 11 F4 line ("Connector plugin scaffold + POST /connect: separate plugin repo, WP admin page 'Generate Connection Code,' stores keypair, code-validation only") → fully covered.

**Placeholder scan:** None. Every step contains the code, command, or test it needs.

**Type consistency:** Method names: `ConnectorState::all()`, `::exists()`, `::save()`, `::update()`, `::get()`, `::reset()` are used identically in tasks 4, 5, 9, 10, 11. `CodeGenerator::generate()` is used identically in tasks 7 + 9. State shape keys (`state`, `connection_code`, `site_nonce`, `code_expires_at`, `code_consumed_at`, `site_public_key`, `site_private_key`) are consistent across Activation, SettingsPage handlers, ConnectController, and the README state-shape doc.

**Out-of-scope reminders:**
- Ed25519 challenge-response (F5 — sign callback_challenge with K_site)
- Dashboard `POST /sites` endpoint (F5)
- Action Scheduler `defyn_complete_connection` job (F5)
- Connector's `/status`, `/heartbeat`, `/disconnect` endpoints + `VerifySignatureMiddleware` (F6)
- SiteInfo collector (F6 — used by `/status`)

---

## Carried-forward follow-ups (out of scope, capture for F5+)

- After F5, the `state = "code-consumed"` branch in SettingsPage::render should be replaced (or supplemented) with `state = "connected"` showing the handshake timestamp, dashboard public key fingerprint, and a Disconnect button.
- The `code_consumed_at` cleanup: F5's handshake job should advance state from `code-consumed` to `connected` (or `error`) and store the `dashboard_public_key`. After 24h with no advancement, a future cleanup job could revert to `unconfigured`.
- Internationalization: text domain `defyn-connector` is set; no `.pot` file generated yet — defer until F10 (deploy + harden).
- The settings page does not yet auto-refresh the expiry countdown. Consider adding a small JS snippet (or `meta refresh`) in F8 polish.
