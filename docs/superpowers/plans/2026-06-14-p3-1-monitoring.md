# P3.1 — Site Monitoring (Incidents + Email Alerts) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the existing silent 5-minute heartbeat loop into actionable uptime monitoring — record confirmed downtime as incidents, email the site owner on down + recovery, and surface incidents on the Overview (open-incidents rollup) and Site detail (incident history panel).

**Architecture:** Pure extension of the foundation's `HealthService::ping()` loop. A new `IncidentService` is invoked from the existing fail/success branches; it manages a per-site `consecutive_failures` counter, opens/closes rows in a new `wp_defyn_incidents` table after a 2-failure debounce, and notifies through a `Notifier` interface (one `EmailNotifier` impl via `wp_mail`). New REST surface: `GET /sites/{id}/incidents` + an additive `open_incidents[]` on `/overview`. SPA adds a SiteDetail panel + an Overview widget. **Connector untouched.**

**Tech Stack:** PHP 8.1 (dashboard plugin, PHPUnit + wp-phpunit), React 18 + TypeScript + TanStack Query v5 + Zod + Vitest + MSW (SPA). Schema self-heal migration (v7→v8). Node 22 (`.nvmrc`).

**Spec:** `docs/superpowers/specs/2026-06-14-p3-1-monitoring-design.md` (commit e4fcbf2).
**Branch:** `p3-1-monitoring` (off `main` @ c599457).

---

## File Structure

**Dashboard plugin (`packages/dashboard-plugin`)**
- Create `src/Schema/IncidentsTable.php` — `wp_defyn_incidents` DDL (mirror `src/Schema/BulkJobsTable.php`).
- Modify `src/Activation.php` — `SCHEMA_VERSION 7→8`, add `IncidentsTable::class` to `TABLES`, add `addConsecutiveFailuresColumn()` guarded ALTER.
- Modify `src/Uninstaller.php` — drop `wp_defyn_incidents`.
- Create `src/Models/Incident.php` — immutable DTO (mirror `src/Models/*` e.g. the Theme/BulkJob model).
- Create `src/Services/IncidentsRepository.php` — incident persistence.
- Modify `src/Services/SitesRepository.php` — `incrementConsecutiveFailures` / `resetConsecutiveFailures`.
- Modify `src/Models/Site.php` — add readonly `consecutiveFailures` field.
- Create `src/Notify/Notifier.php` (interface) + `src/Notify/EmailNotifier.php`.
- Create `src/Services/IncidentService.php` — the state machine.
- Modify `src/Services/HealthService.php` — call `IncidentService` in fail/success branches.
- Create `src/Rest/SitesIncidentsController.php` — `GET /sites/{id}/incidents`.
- Modify `src/Rest/Middleware/RateLimit.php` — `sitesIncidents` bucket.
- Modify `src/Rest/RestRouter.php` — register route.
- Modify `src/Services/OverviewService.php` — add `open_incidents`.
- Modify `defyn-dashboard.php` + `readme.txt` — v0.9.0→v0.10.0.

**SPA (`apps/web`)**
- Modify `src/types/api.ts` — `incidentSchema`, `Incident`, extend `overviewSchema` (`open_incidents`).
- Modify `src/test/handlers.ts` — MSW handlers for `/sites/:id/incidents` + extended `/overview`.
- Create `src/lib/queries/useSiteIncidents.ts`.
- Create `src/components/sites/IncidentHistoryPanel.tsx`.
- Create `src/components/overview/OpenIncidentsWidget.tsx`.
- Modify `src/routes/SiteDetail.tsx` — mount the panel.
- Modify `src/routes/Overview.tsx` — mount the widget.

---

## Guardrails (from spec §13 — repeated at point of use in tasks)

1. Open an incident only on the **2nd** consecutive failure, never the 1st (Task 6).
2. **One email per edge** — guard with `down_alert_sent_at` / `up_alert_sent_at` (Tasks 5, 6).
3. Do **not** change the existing instantaneous `status` flip or `site.health_*` events (Task 7).
4. `recordSuccess` always resets `consecutive_failures` to 0 (Task 6).
5. Single open incident per site — `open` only when `findOpenForSite` is null (Tasks 3, 6).
6. Email is **best-effort** — `EmailNotifier` and `IncidentService` never throw into the ping loop (Tasks 5, 6).
7. Recipient = the **site owner's** user email (Task 5).
8. `OpenIncidentsWidget` is **hidden** when there are no open incidents (Task 14).
9. UTC everywhere; `duration_seconds` computed on close only (Tasks 3, 6).
10. Schema via self-heal (v8); Uninstaller drops `wp_defyn_incidents`; FK cascade on site delete (Task 1).
11. Connector is NOT touched — no version bump, no zip, no re-handshake (Task 16).

---

## Task 1: Schema v8 — `wp_defyn_incidents` table + `consecutive_failures` column + Uninstaller drop

**Files:**
- Create: `packages/dashboard-plugin/src/Schema/IncidentsTable.php`
- Modify: `packages/dashboard-plugin/src/Activation.php` (const + TABLES + new ALTER method)
- Modify: `packages/dashboard-plugin/src/Uninstaller.php`
- Test: `packages/dashboard-plugin/tests/Integration/Schema/IncidentsSchemaTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Schema;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Schema\IncidentsTable;
use Defyn\Dashboard\Schema\SitesTable;
use WP_UnitTestCase;

final class IncidentsSchemaTest extends WP_UnitTestCase
{
    public function test_ensure_schema_creates_incidents_table(): void
    {
        global $wpdb;
        Activation::ensureSchema();
        $table = IncidentsTable::tableName();
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        $this->assertSame($table, $found);
    }

    public function test_ensure_schema_adds_consecutive_failures_column(): void
    {
        global $wpdb;
        Activation::ensureSchema();
        $sites = SitesTable::tableName();
        $col   = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$sites}` LIKE %s", 'consecutive_failures'));
        $this->assertSame('consecutive_failures', $col);
    }

    public function test_schema_version_is_8(): void
    {
        $this->assertSame(8, Activation::SCHEMA_VERSION);
    }
}
```

- [ ] **Step 2: Run it; expect FAIL** — `vendor/bin/phpunit --filter IncidentsSchemaTest` → fails (`IncidentsTable` missing / version 7).

- [ ] **Step 3: Create `IncidentsTable.php`** (mirror `BulkJobsTable.php`: same `implements SchemaTable`, `tableName()` using `$wpdb->prefix . 'defyn_incidents'`, `createSql()` returning dbDelta-compatible DDL with the charset collate):

```php
<?php
declare(strict_types=1);

namespace Defyn\Dashboard\Schema;

final class IncidentsTable implements SchemaTable
{
    public static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'defyn_incidents';
    }

    public static function createSql(): string
    {
        global $wpdb;
        $table   = self::tableName();
        $charset = $wpdb->get_charset_collate();

        return "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            site_id BIGINT UNSIGNED NOT NULL,
            started_at DATETIME NOT NULL,
            ended_at DATETIME NULL DEFAULT NULL,
            duration_seconds INT UNSIGNED NULL DEFAULT NULL,
            last_error TEXT NULL,
            down_alert_sent_at DATETIME NULL DEFAULT NULL,
            up_alert_sent_at DATETIME NULL DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_incidents_site (site_id, started_at),
            KEY idx_incidents_open (site_id, ended_at)
        ) {$charset};";
    }
}
```

> Note: dbDelta does not create foreign keys; the cascade-on-site-delete is achieved by an explicit `DELETE FROM incidents WHERE site_id = ...` in the site-delete path (if `SitesRepository::delete` exists, add it there — mirror how site_plugins/site_themes rows are cleaned) + the Uninstaller drop. Single-open is enforced in app logic (Tasks 3/6), not a DB constraint.

- [ ] **Step 4: Edit `Activation.php`:**
  - Add `use Defyn\Dashboard\Schema\IncidentsTable;` with the other Schema imports.
  - Change `public const SCHEMA_VERSION = 7;` → `= 8;`.
  - Add `IncidentsTable::class,` to the `TABLES` array (after `BulkJobItemsTable::class`).
  - In `ensureSchema()`, after `self::addThemesTestedUpToColumn($wpdb);`, add `self::addConsecutiveFailuresColumn($wpdb);`.
  - Add the method (mirror `addCoreAllowMajorColumn`):

```php
    private static function addConsecutiveFailuresColumn(\wpdb $wpdb): void
    {
        $table  = SitesTable::tableName();
        $exists = $wpdb->get_var($wpdb->prepare(
            "SHOW COLUMNS FROM `{$table}` LIKE %s",
            'consecutive_failures'
        ));
        if ($exists !== null) {
            return;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL — column DDL cannot be parameterized.
        $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN consecutive_failures INT NOT NULL DEFAULT 0");
    }
```

- [ ] **Step 5: Edit `Uninstaller.php`** — add `IncidentsTable::tableName()` (or `$wpdb->prefix . 'defyn_incidents'`) to the set of tables dropped (mirror how `wp_defyn_bulk_jobs` is dropped).

- [ ] **Step 6: Run it; expect PASS** — `vendor/bin/phpunit --filter IncidentsSchemaTest`.

- [ ] **Step 7: Commit**

```bash
git add packages/dashboard-plugin/src/Schema/IncidentsTable.php packages/dashboard-plugin/src/Activation.php packages/dashboard-plugin/src/Uninstaller.php packages/dashboard-plugin/tests/Integration/Schema/IncidentsSchemaTest.php
git commit -m "feat(p3-1): schema v8 — wp_defyn_incidents + consecutive_failures column"
```

---

## Task 2: `Models\Incident` immutable DTO

**Files:**
- Create: `packages/dashboard-plugin/src/Models/Incident.php`
- Test: `packages/dashboard-plugin/tests/Unit/Models/IncidentTest.php`

- [ ] **Step 1: Failing test**

```php
<?php
declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Unit\Models;

use Defyn\Dashboard\Models\Incident;
use PHPUnit\Framework\TestCase;

final class IncidentTest extends TestCase
{
    public function test_from_row_and_to_json_open_incident(): void
    {
        $i = Incident::fromRow([
            'id' => '5', 'site_id' => '2',
            'started_at' => '2026-06-14 10:00:00', 'ended_at' => null,
            'duration_seconds' => null, 'last_error' => 'Connector returned status 500',
            'down_alert_sent_at' => '2026-06-14 10:00:01', 'up_alert_sent_at' => null,
            'created_at' => '2026-06-14 10:00:00',
        ]);
        $this->assertSame(5, $i->id);
        $this->assertSame(2, $i->siteId);
        $this->assertNull($i->endedAt);
        $this->assertNull($i->durationSeconds);
        $json = $i->toJson();
        $this->assertSame(5, $json['id']);
        $this->assertSame('Connector returned status 500', $json['last_error']);
        $this->assertNull($json['ended_at']);
    }

    public function test_from_row_closed_incident_casts_duration(): void
    {
        $i = Incident::fromRow([
            'id' => '5', 'site_id' => '2',
            'started_at' => '2026-06-14 10:00:00', 'ended_at' => '2026-06-14 10:35:00',
            'duration_seconds' => '2100', 'last_error' => 'x',
            'down_alert_sent_at' => null, 'up_alert_sent_at' => null, 'created_at' => '2026-06-14 10:00:00',
        ]);
        $this->assertSame(2100, $i->durationSeconds);
        $this->assertSame('2026-06-14 10:35:00', $i->endedAt);
    }
}
```

- [ ] **Step 2: Run; expect FAIL** (class missing).

- [ ] **Step 3: Implement**

```php
<?php
declare(strict_types=1);

namespace Defyn\Dashboard\Models;

final class Incident
{
    public function __construct(
        public readonly int $id,
        public readonly int $siteId,
        public readonly string $startedAt,
        public readonly ?string $endedAt,
        public readonly ?int $durationSeconds,
        public readonly ?string $lastError,
        public readonly ?string $downAlertSentAt,
        public readonly ?string $upAlertSentAt,
        public readonly string $createdAt,
    ) {}

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            siteId: (int) $row['site_id'],
            startedAt: (string) $row['started_at'],
            endedAt: isset($row['ended_at']) ? (string) $row['ended_at'] : null,
            durationSeconds: isset($row['duration_seconds']) ? (int) $row['duration_seconds'] : null,
            lastError: isset($row['last_error']) ? (string) $row['last_error'] : null,
            downAlertSentAt: isset($row['down_alert_sent_at']) ? (string) $row['down_alert_sent_at'] : null,
            upAlertSentAt: isset($row['up_alert_sent_at']) ? (string) $row['up_alert_sent_at'] : null,
            createdAt: (string) $row['created_at'],
        );
    }

    /** @return array<string,mixed> */
    public function toJson(): array
    {
        return [
            'id' => $this->id,
            'site_id' => $this->siteId,
            'started_at' => $this->startedAt,
            'ended_at' => $this->endedAt,
            'duration_seconds' => $this->durationSeconds,
            'last_error' => $this->lastError,
            'created_at' => $this->createdAt,
        ];
    }
}
```

> Note: `down_alert_sent_at` / `up_alert_sent_at` are persistence-internal — intentionally NOT emitted in `toJson()` (the SPA never needs them).

- [ ] **Step 4: Run; expect PASS.**
- [ ] **Step 5: Commit** — `feat(p3-1): Incident immutable DTO`.

---

## Task 3: `Services\IncidentsRepository`

**Files:**
- Create: `packages/dashboard-plugin/src/Services/IncidentsRepository.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/IncidentsRepositoryTest.php`

- [ ] **Step 1: Failing test** — covers: `open` then `findOpenForSite` returns it; `close` sets ended/duration and `findOpenForSite` then returns null; `markDownAlertSent` stamps; `findForSite` newest-first with limit/offset; `findOpenForUser` only returns open incidents for that user's sites with the joined label. (Seed sites via the same helper the existing repo tests use — mirror `tests/Integration/Services/BulkJobsRepositoryTest.php` setup; a `makeSite(int $userId, string $label): int` helper inserts via `SitesRepository`.)

```php
public function test_open_then_find_open_returns_incident(): void
{
    $siteId = $this->makeSite(1, 'AcmeBlog');
    $repo = new IncidentsRepository();
    $id = $repo->open($siteId, '2026-06-14 10:00:00', 'Connector returned status 500');
    $open = $repo->findOpenForSite($siteId);
    $this->assertNotNull($open);
    $this->assertSame($id, $open->id);
    $this->assertNull($open->endedAt);
}

public function test_close_clears_open_and_sets_duration(): void
{
    $siteId = $this->makeSite(1, 'AcmeBlog');
    $repo = new IncidentsRepository();
    $id = $repo->open($siteId, '2026-06-14 10:00:00', 'x');
    $repo->close($id, '2026-06-14 10:35:00', 2100);
    $this->assertNull($repo->findOpenForSite($siteId));
    $rows = $repo->findForSite($siteId, 10, 0);
    $this->assertSame(2100, $rows[0]->durationSeconds);
}

public function test_find_open_for_user_joins_label_and_scopes_to_user(): void
{
    $mine   = $this->makeSite(1, 'Mine');
    $theirs = $this->makeSite(2, 'Theirs');
    $repo = new IncidentsRepository();
    $repo->open($mine, '2026-06-14 10:00:00', 'x');
    $repo->open($theirs, '2026-06-14 10:00:00', 'x');
    $rows = $repo->findOpenForUser(1);
    $this->assertCount(1, $rows);
    $this->assertSame('Mine', $rows[0]['site_label']);
}
```

- [ ] **Step 2: Run; expect FAIL.**
- [ ] **Step 3: Implement** (mirror `BulkJobsRepository` style):

```php
<?php
declare(strict_types=1);

namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Models\Incident;
use Defyn\Dashboard\Schema\IncidentsTable;
use Defyn\Dashboard\Schema\SitesTable;

final class IncidentsRepository
{
    public function findOpenForSite(int $siteId): ?Incident
    {
        global $wpdb;
        $t = IncidentsTable::tableName();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM `{$t}` WHERE site_id = %d AND ended_at IS NULL ORDER BY started_at DESC LIMIT 1", $siteId),
            ARRAY_A
        );
        return $row ? Incident::fromRow($row) : null;
    }

    public function open(int $siteId, string $startedAt, string $error): int
    {
        global $wpdb;
        $wpdb->insert(IncidentsTable::tableName(), [
            'site_id' => $siteId,
            'started_at' => $startedAt,
            'last_error' => $error,
            'created_at' => $startedAt,
        ], ['%d', '%s', '%s', '%s']);
        return (int) $wpdb->insert_id;
    }

    public function close(int $incidentId, string $endedAt, int $durationSeconds): void
    {
        global $wpdb;
        $wpdb->update(IncidentsTable::tableName(),
            ['ended_at' => $endedAt, 'duration_seconds' => $durationSeconds],
            ['id' => $incidentId], ['%s', '%d'], ['%d']);
    }

    public function markDownAlertSent(int $id, string $at): void
    {
        global $wpdb;
        $wpdb->update(IncidentsTable::tableName(), ['down_alert_sent_at' => $at], ['id' => $id], ['%s'], ['%d']);
    }

    public function markUpAlertSent(int $id, string $at): void
    {
        global $wpdb;
        $wpdb->update(IncidentsTable::tableName(), ['up_alert_sent_at' => $at], ['id' => $id], ['%s'], ['%d']);
    }

    /** @return Incident[] */
    public function findForSite(int $siteId, int $limit, int $offset): array
    {
        global $wpdb;
        $t = IncidentsTable::tableName();
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM `{$t}` WHERE site_id = %d ORDER BY started_at DESC LIMIT %d OFFSET %d", $siteId, $limit, $offset),
            ARRAY_A
        ) ?: [];
        return array_map([Incident::class, 'fromRow'], $rows);
    }

    /** @return array<int,array{site_id:int,site_label:string,started_at:string}> */
    public function findOpenForUser(int $userId): array
    {
        global $wpdb;
        $i = IncidentsTable::tableName();
        $s = SitesTable::tableName();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT i.site_id AS site_id, s.label AS site_label, i.started_at AS started_at
             FROM `{$i}` i INNER JOIN `{$s}` s ON s.id = i.site_id
             WHERE s.user_id = %d AND i.ended_at IS NULL
             ORDER BY i.started_at ASC",
            $userId
        ), ARRAY_A) ?: [];
        return array_map(static fn ($r) => [
            'site_id' => (int) $r['site_id'],
            'site_label' => (string) $r['site_label'],
            'started_at' => (string) $r['started_at'],
        ], $rows);
    }
}
```

> Guardrail 5: single-open is the caller's responsibility (`open` only when `findOpenForSite` is null) — see Task 6. `findOpenForSite` defensively `ORDER BY ... LIMIT 1`.

- [ ] **Step 4: Run; expect PASS.**
- [ ] **Step 5: Commit** — `feat(p3-1): IncidentsRepository`.

---

## Task 4: `consecutive_failures` counter on SitesRepository + Site model

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/SitesRepository.php`
- Modify: `packages/dashboard-plugin/src/Models/Site.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/SitesRepositoryConsecutiveFailuresTest.php`

- [ ] **Step 1: Failing test**

```php
public function test_increment_then_reset_consecutive_failures(): void
{
    $siteId = $this->makeSite(1, 'AcmeBlog');
    $repo = new SitesRepository();
    $this->assertSame(1, $repo->incrementConsecutiveFailures($siteId));
    $this->assertSame(2, $repo->incrementConsecutiveFailures($siteId));
    $repo->resetConsecutiveFailures($siteId);
    $this->assertSame(0, $repo->findById($siteId)->consecutiveFailures);
}
```

- [ ] **Step 2: Run; expect FAIL** (methods + field missing).

- [ ] **Step 3: Implement** — in `SitesRepository`:

```php
    public function incrementConsecutiveFailures(int $siteId): int
    {
        global $wpdb;
        $t = SitesTable::tableName();
        // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->query($wpdb->prepare("UPDATE `{$t}` SET consecutive_failures = consecutive_failures + 1 WHERE id = %d", $siteId));
        return (int) $wpdb->get_var($wpdb->prepare("SELECT consecutive_failures FROM `{$t}` WHERE id = %d", $siteId));
    }

    public function resetConsecutiveFailures(int $siteId): void
    {
        global $wpdb;
        $t = SitesTable::tableName();
        $wpdb->update($t, ['consecutive_failures' => 0], ['id' => $siteId], ['%d'], ['%d']);
    }
```

  In `Site.php`: add `public readonly int $consecutiveFailures = 0,` to the constructor (after the existing core fields) and in `fromRow` set `consecutiveFailures: isset($row['consecutive_failures']) ? (int) $row['consecutive_failures'] : 0,`. Do NOT add it to `toJson()`.

- [ ] **Step 4: Run; expect PASS.**
- [ ] **Step 5: Commit** — `feat(p3-1): consecutive_failures counter on SitesRepository + Site model`.

---

## Task 5: `Notifier` interface + `EmailNotifier`

**Files:**
- Create: `packages/dashboard-plugin/src/Notify/Notifier.php`
- Create: `packages/dashboard-plugin/src/Notify/EmailNotifier.php`
- Test: `packages/dashboard-plugin/tests/Integration/Notify/EmailNotifierTest.php`

- [ ] **Step 1: Failing test** — a down email goes to the owner with the label in the subject; a `wp_mail` failure does NOT throw. Use the WP test harness mailer (`reset_phpmailer_instance()` / `tests_retrieve_phpmailer_instance()`):

```php
public function test_notify_down_sends_email_to_site_owner(): void
{
    reset_phpmailer_instance();
    $userId = self::factory()->user->create(['user_email' => 'owner@example.com']);
    $site = $this->makeSiteModel($userId, 'AcmeBlog', 'https://acme.test');
    $incident = $this->makeIncidentModel($site->id, '2026-06-14 10:00:00');

    (new EmailNotifier())->notifyDown($site, $incident);

    $sent = tests_retrieve_phpmailer_instance()->get_sent();
    $this->assertNotEmpty($sent);
    $this->assertSame('owner@example.com', $sent[0]->to[0][0]);
    $this->assertStringContainsString('AcmeBlog', $sent[0]->subject);
    $this->assertStringContainsString('down', strtolower($sent[0]->subject));
}

public function test_notify_down_does_not_throw_when_wp_mail_fails(): void
{
    add_filter('pre_wp_mail', '__return_false');
    $site = $this->makeSiteModel(1, 'AcmeBlog', 'https://acme.test');
    $incident = $this->makeIncidentModel($site->id, '2026-06-14 10:00:00');
    (new EmailNotifier())->notifyDown($site, $incident);   // must not throw
    $this->assertTrue(true);
    remove_filter('pre_wp_mail', '__return_false');
}
```

- [ ] **Step 2: Run; expect FAIL.**

- [ ] **Step 3: Implement**

```php
<?php
declare(strict_types=1);

namespace Defyn\Dashboard\Notify;

use Defyn\Dashboard\Models\Incident;
use Defyn\Dashboard\Models\Site;

interface Notifier
{
    public function notifyDown(Site $site, Incident $incident): void;
    public function notifyRecovered(Site $site, Incident $incident): void;
}
```

```php
<?php
declare(strict_types=1);

namespace Defyn\Dashboard\Notify;

use Defyn\Dashboard\Models\Incident;
use Defyn\Dashboard\Models\Site;
use Throwable;

/**
 * P3.1 — emails the site owner on incident open/close. Best-effort: a wp_mail
 * failure is swallowed (logged) and never propagates into the HealthService
 * ping loop (guardrail 6). Recipient is the OWNER's user email (guardrail 7).
 */
final class EmailNotifier implements Notifier
{
    public function notifyDown(Site $site, Incident $incident): void
    {
        $this->send(
            $site,
            '🔴 ' . $site->label . ' is down',
            "Your site {$site->label} ({$site->url}) appears to be down.\n\n"
            . "Down since: {$incident->startedAt} UTC\n"
            . "Last error: " . ($incident->lastError ?? 'unknown') . "\n"
        );
    }

    public function notifyRecovered(Site $site, Incident $incident): void
    {
        $dur = $incident->durationSeconds !== null ? $this->humanDuration($incident->durationSeconds) : 'unknown';
        $this->send(
            $site,
            '✅ ' . $site->label . ' recovered — down ' . $dur,
            "Your site {$site->label} ({$site->url}) has recovered.\n\n"
            . "Down from {$incident->startedAt} to " . ($incident->endedAt ?? '?') . " UTC ({$dur}).\n"
        );
    }

    private function send(Site $site, string $subject, string $body): void
    {
        $to = $this->ownerEmail($site->userId);
        if ($to === '') {
            return;
        }
        try {
            wp_mail($to, $subject, $body);
        } catch (Throwable $e) {
            error_log('[defyn] EmailNotifier failed: ' . $e->getMessage());
        }
    }

    private function ownerEmail(int $userId): string
    {
        $user = get_userdata($userId);
        return ($user && is_email($user->user_email)) ? (string) $user->user_email : '';
    }

    private function humanDuration(int $seconds): string
    {
        if ($seconds < 60) return $seconds . 's';
        if ($seconds < 3600) return floor($seconds / 60) . 'm';
        return floor($seconds / 3600) . 'h ' . floor(($seconds % 3600) / 60) . 'm';
    }
}
```

- [ ] **Step 4: Run; expect PASS.**
- [ ] **Step 5: Commit** — `feat(p3-1): Notifier interface + EmailNotifier (best-effort wp_mail)`.

---

## Task 6: `IncidentService` — the confirm-down state machine (CORE)

**Files:**
- Create: `packages/dashboard-plugin/src/Services/IncidentService.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/IncidentServiceTest.php`

State machine (guardrails 1, 2, 4, 5, 6, 9):
- `recordFailure(Site $site, string $message)`: `$n = increment`. If `$n < 2` → return. If `findOpenForSite` not null → return. Else `open` + `notifyDown` + `markDownAlertSent` + `ActivityLogger::log(... 'site.incident_opened' ...)`.
- `recordSuccess(Site $site)`: `$open = findOpenForSite`. If `$open`: compute duration; `close`; `notifyRecovered` (closed copy with ended/duration); `markUpAlertSent`; `ActivityLogger::log(... 'site.incident_closed' ...)`. Always `resetConsecutiveFailures`.
- All timestamps `gmdate('Y-m-d H:i:s')`.

- [ ] **Step 1: Failing tests** (use a `SpyNotifier implements Notifier` counting `downCount`/`upCount`; `seedSite(int $userId): Site` seeds + returns the model; `reload(Site): Site` re-reads via `SitesRepository::findById`):

```php
public function test_first_failure_does_not_open_incident(): void
{
    $site = $this->seedSite(1);
    $notifier = new SpyNotifier();
    $svc = $this->service($notifier);
    $svc->recordFailure($site, 'boom');
    $this->assertNull((new IncidentsRepository())->findOpenForSite($site->id));
    $this->assertSame(0, $notifier->downCount);
}

public function test_second_consecutive_failure_opens_incident_and_alerts_once(): void
{
    $site = $this->seedSite(1);
    $notifier = new SpyNotifier();
    $svc = $this->service($notifier);
    $svc->recordFailure($site, 'boom');
    $svc->recordFailure($this->reload($site), 'boom');
    $open = (new IncidentsRepository())->findOpenForSite($site->id);
    $this->assertNotNull($open);
    $this->assertSame(1, $notifier->downCount);
    $svc->recordFailure($this->reload($site), 'boom');     // already open
    $this->assertSame(1, $notifier->downCount);
}

public function test_success_closes_incident_resets_counter_and_alerts(): void
{
    $site = $this->seedSite(1);
    $notifier = new SpyNotifier();
    $svc = $this->service($notifier);
    $svc->recordFailure($site, 'boom');
    $svc->recordFailure($this->reload($site), 'boom');
    $svc->recordSuccess($this->reload($site));
    $this->assertNull((new IncidentsRepository())->findOpenForSite($site->id));
    $this->assertSame(1, $notifier->upCount);
    $this->assertSame(0, $this->reload($site)->consecutiveFailures);
}

public function test_single_failure_then_success_no_incident_no_email(): void
{
    $site = $this->seedSite(1);
    $notifier = new SpyNotifier();
    $svc = $this->service($notifier);
    $svc->recordFailure($site, 'blip');
    $svc->recordSuccess($this->reload($site));
    $this->assertSame(0, $notifier->downCount);
    $this->assertSame(0, $notifier->upCount);
    $this->assertNull((new IncidentsRepository())->findOpenForSite($site->id));
}
```

- [ ] **Step 2: Run; expect FAIL.**

- [ ] **Step 3: Implement**

```php
<?php
declare(strict_types=1);

namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Models\Incident;
use Defyn\Dashboard\Models\Site;
use Defyn\Dashboard\Notify\EmailNotifier;
use Defyn\Dashboard\Notify\Notifier;
use Throwable;

final class IncidentService
{
    private const CONFIRM_THRESHOLD = 2;   // guardrail 1

    public function __construct(
        private readonly ?IncidentsRepository $incidents = null,
        private readonly ?SitesRepository $sites = null,
        private readonly ?Notifier $notifier = null,
        private readonly ?ActivityLogger $logger = null,
    ) {}

    public function recordFailure(Site $site, string $message): void
    {
        $incidents = $this->incidents ?? new IncidentsRepository();
        $sites     = $this->sites ?? new SitesRepository();
        $logger    = $this->logger ?? new ActivityLogger();
        $notifier  = $this->notifier ?? new EmailNotifier();

        $count = $sites->incrementConsecutiveFailures($site->id);
        if ($count < self::CONFIRM_THRESHOLD) {
            return;                                            // guardrail 1
        }
        if ($incidents->findOpenForSite($site->id) !== null) {
            return;                                            // guardrail 5
        }

        $now = gmdate('Y-m-d H:i:s');                          // guardrail 9
        $id  = $incidents->open($site->id, $now, $message);
        $incident = new Incident($id, $site->id, $now, null, null, $message, null, null, $now);

        $this->safeNotify(static fn () => $notifier->notifyDown($site, $incident));  // guardrail 6
        $incidents->markDownAlertSent($id, gmdate('Y-m-d H:i:s'));                   // guardrail 2
        $logger->log($site->userId, $site->id, 'site.incident_opened', [
            'incident_id' => $id, 'started_at' => $now, 'error' => $message,
        ]);
    }

    public function recordSuccess(Site $site): void
    {
        $incidents = $this->incidents ?? new IncidentsRepository();
        $sites     = $this->sites ?? new SitesRepository();
        $logger    = $this->logger ?? new ActivityLogger();
        $notifier  = $this->notifier ?? new EmailNotifier();

        $open = $incidents->findOpenForSite($site->id);
        if ($open !== null) {
            $endedAt  = gmdate('Y-m-d H:i:s');                                  // guardrail 9
            $duration = max(0, strtotime($endedAt . ' UTC') - strtotime($open->startedAt . ' UTC'));
            $incidents->close($open->id, $endedAt, $duration);
            $closed = new Incident($open->id, $site->id, $open->startedAt, $endedAt, $duration, $open->lastError, $open->downAlertSentAt, null, $open->createdAt);
            $this->safeNotify(static fn () => $notifier->notifyRecovered($site, $closed));  // guardrail 6
            $incidents->markUpAlertSent($open->id, gmdate('Y-m-d H:i:s'));                  // guardrail 2
            $logger->log($site->userId, $site->id, 'site.incident_closed', [
                'incident_id' => $open->id, 'duration_seconds' => $duration,
            ]);
        }
        $sites->resetConsecutiveFailures($site->id);                            // guardrail 4 — always
    }

    private function safeNotify(callable $fn): void
    {
        try { $fn(); } catch (Throwable $e) { error_log('[defyn] notify failed: ' . $e->getMessage()); }
    }
}
```

- [ ] **Step 4: Run; expect PASS.**
- [ ] **Step 5: Commit** — `feat(p3-1): IncidentService confirm-down state machine`.

---

## Task 7: Wire `IncidentService` into `HealthService`

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/HealthService.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/HealthServiceIncidentTest.php`

Guardrail 3: do NOT change the existing `markOffline`/`markRecovered`/`markContactAt` calls or the `site.health_*` events. Only ADD `IncidentService` calls.

- [ ] **Step 1: Failing test** — inject a fake `SignedHttpClient` that returns an error; ping a seeded active site twice → an incident opens on the 2nd; flip the client to 200; ping → incident closes. (Mirror the existing HealthService/SyncService test harness for stubbing the client.)

```php
public function test_two_failed_pings_open_incident_then_success_closes_it(): void
{
    $siteId = $this->seedActiveSite(1);
    $client = new FakeSignedHttpClient();                   // returns error by default
    $svc = new HealthService($client, null, null, new IncidentService());
    $svc->ping($siteId); $svc->ping($siteId);
    $this->assertNotNull((new IncidentsRepository())->findOpenForSite($siteId));
    $client->succeed();                                     // now returns 200
    $svc->ping($siteId);
    $this->assertNull((new IncidentsRepository())->findOpenForSite($siteId));
}
```

- [ ] **Step 2: Run; expect FAIL.**

- [ ] **Step 3: Implement** — add a 4th constructor param `private readonly ?IncidentService $incidents = null` to `HealthService`. In each of the three failure branches (after the existing `markOffline` + `site.health_failed` log), add `($this->incidents ?? new IncidentService())->recordFailure($site, $message);` (use the same `$message` var already in scope per branch). In BOTH success branches (the `markRecovered` and `markContactAt` paths, after the existing log), add `($this->incidents ?? new IncidentService())->recordSuccess($site);`. Keep everything else byte-for-byte.

- [ ] **Step 4: Run; expect PASS** + `vendor/bin/phpunit --filter HealthService` to confirm no regression in the existing health tests.
- [ ] **Step 5: Commit** — `feat(p3-1): HealthService opens/closes incidents on confirmed transitions`.

---

## Task 8: `GET /sites/{id}/incidents` controller + RateLimit + route

**Files:**
- Create: `packages/dashboard-plugin/src/Rest/SitesIncidentsController.php`
- Modify: `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php`
- Modify: `packages/dashboard-plugin/src/Rest/RestRouter.php`
- Test: `packages/dashboard-plugin/tests/Integration/Rest/SitesIncidentsTest.php`

Mirror `src/Rest/SitesThemesController.php` (GET, JWT auth, ownership check → 404, envelope) + the P2.9 `JobsListController` pagination. RateLimit: `const SITES_INCIDENTS_LIMIT = 30; const SITES_INCIDENTS_WINDOW = MINUTE_IN_SECONDS;` + a `sitesIncidents(WP_REST_Request $request)` static method (mirror `jobsList`). Route: `GET /defyn/v1/sites/(?P<id>\d+)/incidents`, `permission_callback` = JWT auth + `sitesIncidents` rate-limit; query params `limit` (default 20, max 100), `offset` (default 0).

- [ ] **Step 1: Failing test** — 200 envelope `{ data: { incidents: [...] }, error: null }` for the owner with seeded incidents (newest first); 401 no-auth; 404 non-owned site; respects `limit`/`offset`. Mirror `tests/Integration/Rest/SitesThemesTest.php` + `JobsListTest.php`.
- [ ] **Step 2: Run; expect FAIL.**
- [ ] **Step 3: Implement** the controller (resolve user from JWT; `SitesRepository::findById` + `userId` match else 404; read `limit`/`offset` with clamps; `IncidentsRepository::findForSite`; map `->toJson()`; return envelope). Add the RateLimit const + `sitesIncidents` method. Register the route in `RestRouter` next to the other `sites/{id}/...` GETs. Extend the CORS-allowed-routes regression test (mirror P2.9's 5-route addition) for the new route.
- [ ] **Step 4: Run; expect PASS.**
- [ ] **Step 5: Commit** — `feat(p3-1): GET /sites/{id}/incidents + 30/min RateLimit + route`.

---

## Task 9: `/overview` gains `open_incidents[]`

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/OverviewService.php`
- Test: extend `packages/dashboard-plugin/tests/Integration/Services/OverviewServiceTest.php`

- [ ] **Step 1: Failing test** — seed a user with one open incident; assert the composed array contains `open_incidents` = `[{ site_id, site_label, started_at }]`; assert `[]` when none. Mirror the existing OverviewService test.
- [ ] **Step 2: Run; expect FAIL.**
- [ ] **Step 3: Implement** — instantiate `IncidentsRepository` in `OverviewService`; add `'open_incidents' => $this->incidents->findOpenForUser($userId),` to the response array (alongside `sites_needing_attention`, `total_sites`).
- [ ] **Step 4: Run; expect PASS.**
- [ ] **Step 5: Commit** — `feat(p3-1): /overview emits open_incidents`.

---

## Task 10: Dashboard v0.10.0 bump + full PHP suite

**Files:** Modify `packages/dashboard-plugin/defyn-dashboard.php` (header `Version:` + any version const) + `packages/dashboard-plugin/readme.txt` (`Stable tag`).

- [ ] **Step 1:** Bump `0.9.0 → 0.10.0` in both files.
- [ ] **Step 2:** Run the full dashboard suite: `cd packages/dashboard-plugin && composer test`. Expected: green except the documented carry-forward constant-pin tests (V4/V5/V6/Uninstall baseline) + the new incident tests pass. Note: the schema-version-pin tests may need updating for v8 (they assert the constant) — update those constant assertions as part of this task.
- [ ] **Step 3: Commit** — `chore(p3-1): dashboard v0.10.0`.

---

## Task 11: SPA Zod `incidentSchema` + extended `overviewSchema` + MSW

**Files:**
- Modify: `apps/web/src/types/api.ts`
- Modify: `apps/web/src/test/handlers.ts`
- Test: `apps/web/tests/types/incidents.test.ts`

- [ ] **Step 1: Failing test**

```ts
import { describe, it, expect } from 'vitest';
import { incidentSchema, overviewSchema } from '@/types/api';

describe('incident schemas', () => {
  it('parses an open incident', () => {
    const r = incidentSchema.parse({ id: 1, site_id: 2, started_at: '2026-06-14 10:00:00', ended_at: null, duration_seconds: null, last_error: 'x', created_at: '2026-06-14 10:00:00' });
    expect(r.ended_at).toBeNull();
  });
});
```

(Plus an `overviewSchema.parse` test with `open_incidents: [{ site_id, site_label, started_at }]` using the existing overview fixture spread.)

- [ ] **Step 2: Run; expect FAIL.**
- [ ] **Step 3: Implement** in `types/api.ts`:

```ts
export const incidentSchema = z.object({
  id: z.number(),
  site_id: z.number(),
  started_at: z.string(),
  ended_at: z.string().nullable(),
  duration_seconds: z.number().nullable(),
  last_error: z.string().nullable(),
  created_at: z.string(),
});
export type Incident = z.infer<typeof incidentSchema>;

export const openIncidentSchema = z.object({
  site_id: z.number(),
  site_label: z.string(),
  started_at: z.string(),
});
export type OpenIncident = z.infer<typeof openIncidentSchema>;
```

  Add `open_incidents: z.array(openIncidentSchema)` to the existing `overviewSchema`. In `handlers.ts`: add `GET */wp-json/defyn/v1/sites/:id/incidents` → `{ data: { incidents: [] }, error: null }`; add `open_incidents: []` to the existing `/overview` handler payload.

- [ ] **Step 4: Run; expect PASS** (+ confirm no existing overview test breaks — the new field defaults to `[]` in the handler).
- [ ] **Step 5: Commit** — `feat(p3-1): SPA incident + overview schemas + MSW`.

---

## Task 12: `useSiteIncidents` query hook

**Files:**
- Create: `apps/web/src/lib/queries/useSiteIncidents.ts`
- Test: `apps/web/tests/lib/useSiteIncidents.test.tsx`

- [ ] **Step 1: Failing test** — render the hook under `QueryClientProvider` + MSW returning two incidents; assert it resolves to the parsed array (mirror `useSitePlugins.test`).
- [ ] **Step 2: Run; expect FAIL.**
- [ ] **Step 3: Implement** (mirror `useSitePlugins`):

```ts
import { useQuery } from '@tanstack/react-query';
import { z } from 'zod';
import { apiClient } from '@/lib/apiClient';
import { incidentSchema } from '@/types/api';

const responseSchema = z.object({ incidents: z.array(incidentSchema) });

export function useSiteIncidents(siteId: number) {
  return useQuery({
    queryKey: ['siteIncidents', siteId],
    queryFn: async () => {
      const data = await apiClient.get<unknown>(`/sites/${siteId}/incidents`);
      return responseSchema.parse(data).incidents;
    },
    staleTime: 30_000,
  });
}
```

- [ ] **Step 4: Run; expect PASS.**
- [ ] **Step 5: Commit** — `feat(p3-1): useSiteIncidents query hook`.

---

## Task 13: `IncidentHistoryPanel` (Site detail)

**Files:**
- Create: `apps/web/src/components/sites/IncidentHistoryPanel.tsx`
- Test: `apps/web/tests/components/sites/IncidentHistoryPanel.test.tsx`

- [ ] **Step 1: Failing test** — three render states via MSW-provided incidents (wrap in `QueryClientProvider`): (a) empty → "No incidents recorded"; (b) one ongoing (`ended_at: null`) → an "Ongoing" row with the start time; (c) one closed → start→end + humanized duration. Assert exact strings.
- [ ] **Step 2: Run; expect FAIL.**
- [ ] **Step 3: Implement** — `IncidentHistoryPanel({ siteId }: { siteId: number })`: `const { data, isLoading } = useSiteIncidents(siteId);` loading skeleton; if `data?.length === 0` show "No incidents recorded."; else a list — ongoing rows (`ended_at === null`) red-highlighted with `Ongoing — started {started_at}`, closed rows `{started_at} → {ended_at} · {humanizeDuration(duration_seconds)}`. Local `humanizeDuration(s: number)` mirrors the PHP one (`<60→${s}s`, `<3600→${Math.floor(s/60)}m`, else `${h}h ${m}m`). Use the card/section classes from `SitePluginsPanel`.
- [ ] **Step 4: Run; expect PASS.**
- [ ] **Step 5: Commit** — `feat(p3-1): IncidentHistoryPanel`.

---

## Task 14: `OpenIncidentsWidget` (Overview)

**Files:**
- Create: `apps/web/src/components/overview/OpenIncidentsWidget.tsx`
- Test: `apps/web/tests/components/overview/OpenIncidentsWidget.test.tsx`

Guardrail 8: renders nothing when `openIncidents` is empty.

- [ ] **Step 1: Failing test**

```tsx
import { render, screen } from '@testing-library/react';
import { OpenIncidentsWidget } from '@/components/overview/OpenIncidentsWidget';

it('renders a red rollup with each open incident when there are some', () => {
  render(<OpenIncidentsWidget openIncidents={[{ site_id: 2, site_label: 'AcmeBlog', started_at: '2026-06-14 10:00:00' }]} />);
  expect(screen.getByText(/1 site down/i)).toBeInTheDocument();
  expect(screen.getByText(/AcmeBlog/)).toBeInTheDocument();
});
it('renders nothing when there are no open incidents', () => {
  const { container } = render(<OpenIncidentsWidget openIncidents={[]} />);
  expect(container).toBeEmptyDOMElement();
});
```

- [ ] **Step 2: Run; expect FAIL.**
- [ ] **Step 3: Implement** — `OpenIncidentsWidget({ openIncidents }: { openIncidents: OpenIncident[] })`: `if (openIncidents.length === 0) return null;` (guardrail 8). Else a red-bordered card: header `{n} site{n === 1 ? '' : 's'} down`, then one line per incident `{site_label} — down since {started_at}`. Red styling `border-red-500 bg-red-50 text-red-700` (no shadcn destructive variant — same convention as the bulk dialogs). Import `OpenIncident` from `@/types/api`.
- [ ] **Step 4: Run; expect PASS.**
- [ ] **Step 5: Commit** — `feat(p3-1): OpenIncidentsWidget`.

---

## Task 15: Integrate panel + widget; full SPA suite green

**Files:**
- Modify: `apps/web/src/routes/SiteDetail.tsx`
- Modify: `apps/web/src/routes/Overview.tsx`

- [ ] **Step 1:** SiteDetail — import + mount `<IncidentHistoryPanel siteId={site.id} />` in the existing panel stack (after the core card, before the plugins panel, matching the spec's "Status · … · Incident history" ordering). Overview — import + mount `<OpenIncidentsWidget openIncidents={data.open_incidents} />` at the top of the page content (above the pending-updates widget) so a down site is seen first.
- [ ] **Step 2: tsc** — `cd apps/web && npx tsc --noEmit` → 0 errors.
- [ ] **Step 3: Full suite** — `pkill -9 -f vitest; npx vitest run --reporter=json --outputFile=/tmp/p3.json` (Node 22 via `.nvmrc`). Expected: green except the 4 documented carry-forwards (SiteDetail×2 + SiteCoreCard×2). **Any NEW failure or hang is a real bug to investigate** (P2.10 render-loop lesson — do not dismiss as environment; the suite runs fine under Node 22/24).
- [ ] **Step 4: Build** — `npm run build` → clean.
- [ ] **Step 5: Commit** — `feat(p3-1): mount IncidentHistoryPanel + OpenIncidentsWidget`.

---

## Task 16: Ship — dashboard zip, deploy, smoke, tag, MEMORY

- [ ] **Step 1: Build the dashboard zip** with the **symfony-preserving** exclusion list (MEMORY zip-build gotcha). `composer install --no-dev --classmap-authoritative` first; zip excluding ONLY: `tests/*`, `*wp-tests-config.php`, `.phpunit.result.cache`, `test-output.log`, `phpunit.xml`, `composer.lock`, `.github/*`, `.gitignore`. **NEVER** exclude any `vendor/*` subdir. Verify: `unzip -l dist/...zip | grep -E "deprecation-contracts/function\.php|polyfill-php83/bootstrap\.php"` → **2 lines**. Restore dev autoload (`composer install`).
- [ ] **Step 2: Build the SPA** — `cd apps/web && npm run build`.
- [ ] **Step 3: Push + merge** — `git push -u origin p3-1-monitoring`; `git checkout main && git merge --ff-only p3-1-monitoring && git push origin main` (Cloudflare auto-deploys SPA).
- [ ] **Step 4: Install dashboard on Kinsta** — WP Admin → Plugins → upload v0.10.0 zip → "Replace current with uploaded version" → **MyKinsta → Tools → Clear cache** (OPcache/Redis; required for new bytecode + schema self-heal). **Connector NOT touched.**
- [ ] **Step 5: Production smoke** (curl with admin JWT; UI-password entry prohibited, API curl allowed):
  - Schema v8: `GET /sites/{id}/incidents` returns a valid envelope (not 500) → table exists.
  - `GET /sites/{id}/incidents` → 200 owner / 401 no-auth / 404 non-owned.
  - `GET /overview` payload contains `open_incidents` (array).
  - Deployed SPA bundle (`assets/index-*.js`) contains "Incident history" / "site down".
  - If a connected site is reachable: drive 2 failed heartbeats (point heartbeat at a dead path) → incident opens + `wp_mail` logged; restore → closes + recovery mail logged. Else rely on the green PHP state-machine tests + a one-off `IncidentService` invocation via wp-cli eval.
- [ ] **Step 6: Tag** — `git tag -a p3-1-monitoring-complete -m "P3.1 — site monitoring: incidents + email alerts"`; `git push origin p3-1-monitoring-complete`.
- [ ] **Step 7: MEMORY** — append a concise P3.1-complete note to `project_defyn_roadmap.md` (Monitoring P3.1 shipped; schema v8; dashboard v0.10.0; connector unchanged) + a tight one-line MEMORY.md pointer touch-up. (MEMORY.md is over budget — keep it short.)

---

## Self-Review

**Spec coverage:** §1 goal → Tasks 6/7/13/14; §2 reuse → Task 7 (no new pinger); §3 scope-in → Tasks 1–15, scope-out → not built; §4 state machine → Task 6 (2-failure, one-per-edge, status untouched); §5 data model → Tasks 1/2; §6 services/notifier → Tasks 3/5/6/7; §7 activity events → Task 6; §8 REST → Tasks 8/9; §9 SPA → Tasks 11–15; §10 edges → Tasks 5/6/7 tests; §11 testing → every task; §12 release → Tasks 10/16; §13 guardrails → mapped inline. **All covered.**

**Placeholder scan:** No TBD/TODO; code blocks complete; mirror references name existing files + the exact constants to copy. ✓

**Type consistency:** `Incident` fields (`siteId`/`startedAt`/`endedAt`/`durationSeconds`/`lastError`/`downAlertSentAt`/`upAlertSentAt`/`createdAt`) consistent Tasks 2/3/6. `IncidentsRepository` methods (`findOpenForSite`/`open`/`close`/`markDownAlertSent`/`markUpAlertSent`/`findForSite`/`findOpenForUser`) consistent Tasks 3/6/8/9. `incidentSchema`/`openIncidentSchema`/`overviewSchema`/`OpenIncident` consistent Tasks 11/12/14. `consecutiveFailures`/`incrementConsecutiveFailures`/`resetConsecutiveFailures` consistent Tasks 4/6. ✓
