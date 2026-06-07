# P2.5 Operator Overview Dashboard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a cross-site aggregate dashboard at `/overview` so operators see pending updates, sites needing attention, and recent activity at a glance instead of drilling site-by-site. Read-only MVP. Bulk actions deferred to P2.6. Output: dashboard v0.6.0 → v0.7.0, no connector changes, SPA gets a new landing page + a filter param on `/sites`.

**Architecture:** ONE new REST endpoint `GET /defyn/v1/overview` computes the 3-section response via live SQL aggregation (no cache — 5 indexed queries against existing tables). ONE new SPA route `/overview` polls every 60s via TanStack Query. THREE new widget components render Layout A (hero strip + bottom split). `SitesList` gains a `?filter=` query-string filter so count cards on the overview drill into filtered sites views.

**Tech Stack:** PHP 8.1+ (PHPUnit, `WP_UnitTestCase` / `AbstractSchemaTestCase`), WordPress REST API, React 18 + TypeScript + TanStack Query v5 + Zod + Tailwind + shadcn/ui + Vitest + React Testing Library + MSW + react-router-dom v6.

**Spec:** [`docs/superpowers/specs/2026-06-07-p2-5-overview-dashboard-design.md`](../specs/2026-06-07-p2-5-overview-dashboard-design.md)

---

## Workflow conventions

- **Branch:** Branch off **`p2-4-1-major-core-updates`** (current tip `e837253` — the just-committed P2.5 spec). Main was fast-forwarded to that same commit during P2.4.1 ship, so either base is equivalent. Branch name: `p2-5-overview-dashboard`. Command:
  ```
  git checkout -b p2-5-overview-dashboard p2-4-1-major-core-updates
  ```
- **Each Task = one atomic commit.**
- **Test discipline (TDD):** Step 1 writes the failing test. Step 2 runs it and confirms it fails. Step 3 writes the implementation. Step 4 confirms it passes. Step 5 commits.
- **Test runners:**
  - Dashboard PHP: `cd packages/dashboard-plugin && composer test`
  - SPA: `cd apps/web && pnpm test -- --run`
- **Commit message format:** `<type>(p2-5): <description>` where `<type>` ∈ {feat, fix, refactor, docs, test, chore}.
- **All edits adhere to** `~/.claude/rules/common/coding-style.md` — immutability, KISS, DRY, YAGNI, error handling, no console.log / var_dump / print_r.
- **No connector changes.** Connector stays at v0.1.7. The 8-step smoke does NOT require connector reinstall.

### Plan-bug traps to internalise before writing any code

1. **`RateLimit::OVERVIEW_LIMIT = 30`** — **PER MINUTE**, NOT per hour. `OVERVIEW_WINDOW = MINUTE_IN_SECONDS`. Test method MUST be `testRateLimit429AfterThirtyFirstCall`. This is the FIRST per-minute bucket in the project — all prior buckets (login at 60s window, plugins-update, themes-update, core-update, core-allow-major) are per-hour. Easy copy-paste trap. Use `MINUTE_IN_SECONDS` (WordPress constant = 60), NOT a magic literal `60`.

2. **Activity-log JOIN uses `idx_activity_site_created_at`** — express user-ownership filter as `EXISTS` or subquery, NOT a plain `LEFT JOIN`. Plain LEFT JOIN forces a full-scan plan once the activity log grows past ~100k rows. The composite index is `(site_id, created_at DESC)` from F9 — the subquery form lets MySQL use it.

3. **Layout A grid responsive collapse** — `grid-cols-3 gap-4` at `md:` and above. Below `md:` stacks vertically. Tailwind: `<div className="grid grid-cols-1 gap-4 md:grid-cols-3">`. Test asserts both breakpoints render the expected DOM.

4. **Attention thresholds hardcoded** — 15 min offline, 30 day SSL, 24 hr sync-stale. Tests assert the exact thresholds. Per-user config is P2.6 territory.

5. **Empty-state guard** — zero sites → redirect to `/sites/add`, NOT render "0 of 0" everywhere. (Note: existing route is `/sites/add`, NOT `/sites/new` as I initially wrote in spec — verified by reading App.tsx routes.)

6. **Count cards click target** — `/sites?filter=has-plugin-updates|has-theme-updates|has-core-update`. URL query param parsed in `SitesList.tsx` via React Router's `useSearchParams` hook.

7. **`SitesList` filter parsing** — URL state via `useSearchParams`, NOT local `useState`. Back-button navigates correctly. Filter is OPTIONAL on `useSites()` — no filter = existing behavior preserved (zero regression risk for the existing route).

8. **`AttentionReasonChip` palette** — red (`bg-red-100 text-red-800`) for `offline` / `failed_update`, amber (`bg-amber-100 text-amber-800`) for `ssl_expiring` / `sync_stale`. Test asserts exact class names.

9. **`generated_at` is server-side timestamp** — SPA "Last refreshed" computes relative time from this field via a simple helper, NOT React Query's `dataUpdatedAt`. Server time is canonical so tests can stub it via MSW.

10. **No connector changes.** Connector stays at v0.1.7. Smoke does NOT require connector reinstall. Plan only touches `packages/dashboard-plugin/` + `apps/web/`.

11. **Dashboard zip build:** `composer install --no-dev --classmap-authoritative` FIRST (NOT just `dump-autoload --no-dev`). Exclude `vendor/wordpress/*` + `vendor/johnpbloch/*` + dev packages. Target zip size ~570KB. After zipping, run `composer install` to restore dev autoload. (Burned 2 hours debugging on P2.4.1; do not skip.)

12. **OPcache + Redis cache discipline:** after dashboard plugin replace on Kinsta, hit MyKinsta "Clear cache" (Tools tab). Without it, new routes may 404 / behave on stale code for hours. (Plan-bug from P2.4.1 production smoke — MEMORY.md item.)

13. **Final smoke matrix is § 6.2 of the spec verbatim — 8 steps.** Tag `p2-5-overview-dashboard-complete` ONLY after all 8 pass AND § 6.3 cleanup is applied.

### Existing-code anchors (read these before starting any task)

- `packages/dashboard-plugin/src/Services/SitesRepository.php` — public methods include `findAllForUser(int $userId): array` at line ~87. P2.5 extends with optional filter param + 5 count methods + `findSitesNeedingAttention`. Last P2.4.1 addition was `setCoreAllowMajor(int $siteId, bool $allow): void` at line ~390 — append new methods after that.
- `packages/dashboard-plugin/src/Services/ActivityLogRepository.php` — already has `paginateForUser`, `countForUser`, `insert`. P2.5 adds `tailForUser(int $userId, int $limit = 25): array`.
- `packages/dashboard-plugin/src/Services/ActivityLogger.php` — `log(?int $userId, ?int $siteId, string $eventType, ?array $details = null, ?string $ipAddress = null): void` — NOT modified by P2.5 (read-only phase).
- `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php` — most recent constants `CORE_ALLOW_MAJOR_LIMIT = 10` + `CORE_ALLOW_MAJOR_WINDOW = HOUR_IN_SECONDS` at lines 72-73. Most recent method `coreAllowMajor(WP_REST_Request $request)` at line 320. Append new constants + method below those.
- `packages/dashboard-plugin/src/Rest/RestRouter.php` — register new route alongside existing `/sites/(?P<id>\d+)/core/allow-major` from P2.4.1.
- `apps/web/src/routes/Home.tsx` — currently `<Navigate to="/sites" replace />`. P2.5 changes target to `/overview`.
- `apps/web/src/lib/queries/useSites.ts` — currently takes no args. P2.5 extends to accept optional `filter` param.
- `apps/web/src/routes/SitesList.tsx` — reads URL via `useSearchParams`, passes `filter` to `useSites({filter})`.
- `apps/web/src/App.tsx` lines 12-21 — Routes table. Add `<Route path="/overview" element={<Overview />} />` inside the `<RequireAuth>` block. Also keep existing `<Route path="/" element={<Home />} />` since Home now redirects to `/overview`.

---

## File structure overview

### Dashboard plugin (v0.7.0) — new files

| Path | Responsibility |
|---|---|
| `src/Rest/OverviewController.php` | GET /defyn/v1/overview — auth, compose response from OverviewService, emit JSON |
| `src/Services/OverviewService.php` | Compose 3-section response by orchestrating SitesRepository + ActivityLogRepository |
| `tests/Integration/Rest/OverviewControllerTest.php` | 5 tests — auth, happy, rate limit, ownership, cache headers |
| `tests/Unit/Services/OverviewServiceTest.php` | 5 tests — each attention criterion + multi-reason combined |
| `tests/Integration/Services/SitesRepositoryOverviewTest.php` | 13 tests — 5 count methods + attention + filter |
| `tests/Integration/Services/ActivityLogRepositoryTailTest.php` | 3 tests — tailForUser ordering + scoping + label join |
| `tests/Integration/Rest/OverviewCorsTest.php` | CORS preflight regression for /overview |

### Dashboard plugin — modified files

| Path | What changes |
|---|---|
| `src/Services/SitesRepository.php` | Add 5 count methods + `findSitesNeedingAttention` + extend `findAllForUser` with optional filter |
| `src/Services/ActivityLogRepository.php` | Add `tailForUser(int $userId, int $limit = 25): array` using EXISTS subquery |
| `src/Rest/Middleware/RateLimit.php` | Add `OVERVIEW_LIMIT/WINDOW` constants + `overview()` method (per-minute bucket) |
| `src/Rest/RestRouter.php` | Register `/overview` route |
| `defyn-dashboard.php` | Version `0.6.0` → `0.7.0` |
| `readme.txt` | Stable tag + changelog entry |
| `composer.json` | Version `0.6.0` → `0.7.0` |

### SPA (apps/web) — new files

| Path | Responsibility |
|---|---|
| `src/routes/Overview.tsx` | Top-level route. Renders 3 widgets in Layout A grid. Calls `useOverview()`. |
| `src/components/overview/PendingUpdatesWidget.tsx` | Three big-number count cards (plugins/themes/cores). |
| `src/components/overview/SitesNeedingAttentionWidget.tsx` | Left-bottom panel — list of sites with reason chips. |
| `src/components/overview/RecentActivityWidget.tsx` | Right-bottom panel — last 25 cross-site events. |
| `src/components/overview/AttentionReasonChip.tsx` | Chip renderer (red for offline/failed_update, amber for ssl_expiring/sync_stale). |
| `src/lib/queries/useOverview.ts` | TanStack hook polling 60s. |
| `tests/components/overview/PendingUpdatesWidget.test.tsx` | 2 tests |
| `tests/components/overview/SitesNeedingAttentionWidget.test.tsx` | 3 tests |
| `tests/components/overview/RecentActivityWidget.test.tsx` | 2 tests |
| `tests/components/overview/AttentionReasonChip.test.tsx` | 2 tests |
| `tests/lib/queries/useOverview.test.tsx` | 2 tests |
| `tests/routes/Overview.test.tsx` | 2 tests — full route render + error state |

### SPA — modified files

| Path | What changes |
|---|---|
| `src/types/api.ts` | Add `overviewSchema` Zod mirroring REST shape |
| `src/test/handlers.ts` | Add MSW handler for GET /overview |
| `src/routes/Home.tsx` | `<Navigate to="/sites"/>` → `<Navigate to="/overview"/>` |
| `src/lib/queries/useSites.ts` | Accept optional `{filter}` param, append as query string |
| `src/routes/SitesList.tsx` | Parse `?filter=` via `useSearchParams`, pass to `useSites` |
| `src/App.tsx` | Register `/overview` route |

---

## Task 1 — `SitesRepository` — 5 count methods

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/SitesRepository.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/SitesRepositoryOverviewTest.php` (CREATE)

### Step 1: Write the failing test

Create `packages/dashboard-plugin/tests/Integration/Services/SitesRepositoryOverviewTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SitesRepositoryOverviewTest extends AbstractSchemaTestCase
{
    public function testCountPendingPluginsReturnsZeroWhenNoSites(): void
    {
        $this->assertSame(0, (new SitesRepository())->countPendingPlugins(1));
    }

    public function testCountPendingPluginsReturnsCorrectCountAcrossOwnedSites(): void
    {
        $siteA = $this->seedSite(1);
        $siteB = $this->seedSite(1);
        $siteC = $this->seedSite(2); // different user

        $this->seedPlugin($siteA, 'akismet/akismet.php', true);
        $this->seedPlugin($siteA, 'yoast/yoast.php', true);
        $this->seedPlugin($siteB, 'jetpack/jetpack.php', true);
        $this->seedPlugin($siteC, 'wpml/wpml.php', true); // owned by user 2 — must NOT count for user 1

        $this->assertSame(3, (new SitesRepository())->countPendingPlugins(1));
        $this->assertSame(1, (new SitesRepository())->countPendingPlugins(2));
    }

    public function testCountPendingThemesReturnsCorrectCountAcrossOwnedSites(): void
    {
        $siteA = $this->seedSite(1);
        $this->seedTheme($siteA, 'twentytwentyfour', true);
        $this->seedTheme($siteA, 'astra', false); // no update available — must NOT count

        $this->assertSame(1, (new SitesRepository())->countPendingThemes(1));
    }

    public function testCountPendingCoresMinorReturnsCorrectCount(): void
    {
        global $wpdb;
        $siteA = $this->seedSite(1);
        $siteB = $this->seedSite(1);

        // siteA: 7.0 -> 7.0.1 (minor)
        $wpdb->update($wpdb->prefix . 'defyn_sites', [
            'wp_version' => '7.0',
            'core_update_available' => 1,
            'core_update_version' => '7.0.1',
        ], ['id' => $siteA]);

        // siteB: 7.0 -> 8.0 (major)
        $wpdb->update($wpdb->prefix . 'defyn_sites', [
            'wp_version' => '7.0',
            'core_update_available' => 1,
            'core_update_version' => '8.0',
        ], ['id' => $siteB]);

        $repo = new SitesRepository();
        $this->assertSame(1, $repo->countPendingCoresMinor(1));
        $this->assertSame(1, $repo->countPendingCoresMajor(1));
    }

    public function testCountSitesWithAnyUpdateUnionsAcrossPluginThemeAndCore(): void
    {
        global $wpdb;
        $siteA = $this->seedSite(1);
        $siteB = $this->seedSite(1);
        $siteC = $this->seedSite(1);

        $this->seedPlugin($siteA, 'akismet/akismet.php', true);          // siteA has plugin update
        $this->seedTheme($siteB, 'twentytwentyfour', true);              // siteB has theme update
        $wpdb->update($wpdb->prefix . 'defyn_sites', [
            'wp_version' => '7.0',
            'core_update_available' => 1,
            'core_update_version' => '7.0.1',
        ], ['id' => $siteC]);                                            // siteC has core update

        $this->assertSame(3, (new SitesRepository())->countSitesWithAnyUpdate(1));
    }

    private function seedSite(int $userId): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'    => $userId,
            'url'        => 'https://example' . microtime(true) . '.com',
            'label'      => 'Example',
            'status'     => 'active',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $wpdb->insert_id;
    }

    private function seedPlugin(int $siteId, string $slug, bool $updateAvailable): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_site_plugins', [
            'site_id'          => $siteId,
            'slug'             => $slug,
            'name'             => $slug,
            'version'          => '1.0',
            'active'           => 1,
            'update_available' => $updateAvailable ? 1 : 0,
            'updated_at'       => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function seedTheme(int $siteId, string $slug, bool $updateAvailable): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_site_themes', [
            'site_id'          => $siteId,
            'slug'             => $slug,
            'name'             => $slug,
            'version'          => '1.0',
            'active'           => 1,
            'update_available' => $updateAvailable ? 1 : 0,
            'updated_at'       => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
```

Test method names MUST be EXACTLY:
- `testCountPendingPluginsReturnsZeroWhenNoSites`
- `testCountPendingPluginsReturnsCorrectCountAcrossOwnedSites`
- `testCountPendingThemesReturnsCorrectCountAcrossOwnedSites`
- `testCountPendingCoresMinorReturnsCorrectCount`
- `testCountSitesWithAnyUpdateUnionsAcrossPluginThemeAndCore`

If `_site_plugins` / `_site_themes` schemas differ (e.g. require additional NOT NULL columns), adapt the `seedPlugin/seedTheme` helpers to match — read the migration methods in `src/Activation.php` for the canonical schema.

### Step 2: Run test to verify it fails

```
cd packages/dashboard-plugin && composer test -- --filter SitesRepositoryOverviewTest
```

Expected: FAIL — `Call to undefined method SitesRepository::countPendingPlugins`.

### Step 3: Add the count methods

In `packages/dashboard-plugin/src/Services/SitesRepository.php`, append AFTER the existing `setCoreAllowMajor` method (~line 390):

```php
/**
 * P2.5 — count of pending plugin updates across all sites owned by $userId.
 */
public function countPendingPlugins(int $userId): int
{
    $sitesTable   = $this->table;
    $pluginsTable = $this->wpdb->prefix . 'defyn_site_plugins';

    return (int) $this->wpdb->get_var($this->wpdb->prepare(
        "SELECT COUNT(*)
         FROM {$pluginsTable} sp
         INNER JOIN {$sitesTable} s ON s.id = sp.site_id
         WHERE s.user_id = %d
           AND sp.update_available = 1",
        $userId
    ));
}

/**
 * P2.5 — count of pending theme updates across all sites owned by $userId.
 */
public function countPendingThemes(int $userId): int
{
    $sitesTable  = $this->table;
    $themesTable = $this->wpdb->prefix . 'defyn_site_themes';

    return (int) $this->wpdb->get_var($this->wpdb->prepare(
        "SELECT COUNT(*)
         FROM {$themesTable} st
         INNER JOIN {$sitesTable} s ON s.id = st.site_id
         WHERE s.user_id = %d
           AND st.update_available = 1",
        $userId
    ));
}

/**
 * P2.5 — count of pending MINOR core updates (major+minor segments match).
 * A bump is "minor" when wp_version major.minor === core_update_version major.minor.
 */
public function countPendingCoresMinor(int $userId): int
{
    return (int) $this->wpdb->get_var($this->wpdb->prepare(
        "SELECT COUNT(*)
         FROM {$this->table}
         WHERE user_id = %d
           AND core_update_available = 1
           AND core_update_version IS NOT NULL
           AND SUBSTRING_INDEX(wp_version, '.', 2) = SUBSTRING_INDEX(core_update_version, '.', 2)",
        $userId
    ));
}

/**
 * P2.5 — count of pending MAJOR core updates (major or minor segments differ).
 */
public function countPendingCoresMajor(int $userId): int
{
    return (int) $this->wpdb->get_var($this->wpdb->prepare(
        "SELECT COUNT(*)
         FROM {$this->table}
         WHERE user_id = %d
           AND core_update_available = 1
           AND core_update_version IS NOT NULL
           AND SUBSTRING_INDEX(wp_version, '.', 2) != SUBSTRING_INDEX(core_update_version, '.', 2)",
        $userId
    ));
}

/**
 * P2.5 — count of sites owned by $userId that have ANY pending update
 * (plugin OR theme OR core). Uses UNION DISTINCT to deduplicate sites that
 * have multiple kinds of pending updates.
 */
public function countSitesWithAnyUpdate(int $userId): int
{
    $sitesTable   = $this->table;
    $pluginsTable = $this->wpdb->prefix . 'defyn_site_plugins';
    $themesTable  = $this->wpdb->prefix . 'defyn_site_themes';

    return (int) $this->wpdb->get_var($this->wpdb->prepare(
        "SELECT COUNT(DISTINCT site_id) FROM (
            SELECT sp.site_id FROM {$pluginsTable} sp
              INNER JOIN {$sitesTable} s ON s.id = sp.site_id
              WHERE s.user_id = %d AND sp.update_available = 1
            UNION
            SELECT st.site_id FROM {$themesTable} st
              INNER JOIN {$sitesTable} s ON s.id = st.site_id
              WHERE s.user_id = %d AND st.update_available = 1
            UNION
            SELECT id FROM {$sitesTable}
              WHERE user_id = %d AND core_update_available = 1
         ) AS combined",
        $userId, $userId, $userId
    ));
}
```

(If `$this->table` and `$this->wpdb` property names differ in the actual class, adapt accordingly — they should match the existing P2.4 methods like `markCoreUpdateRequested`.)

### Step 4: Run tests to verify they pass

```
cd packages/dashboard-plugin && composer test -- --filter SitesRepositoryOverviewTest
```
Expected: PASS — all 5 count tests green.

Also run existing baseline to confirm no regression:
```
cd packages/dashboard-plugin && composer test -- --filter SitesRepositoryTest
```
Expected: PASS.

### Step 5: Commit

```bash
git -C "/Users/pradeep/Local Sites/defynWP" add packages/dashboard-plugin/src/Services/SitesRepository.php \
        packages/dashboard-plugin/tests/Integration/Services/SitesRepositoryOverviewTest.php
git -C "/Users/pradeep/Local Sites/defynWP" commit -m "feat(p2-5): SitesRepository — 5 count methods for overview aggregates

countPendingPlugins, countPendingThemes, countPendingCoresMinor,
countPendingCoresMajor, countSitesWithAnyUpdate. All scoped to userId
via INNER JOIN with sites table for ownership. Per spec § 3."
```

---

## Task 2 — `SitesRepository::findSitesNeedingAttention` + `findAllForUser` filter

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/SitesRepository.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/SitesRepositoryOverviewTest.php` (extend)

### Step 1: Append failing tests

In the existing `SitesRepositoryOverviewTest.php` file, append these test methods inside the class:

```php
public function testFindSitesNeedingAttentionReturnsEmptyWhenAllHealthy(): void
{
    global $wpdb;
    $siteA = $this->seedSite(1);
    $wpdb->update($wpdb->prefix . 'defyn_sites', [
        'last_contact_at' => gmdate('Y-m-d H:i:s'),
        'last_sync_at'    => gmdate('Y-m-d H:i:s'),
        'ssl_expires_at'  => gmdate('Y-m-d H:i:s', strtotime('+90 days')),
    ], ['id' => $siteA]);

    $this->assertSame([], (new SitesRepository())->findSitesNeedingAttention(1));
}

public function testFindSitesNeedingAttentionFlagsOfflineSitesPast15MinThreshold(): void
{
    global $wpdb;
    $siteA = $this->seedSite(1);
    $wpdb->update($wpdb->prefix . 'defyn_sites', [
        'last_contact_at' => gmdate('Y-m-d H:i:s', strtotime('-20 minutes')),
        'last_sync_at'    => gmdate('Y-m-d H:i:s'),
        'ssl_expires_at'  => gmdate('Y-m-d H:i:s', strtotime('+90 days')),
    ], ['id' => $siteA]);

    $result = (new SitesRepository())->findSitesNeedingAttention(1);
    $this->assertCount(1, $result);
    $this->assertSame($siteA, $result[0]['site_id']);
    $this->assertContains('offline', $result[0]['reasons']);
}

public function testFindSitesNeedingAttentionFlagsSslExpiringWithin30Days(): void
{
    global $wpdb;
    $siteA = $this->seedSite(1);
    $wpdb->update($wpdb->prefix . 'defyn_sites', [
        'last_contact_at' => gmdate('Y-m-d H:i:s'),
        'last_sync_at'    => gmdate('Y-m-d H:i:s'),
        'ssl_expires_at'  => gmdate('Y-m-d H:i:s', strtotime('+12 days')),
    ], ['id' => $siteA]);

    $result = (new SitesRepository())->findSitesNeedingAttention(1);
    $this->assertCount(1, $result);
    $this->assertContains('ssl_expiring', $result[0]['reasons']);
}

public function testFindSitesNeedingAttentionFlagsSyncStalePast24Hours(): void
{
    global $wpdb;
    $siteA = $this->seedSite(1);
    $wpdb->update($wpdb->prefix . 'defyn_sites', [
        'last_contact_at' => gmdate('Y-m-d H:i:s'),
        'last_sync_at'    => gmdate('Y-m-d H:i:s', strtotime('-2 days')),
        'ssl_expires_at'  => gmdate('Y-m-d H:i:s', strtotime('+90 days')),
    ], ['id' => $siteA]);

    $result = (new SitesRepository())->findSitesNeedingAttention(1);
    $this->assertContains('sync_stale', $result[0]['reasons']);
}

public function testFindSitesNeedingAttentionCombinesMultipleReasons(): void
{
    global $wpdb;
    $siteA = $this->seedSite(1);
    $wpdb->update($wpdb->prefix . 'defyn_sites', [
        'last_contact_at' => gmdate('Y-m-d H:i:s', strtotime('-20 minutes')),
        'last_sync_at'    => gmdate('Y-m-d H:i:s', strtotime('-2 days')),
        'ssl_expires_at'  => gmdate('Y-m-d H:i:s', strtotime('+5 days')),
    ], ['id' => $siteA]);

    $result = (new SitesRepository())->findSitesNeedingAttention(1);
    $reasons = $result[0]['reasons'];
    $this->assertContains('offline', $reasons);
    $this->assertContains('ssl_expiring', $reasons);
    $this->assertContains('sync_stale', $reasons);
}

public function testFindSitesNeedingAttentionLimitsToFiftyRows(): void
{
    global $wpdb;
    // Seed 60 offline sites
    for ($i = 0; $i < 60; $i++) {
        $id = $this->seedSite(1);
        $wpdb->update($wpdb->prefix . 'defyn_sites', [
            'last_contact_at' => gmdate('Y-m-d H:i:s', strtotime('-20 minutes')),
        ], ['id' => $id]);
    }

    $result = (new SitesRepository())->findSitesNeedingAttention(1);
    $this->assertCount(50, $result);
}

public function testFindAllForUserFilterByHasPluginUpdates(): void
{
    $siteA = $this->seedSite(1);
    $siteB = $this->seedSite(1);
    $this->seedPlugin($siteA, 'akismet/akismet.php', true);   // has update
    $this->seedPlugin($siteB, 'yoast/yoast.php', false);      // no update

    $result = (new SitesRepository())->findAllForUser(1, 'has-plugin-updates');
    $ids = array_map(static fn($s) => $s->id, $result);

    $this->assertContains($siteA, $ids);
    $this->assertNotContains($siteB, $ids);
}

public function testFindAllForUserUnfilteredReturnsAllSites(): void
{
    $this->seedSite(1);
    $this->seedSite(1);

    $result = (new SitesRepository())->findAllForUser(1);
    $this->assertCount(2, $result);
}
```

### Step 2: Run tests to verify they fail

```
cd packages/dashboard-plugin && composer test -- --filter SitesRepositoryOverviewTest
```
Expected: FAIL — `Call to undefined method SitesRepository::findSitesNeedingAttention`.

### Step 3: Add the methods

In `SitesRepository.php`, append after the count methods from Task 1:

```php
/**
 * P2.5 — sites owned by $userId that have at least one attention reason.
 * Capped at 50 rows.
 *
 * @return list<array{site_id:int,url:string,label:string,reasons:list<string>,last_contact_at:?string,ssl_expires_at:?string}>
 */
public function findSitesNeedingAttention(int $userId): array
{
    $sitesTable = $this->table;
    $pluginsTable = $this->wpdb->prefix . 'defyn_site_plugins';
    $themesTable  = $this->wpdb->prefix . 'defyn_site_themes';

    $rows = $this->wpdb->get_results($this->wpdb->prepare(
        "SELECT
            s.id,
            s.url,
            s.label,
            s.last_contact_at,
            s.ssl_expires_at,
            CASE WHEN s.last_contact_at < (NOW() - INTERVAL 15 MINUTE) THEN 1 ELSE 0 END AS is_offline,
            CASE WHEN s.ssl_expires_at IS NOT NULL AND s.ssl_expires_at < (NOW() + INTERVAL 30 DAY) THEN 1 ELSE 0 END AS is_ssl_expiring,
            CASE WHEN s.last_sync_at < (NOW() - INTERVAL 24 HOUR) THEN 1 ELSE 0 END AS is_sync_stale,
            CASE WHEN s.core_update_state = 'failed'
                 OR EXISTS (SELECT 1 FROM {$pluginsTable} sp WHERE sp.site_id = s.id AND sp.update_state = 'failed')
                 OR EXISTS (SELECT 1 FROM {$themesTable} st WHERE st.site_id = s.id AND st.update_state = 'failed')
                 THEN 1 ELSE 0 END AS has_failed_update
         FROM {$sitesTable} s
         WHERE s.user_id = %d
         HAVING is_offline = 1 OR is_ssl_expiring = 1 OR is_sync_stale = 1 OR has_failed_update = 1
         ORDER BY s.last_contact_at ASC
         LIMIT 50",
        $userId
    ), ARRAY_A);

    $out = [];
    foreach ($rows as $row) {
        $reasons = [];
        if ((int) $row['is_offline'] === 1) {
            $reasons[] = 'offline';
        }
        if ((int) $row['has_failed_update'] === 1) {
            $reasons[] = 'failed_update';
        }
        if ((int) $row['is_ssl_expiring'] === 1) {
            $reasons[] = 'ssl_expiring';
        }
        if ((int) $row['is_sync_stale'] === 1) {
            $reasons[] = 'sync_stale';
        }
        $out[] = [
            'site_id'         => (int) $row['id'],
            'url'             => (string) $row['url'],
            'label'           => (string) $row['label'],
            'reasons'         => $reasons,
            'last_contact_at' => $row['last_contact_at'] ?? null,
            'ssl_expires_at'  => $row['ssl_expires_at'] ?? null,
        ];
    }
    return $out;
}
```

Modify the existing `findAllForUser` method (currently signature `findAllForUser(int $userId): array`) to accept an optional filter:

```php
/**
 * P2.5: accepts optional $filter for ?filter=has-plugin-updates|has-theme-updates|has-core-update.
 * When null, returns all sites for the user (existing behavior).
 */
public function findAllForUser(int $userId, ?string $filter = null): array
{
    if ($filter === 'has-plugin-updates') {
        $pluginsTable = $this->wpdb->prefix . 'defyn_site_plugins';
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT s.* FROM {$this->table} s
             WHERE s.user_id = %d
               AND EXISTS (SELECT 1 FROM {$pluginsTable} sp WHERE sp.site_id = s.id AND sp.update_available = 1)
             ORDER BY s.created_at DESC",
            $userId
        ), ARRAY_A);
    } elseif ($filter === 'has-theme-updates') {
        $themesTable = $this->wpdb->prefix . 'defyn_site_themes';
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT s.* FROM {$this->table} s
             WHERE s.user_id = %d
               AND EXISTS (SELECT 1 FROM {$themesTable} st WHERE st.site_id = s.id AND st.update_available = 1)
             ORDER BY s.created_at DESC",
            $userId
        ), ARRAY_A);
    } elseif ($filter === 'has-core-update') {
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE user_id = %d AND core_update_available = 1
             ORDER BY created_at DESC",
            $userId
        ), ARRAY_A);
    } else {
        // Existing unfiltered query — preserve EXACTLY what's there today.
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE user_id = %d ORDER BY created_at DESC",
            $userId
        ), ARRAY_A);
    }

    return array_map(static fn($row) => \Defyn\Dashboard\Models\Site::fromRow($row), $rows ?? []);
}
```

(The existing unfiltered query may already SELECT specific columns or have different ORDER — preserve whatever it actually does today by reading the file first.)

### Step 4: Run tests to verify they pass

```
cd packages/dashboard-plugin && composer test -- --filter SitesRepositoryOverviewTest
```
Expected: PASS — 13 tests in the file now green.

```
cd packages/dashboard-plugin && composer test -- --filter SitesRepositoryTest
```
Expected: PASS — existing `findAllForUser` tests still pass (no filter = same behavior).

### Step 5: Commit

```bash
git -C "/Users/pradeep/Local Sites/defynWP" add packages/dashboard-plugin/src/Services/SitesRepository.php \
        packages/dashboard-plugin/tests/Integration/Services/SitesRepositoryOverviewTest.php
git -C "/Users/pradeep/Local Sites/defynWP" commit -m "feat(p2-5): findSitesNeedingAttention + optional filter on findAllForUser

findSitesNeedingAttention applies 4 hardcoded thresholds (15m offline,
30d SSL, 24h sync-stale, failed_update via EXISTS) and returns up to 50
rows with reasons[] array per spec § 3.4. findAllForUser now accepts
?filter=has-plugin-updates|has-theme-updates|has-core-update for the
overview count-card drill-in. Per spec § 3.5."
```

---

## Task 3 — `ActivityLogRepository::tailForUser`

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/ActivityLogRepository.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/ActivityLogRepositoryTailTest.php` (CREATE)

### Step 1: Write the failing test

Create `packages/dashboard-plugin/tests/Integration/Services/ActivityLogRepositoryTailTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\ActivityLogRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class ActivityLogRepositoryTailTest extends AbstractSchemaTestCase
{
    public function testTailForUserReturnsTwentyFiveOrderedByCreatedAtDesc(): void
    {
        global $wpdb;
        $siteA = $this->seedSite(1);
        $siteB = $this->seedSite(2); // different user — must NOT appear in user 1's tail

        $logger = new ActivityLogger();
        for ($i = 0; $i < 30; $i++) {
            $logger->log(1, $siteA, 'site.health_ok', ['seq' => $i]);
        }
        for ($i = 0; $i < 5; $i++) {
            $logger->log(2, $siteB, 'site.health_ok', ['seq' => $i]);
        }

        $tail = (new ActivityLogRepository())->tailForUser(1, 25);

        $this->assertCount(25, $tail);
        $first = json_decode($tail[0]['details'] ?? '{}', true);
        $last  = json_decode($tail[24]['details'] ?? '{}', true);
        $this->assertSame(29, $first['seq']);
        $this->assertSame(5, $last['seq']);
    }

    public function testTailForUserExcludesOtherUsersEvents(): void
    {
        $siteA = $this->seedSite(1);
        $siteB = $this->seedSite(2);

        (new ActivityLogger())->log(1, $siteA, 'plugin_update.succeeded', ['marker' => 'user1']);
        (new ActivityLogger())->log(2, $siteB, 'plugin_update.succeeded', ['marker' => 'user2']);

        $tail = (new ActivityLogRepository())->tailForUser(1, 25);
        foreach ($tail as $row) {
            $details = json_decode($row['details'] ?? '{}', true);
            $this->assertSame('user1', $details['marker'] ?? null);
        }
    }

    public function testTailForUserIncludesSiteLabelJoin(): void
    {
        $siteA = $this->seedSite(1);
        (new ActivityLogger())->log(1, $siteA, 'plugin_update.succeeded', null);

        $tail = (new ActivityLogRepository())->tailForUser(1, 25);
        $this->assertNotEmpty($tail);
        $this->assertArrayHasKey('site_label', $tail[0]);
        $this->assertSame('Example', $tail[0]['site_label']);
    }

    private function seedSite(int $userId): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'    => $userId,
            'url'        => 'https://ex' . microtime(true) . '.com',
            'label'      => 'Example',
            'status'     => 'active',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $wpdb->insert_id;
    }
}
```

Test method names MUST be EXACTLY:
- `testTailForUserReturnsTwentyFiveOrderedByCreatedAtDesc`
- `testTailForUserExcludesOtherUsersEvents`
- `testTailForUserIncludesSiteLabelJoin`

### Step 2: Run test to verify it fails

```
cd packages/dashboard-plugin && composer test -- --filter ActivityLogRepositoryTailTest
```
Expected: FAIL — `Call to undefined method ActivityLogRepository::tailForUser`.

### Step 3: Add the method

In `packages/dashboard-plugin/src/Services/ActivityLogRepository.php`, append after the existing `countForUser` method:

```php
/**
 * P2.5 — last $limit events for sites owned by $userId, joined with sites
 * to surface site_label. Uses EXISTS subquery (NOT plain LEFT JOIN) so
 * MySQL leverages the idx_activity_site_created_at composite index from F9.
 *
 * @return list<array{
 *   id:int, site_id:?int, site_label:?string, event_type:string,
 *   details:?string, created_at:string
 * }>
 */
public function tailForUser(int $userId, int $limit = 25): array
{
    $activityTable = $this->wpdb->prefix . 'defyn_activity_log';
    $sitesTable    = $this->wpdb->prefix . 'defyn_sites';

    $rows = $this->wpdb->get_results($this->wpdb->prepare(
        "SELECT a.id, a.site_id, s.label AS site_label, a.event_type, a.details, a.created_at
         FROM {$activityTable} a
         LEFT JOIN {$sitesTable} s ON s.id = a.site_id AND s.user_id = %d
         WHERE EXISTS (
            SELECT 1 FROM {$sitesTable} s2
            WHERE s2.id = a.site_id AND s2.user_id = %d
         )
         ORDER BY a.created_at DESC, a.id DESC
         LIMIT %d",
        $userId, $userId, $limit
    ), ARRAY_A);

    return $rows ?: [];
}
```

(The LEFT JOIN here is purely to surface `s.label` AS `site_label`; the WHERE EXISTS guarantees user-ownership filtering and lets MySQL pick the activity index. Verify EXPLAIN locally if curious — the test asserts correctness, not query plan.)

### Step 4: Run tests to verify they pass

```
cd packages/dashboard-plugin && composer test -- --filter ActivityLogRepositoryTailTest
```
Expected: PASS — 3 tests green.

Also run existing baseline:
```
cd packages/dashboard-plugin && composer test -- --filter ActivityLogRepositoryTest
```
Expected: PASS.

### Step 5: Commit

```bash
git -C "/Users/pradeep/Local Sites/defynWP" add packages/dashboard-plugin/src/Services/ActivityLogRepository.php \
        packages/dashboard-plugin/tests/Integration/Services/ActivityLogRepositoryTailTest.php
git -C "/Users/pradeep/Local Sites/defynWP" commit -m "feat(p2-5): ActivityLogRepository::tailForUser with EXISTS subquery

tailForUser(int userId, int limit=25) returns most-recent events for
sites owned by the user. Uses EXISTS subquery (per spec § 2 + plan-bug
trap #2) so MySQL leverages idx_activity_site_created_at instead of
full-scanning the activity log. Joins for site_label."
```

---

## Task 4 — `OverviewService`

**Files:**
- Create: `packages/dashboard-plugin/src/Services/OverviewService.php`
- Test: `packages/dashboard-plugin/tests/Unit/Services/OverviewServiceTest.php` (CREATE)

### Step 1: Write the failing test

Create `packages/dashboard-plugin/tests/Unit/Services/OverviewServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Unit\Services;

use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\OverviewService;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class OverviewServiceTest extends AbstractSchemaTestCase
{
    public function testComposeReturnsFullEnvelopeShape(): void
    {
        $this->seedSite(1);
        $result = (new OverviewService())->compose(1);

        $this->assertArrayHasKey('pending_updates', $result);
        $this->assertArrayHasKey('sites_needing_attention', $result);
        $this->assertArrayHasKey('recent_activity', $result);
        $this->assertArrayHasKey('generated_at', $result);

        $this->assertArrayHasKey('plugins', $result['pending_updates']);
        $this->assertArrayHasKey('themes', $result['pending_updates']);
        $this->assertArrayHasKey('cores_minor', $result['pending_updates']);
        $this->assertArrayHasKey('cores_major', $result['pending_updates']);
        $this->assertArrayHasKey('sites_with_any_update', $result['pending_updates']);
    }

    public function testComposeIncludesOfflineSiteInAttention(): void
    {
        global $wpdb;
        $siteA = $this->seedSite(1);
        $wpdb->update($wpdb->prefix . 'defyn_sites', [
            'last_contact_at' => gmdate('Y-m-d H:i:s', strtotime('-20 minutes')),
        ], ['id' => $siteA]);

        $result = (new OverviewService())->compose(1);
        $this->assertNotEmpty($result['sites_needing_attention']);
        $this->assertContains('offline', $result['sites_needing_attention'][0]['reasons']);
    }

    public function testComposeIncludesFailedUpdateInAttention(): void
    {
        global $wpdb;
        $siteA = $this->seedSite(1);
        $wpdb->update($wpdb->prefix . 'defyn_sites', [
            'core_update_state' => 'failed',
            'last_contact_at'   => gmdate('Y-m-d H:i:s'),
            'last_sync_at'      => gmdate('Y-m-d H:i:s'),
            'ssl_expires_at'    => gmdate('Y-m-d H:i:s', strtotime('+90 days')),
        ], ['id' => $siteA]);

        $result = (new OverviewService())->compose(1);
        $this->assertContains('failed_update', $result['sites_needing_attention'][0]['reasons']);
    }

    public function testComposeIncludesRecentActivity(): void
    {
        $siteA = $this->seedSite(1);
        (new ActivityLogger())->log(1, $siteA, 'plugin_update.succeeded', ['slug' => 'akismet']);

        $result = (new OverviewService())->compose(1);
        $this->assertNotEmpty($result['recent_activity']);
        $this->assertSame('plugin_update.succeeded', $result['recent_activity'][0]['event_type']);
    }

    public function testGeneratedAtIsServerTimestampString(): void
    {
        $this->seedSite(1);
        $result = (new OverviewService())->compose(1);

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $result['generated_at']
        );
    }

    private function seedSite(int $userId): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'         => $userId,
            'url'             => 'https://ex' . microtime(true) . '.com',
            'label'           => 'Example',
            'status'          => 'active',
            'created_at'      => gmdate('Y-m-d H:i:s'),
            'last_contact_at' => gmdate('Y-m-d H:i:s'),
            'last_sync_at'    => gmdate('Y-m-d H:i:s'),
            'ssl_expires_at'  => gmdate('Y-m-d H:i:s', strtotime('+90 days')),
        ]);
        return (int) $wpdb->insert_id;
    }
}
```

Test method names MUST be EXACTLY:
- `testComposeReturnsFullEnvelopeShape`
- `testComposeIncludesOfflineSiteInAttention`
- `testComposeIncludesFailedUpdateInAttention`
- `testComposeIncludesRecentActivity`
- `testGeneratedAtIsServerTimestampString`

### Step 2: Run test to verify it fails

```
cd packages/dashboard-plugin && composer test -- --filter OverviewServiceTest
```
Expected: FAIL — `Class OverviewService not found`.

### Step 3: Create the service

Create `packages/dashboard-plugin/src/Services/OverviewService.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

/**
 * P2.5 — composes the GET /defyn/v1/overview response.
 *
 * Read-only aggregation. Delegates all DB work to SitesRepository +
 * ActivityLogRepository. Lives in Services/ alongside the existing
 * SyncService, HealthService etc.
 *
 * Spec: docs/superpowers/specs/2026-06-07-p2-5-overview-dashboard-design.md § 3
 */
final class OverviewService
{
    public function __construct(
        private readonly SitesRepository $sites = new SitesRepository(),
        private readonly ActivityLogRepository $activity = new ActivityLogRepository(),
    ) {
    }

    /**
     * @return array{
     *   pending_updates: array{
     *     plugins:int, themes:int, cores_minor:int, cores_major:int, sites_with_any_update:int
     *   },
     *   sites_needing_attention: list<array{
     *     site_id:int, url:string, label:string, reasons:list<string>,
     *     last_contact_at:?string, ssl_expires_at:?string
     *   }>,
     *   recent_activity: list<array{
     *     id:int, site_id:?int, site_label:?string, event_type:string,
     *     details:array<string,mixed>|null, created_at:string
     *   }>,
     *   generated_at: string
     * }
     */
    public function compose(int $userId): array
    {
        $activity = array_map(static function (array $row): array {
            $details = isset($row['details'])
                ? json_decode((string) $row['details'], true)
                : null;
            return [
                'id'         => (int) $row['id'],
                'site_id'    => isset($row['site_id']) ? (int) $row['site_id'] : null,
                'site_label' => isset($row['site_label']) ? (string) $row['site_label'] : null,
                'event_type' => (string) $row['event_type'],
                'details'    => is_array($details) ? $details : null,
                'created_at' => (string) $row['created_at'],
            ];
        }, $this->activity->tailForUser($userId, 25));

        return [
            'pending_updates' => [
                'plugins'               => $this->sites->countPendingPlugins($userId),
                'themes'                => $this->sites->countPendingThemes($userId),
                'cores_minor'           => $this->sites->countPendingCoresMinor($userId),
                'cores_major'           => $this->sites->countPendingCoresMajor($userId),
                'sites_with_any_update' => $this->sites->countSitesWithAnyUpdate($userId),
            ],
            'sites_needing_attention' => $this->sites->findSitesNeedingAttention($userId),
            'recent_activity'         => $activity,
            'generated_at'            => gmdate('Y-m-d H:i:s'),
        ];
    }
}
```

### Step 4: Run tests to verify they pass

```
cd packages/dashboard-plugin && composer test -- --filter OverviewServiceTest
```
Expected: PASS — 5 tests green.

### Step 5: Commit

```bash
git -C "/Users/pradeep/Local Sites/defynWP" add packages/dashboard-plugin/src/Services/OverviewService.php \
        packages/dashboard-plugin/tests/Unit/Services/OverviewServiceTest.php
git -C "/Users/pradeep/Local Sites/defynWP" commit -m "feat(p2-5): OverviewService composes the /overview response

Delegates to SitesRepository + ActivityLogRepository for all DB work.
Returns the full 4-key envelope per spec § 3.2 — pending_updates,
sites_needing_attention, recent_activity, generated_at."
```

---

## Task 5 — `OverviewController` + `RateLimit::overview` (30/MINUTE) + route registration

**Files:**
- Create: `packages/dashboard-plugin/src/Rest/OverviewController.php`
- Modify: `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php`
- Modify: `packages/dashboard-plugin/src/Rest/RestRouter.php`
- Test: `packages/dashboard-plugin/tests/Integration/Rest/OverviewControllerTest.php` (CREATE)

### Step 1: Write the failing test

Create `packages/dashboard-plugin/tests/Integration/Rest/OverviewControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

final class OverviewControllerTest extends AbstractSchemaTestCase
{
    public function testAuthRequiredReturns401WhenNoBearerToken(): void
    {
        $request = new WP_REST_Request('GET', '/defyn/v1/overview');
        $response = rest_do_request($request);

        $this->assertSame(401, $response->get_status());
    }

    public function testHappyPath200WithFullEnvelopeShape(): void
    {
        $this->seedSite(1);
        $token = $this->token(1);

        $request = new WP_REST_Request('GET', '/defyn/v1/overview');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $response = rest_do_request($request);

        $this->assertSame(200, $response->get_status());
        $body = $response->get_data();
        $this->assertArrayHasKey('pending_updates', $body);
        $this->assertArrayHasKey('sites_needing_attention', $body);
        $this->assertArrayHasKey('recent_activity', $body);
        $this->assertArrayHasKey('generated_at', $body);
    }

    public function testRateLimit429AfterThirtyFirstCall(): void
    {
        $this->seedSite(1);
        $token = $this->token(1);

        for ($i = 0; $i < 30; $i++) {
            $request = new WP_REST_Request('GET', '/defyn/v1/overview');
            $request->set_header('Authorization', 'Bearer ' . $token);
            $resp = rest_do_request($request);
            $this->assertSame(200, $resp->get_status(), "call #" . ($i + 1) . " should be 200");
        }

        $request = new WP_REST_Request('GET', '/defyn/v1/overview');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $resp = rest_do_request($request);
        $this->assertSame(429, $resp->get_status());
        $this->assertSame('overview.rate_limited', $resp->get_data()['error']['code']);
    }

    public function testOwnershipScopingExcludesOtherUsersSites(): void
    {
        $this->seedSite(2); // user 2 has a site
        $token = $this->token(1); // user 1 has zero sites

        $request = new WP_REST_Request('GET', '/defyn/v1/overview');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $response = rest_do_request($request);

        $this->assertSame(200, $response->get_status());
        $body = $response->get_data();
        $this->assertSame(0, $body['pending_updates']['plugins']);
        $this->assertSame([], $body['sites_needing_attention']);
    }

    public function testNoStoreCacheHeader(): void
    {
        $token = $this->token(1);
        $request = new WP_REST_Request('GET', '/defyn/v1/overview');
        $request->set_header('Authorization', 'Bearer ' . $token);

        $response = rest_do_request($request);
        $response = apply_filters('rest_post_dispatch', $response, rest_get_server(), $request);

        $this->assertStringContainsString(
            'no-store',
            $response->get_headers()['Cache-Control'] ?? ''
        );
    }

    private function seedSite(int $userId): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'    => $userId,
            'url'        => 'https://ex' . microtime(true) . '.com',
            'label'      => 'Example',
            'status'     => 'active',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $wpdb->insert_id;
    }

    private function token(int $userId): string
    {
        return (new TokenService())->issueAccess($userId);
    }
}
```

Test method names MUST be EXACTLY:
- `testAuthRequiredReturns401WhenNoBearerToken`
- `testHappyPath200WithFullEnvelopeShape`
- `testRateLimit429AfterThirtyFirstCall` ← critical: NOT "Fourth", NOT "Eleventh"
- `testOwnershipScopingExcludesOtherUsersSites`
- `testNoStoreCacheHeader`

### Step 2: Run test to verify it fails

```
cd packages/dashboard-plugin && composer test -- --filter OverviewControllerTest
```
Expected: FAIL — `rest_no_route` because the endpoint isn't registered.

### Step 3: Create controller + RateLimit method + route registration

Create `packages/dashboard-plugin/src/Rest/OverviewController.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Services\OverviewService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P2.5 — GET /defyn/v1/overview.
 *
 * Read-only aggregate view across all sites owned by the authenticated
 * user. Per spec § 3.2 — emits {pending_updates, sites_needing_attention,
 * recent_activity, generated_at}. Rate limited at 30/minute.
 *
 * Spec: docs/superpowers/specs/2026-06-07-p2-5-overview-dashboard-design.md § 3
 */
final class OverviewController
{
    public function __construct(
        private readonly OverviewService $service = new OverviewService(),
    ) {
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        return new WP_REST_Response($this->service->compose($userId), 200);
    }
}
```

In `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php`, after the most recent constants block (`CORE_ALLOW_MAJOR_*` around lines 72-73), add:

```php
// P2.5 — overview dashboard polling endpoint. FIRST per-MINUTE bucket
// in the project (all prior buckets are per-hour). The SPA polls every
// 60s while the tab is active = 60/hr from one tab; we cap at 30/min
// to allow multiple tabs / rapid manual refresh without DoSing the DB.
public const OVERVIEW_LIMIT  = 30;
public const OVERVIEW_WINDOW = MINUTE_IN_SECONDS;
```

After the most recent method (`coreAllowMajor` around line 320), append:

```php
/**
 * Permission callback for GET /overview.
 *
 * Per-MINUTE bucket (NOT per-hour like every other RateLimit method).
 * Plan-bug trap #1 — copy-paste from coreUpdate's HOUR_IN_SECONDS is wrong.
 *
 * @return true|WP_Error
 */
public static function overview(WP_REST_Request $request)
{
    $authResult = RequireAuth::check($request);
    if (is_wp_error($authResult)) {
        return $authResult;
    }

    $userId = (int) $request->get_param('_authenticated_user_id');

    $key   = sprintf('defyn_rl_overview_%d', $userId);
    $count = (int) (get_transient($key) ?: 0);

    if ($count >= self::OVERVIEW_LIMIT) {
        return new \WP_Error(
            'overview.rate_limited',
            'Too many requests. The overview polls every minute — try again shortly.',
            ['status' => 429]
        );
    }

    set_transient($key, $count + 1, self::OVERVIEW_WINDOW);
    return true;
}
```

In `packages/dashboard-plugin/src/Rest/RestRouter.php`, register the route alongside the existing `/sites/{id}/core/allow-major` route:

```php
register_rest_route('defyn/v1', '/overview', [
    'methods'             => 'GET',
    'callback'            => [(new \Defyn\Dashboard\Rest\OverviewController()), 'handle'],
    'permission_callback' => ['\Defyn\Dashboard\Rest\Middleware\RateLimit', 'overview'],
]);
```

(Adjust to match the existing RestRouter idiom — it may use a metadata-array pattern. Read the file to confirm.)

### Step 4: Run tests to verify they pass

```
cd packages/dashboard-plugin && composer test -- --filter OverviewControllerTest
```
Expected: PASS — all 5 tests green.

Also run rate-limit baseline to confirm no regression on existing buckets:
```
cd packages/dashboard-plugin && composer test -- --filter RateLimitTest
```
Expected: PASS.

### Step 5: Commit

```bash
git -C "/Users/pradeep/Local Sites/defynWP" add packages/dashboard-plugin/src/Rest/OverviewController.php \
        packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php \
        packages/dashboard-plugin/src/Rest/RestRouter.php \
        packages/dashboard-plugin/tests/Integration/Rest/OverviewControllerTest.php
git -C "/Users/pradeep/Local Sites/defynWP" commit -m "feat(p2-5): GET /defyn/v1/overview endpoint + 30/min RateLimit

First per-MINUTE rate-limit bucket in the project (OVERVIEW_LIMIT=30,
OVERVIEW_WINDOW=MINUTE_IN_SECONDS). Controller delegates to
OverviewService and emits the full envelope. Per spec § 3 + plan-bug
trap #1."
```

---

## Task 6 — Dashboard v0.7.0 release bump + CORS regression

**Files:**
- Modify: `packages/dashboard-plugin/defyn-dashboard.php`
- Modify: `packages/dashboard-plugin/readme.txt`
- Modify: `packages/dashboard-plugin/composer.json`
- Test: `packages/dashboard-plugin/tests/Integration/Rest/OverviewCorsTest.php` (CREATE)

### Step 1: Write the CORS regression test

Create `packages/dashboard-plugin/tests/Integration/Rest/OverviewCorsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

final class OverviewCorsTest extends AbstractSchemaTestCase
{
    public function testOptionsPreflightOnOverviewRouteReturnsCorsHeaders(): void
    {
        $request = new WP_REST_Request('OPTIONS', '/defyn/v1/overview');
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

Test method name MUST be EXACTLY: `testOptionsPreflightOnOverviewRouteReturnsCorsHeaders`.

### Step 2: Run test to verify it passes (or fails)

```
cd packages/dashboard-plugin && composer test -- --filter OverviewCorsTest
```
Expected: PASS — the dashboard's CORS filter applies to all `defyn/v1` namespaced routes (proven by P2.4.1's CORS test). Route registration in Task 5 gave it CORS automatically.

If the test FAILS, the CORS filter requires explicit allowlisting. Inspect `src/Rest/Cors.php` (or similar) and add the new route.

### Step 3: Bump version constants

In `packages/dashboard-plugin/defyn-dashboard.php`, change `Version: 0.6.0` → `Version: 0.7.0`. Also update any `DEFYN_DASHBOARD_VERSION` constant.

In `packages/dashboard-plugin/composer.json`, change `"version": "0.6.0"` → `"version": "0.7.0"`.

In `packages/dashboard-plugin/readme.txt`, update `Stable tag: 0.6.0` → `Stable tag: 0.7.0` and prepend:

```
= 0.7.0 =
* Operator overview dashboard via GET /defyn/v1/overview — pending updates summary, sites needing attention, recent activity feed.
* SitesList gains ?filter=has-plugin-updates|has-theme-updates|has-core-update query-string filter for drill-in from overview count cards.
```

### Step 4: Run all dashboard tests

```
cd packages/dashboard-plugin && composer test
```
Expected: ALL PASS (Tasks 1-6 tests + every prior P2.x suite).

### Step 5: Commit

```bash
git -C "/Users/pradeep/Local Sites/defynWP" add packages/dashboard-plugin/defyn-dashboard.php \
        packages/dashboard-plugin/readme.txt \
        packages/dashboard-plugin/composer.json \
        packages/dashboard-plugin/tests/Integration/Rest/OverviewCorsTest.php
git -C "/Users/pradeep/Local Sites/defynWP" commit -m "chore(p2-5): dashboard v0.7.0 release bump + CORS regression

Bumps plugin version to v0.7.0 and adds a CORS preflight regression
test for the new /overview route."
```

---

## Task 7 — SPA Zod schema + MSW handler + `useOverview` hook

**Files:**
- Modify: `apps/web/src/types/api.ts`
- Modify: `apps/web/src/test/handlers.ts`
- Create: `apps/web/src/lib/queries/useOverview.ts`
- Test: `apps/web/tests/lib/queries/useOverview.test.tsx` (CREATE)

### Step 1: Write the failing tests

Create `apps/web/tests/lib/queries/useOverview.test.tsx`:

```tsx
import { describe, it, expect } from 'vitest'
import { renderHook, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { http, HttpResponse } from 'msw'
import { server } from '@/test/server'
import { useOverview } from '@/lib/queries/useOverview'
import React from 'react'

function wrapper({ children }: { children: React.ReactNode }) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>
}

describe('useOverview', () => {
  it('validates response against zod schema and returns parsed data', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/overview', () =>
        HttpResponse.json({
          pending_updates: {
            plugins: 47,
            themes: 3,
            cores_minor: 1,
            cores_major: 0,
            sites_with_any_update: 9,
          },
          sites_needing_attention: [
            {
              site_id: 1,
              url: 'https://smartcoding.com.au',
              label: 'SmartCoding',
              reasons: ['offline'],
              last_contact_at: '2026-06-07 09:30:00',
              ssl_expires_at: null,
            },
          ],
          recent_activity: [],
          generated_at: '2026-06-07 11:30:00',
        })
      )
    )

    const { result } = renderHook(() => useOverview(), { wrapper })
    await waitFor(() => expect(result.current.isSuccess).toBe(true))
    expect(result.current.data?.pending_updates.plugins).toBe(47)
    expect(result.current.data?.sites_needing_attention[0].reasons).toContain('offline')
  })

  it('rejects malformed response via Zod', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/overview', () =>
        HttpResponse.json({ pending_updates: 'not-an-object' })
      )
    )

    const { result } = renderHook(() => useOverview(), { wrapper })
    await waitFor(() => expect(result.current.isError).toBe(true))
  })
})
```

### Step 2: Run tests to verify they fail

```
cd apps/web && pnpm test -- --run useOverview
```
Expected: FAIL — `useOverview` doesn't exist.

### Step 3: Extend Zod + MSW + create the hook

In `apps/web/src/types/api.ts`, add (search for the end of the existing schema definitions and append):

```ts
export const overviewAttentionReasonSchema = z.enum([
  'offline',
  'failed_update',
  'ssl_expiring',
  'sync_stale',
])
export type OverviewAttentionReason = z.infer<typeof overviewAttentionReasonSchema>

export const overviewSchema = z.object({
  pending_updates: z.object({
    plugins: z.number().int().nonnegative(),
    themes: z.number().int().nonnegative(),
    cores_minor: z.number().int().nonnegative(),
    cores_major: z.number().int().nonnegative(),
    sites_with_any_update: z.number().int().nonnegative(),
  }),
  sites_needing_attention: z.array(z.object({
    site_id: z.number().int(),
    url: z.string(),
    label: z.string(),
    reasons: z.array(overviewAttentionReasonSchema),
    last_contact_at: z.string().nullable(),
    ssl_expires_at: z.string().nullable(),
  })),
  recent_activity: z.array(z.object({
    id: z.number().int(),
    site_id: z.number().int().nullable(),
    site_label: z.string().nullable(),
    event_type: z.string(),
    details: z.record(z.string(), z.unknown()).nullable(),
    created_at: z.string(),
  })),
  generated_at: z.string(),
})
export type Overview = z.infer<typeof overviewSchema>
```

In `apps/web/src/test/handlers.ts`, add (near the bottom, before the closing array):

```ts
http.get('*/wp-json/defyn/v1/overview', () => {
  return HttpResponse.json({
    pending_updates: {
      plugins: 0,
      themes: 0,
      cores_minor: 0,
      cores_major: 0,
      sites_with_any_update: 0,
    },
    sites_needing_attention: [],
    recent_activity: [],
    generated_at: '2026-06-07 11:30:00',
  })
}),
```

Create `apps/web/src/lib/queries/useOverview.ts`:

```ts
import { useQuery } from '@tanstack/react-query'
import { apiClient } from '@/lib/apiClient'
import { overviewSchema } from '@/types/api'

export function useOverview() {
  return useQuery({
    queryKey: ['overview'],
    queryFn: async () => {
      const data = await apiClient.get<unknown>('/overview')
      return overviewSchema.parse(data)
    },
    refetchInterval: 60_000,
    refetchIntervalInBackground: false,
  })
}
```

### Step 4: Run tests to verify they pass

```
cd apps/web && pnpm test -- --run useOverview
```
Expected: PASS — both tests green.

Also run the full SPA suite to verify the new MSW handler hasn't broken any existing test:
```
cd apps/web && pnpm test -- --run
```
Expected: PASS (or the same pre-existing baseline failures we've been tolerating in `SiteDetail.test.tsx`).

### Step 5: Commit

```bash
git -C "/Users/pradeep/Local Sites/defynWP" add apps/web/src/types/api.ts \
        apps/web/src/test/handlers.ts \
        apps/web/src/lib/queries/useOverview.ts \
        apps/web/tests/lib/queries/useOverview.test.tsx
git -C "/Users/pradeep/Local Sites/defynWP" commit -m "feat(p2-5): Zod overviewSchema + MSW handler + useOverview hook

overviewSchema mirrors the dashboard REST contract from spec § 3.2.
useOverview hook polls /overview every 60s (refetchIntervalInBackground
false to pause when tab is inactive). MSW returns an empty payload by
default; tests use server.use() to override per case."
```

---

## Task 8 — `AttentionReasonChip` + `RecentActivityWidget`

**Files:**
- Create: `apps/web/src/components/overview/AttentionReasonChip.tsx`
- Create: `apps/web/src/components/overview/RecentActivityWidget.tsx`
- Test: `apps/web/tests/components/overview/AttentionReasonChip.test.tsx` (CREATE)
- Test: `apps/web/tests/components/overview/RecentActivityWidget.test.tsx` (CREATE)

### Step 1: Write the failing tests

Create `apps/web/tests/components/overview/AttentionReasonChip.test.tsx`:

```tsx
import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import { AttentionReasonChip } from '@/components/overview/AttentionReasonChip'

describe('AttentionReasonChip', () => {
  it('renders red palette for offline and failed_update reasons', () => {
    const { rerender } = render(<AttentionReasonChip reason="offline" />)
    expect(screen.getByText(/offline/i).className).toMatch(/bg-red-100/)
    expect(screen.getByText(/offline/i).className).toMatch(/text-red-800/)

    rerender(<AttentionReasonChip reason="failed_update" />)
    expect(screen.getByText(/failed update/i).className).toMatch(/bg-red-100/)
  })

  it('renders amber palette for ssl_expiring and sync_stale reasons', () => {
    const { rerender } = render(<AttentionReasonChip reason="ssl_expiring" />)
    expect(screen.getByText(/ssl expiring/i).className).toMatch(/bg-amber-100/)
    expect(screen.getByText(/ssl expiring/i).className).toMatch(/text-amber-800/)

    rerender(<AttentionReasonChip reason="sync_stale" />)
    expect(screen.getByText(/sync stale/i).className).toMatch(/bg-amber-100/)
  })
})
```

Create `apps/web/tests/components/overview/RecentActivityWidget.test.tsx`:

```tsx
import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { RecentActivityWidget } from '@/components/overview/RecentActivityWidget'

const baseEvent = {
  id: 1,
  site_id: 1,
  site_label: 'SmartCoding',
  event_type: 'plugin_update.succeeded',
  details: { slug: 'akismet' },
  created_at: '2026-06-07 11:30:00',
}

describe('RecentActivityWidget', () => {
  it('renders events in the order they are passed (reverse chronological)', () => {
    const events = [
      { ...baseEvent, id: 1, created_at: '2026-06-07 11:30:00' },
      { ...baseEvent, id: 2, created_at: '2026-06-07 10:30:00' },
      { ...baseEvent, id: 3, created_at: '2026-06-07 09:30:00' },
    ]
    render(<RecentActivityWidget events={events} />, { wrapper: MemoryRouter })

    const rows = screen.getAllByTestId('activity-row')
    expect(rows).toHaveLength(3)
    expect(rows[0]).toHaveTextContent('11:30:00')
  })

  it('renders at most 25 events even when passed more', () => {
    const events = Array.from({ length: 40 }, (_, i) => ({
      ...baseEvent,
      id: i,
      created_at: `2026-06-07 ${String(i % 24).padStart(2, '0')}:00:00`,
    }))
    render(<RecentActivityWidget events={events} />, { wrapper: MemoryRouter })

    expect(screen.getAllByTestId('activity-row')).toHaveLength(25)
  })
})
```

### Step 2: Run tests to verify they fail

```
cd apps/web && pnpm test -- --run AttentionReasonChip RecentActivityWidget
```
Expected: FAIL — components don't exist.

### Step 3: Create the components

Create `apps/web/src/components/overview/AttentionReasonChip.tsx`:

```tsx
import type { OverviewAttentionReason } from '@/types/api'

interface AttentionReasonChipProps {
  reason: OverviewAttentionReason
}

const PALETTE: Record<OverviewAttentionReason, { className: string; label: string }> = {
  offline:        { className: 'bg-red-100 text-red-800',     label: 'offline' },
  failed_update:  { className: 'bg-red-100 text-red-800',     label: 'failed update' },
  ssl_expiring:   { className: 'bg-amber-100 text-amber-800', label: 'ssl expiring' },
  sync_stale:     { className: 'bg-amber-100 text-amber-800', label: 'sync stale' },
}

export function AttentionReasonChip({ reason }: AttentionReasonChipProps) {
  const { className, label } = PALETTE[reason]
  return (
    <span className={`inline-block rounded-full px-2 py-0.5 text-xs font-medium ${className}`}>
      {label}
    </span>
  )
}
```

Create `apps/web/src/components/overview/RecentActivityWidget.tsx`:

```tsx
import { Link } from 'react-router-dom'
import type { Overview } from '@/types/api'

interface RecentActivityWidgetProps {
  events: Overview['recent_activity']
}

const MAX_ROWS = 25

export function RecentActivityWidget({ events }: RecentActivityWidgetProps) {
  const rows = events.slice(0, MAX_ROWS)

  return (
    <div className="rounded-md border bg-white p-4">
      <h3 className="mb-3 text-sm font-semibold">Recent activity</h3>
      {rows.length === 0 ? (
        <p className="text-sm text-muted-foreground">No recent events</p>
      ) : (
        <ul className="divide-y divide-dashed">
          {rows.map((e) => (
            <li
              key={e.id}
              data-testid="activity-row"
              className="flex items-center gap-2 py-1.5 text-xs"
            >
              <span className="flex-1 font-mono text-muted-foreground">{e.event_type}</span>
              {e.site_id !== null ? (
                <Link to={`/sites/${e.site_id}`} className="text-foreground hover:underline">
                  {e.site_label ?? `site ${e.site_id}`}
                </Link>
              ) : (
                <span className="text-muted-foreground">—</span>
              )}
              <span className="text-muted-foreground">{e.created_at.slice(-8)}</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
```

### Step 4: Run tests to verify they pass

```
cd apps/web && pnpm test -- --run AttentionReasonChip RecentActivityWidget
```
Expected: PASS — 4 tests green.

### Step 5: Commit

```bash
git -C "/Users/pradeep/Local Sites/defynWP" add apps/web/src/components/overview/AttentionReasonChip.tsx \
        apps/web/src/components/overview/RecentActivityWidget.tsx \
        apps/web/tests/components/overview/AttentionReasonChip.test.tsx \
        apps/web/tests/components/overview/RecentActivityWidget.test.tsx
git -C "/Users/pradeep/Local Sites/defynWP" commit -m "feat(p2-5): AttentionReasonChip + RecentActivityWidget

Chip palette: red (bg-red-100 text-red-800) for offline/failed_update,
amber (bg-amber-100 text-amber-800) for ssl_expiring/sync_stale.
RecentActivityWidget caps at 25 rows, links events to /sites/{id}
when site_id is set. Per spec § 4.4 + plan-bug trap #8."
```

---

## Task 9 — `PendingUpdatesWidget` + `SitesNeedingAttentionWidget`

**Files:**
- Create: `apps/web/src/components/overview/PendingUpdatesWidget.tsx`
- Create: `apps/web/src/components/overview/SitesNeedingAttentionWidget.tsx`
- Test: `apps/web/tests/components/overview/PendingUpdatesWidget.test.tsx` (CREATE)
- Test: `apps/web/tests/components/overview/SitesNeedingAttentionWidget.test.tsx` (CREATE)

### Step 1: Write the failing tests

Create `apps/web/tests/components/overview/PendingUpdatesWidget.test.tsx`:

```tsx
import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter, Routes, Route } from 'react-router-dom'
import { PendingUpdatesWidget } from '@/components/overview/PendingUpdatesWidget'

function renderWithRouter() {
  render(
    <MemoryRouter initialEntries={['/overview']}>
      <Routes>
        <Route path="/overview" element={
          <PendingUpdatesWidget
            counts={{ plugins: 47, themes: 3, cores_minor: 1, cores_major: 0, sites_with_any_update: 9 }}
          />
        } />
        <Route path="/sites" element={<div data-testid="sites-page">sites</div>} />
      </Routes>
    </MemoryRouter>
  )
}

describe('PendingUpdatesWidget', () => {
  it('renders three count cards with correct numbers', () => {
    renderWithRouter()
    expect(screen.getByText('47')).toBeInTheDocument()
    expect(screen.getByText('3')).toBeInTheDocument()
  })

  it('plugin card links to /sites?filter=has-plugin-updates', () => {
    renderWithRouter()
    const pluginCard = screen.getByRole('link', { name: /plugin updates/i })
    expect(pluginCard).toHaveAttribute('href', '/sites?filter=has-plugin-updates')
  })
})
```

Create `apps/web/tests/components/overview/SitesNeedingAttentionWidget.test.tsx`:

```tsx
import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { SitesNeedingAttentionWidget } from '@/components/overview/SitesNeedingAttentionWidget'

describe('SitesNeedingAttentionWidget', () => {
  it('renders one row per site with chips for each reason', () => {
    render(
      <SitesNeedingAttentionWidget
        sites={[
          {
            site_id: 1,
            url: 'https://smartcoding.com.au',
            label: 'SmartCoding',
            reasons: ['offline', 'ssl_expiring'],
            last_contact_at: '2026-06-07 09:30:00',
            ssl_expires_at: '2026-06-25 00:00:00',
          },
        ]}
      />,
      { wrapper: MemoryRouter }
    )

    expect(screen.getByText(/SmartCoding/)).toBeInTheDocument()
    expect(screen.getByText(/offline/i)).toBeInTheDocument()
    expect(screen.getByText(/ssl expiring/i)).toBeInTheDocument()
  })

  it('renders an all-healthy message when the list is empty', () => {
    render(<SitesNeedingAttentionWidget sites={[]} />, { wrapper: MemoryRouter })
    expect(screen.getByText(/all sites healthy/i)).toBeInTheDocument()
  })

  it('row links navigate to /sites/{id}', () => {
    render(
      <SitesNeedingAttentionWidget
        sites={[
          {
            site_id: 42,
            url: 'https://acme.io',
            label: 'Acme',
            reasons: ['failed_update'],
            last_contact_at: null,
            ssl_expires_at: null,
          },
        ]}
      />,
      { wrapper: MemoryRouter }
    )

    const link = screen.getByRole('link', { name: /Acme/ })
    expect(link).toHaveAttribute('href', '/sites/42')
  })
})
```

### Step 2: Run tests to verify they fail

```
cd apps/web && pnpm test -- --run PendingUpdatesWidget SitesNeedingAttentionWidget
```
Expected: FAIL — components don't exist.

### Step 3: Create the components

Create `apps/web/src/components/overview/PendingUpdatesWidget.tsx`:

```tsx
import { Link } from 'react-router-dom'
import type { Overview } from '@/types/api'

interface PendingUpdatesWidgetProps {
  counts: Overview['pending_updates']
}

interface CountCardProps {
  to: string
  label: string
  num: number | string
  sub: string
}

function CountCard({ to, label, num, sub }: CountCardProps) {
  return (
    <Link
      to={to}
      className="block rounded-md border bg-white p-4 transition hover:border-foreground"
    >
      <p className="text-xs uppercase tracking-wide text-muted-foreground">{label}</p>
      <p className="mt-1 text-3xl font-bold text-foreground">{num}</p>
      <p className="mt-0.5 text-xs text-muted-foreground">{sub}</p>
    </Link>
  )
}

export function PendingUpdatesWidget({ counts }: PendingUpdatesWidgetProps) {
  return (
    <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
      <CountCard
        to="/sites?filter=has-plugin-updates"
        label="Plugin updates"
        num={counts.plugins}
        sub={`across ${counts.sites_with_any_update} site${counts.sites_with_any_update === 1 ? '' : 's'}`}
      />
      <CountCard
        to="/sites?filter=has-theme-updates"
        label="Theme updates"
        num={counts.themes}
        sub="across all sites"
      />
      <CountCard
        to="/sites?filter=has-core-update"
        label="WP core updates"
        num={`${counts.cores_minor} / ${counts.cores_major}`}
        sub="minor / major"
      />
    </div>
  )
}
```

Create `apps/web/src/components/overview/SitesNeedingAttentionWidget.tsx`:

```tsx
import { Link } from 'react-router-dom'
import type { Overview } from '@/types/api'
import { AttentionReasonChip } from '@/components/overview/AttentionReasonChip'

interface SitesNeedingAttentionWidgetProps {
  sites: Overview['sites_needing_attention']
}

export function SitesNeedingAttentionWidget({ sites }: SitesNeedingAttentionWidgetProps) {
  if (sites.length === 0) {
    return (
      <div className="rounded-md border bg-white p-4">
        <h3 className="mb-3 text-sm font-semibold">Sites needing attention</h3>
        <p className="text-sm text-muted-foreground">All sites healthy ✓</p>
      </div>
    )
  }

  return (
    <div className="rounded-md border bg-white p-4">
      <h3 className="mb-3 text-sm font-semibold">Sites needing attention ({sites.length})</h3>
      <ul className="divide-y divide-dashed">
        {sites.map((s) => (
          <li key={s.site_id} className="flex items-center gap-2 py-2 text-sm">
            <Link to={`/sites/${s.site_id}`} className="flex-1 font-medium hover:underline">
              {s.label}
            </Link>
            <div className="flex gap-1">
              {s.reasons.map((r) => (
                <AttentionReasonChip key={r} reason={r} />
              ))}
            </div>
          </li>
        ))}
      </ul>
    </div>
  )
}
```

### Step 4: Run tests to verify they pass

```
cd apps/web && pnpm test -- --run PendingUpdatesWidget SitesNeedingAttentionWidget
```
Expected: PASS — 5 tests green.

### Step 5: Commit

```bash
git -C "/Users/pradeep/Local Sites/defynWP" add apps/web/src/components/overview/PendingUpdatesWidget.tsx \
        apps/web/src/components/overview/SitesNeedingAttentionWidget.tsx \
        apps/web/tests/components/overview/PendingUpdatesWidget.test.tsx \
        apps/web/tests/components/overview/SitesNeedingAttentionWidget.test.tsx
git -C "/Users/pradeep/Local Sites/defynWP" commit -m "feat(p2-5): PendingUpdatesWidget + SitesNeedingAttentionWidget

PendingUpdatesWidget renders three count cards as Link components
pointing to /sites?filter=has-plugin-updates|has-theme-updates|has-core-update.
SitesNeedingAttentionWidget lists sites with reason chips, links rows
to /sites/{id}, shows 'All sites healthy ✓' when empty. Per spec § 4.4."
```

---

## Task 10 — `Overview` route + Home redirect + `SitesList` filter + `useSites` filter param

**Files:**
- Create: `apps/web/src/routes/Overview.tsx`
- Modify: `apps/web/src/routes/Home.tsx`
- Modify: `apps/web/src/App.tsx`
- Modify: `apps/web/src/lib/queries/useSites.ts`
- Modify: `apps/web/src/routes/SitesList.tsx`
- Test: `apps/web/tests/routes/Overview.test.tsx` (CREATE)

### Step 1: Write the failing test

Create `apps/web/tests/routes/Overview.test.tsx`:

```tsx
import { describe, it, expect } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Routes, Route } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { http, HttpResponse } from 'msw'
import { server } from '@/test/server'
import Overview from '@/routes/Overview'

function renderRoute() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/overview']}>
        <Routes>
          <Route path="/overview" element={<Overview />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>
  )
}

describe('Overview route', () => {
  it('renders all three widgets when MSW returns the canonical payload', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/overview', () =>
        HttpResponse.json({
          pending_updates: {
            plugins: 47, themes: 3, cores_minor: 1, cores_major: 0, sites_with_any_update: 9,
          },
          sites_needing_attention: [
            {
              site_id: 1, url: 'https://smart.co', label: 'Smart',
              reasons: ['offline'], last_contact_at: null, ssl_expires_at: null,
            },
          ],
          recent_activity: [
            {
              id: 1, site_id: 1, site_label: 'Smart',
              event_type: 'plugin_update.succeeded', details: { slug: 'a' },
              created_at: '2026-06-07 11:30:00',
            },
          ],
          generated_at: '2026-06-07 11:30:00',
        })
      )
    )

    renderRoute()
    await waitFor(() => expect(screen.getByText('47')).toBeInTheDocument())
    expect(screen.getByText(/Smart/)).toBeInTheDocument()
    expect(screen.getByText('plugin_update.succeeded')).toBeInTheDocument()
  })

  it('renders error state when MSW returns 500', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/overview', () =>
        HttpResponse.json({ error: { code: 'server.error', message: 'oops' } }, { status: 500 })
      )
    )

    renderRoute()
    await waitFor(() => expect(screen.getByText(/try again/i)).toBeInTheDocument())
  })
})
```

### Step 2: Run test to verify it fails

```
cd apps/web && pnpm test -- --run "routes/Overview"
```
Expected: FAIL — Overview route doesn't exist.

### Step 3: Create the route + wire up the rest

Create `apps/web/src/routes/Overview.tsx`:

```tsx
import { useOverview } from '@/lib/queries/useOverview'
import { PendingUpdatesWidget } from '@/components/overview/PendingUpdatesWidget'
import { SitesNeedingAttentionWidget } from '@/components/overview/SitesNeedingAttentionWidget'
import { RecentActivityWidget } from '@/components/overview/RecentActivityWidget'

export default function Overview() {
  const { data, isLoading, isError, refetch } = useOverview()

  if (isLoading) {
    return (
      <div className="space-y-4 p-4">
        <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
          <div className="h-24 animate-pulse rounded-md bg-gray-100" />
          <div className="h-24 animate-pulse rounded-md bg-gray-100" />
          <div className="h-24 animate-pulse rounded-md bg-gray-100" />
        </div>
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          <div className="h-64 animate-pulse rounded-md bg-gray-100" />
          <div className="h-64 animate-pulse rounded-md bg-gray-100" />
        </div>
      </div>
    )
  }

  if (isError || !data) {
    return (
      <div className="p-4">
        <div className="rounded-md border border-red-200 bg-red-50 p-4">
          <p className="text-sm text-red-800">Failed to load the overview.</p>
          <button
            onClick={() => refetch()}
            className="mt-2 rounded-md border border-red-200 px-3 py-1 text-sm text-red-800"
          >
            Try again
          </button>
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-4 p-4">
      <div className="flex items-baseline justify-between">
        <h1 className="text-xl font-semibold">Overview</h1>
        <p className="text-xs text-muted-foreground">
          Last refreshed: {data.generated_at}
        </p>
      </div>

      <PendingUpdatesWidget counts={data.pending_updates} />

      <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
        <SitesNeedingAttentionWidget sites={data.sites_needing_attention} />
        <RecentActivityWidget events={data.recent_activity} />
      </div>
    </div>
  )
}
```

In `apps/web/src/routes/Home.tsx`, change the redirect target:

```tsx
import { Navigate } from 'react-router-dom'

/**
 * The root authenticated route. P2.5: post-login landing is /overview.
 */
export default function Home() {
  return <Navigate to="/overview" replace />
}
```

In `apps/web/src/App.tsx`, register the new route inside the `<RequireAuth>` block. The file currently looks like:

```tsx
<Routes>
  <Route path="/login" element={<Login />} />
  <Route element={<RequireAuth />}>
    <Route path="/" element={<Home />} />
    <Route path="/sites" element={<SitesList />} />
    <Route path="/sites/add" element={<SiteAdd />} />
    <Route path="/sites/:id" element={<SiteDetail />} />
    <Route path="/activity" element={<Activity />} />
  </Route>
</Routes>
```

Add the import at the top of the file:
```tsx
import Overview from '@/routes/Overview'
```

Add the route inside `<RequireAuth>`:
```tsx
<Route path="/overview" element={<Overview />} />
```

In `apps/web/src/lib/queries/useSites.ts`, extend with optional filter:

```ts
import { useQuery } from '@tanstack/react-query'
import { apiClient } from '@/lib/apiClient'
import { sitesListSchema } from '@/types/api'

type SitesFilter = 'has-plugin-updates' | 'has-theme-updates' | 'has-core-update'

interface UseSitesOptions {
  filter?: SitesFilter
}

export function useSites(opts: UseSitesOptions = {}) {
  return useQuery({
    queryKey: ['sites', opts.filter ?? null],
    queryFn: async () => {
      const path = opts.filter ? `/sites?filter=${opts.filter}` : '/sites'
      const data = await apiClient.get<unknown>(path)
      return sitesListSchema.parse(data)
    },
  })
}
```

In `apps/web/src/routes/SitesList.tsx`, parse the `?filter=` query string. Read the current file to preserve existing JSX, then add at the top of the function body:

```tsx
import { useSearchParams } from 'react-router-dom'
// ... existing imports

export default function SitesList() {
  const [searchParams] = useSearchParams()
  const filterParam = searchParams.get('filter')
  const filter =
    filterParam === 'has-plugin-updates' ||
    filterParam === 'has-theme-updates' ||
    filterParam === 'has-core-update'
      ? filterParam
      : undefined
  const { data, isLoading } = useSites({ filter })

  // ... rest of the existing component unchanged
}
```

(Preserve the existing rendering logic — only change is the `useSearchParams` line and passing `filter` into `useSites`.)

### Step 4: Run tests to verify they pass

```
cd apps/web && pnpm test -- --run Overview
cd apps/web && pnpm test -- --run
cd apps/web && pnpm lint
```
Expected: PASS for Overview tests. Full suite + lint clean (same pre-existing baseline failures as before are tolerated).

### Step 5: Commit

```bash
git -C "/Users/pradeep/Local Sites/defynWP" add apps/web/src/routes/Overview.tsx \
        apps/web/src/routes/Home.tsx \
        apps/web/src/App.tsx \
        apps/web/src/lib/queries/useSites.ts \
        apps/web/src/routes/SitesList.tsx \
        apps/web/tests/routes/Overview.test.tsx
git -C "/Users/pradeep/Local Sites/defynWP" commit -m "feat(p2-5): Overview route + Home redirect + SitesList filter

/overview is the new post-login landing. Home now redirects there
instead of /sites. SitesList parses ?filter= via useSearchParams and
passes through to useSites({filter}). useSites accepts optional
filter param; no filter = existing behavior preserved. Per spec § 4."
```

---

## Task 11 — Build zips + 8-step manual smoke matrix

**Files:** none (build + smoke playbook).

Run the spec § 6.2 smoke matrix verbatim. Do NOT proceed to Task 12 unless all 8 steps are green.

- [ ] **Step 1: Confirm all suites green**

```
cd packages/dashboard-plugin && composer test
cd apps/web && pnpm test -- --run
cd apps/web && pnpm lint
```
Expected: ALL PASS (or the same pre-existing P2.4.1 carry-forward failures).

- [ ] **Step 2: Build dashboard zip (v0.7.0) — apply lessons**

```bash
cd /Users/pradeep/Local\ Sites/defynWP/packages/dashboard-plugin
composer install --no-dev --classmap-authoritative
rm -f ~/Desktop/defyn-dashboard-v0.7.0-$(date +%Y-%m-%d).zip
zip -rq ~/Desktop/defyn-dashboard-v0.7.0-$(date +%Y-%m-%d).zip . \
  -x "tests/*" "node_modules/*" "*.git*" "phpunit.xml*" "*.lock"
ls -lah ~/Desktop/defyn-dashboard-v0.7.0-$(date +%Y-%m-%d).zip
composer install
```
Target zip size: ~570KB. If dramatically larger, the dev-package prune didn't take.

- [ ] **Step 3: Build SPA**

```bash
cd /Users/pradeep/Local\ Sites/defynWP/apps/web
pnpm build
ls -lah dist/index.html dist/assets/*.js | head -3
```
Expected: a fresh `dist/` directory.

- [ ] **Step 4: Install on production**

1. Upload the dashboard zip to `defynwp.defyn.agency` via Plugins → Add New → Upload → Replace current with uploaded version.
2. **Clear MyKinsta cache** (Tools → Clear cache). Per plan-bug trap #12 — without this the new `/overview` route may 404 for hours.
3. Push branch + main to origin to trigger Cloudflare auto-deploy:
   ```
   git push origin p2-5-overview-dashboard
   git checkout main
   git merge --ff-only p2-5-overview-dashboard
   git push origin main
   git checkout p2-5-overview-dashboard
   ```
4. Watch Cloudflare Pages for the deploy completion (1-3 min).

- [ ] **Step 5: Run the 8-step smoke matrix from spec § 6.2**

Document each step's outcome inline (PASS/FAIL). If any step fails, STOP — file `fix(p2-5):` commits before tagging.

```bash
TOKEN=$(curl -s -X POST https://defynwp.defyn.agency/wp-json/defyn/v1/auth/login \
  -H "Content-Type: application/json" \
  --data '{"email":"pradeep@defyn.com.au","password":"DefynWP-ifirCh5pXm5bTOj0"}' \
  | python3 -c "import sys,json; print(json.load(sys.stdin)['access_token'])")
```

| # | Action | Expected |
|---|---|---|
| 1 | `curl -H "Authorization: Bearer $TOKEN" "https://defynwp.defyn.agency/wp-json/defyn/v1/overview"` | 200 + full envelope shape (pending_updates, sites_needing_attention, recent_activity, generated_at). All present even if empty. |
| 2 | Same call WITHOUT `Authorization` header | 401 `auth.required` |
| 3 | 31× same call from same user in 1 minute | 31st returns 429 `overview.rate_limited` |
| 4 | SPA: log out, log in fresh | Lands on `/overview`, NOT `/sites`. |
| 5 | SPA at `/overview` with current state | Counts render. Attention list either empty + "All sites healthy ✓" or shows SmartCoding if it has lingering state. Activity feed shows the last 25 events. |
| 6 | Synthetic inject: `UPDATE wp_defyn_sites SET last_contact_at = '2025-01-01 00:00:00' WHERE id = 1;` then refresh SPA | "Sites needing attention" widget shows SmartCoding row with `offline` chip (red `bg-red-100 text-red-800`). |
| 7 | Trigger a plugin update from `/sites/1`, wait ~30s, navigate back to `/overview` | Activity widget shows the new `plugin_update.requested → started → succeeded\|failed` triplet at the top. |
| 8 | Click "Plugin updates" count card on `/overview` | Navigates to `/sites?filter=has-plugin-updates`. Sites list filters to only sites with update_available=1. |

- [ ] **Step 6: Document smoke results + cleanup**

If smoke is green, run cleanup SQL:
```sql
UPDATE wp_defyn_sites SET last_contact_at = NOW() WHERE id = 1;
```

If any step fails, file `fix(p2-5): …` commits before re-running from the failing step.

- [ ] **Step 7: Commit (only if any fix commits were needed)**

If smoke was green on the first run, this task creates no commits.

---

## Task 12 — Tag + push

**Files:** none (git tag).

ONLY run this task after Task 11's smoke matrix is fully green AND cleanup applied. **NEVER push the tag if any smoke step failed.**

- [ ] **Step 1: Verify all suites green + working tree clean**

```bash
cd packages/dashboard-plugin && composer test
cd apps/web && pnpm test -- --run
cd apps/web && pnpm lint
git status
```
Expected: ALL PASS + `nothing to commit, working tree clean`.

- [ ] **Step 2: Create the annotated tag**

```bash
git tag -a p2-5-overview-dashboard-complete -m "P2.5 — Operator overview dashboard shipped

- Dashboard v0.7.0: new GET /defyn/v1/overview endpoint (30/MINUTE bucket
  — first per-minute rate limit in the project); OverviewService composes
  3-section response (pending_updates, sites_needing_attention,
  recent_activity); SitesRepository gains 5 count methods +
  findSitesNeedingAttention with hardcoded thresholds (15m offline,
  30d SSL, 24h sync-stale) + optional filter on findAllForUser;
  ActivityLogRepository::tailForUser with EXISTS subquery for index use.
- SPA: new /overview route as post-login landing; 3 widgets in Layout A
  (PendingUpdatesWidget + SitesNeedingAttentionWidget + RecentActivityWidget);
  AttentionReasonChip with red/amber palette; useOverview hook polling 60s;
  SitesList gains ?filter=has-plugin-updates|has-theme-updates|has-core-update
  via useSearchParams.
- No connector changes (connector stays at v0.1.7).
- Spec: docs/superpowers/specs/2026-06-07-p2-5-overview-dashboard-design.md
"
```

- [ ] **Step 3: Push the tag**

```bash
git push origin p2-5-overview-dashboard-complete
```

- [ ] **Step 4: Update MEMORY**

Append a one-line entry to `~/.claude/projects/-Users-pradeep-Local-Sites-defynWP/memory/project_defyn_overview.md`:
- "P2.5 (Operator overview dashboard) COMPLETE 2026-06-07 — tag `p2-5-overview-dashboard-complete`, dashboard v0.7.0 live in prod, SPA /overview is the new landing page. Connector unchanged at v0.1.7. Live SQL aggregation, 60s polling. Next: P2.6 — bulk actions."

Any new plan-bug lessons surfaced during execution go into `MEMORY.md`.

---

## Self-review — coverage against spec

Walking the spec sections to confirm every requirement maps to a task:

- **Spec § 1 architecture** — covered collectively.
- **Spec § 2 schema (stays v6)** — no task needed; no schema change.
- **Spec § 3.1 route + auth + rate limit + cache headers** — Task 5.
- **Spec § 3.2 response shape** — Task 4 (OverviewService) + Task 5 (controller emits it).
- **Spec § 3.3 file structure** — Tasks 1-5.
- **Spec § 3.4 attention criteria thresholds** — Task 2.
- **Spec § 3.5 SitesList filter extension** — Task 2 (`findAllForUser` filter) + Task 10 (SitesList parsing).
- **Spec § 3.6 dashboard tests** — Tasks 1-5 (~16 tests).
- **Spec § 3.7 dashboard release** — Task 6.
- **Spec § 4.1 new route + nav** — Task 10 (Home redirect; nav link skipped if no shared nav exists).
- **Spec § 4.2 file structure** — Tasks 7-10.
- **Spec § 4.3 empty + loading + error states** — Task 10.
- **Spec § 4.4 visual details** — Tasks 8-10.
- **Spec § 4.5 SPA tests** — Tasks 7-10 (~13 tests).
- **Spec § 4.6 routing edge cases** — Task 10.
- **Spec § 5 testing strategy** — sum of per-task tests.
- **Spec § 6 manual smoke flow** — Task 11.
- **Spec § 8 plan-author notes (13 plan-bug traps)** — encoded in workflow conventions.
- **Spec § 9 acceptance criteria** — Task 12.

All sections covered. ✅

## Self-review — placeholder scan

Searched for `TBD`, `TODO`, `implement later`, `fill in`, `similar to Task N` — none present in concrete code blocks. Task 10's "(Preserve the existing rendering logic — only change is the `useSearchParams` line and passing `filter` into `useSites`)" is an explicit pointer to in-tree code, NOT a placeholder.

## Self-review — type consistency

- `OverviewAttentionReason` enum (`offline | failed_update | ssl_expiring | sync_stale`) matches Task 2 (PHP strings), Task 7 (Zod), Task 8 (AttentionReasonChip PALETTE keys).
- `findSitesNeedingAttention` return shape consistent across Task 2 → Task 4 → Task 5 → Task 7 → Task 9.
- `OVERVIEW_LIMIT = 30` and `OVERVIEW_WINDOW = MINUTE_IN_SECONDS` consistent in Task 5 (RateLimit) + test name (`testRateLimit429AfterThirtyFirstCall`) + plan-bug trap #1.
- `useSites({filter})` signature consistent across Task 10 + Task 2 (PHP `findAllForUser(int $userId, ?string $filter = null)`).
- `Overview` Zod type references (`Overview['pending_updates']`, etc.) consistent across Tasks 8-9 component props + Task 10 route consumer.

No drift. ✅

---

## Execution handoff

**Plan complete and saved to `docs/superpowers/plans/2026-06-07-p2-5-overview-dashboard.md`. Two execution options:**

**1. Subagent-Driven (recommended)** — I dispatch a fresh subagent per task, two-stage review (spec compliance + code quality) between tasks, same-session fast iteration. This is what every prior P2.x phase used.

**2. Inline Execution** — Execute tasks in this session using the executing-plans skill, batch execution with checkpoints for review.

**Which approach?**
