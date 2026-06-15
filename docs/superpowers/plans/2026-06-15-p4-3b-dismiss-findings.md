# P4.3b — Dismiss / Ignore Security Findings — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an operator dismiss a per-site vulnerability finding so it leaves the active view + at-risk counts and never re-alerts, with one-click Restore.

**Architecture:** Read-time overlay. A new `wp_defyn_dismissed_vulnerabilities` table keyed by the per-site fingerprint `type|slug|source_id`. The scan snapshot (`wp_defyn_site_vulnerabilities`) is **never** touched. Every read path — the per-site panel, the P4.2 fleet rollup SQL, and the P4.3a alert-diff — consults the overlay to flag/exclude dismissed findings. Restore = delete the overlay row (instant, no rescan).

**Tech Stack:** PHP 8.1 dashboard plugin (PHPUnit/wp-phpunit), React 18 + TS + TanStack Query v5 + Zod + Vitest + MSW SPA (pnpm, Node 22). Connector untouched (v0.1.7). Schema v11→v12. Dashboard v0.15.0→v0.16.0.

**Spec:** `docs/superpowers/specs/2026-06-15-p4-3b-dismiss-findings-design.md`.

**Branch:** `p4-3b-dismiss-findings` (already created off `main` @ `ab65041`).

---

## Plan-level corrections vs the spec (read first)

Two spec assumptions were verified false during exploration; the plan deviates deliberately:

1. **Version-pin bump is ONE line.** Only `tests/Integration/SecurityScanningSchemaTest.php:18` asserts the literal `11` (`self::assertSame(11, Activation::SCHEMA_VERSION)`). The other schema tests reference the constant, not a literal. Task 1 bumps just that one assertion.
2. **No site-delete cleanup.** `SitesRepository::deleteForUser` only deletes the `defyn_sites` row — it does **not** cascade-clean `defyn_site_plugins`/`defyn_site_themes`/`defyn_site_vulnerabilities` (those are already orphaned on delete by existing design). Adding dismissed-vulnerabilities cleanup there would be inconsistent. We **skip** it: orphaned dismissed rows are harmless (every read is ownership-scoped and requires the site row to exist; uninstall drops the table via `Activation::TABLES` iteration). The spec's §4 site-delete bullet is intentionally not implemented.

Everything else follows the spec.

## Test-runner notes (carry-forward from P4.1/P4.2/P4.3a)

- PHP: `cd packages/dashboard-plugin && composer test:integration -- --filter <Name>`; full suite `COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit`. Tolerate ONLY `UninstallTest::testUninstallDropsAllTables` (wp-phpunit TEMPORARY-TABLE infra) — **1** carry-forward.
- **Guardrail #15 (test isolation):** `SiteVulnerabilitiesRepository::replaceForSite` + `VulnerabilitiesRepository::upsertForSource` + `SitePluginsRepository::replaceForSite` use explicit `START TRANSACTION`/`COMMIT` that ESCAPES `WP_UnitTestCase` rollback. Any integration test seeding those tables MUST purge in `setUp` with `SET autocommit = 1` + `DELETE FROM`. Copy the `setUp` + `seedSite`/`seedVuln` helpers verbatim from `tests/Integration/Services/VulnerabilityScanAlertTest.php` (P4.3a).
- No `SitesRepository::create()` — seed sites via `$wpdb->insert($wpdb->prefix.'defyn_sites', [...])`.
- SPA: Node 22 (`.nvmrc` via fnm), `pnpm test`. Carry-forward **4**: `tests/SiteDetail.test.tsx` ×2 + `tests/components/sites/SiteCoreCard.test.tsx` ×2. Any OTHER failure is a real regression.

---

## Task 1: Schema v12 — `DismissedVulnerabilitiesTable` + Activation + version-pin bump

**Files:**
- Create: `packages/dashboard-plugin/src/Schema/DismissedVulnerabilitiesTable.php`
- Modify: `packages/dashboard-plugin/src/Activation.php` (TABLES array + `SCHEMA_VERSION`)
- Modify: `packages/dashboard-plugin/tests/Integration/SecurityScanningSchemaTest.php:18`
- Test: `packages/dashboard-plugin/tests/Integration/Schema/DismissedVulnerabilitiesSchemaTest.php` (new)

- [ ] **Step 1: Write the failing schema test** `tests/Integration/Schema/DismissedVulnerabilitiesSchemaTest.php`:

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Schema;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Schema\DismissedVulnerabilitiesTable;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class DismissedVulnerabilitiesSchemaTest extends AbstractSchemaTestCase
{
    public function testSchemaVersionIsTwelve(): void
    {
        self::assertSame(12, Activation::SCHEMA_VERSION);
    }

    public function testDismissedTableExistsAfterEnsureSchema(): void
    {
        Activation::ensureSchema();
        global $wpdb;
        $table = DismissedVulnerabilitiesTable::tableName();
        // phpcs:ignore WordPress.DB.PreparedSQL
        $found = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
        self::assertSame($table, $found, 'dismissed_vulnerabilities table should be created by ensureSchema');
    }

    public function testUniqueFingerprintColumnsPresent(): void
    {
        Activation::ensureSchema();
        global $wpdb;
        $table = DismissedVulnerabilitiesTable::tableName();
        // phpcs:ignore WordPress.DB.PreparedSQL
        $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}");
        foreach (['id', 'site_id', 'type', 'slug', 'source_id', 'dismissed_by', 'dismissed_at'] as $c) {
            self::assertContains($c, $cols, "column {$c} must exist");
        }
    }
}
```

- [ ] **Step 2: Run red** — `composer test:integration -- --filter DismissedVulnerabilitiesSchemaTest` → FAIL (class `DismissedVulnerabilitiesTable` not found; `SCHEMA_VERSION` is 11).

- [ ] **Step 3: Create `src/Schema/DismissedVulnerabilitiesTable.php`** (mirror `SiteVulnerabilitiesTable.php`):

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Schema;

/** P4.3b — wp_defyn_dismissed_vulnerabilities: per-site dismissal overlay keyed by the
 *  fingerprint (site_id, type, slug, source_id). The scan snapshot is never modified;
 *  this table is consulted at read time to flag/exclude dismissed findings. */
final class DismissedVulnerabilitiesTable implements SchemaTable
{
    public static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'defyn_dismissed_vulnerabilities';
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
            source_id VARCHAR(64) NOT NULL,
            dismissed_by BIGINT UNSIGNED NOT NULL,
            dismissed_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_dismissed_fp (site_id, type, slug, source_id),
            KEY idx_dismissed_site (site_id)
        ) {$charset};";
    }
}
```

- [ ] **Step 4: Wire into `src/Activation.php`.** Add the import near the other `use Defyn\Dashboard\Schema\…` lines:

```php
use Defyn\Dashboard\Schema\DismissedVulnerabilitiesTable;
```

Bump the constant (line 29):

```php
    public const SCHEMA_VERSION = 12;
```

Append to the `TABLES` array (after `SiteVulnerabilitiesTable::class`, line ~47):

```php
        SiteVulnerabilitiesTable::class,
        DismissedVulnerabilitiesTable::class,
    ];
```

(The Uninstaller iterates `Activation::TABLES`, so DROP-on-uninstall is automatic — no `Uninstaller.php` edit needed.)

- [ ] **Step 5: Bump the version-pin assertion** in `tests/Integration/SecurityScanningSchemaTest.php` line 18:

```php
        self::assertSame(12, Activation::SCHEMA_VERSION);
```

- [ ] **Step 6: Run green** — `composer test:integration -- --filter "DismissedVulnerabilitiesSchemaTest|SecurityScanningSchemaTest"` → PASS. Then full suite `COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit` → only `UninstallTest` carry-forward (UninstallTest now also drops the new table automatically; it should still pass-or-carry exactly as before).

- [ ] **Step 7: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP"
git add packages/dashboard-plugin/src/Schema/DismissedVulnerabilitiesTable.php packages/dashboard-plugin/src/Activation.php packages/dashboard-plugin/tests/Integration/
git commit -m "feat(p4-3b): schema v12 — wp_defyn_dismissed_vulnerabilities overlay table"
```

---

## Task 2: `DismissedVulnerabilitiesRepository`

**Files:**
- Create: `packages/dashboard-plugin/src/Services/DismissedVulnerabilitiesRepository.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/DismissedVulnerabilitiesRepositoryTest.php`

- [ ] **Step 1: Write the failing test**:

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Schema\DismissedVulnerabilitiesTable;
use Defyn\Dashboard\Services\DismissedVulnerabilitiesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class DismissedVulnerabilitiesRepositoryTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Activation::ensureSchema();
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->query('SET autocommit = 1');
        // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->query('DELETE FROM ' . DismissedVulnerabilitiesTable::tableName());
    }

    public function testDismissInsertsAndFindFingerprintsReturnsIt(): void
    {
        $repo = new DismissedVulnerabilitiesRepository();
        $repo->dismiss(7, 'plugin', 'elementor', 'src-ele', 1, '2026-06-15 00:00:00');

        $fps = $repo->findFingerprintsForSite(7);
        self::assertArrayHasKey('plugin|elementor|src-ele', $fps);
        self::assertTrue($fps['plugin|elementor|src-ele']);
    }

    public function testDismissIsIdempotent(): void
    {
        $repo = new DismissedVulnerabilitiesRepository();
        $repo->dismiss(7, 'plugin', 'elementor', 'src-ele', 1, '2026-06-15 00:00:00');
        $repo->dismiss(7, 'plugin', 'elementor', 'src-ele', 2, '2026-06-15 01:00:00');

        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL
        $count = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . DismissedVulnerabilitiesTable::tableName());
        self::assertSame(1, $count, 'repeat dismiss of the same fingerprint must not duplicate');
    }

    public function testRestoreDeletes(): void
    {
        $repo = new DismissedVulnerabilitiesRepository();
        $repo->dismiss(7, 'plugin', 'elementor', 'src-ele', 1, '2026-06-15 00:00:00');
        $repo->restore(7, 'plugin', 'elementor', 'src-ele');

        self::assertSame([], $repo->findFingerprintsForSite(7));
    }

    public function testRestoreOfMissingRowIsNoop(): void
    {
        $repo = new DismissedVulnerabilitiesRepository();
        $repo->restore(7, 'plugin', 'nonexistent', 'src-x'); // must not throw
        self::assertSame([], $repo->findFingerprintsForSite(7));
    }

    public function testFingerprintsAreScopedPerSite(): void
    {
        $repo = new DismissedVulnerabilitiesRepository();
        $repo->dismiss(7, 'plugin', 'elementor', 'src-ele', 1, '2026-06-15 00:00:00');
        $repo->dismiss(8, 'plugin', 'akismet', 'src-ak', 1, '2026-06-15 00:00:00');

        self::assertSame(['plugin|elementor|src-ele' => true], $repo->findFingerprintsForSite(7));
        self::assertSame(['plugin|akismet|src-ak' => true], $repo->findFingerprintsForSite(8));
    }
}
```

- [ ] **Step 2: Run red** — `composer test:integration -- --filter DismissedVulnerabilitiesRepositoryTest` → FAIL (class not found).

- [ ] **Step 3: Create `src/Services/DismissedVulnerabilitiesRepository.php`**:

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Schema\DismissedVulnerabilitiesTable;

/**
 * P4.3b — the dismissal overlay. Stores per-site accepted/ignored findings keyed by
 * the stable fingerprint (site_id, type, slug, source_id). Read by the per-site panel,
 * the fleet rollup, and the scan alert-diff to exclude dismissed findings. The scan
 * snapshot table is never touched here.
 */
final class DismissedVulnerabilitiesRepository
{
    /** Idempotent: a repeat dismiss of the same fingerprint refreshes the timestamp, never duplicates. */
    public function dismiss(int $siteId, string $type, string $slug, string $sourceId, int $userId, string $now): void
    {
        global $wpdb;
        $table = DismissedVulnerabilitiesTable::tableName();
        // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (site_id, type, slug, source_id, dismissed_by, dismissed_at)
             VALUES (%d, %s, %s, %s, %d, %s)
             ON DUPLICATE KEY UPDATE dismissed_by = VALUES(dismissed_by), dismissed_at = VALUES(dismissed_at)",
            $siteId, $type, $slug, $sourceId, $userId, $now
        ));
    }

    /** Idempotent: deleting a non-existent row is a no-op. */
    public function restore(int $siteId, string $type, string $slug, string $sourceId): void
    {
        global $wpdb;
        $wpdb->delete(
            DismissedVulnerabilitiesTable::tableName(),
            ['site_id' => $siteId, 'type' => $type, 'slug' => $slug, 'source_id' => $sourceId],
            ['%d', '%s', '%s', '%s']
        );
    }

    /**
     * @return array<string,true> keyed by "type|slug|source_id" for O(1) membership tests.
     */
    public function findFingerprintsForSite(int $siteId): array
    {
        global $wpdb;
        $table = DismissedVulnerabilitiesTable::tableName();
        // phpcs:ignore WordPress.DB.PreparedSQL
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT type, slug, source_id FROM {$table} WHERE site_id = %d", $siteId),
            ARRAY_A
        );
        $out = [];
        foreach ($rows ?: [] as $r) {
            $out[$r['type'] . '|' . $r['slug'] . '|' . $r['source_id']] = true;
        }
        return $out;
    }
}
```

- [ ] **Step 4: Run green** — `composer test:integration -- --filter DismissedVulnerabilitiesRepositoryTest` → PASS.

- [ ] **Step 5: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP"
git add packages/dashboard-plugin/src/Services/DismissedVulnerabilitiesRepository.php packages/dashboard-plugin/tests/Integration/Services/DismissedVulnerabilitiesRepositoryTest.php
git commit -m "feat(p4-3b): DismissedVulnerabilitiesRepository — dismiss/restore/findFingerprintsForSite"
```

---

## Task 3: `SiteVulnerability` DTO — emit `source_id` + `dismissed`

**Files:**
- Modify: `packages/dashboard-plugin/src/Models/SiteVulnerability.php`
- Test: `packages/dashboard-plugin/tests/Unit/Models/SiteVulnerabilityTest.php` (create if absent; else append)

The constructor currently has 14 readonly params ending `createdAt`. Add a 15th defaulting `dismissed = false` (so `fromRow` is unchanged), an immutable `withDismissed()`, and emit `source_id` + `dismissed` from `toJson`.

- [ ] **Step 1: Write the failing test** `tests/Unit/Models/SiteVulnerabilityTest.php`:

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Unit\Models;

use Defyn\Dashboard\Models\SiteVulnerability;
use PHPUnit\Framework\TestCase;

final class SiteVulnerabilityTest extends TestCase
{
    private function make(): SiteVulnerability
    {
        return SiteVulnerability::fromRow([
            'id' => 1, 'site_id' => 7, 'type' => 'plugin', 'slug' => 'elementor',
            'component_name' => 'Elementor', 'installed_version' => '3.0', 'severity' => 'high',
            'cvss_score' => null, 'cve' => 'CVE-1', 'fixed_in' => '3.1', 'title' => 'XSS',
            'source_id' => 'src-ele', 'scanned_at' => '2026-06-15 00:00:00', 'created_at' => '2026-06-15 00:00:00',
        ]);
    }

    public function testToJsonEmitsSourceIdAndDismissedDefaultFalse(): void
    {
        $json = $this->make()->toJson();
        self::assertSame('src-ele', $json['source_id']);
        self::assertFalse($json['dismissed']);
    }

    public function testWithDismissedReturnsNewInstanceMarkedDismissed(): void
    {
        $original = $this->make();
        $dismissed = $original->withDismissed(true);

        self::assertFalse($original->toJson()['dismissed'], 'original is unchanged (immutable)');
        self::assertTrue($dismissed->toJson()['dismissed']);
        self::assertSame('Elementor', $dismissed->toJson()['component_name'], 'other fields preserved');
    }
}
```

- [ ] **Step 2: Run red** — `composer test:unit -- --filter SiteVulnerabilityTest` → FAIL (`source_id`/`dismissed` not in toJson; `withDismissed` missing).

- [ ] **Step 3: Edit `src/Models/SiteVulnerability.php`.** Add the 15th constructor param after `createdAt`:

```php
        public readonly string $scannedAt,
        public readonly string $createdAt,
        public readonly bool $dismissed = false,
    ) {}
```

Add `withDismissed` immediately after `fromRow` (before `toJson`):

```php
    public function withDismissed(bool $dismissed): self
    {
        return new self(
            $this->id, $this->siteId, $this->type, $this->slug, $this->componentName,
            $this->installedVersion, $this->severity, $this->cvssScore, $this->cve,
            $this->fixedIn, $this->title, $this->sourceId, $this->scannedAt, $this->createdAt,
            $dismissed,
        );
    }
```

Add the two fields to `toJson` (after `'title'`):

```php
            'title'             => $this->title,
            'source_id'         => $this->sourceId,
            'dismissed'         => $this->dismissed,
        ];
```

- [ ] **Step 4: Run green** — `composer test:unit -- --filter SiteVulnerabilityTest` → PASS.

- [ ] **Step 5: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP"
git add packages/dashboard-plugin/src/Models/SiteVulnerability.php packages/dashboard-plugin/tests/Unit/Models/SiteVulnerabilityTest.php
git commit -m "feat(p4-3b): SiteVulnerability emits source_id + dismissed; immutable withDismissed"
```

---

## Task 4: `SiteVulnerabilitiesRepository::findForSite` — dismissed enrichment

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/SiteVulnerabilitiesRepository.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/SiteVulnerabilitiesRepositoryDismissTest.php` (new — keep the P4.1 repo test intact)

`findForSite` returns `list<SiteVulnerability>` from the snapshot. Enrich each with `dismissed` by checking the overlay set. To keep the method dependency-injectable + testable, give the repo an optional `?DismissedVulnerabilitiesRepository` constructor dep (defaulting to a fresh instance), matching the project's nullable-injection convention.

- [ ] **Step 1: Write the failing test** `tests/Integration/Services/SiteVulnerabilitiesRepositoryDismissTest.php`:

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Schema\DismissedVulnerabilitiesTable;
use Defyn\Dashboard\Services\DismissedVulnerabilitiesRepository;
use Defyn\Dashboard\Services\SiteVulnerabilitiesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SiteVulnerabilitiesRepositoryDismissTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Activation::ensureSchema();
        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL
        $wpdb->query('SET autocommit = 1');
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'defyn_site_vulnerabilities');
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'defyn_sites');
        $wpdb->query('DELETE FROM ' . DismissedVulnerabilitiesTable::tableName());
        // phpcs:enable WordPress.DB.PreparedSQL
    }

    public function testFindForSiteTagsDismissedFlag(): void
    {
        $repo = new SiteVulnerabilitiesRepository();
        $repo->replaceForSite(7, [
            $this->finding('plugin', 'elementor', 'src-ele', 'high'),
            $this->finding('plugin', 'wp-file-manager', 'src-wfm', 'critical'),
        ], '2026-06-15 00:00:00');

        (new DismissedVulnerabilitiesRepository())->dismiss(7, 'plugin', 'elementor', 'src-ele', 1, '2026-06-15 00:00:00');

        $found = $repo->findForSite(7);
        $byslug = [];
        foreach ($found as $f) { $byslug[$f->slug] = $f; }

        self::assertTrue($byslug['elementor']->dismissed, 'dismissed finding flagged true');
        self::assertFalse($byslug['wp-file-manager']->dismissed, 'non-dismissed finding flagged false');
    }

    private function finding(string $type, string $slug, string $sourceId, string $severity): array
    {
        return ['type'=>$type,'slug'=>$slug,'component_name'=>ucfirst($slug),'installed_version'=>'6.0',
                'severity'=>$severity,'cvss_score'=>null,'cve'=>null,'fixed_in'=>'9.9','title'=>'x','source_id'=>$sourceId];
    }
}
```

- [ ] **Step 2: Run red** — `composer test:integration -- --filter SiteVulnerabilitiesRepositoryDismissTest` → FAIL (`dismissed` always false — no enrichment).

- [ ] **Step 3: Edit `src/Services/SiteVulnerabilitiesRepository.php`.** Add a constructor with an injectable dismissals repo, and enrich in `findForSite`. Add the constructor at the top of the class (after the `SEVERITY_RANK` const):

```php
    public function __construct(
        private readonly ?DismissedVulnerabilitiesRepository $dismissals = null,
    ) {}
```

In `findForSite`, after building the rows, enrich each:

```php
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

        $dismissed = ($this->dismissals ?? new DismissedVulnerabilitiesRepository())
            ->findFingerprintsForSite($siteId);

        return array_map(static function (array $row) use ($dismissed): SiteVulnerability {
            $v  = SiteVulnerability::fromRow($row);
            $fp = $v->type . '|' . $v->slug . '|' . $v->sourceId;
            return $v->withDismissed(isset($dismissed[$fp]));
        }, $rows ?: []);
    }
```

(The `use Defyn\Dashboard\Models\SiteVulnerability;` import already exists at the top of this file — don't duplicate it. `DismissedVulnerabilitiesRepository` is in the same `Defyn\Dashboard\Services` namespace, so no import is needed.)

- [ ] **Step 4: Run green** — `composer test:integration -- --filter "SiteVulnerabilitiesRepositoryDismissTest|VulnerabilityScan"` → PASS (the P4.1 repo/scan tests still green — `dismissed` defaults false when nothing is dismissed).

- [ ] **Step 5: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP"
git add packages/dashboard-plugin/src/Services/SiteVulnerabilitiesRepository.php packages/dashboard-plugin/tests/Integration/Services/SiteVulnerabilitiesRepositoryDismissTest.php
git commit -m "feat(p4-3b): findForSite enriches each finding with dismissed flag"
```

---

## Task 5: `VulnerabilityScanService` — exclude dismissed fingerprints from the alert-diff

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/VulnerabilityScanService.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/VulnerabilityScanAlertTest.php` (append one test + extend setUp purge)

The P4.3a `scan()` builds `$newFindings` from `$results` whose fp wasn't in `$priorFingerprints`. Add an 8th constructor dep `?DismissedVulnerabilitiesRepository $dismissed = null`; load the dismissed fp set and skip dismissed fps when building `$newFindings`. Keep `replaceForSite` / `markSecurityScannedAt` / the raw `site.vulnerabilities_detected` total UNCHANGED.

- [ ] **Step 1: Extend setUp + write the failing test** in `tests/Integration/Services/VulnerabilityScanAlertTest.php`. First add `'defyn_dismissed_vulnerabilities'` to the `foreach` list of tables its existing `setUp` DELETEs. Then append:

```php
    public function testDismissedReappearingFindingDoesNotAlert(): void
    {
        $siteId = $this->seedSite(1, 'https://d.test', 'Dism', false);
        (new \Defyn\Dashboard\Services\SitePluginsRepository())->replaceForSite($siteId, [
            ['slug'=>'wp-file-manager','name'=>'WP File Manager','version'=>'6.0','update_available'=>false,'update_version'=>null,'tested_up_to'=>null],
        ], '2026-06-15 00:00:00');
        $this->seedVuln('plugin', 'wp-file-manager', 'src-wfm', 'critical');
        (new \Defyn\Dashboard\Services\DismissedVulnerabilitiesRepository())
            ->dismiss($siteId, 'plugin', 'wp-file-manager', 'src-wfm', 1, '2026-06-15 00:00:00');

        $spy = new RecordingNotifier();
        (new VulnerabilityScanService(notifier: $spy))->scan($siteId);

        self::assertCount(0, $spy->calls, 'a dismissed finding must never alert, even as a first-seen new finding');
        global $wpdb;
        self::assertNull($wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}defyn_activity_log WHERE event_type = %s", 'site.new_vulnerabilities')));
        // ...but the snapshot still contains it (raw scan is untouched).
        self::assertCount(1, (new \Defyn\Dashboard\Services\SiteVulnerabilitiesRepository())->findForSite($siteId));
    }
```

- [ ] **Step 2: Run red** — `composer test:integration -- --filter VulnerabilityScanAlertTest` → FAIL (the dismissed finding is first-seen → currently alerts).

- [ ] **Step 3: Edit `src/Services/VulnerabilityScanService.php`.** Add the 8th ctor param after `$notifier`:

```php
        private readonly ?Notifier $notifier = null,
        private readonly ?DismissedVulnerabilitiesRepository $dismissed = null,
    ) {}
```

In `scan()`, near where the other deps resolve (after `$notifier = …`):

```php
        $dismissedFps = ($this->dismissed ?? new DismissedVulnerabilitiesRepository())
            ->findFingerprintsForSite($siteId);
```

In the `$newFindings` build loop, add the dismissed-skip alongside the prior-fingerprint check (the ONLY change is the `|| isset($dismissedFps[$fp])`):

```php
        foreach ($results as $r) {
            $fp = $r['type'] . '|' . $r['slug'] . '|' . $r['source_id'];
            if (isset($priorFingerprints[$fp]) || isset($dismissedFps[$fp])) {
                continue;
            }
            $newFindings[] = $r;
            if (isset($newCounts[$r['severity']])) {
                $newCounts[$r['severity']]++;
            }
        }
```

(Everything after — usort, mute-gate, `site.new_vulnerabilities` — is unchanged. The `site.vulnerabilities_detected` raw total is unchanged. `DismissedVulnerabilitiesRepository` is in the same `Services` namespace — no import needed.)

- [ ] **Step 4: Run green** — `composer test:integration -- --filter VulnerabilityScanAlertTest` → PASS (all P4.3a tests + the new dismissed-exclusion test).

- [ ] **Step 5: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP"
git add packages/dashboard-plugin/src/Services/VulnerabilityScanService.php packages/dashboard-plugin/tests/Integration/Services/VulnerabilityScanAlertTest.php
git commit -m "feat(p4-3b): scan alert-diff skips dismissed fingerprints (never re-alerts)"
```

---

## Task 6: `findFleetSummariesForUser` — exclude dismissed from at-risk counts

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/SiteVulnerabilitiesRepository.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/SiteVulnerabilitiesRepositoryDismissTest.php` (append)

Exclude dismissed findings from the rollup by adding a `NOT EXISTS` to the `LEFT JOIN … ON` clause (in the ON, NOT the WHERE, to preserve the LEFT JOIN so all sites still appear with zero counts).

- [ ] **Step 1: Append the failing test** to `SiteVulnerabilitiesRepositoryDismissTest.php` (its `setUp` already purges `defyn_sites`):

```php
    public function testFleetSummaryExcludesDismissedFromCounts(): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix.'defyn_sites', [
            'user_id'=>1,'url'=>'https://f.test','label'=>'Fleet','status'=>'active',
            'last_security_scan_at'=>'2026-06-15 00:00:00',
            'created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s'),
        ]);
        $siteId = (int) $wpdb->insert_id;

        $repo = new SiteVulnerabilitiesRepository();
        $repo->replaceForSite($siteId, [
            $this->finding('plugin', 'elementor', 'src-ele', 'high'),
            $this->finding('plugin', 'wp-file-manager', 'src-wfm', 'critical'),
        ], '2026-06-15 00:00:00');
        (new DismissedVulnerabilitiesRepository())->dismiss($siteId, 'plugin', 'wp-file-manager', 'src-wfm', 1, '2026-06-15 00:00:00');

        $rows = $repo->findFleetSummariesForUser(1);
        $row = null;
        foreach ($rows as $r) { if ((int) $r['site_id'] === $siteId) { $row = $r; } }

        self::assertNotNull($row);
        self::assertSame(0, (int) $row['critical'], 'dismissed critical excluded');
        self::assertSame(1, (int) $row['high'], 'non-dismissed high still counted');
        self::assertSame(1, (int) $row['total'], 'total counts only non-dismissed');
        self::assertNotNull($row['last_security_scan_at'], 'site still reads scanned (clean), not never-scanned');
    }
```

- [ ] **Step 2: Run red** — `composer test:integration -- --filter SiteVulnerabilitiesRepositoryDismissTest` → FAIL (dismissed critical still counted: critical=1, total=2).

- [ ] **Step 3: Edit `findFleetSummariesForUser`.** Add the dismissals table name + the `NOT EXISTS` in the JOIN ON:

```php
        $sv        = SiteVulnerabilitiesTable::tableName();
        $sites     = SitesTable::tableName();
        $dismissed = DismissedVulnerabilitiesTable::tableName();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT s.id AS site_id, s.label AS label, s.url AS url,
                        s.last_security_scan_at AS last_security_scan_at,
                        SUM(CASE WHEN sv.severity = 'critical' THEN 1 ELSE 0 END) AS critical,
                        SUM(CASE WHEN sv.severity = 'high'     THEN 1 ELSE 0 END) AS high,
                        SUM(CASE WHEN sv.severity = 'medium'   THEN 1 ELSE 0 END) AS medium,
                        SUM(CASE WHEN sv.severity = 'low'      THEN 1 ELSE 0 END) AS low,
                        COUNT(sv.id) AS total
                 FROM {$sites} s
                 LEFT JOIN {$sv} sv ON sv.site_id = s.id
                     AND NOT EXISTS (
                         SELECT 1 FROM {$dismissed} d
                         WHERE d.site_id = sv.site_id AND d.type = sv.type
                           AND d.slug = sv.slug AND d.source_id = sv.source_id
                     )
                 WHERE s.user_id = %d
                 GROUP BY s.id, s.label, s.url, s.last_security_scan_at
                 ORDER BY s.id ASC",
                $userId
            ),
            ARRAY_A
        );
```

Add the import at the top of the file (next to the other Schema imports):

```php
use Defyn\Dashboard\Schema\DismissedVulnerabilitiesTable;
```

(Only the `AND NOT EXISTS (…)` block, the `$dismissed` variable, and the import are new. The `SELECT`/`GROUP BY`/`ORDER BY` + the `array_map` return below are unchanged.)

- [ ] **Step 4: Run green** — `composer test:integration -- --filter "SiteVulnerabilitiesRepositoryDismissTest|SecurityService|SecurityFleet"` → PASS (the P4.2 SecurityService/fleet tests still green — no dismissals seeded there, so counts unchanged).

- [ ] **Step 5: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP"
git add packages/dashboard-plugin/src/Services/SiteVulnerabilitiesRepository.php packages/dashboard-plugin/tests/Integration/Services/SiteVulnerabilitiesRepositoryDismissTest.php
git commit -m "feat(p4-3b): fleet rollup excludes dismissed findings from at-risk counts"
```

---

## Task 7: REST — `RateLimit::vulnerabilitiesDismiss` + `SitesVulnerabilitiesDismissController` + route + CORS

**Files:**
- Modify: `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php` (new bucket method)
- Create: `packages/dashboard-plugin/src/Rest/SitesVulnerabilitiesDismissController.php`
- Modify: `packages/dashboard-plugin/src/Rest/RestRouter.php` (register route)
- Test: `packages/dashboard-plugin/tests/Integration/Rest/SitesVulnerabilitiesDismissTest.php` (new)
- Test: `packages/dashboard-plugin/tests/Integration/Rest/SitesVulnerabilitiesDismissCorsTest.php` (new — mirror `SecurityFleetCorsTest.php`)

The controller mirrors `SitesAlertsMuteController` (ownership 404 → body validation → repo call → activity → response), but validates the fingerprint against the snapshot on dismiss and returns the enveloped `{data:{dismissed},error:null}` shape (consistent with the per-site GET).

- [ ] **Step 1: Add the rate-limit bucket.** In `src/Rest/Middleware/RateLimit.php`, find an existing per-HOUR bucket method (e.g. `securityScan` 6/hr) and add a sibling 30/HOUR method, copying its exact body + helper call and changing only the bucket key + limit:

```php
    public static function vulnerabilitiesDismiss(WP_REST_Request $request)
    {
        return self::enforce($request, 'vuln_dismiss', 30, HOUR_IN_SECONDS);
    }
```

(Match the REAL helper name/signature used by the neighbouring buckets in that file — if they call a private `check(...)`/`limit(...)`/`throttle(...)` rather than `enforce(...)`, use that exact one. The contract: a 30/HOUR per-user bucket that chains `RequireAuth::check` exactly like its neighbours.)

- [ ] **Step 2: Write the failing controller test** `tests/Integration/Rest/SitesVulnerabilitiesDismissTest.php`. Copy the auth-dispatch + site-seeding harness from an existing per-site REST test (e.g. the P4.1 `tests/Integration/Rest/` vulnerabilities-GET test, or the mute-toggle test) — it shows how to register a user, build an authenticated `WP_REST_Request`, seed a site via `$wpdb->insert`, and dispatch via `rest_do_request`. Seed a snapshot finding via `SiteVulnerabilitiesRepository::replaceForSite` (purge in setUp per guardrail #15). Assertions:

```php
public function testDismissInsertsAndReturns200(): void
{ /* POST owned site, body {type:'plugin',slug:'elementor',source_id:'src-ele',dismissed:true}
     where that finding IS in the snapshot → 200; json data.dismissed === true;
     a row exists in defyn_dismissed_vulnerabilities; an activity row site.vulnerability_dismissed exists. */ }

public function testRestoreDeletesAndReturns200(): void
{ /* after dismiss, POST dismissed:false → 200 data.dismissed === false; row gone;
     activity site.vulnerability_restored exists. */ }

public function testDismissUnknownFingerprintReturns400(): void
{ /* dismissed:true for a (type,slug,source_id) NOT in the snapshot → 400 vulnerabilities.unknown_finding. */ }

public function testNonOwnedSiteReturns404(): void
{ /* site owned by a different user → 404 sites.not_found. */ }

public function testInvalidPayloadReturns400(): void
{ /* missing/invalid dismissed bool → 400 vulnerabilities.invalid_payload. */ }
```

- [ ] **Step 3: Run red** → FAIL (controller + route missing).

- [ ] **Step 4: Create `src/Rest/SitesVulnerabilitiesDismissController.php`**:

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\DismissedVulnerabilitiesRepository;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Services\SiteVulnerabilitiesRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P4.3b — POST /defyn/v1/sites/{id}/vulnerabilities/dismiss.
 *
 * Toggles a per-site dismissal of a single finding identified by its fingerprint
 * (type, slug, source_id). dismissed=true inserts (validated against the current
 * snapshot); dismissed=false restores (always allowed). Ownership-gated.
 * Envelope: { data: { dismissed: bool }, error: null }.
 */
final class SitesVulnerabilitiesDismissController
{
    private const TYPES = ['plugin', 'theme', 'core'];

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');

        if ((new SitesRepository())->findByIdForUser($siteId, $userId) === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }

        $body = $request->get_json_params() ?: [];
        $type     = isset($body['type']) ? (string) $body['type'] : '';
        $slug     = isset($body['slug']) ? (string) $body['slug'] : '';
        $sourceId = isset($body['source_id']) ? (string) $body['source_id'] : '';
        $dismissed = $body['dismissed'] ?? null;

        if (!is_bool($dismissed) || !in_array($type, self::TYPES, true) || $slug === '' || $sourceId === '') {
            return ErrorResponse::create(400, 'vulnerabilities.invalid_payload',
                'Body must include type, slug, source_id and a boolean "dismissed".');
        }

        $repo = new DismissedVulnerabilitiesRepository();
        $now  = gmdate('Y-m-d H:i:s');

        if ($dismissed) {
            $finding = null;
            foreach ((new SiteVulnerabilitiesRepository())->findForSite($siteId) as $v) {
                if ($v->type === $type && $v->slug === $slug && $v->sourceId === $sourceId) {
                    $finding = $v;
                    break;
                }
            }
            if ($finding === null) {
                return ErrorResponse::create(400, 'vulnerabilities.unknown_finding',
                    'No such finding in the current scan snapshot.');
            }
            $repo->dismiss($siteId, $type, $slug, $sourceId, $userId, $now);
            (new ActivityLogger())->log($userId, $siteId, 'site.vulnerability_dismissed', [
                'type' => $type, 'slug' => $slug, 'source_id' => $sourceId,
                'component_name' => $finding->componentName,
            ]);
        } else {
            $repo->restore($siteId, $type, $slug, $sourceId);
            (new ActivityLogger())->log($userId, $siteId, 'site.vulnerability_restored', [
                'type' => $type, 'slug' => $slug, 'source_id' => $sourceId,
            ]);
        }

        return new WP_REST_Response(['data' => ['dismissed' => $dismissed], 'error' => null], 200);
    }
}
```

- [ ] **Step 5: Register the route in `src/Rest/RestRouter.php`.** After the P4.1 `/sites/{id}/vulnerabilities` GET registration (line ~342), add (matching the exact idiom the neighbour uses — confirm whether callbacks are `[new X(), 'handle']` or `[X::class, 'handle']` by copying the GET directly above):

```php
        // P4.3b — POST /sites/{id}/vulnerabilities/dismiss. Toggles a per-site finding
        // dismissal. RateLimit::vulnerabilitiesDismiss chains RequireAuth::check + a
        // 30/HOUR bucket.
        register_rest_route(self::NAMESPACE, '/sites/(?P<id>\d+)/vulnerabilities/dismiss', [
            'methods'             => 'POST',
            'callback'            => [new SitesVulnerabilitiesDismissController(), 'handle'],
            'permission_callback' => [RateLimit::class, 'vulnerabilitiesDismiss'],
        ]);
```

- [ ] **Step 6: Add the CORS regression test** `tests/Integration/Rest/SitesVulnerabilitiesDismissCorsTest.php` — copy `tests/Integration/Rest/SecurityFleetCorsTest.php` verbatim and change the route to `/sites/1/vulnerabilities/dismiss` + method `POST`, asserting the `Access-Control-Allow-*` / preflight headers behave like every other route. (Whatever allow-list mechanism that test exercises, this new route must satisfy it — if the CORS layer needs the route added to an allow-list, add it.)

- [ ] **Step 7: Run green** — `composer test:integration -- --filter "SitesVulnerabilitiesDismiss"` → PASS. Then full suite → only `UninstallTest` carry-forward.

- [ ] **Step 8: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP"
git add packages/dashboard-plugin/src/Rest/ packages/dashboard-plugin/tests/Integration/Rest/
git commit -m "feat(p4-3b): POST /sites/{id}/vulnerabilities/dismiss toggle + 30/hr bucket + CORS"
```

---

## Task 8: Dashboard v0.16.0 version bump

**Files:**
- Modify: `packages/dashboard-plugin/defyn-dashboard.php` (both version lines)

- [ ] **Step 1: Edit the header + constant.** Change line 6 `* Version:           0.15.0` → `0.16.0` and line 46 `define('DEFYN_DASHBOARD_VERSION', '0.15.0');` → `'0.16.0'`.

- [ ] **Step 2: Verify no test pins the string** — `grep -rn "0\.15\.0" packages/dashboard-plugin/src packages/dashboard-plugin/tests` → expect no matches. Confirm `grep -n "0.16.0" packages/dashboard-plugin/defyn-dashboard.php` shows both lines.

- [ ] **Step 3: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP"
git add packages/dashboard-plugin/defyn-dashboard.php
git commit -m "chore(p4-3b): bump dashboard plugin to v0.16.0"
```

---

## Task 9: SPA — Zod `source_id` + `dismissed` + MSW/fixtures

**Files:**
- Modify: `apps/web/src/types/api.ts` (`vulnerabilitySchema`)
- Modify: MSW handlers + any vulnerability fixtures (grep for them)

- [ ] **Step 1: Extend the Zod schema.** In `apps/web/src/types/api.ts`, add to `vulnerabilitySchema` (after `title`):

```ts
  title: z.string().nullable(),
  source_id: z.string(),
  dismissed: z.boolean(),
});
```

- [ ] **Step 2: Update MSW + fixtures.** Run `grep -rln "component_name\|installed_version" apps/web/src` to find the MSW handler(s) + fixtures that build vulnerability objects. Add `source_id` + `dismissed` to every vulnerability fixture object so the Zod parse stays valid. Example:

```ts
{ type: 'plugin', slug: 'elementor', component_name: 'Elementor', installed_version: '3.0',
  severity: 'high', cvss_score: null, cve: 'CVE-1', fixed_in: '3.1', title: 'XSS',
  source_id: 'src-ele', dismissed: false },
```

- [ ] **Step 3: Run green** — from `apps/web`: `pnpm test -- --run vulnerabilit` (and any security / site-detail handler tests). Run the full `pnpm test` to confirm only the 4 carry-forwards fail.

- [ ] **Step 4: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP"
git add apps/web/src/types/api.ts apps/web/src
git commit -m "feat(p4-3b): SPA vulnerabilitySchema gains source_id + dismissed; fixtures updated"
```

---

## Task 10: SPA — `useDismissVulnerability` mutation

**Files:**
- Create: `apps/web/src/lib/mutations/useDismissVulnerability.ts`
- Test: `apps/web/src/lib/mutations/useDismissVulnerability.test.ts` (mirror the existing mutation-test location/harness — if `useScanSiteSecurity` has no test, write a minimal MSW-backed one)

- [ ] **Step 1: Write the failing test** asserting the mutation POSTs `/sites/{id}/vulnerabilities/dismiss` with `{type,slug,source_id,dismissed}` and invalidates `['siteVulnerabilities', siteId]`. Use the project's renderHook + QueryClient + MSW harness (copy from an existing mutation test).

- [ ] **Step 2: Run red** → FAIL (hook missing).

- [ ] **Step 3: Create `apps/web/src/lib/mutations/useDismissVulnerability.ts`**:

```ts
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';

interface DismissArgs {
  type: string;
  slug: string;
  source_id: string;
  dismissed: boolean;
}

export function useDismissVulnerability(siteId: number) {
  const queryClient = useQueryClient();

  const mutation = useMutation({
    mutationFn: (args: DismissArgs) =>
      apiClient.post<{ data: { dismissed: boolean }; error: null }>(
        `/sites/${siteId}/vulnerabilities/dismiss`,
        args,
      ),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['siteVulnerabilities', siteId] });
    },
  });

  return {
    dismiss: (args: DismissArgs) => mutation.mutate(args),
    isPending: mutation.isPending,
    error: mutation.error,
  };
}
```

(Confirm `apiClient.post` accepts a body as the 2nd arg — `useScanSiteSecurity` calls it with one arg; check `apiClient`'s signature and pass the body the way it expects. If `apiClient.post(path, body)` isn't the shape, adapt to the real one.)

- [ ] **Step 4: Run green** → PASS.

- [ ] **Step 5: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP"
git add apps/web/src/lib/mutations/useDismissVulnerability.ts apps/web/src/lib/mutations/useDismissVulnerability.test.ts
git commit -m "feat(p4-3b): useDismissVulnerability mutation hook"
```

---

## Task 11: SPA — `SiteSecurityPanel` split + Dismissed section + Restore

**Files:**
- Modify: `apps/web/src/components/sites/SiteSecurityPanel.tsx`
- Test: `apps/web/src/components/sites/SiteSecurityPanel.test.tsx` (create if absent; else append)

Behavior: split `vulnerabilities` into `active` (`!v.dismissed`) and `dismissed` (`v.dismissed`). Active findings render in the existing severity groups, each row with a Dismiss action (calls `dismiss({...fp, dismissed:true})`). Below the active groups, a "Dismissed (N)" section lists struck rows each with a Restore action (`dismissed:false`). Hidden entirely when `dismissed.length === 0`. Meta line + `isClean` use **active** only. Row keys become `${type}|${slug}|${source_id}`.

- [ ] **Step 1: Write the failing test** `SiteSecurityPanel.test.tsx`. Render with `useSiteVulnerabilities` returning one active + one dismissed finding (vi.mock the query hook or use MSW), and spy on `useDismissVulnerability`. Assert:
  - the active finding's component name renders in the active region;
  - a "Dismissed (1)" heading is present;
  - the dismissed finding renders with a Restore control;
  - clicking Dismiss on the active row calls `dismiss` with `dismissed:true` and the right fingerprint;
  - clicking Restore calls `dismiss` with `dismissed:false`;
  - with zero dismissed findings, no "Dismissed" heading appears;
  - the meta line count reflects active-only.

- [ ] **Step 2: Run red** → FAIL.

- [ ] **Step 3: Implement.** Edit `SiteSecurityPanel.tsx`:
  - Import + call `const { dismiss } = useDismissVulnerability(siteId);`
  - `const active = useMemo(() => vulnerabilities.filter((v) => !v.dismissed), [vulnerabilities]);`
  - `const dismissed = useMemo(() => vulnerabilities.filter((v) => v.dismissed), [vulnerabilities]);`
  - `const grouped = useMemo(() => groupBySeverity(active), [active]);`
  - `metaLine` / `isClean` / `hasFindings` use `active` (`isClean = scannedAt !== null && active.length === 0`; meta count = `active.length`, append `· ${dismissed.length} dismissed` when `> 0`).
  - In the active row, add a Dismiss button calling `dismiss({ type: v.type, slug: v.slug, source_id: v.source_id, dismissed: true })`; change the row key to `${v.type}|${v.slug}|${v.source_id}`.
  - After the severity groups, render the Dismissed section when `dismissed.length > 0`.

Reference for the added parts (keep the existing severity-group rendering; adapt classes to the panel's style — the test asserts text + mutation calls, not classes):

```tsx
import { useDismissVulnerability } from '@/lib/mutations/useDismissVulnerability';

// dismiss control on an active row (inside VulnerabilityRow, needs siteId/dismiss via props or lift the row inline):
<button
  type="button"
  className="ml-auto text-zinc-400 hover:text-zinc-600 text-xs"
  onClick={() => dismiss({ type: vuln.type, slug: vuln.slug, source_id: vuln.source_id, dismissed: true })}
  aria-label={`Dismiss ${vuln.component_name}`}
>
  Dismiss
</button>

// Dismissed section after the severity groups:
{dismissed.length > 0 && (
  <div className="mt-4 pt-3 border-t">
    <p className="text-xs text-zinc-500 mb-2">Dismissed ({dismissed.length})</p>
    <ul className="w-full">
      {dismissed.map((v) => (
        <li key={`${v.type}|${v.slug}|${v.source_id}`} className="py-2 text-sm flex items-baseline gap-2 text-zinc-400">
          <span className="line-through">{v.component_name}</span>
          <span className="text-xs">({v.type})</span>
          <span>{v.installed_version}</span>
          <button
            type="button"
            className="ml-auto text-blue-600 hover:text-blue-700 text-xs"
            onClick={() => dismiss({ type: v.type, slug: v.slug, source_id: v.source_id, dismissed: false })}
            aria-label={`Restore ${v.component_name}`}
          >
            Restore
          </button>
        </li>
      ))}
    </ul>
  </div>
)}
```

Note: the existing `VulnerabilityRow` is a standalone sub-component taking only `{ vuln }`. To give it the Dismiss button you must thread `dismiss` (and the fingerprint) into it — either pass `onDismiss={() => dismiss({...})}` as a prop, or inline the row mapping inside the main component where `dismiss` is in scope. Pick the smaller diff (passing an `onDismiss` callback prop is clean).

- [ ] **Step 4: Run green** — `pnpm test -- --run SiteSecurityPanel` → PASS. Full `pnpm test` → only the 4 carry-forwards.

- [ ] **Step 5: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP"
git add apps/web/src/components/sites/SiteSecurityPanel.tsx apps/web/src/components/sites/SiteSecurityPanel.test.tsx
git commit -m "feat(p4-3b): SiteSecurityPanel splits active vs dismissed with one-click dismiss/restore"
```

---

## Task 12: Release — build, ship, smoke, tag, MEMORY

**Files:** build artifacts only.

- [ ] **Step 1: Full PHP suite green** — `cd packages/dashboard-plugin && COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit` → only `UninstallTest` carry-forward.

- [ ] **Step 2: Full SPA suite green** — `cd apps/web && pnpm test` (Node 22) → only the 4 carry-forwards (SiteDetail×2 + SiteCoreCard×2).

- [ ] **Step 3: Build the SPA** — `cd apps/web && pnpm build` (Cloudflare auto-deploys from `main` after merge).

- [ ] **Step 4: Build the dashboard zip** (symfony + json-machine preserving):

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin"
composer install --no-dev --classmap-authoritative
cd "/Users/pradeep/Local Sites/defynWP/packages"
rm -f "/Users/pradeep/Local Sites/defynWP/dist/defyn-dashboard-0.16.0.zip"
mkdir -p "/Users/pradeep/Local Sites/defynWP/dist"
zip -rq "/Users/pradeep/Local Sites/defynWP/dist/defyn-dashboard-0.16.0.zip" dashboard-plugin \
  -x 'dashboard-plugin/tests/*' '*wp-tests-config.php' 'dashboard-plugin/.phpunit.result.cache' \
     'dashboard-plugin/test-output.log' 'dashboard-plugin/phpunit.xml' 'dashboard-plugin/composer.lock' \
     'dashboard-plugin/.github/*' 'dashboard-plugin/.gitignore'
cd "/Users/pradeep/Local Sites/defynWP"
# VERIFY (symfony MUST print 2; json-machine MUST print >=1):
unzip -l dist/defyn-dashboard-0.16.0.zip | grep -cE "deprecation-contracts/function\.php|polyfill-php83/bootstrap\.php"
unzip -l dist/defyn-dashboard-0.16.0.zip | grep -c "json-machine/src/Items\.php"
unzip -p dist/defyn-dashboard-0.16.0.zip dashboard-plugin/defyn-dashboard.php | grep -m1 DEFYN_DASHBOARD_VERSION
cd packages/dashboard-plugin && composer install   # restore dev autoload
```

- [ ] **Step 5: Merge to main + push**

```bash
cd "/Users/pradeep/Local Sites/defynWP"
git checkout main && git merge --ff-only p4-3b-dismiss-findings && git push origin main
```

- [ ] **Step 6: Kinsta install (MANUAL USER STEP — flag it).** Operator uploads `dist/defyn-dashboard-0.16.0.zip` via WP Admin → Plugins → "Replace current with uploaded version" on `defynwp.defyn.agency`, then clears the MyKinsta cache. Schema self-heal applies v12 (creates the new table) on first page load.

- [ ] **Step 7: Production smoke (API curl only; login field `access_token`).** After the user confirms install (indirect — happy dismiss/restore path foreclosed by zero-sites + no-API-key prod state):
  - `POST /auth/login` → `access_token`.
  - `GET /security` (auth) → **200** (fleet endpoint still works post-rollup-change).
  - `GET /sites/1/vulnerabilities` (no-auth) → **401** `auth.missing_token`.
  - `POST /sites/999999/vulnerabilities/dismiss` (auth, body `{"type":"plugin","slug":"x","source_id":"y","dismissed":true}`) → **404** `sites.not_found` (route registered, controller wired).
  - `POST /sites/1/vulnerabilities/dismiss` (no-auth) → **401**.

- [ ] **Step 8: Tag + push**

```bash
cd "/Users/pradeep/Local Sites/defynWP"
git tag p4-3b-dismiss-findings-complete && git push origin p4-3b-dismiss-findings-complete
```

- [ ] **Step 9: Update MEMORY** — append a P4.3b-complete entry to `project_defyn_roadmap.md` (v0.16.0, tag, schema v12, the read-time-overlay table, the three exclusion points + the deliberately-raw `vulnerabilities_detected`, the no-site-delete-cleanup correction, smoke results) + refresh the `MEMORY.md` index line. **This completes the Phase-4 Security arc (P4.1–P4.3b); set NEXT = Reporting (roadmap item 3).**

---

## Self-Review (completed during planning)

- **Spec coverage:** §4 schema → Task 1; §5 repository → Task 2; §6 toJson/source_id/dismissed → Task 3, findForSite enrichment → Task 4, fleet rollup → Task 6; §7 alert-diff → Task 5; §8 REST → Task 7; §9 SPA schema → Task 9, mutation → Task 10, panel → Task 11; §10 activity events → Task 7 (emitted in the controller); §12 release → Task 8 (version) + Task 12 (ship). ✅
- **Spec §4 site-delete bullet → deliberately NOT implemented** (plan-correction #2): existing `deleteForUser` does not cascade-clean any per-site table; orphan dismissed rows are harmless. Documented at the top + in Task 12 Step 9 MEMORY note.
- **Type consistency:** the fingerprint string `type|slug|source_id` is identical in the repository (Task 2), `findForSite` (Task 4), the scan diff (Task 5), and the fleet `NOT EXISTS` tuple (Task 6). `DismissedVulnerabilitiesRepository::{dismiss,restore,findFingerprintsForSite}` signatures match across Tasks 2/4/5/7. `SiteVulnerability::{withDismissed,dismissed,sourceId}` consistent across Tasks 3/4. The toggle body `{type,slug,source_id,dismissed}` is identical in the controller (Task 7), the Zod schema (Task 9), the mutation (Task 10), and the panel calls (Task 11). Activity events `site.vulnerability_dismissed`/`site.vulnerability_restored` defined once (Task 7). ✅
- **Version-pin bump is one line** (`SecurityScanningSchemaTest.php:18`, plan-correction #1) — confirmed by grep; the other schema tests reference the constant. ✅
- **No connector change, no schema guarded-ALTER** (new table via `dbDelta`); Uninstaller auto-covers via `Activation::TABLES` iteration. ✅
