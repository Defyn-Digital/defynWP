# P2.4 WordPress Core Updates (Minor Only) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an operator click "Update WordPress core" on a managed site and execute the WordPress minor-version upgrade (e.g. 7.0 → 7.0.1) end-to-end. Major bumps (7.0 → 7.1+) are explicitly blocked at both the connector and the dashboard with a `core.major_update_blocked` envelope, deferred to P2.4.1. Output: connector v0.1.6 + dashboard v0.5.0. State writes target the existing `wp_defyn_sites` row (single resource per site) — no new table, no new repository class.

**Architecture:** SPA `<SiteCoreCard>` reads `useSite(siteId)` (which already polls `/sites/{id}`). SPA → dashboard `POST /defyn/v1/sites/{id}/core/refresh` (Bearer JWT + 6/hour `sitesCoreRefresh` bucket) schedules `defyn_refresh_site_core`. SPA → dashboard `POST /defyn/v1/sites/{id}/core/update` (Bearer + **3/hour** `coreUpdate` bucket — tighter than themes/plugins) writes optimistic `core_update_state='queued'` and schedules `defyn_update_site_core($siteId, $attempt)`. The AS jobs decrypt the per-site Ed25519 key, call connector `/core/refresh` (30s) or `/core/update` (**300s** — vs themes' 120s because core upgrades include WP DB migrations), then write back via four new `SitesRepository::markCoreUpdate*` methods. The connector reuses the shared `defyn_connector_upgrade_in_flight` transient lock (now covers core ↔ plugin and core ↔ theme collisions too) and the P2.2.1 `ob_start/ob_end_clean` STDOUT discipline. The SPA pins `useSite` at 30s polling while `core_update_state IN ('queued','updating')` and settles back to a 5-minute stale window on `idle`/`failed` (5min hard cap on the polling pin).

**Tech Stack:** PHP 8.1+ (PHPUnit, `WP_UnitTestCase` / `AbstractSchemaTestCase`), Action Scheduler, Symfony HttpClient (`MockHttpClient` for tests), WordPress REST API + `Core_Upgrader`, React 18 + TypeScript + TanStack Query v5 + Zod + Tailwind + shadcn/ui + Vitest + React Testing Library + MSW.

**Spec:** [`docs/superpowers/specs/2026-06-07-p2-4-core-updates-design.md`](../specs/2026-06-07-p2-4-core-updates-design.md)

---

## Workflow conventions

- **Branch:** Branch off **`p2-3-themes`** (currently HEAD at `59f8c69`), NOT `main`. The dashboard schema v5 migration builds on the schema v4 from P2.3 — branching off main would require manually rebasing schema column ordering once P2.3 merges. Branch name: `p2-4-core-updates`. Command:
  ```
  git checkout -b p2-4-core-updates p2-3-themes
  ```
- **Each Task = one atomic commit.**
- **Test discipline (TDD):** Step 1 writes the failing test. Step 2 runs it and confirms it fails. Step 3 writes the implementation. Step 4 confirms it passes. Step 5 commits.
- **Test runners:**
  - Connector PHP: `cd packages/connector-plugin && composer test`
  - Dashboard PHP: `cd packages/dashboard-plugin && composer test`
  - SPA: `cd apps/web && pnpm test -- --run`
- **Commit message format:** `<type>(p2-4): <description>` where `<type>` ∈ {feat, fix, refactor, docs, test, chore}.
- **All edits adhere to** `~/.claude/rules/common/coding-style.md` — immutability, KISS, DRY, YAGNI, error handling, no console.log / var_dump / print_r.
- **Cache headers:** the existing `RestRouter::applyNoCacheHeaders` filter (connector + dashboard) already covers any defyn-namespaced route — core routes inherit `no-store` for free.
- **Signed-request canonical string:** ALWAYS use `Signer::canonical($method, $path, $ts, $nonce, $bodyHash)` — never inline format. (Plan-bug lesson from P2.1 Task 2 — caught in `89aa6d0`.)
- **Cache-header tests:** must manually invoke `apply_filters('rest_post_dispatch', $res, rest_get_server(), $req)` because `rest_do_request()` skips that filter pipeline. (Plan-bug lesson from P2.1 Task 4 — caught in `2770cd0`.)
- **`ActivityLogger::log()` signature:** `log(?int $userId, ?int $siteId, string $eventType, ?array $details, ?string $ip)` — `$userId` FIRST. (Plan-bug lesson from P2.1 Task 7 — caught in `4c65168`.)
- **`SignedHttpClient::signedPostJson` shape:** `(string $url, array $body, string $privateKeyBase64, string $canonicalPath, int $timeoutSeconds = 30): array` returning `['status' => int, 'body' => array, 'error' => string]`. Core update job MUST use `timeoutSeconds: 300` — assert this in `UpdateSiteCoreTest` (the timeout is a regression-testable constant; copy-paste from P2.3's `120` is a known trap).
- **STDOUT discipline (P2.2.1):** `CoreUpdateController` wraps the service call in `ob_start()` / `ob_end_clean()` inside a `try/finally` from day 1. Day-1 regression test `testStdoutFromUpgraderDoesNotCorruptResponse` is mandatory — verbatim copy of the P2.3 themes regression with a `Core_Upgrader` stub that echoes stray bytes.
- **Shared transient lock:** `defyn_connector_upgrade_in_flight` covers all 3 × 3 = 9 plugin/theme/core resource collisions. `CoreUpdateLockTest` MUST cover 4 cross-resource scenarios from day 1: plugin↔core, theme↔core, core↔plugin, core↔theme — plus auto-release on success AND on exception (the `finally` clause).
- **Day-1 single-row heal in `SitesRepository::markSynced`:** when incoming `core.update_available === false` AND the existing row's `core_update_state === 'failed'`, reset to `idle` and clear `last_core_update_error`. Tests `testMarkSyncedHealsStuckFailedWhenIncomingHasNoUpdateAvailable` + `testMarkSyncedDoesNotHealWhenUpdateStillAvailable` are required. This is the single-row equivalent of P2.2.1's `healDanglingFailedStates`.
- **Activity log triplet:** every operator-triggered core update emits `core_update.requested → core_update.started → core_update.succeeded|failed`. `requested` written at REST-controller queue time; `started` + terminal event written by the AS job. Smoke matrix asserts the triplet exists in order.
- **409 success-by-other-means uses `$wpVersionBeforeAttempt`:** read the row's existing `wp_version` BEFORE running the connector roundtrip into a local variable, then pass it to `markCoreUpdateSucceeded` on the `core.no_update_available` 409 branch. The connector's 409 error envelope does NOT carry a version field — never try to read `body.new_version` on that branch. Log `core_update.succeeded_no_change`.
- **409 `core.major_update_blocked`:** mark failed immediately, NO retry. Log `core_update.blocked_major`. Treated as a soft operator-visible failure (it means the dashboard's preflight allowed a major through, which is a contract violation — but it's safer to surface than retry).
- **409 `connector.upgrade_in_progress`:** exponential backoff retry — 60s / 120s / 240s / 480s / 960s, up to 5 attempts. Log `core_update.retry` per retry, `core_update.failed` with `error_code = 'retry_exhausted'` on the 6th-attempt giveup.
- **502 / transport errors NO retry:** for core updates, repeated non-lock failures usually mean a real problem (out-of-disk, broken connector, network). `markCoreUpdateFailed` immediately + log `core_update.failed`. (Different from P2.3 — themes can retry on some transport errors; core does not.)
- **`RateLimit::coreUpdate` is 3/hour per (user, site)** — strictly tighter than themes/plugins at 6/hour. Common trap: copy-pasting the P2.3 test method name `testRateLimit429AfterSeventhCall` and forgetting to rename. The P2.4 test method MUST be named `testRateLimit429AfterFourthCall` and 3 prior 202s must precede the 429.
- **`RateLimit::sitesCoreRefresh` is 6/hour per (user, site)** — same shape as themes/plugins. Asserted separate bucket from `sitesPluginsRefresh` and `sitesThemesRefresh`.
- **`SyncSite::handle` extension:** ONE-line addition — schedule `defyn_refresh_site_core` alongside the existing plugins + themes refresh. The smoke matrix + `PluginBootASHookCoreTest::testSyncSiteAlsoSchedulesCoreRefresh` assert all three hooks fire per background tick.
- **`Plugin::boot` hook registration:** 2 new `add_action` calls — `defyn_refresh_site_core` (1 arg: `siteId`) + `defyn_update_site_core` (2 args: `siteId`, `attempt`). Test asserts both hooks registered.
- **Connector `Collector::collect()` extension:** pure read — never call `wp_version_check()` on the `/status` path. The new `collectCoreUpdate()` helper reads the `update_core` site transient that WP itself refreshes via cron. `wp_version_check()` is only invoked from `POST /core/refresh` and `POST /core/update`.
- **No new model class. No new repository class. No new SPA query hook.** Core update state arrives through the existing `useSite(siteId)` query and the existing `Site` model — just with five new readonly fields surfaced through `fromRow` + `toJson`. This is the structural divergence from P2.1/P2.2/P2.3.
- **SPA `SiteCoreCard` placement:** ABOVE `SiteSummaryCard`, BELOW `SiteHeader`. Test asserts DOM order via `compareDocumentPosition`. Card has 4 visual states: idle no-update, idle update-available, updating (queued|updating), failed.
- **`ConfirmUpdateCoreDialog`:** TWO warning banners (downtime + downgrade-irreversibility), conditional "Auto-updates ON" paragraph when `is_auto_update_enabled === true`, amber primary button (`bg-amber-600 hover:bg-amber-700`) labelled "Yes, update WordPress core", Cancel has default focus.

### wp-phpunit gotchas for connector REST integration tests (Tasks 1–5)

Inherited from the P2.1 + P2.2 + P2.3 test scaffolding, repeated here so core tasks don't re-discover them:

1. **REST routes must register on the `rest_api_init` action**, not directly in `setUp()`. Wrap registration:
    ```php
    add_action('rest_api_init', static function () use ($controller) {
        register_rest_route('defyn-connector/v1', '/core/update', [...]);
    });
    do_action('rest_api_init');
    ```
2. **WP's route regex is case-insensitive** — invalid-route tests must use truly out-of-class characters (`under_score`, `dot.test`) to force a 404, not just uppercase.
3. **The `pre_set_site_transient_update_core` filter** is the correct mock surface for forcing `wp_version_check()` outcomes. Returning `false` from the filter aborts the transient set and is what we use to simulate `core.refresh_failed`.
4. **`WP_AUTO_UPDATE_CORE` is a PHP constant**, not a runtime value. The four `is_auto_update_enabled` sub-cases (undefined / `true` / `'minor'` / `false`) MUST use the `@runInSeparateProcess` PHPUnit annotation so the constant can be (un)defined per case without affecting siblings.
5. **`Core_Upgrader::upgrade()` second argument** — unlike `Plugin_Upgrader`/`Theme_Upgrader` which take a string slug, `Core_Upgrader::upgrade()` takes the matching `$update` object from `get_core_updates()`. The service flow reads the transient, finds the matching `'upgrade'` response entry, and passes it through.

---

## File structure overview

### Connector plugin (v0.1.6) — new files

| Path | Responsibility |
|---|---|
| `src/SiteInfo/CoreUpgradeException.php` | Base abstract for the three new exceptions below |
| `src/SiteInfo/NoCoreUpdateAvailableException.php` | `get_core_updates()` returned no `upgrade` response |
| `src/SiteInfo/MajorUpdateBlockedException.php` | Target is a major bump; P2.4.1 will handle |
| `src/SiteInfo/CoreUpgradeFailedException.php` | `Core_Upgrader::upgrade()` returned `false` / `WP_Error` |
| `src/SiteInfo/CoreUpgraderService.php` | Drives `Core_Upgrader` with constructor-injected factory + maps results to envelope shape |
| `src/Rest/CoreRefreshController.php` | `POST /core/refresh` — `wp_version_check()` then collect |
| `src/Rest/CoreUpdateController.php` | `POST /core/update` — shared lock + ob_start discipline + service dispatch |
| `tests/Unit/SiteInfo/CollectorCoreTest.php` | Extends `CollectorTest` with the new `core` sub-object |
| `tests/Unit/SiteInfo/CoreUpgraderServiceTest.php` | 5 cases — no update, major block, false return, WP_Error return, success |
| `tests/Integration/Rest/StatusCoreExtensionTest.php` | `/status` includes the `core` sub-object |
| `tests/Integration/Rest/CoreRefreshTest.php` | Signed POST + success + 502 refresh_failed |
| `tests/Integration/Rest/CoreUpdateTest.php` | All envelope cases + STDOUT regression |
| `tests/Integration/Rest/CoreUpdateLockTest.php` | 4 cross-resource collisions + cleanup-on-exception |
| `tests/Integration/Rest/CoreCacheHeadersTest.php` | `Cache-Control: no-store` regression on the new routes |

### Connector plugin — modified files

| Path | What changes |
|---|---|
| `src/SiteInfo/Collector.php` | Add `collectCoreUpdate()` private helper + invoke from `collect()` |
| `src/Rest/RestRouter.php` | Register the two new core routes |
| `defyn-connector.php` | Version `0.1.5` → `0.1.6` |
| `readme.txt` | Stable tag + changelog entry |
| `composer.json` | Version `0.1.5` → `0.1.6` |

### Dashboard plugin (v0.5.0) — new files

| Path | Responsibility |
|---|---|
| `src/Jobs/RefreshSiteCore.php` | AS hook handler for `defyn_refresh_site_core` |
| `src/Jobs/UpdateSiteCore.php` | AS hook handler for `defyn_update_site_core($siteId, $attempt)` with 5-branch response handling |
| `src/Rest/SitesCoreRefreshController.php` | `POST /sites/{id}/core/refresh` |
| `src/Rest/SitesCoreUpdateController.php` | `POST /sites/{id}/core/update` with 4 preflight guards |
| `tests/Integration/Schema/SchemaVersionMigrationV5Test.php` | v4→v5 column add + idempotency + index guard |
| `tests/Integration/Services/SitesRepositoryCoreTest.php` | 4 markCoreUpdate* methods + extended markSynced heal logic |
| `tests/Integration/Services/SyncServiceCoreTest.php` | Core sub-object propagation through markSynced |
| `tests/Integration/Jobs/RefreshSiteCoreTest.php` | AS job success + transport failure |
| `tests/Integration/Jobs/UpdateSiteCoreTest.php` | 5-branch response handling + 300s timeout assertion |
| `tests/Integration/Rest/SitesCoreRefreshTest.php` | POST + separate rate-limit bucket assertion |
| `tests/Integration/Rest/SitesCoreUpdateTest.php` | 4 preflight guards + 3/hr rate limit + separate-bucket assertion |
| `tests/Integration/Rest/SitesCoreUpdateCorsTest.php` | CORS regression on the new core routes |
| `tests/Integration/PluginBootASHookCoreTest.php` | Both hooks registered + SyncSite schedules core refresh |
| `tests/Unit/Models/SiteCoreExtensionTest.php` | Site model surfaces the 5 new core fields through fromRow + toJson |

### Dashboard plugin — modified files

| Path | What changes |
|---|---|
| `src/Activation.php` | Bump `SCHEMA_VERSION` `4` → `5`; add `addCoreUpdateColumns()` private method |
| `src/Models/Site.php` | Add 5 new readonly properties + extend `fromRow` + `toJson` |
| `src/Services/SitesRepository.php` | Add 4 markCoreUpdate* methods + extend `markSynced` with single-row heal |
| `src/Jobs/SyncSite.php` | One-line addition — schedule `defyn_refresh_site_core` alongside plugins + themes |
| `src/Plugin.php` | Register the 2 new AS hook handlers |
| `src/Rest/Middleware/RateLimit.php` | Add `sitesCoreRefresh` (6/hr) + `coreUpdate` (**3/hr**) methods + constants |
| `src/Rest/RestRouter.php` | Register the 2 new core routes |
| `defyn-dashboard.php` | Version `0.4.0` → `0.5.0` |
| `readme.txt` | Stable tag + changelog entry |

`src/Uninstaller.php` — **no changes**. The 5 new columns drop automatically when the `wp_defyn_sites` table drops on uninstall.

### SPA — new files

| Path | Responsibility |
|---|---|
| `apps/web/src/components/sites/SiteCoreCard.tsx` | 4-visual-state card |
| `apps/web/src/components/sites/ConfirmUpdateCoreDialog.tsx` | TWO warning banners + conditional auto-update copy |
| `apps/web/src/lib/mutations/useRefreshSiteCore.ts` | Mutation hook |
| `apps/web/src/lib/mutations/useUpdateSiteCore.ts` | Mutation hook with polling pin (30s, 5min cap) |
| `apps/web/tests/components/sites/SiteCoreCard.test.tsx` | 4-state rendering tests |
| `apps/web/tests/components/sites/ConfirmUpdateCoreDialog.test.tsx` | Two-banner + conditional copy tests |
| `apps/web/tests/lib/mutations/useRefreshSiteCore.test.tsx` | Mutation behaviour tests |
| `apps/web/tests/lib/mutations/useUpdateSiteCore.test.tsx` | Mutation + polling pin tests |
| `apps/web/tests/routes/SiteDetail.core.test.tsx` | `SiteCoreCard` placement above `SiteSummaryCard` |

### SPA — modified files

| Path | What changes |
|---|---|
| `apps/web/src/types/api.ts` | Extend `siteSchema` with 5 new core fields + 2 optional transient meta fields |
| `apps/web/src/test/handlers.ts` | Extend the `/sites/{id}` MSW handler to surface the new fields + add 2 new POST handlers for `/core/refresh` + `/core/update` |
| `apps/web/src/routes/SiteDetail.tsx` | Render `<SiteCoreCard />` ABOVE `<SiteSummaryCard />`, BELOW `<SiteHeader />` |

---

# Tasks

## Task 1 — Extend `Collector` with `collectCoreUpdate()`

**Files:**
- Modify: `packages/connector-plugin/src/SiteInfo/Collector.php`
- Test: `packages/connector-plugin/tests/Unit/SiteInfo/CollectorCoreTest.php`

Adds the `core` sub-object to the existing `/status` payload. Pure read — never calls `wp_version_check()` here; only reads the `update_core` site transient that WP refreshes via its own cron.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Unit\SiteInfo;

use Defyn\Connector\SiteInfo\Collector;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class CollectorCoreTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_site_transient('update_core');
    }

    public function testCoreSubObjectPresentWhenNoUpdateAvailable(): void
    {
        $result = (new Collector())->collect();

        self::assertArrayHasKey('core', $result);
        $core = $result['core'];
        self::assertFalse($core['update_available']);
        self::assertNull($core['update_version']);
        self::assertFalse($core['is_minor_update']);
        self::assertIsBool($core['is_auto_update_enabled']);
    }

    public function testCoreSurfacesMinorUpdateFromTransient(): void
    {
        $current = (string) get_bloginfo('version');
        [$maj, $min] = explode('.', $current) + [1 => '0'];
        $target = $maj . '.' . $min . '.1';

        $update = new \stdClass();
        $update->updates = [(object) [
            'response' => 'upgrade',
            'current'  => $target,
            'version'  => $target,
        ]];
        set_site_transient('update_core', $update);

        $result = (new Collector())->collect();
        $core = $result['core'];

        self::assertTrue($core['update_available']);
        self::assertSame($target, $core['update_version']);
        self::assertTrue($core['is_minor_update']);
    }

    public function testCoreFlagsMajorUpdateAsNonMinor(): void
    {
        $current = (string) get_bloginfo('version');
        [$maj] = explode('.', $current) + [0 => '0'];
        $target = ((int) $maj + 1) . '.0';

        $update = new \stdClass();
        $update->updates = [(object) [
            'response' => 'upgrade',
            'current'  => $target,
            'version'  => $target,
        ]];
        set_site_transient('update_core', $update);

        $result = (new Collector())->collect();
        $core = $result['core'];

        self::assertTrue($core['update_available']);
        self::assertSame($target, $core['update_version']);
        self::assertFalse($core['is_minor_update']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAutoUpdatesEnabledWhenConstantUndefined(): void
    {
        $result = (new Collector())->collect();
        self::assertTrue($result['core']['is_auto_update_enabled']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAutoUpdatesEnabledWhenConstantTrue(): void
    {
        define('WP_AUTO_UPDATE_CORE', true);
        $result = (new Collector())->collect();
        self::assertTrue($result['core']['is_auto_update_enabled']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAutoUpdatesEnabledWhenConstantMinor(): void
    {
        define('WP_AUTO_UPDATE_CORE', 'minor');
        $result = (new Collector())->collect();
        self::assertTrue($result['core']['is_auto_update_enabled']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAutoUpdatesDisabledWhenConstantFalse(): void
    {
        define('WP_AUTO_UPDATE_CORE', false);
        $result = (new Collector())->collect();
        self::assertFalse($result['core']['is_auto_update_enabled']);
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

```
cd packages/connector-plugin && composer test -- --filter CollectorCoreTest
```

Expected: FAIL — `core` key missing from the existing `Collector::collect()` payload.

- [ ] **Step 3: Implement — modify `Collector.php`**

Open `packages/connector-plugin/src/SiteInfo/Collector.php`. In `Collector::collect()`, add a new key to the returned array (place it after `ssl_expires_at`, before `server_time`):

```php
'core' => $this->collectCoreUpdate(),
```

Then add the three private helpers at the bottom of the class:

```php
/**
 * P2.4 — read the WP `update_core` site transient and shape the SPA-visible
 * core sub-object. Pure read: never calls wp_version_check() here. The
 * transient is refreshed by WP's own cron; on-demand refresh lives in
 * POST /defyn-connector/v1/core/refresh.
 *
 * @return array{
 *   update_available: bool,
 *   update_version: ?string,
 *   is_minor_update: bool,
 *   is_auto_update_enabled: bool
 * }
 */
private function collectCoreUpdate(): array
{
    if (!function_exists('get_core_updates')) {
        require_once ABSPATH . 'wp-admin/includes/update.php';
    }

    $current = (string) get_bloginfo('version');
    $updates = get_core_updates(['available' => true, 'dismissed' => false]);

    foreach ((array) $updates as $u) {
        if (!isset($u->response) || $u->response !== 'upgrade') {
            continue;
        }
        $target = (string) ($u->current ?? $u->version ?? '');
        if ($target === '') {
            continue;
        }
        return [
            'update_available'       => true,
            'update_version'         => $target,
            'is_minor_update'        => self::isMinorUpgrade($current, $target),
            'is_auto_update_enabled' => self::isMinorAutoUpdateEnabled(),
        ];
    }

    return [
        'update_available'       => false,
        'update_version'         => null,
        'is_minor_update'        => false,
        'is_auto_update_enabled' => self::isMinorAutoUpdateEnabled(),
    ];
}

private static function isMinorUpgrade(string $current, string $target): bool
{
    [$cMaj, $cMin] = array_pad(array_slice(explode('.', $current), 0, 2), 2, '0');
    [$tMaj, $tMin] = array_pad(array_slice(explode('.', $target), 0, 2), 2, '0');
    return $cMaj === $tMaj && $cMin === $tMin;
}

private static function isMinorAutoUpdateEnabled(): bool
{
    if (!defined('WP_AUTO_UPDATE_CORE')) {
        // WP default: minor updates enabled.
        return true;
    }
    return in_array(WP_AUTO_UPDATE_CORE, [true, 'minor', 'minor-security'], true);
}
```

- [ ] **Step 4: Run the test, verify it passes**

```
cd packages/connector-plugin && composer test -- --filter CollectorCoreTest
```

Expected: PASS (7/7).

- [ ] **Step 5: Commit**

```bash
git add packages/connector-plugin/src/SiteInfo/Collector.php \
        packages/connector-plugin/tests/Unit/SiteInfo/CollectorCoreTest.php
git commit -m "feat(p2-4): Collector emits core sub-object on /status"
```

---

## Task 2 — `CoreUpgraderService` + 3 exceptions

**Files:**
- Create: `packages/connector-plugin/src/SiteInfo/CoreUpgradeException.php`
- Create: `packages/connector-plugin/src/SiteInfo/NoCoreUpdateAvailableException.php`
- Create: `packages/connector-plugin/src/SiteInfo/MajorUpdateBlockedException.php`
- Create: `packages/connector-plugin/src/SiteInfo/CoreUpgradeFailedException.php`
- Create: `packages/connector-plugin/src/SiteInfo/CoreUpgraderService.php`
- Test: `packages/connector-plugin/tests/Unit/SiteInfo/CoreUpgraderServiceTest.php`

Calls `wp_version_check()` to refresh first, reads `get_core_updates()`, throws on no-update / major-bump, then dispatches through the constructor-injected upgrader factory. Returns the success envelope shape. `CapturingUpgraderSkin` is reused as-is from P2.2 — it works for `Core_Upgrader` because the skin ABI is shared.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Unit\SiteInfo;

use Defyn\Connector\SiteInfo\CapturingUpgraderSkin;
use Defyn\Connector\SiteInfo\CoreUpgradeFailedException;
use Defyn\Connector\SiteInfo\CoreUpgraderService;
use Defyn\Connector\SiteInfo\MajorUpdateBlockedException;
use Defyn\Connector\SiteInfo\NoCoreUpdateAvailableException;
use WP_UnitTestCase;

final class CoreUpgraderServiceTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_site_transient('update_core');
        // Suppress wp_version_check()'s network call in all cases.
        add_filter('pre_set_site_transient_update_core', static fn ($value) => $value, 10, 1);
    }

    public function testUpgradeWithNoUpdateThrowsNoCoreUpdateAvailable(): void
    {
        $service = new CoreUpgraderService(fn () => $this->fail('factory should not be called'));

        $this->expectException(NoCoreUpdateAvailableException::class);
        $service->upgrade();
    }

    public function testUpgradeWithMajorBumpThrowsMajorUpdateBlocked(): void
    {
        $current = (string) get_bloginfo('version');
        [$maj] = explode('.', $current) + [0 => '0'];
        $target = ((int) $maj + 1) . '.0';
        $this->seedUpdateAvailable($target);

        $service = new CoreUpgraderService(fn () => $this->fail('factory should not be called'));

        $this->expectException(MajorUpdateBlockedException::class);
        $this->expectExceptionMessage($target);
        $service->upgrade();
    }

    public function testUpgradeWithFalseReturnThrowsCoreUpgradeFailed(): void
    {
        $current = (string) get_bloginfo('version');
        [$maj, $min] = explode('.', $current) + [1 => '0'];
        $target = $maj . '.' . $min . '.1';
        $this->seedUpdateAvailable($target);

        $service = new CoreUpgraderService(function (CapturingUpgraderSkin $skin) {
            $skin->error('Could not copy file. /wp-admin/index.php');
            return new class {
                public function upgrade($update) { return false; }
            };
        });

        $this->expectException(CoreUpgradeFailedException::class);
        $this->expectExceptionMessage('Could not copy file');
        $service->upgrade();
    }

    public function testUpgradeWithWpErrorThrowsCoreUpgradeFailed(): void
    {
        $current = (string) get_bloginfo('version');
        [$maj, $min] = explode('.', $current) + [1 => '0'];
        $target = $maj . '.' . $min . '.1';
        $this->seedUpdateAvailable($target);

        $service = new CoreUpgraderService(fn () => new class {
            public function upgrade($update) {
                return new \WP_Error('download_failed', 'HTTP 404 from downloads.wordpress.org.');
            }
        });

        $this->expectException(CoreUpgradeFailedException::class);
        $this->expectExceptionMessage('HTTP 404');
        $service->upgrade();
    }

    public function testUpgradeSuccessReturnsExpectedShape(): void
    {
        $current = (string) get_bloginfo('version');
        [$maj, $min] = explode('.', $current) + [1 => '0'];
        $target = $maj . '.' . $min . '.1';
        $this->seedUpdateAvailable($target);

        $service = new CoreUpgraderService(fn () => new class {
            public function upgrade($update) { return true; }
        });

        $before = time();
        $result = $service->upgrade();
        $after  = time();

        $this->assertTrue($result['success']);
        $this->assertSame($current, $result['previous_version']);
        // Stub didn't actually swap files; re-read returns the same version.
        $this->assertSame($current, $result['new_version']);
        $this->assertIsInt($result['server_time']);
        $this->assertGreaterThanOrEqual($before, $result['server_time']);
        $this->assertLessThanOrEqual($after, $result['server_time']);
    }

    private function seedUpdateAvailable(string $target): void
    {
        $update = new \stdClass();
        $update->updates = [(object) [
            'response' => 'upgrade',
            'current'  => $target,
            'version'  => $target,
            'package'  => 'https://example.test/wp.zip',
            'locale'   => 'en_US',
        ]];
        $update->version_checked = (string) get_bloginfo('version');
        set_site_transient('update_core', $update);
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

```
cd packages/connector-plugin && composer test -- --filter CoreUpgraderServiceTest
```

Expected: FAIL — `CoreUpgraderService` not found.

- [ ] **Step 3: Implement — exceptions first**

```php
<?php
// src/SiteInfo/CoreUpgradeException.php
declare(strict_types=1);

namespace Defyn\Connector\SiteInfo;

abstract class CoreUpgradeException extends \RuntimeException {}
```

```php
<?php
// src/SiteInfo/NoCoreUpdateAvailableException.php
declare(strict_types=1);

namespace Defyn\Connector\SiteInfo;

final class NoCoreUpdateAvailableException extends CoreUpgradeException
{
    public function __construct(string $message = 'No core update available.')
    {
        parent::__construct($message);
    }
}
```

```php
<?php
// src/SiteInfo/MajorUpdateBlockedException.php
declare(strict_types=1);

namespace Defyn\Connector\SiteInfo;

final class MajorUpdateBlockedException extends CoreUpgradeException
{
    public function __construct(string $current, string $target)
    {
        parent::__construct(sprintf(
            'Major-version updates (%s -> %s) require P2.4.1.',
            $current,
            $target
        ));
    }
}
```

```php
<?php
// src/SiteInfo/CoreUpgradeFailedException.php
declare(strict_types=1);

namespace Defyn\Connector\SiteInfo;

final class CoreUpgradeFailedException extends CoreUpgradeException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
```

Then the service:

```php
<?php
// src/SiteInfo/CoreUpgraderService.php
declare(strict_types=1);

namespace Defyn\Connector\SiteInfo;

/**
 * P2.4 — runs WordPress's Core_Upgrader on the active install.
 *
 * Single-resource: no slug. The service refreshes the `update_core` site
 * transient first via wp_version_check() — never trust the cached
 * transient on a destructive code path — then reads the freshly-refreshed
 * transient and dispatches the upgrade through the constructor-injected
 * factory.
 *
 * Exception -> controller envelope (see CoreUpdateController):
 *   NoCoreUpdateAvailableException -> 409 core.no_update_available
 *   MajorUpdateBlockedException    -> 409 core.major_update_blocked
 *   CoreUpgradeFailedException     -> 502 core.update_failed
 */
final class CoreUpgraderService
{
    /** @var callable(CapturingUpgraderSkin): object */
    private $upgraderFactory;

    /**
     * @param callable(CapturingUpgraderSkin): object|null $upgraderFactory
     */
    public function __construct(?callable $upgraderFactory = null)
    {
        $this->upgraderFactory = $upgraderFactory ?? self::defaultUpgraderFactory();
    }

    /**
     * @return array{success: true, previous_version: string, new_version: string, server_time: int}
     */
    public function upgrade(): array
    {
        if (!function_exists('wp_version_check')) {
            require_once ABSPATH . 'wp-includes/update.php';
        }
        if (!function_exists('get_core_updates')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }

        wp_version_check();

        $current = (string) get_bloginfo('version');
        $updates = get_core_updates(['available' => true, 'dismissed' => false]);

        $matching = null;
        foreach ((array) $updates as $u) {
            if (isset($u->response) && $u->response === 'upgrade') {
                $matching = $u;
                break;
            }
        }
        if ($matching === null) {
            throw new NoCoreUpdateAvailableException(
                'WordPress reports no core update available.'
            );
        }

        $target = (string) ($matching->current ?? $matching->version ?? '');
        if ($target === '') {
            throw new NoCoreUpdateAvailableException(
                'WordPress upgrade response is missing the target version.'
            );
        }

        if (!self::isMinorUpgrade($current, $target)) {
            throw new MajorUpdateBlockedException($current, $target);
        }

        $skin     = new CapturingUpgraderSkin();
        $upgrader = ($this->upgraderFactory)($skin);
        $result   = $upgrader->upgrade($matching);

        if ($result === false) {
            $message = $skin->lastErrorMessage() ?? 'Core_Upgrader returned false without a message.';
            throw new CoreUpgradeFailedException($message);
        }
        if (is_wp_error($result)) {
            throw new CoreUpgradeFailedException((string) $result->get_error_message());
        }

        global $wp_version;
        $newVersion = (string) ($wp_version ?? get_bloginfo('version'));
        if ($newVersion === '') {
            $newVersion = $current;
        }

        return [
            'success'          => true,
            'previous_version' => $current,
            'new_version'      => $newVersion,
            'server_time'      => time(),
        ];
    }

    private static function isMinorUpgrade(string $current, string $target): bool
    {
        [$cMaj, $cMin] = array_pad(array_slice(explode('.', $current), 0, 2), 2, '0');
        [$tMaj, $tMin] = array_pad(array_slice(explode('.', $target), 0, 2), 2, '0');
        return $cMaj === $tMaj && $cMin === $tMin;
    }

    /** @return callable(CapturingUpgraderSkin): object */
    private static function defaultUpgraderFactory(): callable
    {
        return static function (CapturingUpgraderSkin $skin): object {
            if (!class_exists(\Core_Upgrader::class)) {
                require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
                require_once ABSPATH . 'wp-admin/includes/class-core-upgrader.php';
            }
            return new \Core_Upgrader($skin);
        };
    }
}
```

- [ ] **Step 4: Run the test, verify it passes**

```
cd packages/connector-plugin && composer test -- --filter CoreUpgraderServiceTest
```

Expected: PASS (5/5).

- [ ] **Step 5: Commit**

```bash
git add packages/connector-plugin/src/SiteInfo/CoreUpgradeException.php \
        packages/connector-plugin/src/SiteInfo/NoCoreUpdateAvailableException.php \
        packages/connector-plugin/src/SiteInfo/MajorUpdateBlockedException.php \
        packages/connector-plugin/src/SiteInfo/CoreUpgradeFailedException.php \
        packages/connector-plugin/src/SiteInfo/CoreUpgraderService.php \
        packages/connector-plugin/tests/Unit/SiteInfo/CoreUpgraderServiceTest.php
git commit -m "feat(p2-4): CoreUpgraderService drives Core_Upgrader with 3 exception envelope"
```

---

## Task 3 — `CoreRefreshController` `POST /defyn-connector/v1/core/refresh`

**Files:**
- Create: `packages/connector-plugin/src/Rest/CoreRefreshController.php`
- Test: `packages/connector-plugin/tests/Integration/Rest/CoreRefreshTest.php`

Forces a fresh `wp_version_check()` call (contacts api.wordpress.org), then returns the freshly-collected core sub-object. On failure (filter aborts the transient set), return 502 `core.refresh_failed`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Rest;

use Defyn\Connector\Crypto\Signer;
use Defyn\Connector\Persistence\ConnectorState;
use Defyn\Connector\Rest\CoreRefreshController;
use WP_REST_Request;
use WP_UnitTestCase;

final class CoreRefreshTest extends WP_UnitTestCase
{
    private string $privateKeyBase64;

    protected function setUp(): void
    {
        parent::setUp();
        delete_site_transient('update_core');

        $keypair = sodium_crypto_sign_keypair();
        $this->privateKeyBase64 = base64_encode(sodium_crypto_sign_secretkey($keypair));

        (new ConnectorState())->update([
            'state'                => 'connected',
            'dashboard_public_key' => base64_encode(sodium_crypto_sign_publickey($keypair)),
            'connected_at'         => gmdate('Y-m-d H:i:s'),
        ]);

        add_action('rest_api_init', static function (): void {
            register_rest_route('defyn-connector/v1', '/core/refresh', [
                'methods'             => 'POST',
                'callback'            => [new CoreRefreshController(), 'handle'],
                'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
            ]);
        });
        do_action('rest_api_init');
    }

    public function testRefreshCallsWpVersionCheckAndReturnsCorePayload(): void
    {
        $called = false;
        add_filter('pre_set_site_transient_update_core', static function ($value) use (&$called) {
            $called = true;
            $current = (string) get_bloginfo('version');
            [$maj, $min] = explode('.', $current) + [1 => '0'];
            $target = $maj . '.' . $min . '.1';
            $update = new \stdClass();
            $update->updates = [(object) [
                'response' => 'upgrade',
                'current'  => $target,
                'version'  => $target,
            ]];
            return $update;
        });

        $res = $this->sendSigned();

        $this->assertTrue($called, 'wp_version_check() must run');
        $this->assertSame(200, $res->get_status());
        $data = $res->get_data();
        $this->assertArrayHasKey('update_available', $data);
        $this->assertArrayHasKey('update_version', $data);
        $this->assertArrayHasKey('is_minor_update', $data);
        $this->assertArrayHasKey('is_auto_update_enabled', $data);
        $this->assertArrayHasKey('server_time', $data);
        $this->assertTrue($data['update_available']);
        $this->assertTrue($data['is_minor_update']);
    }

    public function testRefreshFailureReturns502(): void
    {
        add_filter('pre_set_site_transient_update_core', static fn () => false);
        add_filter('pre_http_request', static function () {
            return new \WP_Error('http_request_failed', 'Connection timed out.');
        });

        $res = $this->sendSigned();

        $this->assertSame(502, $res->get_status());
        $this->assertSame('core.refresh_failed', $res->get_data()['error']['code']);
    }

    private function sendSigned(): \WP_REST_Response
    {
        $ts        = (string) time();
        $nonce     = bin2hex(random_bytes(16));
        $canonical = Signer::canonical('POST', '/defyn-connector/v1/core/refresh', $ts, $nonce, '');
        $sig       = base64_encode(sodium_crypto_sign_detached(
            $canonical,
            base64_decode($this->privateKeyBase64)
        ));

        $request = new WP_REST_Request('POST', '/defyn-connector/v1/core/refresh');
        $request->set_header('X-Defyn-Timestamp', $ts);
        $request->set_header('X-Defyn-Nonce', $nonce);
        $request->set_header('X-Defyn-Signature', $sig);
        return rest_do_request($request);
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

```
cd packages/connector-plugin && composer test -- --filter CoreRefreshTest
```

Expected: FAIL — `CoreRefreshController` not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Rest;

use Defyn\Connector\Rest\Responses\ErrorResponse;
use Defyn\Connector\SiteInfo\Collector;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles POST /defyn-connector/v1/core/refresh (spec § 3.2).
 *
 * Forces a fresh wp_version_check() poll then returns the freshly-collected
 * `core` sub-object (no surrounding `core` wrapper — caller already knows
 * they hit /core/refresh). Signature gate runs in VerifySignatureMiddleware.
 */
final class CoreRefreshController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        if (!function_exists('wp_version_check')) {
            require_once ABSPATH . 'wp-includes/update.php';
        }

        if (!function_exists('wp_version_check')) {
            return ErrorResponse::create(
                502,
                'core.refresh_failed',
                'WP update subsystem unavailable on this site.'
            );
        }

        try {
            wp_version_check();
        } catch (\Throwable $e) {
            return ErrorResponse::create(
                502,
                'core.refresh_failed',
                'wp_version_check() failed: ' . $e->getMessage()
            );
        }

        $transient = get_site_transient('update_core');
        if ($transient === false || $transient === null) {
            return ErrorResponse::create(
                502,
                'core.refresh_failed',
                'wp_version_check() did not populate the update_core transient.'
            );
        }

        $full = (new Collector())->collect();
        $core = $full['core'] ?? [
            'update_available'       => false,
            'update_version'         => null,
            'is_minor_update'        => false,
            'is_auto_update_enabled' => true,
        ];
        $core['server_time'] = time();

        return new WP_REST_Response($core, 200);
    }
}
```

- [ ] **Step 4: Run the test, verify it passes**

```
cd packages/connector-plugin && composer test -- --filter CoreRefreshTest
```

Expected: PASS (2/2).

- [ ] **Step 5: Commit**

```bash
git add packages/connector-plugin/src/Rest/CoreRefreshController.php \
        packages/connector-plugin/tests/Integration/Rest/CoreRefreshTest.php
git commit -m "feat(p2-4): POST /defyn-connector/v1/core/refresh signed endpoint"
```

---

## Task 4 — `CoreUpdateController` + STDOUT regression (day 1)

**Files:**
- Create: `packages/connector-plugin/src/Rest/CoreUpdateController.php`
- Test: `packages/connector-plugin/tests/Integration/Rest/CoreUpdateTest.php`

The controller acquires the **shared** `defyn_connector_upgrade_in_flight` transient lock (same key as plugin + theme updates — covers cross-resource collisions per spec § 3.4), wraps the service call in `ob_start()` / `ob_end_clean()` inside a `try/finally` to absorb stray STDOUT bytes (P2.2.1 carry-over from day 1), and maps exceptions to spec-shape error envelopes.

This task covers happy path + all error envelopes + the STDOUT regression. Cross-resource lock collision is covered by Task 5.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Rest;

use Defyn\Connector\Crypto\Signer;
use Defyn\Connector\Persistence\ConnectorState;
use Defyn\Connector\Rest\CoreUpdateController;
use Defyn\Connector\SiteInfo\CapturingUpgraderSkin;
use Defyn\Connector\SiteInfo\CoreUpgraderService;
use WP_REST_Request;
use WP_UnitTestCase;

final class CoreUpdateTest extends WP_UnitTestCase
{
    private string $privateKeyBase64;

    protected function setUp(): void
    {
        parent::setUp();
        delete_site_transient('update_core');
        delete_transient('defyn_connector_upgrade_in_flight');
        add_filter('pre_set_site_transient_update_core', static fn ($value) => $value, 10, 1);

        $keypair = sodium_crypto_sign_keypair();
        $this->privateKeyBase64 = base64_encode(sodium_crypto_sign_secretkey($keypair));

        (new ConnectorState())->update([
            'state'                => 'connected',
            'dashboard_public_key' => base64_encode(sodium_crypto_sign_publickey($keypair)),
            'connected_at'         => gmdate('Y-m-d H:i:s'),
        ]);

        add_action('rest_api_init', static function (): void {
            register_rest_route('defyn-connector/v1', '/core/update', [
                'methods'             => 'POST',
                'callback'            => [new CoreUpdateController(), 'handle'],
                'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
            ]);
        });
        do_action('rest_api_init');
    }

    public function testNoUpdateAvailableReturns409(): void
    {
        $res = $this->sendSigned();

        $this->assertSame(409, $res->get_status());
        $this->assertSame('core.no_update_available', $res->get_data()['error']['code']);
    }

    public function testMajorBumpReturns409(): void
    {
        $current = (string) get_bloginfo('version');
        [$maj] = explode('.', $current) + [0 => '0'];
        $target = ((int) $maj + 1) . '.0';
        $this->seedUpdateAvailable($target);

        $res = $this->sendSigned();

        $this->assertSame(409, $res->get_status());
        $this->assertSame('core.major_update_blocked', $res->get_data()['error']['code']);
        $this->assertStringContainsString($target, $res->get_data()['error']['message']);
    }

    public function testSuccessReturns200WithExpectedShape(): void
    {
        $current = (string) get_bloginfo('version');
        [$maj, $min] = explode('.', $current) + [1 => '0'];
        $target = $maj . '.' . $min . '.1';
        $this->seedUpdateAvailable($target);

        $controller = new CoreUpdateController(
            new CoreUpgraderService(
                fn () => new class { public function upgrade($update) { return true; } }
            )
        );
        register_rest_route('defyn-connector/v1', '/core/update', [
            'methods'             => 'POST',
            'callback'            => [$controller, 'handle'],
            'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
        ], true);

        $res = $this->sendSigned();

        $this->assertSame(200, $res->get_status());
        $data = $res->get_data();
        $this->assertTrue($data['success']);
        $this->assertIsString($data['previous_version']);
        $this->assertIsString($data['new_version']);
        $this->assertIsInt($data['server_time']);
    }

    public function testUpgradeFailureReturns502(): void
    {
        $current = (string) get_bloginfo('version');
        [$maj, $min] = explode('.', $current) + [1 => '0'];
        $target = $maj . '.' . $min . '.1';
        $this->seedUpdateAvailable($target);

        $controller = new CoreUpdateController(
            new CoreUpgraderService(
                function (CapturingUpgraderSkin $skin) {
                    $skin->error('Could not copy file. /wp-admin/index.php');
                    return new class { public function upgrade($update) { return false; } };
                }
            )
        );
        register_rest_route('defyn-connector/v1', '/core/update', [
            'methods'             => 'POST',
            'callback'            => [$controller, 'handle'],
            'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
        ], true);

        $res = $this->sendSigned();

        $this->assertSame(502, $res->get_status());
        $this->assertSame('core.update_failed', $res->get_data()['error']['code']);
        $this->assertStringContainsString('Could not copy file', $res->get_data()['error']['message']);
    }

    public function testInvalidPathReturns404FromRouter(): void
    {
        $request = new WP_REST_Request('POST', '/defyn-connector/v1/core/under_score');
        $res = rest_do_request($request);
        $this->assertSame(404, $res->get_status());
    }

    /**
     * P2.2.1 carry-over (day 1): a Core_Upgrader stub that echoes stray bytes
     * to STDOUT must NOT corrupt the JSON response body — the controller's
     * ob_start/ob_end_clean in `finally` absorbs everything.
     *
     * Without the buffer, the upgrader's "Updating WordPress to ..." string
     * would prepend to the WP_REST_Response body and json_decode on the
     * dashboard side would fail; the dashboard would then mark a successful
     * upgrade as failed (the exact P2.2 production bug fix `7a05d48`).
     */
    public function testStdoutFromUpgraderDoesNotCorruptResponse(): void
    {
        $current = (string) get_bloginfo('version');
        [$maj, $min] = explode('.', $current) + [1 => '0'];
        $target = $maj . '.' . $min . '.1';
        $this->seedUpdateAvailable($target);

        $controller = new CoreUpdateController(
            new CoreUpgraderService(
                fn () => new class {
                    public function upgrade($update) {
                        echo 'Updating WordPress to ' . ($update->current ?? '?') . '...';
                        echo "\n<p>HTML noise the dashboard never sees</p>";
                        return true;
                    }
                }
            )
        );
        register_rest_route('defyn-connector/v1', '/core/update', [
            'methods'             => 'POST',
            'callback'            => [$controller, 'handle'],
            'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
        ], true);

        ob_start();
        $res = $this->sendSigned();
        $stray = ob_get_clean();

        $this->assertSame(200, $res->get_status());
        $data = $res->get_data();
        $this->assertTrue($data['success']);
        $this->assertSame('', $stray, 'controller must absorb upgrader STDOUT');
    }

    private function sendSigned(): \WP_REST_Response
    {
        $ts        = (string) time();
        $nonce     = bin2hex(random_bytes(16));
        $canonical = Signer::canonical('POST', '/defyn-connector/v1/core/update', $ts, $nonce, '');
        $sig       = base64_encode(sodium_crypto_sign_detached(
            $canonical,
            base64_decode($this->privateKeyBase64)
        ));

        $request = new WP_REST_Request('POST', '/defyn-connector/v1/core/update');
        $request->set_header('X-Defyn-Timestamp', $ts);
        $request->set_header('X-Defyn-Nonce', $nonce);
        $request->set_header('X-Defyn-Signature', $sig);

        return rest_do_request($request);
    }

    private function seedUpdateAvailable(string $target): void
    {
        $update = new \stdClass();
        $update->updates = [(object) [
            'response' => 'upgrade',
            'current'  => $target,
            'version'  => $target,
            'package'  => 'https://example.test/wp.zip',
            'locale'   => 'en_US',
        ]];
        $update->version_checked = (string) get_bloginfo('version');
        set_site_transient('update_core', $update);
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

```
cd packages/connector-plugin && composer test -- --filter CoreUpdateTest
```

Expected: FAIL — `CoreUpdateController` not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Rest;

use Defyn\Connector\Rest\Responses\ErrorResponse;
use Defyn\Connector\SiteInfo\CoreUpgradeFailedException;
use Defyn\Connector\SiteInfo\CoreUpgraderService;
use Defyn\Connector\SiteInfo\MajorUpdateBlockedException;
use Defyn\Connector\SiteInfo\NoCoreUpdateAvailableException;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /defyn-connector/v1/core/update — signed.
 *
 * Single resource — no slug. Acquires the SHARED
 * defyn_connector_upgrade_in_flight transient lock (same key as plugin +
 * theme update endpoints) so concurrent plugin/theme/core upgrade requests
 * on the same install serialise. Second hitter returns 409
 * connector.upgrade_in_progress.
 *
 * STDOUT discipline: wrap the service call in ob_start/ob_end_clean inside
 * try/finally. Core_Upgrader echoes HTML directly because it normally runs
 * inside wp-admin. Without the buffer, stray bytes prepend/append to the
 * JSON response and break json_decode on the dashboard — same P2.2.1 fix
 * (`7a05d48`), retyped from day 1.
 *
 * Exception -> envelope:
 *   NoCoreUpdateAvailableException -> 409 core.no_update_available
 *   MajorUpdateBlockedException    -> 409 core.major_update_blocked
 *   CoreUpgradeFailedException     -> 502 core.update_failed
 *
 * Spec: docs/superpowers/specs/2026-06-07-p2-4-core-updates-design.md §3.3, §3.4
 */
final class CoreUpdateController
{
    /** Shared with PluginUpdateController + ThemeUpdateController. */
    private const LOCK_KEY = 'defyn_connector_upgrade_in_flight';
    private const LOCK_TTL = 600; // 10 min

    public function __construct(
        private readonly CoreUpgraderService $service = new CoreUpgraderService()
    ) {
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $existingLock = get_transient(self::LOCK_KEY);
        if ($existingLock !== false) {
            return ErrorResponse::create(
                409,
                'connector.upgrade_in_progress',
                sprintf('Another upgrade is in progress (%s).', (string) $existingLock)
            );
        }

        set_transient(self::LOCK_KEY, 'core', self::LOCK_TTL);

        ob_start();

        try {
            $result = $this->service->upgrade();
            return new WP_REST_Response($result, 200);
        } catch (NoCoreUpdateAvailableException $e) {
            return ErrorResponse::create(
                409,
                'core.no_update_available',
                $e->getMessage(),
            );
        } catch (MajorUpdateBlockedException $e) {
            return ErrorResponse::create(
                409,
                'core.major_update_blocked',
                $e->getMessage(),
            );
        } catch (CoreUpgradeFailedException $e) {
            return ErrorResponse::create(502, 'core.update_failed', $e->getMessage());
        } finally {
            ob_end_clean();
            delete_transient(self::LOCK_KEY);
        }
    }
}
```

- [ ] **Step 4: Run the test, verify it passes**

```
cd packages/connector-plugin && composer test -- --filter CoreUpdateTest
```

Expected: PASS (6/6).

- [ ] **Step 5: Commit**

```bash
git add packages/connector-plugin/src/Rest/CoreUpdateController.php \
        packages/connector-plugin/tests/Integration/Rest/CoreUpdateTest.php
git commit -m "feat(p2-4): POST /defyn-connector/v1/core/update with shared lock + STDOUT discipline"
```

---

## Task 5 — Cross-resource lock collisions + cache headers + RestRouter registration

**Files:**
- Modify: `packages/connector-plugin/src/Rest/RestRouter.php`
- Test: `packages/connector-plugin/tests/Integration/Rest/CoreUpdateLockTest.php`
- Test: `packages/connector-plugin/tests/Integration/Rest/CoreCacheHeadersTest.php`

Three concerns in one task:

1. **`CoreUpdateLockTest`** — four cross-resource scenarios from day 1:
    - Plugin in flight → core update returns 409 `connector.upgrade_in_progress`
    - Theme in flight → core update returns 409
    - Core in flight → plugin update returns 409
    - Core in flight → theme update returns 409
    - Plus: lock auto-releases on success AND on exception (the `finally` clause).
2. **`CoreCacheHeadersTest`** — emit `Cache-Control: no-store` on both new routes. Manually invoke `apply_filters('rest_post_dispatch', ...)` because `rest_do_request()` skips that pipeline.
3. **`RestRouter::register()`** — register the two new core routes.

- [ ] **Step 1: Write the failing `CoreUpdateLockTest`**

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Rest;

use Defyn\Connector\Crypto\Signer;
use Defyn\Connector\Persistence\ConnectorState;
use Defyn\Connector\Rest\CoreUpdateController;
use Defyn\Connector\Rest\PluginUpdateController;
use Defyn\Connector\Rest\ThemeUpdateController;
use Defyn\Connector\SiteInfo\CoreUpgraderService;
use Defyn\Connector\SiteInfo\PluginUpgraderService;
use Defyn\Connector\SiteInfo\ThemeUpgraderService;
use WP_REST_Request;
use WP_UnitTestCase;

final class CoreUpdateLockTest extends WP_UnitTestCase
{
    private string $privateKeyBase64;

    protected function setUp(): void
    {
        parent::setUp();
        delete_site_transient('update_core');
        delete_site_transient('update_plugins');
        delete_site_transient('update_themes');
        delete_transient('defyn_connector_upgrade_in_flight');
        add_filter('pre_set_site_transient_update_core', static fn ($value) => $value, 10, 1);

        $keypair = sodium_crypto_sign_keypair();
        $this->privateKeyBase64 = base64_encode(sodium_crypto_sign_secretkey($keypair));

        (new ConnectorState())->update([
            'state'                => 'connected',
            'dashboard_public_key' => base64_encode(sodium_crypto_sign_publickey($keypair)),
            'connected_at'         => gmdate('Y-m-d H:i:s'),
        ]);

        $current = (string) get_bloginfo('version');
        [$maj, $min] = explode('.', $current) + [1 => '0'];
        $this->seedCoreUpdate($maj . '.' . $min . '.1');
    }

    public function testCoreLockReleasedOnSuccess(): void
    {
        $this->registerCoreWithStub(true);

        $res1 = $this->sendCoreSigned();
        $this->assertSame(200, $res1->get_status());
        $this->assertFalse(get_transient('defyn_connector_upgrade_in_flight'));

        $res2 = $this->sendCoreSigned();
        $this->assertSame(200, $res2->get_status());
    }

    public function testCoreLockReleasedOnFailure(): void
    {
        $this->registerCoreWithStub(false);

        $res = $this->sendCoreSigned();
        $this->assertSame(502, $res->get_status());
        $this->assertFalse(get_transient('defyn_connector_upgrade_in_flight'));
    }

    public function testCoreBlockedByPluginInFlight(): void
    {
        set_transient('defyn_connector_upgrade_in_flight', 'akismet', 600);
        $this->registerCoreWithStub(true);

        $res = $this->sendCoreSigned();

        $this->assertSame(409, $res->get_status());
        $this->assertSame('connector.upgrade_in_progress', $res->get_data()['error']['code']);
        $this->assertStringContainsString('akismet', $res->get_data()['error']['message']);
    }

    public function testCoreBlockedByThemeInFlight(): void
    {
        set_transient('defyn_connector_upgrade_in_flight', 'twentytwentyfive', 600);
        $this->registerCoreWithStub(true);

        $res = $this->sendCoreSigned();

        $this->assertSame(409, $res->get_status());
        $this->assertSame('connector.upgrade_in_progress', $res->get_data()['error']['code']);
        $this->assertStringContainsString('twentytwentyfive', $res->get_data()['error']['message']);
    }

    public function testPluginBlockedByCoreInFlight(): void
    {
        set_transient('defyn_connector_upgrade_in_flight', 'core', 600);

        $this->seedPluginUpdate('hello', 'hello.php', '99.9');
        $controller = new PluginUpdateController(
            new PluginUpgraderService(fn () => new class { public function upgrade(string $pluginFile) { return true; } })
        );
        register_rest_route('defyn-connector/v1', '/plugins/(?P<slug>[a-z0-9-]{1,80})/update', [
            'methods'             => 'POST',
            'callback'            => [$controller, 'handle'],
            'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
        ], true);

        $res = $this->sendSigned('POST', '/defyn-connector/v1/plugins/hello/update');

        $this->assertSame(409, $res->get_status());
        $this->assertSame('plugins.update_in_progress', $res->get_data()['error']['code']);
    }

    public function testThemeBlockedByCoreInFlight(): void
    {
        set_transient('defyn_connector_upgrade_in_flight', 'core', 600);

        $stylesheet = (string) get_stylesheet();
        $themeUpdate = new \stdClass();
        $themeUpdate->response = [
            $stylesheet => ['theme' => $stylesheet, 'new_version' => '99.9', 'package' => 'https://example.test/theme.zip'],
        ];
        set_site_transient('update_themes', $themeUpdate);

        $controller = new ThemeUpdateController(
            new ThemeUpgraderService(fn () => new class { public function upgrade(string $stylesheet) { return true; } })
        );
        register_rest_route('defyn-connector/v1', '/themes/(?P<slug>[a-z0-9-]{1,80})/update', [
            'methods'             => 'POST',
            'callback'            => [$controller, 'handle'],
            'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
        ], true);

        $res = $this->sendSigned('POST', '/defyn-connector/v1/themes/' . $stylesheet . '/update');

        $this->assertSame(409, $res->get_status());
        $this->assertSame('connector.upgrade_in_progress', $res->get_data()['error']['code']);
    }

    private function registerCoreWithStub(bool $success): void
    {
        $factory = $success
            ? fn () => new class { public function upgrade($update) { return true; } }
            : function (\Defyn\Connector\SiteInfo\CapturingUpgraderSkin $skin) {
                $skin->error('Synthetic test failure.');
                return new class { public function upgrade($update) { return false; } };
            };

        $controller = new CoreUpdateController(new CoreUpgraderService($factory));
        register_rest_route('defyn-connector/v1', '/core/update', [
            'methods'             => 'POST',
            'callback'            => [$controller, 'handle'],
            'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
        ], true);
    }

    private function sendCoreSigned(): \WP_REST_Response
    {
        return $this->sendSigned('POST', '/defyn-connector/v1/core/update');
    }

    private function sendSigned(string $method, string $path): \WP_REST_Response
    {
        $ts        = (string) time();
        $nonce     = bin2hex(random_bytes(16));
        $canonical = Signer::canonical($method, $path, $ts, $nonce, '');
        $sig       = base64_encode(sodium_crypto_sign_detached($canonical, base64_decode($this->privateKeyBase64)));

        $request = new WP_REST_Request($method, $path);
        $request->set_header('X-Defyn-Timestamp', $ts);
        $request->set_header('X-Defyn-Nonce', $nonce);
        $request->set_header('X-Defyn-Signature', $sig);
        return rest_do_request($request);
    }

    private function seedCoreUpdate(string $target): void
    {
        $update = new \stdClass();
        $update->updates = [(object) [
            'response' => 'upgrade',
            'current'  => $target,
            'version'  => $target,
            'package'  => 'https://example.test/wp.zip',
            'locale'   => 'en_US',
        ]];
        $update->version_checked = (string) get_bloginfo('version');
        set_site_transient('update_core', $update);
    }

    private function seedPluginUpdate(string $folder, string $pluginFile, string $newVersion): void
    {
        $update = new \stdClass();
        $update->response = [
            $folder . '/' . $pluginFile => (object) [
                'slug' => $folder,
                'new_version' => $newVersion,
                'package' => 'https://example.test/plugin.zip',
            ],
        ];
        set_site_transient('update_plugins', $update);
    }
}
```

- [ ] **Step 2: Run the test, verify it passes (Task 4's `finally` already satisfies the contract)**

```
cd packages/connector-plugin && composer test -- --filter CoreUpdateLockTest
```

Expected: PASS (6/6). If a test fails, the `finally` in Task 4 is broken — fix it there.

- [ ] **Step 3: Write the failing `CoreCacheHeadersTest`**

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Rest;

use Defyn\Connector\Crypto\Signer;
use Defyn\Connector\Persistence\ConnectorState;
use Defyn\Connector\Rest\RestRouter;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

final class CoreCacheHeadersTest extends WP_UnitTestCase
{
    private string $privateKeyBase64;

    protected function setUp(): void
    {
        parent::setUp();
        delete_site_transient('update_core');
        delete_transient('defyn_connector_upgrade_in_flight');
        add_filter('pre_set_site_transient_update_core', static fn ($value) => $value, 10, 1);

        $keypair = sodium_crypto_sign_keypair();
        $this->privateKeyBase64 = base64_encode(sodium_crypto_sign_secretkey($keypair));

        (new ConnectorState())->update([
            'state'                => 'connected',
            'dashboard_public_key' => base64_encode(sodium_crypto_sign_publickey($keypair)),
            'connected_at'         => gmdate('Y-m-d H:i:s'),
        ]);

        (new RestRouter())->register();
    }

    public function testPostCoreRefreshGetsNoStoreHeaders(): void
    {
        add_filter('pre_set_site_transient_update_core', static function () {
            $fake = new \stdClass();
            $fake->updates = [];
            return $fake;
        });

        $request = $this->makeSignedRequest('POST', '/defyn-connector/v1/core/refresh');
        $response = rest_do_request($request);
        $this->assertSame(200, $response->get_status());

        $filtered = apply_filters('rest_post_dispatch', $response, rest_get_server(), $request);
        $this->assertCacheControlNoStore($filtered);
    }

    public function testPostCoreUpdateGetsNoStoreHeaders(): void
    {
        $current = (string) get_bloginfo('version');
        [$maj, $min] = explode('.', $current) + [1 => '0'];
        $target = $maj . '.' . $min . '.1';
        $update = new \stdClass();
        $update->updates = [(object) ['response' => 'upgrade', 'current' => $target, 'version' => $target]];
        $update->version_checked = $current;
        set_site_transient('update_core', $update);

        $controller = new \Defyn\Connector\Rest\CoreUpdateController(
            new \Defyn\Connector\SiteInfo\CoreUpgraderService(
                fn () => new class { public function upgrade($update) { return true; } }
            )
        );
        register_rest_route('defyn-connector/v1', '/core/update', [
            'methods'             => 'POST',
            'callback'            => [$controller, 'handle'],
            'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
        ], true);

        $request = $this->makeSignedRequest('POST', '/defyn-connector/v1/core/update');
        $response = rest_do_request($request);
        $this->assertSame(200, $response->get_status());

        $filtered = apply_filters('rest_post_dispatch', $response, rest_get_server(), $request);
        $this->assertCacheControlNoStore($filtered);
    }

    private function assertCacheControlNoStore(WP_REST_Response $response): void
    {
        $cc = $response->get_headers()['Cache-Control'] ?? '';
        $this->assertStringContainsString('no-store', $cc);
        $this->assertStringContainsString('no-cache', $cc);
        $this->assertStringContainsString('private', $cc);
        $this->assertSame('no-cache', $response->get_headers()['Pragma'] ?? '');
        $this->assertSame('0', $response->get_headers()['Expires'] ?? '');
    }

    private function makeSignedRequest(string $method, string $path): WP_REST_Request
    {
        $ts        = (string) time();
        $nonce     = bin2hex(random_bytes(16));
        $canonical = Signer::canonical($method, $path, $ts, $nonce, '');
        $sig       = base64_encode(sodium_crypto_sign_detached($canonical, base64_decode($this->privateKeyBase64)));

        $request = new WP_REST_Request($method, $path);
        $request->set_header('X-Defyn-Timestamp', $ts);
        $request->set_header('X-Defyn-Nonce', $nonce);
        $request->set_header('X-Defyn-Signature', $sig);
        return $request;
    }
}
```

- [ ] **Step 4: Run the test, verify it fails (routes not yet registered in RestRouter)**

```
cd packages/connector-plugin && composer test -- --filter CoreCacheHeadersTest
```

Expected: FAIL — routes not found.

- [ ] **Step 5: Modify `RestRouter::register()` to add the two new core routes**

In `packages/connector-plugin/src/Rest/RestRouter.php`, add the imports near the existing controller imports at the top:

```php
use Defyn\Connector\Rest\CoreRefreshController;
use Defyn\Connector\Rest\CoreUpdateController;
```

Inside `register()`, after the existing `/themes/(?P<slug>...)/update` block, append:

```php
register_rest_route(self::NAMESPACE, '/core/refresh', [
    'methods'             => 'POST',
    'callback'            => [new CoreRefreshController(), 'handle'],
    'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
]);

register_rest_route(self::NAMESPACE, '/core/update', [
    'methods'             => 'POST',
    'callback'            => [new CoreUpdateController(), 'handle'],
    'permission_callback' => [\Defyn\Connector\Rest\Middleware\VerifySignatureMiddleware::class, 'check'],
]);
```

- [ ] **Step 6: Run all three tests + the full connector suite, verify all pass**

```
cd packages/connector-plugin && composer test -- --filter CoreUpdateLockTest
cd packages/connector-plugin && composer test -- --filter CoreCacheHeadersTest
cd packages/connector-plugin && composer test
```

Expected: ALL PASS.

- [ ] **Step 7: Commit**

```bash
git add packages/connector-plugin/src/Rest/RestRouter.php \
        packages/connector-plugin/tests/Integration/Rest/CoreUpdateLockTest.php \
        packages/connector-plugin/tests/Integration/Rest/CoreCacheHeadersTest.php
git commit -m "feat(p2-4): register core routes in RestRouter + lock collision + cache-header regression"
```

---

## Task 6 — `/status` includes the `core` sub-object (integration)

**Files:**
- Test: `packages/connector-plugin/tests/Integration/Rest/StatusCoreExtensionTest.php`

Asserts that the existing `GET /defyn-connector/v1/status` endpoint surfaces the new `core` sub-object end-to-end through the signed REST pipeline — Collector emits it, controller passes it through.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Rest;

use Defyn\Connector\Crypto\Signer;
use Defyn\Connector\Persistence\ConnectorState;
use Defyn\Connector\Rest\RestRouter;
use WP_REST_Request;
use WP_UnitTestCase;

final class StatusCoreExtensionTest extends WP_UnitTestCase
{
    private string $privateKeyBase64;

    protected function setUp(): void
    {
        parent::setUp();
        delete_site_transient('update_core');

        $keypair = sodium_crypto_sign_keypair();
        $this->privateKeyBase64 = base64_encode(sodium_crypto_sign_secretkey($keypair));

        (new ConnectorState())->update([
            'state'                => 'connected',
            'dashboard_public_key' => base64_encode(sodium_crypto_sign_publickey($keypair)),
            'connected_at'         => gmdate('Y-m-d H:i:s'),
        ]);

        (new RestRouter())->register();
    }

    public function testStatusIncludesCoreSubObjectWithExpectedKeys(): void
    {
        $res = $this->sendSigned();

        $this->assertSame(200, $res->get_status());
        $data = $res->get_data();

        $this->assertArrayHasKey('core', $data);
        $this->assertArrayHasKey('update_available', $data['core']);
        $this->assertArrayHasKey('update_version', $data['core']);
        $this->assertArrayHasKey('is_minor_update', $data['core']);
        $this->assertArrayHasKey('is_auto_update_enabled', $data['core']);
    }

    public function testStatusPreservesExistingKeys(): void
    {
        $res = $this->sendSigned();
        $data = $res->get_data();

        $this->assertArrayHasKey('wp_version', $data);
        $this->assertArrayHasKey('php_version', $data);
        $this->assertArrayHasKey('plugin_counts', $data);
        $this->assertArrayHasKey('theme_counts', $data);
        $this->assertArrayHasKey('ssl_status', $data);
        $this->assertArrayHasKey('server_time', $data);
    }

    private function sendSigned(): \WP_REST_Response
    {
        $ts        = (string) time();
        $nonce     = bin2hex(random_bytes(16));
        $canonical = Signer::canonical('GET', '/defyn-connector/v1/status', $ts, $nonce, '');
        $sig       = base64_encode(sodium_crypto_sign_detached($canonical, base64_decode($this->privateKeyBase64)));

        $request = new WP_REST_Request('GET', '/defyn-connector/v1/status');
        $request->set_header('X-Defyn-Timestamp', $ts);
        $request->set_header('X-Defyn-Nonce', $nonce);
        $request->set_header('X-Defyn-Signature', $sig);
        return rest_do_request($request);
    }
}
```

- [ ] **Step 2: Run the test, verify it passes (Task 1's Collector extension already satisfies the contract)**

```
cd packages/connector-plugin && composer test -- --filter StatusCoreExtensionTest
```

Expected: PASS (2/2). If a test fails, Task 1's Collector hook is wrong — fix it there.

- [ ] **Step 3: No new implementation — Task 1 already wires `core` into the status payload.**

- [ ] **Step 4: Run the full connector suite to confirm no regressions**

```
cd packages/connector-plugin && composer test
```

Expected: ALL PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/connector-plugin/tests/Integration/Rest/StatusCoreExtensionTest.php
git commit -m "test(p2-4): /status surfaces the core sub-object end-to-end"
```

---

## Task 7 — Connector v0.1.6 release bump

**Files:**
- Modify: `packages/connector-plugin/defyn-connector.php`
- Modify: `packages/connector-plugin/readme.txt`
- Modify: `packages/connector-plugin/composer.json`

- [ ] **Step 1: No test — header-only bump.**

- [ ] **Step 2: Run the full connector suite to confirm baseline green**

```
cd packages/connector-plugin && composer test
```

Expected: ALL PASS.

- [ ] **Step 3: Bump version + add changelog entry**

In `defyn-connector.php`, change the `Version:` header line:

```
 * Version:           0.1.6
```

In `composer.json`, change the version field:

```
"version": "0.1.6"
```

In `readme.txt`, change the `Stable tag:` line:

```
Stable tag: 0.1.6
```

Add a new changelog block above the existing `= 0.1.5 =` entry:

```
= 0.1.6 =
* Feature: WP core minor updates. GET /status now includes a `core` sub-object (update_available, update_version, is_minor_update, is_auto_update_enabled). POST /core/refresh forces a fresh wp_version_check() poll. POST /core/update runs Core_Upgrader on the install — only for minor bumps; major bumps return 409 core.major_update_blocked (deferred to P2.4.1). Shared `defyn_connector_upgrade_in_flight` transient lock now covers all 3 × 3 plugin/theme/core resource collisions on the same install (P2.4).
```

Keep the existing 0.1.5 / 0.1.4 / 0.1.3 / 0.1.2 / 0.1.1 / 0.1.0 entries intact.

- [ ] **Step 4: Run the full connector suite again to confirm no regression**

```
cd packages/connector-plugin && composer test
```

Expected: ALL PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/connector-plugin/defyn-connector.php \
        packages/connector-plugin/readme.txt \
        packages/connector-plugin/composer.json
git commit -m "chore(p2-4): connector v0.1.6 — release version bump"
```

---

## Task 8 — Schema v5: add 5 core-update columns to `wp_defyn_sites`

**Files:**
- Modify: `packages/dashboard-plugin/src/Activation.php`
- Test: `packages/dashboard-plugin/tests/Integration/Schema/SchemaVersionMigrationV5Test.php`

Bump `SCHEMA_VERSION` from `4` to `5`. Add a new private `addCoreUpdateColumns()` method that runs guarded `ALTER TABLE … ADD COLUMN` statements for all 5 new columns plus the `idx_core_update_available` index. Each `ALTER` is wrapped in a `SHOW COLUMNS LIKE` / `SHOW INDEX WHERE Key_name` guard so re-runs are idempotent. Wire the new method into `ensureSchema()` alongside the existing `dropLegacyActiveThemeColumn()` call.

The P2.2.1 self-heal hook (`Activation::maybeRunSelfHeal` on `plugins_loaded`) auto-triggers `ensureSchema` when `SchemaVersion::current() < SCHEMA_VERSION`, so the v4→v5 migration auto-runs on first request after dashboard upgrade.

- [ ] **Step 1: Write the failing `SchemaVersionMigrationV5Test`**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Schema;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Schema\SchemaVersion;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SchemaVersionMigrationV5Test extends AbstractSchemaTestCase
{
    public function testSchemaVersionConstantIsFive(): void
    {
        $this->assertSame(5, Activation::SCHEMA_VERSION);
    }

    public function testActivationBumpsSchemaVersionToFive(): void
    {
        delete_option(Activation::SCHEMA_OPTION);
        Activation::activate();
        $this->assertGreaterThanOrEqual(5, SchemaVersion::current());
    }

    public function testActivationAddsAllFiveCoreColumns(): void
    {
        global $wpdb;
        $sitesTable = SitesTable::tableName();

        // Force a clean slate — drop the columns if they happen to exist.
        foreach ([
            'core_update_available',
            'core_update_version',
            'core_update_state',
            'last_core_update_error',
            'last_core_update_attempt_at',
        ] as $col) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SHOW COLUMNS FROM `{$sitesTable}` LIKE %s",
                $col
            ));
            if ($exists !== null) {
                // phpcs:ignore WordPress.DB.PreparedSQL — column DDL.
                $wpdb->query("ALTER TABLE `{$sitesTable}` DROP COLUMN {$col}");
            }
        }

        Activation::ensureSchema();

        $cols = $wpdb->get_results(
            "SHOW COLUMNS FROM `{$sitesTable}`",
            ARRAY_A,
        );
        $byName = [];
        foreach ($cols as $c) {
            $byName[$c['Field']] = $c;
        }

        $this->assertArrayHasKey('core_update_available', $byName);
        $this->assertSame('NO', $byName['core_update_available']['Null']);
        $this->assertSame('0', $byName['core_update_available']['Default']);

        $this->assertArrayHasKey('core_update_version', $byName);
        $this->assertSame('YES', $byName['core_update_version']['Null']);

        $this->assertArrayHasKey('core_update_state', $byName);
        $this->assertSame('NO', $byName['core_update_state']['Null']);
        $this->assertSame('idle', $byName['core_update_state']['Default']);

        $this->assertArrayHasKey('last_core_update_error', $byName);
        $this->assertSame('YES', $byName['last_core_update_error']['Null']);

        $this->assertArrayHasKey('last_core_update_attempt_at', $byName);
        $this->assertSame('YES', $byName['last_core_update_attempt_at']['Null']);
    }

    public function testActivationIsIdempotent(): void
    {
        Activation::ensureSchema();
        Activation::ensureSchema();

        global $wpdb;
        $sitesTable = SitesTable::tableName();
        $cols = $wpdb->get_results("SHOW COLUMNS FROM `{$sitesTable}`", ARRAY_A);
        $found = 0;
        foreach ($cols as $c) {
            if (str_starts_with($c['Field'], 'core_update_') || str_starts_with($c['Field'], 'last_core_update_')) {
                $found++;
            }
        }
        // 5 columns total: core_update_available, core_update_version, core_update_state,
        // last_core_update_error, last_core_update_attempt_at.
        $this->assertSame(5, $found, 'second ensureSchema call must not duplicate columns');
    }

    public function testIndexAddedAndIdempotent(): void
    {
        global $wpdb;
        $sitesTable = SitesTable::tableName();

        Activation::ensureSchema();

        $hasIndex = $wpdb->get_row($wpdb->prepare(
            "SHOW INDEX FROM `{$sitesTable}` WHERE Key_name = %s",
            'idx_core_update_available'
        ));
        $this->assertNotNull($hasIndex, 'idx_core_update_available index should exist');

        Activation::ensureSchema();

        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s",
            $sitesTable,
            'idx_core_update_available'
        ));
        // Single-column index → exactly 1 row in statistics; idempotent.
        $this->assertSame(1, $count);
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

```
cd packages/dashboard-plugin && composer test -- --filter SchemaVersionMigrationV5Test
```

Expected: FAIL — `SCHEMA_VERSION` still 4 and columns don't exist.

- [ ] **Step 3: Modify `Activation.php`**

Open `packages/dashboard-plugin/src/Activation.php`.

Bump the schema version constant:

```php
public const SCHEMA_VERSION = 5;
```

In `ensureSchema()`, after the existing `self::dropLegacyActiveThemeColumn();` call and BEFORE the `SchemaVersion::set(...)` call, add:

```php
// P2.4 — add the 5 new core-update columns + index to wp_defyn_sites.
// Guarded ALTERs make this idempotent. dbDelta cannot reliably add
// columns to a pre-existing table with the exact spec we want, so we
// run raw $wpdb->query() with SHOW COLUMNS guards (same pattern as
// dropLegacyActiveThemeColumn).
self::addCoreUpdateColumns();
```

Add the new private method (place it directly below `dropLegacyActiveThemeColumn`):

```php
private static function addCoreUpdateColumns(): void
{
    global $wpdb;
    $sitesTable = SitesTable::tableName();

    $columns = [
        'core_update_available'       => 'TINYINT(1) NOT NULL DEFAULT 0',
        'core_update_version'         => 'VARCHAR(20) NULL',
        'core_update_state'           => "VARCHAR(20) NOT NULL DEFAULT 'idle'",
        'last_core_update_error'      => 'VARCHAR(1000) NULL',
        'last_core_update_attempt_at' => 'DATETIME NULL',
    ];
    foreach ($columns as $name => $definition) {
        $exists = $wpdb->get_var($wpdb->prepare(
            "SHOW COLUMNS FROM `{$sitesTable}` LIKE %s",
            $name
        ));
        if ($exists === null) {
            // phpcs:ignore WordPress.DB.PreparedSQL — column DDL cannot be parameterized.
            $wpdb->query("ALTER TABLE `{$sitesTable}` ADD COLUMN {$name} {$definition}");
        }
    }

    $hasIndex = $wpdb->get_row($wpdb->prepare(
        "SHOW INDEX FROM `{$sitesTable}` WHERE Key_name = %s",
        'idx_core_update_available'
    ));
    if ($hasIndex === null) {
        // phpcs:ignore WordPress.DB.PreparedSQL — index DDL cannot be parameterized.
        $wpdb->query("ALTER TABLE `{$sitesTable}` ADD INDEX idx_core_update_available (core_update_available)");
    }
}
```

- [ ] **Step 4: Run the test, verify it passes**

```
cd packages/dashboard-plugin && composer test -- --filter SchemaVersionMigrationV5Test
```

Expected: PASS (5/5).

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Activation.php \
        packages/dashboard-plugin/tests/Integration/Schema/SchemaVersionMigrationV5Test.php
git commit -m "feat(p2-4): schema v5 — add 5 core-update columns + index on wp_defyn_sites"
```

---

## Task 9 — Extend `Site` model with 5 new readonly core fields

**Files:**
- Modify: `packages/dashboard-plugin/src/Models/Site.php`
- Test: `packages/dashboard-plugin/tests/Unit/Models/SiteCoreExtensionTest.php`

Extend the immutable `Site` DTO with 5 readonly properties for the new core columns. `fromRow()` reads them, `toJson()` exposes them under snake_case keys so the SPA sees them via the existing `/sites/{id}` payload. The two transient meta fields (`is_minor_update`, `is_auto_update_enabled`) come from the connector's `/status` payload, are NOT persisted to `wp_defyn_sites`, and surface separately through the SPA Zod schema in Task 18 — they don't belong on the Site model.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Unit\Models;

use Defyn\Dashboard\Models\Site;
use PHPUnit\Framework\TestCase;

final class SiteCoreExtensionTest extends TestCase
{
    public function testFromRowMapsCoreUpdateColumns(): void
    {
        $row = $this->baseRow() + [
            'core_update_available'       => '1',
            'core_update_version'         => '7.0.1',
            'core_update_state'           => 'idle',
            'last_core_update_error'      => null,
            'last_core_update_attempt_at' => '2026-06-07 04:00:00',
        ];

        $site = Site::fromRow($row);

        $this->assertTrue($site->coreUpdateAvailable);
        $this->assertSame('7.0.1', $site->coreUpdateVersion);
        $this->assertSame('idle', $site->coreUpdateState);
        $this->assertNull($site->lastCoreUpdateError);
        $this->assertSame('2026-06-07 04:00:00', $site->lastCoreUpdateAttemptAt);
    }

    public function testFromRowDefaultsWhenColumnsMissing(): void
    {
        $row = $this->baseRow();
        // No core_* keys at all.

        $site = Site::fromRow($row);

        $this->assertFalse($site->coreUpdateAvailable);
        $this->assertNull($site->coreUpdateVersion);
        $this->assertSame('idle', $site->coreUpdateState);
        $this->assertNull($site->lastCoreUpdateError);
        $this->assertNull($site->lastCoreUpdateAttemptAt);
    }

    public function testToJsonExposesCoreUpdateFieldsInSnakeCase(): void
    {
        $row = $this->baseRow() + [
            'core_update_available'       => '0',
            'core_update_version'         => null,
            'core_update_state'           => 'failed',
            'last_core_update_error'      => 'Disk full',
            'last_core_update_attempt_at' => '2026-06-07 04:00:00',
        ];

        $json = Site::fromRow($row)->toJson();

        $this->assertFalse($json['core_update_available']);
        $this->assertNull($json['core_update_version']);
        $this->assertSame('failed', $json['core_update_state']);
        $this->assertSame('Disk full', $json['last_core_update_error']);
        $this->assertSame('2026-06-07 04:00:00', $json['last_core_update_attempt_at']);
    }

    /** @return array<string, mixed> */
    private function baseRow(): array
    {
        return [
            'id'              => '7',
            'user_id'         => '1',
            'url'             => 'https://smartcoding.test',
            'label'           => 'Smart',
            'status'          => 'active',
            'site_public_key' => null,
            'our_public_key'  => null,
            'last_contact_at' => '2026-06-07 04:00:00',
            'last_error'      => null,
            'created_at'      => '2026-06-06 00:00:00',
            'our_private_key' => null,
            'wp_version'      => '7.0',
            'php_version'     => '8.3.31',
            'plugin_counts'   => '{"installed":21,"active":20}',
            'theme_counts'    => '{"installed":8,"active":1}',
            'ssl_status'      => 'enabled',
            'ssl_expires_at'  => null,
            'last_sync_at'    => '2026-06-07 04:00:00',
        ];
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

```
cd packages/dashboard-plugin && composer test -- --filter SiteCoreExtensionTest
```

Expected: FAIL — `coreUpdateAvailable` and friends not declared.

- [ ] **Step 3: Modify `packages/dashboard-plugin/src/Models/Site.php`**

Add 5 new readonly properties to the constructor (after `lastSyncAt`):

```php
// P2.4 additions — core update state machine fields. All five default to
// "nothing happening" values so a freshly-inserted site (pre-sync) renders
// cleanly without null-vs-default checks in the SPA card.
public readonly bool    $coreUpdateAvailable = false,
public readonly ?string $coreUpdateVersion = null,
public readonly string  $coreUpdateState = 'idle',
public readonly ?string $lastCoreUpdateError = null,
public readonly ?string $lastCoreUpdateAttemptAt = null,
```

Extend `fromRow()` — add the 5 new args at the bottom of the `new self(...)` call:

```php
coreUpdateAvailable:     (bool) (int) ($row['core_update_available'] ?? 0),
coreUpdateVersion:       isset($row['core_update_version']) ? (string) $row['core_update_version'] : null,
coreUpdateState:         (string) ($row['core_update_state'] ?? 'idle'),
lastCoreUpdateError:     isset($row['last_core_update_error']) ? (string) $row['last_core_update_error'] : null,
lastCoreUpdateAttemptAt: isset($row['last_core_update_attempt_at']) ? (string) $row['last_core_update_attempt_at'] : null,
```

Extend `toJson()` — append five new keys before the closing bracket:

```php
'core_update_available'       => $this->coreUpdateAvailable,
'core_update_version'         => $this->coreUpdateVersion,
'core_update_state'           => $this->coreUpdateState,
'last_core_update_error'      => $this->lastCoreUpdateError,
'last_core_update_attempt_at' => $this->lastCoreUpdateAttemptAt,
```

- [ ] **Step 4: Run the test, verify it passes**

```
cd packages/dashboard-plugin && composer test -- --filter SiteCoreExtensionTest
```

Expected: PASS (3/3).

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Models/Site.php \
        packages/dashboard-plugin/tests/Unit/Models/SiteCoreExtensionTest.php
git commit -m "feat(p2-4): Site model surfaces 5 core-update fields to /sites/{id}"
```

---

## Task 10 — `SitesRepository` — 4 markCoreUpdate* methods + single-row heal in `markSynced`

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/SitesRepository.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/SitesRepositoryCoreTest.php`

Add four new state-machine methods (`markCoreUpdateRequested`, `markCoreUpdating`, `markCoreUpdateSucceeded`, `markCoreUpdateFailed`) and extend the existing `markSynced` to write the core sub-object. The day-1 single-row heal — when incoming says "no update available" AND existing state is `failed`, reset to `idle` + clear error — is the single-row equivalent of P2.2.1's multi-row `healDanglingFailedStates`. Tests `testMarkSyncedHealsStuckFailedWhenIncomingHasNoUpdateAvailable` + `testMarkSyncedDoesNotHealWhenUpdateStillAvailable` are required from day 1.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SitesRepositoryCoreTest extends AbstractSchemaTestCase
{
    private SitesRepository $repo;
    private int $siteId;

    protected function setUp(): void
    {
        parent::setUp();
        \Defyn\Dashboard\Activation::activate();
        $this->repo = new SitesRepository();

        global $wpdb;
        $wpdb->insert(SitesTable::tableName(), [
            'user_id'         => 1,
            'url'             => 'https://smartcoding.test',
            'label'           => 'Smart',
            'status'          => 'active',
            'our_private_key' => '',
            'wp_version'      => '7.0',
            'php_version'     => '8.3.31',
            'plugin_counts'   => '{"installed":0,"active":0}',
            'theme_counts'    => '{"installed":0,"active":0}',
            'ssl_status'      => 'enabled',
            'ssl_expires_at'  => null,
            'last_sync_at'    => '2026-06-07 04:00:00',
            'last_contact_at' => '2026-06-07 04:00:00',
            'created_at'      => '2026-06-06 00:00:00',
            'updated_at'      => '2026-06-07 04:00:00',
        ]);
        $this->siteId = (int) $wpdb->insert_id;
    }

    public function testMarkCoreUpdateRequestedSetsQueuedAndClearsError(): void
    {
        $this->seedRowState('failed', '7.0.1', 1, 'old error');

        $this->repo->markCoreUpdateRequested($this->siteId, '2026-06-07 09:00:00');
        $row = $this->findRow();

        $this->assertSame('queued', $row['core_update_state']);
        $this->assertNull($row['last_core_update_error']);
        $this->assertSame('2026-06-07 09:00:00', $row['last_core_update_attempt_at']);
    }

    public function testMarkCoreUpdatingFlipsState(): void
    {
        $this->seedRowState('queued', '7.0.1', 1, null);

        $this->repo->markCoreUpdating($this->siteId, '2026-06-07 09:00:30');
        $row = $this->findRow();
        $this->assertSame('updating', $row['core_update_state']);
    }

    public function testMarkCoreUpdateSucceededBumpsVersionAndClearsAvailable(): void
    {
        $this->seedRowState('updating', '7.0.1', 1, null);

        $this->repo->markCoreUpdateSucceeded($this->siteId, '7.0.1', '2026-06-07 09:01:00');
        $row = $this->findRow();

        $this->assertSame('idle', $row['core_update_state']);
        $this->assertSame('7.0.1', $row['wp_version']);
        $this->assertSame('0', $row['core_update_available']);
        $this->assertNull($row['core_update_version']);
        $this->assertNull($row['last_core_update_error']);
    }

    public function testMarkCoreUpdateFailedTruncatesLongError(): void
    {
        $this->seedRowState('updating', '7.0.1', 1, null);

        $long = str_repeat('A', 1200);
        $this->repo->markCoreUpdateFailed($this->siteId, $long, '2026-06-07 09:01:00');
        $row = $this->findRow();

        $this->assertSame('failed', $row['core_update_state']);
        $this->assertSame(1000, strlen($row['last_core_update_error']));
        $this->assertSame('2026-06-07 09:01:00', $row['last_core_update_attempt_at']);
    }

    public function testMarkSyncedPropagatesCoreFieldsFromStatusPayload(): void
    {
        $this->repo->markSynced($this->siteId, $this->statusPayload([
            'core' => [
                'update_available'       => true,
                'update_version'         => '7.0.1',
                'is_minor_update'        => true,
                'is_auto_update_enabled' => false,
            ],
        ]));

        $row = $this->findRow();
        $this->assertSame('1', $row['core_update_available']);
        $this->assertSame('7.0.1', $row['core_update_version']);
    }

    public function testMarkSyncedHealsStuckFailedWhenIncomingHasNoUpdateAvailable(): void
    {
        // Seed a stuck failed row (e.g. previous attempt crashed mid-flight,
        // but WP cron picked up the upgrade in the meantime).
        $this->seedRowState('failed', '7.0.1', 1, 'lingering error');

        $this->repo->markSynced($this->siteId, $this->statusPayload([
            'core' => [
                'update_available'       => false,
                'update_version'         => null,
                'is_minor_update'        => false,
                'is_auto_update_enabled' => true,
            ],
        ]));

        $row = $this->findRow();
        $this->assertSame('idle', $row['core_update_state']);
        $this->assertNull($row['last_core_update_error']);
        $this->assertSame('0', $row['core_update_available']);
    }

    public function testMarkSyncedDoesNotHealWhenUpdateStillAvailable(): void
    {
        $this->seedRowState('failed', '7.0.1', 1, 'real prior failure');

        $this->repo->markSynced($this->siteId, $this->statusPayload([
            'core' => [
                'update_available'       => true,
                'update_version'         => '7.0.1',
                'is_minor_update'        => true,
                'is_auto_update_enabled' => true,
            ],
        ]));

        $row = $this->findRow();
        $this->assertSame('failed', $row['core_update_state']);
        $this->assertSame('real prior failure', $row['last_core_update_error']);
    }

    /** @return array<string, string|null> */
    private function findRow(): array
    {
        global $wpdb;
        return (array) $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM " . SitesTable::tableName() . " WHERE id = %d", $this->siteId),
            ARRAY_A,
        );
    }

    private function seedRowState(string $state, ?string $version, int $available, ?string $error): void
    {
        global $wpdb;
        $wpdb->update(SitesTable::tableName(), [
            'core_update_state'      => $state,
            'core_update_version'    => $version,
            'core_update_available'  => $available,
            'last_core_update_error' => $error,
        ], ['id' => $this->siteId]);
    }

    /** @param array<string, mixed> $overrides */
    private function statusPayload(array $overrides): array
    {
        return array_merge([
            'wp_version'     => '7.0',
            'php_version'    => '8.3.31',
            'plugin_counts'  => ['installed' => 0, 'active' => 0],
            'theme_counts'   => ['installed' => 0, 'active' => 0],
            'ssl_status'     => 'enabled',
            'ssl_expires_at' => null,
        ], $overrides);
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

```
cd packages/dashboard-plugin && composer test -- --filter SitesRepositoryCoreTest
```

Expected: FAIL — methods don't exist.

- [ ] **Step 3: Implement — extend `SitesRepository`**

Open `packages/dashboard-plugin/src/Services/SitesRepository.php`.

Add the 4 new state-machine methods (place them after the existing `markSynced` method):

```php
/**
 * P2.4 — operator pressed "Update WordPress core". Flip the row to queued
 * + clear any prior error. Called from SitesCoreUpdateController.
 */
public function markCoreUpdateRequested(int $siteId, string $now): void
{
    global $wpdb;
    $wpdb->update(
        SitesTable::tableName(),
        [
            'core_update_state'           => 'queued',
            'last_core_update_error'      => null,
            'last_core_update_attempt_at' => $now,
            'updated_at'                  => $now,
        ],
        ['id' => $siteId],
        ['%s', '%s', '%s', '%s'],
        ['%d'],
    );
}

/**
 * P2.4 — AS job started executing the upgrade. Called from UpdateSiteCore.
 */
public function markCoreUpdating(int $siteId, string $now): void
{
    global $wpdb;
    $wpdb->update(
        SitesTable::tableName(),
        [
            'core_update_state' => 'updating',
            'updated_at'        => $now,
        ],
        ['id' => $siteId],
        ['%s', '%s'],
        ['%d'],
    );
}

/**
 * P2.4 — upgrade succeeded. Bump wp_version + clear the update-available
 * badge. Called from UpdateSiteCore on either the explicit success path
 * (200 + success:true) or the 409 success-by-other-means path
 * (`core.no_update_available` — auto-update may have landed it). For the
 * latter, the caller passes the row's existing wp_version (the
 * $wpVersionBeforeAttempt local).
 */
public function markCoreUpdateSucceeded(int $siteId, string $newVersion, string $now): void
{
    global $wpdb;
    $wpdb->update(
        SitesTable::tableName(),
        [
            'wp_version'             => $newVersion,
            'core_update_state'      => 'idle',
            'core_update_available'  => 0,
            'core_update_version'    => null,
            'last_core_update_error' => null,
            'updated_at'             => $now,
        ],
        ['id' => $siteId],
        ['%s', '%s', '%d', '%s', '%s', '%s'],
        ['%d'],
    );
}

/**
 * P2.4 — upgrade failed (terminal). Truncates the error to 1000 chars to
 * match the VARCHAR(1000) column. Called from UpdateSiteCore on retry
 * exhaustion, the 409 major-block branch, or any non-lock failure.
 */
public function markCoreUpdateFailed(int $siteId, string $errorMessage, string $now): void
{
    global $wpdb;
    $wpdb->update(
        SitesTable::tableName(),
        [
            'core_update_state'           => 'failed',
            'last_core_update_error'      => substr($errorMessage, 0, 1000),
            'last_core_update_attempt_at' => $now,
            'updated_at'                  => $now,
        ],
        ['id' => $siteId],
        ['%s', '%s', '%s', '%s'],
        ['%d'],
    );
}
```

Then **extend the existing `markSynced` method** to also write the core sub-object + run the day-1 single-row heal logic. Modify the existing method's body — keep the existing writes intact but switch from a single `$wpdb->update` to an `$updates` array that we mutate before the call.

Replace the existing `markSynced` body. The full method becomes:

```php
public function markSynced(int $id, array $info): void
{
    global $wpdb;
    $now = gmdate('Y-m-d H:i:s');

    $updates = [
        'status'          => 'active',
        'last_error'      => '',
        'wp_version'      => $info['wp_version'],
        'php_version'     => $info['php_version'],
        'plugin_counts'   => (string) wp_json_encode($info['plugin_counts']),
        'theme_counts'    => (string) wp_json_encode($info['theme_counts']),
        'ssl_status'      => $info['ssl_status'],
        'ssl_expires_at'  => $info['ssl_expires_at'],
        'last_sync_at'    => $now,
        'last_contact_at' => $now,
        'updated_at'      => $now,
    ];

    // P2.4 — propagate the core sub-object from the connector /status payload.
    // The two transient meta fields (is_minor_update, is_auto_update_enabled)
    // are NOT persisted — they surface via the SPA Zod schema in the
    // per-call /sites/{id} response shape from the connector roundtrip.
    $coreInfo = $info['core'] ?? null;
    if (is_array($coreInfo)) {
        $updates['core_update_available'] = !empty($coreInfo['update_available']) ? 1 : 0;
        $updates['core_update_version']   = $coreInfo['update_version'] ?? null;

        // Day-1 single-row heal — if incoming says "no update available"
        // but the existing row is stuck in `failed`, reset to idle + clear
        // the stale error. This is the SitesPluginsRepository
        // healDanglingFailedStates equivalent for a single resource row;
        // ships from day 1 (not retrofitted like P2.2.1 was).
        $existing = $this->findById($id);
        if (
            $existing !== null
            && $existing->coreUpdateState === 'failed'
            && empty($coreInfo['update_available'])
        ) {
            $updates['core_update_state']      = 'idle';
            $updates['last_core_update_error'] = null;
        }
    }

    $wpdb->update(SitesTable::tableName(), $updates, ['id' => $id]);
}
```

Note: the format-string array (`['%s', ...]`) that the existing code passed as the 4th arg to `$wpdb->update` is dropped here because the column set is variable. `$wpdb->update` infers format from the column-name → value association when no format array is supplied, which is fine for VARCHAR / DATETIME / TINYINT columns. This matches how `markCoreUpdateRequested` and siblings work above.

- [ ] **Step 4: Run the test, verify it passes**

```
cd packages/dashboard-plugin && composer test -- --filter SitesRepositoryCoreTest
```

Expected: PASS (7/7).

- [ ] **Step 5: Run the full dashboard suite — verify no regressions on existing markSynced callers**

```
cd packages/dashboard-plugin && composer test
```

Expected: ALL PASS. If existing `SyncService` tests break, the format-array change is the likely culprit — the prior `$wpdb->update` call took an explicit format array; the new variable column set drops it. WP's `$wpdb->update` infers format correctly without it for our column types.

- [ ] **Step 6: Commit**

```bash
git add packages/dashboard-plugin/src/Services/SitesRepository.php \
        packages/dashboard-plugin/tests/Integration/Services/SitesRepositoryCoreTest.php
git commit -m "feat(p2-4): SitesRepository core state-machine methods + day-1 single-row heal in markSynced"
```

---

## Task 11 — `SyncService` core sub-object passthrough integration

**Files:**
- Test: `packages/dashboard-plugin/tests/Integration/Services/SyncServiceCoreTest.php`

`SyncService::sync()` already calls `$this->sites->markSynced($siteId, $info)`. Task 10 taught `markSynced` how to read the `core` sub-object. This task asserts the end-to-end passthrough: when `SyncService` is fed a `/status` payload with a `core` sub-object, the row picks up the new column values. No new source code — pure integration test.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Services\SyncService;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SyncServiceCoreTest extends AbstractSchemaTestCase
{
    private int $siteId;

    protected function setUp(): void
    {
        parent::setUp();
        \Defyn\Dashboard\Activation::activate();

        global $wpdb;
        $wpdb->insert(SitesTable::tableName(), [
            'user_id'         => 1,
            'url'             => 'https://smartcoding.test',
            'label'           => 'Smart',
            'status'          => 'active',
            'our_private_key' => '',
            'wp_version'      => '7.0',
            'php_version'     => '8.3.31',
            'plugin_counts'   => '{"installed":0,"active":0}',
            'theme_counts'    => '{"installed":0,"active":0}',
            'ssl_status'      => 'enabled',
            'ssl_expires_at'  => null,
            'last_sync_at'    => '2026-06-07 04:00:00',
            'last_contact_at' => '2026-06-07 04:00:00',
            'created_at'      => '2026-06-06 00:00:00',
            'updated_at'      => '2026-06-07 04:00:00',
        ]);
        $this->siteId = (int) $wpdb->insert_id;
    }

    public function testSyncWritesCoreFieldsOnSuccess(): void
    {
        // SyncService::sync(int $id, array $statusPayload) is the existing F6
        // signature. It calls SitesRepository::markSynced internally.
        $service = new SyncService();
        $service->sync($this->siteId, [
            'wp_version'     => '7.0',
            'php_version'    => '8.3.31',
            'plugin_counts'  => ['installed' => 21, 'active' => 20],
            'theme_counts'   => ['installed' => 8, 'active' => 1],
            'ssl_status'     => 'enabled',
            'ssl_expires_at' => null,
            'core'           => [
                'update_available'       => true,
                'update_version'         => '7.0.1',
                'is_minor_update'        => true,
                'is_auto_update_enabled' => false,
            ],
        ]);

        $row = (new SitesRepository())->findById($this->siteId);
        $this->assertNotNull($row);
        $this->assertTrue($row->coreUpdateAvailable);
        $this->assertSame('7.0.1', $row->coreUpdateVersion);
    }

    public function testSyncHealsStuckFailedThroughTheService(): void
    {
        // Seed stuck failed via repository directly.
        $repo = new SitesRepository();
        $repo->markCoreUpdateFailed($this->siteId, 'Old error', '2026-06-07 08:00:00');

        $service = new SyncService();
        $service->sync($this->siteId, [
            'wp_version'     => '7.0.1',
            'php_version'    => '8.3.31',
            'plugin_counts'  => ['installed' => 0, 'active' => 0],
            'theme_counts'   => ['installed' => 0, 'active' => 0],
            'ssl_status'     => 'enabled',
            'ssl_expires_at' => null,
            'core'           => [
                'update_available'       => false,
                'update_version'         => null,
                'is_minor_update'        => false,
                'is_auto_update_enabled' => true,
            ],
        ]);

        $row = $repo->findById($this->siteId);
        $this->assertSame('idle', $row->coreUpdateState);
        $this->assertNull($row->lastCoreUpdateError);
        $this->assertFalse($row->coreUpdateAvailable);
        $this->assertSame('7.0.1', $row->wpVersion);
    }
}
```

- [ ] **Step 2: Run the test, verify it passes (Task 10's `markSynced` extension already satisfies the contract)**

```
cd packages/dashboard-plugin && composer test -- --filter SyncServiceCoreTest
```

Expected: PASS (2/2). If a test fails, Task 10's heal-condition logic is wrong — fix it there.

- [ ] **Step 3: No new implementation. Run the full dashboard suite — verify no regressions**

```
cd packages/dashboard-plugin && composer test
```

Expected: ALL PASS.

- [ ] **Step 4: Commit**

```bash
git add packages/dashboard-plugin/tests/Integration/Services/SyncServiceCoreTest.php
git commit -m "test(p2-4): SyncService propagates core sub-object + heals stuck failed end-to-end"
```

---

## Task 12 — `RefreshSiteCore` AS job

**Files:**
- Create: `packages/dashboard-plugin/src/Jobs/RefreshSiteCore.php`
- Test: `packages/dashboard-plugin/tests/Integration/Jobs/RefreshSiteCoreTest.php`

AS hook handler for `defyn_refresh_site_core`. Direct mirror of `RefreshSiteThemes`: decrypts the per-site key, signed-POSTs the connector's `/core/refresh`, runs `SyncService::sync` (which now writes the core sub-object). Failures log `site.core_refresh_failed`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Jobs;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Jobs\RefreshSiteCore;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Services\SyncService;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RefreshSiteCoreTest extends AbstractSchemaTestCase
{
    private int $siteId;

    protected function setUp(): void
    {
        parent::setUp();
        \Defyn\Dashboard\Activation::activate();

        $keypair = sodium_crypto_sign_keypair();
        $privateKey = base64_encode(sodium_crypto_sign_secretkey($keypair));
        $vault = new Vault(DEFYN_VAULT_KEY);
        $encrypted = $vault->encrypt($privateKey);

        global $wpdb;
        $wpdb->insert(SitesTable::tableName(), [
            'user_id'         => 1,
            'url'             => 'https://smartcoding.test',
            'label'           => 'Smart',
            'status'          => 'active',
            'our_private_key' => $encrypted,
            'site_public_key' => base64_encode(sodium_crypto_sign_publickey($keypair)),
            'wp_version'      => '7.0',
            'php_version'     => '8.3.31',
            'plugin_counts'   => '{"installed":0,"active":0}',
            'theme_counts'    => '{"installed":0,"active":0}',
            'ssl_status'      => 'enabled',
            'ssl_expires_at'  => null,
            'last_sync_at'    => '2026-06-07 04:00:00',
            'last_contact_at' => '2026-06-07 04:00:00',
            'created_at'      => '2026-06-06 00:00:00',
            'updated_at'      => '2026-06-07 04:00:00',
        ]);
        $this->siteId = (int) $wpdb->insert_id;
    }

    public function testSuccessPathWritesCoreColumnsAndLogsEvent(): void
    {
        $body = json_encode([
            'update_available'       => true,
            'update_version'         => '7.0.1',
            'is_minor_update'        => true,
            'is_auto_update_enabled' => false,
            'server_time'            => time(),
        ]);

        $captured = null;
        $factory = function (string $method, string $url, array $options) use (&$captured, $body) {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];
            return new MockResponse($body, ['http_code' => 200]);
        };

        $job = new RefreshSiteCore(
            new SitesRepository(),
            new SignedHttpClient(new MockHttpClient($factory)),
            new SyncService(),
            new ActivityLogger(),
        );

        $job->handle($this->siteId);

        $this->assertSame('POST', $captured['method']);
        $this->assertStringEndsWith('/wp-json/defyn-connector/v1/core/refresh', $captured['url']);

        $row = (new SitesRepository())->findById($this->siteId);
        $this->assertTrue($row->coreUpdateAvailable);
        $this->assertSame('7.0.1', $row->coreUpdateVersion);

        global $wpdb;
        $event = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT event_type, details FROM {$wpdb->prefix}defyn_activity_log
                 WHERE site_id = %d AND event_type = 'core_inventory.refreshed'
                 ORDER BY id DESC LIMIT 1",
                $this->siteId
            ),
            ARRAY_A,
        );
        $this->assertNotNull($event);
        $details = json_decode((string) $event['details'], true);
        $this->assertSame(true, $details['update_available']);
        $this->assertSame('refresh', $details['source']);
    }

    public function testTransportFailureLogsRefreshFailed(): void
    {
        $factory = fn () => throw new \Symfony\Component\HttpClient\Exception\TransportException('Connection refused');

        $job = new RefreshSiteCore(
            new SitesRepository(),
            new SignedHttpClient(new MockHttpClient($factory)),
            new SyncService(),
            new ActivityLogger(),
        );

        $job->handle($this->siteId);

        global $wpdb;
        $event = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT details FROM {$wpdb->prefix}defyn_activity_log
                 WHERE site_id = %d AND event_type = 'site.core_refresh_failed'
                 ORDER BY id DESC LIMIT 1",
                $this->siteId
            ),
            ARRAY_A,
        );
        $this->assertNotNull($event);
        $details = json_decode((string) $event['details'], true);
        $this->assertStringContainsString('Connection refused', (string) $details['error']);

        // Core columns NOT clobbered.
        $row = (new SitesRepository())->findById($this->siteId);
        $this->assertFalse($row->coreUpdateAvailable);
        $this->assertNull($row->coreUpdateVersion);
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

```
cd packages/dashboard-plugin && composer test -- --filter RefreshSiteCoreTest
```

Expected: FAIL — `RefreshSiteCore` not found.

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Services\SyncService;
use Throwable;

/**
 * P2.4 — Action Scheduler hook handler for `defyn_refresh_site_core`.
 *
 * Scheduled by SitesCoreRefreshController on operator click or by
 * SyncSite extension on the recurring background tick. Forces a fresh
 * /core/refresh against the connector then runs the site sync (which now
 * writes the core sub-object via SitesRepository::markSynced). Failures
 * log `site.core_refresh_failed`.
 *
 * Direct mirror of RefreshSiteThemes (P2.3 Task 13).
 */
final class RefreshSiteCore
{
    public const HOOK = 'defyn_refresh_site_core';

    public function __construct(
        private readonly SitesRepository $repo = new SitesRepository(),
        private readonly SignedHttpClient $httpClient = new SignedHttpClient(),
        private readonly SyncService $syncService = new SyncService(),
        private readonly ActivityLogger $log = new ActivityLogger(),
    ) {
    }

    public function handle(int $siteId): void
    {
        $site = $this->repo->findById($siteId);
        if ($site === null || $site->status === 'pending') {
            return;
        }

        if ($site->ourPrivateKey === null || $site->ourPrivateKey === '') {
            $this->logFailed($siteId, 'Site is missing its encrypted private key.');
            return;
        }

        $vault = new Vault(DEFYN_VAULT_KEY);
        try {
            $privateKey = $vault->decrypt($site->ourPrivateKey);
        } catch (Throwable) {
            $this->logFailed($siteId, 'Failed to decrypt site keypair.');
            return;
        }

        $url           = rtrim($site->url, '/') . '/wp-json/defyn-connector/v1/core/refresh';
        $canonicalPath = '/defyn-connector/v1/core/refresh';

        $response = $this->httpClient->signedPostJson($url, [], $privateKey, $canonicalPath);

        if ($response['error'] !== '') {
            $this->logFailed($siteId, $response['error']);
            return;
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $this->logFailed($siteId, 'Connector returned status ' . $response['status']);
            return;
        }

        // The connector /core/refresh returns the core sub-object DIRECTLY
        // (no `core` wrapper). Wrap it for SyncService::sync, then preserve
        // the existing wp_version/php_version/etc by reading the current
        // row's existing values — SyncService::sync requires a full /status
        // payload shape.
        $coreSubObject = $response['body'];
        $statusShim = [
            'wp_version'     => $site->wpVersion ?? '',
            'php_version'    => $site->phpVersion ?? '',
            'plugin_counts'  => $site->pluginCounts ?? ['installed' => 0, 'active' => 0],
            'theme_counts'   => $site->themeCounts ?? ['installed' => 0, 'active' => 0],
            'ssl_status'     => $site->sslStatus ?? '',
            'ssl_expires_at' => $site->sslExpiresAt,
            'core'           => [
                'update_available'       => (bool) ($coreSubObject['update_available'] ?? false),
                'update_version'         => $coreSubObject['update_version'] ?? null,
                'is_minor_update'        => (bool) ($coreSubObject['is_minor_update'] ?? false),
                'is_auto_update_enabled' => (bool) ($coreSubObject['is_auto_update_enabled'] ?? true),
            ],
        ];

        $this->syncService->sync($siteId, $statusShim);

        $this->log->log(null, $siteId, 'core_inventory.refreshed', [
            'update_available' => (bool) ($coreSubObject['update_available'] ?? false),
            'update_version'   => $coreSubObject['update_version'] ?? null,
            'source'           => 'refresh',
        ]);
    }

    private function logFailed(int $siteId, string $error): void
    {
        $this->log->log(null, $siteId, 'site.core_refresh_failed', [
            'error'  => substr($error, 0, 1000),
            'source' => 'refresh',
        ]);
    }
}
```

- [ ] **Step 4: Run the test, verify it passes**

```
cd packages/dashboard-plugin && composer test -- --filter RefreshSiteCoreTest
```

Expected: PASS (2/2).

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Jobs/RefreshSiteCore.php \
        packages/dashboard-plugin/tests/Integration/Jobs/RefreshSiteCoreTest.php
git commit -m "feat(p2-4): RefreshSiteCore AS job — connector refresh + core column write"
```

---

## Task 13 — `UpdateSiteCore` AS job — success path + triplet logging + 300s timeout

**Files:**
- Create: `packages/dashboard-plugin/src/Jobs/UpdateSiteCore.php`
- Test: `packages/dashboard-plugin/tests/Integration/Jobs/UpdateSiteCoreTest.php`

AS hook handler for `defyn_update_site_core($siteId, $attempt)`. This task covers steps 1 + 2 + the success branch + the 300s timeout regression. Task 14 layers on the 4 remaining response branches (409 success-by-other-means, 409 major-block, 409 lock-collision retry, terminal failure).

Sequence per call (this task):

1. `markCoreUpdating` + log `core_update.started` with `{previous_version, target_version, attempt}` — the second event of the `requested → started → succeeded|failed` triplet.
2. SignedPostJson to connector `/core/update` with **300s** timeout (the regression-testable constant).
3. On 200 + `success: true` → `markCoreUpdateSucceeded($siteId, $newVersion, $now)` + log `core_update.succeeded` with `{previous_version, new_version}`.

- [ ] **Step 1: Write the failing test (success + 300s timeout assertion)**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Jobs;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Jobs\UpdateSiteCore;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class UpdateSiteCoreTest extends AbstractSchemaTestCase
{
    private int $siteId;

    protected function setUp(): void
    {
        parent::setUp();
        \Defyn\Dashboard\Activation::activate();

        $keypair = sodium_crypto_sign_keypair();
        $privateKey = base64_encode(sodium_crypto_sign_secretkey($keypair));
        $vault = new Vault(DEFYN_VAULT_KEY);
        $encrypted = $vault->encrypt($privateKey);

        global $wpdb;
        $wpdb->insert(SitesTable::tableName(), [
            'user_id'                 => 1,
            'url'                     => 'https://smartcoding.test',
            'label'                   => 'Smart',
            'status'                  => 'active',
            'our_private_key'         => $encrypted,
            'site_public_key'         => base64_encode(sodium_crypto_sign_publickey($keypair)),
            'wp_version'              => '7.0',
            'php_version'             => '8.3.31',
            'plugin_counts'           => '{"installed":0,"active":0}',
            'theme_counts'            => '{"installed":0,"active":0}',
            'ssl_status'              => 'enabled',
            'ssl_expires_at'          => null,
            'last_sync_at'            => '2026-06-07 04:00:00',
            'last_contact_at'         => '2026-06-07 04:00:00',
            'created_at'              => '2026-06-06 00:00:00',
            'updated_at'              => '2026-06-07 04:00:00',
            'core_update_available'   => 1,
            'core_update_version'     => '7.0.1',
            'core_update_state'       => 'queued',
            'last_core_update_error'  => null,
        ]);
        $this->siteId = (int) $wpdb->insert_id;
    }

    public function testSuccessPathMarksIdleAndBumpsVersion(): void
    {
        $body = json_encode([
            'success'          => true,
            'previous_version' => '7.0',
            'new_version'      => '7.0.1',
            'server_time'      => time(),
        ]);

        $captured = null;
        $factory = function (string $method, string $url, array $options) use (&$captured, $body) {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];
            return new MockResponse($body, ['http_code' => 200]);
        };

        $job = new UpdateSiteCore(
            new SitesRepository(),
            new SignedHttpClient(new MockHttpClient($factory)),
            new ActivityLogger(),
        );

        $job->handle($this->siteId, 0);

        $this->assertSame('POST', $captured['method']);
        // P2.4 regression — the timeout MUST be 300s, not 120s (themes) or 30s.
        $this->assertSame(300, $captured['options']['timeout']);
        $this->assertStringEndsWith('/wp-json/defyn-connector/v1/core/update', $captured['url']);

        $row = (new SitesRepository())->findById($this->siteId);
        $this->assertSame('idle', $row->coreUpdateState);
        $this->assertSame('7.0.1', $row->wpVersion);
        $this->assertFalse($row->coreUpdateAvailable);
        $this->assertNull($row->coreUpdateVersion);
    }

    public function testTripletStartedAndSucceededEventsLoggedInOrder(): void
    {
        $body = json_encode([
            'success'          => true,
            'previous_version' => '7.0',
            'new_version'      => '7.0.1',
            'server_time'      => time(),
        ]);
        $factory = fn () => new MockResponse($body, ['http_code' => 200]);

        $job = new UpdateSiteCore(
            new SitesRepository(),
            new SignedHttpClient(new MockHttpClient($factory)),
            new ActivityLogger(),
        );

        $job->handle($this->siteId, 0);

        global $wpdb;
        $events = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT event_type FROM {$wpdb->prefix}defyn_activity_log
                 WHERE site_id = %d AND event_type LIKE 'core_update%%'
                 ORDER BY id ASC",
                $this->siteId
            )
        );
        $this->assertSame(['core_update.started', 'core_update.succeeded'], $events);
    }

    public function testTimeoutConstantIsThreeHundred(): void
    {
        $this->assertSame(300, UpdateSiteCore::TIMEOUT_SECONDS);
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

```
cd packages/dashboard-plugin && composer test -- --filter UpdateSiteCoreTest
```

Expected: FAIL — `UpdateSiteCore` not found.

- [ ] **Step 3: Implement (success-only — Task 14 adds the other branches)**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\SitesRepository;

/**
 * P2.4 — Action Scheduler handler for `defyn_update_site_core($siteId, $attempt)`.
 *
 * Decrypts the per-site Ed25519 dashboard private key and calls the
 * connector's signed /core/update endpoint with a 300-second timeout (vs
 * P2.3 themes' 120s — core upgrades involve more files + WP database
 * migrations on point releases; 300s budget covers slow shared hosts).
 *
 * Activity log triplet (spec § 8.2): core_update.requested ->
 * core_update.started -> core_update.succeeded|failed. This job emits
 * .started + .succeeded|.failed (.requested is written by
 * SitesCoreUpdateController at queue time).
 *
 * Single-resource — no slug. Mirrors UpdateSiteTheme (P2.3 Task 14) with
 * the multi-row state-machine collapsed to single-row Site repository
 * calls.
 *
 * Spec: docs/superpowers/specs/2026-06-07-p2-4-core-updates-design.md §4.3
 */
final class UpdateSiteCore
{
    public const HOOK = 'defyn_update_site_core';

    /**
     * Core upgrades involve WP database migrations on point releases.
     * Production runs on Kinsta typically complete in 60–90 s; 300 s
     * leaves headroom for slow shared hosts.
     */
    public const TIMEOUT_SECONDS = 300;

    public function __construct(
        private readonly SitesRepository $sites = new SitesRepository(),
        private readonly SignedHttpClient $http = new SignedHttpClient(),
        private readonly ActivityLogger $log = new ActivityLogger(),
        private readonly ?Vault $vault = null,
    ) {
    }

    public function handle(int $siteId, int $attempt = 0): void
    {
        $site = $this->sites->findById($siteId);
        if ($site === null) {
            return;
        }

        $now = gmdate('Y-m-d H:i:s');

        // Snapshot the row's wp_version BEFORE we touch anything. We use it
        // on the 409 success-by-other-means branch in Task 14, where the
        // connector returns no version. Reading it now (rather than after
        // the connector roundtrip) means we never accidentally take the
        // in-flight value back.
        $wpVersionBeforeAttempt = (string) ($site->wpVersion ?? '');
        $targetVersion = (string) ($site->coreUpdateVersion ?? '');

        $this->sites->markCoreUpdating($siteId, $now);

        // core_update.started — second of the requested -> started -> succeeded|failed triplet.
        $this->log->log(null, $siteId, 'core_update.started', [
            'previous_version' => $wpVersionBeforeAttempt,
            'target_version'   => $targetVersion,
            'attempt'          => $attempt,
        ]);

        $vault      = $this->vault ?? new Vault(DEFYN_VAULT_KEY);
        $privateKey = $vault->decrypt((string) $site->ourPrivateKey);

        $url           = rtrim($site->url, '/') . '/wp-json/defyn-connector/v1/core/update';
        $canonicalPath = '/defyn-connector/v1/core/update';

        $response = $this->http->signedPostJson(
            $url,
            [],
            $privateKey,
            $canonicalPath,
            timeoutSeconds: self::TIMEOUT_SECONDS,
        );

        if ($response['status'] === 200 && !empty($response['body']['success'])) {
            $previousVersion = (string) ($response['body']['previous_version'] ?? $wpVersionBeforeAttempt);
            $newVersion      = (string) ($response['body']['new_version'] ?? $targetVersion);

            $this->sites->markCoreUpdateSucceeded($siteId, $newVersion, $now);
            $this->log->log(null, $siteId, 'core_update.succeeded', [
                'previous_version' => $previousVersion,
                'new_version'      => $newVersion,
            ]);
            return;
        }

        // Task 14 layers on the four remaining branches:
        //   - 409 core.no_update_available (success-by-other-means)
        //   - 409 core.major_update_blocked (immediate fail, no retry)
        //   - 409 connector.upgrade_in_progress (retry with exponential backoff)
        //   - 502 / non-lock failures (immediate fail, no retry)
        //
        // For this Task 13's tests to pass we only need the success branch.
        // Falling through here is fine while Task 14 is unimplemented; the
        // row stays in 'updating'. Task 14 replaces this comment block.
    }
}
```

- [ ] **Step 4: Run the test, verify it passes**

```
cd packages/dashboard-plugin && composer test -- --filter UpdateSiteCoreTest
```

Expected: PASS (3/3).

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Jobs/UpdateSiteCore.php \
        packages/dashboard-plugin/tests/Integration/Jobs/UpdateSiteCoreTest.php
git commit -m "feat(p2-4): UpdateSiteCore AS job — success path + started triplet event + 300s timeout"
```

---

## Task 14 — `UpdateSiteCore` — 4 remaining response branches

**Files:**
- Modify: `packages/dashboard-plugin/src/Jobs/UpdateSiteCore.php`
- Test: same file as Task 13 — append four new test methods

Add four branches to `UpdateSiteCore::handle()` after the success block:

1. **409 `core.no_update_available`** — success-by-other-means. Use `$wpVersionBeforeAttempt` (snapshot from Task 13) — the 409 envelope has NO version field. Call `markCoreUpdateSucceeded($siteId, $wpVersionBeforeAttempt, $now)` + log `core_update.succeeded_no_change`.
2. **409 `core.major_update_blocked`** — mark failed immediately, NO retry. Log `core_update.blocked_major`. Treated as a soft operator-visible failure (the dashboard's preflight should have caught this; the 409 means it didn't).
3. **409 `connector.upgrade_in_progress`** — exponential backoff retry (60/120/240/480/960 s, max 5 attempts). Log `core_update.retry` per retry. After 5 attempts: `markCoreUpdateFailed` with `"Site is busy after 5 retries."` + log `core_update.failed` with `error_code = retry_exhausted`.
4. **502 / non-lock failures** — `markCoreUpdateFailed` with the truncated message + log `core_update.failed`. **NO retry** (different from P2.3 — for core, repeated non-lock failures usually mean a real problem like out-of-disk).

- [ ] **Step 1: Append failing tests to `UpdateSiteCoreTest`**

```php
public function testNoUpdateAvailable409TreatedAsSuccessByOtherMeans(): void
{
    $body = json_encode(['error' => ['code' => 'core.no_update_available', 'message' => 'WordPress reports no core update available.']]);
    $factory = fn () => new MockResponse($body, ['http_code' => 409]);
    $job = new UpdateSiteCore(
        new SitesRepository(),
        new SignedHttpClient(new MockHttpClient($factory)),
        new ActivityLogger(),
    );

    // Row's wp_version BEFORE the attempt is '7.0' (from setUp).
    $job->handle($this->siteId, 0);

    $row = (new SitesRepository())->findById($this->siteId);

    // Should be marked succeeded — pinned to the pre-attempt wp_version,
    // NOT the connector's update_version (no update actually happened).
    $this->assertSame('idle', $row->coreUpdateState);
    $this->assertSame('7.0', $row->wpVersion);
    $this->assertFalse($row->coreUpdateAvailable);
    $this->assertNull($row->coreUpdateVersion);

    global $wpdb;
    $event = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT event_type, details FROM {$wpdb->prefix}defyn_activity_log
             WHERE site_id = %d AND event_type = 'core_update.succeeded_no_change'
             ORDER BY id DESC LIMIT 1",
            $this->siteId
        ),
        ARRAY_A,
    );
    $this->assertNotNull($event);
    $details = json_decode((string) $event['details'], true);
    $this->assertSame('7.0', $details['current_version']);
}

public function testMajorBlocked409MarksFailedImmediatelyNoRetry(): void
{
    $body = json_encode(['error' => ['code' => 'core.major_update_blocked', 'message' => 'Major-version updates (7.0 -> 8.0) require P2.4.1.']]);
    $factory = fn () => new MockResponse($body, ['http_code' => 409]);

    $scheduled = [];
    \add_filter('pre_as_schedule_single_action', function ($pre, $when, $hook, $args) use (&$scheduled) {
        $scheduled[] = ['hook' => $hook, 'args' => $args];
        return 999;
    }, 10, 4);

    $job = new UpdateSiteCore(
        new SitesRepository(),
        new SignedHttpClient(new MockHttpClient($factory)),
        new ActivityLogger(),
    );

    $job->handle($this->siteId, 0);

    $row = (new SitesRepository())->findById($this->siteId);
    $this->assertSame('failed', $row->coreUpdateState);
    $this->assertStringContainsString('Major-version updates', $row->lastCoreUpdateError ?? '');

    // Critical: NO retry was scheduled.
    $this->assertEmpty($scheduled);

    global $wpdb;
    $event = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT event_type FROM {$wpdb->prefix}defyn_activity_log
             WHERE site_id = %d AND event_type = 'core_update.blocked_major'
             ORDER BY id DESC LIMIT 1",
            $this->siteId
        ),
        ARRAY_A,
    );
    $this->assertNotNull($event);
}

public function testInProgress409ReschedulesWithExponentialBackoff(): void
{
    $body = json_encode(['error' => ['code' => 'connector.upgrade_in_progress', 'message' => 'busy']]);
    $factory = fn () => new MockResponse($body, ['http_code' => 409]);
    $job = new UpdateSiteCore(
        new SitesRepository(),
        new SignedHttpClient(new MockHttpClient($factory)),
        new ActivityLogger(),
    );

    $scheduled = [];
    \add_filter('pre_as_schedule_single_action', function ($pre, $when, $hook, $args) use (&$scheduled) {
        $scheduled[] = ['when' => $when, 'hook' => $hook, 'args' => $args];
        return 999;
    }, 10, 4);

    $job->handle($this->siteId, 0);
    $job->handle($this->siteId, 1);
    $job->handle($this->siteId, 2);

    $this->assertCount(3, $scheduled);

    $now = time();
    $this->assertEqualsWithDelta($now + 60, $scheduled[0]['when'], 5);
    $this->assertEqualsWithDelta($now + 120, $scheduled[1]['when'], 5);
    $this->assertEqualsWithDelta($now + 240, $scheduled[2]['when'], 5);
    $this->assertSame([$this->siteId, 1], $scheduled[0]['args']);
    $this->assertSame([$this->siteId, 2], $scheduled[1]['args']);
    $this->assertSame([$this->siteId, 3], $scheduled[2]['args']);

    // Row stays in 'updating' across retries.
    $row = (new SitesRepository())->findById($this->siteId);
    $this->assertSame('updating', $row->coreUpdateState);

    // core_update.retry events logged.
    global $wpdb;
    $count = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}defyn_activity_log
             WHERE site_id = %d AND event_type = 'core_update.retry'",
            $this->siteId
        )
    );
    $this->assertSame(3, $count);
}

public function testFifthRetryExhaustionMarksFailedWithRetryExhaustedCode(): void
{
    $body = json_encode(['error' => ['code' => 'connector.upgrade_in_progress', 'message' => 'busy']]);
    $factory = fn () => new MockResponse($body, ['http_code' => 409]);
    $job = new UpdateSiteCore(
        new SitesRepository(),
        new SignedHttpClient(new MockHttpClient($factory)),
        new ActivityLogger(),
    );

    $job->handle($this->siteId, 5);

    $row = (new SitesRepository())->findById($this->siteId);
    $this->assertSame('failed', $row->coreUpdateState);
    $this->assertStringContainsString('busy after 5 retries', $row->lastCoreUpdateError ?? '');

    global $wpdb;
    $details = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT details FROM {$wpdb->prefix}defyn_activity_log
             WHERE site_id = %d AND event_type = 'core_update.failed' ORDER BY id DESC LIMIT 1",
            $this->siteId
        )
    );
    $decoded = json_decode((string) $details, true);
    $this->assertSame('retry_exhausted', $decoded['error_code']);
}

public function testTransportErrorMarksFailedNoRetry(): void
{
    $factory = fn () => throw new \Symfony\Component\HttpClient\Exception\TransportException('Connection refused');

    $scheduled = [];
    \add_filter('pre_as_schedule_single_action', function ($pre, $when, $hook, $args) use (&$scheduled) {
        $scheduled[] = ['hook' => $hook, 'args' => $args];
        return 999;
    }, 10, 4);

    $job = new UpdateSiteCore(
        new SitesRepository(),
        new SignedHttpClient(new MockHttpClient($factory)),
        new ActivityLogger(),
    );

    $job->handle($this->siteId, 0);

    $row = (new SitesRepository())->findById($this->siteId);
    $this->assertSame('failed', $row->coreUpdateState);
    $this->assertStringContainsString('Connection refused', $row->lastCoreUpdateError ?? '');

    // Critical for core (different from themes): NO retry was scheduled.
    $this->assertEmpty($scheduled);
}

public function testConnectorUpdateFailed502MarksFailedNoRetry(): void
{
    $body = json_encode(['error' => ['code' => 'core.update_failed', 'message' => 'Could not copy file. /wp-admin/index.php']]);
    $factory = fn () => new MockResponse($body, ['http_code' => 502]);

    $scheduled = [];
    \add_filter('pre_as_schedule_single_action', function ($pre, $when, $hook, $args) use (&$scheduled) {
        $scheduled[] = ['hook' => $hook, 'args' => $args];
        return 999;
    }, 10, 4);

    $job = new UpdateSiteCore(
        new SitesRepository(),
        new SignedHttpClient(new MockHttpClient($factory)),
        new ActivityLogger(),
    );

    $job->handle($this->siteId, 0);

    $row = (new SitesRepository())->findById($this->siteId);
    $this->assertSame('failed', $row->coreUpdateState);
    $this->assertStringContainsString('Could not copy file', $row->lastCoreUpdateError ?? '');

    // No retry.
    $this->assertEmpty($scheduled);

    global $wpdb;
    $msg = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT JSON_EXTRACT(details, '$.error_message') FROM {$wpdb->prefix}defyn_activity_log
             WHERE site_id = %d AND event_type = 'core_update.failed' ORDER BY id DESC LIMIT 1",
            $this->siteId
        )
    );
    $this->assertStringContainsString('Could not copy file', (string) $msg);
}
```

- [ ] **Step 2: Run the tests, verify they fail**

```
cd packages/dashboard-plugin && composer test -- --filter UpdateSiteCoreTest
```

Expected: FAIL — `handle()` doesn't implement the 4 branches.

- [ ] **Step 3: Replace the trailing comment in `UpdateSiteCore::handle()` with the 4 branches**

In `packages/dashboard-plugin/src/Jobs/UpdateSiteCore.php`, replace the multi-line comment that says "Task 14 layers on the four remaining branches" with this block (placed AFTER the existing success block):

```php
// 409 + core.no_update_available -> success-by-other-means.
//
// The connector reports the on-disk WP version is already current — either
// the operator manually upgraded via wp-admin, an auto-update fired, or a
// concurrent dashboard request beat us to it. The 409 envelope does NOT
// carry a version field; use the snapshot we took at the top of handle()
// from the row's existing wp_version. Mirrors P2.3 Task 15's pattern.
if (
    $response['status'] === 409
    && ($response['body']['error']['code'] ?? '') === 'core.no_update_available'
) {
    $this->sites->markCoreUpdateSucceeded($siteId, $wpVersionBeforeAttempt, $now);
    $this->log->log(null, $siteId, 'core_update.succeeded_no_change', [
        'current_version' => $wpVersionBeforeAttempt,
    ]);
    return;
}

// 409 + core.major_update_blocked -> immediate fail, NO retry.
//
// Reaching this branch means the dashboard's preflight in
// SitesCoreUpdateController allowed a major bump through (a contract
// violation), and the connector's own major-bump guard caught it. We
// treat it as a soft operator-visible failure — surfacing it is safer
// than retrying.
if (
    $response['status'] === 409
    && ($response['body']['error']['code'] ?? '') === 'core.major_update_blocked'
) {
    $errorMessage = (string) ($response['body']['error']['message'] ?? 'Major-version update blocked.');
    $this->sites->markCoreUpdateFailed($siteId, $errorMessage, $now);
    $this->log->log(null, $siteId, 'core_update.blocked_major', [
        'error_message' => $errorMessage,
    ]);
    return;
}

// 409 + connector.upgrade_in_progress -> exponential backoff retry (max 5).
// 60s, 120s, 240s, 480s, 960s -> ~32 min total budget.
if (
    $response['status'] === 409
    && ($response['body']['error']['code'] ?? '') === 'connector.upgrade_in_progress'
) {
    if ($attempt >= 5) {
        $this->sites->markCoreUpdateFailed(
            $siteId,
            'Site is busy after 5 retries.',
            $now,
        );
        $this->log->log(null, $siteId, 'core_update.failed', [
            'error_code'        => 'retry_exhausted',
            'error_message'     => 'Site is busy after 5 retries.',
            'attempted_version' => $targetVersion,
        ]);
        return;
    }

    $delay   = 60 * (2 ** $attempt);
    $nextRun = time() + $delay;
    \as_schedule_single_action($nextRun, self::HOOK, [$siteId, $attempt + 1]);
    $this->log->log(null, $siteId, 'core_update.retry', [
        'attempt'     => $attempt,
        'next_run_at' => gmdate('Y-m-d H:i:s', $nextRun),
    ]);
    return;
}

// All other failures (non-2xx, parse failure, transport error). No retry —
// for core, repeated non-lock failures probably indicate a real problem
// (out-of-disk, broken connector, network down) rather than transient
// hiccup. The operator gets a visible failed state + the truncated error
// message in the SPA tooltip.
$errorMessage = $response['body']['error']['message']
    ?? ($response['error'] !== '' ? $response['error'] : sprintf('Connector returned HTTP %d.', $response['status']));

$this->sites->markCoreUpdateFailed($siteId, $errorMessage, $now);
$this->log->log(null, $siteId, 'core_update.failed', [
    'error_code'        => 'connector_failure',
    'error_message'     => $errorMessage,
    'attempted_version' => $targetVersion,
]);
```

- [ ] **Step 4: Run the full `UpdateSiteCoreTest` class, verify all pass**

```
cd packages/dashboard-plugin && composer test -- --filter UpdateSiteCoreTest
```

Expected: PASS (9/9 — 3 from Task 13 + 6 added in this task).

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Jobs/UpdateSiteCore.php \
        packages/dashboard-plugin/tests/Integration/Jobs/UpdateSiteCoreTest.php
git commit -m "feat(p2-4): UpdateSiteCore — 4 branches (success-by-other-means, major-block, retry, terminal)"
```

---

## Task 15 — `SitesCoreRefreshController` + `RateLimit::sitesCoreRefresh` (6/hr)

**Files:**
- Create: `packages/dashboard-plugin/src/Rest/SitesCoreRefreshController.php`
- Modify: `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php`
- Modify: `packages/dashboard-plugin/src/Rest/RestRouter.php`
- Test: `packages/dashboard-plugin/tests/Integration/Rest/SitesCoreRefreshTest.php`

`RateLimit::sitesCoreRefresh` is a **separate 6/hour bucket** from `pluginsRefresh` and `sitesThemesRefresh`. The integration test asserts that 6 plugin refreshes in the same hour do NOT block a 7th core refresh.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Rest\Middleware\RateLimit;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

final class SitesCoreRefreshTest extends AbstractSchemaTestCase
{
    private int $userId;
    private string $token;
    private int $siteId;

    protected function setUp(): void
    {
        parent::setUp();
        \Defyn\Dashboard\Activation::activate();
        (new \Defyn\Dashboard\Rest\RestRouter())->register();

        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_defyn_rl_%' OR option_name LIKE '_transient_timeout_defyn_rl_%'");

        $this->userId = self::factory()->user->create();
        $this->token = \Defyn\Dashboard\Auth\JwtIssuer::issue($this->userId);

        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'         => $this->userId,
            'url'             => 'https://smartcoding.test',
            'label'           => 'Smart',
            'status'          => 'active',
            'our_private_key' => '',
            'created_at'      => '2026-06-07 00:00:00',
        ]);
        $this->siteId = (int) $wpdb->insert_id;
    }

    public function testSuccessSchedulesJobAndReturns202(): void
    {
        $scheduled = [];
        \add_filter('pre_as_schedule_single_action', function ($pre, $when, $hook, $args) use (&$scheduled) {
            $scheduled[] = ['hook' => $hook, 'args' => $args];
            return 999;
        }, 10, 4);

        $response = rest_do_request($this->signed('POST', "/defyn/v1/sites/{$this->siteId}/core/refresh"));

        $this->assertSame(202, $response->get_status());
        $body = $response->get_data();
        $this->assertTrue($body['scheduled']);
        $this->assertSame($this->siteId, $body['site_id']);

        $this->assertSame('defyn_refresh_site_core', $scheduled[0]['hook']);
        $this->assertSame([$this->siteId], $scheduled[0]['args']);
    }

    public function testOwnerScoped404(): void
    {
        $response = rest_do_request($this->signed('POST', "/defyn/v1/sites/99999/core/refresh"));
        $this->assertSame(404, $response->get_status());
        $this->assertSame('sites.not_found', $response->get_data()['error']['code']);
    }

    public function testSeventhCallReturns429(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $res = rest_do_request($this->signed('POST', "/defyn/v1/sites/{$this->siteId}/core/refresh"));
            $this->assertSame(202, $res->get_status(), "call {$i} should pass");
        }
        $res = rest_do_request($this->signed('POST', "/defyn/v1/sites/{$this->siteId}/core/refresh"));
        $this->assertSame(429, $res->get_status());
        $this->assertSame('core.rate_limited', $res->get_data()['error']['code']);
    }

    public function testCoreRefreshBucketSeparateFromPluginsAndThemesRefresh(): void
    {
        // Fill both other buckets with 6 calls each.
        for ($i = 1; $i <= 6; $i++) {
            $r = new WP_REST_Request('POST', "/defyn/v1/sites/{$this->siteId}/plugins/refresh");
            $r->set_header('Authorization', 'Bearer ' . $this->token);
            $r->set_param('id', $this->siteId);
            $r->set_param('_authenticated_user_id', $this->userId);
            $this->assertTrue(RateLimit::pluginsRefresh($r));

            $r2 = new WP_REST_Request('POST', "/defyn/v1/sites/{$this->siteId}/themes/refresh");
            $r2->set_header('Authorization', 'Bearer ' . $this->token);
            $r2->set_param('id', $this->siteId);
            $r2->set_param('_authenticated_user_id', $this->userId);
            $this->assertTrue(RateLimit::sitesThemesRefresh($r2));
        }

        // A 7th plugin/theme would 429, but the first core refresh should still pass.
        $r3 = new WP_REST_Request('POST', "/defyn/v1/sites/{$this->siteId}/core/refresh");
        $r3->set_header('Authorization', 'Bearer ' . $this->token);
        $r3->set_param('id', $this->siteId);
        $r3->set_param('_authenticated_user_id', $this->userId);
        $this->assertTrue(RateLimit::sitesCoreRefresh($r3));
    }

    private function signed(string $method, string $path): WP_REST_Request
    {
        $request = new WP_REST_Request($method, $path);
        $request->set_header('Authorization', 'Bearer ' . $this->token);
        return $request;
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

```
cd packages/dashboard-plugin && composer test -- --filter SitesCoreRefreshTest
```

Expected: FAIL — controller + RateLimit method missing.

- [ ] **Step 3: Add `RateLimit::sitesCoreRefresh` (separate bucket)**

In `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php`, add constants alongside the existing themes/plugins constants:

```php
// P2.4 — refresh button on SiteCoreCard. Separate bucket from
// pluginsRefresh + sitesThemesRefresh per spec § 5.1 — operator clicking
// "Refresh WordPress core" must not be locked out by prior plugin or
// theme refreshes. 6/hour matches the plugins + themes baseline.
public const CORE_REFRESH_LIMIT  = 6;
public const CORE_REFRESH_WINDOW = HOUR_IN_SECONDS;
```

Add the permission_callback method beside `sitesThemesRefresh`:

```php
/**
 * Permission callback for POST /sites/{id}/core/refresh.
 *
 * Separate transient-bucket from pluginsRefresh + sitesThemesRefresh —
 * operator clicking "Refresh WordPress core" must not be locked out by
 * prior plugin or theme refreshes. Same auth-chain pattern as siblings.
 *
 * @return true|\WP_Error
 */
public static function sitesCoreRefresh(WP_REST_Request $request)
{
    $authResult = RequireAuth::check($request);
    if (is_wp_error($authResult)) {
        return $authResult;
    }

    $userId = (int) $request->get_param('_authenticated_user_id');
    $siteId = (int) $request['id'];

    $key   = sprintf('defyn_rl_core_refresh_%d_%d', $userId, $siteId);
    $count = (int) (get_transient($key) ?: 0);

    if ($count >= self::CORE_REFRESH_LIMIT) {
        return new \WP_Error(
            'core.rate_limited',
            'Refresh requested too often. Wait an hour and try again.',
            ['status' => 429]
        );
    }

    set_transient($key, $count + 1, self::CORE_REFRESH_WINDOW);
    return true;
}
```

- [ ] **Step 4: Implement `SitesCoreRefreshController` + register the route**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Jobs\RefreshSiteCore;
use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P2.4 — POST /defyn/v1/sites/{id}/core/refresh.
 *
 * Schedules `defyn_refresh_site_core` (Action Scheduler, handled by
 * Jobs\RefreshSiteCore) and writes `core_inventory.refresh_requested`
 * to the activity log. Returns 202 — the connector roundtrip + delta sync
 * runs async on the next AS tick.
 *
 * Mirrors SitesThemesRefreshController's user-scoped ownership gate.
 * Rate-limited by RateLimit::sitesCoreRefresh — 6/hour per (user, site),
 * separate from pluginsRefresh + sitesThemesRefresh.
 */
final class SitesCoreRefreshController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');

        $site = (new SitesRepository())->findByIdForUser($siteId, $userId);
        if ($site === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }

        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time(), RefreshSiteCore::HOOK, [$siteId], 'defyn');
        }

        (new ActivityLogger())->log($userId, $siteId, 'core_inventory.refresh_requested', null);

        return new WP_REST_Response(['scheduled' => true, 'site_id' => $siteId], 202);
    }
}
```

In `packages/dashboard-plugin/src/Rest/RestRouter.php`, after the existing themes routes block:

```php
register_rest_route(self::NAMESPACE, '/sites/(?P<id>\d+)/core/refresh', [
    'methods'             => 'POST',
    'callback'            => [new SitesCoreRefreshController(), 'handle'],
    'permission_callback' => [RateLimit::class, 'sitesCoreRefresh'],
]);
```

Add `use Defyn\Dashboard\Rest\SitesCoreRefreshController;` near the existing controller imports.

- [ ] **Step 5: Run the test, verify all pass**

```
cd packages/dashboard-plugin && composer test -- --filter SitesCoreRefreshTest
```

Expected: PASS (4/4).

- [ ] **Step 6: Commit**

```bash
git add packages/dashboard-plugin/src/Rest/SitesCoreRefreshController.php \
        packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php \
        packages/dashboard-plugin/src/Rest/RestRouter.php \
        packages/dashboard-plugin/tests/Integration/Rest/SitesCoreRefreshTest.php
git commit -m "feat(p2-4): POST /defyn/v1/sites/{id}/core/refresh + separate 6/hr rate-limit bucket"
```

---

## Task 16 — `SitesCoreUpdateController` + `RateLimit::coreUpdate` (3/hr)

**Files:**
- Create: `packages/dashboard-plugin/src/Rest/SitesCoreUpdateController.php`
- Modify: `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php`
- Modify: `packages/dashboard-plugin/src/Rest/RestRouter.php`
- Test: `packages/dashboard-plugin/tests/Integration/Rest/SitesCoreUpdateTest.php`

Four preflight guards:
1. `SitesRepository::findByIdForUser` → 404 `sites.not_found`
2. `core_update_available === 0` → 409 `core.no_update_available_for_site`
3. `core_update_state IN ('queued', 'updating')` → 409 `core.update_in_progress`
4. Dashboard-side major-bump detection on `(wp_version, core_update_version)` → 409 `core.major_update_blocked`

`RateLimit::coreUpdate` is **3/hour per (user, site)** — tighter than themes/plugins at 6/hour. Test method MUST be named `testRateLimit429AfterFourthCall` (3 prior 202s then the 4th call 429s). Separate bucket from `pluginsUpdate` and `themesUpdate`.

Optimistic write to `core_update_state='queued'`, log `core_update.requested` with `{from_version, to_version}` (first event of the triplet), schedule the AS job, return 202.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Rest\Middleware\RateLimit;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

final class SitesCoreUpdateTest extends AbstractSchemaTestCase
{
    private int $userId;
    private string $token;
    private int $siteId;

    protected function setUp(): void
    {
        parent::setUp();
        \Defyn\Dashboard\Activation::activate();
        (new \Defyn\Dashboard\Rest\RestRouter())->register();

        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_defyn_rl_%' OR option_name LIKE '_transient_timeout_defyn_rl_%'");

        $this->userId = self::factory()->user->create();
        $this->token = \Defyn\Dashboard\Auth\JwtIssuer::issue($this->userId);

        $wpdb->insert(SitesTable::tableName(), [
            'user_id'                => $this->userId,
            'url'                    => 'https://smartcoding.test',
            'label'                  => 'Smart',
            'status'                 => 'active',
            'our_private_key'        => '',
            'wp_version'             => '7.0',
            'php_version'            => '8.3.31',
            'plugin_counts'          => '{"installed":0,"active":0}',
            'theme_counts'           => '{"installed":0,"active":0}',
            'ssl_status'             => 'enabled',
            'ssl_expires_at'         => null,
            'last_sync_at'           => '2026-06-07 04:00:00',
            'last_contact_at'        => '2026-06-07 04:00:00',
            'created_at'             => '2026-06-07 00:00:00',
            'updated_at'             => '2026-06-07 04:00:00',
            'core_update_available'  => 1,
            'core_update_version'    => '7.0.1',
            'core_update_state'      => 'idle',
            'last_core_update_error' => null,
        ]);
        $this->siteId = (int) $wpdb->insert_id;
    }

    public function testHappyPathReturns202QueuedState(): void
    {
        $scheduled = [];
        \add_filter('pre_as_schedule_single_action', function ($pre, $when, $hook, $args) use (&$scheduled) {
            $scheduled[] = ['hook' => $hook, 'args' => $args];
            return 999;
        }, 10, 4);

        $response = rest_do_request($this->signed('POST', "/defyn/v1/sites/{$this->siteId}/core/update"));

        $this->assertSame(202, $response->get_status());
        $body = $response->get_data();
        $this->assertTrue($body['scheduled']);
        $this->assertSame($this->siteId, $body['site_id']);
        $this->assertSame('queued', $body['core_update_state']);

        // Optimistic write happened.
        $row = (new SitesRepository())->findById($this->siteId);
        $this->assertSame('queued', $row->coreUpdateState);

        // AS scheduled.
        $this->assertSame('defyn_update_site_core', $scheduled[0]['hook']);
        $this->assertSame([$this->siteId, 0], $scheduled[0]['args']);

        // core_update.requested logged with user_id.
        global $wpdb;
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}defyn_activity_log
                 WHERE event_type = 'core_update.requested' AND site_id = %d",
                $this->siteId
            )
        );
        $this->assertSame(1, $count);
    }

    public function testNotOwnedReturns404(): void
    {
        $response = rest_do_request($this->signed('POST', "/defyn/v1/sites/99999/core/update"));
        $this->assertSame(404, $response->get_status());
        $this->assertSame('sites.not_found', $response->get_data()['error']['code']);
    }

    public function testNoUpdateAvailableReturns409(): void
    {
        global $wpdb;
        $wpdb->update(SitesTable::tableName(),
            ['core_update_available' => 0, 'core_update_version' => null],
            ['id' => $this->siteId]);

        $response = rest_do_request($this->signed('POST', "/defyn/v1/sites/{$this->siteId}/core/update"));
        $this->assertSame(409, $response->get_status());
        $this->assertSame('core.no_update_available_for_site', $response->get_data()['error']['code']);
    }

    public function testUpdateInProgressReturns409(): void
    {
        global $wpdb;
        $wpdb->update(SitesTable::tableName(),
            ['core_update_state' => 'updating'],
            ['id' => $this->siteId]);

        $response = rest_do_request($this->signed('POST', "/defyn/v1/sites/{$this->siteId}/core/update"));
        $this->assertSame(409, $response->get_status());
        $this->assertSame('core.update_in_progress', $response->get_data()['error']['code']);
    }

    public function testMajorBumpReturns409DashboardSideFastFail(): void
    {
        global $wpdb;
        $wpdb->update(SitesTable::tableName(),
            ['wp_version' => '7.0', 'core_update_version' => '8.0'],
            ['id' => $this->siteId]);

        $scheduled = [];
        \add_filter('pre_as_schedule_single_action', function ($pre, $when, $hook, $args) use (&$scheduled) {
            $scheduled[] = ['hook' => $hook, 'args' => $args];
            return 999;
        }, 10, 4);

        $response = rest_do_request($this->signed('POST', "/defyn/v1/sites/{$this->siteId}/core/update"));

        $this->assertSame(409, $response->get_status());
        $this->assertSame('core.major_update_blocked', $response->get_data()['error']['code']);

        // Critical: NO AS job was scheduled. The dashboard-side fast-fail
        // means the operator gets the 409 immediately without an AS roundtrip.
        $this->assertEmpty($scheduled);

        // Row stays idle — no optimistic write.
        $row = (new SitesRepository())->findById($this->siteId);
        $this->assertSame('idle', $row->coreUpdateState);
    }

    /**
     * Spec § 5.2 says 3/hour, NOT 6/hour like themes/plugins.
     * Common copy-paste trap from P2.3: the test method name MUST be
     * `testRateLimit429AfterFourthCall`, not `AfterSeventhCall`.
     */
    public function testRateLimit429AfterFourthCall(): void
    {
        // Reset the optimistic-write side-effect after each successful call by
        // flipping the state back to idle so the next preflight passes.
        global $wpdb;
        for ($i = 1; $i <= 3; $i++) {
            $res = rest_do_request($this->signed('POST', "/defyn/v1/sites/{$this->siteId}/core/update"));
            $this->assertSame(202, $res->get_status(), "call {$i} should pass");
            $wpdb->update(SitesTable::tableName(), ['core_update_state' => 'idle'], ['id' => $this->siteId]);
        }

        $res = rest_do_request($this->signed('POST', "/defyn/v1/sites/{$this->siteId}/core/update"));
        $this->assertSame(429, $res->get_status());
        $this->assertSame('core.rate_limited', $res->get_data()['error']['code']);
    }

    public function testCoreUpdateBucketSeparateFromPluginsUpdate(): void
    {
        // Fill the pluginsUpdate bucket for 6 different slugs (the bucket
        // key includes the slug for pluginsUpdate per P2.2).
        for ($i = 1; $i <= 6; $i++) {
            $r = new WP_REST_Request('POST', "/defyn/v1/sites/{$this->siteId}/plugins/plugin-{$i}/update");
            $r->set_header('Authorization', 'Bearer ' . $this->token);
            $r->set_param('id', $this->siteId);
            $r->set_param('slug', 'plugin-' . $i);
            $r->set_param('_authenticated_user_id', $this->userId);
            $this->assertTrue(RateLimit::pluginsUpdate($r));
        }

        // First core update for this (user, site) must still pass — separate bucket.
        $r = new WP_REST_Request('POST', "/defyn/v1/sites/{$this->siteId}/core/update");
        $r->set_header('Authorization', 'Bearer ' . $this->token);
        $r->set_param('id', $this->siteId);
        $r->set_param('_authenticated_user_id', $this->userId);
        $this->assertTrue(RateLimit::coreUpdate($r));
    }

    private function signed(string $method, string $path): WP_REST_Request
    {
        $request = new WP_REST_Request($method, $path);
        $request->set_header('Authorization', 'Bearer ' . $this->token);
        return $request;
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

```
cd packages/dashboard-plugin && composer test -- --filter SitesCoreUpdateTest
```

Expected: FAIL — controller + RateLimit method missing.

- [ ] **Step 3: Add `RateLimit::coreUpdate` (3/hr per (user, site))**

In `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php`, add constants:

```php
// P2.4 — operator-triggered core update. STRICTLY TIGHTER than
// pluginsUpdate + themesUpdate (6/hr each) per spec § 5.2 — core updates
// are higher-impact and an operator pressing this 4 times in an hour is
// almost certainly a mistake. Per-(user, site), NOT per-(user, site,
// slug) because there IS no slug for single-resource core.
public const CORE_UPDATE_LIMIT  = 3;
public const CORE_UPDATE_WINDOW = HOUR_IN_SECONDS;
```

Add the method beside `themesUpdate`:

```php
/**
 * Permission callback for POST /sites/{id}/core/update.
 *
 * 3/hour bucket per (user, site) — tighter than pluginsUpdate +
 * themesUpdate. Separate transient-bucket so a 6-plugin batch in one
 * hour doesn't lock out the first core update.
 *
 * @return true|\WP_Error
 */
public static function coreUpdate(WP_REST_Request $request)
{
    $authResult = RequireAuth::check($request);
    if (is_wp_error($authResult)) {
        return $authResult;
    }

    $userId = (int) $request->get_param('_authenticated_user_id');
    $siteId = (int) $request['id'];

    $key   = sprintf('defyn_rl_coreUpdate_%d_%d', $userId, $siteId);
    $count = (int) (get_transient($key) ?: 0);

    if ($count >= self::CORE_UPDATE_LIMIT) {
        return new \WP_Error(
            'core.rate_limited',
            'Too many core update requests for this site. Try again in an hour.',
            ['status' => 429]
        );
    }

    set_transient($key, $count + 1, self::CORE_UPDATE_WINDOW);
    return true;
}
```

- [ ] **Step 4: Implement `SitesCoreUpdateController` + register the route**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Jobs\UpdateSiteCore;
use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P2.4 — POST /defyn/v1/sites/{id}/core/update.
 *
 * Single-resource: no {slug} path param. Auth + 3/hour per (user, site)
 * rate limit run in the permission_callback layer (RateLimit::coreUpdate,
 * which chains RequireAuth::check). This handler runs FOUR preflight guards
 * before scheduling the AS job:
 *
 *   1. Owner check via SitesRepository::findByIdForUser -> 404 sites.not_found.
 *   2. core_update_available === 0 -> 409 core.no_update_available_for_site.
 *   3. core_update_state IN ('queued', 'updating') -> 409 core.update_in_progress.
 *   4. Dashboard-side major-bump detection on (wp_version, core_update_version)
 *      using the same isMinorUpgrade helper -> 409 core.major_update_blocked.
 *      Fast-fails without an AS roundtrip (the connector also rejects, but
 *      we don't want to schedule + log requested + log started just to fail).
 *
 * On all guards passing:
 *   - markCoreUpdateRequested writes optimistic core_update_state='queued'.
 *   - core_update.requested activity log with operator's userId + the
 *     from/to versions snapshotted at queue time. First of the triplet.
 *   - as_schedule_single_action(UpdateSiteCore::HOOK, [$siteId, 0]).
 *
 * Returns 202 Accepted.
 *
 * Spec: docs/superpowers/specs/2026-06-07-p2-4-core-updates-design.md §5.2
 */
final class SitesCoreUpdateController
{
    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request->get_param('id');

        $repo = new SitesRepository();
        $site = $repo->findByIdForUser($siteId, $userId);
        if ($site === null) {
            return ErrorResponse::create(404, 'sites.not_found', 'Site not found.');
        }

        if (!$site->coreUpdateAvailable) {
            return ErrorResponse::create(
                409,
                'core.no_update_available_for_site',
                'No WordPress core update is available for this site.',
            );
        }

        if (in_array($site->coreUpdateState, ['queued', 'updating'], true)) {
            return ErrorResponse::create(
                409,
                'core.update_in_progress',
                'A core update for this site is already in progress.',
            );
        }

        $currentVersion = (string) ($site->wpVersion ?? '');
        $targetVersion  = (string) ($site->coreUpdateVersion ?? '');
        if (!self::isMinorUpgrade($currentVersion, $targetVersion)) {
            return ErrorResponse::create(
                409,
                'core.major_update_blocked',
                sprintf(
                    'Major-version updates (%s -> %s) require P2.4.1.',
                    $currentVersion,
                    $targetVersion
                ),
            );
        }

        $now = gmdate('Y-m-d H:i:s');
        $repo->markCoreUpdateRequested($siteId, $now);

        (new ActivityLogger())->log($userId, $siteId, 'core_update.requested', [
            'from_version' => $currentVersion,
            'to_version'   => $targetVersion,
        ]);

        \as_schedule_single_action(time(), UpdateSiteCore::HOOK, [$siteId, 0]);

        return new WP_REST_Response([
            'scheduled'         => true,
            'site_id'           => $siteId,
            'core_update_state' => 'queued',
        ], 202);
    }

    /**
     * Same logic as the connector's CoreUpgraderService::isMinorUpgrade.
     * Reimplemented dashboard-side because the dashboard preflight runs
     * BEFORE the connector roundtrip.
     */
    private static function isMinorUpgrade(string $current, string $target): bool
    {
        [$cMaj, $cMin] = array_pad(array_slice(explode('.', $current), 0, 2), 2, '0');
        [$tMaj, $tMin] = array_pad(array_slice(explode('.', $target), 0, 2), 2, '0');
        return $cMaj === $tMaj && $cMin === $tMin;
    }
}
```

In `packages/dashboard-plugin/src/Rest/RestRouter.php`:

```php
register_rest_route(self::NAMESPACE, '/sites/(?P<id>\d+)/core/update', [
    'methods'             => 'POST',
    'callback'            => [new SitesCoreUpdateController(), 'handle'],
    'permission_callback' => [RateLimit::class, 'coreUpdate'],
]);
```

Add `use Defyn\Dashboard\Rest\SitesCoreUpdateController;`.

- [ ] **Step 5: Run the test, verify all pass**

```
cd packages/dashboard-plugin && composer test -- --filter SitesCoreUpdateTest
```

Expected: PASS (7/7).

- [ ] **Step 6: Commit**

```bash
git add packages/dashboard-plugin/src/Rest/SitesCoreUpdateController.php \
        packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php \
        packages/dashboard-plugin/src/Rest/RestRouter.php \
        packages/dashboard-plugin/tests/Integration/Rest/SitesCoreUpdateTest.php
git commit -m "feat(p2-4): POST /defyn/v1/sites/{id}/core/update + 3/hr rate-limit bucket + 4 preflight guards"
```

---

## Task 17 — Wire AS hooks in `Plugin::boot()` + extend `SyncSite`

**Files:**
- Modify: `packages/dashboard-plugin/src/Plugin.php`
- Modify: `packages/dashboard-plugin/src/Jobs/SyncSite.php`
- Test: `packages/dashboard-plugin/tests/Integration/PluginBootASHookCoreTest.php`

Register both new AS hook handlers (`defyn_refresh_site_core`, `defyn_update_site_core`) so AS can dispatch them. Also extend `SyncSite::handle()` to schedule `defyn_refresh_site_core` alongside the existing plugins + themes refresh fan-out (spec § 4.5).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Jobs\RefreshSiteCore;
use Defyn\Dashboard\Jobs\SyncSite;
use Defyn\Dashboard\Jobs\UpdateSiteCore;
use Defyn\Dashboard\Plugin;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Services\SyncPluginsService;
use Defyn\Dashboard\Services\SyncService;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PluginBootASHookCoreTest extends AbstractSchemaTestCase
{
    public function testRefreshSiteCoreHookRegistered(): void
    {
        Plugin::instance()->boot();
        $this->assertNotFalse(has_action(RefreshSiteCore::HOOK));
    }

    public function testUpdateSiteCoreHookRegistered(): void
    {
        Plugin::instance()->boot();
        $this->assertNotFalse(has_action(UpdateSiteCore::HOOK));
    }

    public function testSyncSiteAlsoSchedulesCoreRefresh(): void
    {
        \Defyn\Dashboard\Activation::activate();

        $keypair = sodium_crypto_sign_keypair();
        $privateKey = base64_encode(sodium_crypto_sign_secretkey($keypair));
        $vault = new Vault(DEFYN_VAULT_KEY);
        $encrypted = $vault->encrypt($privateKey);

        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'         => 1,
            'url'             => 'https://smartcoding.test',
            'label'           => 'Smart',
            'status'          => 'active',
            'our_private_key' => $encrypted,
            'site_public_key' => base64_encode(sodium_crypto_sign_publickey($keypair)),
            'created_at'      => '2026-06-07 00:00:00',
        ]);
        $siteId = (int) $wpdb->insert_id;

        // Stub SyncService + SignedHttpClient so SyncSite::handle runs without errors.
        $statusBody  = json_encode(['ok' => true, 'wp_version' => '6.6', 'php_version' => '8.2']);
        $pluginsBody = json_encode(['plugins' => [], 'truncated' => false, 'server_time' => time()]);
        $factory = function (string $method, string $url) use ($statusBody, $pluginsBody) {
            if (str_contains($url, '/plugins')) {
                return new MockResponse($pluginsBody, ['http_code' => 200]);
            }
            return new MockResponse($statusBody, ['http_code' => 200]);
        };

        $scheduled = [];
        \add_filter('pre_as_schedule_single_action', function ($pre, $when, $hook, $args) use (&$scheduled) {
            $scheduled[] = ['hook' => $hook, 'args' => $args];
            return 999;
        }, 10, 4);

        $sync = new SyncSite(
            new SyncService(),
            new SitesRepository(),
            new SignedHttpClient(new MockHttpClient($factory)),
            new SyncPluginsService(),
            new ActivityLogger(),
        );
        $sync->handle($siteId);

        $hooks = array_column($scheduled, 'hook');
        $this->assertContains('defyn_refresh_site_plugins', $hooks);
        $this->assertContains('defyn_refresh_site_themes', $hooks);
        $this->assertContains('defyn_refresh_site_core', $hooks);
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

```
cd packages/dashboard-plugin && composer test -- --filter PluginBootASHookCoreTest
```

Expected: FAIL — hooks not registered + `SyncSite::handle` doesn't fan out to core.

- [ ] **Step 3: Add the hook registrations in `Plugin::boot()`**

In `packages/dashboard-plugin/src/Plugin.php`, add the imports near the existing Jobs imports:

```php
use Defyn\Dashboard\Jobs\RefreshSiteCore;
use Defyn\Dashboard\Jobs\UpdateSiteCore;
```

Inside `boot()`, after the existing P2.3 `UpdateSiteTheme::HOOK` block, append:

```php
// P2.4 — operator-triggered WP core inventory refresh.
add_action(RefreshSiteCore::HOOK, static function (int $siteId): void {
    (new RefreshSiteCore())->handle($siteId);
}, 10, 1);

// P2.4 — operator-triggered WP core update.
add_action(UpdateSiteCore::HOOK, static function (int $siteId, int $attempt = 0): void {
    (new UpdateSiteCore())->handle($siteId, $attempt);
}, 10, 2);
```

- [ ] **Step 4: Extend `SyncSite::handle()` to schedule core refresh**

In `packages/dashboard-plugin/src/Jobs/SyncSite.php`, immediately after the existing `as_schedule_single_action(time(), RefreshSiteThemes::HOOK, [$siteId], 'defyn');` block, append:

```php
// P2.4 — fan-out to core refresh too. Core inventory hydrates on the
// same recurring tick as plugins + themes. Best-effort scheduling.
if (function_exists('as_schedule_single_action')) {
    as_schedule_single_action(time(), RefreshSiteCore::HOOK, [$siteId], 'defyn');
}
```

Add `use Defyn\Dashboard\Jobs\RefreshSiteCore;` to the imports.

- [ ] **Step 5: Run the test, verify it passes**

```
cd packages/dashboard-plugin && composer test -- --filter PluginBootASHookCoreTest
```

Expected: PASS (3/3).

- [ ] **Step 6: Commit**

```bash
git add packages/dashboard-plugin/src/Plugin.php \
        packages/dashboard-plugin/src/Jobs/SyncSite.php \
        packages/dashboard-plugin/tests/Integration/PluginBootASHookCoreTest.php
git commit -m "feat(p2-4): register core AS hooks + extend SyncSite to schedule core refresh"
```

---

## Task 18 — Dashboard v0.5.0 release bump + CORS regression

**Files:**
- Modify: `packages/dashboard-plugin/defyn-dashboard.php`
- Modify: `packages/dashboard-plugin/readme.txt`
- Test: `packages/dashboard-plugin/tests/Integration/Rest/SitesCoreUpdateCorsTest.php`

Bump dashboard to 0.5.0 and add a CORS regression covering the two new core routes (the existing CORS filter from F3a already allows `defyn/v1/*` so this is purely defensive).

- [ ] **Step 1: Write the CORS test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Rest\RestRouter;
use WP_REST_Request;
use WP_UnitTestCase;

final class SitesCoreUpdateCorsTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new RestRouter())->register();
    }

    public function testOptionsRequestOnCoreRefreshGetsCorsHeaders(): void
    {
        $request = new WP_REST_Request('OPTIONS', '/defyn/v1/sites/1/core/refresh');
        $request->set_header('Origin', 'https://app.defynwp.defyn.agency');
        $request->set_header('Access-Control-Request-Method', 'POST');
        $request->set_header('Access-Control-Request-Headers', 'authorization');

        $response = rest_do_request($request);
        $filtered = apply_filters('rest_post_dispatch', $response, rest_get_server(), $request);

        $this->assertStringContainsString(
            'app.defynwp.defyn.agency',
            $filtered->get_headers()['Access-Control-Allow-Origin'] ?? '',
        );
    }

    public function testOptionsRequestOnCoreUpdateGetsCorsHeaders(): void
    {
        $request = new WP_REST_Request('OPTIONS', '/defyn/v1/sites/1/core/update');
        $request->set_header('Origin', 'https://app.defynwp.defyn.agency');
        $request->set_header('Access-Control-Request-Method', 'POST');
        $request->set_header('Access-Control-Request-Headers', 'authorization');

        $response = rest_do_request($request);
        $filtered = apply_filters('rest_post_dispatch', $response, rest_get_server(), $request);

        $this->assertStringContainsString(
            'app.defynwp.defyn.agency',
            $filtered->get_headers()['Access-Control-Allow-Origin'] ?? '',
        );
    }

    public function testUnauthenticatedPostReturnsEnvelopeShape(): void
    {
        $request = new WP_REST_Request('POST', '/defyn/v1/sites/1/core/update');
        $response = rest_do_request($request);

        $data = $response->get_data();
        $this->assertArrayHasKey('error', $data);
        $this->assertArrayHasKey('code', $data['error']);
        $this->assertArrayHasKey('message', $data['error']);
    }
}
```

- [ ] **Step 2: Run the test, verify it passes (existing CORS filter already covers the new routes)**

```
cd packages/dashboard-plugin && composer test -- --filter SitesCoreUpdateCorsTest
```

Expected: PASS (3/3). If it fails the CORS filter has a defyn/v1 prefix check that should already pass — investigate the filter rather than narrowing the assertion.

- [ ] **Step 3: Bump version + add changelog**

In `defyn-dashboard.php`:

```
 * Version:           0.5.0
```

In `readme.txt`:

```
Stable tag: 0.5.0
```

Add a new changelog block above the existing `= 0.4.0 =`:

```
= 0.5.0 =
* Feature: operator can update WordPress core (minor versions only) on managed sites from the DefynWP dashboard. New POST /defyn/v1/sites/{id}/core/refresh forces a fresh wp_version_check() poll on the connector. POST /defyn/v1/sites/{id}/core/update schedules an AS job that calls the connector's signed /core/update endpoint with a 300s HTTP timeout. The new SiteCoreCard renders above the SiteSummaryCard with four visual states (up to date / update available / updating / failed) and an amber confirmation dialog. Schema bumps v4 -> v5 — adds 5 new core_update_* columns to wp_defyn_sites + an idx_core_update_available index. Day-1 single-row heal in SitesRepository::markSynced resets stuck failed states when the connector reports no update available. Activity log emits core_update.requested -> core_update.started -> core_update.succeeded|failed triplet. Tighter 3/hour rate limit on the update endpoint (vs themes/plugins at 6/hour). Major bumps (7.0 -> 7.1+) are explicitly blocked at both connector + dashboard with a core.major_update_blocked envelope; deferred to P2.4.1. Auto-runs via plugins_loaded self-heal — no manual deact/react required.
```

- [ ] **Step 4: Run the full dashboard suite to confirm baseline green**

```
cd packages/dashboard-plugin && composer test
```

Expected: ALL PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/defyn-dashboard.php \
        packages/dashboard-plugin/readme.txt \
        packages/dashboard-plugin/tests/Integration/Rest/SitesCoreUpdateCorsTest.php
git commit -m "chore(p2-4): dashboard v0.5.0 — release version bump + CORS regression"
```

---

## Task 19 — SPA Zod schema extension + MSW handlers

**Files:**
- Modify: `apps/web/src/types/api.ts`
- Modify: `apps/web/src/test/handlers.ts`
- Test: append to existing `apps/web/tests/types/api.test.ts` (or create if absent)

Extend `siteSchema` with the 5 new persisted core fields + 2 optional transient meta fields from the connector. The existing `useSite(siteId)` query already POSTs to `/sites/{id}` — no new query hook needed. Add MSW handlers for the new POST endpoints.

- [ ] **Step 1: Write the failing test (append)**

```ts
// apps/web/tests/types/api.test.ts (append)
import { describe, it, expect } from 'vitest';
import { siteSchema } from '@/types/api';

describe('siteSchema — P2.4 core extension', () => {
  it('accepts a site with all 5 new core fields populated', () => {
    const parsed = siteSchema.parse({
      id: 1,
      url: 'https://smartcoding.test',
      label: 'Smart',
      status: 'active',
      last_contact_at: '2026-06-07 04:00:00',
      last_sync_at: '2026-06-07 04:00:00',
      last_error: null,
      created_at: '2026-06-07 00:00:00',
      wp_version: '7.0',
      php_version: '8.3.31',
      active_theme: null,
      plugin_counts: { installed: 21, active: 20 },
      theme_counts: { installed: 8, active: 1 },
      ssl_status: 'enabled',
      ssl_expires_at: null,
      core_update_available: true,
      core_update_version: '7.0.1',
      core_update_state: 'queued',
      last_core_update_error: null,
      last_core_update_attempt_at: '2026-06-07 09:00:00',
      is_minor_update: true,
      is_auto_update_enabled: false,
    });
    expect(parsed.core_update_available).toBe(true);
    expect(parsed.core_update_state).toBe('queued');
    expect(parsed.is_minor_update).toBe(true);
  });

  it('accepts a site with no core update + no transient meta', () => {
    const parsed = siteSchema.parse({
      id: 1,
      url: 'https://smartcoding.test',
      label: 'Smart',
      status: 'active',
      last_contact_at: '2026-06-07 04:00:00',
      last_sync_at: '2026-06-07 04:00:00',
      last_error: null,
      created_at: '2026-06-07 00:00:00',
      wp_version: '7.0',
      php_version: '8.3.31',
      active_theme: null,
      plugin_counts: { installed: 21, active: 20 },
      theme_counts: { installed: 8, active: 1 },
      ssl_status: 'enabled',
      ssl_expires_at: null,
      core_update_available: false,
      core_update_version: null,
      core_update_state: 'idle',
      last_core_update_error: null,
      last_core_update_attempt_at: null,
      // is_minor_update + is_auto_update_enabled intentionally absent
    });
    expect(parsed.core_update_available).toBe(false);
    expect(parsed.is_minor_update).toBeUndefined();
  });

  it('rejects an unknown core_update_state value', () => {
    expect(() => siteSchema.parse({
      id: 1,
      url: 'https://smartcoding.test',
      label: 'Smart',
      status: 'active',
      last_contact_at: null,
      last_sync_at: null,
      last_error: null,
      created_at: '2026-06-07 00:00:00',
      wp_version: null,
      php_version: null,
      active_theme: null,
      plugin_counts: null,
      theme_counts: null,
      ssl_status: null,
      ssl_expires_at: null,
      core_update_available: false,
      core_update_version: null,
      core_update_state: 'mystery',
      last_core_update_error: null,
      last_core_update_attempt_at: null,
    })).toThrow();
  });
});
```

- [ ] **Step 2: Run the test, verify it fails**

```
cd apps/web && pnpm test -- --run tests/types/api.test.ts
```

Expected: FAIL — siteSchema rejects the new fields.

- [ ] **Step 3: Extend `apps/web/src/types/api.ts`**

In the `siteSchema = z.object({...})` definition, append five new persisted fields + two optional transient meta fields BEFORE the closing brace:

```ts
// P2.4 — persisted core update state machine fields.
core_update_available: z.boolean(),
core_update_version: z.string().nullable(),
core_update_state: z.enum(['idle', 'queued', 'updating', 'failed']),
last_core_update_error: z.string().nullable(),
last_core_update_attempt_at: z.string().nullable(),

// P2.4 — transient meta from connector /status, NOT persisted to
// wp_defyn_sites. Optional because they only surface after a fresh
// /sites/{id} fetch (which includes the connector roundtrip in the
// background) — the persisted row alone doesn't carry them.
is_minor_update: z.boolean().optional(),
is_auto_update_enabled: z.boolean().optional(),
```

- [ ] **Step 4: Run the schema test, verify it passes**

```
cd apps/web && pnpm test -- --run tests/types/api.test.ts
```

Expected: PASS (3/3 new tests + existing tests).

- [ ] **Step 5: Add MSW handlers in `apps/web/src/test/handlers.ts`**

Find the existing `http.get(`${API_BASE}/sites/:id`, ...)` handler — extend its returned site stub so the new core fields are surfaced. Then append two new POST handlers below the existing themes handlers:

```ts
// P2.4 — core mock state
export const mockSiteCoreState: Record<number, {
  core_update_available: boolean;
  core_update_version: string | null;
  core_update_state: 'idle' | 'queued' | 'updating' | 'failed';
  last_core_update_error: string | null;
  last_core_update_attempt_at: string | null;
  is_minor_update?: boolean;
  is_auto_update_enabled?: boolean;
  wp_version_after_upgrade?: string;
}> = {};

export function resetMockSiteCoreState() {
  for (const k of Object.keys(mockSiteCoreState)) {
    delete mockSiteCoreState[Number(k)];
  }
}

// Append the two POST handlers to the existing `handlers` array:
http.post(`${API_BASE}/sites/:id/core/refresh`, ({ params }) => {
  const siteId = Number(params.id);
  return HttpResponse.json({ scheduled: true, site_id: siteId }, { status: 202 });
}),

http.post(`${API_BASE}/sites/:id/core/update`, ({ params }) => {
  const siteId = Number(params.id);
  const state = mockSiteCoreState[siteId];
  if (!state || !state.core_update_available) {
    return HttpResponse.json(
      { error: { code: 'core.no_update_available_for_site', message: 'No update available.' } },
      { status: 409 },
    );
  }

  // Optimistic queued.
  mockSiteCoreState[siteId] = { ...state, core_update_state: 'queued' };

  // Deferred transitions: queued -> updating @ 50ms, updating -> idle @ 200ms.
  setTimeout(() => {
    const cur = mockSiteCoreState[siteId];
    if (cur && cur.core_update_state === 'queued') {
      mockSiteCoreState[siteId] = { ...cur, core_update_state: 'updating' };
    }
  }, 50);
  setTimeout(() => {
    const cur = mockSiteCoreState[siteId];
    if (!cur || cur.core_update_state !== 'updating') return;
    mockSiteCoreState[siteId] = {
      ...cur,
      core_update_state: 'idle',
      core_update_available: false,
      core_update_version: null,
    };
  }, 200);

  return HttpResponse.json({
    scheduled: true,
    site_id: siteId,
    core_update_state: 'queued',
  }, { status: 202 });
}),
```

Also extend the existing `/sites/:id` GET handler — when returning the mock site, merge in the `mockSiteCoreState[siteId]` if present (otherwise use the default `core_update_available: false, core_update_state: 'idle', ...` shape). This ensures `useSite(siteId)` sees state transitions during polling-pin tests.

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/types/api.ts \
        apps/web/src/test/handlers.ts \
        apps/web/tests/types/api.test.ts
git commit -m "feat(p2-4): siteSchema accepts 5 core fields + 2 transient meta + MSW handlers"
```

---

## Task 20 — `useRefreshSiteCore` + `useUpdateSiteCore` mutation hooks

**Files:**
- Create: `apps/web/src/lib/mutations/useRefreshSiteCore.ts`
- Create: `apps/web/src/lib/mutations/useUpdateSiteCore.ts`
- Test: `apps/web/tests/lib/mutations/useRefreshSiteCore.test.tsx`
- Test: `apps/web/tests/lib/mutations/useUpdateSiteCore.test.tsx`

`useRefreshSiteCore` mirrors `useRefreshSitePlugins` — POSTs, invalidates `['sites', siteId]`, polls `useSite` until `last_sync_at` advances (60s hard cap).

`useUpdateSiteCore` is the heavier hook: POSTs, optimistic write so card re-renders queued instantly, **pins `useSite(siteId)` polling at 30s** while `core_update_state IN ('queued', 'updating')` OR the mutation is in flight. Settles to 5min stale once state hits `idle` or `failed`. **5-minute hard cap** on the polling pin.

- [ ] **Step 1: Write the failing tests**

```tsx
// apps/web/tests/lib/mutations/useRefreshSiteCore.test.tsx
import { describe, it, expect, beforeEach } from 'vitest';
import { renderHook, act, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useRefreshSiteCore } from '@/lib/mutations/useRefreshSiteCore';
import { resetMockSiteCoreState } from '@/test/handlers';
import { setAccessToken } from '@/lib/apiClient';

function makeWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return ({ children }: { children: React.ReactNode }) =>
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

describe('useRefreshSiteCore', () => {
  beforeEach(() => {
    resetMockSiteCoreState();
    setAccessToken('fake');
  });

  it('fires POST and sets isPolling true after success', async () => {
    const { result } = renderHook(() => useRefreshSiteCore(1), { wrapper: makeWrapper() });

    act(() => { result.current.refresh(); });
    await waitFor(() => expect(result.current.isPolling).toBe(true));
  });
});
```

```tsx
// apps/web/tests/lib/mutations/useUpdateSiteCore.test.tsx
import { describe, it, expect, beforeEach } from 'vitest';
import { renderHook, act, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useUpdateSiteCore } from '@/lib/mutations/useUpdateSiteCore';
import { useSite } from '@/lib/queries/useSite';
import { mockSiteCoreState, resetMockSiteCoreState } from '@/test/handlers';
import { setAccessToken } from '@/lib/apiClient';

function makeWrapper() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return ({ children }: { children: React.ReactNode }) =>
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}

describe('useUpdateSiteCore', () => {
  beforeEach(() => {
    resetMockSiteCoreState();
    setAccessToken('fake');
    mockSiteCoreState[1] = {
      core_update_available: true,
      core_update_version: '7.0.1',
      core_update_state: 'idle',
      last_core_update_error: null,
      last_core_update_attempt_at: null,
      is_minor_update: true,
      is_auto_update_enabled: false,
    };
  });

  it('fires POST, transitions row to idle, stops polling', async () => {
    const Wrap = makeWrapper();

    const { result: siteHook } = renderHook(() => useSite(1), { wrapper: Wrap });
    const { result: mut } = renderHook(() => useUpdateSiteCore(1), { wrapper: Wrap });

    await waitFor(() => expect(siteHook.current.data).toBeDefined());

    act(() => { mut.current.update(); });
    await waitFor(() => expect(mut.current.isPolling).toBe(true));

    await waitFor(
      () => expect(mut.current.isPolling).toBe(false),
      { timeout: 4000 },
    );

    await waitFor(() => {
      const site = siteHook.current.data;
      expect(site?.core_update_state).toBe('idle');
      expect(site?.core_update_available).toBe(false);
    });
  });
});
```

- [ ] **Step 2: Run the tests, verify they fail**

```
cd apps/web && pnpm test -- --run tests/lib/mutations/useRefreshSiteCore.test.tsx tests/lib/mutations/useUpdateSiteCore.test.tsx
```

Expected: FAIL — hooks don't exist.

- [ ] **Step 3: Implement `useRefreshSiteCore`**

```ts
// apps/web/src/lib/mutations/useRefreshSiteCore.ts
import { useEffect, useRef, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { useSite } from '@/lib/queries/useSite';

const POLL_INTERVAL_MS = 30_000;
const HARD_CAP_MS = 60_000;

interface UseRefreshSiteCoreReturn {
  refresh: () => void;
  isPending: boolean;
  isPolling: boolean;
  error: unknown;
}

/**
 * Triggers a connector /core/refresh roundtrip + dashboard sync. While
 * polling, useSite(siteId) is pinned at 30s cadence until last_sync_at
 * advances past the refresh trigger or the 60s hard cap fires.
 */
export function useRefreshSiteCore(siteId: number): UseRefreshSiteCoreReturn {
  const queryClient = useQueryClient();
  const triggerAtRef = useRef<string | null>(null);
  const [isPolling, setIsPolling] = useState(false);

  const query = useSite(siteId, {
    pollWhilePending: isPolling ? POLL_INTERVAL_MS : 0,
  });

  useEffect(() => {
    if (!isPolling) return;
    const latest = query.data?.last_sync_at;
    const trigger = triggerAtRef.current;
    if (latest && trigger && latest > trigger) {
      setIsPolling(false);
    }
  }, [query.data?.last_sync_at, isPolling]);

  useEffect(() => {
    if (!isPolling) return;
    const timeoutId = window.setTimeout(() => setIsPolling(false), HARD_CAP_MS);
    return () => window.clearTimeout(timeoutId);
  }, [isPolling]);

  const mutation = useMutation({
    mutationFn: async () => {
      triggerAtRef.current = new Date().toISOString();
      return apiClient.post<{ scheduled: boolean; site_id: number }>(
        `/sites/${siteId}/core/refresh`,
      );
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['sites', siteId] });
      setIsPolling(true);
    },
  });

  return {
    refresh: () => mutation.mutate(),
    isPending: mutation.isPending,
    isPolling,
    error: mutation.error,
  };
}
```

- [ ] **Step 4: Implement `useUpdateSiteCore`**

```ts
// apps/web/src/lib/mutations/useUpdateSiteCore.ts
import { useEffect, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import { apiClient } from '@/lib/apiClient';
import { useSite } from '@/lib/queries/useSite';

const POLL_INTERVAL_MS = 30_000;
const HARD_CAP_MS = 5 * 60 * 1000;

const updateSiteCoreResponseSchema = z.object({
  scheduled: z.literal(true),
  site_id: z.number(),
  core_update_state: z.enum(['idle', 'queued', 'updating', 'failed']),
});

interface UseUpdateSiteCoreReturn {
  update: () => void;
  isPending: boolean;
  isPolling: boolean;
  error: unknown;
}

/**
 * Triggers a core update on the dashboard and pins useSite(siteId) at 30s
 * polling cadence until core_update_state settles on idle or failed. Hard
 * 5-minute polling cap.
 *
 * Mirrors useUpdateSiteTheme — both rely on useSite as the single source
 * of truth; TanStack Query dedupes by queryKey ['sites', siteId] so the
 * visible SiteCoreCard and this hook share one query instance.
 */
export function useUpdateSiteCore(siteId: number): UseUpdateSiteCoreReturn {
  const queryClient = useQueryClient();
  const [isPolling, setIsPolling] = useState(false);

  const query = useSite(siteId, {
    pollWhilePending: isPolling ? POLL_INTERVAL_MS : 0,
  });

  const state = query.data?.core_update_state;

  useEffect(() => {
    if (!isPolling) return;
    if (state === 'idle' || state === 'failed') {
      setIsPolling(false);
    }
  }, [state, isPolling]);

  useEffect(() => {
    if (!isPolling) return;
    const timeoutId = window.setTimeout(() => setIsPolling(false), HARD_CAP_MS);
    return () => window.clearTimeout(timeoutId);
  }, [isPolling]);

  const mutation = useMutation({
    mutationFn: async () => {
      const data = await apiClient.post<unknown>(`/sites/${siteId}/core/update`);
      return updateSiteCoreResponseSchema.parse(data);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['sites', siteId] });
      setIsPolling(true);
    },
  });

  return {
    update: () => mutation.mutate(),
    isPending: mutation.isPending,
    isPolling,
    error: mutation.error,
  };
}
```

Note: this implementation relies on `useSite(siteId, { pollWhilePending: N })` propagating its `pollWhilePending` to the underlying TanStack Query `refetchInterval` regardless of the site's `status`. Inspect `apps/web/src/lib/queries/useSite.ts` — if `pollWhilePending` currently triggers polling ONLY when `status === 'pending'`, broaden the predicate to also poll when the caller has passed a non-zero `pollWhilePending` value (the mutation hooks above pass a value precisely because they want polling regardless of status). One-line change in `useSite.ts`:

```ts
refetchInterval: (query) => {
  const data = query.state.data as Site | undefined;
  if (pollInterval > 0 && (data?.status === 'pending' || data?.core_update_state === 'queued' || data?.core_update_state === 'updating')) {
    return pollInterval;
  }
  return false;
},
```

- [ ] **Step 5: Run the tests, verify all pass**

```
cd apps/web && pnpm test -- --run tests/lib/mutations/useRefreshSiteCore.test.tsx tests/lib/mutations/useUpdateSiteCore.test.tsx
```

Expected: PASS (1/1 + 1/1).

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/lib/mutations/useRefreshSiteCore.ts \
        apps/web/src/lib/mutations/useUpdateSiteCore.ts \
        apps/web/src/lib/queries/useSite.ts \
        apps/web/tests/lib/mutations/useRefreshSiteCore.test.tsx \
        apps/web/tests/lib/mutations/useUpdateSiteCore.test.tsx
git commit -m "feat(p2-4): useRefreshSiteCore + useUpdateSiteCore mutations with polling pin"
```

---

## Task 21 — `SiteCoreCard` (4 visual states) + `ConfirmUpdateCoreDialog` + `SiteDetail` integration

**Files:**
- Create: `apps/web/src/components/sites/SiteCoreCard.tsx`
- Create: `apps/web/src/components/sites/ConfirmUpdateCoreDialog.tsx`
- Modify: `apps/web/src/routes/SiteDetail.tsx`
- Test: `apps/web/tests/components/sites/SiteCoreCard.test.tsx`
- Test: `apps/web/tests/components/sites/ConfirmUpdateCoreDialog.test.tsx`
- Test: `apps/web/tests/routes/SiteDetail.core.test.tsx`

Card has 4 visual states (idle no-update, idle update-available, updating, failed). Confirm dialog has TWO warning banners (downtime + downgrade) + conditional "Auto-updates ON" paragraph + amber primary button labelled "Yes, update WordPress core" + Cancel default focus. `SiteDetail` renders `<SiteCoreCard />` ABOVE `<SiteSummaryCard />`, BELOW `<SiteHeader />`.

- [ ] **Step 1: Write the failing `SiteCoreCard` test**

```tsx
// apps/web/tests/components/sites/SiteCoreCard.test.tsx
import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { SiteCoreCard } from '@/components/sites/SiteCoreCard';
import { mockSiteCoreState, resetMockSiteCoreState } from '@/test/handlers';
import { setAccessToken } from '@/lib/apiClient';

function wrap(siteId: number) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <SiteCoreCard siteId={siteId} />
    </QueryClientProvider>,
  );
}

describe('SiteCoreCard', () => {
  beforeEach(() => {
    resetMockSiteCoreState();
    setAccessToken('fake');
  });

  it('idle no-update renders only the version + meta line', async () => {
    mockSiteCoreState[1] = {
      core_update_available: false,
      core_update_version: null,
      core_update_state: 'idle',
      last_core_update_error: null,
      last_core_update_attempt_at: null,
      is_auto_update_enabled: true,
    };
    wrap(1);

    await waitFor(() => expect(screen.getByText(/WordPress/i)).toBeInTheDocument());
    expect(screen.queryByRole('button', { name: /^Update/ })).not.toBeInTheDocument();
    expect(screen.getByText(/Auto-updates ON/i)).toBeInTheDocument();
  });

  it('idle update-available renders version diff + Update button', async () => {
    mockSiteCoreState[1] = {
      core_update_available: true,
      core_update_version: '7.0.1',
      core_update_state: 'idle',
      last_core_update_error: null,
      last_core_update_attempt_at: null,
      is_minor_update: true,
      is_auto_update_enabled: false,
    };
    wrap(1);

    await waitFor(() => expect(screen.getByText(/7\.0\.1/)).toBeInTheDocument());
    expect(screen.getByRole('button', { name: /Update to 7\.0\.1/i })).toBeInTheDocument();
  });

  it('updating state renders full-width amber + spinner + duration copy', async () => {
    mockSiteCoreState[1] = {
      core_update_available: true,
      core_update_version: '7.0.1',
      core_update_state: 'updating',
      last_core_update_error: null,
      last_core_update_attempt_at: '2026-06-07 09:00:00',
    };
    wrap(1);

    await waitFor(() => expect(screen.getByText(/Upgrading/i)).toBeInTheDocument());
    expect(screen.getByText(/30.+90 seconds/i)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Update to/ })).not.toBeInTheDocument();
  });

  it('failed state renders red banner + Retry button + tooltip on hover', async () => {
    const user = userEvent.setup();
    mockSiteCoreState[1] = {
      core_update_available: true,
      core_update_version: '7.0.1',
      core_update_state: 'failed',
      last_core_update_error: 'Disk full at /tmp during package extract',
      last_core_update_attempt_at: '2026-06-07 09:00:00',
    };
    wrap(1);

    await waitFor(() => expect(screen.getByText(/Last update attempt failed/i)).toBeInTheDocument());
    expect(screen.getByRole('button', { name: /Retry update/i })).toBeInTheDocument();

    const warningIcon = screen.getByLabelText(/update failed/i);
    await user.hover(warningIcon);
    expect(await screen.findByText(/Disk full at \/tmp/i)).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run the test, verify it fails**

```
cd apps/web && pnpm test -- --run tests/components/sites/SiteCoreCard.test.tsx
```

Expected: FAIL — component doesn't exist.

- [ ] **Step 3: Implement `SiteCoreCard`**

```tsx
// apps/web/src/components/sites/SiteCoreCard.tsx
import { useState } from 'react';
import { AlertCircle, Loader2, RefreshCw } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip';
import { ConfirmUpdateCoreDialog } from '@/components/sites/ConfirmUpdateCoreDialog';
import { useRefreshSiteCore } from '@/lib/mutations/useRefreshSiteCore';
import { useSite } from '@/lib/queries/useSite';
import { useUpdateSiteCore } from '@/lib/mutations/useUpdateSiteCore';

const MAX_ERROR_LENGTH = 200;

interface SiteCoreCardProps {
  siteId: number;
}

export function SiteCoreCard({ siteId }: SiteCoreCardProps) {
  const [confirmOpen, setConfirmOpen] = useState(false);
  const { data: site } = useSite(siteId);
  const { refresh, isPending: refreshPending } = useRefreshSiteCore(siteId);
  const { update, isPolling } = useUpdateSiteCore(siteId);

  if (!site) {
    return null;
  }

  const state = site.core_update_state;
  const updating = state === 'queued' || state === 'updating' || isPolling;
  const failed = state === 'failed';
  const showUpdateButton = site.core_update_available && !updating;

  if (updating) {
    return (
      <Card className="border-amber-200 bg-amber-50">
        <CardContent className="flex items-center gap-3 p-4 text-amber-900">
          <Loader2 className="h-5 w-5 animate-spin" aria-hidden="true" />
          <div className="text-sm">
            <p className="font-semibold">
              Upgrading WordPress {site.wp_version} -> {site.core_update_version ?? ''}
            </p>
            <p>Approximately 30-90 seconds. Site may briefly show a maintenance message.</p>
          </div>
        </CardContent>
      </Card>
    );
  }

  const renderFailedBanner = () => {
    if (!failed) return null;
    const rawError = site.last_core_update_error ?? '';
    const truncated =
      rawError.length > MAX_ERROR_LENGTH
        ? `${rawError.slice(0, MAX_ERROR_LENGTH)}...`
        : rawError || 'Update failed.';
    return (
      <div className="mb-3 rounded border-l-2 border-red-500 bg-red-50 p-2 text-sm text-red-900">
        <span className="font-semibold">Last update attempt failed: </span>
        {truncated}
        <TooltipProvider>
          <Tooltip>
            <TooltipTrigger asChild>
              <span
                aria-label="Update failed details"
                className="ml-1 inline-flex cursor-help text-red-600"
              >
                <AlertCircle className="h-4 w-4" />
              </span>
            </TooltipTrigger>
            <TooltipContent>{truncated}</TooltipContent>
          </Tooltip>
        </TooltipProvider>
      </div>
    );
  };

  return (
    <>
      <Card>
        <CardContent className="space-y-3 p-4">
          {renderFailedBanner()}

          <div className="flex items-center justify-between">
            <div>
              <p className="font-semibold">WordPress {site.wp_version ?? '—'}</p>
              <p className="text-xs text-zinc-500">
                PHP {site.php_version ?? '—'}
                {site.is_auto_update_enabled === true ? ' · Auto-updates ON' : ''}
              </p>
            </div>
            <div className="flex items-center gap-2">
              <Button
                variant="outline"
                size="sm"
                onClick={() => refresh()}
                disabled={refreshPending}
                aria-label="Refresh WordPress core"
              >
                <RefreshCw className={refreshPending ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
              </Button>
              {showUpdateButton && (
                <Button
                  size="sm"
                  onClick={() => setConfirmOpen(true)}
                  className="bg-amber-600 hover:bg-amber-700"
                >
                  {failed ? 'Retry update' : `Update to ${site.core_update_version ?? ''}`}
                </Button>
              )}
            </div>
          </div>

          {site.core_update_available && (
            <p className="text-sm text-zinc-700">
              Update available: <span className="font-medium">{site.core_update_version}</span>
              {' '}(security & maintenance)
            </p>
          )}
        </CardContent>
      </Card>

      <ConfirmUpdateCoreDialog
        site={site}
        open={confirmOpen}
        onOpenChange={setConfirmOpen}
        onConfirm={() => {
          setConfirmOpen(false);
          update();
        }}
      />
    </>
  );
}
```

- [ ] **Step 4: Write the failing `ConfirmUpdateCoreDialog` test**

```tsx
// apps/web/tests/components/sites/ConfirmUpdateCoreDialog.test.tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ConfirmUpdateCoreDialog } from '@/components/sites/ConfirmUpdateCoreDialog';
import type { Site } from '@/types/api';

const baseSite = {
  id: 1,
  url: 'https://smartcoding.test',
  label: 'Smart',
  status: 'active',
  last_contact_at: null,
  last_sync_at: null,
  last_error: null,
  created_at: '2026-06-07 00:00:00',
  wp_version: '7.0',
  php_version: '8.3.31',
  active_theme: null,
  plugin_counts: null,
  theme_counts: null,
  ssl_status: null,
  ssl_expires_at: null,
  core_update_available: true,
  core_update_version: '7.0.1',
  core_update_state: 'idle' as const,
  last_core_update_error: null,
  last_core_update_attempt_at: null,
  is_minor_update: true,
  is_auto_update_enabled: false,
} satisfies Site;

describe('ConfirmUpdateCoreDialog', () => {
  it('renders title with version diff', () => {
    render(
      <ConfirmUpdateCoreDialog
        site={baseSite}
        open
        onOpenChange={() => {}}
        onConfirm={() => {}}
      />,
    );
    expect(screen.getByText(/Update WordPress 7\.0\s*->\s*7\.0\.1/i)).toBeInTheDocument();
  });

  it('renders BOTH warning banners (downtime + downgrade)', () => {
    render(
      <ConfirmUpdateCoreDialog
        site={baseSite}
        open
        onOpenChange={() => {}}
        onConfirm={() => {}}
      />,
    );
    expect(screen.getByText(/Site goes briefly offline/i)).toBeInTheDocument();
    expect(screen.getByText(/Downgrades require SFTP/i)).toBeInTheDocument();
  });

  it('renders Auto-updates ON paragraph when is_auto_update_enabled === true', () => {
    render(
      <ConfirmUpdateCoreDialog
        site={{ ...baseSite, is_auto_update_enabled: true }}
        open
        onOpenChange={() => {}}
        onConfirm={() => {}}
      />,
    );
    expect(screen.getByText(/install this update automatically/i)).toBeInTheDocument();
  });

  it('OMITS Auto-updates ON paragraph when is_auto_update_enabled !== true', () => {
    render(
      <ConfirmUpdateCoreDialog
        site={{ ...baseSite, is_auto_update_enabled: false }}
        open
        onOpenChange={() => {}}
        onConfirm={() => {}}
      />,
    );
    expect(screen.queryByText(/install this update automatically/i)).not.toBeInTheDocument();
  });

  it('renders amber primary button with the exact label', () => {
    render(
      <ConfirmUpdateCoreDialog
        site={baseSite}
        open
        onOpenChange={() => {}}
        onConfirm={() => {}}
      />,
    );
    const btn = screen.getByRole('button', { name: /^Yes, update WordPress core$/ });
    expect(btn).toBeInTheDocument();
    expect(btn.className).toMatch(/bg-amber-600/);
    expect(btn.className).toMatch(/hover:bg-amber-700/);
  });

  it('Cancel has the default focus', () => {
    render(
      <ConfirmUpdateCoreDialog
        site={baseSite}
        open
        onOpenChange={() => {}}
        onConfirm={() => {}}
      />,
    );
    expect(screen.getByRole('button', { name: /^Cancel$/ })).toHaveFocus();
  });

  it('calls onConfirm when amber button clicked', async () => {
    const user = userEvent.setup();
    let confirmed = false;
    render(
      <ConfirmUpdateCoreDialog
        site={baseSite}
        open
        onOpenChange={() => {}}
        onConfirm={() => { confirmed = true; }}
      />,
    );
    await user.click(screen.getByRole('button', { name: /Yes, update WordPress core/ }));
    expect(confirmed).toBe(true);
  });

  it('calls onOpenChange(false) when Cancel clicked', async () => {
    const user = userEvent.setup();
    let opened = true;
    render(
      <ConfirmUpdateCoreDialog
        site={baseSite}
        open
        onOpenChange={(o) => { opened = o; }}
        onConfirm={() => {}}
      />,
    );
    await user.click(screen.getByRole('button', { name: /Cancel/ }));
    expect(opened).toBe(false);
  });
});
```

- [ ] **Step 5: Run the test, verify it fails**

```
cd apps/web && pnpm test -- --run tests/components/sites/ConfirmUpdateCoreDialog.test.tsx
```

Expected: FAIL — component doesn't exist.

- [ ] **Step 6: Implement `ConfirmUpdateCoreDialog`**

```tsx
// apps/web/src/components/sites/ConfirmUpdateCoreDialog.tsx
import { useEffect, useRef } from 'react';
import { AlertTriangle } from 'lucide-react';
import { Button } from '@/components/ui/button';
import type { Site } from '@/types/api';

interface ConfirmUpdateCoreDialogProps {
  site: Site;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onConfirm: () => void;
}

/**
 * Confirmation modal for kicking off a WordPress core update.
 *
 * Stronger than P2.3's active-theme variant because:
 *   - Downtime is guaranteed (every core upgrade enters maintenance mode)
 *   - Irreversibility is total (no in-WP rollback path)
 *   - File system changes are broader
 *
 * Renders TWO warning banners (downtime + downgrade-irreversibility) and
 * a conditional "Auto-updates ON" paragraph that only shows when
 * is_auto_update_enabled === true. Primary button is amber (matches the
 * P2.3 active-theme severity tier) with the label
 * "Yes, update WordPress core" (explicit + harder to fat-finger).
 * Cancel is the default focus.
 *
 * Spec § 6.4.
 */
export function ConfirmUpdateCoreDialog({
  site,
  open,
  onOpenChange,
  onConfirm,
}: ConfirmUpdateCoreDialogProps) {
  const cancelRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (open) {
      cancelRef.current?.focus();
    }
  }, [open]);

  if (!open) {
    return null;
  }

  const titleId = `core-update-confirm-${site.id}`;
  const isAutoUpdateEnabled = site.is_auto_update_enabled === true;

  return (
    <div
      role="alertdialog"
      aria-modal="true"
      aria-labelledby={titleId}
      className="mt-3 rounded-md border border-zinc-200 bg-white p-4 shadow-sm"
    >
      <h3 id={titleId} className="text-sm font-semibold text-zinc-900">
        Update WordPress {site.wp_version} {'->'} {site.core_update_version}?
      </h3>

      {/* Warning banner 1 — downtime */}
      <div className="mt-3 space-y-2 rounded border-l-2 border-amber-500 bg-amber-50 p-3 text-sm text-amber-900">
        <p className="flex items-start gap-2 font-semibold">
          <AlertTriangle className="mt-0.5 h-4 w-4 flex-shrink-0" aria-hidden="true" />
          Site goes briefly offline during the upgrade
        </p>
        <p>
          The frontend serves a "Briefly unavailable for scheduled maintenance"
          message for 30-90 seconds. Logged-in users see wp-admin become
          unavailable.
        </p>
      </div>

      {/* Warning banner 2 — downgrade irreversibility */}
      <div className="mt-3 space-y-2 rounded border-l-2 border-amber-500 bg-amber-50 p-3 text-sm text-amber-900">
        <p className="flex items-start gap-2 font-semibold">
          <AlertTriangle className="mt-0.5 h-4 w-4 flex-shrink-0" aria-hidden="true" />
          Downgrades require SFTP
        </p>
        <p>
          If {site.core_update_version} introduces an incompatibility, restoring
          {' '}{site.wp_version} means uploading WP core files manually. There is
          no in-WordPress rollback. Make sure recent backups exist before
          continuing.
        </p>
      </div>

      {/* Conditional auto-update paragraph */}
      {isAutoUpdateEnabled && (
        <p className="mt-3 text-sm text-zinc-700">
          <span className="font-semibold">Auto-updates ON:</span> WordPress will
          install this update automatically within ~24 hours regardless. Updating
          now just does it sooner.
        </p>
      )}

      <div className="mt-3 flex justify-end gap-2">
        <Button
          ref={cancelRef}
          variant="outline"
          onClick={() => onOpenChange(false)}
        >
          Cancel
        </Button>
        <Button
          onClick={onConfirm}
          className="bg-amber-600 hover:bg-amber-700"
        >
          Yes, update WordPress core
        </Button>
      </div>
    </div>
  );
}
```

- [ ] **Step 7: Write the failing `SiteDetail.core.test` placement test**

```tsx
// apps/web/tests/routes/SiteDetail.core.test.tsx
import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import SiteDetail from '@/routes/SiteDetail';
import { mockSiteCoreState, resetMockSiteCoreState } from '@/test/handlers';
import { setAccessToken } from '@/lib/apiClient';

function renderRoute(siteId: number) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={[`/sites/${siteId}`]}>
        <Routes>
          <Route path="/sites/:id" element={<SiteDetail />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('SiteDetail — core card integration', () => {
  beforeEach(() => {
    resetMockSiteCoreState();
    setAccessToken('fake');
    mockSiteCoreState[1] = {
      core_update_available: false,
      core_update_version: null,
      core_update_state: 'idle',
      last_core_update_error: null,
      last_core_update_attempt_at: null,
    };
  });

  it('renders SiteCoreCard ABOVE SiteSummaryCard in DOM order', async () => {
    renderRoute(1);

    // Wait for the page to render at all.
    await waitFor(() => {
      expect(screen.getByText(/WordPress/i)).toBeInTheDocument();
    });

    // SiteCoreCard contains "WordPress" text; SiteSummaryCard renders the
    // existing site-summary heading (e.g. "Site summary" or the SSL/PHP
    // section). We assert the core text appears BEFORE the summary text.
    const coreEl = screen.getByText(/WordPress/i);

    // Find a stable summary-card landmark. The existing SiteSummaryCard
    // renders "Plugins" sub-heading or "Themes" sub-heading from the
    // F8/P2.1/P2.3 panels below it; either works as the summary anchor.
    const pluginsHeader = await screen.findByText(/^Plugins$/i);

    expect(coreEl.compareDocumentPosition(pluginsHeader) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });
});
```

- [ ] **Step 8: Run the placement test, verify it fails**

```
cd apps/web && pnpm test -- --run tests/routes/SiteDetail.core.test.tsx
```

Expected: FAIL — `SiteCoreCard` not rendered.

- [ ] **Step 9: Modify `apps/web/src/routes/SiteDetail.tsx`**

Add the import near the existing component imports:

```tsx
import { SiteCoreCard } from '@/components/sites/SiteCoreCard';
```

Render `<SiteCoreCard />` between `<SiteHeader />` (or the equivalent top-of-page summary block) and `<SiteSummaryCard />`. Look for the existing JSX block where `SiteSummaryCard` is rendered (or where `SiteRuntimeInfo` / `SitePluginsPanel` sits) and insert directly before it, gated on `data.status !== 'pending'`:

```tsx
{data.status !== 'pending' && <SiteCoreCard siteId={siteId} />}
```

If `SiteDetail.tsx` does not yet have a `SiteSummaryCard` block (the file's structure may differ from the spec sketch), insert `<SiteCoreCard />` at the top of the per-site content block, BEFORE the existing F8 runtime info row, gated on `status !== 'pending'`.

- [ ] **Step 10: Run all three SPA tests, verify they pass**

```
cd apps/web && pnpm test -- --run tests/components/sites/SiteCoreCard.test.tsx tests/components/sites/ConfirmUpdateCoreDialog.test.tsx tests/routes/SiteDetail.core.test.tsx
```

Expected: PASS (4 + 8 + 1).

- [ ] **Step 11: Commit**

```bash
git add apps/web/src/components/sites/SiteCoreCard.tsx \
        apps/web/src/components/sites/ConfirmUpdateCoreDialog.tsx \
        apps/web/src/routes/SiteDetail.tsx \
        apps/web/tests/components/sites/SiteCoreCard.test.tsx \
        apps/web/tests/components/sites/ConfirmUpdateCoreDialog.test.tsx \
        apps/web/tests/routes/SiteDetail.core.test.tsx
git commit -m "feat(p2-4): SiteCoreCard 4 states + ConfirmUpdateCoreDialog + SiteDetail integration"
```

---

## Task 22 — Build zips + 13-step manual smoke matrix

**Files:** none (build + smoke playbook).

Run the full smoke matrix from spec § 8.2 (13 steps). Do NOT push the tag if any step fails.

- [ ] **Step 1: Run all suites — confirm baseline green**

```
cd packages/connector-plugin && composer test
cd packages/dashboard-plugin && composer test
cd apps/web && pnpm test -- --run
cd apps/web && pnpm lint
```

Expected: ALL PASS.

- [ ] **Step 2: Build connector zip (v0.1.6) — apply P2.3 zip lessons**

```bash
cd packages/connector-plugin
composer dump-autoload --no-dev --classmap-authoritative
zip -r ~/Desktop/defyn-connector-v0.1.6-$(date +%Y-%m-%d).zip . \
  -x "tests/*" "node_modules/*" "*.git*" "vendor/wordpress/*" "vendor/johnpbloch/*"
composer install
```

Target zip size: ~65KB. If the result is dramatically larger (5MB+), the `vendor/wordpress/*` exclusion didn't take — verify the `-x` patterns.

- [ ] **Step 3: Build dashboard zip (v0.5.0) — apply P2.3 zip lessons**

```bash
cd packages/dashboard-plugin
composer dump-autoload --no-dev --classmap-authoritative
zip -r ~/Desktop/defyn-dashboard-v0.5.0-$(date +%Y-%m-%d).zip . \
  -x "tests/*" "node_modules/*" "*.git*" "vendor/wordpress/*" "vendor/johnpbloch/*"
composer install
```

Target zip size: ~550KB.

- [ ] **Step 4: Install on production**

1. Upload the connector zip to `smartcoding.com.au` via Plugins -> Add New -> Upload, replace current with uploaded.
2. Upload the dashboard zip to `defynwp.defyn.agency` via Plugins -> Add New -> Upload, replace current with uploaded.
3. **Schema self-heal note (P2.2.1):** the dashboard auto-runs the v4 -> v5 migration on first `plugins_loaded` after upgrade. **No manual deact/react required.** Verify by:
   - Visiting any dashboard admin page (triggers `plugins_loaded`).
   - `wp option get defyn_dashboard_schema_version` returns `5`.
   - `wp db query "SHOW COLUMNS FROM wp_defyn_sites LIKE 'core_update_%'"` lists 4 rows + the 5th column `last_core_update_attempt_at`.
   - `wp db query "SHOW INDEX FROM wp_defyn_sites WHERE Key_name='idx_core_update_available'"` returns 1 row.
4. SmartCoding handshake is still in place from prior smoke runs.

- [ ] **Step 5: Run the 13-step smoke matrix from spec § 8.2 verbatim**

Document each step's outcome inline (PASS/FAIL). If any step fails, STOP — file `fix(p2-4):` commits before tagging.

```bash
# Set TOKEN once for the curl steps
TOKEN=$(curl -s -X POST https://defynwp.defyn.agency/wp-json/defyn/v1/auth/login \
  -H "Content-Type: application/json" \
  --data '{"email":"pradeep@defyn.com.au","password":"DefynWP-ifirCh5pXm5bTOj0"}' \
  | python3 -c "import sys,json; print(json.load(sys.stdin)['access_token'])")
SITE_ID=1
```

| # | Action | Expected |
|---|---|---|
| 1 | `curl -H "Authorization: Bearer $TOKEN" "https://defynwp.defyn.agency/wp-json/defyn/v1/sites/$SITE_ID"` | Response includes 5 new core fields (`core_update_available=0` if no update, `core_update_state='idle'`, etc.) |
| 2 | `curl -X POST -H "Authorization: Bearer $TOKEN" "https://defynwp.defyn.agency/wp-json/defyn/v1/sites/$SITE_ID/core/refresh"` | `202 {"scheduled":true,"site_id":1}`; AS job fires within ~60s |
| 3 | After job: repeat step 1 | `core_update_available` reflects production reality (and `core_update_version` / minor-flag if a real update is available) |
| 4 | `curl GET /defyn-connector/v1/status` directly (signed) on smartcoding | Response includes `core: {update_available, update_version, is_minor_update, is_auto_update_enabled}` |
| 5 | If no real update is available: SSH to smartcoding, `wp core update --version=<prior>`, then trigger refresh again | Update appears as available in next sync |
| 6 | `curl -X POST -H "Authorization: Bearer $TOKEN" "https://defynwp.defyn.agency/wp-json/defyn/v1/sites/$SITE_ID/core/update"` | `202 {"scheduled":true,"site_id":1,"core_update_state":"queued"}`; AS job runs; row transitions `queued -> updating -> idle` with new `wp_version` |
| 7 | During #6: load `https://smartcoding.com.au` in browser | Brief `.maintenance` 503 page (< 60s), then resumes normally |
| 8 | `curl -H "Authorization: Bearer $TOKEN" "https://defynwp.defyn.agency/wp-json/defyn/v1/sites/$SITE_ID/activity?per_page=20"` | `core_update.requested -> core_update.started -> core_update.succeeded` triplet, in that order |
| 9 | 4x `POST /core/update` in <1hr (with manufactured updates to dodge the preflight) | 4th call returns `429 core.rate_limited` — **NOT** the 7th call (this is the 3/hour bucket, not 6/hour) |
| 10 | Concurrent: queue a plugin update first, then fire core update | Core update returns `409 connector.upgrade_in_progress` (or `409 core.update_in_progress` from dashboard preflight) |
| 11 | Manually `UPDATE wp_defyn_sites SET core_update_state='failed', last_core_update_error='Old error', core_update_available=0 WHERE id=$SITE_ID`, then `POST /core/refresh` | After sync: row state heals to `idle`, error cleared (day-1 single-row heal logic in `markSynced`) |
| 12 | SPA at `app.defynwp.defyn.agency/sites/1` -> scroll to `SiteCoreCard` | Card matches API state; clicking "Update to X.Y.Z" opens amber dialog with BOTH warning banners + conditional "Auto-updates ON" paragraph; amber button labelled "Yes, update WordPress core"; Cancel has default focus |
| 13 | Inject synthetic major bump: temporarily `UPDATE wp_defyn_sites SET core_update_version='8.0' WHERE id=$SITE_ID`, then `POST /core/update` | `409 core.major_update_blocked` immediately (dashboard-side fast-fail, no AS roundtrip — confirm via no `core_update.requested` activity event) |

- [ ] **Step 6: Document smoke results**

Record PASS/FAIL inline for each step. Do NOT proceed to Task 23 unless all 13 are green. File `fix(p2-4):` commits for any failures, then re-run from the failing step.

- [ ] **Step 7: Commit (only if any fix commits were needed)**

If smoke uncovered issues, commit each fix individually with `fix(p2-4): …` messages. If smoke was green on the first run, this task creates no commits.

---

## Task 23 — Tag + push

**Files:** none (git tag).

ONLY run this task after Task 22's smoke matrix is fully green. If any step failed, file fix commits + re-run smoke before tagging. **NEVER push the tag if any smoke step failed.**

- [ ] **Step 1: Verify all suites green + working tree clean**

```bash
cd packages/connector-plugin && composer test
cd packages/dashboard-plugin && composer test
cd apps/web && pnpm test -- --run
cd /Users/pradeep/Local\ Sites/defynWP
git status
```

Expected: ALL tests green; working tree clean.

- [ ] **Step 2: Verify the manual smoke matrix from Task 22 was fully green**

If ANY step failed and was not fixed + re-smoked, STOP. Do not push the tag.

- [ ] **Step 3: Tag + push**

```bash
cd /Users/pradeep/Local\ Sites/defynWP
git tag -a "p2-4-core-updates-complete" -m "$(cat <<'EOF'
P2.4 — WP core updates (minor only) shipped

Operator can update WordPress core (minor versions) on managed sites
from DefynWP. Major bumps blocked at both layers with
core.major_update_blocked — deferred to P2.4.1.

Verified end-to-end against production 2026-06-07:
- Connector v0.1.6 on smartcoding.com.au
- Dashboard v0.5.0 on defynwp.defyn.agency
- SPA bundle deployed via Cloudflare Pages
- SiteCoreCard 4 visual states -> amber ConfirmUpdateCoreDialog
  -> AS job -> connector Core_Upgrader -> row updates with new wp_version
- Activity log records requested -> started -> succeeded triplet
- Shared defyn_connector_upgrade_in_flight transient lock now covers
  3 x 3 = 9 plugin/theme/core resource collisions on the same install
- 3/hour coreUpdate bucket strictly tighter than themes/plugins (6/hour)
- 6/hour sitesCoreRefresh bucket separate from plugins + themes refresh
- Schema v4 -> v5 ships with day-1 single-row heal in markSynced + 5
  new columns + idx_core_update_available index
- 300s timeout on UpdateSiteCore::TIMEOUT_SECONDS (vs themes' 120s)
- 409 success-by-other-means uses $wpVersionBeforeAttempt snapshot
- 409 major-block immediate fail, no retry, dashboard preflight catches first
- Auto-runs via plugins_loaded self-heal — no manual deact/react required

Tests: ~80 new tests across connector + dashboard + SPA, all green.
EOF
)"
git push origin "p2-4-core-updates-complete"
```

- [ ] **Step 4: Final status check**

```bash
git status
git log --oneline -15
```

Expected: working tree clean; tag visible in `git log --tags`.

---

## Self-review checklist

After implementing all 23 tasks, run this checklist before declaring P2.4 complete:

- [ ] All test suites green: connector + dashboard + SPA + lint
- [ ] No `console.log` / `var_dump` / `print_r` in source code (`rg "console.log|var_dump|print_r" packages/ apps/web/src/`)
- [ ] All new error codes use the `{error:{code,message}}` envelope shape (spec § 3.5 + § 5.3)
- [ ] Activity log details for `core_update.*` events match the spec § 4.3 shape
- [ ] No placeholder strings ("TODO", "FIXME", "XXX") in source
- [ ] Each commit is atomic (one task = one commit, except Task 22 which may have fix commits)
- [ ] **`UpdateSiteCore::TIMEOUT_SECONDS === 300`** — explicitly asserted in the job test
- [ ] **`RateLimit::CORE_UPDATE_LIMIT === 3`** — test method `testRateLimit429AfterFourthCall` passes (4th call 429s)
- [ ] **Day-1 single-row heal in `markSynced`** — tests `testMarkSyncedHealsStuckFailedWhenIncomingHasNoUpdateAvailable` + `testMarkSyncedDoesNotHealWhenUpdateStillAvailable` both pass
- [ ] **Connector `ob_start/ob_end_clean` in `finally`** — regression test `testStdoutFromUpgraderDoesNotCorruptResponse` passes
- [ ] **`CoreUpdateLockTest` cross-resource scenarios** — all 4 (plugin↔core, theme↔core, core↔plugin, core↔theme) plus cleanup-on-exception pass
- [ ] **`UpdateSiteCore` 4 branches** — success, 409 success-by-other-means (uses `$wpVersionBeforeAttempt`), 409 major-block (no retry), 409 lock-collision retry (60/120/240/480/960s), 502 / transport (no retry)
- [ ] **`SyncSite::handle` schedules all 3 refresh hooks** per recurring tick — `testSyncSiteAlsoSchedulesCoreRefresh` passes
- [ ] **`Plugin::boot` registers both new AS hooks** — `testRefreshSiteCoreHookRegistered` + `testUpdateSiteCoreHookRegistered` pass
- [ ] **SPA SiteCoreCard placement** — `SiteDetail.core.test.tsx` asserts DOM order via `compareDocumentPosition`
- [ ] **SPA `ConfirmUpdateCoreDialog`** — TWO warning banners + conditional Auto-updates copy + amber button + "Yes, update WordPress core" label + Cancel focused default — all asserted in the dialog test
- [ ] Manual smoke matrix (§ 8.2) documented PASS for all 13 steps
- [ ] Tag pushed: `p2-4-core-updates-complete`

---

## Spec-coverage matrix (sanity check)

| Spec section | Covered by task(s) |
|---|---|
| § 1 Architecture overview | All tasks |
| § 2.1 Five new columns on wp_defyn_sites | Task 8 |
| § 2.2 Column semantics | Task 8 (DDL); Task 9 (model mapping); Task 10 (writes) |
| § 2.3 Migration mechanics + idempotent guards | Task 8 |
| § 2.4 No major-update column | Task 16 (preflight) — version-strings only, no persisted flag |
| § 3.1 Extended GET /status | Task 1 (Collector) + Task 6 (integration) |
| § 3.2 POST /core/refresh | Task 3 |
| § 3.3 POST /core/update | Tasks 2, 4 |
| § 3.4 Shared transient lock (3 resources) | Tasks 4, 5 |
| § 3.5 New connector error codes | Tasks 3, 4 |
| § 4.1 SitesRepository extensions + day-1 heal | Task 10 |
| § 4.2 SyncService extension (passthrough) | Task 11 |
| § 4.3 AS jobs (RefreshSiteCore, UpdateSiteCore 5-branch) | Tasks 12, 13, 14 |
| § 4.4 Wire AS hooks + extend SyncSite | Task 17 |
| § 5.1 POST /sites/{id}/core/refresh | Task 15 |
| § 5.2 POST /sites/{id}/core/update — 4 preflights + 3/hr | Task 16 |
| § 5.3 New dashboard error envelope codes | Tasks 15, 16 |
| § 5.4 RestRouter registration | Tasks 15, 16 |
| § 5.5 CORS | Task 18 |
| § 6.1 Component tree | Task 21 |
| § 6.2 New files | Tasks 19, 20, 21 |
| § 6.3 SiteCoreCard 4 visual states | Task 21 |
| § 6.4 ConfirmUpdateCoreDialog | Task 21 |
| § 6.5 useRefreshSiteCore + useUpdateSiteCore polling pin | Task 20 |
| § 6.6 Extended useSite (siteSchema extension) | Task 19 |
| § 6.7 Reuses (Tooltip, Card, useToast, Lucide icons) | Tasks 20, 21 |
| § 7 Testing strategy | All tasks |
| § 8 Manual smoke + tag | Tasks 22, 23 |
| § 9 Out of scope | — (no code) |
| § 10 Implementation notes (day-1 carry-overs) | Tasks 4 (STDOUT), 5 (lock), 10 (heal), 13 (timeout), 14 (retry/no-retry), 16 (3/hr) |

Coverage: complete.
