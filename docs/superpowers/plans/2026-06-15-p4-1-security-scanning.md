# P4.1 — Security Scanning: Detect & Show — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Match each managed site's already-collected plugin/theme/core versions against a cached Wordfence Intelligence vulnerability database, store the findings, and surface them on a per-site Security panel + an Overview "at-risk" attention chip.

**Architecture:** Dashboard-side only (the connector is untouched). A daily fan-out job refreshes a globally-cached vuln feed once, then scans each site by matching its inventory against the feed via a pure version-range matcher. Findings are stored as a per-site denormalized snapshot (replace-for-site). The vuln feed is the **Wordfence Intelligence v3 production feed**, authenticated with a free per-operator API key read from a `DEFYN_WORDFENCE_API_KEY` wp-config/env constant (no key in the DB, no SPA field, never logged); an empty key makes the refresh cleanly no-op.

**Tech Stack:** PHP 8.1 (WP plugin, PHPUnit/wp-phpunit), Action Scheduler, React 18 + TS + TanStack Query v5 + Zod + Vitest + MSW. Schema **v10 → v11**. Dashboard **v0.12.0 → v0.13.0**. Connector **unchanged (v0.1.7)**.

**Spec:** `docs/superpowers/specs/2026-06-14-p4-1-security-scanning-design.md` (revised at commit `ef4dd21`).

**Branch:** `p4-1-security-scanning` (off `main` @ `c2bcdad`).

---

## Conventions for every task

- **TDD:** write the failing test, run it red, implement minimal, run it green, commit.
- **PHP suite:** `composer test:unit` / `composer test:integration` / `composer test` from `packages/dashboard-plugin/`.
- **SPA suite (Node 22):** from `apps/web/` run `export FNM_DIR="$HOME/.fnm"; eval "$(fnm env --shell bash)"; fnm use 22` then `npm run test` (vitest run). A vitest run that prints "RUN" then hangs is a real render loop / bug — bisect the component, do NOT dismiss it as env (P2.10 lesson). `pkill -9 -f vitest` between runs.
- **Carry-forward failures to TOLERATE:** SPA 4 (`tests/SiteDetail.test.tsx` ×2 + `tests/components/sites/SiteCoreCard.test.tsx` ×2); PHP 1 (`UninstallTest` wp-phpunit TEMPORARY-TABLE infra limit). Any OTHER new failure is a real regression.
- **UTC everywhere** (`gmdate('Y-m-d H:i:s')`).
- **Namespaces:** PHP `Defyn\Dashboard\...`. Models in `src/Models/`, services in `src/Services/`, jobs in `src/Jobs/`, REST in `src/Rest/`, schema in `src/Schema/`.
- **REST envelope:** site-scoped GET endpoints return `{ data: {...}, error: null }` (mirror `SitesIncidentsController`); POST refresh/scan endpoints return a **direct** body (mirror `SitesPluginsRefreshController`). `/overview` is a direct payload (no envelope).
- Reuse existing helpers: `ErrorResponse::create(status, code, message)`, `ActivityLogger::log(?userId, ?siteId, eventType, ?details, ?ip)`, `SitesRepository::findByIdForUser` / `findById` / `findAllSchedulable`, `RequireAuth::check`.

---

## Task 1: Spike — verify the Wordfence v3 feed contract

**Goal:** Pin the live v3 feed URL, the auth mechanism, the free-tier terms, and the record JSON schema **before** any field names get baked into `VulnFeedService`. No production code in this task.

**Files:**
- Create: `docs/superpowers/notes/2026-06-15-wordfence-v3-feed.md`

- [ ] **Step 1: Confirm the endpoint + auth mechanism.** From a shell, probe the v3 production feed and capture the HTTP status + any `WWW-Authenticate`/error body **without** a key (expect 401, which confirms auth-is-required and often reveals the scheme):

```bash
curl -sS -o /dev/null -w '%{http_code}\n' 'https://www.wordfence.com/api/intelligence/v3/vulnerabilities/production'
curl -sS -D - -o /dev/null 'https://www.wordfence.com/api/intelligence/v3/vulnerabilities/production' | head -30
# Also try the documented scanner variant if production 401s differently:
curl -sS -o /dev/null -w '%{http_code}\n' 'https://www.wordfence.com/api/intelligence/v3/vulnerabilities/scanner'
```

- [ ] **Step 2: Read the public documentation** of the Wordfence Intelligence API v3 (the vulnerability record format + how the API key is passed + free-tier registration + license terms). Use WebFetch on `https://www.wordfence.com/help/wordfence-intelligence/` and the v3 API reference it links to. If the docs page is JS-gated/unscrapable, fall back to WebSearch for "Wordfence Intelligence v3 vulnerability feed API key record format".

- [ ] **Step 3: Write the findings note** `docs/superpowers/notes/2026-06-15-wordfence-v3-feed.md` capturing, concretely:
  - The exact **feed URL** to use (production vs scanner) and the **auth scheme** (`Authorization: Bearer <key>` header vs `?token=<key>` query param vs HTTP Basic). State which one, with evidence.
  - **Free-tier terms** (is the production/scanner feed available on the free key? rate/size limits? license — CC BY-SA / Wordfence terms — that allows offline caching).
  - The **record JSON schema** the feed returns, as a sample record with the fields `VulnFeedService` will map: per-vuln `id`/`uuid`, `title`, `cve(s)`, `cvss { score, rating }`, and the affected-software list — for each software entry: `type` (plugin/theme/core), `slug`, `name`, `affected_versions` (each with `from_version`, `from_inclusive`, `to_version`, `to_inclusive`), and `patched` / first-fixed version. Note the **actual JSON key names** (they differ from our column names; the mapping in Task 7 depends on this).
  - A one-line **go/no-go**: does the free v3 feed satisfy the design? If NOT (e.g. production feed is paid-only and only a smaller scanner feed is free), record exactly which free feed to use instead and any field-shape differences. This is the single decision that can change Task 7.

- [ ] **Step 4: Commit**

```bash
git add docs/superpowers/notes/2026-06-15-wordfence-v3-feed.md
git commit -m "docs(p4-1): verify Wordfence v3 feed contract (URL, auth, free-tier, schema)"
```

> **Guardrail:** if Step 3 reveals the free tier does NOT expose a usable bulk feed, STOP and escalate to the human before Task 7 — do not silently substitute a different paid source. The rest of the plan (matcher, scan, storage, UI) is source-agnostic and proceeds regardless; only Task 7's URL/auth/mapping depends on this note.

---

## Task 2: Schema v11 — two new tables + `last_security_scan_at` column

**Files:**
- Create: `src/Schema/VulnerabilitiesTable.php`
- Create: `src/Schema/SiteVulnerabilitiesTable.php`
- Modify: `src/Activation.php` (add to `TABLES`, bump `SCHEMA_VERSION`, add guarded ALTER)
- Test: `tests/Integration/SecurityScanningSchemaTest.php`
- Modify (version-pin): `tests/Integration/SchemaMigrationOnActivationTest.php` (and any `SchemaVersion*` pin asserting `10`)

- [ ] **Step 1: Write the failing schema test** `tests/Integration/SecurityScanningSchemaTest.php`:

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Schema\SchemaVersion;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Schema\VulnerabilitiesTable;
use Defyn\Dashboard\Schema\SiteVulnerabilitiesTable;

final class SecurityScanningSchemaTest extends AbstractSchemaTestCase
{
    public function testActivationCreatesVulnTablesAndBumpsToEleven(): void
    {
        Activation::activate();
        $this->assertTableExists(VulnerabilitiesTable::tableName());
        $this->assertTableExists(SiteVulnerabilitiesTable::tableName());
        self::assertSame(11, Activation::SCHEMA_VERSION);
        self::assertSame(Activation::SCHEMA_VERSION, SchemaVersion::current());
    }

    public function testSitesHasLastSecurityScanAtColumn(): void
    {
        global $wpdb;
        Activation::activate();
        $table = SitesTable::tableName();
        $col = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'last_security_scan_at'));
        self::assertSame('last_security_scan_at', $col);
    }

    public function testGuardedAlterIsIdempotent(): void
    {
        Activation::ensureSchema();
        Activation::ensureSchema();
        self::assertSame(Activation::SCHEMA_VERSION, SchemaVersion::current());
    }
}
```

- [ ] **Step 2: Run it red** — `composer test:integration -- --filter SecurityScanningSchemaTest` → FAIL (classes/column missing).

- [ ] **Step 3: Create `src/Schema/VulnerabilitiesTable.php`** (mirror `IncidentsTable`; NO `DESC` in indexes — dbDelta can't parse it):

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Schema;

/** P4.1 — wp_defyn_vulnerabilities: global cached Wordfence vuln DB (NOT per-site). */
final class VulnerabilitiesTable implements SchemaTable
{
    public static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'defyn_vulnerabilities';
    }

    public static function createSql(): string
    {
        global $wpdb;
        $table   = self::tableName();
        $charset = $wpdb->get_charset_collate();

        return "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_id VARCHAR(64) NOT NULL,
            type VARCHAR(10) NOT NULL,
            slug VARCHAR(191) NOT NULL,
            title TEXT NULL,
            severity VARCHAR(10) NOT NULL DEFAULT 'unknown',
            cvss_score DECIMAL(3,1) NULL DEFAULT NULL,
            cve VARCHAR(32) NULL DEFAULT NULL,
            from_version VARCHAR(32) NULL DEFAULT NULL,
            from_inclusive TINYINT NOT NULL DEFAULT 1,
            to_version VARCHAR(32) NULL DEFAULT NULL,
            to_inclusive TINYINT NOT NULL DEFAULT 0,
            fixed_in VARCHAR(32) NULL DEFAULT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_vuln_range (source_id, type, slug, from_version, to_version),
            KEY idx_vuln_type_slug (type, slug)
        ) {$charset};";
    }
}
```

> NOTE: MySQL treats two rows differing only in a NULL `from_version`/`to_version` as non-duplicate under a UNIQUE key (NULLs compare unequal). That is acceptable here — the upsert in Task 6 handles the NULL case by `DELETE`-ing prior rows for a `source_id` before re-insert (see Task 6), so the unique key is a secondary guard, not the sole idempotency mechanism.

- [ ] **Step 4: Create `src/Schema/SiteVulnerabilitiesTable.php`:**

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Schema;

/** P4.1 — wp_defyn_site_vulnerabilities: per-site denormalized findings snapshot. */
final class SiteVulnerabilitiesTable implements SchemaTable
{
    public static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'defyn_site_vulnerabilities';
    }

    public static function createSql(): string
    {
        global $wpdb;
        $table   = self::tableName();
        $charset = $wpdb->get_charset_collate();

        return "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            site_id BIGINT UNSIGNED NOT NULL,
            type VARCHAR(10) NOT NULL,
            slug VARCHAR(191) NOT NULL,
            component_name VARCHAR(191) NOT NULL,
            installed_version VARCHAR(32) NOT NULL,
            severity VARCHAR(10) NOT NULL DEFAULT 'unknown',
            cvss_score DECIMAL(3,1) NULL DEFAULT NULL,
            cve VARCHAR(32) NULL DEFAULT NULL,
            fixed_in VARCHAR(32) NULL DEFAULT NULL,
            title TEXT NULL,
            source_id VARCHAR(64) NOT NULL,
            scanned_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_sitevuln_site (site_id)
        ) {$charset};";
    }
}
```

- [ ] **Step 5: Edit `src/Activation.php`:**
  - Add imports: `use Defyn\Dashboard\Schema\VulnerabilitiesTable;` and `use Defyn\Dashboard\Schema\SiteVulnerabilitiesTable;`.
  - Append both classes to the `TABLES` constant (after `IncidentsTable::class`).
  - Bump `public const SCHEMA_VERSION = 11;`.
  - In `ensureSchema()`, after the P3.3 ALTER calls, add: `self::addLastSecurityScanAtColumn($wpdb);`.
  - Add the guarded helper (mirror `addResponseTimeColumn`):

```php
private static function addLastSecurityScanAtColumn(\wpdb $wpdb): void
{
    $table  = SitesTable::tableName();
    $exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'last_security_scan_at'));
    if ($exists !== null) {
        return;
    }
    // phpcs:ignore WordPress.DB.PreparedSQL — column DDL cannot be parameterized.
    $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN last_security_scan_at DATETIME NULL");
}
```

- [ ] **Step 6: Update version-pin tests.** Search for tests asserting the schema version is `10` and bump them to `11`:

```bash
grep -rln "SCHEMA_VERSION\|assertSame(10\|=== 10\|, 10)" tests/Integration | xargs grep -l "Schema\|Version" 2>/dev/null
```

Update each pin (e.g. `SchemaMigrationOnActivationTest`) to expect `11`. The Uninstaller already iterates `Activation::TABLES`, so dropping the two new tables needs **no Uninstaller change** — verify `UninstallTest` still references `TABLES` iteration (carry-forward infra failure is tolerated).

- [ ] **Step 7: Run green** — `composer test:integration -- --filter "SecurityScanningSchemaTest|SchemaMigration"` → PASS.

- [ ] **Step 8: Commit**

```bash
git add src/Schema/VulnerabilitiesTable.php src/Schema/SiteVulnerabilitiesTable.php src/Activation.php tests/Integration/SecurityScanningSchemaTest.php tests/Integration/SchemaMigrationOnActivationTest.php
git commit -m "feat(p4-1): schema v11 — vulnerabilities + site_vulnerabilities tables + last_security_scan_at"
```

---

## Task 3: `DEFYN_WORDFENCE_API_KEY` constant bootstrap

**Files:**
- Modify: `packages/dashboard-plugin/defyn-dashboard.php` (add env→define bridge near the existing `DEFYN_VAULT_KEY` block)

- [ ] **Step 1: Edit `defyn-dashboard.php`.** Immediately after the existing `if (!defined('DEFYN_VAULT_KEY')) { ... }` bridge, add the same shape for the Wordfence key (so it can come from a wp-config constant OR an env var; absent = empty string downstream):

```php
if (!defined('DEFYN_WORDFENCE_API_KEY')) {
    $envWfKey = getenv('DEFYN_WORDFENCE_API_KEY');
    if ($envWfKey !== false && $envWfKey !== '') {
        define('DEFYN_WORDFENCE_API_KEY', $envWfKey);
    }
}
```

- [ ] **Step 2: Sanity-check** the file still parses: `php -l defyn-dashboard.php` → "No syntax errors".

- [ ] **Step 3: Commit**

```bash
git add defyn-dashboard.php
git commit -m "feat(p4-1): bootstrap DEFYN_WORDFENCE_API_KEY constant (env->define bridge)"
```

> The constant is consumed only in Task 7 via `defined('DEFYN_WORDFENCE_API_KEY') ? (string) constant('DEFYN_WORDFENCE_API_KEY') : ''`. It is **never logged**.

---

## Task 4: `Models\SiteVulnerability` DTO

**Files:**
- Create: `src/Models/SiteVulnerability.php`
- Test: `tests/Unit/Models/SiteVulnerabilityTest.php`

- [ ] **Step 1: Write the failing test** `tests/Unit/Models/SiteVulnerabilityTest.php`:

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Unit\Models;

use Defyn\Dashboard\Models\SiteVulnerability;
use PHPUnit\Framework\TestCase;

final class SiteVulnerabilityTest extends TestCase
{
    public function testFromRowAndToJsonRoundTrip(): void
    {
        $row = [
            'id' => '5', 'site_id' => '7', 'type' => 'plugin', 'slug' => 'elementor',
            'component_name' => 'Elementor', 'installed_version' => '3.18.2',
            'severity' => 'high', 'cvss_score' => '7.5', 'cve' => 'CVE-2024-5678',
            'fixed_in' => '3.18.3', 'title' => 'XSS', 'source_id' => 'abc-123',
            'scanned_at' => '2026-06-15 01:00:00', 'created_at' => '2026-06-15 01:00:00',
        ];
        $v = SiteVulnerability::fromRow($row);
        self::assertSame('plugin', $v->type);
        self::assertSame('elementor', $v->slug);
        self::assertSame(7.5, $v->cvssScore);

        $json = $v->toJson();
        self::assertSame('Elementor', $json['component_name']);
        self::assertSame('3.18.3', $json['fixed_in']);
        self::assertArrayNotHasKey('site_id', $json); // API response is nested under the site
        self::assertArrayNotHasKey('created_at', $json);
    }

    public function testNullableNumericFields(): void
    {
        $row = [
            'id' => '1', 'site_id' => '1', 'type' => 'core', 'slug' => 'wordpress',
            'component_name' => 'WordPress', 'installed_version' => '6.4.1',
            'severity' => 'unknown', 'cvss_score' => null, 'cve' => null,
            'fixed_in' => null, 'title' => null, 'source_id' => 'x',
            'scanned_at' => '2026-06-15 01:00:00', 'created_at' => '2026-06-15 01:00:00',
        ];
        $v = SiteVulnerability::fromRow($row);
        self::assertNull($v->cvssScore);
        self::assertNull($v->cve);
        self::assertNull($v->toJson()['cvss_score']);
    }
}
```

- [ ] **Step 2: Run red** — `composer test:unit -- --filter SiteVulnerabilityTest` → FAIL.

- [ ] **Step 3: Create `src/Models/SiteVulnerability.php`:**

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Models;

final class SiteVulnerability
{
    public function __construct(
        public readonly int $id,
        public readonly int $siteId,
        public readonly string $type,
        public readonly string $slug,
        public readonly string $componentName,
        public readonly string $installedVersion,
        public readonly string $severity,
        public readonly ?float $cvssScore,
        public readonly ?string $cve,
        public readonly ?string $fixedIn,
        public readonly ?string $title,
        public readonly string $sourceId,
        public readonly string $scannedAt,
        public readonly string $createdAt,
    ) {}

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            siteId: (int) $row['site_id'],
            type: (string) $row['type'],
            slug: (string) $row['slug'],
            componentName: (string) $row['component_name'],
            installedVersion: (string) $row['installed_version'],
            severity: (string) $row['severity'],
            cvssScore: isset($row['cvss_score']) && $row['cvss_score'] !== null ? (float) $row['cvss_score'] : null,
            cve: isset($row['cve']) && $row['cve'] !== null ? (string) $row['cve'] : null,
            fixedIn: isset($row['fixed_in']) && $row['fixed_in'] !== null ? (string) $row['fixed_in'] : null,
            title: isset($row['title']) && $row['title'] !== null ? (string) $row['title'] : null,
            sourceId: (string) $row['source_id'],
            scannedAt: (string) $row['scanned_at'],
            createdAt: (string) $row['created_at'],
        );
    }

    /** @return array<string,mixed> The per-finding shape the SPA consumes (no site_id/created_at). */
    public function toJson(): array
    {
        return [
            'type'              => $this->type,
            'slug'              => $this->slug,
            'component_name'    => $this->componentName,
            'installed_version' => $this->installedVersion,
            'severity'          => $this->severity,
            'cvss_score'        => $this->cvssScore,
            'cve'               => $this->cve,
            'fixed_in'          => $this->fixedIn,
            'title'             => $this->title,
        ];
    }
}
```

- [ ] **Step 4: Run green** → PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Models/SiteVulnerability.php tests/Unit/Models/SiteVulnerabilityTest.php
git commit -m "feat(p4-1): SiteVulnerability immutable DTO"
```

---

## Task 5: `Services\VulnerabilityMatcher` — pure version-range matcher

> This is the heart of the slice. Exhaustive unit tests; no DB access.

**Files:**
- Create: `src/Services/VulnerabilityMatcher.php`
- Test: `tests/Unit/Services/VulnerabilityMatcherTest.php`

- [ ] **Step 1: Write the failing test** `tests/Unit/Services/VulnerabilityMatcherTest.php`:

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Unit\Services;

use Defyn\Dashboard\Services\VulnerabilityMatcher;
use PHPUnit\Framework\TestCase;

final class VulnerabilityMatcherTest extends TestCase
{
    /** @dataProvider cases */
    public function testIsAffected(string $installed, ?string $from, bool $fromInc, ?string $to, bool $toInc, bool $expected): void
    {
        self::assertSame($expected, VulnerabilityMatcher::isAffected($installed, $from, $fromInc, $to, $toInc));
    }

    /** @return array<string,array{0:string,1:?string,2:bool,3:?string,4:bool,5:bool}> */
    public static function cases(): array
    {
        return [
            'within open-from, exclusive-to'         => ['3.0.0', null, true, '3.5.0', false, true],
            'equals exclusive-to => not affected'    => ['3.5.0', null, true, '3.5.0', false, false],
            'equals inclusive-to => affected'        => ['3.5.0', null, true, '3.5.0', true,  true],
            'below inclusive-from => not affected'   => ['2.9.9', '3.0.0', true, '3.5.0', false, false],
            'equals inclusive-from => affected'      => ['3.0.0', '3.0.0', true, '3.5.0', false, true],
            'equals exclusive-from => not affected'  => ['3.0.0', '3.0.0', false, '3.5.0', false, false],
            'unbounded-to (to null) => affected'     => ['9.9.9', '3.0.0', true, null, false, true],
            'unbounded-from + unbounded-to => all'   => ['1.0.0', null, true, null, false, true],
            'above exclusive-to => not affected'     => ['4.0.0', null, true, '3.5.0', false, false],
            'unparseable installed => false'         => ['not-a-version', null, true, null, false, false],
            'empty installed => false'               => ['', null, true, null, false, false],
        ];
    }
}
```

- [ ] **Step 2: Run red** → FAIL (class missing).

- [ ] **Step 3: Create `src/Services/VulnerabilityMatcher.php`:**

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Services;

/**
 * P4.1 — pure version-range membership test. No DB access.
 *
 * affected iff:
 *   (from === null || version_compare(installed, from, fromInc ? '>=' : '>'))
 *   && (to === null || version_compare(installed, to, toInc ? '<=' : '<'))
 *
 * An empty or unparseable installed version returns false (no false positive).
 */
final class VulnerabilityMatcher
{
    public static function isAffected(string $installed, ?string $from, bool $fromInc, ?string $to, bool $toInc): bool
    {
        $installed = trim($installed);
        if ($installed === '' || !self::looksLikeVersion($installed)) {
            return false;
        }

        if ($from !== null && $from !== '') {
            if (!version_compare($installed, $from, $fromInc ? '>=' : '>')) {
                return false;
            }
        }
        if ($to !== null && $to !== '') {
            if (!version_compare($installed, $to, $toInc ? '<=' : '<')) {
                return false;
            }
        }
        return true;
    }

    /** Cheap guard against garbage strings: must contain at least one digit. */
    private static function looksLikeVersion(string $v): bool
    {
        return (bool) preg_match('/\d/', $v);
    }
}
```

- [ ] **Step 4: Run green** → all 11 cases PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Services/VulnerabilityMatcher.php tests/Unit/Services/VulnerabilityMatcherTest.php
git commit -m "feat(p4-1): VulnerabilityMatcher pure version-range matcher + exhaustive tests"
```

---

## Task 6: `Services\VulnerabilitiesRepository` — global feed table access

**Files:**
- Create: `src/Services/VulnerabilitiesRepository.php`
- Test: `tests/Integration/Services/VulnerabilitiesRepositoryTest.php`

The repo exposes: `upsertForSource(string $sourceId, array $rows): void` (idempotent — delete prior rows for that `source_id`, then insert the given range rows, in a transaction), `findByTypeAndSlug(string $type, string $slug): array` (raw array rows for the matcher), `countAll(): int`.

- [ ] **Step 1: Write the failing test** `tests/Integration/Services/VulnerabilitiesRepositoryTest.php`:

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Services\VulnerabilitiesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class VulnerabilitiesRepositoryTest extends AbstractSchemaTestCase
{
    private VulnerabilitiesRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        Activation::ensureSchema();
        $this->repo = new VulnerabilitiesRepository();
    }

    public function testUpsertInsertsRowsAndFindByTypeAndSlugReturnsThem(): void
    {
        $now = '2026-06-15 00:00:00';
        $this->repo->upsertForSource('src-1', [
            ['type' => 'plugin', 'slug' => 'elementor', 'title' => 'XSS', 'severity' => 'high',
             'cvss_score' => 7.5, 'cve' => 'CVE-1', 'from_version' => null, 'from_inclusive' => true,
             'to_version' => '3.18.3', 'to_inclusive' => false, 'fixed_in' => '3.18.3', 'updated_at' => $now],
        ]);
        $rows = $this->repo->findByTypeAndSlug('plugin', 'elementor');
        self::assertCount(1, $rows);
        self::assertSame('CVE-1', $rows[0]['cve']);
        self::assertSame(1, $this->repo->countAll());
    }

    public function testUpsertForSourceIsIdempotentReplacingPriorRows(): void
    {
        $now = '2026-06-15 00:00:00';
        $payload = [
            ['type' => 'plugin', 'slug' => 'elementor', 'title' => 'XSS', 'severity' => 'high',
             'cvss_score' => 7.5, 'cve' => 'CVE-1', 'from_version' => null, 'from_inclusive' => true,
             'to_version' => '3.18.3', 'to_inclusive' => false, 'fixed_in' => '3.18.3', 'updated_at' => $now],
        ];
        $this->repo->upsertForSource('src-1', $payload);
        $this->repo->upsertForSource('src-1', $payload); // re-ingest same source
        self::assertSame(1, $this->repo->countAll(), 're-ingesting a source must not duplicate');
    }
}
```

- [ ] **Step 2: Run red** → FAIL.

- [ ] **Step 3: Create `src/Services/VulnerabilitiesRepository.php`:**

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Schema\VulnerabilitiesTable;

final class VulnerabilitiesRepository
{
    /**
     * Replace all cached ranges for one feed vuln (source_id) with the given set.
     * Idempotent per source: a DELETE-then-INSERT inside a transaction.
     *
     * @param list<array{type:string,slug:string,title:?string,severity:string,cvss_score:?float,cve:?string,from_version:?string,from_inclusive:bool,to_version:?string,to_inclusive:bool,fixed_in:?string,updated_at:string}> $rows
     */
    public function upsertForSource(string $sourceId, array $rows): void
    {
        global $wpdb;
        $table = VulnerabilitiesTable::tableName();

        $wpdb->query('START TRANSACTION');
        try {
            $wpdb->delete($table, ['source_id' => $sourceId], ['%s']);
            foreach ($rows as $r) {
                $wpdb->insert($table, [
                    'source_id'      => $sourceId,
                    'type'           => $r['type'],
                    'slug'           => $r['slug'],
                    'title'          => $r['title'],
                    'severity'       => $r['severity'],
                    'cvss_score'     => $r['cvss_score'],
                    'cve'            => $r['cve'],
                    'from_version'   => $r['from_version'],
                    'from_inclusive' => $r['from_inclusive'] ? 1 : 0,
                    'to_version'     => $r['to_version'],
                    'to_inclusive'   => $r['to_inclusive'] ? 1 : 0,
                    'fixed_in'       => $r['fixed_in'],
                    'updated_at'     => $r['updated_at'],
                ], ['%s','%s','%s','%s','%s','%f','%s','%s','%d','%s','%d','%s','%s']);
            }
            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    /** @return list<array<string,mixed>> raw rows for the matcher */
    public function findByTypeAndSlug(string $type, string $slug): array
    {
        global $wpdb;
        $table = VulnerabilitiesTable::tableName();
        $rows  = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} WHERE type = %s AND slug = %s", $type, $slug),
            ARRAY_A
        );
        return $rows ?: [];
    }

    public function countAll(): int
    {
        global $wpdb;
        $table = VulnerabilitiesTable::tableName();
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }
}
```

> NOTE on `%f`: `cvss_score` is nullable; `wpdb` formats null correctly regardless of the placeholder, but if a strict-types issue arises in the wp-phpunit harness, insert the row array without a format array (let wpdb infer) — keep the test green as the contract.

- [ ] **Step 4: Run green** → PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Services/VulnerabilitiesRepository.php tests/Integration/Services/VulnerabilitiesRepositoryTest.php
git commit -m "feat(p4-1): VulnerabilitiesRepository (per-source upsert + findByTypeAndSlug)"
```

---

## Task 7: `Services\VulnFeedService::refreshIfStale()` — keyed, best-effort feed ingest

> External boundary + best-effort. Use the **note from Task 1** for the exact URL, auth scheme, and JSON key names; the mapping below targets the documented Wordfence record shape — adjust field paths to match Task 1's findings.

**Files:**
- Modify: `packages/dashboard-plugin/composer.json` (add `halaxa/json-machine`)
- Create: `src/Services/VulnFeedService.php`
- Test: `tests/Integration/Services/VulnFeedServiceTest.php`

> **CRITICAL — feed size (from Task 1's note `053f8f0`):** the Wordfence v3 **production** feed is **~117 MB / 12k+ records**. A whole-body `wp_remote_get` + `json_decode` would hold ~350–700 MB in PHP memory → **fatal on Kinsta**. We MUST **stream the body to a temp file** (`wp_remote_get` with `'stream' => true, 'filename' => $tmp`) and **parse it incrementally** with the pure-PHP streaming parser **`halaxa/json-machine`** (iterates the top-level UUID→record object lazily; memory stays flat at a few MB). Confirmed feed facts from Task 1 to hard-code: URL `https://www.wordfence.com/api/intelligence/v3/vulnerabilities/production`; auth `Authorization: Bearer <key>` header; top-level JSON is an **object keyed by UUID** (UUID → our `source_id`); `affected_versions` is a **dict keyed by a range-label, NOT an array** (iterate its values — the plan's `foreach` already does); `cvss.score` is a **string**; unbounded lower bound is the literal `"*"`; fixed version is `patched_versions[]` (array); `cve` can be null.

Contract: `refreshIfStale(): void` — (1) read the key from `DEFYN_WORDFENCE_API_KEY`; empty ⇒ `error_log` once and return; (2) if `get_option('defyn_vuln_feed_synced_at')` is within ~24h, return; (3) **stream-download** the v3 feed to a temp file (an injectable `downloader(url,key): ?string` returning the path, or null on transport/non-2xx — best-effort, last-good rows intact); (4) **stream-parse** the file with `JsonMachine\Items::fromFile($path, ['decoder' => new ExtJsonDecoder(true)])`, mapping each record → per-source range rows → `VulnerabilitiesRepository::upsertForSource` (per-source atomic); (5) `update_option('defyn_vuln_feed_synced_at', gmdate(...))`; (6) always `unlink` the temp file. The key is never logged.

- [ ] **Step 0: Add the streaming-parser dependency.** From `packages/dashboard-plugin/`:

```bash
composer require halaxa/json-machine
```

Verify it landed in `composer.json` `require` (NOT `require-dev`) — it's a production dependency (it ships in the release zip after `composer install --no-dev`). It is pure PHP (only needs ext-json), so it survives the `--no-dev` prune cleanly.

- [ ] **Step 1: Write the failing test** `tests/Integration/Services/VulnFeedServiceTest.php` (inject the **downloader** as a closure returning a local fixture-file path — `pre_http_request` can't intercept a streamed download, and we don't want a 117 MB fixture; drive the key via an injected closure too):

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Services\VulnFeedService;
use Defyn\Dashboard\Services\VulnerabilitiesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class VulnFeedServiceTest extends AbstractSchemaTestCase
{
    /** @var list<string> temp fixture files to clean up */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        Activation::ensureSchema();
        delete_option('defyn_vuln_feed_synced_at');
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        $this->tmpFiles = [];
        parent::tearDown();
    }

    public function testEmptyKeyNoOps(): void
    {
        $svc = new VulnFeedService(keyProvider: static fn (): string => '');
        $svc->refreshIfStale();
        self::assertSame(0, (new VulnerabilitiesRepository())->countAll());
        self::assertFalse(get_option('defyn_vuln_feed_synced_at'));
    }

    public function testMapsSamplePayloadIntoRows(): void
    {
        $path = $this->fixtureFile($this->sampleFeed());
        $svc  = new VulnFeedService(
            keyProvider: static fn (): string => 'test-key',
            downloader:  static fn (): ?string => $path,
        );
        $svc->refreshIfStale();

        $repo = new VulnerabilitiesRepository();
        self::assertGreaterThan(0, $repo->countAll());
        $rows = $repo->findByTypeAndSlug('plugin', 'elementor');
        self::assertNotEmpty($rows);
        self::assertSame('CVE-2024-5678', $rows[0]['cve']);
        self::assertNull($rows[0]['from_version'], 'literal "*" lower bound maps to null');
        self::assertSame('3.18.3', $rows[0]['fixed_in'], 'fixed_in from patched_versions[0]');
        self::assertNotFalse(get_option('defyn_vuln_feed_synced_at'));
    }

    public function testDownloadFailureLeavesPriorRowsAndDoesNotThrow(): void
    {
        (new VulnerabilitiesRepository())->upsertForSource('prior', [[
            'type'=>'plugin','slug'=>'akismet','title'=>'x','severity'=>'low','cvss_score'=>null,
            'cve'=>null,'from_version'=>null,'from_inclusive'=>true,'to_version'=>'1.0','to_inclusive'=>false,
            'fixed_in'=>'1.0','updated_at'=>'2026-06-15 00:00:00',
        ]]);

        $svc = new VulnFeedService(
            keyProvider: static fn (): string => 'test-key',
            downloader:  static fn (): ?string => null, // transport/non-2xx failure
        );
        $svc->refreshIfStale(); // must not throw
        self::assertSame(1, (new VulnerabilitiesRepository())->countAll(), 'prior rows preserved');
        self::assertFalse(get_option('defyn_vuln_feed_synced_at'), 'synced stamp not set on failed download');
    }

    public function testStalenessSkipWhenRecentlySynced(): void
    {
        update_option('defyn_vuln_feed_synced_at', gmdate('Y-m-d H:i:s'));
        $svc = new VulnFeedService(
            keyProvider: static fn (): string => 'test-key',
            downloader:  static function (): ?string {
                throw new \RuntimeException('download should not be called when fresh');
            },
        );
        $svc->refreshIfStale(); // returns without downloading
        self::assertTrue(true);
    }

    /** Writes the feed to a temp JSON file and returns its path (json-machine parses from file). */
    private function fixtureFile(array $feed): string
    {
        $path = tempnam(sys_get_temp_dir(), 'defyn-vuln-fixture');
        file_put_contents($path, (string) wp_json_encode($feed));
        $this->tmpFiles[] = $path;
        return $path;
    }

    /** @return array<string,mixed> Wordfence v3 record map keyed by UUID (per Task 1 note 053f8f0). */
    private function sampleFeed(): array
    {
        return [
            'uuid-1' => [
                'id' => 'uuid-1',
                'title' => 'Elementor <= 3.18.2 - XSS',
                'cve' => 'CVE-2024-5678',
                'cvss' => ['score' => '9.8', 'rating' => 'Critical'], // score is a STRING in the real feed
                'software' => [[
                    'type' => 'plugin',
                    'slug' => 'elementor',
                    'name' => 'Elementor',
                    'affected_versions' => [ // dict keyed by range-label, NOT an array
                        '* - 3.18.2' => [
                            'from_version' => '*', 'from_inclusive' => true,
                            'to_version' => '3.18.2', 'to_inclusive' => true,
                        ],
                    ],
                    'patched_versions' => ['3.18.3'],
                ]],
            ],
        ];
    }
}
```

- [ ] **Step 2: Run red** → FAIL.

- [ ] **Step 3: Create `src/Services/VulnFeedService.php`** (stream-download → json-machine stream-parse; map per Task 1's note: `from_version === '*'` → null; `cvss.score` string → float; severity from `cvss.rating` lowercased, default `unknown`):

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Services;

use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;

final class VulnFeedService
{
    private const FEED_URL = 'https://www.wordfence.com/api/intelligence/v3/vulnerabilities/production';
    private const STALE_SECONDS = 86400;
    private const OPTION = 'defyn_vuln_feed_synced_at';

    /** @var callable(): string */
    private $keyProvider;
    /** @var callable(string,string): ?string returns a path to the downloaded feed file, or null on failure */
    private $downloader;

    public function __construct(
        ?callable $keyProvider = null,
        private readonly ?VulnerabilitiesRepository $repo = null,
        ?callable $downloader = null,
    ) {
        $this->keyProvider = $keyProvider ?? static function (): string {
            return defined('DEFYN_WORDFENCE_API_KEY') ? (string) constant('DEFYN_WORDFENCE_API_KEY') : '';
        };
        $this->downloader = $downloader ?? fn (string $url, string $key): ?string => $this->download($url, $key);
    }

    public function refreshIfStale(): void
    {
        $key = ($this->keyProvider)();
        if ($key === '') {
            error_log('[defyn] vuln feed: DEFYN_WORDFENCE_API_KEY not set; skipping refresh.');
            return;
        }

        $syncedAt = get_option(self::OPTION);
        if (is_string($syncedAt) && $syncedAt !== '') {
            $age = time() - (int) strtotime($syncedAt . ' UTC');
            if ($age >= 0 && $age < self::STALE_SECONDS) {
                return; // still fresh
            }
        }

        $path = ($this->downloader)(self::FEED_URL, $key);
        if ($path === null) {
            return; // transport/non-2xx (already logged in download()); last-good rows intact
        }

        $repo = $this->repo ?? new VulnerabilitiesRepository();
        $now  = gmdate('Y-m-d H:i:s');
        try {
            // Stream-parse the ~117MB feed lazily: top-level object keyed by UUID -> record.
            // ExtJsonDecoder(true) decodes nested structures into associative arrays for mapRecord().
            foreach (Items::fromFile($path, ['decoder' => new ExtJsonDecoder(true)]) as $sourceId => $record) {
                if (!is_array($record)) {
                    continue;
                }
                $rows = $this->mapRecord((string) $sourceId, $record, $now);
                if ($rows !== []) {
                    $repo->upsertForSource((string) $sourceId, $rows);
                }
            }
            update_option(self::OPTION, $now);
        } catch (\Throwable $e) {
            error_log('[defyn] vuln feed: parse error: ' . $e->getMessage()); // never logs the key
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /** Stream the feed body to a temp file (never holds 117MB in memory). Returns the path, or null on failure. */
    private function download(string $url, string $key): ?string
    {
        $tmp = wp_tempnam('defyn-vuln-feed');
        if (!$tmp) {
            error_log('[defyn] vuln feed: could not create temp file.');
            return null;
        }
        $response = wp_remote_get($url, [
            'timeout'  => 120,
            'stream'   => true,
            'filename' => $tmp,
            'headers'  => ['Authorization' => 'Bearer ' . $key],
        ]);
        if (is_wp_error($response)) {
            @unlink($tmp);
            error_log('[defyn] vuln feed: transport error: ' . $response->get_error_message());
            return null;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            @unlink($tmp);
            error_log('[defyn] vuln feed: non-2xx response: ' . $code); // never logs the key
            return null;
        }
        return $tmp;
    }

    /**
     * Map one Wordfence record into 0+ normalized range rows (one per affected-version range per software entry).
     * Defensive: skips entries missing type/slug; tolerates absent fields. ADJUST key paths to Task 1's note.
     *
     * @param array<string,mixed> $record
     * @return list<array<string,mixed>>
     */
    private function mapRecord(string $sourceId, array $record, string $now): array
    {
        $title    = isset($record['title']) ? (string) $record['title'] : null;
        $cve      = isset($record['cve']) && $record['cve'] !== '' ? (string) $record['cve'] : null;
        $cvss     = is_array($record['cvss'] ?? null) ? $record['cvss'] : [];
        $score    = isset($cvss['score']) ? (float) $cvss['score'] : null;
        $severity = isset($cvss['rating']) && $cvss['rating'] !== '' ? strtolower((string) $cvss['rating']) : 'unknown';

        $software = is_array($record['software'] ?? null) ? $record['software'] : [];
        $rows = [];
        foreach ($software as $sw) {
            if (!is_array($sw) || empty($sw['type']) || empty($sw['slug'])) {
                continue;
            }
            $type = (string) $sw['type'];
            if ($type === 'plugin' || $type === 'theme' || $type === 'core') {
                // core software slug in the feed may be 'wordpress'/'core' — normalize to 'wordpress'.
                $slug    = $type === 'core' ? 'wordpress' : (string) $sw['slug'];
                $fixedIn = isset($sw['patched_versions'][0]) ? (string) $sw['patched_versions'][0] : null;
                $ranges  = is_array($sw['affected_versions'] ?? null) ? $sw['affected_versions'] : [];
                foreach ($ranges as $range) {
                    if (!is_array($range)) {
                        continue;
                    }
                    $from = isset($range['from_version']) && $range['from_version'] !== '*' && $range['from_version'] !== ''
                        ? (string) $range['from_version'] : null;
                    $to = isset($range['to_version']) && $range['to_version'] !== '*' && $range['to_version'] !== ''
                        ? (string) $range['to_version'] : null;
                    $rows[] = [
                        'type'           => $type,
                        'slug'           => $slug,
                        'title'          => $title,
                        'severity'       => $severity,
                        'cvss_score'     => $score,
                        'cve'            => $cve,
                        'from_version'   => $from,
                        'from_inclusive' => (bool) ($range['from_inclusive'] ?? true),
                        'to_version'     => $to,
                        'to_inclusive'   => (bool) ($range['to_inclusive'] ?? false),
                        'fixed_in'       => $fixedIn,
                        'updated_at'     => $now,
                    ];
                }
            }
        }
        return $rows;
    }
}
```

- [ ] **Step 4: Run green** → all 4 tests PASS.

- [ ] **Step 5: Commit** (include the composer manifest + lock for the new dependency):

```bash
git add composer.json composer.lock src/Services/VulnFeedService.php tests/Integration/Services/VulnFeedServiceTest.php
git commit -m "feat(p4-1): VulnFeedService — streamed keyed v3 feed ingest (json-machine), best-effort, empty-key no-op"
```

---

## Task 8: `Services\SiteVulnerabilitiesRepository` — per-site findings snapshot

**Files:**
- Create: `src/Services/SiteVulnerabilitiesRepository.php`
- Test: `tests/Integration/Services/SiteVulnerabilitiesRepositoryTest.php`

Methods: `replaceForSite(int $siteId, array $findings, string $now): void` (DELETE all for site + INSERT, in a txn), `findForSite(int $siteId): SiteVulnerability[]` (ORDER BY severity rank desc, then component_name), `siteIdsWithFindingsForUser(int $userId): int[]` (JOIN sites — drives the Overview reason, Task 14).

- [ ] **Step 1: Write the failing test** `tests/Integration/Services/SiteVulnerabilitiesRepositoryTest.php`:

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Services\SiteVulnerabilitiesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SiteVulnerabilitiesRepositoryTest extends AbstractSchemaTestCase
{
    private SiteVulnerabilitiesRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        Activation::ensureSchema();
        $this->repo = new SiteVulnerabilitiesRepository();
    }

    public function testReplaceForSiteThenFindSortedBySeverity(): void
    {
        $now = '2026-06-15 02:00:00';
        $this->repo->replaceForSite(7, [
            $this->finding('plugin', 'akismet', 'Akismet', 'low'),
            $this->finding('plugin', 'wp-file-manager', 'WP File Manager', 'critical'),
            $this->finding('plugin', 'elementor', 'Elementor', 'high'),
        ], $now);

        $found = $this->repo->findForSite(7);
        self::assertCount(3, $found);
        self::assertSame('critical', $found[0]->severity);
        self::assertSame('high', $found[1]->severity);
        self::assertSame('low', $found[2]->severity);
    }

    public function testReplaceForSiteWipesStaleFindings(): void
    {
        $now = '2026-06-15 02:00:00';
        $this->repo->replaceForSite(7, [$this->finding('plugin', 'elementor', 'Elementor', 'high')], $now);
        $this->repo->replaceForSite(7, [], $now); // re-scan finds nothing
        self::assertCount(0, $this->repo->findForSite(7));
    }

    /** @return array<string,mixed> */
    private function finding(string $type, string $slug, string $name, string $severity): array
    {
        return [
            'type' => $type, 'slug' => $slug, 'component_name' => $name, 'installed_version' => '1.0.0',
            'severity' => $severity, 'cvss_score' => null, 'cve' => null, 'fixed_in' => '2.0.0',
            'title' => 'x', 'source_id' => 'src',
        ];
    }
}
```

- [ ] **Step 2: Run red** → FAIL.

- [ ] **Step 3: Create `src/Services/SiteVulnerabilitiesRepository.php`:**

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Models\SiteVulnerability;
use Defyn\Dashboard\Schema\SiteVulnerabilitiesTable;
use Defyn\Dashboard\Schema\SitesTable;

final class SiteVulnerabilitiesRepository
{
    private const SEVERITY_RANK = "CASE severity WHEN 'critical' THEN 4 WHEN 'high' THEN 3 WHEN 'medium' THEN 2 WHEN 'low' THEN 1 ELSE 0 END";

    /**
     * @param list<array{type:string,slug:string,component_name:string,installed_version:string,severity:string,cvss_score:?float,cve:?string,fixed_in:?string,title:?string,source_id:string}> $findings
     */
    public function replaceForSite(int $siteId, array $findings, string $now): void
    {
        global $wpdb;
        $table = SiteVulnerabilitiesTable::tableName();

        $wpdb->query('START TRANSACTION');
        try {
            $wpdb->delete($table, ['site_id' => $siteId], ['%d']);
            foreach ($findings as $f) {
                $wpdb->insert($table, [
                    'site_id'           => $siteId,
                    'type'              => $f['type'],
                    'slug'              => $f['slug'],
                    'component_name'    => $f['component_name'],
                    'installed_version' => $f['installed_version'],
                    'severity'          => $f['severity'],
                    'cvss_score'        => $f['cvss_score'],
                    'cve'               => $f['cve'],
                    'fixed_in'          => $f['fixed_in'],
                    'title'             => $f['title'],
                    'source_id'         => $f['source_id'],
                    'scanned_at'        => $now,
                    'created_at'        => $now,
                ], ['%d','%s','%s','%s','%s','%s','%f','%s','%s','%s','%s','%s','%s']);
            }
            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    /** @return list<SiteVulnerability> sorted severity-desc then component */
    public function findForSite(int $siteId): array
    {
        global $wpdb;
        $table = SiteVulnerabilitiesTable::tableName();
        $rank  = self::SEVERITY_RANK;
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE site_id = %d ORDER BY {$rank} DESC, component_name ASC",
                $siteId
            ),
            ARRAY_A
        );
        return array_map([SiteVulnerability::class, 'fromRow'], $rows ?: []);
    }

    /**
     * P4.1 — site_ids (owned by $userId) that currently have >=1 finding. Drives the Overview reason.
     * @return list<int>
     */
    public function siteIdsWithFindingsForUser(int $userId): array
    {
        global $wpdb;
        $sv    = SiteVulnerabilitiesTable::tableName();
        $sites = SitesTable::tableName();
        $rows  = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT sv.site_id FROM {$sv} sv
             INNER JOIN {$sites} s ON s.id = sv.site_id
             WHERE s.user_id = %d",
            $userId
        ));
        return array_map('intval', $rows ?: []);
    }
}
```

- [ ] **Step 4: Run green** → PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Services/SiteVulnerabilitiesRepository.php tests/Integration/Services/SiteVulnerabilitiesRepositoryTest.php
git commit -m "feat(p4-1): SiteVulnerabilitiesRepository (replace-for-site snapshot + sorted find)"
```

---

## Task 9: `SitesRepository::markSecurityScannedAt` + `Site::lastSecurityScanAt`

**Files:**
- Modify: `src/Services/SitesRepository.php` (add `markSecurityScannedAt`)
- Modify: `src/Models/Site.php` (add `lastSecurityScanAt` readonly field + read it in `fromRow`)
- Test: `tests/Integration/Services/SitesRepositorySecurityScanTest.php`

- [ ] **Step 1: Write the failing test** `tests/Integration/Services/SitesRepositorySecurityScanTest.php`:

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SitesRepositorySecurityScanTest extends AbstractSchemaTestCase
{
    public function testMarkSecurityScannedAtStampsAndSurfacesOnModel(): void
    {
        Activation::ensureSchema();
        $repo = new SitesRepository();
        $id = $repo->create(1, 'https://x.test', 'X'); // mirror existing repo create signature
        $repo->markSecurityScannedAt($id, '2026-06-15 03:00:00');

        $site = $repo->findById($id);
        self::assertNotNull($site);
        self::assertSame('2026-06-15 03:00:00', $site->lastSecurityScanAt);
    }
}
```

> If `SitesRepository::create()` has a different signature, mirror an existing repo integration test's site-creation helper instead. The behavioral assertion (stamp → surfaces on model) is the contract.

- [ ] **Step 2: Run red** → FAIL.

- [ ] **Step 3: Add `markSecurityScannedAt` to `src/Services/SitesRepository.php`** (mirror the existing `markContactAt`/`recordResponseTime` simple updaters):

```php
public function markSecurityScannedAt(int $siteId, string $now): void
{
    global $wpdb;
    $wpdb->update(
        \Defyn\Dashboard\Schema\SitesTable::tableName(),
        ['last_security_scan_at' => $now],
        ['id' => $siteId],
        ['%s'],
        ['%d']
    );
}
```

- [ ] **Step 4: Extend `src/Models/Site.php`** — add `public readonly ?string $lastSecurityScanAt = null,` as the **last** constructor parameter (after `sslAlertSentAt`), and in `fromRow(...)` read it: `lastSecurityScanAt: isset($row['last_security_scan_at']) ? (string) $row['last_security_scan_at'] : null,`. **Leave `Site::toJson()` untouched** — the GET `/sites/{id}/vulnerabilities` endpoint reads this field directly (Task 12), so it need not appear in the `/sites/{id}` payload (avoids rippling the SPA `siteSchema`).

- [ ] **Step 5: Run green** → PASS. Then run the broader sites suite to catch ripples: `composer test:integration -- --filter SitesRepository` → green (Site DTO change is additive with a default).

- [ ] **Step 6: Commit**

```bash
git add src/Services/SitesRepository.php src/Models/Site.php tests/Integration/Services/SitesRepositorySecurityScanTest.php
git commit -m "feat(p4-1): SitesRepository::markSecurityScannedAt + Site.lastSecurityScanAt"
```

---

## Task 10: `Services\VulnerabilityScanService::scan()` — orchestrate a per-site scan

**Files:**
- Create: `src/Services/VulnerabilityScanService.php`
- Test: `tests/Integration/Services/VulnerabilityScanServiceTest.php`

`scan(int $siteId): void`:
1. `SitesRepository::findById($siteId)`; null ⇒ return.
2. Candidates: each plugin (`SitePluginsRepository::findAllForSite` → `type=plugin`, `slug`, `version`, `name`), each theme (`ThemesRepository::findAllForSite` → `type=theme`), and core (`type=core`, slug `wordpress`, version `$site->wpVersion`, name `WordPress`). Skip a candidate whose version is null/empty.
3. For each candidate, `VulnerabilitiesRepository::findByTypeAndSlug(type, slug)`; keep rows where `VulnerabilityMatcher::isAffected($installed, from_version, (bool)from_inclusive, to_version, (bool)to_inclusive)` is true → build a finding (denormalized snapshot).
4. `SiteVulnerabilitiesRepository::replaceForSite($siteId, $findings, $now)`.
5. **Always** `SitesRepository::markSecurityScannedAt($siteId, $now)`.
6. `ActivityLogger::log($site->userId, $siteId, 'site.vulnerabilities_detected', ['total'=>N,'critical'=>c,'high'=>h,'medium'=>m,'low'=>l])`.

- [ ] **Step 1: Write the failing test** `tests/Integration/Services/VulnerabilityScanServiceTest.php`:

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Services\SitePluginsRepository;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Services\SiteVulnerabilitiesRepository;
use Defyn\Dashboard\Services\VulnerabilitiesRepository;
use Defyn\Dashboard\Services\VulnerabilityScanService;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class VulnerabilityScanServiceTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Activation::ensureSchema();
    }

    public function testScanStoresMatchingFindingsAndStampsScanTime(): void
    {
        $sites = new SitesRepository();
        $siteId = $sites->create(1, 'https://x.test', 'X');
        (new SitePluginsRepository())->replaceForSite($siteId, [
            ['slug'=>'elementor','name'=>'Elementor','version'=>'3.18.0','update_available'=>false,'update_version'=>null,'tested_up_to'=>null],
        ], '2026-06-15 00:00:00');
        (new VulnerabilitiesRepository())->upsertForSource('v1', [[
            'type'=>'plugin','slug'=>'elementor','title'=>'XSS','severity'=>'high','cvss_score'=>7.5,'cve'=>'CVE-1',
            'from_version'=>null,'from_inclusive'=>true,'to_version'=>'3.18.2','to_inclusive'=>true,'fixed_in'=>'3.18.3',
            'updated_at'=>'2026-06-15 00:00:00',
        ]]);

        (new VulnerabilityScanService())->scan($siteId);

        $found = (new SiteVulnerabilitiesRepository())->findForSite($siteId);
        self::assertCount(1, $found);
        self::assertSame('elementor', $found[0]->slug);
        self::assertSame('3.18.3', $found[0]->fixedIn);

        $site = $sites->findById($siteId);
        self::assertNotNull($site->lastSecurityScanAt);
    }

    public function testCleanSiteHasZeroFindingsButIsStillStamped(): void
    {
        $sites = new SitesRepository();
        $siteId = $sites->create(1, 'https://clean.test', 'Clean');
        (new SitePluginsRepository())->replaceForSite($siteId, [
            ['slug'=>'akismet','name'=>'Akismet','version'=>'5.3','update_available'=>false,'update_version'=>null,'tested_up_to'=>null],
        ], '2026-06-15 00:00:00');
        // no vuln rows seeded

        (new VulnerabilityScanService())->scan($siteId);

        self::assertCount(0, (new SiteVulnerabilitiesRepository())->findForSite($siteId));
        self::assertNotNull($sites->findById($siteId)->lastSecurityScanAt, 'scanned-clean must still stamp the scan time');
    }
}
```

- [ ] **Step 2: Run red** → FAIL.

- [ ] **Step 3: Create `src/Services/VulnerabilityScanService.php`:**

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Logging\ActivityLogger;

final class VulnerabilityScanService
{
    public function __construct(
        private readonly ?SitesRepository $sites = null,
        private readonly ?SitePluginsRepository $plugins = null,
        private readonly ?ThemesRepository $themes = null,
        private readonly ?VulnerabilitiesRepository $vulns = null,
        private readonly ?SiteVulnerabilitiesRepository $findings = null,
        private readonly ?ActivityLogger $activity = null,
    ) {}

    public function scan(int $siteId): void
    {
        $sites    = $this->sites ?? new SitesRepository();
        $site     = $sites->findById($siteId);
        if ($site === null) {
            return;
        }

        $plugins  = $this->plugins ?? new SitePluginsRepository();
        $themes   = $this->themes ?? new ThemesRepository();
        $vulns    = $this->vulns ?? new VulnerabilitiesRepository();
        $findings = $this->findings ?? new SiteVulnerabilitiesRepository();
        $activity = $this->activity ?? new ActivityLogger();
        $now      = gmdate('Y-m-d H:i:s');

        $candidates = [];
        foreach ($plugins->findAllForSite($siteId) as $p) {
            $candidates[] = ['type' => 'plugin', 'slug' => $p->slug, 'name' => $p->name, 'version' => (string) ($p->version ?? '')];
        }
        foreach ($themes->findAllForSite($siteId) as $t) {
            $candidates[] = ['type' => 'theme', 'slug' => $t->slug, 'name' => $t->name, 'version' => (string) ($t->version ?? '')];
        }
        if (!empty($site->wpVersion)) {
            $candidates[] = ['type' => 'core', 'slug' => 'wordpress', 'name' => 'WordPress', 'version' => (string) $site->wpVersion];
        }

        $results = [];
        foreach ($candidates as $c) {
            if ($c['version'] === '') {
                continue;
            }
            foreach ($vulns->findByTypeAndSlug($c['type'], $c['slug']) as $row) {
                $isHit = VulnerabilityMatcher::isAffected(
                    $c['version'],
                    $row['from_version'] !== null ? (string) $row['from_version'] : null,
                    (bool) $row['from_inclusive'],
                    $row['to_version'] !== null ? (string) $row['to_version'] : null,
                    (bool) $row['to_inclusive'],
                );
                if (!$isHit) {
                    continue;
                }
                $results[] = [
                    'type'              => $c['type'],
                    'slug'              => $c['slug'],
                    'component_name'    => $c['name'],
                    'installed_version' => $c['version'],
                    'severity'          => (string) $row['severity'],
                    'cvss_score'        => $row['cvss_score'] !== null ? (float) $row['cvss_score'] : null,
                    'cve'               => $row['cve'] !== null ? (string) $row['cve'] : null,
                    'fixed_in'          => $row['fixed_in'] !== null ? (string) $row['fixed_in'] : null,
                    'title'             => $row['title'] !== null ? (string) $row['title'] : null,
                    'source_id'         => (string) $row['source_id'],
                ];
            }
        }

        $findings->replaceForSite($siteId, $results, $now);
        $sites->markSecurityScannedAt($siteId, $now);

        $counts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        foreach ($results as $r) {
            if (isset($counts[$r['severity']])) {
                $counts[$r['severity']]++;
            }
        }
        $activity->log($site->userId, $siteId, 'site.vulnerabilities_detected', array_merge(
            ['total' => count($results)],
            $counts
        ));
    }
}
```

> Verify `ActivityLogger`'s namespace + `log()` signature against an existing caller (e.g. `IncidentService`) and adjust the `use`/constructor accordingly. Verify the `Theme`/`Plugin` model property names (`->slug`, `->name`, `->version`) — they exist on the P2.1/P2.3 DTOs.

- [ ] **Step 4: Run green** → both tests PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Services/VulnerabilityScanService.php tests/Integration/Services/VulnerabilityScanServiceTest.php
git commit -m "feat(p4-1): VulnerabilityScanService — match plugins/themes/core, snapshot findings, always stamp scan time"
```

---

## Task 11: Jobs — `SecurityScanAll` → `SecurityScan` daily fan-out + wiring + self-heal guard

**Files:**
- Create: `src/Jobs/SecurityScanAll.php`
- Create: `src/Jobs/SecurityScan.php`
- Modify: `src/Jobs/Scheduler.php` (add to `SCHEDULES` at 86400)
- Modify: `src/Plugin.php` (add_action wiring)
- Modify: `src/Activation.php` (a SECOND `maybeRunSelfHeal` ensure-scheduled guard for the new hook)
- Test: `tests/Integration/Jobs/SecurityScanAllTest.php`, `tests/Integration/Jobs/SchedulerSecurityTest.php`

> **CRITICAL self-heal trap:** the existing `maybeRunSelfHeal` guard only re-installs schedules when `SslCheckAll::HOOK` is absent. On an upgrade from v0.12.0 (which already has `SslCheckAll` scheduled), that guard will NOT fire, so the new `SecurityScanAll` recurring schedule would never install. Add a **second** guard block keyed on `SecurityScanAll::HOOK` (mirror the P3.3 one). `installRecurringSchedules()` is idempotent.

- [ ] **Step 1: Write the failing fan-out test** `tests/Integration/Jobs/SecurityScanAllTest.php`:

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Jobs;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Jobs\SecurityScan;
use Defyn\Dashboard\Jobs\SecurityScanAll;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SecurityScanAllTest extends AbstractSchemaTestCase
{
    public function testSchedulesOnePerSchedulableSite(): void
    {
        Activation::ensureSchema();
        $sites = new SitesRepository();
        $a = $sites->create(1, 'https://a.test', 'A');
        $b = $sites->create(1, 'https://b.test', 'B');

        (new SecurityScanAll())->handle();

        self::assertNotFalse(as_next_scheduled_action(SecurityScan::HOOK, [$a], 'defyn'));
        self::assertNotFalse(as_next_scheduled_action(SecurityScan::HOOK, [$b], 'defyn'));
    }
}
```

- [ ] **Step 2: Run red** → FAIL.

- [ ] **Step 3: Create `src/Jobs/SecurityScanAll.php`** (mirror `SslCheckAll`; refresh the feed once BEFORE the fan-out):

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Services\VulnFeedService;

final class SecurityScanAll
{
    public const HOOK = 'defyn_security_scan_all';

    public function __construct(
        private readonly ?SitesRepository $repo = null,
        private readonly ?VulnFeedService $feed = null,
    ) {}

    public function handle(): void
    {
        // Refresh the global vuln feed ONCE per cycle (not per-site). Best-effort.
        ($this->feed ?? new VulnFeedService())->refreshIfStale();

        $repo = $this->repo ?? new SitesRepository();
        if (!function_exists('as_schedule_single_action')) {
            return;
        }
        foreach ($repo->findAllSchedulable() as $siteId) {
            as_schedule_single_action(time(), SecurityScan::HOOK, [$siteId], 'defyn');
        }
    }
}
```

- [ ] **Step 4: Create `src/Jobs/SecurityScan.php`:**

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Services\VulnerabilityScanService;

final class SecurityScan
{
    public const HOOK = 'defyn_security_scan';

    public function __construct(private readonly ?VulnerabilityScanService $service = null) {}

    public function handle(int $siteId): void
    {
        ($this->service ?? new VulnerabilityScanService())->scan($siteId);
    }
}
```

- [ ] **Step 5: Edit `src/Jobs/Scheduler.php`** — add to `SCHEDULES`: `SecurityScanAll::HOOK => 86400,` (add the `use Defyn\Dashboard\Jobs\SecurityScanAll;` import if needed — same namespace, so likely just reference the class). Add `tests/Integration/Jobs/SchedulerSecurityTest.php`:

```php
public function testSecurityScanAllIsScheduledDaily(): void
{
    \Defyn\Dashboard\Jobs\Scheduler::installRecurringSchedules();
    self::assertNotFalse(as_next_scheduled_action(\Defyn\Dashboard\Jobs\SecurityScanAll::HOOK, [], 'defyn'));
}
```

- [ ] **Step 6: Edit `src/Plugin.php`** — register the two hooks (mirror the SSL block). In `boot()`:

```php
// P4.1 — daily security scan fan-out + per-site leaf job.
add_action(SecurityScanAll::HOOK, static function (): void {
    (new SecurityScanAll())->handle();
}, 10, 0);

add_action(SecurityScan::HOOK, static function (int $siteId): void {
    (new SecurityScan())->handle($siteId);
}, 10, 1);
```

Add `use Defyn\Dashboard\Jobs\SecurityScanAll;` / `use Defyn\Dashboard\Jobs\SecurityScan;`.

- [ ] **Step 7: Edit `src/Activation.php::maybeRunSelfHeal()`** — immediately after the existing SSL ensure-scheduled guard, add the second guard:

```php
if (function_exists('as_next_scheduled_action')
    && as_next_scheduled_action(\Defyn\Dashboard\Jobs\SecurityScanAll::HOOK, [], 'defyn') === false) {
    \Defyn\Dashboard\Jobs\Scheduler::installRecurringSchedules();
}
```

- [ ] **Step 8: Run green** — `composer test:integration -- --filter "SecurityScanAll|SchedulerSecurity"` → PASS.

- [ ] **Step 9: Commit**

```bash
git add src/Jobs/SecurityScanAll.php src/Jobs/SecurityScan.php src/Jobs/Scheduler.php src/Plugin.php src/Activation.php tests/Integration/Jobs/SecurityScanAllTest.php tests/Integration/Jobs/SchedulerSecurityTest.php
git commit -m "feat(p4-1): SecurityScanAll->SecurityScan daily fan-out + boot wiring + self-heal guard"
```

---

## Task 12: REST — `GET /sites/{id}/vulnerabilities` + `RateLimit::siteVulnerabilities`

**Files:**
- Create: `src/Rest/SitesVulnerabilitiesController.php`
- Modify: `src/Rest/Middleware/RateLimit.php` (add `siteVulnerabilities` 30/min)
- Modify: `src/Rest/RestRouter.php` (register the GET route)
- Test: `tests/Integration/Rest/SitesVulnerabilitiesTest.php`

Response (enveloped): `{ data: { scanned_at: string|null, vulnerabilities: [ {type,slug,component_name,installed_version,severity,cvss_score,cve,fixed_in,title} ] }, error: null }`. `scanned_at` = `site.lastSecurityScanAt` (NOT from findings rows).

- [ ] **Step 1: Write the failing controller test** `tests/Integration/Rest/SitesVulnerabilitiesTest.php` (mirror `AuthMeTest`/`SitesIncidentsTest` setup — boot REST, authenticate, hit the route). Assert: 200 with the envelope for an owned site (scanned_at + findings array), 404 `sites.not_found` for a non-owned/missing site, 401 without auth.

- [ ] **Step 2: Run red** → FAIL.

- [ ] **Step 3: Create `src/Rest/SitesVulnerabilitiesController.php`:**

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Services\SiteVulnerabilitiesRepository;
use WP_REST_Request;
use WP_REST_Response;

final class SitesVulnerabilitiesController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');

        $site = (new SitesRepository())->findByIdForUser($siteId, $userId);
        if ($site === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }

        $findings = (new SiteVulnerabilitiesRepository())->findForSite($siteId);

        return new WP_REST_Response([
            'data' => [
                'scanned_at'      => $site->lastSecurityScanAt,
                'vulnerabilities' => array_map(static fn ($v) => $v->toJson(), $findings),
            ],
            'error' => null,
        ], 200);
    }
}
```

- [ ] **Step 4: Add the rate-limit bucket to `src/Rest/Middleware/RateLimit.php`** (mirror `sitesIncidents`):

```php
public const SITE_VULNERABILITIES_LIMIT  = 30;
public const SITE_VULNERABILITIES_WINDOW = MINUTE_IN_SECONDS;

public static function siteVulnerabilities(WP_REST_Request $request)
{
    $authResult = RequireAuth::check($request);
    if (is_wp_error($authResult)) {
        return $authResult;
    }
    $userId = (int) $request->get_param('_authenticated_user_id');
    $siteId = (int) $request['id'];
    $key    = sprintf('defyn_rl_siteVulnerabilities_%d_%d', $userId, $siteId);
    $count  = (int) (get_transient($key) ?: 0);
    if ($count >= self::SITE_VULNERABILITIES_LIMIT) {
        return new \WP_Error('vulnerabilities.rate_limited', 'Too many requests. Try again shortly.', ['status' => 429]);
    }
    set_transient($key, $count + 1, self::SITE_VULNERABILITIES_WINDOW);
    return true;
}
```

- [ ] **Step 5: Register the GET route in `src/Rest/RestRouter.php`** (add the `use` import):

```php
register_rest_route(self::NAMESPACE, '/sites/(?P<id>\d+)/vulnerabilities', [
    'methods'             => 'GET',
    'callback'            => [new SitesVulnerabilitiesController(), 'handle'],
    'permission_callback' => [RateLimit::class, 'siteVulnerabilities'],
]);
```

- [ ] **Step 6: Run green** — `composer test:integration -- --filter "SitesVulnerabilities"` → PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Rest/SitesVulnerabilitiesController.php src/Rest/Middleware/RateLimit.php src/Rest/RestRouter.php tests/Integration/Rest/SitesVulnerabilitiesTest.php
git commit -m "feat(p4-1): GET /sites/{id}/vulnerabilities + siteVulnerabilities 30/min bucket + route"
```

---

## Task 13: REST — `POST /sites/{id}/security/scan` + route + CORS

**Files:**
- Create: `src/Rest/SecurityScanController.php`
- Modify: `src/Rest/Middleware/RateLimit.php` (add `securityScan` 6/hr)
- Modify: `src/Rest/RestRouter.php` (register POST route)
- Test: `tests/Integration/Rest/SecurityScanControllerTest.php`, `tests/Integration/Rest/RestRouterCorsSecurityTest.php`

POST behavior: ownership-checked (404 `sites.not_found`); ensure the feed is fresh (`VulnFeedService::refreshIfStale()` — cheap when fresh, no-op without a key) then schedule a `SecurityScan` AS job for the site; return **202** direct body `{ scheduled: true, site_id: <id> }` (mirror `SitesPluginsRefreshController`).

- [ ] **Step 1: Write the failing test** `tests/Integration/Rest/SecurityScanControllerTest.php`: 202 `{scheduled:true,site_id}` for an owned site (assert an AS `SecurityScan` action is scheduled), 404 for non-owned.

- [ ] **Step 2: Run red** → FAIL.

- [ ] **Step 3: Create `src/Rest/SecurityScanController.php`:**

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Jobs\SecurityScan;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Services\VulnFeedService;
use WP_REST_Request;
use WP_REST_Response;

final class SecurityScanController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');

        if ((new SitesRepository())->findByIdForUser($siteId, $userId) === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }

        // Best-effort feed freshness (no-op without a key / when fresh). Never throws.
        (new VulnFeedService())->refreshIfStale();

        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time(), SecurityScan::HOOK, [$siteId], 'defyn');
        }

        return new WP_REST_Response(['scheduled' => true, 'site_id' => $siteId], 202);
    }
}
```

- [ ] **Step 4: Add `securityScan` 6/hr bucket to `RateLimit.php`** (mirror `sitesCoreRefresh`): `SECURITY_SCAN_LIMIT = 6`, `SECURITY_SCAN_WINDOW = HOUR_IN_SECONDS`, key `defyn_rl_securityScan_%d_%d`, error code `security.rate_limited`, status 429.

- [ ] **Step 5: Register the POST route in `src/Rest/RestRouter.php`** (add the `use` import):

```php
register_rest_route(self::NAMESPACE, '/sites/(?P<id>\d+)/security/scan', [
    'methods'             => 'POST',
    'callback'            => [new SecurityScanController(), 'handle'],
    'permission_callback' => [RateLimit::class, 'securityScan'],
]);
```

Add both new routes to the project's CORS/preflight regression coverage (mirror how P2.9 added CORS regressions) — `tests/Integration/Rest/RestRouterCorsSecurityTest.php` asserts both `/sites/{id}/vulnerabilities` (GET) and `/sites/{id}/security/scan` (POST) emit the expected `Access-Control-Allow-*` headers on an `OPTIONS` preflight.

- [ ] **Step 6: Run green** — `composer test:integration -- --filter "SecurityScanController|CorsSecurity"` → PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Rest/SecurityScanController.php src/Rest/Middleware/RateLimit.php src/Rest/RestRouter.php tests/Integration/Rest/SecurityScanControllerTest.php tests/Integration/Rest/RestRouterCorsSecurityTest.php
git commit -m "feat(p4-1): POST /sites/{id}/security/scan + securityScan 6/hr + route + CORS regression"
```

---

## Task 14: Overview — `has_vulnerabilities` attention reason

**Files:**
- Modify: `src/Services/SitesRepository.php::findSitesNeedingAttention` (add the reason)
- Test: `tests/Integration/Services/SitesRepositoryAttentionVulnTest.php`

- [ ] **Step 1: Write the failing test** `tests/Integration/Services/SitesRepositoryAttentionVulnTest.php` — seed a site with one finding row, assert `findSitesNeedingAttention($userId)` returns that site with `'has_vulnerabilities'` in `reasons`:

```php
public function testHasVulnerabilitiesReasonSurfaces(): void
{
    Activation::ensureSchema();
    $sites = new SitesRepository();
    $id = $sites->create(1, 'https://x.test', 'X');
    (new SiteVulnerabilitiesRepository())->replaceForSite($id, [[
        'type'=>'plugin','slug'=>'elementor','component_name'=>'Elementor','installed_version'=>'3.18.0',
        'severity'=>'high','cvss_score'=>null,'cve'=>null,'fixed_in'=>'3.18.3','title'=>'x','source_id'=>'s',
    ]], '2026-06-15 00:00:00');

    $rows = $sites->findSitesNeedingAttention(1);
    $match = array_values(array_filter($rows, static fn ($r) => (int) $r['site_id'] === $id));
    self::assertNotEmpty($match);
    self::assertContains('has_vulnerabilities', $match[0]['reasons']);
}
```

- [ ] **Step 2: Run red** → FAIL.

- [ ] **Step 3: Edit `findSitesNeedingAttention`.** Add a `site_vulnerabilities` table alias + a CASE/EXISTS column in the SELECT (mirror the `has_failed_update` EXISTS pattern):

```sql
CASE WHEN EXISTS (SELECT 1 FROM {$vulnTable} sv WHERE sv.site_id = s.id) THEN 1 ELSE 0 END AS has_vulnerabilities
```

(define `$vulnTable = SiteVulnerabilitiesTable::tableName();` near the other `$pluginsTable`/`$themesTable` locals; add the `use Defyn\Dashboard\Schema\SiteVulnerabilitiesTable;` import). Then in the reason-collection block append:

```php
if ((int) $row['has_vulnerabilities'] === 1) {
    $reasons[] = 'has_vulnerabilities';
}
```

> The WHERE clause that decides whether a site is "needing attention" must also include the new condition (so a site with ONLY vulnerabilities still surfaces). Mirror however the existing query ORs its conditions — add `OR EXISTS(... site_vulnerabilities ...)` to that predicate.

- [ ] **Step 4: Run green** → PASS. Run the existing overview suite to confirm no ripple: `composer test:integration -- --filter "Overview|Attention"`.

- [ ] **Step 5: Commit**

```bash
git add src/Services/SitesRepository.php tests/Integration/Services/SitesRepositoryAttentionVulnTest.php
git commit -m "feat(p4-1): Overview has_vulnerabilities attention reason"
```

---

## Task 15: SPA — Zod schemas + attention enum + MSW handlers

**Files:**
- Modify: `apps/web/src/types/api.ts` (add `vulnerabilitySchema`, `siteVulnerabilitiesSchema`; add `'has_vulnerabilities'` to `overviewAttentionReasonSchema`)
- Modify: `apps/web/src/test/handlers.ts` (GET empty default + POST 202)
- Test: `apps/web/tests/types/securitySchemas.test.ts`

- [ ] **Step 1: Write the failing schema test** `apps/web/tests/types/securitySchemas.test.ts` — parse a sample `{ scanned_at, vulnerabilities: [...] }` payload via `siteVulnerabilitiesSchema`; assert `overviewAttentionReasonSchema.parse('has_vulnerabilities')` succeeds.

- [ ] **Step 2: Run red** (Node 22) → FAIL.

- [ ] **Step 3: Edit `src/types/api.ts`:**

```typescript
export const vulnerabilitySchema = z.object({
  type: z.enum(['plugin', 'theme', 'core']),
  slug: z.string(),
  component_name: z.string(),
  installed_version: z.string(),
  severity: z.enum(['critical', 'high', 'medium', 'low', 'unknown']),
  cvss_score: z.number().nullable(),
  cve: z.string().nullable(),
  fixed_in: z.string().nullable(),
  title: z.string().nullable(),
});
export type Vulnerability = z.infer<typeof vulnerabilitySchema>;

export const siteVulnerabilitiesSchema = z.object({
  scanned_at: z.string().nullable(),
  vulnerabilities: z.array(vulnerabilitySchema),
});
export type SiteVulnerabilities = z.infer<typeof siteVulnerabilitiesSchema>;
```

Add `'has_vulnerabilities'` to the `overviewAttentionReasonSchema` enum array.

- [ ] **Step 4: Add MSW handlers to `src/test/handlers.ts`:**

```typescript
// P4.1 — GET /sites/:id/vulnerabilities — empty, never-scanned by default.
http.get('*/wp-json/defyn/v1/sites/:id/vulnerabilities', () => {
  return HttpResponse.json({ data: { scanned_at: null, vulnerabilities: [] }, error: null });
}),
// P4.1 — POST /sites/:id/security/scan — 202 scheduled.
http.post('*/wp-json/defyn/v1/sites/:id/security/scan', ({ params }) => {
  return HttpResponse.json({ scheduled: true, site_id: Number(params.id) }, { status: 202 });
}),
```

- [ ] **Step 5: Run green** → PASS. Run the full SPA suite to confirm the enum change didn't break overview fixtures: `npm run test` (expect 4 carry-forward failures only).

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/types/api.ts apps/web/src/test/handlers.ts apps/web/tests/types/securitySchemas.test.ts
git commit -m "feat(p4-1): SPA vuln Zod schemas + has_vulnerabilities reason + MSW handlers"
```

---

## Task 16: SPA — `useSiteVulnerabilities` query hook

**Files:**
- Create: `apps/web/src/lib/queries/useSiteVulnerabilities.ts`
- Test: `apps/web/tests/useSiteVulnerabilities.test.tsx`

- [ ] **Step 1: Write the failing hook test** (mirror `useSiteIncidents.test.tsx`): override the GET handler to return one finding + a `scanned_at`; assert `result.current.data.vulnerabilities` has length 1 and `data.scanned_at` is the stub.

- [ ] **Step 2: Run red** → FAIL.

- [ ] **Step 3: Create the hook** (returns the parsed `{ scanned_at, vulnerabilities }` object; queryKey `['siteVulnerabilities', siteId]`):

```typescript
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { siteVulnerabilitiesSchema } from '@/types/api';
import { z } from 'zod';

const responseSchema = z.object({ data: siteVulnerabilitiesSchema, error: z.null() });

export function useSiteVulnerabilities(siteId: number, options?: { refetchInterval?: number | false }) {
  return useQuery({
    queryKey: ['siteVulnerabilities', siteId],
    queryFn: async () => {
      const raw = await apiClient.get<unknown>(`/sites/${siteId}/vulnerabilities`);
      return responseSchema.parse(raw).data;
    },
    staleTime: 30_000,
    refetchInterval: options?.refetchInterval ?? false,
  });
}
```

> Confirm the `apiClient` import path against `useSiteIncidents.ts` (it may import from `@/lib/api` or `@/lib/apiClient`) and match it exactly.

- [ ] **Step 4: Run green** → PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/lib/queries/useSiteVulnerabilities.ts apps/web/tests/useSiteVulnerabilities.test.tsx
git commit -m "feat(p4-1): useSiteVulnerabilities query hook"
```

---

## Task 17: SPA — `useScanSiteSecurity` mutation hook (poll-until-rescanned)

**Files:**
- Create: `apps/web/src/lib/mutations/useScanSiteSecurity.ts`
- Test: `apps/web/tests/useScanSiteSecurity.test.tsx`

Pattern: mirror `useRefreshSitePlugins` — POST `/sites/{id}/security/scan`, then poll `useSiteVulnerabilities` until `scanned_at` **changes from its pre-scan value**; on success invalidate `['siteVulnerabilities', siteId]`.

- [ ] **Step 1: Write the failing test** — assert calling `scan()` issues the POST and flips `isPolling` true on success (mirror the refresh-plugins test's assertions).

- [ ] **Step 2: Run red** → FAIL.

- [ ] **Step 3: Create the hook** (mirror `useRefreshSitePlugins.ts`; capture the **pre-scan** `scanned_at` and stop polling when it changes — do NOT compare an ISO `new Date()` against the server `'Y-m-d H:i:s'` string):

```typescript
import { useEffect, useRef, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { useSiteVulnerabilities } from '@/lib/queries/useSiteVulnerabilities';

export function useScanSiteSecurity(siteId: number) {
  const queryClient = useQueryClient();
  const preScanRef = useRef<string | null>(null);
  const [isPolling, setIsPolling] = useState(false);

  const query = useSiteVulnerabilities(siteId, { refetchInterval: isPolling ? 2_000 : false });

  useEffect(() => {
    if (!isPolling) return;
    const latest = query.data?.scanned_at ?? null;
    if (latest !== preScanRef.current) {
      setIsPolling(false);
    }
  }, [query.data?.scanned_at, isPolling]);

  const mutation = useMutation({
    mutationFn: async () => {
      preScanRef.current = query.data?.scanned_at ?? null; // capture value at trigger time
      return apiClient.post<{ scheduled: boolean; site_id: number }>(`/sites/${siteId}/security/scan`);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['siteVulnerabilities', siteId] });
      setIsPolling(true);
    },
  });

  return { scan: () => mutation.mutate(), isPending: mutation.isPending, isPolling, error: mutation.error };
}
```

- [ ] **Step 4: Run green** → PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/lib/mutations/useScanSiteSecurity.ts apps/web/tests/useScanSiteSecurity.test.tsx
git commit -m "feat(p4-1): useScanSiteSecurity mutation hook (poll-until-rescanned)"
```

---

## Task 18: SPA — `SiteSecurityPanel` (severity-grouped list)

**Files:**
- Create: `apps/web/src/components/sites/SiteSecurityPanel.tsx`
- Test: `apps/web/tests/components/sites/SiteSecurityPanel.test.tsx`

Layout A (severity-grouped): header "Security · N vulnerabilities · scanned {relative}" + a "Scan now" button; sections **Critical / High / Medium / Low** (only render a section if it has findings); each finding row = `component_name` (+ `(type)`) · `installed_version` → fix `fixed_in` · `cve`. States: loading, error, **empty** (`scanned_at` set, zero findings → "✓ No known vulnerabilities"), **never-scanned** (`scanned_at === null` → "Not yet scanned"), **scanning** (mutation `isPolling` → button shows "Scanning…").

- [ ] **Step 1: Write the failing render-state test** `tests/components/sites/SiteSecurityPanel.test.tsx`:
  - findings present → renders the Critical section + the component name + fixed-in;
  - `scanned_at` set + empty array → "No known vulnerabilities";
  - `scanned_at: null` → "Not yet scanned";
  - clicking "Scan now" issues the POST (MSW) without error.

- [ ] **Step 2: Run red** → FAIL.

- [ ] **Step 3: Create `SiteSecurityPanel.tsx`** consuming `useSiteVulnerabilities(siteId)` + `useScanSiteSecurity(siteId)`. Group findings by severity with a fixed order `['critical','high','medium','low','unknown']`; map each to a section header with the established palette (critical/high red-ish, medium amber, low slate). Mirror `IncidentHistoryPanel`/`SiteThemesPanel` structure + Tailwind classes. Use a small `relativeTime(scanned_at)` helper (reuse an existing one in `src/lib/` if present).

- [ ] **Step 4: Run green** → PASS (all render-state cases).

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/components/sites/SiteSecurityPanel.tsx apps/web/tests/components/sites/SiteSecurityPanel.test.tsx
git commit -m "feat(p4-1): SiteSecurityPanel severity-grouped findings + Scan now"
```

---

## Task 19: SPA — attention chip case + SiteDetail integration

**Files:**
- Modify: `apps/web/src/components/overview/AttentionReasonChip.tsx` (add `has_vulnerabilities`)
- Modify: `apps/web/src/routes/SiteDetail.tsx` (mount `SiteSecurityPanel`)
- Test: `apps/web/tests/components/overview/AttentionReasonChip.test.tsx` (extend), and rely on existing `SiteDetail.test.tsx` (carry-forward)

- [ ] **Step 1: Write/extend the failing chip test** — `AttentionReasonChip` with `reason="has_vulnerabilities"` renders a red "vulnerable" chip.

- [ ] **Step 2: Run red** → FAIL.

- [ ] **Step 3: Edit `AttentionReasonChip.tsx`** — add to `PALETTE`:

```typescript
has_vulnerabilities: { className: 'bg-red-100 text-red-800', label: 'vulnerable' },
```

- [ ] **Step 4: Edit `SiteDetail.tsx`** — add `import { SiteSecurityPanel } from '@/components/sites/SiteSecurityPanel'` and mount it in the panel stack (after `SiteThemesPanel`, gated on `data.status !== 'pending'` like its siblings):

```tsx
{data.status !== 'pending' && <SiteSecurityPanel siteId={siteId} />}
```

- [ ] **Step 5: Run green** — `npm run test` → chip test passes; full suite shows only the 4 carry-forward failures. If `SiteDetail.test.tsx` gains a NEW failure (beyond its 2 carry-forwards) because the panel fires an unmocked request, confirm the default MSW handler from Task 15 covers `/vulnerabilities` (it does) — otherwise it's a real regression to fix, not a carry-forward.

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/components/overview/AttentionReasonChip.tsx apps/web/src/routes/SiteDetail.tsx apps/web/tests/components/overview/AttentionReasonChip.test.tsx
git commit -m "feat(p4-1): has_vulnerabilities attention chip + mount SiteSecurityPanel on Site detail"
```

---

## Task 20: Release — version bump, build, ship, smoke, tag, MEMORY

**Files:**
- Modify: `packages/dashboard-plugin/defyn-dashboard.php` (`DEFYN_DASHBOARD_VERSION` → `0.13.0` + plugin header `Version: 0.13.0`)
- Build artifacts: `dist/defyn-dashboard-0.13.0.zip`

- [ ] **Step 1: Bump the dashboard version** in `defyn-dashboard.php` (both the `define('DEFYN_DASHBOARD_VERSION', '0.13.0');` and the header `* Version: 0.13.0`). Commit: `chore(p4-1): bump dashboard to v0.13.0`.

- [ ] **Step 2: Run the full PHP + SPA suites green** (minus documented carry-forwards):

```bash
cd packages/dashboard-plugin && composer test
cd ../../apps/web && export FNM_DIR="$HOME/.fnm"; eval "$(fnm env --shell bash)"; fnm use 22 && npm run test
```

- [ ] **Step 3: Build the dashboard zip** (symfony-preserving — exclude ONLY tests/dev tooling, NEVER `vendor/*` subdirs; top-level folder is `dashboard-plugin/`):

```bash
cd packages/dashboard-plugin
composer install --no-dev --classmap-authoritative
cd ..
rm -f dist/defyn-dashboard-0.13.0.zip && mkdir -p dist
zip -rq dist/defyn-dashboard-0.13.0.zip dashboard-plugin \
  -x 'dashboard-plugin/tests/*' '*wp-tests-config.php' 'dashboard-plugin/.phpunit.result.cache' \
     'dashboard-plugin/test-output.log' 'dashboard-plugin/phpunit.xml' 'dashboard-plugin/composer.lock' \
     'dashboard-plugin/.github/*' 'dashboard-plugin/.gitignore'
# VERIFY symfony prod deps survived (MUST print 2 lines):
unzip -l dist/defyn-dashboard-0.13.0.zip | grep -E "deprecation-contracts/function\.php|polyfill-php83/bootstrap\.php"
# VERIFY the new streaming-parser dep shipped (MUST print >=1 line):
unzip -l dist/defyn-dashboard-0.13.0.zip | grep -E "json-machine/src/Items\.php"
cd dashboard-plugin && composer install   # restore dev autoload so tests run locally
```

- [ ] **Step 4: Build the SPA** — `cd apps/web && npm run build` (Cloudflare Pages auto-deploys from `main`).

- [ ] **Step 5: Merge to main + push:**

```bash
git checkout main && git merge --ff-only p4-1-security-scanning && git push origin main
```

- [ ] **Step 6: Install the dashboard zip on Kinsta (MANUAL USER STEP — flag it).** The operator uploads `dist/defyn-dashboard-0.13.0.zip` via WP Admin → Plugins → "Replace current with uploaded version", then clears the MyKinsta cache (OPcache/Redis). Schema self-heal applies v11 on first `plugins_loaded`. Also flag the **`DEFYN_WORDFENCE_API_KEY` env/wp-config step**: the operator registers a free Wordfence Intelligence key and sets `DEFYN_WORDFENCE_API_KEY` on Kinsta (env var or wp-config constant). Without it the feature is inert-but-safe (no findings). **Do NOT attempt UI automation or enter the key yourself.**

- [ ] **Step 7: Production smoke (API curl only; login JWT field is `access_token`).** After the user confirms install:
  - `POST /auth/login` → capture `access_token`.
  - `GET /sites/{ownedId}/vulnerabilities` → **200** with `{ data: { scanned_at, vulnerabilities }, error: null }` (proves schema v11 + route). `scanned_at` likely null until the first scan.
  - `GET /sites/{id}/vulnerabilities` no-auth → **401**.
  - `GET /sites/999999/vulnerabilities` (non-owned) → **404** `sites.not_found`.
  - `POST /sites/{ownedId}/security/scan` → **202** `{scheduled:true,site_id}`; non-owned → **404**.
  - `GET /overview` → the attention-reason enum tolerates `has_vulnerabilities` (no Zod break; reason appears only if findings exist).
  - SPA: `/sites/:id` route 200; the deployed bundle contains the strings `Security` + `vulnerabilities` + `Scan now` (curl the built JS, grep).
  - (Happy keyed feed-download + real findings are foreclosed by the zero-sites prod state; covered by green PHP tests.)

- [ ] **Step 8: Tag + push:**

```bash
git tag p4-1-security-scanning-complete && git push origin p4-1-security-scanning-complete
```

- [ ] **Step 9: Update MEMORY** — append a P4.1-complete entry to `project_defyn_roadmap.md` (versions, tag, schema v11, the `DEFYN_WORDFENCE_API_KEY` env-constant decision + the Task-1 feed note path, smoke results, what was foreclosed) and refresh the roadmap "NEXT" pointer to **P4.2 (dedicated `/security` fleet page)**. Keep the MEMORY index line ≤200 chars.

---

## Self-Review (completed during planning)

- **Spec coverage:** §3 scope → Tasks 2–19; §4 VulnFeedService → Task 7 (+ key bootstrap Task 3); §5 data model → Task 2; §6 matcher → Task 5; §7 scan → Task 10; §8 jobs → Task 11; §9 REST (GET + POST + overview reason) → Tasks 12–14; §10 SPA → Tasks 15–19; §11 edge cases (no-key, best-effort, unparseable, replace-for-site) → Tasks 5/7/8/10; §13 release → Task 20; §14 guardrails → surfaced inline (no-connector, keyed best-effort, pure matcher, plugins+themes+core, snapshot+stamp, global feed, self-heal v11, daily fan-out + the second self-heal guard, overview reason, feed-refresh-once). ✅
- **Type consistency:** `SiteVulnerability` props/`toJson` (Task 4) match the GET envelope (Task 12) and the Zod `vulnerabilitySchema` (Task 15: type/slug/component_name/installed_version/severity/cvss_score/cve/fixed_in/title). `VulnerabilitiesRepository::upsertForSource`/`findByTypeAndSlug` (Task 6) are consumed by `VulnFeedService` (Task 7) and `VulnerabilityScanService` (Task 10) with matching shapes. `markSecurityScannedAt`/`lastSecurityScanAt` (Task 9) are read by the GET (Task 12). ✅
- **Known adjustment points flagged for implementers:** Task 1's note governs Task 7's URL/auth/field-paths; `ActivityLogger` namespace + `Plugin`/`Theme` model property names verified at implementation; `apiClient` import path matched to `useSiteIncidents`; the `scanned_at` poll-stop compares to the pre-scan value, not a timestamp `>`. ✅
