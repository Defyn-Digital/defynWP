# P2.8 — Bulk theme updates across fleet Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a "Bulk update themes (N)" button on the Operator Overview at `/overview` that lets the operator review + selectively uncheck (site, theme) pairs in a destructive-tier confirmation dialog (with day-1 "Skip major bumps" toggle), then fan-out the existing P2.3 `defyn_update_site_theme` AS job per confirmed pair. Two new REST endpoints, dashboard v0.8.1, no connector changes, no schema changes.

**Architecture:** ONE new `GET /defyn/v1/overview/pending-theme-updates` (30/MIN per user) returns the flat list for the dialog. ONE new `POST /defyn/v1/overview/bulk-update-themes` (5/HR per user) validates each `(site_id, slug)` pair, fan-outs `as_schedule_single_action('defyn_update_site_theme', [siteId, slug, 0], 'defyn')` per valid pair, emits ONE fleet-scoped `overview.bulk_theme_update_requested` activity event (site_id=null), and returns 202 (or 200 if all skipped) with `{scheduled_count, skipped_count, scheduled_pairs, skipped_pairs[{site_id,slug,reason}], scheduled_at}`. SPA gets a RED-tier confirm dialog (mirror of P2.7.1) with per-site collapsible groups, all-pre-checked checkboxes, useMemo-driven footer counter, day-1 "Skip major bumps" toggle (default OFF), and `enabled-on-open` query hook for the pairs list.

**Tech Stack:** PHP 8.1+ (PHPUnit, `WP_UnitTestCase` / `AbstractSchemaTestCase`), WordPress REST API, Action Scheduler, React 18 + TypeScript + TanStack Query v5 + Zod + Tailwind + shadcn/ui (`Button` variants `default`/`outline`/`ghost` — NO `destructive` variant, see plan-bug trap #1 below) + Vitest + React Testing Library + MSW.

**Spec:** [`docs/superpowers/specs/2026-06-09-p2-8-bulk-theme-updates-design.md`](../specs/2026-06-09-p2-8-bulk-theme-updates-design.md)

---

## Workflow conventions

- **Branch:** already on **`p2-8-bulk-theme-updates`** (current tip `ea3e1c1` — the just-committed P2.8 spec). Confirm with `git branch --show-current` before starting. Branch was created off `main` (== `c48e5fd` after P2.7.1 ff merge).
- **Each Task = one atomic commit.**
- **Test discipline (TDD):** Step 1 writes the failing test. Step 2 runs it and confirms it fails. Step 3 writes the implementation. Step 4 confirms it passes. Step 5 commits.
- **Test runners:**
  - Dashboard PHP: `cd packages/dashboard-plugin && composer test`
  - SPA: `cd apps/web && pnpm test -- --run`
- **Commit message format:** `<type>(p2-8): <description>` where `<type>` ∈ {feat, fix, refactor, docs, test, chore}.
- **All edits adhere to** `~/.claude/rules/common/coding-style.md` — immutability, KISS, DRY, YAGNI, error handling, no `console.log` / `var_dump` / `print_r`.
- **No connector changes.** Connector stays at **v0.1.7**. Smoke does NOT require connector reinstall.
- **No schema changes.** Schema stays at **v6**.

### Plan-bug traps to internalise before writing any code

1. **CRITICAL — Button `destructive` variant DOES NOT EXIST in `apps/web/src/components/ui/button.tsx`.** It only has `default`, `outline`, `ghost`. Use the P2.4.1/P2.7 pattern `<Button className="bg-red-600 hover:bg-red-700 text-white">`. Test assertion `primaryButtonUsesDestructiveStyling` checks for `bg-red-600` in the className.
2. **Activity event MUST NOT fire when `scheduled_count === 0`** — guard with `if (count($scheduled) > 0)`. Test `testZeroValidPairsReturns200AndNoActivityEvent` asserts NO row was written to `wp_defyn_activity_log` even when 3 pairs were processed (all skipped via the three distinct skip reasons).
3. **Activity event name MUST be EXACTLY** `overview.bulk_theme_update_requested` — singular `update`, not plural. Not `overview.bulk_theme_updates_requested`, not `theme.bulk_update_requested`, not `bulk_theme_update.requested`. Distinct from P2.7's `overview.bulk_plugin_update_requested`. Both `testActivityEventEmittedWithCorrectDetails` + smoke step 7 grep for this exact string.
4. **`site_id = null` on the activity event** (fleet-scoped — mirror of P2.6 + P2.7). The `pairs[]` array goes inside the JSON `details` column. `ActivityLogger::log(?int $userId, ?int $siteId, string $eventType, ?array $details = null, ?string $ipAddress = null): void` — pass `null` as the second positional arg.
5. **Bulk endpoint BYPASSES the per-(user, site, slug) `themesUpdate` 6/HOUR bucket.** Operator's explicit dialog confirmation IS the safety. Do NOT add preflight bucket checks — the bulk endpoint's own 5/HOUR cap is the rate-limit boundary.
6. **Mutation hook invalidates `['overview']` AND `['pendingThemeUpdates']` on success** — NOT `['sites']`. Per-site theme states refresh naturally as each `UpdateSiteTheme` AS job executes. Same reasoning as P2.7 plan-bug trap #11.
7. **`usePendingThemeUpdates` is enabled-only-on-dialog-open** — set `enabled: dialogOpen` on the TanStack query. NOT polling. Otherwise we'd hit the 30/MIN bucket from the SPA's existing 60s `/overview` poll noise.
8. **Bulk button HIDDEN entirely when `pendingCount === 0`** (returns `null`, NOT just disabled). Mirrors P2.7. Test `hiddenWhenPendingCountIsZero` asserts the button is NOT in the document at count=0.
9. **Skip-major toggle default `false` (opt-in).** Carry-forward from P2.7.1. Default OFF means the operator sees all rows by default and explicitly opts into hiding majors.
10. **`ROWS_WITH_MAJOR` test fixture SEPARATE from base `ROWS`** — do NOT modify the base fixture. Define both at top of the dialog test file. The 3 base tests use `ROWS` (no major bumps); the 3 skipMajor tests use `ROWS_WITH_MAJOR` (includes a Kadence 1.1.40 → 2.0.0 major bump).
11. **Toggle JSX placement: BETWEEN body explanatory `<div>` and per-site groups `<div>`** — NOT in footer, NOT in title. Carry-forward from P2.7.1.
12. **`allKeys`, `grouped`, `totalCount` ALL derive from `visibleRows`** — NOT `rows`. The filter flows through every derived value so the dialog title + footer counter + primary button label all reflect filtered counts.
13. **NO new `useEffect([skipMajor])`** — the existing `useEffect([open, allKeys])` re-fires indirectly because `allKeys` is derived from `visibleRows` which depends on `skipMajor`. Adding a separate effect causes double re-seeding.
14. **`isMajorBump` returns `false` for null/undefined/unparseable inputs** — defensive default. Helper renamed from `isPluginMajorBump` (P2.7.1) in Task 5; semantics unchanged.
15. **Activity event details `pairs` field key is `slug` NOT `stylesheet`** — the actual codebase + DB column is `slug`. The spec used "stylesheet" in places (WordPress terminology) but the column name in `wp_defyn_site_themes` is `slug` (verified via `Schema/SiteThemesTable.php` line 32). Use `slug` everywhere: PHP repository, REST request body, REST response, SPA Zod schema, SPA component props, test fixtures.
16. **Repository class is `ThemesRepository`** (NOT `SiteThemesRepository` — spec used the longer name but the actual file is `packages/dashboard-plugin/src/Services/ThemesRepository.php`). Add `findAllPendingUpdatesForUser(int $userId): array` as a new method appended after `healDanglingFailedStates` at line 264.
17. **Test fixtures must seed NOT NULL columns.** `defyn_site_themes` requires `last_seen_at DATETIME NOT NULL`, `created_at DATETIME NOT NULL`, `updated_at DATETIME NOT NULL`, `name VARCHAR(255) NOT NULL`, `site_id BIGINT UNSIGNED NOT NULL`, `slug VARCHAR(80) NOT NULL` (verified via `Schema/SiteThemesTable.php` lines 30–44). `defyn_sites` requires `updated_at DATETIME NOT NULL`. Plan-author has read these migrations.
18. **Test isolation:** any new test class extending `AbstractSchemaTestCase` that seeds `defyn_site_themes` MUST call `$this->freshlyActivate('defyn_site_themes')` + explicit `DELETE FROM` in `setUp()`. The transaction rollback in `WP_UnitTestCase` doesn't cover custom plugin tables. Carry-forward from P2.6 plan-bug #1 + P2.7 plan-bug #2.
19. **Defensive `ob_start()` + `ob_end_clean()` in try/finally** in BOTH new controllers — P2.2 plan-bug #4 carry-forward.
20. **`RestRouter` registration** for the two new routes goes **immediately after** the existing `/overview/bulk-update-plugins` POST registration (P2.7 added it) and **BEFORE** the `/activity` GET. Plan-bug trap from P2.6/P2.7.
21. **Per-site group checkbox in dialog** toggles all child checkboxes. Footer counter "X selected of Y available" must update via React state, computed via `useMemo` (not derived on every render).
22. **Dashboard zip build:** `composer install --no-dev --classmap-authoritative` FIRST. Exclude `vendor/wordpress/*` + `vendor/johnpbloch/*` + `*wp-tests-config.php` + `*.phpunit.result.cache` (P2.6 + P2.7 carry-forward). Target ~552KB.
23. **MyKinsta cache clear** after install — every P2.x phase carry-forward. After uploading the v0.8.1 zip via WP Admin "Replace current," click MyKinsta → Tools → Clear cache (busts OPcache + page cache + Redis).
24. **Final smoke matrix is § 5 of the spec verbatim — 8 steps.** Tag `p2-8-bulk-theme-updates-complete` ONLY after all 8 pass (or after the SPA/test-coverage fallback if prod sites table empty per the carry-forward).

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

- `packages/dashboard-plugin/src/Services/ThemesRepository.php` — most recent additions: `findAllForSite(int $siteId): array` at line 25, `findRowForSiteAndSlug(int $siteId, string $slug): ?array` at line 53, `healDanglingFailedStates(int $siteId, string $now): int` at line 250. Append new `findAllPendingUpdatesForUser` method after `healDanglingFailedStates`.
- `packages/dashboard-plugin/src/Services/SitePluginsRepository.php` — `findAllPendingUpdatesForUser(int $userId): array` at line 291 is the EXACT template for our themes equivalent (substitute `defyn_site_plugins` → `defyn_site_themes`, `plugin_name` → `theme_name`).
- `packages/dashboard-plugin/src/Services/SitesRepository.php` — `findByIdForUser(int $id, int $userId): ?Site` for ownership checks.
- `packages/dashboard-plugin/src/Schema/SiteThemesTable.php` — columns include `name VARCHAR(255) NOT NULL`, `slug VARCHAR(80) NOT NULL`, `version VARCHAR(50) NULL`, `update_version VARCHAR(50) NULL`, `update_available TINYINT(1) NOT NULL DEFAULT 0`. The GET SQL joins this with `defyn_sites`.
- `packages/dashboard-plugin/src/Rest/OverviewPendingPluginUpdatesController.php` — P2.7 reference controller; copy verbatim and substitute table/column names. Includes the `ob_start`/`ob_end_clean` STDOUT guard pattern.
- `packages/dashboard-plugin/src/Rest/OverviewBulkUpdatePluginsController.php` — P2.7 reference controller; copy verbatim and substitute `plugin` → `theme`, `slug` stays `slug`.
- `packages/dashboard-plugin/src/Jobs/UpdateSiteTheme.php` — existing P2.3 AS job. `HOOK = 'defyn_update_site_theme'` constant. Handler signature `handle(int $siteId, string $slug, int $attempt): void`.
- `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php` — P2.7 added `OVERVIEW_PENDING_PLUGIN_UPDATES_LIMIT/WINDOW` (30/MIN) + `BULK_PLUGIN_UPDATE_LIMIT/WINDOW` (5/HR). Append our new constants AFTER the `BULK_PLUGIN_UPDATE_*` block.
- `packages/dashboard-plugin/src/Rest/RestRouter.php` — P2.7 added `/overview/pending-plugin-updates` GET + `/overview/bulk-update-plugins` POST. New theme routes append AFTER those, BEFORE `/activity`.
- `apps/web/src/lib/queries/useOverview.ts` — TanStack hook with `queryKey: ['overview']`, polls every 60s.
- `apps/web/src/lib/queries/usePendingPluginUpdates.ts` — TanStack hook with `enabled: dialogOpen` (P2.7). Template for `usePendingThemeUpdates`.
- `apps/web/src/lib/mutations/useBulkUpdatePlugins.ts` — closest mutation hook template; copy verbatim and substitute plugins → themes.
- `apps/web/src/components/overview/BulkUpdatePluginsButton.tsx` (P2.7) — structural template for `BulkUpdateThemesButton`: HIDDEN-when-zero pattern (`if (pendingCount === 0) return null;`).
- `apps/web/src/components/overview/ConfirmBulkUpdatePluginsDialog.tsx` (P2.7.1) — structural template for `ConfirmBulkUpdateThemesDialog`, including the day-1 `skipMajor` state + `visibleRows` useMemo + toggle JSX.
- `apps/web/src/components/overview/PendingPluginUpdatesGroup.tsx` (P2.7) — structural template for `PendingThemeUpdatesGroup`.
- `apps/web/src/lib/semver.ts` — exports `isPluginMajorBump` (P2.7.1). Task 5 renames to `isMajorBump`.
- `apps/web/src/routes/Overview.tsx` — header layout. P2.7 added `<BulkUpdatePluginsButton pendingCount={data.pending_updates.plugins} />`. P2.8 adds `<BulkUpdateThemesButton pendingCount={data.pending_updates.themes} />` AFTER it.
- `apps/web/src/types/api.ts` — existing schemas. Append `pendingThemeUpdatesSchema` + `bulkUpdateThemesResponseSchema`.
- `apps/web/src/test/handlers.ts` — MSW handlers array. Append handlers for the two new endpoints near the existing `/overview/bulk-update-plugins` POST handler.

---

## File structure overview

### Dashboard plugin (v0.8.1) — new files

| Path | Responsibility |
|---|---|
| `src/Rest/OverviewPendingThemeUpdatesController.php` | GET endpoint — flat list of eligible pairs |
| `src/Rest/OverviewBulkUpdateThemesController.php` | POST endpoint — validate + fan-out + fleet activity event |
| `tests/Integration/Rest/OverviewPendingThemeUpdatesControllerTest.php` | 5 tests (auth, happy with rows, happy empty, rate limit, ownership) |
| `tests/Integration/Rest/OverviewBulkUpdateThemesControllerTest.php` | 8 tests (auth, happy, empty body, rate limit, skip reasons, fan-out, activity event, zero-pairs no-log) |
| `tests/Integration/Services/ThemesRepositoryPendingUpdatesTest.php` | 3 tests (correct rows, cross-user isolation, exclude no-update-available) |
| `tests/Integration/Rest/OverviewPendingThemeUpdatesCorsTest.php` | CORS preflight regression |
| `tests/Integration/Rest/OverviewBulkUpdateThemesCorsTest.php` | CORS preflight regression |

### Dashboard plugin — modified files

| Path | What changes |
|---|---|
| `src/Services/ThemesRepository.php` | Add `findAllPendingUpdatesForUser(int $userId): array` |
| `src/Rest/Middleware/RateLimit.php` | Add `OVERVIEW_PENDING_THEME_UPDATES_LIMIT/WINDOW` (30/MIN) + `BULK_THEME_UPDATE_LIMIT/WINDOW` (5/HR) constants + 2 new permission methods |
| `src/Rest/RestRouter.php` | Register 2 new routes between `/overview/bulk-update-plugins` and `/activity` |
| `defyn-dashboard.php` | Version `0.8.0` → `0.8.1` |
| `readme.txt` | Stable tag + changelog entry |
| `composer.json` | Version `0.8.0` → `0.8.1` |

### SPA (apps/web) — new files

| Path | Responsibility |
|---|---|
| `src/components/overview/BulkUpdateThemesButton.tsx` | Button — HIDDEN when count=0, opens dialog |
| `src/components/overview/ConfirmBulkUpdateThemesDialog.tsx` | Modal — title, body, day-1 skipMajor toggle, checkbox state, footer counter, RED primary button, Cancel default focus |
| `src/components/overview/PendingThemeUpdatesGroup.tsx` | Per-site collapsible group with grouped checkbox + child rows |
| `src/lib/queries/usePendingThemeUpdates.ts` | TanStack query — `enabled: dialogOpen`, NOT polling |
| `src/lib/mutations/useBulkUpdateThemes.ts` | TanStack mutation — POSTs `/overview/bulk-update-themes`, invalidates `['overview']` + `['pendingThemeUpdates']` |
| `tests/components/overview/BulkUpdateThemesButton.test.tsx` | 2 tests (hidden at 0, visible with count) |
| `tests/components/overview/ConfirmBulkUpdateThemesDialog.test.tsx` | 7 tests (4 base + 3 skipMajor) |

### SPA — modified files

| Path | What changes |
|---|---|
| `src/lib/semver.ts` | Rename `isPluginMajorBump` → `isMajorBump` (Task 5) |
| `tests/lib/semver.test.ts` | Rename import + 8 test descriptions reference `isMajorBump` (Task 5) |
| `src/components/overview/ConfirmBulkUpdatePluginsDialog.tsx` | Update 1 import line (Task 5) |
| `src/types/api.ts` | Append `pendingThemeUpdatesSchema` + `bulkUpdateThemesResponseSchema` (+ types) |
| `src/test/handlers.ts` | Add 2 MSW handlers for new endpoints |
| `src/routes/Overview.tsx` | Render `<BulkUpdateThemesButton pendingCount={data.pending_updates.themes} />` below `BulkUpdatePluginsButton` |

---

## Task 1 — `ThemesRepository::findAllPendingUpdatesForUser`

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/ThemesRepository.php` (append new method after line 264)
- Test: `packages/dashboard-plugin/tests/Integration/Services/ThemesRepositoryPendingUpdatesTest.php` (CREATE)

- [ ] **Step 1: Write the failing tests**

Create `packages/dashboard-plugin/tests/Integration/Services/ThemesRepositoryPendingUpdatesTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\ThemesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * P2.8 — Tests for ThemesRepository::findAllPendingUpdatesForUser.
 *
 * Mirrors P2.7's SitePluginsRepositoryPendingUpdatesTest with table-name swap.
 */
final class ThemesRepositoryPendingUpdatesTest extends AbstractSchemaTestCase
{
    private ThemesRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_sites');
        $this->freshlyActivate('defyn_site_themes');

        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_site_themes");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_sites");

        $this->repo = new ThemesRepository();
    }

    public function testFindAllPendingUpdatesForUserReturnsCorrectRowsAcrossSites(): void
    {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');

        $wpdb->insert("{$wpdb->prefix}defyn_sites", [
            'user_id'    => 1,
            'url'        => 'https://smartcoding.test',
            'label'      => 'SmartCoding',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $siteAId = (int) $wpdb->insert_id;

        $wpdb->insert("{$wpdb->prefix}defyn_sites", [
            'user_id'    => 1,
            'url'        => 'https://acmeblog.test',
            'label'      => 'AcmeBlog',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $siteBId = (int) $wpdb->insert_id;

        $wpdb->insert("{$wpdb->prefix}defyn_site_themes", [
            'site_id'          => $siteAId,
            'slug'             => 'astra',
            'name'             => 'Astra',
            'version'          => '4.6.3',
            'update_available' => 1,
            'update_version'   => '4.7.0',
            'last_seen_at'     => $now,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
        $wpdb->insert("{$wpdb->prefix}defyn_site_themes", [
            'site_id'          => $siteAId,
            'slug'             => 'twentytwentyfour',
            'name'             => 'Twenty TwentyFour',
            'version'          => '1.3',
            'update_available' => 0,
            'last_seen_at'     => $now,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
        $wpdb->insert("{$wpdb->prefix}defyn_site_themes", [
            'site_id'          => $siteBId,
            'slug'             => 'blocksy',
            'name'             => 'Blocksy',
            'version'          => '2.0.1',
            'update_available' => 1,
            'update_version'   => '2.0.2',
            'last_seen_at'     => $now,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        $rows = $this->repo->findAllPendingUpdatesForUser(1);

        $this->assertCount(2, $rows);
        $this->assertSame('AcmeBlog', $rows[0]['site_label']);
        $this->assertSame('blocksy', $rows[0]['slug']);
        $this->assertSame('Blocksy', $rows[0]['theme_name']);
        $this->assertSame('2.0.1', $rows[0]['current_version']);
        $this->assertSame('2.0.2', $rows[0]['target_version']);
        $this->assertSame('SmartCoding', $rows[1]['site_label']);
        $this->assertSame('astra', $rows[1]['slug']);
        $this->assertSame('Astra', $rows[1]['theme_name']);
    }

    public function testFindAllPendingUpdatesForUserExcludesOtherUsers(): void
    {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');

        $wpdb->insert("{$wpdb->prefix}defyn_sites", [
            'user_id'    => 1,
            'url'        => 'https://mine.test',
            'label'      => 'Mine',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $mineId = (int) $wpdb->insert_id;

        $wpdb->insert("{$wpdb->prefix}defyn_sites", [
            'user_id'    => 2,
            'url'        => 'https://theirs.test',
            'label'      => 'Theirs',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $theirsId = (int) $wpdb->insert_id;

        $wpdb->insert("{$wpdb->prefix}defyn_site_themes", [
            'site_id'          => $mineId,
            'slug'             => 'astra',
            'name'             => 'Astra',
            'version'          => '4.6.3',
            'update_available' => 1,
            'update_version'   => '4.7.0',
            'last_seen_at'     => $now,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
        $wpdb->insert("{$wpdb->prefix}defyn_site_themes", [
            'site_id'          => $theirsId,
            'slug'             => 'kadence',
            'name'             => 'Kadence',
            'version'          => '1.1.40',
            'update_available' => 1,
            'update_version'   => '1.2.0',
            'last_seen_at'     => $now,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        $rowsForUser1 = $this->repo->findAllPendingUpdatesForUser(1);
        $rowsForUser2 = $this->repo->findAllPendingUpdatesForUser(2);

        $this->assertCount(1, $rowsForUser1);
        $this->assertSame('astra', $rowsForUser1[0]['slug']);
        $this->assertCount(1, $rowsForUser2);
        $this->assertSame('kadence', $rowsForUser2[0]['slug']);
    }

    public function testFindAllPendingUpdatesForUserExcludesRowsWithoutAvailableUpdate(): void
    {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');

        $wpdb->insert("{$wpdb->prefix}defyn_sites", [
            'user_id'    => 1,
            'url'        => 'https://test.test',
            'label'      => 'Test',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $siteId = (int) $wpdb->insert_id;

        $wpdb->insert("{$wpdb->prefix}defyn_site_themes", [
            'site_id'          => $siteId,
            'slug'             => 'astra',
            'name'             => 'Astra',
            'version'          => '4.7.0',
            'update_available' => 0,
            'last_seen_at'     => $now,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        $rows = $this->repo->findAllPendingUpdatesForUser(1);

        $this->assertSame([], $rows);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd packages/dashboard-plugin && composer test -- --filter ThemesRepositoryPendingUpdatesTest`

Expected: 3 FAILED with `Error: Call to undefined method ThemesRepository::findAllPendingUpdatesForUser`.

- [ ] **Step 3: Add the method**

Append to `packages/dashboard-plugin/src/Services/ThemesRepository.php` (after `healDanglingFailedStates` at line 264, before the closing class brace):

```php
    /**
     * P2.8 — INNER JOIN'd query feeding the operator's bulk-update-themes dialog.
     *
     * Returns rows with keys: site_id, site_label, slug, theme_name,
     * current_version, target_version.
     *
     * @return list<array{site_id:int,site_label:string,slug:string,theme_name:string,current_version:string,target_version:?string}>
     */
    public function findAllPendingUpdatesForUser(int $userId): array
    {
        global $wpdb;
        $sitesTable  = $wpdb->prefix . 'defyn_sites';
        $themesTable = $wpdb->prefix . 'defyn_site_themes';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT s.id AS site_id, s.label AS site_label,
                    st.slug, st.name AS theme_name,
                    st.version AS current_version, st.update_version AS target_version
             FROM {$sitesTable} s
             INNER JOIN {$themesTable} st ON st.site_id = s.id
             WHERE s.user_id = %d
               AND st.update_available = 1
             ORDER BY s.label, st.name",
            $userId
        ), ARRAY_A);

        if (!is_array($rows)) {
            return [];
        }

        return array_map(static fn(array $row) => [
            'site_id'         => (int) $row['site_id'],
            'site_label'      => (string) $row['site_label'],
            'slug'            => (string) $row['slug'],
            'theme_name'      => (string) $row['theme_name'],
            'current_version' => (string) $row['current_version'],
            'target_version'  => $row['target_version'] !== null ? (string) $row['target_version'] : null,
        ], $rows);
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd packages/dashboard-plugin && composer test -- --filter ThemesRepositoryPendingUpdatesTest`

Expected: 3 PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Services/ThemesRepository.php \
        packages/dashboard-plugin/tests/Integration/Services/ThemesRepositoryPendingUpdatesTest.php
git commit -m "feat(p2-8): ThemesRepository::findAllPendingUpdatesForUser

INNER JOIN'd query feeding the bulk-update-themes dialog. Returns flat
list of {site_id, site_label, slug, theme_name, current_version,
target_version} for sites owned by the given user where
update_available = 1. Mirrors P2.7's plugins equivalent.

3 integration tests:
- Returns rows across sites ordered by site_label
- Excludes other users' sites
- Excludes rows without update_available = 1

Per spec § 2.1."
```

---

## Task 2 — `OverviewPendingThemeUpdatesController` + `RateLimit::overviewPendingThemeUpdates` (30/MIN) + route

**Files:**
- Create: `packages/dashboard-plugin/src/Rest/OverviewPendingThemeUpdatesController.php`
- Modify: `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php` (add constants + permission method)
- Modify: `packages/dashboard-plugin/src/Rest/RestRouter.php` (register route)
- Test: `packages/dashboard-plugin/tests/Integration/Rest/OverviewPendingThemeUpdatesControllerTest.php` (CREATE)

- [ ] **Step 1: Write the failing tests**

Create `packages/dashboard-plugin/tests/Integration/Rest/OverviewPendingThemeUpdatesControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

/**
 * P2.8 — Tests for GET /defyn/v1/overview/pending-theme-updates.
 *
 * Mirrors P2.7's OverviewPendingPluginUpdatesControllerTest with theme swap.
 */
final class OverviewPendingThemeUpdatesControllerTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_sites');
        $this->freshlyActivate('defyn_site_themes');

        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_site_themes");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_sites");

        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_defyn_rl_overviewPendingThemeUpdates_%' OR option_name LIKE '\\_transient\\_timeout\\_defyn_rl_overviewPendingThemeUpdates_%'");

        do_action('rest_api_init');
    }

    public function testAuthRequiredReturns401WhenNoBearerToken(): void
    {
        $request  = new WP_REST_Request('GET', '/defyn/v1/overview/pending-theme-updates');
        $response = rest_do_request($request);

        $this->assertSame(401, $response->get_status());
    }

    public function testHappyPath200WithFlatList(): void
    {
        $userId = self::factory()->user->create(['role' => 'administrator']);
        $now    = gmdate('Y-m-d H:i:s');

        global $wpdb;
        $wpdb->insert("{$wpdb->prefix}defyn_sites", [
            'user_id'    => $userId,
            'url'        => 'https://smartcoding.test',
            'label'      => 'SmartCoding',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $siteId = (int) $wpdb->insert_id;

        $wpdb->insert("{$wpdb->prefix}defyn_site_themes", [
            'site_id'          => $siteId,
            'slug'             => 'astra',
            'name'             => 'Astra',
            'version'          => '4.6.3',
            'update_available' => 1,
            'update_version'   => '4.7.0',
            'last_seen_at'     => $now,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        wp_set_current_user($userId);
        $token   = (new \Defyn\Dashboard\Auth\JwtTokenIssuer())->issue($userId);
        $request = new WP_REST_Request('GET', '/defyn/v1/overview/pending-theme-updates');
        $request->set_header('Authorization', 'Bearer ' . $token);

        $response = rest_do_request($request);
        $data     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertArrayHasKey('pending_updates', $data);
        $this->assertArrayHasKey('generated_at', $data);
        $this->assertCount(1, $data['pending_updates']);
        $row = $data['pending_updates'][0];
        $this->assertSame($siteId, $row['site_id']);
        $this->assertSame('SmartCoding', $row['site_label']);
        $this->assertSame('astra', $row['slug']);
        $this->assertSame('Astra', $row['theme_name']);
        $this->assertSame('4.6.3', $row['current_version']);
        $this->assertSame('4.7.0', $row['target_version']);
    }

    public function testHappyPath200EmptyListWhenNoThemesPending(): void
    {
        $userId = self::factory()->user->create(['role' => 'administrator']);

        wp_set_current_user($userId);
        $token   = (new \Defyn\Dashboard\Auth\JwtTokenIssuer())->issue($userId);
        $request = new WP_REST_Request('GET', '/defyn/v1/overview/pending-theme-updates');
        $request->set_header('Authorization', 'Bearer ' . $token);

        $response = rest_do_request($request);
        $data     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame([], $data['pending_updates']);
    }

    public function testRateLimit429AfterThirtyFirstCall(): void
    {
        $userId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($userId);
        $token = (new \Defyn\Dashboard\Auth\JwtTokenIssuer())->issue($userId);

        for ($i = 1; $i <= 30; $i++) {
            $request = new WP_REST_Request('GET', '/defyn/v1/overview/pending-theme-updates');
            $request->set_header('Authorization', 'Bearer ' . $token);
            $response = rest_do_request($request);
            $this->assertSame(200, $response->get_status(), "Call #{$i} should be 200, got {$response->get_status()}");
        }

        $request = new WP_REST_Request('GET', '/defyn/v1/overview/pending-theme-updates');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $response = rest_do_request($request);
        $this->assertSame(429, $response->get_status(), 'Call #31 should be 429');
    }

    public function testOwnershipScopingExcludesOtherUsersSites(): void
    {
        $myUserId    = self::factory()->user->create(['role' => 'administrator']);
        $otherUserId = self::factory()->user->create(['role' => 'administrator']);
        $now         = gmdate('Y-m-d H:i:s');

        global $wpdb;
        $wpdb->insert("{$wpdb->prefix}defyn_sites", [
            'user_id'    => $otherUserId,
            'url'        => 'https://theirs.test',
            'label'      => 'Theirs',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $theirSiteId = (int) $wpdb->insert_id;

        $wpdb->insert("{$wpdb->prefix}defyn_site_themes", [
            'site_id'          => $theirSiteId,
            'slug'             => 'kadence',
            'name'             => 'Kadence',
            'version'          => '1.1.40',
            'update_available' => 1,
            'update_version'   => '1.2.0',
            'last_seen_at'     => $now,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        wp_set_current_user($myUserId);
        $token   = (new \Defyn\Dashboard\Auth\JwtTokenIssuer())->issue($myUserId);
        $request = new WP_REST_Request('GET', '/defyn/v1/overview/pending-theme-updates');
        $request->set_header('Authorization', 'Bearer ' . $token);

        $response = rest_do_request($request);
        $data     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame([], $data['pending_updates']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd packages/dashboard-plugin && composer test -- --filter OverviewPendingThemeUpdatesControllerTest`

Expected: 5 FAILED with 404 on the route (controller + route not yet wired).

- [ ] **Step 3: Create controller + RateLimit method + route registration**

Create `packages/dashboard-plugin/src/Rest/OverviewPendingThemeUpdatesController.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Services\ThemesRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P2.8 — GET /defyn/v1/overview/pending-theme-updates.
 *
 * Feeds the SPA's ConfirmBulkUpdateThemesDialog with the flat list of
 * eligible (site, theme) pairs for the authenticated operator.
 *
 * Mirrors P2.7's OverviewPendingPluginUpdatesController with theme swap.
 */
final class OverviewPendingThemeUpdatesController
{
    public function __construct(
        private readonly ThemesRepository $themes = new ThemesRepository(),
    ) {
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        // Defensive STDOUT guard — P2.2 plan-bug #4 carry-forward (trap #19).
        ob_start();
        try {
            $userId = (int) $request->get_param('_authenticated_user_id');
            $rows   = $this->themes->findAllPendingUpdatesForUser($userId);

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

Modify `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php` — find the `BULK_PLUGIN_UPDATE_LIMIT` constant (added in P2.7) and append AFTER that block:

```php
    public const OVERVIEW_PENDING_THEME_UPDATES_LIMIT  = 30;
    public const OVERVIEW_PENDING_THEME_UPDATES_WINDOW = MINUTE_IN_SECONDS;
```

Find the `bulkPluginUpdate(WP_REST_Request $request): bool` method (added in P2.7) and append AFTER it:

```php
    public function overviewPendingThemeUpdates(WP_REST_Request $request): bool
    {
        $userId = $this->resolveUserId($request);
        if ($userId === null) {
            return false;
        }
        return $this->check(
            "overviewPendingThemeUpdates_{$userId}",
            self::OVERVIEW_PENDING_THEME_UPDATES_LIMIT,
            self::OVERVIEW_PENDING_THEME_UPDATES_WINDOW,
        );
    }
```

Modify `packages/dashboard-plugin/src/Rest/RestRouter.php` — append the GET registration AFTER the existing `/overview/bulk-update-plugins` POST (P2.7 added it), BEFORE the `/activity` GET. Add:

```php
        register_rest_route('defyn/v1', '/overview/pending-theme-updates', [
            'methods'             => 'GET',
            'callback'            => [new OverviewPendingThemeUpdatesController(), 'handle'],
            'permission_callback' => function ($request) {
                if (!(new AuthMiddleware())->authenticate($request)) {
                    return new \WP_Error('rest_forbidden', 'Bearer token required', ['status' => 401]);
                }
                if (!(new Middleware\RateLimit())->overviewPendingThemeUpdates($request)) {
                    return new \WP_Error('rate_limited', 'Too many requests', ['status' => 429]);
                }
                return true;
            },
        ]);
```

Add the import at the top of `RestRouter.php` (alongside the existing P2.7 controllers):

```php
use Defyn\Dashboard\Rest\OverviewPendingThemeUpdatesController;
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd packages/dashboard-plugin && composer test -- --filter OverviewPendingThemeUpdatesControllerTest`

Expected: 5 PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Rest/OverviewPendingThemeUpdatesController.php \
        packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php \
        packages/dashboard-plugin/src/Rest/RestRouter.php \
        packages/dashboard-plugin/tests/Integration/Rest/OverviewPendingThemeUpdatesControllerTest.php
git commit -m "feat(p2-8): GET /overview/pending-theme-updates endpoint

Returns flat list of {site_id, site_label, slug, theme_name,
current_version, target_version} for sites owned by the authenticated
user where update_available = 1. 30/MINUTE rate limit (first per-minute
bucket for themes).

5 integration tests:
- Auth required → 401
- Happy path 200 with flat list
- Happy path 200 with empty list
- Rate limit 429 after 31st call
- Ownership scoping excludes other users' sites

Per spec § 2.1."
```

---

## Task 3 — `OverviewBulkUpdateThemesController` + `RateLimit::bulkThemeUpdate` (5/HR) + route

**Files:**
- Create: `packages/dashboard-plugin/src/Rest/OverviewBulkUpdateThemesController.php`
- Modify: `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php` (add 2 constants + 1 method)
- Modify: `packages/dashboard-plugin/src/Rest/RestRouter.php` (register POST route)
- Test: `packages/dashboard-plugin/tests/Integration/Rest/OverviewBulkUpdateThemesControllerTest.php` (CREATE)

- [ ] **Step 1: Write the failing tests**

Create `packages/dashboard-plugin/tests/Integration/Rest/OverviewBulkUpdateThemesControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

/**
 * P2.8 — Tests for POST /defyn/v1/overview/bulk-update-themes.
 *
 * Mirror of P2.7's OverviewBulkUpdatePluginsControllerTest.
 */
final class OverviewBulkUpdateThemesControllerTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_sites');
        $this->freshlyActivate('defyn_site_themes');
        $this->freshlyActivate('defyn_activity_log');

        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_site_themes");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_sites");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_activity_log");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_defyn_rl_bulkThemeUpdate_%' OR option_name LIKE '\\_transient\\_timeout\\_defyn_rl_bulkThemeUpdate_%'");

        as_unschedule_all_actions('defyn_update_site_theme');

        do_action('rest_api_init');
    }

    public function testAuthRequiredReturns401WhenNoBearerToken(): void
    {
        $request = new WP_REST_Request('POST', '/defyn/v1/overview/bulk-update-themes');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode(['updates' => [['site_id' => 1, 'slug' => 'astra']]]));

        $response = rest_do_request($request);

        $this->assertSame(401, $response->get_status());
    }

    public function testHappyPath202WithScheduledPairs(): void
    {
        $userId = self::factory()->user->create(['role' => 'administrator']);
        $now    = gmdate('Y-m-d H:i:s');

        global $wpdb;
        $wpdb->insert("{$wpdb->prefix}defyn_sites", [
            'user_id'    => $userId,
            'url'        => 'https://smart.test',
            'label'      => 'Smart',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $siteId = (int) $wpdb->insert_id;

        $wpdb->insert("{$wpdb->prefix}defyn_site_themes", [
            'site_id'          => $siteId,
            'slug'             => 'astra',
            'name'             => 'Astra',
            'version'          => '4.6.3',
            'update_available' => 1,
            'update_version'   => '4.7.0',
            'last_seen_at'     => $now,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
        $wpdb->insert("{$wpdb->prefix}defyn_site_themes", [
            'site_id'          => $siteId,
            'slug'             => 'blocksy',
            'name'             => 'Blocksy',
            'version'          => '2.0.1',
            'update_available' => 1,
            'update_version'   => '2.0.2',
            'last_seen_at'     => $now,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        wp_set_current_user($userId);
        $token   = (new \Defyn\Dashboard\Auth\JwtTokenIssuer())->issue($userId);
        $request = new WP_REST_Request('POST', '/defyn/v1/overview/bulk-update-themes');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode([
            'updates' => [
                ['site_id' => $siteId, 'slug' => 'astra'],
                ['site_id' => $siteId, 'slug' => 'blocksy'],
            ],
        ]));

        $response = rest_do_request($request);
        $data     = $response->get_data();

        $this->assertSame(202, $response->get_status());
        $this->assertSame(2, $data['scheduled_count']);
        $this->assertSame(0, $data['skipped_count']);
        $this->assertCount(2, $data['scheduled_pairs']);
        $this->assertSame($siteId, $data['scheduled_pairs'][0]['site_id']);
        $this->assertSame('astra', $data['scheduled_pairs'][0]['slug']);
    }

    public function testEmptyUpdatesReturns400(): void
    {
        $userId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($userId);
        $token = (new \Defyn\Dashboard\Auth\JwtTokenIssuer())->issue($userId);

        $request = new WP_REST_Request('POST', '/defyn/v1/overview/bulk-update-themes');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode(['updates' => []]));

        $response = rest_do_request($request);
        $data     = $response->get_data();

        $this->assertSame(400, $response->get_status());
        $this->assertSame('bulk.empty_updates', $data['error']['code']);
    }

    public function testRateLimit429AfterSixthCall(): void
    {
        $userId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($userId);
        $token = (new \Defyn\Dashboard\Auth\JwtTokenIssuer())->issue($userId);

        for ($i = 1; $i <= 5; $i++) {
            $request = new WP_REST_Request('POST', '/defyn/v1/overview/bulk-update-themes');
            $request->set_header('Authorization', 'Bearer ' . $token);
            $request->set_header('Content-Type', 'application/json');
            $request->set_body(json_encode(['updates' => [['site_id' => 999, 'slug' => 'nonexistent']]]));

            $response = rest_do_request($request);
            $this->assertNotSame(429, $response->get_status(), "Call #{$i} should NOT be 429, got {$response->get_status()}");
        }

        $request = new WP_REST_Request('POST', '/defyn/v1/overview/bulk-update-themes');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode(['updates' => [['site_id' => 999, 'slug' => 'nonexistent']]]));

        $response = rest_do_request($request);
        $data     = $response->get_data();

        $this->assertSame(429, $response->get_status(), 'Call #6 should be 429');
        $this->assertSame('bulk.rate_limited', $data['error']['code']);
    }

    public function testSkipsPairsNotOwnedOrWithoutUpdate(): void
    {
        $myUserId    = self::factory()->user->create(['role' => 'administrator']);
        $otherUserId = self::factory()->user->create(['role' => 'administrator']);
        $now         = gmdate('Y-m-d H:i:s');

        global $wpdb;
        $wpdb->insert("{$wpdb->prefix}defyn_sites", [
            'user_id'    => $myUserId,
            'url'        => 'https://mine.test',
            'label'      => 'Mine',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $mineId = (int) $wpdb->insert_id;

        $wpdb->insert("{$wpdb->prefix}defyn_sites", [
            'user_id'    => $otherUserId,
            'url'        => 'https://theirs.test',
            'label'      => 'Theirs',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $theirsId = (int) $wpdb->insert_id;

        $wpdb->insert("{$wpdb->prefix}defyn_site_themes", [
            'site_id'          => $mineId,
            'slug'             => 'astra',
            'name'             => 'Astra',
            'version'          => '4.7.0',
            'update_available' => 0,
            'last_seen_at'     => $now,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        wp_set_current_user($myUserId);
        $token   = (new \Defyn\Dashboard\Auth\JwtTokenIssuer())->issue($myUserId);
        $request = new WP_REST_Request('POST', '/defyn/v1/overview/bulk-update-themes');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode([
            'updates' => [
                ['site_id' => $theirsId, 'slug' => 'astra'],
                ['site_id' => $mineId,   'slug' => 'missing-theme'],
                ['site_id' => $mineId,   'slug' => 'astra'],
            ],
        ]));

        $response = rest_do_request($request);
        $data     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame(0, $data['scheduled_count']);
        $this->assertSame(3, $data['skipped_count']);
        $this->assertSame([], $data['scheduled_pairs']);
        $this->assertCount(3, $data['skipped_pairs']);

        $reasons = array_column($data['skipped_pairs'], 'reason');
        $this->assertContains('site_not_owned', $reasons);
        $this->assertContains('theme_not_found', $reasons);
        $this->assertContains('no_update_available', $reasons);
    }

    public function testFanOutSchedulesPerPair(): void
    {
        $userId = self::factory()->user->create(['role' => 'administrator']);
        $now    = gmdate('Y-m-d H:i:s');

        global $wpdb;
        $wpdb->insert("{$wpdb->prefix}defyn_sites", [
            'user_id'    => $userId,
            'url'        => 'https://test.test',
            'label'      => 'Test',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $siteId = (int) $wpdb->insert_id;

        $wpdb->insert("{$wpdb->prefix}defyn_site_themes", [
            'site_id'          => $siteId,
            'slug'             => 'astra',
            'name'             => 'Astra',
            'version'          => '4.6.3',
            'update_available' => 1,
            'update_version'   => '4.7.0',
            'last_seen_at'     => $now,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        wp_set_current_user($userId);
        $token   = (new \Defyn\Dashboard\Auth\JwtTokenIssuer())->issue($userId);
        $request = new WP_REST_Request('POST', '/defyn/v1/overview/bulk-update-themes');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode([
            'updates' => [['site_id' => $siteId, 'slug' => 'astra']],
        ]));

        rest_do_request($request);

        $scheduled = as_get_scheduled_actions([
            'hook'   => 'defyn_update_site_theme',
            'status' => \ActionScheduler_Store::STATUS_PENDING,
        ]);
        $this->assertCount(1, $scheduled);
    }

    public function testActivityEventEmittedWithCorrectDetails(): void
    {
        $userId = self::factory()->user->create(['role' => 'administrator']);
        $now    = gmdate('Y-m-d H:i:s');

        global $wpdb;
        $wpdb->insert("{$wpdb->prefix}defyn_sites", [
            'user_id'    => $userId,
            'url'        => 'https://test.test',
            'label'      => 'Test',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $siteId = (int) $wpdb->insert_id;

        $wpdb->insert("{$wpdb->prefix}defyn_site_themes", [
            'site_id'          => $siteId,
            'slug'             => 'astra',
            'name'             => 'Astra',
            'version'          => '4.6.3',
            'update_available' => 1,
            'update_version'   => '4.7.0',
            'last_seen_at'     => $now,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        wp_set_current_user($userId);
        $token   = (new \Defyn\Dashboard\Auth\JwtTokenIssuer())->issue($userId);
        $request = new WP_REST_Request('POST', '/defyn/v1/overview/bulk-update-themes');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode([
            'updates' => [['site_id' => $siteId, 'slug' => 'astra']],
        ]));

        rest_do_request($request);

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}defyn_activity_log WHERE user_id = %d AND event_type = %s",
                $userId,
                'overview.bulk_theme_update_requested'
            ),
            ARRAY_A
        );

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['site_id'], 'Activity event must be fleet-scoped (site_id = null)');

        $details = json_decode($rows[0]['details'], true);
        $this->assertSame(1, $details['scheduled_count']);
        $this->assertSame(0, $details['skipped_count']);
        $this->assertCount(1, $details['pairs']);
        $this->assertSame($siteId, $details['pairs'][0]['site_id']);
        $this->assertSame('astra', $details['pairs'][0]['slug']);
    }

    public function testZeroValidPairsReturns200AndNoActivityEvent(): void
    {
        $userId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($userId);
        $token = (new \Defyn\Dashboard\Auth\JwtTokenIssuer())->issue($userId);

        $request = new WP_REST_Request('POST', '/defyn/v1/overview/bulk-update-themes');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode([
            'updates' => [
                ['site_id' => 999, 'slug' => 'nonexistent'],
                ['site_id' => 998, 'slug' => 'phantom'],
                ['site_id' => 997, 'slug' => 'ghost'],
            ],
        ]));

        $response = rest_do_request($request);
        $data     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame(0, $data['scheduled_count']);
        $this->assertSame(3, $data['skipped_count']);

        global $wpdb;
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}defyn_activity_log WHERE user_id = %d AND event_type = %s",
                $userId,
                'overview.bulk_theme_update_requested'
            )
        );

        $this->assertSame(0, $count, 'NO activity event row should be written when scheduled_count = 0 (guardrail #2)');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd packages/dashboard-plugin && composer test -- --filter OverviewBulkUpdateThemesControllerTest`

Expected: 8 FAILED with 404 on the route.

- [ ] **Step 3: Create controller + RateLimit method + route**

Create `packages/dashboard-plugin/src/Rest/OverviewBulkUpdateThemesController.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Services\ThemesRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P2.8 — POST /defyn/v1/overview/bulk-update-themes.
 *
 * Validates each {site_id, slug} pair, fan-outs the existing P2.3
 * defyn_update_site_theme AS job per valid pair, emits ONE fleet-scoped
 * overview.bulk_theme_update_requested activity event (site_id=null) ONLY
 * when scheduled_count > 0.
 *
 * BYPASSES the per-(user, site, slug) themesUpdate 6/HR bucket — operator's
 * explicit dialog confirmation IS the safety.
 *
 * Mirror of P2.7's OverviewBulkUpdatePluginsController.
 */
final class OverviewBulkUpdateThemesController
{
    public function __construct(
        private readonly SitesRepository $sites = new SitesRepository(),
        private readonly ThemesRepository $themes = new ThemesRepository(),
        private readonly ActivityLogger $logger = new ActivityLogger(),
    ) {
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        // Defensive STDOUT guard — P2.2 plan-bug #4 carry-forward (trap #19).
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
                $row = $this->themes->findRowForSiteAndSlug($siteId, $slug);
                if ($row === null) {
                    $skipped[] = ['site_id' => $siteId, 'slug' => $slug, 'reason' => 'theme_not_found'];
                    continue;
                }
                if ((int) ($row['update_available'] ?? 0) !== 1) {
                    $skipped[] = ['site_id' => $siteId, 'slug' => $slug, 'reason' => 'no_update_available'];
                    continue;
                }

                as_schedule_single_action(
                    time(),
                    'defyn_update_site_theme',
                    [$siteId, $slug, 0],
                    'defyn'
                );
                $scheduled[] = ['site_id' => $siteId, 'slug' => $slug];
            }

            // Guardrail #2 — activity event ONLY when scheduled_count > 0.
            if (count($scheduled) > 0) {
                $this->logger->log(
                    $userId,
                    null,                                          // Fleet-scoped (trap #4)
                    'overview.bulk_theme_update_requested',        // EXACT string (trap #3)
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

Modify `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php` — append after the `OVERVIEW_PENDING_THEME_UPDATES_*` constants (added in Task 2):

```php
    public const BULK_THEME_UPDATE_LIMIT  = 5;
    public const BULK_THEME_UPDATE_WINDOW = HOUR_IN_SECONDS;
```

Append after the `overviewPendingThemeUpdates(...)` method (added in Task 2):

```php
    public function bulkThemeUpdate(WP_REST_Request $request): bool
    {
        $userId = $this->resolveUserId($request);
        if ($userId === null) {
            return false;
        }
        return $this->check(
            "bulkThemeUpdate_{$userId}",
            self::BULK_THEME_UPDATE_LIMIT,
            self::BULK_THEME_UPDATE_WINDOW,
        );
    }
```

Modify `packages/dashboard-plugin/src/Rest/RestRouter.php` — append the POST registration AFTER the `/overview/pending-theme-updates` GET (added in Task 2), BEFORE the `/activity` GET:

```php
        register_rest_route('defyn/v1', '/overview/bulk-update-themes', [
            'methods'             => 'POST',
            'callback'            => [new OverviewBulkUpdateThemesController(), 'handle'],
            'permission_callback' => function ($request) {
                if (!(new AuthMiddleware())->authenticate($request)) {
                    return new \WP_Error('rest_forbidden', 'Bearer token required', ['status' => 401]);
                }
                if (!(new Middleware\RateLimit())->bulkThemeUpdate($request)) {
                    return new \WP_Error('bulk.rate_limited', 'Too many bulk update requests. Try again in an hour.', ['status' => 429]);
                }
                return true;
            },
        ]);
```

Add the import at the top of `RestRouter.php`:

```php
use Defyn\Dashboard\Rest\OverviewBulkUpdateThemesController;
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd packages/dashboard-plugin && composer test -- --filter OverviewBulkUpdateThemesControllerTest`

Expected: 8 PASS.

Sanity-check that nothing else broke:

Run: `cd packages/dashboard-plugin && composer test`

Expected: only the 3 pre-existing carry-forward failures. No new failures.

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Rest/OverviewBulkUpdateThemesController.php \
        packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php \
        packages/dashboard-plugin/src/Rest/RestRouter.php \
        packages/dashboard-plugin/tests/Integration/Rest/OverviewBulkUpdateThemesControllerTest.php
git commit -m "feat(p2-8): POST /overview/bulk-update-themes endpoint

Validates each {site_id, slug} pair with 3 skip reasons
(site_not_owned, theme_not_found, no_update_available). Fan-outs
existing P2.3 defyn_update_site_theme AS job per valid pair via
as_schedule_single_action. Emits ONE fleet-scoped
overview.bulk_theme_update_requested activity event (site_id=null)
ONLY when scheduled_count > 0. 5/HOUR rate limit per user.

Bypasses per-(user, site, slug) themesUpdate 6/HR bucket — operator's
explicit dialog confirmation IS the safety.

4 response shapes:
- 202 when scheduled_count > 0
- 200 when all pairs skipped (same envelope, NO activity event)
- 400 bulk.empty_updates
- 429 bulk.rate_limited

8 integration tests covering all branches + activity event row presence
and absence + AS fan-out + rate limit at 6th call.

Per spec § 2.2."
```

---

## Task 4 — Dashboard v0.8.1 release bump + 2 CORS regressions

**Files:**
- Modify: `packages/dashboard-plugin/defyn-dashboard.php` (version header)
- Modify: `packages/dashboard-plugin/composer.json` (version)
- Modify: `packages/dashboard-plugin/readme.txt` (stable tag + changelog)
- Test: `packages/dashboard-plugin/tests/Integration/Rest/OverviewPendingThemeUpdatesCorsTest.php` (CREATE)
- Test: `packages/dashboard-plugin/tests/Integration/Rest/OverviewBulkUpdateThemesCorsTest.php` (CREATE)

- [ ] **Step 1: Write the CORS regression tests**

Create `packages/dashboard-plugin/tests/Integration/Rest/OverviewPendingThemeUpdatesCorsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

/**
 * P2.8 — CORS preflight regression for /overview/pending-theme-updates.
 */
final class OverviewPendingThemeUpdatesCorsTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        do_action('rest_api_init');
    }

    public function testOptionsPreflightAllowsSpaOrigin(): void
    {
        $request = new WP_REST_Request('OPTIONS', '/defyn/v1/overview/pending-theme-updates');
        $request->set_header('Origin', 'https://app.defynwp.defyn.agency');
        $request->set_header('Access-Control-Request-Method', 'GET');
        $request->set_header('Access-Control-Request-Headers', 'authorization,content-type');

        $response = rest_do_request($request);
        $headers  = $response->get_headers();

        $this->assertArrayHasKey('Access-Control-Allow-Origin', $headers);
        $this->assertSame('https://app.defynwp.defyn.agency', $headers['Access-Control-Allow-Origin']);
        $this->assertArrayHasKey('Access-Control-Allow-Methods', $headers);
        $this->assertStringContainsString('GET', $headers['Access-Control-Allow-Methods']);
        $this->assertArrayHasKey('Access-Control-Allow-Headers', $headers);
        $this->assertStringContainsString('Authorization', $headers['Access-Control-Allow-Headers']);
    }
}
```

Create `packages/dashboard-plugin/tests/Integration/Rest/OverviewBulkUpdateThemesCorsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

/**
 * P2.8 — CORS preflight regression for /overview/bulk-update-themes.
 */
final class OverviewBulkUpdateThemesCorsTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        do_action('rest_api_init');
    }

    public function testOptionsPreflightAllowsSpaOrigin(): void
    {
        $request = new WP_REST_Request('OPTIONS', '/defyn/v1/overview/bulk-update-themes');
        $request->set_header('Origin', 'https://app.defynwp.defyn.agency');
        $request->set_header('Access-Control-Request-Method', 'POST');
        $request->set_header('Access-Control-Request-Headers', 'authorization,content-type');

        $response = rest_do_request($request);
        $headers  = $response->get_headers();

        $this->assertArrayHasKey('Access-Control-Allow-Origin', $headers);
        $this->assertSame('https://app.defynwp.defyn.agency', $headers['Access-Control-Allow-Origin']);
        $this->assertArrayHasKey('Access-Control-Allow-Methods', $headers);
        $this->assertStringContainsString('POST', $headers['Access-Control-Allow-Methods']);
        $this->assertArrayHasKey('Access-Control-Allow-Headers', $headers);
        $this->assertStringContainsString('Authorization', $headers['Access-Control-Allow-Headers']);
    }
}
```

- [ ] **Step 2: Run tests to verify they pass**

The CORS layer in `RestRouter::registerCorsHeaders` is namespace-level (`defyn/v1`), so newly-registered routes inherit it automatically.

Run: `cd packages/dashboard-plugin && composer test -- --filter "OverviewPendingThemeUpdatesCorsTest|OverviewBulkUpdateThemesCorsTest"`

Expected: 2 PASS.

- [ ] **Step 3: Bump version**

Modify `packages/dashboard-plugin/defyn-dashboard.php` — change `Version:` from `0.8.0` to `0.8.1`. If `DEFYN_DASHBOARD_VERSION` constant is defined, bump it too:

```php
 * Version: 0.8.1
```

```php
define('DEFYN_DASHBOARD_VERSION', '0.8.1');
```

Modify `packages/dashboard-plugin/composer.json`:

```json
"version": "0.8.1",
```

Modify `packages/dashboard-plugin/readme.txt` — change `Stable tag:` from `0.8.0` to `0.8.1`. Add at top of changelog:

```
= 0.8.1 =
* Bulk theme updates across fleet: POST /defyn/v1/overview/bulk-update-themes fan-outs the existing P2.3 UpdateSiteTheme AS job per confirmed (site, theme) pair. 5/hour rate limit. Single overview.bulk_theme_update_requested activity event captures the fleet-scoped intent.
* New GET /defyn/v1/overview/pending-theme-updates returns a flat list of eligible (site, theme) pairs for the SPA's confirmation dialog. 30/minute rate limit.
* SPA confirmation dialog ships with the "Skip major bumps" toggle baked in from day 1 (mirrors P2.7.1 for plugins). Semver helper isPluginMajorBump renamed to isMajorBump in apps/web/src/lib/semver.ts (resource-agnostic).
* Patch bump because endpoints + event type are additive on top of the v0.8.0 destructive-bulk shape.
```

- [ ] **Step 4: Commit**

```bash
git add packages/dashboard-plugin/defyn-dashboard.php \
        packages/dashboard-plugin/composer.json \
        packages/dashboard-plugin/readme.txt \
        packages/dashboard-plugin/tests/Integration/Rest/OverviewPendingThemeUpdatesCorsTest.php \
        packages/dashboard-plugin/tests/Integration/Rest/OverviewBulkUpdateThemesCorsTest.php
git commit -m "feat(p2-8): dashboard v0.8.1 release bump + 2 CORS regressions

Patch bump because P2.8 endpoints + event type are additive on top of
the v0.8.0 destructive-bulk shape. No schema change. No connector
change.

2 CORS preflight regressions confirm /overview/pending-theme-updates
and /overview/bulk-update-themes inherit the namespace-level CORS
headers (https://app.defynwp.defyn.agency origin allowed).

Per spec § 2.7."
```

---

## Task 5 — Rename `isPluginMajorBump` → `isMajorBump` (semver.ts)

**Files:**
- Modify: `apps/web/src/lib/semver.ts` (export rename)
- Modify: `apps/web/tests/lib/semver.test.ts` (import + 8 test descriptions)
- Modify: `apps/web/src/components/overview/ConfirmBulkUpdatePluginsDialog.tsx` (1 import + 1 callsite)

- [ ] **Step 1: Update the helper**

Edit `apps/web/src/lib/semver.ts` — change all occurrences of `isPluginMajorBump` to `isMajorBump`:

```ts
/**
 * P2.8 — npm-style major bump detection for plugin/theme updates.
 *
 * Returns true when the leftmost numeric segment differs between
 * current and target (e.g. 1.x → 2.x). Returns false for:
 *   - null/empty target (defensive — don't auto-hide unknown bumps)
 *   - same major (1.5.0 → 1.6.0, 1.5.0 → 1.5.1)
 *   - unparseable major (treat as not major — match conservative default)
 *
 * Distinct from P2.4.1's SiteCoreCard `isMinorBump` which uses WP-core
 * convention (major.minor both must match). For plugins/themes we use
 * npm convention (major segment only).
 *
 * Renamed from isPluginMajorBump in P2.8 since both plugins (P2.7.1) and
 * themes (P2.8) use the same helper — the predicate is resource-agnostic.
 *
 * Spec: docs/superpowers/specs/2026-06-09-p2-8-bulk-theme-updates-design.md § 7 #14
 */
export function isMajorBump(
  current: string | null | undefined,
  target: string | null | undefined,
): boolean {
  if (!current || !target) return false;
  const cMaj = parseInt(current.split('.')[0] ?? '', 10);
  const tMaj = parseInt(target.split('.')[0] ?? '', 10);
  if (Number.isNaN(cMaj) || Number.isNaN(tMaj)) return false;
  return cMaj !== tMaj;
}
```

- [ ] **Step 2: Update the tests**

Edit `apps/web/tests/lib/semver.test.ts` — change the import + rename the function in every test case. Final file:

```ts
import { describe, it, expect } from 'vitest';
import { isMajorBump } from '@/lib/semver';

describe('isMajorBump', () => {
  it('returns true for major version change 1.0.0 → 2.0.0', () => {
    expect(isMajorBump('1.0.0', '2.0.0')).toBe(true);
  });

  it('returns false for minor version change 1.0.0 → 1.5.0', () => {
    expect(isMajorBump('1.0.0', '1.5.0')).toBe(false);
  });

  it('returns false for patch version change 1.0.0 → 1.0.5', () => {
    expect(isMajorBump('1.0.0', '1.0.5')).toBe(false);
  });

  it('returns false for same version 1.0.0 → 1.0.0', () => {
    expect(isMajorBump('1.0.0', '1.0.0')).toBe(false);
  });

  it('returns false when target is null (defensive)', () => {
    expect(isMajorBump('1.0.0', null)).toBe(false);
  });

  it('returns false when current is undefined (defensive)', () => {
    expect(isMajorBump(undefined, '2.0.0')).toBe(false);
  });

  it('returns true for pre-release suffix 1.0-beta → 2.0', () => {
    expect(isMajorBump('1.0-beta', '2.0')).toBe(true);
  });

  it('returns false when major segment is unparseable (conservative)', () => {
    expect(isMajorBump('v2', '3')).toBe(false);
  });
});
```

- [ ] **Step 3: Update the P2.7.1 dialog callsite**

Edit `apps/web/src/components/overview/ConfirmBulkUpdatePluginsDialog.tsx` — change the import + the single callsite. Replace the import line:

```ts
import { isPluginMajorBump } from '@/lib/semver';
```

with:

```ts
import { isMajorBump } from '@/lib/semver';
```

And the callsite (inside the `visibleRows` useMemo) currently reads:

```ts
() => skipMajor
  ? rows.filter((r) => !isPluginMajorBump(r.current_version, r.target_version))
  : rows,
```

Change to:

```ts
() => skipMajor
  ? rows.filter((r) => !isMajorBump(r.current_version, r.target_version))
  : rows,
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd apps/web && pnpm test -- --run semver ConfirmBulkUpdatePluginsDialog`

Expected: 8 semver tests + 7 plugins dialog tests PASS (15/15 green).

Run the full suite to confirm no broken imports elsewhere:

Run: `cd apps/web && pnpm test -- --run`

Expected: 208 pass + 4 documented carry-forward failures. No new failures.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/lib/semver.ts \
        apps/web/tests/lib/semver.test.ts \
        apps/web/src/components/overview/ConfirmBulkUpdatePluginsDialog.tsx
git commit -m "refactor(p2-8): rename isPluginMajorBump → isMajorBump

Resource-agnostic predicate (plugins from P2.7.1 + themes from P2.8 use
the same helper). Transitive rename touches 3 files:
- apps/web/src/lib/semver.ts (export)
- apps/web/tests/lib/semver.test.ts (import + 8 test descriptions)
- apps/web/src/components/overview/ConfirmBulkUpdatePluginsDialog.tsx
  (1 import + 1 callsite)

No behavior change. All 15 tests pass (8 semver + 7 plugins dialog).

Per spec § 1 (semver helper rename) + § 7 #14."
```

---

## Task 6 — SPA Zod schemas + MSW handlers

**Files:**
- Modify: `apps/web/src/types/api.ts` (append schemas + types)
- Modify: `apps/web/src/test/handlers.ts` (append 2 MSW handlers)

- [ ] **Step 1: Append schemas**

Append to `apps/web/src/types/api.ts` (after the existing P2.7 `bulkUpdatePluginsResponseSchema`):

```ts
// ────────────────────────────────────────────────────────────────────────────
// P2.8 — Bulk theme updates
// ────────────────────────────────────────────────────────────────────────────

export const pendingThemeUpdateRowSchema = z.object({
  site_id:         z.number().int(),
  site_label:      z.string(),
  slug:            z.string(),
  theme_name:      z.string(),
  current_version: z.string(),
  target_version:  z.string().nullable(),
});

export type PendingThemeUpdateRow = z.infer<typeof pendingThemeUpdateRowSchema>;

export const pendingThemeUpdatesResponseSchema = z.object({
  pending_updates: z.array(pendingThemeUpdateRowSchema),
  generated_at:    z.string(),
});

export type PendingThemeUpdatesResponse = z.infer<typeof pendingThemeUpdatesResponseSchema>;

export const bulkUpdateThemesRequestSchema = z.object({
  updates: z.array(z.object({
    site_id: z.number().int(),
    slug:    z.string(),
  })).min(1),
});

export type BulkUpdateThemesRequest = z.infer<typeof bulkUpdateThemesRequestSchema>;

export const bulkUpdateThemesResponseSchema = z.object({
  scheduled_count: z.number().int(),
  skipped_count:   z.number().int(),
  scheduled_pairs: z.array(z.object({
    site_id: z.number().int(),
    slug:    z.string(),
  })),
  skipped_pairs: z.array(z.object({
    site_id: z.number().int(),
    slug:    z.string(),
    reason:  z.enum(['site_not_owned', 'theme_not_found', 'no_update_available']),
  })),
  scheduled_at: z.string(),
});

export type BulkUpdateThemesResponse = z.infer<typeof bulkUpdateThemesResponseSchema>;
```

- [ ] **Step 2: Append MSW handlers**

Append to `apps/web/src/test/handlers.ts` (near the existing `/overview/bulk-update-plugins` POST handler):

```ts
// P2.8 — Pending theme updates GET handler.
http.get('*/defyn/v1/overview/pending-theme-updates', () => {
  return HttpResponse.json({
    pending_updates: [
      { site_id: 1, site_label: 'SmartCoding', slug: 'astra',   theme_name: 'Astra',   current_version: '4.6.3', target_version: '4.7.0' },
      { site_id: 2, site_label: 'AcmeBlog',    slug: 'blocksy', theme_name: 'Blocksy', current_version: '2.0.1', target_version: '2.0.2' },
    ],
    generated_at: '2026-06-09 23:45:00',
  });
}),

// P2.8 — Bulk update themes POST handler.
http.post('*/defyn/v1/overview/bulk-update-themes', async ({ request }) => {
  const body = await request.json() as { updates: Array<{site_id: number, slug: string}> };
  if (!body?.updates?.length) {
    return HttpResponse.json(
      { error: { code: 'bulk.empty_updates', message: 'updates array must be non-empty' } },
      { status: 400 }
    );
  }
  return HttpResponse.json(
    {
      scheduled_count: body.updates.length,
      skipped_count:   0,
      scheduled_pairs: body.updates,
      skipped_pairs:   [],
      scheduled_at:    '2026-06-09 23:45:42',
    },
    { status: 202 }
  );
}),
```

- [ ] **Step 3: Verify nothing broken**

Run: `cd apps/web && pnpm test -- --run`

Expected: 208 pass + 4 documented carry-forward failures. No new failures.

- [ ] **Step 4: Commit**

```bash
git add apps/web/src/types/api.ts apps/web/src/test/handlers.ts
git commit -m "feat(p2-8): SPA Zod schemas + MSW handlers for theme bulk endpoints

Adds 4 Zod schemas (pendingThemeUpdateRow, pendingThemeUpdatesResponse,
bulkUpdateThemesRequest, bulkUpdateThemesResponse) + 4 inferred types.
2 MSW handlers for the new endpoints (mirror of P2.7 plugin handlers).

Per spec § 3.3 (schema section)."
```

---

## Task 7 — `usePendingThemeUpdates` query hook (enabled-on-open)

**Files:**
- Create: `apps/web/src/lib/queries/usePendingThemeUpdates.ts`
- Test: `apps/web/tests/lib/queries/usePendingThemeUpdates.test.tsx` (CREATE)

- [ ] **Step 1: Write the failing test**

Create `apps/web/tests/lib/queries/usePendingThemeUpdates.test.tsx`:

```tsx
import { describe, it, expect } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { usePendingThemeUpdates } from '@/lib/queries/usePendingThemeUpdates';

function wrapper(client: QueryClient) {
  return function Wrapper({ children }: { children: React.ReactNode }) {
    return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
  };
}

describe('usePendingThemeUpdates', () => {
  it('does not fetch when dialogOpen is false', () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const { result } = renderHook(() => usePendingThemeUpdates(false), { wrapper: wrapper(client) });

    expect(result.current.fetchStatus).toBe('idle');
  });

  it('fetches and parses the response when dialogOpen is true', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const { result } = renderHook(() => usePendingThemeUpdates(true), { wrapper: wrapper(client) });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data?.pending_updates).toHaveLength(2);
    expect(result.current.data?.pending_updates[0].slug).toBe('astra');
  });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd apps/web && pnpm test -- --run usePendingThemeUpdates`

Expected: 2 FAILED with module-resolution error.

- [ ] **Step 3: Create the hook**

Create `apps/web/src/lib/queries/usePendingThemeUpdates.ts`:

```ts
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { pendingThemeUpdatesResponseSchema, type PendingThemeUpdatesResponse } from '@/types/api';

/**
 * P2.8 — TanStack query hook feeding ConfirmBulkUpdateThemesDialog.
 *
 * Gated on dialogOpen — fetches when the dialog opens, stays cached for
 * 30s. Does NOT poll (would otherwise hit the 30/MIN bucket needlessly
 * given /overview already polls at 60s).
 *
 * Mirror of P2.7's usePendingPluginUpdates with theme swap.
 */
export function usePendingThemeUpdates(dialogOpen: boolean) {
  return useQuery<PendingThemeUpdatesResponse>({
    queryKey: ['pendingThemeUpdates'],
    queryFn: async () => {
      const res = await apiClient.get<unknown>('/defyn/v1/overview/pending-theme-updates');
      return pendingThemeUpdatesResponseSchema.parse(res);
    },
    enabled: dialogOpen,
    staleTime: 30_000,
  });
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd apps/web && pnpm test -- --run usePendingThemeUpdates`

Expected: 2 PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/lib/queries/usePendingThemeUpdates.ts \
        apps/web/tests/lib/queries/usePendingThemeUpdates.test.tsx
git commit -m "feat(p2-8): usePendingThemeUpdates query hook (enabled-on-open)

Gated on dialogOpen flag — fetches when the dialog opens, stays cached
for 30s. Does NOT poll. Same pattern as P2.7's usePendingPluginUpdates.

2 unit tests:
- Idle (fetchStatus = 'idle') when dialogOpen = false
- Success + Zod-parsed shape when dialogOpen = true

Per spec § 4.1 + guardrail #7."
```

---

## Task 8 — `useBulkUpdateThemes` mutation hook

**Files:**
- Create: `apps/web/src/lib/mutations/useBulkUpdateThemes.ts`
- Test: `apps/web/tests/lib/mutations/useBulkUpdateThemes.test.tsx` (CREATE)

- [ ] **Step 1: Write the failing test**

Create `apps/web/tests/lib/mutations/useBulkUpdateThemes.test.tsx`:

```tsx
import { describe, it, expect, vi } from 'vitest';
import { renderHook, act, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useBulkUpdateThemes } from '@/lib/mutations/useBulkUpdateThemes';

function wrapper(client: QueryClient) {
  return function Wrapper({ children }: { children: React.ReactNode }) {
    return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
  };
}

describe('useBulkUpdateThemes', () => {
  it('posts the body and parses 202 response', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const { result } = renderHook(() => useBulkUpdateThemes(), { wrapper: wrapper(client) });

    await act(async () => {
      await result.current.mutateAsync({
        updates: [{ site_id: 1, slug: 'astra' }],
      });
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data?.scheduled_count).toBe(1);
  });

  it('invalidates overview and pendingThemeUpdates on success', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const spy = vi.spyOn(client, 'invalidateQueries');
    const { result } = renderHook(() => useBulkUpdateThemes(), { wrapper: wrapper(client) });

    await act(async () => {
      await result.current.mutateAsync({
        updates: [{ site_id: 1, slug: 'astra' }],
      });
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(spy).toHaveBeenCalledWith({ queryKey: ['overview'] });
    expect(spy).toHaveBeenCalledWith({ queryKey: ['pendingThemeUpdates'] });
    expect(spy).not.toHaveBeenCalledWith({ queryKey: ['sites'] });
  });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd apps/web && pnpm test -- --run useBulkUpdateThemes`

Expected: 2 FAILED with module-resolution error.

- [ ] **Step 3: Create the hook**

Create `apps/web/src/lib/mutations/useBulkUpdateThemes.ts`:

```ts
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import {
  bulkUpdateThemesResponseSchema,
  type BulkUpdateThemesRequest,
  type BulkUpdateThemesResponse,
} from '@/types/api';

/**
 * P2.8 — TanStack mutation hook for POST /overview/bulk-update-themes.
 *
 * On success, invalidates ['overview'] (so the new themes count drops to
 * reflect scheduled jobs) and ['pendingThemeUpdates'] (so the next dialog
 * open shows fewer pairs). Does NOT invalidate ['sites'] — per-site theme
 * state hasn't changed yet, only AS jobs queued. Same reasoning as P2.7's
 * useBulkUpdatePlugins.
 */
export function useBulkUpdateThemes() {
  const queryClient = useQueryClient();

  return useMutation<BulkUpdateThemesResponse, Error, BulkUpdateThemesRequest>({
    mutationFn: async (body) => {
      const res = await apiClient.post<unknown>('/defyn/v1/overview/bulk-update-themes', body);
      return bulkUpdateThemesResponseSchema.parse(res);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['overview'] });
      queryClient.invalidateQueries({ queryKey: ['pendingThemeUpdates'] });
    },
  });
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd apps/web && pnpm test -- --run useBulkUpdateThemes`

Expected: 2 PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/lib/mutations/useBulkUpdateThemes.ts \
        apps/web/tests/lib/mutations/useBulkUpdateThemes.test.tsx
git commit -m "feat(p2-8): useBulkUpdateThemes mutation hook

Invalidates ['overview'] AND ['pendingThemeUpdates'] on success, NOT
['sites'] (per-site theme state hasn't changed yet, only AS jobs
queued). Same pattern as P2.7's useBulkUpdatePlugins.

2 unit tests:
- POST 202 happy path + Zod-parsed response
- Invalidates correct query keys + does NOT touch ['sites']

Per spec § 4.2 + guardrail #6."
```

---

## Task 9 — `PendingThemeUpdatesGroup` + `ConfirmBulkUpdateThemesDialog` (with day-1 skipMajor toggle)

**Files:**
- Create: `apps/web/src/components/overview/PendingThemeUpdatesGroup.tsx`
- Create: `apps/web/src/components/overview/ConfirmBulkUpdateThemesDialog.tsx`
- Test: `apps/web/tests/components/overview/ConfirmBulkUpdateThemesDialog.test.tsx` (CREATE)

- [ ] **Step 1: Write the failing tests**

Create `apps/web/tests/components/overview/ConfirmBulkUpdateThemesDialog.test.tsx`:

```tsx
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { ConfirmBulkUpdateThemesDialog } from '@/components/overview/ConfirmBulkUpdateThemesDialog';

const ROWS = [
  { site_id: 1, site_label: 'SmartCoding', slug: 'astra',            theme_name: 'Astra',             current_version: '4.6.3',  target_version: '4.7.0' },
  { site_id: 1, site_label: 'SmartCoding', slug: 'twentytwentyfour', theme_name: 'Twenty TwentyFour', current_version: '1.2',    target_version: '1.3' },
  { site_id: 2, site_label: 'AcmeBlog',    slug: 'blocksy',          theme_name: 'Blocksy',           current_version: '2.0.1',  target_version: '2.0.2' },
];

const ROWS_WITH_MAJOR = [
  { site_id: 1, site_label: 'SmartCoding', slug: 'astra',            theme_name: 'Astra',             current_version: '4.6.3',  target_version: '4.7.0' },
  { site_id: 1, site_label: 'SmartCoding', slug: 'twentytwentyfour', theme_name: 'Twenty TwentyFour', current_version: '1.2',    target_version: '1.3' },
  { site_id: 1, site_label: 'SmartCoding', slug: 'kadence',          theme_name: 'Kadence',           current_version: '1.1.40', target_version: '2.0.0' }, // MAJOR
  { site_id: 2, site_label: 'AcmeBlog',    slug: 'blocksy',          theme_name: 'Blocksy',           current_version: '2.0.1',  target_version: '2.0.2' },
];

describe('ConfirmBulkUpdateThemesDialog', () => {
  it('opensWithAllRowsPreChecked', () => {
    render(
      <ConfirmBulkUpdateThemesDialog
        open
        rows={ROWS}
        onCancel={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );
    expect(screen.getByText(/3 selected of 3 available/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /bulk update 3 themes/i })).toBeInTheDocument();
  });

  it('manualUncheckUpdatesFooterCounter', () => {
    render(
      <ConfirmBulkUpdateThemesDialog
        open
        rows={ROWS}
        onCancel={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );
    fireEvent.click(screen.getByRole('checkbox', { name: /astra/i }));
    expect(screen.getByText(/2 selected of 3 available/i)).toBeInTheDocument();
  });

  it('allUncheckedDisablesPrimary', () => {
    render(
      <ConfirmBulkUpdateThemesDialog
        open
        rows={ROWS}
        onCancel={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );
    fireEvent.click(screen.getByRole('checkbox', { name: /astra/i }));
    fireEvent.click(screen.getByRole('checkbox', { name: /twenty twentyfour/i }));
    fireEvent.click(screen.getByRole('checkbox', { name: /blocksy/i }));

    const primary = screen.getByRole('button', { name: /bulk update 0 themes/i });
    expect(primary).toBeDisabled();
  });

  it('cancelCallsOnCancel', () => {
    const onCancel = vi.fn();
    render(
      <ConfirmBulkUpdateThemesDialog
        open
        rows={ROWS}
        onCancel={onCancel}
        onConfirm={vi.fn()}
      />,
    );
    fireEvent.click(screen.getByRole('button', { name: /cancel/i }));
    expect(onCancel).toHaveBeenCalledTimes(1);
  });

  it('skipMajorToggleOffShowsAllRows', () => {
    render(
      <ConfirmBulkUpdateThemesDialog
        open
        rows={ROWS_WITH_MAJOR}
        onCancel={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );
    expect(screen.getByRole('checkbox', { name: /astra/i })).toBeInTheDocument();
    expect(screen.getByRole('checkbox', { name: /twenty twentyfour/i })).toBeInTheDocument();
    expect(screen.getByRole('checkbox', { name: /kadence/i })).toBeInTheDocument();
    expect(screen.getByRole('checkbox', { name: /blocksy/i })).toBeInTheDocument();
    expect(screen.getByText(/4 selected of 4 available/i)).toBeInTheDocument();
  });

  it('skipMajorToggleOnHidesMajorRowsAndUpdatesCounts', () => {
    render(
      <ConfirmBulkUpdateThemesDialog
        open
        rows={ROWS_WITH_MAJOR}
        onCancel={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );

    fireEvent.click(screen.getByRole('checkbox', { name: /skip major bumps/i }));

    expect(screen.queryByRole('checkbox', { name: /kadence/i })).not.toBeInTheDocument();
    expect(screen.getByRole('checkbox', { name: /astra/i })).toBeInTheDocument();
    expect(screen.getByRole('checkbox', { name: /twenty twentyfour/i })).toBeInTheDocument();
    expect(screen.getByRole('checkbox', { name: /blocksy/i })).toBeInTheDocument();

    expect(screen.getByText(/bulk update 3 themes across 2 sites\?/i)).toBeInTheDocument();
    expect(screen.getByText(/3 selected of 3 available/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /bulk update 3 themes/i })).toBeInTheDocument();
  });

  it('skipMajorToggleResetsCheckedKeysWhenFlipped', () => {
    render(
      <ConfirmBulkUpdateThemesDialog
        open
        rows={ROWS_WITH_MAJOR}
        onCancel={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );

    fireEvent.click(screen.getByRole('checkbox', { name: /astra/i }));
    expect(screen.getByText(/3 selected of 4 available/i)).toBeInTheDocument();

    fireEvent.click(screen.getByRole('checkbox', { name: /skip major bumps/i }));
    expect(screen.getByText(/3 selected of 3 available/i)).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd apps/web && pnpm test -- --run ConfirmBulkUpdateThemesDialog`

Expected: 7 FAILED with module-resolution error.

- [ ] **Step 3: Create PendingThemeUpdatesGroup**

Create `apps/web/src/components/overview/PendingThemeUpdatesGroup.tsx`:

```tsx
import type { PendingThemeUpdateRow } from '@/types/api';

interface PendingThemeUpdatesGroupProps {
  label: string;
  rows: PendingThemeUpdateRow[];
  checkedKeys: Set<string>;
  onToggleRow: (key: string) => void;
}

/**
 * P2.8 — Per-site collapsible group inside ConfirmBulkUpdateThemesDialog.
 * Mirror of P2.7's PendingPluginUpdatesGroup with theme swap.
 */
export function PendingThemeUpdatesGroup({
  label,
  rows,
  checkedKeys,
  onToggleRow,
}: PendingThemeUpdatesGroupProps): JSX.Element {
  return (
    <div className="rounded-md border border-zinc-200 p-3">
      <div className="text-sm font-medium text-zinc-900">{label}</div>
      <ul className="mt-2 space-y-1">
        {rows.map((row) => {
          const key = `${row.site_id}:${row.slug}`;
          const checked = checkedKeys.has(key);
          return (
            <li key={key} className="flex items-center gap-2">
              <input
                type="checkbox"
                checked={checked}
                onChange={() => onToggleRow(key)}
                aria-label={`${row.theme_name} ${row.current_version} to ${row.target_version}`}
              />
              <span className="text-sm text-zinc-700">
                {row.theme_name}{' '}
                <span className="text-xs text-zinc-500">
                  {row.current_version} → {row.target_version ?? '?'}
                </span>
              </span>
            </li>
          );
        })}
      </ul>
    </div>
  );
}
```

- [ ] **Step 4: Create ConfirmBulkUpdateThemesDialog**

Create `apps/web/src/components/overview/ConfirmBulkUpdateThemesDialog.tsx`:

```tsx
import { useEffect, useMemo, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { PendingThemeUpdatesGroup } from '@/components/overview/PendingThemeUpdatesGroup';
import { isMajorBump } from '@/lib/semver';
import type { PendingThemeUpdateRow } from '@/types/api';

interface ConfirmBulkUpdateThemesDialogProps {
  open: boolean;
  rows: PendingThemeUpdateRow[];
  onCancel: () => void;
  onConfirm: (checkedPairs: Array<{ site_id: number; slug: string }>) => void;
}

/**
 * P2.8 — Confirmation dialog for bulk theme updates.
 * Mirror of P2.7.1's ConfirmBulkUpdatePluginsDialog with theme swap.
 *
 * State machine:
 * - skipMajor (default OFF) gates a `visibleRows` useMemo filter.
 * - allKeys, grouped, totalCount ALL derive from visibleRows.
 * - The existing useEffect([open, allKeys]) re-fires when toggle flips
 *   (allKeys depends on visibleRows). No separate effect for [skipMajor].
 */
export function ConfirmBulkUpdateThemesDialog({
  open,
  rows,
  onCancel,
  onConfirm,
}: ConfirmBulkUpdateThemesDialogProps): JSX.Element | null {
  const [showAll, setShowAll] = useState(false);
  const [skipMajor, setSkipMajor] = useState(false);
  const [checkedKeys, setCheckedKeys] = useState<Set<string>>(new Set());
  const cancelRef = useRef<HTMLButtonElement>(null);

  const visibleRows = useMemo(
    () =>
      skipMajor
        ? rows.filter((r) => !isMajorBump(r.current_version, r.target_version))
        : rows,
    [rows, skipMajor],
  );

  const allKeys = useMemo(
    () => visibleRows.map((r) => `${r.site_id}:${r.slug}`),
    [visibleRows],
  );

  useEffect(() => {
    if (open) {
      setCheckedKeys(new Set(allKeys));
      setShowAll(false);
      cancelRef.current?.focus();
    }
  }, [open, allKeys]);

  const grouped = useMemo(() => {
    const map = new Map<string, PendingThemeUpdateRow[]>();
    for (const row of visibleRows) {
      const list = map.get(row.site_label) ?? [];
      list.push(row);
      map.set(row.site_label, list);
    }
    return Array.from(map.entries());
  }, [visibleRows]);

  const visibleGroups = showAll ? grouped : grouped.slice(0, 3);
  const hiddenGroupCount = Math.max(0, grouped.length - visibleGroups.length);
  const totalCount = visibleRows.length;
  const siteCount = grouped.length;
  const checkedCount = checkedKeys.size;

  if (!open) return null;

  const handleToggle = (key: string): void => {
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

  const handleConfirm = (): void => {
    const pairs = visibleRows
      .filter((r) => checkedKeys.has(`${r.site_id}:${r.slug}`))
      .map((r) => ({ site_id: r.site_id, slug: r.slug }));
    onConfirm(pairs);
  };

  return (
    <div
      role="alertdialog"
      aria-modal="true"
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
    >
      <div className="w-full max-w-2xl rounded-lg bg-white p-6 shadow-xl">
        <h2 className="text-lg font-semibold text-zinc-900">
          Bulk update {totalCount} themes across {siteCount} sites?
        </h2>

        <div className="mt-3 space-y-2 text-sm text-zinc-700">
          <p>This will run the theme upgrader on every checked pair below. Each site briefly enters maintenance mode during its update.</p>
          <p>Uncheck any pair you want to skip — server fans out exactly what's checked. Already-updated rows are silently no-op'd.</p>
        </div>

        {/* P2.8 — Skip major bumps toggle (day 1, mirrors P2.7.1) */}
        <label className="mt-3 flex items-center gap-2 text-sm text-zinc-700">
          <input
            type="checkbox"
            checked={skipMajor}
            onChange={(e) => setSkipMajor(e.target.checked)}
          />
          Skip major bumps
          <span className="text-xs text-zinc-500">
            (hide updates where the major version changes, e.g. 1.x → 2.x)
          </span>
        </label>

        <div className="mt-3 space-y-2">
          {visibleGroups.map(([label, groupRows]) => (
            <PendingThemeUpdatesGroup
              key={label}
              label={label}
              rows={groupRows}
              checkedKeys={checkedKeys}
              onToggleRow={handleToggle}
            />
          ))}
          {hiddenGroupCount > 0 && (
            <button
              type="button"
              className="text-sm text-blue-600 hover:underline"
              onClick={() => setShowAll(true)}
            >
              show all {grouped.length} sites ▾
            </button>
          )}
        </div>

        <div className="mt-4 flex items-center justify-between border-t border-zinc-200 pt-4">
          <div className="text-sm text-zinc-600">
            {checkedCount} selected of {totalCount} available
          </div>
          <div className="flex gap-2">
            <Button ref={cancelRef} variant="outline" onClick={onCancel}>
              Cancel
            </Button>
            <Button
              onClick={handleConfirm}
              disabled={checkedCount === 0}
              className="bg-red-600 hover:bg-red-700 text-white"
            >
              Bulk update {checkedCount} themes
            </Button>
          </div>
        </div>
      </div>
    </div>
  );
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd apps/web && pnpm test -- --run ConfirmBulkUpdateThemesDialog`

Expected: 7 PASS.

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/components/overview/PendingThemeUpdatesGroup.tsx \
        apps/web/src/components/overview/ConfirmBulkUpdateThemesDialog.tsx \
        apps/web/tests/components/overview/ConfirmBulkUpdateThemesDialog.test.tsx
git commit -m "feat(p2-8): ConfirmBulkUpdateThemesDialog with day-1 skipMajor toggle

Mirror of P2.7.1's ConfirmBulkUpdatePluginsDialog with theme swap.

Day-1 Skip major bumps toggle (default OFF, opt-in) between body text
and per-site groups. When ON, rows where isMajorBump(current, target)
is true are filtered out via visibleRows useMemo. allKeys, grouped,
totalCount ALL derive from visibleRows so dialog title + footer
counter + primary button label all reflect filtered counts. NO new
useEffect([skipMajor]) — existing useEffect([open, allKeys]) re-fires
indirectly because allKeys depends on visibleRows.

RED-tier destructive primary via className=\"bg-red-600 hover:bg-red-700
text-white\" (shadcn Button has NO destructive variant per plan-bug
guardrail #1).

PendingThemeUpdatesGroup sub-component for per-site collapsible groups.

7 component tests (4 base + 3 skipMajor) — exact same names as P2.7.1:
- opensWithAllRowsPreChecked
- manualUncheckUpdatesFooterCounter
- allUncheckedDisablesPrimary
- cancelCallsOnCancel
- skipMajorToggleOffShowsAllRows
- skipMajorToggleOnHidesMajorRowsAndUpdatesCounts
- skipMajorToggleResetsCheckedKeysWhenFlipped

Per spec § 3.4 + plan-bug traps #1, #9-#13."
```

---

## Task 10 — `BulkUpdateThemesButton` + Overview header integration

**Files:**
- Create: `apps/web/src/components/overview/BulkUpdateThemesButton.tsx`
- Modify: `apps/web/src/routes/Overview.tsx` (render the button)
- Test: `apps/web/tests/components/overview/BulkUpdateThemesButton.test.tsx` (CREATE)

- [ ] **Step 1: Write the failing tests**

Create `apps/web/tests/components/overview/BulkUpdateThemesButton.test.tsx`:

```tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { BulkUpdateThemesButton } from '@/components/overview/BulkUpdateThemesButton';

function wrapper(client: QueryClient) {
  return function Wrapper({ children }: { children: React.ReactNode }) {
    return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
  };
}

describe('BulkUpdateThemesButton', () => {
  it('hiddenWhenPendingCountIsZero', () => {
    const client = new QueryClient();
    render(<BulkUpdateThemesButton pendingCount={0} />, { wrapper: wrapper(client) });

    expect(screen.queryByRole('button', { name: /bulk update/i })).not.toBeInTheDocument();
  });

  it('visibleWithCountWhenPendingCountGreaterThanZero', () => {
    const client = new QueryClient();
    render(<BulkUpdateThemesButton pendingCount={12} />, { wrapper: wrapper(client) });

    const button = screen.getByRole('button', { name: /bulk update themes \(12\)/i });
    expect(button).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd apps/web && pnpm test -- --run BulkUpdateThemesButton`

Expected: 2 FAILED with module-resolution error.

- [ ] **Step 3: Create BulkUpdateThemesButton**

Create `apps/web/src/components/overview/BulkUpdateThemesButton.tsx`:

```tsx
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { ConfirmBulkUpdateThemesDialog } from '@/components/overview/ConfirmBulkUpdateThemesDialog';
import { usePendingThemeUpdates } from '@/lib/queries/usePendingThemeUpdates';
import { useBulkUpdateThemes } from '@/lib/mutations/useBulkUpdateThemes';

interface BulkUpdateThemesButtonProps {
  pendingCount: number;
}

/**
 * P2.8 — Overview header button + dialog orchestration for bulk theme updates.
 *
 * HIDDEN entirely (returns null) when pendingCount === 0. Different from
 * P2.6's SyncAllSitesButton which renders disabled — here, the absence of
 * pending updates means there's nothing to bulk-update; surfacing a
 * disabled button would add visual noise.
 *
 * Mirror of P2.7's BulkUpdatePluginsButton with theme swap.
 */
export function BulkUpdateThemesButton({ pendingCount }: BulkUpdateThemesButtonProps): JSX.Element | null {
  const [dialogOpen, setDialogOpen] = useState(false);
  const { data } = usePendingThemeUpdates(dialogOpen);
  const mutation = useBulkUpdateThemes();

  if (pendingCount === 0) return null;

  const rows = data?.pending_updates ?? [];

  const handleConfirm = (pairs: Array<{ site_id: number; slug: string }>): void => {
    mutation.mutate({ updates: pairs }, {
      onSuccess: () => setDialogOpen(false),
    });
  };

  return (
    <>
      <Button
        variant="outline"
        onClick={() => setDialogOpen(true)}
        disabled={mutation.isPending}
      >
        Bulk update themes ({pendingCount})
      </Button>
      <ConfirmBulkUpdateThemesDialog
        open={dialogOpen}
        rows={rows}
        onCancel={() => setDialogOpen(false)}
        onConfirm={handleConfirm}
      />
    </>
  );
}
```

- [ ] **Step 4: Wire it into Overview.tsx**

Edit `apps/web/src/routes/Overview.tsx` — add the import:

```tsx
import { BulkUpdateThemesButton } from '@/components/overview/BulkUpdateThemesButton';
```

In the header's right-column stack (where `<BulkUpdatePluginsButton>` is rendered), add immediately AFTER it:

```tsx
<BulkUpdateThemesButton pendingCount={data.pending_updates.themes} />
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd apps/web && pnpm test -- --run BulkUpdateThemesButton`

Expected: 2 PASS.

Run the full suite to confirm no regressions:

Run: `cd apps/web && pnpm test -- --run`

Expected: 208 + new tests pass; 4 documented carry-forward failures only.

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/components/overview/BulkUpdateThemesButton.tsx \
        apps/web/src/routes/Overview.tsx \
        apps/web/tests/components/overview/BulkUpdateThemesButton.test.tsx
git commit -m "feat(p2-8): BulkUpdateThemesButton + Overview header integration

HIDDEN entirely (returns null) when pendingCount === 0. Mirror of P2.7's
BulkUpdatePluginsButton with theme swap. Orchestrates
usePendingThemeUpdates (enabled-on-open) + useBulkUpdateThemes mutation
+ ConfirmBulkUpdateThemesDialog.

Overview header now renders 3 buttons in the right column:
- SyncAllSitesButton (always visible, disabled at 0)
- BulkUpdatePluginsButton (hidden at 0)
- BulkUpdateThemesButton (hidden at 0) ← NEW

2 component tests:
- hiddenWhenPendingCountIsZero
- visibleWithCountWhenPendingCountGreaterThanZero

Per spec § 3.1 + plan-bug trap #8."
```

---

## Task 11 — Build zips + 8-step manual smoke matrix

**Files:**
- Build artifact: `dist/defyn-dashboard-0.8.1.zip` (NEW)
- No connector zip needed (connector unchanged at v0.1.7)

- [ ] **Step 1: Build the dashboard zip**

```bash
cd packages/dashboard-plugin
composer install --no-dev --classmap-authoritative
cd ../..

mkdir -p dist
cd packages
zip -r ../dist/defyn-dashboard-0.8.1.zip dashboard-plugin \
  -x 'dashboard-plugin/tests/*' \
  -x 'dashboard-plugin/vendor/wordpress/*' \
  -x 'dashboard-plugin/vendor/johnpbloch/*' \
  -x 'dashboard-plugin/vendor/phpunit/*' \
  -x 'dashboard-plugin/vendor/wp-phpunit/*' \
  -x 'dashboard-plugin/vendor/yoast/*' \
  -x 'dashboard-plugin/vendor/myclabs/*' \
  -x 'dashboard-plugin/vendor/symfony/*' \
  -x 'dashboard-plugin/vendor/theseer/*' \
  -x 'dashboard-plugin/vendor/sebastian/*' \
  -x 'dashboard-plugin/vendor/phpunit*' \
  -x 'dashboard-plugin/*wp-tests-config.php' \
  -x 'dashboard-plugin/.phpunit.result.cache' \
  -x 'dashboard-plugin/composer.lock'
cd ..

ls -lah dist/defyn-dashboard-0.8.1.zip
```

Expected: ~552KB.

Restore dev autoload so local tests still work:

```bash
cd packages/dashboard-plugin && composer install && cd ../..
```

- [ ] **Step 2: Push branch + main**

```bash
git push origin p2-8-bulk-theme-updates
git checkout main
git merge --ff-only p2-8-bulk-theme-updates
git push origin main
git checkout p2-8-bulk-theme-updates
```

Cloudflare Pages auto-deploys SPA from main (~60-90s).

- [ ] **Step 3: Upload dashboard zip to Kinsta**

1. WP Admin (`defynwp.defyn.agency/wp-admin`) → Plugins → Add New → Upload Plugin → choose `dist/defyn-dashboard-0.8.1.zip` → Install Now → **"Replace current with uploaded version"**.
2. After install completes, click MyKinsta → Tools → **Clear cache** (busts OPcache + page cache + Redis — guardrail #23).
3. Visit `defynwp.defyn.agency/wp-admin/plugins.php` and confirm "Defyn Dashboard" shows Version 0.8.1.

- [ ] **Step 4: Wait for Cloudflare Pages deploy + verify**

```bash
until [ "$(curl -s -o /dev/null -w '%{http_code}' https://app.defynwp.defyn.agency/overview)" = "200" ]; do sleep 5; done
echo "SPA up"
NEW_JS=$(curl -s "https://app.defynwp.defyn.agency/?cb=$(uuidgen)" | grep -oE 'index-[A-Za-z0-9]+\.js' | head -1)
echo "Latest JS: $NEW_JS"
curl -s --compressed "https://app.defynwp.defyn.agency/assets/$NEW_JS" | grep -oE "Bulk update themes|bulk-update-themes|pending-theme-updates" | sort -u
```

Expected: at least `Bulk update themes` + `bulk-update-themes` present.

- [ ] **Step 5: Run 8-step smoke matrix**

Set up the auth token (Bearer JWT from a prior login):

```bash
export DEFYN_TOKEN="<paste-from-prior-session-OR-login-via-curl>"
```

Run the 8 smoke steps from spec § 5:

```bash
# Step 1 — GET no-auth → 401
curl -sw "\nHTTP=%{http_code}\n" https://defynwp.defyn.agency/wp-json/defyn/v1/overview/pending-theme-updates

# Step 2 — GET with auth → 200 + shape
curl -sw "\nHTTP=%{http_code}\n" \
  -H "Authorization: Bearer $DEFYN_TOKEN" \
  "https://defynwp.defyn.agency/wp-json/defyn/v1/overview/pending-theme-updates?_=$(uuidgen)"

# Step 3 — POST empty → 400
curl -sw "\nHTTP=%{http_code}\n" \
  -H "Authorization: Bearer $DEFYN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"updates":[]}' \
  https://defynwp.defyn.agency/wp-json/defyn/v1/overview/bulk-update-themes

# Step 4 — POST with valid pairs → 202 (or 200 if all skipped per zero-sites carry-forward)
# Substitute actual site_id + slug from Step 2's response. If empty, proceed to Step 5.
curl -sw "\nHTTP=%{http_code}\n" \
  -H "Authorization: Bearer $DEFYN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"updates":[{"site_id":1,"slug":"astra"}]}' \
  https://defynwp.defyn.agency/wp-json/defyn/v1/overview/bulk-update-themes

# Step 5 — POST all-invalid → 200 with 3 distinct skip reasons + NO activity event
curl -sw "\nHTTP=%{http_code}\n" \
  -H "Authorization: Bearer $DEFYN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"updates":[{"site_id":999,"slug":"nonexistent"},{"site_id":998,"slug":"phantom"},{"site_id":997,"slug":"ghost"}]}' \
  https://defynwp.defyn.agency/wp-json/defyn/v1/overview/bulk-update-themes

# Step 6 — Rate limit: 6 sequential calls → 6th returns 429 bulk.rate_limited
for i in 1 2 3 4 5 6; do
  echo "Call $i:"
  curl -sw " HTTP=%{http_code}\n" \
    -H "Authorization: Bearer $DEFYN_TOKEN" \
    -H "Content-Type: application/json" \
    -d '{"updates":[{"site_id":999,"slug":"x"}]}' \
    https://defynwp.defyn.agency/wp-json/defyn/v1/overview/bulk-update-themes
done

# Step 7 — SPA Overview header — visit /overview post-login + visually verify
echo "Visit https://app.defynwp.defyn.agency/overview — BulkUpdateThemesButton visible iff pending_updates.themes > 0"

# Step 8 — SPA dialog — open ConfirmBulkUpdateThemesDialog + visually verify Skip-major toggle
echo "Open the dialog from Step 7 and verify Skip-major toggle present + default OFF + flipping ON hides major rows"
```

For each step, paste the response (status code + body excerpts) into a smoke notes file.

**Carry-forward (per spec § 5 + plan-bug trap #24):**
- Step 4 may be foreclosed if prod `wp_defyn_sites` table is empty for user 1 (continues from P2.6 + P2.7). If so, fall back to test coverage as proof of the destructive write path.
- Step 6 may trigger 429 earlier than the 6th call due to Kinsta Redis stale-transient cache (continues from P2.4.1 + P2.5 + P2.6 + P2.7). Mechanism still verified correct — assert the limit triggers + the error envelope is right.

- [ ] **Step 6: Commit the smoke notes (optional)**

If you captured smoke notes:

```bash
git add docs/superpowers/smoke/p2-8-smoke-notes.md
git commit -m "docs(p2-8): production smoke notes for v0.8.1 release"
```

(Skip this commit if no notes file was created.)

---

## Task 12 — Tag `p2-8-bulk-theme-updates-complete` + push + MEMORY

**Files:**
- Git tag: `p2-8-bulk-theme-updates-complete`
- MEMORY: `~/.claude/projects/-Users-pradeep-Local-Sites-defynWP/memory/MEMORY.md`

- [ ] **Step 1: Create + push the tag**

```bash
git -C "/Users/pradeep/Local Sites/defynWP" tag -a p2-8-bulk-theme-updates-complete -m "P2.8 — Bulk theme updates across fleet

Dashboard v0.8.1 live in prod. Connector v0.1.7 unchanged. Schema v6
unchanged.

Two new REST endpoints:
- GET /defyn/v1/overview/pending-theme-updates (30/MIN)
- POST /defyn/v1/overview/bulk-update-themes (5/HR)

Fan-outs existing P2.3 defyn_update_site_theme AS job per validated
(site_id, slug) pair. Emits ONE fleet-scoped
overview.bulk_theme_update_requested activity event when
scheduled_count > 0. Bypasses per-resource themesUpdate 6/HR bucket.

ThemesRepository::findAllPendingUpdatesForUser is the new INNER JOIN'd
query. SitesRepository::findByIdForUser handles ownership.

SPA: BulkUpdateThemesButton (hidden when count=0) +
ConfirmBulkUpdateThemesDialog (with day-1 skipMajor toggle from P2.7.1
pattern) + PendingThemeUpdatesGroup sub-component +
usePendingThemeUpdates (enabled-on-open) + useBulkUpdateThemes
mutation hooks. Renamed isPluginMajorBump → isMajorBump in
apps/web/src/lib/semver.ts (resource-agnostic).

PHP tests: 5 (pending GET) + 8 (bulk POST) + 3 (repository) + 2 (CORS) =
18 new tests.

SPA tests: 8 (semver renamed) + 2 (usePendingThemeUpdates) +
2 (useBulkUpdateThemes) + 7 (ConfirmBulkUpdateThemesDialog) +
2 (BulkUpdateThemesButton) = 21 new + 7 renamed tests."

git -C "/Users/pradeep/Local Sites/defynWP" push origin p2-8-bulk-theme-updates-complete
```

- [ ] **Step 2: Update MEMORY index**

Edit `~/.claude/projects/-Users-pradeep-Local-Sites-defynWP/memory/MEMORY.md`:

Find the end of the long index entry for `[DefynWP project overview]` (the line that currently ends with `Next: P2.8 (bulk theme updates) → bulk-jobs entity (cancel/resume/history) → filtered drill-in /overview/plugins route.`). Replace that trailing `Next:` sentence with:

```
**P2.8 (Bulk theme updates) COMPLETE 2026-06-09** — tag `p2-8-bulk-theme-updates-complete`, dashboard v0.8.1 live in prod (connector unchanged at v0.1.7, schema unchanged at v6). Two new endpoints: `GET /defyn/v1/overview/pending-theme-updates` (30/MIN) + `POST /defyn/v1/overview/bulk-update-themes` (5/HR per user). Mirrors P2.7 structural shape: validates each `(site_id, slug)` pair with 3 skip reasons (`site_not_owned`, `theme_not_found`, `no_update_available`), fan-outs the existing P2.3 `defyn_update_site_theme` AS job per valid pair, emits ONE fleet-scoped `overview.bulk_theme_update_requested` activity event (site_id=null) ONLY when scheduled_count > 0. Bulk endpoint BYPASSES the per-(user, site, slug) `themesUpdate` 6/HR bucket — operator's dialog confirmation IS the safety. New `ThemesRepository::findAllPendingUpdatesForUser` INNER JOIN'd query (NOT `SiteThemesRepository` — spec used that longer name but actual class is `ThemesRepository`). **Day-1 minor-only filter baked in** (no P2.8.1 follow-up needed) — semver helper renamed `isPluginMajorBump` → `isMajorBump` in `apps/web/src/lib/semver.ts` (resource-agnostic; P2.7.1 plugin dialog import auto-updated). SPA gets `BulkUpdateThemesButton` on Overview header (HIDDEN when `pending_updates.themes === 0`, matches P2.7 pattern), `ConfirmBulkUpdateThemesDialog` (mirror of P2.7.1 dialog including `skipMajor` toggle + `visibleRows` useMemo filter + `ROWS_WITH_MAJOR` fixture), `PendingThemeUpdatesGroup` sub-component, `usePendingThemeUpdates(dialogOpen)` enabled-on-open query hook, `useBulkUpdateThemes` mutation invalidating `['overview']` + `['pendingThemeUpdates']` (NOT `['sites']`). **Plan-correction caught during plan-writing:** spec used `stylesheet` (WordPress terminology) but actual DB column + codebase uses `slug` — plan corrected to `slug` throughout (DB + REST API + SPA). 21 new tests + 7 P2.7.1 tests renamed for `isMajorBump`. Next: P2.9 (bulk-jobs entity for cancel/resume/history) → P2.10 (filtered drill-in `/overview/plugins` + `/overview/themes` routes).
```

- [ ] **Step 3: Final verification**

```bash
git -C "/Users/pradeep/Local Sites/defynWP" tag --list "p2-8*"
git -C "/Users/pradeep/Local Sites/defynWP" log --oneline -5
```

Expected:
- `p2-8-bulk-theme-updates-complete` tag exists.
- Branch + main at tip with all P2.8 commits.

---

## Self-review summary

**Spec coverage:**
- §1 architecture → Task 1-10 cover endpoints, repository, SPA hooks, components, header integration.
- §2 REST contract → Task 1 (repo) + Task 2 (GET) + Task 3 (POST) + Task 4 (CORS + version) cover all endpoint behavior + skip reasons + rate limits + activity event.
- §3 SPA UI → Task 5 (semver rename) + Task 6 (schemas + MSW) + Task 7 (query hook) + Task 8 (mutation) + Task 9 (dialog + sub-component) + Task 10 (button + integration).
- §4 SPA hooks → Task 7 + Task 8.
- §5 smoke matrix → Task 11 (8 steps).
- §6 deferred → noted in tag message + MEMORY.
- §7 plan-bug guardrails → 24 traps surfaced in workflow conventions; relevant trap referenced in each task.

**Placeholder scan:** No `TBD`, `TODO`, `implement later`, `similar to Task N`. All code blocks complete. Commands have expected outputs.

**Type consistency:** `findAllPendingUpdatesForUser` signature consistent across PHP repo Task 1 + tests + Task 2 controller. `slug` used everywhere (not `stylesheet`). `isMajorBump` renamed in Task 5 used in Task 9.

**Plan-correction noted:** spec used `SiteThemesRepository` and `stylesheet`; plan uses `ThemesRepository` and `slug` to match actual codebase. Surfaced in plan-bug trap #15 + #16 + Task 1 step 3.

---

**End of plan.** Estimated effort: 12 atomic commits across 3 phases (Backend / SPA / Ship). Pure SPA rename in Task 5 is the only cross-feature surface that touches existing P2.7.1 code; everything else is additive.
