# DefynWP Foundation F1 — Scaffolding Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bootstrap a working `defyn-dashboard` WordPress plugin with PSR-4 autoloader, PHPUnit + wp-phpunit test harness, and an activation hook that creates the three custom tables (`wp_defyn_sites`, `wp_defyn_connection_codes`, `wp_defyn_activity_log`) — all running on a local WordPress site with green CI.

**Architecture:** Monorepo at `~/Local Sites/defynWP/` containing all foundation components in `packages/`. The dashboard plugin lives at `packages/dashboard-plugin/` and is symlinked into a Local-by-Flywheel site's `wp-content/plugins/` directory for development. Bedrock layout is deferred to F10 (Kinsta deployment); for local dev we use Local's vanilla WordPress install — plugin code is identical between the two.

**Tech Stack:** PHP 7.2+ · Composer 2 · WordPress 6.x (Local by Flywheel) · MySQL · PHPUnit 9.x · wp-phpunit · yoast/phpunit-polyfills · GitHub Actions

---

## About this plan

This is **F1 of 10 sub-phases** in the DefynWP Foundation (see spec § 11). Each F-phase gets its own implementation plan when its predecessor ships. The F2–F10 roadmap at the end of this document is for reference only — it is **not** detailed at the bite-sized step level, and the writing-plans skill should be re-invoked for F2 once F1 is complete.

**Source spec:** [`docs/superpowers/specs/2026-04-18-defyn-foundation-design.md`](../specs/2026-04-18-defyn-foundation-design.md) (commit `2bae49d`)

**Definition of "F1 done":**
1. Local by Flywheel site is running WordPress and accessible from the browser
2. `defyn-dashboard` plugin appears in WP admin, can be activated/deactivated
3. Activating the plugin creates `wp_defyn_sites`, `wp_defyn_connection_codes`, `wp_defyn_activity_log` tables with the schema from spec § 4.1
4. Uninstalling the plugin drops those tables
5. Activation is idempotent (running twice doesn't error)
6. PHPUnit tests pass locally and in GitHub Actions CI

---

## File structure after F1

```
~/Local Sites/defynWP/                          # repo root (git, already initialized)
├── .git/
├── .github/
│   └── workflows/
│       └── test.yml                            # CI: runs PHPUnit on PR + push
├── .gitignore                                  # excludes Local's runtime dirs
├── README.md                                   # project overview + dev setup
├── app/                                        # ⚠️  Local by Flywheel managed (gitignored)
│   ├── public/                                 # WP install root
│   │   └── wp-content/
│   │       └── plugins/
│   │           └── defyn-dashboard@            # symlink → ../../../../packages/dashboard-plugin
│   └── ... (logs, conf — gitignored)
├── conf/                                       # gitignored (Local's nginx/php conf)
├── logs/                                       # gitignored
├── docs/
│   └── superpowers/
│       ├── specs/
│       │   └── 2026-04-18-defyn-foundation-design.md
│       └── plans/
│           └── 2026-04-25-defyn-foundation-f1-scaffolding.md   # this file
├── packages/
│   └── dashboard-plugin/                       # the WordPress plugin we're building
│       ├── defyn-dashboard.php                 # WP plugin headers + bootstrap
│       ├── composer.json                       # PSR-4 autoload + dev deps
│       ├── composer.lock
│       ├── phpunit.xml                         # PHPUnit config
│       ├── uninstall.php                       # WP-invoked on plugin uninstall
│       ├── src/
│       │   ├── Plugin.php                      # bootstrap class (singleton)
│       │   ├── Activation.php                  # creates tables on activate
│       │   └── Schema/
│       │       ├── SitesTable.php              # CREATE TABLE for wp_defyn_sites
│       │       ├── ConnectionCodesTable.php    # CREATE TABLE for wp_defyn_connection_codes
│       │       └── ActivityLogTable.php        # CREATE TABLE for wp_defyn_activity_log
│       ├── tests/
│       │   ├── bootstrap.php                   # loads wp-phpunit + plugin
│       │   ├── Integration/
│       │   │   ├── ActivationTest.php          # tables created/dropped/idempotent
│       │   │   └── SchemaTest.php              # column types + indexes correct
│       │   └── Unit/
│       │       └── SmokeTest.php               # 1+1=2 — proves toolchain works
│       └── vendor/                             # composer install output (gitignored)
└── ... (other components — added in later F-phases: connector-plugin, web SPA, infrastructure)
```

**Why one file per table?** Each `Schema/*Table.php` returns the `dbDelta`-compatible CREATE statement for one table. Splitting them keeps each file focused and each file's test focused. Spec § 4.1 has three tables, so three files.

---

## Prerequisites

The engineer running this plan must have, on macOS:

| Tool | Version | Verify with | Install if missing |
|---|---|---|---|
| Local by Flywheel | latest | open Local app | https://localwp.com/ |
| Composer | 2.x | `composer --version` | `brew install composer` |
| Git | any 2.x | `git --version` | already on macOS |
| PHP CLI | 7.4+ (matches Local's PHP) | `php --version` | comes with Local; Homebrew if needed |
| MySQL CLI (optional, helpful) | any | `mysql --version` | `brew install mysql-client` |

Node 20+ and pnpm are **not** required for F1; they show up in F3 when the SPA scaffolding starts.

---

## Tasks

### Task 1: Create the Local by Flywheel site

**Why first:** every later task assumes a working WordPress install at a known location. We need this before we can develop or test anything.

**Files:** none (Local app is the action)

- [ ] **Step 1: Verify the project directory state**

Run:
```bash
ls -la "/Users/pradeep/Local Sites/defynWP/"
```

Expected: directory exists, contains `.git/`, `.gitignore`, `docs/`, `.superpowers/`. **Important:** if `app/` already exists with a populated `app/public/wp-content/`, Local has already created a site here — skip ahead to Step 4.

- [ ] **Step 2: Create the Local site (manual UI step — engineer must do this)**

Open Local by Flywheel app. Click **+ Create a new site**. Use these settings:

| Field | Value |
|---|---|
| Site name | `defynWP` |
| Local site path | `/Users/pradeep/Local Sites/defynWP` |
| Environment | **Custom** → PHP 8.2, nginx, MySQL 8.0 |
| WordPress username | `admin` (or any) |
| WordPress password | record it somewhere |
| WordPress email | any valid email |

Click **Add Site**. Wait for Local to finish provisioning.

> **Why custom environment?** Spec § 5.5 requires PHP 7.2+; we use 8.2 because it matches Kinsta's modern PHP and what we'll deploy with in F10. nginx + MySQL 8.0 also matches Kinsta.

- [ ] **Step 3: Verify the site loads**

In Local's UI, click **Open site** → browser opens. WordPress homepage should load. Click **WP Admin** → log in with the credentials from Step 2. You should see wp-admin dashboard.

- [ ] **Step 4: Confirm WP install location**

Run:
```bash
ls -la "/Users/pradeep/Local Sites/defynWP/app/public/wp-content/plugins/"
```

Expected output: includes `akismet`, `hello.php`, possibly `index.php`. This is the directory we'll symlink our plugin into in Task 4.

- [ ] **Step 5: No commit yet** — Local's app/conf/logs dirs will be gitignored in Task 2.

---

### Task 2: Set up project structure + .gitignore

**Why:** Local creates many files (`app/`, `conf/`, `logs/`) we must never commit. We also need `packages/`, `README.md`, and `.github/` directories before adding code.

**Files:**
- Modify: `/Users/pradeep/Local Sites/defynWP/.gitignore`
- Create: `/Users/pradeep/Local Sites/defynWP/README.md`
- Create: `/Users/pradeep/Local Sites/defynWP/packages/.gitkeep` (empty placeholder so dir is tracked)

- [ ] **Step 1: Read current .gitignore**

Run:
```bash
cat "/Users/pradeep/Local Sites/defynWP/.gitignore"
```

Expected current content:
```
.superpowers/
```

- [ ] **Step 2: Replace .gitignore with the full version**

Write the file at `/Users/pradeep/Local Sites/defynWP/.gitignore` with this content:

```gitignore
# Brainstorming workspace
.superpowers/

# Local by Flywheel runtime files
/app/
/conf/
/logs/

# Composer / Node artifacts
**/vendor/
**/node_modules/

# OS / editor
.DS_Store
.idea/
.vscode/
*.swp
*~

# Build outputs (added in later F-phases)
**/dist/
**/build/

# Environment files (any package may have its own)
.env
.env.local
**/.env
**/.env.local

# PHPUnit / test artifacts
.phpunit.result.cache
**/.phpunit.result.cache
coverage.xml
**/coverage.xml
```

> **Why ignore `/app/` (with leading slash)?** It anchors the pattern to the repo root so we only ignore Local's WP install — not, say, a future `packages/something/app/` subdirectory.

- [ ] **Step 3: Create README.md at repo root**

Write `/Users/pradeep/Local Sites/defynWP/README.md`:

```markdown
# DefynWP

A ManageWP-style multi-site WordPress management platform.

## Project layout

| Path | Contents |
|---|---|
| `docs/` | Specs and implementation plans |
| `packages/dashboard-plugin/` | The WordPress plugin powering the dashboard backend |
| `packages/connector-plugin/` | (added in F4) The plugin installed on each managed WP site |
| `packages/web/` | (added in F3) The React SPA (`app.defyn.dev`) |
| `app/`, `conf/`, `logs/` | Local by Flywheel runtime — gitignored |

## Local development

1. Install [Local by Flywheel](https://localwp.com/)
2. Create a Local site at this directory (PHP 8.2, nginx, MySQL 8.0) — see `docs/superpowers/plans/2026-04-25-defyn-foundation-f1-scaffolding.md` Task 1
3. Symlink the plugin into the WP install (Task 4 of F1 plan)

## Status

F1 (scaffolding) in progress. See `docs/superpowers/plans/`.
```

- [ ] **Step 4: Create the packages directory placeholder**

Run:
```bash
mkdir -p "/Users/pradeep/Local Sites/defynWP/packages"
touch "/Users/pradeep/Local Sites/defynWP/packages/.gitkeep"
```

- [ ] **Step 5: Verify what git sees**

Run:
```bash
cd "/Users/pradeep/Local Sites/defynWP" && git status --short
```

Expected output (order may differ; `app/`, `conf/`, `logs/` should NOT appear):
```
 M .gitignore
?? README.md
?? packages/.gitkeep
```

If `app/` or `logs/` show up as untracked, the .gitignore patterns aren't matching — recheck Step 2.

- [ ] **Step 6: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git add .gitignore README.md packages/.gitkeep && git commit -m "$(cat <<'EOF'
F1: Set up monorepo structure, .gitignore for Local runtime

Adds packages/ placeholder, README, and .gitignore patterns so Local by
Flywheel's app/conf/logs directories never enter version control.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: Initialize the dashboard-plugin package with Composer

**Why:** the plugin needs Composer for PSR-4 autoloading + dev dependencies (PHPUnit, wp-phpunit). All later tasks rely on `composer install` having run.

**Files:**
- Create: `packages/dashboard-plugin/composer.json`

- [ ] **Step 1: Create the package directory**

```bash
mkdir -p "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin/src/Schema"
mkdir -p "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin/tests/Unit"
mkdir -p "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin/tests/Integration"
```

- [ ] **Step 2: Write composer.json**

Write `packages/dashboard-plugin/composer.json`:

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
    "minimum-stability": "stable"
}
```

> **Why `johnpbloch/wordpress-core` as dev dep?** It installs WP core into `vendor/wordpress/` so wp-phpunit's bootstrap has something to load. In production WP is provided by the host install — we never load vendor's WP at runtime. The `wordpress-install-dir` extra config pins the install path so we can reference it in `wp-tests-config.php`.

- [ ] **Step 3: Run `composer install`**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && composer install
```

Expected: composer downloads dependencies into `vendor/`. Last line should be `Generating autoload files`. Should complete in 10–60 seconds.

If you see "Your requirements could not be resolved" — usually a PHP version mismatch. Check `php --version`; must be 7.4+.

- [ ] **Step 4: Verify autoload works**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && php -r "require 'vendor/autoload.php'; echo 'OK', PHP_EOL;"
```

Expected: prints `OK`. If "Class not found" or fatal error, autoload mapping in composer.json is broken — re-check Step 2.

- [ ] **Step 5: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git add packages/dashboard-plugin/composer.json packages/dashboard-plugin/composer.lock && git commit -m "$(cat <<'EOF'
F1: Initialize dashboard-plugin Composer package

PSR-4 autoload mapping for Defyn\Dashboard namespace. Dev dependencies:
PHPUnit, wp-phpunit, polyfills, and roots/wordpress for the test harness.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Create the plugin bootstrap file + symlink into WP

**Why:** until WordPress can see and activate the plugin, no later task can run. This is the smallest possible plugin file that WP recognizes.

**Files:**
- Create: `packages/dashboard-plugin/defyn-dashboard.php`
- Create: `packages/dashboard-plugin/src/Plugin.php`

- [ ] **Step 1: Write the main plugin file**

Write `packages/dashboard-plugin/defyn-dashboard.php`:

```php
<?php
/**
 * Plugin Name:       DefynWP Dashboard
 * Plugin URI:        https://defyn.dev
 * Description:       Central dashboard for managing multiple WordPress sites — the backend brain.
 * Version:           0.1.0
 * Requires at least: 5.5
 * Requires PHP:      7.4
 * Author:            DefynWP
 * License:           Proprietary
 * Text Domain:       defyn-dashboard
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

// Composer autoloader (vendor is sibling of this file)
$autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>DefynWP Dashboard:</strong> Composer dependencies missing. ';
        echo 'Run <code>composer install</code> in the plugin directory.';
        echo '</p></div>';
    });
    return;
}
require_once $autoload;

// Constants used throughout the plugin
define('DEFYN_DASHBOARD_VERSION', '0.1.0');
define('DEFYN_DASHBOARD_FILE', __FILE__);
define('DEFYN_DASHBOARD_DIR', __DIR__);

// Boot
\Defyn\Dashboard\Plugin::instance()->boot();
```

- [ ] **Step 2: Write the bootstrap class**

Write `packages/dashboard-plugin/src/Plugin.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard;

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
    }
}
```

> Activation::class is referenced but not yet created — Task 5 creates it. We define the wiring first so we can verify "plugin loads without fatal error" before adding logic.

- [ ] **Step 3: Symlink the package into the WP install**

```bash
ln -sfn \
  "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" \
  "/Users/pradeep/Local Sites/defynWP/app/public/wp-content/plugins/defyn-dashboard"
```

Verify:
```bash
ls -la "/Users/pradeep/Local Sites/defynWP/app/public/wp-content/plugins/defyn-dashboard"
```

Expected: shows it as a symlink pointing to the packages directory.

- [ ] **Step 4: Verify plugin appears in WP admin (manual check)**

In Local app, open WP Admin → **Plugins**. Look for **DefynWP Dashboard** in the list. It should show as deactivated.

**Do not activate yet** — Activation::activate doesn't exist; activating now will fatal.

- [ ] **Step 5: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git add packages/dashboard-plugin/defyn-dashboard.php packages/dashboard-plugin/src/Plugin.php && git commit -m "$(cat <<'EOF'
F1: Add plugin bootstrap (defyn-dashboard.php + Plugin singleton)

Plugin appears in WP admin but is intentionally inert until Activation
class is added in the next task. Symlink wiring documented in README.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: Create the Activation class (initially empty)

**Why:** the plugin file references `Defyn\Dashboard\Activation::activate`. We need that class to exist (even if empty) so activating doesn't fatal — then we'll TDD the table-creation behavior in Tasks 7–9.

**Files:**
- Create: `packages/dashboard-plugin/src/Activation.php`

- [ ] **Step 1: Write the empty activation class**

Write `packages/dashboard-plugin/src/Activation.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard;

/**
 * Runs on plugin activation. Creates the three custom tables required by spec § 4.1.
 *
 * Schema definitions live in Schema\*Table classes; this class orchestrates
 * the dbDelta calls and manages the schema version option.
 */
final class Activation
{
    public const SCHEMA_VERSION = 1;
    public const SCHEMA_OPTION  = 'defyn_dashboard_schema_version';

    public static function activate(): void
    {
        // Tasks 7–9 fill this in.
    }
}
```

- [ ] **Step 2: Activate the plugin in WP admin (manual)**

Local app → WP Admin → Plugins → click **Activate** under **DefynWP Dashboard**.

Expected: success message appears, no fatal. Plugin shows as **Active**.

If you see a fatal — re-check that `composer install` ran (Task 3 Step 3) and the symlink exists (Task 4 Step 3).

- [ ] **Step 3: Deactivate the plugin (manual)**

In Plugins page, click **Deactivate**. Plugin should deactivate without error.

- [ ] **Step 4: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git add packages/dashboard-plugin/src/Activation.php && git commit -m "$(cat <<'EOF'
F1: Add empty Activation class so plugin activates without fatal

Schema version constant + option key defined. Table creation logic
TDD'd into this class in subsequent tasks.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: Set up PHPUnit + wp-phpunit + first smoke test

**Why:** every subsequent task is TDD. We need PHPUnit configured and a passing smoke test before we can write failing tests for activation.

**Files:**
- Create: `packages/dashboard-plugin/phpunit.xml`
- Create: `packages/dashboard-plugin/tests/bootstrap.php`
- Create: `packages/dashboard-plugin/tests/Unit/SmokeTest.php`

- [ ] **Step 1: Write phpunit.xml**

Write `packages/dashboard-plugin/phpunit.xml`:

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

- [ ] **Step 2: Write the test bootstrap**

Write `packages/dashboard-plugin/tests/bootstrap.php`:

```php
<?php
/**
 * Test bootstrap.
 *
 * Loads wp-phpunit's WordPress test harness. wp-phpunit ships its own copy of WP core
 * inside vendor/wp-phpunit/wp-phpunit/, but it needs:
 *   1. A WP_PHPUNIT__TESTS_CONFIG file (database creds for the test DB)
 *   2. Our plugin loaded as a "muplugin" so it activates before tests run
 */

declare(strict_types=1);

// Polyfills for PHPUnit version differences.
require_once __DIR__ . '/../vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

// Path to wp-phpunit's bootstrap.
$wp_tests_dir = __DIR__ . '/../vendor/wp-phpunit/wp-phpunit';
if (!file_exists($wp_tests_dir . '/includes/functions.php')) {
    fwrite(STDERR, "wp-phpunit not installed. Run: composer install\n");
    exit(1);
}

require_once $wp_tests_dir . '/includes/functions.php';

// Load our plugin before WP test setup runs activation hooks.
tests_add_filter('muplugins_loaded', static function (): void {
    require __DIR__ . '/../defyn-dashboard.php';
});

// Start the WP test environment.
require $wp_tests_dir . '/includes/bootstrap.php';
```

- [ ] **Step 3: Write the smoke test**

Write `packages/dashboard-plugin/tests/Unit/SmokeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function testToolchainWorks(): void
    {
        self::assertSame(2, 1 + 1);
    }
}
```

- [ ] **Step 4: Configure the WP test database**

wp-phpunit needs a separate MySQL database. The Local site already has one (the WP install uses it), but we want an **isolated test DB** so tests don't trash dev data.

Get Local's MySQL connection details: in Local app, click the site → **Database** tab → note **Host**, **Port**, **User**, **Password**, **Database** name.

Create a test database. Open Local's MySQL shell (Database tab → **Open Adminer** is easiest):

Run this SQL:
```sql
CREATE DATABASE defyn_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON defyn_test.* TO 'root'@'localhost';
FLUSH PRIVILEGES;
```

> Local typically uses `root` with empty password and a custom socket; if your setup differs, adjust the GRANT.

Now create the wp-tests-config file. Find the template:
```bash
ls "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin/vendor/wp-phpunit/wp-phpunit/"
```

Expected: includes `wp-tests-config-sample.php`. Copy and edit:
```bash
cp "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin/vendor/wp-phpunit/wp-phpunit/wp-tests-config-sample.php" \
   "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin/wp-tests-config.php"
```

Edit `packages/dashboard-plugin/wp-tests-config.php`. Set these constants (leave others at defaults):
```php
define('ABSPATH', __DIR__ . '/vendor/wordpress/');
define('WP_TESTS_DOMAIN', 'defyn.test');
define('WP_TESTS_EMAIL', 'admin@defyn.test');
define('WP_TESTS_TITLE', 'DefynWP Tests');
define('WP_PHP_BINARY', 'php');

define('DB_NAME', 'defyn_test');
define('DB_USER', 'root');
define('DB_PASSWORD', '');           // adjust to match Local's MySQL
define('DB_HOST', '127.0.0.1:10006'); // adjust port from Local's Database tab
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

$table_prefix = 'wptests_';
```

> ⚠️ This file contains DB credentials — make sure it's gitignored. Add to `.gitignore` if not already covered (next step).

- [ ] **Step 5: Add wp-tests-config.php to .gitignore**

Edit `/Users/pradeep/Local Sites/defynWP/.gitignore` — append at the bottom:
```gitignore

# Test config (per-developer credentials)
**/wp-tests-config.php
```

- [ ] **Step 6: Tell PHPUnit where to find wp-tests-config**

Set the env var. Add this to `phpunit.xml` between `<phpunit ...>` and `<testsuites>`:

```xml
    <php>
        <env name="WP_PHPUNIT__TESTS_CONFIG" value="wp-tests-config.php"/>
    </php>
```

The full `phpunit.xml` should now look like:
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
    <php>
        <env name="WP_PHPUNIT__TESTS_CONFIG" value="wp-tests-config.php"/>
    </php>
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

- [ ] **Step 7: Run PHPUnit (just the unit suite first)**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && ./vendor/bin/phpunit --testsuite unit
```

Expected output:
```
PHPUnit 9.6.x by Sebastian Bergmann ...
.                                                                   1 / 1 (100%)
Time: 00:00.xxx, Memory: ...
OK (1 test, 1 assertion)
```

If the smoke test fails, the toolchain isn't wired correctly — re-check Steps 1–6.

- [ ] **Step 8: Run the integration suite (will be empty but should bootstrap WP)**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && ./vendor/bin/phpunit --testsuite integration
```

Expected: it loads WP test bootstrap (you'll see "Installing..." messages on first run as it sets up the test DB), then says "No tests executed" — that's fine; the integration suite is empty until Task 7.

If you see a connection error, the DB credentials in `wp-tests-config.php` are wrong.

- [ ] **Step 9: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git add packages/dashboard-plugin/phpunit.xml packages/dashboard-plugin/tests/bootstrap.php packages/dashboard-plugin/tests/Unit/SmokeTest.php .gitignore && git commit -m "$(cat <<'EOF'
F1: PHPUnit + wp-phpunit harness with passing smoke test

Configures the unit + integration test suites, polyfills, and the
WP test bootstrap. Per-developer wp-tests-config.php is gitignored.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: TDD `wp_defyn_sites` table creation

**Why:** the spec § 4.1 first table is `wp_defyn_sites`. Following TDD: write the test, run it (it fails), implement, run again (passes), commit.

**Files:**
- Create: `packages/dashboard-plugin/tests/Integration/SitesTableTest.php`
- Create: `packages/dashboard-plugin/src/Schema/SitesTable.php`
- Modify: `packages/dashboard-plugin/src/Activation.php`

- [ ] **Step 1: Write the failing test**

Write `packages/dashboard-plugin/tests/Integration/SitesTableTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration;

use Defyn\Dashboard\Activation;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class SitesTableTest extends WP_UnitTestCase
{
    public function testActivationCreatesSitesTable(): void
    {
        global $wpdb;

        // wp-phpunit may have already-activated state — drop the table first to ensure clean slate.
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}defyn_sites");
        delete_option(Activation::SCHEMA_OPTION);

        Activation::activate();

        $tableName = $wpdb->prefix . 'defyn_sites';
        $found     = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $tableName)
        );

        self::assertSame($tableName, $found, "Table {$tableName} was not created on activation");
    }

    public function testSitesTableHasRequiredColumns(): void
    {
        global $wpdb;

        Activation::activate();

        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}defyn_sites", 0);

        // Spec § 4.1 — required columns
        $required = [
            'id', 'user_id', 'url', 'label', 'status',
            'site_public_key', 'our_public_key', 'our_private_key',
            'wp_version', 'php_version', 'active_theme',
            'plugin_counts', 'theme_counts',
            'ssl_status', 'ssl_expires_at',
            'last_contact_at', 'last_sync_at', 'last_error',
            'created_at', 'updated_at',
        ];

        foreach ($required as $column) {
            self::assertContains($column, $columns, "Sites table missing column: {$column}");
        }
    }
}
```

- [ ] **Step 2: Run the test — verify it FAILS**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && ./vendor/bin/phpunit --testsuite integration --filter SitesTableTest
```

Expected: FAIL with messages about the table not existing. This is correct — we haven't implemented it yet.

If it passes by accident, the schema-creation logic exists somewhere it shouldn't — investigate before continuing.

- [ ] **Step 3: Write the schema class**

Write `packages/dashboard-plugin/src/Schema/SitesTable.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Schema;

/**
 * Schema for wp_defyn_sites — see spec § 4.1.
 *
 * Returns the dbDelta-compatible CREATE TABLE statement.
 * dbDelta requires: PRIMARY KEY on its own line; two spaces after PRIMARY KEY;
 * uppercase keywords; one column per line.
 */
final class SitesTable
{
    public static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'defyn_sites';
    }

    public static function createSql(): string
    {
        global $wpdb;
        $table   = self::tableName();
        $charset = $wpdb->get_charset_collate();

        return "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            url VARCHAR(255) NOT NULL,
            label VARCHAR(120) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            site_public_key TEXT NULL,
            our_public_key TEXT NULL,
            our_private_key TEXT NULL,
            wp_version VARCHAR(20) NULL,
            php_version VARCHAR(20) NULL,
            active_theme LONGTEXT NULL,
            plugin_counts LONGTEXT NULL,
            theme_counts LONGTEXT NULL,
            ssl_status VARCHAR(20) NOT NULL DEFAULT 'unknown',
            ssl_expires_at DATETIME NULL,
            last_contact_at DATETIME NULL,
            last_sync_at DATETIME NULL,
            last_error TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY status (status),
            KEY user_url (user_id, url(191))
        ) {$charset};";
    }
}
```

> **dbDelta gotchas baked in:** `PRIMARY KEY  (id)` has two spaces (dbDelta's quirky parser requires it). JSON-storing columns use `LONGTEXT` (we'll JSON-encode in the Model layer in F2+); `url(191)` index prefix avoids MySQL's 767-byte index limit on utf8mb4.

- [ ] **Step 4: Wire up Activation::activate to call dbDelta**

Edit `packages/dashboard-plugin/src/Activation.php`. Replace its full contents with:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard;

use Defyn\Dashboard\Schema\SitesTable;

/**
 * Runs on plugin activation. Creates the three custom tables required by spec § 4.1.
 *
 * Schema definitions live in Schema\*Table classes; this class orchestrates
 * the dbDelta calls and manages the schema version option.
 */
final class Activation
{
    public const SCHEMA_VERSION = 1;
    public const SCHEMA_OPTION  = 'defyn_dashboard_schema_version';

    public static function activate(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta(SitesTable::createSql());

        update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION);
    }
}
```

- [ ] **Step 5: Run the test — verify it PASSES**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && ./vendor/bin/phpunit --testsuite integration --filter SitesTableTest
```

Expected:
```
OK (2 tests, 22 assertions)
```

If a column is missing, compare your `createSql()` output to the spec § 4.1 table.

- [ ] **Step 6: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git add packages/dashboard-plugin/src/Schema/SitesTable.php packages/dashboard-plugin/src/Activation.php packages/dashboard-plugin/tests/Integration/SitesTableTest.php && git commit -m "$(cat <<'EOF'
F1: TDD wp_defyn_sites table creation on activation

Schema matches spec § 4.1 exactly. Includes user_id and status indexes
for the queries we'll write in F8 (sites list with status filter).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 8: TDD `wp_defyn_connection_codes` table creation

**Why:** spec § 4.1 second table. Same TDD pattern.

**Files:**
- Create: `packages/dashboard-plugin/tests/Integration/ConnectionCodesTableTest.php`
- Create: `packages/dashboard-plugin/src/Schema/ConnectionCodesTable.php`
- Modify: `packages/dashboard-plugin/src/Activation.php`

- [ ] **Step 1: Write the failing test**

Write `packages/dashboard-plugin/tests/Integration/ConnectionCodesTableTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration;

use Defyn\Dashboard\Activation;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class ConnectionCodesTableTest extends WP_UnitTestCase
{
    public function testActivationCreatesConnectionCodesTable(): void
    {
        global $wpdb;

        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}defyn_connection_codes");
        delete_option(Activation::SCHEMA_OPTION);

        Activation::activate();

        $tableName = $wpdb->prefix . 'defyn_connection_codes';
        $found     = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $tableName)
        );

        self::assertSame($tableName, $found);
    }

    public function testConnectionCodesTableHasRequiredColumns(): void
    {
        global $wpdb;

        Activation::activate();

        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}defyn_connection_codes", 0);

        $required = ['code', 'site_url', 'site_nonce', 'expires_at', 'consumed_at', 'created_at'];

        foreach ($required as $column) {
            self::assertContains($column, $columns, "Connection codes table missing column: {$column}");
        }
    }
}
```

- [ ] **Step 2: Run the test — verify it FAILS**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && ./vendor/bin/phpunit --testsuite integration --filter ConnectionCodesTableTest
```

Expected: FAIL — table doesn't exist.

- [ ] **Step 3: Write the schema class**

Write `packages/dashboard-plugin/src/Schema/ConnectionCodesTable.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Schema;

/**
 * Schema for wp_defyn_connection_codes — see spec § 4.1.
 * Short-lived handshake tokens with 15-minute expiry.
 */
final class ConnectionCodesTable
{
    public static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'defyn_connection_codes';
    }

    public static function createSql(): string
    {
        global $wpdb;
        $table   = self::tableName();
        $charset = $wpdb->get_charset_collate();

        return "CREATE TABLE {$table} (
            code VARCHAR(32) NOT NULL,
            site_url VARCHAR(255) NOT NULL,
            site_nonce VARCHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            consumed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (code),
            KEY expires_at (expires_at)
        ) {$charset};";
    }
}
```

> The `expires_at` index supports the hourly cleanup job (`defyn_cleanup_expired_codes`, spec § 6.3) which queries by expiry.

- [ ] **Step 4: Wire it into Activation**

Edit `packages/dashboard-plugin/src/Activation.php`. Replace the `activate()` method body so the file becomes:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard;

use Defyn\Dashboard\Schema\ConnectionCodesTable;
use Defyn\Dashboard\Schema\SitesTable;

final class Activation
{
    public const SCHEMA_VERSION = 1;
    public const SCHEMA_OPTION  = 'defyn_dashboard_schema_version';

    public static function activate(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta(SitesTable::createSql());
        dbDelta(ConnectionCodesTable::createSql());

        update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION);
    }
}
```

- [ ] **Step 5: Run the test — verify it PASSES**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && ./vendor/bin/phpunit --testsuite integration --filter ConnectionCodesTableTest
```

Expected: `OK (2 tests, 7 assertions)`.

- [ ] **Step 6: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git add packages/dashboard-plugin/src/Schema/ConnectionCodesTable.php packages/dashboard-plugin/src/Activation.php packages/dashboard-plugin/tests/Integration/ConnectionCodesTableTest.php && git commit -m "$(cat <<'EOF'
F1: TDD wp_defyn_connection_codes table

Includes expires_at index for the hourly cleanup job (spec § 6.3).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 9: TDD `wp_defyn_activity_log` table creation

**Why:** third and last table from spec § 4.1.

**Files:**
- Create: `packages/dashboard-plugin/tests/Integration/ActivityLogTableTest.php`
- Create: `packages/dashboard-plugin/src/Schema/ActivityLogTable.php`
- Modify: `packages/dashboard-plugin/src/Activation.php`

- [ ] **Step 1: Write the failing test**

Write `packages/dashboard-plugin/tests/Integration/ActivityLogTableTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration;

use Defyn\Dashboard\Activation;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class ActivityLogTableTest extends WP_UnitTestCase
{
    public function testActivationCreatesActivityLogTable(): void
    {
        global $wpdb;

        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}defyn_activity_log");
        delete_option(Activation::SCHEMA_OPTION);

        Activation::activate();

        $tableName = $wpdb->prefix . 'defyn_activity_log';
        $found     = $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $tableName)
        );

        self::assertSame($tableName, $found);
    }

    public function testActivityLogTableHasRequiredColumns(): void
    {
        global $wpdb;

        Activation::activate();

        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}defyn_activity_log", 0);

        $required = ['id', 'user_id', 'site_id', 'event_type', 'details', 'ip_address', 'created_at'];

        foreach ($required as $column) {
            self::assertContains($column, $columns, "Activity log table missing column: {$column}");
        }
    }
}
```

- [ ] **Step 2: Run the test — verify it FAILS**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && ./vendor/bin/phpunit --testsuite integration --filter ActivityLogTableTest
```

Expected: FAIL — table doesn't exist.

- [ ] **Step 3: Write the schema class**

Write `packages/dashboard-plugin/src/Schema/ActivityLogTable.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Schema;

/**
 * Schema for wp_defyn_activity_log — see spec § 4.1.
 * Audit trail for every meaningful event.
 */
final class ActivityLogTable
{
    public static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'defyn_activity_log';
    }

    public static function createSql(): string
    {
        global $wpdb;
        $table   = self::tableName();
        $charset = $wpdb->get_charset_collate();

        return "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NULL,
            site_id BIGINT UNSIGNED NULL,
            event_type VARCHAR(64) NOT NULL,
            details LONGTEXT NULL,
            ip_address VARCHAR(45) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY site_id (site_id),
            KEY event_type (event_type),
            KEY created_at (created_at)
        ) {$charset};";
    }
}
```

- [ ] **Step 4: Wire it into Activation**

Edit `packages/dashboard-plugin/src/Activation.php` so its full contents become:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard;

use Defyn\Dashboard\Schema\ActivityLogTable;
use Defyn\Dashboard\Schema\ConnectionCodesTable;
use Defyn\Dashboard\Schema\SitesTable;

final class Activation
{
    public const SCHEMA_VERSION = 1;
    public const SCHEMA_OPTION  = 'defyn_dashboard_schema_version';

    public static function activate(): void
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta(SitesTable::createSql());
        dbDelta(ConnectionCodesTable::createSql());
        dbDelta(ActivityLogTable::createSql());

        update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION);
    }
}
```

- [ ] **Step 5: Run the test — verify it PASSES**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && ./vendor/bin/phpunit --testsuite integration --filter ActivityLogTableTest
```

Expected: `OK (2 tests, 8 assertions)`.

- [ ] **Step 6: Run the entire integration suite — verify nothing regressed**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && ./vendor/bin/phpunit --testsuite integration
```

Expected: all 6 tests pass (2 sites + 2 connection_codes + 2 activity_log).

- [ ] **Step 7: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git add packages/dashboard-plugin/src/Schema/ActivityLogTable.php packages/dashboard-plugin/src/Activation.php packages/dashboard-plugin/tests/Integration/ActivityLogTableTest.php && git commit -m "$(cat <<'EOF'
F1: TDD wp_defyn_activity_log table

Indexes on user_id, site_id, event_type, created_at — supporting all
filter patterns the activity log endpoint needs in F9.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 10: TDD activation idempotency

**Why:** WP can call activation hooks multiple times (network activate, reactivate, plugin updates). Activation must be safe to run twice. dbDelta is idempotent by design, but the schema-version write also needs to be safe — and we should pin this behavior with a test so future changes don't break it.

**Files:**
- Modify: `packages/dashboard-plugin/tests/Integration/SitesTableTest.php` (add idempotency test)

- [ ] **Step 1: Add a failing test for idempotency**

Open `packages/dashboard-plugin/tests/Integration/SitesTableTest.php` and add this test method to the class (after the existing methods):

```php
    public function testActivationIsIdempotent(): void
    {
        global $wpdb;

        // First activation
        Activation::activate();
        $firstSchemaVersion = (int) get_option(Activation::SCHEMA_OPTION);

        // Insert a row to make sure activation doesn't drop existing data
        $wpdb->insert(
            $wpdb->prefix . 'defyn_sites',
            [
                'user_id'    => 1,
                'url'        => 'https://example.test',
                'label'      => 'Test',
                'status'     => 'pending',
                'ssl_status' => 'unknown',
                'created_at' => current_time('mysql', true),
                'updated_at' => current_time('mysql', true),
            ]
        );
        $rowsBefore = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}defyn_sites"
        );

        // Second activation — must not throw, must not lose data
        Activation::activate();
        $secondSchemaVersion = (int) get_option(Activation::SCHEMA_OPTION);

        $rowsAfter = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}defyn_sites"
        );

        self::assertSame($firstSchemaVersion, $secondSchemaVersion, 'Schema version should stay the same on repeated activation');
        self::assertSame($rowsBefore, $rowsAfter, 'Existing rows must not be lost on repeated activation');
        self::assertGreaterThan(0, $rowsAfter, 'Sanity: row was inserted');
    }
```

- [ ] **Step 2: Run the test**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && ./vendor/bin/phpunit --testsuite integration --filter testActivationIsIdempotent
```

Expected: PASS — `dbDelta` is idempotent and our `update_option` call only touches the version, not the data. The test still adds value because it pins the behavior.

If it FAILS — investigate. Most likely cause: the schema CREATE statement was changed in a way that drops data. Fix `Schema/*Table.php` and retest.

- [ ] **Step 3: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git add packages/dashboard-plugin/tests/Integration/SitesTableTest.php && git commit -m "$(cat <<'EOF'
F1: Pin activation idempotency with a regression test

Inserts a row, re-runs activation, verifies row + schema version unchanged.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 11: TDD uninstall drops tables

**Why:** when the operator removes the plugin entirely (Plugins → Delete), we should clean up our tables. WP automatically loads `uninstall.php` from the plugin root for this purpose.

**Files:**
- Create: `packages/dashboard-plugin/uninstall.php`
- Create: `packages/dashboard-plugin/src/Uninstaller.php`
- Create: `packages/dashboard-plugin/tests/Integration/UninstallTest.php`

- [ ] **Step 1: Write the failing test**

Write `packages/dashboard-plugin/tests/Integration/UninstallTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Uninstaller;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class UninstallTest extends WP_UnitTestCase
{
    public function testUninstallDropsAllTables(): void
    {
        global $wpdb;

        // Make sure tables exist first
        Activation::activate();

        $tables = [
            $wpdb->prefix . 'defyn_sites',
            $wpdb->prefix . 'defyn_connection_codes',
            $wpdb->prefix . 'defyn_activity_log',
        ];

        foreach ($tables as $t) {
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t));
            self::assertSame($t, $found, "Pre-condition failed: {$t} should exist before uninstall");
        }

        Uninstaller::uninstall();

        foreach ($tables as $t) {
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t));
            self::assertNull($found, "Table {$t} should not exist after uninstall");
        }
    }

    public function testUninstallRemovesSchemaVersionOption(): void
    {
        Activation::activate();
        self::assertSame(Activation::SCHEMA_VERSION, (int) get_option(Activation::SCHEMA_OPTION));

        Uninstaller::uninstall();

        self::assertFalse(get_option(Activation::SCHEMA_OPTION), 'Schema version option should be deleted');
    }
}
```

- [ ] **Step 2: Run the test — verify it FAILS**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && ./vendor/bin/phpunit --testsuite integration --filter UninstallTest
```

Expected: FAIL — `Uninstaller` class doesn't exist yet.

- [ ] **Step 3: Write the Uninstaller class**

Write `packages/dashboard-plugin/src/Uninstaller.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard;

use Defyn\Dashboard\Schema\ActivityLogTable;
use Defyn\Dashboard\Schema\ConnectionCodesTable;
use Defyn\Dashboard\Schema\SitesTable;

/**
 * Removes all DefynWP Dashboard data when the plugin is uninstalled (deleted).
 * Triggered by WP via uninstall.php in the plugin root.
 */
final class Uninstaller
{
    public static function uninstall(): void
    {
        global $wpdb;

        $tables = [
            ActivityLogTable::tableName(),
            ConnectionCodesTable::tableName(),
            SitesTable::tableName(),
        ];

        foreach ($tables as $table) {
            // phpcs:ignore WordPress.DB.PreparedSQL — table names can't be parameterized
            $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
        }

        delete_option(Activation::SCHEMA_OPTION);
    }
}
```

- [ ] **Step 4: Write the uninstall.php entrypoint**

Write `packages/dashboard-plugin/uninstall.php`:

```php
<?php
/**
 * Triggered by WordPress when the plugin is uninstalled (deleted via Plugins → Delete).
 * Loaded with WPINC defined but no plugin code — we have to load Composer + our class manually.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    return; // Composer wasn't installed — nothing we can clean up safely.
}
require_once $autoload;

\Defyn\Dashboard\Uninstaller::uninstall();
```

- [ ] **Step 5: Run the test — verify it PASSES**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && ./vendor/bin/phpunit --testsuite integration --filter UninstallTest
```

Expected: `OK (2 tests, 7 assertions)`.

- [ ] **Step 6: Run all tests — make sure nothing else broke**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && ./vendor/bin/phpunit
```

Expected: all tests pass (1 unit + 7 integration = 8 tests).

> If the integration tests later in the run start failing because the tables were dropped earlier — that's an expected ordering issue. PHPUnit tests in this suite each call `Activation::activate()` first, so order shouldn't matter. If you see this, double-check each test starts by activating.

- [ ] **Step 7: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git add packages/dashboard-plugin/uninstall.php packages/dashboard-plugin/src/Uninstaller.php packages/dashboard-plugin/tests/Integration/UninstallTest.php && git commit -m "$(cat <<'EOF'
F1: TDD plugin uninstall — drops tables and clears schema version

uninstall.php delegates to Uninstaller class; works whether composer
deps are present or absent (degrades gracefully if vendor missing).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

### Task 12: GitHub Actions CI

**Why:** every commit and PR should run the test suite. F1 ships with green CI so we know the plan is reproducible from a clean checkout.

**Files:**
- Create: `.github/workflows/test.yml`

- [ ] **Step 1: Create the workflow file**

```bash
mkdir -p "/Users/pradeep/Local Sites/defynWP/.github/workflows"
```

Write `/Users/pradeep/Local Sites/defynWP/.github/workflows/test.yml`:

```yaml
name: Test

on:
  push:
    branches: [main]
  pull_request:

jobs:
  dashboard-plugin:
    name: dashboard-plugin (PHP ${{ matrix.php }})
    runs-on: ubuntu-latest
    strategy:
      fail-fast: false
      matrix:
        php: ['7.4', '8.2']

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
        working-directory: packages/dashboard-plugin

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
          path: packages/dashboard-plugin/vendor
          key: composer-${{ matrix.php }}-${{ hashFiles('packages/dashboard-plugin/composer.lock') }}

      - name: Install Composer dependencies
        run: composer install --prefer-dist --no-progress

      - name: Wait for MySQL
        run: |
          for i in {1..30}; do
            mysqladmin ping -h 127.0.0.1 -uroot --silent && break
            sleep 1
          done

      - name: Set up wp-tests-config.php
        run: |
          cp vendor/wp-phpunit/wp-phpunit/wp-tests-config-sample.php wp-tests-config.php
          sed -i "s|youremptytestdbnamehere|defyn_test|g"   wp-tests-config.php
          sed -i "s|yourusernamehere|root|g"                 wp-tests-config.php
          sed -i "s|yourpasswordhere||g"                     wp-tests-config.php
          sed -i "s|localhost|127.0.0.1|g"                   wp-tests-config.php

      - name: Run PHPUnit
        env:
          WP_PHPUNIT__TESTS_CONFIG: wp-tests-config.php
        run: ./vendor/bin/phpunit
```

> **Why PHP 7.4 + 8.2 matrix?** 7.4 is our `composer.json` floor; 8.2 is what we deploy with on Kinsta. Catching breakage on either end early is cheap.

- [ ] **Step 2: Commit and push to a feature branch**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git add .github/workflows/test.yml && git commit -m "$(cat <<'EOF'
F1: GitHub Actions CI — runs PHPUnit on push + PR

Matrix on PHP 7.4 (composer.json floor) and 8.2 (production target).
MySQL 8.0 service mirrors Kinsta.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

> **Note on pushing:** if no remote is configured yet, `git push` will fail. Add a remote (e.g. `git remote add origin <github url>`) and push. Set up the GitHub repo if you haven't yet — push the `main` branch first, then create a PR for an unrelated branch later to verify CI runs both on push and PR.

- [ ] **Step 3: Verify CI passes (manual — wait for GitHub)**

Open the GitHub repo's Actions tab. The Test workflow should run. Expected result: green check on both `7.4` and `8.2` jobs.

If a job fails, read the log. Most common issues:
- `wp-tests-config.php` path/credentials wrong — re-check the `sed` replacements
- `mysqladmin ping` not found — Ubuntu runner; should be present, but if not, add `sudo apt-get install -y mysql-client`

Iterate until both jobs are green.

---

### Task 13: F1 acceptance — manual smoke test

**Why:** the unit/integration suite proves the code works. A manual end-to-end check proves the *plugin* works in a real WP install — catching anything the test environment papers over (file permissions, symlink resolution, etc.).

**Files:** none (manual verification + closing notes in this plan)

- [ ] **Step 1: Deactivate then activate the plugin in WP admin**

In Local: WP Admin → Plugins → DefynWP Dashboard → Deactivate, then Activate again. No errors expected.

- [ ] **Step 2: Inspect tables in the dev database**

Local app → site → Database tab → Open Adminer (or use Local's MySQL CLI).

Run:
```sql
SHOW TABLES LIKE 'wp_defyn_%';
```

Expected three rows:
```
wp_defyn_sites
wp_defyn_connection_codes
wp_defyn_activity_log
```

Spot-check the schema:
```sql
DESCRIBE wp_defyn_sites;
```

Expected columns match spec § 4.1 — at minimum `id`, `user_id`, `url`, `status`, `our_private_key`, `created_at`, `updated_at` should appear.

- [ ] **Step 3: Verify schema version option**

```sql
SELECT option_value FROM wp_options WHERE option_name = 'defyn_dashboard_schema_version';
```

Expected: `1`.

- [ ] **Step 4: Test uninstall (this WILL delete dev data)**

In WP Admin → Plugins → DefynWP Dashboard → **Deactivate**. Then **Delete**. Confirm.

> ⚠️ The symlink lives at `app/public/wp-content/plugins/defyn-dashboard`. After Delete, WP will try to remove the symlink — that's fine, it doesn't touch the source files in `packages/`. But you'll need to re-create the symlink (Task 4 Step 3) to keep developing.

After delete, in Adminer:
```sql
SHOW TABLES LIKE 'wp_defyn_%';
```

Expected: zero rows. Tables gone.

```sql
SELECT * FROM wp_options WHERE option_name = 'defyn_dashboard_schema_version';
```

Expected: zero rows.

- [ ] **Step 5: Re-create the symlink and re-activate**

```bash
ln -sfn \
  "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" \
  "/Users/pradeep/Local Sites/defynWP/app/public/wp-content/plugins/defyn-dashboard"
```

WP Admin → Plugins → Activate. Tables come back.

- [ ] **Step 6: Tag F1 complete**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git tag -a f1-scaffolding-complete -m "F1: Scaffolding complete — plugin activates, tables created, tests + CI green."
```

> Use a tag (not a release) — F1 isn't user-visible and doesn't have a public artifact.

---

## F1 verification checklist (definition of done)

Before declaring F1 complete and moving to F2, verify all of these:

- [ ] Plugin activates and deactivates in WP admin without errors
- [ ] Three custom tables exist after activation (verified in Adminer)
- [ ] All three tables have the columns from spec § 4.1
- [ ] Activation is idempotent (running twice doesn't error or lose data) — pinned by test
- [ ] Uninstall drops all three tables + the schema version option — pinned by test
- [ ] PHPUnit runs locally (1 unit + 7 integration = 8 tests, all pass)
- [ ] GitHub Actions CI runs on push + PR, both PHP 7.4 and 8.2 jobs are green
- [ ] `f1-scaffolding-complete` git tag exists

When all boxes are checked, F1 is done. Re-invoke `superpowers:writing-plans` for F2.

---

## F2–F10 Roadmap (NOT detailed in this plan)

Each phase below produces working, testable software and gets its own dedicated plan when its predecessor ships. **Do not implement these from this document — invoke writing-plans for each in turn.**

| Phase | Deliverable | Approximate task count |
|---|---|---|
| **F2** | Crypto primitives: `KeyPair`, `Signer`, `Vault` classes with unit tests for sign/verify/tamper-detection/replay-rejection. No HTTP. | ~10 tasks |
| **F3** | Dashboard auth REST + SPA login: JWT issue/refresh/logout/me endpoints, rate limiter; SPA scaffold (Vite + shadcn) with working login flow. | ~15 tasks |
| **F4** | Connector plugin scaffold + `POST /connect`: separate plugin package at `packages/connector-plugin/`, "Generate Connection Code" admin UI, code validation only (no crypto response yet). | ~10 tasks |
| **F5** | Handshake end-to-end: dashboard `POST /sites` → Action Scheduler `defyn_complete_connection` job → connector `/connect` → challenge-response verified → site flips `pending → active`. SPA Add Site form works. | ~12 tasks |
| **F6** | Signed `/status` + `/heartbeat`: `VerifySignature` middleware on connector; `SyncService` + `HealthService` on dashboard. First successful sync populates site info. | ~10 tasks |
| **F7** | Background scheduling: recurring AS jobs (`defyn_sync_all_sites` every 30 min, `defyn_health_ping_all` every 5 min), `defyn_cleanup_expired_codes` hourly. Kinsta server cron verified. | ~6 tasks |
| **F8** | SPA sites list + detail: filter + search, cached info display, action buttons (Refresh, Ping, Disconnect). | ~12 tasks |
| **F9** | Activity log: endpoints + SPA page, per-site activity on detail page. | ~7 tasks |
| **F10** | Deploy + harden: Bedrock layout for production, Kinsta provisioning, Cloudflare Pages SPA deploy, CORS/HTTPS/rate-limit verification in prod, manual E2E against a real WP site. | ~10 tasks |

When F1 ships and you start F2, the writing-plans skill will produce a plan with the same level of bite-sized detail as this F1 plan.
