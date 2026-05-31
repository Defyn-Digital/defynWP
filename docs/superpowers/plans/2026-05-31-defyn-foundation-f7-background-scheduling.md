# F7 — Background Scheduling Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Install recurring Action Scheduler schedules so the dashboard auto-syncs and auto-pings every active managed site without manual SPA triggers, plus a cleanup job for stale connection codes.

**Architecture:** Three new recurring AS jobs use the fan-out pattern (one master per cadence -> enqueues one leaf job per site) to stay under Kinsta's 300s PHP limit. Leaf jobs (`defyn_sync_site`, `defyn_health_ping`) already exist from F6 — F7 just wires the recurring fan-out masters. Schedules install on plugin activation, clean up on deactivation. Per-site one-shot sync also fires immediately after handshake completes (better first-impression UX than waiting for the next 30-min tick).

**Tech Stack:** PHP 8.1+, Action Scheduler 3.7+ (`as_schedule_recurring_action`, `as_unschedule_action`, `as_next_scheduled_action`), wpdb, PHPUnit. No new dependencies.

**Spec source:** `docs/superpowers/specs/2026-04-18-defyn-foundation-design.md` — § 6.3 (jobs + cadences locked) + § 11 (F7 deliverable scope).

**Branch:** Off main as `f7-background-scheduling`. Last shipped: F6 merge `6245468`.

**Design decisions (locked):**
- Fan-out scope: `status IN ('active', 'offline', 'error')` — try everything; even broken sites might recover.
- Pagination: naive query with `LIMIT 500` + inline TODO for when site count grows. YAGNI for foundation.
- New-site UX: `Connection::complete()` enqueues a one-shot `defyn_sync_site` immediately after `markActive`.
- Cron verification: programmatic smoke at F7 close (defer real Kinsta cron check to F10).

---

### Task 1: `SitesRepository::findAllSchedulable()`

**Why:** The two fan-out jobs need to query which sites to enqueue. Per the repository pattern (F1), all SQL goes through the repo. Returns IDs only (avoid hydrating full `Site` objects for thousands of rows).

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/SitesRepository.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/SitesRepositoryFindAllSchedulableTest.php` (NEW)

- [ ] **Step 1: Write the failing test**

Create the test file:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SitesRepositoryFindAllSchedulableTest extends AbstractSchemaTestCase
{
    public function testReturnsActiveOfflineAndErrorIdsSkipsPending(): void
    {
        $repo = new SitesRepository();

        $activeId  = $repo->insertPending(1, 'https://active.test',  'A', base64_encode(random_bytes(32)), 'cipher');
        $repo->markActive($activeId, base64_encode(random_bytes(32)));

        $offlineId = $repo->insertPending(1, 'https://offline.test', 'B', base64_encode(random_bytes(32)), 'cipher');
        $repo->markActive($offlineId, base64_encode(random_bytes(32)));
        $repo->markOffline($offlineId, 'previously offline');

        $errorId   = $repo->insertPending(1, 'https://error.test',   'C', base64_encode(random_bytes(32)), 'cipher');
        $repo->markError($errorId, 'previously errored');

        $pendingId = $repo->insertPending(1, 'https://pending.test', 'D', base64_encode(random_bytes(32)), 'cipher');

        $ids = $repo->findAllSchedulable();

        $this->assertContains($activeId,  $ids);
        $this->assertContains($offlineId, $ids);
        $this->assertContains($errorId,   $ids);
        $this->assertNotContains($pendingId, $ids);
    }

    public function testRespectsLimit(): void
    {
        $repo = new SitesRepository();
        for ($i = 0; $i < 6; $i++) {
            $id = $repo->insertPending(1, "https://site{$i}.test", "S{$i}", base64_encode(random_bytes(32)), 'cipher');
            $repo->markActive($id, base64_encode(random_bytes(32)));
        }

        $ids = $repo->findAllSchedulable(limit: 3);
        $this->assertCount(3, $ids);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit --filter SitesRepositoryFindAllSchedulableTest`
Expected: FAIL — `findAllSchedulable` doesn't exist.

- [ ] **Step 3: Add `findAllSchedulable()` to `SitesRepository`**

In `packages/dashboard-plugin/src/Services/SitesRepository.php`, ADD alongside existing methods:

```php
/**
 * Site IDs eligible for background sync/ping. Active + offline + error (all
 * have a completed handshake and a private key on file; even error sites
 * might recover). Excludes pending (handshake not yet complete).
 *
 * TODO (F10+): paginate when sites > 500 — current naive LIMIT keeps the
 * fan-out within Kinsta's 300s PHP budget.
 *
 * @return list<int>
 */
public function findAllSchedulable(int $limit = 500): array
{
    global $wpdb;
    $table = SitesTable::tableName();
    $rows = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT id FROM {$table} WHERE status IN ('active', 'offline', 'error') ORDER BY id ASC LIMIT %d",
            $limit
        )
    );
    return array_map('intval', $rows ?: []);
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit --filter SitesRepositoryFindAllSchedulableTest`
Expected: PASS (both cases).

- [ ] **Step 5: Run full dashboard suite**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add packages/dashboard-plugin/src/Services/SitesRepository.php \
        packages/dashboard-plugin/tests/Integration/Services/SitesRepositoryFindAllSchedulableTest.php
git commit -m "F7: dashboard — SitesRepository::findAllSchedulable for fan-out jobs"
```

---

### Task 2: `SyncAllSites` fan-out AS job

**Why:** Recurring master that runs every 30 min and enqueues a `defyn_sync_site` for each schedulable site. Pattern mirrors F6's `SyncSite` shim shape.

**Files:**
- Create: `packages/dashboard-plugin/src/Jobs/SyncAllSites.php`
- Test: `packages/dashboard-plugin/tests/Integration/Jobs/SyncAllSitesTest.php` (NEW)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Jobs;

use Defyn\Dashboard\Jobs\SyncAllSites;
use Defyn\Dashboard\Jobs\SyncSite;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SyncAllSitesTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(SyncSite::HOOK, null, 'defyn');
        }
    }

    public function testHookNameIsDefynSyncAllSites(): void
    {
        $this->assertSame('defyn_sync_all_sites', SyncAllSites::HOOK);
    }

    public function testEnqueuesOneSyncSitePerSchedulableSite(): void
    {
        $repo = new SitesRepository();
        $idA  = $repo->insertPending(1, 'https://a.test', 'A', base64_encode(random_bytes(32)), 'cipher');
        $repo->markActive($idA, base64_encode(random_bytes(32)));
        $idB  = $repo->insertPending(1, 'https://b.test', 'B', base64_encode(random_bytes(32)), 'cipher');
        $repo->markActive($idB, base64_encode(random_bytes(32)));
        // a pending site (must be skipped)
        $repo->insertPending(1, 'https://c.test', 'C', base64_encode(random_bytes(32)), 'cipher');

        (new SyncAllSites())->handle();

        $this->assertNotFalse(as_next_scheduled_action(SyncSite::HOOK, [$idA], 'defyn'));
        $this->assertNotFalse(as_next_scheduled_action(SyncSite::HOOK, [$idB], 'defyn'));
    }
}
```

- [ ] **Step 2: Run test to verify failure**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit --filter SyncAllSitesTest`
Expected: FAIL — class doesn't exist.

- [ ] **Step 3: Create `SyncAllSites.php`**

`packages/dashboard-plugin/src/Jobs/SyncAllSites.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Services\SitesRepository;

/**
 * Recurring fan-out master: every 30 min enqueue one `defyn_sync_site` per
 * schedulable site (active + offline + error). Per spec § 6.3.
 *
 * Each leaf SyncSite job runs independently within Kinsta's 300s PHP budget.
 */
final class SyncAllSites
{
    public const HOOK = 'defyn_sync_all_sites';

    public function __construct(
        private readonly ?SitesRepository $repo = null,
    ) {}

    public function handle(): void
    {
        $repo = $this->repo ?? new SitesRepository();
        if (!function_exists('as_schedule_single_action')) {
            return;
        }
        foreach ($repo->findAllSchedulable() as $siteId) {
            as_schedule_single_action(time(), SyncSite::HOOK, [$siteId], 'defyn');
        }
    }
}
```

- [ ] **Step 4: Run test to verify pass**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit --filter SyncAllSitesTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Jobs/SyncAllSites.php \
        packages/dashboard-plugin/tests/Integration/Jobs/SyncAllSitesTest.php
git commit -m "F7: dashboard — SyncAllSites fan-out AS job (every 30 min)"
```

---

### Task 3: `HealthPingAll` fan-out AS job

**Why:** Same shape as Task 2 but enqueues `defyn_health_ping` per site, every 5 min.

**Files:**
- Create: `packages/dashboard-plugin/src/Jobs/HealthPingAll.php`
- Test: `packages/dashboard-plugin/tests/Integration/Jobs/HealthPingAllTest.php` (NEW)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Jobs;

use Defyn\Dashboard\Jobs\HealthPing;
use Defyn\Dashboard\Jobs\HealthPingAll;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class HealthPingAllTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(HealthPing::HOOK, null, 'defyn');
        }
    }

    public function testHookNameIsDefynHealthPingAll(): void
    {
        $this->assertSame('defyn_health_ping_all', HealthPingAll::HOOK);
    }

    public function testEnqueuesOneHealthPingPerSchedulableSite(): void
    {
        $repo = new SitesRepository();
        $id = $repo->insertPending(1, 'https://a.test', 'A', base64_encode(random_bytes(32)), 'cipher');
        $repo->markActive($id, base64_encode(random_bytes(32)));

        (new HealthPingAll())->handle();

        $this->assertNotFalse(as_next_scheduled_action(HealthPing::HOOK, [$id], 'defyn'));
    }
}
```

- [ ] **Step 2: Run test to verify failure**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit --filter HealthPingAllTest`
Expected: FAIL.

- [ ] **Step 3: Create `HealthPingAll.php`**

`packages/dashboard-plugin/src/Jobs/HealthPingAll.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Services\SitesRepository;

/**
 * Recurring fan-out master: every 5 min enqueue one `defyn_health_ping` per
 * schedulable site. Per spec § 6.3.
 */
final class HealthPingAll
{
    public const HOOK = 'defyn_health_ping_all';

    public function __construct(
        private readonly ?SitesRepository $repo = null,
    ) {}

    public function handle(): void
    {
        $repo = $this->repo ?? new SitesRepository();
        if (!function_exists('as_schedule_single_action')) {
            return;
        }
        foreach ($repo->findAllSchedulable() as $siteId) {
            as_schedule_single_action(time(), HealthPing::HOOK, [$siteId], 'defyn');
        }
    }
}
```

- [ ] **Step 4: Run test to verify pass**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit --filter HealthPingAllTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Jobs/HealthPingAll.php \
        packages/dashboard-plugin/tests/Integration/Jobs/HealthPingAllTest.php
git commit -m "F7: dashboard — HealthPingAll fan-out AS job (every 5 min)"
```

---

### Task 4: `ConnectionCodesRepository` + `CleanupExpiredCodes` AS job

**Why:** Hourly sweep of `wp_defyn_connection_codes`. Per repository pattern, the job calls a new repo method rather than issuing raw SQL.

**Files:**
- Create: `packages/dashboard-plugin/src/Services/ConnectionCodesRepository.php`
- Create: `packages/dashboard-plugin/src/Jobs/CleanupExpiredCodes.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/ConnectionCodesRepositoryTest.php` (NEW)
- Test: `packages/dashboard-plugin/tests/Integration/Jobs/CleanupExpiredCodesTest.php` (NEW)

- [ ] **Step 1: Inspect the codes table schema**

Read `packages/dashboard-plugin/src/Schema/ConnectionCodesTable.php` to confirm column names: `code`, `site_url`, `expires_at`, `consumed_at`, etc. Adapt the SQL below to whatever the actual columns are.

- [ ] **Step 2: Write the failing repository test**

`packages/dashboard-plugin/tests/Integration/Services/ConnectionCodesRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Schema\ConnectionCodesTable;
use Defyn\Dashboard\Services\ConnectionCodesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class ConnectionCodesRepositoryTest extends AbstractSchemaTestCase
{
    public function testDeleteExpiredAndConsumedRemovesOnlyTargetRows(): void
    {
        global $wpdb;
        $table = ConnectionCodesTable::tableName();

        // Adapt these INSERTs to the real column list from ConnectionCodesTable
        $wpdb->insert($table, ['code' => 'EXPIRED01234', 'expires_at' => gmdate('Y-m-d H:i:s', time() - 3600), 'consumed_at' => null, 'site_url' => 'https://a.test']);
        $wpdb->insert($table, ['code' => 'CONSUMED1234', 'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600), 'consumed_at' => gmdate('Y-m-d H:i:s'), 'site_url' => 'https://b.test']);
        $wpdb->insert($table, ['code' => 'LIVE12345678', 'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600), 'consumed_at' => null, 'site_url' => 'https://c.test']);

        $deleted = (new ConnectionCodesRepository())->deleteExpiredAndConsumed();

        $this->assertSame(2, $deleted);
        $remaining = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $this->assertSame(1, $remaining);
        $survivor = $wpdb->get_var("SELECT code FROM {$table}");
        $this->assertSame('LIVE12345678', $survivor);
    }
}
```

- [ ] **Step 3: Write the failing job test**

`packages/dashboard-plugin/tests/Integration/Jobs/CleanupExpiredCodesTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Jobs;

use Defyn\Dashboard\Jobs\CleanupExpiredCodes;
use Defyn\Dashboard\Services\ConnectionCodesRepository;
use PHPUnit\Framework\TestCase;

final class CleanupExpiredCodesTest extends TestCase
{
    public function testHookNameIsDefynCleanupExpiredCodes(): void
    {
        $this->assertSame('defyn_cleanup_expired_codes', CleanupExpiredCodes::HOOK);
    }

    public function testHandleDelegatesToRepository(): void
    {
        // Final class — smoke test instead of mock. With an empty table the
        // repo returns 0 deletes and the job returns silently.
        $this->expectNotToPerformAssertions();
        (new CleanupExpiredCodes())->handle();
    }
}
```

- [ ] **Step 4: Run both tests to verify failure**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit --filter "ConnectionCodesRepositoryTest|CleanupExpiredCodesTest"`
Expected: FAIL — classes don't exist.

- [ ] **Step 5: Create `ConnectionCodesRepository.php`**

`packages/dashboard-plugin/src/Services/ConnectionCodesRepository.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Schema\ConnectionCodesTable;

/**
 * Thin wrapper over wpdb for wp_defyn_connection_codes. Only class that
 * issues raw SQL against that table.
 */
final class ConnectionCodesRepository
{
    /**
     * Sweep rows past expiry OR already consumed. Returns deleted count.
     */
    public function deleteExpiredAndConsumed(): int
    {
        global $wpdb;
        $table = ConnectionCodesTable::tableName();
        $now = gmdate('Y-m-d H:i:s');

        $count = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE expires_at < %s OR consumed_at IS NOT NULL",
                $now
            )
        );
        return (int) $count;
    }
}
```

- [ ] **Step 6: Create `CleanupExpiredCodes.php`**

`packages/dashboard-plugin/src/Jobs/CleanupExpiredCodes.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Services\ConnectionCodesRepository;

/**
 * Hourly cleanup AS job. Sweeps expired or consumed connection-code rows.
 * Per spec § 6.3.
 */
final class CleanupExpiredCodes
{
    public const HOOK = 'defyn_cleanup_expired_codes';

    public function __construct(
        private readonly ?ConnectionCodesRepository $repo = null,
    ) {}

    public function handle(): void
    {
        $repo = $this->repo ?? new ConnectionCodesRepository();
        $repo->deleteExpiredAndConsumed();
    }
}
```

- [ ] **Step 7: Run tests to verify pass**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit --filter "ConnectionCodesRepositoryTest|CleanupExpiredCodesTest"`
Expected: PASS (3 tests).

- [ ] **Step 8: Commit**

```bash
git add packages/dashboard-plugin/src/Services/ConnectionCodesRepository.php \
        packages/dashboard-plugin/src/Jobs/CleanupExpiredCodes.php \
        packages/dashboard-plugin/tests/Integration/Services/ConnectionCodesRepositoryTest.php \
        packages/dashboard-plugin/tests/Integration/Jobs/CleanupExpiredCodesTest.php
git commit -m "F7: dashboard — ConnectionCodesRepository + CleanupExpiredCodes hourly job"
```

---

### Task 5: `Scheduler` helper — install + uninstall recurring schedules

**Why:** Wraps `as_schedule_recurring_action` + idempotent re-installation. Called by activation hook (install) and deactivation hook (uninstall). Single source of truth for cadences.

**Files:**
- Create: `packages/dashboard-plugin/src/Jobs/Scheduler.php`
- Test: `packages/dashboard-plugin/tests/Integration/Jobs/SchedulerTest.php` (NEW)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Jobs;

use Defyn\Dashboard\Jobs\CleanupExpiredCodes;
use Defyn\Dashboard\Jobs\HealthPingAll;
use Defyn\Dashboard\Jobs\Scheduler;
use Defyn\Dashboard\Jobs\SyncAllSites;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SchedulerTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Start clean so install assertions are unambiguous
        Scheduler::uninstallRecurringSchedules();
    }

    public function testInstallSchedulesAllThreeRecurringActions(): void
    {
        Scheduler::installRecurringSchedules();

        $this->assertNotFalse(as_next_scheduled_action(SyncAllSites::HOOK,         [], 'defyn'));
        $this->assertNotFalse(as_next_scheduled_action(HealthPingAll::HOOK,        [], 'defyn'));
        $this->assertNotFalse(as_next_scheduled_action(CleanupExpiredCodes::HOOK,  [], 'defyn'));
    }

    public function testInstallIsIdempotent(): void
    {
        Scheduler::installRecurringSchedules();
        Scheduler::installRecurringSchedules();

        // Each hook must have exactly one scheduled recurring action — not two
        $this->assertCount(1, as_get_scheduled_actions([
            'hook'   => SyncAllSites::HOOK,
            'group'  => 'defyn',
            'status' => 'pending',
        ], 'ids'));
    }

    public function testUninstallRemovesAllSchedules(): void
    {
        Scheduler::installRecurringSchedules();
        Scheduler::uninstallRecurringSchedules();

        $this->assertFalse(as_next_scheduled_action(SyncAllSites::HOOK,        [], 'defyn'));
        $this->assertFalse(as_next_scheduled_action(HealthPingAll::HOOK,       [], 'defyn'));
        $this->assertFalse(as_next_scheduled_action(CleanupExpiredCodes::HOOK, [], 'defyn'));
    }
}
```

- [ ] **Step 2: Run test to verify failure**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit --filter SchedulerTest`
Expected: FAIL — class doesn't exist.

- [ ] **Step 3: Create `Scheduler.php`**

`packages/dashboard-plugin/src/Jobs/Scheduler.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Jobs;

/**
 * Install / uninstall the recurring AS schedules for F7 fan-out + cleanup
 * jobs. Single source of truth for cadences — spec § 6.3.
 *
 * Activation hook calls install; deactivation hook calls uninstall.
 * install is idempotent: existing schedules are unscheduled first to prevent
 * duplicate recurring rows on repeated activation.
 */
final class Scheduler
{
    private const SCHEDULES = [
        SyncAllSites::HOOK         => 1800,  // 30 minutes
        HealthPingAll::HOOK        => 300,   // 5 minutes
        CleanupExpiredCodes::HOOK  => 3600,  // 1 hour
    ];

    public static function installRecurringSchedules(): void
    {
        if (!function_exists('as_schedule_recurring_action')) {
            return;
        }
        self::uninstallRecurringSchedules();  // idempotency
        foreach (self::SCHEDULES as $hook => $intervalSeconds) {
            as_schedule_recurring_action(time(), $intervalSeconds, $hook, [], 'defyn');
        }
    }

    public static function uninstallRecurringSchedules(): void
    {
        if (!function_exists('as_unschedule_all_actions')) {
            return;
        }
        foreach (array_keys(self::SCHEDULES) as $hook) {
            as_unschedule_all_actions($hook, null, 'defyn');
        }
    }
}
```

- [ ] **Step 4: Run test to verify pass**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit --filter SchedulerTest`
Expected: PASS (all 3 cases).

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Jobs/Scheduler.php \
        packages/dashboard-plugin/tests/Integration/Jobs/SchedulerTest.php
git commit -m "F7: dashboard — Scheduler helper for install/uninstall recurring AS schedules"
```

---

### Task 6: Wire activation + deactivation + boot hooks

**Why:** `Scheduler::install` must run on plugin activation; `Scheduler::uninstall` on deactivation; the 3 new AS hook handlers must fire when AS runs.

**Files:**
- Modify: `packages/dashboard-plugin/src/Activation.php` (call `Scheduler::installRecurringSchedules()` after schema setup)
- Modify: `packages/dashboard-plugin/src/Plugin.php` (register `register_deactivation_hook` + 3 new `add_action` calls for the AS hooks)
- Test: `packages/dashboard-plugin/tests/Integration/ActivationRecurringSchedulesTest.php` (NEW)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Jobs\CleanupExpiredCodes;
use Defyn\Dashboard\Jobs\HealthPingAll;
use Defyn\Dashboard\Jobs\Scheduler;
use Defyn\Dashboard\Jobs\SyncAllSites;

final class ActivationRecurringSchedulesTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Scheduler::uninstallRecurringSchedules();
    }

    public function testActivationInstallsRecurringSchedules(): void
    {
        Activation::activate();

        $this->assertNotFalse(as_next_scheduled_action(SyncAllSites::HOOK,        [], 'defyn'));
        $this->assertNotFalse(as_next_scheduled_action(HealthPingAll::HOOK,       [], 'defyn'));
        $this->assertNotFalse(as_next_scheduled_action(CleanupExpiredCodes::HOOK, [], 'defyn'));
    }

    public function testThreeNewHookHandlersAreRegistered(): void
    {
        // Plugin::boot runs at bootstrap; if not, trigger the file include.
        $this->assertNotFalse(has_action(SyncAllSites::HOOK));
        $this->assertNotFalse(has_action(HealthPingAll::HOOK));
        $this->assertNotFalse(has_action(CleanupExpiredCodes::HOOK));
    }
}
```

- [ ] **Step 2: Run test to verify failure**

Expected: FAIL — Activation doesn't call Scheduler yet; the 3 hooks aren't bound yet.

- [ ] **Step 3: Modify `Activation::activate()`**

In `packages/dashboard-plugin/src/Activation.php`, after the existing schema-creation loop, add:

```php
\Defyn\Dashboard\Jobs\Scheduler::installRecurringSchedules();
```

- [ ] **Step 4: Modify `Plugin.php` to register deactivation hook + boot the 3 new AS handlers**

Add to `Plugin::boot()` alongside the existing F5/F6 `add_action` blocks:

```php
add_action(\Defyn\Dashboard\Jobs\SyncAllSites::HOOK, static function (): void {
    (new \Defyn\Dashboard\Jobs\SyncAllSites())->handle();
}, 10, 0);

add_action(\Defyn\Dashboard\Jobs\HealthPingAll::HOOK, static function (): void {
    (new \Defyn\Dashboard\Jobs\HealthPingAll())->handle();
}, 10, 0);

add_action(\Defyn\Dashboard\Jobs\CleanupExpiredCodes::HOOK, static function (): void {
    (new \Defyn\Dashboard\Jobs\CleanupExpiredCodes())->handle();
}, 10, 0);
```

And register the deactivation hook (find where `register_activation_hook` already lives; this sits next to it):

```php
register_deactivation_hook($pluginFile, [\Defyn\Dashboard\Jobs\Scheduler::class, 'uninstallRecurringSchedules']);
```

(Adapt `$pluginFile` to whatever variable Plugin.php uses for the main plugin file path.)

- [ ] **Step 5: Run test to verify pass**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit --filter ActivationRecurringSchedulesTest`
Expected: PASS.

- [ ] **Step 6: Run full dashboard suite**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit`
Expected: 149 prior + new tests, all green.

- [ ] **Step 7: Commit**

```bash
git add packages/dashboard-plugin/src/Activation.php \
        packages/dashboard-plugin/src/Plugin.php \
        packages/dashboard-plugin/tests/Integration/ActivationRecurringSchedulesTest.php
git commit -m "F7: dashboard — wire activation/deactivation hooks and AS handlers for fan-out + cleanup"
```

---

### Task 7: Immediate one-shot sync after handshake (Connection::complete)

**Why:** New sites should see runtime info within seconds of handshake, not 30 minutes later when the next `sync_all` tick fires.

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/Connection.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/ConnectionImmediateSyncTest.php` (NEW)

- [ ] **Step 1: Inspect Connection::complete**

Read the existing happy-path branch — find where `markActive` is called. The new `as_schedule_single_action` goes immediately after that, inside the same happy-path branch (so failed handshakes don't trigger it).

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Jobs\SyncSite;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
// ... and whatever else the existing ConnectionTest imports

final class ConnectionImmediateSyncTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(SyncSite::HOOK, null, 'defyn');
        }
    }

    public function testSuccessfulHandshakeEnqueuesImmediateSync(): void
    {
        // Build the same fixture the existing happy-path Connection test uses:
        // - insert a pending site row
        // - mock SignedHttpClient to return a successful POST /connect response
        //   with a valid signed challenge response
        // - call Connection::complete()
        // - assert site flips to active (existing F5 assertion)
        // - NEW: assert as_next_scheduled_action(SyncSite::HOOK, [$siteId], 'defyn') !== false
        //
        // Copy the fixture wiring from packages/dashboard-plugin/tests/Integration/Services/ConnectionTest.php
        // (or whatever the F5 happy-path test is named) and add only the new assertion.
        $this->markTestIncomplete('Implementer: copy F5 ConnectionTest happy-path fixture and add the immediate-sync assertion');
    }
}
```

**Important:** The implementer should INLINE the actual fixture from the existing F5 `ConnectionTest` happy-path test (don't leave `markTestIncomplete`). The plan can't write that test in full because the fixture depends on Connection's constructor signature which the implementer must inspect.

- [ ] **Step 3: Run test to verify failure**

Expected: FAIL after the implementer wires up the real fixture.

- [ ] **Step 4: Modify `Connection::complete`**

After the existing `markActive` call (happy-path only), add:

```php
if (function_exists('as_schedule_single_action')) {
    as_schedule_single_action(time(), \Defyn\Dashboard\Jobs\SyncSite::HOOK, [$siteId], 'defyn');
}
```

- [ ] **Step 5: Run the test + the existing ConnectionTest happy-path**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit --filter "ConnectionTest|ConnectionImmediateSyncTest"`
Expected: PASS (no regression on the F5 happy-path; new assertion green).

- [ ] **Step 6: Full dashboard suite**

Run: `cd packages/dashboard-plugin && vendor/bin/phpunit`
Expected: all green.

- [ ] **Step 7: Commit**

```bash
git add packages/dashboard-plugin/src/Services/Connection.php \
        packages/dashboard-plugin/tests/Integration/Services/ConnectionImmediateSyncTest.php
git commit -m "F7: dashboard — schedule immediate sync after successful handshake (better first-impression UX)"
```

---

### Task 8: README updates

**Why:** Document the new recurring schedules + cadences + activation/deactivation lifecycle.

**Files:**
- Modify: `packages/dashboard-plugin/README.md`

- [ ] **Step 1: Add an `## F7 — Background scheduling` section**

Document:
- The three new recurring AS hooks (`defyn_sync_all_sites` 30 min, `defyn_health_ping_all` 5 min, `defyn_cleanup_expired_codes` hourly)
- Fan-out pattern explanation (master -> leaf per schedulable site; keeps each PHP run under Kinsta's 300s limit)
- Activation hook installs schedules; deactivation hook removes them; install is idempotent
- Schedulable-site filter: `status IN ('active', 'offline', 'error')` (skips `pending`)
- The new immediate-sync behavior on handshake completion
- The new `ConnectionCodesRepository::deleteExpiredAndConsumed()` and the `Scheduler::install/uninstallRecurringSchedules` helpers

- [ ] **Step 2: Commit**

```bash
git add packages/dashboard-plugin/README.md
git commit -m "F7: docs — README updates for recurring AS schedules"
```

---

### Task 9: Manual smoke + merge

**Why:** Programmatic smoke against the live local stack: install schedules, trigger one fan-out tick, verify leaf jobs run and persist.

- [ ] **Step 1: Confirm the F6 site state still exists** (site id=1 from F6 smoke):

```bash
SOCK="/Users/pradeep/Library/Application Support/Local/run/50bJKdbjK/mysql/mysqld.sock"
PHP="/Users/pradeep/Library/Application Support/Local/lightning-services/php-8.2.27+1/bin/darwin/bin/php"
# Write a tiny probe (similar to F6's /tmp/f6-probe2.php) to confirm site id=1 status=active
```

- [ ] **Step 2: Smoke install the schedules + run one tick**

Write `/tmp/f7-smoke.php` that:
1. Bootstraps WP via wp-load.php
2. Calls `Defyn\Dashboard\Jobs\Scheduler::uninstallRecurringSchedules()` then `installRecurringSchedules()` (idempotent install)
3. Calls `(new Defyn\Dashboard\Jobs\SyncAllSites())->handle()` — should enqueue a `defyn_sync_site` for site id=1
4. Asserts via `as_next_scheduled_action(SyncSite::HOOK, [1], 'defyn')` that the leaf is queued
5. Calls `(new Defyn\Dashboard\Jobs\SyncSite())->handle(1)` to drain the leaf
6. Asserts `last_sync_at` advanced
7. Calls `(new HealthPingAll())->handle()` then `(new HealthPing())->handle(1)`
8. Asserts `last_contact_at` advanced
9. Calls `(new CleanupExpiredCodes())->handle()` (no-op if no codes exist, just proves it runs without error)
10. Reports OK

Run: `$PHP -d mysqli.default_socket=$SOCK -d pdo_mysql.default_socket=$SOCK /tmp/f7-smoke.php`
Expected: all OK lines printed.

- [ ] **Step 3: Run both test suites one last time**

```bash
cd packages/connector-plugin && vendor/bin/phpunit && cd ../..
cd packages/dashboard-plugin && vendor/bin/phpunit && cd ../..
```
Expected: all green (connector unchanged from F6; dashboard grew by F7's new tests).

- [ ] **Step 4: Push + PR + merge**

```bash
git push -u origin f7-background-scheduling
gh pr create --title "F7: Background scheduling" --body "$(cat <<'EOF'
## Summary
- Three new recurring AS jobs: defyn_sync_all_sites (30 min), defyn_health_ping_all (5 min), defyn_cleanup_expired_codes (hourly)
- Fan-out pattern: masters enqueue one leaf job per schedulable site (status IN active+offline+error)
- New ConnectionCodesRepository::deleteExpiredAndConsumed() (single-class wpdb wrapper per repository pattern)
- New Scheduler helper installs schedules on plugin activation (idempotent), uninstalls on deactivation
- Connection::complete() schedules an immediate one-shot sync after successful handshake so new sites show runtime info within seconds, not 30 minutes

## Test plan
- [x] All dashboard tests pass
- [x] Programmatic smoke against live local stack: install -> SyncAllSites -> SyncSite -> last_sync_at advances; HealthPingAll -> HealthPing -> last_contact_at advances
EOF
)"
gh pr merge --merge --delete-branch
```

Tag: `git tag -a f7-background-scheduling-complete -m "F7: ..." && git push origin f7-background-scheduling-complete`

- [ ] **Step 5: Update memory and clean up `/tmp` smoke artifacts**

---

## Self-Review Checklist

After all 9 tasks complete, the controller (you) should verify:

- [ ] Every spec § 6.3 row is covered: `defyn_sync_all_sites`, `defyn_sync_site` (F6), `defyn_health_ping_all`, `defyn_health_ping` (F6), `defyn_complete_connection` (F5), `defyn_cleanup_expired_codes`
- [ ] No placeholders / TBD / "implement later"
- [ ] Method names consistent (`installRecurringSchedules`, `uninstallRecurringSchedules`, `deleteExpiredAndConsumed`, `findAllSchedulable`) across tasks
- [ ] Repository pattern preserved: all SQL via repo classes
- [ ] AS group `'defyn'` consistent everywhere (matches F5 + F6)
- [ ] No drift in cadences (30 min / 5 min / 1 hour locked by spec)
