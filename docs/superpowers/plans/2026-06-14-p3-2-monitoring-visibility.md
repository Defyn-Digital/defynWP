# P3.2 — Monitoring: Performance & Uptime Visibility Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Capture per-ping response latency, derive per-site uptime-% from the existing incident history, and surface both on a dedicated `/monitoring` fleet page (summary strip + table) reached from the Overview header.

**Architecture:** Extends the existing 5-min `HealthService::ping` loop (times the existing signed `/heartbeat` call → `last_response_time_ms`). Uptime is *derived* (pure function over `wp_defyn_incidents`), never stored. One new read-only `GET /defyn/v1/monitoring` endpoint (mirrors `/overview`: direct payload, 30/min). One new SPA route. Connector untouched.

**Tech Stack:** PHP 8.1 dashboard plugin (PHPUnit/wp-phpunit, `$wpdb`, Action Scheduler), React 18 + TS + TanStack Query v5 + Zod + Vitest + MSW + Tailwind (pnpm, Node 22 via `apps/web/.nvmrc`).

**Spec:** `docs/superpowers/specs/2026-06-14-p3-2-monitoring-visibility-design.md` (commit `8ca01a1`). **Branch:** `p3-2-monitoring-visibility` (off `main` @ `0d17099`). Schema **v8 → v9**. Dashboard **v0.10.0 → v0.11.0**. Connector **unchanged** (v0.1.7).

**Carry-forward tolerated:** SPA `tests/SiteDetail.test.tsx` ×2 + `tests/components/sites/SiteCoreCard.test.tsx` ×2; PHP `UninstallTest::testUninstallDropsAllTables` (wp-phpunit TEMPORARY-TABLE infra limit). Any *other* new failure/hang is a real regression — the full SPA route suite must run green under Node 22 (P2.10 render-loop lesson: a hanging test is a real bug, not the environment).

**Subagents running SPA tests:** activate Node 22 first — `export FNM_DIR="$HOME/.fnm"; eval "$(fnm env --shell bash)"; fnm use 22`. `pkill -9 -f vitest` between runs; `timeout` is absent on macOS.

---

## Task 1: Schema v9 — `last_response_time_ms` column via self-heal

**Guardrail 10:** additive column via the established guarded ALTER (mirror `addConsecutiveFailuresColumn`); Uninstaller needs no change (column drops with the table). `createSql()` is **NOT** modified — all post-v1 columns are layered by guarded ALTERs that run on every `ensureSchema`/self-heal (this is the existing pattern; `consecutive_failures` and the core columns are not in `createSql` either).

**Files:**
- Modify: `packages/dashboard-plugin/src/Activation.php` (SCHEMA_VERSION line 27; new helper near line 226; call site near line 92)
- Create: `packages/dashboard-plugin/tests/Integration/Schema/ResponseTimeColumnTest.php`
- Modify (pin updates 8→9): `tests/Integration/Schema/SchemaVersionMigrationV4Test.php`, `V5Test.php`, `V6Test.php`, `V7Test.php`, `tests/Integration/Schema/IncidentsSchemaTest.php`

- [ ] **Step 1: Write the failing test** — `ResponseTimeColumnTest.php`. Mirror the setUp/bootstrap of the sibling `IncidentsSchemaTest.php` (same `AbstractSchemaTestCase` base + how it triggers activation).

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Schema;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * P3.2 — schema v9: last_response_time_ms on wp_defyn_sites (guarded ALTER).
 *
 * @group integration
 */
final class ResponseTimeColumnTest extends AbstractSchemaTestCase
{
    public function testSchemaVersionConstantIsNine(): void
    {
        self::assertSame(9, Activation::SCHEMA_VERSION);
    }

    public function testResponseTimeColumnExistsAfterEnsureSchema(): void
    {
        $this->freshlyActivate('defyn_sites');
        Activation::ensureSchema();

        global $wpdb;
        $table = SitesTable::tableName();
        $col = $wpdb->get_var($wpdb->prepare(
            "SHOW COLUMNS FROM `{$table}` LIKE %s",
            'last_response_time_ms'
        ));
        self::assertSame('last_response_time_ms', $col);
    }

    public function testGuardedAlterIsIdempotent(): void
    {
        $this->freshlyActivate('defyn_sites');
        Activation::ensureSchema();
        Activation::ensureSchema(); // second run must not error or duplicate

        global $wpdb;
        $table = SitesTable::tableName();
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_NAME = %s AND COLUMN_NAME = %s",
            $table,
            'last_response_time_ms'
        ));
        self::assertSame('1', (string) $count);
    }
}
```

- [ ] **Step 2: Run it — verify it fails**

Run: `cd packages/dashboard-plugin && composer test -- --filter ResponseTimeColumnTest`
Expected: FAIL (`assertSame(9, …)` gets 8; column absent).

- [ ] **Step 3: Bump the version + add the guarded ALTER**

In `src/Activation.php` line 27:
```php
    public const SCHEMA_VERSION = 9;
```

Add the helper (mirror `addConsecutiveFailuresColumn`, near line 238):
```php
    private static function addResponseTimeColumn(\wpdb $wpdb): void
    {
        $table  = SitesTable::tableName();
        $exists = $wpdb->get_var($wpdb->prepare(
            "SHOW COLUMNS FROM `{$table}` LIKE %s",
            'last_response_time_ms'
        ));
        if ($exists !== null) {
            return;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL — column DDL cannot be parameterized.
        $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN last_response_time_ms INT UNSIGNED NULL");
    }
```

Add the call in `ensureSchema()` immediately after the `addConsecutiveFailuresColumn` call (line ~92):
```php
        // P3.2 — add last_response_time_ms to wp_defyn_sites. Guarded ALTER.
        self::addResponseTimeColumn($wpdb);
```

- [ ] **Step 4: Update the 5 schema-version pin assertions 8 → 9**

In each of `SchemaVersionMigrationV4Test.php`, `V5Test.php`, `V6Test.php`, `V7Test.php`, and `IncidentsSchemaTest.php`, change every `assertSame(8, Activation::SCHEMA_VERSION)` to `assertSame(9, Activation::SCHEMA_VERSION)` (grep each file for `SCHEMA_VERSION` — there are 1–2 per file).

- [ ] **Step 5: Run the schema suite — verify green**

Run: `composer test -- --filter "ResponseTimeColumnTest|SchemaVersionMigration|IncidentsSchema"`
Expected: PASS (all version pins now 9; new column present + idempotent).

- [ ] **Step 6: Commit**

```bash
git add packages/dashboard-plugin/src/Activation.php packages/dashboard-plugin/tests/Integration/Schema/
git commit -m "feat(p3-2): schema v9 — last_response_time_ms column via guarded ALTER"
```

---

## Task 2: `Site` DTO — `lastResponseTimeMs` field

**Files:**
- Modify: `packages/dashboard-plugin/src/Models/Site.php` (constructor final param + `fromRow`)
- Test: `packages/dashboard-plugin/tests/Unit/Models/SiteTest.php` (create if absent; else add methods)

- [ ] **Step 1: Write the failing test**

```php
public function testFromRowMapsLastResponseTimeMs(): void
{
    $site = \Defyn\Dashboard\Models\Site::fromRow([
        'id' => 1, 'user_id' => 1, 'url' => 'https://a.test', 'label' => 'A',
        'status' => 'active', 'created_at' => '2026-06-14 00:00:00',
        'last_response_time_ms' => '247',
    ]);
    self::assertSame(247, $site->lastResponseTimeMs);
}

public function testFromRowDefaultsLastResponseTimeMsToNull(): void
{
    $site = \Defyn\Dashboard\Models\Site::fromRow([
        'id' => 1, 'user_id' => 1, 'url' => 'https://a.test', 'label' => 'A',
        'status' => 'active', 'created_at' => '2026-06-14 00:00:00',
    ]);
    self::assertNull($site->lastResponseTimeMs);
}
```

- [ ] **Step 2: Run it — verify it fails**

Run: `composer test -- --filter SiteTest`
Expected: FAIL (`lastResponseTimeMs` property undefined).

- [ ] **Step 3: Add the field + mapping**

In `src/Models/Site.php`, add as the **final** constructor param (after `consecutiveFailures`):
```php
        // P3.2 — last measured heartbeat round-trip (ms); null when last ping failed / never pinged.
        public readonly ?int    $lastResponseTimeMs = null,
```

In `fromRow`, add as the last mapped argument (after `consecutiveFailures:`):
```php
        lastResponseTimeMs: isset($row['last_response_time_ms']) ? (int) $row['last_response_time_ms'] : null,
```

- [ ] **Step 4: Run it — verify it passes**

Run: `composer test -- --filter SiteTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Models/Site.php packages/dashboard-plugin/tests/Unit/Models/SiteTest.php
git commit -m "feat(p3-2): Site DTO carries lastResponseTimeMs"
```

---

## Task 3: `SitesRepository::recordResponseTime`

**Guardrails 2 & 3:** a **new dedicated** method — do NOT touch `markContactAt` / `markRecovered` / `markOffline`. Persists `null` cleanly (explicit `= NULL` via prepared query, avoiding `$wpdb->update` null-handling ambiguity).

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/SitesRepository.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/SitesRepositoryTest.php` (add methods)

- [ ] **Step 1: Write the failing test**

```php
public function testRecordResponseTimeSetsAndNullsValue(): void
{
    $repo = new SitesRepository();
    $id = $repo->insertPending(
        userId: 1, url: 'https://rt.test', label: 'RT',
        ourPublicKey: 'pk', ourPrivateKeyEncrypted: 'enc',
    );

    $repo->recordResponseTime($id, 247);
    self::assertSame(247, $repo->findById($id)->lastResponseTimeMs);

    $repo->recordResponseTime($id, null);
    self::assertNull($repo->findById($id)->lastResponseTimeMs);
}
```

- [ ] **Step 2: Run it — verify it fails**

Run: `composer test -- --filter "SitesRepositoryTest::testRecordResponseTimeSetsAndNullsValue"`
Expected: FAIL (method undefined).

- [ ] **Step 3: Implement**

In `src/Services/SitesRepository.php` (near `markContactAt`):
```php
    /**
     * P3.2 — persist the last measured heartbeat round-trip. NULL on a failed
     * ping so a down site never shows a stale latency. Dedicated method:
     * the status-flip methods (markContactAt/markRecovered/markOffline) are
     * intentionally left untouched.
     */
    public function recordResponseTime(int $id, ?int $ms): void
    {
        global $wpdb;
        $table = SitesTable::tableName();
        $now   = gmdate('Y-m-d H:i:s');

        if ($ms === null) {
            // phpcs:ignore WordPress.DB.PreparedSQL — table name is a constant.
            $wpdb->query($wpdb->prepare(
                "UPDATE `{$table}` SET last_response_time_ms = NULL, updated_at = %s WHERE id = %d",
                $now,
                $id
            ));
            return;
        }

        $wpdb->update(
            $table,
            ['last_response_time_ms' => $ms, 'updated_at' => $now],
            ['id' => $id],
            ['%d', '%s'],
            ['%d'],
        );
    }
```

- [ ] **Step 4: Run it — verify it passes**

Run: `composer test -- --filter "SitesRepositoryTest::testRecordResponseTimeSetsAndNullsValue"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Services/SitesRepository.php packages/dashboard-plugin/tests/Integration/Services/SitesRepositoryTest.php
git commit -m "feat(p3-2): SitesRepository::recordResponseTime (null on failure)"
```

---

## Task 4: `HealthService::ping` — latency capture

**Guardrails 1, 2, 3:** time the **existing** `signedGet` call (no 2nd request); persist `$elapsedMs` on both success branches, `null` on every failure branch; status flips + `site.health_*` events + `IncidentService` calls stay byte-for-byte unchanged (existing `HealthServiceTest` + `HealthServiceIncidentTest` MUST stay green).

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/HealthService.php` (`ping`, lines 44–104)
- Test: `packages/dashboard-plugin/tests/Integration/Services/HealthServiceTest.php` (add methods, mirror existing harness)

- [ ] **Step 1: Write the failing tests** (append to `HealthServiceTest.php`; reuse its `makeActiveSite()` + `MockHttpClient` pattern)

```php
public function testSuccessfulPingRecordsResponseTime(): void
{
    $siteId = $this->makeActiveSite();

    $mock = new MockHttpClient(fn () => new MockResponse(
        json_encode(['ok' => true, 'server_time' => time()]),
        ['http_code' => 200],
    ));

    (new HealthService(new SignedHttpClient($mock)))->ping($siteId);

    $site = (new SitesRepository())->findById($siteId);
    self::assertNotNull($site->lastResponseTimeMs);
    self::assertGreaterThanOrEqual(0, $site->lastResponseTimeMs);
}

public function testFailedPingNullsResponseTime(): void
{
    $siteId = $this->makeActiveSite();
    (new SitesRepository())->recordResponseTime($siteId, 123); // prior value to prove it clears

    $mock = new MockHttpClient(function () {
        throw new TransportException('host unreachable');
    });

    (new HealthService(new SignedHttpClient($mock)))->ping($siteId);

    $site = (new SitesRepository())->findById($siteId);
    self::assertNull($site->lastResponseTimeMs);
}
```

- [ ] **Step 2: Run them — verify they fail**

Run: `composer test -- --filter "HealthServiceTest::testSuccessfulPingRecordsResponseTime|HealthServiceTest::testFailedPingNullsResponseTime"`
Expected: FAIL (latency never persisted → success test gets null; failure test still sees 123).

- [ ] **Step 3: Wire latency into `ping`**

In `src/Services/HealthService.php`, time the existing call (line ~79):
```php
        $startedAt = microtime(true);
        $response  = $this->httpClient->signedGet($url, $privateKey, $canonicalPath);
        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
```

Then add the persistence call in **each** branch (additive — do not alter the existing lines):

- In the two **pre-`signedGet` failure** branches (missing key ~line 60, decrypt failure ~line 73), before each `return;`:
```php
            $repo->recordResponseTime($siteId, null);
```
- In the **transport-error** branch (~line 85) and the **non-2xx** branch (~line 92), before each `return;`:
```php
            $repo->recordResponseTime($siteId, null);
```
- In **both success** branches — after `markRecovered`/`recordSuccess` (~line 98) and after `markContactAt`/`recordSuccess` (~line 102):
```php
            $repo->recordResponseTime($siteId, $elapsedMs);
```

(`$repo` is already resolved at the top of `ping` as `$this->repo ?? new SitesRepository()`.)

- [ ] **Step 4: Run the full HealthService + Incident suites — verify green**

Run: `composer test -- --filter "HealthService"`
Expected: PASS — the 2 new tests plus the existing `HealthServiceTest` (3) and `HealthServiceIncidentTest` all green (status flips/events unchanged).

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Services/HealthService.php packages/dashboard-plugin/tests/Integration/Services/HealthServiceTest.php
git commit -m "feat(p3-2): HealthService captures heartbeat latency (null on failure)"
```

---

## Task 5: `MonitoringService::uptimePercent` — pure function

**Guardrails 4, 5:** derived-not-stored; pure (UTC integer timestamps, no DB); no incidents → 100.0; open incident ticks to now; pre-window incident counts only its in-window portion; clamp `[0,100]`; zero-length window guard.

**Files:**
- Create: `packages/dashboard-plugin/src/Services/MonitoringService.php`
- Create: `packages/dashboard-plugin/tests/Unit/Services/MonitoringServiceUptimeTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Unit\Services;

use Defyn\Dashboard\Services\MonitoringService;
use PHPUnit\Framework\TestCase;

final class MonitoringServiceUptimeTest extends TestCase
{
    private const NOW = 1_000_000;          // arbitrary epoch
    private const WINDOW_START = 991_360;   // NOW - 8640 (a 2.4h window for easy maths)

    public function testNoIncidentsIsHundred(): void
    {
        self::assertSame(100.0, MonitoringService::uptimePercent([], self::WINDOW_START, self::NOW));
    }

    public function testZeroLengthWindowIsHundred(): void
    {
        self::assertSame(100.0, MonitoringService::uptimePercent([], self::NOW, self::NOW));
    }

    public function testOneFullyInWindowClosedIncident(): void
    {
        // 864s down inside an 8640s window = 10% down = 90% up.
        $incidents = [['started' => self::NOW - 5000, 'ended' => self::NOW - 4136]];
        self::assertSame(90.0, MonitoringService::uptimePercent($incidents, self::WINDOW_START, self::NOW));
    }

    public function testOpenIncidentCountsToNow(): void
    {
        // open incident started 864s ago, still open → 10% down.
        $incidents = [['started' => self::NOW - 864, 'ended' => null]];
        self::assertSame(90.0, MonitoringService::uptimePercent($incidents, self::WINDOW_START, self::NOW));
    }

    public function testIncidentStartingBeforeWindowCountsOnlyInWindowPortion(): void
    {
        // started 4320s before window start, ended at windowStart+864 → only 864s in-window.
        $incidents = [['started' => self::WINDOW_START - 4320, 'ended' => self::WINDOW_START + 864]];
        self::assertSame(90.0, MonitoringService::uptimePercent($incidents, self::WINDOW_START, self::NOW));
    }

    public function testMultipleOverlappingAccumulate(): void
    {
        $incidents = [
            ['started' => self::NOW - 5000, 'ended' => self::NOW - 4568], // 432s
            ['started' => self::NOW - 2000, 'ended' => self::NOW - 1568], // 432s
        ];
        // 864s down / 8640 = 10% → 90% up.
        self::assertSame(90.0, MonitoringService::uptimePercent($incidents, self::WINDOW_START, self::NOW));
    }

    public function testClampsToZeroWhenDowntimeExceedsWindow(): void
    {
        $incidents = [['started' => self::WINDOW_START - 100_000, 'ended' => null]];
        self::assertSame(0.0, MonitoringService::uptimePercent($incidents, self::WINDOW_START, self::NOW));
    }
}
```

- [ ] **Step 2: Run them — verify they fail**

Run: `composer test -- --filter MonitoringServiceUptimeTest`
Expected: FAIL (class not found).

- [ ] **Step 3: Implement the pure function**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

/**
 * P3.2 — composes the /monitoring fleet payload. Uptime is DERIVED from the
 * incident history (never stored): uptimePercent is a pure function over UTC
 * integer timestamps.
 */
final class MonitoringService
{
    /**
     * Uptime-% over [windowStartTs, nowTs] given incidents overlapping it.
     *
     * @param array<int,array{started:int,ended:?int}> $incidents ended=null → still open (down to now)
     */
    public static function uptimePercent(array $incidents, int $windowStartTs, int $nowTs): float
    {
        $window = $nowTs - $windowStartTs;
        if ($window <= 0) {
            return 100.0;
        }

        $downtime = 0;
        foreach ($incidents as $incident) {
            $start = (int) $incident['started'];
            $end   = $incident['ended'] !== null ? (int) $incident['ended'] : $nowTs;
            $overlap = min($end, $nowTs) - max($start, $windowStartTs);
            if ($overlap > 0) {
                $downtime += $overlap;
            }
        }

        $pct = (1 - $downtime / $window) * 100;
        return round(max(0.0, min(100.0, $pct)), 2);
    }
}
```

- [ ] **Step 4: Run them — verify they pass**

Run: `composer test -- --filter MonitoringServiceUptimeTest`
Expected: PASS (all 7).

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Services/MonitoringService.php packages/dashboard-plugin/tests/Unit/Services/MonitoringServiceUptimeTest.php
git commit -m "feat(p3-2): MonitoringService::uptimePercent pure function"
```

---

## Task 6: `IncidentsRepository::findForUserSince`

**Guardrail 6:** ONE query for all the user's sites; `MonitoringService` groups in PHP (no N+1). Fetches incidents overlapping the last-30-days window: open (`ended_at IS NULL`) OR ended on/after `$sinceUtc`. Mirrors `findOpenForUser`'s JOIN shape.

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/IncidentsRepository.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/IncidentsRepositoryTest.php` (add method)

- [ ] **Step 1: Write the failing test** (mirror the file's existing incident-seeding helpers)

```php
public function testFindForUserSinceReturnsOverlappingAndOpenIncidents(): void
{
    $repo  = new IncidentsRepository();
    $sites = new SitesRepository();

    $mine   = $sites->insertPending(userId: 1, url: 'https://m.test', label: 'M', ourPublicKey: 'p', ourPrivateKeyEncrypted: 'e');
    $other  = $sites->insertPending(userId: 2, url: 'https://o.test', label: 'O', ourPublicKey: 'p', ourPrivateKeyEncrypted: 'e');

    // in-window closed (ended yesterday)
    $a = $repo->open($mine, gmdate('Y-m-d H:i:s', time() - 90_000), 'boom');
    $repo->close($a, gmdate('Y-m-d H:i:s', time() - 86_000), 4000);
    // open (ongoing)
    $repo->open($mine, gmdate('Y-m-d H:i:s', time() - 600), 'still down');
    // closed BEFORE the window (40 days ago) — must be excluded
    $c = $repo->open($mine, gmdate('Y-m-d H:i:s', time() - 3_500_000), 'old');
    $repo->close($c, gmdate('Y-m-d H:i:s', time() - 3_400_000), 100000);
    // another user's open incident — must be excluded
    $repo->open($other, gmdate('Y-m-d H:i:s', time() - 600), 'theirs');

    $since = gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS);
    $rows  = $repo->findForUserSince(1, $since);

    self::assertCount(2, $rows);
    foreach ($rows as $r) {
        self::assertSame($mine, $r['site_id']);
        self::assertArrayHasKey('started_at', $r);
        self::assertArrayHasKey('ended_at', $r);
    }
}
```

- [ ] **Step 2: Run it — verify it fails**

Run: `composer test -- --filter "IncidentsRepositoryTest::testFindForUserSinceReturnsOverlappingAndOpenIncidents"`
Expected: FAIL (method undefined).

- [ ] **Step 3: Implement** (mirror `findOpenForUser`)

```php
    /**
     * P3.2 — all incidents for the user's sites overlapping [since, now]:
     * still-open OR ended on/after $sinceUtc. One query; grouped by site in PHP.
     *
     * @return array<int,array{site_id:int,started_at:string,ended_at:?string}>
     */
    public function findForUserSince(int $userId, string $sinceUtc): array
    {
        global $wpdb;
        $i = IncidentsTable::tableName();
        $s = SitesTable::tableName();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT i.site_id AS site_id, i.started_at AS started_at, i.ended_at AS ended_at
             FROM `{$i}` i INNER JOIN `{$s}` s ON s.id = i.site_id
             WHERE s.user_id = %d AND (i.ended_at IS NULL OR i.ended_at >= %s)
             ORDER BY i.started_at ASC",
            $userId,
            $sinceUtc
        ), ARRAY_A) ?: [];

        return array_map(static fn ($r) => [
            'site_id'    => (int) $r['site_id'],
            'started_at' => (string) $r['started_at'],
            'ended_at'   => $r['ended_at'] !== null ? (string) $r['ended_at'] : null,
        ], $rows);
    }
```

- [ ] **Step 4: Run it — verify it passes**

Run: `composer test -- --filter "IncidentsRepositoryTest::testFindForUserSinceReturnsOverlappingAndOpenIncidents"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Services/IncidentsRepository.php packages/dashboard-plugin/tests/Integration/Services/IncidentsRepositoryTest.php
git commit -m "feat(p3-2): IncidentsRepository::findForUserSince (single overlap query)"
```

---

## Task 7: `MonitoringService::compose` — fleet payload

**Guardrails 5, 6, 9:** one incidents query grouped in PHP; `up`=active, `down`=offline (may sum < total when pending/error sites exist); `fleet_uptime_30d` = mean of per-site uptime_30d (null when total===0); `slowest_ms` = max latency (nulls excluded, null when none); UTC throughout.

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/MonitoringService.php`
- Create: `packages/dashboard-plugin/tests/Integration/Services/MonitoringServiceComposeTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\IncidentsRepository;
use Defyn\Dashboard\Services\MonitoringService;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/** @group integration */
final class MonitoringServiceComposeTest extends AbstractSchemaTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_sites');
        $this->freshlyActivate('defyn_incidents');
    }

    public function testComposeBuildsSummaryAndSites(): void
    {
        $sites = new SitesRepository();
        $incidents = new IncidentsRepository();

        $up   = $sites->insertPending(userId: 1, url: 'https://up.test', label: 'Up', ourPublicKey: 'p', ourPrivateKeyEncrypted: 'e');
        $sites->markActive($up, 'pk');
        $sites->recordResponseTime($up, 200);

        $down = $sites->insertPending(userId: 1, url: 'https://down.test', label: 'Down', ourPublicKey: 'p', ourPrivateKeyEncrypted: 'e');
        $sites->markOffline($down, 'boom');
        $sites->recordResponseTime($down, null);
        $incidents->open($down, gmdate('Y-m-d H:i:s', time() - 600), 'boom'); // open

        $payload = (new MonitoringService())->compose(1);

        self::assertSame(2, $payload['summary']['total']);
        self::assertSame(1, $payload['summary']['up']);
        self::assertSame(1, $payload['summary']['down']);
        self::assertSame(200, $payload['summary']['slowest_ms']);
        self::assertNotNull($payload['summary']['fleet_uptime_30d']);
        self::assertCount(2, $payload['sites']);

        $downRow = array_values(array_filter($payload['sites'], fn ($s) => $s['site_id'] === $down))[0];
        self::assertSame('offline', $downRow['status']);
        self::assertNull($downRow['last_response_time_ms']);
        self::assertNotNull($downRow['open_incident_started_at']);
        self::assertLessThan(100.0, $downRow['uptime_7d']);
    }

    public function testComposeEmptyFleetNullsAggregates(): void
    {
        $payload = (new MonitoringService())->compose(999);
        self::assertSame(0, $payload['summary']['total']);
        self::assertNull($payload['summary']['fleet_uptime_30d']);
        self::assertNull($payload['summary']['slowest_ms']);
        self::assertSame([], $payload['sites']);
    }
}
```

- [ ] **Step 2: Run it — verify it fails**

Run: `composer test -- --filter MonitoringServiceComposeTest`
Expected: FAIL (`compose` undefined).

- [ ] **Step 3: Implement `compose`** (add to `MonitoringService`)

```php
    public function compose(int $userId): array
    {
        $now         = time();
        $sevenStart  = $now - 7 * DAY_IN_SECONDS;
        $thirtyStart = $now - 30 * DAY_IN_SECONDS;
        $sinceUtc    = gmdate('Y-m-d H:i:s', $thirtyStart);

        $sites        = (new SitesRepository())->findAllForUser($userId);
        $incidentRows = (new IncidentsRepository())->findForUserSince($userId, $sinceUtc);

        // Group incidents by site, converting UTC strings → epoch (forced UTC).
        $bySite = [];
        foreach ($incidentRows as $r) {
            $bySite[$r['site_id']][] = [
                'started'     => strtotime($r['started_at'] . ' UTC'),
                'ended'       => $r['ended_at'] !== null ? strtotime($r['ended_at'] . ' UTC') : null,
                'started_raw' => $r['started_at'],
            ];
        }

        $siteOut = [];
        $up = 0;
        $down = 0;
        $uptime30Sum = 0.0;
        $slowest = null;

        foreach ($sites as $site) {
            $incidents = $bySite[$site->id] ?? [];

            if ($site->status === 'active') {
                $up++;
            } elseif ($site->status === 'offline') {
                $down++;
            }

            $u7  = self::uptimePercent($incidents, $sevenStart, $now);
            $u30 = self::uptimePercent($incidents, $thirtyStart, $now);
            $uptime30Sum += $u30;

            $openStarted = null;
            foreach ($incidents as $inc) {
                if ($inc['ended'] === null) {
                    $openStarted = $inc['started_raw'];
                    break;
                }
            }

            $ms = $site->lastResponseTimeMs;
            if ($ms !== null && ($slowest === null || $ms > $slowest)) {
                $slowest = $ms;
            }

            $siteOut[] = [
                'site_id'                  => $site->id,
                'label'                    => $site->label,
                'url'                      => $site->url,
                'status'                   => $site->status,
                'last_response_time_ms'    => $ms,
                'last_contact_at'          => $site->lastContactAt,
                'uptime_7d'                => $u7,
                'uptime_30d'               => $u30,
                'open_incident_started_at' => $openStarted,
            ];
        }

        $total = count($sites);

        return [
            'summary' => [
                'total'            => $total,
                'up'               => $up,
                'down'             => $down,
                'fleet_uptime_30d' => $total > 0 ? round($uptime30Sum / $total, 2) : null,
                'slowest_ms'       => $slowest,
            ],
            'sites'        => $siteOut,
            'generated_at' => gmdate('Y-m-d H:i:s', $now),
        ];
    }
```

- [ ] **Step 4: Run it — verify it passes**

Run: `composer test -- --filter MonitoringServiceComposeTest`
Expected: PASS (both).

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Services/MonitoringService.php packages/dashboard-plugin/tests/Integration/Services/MonitoringServiceComposeTest.php
git commit -m "feat(p3-2): MonitoringService::compose fleet payload"
```

---

## Task 8: `RateLimit::monitoring` + `MonitoringController` + route + CORS

**Guardrail 7:** read-only, 30/min, ownership-scoped (each site in `findAllForUser` is the user's own), mirrors `overview`.

**Files:**
- Modify: `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php` (constants + method)
- Create: `packages/dashboard-plugin/src/Rest/MonitoringController.php`
- Modify: `packages/dashboard-plugin/src/Rest/RestRouter.php` (register route)
- Create: `packages/dashboard-plugin/tests/Integration/Rest/MonitoringCorsTest.php`
- Test: `packages/dashboard-plugin/tests/Integration/Rest/RateLimitTest.php` (add a monitoring bucket test, mirror the overview one)

- [ ] **Step 1: Write the failing CORS + rate-limit tests**

`MonitoringCorsTest.php` (mirror `OverviewCorsTest.php`):
```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Rest\Middleware\Cors;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;
use WP_REST_Response;

/** @group integration */
final class MonitoringCorsTest extends AbstractSchemaTestCase
{
    public function testCorsHeadersOnMonitoringRoute(): void
    {
        $response = new WP_REST_Response(['ok' => true], 200);
        $request  = new WP_REST_Request('GET', '/defyn/v1/monitoring');
        $server   = rest_get_server();

        $served = Cors::apply(false, $response, $request, $server);

        $headers = $response->get_headers();
        self::assertArrayHasKey('Access-Control-Allow-Origin', $headers);
        self::assertSame(DEFYN_SPA_ORIGIN, $headers['Access-Control-Allow-Origin']);
        self::assertSame('true', $headers['Access-Control-Allow-Credentials']);
        self::assertSame(false, $served);
    }
}
```

In `RateLimitTest.php` (mirror the overview rate-limit test, e.g. `testOverview…`):
```php
public function testMonitoringRateLimitTripsAfterThirtyFirstCall(): void
{
    $request = new WP_REST_Request('GET', '/defyn/v1/monitoring');
    $request->set_param('_authenticated_user_id', 1);

    for ($i = 0; $i < 30; $i++) {
        self::assertTrue(\Defyn\Dashboard\Rest\Middleware\RateLimit::monitoring($request));
    }
    $result = \Defyn\Dashboard\Rest\Middleware\RateLimit::monitoring($request);
    self::assertInstanceOf(\WP_Error::class, $result);
    self::assertSame(429, $result->get_error_data()['status']);
}
```
(If the existing overview rate-limit test stubs `RequireAuth::check`, mirror that stub exactly.)

- [ ] **Step 2: Run them — verify they fail**

Run: `composer test -- --filter "MonitoringCorsTest|testMonitoringRateLimit"`
Expected: FAIL (`RateLimit::monitoring` undefined; route absent).

- [ ] **Step 3: Add the rate-limit bucket**

In `src/Rest/Middleware/RateLimit.php` constants (near line 80):
```php
    public const MONITORING_LIMIT  = 30;
    public const MONITORING_WINDOW = MINUTE_IN_SECONDS;
```
Add the method (mirror `overview`):
```php
    public static function monitoring(WP_REST_Request $request)
    {
        $authResult = RequireAuth::check($request);
        if (is_wp_error($authResult)) {
            return $authResult;
        }

        $userId = (int) $request->get_param('_authenticated_user_id');

        $key   = sprintf('defyn_rl_monitoring_%d', $userId);
        $count = (int) (get_transient($key) ?: 0);

        if ($count >= self::MONITORING_LIMIT) {
            return new \WP_Error(
                'monitoring.rate_limited',
                'Too many requests. The monitoring page polls every minute — try again shortly.',
                ['status' => 429]
            );
        }

        set_transient($key, $count + 1, self::MONITORING_WINDOW);
        return true;
    }
```

- [ ] **Step 4: Add the controller**

`src/Rest/MonitoringController.php`:
```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Services\MonitoringService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P3.2 — GET /defyn/v1/monitoring. Read-only fleet uptime/latency view.
 * Mirrors OverviewController: direct payload, 30/min bucket.
 */
final class MonitoringController
{
    public function __construct(
        private readonly MonitoringService $service = new MonitoringService(),
    ) {}

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        return new WP_REST_Response($this->service->compose($userId), 200);
    }
}
```

- [ ] **Step 5: Register the route**

In `src/Rest/RestRouter.php` (near the `/overview` registration):
```php
        register_rest_route(self::NAMESPACE, '/monitoring', [
            'methods'             => 'GET',
            'callback'            => [new MonitoringController(), 'handle'],
            'permission_callback' => [RateLimit::class, 'monitoring'],
        ]);
```
Add `use Defyn\Dashboard\Rest\MonitoringController;` if the file uses per-class imports (check the top of RestRouter.php — it imports the other controllers).

- [ ] **Step 6: Run — verify green**

Run: `composer test -- --filter "MonitoringCorsTest|testMonitoringRateLimit"`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add packages/dashboard-plugin/src/Rest/ packages/dashboard-plugin/tests/Integration/Rest/MonitoringCorsTest.php packages/dashboard-plugin/tests/Integration/Rest/RateLimitTest.php
git commit -m "feat(p3-2): GET /monitoring endpoint + 30/min RateLimit::monitoring + CORS"
```

---

## Task 9: Dashboard v0.11.0 version bump

**Files:**
- Modify: `packages/dashboard-plugin/defyn-dashboard.php` (line 6 `Version:`, line 23 `DEFYN_DASHBOARD_VERSION`)
- Modify: `packages/dashboard-plugin/composer.json` (line 2 `"version"`)
- Modify: `packages/dashboard-plugin/readme.txt` (line 4 `Stable tag`)

- [ ] **Step 1: Bump all four occurrences** `0.10.0` → `0.11.0`:
  - `defyn-dashboard.php:6` → ` * Version:           0.11.0`
  - `defyn-dashboard.php:23` → `define('DEFYN_DASHBOARD_VERSION', '0.11.0');`
  - `composer.json:2` → `    "version": "0.11.0",`
  - `readme.txt:4` → `Stable tag: 0.11.0`

- [ ] **Step 2: Verify the whole PHP suite is green** (carry-forward = 1 UninstallTest infra fail only)

Run: `cd packages/dashboard-plugin && composer test 2>&1 | tail -25`
Expected: all green except the single `UninstallTest::testUninstallDropsAllTables` infra carry-forward.

- [ ] **Step 3: Commit**

```bash
git add packages/dashboard-plugin/defyn-dashboard.php packages/dashboard-plugin/composer.json packages/dashboard-plugin/readme.txt
git commit -m "chore(p3-2): bump dashboard to v0.11.0"
```

---

## Task 10: SPA — `monitoringSchema` (Zod) + MSW handler

**Files:**
- Modify: `apps/web/src/types/api.ts`
- Modify: `apps/web/src/test/handlers.ts`
- Test: `apps/web/src/types/__tests__/monitoring.test.ts` (create; place alongside existing schema tests — check where api schema tests live and match)

- [ ] **Step 1: Write the failing schema test**

```ts
import { describe, it, expect } from 'vitest';
import { monitoringSchema } from '@/types/api';

const valid = {
  summary: { total: 2, up: 1, down: 1, fleet_uptime_30d: 99.71, slowest_ms: 910 },
  sites: [
    {
      site_id: 2, label: 'SmartCoding', url: 'https://x.test', status: 'offline',
      last_response_time_ms: null, last_contact_at: '2026-06-14 03:11:00',
      uptime_7d: 97.1, uptime_30d: 99.4, open_incident_started_at: '2026-06-14 03:23:00',
    },
  ],
  generated_at: '2026-06-14 03:35:00',
};

describe('monitoringSchema', () => {
  it('parses a full payload', () => {
    expect(monitoringSchema.parse(valid).summary.slowest_ms).toBe(910);
  });
  it('accepts null aggregates and null latency', () => {
    const empty = { summary: { total: 0, up: 0, down: 0, fleet_uptime_30d: null, slowest_ms: null }, sites: [], generated_at: 'x' };
    expect(monitoringSchema.parse(empty).sites).toHaveLength(0);
  });
});
```

- [ ] **Step 2: Run it — verify it fails**

Run: `cd apps/web && pnpm vitest run src/types/__tests__/monitoring.test.ts`
Expected: FAIL (`monitoringSchema` not exported).

- [ ] **Step 3: Add the schemas** (append to `src/types/api.ts`)

```ts
// P3.2 — Monitoring fleet page.
export const monitoringSiteSchema = z.object({
  site_id: z.number().int(),
  label: z.string(),
  url: z.string(),
  status: siteStatusSchema,
  last_response_time_ms: z.number().int().nullable(),
  last_contact_at: z.string().nullable(),
  uptime_7d: z.number(),
  uptime_30d: z.number(),
  open_incident_started_at: z.string().nullable(),
});
export type MonitoringSite = z.infer<typeof monitoringSiteSchema>;

export const monitoringSchema = z.object({
  summary: z.object({
    total: z.number().int().nonnegative(),
    up: z.number().int().nonnegative(),
    down: z.number().int().nonnegative(),
    fleet_uptime_30d: z.number().nullable(),
    slowest_ms: z.number().int().nullable(),
  }),
  sites: z.array(monitoringSiteSchema),
  generated_at: z.string(),
});
export type Monitoring = z.infer<typeof monitoringSchema>;
```

- [ ] **Step 4: Add the MSW handler** (in `src/test/handlers.ts`, beside the `/overview` handler)

```ts
// P3.2 — GET /monitoring — empty fleet by default; tests override via server.use().
http.get('*/wp-json/defyn/v1/monitoring', () => {
  return HttpResponse.json({
    summary: { total: 0, up: 0, down: 0, fleet_uptime_30d: null, slowest_ms: null },
    sites: [],
    generated_at: '2026-06-14 03:35:00',
  });
}),
```

- [ ] **Step 5: Run it — verify it passes**

Run: `pnpm vitest run src/types/__tests__/monitoring.test.ts`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/types/api.ts apps/web/src/test/handlers.ts apps/web/src/types/__tests__/monitoring.test.ts
git commit -m "feat(p3-2): monitoringSchema + MSW handler"
```

---

## Task 11: SPA — `useMonitoring` query hook

**Files:**
- Create: `apps/web/src/lib/queries/useMonitoring.ts`
- Test: `apps/web/src/lib/queries/__tests__/useMonitoring.test.tsx` (mirror the existing `useOverview` hook test; if none exists, a render-hook test with the QueryClient provider)

- [ ] **Step 1: Write the failing test** (mirror the existing `useOverview`/`useSiteIncidents` hook test setup — QueryClientProvider wrapper + MSW)

```tsx
import { describe, it, expect } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { http, HttpResponse } from 'msw';
import { server } from '@/test/server';
import { useMonitoring } from '@/lib/queries/useMonitoring';

function wrapper({ children }: { children: React.ReactNode }) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

describe('useMonitoring', () => {
  it('fetches and parses the monitoring payload', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/monitoring', () =>
        HttpResponse.json({
          summary: { total: 1, up: 1, down: 0, fleet_uptime_30d: 100, slowest_ms: 200 },
          sites: [{ site_id: 1, label: 'A', url: 'https://a.test', status: 'active', last_response_time_ms: 200, last_contact_at: '2026-06-14 03:00:00', uptime_7d: 100, uptime_30d: 100, open_incident_started_at: null }],
          generated_at: '2026-06-14 03:35:00',
        }),
      ),
    );
    const { result } = renderHook(() => useMonitoring(), { wrapper });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data?.summary.total).toBe(1);
  });
});
```

(Match the import paths/test-server helper the existing hook tests use — verify `@/test/server` vs inline server.)

- [ ] **Step 2: Run it — verify it fails**

Run: `pnpm vitest run src/lib/queries/__tests__/useMonitoring.test.tsx`
Expected: FAIL (hook missing).

- [ ] **Step 3: Implement** (mirror `useOverview` — raw body parsed directly)

```ts
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { monitoringSchema } from '@/types/api';

export function useMonitoring() {
  return useQuery({
    queryKey: ['monitoring'],
    queryFn: async () => {
      const data = await apiClient.get<unknown>('/monitoring');
      return monitoringSchema.parse(data);
    },
    refetchInterval: 30_000,
    refetchIntervalInBackground: false,
  });
}
```

- [ ] **Step 4: Run it — verify it passes**

Run: `pnpm vitest run src/lib/queries/__tests__/useMonitoring.test.tsx`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/lib/queries/useMonitoring.ts apps/web/src/lib/queries/__tests__/useMonitoring.test.tsx
git commit -m "feat(p3-2): useMonitoring query hook (30s poll)"
```

---

## Task 12: SPA — pure helpers (`latencyTone`, `formatUptime`, `minutesSince`, `parseUtc`)

**Files:**
- Create: `apps/web/src/lib/monitoring.ts`
- Test: `apps/web/src/lib/__tests__/monitoring.test.ts`

- [ ] **Step 1: Write the failing tests**

```ts
import { describe, it, expect } from 'vitest';
import { latencyTone, formatUptime, parseUtc, minutesSince } from '@/lib/monitoring';

describe('latencyTone', () => {
  it.each([
    [null, 'bad'], [120, 'good'], [299, 'good'], [300, 'warn'], [799, 'warn'], [800, 'bad'], [2000, 'bad'],
  ])('latencyTone(%s) = %s', (ms, tone) => {
    expect(latencyTone(ms as number | null)).toBe(tone);
  });
});

describe('formatUptime', () => {
  it('renders 2dp percent', () => {
    expect(formatUptime(99.7)).toBe('99.70%');
    expect(formatUptime(100)).toBe('100.00%');
  });
});

describe('parseUtc + minutesSince', () => {
  it('parses a space-separated UTC string', () => {
    expect(parseUtc('2026-06-14 03:00:00').getTime()).toBe(Date.UTC(2026, 5, 14, 3, 0, 0));
  });
  it('computes whole minutes since', () => {
    const now = new Date(Date.UTC(2026, 5, 14, 3, 12, 0));
    expect(minutesSince('2026-06-14 03:00:00', now)).toBe(12);
  });
});
```

- [ ] **Step 2: Run them — verify they fail**

Run: `pnpm vitest run src/lib/__tests__/monitoring.test.ts`
Expected: FAIL (module missing).

- [ ] **Step 3: Implement**

```ts
export const LATENCY_GOOD_MS = 300;
export const LATENCY_WARN_MS = 800;

export type LatencyTone = 'good' | 'warn' | 'bad';

export function latencyTone(ms: number | null): LatencyTone {
  if (ms === null) return 'bad';
  if (ms < LATENCY_GOOD_MS) return 'good';
  if (ms < LATENCY_WARN_MS) return 'warn';
  return 'bad';
}

export function formatUptime(pct: number): string {
  return `${pct.toFixed(2)}%`;
}

/** Backend timestamps are UTC "YYYY-MM-DD HH:MM:SS" (no zone). Force UTC. */
export function parseUtc(s: string): Date {
  return new Date(s.replace(' ', 'T') + 'Z');
}

export function minutesSince(s: string, now: Date = new Date()): number {
  return Math.max(0, Math.floor((now.getTime() - parseUtc(s).getTime()) / 60000));
}
```

- [ ] **Step 4: Run them — verify they pass**

Run: `pnpm vitest run src/lib/__tests__/monitoring.test.ts`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/lib/monitoring.ts apps/web/src/lib/__tests__/monitoring.test.ts
git commit -m "feat(p3-2): monitoring SPA helpers (latencyTone, formatUptime, minutesSince)"
```

---

## Task 13: SPA — `MonitoringSummaryStrip`

**Guardrail 9:** null `fleet_uptime_30d` / `slowest_ms` render `—`; Down tile red when `down > 0`.

**Files:**
- Create: `apps/web/src/components/monitoring/MonitoringSummaryStrip.tsx`
- Test: `apps/web/src/components/monitoring/__tests__/MonitoringSummaryStrip.test.tsx`

- [ ] **Step 1: Write the failing test**

```tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MonitoringSummaryStrip } from '@/components/monitoring/MonitoringSummaryStrip';

describe('MonitoringSummaryStrip', () => {
  it('renders KPIs and dashes nulls', () => {
    render(<MonitoringSummaryStrip summary={{ total: 3, up: 2, down: 0, fleet_uptime_30d: null, slowest_ms: null }} />);
    expect(screen.getByText('Fleet 30d').parentElement).toHaveTextContent('—');
    expect(screen.getByText('Up').parentElement).toHaveTextContent('2');
  });

  it('marks the down tile red when down > 0', () => {
    render(<MonitoringSummaryStrip summary={{ total: 3, up: 2, down: 1, fleet_uptime_30d: 99.7, slowest_ms: 900 }} />);
    expect(screen.getByTestId('kpi-down')).toHaveClass('text-red-600');
  });
});
```

- [ ] **Step 2: Run it — verify it fails**

Run: `pnpm vitest run src/components/monitoring/__tests__/MonitoringSummaryStrip.test.tsx`
Expected: FAIL (component missing).

- [ ] **Step 3: Implement** (match the existing Tailwind/zinc style of Overview widgets)

```tsx
import type { Monitoring } from '@/types/api';
import { formatUptime } from '@/lib/monitoring';

interface Props {
  summary: Monitoring['summary'];
}

export function MonitoringSummaryStrip({ summary }: Props) {
  const tiles = [
    { label: 'Up', value: String(summary.up), tone: 'text-zinc-900', testid: 'kpi-up' },
    { label: 'Down', value: String(summary.down), tone: summary.down > 0 ? 'text-red-600' : 'text-zinc-900', testid: 'kpi-down' },
    { label: 'Fleet 30d', value: summary.fleet_uptime_30d === null ? '—' : formatUptime(summary.fleet_uptime_30d), tone: 'text-zinc-900', testid: 'kpi-fleet' },
    { label: 'Slowest', value: summary.slowest_ms === null ? '—' : `${summary.slowest_ms}ms`, tone: 'text-zinc-900', testid: 'kpi-slowest' },
  ];

  return (
    <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
      {tiles.map((t) => (
        <div key={t.label} className="rounded-lg border border-zinc-200 p-4">
          <div data-testid={t.testid} className={`text-2xl font-semibold ${t.tone}`}>{t.value}</div>
          <div className="mt-1 text-xs uppercase tracking-wide text-zinc-500">{t.label}</div>
        </div>
      ))}
    </div>
  );
}
```

- [ ] **Step 4: Run it — verify it passes**

Run: `pnpm vitest run src/components/monitoring/__tests__/MonitoringSummaryStrip.test.tsx`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/components/monitoring/MonitoringSummaryStrip.tsx apps/web/src/components/monitoring/__tests__/MonitoringSummaryStrip.test.tsx
git commit -m "feat(p3-2): MonitoringSummaryStrip"
```

---

## Task 14: SPA — `MonitoringRow` + `MonitoringTable`

**Files:**
- Create: `apps/web/src/components/monitoring/MonitoringRow.tsx`
- Create: `apps/web/src/components/monitoring/MonitoringTable.tsx`
- Test: `apps/web/src/components/monitoring/__tests__/MonitoringRow.test.tsx`

- [ ] **Step 1: Write the failing test** (rows render inside a router + table)

```tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { MonitoringTable } from '@/components/monitoring/MonitoringTable';
import type { MonitoringSite } from '@/types/api';

const site = (over: Partial<MonitoringSite>): MonitoringSite => ({
  site_id: 1, label: 'Acme', url: 'https://acme.test', status: 'active',
  last_response_time_ms: 247, last_contact_at: '2026-06-14 03:00:00',
  uptime_7d: 100, uptime_30d: 99.82, open_incident_started_at: null, ...over,
});

describe('MonitoringRow', () => {
  it('renders latency and links to site detail', () => {
    render(<MemoryRouter><MonitoringTable sites={[site({})]} /></MemoryRouter>);
    expect(screen.getByText('247ms')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Acme/ })).toHaveAttribute('href', '/sites/1');
  });

  it('shows "down Xm" for an open incident and — for null latency', () => {
    const now = new Date();
    const started = new Date(now.getTime() - 12 * 60000).toISOString().slice(0, 19).replace('T', ' ');
    render(<MemoryRouter><MonitoringTable sites={[site({ status: 'offline', last_response_time_ms: null, open_incident_started_at: started })]} /></MemoryRouter>);
    expect(screen.getByText('—')).toBeInTheDocument();
    expect(screen.getByText(/down 12m/)).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run it — verify it fails**

Run: `pnpm vitest run src/components/monitoring/__tests__/MonitoringRow.test.tsx`
Expected: FAIL (components missing).

- [ ] **Step 3: Implement `MonitoringRow.tsx`**

```tsx
import { Link } from 'react-router-dom';
import type { MonitoringSite } from '@/types/api';
import { latencyTone, formatUptime, minutesSince } from '@/lib/monitoring';

const DOT: Record<string, string> = {
  active: 'bg-green-500',
  offline: 'bg-red-500',
  pending: 'bg-zinc-300',
  error: 'bg-amber-500',
};

const LAT_CLASS = { good: 'text-green-600', warn: 'text-amber-600', bad: 'text-red-600' };

export function MonitoringRow({ site }: { site: MonitoringSite }) {
  const lastCheck = site.open_incident_started_at
    ? `down ${minutesSince(site.open_incident_started_at)}m`
    : site.last_contact_at
      ? `${minutesSince(site.last_contact_at)}m ago`
      : '—';

  return (
    <tr className="border-b border-zinc-100">
      <td className="py-2 pl-1 pr-3">
        <span className={`inline-block h-2 w-2 rounded-full ${DOT[site.status] ?? 'bg-zinc-300'}`} />
      </td>
      <td className="py-2 pr-3">
        <Link to={`/sites/${site.site_id}`} className="text-zinc-900 hover:underline">{site.label}</Link>
      </td>
      <td className={`py-2 pr-3 tabular-nums ${LAT_CLASS[latencyTone(site.last_response_time_ms)]}`}>
        {site.last_response_time_ms === null ? '—' : `${site.last_response_time_ms}ms`}
      </td>
      <td className="py-2 pr-3 tabular-nums text-zinc-700">{formatUptime(site.uptime_7d)}</td>
      <td className="py-2 pr-3 tabular-nums text-zinc-700">{formatUptime(site.uptime_30d)}</td>
      <td className="py-2 pr-1 text-zinc-500">{lastCheck}</td>
    </tr>
  );
}
```

Implement `MonitoringTable.tsx`:
```tsx
import type { MonitoringSite } from '@/types/api';
import { MonitoringRow } from './MonitoringRow';

export function MonitoringTable({ sites }: { sites: MonitoringSite[] }) {
  return (
    <table className="w-full text-sm">
      <thead>
        <tr className="border-b border-zinc-200 text-left text-xs uppercase tracking-wide text-zinc-500">
          <th className="py-2 pl-1 pr-3 font-medium">Status</th>
          <th className="py-2 pr-3 font-medium">Site</th>
          <th className="py-2 pr-3 font-medium">Latency</th>
          <th className="py-2 pr-3 font-medium">Uptime 7d</th>
          <th className="py-2 pr-3 font-medium">Uptime 30d</th>
          <th className="py-2 pr-1 font-medium">Last check</th>
        </tr>
      </thead>
      <tbody>
        {sites.map((s) => <MonitoringRow key={s.site_id} site={s} />)}
      </tbody>
    </table>
  );
}
```

- [ ] **Step 4: Run it — verify it passes**

Run: `pnpm vitest run src/components/monitoring/__tests__/MonitoringRow.test.tsx`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/components/monitoring/MonitoringRow.tsx apps/web/src/components/monitoring/MonitoringTable.tsx apps/web/src/components/monitoring/__tests__/MonitoringRow.test.tsx
git commit -m "feat(p3-2): MonitoringTable + MonitoringRow"
```

---

## Task 15: SPA — `Monitoring` route page + `App.tsx` wiring

**Files:**
- Create: `apps/web/src/routes/Monitoring.tsx`
- Modify: `apps/web/src/App.tsx` (add route under `RequireAuth`)
- Test: `apps/web/src/routes/__tests__/Monitoring.test.tsx`

- [ ] **Step 1: Write the failing test** (render the page through the router + MSW; mirror the existing Overview route test)

```tsx
import { describe, it, expect } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { http, HttpResponse } from 'msw';
import { server } from '@/test/server';
import { Monitoring } from '@/routes/Monitoring';

function renderPage() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}><MemoryRouter><Monitoring /></MemoryRouter></QueryClientProvider>,
  );
}

describe('Monitoring page', () => {
  it('shows the empty state when no sites', async () => {
    renderPage();
    await waitFor(() => expect(screen.getByText('No sites yet')).toBeInTheDocument());
  });

  it('renders the strip + table when sites exist', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/monitoring', () =>
        HttpResponse.json({
          summary: { total: 1, up: 1, down: 0, fleet_uptime_30d: 100, slowest_ms: 188 },
          sites: [{ site_id: 4, label: 'Northwind', url: 'https://n.test', status: 'active', last_response_time_ms: 188, last_contact_at: '2026-06-14 03:00:00', uptime_7d: 100, uptime_30d: 100, open_incident_started_at: null }],
          generated_at: '2026-06-14 03:35:00',
        }),
      ),
    );
    renderPage();
    await waitFor(() => expect(screen.getByText('Northwind')).toBeInTheDocument());
    expect(screen.getByText('188ms')).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run it — verify it fails**

Run: `pnpm vitest run src/routes/__tests__/Monitoring.test.tsx`
Expected: FAIL (route missing).

- [ ] **Step 3: Implement `Monitoring.tsx`** (mirror the Overview route's loading/error scaffolding)

```tsx
import { Link } from 'react-router-dom';
import { useMonitoring } from '@/lib/queries/useMonitoring';
import { MonitoringSummaryStrip } from '@/components/monitoring/MonitoringSummaryStrip';
import { MonitoringTable } from '@/components/monitoring/MonitoringTable';

export function Monitoring() {
  const { data, isLoading, isError } = useMonitoring();

  return (
    <div className="mx-auto max-w-5xl px-4 py-6">
      <div className="mb-5 flex items-baseline gap-3">
        <h1 className="text-xl font-semibold">Monitoring</h1>
        <Link to="/overview" className="text-sm text-zinc-600 underline-offset-4 hover:underline">← Overview</Link>
      </div>

      {isLoading && <p className="text-sm text-zinc-500">Loading…</p>}
      {isError && <p className="text-sm text-red-600">Couldn’t load monitoring data.</p>}

      {data && (
        data.sites.length === 0 ? (
          <p className="text-sm text-zinc-500">No sites yet</p>
        ) : (
          <div className="space-y-5">
            <MonitoringSummaryStrip summary={data.summary} />
            <div className="rounded-lg border border-zinc-200 p-2">
              <MonitoringTable sites={data.sites} />
            </div>
          </div>
        )
      )}
    </div>
  );
}

export default Monitoring;
```

- [ ] **Step 4: Wire the route** in `src/App.tsx` (add inside the `<RequireAuth>` block, beside `/jobs`)

```tsx
        <Route path="/monitoring" element={<Monitoring />} />
```
Add the import with the other route imports:
```tsx
import { Monitoring } from '@/routes/Monitoring';
```
(Match the existing import style — the file uses named imports like `import { Jobs } from ...`; verify whether routes are default or named and match. `Monitoring.tsx` exports both.)

- [ ] **Step 5: Run it — verify it passes**

Run: `pnpm vitest run src/routes/__tests__/Monitoring.test.tsx`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/routes/Monitoring.tsx apps/web/src/App.tsx apps/web/src/routes/__tests__/Monitoring.test.tsx
git commit -m "feat(p3-2): /monitoring route page"
```

---

## Task 16: SPA — `MonitoringNavLink` in the Overview header

**Guardrail 8:** additive — the P3.1 `OpenIncidentsWidget` stays; only a nav link is added.

**Files:**
- Create: `apps/web/src/components/nav/MonitoringNavLink.tsx`
- Modify: `apps/web/src/routes/Overview.tsx` (mount beside `<JobsNavLink />`)
- Test: `apps/web/src/components/nav/__tests__/MonitoringNavLink.test.tsx`

- [ ] **Step 1: Write the failing test**

```tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { MonitoringNavLink } from '@/components/nav/MonitoringNavLink';

describe('MonitoringNavLink', () => {
  it('links to /monitoring', () => {
    render(<MemoryRouter><MonitoringNavLink /></MemoryRouter>);
    expect(screen.getByRole('link', { name: 'Monitoring' })).toHaveAttribute('href', '/monitoring');
  });
});
```

- [ ] **Step 2: Run it — verify it fails**

Run: `pnpm vitest run src/components/nav/__tests__/MonitoringNavLink.test.tsx`
Expected: FAIL (component missing).

- [ ] **Step 3: Implement** (mirror `JobsNavLink`'s styling, without a badge)

```tsx
import { Link } from 'react-router-dom';

export function MonitoringNavLink() {
  return (
    <Link
      to="/monitoring"
      className="inline-flex items-center gap-1.5 text-sm text-zinc-600 underline-offset-4 hover:underline"
    >
      Monitoring
    </Link>
  );
}
```

- [ ] **Step 4: Mount it** in `src/routes/Overview.tsx`, beside `<JobsNavLink />`:

```tsx
      <JobsNavLink />
      <MonitoringNavLink />
```
Add the import:
```tsx
import { MonitoringNavLink } from '@/components/nav/MonitoringNavLink';
```

- [ ] **Step 5: Run it — verify it passes** (and the existing Overview test still green)

Run: `pnpm vitest run src/components/nav/__tests__/MonitoringNavLink.test.tsx src/routes/__tests__/Overview.test.tsx`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/components/nav/MonitoringNavLink.tsx apps/web/src/routes/Overview.tsx apps/web/src/components/nav/__tests__/MonitoringNavLink.test.tsx
git commit -m "feat(p3-2): MonitoringNavLink in Overview header"
```

---

## Task 17: Release — build, deploy, smoke, tag, MEMORY

**Guardrail 11:** connector NOT touched (no zip, no re-handshake).

**Files:** none new (build/release task).

- [ ] **Step 1: Full PHP suite green** (carry-forward = 1 UninstallTest infra fail)

Run: `cd packages/dashboard-plugin && composer test 2>&1 | tail -25`
Expected: green except `UninstallTest::testUninstallDropsAllTables`.

- [ ] **Step 2: Full SPA suite green under Node 22** (carry-forward = SiteDetail×2 + SiteCoreCard×2)

```bash
cd apps/web
export FNM_DIR="$HOME/.fnm"; eval "$(fnm env --shell bash)"; fnm use 22
pkill -9 -f vitest 2>/dev/null; pnpm vitest run 2>&1 | tail -30
```
Expected: only the 4 documented carry-forward failures. **Any hang = real bug** (P2.10 lesson) — bisect the component, do not blame the env.

- [ ] **Step 3: Build the dashboard zip v0.11.0** (symfony-preserving exclusion list — MEMORY gotcha)

```bash
cd packages/dashboard-plugin
composer install --no-dev --classmap-authoritative
# zip excluding ONLY tests/dev tooling — NEVER any vendor/* subdir:
zip -r ../../dist/defyn-dashboard-0.11.0.zip . \
  -x 'tests/*' '*wp-tests-config.php' '.phpunit.result.cache' 'test-output.log' 'phpunit.xml' 'composer.lock' '.github/*' '.gitignore'
composer install   # restore dev autoload
```

- [ ] **Step 4: Verify the zip preserves the symfony prod deps** (must return 2 lines)

```bash
unzip -l ../../dist/defyn-dashboard-0.11.0.zip | grep -E "deprecation-contracts/function\.php|polyfill-php83/bootstrap\.php"
```
Expected: 2 lines. If 0, the zip is broken — do not ship.

- [ ] **Step 5: Build the SPA**

```bash
cd apps/web && pnpm build 2>&1 | tail -5
```
Expected: clean build; note the new `index-*.js` bundle name.

- [ ] **Step 6: Merge to main (Cloudflare auto-deploys the SPA)**

```bash
cd "/Users/pradeep/Local Sites/defynWP"
git checkout main && git merge --ff-only p3-2-monitoring-visibility && git push origin main
```

- [ ] **Step 7: Dashboard install (MANUAL USER STEP — flag it; do NOT attempt UI automation)**

Tell the user: install `dist/defyn-dashboard-0.11.0.zip` via WP Admin → Plugins → "Replace current with uploaded", then MyKinsta → Tools → Clear cache (OPcache/Redis). Wait for confirmation before the API smoke.

- [ ] **Step 8: Production API smoke** (curl only — UI login is the user's job; creds `pradeep@defyn.com.au` / `DefynWP-ifirCh5pXm5bTOj0`)

```bash
# login → JWT
TOKEN=$(curl -s -X POST https://defynwp.defyn.agency/wp-json/defyn/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"pradeep@defyn.com.au","password":"DefynWP-ifirCh5pXm5bTOj0"}' | python3 -c 'import sys,json;print(json.load(sys.stdin)["token"])')
# 200 authed — proves schema v9 migrated + endpoint live
curl -s -o /dev/null -w "monitoring authed: %{http_code}\n" "https://defynwp.defyn.agency/wp-json/defyn/v1/monitoring?_=$RANDOM" -H "Authorization: Bearer $TOKEN"
# 401 no-auth
curl -s -o /dev/null -w "monitoring no-auth: %{http_code}\n" "https://defynwp.defyn.agency/wp-json/defyn/v1/monitoring?_=$RANDOM"
# envelope shape
curl -s "https://defynwp.defyn.agency/wp-json/defyn/v1/monitoring?_=$RANDOM" -H "Authorization: Bearer $TOKEN" | python3 -m json.tool | head -20
```
Expected: authed 200 with `summary`/`sites`/`generated_at`; no-auth 401. (30/min 429 optional — fire 31 calls.)

- [ ] **Step 9: Verify the deployed SPA bundle + route**

```bash
curl -s -o /dev/null -w "/monitoring route: %{http_code}\n" https://app.defynwp.defyn.agency/monitoring
# confirm the new bundle contains the page strings
curl -s https://app.defynwp.defyn.agency/ | grep -oE 'index-[A-Za-z0-9_-]+\.js' | head -1
```
Expected: route 200 (SPA index). Optionally fetch the bundle and grep for `Monitoring`/`Uptime 30d`.

- [ ] **Step 10: Tag + MEMORY**

```bash
git tag -a p3-2-monitoring-visibility-complete -m "P3.2 — monitoring performance & uptime visibility"
git push origin p3-2-monitoring-visibility-complete
```
Then update `~/.claude/projects/-Users-pradeep-Local-Sites-defynWP/memory/project_defyn_roadmap.md`: P3.2 shipped — schema v9, dashboard v0.11.0, connector unchanged, new `/monitoring` route + latency capture + derived uptime; note P3.3 (alerting expansion + config) is the next and final monitoring slice.

---

## Self-Review

**Spec coverage:** §4 latency capture → Tasks 3,4; §5 schema v9 → Task 1; §6 uptime pure fn + single query → Tasks 5,6; §7 endpoint → Task 8; §8 SPA route/strip/table/navlink → Tasks 10–16; §9 edge cases → covered in Tasks 5 (zero-window/clamp), 7 (empty fleet), 14 (null latency/open incident); §10 testing → every task is TDD; §11 release → Task 17. All 12 guardrails are cited inline in the owning task. No gaps.

**Type consistency:** `lastResponseTimeMs` (PHP) ↔ `last_response_time_ms` (DB/JSON/Zod) consistent; `MonitoringService::uptimePercent(array,int,int)` signature identical in Tasks 5 & 7; incident array shape `{started:int, ended:?int}` is what `uptimePercent` consumes — `findForUserSince` (Task 6) returns string `started_at`/`ended_at`, and `compose` (Task 7) converts those to the `{started, ended, started_raw}` epoch shape before calling `uptimePercent` (the Task 5 unit tests feed the epoch shape directly). `monitoringSchema` fields (Task 10) match the `compose` payload keys (Task 7) one-for-one. `useMonitoring` parses the raw body (Task 11) matching `MonitoringController`'s direct `WP_REST_Response($payload)` (Task 8, mirroring `OverviewController`).

**Placeholder scan:** no TBD/TODO; every code step shows complete code; commands have expected output.
