# P2.6 "Sync all sites now" Bulk Action Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a single "Sync all sites now" button on the Operator Overview at `/overview` that fan-outs the existing `SyncSite` Action Scheduler job for every site the operator owns. Smallest deferred bulk action from P2.5 § 7. Read-side action, neutral-tier UI. Output: dashboard v0.7.0 → v0.7.1, no connector changes, no schema changes.

**Architecture:** ONE new REST endpoint `POST /defyn/v1/overview/sync-all` — thin controller fan-outs `as_schedule_single_action('defyn_sync_site', [$siteId])` per owned site, logs ONE fleet-scoped `overview.sync_all_requested` activity event (`site_id = null`), and responds 202 (or 200 if zero sites) with `{scheduled_count, site_ids, scheduled_at}`. `/overview` gets a new additive `total_sites: int` field. SPA gets a neutral-tier `SyncAllSitesButton` on the Overview header + a `ConfirmSyncAllDialog` (Cancel has default focus) + a `useSyncAllSites` mutation hook that invalidates `['overview']` on success.

**Tech Stack:** PHP 8.1+ (PHPUnit, `WP_UnitTestCase` / `AbstractSchemaTestCase`), WordPress REST API, Action Scheduler, React 18 + TypeScript + TanStack Query v5 + Zod + Tailwind + shadcn/ui + Vitest + React Testing Library + MSW.

**Spec:** [`docs/superpowers/specs/2026-06-08-p2-6-sync-all-sites-design.md`](../specs/2026-06-08-p2-6-sync-all-sites-design.md)

---

## Workflow conventions

- **Branch:** branch off the current tip of `p2-5-overview-dashboard` (which is `f11517e` — the just-committed P2.6 spec). Branch name: `p2-6-sync-all-sites`. Command:
  ```
  git checkout -b p2-6-sync-all-sites p2-5-overview-dashboard
  ```
- **Each Task = one atomic commit.**
- **Test discipline (TDD):** Step 1 writes the failing test. Step 2 runs it and confirms it fails. Step 3 writes the implementation. Step 4 confirms it passes. Step 5 commits.
- **Test runners:**
  - Dashboard PHP: `cd packages/dashboard-plugin && composer test`
  - SPA: `cd apps/web && pnpm test -- --run`
- **Commit message format:** `<type>(p2-6): <description>` where `<type>` ∈ {feat, fix, refactor, docs, test, chore}.
- **All edits adhere to** `~/.claude/rules/common/coding-style.md` — immutability, KISS, DRY, YAGNI, error handling, no `console.log` / `var_dump` / `print_r`.
- **No connector changes.** Connector stays at **v0.1.7**. The smoke does NOT require connector reinstall.
- **No schema changes.** Schema stays at **v6**.

### Plan-bug traps to internalise before writing any code

1. **`RateLimit::OVERVIEW_SYNC_ALL_LIMIT = 10`** per **HOUR**. Window `HOUR_IN_SECONDS`. Same shape as `CORE_ALLOW_MAJOR_LIMIT` from P2.4.1. Test method MUST be `testRateLimit429AfterEleventhCall` (NOT "twelfth", NOT "seventh" / "thirty-first" — those are themes/plugins-update + the per-minute overview bucket from P2.5 respectively).

2. **Activity event name MUST be EXACTLY** `overview.sync_all_requested`. Not `site.bulk_sync_requested`, not `overview.bulk_sync`, not `overview.sync_all`. Exact string. Both the controller test `testActivityEventEmittedWithCorrectDetails` and the production smoke step 4 grep for this exact string.

3. **`site_id` on the activity event is `null`** (fleet-scoped). The `site_ids[]` array goes inside `details` (JSON column). `ActivityLogger::log(?int $userId, ?int $siteId, string $eventType, ?array $details = null, ?string $ipAddress = null): void` — pass `null` as the second positional arg.

4. **Activity event MUST NOT fire when `scheduled_count === 0`.** Guard the `log()` call with `if (count($ids) > 0)`. Test `testZeroSitesReturns200WithEmptyArrays` asserts NO row was written to `wp_defyn_activity_log` in the zero-sites path. No-op success = no log noise.

5. **Endpoint returns 202 ONLY when `scheduled_count > 0`; returns 200 with the SAME envelope shape when `scheduled_count === 0`.** Both statuses use the same body shape (`scheduled_count: 0, site_ids: [], scheduled_at: "..."`) so the SPA's `syncAllSitesResponseSchema` Zod parses both unambiguously. Don't 204 / don't omit `site_ids`.

6. **No `OverviewSyncAllService` extension.** Inline the fan-out loop directly in the controller (~6 lines). Compare scope to `SitesPingController` for reference. Don't over-engineer.

7. **`/overview` response gains a `total_sites: int` field.** Additive Zod extension on `overviewSchema`. Backend computes via `SitesRepository::countAllForUser(int $userId): int` — ONE new count method, mirrors the existing `countPendingPlugins` pattern at `SitesRepository.php:445`. MUST use `SELECT COUNT(*) FROM {sites} WHERE user_id = %d`, NOT `count(findAllForUser(...))` which materializes the row set.

8. **MSW handler for `GET /overview` MUST be updated to emit `total_sites`** in the same commit as the Zod extension. Otherwise the entire SPA test suite fails on Zod parse because P2.5's existing `Overview.test.tsx` + `useOverview.test.tsx` ship synthetic payloads that no longer satisfy the schema.

9. **Confirm dialog primary button is NEUTRAL color** — use the shadcn `Button` default variant (`bg-primary text-primary-foreground`). NOT amber, NOT red. Read-side action (sync = fetch, not mutate). RED-tier is reserved for destructive ops (major core update, delete site). AMBER for ambiguous-but-recoverable ops (minor core update, plugin update). Sync is "always safe to retry" → neutral.

10. **Cancel button has default focus** in `ConfirmSyncAllDialog` (mirror P2.4 `ConfirmUpdateCoreDialog` lines 54-63: `cancelRef = useRef<HTMLButtonElement>(null)` + `useEffect(() => { if (open) cancelRef.current?.focus() }, [open])`).

11. **Mutation hook invalidates `['overview']`** on success — NOT `['sites']`. Per-site state hasn't changed yet (the AS jobs are only queued); per-site state updates naturally as each `SyncSite` runs. Invalidating `['sites']` would needlessly refetch the full site list. Invalidating `['overview']` makes the Recent Activity widget show the new fleet event immediately.

12. **`as_schedule_single_action(time(), 'defyn_sync_site', [$siteId])`** — the existing P2.1 hook name. Do NOT introduce a new hook name. The test asserts via `as_get_scheduled_actions(['hook' => 'defyn_sync_site'])`. The single arg `[$siteId]` matches the existing `SyncSite::handle(int $siteId)` signature.

13. **Defensive `ob_start()` in the controller** wrapped in a try/finally that `ob_end_clean()` — carry-forward from P2.2 plan-bug #4 (the connector STDOUT regression that broke `json_decode`). `as_schedule_single_action` itself doesn't echo, but some Kinsta-side plugins hook `action_scheduler_pre_run_action` and DO occasionally echo on the synchronous scheduling path. Defensive guard, same pattern as `PluginUpdateController` and `CoreUpdateController`.

14. **`RestRouter` registration MUST land BEFORE `register_rest_route(self::NAMESPACE, '/activity', [...])`** (lines 222-225) — that's the catch-all-ish last route in the file. Append the new POST route immediately after the `/overview` GET registration at line 216-220.

15. **Dashboard zip build:** `composer install --no-dev --classmap-authoritative` FIRST (NOT just `dump-autoload --no-dev`). Exclude `vendor/wordpress/*` + `vendor/johnpbloch/*` + dev packages. Target zip size ~552KB. After zipping, run `composer install` to restore dev autoload. (Burned 2 hours debugging on P2.4.1; do not skip.)

16. **OPcache + Redis cache discipline:** after dashboard plugin replace on Kinsta, hit **MyKinsta → Tools → Clear cache**. Without it, the new `/overview/sync-all` route may 404 for hours.

17. **Final smoke matrix is § 5.2 of the spec verbatim — 6 steps.** Tag `p2-6-sync-all-sites-complete` ONLY after all 6 pass.

### Existing-code anchors (read these before starting any task)

- `packages/dashboard-plugin/src/Services/SitesRepository.php` — most recent additions: `countPendingPlugins` at line 445, `countSitesWithAnyUpdate` at line 524, `findSitesNeedingAttention` after the count methods, P2.4.1's `setCoreAllowMajor` around line 390, `findAllForUser` at line 97. Append `countAllForUser` after the existing count methods (after `countSitesWithAnyUpdate`).
- `packages/dashboard-plugin/src/Services/OverviewService.php` — `compose(int $userId): array` returns the 4-key envelope today. P2.6 adds `total_sites` as a 5th top-level key. The class uses constructor-injected `SitesRepository $sites` and `ActivityLogRepository $activity`.
- `packages/dashboard-plugin/src/Services/ActivityLogger.php` — signature `log(?int $userId, ?int $siteId, string $eventType, ?array $details = null, ?string $ipAddress = null): void`. Pass `null` for `$siteId` to make the event fleet-scoped.
- `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php` — most recent constants `OVERVIEW_LIMIT = 30 / MINUTE_IN_SECONDS` at lines 79-80. Most recent method `overview(WP_REST_Request $request)` at line 360. Append `OVERVIEW_SYNC_ALL_LIMIT/WINDOW` constants after the `OVERVIEW_*` block and append the `overviewSyncAll(...)` method after the `overview(...)` method.
- `packages/dashboard-plugin/src/Rest/RestRouter.php` — `/overview` GET registration at lines 216-220 (between the comment block at line 212 and the `/activity` registration at line 222). Append the new POST registration immediately after line 220.
- `apps/web/src/lib/queries/useOverview.ts` — TanStack hook with `queryKey: ['overview']`, polls every 60s.
- `apps/web/src/lib/mutations/useToggleCoreAllowMajor.ts` — closest pattern reference for the new `useSyncAllSites` mutation hook (typed `useMutation<TData, Error, TArgs>` with `apiClient.post`, `parse` via Zod, invalidates a query key on success).
- `apps/web/src/components/sites/ConfirmUpdateCoreDialog.tsx` — line 54 (`cancelRef = useRef<HTMLButtonElement>(null)`) + lines 56-63 (`useEffect(() => { if (open) cancelRef.current?.focus() }, [open])`) — exact pattern to mirror for Cancel default focus.
- `apps/web/src/components/ui/button.tsx` — `Button` variants: `default` / `outline` / `ghost`. P2.6 uses `default` for the confirm primary (neutral) and `outline` for the header button.
- `apps/web/src/routes/Overview.tsx` — current header is `<div className="flex items-baseline justify-between">` with `<h1>Overview</h1>` left and `<p>Last refreshed:...</p>` right. P2.6 keeps the structure but adds `<SyncAllSitesButton totalSites={data.total_sites} />` to the right side (below "Last refreshed:" as a stacked column).
- `apps/web/src/types/api.ts` — `overviewSchema` at line 99, `type Overview` at line 125. Append `total_sites: z.number().int().nonnegative()` to `overviewSchema` AND add a NEW `syncAllSitesResponseSchema` after it.
- `apps/web/src/test/handlers.ts` — MSW handler array. Existing `GET /overview` handler emits the current envelope without `total_sites` — must add the field to that handler AND register a new `POST /overview/sync-all` handler.
- `apps/web/src/lib/apiClient.ts` — `apiClient.get<T>(path)` and `apiClient.post<T>(path, body?)` at lines 99-100. `body` is optional — for the empty-body POST in the bulk endpoint, omit it (`apiClient.post<unknown>('/overview/sync-all')`).

---

## File structure overview

### Dashboard plugin (v0.7.1) — new files

| Path | Responsibility |
|---|---|
| `src/Rest/OverviewSyncAllController.php` | POST /defyn/v1/overview/sync-all — auth, fan-out, fleet activity log, 202/200 |
| `tests/Integration/Rest/OverviewSyncAllControllerTest.php` | 7 tests — auth, happy, zero-sites, rate limit, ownership, fan-out, activity event |

### Dashboard plugin — modified files

| Path | What changes |
|---|---|
| `src/Services/SitesRepository.php` | Add `countAllForUser(int $userId): int` |
| `src/Services/OverviewService.php` | Add `total_sites` to the compose() response |
| `src/Rest/Middleware/RateLimit.php` | Add `OVERVIEW_SYNC_ALL_LIMIT/WINDOW` constants + `overviewSyncAll()` method |
| `src/Rest/RestRouter.php` | Register POST /overview/sync-all route |
| `tests/Integration/Services/SitesRepositoryOverviewTest.php` | Extend with 2 `countAllForUser` tests |
| `tests/Unit/Services/OverviewServiceTest.php` | Extend with 1 `total_sites` test |
| `defyn-dashboard.php` | Version `0.7.0` → `0.7.1` |
| `readme.txt` | Stable tag + changelog entry |
| `composer.json` | Version `0.7.0` → `0.7.1` |

### SPA (`apps/web`) — new files

| Path | Responsibility |
|---|---|
| `src/components/overview/SyncAllSitesButton.tsx` | Button + dialog state + mutation invocation + spinner |
| `src/components/overview/ConfirmSyncAllDialog.tsx` | Modal — title, body, Cancel (default focus), neutral primary |
| `src/lib/mutations/useSyncAllSites.ts` | TanStack mutation, POSTs `/overview/sync-all`, invalidates `['overview']` |
| `tests/components/overview/SyncAllSitesButton.test.tsx` | 3 tests — idle render, opens dialog, pending state |
| `tests/components/overview/ConfirmSyncAllDialog.test.tsx` | 2 tests — Cancel default focus, dynamic primary label |
| `tests/lib/mutations/useSyncAllSites.test.tsx` | 2 tests — POST endpoint, invalidates `['overview']` |

### SPA — modified files

| Path | What changes |
|---|---|
| `src/types/api.ts` | Add `total_sites` to `overviewSchema`; add new `syncAllSitesResponseSchema` |
| `src/test/handlers.ts` | Add `total_sites` to existing `GET /overview` handler; add new `POST /overview/sync-all` handler |
| `src/routes/Overview.tsx` | Render `<SyncAllSitesButton totalSites={data.total_sites} />` in the header column |
| `tests/routes/Overview.test.tsx` | Extend MSW responses with `total_sites`; add header-button render assertion |

---

## Task 1 — `SitesRepository::countAllForUser`

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/SitesRepository.php`
- Modify: `packages/dashboard-plugin/tests/Integration/Services/SitesRepositoryOverviewTest.php`

### Step 1: Append failing tests

Append these test methods INSIDE the existing `SitesRepositoryOverviewTest` class (the file already has `seedSite/seedPlugin/seedTheme` helpers from P2.5):

```php
public function testCountAllForUserReturnsZeroWhenUserHasNoSites(): void
{
    $this->assertSame(0, (new SitesRepository())->countAllForUser(1));
}

public function testCountAllForUserReturnsCorrectCountAndExcludesOtherUsers(): void
{
    $this->seedSite(1);
    $this->seedSite(1);
    $this->seedSite(1);
    $this->seedSite(1);
    $this->seedSite(1);
    $this->seedSite(2); // different user — must NOT count for user 1

    $repo = new SitesRepository();
    $this->assertSame(5, $repo->countAllForUser(1));
    $this->assertSame(1, $repo->countAllForUser(2));
}
```

Test method names MUST be EXACTLY:
- `testCountAllForUserReturnsZeroWhenUserHasNoSites`
- `testCountAllForUserReturnsCorrectCountAndExcludesOtherUsers`

### Step 2: Run tests to verify they fail

```
cd packages/dashboard-plugin && composer test -- --filter SitesRepositoryOverviewTest::testCountAllForUser
```

Expected: FAIL — `Call to undefined method SitesRepository::countAllForUser`.

### Step 3: Add the method

In `packages/dashboard-plugin/src/Services/SitesRepository.php`, append after the existing `countSitesWithAnyUpdate` method (around line 540):

```php
/**
 * P2.6 — count of sites owned by $userId. Used by OverviewService to
 * emit `total_sites` on the /overview response so the SPA's "Sync all
 * N sites" button can display the dynamic count.
 *
 * Implementation note: COUNT(*) on the sites table directly — DO NOT
 * use count(findAllForUser($userId)), which materializes the full row
 * set just to count it. Mirrors the existing countPendingPlugins
 * pattern at line ~445.
 */
public function countAllForUser(int $userId): int
{
    global $wpdb;
    $sitesTable = SitesTable::tableName();

    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$sitesTable} WHERE user_id = %d",
        $userId
    ));
}
```

### Step 4: Run tests to verify they pass

```
cd packages/dashboard-plugin && composer test -- --filter SitesRepositoryOverviewTest::testCountAllForUser
```

Expected: PASS — both tests green.

Also run the full overview-repo test class to confirm no regression:
```
cd packages/dashboard-plugin && composer test -- --filter SitesRepositoryOverviewTest
```
Expected: PASS — all 13 P2.5 tests + the 2 new ones green.

### Step 5: Commit

```bash
git -C "/Users/pradeep/Local Sites/defynWP" add packages/dashboard-plugin/src/Services/SitesRepository.php \
        packages/dashboard-plugin/tests/Integration/Services/SitesRepositoryOverviewTest.php
git -C "/Users/pradeep/Local Sites/defynWP" commit -m "feat(p2-6): SitesRepository::countAllForUser

Indexed COUNT(*) query — does not materialize the row set. Used by
OverviewService.compose to emit total_sites so the SPA's Sync All
button can render a dynamic count. Per spec § 2.6 + plan-bug trap #7."
```

---

## Task 2 — `OverviewService::compose` emits `total_sites`

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/OverviewService.php`
- Modify: `packages/dashboard-plugin/tests/Unit/Services/OverviewServiceTest.php`

### Step 1: Append failing test

Append this test method INSIDE the existing `OverviewServiceTest` class:

```php
public function testComposeIncludesTotalSitesCount(): void
{
    $this->seedSite(1);
    $this->seedSite(1);
    $this->seedSite(1);
    $this->seedSite(2); // other user's site

    $result = (new OverviewService())->compose(1);

    $this->assertArrayHasKey('total_sites', $result);
    $this->assertSame(3, $result['total_sites']);
}
```

Test method name MUST be EXACTLY: `testComposeIncludesTotalSitesCount`.

### Step 2: Run test to verify it fails

```
cd packages/dashboard-plugin && composer test -- --filter OverviewServiceTest::testComposeIncludesTotalSitesCount
```

Expected: FAIL — `Failed asserting that an array has the key 'total_sites'`.

### Step 3: Extend compose()

In `packages/dashboard-plugin/src/Services/OverviewService.php`, extend the return array. The current return block is:

```php
return [
    'pending_updates' => [...],
    'sites_needing_attention' => $this->sites->findSitesNeedingAttention($userId),
    'recent_activity'         => $activity,
    'generated_at'            => gmdate('Y-m-d H:i:s'),
];
```

Change it to:

```php
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
    'total_sites'             => $this->sites->countAllForUser($userId),
    'generated_at'            => gmdate('Y-m-d H:i:s'),
];
```

Also update the PHPDoc `@return` array shape at the top of `compose()` to add the `total_sites: int` key.

### Step 4: Run tests to verify they pass

```
cd packages/dashboard-plugin && composer test -- --filter OverviewServiceTest
```
Expected: PASS — all 6 tests (5 existing + 1 new) green.

### Step 5: Commit

```bash
git -C "/Users/pradeep/Local Sites/defynWP" add packages/dashboard-plugin/src/Services/OverviewService.php \
        packages/dashboard-plugin/tests/Unit/Services/OverviewServiceTest.php
git -C "/Users/pradeep/Local Sites/defynWP" commit -m "feat(p2-6): OverviewService emits total_sites in /overview response

Additive field — countAllForUser delegated to SitesRepository. The
SPA's SyncAllSitesButton uses this to render 'Sync all N sites'. Per
spec § 2.6."
```

---

## Task 3 — `RateLimit::overviewSyncAll` (10/HOUR) + `OverviewSyncAllController` + route registration

**Files:**
- Create: `packages/dashboard-plugin/src/Rest/OverviewSyncAllController.php`
- Modify: `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php`
- Modify: `packages/dashboard-plugin/src/Rest/RestRouter.php`
- Create: `packages/dashboard-plugin/tests/Integration/Rest/OverviewSyncAllControllerTest.php`

### Step 1: Write the failing tests

Create `packages/dashboard-plugin/tests/Integration/Rest/OverviewSyncAllControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

final class OverviewSyncAllControllerTest extends AbstractSchemaTestCase
{
    public function testAuthRequiredReturns401WhenNoBearerToken(): void
    {
        $request = new WP_REST_Request('POST', '/defyn/v1/overview/sync-all');
        $response = rest_do_request($request);

        $this->assertSame(401, $response->get_status());
    }

    public function testHappyPath202WithFullEnvelopeShape(): void
    {
        $siteA = $this->seedSite(1);
        $siteB = $this->seedSite(1);
        $siteC = $this->seedSite(1);
        $token = $this->token(1);

        $request = new WP_REST_Request('POST', '/defyn/v1/overview/sync-all');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $response = rest_do_request($request);

        $this->assertSame(202, $response->get_status());
        $body = $response->get_data();
        $this->assertSame(3, $body['scheduled_count']);
        $this->assertEqualsCanonicalizing([$siteA, $siteB, $siteC], $body['site_ids']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $body['scheduled_at']
        );
    }

    public function testZeroSitesReturns200WithEmptyArrays(): void
    {
        global $wpdb;
        $token = $this->token(1); // user 1 has zero sites

        $request = new WP_REST_Request('POST', '/defyn/v1/overview/sync-all');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $response = rest_do_request($request);

        $this->assertSame(200, $response->get_status());
        $body = $response->get_data();
        $this->assertSame(0, $body['scheduled_count']);
        $this->assertSame([], $body['site_ids']);

        // No fleet activity event should fire on the zero-sites path
        // (plan-bug trap #4 — no log noise when there's nothing to do).
        $logRows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}defyn_activity_log
             WHERE event_type = %s",
            'overview.sync_all_requested'
        ));
        $this->assertSame([], $logRows);
    }

    public function testRateLimit429AfterEleventhCall(): void
    {
        $this->seedSite(1);
        $token = $this->token(1);

        for ($i = 0; $i < 10; $i++) {
            $request = new WP_REST_Request('POST', '/defyn/v1/overview/sync-all');
            $request->set_header('Authorization', 'Bearer ' . $token);
            $resp = rest_do_request($request);
            $this->assertSame(
                202,
                $resp->get_status(),
                "call #" . ($i + 1) . " should be 202"
            );
        }

        $request = new WP_REST_Request('POST', '/defyn/v1/overview/sync-all');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $resp = rest_do_request($request);
        $this->assertSame(429, $resp->get_status());
        $this->assertSame(
            'overview.rate_limited',
            $resp->get_data()['error']['code'] ?? null
        );
    }

    public function testOwnershipScopingExcludesOtherUsersSites(): void
    {
        $this->seedSite(1);
        $this->seedSite(1);
        $this->seedSite(2); // user 2's site — must NOT be in user 1's fan-out
        $token = $this->token(1);

        $request = new WP_REST_Request('POST', '/defyn/v1/overview/sync-all');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $response = rest_do_request($request);

        $body = $response->get_data();
        $this->assertSame(2, $body['scheduled_count']);
    }

    public function testFanOutSchedulesSyncSiteJobPerSite(): void
    {
        $siteA = $this->seedSite(1);
        $siteB = $this->seedSite(1);
        $token = $this->token(1);

        $request = new WP_REST_Request('POST', '/defyn/v1/overview/sync-all');
        $request->set_header('Authorization', 'Bearer ' . $token);
        rest_do_request($request);

        // Action Scheduler's helper accepts a partial filter; per-arg lookup
        // uses a separate per-id query to confirm the scheduled action exists.
        $argsA = as_get_scheduled_actions([
            'hook' => 'defyn_sync_site',
            'args' => [$siteA],
        ]);
        $argsB = as_get_scheduled_actions([
            'hook' => 'defyn_sync_site',
            'args' => [$siteB],
        ]);
        $this->assertGreaterThanOrEqual(1, count($argsA), "site A should have at least 1 scheduled SyncSite");
        $this->assertGreaterThanOrEqual(1, count($argsB), "site B should have at least 1 scheduled SyncSite");
    }

    public function testActivityEventEmittedWithCorrectDetails(): void
    {
        global $wpdb;
        $siteA = $this->seedSite(1);
        $siteB = $this->seedSite(1);
        $token = $this->token(1);

        $request = new WP_REST_Request('POST', '/defyn/v1/overview/sync-all');
        $request->set_header('Authorization', 'Bearer ' . $token);
        rest_do_request($request);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}defyn_activity_log
             WHERE event_type = %s",
            'overview.sync_all_requested'
        ), ARRAY_A);

        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows[0]['user_id']);
        $this->assertNull($rows[0]['site_id']); // fleet-scoped — plan-bug trap #3

        $details = json_decode((string) $rows[0]['details'], true);
        $this->assertSame(2, $details['scheduled_count']);
        $this->assertEqualsCanonicalizing([$siteA, $siteB], $details['site_ids']);
    }

    private function seedSite(int $userId): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'    => $userId,
            'url'        => 'https://ex' . microtime(true) . rand(0, 9999) . '.com',
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
- `testHappyPath202WithFullEnvelopeShape`
- `testZeroSitesReturns200WithEmptyArrays`
- `testRateLimit429AfterEleventhCall` ← **critical: NOT "Twelfth", NOT "Seventh", NOT "ThirtyFirst"**
- `testOwnershipScopingExcludesOtherUsersSites`
- `testFanOutSchedulesSyncSiteJobPerSite`
- `testActivityEventEmittedWithCorrectDetails`

### Step 2: Run tests to verify they fail

```
cd packages/dashboard-plugin && composer test -- --filter OverviewSyncAllControllerTest
```

Expected: FAIL — `rest_no_route` because the endpoint isn't registered yet.

### Step 3: Create controller + RateLimit method + route registration

Create `packages/dashboard-plugin/src/Rest/OverviewSyncAllController.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\SitesRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P2.6 — POST /defyn/v1/overview/sync-all.
 *
 * Fan-outs the existing P2.1 `defyn_sync_site` Action Scheduler job for
 * every site owned by the authenticated operator. Logs ONE fleet-scoped
 * `overview.sync_all_requested` activity event with the full `site_ids[]`
 * array in `details`. Read-side action — no inventory writes from this
 * endpoint itself; per-site `site.synced` / `*.inventory.synced` triplets
 * surface naturally from each SyncSite execution.
 *
 * Spec: docs/superpowers/specs/2026-06-08-p2-6-sync-all-sites-design.md § 2
 */
final class OverviewSyncAllController
{
    public function __construct(
        private readonly SitesRepository $sites = new SitesRepository(),
        private readonly ActivityLogger $logger = new ActivityLogger(),
    ) {
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        // Defensive STDOUT guard — carry-forward from P2.2 plan-bug #4.
        // as_schedule_single_action itself doesn't echo, but some upstream
        // plugins hook action_scheduler_pre_run_action and DO occasionally
        // echo on the synchronous scheduling path. Same pattern as
        // PluginUpdateController + CoreUpdateController.
        ob_start();
        try {
            $userId = (int) $request->get_param('_authenticated_user_id');
            $sites  = $this->sites->findAllForUser($userId);
            $ids    = array_map(static fn($s) => $s->id, $sites);

            foreach ($ids as $id) {
                as_schedule_single_action(time(), 'defyn_sync_site', [$id]);
            }

            if (count($ids) > 0) {
                $this->logger->log(
                    $userId,
                    null,                                // fleet-scoped — plan-bug trap #3
                    'overview.sync_all_requested',       // exact string — plan-bug trap #2
                    [
                        'scheduled_count' => count($ids),
                        'site_ids'        => array_values($ids),
                    ]
                );
            }

            return new WP_REST_Response(
                [
                    'scheduled_count' => count($ids),
                    'site_ids'        => array_values($ids),
                    'scheduled_at'    => gmdate('Y-m-d H:i:s'),
                ],
                count($ids) > 0 ? 202 : 200
            );
        } finally {
            ob_end_clean();
        }
    }
}
```

In `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php`, after the most recent constants block (`OVERVIEW_*` around lines 79-80), append:

```php
// P2.6 — bulk fan-out endpoint POST /overview/sync-all. Same shape as
// coreAllowMajor from P2.4.1: per-user, 10/HOUR. Tighter than the
// /overview read-only poll (which is 30/MINUTE) because each call
// schedules N AS jobs — runaway bursts would back-pressure the queue.
public const OVERVIEW_SYNC_ALL_LIMIT  = 10;
public const OVERVIEW_SYNC_ALL_WINDOW = HOUR_IN_SECONDS;
```

After the existing `overview(...)` method (around line 360-381), append:

```php
/**
 * Permission callback for POST /overview/sync-all.
 *
 * Per-user, 10/HOUR — same shape as coreAllowMajor from P2.4.1. The
 * bucket key DOES NOT collide with the /overview read poll's bucket
 * (`defyn_rl_overview_%d`) because this method uses a different prefix.
 * Plan-bug trap #1 — DO NOT copy MINUTE_IN_SECONDS from `overview()`.
 *
 * @return true|WP_Error
 */
public static function overviewSyncAll(WP_REST_Request $request)
{
    $authResult = RequireAuth::check($request);
    if (is_wp_error($authResult)) {
        return $authResult;
    }

    $userId = (int) $request->get_param('_authenticated_user_id');

    $key   = sprintf('defyn_rl_overviewSyncAll_%d', $userId);
    $count = (int) (get_transient($key) ?: 0);

    if ($count >= self::OVERVIEW_SYNC_ALL_LIMIT) {
        return new \WP_Error(
            'overview.rate_limited',
            'Too many bulk sync requests. Try again in an hour.',
            ['status' => 429]
        );
    }

    set_transient($key, $count + 1, self::OVERVIEW_SYNC_ALL_WINDOW);
    return true;
}
```

In `packages/dashboard-plugin/src/Rest/RestRouter.php`, the current `/overview` GET registration is at lines 216-220. Append IMMEDIATELY AFTER line 220 (and BEFORE the `/activity` GET registration at line 222 — plan-bug trap #14):

```php
// P2.6 — bulk fan-out: POST /overview/sync-all. Schedules the existing
// `defyn_sync_site` AS job per owned site and emits ONE fleet-scoped
// activity event (site_id=null). RateLimit::overviewSyncAll is 10/HOUR.
register_rest_route(self::NAMESPACE, '/overview/sync-all', [
    'methods'             => 'POST',
    'callback'            => [new OverviewSyncAllController(), 'handle'],
    'permission_callback' => [RateLimit::class, 'overviewSyncAll'],
]);
```

Also add the `use` import at the top of `RestRouter.php` if it isn't already there (it likely uses a flat namespace already — verify the existing `OverviewController` import line and add an `OverviewSyncAllController` import next to it).

### Step 4: Run tests to verify they pass

```
cd packages/dashboard-plugin && composer test -- --filter OverviewSyncAllControllerTest
```
Expected: PASS — all 7 tests green.

Also run the rate-limit baseline to confirm no regression on existing buckets:
```
cd packages/dashboard-plugin && composer test -- --filter RateLimitTest
```
Expected: PASS.

### Step 5: Commit

```bash
git -C "/Users/pradeep/Local Sites/defynWP" add packages/dashboard-plugin/src/Rest/OverviewSyncAllController.php \
        packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php \
        packages/dashboard-plugin/src/Rest/RestRouter.php \
        packages/dashboard-plugin/tests/Integration/Rest/OverviewSyncAllControllerTest.php
git -C "/Users/pradeep/Local Sites/defynWP" commit -m "feat(p2-6): POST /defyn/v1/overview/sync-all + 10/hr RateLimit

Bulk fan-out endpoint — schedules defyn_sync_site AS job per owned
site, emits fleet-scoped overview.sync_all_requested activity event
(site_id=null), returns 202 with {scheduled_count, site_ids[],
scheduled_at} or 200 with empty arrays when user has zero sites.
Defensive ob_start/ob_end_clean per P2.2 plan-bug carry-forward.
Per spec § 2 + plan-bug traps #1-#4."
```

---

## Task 4 — Dashboard v0.7.1 release bump + CORS regression

**Files:**
- Modify: `packages/dashboard-plugin/defyn-dashboard.php`
- Modify: `packages/dashboard-plugin/readme.txt`
- Modify: `packages/dashboard-plugin/composer.json`
- Create: `packages/dashboard-plugin/tests/Integration/Rest/OverviewSyncAllCorsTest.php`

### Step 1: Write the CORS regression test

Create `packages/dashboard-plugin/tests/Integration/Rest/OverviewSyncAllCorsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

final class OverviewSyncAllCorsTest extends AbstractSchemaTestCase
{
    public function testOptionsPreflightOnSyncAllRouteReturnsCorsHeaders(): void
    {
        $request = new WP_REST_Request('OPTIONS', '/defyn/v1/overview/sync-all');
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

Test method name MUST be EXACTLY: `testOptionsPreflightOnSyncAllRouteReturnsCorsHeaders`.

### Step 2: Run test to verify it passes (or fails)

```
cd packages/dashboard-plugin && composer test -- --filter OverviewSyncAllCorsTest
```
Expected: PASS — the dashboard's CORS filter applies to all `defyn/v1` namespaced routes (proven by P2.5's CORS test). Route registration in Task 3 gave it CORS automatically.

If the test FAILS, the CORS filter requires explicit allowlisting. Inspect `src/Rest/Cors.php` (or similar) and add the new route.

### Step 3: Bump version constants

In `packages/dashboard-plugin/defyn-dashboard.php`, change `Version: 0.7.0` → `Version: 0.7.1`. Also update any `DEFYN_DASHBOARD_VERSION` constant if defined in the file.

In `packages/dashboard-plugin/composer.json`, change `"version": "0.7.0"` → `"version": "0.7.1"`.

In `packages/dashboard-plugin/readme.txt`, update `Stable tag: 0.7.0` → `Stable tag: 0.7.1` and prepend this changelog entry above the existing `= 0.7.0 =` block:

```
= 0.7.1 =
* Bulk action on /overview: POST /defyn/v1/overview/sync-all fan-outs the existing SyncSite job for every site the operator owns. 10/hour rate limit. Single overview.sync_all_requested activity event captures the fleet-scoped intent.
* /overview response gains total_sites field for the bulk-action UI counter.
```

### Step 4: Run all dashboard tests

```
cd packages/dashboard-plugin && composer test
```
Expected: ALL PASS (Tasks 1-4 tests + every prior P2.x suite). Pre-existing carry-forward failures listed in `docs/TOMORROW.md` (3 stale `testSchemaVersionConstantIs{N}` + `UninstallTest::testUninstallDropsAllTables`) are still tolerated until a stabilization window.

### Step 5: Commit

```bash
git -C "/Users/pradeep/Local Sites/defynWP" add packages/dashboard-plugin/defyn-dashboard.php \
        packages/dashboard-plugin/readme.txt \
        packages/dashboard-plugin/composer.json \
        packages/dashboard-plugin/tests/Integration/Rest/OverviewSyncAllCorsTest.php
git -C "/Users/pradeep/Local Sites/defynWP" commit -m "chore(p2-6): dashboard v0.7.1 release bump + CORS regression

Bumps plugin version to v0.7.1 and adds a CORS preflight regression
test for the new POST /overview/sync-all route."
```

---

## Task 5 — SPA Zod schemas + MSW handlers + `useSyncAllSites` mutation hook

**Files:**
- Modify: `apps/web/src/types/api.ts`
- Modify: `apps/web/src/test/handlers.ts`
- Create: `apps/web/src/lib/mutations/useSyncAllSites.ts`
- Create: `apps/web/tests/lib/mutations/useSyncAllSites.test.tsx`

### Step 1: Write the failing tests

Create `apps/web/tests/lib/mutations/useSyncAllSites.test.tsx`:

```tsx
import { describe, it, expect, vi } from 'vitest';
import { renderHook, waitFor, act } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { http, HttpResponse } from 'msw';
import { server } from '@/test/server';
import { useSyncAllSites } from '@/lib/mutations/useSyncAllSites';
import React from 'react';

function makeWrapper(qc: QueryClient) {
  return ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
}

describe('useSyncAllSites', () => {
  it('POSTs to /overview/sync-all and parses the 202 envelope', async () => {
    let postedPath: string | null = null;
    server.use(
      http.post('*/wp-json/defyn/v1/overview/sync-all', ({ request }) => {
        postedPath = new URL(request.url).pathname;
        return HttpResponse.json(
          {
            scheduled_count: 3,
            site_ids: [1, 2, 3],
            scheduled_at: '2026-06-08 09:30:42',
          },
          { status: 202 },
        );
      }),
    );

    const qc = new QueryClient({ defaultOptions: { mutations: { retry: false } } });
    const { result } = renderHook(() => useSyncAllSites(), { wrapper: makeWrapper(qc) });

    await act(async () => {
      await result.current.mutateAsync();
    });

    expect(postedPath).toMatch(/\/overview\/sync-all$/);
    expect(result.current.data).toEqual({
      scheduled_count: 3,
      site_ids: [1, 2, 3],
      scheduled_at: '2026-06-08 09:30:42',
    });
  });

  it('invalidates the overview query on success and does NOT invalidate sites', async () => {
    server.use(
      http.post('*/wp-json/defyn/v1/overview/sync-all', () =>
        HttpResponse.json(
          { scheduled_count: 0, site_ids: [], scheduled_at: '2026-06-08 09:30:42' },
          { status: 200 },
        ),
      ),
    );

    const qc = new QueryClient({ defaultOptions: { mutations: { retry: false } } });
    const invalidateSpy = vi.spyOn(qc, 'invalidateQueries');

    const { result } = renderHook(() => useSyncAllSites(), { wrapper: makeWrapper(qc) });

    await act(async () => {
      await result.current.mutateAsync();
    });

    await waitFor(() => {
      expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: ['overview'] });
    });
    // Plan-bug trap #11 — must NOT invalidate sites.
    const sitesCall = invalidateSpy.mock.calls.find(
      ([arg]) => Array.isArray((arg as { queryKey?: unknown }).queryKey)
        && (arg as { queryKey: unknown[] }).queryKey[0] === 'sites',
    );
    expect(sitesCall).toBeUndefined();
  });
});
```

### Step 2: Run tests to verify they fail

```
cd apps/web && pnpm test -- --run useSyncAllSites
```
Expected: FAIL — `useSyncAllSites` doesn't exist.

### Step 3: Extend Zod + MSW + create the hook

In `apps/web/src/types/api.ts`, find the existing `overviewSchema` at line 99. Add `total_sites: z.number().int().nonnegative(),` INSIDE the schema object — place it just BEFORE the closing `generated_at: z.string(),` line:

```ts
// existing fields...
recent_activity: z.array(z.object({ /* ... */ })),
total_sites: z.number().int().nonnegative(),     // ← NEW
generated_at: z.string(),
});
export type Overview = z.infer<typeof overviewSchema>;
```

Then append the new response schema below `type Overview`:

```ts
export const syncAllSitesResponseSchema = z.object({
  scheduled_count: z.number().int().nonnegative(),
  site_ids: z.array(z.number().int()),
  scheduled_at: z.string(),
});
export type SyncAllSitesResponse = z.infer<typeof syncAllSitesResponseSchema>;
```

In `apps/web/src/test/handlers.ts`, find the existing `GET /overview` handler (the one introduced by P2.5 — search for `'*/wp-json/defyn/v1/overview'` returning the envelope with `pending_updates`). Add `total_sites: 0,` to the returned object — place it just before the existing `generated_at` line so the handler's payload matches the new schema:

```ts
http.get('*/wp-json/defyn/v1/overview', () => {
  return HttpResponse.json({
    pending_updates: { /* unchanged */ },
    sites_needing_attention: [],
    recent_activity: [],
    total_sites: 0,                              // ← NEW
    generated_at: '2026-06-07 11:30:00',
  });
}),
```

Add a NEW handler immediately below it for the bulk action:

```ts
http.post('*/wp-json/defyn/v1/overview/sync-all', () => {
  return HttpResponse.json(
    {
      scheduled_count: 0,
      site_ids: [],
      scheduled_at: '2026-06-08 09:30:42',
    },
    { status: 200 },
  );
}),
```

Create `apps/web/src/lib/mutations/useSyncAllSites.ts`:

```ts
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import {
  syncAllSitesResponseSchema,
  type SyncAllSitesResponse,
} from '@/types/api';

/**
 * P2.6 — POSTs to /defyn/v1/overview/sync-all. Server fan-outs the
 * existing SyncSite AS job per owned site and emits ONE fleet-scoped
 * overview.sync_all_requested activity event. On success the
 * `['overview']` query is invalidated so the activity widget shows
 * the new event immediately.
 *
 * Plan-bug trap #11: invalidate ['overview'] only — DO NOT invalidate
 * ['sites']. Per-site state hasn't changed yet (jobs are only queued);
 * each SyncSite execution invalidates its own per-site keys naturally
 * via SyncPluginsService / SyncThemesService / SyncCoreService.
 */
export function useSyncAllSites() {
  const queryClient = useQueryClient();

  return useMutation<SyncAllSitesResponse, Error, void>({
    mutationFn: async () => {
      const data = await apiClient.post<unknown>('/overview/sync-all');
      return syncAllSitesResponseSchema.parse(data);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['overview'] });
    },
  });
}
```

### Step 4: Run tests to verify they pass

```
cd apps/web && pnpm test -- --run useSyncAllSites
```
Expected: PASS — both tests green.

Run the broader suite to ensure the `total_sites` Zod extension didn't break anything that ships synthetic `/overview` payloads:
```
cd apps/web && pnpm test -- --run
```
Expected: PASS for everything that ships its own `server.use(http.get('*/wp-json/defyn/v1/overview', …))` override. **If existing P2.5 tests (`useOverview.test.tsx`) fail with `total_sites: Required`, that's expected and will be fixed inline as part of Task 6 (the route test also needs the same fix).** Note them as known-failing-pre-Task-6 and proceed.

### Step 5: Commit

```bash
git -C "/Users/pradeep/Local Sites/defynWP" add apps/web/src/types/api.ts \
        apps/web/src/test/handlers.ts \
        apps/web/src/lib/mutations/useSyncAllSites.ts \
        apps/web/tests/lib/mutations/useSyncAllSites.test.tsx
git -C "/Users/pradeep/Local Sites/defynWP" commit -m "feat(p2-6): syncAllSitesResponseSchema + MSW + useSyncAllSites hook

overviewSchema gains total_sites (additive). Default MSW /overview
handler emits total_sites: 0. New /overview/sync-all MSW handler
returns synthetic 200. useSyncAllSites POSTs the endpoint, parses
the response with Zod, invalidates ['overview'] on success. Per
spec § 3.2 + plan-bug traps #7-#8 + #11."
```

---

## Task 6 — `SyncAllSitesButton` + `ConfirmSyncAllDialog` + Overview integration

**Files:**
- Create: `apps/web/src/components/overview/ConfirmSyncAllDialog.tsx`
- Create: `apps/web/src/components/overview/SyncAllSitesButton.tsx`
- Modify: `apps/web/src/routes/Overview.tsx`
- Modify: `apps/web/tests/routes/Overview.test.tsx`
- Modify: `apps/web/tests/lib/queries/useOverview.test.tsx` (if existing payloads omit `total_sites`)
- Create: `apps/web/tests/components/overview/ConfirmSyncAllDialog.test.tsx`
- Create: `apps/web/tests/components/overview/SyncAllSitesButton.test.tsx`

### Step 1: Write the failing tests

Create `apps/web/tests/components/overview/ConfirmSyncAllDialog.test.tsx`:

```tsx
import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ConfirmSyncAllDialog } from '@/components/overview/ConfirmSyncAllDialog';

describe('ConfirmSyncAllDialog', () => {
  it('Cancel button has default focus when opened', () => {
    render(
      <ConfirmSyncAllDialog
        open
        totalSites={12}
        onCancel={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );
    const cancel = screen.getByRole('button', { name: /^cancel$/i });
    expect(cancel).toHaveFocus();
  });

  it('primary button label includes the dynamic total_sites count', () => {
    render(
      <ConfirmSyncAllDialog
        open
        totalSites={12}
        onCancel={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );
    expect(screen.getByRole('button', { name: /sync all 12 sites/i })).toBeInTheDocument();
  });
});
```

Create `apps/web/tests/components/overview/SyncAllSitesButton.test.tsx`:

```tsx
import { describe, it, expect } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { http, HttpResponse } from 'msw';
import { server } from '@/test/server';
import { SyncAllSitesButton } from '@/components/overview/SyncAllSitesButton';
import React from 'react';

function renderButton(totalSites: number) {
  const qc = new QueryClient({
    defaultOptions: {
      queries: { retry: false },
      mutations: { retry: false },
    },
  });
  return render(
    <QueryClientProvider client={qc}>
      <SyncAllSitesButton totalSites={totalSites} />
    </QueryClientProvider>,
  );
}

describe('SyncAllSitesButton', () => {
  it('renders the idle "Sync all sites" trigger', () => {
    renderButton(12);
    expect(screen.getByRole('button', { name: /sync all sites/i })).toBeInTheDocument();
    // Dialog should not be visible until clicked.
    expect(screen.queryByRole('button', { name: /sync all 12 sites/i })).not.toBeInTheDocument();
  });

  it('opens the confirm dialog on click', () => {
    renderButton(12);
    fireEvent.click(screen.getByRole('button', { name: /sync all sites/i }));
    expect(screen.getByRole('button', { name: /sync all 12 sites/i })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /^cancel$/i })).toBeInTheDocument();
  });

  it('shows a pending label while the mutation is in flight', async () => {
    // Add a small delay so the in-flight state is observable.
    server.use(
      http.post('*/wp-json/defyn/v1/overview/sync-all', async () => {
        await new Promise((r) => setTimeout(r, 40));
        return HttpResponse.json(
          { scheduled_count: 12, site_ids: Array.from({ length: 12 }, (_, i) => i + 1), scheduled_at: '2026-06-08 09:30:42' },
          { status: 202 },
        );
      }),
    );

    renderButton(12);
    fireEvent.click(screen.getByRole('button', { name: /sync all sites/i }));
    fireEvent.click(screen.getByRole('button', { name: /sync all 12 sites/i }));

    await waitFor(() => {
      expect(screen.getByText(/syncing 12 sites/i)).toBeInTheDocument();
    });
  });
});
```

Extend `apps/web/tests/routes/Overview.test.tsx`. Add `total_sites: 12,` to BOTH the happy-path MSW response (between `recent_activity: […]` and `generated_at: …`) AND update the error-state test's payload only if it asserts a full envelope (it returns 500 with a non-envelope error body, so likely no change is needed — verify by reading the current file). Add a NEW test below the existing two:

```tsx
it('renders the Sync all sites button in the header', async () => {
  server.use(
    http.get('*/wp-json/defyn/v1/overview', () =>
      HttpResponse.json({
        pending_updates: {
          plugins: 0, themes: 0, cores_minor: 0, cores_major: 0, sites_with_any_update: 0,
        },
        sites_needing_attention: [],
        recent_activity: [],
        total_sites: 12,
        generated_at: '2026-06-07 11:30:00',
      })
    )
  );

  renderRoute();
  await waitFor(() =>
    expect(screen.getByRole('button', { name: /sync all sites/i })).toBeInTheDocument()
  );
});
```

Also edit the `useOverview.test.tsx` payloads from P2.5 to include `total_sites: 0,` in each `server.use` block (both the happy-path test and the malformed-test do NOT need to be edited if they ALREADY ship full envelopes — but the malformed test ships `{pending_updates: 'not-an-object'}` deliberately, which is fine; only the happy-path test that ships a full envelope needs `total_sites: 0,` added to it).

### Step 2: Run tests to verify they fail

```
cd apps/web && pnpm test -- --run "SyncAllSitesButton ConfirmSyncAllDialog routes/Overview"
```
Expected: FAIL — components don't exist + the new Overview test asserts a button that isn't rendered yet.

### Step 3: Create the components

Create `apps/web/src/components/overview/ConfirmSyncAllDialog.tsx`:

```tsx
import { useEffect, useRef } from 'react';
import { Button } from '@/components/ui/button';

interface ConfirmSyncAllDialogProps {
  open: boolean;
  totalSites: number;
  onCancel: () => void;
  onConfirm: () => void;
}

/**
 * P2.6 — confirm modal for "Sync all sites now".
 *
 * Read-side action — primary button uses the neutral shadcn `Button`
 * default variant (NOT red/amber). Plan-bug trap #9.
 *
 * Cancel button has default focus per Plan-bug trap #10 — mirror of
 * P2.4 ConfirmUpdateCoreDialog cancelRef pattern.
 *
 * Spec: docs/superpowers/specs/2026-06-08-p2-6-sync-all-sites-design.md § 3.4
 */
export function ConfirmSyncAllDialog({
  open,
  totalSites,
  onCancel,
  onConfirm,
}: ConfirmSyncAllDialogProps) {
  const cancelRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (open) {
      cancelRef.current?.focus();
    }
  }, [open]);

  if (!open) {
    return null;
  }

  const titleId = 'sync-all-sites-confirm-title';

  return (
    <div
      role="alertdialog"
      aria-modal="true"
      aria-labelledby={titleId}
      className="mt-3 rounded-md border border-zinc-200 bg-white p-4 shadow-sm"
    >
      <h3 id={titleId} className="text-sm font-semibold text-zinc-900">
        Sync all {totalSites} sites now?
      </h3>

      <div className="mt-3 space-y-2 text-sm text-zinc-700">
        <p>
          This will queue a fresh sync to every connected site.
        </p>
        <p>
          Offline sites are included — their sync will fail fast and
          surface as a fresh <code className="rounded bg-zinc-100 px-1">sync.failed</code> event in
          the activity feed.
        </p>
      </div>

      <div className="mt-4 flex items-center justify-end gap-2">
        <Button ref={cancelRef} variant="outline" onClick={onCancel}>
          Cancel
        </Button>
        <Button variant="default" onClick={onConfirm}>
          Sync all {totalSites} sites
        </Button>
      </div>
    </div>
  );
}
```

Create `apps/web/src/components/overview/SyncAllSitesButton.tsx`:

```tsx
import { useState } from 'react';
import { RefreshCw } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useSyncAllSites } from '@/lib/mutations/useSyncAllSites';
import { ConfirmSyncAllDialog } from '@/components/overview/ConfirmSyncAllDialog';

interface SyncAllSitesButtonProps {
  totalSites: number;
}

/**
 * P2.6 — header button + confirm dialog + mutation invocation.
 *
 * Idle:     [↻ Sync all sites]
 * Pending:  [⏳ Syncing N sites…] (disabled)
 *
 * Click → confirm dialog → confirm → POST /overview/sync-all → spinner
 * for the brief mutation in-flight window, then revert. The mutation's
 * onSuccess invalidates ['overview'] so the activity widget surfaces
 * the new fleet event immediately on the next poll/refetch.
 *
 * Spec: docs/superpowers/specs/2026-06-08-p2-6-sync-all-sites-design.md § 3
 */
export function SyncAllSitesButton({ totalSites }: SyncAllSitesButtonProps) {
  const [confirmOpen, setConfirmOpen] = useState(false);
  const mutation = useSyncAllSites();

  const handleConfirm = () => {
    setConfirmOpen(false);
    mutation.mutate();
  };

  if (mutation.isPending) {
    return (
      <Button variant="outline" size="sm" disabled>
        <RefreshCw className="mr-1.5 h-3.5 w-3.5 animate-spin" aria-hidden="true" />
        Syncing {totalSites} sites…
      </Button>
    );
  }

  return (
    <>
      <Button
        variant="outline"
        size="sm"
        onClick={() => setConfirmOpen(true)}
        disabled={totalSites === 0}
      >
        <RefreshCw className="mr-1.5 h-3.5 w-3.5" aria-hidden="true" />
        Sync all sites
      </Button>
      <ConfirmSyncAllDialog
        open={confirmOpen}
        totalSites={totalSites}
        onCancel={() => setConfirmOpen(false)}
        onConfirm={handleConfirm}
      />
    </>
  );
}
```

In `apps/web/src/routes/Overview.tsx`, modify the header strip. The current header is:

```tsx
<div className="flex items-baseline justify-between">
  <h1 className="text-xl font-semibold">Overview</h1>
  <p className="text-xs text-muted-foreground">
    Last refreshed: {formatRelativeTime(data.generated_at)}
  </p>
</div>
```

Change it to:

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

Add the import at the top of `Overview.tsx`:

```tsx
import { SyncAllSitesButton } from '@/components/overview/SyncAllSitesButton'
```

### Step 4: Run tests to verify they pass

```
cd apps/web && pnpm test -- --run "SyncAllSitesButton ConfirmSyncAllDialog routes/Overview useSyncAllSites useOverview"
```
Expected: PASS — all new component/hook tests + the extended Overview route tests green.

Run the full suite + lint:
```
cd apps/web && pnpm test -- --run
cd apps/web && pnpm lint
```
Expected: PASS (or same pre-existing carry-forward failures in `SiteDetail.test.tsx` tolerated since P2.4.1).

If `useOverview.test.tsx`'s happy-path test still fails on `total_sites: Required`, add `total_sites: 0,` to its `server.use` payload (the malformed-test ships an intentionally broken payload and doesn't need editing).

### Step 5: Commit

```bash
git -C "/Users/pradeep/Local Sites/defynWP" add apps/web/src/components/overview/SyncAllSitesButton.tsx \
        apps/web/src/components/overview/ConfirmSyncAllDialog.tsx \
        apps/web/src/routes/Overview.tsx \
        apps/web/tests/components/overview/SyncAllSitesButton.test.tsx \
        apps/web/tests/components/overview/ConfirmSyncAllDialog.test.tsx \
        apps/web/tests/routes/Overview.test.tsx \
        apps/web/tests/lib/queries/useOverview.test.tsx
git -C "/Users/pradeep/Local Sites/defynWP" commit -m "feat(p2-6): SyncAllSitesButton + ConfirmSyncAllDialog on Overview header

Idle: ghost outline button with refresh icon + 'Sync all sites' label.
Pending: spinner + 'Syncing N sites…' (disabled).
Confirm dialog: neutral primary (default Button variant — NOT red,
NOT amber, plan-bug trap #9), Cancel default focus mirrors P2.4
ConfirmUpdateCoreDialog (plan-bug trap #10). Per spec § 3."
```

---

## Task 7 — Build zips + 6-step manual smoke matrix

**Files:** none (build + smoke playbook — exact mirror of spec § 5).

Run the spec § 5.2 smoke matrix verbatim. Do NOT proceed to Task 8 unless all 6 steps are green.

- [ ] **Step 1: Confirm all suites green**

```
cd packages/dashboard-plugin && composer test
cd apps/web && pnpm test -- --run
cd apps/web && pnpm lint
```
Expected: ALL PASS (or the same pre-existing carry-forward failures from `docs/TOMORROW.md` — `SchemaVersionMigrationV{4,5}Test`, `UninstallTest::testUninstallDropsAllTables`).

- [ ] **Step 2: Build dashboard zip (v0.7.1)**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin"
composer install --no-dev --classmap-authoritative
rm -f ~/Desktop/defyn-dashboard-v0.7.1-$(date +%Y-%m-%d).zip
zip -rq ~/Desktop/defyn-dashboard-v0.7.1-$(date +%Y-%m-%d).zip . \
  -x "tests/*" "node_modules/*" "*.git*" "phpunit.xml*" "*.lock" \
     "vendor/wordpress/*" "vendor/johnpbloch/*"
ls -lah ~/Desktop/defyn-dashboard-v0.7.1-$(date +%Y-%m-%d).zip
composer install
```
Target zip size: ~552KB. If dramatically larger, the dev-package prune didn't take — re-run from `composer install --no-dev`.

- [ ] **Step 3: Build SPA**

```bash
cd "/Users/pradeep/Local Sites/defynWP/apps/web"
pnpm build
ls -lah dist/index.html dist/assets/*.js | head -3
```
Expected: fresh `dist/` directory.

- [ ] **Step 4: Install on production**

1. Upload the dashboard zip to `defynwp.defyn.agency` via Plugins → Add New → Upload → "Replace current with uploaded version".
2. **Clear MyKinsta cache** (Tools → Clear cache). Plan-bug trap #16 — without this, the new `/overview/sync-all` route may 404 for hours.
3. Push branch + main to origin to trigger Cloudflare auto-deploy:
   ```
   git push origin p2-6-sync-all-sites
   git checkout main
   git merge --ff-only p2-6-sync-all-sites
   git push origin main
   git checkout p2-6-sync-all-sites
   ```
4. Watch Cloudflare Pages for deploy completion (1-3 min).

- [ ] **Step 5: Run the 6-step smoke matrix from spec § 5.2**

Document each step's outcome inline (PASS/FAIL). If any step fails, STOP — file `fix(p2-6):` commits before tagging.

```bash
TOKEN=$(curl -s -X POST https://defynwp.defyn.agency/wp-json/defyn/v1/auth/login \
  -H "Content-Type: application/json" \
  --data '{"email":"pradeep@defyn.com.au","password":"DefynWP-ifirCh5pXm5bTOj0"}' \
  | python3 -c "import sys,json; print(json.load(sys.stdin)['access_token'])")
```

| # | Action | Expected |
|---|---|---|
| 1 | `curl -X POST -H "Authorization: Bearer $TOKEN" "https://defynwp.defyn.agency/wp-json/defyn/v1/overview/sync-all"` | 202 + `{scheduled_count: 1, site_ids: [1], scheduled_at: "..."}` for SmartCoding |
| 2 | Same POST WITHOUT `Authorization` header | 401 `auth.missing_token` (or `auth.required` — whichever the existing RequireAuth emits) |
| 3 | 11× POST from same user within 1 hour (use `?_=$RANDOM` query string per step to defeat Kinsta edge cache) | 11th returns 429 `overview.rate_limited` |
| 4 | `curl -H "Authorization: Bearer $TOKEN" "https://defynwp.defyn.agency/wp-json/defyn/v1/activity?per_page=5"` after step 1 | `overview.sync_all_requested` event present with `details: {scheduled_count: 1, site_ids: [1]}` and `site_id: null` |
| 5 | SPA at `/overview` → click "Sync all sites" → confirm dialog → click "Sync all 1 sites" | Brief spinner, then idle. Within ~60-90s the activity widget shows `overview.sync_all_requested` + downstream `site.synced` / `plugin_inventory.synced` triplet for SmartCoding |
| 6 | SPA: same flow but press Cancel on the dialog | Dialog closes, no POST fires (verify via DevTools Network), no new activity event |

- [ ] **Step 6: Cleanup**

None. Bulk sync is a read-side fan-out — SmartCoding's `last_sync_at` advances naturally and no synthetic state was introduced. The 10/hr rate-limit transient expires on its own within an hour.

If any step failed, file `fix(p2-6): …` commits before re-running from the failing step.

- [ ] **Step 7: Commit (only if any fix commits were needed)**

If smoke was green on the first run, this task creates no commits.

---

## Task 8 — Tag + push

**Files:** none (git tag).

ONLY run this task after Task 7's smoke matrix is fully green. **NEVER push the tag if any smoke step failed.**

- [ ] **Step 1: Verify all suites green + working tree clean**

```bash
cd "/Users/pradeep/Local Sites/defynWP/packages/dashboard-plugin" && composer test
cd "/Users/pradeep/Local Sites/defynWP/apps/web" && pnpm test -- --run
cd "/Users/pradeep/Local Sites/defynWP/apps/web" && pnpm lint
cd "/Users/pradeep/Local Sites/defynWP" && git status
```
Expected: ALL PASS (or same carry-forward failures) + `nothing to commit, working tree clean`.

- [ ] **Step 2: Create the annotated tag**

```bash
cd "/Users/pradeep/Local Sites/defynWP"
git tag -a p2-6-sync-all-sites-complete -m "P2.6 — Sync all sites bulk action shipped

- Dashboard v0.7.1: new POST /defyn/v1/overview/sync-all endpoint
  (10/HOUR per user) — fan-outs the existing P2.1 SyncSite AS job
  for every site the operator owns. Emits ONE fleet-scoped
  overview.sync_all_requested activity event (site_id=null) with
  scheduled_count + site_ids[] in details. /overview gains additive
  total_sites field via new SitesRepository::countAllForUser.
  Returns 202 when sites > 0, 200 with empty arrays when zero.
- SPA: SyncAllSitesButton on Overview header (ghost outline with
  refresh icon); ConfirmSyncAllDialog (neutral default-variant
  primary, Cancel default focus); useSyncAllSites mutation hook
  invalidates ['overview'] on success.
- No connector changes (connector stays at v0.1.7).
- No schema changes (schema stays at v6).
- Spec: docs/superpowers/specs/2026-06-08-p2-6-sync-all-sites-design.md
"
```

- [ ] **Step 3: Push the tag**

```bash
git push origin p2-6-sync-all-sites-complete
```

- [ ] **Step 4: Update MEMORY**

Append a one-line entry to `~/.claude/projects/-Users-pradeep-Local-Sites-defynWP/memory/project_defyn_overview.md`:

> "P2.6 (Sync all sites bulk action) COMPLETE 2026-06-08 — tag `p2-6-sync-all-sites-complete`, dashboard v0.7.1 live in prod. POST /defyn/v1/overview/sync-all fan-outs SyncSite AS job per owned site, emits fleet-scoped overview.sync_all_requested event. Connector unchanged at v0.1.7. Schema unchanged at v6. Next: P2.7 — bulk plugin updates across fleet."

Any new plan-bug lessons surfaced during execution go into `MEMORY.md`.

---

## Self-review — coverage against spec

Walking the spec sections to confirm every requirement maps to a task:

- **Spec § 1 architecture overview** — covered collectively across Tasks 1-6.
- **Spec § 2.1 route + auth + rate limit + cache headers** — Task 3.
- **Spec § 2.2 response shape (202 / 200 with same envelope)** — Task 3 controller + Task 3 tests `testHappyPath202` + `testZeroSitesReturns200`.
- **Spec § 2.3 controller flow (PHP pseudocode)** — Task 3 controller.
- **Spec § 2.4 file structure (5 files)** — Tasks 1-3 + Task 4 release files.
- **Spec § 2.5 activity event contract** — Task 3 controller + test `testActivityEventEmittedWithCorrectDetails`.
- **Spec § 2.6 `/overview` total_sites extension** — Tasks 1 (countAllForUser) + 2 (OverviewService) + 5 (Zod overviewSchema + MSW).
- **Spec § 2.7 dashboard tests (~7)** — Task 3's 7 tests. Total = 7 controller tests + 2 repo tests (Task 1) + 1 service test (Task 2) = 10 PHP tests.
- **Spec § 2.8 version bump** — Task 4.
- **Spec § 3.1 SPA placement (header column right side)** — Task 6 `Overview.tsx` integration.
- **Spec § 3.2 SPA files** — Tasks 5-6.
- **Spec § 3.3 button states (idle, pending, success, error)** — Task 6 button component (Idle + Pending). The spec's "Success (just fired, < 3s)" sub-state is rendered implicitly by the brief mutation lifecycle (the mutation resolves and the button reverts to idle within ms — explicit success-label timer is OUT OF SCOPE because the activity feed is the durable confirmation). Toast on error is surfaced via `useSyncAllSites`'s `error` field — picked up by the existing global `ApiError` handler in `App.tsx`; no explicit toast wiring needed.
- **Spec § 3.4 confirm dialog content** — Task 6 `ConfirmSyncAllDialog`.
- **Spec § 3.5 SPA tests (~5)** — Tasks 5-6: useSyncAllSites (2) + ConfirmSyncAllDialog (2) + SyncAllSitesButton (3) + Overview integration (1) = 8 SPA tests.
- **Spec § 4 testing strategy (~12 total)** — Tasks 1-6 deliver 10 PHP + 8 SPA = 18 (above target).
- **Spec § 5 manual smoke flow (6 steps)** — Task 7.
- **Spec § 6 out of scope** — N/A (informational).
- **Spec § 7 plan-author notes (12 plan-bug traps)** — all 17 surfaced in workflow conventions (the spec's 12 + 5 carried over from prior P2.x phases relevant to this plan).
- **Spec § 8 acceptance criteria** — Task 8.

All sections covered. ✅

## Self-review — placeholder scan

Searched the plan for `TBD`, `TODO`, `implement later`, `fill in`, `similar to Task N`, "add appropriate validation/error handling" — none present in concrete code blocks. ✅

## Self-review — type consistency

- `SyncAllSitesResponse` shape `{scheduled_count: int, site_ids: int[], scheduled_at: string}` consistent across Task 3 PHP controller, Task 3 PHP tests, Task 5 Zod, Task 5 MSW handlers, Task 5 hook tests, Task 6 SPA tests.
- `total_sites: int` consistent across Task 1 (PHP `countAllForUser`), Task 2 (PHP `OverviewService.compose`), Task 5 (Zod additive + MSW default), Task 5 hook tests, Task 6 SPA prop typing, Task 6 component tests.
- `OVERVIEW_SYNC_ALL_LIMIT = 10` + `HOUR_IN_SECONDS` window consistent in Task 3 RateLimit constants + test name `testRateLimit429AfterEleventhCall` + plan-bug trap #1 + spec § 2.1 + smoke step 3.
- Activity event string `overview.sync_all_requested` consistent across Task 3 controller, Task 3 test, plan-bug trap #2, spec § 2.5, smoke step 4. `site_id = null` consistent across the same set.
- Mutation hook query invalidation key `['overview']` consistent across Task 5 hook, Task 5 hook test (positive assertion), Task 5 hook test (negative assertion `['sites']` NOT invalidated), plan-bug trap #11.
- `useSyncAllSites()` hook signature `() => UseMutationResult<SyncAllSitesResponse, Error, void>` consistent across Task 5 export, Task 5 hook test, Task 6 button consumer.

No drift. ✅

---

## Execution handoff

**Plan complete and saved to `docs/superpowers/plans/2026-06-08-p2-6-sync-all-sites.md`. Two execution options:**

**1. Subagent-Driven (recommended)** — I dispatch a fresh subagent per task, two-stage review (spec compliance + code quality) between tasks, same-session fast iteration. This is what every prior P2.x phase used.

**2. Inline Execution** — Execute tasks in this session using the executing-plans skill, batch execution with checkpoints for review.

**Which approach?**
