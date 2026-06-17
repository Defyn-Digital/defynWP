# P5.4 — Automated Scheduled Report-Send — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Auto-email a generated branded PDF report to a client when a site is opted-in (per-site toggle) and has a client email — chaining the send into the existing async `GenerateReport` job, with no manual step.

**Architecture:** A per-site `auto_send_reports` boolean (OFF by default) + the existing `client_email` gate which reports auto-send. The email build/send/mark/log path is extracted into one shared `ReportSendService` used by both the refactored manual-send controller (`method='manual'`) and a best-effort block chained after `GenerateReport`'s `markReady` (`method='auto'`). No new job, no new schedule — the monthly `GenerateMonthlyReportsAll` already produces the `ready` reports.

**Tech Stack:** PHP 8.1 (WordPress plugin, PHPUnit/wp-phpunit), Action Scheduler, `wp_mail`; React 18 + TS + TanStack Query v5 + Zod + Vitest + MSW (apps/web, Node 22, pnpm). Connector UNCHANGED (v0.1.7). Dashboard v0.21.0 → v0.22.0. Schema v15 → v16 (two ALTER COLUMNs, no new table).

**Branch:** `p5-4-auto-send-reports` (off `main` @ 1860e8d). Spec: `docs/superpowers/specs/2026-06-17-p5-4-auto-send-reports-design.md`.

**Baselines going in:** PHP 912 pass / 1 fail (only `UninstallTest`). SPA 440 pass / 4 carry-forward (`SiteDetail`×2 + `SiteCoreCard`×2). DB online port 10166. Full PHP: `COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit` (tolerate ONLY `UninstallTest`). SPA: `export FNM_DIR="$HOME/.fnm"; eval "$(fnm env --shell bash)"; fnm use 22` then `pnpm test -- --run`; `pnpm build` must be tsc-clean.

**Guardrail #15 (test isolation):** every test seeding `defyn_sites` must, in `setUp()`, `parent::setUp()` + `Activation::ensureSchema()` + `SET autocommit=1` + `DELETE FROM` the touched tables. Real `defyn_sites` cols: `id,user_id,url,label,status,created_at,updated_at,wp_version,client_email,ga4_property_id` (+ now `auto_send_reports`). `Site` owner = `->userId`, recipient `->clientEmail`, flag `->autoSendReports`. `ActivityLogger` in `Services\`, `log(?userId,?siteId,event,?details,?ip)`.

All paths relative to `packages/dashboard-plugin/` (PHP) or `apps/web/` (SPA).

---

## File Structure

**New (PHP):** `src/Services/ReportSendService.php` (the shared send path); `src/Rest/SitesAutoSendController.php`.
**Modified (PHP):** `src/Activation.php` (v16 + 2 guarded ALTERs), `src/Models/Site.php` (autoSendReports), `src/Services/SitesRepository.php` (setAutoSendReports), `src/Models/Report.php` (sentMethod), `src/Services/ReportsRepository.php` (markSent 4th param), `src/Rest/SitesReportSendController.php` (refactor to ReportSendService), `src/Jobs/GenerateReport.php` (auto-send chain), `src/Rest/Middleware/RateLimit.php` (autoSend bucket), `src/Rest/RestRouter.php` (route), `defyn-dashboard.php` (v0.22.0).
**New (SPA):** `src/lib/mutations/useSetAutoSend.ts`.
**Modified (SPA):** `src/types/api.ts` (site `auto_send_reports` + report `sent_method`), `src/components/reports/SiteReportsPanel.tsx` (toggle), `src/components/reports/ReportStatusBadge.tsx` (Auto-sent variant), `src/routes/SiteDetail.tsx` (wire the prop), `src/test/handlers.ts` (fixtures).

---

## Task 1: Schema v16 — two guarded ALTER columns + version-pin ripple

**Files:** Modify `src/Activation.php`; Test `tests/Integration/Schema/AutoSendSchemaTest.php` + ripple.

- [ ] **Step 1: Edit `src/Activation.php`:**
  - `public const SCHEMA_VERSION = 15;` → `16`.
  - Add two guarded ALTER methods next to the existing `addGa4PropertyColumn` (mirror it exactly):
```php
    private static function addAutoSendReportsColumn(\wpdb $wpdb): void
    {
        $table  = SitesTable::tableName();
        $exists = $wpdb->get_var($wpdb->prepare(
            "SHOW COLUMNS FROM `{$table}` LIKE %s",
            'auto_send_reports'
        ));
        if ($exists !== null) {
            return;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL — column DDL cannot be parameterized.
        $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN auto_send_reports TINYINT(1) NOT NULL DEFAULT 0");
    }

    private static function addSentMethodColumn(\wpdb $wpdb): void
    {
        $table  = ReportsTable::tableName();
        $exists = $wpdb->get_var($wpdb->prepare(
            "SHOW COLUMNS FROM `{$table}` LIKE %s",
            'sent_method'
        ));
        if ($exists !== null) {
            return;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL — column DDL cannot be parameterized.
        $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN sent_method VARCHAR(10) NULL");
    }
```
  (Add `use Defyn\Dashboard\Schema\ReportsTable;` at the top if not already imported — check; `SitesTable` is already imported.)
  - In `ensureSchema()`, right after the existing `self::addGa4PropertyColumn($wpdb);` line (~line 122), add:
```php
        // P5.4 — add auto_send_reports to wp_defyn_sites (per-site auto-send opt-in).
        self::addAutoSendReportsColumn($wpdb);

        // P5.4 — add sent_method to wp_defyn_reports (manual|auto send distinction).
        self::addSentMethodColumn($wpdb);
```

- [ ] **Step 2: Write the schema test** `tests/Integration/Schema/AutoSendSchemaTest.php`:
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Schema;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Schema\ReportsTable;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class AutoSendSchemaTest extends AbstractSchemaTestCase
{
    public function testAutoSendReportsColumnExists(): void
    {
        global $wpdb;
        Activation::ensureSchema();
        $table = SitesTable::tableName();
        self::assertNotNull($wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'auto_send_reports')));
    }

    public function testSentMethodColumnExists(): void
    {
        global $wpdb;
        Activation::ensureSchema();
        $table = ReportsTable::tableName();
        self::assertNotNull($wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", 'sent_method')));
    }

    public function testSchemaVersionIs16(): void
    {
        self::assertSame(16, Activation::SCHEMA_VERSION);
    }
}
```

- [ ] **Step 3: Version-pin ripple.** `grep -rn "assertSame(15, Activation::SCHEMA_VERSION)\|, Activation::SCHEMA_VERSION" tests/ | grep 15` → bump EVERY `15` → `16` across all matching schema test files (P6.2 had 13 across 9 files; also rename any `testSchemaVersionIs15` method to `Is16`). Be thorough — a missed pin fails the suite.

- [ ] **Step 4: Run** `composer test:integration -- --filter "AutoSendSchema|SchemaVersion"` → green. Then full suite `COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit` → tolerate ONLY `UninstallTest`.

- [ ] **Step 5: Commit** — **stage the parent `tests/Integration/` dir** (P5.3 add-path miss):
```bash
cd "/Users/pradeep/Local Sites/defynWP"
git add packages/dashboard-plugin/src/Activation.php packages/dashboard-plugin/tests/Integration/
git commit -m "feat(p5-4): schema v16 — auto_send_reports + sent_method columns"
```

---

## Task 2: `Site.autoSendReports` + `SitesRepository::setAutoSendReports`

**Files:** Modify `src/Models/Site.php`, `src/Services/SitesRepository.php`; Test `tests/Integration/Services/SitesRepositoryAutoSendTest.php`.

- [ ] **Step 1: Write the failing test:**
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SitesRepositoryAutoSendTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Defyn\Dashboard\Activation::ensureSchema();
        global $wpdb;
        $wpdb->query('SET autocommit = 1');
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'defyn_sites');
    }

    public function testSetAndReadAutoSendReports(): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix.'defyn_sites', [
            'user_id'=>1,'url'=>'https://a.test','label'=>'A','status'=>'active','wp_version'=>'6.9',
            'created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s'),
        ]);
        $siteId = (int) $wpdb->insert_id;
        $repo = new SitesRepository();

        self::assertFalse($repo->findById($siteId)->autoSendReports); // DEFAULT 0
        $repo->setAutoSendReports($siteId, true);
        self::assertTrue($repo->findById($siteId)->autoSendReports);
        self::assertTrue($repo->findById($siteId)->toJson()['auto_send_reports']);
        $repo->setAutoSendReports($siteId, false);
        self::assertFalse($repo->findById($siteId)->autoSendReports);
    }
}
```
Run red: `composer test:integration -- --filter SitesRepositoryAutoSendTest` → FAIL.

- [ ] **Step 2: Edit `src/Models/Site.php`:**
  - Add a ctor prop right after `ga4PropertyId` (the current last param):
```php
        // P5.4 — per-site opt-in: auto-email the generated monthly report to client_email.
        public readonly bool $autoSendReports = false,
```
  - In `fromRow()`, after the `ga4PropertyId:` line:
```php
            autoSendReports:         (bool) (int) ($row['auto_send_reports'] ?? 0),
```
  - In `toJson()`, after `'ga4_property_id'`:
```php
            'auto_send_reports'           => $this->autoSendReports,
```

- [ ] **Step 3: Edit `src/Services/SitesRepository.php`** — add beside `setGa4PropertyId`:
```php
    public function setAutoSendReports(int $siteId, bool $on): void
    {
        global $wpdb;
        $wpdb->update(SitesTable::tableName(), ['auto_send_reports' => (int) $on], ['id' => $siteId]);
    }
```

- [ ] **Step 4: Run green** → pass; full suite tolerating only `UninstallTest`. **Step 5: Commit** `feat(p5-4): Site.autoSendReports + SitesRepository::setAutoSendReports`.

---

## Task 3: `Report.sentMethod` + `ReportsRepository::markSent` method param

**Files:** Modify `src/Models/Report.php`, `src/Services/ReportsRepository.php`; Test `tests/Integration/Services/ReportsRepositoryMarkSentTest.php`.

> **PHP gotcha:** `Report`'s ctor params are all required (no defaults) and `createdAt` is the last one. An optional param cannot precede a required one, so `sentMethod` is added at the **very end with a default** (`?string $sentMethod = null`). `Report::fromRow` passes it by name, so position is irrelevant to the only constructor caller.

- [ ] **Step 1: Write the failing test** (seed a report row, markSent with a method, assert sent_method persists + DTO surfaces it):
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Schema\ReportsTable;
use Defyn\Dashboard\Services\ReportsRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class ReportsRepositoryMarkSentTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Defyn\Dashboard\Activation::ensureSchema();
        global $wpdb;
        $wpdb->query('SET autocommit = 1');
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'defyn_reports');
    }

    private function seedReport(): int
    {
        global $wpdb;
        $wpdb->insert(ReportsTable::tableName(), [
            'site_id'=>1,'title'=>'May 2026','range_from'=>'2026-05-01','range_to'=>'2026-05-31',
            'status'=>'ready','file_name'=>'report-1-abc.pdf','file_size'=>1234,
            'created_at'=>gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $wpdb->insert_id;
    }

    public function testMarkSentDefaultsToManual(): void
    {
        $repo = new ReportsRepository();
        $id = $this->seedReport();
        $repo->markSent($id, 'c@acme.com', '2026-06-01 03:00:00');
        $r = $repo->findByIdForSite($id, 1);
        self::assertSame('sent', $r->status);
        self::assertSame('manual', $r->sentMethod);
        self::assertSame('manual', $r->toJson()['sent_method']);
    }

    public function testMarkSentAutoMethod(): void
    {
        $repo = new ReportsRepository();
        $id = $this->seedReport();
        $repo->markSent($id, 'c@acme.com', '2026-06-01 03:00:00', 'auto');
        self::assertSame('auto', $repo->findByIdForSite($id, 1)->sentMethod);
    }
}
```
(If `ReportsTable`'s columns differ — e.g. a NOT-NULL `title` or different names — match the real `wp_defyn_reports` shape; the implementer verifies `ReportsTable::createSql`.) Run red → FAIL.

- [ ] **Step 2: Edit `src/Models/Report.php`:**
  - Add to the ctor, **after `createdAt`** (last param, with default):
```php
        // P5.4 — 'manual' | 'auto' | null (never-sent). How the report was emailed.
        public readonly ?string $sentMethod = null,
```
  - In `fromRow()`, add (anywhere in the named-arg list, e.g. after `sentAt:`):
```php
            sentMethod:     isset($row['sent_method'])     && $row['sent_method']     !== null ? (string) $row['sent_method']     : null,
```
  - In `toJson()`, add after `'sent_at'` (do NOT add `file_name` — stays server-only):
```php
            'sent_method'     => $this->sentMethod,
```

- [ ] **Step 3: Edit `src/Services/ReportsRepository.php`** — extend `markSent`:
```php
    public function markSent(int $id, string $recipient, string $sentAt, string $method = 'manual'): void
    {
        global $wpdb;
        $wpdb->update(ReportsTable::tableName(),
            ['status' => 'sent', 'recipient_email' => $recipient, 'sent_at' => $sentAt, 'sent_method' => $method],
            ['id' => $id]);
    }
```

- [ ] **Step 4: Run green** → pass; full suite tolerating only `UninstallTest`. **Step 5: Commit** `feat(p5-4): Report.sentMethod + markSent method param`.

---

## Task 4: `Services\ReportSendService` (shared send path)

**Files:** Create `src/Services/ReportSendService.php`; Test `tests/Integration/Services/ReportSendServiceTest.php`.

- [ ] **Step 1: Write the failing test** (real chain except wp_mail — inject a `ReportMailer` with a stub sender):
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Models\Report;
use Defyn\Dashboard\Schema\ReportsTable;
use Defyn\Dashboard\Services\ReportMailer;
use Defyn\Dashboard\Services\ReportSendService;
use Defyn\Dashboard\Services\ReportsRepository;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class ReportSendServiceTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Defyn\Dashboard\Activation::ensureSchema();
        global $wpdb;
        $wpdb->query('SET autocommit = 1');
        foreach (['defyn_reports','defyn_sites','defyn_activity_log'] as $t) {
            $wpdb->query('DELETE FROM ' . $wpdb->prefix . $t);
        }
    }

    private function seedSite(): \Defyn\Dashboard\Models\Site
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix.'defyn_sites', [
            'user_id'=>1,'url'=>'https://acme.test','label'=>'Acme','status'=>'active','wp_version'=>'6.9',
            'created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s'),
        ]);
        return (new SitesRepository())->findById((int) $wpdb->insert_id);
    }

    private function seedReport(int $siteId): Report
    {
        global $wpdb;
        $wpdb->insert(ReportsTable::tableName(), [
            'site_id'=>$siteId,'title'=>'May 2026','range_from'=>'2026-05-01','range_to'=>'2026-05-31',
            'status'=>'ready','file_name'=>'report-x.pdf','file_size'=>10,'created_at'=>gmdate('Y-m-d H:i:s'),
        ]);
        return (new ReportsRepository())->findByIdForSite((int) $wpdb->insert_id, $siteId);
    }

    private function service(bool $mailOk): ReportSendService
    {
        return new ReportSendService(new ReportMailer(fn ($to,$s,$b,$h,$a): bool => $mailOk));
    }

    public function testAutoSendMarksSentAndLogsAutoEvent(): void
    {
        $site = $this->seedSite();
        $report = $this->seedReport($site->id);
        $ok = $this->service(true)->send($report, $site, 'c@acme.com', null, 'auto');
        self::assertTrue($ok);
        $r = (new ReportsRepository())->findByIdForSite($report->id, $site->id);
        self::assertSame('sent', $r->status);
        self::assertSame('auto', $r->sentMethod);
        global $wpdb;
        $ev = $wpdb->get_var("SELECT event_type FROM {$wpdb->prefix}defyn_activity_log ORDER BY id DESC LIMIT 1");
        self::assertSame('report.auto_sent', $ev);
    }

    public function testManualSendLogsSentEvent(): void
    {
        $site = $this->seedSite();
        $report = $this->seedReport($site->id);
        $this->service(true)->send($report, $site, 'c@acme.com', 'hi', 'manual');
        global $wpdb;
        $ev = $wpdb->get_var("SELECT event_type FROM {$wpdb->prefix}defyn_activity_log ORDER BY id DESC LIMIT 1");
        self::assertSame('report.sent', $ev);
    }

    public function testFailedMailReturnsFalseAndLeavesStatus(): void
    {
        $site = $this->seedSite();
        $report = $this->seedReport($site->id);
        $ok = $this->service(false)->send($report, $site, 'c@acme.com', null, 'auto');
        self::assertFalse($ok);
        self::assertSame('ready', (new ReportsRepository())->findByIdForSite($report->id, $site->id)->status);
    }
}
```
(Verify the activity-log table's event column name — the explore showed `event_type`; if different, match it.) Run red → FAIL.

- [ ] **Step 2: Create `src/Services/ReportSendService.php`** (copy the EXACT subject/body strings from `SitesReportSendController`):
```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Models\Report;
use Defyn\Dashboard\Models\Site;

/**
 * P5.4 — the single email-build + send + markSent + log path for a stored
 * report PDF. Used by BOTH the manual send controller (method='manual',
 * event report.sent) and the auto-send chain in GenerateReport
 * (method='auto', event report.auto_sent), so the two can't drift. The email
 * body is byte-identical to the P5.3 manual flow it was extracted from.
 *
 * Returns true on wp_mail success (report marked `sent`); false leaves the
 * report's status unchanged so the caller can surface a retry path.
 */
final class ReportSendService
{
    public function __construct(private readonly ?ReportMailer $mailer = null)
    {
    }

    /** @param 'manual'|'auto' $method */
    public function send(Report $report, Site $site, string $to, ?string $note, string $method): bool
    {
        $branding = (new BrandingService())->get($site->userId);
        $agency   = (string) ($branding['agency_name'] ?? 'Defyn Digital');
        $host     = esc_html((string) parse_url($site->url, PHP_URL_HOST));
        $subject  = "{$agency} — Website Maintenance Report ({$report->rangeFrom} – {$report->rangeTo})";
        $intro    = $note !== null && $note !== '' ? '<p>' . esc_html($note) . '</p>' : '';
        $bodyHtml = $intro . '<p>Please find attached the website maintenance report for ' . $host
            . ', covering ' . esc_html($report->rangeFrom) . ' – ' . esc_html($report->rangeTo) . '.</p>'
            . '<p>— ' . esc_html($agency) . '</p>';

        $path = (new ReportStorage())->path((string) $report->fileName);
        $ok   = ($this->mailer ?? new ReportMailer())->send($to, $subject, $bodyHtml, $path);
        if (!$ok) {
            return false;
        }

        (new ReportsRepository())->markSent($report->id, $to, gmdate('Y-m-d H:i:s'), $method);
        (new ActivityLogger())->log(
            $site->userId,
            $site->id,
            $method === 'auto' ? 'report.auto_sent' : 'report.sent',
            ['report_id' => $report->id, 'recipient' => $to]
        );
        return true;
    }
}
```

- [ ] **Step 3: Run green** → all 3 pass. **Step 4: Commit** `feat(p5-4): ReportSendService (shared email-build + send + markSent + log)`.

---

## Task 5: Refactor `SitesReportSendController` onto `ReportSendService`

**Files:** Modify `src/Rest/SitesReportSendController.php`. The existing P5.3 send-controller test (verify its name — likely `tests/Integration/Rest/SitesReportSendTest.php` or `SitesReportSendControllerTest.php`; it injects a `ReportMailer` to force success/failure) MUST stay green.

> The controller keeps its public `__construct(?ReportMailer $mailer = null)` seam (so the existing test's injected mailer still works) and threads that mailer into a `ReportSendService`. All status branches + the `report.sent` event are preserved.

- [ ] **Step 1: Run the existing send-controller test first** to capture the green baseline: `composer test:integration -- --filter <SendControllerTestName>` → PASS.

- [ ] **Step 2: Edit `src/Rest/SitesReportSendController.php`** — replace the inline email-build + send + markSent + log (everything from `$branding = …` through the `markSent`/`log` lines) with a `ReportSendService` call, keeping the 404/400 preflights and the 502/200 mapping:
```php
        // (preflights unchanged: ownership-404, reports.not_found, reports.not_sendable, reports.invalid_recipient)
        $note = is_string($body['note'] ?? null) ? trim((string) $body['note']) : '';

        $ok = (new \Defyn\Dashboard\Services\ReportSendService($this->mailer))
            ->send($report, $site, $to, $note, 'manual');
        if (!$ok) {
            return ErrorResponse::create(502, 'reports.send_failed', 'The email could not be sent. Please try again.');
        }

        return new WP_REST_Response(['data' => ['report' => $reports->findByIdForSite($rid, $siteId)->toJson()], 'error' => null], 200);
```
  Drop the now-unused `use` imports (`BrandingService`, `ReportStorage`, `ActivityLogger`) but KEEP `ReportMailer` (the ctor type-hint) and `ReportsRepository` (the preflight + the 200 re-read). The `$reports = new ReportsRepository()` + `findByIdForSite` preflight stays. `ReportSendService::send` does the markSent internally.

- [ ] **Step 3: Run green** — the existing send-controller test passes UNCHANGED (200/502/400/404, `report.sent` event). Then full suite tolerating only `UninstallTest`. **Step 4: Commit** `refactor(p5-4): SitesReportSendController uses ReportSendService (manual)`.

---

## Task 6: `GenerateReport` auto-send chain

**Files:** Modify `src/Jobs/GenerateReport.php`; Test append to `tests/Integration/Jobs/GenerateReportTest.php`.

- [ ] **Step 1: Append failing tests to `GenerateReportTest.php`** (the file's `setUp` already purges `defyn_reports`/`defyn_sites`/`defyn_activity_log` + ensures schema; `seedSite(id=1)` exists but does NOT set client_email/auto_send — set them via the repo; seed a `generating` report and run `handle`). Drive send via an injected `ReportSendService(new ReportMailer(stub))` so no real mail:
```php
    public function testAutoSendsWhenOptedInWithEmail(): void
    {
        $siteId = $this->seedSite();
        (new \Defyn\Dashboard\Services\SitesRepository())->setClientEmail($siteId, 'c@acme.com');
        (new \Defyn\Dashboard\Services\SitesRepository())->setAutoSendReports($siteId, true);
        $reportId = $this->seedGeneratingReport($siteId); // helper: insert a `generating` row, return id

        $sender = new \Defyn\Dashboard\Services\ReportSendService(new \Defyn\Dashboard\Services\ReportMailer(fn (...$a): bool => true));
        (new \Defyn\Dashboard\Jobs\GenerateReport(null, null, $this->stubPdf(), $sender))->handle($reportId);

        $r = (new \Defyn\Dashboard\Services\ReportsRepository())->findByIdForSite($reportId, $siteId);
        self::assertSame('sent', $r->status);
        self::assertSame('auto', $r->sentMethod);
        global $wpdb;
        self::assertSame('report.auto_sent', $wpdb->get_var("SELECT event_type FROM {$wpdb->prefix}defyn_activity_log WHERE event_type='report.auto_sent' LIMIT 1"));
    }

    public function testSkipsAutoSendWhenNoClientEmail(): void
    {
        $siteId = $this->seedSite();
        (new \Defyn\Dashboard\Services\SitesRepository())->setAutoSendReports($siteId, true); // opted-in, no email
        $reportId = $this->seedGeneratingReport($siteId);
        (new \Defyn\Dashboard\Jobs\GenerateReport(null, null, $this->stubPdf()))->handle($reportId);
        self::assertSame('ready', (new \Defyn\Dashboard\Services\ReportsRepository())->findByIdForSite($reportId, $siteId)->status);
    }

    public function testSkipsAutoSendWhenNotOptedIn(): void
    {
        $siteId = $this->seedSite();
        (new \Defyn\Dashboard\Services\SitesRepository())->setClientEmail($siteId, 'c@acme.com'); // email but toggle OFF
        $reportId = $this->seedGeneratingReport($siteId);
        (new \Defyn\Dashboard\Jobs\GenerateReport(null, null, $this->stubPdf()))->handle($reportId);
        self::assertSame('ready', (new \Defyn\Dashboard\Services\ReportsRepository())->findByIdForSite($reportId, $siteId)->status);
    }

    public function testThrowingSenderLeavesReportReady(): void
    {
        $siteId = $this->seedSite();
        (new \Defyn\Dashboard\Services\SitesRepository())->setClientEmail($siteId, 'c@acme.com');
        (new \Defyn\Dashboard\Services\SitesRepository())->setAutoSendReports($siteId, true);
        $reportId = $this->seedGeneratingReport($siteId);
        $throwingSender = new \Defyn\Dashboard\Services\ReportSendService(new \Defyn\Dashboard\Services\ReportMailer(function (...$a): bool { throw new \RuntimeException('boom'); }));
        (new \Defyn\Dashboard\Jobs\GenerateReport(null, null, $this->stubPdf(), $throwingSender))->handle($reportId); // must NOT throw
        self::assertSame('ready', (new \Defyn\Dashboard\Services\ReportsRepository())->findByIdForSite($reportId, $siteId)->status);
    }
```
  **You must add two test helpers if they don't already exist:** `seedGeneratingReport(int $siteId): int` (insert a `wp_defyn_reports` row with `status='generating'`, `range_from`/`range_to` a real month, return id — match the real `ReportsTable` columns) and `stubPdf()` returning a `ReportPdfService` test double whose `render()` returns `'%PDF-1.4 fake'` (so `store` writes a file + `markReady` runs without real dompdf). **Look at how the EXISTING happy-path `GenerateReportTest` already renders** — it almost certainly already has a pdf stub + a report-seed helper; reuse them. Run red → the 4 new FAIL (no auto-send yet).

- [ ] **Step 2: Edit `src/Jobs/GenerateReport.php`:**
  - Add `use Defyn\Dashboard\Services\ReportSendService;` to the imports.
  - Add a 4th ctor dep:
```php
    public function __construct(
        private readonly ?ReportsRepository $reports = null,
        private readonly ?ReportStorage $storage = null,
        private readonly ?ReportPdfService $pdf = null,
        private readonly ?ReportSendService $sender = null,
    ) {
    }
```
  - At the END of `handle()`, AFTER the existing `try { … } catch { … }` block, add the best-effort auto-send (`$site` and `$siteId` are still in scope; `$reports` too):
```php
        // P5.4 — best-effort auto-send. Generation is already committed above; a
        // send failure (or throw) must never undo it — the report stays `ready`
        // for manual retry. Only fires when the site is opted-in AND has a valid
        // client email AND the report actually reached `ready`.
        $report = $reports->findByIdForSite($reportId, $siteId);
        if ($report !== null
            && $report->status === 'ready'
            && $site->autoSendReports
            && is_email((string) $site->clientEmail)) {
            try {
                ($this->sender ?? new ReportSendService())->send($report, $site, (string) $site->clientEmail, null, 'auto');
            } catch (\Throwable $e) {
                // swallow — the report stays `ready`; operator can send manually.
            }
        }
```

- [ ] **Step 3: Run green** `composer test:integration -- --filter GenerateReportTest` → ALL pass (existing happy/failure cases + the 4 new). Then full suite tolerating only `UninstallTest`. The existing generate-success test's site is NOT opted-in (default 0) and has no client_email, so it does NOT auto-send — confirm it stays green. **Step 4: Commit** `feat(p5-4): GenerateReport auto-send chain (best-effort, never throws)`.

---

## Task 7: `RateLimit::autoSend` bucket

**Files:** Modify `src/Rest/Middleware/RateLimit.php`; Test `tests/Integration/Rest/RateLimitAutoSendTest.php`.

- [ ] **Step 1: Write the failing test** (mirror `RateLimitAnalyticsTest`'s shape — real JWT, `set_url_params(['id'=>…])`, setUp flush of the `autoSend` transient prefix):
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Rest\Middleware\RateLimit;
use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;

final class RateLimitAutoSendTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_defyn_rl_autoSend_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_defyn_rl_autoSend_%'");
        wp_cache_flush();
        do_action('rest_api_init');
    }

    private function req(string $jwt, int $siteId): WP_REST_Request
    {
        $r = new WP_REST_Request('POST', '/defyn/v1/sites/1/auto-send');
        $r->set_header('Authorization', 'Bearer ' . $jwt);
        $r->set_url_params(['id' => (string) $siteId]);
        return $r;
    }

    public function testAllows10Then429(): void
    {
        $uid = self::factory()->user->create();
        $jwt = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($uid);
        for ($i = 1; $i <= 10; $i++) {
            self::assertTrue(RateLimit::autoSend($this->req($jwt, 80)));
        }
        $res = RateLimit::autoSend($this->req($jwt, 80));
        self::assertInstanceOf(WP_Error::class, $res);
        self::assertSame('sites.rate_limited', $res->get_error_code());
        self::assertSame(429, $res->get_error_data()['status']);
    }

    public function testMissingAuth401(): void
    {
        $r = new WP_REST_Request('POST', '/defyn/v1/sites/80/auto-send');
        $r->set_url_params(['id' => '80']);
        $res = RateLimit::autoSend($r);
        self::assertSame('auth.missing_token', $res->get_error_code());
    }
}
```
Run red → FAIL.

- [ ] **Step 2: Add to `src/Rest/Middleware/RateLimit.php`** — const pair (near `clientEmail`) + method (copy `clientEmail`'s body exactly, swap key/const/message):
```php
    // P5.4 — POST /sites/{id}/auto-send. Per-(user, site), 10/HOUR. Toggles the
    // per-site auto-send opt-in. 429 code `sites.rate_limited`. Key defyn_rl_autoSend_%d_%d.
    public const AUTO_SEND_LIMIT  = 10;
    public const AUTO_SEND_WINDOW = HOUR_IN_SECONDS;
```
```php
    public static function autoSend(WP_REST_Request $request)
    {
        $authResult = RequireAuth::check($request);
        if (is_wp_error($authResult)) {
            return $authResult;
        }
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request['id'];
        $key   = sprintf('defyn_rl_autoSend_%d_%d', $userId, $siteId);
        $count = (int) (get_transient($key) ?: 0);
        if ($count >= self::AUTO_SEND_LIMIT) {
            return new \WP_Error(
                'sites.rate_limited',
                'Too many auto-send updates. Try again in an hour.',
                ['status' => 429]
            );
        }
        set_transient($key, $count + 1, self::AUTO_SEND_WINDOW);
        return true;
    }
```

- [ ] **Step 3: Run green** → pass. **Step 4: Commit** `feat(p5-4): RateLimit autoSend bucket (10/hr)`.

---

## Task 8: `SitesAutoSendController` + route + CORS

**Files:** Create `src/Rest/SitesAutoSendController.php`; Modify `src/Rest/RestRouter.php`; Test `tests/Integration/Rest/SitesAutoSendTest.php` + `tests/Integration/Rest/AutoSendCorsTest.php`.

- [ ] **Step 1: Write the failing functional test** `tests/Integration/Rest/SitesAutoSendTest.php`:
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SitesAutoSendTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Defyn\Dashboard\Activation::ensureSchema();
        global $wpdb;
        $wpdb->query('SET autocommit = 1');
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'defyn_sites');
    }

    private function seedSite(): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix.'defyn_sites', [
            'user_id'=>1,'url'=>'https://acme.test','label'=>'Acme','status'=>'active','wp_version'=>'6.9',
            'created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $wpdb->insert_id;
    }

    private function req(int $userId, int $siteId, $autoSend): \WP_REST_Request
    {
        $r = new \WP_REST_Request('POST', '/x');
        $r->set_param('_authenticated_user_id', $userId);
        $r->set_param('id', $siteId);
        $r->set_body(json_encode(['auto_send' => $autoSend]));
        $r->set_header('Content-Type', 'application/json');
        return $r;
    }

    public function testNonOwned404(): void
    {
        $res = (new \Defyn\Dashboard\Rest\SitesAutoSendController())->handle($this->req(1, 999999, true));
        self::assertSame(404, $res->get_status());
        self::assertSame('sites.not_found', $res->get_data()['error']['code']);
    }

    public function testToggleOnPersists(): void
    {
        $siteId = $this->seedSite();
        $res = (new \Defyn\Dashboard\Rest\SitesAutoSendController())->handle($this->req(1, $siteId, true));
        self::assertSame(200, $res->get_status());
        self::assertTrue($res->get_data()['data']['auto_send_reports']);
        self::assertTrue((new SitesRepository())->findById($siteId)->autoSendReports);
    }

    public function testToggleOffPersists(): void
    {
        $siteId = $this->seedSite();
        (new SitesRepository())->setAutoSendReports($siteId, true);
        $res = (new \Defyn\Dashboard\Rest\SitesAutoSendController())->handle($this->req(1, $siteId, false));
        self::assertFalse($res->get_data()['data']['auto_send_reports']);
        self::assertFalse((new SitesRepository())->findById($siteId)->autoSendReports);
    }

    public function testNonBooleanDefaultsOff(): void
    {
        $siteId = $this->seedSite();
        (new SitesRepository())->setAutoSendReports($siteId, true);
        $res = (new \Defyn\Dashboard\Rest\SitesAutoSendController())->handle($this->req(1, $siteId, 'yes')); // not strict true
        self::assertFalse($res->get_data()['data']['auto_send_reports']);
    }
}
```
Run red → FAIL.

- [ ] **Step 2: Create `src/Rest/SitesAutoSendController.php`** (mirror `SitesClientEmailController`):
```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

/** P5.4 — POST /sites/{id}/auto-send — per-site auto-send opt-in toggle. */
final class SitesAutoSendController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');
        $body   = $request->get_json_params() ?: [];
        $sites  = new SitesRepository();
        if ($sites->findByIdForUser($siteId, $userId) === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }
        $on = ($body['auto_send'] ?? null) === true; // strict boolean opt-in
        $sites->setAutoSendReports($siteId, $on);
        return new WP_REST_Response(['data' => ['auto_send_reports' => $on], 'error' => null], 200);
    }
}
```

- [ ] **Step 3: Register the route in `src/Rest/RestRouter.php`** — add `use Defyn\Dashboard\Rest\SitesAutoSendController;` (with the other `Sites…Controller` imports) + a route block near the client-email route:
```php
        // P5.4 — POST /sites/{id}/auto-send (per-site auto-send opt-in toggle)
        register_rest_route(self::NAMESPACE, '/sites/(?P<id>\d+)/auto-send', [
            'methods'             => 'POST',
            'callback'            => [new SitesAutoSendController(), 'handle'],
            'permission_callback' => [RateLimit::class, 'autoSend'],
        ]);
```

- [ ] **Step 4: CORS test** — copy `tests/Integration/Rest/AnalyticsCorsTest.php` → `AutoSendCorsTest.php`, with `routes()` = `['POST', '/defyn/v1/sites/1/auto-send']` and `resolutionRoutes()` = `['POST', '/defyn/v1/sites/999999/auto-send']` asserting **404 `sites.not_found`** (not `rest_no_route`).

- [ ] **Step 5: Run green** `composer test:integration -- --filter "SitesAutoSend|AutoSendCors"` → all pass. Then full suite tolerating only `UninstallTest`. **Step 6: Commit** `feat(p5-4): auto-send toggle endpoint + route + CORS`.

---

## Task 9: Dashboard v0.22.0 bump

**Files:** Modify `defyn-dashboard.php`.

- [ ] **Step 1:** Line 6 `Version: 0.21.0` → `0.22.0`; the `DEFYN_DASHBOARD_VERSION` define `'0.21.0'` → `'0.22.0'`.
- [ ] **Step 2:** `grep -rn "0\.21\.0" packages/dashboard-plugin/src packages/dashboard-plugin/tests` → no matches; `grep -n "0.22.0" packages/dashboard-plugin/defyn-dashboard.php` → 2 lines. **Step 3: Commit** `chore(p5-4): bump dashboard plugin to v0.22.0`.

---

## Task 10: SPA — schemas + `useSetAutoSend` mutation

**Files:** Modify `apps/web/src/types/api.ts`, `apps/web/src/test/handlers.ts`; Create `apps/web/src/lib/mutations/useSetAutoSend.ts`.

- [ ] **Step 1: Node 22.** Extend `src/types/api.ts`:
  - In `siteSchema` (api.ts ~line 19-50), after `client_email`:
```ts
  // P5.4 — per-site auto-send opt-in (always present; NOT NULL DEFAULT 0 backend).
  auto_send_reports: z.boolean(),
```
  (If existing MSW site fixtures would now fail because they lack the field, add `auto_send_reports: false` to every site fixture so the contract stays strict; verify which site fixtures exist.)
  - In `reportSchema` (api.ts ~line 233), after `sent_at`:
```ts
  sent_method: z.string().nullable(),
```

- [ ] **Step 2: Create `apps/web/src/lib/mutations/useSetAutoSend.ts`** (mirror `useSetClientEmail`):
```ts
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';

/**
 * P5.4 — POSTs to /sites/{id}/auto-send to toggle the per-site auto-send opt-in.
 * On success: invalidate ['site', siteId] (the flag lives on the site record)
 * AND ['siteReports', siteId] (so the panel re-reads).
 */
export function useSetAutoSend(siteId: number) {
  const qc = useQueryClient();
  return useMutation<unknown, Error, boolean>({
    mutationFn: (autoSend) =>
      apiClient.post<unknown>(`/sites/${siteId}/auto-send`, { auto_send: autoSend }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['site', siteId] });
      qc.invalidateQueries({ queryKey: ['siteReports', siteId] });
    },
  });
}
```

- [ ] **Step 3: Update MSW** in `src/test/handlers.ts`: add a `POST /sites/:id/auto-send` handler → `{data:{auto_send_reports:<echo of body.auto_send>}, error:null}`; add `auto_send_reports: false` to the site fixture(s) + `sent_method: null` to the report fixtures.

- [ ] **Step 4: Run** `pnpm test -- --run` → only the 4 carry-forwards; **`pnpm build` tsc-clean**. **Step 5: Commit** `feat(p5-4): SPA auto_send_reports/sent_method schemas + useSetAutoSend`.

---

## Task 11: SPA — auto-send toggle + "Auto-sent" badge

**Files:** Modify `apps/web/src/components/reports/SiteReportsPanel.tsx`, `apps/web/src/components/reports/ReportStatusBadge.tsx`, `apps/web/src/routes/SiteDetail.tsx`; Test `apps/web/tests/components/reports/SiteReportsPanel.test.tsx` (+ a badge test).

- [ ] **Step 1: Write the failing tests** — mirror the existing `SiteReportsPanel`/`ReportStatusBadge` test harness:
  - `ReportStatusBadge` renders "Auto-sent" (violet) when `status='sent'` + `sentMethod='auto'`; "Sent" (green) when `status='sent'` + `sentMethod` null/'manual'.
  - `SiteReportsPanel` renders an "Auto-send monthly reports" toggle reflecting `autoSendReports`, and clicking it fires `useSetAutoSend`.
  Run red → FAIL.

- [ ] **Step 2: Edit `src/components/reports/ReportStatusBadge.tsx`** — accept an optional `sentMethod` prop and special-case the auto-sent label/color:
```tsx
interface ReportStatusBadgeProps { status: ReportStatus; sentMethod?: string | null; }

export function ReportStatusBadge({ status, sentMethod }: ReportStatusBadgeProps) {
  const isAuto = status === 'sent' && sentMethod === 'auto';
  const cls   = isAuto ? 'text-violet-700 bg-violet-100' : STATUS_CLASSES[status];
  const label = isAuto ? 'Auto-sent' : STATUS_LABELS[status];
  const Icon  = STATUS_ICONS[status];
  const isSpinning = status === 'generating';
  return (
    <span data-testid="report-status-badge"
      className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${cls}`}>
      <Icon className={`h-3 w-3${isSpinning ? ' animate-spin' : ''}`} aria-hidden="true" />
      {label}
    </span>
  );
}
```
  Update the row that renders `<ReportStatusBadge status={report.status} />` to pass `sentMethod={report.sent_method}`.

- [ ] **Step 3: Edit `src/components/reports/SiteReportsPanel.tsx`** — accept an `autoSendReports: boolean` prop (alongside the existing `siteId`/`clientEmail`); render an "Auto-send monthly reports" toggle (use the same Switch/toggle component the mute-alerts or core-allow-major settings row uses — verify the component) wired to `useSetAutoSend(siteId).mutate(next)`; show it beside the client-email field with a hint ("set a client email to receive auto-sent reports") when `clientEmail` is empty. The toggle stays operable regardless (the backend independently gates the actual send on a valid email).

- [ ] **Step 4: Edit `src/routes/SiteDetail.tsx`** — where `<SiteReportsPanel siteId={…} clientEmail={…} />` is rendered, add `autoSendReports={site.auto_send_reports}`.

- [ ] **Step 5: Run green** `pnpm test -- --run "SiteReportsPanel|ReportStatusBadge"` PASS; full `pnpm test -- --run` → only the 4 carry-forwards (run COMPLETES, no hang); **`pnpm build` tsc-clean**. **Step 6: Commit** `feat(p5-4): SPA auto-send toggle + Auto-sent badge`.

---

## Task 12: Release — build, ship, smoke, tag, MEMORY

**Files:** build artifacts only.

- [ ] **Step 1:** Full PHP suite → only `UninstallTest`. **Step 2:** Full SPA suite (Node 22) → only the 4 carry-forwards; `cd apps/web && pnpm build` clean.
- [ ] **Step 3: dompdf-preserving zip:**
```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && composer install --no-dev --classmap-authoritative
cd "/Users/pradeep/Local Sites/defynWP/packages"
rm -f "/Users/pradeep/Local Sites/defynWP/dist/defyn-dashboard-0.22.0.zip"
zip -rq "/Users/pradeep/Local Sites/defynWP/dist/defyn-dashboard-0.22.0.zip" dashboard-plugin \
  -x 'dashboard-plugin/tests/*' '*wp-tests-config.php' 'dashboard-plugin/.phpunit.result.cache' \
     'dashboard-plugin/test-output.log' 'dashboard-plugin/phpunit.xml' 'dashboard-plugin/composer.lock' \
     'dashboard-plugin/.github/*' 'dashboard-plugin/.gitignore'
cd "/Users/pradeep/Local Sites/defynWP"
unzip -l dist/defyn-dashboard-0.22.0.zip | grep -cE "deprecation-contracts/function\.php|polyfill-php83/bootstrap\.php"  # MUST be 2
unzip -l dist/defyn-dashboard-0.22.0.zip | grep -c "json-machine/src/Items\.php"          # >=1
unzip -l dist/defyn-dashboard-0.22.0.zip | grep -c "dompdf/src/Dompdf\.php"                # >=1
unzip -l dist/defyn-dashboard-0.22.0.zip | grep -c "src/Schema/SitePerformanceTable\.php"  # >=1
unzip -l dist/defyn-dashboard-0.22.0.zip | grep -c "src/Schema/SiteAnalyticsTable\.php"    # >=1
unzip -p dist/defyn-dashboard-0.22.0.zip dashboard-plugin/defyn-dashboard.php | grep -m1 DEFYN_DASHBOARD_VERSION
cd packages/dashboard-plugin && composer install
```
- [ ] **Step 4: Merge + push** `git checkout main && git merge --ff-only p5-4-auto-send-reports && git push origin main`.
- [ ] **Step 5: Kinsta install (MANUAL — pause for "installed").** Upload `dist/defyn-dashboard-0.22.0.zip` via "Replace current with uploaded version"; clear MyKinsta cache. Schema v16 self-heals.
- [ ] **Step 6: Indirect curl smoke** (login field `access_token`; backend `defynwp.defyn.agency`): `POST /sites/1/auto-send` no-auth → 401; `POST /sites/999999/auto-send` auth → 404 `sites.not_found` (proves route + v16 live); bogus route → `rest.route_not_found`; verify the deployed SPA bundle contains 'Auto-send' / 'Auto-sent'. **Happy auto-send path foreclosed by zero-sites prod + opt-in-OFF default — the smoke NEVER emails a real client.**
- [ ] **Step 7: Tag** `git tag p5-4-auto-send-complete && git push origin p5-4-auto-send-complete`.
- [ ] **Step 8: MEMORY** — append a P5.4-complete entry to `project_defyn_roadmap.md` (v0.22.0, tag, schema v16, the per-site auto-send toggle + the chain-after-markReady + `ReportSendService` DRY extraction + `sent_method`/Auto-sent badge + the endpoint, smoke results) + refresh `MEMORY.md`. **NEXT = operator's choice (no locked phases remain).**

---

## Self-Review (completed during planning)

- **Spec coverage:** opt-in column + Site model + setter → Tasks 1–2; sent_method + markSent → Tasks 1, 3; `ReportSendService` DRY → Task 4; manual controller refactor (stays green) → Task 5; auto-send chain → Task 6; rate limit → Task 7; endpoint + CORS → Task 8; version → Task 9; SPA schemas + mutation → Task 10; toggle + badge → Task 11; release → Task 12. ✅
- **Type consistency:** `markSent(id, recipient, sentAt, method='manual')` used identically in Tasks 3/4/5/6; `Site->autoSendReports` (bool) + `Site->clientEmail` consumed in Task 6; `Report->sentMethod` (?string) surfaced in Task 3 + consumed by the badge in Task 11; `ReportSendService::send(Report,Site,to,?note,method):bool` consistent across Tasks 4/5/6; the endpoint envelope `{data:{auto_send_reports:bool}}` consistent Task 8 ↔ SPA Task 10. ✅
- **Guardrails embedded:** OFF-by-default + email-gate (Tasks 1/6); best-effort never-throws (Task 6); DRY single send path + byte-identical body (Tasks 4/5); no double-send via fires-once-on-ready + status guard (Task 6); ownership-404 + RateLimit + CORS (Tasks 7/8); file_name never serialized (Task 3); NO new job/guard/table (Task 1 note). ✅
- **Implementer verifications flagged:** the real send-controller test name (Task 5); the existing `GenerateReportTest` pdf-stub + report-seed helpers (Task 6 — reuse, don't reinvent); the `ReportsTable` column names (Tasks 3/4/6 seed inserts); the activity-log event column (`event_type`, Task 4); the `SiteReportsPanel`/`ReportStatusBadge` paths + the Switch component (Task 11); the `siteSchema` field-add not breaking existing site fixtures (Task 10); the `register_rest_route` two-call form (Task 8).
