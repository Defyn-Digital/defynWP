# P2.7 — Bulk plugin updates across fleet Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a "Bulk update plugins (N)" button on the Operator Overview at `/overview` that lets the operator review + selectively uncheck (site, plugin) pairs in a destructive-tier confirmation dialog, then fan-out the existing P2.2 `defyn_update_site_plugin` AS job per confirmed pair. Two new REST endpoints, dashboard v0.8.0, no connector changes, no schema changes.

**Architecture:** ONE new `GET /defyn/v1/overview/pending-plugin-updates` (30/MIN per user) returns the flat list for the dialog. ONE new `POST /defyn/v1/overview/bulk-update-plugins` (5/HR per user) validates each pair, fan-outs `as_schedule_single_action('defyn_update_site_plugin', [siteId, slug, 0], 'defyn')` per valid pair, emits ONE fleet-scoped `overview.bulk_plugin_update_requested` activity event (site_id=null), and returns 202 (or 200 if all skipped) with `{scheduled_count, skipped_count, scheduled_pairs, skipped_pairs[{site_id,slug,reason}], scheduled_at}`. SPA gets a RED-tier confirm dialog with per-site collapsible groups, all-pre-checked checkboxes, useMemo-driven footer counter, and `enabled-on-open` query hook for the pairs list.

**Tech Stack:** PHP 8.1+ (PHPUnit, `WP_UnitTestCase` / `AbstractSchemaTestCase`), WordPress REST API, Action Scheduler, React 18 + TypeScript + TanStack Query v5 + Zod + Tailwind + shadcn/ui (`Button` variants `default`/`outline`/`ghost` — NO `destructive` variant, see plan-bug trap #9 below) + Vitest + React Testing Library + MSW.

**Spec:** [`docs/superpowers/specs/2026-06-09-p2-7-bulk-plugin-updates-design.md`](../specs/2026-06-09-p2-7-bulk-plugin-updates-design.md)

---

## Workflow conventions

- **Branch:** already on **`p2-7-bulk-plugin-updates`** (current tip `c621253` — the just-committed P2.7 spec). Confirm with `git branch --show-current` before starting. Branch was created off `main` (== `1379f24`).
- **Each Task = one atomic commit.**
- **Test discipline (TDD):** Step 1 writes the failing test. Step 2 runs it and confirms it fails. Step 3 writes the implementation. Step 4 confirms it passes. Step 5 commits.
- **Test runners:**
  - Dashboard PHP: `cd packages/dashboard-plugin && composer test`
  - SPA: `cd apps/web && pnpm test -- --run`
- **Commit message format:** `<type>(p2-7): <description>` where `<type>` ∈ {feat, fix, refactor, docs, test, chore}.
- **All edits adhere to** `~/.claude/rules/common/coding-style.md` — immutability, KISS, DRY, YAGNI, error handling, no `console.log` / `var_dump` / `print_r`.
- **No connector changes.** Connector stays at **v0.1.7**. Smoke does NOT require connector reinstall.
- **No schema changes.** Schema stays at **v6**.

### Plan-bug traps to internalise before writing any code

1. **`RateLimit::BULK_PLUGIN_UPDATE_LIMIT = 5`** per **HOUR**. Window `HOUR_IN_SECONDS`. Test method name MUST be `testRateLimit429AfterSixthCall` (NOT Seventh, NOT Eleventh, NOT ThirtyFirst — common copy-paste traps from P2.6/P2.5).
2. **`RateLimit::OVERVIEW_PENDING_PLUGIN_UPDATES_LIMIT = 30`** per **MINUTE**. Window `MINUTE_IN_SECONDS` (NOT HOUR — that's the bulk endpoint). Test method `testRateLimit429AfterThirtyFirstCall` (mirrors P2.5's `overview` endpoint).
3. **Activity event name MUST be EXACTLY** `overview.bulk_plugin_update_requested` — singular `update`, not plural. Not `overview.bulk_plugin_updates_requested`, not `plugin.bulk_update_requested`, not `bulk_plugin_update.requested`. Both `testActivityEventEmittedWithCorrectDetails` + smoke step 7 grep for this exact string.
4. **`site_id = null` on the activity event** (fleet-scoped — mirror of P2.6 pattern). The `pairs[]` array goes inside the JSON `details` column. `ActivityLogger::log(?int $userId, ?int $siteId, string $eventType, ?array $details = null, ?string $ipAddress = null): void` — pass `null` as the second positional arg.
5. **Activity event MUST NOT fire when `scheduled_count === 0`** — guard with `if (count($scheduled) > 0)`. Test `testZeroValidPairsReturns200AndNoActivityEvent` asserts NO row was written to `wp_defyn_activity_log` even when 3 pairs were processed (all skipped via the three distinct skip reasons).
6. **Four distinct response shapes:**
   - 202 when `scheduled_count > 0` with the full envelope
   - 200 with same envelope shape when `scheduled_count === 0` (all skipped)
   - 400 `bulk.empty_updates` on empty body
   - 429 `bulk.rate_limited` over the bucket cap
7. **AS hook is `defyn_update_site_plugin`** (existing P2.2 `UpdateSitePlugin::HOOK`). Args `[$siteId, $slug, 0]` where `0` is `attempt`. The 4th group arg `'defyn'` per codebase convention (P2.6 spec-reviewer confirmed 15/18 existing call sites use it). Do NOT introduce a new hook name.
8. **Bulk endpoint BYPASSES the per-(user, site, slug) `pluginsUpdate` 6/HOUR bucket.** Operator's explicit dialog confirmation IS the safety. Do NOT add preflight bucket checks — the bulk endpoint's own 5/HOUR cap is the rate-limit boundary.
9. **CRITICAL — Button `destructive` variant DOES NOT EXIST in `apps/web/src/components/ui/button.tsx`.** It only has `default`, `outline`, `ghost`. The spec § 3.3 said `variant="destructive"` aspirationally — the ACTUAL codebase pattern (see P2.4.1's `ConfirmUpdateCoreDialog.tsx` line 228) is `<Button className="bg-red-600 hover:bg-red-700">`. Use that pattern. The test assertion `primaryButtonUsesDestructiveVariant` checks for `bg-red-600` in the className.
10. **Cancel button has default focus** — mirror P2.4 `ConfirmUpdateCoreDialog` lines 54-63 (`cancelRef = useRef<HTMLButtonElement>(null)` + `useEffect(() => { if (open) cancelRef.current?.focus() }, [open])`). Identical to P2.6's `ConfirmSyncAllDialog`.
11. **Mutation hook invalidates `['overview']` AND `['pendingPluginUpdates']` on success** — NOT `['sites']`. Per-site plugin states refresh naturally as each `UpdateSitePlugin` AS job executes. Same reasoning as P2.6 plan-bug trap #11.
12. **`usePendingPluginUpdates` is enabled-only-on-dialog-open** — set `enabled: dialogOpen` on the TanStack query. NOT polling. Otherwise we'd hit the 30/MIN bucket from the SPA's existing 60s `/overview` poll noise.
13. **Defensive `ob_start()` + `ob_end_clean()` in try/finally** in BOTH new controllers — P2.2 plan-bug #4 carry-forward.
14. **`RestRouter` registration** for the two new routes goes **immediately after** the existing `/overview/sync-all` POST registration (lines 225-229 in `RestRouter.php`) and **BEFORE** the `/activity` GET at line 231. Plan-bug trap from P2.6.
15. **Per-site group checkbox in dialog** toggles all child checkboxes. Footer counter "X selected of Y available" must update via React state, computed via `useMemo` (not derived on every render).
16. **Long lists collapse:** first 3 sites expanded, rest behind a "show all N sites ▾" disclosure. Test asserts `getAllByTestId('plugin-group').length` equals `min(3, totalSites)` when collapsed.
17. **Dashboard zip build:** `composer install --no-dev --classmap-authoritative` FIRST. Exclude `vendor/wordpress/*` + `vendor/johnpbloch/*` + `*wp-tests-config.php` + `*.phpunit.result.cache` (P2.6 carry-forward). Target ~552KB.
18. **MyKinsta cache clear** after install — every P2.x phase carry-forward.
19. **Final smoke matrix is § 5.2 of the spec verbatim — 8 steps.** Tag `p2-7-bulk-plugin-updates-complete` ONLY after all 8 pass. Prerequisite: prod must have SmartCoding (or some site with `update_available = 1` plugins) registered for `user_id=1`.

### Pre-existing carry-forward failures (TOLERATE — do NOT count as new regressions)

PHP (3, since P2.4.1):
- `SchemaVersionMigrationV4Test::testSchemaVersionConstantIsFour`
- `SchemaVersionMigrationV5Test::testSchemaVersionConstantIsFive`
- `UninstallTest::testUninstallDropsAllTables`

SPA (4, since P2.4.1):
- `tests/SiteDetail.test.tsx` × 2
- `tests/components/sites/SiteCoreCard.test.tsx > idle update-available renders version diff + Update button`
- `tests/components/sites/SiteCoreCard.test.tsx > failed state renders red banner + Retry button + tooltip on hover`

### Existing-code anchors (read these before starting any task)

- `packages/dashboard-plugin/src/Services/SitePluginsRepository.php` — most recent additions: `findAllForSite(int $siteId): array` at line 16, `findRowForSiteAndSlug(int $siteId, string $slug): ?array` at line 149, `healDanglingFailedStates(int $siteId, string $now): int` at line 265. Append new method after the existing ones.
- `packages/dashboard-plugin/src/Services/SitesRepository.php` — `findByIdForUser(int $id, int $userId): ?Site` at line 71. Used by the bulk controller for ownership checks.
- `packages/dashboard-plugin/src/Schema/SitePluginsTable.php` — columns include `name VARCHAR(191)`, `slug VARCHAR(...)`, `version`, `update_version VARCHAR(40)`, `update_available TINYINT(1)`. The GET SQL joins this with `defyn_sites`.
- `packages/dashboard-plugin/src/Rest/SitesPluginsUpdateController.php` — P2.2 reference controller. Uses `ErrorResponse::create(int $status, string $code, string $message)` at line 55, 61, 69, 77. The 4-step validation order in its `handle()` is the template for our bulk loop's per-pair validation.
- `packages/dashboard-plugin/src/Jobs/UpdateSitePlugin.php` — existing P2.2 AS job. `HOOK = 'defyn_update_site_plugin'` constant. Handler signature `handle(int $siteId, string $slug, int $attempt): void`.
- `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php` — most recent constants `OVERVIEW_SYNC_ALL_LIMIT = 10` + `OVERVIEW_SYNC_ALL_WINDOW = HOUR_IN_SECONDS` at lines 86-87. Most recent method `overviewSyncAll(...)` at line 401. Append our new constants AFTER the `OVERVIEW_SYNC_ALL_*` block; append our new methods AFTER `overviewSyncAll(...)`.
- `packages/dashboard-plugin/src/Rest/RestRouter.php` — `/overview/sync-all` POST at lines 225-229. `/activity` GET at line 231. New routes append BETWEEN those two.
- `apps/web/src/lib/queries/useOverview.ts` — TanStack hook with `queryKey: ['overview']`, polls every 60s.
- `apps/web/src/lib/mutations/useSyncAllSites.ts` — closest mutation hook pattern for `useBulkUpdatePlugins` (typed `useMutation<TData, Error, TArgs>` + Zod parse + `invalidateQueries` on success).
- `apps/web/src/components/sites/ConfirmUpdateCoreDialog.tsx` — lines 54-63 (cancelRef + useEffect) for default-focus pattern. Line 228 `className="bg-red-600 hover:bg-red-700"` for RED-tier button (plan-bug trap #9 — NOT `variant="destructive"`).
- `apps/web/src/components/overview/SyncAllSitesButton.tsx` (P2.6) — structural template for `BulkUpdatePluginsButton`: button → confirm dialog → mutation invocation, with conditional rendering and pending-state.
- `apps/web/src/components/overview/ConfirmSyncAllDialog.tsx` (P2.6) — structural template for `ConfirmBulkUpdatePluginsDialog`: cancelRef pattern, alertdialog role, action buttons row.
- `apps/web/src/routes/Overview.tsx` — header layout is `<div className="flex items-start justify-between">` with `<h1>Overview</h1>` left and a stacked `<div className="flex flex-col items-end gap-1">` column on the right containing the "Last refreshed" text + the existing `<SyncAllSitesButton />`. P2.7 adds `<BulkUpdatePluginsButton />` to that same column, BELOW `SyncAllSitesButton`.
- `apps/web/src/types/api.ts` — existing schemas include `overviewSchema` (with `total_sites` field from P2.6), `syncAllSitesResponseSchema`. Append `pendingPluginUpdatesSchema` + `bulkUpdatePluginsResponseSchema` here.
- `apps/web/src/test/handlers.ts` — MSW handlers array. Append new handlers for the two new endpoints near the existing `/overview/sync-all` POST handler.
- `apps/web/src/test/setup.ts` line 6 — exports `server = setupServer(...handlers)`. Import as `from '@/test/setup'` in tests (NOT `from '@/test/server'` — common P2.6 copy-paste trap).
- `apps/web/src/lib/apiClient.ts` lines 99-100 — `apiClient.get<T>(path)` and `apiClient.post<T>(path, body?)`. Body is optional.

---

## File structure overview

### Dashboard plugin (v0.8.0) — new files

| Path | Responsibility |
|---|---|
| `src/Rest/OverviewPendingPluginUpdatesController.php` | GET endpoint — flat list of eligible pairs |
| `src/Rest/OverviewBulkUpdatePluginsController.php` | POST endpoint — validate + fan-out + fleet activity event |
| `tests/Integration/Rest/OverviewPendingPluginUpdatesControllerTest.php` | 4 tests (auth, happy, rate limit, ownership) |
| `tests/Integration/Rest/OverviewBulkUpdatePluginsControllerTest.php` | 8 tests (auth, happy, empty body, rate limit, skip reasons, fan-out, activity event, zero-pairs no-log) |
| `tests/Integration/Services/SitePluginsRepositoryPendingUpdatesTest.php` | 2 tests (correct rows, cross-user isolation) |
| `tests/Integration/Rest/OverviewPendingPluginUpdatesCorsTest.php` | CORS preflight regression |
| `tests/Integration/Rest/OverviewBulkUpdatePluginsCorsTest.php` | CORS preflight regression |

### Dashboard plugin — modified files

| Path | What changes |
|---|---|
| `src/Services/SitePluginsRepository.php` | Add `findAllPendingUpdatesForUser(int $userId): array` |
| `src/Rest/Middleware/RateLimit.php` | Add `OVERVIEW_PENDING_PLUGIN_UPDATES_LIMIT/WINDOW` (30/MIN) + `BULK_PLUGIN_UPDATE_LIMIT/WINDOW` (5/HR) constants + 2 new permission methods |
| `src/Rest/RestRouter.php` | Register 2 new routes between `/overview/sync-all` and `/activity` |
| `defyn-dashboard.php` | Version `0.7.1` → `0.8.0` |
| `readme.txt` | Stable tag + changelog entry |
| `composer.json` | Version `0.7.1` → `0.8.0` |

### SPA (apps/web) — new files

| Path | Responsibility |
|---|---|
| `src/components/overview/BulkUpdatePluginsButton.tsx` | Button + dialog state + mutation invocation + spinner + success transition |
| `src/components/overview/ConfirmBulkUpdatePluginsDialog.tsx` | Modal — title, body, checkbox state, footer counter, RED primary button, Cancel default focus |
| `src/components/overview/PendingPluginUpdatesGroup.tsx` | Per-site collapsible group with grouped checkbox + child rows |
| `src/lib/queries/usePendingPluginUpdates.ts` | TanStack query — `enabled: dialogOpen`, NOT polling |
| `src/lib/mutations/useBulkUpdatePlugins.ts` | TanStack mutation — POSTs `/overview/bulk-update-plugins`, invalidates `['overview']` + `['pendingPluginUpdates']` |
| `tests/components/overview/BulkUpdatePluginsButton.test.tsx` | 4 tests (idle render, hidden when 0, opens dialog, pending label) |
| `tests/components/overview/ConfirmBulkUpdatePluginsDialog.test.tsx` | 4 tests (Cancel default focus, destructive variant, disabled at 0 selected, footer counter live) |
| `tests/lib/mutations/useBulkUpdatePlugins.test.tsx` | 2 tests (POST body shape, query invalidation) |

### SPA — modified files

| Path | What changes |
|---|---|
| `src/types/api.ts` | Append `pendingPluginUpdatesSchema` + `bulkUpdatePluginsResponseSchema` (+ types) |
| `src/test/handlers.ts` | Add 2 MSW handlers for new endpoints |
| `src/routes/Overview.tsx` | Render `<BulkUpdatePluginsButton pendingCount={data.pending_updates.plugins} />` below `SyncAllSitesButton` |

---

## Task 1 — `SitePluginsRepository::findAllPendingUpdatesForUser`

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/SitePluginsRepository.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/SitePluginsRepositoryPendingUpdatesTest.php` (CREATE)

### Step 1: Write the failing test

Create `packages/dashboard-plugin/tests/Integration/Services/SitePluginsRepositoryPendingUpdatesTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\SitePluginsRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SitePluginsRepositoryPendingUpdatesTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL
        $wpdb->query('SET autocommit = 1');
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_site_plugins");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_sites");
        // phpcs:enable WordPress.DB.PreparedSQL
        parent::setUp();
    }

    public function testFindAllPendingUpdatesForUserReturnsCorrectRowsAcrossSites(): void
    {
        $siteA = $this->seedSite(1, 'SmartCoding');
        $siteB = $this->seedSite(1, 'AcmeBlog');

        $this->seedPlugin($siteA, 'akismet', 'Akismet Anti-Spam', '5.3', '5.3.1', true);
        $this->seedPlugin($siteA, 'yoast',   'Yoast SEO',         '22.5', '22.6', true);
        $this->seedPlugin($siteA, 'wpml',    'WPML',              '4.7',  null,   false); // no update
        $this->seedPlugin($siteB, 'jetpack', 'Jetpack',           '13.1', '13.2', true);

        $rows = (new SitePluginsRepository())->findAllPendingUpdatesForUser(1);

        $this->assertCount(3, $rows);
        $slugs = array_map(static fn($r) => $r['slug'], $rows);
        $this->assertEqualsCanonicalizing(['akismet', 'yoast', 'jetpack'], $slugs);

        // Verify shape for akismet row.
        $akismet = null;
        foreach ($rows as $row) {
            if ($row['slug'] === 'akismet') {
                $akismet = $row;
                break;
            }
        }
        $this->assertNotNull($akismet);
        $this->assertSame($siteA, $akismet['site_id']);
        $this->assertSame('SmartCoding', $akismet['site_label']);
        $this->assertSame('Akismet Anti-Spam', $akismet['plugin_name']);
        $this->assertSame('5.3', $akismet['current_version']);
        $this->assertSame('5.3.1', $akismet['target_version']);
    }

    public function testFindAllPendingUpdatesForUserExcludesOtherUsers(): void
    {
        $siteA = $this->seedSite(1, 'SmartCoding');
        $siteB = $this->seedSite(2, 'NotMine');

        $this->seedPlugin($siteA, 'akismet', 'Akismet', '5.3', '5.3.1', true);
        $this->seedPlugin($siteB, 'yoast',   'Yoast',   '22.5', '22.6', true);

        $rows = (new SitePluginsRepository())->findAllPendingUpdatesForUser(1);
        $this->assertCount(1, $rows);
        $this->assertSame('akismet', $rows[0]['slug']);

        $rows2 = (new SitePluginsRepository())->findAllPendingUpdatesForUser(2);
        $this->assertCount(1, $rows2);
        $this->assertSame('yoast', $rows2[0]['slug']);
    }

    private function seedSite(int $userId, string $label): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'    => $userId,
            'url'        => 'https://ex-' . uniqid() . '.com',
            'label'      => $label,
            'status'     => 'active',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $wpdb->insert_id;
    }

    private function seedPlugin(int $siteId, string $slug, string $name, string $version, ?string $updateVersion, bool $updateAvailable): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_site_plugins', [
            'site_id'          => $siteId,
            'slug'             => $slug,
            'name'             => $name,
            'version'          => $version,
            'update_version'   => $updateVersion,
            'active'           => 1,
            'update_available' => $updateAvailable ? 1 : 0,
            'updated_at'       => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
```

Test method names MUST be EXACTLY:
- `testFindAllPendingUpdatesForUserReturnsCorrectRowsAcrossSites`
- `testFindAllPendingUpdatesForUserExcludesOtherUsers`

### Step 2: Run tests to verify they fail

```
cd packages/dashboard-plugin && composer test -- --filter SitePluginsRepositoryPendingUpdatesTest
```

Expected: FAIL — `Call to undefined method SitePluginsRepository::findAllPendingUpdatesForUser`.

### Step 3: Add the method

In `packages/dashboard-plugin/src/Services/SitePluginsRepository.php`, append AFTER the existing `healDanglingFailedStates` method (around line 265+):

```php
/**
 * P2.7 — flat list of every (site, plugin) pair with update_available=1 across
 * all sites owned by $userId. Drives the SPA's "Bulk update plugins" confirm
 * dialog. ORDER BY site label, then plugin name for a stable display order.
 *
 * Returns rows with keys: site_id, site_label, slug, plugin_name,
 * current_version, target_version.
 *
 * @return list<array{site_id:int,site_label:string,slug:string,plugin_name:string,current_version:string,target_version:?string}>
 */
public function findAllPendingUpdatesForUser(int $userId): array
{
    global $wpdb;
    $sitesTable   = $wpdb->prefix . 'defyn_sites';
    $pluginsTable = $wpdb->prefix . 'defyn_site_plugins';

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT s.id AS site_id, s.label AS site_label,
                sp.slug, sp.name AS plugin_name,
                sp.version AS current_version, sp.update_version AS target_version
         FROM {$sitesTable} s
         INNER JOIN {$pluginsTable} sp ON sp.site_id = s.id
         WHERE s.user_id = %d
           AND sp.update_available = 1
         ORDER BY s.label, sp.name",
        $userId
    ), ARRAY_A);

    if (!is_array($rows)) {
        return [];
    }

    return array_map(static fn(array $row) => [
        'site_id'         => (int) $row['site_id'],
        'site_label'      => (string) $row['site_label'],
        'slug'            => (string) $row['slug'],
        'plugin_name'     => (string) $row['plugin_name'],
        'current_version' => (string) $row['current_version'],
        'target_version'  => $row['target_version'] !== null ? (string) $row['target_version'] : null,
    ], $rows);
}
```

### Step 4: Run tests to verify they pass

```
cd packages/dashboard-plugin && composer test -- --filter SitePluginsRepositoryPendingUpdatesTest
```
Expected: PASS — both tests green.

Run the existing baseline to confirm no regression:
```
cd packages/dashboard-plugin && composer test -- --filter SitePluginsRepository
```
Expected: PASS (or same pre-existing carry-forward).

### Step 5: Commit

```bash
git -C "/Users/pradeep/Local Sites/defynWP" add packages/dashboard-plugin/src/Services/SitePluginsRepository.php \
        packages/dashboard-plugin/tests/Integration/Services/SitePluginsRepositoryPendingUpdatesTest.php
git -C "/Users/pradeep/Local Sites/defynWP" commit -m "feat(p2-7): SitePluginsRepository::findAllPendingUpdatesForUser

Flat-list query joining defyn_sites + defyn_site_plugins where
update_available=1, scoped to a single owner. Drives the SPA's bulk
update confirm dialog. Per spec § 2.1."
```

---

## Task 2 — `OverviewPendingPluginUpdatesController` + `RateLimit::overviewPendingPluginUpdates` (30/MIN) + route

**Files:**
- Create: `packages/dashboard-plugin/src/Rest/OverviewPendingPluginUpdatesController.php`
- Modify: `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php`
- Modify: `packages/dashboard-plugin/src/Rest/RestRouter.php`
- Test: `packages/dashboard-plugin/tests/Integration/Rest/OverviewPendingPluginUpdatesControllerTest.php` (CREATE)

### Step 1: Write the failing tests

Create `packages/dashboard-plugin/tests/Integration/Rest/OverviewPendingPluginUpdatesControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

final class OverviewPendingPluginUpdatesControllerTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL
        $wpdb->query('SET autocommit = 1');
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_site_plugins");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_sites");
        for ($u = 1; $u <= 10; $u++) {
            delete_transient(sprintf('defyn_rl_overviewPendingPluginUpdates_%d', $u));
        }
        // phpcs:enable WordPress.DB.PreparedSQL
        parent::setUp();
        do_action('rest_api_init');
    }

    public function testAuthRequiredReturns401WhenNoBearerToken(): void
    {
        $request = new WP_REST_Request('GET', '/defyn/v1/overview/pending-plugin-updates');
        $response = rest_do_request($request);
        $this->assertSame(401, $response->get_status());
    }

    public function testHappyPath200WithFlatList(): void
    {
        $siteA = $this->seedSite(1, 'SmartCoding');
        $this->seedPlugin($siteA, 'akismet', 'Akismet Anti-Spam', '5.3', '5.3.1', true);

        $token = $this->token(1);
        $request = new WP_REST_Request('GET', '/defyn/v1/overview/pending-plugin-updates');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $response = rest_do_request($request);

        $this->assertSame(200, $response->get_status());
        $body = $response->get_data();
        $this->assertArrayHasKey('pending_updates', $body);
        $this->assertArrayHasKey('generated_at', $body);
        $this->assertCount(1, $body['pending_updates']);
        $this->assertSame('akismet', $body['pending_updates'][0]['slug']);
        $this->assertSame('SmartCoding', $body['pending_updates'][0]['site_label']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $body['generated_at']);
    }

    public function testRateLimit429AfterThirtyFirstCall(): void
    {
        $token = $this->token(1);

        for ($i = 0; $i < 30; $i++) {
            $req = new WP_REST_Request('GET', '/defyn/v1/overview/pending-plugin-updates');
            $req->set_header('Authorization', 'Bearer ' . $token);
            $resp = rest_do_request($req);
            $this->assertSame(200, $resp->get_status(), 'call #' . ($i + 1) . ' should be 200');
        }

        $req = new WP_REST_Request('GET', '/defyn/v1/overview/pending-plugin-updates');
        $req->set_header('Authorization', 'Bearer ' . $token);
        $resp = rest_do_request($req);
        $this->assertSame(429, $resp->get_status());
        $this->assertSame('overview.rate_limited', $resp->get_data()['error']['code'] ?? null);
    }

    public function testOwnershipScopingExcludesOtherUsersSites(): void
    {
        $siteOther = $this->seedSite(2, 'NotMine');
        $this->seedPlugin($siteOther, 'akismet', 'Akismet', '5.3', '5.3.1', true);

        $token = $this->token(1); // user 1 has zero sites
        $request = new WP_REST_Request('GET', '/defyn/v1/overview/pending-plugin-updates');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $response = rest_do_request($request);

        $this->assertSame(200, $response->get_status());
        $this->assertSame([], $response->get_data()['pending_updates']);
    }

    private function seedSite(int $userId, string $label): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'    => $userId,
            'url'        => 'https://ex-' . uniqid() . '.com',
            'label'      => $label,
            'status'     => 'active',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $wpdb->insert_id;
    }

    private function seedPlugin(int $siteId, string $slug, string $name, string $version, ?string $updateVersion, bool $updateAvailable): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_site_plugins', [
            'site_id'          => $siteId,
            'slug'             => $slug,
            'name'             => $name,
            'version'          => $version,
            'update_version'   => $updateVersion,
            'active'           => 1,
            'update_available' => $updateAvailable ? 1 : 0,
            'updated_at'       => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function token(int $userId): string
    {
        return (new TokenService(DEFYN_JWT_SECRET))->issueAccess($userId);
    }
}
```

Test method names MUST be EXACTLY:
- `testAuthRequiredReturns401WhenNoBearerToken`
- `testHappyPath200WithFlatList`
- `testRateLimit429AfterThirtyFirstCall` ← critical
- `testOwnershipScopingExcludesOtherUsersSites`

### Step 2: Run tests to verify they fail

```
cd packages/dashboard-plugin && composer test -- --filter OverviewPendingPluginUpdatesControllerTest
```
Expected: FAIL — `rest_no_route` because the endpoint isn't registered yet.

### Step 3: Create controller + RateLimit method + route registration

Create `packages/dashboard-plugin/src/Rest/OverviewPendingPluginUpdatesController.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Services\SitePluginsRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P2.7 — GET /defyn/v1/overview/pending-plugin-updates.
 *
 * Returns the flat list of eligible (site, plugin) pairs for the SPA's bulk
 * update confirm dialog. Rate-limited at 30/MINUTE (same shape as P2.5's
 * /overview because the SPA may fetch this on dialog open).
 *
 * Spec: docs/superpowers/specs/2026-06-09-p2-7-bulk-plugin-updates-design.md § 2.1
 */
final class OverviewPendingPluginUpdatesController
{
    public function __construct(
        private readonly SitePluginsRepository $plugins = new SitePluginsRepository(),
    ) {}

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        ob_start();
        try {
            $userId = (int) $request->get_param('_authenticated_user_id');
            $rows = $this->plugins->findAllPendingUpdatesForUser($userId);

            return new WP_REST_Response([
                'pending_updates' => $rows,
                'generated_at'    => gmdate('Y-m-d H:i:s'),
            ], 200);
        } finally {
            ob_end_clean();
        }
    }
}
```

In `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php`, after the `OVERVIEW_SYNC_ALL_*` constants block (lines 86-87), append:

```php
// P2.7 — GET /overview/pending-plugin-updates. Per-MINUTE bucket — same shape
// as P2.5's overview poll because the SPA fetches this on dialog open. NOT
// HOUR_IN_SECONDS (plan-bug trap #2 — common copy-paste from the bulk endpoint).
public const OVERVIEW_PENDING_PLUGIN_UPDATES_LIMIT  = 30;
public const OVERVIEW_PENDING_PLUGIN_UPDATES_WINDOW = MINUTE_IN_SECONDS;
```

After the existing `overviewSyncAll(...)` method (around line 401-422), append:

```php
/**
 * Permission callback for GET /overview/pending-plugin-updates.
 *
 * Per-MINUTE bucket — mirrors P2.5's overview() pattern because the SPA
 * fetches this on dialog open. Distinct prefix from defyn_rl_overview_%d.
 *
 * @return true|WP_Error
 */
public static function overviewPendingPluginUpdates(WP_REST_Request $request)
{
    $authResult = RequireAuth::check($request);
    if (is_wp_error($authResult)) {
        return $authResult;
    }

    $userId = (int) $request->get_param('_authenticated_user_id');

    $key   = sprintf('defyn_rl_overviewPendingPluginUpdates_%d', $userId);
    $count = (int) (get_transient($key) ?: 0);

    if ($count >= self::OVERVIEW_PENDING_PLUGIN_UPDATES_LIMIT) {
        return new \WP_Error(
            'overview.rate_limited',
            'Too many requests. Try again in a moment.',
            ['status' => 429]
        );
    }

    set_transient($key, $count + 1, self::OVERVIEW_PENDING_PLUGIN_UPDATES_WINDOW);
    return true;
}
```

In `packages/dashboard-plugin/src/Rest/RestRouter.php`, append IMMEDIATELY AFTER the `/overview/sync-all` POST registration (lines 225-229) and BEFORE the `/activity` GET at line 231:

```php
// P2.7 — GET /overview/pending-plugin-updates. Returns the flat list of
// eligible (site, plugin) pairs for the SPA's bulk update confirm dialog.
// RateLimit::overviewPendingPluginUpdates is 30/MINUTE.
register_rest_route(self::NAMESPACE, '/overview/pending-plugin-updates', [
    'methods'             => 'GET',
    'callback'            => [new OverviewPendingPluginUpdatesController(), 'handle'],
    'permission_callback' => [RateLimit::class, 'overviewPendingPluginUpdates'],
]);
```

### Step 4: Run tests to verify they pass

```
cd packages/dashboard-plugin && composer test -- --filter OverviewPendingPluginUpdatesControllerTest
```
Expected: PASS — all 4 tests green.

Also run the RateLimit baseline to confirm no regression:
```
cd packages/dashboard-plugin && composer test -- --filter RateLimitTest
```
Expected: PASS.

### Step 5: Commit

```bash
git -C "/Users/pradeep/Local Sites/defynWP" add packages/dashboard-plugin/src/Rest/OverviewPendingPluginUpdatesController.php \
        packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php \
        packages/dashboard-plugin/src/Rest/RestRouter.php \
        packages/dashboard-plugin/tests/Integration/Rest/OverviewPendingPluginUpdatesControllerTest.php
git -C "/Users/pradeep/Local Sites/defynWP" commit -m "feat(p2-7): GET /defyn/v1/overview/pending-plugin-updates + 30/MIN RateLimit

Read-only flat-list endpoint for the SPA's bulk update confirm dialog.
Delegates to SitePluginsRepository::findAllPendingUpdatesForUser. Per
spec § 2.1 + plan-bug trap #2 (MINUTE_IN_SECONDS, NOT HOUR)."
```

---

## Task 3 — `OverviewBulkUpdatePluginsController` + `RateLimit::bulkPluginUpdate` (5/HR) + route

**Files:**
- Create: `packages/dashboard-plugin/src/Rest/OverviewBulkUpdatePluginsController.php`
- Modify: `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php`
- Modify: `packages/dashboard-plugin/src/Rest/RestRouter.php`
- Test: `packages/dashboard-plugin/tests/Integration/Rest/OverviewBulkUpdatePluginsControllerTest.php` (CREATE)

### Step 1: Write the failing tests

Create `packages/dashboard-plugin/tests/Integration/Rest/OverviewBulkUpdatePluginsControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

final class OverviewBulkUpdatePluginsControllerTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL
        $wpdb->query('SET autocommit = 1');
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_activity_log");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_site_plugins");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_sites");
        for ($u = 1; $u <= 10; $u++) {
            delete_transient(sprintf('defyn_rl_bulkPluginUpdate_%d', $u));
        }
        // phpcs:enable WordPress.DB.PreparedSQL
        parent::setUp();
        do_action('rest_api_init');
    }

    public function testAuthRequiredReturns401WhenNoBearerToken(): void
    {
        $request = new WP_REST_Request('POST', '/defyn/v1/overview/bulk-update-plugins');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode(['updates' => [['site_id' => 1, 'slug' => 'akismet']]]));
        $response = rest_do_request($request);
        $this->assertSame(401, $response->get_status());
    }

    public function testHappyPath202WithScheduledPairs(): void
    {
        $siteA = $this->seedSite(1, 'A');
        $siteB = $this->seedSite(1, 'B');
        $this->seedPlugin($siteA, 'akismet', 'Akismet', '5.3', '5.3.1', true);
        $this->seedPlugin($siteA, 'yoast',   'Yoast',   '22.5', '22.6', true);
        $this->seedPlugin($siteB, 'jetpack', 'Jetpack', '13.1', '13.2', true);

        $token = $this->token(1);
        $request = new WP_REST_Request('POST', '/defyn/v1/overview/bulk-update-plugins');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode(['updates' => [
            ['site_id' => $siteA, 'slug' => 'akismet'],
            ['site_id' => $siteA, 'slug' => 'yoast'],
            ['site_id' => $siteB, 'slug' => 'jetpack'],
        ]]));
        $response = rest_do_request($request);

        $this->assertSame(202, $response->get_status());
        $body = $response->get_data();
        $this->assertSame(3, $body['scheduled_count']);
        $this->assertSame(0, $body['skipped_count']);
        $this->assertCount(3, $body['scheduled_pairs']);
        $this->assertSame([], $body['skipped_pairs']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $body['scheduled_at']);
    }

    public function testEmptyUpdatesReturns400(): void
    {
        $token = $this->token(1);
        $request = new WP_REST_Request('POST', '/defyn/v1/overview/bulk-update-plugins');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode(['updates' => []]));
        $response = rest_do_request($request);

        $this->assertSame(400, $response->get_status());
        $this->assertSame('bulk.empty_updates', $response->get_data()['error']['code'] ?? null);
    }

    public function testRateLimit429AfterSixthCall(): void
    {
        $siteA = $this->seedSite(1, 'A');
        $this->seedPlugin($siteA, 'akismet', 'Akismet', '5.3', '5.3.1', true);
        $token = $this->token(1);

        for ($i = 0; $i < 5; $i++) {
            $req = new WP_REST_Request('POST', '/defyn/v1/overview/bulk-update-plugins');
            $req->set_header('Authorization', 'Bearer ' . $token);
            $req->set_header('Content-Type', 'application/json');
            $req->set_body(json_encode(['updates' => [['site_id' => $siteA, 'slug' => 'akismet']]]));
            $resp = rest_do_request($req);
            $this->assertSame(202, $resp->get_status(), 'call #' . ($i + 1) . ' should be 202');
        }

        $req = new WP_REST_Request('POST', '/defyn/v1/overview/bulk-update-plugins');
        $req->set_header('Authorization', 'Bearer ' . $token);
        $req->set_header('Content-Type', 'application/json');
        $req->set_body(json_encode(['updates' => [['site_id' => $siteA, 'slug' => 'akismet']]]));
        $resp = rest_do_request($req);
        $this->assertSame(429, $resp->get_status());
        $this->assertSame('bulk.rate_limited', $resp->get_data()['error']['code'] ?? null);
    }

    public function testSkipsPairsNotOwnedOrWithoutUpdate(): void
    {
        $siteOwned    = $this->seedSite(1, 'Owned');
        $siteOtherUsr = $this->seedSite(2, 'NotMine');
        $this->seedPlugin($siteOwned,    'akismet',     'Akismet',  '5.3', '5.3.1', true);  // valid
        $this->seedPlugin($siteOwned,    'no-upd',      'NoUpdate', '1.0', null,    false); // no_update_available
        $this->seedPlugin($siteOtherUsr, 'wpml',        'WPML',     '4.7', '4.8',   true);  // owned by user 2 — site_not_owned

        $token = $this->token(1);
        $request = new WP_REST_Request('POST', '/defyn/v1/overview/bulk-update-plugins');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode(['updates' => [
            ['site_id' => $siteOwned,    'slug' => 'akismet'],     // SCHEDULED
            ['site_id' => $siteOwned,    'slug' => 'no-upd'],      // no_update_available
            ['site_id' => $siteOwned,    'slug' => 'not-in-inv'],  // plugin_not_found
            ['site_id' => $siteOtherUsr, 'slug' => 'wpml'],        // site_not_owned
        ]]));
        $response = rest_do_request($request);

        $this->assertSame(202, $response->get_status());
        $body = $response->get_data();
        $this->assertSame(1, $body['scheduled_count']);
        $this->assertSame(3, $body['skipped_count']);

        $reasons = array_column($body['skipped_pairs'], 'reason', 'slug');
        $this->assertSame('no_update_available', $reasons['no-upd']);
        $this->assertSame('plugin_not_found',    $reasons['not-in-inv']);
        $this->assertSame('site_not_owned',      $reasons['wpml']);
    }

    public function testFanOutSchedulesPerPair(): void
    {
        $siteA = $this->seedSite(1, 'A');
        $this->seedPlugin($siteA, 'akismet', 'Akismet', '5.3', '5.3.1', true);
        $this->seedPlugin($siteA, 'yoast',   'Yoast',   '22.5', '22.6', true);

        $token = $this->token(1);
        $request = new WP_REST_Request('POST', '/defyn/v1/overview/bulk-update-plugins');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode(['updates' => [
            ['site_id' => $siteA, 'slug' => 'akismet'],
            ['site_id' => $siteA, 'slug' => 'yoast'],
        ]]));
        rest_do_request($request);

        $akismetJobs = as_get_scheduled_actions([
            'hook' => 'defyn_update_site_plugin',
            'args' => [$siteA, 'akismet', 0],
        ]);
        $yoastJobs = as_get_scheduled_actions([
            'hook' => 'defyn_update_site_plugin',
            'args' => [$siteA, 'yoast', 0],
        ]);
        $this->assertGreaterThanOrEqual(1, count($akismetJobs));
        $this->assertGreaterThanOrEqual(1, count($yoastJobs));
    }

    public function testActivityEventEmittedWithCorrectDetails(): void
    {
        global $wpdb;
        $siteA = $this->seedSite(1, 'A');
        $this->seedPlugin($siteA, 'akismet', 'Akismet', '5.3', '5.3.1', true);
        $this->seedPlugin($siteA, 'yoast',   'Yoast',   '22.5', '22.6', true);

        $token = $this->token(1);
        $request = new WP_REST_Request('POST', '/defyn/v1/overview/bulk-update-plugins');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode(['updates' => [
            ['site_id' => $siteA, 'slug' => 'akismet'],
            ['site_id' => $siteA, 'slug' => 'yoast'],
        ]]));
        rest_do_request($request);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}defyn_activity_log WHERE event_type = %s",
            'overview.bulk_plugin_update_requested'
        ), ARRAY_A);

        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows[0]['user_id']);
        $this->assertNull($rows[0]['site_id']); // fleet-scoped — trap #4

        $details = json_decode((string) $rows[0]['details'], true);
        $this->assertSame(2, $details['scheduled_count']);
        $this->assertSame(0, $details['skipped_count']);
        $this->assertCount(2, $details['pairs']);
    }

    public function testZeroValidPairsReturns200AndNoActivityEvent(): void
    {
        global $wpdb;
        $siteOwned    = $this->seedSite(1, 'Owned');
        $siteOtherUsr = $this->seedSite(2, 'NotMine');
        $this->seedPlugin($siteOwned, 'no-upd', 'NoUpdate', '1.0', null, false);
        $this->seedPlugin($siteOtherUsr, 'wpml', 'WPML', '4.7', '4.8', true);

        $token = $this->token(1);
        $request = new WP_REST_Request('POST', '/defyn/v1/overview/bulk-update-plugins');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode(['updates' => [
            ['site_id' => $siteOwned,    'slug' => 'no-upd'],    // no_update_available
            ['site_id' => $siteOwned,    'slug' => 'ghost'],     // plugin_not_found
            ['site_id' => $siteOtherUsr, 'slug' => 'wpml'],      // site_not_owned
        ]]));
        $response = rest_do_request($request);

        $this->assertSame(200, $response->get_status());
        $body = $response->get_data();
        $this->assertSame(0, $body['scheduled_count']);
        $this->assertSame(3, $body['skipped_count']);

        // Plan-bug trap #5 — guard if (count > 0) before logging.
        $logRows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}defyn_activity_log WHERE event_type = %s",
            'overview.bulk_plugin_update_requested'
        ));
        $this->assertSame([], $logRows);
    }

    private function seedSite(int $userId, string $label): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'    => $userId,
            'url'        => 'https://ex-' . uniqid() . '.com',
            'label'      => $label,
            'status'     => 'active',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $wpdb->insert_id;
    }

    private function seedPlugin(int $siteId, string $slug, string $name, string $version, ?string $updateVersion, bool $updateAvailable): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_site_plugins', [
            'site_id'          => $siteId,
            'slug'             => $slug,
            'name'             => $name,
            'version'          => $version,
            'update_version'   => $updateVersion,
            'active'           => 1,
            'update_available' => $updateAvailable ? 1 : 0,
            'updated_at'       => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function token(int $userId): string
    {
        return (new TokenService(DEFYN_JWT_SECRET))->issueAccess($userId);
    }
}
```

Test method names MUST be EXACTLY:
- `testAuthRequiredReturns401WhenNoBearerToken`
- `testHappyPath202WithScheduledPairs`
- `testEmptyUpdatesReturns400`
- `testRateLimit429AfterSixthCall` ← **critical: NOT Seventh, NOT Eleventh, NOT ThirtyFirst**
- `testSkipsPairsNotOwnedOrWithoutUpdate`
- `testFanOutSchedulesPerPair`
- `testActivityEventEmittedWithCorrectDetails`
- `testZeroValidPairsReturns200AndNoActivityEvent`

### Step 2: Run tests to verify they fail

```
cd packages/dashboard-plugin && composer test -- --filter OverviewBulkUpdatePluginsControllerTest
```
Expected: FAIL — `rest_no_route`.

### Step 3: Create controller + RateLimit method + route

Create `packages/dashboard-plugin/src/Rest/OverviewBulkUpdatePluginsController.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\SitePluginsRepository;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P2.7 — POST /defyn/v1/overview/bulk-update-plugins.
 *
 * Validates each (site_id, slug) pair in the request body, skips invalid pairs
 * with a structured reason, fan-outs the existing P2.2 `defyn_update_site_plugin`
 * AS job per valid pair, and emits ONE fleet-scoped
 * `overview.bulk_plugin_update_requested` activity event (site_id=null) ONLY
 * when scheduled_count > 0.
 *
 * Returns 202 when scheduled_count > 0, 200 with same envelope when 0 (all
 * skipped), 400 bulk.empty_updates on empty body, 429 bulk.rate_limited over
 * the bucket cap.
 *
 * Bypasses the per-(user, site, slug) pluginsUpdate 6/HOUR bucket — operator's
 * explicit dialog confirmation IS the safety.
 *
 * Spec: docs/superpowers/specs/2026-06-09-p2-7-bulk-plugin-updates-design.md § 2.2
 */
final class OverviewBulkUpdatePluginsController
{
    public function __construct(
        private readonly SitesRepository $sites = new SitesRepository(),
        private readonly SitePluginsRepository $plugins = new SitePluginsRepository(),
        private readonly ActivityLogger $logger = new ActivityLogger(),
    ) {}

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        // Defensive STDOUT guard — P2.2 plan-bug #4 carry-forward.
        ob_start();
        try {
            $userId  = (int) $request->get_param('_authenticated_user_id');
            $body    = $request->get_json_params();
            $updates = $body['updates'] ?? null;

            if (!is_array($updates) || count($updates) === 0) {
                return ErrorResponse::create(400, 'bulk.empty_updates', 'updates array must be non-empty');
            }

            $scheduled = [];
            $skipped   = [];

            foreach ($updates as $pair) {
                $siteId = (int) ($pair['site_id'] ?? 0);
                $slug   = (string) ($pair['slug'] ?? '');

                if ($this->sites->findByIdForUser($siteId, $userId) === null) {
                    $skipped[] = ['site_id' => $siteId, 'slug' => $slug, 'reason' => 'site_not_owned'];
                    continue;
                }
                $row = $this->plugins->findRowForSiteAndSlug($siteId, $slug);
                if ($row === null) {
                    $skipped[] = ['site_id' => $siteId, 'slug' => $slug, 'reason' => 'plugin_not_found'];
                    continue;
                }
                if ((int) ($row['update_available'] ?? 0) !== 1) {
                    $skipped[] = ['site_id' => $siteId, 'slug' => $slug, 'reason' => 'no_update_available'];
                    continue;
                }

                as_schedule_single_action(time(), 'defyn_update_site_plugin', [$siteId, $slug, 0], 'defyn');
                $scheduled[] = ['site_id' => $siteId, 'slug' => $slug];
            }

            if (count($scheduled) > 0) {
                $this->logger->log(
                    $userId,
                    null,                                              // fleet-scoped — trap #4
                    'overview.bulk_plugin_update_requested',           // exact string — trap #3
                    [
                        'scheduled_count' => count($scheduled),
                        'skipped_count'   => count($skipped),
                        'pairs'           => array_values($scheduled),
                    ]
                );
            }

            return new WP_REST_Response(
                [
                    'scheduled_count' => count($scheduled),
                    'skipped_count'   => count($skipped),
                    'scheduled_pairs' => array_values($scheduled),
                    'skipped_pairs'   => array_values($skipped),
                    'scheduled_at'    => gmdate('Y-m-d H:i:s'),
                ],
                count($scheduled) > 0 ? 202 : 200
            );
        } finally {
            ob_end_clean();
        }
    }
}
```

In `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php`, after the `OVERVIEW_PENDING_PLUGIN_UPDATES_*` constants block (added in Task 2), append:

```php
// P2.7 — POST /overview/bulk-update-plugins. Per-user, 5/HOUR — distinct
// from P2.6's overviewSyncAll (10/HOUR) and tighter to reflect destructive
// nature (each call fan-outs N writes). Plan-bug trap #1: window is HOUR_IN_SECONDS.
public const BULK_PLUGIN_UPDATE_LIMIT  = 5;
public const BULK_PLUGIN_UPDATE_WINDOW = HOUR_IN_SECONDS;
```

After the `overviewPendingPluginUpdates(...)` method (added in Task 2), append:

```php
/**
 * Permission callback for POST /overview/bulk-update-plugins.
 *
 * Per-user, 5/HOUR. Distinct prefix `defyn_rl_bulkPluginUpdate_%d`.
 * Plan-bug trap #1: tighter than overviewSyncAll's 10/HOUR because this
 * fan-outs destructive writes.
 *
 * @return true|WP_Error
 */
public static function bulkPluginUpdate(WP_REST_Request $request)
{
    $authResult = RequireAuth::check($request);
    if (is_wp_error($authResult)) {
        return $authResult;
    }

    $userId = (int) $request->get_param('_authenticated_user_id');

    $key   = sprintf('defyn_rl_bulkPluginUpdate_%d', $userId);
    $count = (int) (get_transient($key) ?: 0);

    if ($count >= self::BULK_PLUGIN_UPDATE_LIMIT) {
        return new \WP_Error(
            'bulk.rate_limited',
            'Too many bulk update requests. Try again in an hour.',
            ['status' => 429]
        );
    }

    set_transient($key, $count + 1, self::BULK_PLUGIN_UPDATE_WINDOW);
    return true;
}
```

In `packages/dashboard-plugin/src/Rest/RestRouter.php`, append IMMEDIATELY AFTER the `/overview/pending-plugin-updates` GET registration (added in Task 2) and BEFORE `/activity`:

```php
// P2.7 — POST /overview/bulk-update-plugins. Fan-outs the P2.2 UpdateSitePlugin
// AS job per confirmed pair; emits ONE fleet-scoped activity event.
// RateLimit::bulkPluginUpdate is 5/HOUR.
register_rest_route(self::NAMESPACE, '/overview/bulk-update-plugins', [
    'methods'             => 'POST',
    'callback'            => [new OverviewBulkUpdatePluginsController(), 'handle'],
    'permission_callback' => [RateLimit::class, 'bulkPluginUpdate'],
]);
```

### Step 4: Run tests to verify they pass

```
cd packages/dashboard-plugin && composer test -- --filter OverviewBulkUpdatePluginsControllerTest
```
Expected: PASS — all 8 tests green.

### Step 5: Commit

```bash
git -C "/Users/pradeep/Local Sites/defynWP" add packages/dashboard-plugin/src/Rest/OverviewBulkUpdatePluginsController.php \
        packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php \
        packages/dashboard-plugin/src/Rest/RestRouter.php \
        packages/dashboard-plugin/tests/Integration/Rest/OverviewBulkUpdatePluginsControllerTest.php
git -C "/Users/pradeep/Local Sites/defynWP" commit -m "feat(p2-7): POST /defyn/v1/overview/bulk-update-plugins + 5/HR RateLimit

Destructive bulk endpoint — validates each pair, fan-outs the existing
P2.2 defyn_update_site_plugin AS job per valid pair, emits ONE fleet-
scoped overview.bulk_plugin_update_requested activity event (site_id
null) ONLY when scheduled_count > 0. Returns 202 / 200 / 400 / 429.
Bypasses per-(user,site,slug) pluginsUpdate bucket — operator's
explicit dialog confirm IS the safety. Per spec § 2 + traps #1-#8."
```

---

## Task 4 — Dashboard v0.8.0 release bump + 2 CORS regressions

**Files:**
- Modify: `packages/dashboard-plugin/defyn-dashboard.php`
- Modify: `packages/dashboard-plugin/readme.txt`
- Modify: `packages/dashboard-plugin/composer.json`
- Create: `packages/dashboard-plugin/tests/Integration/Rest/OverviewPendingPluginUpdatesCorsTest.php`
- Create: `packages/dashboard-plugin/tests/Integration/Rest/OverviewBulkUpdatePluginsCorsTest.php`

### Step 1: Write the CORS regression tests

Create `packages/dashboard-plugin/tests/Integration/Rest/OverviewPendingPluginUpdatesCorsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

final class OverviewPendingPluginUpdatesCorsTest extends AbstractSchemaTestCase
{
    public function testOptionsPreflightOnPendingPluginUpdatesRouteReturnsCorsHeaders(): void
    {
        $request = new WP_REST_Request('OPTIONS', '/defyn/v1/overview/pending-plugin-updates');
        $request->set_header('Origin', 'https://app.defynwp.defyn.agency');
        $request->set_header('Access-Control-Request-Method', 'GET');
        $request->set_header('Access-Control-Request-Headers', 'authorization,content-type');

        $response = rest_do_request($request);
        $response = apply_filters('rest_post_dispatch', $response, rest_get_server(), $request);

        $this->assertSame(
            'https://app.defynwp.defyn.agency',
            $response->get_headers()['Access-Control-Allow-Origin'] ?? null
        );
        $this->assertStringContainsString(
            'GET',
            $response->get_headers()['Access-Control-Allow-Methods'] ?? ''
        );
    }
}
```

Create `packages/dashboard-plugin/tests/Integration/Rest/OverviewBulkUpdatePluginsCorsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

final class OverviewBulkUpdatePluginsCorsTest extends AbstractSchemaTestCase
{
    public function testOptionsPreflightOnBulkUpdatePluginsRouteReturnsCorsHeaders(): void
    {
        $request = new WP_REST_Request('OPTIONS', '/defyn/v1/overview/bulk-update-plugins');
        $request->set_header('Origin', 'https://app.defynwp.defyn.agency');
        $request->set_header('Access-Control-Request-Method', 'POST');
        $request->set_header('Access-Control-Request-Headers', 'authorization,content-type');

        $response = rest_do_request($request);
        $response = apply_filters('rest_post_dispatch', $response, rest_get_server(), $request);

        $this->assertSame(
            'https://app.defynwp.defyn.agency',
            $response->get_headers()['Access-Control-Allow-Origin'] ?? null
        );
        $this->assertStringContainsString(
            'POST',
            $response->get_headers()['Access-Control-Allow-Methods'] ?? ''
        );
    }
}
```

Test method names MUST be EXACTLY:
- `testOptionsPreflightOnPendingPluginUpdatesRouteReturnsCorsHeaders`
- `testOptionsPreflightOnBulkUpdatePluginsRouteReturnsCorsHeaders`

### Step 2: Run tests to verify they pass

```
cd packages/dashboard-plugin && composer test -- --filter "OverviewPendingPluginUpdatesCorsTest|OverviewBulkUpdatePluginsCorsTest"
```

Expected: PASS — namespace-wide CORS filter applies to all `defyn/v1` routes.

If they FAIL with `Access-Control-Allow-Origin: null`, the test environment uses a `DEFYN_SPA_ORIGIN` constant instead of the literal `https://app.defynwp.defyn.agency` string (P2.6 Task 4 implementer used this approach). Read `packages/dashboard-plugin/tests/Integration/Rest/OverviewSyncAllCorsTest.php` and replace both assertions with:

```php
$this->assertSame(
    DEFYN_SPA_ORIGIN,
    $response->get_headers()['Access-Control-Allow-Origin'] ?? null
);
```

Use whichever form matches the existing `OverviewSyncAllCorsTest.php` from P2.6.

### Step 3: Bump version constants

In `packages/dashboard-plugin/defyn-dashboard.php`:
- Change the `Version: 0.7.1` header → `Version: 0.8.0`.
- If `DEFYN_DASHBOARD_VERSION` is defined in this file, bump it to `'0.8.0'`.

In `packages/dashboard-plugin/composer.json`: change `"version": "0.7.1"` → `"version": "0.8.0"`.

In `packages/dashboard-plugin/readme.txt`:
- Update `Stable tag: 0.7.1` → `Stable tag: 0.8.0`.
- PREPEND this changelog entry ABOVE the existing `= 0.7.1 =` block:

```
= 0.8.0 =
* Bulk plugin updates across fleet: POST /defyn/v1/overview/bulk-update-plugins fan-outs the existing P2.2 UpdateSitePlugin AS job per confirmed (site, plugin) pair. 5/hour rate limit. Single overview.bulk_plugin_update_requested activity event captures the fleet-scoped intent.
* New GET /defyn/v1/overview/pending-plugin-updates returns a flat list of eligible (site, plugin) pairs for the SPA's confirmation dialog. 30/minute rate limit.
* Minor version bump because the destructive bulk operation crosses a meaningful threshold relative to v0.7.1's read-side sync-all.
```

### Step 4: Run the full dashboard suite

```
cd packages/dashboard-plugin && composer test
```
Expected: ALL PASS modulo the 3 documented carry-forward failures (`SchemaVersionMigrationV4Test`, `SchemaVersionMigrationV5Test`, `UninstallTest::testUninstallDropsAllTables`). If anything else fails, STOP and triage.

### Step 5: Commit

```bash
git -C "/Users/pradeep/Local Sites/defynWP" add packages/dashboard-plugin/defyn-dashboard.php \
        packages/dashboard-plugin/readme.txt \
        packages/dashboard-plugin/composer.json \
        packages/dashboard-plugin/tests/Integration/Rest/OverviewPendingPluginUpdatesCorsTest.php \
        packages/dashboard-plugin/tests/Integration/Rest/OverviewBulkUpdatePluginsCorsTest.php
git -C "/Users/pradeep/Local Sites/defynWP" commit -m "chore(p2-7): dashboard v0.8.0 release bump + CORS regressions

Bumps plugin version to v0.8.0 (minor bump — destructive bulk endpoint
crosses a meaningful threshold). Adds CORS preflight regression tests
for the two new routes."
```

---

## Task 5 — SPA Zod schemas + MSW handlers

**Files:**
- Modify: `apps/web/src/types/api.ts`
- Modify: `apps/web/src/test/handlers.ts`

### Step 1: There's no separate failing test for Zod additions

Zod schemas + MSW handlers are infrastructure for Tasks 6-9. We exercise them via Task 6's `usePendingPluginUpdates` hook test. Validate by running the broader suite at the end of this task — it should stay GREEN (no new failures beyond the documented carry-forward).

### Step 2: Extend `apps/web/src/types/api.ts`

Find `syncAllSitesResponseSchema` (added in P2.6) and append BELOW it:

```ts
// P2.7 — GET /defyn/v1/overview/pending-plugin-updates response.
export const pendingPluginUpdateRowSchema = z.object({
  site_id: z.number().int(),
  site_label: z.string(),
  slug: z.string(),
  plugin_name: z.string(),
  current_version: z.string(),
  target_version: z.string().nullable(),
});
export type PendingPluginUpdateRow = z.infer<typeof pendingPluginUpdateRowSchema>;

export const pendingPluginUpdatesSchema = z.object({
  pending_updates: z.array(pendingPluginUpdateRowSchema),
  generated_at: z.string(),
});
export type PendingPluginUpdates = z.infer<typeof pendingPluginUpdatesSchema>;

// P2.7 — POST /defyn/v1/overview/bulk-update-plugins response.
const bulkUpdatePairSchema = z.object({
  site_id: z.number().int(),
  slug: z.string(),
});

export const bulkUpdatePluginsResponseSchema = z.object({
  scheduled_count: z.number().int().nonnegative(),
  skipped_count: z.number().int().nonnegative(),
  scheduled_pairs: z.array(bulkUpdatePairSchema),
  skipped_pairs: z.array(bulkUpdatePairSchema.extend({
    reason: z.enum(['site_not_owned', 'plugin_not_found', 'no_update_available']),
  })),
  scheduled_at: z.string(),
});
export type BulkUpdatePluginsResponse = z.infer<typeof bulkUpdatePluginsResponseSchema>;
```

### Step 3: Extend `apps/web/src/test/handlers.ts`

Find the `POST /overview/sync-all` MSW handler (added in P2.6). Append two new handlers BELOW it:

```ts
// P2.7 — GET /overview/pending-plugin-updates default empty list.
http.get('*/wp-json/defyn/v1/overview/pending-plugin-updates', () => {
  return HttpResponse.json({
    pending_updates: [],
    generated_at: '2026-06-09 23:15:00',
  });
}),

// P2.7 — POST /overview/bulk-update-plugins default synthetic 202.
http.post('*/wp-json/defyn/v1/overview/bulk-update-plugins', async ({ request }) => {
  const body = (await request.json()) as { updates: Array<{ site_id: number; slug: string }> };
  return HttpResponse.json(
    {
      scheduled_count: body.updates.length,
      skipped_count: 0,
      scheduled_pairs: body.updates,
      skipped_pairs: [],
      scheduled_at: '2026-06-09 23:15:42',
    },
    { status: body.updates.length > 0 ? 202 : 200 },
  );
}),
```

### Step 4: Run the broader suite

```
cd apps/web && pnpm test -- --run
```

Expected: PASS modulo the documented carry-forward (4 SPA tests). No new failures from this schema/handler addition.

### Step 5: Commit

```bash
git -C "/Users/pradeep/Local Sites/defynWP" add apps/web/src/types/api.ts apps/web/src/test/handlers.ts
git -C "/Users/pradeep/Local Sites/defynWP" commit -m "feat(p2-7): SPA Zod schemas + MSW handlers for bulk plugin update endpoints

Adds pendingPluginUpdatesSchema (GET) + bulkUpdatePluginsResponseSchema
(POST) with z.infer types. Adds default MSW handlers that mirror the
server contract — empty list for GET, echo input as scheduled for POST.
Per spec § 3.2."
```

---

## Task 6 — `usePendingPluginUpdates` query hook (enabled-on-open)

**Files:**
- Create: `apps/web/src/lib/queries/usePendingPluginUpdates.ts`
- Test: `apps/web/tests/lib/queries/usePendingPluginUpdates.test.tsx` (CREATE)

### Step 1: Write the failing test

Create `apps/web/tests/lib/queries/usePendingPluginUpdates.test.tsx`:

```tsx
import { describe, it, expect } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { http, HttpResponse } from 'msw';
import { server } from '@/test/setup';
import { usePendingPluginUpdates } from '@/lib/queries/usePendingPluginUpdates';
import React from 'react';

function makeWrapper(qc: QueryClient) {
  return ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
}

describe('usePendingPluginUpdates', () => {
  it('does NOT fetch when enabled=false (dialog closed)', async () => {
    let fetchCount = 0;
    server.use(
      http.get('*/wp-json/defyn/v1/overview/pending-plugin-updates', () => {
        fetchCount++;
        return HttpResponse.json({ pending_updates: [], generated_at: '2026-06-09 23:00:00' });
      }),
    );

    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    renderHook(() => usePendingPluginUpdates(false), { wrapper: makeWrapper(qc) });

    // Wait briefly to confirm no fetch fired.
    await new Promise((r) => setTimeout(r, 50));
    expect(fetchCount).toBe(0);
  });

  it('fetches and parses when enabled=true (dialog open)', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/overview/pending-plugin-updates', () => {
        return HttpResponse.json({
          pending_updates: [
            {
              site_id: 1,
              site_label: 'SmartCoding',
              slug: 'akismet',
              plugin_name: 'Akismet Anti-Spam',
              current_version: '5.3',
              target_version: '5.3.1',
            },
          ],
          generated_at: '2026-06-09 23:00:00',
        });
      }),
    );

    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const { result } = renderHook(() => usePendingPluginUpdates(true), { wrapper: makeWrapper(qc) });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data?.pending_updates).toHaveLength(1);
    expect(result.current.data?.pending_updates[0].slug).toBe('akismet');
  });
});
```

### Step 2: Run test to verify it fails

```
cd apps/web && pnpm test -- --run usePendingPluginUpdates
```
Expected: FAIL — `usePendingPluginUpdates` doesn't exist.

### Step 3: Create the hook

Create `apps/web/src/lib/queries/usePendingPluginUpdates.ts`:

```ts
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { pendingPluginUpdatesSchema } from '@/types/api';

/**
 * P2.7 — fetches the flat list of (site, plugin) pairs with update_available=1
 * for the SPA's bulk update confirm dialog. Enabled-ONLY-on-dialog-open
 * (plan-bug trap #12) — set the dialogOpen flag from the parent component to
 * gate the fetch. NOT polling.
 *
 * Query key: ['pendingPluginUpdates'] so the bulk mutation can invalidate it.
 */
export function usePendingPluginUpdates(dialogOpen: boolean) {
  return useQuery({
    queryKey: ['pendingPluginUpdates'],
    queryFn: async () => {
      const data = await apiClient.get<unknown>('/overview/pending-plugin-updates');
      return pendingPluginUpdatesSchema.parse(data);
    },
    enabled: dialogOpen,
  });
}
```

### Step 4: Run test to verify it passes

```
cd apps/web && pnpm test -- --run usePendingPluginUpdates
```
Expected: PASS — both tests green.

### Step 5: Commit

```bash
git -C "/Users/pradeep/Local Sites/defynWP" add apps/web/src/lib/queries/usePendingPluginUpdates.ts \
        apps/web/tests/lib/queries/usePendingPluginUpdates.test.tsx
git -C "/Users/pradeep/Local Sites/defynWP" commit -m "feat(p2-7): usePendingPluginUpdates query hook (enabled-on-open)

TanStack query gated on dialogOpen flag — does NOT poll. Mirrors plan-
bug trap #12. Query key ['pendingPluginUpdates'] is invalidated by the
bulk mutation hook on success."
```

---

## Task 7 — `useBulkUpdatePlugins` mutation hook

**Files:**
- Create: `apps/web/src/lib/mutations/useBulkUpdatePlugins.ts`
- Test: `apps/web/tests/lib/mutations/useBulkUpdatePlugins.test.tsx` (CREATE)

### Step 1: Write the failing tests

Create `apps/web/tests/lib/mutations/useBulkUpdatePlugins.test.tsx`:

```tsx
import { describe, it, expect, vi } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { http, HttpResponse } from 'msw';
import { server } from '@/test/setup';
import { useBulkUpdatePlugins } from '@/lib/mutations/useBulkUpdatePlugins';
import React from 'react';

function makeWrapper(qc: QueryClient) {
  return ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
}

describe('useBulkUpdatePlugins', () => {
  it('postsToBulkUpdateEndpointWithCorrectBody', async () => {
    let capturedBody: unknown = null;
    server.use(
      http.post('*/wp-json/defyn/v1/overview/bulk-update-plugins', async ({ request }) => {
        capturedBody = await request.json();
        return HttpResponse.json(
          {
            scheduled_count: 2,
            skipped_count: 0,
            scheduled_pairs: [
              { site_id: 1, slug: 'akismet' },
              { site_id: 1, slug: 'yoast' },
            ],
            skipped_pairs: [],
            scheduled_at: '2026-06-09 23:15:42',
          },
          { status: 202 },
        );
      }),
    );

    const qc = new QueryClient({ defaultOptions: { mutations: { retry: false } } });
    const { result } = renderHook(() => useBulkUpdatePlugins(), { wrapper: makeWrapper(qc) });
    result.current.mutate({
      updates: [
        { site_id: 1, slug: 'akismet' },
        { site_id: 1, slug: 'yoast' },
      ],
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(capturedBody).toEqual({
      updates: [
        { site_id: 1, slug: 'akismet' },
        { site_id: 1, slug: 'yoast' },
      ],
    });
    expect(result.current.data?.scheduled_count).toBe(2);
  });

  it('invalidatesOverviewAndPendingQueriesOnSuccessButNotSites', async () => {
    server.use(
      http.post('*/wp-json/defyn/v1/overview/bulk-update-plugins', () =>
        HttpResponse.json(
          {
            scheduled_count: 1,
            skipped_count: 0,
            scheduled_pairs: [{ site_id: 1, slug: 'akismet' }],
            skipped_pairs: [],
            scheduled_at: '2026-06-09 23:15:42',
          },
          { status: 202 },
        ),
      ),
    );

    const qc = new QueryClient({ defaultOptions: { mutations: { retry: false } } });
    const invalidateSpy = vi.spyOn(qc, 'invalidateQueries');

    const { result } = renderHook(() => useBulkUpdatePlugins(), { wrapper: makeWrapper(qc) });
    result.current.mutate({ updates: [{ site_id: 1, slug: 'akismet' }] });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    await waitFor(() => {
      expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: ['overview'] });
    });
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: ['pendingPluginUpdates'] });

    // Plan-bug trap #11 — must NOT invalidate sites.
    const sitesCall = invalidateSpy.mock.calls.find(
      ([arg]) =>
        Array.isArray((arg as { queryKey?: unknown }).queryKey) &&
        (arg as { queryKey: unknown[] }).queryKey[0] === 'sites',
    );
    expect(sitesCall).toBeUndefined();
  });
});
```

Test method names MUST be EXACTLY:
- `postsToBulkUpdateEndpointWithCorrectBody`
- `invalidatesOverviewAndPendingQueriesOnSuccessButNotSites`

### Step 2: Run tests to verify they fail

```
cd apps/web && pnpm test -- --run useBulkUpdatePlugins
```
Expected: FAIL — `useBulkUpdatePlugins` doesn't exist.

### Step 3: Create the hook

Create `apps/web/src/lib/mutations/useBulkUpdatePlugins.ts`:

```ts
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import {
  bulkUpdatePluginsResponseSchema,
  type BulkUpdatePluginsResponse,
} from '@/types/api';

export interface BulkUpdatePluginsRequest {
  updates: Array<{ site_id: number; slug: string }>;
}

/**
 * P2.7 — POSTs to /defyn/v1/overview/bulk-update-plugins. Server fan-outs
 * the existing P2.2 UpdateSitePlugin AS job per valid (site, slug) pair and
 * emits ONE fleet-scoped overview.bulk_plugin_update_requested activity event.
 *
 * On success: invalidate ['overview'] AND ['pendingPluginUpdates'] so the
 * Recent Activity widget refreshes + the next dialog open re-fetches the
 * (now-shrunk) pending list. Plan-bug trap #11: NOT ['sites'].
 */
export function useBulkUpdatePlugins() {
  const queryClient = useQueryClient();

  return useMutation<BulkUpdatePluginsResponse, Error, BulkUpdatePluginsRequest>({
    mutationFn: async ({ updates }) => {
      const data = await apiClient.post<unknown>('/overview/bulk-update-plugins', { updates });
      return bulkUpdatePluginsResponseSchema.parse(data);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['overview'] });
      queryClient.invalidateQueries({ queryKey: ['pendingPluginUpdates'] });
    },
  });
}
```

### Step 4: Run tests to verify they pass

```
cd apps/web && pnpm test -- --run useBulkUpdatePlugins
```
Expected: PASS — both tests green.

### Step 5: Commit

```bash
git -C "/Users/pradeep/Local Sites/defynWP" add apps/web/src/lib/mutations/useBulkUpdatePlugins.ts \
        apps/web/tests/lib/mutations/useBulkUpdatePlugins.test.tsx
git -C "/Users/pradeep/Local Sites/defynWP" commit -m "feat(p2-7): useBulkUpdatePlugins mutation hook

POSTs {updates:[...]} to /overview/bulk-update-plugins, parses with Zod,
invalidates ['overview'] AND ['pendingPluginUpdates'] on success (NOT
['sites'] — plan-bug trap #11)."
```

---

## Task 8 — `PendingPluginUpdatesGroup` + `ConfirmBulkUpdatePluginsDialog`

**Files:**
- Create: `apps/web/src/components/overview/PendingPluginUpdatesGroup.tsx`
- Create: `apps/web/src/components/overview/ConfirmBulkUpdatePluginsDialog.tsx`
- Test: `apps/web/tests/components/overview/ConfirmBulkUpdatePluginsDialog.test.tsx` (CREATE)

### Step 1: Write the failing tests

Create `apps/web/tests/components/overview/ConfirmBulkUpdatePluginsDialog.test.tsx`:

```tsx
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { ConfirmBulkUpdatePluginsDialog } from '@/components/overview/ConfirmBulkUpdatePluginsDialog';

const ROWS = [
  { site_id: 1, site_label: 'SmartCoding', slug: 'akismet', plugin_name: 'Akismet', current_version: '5.3', target_version: '5.3.1' },
  { site_id: 1, site_label: 'SmartCoding', slug: 'yoast',   plugin_name: 'Yoast',   current_version: '22.5', target_version: '22.6' },
  { site_id: 2, site_label: 'AcmeBlog',    slug: 'jetpack', plugin_name: 'Jetpack', current_version: '13.1', target_version: '13.2' },
];

describe('ConfirmBulkUpdatePluginsDialog', () => {
  it('cancelHasDefaultFocus', () => {
    render(
      <ConfirmBulkUpdatePluginsDialog
        open
        rows={ROWS}
        onCancel={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );
    expect(screen.getByRole('button', { name: /^cancel$/i })).toHaveFocus();
  });

  it('primaryButtonUsesDestructiveVariant', () => {
    render(
      <ConfirmBulkUpdatePluginsDialog
        open
        rows={ROWS}
        onCancel={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );
    // Plan-bug trap #9 — Button has no built-in destructive variant in this codebase.
    // We use className override matching P2.4.1's ConfirmUpdateCoreDialog pattern.
    const primary = screen.getByRole('button', { name: /bulk update 3 plugins/i });
    expect(primary.className).toMatch(/bg-red-600/);
  });

  it('primaryButtonDisabledWhenZeroSelected', () => {
    render(
      <ConfirmBulkUpdatePluginsDialog
        open
        rows={ROWS}
        onCancel={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );

    // Uncheck all 3 individual checkboxes.
    const plugins = screen.getAllByRole('checkbox', { name: /akismet|yoast|jetpack/i });
    plugins.forEach((cb) => fireEvent.click(cb));

    const primary = screen.getByRole('button', { name: /bulk update 0 plugins/i });
    expect(primary).toBeDisabled();
  });

  it('footerCounterUpdatesLive', () => {
    render(
      <ConfirmBulkUpdatePluginsDialog
        open
        rows={ROWS}
        onCancel={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );
    expect(screen.getByText(/3 selected of 3 available/i)).toBeInTheDocument();

    // Uncheck akismet.
    fireEvent.click(screen.getByRole('checkbox', { name: /akismet/i }));
    expect(screen.getByText(/2 selected of 3 available/i)).toBeInTheDocument();
  });
});
```

Test method names MUST be EXACTLY:
- `cancelHasDefaultFocus`
- `primaryButtonUsesDestructiveVariant`
- `primaryButtonDisabledWhenZeroSelected`
- `footerCounterUpdatesLive`

### Step 2: Run tests to verify they fail

```
cd apps/web && pnpm test -- --run ConfirmBulkUpdatePluginsDialog
```
Expected: FAIL — component doesn't exist.

### Step 3: Create the sub-component + dialog

Create `apps/web/src/components/overview/PendingPluginUpdatesGroup.tsx`:

```tsx
import type { PendingPluginUpdateRow } from '@/types/api';

interface PendingPluginUpdatesGroupProps {
  siteLabel: string;
  rows: PendingPluginUpdateRow[];
  checkedKeys: Set<string>;
  onToggleRow: (key: string) => void;
  onToggleGroup: (rowKeys: string[], allChecked: boolean) => void;
}

/**
 * P2.7 — per-site collapsible group with grouped checkbox + child rows.
 * Used inside ConfirmBulkUpdatePluginsDialog. Each row has a stable key
 * `${site_id}:${slug}` for the controlled state map.
 */
export function PendingPluginUpdatesGroup({
  siteLabel,
  rows,
  checkedKeys,
  onToggleRow,
  onToggleGroup,
}: PendingPluginUpdatesGroupProps) {
  const rowKeys = rows.map((r) => `${r.site_id}:${r.slug}`);
  const allChecked = rowKeys.every((k) => checkedKeys.has(k));

  return (
    <div data-testid="plugin-group" className="rounded border border-zinc-200 p-3">
      <label className="flex items-center gap-2 text-sm font-semibold text-zinc-900">
        <input
          type="checkbox"
          checked={allChecked}
          onChange={() => onToggleGroup(rowKeys, allChecked)}
          aria-label={`Toggle all plugins on ${siteLabel}`}
        />
        {siteLabel} — {rows.length} plugin{rows.length === 1 ? '' : 's'}
      </label>
      <ul className="mt-2 space-y-1">
        {rows.map((row) => {
          const key = `${row.site_id}:${row.slug}`;
          return (
            <li key={key} className="flex items-center gap-2 text-sm text-zinc-700">
              <input
                type="checkbox"
                checked={checkedKeys.has(key)}
                onChange={() => onToggleRow(key)}
                aria-label={`${row.plugin_name} ${row.current_version} to ${row.target_version}`}
              />
              <span className="flex-1">{row.plugin_name}</span>
              <span className="font-mono text-xs text-zinc-500">
                {row.current_version} → {row.target_version ?? '?'}
              </span>
            </li>
          );
        })}
      </ul>
    </div>
  );
}
```

Create `apps/web/src/components/overview/ConfirmBulkUpdatePluginsDialog.tsx`:

```tsx
import { useEffect, useMemo, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { PendingPluginUpdatesGroup } from '@/components/overview/PendingPluginUpdatesGroup';
import type { PendingPluginUpdateRow } from '@/types/api';

interface ConfirmBulkUpdatePluginsDialogProps {
  open: boolean;
  rows: PendingPluginUpdateRow[];
  onCancel: () => void;
  onConfirm: (selectedPairs: Array<{ site_id: number; slug: string }>) => void;
}

const VISIBLE_GROUP_LIMIT = 3;

/**
 * P2.7 — destructive bulk update confirm dialog.
 *
 * Per spec § 3.3:
 *   - All checkboxes pre-checked on open
 *   - Per-site group checkbox toggles all children
 *   - Footer counter "X selected of Y available" via useMemo
 *   - Primary button RED via className override (Button has no destructive
 *     variant — plan-bug trap #9)
 *   - Cancel default focus (cancelRef + useEffect, mirror of P2.4)
 *   - Long lists collapse: first 3 groups expanded, rest behind disclosure
 *
 * Spec: docs/superpowers/specs/2026-06-09-p2-7-bulk-plugin-updates-design.md § 3
 */
export function ConfirmBulkUpdatePluginsDialog({
  open,
  rows,
  onCancel,
  onConfirm,
}: ConfirmBulkUpdatePluginsDialogProps) {
  const cancelRef = useRef<HTMLButtonElement>(null);
  const [showAll, setShowAll] = useState(false);

  const allKeys = useMemo(
    () => rows.map((r) => `${r.site_id}:${r.slug}`),
    [rows],
  );
  const [checkedKeys, setCheckedKeys] = useState<Set<string>>(() => new Set(allKeys));

  // Re-seed checkedKeys when the dialog opens / rows change.
  useEffect(() => {
    if (open) {
      setCheckedKeys(new Set(allKeys));
      setShowAll(false);
      cancelRef.current?.focus();
    }
  }, [open, allKeys]);

  // Group rows by site_label, preserving the server's order.
  const grouped = useMemo(() => {
    const map = new Map<string, PendingPluginUpdateRow[]>();
    for (const row of rows) {
      const list = map.get(row.site_label) ?? [];
      list.push(row);
      map.set(row.site_label, list);
    }
    return Array.from(map.entries()); // [[label, rows], ...]
  }, [rows]);

  const selectedCount = checkedKeys.size;
  const totalCount = rows.length;

  if (!open) {
    return null;
  }

  const toggleRow = (key: string) => {
    setCheckedKeys((prev) => {
      const next = new Set(prev);
      if (next.has(key)) {
        next.delete(key);
      } else {
        next.add(key);
      }
      return next;
    });
  };

  const toggleGroup = (groupKeys: string[], allChecked: boolean) => {
    setCheckedKeys((prev) => {
      const next = new Set(prev);
      if (allChecked) {
        groupKeys.forEach((k) => next.delete(k));
      } else {
        groupKeys.forEach((k) => next.add(k));
      }
      return next;
    });
  };

  const visibleGroups = showAll ? grouped : grouped.slice(0, VISIBLE_GROUP_LIMIT);
  const hiddenCount = grouped.length - VISIBLE_GROUP_LIMIT;

  const handleConfirm = () => {
    const pairs = Array.from(checkedKeys).map((key) => {
      const [siteIdStr, slug] = key.split(':');
      return { site_id: Number(siteIdStr), slug };
    });
    onConfirm(pairs);
  };

  const titleId = 'bulk-update-plugins-confirm-title';

  return (
    <div
      role="alertdialog"
      aria-modal="true"
      aria-labelledby={titleId}
      className="mt-3 rounded-md border border-zinc-200 bg-white p-4 shadow-sm"
    >
      <h3 id={titleId} className="text-sm font-semibold text-zinc-900">
        🛑 Bulk update {totalCount} plugins across {grouped.length} sites?
      </h3>

      <div className="mt-3 space-y-2 text-sm text-zinc-700">
        <p>This will run the plugin upgrader on every checked pair below. Each site briefly enters maintenance mode during its update.</p>
        <p>Uncheck any pair you want to skip — server fans out exactly what's checked. Already-updated rows are silently no-op'd.</p>
      </div>

      <div className="mt-3 space-y-2">
        {visibleGroups.map(([label, groupRows]) => (
          <PendingPluginUpdatesGroup
            key={label}
            siteLabel={label}
            rows={groupRows}
            checkedKeys={checkedKeys}
            onToggleRow={toggleRow}
            onToggleGroup={toggleGroup}
          />
        ))}
        {!showAll && hiddenCount > 0 && (
          <button
            type="button"
            onClick={() => setShowAll(true)}
            className="text-xs text-zinc-600 underline"
          >
            show all {grouped.length} sites ▾
          </button>
        )}
      </div>

      <div className="mt-3 flex items-center justify-between border-t border-zinc-100 pt-3">
        <p className="text-xs text-zinc-600">
          {selectedCount} selected of {totalCount} available
        </p>
        <div className="flex gap-2">
          <Button ref={cancelRef} variant="outline" onClick={onCancel}>
            Cancel
          </Button>
          <Button
            className="bg-red-600 hover:bg-red-700 text-white"
            disabled={selectedCount === 0}
            onClick={handleConfirm}
          >
            🛑 Bulk update {selectedCount} plugins
          </Button>
        </div>
      </div>
    </div>
  );
}
```

### Step 4: Run tests to verify they pass

```
cd apps/web && pnpm test -- --run ConfirmBulkUpdatePluginsDialog
```
Expected: PASS — all 4 tests green.

### Step 5: Commit

```bash
git -C "/Users/pradeep/Local Sites/defynWP" add apps/web/src/components/overview/PendingPluginUpdatesGroup.tsx \
        apps/web/src/components/overview/ConfirmBulkUpdatePluginsDialog.tsx \
        apps/web/tests/components/overview/ConfirmBulkUpdatePluginsDialog.test.tsx
git -C "/Users/pradeep/Local Sites/defynWP" commit -m "feat(p2-7): ConfirmBulkUpdatePluginsDialog + PendingPluginUpdatesGroup

Per-site collapsible groups with grouped checkbox + child rows. All
pre-checked on open. useMemo footer counter. RED primary via className
override (Button has no destructive variant — plan-bug trap #9).
Cancel default focus mirror of P2.4. First 3 groups expanded, rest
behind disclosure (trap #16). Per spec § 3.3."
```

---

## Task 9 — `BulkUpdatePluginsButton` + Overview integration

**Files:**
- Create: `apps/web/src/components/overview/BulkUpdatePluginsButton.tsx`
- Modify: `apps/web/src/routes/Overview.tsx`
- Test: `apps/web/tests/components/overview/BulkUpdatePluginsButton.test.tsx` (CREATE)

### Step 1: Write the failing tests

Create `apps/web/tests/components/overview/BulkUpdatePluginsButton.test.tsx`:

```tsx
import { describe, it, expect } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { http, HttpResponse } from 'msw';
import { server } from '@/test/setup';
import { BulkUpdatePluginsButton } from '@/components/overview/BulkUpdatePluginsButton';

function renderBtn(pendingCount: number) {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={qc}>
      <BulkUpdatePluginsButton pendingCount={pendingCount} />
    </QueryClientProvider>,
  );
}

describe('BulkUpdatePluginsButton', () => {
  it('rendersIdleStateWithDynamicCount', () => {
    renderBtn(47);
    expect(
      screen.getByRole('button', { name: /bulk update plugins.*47/i }),
    ).toBeInTheDocument();
  });

  it('hiddenWhenPendingCountZero', () => {
    renderBtn(0);
    expect(
      screen.queryByRole('button', { name: /bulk update plugins/i }),
    ).not.toBeInTheDocument();
  });

  it('opensConfirmDialogOnClick', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/overview/pending-plugin-updates', () =>
        HttpResponse.json({
          pending_updates: [
            { site_id: 1, site_label: 'SmartCoding', slug: 'akismet', plugin_name: 'Akismet', current_version: '5.3', target_version: '5.3.1' },
          ],
          generated_at: '2026-06-09 23:00:00',
        }),
      ),
    );

    renderBtn(1);
    fireEvent.click(screen.getByRole('button', { name: /bulk update plugins.*1/i }));

    await waitFor(() => {
      expect(
        screen.getByText(/bulk update 1 plugins across 1 sites\?/i),
      ).toBeInTheDocument();
    });
  });

  it('showsPendingLabelWhilePostInFlight', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/overview/pending-plugin-updates', () =>
        HttpResponse.json({
          pending_updates: [
            { site_id: 1, site_label: 'SC', slug: 'akismet', plugin_name: 'Akismet', current_version: '5.3', target_version: '5.3.1' },
          ],
          generated_at: '2026-06-09 23:00:00',
        }),
      ),
      http.post('*/wp-json/defyn/v1/overview/bulk-update-plugins', async () => {
        await new Promise((r) => setTimeout(r, 40));
        return HttpResponse.json(
          {
            scheduled_count: 1,
            skipped_count: 0,
            scheduled_pairs: [{ site_id: 1, slug: 'akismet' }],
            skipped_pairs: [],
            scheduled_at: '2026-06-09 23:15:42',
          },
          { status: 202 },
        );
      }),
    );

    renderBtn(1);
    fireEvent.click(screen.getByRole('button', { name: /bulk update plugins.*1/i }));
    await waitFor(() =>
      expect(screen.getByText(/bulk update 1 plugins across 1 sites\?/i)).toBeInTheDocument(),
    );
    fireEvent.click(screen.getByRole('button', { name: /bulk update 1 plugins/i }));

    await waitFor(() => {
      expect(screen.getByText(/scheduling 1 updates/i)).toBeInTheDocument();
    });
  });
});
```

Test method names MUST be EXACTLY:
- `rendersIdleStateWithDynamicCount`
- `hiddenWhenPendingCountZero`
- `opensConfirmDialogOnClick`
- `showsPendingLabelWhilePostInFlight`

### Step 2: Run tests to verify they fail

```
cd apps/web && pnpm test -- --run BulkUpdatePluginsButton
```
Expected: FAIL — component doesn't exist.

### Step 3: Create the button + wire Overview.tsx

Create `apps/web/src/components/overview/BulkUpdatePluginsButton.tsx`:

```tsx
import { useState } from 'react';
import { Settings } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useBulkUpdatePlugins } from '@/lib/mutations/useBulkUpdatePlugins';
import { usePendingPluginUpdates } from '@/lib/queries/usePendingPluginUpdates';
import { ConfirmBulkUpdatePluginsDialog } from '@/components/overview/ConfirmBulkUpdatePluginsDialog';

interface BulkUpdatePluginsButtonProps {
  pendingCount: number;
}

/**
 * P2.7 — header button + confirm dialog + mutation invocation.
 *
 * Idle:     [⚙ Bulk update plugins (47)]
 * Pending:  [⏳ Scheduling 47 updates…] (disabled)
 *
 * Hidden entirely when pendingCount === 0 (NOT just disabled — per spec § 3.1).
 * Different from P2.6's "disabled at totalSites=0" because zero pending updates
 * means there's nothing to bulk-update; a disabled button would add noise.
 *
 * Spec: docs/superpowers/specs/2026-06-09-p2-7-bulk-plugin-updates-design.md § 3.2
 */
export function BulkUpdatePluginsButton({ pendingCount }: BulkUpdatePluginsButtonProps) {
  const [dialogOpen, setDialogOpen] = useState(false);
  const pending = usePendingPluginUpdates(dialogOpen);
  const mutation = useBulkUpdatePlugins();

  if (pendingCount === 0) {
    return null;
  }

  const handleConfirm = (selectedPairs: Array<{ site_id: number; slug: string }>) => {
    setDialogOpen(false);
    if (selectedPairs.length > 0) {
      mutation.mutate({ updates: selectedPairs });
    }
  };

  if (mutation.isPending) {
    return (
      <Button variant="outline" size="sm" disabled>
        <Settings className="mr-1.5 h-3.5 w-3.5 animate-spin" aria-hidden="true" />
        Scheduling {pendingCount} updates…
      </Button>
    );
  }

  return (
    <>
      <Button
        variant="outline"
        size="sm"
        onClick={() => setDialogOpen(true)}
      >
        <Settings className="mr-1.5 h-3.5 w-3.5" aria-hidden="true" />
        Bulk update plugins ({pendingCount})
      </Button>
      <ConfirmBulkUpdatePluginsDialog
        open={dialogOpen}
        rows={pending.data?.pending_updates ?? []}
        onCancel={() => setDialogOpen(false)}
        onConfirm={handleConfirm}
      />
    </>
  );
}
```

In `apps/web/src/routes/Overview.tsx`, the current header structure is:

```tsx
<div className="flex items-start justify-between">
  <h1 className="text-xl font-semibold">Overview</h1>
  <div className="flex flex-col items-end gap-1">
    <p className="text-xs text-muted-foreground">
      Last refreshed: {formatRelativeTime(data.generated_at)}
    </p>
    <SyncAllSitesButton totalSites={data.total_sites} />
  </div>
</div>
```

Change it to add `BulkUpdatePluginsButton` BELOW `SyncAllSitesButton`:

```tsx
<div className="flex items-start justify-between">
  <h1 className="text-xl font-semibold">Overview</h1>
  <div className="flex flex-col items-end gap-1">
    <p className="text-xs text-muted-foreground">
      Last refreshed: {formatRelativeTime(data.generated_at)}
    </p>
    <SyncAllSitesButton totalSites={data.total_sites} />
    <BulkUpdatePluginsButton pendingCount={data.pending_updates.plugins} />
  </div>
</div>
```

Add the import at the top of `Overview.tsx`:

```tsx
import { BulkUpdatePluginsButton } from '@/components/overview/BulkUpdatePluginsButton'
```

### Step 4: Run tests to verify they pass

```
cd apps/web && pnpm test -- --run "BulkUpdatePluginsButton ConfirmBulkUpdatePluginsDialog useBulkUpdatePlugins usePendingPluginUpdates"
```
Expected: PASS — all new component/hook tests green.

Run the full suite:
```
cd apps/web && pnpm test -- --run
cd apps/web && pnpm lint
```
Expected: PASS modulo the 4 documented pre-existing carry-forward failures. NOTHING ELSE NEW.

### Step 5: Commit

```bash
git -C "/Users/pradeep/Local Sites/defynWP" add apps/web/src/components/overview/BulkUpdatePluginsButton.tsx \
        apps/web/src/routes/Overview.tsx \
        apps/web/tests/components/overview/BulkUpdatePluginsButton.test.tsx
git -C "/Users/pradeep/Local Sites/defynWP" commit -m "feat(p2-7): BulkUpdatePluginsButton on Overview header

Hidden entirely when pendingCount === 0 (NOT just disabled — spec § 3.1).
Idle: outline button + Settings icon + dynamic count. Pending: spinner +
'Scheduling N updates…' (disabled). Wires usePendingPluginUpdates query
hook (enabled-on-open) + useBulkUpdatePlugins mutation. Overview.tsx
renders below SyncAllSitesButton. Per spec § 3."
```

---

## Task 10 — Build zips + 8-step manual smoke matrix

**Files:** none (build + smoke playbook — exact mirror of spec § 5.2).

Do NOT proceed to Task 11 unless all 8 smoke steps are green.

- [ ] **Step 1: Confirm all suites green**

```
cd packages/dashboard-plugin && composer test
cd apps/web && pnpm test -- --run
cd apps/web && pnpm lint
```
Expected: ALL PASS modulo the 3 PHP + 4 SPA documented carry-forward failures.

- [ ] **Step 2: Build dashboard zip (v0.8.0)**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin"
composer install --no-dev --classmap-authoritative
rm -f ~/Desktop/defyn-dashboard-v0.8.0-$(date +%Y-%m-%d).zip
zip -rq ~/Desktop/defyn-dashboard-v0.8.0-$(date +%Y-%m-%d).zip . \
  -x "tests/*" "node_modules/*" "*.git*" "phpunit.xml*" "*.lock" \
     "vendor/wordpress/*" "vendor/johnpbloch/*" \
     "*wp-tests-config.php" "*.phpunit.result.cache"
ls -lah ~/Desktop/defyn-dashboard-v0.8.0-$(date +%Y-%m-%d).zip
composer install
```
Target zip size: ~552KB. If dramatically larger, the dev-prune didn't take.

- [ ] **Step 3: Build SPA**

```bash
cd "/Users/pradeep/Local Sites/defynWP/apps/web"
pnpm build
ls -lah dist/index.html dist/assets/*.js | head -3
```

- [ ] **Step 4: Install on production**

1. Upload the dashboard zip to `defynwp.defyn.agency` via Plugins → Add New → Upload → Replace current with uploaded version.
2. **Clear MyKinsta cache** (Tools → Clear cache). Plan-bug trap #18.
3. Push branch + main:
   ```
   git push origin p2-7-bulk-plugin-updates
   git checkout main && git merge --ff-only p2-7-bulk-plugin-updates && git push origin main
   git checkout p2-7-bulk-plugin-updates
   ```
4. Watch Cloudflare Pages for deploy completion (1-3 min).

- [ ] **Step 5: PREREQUISITE — ensure SmartCoding (or some site) is registered**

P2.6 smoke ran in zero-sites state. P2.7's full happy path needs at least one connected site with `update_available = 1` plugins:

- Visit `https://app.defynwp.defyn.agency/sites/add` and re-register SmartCoding if needed.
- Confirm `/sites` returns a non-empty array.
- Trigger a plugin inventory sync if necessary so `update_available = 1` rows exist.

If you smoke without this prerequisite, steps 4 + 7 + 8 will return zero-pair / 200 responses instead of the full 202 happy path. Document what you observe instead of skipping.

- [ ] **Step 6: Run the 8-step smoke matrix from spec § 5.2 verbatim**

```bash
TOKEN=$(curl -s -X POST https://defynwp.defyn.agency/wp-json/defyn/v1/auth/login \
  -H "Content-Type: application/json" \
  --data '{"email":"pradeep@defyn.com.au","password":"DefynWP-ifirCh5pXm5bTOj0"}' \
  | python3 -c "import sys,json; print(json.load(sys.stdin)['access_token'])")
```

| # | Action | Expected |
|---|---|---|
| 1 | `curl -H "Authorization: Bearer $TOKEN" "https://defynwp.defyn.agency/wp-json/defyn/v1/overview/pending-plugin-updates?_=$RANDOM"` | 200 + `{pending_updates: [...], generated_at}` |
| 2 | Same GET WITHOUT `Authorization` header | 401 `auth.missing_token` |
| 3 | `curl -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" --data '{"updates":[]}' "https://defynwp.defyn.agency/wp-json/defyn/v1/overview/bulk-update-plugins?_=$RANDOM"` | 400 `bulk.empty_updates` |
| 4 | POST with 1 valid pair (use a slug from step 1's response): `{"updates":[{"site_id":<id>,"slug":"<slug>"}]}` | 202 + `{scheduled_count:1, skipped_count:0, scheduled_pairs:[...], skipped_pairs:[], scheduled_at}` |
| 5 | POST with 1 invalid pair: `{"updates":[{"site_id":<id>,"slug":"does-not-exist"}]}` | 200 + `{scheduled_count:0, skipped_count:1, skipped_pairs:[{site_id:<id>,slug:"does-not-exist",reason:"plugin_not_found"}], scheduled_at}`. **Activity log: zero new `overview.bulk_plugin_update_requested` rows** |
| 6 | 6× POST from same user within 1 hour (use `?_=$RANDOM` to defeat Kinsta edge cache) | 6th returns 429 `bulk.rate_limited` |
| 7 | `curl -H "Authorization: Bearer $TOKEN" "https://defynwp.defyn.agency/wp-json/defyn/v1/activity?per_page=10&_=$RANDOM"` after step 4 | Within ~30-90s: `overview.bulk_plugin_update_requested` event (site_id=null, details:{scheduled_count:1, pairs:[...]}) + downstream `plugin_update.requested → started → succeeded\|failed` triplet |
| 8 | SPA at `/overview` → click "Bulk update plugins (N)" → uncheck 2 pairs → confirm | Dialog opens, footer "N-2 selected of N available", RED primary "Bulk update {N-2} plugins" enabled, click → spinner → activity widget shows new fleet event within 60s |

Document each step's outcome inline (PASS / FAIL with output snippet). If any fails, STOP and file `fix(p2-7):` commits before re-running from the failed step.

- [ ] **Step 7: Cleanup** — none. Rate-limit transient expires on its own.

- [ ] **Step 8: Commit (only if any fix commits were needed)**

If smoke was green on first run, this task creates no new commits.

---

## Task 11 — Tag + push + MEMORY

**Files:** none (git tag + MEMORY.md update).

ONLY run after Task 10's smoke is fully green. **NEVER tag if any smoke step failed.**

- [ ] **Step 1: Verify suites green + tree clean**

```bash
cd "/Users/pradeep/Local Sites/defynWP" && git status
cd packages/dashboard-plugin && composer test
cd ../../apps/web && pnpm test -- --run
cd "/Users/pradeep/Local Sites/defynWP/apps/web" && pnpm lint
```
Expected: ALL PASS (or same carry-forward) + working tree clean.

- [ ] **Step 2: Create the annotated tag**

```bash
cd "/Users/pradeep/Local Sites/defynWP"
git tag -a p2-7-bulk-plugin-updates-complete -m "P2.7 — Bulk plugin updates across fleet shipped

- Dashboard v0.8.0: TWO new endpoints —
  - GET /defyn/v1/overview/pending-plugin-updates (30/MIN) — flat list of
    eligible (site, plugin) pairs for the SPA confirm dialog
  - POST /defyn/v1/overview/bulk-update-plugins (5/HR) — validates each
    pair, fan-outs the existing P2.2 defyn_update_site_plugin AS job per
    valid pair, emits ONE fleet-scoped overview.bulk_plugin_update_requested
    activity event (site_id=null) ONLY when scheduled_count > 0
- SitePluginsRepository::findAllPendingUpdatesForUser — JOIN'd query
- RateLimit gains OVERVIEW_PENDING_PLUGIN_UPDATES_* + BULK_PLUGIN_UPDATE_*
- SPA: BulkUpdatePluginsButton on Overview header (hidden when count=0),
  RED-tier ConfirmBulkUpdatePluginsDialog with per-site collapsible
  PendingPluginUpdatesGroup, all-pre-checked checkboxes, useMemo footer
  counter, Cancel default focus
- usePendingPluginUpdates query hook (enabled-on-open, NOT polling)
- useBulkUpdatePlugins mutation hook (invalidates ['overview'] +
  ['pendingPluginUpdates'], NOT ['sites'])
- No connector changes (stays at v0.1.7), no schema changes (stays at v6)
- Spec: docs/superpowers/specs/2026-06-09-p2-7-bulk-plugin-updates-design.md
"
```

- [ ] **Step 3: Push the tag**

```bash
git push origin p2-7-bulk-plugin-updates-complete
```

- [ ] **Step 4: Update MEMORY**

Append to `~/.claude/projects/-Users-pradeep-Local-Sites-defynWP/memory/project_defyn_overview.md`:

> "P2.7 (Bulk plugin updates across fleet) COMPLETE 2026-06-09 — tag `p2-7-bulk-plugin-updates-complete`, dashboard v0.8.0 live in prod. POST /defyn/v1/overview/bulk-update-plugins fan-outs the P2.2 UpdateSitePlugin AS job per (site, slug) pair, 5/HOUR per user. GET /defyn/v1/overview/pending-plugin-updates lists eligible pairs for the SPA dialog, 30/MIN per user. Single overview.bulk_plugin_update_requested fleet event. Connector unchanged at v0.1.7. Schema unchanged at v6. Next: P2.7.1 (minor-only filter) or P2.8 (bulk theme updates)."

Also append a P2.7 plan-bug summary block to `MEMORY.md` for any new lessons surfaced during execution.

---

## Self-review — spec coverage

Walking the spec sections to confirm every requirement maps to a task:

- **Spec § 1 architecture** — covered collectively across Tasks 1-9.
- **Spec § 2.1 GET endpoint + 30/MIN + Zod shape** — Task 2 (controller + RateLimit + route) + Task 5 (Zod) + Task 1 (repository).
- **Spec § 2.2 POST endpoint + 5/HR + 202/200/400/429 envelopes** — Task 3.
- **Spec § 2.3 controller pseudocode (validation + skip reasons + AS hook + activity event)** — Task 3 controller implementation.
- **Spec § 2.4 file structure (5 dashboard files)** — Tasks 1-3 + Task 4 release files.
- **Spec § 2.5 activity event contract** — Task 3 controller + test `testActivityEventEmittedWithCorrectDetails`.
- **Spec § 2.6 tests (~12 PHP)** — Task 1 (2) + Task 2 (4) + Task 3 (8) = 14 (above target).
- **Spec § 2.7 version bump** — Task 4.
- **Spec § 3.1 placement (header column)** — Task 9 Overview.tsx integration.
- **Spec § 3.2 button states (idle, pending, success, error)** — Task 9 button component (Idle + Pending). The "success label for 3s" sub-state is rendered implicitly by the brief mutation lifecycle. Error toast is surfaced via the existing global `ApiError` handler in `App.tsx`, same approach as P2.6.
- **Spec § 3.3 confirm dialog content + RED primary + per-site groups + footer counter** — Task 8 dialog + sub-component.
- **Spec § 3.4 SPA files** — Tasks 5-9.
- **Spec § 3.5 mutation hook contract** — Task 7.
- **Spec § 3.6 SPA tests (~9)** — Task 6 (2) + Task 7 (2) + Task 8 (4) + Task 9 (4) = 12 (above target).
- **Spec § 4 testing strategy** — sum of per-task tests = ~26 (above 21 target).
- **Spec § 5 manual smoke flow (8 steps)** — Task 10.
- **Spec § 6 out of scope** — N/A (informational).
- **Spec § 7 plan-author notes (19 plan-bug traps)** — all 19 surfaced in workflow conventions block at top.
- **Spec § 8 acceptance criteria** — Task 11.

All sections covered. ✅

## Self-review — placeholder scan

Searched for `TBD`, `TODO`, `implement later`, `fill in`, `similar to`, "add appropriate" — none present in concrete code blocks. The "If your environment uses..." text in Task 4 Step 2 is an explicit one-of-two-known-patterns instruction, not a placeholder.

## Self-review — type consistency

- `BulkUpdatePluginsResponse` shape `{scheduled_count, skipped_count, scheduled_pairs, skipped_pairs[{site_id, slug, reason}], scheduled_at}` consistent across Task 3 PHP controller, Task 3 tests, Task 5 Zod, Task 7 hook tests.
- `PendingPluginUpdateRow` shape `{site_id: int, site_label: string, slug: string, plugin_name: string, current_version: string, target_version: string | null}` consistent across Task 1 PHP, Task 2 PHP test, Task 5 Zod, Task 6 hook test, Task 8 dialog props.
- `BULK_PLUGIN_UPDATE_LIMIT = 5` + `HOUR_IN_SECONDS` consistent in Task 3 RateLimit + test name `testRateLimit429AfterSixthCall` + plan-bug trap #1.
- `OVERVIEW_PENDING_PLUGIN_UPDATES_LIMIT = 30` + `MINUTE_IN_SECONDS` consistent in Task 2 RateLimit + test name `testRateLimit429AfterThirtyFirstCall` + plan-bug trap #2.
- Activity event string `overview.bulk_plugin_update_requested` consistent across Task 3 controller, Task 3 test, Task 10 smoke step 7, plan-bug trap #3.
- `useBulkUpdatePlugins(): UseMutationResult<BulkUpdatePluginsResponse, Error, BulkUpdatePluginsRequest>` signature consistent across Task 7 export, Task 7 test, Task 9 button consumer.
- `usePendingPluginUpdates(dialogOpen: boolean)` signature consistent across Task 6 + Task 9 consumer.

No drift. ✅

---

## Execution handoff

**Plan complete and saved to `docs/superpowers/plans/2026-06-09-p2-7-bulk-plugin-updates.md`. Two execution options:**

**1. Subagent-Driven (recommended)** — Fresh subagent per task, two-stage review (spec compliance + code quality), same-session iteration. What every prior P2.x phase used.

**2. Inline Execution** — Execute tasks in this session via the executing-plans skill, batch with checkpoints.

**Which approach?**
