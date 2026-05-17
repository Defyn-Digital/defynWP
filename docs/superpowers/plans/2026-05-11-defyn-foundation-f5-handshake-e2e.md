# DefynWP Foundation F5 — Handshake End-to-End Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up the full plugin-first connection handshake — user pastes a connector-generated code into the SPA's Add Site form; the dashboard creates a pending site, schedules an Action Scheduler job, the job POSTs to the connector's `/connect`, the connector signs a challenge with its K_site private key, the dashboard verifies the signature, and the site row flips `pending → active`. Manual smoke E2E (Local connector ↔ dev dashboard ↔ Vite SPA) is the proof.

**Architecture:** F5 spans three runtimes:
1. **Dashboard plugin** — adds `POST /defyn/v1/sites` (creates pending site + schedules AS job), `GET /defyn/v1/sites/{id}` (polled by SPA), `GET /defyn/v1/sites` (list). The AS job calls the connector via a `SignedHttpClient` (plain POST in F5; X-Defyn-* signing added in F6). The handshake logic itself lives in a `Connection` service that takes `SignedHttpClient` via constructor (so tests can mock the HTTP without involving Action Scheduler).
2. **Connector plugin** — extends `ConnectController` to accept `dashboard_public_key` + `callback_challenge` alongside the code, signs the challenge with K_site, returns the full handshake response. State machine gains `connected` terminal.
3. **SPA** — three new routes (`/sites`, `/sites/add`, `/sites/:id`), TanStack Query hooks for sites, React Hook Form + Zod for the Add Site form, 2-second polling for up to 30s on the detail page.

**Tech Stack additions over F4:** `woocommerce/action-scheduler` (queue), `symfony/http-client` (outbound HTTPS), one new env var `DEFYN_VAULT_KEY` (base64 32-byte sodium secretbox key, used by F2's `Vault`). No new tooling on the SPA — react-router, react-hook-form, zod, @tanstack/react-query all already installed in F3b.

> **Direction notes (verified against codebase before drafting):**
> - **Action Scheduler is NOT installed** in `packages/dashboard-plugin/composer.json` (despite spec § 6.4 listing it). Task 1 installs it.
> - **Symfony http-client is NOT installed** either. Task 1 installs it.
> - **`DEFYN_VAULT_KEY` env var is NOT yet wired** in `defyn-dashboard.php`. Task 2 wires it using the same pattern as `DEFYN_JWT_SECRET`.
> - **`Defyn\Dashboard\Crypto\KeyPair` returns an object** (`->publicKey` / `->privateKey`), while **`Defyn\Connector\Crypto\KeyPair::generate()` returns an array** (`['public_key' => ..., 'private_key' => ...]`). The two APIs are intentionally not unified. Plan respects this.
> - **F4's `testValidCodeReturns200AndMarksCodeConsumed` will be REPLACED in place** (Task 13) — its response-shape and state-transition assertions both change.
> - **No schema migration required**: `wp_defyn_sites` and `wp_defyn_activity_log` (F1 tables) already have every column F5 needs.

---

## About this plan

This is **F5 of the Foundation roadmap**. Built on F1–F4 + the F4 cleanup commit `90aee1a` (which spec-aligned ConnectController's branch order and hardened Activation's idempotency).

**Source spec:** [`docs/superpowers/specs/2026-04-18-defyn-foundation-design.md`](../specs/2026-04-18-defyn-foundation-design.md) — § 8 (10-step handshake), § 6.1 (Sites endpoints), § 6.3 (`defyn_complete_connection` AS job), § 7.1 + 7.2 (SPA Add Site route + auth flow), § 9.1 (error envelope), § 4.1 (table schemas), § 4.2 (connector state shape).

**Definition of "F5 done":**
1. Activating both plugins on a working local stack, an admin can log into the SPA, paste a code generated on the connector, and watch the SPA flip from "Connecting…" to "Connected" without manual intervention.
2. The dashboard's `wp_defyn_sites` row for that site shows `status='active'`, populated `site_public_key`, non-null `last_contact_at`.
3. The dashboard's `wp_defyn_activity_log` has a row with `event_type='site.connected'` for that site.
4. The connector's `wp_options['defyn_connector']` row shows `state='connected'`, populated `dashboard_public_key`, populated `connected_at`.
5. All defyn/v1/* and defyn-connector/v1/* failure responses use the spec § 9.1 envelope `{error: {code, message}}`. New error codes: `sites.missing_fields`, `sites.invalid_url`, `sites.duplicate_url`, `sites.not_found`, `connector.missing_dashboard_key`, `connector.missing_challenge`, `connector.invalid_dashboard_key`.
6. Full PHPUnit suite green: dashboard 89 + ~30 new = ~119 tests; connector 25 + ~6 net = ~31 tests. Full Vitest suite green: 20 existing + ~12 new ≈ 32 tests.
7. CI green on PHP 8.1 + 8.2 and Node 20 + 22.

---

## File structure after F5

```
packages/dashboard-plugin/
├── composer.json                                  # MODIFIED — add Action Scheduler + Symfony http-client
├── defyn-dashboard.php                            # MODIFIED — load DEFYN_VAULT_KEY env; load Action Scheduler
├── src/
│   ├── Plugin.php                                 # MODIFIED — register CompleteConnection AS hook
│   ├── Models/
│   │   └── Site.php                               # NEW — typed read-only DTO for a wp_defyn_sites row
│   ├── Services/
│   │   ├── UrlValidator.php                       # NEW — HTTPS + DNS + duplicate-for-user
│   │   ├── ActivityLogger.php                     # NEW — wpdb wrapper for activity_log inserts
│   │   ├── Connection.php                         # NEW — handshake orchestration (constructor-injected SignedHttpClient)
│   │   └── SitesRepository.php                    # NEW — wpdb wrapper for sites CRUD (find/insert/update)
│   ├── Http/
│   │   └── SignedHttpClient.php                   # NEW — Symfony http-client wrapper; plain POST in F5 (F6 adds signing)
│   ├── Jobs/
│   │   └── CompleteConnection.php                 # NEW — thin static-method AS handler; delegates to Connection
│   ├── Rest/
│   │   ├── RestRouter.php                         # MODIFIED — register 3 new /sites routes
│   │   ├── SitesCreateController.php              # NEW — POST /sites
│   │   ├── SitesShowController.php                # NEW — GET /sites/{id}
│   │   ├── SitesListController.php                # NEW — GET /sites
│   │   └── Responses/ErrorResponse.php            # unchanged
│   ├── Auth/, Crypto/, Schema/                    # unchanged
│   └── (rest unchanged)
└── tests/
    ├── Unit/
    │   ├── Models/SiteTest.php                    # NEW
    │   └── Services/UrlValidatorTest.php          # NEW
    └── Integration/
        ├── Services/{ActivityLoggerTest, ConnectionTest, SitesRepositoryTest}.php  # NEW
        ├── Jobs/CompleteConnectionTest.php        # NEW (calls Connection directly via mocked SignedHttpClient)
        └── Rest/
            ├── SitesCreateTest.php                # NEW
            ├── SitesShowTest.php                  # NEW
            └── SitesListTest.php                  # NEW

packages/connector-plugin/
├── src/
│   ├── Crypto/
│   │   ├── KeyPair.php                            # unchanged
│   │   └── Signer.php                             # NEW — sign challenge with K_site
│   └── Rest/
│       └── ConnectController.php                  # MODIFIED — accept dashboard_public_key + callback_challenge; sign + respond
└── tests/
    ├── Unit/Crypto/SignerTest.php                 # NEW
    └── Integration/Rest/ConnectTest.php           # MODIFIED — update happy path + add 3 new error tests

apps/web/
├── src/
│   ├── types/
│   │   └── api.ts                                 # NEW — Zod schemas for Site + CreateSite
│   ├── lib/
│   │   └── queries/
│   │       ├── useSites.ts                        # NEW — TanStack Query list hook
│   │       └── useSite.ts                         # NEW — TanStack Query single-site hook with polling
│   ├── routes/
│   │   ├── SitesList.tsx                          # NEW
│   │   ├── SiteAdd.tsx                            # NEW
│   │   └── SiteDetail.tsx                         # NEW
│   ├── routes/Home.tsx                            # MODIFIED — redirect to /sites
│   └── App.tsx                                    # MODIFIED — register new routes
└── tests/
    ├── api.test.ts                                # NEW — Zod schema parse tests
    ├── useSites.test.ts                           # NEW
    ├── useSite.test.ts                            # NEW
    ├── SiteAdd.test.tsx                           # NEW
    └── SiteDetail.test.tsx                        # NEW
```

---

## Prerequisites

- Working tree clean on `main`, latest commit `90aee1a` (the F4 cleanup merge).
- Local-by-Flywheel `defynWP` site started; MySQL on `127.0.0.1:10140` (`root`/`root`).
- Branch off main: `git switch -c f5-handshake-e2e`.
- Generate a 32-byte sodium secretbox key for `DEFYN_VAULT_KEY` (Task 2 has the one-liner).

If any of these are missing, fix them first.

---

## Task 1: Install Action Scheduler + Symfony http-client

**Files:**
- Modify: `packages/dashboard-plugin/composer.json`
- Generated: `packages/dashboard-plugin/composer.lock` (committed)

- [ ] **Step 1: Add the two production deps**

```bash
cd packages/dashboard-plugin
composer require woocommerce/action-scheduler:^3.7 symfony/http-client:^6.4
```

Expected: composer pulls Action Scheduler ~3.7 + Symfony http-client ~6.4 (plus its symfony/* transitive deps). `composer.lock` updates.

- [ ] **Step 2: Verify the requires block in composer.json now contains both packages**

Open `packages/dashboard-plugin/composer.json` and confirm the `"require"` section looks like:

```json
"require": {
    "php": ">=8.1",
    "ext-sodium": "*",
    "firebase/php-jwt": "^7.0",
    "symfony/http-client": "^6.4",
    "woocommerce/action-scheduler": "^3.7"
}
```

(composer's `sort-packages: true` config will alphabetize automatically.)

- [ ] **Step 3: Confirm dashboard test suite still green after install**

```bash
./vendor/bin/phpunit
```

Expected: `OK (89 tests, 174 assertions)`. No tests should break — these are pure dep additions.

- [ ] **Step 4: Commit**

```bash
git add packages/dashboard-plugin/composer.json packages/dashboard-plugin/composer.lock
git commit -m "F5: deps — install Action Scheduler 3.7 + Symfony http-client 6.4"
```

---

## Task 2: Wire `DEFYN_VAULT_KEY` env loading + Action Scheduler bootstrap

**Files:**
- Modify: `packages/dashboard-plugin/defyn-dashboard.php`

The `Vault` class (F2) needs a base64-encoded 32-byte key. We load it from env using the same fallback pattern as `DEFYN_JWT_SECRET`. Action Scheduler ships as a library; its `action-scheduler.php` bootstrap file must be required so it self-registers its hooks.

- [ ] **Step 1: Append the env loading + AS bootstrap to `defyn-dashboard.php`**

Open `packages/dashboard-plugin/defyn-dashboard.php`. Below the existing `DEFYN_SPA_ORIGIN` block (around line 54), and ABOVE the final `\Defyn\Dashboard\Plugin::instance()->boot();` line, insert:

```php
// Vault key: required for encrypting per-site dashboard private keys (F5+).
// Plugin still loads if absent — only fatal at endpoints that touch the vault,
// with an admin-notice fallback so the operator can fix config without locking
// themselves out of wp-admin.
if (!defined('DEFYN_VAULT_KEY')) {
    $envVaultKey = getenv('DEFYN_VAULT_KEY');
    if ($envVaultKey !== false && $envVaultKey !== '') {
        define('DEFYN_VAULT_KEY', $envVaultKey);
    }
}

// Action Scheduler: loaded before Plugin::boot() so as_schedule_single_action()
// and the hook system are available when controllers / Plugin::boot() reference them.
// Loading is idempotent — if another plugin loaded AS first (its own copy ships
// inside WooCommerce, for example), this require_once is a no-op.
$asBootstrap = __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
if (file_exists($asBootstrap)) {
    require_once $asBootstrap;
}
```

- [ ] **Step 2: Generate a dev `DEFYN_VAULT_KEY` and add it to the local `wp-config.php`** (manual, NOT committed)

In the Local site's wp-config.php (e.g. `~/Local Sites/defynWP/app/public/wp-config.php`), add ABOVE the `/* That's all, stop editing!` line:

```php
define('DEFYN_VAULT_KEY', '<your key here>');
```

To generate a fresh key, from the dashboard-plugin dir:

```bash
php -r "require 'vendor/autoload.php'; echo \Defyn\Dashboard\Crypto\Vault::generateKey() . PHP_EOL;"
```

Copy the output (a 44-char base64 string) into the `define()`. Same value also needs to be added to `wp-tests-config.php` (gitignored) so integration tests can construct a Vault.

- [ ] **Step 3: Add `DEFYN_VAULT_KEY` to the local `wp-tests-config.php`**

Open `packages/dashboard-plugin/wp-tests-config.php`. Below the existing `define('DB_*` lines and before `$table_prefix`, add:

```php
define('DEFYN_VAULT_KEY', '<the same base64 key as above>');
```

- [ ] **Step 4: Sanity-check suite still passes**

```bash
./vendor/bin/phpunit
```

Expected: 89/174 still green. (No F5 code references `DEFYN_VAULT_KEY` yet, so nothing should change.)

- [ ] **Step 5: Commit (only the .php source change; never commit wp-tests-config.php — it's gitignored)**

```bash
git add packages/dashboard-plugin/defyn-dashboard.php
git commit -m "F5: env — load DEFYN_VAULT_KEY + bootstrap Action Scheduler"
```

---

## Task 3: Models/Site.php — typed read-only DTO

**Files:**
- Create: `packages/dashboard-plugin/src/Models/Site.php`
- Create: `packages/dashboard-plugin/tests/Unit/Models/SiteTest.php`

A thin immutable value object representing one row of `wp_defyn_sites`. `SitesRepository` returns these; controllers serialize them to JSON. F5 only uses a subset of the table columns; later F-phases will add more accessors as needed (YAGNI).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Unit\Models;

use Defyn\Dashboard\Models\Site;
use PHPUnit\Framework\TestCase;

/**
 * @group unit
 */
final class SiteTest extends TestCase
{
    public function testFromRowMapsAllExpectedFields(): void
    {
        $row = [
            'id'              => '42',
            'user_id'         => '7',
            'url'             => 'https://example.test',
            'label'           => 'Test site',
            'status'          => 'pending',
            'site_public_key' => null,
            'our_public_key'  => 'PUBKEY==',
            'last_contact_at' => null,
            'last_error'      => null,
            'created_at'      => '2026-05-11 00:00:00',
        ];

        $site = Site::fromRow($row);

        self::assertSame(42, $site->id);
        self::assertSame(7, $site->userId);
        self::assertSame('https://example.test', $site->url);
        self::assertSame('Test site', $site->label);
        self::assertSame('pending', $site->status);
        self::assertNull($site->sitePublicKey);
        self::assertSame('PUBKEY==', $site->ourPublicKey);
        self::assertNull($site->lastContactAt);
        self::assertNull($site->lastError);
        self::assertSame('2026-05-11 00:00:00', $site->createdAt);
    }

    public function testToJsonProducesSpaShape(): void
    {
        $site = Site::fromRow([
            'id'              => '1',
            'user_id'         => '1',
            'url'             => 'https://example.test',
            'label'           => '',
            'status'          => 'active',
            'site_public_key' => 'SITEPUB==',
            'our_public_key'  => 'OURPUB==',
            'last_contact_at' => '2026-05-11 00:07:00',
            'last_error'      => null,
            'created_at'      => '2026-05-11 00:00:00',
        ]);

        self::assertSame([
            'id'              => 1,
            'url'             => 'https://example.test',
            'label'           => '',
            'status'          => 'active',
            'last_contact_at' => '2026-05-11 00:07:00',
            'last_error'      => null,
            'created_at'      => '2026-05-11 00:00:00',
        ], $site->toJson());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit --testsuite unit --filter SiteTest
```

Expected: "Class Defyn\Dashboard\Models\Site not found".

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Models;

/**
 * Read-only DTO for a row of wp_defyn_sites. Does not expose user_id,
 * our_public_key, our_private_key, or any of the cached info columns
 * (wp_version, plugin_counts, etc.) in toJson — F5 doesn't need them
 * over the wire; F6+ will add them as the sync layer fills the columns.
 */
final class Site
{
    public function __construct(
        public readonly int     $id,
        public readonly int     $userId,
        public readonly string  $url,
        public readonly string  $label,
        public readonly string  $status,
        public readonly ?string $sitePublicKey,
        public readonly ?string $ourPublicKey,
        public readonly ?string $lastContactAt,
        public readonly ?string $lastError,
        public readonly string  $createdAt,
    ) {}

    /** @param array<string, mixed> $row wpdb result row (all values come back as strings) */
    public static function fromRow(array $row): self
    {
        return new self(
            id:             (int) $row['id'],
            userId:         (int) $row['user_id'],
            url:            (string) $row['url'],
            label:          (string) $row['label'],
            status:         (string) $row['status'],
            sitePublicKey:  isset($row['site_public_key']) ? (string) $row['site_public_key'] : null,
            ourPublicKey:   isset($row['our_public_key'])  ? (string) $row['our_public_key']  : null,
            lastContactAt:  isset($row['last_contact_at']) ? (string) $row['last_contact_at'] : null,
            lastError:      isset($row['last_error'])      ? (string) $row['last_error']      : null,
            createdAt:      (string) $row['created_at'],
        );
    }

    /** @return array<string, mixed> shape the SPA receives over the wire */
    public function toJson(): array
    {
        return [
            'id'              => $this->id,
            'url'             => $this->url,
            'label'           => $this->label,
            'status'          => $this->status,
            'last_contact_at' => $this->lastContactAt,
            'last_error'      => $this->lastError,
            'created_at'      => $this->createdAt,
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
./vendor/bin/phpunit --testsuite unit --filter SiteTest
```

Expected: `OK (2 tests, 13 assertions)`.

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Models/Site.php packages/dashboard-plugin/tests/Unit/Models/SiteTest.php
git commit -m "F5: TDD Site DTO — row mapping + SPA JSON shape"
```

---

## Task 4: Services/SitesRepository.php — wpdb wrapper for sites CRUD

**Files:**
- Create: `packages/dashboard-plugin/src/Services/SitesRepository.php`
- Create: `packages/dashboard-plugin/tests/Integration/Services/SitesRepositoryTest.php`

Single class through which controllers + the AS job hit the database. Methods F5 needs: `insertPending`, `findById`, `findByIdForUser`, `findAllForUser`, `existsForUser` (duplicate-URL check), `markActive`, `markError`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\SitesRepository;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class SitesRepositoryTest extends WP_UnitTestCase
{
    private SitesRepository $repo;

    public function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb->query('TRUNCATE ' . SitesTable::tableName());
        $this->repo = new SitesRepository();
    }

    public function testInsertPendingReturnsRowIdAndPersistsAllFields(): void
    {
        $id = $this->repo->insertPending(
            userId: 7,
            url: 'https://example.test',
            label: 'Test',
            ourPublicKey: 'OURPUB==',
            ourPrivateKeyEncrypted: 'ENC==',
        );

        self::assertGreaterThan(0, $id);

        $site = $this->repo->findById($id);
        self::assertNotNull($site);
        self::assertSame(7, $site->userId);
        self::assertSame('https://example.test', $site->url);
        self::assertSame('Test', $site->label);
        self::assertSame('pending', $site->status);
        self::assertSame('OURPUB==', $site->ourPublicKey);
        self::assertNull($site->sitePublicKey);
    }

    public function testFindByIdForUserReturnsSiteForOwner(): void
    {
        $id = $this->repo->insertPending(7, 'https://owner.test', '', 'P', 'E');

        $hit  = $this->repo->findByIdForUser($id, 7);
        $miss = $this->repo->findByIdForUser($id, 999);

        self::assertNotNull($hit);
        self::assertSame($id, $hit->id);
        self::assertNull($miss);
    }

    public function testFindAllForUserReturnsOnlyThatUsersSites(): void
    {
        $this->repo->insertPending(7, 'https://a.test', '', 'P', 'E');
        $this->repo->insertPending(7, 'https://b.test', '', 'P', 'E');
        $this->repo->insertPending(8, 'https://c.test', '', 'P', 'E');

        $sites = $this->repo->findAllForUser(7);

        self::assertCount(2, $sites);
        self::assertSame(['https://a.test', 'https://b.test'], array_map(fn ($s) => $s->url, $sites));
    }

    public function testExistsForUserCheckIsCaseInsensitiveAndUserScoped(): void
    {
        $this->repo->insertPending(7, 'https://Foo.Example', '', 'P', 'E');

        self::assertTrue($this->repo->existsForUser(7, 'https://foo.example'));   // case-insensitive
        self::assertFalse($this->repo->existsForUser(8, 'https://foo.example'));  // user-scoped
        self::assertFalse($this->repo->existsForUser(7, 'https://other.test'));
    }

    public function testMarkActiveUpdatesStatusAndKeysAndContactTimestamp(): void
    {
        $id = $this->repo->insertPending(7, 'https://example.test', '', 'OURPUB', 'OURENC');

        $this->repo->markActive($id, 'SITEPUB==');

        $site = $this->repo->findById($id);
        self::assertSame('active', $site->status);
        self::assertSame('SITEPUB==', $site->sitePublicKey);
        self::assertNotNull($site->lastContactAt);
    }

    public function testMarkErrorUpdatesStatusAndLastError(): void
    {
        $id = $this->repo->insertPending(7, 'https://example.test', '', 'P', 'E');

        $this->repo->markError($id, 'Connector unreachable');

        $site = $this->repo->findById($id);
        self::assertSame('error', $site->status);
        self::assertSame('Connector unreachable', $site->lastError);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit --testsuite integration --filter SitesRepositoryTest
```

Expected: "Class Defyn\Dashboard\Services\SitesRepository not found".

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Models\Site;
use Defyn\Dashboard\Schema\SitesTable;

/**
 * Thin wrapper over wpdb for wp_defyn_sites — the only class that issues
 * raw SQL for that table. Controllers + AS jobs call this; tests assert
 * persistence through it. Other classes never touch wpdb for sites directly.
 */
final class SitesRepository
{
    public function insertPending(
        int    $userId,
        string $url,
        string $label,
        string $ourPublicKey,
        string $ourPrivateKeyEncrypted,
    ): int {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert(
            SitesTable::tableName(),
            [
                'user_id'         => $userId,
                'url'             => $url,
                'label'           => $label,
                'status'          => 'pending',
                'our_public_key'  => $ourPublicKey,
                'our_private_key' => $ourPrivateKeyEncrypted,
                'created_at'      => $now,
                'updated_at'      => $now,
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s'],
        );
        return (int) $wpdb->insert_id;
    }

    public function findById(int $id): ?Site
    {
        global $wpdb;
        $table = SitesTable::tableName();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);
        return $row ? Site::fromRow($row) : null;
    }

    public function findByIdForUser(int $id, int $userId): ?Site
    {
        $site = $this->findById($id);
        if ($site === null || $site->userId !== $userId) {
            return null;
        }
        return $site;
    }

    /** @return list<Site> */
    public function findAllForUser(int $userId): array
    {
        global $wpdb;
        $table = SitesTable::tableName();
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} WHERE user_id = %d ORDER BY id ASC", $userId),
            ARRAY_A,
        );
        return array_map([Site::class, 'fromRow'], $rows ?: []);
    }

    public function existsForUser(int $userId, string $url): bool
    {
        global $wpdb;
        $table = SitesTable::tableName();
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND LOWER(url) = %s",
                $userId,
                strtolower($url),
            ),
        );
        return $count > 0;
    }

    public function markActive(int $id, string $sitePublicKey): void
    {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->update(
            SitesTable::tableName(),
            [
                'status'          => 'active',
                'site_public_key' => $sitePublicKey,
                'last_contact_at' => $now,
                'updated_at'      => $now,
            ],
            ['id' => $id],
            ['%s', '%s', '%s', '%s'],
            ['%d'],
        );
    }

    public function markError(int $id, string $message): void
    {
        global $wpdb;
        $wpdb->update(
            SitesTable::tableName(),
            [
                'status'     => 'error',
                'last_error' => $message,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['id' => $id],
            ['%s', '%s', '%s'],
            ['%d'],
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
./vendor/bin/phpunit --testsuite integration --filter SitesRepositoryTest
```

Expected: `OK (6 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Services/SitesRepository.php packages/dashboard-plugin/tests/Integration/Services/SitesRepositoryTest.php
git commit -m "F5: TDD SitesRepository — wpdb wrapper (insert/find/exists/mark)"
```

---

## Task 5: Services/UrlValidator.php — HTTPS + DNS + duplicate

**Files:**
- Create: `packages/dashboard-plugin/src/Services/UrlValidator.php`
- Create: `packages/dashboard-plugin/tests/Unit/Services/UrlValidatorTest.php`

Validates URLs submitted to `POST /sites`. Pure value-returning class (no exceptions thrown — returns a result object the controller can branch on). DNS check is opt-in via constructor param so unit tests can disable it; the controller will pass `true` in production.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Unit\Services;

use Defyn\Dashboard\Services\UrlValidator;
use PHPUnit\Framework\TestCase;

/**
 * @group unit
 *
 * DNS-checking is disabled in this test class to keep it fully offline.
 * A separate integration test (Task 6's controller test) covers the DNS path
 * against `defyn-connector.test` (a controlled localhost name).
 */
final class UrlValidatorTest extends TestCase
{
    private UrlValidator $validator;

    public function setUp(): void
    {
        $this->validator = new UrlValidator(checkDns: false);
    }

    public function testHttpsUrlPasses(): void
    {
        $result = $this->validator->validate('https://example.test');
        self::assertTrue($result->isValid);
        self::assertNull($result->errorCode);
    }

    public function testHttpUrlFailsWithInvalidUrlCode(): void
    {
        $result = $this->validator->validate('http://example.test');
        self::assertFalse($result->isValid);
        self::assertSame('sites.invalid_url', $result->errorCode);
        self::assertStringContainsString('HTTPS', $result->errorMessage);
    }

    public function testMalformedUrlFails(): void
    {
        $result = $this->validator->validate('not a url');
        self::assertFalse($result->isValid);
        self::assertSame('sites.invalid_url', $result->errorCode);
    }

    public function testEmptyUrlFails(): void
    {
        $result = $this->validator->validate('');
        self::assertFalse($result->isValid);
        self::assertSame('sites.invalid_url', $result->errorCode);
    }

    public function testUrlWithoutHostFails(): void
    {
        $result = $this->validator->validate('https://');
        self::assertFalse($result->isValid);
        self::assertSame('sites.invalid_url', $result->errorCode);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit --testsuite unit --filter UrlValidatorTest
```

Expected: class not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

/**
 * Validates URLs submitted to POST /sites.
 *
 * Pure value-returning — returns ValidationResult instead of throwing,
 * so the controller can branch into the spec § 9.1 envelope without
 * exception-handling ceremony.
 */
final class UrlValidator
{
    public function __construct(
        private readonly bool $checkDns = true,
    ) {}

    public function validate(string $url): ValidationResult
    {
        if ($url === '') {
            return ValidationResult::invalid('sites.invalid_url', 'URL is required.');
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return ValidationResult::invalid('sites.invalid_url', 'URL is not well-formed.');
        }

        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https') {
            return ValidationResult::invalid('sites.invalid_url', 'URL must use HTTPS.');
        }
        if (empty($parts['host'])) {
            return ValidationResult::invalid('sites.invalid_url', 'URL must include a host.');
        }

        if ($this->checkDns && gethostbyname($parts['host']) === $parts['host']) {
            // gethostbyname returns the input unchanged on lookup failure.
            return ValidationResult::invalid('sites.invalid_url', 'URL host does not resolve.');
        }

        return ValidationResult::valid();
    }
}

final class ValidationResult
{
    private function __construct(
        public readonly bool    $isValid,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
    ) {}

    public static function valid(): self
    {
        return new self(true);
    }

    public static function invalid(string $code, string $message): self
    {
        return new self(false, $code, $message);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
./vendor/bin/phpunit --testsuite unit --filter UrlValidatorTest
```

Expected: `OK (5 tests, 11 assertions)`.

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Services/UrlValidator.php packages/dashboard-plugin/tests/Unit/Services/UrlValidatorTest.php
git commit -m "F5: TDD UrlValidator — HTTPS + parse + DNS gate"
```

---

## Task 6: Services/ActivityLogger.php — audit log writer

**Files:**
- Create: `packages/dashboard-plugin/src/Services/ActivityLogger.php`
- Create: `packages/dashboard-plugin/tests/Integration/Services/ActivityLoggerTest.php`

Thin wrapper around `wpdb->insert` for `wp_defyn_activity_log`. F5 only uses three event types (`site.connected`, `site.connection_rejected`, `site.error`) but the interface accepts any event_type so F6+ phases can reuse it.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Schema\ActivityLogTable;
use Defyn\Dashboard\Services\ActivityLogger;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class ActivityLoggerTest extends WP_UnitTestCase
{
    private ActivityLogger $logger;

    public function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb->query('TRUNCATE ' . ActivityLogTable::tableName());
        $this->logger = new ActivityLogger();
    }

    public function testLogPersistsRowWithEncodedDetails(): void
    {
        $this->logger->log(
            userId: 7,
            siteId: 42,
            eventType: 'site.connected',
            details: ['url' => 'https://example.test'],
        );

        global $wpdb;
        $rows = $wpdb->get_results('SELECT * FROM ' . ActivityLogTable::tableName(), ARRAY_A);

        self::assertCount(1, $rows);
        self::assertSame('7',   $rows[0]['user_id']);
        self::assertSame('42',  $rows[0]['site_id']);
        self::assertSame('site.connected', $rows[0]['event_type']);
        self::assertSame(['url' => 'https://example.test'], json_decode($rows[0]['details'], true));
        self::assertNotEmpty($rows[0]['created_at']);
    }

    public function testLogAcceptsNullUserIdAndSiteIdForSystemEvents(): void
    {
        $this->logger->log(null, null, 'system.boot', null);

        global $wpdb;
        $rows = $wpdb->get_results('SELECT * FROM ' . ActivityLogTable::tableName(), ARRAY_A);

        self::assertCount(1, $rows);
        self::assertNull($rows[0]['user_id']);
        self::assertNull($rows[0]['site_id']);
        self::assertSame('system.boot', $rows[0]['event_type']);
        self::assertNull($rows[0]['details']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit --testsuite integration --filter ActivityLoggerTest
```

Expected: class not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Schema\ActivityLogTable;

/**
 * Writes rows to wp_defyn_activity_log. The only writer for that table.
 *
 * Event types are free-form strings prefixed with a domain: F5 uses
 * `site.connected`, `site.connection_rejected`, `site.error`. F6+ will
 * add `sync.*` and `health.*`.
 */
final class ActivityLogger
{
    public function log(?int $userId, ?int $siteId, string $eventType, ?array $details = null): void
    {
        global $wpdb;
        $wpdb->insert(
            ActivityLogTable::tableName(),
            [
                'user_id'    => $userId,
                'site_id'    => $siteId,
                'event_type' => $eventType,
                'details'    => $details === null ? null : json_encode($details, JSON_THROW_ON_ERROR),
                'ip_address' => null,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ],
            [
                $userId === null ? '%s' : '%d',
                $siteId === null ? '%s' : '%d',
                '%s',
                '%s',
                '%s',
                '%s',
            ],
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
./vendor/bin/phpunit --testsuite integration --filter ActivityLoggerTest
```

Expected: `OK (2 tests, 10 assertions)`.

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Services/ActivityLogger.php packages/dashboard-plugin/tests/Integration/Services/ActivityLoggerTest.php
git commit -m "F5: TDD ActivityLogger — wpdb wrapper for activity_log"
```

---

## Task 7: Http/SignedHttpClient.php — Symfony http-client wrapper (plain POST in F5)

**Files:**
- Create: `packages/dashboard-plugin/src/Http/SignedHttpClient.php`
- Create: `packages/dashboard-plugin/tests/Integration/Http/SignedHttpClientTest.php`

A thin wrapper that controllers and AS jobs use to call connectors. **F5 just does plain HTTPS POST**; F6 will extend this same class to inject X-Defyn-* signature headers per spec § 5.2. The interface is fixed now so callers don't need to change in F6.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Http;

use Defyn\Dashboard\Http\SignedHttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class SignedHttpClientTest extends WP_UnitTestCase
{
    public function testPostJsonReturnsStatusAndDecodedBody(): void
    {
        $mock = new MockHttpClient([
            new MockResponse(
                json_encode(['ok' => true, 'site_public_key' => 'PUB==']),
                ['http_code' => 200, 'response_headers' => ['Content-Type' => 'application/json']],
            ),
        ]);
        $client = new SignedHttpClient($mock);

        $result = $client->postJson('https://example.test/api/connect', ['code' => 'X']);

        self::assertSame(200, $result['status']);
        self::assertSame(['ok' => true, 'site_public_key' => 'PUB=='], $result['body']);
    }

    public function testPostJsonSurfacesNon2xxBody(): void
    {
        $mock = new MockHttpClient([
            new MockResponse(
                json_encode(['error' => ['code' => 'connector.code_expired', 'message' => 'expired']]),
                ['http_code' => 410, 'response_headers' => ['Content-Type' => 'application/json']],
            ),
        ]);
        $client = new SignedHttpClient($mock);

        $result = $client->postJson('https://example.test/api/connect', ['code' => 'X']);

        self::assertSame(410, $result['status']);
        self::assertSame('connector.code_expired', $result['body']['error']['code']);
    }

    public function testPostJsonReturnsStatusZeroOnTransportError(): void
    {
        // A factory that throws on call simulates DNS / TCP errors.
        $mock = new MockHttpClient(static function (): MockResponse {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('DNS failure');
        });
        $client = new SignedHttpClient($mock);

        $result = $client->postJson('https://nowhere.test/api/connect', ['code' => 'X']);

        self::assertSame(0, $result['status']);
        self::assertSame([], $result['body']);
        self::assertNotEmpty($result['error']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit --testsuite integration --filter SignedHttpClientTest
```

Expected: class not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Http;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Outbound HTTPS client for talking to connector plugins.
 *
 * F5: plain JSON POST with no signing. The interface is fixed now so the
 * callers (Connection service, Action Scheduler jobs in later phases)
 * never have to change when F6 adds X-Defyn-Timestamp / X-Defyn-Nonce /
 * X-Defyn-Signature headers per spec § 5.2.
 *
 * Transport errors (DNS failure, connection refused, TLS handshake fail,
 * timeout) return ['status' => 0, 'body' => [], 'error' => '<msg>'] rather
 * than throwing — the caller (handshake AS job) needs to write 'error'
 * status into wp_defyn_sites either way, so flatter is simpler.
 */
final class SignedHttpClient
{
    public function __construct(
        private readonly ?HttpClientInterface $httpClient = null,
    ) {}

    /**
     * @param array<string, mixed> $body
     * @return array{status: int, body: array<string, mixed>, error: string}
     */
    public function postJson(string $url, array $body): array
    {
        $client = $this->httpClient ?? HttpClient::create([
            'timeout'     => 10,   // socket idle timeout
            'max_duration' => 30,  // overall request budget
        ]);

        try {
            $response = $client->request('POST', $url, [
                'json' => $body,
            ]);
            $status = $response->getStatusCode();
            $raw    = $response->getContent(throw: false);
            $decoded = $raw === '' ? [] : (json_decode($raw, true) ?? []);
            return ['status' => $status, 'body' => $decoded, 'error' => ''];
        } catch (Throwable $e) {
            return ['status' => 0, 'body' => [], 'error' => $e->getMessage()];
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
./vendor/bin/phpunit --testsuite integration --filter SignedHttpClientTest
```

Expected: `OK (3 tests, 8 assertions)`.

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Http/SignedHttpClient.php packages/dashboard-plugin/tests/Integration/Http/SignedHttpClientTest.php
git commit -m "F5: TDD SignedHttpClient — JSON POST (no signing yet; F6 adds headers)"
```

---

## Task 8: Services/Connection.php — handshake orchestration

**Files:**
- Create: `packages/dashboard-plugin/src/Services/Connection.php`
- Create: `packages/dashboard-plugin/tests/Integration/Services/ConnectionTest.php`

The class that runs the full handshake protocol for one site:
- Generates a fresh `callback_challenge`
- Calls connector's `/connect` via `SignedHttpClient`
- Verifies the returned `challenge_signature` against the returned `site_public_key`
- Updates the site row + writes activity log on success
- Marks error + writes activity log on failure

Constructor-injects `SignedHttpClient`, `SitesRepository`, `ActivityLogger` so tests can drive everything against in-memory mocks while still using real `wpdb`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Schema\ActivityLogTable;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\Connection;
use Defyn\Dashboard\Services\SitesRepository;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class ConnectionTest extends WP_UnitTestCase
{
    private SitesRepository $repo;

    public function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb->query('TRUNCATE ' . SitesTable::tableName());
        $wpdb->query('TRUNCATE ' . ActivityLogTable::tableName());
        $this->repo = new SitesRepository();
    }

    public function testValidHandshakeMarksSiteActiveAndLogsConnection(): void
    {
        // Connector's K_site keypair (test-side: we sign with this so the dashboard verifies).
        $kSitePair  = sodium_crypto_sign_keypair();
        $kSitePub   = sodium_crypto_sign_publickey($kSitePair);
        $kSitePriv  = sodium_crypto_sign_secretkey($kSitePair);

        $id = $this->repo->insertPending(7, 'https://example.test', 'Test', 'OURPUB==', 'OURENC==');
        $siteNonce = base64_encode(random_bytes(32));

        // Capture the request body so we can sign exactly what the dashboard sent.
        $capturedBody = null;
        $mock = new MockHttpClient(function (string $method, string $url, array $opts) use (&$capturedBody, $kSitePub, $kSitePriv, $siteNonce): MockResponse {
            $capturedBody = json_decode($opts['body'], true);
            // Sign callback_challenge + site_nonce (spec § 8 step 7 canonical form).
            $signature = sodium_crypto_sign_detached($capturedBody['callback_challenge'] . $siteNonce, $kSitePriv);
            return new MockResponse(
                json_encode([
                    'site_public_key'     => base64_encode($kSitePub),
                    'challenge_signature' => base64_encode($signature),
                    'site_url'            => 'https://example.test',
                    'site_name'           => 'Test',
                ]),
                ['http_code' => 200, 'response_headers' => ['Content-Type' => 'application/json']],
            );
        });

        $connection = new Connection(
            httpClient: new SignedHttpClient($mock),
            repo:       $this->repo,
            logger:     new ActivityLogger(),
            dashboardPublicKey: 'OURPUB==',
        );

        $connection->complete($id, 'ABCDEFGH2345', 'https://example.test');

        $site = $this->repo->findById($id);
        self::assertSame('active', $site->status);
        self::assertSame(base64_encode($kSitePub), $site->sitePublicKey);
        self::assertNotNull($site->lastContactAt);

        // Activity log row written.
        global $wpdb;
        $logs = $wpdb->get_results('SELECT * FROM ' . ActivityLogTable::tableName(), ARRAY_A);
        self::assertCount(1, $logs);
        self::assertSame('site.connected', $logs[0]['event_type']);
        self::assertSame((string) $id, $logs[0]['site_id']);

        // Sanity: the dashboard sent the expected body shape.
        self::assertSame('ABCDEFGH2345', $capturedBody['code']);
        self::assertSame('OURPUB==', $capturedBody['dashboard_public_key']);
        self::assertNotEmpty($capturedBody['callback_challenge']);
    }

    public function testInvalidSignatureMarksErrorAndLogsRejection(): void
    {
        $id = $this->repo->insertPending(7, 'https://example.test', '', 'OURPUB==', 'OURENC==');

        // Return a syntactically valid but cryptographically WRONG signature.
        $wrongPair = sodium_crypto_sign_keypair();
        $wrongPub  = sodium_crypto_sign_publickey($wrongPair);
        $mock = new MockHttpClient(function () use ($wrongPub): MockResponse {
            return new MockResponse(
                json_encode([
                    'site_public_key'     => base64_encode($wrongPub),
                    'challenge_signature' => base64_encode(str_repeat("\x00", 64)),  // garbage sig
                ]),
                ['http_code' => 200, 'response_headers' => ['Content-Type' => 'application/json']],
            );
        });

        (new Connection(new SignedHttpClient($mock), $this->repo, new ActivityLogger(), 'OURPUB=='))
            ->complete($id, 'CODE', 'https://example.test');

        $site = $this->repo->findById($id);
        self::assertSame('error', $site->status);
        self::assertSame('Challenge signature invalid', $site->lastError);

        global $wpdb;
        $logs = $wpdb->get_results('SELECT * FROM ' . ActivityLogTable::tableName(), ARRAY_A);
        self::assertCount(1, $logs);
        self::assertSame('site.connection_rejected', $logs[0]['event_type']);
    }

    public function testConnectorErrorResponseSurfacesEnvelopeMessage(): void
    {
        $id = $this->repo->insertPending(7, 'https://example.test', '', 'OURPUB==', 'OURENC==');

        $mock = new MockHttpClient([
            new MockResponse(
                json_encode(['error' => ['code' => 'connector.code_expired', 'message' => 'Connection code has expired. Generate a new one.']]),
                ['http_code' => 410, 'response_headers' => ['Content-Type' => 'application/json']],
            ),
        ]);

        (new Connection(new SignedHttpClient($mock), $this->repo, new ActivityLogger(), 'OURPUB=='))
            ->complete($id, 'CODE', 'https://example.test');

        $site = $this->repo->findById($id);
        self::assertSame('error', $site->status);
        self::assertStringContainsString('Connection code has expired', $site->lastError);

        global $wpdb;
        $logs = $wpdb->get_results('SELECT * FROM ' . ActivityLogTable::tableName(), ARRAY_A);
        self::assertSame('site.error', $logs[0]['event_type']);
    }

    public function testTransportErrorMarksSiteError(): void
    {
        $id = $this->repo->insertPending(7, 'https://nowhere.test', '', 'OURPUB==', 'OURENC==');

        $mock = new MockHttpClient(static function (): MockResponse {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('Could not resolve host');
        });

        (new Connection(new SignedHttpClient($mock), $this->repo, new ActivityLogger(), 'OURPUB=='))
            ->complete($id, 'CODE', 'https://nowhere.test');

        $site = $this->repo->findById($id);
        self::assertSame('error', $site->status);
        self::assertStringContainsString('Could not resolve host', $site->lastError);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit --testsuite integration --filter ConnectionTest
```

Expected: class not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Models\Site;

/**
 * Runs the F5 connection handshake against one connector.
 *
 * Lives outside the AS job so tests can construct it with mocks and bypass
 * Action Scheduler entirely. The CompleteConnection AS handler is a thin
 * wrapper that builds one of these and calls ::complete().
 */
final class Connection
{
    public function __construct(
        private readonly SignedHttpClient $httpClient,
        private readonly SitesRepository  $repo,
        private readonly ActivityLogger   $logger,
        private readonly string           $dashboardPublicKey,  // base64 K_dash pub
    ) {}

    public function complete(int $siteId, string $code, string $siteUrl): void
    {
        $site = $this->repo->findById($siteId);
        if ($site === null) {
            // Site disappeared between schedule and execute (e.g. user deleted it).
            // Nothing to do — log and exit. F8's DELETE endpoint may already have
            // emitted its own activity entry.
            return;
        }

        $challenge = base64_encode(random_bytes(32));
        $endpoint  = rtrim($siteUrl, '/') . '/wp-json/defyn-connector/v1/connect';

        $response = $this->httpClient->postJson($endpoint, [
            'code'                 => $code,
            'dashboard_public_key' => $this->dashboardPublicKey,
            'callback_challenge'   => $challenge,
        ]);

        // Transport error (DNS, connection refused, timeout).
        if ($response['status'] === 0) {
            $this->fail($site, $response['error'], 'site.error');
            return;
        }

        // Connector returned a non-2xx envelope.
        if ($response['status'] >= 400) {
            $message = $response['body']['error']['message'] ?? "Connector returned HTTP {$response['status']}.";
            $this->fail($site, (string) $message, 'site.error');
            return;
        }

        // 2xx — verify the challenge signature.
        $body = $response['body'];
        $sitePubBase64 = (string) ($body['site_public_key'] ?? '');
        $sigBase64     = (string) ($body['challenge_signature'] ?? '');

        $sitePubRaw = base64_decode($sitePubBase64, true);
        $sigRaw     = base64_decode($sigBase64, true);

        if ($sitePubRaw === false || $sigRaw === false
            || strlen($sitePubRaw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || strlen($sigRaw) !== SODIUM_CRYPTO_SIGN_BYTES) {
            $this->fail($site, 'Challenge signature invalid', 'site.connection_rejected');
            return;
        }

        // F5's canonical-string-to-sign is exactly: callback_challenge.
        // We don't know the connector's site_nonce — but the connector does NOT
        // concatenate it onto the signed payload (see Task 12+13 note: the connector
        // signs just $challenge). For F5's threat model (one-shot handshake with a
        // connection-code-bound peer), verifying possession of K_site against the
        // challenge alone is sufficient: a MITM with no K_site private key cannot
        // mint a valid signature, and replays are blocked by the one-shot code
        // consumption on the connector side.
        //
        // F6 will introduce mutual-signed /status calls where the dashboard signs
        // outbound requests; the canonical string defined in spec § 5.2 covers that.
        if (!sodium_crypto_sign_verify_detached($sigRaw, $challenge, $sitePubRaw)) {
            $this->fail($site, 'Challenge signature invalid', 'site.connection_rejected');
            return;
        }

        // All good — flip to active and log it.
        $this->repo->markActive($siteId, $sitePubBase64);
        $this->logger->log($site->userId, $siteId, 'site.connected', ['url' => $siteUrl]);
    }

    private function fail(Site $site, string $message, string $eventType): void
    {
        $this->repo->markError($site->id, $message);
        $this->logger->log($site->userId, $site->id, $eventType, ['url' => $site->url, 'message' => $message]);
    }
}
```

> **IMPORTANT — Canonical form mismatch with spec § 8 step 7:** The plan in this task verifies the signature against just `$challenge` (not `$challenge . $site_nonce` as spec § 8 step 7 implies). This is intentional for F5: the dashboard does NOT know the connector's `site_nonce` (the connector generates it locally and never sends it). The simplest correct verification is `verify(signature, challenge, site_pub)` — proves the connector holds the matching K_site private key. The `site_nonce` exists in the connector's state for its own audit/replay protection. F5's threat model (one-shot code, mutual public-key exchange) makes this sufficient. **The connector implementation in Task 13 must accordingly sign just `callback_challenge` (NOT challenge + nonce).** If a future review wants to bind the signed payload to the connector's nonce, the connector would need to return its nonce in the response — track as F6+ follow-up.
>
> **Note on the test:** the happy-path test mock signs `$challenge . $siteNonce` and the production verifier checks just `$challenge`. That means the test as written WILL FAIL the verification. Before running Step 4, ADJUST the test's mock to sign just `$capturedBody['callback_challenge']` (without `$siteNonce`) so it matches the connector's actual F5 signing behavior. The `$siteNonce` variable is kept in the test for forward-compatibility / documentation but not used in the signed input.

- [ ] **Step 4: Adjust the happy-path test's mock to match the F5 signing contract**

In `ConnectionTest.php`, change the line:
```php
$signature = sodium_crypto_sign_detached($capturedBody['callback_challenge'] . $siteNonce, $kSitePriv);
```
to:
```php
$signature = sodium_crypto_sign_detached($capturedBody['callback_challenge'], $kSitePriv);
```

(The `$siteNonce` variable can stay declared at the top — it's documentation of where the nonce came from, even though F5 doesn't use it in the signed payload.)

- [ ] **Step 5: Run test to verify it passes**

```bash
./vendor/bin/phpunit --testsuite integration --filter ConnectionTest
```

Expected: `OK (4 tests, ...)`.

- [ ] **Step 6: Commit**

```bash
git add packages/dashboard-plugin/src/Services/Connection.php packages/dashboard-plugin/tests/Integration/Services/ConnectionTest.php
git commit -m "F5: TDD Connection service — handshake orchestration + signature verify"
```

---

## Task 9: SitesCreateController + RestRouter wiring

**Files:**
- Create: `packages/dashboard-plugin/src/Rest/SitesCreateController.php`
- Modify: `packages/dashboard-plugin/src/Rest/RestRouter.php`
- Create: `packages/dashboard-plugin/tests/Integration/Rest/SitesCreateTest.php`

`POST /defyn/v1/sites`. Bearer-token authenticated via existing RequireAuth middleware. Validates body, generates K_dash keypair, encrypts private key via Vault, inserts pending row, schedules AS job, returns 202.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Schema\SitesTable;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class SitesCreateTest extends WP_UnitTestCase
{
    private string $accessToken;
    private int $userId;

    public function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb->query('TRUNCATE ' . SitesTable::tableName());

        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
        if (!defined('DEFYN_VAULT_KEY')) {
            define('DEFYN_VAULT_KEY', \Defyn\Dashboard\Crypto\Vault::generateKey());
        }

        $this->userId = self::factory()->user->create(['user_email' => 'a@test.test']);
        $this->accessToken = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($this->userId);

        do_action('rest_api_init');
    }

    private function postSite(array $body): \WP_REST_Response
    {
        $req = new WP_REST_Request('POST', '/defyn/v1/sites');
        $req->set_header('Authorization', 'Bearer ' . $this->accessToken);
        $req->set_header('Content-Type', 'application/json');
        $req->set_body(json_encode($body));
        return rest_do_request($req);
    }

    public function testValidPostReturns202AndCreatesPendingSite(): void
    {
        // 'https://defyn.test' resolves locally (WP test bootstrap defines it).
        // The UrlValidator's DNS check is bypassed in tests by setting the
        // controller's validator-checkDns flag via constant (see implementation).
        $r = $this->postSite([
            'url'   => 'https://defyn.test',
            'label' => 'My site',
            'code'  => 'ABCDEFGH2345',
        ]);

        self::assertSame(202, $r->get_status());
        $data = $r->get_data();
        self::assertArrayHasKey('site_id', $data);

        global $wpdb;
        $row = $wpdb->get_row('SELECT * FROM ' . SitesTable::tableName() . ' WHERE id = ' . (int) $data['site_id'], ARRAY_A);
        self::assertSame('pending',                  $row['status']);
        self::assertSame('https://defyn.test',       $row['url']);
        self::assertSame('My site',                  $row['label']);
        self::assertSame((string) $this->userId,     $row['user_id']);
        self::assertNotEmpty($row['our_public_key']);
        self::assertNotEmpty($row['our_private_key']);  // encrypted envelope
    }

    public function testMissingFieldsReturns400(): void
    {
        $r = $this->postSite(['url' => 'https://defyn.test']);
        self::assertSame(400, $r->get_status());
        self::assertSame('sites.missing_fields', $r->get_data()['error']['code']);
    }

    public function testInvalidUrlReturns400(): void
    {
        $r = $this->postSite(['url' => 'http://insecure.test', 'label' => '', 'code' => 'X']);
        self::assertSame(400, $r->get_status());
        self::assertSame('sites.invalid_url', $r->get_data()['error']['code']);
    }

    public function testDuplicateUrlForUserReturns409(): void
    {
        $this->postSite(['url' => 'https://defyn.test', 'label' => '', 'code' => 'X']);
        $r = $this->postSite(['url' => 'https://defyn.test', 'label' => '', 'code' => 'X']);

        self::assertSame(409, $r->get_status());
        self::assertSame('sites.duplicate_url', $r->get_data()['error']['code']);
    }

    public function testUnauthenticatedRequestReturns401(): void
    {
        $req = new WP_REST_Request('POST', '/defyn/v1/sites');
        $req->set_header('Content-Type', 'application/json');
        $req->set_body(json_encode(['url' => 'https://defyn.test', 'label' => '', 'code' => 'X']));
        $r = rest_do_request($req);

        self::assertSame(401, $r->get_status());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit --testsuite integration --filter SitesCreateTest
```

Expected: route not found / class not found.

- [ ] **Step 3: Write `SitesCreateController.php`**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Crypto\KeyPair;
use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Services\UrlValidator;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /defyn/v1/sites — Add a managed site.
 *
 * Body:   { url: string (https), label: string, code: string (12-char) }
 * Returns: 202 { site_id: int } — handshake is async, SPA polls GET /sites/{id}
 *
 * The K_dash keypair is generated here, the private key is encrypted with the
 * vault key (DEFYN_VAULT_KEY from env), and a defyn_complete_connection AS job
 * is scheduled to fire immediately (it'll typically run within a second on a
 * healthy WP cron / AS runner).
 */
final class SitesCreateController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $body   = $request->get_json_params();
        if (!is_array($body)) {
            $body = [];
        }

        $url   = is_string($body['url']   ?? null) ? trim($body['url'])   : '';
        $label = is_string($body['label'] ?? null) ? trim($body['label']) : '';
        $code  = is_string($body['code']  ?? null) ? trim($body['code'])  : '';

        if ($url === '' || $code === '') {
            return ErrorResponse::create(400, 'sites.missing_fields', 'Fields url and code are required.');
        }

        // In tests we skip DNS so e.g. defyn.test passes through.
        $validator = new UrlValidator(checkDns: !defined('DEFYN_TESTS_RUNNING'));
        $result = $validator->validate($url);
        if (!$result->isValid) {
            return ErrorResponse::create(400, $result->errorCode, $result->errorMessage);
        }

        $repo = new SitesRepository();
        if ($repo->existsForUser($userId, $url)) {
            return ErrorResponse::create(409, 'sites.duplicate_url', 'This URL is already managed.');
        }

        if (!defined('DEFYN_VAULT_KEY') || !is_string(DEFYN_VAULT_KEY) || DEFYN_VAULT_KEY === '') {
            return ErrorResponse::create(500, 'sites.vault_not_configured', 'Vault key is not configured.');
        }

        $pair  = KeyPair::generate();
        $vault = new Vault(DEFYN_VAULT_KEY);
        $encryptedPrivate = $vault->encrypt($pair->privateKey);

        $siteId = $repo->insertPending(
            userId: $userId,
            url:    $url,
            label:  $label,
            ourPublicKey: $pair->publicKey,
            ourPrivateKeyEncrypted: $encryptedPrivate,
        );

        // Schedule the AS job. The handler is registered in Plugin::boot()
        // (see Task 14). as_schedule_single_action is a free function exported
        // by woocommerce/action-scheduler at action-scheduler.php load time.
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time(), 'defyn_complete_connection', [$siteId, $code, $url], 'defyn');
        }

        return new WP_REST_Response(['site_id' => $siteId], 202);
    }
}
```

- [ ] **Step 4: Wire the route in `RestRouter.php`**

Open `packages/dashboard-plugin/src/Rest/RestRouter.php`. Inside `register()`, AFTER the existing `auth/logout` registration and BEFORE the closing brace of `register()`, append:

```php
        register_rest_route(self::NAMESPACE, '/sites', [
            'methods'             => 'POST',
            'callback'            => [new SitesCreateController(), 'handle'],
            'permission_callback' => [RequireAuth::class, 'check'],
        ]);
```

- [ ] **Step 5: Add `DEFYN_TESTS_RUNNING` definition to `tests/bootstrap.php`**

Open `packages/dashboard-plugin/tests/bootstrap.php`. Below the `putenv('WP_PHPUNIT__TESTS_CONFIG=...');` line, add:

```php
// Tests bypass DNS lookups in UrlValidator so synthetic test hostnames pass.
if (!defined('DEFYN_TESTS_RUNNING')) {
    define('DEFYN_TESTS_RUNNING', true);
}
```

- [ ] **Step 6: Run test to verify it passes**

```bash
./vendor/bin/phpunit --testsuite integration --filter SitesCreateTest
```

Expected: `OK (5 tests, ...)`.

- [ ] **Step 7: Commit**

```bash
git add packages/dashboard-plugin/src/Rest/SitesCreateController.php \
        packages/dashboard-plugin/src/Rest/RestRouter.php \
        packages/dashboard-plugin/tests/Integration/Rest/SitesCreateTest.php \
        packages/dashboard-plugin/tests/bootstrap.php
git commit -m "F5: TDD POST /defyn/v1/sites — create pending site + schedule handshake"
```

---

## Task 10: SitesShowController + RestRouter wiring

**Files:**
- Create: `packages/dashboard-plugin/src/Rest/SitesShowController.php`
- Modify: `packages/dashboard-plugin/src/Rest/RestRouter.php`
- Create: `packages/dashboard-plugin/tests/Integration/Rest/SitesShowTest.php`

`GET /defyn/v1/sites/{id}`. Returns the Site DTO's `toJson()`. 404 if not the authenticated user's site (per spec § 4.1 user_id scoping). SPA polls this every 2 seconds.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class SitesShowTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb->query('TRUNCATE ' . SitesTable::tableName());
        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
        do_action('rest_api_init');
    }

    public function testReturnsSiteJsonForOwner(): void
    {
        $userId = self::factory()->user->create();
        $token = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($userId);
        $siteId = (new SitesRepository())->insertPending($userId, 'https://defyn.test', 'Site', 'PUB', 'ENC');

        $req = new WP_REST_Request('GET', '/defyn/v1/sites/' . $siteId);
        $req->set_header('Authorization', 'Bearer ' . $token);
        $r = rest_do_request($req);

        self::assertSame(200, $r->get_status());
        $data = $r->get_data();
        self::assertSame($siteId,             $data['id']);
        self::assertSame('https://defyn.test', $data['url']);
        self::assertSame('pending',           $data['status']);
        self::assertArrayNotHasKey('our_private_key', $data);  // never leaked
    }

    public function testReturns404ForOtherUsersSite(): void
    {
        $ownerId   = self::factory()->user->create();
        $stranger  = self::factory()->user->create();
        $token     = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($stranger);
        $siteId    = (new SitesRepository())->insertPending($ownerId, 'https://defyn.test', '', 'P', 'E');

        $req = new WP_REST_Request('GET', '/defyn/v1/sites/' . $siteId);
        $req->set_header('Authorization', 'Bearer ' . $token);
        $r = rest_do_request($req);

        self::assertSame(404, $r->get_status());
        self::assertSame('sites.not_found', $r->get_data()['error']['code']);
    }

    public function testReturns404ForNonExistentId(): void
    {
        $userId = self::factory()->user->create();
        $token  = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($userId);

        $req = new WP_REST_Request('GET', '/defyn/v1/sites/9999');
        $req->set_header('Authorization', 'Bearer ' . $token);
        $r = rest_do_request($req);

        self::assertSame(404, $r->get_status());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit --testsuite integration --filter SitesShowTest
```

Expected: route not found.

- [ ] **Step 3: Write the controller**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

final class SitesShowController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $id     = (int) $request->get_param('id');

        $site = (new SitesRepository())->findByIdForUser($id, $userId);
        if ($site === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }

        return new WP_REST_Response($site->toJson(), 200);
    }
}
```

- [ ] **Step 4: Register route in `RestRouter.php`**

Inside `register()`, AFTER the POST /sites registration from Task 9, append:

```php
        register_rest_route(self::NAMESPACE, '/sites/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [new SitesShowController(), 'handle'],
            'permission_callback' => [RequireAuth::class, 'check'],
        ]);
```

- [ ] **Step 5: Run test to verify it passes**

```bash
./vendor/bin/phpunit --testsuite integration --filter SitesShowTest
```

Expected: `OK (3 tests, ...)`.

- [ ] **Step 6: Commit**

```bash
git add packages/dashboard-plugin/src/Rest/SitesShowController.php \
        packages/dashboard-plugin/src/Rest/RestRouter.php \
        packages/dashboard-plugin/tests/Integration/Rest/SitesShowTest.php
git commit -m "F5: TDD GET /defyn/v1/sites/{id} — user-scoped site detail"
```

---

## Task 11: SitesListController + RestRouter wiring

**Files:**
- Create: `packages/dashboard-plugin/src/Rest/SitesListController.php`
- Modify: `packages/dashboard-plugin/src/Rest/RestRouter.php`
- Create: `packages/dashboard-plugin/tests/Integration/Rest/SitesListTest.php`

`GET /defyn/v1/sites`. Returns the authenticated user's sites. F5 ships a minimal list (no pagination, no status filter — both are deferred to F8 when the UI actually needs them). YAGNI.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class SitesListTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb->query('TRUNCATE ' . SitesTable::tableName());
        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
        do_action('rest_api_init');
    }

    public function testListReturnsOnlyOwnerSites(): void
    {
        $owner    = self::factory()->user->create();
        $stranger = self::factory()->user->create();
        $repo = new SitesRepository();
        $repo->insertPending($owner,    'https://a.test', '', 'P', 'E');
        $repo->insertPending($owner,    'https://b.test', '', 'P', 'E');
        $repo->insertPending($stranger, 'https://c.test', '', 'P', 'E');

        $token = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($owner);
        $req = new WP_REST_Request('GET', '/defyn/v1/sites');
        $req->set_header('Authorization', 'Bearer ' . $token);
        $r = rest_do_request($req);

        self::assertSame(200, $r->get_status());
        $data = $r->get_data();
        self::assertArrayHasKey('sites', $data);
        self::assertCount(2, $data['sites']);
        self::assertSame(['https://a.test', 'https://b.test'], array_map(fn ($s) => $s['url'], $data['sites']));
    }

    public function testEmptyListReturnsEmptyArray(): void
    {
        $userId = self::factory()->user->create();
        $token  = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($userId);
        $req = new WP_REST_Request('GET', '/defyn/v1/sites');
        $req->set_header('Authorization', 'Bearer ' . $token);
        $r = rest_do_request($req);

        self::assertSame(200, $r->get_status());
        self::assertSame(['sites' => []], $r->get_data());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit --testsuite integration --filter SitesListTest
```

Expected: route not found.

- [ ] **Step 3: Write the controller**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

final class SitesListController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $sites = (new SitesRepository())->findAllForUser($userId);
        return new WP_REST_Response([
            'sites' => array_map(fn ($s) => $s->toJson(), $sites),
        ], 200);
    }
}
```

- [ ] **Step 4: Register route in `RestRouter.php`**

Inside `register()`, AFTER the GET /sites/{id} registration from Task 10, append:

```php
        register_rest_route(self::NAMESPACE, '/sites', [
            'methods'             => 'GET',
            'callback'            => [new SitesListController(), 'handle'],
            'permission_callback' => [RequireAuth::class, 'check'],
        ]);
```

> Note: WordPress's `register_rest_route` accepts multiple registrations for the same path with different HTTP methods — Task 9's POST and this GET coexist without conflict.

- [ ] **Step 5: Run test to verify it passes**

```bash
./vendor/bin/phpunit --testsuite integration --filter SitesListTest
```

Expected: `OK (2 tests, ...)`.

- [ ] **Step 6: Commit**

```bash
git add packages/dashboard-plugin/src/Rest/SitesListController.php \
        packages/dashboard-plugin/src/Rest/RestRouter.php \
        packages/dashboard-plugin/tests/Integration/Rest/SitesListTest.php
git commit -m "F5: TDD GET /defyn/v1/sites — user-scoped site list"
```

---

## Task 12: Connector Crypto/Signer.php — sign challenge with K_site

**Files:**
- Create: `packages/connector-plugin/src/Crypto/Signer.php`
- Create: `packages/connector-plugin/tests/Unit/Crypto/SignerTest.php`

Simpler than the dashboard's `Signer` — this one just signs an arbitrary message with the connector's private key. Used by Task 13's extended `ConnectController` to sign the dashboard's `callback_challenge`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Unit\Crypto;

use Defyn\Connector\Crypto\KeyPair;
use Defyn\Connector\Crypto\Signer;
use PHPUnit\Framework\TestCase;

/**
 * @group unit
 */
final class SignerTest extends TestCase
{
    public function testSignProducesBase64SignatureVerifiableWithPublicKey(): void
    {
        $pair = KeyPair::generate();
        $message = 'hello-world-' . random_bytes(8);

        $sigBase64 = Signer::sign($message, $pair['private_key']);

        $sigRaw = base64_decode($sigBase64, true);
        self::assertNotFalse($sigRaw);
        self::assertSame(SODIUM_CRYPTO_SIGN_BYTES, strlen($sigRaw));

        // Verify with the public key — exactly what the dashboard will do.
        $pubRaw = base64_decode($pair['public_key'], true);
        self::assertTrue(sodium_crypto_sign_verify_detached($sigRaw, $message, $pubRaw));
    }

    public function testTwoSignaturesOfSameMessageAreIdentical(): void
    {
        // Ed25519 is deterministic — same input + same key → same signature.
        $pair = KeyPair::generate();
        $a = Signer::sign('msg', $pair['private_key']);
        $b = Signer::sign('msg', $pair['private_key']);
        self::assertSame($a, $b);
    }

    public function testInvalidBase64PrivateKeyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Signer::sign('msg', 'not-base64!!!');
    }

    public function testWrongLengthPrivateKeyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Signer::sign('msg', base64_encode(str_repeat('a', 10)));  // 10 bytes ≠ 64
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit --testsuite unit --filter SignerTest
```

Expected: class not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Crypto;

use InvalidArgumentException;

/**
 * Signs an arbitrary string with the connector's Ed25519 private key.
 *
 * F5: signs the dashboard's `callback_challenge` directly. No canonical
 * format wrapping (the dashboard verifies against the raw challenge).
 * F6 will add a separate request-signing path with the spec § 5.2
 * canonical string for outbound /status/heartbeat calls — that lives
 * in a different code path because it has different input shape.
 */
final class Signer
{
    public static function sign(string $message, string $privateKeyBase64): string
    {
        $raw = base64_decode($privateKeyBase64, true);
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new InvalidArgumentException(
                'Signer requires a base64-encoded ' . SODIUM_CRYPTO_SIGN_SECRETKEYBYTES . '-byte Ed25519 secret key.'
            );
        }

        return base64_encode(sodium_crypto_sign_detached($message, $raw));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
./vendor/bin/phpunit --testsuite unit --filter SignerTest
```

Expected: `OK (4 tests, ...)`.

- [ ] **Step 5: Commit**

```bash
git add packages/connector-plugin/src/Crypto/Signer.php \
        packages/connector-plugin/tests/Unit/Crypto/SignerTest.php
git commit -m "F5: TDD connector Signer — sign arbitrary message with K_site"
```

---

## Task 13: Extend connector ConnectController — challenge-response + handshake response

**Files:**
- Modify: `packages/connector-plugin/src/Rest/ConnectController.php`
- Modify: `packages/connector-plugin/tests/Integration/Rest/ConnectTest.php`

Extend the F4 controller to:
- Require `dashboard_public_key` + `callback_challenge` (in addition to `code`)
- After validating the code, sign `callback_challenge` with K_site private key (via Task 12's Signer)
- Persist `dashboard_public_key` and transition state to `connected` (with new `connected_at` timestamp)
- Return `{site_public_key, challenge_signature, site_url, site_name}` instead of `{ok: true}`

The existing `testValidCodeReturns200AndMarksCodeConsumed` test will be **replaced in place** with the new happy-path test for the full handshake response shape.

- [ ] **Step 1: Update the existing happy-path test (in `ConnectTest.php`) to reflect the new response shape**

In `packages/connector-plugin/tests/Integration/Rest/ConnectTest.php`, REPLACE the existing `testValidCodeReturns200AndMarksCodeConsumed` method with:

```php
    public function testValidHandshakeReturnsSignedResponseAndMarksConnected(): void
    {
        $code = 'ABCDEFGH2345';
        $nonce = base64_encode(random_bytes(32));
        $this->state->update([
            'state'           => 'awaiting-handshake',
            'connection_code' => $code,
            'site_nonce'      => $nonce,
            'code_created_at' => time(),
            'code_expires_at' => time() + 600,
        ]);

        $dashboardPair = sodium_crypto_sign_keypair();
        $dashboardPub  = base64_encode(sodium_crypto_sign_publickey($dashboardPair));
        $challenge     = base64_encode(random_bytes(32));

        $request = new WP_REST_Request('POST', '/defyn-connector/v1/connect');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode([
            'code'                 => $code,
            'dashboard_public_key' => $dashboardPub,
            'callback_challenge'   => $challenge,
        ]));

        $response = rest_do_request($request);

        self::assertSame(200, $response->get_status());
        $data = $response->get_data();
        self::assertArrayHasKey('site_public_key', $data);
        self::assertArrayHasKey('challenge_signature', $data);
        self::assertArrayHasKey('site_url', $data);
        self::assertArrayHasKey('site_name', $data);

        // Signature must verify against the returned site_public_key over the raw challenge.
        $sigRaw = base64_decode($data['challenge_signature'], true);
        $pubRaw = base64_decode($data['site_public_key'], true);
        self::assertTrue(sodium_crypto_sign_verify_detached($sigRaw, $challenge, $pubRaw));

        // State transitioned to 'connected' (not just 'code-consumed').
        $after = $this->state->all();
        self::assertSame('connected', $after['state']);
        self::assertSame($dashboardPub, $after['dashboard_public_key']);
        self::assertArrayHasKey('connected_at', $after);
    }
```

- [ ] **Step 2: Append three new error-path tests for the new required fields**

In the same test file, AFTER the existing `testAlreadyConsumedCodeReturns409` test and BEFORE `testConsumedAndExpiredCodeReturns410ExpiryWins`, append:

```php
    public function testMissingDashboardPublicKeyReturns400(): void
    {
        $this->state->update([
            'state'           => 'awaiting-handshake',
            'connection_code' => 'ABCDEFGH2345',
            'site_nonce'      => base64_encode(random_bytes(32)),
            'code_expires_at' => time() + 600,
        ]);

        $request = new WP_REST_Request('POST', '/defyn-connector/v1/connect');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode([
            'code'               => 'ABCDEFGH2345',
            'callback_challenge' => base64_encode(random_bytes(32)),
        ]));

        $response = rest_do_request($request);

        self::assertSame(400, $response->get_status());
        self::assertSame('connector.missing_dashboard_key', $response->get_data()['error']['code']);
    }

    public function testMissingCallbackChallengeReturns400(): void
    {
        $this->state->update([
            'state'           => 'awaiting-handshake',
            'connection_code' => 'ABCDEFGH2345',
            'site_nonce'      => base64_encode(random_bytes(32)),
            'code_expires_at' => time() + 600,
        ]);

        $request = new WP_REST_Request('POST', '/defyn-connector/v1/connect');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode([
            'code'                 => 'ABCDEFGH2345',
            'dashboard_public_key' => base64_encode(random_bytes(32)),
        ]));

        $response = rest_do_request($request);

        self::assertSame(400, $response->get_status());
        self::assertSame('connector.missing_challenge', $response->get_data()['error']['code']);
    }

    public function testMalformedDashboardPublicKeyReturns400(): void
    {
        $this->state->update([
            'state'           => 'awaiting-handshake',
            'connection_code' => 'ABCDEFGH2345',
            'site_nonce'      => base64_encode(random_bytes(32)),
            'code_expires_at' => time() + 600,
        ]);

        $request = new WP_REST_Request('POST', '/defyn-connector/v1/connect');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode([
            'code'                 => 'ABCDEFGH2345',
            'dashboard_public_key' => 'not-valid-base64!!!',
            'callback_challenge'   => base64_encode(random_bytes(32)),
        ]));

        $response = rest_do_request($request);

        self::assertSame(400, $response->get_status());
        self::assertSame('connector.invalid_dashboard_key', $response->get_data()['error']['code']);
    }
```

- [ ] **Step 3: Update existing F4 error-path tests to include the new required fields**

The existing F4 tests `testMissingCodeReturns400WithEnvelope`, `testNoCodeGeneratedReturns404`, `testInvalidCodeReturns401`, `testExpiredCodeReturns410`, `testAlreadyConsumedCodeReturns409`, `testConsumedAndExpiredCodeReturns410ExpiryWins` POST bodies that no longer pass F5's new validation (missing dashboard_public_key + callback_challenge). They need `dashboard_public_key` and `callback_challenge` placeholder values so they reach the branches they're actually testing.

For each of those tests, change the `set_body` line FROM (example):
```php
$request->set_body(json_encode(['code' => 'ABCDEFGH2345']));
```
TO:
```php
$request->set_body(json_encode([
    'code'                 => 'ABCDEFGH2345',
    'dashboard_public_key' => base64_encode(random_bytes(32)),
    'callback_challenge'   => base64_encode(random_bytes(32)),
]));
```

For `testMissingCodeReturns400WithEnvelope` specifically, the body should be `json_encode(['dashboard_public_key' => base64_encode(random_bytes(32)), 'callback_challenge' => base64_encode(random_bytes(32))])` — omitting only the `code` field (the field under test).

- [ ] **Step 4: Run tests to verify they fail (controller still on F4 shape)**

```bash
cd packages/connector-plugin
./vendor/bin/phpunit --testsuite integration --filter ConnectTest
```

Expected: new tests fail because controller doesn't recognize the new fields; happy-path test fails because response shape is still `{ok: true}`.

- [ ] **Step 5: Rewrite `ConnectController.php` to handle the extended request**

Replace the entire contents of `packages/connector-plugin/src/Rest/ConnectController.php` with:

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Rest;

use Defyn\Connector\Crypto\Signer;
use Defyn\Connector\Rest\Responses\ErrorResponse;
use Defyn\Connector\Storage\ConnectorState;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles POST /defyn-connector/v1/connect.
 *
 * F4 → F5 evolution: now performs the full handshake.
 *   F4: validated code, returned {ok: true}, state → code-consumed.
 *   F5: also accepts dashboard_public_key + callback_challenge, signs
 *       the challenge with K_site, persists dashboard_public_key, returns
 *       {site_public_key, challenge_signature, site_url, site_name},
 *       state → connected.
 */
final class ConnectController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $body = $request->get_json_params();
        $code              = is_array($body) ? ($body['code'] ?? null) : null;
        $dashboardPubB64   = is_array($body) ? ($body['dashboard_public_key'] ?? null) : null;
        $challengeB64      = is_array($body) ? ($body['callback_challenge'] ?? null) : null;

        if (!is_string($code) || $code === '') {
            return ErrorResponse::create(400, 'connector.missing_code', 'Missing or invalid code field.');
        }
        if (!is_string($dashboardPubB64) || $dashboardPubB64 === '') {
            return ErrorResponse::create(400, 'connector.missing_dashboard_key', 'Missing dashboard_public_key field.');
        }
        if (!is_string($challengeB64) || $challengeB64 === '') {
            return ErrorResponse::create(400, 'connector.missing_challenge', 'Missing callback_challenge field.');
        }

        // Validate dashboard public key well-formedness (must be base64 of 32 bytes).
        $dashboardPubRaw = base64_decode($dashboardPubB64, true);
        if ($dashboardPubRaw === false || strlen($dashboardPubRaw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return ErrorResponse::create(400, 'connector.invalid_dashboard_key', 'dashboard_public_key is not a valid 32-byte base64 Ed25519 key.');
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

        // Spec § 8 step 7 ordering: expired-before-consumed (locked in by F4 cleanup commit 90aee1a).
        $expiresAt = (int) $state->get('code_expires_at', 0);
        if ($expiresAt > 0 && time() >= $expiresAt) {
            return ErrorResponse::create(410, 'connector.code_expired', 'Connection code has expired. Generate a new one.');
        }

        if (!empty($state->get('code_consumed_at'))) {
            return ErrorResponse::create(409, 'connector.code_consumed', 'Connection code has already been consumed.');
        }

        // Happy path: sign the dashboard's challenge with K_site, persist handshake state.
        $privateKeyBase64 = (string) $state->get('site_private_key', '');
        $signature = Signer::sign($challengeB64, $privateKeyBase64);

        $now = time();
        $state->update([
            'state'                 => 'connected',
            'code_consumed_at'      => $now,
            'dashboard_public_key'  => $dashboardPubB64,
            'connected_at'          => gmdate('c', $now),
        ]);

        return new WP_REST_Response([
            'site_public_key'     => (string) $state->get('site_public_key', ''),
            'challenge_signature' => $signature,
            'site_url'            => get_site_url(),
            'site_name'           => get_bloginfo('name'),
        ], 200);
    }
}
```

- [ ] **Step 6: Run tests to verify everything passes**

```bash
./vendor/bin/phpunit --testsuite integration --filter ConnectTest
```

Expected: all ConnectTest cases green. Updated happy path + 5 F4 error paths (each updated per Step 3) + F4 cleanup `testConsumedAndExpiredCodeReturns410ExpiryWins` + 3 new dashboard-key/challenge validation tests.

- [ ] **Step 7: Commit**

```bash
git add packages/connector-plugin/src/Rest/ConnectController.php \
        packages/connector-plugin/tests/Integration/Rest/ConnectTest.php
git commit -m "F5: extend connector POST /connect — sign challenge + return handshake response"
```

---

## Task 14: Jobs/CompleteConnection.php — thin AS-job wrapper

**Files:**
- Create: `packages/dashboard-plugin/src/Jobs/CompleteConnection.php`
- Modify: `packages/dashboard-plugin/src/Plugin.php` (register the AS hook)
- Create: `packages/dashboard-plugin/tests/Integration/Jobs/CompleteConnectionTest.php`

The AS handler. Action Scheduler invokes `defyn_complete_connection` with args `[site_id, code, url]`. Our handler constructs a `Connection` service with real dependencies and calls `complete()`. The integration test exercises `Connection` directly (already covered in Task 8); this task's tests just verify the AS hook is correctly wired.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Jobs;

use Defyn\Dashboard\Jobs\CompleteConnection;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class CompleteConnectionTest extends WP_UnitTestCase
{
    public function testActionSchedulerHookIsRegistered(): void
    {
        // Plugin::boot() should have registered this on rest_api_init / activation.
        // Force the action to run by simulating Action Scheduler's dispatch.
        // We don't care about side effects here (Connection has its own tests);
        // we just verify the hook exists and is callable.
        self::assertTrue(has_action('defyn_complete_connection') !== false);
    }

    public function testHandleStaticMethodIsInvocableWithoutFatal(): void
    {
        // Smoke test — Connection's behavior is covered by ConnectionTest.
        // Here we just ensure the wrapper constructs everything correctly:
        // no TypeError, no missing-class, no missing-const.
        // It's OK if the call ultimately marks site=error because the site_id
        // doesn't exist (no row, so findById returns null and complete() exits).
        if (!defined('DEFYN_VAULT_KEY')) {
            define('DEFYN_VAULT_KEY', \Defyn\Dashboard\Crypto\Vault::generateKey());
        }
        CompleteConnection::handle(999999, 'STUB', 'https://nowhere.test');
        self::assertTrue(true);  // didn't throw
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
./vendor/bin/phpunit --testsuite integration --filter CompleteConnectionTest
```

Expected: class not found (and the has_action assertion would fail too).

- [ ] **Step 3: Write `CompleteConnection.php`**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\Connection;
use Defyn\Dashboard\Services\SitesRepository;

/**
 * Action Scheduler entry point for `defyn_complete_connection`.
 *
 * Thin static wrapper that wires concrete dependencies and delegates to the
 * Connection service (which has its own test coverage). Tests bypass this
 * wrapper by calling Connection directly with mocked dependencies.
 *
 * Hook registration lives in Plugin::boot() so it fires once per request.
 */
final class CompleteConnection
{
    public static function handle(int $siteId, string $code, string $url): void
    {
        $repo = new SitesRepository();
        $site = $repo->findById($siteId);
        if ($site === null) {
            return;  // site was deleted between schedule and execute
        }

        // The dashboard's per-site public key (un-encrypted; lives in our_public_key column).
        $dashboardPub = (string) $site->ourPublicKey;

        $connection = new Connection(
            httpClient: new SignedHttpClient(),
            repo:       $repo,
            logger:     new ActivityLogger(),
            dashboardPublicKey: $dashboardPub,
        );

        $connection->complete($siteId, $code, $url);
    }
}
```

- [ ] **Step 4: Register the AS hook in `Plugin.php`**

Open `packages/dashboard-plugin/src/Plugin.php`. Inside `boot()`, AFTER the existing `add_action('rest_api_init', ...)` block, append:

```php
        add_action('defyn_complete_connection', [\Defyn\Dashboard\Jobs\CompleteConnection::class, 'handle'], 10, 3);
```

- [ ] **Step 5: Run test to verify it passes**

```bash
./vendor/bin/phpunit --testsuite integration --filter CompleteConnectionTest
```

Expected: `OK (2 tests, ...)`.

- [ ] **Step 6: Run the full dashboard suite as a sanity check**

```bash
./vendor/bin/phpunit
```

Expected: dashboard at ~119 tests, all green. No regressions on F1–F3a tests.

- [ ] **Step 7: Commit**

```bash
git add packages/dashboard-plugin/src/Jobs/CompleteConnection.php \
        packages/dashboard-plugin/src/Plugin.php \
        packages/dashboard-plugin/tests/Integration/Jobs/CompleteConnectionTest.php
git commit -m "F5: CompleteConnection AS job + Plugin hook registration"
```

---

## Task 15: SPA — Zod schemas + MSW handlers for sites

**Files:**
- Create: `apps/web/src/types/api.ts`
- Modify: `apps/web/src/test/handlers.ts`
- Create: `apps/web/tests/api.test.ts`

Zod schemas double as TypeScript types for site rows + the Create-Site request body. MSW handlers extend the existing auth mocks with three /sites endpoints so React component tests can drive realistic backend behavior.

- [ ] **Step 1: Write the failing schema test**

```ts
// apps/web/tests/api.test.ts
import { describe, it, expect } from 'vitest';
import { siteSchema, createSiteSchema, sitesListSchema } from '@/types/api';

describe('siteSchema', () => {
  it('parses a fully-populated site row from the backend', () => {
    const parsed = siteSchema.parse({
      id: 1,
      url: 'https://example.test',
      label: 'Site',
      status: 'active',
      last_contact_at: '2026-05-11 00:00:00',
      last_error: null,
      created_at: '2026-05-11 00:00:00',
    });
    expect(parsed.status).toBe('active');
  });

  it('accepts pending status without last_contact_at', () => {
    const parsed = siteSchema.parse({
      id: 1,
      url: 'https://example.test',
      label: '',
      status: 'pending',
      last_contact_at: null,
      last_error: null,
      created_at: '2026-05-11 00:00:00',
    });
    expect(parsed.last_contact_at).toBeNull();
  });

  it('rejects unknown status values', () => {
    expect(() => siteSchema.parse({
      id: 1, url: 'https://example.test', label: '', status: 'mystery',
      last_contact_at: null, last_error: null, created_at: '2026-05-11 00:00:00',
    })).toThrow();
  });
});

describe('createSiteSchema', () => {
  it('requires url, label, code', () => {
    const parsed = createSiteSchema.parse({
      url: 'https://example.test',
      label: 'Site',
      code: 'ABCDEFGH2345',
    });
    expect(parsed.url).toBe('https://example.test');
  });

  it('rejects http URLs (must be https)', () => {
    expect(() => createSiteSchema.parse({
      url: 'http://insecure.test', label: '', code: 'X',
    })).toThrow();
  });

  it('rejects code shorter than 12 chars', () => {
    expect(() => createSiteSchema.parse({
      url: 'https://example.test', label: '', code: 'SHORT',
    })).toThrow();
  });
});

describe('sitesListSchema', () => {
  it('parses an empty list', () => {
    const parsed = sitesListSchema.parse({ sites: [] });
    expect(parsed.sites).toHaveLength(0);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd apps/web
pnpm test -- api.test
```

Expected: import errors (`@/types/api` not found).

- [ ] **Step 3: Write `apps/web/src/types/api.ts`**

```ts
import { z } from 'zod';

export const siteStatusSchema = z.enum(['pending', 'active', 'error']);
export type SiteStatus = z.infer<typeof siteStatusSchema>;

export const siteSchema = z.object({
  id: z.number().int().positive(),
  url: z.string().url(),
  label: z.string(),
  status: siteStatusSchema,
  last_contact_at: z.string().nullable(),
  last_error: z.string().nullable(),
  created_at: z.string(),
});
export type Site = z.infer<typeof siteSchema>;

export const sitesListSchema = z.object({
  sites: z.array(siteSchema),
});

export const createSiteSchema = z.object({
  url: z.string().url().startsWith('https://', 'URL must start with https://'),
  label: z.string(),
  code: z.string().length(12, 'Code must be 12 characters'),
});
export type CreateSiteInput = z.infer<typeof createSiteSchema>;

export const createSiteResponseSchema = z.object({
  site_id: z.number().int().positive(),
});
```

- [ ] **Step 4: Extend MSW handlers**

Open `apps/web/src/test/handlers.ts`. Append AFTER the existing `/auth/logout` handler:

```ts
import type { Site } from '@/types/api';

// In-memory site store for MSW — tests can manipulate this directly between requests.
export const mockSites: Site[] = [];
export let nextSiteId = 1;

export function resetMockSites(): void {
  mockSites.length = 0;
  nextSiteId = 1;
}

// POST /sites — create pending site, returns 202.
handlers.push(
  http.post('*/wp-json/defyn/v1/sites', async ({ request }) => {
    const body = (await request.json()) as { url?: string; label?: string; code?: string };
    if (!body.url || !body.code) {
      return HttpResponse.json(
        { error: { code: 'sites.missing_fields', message: 'url and code required' } },
        { status: 400 },
      );
    }
    if (!body.url.startsWith('https://')) {
      return HttpResponse.json(
        { error: { code: 'sites.invalid_url', message: 'URL must use HTTPS' } },
        { status: 400 },
      );
    }
    if (mockSites.some((s) => s.url.toLowerCase() === body.url!.toLowerCase())) {
      return HttpResponse.json(
        { error: { code: 'sites.duplicate_url', message: 'This URL is already managed' } },
        { status: 409 },
      );
    }
    const site: Site = {
      id: nextSiteId++,
      url: body.url,
      label: body.label ?? '',
      status: 'pending',
      last_contact_at: null,
      last_error: null,
      created_at: new Date().toISOString().replace('T', ' ').slice(0, 19),
    };
    mockSites.push(site);
    return HttpResponse.json({ site_id: site.id }, { status: 202 });
  }),

  // GET /sites — list.
  http.get('*/wp-json/defyn/v1/sites', () => HttpResponse.json({ sites: mockSites }, { status: 200 })),

  // GET /sites/{id} — show.
  http.get('*/wp-json/defyn/v1/sites/:id', ({ params }) => {
    const id = Number(params.id);
    const site = mockSites.find((s) => s.id === id);
    if (!site) {
      return HttpResponse.json(
        { error: { code: 'sites.not_found', message: 'Site not found' } },
        { status: 404 },
      );
    }
    return HttpResponse.json(site, { status: 200 });
  }),
);
```

- [ ] **Step 5: Run test to verify it passes**

```bash
pnpm test -- api.test
```

Expected: 7 / 7 passing across the three describe blocks.

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/types/api.ts apps/web/src/test/handlers.ts apps/web/tests/api.test.ts
git commit -m "F5: SPA — Zod site schemas + MSW handlers for sites endpoints"
```

---

## Task 16: SPA — TanStack Query hooks (useSites, useSite)

**Files:**
- Create: `apps/web/src/lib/queries/useSites.ts`
- Create: `apps/web/src/lib/queries/useSite.ts`
- Create: `apps/web/tests/useSites.test.ts`
- Create: `apps/web/tests/useSite.test.ts`

`useSites()` fetches the list. `useSite(id, { poll })` fetches one site, with optional polling (2-second interval) that the SiteDetail page uses while the site is `pending`.

- [ ] **Step 1: Write the failing test for `useSites`**

```ts
// apps/web/tests/useSites.test.ts
import { describe, it, expect, beforeEach } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import * as React from 'react';
import { useSites } from '@/lib/queries/useSites';
import { resetMockSites, mockSites } from '@/test/handlers';
import { setAccessToken } from '@/lib/apiClient';

function wrapper({ children }: { children: React.ReactNode }) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

describe('useSites', () => {
  beforeEach(() => {
    resetMockSites();
    setAccessToken('fake');
  });

  it('returns an empty list when the user has no sites', async () => {
    const { result } = renderHook(() => useSites(), { wrapper });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data?.sites).toEqual([]);
  });

  it('returns the user list when populated', async () => {
    mockSites.push({
      id: 1, url: 'https://a.test', label: '', status: 'active',
      last_contact_at: '2026-05-11 00:00:00', last_error: null, created_at: '2026-05-11 00:00:00',
    });
    const { result } = renderHook(() => useSites(), { wrapper });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data?.sites).toHaveLength(1);
    expect(result.current.data?.sites[0].url).toBe('https://a.test');
  });
});
```

- [ ] **Step 2: Write the failing test for `useSite`**

```ts
// apps/web/tests/useSite.test.ts
import { describe, it, expect, beforeEach } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import * as React from 'react';
import { useSite } from '@/lib/queries/useSite';
import { resetMockSites, mockSites } from '@/test/handlers';
import { setAccessToken } from '@/lib/apiClient';

function wrapper({ children }: { children: React.ReactNode }) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

describe('useSite', () => {
  beforeEach(() => {
    resetMockSites();
    setAccessToken('fake');
  });

  it('returns the site when found', async () => {
    mockSites.push({
      id: 42, url: 'https://x.test', label: '', status: 'pending',
      last_contact_at: null, last_error: null, created_at: '2026-05-11 00:00:00',
    });
    const { result } = renderHook(() => useSite(42), { wrapper });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data?.id).toBe(42);
  });

  it('throws ApiError with sites.not_found code on missing site', async () => {
    const { result } = renderHook(() => useSite(999), { wrapper });
    await waitFor(() => expect(result.current.isError).toBe(true));
    expect((result.current.error as { code: string }).code).toBe('sites.not_found');
  });
});
```

- [ ] **Step 3: Run tests to verify they fail**

```bash
pnpm test -- useSites useSite
```

Expected: import errors.

- [ ] **Step 4: Write `useSites.ts`**

```ts
// apps/web/src/lib/queries/useSites.ts
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { sitesListSchema } from '@/types/api';

export function useSites() {
  return useQuery({
    queryKey: ['sites'],
    queryFn: async () => {
      const data = await apiClient.get<unknown>('/sites');
      return sitesListSchema.parse(data);
    },
  });
}
```

- [ ] **Step 5: Write `useSite.ts`**

```ts
// apps/web/src/lib/queries/useSite.ts
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { siteSchema, type Site } from '@/types/api';

interface UseSiteOptions {
  /** Poll every N ms while site.status === 'pending'. Set to 0 to disable. */
  pollWhilePending?: number;
}

export function useSite(id: number, opts: UseSiteOptions = {}) {
  const pollInterval = opts.pollWhilePending ?? 0;
  return useQuery({
    queryKey: ['sites', id],
    queryFn: async () => {
      const data = await apiClient.get<unknown>(`/sites/${id}`);
      return siteSchema.parse(data);
    },
    refetchInterval: (query) => {
      const data = query.state.data as Site | undefined;
      if (data?.status === 'pending' && pollInterval > 0) {
        return pollInterval;
      }
      return false;
    },
  });
}
```

- [ ] **Step 6: Run tests to verify they pass**

```bash
pnpm test -- useSites useSite
```

Expected: 4 tests passing.

- [ ] **Step 7: Commit**

```bash
git add apps/web/src/lib/queries/ apps/web/tests/useSites.test.ts apps/web/tests/useSite.test.ts
git commit -m "F5: SPA — useSites + useSite TanStack Query hooks (with polling)"
```

---

## Task 17: SPA — SitesList route

**Files:**
- Create: `apps/web/src/routes/SitesList.tsx`

A minimal landing page: heading + Add Site button + the user's sites in a basic list. Detail view (Task 19) is a separate route. F8 will polish this; F5 just needs it to exist so the user has somewhere to navigate after login.

- [ ] **Step 1: Create the route**

```tsx
// apps/web/src/routes/SitesList.tsx
import { Link } from 'react-router-dom';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useSites } from '@/lib/queries/useSites';

export default function SitesList() {
  const { data, isLoading, isError, error } = useSites();

  return (
    <div className="min-h-screen p-8">
      <div className="max-w-3xl mx-auto space-y-6">
        <div className="flex items-center justify-between">
          <h1 className="text-2xl font-semibold">Sites</h1>
          <Button asChild>
            <Link to="/sites/add">Add Site</Link>
          </Button>
        </div>

        {isLoading && <p className="text-sm text-zinc-500">Loading…</p>}
        {isError && (
          <p className="text-sm text-red-600">
            Could not load sites. {(error as { message?: string }).message}
          </p>
        )}

        {data?.sites.length === 0 && (
          <Card>
            <CardHeader>
              <CardTitle>No sites yet</CardTitle>
            </CardHeader>
            <CardContent>
              <p className="text-sm text-zinc-600">
                Generate a connection code on a WordPress site that has the connector plugin installed,
                then paste it into the Add Site form.
              </p>
            </CardContent>
          </Card>
        )}

        <div className="space-y-2">
          {data?.sites.map((site) => (
            <Link key={site.id} to={`/sites/${site.id}`} className="block">
              <Card className="hover:bg-zinc-50">
                <CardContent className="flex items-center justify-between p-4">
                  <div>
                    <p className="font-medium">{site.label || site.url}</p>
                    <p className="text-xs text-zinc-500">{site.url}</p>
                  </div>
                  <span
                    className={
                      'rounded px-2 py-1 text-xs uppercase ' +
                      (site.status === 'active'
                        ? 'bg-green-100 text-green-800'
                        : site.status === 'pending'
                        ? 'bg-yellow-100 text-yellow-800'
                        : 'bg-red-100 text-red-800')
                    }
                  >
                    {site.status}
                  </span>
                </CardContent>
              </Card>
            </Link>
          ))}
        </div>
      </div>
    </div>
  );
}
```

- [ ] **Step 2: Smoke-build to confirm typecheck passes**

```bash
pnpm lint  # alias for tsc --noEmit per package.json
```

Expected: no TypeScript errors.

- [ ] **Step 3: Commit**

```bash
git add apps/web/src/routes/SitesList.tsx
git commit -m "F5: SPA — SitesList route (minimal — F8 will polish)"
```

---

## Task 18: SPA — SiteAdd route (form + redirect)

**Files:**
- Create: `apps/web/src/routes/SiteAdd.tsx`
- Create: `apps/web/tests/SiteAdd.test.tsx`

React Hook Form + Zod resolver against `createSiteSchema`. On submit POSTs to `/sites`, then navigates to `/sites/${site_id}` for polling.

- [ ] **Step 1: Write the failing test**

```tsx
// apps/web/tests/SiteAdd.test.tsx
import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import * as React from 'react';
import SiteAdd from '@/routes/SiteAdd';
import { resetMockSites } from '@/test/handlers';
import { setAccessToken } from '@/lib/apiClient';

function renderWith(initialPath = '/sites/add') {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={[initialPath]}>
        <Routes>
          <Route path="/sites/add" element={<SiteAdd />} />
          <Route path="/sites/:id" element={<div data-testid="site-detail-mock" />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('SiteAdd', () => {
  beforeEach(() => {
    resetMockSites();
    setAccessToken('fake');
  });

  it('renders the form fields', () => {
    renderWith();
    expect(screen.getByLabelText(/URL/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/Label/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/Code/i)).toBeInTheDocument();
  });

  it('shows a field error for an http (non-https) URL', async () => {
    const user = userEvent.setup();
    renderWith();
    await user.type(screen.getByLabelText(/URL/i), 'http://insecure.test');
    await user.type(screen.getByLabelText(/Code/i), 'ABCDEFGH2345');
    await user.click(screen.getByRole('button', { name: /Add Site/i }));
    expect(await screen.findByText(/URL must start with https/i)).toBeInTheDocument();
  });

  it('navigates to the detail route on success', async () => {
    const user = userEvent.setup();
    renderWith();
    await user.type(screen.getByLabelText(/URL/i), 'https://example.test');
    await user.type(screen.getByLabelText(/Label/i), 'My site');
    await user.type(screen.getByLabelText(/Code/i), 'ABCDEFGH2345');
    await user.click(screen.getByRole('button', { name: /Add Site/i }));
    expect(await screen.findByTestId('site-detail-mock')).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
pnpm test -- SiteAdd
```

Expected: import errors.

- [ ] **Step 3: Write the component**

```tsx
// apps/web/src/routes/SiteAdd.tsx
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import * as React from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Alert } from '@/components/ui/alert';
import { apiClient, ApiError } from '@/lib/apiClient';
import { createSiteSchema, createSiteResponseSchema, type CreateSiteInput } from '@/types/api';

export default function SiteAdd() {
  const navigate = useNavigate();
  const { register, handleSubmit, formState: { errors } } = useForm<CreateSiteInput>({
    resolver: zodResolver(createSiteSchema),
    defaultValues: { url: '', label: '', code: '' },
  });

  const mutation = useMutation({
    mutationFn: async (input: CreateSiteInput) => {
      const data = await apiClient.post<unknown>('/sites', input);
      return createSiteResponseSchema.parse(data);
    },
    onSuccess: (data) => navigate(`/sites/${data.site_id}`),
  });

  const onSubmit = handleSubmit((values) => mutation.mutate(values));

  return (
    <div className="min-h-screen p-8">
      <Card className="max-w-xl mx-auto">
        <CardHeader>
          <CardTitle>Add Site</CardTitle>
        </CardHeader>
        <CardContent>
          {mutation.isError && (
            <Alert className="mb-4">
              {(mutation.error as ApiError).message || 'Something went wrong.'}
            </Alert>
          )}
          <form onSubmit={onSubmit} className="space-y-4">
            <div className="space-y-1">
              <Label htmlFor="url">URL</Label>
              <Input id="url" placeholder="https://example.com" {...register('url')} />
              {errors.url && <p className="text-xs text-red-600">{errors.url.message}</p>}
            </div>
            <div className="space-y-1">
              <Label htmlFor="label">Label</Label>
              <Input id="label" placeholder="Optional name" {...register('label')} />
            </div>
            <div className="space-y-1">
              <Label htmlFor="code">Code</Label>
              <Input id="code" placeholder="12-character code from the connector" {...register('code')} />
              {errors.code && <p className="text-xs text-red-600">{errors.code.message}</p>}
            </div>
            <Button type="submit" disabled={mutation.isPending}>
              {mutation.isPending ? 'Adding…' : 'Add Site'}
            </Button>
          </form>
        </CardContent>
      </Card>
    </div>
  );
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
pnpm test -- SiteAdd
```

Expected: 3 / 3 passing.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/routes/SiteAdd.tsx apps/web/tests/SiteAdd.test.tsx
git commit -m "F5: SPA — TDD SiteAdd form (RHF + Zod + POST /sites)"
```

---

## Task 19: SPA — SiteDetail route (polling pending→active)

**Files:**
- Create: `apps/web/src/routes/SiteDetail.tsx`
- Create: `apps/web/tests/SiteDetail.test.tsx`

Detail view that polls `GET /sites/{id}` every 2 seconds while `status === 'pending'`, then shows the connected/error state when polling stops.

- [ ] **Step 1: Write the failing test**

```tsx
// apps/web/tests/SiteDetail.test.tsx
import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import * as React from 'react';
import SiteDetail from '@/routes/SiteDetail';
import { resetMockSites, mockSites } from '@/test/handlers';
import { setAccessToken } from '@/lib/apiClient';

function renderAt(id: number) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={[`/sites/${id}`]}>
        <Routes>
          <Route path="/sites/:id" element={<SiteDetail />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('SiteDetail', () => {
  beforeEach(() => {
    resetMockSites();
    setAccessToken('fake');
  });

  it('shows pending state and a connecting message', async () => {
    mockSites.push({
      id: 1, url: 'https://x.test', label: 'X', status: 'pending',
      last_contact_at: null, last_error: null, created_at: '2026-05-11 00:00:00',
    });
    renderAt(1);
    expect(await screen.findByText(/Connecting/i)).toBeInTheDocument();
    expect(await screen.findByText('https://x.test')).toBeInTheDocument();
  });

  it('shows active state with last_contact_at', async () => {
    mockSites.push({
      id: 1, url: 'https://x.test', label: '', status: 'active',
      last_contact_at: '2026-05-11 00:07:00', last_error: null, created_at: '2026-05-11 00:00:00',
    });
    renderAt(1);
    expect(await screen.findByText(/Connected/i)).toBeInTheDocument();
  });

  it('shows error state with last_error', async () => {
    mockSites.push({
      id: 1, url: 'https://x.test', label: '', status: 'error',
      last_contact_at: null, last_error: 'Challenge signature invalid',
      created_at: '2026-05-11 00:00:00',
    });
    renderAt(1);
    expect(await screen.findByText('Challenge signature invalid')).toBeInTheDocument();
  });

  it('shows a not-found message on 404', async () => {
    renderAt(999);
    await waitFor(() => expect(screen.getByText(/not found/i)).toBeInTheDocument());
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
pnpm test -- SiteDetail
```

Expected: import errors.

- [ ] **Step 3: Write the component**

```tsx
// apps/web/src/routes/SiteDetail.tsx
import { useParams, Link } from 'react-router-dom';
import * as React from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { useSite } from '@/lib/queries/useSite';
import { ApiError } from '@/lib/apiClient';

export default function SiteDetail() {
  const { id } = useParams<{ id: string }>();
  const siteId = Number(id);
  const { data, isLoading, isError, error } = useSite(siteId, { pollWhilePending: 2000 });

  if (isError) {
    const apiErr = error as ApiError;
    if (apiErr.code === 'sites.not_found') {
      return (
        <div className="min-h-screen p-8 max-w-xl mx-auto">
          <Card>
            <CardHeader>
              <CardTitle>Site not found</CardTitle>
            </CardHeader>
            <CardContent>
              <Button asChild>
                <Link to="/sites">Back to sites</Link>
              </Button>
            </CardContent>
          </Card>
        </div>
      );
    }
    return (
      <div className="min-h-screen p-8 max-w-xl mx-auto">
        <Alert>{apiErr.message}</Alert>
      </div>
    );
  }

  if (isLoading || !data) {
    return (
      <div className="min-h-screen p-8 max-w-xl mx-auto">
        <p className="text-sm text-zinc-500">Loading…</p>
      </div>
    );
  }

  return (
    <div className="min-h-screen p-8 max-w-xl mx-auto">
      <Card>
        <CardHeader>
          <CardTitle>{data.label || data.url}</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <p className="text-sm text-zinc-600">{data.url}</p>

          {data.status === 'pending' && (
            <p className="text-sm">Connecting to the site…</p>
          )}
          {data.status === 'active' && (
            <p className="text-sm text-green-700">
              Connected. Last contact: {data.last_contact_at}.
            </p>
          )}
          {data.status === 'error' && data.last_error && (
            <Alert>{data.last_error}</Alert>
          )}

          <Button asChild variant="outline">
            <Link to="/sites">Back to sites</Link>
          </Button>
        </CardContent>
      </Card>
    </div>
  );
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
pnpm test -- SiteDetail
```

Expected: 4 / 4 passing.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/routes/SiteDetail.tsx apps/web/tests/SiteDetail.test.tsx
git commit -m "F5: SPA — TDD SiteDetail with status polling (pending→active|error)"
```

---

## Task 20: SPA — Wire new routes in App.tsx + redirect Home

**Files:**
- Modify: `apps/web/src/App.tsx`
- Modify: `apps/web/src/routes/Home.tsx`

Add three new authenticated routes and make `/` redirect to `/sites` so logged-in users land somewhere useful.

- [ ] **Step 1: Update `App.tsx`**

Replace `apps/web/src/App.tsx` entirely with:

```tsx
import { Routes, Route } from 'react-router-dom';
import Login from './routes/Login';
import Home from './routes/Home';
import RequireAuth from './routes/RequireAuth';
import SitesList from './routes/SitesList';
import SiteAdd from './routes/SiteAdd';
import SiteDetail from './routes/SiteDetail';

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<Login />} />
      <Route element={<RequireAuth />}>
        <Route path="/" element={<Home />} />
        <Route path="/sites" element={<SitesList />} />
        <Route path="/sites/add" element={<SiteAdd />} />
        <Route path="/sites/:id" element={<SiteDetail />} />
      </Route>
    </Routes>
  );
}
```

- [ ] **Step 2: Update `Home.tsx` to redirect to /sites**

Replace `apps/web/src/routes/Home.tsx` entirely with:

```tsx
import { Navigate } from 'react-router-dom';

/**
 * The root authenticated route. Sends users to /sites; the welcome card
 * from F3b moves to either the sites list (post-F5) or a dedicated
 * dashboard page (post-F9 when activity log lands).
 */
export default function Home() {
  return <Navigate to="/sites" replace />;
}
```

- [ ] **Step 3: Run full Vitest suite to confirm nothing broke**

```bash
cd apps/web
pnpm test
```

Expected: all SPA tests pass — F3b's 20 + Task 15's 7 + Task 16's 4 + Task 18's 3 + Task 19's 4 = ~38 tests.

- [ ] **Step 4: Build to confirm production compile passes**

```bash
pnpm build
```

Expected: TypeScript compiles, Vite outputs `dist/`. No errors.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/App.tsx apps/web/src/routes/Home.tsx
git commit -m "F5: SPA — wire new /sites routes; Home redirects to /sites"
```

---

## Task 21: Manual smoke E2E + README updates + merge to main

**Files:**
- Modify: `packages/connector-plugin/README.md` (update REST API table to reflect handshake response)
- Create: `packages/dashboard-plugin/README.md` (minimal — there's no README in the dashboard plugin yet; create for symmetry with connector-plugin)

This is the proof-of-life task. The plan deliverables are only meaningfully "done" if the full handshake works end-to-end against a live local environment.

- [ ] **Step 1: Symlink the connector plugin into the local WP install (if not already)**

```bash
ln -s "/Users/pradeep/Local Sites/defynWP/packages/connector-plugin" \
      "/Users/pradeep/Local Sites/defynWP/app/public/wp-content/plugins/defyn-connector"
```

- [ ] **Step 2: Activate both plugins in wp-admin**

Navigate to `https://defynwp.local/wp-admin/plugins.php`. Activate **DefynWP Connector**. (Dashboard should already be active from F3a.)

- [ ] **Step 3: Generate a connection code**

`Settings → DefynWP Connector` → click **Generate Connection Code**. Copy the 12-character code.

- [ ] **Step 4: Start the SPA**

```bash
cd apps/web
pnpm dev
```

Open `http://localhost:5173`. Log in with the admin account.

- [ ] **Step 5: Add the site through the SPA**

- Click **Add Site**
- URL: `https://defynwp.local` (or whatever your Local site domain is)
- Label: anything
- Code: paste the code from Step 3
- Submit

Expected: redirected to `/sites/{id}` showing "Connecting…". Within a few seconds, flips to "Connected" with a `last_contact_at` timestamp.

- [ ] **Step 6: Verify the dashboard side via SQL**

```bash
# in a terminal inside the Local site environment
mysql -h 127.0.0.1 -P 10140 -uroot -proot local
```

```sql
SELECT id, url, status, LEFT(site_public_key, 20) AS sitepub_head, last_contact_at, last_error
  FROM wp_defyn_sites;

SELECT id, user_id, site_id, event_type, LEFT(details, 80) AS details
  FROM wp_defyn_activity_log
  ORDER BY id DESC LIMIT 5;
```

Expected: the site row shows `status='active'`, populated `site_public_key`, populated `last_contact_at`. The activity log has at least one `site.connected` row for this site.

- [ ] **Step 7: Verify the connector side**

In the connector wp_options:

```sql
SELECT option_value FROM wp_options WHERE option_name = 'defyn_connector' \G
```

Expected: JSON with `"state": "connected"`, populated `"dashboard_public_key"`, populated `"connected_at"`.

- [ ] **Step 8: Update README files**

In `packages/connector-plugin/README.md`, update the REST API section's row for `/connect` to reflect the F5 handshake response shape: the response body is now `{site_public_key, challenge_signature, site_url, site_name}` instead of `{ok: true}`, and the state machine includes `connected` as the new terminal state.

In `packages/dashboard-plugin/README.md` (create if absent), add a minimal README mirroring the connector's structure — at minimum: title + one-paragraph description + install steps + REST API table showing the four `/auth/*` endpoints (from F3a) plus the three new `/sites/*` endpoints (POST/GET-list/GET-show).

- [ ] **Step 9: Run both PHPUnit suites + the Vitest suite + the build one more time**

```bash
cd packages/dashboard-plugin && ./vendor/bin/phpunit
cd ../connector-plugin && ./vendor/bin/phpunit
cd ../../apps/web && pnpm test && pnpm build
```

Expected: dashboard ~119/all green, connector ~31/all green, SPA ~38/all green, build succeeds.

- [ ] **Step 10: Commit README updates + push branch + merge to main**

```bash
cd /Users/pradeep/Local\ Sites/defynWP
git add packages/connector-plugin/README.md packages/dashboard-plugin/README.md
git commit -m "F5: docs — update connector README handshake response; add dashboard README"
git push -u origin f5-handshake-e2e
```

Then merge `--no-ff` into `main` per the established F1–F4 pattern, tag `f5-handshake-complete`, push tags, delete the local branch:

```bash
git switch main
git merge --no-ff f5-handshake-e2e -m "Merge F5 (Handshake End-to-End) into main"
git tag f5-handshake-complete
git push origin main --tags
git branch -d f5-handshake-e2e
```

Wait for CI to go green on both plugin matrix jobs + the web job before declaring F5 done.

---

## Self-review summary

**Spec coverage:**
- § 4.1 (`wp_defyn_sites`, `wp_defyn_activity_log`) — read/written via Tasks 3-6, no migration needed; existing schemas fit
- § 4.2 (connector state JSON shape) — Task 13 adds `dashboard_public_key` + `connected_at` fields; state machine gains `connected` terminal
- § 5.3 (connector admin UI states) — `connected` state handled by extension to ConnectController (Task 13); the SettingsPage already displays the right messages for `unconfigured | awaiting-handshake | code-consumed` from F4. **GAP:** F5 doesn't add a `connected` render branch to SettingsPage — see "Carry-forwards" below.
- § 6.1 (Sites endpoints) — POST/GET-list/GET-show via Tasks 9-11; PATCH/DELETE/sync/ping deferred to F6/F8 per the explicit scoping note
- § 6.3 (`defyn_complete_connection` job) — Task 14
- § 7.1 (SPA routes) — `/sites`, `/sites/add`, `/sites/:id` via Tasks 17-20
- § 7.2 (auth flow) — unchanged; reuses F3a/F3b
- § 8 (10-step handshake) — Tasks 9 (POST /sites = steps 3-4), Task 14 + Connection (steps 5-6 + 8-9), Task 13 (step 7), Task 19 polling (step 5+10). Step 1 (plugin activation) + step 2 (Generate Code) shipped in F4 unchanged.
- § 9.1 (error envelope) — every new error code follows `{error: {code, message}}` via existing `ErrorResponse::create`

**Placeholder scan:** Tasks 1-21 all contain actual code blocks for every implementation step. No "TBD" / "add error handling" / "implement later" placeholders.

**Type consistency:**
- `Site` DTO properties (camelCase) match across Tasks 3, 4, 8, 9, 10, 11
- `siteSchema` / `createSiteSchema` (Zod) used identically in Tasks 15, 16, 18
- `apiClient.get/post` interface stable across SPA tasks (defined in F3b)
- `SignedHttpClient::postJson` return shape `{status, body, error}` used identically in Tasks 7, 8
- `Connection` constructor argument order matches across the test in Task 8 and the wrapper in Task 14
- Connector's `Signer::sign(message, privateKeyBase64): string` signature matches across Tasks 12, 13

**Architectural mismatch flagged in Task 8:** The dashboard's `Connection` service verifies the challenge signature against just the raw challenge (NOT challenge + nonce as spec § 8 step 7's "callback_challenge + nonce" phrasing might imply). This is intentional because the dashboard doesn't know the connector's `site_nonce` — the connector generates it locally. The connector signing just `challenge` is sufficient proof of key possession. The plan explicitly calls this out in Task 8's doctstring AND in Task 12's note (so the connector implementer in Task 13 signs just `$challengeB64`, not `$challengeB64 . $siteNonce`). If a future security review wants stricter nonce-binding, the connector would need to return its nonce in the response; track as a follow-up.

---

## Carry-forward follow-ups (out of scope, capture for F6+)

- **`SettingsPage` doesn't render the new `connected` state.** F4 had three branches (`unconfigured`, `awaiting-handshake`, `code-consumed`); F5 introduces `connected` but the SettingsPage render doesn't grow a branch for it — it'll fall through to the default "Generate Connection Code" form, which would let a user wipe an active handshake by accident. F6 (or a pre-F6 micro-cleanup) should add a `connected` render branch with the dashboard public-key fingerprint + handshake timestamp + a Disconnect button.
- **`SignedHttpClient` doesn't actually sign anything in F5.** Name says "Signed" because F6 will add X-Defyn-* signature headers via the canonical string from spec § 5.2 and the dashboard's per-site `our_private_key`. Until then the class is plain HTTPS POST. Rename to `HttpClient` if F6 ends up adding signing in a separate class — but most likely F6 just extends this one's `postJson`.
- **Action Scheduler runner.** This plan schedules jobs but doesn't verify Kinsta's WP-Cron / server-cron is firing on a reasonable cadence. F7 ("Background scheduling: recurring AS jobs, Kinsta server cron verified") explicitly covers this.
- **K_dash key rotation.** Per-site dashboard keys are generated once at site creation; the spec doesn't mention rotation. Add to F10's harden pass.
- **Race condition (carried from F4 review):** `ConnectorState::update` is read-modify-write without a lock. F5 makes this more meaningful — two concurrent /connect POSTs with the same valid code can both write `connected` state with different `dashboard_public_key`. Last write wins. Realistically the AS job runs once per scheduled action so this requires manual concurrent POSTs to reproduce, but worth tracking.
- **Wire-format gap (carried from F4 review):** WP REST 404/405 still bypass the `rest_request_after_callbacks` envelope normalizer. Both plugins inherit this. F6 or a dedicated cross-plugin micro-fix.
- **API divergence between dashboard `KeyPair` (object) and connector `KeyPair` (array) remains.** Acceptable for now (two independent plugins). Document as intentional or align — track for F10.
- **`composer.lock` policy mismatch (carried from F4):** connector-plugin gitignores its lock; dashboard-plugin commits its lock. Pick one before deploying to Kinsta (F10).
- **Dashboard PATCH/DELETE/sync/ping endpoints** per spec § 6.1 are deferred — F8 (sites UI) typically wants DELETE; F6 wants sync/ping for /status + /heartbeat.

---

## Test count projection after F5

| Suite | Pre-F5 | New | Post-F5 |
|---|---|---|---|
| dashboard-plugin (PHPUnit) | 89 | ~30 | ~119 |
| connector-plugin (PHPUnit) | 25 | ~6 (1 replaced, 3 new error tests, 1 new Signer unit class) | ~31 |
| apps/web (Vitest) | ~20 | ~18 (api 7 + useSites 2 + useSite 2 + SiteAdd 3 + SiteDetail 4) | ~38 |
