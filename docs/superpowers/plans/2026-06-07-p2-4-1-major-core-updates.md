# P2.4.1 Major-Version WordPress Core Updates Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend P2.4's minor-only WordPress core update pipeline to support major-version upgrades (e.g. 7.4 → 8.0) behind a per-site opt-in flag, with a red-tier confirmation dialog and a "type-the-version" guard. Output: connector v0.1.6 → v0.1.7 + dashboard v0.5.0 → v0.6.0. Schema v5 → v6 adds `core_allow_major TINYINT(1)` on `wp_defyn_sites` plus `tested_up_to VARCHAR(20)` on plugin + theme tables (feeds the compat list in the dialog).

**Architecture:** P2.4 ships two enforcement points that block major bumps — `CoreUpgraderService::upgrade()` (throws `MajorUpdateBlockedException`) and `SitesCoreUpdateController` preflight #4 (returns 409 `core.major_update_blocked`). P2.4.1 relaxes both behind the `core_allow_major` flag. The flag is toggled via a new `POST /defyn/v1/sites/{id}/core/allow-major` endpoint (10/hour bucket, dedicated from coreUpdate's 3/hour). `Jobs\UpdateSiteCore` reads the flag from the site row and threads `{allow_major: bool}` into the connector body; the connector's `CoreUpdateController` parses the body with a strict `=== true` check (defends against `'true'` strings, ints, etc.) and passes through to `CoreUpgraderService::upgrade(bool $allowMajor = false)`. The SPA `SiteCoreCard` gains a 5th visual state ("blocked-major-available" → "Enable in settings") and a red-tier `ConfirmUpdateCoreDialog` variant with a stop-sign emoji, a 3rd compat banner driven by per-plugin/per-theme `tested_up_to` headers, and a type-the-version input that gates the red confirm button.

**Tech Stack:** PHP 8.1+ (PHPUnit, `WP_UnitTestCase` / `AbstractSchemaTestCase`), Action Scheduler, Symfony HttpClient (`MockHttpClient` for tests), WordPress REST API + `Core_Upgrader`, React 18 + TypeScript + TanStack Query v5 + Zod + Tailwind + shadcn/ui + Vitest + React Testing Library + MSW.

**Spec:** [`docs/superpowers/specs/2026-06-07-p2-4-1-major-core-updates-design.md`](../specs/2026-06-07-p2-4-1-major-core-updates-design.md)

---

## Workflow conventions

- **Branch:** Branch off **`p2-4-core-updates`** (currently HEAD at `a7e09f6` — the just-committed P2.4.1 spec), NOT `main`. The dashboard schema v6 migration builds on the schema v5 from P2.4 — branching off main would require manually rebasing schema column ordering once P2.4 merges. Branch name: `p2-4-1-major-core-updates`. Command:
  ```
  git checkout -b p2-4-1-major-core-updates p2-4-core-updates
  ```
- **Each Task = one atomic commit.**
- **Test discipline (TDD):** Step 1 writes the failing test. Step 2 runs it and confirms it fails. Step 3 writes the implementation. Step 4 confirms it passes. Step 5 commits.
- **Test runners:**
  - Connector PHP: `cd packages/connector-plugin && composer test`
  - Dashboard PHP: `cd packages/dashboard-plugin && composer test`
  - SPA: `cd apps/web && pnpm test -- --run`
- **Commit message format:** `<type>(p2-4-1): <description>` where `<type>` ∈ {feat, fix, refactor, docs, test, chore}.
- **All edits adhere to** `~/.claude/rules/common/coding-style.md` — immutability, KISS, DRY, YAGNI, error handling, no console.log / var_dump / print_r.
- **Existing pipeline is unchanged.** P2.4's job lifecycle (`core_update.requested → started → succeeded|failed`), shared `defyn_connector_upgrade_in_flight` transient lock, STDOUT discipline, 300s `signedPostJson` timeout, 5-retry backoff for `connector.upgrade_in_progress`, and `markSynced` single-row heal ALL stay as-is. P2.4.1 only changes what happens at the **decision point** (allow vs. block major) and adds **one new endpoint** (allow-major toggle) plus **three new schema columns**.
- **Plan-bug traps to internalise before writing any code:**
  1. **`RateLimit::CORE_ALLOW_MAJOR_LIMIT = 10`** — 10/hr bucket. Test method MUST be `testRateLimit429AfterEleventhCall`. The 11th call returns 429, the 10th is still 200. (Copy-paste from `testRateLimit429AfterFourthCall` in P2.4's coreUpdate test is the trap.)
  2. **`CoreUpgraderService::upgrade(bool $allowMajor = false): array`** — default `false` is MANDATORY for backward compatibility (the existing P2.4 controller calls `$this->service->upgrade()` with no args; that call site stays unchanged in P2.4.1 because the controller will now pass the flag explicitly).
  3. **`CoreUpdateController` body parsing must use strict `=== true`:**
     ```php
     $allowMajor = isset($body['allow_major']) && $body['allow_major'] === true;
     ```
     NOT `(bool) $body['allow_major']` or `!empty($body['allow_major'])`. Defends against `'true'` strings, `1` ints, etc. — the contract is "explicit opt-in only."
  4. **Activity event name:** `core_allow_major.toggled` (NOT `site.allow_major.changed`, NOT `core.allow_major.toggled`, NOT `core.allow_major_toggled`). Exact match expected by `SitesCoreAllowMajorTest::testActivityLogEventEmitted`.
  5. **`Models\Plugin::testedUpTo` and `Models\Theme::testedUpTo` are nullable string** (`?string $testedUpTo = null`). Older connector versions or plugins/themes without the `Tested up to:` header emit `null`. Test BOTH branches in toJson() coverage.
  6. **`SitesCoreUpdateController` preflight relaxation logic MUST use AND, not OR:**
     ```php
     if (!self::isMinorUpgrade($current, $target) && !$site->coreAllowMajor) {
         return ErrorResponse::create(409, 'core.major_update_blocked', ...);
     }
     ```
     Writing `||` would block ALL updates (even minor ones with the flag off). Writing `&&` correctly only blocks the major-without-flag case.
  7. **`Jobs\UpdateSiteCore` body extension:** `'allow_major' => $site->coreAllowMajor` — pass the boolean DIRECTLY. NOT `(int) $site->coreAllowMajor`. The connector's `=== true` check rejects ints, so `(int)` would silently disable the opt-in.
  8. **SPA `ConfirmUpdateCoreDialog` major-variant:** the type-the-version input compares against `core_update_version` **exactly** — case-sensitive, no whitespace trim, no normalization. The confirm button stays disabled until the input value `=== site.core_update_version`. Test asserts the disabled state with `'8.0 '` (trailing space) input + `'8.0'` target.
  9. **SPA `SiteMajorUpdatesSettingsRow` ALWAYS renders below `SiteCoreCard` on `SiteDetail`** — not conditional on `core_update_available`. Pre-emptive opt-in is a real operator flow (rolling readiness across a fleet).
  10. **Connector zip build:** `composer dump-autoload --no-dev --classmap-authoritative` MUST run BEFORE `zip`. Exclude `vendor/wordpress/*` + `vendor/johnpbloch/*` + dev packages. Target zip sizes: ~70KB connector, ~550KB dashboard. After zipping, run `composer install` to restore dev autoload.
  11. **Final smoke matrix is § 7.2 of the spec verbatim — 10 steps.** Tag `p2-4-1-major-core-updates-complete` only after all 10 pass AND § 7.3 cleanup is applied.

### Existing-code anchors (read these before starting any task)

- `packages/connector-plugin/src/SiteInfo/CoreUpgraderService.php` — `upgrade(): array` currently throws `MajorUpdateBlockedException` at the `if (!self::isMinorUpgrade(...))` check (lines 65-67 today). Add `bool $allowMajor = false` parameter; relax the throw to `if (!isMinor && !$allowMajor)`.
- `packages/connector-plugin/src/Rest/CoreUpdateController.php` — `handle()` constructs the service with `new CoreUpgraderService()` and calls `$this->service->upgrade()` at line 56. Add body parse before the call; pass `$allowMajor` to the service.
- `packages/connector-plugin/src/SiteInfo/Collector.php` — `collectCoreUpdate()` already emits `is_minor_update`. Add `is_major_update_available` alongside.
- `packages/connector-plugin/src/SiteInfo/PluginListCollector.php` — currently emits name/version/update_available/update_version/active. Add `tested_up_to` via `get_file_data`.
- `packages/connector-plugin/src/SiteInfo/ThemeListCollector.php` — same pattern using `wp_get_theme($slug)->get('TestedUpTo')`.
- `packages/dashboard-plugin/src/Activation.php` — `SCHEMA_VERSION = 5` today. Bump to `6` + add 3 guarded `ADD COLUMN` migrations.
- `packages/dashboard-plugin/src/Models/Site.php` — has 5 P2.4 `coreUpdate*` readonly props (lines 53-58, 84-88, 130-134). Add `coreAllowMajor` as a 6th P2.4 prop.
- `packages/dashboard-plugin/src/Models/Plugin.php` + `Models/Theme.php` — add `?string $testedUpTo = null` prop + `tested_up_to` in fromRow + toJson.
- `packages/dashboard-plugin/src/Services/SitesRepository.php` — add `setCoreAllowMajor(int $siteId, bool $allow): void` method; extend `findById*` SELECT to include the new column.
- `packages/dashboard-plugin/src/Services/SyncPluginsService.php` + `SyncThemesService.php` — persist `tested_up_to` from connector payload (null-safe — older connectors omit the field).
- `packages/dashboard-plugin/src/Rest/SitesCoreUpdateController.php` — preflight #4 at lines 50-60 today returns 409 `core.major_update_blocked`. Relax to `!isMinor && !$site->coreAllowMajor`. Update the error message to "Major WordPress version upgrades require enabling major updates for this site first."
- `packages/dashboard-plugin/src/Jobs/UpdateSiteCore.php` — currently sends `['target_version' => $targetVersion]` in connector body. Add `'allow_major' => $site->coreAllowMajor`. Also include `allow_major` in the `core_update.started` activity payload.
- `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php` — add `CORE_ALLOW_MAJOR_LIMIT = 10` + `CORE_ALLOW_MAJOR_WINDOW = HOUR_IN_SECONDS` constants + `coreAllowMajor(WP_REST_Request $request)` static method.
- `packages/dashboard-plugin/src/Plugin.php` — REST route registration via `RestRouter`. Add the new allow-major route alongside.
- `apps/web/src/types/api.ts` — extend `siteSchema` with `core_allow_major: z.boolean()`; extend plugin + theme schemas with `tested_up_to: z.string().nullable()`.
- `apps/web/src/components/sites/SiteCoreCard.tsx` — currently renders 4 states (idle-no-update, idle-update-available, updating, failed). Add 5th state ("blocked-major-available" → Manage button) and "allowed-major-available" red-tier variant of state 2.
- `apps/web/src/components/sites/ConfirmUpdateCoreDialog.tsx` — currently amber-tier minor dialog. Add `isMajorVariant` flag that switches to red-tier UI with stop-sign + 3rd banner + type-the-version input.
- `apps/web/src/routes/SiteDetail.tsx` — already renders `SiteCoreCard`. Add `SiteMajorUpdatesSettingsRow` below the card.

---

## File structure overview

### Connector plugin (v0.1.7) — new files

| Path | Responsibility |
|---|---|
| `tests/Unit/SiteInfo/CoreUpgraderServiceAllowMajorTest.php` | Tests for the new `bool $allowMajor = false` parameter — blocks default, proceeds when true |
| `tests/Unit/SiteInfo/CollectorIsMajorUpdateTest.php` | Tests for `is_major_update_available` boolean on `/status` core block |
| `tests/Integration/Rest/CoreUpdateAllowMajorTest.php` | Integration test: POST `/core/update` with `{allow_major: true}` for major target |
| `tests/Unit/SiteInfo/CollectorPluginsTestedUpToTest.php` | `tested_up_to` emitted from plugin headers (null when absent) |
| `tests/Unit/SiteInfo/CollectorThemesTestedUpToTest.php` | `tested_up_to` emitted from theme headers (null when absent) |

### Connector plugin — modified files

| Path | What changes |
|---|---|
| `src/SiteInfo/CoreUpgraderService.php` | Add `bool $allowMajor = false` param; relax major-block check |
| `src/SiteInfo/Collector.php` | Add `is_major_update_available` to `collectCoreUpdate()` output |
| `src/Rest/CoreUpdateController.php` | Parse `allow_major` from body (strict `=== true`); pass to service |
| `src/SiteInfo/PluginListCollector.php` | Add `tested_up_to` from `Tested up to:` header |
| `src/SiteInfo/ThemeListCollector.php` | Add `tested_up_to` from theme's `TestedUpTo` header |
| `defyn-connector.php` | Version `0.1.6` → `0.1.7` |
| `readme.txt` | Stable tag + changelog entry |
| `composer.json` | Version `0.1.6` → `0.1.7` |

### Dashboard plugin (v0.6.0) — new files

| Path | Responsibility |
|---|---|
| `src/Rest/SitesCoreAllowMajorController.php` | POST `/sites/{id}/core/allow-major` — toggle flag |
| `tests/Integration/Activation/SchemaVersionMigrationV6Test.php` | v5 → v6 migration + idempotency |
| `tests/Unit/Models/SiteCoreAllowMajorTest.php` | Site model coreAllowMajor field + toJson |
| `tests/Unit/Models/PluginTestedUpToTest.php` | Plugin model testedUpTo field |
| `tests/Unit/Models/ThemeTestedUpToTest.php` | Theme model testedUpTo field |
| `tests/Integration/Services/SitesRepositoryAllowMajorTest.php` | setCoreAllowMajor + findById hydration |
| `tests/Integration/Rest/SitesCoreAllowMajorTest.php` | 6 tests — happy, ownership, validation, rate limit, activity |
| `tests/Integration/Rest/SitesCoreUpdateMajorRelaxTest.php` | Preflight passes when flag on, still blocks when off |
| `tests/Integration/Jobs/UpdateSiteCoreAllowMajorTest.php` | Job body includes `allow_major` from site row |

### Dashboard plugin — modified files

| Path | What changes |
|---|---|
| `src/Activation.php` | `SCHEMA_VERSION = 6` + 3 guarded ALTER methods |
| `src/Models/Site.php` | Add `coreAllowMajor` readonly + fromRow + toJson |
| `src/Models/Plugin.php` | Add `testedUpTo` readonly + fromRow + toJson |
| `src/Models/Theme.php` | Add `testedUpTo` readonly + fromRow + toJson |
| `src/Services/SitesRepository.php` | Add `setCoreAllowMajor()` + extend findById SELECT |
| `src/Services/SyncPluginsService.php` | Persist `tested_up_to` from connector payload |
| `src/Services/SyncThemesService.php` | Persist `tested_up_to` from connector payload |
| `src/Rest/SitesCoreUpdateController.php` | Preflight #4 relaxation (AND, not OR) |
| `src/Jobs/UpdateSiteCore.php` | Add `allow_major` to body + started event payload |
| `src/Rest/Middleware/RateLimit.php` | Add `CORE_ALLOW_MAJOR_LIMIT/WINDOW` + `coreAllowMajor()` method |
| `src/Plugin.php` | Register new `/core/allow-major` REST route |
| `defyn-dashboard.php` | Version `0.5.0` → `0.6.0` |
| `readme.txt` | Stable tag + changelog entry |
| `composer.json` | Version `0.5.0` → `0.6.0` |

### SPA (apps/web) — new files

| Path | Responsibility |
|---|---|
| `src/lib/mutations/useToggleCoreAllowMajor.ts` | TanStack mutation — POST allow-major; invalidate site query |
| `src/components/sites/SiteMajorUpdatesSettingsRow.tsx` | Switch row component, always renders on SiteDetail |
| `src/lib/mutations/useToggleCoreAllowMajor.test.tsx` | Mutation hook tests |
| `src/components/sites/SiteMajorUpdatesSettingsRow.test.tsx` | Component tests |

### SPA — modified files

| Path | What changes |
|---|---|
| `src/types/api.ts` | `core_allow_major` on siteSchema, `tested_up_to` on plugin/theme schemas |
| `src/test/handlers.ts` | New MSW handler for POST `/core/allow-major`; mocked sites include new fields |
| `src/components/sites/SiteCoreCard.tsx` | Add 5th state + red-tier "allowed-major-available" variant |
| `src/components/sites/ConfirmUpdateCoreDialog.tsx` | Add `isMajor` prop with red-tier + stop-sign + 3 banners + type-version input |
| `src/routes/SiteDetail.tsx` | Render `SiteMajorUpdatesSettingsRow` below `SiteCoreCard` |
| `src/components/sites/SiteCoreCard.test.tsx` | Add 5th state tests |
| `src/components/sites/ConfirmUpdateCoreDialog.test.tsx` | Add major-variant tests |

---

## Task 1 — `CoreUpgraderService::upgrade()` accepts `bool $allowMajor = false`

**Files:**
- Modify: `packages/connector-plugin/src/SiteInfo/CoreUpgraderService.php`
- Test: `packages/connector-plugin/tests/Unit/SiteInfo/CoreUpgraderServiceAllowMajorTest.php`

The current method signature is `public function upgrade(): array` and unconditionally throws `MajorUpdateBlockedException` for any major bump. P2.4.1 adds an `$allowMajor` parameter that, when true, lets the upgrade proceed.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Unit\SiteInfo;

use Defyn\Connector\SiteInfo\CapturingUpgraderSkin;
use Defyn\Connector\SiteInfo\CoreUpgraderService;
use Defyn\Connector\SiteInfo\MajorUpdateBlockedException;
use WP_UnitTestCase;

final class CoreUpgraderServiceAllowMajorTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Stub get_core_updates() with a major bump target.
        add_filter('pre_site_transient_update_core', static function (): object {
            $update           = new \stdClass();
            $update->response = 'upgrade';
            $update->current  = '8.0';
            $update->version  = '8.0';
            $obj          = new \stdClass();
            $obj->updates = [$update];
            return $obj;
        });
        // Stub current wp version as 7.4 to make the target a major bump.
        add_filter('bloginfo', static function ($value, $show) {
            return $show === 'version' ? '7.4' : $value;
        }, 10, 2);
    }

    public function testUpgradeDefaultsToBlockedForMajorWithoutAllowFlag(): void
    {
        $service = new CoreUpgraderService();

        $this->expectException(MajorUpdateBlockedException::class);
        $service->upgrade(); // no argument -- backward compat: defaults to allowMajor=false
    }

    public function testUpgradeAcceptsAllowMajorParamAndProceedsOnMajor(): void
    {
        // Inject a no-op upgrader factory that returns true (simulating successful upgrade).
        $factory = static fn(CapturingUpgraderSkin $skin): object => new class {
            public function upgrade(\stdClass $update): bool
            {
                return true;
            }
        };
        $service = new CoreUpgraderService($factory);

        // Should NOT throw -- allowMajor=true permits the bump.
        $result = $service->upgrade(true);

        $this->assertTrue($result['success']);
        $this->assertSame('7.4', $result['previous_version']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:
```
cd packages/connector-plugin && composer test -- --filter CoreUpgraderServiceAllowMajorTest
```

Expected: FAIL — `testUpgradeAcceptsAllowMajorParamAndProceedsOnMajor` throws `MajorUpdateBlockedException` because the current implementation always throws for major bumps regardless of the argument (which isn't accepted yet).

- [ ] **Step 3: Modify `CoreUpgraderService::upgrade()`**

Edit `packages/connector-plugin/src/SiteInfo/CoreUpgraderService.php`:

Change the signature on line 36:
```php
public function upgrade(): array
```
to:
```php
public function upgrade(bool $allowMajor = false): array
```

Change the major-block check on lines 65-67:
```php
if (!self::isMinorUpgrade($current, $target)) {
    throw new MajorUpdateBlockedException($current, $target);
}
```
to:
```php
if (!self::isMinorUpgrade($current, $target) && !$allowMajor) {
    throw new MajorUpdateBlockedException($current, $target);
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run:
```
cd packages/connector-plugin && composer test -- --filter CoreUpgraderServiceAllowMajorTest
```

Expected: PASS — both tests green.

Also run the existing `CoreUpgraderServiceTest` to verify backward compat:
```
cd packages/connector-plugin && composer test -- --filter CoreUpgraderServiceTest
```

Expected: PASS — the original 5 P2.4 tests still pass because the default `false` preserves prior behavior.

- [ ] **Step 5: Commit**

```bash
git add packages/connector-plugin/src/SiteInfo/CoreUpgraderService.php \
        packages/connector-plugin/tests/Unit/SiteInfo/CoreUpgraderServiceAllowMajorTest.php
git commit -m "feat(p2-4-1): CoreUpgraderService accepts allowMajor opt-in param

Default false preserves P2.4 backward compat. When true, the
isMinorUpgrade check no longer throws MajorUpdateBlockedException --
the upgrade proceeds even for major version bumps. Per spec § 3.1."
```

---

## Task 2 — `Collector::collectCoreUpdate()` emits `is_major_update_available`

**Files:**
- Modify: `packages/connector-plugin/src/SiteInfo/Collector.php`
- Test: `packages/connector-plugin/tests/Unit/SiteInfo/CollectorIsMajorUpdateTest.php`

The existing `collectCoreUpdate()` returns `is_minor_update` as a derived boolean. P2.4.1 adds `is_major_update_available` as the explicit complement — useful for SPA debugging and operator clarity in raw `/status` inspection.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Unit\SiteInfo;

use Defyn\Connector\SiteInfo\Collector;
use WP_UnitTestCase;

final class CollectorIsMajorUpdateTest extends WP_UnitTestCase
{
    public function testIsMajorUpdateAvailableTrueWhenMajorBumpPending(): void
    {
        add_filter('bloginfo', static function ($value, $show) {
            return $show === 'version' ? '7.4' : $value;
        }, 10, 2);
        add_filter('pre_site_transient_update_core', static function (): object {
            $update           = new \stdClass();
            $update->response = 'upgrade';
            $update->current  = '8.0';
            $update->version  = '8.0';
            $obj          = new \stdClass();
            $obj->updates = [$update];
            return $obj;
        });

        $result = (new Collector())->collect();

        $this->assertArrayHasKey('core', $result);
        $this->assertTrue($result['core']['update_available']);
        $this->assertFalse($result['core']['is_minor_update']);
        $this->assertTrue($result['core']['is_major_update_available']);
    }

    public function testIsMajorUpdateAvailableFalseWhenMinorOnly(): void
    {
        add_filter('bloginfo', static function ($value, $show) {
            return $show === 'version' ? '7.4' : $value;
        }, 10, 2);
        add_filter('pre_site_transient_update_core', static function (): object {
            $update           = new \stdClass();
            $update->response = 'upgrade';
            $update->current  = '7.4.1';
            $update->version  = '7.4.1';
            $obj          = new \stdClass();
            $obj->updates = [$update];
            return $obj;
        });

        $result = (new Collector())->collect();

        $this->assertTrue($result['core']['is_minor_update']);
        $this->assertFalse($result['core']['is_major_update_available']);
    }

    public function testIsMajorUpdateAvailableFalseWhenNoUpdate(): void
    {
        add_filter('pre_site_transient_update_core', static fn() => null);

        $result = (new Collector())->collect();

        $this->assertFalse($result['core']['update_available']);
        $this->assertFalse($result['core']['is_minor_update']);
        $this->assertFalse($result['core']['is_major_update_available']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:
```
cd packages/connector-plugin && composer test -- --filter CollectorIsMajorUpdateTest
```

Expected: FAIL — `Failed asserting that an array has the key 'is_major_update_available'.`

- [ ] **Step 3: Modify `Collector::collectCoreUpdate()`**

In `packages/connector-plugin/src/SiteInfo/Collector.php`, find the `collectCoreUpdate()` private helper. Locate the return statement that currently looks similar to:
```php
return [
    'wp_version'                   => $currentVersion,
    'update_available'             => $hasUpdate,
    'update_version'               => $newVersion ?: null,
    'is_minor_update'              => $hasUpdate ? $this->isMinorUpgrade($currentVersion, $newVersion) : false,
    'is_minor_auto_update_enabled' => $this->isMinorAutoUpdateEnabled(),
];
```

Insert one new key so it becomes:
```php
return [
    'wp_version'                   => $currentVersion,
    'update_available'             => $hasUpdate,
    'update_version'               => $newVersion ?: null,
    'is_minor_update'              => $hasUpdate ? $this->isMinorUpgrade($currentVersion, $newVersion) : false,
    'is_major_update_available'    => $hasUpdate ? !$this->isMinorUpgrade($currentVersion, $newVersion) : false,
    'is_minor_auto_update_enabled' => $this->isMinorAutoUpdateEnabled(),
];
```

(If the existing key/value names differ from the snippet above, preserve the existing shape and only insert the new `is_major_update_available` key with the same value-or-`false` short-circuit.)

- [ ] **Step 4: Run tests to verify they pass**

Run:
```
cd packages/connector-plugin && composer test -- --filter CollectorIsMajorUpdateTest
```

Expected: PASS — 3 tests green.

Also run the existing `CollectorTest` to verify no regressions on the rest of the `/status` shape:
```
cd packages/connector-plugin && composer test -- --filter CollectorTest
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/connector-plugin/src/SiteInfo/Collector.php \
        packages/connector-plugin/tests/Unit/SiteInfo/CollectorIsMajorUpdateTest.php
git commit -m "feat(p2-4-1): Collector emits is_major_update_available on /status

Adds an explicit complement to is_minor_update. Dashboard ignores it
(derives the same fact from comparing wp_version vs core_update_version)
but the field aids SPA debugging and raw /status inspection. Per spec § 3.3."
```

---

## Task 3 — `CoreUpdateController` parses `allow_major` from body + integration test

**Files:**
- Modify: `packages/connector-plugin/src/Rest/CoreUpdateController.php`
- Test: `packages/connector-plugin/tests/Integration/Rest/CoreUpdateAllowMajorTest.php`

The controller currently calls `$this->service->upgrade()` with no arguments. P2.4.1 parses `allow_major` from the request body with a strict `=== true` check and threads it through.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Rest;

use Defyn\Connector\Rest\CoreUpdateController;
use Defyn\Connector\SiteInfo\CapturingUpgraderSkin;
use Defyn\Connector\SiteInfo\CoreUpgraderService;
use WP_REST_Request;
use WP_UnitTestCase;

final class CoreUpdateAllowMajorTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Stub: current 7.4, target 8.0 (major bump).
        add_filter('bloginfo', static function ($value, $show) {
            return $show === 'version' ? '7.4' : $value;
        }, 10, 2);
        add_filter('pre_site_transient_update_core', static function (): object {
            $update           = new \stdClass();
            $update->response = 'upgrade';
            $update->current  = '8.0';
            $update->version  = '8.0';
            $obj          = new \stdClass();
            $obj->updates = [$update];
            return $obj;
        });

        delete_transient('defyn_connector_upgrade_in_flight');
    }

    private function buildController(): CoreUpdateController
    {
        $factory = static fn(CapturingUpgraderSkin $skin): object => new class {
            public function upgrade(\stdClass $update): bool
            {
                return true;
            }
        };
        return new CoreUpdateController(new CoreUpgraderService($factory));
    }

    public function testAllowMajorBodyParamPassesThroughToServiceAndSucceeds(): void
    {
        $request = new WP_REST_Request('POST', '/defyn-connector/v1/core/update');
        $request->set_body(json_encode(['allow_major' => true]));
        $request->set_header('Content-Type', 'application/json');

        $response = $this->buildController()->handle($request);

        $this->assertSame(200, $response->get_status());
        $this->assertTrue($response->get_data()['success']);
    }

    public function testMissingAllowMajorFieldStillBlocksMajor(): void
    {
        $request = new WP_REST_Request('POST', '/defyn-connector/v1/core/update');
        $request->set_body(json_encode(['some_other_field' => 'value']));
        $request->set_header('Content-Type', 'application/json');

        $response = $this->buildController()->handle($request);

        $this->assertSame(409, $response->get_status());
        $this->assertSame('core.major_update_blocked', $response->get_data()['error']['code']);
    }

    public function testAllowMajorAsStringTrueStillBlocksMajor(): void
    {
        // Defends against the strict === true check requirement.
        $request = new WP_REST_Request('POST', '/defyn-connector/v1/core/update');
        $request->set_body(json_encode(['allow_major' => 'true']));
        $request->set_header('Content-Type', 'application/json');

        $response = $this->buildController()->handle($request);

        $this->assertSame(409, $response->get_status());
        $this->assertSame('core.major_update_blocked', $response->get_data()['error']['code']);
    }

    public function testAllowMajorAsIntegerOneStillBlocksMajor(): void
    {
        $request = new WP_REST_Request('POST', '/defyn-connector/v1/core/update');
        $request->set_body(json_encode(['allow_major' => 1]));
        $request->set_header('Content-Type', 'application/json');

        $response = $this->buildController()->handle($request);

        $this->assertSame(409, $response->get_status());
        $this->assertSame('core.major_update_blocked', $response->get_data()['error']['code']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:
```
cd packages/connector-plugin && composer test -- --filter CoreUpdateAllowMajorTest
```

Expected: FAIL — `testAllowMajorBodyParamPassesThroughToServiceAndSucceeds` returns 409 because the controller doesn't yet read the body or forward the flag.

- [ ] **Step 3: Modify `CoreUpdateController::handle()`**

In `packages/connector-plugin/src/Rest/CoreUpdateController.php`, find the `try` block (around line 55):

```php
try {
    $result = $this->service->upgrade();
    return new WP_REST_Response($result, 200);
```

Replace with:
```php
try {
    $body       = $request->get_json_params() ?: [];
    $allowMajor = isset($body['allow_major']) && $body['allow_major'] === true;
    $result     = $this->service->upgrade($allowMajor);
    return new WP_REST_Response($result, 200);
```

The `=== true` check is mandatory — `'true'` strings, `1` ints, and other truthy values must be rejected. The integration test asserts this.

- [ ] **Step 4: Run tests to verify they pass**

Run:
```
cd packages/connector-plugin && composer test -- --filter CoreUpdateAllowMajorTest
```

Expected: PASS — all 4 tests green.

Also run the existing `CoreUpdateTest` (the P2.4 baseline) to verify no regressions in the unchanged code paths:
```
cd packages/connector-plugin && composer test -- --filter CoreUpdateTest
```

Expected: PASS — all existing tests still pass because they don't send `allow_major: true`.

- [ ] **Step 5: Commit**

```bash
git add packages/connector-plugin/src/Rest/CoreUpdateController.php \
        packages/connector-plugin/tests/Integration/Rest/CoreUpdateAllowMajorTest.php
git commit -m "feat(p2-4-1): CoreUpdateController parses allow_major (strict === true)

Body parameter allow_major opts the request into a major-version
upgrade. Strict identity check defends against truthy-but-not-true
values ('true' strings, 1 ints, etc) -- the contract is explicit
boolean opt-in only. Per spec § 3.2."
```

---

## Task 4 — `PluginListCollector` + `ThemeListCollector` emit `tested_up_to`

**Files:**
- Modify: `packages/connector-plugin/src/SiteInfo/PluginListCollector.php`
- Modify: `packages/connector-plugin/src/SiteInfo/ThemeListCollector.php`
- Test: `packages/connector-plugin/tests/Unit/SiteInfo/CollectorPluginsTestedUpToTest.php`
- Test: `packages/connector-plugin/tests/Unit/SiteInfo/CollectorThemesTestedUpToTest.php`

Both collectors gain a `tested_up_to` field per row, sourced from the `Tested up to:` header in plugin/theme metadata. Null when the header is absent (older plugins/themes).

- [ ] **Step 1: Write the failing tests**

Create `packages/connector-plugin/tests/Unit/SiteInfo/CollectorPluginsTestedUpToTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Unit\SiteInfo;

use Defyn\Connector\SiteInfo\PluginListCollector;
use WP_UnitTestCase;

final class CollectorPluginsTestedUpToTest extends WP_UnitTestCase
{
    public function testCollectorEmitsTestedUpToWhenHeaderPresent(): void
    {
        $stub = [
            'akismet/akismet.php' => [
                'Name'         => 'Akismet',
                'Version'      => '5.0',
                'Tested up to' => '6.4',
            ],
        ];
        add_filter('all_plugins', static fn() => $stub);
        add_filter('option_active_plugins', static fn() => ['akismet/akismet.php']);

        $rows = (new PluginListCollector())->collect();

        $this->assertCount(1, $rows);
        $this->assertSame('6.4', $rows[0]['tested_up_to']);
    }

    public function testCollectorEmitsNullWhenHeaderAbsent(): void
    {
        $stub = [
            'old-plugin/old-plugin.php' => [
                'Name'    => 'Old Plugin',
                'Version' => '1.0',
            ],
        ];
        add_filter('all_plugins', static fn() => $stub);
        add_filter('option_active_plugins', static fn() => []);

        $rows = (new PluginListCollector())->collect();

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['tested_up_to']);
    }
}
```

Create `packages/connector-plugin/tests/Unit/SiteInfo/CollectorThemesTestedUpToTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Unit\SiteInfo;

use Defyn\Connector\SiteInfo\ThemeListCollector;
use WP_UnitTestCase;

final class CollectorThemesTestedUpToTest extends WP_UnitTestCase
{
    public function testEveryThemeRowHasTestedUpToKey(): void
    {
        $rows = (new ThemeListCollector())->collect();
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertArrayHasKey('tested_up_to', $row, 'every theme row must have tested_up_to key');
            $this->assertTrue(
                $row['tested_up_to'] === null || is_string($row['tested_up_to']),
                'tested_up_to must be null or string'
            );
        }
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run:
```
cd packages/connector-plugin && composer test -- --filter CollectorPluginsTestedUpToTest
cd packages/connector-plugin && composer test -- --filter CollectorThemesTestedUpToTest
```

Expected: FAIL — `tested_up_to` key not present on rows.

- [ ] **Step 3: Modify the collectors**

In `packages/connector-plugin/src/SiteInfo/PluginListCollector.php`, find the per-plugin row construction loop. For each iteration, add to the row array:

```php
'tested_up_to' => !empty($plugins[$pluginFile]['Tested up to'])
    ? (string) $plugins[$pluginFile]['Tested up to']
    : null,
```

(Adjust the variable name to match the existing iterator variable in the file. If the existing code uses `$pluginData['Name']` etc., the line becomes `!empty($pluginData['Tested up to']) ? (string) $pluginData['Tested up to'] : null`.)

In `packages/connector-plugin/src/SiteInfo/ThemeListCollector.php`, find the per-theme row construction. Add:

```php
$themeObj = wp_get_theme($slug);
$tested   = $themeObj->get('TestedUpTo');
$row['tested_up_to'] = ($tested !== false && $tested !== '') ? (string) $tested : null;
```

(`wp_get_theme()->get('TestedUpTo')` returns `false` if the header isn't declared; normalize to `null`. Adjust `$slug` to match the existing iterator variable name.)

- [ ] **Step 4: Run tests to verify they pass**

Run:
```
cd packages/connector-plugin && composer test -- --filter CollectorPluginsTestedUpToTest
cd packages/connector-plugin && composer test -- --filter CollectorThemesTestedUpToTest
```

Expected: PASS.

Also run the existing baseline collector tests to verify no regressions:
```
cd packages/connector-plugin && composer test -- --filter PluginListCollectorTest
cd packages/connector-plugin && composer test -- --filter ThemeListCollectorTest
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/connector-plugin/src/SiteInfo/PluginListCollector.php \
        packages/connector-plugin/src/SiteInfo/ThemeListCollector.php \
        packages/connector-plugin/tests/Unit/SiteInfo/CollectorPluginsTestedUpToTest.php \
        packages/connector-plugin/tests/Unit/SiteInfo/CollectorThemesTestedUpToTest.php
git commit -m "feat(p2-4-1): plugin + theme collectors emit tested_up_to header

Both inventory collectors now surface the Tested up to header from
plugin/theme metadata as a nullable string. Feeds the SPA's compat
list in the major-upgrade dialog. Per spec § 3.4 + § 3.5."
```

---

## Task 5 — Connector v0.1.7 release bump

**Files:**
- Modify: `packages/connector-plugin/defyn-connector.php`
- Modify: `packages/connector-plugin/readme.txt`
- Modify: `packages/connector-plugin/composer.json`

- [ ] **Step 1: Bump version constants**

In `packages/connector-plugin/defyn-connector.php`, change the plugin header `Version: 0.1.6` to `Version: 0.1.7`. Also update any `DEFYN_CONNECTOR_VERSION` constant from `'0.1.6'` to `'0.1.7'`.

In `packages/connector-plugin/composer.json`, change `"version": "0.1.6"` to `"version": "0.1.7"`.

In `packages/connector-plugin/readme.txt`, update `Stable tag: 0.1.6` to `Stable tag: 0.1.7` and prepend a changelog entry:

```
= 0.1.7 =
* Add per-request `allow_major` opt-in to /core/update for major version upgrades.
* Add `is_major_update_available` field to /status core block.
* PluginListCollector + ThemeListCollector now emit `tested_up_to` from plugin/theme headers.
```

- [ ] **Step 2: Run all connector tests**

Run:
```
cd packages/connector-plugin && composer test
```

Expected: ALL PASS (Tasks 1-4 tests + every prior P2.x suite).

- [ ] **Step 3: Commit**

```bash
git add packages/connector-plugin/defyn-connector.php \
        packages/connector-plugin/readme.txt \
        packages/connector-plugin/composer.json
git commit -m "chore(p2-4-1): connector v0.1.7 release version bump"
```

---

## Task 6 — Schema v6 migration: 3 new columns

**Files:**
- Modify: `packages/dashboard-plugin/src/Activation.php`
- Test: `packages/dashboard-plugin/tests/Integration/Activation/SchemaVersionMigrationV6Test.php`

Schema bumps from `5` → `6`. Adds:
- `wp_defyn_sites.core_allow_major TINYINT(1) NOT NULL DEFAULT 0` (after `last_core_update_attempt_at`)
- `wp_defyn_site_plugins.tested_up_to VARCHAR(20) NULL` (after `update_version`)
- `wp_defyn_site_themes.tested_up_to VARCHAR(20) NULL` (after `update_version`)

All guarded by `SHOW COLUMNS LIKE` so the migration is idempotent.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Activation;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SchemaVersionMigrationV6Test extends AbstractSchemaTestCase
{
    public function testSchemaVersionConstantIsSix(): void
    {
        $this->assertSame(6, Activation::SCHEMA_VERSION);
    }

    public function testActivationAddsCoreAllowMajorColumn(): void
    {
        global $wpdb;
        Activation::ensureSchema();

        $table   = $wpdb->prefix . 'defyn_sites';
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);

        $this->assertContains('core_allow_major', $columns);

        $colDef = $wpdb->get_row(
            "SHOW COLUMNS FROM {$table} LIKE 'core_allow_major'",
            ARRAY_A
        );
        $this->assertStringContainsString('tinyint(1)', strtolower($colDef['Type']));
        $this->assertSame('NO', $colDef['Null']);
        $this->assertSame('0', $colDef['Default']);
    }

    public function testActivationAddsTestedUpToOnPlugins(): void
    {
        global $wpdb;
        Activation::ensureSchema();

        $table   = $wpdb->prefix . 'defyn_site_plugins';
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
        $this->assertContains('tested_up_to', $columns);

        $colDef = $wpdb->get_row(
            "SHOW COLUMNS FROM {$table} LIKE 'tested_up_to'",
            ARRAY_A
        );
        $this->assertStringContainsString('varchar(20)', strtolower($colDef['Type']));
        $this->assertSame('YES', $colDef['Null']);
    }

    public function testActivationAddsTestedUpToOnThemes(): void
    {
        global $wpdb;
        Activation::ensureSchema();

        $table   = $wpdb->prefix . 'defyn_site_themes';
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
        $this->assertContains('tested_up_to', $columns);

        $colDef = $wpdb->get_row(
            "SHOW COLUMNS FROM {$table} LIKE 'tested_up_to'",
            ARRAY_A
        );
        $this->assertStringContainsString('varchar(20)', strtolower($colDef['Type']));
        $this->assertSame('YES', $colDef['Null']);
    }

    public function testV6MigrationIsIdempotent(): void
    {
        Activation::ensureSchema();
        Activation::ensureSchema(); // second call should not error
        $this->assertSame(6, Activation::SCHEMA_VERSION);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:
```
cd packages/dashboard-plugin && composer test -- --filter SchemaVersionMigrationV6Test
```

Expected: FAIL — `Failed asserting that 5 is identical to 6.`

- [ ] **Step 3: Modify `Activation.php`**

In `packages/dashboard-plugin/src/Activation.php`:

1. Change the constant:
```php
public const SCHEMA_VERSION = 5;
```
to:
```php
public const SCHEMA_VERSION = 6;
```

2. In the `ensureSchema()` method body, after the existing `addCoreUpdateColumns()` call (or wherever the v5 migrations are invoked), add three new private method calls:
```php
self::addCoreAllowMajorColumn($wpdb);
self::addPluginsTestedUpToColumn($wpdb);
self::addThemesTestedUpToColumn($wpdb);
```

3. Append three new private static methods at the bottom of the class:

```php
private static function addCoreAllowMajorColumn(\wpdb $wpdb): void
{
    $table  = $wpdb->prefix . 'defyn_sites';
    $exists = $wpdb->get_var($wpdb->prepare(
        "SHOW COLUMNS FROM {$table} LIKE %s",
        'core_allow_major'
    ));
    if ($exists) {
        return;
    }
    $wpdb->query(
        "ALTER TABLE {$table}
         ADD COLUMN core_allow_major TINYINT(1) NOT NULL DEFAULT 0
         AFTER last_core_update_attempt_at"
    );
}

private static function addPluginsTestedUpToColumn(\wpdb $wpdb): void
{
    $table  = $wpdb->prefix . 'defyn_site_plugins';
    $exists = $wpdb->get_var($wpdb->prepare(
        "SHOW COLUMNS FROM {$table} LIKE %s",
        'tested_up_to'
    ));
    if ($exists) {
        return;
    }
    $wpdb->query(
        "ALTER TABLE {$table}
         ADD COLUMN tested_up_to VARCHAR(20) NULL
         AFTER update_version"
    );
}

private static function addThemesTestedUpToColumn(\wpdb $wpdb): void
{
    $table  = $wpdb->prefix . 'defyn_site_themes';
    $exists = $wpdb->get_var($wpdb->prepare(
        "SHOW COLUMNS FROM {$table} LIKE %s",
        'tested_up_to'
    ));
    if ($exists) {
        return;
    }
    $wpdb->query(
        "ALTER TABLE {$table}
         ADD COLUMN tested_up_to VARCHAR(20) NULL
         AFTER update_version"
    );
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run:
```
cd packages/dashboard-plugin && composer test -- --filter SchemaVersionMigrationV6Test
```

Expected: PASS — all 5 tests green.

Also run the earlier schema migration tests (v4, v5) to verify they're still passing:
```
cd packages/dashboard-plugin && composer test -- --filter SchemaVersionMigration
```

Expected: PASS — earlier migrations are unaffected.

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Activation.php \
        packages/dashboard-plugin/tests/Integration/Activation/SchemaVersionMigrationV6Test.php
git commit -m "feat(p2-4-1): schema v6 -- core_allow_major + tested_up_to columns

Adds wp_defyn_sites.core_allow_major TINYINT(1) DEFAULT 0,
wp_defyn_site_plugins.tested_up_to VARCHAR(20) NULL, and
wp_defyn_site_themes.tested_up_to VARCHAR(20) NULL. All guarded
by SHOW COLUMNS so the migration is idempotent. Per spec § 2."
```

---

## Task 7 — `Site` / `Plugin` / `Theme` model extensions

**Files:**
- Modify: `packages/dashboard-plugin/src/Models/Site.php`
- Modify: `packages/dashboard-plugin/src/Models/Plugin.php`
- Modify: `packages/dashboard-plugin/src/Models/Theme.php`
- Test: `packages/dashboard-plugin/tests/Unit/Models/SiteCoreAllowMajorTest.php`
- Test: `packages/dashboard-plugin/tests/Unit/Models/PluginTestedUpToTest.php`
- Test: `packages/dashboard-plugin/tests/Unit/Models/ThemeTestedUpToTest.php`

`Site` gains `coreAllowMajor: bool` (default `false`). `Plugin` and `Theme` gain `testedUpTo: ?string` (default `null`). All three surface in `fromRow` + `toJson`.

- [ ] **Step 1: Write the failing tests**

Create `packages/dashboard-plugin/tests/Unit/Models/SiteCoreAllowMajorTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Unit\Models;

use Defyn\Dashboard\Models\Site;
use PHPUnit\Framework\TestCase;

final class SiteCoreAllowMajorTest extends TestCase
{
    public function testFromRowDefaultsCoreAllowMajorToFalseWhenColumnAbsent(): void
    {
        $site = Site::fromRow($this->baseRow());
        $this->assertFalse($site->coreAllowMajor);
    }

    public function testFromRowHydratesCoreAllowMajorFromOne(): void
    {
        $row = $this->baseRow();
        $row['core_allow_major'] = '1';
        $site = Site::fromRow($row);
        $this->assertTrue($site->coreAllowMajor);
    }

    public function testFromRowHydratesCoreAllowMajorFromZero(): void
    {
        $row = $this->baseRow();
        $row['core_allow_major'] = '0';
        $site = Site::fromRow($row);
        $this->assertFalse($site->coreAllowMajor);
    }

    public function testToJsonExposesCoreAllowMajor(): void
    {
        $row = $this->baseRow();
        $row['core_allow_major'] = '1';
        $json = Site::fromRow($row)->toJson();

        $this->assertArrayHasKey('core_allow_major', $json);
        $this->assertTrue($json['core_allow_major']);
    }

    /** @return array<string, mixed> */
    private function baseRow(): array
    {
        return [
            'id'         => 1,
            'user_id'    => 1,
            'url'        => 'https://example.com',
            'label'      => 'Example',
            'status'     => 'connected',
            'created_at' => '2026-06-07 00:00:00',
        ];
    }
}
```

Create `packages/dashboard-plugin/tests/Unit/Models/PluginTestedUpToTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Unit\Models;

use Defyn\Dashboard\Models\Plugin;
use PHPUnit\Framework\TestCase;

final class PluginTestedUpToTest extends TestCase
{
    public function testFromRowDefaultsTestedUpToToNull(): void
    {
        $plugin = Plugin::fromRow($this->baseRow());
        $this->assertNull($plugin->testedUpTo);
    }

    public function testFromRowHydratesTestedUpToFromString(): void
    {
        $row = $this->baseRow();
        $row['tested_up_to'] = '6.4';
        $plugin = Plugin::fromRow($row);
        $this->assertSame('6.4', $plugin->testedUpTo);
    }

    public function testToJsonExposesTestedUpToBothBranches(): void
    {
        $row = $this->baseRow();
        $row['tested_up_to'] = '6.4';
        $json = Plugin::fromRow($row)->toJson();
        $this->assertArrayHasKey('tested_up_to', $json);
        $this->assertSame('6.4', $json['tested_up_to']);

        $jsonNull = Plugin::fromRow($this->baseRow())->toJson();
        $this->assertArrayHasKey('tested_up_to', $jsonNull);
        $this->assertNull($jsonNull['tested_up_to']);
    }

    /** @return array<string, mixed> */
    private function baseRow(): array
    {
        return [
            'id'                 => 1,
            'site_id'            => 1,
            'plugin_file'        => 'akismet/akismet.php',
            'name'               => 'Akismet',
            'version'            => '5.0',
            'active'             => '1',
            'update_available'   => '0',
            'update_version'     => null,
            'update_state'       => 'idle',
            'last_update_error'  => null,
            'updated_at'         => '2026-06-07 00:00:00',
        ];
    }
}
```

Create `packages/dashboard-plugin/tests/Unit/Models/ThemeTestedUpToTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Unit\Models;

use Defyn\Dashboard\Models\Theme;
use PHPUnit\Framework\TestCase;

final class ThemeTestedUpToTest extends TestCase
{
    public function testFromRowDefaultsTestedUpToToNull(): void
    {
        $theme = Theme::fromRow($this->baseRow());
        $this->assertNull($theme->testedUpTo);
    }

    public function testFromRowHydratesTestedUpToFromString(): void
    {
        $row = $this->baseRow();
        $row['tested_up_to'] = '6.4';
        $theme = Theme::fromRow($row);
        $this->assertSame('6.4', $theme->testedUpTo);
    }

    public function testToJsonExposesTestedUpToBothBranches(): void
    {
        $row = $this->baseRow();
        $row['tested_up_to'] = '6.4';
        $json = Theme::fromRow($row)->toJson();
        $this->assertSame('6.4', $json['tested_up_to']);

        $jsonNull = Theme::fromRow($this->baseRow())->toJson();
        $this->assertNull($jsonNull['tested_up_to']);
    }

    /** @return array<string, mixed> */
    private function baseRow(): array
    {
        return [
            'id'                 => 1,
            'site_id'            => 1,
            'slug'               => 'twentytwentyfour',
            'name'               => 'Twenty Twenty-Four',
            'version'            => '1.0',
            'active'             => '1',
            'parent'             => null,
            'update_available'   => '0',
            'update_version'     => null,
            'update_state'       => 'idle',
            'last_update_error'  => null,
            'updated_at'         => '2026-06-07 00:00:00',
        ];
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run:
```
cd packages/dashboard-plugin && composer test -- --filter "SiteCoreAllowMajorTest|PluginTestedUpToTest|ThemeTestedUpToTest"
```

Expected: FAIL — `Site->coreAllowMajor` / `Plugin->testedUpTo` / `Theme->testedUpTo` are not defined.

- [ ] **Step 3: Extend the three models**

In `packages/dashboard-plugin/src/Models/Site.php`, add `public readonly bool $coreAllowMajor = false,` as the final constructor parameter (after `$lastCoreUpdateAttemptAt`).

In `fromRow()`, add as the final argument:
```php
coreAllowMajor: (bool) (int) ($row['core_allow_major'] ?? 0),
```

In `toJson()`, add after the existing `'last_core_update_attempt_at'` entry:
```php
'core_allow_major' => $this->coreAllowMajor,
```

In `packages/dashboard-plugin/src/Models/Plugin.php`, add `public readonly ?string $testedUpTo = null,` as the final constructor parameter.

In `fromRow()`, add:
```php
testedUpTo: isset($row['tested_up_to']) ? (string) $row['tested_up_to'] : null,
```

In `toJson()`, add:
```php
'tested_up_to' => $this->testedUpTo,
```

In `packages/dashboard-plugin/src/Models/Theme.php`, repeat the same shape — `public readonly ?string $testedUpTo = null,` + `testedUpTo: isset($row['tested_up_to']) ? (string) $row['tested_up_to'] : null,` + `'tested_up_to' => $this->testedUpTo,`.

- [ ] **Step 4: Run tests to verify they pass**

Run:
```
cd packages/dashboard-plugin && composer test -- --filter "SiteCoreAllowMajorTest|PluginTestedUpToTest|ThemeTestedUpToTest"
```

Expected: PASS — all three test classes green.

Also run the existing model tests to verify no regressions:
```
cd packages/dashboard-plugin && composer test -- --filter "SiteTest|PluginTest|ThemeTest"
```

Expected: PASS — fields default safely so old test rows still hydrate.

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Models/Site.php \
        packages/dashboard-plugin/src/Models/Plugin.php \
        packages/dashboard-plugin/src/Models/Theme.php \
        packages/dashboard-plugin/tests/Unit/Models/SiteCoreAllowMajorTest.php \
        packages/dashboard-plugin/tests/Unit/Models/PluginTestedUpToTest.php \
        packages/dashboard-plugin/tests/Unit/Models/ThemeTestedUpToTest.php
git commit -m "feat(p2-4-1): Site.coreAllowMajor + Plugin/Theme.testedUpTo readonly fields

Three immutable model extensions surface the new schema v6 columns
to the SPA wire (toJson). Defaults preserve backward compat with
rows from older schema versions. Per spec § 4.2 + § 4.3."
```

---

## Task 8 — `SitesRepository::setCoreAllowMajor()` + `Sync*Services` persist `tested_up_to`

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/SitesRepository.php`
- Modify: `packages/dashboard-plugin/src/Services/SyncPluginsService.php`
- Modify: `packages/dashboard-plugin/src/Services/SyncThemesService.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/SitesRepositoryAllowMajorTest.php`

Repository gains a single new mutator. The sync services persist `tested_up_to` from the connector payload (null-safe — older connectors omit the field). The repository's `findById*` SELECT needs to include the new column for hydration.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SitesRepositoryAllowMajorTest extends AbstractSchemaTestCase
{
    public function testSetCoreAllowMajorPersistsTrue(): void
    {
        $siteId = $this->seedSite();
        $repo   = new SitesRepository();

        $repo->setCoreAllowMajor($siteId, true);

        $site = $repo->findById($siteId);
        $this->assertNotNull($site);
        $this->assertTrue($site->coreAllowMajor);
    }

    public function testSetCoreAllowMajorPersistsFalse(): void
    {
        $siteId = $this->seedSite();
        $repo   = new SitesRepository();

        $repo->setCoreAllowMajor($siteId, true);
        $repo->setCoreAllowMajor($siteId, false);

        $site = $repo->findById($siteId);
        $this->assertFalse($site->coreAllowMajor);
    }

    public function testFindByIdReturnsCoreAllowMajor(): void
    {
        global $wpdb;
        $siteId = $this->seedSite();
        $wpdb->update(
            $wpdb->prefix . 'defyn_sites',
            ['core_allow_major' => 1],
            ['id' => $siteId],
            ['%d'],
            ['%d']
        );

        $site = (new SitesRepository())->findById($siteId);
        $this->assertTrue($site->coreAllowMajor);
    }

    private function seedSite(): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'    => 1,
            'url'        => 'https://example.com',
            'label'      => 'Example',
            'status'     => 'connected',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $wpdb->insert_id;
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:
```
cd packages/dashboard-plugin && composer test -- --filter SitesRepositoryAllowMajorTest
```

Expected: FAIL — `Call to undefined method SitesRepository::setCoreAllowMajor`.

- [ ] **Step 3: Add the repository method + extend SELECT + extend sync services**

In `packages/dashboard-plugin/src/Services/SitesRepository.php`, append a new method:

```php
public function setCoreAllowMajor(int $siteId, bool $allow): void
{
    $this->wpdb->update(
        $this->table,
        ['core_allow_major' => $allow ? 1 : 0],
        ['id' => $siteId],
        ['%d'],
        ['%d']
    );
}
```

(`$this->table` and `$this->wpdb` should match existing private properties in the class — adjust if the property names differ.)

Extend any `SELECT` statements in `findById` / `findByIdForUser` / `findAllForUser` so the new column is fetched. If the existing SELECT uses `SELECT *`, this is automatic. If it lists columns explicitly, add `core_allow_major` to the column list.

In `packages/dashboard-plugin/src/Services/SyncPluginsService.php`, find the loop that builds rows for persistence. For each incoming plugin payload from the connector, add `tested_up_to` to the row array:

```php
'tested_up_to' => isset($incoming['tested_up_to']) && $incoming['tested_up_to'] !== ''
    ? (string) $incoming['tested_up_to']
    : null,
```

In `packages/dashboard-plugin/src/Services/SyncThemesService.php`, repeat the same `'tested_up_to' => ...` line in the row array built from each incoming theme payload.

- [ ] **Step 4: Run tests to verify they pass**

Run:
```
cd packages/dashboard-plugin && composer test -- --filter SitesRepositoryAllowMajorTest
```

Expected: PASS — all 3 tests green.

Also run sync-service tests to verify no regressions:
```
cd packages/dashboard-plugin && composer test -- --filter "SyncPluginsServiceTest|SyncThemesServiceTest"
```

Expected: PASS — older tests don't assert on `tested_up_to` and the null-safe default handles older connector payloads.

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Services/SitesRepository.php \
        packages/dashboard-plugin/src/Services/SyncPluginsService.php \
        packages/dashboard-plugin/src/Services/SyncThemesService.php \
        packages/dashboard-plugin/tests/Integration/Services/SitesRepositoryAllowMajorTest.php
git commit -m "feat(p2-4-1): repository + sync services persist new v6 columns

SitesRepository::setCoreAllowMajor() mutator + extended SELECT to
hydrate the new column on findById. SyncPluginsService and
SyncThemesService persist tested_up_to from connector payload
(null-safe -- older connectors omit the field). Per spec § 4.4 + § 4.5."
```

---

## Task 9 — `SitesCoreUpdateController` preflight relaxation + `UpdateSiteCore` job body extension

**Files:**
- Modify: `packages/dashboard-plugin/src/Rest/SitesCoreUpdateController.php`
- Modify: `packages/dashboard-plugin/src/Jobs/UpdateSiteCore.php`
- Test: `packages/dashboard-plugin/tests/Integration/Rest/SitesCoreUpdateMajorRelaxTest.php`
- Test: `packages/dashboard-plugin/tests/Integration/Jobs/UpdateSiteCoreAllowMajorTest.php`

Preflight #4 in `SitesCoreUpdateController` currently rejects any major bump unconditionally. P2.4.1 relaxes it: only block when `!isMinor && !$site->coreAllowMajor`. The `UpdateSiteCore` AS job threads `allow_major` from the site row into the connector body and into the `core_update.started` activity event.

- [ ] **Step 1: Write the failing tests**

Create `packages/dashboard-plugin/tests/Integration/Rest/SitesCoreUpdateMajorRelaxTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

final class SitesCoreUpdateMajorRelaxTest extends AbstractSchemaTestCase
{
    public function testMajorBumpProceedsWhenAllowMajorFlagIsOn(): void
    {
        $siteId = $this->seedSiteWithMajorUpdate();
        (new SitesRepository())->setCoreAllowMajor($siteId, true);

        $token   = $this->issueAccessTokenForUser(1);
        $request = new WP_REST_Request('POST', '/defyn/v1/sites/' . $siteId . '/core/update');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $request->set_param('id', $siteId);
        $request->set_param('_authenticated_user_id', 1);

        $response = rest_do_request($request);

        $this->assertSame(202, $response->get_status());
        $this->assertTrue($response->get_data()['scheduled']);
    }

    public function testMajorBumpStillReturns409WhenFlagIsOff(): void
    {
        $siteId = $this->seedSiteWithMajorUpdate();
        // Flag is OFF by default -- no setCoreAllowMajor call.

        $token   = $this->issueAccessTokenForUser(1);
        $request = new WP_REST_Request('POST', '/defyn/v1/sites/' . $siteId . '/core/update');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $request->set_param('id', $siteId);
        $request->set_param('_authenticated_user_id', 1);

        $response = rest_do_request($request);

        $this->assertSame(409, $response->get_status());
        $this->assertSame('core.major_update_blocked', $response->get_data()['error']['code']);
    }

    private function seedSiteWithMajorUpdate(): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'                 => 1,
            'url'                     => 'https://example.com',
            'label'                   => 'Example',
            'status'                  => 'connected',
            'wp_version'              => '7.4',
            'core_update_available'   => 1,
            'core_update_version'     => '8.0',
            'core_update_state'       => 'idle',
            'created_at'              => gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $wpdb->insert_id;
    }

    private function issueAccessTokenForUser(int $userId): string
    {
        return (new \Defyn\Dashboard\Auth\TokenService())->issueAccess($userId);
    }
}
```

Create `packages/dashboard-plugin/tests/Integration/Jobs/UpdateSiteCoreAllowMajorTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Jobs;

use Defyn\Dashboard\Jobs\UpdateSiteCore;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class UpdateSiteCoreAllowMajorTest extends AbstractSchemaTestCase
{
    public function testJobPassesAllowMajorTrueWhenFlagIsOn(): void
    {
        $siteId = $this->seedSite();
        (new SitesRepository())->setCoreAllowMajor($siteId, true);

        $capturedBody = null;
        $mock = new MockHttpClient(function ($method, $url, $options) use (&$capturedBody) {
            $capturedBody = json_decode($options['body'] ?? '{}', true);
            return new MockResponse(json_encode([
                'success' => true,
                'previous_version' => '7.4',
                'new_version' => '8.0',
                'server_time' => time(),
            ]), ['http_code' => 200]);
        });

        $job = new UpdateSiteCore($mock);
        $job->handle($siteId, 0);

        $this->assertIsArray($capturedBody);
        $this->assertArrayHasKey('allow_major', $capturedBody);
        $this->assertTrue($capturedBody['allow_major']);
    }

    public function testJobPassesAllowMajorFalseWhenFlagIsOff(): void
    {
        $siteId = $this->seedSite();
        // Flag stays off (default).

        $capturedBody = null;
        $mock = new MockHttpClient(function ($method, $url, $options) use (&$capturedBody) {
            $capturedBody = json_decode($options['body'] ?? '{}', true);
            return new MockResponse(json_encode([
                'success' => true,
                'previous_version' => '7.4',
                'new_version' => '7.4.1',
                'server_time' => time(),
            ]), ['http_code' => 200]);
        });

        $job = new UpdateSiteCore($mock);
        $job->handle($siteId, 0);

        $this->assertIsArray($capturedBody);
        $this->assertFalse($capturedBody['allow_major']);
    }

    public function testStartedActivityEventIncludesAllowMajor(): void
    {
        $siteId = $this->seedSite();
        (new SitesRepository())->setCoreAllowMajor($siteId, true);

        $mock = new MockHttpClient(static fn() => new MockResponse(json_encode([
            'success' => true,
            'previous_version' => '7.4',
            'new_version' => '8.0',
            'server_time' => time(),
        ]), ['http_code' => 200]));

        $job = new UpdateSiteCore($mock);
        $job->handle($siteId, 0);

        global $wpdb;
        $activity = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}defyn_activity_log
                 WHERE site_id = %d AND event_type = 'core_update.started'
                 ORDER BY id DESC LIMIT 1",
                $siteId
            ),
            ARRAY_A
        );
        $this->assertNotNull($activity);
        $details = json_decode($activity['details'] ?? '{}', true);
        $this->assertArrayHasKey('allow_major', $details);
        $this->assertTrue($details['allow_major']);
    }

    private function seedSite(): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'                 => 1,
            'url'                     => 'https://example.com',
            'label'                   => 'Example',
            'status'                  => 'connected',
            'wp_version'              => '7.4',
            'our_private_key'         => 'stub',
            'site_public_key'         => 'stub',
            'core_update_available'   => 1,
            'core_update_version'     => '8.0',
            'core_update_state'       => 'queued',
            'created_at'              => gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $wpdb->insert_id;
    }
}
```

(The `UpdateSiteCore` constructor signature should match the existing class — adjust the constructor arg to whatever shape the P2.4 implementation uses for HTTP client injection.)

- [ ] **Step 2: Run tests to verify they fail**

Run:
```
cd packages/dashboard-plugin && composer test -- --filter "SitesCoreUpdateMajorRelaxTest|UpdateSiteCoreAllowMajorTest"
```

Expected: FAIL — `testMajorBumpProceedsWhenAllowMajorFlagIsOn` returns 409 because the controller doesn't yet respect the flag.

- [ ] **Step 3: Modify the controller + job**

In `packages/dashboard-plugin/src/Rest/SitesCoreUpdateController.php`, find lines 48-60 (the preflight #4 check):

```php
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
```

Replace with:
```php
$currentVersion = (string) ($site->wpVersion ?? '');
$targetVersion  = (string) ($site->coreUpdateVersion ?? '');
if (!self::isMinorUpgrade($currentVersion, $targetVersion) && !$site->coreAllowMajor) {
    return ErrorResponse::create(
        409,
        'core.major_update_blocked',
        'Major WordPress version upgrades require enabling major updates for this site first.',
    );
}
```

Note the change: AND, not OR. AND blocks only when BOTH conditions hold (it's a major AND the flag is off).

In `packages/dashboard-plugin/src/Jobs/UpdateSiteCore.php`, find the body construction (a `$body = [...]` array passed to `signedPostJson`). Add `'allow_major' => $site->coreAllowMajor` to the array:

```php
$body = [
    'target_version' => $targetVersion,
    'allow_major'    => $site->coreAllowMajor,
];
```

Find the `ActivityLogger::log()` call for `core_update.started`. Add `allow_major` to the details array:

```php
(new ActivityLogger())->log(null, $siteId, 'core_update.started', [
    'previous_version' => $site->wpVersion,
    'target_version'   => $targetVersion,
    'attempt'          => $attempt,
    'allow_major'      => $site->coreAllowMajor,
]);
```

(If the existing log call has additional fields, preserve them — just append `'allow_major' => $site->coreAllowMajor` to the details array.)

- [ ] **Step 4: Run tests to verify they pass**

Run:
```
cd packages/dashboard-plugin && composer test -- --filter "SitesCoreUpdateMajorRelaxTest|UpdateSiteCoreAllowMajorTest"
```

Expected: PASS — all 5 tests green.

Also run the existing `SitesCoreUpdateTest` and `UpdateSiteCoreTest` baselines:
```
cd packages/dashboard-plugin && composer test -- --filter "SitesCoreUpdateTest|UpdateSiteCoreTest"
```

Expected: PASS — existing tests use minor-bump fixtures (the relaxed check is equivalent for minor) and the body extension is additive.

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Rest/SitesCoreUpdateController.php \
        packages/dashboard-plugin/src/Jobs/UpdateSiteCore.php \
        packages/dashboard-plugin/tests/Integration/Rest/SitesCoreUpdateMajorRelaxTest.php \
        packages/dashboard-plugin/tests/Integration/Jobs/UpdateSiteCoreAllowMajorTest.php
git commit -m "feat(p2-4-1): preflight + job respect per-site allow_major flag

SitesCoreUpdateController preflight #4 now relaxes (AND, not OR) when
the site's coreAllowMajor flag is on. UpdateSiteCore job threads
allow_major into the connector body and into the core_update.started
activity event payload for audit trails. Per spec § 4.6 + § 4.9."
```

---

## Task 10 — `SitesCoreAllowMajorController` + `RateLimit::coreAllowMajor` (10/hr) + route registration

**Files:**
- Create: `packages/dashboard-plugin/src/Rest/SitesCoreAllowMajorController.php`
- Modify: `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php`
- Modify: `packages/dashboard-plugin/src/Plugin.php`
- Test: `packages/dashboard-plugin/tests/Integration/Rest/SitesCoreAllowMajorTest.php`

New endpoint `POST /defyn/v1/sites/{id}/core/allow-major` toggles the flag. Body: `{"allow": true|false}`. Rate-limited at 10/hr per (user, site). Activity-logs `core_allow_major.toggled`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

final class SitesCoreAllowMajorTest extends AbstractSchemaTestCase
{
    public function testHappyPath200OnEnable(): void
    {
        $siteId = $this->seedSite();
        $token  = $this->token();

        $request  = $this->buildRequest($siteId, ['allow' => true], $token);
        $response = rest_do_request($request);

        $this->assertSame(200, $response->get_status());
        $body = $response->get_data();
        $this->assertSame($siteId, $body['site_id']);
        $this->assertTrue($body['core_allow_major']);
    }

    public function testHappyPath200OnDisable(): void
    {
        $siteId = $this->seedSite();
        $token  = $this->token();

        rest_do_request($this->buildRequest($siteId, ['allow' => true], $token));
        $response = rest_do_request($this->buildRequest($siteId, ['allow' => false], $token));

        $this->assertSame(200, $response->get_status());
        $this->assertFalse($response->get_data()['core_allow_major']);
    }

    public function testNotOwnedReturns404(): void
    {
        $siteId = $this->seedSite(/*userId=*/ 2);
        $token  = $this->token(1);

        $response = rest_do_request($this->buildRequest($siteId, ['allow' => true], $token));

        $this->assertSame(404, $response->get_status());
        $this->assertSame('sites.not_found', $response->get_data()['error']['code']);
    }

    public function testInvalidPayloadReturns400(): void
    {
        $siteId = $this->seedSite();
        $token  = $this->token();

        // Missing allow key.
        $response = rest_do_request($this->buildRequest($siteId, ['something_else' => 'value'], $token));
        $this->assertSame(400, $response->get_status());

        // Non-bool value.
        $response2 = rest_do_request($this->buildRequest($siteId, ['allow' => 'yes'], $token));
        $this->assertSame(400, $response2->get_status());
    }

    public function testRateLimit429AfterEleventhCall(): void
    {
        $siteId = $this->seedSite();
        $token  = $this->token();

        // 10 successful calls.
        for ($i = 0; $i < 10; $i++) {
            $resp = rest_do_request($this->buildRequest($siteId, ['allow' => ($i % 2 === 0)], $token));
            $this->assertSame(200, $resp->get_status(), "call #" . ($i + 1) . " should be 200");
        }

        // 11th call must be 429.
        $resp = rest_do_request($this->buildRequest($siteId, ['allow' => true], $token));
        $this->assertSame(429, $resp->get_status());
        $this->assertSame('core.rate_limited', $resp->get_data()['error']['code']);
    }

    public function testActivityLogEventEmitted(): void
    {
        global $wpdb;
        $siteId = $this->seedSite();
        $token  = $this->token();

        rest_do_request($this->buildRequest($siteId, ['allow' => true], $token));

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}defyn_activity_log
             WHERE site_id = %d AND event_type = %s
             ORDER BY id DESC LIMIT 1",
            $siteId,
            'core_allow_major.toggled'
        ), ARRAY_A);

        $this->assertNotNull($row);
        $details = json_decode($row['details'] ?? '{}', true);
        $this->assertTrue($details['enabled']);
    }

    private function seedSite(int $userId = 1): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'    => $userId,
            'url'        => 'https://example.com',
            'label'      => 'Example',
            'status'     => 'connected',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $wpdb->insert_id;
    }

    private function token(int $userId = 1): string
    {
        return (new TokenService())->issueAccess($userId);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function buildRequest(int $siteId, array $body, string $token): WP_REST_Request
    {
        $req = new WP_REST_Request('POST', '/defyn/v1/sites/' . $siteId . '/core/allow-major');
        $req->set_header('Authorization', 'Bearer ' . $token);
        $req->set_header('Content-Type', 'application/json');
        $req->set_body(json_encode($body));
        $req->set_param('id', $siteId);
        return $req;
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:
```
cd packages/dashboard-plugin && composer test -- --filter SitesCoreAllowMajorTest
```

Expected: FAIL — `rest_no_route` because the endpoint isn't registered.

- [ ] **Step 3: Create the controller + rate limit + route registration**

Create `packages/dashboard-plugin/src/Rest/SitesCoreAllowMajorController.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P2.4.1 — POST /defyn/v1/sites/{id}/core/allow-major.
 *
 * Toggles the per-site core_allow_major flag. When on, major-version
 * WordPress core upgrades become eligible (the dashboard's preflight #4
 * and the connector's CoreUpgraderService respect the flag).
 *
 * Spec: docs/superpowers/specs/2026-06-07-p2-4-1-major-core-updates-design.md § 4.7
 */
final class SitesCoreAllowMajorController
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

        $body = $request->get_json_params() ?: [];
        if (!array_key_exists('allow', $body) || !is_bool($body['allow'])) {
            return ErrorResponse::create(
                400,
                'core.invalid_payload',
                'Request body must include an "allow" field with a boolean value.',
            );
        }
        $allow = (bool) $body['allow'];

        $repo->setCoreAllowMajor($siteId, $allow);

        (new ActivityLogger())->log($userId, $siteId, 'core_allow_major.toggled', [
            'enabled' => $allow,
        ]);

        return new WP_REST_Response([
            'site_id'          => $siteId,
            'core_allow_major' => $allow,
        ], 200);
    }
}
```

In `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php`, after the `CORE_UPDATE_*` constants block, add:

```php
// P2.4.1 — toggle for per-site allow_major flag. Separate bucket from
// sitesCoreRefresh/coreUpdate per spec § 4.8 -- toggling is a cheap
// metadata write, not an upgrade, so the limit is looser (10/hour vs.
// 3/hour for actual upgrades). Bucket is per-(user, site).
public const CORE_ALLOW_MAJOR_LIMIT  = 10;
public const CORE_ALLOW_MAJOR_WINDOW = HOUR_IN_SECONDS;
```

And after the existing `coreUpdate()` method, append:

```php
/**
 * Permission callback for POST /sites/{id}/core/allow-major.
 *
 * Separate transient-bucket from coreUpdate per spec § 4.8 -- toggling
 * is cheap (a single column write) so the limit is looser (10/hour vs.
 * 3/hour for actual upgrades). Same auth-chain pattern as coreUpdate.
 *
 * @return true|WP_Error
 */
public static function coreAllowMajor(WP_REST_Request $request)
{
    $authResult = RequireAuth::check($request);
    if (is_wp_error($authResult)) {
        return $authResult;
    }

    $userId = (int) $request->get_param('_authenticated_user_id');
    $siteId = (int) $request['id'];

    $key   = sprintf('defyn_rl_coreAllowMajor_%d_%d', $userId, $siteId);
    $count = (int) (get_transient($key) ?: 0);

    if ($count >= self::CORE_ALLOW_MAJOR_LIMIT) {
        return new \WP_Error(
            'core.rate_limited',
            'Too many setting changes. Try again in an hour.',
            ['status' => 429]
        );
    }

    set_transient($key, $count + 1, self::CORE_ALLOW_MAJOR_WINDOW);
    return true;
}
```

In `packages/dashboard-plugin/src/Plugin.php` (or wherever REST routes are registered — search for `register_rest_route` calls or look in `Rest/RestRouter.php`), add a route registration alongside the existing `core/update` route:

```php
register_rest_route('defyn/v1', '/sites/(?P<id>\d+)/core/allow-major', [
    'methods'             => 'POST',
    'callback'            => [(new SitesCoreAllowMajorController()), 'handle'],
    'permission_callback' => ['Defyn\Dashboard\Rest\Middleware\RateLimit', 'coreAllowMajor'],
]);
```

(Adjust the registration pattern to match how other dashboard routes are wired in this codebase — `RestRouter` may use a static registration loop with route metadata arrays.)

- [ ] **Step 4: Run tests to verify they pass**

Run:
```
cd packages/dashboard-plugin && composer test -- --filter SitesCoreAllowMajorTest
```

Expected: PASS — all 6 tests green.

Also run the broader rate-limit and route-registration tests to verify no regressions:
```
cd packages/dashboard-plugin && composer test -- --filter "RateLimitTest|RestRouterTest"
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Rest/SitesCoreAllowMajorController.php \
        packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php \
        packages/dashboard-plugin/src/Plugin.php \
        packages/dashboard-plugin/tests/Integration/Rest/SitesCoreAllowMajorTest.php
git commit -m "feat(p2-4-1): POST /sites/{id}/core/allow-major endpoint + 10/hr bucket

New controller toggles the per-site core_allow_major flag with
ownership + payload validation + 10/hr rate limit + activity logging
(core_allow_major.toggled with enabled:bool detail). Separate bucket
from coreUpdate's 3/hr -- toggling is a cheap metadata write.
Per spec § 4.7 + § 4.8."
```

---

## Task 11 — Dashboard v0.6.0 release bump + CORS regression

**Files:**
- Modify: `packages/dashboard-plugin/defyn-dashboard.php`
- Modify: `packages/dashboard-plugin/readme.txt`
- Modify: `packages/dashboard-plugin/composer.json`
- Test: `packages/dashboard-plugin/tests/Integration/Rest/SitesCoreAllowMajorCorsTest.php`

CORS regression confirms the new `/core/allow-major` route returns the standard CORS headers for SPA cross-origin POST.

- [ ] **Step 1: Write the CORS regression test**

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

final class SitesCoreAllowMajorCorsTest extends AbstractSchemaTestCase
{
    public function testOptionsPreflightOnCoreAllowMajorRouteReturnsCorsHeaders(): void
    {
        $siteId = $this->seedSite();

        $request = new WP_REST_Request('OPTIONS', '/defyn/v1/sites/' . $siteId . '/core/allow-major');
        $request->set_header('Origin', 'https://app.defynwp.defyn.agency');
        $request->set_header('Access-Control-Request-Method', 'POST');
        $request->set_header('Access-Control-Request-Headers', 'authorization,content-type');

        $response = rest_do_request($request);
        // The rest_post_dispatch filter (where CORS headers are added) skips
        // rest_do_request's pipeline -- invoke it manually for assertion.
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

    private function seedSite(): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'    => 1,
            'url'        => 'https://example.com',
            'label'      => 'Example',
            'status'     => 'connected',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $wpdb->insert_id;
    }
}
```

- [ ] **Step 2: Run test to verify it passes**

Run:
```
cd packages/dashboard-plugin && composer test -- --filter SitesCoreAllowMajorCorsTest
```

Expected: PASS — the dashboard's CORS filter applies to ALL `defyn/v1` namespaced routes (the route registration in Task 10 gave it CORS automatically). This test is a regression guard against future accidental narrowing of the CORS filter.

(If the test FAILS, the CORS filter likely requires the new route to be explicitly listed somewhere. Inspect the existing CORS filter implementation and add the new route to whatever allowlist mechanism it uses.)

- [ ] **Step 3: Bump version constants**

In `packages/dashboard-plugin/defyn-dashboard.php`, change the plugin header `Version: 0.5.0` to `Version: 0.6.0`. Also update any `DEFYN_DASHBOARD_VERSION` constant from `'0.5.0'` to `'0.6.0'`.

In `packages/dashboard-plugin/composer.json`, change `"version": "0.5.0"` to `"version": "0.6.0"`.

In `packages/dashboard-plugin/readme.txt`, update `Stable tag: 0.5.0` to `Stable tag: 0.6.0` and prepend:

```
= 0.6.0 =
* Per-site opt-in for major WordPress version upgrades via /sites/{id}/core/allow-major.
* Schema v6: core_allow_major on sites table + tested_up_to on plugins/themes tables.
* UpdateSiteCore job threads allow_major flag to connector on upgrade requests.
```

- [ ] **Step 4: Run all dashboard tests**

Run:
```
cd packages/dashboard-plugin && composer test
```

Expected: ALL PASS (Tasks 6-11 + every P2.x suite).

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/defyn-dashboard.php \
        packages/dashboard-plugin/readme.txt \
        packages/dashboard-plugin/composer.json \
        packages/dashboard-plugin/tests/Integration/Rest/SitesCoreAllowMajorCorsTest.php
git commit -m "chore(p2-4-1): dashboard v0.6.0 release bump + CORS regression

Bumps plugin version to v0.6.0 and adds a CORS preflight regression
test for the new /sites/{id}/core/allow-major route."
```

---

## Task 12 — SPA Zod extensions + MSW handlers + `useToggleCoreAllowMajor` hook

**Files:**
- Modify: `apps/web/src/types/api.ts`
- Modify: `apps/web/src/test/handlers.ts`
- Create: `apps/web/src/lib/mutations/useToggleCoreAllowMajor.ts`
- Test: `apps/web/src/lib/mutations/useToggleCoreAllowMajor.test.tsx`
- Test: extend `apps/web/src/types/api.test.ts` (or create if absent)

`siteSchema` gains `core_allow_major: z.boolean()`. `pluginSchema` and `themeSchema` gain `tested_up_to: z.string().nullable()`. MSW handlers for the new endpoint. Mutation hook with TanStack Query.

- [ ] **Step 1: Write the failing tests**

Read `apps/web/src/types/api.test.ts` first to see the exact required-field fixture shapes used in existing siteSchema/pluginSchema/themeSchema tests. Then append, copying the existing minimal-valid fixture pattern and adding the new fields:

```ts
import { describe, it, expect } from 'vitest'
import { siteSchema, pluginSchema, themeSchema } from './api'

describe('siteSchema with core_allow_major', () => {
  it('parses core_allow_major boolean', () => {
    const valid = {
      // Copy the exact required fields used in the existing siteSchema test
      // (do NOT make up field names -- read the file). Add:
      core_allow_major: true,
    }
    expect(() => siteSchema.parse(valid)).not.toThrow()
  })

  it('rejects siteSchema parse when core_allow_major is missing', () => {
    const invalid = {
      // Same required fields, WITHOUT core_allow_major.
    }
    expect(() => siteSchema.parse(invalid)).toThrow()
  })
})

describe('pluginSchema with tested_up_to', () => {
  it('parses tested_up_to as string', () => {
    const withValue = {
      // existing required + 
      tested_up_to: '6.4',
    }
    expect(() => pluginSchema.parse(withValue)).not.toThrow()
  })

  it('parses tested_up_to as null', () => {
    const withNull = {
      // existing required +
      tested_up_to: null,
    }
    expect(() => pluginSchema.parse(withNull)).not.toThrow()
  })
})

describe('themeSchema with tested_up_to', () => {
  it('parses tested_up_to as string', () => {
    const withValue = {
      // existing required +
      tested_up_to: '6.4',
    }
    expect(() => themeSchema.parse(withValue)).not.toThrow()
  })

  it('parses tested_up_to as null', () => {
    const withNull = {
      // existing required +
      tested_up_to: null,
    }
    expect(() => themeSchema.parse(withNull)).not.toThrow()
  })
})
```

(The "Copy the exact required fields used in the existing siteSchema test" guidance is explicit — read `api.test.ts` first and lift the fixture shape exactly. Do NOT invent field names.)

Create `apps/web/src/lib/mutations/useToggleCoreAllowMajor.test.tsx`:

```tsx
import { describe, it, expect, vi } from 'vitest'
import { renderHook, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { http, HttpResponse } from 'msw'
import { server } from '@/test/server'
import { useToggleCoreAllowMajor } from './useToggleCoreAllowMajor'
import React from 'react'

function wrapper({ children }: { children: React.ReactNode }) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>
}

describe('useToggleCoreAllowMajor', () => {
  it('posts allow:true to /sites/:id/core/allow-major and returns new state', async () => {
    let capturedBody: unknown = null
    server.use(
      http.post('*/sites/:id/core/allow-major', async ({ request, params }) => {
        capturedBody = await request.json()
        return HttpResponse.json({
          site_id: Number(params.id),
          core_allow_major: true,
        })
      })
    )

    const { result } = renderHook(() => useToggleCoreAllowMajor(42), { wrapper })
    result.current.mutate(true)

    await waitFor(() => expect(result.current.isSuccess).toBe(true))
    expect(capturedBody).toEqual({ allow: true })
    expect(result.current.data).toEqual({ site_id: 42, core_allow_major: true })
  })

  it('invalidates the site query on success', async () => {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    qc.setQueryData(['sites', 42], { id: 42, core_allow_major: false })

    server.use(
      http.post('*/sites/:id/core/allow-major', () =>
        HttpResponse.json({ site_id: 42, core_allow_major: true })
      )
    )

    const customWrapper = ({ children }: { children: React.ReactNode }) => (
      <QueryClientProvider client={qc}>{children}</QueryClientProvider>
    )
    const { result } = renderHook(() => useToggleCoreAllowMajor(42), { wrapper: customWrapper })
    result.current.mutate(true)

    await waitFor(() => expect(result.current.isSuccess).toBe(true))
    expect(qc.getQueryState(['sites', 42])?.isInvalidated).toBe(true)
  })

  it('surfaces 429 errors so caller can show a toast', async () => {
    server.use(
      http.post('*/sites/:id/core/allow-major', () =>
        HttpResponse.json(
          { error: { code: 'core.rate_limited', message: 'Too many.' } },
          { status: 429 }
        )
      )
    )

    const { result } = renderHook(() => useToggleCoreAllowMajor(42), { wrapper })
    result.current.mutate(true)

    await waitFor(() => expect(result.current.isError).toBe(true))
  })
})
```

- [ ] **Step 2: Run tests to verify they fail**

Run:
```
cd apps/web && pnpm test -- --run useToggleCoreAllowMajor
cd apps/web && pnpm test -- --run api.test
```

Expected: FAIL — `useToggleCoreAllowMajor` doesn't exist, and the schema parses reject `tested_up_to` / `core_allow_major`.

- [ ] **Step 3: Extend Zod schemas + handlers + create the hook**

In `apps/web/src/types/api.ts`, find `siteSchema` and add (place adjacent to the existing `core_update_*` fields, matching the toJson contract from Task 7):
```ts
core_allow_major: z.boolean(),
```

Find `pluginSchema` and add:
```ts
tested_up_to: z.string().nullable(),
```

Find `themeSchema` and add the same line.

Also export a tiny response schema used by the new mutation:
```ts
export const coreAllowMajorResponseSchema = z.object({
  site_id: z.number().int(),
  core_allow_major: z.boolean(),
})
export type CoreAllowMajorResponse = z.infer<typeof coreAllowMajorResponseSchema>
```

In `apps/web/src/test/handlers.ts`, add an MSW handler for the new endpoint:
```ts
http.post('*/sites/:id/core/allow-major', async ({ request, params }) => {
  const body = (await request.json()) as { allow?: boolean }
  return HttpResponse.json({
    site_id: Number(params.id),
    core_allow_major: body.allow === true,
  })
}),
```

Extend the existing `GET /sites/:id` handler so its mocked response includes `core_allow_major: false` and the plugin/theme list handlers include `tested_up_to: null` (to keep existing tests happy with the new required fields).

Create `apps/web/src/lib/mutations/useToggleCoreAllowMajor.ts`:

```ts
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { coreAllowMajorResponseSchema, type CoreAllowMajorResponse } from '@/types/api'

export function useToggleCoreAllowMajor(siteId: number) {
  const qc = useQueryClient()
  return useMutation<CoreAllowMajorResponse, Error, boolean>({
    mutationFn: async (allow: boolean) => {
      const res = await api.post(`/sites/${siteId}/core/allow-major`, { allow })
      return coreAllowMajorResponseSchema.parse(res.data)
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['sites', siteId] })
    },
  })
}
```

(Adjust the `api` import to match this codebase's existing HTTP client — likely `@/lib/api` or a re-exported axios/fetch wrapper. Read another mutation hook file like `useRefreshSiteCore.ts` for the canonical import pattern.)

- [ ] **Step 4: Run tests to verify they pass**

Run:
```
cd apps/web && pnpm test -- --run useToggleCoreAllowMajor
cd apps/web && pnpm test -- --run api.test
cd apps/web && pnpm test -- --run
```

Expected: PASS — schema tests + mutation hook tests green; existing SPA suite still green (because MSW handlers were extended to include the new fields with safe defaults).

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/types/api.ts \
        apps/web/src/test/handlers.ts \
        apps/web/src/lib/mutations/useToggleCoreAllowMajor.ts \
        apps/web/src/lib/mutations/useToggleCoreAllowMajor.test.tsx \
        apps/web/src/types/api.test.ts
git commit -m "feat(p2-4-1): Zod extensions + MSW + useToggleCoreAllowMajor hook

siteSchema gains core_allow_major:boolean, plugin/theme schemas gain
tested_up_to:string|null. MSW handlers updated to emit the new fields
with safe defaults so existing tests stay green. New mutation hook
invalidates the site query on success and surfaces 429 errors for
the caller to toast. Per spec § 5.1, § 5.4, § 5.7."
```

---

## Task 13 — SiteCoreCard 5th state + SiteMajorUpdatesSettingsRow + ConfirmUpdateCoreDialog major variant + SiteDetail integration

**Files:**
- Modify: `apps/web/src/components/sites/SiteCoreCard.tsx`
- Modify: `apps/web/src/components/sites/SiteCoreCard.test.tsx`
- Create: `apps/web/src/components/sites/SiteMajorUpdatesSettingsRow.tsx`
- Test: `apps/web/src/components/sites/SiteMajorUpdatesSettingsRow.test.tsx`
- Modify: `apps/web/src/components/sites/ConfirmUpdateCoreDialog.tsx`
- Modify: `apps/web/src/components/sites/ConfirmUpdateCoreDialog.test.tsx`
- Modify: `apps/web/src/routes/SiteDetail.tsx`

The largest single SPA task — three component changes wired into the route.

### 13.1 — `SiteCoreCard` 5th visual state

- [ ] **Step 1: Write failing tests for the 5th + 5a states**

Append to `apps/web/src/components/sites/SiteCoreCard.test.tsx`:

```tsx
import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import { SiteCoreCard } from './SiteCoreCard'

describe('SiteCoreCard major-update states', () => {
  const baseSite = {
    id: 1,
    wp_version: '7.4',
    core_update_available: true,
    core_update_version: '8.0',
    core_update_state: 'idle' as const,
    last_core_update_error: null,
    last_core_update_attempt_at: null,
    is_minor_update: false, // major bump
    core_allow_major: false,
    is_minor_auto_update_enabled: false,
  }

  it('renders blocked-major-available state when flag is off and update is major', () => {
    render(<SiteCoreCard site={baseSite as any} />)
    expect(screen.getByText(/Major update available/i)).toBeInTheDocument()
    expect(screen.getByText(/disabled for this site/i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Manage settings/i })).toBeInTheDocument()
    // The "Update" button must NOT exist in this state.
    expect(screen.queryByRole('button', { name: /^Update/i })).not.toBeInTheDocument()
  })

  it('renders allowed-major-available state when flag is on and update is major', () => {
    render(<SiteCoreCard site={{ ...baseSite, core_allow_major: true } as any} />)
    expect(screen.getByText(/Major update available/i)).toBeInTheDocument()
    const updateBtn = screen.getByRole('button', { name: /^Update/i })
    expect(updateBtn).toHaveClass('bg-red-600')
  })
})
```

- [ ] **Step 2: Modify `SiteCoreCard.tsx`**

In `apps/web/src/components/sites/SiteCoreCard.tsx`, the existing state-determination logic likely uses a series of conditional renders. Add the new derived state:

```tsx
const isMajor         = site.core_update_available && site.is_minor_update === false
const isBlockedMajor  = isMajor && !site.core_allow_major
const isAllowedMajor  = isMajor && site.core_allow_major
```

Add a branch for `isBlockedMajor` (renders header + subhead + "Manage settings" ghost button), and a variant of the existing update-available branch for `isAllowedMajor` (red-tier chrome + red Update button).

Sketch (final JSX/CSS classes should match the existing component idiom — reuse the same Card scaffolding the file already uses for state 2):

```tsx
if (isBlockedMajor) {
  return (
    <Card className="border-amber-200 bg-amber-50">
      <CardHeader>
        <CardTitle>Major update available — WordPress {site.core_update_version}</CardTitle>
        <CardDescription>Major upgrades are disabled for this site</CardDescription>
      </CardHeader>
      <CardFooter>
        <Button variant="ghost" onClick={() => scrollToMajorSettings()}>
          Manage settings
        </Button>
      </CardFooter>
    </Card>
  )
}

if (isAllowedMajor) {
  return (
    <Card className="border-red-200 bg-red-50">
      <CardHeader>
        <CardTitle>Major update available — WordPress {site.core_update_version}</CardTitle>
        <CardDescription className="text-red-700">
          Major upgrade — review compatibility before proceeding
        </CardDescription>
      </CardHeader>
      <CardFooter>
        <Button className="bg-red-600 hover:bg-red-700" onClick={() => setDialogOpen(true)}>
          Update WordPress
        </Button>
      </CardFooter>
    </Card>
  )
}
// existing minor / no-update / updating / failed branches stay unchanged
```

The `scrollToMajorSettings` helper can be inlined as a `document.getElementById('major-updates-settings')?.scrollIntoView({ behavior: 'smooth' })` call. The `SiteMajorUpdatesSettingsRow` component will render with `id="major-updates-settings"` to receive the scroll target.

- [ ] **Step 3: Run tests to verify they pass**

Run:
```
cd apps/web && pnpm test -- --run SiteCoreCard
```

Expected: PASS — new tests green, existing 4-state tests still pass.

### 13.2 — `SiteMajorUpdatesSettingsRow`

- [ ] **Step 4: Write failing tests**

Create `apps/web/src/components/sites/SiteMajorUpdatesSettingsRow.test.tsx`:

```tsx
import { describe, it, expect, vi } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import React from 'react'
import { SiteMajorUpdatesSettingsRow } from './SiteMajorUpdatesSettingsRow'

function withClient(children: React.ReactNode) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>
}

vi.mock('@/lib/mutations/useToggleCoreAllowMajor', () => ({
  useToggleCoreAllowMajor: vi.fn(() => ({ mutate: vi.fn(), isPending: false })),
}))

import { useToggleCoreAllowMajor } from '@/lib/mutations/useToggleCoreAllowMajor'

describe('SiteMajorUpdatesSettingsRow', () => {
  it('renders switch off when site.core_allow_major is false', () => {
    render(withClient(<SiteMajorUpdatesSettingsRow site={{ id: 1, core_allow_major: false } as any} />))
    const sw = screen.getByRole('switch')
    expect(sw).not.toBeChecked()
  })

  it('renders switch on when site.core_allow_major is true', () => {
    render(withClient(<SiteMajorUpdatesSettingsRow site={{ id: 1, core_allow_major: true } as any} />))
    const sw = screen.getByRole('switch')
    expect(sw).toBeChecked()
  })

  it('calls toggle mutation with new value when clicked', () => {
    const mockMutate = vi.fn()
    ;(useToggleCoreAllowMajor as any).mockReturnValue({ mutate: mockMutate, isPending: false })
    render(withClient(<SiteMajorUpdatesSettingsRow site={{ id: 1, core_allow_major: false } as any} />))
    fireEvent.click(screen.getByRole('switch'))
    expect(mockMutate).toHaveBeenCalledWith(true)
  })
})
```

- [ ] **Step 5: Create the component**

Create `apps/web/src/components/sites/SiteMajorUpdatesSettingsRow.tsx`:

```tsx
import { Switch } from '@/components/ui/switch'
import { useToggleCoreAllowMajor } from '@/lib/mutations/useToggleCoreAllowMajor'
import type { Site } from '@/types/api'

export function SiteMajorUpdatesSettingsRow({ site }: { site: Site }) {
  const toggle = useToggleCoreAllowMajor(site.id)

  return (
    <div
      id="major-updates-settings"
      className="flex items-start gap-4 rounded-md border p-4"
    >
      <Switch
        checked={site.core_allow_major}
        disabled={toggle.isPending}
        onCheckedChange={(checked) => toggle.mutate(checked)}
        aria-label="Allow major WordPress upgrades"
      />
      <div className="flex-1">
        <p className="font-medium">Allow major WordPress upgrades for this site</p>
        <p className="text-sm text-muted-foreground">
          When off (default), only minor updates are eligible. When on, you can
          install major versions but compatibility is your responsibility.
        </p>
      </div>
    </div>
  )
}
```

(Match the shadcn/ui Switch + Card scaffolding already used elsewhere in the codebase — search for `<Switch` references for the canonical pattern.)

- [ ] **Step 6: Run tests to verify they pass**

Run:
```
cd apps/web && pnpm test -- --run SiteMajorUpdatesSettingsRow
```

Expected: PASS — all 3 tests green.

### 13.3 — `ConfirmUpdateCoreDialog` major variant

- [ ] **Step 7: Write failing tests**

Append to `apps/web/src/components/sites/ConfirmUpdateCoreDialog.test.tsx`:

```tsx
describe('ConfirmUpdateCoreDialog major variant', () => {
  const majorProps = {
    open: true,
    onConfirm: vi.fn(),
    onCancel: vi.fn(),
    currentVersion: '7.4',
    targetVersion: '8.0',
    isMinorUpdate: false,
    isAutoUpdateEnabled: false,
    plugins: [
      { name: 'Akismet', tested_up_to: '7.4' },   // older than target
      { name: 'Yoast SEO', tested_up_to: '8.0' }, // compatible
      { name: 'WP Old Plugin', tested_up_to: null }, // unknown
    ],
    themes: [],
  }

  it('renders stop-sign emoji and red button in major variant', () => {
    render(<ConfirmUpdateCoreDialog {...majorProps} />)
    expect(screen.getByText(/🛑/)).toBeInTheDocument()
    const btn = screen.getByRole('button', { name: /Yes, run MAJOR upgrade 7\.4 → 8\.0/i })
    expect(btn).toHaveClass('bg-red-600')
  })

  it('shows compat list with plugins below target', () => {
    render(<ConfirmUpdateCoreDialog {...majorProps} />)
    expect(screen.getByText(/Akismet/)).toBeInTheDocument()
    // Yoast SEO is compatible (8.0) so not in the warning list.
    // WP Old Plugin is unknown so shown.
    expect(screen.getByText(/WP Old Plugin/)).toBeInTheDocument()
  })

  it('shows soft success line when all compatible', () => {
    const compatProps = {
      ...majorProps,
      plugins: [{ name: 'Yoast SEO', tested_up_to: '8.0' }],
      themes: [{ name: 'Twenty Twenty-Four', tested_up_to: '8.0' }],
    }
    render(<ConfirmUpdateCoreDialog {...compatProps} />)
    expect(screen.getByText(/All installed plugins & themes report compatibility/i)).toBeInTheDocument()
  })

  it('requires typing the target version to enable confirm', () => {
    const { getByRole, getByPlaceholderText } = render(<ConfirmUpdateCoreDialog {...majorProps} />)
    const btn = getByRole('button', { name: /Yes, run MAJOR upgrade/i })
    expect(btn).toBeDisabled()

    const input = getByPlaceholderText(/e\.g\./)
    fireEvent.change(input, { target: { value: '8.0' } })
    expect(btn).not.toBeDisabled()
  })

  it('rejects type-the-version input with trailing whitespace', () => {
    const { getByRole, getByPlaceholderText } = render(<ConfirmUpdateCoreDialog {...majorProps} />)
    const input = getByPlaceholderText(/e\.g\./)
    fireEvent.change(input, { target: { value: '8.0 ' } }) // trailing space
    expect(getByRole('button', { name: /Yes, run MAJOR upgrade/i })).toBeDisabled()
  })

  it('button label includes from and target versions', () => {
    render(<ConfirmUpdateCoreDialog {...majorProps} />)
    expect(
      screen.getByRole('button', { name: /Yes, run MAJOR upgrade 7\.4 → 8\.0/ })
    ).toBeInTheDocument()
  })
})
```

- [ ] **Step 8: Modify `ConfirmUpdateCoreDialog.tsx`**

In `apps/web/src/components/sites/ConfirmUpdateCoreDialog.tsx`, add an `isMajor` derived value + the major-variant JSX branches. New props on the component: `plugins: Array<{ name: string; tested_up_to: string | null }>` and `themes: Array<{ name: string; tested_up_to: string | null }>`.

Add to the component body:

```tsx
const isMajor = !isMinorUpdate
const [typedVersion, setTypedVersion] = useState('')
const isConfirmEnabled = !isMajor || typedVersion === targetVersion

const incompatiblePlugins = plugins.filter(
  (p) => p.tested_up_to !== null && p.tested_up_to < targetVersion
)
const unknownPlugins = plugins.filter((p) => p.tested_up_to === null)
const incompatibleThemes = themes.filter(
  (t) => t.tested_up_to !== null && t.tested_up_to < targetVersion
)
const unknownThemes = themes.filter((t) => t.tested_up_to === null)
const hasCompatIssues =
  incompatiblePlugins.length + unknownPlugins.length +
  incompatibleThemes.length + unknownThemes.length > 0
```

JSX additions inside the dialog body:

```tsx
<DialogHeader>
  <DialogTitle>
    {isMajor ? '🛑 ' : ''}
    {isMajor
      ? `Run MAJOR WordPress upgrade — ${currentVersion} → ${targetVersion}`
      : `Update WordPress to ${targetVersion}`}
  </DialogTitle>
</DialogHeader>

{/* Banner 1: downtime + cache flush (existing) -- keep as-is */}
{/* Banner 2: cannot be reversed without backup (existing) -- keep as-is */}

{isMajor && (
  <div className="rounded-md border border-red-200 bg-red-50 p-4">
    <p className="font-medium text-red-900">Plugin & theme compatibility</p>
    {hasCompatIssues ? (
      <>
        <p className="text-sm text-red-800">
          These items may not be compatible with WordPress {targetVersion}:
        </p>
        <ul className="mt-2 list-disc pl-5 text-sm text-red-800">
          {incompatiblePlugins.map((p) => (
            <li key={p.name}>{p.name} (tested up to {p.tested_up_to})</li>
          ))}
          {unknownPlugins.map((p) => (
            <li key={p.name}>{p.name} (no compatibility info)</li>
          ))}
          {incompatibleThemes.map((t) => (
            <li key={t.name}>{t.name} (tested up to {t.tested_up_to})</li>
          ))}
          {unknownThemes.map((t) => (
            <li key={t.name}>{t.name} (no compatibility info)</li>
          ))}
        </ul>
      </>
    ) : (
      <p className="text-sm text-green-800">
        All installed plugins & themes report compatibility with {targetVersion}.
      </p>
    )}
  </div>
)}

{isMajor && (
  <div>
    <Label htmlFor="confirm-version">
      To confirm this major upgrade, type the target version:
    </Label>
    <Input
      id="confirm-version"
      placeholder={`e.g. ${targetVersion}`}
      value={typedVersion}
      onChange={(e) => setTypedVersion(e.target.value)}
    />
  </div>
)}

{/* "Auto-updates ON" paragraph: render ONLY when !isMajor */}
{!isMajor && isAutoUpdateEnabled && (
  <p className="text-sm text-muted-foreground">
    {/* Existing auto-updates copy preserved verbatim from P2.4 */}
  </p>
)}

<DialogFooter>
  <Button variant="outline" onClick={onCancel} autoFocus>Cancel</Button>
  <Button
    onClick={onConfirm}
    disabled={!isConfirmEnabled}
    className={isMajor ? 'bg-red-600 hover:bg-red-700' : 'bg-amber-600 hover:bg-amber-700'}
  >
    {isMajor
      ? `Yes, run MAJOR upgrade ${currentVersion} → ${targetVersion}`
      : 'Yes, update WordPress core'}
  </Button>
</DialogFooter>
```

Update the caller (`SiteCoreCard`) to pass `plugins` and `themes` to the dialog — they come from `useSitePlugins(siteId)` and `useSiteThemes(siteId)` queries that the parent already has access to. The simplest path: have `SiteCoreCard` call those hooks at the top and pass `.data ?? []` through to the dialog.

- [ ] **Step 9: Run tests to verify they pass**

Run:
```
cd apps/web && pnpm test -- --run ConfirmUpdateCoreDialog
```

Expected: PASS — major-variant tests green, existing minor-variant tests still pass.

### 13.4 — `SiteDetail` integration

- [ ] **Step 10: Modify `SiteDetail.tsx`**

In `apps/web/src/routes/SiteDetail.tsx`, find where `<SiteCoreCard site={site} />` is rendered. Immediately below it, add:

```tsx
{site && <SiteMajorUpdatesSettingsRow site={site} />}
```

(The `site` variable is presumably `useSite(siteId).data` — match the existing component's prop sourcing.)

- [ ] **Step 11: Run the full SPA test suite to verify integration**

Run:
```
cd apps/web && pnpm test -- --run
cd apps/web && pnpm lint
```

Expected: PASS — all tests green, no lint errors.

- [ ] **Step 12: Commit**

```bash
git add apps/web/src/components/sites/SiteCoreCard.tsx \
        apps/web/src/components/sites/SiteCoreCard.test.tsx \
        apps/web/src/components/sites/SiteMajorUpdatesSettingsRow.tsx \
        apps/web/src/components/sites/SiteMajorUpdatesSettingsRow.test.tsx \
        apps/web/src/components/sites/ConfirmUpdateCoreDialog.tsx \
        apps/web/src/components/sites/ConfirmUpdateCoreDialog.test.tsx \
        apps/web/src/routes/SiteDetail.tsx
git commit -m "feat(p2-4-1): SPA major-update UI -- 5th card state + settings row + red dialog

SiteCoreCard gains blocked-major-available (Manage settings button) and
allowed-major-available (red Update button) states. SiteMajorUpdatesSettingsRow
renders below the card with a Switch bound to core_allow_major.
ConfirmUpdateCoreDialog gains an isMajor variant: 🛑 header, 3rd compat
banner driven by tested_up_to data, type-the-version input gating, and
a red bg-red-600 Confirm button labelled 'Yes, run MAJOR upgrade X → Y'.
Per spec § 5.2, § 5.3, § 5.5, § 5.6."
```

---

## Task 14 — Build zips + 10-step manual smoke matrix

**Files:** none (build + smoke playbook).

Run the spec § 7.2 smoke matrix verbatim. DO NOT proceed to Task 15 unless all 10 steps are green.

- [ ] **Step 1: Confirm all suites green**

```
cd packages/connector-plugin && composer test
cd packages/dashboard-plugin && composer test
cd apps/web && pnpm test -- --run
cd apps/web && pnpm lint
```

Expected: ALL PASS.

- [ ] **Step 2: Build connector zip (v0.1.7) — apply established lessons**

```bash
cd packages/connector-plugin
composer dump-autoload --no-dev --classmap-authoritative
zip -r ~/Desktop/defyn-connector-v0.1.7-$(date +%Y-%m-%d).zip . \
  -x "tests/*" "node_modules/*" "*.git*" "vendor/wordpress/*" "vendor/johnpbloch/*"
composer install
```

Target zip size: ~70KB. If dramatically larger (5MB+), the vendor exclusions didn't take.

- [ ] **Step 3: Build dashboard zip (v0.6.0) — apply established lessons**

```bash
cd packages/dashboard-plugin
composer dump-autoload --no-dev --classmap-authoritative
zip -r ~/Desktop/defyn-dashboard-v0.6.0-$(date +%Y-%m-%d).zip . \
  -x "tests/*" "node_modules/*" "*.git*" "vendor/wordpress/*" "vendor/johnpbloch/*"
composer install
```

Target zip size: ~550KB.

- [ ] **Step 4: Install on production via "Replace current with uploaded version"**

1. Upload the connector zip to `smartcoding.com.au` via Plugins → Add New → Upload → Replace current.
2. Upload the dashboard zip to `defynwp.defyn.agency` via Plugins → Add New → Upload → Replace current.
3. **Schema self-heal note:** the dashboard auto-runs the v5 → v6 migration on first `plugins_loaded` after upgrade. Verify by:
   - `wp option get defyn_dashboard_schema_version` returns `6`
   - `wp db query "SHOW COLUMNS FROM wp_defyn_sites LIKE 'core_allow_major'"` returns 1 row
   - `wp db query "SHOW COLUMNS FROM wp_defyn_site_plugins LIKE 'tested_up_to'"` returns 1 row
   - `wp db query "SHOW COLUMNS FROM wp_defyn_site_themes LIKE 'tested_up_to'"` returns 1 row
4. SmartCoding handshake stays in place from prior smoke runs.

- [ ] **Step 5: Run the 10-step smoke matrix from spec § 7.2 verbatim**

Record PASS/FAIL inline for each step. If any step fails, STOP — file `fix(p2-4-1):` commits before tagging.

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
| 1 | `curl -H "Authorization: Bearer $TOKEN" "https://defynwp.defyn.agency/wp-json/defyn/v1/sites/$SITE_ID"` | Response includes `core_allow_major: false` (default) |
| 2 | `curl -X POST -H "Authorization: Bearer $TOKEN" "https://defynwp.defyn.agency/wp-json/defyn/v1/sites/$SITE_ID/plugins/refresh"` then GET `/sites/1/plugins` | Each plugin row has `tested_up_to` (null or version) |
| 3 | `curl -X POST -H "Authorization: Bearer $TOKEN" "https://defynwp.defyn.agency/wp-json/defyn/v1/sites/$SITE_ID/themes/refresh"` then GET `/sites/1/themes` | Each theme row has `tested_up_to` |
| 4 | `wp db query "UPDATE wp_defyn_sites SET core_update_available=1, core_update_version='8.0' WHERE id=1"` (synthetic major), then `curl GET /sites/1` | `core_update_available=true`, `core_update_version=8.0`, `core_allow_major=false` |
| 5 | `curl -X POST -H "Authorization: Bearer $TOKEN" "https://defynwp.defyn.agency/wp-json/defyn/v1/sites/$SITE_ID/core/update"` | `409 core.major_update_blocked` with new message ("require enabling major updates for this site first") |
| 6 | `curl -X POST -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" --data '{"allow":true}' "https://defynwp.defyn.agency/wp-json/defyn/v1/sites/$SITE_ID/core/allow-major"` | `200 {"site_id":1,"core_allow_major":true}` |
| 7 | `curl -H "Authorization: Bearer $TOKEN" "https://defynwp.defyn.agency/wp-json/defyn/v1/sites/$SITE_ID/activity?per_page=20"` | `core_allow_major.toggled enabled=true` event present |
| 8 | `curl -X POST -H "Authorization: Bearer $TOKEN" "https://defynwp.defyn.agency/wp-json/defyn/v1/sites/$SITE_ID/core/update"` (after #6) | `202 {"scheduled":true, ..."core_update_state":"queued"}` |
| 9 | Tick wp-cron (`wp cron event run --all`), then `curl GET /sites/1/activity` | `core_update.requested → core_update.started → core_update.{succeeded\|failed}` triplet. `core_update.started` payload includes `allow_major: true` |
| 10 | SPA at `app.defynwp.defyn.agency/sites/1` → SiteCoreCard | Flag OFF + major available → "Major update available · Enable in settings" + Manage button (no Update). Flag ON → red Update button. Click → red-tier dialog with stop-sign + 3 banners + compat list + type-the-version input + red confirm button. Typing wrong version keeps button disabled. Typing exact target enables it. |

- [ ] **Step 6: Cleanup after smoke (spec § 7.3)**

```bash
wp db query "UPDATE wp_defyn_sites SET core_update_available=0, core_update_version=NULL, core_allow_major=0 WHERE id=1"
```

Or via SPA: toggle Allow-major off in the settings row, then `curl POST /sites/1/core/refresh` to clear the synthetic state.

- [ ] **Step 7: Document smoke results + commit any fix commits**

Record PASS/FAIL inline for each step. Do NOT proceed to Task 15 unless all 10 are green. File `fix(p2-4-1):` commits for any failures, then re-run from the failing step.

If smoke was green on the first run, this task creates no commits.

---

## Task 15 — Tag + push

**Files:** none (git tag).

ONLY run this task after Task 14's smoke matrix is fully green AND cleanup is applied. **NEVER push the tag if any smoke step failed.**

- [ ] **Step 1: Verify all suites green + working tree clean**

```bash
cd packages/connector-plugin && composer test
cd packages/dashboard-plugin && composer test
cd apps/web && pnpm test -- --run
cd apps/web && pnpm lint
git status
```

Expected: ALL PASS + `nothing to commit, working tree clean`.

- [ ] **Step 2: Create the annotated tag**

```bash
git tag -a p2-4-1-major-core-updates-complete -m "P2.4.1 — major-version WP core updates shipped

- Connector v0.1.7: CoreUpgraderService gains allowMajor opt-in;
  CoreUpdateController parses strict allow_major === true; collectors
  emit tested_up_to from plugin/theme headers.
- Dashboard v0.6.0: schema v6 with core_allow_major +
  tested_up_to columns; new POST /sites/{id}/core/allow-major endpoint
  (10/hr bucket); SitesCoreUpdateController preflight relaxes when
  flag is on; UpdateSiteCore threads allow_major to connector.
- SPA: SiteCoreCard 5th state (blocked-major-available +
  allowed-major-available red-tier); SiteMajorUpdatesSettingsRow inline
  toggle; ConfirmUpdateCoreDialog major variant with 🛑 + 3 banners +
  type-the-version input + red bg-red-600 confirm button.
- Spec: docs/superpowers/specs/2026-06-07-p2-4-1-major-core-updates-design.md
"
```

- [ ] **Step 3: Push the branch + tag**

```bash
git push origin p2-4-1-major-core-updates
git push origin p2-4-1-major-core-updates-complete
```

- [ ] **Step 4: Update MEMORY**

Append a one-line entry to `~/.claude/projects/-Users-pradeep-Local-Sites-defynWP/memory/project_defyn_overview.md`:
- "P2.4.1 (Major-version WP core updates) COMPLETE 2026-06-07 — tag `p2-4-1-major-core-updates-complete`, connector v0.1.7 + dashboard v0.6.0 live in prod. Operator opt-in flag persists per site; SPA dialog gates major upgrades behind a type-the-version confirm + tested_up_to-driven compat list."

Any new plan-bug lessons surfaced during execution go into MEMORY.md.

---

## Self-review — coverage against spec

Walking the spec sections to confirm every requirement maps to a task:

- **Spec § 1 architecture** — covered collectively by tasks below
- **Spec § 2 schema v6 migration** — Task 6 (all 3 ALTER columns + idempotency)
- **Spec § 2.4 migration tests** — Task 6 (5 tests including SCHEMA_VERSION constant + idempotency)
- **Spec § 2.5 self-heal** — automatic via P2.4's `Activation::maybeRunSelfHeal()`; manual smoke step 4 verifies live
- **Spec § 3.1 CoreUpgraderService::upgrade $allowMajor** — Task 1
- **Spec § 3.2 CoreUpdateController body parsing** — Task 3 (strict `=== true`)
- **Spec § 3.3 Collector::collectCore is_major_update_available** — Task 2
- **Spec § 3.4 PluginListCollector tested_up_to** — Task 4
- **Spec § 3.5 ThemeListCollector tested_up_to** — Task 4
- **Spec § 3.6 connector tests** — Tasks 1-4 (9 tests across CoreUpgraderServiceAllowMajor, CollectorIsMajorUpdate, CoreUpdateAllowMajor, CollectorPluginsTestedUpTo, CollectorThemesTestedUpTo)
- **Spec § 3.7 connector v0.1.7 release** — Task 5
- **Spec § 4.1 SCHEMA_VERSION = 6** — Task 6
- **Spec § 4.2 Site model coreAllowMajor** — Task 7
- **Spec § 4.3 Plugin/Theme model testedUpTo** — Task 7
- **Spec § 4.4 SitesRepository::setCoreAllowMajor + extended findById** — Task 8
- **Spec § 4.5 Sync*Services persist tested_up_to** — Task 8
- **Spec § 4.6 SitesCoreUpdateController preflight relaxation** — Task 9
- **Spec § 4.7 SitesCoreAllowMajorController** — Task 10
- **Spec § 4.8 RateLimit::coreAllowMajor (10/hr)** — Task 10
- **Spec § 4.9 UpdateSiteCore allow_major body + started event** — Task 9
- **Spec § 4.10 dashboard tests** — Tasks 6-10 (~20 tests across SchemaVersionMigrationV6, SiteCoreAllowMajor, Plugin/Theme TestedUpTo, SitesRepositoryAllowMajor, SitesCoreAllowMajor, SitesCoreUpdateMajorRelax, UpdateSiteCoreAllowMajor)
- **Spec § 4.11 dashboard v0.6.0 release** — Task 11
- **Spec § 5.1 Zod schema extensions** — Task 12
- **Spec § 5.2 SiteCoreCard 5th state** — Task 13.1
- **Spec § 5.3 SiteMajorUpdatesSettingsRow** — Task 13.2
- **Spec § 5.4 useToggleCoreAllowMajor** — Task 12
- **Spec § 5.5 ConfirmUpdateCoreDialog major variant** — Task 13.3
- **Spec § 5.6 SiteDetail always-renders SettingsRow** — Task 13.4
- **Spec § 5.7 MSW handlers** — Task 12
- **Spec § 5.8 SPA tests** — Tasks 12 + 13 (~15 tests across useToggleCoreAllowMajor, SiteCoreCard major states, SiteMajorUpdatesSettingsRow, ConfirmUpdateCoreDialog major variant)
- **Spec § 6 testing strategy** — covered as a sum of per-task tests
- **Spec § 7 manual smoke flow** — Task 14
- **Spec § 9 plan-author notes (11 plan-bug traps)** — all encoded inline in tasks + workflow conventions block
- **Spec § 10 acceptance criteria** — Task 15 (tag + push + MEMORY update)

All sections covered. ✅

## Self-review — placeholder scan

Searched for `TBD`, `TODO`, `implement later`, `fill in`, `similar to Task` — none present in any concrete code block. The phrase "Copy the exact required fields used in the existing siteSchema test" appears ONCE in Task 12 as an explicit instruction to read `api.test.ts` and lift the canonical fixture shape rather than inventing fields — that's a pointer to in-tree code, not a placeholder.

## Self-review — type consistency

- `coreAllowMajor` (camelCase model field) ↔ `core_allow_major` (snake_case DB column + Zod field + JSON wire) — consistent across Tasks 6, 7, 8, 9, 10, 12, 13.
- `testedUpTo` (camelCase model field) ↔ `tested_up_to` (snake_case DB column + Zod field + JSON wire) — consistent across Tasks 4, 6, 7, 8, 12, 13.
- `CORE_ALLOW_MAJOR_LIMIT = 10` + `CORE_ALLOW_MAJOR_WINDOW = HOUR_IN_SECONDS` + method `coreAllowMajor(WP_REST_Request)` — consistent with the established `CORE_*_LIMIT` + `CORE_*_WINDOW` + camelCase method pattern in `RateLimit.php` (verified by reading existing `coreUpdate` / `sitesCoreRefresh` shape).
- Activity event name `core_allow_major.toggled` — same across Tasks 10 + 14 smoke step 7.
- Test method `testRateLimit429AfterEleventhCall` (10/hr bucket → 11th call returns 429) — consistent across Task 10 + workflow conventions trap #1.
- `CoreUpgraderService::upgrade(bool $allowMajor = false)` — single signature change, consistent across Tasks 1, 3.
- `SitesCoreUpdateController` preflight uses AND (`!isMinor && !$site->coreAllowMajor`), NOT OR — consistent across Task 9 + workflow conventions trap #6.

No drift. ✅

---

## Execution handoff

**Plan complete and saved to `docs/superpowers/plans/2026-06-07-p2-4-1-major-core-updates.md`. Two execution options:**

**1. Subagent-Driven (recommended)** — I dispatch a fresh subagent per task, two-stage review (spec compliance + code quality) between tasks, same-session fast iteration. This is what every prior P2.x phase used.

**2. Inline Execution** — Execute tasks in this session using the executing-plans skill, batch execution with checkpoints for review.

**Which approach?**
