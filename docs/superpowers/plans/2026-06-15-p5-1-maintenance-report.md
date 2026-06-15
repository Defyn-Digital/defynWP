# P5.1 — Client Maintenance Report (MVP) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A per-site, date-ranged **maintenance report** (Overview · Updates · Uptime · Security) aggregated read-only from existing data, rendered as a print-styled SPA page the operator saves-as-PDF from the browser.

**Architecture:** One backend aggregator `ReportService::compose(siteId, userId, fromUtc, toUtc)` → one read endpoint `GET /sites/{id}/report?from&to` → a print-styled SPA route `/sites/:id/report`. Mirrors the `OverviewService`/`MonitoringService`/`SecurityService` pattern. No new tables, no connector change.

**Tech Stack:** PHP 8.1 dashboard plugin (PHPUnit/wp-phpunit), React 18 + TS + TanStack Query v5 + Zod + Vitest + MSW SPA (pnpm, Node 22). Connector untouched (v0.1.7). Schema v12 unchanged. Dashboard v0.16.0→v0.17.0.

**Spec:** `docs/superpowers/specs/2026-06-15-p5-1-maintenance-report-design.md`.
**Branch:** `p5-1-maintenance-report` (already created off `main` @ `6877a12`).

---

## Verified facts (do NOT re-derive — these are confirmed against the codebase)

- **Update events** (activity log, `created_at` = applied-at):
  - `plugin_update.succeeded` details `{slug, previous_version, new_version}`
  - `theme_update.succeeded` details `{slug, previous_version, new_version}`
  - `core_update.succeeded` details `{previous_version, new_version}` — **NO `slug`** (→ default slug `wordpress`, name `WordPress`). A separate `core_update.succeeded_no_change` event exists and is **deliberately excluded** (the event-type `IN(...)` filter only lists the three `.succeeded` types).
- **`MonitoringService::uptimePercent(array $incidents, int $windowStartTs, int $nowTs): float`** reads each incident as a **plain array** `['started' => int_epoch, 'ended' => int_epoch|null]` (NOT an `Incident` object, NOT `started_at` strings). Build it with `strtotime($startedAt . ' UTC')`. Pure function; reuse it, do NOT reuse `MonitoringService::compose`.
- **`ActivityLogRepository::insert(?int $userId, ?int $siteId, string $eventType, ?array $details = null, ?string $ipAddress = null): int`** — for test seeding.
- **`Incident`** model: readonly `id, siteId, startedAt, endedAt(?), durationSeconds(?), lastError(?), downAlertSentAt, upAlertSentAt, createdAt`; `fromRow` maps `started_at/ended_at/duration_seconds/last_error`.
- **`SiteVulnerabilitiesRepository::findForSite(int $siteId): array<SiteVulnerability>`** returns dismissed-enriched findings (each `->dismissed, ->severity, ->componentName, ->type, ->installedVersion, ->cve, ->title, ->fixedIn, ->toJson()`).
- **`Site`**: `->id, ->label, ->url, ->wpVersion, ->userId, ->lastSecurityScanAt`. `SitesRepository::findByIdForUser(int, int): ?Site`.
- **REST**: enveloped read = `new WP_REST_Response(['data'=>…, 'error'=>null], 200)`; errors = `ErrorResponse::create(int $status, string $code, string $msg)`. RateLimit buckets are fully inlined per-method returning `true|WP_Error`.
- PHP full suite: `COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit`; tolerate ONLY `UninstallTest::testUninstallDropsAllTables`. Baseline after P4.3b = 734 pass / 1.
- **Guardrail #15**: tests seeding `defyn_site_vulnerabilities` (explicit-COMMIT) MUST purge in `setUp` (`SET autocommit=1` + `DELETE FROM`). No `SitesRepository::create` — seed via `$wpdb->insert($wpdb->prefix.'defyn_sites', […])`. Copy the `seedSite` helper from `tests/Integration/Services/VulnerabilityScanAlertTest.php`.
- SPA: Node 22 (`.nvmrc` via fnm), `pnpm test`. Carry-forward 4: `tests/SiteDetail.test.tsx` ×2 + `tests/components/sites/SiteCoreCard.test.tsx` ×2. A RUN-then-hang vitest = a render loop (real bug, NOT env — the P2.10 lesson); keep `useMemo`/derived state, NO `useEffect` on fresh array refs.

---

## Task 1: `ActivityLogRepository` — two range query helpers

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/ActivityLogRepository.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/ActivityLogRepositoryRangeTest.php` (new)

Both helpers return rows as `list<array{event_type:string, details:array, created_at:string}>` — **details JSON-decoded in the repo** (so callers never re-decode).

- [ ] **Step 1: Write the failing test** `tests/Integration/Services/ActivityLogRepositoryRangeTest.php`:

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\ActivityLogRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class ActivityLogRepositoryRangeTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'defyn_activity_log');
    }

    public function testFindUpdatesForSiteInRangeFiltersByTypeSiteAndDate(): void
    {
        $repo = new ActivityLogRepository();
        $repo->insert(null, 7, 'plugin_update.succeeded', ['slug'=>'akismet','previous_version'=>'5.3','new_version'=>'5.4'], null);
        $this->insertAt($repo, 7, 'plugin_update.succeeded', ['slug'=>'old','previous_version'=>'1','new_version'=>'2'], '2020-01-01 00:00:00'); // out of range
        $repo->insert(null, 7, 'plugin_update.started', ['slug'=>'x'], null); // wrong type
        $repo->insert(null, 8, 'plugin_update.succeeded', ['slug'=>'other','previous_version'=>'1','new_version'=>'2'], null); // wrong site

        $rows = $repo->findUpdatesForSiteInRange(7, '2026-01-01 00:00:00', '2030-01-01 23:59:59');

        self::assertCount(1, $rows);
        self::assertSame('plugin_update.succeeded', $rows[0]['event_type']);
        self::assertSame('akismet', $rows[0]['details']['slug']);
        self::assertSame('5.4', $rows[0]['details']['new_version']);
    }

    public function testFindSecurityScansForSiteInRange(): void
    {
        $repo = new ActivityLogRepository();
        $repo->insert(null, 7, 'site.vulnerabilities_detected', ['total'=>2,'critical'=>0,'high'=>1,'medium'=>1,'low'=>0], null);
        $repo->insert(null, 7, 'site.new_vulnerabilities', ['new_count'=>1], null); // wrong type

        $rows = $repo->findSecurityScansForSiteInRange(7, '2026-01-01 00:00:00', '2030-01-01 23:59:59');

        self::assertCount(1, $rows);
        self::assertSame('site.vulnerabilities_detected', $rows[0]['event_type']);
        self::assertSame(2, $rows[0]['details']['total']);
    }

    private function insertAt(ActivityLogRepository $repo, int $siteId, string $type, array $details, string $createdAt): void
    {
        $id = $repo->insert(null, $siteId, $type, $details, null);
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->query($wpdb->prepare('UPDATE ' . $wpdb->prefix . 'defyn_activity_log SET created_at = %s WHERE id = %d', $createdAt, $id));
    }
}
```
(Confirm `insert` returns the row id (it does — `: int`) and sets `created_at` to "now". Confirm `AbstractSchemaTestCase` base matches sibling integration tests.)

- [ ] **Step 2: Run red** — `composer test:integration -- --filter ActivityLogRepositoryRangeTest` → FAIL (methods missing).

- [ ] **Step 3: Add the two methods to `src/Services/ActivityLogRepository.php`** (use the table name the file already references — match the existing methods):

```php
    /** @return list<array{event_type:string, details:array, created_at:string}> */
    public function findUpdatesForSiteInRange(int $siteId, string $fromUtc, string $toUtc): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'defyn_activity_log';
        // phpcs:ignore WordPress.DB.PreparedSQL
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT event_type, details, created_at FROM {$table}
             WHERE site_id = %d
               AND event_type IN ('plugin_update.succeeded','theme_update.succeeded','core_update.succeeded')
               AND created_at BETWEEN %s AND %s
             ORDER BY created_at DESC",
            $siteId, $fromUtc, $toUtc
        ), ARRAY_A) ?: [];

        return array_map(static fn (array $r): array => [
            'event_type' => (string) $r['event_type'],
            'details'    => is_string($r['details']) ? (json_decode($r['details'], true) ?: []) : [],
            'created_at' => (string) $r['created_at'],
        ], $rows);
    }

    /** @return list<array{event_type:string, details:array, created_at:string}> */
    public function findSecurityScansForSiteInRange(int $siteId, string $fromUtc, string $toUtc): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'defyn_activity_log';
        // phpcs:ignore WordPress.DB.PreparedSQL
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT event_type, details, created_at FROM {$table}
             WHERE site_id = %d AND event_type = 'site.vulnerabilities_detected'
               AND created_at BETWEEN %s AND %s
             ORDER BY created_at DESC",
            $siteId, $fromUtc, $toUtc
        ), ARRAY_A) ?: [];

        return array_map(static fn (array $r): array => [
            'event_type' => (string) $r['event_type'],
            'details'    => is_string($r['details']) ? (json_decode($r['details'], true) ?: []) : [],
            'created_at' => (string) $r['created_at'],
        ], $rows);
    }
```

- [ ] **Step 4: Run green** — `composer test:integration -- --filter ActivityLogRepositoryRangeTest` → PASS. Full suite → only `UninstallTest`.

- [ ] **Step 5: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP"
git add packages/dashboard-plugin/src/Services/ActivityLogRepository.php packages/dashboard-plugin/tests/Integration/Services/ActivityLogRepositoryRangeTest.php
git commit -m "feat(p5-1): ActivityLogRepository range helpers for updates + security scans"
```

---

## Task 2: `IncidentsRepository::findForSiteInRange`

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/IncidentsRepository.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/IncidentsRepositoryRangeTest.php` (new)

Returns `list<Incident>` for incidents OVERLAPPING `[from, to]`.

- [ ] **Step 1: Write the failing test**:

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\IncidentsRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class IncidentsRepositoryRangeTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->query('DELETE FROM ' . $wpdb->prefix . 'defyn_incidents');
    }

    public function testFindForSiteInRangeReturnsOverlappingIncidents(): void
    {
        $repo = new IncidentsRepository();
        $id1 = $repo->open(7, '2026-05-22 02:01:00', '502 Bad Gateway');
        $repo->close($id1, '2026-05-22 02:08:00', 420);                 // closed, in range
        $id2 = $repo->open(7, '2026-01-01 00:00:00', 'old');
        $repo->close($id2, '2026-01-01 00:05:00', 300);                 // before range, excluded
        $repo->open(7, '2026-05-30 10:00:00', '503');                   // ongoing, in range
        $repo->open(8, '2026-05-22 02:01:00', 'other');                 // other site, excluded

        $rows = $repo->findForSiteInRange(7, '2026-05-16 00:00:00', '2026-06-15 23:59:59');

        self::assertCount(2, $rows);
        self::assertSame('2026-05-30 10:00:00', $rows[0]->startedAt);   // started_at DESC → ongoing first
        self::assertNull($rows[0]->endedAt);
        self::assertSame('502 Bad Gateway', $rows[1]->lastError);
        self::assertSame(420, $rows[1]->durationSeconds);
    }
}
```

- [ ] **Step 2: Run red** — `composer test:integration -- --filter IncidentsRepositoryRangeTest` → FAIL.

- [ ] **Step 3: Add the method** to `src/Services/IncidentsRepository.php` (mirror the file's `findForSite`/`findForUserSince` style — `IncidentsTable::tableName()`, `Incident::fromRow`):

```php
    /** @return list<Incident> incidents overlapping [from,to], newest-started first. */
    public function findForSiteInRange(int $siteId, string $fromUtc, string $toUtc): array
    {
        global $wpdb;
        $table = IncidentsTable::tableName();
        // phpcs:ignore WordPress.DB.PreparedSQL
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `{$table}`
             WHERE site_id = %d AND started_at <= %s AND (ended_at IS NULL OR ended_at >= %s)
             ORDER BY started_at DESC",
            $siteId, $toUtc, $fromUtc
        ), ARRAY_A) ?: [];

        return array_map([Incident::class, 'fromRow'], $rows);
    }
```
(`use Defyn\Dashboard\Models\Incident;` + `use Defyn\Dashboard\Schema\IncidentsTable;` are already imported if `findForSite` returns Incidents.)

- [ ] **Step 4: Run green** — `composer test:integration -- --filter IncidentsRepositoryRangeTest` → PASS. Full suite → only `UninstallTest`.

- [ ] **Step 5: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP"
git add packages/dashboard-plugin/src/Services/IncidentsRepository.php packages/dashboard-plugin/tests/Integration/Services/IncidentsRepositoryRangeTest.php
git commit -m "feat(p5-1): IncidentsRepository::findForSiteInRange (overlap query)"
```

---

## Task 3: `ReportService::compose` — the aggregator

**Files:**
- Create: `packages/dashboard-plugin/src/Services/ReportService.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/ReportServiceTest.php` (new)

`compose(int $siteId, int $userId, string $fromUtc, string $toUtc): array` assembles all four sections. `fromUtc`/`toUtc` are full-datetime bounds (`'2026-05-16 00:00:00'` … `'2026-06-15 23:59:59'`) — the controller (Task 4) produces them. Constructor takes nullable-injected deps (the project convention).

- [ ] **Step 1: Write the failing test** `tests/Integration/Services/ReportServiceTest.php`. Seed a site + updates + a closed incident + a vuln snapshot + a `vulnerabilities_detected` event, then assert the composed shape. **Guardrail #15 purge in setUp.** Copy `seedSite` from `VulnerabilityScanAlertTest.php`.

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\ActivityLogRepository;
use Defyn\Dashboard\Services\IncidentsRepository;
use Defyn\Dashboard\Services\ReportService;
use Defyn\Dashboard\Services\SitePluginsRepository;
use Defyn\Dashboard\Services\SiteVulnerabilitiesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class ReportServiceTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL
        $wpdb->query('SET autocommit = 1');
        foreach (['defyn_sites','defyn_activity_log','defyn_incidents','defyn_site_vulnerabilities','defyn_site_plugins'] as $t) {
            $wpdb->query('DELETE FROM ' . $wpdb->prefix . $t);
        }
        // phpcs:enable WordPress.DB.PreparedSQL
    }

    public function testComposeAggregatesAllSections(): void
    {
        $siteId = $this->seedSite(1, 'https://acme.test', 'Acme', '6.9.4');
        $from = '2026-05-16 00:00:00';
        $to   = '2026-06-15 23:59:59';

        $log = new ActivityLogRepository();
        $log->insert(null, $siteId, 'plugin_update.succeeded', ['slug'=>'akismet','previous_version'=>'5.3','new_version'=>'5.4'], null);
        $log->insert(null, $siteId, 'core_update.succeeded', ['previous_version'=>'6.9.3','new_version'=>'6.9.4'], null);
        $log->insert(null, $siteId, 'site.vulnerabilities_detected', ['total'=>1,'critical'=>0,'high'=>1,'medium'=>0,'low'=>0], null);

        (new SitePluginsRepository())->replaceForSite($siteId, [
            ['slug'=>'akismet','name'=>'Akismet','version'=>'5.4','update_available'=>false,'update_version'=>null,'tested_up_to'=>null],
        ], '2026-06-15 00:00:00');

        $inc = new IncidentsRepository();
        $id = $inc->open($siteId, '2026-05-22 02:01:00', '502 Bad Gateway');
        $inc->close($id, '2026-05-22 02:08:00', 420);

        (new SiteVulnerabilitiesRepository())->replaceForSite($siteId, [
            ['type'=>'plugin','slug'=>'wp-file-manager','component_name'=>'WP File Manager','installed_version'=>'6.0',
             'severity'=>'high','cvss_score'=>null,'cve'=>null,'fixed_in'=>'6.9','title'=>'x','source_id'=>'src-wfm'],
        ], '2026-06-14 05:35:00');

        $report = (new ReportService())->compose($siteId, 1, $from, $to);

        self::assertSame(2, $report['overview']['updates_applied']);
        self::assertSame(1, $report['overview']['open_findings']);
        self::assertSame('6.9.4', $report['overview']['wp_version']);
        $names = array_column($report['updates'], 'component_name');
        self::assertContains('Akismet', $names);
        self::assertContains('WordPress', $names);
        self::assertCount(1, $report['uptime']['incidents']);
        self::assertSame('502 Bad Gateway', $report['uptime']['incidents'][0]['reason']);
        self::assertLessThan(100.0, $report['uptime']['range_percent']);
        self::assertCount(1, $report['security']['open_findings']);
        self::assertSame(1, $report['security']['severity_counts']['high']);
        self::assertCount(1, $report['security']['scans']);
        self::assertSame('2026-06-14 05:35:00', $report['security']['last_scan_at']);
    }

    private function seedSite(int $userId, string $url, string $label, string $wpVersion): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix.'defyn_sites', [
            'user_id'=>$userId,'url'=>$url,'label'=>$label,'status'=>'active','wp_version'=>$wpVersion,
            'last_security_scan_at'=>'2026-06-14 05:35:00',
            'created_at'=>gmdate('Y-m-d H:i:s'),'updated_at'=>gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $wpdb->insert_id;
    }
}
```
> **Verify** the `defyn_sites` insert columns: `wp_version` + `last_security_scan_at` are real (P2.4/P4.1). If `SitePluginsRepository::replaceForSite` row keys differ, copy the exact shape from `VulnerabilityScanAlertTest.php`.

- [ ] **Step 2: Run red** — `composer test:integration -- --filter ReportServiceTest` → FAIL (class missing).

- [ ] **Step 3: Create `src/Services/ReportService.php`**:

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Models\Incident;

/**
 * P5.1 — read-only maintenance-report aggregator. Pulls Overview + Updates + Uptime +
 * Security for one site over [fromUtc, toUtc] from existing data. No writes, no schema.
 */
final class ReportService
{
    public function __construct(
        private readonly ?SitesRepository $sites = null,
        private readonly ?ActivityLogRepository $activity = null,
        private readonly ?IncidentsRepository $incidents = null,
        private readonly ?SiteVulnerabilitiesRepository $findings = null,
        private readonly ?SitePluginsRepository $plugins = null,
        private readonly ?ThemesRepository $themes = null,
    ) {}

    /** @return array<string,mixed> */
    public function compose(int $siteId, int $userId, string $fromUtc, string $toUtc): array
    {
        $sites     = $this->sites ?? new SitesRepository();
        $activity  = $this->activity ?? new ActivityLogRepository();
        $incidents = $this->incidents ?? new IncidentsRepository();
        $findings  = $this->findings ?? new SiteVulnerabilitiesRepository();

        $site     = $sites->findByIdForUser($siteId, $userId); // controller already 404s on null
        $label    = $site?->label ?? '';
        $url      = $site?->url ?? '';
        $wpVer    = $site?->wpVersion ?? '';
        $lastScan = $site?->lastSecurityScanAt;

        $updates  = $this->buildUpdates($siteId, $fromUtc, $toUtc, $activity);
        $uptime   = $this->buildUptime($siteId, $fromUtc, $toUtc, $incidents);
        $security = $this->buildSecurity($siteId, $fromUtc, $toUtc, $findings, $activity, $lastScan);

        return [
            'site'   => ['id' => $siteId, 'label' => $label, 'url' => $url, 'wp_version' => $wpVer],
            'period' => ['from' => substr($fromUtc, 0, 10), 'to' => substr($toUtc, 0, 10)],
            'overview' => [
                'updates_applied'      => count($updates),
                'uptime_range_percent' => $uptime['range_percent'],
                'open_findings'        => count($security['open_findings']),
                'wp_version'           => $wpVer,
            ],
            'updates'  => $updates,
            'uptime'   => $uptime,
            'security' => $security,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function buildUpdates(int $siteId, string $fromUtc, string $toUtc, ActivityLogRepository $activity): array
    {
        $rows    = $activity->findUpdatesForSiteInRange($siteId, $fromUtc, $toUtc);
        $nameMap = $this->slugNameMap($siteId);

        $out = [];
        foreach ($rows as $r) {
            $type = match ($r['event_type']) {
                'plugin_update.succeeded' => 'plugin',
                'theme_update.succeeded'  => 'theme',
                default                   => 'core',
            };
            $slug = $type === 'core' ? 'wordpress' : (string) ($r['details']['slug'] ?? '');
            $name = $type === 'core' ? 'WordPress' : ($nameMap[$type][$slug] ?? ($slug !== '' ? $slug : 'Unknown'));
            $out[] = [
                'type'             => $type,
                'slug'             => $slug,
                'component_name'   => $name,
                'previous_version' => (string) ($r['details']['previous_version'] ?? ''),
                'new_version'      => (string) ($r['details']['new_version'] ?? ''),
                'applied_at'       => $r['created_at'],
            ];
        }
        return $out;
    }

    /** @return array{plugin:array<string,string>, theme:array<string,string>} */
    private function slugNameMap(int $siteId): array
    {
        $plugins = $this->plugins ?? new SitePluginsRepository();
        $themes  = $this->themes ?? new ThemesRepository();
        $map = ['plugin' => [], 'theme' => []];
        foreach ($plugins->findAllForSite($siteId) as $p) {
            $map['plugin'][$p->slug] = $p->name;
        }
        foreach ($themes->findAllForSite($siteId) as $t) {
            $map['theme'][$t->slug] = $t->name;
        }
        return $map;
    }

    /** @return array<string,mixed> */
    private function buildUptime(int $siteId, string $fromUtc, string $toUtc, IncidentsRepository $incidents): array
    {
        $now    = time();
        $fromTs = (int) strtotime($fromUtc . ' UTC');
        $toTs   = (int) strtotime($toUtc . ' UTC');
        // Load a window covering BOTH the report range AND the trailing 30d so the
        // 24h/7d/30d figures are accurate even for a past-month report.
        $loadFrom = gmdate('Y-m-d H:i:s', min($fromTs, $now - 31 * 86400));
        $loadTo   = gmdate('Y-m-d H:i:s', max($toTs, $now));
        $all      = $incidents->findForSiteInRange($siteId, $loadFrom, $loadTo);

        $epoch = array_map(static fn (Incident $i): array => [
            'started' => (int) strtotime($i->startedAt . ' UTC'),
            'ended'   => $i->endedAt !== null ? (int) strtotime($i->endedAt . ' UTC') : null,
        ], $all);

        $history = [];
        foreach ($all as $i) {
            $sTs = (int) strtotime($i->startedAt . ' UTC');
            $eTs = $i->endedAt !== null ? (int) strtotime($i->endedAt . ' UTC') : $now;
            if ($sTs <= $toTs && $eTs >= $fromTs) {
                $history[] = [
                    'started_at'       => $i->startedAt,
                    'ended_at'         => $i->endedAt,
                    'duration_seconds' => $i->durationSeconds,
                    'reason'           => $i->lastError,
                    'ongoing'          => $i->endedAt === null,
                ];
            }
        }

        return [
            'range_percent'    => MonitoringService::uptimePercent($epoch, $fromTs, $toTs),
            'last_24h_percent' => MonitoringService::uptimePercent($epoch, $now - 86400, $now),
            'last_7d_percent'  => MonitoringService::uptimePercent($epoch, $now - 7 * 86400, $now),
            'last_30d_percent' => MonitoringService::uptimePercent($epoch, $now - 30 * 86400, $now),
            'incidents'        => $history,
        ];
    }

    /** @return array<string,mixed> */
    private function buildSecurity(int $siteId, string $fromUtc, string $toUtc, SiteVulnerabilitiesRepository $findings, ActivityLogRepository $activity, ?string $lastScan): array
    {
        $open = array_values(array_filter($findings->findForSite($siteId), static fn ($v) => !$v->dismissed));
        $counts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        $openJson = [];
        foreach ($open as $v) {
            if (isset($counts[$v->severity])) {
                $counts[$v->severity]++;
            }
            $openJson[] = $v->toJson();
        }

        $scans = [];
        foreach ($activity->findSecurityScansForSiteInRange($siteId, $fromUtc, $toUtc) as $r) {
            $d = $r['details'];
            $scans[] = [
                'scanned_at' => $r['created_at'],
                'total'      => (int) ($d['total'] ?? 0),
                'critical'   => (int) ($d['critical'] ?? 0),
                'high'       => (int) ($d['high'] ?? 0),
                'medium'     => (int) ($d['medium'] ?? 0),
                'low'        => (int) ($d['low'] ?? 0),
            ];
        }

        return [
            'last_scan_at'    => $lastScan,
            'open_findings'   => $openJson,
            'severity_counts' => $counts,
            'scans'           => $scans,
        ];
    }
}
```
> **Verify before implementing:** `SitePluginsRepository::findAllForSite($siteId)` + `ThemesRepository::findAllForSite($siteId)` exist and return objects with `->slug` + `->name` (the P4.1 `VulnerabilityScanService` iterates `$plugins->findAllForSite($siteId)` reading `->slug`/`->name`/`->version`, so they do). If a method name differs, use the real one.

- [ ] **Step 4: Run green** — `composer test:integration -- --filter ReportServiceTest` → PASS. Full suite → only `UninstallTest`.

- [ ] **Step 5: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP"
git add packages/dashboard-plugin/src/Services/ReportService.php packages/dashboard-plugin/tests/Integration/Services/ReportServiceTest.php
git commit -m "feat(p5-1): ReportService::compose aggregates overview/updates/uptime/security"
```

---

## Task 4: `SitesReportController` + date validation + `RateLimit::siteReport` + route + CORS

**Files:**
- Modify: `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php`
- Create: `packages/dashboard-plugin/src/Rest/SitesReportController.php`
- Modify: `packages/dashboard-plugin/src/Rest/RestRouter.php`
- Test: `packages/dashboard-plugin/tests/Integration/Rest/SitesReportTest.php` (new)
- Test: `packages/dashboard-plugin/tests/Integration/Rest/SitesReportCorsTest.php` (new — copy `SecurityFleetCorsTest.php`)

- [ ] **Step 1: Add the rate-limit bucket** in `src/Rest/Middleware/RateLimit.php` — copy the EXACT inlined form of `siteVulnerabilities` (30/MIN), rename to `siteReport` with const `SITE_REPORT_LIMIT = 30`, key `defyn_rl_siteReport_%d_%d`, 429 code `report.rate_limited`, window `MINUTE_IN_SECONDS`. (Match the neighbour byte-for-byte; only the const name, key string, and 429 code change.)

- [ ] **Step 2: Write the failing controller test** `tests/Integration/Rest/SitesReportTest.php`. Copy the auth-dispatch + `$wpdb->insert` site-seeding harness from a sibling per-site REST test (e.g. the P4.1 vulnerabilities-GET test). Cover:
  - `test200EnvelopeWithDefaultRange`: GET owned site, no `from`/`to` → 200; `data.period` + `data.overview/updates/uptime/security` keys present.
  - `testExplicitRange200`: `?from=2026-05-16&to=2026-06-15` → 200; `data.period.from === '2026-05-16'`.
  - `testFromAfterToReturns400`: `?from=2026-06-15&to=2026-05-01` → 400 `report.invalid_range`.
  - `testMalformedDateReturns400`: `?from=not-a-date&to=2026-06-15` → 400 `report.invalid_range`.
  - `testRangeTooLargeReturns400`: `?from=2020-01-01&to=2026-01-01` → 400 `report.range_too_large`.
  - `testNonOwnedSiteReturns404`: site owned by another user → 404 `sites.not_found`.

- [ ] **Step 3: Run red** → FAIL.

- [ ] **Step 4: Create `src/Rest/SitesReportController.php`**:

```php
<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\ReportService;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P5.1 — GET /defyn/v1/sites/{id}/report?from=YYYY-MM-DD&to=YYYY-MM-DD
 * Read-only maintenance report over a date range. Defaults to the trailing 30 days.
 * Envelope: { data: {…}, error: null }.
 */
final class SitesReportController
{
    private const MAX_SPAN_DAYS = 366;

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');

        if ((new SitesRepository())->findByIdForUser($siteId, $userId) === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }

        $fromParam = $request->get_param('from');
        $toParam   = $request->get_param('to');

        if ($fromParam === null && $toParam === null) {
            $toDate   = gmdate('Y-m-d');
            $fromDate = gmdate('Y-m-d', time() - 30 * 86400);
        } else {
            $fromDate = $this->parseDate(is_string($fromParam) ? $fromParam : '');
            $toDate   = $this->parseDate(is_string($toParam) ? $toParam : '');
            if ($fromDate === null || $toDate === null) {
                return ErrorResponse::create(400, 'report.invalid_range', 'from/to must be valid YYYY-MM-DD dates.');
            }
        }

        if (strcmp($fromDate, $toDate) > 0) {
            return ErrorResponse::create(400, 'report.invalid_range', 'from must be on or before to.');
        }
        $spanDays = (strtotime($toDate . ' UTC') - strtotime($fromDate . ' UTC')) / 86400;
        if ($spanDays > self::MAX_SPAN_DAYS) {
            return ErrorResponse::create(400, 'report.range_too_large', 'Date range exceeds the maximum of 366 days.');
        }

        $report = (new ReportService())->compose($siteId, $userId, $fromDate . ' 00:00:00', $toDate . ' 23:59:59');
        return new WP_REST_Response(['data' => $report, 'error' => null], 200);
    }

    /** Strict YYYY-MM-DD; returns the normalized date or null. */
    private function parseDate(string $value): ?string
    {
        $d = \DateTime::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        if ($d === false || $d->format('Y-m-d') !== $value) {
            return null;
        }
        return $value;
    }
}
```

- [ ] **Step 5: Register the route** in `src/Rest/RestRouter.php` after the `/sites/{id}/vulnerabilities` GET (add the `use Defyn\Dashboard\Rest\SitesReportController;` import + the neighbour's exact callback idiom):

```php
        // P5.1 — GET /sites/{id}/report. Read-only maintenance report over a date range.
        register_rest_route(self::NAMESPACE, '/sites/(?P<id>\d+)/report', [
            'methods'             => 'GET',
            'callback'            => [new SitesReportController(), 'handle'],
            'permission_callback' => [RateLimit::class, 'siteReport'],
        ]);
```

- [ ] **Step 6: CORS test** — copy `tests/Integration/Rest/SecurityFleetCorsTest.php` → `SitesReportCorsTest.php`, route `/sites/1/report`, method `GET`.

- [ ] **Step 7: Run green** — `composer test:integration -- --filter "SitesReport"` → PASS. Full suite → only `UninstallTest`.

- [ ] **Step 8: Commit**

```bash
cd "/Users/pradeep/Local Sites/defynWP"
git add packages/dashboard-plugin/src/Rest/ packages/dashboard-plugin/tests/Integration/Rest/
git commit -m "feat(p5-1): GET /sites/{id}/report + date validation + 30/min bucket + CORS"
```

---

## Task 5: Dashboard v0.17.0 bump

**Files:** Modify `packages/dashboard-plugin/defyn-dashboard.php`.

- [ ] **Step 1:** change line 6 `* Version:           0.16.0` → `0.17.0` and line 46 `define('DEFYN_DASHBOARD_VERSION', '0.16.0');` → `'0.17.0'`.
- [ ] **Step 2:** `grep -rn "0\.16\.0" packages/dashboard-plugin/src packages/dashboard-plugin/tests` → expect no matches; `grep -n "0.17.0" packages/dashboard-plugin/defyn-dashboard.php` → 2 lines.
- [ ] **Step 3: Commit**
```bash
cd "/Users/pradeep/Local Sites/defynWP"
git add packages/dashboard-plugin/defyn-dashboard.php
git commit -m "chore(p5-1): bump dashboard plugin to v0.17.0"
```

---

## Task 6: SPA — `reportSchema` (Zod) + MSW + `useSiteReport` hook

**Files:**
- Modify: `apps/web/src/types/api.ts`
- Modify: MSW handlers (grep the mock server / handlers file)
- Create: `apps/web/src/lib/queries/useSiteReport.ts`
- Test: `apps/web/tests/useSiteReport.test.tsx` (new — mirror `tests/useSiteVulnerabilities.test.tsx`)

- [ ] **Step 1: Add the schemas** to `apps/web/src/types/api.ts` (reuse `vulnerabilitySchema` for `security.open_findings`):

```ts
export const reportUpdateSchema = z.object({
  type: z.enum(['plugin', 'theme', 'core']),
  slug: z.string(),
  component_name: z.string(),
  previous_version: z.string(),
  new_version: z.string(),
  applied_at: z.string(),
});
export const reportIncidentSchema = z.object({
  started_at: z.string(),
  ended_at: z.string().nullable(),
  duration_seconds: z.number().nullable(),
  reason: z.string().nullable(),
  ongoing: z.boolean(),
});
export const reportScanSchema = z.object({
  scanned_at: z.string(),
  total: z.number(), critical: z.number(), high: z.number(), medium: z.number(), low: z.number(),
});
export const reportSchema = z.object({
  site: z.object({ id: z.number(), label: z.string(), url: z.string(), wp_version: z.string() }),
  period: z.object({ from: z.string(), to: z.string() }),
  overview: z.object({
    updates_applied: z.number(),
    uptime_range_percent: z.number(),
    open_findings: z.number(),
    wp_version: z.string(),
  }),
  updates: z.array(reportUpdateSchema),
  uptime: z.object({
    range_percent: z.number(),
    last_24h_percent: z.number(),
    last_7d_percent: z.number(),
    last_30d_percent: z.number(),
    incidents: z.array(reportIncidentSchema),
  }),
  security: z.object({
    last_scan_at: z.string().nullable(),
    open_findings: z.array(vulnerabilitySchema),
    severity_counts: z.object({ critical: z.number(), high: z.number(), medium: z.number(), low: z.number() }),
    scans: z.array(reportScanSchema),
  }),
});
export type Report = z.infer<typeof reportSchema>;
```

- [ ] **Step 2: MSW handler** for `GET */sites/:id/report` returning a fixture satisfying `reportSchema` (one update, one incident, one finding, one scan).

- [ ] **Step 3: Create `apps/web/src/lib/queries/useSiteReport.ts`** (mirror `useSiteVulnerabilities`; parse the envelope `data` with `reportSchema`):

```ts
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { reportSchema, type Report } from '@/types/api';

export function useSiteReport(siteId: number, from: string, to: string) {
  return useQuery<Report>({
    queryKey: ['siteReport', siteId, from, to],
    queryFn: async () => {
      const res = await apiClient.get<{ data: unknown; error: null }>(
        `/sites/${siteId}/report?from=${from}&to=${to}`,
      );
      return reportSchema.parse(res.data);
    },
    enabled: Boolean(siteId) && Boolean(from) && Boolean(to),
  });
}
```
(Confirm `apiClient.get` envelope handling against `useSiteVulnerabilities` — if it returns the parsed body directly vs `{data}`, match it.)

- [ ] **Step 4: Test** `useSiteReport.test.tsx` — renderHook + MSW; assert it returns a parsed report with the 4 sections.

- [ ] **Step 5: Run green** — Node 22; `pnpm test -- --run useSiteReport`, then full `pnpm test` → only the 4 carry-forwards.

- [ ] **Step 6: Commit**
```bash
cd "/Users/pradeep/Local Sites/defynWP"
git add apps/web/src/types/api.ts apps/web/src/lib/queries/useSiteReport.ts apps/web/tests apps/web/src
git commit -m "feat(p5-1): SPA reportSchema + MSW + useSiteReport hook"
```

---

## Task 7: SPA — date-range presets helper

**Files:**
- Create: `apps/web/src/lib/reportRange.ts`
- Test: `apps/web/tests/reportRange.test.ts` (new)

Pure helpers computing `{from, to}` (`YYYY-MM-DD`) for presets — `now` passed in so they're clock-free unit tests.

- [ ] **Step 1: Write the failing test**:

```ts
import { describe, it, expect } from 'vitest';
import { presetRange } from '@/lib/reportRange';

describe('presetRange', () => {
  const now = new Date('2026-06-15T10:00:00Z');
  it('last30 → trailing 30 days', () => {
    expect(presetRange('last30', now)).toEqual({ from: '2026-05-16', to: '2026-06-15' });
  });
  it('thisMonth → 1st to today', () => {
    expect(presetRange('thisMonth', now)).toEqual({ from: '2026-06-01', to: '2026-06-15' });
  });
  it('lastMonth → full previous month', () => {
    expect(presetRange('lastMonth', now)).toEqual({ from: '2026-05-01', to: '2026-05-31' });
  });
});
```

- [ ] **Step 2: Run red** → FAIL.

- [ ] **Step 3: Implement** `apps/web/src/lib/reportRange.ts`:

```ts
export type RangePreset = 'last30' | 'thisMonth' | 'lastMonth';

function iso(d: Date): string {
  return d.toISOString().slice(0, 10);
}

export function presetRange(preset: RangePreset, now: Date = new Date()): { from: string; to: string } {
  const y = now.getUTCFullYear();
  const m = now.getUTCMonth();
  if (preset === 'thisMonth') {
    return { from: iso(new Date(Date.UTC(y, m, 1))), to: iso(now) };
  }
  if (preset === 'lastMonth') {
    const first = new Date(Date.UTC(y, m - 1, 1));
    const last = new Date(Date.UTC(y, m, 0)); // day 0 of this month = last day of prev month
    return { from: iso(first), to: iso(last) };
  }
  const from = new Date(now.getTime() - 30 * 86400_000); // last30 inclusive
  return { from: iso(from), to: iso(now) };
}
```

- [ ] **Step 4: Run green** → PASS.

- [ ] **Step 5: Commit**
```bash
cd "/Users/pradeep/Local Sites/defynWP"
git add apps/web/src/lib/reportRange.ts apps/web/tests/reportRange.test.ts
git commit -m "feat(p5-1): report date-range preset helper + tests"
```

---

## Task 8: SPA — report sections + `SiteReport` page + print CSS + entry button + route

**Files:**
- Create: `apps/web/src/pages/SiteReport.tsx`
- Create: `apps/web/src/components/report/ReportHeader.tsx`, `ReportOverview.tsx`, `ReportUpdates.tsx`, `ReportUptime.tsx`, `ReportSecurity.tsx`
- Create: `apps/web/src/lib/reportBranding.ts`
- Create: `apps/web/src/components/report/report-print.css` (or a print block in a global css)
- Modify: `apps/web/src/App.tsx` (route)
- Modify: the Site detail header (`apps/web/src/pages/SiteDetail.tsx` or its header component) — add a "Report" link
- Test: `apps/web/tests/SiteReport.test.tsx` (new)

Largest task — build page shell + date picker + print button (with the `window.print` spy test) first, then the five presentational sections each with an empty state.

- [ ] **Step 1: Write the failing page test** `apps/web/tests/SiteReport.test.tsx`. `vi.mock` `useSiteReport` to return a fixture with one item per section, plus a second case with empty arrays. Assert:
  - all four section headings render (Overview / Updates / Uptime / Security);
  - the updates table shows the component name + `A → B`;
  - the security section shows the finding;
  - clicking **"Print / Save as PDF"** calls `window.print` (`vi.spyOn(window, 'print').mockImplementation(() => {})`);
  - selecting the "Last month" preset updates the period (assert the displayed period text changes or the hook is called with the preset's from/to);
  - **empty states**: empty `updates`/`incidents`/`open_findings` → "No updates applied this period" / "No downtime this period" / "No open findings".

- [ ] **Step 2: Run red** → FAIL.

- [ ] **Step 3: Implement.**
  - `reportBranding.ts` — `export const REPORT_AGENCY_NAME = 'Defyn Digital';` + an accent colour token.
  - `SiteReport.tsx` — reads `:id`; holds `{from,to}` state (default `presetRange('last30')`); a preset selector + custom from/to inputs (set via `presetRange` or custom values); `useSiteReport(siteId, from, to)`; a **"Print / Save as PDF"** `<button onClick={() => window.print()}>`; loading/error states; renders the five sections. Derived data via `useMemo` (NO `useEffect` on fresh arrays — P2.10 guard). Wrap the picker + print button in a `report-controls` class.
  - `ReportHeader` — agency band (`REPORT_AGENCY_NAME` + accent + `report.site.url` + `period.from – period.to`).
  - `ReportOverview` — 4 stat cards (updates_applied, uptime_range_percent, open_findings, wp_version).
  - `ReportUpdates` — table (component_name · type · `previous_version → new_version` · applied_at date); empty → "No updates applied this period."
  - `ReportUptime` — `range_percent` headline + 24h/7d/30d cards + incident history (`reason` · duration · started_at; "ongoing" badge when `ongoing`); empty → "No downtime this period."
  - `ReportSecurity` — `last_scan_at` line + open findings grouped by severity (reuse the severity styling from `SiteSecurityPanel`) + scan-history mini table; empty → "No open findings."
  - `report-print.css` — `@media print { .app-nav, .report-controls { display: none !important } .report-section { break-inside: avoid } }` (inspect the real app-shell nav/sidebar class names and hide them; or wrap the report in `.report-root` and hide everything else). Import it in `SiteReport.tsx`.
  - **Route**: add `<Route path="/sites/:id/report" element={<SiteReport />} />` inside the `RequireAuth` outlet in `App.tsx`.
  - **Entry button**: on the Site detail header, add `<Link to={\`/sites/${id}/report\`}>Report</Link>` (or a `navigate` button), matching the header's existing button styling.

- [ ] **Step 4: Run green** — `pnpm test -- --run SiteReport` → PASS. Then FULL `pnpm test` → only the 4 carry-forwards. **If the run hangs, you introduced a render loop — bisect (a `useEffect`/`useMemo` keyed on a freshly-built array/object); do NOT dismiss as env.**

- [ ] **Step 5: Commit**
```bash
cd "/Users/pradeep/Local Sites/defynWP"
git add apps/web/src/pages/SiteReport.tsx apps/web/src/components/report/ apps/web/src/lib/reportBranding.ts apps/web/src/App.tsx apps/web/src/pages/SiteDetail.tsx apps/web/tests/SiteReport.test.tsx
git commit -m "feat(p5-1): SiteReport page — sections, date presets, print-to-PDF, entry button"
```
(Adjust the `git add` paths to the real SiteDetail file edited.)

---

## Task 9: Release — build, ship, smoke, tag, MEMORY

**Files:** build artifacts only.

- [ ] **Step 1: Full PHP suite** — `cd packages/dashboard-plugin && COMPOSER_PROCESS_TIMEOUT=0 vendor/bin/phpunit` → only `UninstallTest`.
- [ ] **Step 2: Full SPA suite** — `cd apps/web && pnpm test` (Node 22) → only the 4 carry-forwards.
- [ ] **Step 3: Build SPA** — `cd apps/web && pnpm build`.
- [ ] **Step 4: Build the dashboard zip** (symfony + json-machine preserving):
```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin"
composer install --no-dev --classmap-authoritative
cd "/Users/pradeep/Local Sites/defynWP/packages"
rm -f "/Users/pradeep/Local Sites/defynWP/dist/defyn-dashboard-0.17.0.zip"
mkdir -p "/Users/pradeep/Local Sites/defynWP/dist"
zip -rq "/Users/pradeep/Local Sites/defynWP/dist/defyn-dashboard-0.17.0.zip" dashboard-plugin \
  -x 'dashboard-plugin/tests/*' '*wp-tests-config.php' 'dashboard-plugin/.phpunit.result.cache' \
     'dashboard-plugin/test-output.log' 'dashboard-plugin/phpunit.xml' 'dashboard-plugin/composer.lock' \
     'dashboard-plugin/.github/*' 'dashboard-plugin/.gitignore'
cd "/Users/pradeep/Local Sites/defynWP"
unzip -l dist/defyn-dashboard-0.17.0.zip | grep -cE "deprecation-contracts/function\.php|polyfill-php83/bootstrap\.php"   # MUST be 2
unzip -l dist/defyn-dashboard-0.17.0.zip | grep -c "json-machine/src/Items\.php"                                          # MUST be >=1
unzip -p dist/defyn-dashboard-0.17.0.zip dashboard-plugin/defyn-dashboard.php | grep -m1 DEFYN_DASHBOARD_VERSION
cd packages/dashboard-plugin && composer install   # restore dev autoload
```
- [ ] **Step 5: Merge to main + push**
```bash
cd "/Users/pradeep/Local Sites/defynWP"
git checkout main && git merge --ff-only p5-1-maintenance-report && git push origin main
```
- [ ] **Step 6: Kinsta install (MANUAL USER STEP — flag it + pause for "installed").** Upload `dist/defyn-dashboard-0.17.0.zip` via WP Admin → Plugins → "Replace current with uploaded version" on `defynwp.defyn.agency`, then clear the MyKinsta cache. No schema migration (v12 unchanged).
- [ ] **Step 7: Production smoke (API curl only; login field `access_token`)** — after install:
  - `POST /auth/login` → `access_token`.
  - `GET /sites/1/report` (no-auth) → **401**.
  - `GET /sites/999999/report` (auth) → **404** `sites.not_found`.
  - `GET /sites/1/report?from=2026-06-15&to=2026-06-01` (auth) → **400** `report.invalid_range`.
  - `GET /sites/1/report?from=2020-01-01&to=2026-01-01` (auth) → **400** `report.range_too_large`.
  - SPA `/sites/:id/report` route serves 200.
  - (Happy populated report foreclosed by zero-sites + no-update-history prod state; covered by green tests.)
- [ ] **Step 8: Tag + push**
```bash
cd "/Users/pradeep/Local Sites/defynWP"
git tag p5-1-maintenance-report-complete && git push origin p5-1-maintenance-report-complete
```
- [ ] **Step 9: Update MEMORY** — append a P5.1-complete entry to `project_defyn_roadmap.md` (v0.17.0, tag, schema v12 unchanged, the read-only `ReportService` aggregator, the 4 sections, uptime-%-over-range, on-screen-print deliverable, Analytics/PageSpeed deferred, smoke results) + refresh the `MEMORY.md` index line. Set **NEXT = P5.2 (PDF generation + agency branding/white-label)**, then P5.3 (scheduled monthly email).

---

## Self-Review (completed during planning)

- **Spec coverage:** §5 payload → Tasks 1–3; §6 repo helpers → Tasks 1–2; §7 endpoint+validation+ratelimit+CORS → Task 4; §8 SPA schema/hook → Task 6, presets → Task 7, page/sections/print/entry → Task 8; §10 release → Tasks 5 + 9. ✅
- **Verified-facts applied:** `uptimePercent` epoch-array shape (`buildUptime` maps `{started,ended}` via `strtotime(... ' UTC')`); `core_update.succeeded` has no slug (defaults `wordpress`/`WordPress`); `core_update.succeeded_no_change` excluded by the event-type filter. ✅
- **Type consistency:** the report payload keys are identical across `ReportService` (Task 3), the controller envelope (Task 4), `reportSchema` (Task 6), and the section components (Task 8). The three repo range-helper signatures match across Tasks 1/2/3. `RateLimit::siteReport` consistent (Task 4). ✅
- **No schema/connector change**; no version-pin bumps (no schema constant touched). ✅
- **Open verifications flagged for the implementer** (cheap grep-confirms, not blockers): `SitePluginsRepository::findAllForSite`/`ThemesRepository::findAllForSite` names + `->slug`/`->name`; `apiClient.get` envelope handling; `defyn_sites` `wp_version` + `last_security_scan_at` columns (they exist — P2.4/P4.1); the SiteDetail header file path for the entry button.
```
