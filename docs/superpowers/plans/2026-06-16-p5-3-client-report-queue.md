# P5.3 — Client Report Queue Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a per-site queue of generated, stored, branded maintenance-report PDFs — auto-generated monthly + on demand, listed with status, emailed to the client when the operator sends, deleted manually.

**Architecture:** A new `Report` entity (table `wp_defyn_reports`, schema v13) wraps one stored PDF snapshot per site. Generation is always async via an Action Scheduler job (`GenerateReport`) driven by a manual create endpoint and a recurring monthly fan-out (`GenerateMonthlyReportsAll`). PDFs live in a private uploads dir (random filename, served only through an auth+ownership-gated `emit()` endpoint). Reuses P5.1 `ReportService`, P5.2 `ReportPdfService`/`BrandingService`/`ReportRange`/`emit()` seam, and the SslCheckAll/SecurityScanAll recurring-fan-out + self-heal patterns.

**Tech Stack:** PHP 8.1 (WP plugin, PHPUnit/wp-phpunit), Action Scheduler, dompdf (already shipped P5.2), React 18 + TS + TanStack Query v5 + Zod + Vitest/MSW (pnpm, Node 22).

**Branch:** `p5-3-client-report-queue` (off main @ `a5ccd5b`). Connector UNCHANGED (v0.1.7). Dashboard v0.18.0 → **v0.19.0**.

**Carry-forward tolerances:** PHP 1 (`UninstallTest::testUninstallDropsAllTables`); SPA 4 (`SiteDetail` ×2 + `SiteCoreCard` ×2). Baseline after P5.2: PHP 770/1, SPA 410/4.

**Test-isolation (guardrail #15):** `wp_defyn_reports` uses plain dbDelta + `$wpdb->insert` (NO explicit `START TRANSACTION`/`COMMIT`) so rows roll back under `WP_UnitTestCase` — BUT tests that seed `defyn_sites` still purge it in `setUp`. Copy the `seedSite` helper + the `setUp` purge from `tests/Integration/Services/VulnerabilityScanAlertTest.php`. There is NO `SitesRepository::create()` — seed sites via `$wpdb->insert($wpdb->prefix.'defyn_sites', [...])`. `ActivityLogger` lives in `Defyn\Dashboard\Services\`.

Full PHP suite: `COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit`. Filtered: `composer test:integration -- --filter <Name>` / `composer test:unit -- --filter <Name>`. SPA (Node 22): `export FNM_DIR="$HOME/.fnm"; eval "$(fnm env --shell bash)"; fnm use 22` then `pnpm test -- --run <pat>`; `pnpm build` typechecks (a vitest-green test can still fail `tsc` — P5.2 lesson).

---

## Task 1: Schema v13 — `ReportsTable` + `client_email` column + version-pin bumps

**Files:**
- Create: `packages/dashboard-plugin/src/Schema/ReportsTable.php`
- Modify: `packages/dashboard-plugin/src/Activation.php` (SCHEMA_VERSION, TABLES, ensureSchema, maybeRunSelfHeal)
- Test: `packages/dashboard-plugin/tests/Integration/Schema/ReportsSchemaTest.php` (new); bump all existing `assertSame(12, …)` schema pins.

- [ ] **Step 1: Write the failing test** `tests/Integration/Schema/ReportsSchemaTest.php` (mirror `tests/Integration/Schema/SecurityScanningSchemaTest.php` shape — extend `AbstractSchemaTestCase`):

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Schema;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Schema\ReportsTable;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class ReportsSchemaTest extends AbstractSchemaTestCase
{
    public function testSchemaVersionIs13(): void
    {
        self::assertSame(13, Activation::SCHEMA_VERSION);
    }

    public function testReportsTableExistsWithColumns(): void
    {
        global $wpdb;
        $t = ReportsTable::tableName();
        $cols = $wpdb->get_col("SHOW COLUMNS FROM `{$t}`"); // phpcs:ignore
        foreach (['id','site_id','title','range_from','range_to','status','file_name','file_size','recipient_email','error_message','generated_at','sent_at','created_at'] as $c) {
            self::assertContains($c, $cols, "missing column {$c}");
        }
    }

    public function testSitesHasClientEmailColumn(): void
    {
        global $wpdb;
        $t = SitesTable::tableName();
        $cols = $wpdb->get_col("SHOW COLUMNS FROM `{$t}`"); // phpcs:ignore
        self::assertContains('client_email', $cols);
    }
}
```

- [ ] **Step 2: Run red** → `composer test:integration -- --filter ReportsSchemaTest` → FAIL (class + columns missing).

- [ ] **Step 3: Create `src/Schema/ReportsTable.php`** (mirror `src/Schema/BulkJobsTable.php` — `implements SchemaTable`, `tableName()` returns `$wpdb->prefix.'defyn_reports'`, `createSql()` returns dbDelta DDL with the charset collate):

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Schema;

final class ReportsTable implements SchemaTable
{
    public static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'defyn_reports';
    }

    public static function createSql(): string
    {
        global $wpdb;
        $table   = self::tableName();
        $charset = $wpdb->get_charset_collate();
        return "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            site_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(160) NOT NULL DEFAULT 'Website Maintenance Report',
            range_from DATE NOT NULL,
            range_to DATE NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'generating',
            file_name VARCHAR(255) NULL,
            file_size INT UNSIGNED NULL,
            recipient_email VARCHAR(255) NULL,
            error_message TEXT NULL,
            generated_at DATETIME NULL,
            sent_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_reports_site_created (site_id, created_at)
        ) {$charset};";
    }
}
```
(Confirm the `SchemaTable` interface method names against `src/Schema/SchemaTable.php` — match `createSql()`/`tableName()` exactly.)

- [ ] **Step 4: Edit `src/Activation.php`:**
  - Bump `public const SCHEMA_VERSION = 12;` → `13`.
  - Add `ReportsTable::class,` to the `TABLES` array (and `use Defyn\Dashboard\Schema\ReportsTable;` at top).
  - In `ensureSchema()`, after `addLastSecurityScanAtColumn($wpdb);`, add `self::addClientEmailColumn($wpdb);`.
  - Add the guarded method (mirror `addLastSecurityScanAtColumn`):
```php
    private static function addClientEmailColumn(\wpdb $wpdb): void
    {
        $table  = SitesTable::tableName();
        $exists = $wpdb->get_var($wpdb->prepare(
            "SHOW COLUMNS FROM `{$table}` LIKE %s",
            'client_email'
        ));
        if ($exists !== null) {
            return;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL — column DDL cannot be parameterized.
        $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN client_email VARCHAR(255) NULL");
    }
```
  - In `maybeRunSelfHeal()`, after the SecurityScanAll guard block, add the new-hook ensure-scheduled guard:
```php
        // P5.3 — ensure the monthly report-generation schedule exists on a silent
        // upgrade (existing SSL/security guards don't cover a brand-new hook).
        if (function_exists('as_next_scheduled_action')
            && as_next_scheduled_action(\Defyn\Dashboard\Jobs\GenerateMonthlyReportsAll::HOOK, [], 'defyn') === false) {
            \Defyn\Dashboard\Jobs\Scheduler::installRecurringSchedules();
        }
```

- [ ] **Step 5: Bump every `assertSame(12, Activation::SCHEMA_VERSION)` pin to 13.** Find them: `grep -rln "assertSame(12" tests/` (expect the SchemaVersionMigration + per-feature schema tests — same multi-file ripple as P4.3b's v11→v12). Update each to `13`. Then `grep -rn "assertSame(12, " tests/` → no SCHEMA_VERSION matches remain.

- [ ] **Step 6: Run green** → `composer test:integration -- --filter "ReportsSchemaTest|SchemaVersion"` PASS. Full suite → only `UninstallTest`.

- [ ] **Step 7: Commit**
```bash
git add packages/dashboard-plugin/src/Schema/ReportsTable.php packages/dashboard-plugin/src/Activation.php packages/dashboard-plugin/tests/Integration/Schema/
git commit -m "feat(p5-3): schema v13 — wp_defyn_reports table + sites.client_email"
```

---

## Task 2: `Models\Report` immutable DTO

**Files:**
- Create: `packages/dashboard-plugin/src/Models/Report.php`
- Test: `packages/dashboard-plugin/tests/Unit/Models/ReportTest.php`

- [ ] **Step 1: Write the failing test** `tests/Unit/Models/ReportTest.php`:

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Unit\Models;

use Defyn\Dashboard\Models\Report;
use PHPUnit\Framework\TestCase;

final class ReportTest extends TestCase
{
    private function row(): array
    {
        return [
            'id' => '7', 'site_id' => '3', 'title' => 'Website Maintenance Report',
            'range_from' => '2026-05-01', 'range_to' => '2026-05-31', 'status' => 'ready',
            'file_name' => 'report-7-secrettoken.pdf', 'file_size' => '1120',
            'recipient_email' => null, 'error_message' => null,
            'generated_at' => '2026-06-01 02:00:00', 'sent_at' => null, 'created_at' => '2026-06-01 01:59:00',
        ];
    }

    public function testFromRowTypes(): void
    {
        $r = Report::fromRow($this->row());
        self::assertSame(7, $r->id);
        self::assertSame(3, $r->siteId);
        self::assertSame('ready', $r->status);
        self::assertSame('report-7-secrettoken.pdf', $r->fileName);
        self::assertSame(1120, $r->fileSize);
    }

    public function testToJsonNeverLeaksFileName(): void
    {
        $json = Report::fromRow($this->row())->toJson();
        self::assertArrayNotHasKey('file_name', $json);
        self::assertSame(1120, $json['file_size']);
        self::assertSame('2026-05-01', $json['range_from']);
        self::assertSame('ready', $json['status']);
    }
}
```

- [ ] **Step 2: Run red** → `composer test:unit -- --filter ReportTest` → FAIL.

- [ ] **Step 3: Create `src/Models/Report.php`** (mirror an existing readonly DTO like `src/Models/Site.php`):

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Models;

final class Report
{
    public function __construct(
        public readonly int $id,
        public readonly int $siteId,
        public readonly string $title,
        public readonly string $rangeFrom,
        public readonly string $rangeTo,
        public readonly string $status,
        public readonly ?string $fileName,
        public readonly ?int $fileSize,
        public readonly ?string $recipientEmail,
        public readonly ?string $errorMessage,
        public readonly ?string $generatedAt,
        public readonly ?string $sentAt,
        public readonly string $createdAt,
    ) {
    }

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['site_id'],
            (string) $row['title'],
            (string) $row['range_from'],
            (string) $row['range_to'],
            (string) $row['status'],
            isset($row['file_name']) && $row['file_name'] !== null ? (string) $row['file_name'] : null,
            isset($row['file_size']) && $row['file_size'] !== null ? (int) $row['file_size'] : null,
            isset($row['recipient_email']) && $row['recipient_email'] !== null ? (string) $row['recipient_email'] : null,
            isset($row['error_message']) && $row['error_message'] !== null ? (string) $row['error_message'] : null,
            isset($row['generated_at']) && $row['generated_at'] !== null ? (string) $row['generated_at'] : null,
            isset($row['sent_at']) && $row['sent_at'] !== null ? (string) $row['sent_at'] : null,
            (string) $row['created_at'],
        );
    }

    /** @return array<string,mixed> file_name is server-only and deliberately omitted. */
    public function toJson(): array
    {
        return [
            'id' => $this->id,
            'site_id' => $this->siteId,
            'title' => $this->title,
            'range_from' => $this->rangeFrom,
            'range_to' => $this->rangeTo,
            'status' => $this->status,
            'file_size' => $this->fileSize,
            'recipient_email' => $this->recipientEmail,
            'generated_at' => $this->generatedAt,
            'sent_at' => $this->sentAt,
            'created_at' => $this->createdAt,
        ];
    }
}
```

- [ ] **Step 4: Run green** → PASS. **Step 5: Commit** `feat(p5-3): Report immutable DTO (file_name never serialized)`.

---

## Task 3: `Services\ReportsRepository`

**Files:**
- Create: `packages/dashboard-plugin/src/Services/ReportsRepository.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/ReportsRepositoryTest.php`

- [ ] **Step 1: Write the failing test** (copy the `setUp` purge + `seedSite` from `tests/Integration/Services/VulnerabilityScanAlertTest.php`; purge `defyn_reports` + `defyn_sites`):

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\ReportsRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class ReportsRepositoryTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb->query('SET autocommit = 1');
        foreach (['defyn_reports','defyn_sites'] as $t) {
            $wpdb->query('DELETE FROM ' . $wpdb->prefix . $t);
        }
    }

    private function seedSite(int $id = 1): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'id' => $id, 'owner_user_id' => 1, 'url' => 'https://acme.test', 'label' => 'Acme',
            'status' => 'active', 'public_key' => 'pk', 'encrypted_dashboard_key' => 'ek', 'created_at' => '2026-01-01 00:00:00',
        ]);
        return $id;
    }

    public function testCreateThenFindAndCount(): void
    {
        $siteId = $this->seedSite();
        $repo = new ReportsRepository();
        $id = $repo->create($siteId, 'Website Maintenance Report', '2026-05-01', '2026-05-31', '2026-06-01 00:00:00');
        self::assertGreaterThan(0, $id);
        self::assertSame(1, $repo->countForSite($siteId));
        $rows = $repo->findForSite($siteId, 20, 0);
        self::assertCount(1, $rows);
        self::assertSame('generating', $rows[0]->status);
        self::assertSame($id, $repo->findByIdForSite($id, $siteId)->id);
        self::assertNull($repo->findByIdForSite($id, 999));
    }

    public function testLifecycleMarks(): void
    {
        $siteId = $this->seedSite();
        $repo = new ReportsRepository();
        $id = $repo->create($siteId, 'T', '2026-05-01', '2026-05-31', '2026-06-01 00:00:00');
        $repo->markReady($id, 'report-1-tok.pdf', 1234, '2026-06-01 02:00:00');
        self::assertSame('ready', $repo->findByIdForSite($id, $siteId)->status);
        self::assertSame(1234, $repo->findByIdForSite($id, $siteId)->fileSize);
        $repo->markSent($id, 'client@acme.test', '2026-06-02 09:00:00');
        self::assertSame('sent', $repo->findByIdForSite($id, $siteId)->status);
        self::assertSame('client@acme.test', $repo->findByIdForSite($id, $siteId)->recipientEmail);
        $repo->delete($id);
        self::assertNull($repo->findByIdForSite($id, $siteId));
    }

    public function testMarkFailedStoresError(): void
    {
        $siteId = $this->seedSite();
        $repo = new ReportsRepository();
        $id = $repo->create($siteId, 'T', '2026-05-01', '2026-05-31', '2026-06-01 00:00:00');
        $repo->markFailed($id, 'render boom');
        $r = $repo->findByIdForSite($id, $siteId);
        self::assertSame('failed', $r->status);
        self::assertSame('render boom', $r->errorMessage);
    }

    public function testExistsForSiteAndMonth(): void
    {
        $siteId = $this->seedSite();
        $repo = new ReportsRepository();
        self::assertFalse($repo->existsForSiteAndMonth($siteId, '2026-05-01', '2026-05-31'));
        $repo->create($siteId, 'T', '2026-05-01', '2026-05-31', '2026-06-01 00:00:00');
        self::assertTrue($repo->existsForSiteAndMonth($siteId, '2026-05-01', '2026-05-31'));
        self::assertFalse($repo->existsForSiteAndMonth($siteId, '2026-04-01', '2026-04-30'));
    }
}
```

- [ ] **Step 2: Run red** → FAIL. **Step 3: Create `src/Services/ReportsRepository.php`** (mirror `SiteVulnerabilitiesRepository` style — `global $wpdb`, `$wpdb->prepare`, `ReportsTable::tableName()`, `Report::fromRow`):

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Models\Report;
use Defyn\Dashboard\Schema\ReportsTable;

final class ReportsRepository
{
    public function create(int $siteId, string $title, string $from, string $to, string $now): int
    {
        global $wpdb;
        $wpdb->insert(ReportsTable::tableName(), [
            'site_id' => $siteId, 'title' => $title, 'range_from' => $from, 'range_to' => $to,
            'status' => 'generating', 'created_at' => $now,
        ]);
        return (int) $wpdb->insert_id;
    }

    /** @return Report[] newest first */
    public function findForSite(int $siteId, int $limit, int $offset): array
    {
        global $wpdb;
        $t = ReportsTable::tableName();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$t} WHERE site_id = %d ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
            $siteId, $limit, $offset
        ), ARRAY_A) ?: [];
        return array_map([Report::class, 'fromRow'], $rows);
    }

    public function countForSite(int $siteId): int
    {
        global $wpdb;
        $t = ReportsTable::tableName();
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE site_id = %d", $siteId));
    }

    public function findByIdForSite(int $reportId, int $siteId): ?Report
    {
        global $wpdb;
        $t = ReportsTable::tableName();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$t} WHERE id = %d AND site_id = %d", $reportId, $siteId
        ), ARRAY_A);
        return $row ? Report::fromRow($row) : null;
    }

    public function markReady(int $id, string $fileName, int $size, string $generatedAt): void
    {
        global $wpdb;
        $wpdb->update(ReportsTable::tableName(),
            ['status' => 'ready', 'file_name' => $fileName, 'file_size' => $size, 'generated_at' => $generatedAt, 'error_message' => null],
            ['id' => $id]);
    }

    public function markFailed(int $id, string $error): void
    {
        global $wpdb;
        $wpdb->update(ReportsTable::tableName(),
            ['status' => 'failed', 'error_message' => mb_substr($error, 0, 60000)],
            ['id' => $id]);
    }

    public function markSent(int $id, string $recipient, string $sentAt): void
    {
        global $wpdb;
        $wpdb->update(ReportsTable::tableName(),
            ['status' => 'sent', 'recipient_email' => $recipient, 'sent_at' => $sentAt],
            ['id' => $id]);
    }

    public function delete(int $id): void
    {
        global $wpdb;
        $wpdb->delete(ReportsTable::tableName(), ['id' => $id]);
    }

    public function existsForSiteAndMonth(int $siteId, string $from, string $to): bool
    {
        global $wpdb;
        $t = ReportsTable::tableName();
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$t} WHERE site_id = %d AND range_from = %s AND range_to = %s",
            $siteId, $from, $to
        )) > 0;
    }
}
```

- [ ] **Step 4: Run green** → PASS. Full suite → only `UninstallTest`. **Step 5: Commit** `feat(p5-3): ReportsRepository (CRUD + lifecycle marks + month dedup)`.

---

## Task 4: `Services\ReportStorage` (private file storage)

**Files:**
- Create: `packages/dashboard-plugin/src/Services/ReportStorage.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/ReportStorageTest.php`

- [ ] **Step 1: Write the failing test**:

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\ReportStorage;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class ReportStorageTest extends AbstractSchemaTestCase
{
    public function testStoreReadDeleteRoundTrips(): void
    {
        $s = new ReportStorage();
        $res = $s->store(42, '%PDF-1.7 fake bytes');
        self::assertStringStartsWith('report-42-', $res['file_name']);
        self::assertStringEndsWith('.pdf', $res['file_name']);
        self::assertSame(strlen('%PDF-1.7 fake bytes'), $res['size']);
        self::assertSame('%PDF-1.7 fake bytes', $s->read($res['file_name']));
        self::assertFileExists($s->path($res['file_name']));
        $s->delete($res['file_name']);
        self::assertNull($s->read($res['file_name']));
    }

    public function testRandomNamesDiffer(): void
    {
        $s = new ReportStorage();
        $a = $s->store(1, 'x'); $b = $s->store(1, 'x');
        self::assertNotSame($a['file_name'], $b['file_name']);
        $s->delete($a['file_name']); $s->delete($b['file_name']);
    }
}
```

- [ ] **Step 2: Run red** → FAIL. **Step 3: Create `src/Services/ReportStorage.php`:**

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Services;

final class ReportStorage
{
    private const SUBDIR = 'defyn-reports';

    public function dir(): string
    {
        $up = wp_upload_dir();
        return rtrim($up['basedir'], '/') . '/' . self::SUBDIR;
    }

    public function ensureDir(): void
    {
        $dir = $this->dir();
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        // Best-effort deny for Apache hosts (ignored on nginx/Kinsta — the random
        // filename + authed-only serving is the real protection).
        $ht = $dir . '/.htaccess';
        if (!file_exists($ht)) {
            @file_put_contents($ht, "Deny from all\n");
        }
        $idx = $dir . '/index.html';
        if (!file_exists($idx)) {
            @file_put_contents($idx, '');
        }
    }

    /** @return array{file_name:string,size:int} */
    public function store(int $reportId, string $bytes): array
    {
        $this->ensureDir();
        $token = wp_generate_password(32, false, false);
        $name  = "report-{$reportId}-{$token}.pdf";
        file_put_contents($this->path($name), $bytes);
        return ['file_name' => $name, 'size' => strlen($bytes)];
    }

    public function path(string $fileName): string
    {
        return $this->dir() . '/' . basename($fileName);
    }

    public function read(string $fileName): ?string
    {
        $p = $this->path($fileName);
        if (!is_file($p)) {
            return null;
        }
        $bytes = file_get_contents($p);
        return $bytes === false ? null : $bytes;
    }

    public function delete(string $fileName): void
    {
        $p = $this->path($fileName);
        if (is_file($p)) {
            @unlink($p);
        }
    }
}
```
(`basename()` on every path defends against any `../` in a stored name.)

- [ ] **Step 4: Run green** → PASS. **Step 5: Commit** `feat(p5-3): ReportStorage (random-named private PDF files + deny guard)`.

---

## Task 5: `Services\ReportMailer` (wp_mail + attachment seam)

**Files:**
- Create: `packages/dashboard-plugin/src/Services/ReportMailer.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/ReportMailerTest.php`

- [ ] **Step 1: Write the failing test** (inject a fake sender to capture args without sending):

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\ReportMailer;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class ReportMailerTest extends AbstractSchemaTestCase
{
    public function testSendPassesAttachmentAndReturnsBool(): void
    {
        $captured = [];
        $mailer = new ReportMailer(static function ($to, $subject, $body, $headers, $attachments) use (&$captured): bool {
            $captured = compact('to', 'subject', 'body', 'headers', 'attachments');
            return true;
        });
        $ok = $mailer->send('client@acme.test', 'Subject', 'Body', '/tmp/report.pdf');
        self::assertTrue($ok);
        self::assertSame('client@acme.test', $captured['to']);
        self::assertSame(['/tmp/report.pdf'], $captured['attachments']);
        self::assertContains('Content-Type: text/html; charset=UTF-8', $captured['headers']);
    }
}
```

- [ ] **Step 2: Run red** → FAIL. **Step 3: Create `src/Services/ReportMailer.php`:**

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Services;

final class ReportMailer
{
    /** @var callable(string,string,string,array,array):bool */
    private $sender;

    public function __construct(?callable $sender = null)
    {
        $this->sender = $sender ?? static fn ($to, $subject, $body, $headers, $attachments): bool
            => (bool) wp_mail($to, $subject, $body, $headers, $attachments);
    }

    public function send(string $to, string $subject, string $body, string $attachmentPath): bool
    {
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        return ($this->sender)($to, $subject, $body, $headers, [$attachmentPath]);
    }
}
```

- [ ] **Step 4: Run green** → PASS. **Step 5: Commit** `feat(p5-3): ReportMailer (wp_mail + PDF attachment, injectable seam)`.

---

## Task 6: `Jobs\GenerateReport` (async render → store → markReady)

**Files:**
- Create: `packages/dashboard-plugin/src/Jobs/GenerateReport.php`
- Modify: `packages/dashboard-plugin/src/Services/ReportPdfService.php` (drop `final` — subclassed in the failure-seam test, mirroring the P5.2 `SitesReportPdfController` precedent)
- Test: `packages/dashboard-plugin/tests/Integration/Jobs/GenerateReportTest.php`

- [ ] **Step 1: Write the failing test** (seed a site + a `generating` report row; run handle; assert ready + a stored file. Then a throwing PDF service → failed, no throw). Copy `setUp` purge + `seedSite` from `VulnerabilityScanAlertTest`:

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Jobs;

use Defyn\Dashboard\Jobs\GenerateReport;
use Defyn\Dashboard\Services\ReportsRepository;
use Defyn\Dashboard\Services\ReportStorage;
use Defyn\Dashboard\Services\ReportPdfService;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class GenerateReportTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb->query('SET autocommit = 1');
        foreach (['defyn_reports','defyn_sites'] as $t) { $wpdb->query('DELETE FROM ' . $wpdb->prefix . $t); }
    }
    private function seedSite(int $id = 1): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', ['id'=>$id,'owner_user_id'=>1,'url'=>'https://acme.test','label'=>'Acme','status'=>'active','public_key'=>'pk','encrypted_dashboard_key'=>'ek','created_at'=>'2026-01-01 00:00:00','wp_version'=>'6.9.4']);
        return $id;
    }

    public function testSuccessMarksReadyAndStoresFile(): void
    {
        $siteId = $this->seedSite();
        $repo = new ReportsRepository();
        $id = $repo->create($siteId, 'T', '2026-05-01', '2026-05-31', '2026-06-01 00:00:00');
        (new GenerateReport())->handle($id);
        $r = $repo->findByIdForSite($id, $siteId);
        self::assertSame('ready', $r->status);
        self::assertNotNull($r->fileName);
        self::assertStringStartsWith('%PDF-', (new ReportStorage())->read($r->fileName));
        (new ReportStorage())->delete($r->fileName);
    }

    public function testRenderFailureMarksFailedAndDoesNotThrow(): void
    {
        $siteId = $this->seedSite();
        $repo = new ReportsRepository();
        $id = $repo->create($siteId, 'T', '2026-05-01', '2026-05-31', '2026-06-01 00:00:00');
        $boom = new class extends ReportPdfService {
            public function render(array $report, array $branding): string { throw new \RuntimeException('boom'); }
        };
        (new GenerateReport(null, null, $boom))->handle($id);  // ctor: (?ReportsRepository,?ReportStorage,?ReportPdfService)
        $r = $repo->findByIdForSite($id, $siteId);
        self::assertSame('failed', $r->status);
        self::assertStringContainsString('boom', $r->errorMessage);
    }

    public function testMissingRowIsNoop(): void
    {
        (new GenerateReport())->handle(999999); // must not throw
        $this->expectNotToPerformAssertions();
    }
}
```
(Drop `final` on `ReportPdfService` so the anonymous subclass works — same trade-off P5.2 made for `SitesReportPdfController`. Add a one-line docblock noting it's subclassed in tests for the failure seam.)

- [ ] **Step 2: Run red** → FAIL. **Step 3: Create `src/Jobs/GenerateReport.php`** (resolve site+owner; compose → branding → render → store → markReady; Throwable → markFailed; emit events via `ActivityLogger`):

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Schema\ReportsTable;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\BrandingService;
use Defyn\Dashboard\Services\ReportPdfService;
use Defyn\Dashboard\Services\ReportService;
use Defyn\Dashboard\Services\ReportsRepository;
use Defyn\Dashboard\Services\ReportStorage;
use Defyn\Dashboard\Services\SitesRepository;

final class GenerateReport
{
    public const HOOK = 'defyn_generate_report';

    public function __construct(
        private readonly ?ReportsRepository $reports = null,
        private readonly ?ReportStorage $storage = null,
        private readonly ?ReportPdfService $pdf = null,
    ) {
    }

    public function handle(int $reportId): void
    {
        $reports = $this->reports ?? new ReportsRepository();

        $row = $this->loadAnyRow($reportId);
        if ($row === null) {
            return; // deleted before the job ran — no-op
        }
        $siteId = (int) $row['site_id'];
        $site   = (new SitesRepository())->findById($siteId);
        if ($site === null) {
            $reports->markFailed($reportId, 'Owning site no longer exists.');
            return;
        }

        try {
            $payload  = (new ReportService())->compose($siteId, $site->ownerUserId, $row['range_from'] . ' 00:00:00', $row['range_to'] . ' 23:59:59');
            $branding = (new BrandingService())->get($site->ownerUserId);
            $bytes    = ($this->pdf ?? new ReportPdfService())->render($payload, $branding);
            $stored   = ($this->storage ?? new ReportStorage())->store($reportId, $bytes);
            $reports->markReady($reportId, $stored['file_name'], $stored['size'], gmdate('Y-m-d H:i:s'));
            (new ActivityLogger())->log($site->ownerUserId, $siteId, 'report.generated',
                ['report_id' => $reportId, 'range_from' => $row['range_from'], 'range_to' => $row['range_to']]);
        } catch (\Throwable $e) {
            $reports->markFailed($reportId, $e->getMessage());
            (new ActivityLogger())->log($site->ownerUserId, $siteId, 'report.generation_failed',
                ['report_id' => $reportId, 'error' => $e->getMessage()]);
        }
    }

    /** @return array<string,mixed>|null raw row — need site_id before ownership scoping (system context). */
    private function loadAnyRow(int $reportId): ?array
    {
        global $wpdb;
        $t = ReportsTable::tableName();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id = %d", $reportId), ARRAY_A);
        return $row ?: null;
    }
}
```
(VERIFY `SitesRepository::findById(int): ?Site` + `Site->ownerUserId` exist. If only `findByIdForUser` exists, add a minimal owner-agnostic `findById` to `SitesRepository` mirroring its single-row read — the monthly system job needs it. Confirm the Site owner accessor name (`ownerUserId` vs `owner_user_id`).)

- [ ] **Step 4: Run green** → PASS. Full suite → only `UninstallTest`. **Step 5: Commit** `feat(p5-3): GenerateReport job (render→store→markReady; failure→markFailed)`.

---

## Task 7: `Jobs\GenerateMonthlyReportsAll` (recurring fan-out)

**Files:**
- Create: `packages/dashboard-plugin/src/Jobs/GenerateMonthlyReportsAll.php`
- Modify: `packages/dashboard-plugin/src/Jobs/Scheduler.php` (add the monthly cadence)
- Test: `packages/dashboard-plugin/tests/Integration/Jobs/GenerateMonthlyReportsAllTest.php`

- [ ] **Step 1: Write the failing test** (seed 1 site; run handle; assert a report row exists for the previous calendar month + dedup on a second run). Same `setUp` purge + `seedSite` as Task 6:

```php
public function testCreatesPreviousMonthRowOncePerSite(): void
{
    $siteId = $this->seedSite();
    [$from, $to] = GenerateMonthlyReportsAll::previousMonthRange(gmdate('Y-m-d'));
    $repo = new ReportsRepository();
    self::assertFalse($repo->existsForSiteAndMonth($siteId, $from, $to));
    (new GenerateMonthlyReportsAll())->handle();
    self::assertTrue($repo->existsForSiteAndMonth($siteId, $from, $to));
    self::assertSame(1, $repo->countForSite($siteId));
    (new GenerateMonthlyReportsAll())->handle(); // dedup
    self::assertSame(1, $repo->countForSite($siteId));
}

public function testPreviousMonthRangeIsCalendarMonth(): void
{
    self::assertSame(['2026-05-01', '2026-05-31'], GenerateMonthlyReportsAll::previousMonthRange('2026-06-16'));
    self::assertSame(['2026-01-01', '2026-01-31'], GenerateMonthlyReportsAll::previousMonthRange('2026-02-10'));
    self::assertSame(['2025-12-01', '2025-12-31'], GenerateMonthlyReportsAll::previousMonthRange('2026-01-05'));
}
```
(The seeded site must be schedulable — status `active`. `as_enqueue_async_action` is a no-op without AS loaded but row creation still runs; the test asserts row creation, not the enqueue.)

- [ ] **Step 2: Run red** → FAIL. **Step 3: Create `src/Jobs/GenerateMonthlyReportsAll.php`** (mirror `SecurityScanAll` fan-out; expose a pure static `previousMonthRange` for deterministic testing):

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Services\ReportsRepository;
use Defyn\Dashboard\Services\SitesRepository;

final class GenerateMonthlyReportsAll
{
    public const HOOK = 'defyn_generate_monthly_reports_all';

    public function __construct(
        private readonly ?SitesRepository $sites = null,
        private readonly ?ReportsRepository $reports = null,
    ) {
    }

    /** @return array{0:string,1:string} [first-day, last-day] of the calendar month before $today (UTC). */
    public static function previousMonthRange(string $today): array
    {
        $thisFirst = gmdate('Y-m-01', strtotime($today . ' UTC'));
        $prevLast  = gmdate('Y-m-d', strtotime($thisFirst . ' UTC') - 86400);
        $prevFirst = gmdate('Y-m-01', strtotime($prevLast . ' UTC'));
        return [$prevFirst, $prevLast];
    }

    public function handle(): void
    {
        $sites   = $this->sites ?? new SitesRepository();
        $reports = $this->reports ?? new ReportsRepository();
        [$from, $to] = self::previousMonthRange(gmdate('Y-m-d'));
        $now = gmdate('Y-m-d H:i:s');

        foreach ($sites->findAllSchedulable() as $siteId) {
            if ($reports->existsForSiteAndMonth($siteId, $from, $to)) {
                continue;
            }
            $reportId = $reports->create($siteId, 'Website Maintenance Report', $from, $to, $now);
            if (function_exists('as_enqueue_async_action')) {
                as_enqueue_async_action(GenerateReport::HOOK, [$reportId], 'defyn');
            }
        }
    }
}
```

- [ ] **Step 4: Add the cadence** in `src/Jobs/Scheduler.php` — add to the `SCHEDULES` const map: `GenerateMonthlyReportsAll::HOOK => MONTH_IN_SECONDS,` (and `use` it). `MONTH_IN_SECONDS` = 2592000 (WP core constant).

- [ ] **Step 5: Run green** → PASS. Full suite → only `UninstallTest`. **Step 6: Commit** `feat(p5-3): GenerateMonthlyReportsAll recurring fan-out (prev-month + dedup)`.

---

## Task 8: Wire AS hooks in `Plugin::boot`

**Files:** Modify `packages/dashboard-plugin/src/Plugin.php`.

- [ ] **Step 1:** Add `use Defyn\Dashboard\Jobs\GenerateReport;` + `use Defyn\Dashboard\Jobs\GenerateMonthlyReportsAll;`. After the `SecurityScan::HOOK` `add_action` block, add:
```php
        add_action(GenerateMonthlyReportsAll::HOOK, static function (): void {
            (new GenerateMonthlyReportsAll())->handle();
        });
        add_action(GenerateReport::HOOK, static function (int $reportId): void {
            (new GenerateReport())->handle($reportId);
        });
```
- [ ] **Step 2:** `php -l src/Plugin.php` clean; full suite → only `UninstallTest`. **Step 3: Commit** `feat(p5-3): register GenerateReport + GenerateMonthlyReportsAll AS hooks`.

---

## Task 9: RateLimit buckets

**Files:** Modify `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php`; Test: `packages/dashboard-plugin/tests/Integration/Rest/RateLimitReportsTest.php`.

- [ ] **Step 1: Write the failing test** for the `reportsGenerate` bucket (10/hr). Mirror the existing `RateLimitTest` exactly for how it stubs `_authenticated_user_id` and drives the static; assert the bucket trips 429 after the limit and the error data status is 429. (The point is the counter, not auth.)

- [ ] **Step 2: Run red** → FAIL. **Step 3: Add 6 methods** to `RateLimit.php`, each copied from the `siteReport` method (`RequireAuth::check` first → is_wp_error guard → `$userId`/`$siteId` → `sprintf` key → `get_transient` count → `>= LIMIT` ? 429 : `set_transient(count+1, WINDOW)` → `true`):
  - `reportsGenerate` — `REPORTS_GENERATE_LIMIT=10`, `REPORTS_GENERATE_WINDOW=HOUR_IN_SECONDS`, key `defyn_rl_reportsGenerate_%d_%d`, code `reports.rate_limited`.
  - `reportsList` — `30`, `MINUTE_IN_SECONDS`, key `defyn_rl_reportsList_%d_%d`, code `reports.rate_limited`.
  - `reportsDownload` — `30`, `MINUTE_IN_SECONDS`, key `defyn_rl_reportsDownload_%d_%d`, code `reports.rate_limited`.
  - `reportsSend` — `10`, `HOUR_IN_SECONDS`, key `defyn_rl_reportsSend_%d_%d`, code `reports.rate_limited`.
  - `reportsDelete` — `30`, `HOUR_IN_SECONDS`, key `defyn_rl_reportsDelete_%d_%d`, code `reports.rate_limited`.
  - `clientEmail` — `10`, `HOUR_IN_SECONDS`, key `defyn_rl_clientEmail_%d_%d`, code `sites.rate_limited`.
- [ ] **Step 4: Run green** → PASS. **Step 5: Commit** `feat(p5-3): RateLimit buckets for reports + client-email`.

---

## Task 10: `Site` model + `Site::toJson` + `client_email`

**Files:** Modify `packages/dashboard-plugin/src/Models/Site.php` + the `SitesRepository` hydration; Test: extend the existing Site/SitesRepository test.

- [ ] **Step 1: Write the failing test** — add to the existing `SiteTest`/`SitesRepositoryTest`: a row with `client_email => 'c@acme.test'` round-trips to `->clientEmail` and `toJson()['client_email']`; a null stays null.
- [ ] **Step 2: Run red** → FAIL. **Step 3:** Add `public readonly ?string $clientEmail` to the `Site` constructor (last param, with a default `= null` if other readonly fields have defaults — match the existing constructor style), hydrate in `Site::fromRow` (`isset($row['client_email']) && $row['client_email'] !== null ? (string) $row['client_email'] : null`), and add `'client_email' => $this->clientEmail,` to `Site::toJson()`. If `SitesRepository` selects explicit columns rather than `SELECT *`, add `client_email`.
- [ ] **Step 4: Run green** → PASS. **Step 5: Commit** `feat(p5-3): Site model exposes client_email`.

---

## Task 11: `POST /sites/{id}/reports` (create + enqueue)

**Files:** Create `src/Rest/SitesReportsController.php` (`handleCreate` here; `handleList` in Task 12); Test `tests/Integration/Rest/SitesReportsCreateTest.php`.

- [ ] **Step 1: Write the failing test** (mirror `SitesReportPdfTest`'s auth/dispatch + seedSite/purge). Cover: non-owned → 404 `sites.not_found`; bad range `?from=2026-06-15&to=2026-05-01` → 400 `report.invalid_range`; happy → 202 + a `generating` row in `defyn_reports`.

- [ ] **Step 2: Run red** → FAIL. **Step 3: Create the controller** (`use` ErrorResponse, ReportRange, InvalidReportRange, ReportsRepository, SitesRepository, GenerateReport):
```php
    public function handleCreate(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');
        if ((new SitesRepository())->findByIdForUser($siteId, $userId) === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }
        try {
            $range = ReportRange::resolve(
                is_string($request->get_param('from')) ? $request->get_param('from') : null,
                is_string($request->get_param('to')) ? $request->get_param('to') : null,
            );
        } catch (InvalidReportRange $e) {
            return ErrorResponse::create(400, $e->code, $e->getMessage());
        }
        $repo = new ReportsRepository();
        $id = $repo->create($siteId, 'Website Maintenance Report', $range['from_date'], $range['to_date'], gmdate('Y-m-d H:i:s'));
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(GenerateReport::HOOK, [$id], 'defyn');
        }
        return new WP_REST_Response(['data' => ['report' => $repo->findByIdForSite($id, $siteId)->toJson()], 'error' => null], 202);
    }
```
- [ ] **Step 4: Run green** → PASS. **Step 5: Commit** `feat(p5-3): POST /sites/{id}/reports (create + enqueue, 202)`.

---

## Task 12: `GET /sites/{id}/reports` (paginated list)

**Files:** Modify `src/Rest/SitesReportsController.php` (`handleList`); Test `tests/Integration/Rest/SitesReportsListTest.php`.

- [ ] **Step 1: Write the failing test** — owned site with 2 created reports → 200, `data.total === 2`, `data.reports` length 2 newest-first, each item has NO `file_name`; non-owned → 404 `sites.not_found`.
- [ ] **Step 2: Run red** → FAIL. **Step 3: Implement `handleList`:**
```php
    public function handleList(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');
        if ((new SitesRepository())->findByIdForUser($siteId, $userId) === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }
        $perPage = 20;
        $page = max(1, (int) $request->get_param('page'));
        $repo = new ReportsRepository();
        $reports = array_map(static fn ($r) => $r->toJson(), $repo->findForSite($siteId, $perPage, ($page - 1) * $perPage));
        return new WP_REST_Response(['data' => [
            'reports' => $reports, 'total' => $repo->countForSite($siteId), 'page' => $page, 'per_page' => $perPage,
        ], 'error' => null], 200);
    }
```
- [ ] **Step 4: Run green** → PASS. **Step 5: Commit** `feat(p5-3): GET /sites/{id}/reports (paginated list, no file_name leak)`.

---

## Task 13: `GET /sites/{id}/reports/{rid}/download` (binary emit seam)

**Files:** Create `src/Rest/SitesReportDownloadController.php` (declare `class …` — NOT `final` — subclassed in the success test); Test `tests/Integration/Rest/SitesReportDownloadTest.php`.

- [ ] **Step 1: Write the failing test** — mirror `SitesReportPdfTest`: error branches via `rest_do_request` (non-owned 404 `sites.not_found`; a `generating` report → 409 `reports.not_ready`); success via a controller SUBCLASS overriding `emit()` to capture bytes, asserting `%PDF-` + a `.pdf` filename. Seed a `ready` report by `create` → `ReportStorage::store('%PDF-…')` → `markReady`.
- [ ] **Step 2: Run red** → FAIL. **Step 3: Create the controller** (reuse the P5.2 `emit()` seam verbatim; read via `ReportStorage`):
```php
final class SitesReportDownloadController  // DECLARE AS `class SitesReportDownloadController` — NOT final (test seam)
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');
        $rid    = (int) $request->get_param('rid');
        $site = (new SitesRepository())->findByIdForUser($siteId, $userId);
        if ($site === null) { return ErrorResponse::create(404, 'sites.not_found', 'Site not found.'); }
        $report = (new ReportsRepository())->findByIdForSite($rid, $siteId);
        if ($report === null) { return ErrorResponse::create(404, 'reports.not_found', 'Report not found.'); }
        if (!in_array($report->status, ['ready', 'sent'], true) || $report->fileName === null) {
            return ErrorResponse::create(409, 'reports.not_ready', 'Report is not ready to download.');
        }
        $bytes = (new ReportStorage())->read($report->fileName);
        if ($bytes === null) { return ErrorResponse::create(409, 'reports.not_ready', 'Report file is missing.'); }
        $host = preg_replace('/[^a-z0-9.-]+/i', '-', (string) parse_url($site->url, PHP_URL_HOST)) ?: 'site';
        $this->emit($bytes, "Website-Maintenance-Report-{$host}-{$report->rangeFrom}-to-{$report->rangeTo}.pdf");
        return new WP_REST_Response(null, 200);
    }

    protected function emit(string $pdf, string $filename): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($pdf));
        }
        echo $pdf; exit;
    }
}
```
(Change the declaration to `class SitesReportDownloadController` — NOT `final`.)
- [ ] **Step 4: Run green** → PASS. **Step 5: Commit** `feat(p5-3): GET report download (authed emit seam, 409 not-ready)`.

---

## Task 14: `POST /sites/{id}/reports/{rid}/send`

**Files:** Create `src/Rest/SitesReportSendController.php` (`__construct(?ReportMailer $mailer = null)`); Test `tests/Integration/Rest/SitesReportSendTest.php`.

- [ ] **Step 1: Write the failing test** — non-owned 404; `generating` report → 400 `reports.not_sendable`; bad email → 400 `reports.invalid_recipient`; happy (a `ready` report + injected mailer returning true) → 200, status `sent`, a `report.sent` activity row; injected mailer false → 502 `reports.send_failed`, status stays `ready`. Build the controller with the injected mailer for the success/failure cases; the error cases can use `rest_do_request` (mailer never reached).
- [ ] **Step 2: Run red** → FAIL. **Step 3: Create the controller:**
```php
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');
        $rid    = (int) $request->get_param('rid');
        $body   = $request->get_json_params() ?: [];
        $site = (new SitesRepository())->findByIdForUser($siteId, $userId);
        if ($site === null) { return ErrorResponse::create(404, 'sites.not_found', 'Site not found.'); }
        $reports = new ReportsRepository();
        $report = $reports->findByIdForSite($rid, $siteId);
        if ($report === null) { return ErrorResponse::create(404, 'reports.not_found', 'Report not found.'); }
        if (!in_array($report->status, ['ready', 'sent'], true) || $report->fileName === null) {
            return ErrorResponse::create(400, 'reports.not_sendable', 'Report is not ready to send.');
        }
        $to = is_string($body['recipient_email'] ?? null) ? trim((string) $body['recipient_email']) : '';
        if (!is_email($to)) { return ErrorResponse::create(400, 'reports.invalid_recipient', 'A valid recipient email is required.'); }
        $note = is_string($body['note'] ?? null) ? trim((string) $body['note']) : '';

        $branding = (new BrandingService())->get($userId);
        $agency   = $branding['agency_name'];
        $host     = esc_html((string) parse_url($site->url, PHP_URL_HOST));
        $subject  = "{$agency} — Website Maintenance Report ({$report->rangeFrom} – {$report->rangeTo})";
        $intro    = $note !== '' ? '<p>' . esc_html($note) . '</p>' : '';
        $bodyHtml = $intro . '<p>Please find attached the website maintenance report for ' . $host
            . ', covering ' . esc_html($report->rangeFrom) . ' – ' . esc_html($report->rangeTo) . '.</p>'
            . '<p>— ' . esc_html($agency) . '</p>';

        $path = (new ReportStorage())->path($report->fileName);
        $ok = ($this->mailer ?? new ReportMailer())->send($to, $subject, $bodyHtml, $path);
        if (!$ok) { return ErrorResponse::create(502, 'reports.send_failed', 'The email could not be sent. Please try again.'); }

        $reports->markSent($rid, $to, gmdate('Y-m-d H:i:s'));
        (new ActivityLogger())->log($userId, $siteId, 'report.sent', ['report_id' => $rid, 'recipient' => $to]);
        return new WP_REST_Response(['data' => ['report' => $reports->findByIdForSite($rid, $siteId)->toJson()], 'error' => null], 200);
    }
```
- [ ] **Step 4: Run green** → PASS. **Step 5: Commit** `feat(p5-3): POST report send (wp_mail + attachment, markSent, 502 on failure)`.

---

## Task 15: `DELETE /sites/{id}/reports/{rid}`

**Files:** Create `src/Rest/SitesReportDeleteController.php`; Test `tests/Integration/Rest/SitesReportDeleteTest.php`.

- [ ] **Step 1: Write the failing test** — non-owned 404 `sites.not_found`; report 404 `reports.not_found`; happy delete of a `ready` report (with a stored file) → 200 `{deleted:true}`, the row is gone, the file is gone, a `report.deleted` activity row exists.
- [ ] **Step 2: Run red** → FAIL. **Step 3: Create the controller:**
```php
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');
        $rid    = (int) $request->get_param('rid');
        if ((new SitesRepository())->findByIdForUser($siteId, $userId) === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }
        $repo = new ReportsRepository();
        $report = $repo->findByIdForSite($rid, $siteId);
        if ($report === null) { return ErrorResponse::create(404, 'reports.not_found', 'Report not found.'); }
        if ($report->fileName !== null) { (new ReportStorage())->delete($report->fileName); }
        $repo->delete($rid);
        (new ActivityLogger())->log($userId, $siteId, 'report.deleted', ['report_id' => $rid]);
        return new WP_REST_Response(['data' => ['deleted' => true], 'error' => null], 200);
    }
```
- [ ] **Step 4: Run green** → PASS. **Step 5: Commit** `feat(p5-3): DELETE report (file + row)`.

---

## Task 16: `POST /sites/{id}/client-email`

**Files:** Create `src/Rest/SitesClientEmailController.php`; Modify `src/Services/SitesRepository.php` (`setClientEmail`); Test `tests/Integration/Rest/SitesClientEmailTest.php`.

- [ ] **Step 1: Write the failing test** — non-owned 404; invalid email `'nope'` → 400 `sites.invalid_client_email`; valid → 200 `{client_email:'c@acme.test'}` + the column is set; empty string clears it → 200 `{client_email:null}`.
- [ ] **Step 2: Run red** → FAIL. **Step 3:** Add `SitesRepository::setClientEmail(int $siteId, ?string $email): void` (`$wpdb->update(SitesTable::tableName(), ['client_email' => $email], ['id' => $siteId])`). Create the controller (mirror the P3.3 mute setting controller shape):
```php
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');
        $body   = $request->get_json_params() ?: [];
        $sites = new SitesRepository();
        if ($sites->findByIdForUser($siteId, $userId) === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }
        $raw = is_string($body['client_email'] ?? null) ? trim((string) $body['client_email']) : '';
        if ($raw !== '' && !is_email($raw)) {
            return ErrorResponse::create(400, 'sites.invalid_client_email', 'A valid email or empty value is required.');
        }
        $sites->setClientEmail($siteId, $raw === '' ? null : $raw);
        return new WP_REST_Response(['data' => ['client_email' => $raw === '' ? null : $raw], 'error' => null], 200);
    }
```
- [ ] **Step 4: Run green** → PASS. **Step 5: Commit** `feat(p5-3): POST /sites/{id}/client-email (per-site recipient)`.

---

## Task 17: RestRouter registration + CORS tests

**Files:** Modify `src/Rest/RestRouter.php`; Create `tests/Integration/Rest/ReportsCorsTest.php` (copy `SitesReportPdfCorsTest.php`).

- [ ] **Step 1:** Register the routes after the existing `/sites/(?P<id>\d+)/report\.pdf` block (add `use` imports for the 5 new controllers). Use TWO separate `register_rest_route` calls for the GET-list and POST-create on `/reports` if the codebase doesn't use the multi-endpoint array form elsewhere — **VERIFY against existing registrations**:
```php
        register_rest_route(self::NAMESPACE, '/sites/(?P<id>\d+)/reports', [
            'methods' => 'GET', 'callback' => [new SitesReportsController(), 'handleList'],
            'permission_callback' => [RateLimit::class, 'reportsList'],
        ]);
        register_rest_route(self::NAMESPACE, '/sites/(?P<id>\d+)/reports', [
            'methods' => 'POST', 'callback' => [new SitesReportsController(), 'handleCreate'],
            'permission_callback' => [RateLimit::class, 'reportsGenerate'],
        ]);
        register_rest_route(self::NAMESPACE, '/sites/(?P<id>\d+)/reports/(?P<rid>\d+)/download', [
            'methods' => 'GET', 'callback' => [new SitesReportDownloadController(), 'handle'],
            'permission_callback' => [RateLimit::class, 'reportsDownload'],
        ]);
        register_rest_route(self::NAMESPACE, '/sites/(?P<id>\d+)/reports/(?P<rid>\d+)/send', [
            'methods' => 'POST', 'callback' => [new SitesReportSendController(), 'handle'],
            'permission_callback' => [RateLimit::class, 'reportsSend'],
        ]);
        register_rest_route(self::NAMESPACE, '/sites/(?P<id>\d+)/reports/(?P<rid>\d+)', [
            'methods' => 'DELETE', 'callback' => [new SitesReportDeleteController(), 'handle'],
            'permission_callback' => [RateLimit::class, 'reportsDelete'],
        ]);
        register_rest_route(self::NAMESPACE, '/sites/(?P<id>\d+)/client-email', [
            'methods' => 'POST', 'callback' => [new SitesClientEmailController(), 'handle'],
            'permission_callback' => [RateLimit::class, 'clientEmail'],
        ]);
```
- [ ] **Step 2: CORS test** — copy `SitesReportPdfCorsTest.php` → `ReportsCorsTest.php`, asserting `Cors::apply` adds Allow-Origin for `GET`/`POST` `/defyn/v1/sites/1/reports`, `GET …/reports/1/download`, `POST …/reports/1/send`, `DELETE …/reports/1`, `POST …/client-email`.
- [ ] **Step 3: Run green** — `composer test:integration -- --filter "Reports|SitesReport|SitesClientEmail"` PASS. Full suite → only `UninstallTest`. **Step 4: Commit** `feat(p5-3): register report routes + CORS`.

---

## Task 18: Uninstaller drops table + wipes the file dir

**Files:** Modify `src/Uninstaller.php`.

- [ ] **Step 1:** Confirm the Uninstaller drops every `Activation::TABLES` entry generically (it iterates `TABLES` → `DROP TABLE`) — `ReportsTable` is now in `TABLES` so the table drop is automatic. **Add** a recursive wipe of the uploads dir (place it alongside the existing table-drop loop):
```php
        // P5.3 — remove stored report PDFs.
        $up = wp_upload_dir();
        $dir = rtrim($up['basedir'], '/') . '/defyn-reports';
        if (is_dir($dir)) {
            foreach ((array) glob($dir . '/*') as $f) { @unlink($f); }
            @rmdir($dir);
        }
```
- [ ] **Step 2:** Full suite → `UninstallTest` remains the single tolerated carry-forward (its TEMPORARY-TABLE infra limitation is unchanged). **Step 3: Commit** `feat(p5-3): uninstaller wipes defyn-reports dir`.

---

## Task 19: Dashboard v0.19.0 bump

**Files:** Modify `packages/dashboard-plugin/defyn-dashboard.php`.

- [ ] **Step 1:** line 6 `Version: 0.18.0` → `0.19.0`; line 46 `define('DEFYN_DASHBOARD_VERSION', '0.18.0')` → `'0.19.0'`.
- [ ] **Step 2:** `grep -rn "0\.18\.0" packages/dashboard-plugin/src packages/dashboard-plugin/tests` → no matches; `grep -n "0.19.0" packages/dashboard-plugin/defyn-dashboard.php` → 2 lines. **Step 3: Commit** `chore(p5-3): bump dashboard plugin to v0.19.0`.

---

## Task 20: SPA — Zod schemas + MSW

**Files:** Modify `apps/web/src/types/api.ts` (`siteSchema` + new report schemas); the MSW handler file `apps/web/src/test/handlers.ts`; Test `apps/web/tests/types/reports.test.ts`.

- [ ] **Step 1: Extend `siteSchema`** — add `client_email: z.string().nullable().optional()` (additive; existing site fixtures stay valid). Add report schemas:
```ts
export const reportStatusSchema = z.enum(['generating', 'ready', 'failed', 'sent']);
export const reportSchema = z.object({
  id: z.number(), site_id: z.number(), title: z.string(),
  range_from: z.string(), range_to: z.string(), status: reportStatusSchema,
  file_size: z.number().nullable(), recipient_email: z.string().nullable(),
  generated_at: z.string().nullable(), sent_at: z.string().nullable(), created_at: z.string(),
});
export type Report = z.infer<typeof reportSchema>;
export const reportsResponseSchema = z.object({
  data: z.object({ reports: z.array(reportSchema), total: z.number(), page: z.number(), per_page: z.number() }),
  error: z.null(),
});
```
- [ ] **Step 2:** Add MSW handlers in `src/test/handlers.ts`: `GET /sites/:id/reports` → `{data:{reports:[…], total, page:1, per_page:20}, error:null}`; `POST /sites/:id/reports` → 202 `{data:{report:{…status:'generating'}}, error:null}`; `POST /sites/:id/reports/:rid/send` → `{data:{report:{…status:'sent'}}, error:null}`; `DELETE /sites/:id/reports/:rid` → `{data:{deleted:true}, error:null}`; `POST /sites/:id/client-email` → `{data:{client_email}, error:null}`. **If any existing site fixture is `siteSchema.parse`d, no change needed (client_email optional).**
- [ ] **Step 3: Test** `tests/types/reports.test.ts` — `reportSchema.parse` accepts a ready report and a generating report (null file_size); `reportStatusSchema` rejects `'bogus'`.
- [ ] **Step 4:** `pnpm test -- --run reports` PASS; full `pnpm test -- --run` → only the 4 carry-forwards. **Step 5: Commit** `feat(p5-3): SPA report Zod schemas + client_email + MSW`.

---

## Task 21: SPA — query + mutation hooks

**Files:** Create `apps/web/src/lib/queries/useSiteReports.ts`, `apps/web/src/lib/mutations/{useGenerateReport,useSendReport,useDeleteReport,useSetClientEmail}.ts`, `apps/web/src/lib/downloadStoredReport.ts`; Test `apps/web/tests/useSiteReports.test.tsx`.

- [ ] **Step 1: Write the failing test** — `useSiteReports(1)`: with a `generating` report in the MSW list, `refetchInterval` resolves to 5000; with all terminal, resolves to `false`. Assert by calling the `refetchInterval` fn with a fake query whose `state.data` is the parsed payload (mirror the P2.9 `useJobsList` test's refetchInterval assertion).
- [ ] **Step 2: Run red** → FAIL. **Step 3: Implement** (`apiClient.get` returns the raw `{data,error}` envelope — parse with `reportsResponseSchema`):
```ts
export function useSiteReports(siteId: number) {
  return useQuery({
    queryKey: ['siteReports', siteId],
    queryFn: async () => reportsResponseSchema.parse(await apiClient.get(`/sites/${siteId}/reports`)).data,
    refetchInterval: (query) => {
      const reports = query.state.data?.reports ?? [];
      return reports.some((r) => r.status === 'generating') ? 5000 : false;
    },
  });
}
```
Mutations (each invalidates `['siteReports', siteId]`; `useSetClientEmail` ALSO invalidates `['site', siteId]`):
- `useGenerateReport(siteId)` → `apiClient.post(/sites/${siteId}/reports, {from, to})`.
- `useSendReport(siteId)` → `apiClient.post(/sites/${siteId}/reports/${rid}/send, {recipient_email, note})`.
- `useDeleteReport(siteId)` → `apiClient.delete(/sites/${siteId}/reports/${rid})`.
- `useSetClientEmail(siteId)` → `apiClient.post(/sites/${siteId}/client-email, {client_email})`.
`downloadStoredReport.ts` mirrors P5.2 `downloadReportPdf`: `apiClient.getBlob(/sites/${siteId}/reports/${rid}/download)` → object-URL `<a download={filename}>` trigger (filename e.g. `report-${rid}.pdf`).
- [ ] **Step 4: Run green** → PASS; full suite → only the 4 carry-forwards. **Step 5: Commit** `feat(p5-3): SPA report hooks (poll-while-generating + mutations + blob download)`.

---

## Task 22: SPA — `SiteReportsPanel` + dialogs + badge + mount

**Files:** Create `apps/web/src/components/reports/{ReportStatusBadge,GenerateReportDialog,SendReportDialog,DeleteReportDialog,SiteReportsPanel}.tsx`; Modify `apps/web/src/routes/SiteDetail.tsx`; Test `apps/web/tests/SiteReportsPanel.test.tsx`.

- [ ] **Step 1: Write the failing test** — render `SiteReportsPanel` (mock `useSiteReports` with one report of each status + spy the 4 mutations + `downloadStoredReport`). Assert: a generating row shows the "Generating" badge + disabled Download/Send; a ready row's Send opens `SendReportDialog` pre-filled from the passed `clientEmail`; confirming Send calls the send mutation with the recipient; an invalid recipient (`'nope'`) shows a client error + does NOT call send; Delete opens the neutral dialog → calls delete; Generate opens `GenerateReportDialog` and confirming calls generate with the chosen range. **NO `useEffect` keyed on an object** — the recipient seed keys on the primitive `clientEmail` string (P2.10 guard) or uses lazy `useState(() => clientEmail ?? '')`.
- [ ] **Step 2: Run red** → FAIL. **Step 3: Implement** (mirror existing panels/dialogs — `SiteSecurityPanel` for the table shell, the neutral `ConfirmSyncAllDialog`/gate-dialog style for Send/Delete, `reportRange` presets for the generate dialog). `ReportStatusBadge` maps status → color (generating=amber, ready=blue, sent=green, failed=red) using existing badge classes. `SendReportDialog` validates with `/^[^@\s]+@[^@\s]+\.[^@\s]+$/`. Mount `<SiteReportsPanel siteId={siteId} clientEmail={site.client_email ?? null} />` in `SiteDetail.tsx` below the existing panels (keep the P5.1 "Report" preview button).
- [ ] **Step 4: Run green** → `pnpm test -- --run SiteReportsPanel` PASS; full `pnpm test -- --run` → only 4 carry-forwards (NO hang — a hang = an object-keyed effect; fix to primitives). `pnpm build` clean (tsc passes). **Step 5: Commit** `feat(p5-3): SiteReportsPanel + generate/send/delete dialogs + status badge`.

---

## Task 23: Release — build, ship, smoke, tag, MEMORY

**Files:** build artifacts only.

- [ ] **Step 1:** Full PHP suite → only `UninstallTest`. **Step 2:** Full SPA suite (Node 22) → only the 4 carry-forwards; `cd apps/web && pnpm build` clean.
- [ ] **Step 3: dompdf-preserving zip** (verify list unchanged from P5.2):
```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && composer install --no-dev --classmap-authoritative
cd "/Users/pradeep/Local Sites/defynWP/packages"
rm -f "/Users/pradeep/Local Sites/defynWP/dist/defyn-dashboard-0.19.0.zip"
zip -rq "/Users/pradeep/Local Sites/defynWP/dist/defyn-dashboard-0.19.0.zip" dashboard-plugin \
  -x 'dashboard-plugin/tests/*' '*wp-tests-config.php' 'dashboard-plugin/.phpunit.result.cache' \
     'dashboard-plugin/test-output.log' 'dashboard-plugin/phpunit.xml' 'dashboard-plugin/composer.lock' \
     'dashboard-plugin/.github/*' 'dashboard-plugin/.gitignore'
cd "/Users/pradeep/Local Sites/defynWP"
unzip -l dist/defyn-dashboard-0.19.0.zip | grep -cE "deprecation-contracts/function\.php|polyfill-php83/bootstrap\.php"  # MUST be 2
unzip -l dist/defyn-dashboard-0.19.0.zip | grep -c "json-machine/src/Items\.php"  # >=1
unzip -l dist/defyn-dashboard-0.19.0.zip | grep -c "dompdf/src/Dompdf\.php"  # >=1
unzip -p dist/defyn-dashboard-0.19.0.zip dashboard-plugin/defyn-dashboard.php | grep -m1 DEFYN_DASHBOARD_VERSION
cd packages/dashboard-plugin && composer install
```
- [ ] **Step 4: Merge + push** `git checkout main && git merge --ff-only p5-3-client-report-queue && git push origin main`.
- [ ] **Step 5: Kinsta install (MANUAL — pause for "installed").** Upload `dist/defyn-dashboard-0.19.0.zip` via "Replace current with uploaded version"; clear MyKinsta cache. Schema v13 self-heals on first load.
- [ ] **Step 6: Indirect curl smoke** (login field `access_token`; backend `defynwp.defyn.agency`): `GET /sites/1/reports` no-auth → 401; auth `GET /sites/999999/reports` → 404 `sites.not_found`; `POST /sites/999999/reports` auth → 404; `POST /sites/999999/client-email` auth invalid email → 404 (ownership-first); happy paths foreclosed by zero-sites prod (covered by green tests); verify the deployed SPA bundle contains 'Generate report' / 'Send report' / 'Client email'.
- [ ] **Step 7: Tag** `git tag p5-3-client-report-queue-complete && git push origin p5-3-client-report-queue-complete`.
- [ ] **Step 8: MEMORY** — append a P5.3-complete entry to `project_defyn_roadmap.md` (v0.19.0, tag, schema v13, the report queue + monthly fan-out + private storage + send/delete, smoke results) and refresh `MEMORY.md`. **Set: Reporting phase CLOSED — the entire locked roadmap (Monitoring → Security → Reporting) is DONE; NO Backups.**

---

## Self-Review (completed during planning)

- **Spec coverage:** data model v13 → Task 1; Report DTO → Task 2; ReportsRepository → Task 3; ReportStorage → Task 4; ReportMailer → Task 5; GenerateReport → Task 6; GenerateMonthlyReportsAll + self-heal cadence → Tasks 7 + 1(maybeRunSelfHeal) + 8; RateLimit → Task 9; Site.client_email → Task 10; the 6 endpoints → Tasks 11–16; routes + CORS → Task 17; uninstall → Task 18; version → Task 19; SPA schema/hooks/components → Tasks 20–22; release → Task 23. ✅
- **Type consistency:** `Report` field names (`fileName`, `fileSize`, `rangeFrom`, `rangeTo`, `recipientEmail`, `generatedAt`, `sentAt`, `createdAt`) consistent across `fromRow`/`toJson`/repo/controllers; status enum `generating|ready|failed|sent` consistent (PHP + Zod). `ReportsRepository` methods (`create/findForSite/countForSite/findByIdForSite/markReady/markFailed/markSent/delete/existsForSiteAndMonth`) used identically across Tasks 6/7/11–16. `GenerateReport::HOOK`/`GenerateMonthlyReportsAll::HOOK` consistent across Tasks 1/7/8/11. Error codes (`sites.not_found`, `reports.not_found`, `reports.not_ready`, `reports.not_sendable`, `reports.invalid_recipient`, `reports.send_failed`, `sites.invalid_client_email`, `report.invalid_range`) consistent. ✅
- **Open verifications flagged inline** (cheap grep-confirms, not blockers): `SchemaTable` interface method names; `SitesRepository::findById`/`Site->ownerUserId` accessors (add a minimal `findById` if absent — monthly system job needs owner-agnostic lookup); the multi-vs-single `register_rest_route` form; how `RateLimitTest` stubs auth for the static-call bucket test; the `ReportPdfService` `final` drop (Task 6, mirrors the P5.2 `SitesReportPdfController` precedent).
- **No-placeholder scan:** every code step carries complete code; no TBD / "similar to Task N". ✅
```
