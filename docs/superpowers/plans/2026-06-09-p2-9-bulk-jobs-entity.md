# P2.9 — Bulk-jobs entity (cancel/resume/history) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wrap the P2.7 + P2.8 destructive bulk operations in a tracked `BulkJob` domain entity. Every `POST /overview/bulk-update-plugins` and `POST /overview/bulk-update-themes` creates a parent row in `wp_defyn_bulk_jobs` + N child rows in `wp_defyn_bulk_job_items` (one per scheduled pair). Operator gets per-pair lifecycle visibility (`queued → started → succeeded|failed|cancelled`) via a new SPA `/jobs` list route + `/jobs/:id` detail route, cancel-queued, per-item retry, and bulk retry-failed. Schema v6 → v7. Dashboard v0.8.1 → v0.9.0. Connector unchanged at v0.1.7.

**Architecture:** Two new tables (parent `wp_defyn_bulk_jobs` + child `wp_defyn_bulk_job_items`). `BulkJobsRepository` owns CRUD + lifecycle marks + automatic job-timestamp refresh; `BulkJobAggregator` is the pure-function derived-state helper (counts-by-state + 4 job-level states). Five new REST endpoints under `/defyn/v1/jobs*` (list 30/MIN, detail 30/MIN, cancel 5/HR, retry-item 20/HR, retry-failed 5/HR — all per-user static `RateLimit` methods chaining `RequireAuth::check`). The two existing bulk controllers create the job + items BEFORE the AS fan-out and schedule each `defyn_update_site_plugin` / `defyn_update_site_theme` action with a NEW 4th arg `$jobItemId`; `UpdateSitePlugin::handle` + `UpdateSiteTheme::handle` gain `int $jobItemId = 0` and mark item lifecycle at every terminal branch (409-as-success counts as succeeded; retry-rescheduling propagates the item id without marking terminal). SPA gets `/jobs` + `/jobs/:id` routes, a `JobsNavLink` (with active-count badge) in the Overview header (plan-correction: no sidebar exists), adaptive-polling hooks (10s list / 5s detail / 30s count), 3 mutation hooks, and navigate-to-`/jobs/{job_id}` on bulk-confirm success.

**Tech Stack:** PHP 8.1+ (PHPUnit, `WP_UnitTestCase` / `AbstractSchemaTestCase`, Symfony MockHttpClient for AS-job tests), WordPress REST API + dbDelta + Action Scheduler, React 18 + TypeScript + TanStack Query v5 (`refetchInterval` receives the Query object) + react-router-dom v6 + Zod + Tailwind + shadcn/ui (`Button` variants `default`/`outline`/`ghost` only) + Vitest + React Testing Library + MSW.

**Spec:** [`docs/superpowers/specs/2026-06-09-p2-9-bulk-jobs-entity-design.md`](../specs/2026-06-09-p2-9-bulk-jobs-entity-design.md)

---

## Workflow conventions

- **Branch:** already on **`p2-9-bulk-jobs-entity`** (current tip `f3b1757` — the committed P2.9 spec). Confirm with `git branch --show-current` before starting.
- **Each Task = one atomic commit.**
- **Test discipline (TDD):** Step 1 writes the failing test. Step 2 runs it and confirms it fails. Step 3 writes the implementation. Step 4 confirms it passes. Step 5 commits.
- **Test runners:**
  - Dashboard PHP: `cd packages/dashboard-plugin && composer test`
  - SPA: `cd apps/web && pnpm test -- --run`
- **Commit message format:** `<type>(p2-9): <description>` where `<type>` ∈ {feat, fix, refactor, docs, test, chore}.
- **All edits adhere to** `~/.claude/rules/common/coding-style.md` — immutability, KISS, DRY, YAGNI, error handling, no `console.log` / `var_dump` / `print_r`.
- **No connector changes.** Connector stays at **v0.1.7**. Smoke does NOT require connector reinstall.
- **Schema v6 → v7.** Two additive CREATE TABLEs, no destructive ALTERs. Self-heal on `plugins_loaded` migrates prod transparently.
- **Dashboard v0.8.1 → v0.9.0** (minor bump — new domain entity).

### Plan-bug traps to internalise before writing any code

Guardrails #1–#22 are spec § 6 verbatim; #23+ are NEW traps discovered while reality-checking the codebase for this plan.

1. **shadcn `Button` has NO `destructive` variant** (verified: `apps/web/src/components/ui/button.tsx` lines 10–14 — only `default`/`outline`/`ghost`). Cancel + Retry buttons use default-styled primary (non-destructive — operator confirm IS the safety). No red className needed in P2.9 (cancel-queued and retry are non-destructive).
2. **Schema v6 → v7 migration is additive** (2 new CREATE TABLEs, no destructive ALTERs). Schema self-heal on `plugins_loaded` handles "Replace current" gracefully (carry-forward from P2.2.1).
3. **AS hook arg count change:** `Plugin::boot` registrations for `UpdateSitePlugin::HOOK` + `UpdateSiteTheme::HOOK` MUST be bumped from 3 args to 4 (`add_action(..., 10, 4)`). Pre-v0.9.0 in-flight AS rows have 3 args — the closure param `int $jobItemId = 0` default means old rows don't fatal.
4. **`as_unschedule_action` requires EXACT args match.** Cancel controller MUST pass the same 4-tuple used at schedule time: `[$siteId, $slug, 0, $jobItemId]` plus the `'defyn'` group.
5. **`BulkJobsRepository::createItems` MUST use a single multi-row INSERT.** Test `testCreateItemsUsesSingleInsertStatement` asserts the `$wpdb->num_queries` delta is exactly **2** for 5 pairs (1 multi-row INSERT + 1 read-back SELECT) — i.e. the query count never scales with pair count.
6. **Item state machine enforcement:** `markItemCancelled` only transitions from `queued` (guarded `WHERE ... AND state = 'queued'`). Calling cancel on a `started`/`succeeded`/`failed` item is a silent no-op.
7. **`findByIdForUser` returns null for foreign jobs.** REST controllers return 404 `jobs.not_found` (not 403) to avoid an existence info-leak.
8. **`refreshJobTimestamps` is automatic.** Every `markItem*` + `resetItemForRetry` call internally triggers `refreshJobTimestamps` so `bulk_jobs.started_at`/`completed_at` stay accurate with zero controller-side bookkeeping. Retry re-queueing also CLEARS a previously-set `completed_at`.
9. **SPA adaptive polling cadences are CRITICAL:** `useJobsList` 10s while any job `queued|in_progress`, else no polling. `useJobDetail` 5s while any item `queued|started`, else no polling. `useJobsCount` 30s unconditionally.
10. **Mutation hooks invalidate `['job', id]` + `['jobs']` (prefix — hits every `['jobs', status, page]` key) + `['jobsCount']` on success. NOT `['sites']`** (per-site state hasn't changed). Carry-forward reasoning from P2.6/P2.7/P2.8.
11. **Navigate-on-success in BulkUpdate{Plugins,Themes}Button:** `mutation.mutate(..., { onSuccess: (data) => { if (data.job_id !== null) navigate('/jobs/' + data.job_id); } })`. Behavior tested in the BUTTON component, not the dialog.
12. **`job_id` is null when scheduled_count = 0.** No parent job row is inserted when nothing was scheduled (matches the activity-event guardrail from P2.7/P2.8).
13. **Cancel responds 200 OK (not 202)** — cancellation is synchronous + complete at response time. Retry endpoints respond 202 (async re-queue), except retry-failed no-op which is 200.
14. **404 `jobs.not_found` for missing OR foreign jobs.** Item-level: `jobs.item_not_found` (404) + `jobs.item_not_retryable` (400 when state ≠ `failed`).
15. **Test isolation:** any test class extending `AbstractSchemaTestCase` that seeds `defyn_bulk_jobs` MUST call `$this->freshlyActivate('defyn_bulk_jobs')` + `$this->freshlyActivate('defyn_bulk_job_items')` + explicit `DELETE FROM` both tables in `setUp()`. `WP_UnitTestCase` transaction rollback does NOT cover custom plugin tables (P2.6 plan-bug #1 carry-forward).
16. **Test fixtures must seed NOT NULL columns.** `defyn_bulk_jobs`: `user_id`, `kind`, `created_at` (counts default 0). `defyn_bulk_job_items`: `job_id`, `site_id`, `resource_slug`, `created_at` (`state` defaults `'queued'`). `defyn_sites`: `user_id`, `url`, `label`, `status`, `created_at`, `updated_at` (verified against existing test seeders). `defyn_site_plugins`: `site_id`, `slug`, `name`, `last_seen_at`, `created_at`, `updated_at` (`src/Schema/SitePluginsTable.php` lines 24–43). `defyn_site_themes`: same set (`src/Schema/SiteThemesTable.php` lines 29–49).
17. **Zip-build exclusion list — NEVER strip `vendor/symfony/*`** (P2.8 MEMORY entry). After `composer install --no-dev --classmap-authoritative`, ALL remaining vendor/ contents are required by prod autoload. Exclude ONLY: `tests/*`, `*wp-tests-config.php`, `.phpunit.result.cache`, `test-output.log`, `phpunit.xml`, `composer.lock`, `.github/*`, `.gitignore`. Verify the zip contains `vendor/symfony/deprecation-contracts/function.php` + `vendor/symfony/polyfill-php83/bootstrap.php`.
18. **Kinsta cache discipline:** after every WP-Admin "Replace current" install, click MyKinsta → Tools → Clear cache (busts OPcache + page cache + Redis).
19. **`BulkJobAggregator` is pure-function** — no I/O, no DB, no globals. Used by BOTH the list controller (via grouped counts) AND the detail controller (via item rows).
20. **State chip colors live ONLY in `JobStateChip.tsx`** — single source for 5 item states + 4 job states. Used by list row + detail row + header.
21. **Per-row Retry is one-click (no confirmation dialog).** Bulk Retry-all uses a neutral default-styled dialog.
22. **Sidebar badge hides when active count = 0.** No "(0)" suffix.
23. **REALITY: `RateLimit` is an ALL-STATIC class** (`packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php`). Every method is `public static function name(WP_REST_Request $request)` returning `true|\WP_Error`, chains `RequireAuth::check($request)` first, and uses transient keys `defyn_rl_<bucketName>_<userId>`. The P2.8 *plan document* showed instance methods + closures — the actual shipped code is static. Write static methods + `[RateLimit::class, 'method']` permission_callbacks ONLY.
24. **REALITY: `RestRouter` has NO use-imports for same-namespace controllers** — controllers in `Defyn\Dashboard\Rest` are referenced bare (`new JobsListController()`). Routes use `[RateLimit::class, 'method']` directly as `permission_callback`; dynamic segments use `(?P<id>\d+)` regex.
25. **REALITY: test auth is `(new TokenService(DEFYN_JWT_SECRET))->issueAccess($userId)`** (`src/Auth/TokenService.php` line 36 ctor / line 45 issueAccess) plus a `if (!defined('DEFYN_JWT_SECRET')) define(...)` guard in `setUp()`. NOT `JwtTokenIssuer` (doesn't exist).
26. **REALITY: NO sidebar exists in the SPA.** `src/App.tsx` renders bare `<Routes>`; there is no `Sidebar.tsx`, no shared nav shell. Spec § 3.2's "existing left sidebar" is a spec-vs-reality divergence. PLAN-CORRECTION: `JobsNavLink` lives at `src/components/nav/JobsNavLink.tsx` and renders in the **Overview header** (next to the `<h1>`); `Jobs.tsx` carries a "Back to Overview" link and `JobDetail.tsx` a "Back to Jobs" link so the chain is navigable.
27. **REALITY: `apiClient` prepends `API_BASE = '/api/defyn/v1'`** (`src/lib/apiClient.ts` line 11). Hook paths are `/jobs?...`, `/jobs/${id}` — NEVER `/defyn/v1/jobs` (double prefix). ALSO: spec § 3.6's `useJobsList` example path `/overview/jobs` is WRONG — the registered route is `/jobs`.
28. **REALITY: TanStack Query v5 (`^5.100.5`)** — `refetchInterval` callback receives the **Query object**: `(query) => { const data = query.state.data; ... }` (pattern at `src/lib/queries/useSite.ts` line 18). The spec's `(data) => ...` examples are v4 style — do not copy them.
29. **dbDelta conventions:** `PRIMARY KEY  (id)` with TWO spaces; KEY lines without DESC (spec's `idx_user_created (user_id, created_at DESC)` becomes plain `(user_id, created_at)` — DESC index syntax does not survive dbDelta's parser; ordering is enforced in the query's `ORDER BY` instead).
30. **Adding required `job_id` to the bulk-response Zod schemas breaks every MSW fixture that omits it.** Task 12 MUST update, in the SAME commit: both default handlers in `src/test/handlers.ts`, the inline overrides in `tests/lib/mutations/useBulkUpdatePlugins.test.tsx` + `tests/lib/mutations/useBulkUpdateThemes.test.tsx`, and the inline POST override in `tests/components/overview/BulkUpdatePluginsButton.test.tsx`.
31. **`useNavigate` requires Router context.** Task 18 adds it to both bulk buttons → ALL existing tests in `BulkUpdatePluginsButton.test.tsx` + `BulkUpdateThemesButton.test.tsx` must gain a `<MemoryRouter>` wrapper in the same commit or they fail with "useNavigate() may be used only in the context of a <Router>".
32. **`useJobsCount` derives from the list endpoint** (`GET /jobs?status=active&page=1&per_page=1` → `total`). NO dedicated count endpoint (spec § 3.5 allows "derive from list" — YAGNI).
33. **Modified bulk controllers restructure the loop:** the validation loop ONLY partitions into `$scheduled`/`$skipped`; job + items are created AFTER the loop (only when `count($scheduled) > 0`); the AS fan-out iterates the **enriched** pairs from `createItems` so each action carries its `item_id`. Do NOT schedule inside the validation loop any more.
34. **Existing fan-out tests assert 3-arg AS args** (`OverviewBulkUpdatePluginsControllerTest::testFanOutSchedulesPerPair` line 161 uses `'args' => [$siteA, 'akismet', 0]`). Task 9 must update them to the 4-tuple (read the item id back from `wp_defyn_bulk_job_items`).
35. **`ErrorResponse::create(int $status, string $code, string $message)`** is the error envelope helper (`src/Rest/Responses/ErrorResponse.php` line 15) — use it for 400/404 controller errors, never raw `WP_Error` from controllers.

### Pre-existing carry-forward failures (TOLERATE — do NOT count as new regressions)

PHP (3, since P2.4.1):
- `SchemaVersionMigrationV4Test::testSchemaVersionConstantIsFour`
- `SchemaVersionMigrationV5Test::testSchemaVersionConstantIsFive`
- `UninstallTest::testUninstallDropsAllTables`

SPA (4, since P2.4.1):
- `tests/SiteDetail.test.tsx` × 2
- `tests/SiteCoreCard.test.tsx > idle update-available renders version diff + Update button`
- `tests/SiteCoreCard.test.tsx > failed state renders red banner + Retry button + tooltip on hover`

Note: `SchemaVersionMigrationV4Test`/`V5Test` assert the constant equals 4/5 — they were already failing at 6 and will keep failing at 7. Do not "fix" them in this phase.

### Existing-code anchors (read these before starting any task)

- `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php` — ALL-STATIC. P2.8's `BULK_THEME_UPDATE_LIMIT/WINDOW` constants at lines 110–111; `bulkThemeUpdate()` method at lines 558–580 is the exact shape to mirror for the 5 new buckets. Transient key pattern `sprintf('defyn_rl_<bucket>_%d', $userId)`.
- `packages/dashboard-plugin/src/Rest/RestRouter.php` — `register()` lines 57–272. P2.8 routes at lines 253–266; `/activity` at line 268. The 5 new `/jobs*` registrations slot between them. Permission pattern `[RateLimit::class, 'method']`; dynamic segment `'/sites/(?P<id>\d+)'` at line 92.
- `packages/dashboard-plugin/tests/Integration/Rest/OverviewBulkUpdateThemesControllerTest.php` — THE controller-test template: `DEFYN_JWT_SECRET` guard + `freshlyActivate` + `SET autocommit = 1` + `DELETE FROM` + `delete_transient` loop in `setUp()` (lines 25–53), `seedSite()` with `status` column (lines 247–260), `token()` via `TokenService::issueAccess` (lines 279–282), `do_action('rest_api_init')`.
- `packages/dashboard-plugin/src/Jobs/UpdateSitePlugin.php` — `handle(int $siteId, string $slug, int $attempt = 0)` at line 48; success branch lines 85–96; 409 `plugins.update_in_progress` retry lines 100–128 (reschedule at line 121); final-failure lines 138–146. Quoted in full in Task 10.
- `packages/dashboard-plugin/src/Jobs/UpdateSiteTheme.php` — same shape PLUS the 409 `themes.no_update_available` success-by-other-means branch at lines 131–142 (MUST count as item-succeeded).
- `packages/dashboard-plugin/src/Plugin.php` — `add_action(UpdateSitePlugin::HOOK, ...)` 3-arg registration at lines 75–77; `UpdateSiteTheme::HOOK` at lines 87–89. Task 10 bumps both to 4.
- `packages/dashboard-plugin/src/Schema/SiteThemesTable.php` — dbDelta template: `PRIMARY KEY  (id)` double-space (line 45), `$wpdb->get_charset_collate()`, `SchemaTable` interface (`tableName()` + `createSql()`).
- `packages/dashboard-plugin/src/Activation.php` — `SCHEMA_VERSION = 6` (line 24), `TABLES` const (lines 32–38), `ensureSchema()` (lines 58–89) ends with `SchemaVersion::set(max(...))`.
- `packages/dashboard-plugin/src/Uninstaller.php` — iterates `Activation::TABLES`, so the 2 new tables are auto-covered with no uninstall change.
- `packages/dashboard-plugin/src/Rest/OverviewBulkUpdatePluginsController.php` + `OverviewBulkUpdateThemesController.php` — current handle() quoted in Task 9; AS scheduling currently inside the validation loop with 3 args (`[$siteId, $slug, 0]`, group `'defyn'`).
- `packages/dashboard-plugin/src/Rest/Responses/ErrorResponse.php` — `ErrorResponse::create($status, $code, $message)`.
- `packages/dashboard-plugin/src/Services/ActivityLogger.php` — `log(?int $userId, ?int $siteId, string $eventType, ?array $details = null, ?string $ipAddress = null)`.
- `packages/dashboard-plugin/src/Services/SitesRepository.php` — `findByIdForUser(int $id, int $userId): ?Site` (line 71).
- `packages/dashboard-plugin/tests/Integration/Jobs/UpdateSiteThemeTest.php` — AS-job test template: `makeActiveSite()` with real Ed25519 keypair + Vault (lines 38–55), `MockHttpClient`/`MockResponse` injection, `pre_as_schedule_single_action` filter capture (lines 158–161).
- `packages/dashboard-plugin/tests/Integration/Schema/SiteThemesTableTest.php` + `SchemaVersionMigrationV6Test.php` — schema-test templates (`describeTable`, `assertHasIndex`, constant assertions).
- `packages/dashboard-plugin/tests/Integration/Rest/OverviewBulkUpdateThemesCorsTest.php` — CORS-test template: drives `Cors::apply(false, $response, $request, $server)` directly (lines 40–58).
- `apps/web/src/App.tsx` — bare `<Routes>` (lines 11–25); add 2 routes inside `<Route element={<RequireAuth />}>`.
- `apps/web/src/lib/apiClient.ts` — `API_BASE = '/api/defyn/v1'` (line 11); `apiClient.get/post`.
- `apps/web/src/lib/queries/usePendingThemeUpdates.ts` + `src/lib/mutations/useBulkUpdateThemes.ts` — hook templates (queryKey, Zod parse, invalidation pattern).
- `apps/web/src/lib/queries/useSite.ts` line 18 — TanStack v5 `refetchInterval: (query) => ...` pattern.
- `apps/web/src/components/overview/BulkUpdatePluginsButton.tsx` + `BulkUpdateThemesButton.tsx` — current `handleConfirm` (closes dialog, then `mutation.mutate({ updates: selectedPairs })`); Task 18 adds the `onSuccess` navigate.
- `apps/web/src/components/overview/ConfirmSyncAllDialog.tsx` — neutral confirm-dialog template (`role="alertdialog"`, `cancelRef` focus-on-open, outline Cancel + default primary).
- `apps/web/src/components/overview/PendingThemeUpdatesGroup.tsx` — per-site group template.
- `apps/web/src/types/api.ts` — Zod conventions; P2.7/P2.8 bulk schemas at lines 135–201 (Task 12 extends both response schemas).
- `apps/web/src/test/handlers.ts` — MSW URL pattern `*/wp-json/defyn/v1/...`; P2.8 handlers at lines 618–639.
- `apps/web/tests/lib/queries/usePendingThemeUpdates.test.tsx` + `tests/lib/mutations/useBulkUpdateThemes.test.tsx` — hook-test templates (`makeWrapper`, `server.use`, invalidateSpy).
- `apps/web/src/lib/formatRelativeTime.ts` — relative timestamps for rows/headers.
- `apps/web/src/routes/Overview.tsx` — header at lines 47–57 (JobsNavLink integration point).

---

## File structure overview

### Dashboard plugin (v0.9.0) — new files

| Path | Responsibility |
|---|---|
| `src/Schema/BulkJobsTable.php` | CREATE TABLE parent |
| `src/Schema/BulkJobItemsTable.php` | CREATE TABLE child |
| `src/Services/BulkJobAggregator.php` | Pure-function counts + derived job state |
| `src/Services/BulkJobsRepository.php` | CRUD + lifecycle marks + timestamp refresh |
| `src/Rest/JobsListController.php` | GET /jobs (+ shared `presentJob()` helper) |
| `src/Rest/JobsDetailController.php` | GET /jobs/{id} with resource-resolution JOIN |
| `src/Rest/JobsCancelController.php` | POST /jobs/{id}/cancel |
| `src/Rest/JobsRetryItemController.php` | POST /jobs/{id}/items/{item_id}/retry |
| `src/Rest/JobsRetryFailedController.php` | POST /jobs/{id}/retry-failed |
| `tests/Integration/Schema/BulkJobsTableTest.php` | 4 tests |
| `tests/Integration/Schema/BulkJobItemsTableTest.php` | 4 tests |
| `tests/Integration/Schema/SchemaVersionMigrationV7Test.php` | 3 tests |
| `tests/Unit/Services/BulkJobAggregatorTest.php` | 8 tests |
| `tests/Integration/Services/BulkJobsRepositoryTest.php` | 9 tests (create/find) |
| `tests/Integration/Services/BulkJobsRepositoryLifecycleTest.php` | 13 tests (marks/refresh/filters) |
| `tests/Integration/Rest/JobsListControllerTest.php` | 7 tests |
| `tests/Integration/Rest/JobsDetailControllerTest.php` | 6 tests |
| `tests/Integration/Rest/JobsCancelControllerTest.php` | 5 tests |
| `tests/Integration/Rest/JobsRetryControllersTest.php` | 7 tests |
| `tests/Integration/Jobs/UpdateSitePluginBulkJobItemTest.php` | 6 tests |
| `tests/Integration/Jobs/UpdateSiteThemeBulkJobItemTest.php` | 5 tests |
| `tests/Integration/Rest/JobsRoutesCorsTest.php` | 5 tests |

### Dashboard plugin — modified files

| Path | What changes |
|---|---|
| `src/Activation.php` | 2 new TABLES entries + `SCHEMA_VERSION = 7` |
| `src/Rest/Middleware/RateLimit.php` | 5 new constant pairs + 5 new static methods |
| `src/Rest/RestRouter.php` | 5 new routes between `/overview/bulk-update-themes` and `/activity` |
| `src/Rest/OverviewBulkUpdatePluginsController.php` | Create job + items, 4-arg fan-out, `job_id` in response |
| `src/Rest/OverviewBulkUpdateThemesController.php` | Same for themes |
| `src/Jobs/UpdateSitePlugin.php` | `$jobItemId = 0` param + lifecycle marks |
| `src/Jobs/UpdateSiteTheme.php` | Same + 409-no-update counts as succeeded |
| `src/Plugin.php` | Both AS hook registrations 3 → 4 args |
| `tests/Integration/Rest/OverviewBulkUpdatePluginsControllerTest.php` | job_id + 4-tuple assertions |
| `tests/Integration/Rest/OverviewBulkUpdateThemesControllerTest.php` | Same |
| `defyn-dashboard.php` / `composer.json` / `readme.txt` | Version 0.9.0 |

### SPA (apps/web) — new files

| Path | Responsibility |
|---|---|
| `src/routes/Jobs.tsx` | List page — status chips + paginated rows |
| `src/routes/JobDetail.tsx` | Detail page — header + per-site groups |
| `src/components/jobs/JobStateChip.tsx` | SINGLE source of state colors (9 states) |
| `src/components/jobs/JobRow.tsx` | List-row card (Link to detail) |
| `src/components/jobs/JobItemRow.tsx` | Item row + conditional one-click Retry |
| `src/components/jobs/JobItemsGroup.tsx` | Per-site collapsible group |
| `src/components/jobs/JobHeader.tsx` | Detail header + Cancel/Retry-all + dialogs |
| `src/components/jobs/CancelJobDialog.tsx` | Neutral confirm (cancel-queued) |
| `src/components/jobs/RetryFailedDialog.tsx` | Neutral confirm (bulk retry) |
| `src/components/nav/JobsNavLink.tsx` | Overview-header nav link + active badge |
| `src/lib/queries/useJobsList.ts` | List query + exported `jobsListPollInterval` |
| `src/lib/queries/useJobDetail.ts` | Detail query + exported `jobDetailPollInterval` |
| `src/lib/queries/useJobsCount.ts` | 30s-polled active count (derived from list) |
| `src/lib/mutations/useCancelJob.ts` | POST cancel + invalidations |
| `src/lib/mutations/useRetryItem.ts` | POST item retry + invalidations |
| `src/lib/mutations/useRetryFailed.ts` | POST retry-failed + invalidations |
| `tests/lib/queries/useJobsList.test.tsx` | 3 tests |
| `tests/lib/queries/useJobDetail.test.tsx` | 3 tests |
| `tests/lib/queries/useJobsCount.test.tsx` | 2 tests |
| `tests/lib/mutations/useCancelJob.test.tsx` | 2 tests |
| `tests/lib/mutations/useRetryItem.test.tsx` | 2 tests |
| `tests/lib/mutations/useRetryFailed.test.tsx` | 2 tests |
| `tests/components/jobs/JobStateChip.test.tsx` | 9 tests |
| `tests/components/jobs/JobRow.test.tsx` | 3 tests |
| `tests/components/jobs/JobItemRow.test.tsx` | 4 tests |
| `tests/components/jobs/JobHeaderAndDialogs.test.tsx` | 6 tests |
| `tests/components/nav/JobsNavLink.test.tsx` | 2 tests |
| `tests/routes/Jobs.test.tsx` | 3 tests |
| `tests/routes/JobDetail.test.tsx` | 4 tests |

### SPA — modified files

| Path | What changes |
|---|---|
| `src/types/api.ts` | 7 new Zod schemas + extend both bulk response schemas with `job_id` |
| `src/test/handlers.ts` | 5 new handlers + `job_id` in both bulk POST handlers |
| `src/App.tsx` | `/jobs` + `/jobs/:id` routes |
| `src/routes/Overview.tsx` | Render `<JobsNavLink />` next to the h1 |
| `src/components/overview/BulkUpdatePluginsButton.tsx` | navigate-on-success |
| `src/components/overview/BulkUpdateThemesButton.tsx` | Same |
| `tests/lib/mutations/useBulkUpdatePlugins.test.tsx` | `job_id: 42` in 2 inline fixtures |
| `tests/lib/mutations/useBulkUpdateThemes.test.tsx` | Same |
| `tests/components/overview/BulkUpdatePluginsButton.test.tsx` | MemoryRouter wrapper + job_id fixture + 1 new test |
| `tests/components/overview/BulkUpdateThemesButton.test.tsx` | MemoryRouter wrapper + 1 new test |

---

# Phase A — Schema + domain

## Task 1 — `BulkJobsTable` + `BulkJobItemsTable` + Activation v7

**Files:**
- Create: `packages/dashboard-plugin/src/Schema/BulkJobsTable.php`
- Create: `packages/dashboard-plugin/src/Schema/BulkJobItemsTable.php`
- Modify: `packages/dashboard-plugin/src/Activation.php` (TABLES + SCHEMA_VERSION)
- Test: `packages/dashboard-plugin/tests/Integration/Schema/BulkJobsTableTest.php` (CREATE)
- Test: `packages/dashboard-plugin/tests/Integration/Schema/BulkJobItemsTableTest.php` (CREATE)
- Test: `packages/dashboard-plugin/tests/Integration/Schema/SchemaVersionMigrationV7Test.php` (CREATE)

- [ ] **Step 1: Write the failing tests**

Create `packages/dashboard-plugin/tests/Integration/Schema/BulkJobsTableTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Schema;

use Defyn\Dashboard\Schema\BulkJobsTable;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class BulkJobsTableTest extends AbstractSchemaTestCase
{
    public function testTableNameIsPrefixed(): void
    {
        global $wpdb;
        $this->assertSame($wpdb->prefix . 'defyn_bulk_jobs', BulkJobsTable::tableName());
    }

    public function testCreateSqlHasAllRequiredColumns(): void
    {
        $sql = BulkJobsTable::createSql();

        foreach ([
            'id', 'user_id', 'kind', 'scheduled_count', 'skipped_count',
            'started_at', 'completed_at', 'created_at',
        ] as $column) {
            $this->assertStringContainsString($column, $sql, "createSql must declare {$column}");
        }
    }

    public function testTableExistsAfterActivation(): void
    {
        \Defyn\Dashboard\Activation::activate();
        $this->assertTableExists(BulkJobsTable::tableName());
    }

    public function testColumnTypesAndIndex(): void
    {
        \Defyn\Dashboard\Activation::activate();
        $cols = $this->describeTable(BulkJobsTable::tableName());

        $this->assertSame('NO', $cols['user_id']['Null']);
        $this->assertSame('NO', $cols['kind']['Null']);
        $this->assertSame('0', $cols['scheduled_count']['Default']);
        $this->assertSame('0', $cols['skipped_count']['Default']);
        $this->assertSame('YES', $cols['started_at']['Null']);
        $this->assertSame('YES', $cols['completed_at']['Null']);
        $this->assertSame('NO', $cols['created_at']['Null']);

        $this->assertHasIndex(BulkJobsTable::tableName(), 'idx_user_created');
    }
}
```

Create `packages/dashboard-plugin/tests/Integration/Schema/BulkJobItemsTableTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Schema;

use Defyn\Dashboard\Schema\BulkJobItemsTable;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class BulkJobItemsTableTest extends AbstractSchemaTestCase
{
    public function testTableNameIsPrefixed(): void
    {
        global $wpdb;
        $this->assertSame($wpdb->prefix . 'defyn_bulk_job_items', BulkJobItemsTable::tableName());
    }

    public function testCreateSqlHasAllRequiredColumns(): void
    {
        $sql = BulkJobItemsTable::createSql();

        foreach ([
            'id', 'job_id', 'site_id', 'resource_slug', 'state',
            'error_message', 'started_at', 'completed_at', 'created_at',
        ] as $column) {
            $this->assertStringContainsString($column, $sql, "createSql must declare {$column}");
        }
    }

    public function testTableExistsAfterActivation(): void
    {
        \Defyn\Dashboard\Activation::activate();
        $this->assertTableExists(BulkJobItemsTable::tableName());
    }

    public function testStateDefaultsToQueuedAndIndexesExist(): void
    {
        \Defyn\Dashboard\Activation::activate();
        $cols = $this->describeTable(BulkJobItemsTable::tableName());

        $this->assertSame('queued', $cols['state']['Default']);
        $this->assertSame('NO', $cols['state']['Null']);
        $this->assertSame('YES', $cols['error_message']['Null']);
        $this->assertSame('YES', $cols['started_at']['Null']);
        $this->assertSame('YES', $cols['completed_at']['Null']);

        $this->assertHasIndex(BulkJobItemsTable::tableName(), 'idx_job_state');
        $this->assertHasIndex(BulkJobItemsTable::tableName(), 'idx_state_completed');
    }
}
```

Create `packages/dashboard-plugin/tests/Integration/Schema/SchemaVersionMigrationV7Test.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Schema;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Schema\BulkJobItemsTable;
use Defyn\Dashboard\Schema\BulkJobsTable;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SchemaVersionMigrationV7Test extends AbstractSchemaTestCase
{
    public function testSchemaVersionConstantIsSeven(): void
    {
        $this->assertSame(7, Activation::SCHEMA_VERSION);
    }

    public function testActivationCreatesBulkJobsAndItemsTables(): void
    {
        Activation::ensureSchema();

        $this->assertTableExists(BulkJobsTable::tableName());
        $this->assertTableExists(BulkJobItemsTable::tableName());
    }

    public function testV7MigrationIsIdempotent(): void
    {
        Activation::ensureSchema();
        Activation::ensureSchema(); // second call must not error
        $this->assertSame(7, Activation::SCHEMA_VERSION);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd packages/dashboard-plugin && composer test -- --filter "BulkJobsTableTest|BulkJobItemsTableTest|SchemaVersionMigrationV7Test"`

Expected: FAIL — `Error: Class "Defyn\Dashboard\Schema\BulkJobsTable" not found` + `testSchemaVersionConstantIsSeven` fails with `6 !== 7`.

- [ ] **Step 3: Create the two table classes + bump Activation**

Create `packages/dashboard-plugin/src/Schema/BulkJobsTable.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Schema;

/**
 * P2.9 — wp_defyn_bulk_jobs (spec § 1).
 *
 * Parent row — one per destructive bulk request (P2.7 plugins + P2.8 themes).
 * `kind` is 'plugin_update' | 'theme_update'. started_at/completed_at are
 * maintained automatically by BulkJobsRepository::refreshJobTimestamps.
 *
 * NOTE (plan-bug trap #29): no DESC in the index definition — dbDelta's
 * parser doesn't support it; list ordering is enforced via ORDER BY.
 */
final class BulkJobsTable implements SchemaTable
{
    public static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'defyn_bulk_jobs';
    }

    public static function createSql(): string
    {
        global $wpdb;
        $table   = self::tableName();
        $charset = $wpdb->get_charset_collate();

        return "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            kind VARCHAR(20) NOT NULL,
            scheduled_count INT UNSIGNED NOT NULL DEFAULT 0,
            skipped_count INT UNSIGNED NOT NULL DEFAULT 0,
            started_at DATETIME NULL,
            completed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_user_created (user_id, created_at)
        ) {$charset};";
    }
}
```

Create `packages/dashboard-plugin/src/Schema/BulkJobItemsTable.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Schema;

/**
 * P2.9 — wp_defyn_bulk_job_items (spec § 1).
 *
 * Child row — one per scheduled (site_id, resource_slug) pair. State machine:
 * queued → started → succeeded|failed (terminal), queued → cancelled
 * (terminal), failed → queued (operator retry). `resource_slug` works for
 * both plugins and themes (both inventories key on `slug`).
 */
final class BulkJobItemsTable implements SchemaTable
{
    public static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'defyn_bulk_job_items';
    }

    public static function createSql(): string
    {
        global $wpdb;
        $table   = self::tableName();
        $charset = $wpdb->get_charset_collate();

        return "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            job_id BIGINT UNSIGNED NOT NULL,
            site_id BIGINT UNSIGNED NOT NULL,
            resource_slug VARCHAR(80) NOT NULL,
            state VARCHAR(20) NOT NULL DEFAULT 'queued',
            error_message VARCHAR(1000) NULL,
            started_at DATETIME NULL,
            completed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_job_state (job_id, state),
            KEY idx_state_completed (state, completed_at)
        ) {$charset};";
    }
}
```

Modify `packages/dashboard-plugin/src/Activation.php`. Three edits:

Edit 1 — use-imports. Old:

```php
use Defyn\Dashboard\Jobs\Scheduler;
use Defyn\Dashboard\Schema\ActivityLogTable;
use Defyn\Dashboard\Schema\ConnectionCodesTable;
```

New:

```php
use Defyn\Dashboard\Jobs\Scheduler;
use Defyn\Dashboard\Schema\ActivityLogTable;
use Defyn\Dashboard\Schema\BulkJobItemsTable;
use Defyn\Dashboard\Schema\BulkJobsTable;
use Defyn\Dashboard\Schema\ConnectionCodesTable;
```

Edit 2 — version constant. Old:

```php
    public const SCHEMA_VERSION = 6;
```

New:

```php
    public const SCHEMA_VERSION = 7;
```

Edit 3 — TABLES const. Old:

```php
    public const TABLES = [
        SitesTable::class,
        ConnectionCodesTable::class,
        ActivityLogTable::class,
        SitePluginsTable::class,
        SiteThemesTable::class,
    ];
```

New:

```php
    public const TABLES = [
        SitesTable::class,
        ConnectionCodesTable::class,
        ActivityLogTable::class,
        SitePluginsTable::class,
        SiteThemesTable::class,
        BulkJobsTable::class,
        BulkJobItemsTable::class,
    ];
```

No Uninstaller change needed — `Uninstaller::uninstall()` iterates `Activation::TABLES` so the two new tables are covered automatically.

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd packages/dashboard-plugin && composer test -- --filter "BulkJobsTableTest|BulkJobItemsTableTest|SchemaVersionMigrationV7Test"`

Expected: 11 PASS.

Sanity-check the whole suite: `cd packages/dashboard-plugin && composer test` — only the 3 documented carry-forward failures.

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Schema/BulkJobsTable.php \
        packages/dashboard-plugin/src/Schema/BulkJobItemsTable.php \
        packages/dashboard-plugin/src/Activation.php \
        packages/dashboard-plugin/tests/Integration/Schema/BulkJobsTableTest.php \
        packages/dashboard-plugin/tests/Integration/Schema/BulkJobItemsTableTest.php \
        packages/dashboard-plugin/tests/Integration/Schema/SchemaVersionMigrationV7Test.php
git commit -m "feat(p2-9): schema v7 — wp_defyn_bulk_jobs + wp_defyn_bulk_job_items

Two additive CREATE TABLEs for the BulkJob domain entity. Parent table
carries user_id/kind/counts/timestamps; child carries one row per
scheduled (site_id, resource_slug) pair with the 5-state machine
(queued/started/succeeded/failed/cancelled). Indexes: idx_user_created
(list query), idx_job_state (detail grouping), idx_state_completed
(future retention sweep).

SCHEMA_VERSION 6 -> 7; both tables join Activation::TABLES so
self-heal + Uninstaller cover them automatically.

11 schema tests across 3 files.

Per spec § 1."
```

---

## Task 2 — `BulkJobAggregator` pure-function helper

**Files:**
- Create: `packages/dashboard-plugin/src/Services/BulkJobAggregator.php`
- Test: `packages/dashboard-plugin/tests/Unit/Services/BulkJobAggregatorTest.php` (CREATE)

- [ ] **Step 1: Write the failing tests**

Create `packages/dashboard-plugin/tests/Unit/Services/BulkJobAggregatorTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Unit\Services;

use Defyn\Dashboard\Services\BulkJobAggregator;
use PHPUnit\Framework\TestCase;

/**
 * P2.9 — pure-function tests, no DB / no WP (guardrail #19).
 */
final class BulkJobAggregatorTest extends TestCase
{
    /** @param list<string> $states @return list<array{state: string}> */
    private static function items(array $states): array
    {
        return array_map(static fn(string $s) => ['state' => $s], $states);
    }

    public function testCountsByStateRollup(): void
    {
        $counts = BulkJobAggregator::countsByState(
            self::items(['queued', 'queued', 'started', 'succeeded', 'failed', 'cancelled'])
        );

        $this->assertSame(
            ['queued' => 2, 'started' => 1, 'succeeded' => 1, 'failed' => 1, 'cancelled' => 1],
            $counts
        );
    }

    public function testCountsByStateIgnoresUnknownStates(): void
    {
        $counts = BulkJobAggregator::countsByState(self::items(['queued', 'bogus']));

        $this->assertSame(1, $counts['queued']);
        $this->assertSame(1, array_sum($counts));
    }

    public function testDeriveJobStateQueuedWhenAllQueued(): void
    {
        $this->assertSame('queued', BulkJobAggregator::deriveJobState(self::items(['queued', 'queued'])));
    }

    public function testDeriveJobStateQueuedWhenNoItems(): void
    {
        // Defensive — jobs are only created with >= 1 item, but an empty array
        // must not divide-by-zero or mislabel.
        $this->assertSame('queued', BulkJobAggregator::deriveJobState([]));
    }

    public function testDeriveJobStateInProgressWhenAnyStarted(): void
    {
        $this->assertSame('in_progress', BulkJobAggregator::deriveJobState(self::items(['queued', 'started'])));
    }

    public function testDeriveJobStateInProgressWhenMixedTerminalAndNonTerminal(): void
    {
        $this->assertSame('in_progress', BulkJobAggregator::deriveJobState(self::items(['succeeded', 'queued'])));
    }

    public function testDeriveJobStateCompletedWhenAllSucceeded(): void
    {
        $this->assertSame('completed', BulkJobAggregator::deriveJobState(self::items(['succeeded', 'succeeded'])));
    }

    public function testDeriveJobStatePartialWhenAllTerminalButSomeFailedOrCancelled(): void
    {
        $this->assertSame('partial', BulkJobAggregator::deriveJobState(self::items(['succeeded', 'failed'])));
        $this->assertSame('partial', BulkJobAggregator::deriveJobState(self::items(['succeeded', 'cancelled'])));
        $this->assertSame('partial', BulkJobAggregator::deriveJobState(self::items(['failed', 'cancelled'])));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd packages/dashboard-plugin && composer test -- --filter BulkJobAggregatorTest`

Expected: 8 FAIL with `Class "Defyn\Dashboard\Services\BulkJobAggregator" not found`.

- [ ] **Step 3: Create the aggregator**

Create `packages/dashboard-plugin/src/Services/BulkJobAggregator.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

/**
 * P2.9 — pure-function derived state for bulk jobs (spec § 2.9, guardrail #19).
 *
 * No I/O, no DB, no globals. Used by JobsListController (via grouped counts
 * from BulkJobsRepository::countsByStateForJobs) AND JobsDetailController
 * (via raw item rows).
 *
 * Job-level state semantics (spec § 1):
 *   queued      — all items still queued
 *   in_progress — at least one started, OR any terminal alongside any non-terminal
 *   completed   — all items succeeded (clean win)
 *   partial     — all items terminal but at least one failed or cancelled
 */
final class BulkJobAggregator
{
    /** @return array{queued: int, started: int, succeeded: int, failed: int, cancelled: int} */
    public static function emptyCounts(): array
    {
        return ['queued' => 0, 'started' => 0, 'succeeded' => 0, 'failed' => 0, 'cancelled' => 0];
    }

    /**
     * @param list<array{state: string}> $items
     * @return array{queued: int, started: int, succeeded: int, failed: int, cancelled: int}
     */
    public static function countsByState(array $items): array
    {
        $counts = self::emptyCounts();
        foreach ($items as $item) {
            $state = (string) ($item['state'] ?? '');
            if (array_key_exists($state, $counts)) {
                $counts[$state]++;
            }
        }
        return $counts;
    }

    /**
     * @param array{queued: int, started: int, succeeded: int, failed: int, cancelled: int} $counts
     * @return string 'queued'|'in_progress'|'completed'|'partial'
     */
    public static function deriveJobStateFromCounts(array $counts): string
    {
        $total = array_sum($counts);
        if ($total === 0) {
            return 'queued'; // defensive — jobs are never created without items
        }
        if ($counts['succeeded'] === $total) {
            return 'completed';
        }
        $terminal = $counts['succeeded'] + $counts['failed'] + $counts['cancelled'];
        if ($terminal === $total) {
            return 'partial';
        }
        if ($counts['queued'] === $total) {
            return 'queued';
        }
        return 'in_progress';
    }

    /**
     * @param list<array{state: string}> $items
     * @return string 'queued'|'in_progress'|'completed'|'partial'
     */
    public static function deriveJobState(array $items): string
    {
        return self::deriveJobStateFromCounts(self::countsByState($items));
    }
}
```

Note: `deriveJobStateFromCounts` is a deliberate addition over the spec's two-method contract — the list controller works from a single grouped-counts query (no item rows in memory), so the counts→state derivation must be callable directly. `deriveJobState` stays as the composition, so the spec's contract is a strict subset.

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd packages/dashboard-plugin && composer test -- --filter BulkJobAggregatorTest`

Expected: 8 PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Services/BulkJobAggregator.php \
        packages/dashboard-plugin/tests/Unit/Services/BulkJobAggregatorTest.php
git commit -m "feat(p2-9): BulkJobAggregator pure-function derived state

countsByState (5-state rollup, unknown states ignored) +
deriveJobStateFromCounts (queued/in_progress/completed/partial) +
deriveJobState composition. No I/O, no DB, no globals — guardrail #19.

deriveJobStateFromCounts is an addition over the spec contract so the
list controller can derive state from ONE grouped-counts query instead
of loading every item row (N+1 avoidance).

8 unit tests covering each derived state + rollup + empty-input guard.

Per spec § 2.9."
```

---

## Task 3 — `BulkJobsRepository` part 1: create + find

**Files:**
- Create: `packages/dashboard-plugin/src/Services/BulkJobsRepository.php`
- Test: `packages/dashboard-plugin/tests/Integration/Services/BulkJobsRepositoryTest.php` (CREATE)

- [ ] **Step 1: Write the failing tests**

Create `packages/dashboard-plugin/tests/Integration/Services/BulkJobsRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\BulkJobsRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * P2.9 — create + find half of BulkJobsRepository.
 *
 * Guardrail #15: freshlyActivate + explicit purge of both bulk tables in setUp.
 *
 * @group integration
 */
final class BulkJobsRepositoryTest extends AbstractSchemaTestCase
{
    private BulkJobsRepository $repo;

    public function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_bulk_jobs');
        $this->freshlyActivate('defyn_bulk_job_items');

        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL
        $wpdb->query('SET autocommit = 1');
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_bulk_job_items");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_bulk_jobs");
        // phpcs:enable WordPress.DB.PreparedSQL

        $this->repo = new BulkJobsRepository();
    }

    public function testCreateJobReturnsInsertedId(): void
    {
        $jobId = $this->repo->createJob(1, 'plugin_update', 3, 1, '2026-06-09 21:00:00');

        $this->assertGreaterThan(0, $jobId);

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}defyn_bulk_jobs WHERE id = %d", $jobId),
            ARRAY_A
        );
        $this->assertSame('1', $row['user_id']);
        $this->assertSame('plugin_update', $row['kind']);
        $this->assertSame('3', $row['scheduled_count']);
        $this->assertSame('1', $row['skipped_count']);
        $this->assertNull($row['started_at']);
        $this->assertNull($row['completed_at']);
        $this->assertSame('2026-06-09 21:00:00', $row['created_at']);
    }

    public function testCreateItemsInsertsAllPairs(): void
    {
        $jobId = $this->repo->createJob(1, 'plugin_update', 2, 0, '2026-06-09 21:00:00');
        $this->repo->createItems($jobId, [
            ['site_id' => 1, 'slug' => 'akismet'],
            ['site_id' => 2, 'slug' => 'yoast'],
        ], '2026-06-09 21:00:00');

        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}defyn_bulk_job_items WHERE job_id = %d ORDER BY id ASC",
                $jobId
            ),
            ARRAY_A
        );
        $this->assertCount(2, $rows);
        $this->assertSame('akismet', $rows[0]['resource_slug']);
        $this->assertSame('queued', $rows[0]['state']);
        $this->assertSame('yoast', $rows[1]['resource_slug']);
        $this->assertSame('2', $rows[1]['site_id']);
    }

    public function testCreateItemsReturnsPairsEnrichedWithItemIds(): void
    {
        $jobId    = $this->repo->createJob(1, 'theme_update', 2, 0, '2026-06-09 21:00:00');
        $enriched = $this->repo->createItems($jobId, [
            ['site_id' => 1, 'slug' => 'astra'],
            ['site_id' => 1, 'slug' => 'blocksy'],
        ], '2026-06-09 21:00:00');

        $this->assertCount(2, $enriched);
        foreach ($enriched as $pair) {
            $this->assertArrayHasKey('site_id', $pair);
            $this->assertArrayHasKey('slug', $pair);
            $this->assertArrayHasKey('item_id', $pair);
            $this->assertGreaterThan(0, $pair['item_id']);
        }
        $this->assertSame('astra', $enriched[0]['slug']);
        $this->assertSame('blocksy', $enriched[1]['slug']);
        $this->assertNotSame($enriched[0]['item_id'], $enriched[1]['item_id']);
    }

    public function testCreateItemsUsesSingleInsertStatement(): void
    {
        $jobId = $this->repo->createJob(1, 'plugin_update', 5, 0, '2026-06-09 21:00:00');

        global $wpdb;
        $before = (int) $wpdb->num_queries;

        $this->repo->createItems($jobId, [
            ['site_id' => 1, 'slug' => 'a'],
            ['site_id' => 1, 'slug' => 'b'],
            ['site_id' => 2, 'slug' => 'c'],
            ['site_id' => 2, 'slug' => 'd'],
            ['site_id' => 3, 'slug' => 'e'],
        ], '2026-06-09 21:00:00');

        $delta = (int) $wpdb->num_queries - $before;

        // Guardrail #5: 1 multi-row INSERT + 1 read-back SELECT. The delta
        // must NOT scale with pair count (5 pairs here).
        $this->assertSame(2, $delta, "createItems issued {$delta} queries for 5 pairs; expected 2");
    }

    public function testCreateItemsWithEmptyPairsReturnsEmptyArrayWithoutQueries(): void
    {
        $jobId = $this->repo->createJob(1, 'plugin_update', 0, 0, '2026-06-09 21:00:00');

        global $wpdb;
        $before = (int) $wpdb->num_queries;
        $result = $this->repo->createItems($jobId, [], '2026-06-09 21:00:00');

        $this->assertSame([], $result);
        $this->assertSame($before, (int) $wpdb->num_queries);
    }

    public function testFindByIdForUserReturnsRow(): void
    {
        $jobId = $this->repo->createJob(7, 'plugin_update', 1, 0, '2026-06-09 21:00:00');

        $row = $this->repo->findByIdForUser($jobId, 7);

        $this->assertNotNull($row);
        $this->assertSame((string) $jobId, $row['id']);
        $this->assertSame('plugin_update', $row['kind']);
    }

    public function testFindByIdForUserReturnsNullForForeignUser(): void
    {
        $jobId = $this->repo->createJob(7, 'plugin_update', 1, 0, '2026-06-09 21:00:00');

        $this->assertNull($this->repo->findByIdForUser($jobId, 8)); // guardrail #7
        $this->assertNull($this->repo->findByIdForUser(999999, 7));
    }

    public function testFindItemsForJobReturnsRowsInIdOrder(): void
    {
        $jobId = $this->repo->createJob(1, 'plugin_update', 3, 0, '2026-06-09 21:00:00');
        $this->repo->createItems($jobId, [
            ['site_id' => 1, 'slug' => 'a'],
            ['site_id' => 1, 'slug' => 'b'],
            ['site_id' => 1, 'slug' => 'c'],
        ], '2026-06-09 21:00:00');

        $items = $this->repo->findItemsForJob($jobId);

        $this->assertCount(3, $items);
        $this->assertSame(['a', 'b', 'c'], array_column($items, 'resource_slug'));
    }

    public function testFindItemForJobScopesToJob(): void
    {
        $jobA   = $this->repo->createJob(1, 'plugin_update', 1, 0, '2026-06-09 21:00:00');
        $jobB   = $this->repo->createJob(1, 'plugin_update', 1, 0, '2026-06-09 21:00:00');
        $itemsA = $this->repo->createItems($jobA, [['site_id' => 1, 'slug' => 'a']], '2026-06-09 21:00:00');

        $this->assertNotNull($this->repo->findItemForJob($jobA, $itemsA[0]['item_id']));
        $this->assertNull($this->repo->findItemForJob($jobB, $itemsA[0]['item_id']));
        $this->assertNull($this->repo->findItemForJob($jobA, 999999));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd packages/dashboard-plugin && composer test -- --filter BulkJobsRepositoryTest`

Expected: 9 FAIL with `Class "Defyn\Dashboard\Services\BulkJobsRepository" not found`.

- [ ] **Step 3: Create the repository (part 1)**

Create `packages/dashboard-plugin/src/Services/BulkJobsRepository.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Schema\BulkJobItemsTable;
use Defyn\Dashboard\Schema\BulkJobsTable;

/**
 * P2.9 — CRUD + lifecycle marks for the BulkJob entity (spec § 2.8).
 *
 * Part 1 (this task): createJob / createItems / findByIdForUser /
 * findItemsForJob / findItemForJob. Part 2 (Task 4) adds the markItem*
 * lifecycle methods, refreshJobTimestamps, and the list/filter queries.
 * Task 6 adds findItemsForJobWithResources (detail-view JOIN).
 */
final class BulkJobsRepository
{
    public function createJob(int $userId, string $kind, int $scheduledCount, int $skippedCount, string $now): int
    {
        global $wpdb;
        $wpdb->insert(BulkJobsTable::tableName(), [
            'user_id'         => $userId,
            'kind'            => $kind,
            'scheduled_count' => $scheduledCount,
            'skipped_count'   => $skippedCount,
            'created_at'      => $now,
        ]);
        return (int) $wpdb->insert_id;
    }

    /**
     * Single multi-row INSERT + ONE read-back SELECT (guardrail #5) — the
     * query count never scales with pair count. The read-back avoids any
     * assumption about consecutive auto-increment allocation.
     *
     * @param list<array{site_id: int, slug: string}> $pairs
     * @return list<array{site_id: int, slug: string, item_id: int}>
     */
    public function createItems(int $jobId, array $pairs, string $now): array
    {
        if ($pairs === []) {
            return [];
        }

        global $wpdb;
        $table = BulkJobItemsTable::tableName();

        $placeholders = [];
        $values       = [];
        foreach ($pairs as $pair) {
            $placeholders[] = '(%d, %d, %s, %s, %s)';
            $values[]       = $jobId;
            $values[]       = (int) $pair['site_id'];
            $values[]       = (string) $pair['slug'];
            $values[]       = 'queued';
            $values[]       = $now;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL — placeholder list built above; all values flow through prepare().
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (job_id, site_id, resource_slug, state, created_at) VALUES "
                . implode(', ', $placeholders),
            $values
        ));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, site_id, resource_slug FROM {$table} WHERE job_id = %d ORDER BY id ASC",
            $jobId
        ), ARRAY_A);

        return array_map(static fn(array $row) => [
            'site_id' => (int) $row['site_id'],
            'slug'    => (string) $row['resource_slug'],
            'item_id' => (int) $row['id'],
        ], is_array($rows) ? $rows : []);
    }

    /** @return array<string, mixed>|null Null for missing OR foreign jobs (guardrail #7). */
    public function findByIdForUser(int $jobId, int $userId): ?array
    {
        global $wpdb;
        $table = BulkJobsTable::tableName();
        $row   = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND user_id = %d",
            $jobId,
            $userId
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string, mixed>> */
    public function findItemsForJob(int $jobId): array
    {
        global $wpdb;
        $table = BulkJobItemsTable::tableName();
        $rows  = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE job_id = %d ORDER BY id ASC",
            $jobId
        ), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /** @return array<string, mixed>|null Scoped to the job — foreign items return null. */
    public function findItemForJob(int $jobId, int $itemId): ?array
    {
        global $wpdb;
        $table = BulkJobItemsTable::tableName();
        $row   = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND job_id = %d",
            $itemId,
            $jobId
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd packages/dashboard-plugin && composer test -- --filter BulkJobsRepositoryTest`

Expected: 9 PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Services/BulkJobsRepository.php \
        packages/dashboard-plugin/tests/Integration/Services/BulkJobsRepositoryTest.php
git commit -m "feat(p2-9): BulkJobsRepository part 1 — create + find

createJob (insert_id return), createItems (SINGLE multi-row INSERT +
one read-back SELECT — query count never scales with pair count,
asserted via wpdb->num_queries delta === 2 for 5 pairs), findByIdForUser
(null for foreign/missing — guardrail #7), findItemsForJob (id order),
findItemForJob (job-scoped).

9 integration tests.

Per spec § 2.8 + guardrails #5/#7/#15/#16."
```

---

## Task 4 — `BulkJobsRepository` part 2: lifecycle marks + filters

**Files:**
- Modify: `packages/dashboard-plugin/src/Services/BulkJobsRepository.php` (append methods)
- Test: `packages/dashboard-plugin/tests/Integration/Services/BulkJobsRepositoryLifecycleTest.php` (CREATE)

- [ ] **Step 1: Write the failing tests**

Create `packages/dashboard-plugin/tests/Integration/Services/BulkJobsRepositoryLifecycleTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\BulkJobsRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * P2.9 — lifecycle marks + refreshJobTimestamps + list filters.
 *
 * @group integration
 */
final class BulkJobsRepositoryLifecycleTest extends AbstractSchemaTestCase
{
    private BulkJobsRepository $repo;

    public function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_bulk_jobs');
        $this->freshlyActivate('defyn_bulk_job_items');

        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL
        $wpdb->query('SET autocommit = 1');
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_bulk_job_items");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_bulk_jobs");
        // phpcs:enable WordPress.DB.PreparedSQL

        $this->repo = new BulkJobsRepository();
    }

    /**
     * @param list<array{site_id: int, slug: string}> $pairs
     * @return array{0: int, 1: list<array{site_id: int, slug: string, item_id: int}>}
     */
    private function makeJobWithItems(int $userId, string $kind, array $pairs, string $createdAt = '2026-06-09 21:00:00'): array
    {
        $jobId = $this->repo->createJob($userId, $kind, count($pairs), 0, $createdAt);
        $items = $this->repo->createItems($jobId, $pairs, $createdAt);
        return [$jobId, $items];
    }

    private function itemRow(int $itemId): array
    {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}defyn_bulk_job_items WHERE id = %d", $itemId),
            ARRAY_A
        );
    }

    private function jobRow(int $jobId): array
    {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}defyn_bulk_jobs WHERE id = %d", $jobId),
            ARRAY_A
        );
    }

    public function testMarkItemStartedTransitionsStateSetsStartedAtAndJobStartedAt(): void
    {
        [$jobId, $items] = $this->makeJobWithItems(1, 'plugin_update', [['site_id' => 1, 'slug' => 'a']]);
        $itemId = $items[0]['item_id'];

        $this->assertNull($this->jobRow($jobId)['started_at']);

        $this->repo->markItemStarted($itemId, '2026-06-09 21:01:00');

        $item = $this->itemRow($itemId);
        $this->assertSame('started', $item['state']);
        $this->assertSame('2026-06-09 21:01:00', $item['started_at']);
        $this->assertSame('2026-06-09 21:01:00', $this->jobRow($jobId)['started_at']);

        // No-op from a terminal state (retry re-entries are already-started
        // and terminal items must not be revived).
        $this->repo->markItemSucceeded($itemId, '2026-06-09 21:02:00');
        $this->repo->markItemStarted($itemId, '2026-06-09 21:03:00');
        $this->assertSame('succeeded', $this->itemRow($itemId)['state']);
    }

    public function testMarkItemSucceededSetsCompletedAt(): void
    {
        [, $items] = $this->makeJobWithItems(1, 'plugin_update', [['site_id' => 1, 'slug' => 'a']]);
        $itemId = $items[0]['item_id'];

        $this->repo->markItemStarted($itemId, '2026-06-09 21:01:00');
        $this->repo->markItemSucceeded($itemId, '2026-06-09 21:02:00');

        $item = $this->itemRow($itemId);
        $this->assertSame('succeeded', $item['state']);
        $this->assertSame('2026-06-09 21:02:00', $item['completed_at']);
        $this->assertNull($item['error_message']);
    }

    public function testMarkItemFailedSetsErrorMessageAndTruncatesTo1000Chars(): void
    {
        [, $items] = $this->makeJobWithItems(1, 'plugin_update', [['site_id' => 1, 'slug' => 'a']]);
        $itemId = $items[0]['item_id'];

        $this->repo->markItemStarted($itemId, '2026-06-09 21:01:00');
        $this->repo->markItemFailed($itemId, '2026-06-09 21:02:00', str_repeat('x', 1500));

        $item = $this->itemRow($itemId);
        $this->assertSame('failed', $item['state']);
        $this->assertSame('2026-06-09 21:02:00', $item['completed_at']);
        $this->assertSame(1000, strlen($item['error_message']));
    }

    public function testMarkItemCancelledOnlyAllowedFromQueued(): void
    {
        [, $items] = $this->makeJobWithItems(1, 'plugin_update', [
            ['site_id' => 1, 'slug' => 'a'],
            ['site_id' => 1, 'slug' => 'b'],
        ]);

        // queued → cancelled OK
        $this->repo->markItemCancelled($items[0]['item_id'], '2026-06-09 21:01:00');
        $this->assertSame('cancelled', $this->itemRow($items[0]['item_id'])['state']);

        // started → cancel is a silent no-op (guardrail #6)
        $this->repo->markItemStarted($items[1]['item_id'], '2026-06-09 21:01:00');
        $this->repo->markItemCancelled($items[1]['item_id'], '2026-06-09 21:02:00');
        $this->assertSame('started', $this->itemRow($items[1]['item_id'])['state']);
    }

    public function testResetItemForRetryRequeuesFailedItemAndIgnoresOthers(): void
    {
        [, $items] = $this->makeJobWithItems(1, 'plugin_update', [
            ['site_id' => 1, 'slug' => 'a'],
            ['site_id' => 1, 'slug' => 'b'],
        ]);
        $failedId    = $items[0]['item_id'];
        $succeededId = $items[1]['item_id'];

        $this->repo->markItemStarted($failedId, '2026-06-09 21:01:00');
        $this->repo->markItemFailed($failedId, '2026-06-09 21:02:00', 'boom');
        $this->repo->markItemStarted($succeededId, '2026-06-09 21:01:00');
        $this->repo->markItemSucceeded($succeededId, '2026-06-09 21:02:00');

        $this->repo->resetItemForRetry($failedId, '2026-06-09 21:05:00');

        $item = $this->itemRow($failedId);
        $this->assertSame('queued', $item['state']);
        $this->assertNull($item['error_message']);
        $this->assertNull($item['started_at']);
        $this->assertNull($item['completed_at']);

        // succeeded item is NOT resettable
        $this->repo->resetItemForRetry($succeededId, '2026-06-09 21:05:00');
        $this->assertSame('succeeded', $this->itemRow($succeededId)['state']);
    }

    public function testRefreshJobTimestampsSetsCompletedAtWhenAllTerminal(): void
    {
        [$jobId, $items] = $this->makeJobWithItems(1, 'plugin_update', [
            ['site_id' => 1, 'slug' => 'a'],
            ['site_id' => 1, 'slug' => 'b'],
        ]);

        $this->repo->markItemStarted($items[0]['item_id'], '2026-06-09 21:01:00');
        $this->repo->markItemSucceeded($items[0]['item_id'], '2026-06-09 21:02:00');
        $this->assertNull($this->jobRow($jobId)['completed_at'], 'one item still queued — not complete');

        $this->repo->markItemStarted($items[1]['item_id'], '2026-06-09 21:03:00');
        $this->repo->markItemFailed($items[1]['item_id'], '2026-06-09 21:04:00', 'boom');

        $this->assertSame('2026-06-09 21:04:00', $this->jobRow($jobId)['completed_at']);
    }

    public function testResetItemForRetryClearsJobCompletedAt(): void
    {
        [$jobId, $items] = $this->makeJobWithItems(1, 'plugin_update', [['site_id' => 1, 'slug' => 'a']]);
        $itemId = $items[0]['item_id'];

        $this->repo->markItemStarted($itemId, '2026-06-09 21:01:00');
        $this->repo->markItemFailed($itemId, '2026-06-09 21:02:00', 'boom');
        $this->assertNotNull($this->jobRow($jobId)['completed_at']);

        $this->repo->resetItemForRetry($itemId, '2026-06-09 21:05:00');

        $this->assertNull($this->jobRow($jobId)['completed_at'], 'guardrail #8 — retry clears completed_at');
    }

    public function testFindAllForUserWithStatusFilterActive(): void
    {
        [$activeJob] = $this->makeJobWithItems(1, 'plugin_update', [['site_id' => 1, 'slug' => 'a']]);
        [$doneJob, $doneItems] = $this->makeJobWithItems(1, 'plugin_update', [['site_id' => 1, 'slug' => 'b']]);
        $this->repo->markItemStarted($doneItems[0]['item_id'], '2026-06-09 21:01:00');
        $this->repo->markItemSucceeded($doneItems[0]['item_id'], '2026-06-09 21:02:00');

        $rows = $this->repo->findAllForUser(1, 'active', 20, 0);

        $this->assertCount(1, $rows);
        $this->assertSame((string) $activeJob, $rows[0]['id']);
        $this->assertNotSame((string) $doneJob, $rows[0]['id']);
    }

    public function testFindAllForUserWithStatusFilterCompleted(): void
    {
        $this->makeJobWithItems(1, 'plugin_update', [['site_id' => 1, 'slug' => 'a']]);
        [$doneJob, $doneItems] = $this->makeJobWithItems(1, 'plugin_update', [['site_id' => 1, 'slug' => 'b']]);
        $this->repo->markItemStarted($doneItems[0]['item_id'], '2026-06-09 21:01:00');
        $this->repo->markItemSucceeded($doneItems[0]['item_id'], '2026-06-09 21:02:00');

        $rows = $this->repo->findAllForUser(1, 'completed', 20, 0);

        $this->assertCount(1, $rows);
        $this->assertSame((string) $doneJob, $rows[0]['id']);
    }

    public function testFindAllForUserOrdersNewestFirstPaginatesAndScopesToUser(): void
    {
        [$oldJob] = $this->makeJobWithItems(1, 'plugin_update', [['site_id' => 1, 'slug' => 'a']], '2026-06-09 20:00:00');
        [$newJob] = $this->makeJobWithItems(1, 'theme_update', [['site_id' => 1, 'slug' => 'b']], '2026-06-09 22:00:00');
        $this->makeJobWithItems(2, 'plugin_update', [['site_id' => 9, 'slug' => 'x']]); // foreign user

        $pageOne = $this->repo->findAllForUser(1, null, 1, 0);
        $pageTwo = $this->repo->findAllForUser(1, null, 1, 1);

        $this->assertSame((string) $newJob, $pageOne[0]['id']);
        $this->assertSame((string) $oldJob, $pageTwo[0]['id']);
        $this->assertCount(2, $this->repo->findAllForUser(1, null, 20, 0));
    }

    public function testCountAllForUserMatchesFilters(): void
    {
        $this->makeJobWithItems(1, 'plugin_update', [['site_id' => 1, 'slug' => 'a']]);
        [, $doneItems] = $this->makeJobWithItems(1, 'plugin_update', [['site_id' => 1, 'slug' => 'b']]);
        $this->repo->markItemStarted($doneItems[0]['item_id'], '2026-06-09 21:01:00');
        $this->repo->markItemSucceeded($doneItems[0]['item_id'], '2026-06-09 21:02:00');

        $this->assertSame(2, $this->repo->countAllForUser(1, null));
        $this->assertSame(1, $this->repo->countAllForUser(1, 'active'));
        $this->assertSame(1, $this->repo->countAllForUser(1, 'completed'));
        $this->assertSame(0, $this->repo->countAllForUser(2, null));
    }

    public function testFindQueuedItemsForJobReturnsItemIdSiteIdSlug(): void
    {
        [$jobId, $items] = $this->makeJobWithItems(1, 'plugin_update', [
            ['site_id' => 3, 'slug' => 'akismet'],
            ['site_id' => 4, 'slug' => 'yoast'],
        ]);
        $this->repo->markItemStarted($items[1]['item_id'], '2026-06-09 21:01:00');

        $queued = $this->repo->findQueuedItemsForJob($jobId);

        $this->assertSame([
            ['item_id' => $items[0]['item_id'], 'site_id' => 3, 'slug' => 'akismet'],
        ], $queued);
    }

    public function testCountItemsByStateForJobAndGroupedCounts(): void
    {
        [$jobA, $itemsA] = $this->makeJobWithItems(1, 'plugin_update', [
            ['site_id' => 1, 'slug' => 'a'],
            ['site_id' => 1, 'slug' => 'b'],
        ]);
        [$jobB] = $this->makeJobWithItems(1, 'theme_update', [['site_id' => 2, 'slug' => 'c']]);
        $this->repo->markItemStarted($itemsA[0]['item_id'], '2026-06-09 21:01:00');
        $this->repo->markItemSucceeded($itemsA[0]['item_id'], '2026-06-09 21:02:00');

        $this->assertSame(1, $this->repo->countItemsByStateForJob($jobA, 'queued'));
        $this->assertSame(1, $this->repo->countItemsByStateForJob($jobA, 'succeeded'));
        $this->assertSame(0, $this->repo->countItemsByStateForJob($jobA, 'started'));

        global $wpdb;
        $before  = (int) $wpdb->num_queries;
        $grouped = $this->repo->countsByStateForJobs([$jobA, $jobB]);
        $this->assertSame(1, (int) $wpdb->num_queries - $before, 'grouped counts must be ONE query');

        $this->assertSame(1, $grouped[$jobA]['queued']);
        $this->assertSame(1, $grouped[$jobA]['succeeded']);
        $this->assertSame(1, $grouped[$jobB]['queued']);
        $this->assertSame([], $this->repo->countsByStateForJobs([]));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd packages/dashboard-plugin && composer test -- --filter BulkJobsRepositoryLifecycleTest`

Expected: 13 FAIL with `Call to undefined method ... markItemStarted`.

- [ ] **Step 3: Append the part-2 methods**

Append to `packages/dashboard-plugin/src/Services/BulkJobsRepository.php`, immediately after the `findItemForJob` method and before the closing class brace:

```php
    // ─── Lifecycle marks (each triggers refreshJobTimestamps — guardrail #8) ──

    /** queued → started. Guarded — 409-retry re-entries (already started) are no-ops. */
    public function markItemStarted(int $itemId, string $now): void
    {
        global $wpdb;
        $table = BulkJobItemsTable::tableName();
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET state = 'started', started_at = %s WHERE id = %d AND state = 'queued'",
            $now,
            $itemId
        ));
        $this->refreshForItem($itemId, $now);
    }

    public function markItemSucceeded(int $itemId, string $now): void
    {
        global $wpdb;
        $table = BulkJobItemsTable::tableName();
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET state = 'succeeded', completed_at = %s
             WHERE id = %d AND state IN ('queued', 'started')",
            $now,
            $itemId
        ));
        $this->refreshForItem($itemId, $now);
    }

    public function markItemFailed(int $itemId, string $now, string $errorMessage): void
    {
        global $wpdb;
        $table = BulkJobItemsTable::tableName();
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET state = 'failed', completed_at = %s, error_message = %s
             WHERE id = %d AND state IN ('queued', 'started')",
            $now,
            mb_substr($errorMessage, 0, 1000),
            $itemId
        ));
        $this->refreshForItem($itemId, $now);
    }

    /** Guardrail #6 — cancel is only legal from `queued`; anything else is a silent no-op. */
    public function markItemCancelled(int $itemId, string $now): void
    {
        global $wpdb;
        $table = BulkJobItemsTable::tableName();
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET state = 'cancelled', completed_at = %s WHERE id = %d AND state = 'queued'",
            $now,
            $itemId
        ));
        $this->refreshForItem($itemId, $now);
    }

    /** failed → queued; clears error + timestamps (spec § 2.4). */
    public function resetItemForRetry(int $itemId, string $now): void
    {
        global $wpdb;
        $table = BulkJobItemsTable::tableName();
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET state = 'queued', error_message = NULL, started_at = NULL, completed_at = NULL
             WHERE id = %d AND state = 'failed'",
            $itemId
        ));
        $this->refreshForItem($itemId, $now);
    }

    /**
     * Maybe-touch job-level started_at / completed_at based on current item
     * states (guardrail #8). Three guarded statements — each a no-op when the
     * condition doesn't hold, so calling this after every mark is cheap.
     */
    public function refreshJobTimestamps(int $jobId, string $now): void
    {
        global $wpdb;
        $jobs  = BulkJobsTable::tableName();
        $items = BulkJobItemsTable::tableName();

        // started_at — first time any item moves beyond `queued` toward
        // execution (cancelled-only movement doesn't count as "started").
        $wpdb->query($wpdb->prepare(
            "UPDATE {$jobs} SET started_at = %s
             WHERE id = %d AND started_at IS NULL
               AND EXISTS (
                   SELECT 1 FROM {$items}
                   WHERE job_id = %d AND state IN ('started', 'succeeded', 'failed')
               )",
            $now,
            $jobId,
            $jobId
        ));

        // completed_at — set once nothing is queued/started any more…
        $wpdb->query($wpdb->prepare(
            "UPDATE {$jobs} SET completed_at = %s
             WHERE id = %d AND completed_at IS NULL
               AND NOT EXISTS (
                   SELECT 1 FROM {$items}
                   WHERE job_id = %d AND state IN ('queued', 'started')
               )",
            $now,
            $jobId,
            $jobId
        ));

        // …and cleared again when a retry re-queues an item.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$jobs} SET completed_at = NULL
             WHERE id = %d AND completed_at IS NOT NULL
               AND EXISTS (
                   SELECT 1 FROM {$items}
                   WHERE job_id = %d AND state IN ('queued', 'started')
               )",
            $jobId,
            $jobId
        ));
    }

    /**
     * Jobs newest-first. $statusFilter: 'active' (has queued/started items —
     * job-level queued|in_progress) | 'completed' (all terminal — job-level
     * completed|partial) | null (no filter).
     *
     * @return list<array<string, mixed>>
     */
    public function findAllForUser(int $userId, ?string $statusFilter, int $limit, int $offset): array
    {
        global $wpdb;
        $jobs      = BulkJobsTable::tableName();
        $statusSql = $this->statusFilterSql($statusFilter);

        // phpcs:ignore WordPress.DB.PreparedSQL — $statusSql is a fixed fragment chosen below.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT j.* FROM {$jobs} j
             WHERE j.user_id = %d {$statusSql}
             ORDER BY j.created_at DESC, j.id DESC
             LIMIT %d OFFSET %d",
            $userId,
            $limit,
            $offset
        ), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    public function countAllForUser(int $userId, ?string $statusFilter): int
    {
        global $wpdb;
        $jobs      = BulkJobsTable::tableName();
        $statusSql = $this->statusFilterSql($statusFilter);

        // phpcs:ignore WordPress.DB.PreparedSQL — $statusSql is a fixed fragment chosen below.
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$jobs} j WHERE j.user_id = %d {$statusSql}",
            $userId
        ));
    }

    /**
     * Queued items with the exact fields the Cancel controller needs to call
     * as_unschedule_action with the schedule-time 4-tuple (guardrail #4).
     *
     * @return list<array{item_id: int, site_id: int, slug: string}>
     */
    public function findQueuedItemsForJob(int $jobId): array
    {
        global $wpdb;
        $table = BulkJobItemsTable::tableName();
        $rows  = $wpdb->get_results($wpdb->prepare(
            "SELECT id, site_id, resource_slug FROM {$table}
             WHERE job_id = %d AND state = 'queued' ORDER BY id ASC",
            $jobId
        ), ARRAY_A);

        return array_map(static fn(array $row) => [
            'item_id' => (int) $row['id'],
            'site_id' => (int) $row['site_id'],
            'slug'    => (string) $row['resource_slug'],
        ], is_array($rows) ? $rows : []);
    }

    public function countItemsByStateForJob(int $jobId, string $state): int
    {
        global $wpdb;
        $table = BulkJobItemsTable::tableName();
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE job_id = %d AND state = %s",
            $jobId,
            $state
        ));
    }

    /**
     * ONE grouped query for the list page — avoids N+1 across page rows.
     *
     * @param list<int> $jobIds
     * @return array<int, array{queued: int, started: int, succeeded: int, failed: int, cancelled: int}>
     */
    public function countsByStateForJobs(array $jobIds): array
    {
        if ($jobIds === []) {
            return [];
        }

        global $wpdb;
        $table        = BulkJobItemsTable::tableName();
        $placeholders = implode(', ', array_fill(0, count($jobIds), '%d'));

        // phpcs:ignore WordPress.DB.PreparedSQL — placeholder list built above.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT job_id, state, COUNT(*) AS n FROM {$table}
             WHERE job_id IN ({$placeholders}) GROUP BY job_id, state",
            $jobIds
        ), ARRAY_A);

        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $jobId = (int) $row['job_id'];
            if (!isset($out[$jobId])) {
                $out[$jobId] = BulkJobAggregator::emptyCounts();
            }
            $state = (string) $row['state'];
            if (array_key_exists($state, $out[$jobId])) {
                $out[$jobId][$state] = (int) $row['n'];
            }
        }
        return $out;
    }

    private function statusFilterSql(?string $statusFilter): string
    {
        $items        = BulkJobItemsTable::tableName();
        $activeExists = "EXISTS (SELECT 1 FROM {$items} i WHERE i.job_id = j.id AND i.state IN ('queued', 'started'))";

        if ($statusFilter === 'active') {
            return "AND {$activeExists}";
        }
        if ($statusFilter === 'completed') {
            return "AND NOT {$activeExists}";
        }
        return '';
    }

    private function refreshForItem(int $itemId, string $now): void
    {
        global $wpdb;
        $table = BulkJobItemsTable::tableName();
        $jobId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT job_id FROM {$table} WHERE id = %d",
            $itemId
        ));
        if ($jobId > 0) {
            $this->refreshJobTimestamps($jobId, $now);
        }
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd packages/dashboard-plugin && composer test -- --filter "BulkJobsRepositoryTest|BulkJobsRepositoryLifecycleTest|BulkJobAggregatorTest"`

Expected: 9 + 13 + 8 = 30 PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Services/BulkJobsRepository.php \
        packages/dashboard-plugin/tests/Integration/Services/BulkJobsRepositoryLifecycleTest.php
git commit -m "feat(p2-9): BulkJobsRepository part 2 — lifecycle marks + filters

markItemStarted (guarded queued->started; retry re-entries no-op),
markItemSucceeded/Failed (from queued|started; failed truncates error to
1000 chars), markItemCancelled (ONLY from queued — guardrail #6),
resetItemForRetry (failed->queued, clears error + timestamps). Every
mark auto-triggers refreshJobTimestamps (guardrail #8): started_at on
first non-queued movement, completed_at when nothing queued/started
remains, completed_at CLEARED when retry re-queues.

findAllForUser/countAllForUser with active|completed|null filter via
EXISTS on non-terminal items (active == job-level queued|in_progress).
findQueuedItemsForJob feeds the cancel 4-tuple. countsByStateForJobs is
ONE grouped query for the list page (N+1 avoidance, asserted via
num_queries delta === 1).

13 integration tests.

Per spec § 2.8 + guardrails #4/#6/#8."
```

---

# Phase B — REST + integration

## Task 5 — `JobsListController` (GET /jobs) + `RateLimit::jobsList` + route

**Files:**
- Create: `packages/dashboard-plugin/src/Rest/JobsListController.php`
- Modify: `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php` (constants + 1 static method)
- Modify: `packages/dashboard-plugin/src/Rest/RestRouter.php` (register route)
- Test: `packages/dashboard-plugin/tests/Integration/Rest/JobsListControllerTest.php` (CREATE)

- [ ] **Step 1: Write the failing tests**

Create `packages/dashboard-plugin/tests/Integration/Rest/JobsListControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Services\BulkJobsRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

/**
 * P2.9 — Tests for GET /defyn/v1/jobs.
 *
 * @group integration
 */
final class JobsListControllerTest extends AbstractSchemaTestCase
{
    private BulkJobsRepository $repo;

    public function setUp(): void
    {
        parent::setUp();
        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
        $this->freshlyActivate('defyn_bulk_jobs');
        $this->freshlyActivate('defyn_bulk_job_items');

        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL
        $wpdb->query('SET autocommit = 1');
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_bulk_job_items");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_bulk_jobs");
        // phpcs:enable WordPress.DB.PreparedSQL

        for ($i = 1; $i <= 10; $i++) {
            delete_transient("defyn_rl_jobsList_{$i}");
        }

        do_action('rest_api_init');

        $this->repo = new BulkJobsRepository();
    }

    private function token(int $userId): string
    {
        return (new TokenService(DEFYN_JWT_SECRET))->issueAccess($userId);
    }

    private function listRequest(string $token, array $query = []): WP_REST_Request
    {
        $request = new WP_REST_Request('GET', '/defyn/v1/jobs');
        $request->set_header('Authorization', 'Bearer ' . $token);
        if ($query !== []) {
            $request->set_query_params($query);
        }
        return $request;
    }

    public function testAuthRequiredReturns401WhenNoBearerToken(): void
    {
        $response = rest_do_request(new WP_REST_Request('GET', '/defyn/v1/jobs'));
        $this->assertSame(401, $response->get_status());
    }

    public function testEmptyListReturns200WithZeroTotal(): void
    {
        $response = rest_do_request($this->listRequest($this->token(1)));
        $body     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame([], $body['jobs']);
        $this->assertSame(0, $body['total']);
        $this->assertSame(1, $body['page']);
        $this->assertSame(20, $body['per_page']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $body['generated_at']);
    }

    public function testListReturnsJobsWithDerivedCountsAndState(): void
    {
        $jobId = $this->repo->createJob(1, 'plugin_update', 3, 1, '2026-06-09 21:00:00');
        $items = $this->repo->createItems($jobId, [
            ['site_id' => 1, 'slug' => 'a'],
            ['site_id' => 1, 'slug' => 'b'],
            ['site_id' => 2, 'slug' => 'c'],
        ], '2026-06-09 21:00:00');
        $this->repo->markItemStarted($items[0]['item_id'], '2026-06-09 21:01:00');
        $this->repo->markItemSucceeded($items[0]['item_id'], '2026-06-09 21:02:00');
        $this->repo->markItemStarted($items[1]['item_id'], '2026-06-09 21:01:00');
        $this->repo->markItemFailed($items[1]['item_id'], '2026-06-09 21:03:00', 'boom');

        $response = rest_do_request($this->listRequest($this->token(1)));
        $body     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame(1, $body['total']);
        $job = $body['jobs'][0];
        $this->assertSame($jobId, $job['id']);
        $this->assertSame('plugin_update', $job['kind']);
        $this->assertSame(3, $job['scheduled_count']);
        $this->assertSame(1, $job['skipped_count']);
        $this->assertSame(1, $job['succeeded_count']);
        $this->assertSame(1, $job['failed_count']);
        $this->assertSame(0, $job['cancelled_count']);
        $this->assertSame(1, $job['queued_count']);
        $this->assertSame(0, $job['started_count']);
        $this->assertSame('in_progress', $job['state']);
        $this->assertSame('2026-06-09 21:01:00', $job['started_at']);
        $this->assertNull($job['completed_at']);
        $this->assertSame('2026-06-09 21:00:00', $job['created_at']);
    }

    public function testStatusFilterActiveAndCompleted(): void
    {
        $activeJob = $this->repo->createJob(1, 'plugin_update', 1, 0, '2026-06-09 21:00:00');
        $this->repo->createItems($activeJob, [['site_id' => 1, 'slug' => 'a']], '2026-06-09 21:00:00');

        $doneJob   = $this->repo->createJob(1, 'theme_update', 1, 0, '2026-06-09 21:05:00');
        $doneItems = $this->repo->createItems($doneJob, [['site_id' => 1, 'slug' => 'b']], '2026-06-09 21:05:00');
        $this->repo->markItemStarted($doneItems[0]['item_id'], '2026-06-09 21:06:00');
        $this->repo->markItemSucceeded($doneItems[0]['item_id'], '2026-06-09 21:07:00');

        $token = $this->token(1);

        $active = rest_do_request($this->listRequest($token, ['status' => 'active']))->get_data();
        $this->assertSame(1, $active['total']);
        $this->assertSame($activeJob, $active['jobs'][0]['id']);

        $completed = rest_do_request($this->listRequest($token, ['status' => 'completed']))->get_data();
        $this->assertSame(1, $completed['total']);
        $this->assertSame($doneJob, $completed['jobs'][0]['id']);
        $this->assertSame('completed', $completed['jobs'][0]['state']);

        $all = rest_do_request($this->listRequest($token, ['status' => 'all']))->get_data();
        $this->assertSame(2, $all['total']);
    }

    public function testPaginationRespectsPageAndPerPage(): void
    {
        foreach (['20:00:00', '21:00:00', '22:00:00'] as $time) {
            $jobId = $this->repo->createJob(1, 'plugin_update', 1, 0, "2026-06-09 {$time}");
            $this->repo->createItems($jobId, [['site_id' => 1, 'slug' => 'a']], "2026-06-09 {$time}");
        }

        $token = $this->token(1);

        $pageOne = rest_do_request($this->listRequest($token, ['page' => '1', 'per_page' => '2']))->get_data();
        $this->assertCount(2, $pageOne['jobs']);
        $this->assertSame(3, $pageOne['total']);
        $this->assertSame('2026-06-09 22:00:00', $pageOne['jobs'][0]['created_at'], 'newest first');

        $pageTwo = rest_do_request($this->listRequest($token, ['page' => '2', 'per_page' => '2']))->get_data();
        $this->assertCount(1, $pageTwo['jobs']);
        $this->assertSame(2, $pageTwo['page']);
        $this->assertSame('2026-06-09 20:00:00', $pageTwo['jobs'][0]['created_at']);
    }

    public function testForeignUsersJobsExcluded(): void
    {
        $foreignJob = $this->repo->createJob(2, 'plugin_update', 1, 0, '2026-06-09 21:00:00');
        $this->repo->createItems($foreignJob, [['site_id' => 9, 'slug' => 'x']], '2026-06-09 21:00:00');

        $body = rest_do_request($this->listRequest($this->token(1)))->get_data();

        $this->assertSame(0, $body['total']);
        $this->assertSame([], $body['jobs']);
    }

    public function testRateLimit429AfterThirtyFirstCall(): void
    {
        $token = $this->token(1);

        for ($i = 1; $i <= 30; $i++) {
            $response = rest_do_request($this->listRequest($token));
            $this->assertSame(200, $response->get_status(), "call #{$i} should be 200");
        }

        $response = rest_do_request($this->listRequest($token));
        $this->assertSame(429, $response->get_status(), 'call #31 should be 429');
        $this->assertSame('jobs.rate_limited', $response->get_data()['error']['code'] ?? null);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd packages/dashboard-plugin && composer test -- --filter JobsListControllerTest`

Expected: 7 FAIL — 404 `rest.route_not_found` on every authed request (route not registered).

- [ ] **Step 3: Create controller + RateLimit bucket + route**

Create `packages/dashboard-plugin/src/Rest/JobsListController.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Services\BulkJobAggregator;
use Defyn\Dashboard\Services\BulkJobsRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P2.9 — GET /defyn/v1/jobs (spec § 2.1).
 *
 * Paginated, status-filterable list of the operator's bulk jobs with
 * derived per-state counts + job-level state. Counts come from ONE
 * grouped query (countsByStateForJobs) — no per-row item loading.
 */
final class JobsListController
{
    private const DEFAULT_PER_PAGE = 20;
    private const MAX_PER_PAGE     = 100;

    public function __construct(
        private readonly BulkJobsRepository $jobs = new BulkJobsRepository(),
    ) {
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        // Defensive STDOUT guard — P2.2 plan-bug #4 carry-forward.
        ob_start();
        try {
            $userId  = (int) $request->get_param('_authenticated_user_id');
            $page    = max(1, (int) ($request->get_param('page') ?: 1));
            $perPage = (int) ($request->get_param('per_page') ?: self::DEFAULT_PER_PAGE);
            $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
            $status  = (string) ($request->get_param('status') ?: 'all');
            if (!in_array($status, ['active', 'completed', 'all'], true)) {
                $status = 'all';
            }
            $filter = $status === 'all' ? null : $status;
            $offset = ($page - 1) * $perPage;

            $rows  = $this->jobs->findAllForUser($userId, $filter, $perPage, $offset);
            $total = $this->jobs->countAllForUser($userId, $filter);

            $countsByJob = $this->jobs->countsByStateForJobs(
                array_map(static fn(array $r): int => (int) $r['id'], $rows)
            );

            $jobs = array_map(static function (array $row) use ($countsByJob): array {
                $counts = $countsByJob[(int) $row['id']] ?? BulkJobAggregator::emptyCounts();
                return self::presentJob($row, $counts);
            }, $rows);

            return new WP_REST_Response([
                'jobs'         => array_values($jobs),
                'total'        => $total,
                'page'         => $page,
                'per_page'     => $perPage,
                'generated_at' => gmdate('Y-m-d H:i:s'),
            ], 200);
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Shared job JSON shape — also used by JobsDetailController (DRY).
     *
     * @param array<string, mixed> $row Raw wp_defyn_bulk_jobs row.
     * @param array{queued: int, started: int, succeeded: int, failed: int, cancelled: int} $counts
     * @return array<string, mixed>
     */
    public static function presentJob(array $row, array $counts): array
    {
        return [
            'id'              => (int) $row['id'],
            'kind'            => (string) $row['kind'],
            'scheduled_count' => (int) $row['scheduled_count'],
            'skipped_count'   => (int) $row['skipped_count'],
            'succeeded_count' => $counts['succeeded'],
            'failed_count'    => $counts['failed'],
            'cancelled_count' => $counts['cancelled'],
            'queued_count'    => $counts['queued'],
            'started_count'   => $counts['started'],
            'state'           => BulkJobAggregator::deriveJobStateFromCounts($counts),
            'started_at'      => $row['started_at'],
            'completed_at'    => $row['completed_at'],
            'created_at'      => (string) $row['created_at'],
        ];
    }
}
```

Modify `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php`. Append after the `BULK_THEME_UPDATE_*` constants (lines 110–111):

```php
    // P2.9 — GET /jobs list. Per-MINUTE bucket — the SPA polls every 10s
    // while any job is active (mirror of P2.5's overview() 30/MIN shape).
    public const JOBS_LIST_LIMIT  = 30;
    public const JOBS_LIST_WINDOW = MINUTE_IN_SECONDS;
```

Append after the `bulkThemeUpdate()` method (before `clientIp()`):

```php
    /**
     * Permission callback for GET /jobs.
     *
     * Per-user, 30/MINUTE. Chains RequireAuth::check first (same pattern as
     * every post-P2.1 bucket). Distinct prefix `defyn_rl_jobsList_%d`.
     *
     * @return true|WP_Error
     */
    public static function jobsList(WP_REST_Request $request)
    {
        $authResult = RequireAuth::check($request);
        if (is_wp_error($authResult)) {
            return $authResult;
        }

        $userId = (int) $request->get_param('_authenticated_user_id');

        $key   = sprintf('defyn_rl_jobsList_%d', $userId);
        $count = (int) (get_transient($key) ?: 0);

        if ($count >= self::JOBS_LIST_LIMIT) {
            return new \WP_Error(
                'jobs.rate_limited',
                'Too many requests. The jobs list polls every few seconds — try again shortly.',
                ['status' => 429]
            );
        }

        set_transient($key, $count + 1, self::JOBS_LIST_WINDOW);
        return true;
    }
```

Modify `packages/dashboard-plugin/src/Rest/RestRouter.php` — insert AFTER the `/overview/bulk-update-themes` registration block and BEFORE the `/activity` registration:

```php
        // P2.9 — GET /jobs. Paginated, status-filterable list of the
        // operator's bulk jobs. RateLimit::jobsList is 30/MINUTE.
        register_rest_route(self::NAMESPACE, '/jobs', [
            'methods'             => 'GET',
            'callback'            => [new JobsListController(), 'handle'],
            'permission_callback' => [RateLimit::class, 'jobsList'],
        ]);
```

(No use-import needed — `JobsListController` shares the `Defyn\Dashboard\Rest` namespace, trap #24.)

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd packages/dashboard-plugin && composer test -- --filter JobsListControllerTest`

Expected: 7 PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Rest/JobsListController.php \
        packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php \
        packages/dashboard-plugin/src/Rest/RestRouter.php \
        packages/dashboard-plugin/tests/Integration/Rest/JobsListControllerTest.php
git commit -m "feat(p2-9): GET /jobs endpoint — paginated job list

JobsListController composes the response from findAllForUser +
countAllForUser + ONE grouped countsByStateForJobs query, then derives
per-job counts + state via BulkJobAggregator. presentJob() is the
shared job JSON shape (reused by the detail controller in Task 6).

Query params: page (default 1), per_page (default 20, max 100), status
active|completed|all (default all; invalid values coerce to all).
RateLimit::jobsList is 30/MINUTE per user (jobs.rate_limited).

7 integration tests: 401, empty list, derived counts/state, status
filters, pagination + newest-first ordering, foreign-user exclusion,
rate limit at call #31.

Per spec § 2.1."
```

---

## Task 6 — `JobsDetailController` (GET /jobs/{id}) + resource-resolution JOIN

**Files:**
- Create: `packages/dashboard-plugin/src/Rest/JobsDetailController.php`
- Modify: `packages/dashboard-plugin/src/Services/BulkJobsRepository.php` (add `findItemsForJobWithResources`)
- Modify: `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php` (jobsDetail bucket)
- Modify: `packages/dashboard-plugin/src/Rest/RestRouter.php` (register route)
- Test: `packages/dashboard-plugin/tests/Integration/Rest/JobsDetailControllerTest.php` (CREATE)

- [ ] **Step 1: Write the failing tests**

Create `packages/dashboard-plugin/tests/Integration/Rest/JobsDetailControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Services\BulkJobsRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

/**
 * P2.9 — Tests for GET /defyn/v1/jobs/{id}.
 *
 * @group integration
 */
final class JobsDetailControllerTest extends AbstractSchemaTestCase
{
    private BulkJobsRepository $repo;

    public function setUp(): void
    {
        parent::setUp();
        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
        $this->freshlyActivate('defyn_bulk_jobs');
        $this->freshlyActivate('defyn_bulk_job_items');
        $this->freshlyActivate('defyn_sites');
        $this->freshlyActivate('defyn_site_plugins');
        $this->freshlyActivate('defyn_site_themes');

        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL
        $wpdb->query('SET autocommit = 1');
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_bulk_job_items");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_bulk_jobs");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_site_plugins");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_site_themes");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_sites");
        // phpcs:enable WordPress.DB.PreparedSQL

        for ($i = 1; $i <= 10; $i++) {
            delete_transient("defyn_rl_jobsDetail_{$i}");
        }

        do_action('rest_api_init');

        $this->repo = new BulkJobsRepository();
    }

    private function token(int $userId): string
    {
        return (new TokenService(DEFYN_JWT_SECRET))->issueAccess($userId);
    }

    private function detailRequest(int $jobId, string $token): WP_REST_Request
    {
        $request = new WP_REST_Request('GET', "/defyn/v1/jobs/{$jobId}");
        $request->set_header('Authorization', 'Bearer ' . $token);
        return $request;
    }

    private function seedSite(int $userId, string $label): int
    {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'    => $userId,
            'url'        => 'https://ex-' . uniqid() . '.com',
            'label'      => $label,
            'status'     => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return (int) $wpdb->insert_id;
    }

    private function seedPlugin(int $siteId, string $slug, string $name, string $version, ?string $updateVersion): void
    {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert($wpdb->prefix . 'defyn_site_plugins', [
            'site_id'          => $siteId,
            'slug'             => $slug,
            'name'             => $name,
            'version'          => $version,
            'update_available' => $updateVersion !== null ? 1 : 0,
            'update_version'   => $updateVersion,
            'last_seen_at'     => $now,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
    }

    private function seedTheme(int $siteId, string $slug, string $name, string $version, ?string $updateVersion): void
    {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert($wpdb->prefix . 'defyn_site_themes', [
            'site_id'          => $siteId,
            'slug'             => $slug,
            'name'             => $name,
            'version'          => $version,
            'update_available' => $updateVersion !== null ? 1 : 0,
            'update_version'   => $updateVersion,
            'last_seen_at'     => $now,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
    }

    public function testAuthRequiredReturns401WhenNoBearerToken(): void
    {
        $response = rest_do_request(new WP_REST_Request('GET', '/defyn/v1/jobs/1'));
        $this->assertSame(401, $response->get_status());
    }

    public function testMissingJobReturns404NotFound(): void
    {
        $response = rest_do_request($this->detailRequest(999999, $this->token(1)));

        $this->assertSame(404, $response->get_status());
        $this->assertSame('jobs.not_found', $response->get_data()['error']['code'] ?? null);
    }

    public function testForeignJobReturns404NotFound(): void
    {
        $jobId = $this->repo->createJob(2, 'plugin_update', 1, 0, '2026-06-09 21:00:00');
        $this->repo->createItems($jobId, [['site_id' => 1, 'slug' => 'a']], '2026-06-09 21:00:00');

        // Guardrail #7: foreign job is indistinguishable from missing (404, not 403).
        $response = rest_do_request($this->detailRequest($jobId, $this->token(1)));

        $this->assertSame(404, $response->get_status());
        $this->assertSame('jobs.not_found', $response->get_data()['error']['code'] ?? null);
    }

    public function testHappyPathPluginJobResolvesResourceFields(): void
    {
        $siteId = $this->seedSite(1, 'SmartCoding');
        $this->seedPlugin($siteId, 'akismet', 'Akismet Anti-Spam', '5.3', '5.3.1');

        $jobId = $this->repo->createJob(1, 'plugin_update', 1, 0, '2026-06-09 20:59:15');
        $items = $this->repo->createItems($jobId, [['site_id' => $siteId, 'slug' => 'akismet']], '2026-06-09 20:59:15');
        $this->repo->markItemStarted($items[0]['item_id'], '2026-06-09 21:00:02');
        $this->repo->markItemSucceeded($items[0]['item_id'], '2026-06-09 21:00:11');

        $response = rest_do_request($this->detailRequest($jobId, $this->token(1)));
        $body     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame($jobId, $body['job']['id']);
        $this->assertSame('completed', $body['job']['state']);
        $this->assertSame(1, $body['job']['succeeded_count']);

        $item = $body['items'][0];
        $this->assertSame($siteId, $item['site_id']);
        $this->assertSame('SmartCoding', $item['site_label']);
        $this->assertSame('akismet', $item['resource_slug']);
        $this->assertSame('Akismet Anti-Spam', $item['resource_name']);
        $this->assertSame('5.3', $item['current_version']);
        $this->assertSame('5.3.1', $item['target_version']);
        $this->assertSame('succeeded', $item['state']);
        $this->assertNull($item['error_message']);
        $this->assertSame('2026-06-09 21:00:02', $item['started_at']);
        $this->assertSame('2026-06-09 21:00:11', $item['completed_at']);
    }

    public function testDeletedResourceAndSiteFallBackToSlugAndPlaceholderLabel(): void
    {
        // NO site row + NO plugin row seeded — both LEFT JOINs miss.
        $jobId = $this->repo->createJob(1, 'plugin_update', 1, 0, '2026-06-09 21:00:00');
        $this->repo->createItems($jobId, [['site_id' => 42, 'slug' => 'ghost-plugin']], '2026-06-09 21:00:00');

        $body = rest_do_request($this->detailRequest($jobId, $this->token(1)))->get_data();
        $item = $body['items'][0];

        $this->assertSame('ghost-plugin', $item['resource_name'], 'falls back to slug per spec § 2.2');
        $this->assertNull($item['current_version']);
        $this->assertNull($item['target_version']);
        $this->assertSame('Site #42', $item['site_label']);
    }

    public function testThemeJobResolvesAgainstThemesTable(): void
    {
        $siteId = $this->seedSite(1, 'AcmeBlog');
        $this->seedTheme($siteId, 'astra', 'Astra', '4.6.3', '4.7.0');

        $jobId = $this->repo->createJob(1, 'theme_update', 1, 0, '2026-06-09 21:00:00');
        $this->repo->createItems($jobId, [['site_id' => $siteId, 'slug' => 'astra']], '2026-06-09 21:00:00');

        $body = rest_do_request($this->detailRequest($jobId, $this->token(1)))->get_data();
        $item = $body['items'][0];

        $this->assertSame('Astra', $item['resource_name']);
        $this->assertSame('4.6.3', $item['current_version']);
        $this->assertSame('4.7.0', $item['target_version']);
        $this->assertSame('queued', $item['state']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd packages/dashboard-plugin && composer test -- --filter JobsDetailControllerTest`

Expected: 6 FAIL — 404 `rest.route_not_found` (route not registered yet; note the auth test passes only after registration, so expect failures across the board).

- [ ] **Step 3: Repo JOIN method + controller + RateLimit bucket + route**

Append to `packages/dashboard-plugin/src/Services/BulkJobsRepository.php` (after `findItemForJob`, before the lifecycle-marks section). Also extend the class's use-imports. Old imports:

```php
use Defyn\Dashboard\Schema\BulkJobItemsTable;
use Defyn\Dashboard\Schema\BulkJobsTable;
```

New imports:

```php
use Defyn\Dashboard\Schema\BulkJobItemsTable;
use Defyn\Dashboard\Schema\BulkJobsTable;
use Defyn\Dashboard\Schema\SitePluginsTable;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Schema\SiteThemesTable;
```

New method:

```php
    /**
     * Detail-view items with response-time resource resolution (spec § 2.2):
     * LEFT JOIN defyn_sites for the label and the kind's inventory table for
     * name/current/target versions. Deleted rows yield NULLs — the controller
     * substitutes the slug / "Site #N" fallbacks.
     *
     * @return list<array<string, mixed>>
     */
    public function findItemsForJobWithResources(int $jobId, string $kind): array
    {
        global $wpdb;
        $items    = BulkJobItemsTable::tableName();
        $sites    = SitesTable::tableName();
        $resource = $kind === 'theme_update'
            ? SiteThemesTable::tableName()
            : SitePluginsTable::tableName();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT i.*, s.label AS site_label,
                    r.name AS resource_name,
                    r.version AS resource_current_version,
                    r.update_version AS resource_target_version
             FROM {$items} i
             LEFT JOIN {$sites} s ON s.id = i.site_id
             LEFT JOIN {$resource} r ON r.site_id = i.site_id AND r.slug = i.resource_slug
             WHERE i.job_id = %d
             ORDER BY i.id ASC",
            $jobId
        ), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }
```

Create `packages/dashboard-plugin/src/Rest/JobsDetailController.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\BulkJobAggregator;
use Defyn\Dashboard\Services\BulkJobsRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P2.9 — GET /defyn/v1/jobs/{id} (spec § 2.2).
 *
 * Job header (via JobsListController::presentJob — shared shape) + items
 * with response-time resource resolution. Missing inventory rows fall back
 * to resource_slug / null versions; missing sites to "Site #N".
 */
final class JobsDetailController
{
    public function __construct(
        private readonly BulkJobsRepository $jobs = new BulkJobsRepository(),
    ) {
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        // Defensive STDOUT guard — P2.2 plan-bug #4 carry-forward.
        ob_start();
        try {
            $userId = (int) $request->get_param('_authenticated_user_id');
            $jobId  = (int) $request['id'];

            $job = $this->jobs->findByIdForUser($jobId, $userId);
            if ($job === null) {
                // Guardrail #7/#14 — 404 for missing AND foreign jobs.
                return ErrorResponse::create(404, 'jobs.not_found', 'Job not found.');
            }

            $rows   = $this->jobs->findItemsForJobWithResources($jobId, (string) $job['kind']);
            $counts = BulkJobAggregator::countsByState($rows);

            $items = array_map(static fn(array $row): array => [
                'id'              => (int) $row['id'],
                'site_id'         => (int) $row['site_id'],
                'site_label'      => $row['site_label'] !== null
                    ? (string) $row['site_label']
                    : 'Site #' . (int) $row['site_id'],
                'resource_slug'   => (string) $row['resource_slug'],
                'resource_name'   => $row['resource_name'] !== null
                    ? (string) $row['resource_name']
                    : (string) $row['resource_slug'],
                'current_version' => $row['resource_current_version'] !== null
                    ? (string) $row['resource_current_version'] : null,
                'target_version'  => $row['resource_target_version'] !== null
                    ? (string) $row['resource_target_version'] : null,
                'state'           => (string) $row['state'],
                'error_message'   => $row['error_message'] !== null ? (string) $row['error_message'] : null,
                'started_at'      => $row['started_at'],
                'completed_at'    => $row['completed_at'],
                'created_at'      => (string) $row['created_at'],
            ], $rows);

            return new WP_REST_Response([
                'job'          => JobsListController::presentJob($job, $counts),
                'items'        => array_values($items),
                'generated_at' => gmdate('Y-m-d H:i:s'),
            ], 200);
        } finally {
            ob_end_clean();
        }
    }
}
```

Modify `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php` — append after the `JOBS_LIST_*` constants:

```php
    // P2.9 — GET /jobs/{id} detail. Per-MINUTE — the SPA polls every 5s
    // while any item is queued/started.
    public const JOBS_DETAIL_LIMIT  = 30;
    public const JOBS_DETAIL_WINDOW = MINUTE_IN_SECONDS;
```

Append after the `jobsList()` method:

```php
    /**
     * Permission callback for GET /jobs/{id}.
     *
     * Per-user, 30/MINUTE. Distinct prefix `defyn_rl_jobsDetail_%d`.
     *
     * @return true|WP_Error
     */
    public static function jobsDetail(WP_REST_Request $request)
    {
        $authResult = RequireAuth::check($request);
        if (is_wp_error($authResult)) {
            return $authResult;
        }

        $userId = (int) $request->get_param('_authenticated_user_id');

        $key   = sprintf('defyn_rl_jobsDetail_%d', $userId);
        $count = (int) (get_transient($key) ?: 0);

        if ($count >= self::JOBS_DETAIL_LIMIT) {
            return new \WP_Error(
                'jobs.rate_limited',
                'Too many requests. The job detail polls every few seconds — try again shortly.',
                ['status' => 429]
            );
        }

        set_transient($key, $count + 1, self::JOBS_DETAIL_WINDOW);
        return true;
    }
```

Modify `packages/dashboard-plugin/src/Rest/RestRouter.php` — insert AFTER the `/jobs` GET registration (Task 5), BEFORE `/activity`:

```php
        // P2.9 — GET /jobs/{id}. Job header + items with response-time
        // resource resolution. RateLimit::jobsDetail is 30/MINUTE.
        register_rest_route(self::NAMESPACE, '/jobs/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [new JobsDetailController(), 'handle'],
            'permission_callback' => [RateLimit::class, 'jobsDetail'],
        ]);
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd packages/dashboard-plugin && composer test -- --filter JobsDetailControllerTest`

Expected: 6 PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Rest/JobsDetailController.php \
        packages/dashboard-plugin/src/Services/BulkJobsRepository.php \
        packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php \
        packages/dashboard-plugin/src/Rest/RestRouter.php \
        packages/dashboard-plugin/tests/Integration/Rest/JobsDetailControllerTest.php
git commit -m "feat(p2-9): GET /jobs/{id} endpoint — detail with resource resolution

JobsDetailController returns the shared presentJob() header + items
resolved at response time via BulkJobsRepository::
findItemsForJobWithResources — LEFT JOIN defyn_sites (label) + the
kind-appropriate inventory table (name/current/target). Deleted
inventory rows fall back to resource_slug + null versions; deleted
sites to 'Site #N' (spec § 2.2).

404 jobs.not_found for missing AND foreign jobs (guardrail #7 — no
existence leak). RateLimit::jobsDetail is 30/MINUTE per user.

6 integration tests: 401, 404 missing, 404 foreign, plugin-kind happy
path, deleted-resource fallback, theme-kind resolution.

Per spec § 2.2."
```

---

## Task 7 — `JobsCancelController` (POST /jobs/{id}/cancel)

**Files:**
- Create: `packages/dashboard-plugin/src/Rest/JobsCancelController.php`
- Modify: `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php` (jobsCancel bucket)
- Modify: `packages/dashboard-plugin/src/Rest/RestRouter.php` (register route)
- Test: `packages/dashboard-plugin/tests/Integration/Rest/JobsCancelControllerTest.php` (CREATE)

- [ ] **Step 1: Write the failing tests**

Create `packages/dashboard-plugin/tests/Integration/Rest/JobsCancelControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Services\BulkJobsRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

/**
 * P2.9 — Tests for POST /defyn/v1/jobs/{id}/cancel.
 *
 * @group integration
 */
final class JobsCancelControllerTest extends AbstractSchemaTestCase
{
    private BulkJobsRepository $repo;

    public function setUp(): void
    {
        parent::setUp();
        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
        $this->freshlyActivate('defyn_bulk_jobs');
        $this->freshlyActivate('defyn_bulk_job_items');

        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL
        $wpdb->query('SET autocommit = 1');
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_bulk_job_items");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_bulk_jobs");
        // phpcs:enable WordPress.DB.PreparedSQL

        for ($i = 1; $i <= 10; $i++) {
            delete_transient("defyn_rl_jobsCancel_{$i}");
        }

        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('defyn_update_site_plugin');
            as_unschedule_all_actions('defyn_update_site_theme');
        }

        do_action('rest_api_init');

        $this->repo = new BulkJobsRepository();
    }

    private function token(int $userId): string
    {
        return (new TokenService(DEFYN_JWT_SECRET))->issueAccess($userId);
    }

    private function cancelRequest(int $jobId, string $token): WP_REST_Request
    {
        $request = new WP_REST_Request('POST', "/defyn/v1/jobs/{$jobId}/cancel");
        $request->set_header('Authorization', 'Bearer ' . $token);
        return $request;
    }

    public function testAuthRequiredReturns401WhenNoBearerToken(): void
    {
        $response = rest_do_request(new WP_REST_Request('POST', '/defyn/v1/jobs/1/cancel'));
        $this->assertSame(401, $response->get_status());
    }

    public function testForeignJobReturns404NotFound(): void
    {
        $jobId = $this->repo->createJob(2, 'plugin_update', 1, 0, '2026-06-09 21:00:00');
        $this->repo->createItems($jobId, [['site_id' => 1, 'slug' => 'a']], '2026-06-09 21:00:00');

        $response = rest_do_request($this->cancelRequest($jobId, $this->token(1)));

        $this->assertSame(404, $response->get_status());
        $this->assertSame('jobs.not_found', $response->get_data()['error']['code'] ?? null);
    }

    public function testCancelUnschedulesQueuedItemsAndMarksThemCancelled(): void
    {
        $jobId = $this->repo->createJob(1, 'plugin_update', 3, 0, '2026-06-09 21:00:00');
        $items = $this->repo->createItems($jobId, [
            ['site_id' => 5, 'slug' => 'akismet'],
            ['site_id' => 5, 'slug' => 'yoast'],
            ['site_id' => 6, 'slug' => 'elementor'],
        ], '2026-06-09 21:00:00');

        // Schedule the matching AS actions exactly like the bulk controller does (4-tuple + 'defyn' group).
        foreach ($items as $item) {
            as_schedule_single_action(
                time() + 60,
                'defyn_update_site_plugin',
                [$item['site_id'], $item['slug'], 0, $item['item_id']],
                'defyn'
            );
        }
        // Third item is already running — must NOT be cancellable.
        $this->repo->markItemStarted($items[2]['item_id'], '2026-06-09 21:01:00');

        $response = rest_do_request($this->cancelRequest($jobId, $this->token(1)));
        $body     = $response->get_data();

        $this->assertSame(200, $response->get_status(), 'cancel is synchronous — 200, not 202 (guardrail #13)');
        $this->assertSame(2, $body['cancelled_count']);
        $this->assertSame(1, $body['still_running_count']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $body['cancelled_at']);

        global $wpdb;
        $states = $wpdb->get_col($wpdb->prepare(
            "SELECT state FROM {$wpdb->prefix}defyn_bulk_job_items WHERE job_id = %d ORDER BY id ASC",
            $jobId
        ));
        $this->assertSame(['cancelled', 'cancelled', 'started'], $states);

        // Guardrail #4 — the EXACT 4-tuples were unscheduled.
        foreach ([0, 1] as $i) {
            $pending = as_get_scheduled_actions([
                'hook'   => 'defyn_update_site_plugin',
                'args'   => [$items[$i]['site_id'], $items[$i]['slug'], 0, $items[$i]['item_id']],
                'status' => \ActionScheduler_Store::STATUS_PENDING,
            ]);
            $this->assertCount(0, $pending, "queued item #{$i} should be unscheduled");
        }
        $stillPending = as_get_scheduled_actions([
            'hook'   => 'defyn_update_site_plugin',
            'args'   => [$items[2]['site_id'], $items[2]['slug'], 0, $items[2]['item_id']],
            'status' => \ActionScheduler_Store::STATUS_PENDING,
        ]);
        $this->assertCount(1, $stillPending, 'started item keeps its AS action');
    }

    public function testCancelOnFinishedJobIsIdempotentNoOp(): void
    {
        $jobId = $this->repo->createJob(1, 'theme_update', 1, 0, '2026-06-09 21:00:00');
        $items = $this->repo->createItems($jobId, [['site_id' => 1, 'slug' => 'astra']], '2026-06-09 21:00:00');
        $this->repo->markItemStarted($items[0]['item_id'], '2026-06-09 21:01:00');
        $this->repo->markItemSucceeded($items[0]['item_id'], '2026-06-09 21:02:00');

        $response = rest_do_request($this->cancelRequest($jobId, $this->token(1)));
        $body     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame(0, $body['cancelled_count']);
        $this->assertSame(0, $body['still_running_count']);
    }

    public function testRateLimit429AfterSixthCall(): void
    {
        $jobId = $this->repo->createJob(1, 'plugin_update', 1, 0, '2026-06-09 21:00:00');
        $this->repo->createItems($jobId, [['site_id' => 1, 'slug' => 'a']], '2026-06-09 21:00:00');
        $token = $this->token(1);

        for ($i = 1; $i <= 5; $i++) {
            $response = rest_do_request($this->cancelRequest($jobId, $token));
            $this->assertNotSame(429, $response->get_status(), "call #{$i} should not be 429");
        }

        $response = rest_do_request($this->cancelRequest($jobId, $token));
        $this->assertSame(429, $response->get_status(), 'call #6 should be 429');
        $this->assertSame('jobs.rate_limited', $response->get_data()['error']['code'] ?? null);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd packages/dashboard-plugin && composer test -- --filter JobsCancelControllerTest`

Expected: 5 FAIL — 404 `rest.route_not_found`.

- [ ] **Step 3: Controller + RateLimit bucket + route**

Create `packages/dashboard-plugin/src/Rest/JobsCancelController.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Jobs\UpdateSitePlugin;
use Defyn\Dashboard\Jobs\UpdateSiteTheme;
use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\BulkJobsRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P2.9 — POST /defyn/v1/jobs/{id}/cancel (spec § 2.3).
 *
 * Unschedules every still-queued item's AS action (exact 4-tuple match —
 * guardrail #4) and marks the items cancelled. Items already `started`
 * can't be interrupted mid-upgrade; they keep running and are surfaced
 * via still_running_count. Synchronous + idempotent — always 200
 * (guardrail #13).
 */
final class JobsCancelController
{
    public function __construct(
        private readonly BulkJobsRepository $jobs = new BulkJobsRepository(),
    ) {
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        // Defensive STDOUT guard — P2.2 plan-bug #4 carry-forward.
        ob_start();
        try {
            $userId = (int) $request->get_param('_authenticated_user_id');
            $jobId  = (int) $request['id'];

            $job = $this->jobs->findByIdForUser($jobId, $userId);
            if ($job === null) {
                return ErrorResponse::create(404, 'jobs.not_found', 'Job not found.');
            }

            $hook = $job['kind'] === 'theme_update'
                ? UpdateSiteTheme::HOOK
                : UpdateSitePlugin::HOOK;

            $now    = gmdate('Y-m-d H:i:s');
            $queued = $this->jobs->findQueuedItemsForJob($jobId);

            foreach ($queued as $item) {
                // Guardrail #4 — exact schedule-time 4-tuple + 'defyn' group.
                as_unschedule_action($hook, [$item['site_id'], $item['slug'], 0, $item['item_id']], 'defyn');
                $this->jobs->markItemCancelled($item['item_id'], $now);
            }

            return new WP_REST_Response([
                'cancelled_count'     => count($queued),
                'still_running_count' => $this->jobs->countItemsByStateForJob($jobId, 'started'),
                'cancelled_at'        => $now,
            ], 200);
        } finally {
            ob_end_clean();
        }
    }
}
```

Modify `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php` — append after the `JOBS_DETAIL_*` constants:

```php
    // P2.9 — POST /jobs/{id}/cancel. 5/HOUR — a control action with fan-out
    // side effects (N as_unschedule calls); same weight class as the bulk
    // POST endpoints.
    public const JOBS_CANCEL_LIMIT  = 5;
    public const JOBS_CANCEL_WINDOW = HOUR_IN_SECONDS;
```

Append after the `jobsDetail()` method:

```php
    /**
     * Permission callback for POST /jobs/{id}/cancel.
     *
     * Per-user, 5/HOUR. Distinct prefix `defyn_rl_jobsCancel_%d`.
     *
     * @return true|WP_Error
     */
    public static function jobsCancel(WP_REST_Request $request)
    {
        $authResult = RequireAuth::check($request);
        if (is_wp_error($authResult)) {
            return $authResult;
        }

        $userId = (int) $request->get_param('_authenticated_user_id');

        $key   = sprintf('defyn_rl_jobsCancel_%d', $userId);
        $count = (int) (get_transient($key) ?: 0);

        if ($count >= self::JOBS_CANCEL_LIMIT) {
            return new \WP_Error(
                'jobs.rate_limited',
                'Too many cancel requests. Try again in an hour.',
                ['status' => 429]
            );
        }

        set_transient($key, $count + 1, self::JOBS_CANCEL_WINDOW);
        return true;
    }
```

Modify `packages/dashboard-plugin/src/Rest/RestRouter.php` — insert AFTER the `/jobs/(?P<id>\d+)` GET registration:

```php
        // P2.9 — POST /jobs/{id}/cancel. Unschedules + cancels all
        // still-queued items (started items keep running — UI is honest).
        // RateLimit::jobsCancel is 5/HOUR.
        register_rest_route(self::NAMESPACE, '/jobs/(?P<id>\d+)/cancel', [
            'methods'             => 'POST',
            'callback'            => [new JobsCancelController(), 'handle'],
            'permission_callback' => [RateLimit::class, 'jobsCancel'],
        ]);
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd packages/dashboard-plugin && composer test -- --filter JobsCancelControllerTest`

Expected: 5 PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Rest/JobsCancelController.php \
        packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php \
        packages/dashboard-plugin/src/Rest/RestRouter.php \
        packages/dashboard-plugin/tests/Integration/Rest/JobsCancelControllerTest.php
git commit -m "feat(p2-9): POST /jobs/{id}/cancel endpoint

Cancels all still-queued items: as_unschedule_action with the EXACT
schedule-time 4-tuple [siteId, slug, 0, jobItemId] + 'defyn' group
(guardrail #4), then markItemCancelled (queued-only transition —
guardrail #6). Items already started keep running and are reported via
still_running_count. Synchronous + idempotent — always 200, never 202
(guardrail #13). Hook chosen by job kind (plugin vs theme).

RateLimit::jobsCancel is 5/HOUR per user.

5 integration tests: 401, 404 foreign, happy cancel (AS unschedule
verified per-tuple + started item untouched), idempotent no-op on a
finished job, rate limit at call #6.

Per spec § 2.3."
```

---

## Task 8 — `JobsRetryItemController` + `JobsRetryFailedController`

**Files:**
- Create: `packages/dashboard-plugin/src/Rest/JobsRetryItemController.php`
- Create: `packages/dashboard-plugin/src/Rest/JobsRetryFailedController.php`
- Modify: `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php` (2 buckets)
- Modify: `packages/dashboard-plugin/src/Rest/RestRouter.php` (2 routes)
- Test: `packages/dashboard-plugin/tests/Integration/Rest/JobsRetryControllersTest.php` (CREATE)

- [ ] **Step 1: Write the failing tests**

Create `packages/dashboard-plugin/tests/Integration/Rest/JobsRetryControllersTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Services\BulkJobsRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

/**
 * P2.9 — Tests for POST /jobs/{id}/items/{item_id}/retry +
 * POST /jobs/{id}/retry-failed.
 *
 * @group integration
 */
final class JobsRetryControllersTest extends AbstractSchemaTestCase
{
    private BulkJobsRepository $repo;

    public function setUp(): void
    {
        parent::setUp();
        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
        $this->freshlyActivate('defyn_bulk_jobs');
        $this->freshlyActivate('defyn_bulk_job_items');

        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL
        $wpdb->query('SET autocommit = 1');
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_bulk_job_items");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_bulk_jobs");
        // phpcs:enable WordPress.DB.PreparedSQL

        for ($i = 1; $i <= 10; $i++) {
            delete_transient("defyn_rl_jobsRetryItem_{$i}");
            delete_transient("defyn_rl_jobsRetryFailed_{$i}");
        }

        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('defyn_update_site_plugin');
            as_unschedule_all_actions('defyn_update_site_theme');
        }

        do_action('rest_api_init');

        $this->repo = new BulkJobsRepository();
    }

    private function token(int $userId): string
    {
        return (new TokenService(DEFYN_JWT_SECRET))->issueAccess($userId);
    }

    private function post(string $path, string $token): WP_REST_Request
    {
        $request = new WP_REST_Request('POST', $path);
        $request->set_header('Authorization', 'Bearer ' . $token);
        return $request;
    }

    /** @return array{0: int, 1: list<array{site_id: int, slug: string, item_id: int}>} */
    private function jobWithFailedItem(string $kind = 'plugin_update'): array
    {
        $jobId = $this->repo->createJob(1, $kind, 2, 0, '2026-06-09 21:00:00');
        $items = $this->repo->createItems($jobId, [
            ['site_id' => 5, 'slug' => 'akismet'],
            ['site_id' => 5, 'slug' => 'yoast'],
        ], '2026-06-09 21:00:00');
        $this->repo->markItemStarted($items[0]['item_id'], '2026-06-09 21:01:00');
        $this->repo->markItemFailed($items[0]['item_id'], '2026-06-09 21:02:00', 'boom');
        return [$jobId, $items];
    }

    public function testAuthRequiredReturns401OnBothEndpoints(): void
    {
        $this->assertSame(401, rest_do_request(new WP_REST_Request('POST', '/defyn/v1/jobs/1/items/1/retry'))->get_status());
        $this->assertSame(401, rest_do_request(new WP_REST_Request('POST', '/defyn/v1/jobs/1/retry-failed'))->get_status());
    }

    public function testRetryItemHappyPath202RequeuesAndReschedules(): void
    {
        [$jobId, $items] = $this->jobWithFailedItem();
        $failedId = $items[0]['item_id'];

        $response = rest_do_request($this->post("/defyn/v1/jobs/{$jobId}/items/{$failedId}/retry", $this->token(1)));
        $body     = $response->get_data();

        $this->assertSame(202, $response->get_status(), 'retry is async re-queue — 202 (guardrail #13)');
        $this->assertSame($failedId, $body['item_id']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $body['scheduled_at']);

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}defyn_bulk_job_items WHERE id = %d", $failedId),
            ARRAY_A
        );
        $this->assertSame('queued', $row['state']);
        $this->assertNull($row['error_message']);
        $this->assertNull($row['started_at']);
        $this->assertNull($row['completed_at']);

        // Re-scheduled with the same 4-tuple shape the bulk fan-out uses.
        $pending = as_get_scheduled_actions([
            'hook'   => 'defyn_update_site_plugin',
            'args'   => [5, 'akismet', 0, $failedId],
            'status' => \ActionScheduler_Store::STATUS_PENDING,
        ]);
        $this->assertCount(1, $pending);
    }

    public function testRetryItemReturns400WhenItemNotFailed(): void
    {
        [$jobId, $items] = $this->jobWithFailedItem();
        $queuedId = $items[1]['item_id']; // still queued

        $response = rest_do_request($this->post("/defyn/v1/jobs/{$jobId}/items/{$queuedId}/retry", $this->token(1)));

        $this->assertSame(400, $response->get_status());
        $this->assertSame('jobs.item_not_retryable', $response->get_data()['error']['code'] ?? null);
    }

    public function testRetryItemReturns404ForMissingItemAndForeignJob(): void
    {
        [$jobId] = $this->jobWithFailedItem();

        $missing = rest_do_request($this->post("/defyn/v1/jobs/{$jobId}/items/999999/retry", $this->token(1)));
        $this->assertSame(404, $missing->get_status());
        $this->assertSame('jobs.item_not_found', $missing->get_data()['error']['code'] ?? null);

        $foreign = rest_do_request($this->post("/defyn/v1/jobs/{$jobId}/items/1/retry", $this->token(2)));
        $this->assertSame(404, $foreign->get_status());
        $this->assertSame('jobs.not_found', $foreign->get_data()['error']['code'] ?? null);
    }

    public function testRetryFailedHappyPath202RetriesAllFailedItems(): void
    {
        $jobId = $this->repo->createJob(1, 'theme_update', 3, 0, '2026-06-09 21:00:00');
        $items = $this->repo->createItems($jobId, [
            ['site_id' => 7, 'slug' => 'astra'],
            ['site_id' => 7, 'slug' => 'blocksy'],
            ['site_id' => 8, 'slug' => 'kadence'],
        ], '2026-06-09 21:00:00');
        foreach ([0, 1] as $i) {
            $this->repo->markItemStarted($items[$i]['item_id'], '2026-06-09 21:01:00');
            $this->repo->markItemFailed($items[$i]['item_id'], '2026-06-09 21:02:00', 'boom');
        }
        $this->repo->markItemStarted($items[2]['item_id'], '2026-06-09 21:01:00');
        $this->repo->markItemSucceeded($items[2]['item_id'], '2026-06-09 21:02:00');

        $response = rest_do_request($this->post("/defyn/v1/jobs/{$jobId}/retry-failed", $this->token(1)));
        $body     = $response->get_data();

        $this->assertSame(202, $response->get_status());
        $this->assertSame(2, $body['retried_count']);
        $this->assertSame([$items[0]['item_id'], $items[1]['item_id']], $body['retried_item_ids']);

        // Theme-kind job re-schedules the THEME hook with each item's 4-tuple.
        foreach ([0, 1] as $i) {
            $pending = as_get_scheduled_actions([
                'hook'   => 'defyn_update_site_theme',
                'args'   => [$items[$i]['site_id'], $items[$i]['slug'], 0, $items[$i]['item_id']],
                'status' => \ActionScheduler_Store::STATUS_PENDING,
            ]);
            $this->assertCount(1, $pending);
        }

        global $wpdb;
        $states = $wpdb->get_col($wpdb->prepare(
            "SELECT state FROM {$wpdb->prefix}defyn_bulk_job_items WHERE job_id = %d ORDER BY id ASC",
            $jobId
        ));
        $this->assertSame(['queued', 'queued', 'succeeded'], $states);
    }

    public function testRetryFailedNoOpReturns200WhenNothingFailed(): void
    {
        $jobId = $this->repo->createJob(1, 'plugin_update', 1, 0, '2026-06-09 21:00:00');
        $items = $this->repo->createItems($jobId, [['site_id' => 1, 'slug' => 'a']], '2026-06-09 21:00:00');
        $this->repo->markItemStarted($items[0]['item_id'], '2026-06-09 21:01:00');
        $this->repo->markItemSucceeded($items[0]['item_id'], '2026-06-09 21:02:00');

        $response = rest_do_request($this->post("/defyn/v1/jobs/{$jobId}/retry-failed", $this->token(1)));
        $body     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame(0, $body['retried_count']);
        $this->assertSame([], $body['retried_item_ids']);
    }

    public function testRetryFailedReturns404ForForeignJob(): void
    {
        [$jobId] = $this->jobWithFailedItem();

        $response = rest_do_request($this->post("/defyn/v1/jobs/{$jobId}/retry-failed", $this->token(2)));

        $this->assertSame(404, $response->get_status());
        $this->assertSame('jobs.not_found', $response->get_data()['error']['code'] ?? null);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd packages/dashboard-plugin && composer test -- --filter JobsRetryControllersTest`

Expected: 7 FAIL — 404 `rest.route_not_found`.

- [ ] **Step 3: Two controllers + 2 RateLimit buckets + 2 routes**

Create `packages/dashboard-plugin/src/Rest/JobsRetryItemController.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Jobs\UpdateSitePlugin;
use Defyn\Dashboard\Jobs\UpdateSiteTheme;
use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\BulkJobsRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P2.9 — POST /defyn/v1/jobs/{id}/items/{item_id}/retry (spec § 2.4).
 *
 * Only `failed` items are retryable. Resets the item to `queued` (clearing
 * error + timestamps; refreshJobTimestamps un-finalizes the job) and
 * re-schedules the kind-appropriate AS hook with the same 4-tuple shape the
 * bulk fan-out uses. 202 — the work is async.
 */
final class JobsRetryItemController
{
    public function __construct(
        private readonly BulkJobsRepository $jobs = new BulkJobsRepository(),
    ) {
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        // Defensive STDOUT guard — P2.2 plan-bug #4 carry-forward.
        ob_start();
        try {
            $userId = (int) $request->get_param('_authenticated_user_id');
            $jobId  = (int) $request['id'];
            $itemId = (int) $request['item_id'];

            $job = $this->jobs->findByIdForUser($jobId, $userId);
            if ($job === null) {
                return ErrorResponse::create(404, 'jobs.not_found', 'Job not found.');
            }

            $item = $this->jobs->findItemForJob($jobId, $itemId);
            if ($item === null) {
                return ErrorResponse::create(404, 'jobs.item_not_found', 'Job item not found.');
            }
            if ((string) $item['state'] !== 'failed') {
                return ErrorResponse::create(400, 'jobs.item_not_retryable', 'Only failed items can be retried.');
            }

            $now = gmdate('Y-m-d H:i:s');
            $this->jobs->resetItemForRetry($itemId, $now);

            $hook = $job['kind'] === 'theme_update'
                ? UpdateSiteTheme::HOOK
                : UpdateSitePlugin::HOOK;

            as_schedule_single_action(
                time(),
                $hook,
                [(int) $item['site_id'], (string) $item['resource_slug'], 0, $itemId],
                'defyn'
            );

            return new WP_REST_Response([
                'item_id'      => $itemId,
                'scheduled_at' => $now,
            ], 202);
        } finally {
            ob_end_clean();
        }
    }
}
```

Create `packages/dashboard-plugin/src/Rest/JobsRetryFailedController.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Jobs\UpdateSitePlugin;
use Defyn\Dashboard\Jobs\UpdateSiteTheme;
use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\BulkJobsRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P2.9 — POST /defyn/v1/jobs/{id}/retry-failed (spec § 2.5).
 *
 * Bulk variant of the per-item retry: ONE request re-queues every failed
 * item in the job. 202 when retried_count > 0; 200 no-op when the job has
 * no failed items (guardrail #13).
 */
final class JobsRetryFailedController
{
    public function __construct(
        private readonly BulkJobsRepository $jobs = new BulkJobsRepository(),
    ) {
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        // Defensive STDOUT guard — P2.2 plan-bug #4 carry-forward.
        ob_start();
        try {
            $userId = (int) $request->get_param('_authenticated_user_id');
            $jobId  = (int) $request['id'];

            $job = $this->jobs->findByIdForUser($jobId, $userId);
            if ($job === null) {
                return ErrorResponse::create(404, 'jobs.not_found', 'Job not found.');
            }

            $failed = array_values(array_filter(
                $this->jobs->findItemsForJob($jobId),
                static fn(array $item): bool => (string) $item['state'] === 'failed'
            ));

            $now  = gmdate('Y-m-d H:i:s');
            $hook = $job['kind'] === 'theme_update'
                ? UpdateSiteTheme::HOOK
                : UpdateSitePlugin::HOOK;

            $retriedIds = [];
            foreach ($failed as $item) {
                $itemId = (int) $item['id'];
                $this->jobs->resetItemForRetry($itemId, $now);
                as_schedule_single_action(
                    time(),
                    $hook,
                    [(int) $item['site_id'], (string) $item['resource_slug'], 0, $itemId],
                    'defyn'
                );
                $retriedIds[] = $itemId;
            }

            return new WP_REST_Response([
                'retried_count'    => count($retriedIds),
                'retried_item_ids' => $retriedIds,
                'scheduled_at'     => $now,
            ], count($retriedIds) > 0 ? 202 : 200);
        } finally {
            ob_end_clean();
        }
    }
}
```

Modify `packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php` — append after the `JOBS_CANCEL_*` constants:

```php
    // P2.9 — POST /jobs/{id}/items/{item_id}/retry. 20/HOUR — looser than
    // the bulk buckets because per-item retries legitimately come in bursts
    // after a flaky-network bulk run.
    public const JOBS_RETRY_ITEM_LIMIT  = 20;
    public const JOBS_RETRY_ITEM_WINDOW = HOUR_IN_SECONDS;

    // P2.9 — POST /jobs/{id}/retry-failed. 5/HOUR — bulk fan-out, same
    // weight class as bulkPluginUpdate / bulkThemeUpdate / jobsCancel.
    public const JOBS_RETRY_FAILED_LIMIT  = 5;
    public const JOBS_RETRY_FAILED_WINDOW = HOUR_IN_SECONDS;
```

Append after the `jobsCancel()` method:

```php
    /**
     * Permission callback for POST /jobs/{id}/items/{item_id}/retry.
     *
     * Per-user, 20/HOUR. Distinct prefix `defyn_rl_jobsRetryItem_%d`.
     *
     * @return true|WP_Error
     */
    public static function jobsRetryItem(WP_REST_Request $request)
    {
        $authResult = RequireAuth::check($request);
        if (is_wp_error($authResult)) {
            return $authResult;
        }

        $userId = (int) $request->get_param('_authenticated_user_id');

        $key   = sprintf('defyn_rl_jobsRetryItem_%d', $userId);
        $count = (int) (get_transient($key) ?: 0);

        if ($count >= self::JOBS_RETRY_ITEM_LIMIT) {
            return new \WP_Error(
                'jobs.rate_limited',
                'Too many retry requests. Try again in an hour.',
                ['status' => 429]
            );
        }

        set_transient($key, $count + 1, self::JOBS_RETRY_ITEM_WINDOW);
        return true;
    }

    /**
     * Permission callback for POST /jobs/{id}/retry-failed.
     *
     * Per-user, 5/HOUR. Distinct prefix `defyn_rl_jobsRetryFailed_%d`.
     *
     * @return true|WP_Error
     */
    public static function jobsRetryFailed(WP_REST_Request $request)
    {
        $authResult = RequireAuth::check($request);
        if (is_wp_error($authResult)) {
            return $authResult;
        }

        $userId = (int) $request->get_param('_authenticated_user_id');

        $key   = sprintf('defyn_rl_jobsRetryFailed_%d', $userId);
        $count = (int) (get_transient($key) ?: 0);

        if ($count >= self::JOBS_RETRY_FAILED_LIMIT) {
            return new \WP_Error(
                'jobs.rate_limited',
                'Too many bulk retry requests. Try again in an hour.',
                ['status' => 429]
            );
        }

        set_transient($key, $count + 1, self::JOBS_RETRY_FAILED_WINDOW);
        return true;
    }
```

Modify `packages/dashboard-plugin/src/Rest/RestRouter.php` — insert AFTER the `/jobs/(?P<id>\d+)/cancel` registration, BEFORE `/activity`:

```php
        // P2.9 — POST /jobs/{id}/items/{item_id}/retry. Only `failed` items
        // are retryable. RateLimit::jobsRetryItem is 20/HOUR.
        register_rest_route(self::NAMESPACE, '/jobs/(?P<id>\d+)/items/(?P<item_id>\d+)/retry', [
            'methods'             => 'POST',
            'callback'            => [new JobsRetryItemController(), 'handle'],
            'permission_callback' => [RateLimit::class, 'jobsRetryItem'],
        ]);

        // P2.9 — POST /jobs/{id}/retry-failed. Re-queues every failed item
        // in one request. RateLimit::jobsRetryFailed is 5/HOUR.
        register_rest_route(self::NAMESPACE, '/jobs/(?P<id>\d+)/retry-failed', [
            'methods'             => 'POST',
            'callback'            => [new JobsRetryFailedController(), 'handle'],
            'permission_callback' => [RateLimit::class, 'jobsRetryFailed'],
        ]);
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd packages/dashboard-plugin && composer test -- --filter JobsRetryControllersTest`

Expected: 7 PASS. Then `composer test` — only the 3 carry-forward failures.

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Rest/JobsRetryItemController.php \
        packages/dashboard-plugin/src/Rest/JobsRetryFailedController.php \
        packages/dashboard-plugin/src/Rest/Middleware/RateLimit.php \
        packages/dashboard-plugin/src/Rest/RestRouter.php \
        packages/dashboard-plugin/tests/Integration/Rest/JobsRetryControllersTest.php
git commit -m "feat(p2-9): retry endpoints — per-item + retry-failed

POST /jobs/{id}/items/{item_id}/retry (20/HR): 404 jobs.item_not_found
for foreign/missing items, 400 jobs.item_not_retryable unless state is
failed; resets the item to queued (clears error + timestamps; job
completed_at un-finalized via refreshJobTimestamps) and re-schedules
the kind-appropriate AS hook with the bulk fan-out's 4-tuple. 202.

POST /jobs/{id}/retry-failed (5/HR): same reset+reschedule for EVERY
failed item in one request. 202 when retried_count > 0, 200 no-op
otherwise (guardrail #13).

7 integration tests covering both endpoints' happy/no-op/error paths +
AS re-schedule tuple verification per kind.

Per spec § 2.4 + § 2.5."
```

---

## Task 9 — Bulk controllers create jobs + 4-arg fan-out + `job_id` in response

**Files:**
- Modify: `packages/dashboard-plugin/src/Rest/OverviewBulkUpdatePluginsController.php`
- Modify: `packages/dashboard-plugin/src/Rest/OverviewBulkUpdateThemesController.php`
- Modify: `packages/dashboard-plugin/tests/Integration/Rest/OverviewBulkUpdatePluginsControllerTest.php`
- Modify: `packages/dashboard-plugin/tests/Integration/Rest/OverviewBulkUpdateThemesControllerTest.php`

**Current code being modified (read both controllers first).** `OverviewBulkUpdatePluginsController::handle` currently schedules INSIDE the validation loop with 3 args:

```php
                as_schedule_single_action(time(), 'defyn_update_site_plugin', [$siteId, $slug, 0], 'defyn');
                $scheduled[] = ['site_id' => $siteId, 'slug' => $slug];
```

and returns the envelope without `job_id`. The themes controller is the mirror (`'defyn_update_site_theme'`, `theme_not_found`). Trap #33: the restructure moves ALL scheduling out of the validation loop.

- [ ] **Step 1: Write the failing tests (extend BOTH existing test classes)**

Modify `packages/dashboard-plugin/tests/Integration/Rest/OverviewBulkUpdateThemesControllerTest.php`:

Edit A — setUp gains the bulk tables. Old:

```php
        $this->freshlyActivate('defyn_site_themes');
        $this->freshlyActivate('defyn_activity_log');
```

New:

```php
        $this->freshlyActivate('defyn_site_themes');
        $this->freshlyActivate('defyn_activity_log');
        $this->freshlyActivate('defyn_bulk_jobs');
        $this->freshlyActivate('defyn_bulk_job_items');
```

Edit B — setUp purges them. Old:

```php
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_activity_log");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_site_themes");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_sites");
```

New:

```php
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_bulk_job_items");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_bulk_jobs");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_activity_log");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_site_themes");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_sites");
```

Edit C — `testFanOutSchedulesPerPair` must match the NEW 4-tuple. Old:

```php
        $astraJobs = as_get_scheduled_actions([
            'hook' => 'defyn_update_site_theme',
            'args' => [$siteA, 'astra', 0],
        ]);
        $this->assertGreaterThanOrEqual(1, count($astraJobs));
```

New:

```php
        global $wpdb;
        $itemId = (int) $wpdb->get_var(
            "SELECT id FROM {$wpdb->prefix}defyn_bulk_job_items WHERE resource_slug = 'astra'"
        );
        $this->assertGreaterThan(0, $itemId, 'bulk_job_items row must exist for the scheduled pair');

        $astraJobs = as_get_scheduled_actions([
            'hook' => 'defyn_update_site_theme',
            'args' => [$siteA, 'astra', 0, $itemId],
        ]);
        $this->assertGreaterThanOrEqual(1, count($astraJobs), 'AS action must carry the item id as 4th arg');
```

Edit D — append two NEW test methods before the `seedSite` helper:

```php
    public function testHappyPathCreatesJobAndItemsAndReturnsJobId(): void
    {
        $siteA = $this->seedSite(1, 'SmartCoding');
        $this->seedTheme($siteA, 'astra',   'Astra',   '4.6.3', '4.7.0', true);
        $this->seedTheme($siteA, 'blocksy', 'Blocksy', '2.0.1', '2.0.2', true);

        $token   = $this->token(1);
        $request = new WP_REST_Request('POST', '/defyn/v1/overview/bulk-update-themes');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode(['updates' => [
            ['site_id' => $siteA, 'slug' => 'astra'],
            ['site_id' => $siteA, 'slug' => 'blocksy'],
        ]]));
        $response = rest_do_request($request);
        $body     = $response->get_data();

        $this->assertSame(202, $response->get_status());
        $this->assertIsInt($body['job_id']);

        global $wpdb;
        $job = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}defyn_bulk_jobs WHERE id = %d",
            $body['job_id']
        ), ARRAY_A);
        $this->assertSame('1', $job['user_id']);
        $this->assertSame('theme_update', $job['kind']);
        $this->assertSame('2', $job['scheduled_count']);
        $this->assertSame('0', $job['skipped_count']);

        $itemCount = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}defyn_bulk_job_items WHERE job_id = %d AND state = 'queued'",
            $body['job_id']
        ));
        $this->assertSame(2, $itemCount, 'one queued item per scheduled pair');
    }

    public function testZeroValidPairsReturnsNullJobIdAndNoJobRow(): void
    {
        $token   = $this->token(1);
        $request = new WP_REST_Request('POST', '/defyn/v1/overview/bulk-update-themes');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode(['updates' => [['site_id' => 999, 'slug' => 'ghost']]]));
        $response = rest_do_request($request);
        $body     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertNull($body['job_id'], 'guardrail #12 — null job_id when nothing scheduled');

        global $wpdb;
        $jobCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}defyn_bulk_jobs");
        $this->assertSame(0, $jobCount, 'guardrail #12 — no job row when nothing scheduled');
    }
```

Modify `packages/dashboard-plugin/tests/Integration/Rest/OverviewBulkUpdatePluginsControllerTest.php` — the same four changes, plugin-flavoured:

Edit A/B — in `setUp()`, add `$this->freshlyActivate('defyn_bulk_jobs');` + `$this->freshlyActivate('defyn_bulk_job_items');` immediately after the existing `freshlyActivate` calls, and add `$wpdb->query("DELETE FROM {$wpdb->prefix}defyn_bulk_job_items");` + `$wpdb->query("DELETE FROM {$wpdb->prefix}defyn_bulk_jobs");` as the FIRST two lines of the existing DELETE block.

Edit C — `testFanOutSchedulesPerPair` (lines 145–167) currently asserts the two 3-arg tuples. Old:

```php
        $akismetJobs = as_get_scheduled_actions([
            'hook' => 'defyn_update_site_plugin',
            'args' => [$siteA, 'akismet', 0],
        ]);
```

New:

```php
        global $wpdb;
        $akismetItemId = (int) $wpdb->get_var(
            "SELECT id FROM {$wpdb->prefix}defyn_bulk_job_items WHERE resource_slug = 'akismet'"
        );
        $akismetJobs = as_get_scheduled_actions([
            'hook' => 'defyn_update_site_plugin',
            'args' => [$siteA, 'akismet', 0, $akismetItemId],
        ]);
```

and old:

```php
        $yoastJobs = as_get_scheduled_actions([
            'hook' => 'defyn_update_site_plugin',
            'args' => [$siteA, 'yoast', 0],
        ]);
```

new:

```php
        $yoastItemId = (int) $wpdb->get_var(
            "SELECT id FROM {$wpdb->prefix}defyn_bulk_job_items WHERE resource_slug = 'yoast'"
        );
        $yoastJobs = as_get_scheduled_actions([
            'hook' => 'defyn_update_site_plugin',
            'args' => [$siteA, 'yoast', 0, $yoastItemId],
        ]);
```

(If the test method already declares `global $wpdb;` earlier in its body, keep only one declaration.)

Edit D — append the two NEW test methods (mirror of the themes versions with `bulk-update-plugins` path, `seedPlugin(...)` fixtures, `'kind' === 'plugin_update'`):

```php
    public function testHappyPathCreatesJobAndItemsAndReturnsJobId(): void
    {
        $siteA = $this->seedSite(1, 'SmartCoding');
        $this->seedPlugin($siteA, 'akismet', 'Akismet', '5.3', '5.3.1', true);
        $this->seedPlugin($siteA, 'yoast',   'Yoast',   '22.5', '22.6', true);

        $token   = $this->token(1);
        $request = new WP_REST_Request('POST', '/defyn/v1/overview/bulk-update-plugins');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode(['updates' => [
            ['site_id' => $siteA, 'slug' => 'akismet'],
            ['site_id' => $siteA, 'slug' => 'yoast'],
        ]]));
        $response = rest_do_request($request);
        $body     = $response->get_data();

        $this->assertSame(202, $response->get_status());
        $this->assertIsInt($body['job_id']);

        global $wpdb;
        $job = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}defyn_bulk_jobs WHERE id = %d",
            $body['job_id']
        ), ARRAY_A);
        $this->assertSame('plugin_update', $job['kind']);
        $this->assertSame('2', $job['scheduled_count']);

        $itemCount = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}defyn_bulk_job_items WHERE job_id = %d AND state = 'queued'",
            $body['job_id']
        ));
        $this->assertSame(2, $itemCount);
    }

    public function testZeroValidPairsReturnsNullJobIdAndNoJobRow(): void
    {
        $token   = $this->token(1);
        $request = new WP_REST_Request('POST', '/defyn/v1/overview/bulk-update-plugins');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode(['updates' => [['site_id' => 999, 'slug' => 'ghost']]]));
        $response = rest_do_request($request);
        $body     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertNull($body['job_id']);

        global $wpdb;
        $jobCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}defyn_bulk_jobs");
        $this->assertSame(0, $jobCount);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd packages/dashboard-plugin && composer test -- --filter "OverviewBulkUpdatePluginsControllerTest|OverviewBulkUpdateThemesControllerTest"`

Expected: the 4 new tests FAIL (`job_id` key missing) + both `testFanOutSchedulesPerPair` FAIL (no item row exists / args don't match).

- [ ] **Step 3: Restructure both controllers**

Modify `packages/dashboard-plugin/src/Rest/OverviewBulkUpdatePluginsController.php`:

Edit 1 — use-imports. Old:

```php
use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\SitePluginsRepository;
```

New:

```php
use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\BulkJobsRepository;
use Defyn\Dashboard\Services\SitePluginsRepository;
```

Edit 2 — constructor. Old:

```php
    public function __construct(
        private readonly SitesRepository $sites = new SitesRepository(),
        private readonly SitePluginsRepository $plugins = new SitePluginsRepository(),
        private readonly ActivityLogger $logger = new ActivityLogger(),
    ) {
    }
```

New:

```php
    public function __construct(
        private readonly SitesRepository $sites = new SitesRepository(),
        private readonly SitePluginsRepository $plugins = new SitePluginsRepository(),
        private readonly ActivityLogger $logger = new ActivityLogger(),
        private readonly BulkJobsRepository $bulkJobs = new BulkJobsRepository(),
    ) {
    }
```

Edit 3 — the scheduling + event + response section. Old (current lines 75–101):

```php
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
```

New:

```php
                $scheduled[] = ['site_id' => $siteId, 'slug' => $slug];
            }

            $now   = gmdate('Y-m-d H:i:s');
            $jobId = null;

            if (count($scheduled) > 0) {
                // P2.9 (trap #33) — create the tracked job + items BEFORE the
                // AS fan-out so each action carries its item id as 4th arg.
                // Guardrail #12 — job row ONLY when something was scheduled.
                $jobId    = $this->bulkJobs->createJob($userId, 'plugin_update', count($scheduled), count($skipped), $now);
                $enriched = $this->bulkJobs->createItems($jobId, $scheduled, $now);

                foreach ($enriched as $pair) {
                    as_schedule_single_action(
                        time(),
                        'defyn_update_site_plugin',
                        [$pair['site_id'], $pair['slug'], 0, $pair['item_id']],
                        'defyn'
                    );
                }

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
                    'job_id'          => $jobId,
                    'scheduled_count' => count($scheduled),
                    'skipped_count'   => count($skipped),
                    'scheduled_pairs' => array_values($scheduled),
                    'skipped_pairs'   => array_values($skipped),
                    'scheduled_at'    => $now,
                ],
                count($scheduled) > 0 ? 202 : 200
            );
```

Modify `packages/dashboard-plugin/src/Rest/OverviewBulkUpdateThemesController.php` — same three edits with the theme substitutions:

Edit 1 — add `use Defyn\Dashboard\Services\BulkJobsRepository;` between the `ActivityLogger` and `SitesRepository` imports.

Edit 2 — constructor gains `private readonly BulkJobsRepository $bulkJobs = new BulkJobsRepository(),` after the `$logger` parameter (identical shape to the plugins edit).

Edit 3 — old (current lines 78–104):

```php
                as_schedule_single_action(time(), 'defyn_update_site_theme', [$siteId, $slug, 0], 'defyn');
                $scheduled[] = ['site_id' => $siteId, 'slug' => $slug];
            }

            if (count($scheduled) > 0) {
                $this->logger->log(
                    $userId,
                    null,                                              // fleet-scoped — guardrail #4
                    'overview.bulk_theme_update_requested',            // exact string — guardrail #1
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
```

New:

```php
                $scheduled[] = ['site_id' => $siteId, 'slug' => $slug];
            }

            $now   = gmdate('Y-m-d H:i:s');
            $jobId = null;

            if (count($scheduled) > 0) {
                // P2.9 (trap #33) — create the tracked job + items BEFORE the
                // AS fan-out so each action carries its item id as 4th arg.
                // Guardrail #12 — job row ONLY when something was scheduled.
                $jobId    = $this->bulkJobs->createJob($userId, 'theme_update', count($scheduled), count($skipped), $now);
                $enriched = $this->bulkJobs->createItems($jobId, $scheduled, $now);

                foreach ($enriched as $pair) {
                    as_schedule_single_action(
                        time(),
                        'defyn_update_site_theme',
                        [$pair['site_id'], $pair['slug'], 0, $pair['item_id']],
                        'defyn'
                    );
                }

                $this->logger->log(
                    $userId,
                    null,                                              // fleet-scoped — guardrail #4
                    'overview.bulk_theme_update_requested',            // exact string — guardrail #1
                    [
                        'scheduled_count' => count($scheduled),
                        'skipped_count'   => count($skipped),
                        'pairs'           => array_values($scheduled),
                    ]
                );
            }

            return new WP_REST_Response(
                [
                    'job_id'          => $jobId,
                    'scheduled_count' => count($scheduled),
                    'skipped_count'   => count($skipped),
                    'scheduled_pairs' => array_values($scheduled),
                    'skipped_pairs'   => array_values($skipped),
                    'scheduled_at'    => $now,
                ],
                count($scheduled) > 0 ? 202 : 200
            );
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd packages/dashboard-plugin && composer test -- --filter "OverviewBulkUpdatePluginsControllerTest|OverviewBulkUpdateThemesControllerTest"`

Expected: ALL pass (8 + 2 new per class). Then `composer test` — only the 3 carry-forward failures (the P2.7/P2.8 activity-event + skip-reason tests are untouched and must stay green).

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Rest/OverviewBulkUpdatePluginsController.php \
        packages/dashboard-plugin/src/Rest/OverviewBulkUpdateThemesController.php \
        packages/dashboard-plugin/tests/Integration/Rest/OverviewBulkUpdatePluginsControllerTest.php \
        packages/dashboard-plugin/tests/Integration/Rest/OverviewBulkUpdateThemesControllerTest.php
git commit -m "feat(p2-9): bulk controllers create tracked jobs + 4-arg fan-out

Both bulk endpoints restructured: the validation loop now ONLY
partitions into scheduled/skipped; when scheduled_count > 0 the
controller creates the wp_defyn_bulk_jobs parent + one queued item per
pair (single multi-row INSERT), then fan-outs each AS action with the
NEW 4th arg [siteId, slug, 0, jobItemId]. Response envelope gains
job_id (null on the all-skipped 200 path — guardrail #12; no job row
is written either). Existing fleet-scoped activity events unchanged.

Existing fan-out tests updated to assert the 4-tuple (item id read
back from wp_defyn_bulk_job_items); 2 new tests per controller cover
job+items creation and the null-job_id/no-row guarantee.

Per spec § 1 (controller integration) + § 2.6."
```

---

## Task 10 — `UpdateSitePlugin` + `UpdateSiteTheme` lifecycle marks + `Plugin.php` 4-arg hooks

**Files:**
- Modify: `packages/dashboard-plugin/src/Jobs/UpdateSitePlugin.php`
- Modify: `packages/dashboard-plugin/src/Jobs/UpdateSiteTheme.php`
- Modify: `packages/dashboard-plugin/src/Plugin.php`
- Test: `packages/dashboard-plugin/tests/Integration/Jobs/UpdateSitePluginBulkJobItemTest.php` (CREATE)
- Test: `packages/dashboard-plugin/tests/Integration/Jobs/UpdateSiteThemeBulkJobItemTest.php` (CREATE)

**WHERE the marks go — the branch map (read this before touching code).** Current `UpdateSitePlugin::handle` (lines 48–147) has these branches; each gets exactly one treatment:

| Branch | Current code | P2.9 treatment |
|---|---|---|
| `$site === null` early return (line 50–53) | `return;` | TERMINAL → `markItemFailed(..., 'Site no longer exists.')` — without this the item hangs in `queued` forever |
| `$row === null` early return (line 55–58) | `return;` | TERMINAL → `markItemFailed(..., 'Plugin row no longer exists.')` |
| after row found, before HTTP call | `markUpdating` | ENTRY → `markItemStarted` (idempotent — guarded `queued`-only UPDATE, so 409-retry re-entries already `started` are no-ops) |
| 200 success (lines 85–96) | `markUpdateSucceeded` + log, `return;` | TERMINAL → `markItemSucceeded` before the return |
| 409 busy + `$attempt >= 5` (lines 104–117) | `markUpdateFailed('Site is busy after 5 retries.')`, `return;` | TERMINAL → `markItemFailed(..., 'Site is busy after 5 retries.')` |
| 409 busy retry (lines 119–127) | `as_schedule_single_action(..., [$siteId, $slug, $attempt + 1])`, `return;` | NOT TERMINAL → reschedule with `[$siteId, $slug, $attempt + 1, $jobItemId]`; NO mark — item stays `started` (in-flight) |
| final failure (lines 138–146) | `markUpdateFailed($errorMessage)` | TERMINAL → `markItemFailed(..., $errorMessage)` |

`UpdateSiteTheme::handle` has ONE extra branch (lines 131–142): 409 `themes.no_update_available` = success-by-other-means → TERMINAL → `markItemSucceeded` (the upgrade goal was achieved by someone else; the item is NOT a failure).

All marks are wrapped in `if ($jobItemId > 0)` — `$jobItemId = 0` means "no bulk-job tracking" (per-site P2.2/P2.3 endpoints + pre-v0.9.0 AS rows).

- [ ] **Step 1: Write the failing tests**

Create `packages/dashboard-plugin/tests/Integration/Jobs/UpdateSitePluginBulkJobItemTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Jobs;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Jobs\UpdateSitePlugin;
use Defyn\Dashboard\Schema\ActivityLogTable;
use Defyn\Dashboard\Schema\SitePluginsTable;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\BulkJobsRepository;
use Defyn\Dashboard\Services\SitePluginsRepository;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * P2.9 — bulk-job item lifecycle marks inside UpdateSitePlugin::handle.
 *
 * Mirrors UpdateSitePluginTest's MockHttpClient harness; asserts ONLY the
 * new $jobItemId behavior (the existing tests keep covering the per-site
 * row transitions).
 *
 * @group integration
 */
final class UpdateSitePluginBulkJobItemTest extends AbstractSchemaTestCase
{
    private BulkJobsRepository $bulkJobs;

    public function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_sites');
        $this->freshlyActivate('defyn_site_plugins');
        $this->freshlyActivate('defyn_activity_log');
        $this->freshlyActivate('defyn_bulk_jobs');
        $this->freshlyActivate('defyn_bulk_job_items');

        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL
        $wpdb->query('TRUNCATE ' . SitesTable::tableName());
        $wpdb->query('TRUNCATE ' . SitePluginsTable::tableName());
        $wpdb->query('TRUNCATE ' . ActivityLogTable::tableName());
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_bulk_job_items");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_bulk_jobs");
        // phpcs:enable WordPress.DB.PreparedSQL

        $this->bulkJobs = new BulkJobsRepository();
    }

    /** Active site with real Vault-encrypted dashboard key (UpdateSiteThemeTest pattern). */
    private function makeActiveSite(): int
    {
        $repo    = new SitesRepository();
        $vault   = new Vault(DEFYN_VAULT_KEY);
        $keypair = sodium_crypto_sign_keypair();
        $priv    = base64_encode(sodium_crypto_sign_secretkey($keypair));
        $id      = $repo->insertPending(
            userId: 1,
            url: 'https://smartcoding.test',
            label: 'Smart',
            ourPublicKey: base64_encode(sodium_crypto_sign_publickey($keypair)),
            ourPrivateKeyEncrypted: $vault->encrypt($priv),
        );
        $repo->markActive($id, base64_encode(random_bytes(32)));
        return $id;
    }

    private function seedAkismetRow(int $siteId): void
    {
        global $wpdb;
        $wpdb->insert(SitePluginsTable::tableName(), [
            'site_id'          => $siteId,
            'slug'             => 'akismet',
            'name'             => 'Akismet',
            'version'          => '5.3',
            'update_available' => 1,
            'update_version'   => '5.3.1',
            'update_state'     => 'queued',
            'last_seen_at'     => '2026-06-09 05:00:00',
            'created_at'       => '2026-06-09 05:00:00',
            'updated_at'       => '2026-06-09 05:00:00',
        ]);
    }

    private function makeJobItem(int $siteId, string $slug = 'akismet'): int
    {
        $jobId = $this->bulkJobs->createJob(1, 'plugin_update', 1, 0, '2026-06-09 21:00:00');
        $items = $this->bulkJobs->createItems($jobId, [['site_id' => $siteId, 'slug' => $slug]], '2026-06-09 21:00:00');
        return $items[0]['item_id'];
    }

    private function itemRow(int $itemId): array
    {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}defyn_bulk_job_items WHERE id = %d", $itemId),
            ARRAY_A
        );
    }

    private function makeJob(callable $responseFactory): UpdateSitePlugin
    {
        return new UpdateSitePlugin(
            new SitesRepository(),
            new SitePluginsRepository(),
            new SignedHttpClient(new MockHttpClient($responseFactory)),
            new ActivityLogger(),
            new Vault(DEFYN_VAULT_KEY),
        );
    }

    public function testItemMarkedStartedAndBusyRetryReschedulesWithItemId(): void
    {
        $siteId = $this->makeActiveSite();
        $this->seedAkismetRow($siteId);
        $itemId = $this->makeJobItem($siteId);

        $body = (string) json_encode(['error' => ['code' => 'plugins.update_in_progress', 'message' => 'busy']]);
        $job  = $this->makeJob(fn () => new MockResponse($body, [
            'http_code'        => 409,
            'response_headers' => ['content-type: application/json'],
        ]));

        $scheduled = [];
        \add_filter('pre_as_schedule_single_action', function ($pre, $when, $hook, $args) use (&$scheduled) {
            $scheduled[] = ['hook' => $hook, 'args' => $args];
            return 999;
        }, 10, 4);

        $job->handle($siteId, 'akismet', 0, $itemId);

        // Item entered `started` at handle entry and STAYS started across the
        // retry (retry-rescheduling must NOT mark terminal).
        $item = $this->itemRow($itemId);
        self::assertSame('started', $item['state']);
        self::assertNotNull($item['started_at']);
        self::assertNull($item['completed_at']);

        // The rescheduled action carries the SAME item id as 4th arg.
        self::assertCount(1, $scheduled);
        self::assertSame(UpdateSitePlugin::HOOK, $scheduled[0]['hook']);
        self::assertSame([$siteId, 'akismet', 1, $itemId], $scheduled[0]['args']);
    }

    public function testItemMarkedSucceededOnSuccess(): void
    {
        $siteId = $this->makeActiveSite();
        $this->seedAkismetRow($siteId);
        $itemId = $this->makeJobItem($siteId);

        $body = (string) json_encode([
            'success'          => true,
            'slug'             => 'akismet',
            'previous_version' => '5.3',
            'new_version'      => '5.3.1',
            'server_time'      => time(),
        ]);
        $job = $this->makeJob(fn () => new MockResponse($body, [
            'http_code'        => 200,
            'response_headers' => ['content-type: application/json'],
        ]));

        $job->handle($siteId, 'akismet', 0, $itemId);

        $item = $this->itemRow($itemId);
        self::assertSame('succeeded', $item['state']);
        self::assertNotNull($item['completed_at']);

        // Single-item job — refreshJobTimestamps finalizes the parent too.
        global $wpdb;
        $jobRow = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}defyn_bulk_jobs WHERE id = %d", (int) $item['job_id']),
            ARRAY_A
        );
        self::assertNotNull($jobRow['started_at']);
        self::assertNotNull($jobRow['completed_at']);
    }

    public function testItemMarkedFailedOnConnectorFailure(): void
    {
        $siteId = $this->makeActiveSite();
        $this->seedAkismetRow($siteId);
        $itemId = $this->makeJobItem($siteId);

        $body = (string) json_encode([
            'error' => ['code' => 'plugins.update_failed', 'message' => 'Could not copy file.'],
        ]);
        $job = $this->makeJob(fn () => new MockResponse($body, [
            'http_code'        => 502,
            'response_headers' => ['content-type: application/json'],
        ]));

        $job->handle($siteId, 'akismet', 0, $itemId);

        $item = $this->itemRow($itemId);
        self::assertSame('failed', $item['state']);
        self::assertStringContainsString('Could not copy file', $item['error_message']);
        self::assertNotNull($item['completed_at']);
    }

    public function testItemMarkedFailedWhenBusyAfterFiveRetries(): void
    {
        $siteId = $this->makeActiveSite();
        $this->seedAkismetRow($siteId);
        $itemId = $this->makeJobItem($siteId);

        $body = (string) json_encode(['error' => ['code' => 'plugins.update_in_progress', 'message' => 'busy']]);
        $job  = $this->makeJob(fn () => new MockResponse($body, [
            'http_code'        => 409,
            'response_headers' => ['content-type: application/json'],
        ]));

        $job->handle($siteId, 'akismet', 5, $itemId);

        $item = $this->itemRow($itemId);
        self::assertSame('failed', $item['state']);
        self::assertStringContainsString('busy after 5 retries', $item['error_message']);
    }

    public function testItemMarkedFailedWhenSiteRowMissing(): void
    {
        $itemId = $this->makeJobItem(999999);

        $job = $this->makeJob(fn () => new MockResponse('{}', ['http_code' => 200]));
        $job->handle(999999, 'akismet', 0, $itemId);

        $item = $this->itemRow($itemId);
        self::assertSame('failed', $item['state']);
        self::assertStringContainsString('Site no longer exists', $item['error_message']);
    }

    public function testNoBulkRowsTouchedWhenJobItemIdIsZero(): void
    {
        $siteId = $this->makeActiveSite();
        $this->seedAkismetRow($siteId);
        $unrelatedItemId = $this->makeJobItem($siteId);

        $body = (string) json_encode([
            'success'          => true,
            'slug'             => 'akismet',
            'previous_version' => '5.3',
            'new_version'      => '5.3.1',
            'server_time'      => time(),
        ]);
        $job = $this->makeJob(fn () => new MockResponse($body, [
            'http_code'        => 200,
            'response_headers' => ['content-type: application/json'],
        ]));

        // Backwards-compat: 3-arg call (pre-v0.9.0 AS rows / per-site P2.2 endpoint).
        $job->handle($siteId, 'akismet', 0);

        $item = $this->itemRow($unrelatedItemId);
        self::assertSame('queued', $item['state'], 'jobItemId=0 must not touch any bulk rows');
        self::assertNull($item['started_at']);
    }
}
```

Create `packages/dashboard-plugin/tests/Integration/Jobs/UpdateSiteThemeBulkJobItemTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Jobs;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Jobs\UpdateSiteTheme;
use Defyn\Dashboard\Schema\ActivityLogTable;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Schema\SiteThemesTable;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\BulkJobsRepository;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Services\ThemesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * P2.9 — bulk-job item lifecycle marks inside UpdateSiteTheme::handle,
 * including the theme-specific 409 themes.no_update_available
 * success-by-other-means path (MUST mark the item succeeded).
 *
 * @group integration
 */
final class UpdateSiteThemeBulkJobItemTest extends AbstractSchemaTestCase
{
    private BulkJobsRepository $bulkJobs;

    public function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_sites');
        $this->freshlyActivate('defyn_site_themes');
        $this->freshlyActivate('defyn_activity_log');
        $this->freshlyActivate('defyn_bulk_jobs');
        $this->freshlyActivate('defyn_bulk_job_items');

        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL
        $wpdb->query('TRUNCATE ' . SitesTable::tableName());
        $wpdb->query('TRUNCATE ' . SiteThemesTable::tableName());
        $wpdb->query('TRUNCATE ' . ActivityLogTable::tableName());
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_bulk_job_items");
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_bulk_jobs");
        // phpcs:enable WordPress.DB.PreparedSQL

        $this->bulkJobs = new BulkJobsRepository();
    }

    private function makeActiveSite(): int
    {
        $repo    = new SitesRepository();
        $vault   = new Vault(DEFYN_VAULT_KEY);
        $keypair = sodium_crypto_sign_keypair();
        $priv    = base64_encode(sodium_crypto_sign_secretkey($keypair));
        $id      = $repo->insertPending(
            userId: 1,
            url: 'https://smartcoding.test',
            label: 'Smart',
            ourPublicKey: base64_encode(sodium_crypto_sign_publickey($keypair)),
            ourPrivateKeyEncrypted: $vault->encrypt($priv),
        );
        $repo->markActive($id, base64_encode(random_bytes(32)));
        return $id;
    }

    private function seedAstraRow(int $siteId): void
    {
        global $wpdb;
        $wpdb->insert(SiteThemesTable::tableName(), [
            'site_id'          => $siteId,
            'slug'             => 'astra',
            'name'             => 'Astra',
            'version'          => '4.6.3',
            'parent_slug'      => null,
            'is_active'        => 1,
            'update_available' => 1,
            'update_version'   => '4.7.0',
            'update_state'     => 'queued',
            'last_seen_at'     => '2026-06-09 05:00:00',
            'created_at'       => '2026-06-09 05:00:00',
            'updated_at'       => '2026-06-09 05:00:00',
        ]);
    }

    private function makeJobItem(int $siteId): int
    {
        $jobId = $this->bulkJobs->createJob(1, 'theme_update', 1, 0, '2026-06-09 21:00:00');
        $items = $this->bulkJobs->createItems($jobId, [['site_id' => $siteId, 'slug' => 'astra']], '2026-06-09 21:00:00');
        return $items[0]['item_id'];
    }

    private function itemRow(int $itemId): array
    {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$wpdb->prefix}defyn_bulk_job_items WHERE id = %d", $itemId),
            ARRAY_A
        );
    }

    private function makeJob(callable $responseFactory): UpdateSiteTheme
    {
        return new UpdateSiteTheme(
            new SitesRepository(),
            new ThemesRepository(),
            new SignedHttpClient(new MockHttpClient($responseFactory)),
            new ActivityLogger(),
            new Vault(DEFYN_VAULT_KEY),
        );
    }

    public function testItemMarkedStartedAndBusyRetryReschedulesWithItemId(): void
    {
        $siteId = $this->makeActiveSite();
        $this->seedAstraRow($siteId);
        $itemId = $this->makeJobItem($siteId);

        $body = (string) json_encode(['error' => ['code' => 'connector.upgrade_in_progress', 'message' => 'busy']]);
        $job  = $this->makeJob(fn () => new MockResponse($body, [
            'http_code'        => 409,
            'response_headers' => ['content-type: application/json'],
        ]));

        $scheduled = [];
        \add_filter('pre_as_schedule_single_action', function ($pre, $when, $hook, $args) use (&$scheduled) {
            $scheduled[] = ['hook' => $hook, 'args' => $args];
            return 999;
        }, 10, 4);

        $job->handle($siteId, 'astra', 0, $itemId);

        $item = $this->itemRow($itemId);
        self::assertSame('started', $item['state']);
        self::assertNull($item['completed_at']);
        self::assertSame([$siteId, 'astra', 1, $itemId], $scheduled[0]['args']);
    }

    public function testItemMarkedSucceededOnSuccess(): void
    {
        $siteId = $this->makeActiveSite();
        $this->seedAstraRow($siteId);
        $itemId = $this->makeJobItem($siteId);

        $body = (string) json_encode([
            'success'          => true,
            'slug'             => 'astra',
            'previous_version' => '4.6.3',
            'new_version'      => '4.7.0',
            'server_time'      => time(),
        ]);
        $job = $this->makeJob(fn () => new MockResponse($body, [
            'http_code'        => 200,
            'response_headers' => ['content-type: application/json'],
        ]));

        $job->handle($siteId, 'astra', 0, $itemId);

        $item = $this->itemRow($itemId);
        self::assertSame('succeeded', $item['state']);
        self::assertNotNull($item['completed_at']);
    }

    public function testItemMarkedFailedOnConnectorFailure(): void
    {
        $siteId = $this->makeActiveSite();
        $this->seedAstraRow($siteId);
        $itemId = $this->makeJobItem($siteId);

        $body = (string) json_encode([
            'error' => ['code' => 'themes.update_failed', 'message' => 'Could not copy file.'],
        ]);
        $job = $this->makeJob(fn () => new MockResponse($body, [
            'http_code'        => 502,
            'response_headers' => ['content-type: application/json'],
        ]));

        $job->handle($siteId, 'astra', 0, $itemId);

        $item = $this->itemRow($itemId);
        self::assertSame('failed', $item['state']);
        self::assertStringContainsString('Could not copy file', $item['error_message']);
    }

    public function testItemMarkedSucceededOnNoUpdateAvailable409(): void
    {
        $siteId = $this->makeActiveSite();
        $this->seedAstraRow($siteId);
        $itemId = $this->makeJobItem($siteId);

        // 409 success-by-other-means — someone already upgraded the theme.
        // The bulk-job item's goal is achieved: MUST count as succeeded.
        $body = (string) json_encode(['error' => ['code' => 'themes.no_update_available', 'message' => 'No update available for "astra".']]);
        $job  = $this->makeJob(fn () => new MockResponse($body, ['http_code' => 409]));

        $job->handle($siteId, 'astra', 0, $itemId);

        $item = $this->itemRow($itemId);
        self::assertSame('succeeded', $item['state']);
        self::assertNotNull($item['completed_at']);
        self::assertNull($item['error_message']);
    }

    public function testNoBulkRowsTouchedWhenJobItemIdIsZero(): void
    {
        $siteId = $this->makeActiveSite();
        $this->seedAstraRow($siteId);
        $unrelatedItemId = $this->makeJobItem($siteId);

        $body = (string) json_encode([
            'success'          => true,
            'slug'             => 'astra',
            'previous_version' => '4.6.3',
            'new_version'      => '4.7.0',
            'server_time'      => time(),
        ]);
        $job = $this->makeJob(fn () => new MockResponse($body, [
            'http_code'        => 200,
            'response_headers' => ['content-type: application/json'],
        ]));

        $job->handle($siteId, 'astra', 0);

        $item = $this->itemRow($unrelatedItemId);
        self::assertSame('queued', $item['state']);
        self::assertNull($item['started_at']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd packages/dashboard-plugin && composer test -- --filter "UpdateSitePluginBulkJobItemTest|UpdateSiteThemeBulkJobItemTest"`

Expected: 11 FAIL — items never leave `queued` (no marks exist yet) and the reschedule capture asserts a 4-element args array against the current 3-element one.

- [ ] **Step 3: Edit both job classes + Plugin.php**

Modify `packages/dashboard-plugin/src/Jobs/UpdateSitePlugin.php`:

Edit 1 — use-imports. Old:

```php
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\SitePluginsRepository;
use Defyn\Dashboard\Services\SitesRepository;
```

New:

```php
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\BulkJobsRepository;
use Defyn\Dashboard\Services\SitePluginsRepository;
use Defyn\Dashboard\Services\SitesRepository;
```

Edit 2 — constructor (the new param goes LAST so the existing positional test constructions stay valid). Old:

```php
        private readonly ?Vault $vault = null,
    ) {
    }
```

New:

```php
        private readonly ?Vault $vault = null,
        private readonly BulkJobsRepository $bulkJobs = new BulkJobsRepository(),
    ) {
    }
```

Edit 3 — signature + early returns + entry mark. Old:

```php
    public function handle(int $siteId, string $slug, int $attempt = 0): void
    {
        $site = $this->sites->findById($siteId);
        if ($site === null) {
            return;
        }

        $row = $this->repo->findRowForSiteAndSlug($siteId, $slug);
        if ($row === null) {
            return;
        }

        $now = gmdate('Y-m-d H:i:s');

        $this->repo->markUpdating($siteId, $slug, $now);
```

New:

```php
    public function handle(int $siteId, string $slug, int $attempt = 0, int $jobItemId = 0): void
    {
        $now = gmdate('Y-m-d H:i:s');

        $site = $this->sites->findById($siteId);
        if ($site === null) {
            // Terminal — a bulk-job item pointing at a deleted site can never
            // succeed; without the mark it would hang in `queued` forever.
            if ($jobItemId > 0) {
                $this->bulkJobs->markItemFailed($jobItemId, $now, 'Site no longer exists.');
            }
            return;
        }

        $row = $this->repo->findRowForSiteAndSlug($siteId, $slug);
        if ($row === null) {
            if ($jobItemId > 0) {
                $this->bulkJobs->markItemFailed($jobItemId, $now, 'Plugin row no longer exists.');
            }
            return;
        }

        // P2.9 — queued → started at handle entry. The repository UPDATE is
        // guarded queued-only, so 409-retry re-entries (already started) no-op.
        if ($jobItemId > 0) {
            $this->bulkJobs->markItemStarted($jobItemId, $now);
        }

        $this->repo->markUpdating($siteId, $slug, $now);
```

Edit 4 — success branch. Old:

```php
            $this->repo->markUpdateSucceeded($siteId, $slug, $newVersion, $now);
            $this->log->log(null, $siteId, 'plugin_update.succeeded', [
                'slug'             => $slug,
                'previous_version' => $previousVersion,
                'new_version'      => $newVersion,
            ]);
            return;
```

New:

```php
            $this->repo->markUpdateSucceeded($siteId, $slug, $newVersion, $now);
            $this->log->log(null, $siteId, 'plugin_update.succeeded', [
                'slug'             => $slug,
                'previous_version' => $previousVersion,
                'new_version'      => $newVersion,
            ]);
            if ($jobItemId > 0) {
                $this->bulkJobs->markItemSucceeded($jobItemId, $now);
            }
            return;
```

Edit 5 — 409-busy exhausted branch. Old:

```php
                $this->log->log(null, $siteId, 'plugin_update.failed', [
                    'slug'              => $slug,
                    'error_message'     => 'Site is busy after 5 retries.',
                    'attempted_version' => $row['update_version'] ?? null,
                ]);
                return;
```

New:

```php
                $this->log->log(null, $siteId, 'plugin_update.failed', [
                    'slug'              => $slug,
                    'error_message'     => 'Site is busy after 5 retries.',
                    'attempted_version' => $row['update_version'] ?? null,
                ]);
                if ($jobItemId > 0) {
                    $this->bulkJobs->markItemFailed($jobItemId, $now, 'Site is busy after 5 retries.');
                }
                return;
```

Edit 6 — retry reschedule propagates the item id (NO terminal mark). Old:

```php
            \as_schedule_single_action($nextRun, self::HOOK, [$siteId, $slug, $attempt + 1]);
```

New:

```php
            // P2.9 — propagate the item id so the retry attempt keeps marking
            // lifecycle on the SAME item. No terminal mark — stays `started`.
            \as_schedule_single_action($nextRun, self::HOOK, [$siteId, $slug, $attempt + 1, $jobItemId]);
```

Edit 7 — final failure. Old:

```php
        $this->repo->markUpdateFailed($siteId, $slug, $errorMessage, $now);
        $this->log->log(null, $siteId, 'plugin_update.failed', [
            'slug'              => $slug,
            'error_message'     => $errorMessage,
            'attempted_version' => $row['update_version'] ?? null,
        ]);
    }
```

New:

```php
        $this->repo->markUpdateFailed($siteId, $slug, $errorMessage, $now);
        $this->log->log(null, $siteId, 'plugin_update.failed', [
            'slug'              => $slug,
            'error_message'     => $errorMessage,
            'attempted_version' => $row['update_version'] ?? null,
        ]);
        if ($jobItemId > 0) {
            $this->bulkJobs->markItemFailed($jobItemId, $now, $errorMessage);
        }
    }
```

Modify `packages/dashboard-plugin/src/Jobs/UpdateSiteTheme.php` — Edits 1–7 are identical with `Plugin row no longer exists.` → `Theme row no longer exists.`, `'plugin_update.succeeded'`/`'plugin_update.failed'` anchors → `'theme_update.succeeded'`/`'theme_update.failed'`, and `SitePluginsRepository` import anchor → the existing `use Defyn\Dashboard\Services\SitesRepository;` / `use Defyn\Dashboard\Services\ThemesRepository;` pair (insert `use Defyn\Dashboard\Services\BulkJobsRepository;` between `ActivityLogger` and `SitesRepository`). PLUS one extra edit:

Edit 8 — the 409 `themes.no_update_available` success-by-other-means branch. Old:

```php
            $rowVersionBeforeAttempt = (string) ($row['version'] ?? '');
            $this->repo->markUpdateSucceeded($siteId, $slug, $rowVersionBeforeAttempt, $now);
            $this->log->log(null, $siteId, 'theme_update.succeeded_no_change', [
                'slug'            => $slug,
                'current_version' => $rowVersionBeforeAttempt,
            ]);
            return;
```

New:

```php
            $rowVersionBeforeAttempt = (string) ($row['version'] ?? '');
            $this->repo->markUpdateSucceeded($siteId, $slug, $rowVersionBeforeAttempt, $now);
            $this->log->log(null, $siteId, 'theme_update.succeeded_no_change', [
                'slug'            => $slug,
                'current_version' => $rowVersionBeforeAttempt,
            ]);
            // 409-as-success — the item's goal was achieved by other means.
            if ($jobItemId > 0) {
                $this->bulkJobs->markItemSucceeded($jobItemId, $now);
            }
            return;
```

Modify `packages/dashboard-plugin/src/Plugin.php` (trap #3 — registrations bump to 4 args):

Edit 1 — Old:

```php
        add_action(UpdateSitePlugin::HOOK, static function (int $siteId, string $slug, int $attempt = 0): void {
            (new UpdateSitePlugin())->handle($siteId, $slug, $attempt);
        }, 10, 3);
```

New:

```php
        // P2.9 — 4th arg is the bulk-job item id (0 = no tracking). Default
        // param keeps pre-v0.9.0 3-arg AS rows from fataling.
        add_action(UpdateSitePlugin::HOOK, static function (int $siteId, string $slug, int $attempt = 0, int $jobItemId = 0): void {
            (new UpdateSitePlugin())->handle($siteId, $slug, $attempt, $jobItemId);
        }, 10, 4);
```

Edit 2 — Old:

```php
        add_action(UpdateSiteTheme::HOOK, static function (int $siteId, string $slug, int $attempt = 0): void {
            (new UpdateSiteTheme())->handle($siteId, $slug, $attempt);
        }, 10, 3);
```

New:

```php
        // P2.9 — same 4-arg bump as UpdateSitePlugin::HOOK above.
        add_action(UpdateSiteTheme::HOOK, static function (int $siteId, string $slug, int $attempt = 0, int $jobItemId = 0): void {
            (new UpdateSiteTheme())->handle($siteId, $slug, $attempt, $jobItemId);
        }, 10, 4);
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd packages/dashboard-plugin && composer test -- --filter "UpdateSitePluginBulkJobItemTest|UpdateSiteThemeBulkJobItemTest|UpdateSitePluginTest|UpdateSiteThemeTest|PluginBootASHook"`

Expected: 11 new tests PASS + every existing UpdateSitePluginTest/UpdateSiteThemeTest/PluginBootASHook* test STILL passes (the `$jobItemId = 0` default keeps every 3-arg call path identical). Then `composer test` — only the 3 carry-forward failures.

- [ ] **Step 5: Commit**

```bash
git add packages/dashboard-plugin/src/Jobs/UpdateSitePlugin.php \
        packages/dashboard-plugin/src/Jobs/UpdateSiteTheme.php \
        packages/dashboard-plugin/src/Plugin.php \
        packages/dashboard-plugin/tests/Integration/Jobs/UpdateSitePluginBulkJobItemTest.php \
        packages/dashboard-plugin/tests/Integration/Jobs/UpdateSiteThemeBulkJobItemTest.php
git commit -m "feat(p2-9): AS jobs mark bulk-job item lifecycle

UpdateSitePlugin::handle + UpdateSiteTheme::handle gain int \$jobItemId
= 0 (backwards-compat — per-site endpoints + pre-v0.9.0 AS rows keep
working). Marks at EVERY terminal branch:
- site/row missing early-returns -> markItemFailed (would hang queued)
- handle entry -> markItemStarted (guarded queued-only; retry re-entry no-op)
- 200 success -> markItemSucceeded
- 409 busy at attempt>=5 -> markItemFailed
- 409 busy retry -> reschedule with [siteId, slug, attempt+1, jobItemId],
  NO terminal mark (item stays started)
- theme 409 no_update_available -> markItemSucceeded (409-as-success)
- final failure -> markItemFailed(errorMessage)

Plugin::boot registrations bumped 3 -> 4 args for both hooks with a
defaulted closure param (trap #3 — old in-flight rows don't fatal).

11 integration tests (6 plugin + 5 theme) incl. backwards-compat
jobItemId=0 no-op and the 4-tuple retry propagation.

Per spec § 1 (AS job extensions) + guardrail #3."
```

---

## Task 11 — Dashboard v0.9.0 release bump + 5 CORS regressions

**Files:**
- Modify: `packages/dashboard-plugin/defyn-dashboard.php` (version header)
- Modify: `packages/dashboard-plugin/composer.json` (version)
- Modify: `packages/dashboard-plugin/readme.txt` (stable tag + changelog)
- Test: `packages/dashboard-plugin/tests/Integration/Rest/JobsRoutesCorsTest.php` (CREATE)

- [ ] **Step 1: Write the CORS regression tests**

One consolidated file (5 routes share identical mechanics — the namespace-level `Cors` middleware). Create `packages/dashboard-plugin/tests/Integration/Rest/JobsRoutesCorsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Rest\Middleware\Cors;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P2.9 — CORS regression for the 5 new /jobs* routes.
 *
 * Mirrors OverviewBulkUpdateThemesCorsTest: drives Cors::apply directly
 * because rest_pre_serve_request fires outside the WP_REST_Request
 * lifecycle in WP_UnitTestCase.
 *
 * @group integration
 */
final class JobsRoutesCorsTest extends AbstractSchemaTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        if (!defined('DEFYN_SPA_ORIGIN')) {
            define('DEFYN_SPA_ORIGIN', 'http://localhost:5173');
        }
        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
        do_action('rest_api_init');
    }

    /** @return array{0: WP_REST_Response, 1: bool} */
    private function applyCors(string $method, string $route): array
    {
        $response = new WP_REST_Response(['ok' => true], 200);
        $request  = new WP_REST_Request($method, $route);
        $served   = Cors::apply(false, $response, $request, rest_get_server());
        return [$response, $served];
    }

    private function assertCorsHeaders(WP_REST_Response $response, bool $served, string $expectedMethod): void
    {
        $headers = $response->get_headers();
        self::assertArrayHasKey('Access-Control-Allow-Origin', $headers);
        self::assertSame(DEFYN_SPA_ORIGIN, $headers['Access-Control-Allow-Origin']);
        self::assertSame('true', $headers['Access-Control-Allow-Credentials']);
        self::assertStringContainsString($expectedMethod, $headers['Access-Control-Allow-Methods']);
        self::assertSame(false, $served, 'apply must return the served bool unchanged');
    }

    public function testJobsListRouteReturnsCorsHeaders(): void
    {
        [$response, $served] = $this->applyCors('GET', '/defyn/v1/jobs');
        $this->assertCorsHeaders($response, $served, 'GET');
    }

    public function testJobsDetailRouteReturnsCorsHeaders(): void
    {
        [$response, $served] = $this->applyCors('GET', '/defyn/v1/jobs/42');
        $this->assertCorsHeaders($response, $served, 'GET');
    }

    public function testJobsCancelRouteReturnsCorsHeaders(): void
    {
        [$response, $served] = $this->applyCors('POST', '/defyn/v1/jobs/42/cancel');
        $this->assertCorsHeaders($response, $served, 'POST');
    }

    public function testJobsRetryItemRouteReturnsCorsHeaders(): void
    {
        [$response, $served] = $this->applyCors('POST', '/defyn/v1/jobs/42/items/201/retry');
        $this->assertCorsHeaders($response, $served, 'POST');
    }

    public function testJobsRetryFailedRouteReturnsCorsHeaders(): void
    {
        [$response, $served] = $this->applyCors('POST', '/defyn/v1/jobs/42/retry-failed');
        $this->assertCorsHeaders($response, $served, 'POST');
    }
}
```

- [ ] **Step 2: Run tests to verify they pass**

The `Cors` middleware is namespace-scoped (`defyn/v1`), so the new routes inherit it with no code change.

Run: `cd packages/dashboard-plugin && composer test -- --filter JobsRoutesCorsTest`

Expected: 5 PASS.

- [ ] **Step 3: Bump version**

Modify `packages/dashboard-plugin/defyn-dashboard.php`. Old:

```php
 * Version:           0.8.1
```

New:

```php
 * Version:           0.9.0
```

(If a `DEFYN_DASHBOARD_VERSION` constant is defined in the same file, bump it to `'0.9.0'` too.)

Modify `packages/dashboard-plugin/composer.json`. Old:

```json
    "version": "0.8.1",
```

New:

```json
    "version": "0.9.0",
```

Modify `packages/dashboard-plugin/readme.txt`. Old:

```
Stable tag: 0.8.1
```

New:

```
Stable tag: 0.9.0
```

Add at the top of the changelog section (spec § 2.11 verbatim):

```
= 0.9.0 =
* Bulk-jobs entity: every POST /overview/bulk-update-plugins and POST /overview/bulk-update-themes now creates a tracked job in wp_defyn_bulk_jobs + N child rows in wp_defyn_bulk_job_items. Response envelope adds job_id.
* New GET /defyn/v1/jobs (30/MIN) + GET /defyn/v1/jobs/{id} (30/MIN) feed the new SPA /jobs route and per-job detail view.
* New POST /defyn/v1/jobs/{id}/cancel (5/HR) cancels all queued items via as_unschedule_action. Items already started can't be cancelled.
* New POST /defyn/v1/jobs/{id}/items/{item_id}/retry (20/HR) + POST /defyn/v1/jobs/{id}/retry-failed (5/HR) re-schedule failed items.
* Schema v6 → v7 (additive: 2 new tables, no destructive ALTERs). Self-heal handles upgrade transparently.
* Minor version bump because the new domain entity is a meaningful surface change, not a patch.
```

- [ ] **Step 4: Final backend suite check + commit**

Run: `cd packages/dashboard-plugin && composer test`

Expected: everything green except the 3 documented carry-forward failures.

```bash
git add packages/dashboard-plugin/defyn-dashboard.php \
        packages/dashboard-plugin/composer.json \
        packages/dashboard-plugin/readme.txt \
        packages/dashboard-plugin/tests/Integration/Rest/JobsRoutesCorsTest.php
git commit -m "feat(p2-9): dashboard v0.9.0 release bump + 5 CORS regressions

Minor bump — the BulkJob domain entity (schema v7, 5 new endpoints,
job_id in both bulk envelopes) is a meaningful surface change.

5 CORS preflight regressions pin the namespace-level Cors middleware
for /jobs, /jobs/{id}, /jobs/{id}/cancel,
/jobs/{id}/items/{item_id}/retry, /jobs/{id}/retry-failed.

Per spec § 2.11."
```

---

# Phase C — SPA

## Task 12 — SPA Zod schemas + MSW handlers (+ `job_id` ripple)

**Files:**
- Modify: `apps/web/src/types/api.ts` (7 new schemas + extend 2 existing)
- Modify: `apps/web/src/test/handlers.ts` (5 new handlers + job_id in 2 existing)
- Modify: `apps/web/tests/lib/mutations/useBulkUpdatePlugins.test.tsx` (job_id in 2 inline fixtures)
- Modify: `apps/web/tests/lib/mutations/useBulkUpdateThemes.test.tsx` (job_id in 2 inline fixtures)
- Modify: `apps/web/tests/components/overview/BulkUpdatePluginsButton.test.tsx` (job_id in 1 inline fixture)

- [ ] **Step 1: Append the new schemas + extend the bulk response schemas**

Append to `apps/web/src/types/api.ts` (after `bulkUpdateThemesResponseSchema` at the end of the file):

```ts
// P2.9 — Bulk-jobs entity.
export const jobKindSchema = z.enum(['plugin_update', 'theme_update']);
export type JobKind = z.infer<typeof jobKindSchema>;

export const jobStateSchema = z.enum(['queued', 'in_progress', 'completed', 'partial']);
export type JobState = z.infer<typeof jobStateSchema>;

export const jobItemStateSchema = z.enum(['queued', 'started', 'succeeded', 'failed', 'cancelled']);
export type JobItemState = z.infer<typeof jobItemStateSchema>;

export const jobSchema = z.object({
  id: z.number().int().positive(),
  kind: jobKindSchema,
  scheduled_count: z.number().int().nonnegative(),
  skipped_count: z.number().int().nonnegative(),
  succeeded_count: z.number().int().nonnegative(),
  failed_count: z.number().int().nonnegative(),
  cancelled_count: z.number().int().nonnegative(),
  queued_count: z.number().int().nonnegative(),
  started_count: z.number().int().nonnegative(),
  state: jobStateSchema,
  started_at: z.string().nullable(),
  completed_at: z.string().nullable(),
  created_at: z.string(),
});
export type Job = z.infer<typeof jobSchema>;

export const jobItemSchema = z.object({
  id: z.number().int().positive(),
  site_id: z.number().int(),
  site_label: z.string(),
  resource_slug: z.string(),
  resource_name: z.string(),
  current_version: z.string().nullable(),
  target_version: z.string().nullable(),
  state: jobItemStateSchema,
  error_message: z.string().nullable(),
  started_at: z.string().nullable(),
  completed_at: z.string().nullable(),
  created_at: z.string(),
});
export type JobItem = z.infer<typeof jobItemSchema>;

export const jobsListResponseSchema = z.object({
  jobs: z.array(jobSchema),
  total: z.number().int().nonnegative(),
  page: z.number().int().positive(),
  per_page: z.number().int().positive(),
  generated_at: z.string(),
});
export type JobsListResponse = z.infer<typeof jobsListResponseSchema>;

export const jobDetailResponseSchema = z.object({
  job: jobSchema,
  items: z.array(jobItemSchema),
  generated_at: z.string(),
});
export type JobDetailResponse = z.infer<typeof jobDetailResponseSchema>;

export const cancelJobResponseSchema = z.object({
  cancelled_count: z.number().int().nonnegative(),
  still_running_count: z.number().int().nonnegative(),
  cancelled_at: z.string(),
});
export type CancelJobResponse = z.infer<typeof cancelJobResponseSchema>;

export const retryItemResponseSchema = z.object({
  item_id: z.number().int().positive(),
  scheduled_at: z.string(),
});
export type RetryItemResponse = z.infer<typeof retryItemResponseSchema>;

export const retryFailedResponseSchema = z.object({
  retried_count: z.number().int().nonnegative(),
  retried_item_ids: z.array(z.number().int()),
  scheduled_at: z.string(),
});
export type RetryFailedResponse = z.infer<typeof retryFailedResponseSchema>;
```

Extend the TWO existing bulk response schemas with `job_id` as the first field. Edit `bulkUpdatePluginsResponseSchema` (line 158). Old:

```ts
export const bulkUpdatePluginsResponseSchema = z.object({
  scheduled_count: z.number().int().nonnegative(),
```

New:

```ts
export const bulkUpdatePluginsResponseSchema = z.object({
  job_id: z.number().int().nullable(),
  scheduled_count: z.number().int().nonnegative(),
```

Edit `bulkUpdateThemesResponseSchema` (line 192). Old:

```ts
export const bulkUpdateThemesResponseSchema = z.object({
  scheduled_count: z.number().int().nonnegative(),
```

New:

```ts
export const bulkUpdateThemesResponseSchema = z.object({
  job_id: z.number().int().nullable(),
  scheduled_count: z.number().int().nonnegative(),
```

- [ ] **Step 2: MSW handlers — new fixtures + handlers, and `job_id` in the existing bulk handlers**

Modify `apps/web/src/test/handlers.ts`.

Edit A — the P2.7 bulk-plugins POST handler (lines 604–616) gains job_id. Old:

```ts
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

  // P2.8 — GET /overview/pending-theme-updates default empty list.
```

New:

```ts
    return HttpResponse.json(
      {
        job_id: body.updates.length > 0 ? 42 : null,
        scheduled_count: body.updates.length,
        skipped_count: 0,
        scheduled_pairs: body.updates,
        skipped_pairs: [],
        scheduled_at: '2026-06-09 23:15:42',
      },
      { status: body.updates.length > 0 ? 202 : 200 },
    );
  }),

  // P2.8 — GET /overview/pending-theme-updates default empty list.
```

Edit B — the P2.8 bulk-themes POST handler (lines 627–639) gains the same field. Old:

```ts
    return HttpResponse.json(
      {
        scheduled_count: body.updates.length,
        skipped_count: 0,
        scheduled_pairs: body.updates,
        skipped_pairs: [],
        scheduled_at: '2026-06-09 23:45:42',
      },
      { status: body.updates.length > 0 ? 202 : 200 },
    );
  }),
```

New:

```ts
    return HttpResponse.json(
      {
        job_id: body.updates.length > 0 ? 42 : null,
        scheduled_count: body.updates.length,
        skipped_count: 0,
        scheduled_pairs: body.updates,
        skipped_pairs: [],
        scheduled_at: '2026-06-09 23:45:42',
      },
      { status: body.updates.length > 0 ? 202 : 200 },
    );
  }),
```

Edit C — append the P2.9 fixtures + 5 handlers immediately after the (now-extended) P2.8 bulk-themes POST handler, BEFORE the `// P2.4 — POST /sites/:id/core/update` handler. First add the shared fixtures near the top of the file (after the existing imports / alongside other mock fixtures):

```ts
// P2.9 — bulk-jobs fixtures shared by the /jobs handlers.
const MOCK_JOB = {
  id: 42,
  kind: 'plugin_update',
  scheduled_count: 3,
  skipped_count: 0,
  succeeded_count: 1,
  failed_count: 1,
  cancelled_count: 0,
  queued_count: 1,
  started_count: 0,
  state: 'in_progress',
  started_at: '2026-06-09 21:00:00',
  completed_at: null,
  created_at: '2026-06-09 20:59:15',
};

const MOCK_JOB_ITEMS = [
  { id: 201, site_id: 1, site_label: 'SmartCoding', resource_slug: 'akismet', resource_name: 'Akismet Anti-Spam', current_version: '5.3', target_version: '5.3.1', state: 'succeeded', error_message: null, started_at: '2026-06-09 21:00:02', completed_at: '2026-06-09 21:00:11', created_at: '2026-06-09 20:59:15' },
  { id: 202, site_id: 1, site_label: 'SmartCoding', resource_slug: 'elementor', resource_name: 'Elementor', current_version: '3.18.2', target_version: '4.0.0', state: 'failed', error_message: 'Could not copy file.', started_at: '2026-06-09 21:00:12', completed_at: '2026-06-09 21:00:40', created_at: '2026-06-09 20:59:15' },
  { id: 203, site_id: 2, site_label: 'AcmeBlog', resource_slug: 'yoast', resource_name: 'Yoast SEO', current_version: '22.5', target_version: '22.6', state: 'queued', error_message: null, started_at: null, completed_at: null, created_at: '2026-06-09 20:59:15' },
];
```

Then the handlers:

```ts
  // P2.9 — GET /jobs default list (one in_progress job; completed filter empty).
  http.get('*/wp-json/defyn/v1/jobs', ({ request }) => {
    const url = new URL(request.url);
    const status = url.searchParams.get('status') ?? 'all';
    const jobs = status === 'completed' ? [] : [MOCK_JOB];
    return HttpResponse.json({
      jobs,
      total: jobs.length,
      page: Number(url.searchParams.get('page') ?? '1'),
      per_page: Number(url.searchParams.get('per_page') ?? '20'),
      generated_at: '2026-06-09 21:30:00',
    });
  }),

  // P2.9 — GET /jobs/:id default detail.
  http.get('*/wp-json/defyn/v1/jobs/:id', ({ params }) => {
    return HttpResponse.json({
      job: { ...MOCK_JOB, id: Number(params.id) },
      items: MOCK_JOB_ITEMS,
      generated_at: '2026-06-09 21:30:00',
    });
  }),

  // P2.9 — POST /jobs/:id/cancel default synchronous 200.
  http.post('*/wp-json/defyn/v1/jobs/:id/cancel', () => {
    return HttpResponse.json(
      { cancelled_count: 1, still_running_count: 0, cancelled_at: '2026-06-09 21:30:42' },
      { status: 200 },
    );
  }),

  // P2.9 — POST /jobs/:id/items/:itemId/retry default 202.
  http.post('*/wp-json/defyn/v1/jobs/:id/items/:itemId/retry', ({ params }) => {
    return HttpResponse.json(
      { item_id: Number(params.itemId), scheduled_at: '2026-06-09 21:35:00' },
      { status: 202 },
    );
  }),

  // P2.9 — POST /jobs/:id/retry-failed default 202.
  http.post('*/wp-json/defyn/v1/jobs/:id/retry-failed', () => {
    return HttpResponse.json(
      { retried_count: 1, retried_item_ids: [202], scheduled_at: '2026-06-09 21:40:00' },
      { status: 202 },
    );
  }),
```

- [ ] **Step 3: Ripple `job_id` into the inline test fixtures (trap #30)**

The schema change makes `job_id` REQUIRED — every inline `server.use(...)` override that returns a bulk envelope must now include it or its test fails on Zod parse.

Edit `apps/web/tests/lib/mutations/useBulkUpdateThemes.test.tsx` — both inline POST fixtures. Old (first fixture, inside `postsToBulkUpdateEndpointWithCorrectBody`):

```ts
        return HttpResponse.json(
          {
            scheduled_count: 2,
```

New:

```ts
        return HttpResponse.json(
          {
            job_id: 42,
            scheduled_count: 2,
```

Old (second fixture, inside `invalidatesOverviewAndPendingQueriesOnSuccessButNotSites`):

```ts
        HttpResponse.json(
          {
            scheduled_count: 1,
```

New:

```ts
        HttpResponse.json(
          {
            job_id: 42,
            scheduled_count: 1,
```

Edit `apps/web/tests/lib/mutations/useBulkUpdatePlugins.test.tsx` — the same two insertions (the file mirrors the themes test: add `job_id: 42,` as the first property of both inline response objects, at lines 21–23 and 59–61).

Edit `apps/web/tests/components/overview/BulkUpdatePluginsButton.test.tsx` — the inline POST fixture inside `showsPendingLabelWhilePostInFlight` (lines 68–75). Old:

```ts
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
```

New:

```ts
        return HttpResponse.json(
          {
            job_id: 42,
            scheduled_count: 1,
            skipped_count: 0,
            scheduled_pairs: [{ site_id: 1, slug: 'akismet' }],
            skipped_pairs: [],
            scheduled_at: '2026-06-09 23:15:42',
          },
          { status: 202 },
        );
```

- [ ] **Step 4: Verify nothing broken**

Run: `cd apps/web && pnpm test -- --run`

Expected: full suite green except the 4 documented carry-forward failures. (The Zod extension + fixture ripple must net to zero new failures.)

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/types/api.ts \
        apps/web/src/test/handlers.ts \
        apps/web/tests/lib/mutations/useBulkUpdatePlugins.test.tsx \
        apps/web/tests/lib/mutations/useBulkUpdateThemes.test.tsx \
        apps/web/tests/components/overview/BulkUpdatePluginsButton.test.tsx
git commit -m "feat(p2-9): SPA Zod schemas + MSW handlers for bulk-jobs entity

7 new Zod schemas (job, jobItem, jobsListResponse, jobDetailResponse,
cancelJobResponse, retryItemResponse, retryFailedResponse) + 3 enum
schemas (kind, jobState, jobItemState) + inferred types. Both bulk
response schemas gain REQUIRED nullable job_id.

5 new MSW handlers (/jobs list with status-param awareness, /jobs/:id
detail, cancel, retry-item, retry-failed) + shared MOCK_JOB /
MOCK_JOB_ITEMS fixtures. job_id added to both existing bulk POST
handlers AND every inline test fixture that returns a bulk envelope
(trap #30 — required field would otherwise fail Zod parse in 5 tests).

Per spec § 3.5 (schemas) + § 2.6."
```

---

## Task 13 — `useJobsList` + `useJobDetail` + `useJobsCount` query hooks (adaptive polling)

**Files:**
- Create: `apps/web/src/lib/queries/useJobsList.ts`
- Create: `apps/web/src/lib/queries/useJobDetail.ts`
- Create: `apps/web/src/lib/queries/useJobsCount.ts`
- Test: `apps/web/tests/lib/queries/useJobsList.test.tsx` (CREATE)
- Test: `apps/web/tests/lib/queries/useJobDetail.test.tsx` (CREATE)
- Test: `apps/web/tests/lib/queries/useJobsCount.test.tsx` (CREATE)

- [ ] **Step 1: Write the failing tests**

Create `apps/web/tests/lib/queries/useJobsList.test.tsx`:

```tsx
import { describe, it, expect } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useJobsList, jobsListPollInterval } from '@/lib/queries/useJobsList';
import type { JobsListResponse } from '@/types/api';
import React from 'react';

function makeWrapper(qc: QueryClient) {
  return ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
}

const BASE_JOB = {
  id: 42,
  kind: 'plugin_update' as const,
  scheduled_count: 3,
  skipped_count: 0,
  succeeded_count: 1,
  failed_count: 1,
  cancelled_count: 0,
  queued_count: 1,
  started_count: 0,
  state: 'in_progress' as const,
  started_at: '2026-06-09 21:00:00',
  completed_at: null,
  created_at: '2026-06-09 20:59:15',
};

function listResponse(state: 'queued' | 'in_progress' | 'completed' | 'partial'): JobsListResponse {
  return {
    jobs: [{ ...BASE_JOB, state }],
    total: 1,
    page: 1,
    per_page: 20,
    generated_at: '2026-06-09 21:30:00',
  };
}

describe('useJobsList', () => {
  it('fetches and parses the list shape (default MSW handler)', async () => {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const { result } = renderHook(() => useJobsList('all', 1), { wrapper: makeWrapper(qc) });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data?.jobs).toHaveLength(1);
    expect(result.current.data?.jobs[0].id).toBe(42);
    expect(result.current.data?.jobs[0].state).toBe('in_progress');
    expect(result.current.data?.total).toBe(1);
  });

  it('jobsListPollInterval returns 10s while any job is queued/in_progress (guardrail #9)', () => {
    expect(jobsListPollInterval(listResponse('in_progress'))).toBe(10_000);
    expect(jobsListPollInterval(listResponse('queued'))).toBe(10_000);
  });

  it('jobsListPollInterval stops polling when all jobs terminal', () => {
    expect(jobsListPollInterval(listResponse('completed'))).toBe(false);
    expect(jobsListPollInterval(listResponse('partial'))).toBe(false);
    expect(jobsListPollInterval(undefined)).toBe(false);
  });
});
```

Create `apps/web/tests/lib/queries/useJobDetail.test.tsx`:

```tsx
import { describe, it, expect } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useJobDetail, jobDetailPollInterval } from '@/lib/queries/useJobDetail';
import type { JobDetailResponse, JobItem } from '@/types/api';
import React from 'react';

function makeWrapper(qc: QueryClient) {
  return ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
}

function makeItem(state: JobItem['state']): JobItem {
  return {
    id: 201,
    site_id: 1,
    site_label: 'SmartCoding',
    resource_slug: 'akismet',
    resource_name: 'Akismet Anti-Spam',
    current_version: '5.3',
    target_version: '5.3.1',
    state,
    error_message: null,
    started_at: null,
    completed_at: null,
    created_at: '2026-06-09 20:59:15',
  };
}

function detailResponse(states: Array<JobItem['state']>): JobDetailResponse {
  return {
    job: {
      id: 42,
      kind: 'plugin_update',
      scheduled_count: states.length,
      skipped_count: 0,
      succeeded_count: 0,
      failed_count: 0,
      cancelled_count: 0,
      queued_count: states.length,
      started_count: 0,
      state: 'queued',
      started_at: null,
      completed_at: null,
      created_at: '2026-06-09 20:59:15',
    },
    items: states.map((s) => makeItem(s)),
    generated_at: '2026-06-09 21:30:00',
  };
}

describe('useJobDetail', () => {
  it('fetches and parses the detail shape (default MSW handler)', async () => {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const { result } = renderHook(() => useJobDetail(42), { wrapper: makeWrapper(qc) });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data?.job.id).toBe(42);
    expect(result.current.data?.items).toHaveLength(3);
    expect(result.current.data?.items[1].state).toBe('failed');
    expect(result.current.data?.items[1].error_message).toBe('Could not copy file.');
  });

  it('jobDetailPollInterval returns 5s while any item is queued/started (guardrail #9)', () => {
    expect(jobDetailPollInterval(detailResponse(['succeeded', 'queued']))).toBe(5_000);
    expect(jobDetailPollInterval(detailResponse(['started']))).toBe(5_000);
  });

  it('jobDetailPollInterval stops polling when all items terminal', () => {
    expect(jobDetailPollInterval(detailResponse(['succeeded', 'failed', 'cancelled']))).toBe(false);
    expect(jobDetailPollInterval(undefined)).toBe(false);
  });
});
```

Create `apps/web/tests/lib/queries/useJobsCount.test.tsx`:

```tsx
import { describe, it, expect } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useJobsCount } from '@/lib/queries/useJobsCount';
import React from 'react';

function makeWrapper(qc: QueryClient) {
  return ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
}

describe('useJobsCount', () => {
  it('returns the active-jobs total from the list endpoint (trap #32)', async () => {
    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const { result } = renderHook(() => useJobsCount(), { wrapper: makeWrapper(qc) });

    // Default MSW /jobs handler returns 1 job for status=active.
    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data).toBe(1);
  });

  it('queries with status=active and per_page=1', async () => {
    const { server } = await import('@/test/setup');
    const { http, HttpResponse } = await import('msw');

    let capturedUrl = '';
    server.use(
      http.get('*/wp-json/defyn/v1/jobs', ({ request }) => {
        capturedUrl = request.url;
        return HttpResponse.json({
          jobs: [],
          total: 3,
          page: 1,
          per_page: 1,
          generated_at: '2026-06-09 21:30:00',
        });
      }),
    );

    const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const { result } = renderHook(() => useJobsCount(), { wrapper: makeWrapper(qc) });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data).toBe(3);
    expect(capturedUrl).toContain('status=active');
    expect(capturedUrl).toContain('per_page=1');
  });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd apps/web && pnpm test -- --run useJobsList useJobDetail useJobsCount`

Expected: all FAIL with module-resolution errors.

- [ ] **Step 3: Create the three hooks**

Create `apps/web/src/lib/queries/useJobsList.ts`:

```ts
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { jobsListResponseSchema, type JobsListResponse } from '@/types/api';

export type JobsStatusFilter = 'active' | 'completed' | 'all';

/**
 * P2.9 — pure poll-interval decision, exported for direct unit testing
 * (guardrail #9): 10s while ANY job is non-terminal, otherwise stop.
 */
export function jobsListPollInterval(data: JobsListResponse | undefined): number | false {
  return data?.jobs.some((j) => j.state === 'queued' || j.state === 'in_progress') ? 10_000 : false;
}

/**
 * P2.9 — jobs list with adaptive polling. Path is `/jobs` (trap #27 —
 * apiClient prepends /api/defyn/v1; the spec's `/overview/jobs` example
 * was wrong). TanStack v5: refetchInterval receives the Query object
 * (trap #28).
 */
export function useJobsList(status: JobsStatusFilter, page: number) {
  return useQuery({
    queryKey: ['jobs', status, page],
    queryFn: async () => {
      const data = await apiClient.get<unknown>(`/jobs?status=${status}&page=${page}&per_page=20`);
      return jobsListResponseSchema.parse(data);
    },
    refetchInterval: (query) =>
      jobsListPollInterval(query.state.data as JobsListResponse | undefined),
    refetchIntervalInBackground: false,
    staleTime: 5_000,
  });
}
```

Create `apps/web/src/lib/queries/useJobDetail.ts`:

```ts
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { jobDetailResponseSchema, type JobDetailResponse } from '@/types/api';

/**
 * P2.9 — pure poll-interval decision, exported for direct unit testing
 * (guardrail #9): 5s while ANY item is queued/started, otherwise stop —
 * the UI freezes at the final state until the operator navigates away.
 */
export function jobDetailPollInterval(data: JobDetailResponse | undefined): number | false {
  return data?.items.some((i) => i.state === 'queued' || i.state === 'started') ? 5_000 : false;
}

export function useJobDetail(id: number) {
  return useQuery({
    queryKey: ['job', id],
    queryFn: async () => {
      const data = await apiClient.get<unknown>(`/jobs/${id}`);
      return jobDetailResponseSchema.parse(data);
    },
    refetchInterval: (query) =>
      jobDetailPollInterval(query.state.data as JobDetailResponse | undefined),
    refetchIntervalInBackground: false,
    staleTime: 2_000,
  });
}
```

Create `apps/web/src/lib/queries/useJobsCount.ts`:

```ts
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { jobsListResponseSchema } from '@/types/api';

/**
 * P2.9 — active-jobs count for the JobsNavLink badge. Derived from the
 * list endpoint (status=active&per_page=1 — `total` carries the count;
 * trap #32, no dedicated endpoint). Polls every 30s unconditionally
 * (guardrail #9).
 */
export function useJobsCount() {
  return useQuery({
    queryKey: ['jobsCount'],
    queryFn: async () => {
      const data = await apiClient.get<unknown>('/jobs?status=active&page=1&per_page=1');
      return jobsListResponseSchema.parse(data).total;
    },
    refetchInterval: 30_000,
    refetchIntervalInBackground: false,
  });
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd apps/web && pnpm test -- --run useJobsList useJobDetail useJobsCount`

Expected: 8 PASS (3 + 3 + 2).

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/lib/queries/useJobsList.ts \
        apps/web/src/lib/queries/useJobDetail.ts \
        apps/web/src/lib/queries/useJobsCount.ts \
        apps/web/tests/lib/queries/useJobsList.test.tsx \
        apps/web/tests/lib/queries/useJobDetail.test.tsx \
        apps/web/tests/lib/queries/useJobsCount.test.tsx
git commit -m "feat(p2-9): jobs query hooks with adaptive polling

useJobsList(status, page) — queryKey ['jobs', status, page], polls 10s
while any job is queued/in_progress via exported pure helper
jobsListPollInterval (directly unit-tested), staleTime 5s.
useJobDetail(id) — queryKey ['job', id], 5s while any item
queued/started via jobDetailPollInterval, staleTime 2s.
useJobsCount() — queryKey ['jobsCount'], derives the badge count from
GET /jobs?status=active&per_page=1 (total field), 30s unconditional.

TanStack v5 refetchInterval(query) signature throughout (trap #28);
paths are /jobs (trap #27 — NOT the spec's /overview/jobs).

8 unit tests.

Per spec § 3.6 + guardrail #9."
```

---

## Task 14 — `useCancelJob` + `useRetryItem` + `useRetryFailed` mutation hooks

**Files:**
- Create: `apps/web/src/lib/mutations/useCancelJob.ts`
- Create: `apps/web/src/lib/mutations/useRetryItem.ts`
- Create: `apps/web/src/lib/mutations/useRetryFailed.ts`
- Test: `apps/web/tests/lib/mutations/useCancelJob.test.tsx` (CREATE)
- Test: `apps/web/tests/lib/mutations/useRetryItem.test.tsx` (CREATE)
- Test: `apps/web/tests/lib/mutations/useRetryFailed.test.tsx` (CREATE)

- [ ] **Step 1: Write the failing tests**

Create `apps/web/tests/lib/mutations/useCancelJob.test.tsx`:

```tsx
import { describe, it, expect, vi } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useCancelJob } from '@/lib/mutations/useCancelJob';
import React from 'react';

function makeWrapper(qc: QueryClient) {
  return ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
}

describe('useCancelJob', () => {
  it('posts to /jobs/{id}/cancel and parses the 200 response', async () => {
    const qc = new QueryClient({ defaultOptions: { mutations: { retry: false } } });
    const { result } = renderHook(() => useCancelJob(), { wrapper: makeWrapper(qc) });

    result.current.mutate(42);

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data?.cancelled_count).toBe(1);
    expect(result.current.data?.still_running_count).toBe(0);
  });

  it('invalidates job, jobs and jobsCount but NOT sites (guardrail #10)', async () => {
    const qc = new QueryClient({ defaultOptions: { mutations: { retry: false } } });
    const invalidateSpy = vi.spyOn(qc, 'invalidateQueries');
    const { result } = renderHook(() => useCancelJob(), { wrapper: makeWrapper(qc) });

    result.current.mutate(42);

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: ['job', 42] });
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: ['jobs'] });
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: ['jobsCount'] });

    const sitesCall = invalidateSpy.mock.calls.find(
      ([arg]) =>
        Array.isArray((arg as { queryKey?: unknown }).queryKey) &&
        (arg as { queryKey: unknown[] }).queryKey[0] === 'sites',
    );
    expect(sitesCall).toBeUndefined();
  });
});
```

Create `apps/web/tests/lib/mutations/useRetryItem.test.tsx`:

```tsx
import { describe, it, expect, vi } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useRetryItem } from '@/lib/mutations/useRetryItem';
import React from 'react';

function makeWrapper(qc: QueryClient) {
  return ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
}

describe('useRetryItem', () => {
  it('posts to /jobs/{id}/items/{itemId}/retry and parses the 202 response', async () => {
    const qc = new QueryClient({ defaultOptions: { mutations: { retry: false } } });
    const { result } = renderHook(() => useRetryItem(), { wrapper: makeWrapper(qc) });

    result.current.mutate({ jobId: 42, itemId: 202 });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data?.item_id).toBe(202);
  });

  it('invalidates job, jobs and jobsCount but NOT sites (guardrail #10)', async () => {
    const qc = new QueryClient({ defaultOptions: { mutations: { retry: false } } });
    const invalidateSpy = vi.spyOn(qc, 'invalidateQueries');
    const { result } = renderHook(() => useRetryItem(), { wrapper: makeWrapper(qc) });

    result.current.mutate({ jobId: 42, itemId: 202 });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: ['job', 42] });
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: ['jobs'] });
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: ['jobsCount'] });

    const sitesCall = invalidateSpy.mock.calls.find(
      ([arg]) =>
        Array.isArray((arg as { queryKey?: unknown }).queryKey) &&
        (arg as { queryKey: unknown[] }).queryKey[0] === 'sites',
    );
    expect(sitesCall).toBeUndefined();
  });
});
```

Create `apps/web/tests/lib/mutations/useRetryFailed.test.tsx`:

```tsx
import { describe, it, expect, vi } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useRetryFailed } from '@/lib/mutations/useRetryFailed';
import React from 'react';

function makeWrapper(qc: QueryClient) {
  return ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
}

describe('useRetryFailed', () => {
  it('posts to /jobs/{id}/retry-failed and parses the 202 response', async () => {
    const qc = new QueryClient({ defaultOptions: { mutations: { retry: false } } });
    const { result } = renderHook(() => useRetryFailed(), { wrapper: makeWrapper(qc) });

    result.current.mutate(42);

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data?.retried_count).toBe(1);
    expect(result.current.data?.retried_item_ids).toEqual([202]);
  });

  it('invalidates job, jobs and jobsCount but NOT sites (guardrail #10)', async () => {
    const qc = new QueryClient({ defaultOptions: { mutations: { retry: false } } });
    const invalidateSpy = vi.spyOn(qc, 'invalidateQueries');
    const { result } = renderHook(() => useRetryFailed(), { wrapper: makeWrapper(qc) });

    result.current.mutate(42);

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: ['job', 42] });
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: ['jobs'] });
    expect(invalidateSpy).toHaveBeenCalledWith({ queryKey: ['jobsCount'] });

    const sitesCall = invalidateSpy.mock.calls.find(
      ([arg]) =>
        Array.isArray((arg as { queryKey?: unknown }).queryKey) &&
        (arg as { queryKey: unknown[] }).queryKey[0] === 'sites',
    );
    expect(sitesCall).toBeUndefined();
  });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd apps/web && pnpm test -- --run useCancelJob useRetryItem useRetryFailed`

Expected: 6 FAIL with module-resolution errors.

- [ ] **Step 3: Create the three hooks**

Create `apps/web/src/lib/mutations/useCancelJob.ts`:

```ts
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { cancelJobResponseSchema, type CancelJobResponse } from '@/types/api';

/**
 * P2.9 — POST /jobs/{id}/cancel. On success invalidates ['job', id] +
 * ['jobs'] (prefix — every status/page key) + ['jobsCount']. NOT
 * ['sites'] — per-site state hasn't changed (guardrail #10).
 */
export function useCancelJob() {
  const queryClient = useQueryClient();

  return useMutation<CancelJobResponse, Error, number>({
    mutationFn: async (jobId) => {
      const data = await apiClient.post<unknown>(`/jobs/${jobId}/cancel`);
      return cancelJobResponseSchema.parse(data);
    },
    onSuccess: (_data, jobId) => {
      queryClient.invalidateQueries({ queryKey: ['job', jobId] });
      queryClient.invalidateQueries({ queryKey: ['jobs'] });
      queryClient.invalidateQueries({ queryKey: ['jobsCount'] });
    },
  });
}
```

Create `apps/web/src/lib/mutations/useRetryItem.ts`:

```ts
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { retryItemResponseSchema, type RetryItemResponse } from '@/types/api';

export interface RetryItemVariables {
  jobId: number;
  itemId: number;
}

/**
 * P2.9 — POST /jobs/{id}/items/{itemId}/retry. One-click per-item retry
 * (guardrail #21 — no confirm dialog). Same invalidation set as
 * useCancelJob (guardrail #10).
 */
export function useRetryItem() {
  const queryClient = useQueryClient();

  return useMutation<RetryItemResponse, Error, RetryItemVariables>({
    mutationFn: async ({ jobId, itemId }) => {
      const data = await apiClient.post<unknown>(`/jobs/${jobId}/items/${itemId}/retry`);
      return retryItemResponseSchema.parse(data);
    },
    onSuccess: (_data, { jobId }) => {
      queryClient.invalidateQueries({ queryKey: ['job', jobId] });
      queryClient.invalidateQueries({ queryKey: ['jobs'] });
      queryClient.invalidateQueries({ queryKey: ['jobsCount'] });
    },
  });
}
```

Create `apps/web/src/lib/mutations/useRetryFailed.ts`:

```ts
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { retryFailedResponseSchema, type RetryFailedResponse } from '@/types/api';

/**
 * P2.9 — POST /jobs/{id}/retry-failed. Bulk retry behind the neutral
 * RetryFailedDialog. Same invalidation set as useCancelJob (guardrail #10).
 */
export function useRetryFailed() {
  const queryClient = useQueryClient();

  return useMutation<RetryFailedResponse, Error, number>({
    mutationFn: async (jobId) => {
      const data = await apiClient.post<unknown>(`/jobs/${jobId}/retry-failed`);
      return retryFailedResponseSchema.parse(data);
    },
    onSuccess: (_data, jobId) => {
      queryClient.invalidateQueries({ queryKey: ['job', jobId] });
      queryClient.invalidateQueries({ queryKey: ['jobs'] });
      queryClient.invalidateQueries({ queryKey: ['jobsCount'] });
    },
  });
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd apps/web && pnpm test -- --run useCancelJob useRetryItem useRetryFailed`

Expected: 6 PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/lib/mutations/useCancelJob.ts \
        apps/web/src/lib/mutations/useRetryItem.ts \
        apps/web/src/lib/mutations/useRetryFailed.ts \
        apps/web/tests/lib/mutations/useCancelJob.test.tsx \
        apps/web/tests/lib/mutations/useRetryItem.test.tsx \
        apps/web/tests/lib/mutations/useRetryFailed.test.tsx
git commit -m "feat(p2-9): cancel + retry mutation hooks

useCancelJob(jobId) / useRetryItem({jobId, itemId}) / useRetryFailed
(jobId). Each invalidates ['job', id] + ['jobs'] prefix + ['jobsCount']
on success — NOT ['sites'] (guardrail #10; per-site state only changes
when the AS workers actually run).

6 unit tests (2 per hook: response parse + invalidation set with
explicit no-sites assertion).

Per spec § 3.5 + guardrail #10."
```

---

## Task 15 — `JobStateChip` + `JobRow` + `JobItemRow`

**Files:**
- Create: `apps/web/src/components/jobs/JobStateChip.tsx`
- Create: `apps/web/src/components/jobs/JobRow.tsx`
- Create: `apps/web/src/components/jobs/JobItemRow.tsx`
- Test: `apps/web/tests/components/jobs/JobStateChip.test.tsx` (CREATE)
- Test: `apps/web/tests/components/jobs/JobRow.test.tsx` (CREATE)
- Test: `apps/web/tests/components/jobs/JobItemRow.test.tsx` (CREATE)

- [ ] **Step 1: Write the failing tests**

Create `apps/web/tests/components/jobs/JobStateChip.test.tsx`:

```tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { JobStateChip } from '@/components/jobs/JobStateChip';

describe('JobStateChip', () => {
  it('queued renders zinc treatment', () => {
    render(<JobStateChip state="queued" />);
    const chip = screen.getByTestId('job-state-chip');
    expect(chip).toHaveTextContent('queued');
    expect(chip.className).toContain('bg-zinc-100');
    expect(chip.className).toContain('text-zinc-600');
  });

  it('started renders blue treatment with spinner', () => {
    render(<JobStateChip state="started" />);
    const chip = screen.getByTestId('job-state-chip');
    expect(chip.className).toContain('bg-blue-100');
    expect(chip.querySelector('.animate-spin')).not.toBeNull();
  });

  it('succeeded renders green treatment', () => {
    render(<JobStateChip state="succeeded" />);
    expect(screen.getByTestId('job-state-chip').className).toContain('bg-green-100');
  });

  it('failed renders red treatment', () => {
    render(<JobStateChip state="failed" />);
    expect(screen.getByTestId('job-state-chip').className).toContain('bg-red-100');
  });

  it('cancelled renders strikethrough zinc treatment', () => {
    render(<JobStateChip state="cancelled" />);
    const chip = screen.getByTestId('job-state-chip');
    expect(chip.className).toContain('line-through');
    expect(chip.className).toContain('text-zinc-500');
  });

  it('in_progress renders blue treatment with spinner and a space in the label', () => {
    render(<JobStateChip state="in_progress" />);
    const chip = screen.getByTestId('job-state-chip');
    expect(chip).toHaveTextContent('in progress');
    expect(chip.className).toContain('bg-blue-100');
    expect(chip.querySelector('.animate-spin')).not.toBeNull();
  });

  it('completed renders green treatment', () => {
    render(<JobStateChip state="completed" />);
    expect(screen.getByTestId('job-state-chip').className).toContain('bg-green-100');
  });

  it('partial renders red treatment (dominant failed state)', () => {
    render(<JobStateChip state="partial" />);
    expect(screen.getByTestId('job-state-chip').className).toContain('bg-red-100');
  });

  it('queued job-level state reuses the item queued treatment', () => {
    // Job-level 'queued' and item-level 'queued' share the literal key —
    // single STATE_CLASSES map (guardrail #20).
    render(<JobStateChip state="queued" />);
    expect(screen.getByTestId('job-state-chip').className).toContain('text-zinc-600');
  });
});
```

Create `apps/web/tests/components/jobs/JobRow.test.tsx`:

```tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { JobRow } from '@/components/jobs/JobRow';
import type { Job } from '@/types/api';

const JOB: Job = {
  id: 42,
  kind: 'plugin_update',
  scheduled_count: 12,
  skipped_count: 0,
  succeeded_count: 9,
  failed_count: 2,
  cancelled_count: 1,
  queued_count: 0,
  started_count: 0,
  state: 'partial',
  started_at: '2026-06-09 21:00:00',
  completed_at: '2026-06-09 21:08:42',
  created_at: '2026-06-09 20:59:15',
};

function renderRow(job: Job) {
  return render(
    <MemoryRouter>
      <ul>
        <JobRow job={job} />
      </ul>
    </MemoryRouter>,
  );
}

describe('JobRow', () => {
  it('renders kind label + scheduled count + state chip', () => {
    renderRow(JOB);
    expect(screen.getByText(/plugin update — 12 scheduled/i)).toBeInTheDocument();
    expect(screen.getByTestId('job-state-chip')).toHaveTextContent('partial');
  });

  it('renders the per-state counts line', () => {
    renderRow(JOB);
    expect(
      screen.getByText(/9 succeeded · 2 failed · 1 cancelled · 0 queued · 0 started/i),
    ).toBeInTheDocument();
  });

  it('links to the job detail route', () => {
    renderRow(JOB);
    expect(screen.getByRole('link')).toHaveAttribute('href', '/jobs/42');
  });
});
```

Create `apps/web/tests/components/jobs/JobItemRow.test.tsx`:

```tsx
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { JobItemRow } from '@/components/jobs/JobItemRow';
import type { JobItem } from '@/types/api';

function makeItem(overrides: Partial<JobItem> = {}): JobItem {
  return {
    id: 202,
    site_id: 1,
    site_label: 'SmartCoding',
    resource_slug: 'elementor',
    resource_name: 'Elementor',
    current_version: '3.18.2',
    target_version: '4.0.0',
    state: 'succeeded',
    error_message: null,
    started_at: null,
    completed_at: null,
    created_at: '2026-06-09 20:59:15',
    ...overrides,
  };
}

describe('JobItemRow', () => {
  it('renders resource name + version diff + state chip', () => {
    render(
      <ul>
        <JobItemRow item={makeItem()} onRetry={() => undefined} retryPending={false} />
      </ul>,
    );
    expect(screen.getByText('Elementor')).toBeInTheDocument();
    expect(screen.getByText(/3\.18\.2 → 4\.0\.0/)).toBeInTheDocument();
    expect(screen.getByTestId('job-state-chip')).toHaveTextContent('succeeded');
  });

  it('hides the Retry button for non-failed states', () => {
    render(
      <ul>
        <JobItemRow item={makeItem({ state: 'queued' })} onRetry={() => undefined} retryPending={false} />
      </ul>,
    );
    expect(screen.queryByRole('button', { name: /retry/i })).not.toBeInTheDocument();
  });

  it('shows the error message + one-click Retry for failed items (guardrail #21)', () => {
    const onRetry = vi.fn();
    render(
      <ul>
        <JobItemRow
          item={makeItem({ state: 'failed', error_message: 'Could not copy file.' })}
          onRetry={onRetry}
          retryPending={false}
        />
      </ul>,
    );
    expect(screen.getByText('Could not copy file.')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /retry/i }));
    expect(onRetry).toHaveBeenCalledWith(202); // one click — NO confirm dialog
  });

  it('disables Retry while a retry mutation is pending', () => {
    render(
      <ul>
        <JobItemRow
          item={makeItem({ state: 'failed', error_message: 'boom' })}
          onRetry={() => undefined}
          retryPending={true}
        />
      </ul>,
    );
    expect(screen.getByRole('button', { name: /retry/i })).toBeDisabled();
  });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd apps/web && pnpm test -- --run JobStateChip JobRow JobItemRow`

Expected: all FAIL with module-resolution errors.

- [ ] **Step 3: Create the three components**

Create `apps/web/src/components/jobs/JobStateChip.tsx`:

```tsx
import { Check, Clock, Loader2, Minus, X } from 'lucide-react';
import type { JobItemState, JobState } from '@/types/api';

type ChipState = JobState | JobItemState;

/**
 * P2.9 — SINGLE source of truth for state chip colors (guardrail #20).
 * Spec § 3.7 item palette; job-level states map onto the dominant item
 * state: in_progress → started (blue), completed → succeeded (green),
 * partial → failed (red).
 */
const STATE_CLASSES: Record<ChipState, string> = {
  queued: 'text-zinc-600 bg-zinc-100',
  started: 'text-blue-700 bg-blue-100',
  in_progress: 'text-blue-700 bg-blue-100',
  succeeded: 'text-green-700 bg-green-100',
  completed: 'text-green-700 bg-green-100',
  failed: 'text-red-700 bg-red-100',
  partial: 'text-red-700 bg-red-100',
  cancelled: 'text-zinc-500 bg-zinc-100 line-through',
};

const STATE_ICONS: Record<ChipState, typeof Check> = {
  queued: Clock,
  started: Loader2,
  in_progress: Loader2,
  succeeded: Check,
  completed: Check,
  failed: X,
  partial: X,
  cancelled: Minus,
};

interface JobStateChipProps {
  state: ChipState;
}

export function JobStateChip({ state }: JobStateChipProps) {
  const Icon = STATE_ICONS[state];
  const isSpinning = state === 'started' || state === 'in_progress';

  return (
    <span
      data-testid="job-state-chip"
      className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${STATE_CLASSES[state]}`}
    >
      <Icon className={`h-3 w-3${isSpinning ? ' animate-spin' : ''}`} aria-hidden="true" />
      {state.replace('_', ' ')}
    </span>
  );
}
```

Create `apps/web/src/components/jobs/JobRow.tsx`:

```tsx
import { Link } from 'react-router-dom';
import { JobStateChip } from '@/components/jobs/JobStateChip';
import { formatRelativeTime } from '@/lib/formatRelativeTime';
import type { Job } from '@/types/api';

const KIND_LABELS: Record<Job['kind'], string> = {
  plugin_update: 'Plugin update',
  theme_update: 'Theme update',
};

interface JobRowProps {
  job: Job;
}

/**
 * P2.9 — single list-row card on /jobs (spec § 3.3). Whole row is a Link
 * to /jobs/{id}.
 */
export function JobRow({ job }: JobRowProps) {
  return (
    <li className="rounded-md border border-zinc-200 bg-white">
      <Link to={`/jobs/${job.id}`} className="block p-3 hover:bg-zinc-50">
        <div className="flex items-center justify-between">
          <span className="text-sm font-semibold text-zinc-900">
            {KIND_LABELS[job.kind]} — {job.scheduled_count} scheduled
          </span>
          <JobStateChip state={job.state} />
        </div>
        <p className="mt-1 text-xs text-zinc-600">
          {job.succeeded_count} succeeded · {job.failed_count} failed · {job.cancelled_count} cancelled
          {' · '}
          {job.queued_count} queued · {job.started_count} started · {formatRelativeTime(job.created_at)}
        </p>
      </Link>
    </li>
  );
}
```

Create `apps/web/src/components/jobs/JobItemRow.tsx`:

```tsx
import { Button } from '@/components/ui/button';
import { JobStateChip } from '@/components/jobs/JobStateChip';
import type { JobItem } from '@/types/api';

interface JobItemRowProps {
  item: JobItem;
  onRetry: (itemId: number) => void;
  retryPending: boolean;
}

/**
 * P2.9 — single item row inside JobItemsGroup (spec § 3.4). Per-row Retry
 * is ONE-CLICK with no confirmation (guardrail #21) and only renders when
 * the item state is `failed`.
 */
export function JobItemRow({ item, onRetry, retryPending }: JobItemRowProps) {
  return (
    <li className="flex items-center gap-2 py-1.5 text-sm text-zinc-700">
      <span className="flex-1 truncate">
        {item.resource_name}
        <span className="ml-2 font-mono text-xs text-zinc-500">
          {item.current_version ?? '?'} → {item.target_version ?? '?'}
        </span>
      </span>
      {item.state === 'failed' && item.error_message !== null && (
        <span className="max-w-[200px] truncate text-xs text-red-700" title={item.error_message}>
          {item.error_message}
        </span>
      )}
      <JobStateChip state={item.state} />
      {item.state === 'failed' && (
        <Button size="sm" variant="outline" disabled={retryPending} onClick={() => onRetry(item.id)}>
          Retry
        </Button>
      )}
    </li>
  );
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd apps/web && pnpm test -- --run JobStateChip JobRow JobItemRow`

Expected: 16 PASS (9 + 3 + 4).

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/components/jobs/JobStateChip.tsx \
        apps/web/src/components/jobs/JobRow.tsx \
        apps/web/src/components/jobs/JobItemRow.tsx \
        apps/web/tests/components/jobs/JobStateChip.test.tsx \
        apps/web/tests/components/jobs/JobRow.test.tsx \
        apps/web/tests/components/jobs/JobItemRow.test.tsx
git commit -m "feat(p2-9): JobStateChip + JobRow + JobItemRow components

JobStateChip is the SINGLE color source for all 9 states (5 item + 4
job — guardrail #20): queued zinc/clock, started+in_progress
blue/spinner, succeeded+completed green/check, failed+partial red/X,
cancelled zinc strikethrough/dash. JobRow is the /jobs list card (Link
to detail, counts line, relative time). JobItemRow shows resource +
version diff + chip + truncated error + ONE-CLICK Retry rendered only
for failed items (guardrail #21).

16 component tests.

Per spec § 3.3 + § 3.4 + § 3.7."
```

---

## Task 16 — `JobItemsGroup` + `JobHeader` + `CancelJobDialog` + `RetryFailedDialog`

**Files:**
- Create: `apps/web/src/components/jobs/JobItemsGroup.tsx`
- Create: `apps/web/src/components/jobs/JobHeader.tsx`
- Create: `apps/web/src/components/jobs/CancelJobDialog.tsx`
- Create: `apps/web/src/components/jobs/RetryFailedDialog.tsx`
- Test: `apps/web/tests/components/jobs/JobHeaderAndDialogs.test.tsx` (CREATE)

- [ ] **Step 1: Write the failing tests**

Create `apps/web/tests/components/jobs/JobHeaderAndDialogs.test.tsx`:

```tsx
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { JobHeader } from '@/components/jobs/JobHeader';
import { JobItemsGroup } from '@/components/jobs/JobItemsGroup';
import type { Job, JobItem } from '@/types/api';

function makeJob(overrides: Partial<Job> = {}): Job {
  return {
    id: 42,
    kind: 'plugin_update',
    scheduled_count: 12,
    skipped_count: 0,
    succeeded_count: 9,
    failed_count: 2,
    cancelled_count: 0,
    queued_count: 1,
    started_count: 0,
    state: 'in_progress',
    started_at: '2026-06-09 21:00:00',
    completed_at: null,
    created_at: '2026-06-09 20:59:15',
    ...overrides,
  };
}

function makeItem(overrides: Partial<JobItem> = {}): JobItem {
  return {
    id: 201,
    site_id: 1,
    site_label: 'SmartCoding',
    resource_slug: 'akismet',
    resource_name: 'Akismet Anti-Spam',
    current_version: '5.3',
    target_version: '5.3.1',
    state: 'succeeded',
    error_message: null,
    started_at: null,
    completed_at: null,
    created_at: '2026-06-09 20:59:15',
    ...overrides,
  };
}

function renderHeader(job: Job, onCancel = vi.fn(), onRetryFailed = vi.fn()) {
  render(
    <JobHeader
      job={job}
      onCancel={onCancel}
      onRetryFailed={onRetryFailed}
      cancelPending={false}
      retryFailedPending={false}
    />,
  );
  return { onCancel, onRetryFailed };
}

describe('JobHeader', () => {
  it('renders kind, job id, counts and state chip', () => {
    renderHeader(makeJob());
    expect(screen.getByText(/plugin update — job #42/i)).toBeInTheDocument();
    expect(screen.getByText(/12 scheduled · 9 succeeded · 2 failed · 0 cancelled/i)).toBeInTheDocument();
    expect(screen.getByTestId('job-state-chip')).toHaveTextContent('in progress');
  });

  it('Cancel enabled when queued_count > 0; confirm flows through the neutral dialog', () => {
    const { onCancel } = renderHeader(makeJob({ queued_count: 3 }));

    const cancelButton = screen.getByRole('button', { name: /^cancel$/i });
    expect(cancelButton).toBeEnabled();

    fireEvent.click(cancelButton);
    // Dialog copy per spec § 3.8.
    expect(screen.getByText(/cancel 3 queued items\?/i)).toBeInTheDocument();
    expect(
      screen.getByText(/items already in progress can't be cancelled and will continue running/i),
    ).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /cancel 3 queued items/i }));
    expect(onCancel).toHaveBeenCalledTimes(1);
  });

  it('Cancel disabled with tooltip when queued_count === 0', () => {
    renderHeader(makeJob({ queued_count: 0 }));
    const cancelButton = screen.getByRole('button', { name: /^cancel$/i });
    expect(cancelButton).toBeDisabled();
    expect(cancelButton).toHaveAttribute('title', 'All items already started or terminal');
  });

  it('Retry all enabled when failed_count > 0; confirm flows through the neutral dialog', () => {
    const { onRetryFailed } = renderHeader(makeJob({ failed_count: 2 }));

    const retryAll = screen.getByRole('button', { name: /retry all/i });
    expect(retryAll).toBeEnabled();

    fireEvent.click(retryAll);
    expect(screen.getByText(/retry 2 failed items\?/i)).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /retry 2 items/i }));
    expect(onRetryFailed).toHaveBeenCalledTimes(1);
  });

  it('Retry all disabled when failed_count === 0', () => {
    renderHeader(makeJob({ failed_count: 0 }));
    expect(screen.getByRole('button', { name: /retry all/i })).toBeDisabled();
  });
});

describe('JobItemsGroup', () => {
  it('renders site label with item count and toggles collapse', () => {
    render(
      <JobItemsGroup
        siteLabel="SmartCoding"
        items={[makeItem(), makeItem({ id: 202, resource_slug: 'yoast', resource_name: 'Yoast SEO' })]}
        defaultExpanded={true}
        onRetryItem={() => undefined}
        retryPending={false}
      />,
    );
    expect(screen.getByText(/smartcoding \(2 items\)/i)).toBeInTheDocument();
    expect(screen.getByText('Akismet Anti-Spam')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /smartcoding/i }));
    expect(screen.queryByText('Akismet Anti-Spam')).not.toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd apps/web && pnpm test -- --run JobHeaderAndDialogs`

Expected: 6 FAIL with module-resolution errors.

- [ ] **Step 3: Create the four components**

Create `apps/web/src/components/jobs/CancelJobDialog.tsx`:

```tsx
import { useEffect, useRef } from 'react';
import { Button } from '@/components/ui/button';

interface CancelJobDialogProps {
  open: boolean;
  queuedCount: number;
  onClose: () => void;
  onConfirm: () => void;
}

/**
 * P2.9 — neutral confirm for cancel-queued (spec § 3.8). NOT red —
 * cancel-queued is non-destructive (guardrail #1; nothing is deleted,
 * queued work is simply not performed). Cancel-the-dialog button is
 * labelled "Back" to avoid two "Cancel" buttons. cancelRef focus-on-open
 * mirrors ConfirmSyncAllDialog (P2.6).
 */
export function CancelJobDialog({ open, queuedCount, onClose, onConfirm }: CancelJobDialogProps) {
  const backRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (open) {
      backRef.current?.focus();
    }
  }, [open]);

  if (!open) {
    return null;
  }

  const titleId = 'cancel-job-confirm-title';

  return (
    <div
      role="alertdialog"
      aria-modal="true"
      aria-labelledby={titleId}
      className="mt-3 rounded-md border border-zinc-200 bg-white p-4 shadow-sm"
    >
      <h3 id={titleId} className="text-sm font-semibold text-zinc-900">
        Cancel {queuedCount} queued items?
      </h3>

      <p className="mt-3 text-sm text-zinc-700">
        Items already in progress can't be cancelled and will continue running.
      </p>

      <div className="mt-4 flex items-center justify-end gap-2">
        <Button ref={backRef} variant="outline" onClick={onClose}>
          Back
        </Button>
        <Button variant="default" onClick={onConfirm}>
          Cancel {queuedCount} queued items
        </Button>
      </div>
    </div>
  );
}
```

Create `apps/web/src/components/jobs/RetryFailedDialog.tsx`:

```tsx
import { useEffect, useRef } from 'react';
import { Button } from '@/components/ui/button';

interface RetryFailedDialogProps {
  open: boolean;
  failedCount: number;
  onClose: () => void;
  onConfirm: () => void;
}

/**
 * P2.9 — neutral confirm for bulk retry-failed (spec § 3.8). Default
 * shadcn primary, not red (guardrail #1).
 */
export function RetryFailedDialog({ open, failedCount, onClose, onConfirm }: RetryFailedDialogProps) {
  const backRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (open) {
      backRef.current?.focus();
    }
  }, [open]);

  if (!open) {
    return null;
  }

  const titleId = 'retry-failed-confirm-title';

  return (
    <div
      role="alertdialog"
      aria-modal="true"
      aria-labelledby={titleId}
      className="mt-3 rounded-md border border-zinc-200 bg-white p-4 shadow-sm"
    >
      <h3 id={titleId} className="text-sm font-semibold text-zinc-900">
        Retry {failedCount} failed items?
      </h3>

      <p className="mt-3 text-sm text-zinc-700">
        Each item is re-queued and re-attempted from scratch.
      </p>

      <div className="mt-4 flex items-center justify-end gap-2">
        <Button ref={backRef} variant="outline" onClick={onClose}>
          Back
        </Button>
        <Button variant="default" onClick={onConfirm}>
          Retry {failedCount} items
        </Button>
      </div>
    </div>
  );
}
```

Create `apps/web/src/components/jobs/JobItemsGroup.tsx`:

```tsx
import { useState } from 'react';
import { JobItemRow } from '@/components/jobs/JobItemRow';
import type { JobItem } from '@/types/api';

interface JobItemsGroupProps {
  siteLabel: string;
  items: JobItem[];
  defaultExpanded: boolean;
  onRetryItem: (itemId: number) => void;
  retryPending: boolean;
}

/**
 * P2.9 — per-site collapsible group on the detail view (spec § 3.4).
 * JobDetail passes defaultExpanded={index < 3} so long lists collapse
 * with the first 3 sites expanded (mirrors the P2.7 dialog pattern).
 */
export function JobItemsGroup({ siteLabel, items, defaultExpanded, onRetryItem, retryPending }: JobItemsGroupProps) {
  const [expanded, setExpanded] = useState(defaultExpanded);

  return (
    <div data-testid="job-items-group" className="rounded border border-zinc-200 p-3">
      <button
        type="button"
        className="flex w-full items-center justify-between text-sm font-semibold text-zinc-900"
        onClick={() => setExpanded((prev) => !prev)}
        aria-expanded={expanded}
      >
        <span>
          {siteLabel} ({items.length} item{items.length === 1 ? '' : 's'})
        </span>
        <span aria-hidden="true">{expanded ? '▾' : '▸'}</span>
      </button>
      {expanded && (
        <ul className="mt-2 divide-y divide-zinc-100">
          {items.map((item) => (
            <JobItemRow key={item.id} item={item} onRetry={onRetryItem} retryPending={retryPending} />
          ))}
        </ul>
      )}
    </div>
  );
}
```

Create `apps/web/src/components/jobs/JobHeader.tsx`:

```tsx
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { CancelJobDialog } from '@/components/jobs/CancelJobDialog';
import { JobStateChip } from '@/components/jobs/JobStateChip';
import { RetryFailedDialog } from '@/components/jobs/RetryFailedDialog';
import { formatRelativeTime } from '@/lib/formatRelativeTime';
import type { Job } from '@/types/api';

const KIND_LABELS: Record<Job['kind'], string> = {
  plugin_update: 'Plugin update',
  theme_update: 'Theme update',
};

interface JobHeaderProps {
  job: Job;
  onCancel: () => void;
  onRetryFailed: () => void;
  cancelPending: boolean;
  retryFailedPending: boolean;
}

/**
 * P2.9 — detail-view header (spec § 3.4 + § 3.8). Cancel enabled iff
 * queued_count > 0 (disabled tooltip otherwise); Retry-all enabled iff
 * failed_count > 0. Both flow through neutral default-styled dialogs.
 */
export function JobHeader({ job, onCancel, onRetryFailed, cancelPending, retryFailedPending }: JobHeaderProps) {
  const [cancelOpen, setCancelOpen] = useState(false);
  const [retryOpen, setRetryOpen] = useState(false);

  const cancelDisabled = job.queued_count === 0 || cancelPending;

  return (
    <div className="rounded-md border border-zinc-200 bg-white p-4">
      <div className="flex items-start justify-between">
        <div>
          <div className="flex items-center gap-2">
            <h1 className="text-xl font-semibold text-zinc-900">
              {KIND_LABELS[job.kind]} — Job #{job.id}
            </h1>
            <JobStateChip state={job.state} />
          </div>
          <p className="mt-1 text-sm text-zinc-600">
            {job.scheduled_count} scheduled · {job.succeeded_count} succeeded · {job.failed_count} failed
            {' · '}
            {job.cancelled_count} cancelled · {formatRelativeTime(job.created_at)}
          </p>
        </div>
        <div className="flex gap-2">
          <Button
            variant="outline"
            size="sm"
            disabled={cancelDisabled}
            title={job.queued_count === 0 ? 'All items already started or terminal' : undefined}
            onClick={() => setCancelOpen(true)}
          >
            Cancel
          </Button>
          <Button
            variant="default"
            size="sm"
            disabled={job.failed_count === 0 || retryFailedPending}
            onClick={() => setRetryOpen(true)}
          >
            Retry all
          </Button>
        </div>
      </div>

      <CancelJobDialog
        open={cancelOpen}
        queuedCount={job.queued_count}
        onClose={() => setCancelOpen(false)}
        onConfirm={() => {
          setCancelOpen(false);
          onCancel();
        }}
      />
      <RetryFailedDialog
        open={retryOpen}
        failedCount={job.failed_count}
        onClose={() => setRetryOpen(false)}
        onConfirm={() => {
          setRetryOpen(false);
          onRetryFailed();
        }}
      />
    </div>
  );
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd apps/web && pnpm test -- --run JobHeaderAndDialogs`

Expected: 6 PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/components/jobs/JobItemsGroup.tsx \
        apps/web/src/components/jobs/JobHeader.tsx \
        apps/web/src/components/jobs/CancelJobDialog.tsx \
        apps/web/src/components/jobs/RetryFailedDialog.tsx \
        apps/web/tests/components/jobs/JobHeaderAndDialogs.test.tsx
git commit -m "feat(p2-9): JobHeader + JobItemsGroup + neutral confirm dialogs

JobHeader: kind + Job #id + counts + state chip; Cancel button enabled
iff queued_count > 0 (disabled tooltip 'All items already started or
terminal'), Retry all enabled iff failed_count > 0. Both actions flow
through NEUTRAL default-styled dialogs (guardrail #1 — non-destructive;
dismiss button is 'Back' to avoid double-Cancel ambiguity; cancelRef
focus-on-open mirrors ConfirmSyncAllDialog).

JobItemsGroup: per-site collapsible with item count + aria-expanded;
detail page expands the first 3 sites by default.

6 component tests covering enable/disable rules + dialog confirm flow +
collapse toggle.

Per spec § 3.4 + § 3.8."
```

---

## Task 17 — `Jobs.tsx` + `JobDetail.tsx` routes + `JobsNavLink` + router wiring

**Files:**
- Create: `apps/web/src/routes/Jobs.tsx`
- Create: `apps/web/src/routes/JobDetail.tsx`
- Create: `apps/web/src/components/nav/JobsNavLink.tsx`
- Modify: `apps/web/src/App.tsx` (2 routes)
- Modify: `apps/web/src/routes/Overview.tsx` (render JobsNavLink)
- Test: `apps/web/tests/routes/Jobs.test.tsx` (CREATE)
- Test: `apps/web/tests/routes/JobDetail.test.tsx` (CREATE)
- Test: `apps/web/tests/components/nav/JobsNavLink.test.tsx` (CREATE)

- [ ] **Step 1: Write the failing tests**

Create `apps/web/tests/routes/Jobs.test.tsx`:

```tsx
import { describe, it, expect } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import { server } from '@/test/setup';
import Jobs from '@/routes/Jobs';
import React from 'react';

function renderJobs(initialEntry = '/jobs') {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={[initialEntry]}>
        <Routes>
          <Route path="/jobs" element={<Jobs />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('Jobs', () => {
  it('renders the list from the default MSW handler', async () => {
    renderJobs();

    await waitFor(() => {
      expect(screen.getByText(/plugin update — 3 scheduled/i)).toBeInTheDocument();
    });
    expect(screen.getByTestId('job-state-chip')).toHaveTextContent('in progress');
  });

  it('status filter chips switch the query (Completed shows the empty state)', async () => {
    renderJobs();

    await waitFor(() => expect(screen.getByText(/plugin update — 3 scheduled/i)).toBeInTheDocument());

    // Default MSW handler returns [] for status=completed.
    fireEvent.click(screen.getByRole('button', { name: /^completed$/i }));

    await waitFor(() => {
      expect(screen.getByText(/no jobs yet/i)).toBeInTheDocument();
    });
  });

  it('paginates via Prev/Next when total exceeds per_page', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/jobs', ({ request }) => {
        const url = new URL(request.url);
        const page = Number(url.searchParams.get('page') ?? '1');
        return HttpResponse.json({
          jobs: [
            {
              id: page === 1 ? 42 : 41,
              kind: 'plugin_update',
              scheduled_count: page === 1 ? 12 : 5,
              skipped_count: 0,
              succeeded_count: 0,
              failed_count: 0,
              cancelled_count: 0,
              queued_count: page === 1 ? 12 : 5,
              started_count: 0,
              state: 'queued',
              started_at: null,
              completed_at: null,
              created_at: '2026-06-09 20:59:15',
            },
          ],
          total: 25,
          page,
          per_page: 20,
          generated_at: '2026-06-09 21:30:00',
        });
      }),
    );

    renderJobs();

    await waitFor(() => expect(screen.getByText(/page 1 of 2/i)).toBeInTheDocument());
    expect(screen.getByRole('button', { name: /prev/i })).toBeDisabled();

    fireEvent.click(screen.getByRole('button', { name: /next/i }));

    await waitFor(() => expect(screen.getByText(/page 2 of 2/i)).toBeInTheDocument());
    expect(screen.getByText(/plugin update — 5 scheduled/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /next/i })).toBeDisabled();
  });
});
```

Create `apps/web/tests/routes/JobDetail.test.tsx`:

```tsx
import { describe, it, expect } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import JobDetail from '@/routes/JobDetail';
import React from 'react';

function renderDetail(jobId = 42) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={[`/jobs/${jobId}`]}>
        <Routes>
          <Route path="/jobs/:id" element={<JobDetail />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('JobDetail', () => {
  it('renders the header with kind, id and counts', async () => {
    renderDetail();

    await waitFor(() => {
      expect(screen.getByText(/plugin update — job #42/i)).toBeInTheDocument();
    });
    expect(screen.getByText(/3 scheduled · 1 succeeded · 1 failed · 0 cancelled/i)).toBeInTheDocument();
  });

  it('groups items per site (default fixture: SmartCoding ×2 + AcmeBlog ×1)', async () => {
    renderDetail();

    await waitFor(() => expect(screen.getAllByTestId('job-items-group')).toHaveLength(2));
    expect(screen.getByText(/smartcoding \(2 items\)/i)).toBeInTheDocument();
    expect(screen.getByText(/acmeblog \(1 item\)/i)).toBeInTheDocument();
    expect(screen.getByText('Akismet Anti-Spam')).toBeInTheDocument();
  });

  it('Cancel button enabled (fixture has queued_count 1) and Retry-all enabled (failed_count 1)', async () => {
    renderDetail();

    await waitFor(() => expect(screen.getByRole('button', { name: /^cancel$/i })).toBeEnabled());
    expect(screen.getByRole('button', { name: /retry all/i })).toBeEnabled();
  });

  it('renders the Back to Jobs link', async () => {
    renderDetail();

    await waitFor(() => {
      expect(screen.getByRole('link', { name: /back to jobs/i })).toHaveAttribute('href', '/jobs');
    });
  });
});
```

Create `apps/web/tests/components/nav/JobsNavLink.test.tsx`:

```tsx
import { describe, it, expect } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import { server } from '@/test/setup';
import { JobsNavLink } from '@/components/nav/JobsNavLink';
import React from 'react';

function renderLink() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>
        <JobsNavLink />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('JobsNavLink', () => {
  it('shows the badge when active count > 0 (default MSW fixture has 1 active job)', async () => {
    renderLink();

    expect(screen.getByRole('link', { name: /jobs/i })).toHaveAttribute('href', '/jobs');
    await waitFor(() => expect(screen.getByTestId('jobs-badge')).toHaveTextContent('1'));
  });

  it('hides the badge when active count is 0 (guardrail #22)', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/jobs', () =>
        HttpResponse.json({
          jobs: [],
          total: 0,
          page: 1,
          per_page: 1,
          generated_at: '2026-06-09 21:30:00',
        }),
      ),
    );

    renderLink();

    // Give the query time to resolve, then assert no badge AND no "(0)".
    await waitFor(() => expect(screen.getByRole('link', { name: /jobs/i })).toBeInTheDocument());
    await waitFor(() => expect(screen.queryByTestId('jobs-badge')).not.toBeInTheDocument());
    expect(screen.queryByText(/\(0\)/)).not.toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd apps/web && pnpm test -- --run tests/routes/Jobs.test.tsx tests/routes/JobDetail.test.tsx JobsNavLink`

Expected: all FAIL with module-resolution errors.

- [ ] **Step 3: Create the routes + nav link, wire the router and Overview header**

Create `apps/web/src/components/nav/JobsNavLink.tsx`:

```tsx
import { Link } from 'react-router-dom';
import { useJobsCount } from '@/lib/queries/useJobsCount';

/**
 * P2.9 — nav link to /jobs with an active-jobs badge.
 *
 * PLAN-CORRECTION vs spec § 3.2 (trap #26): the SPA has NO sidebar shell
 * (App.tsx renders bare routes). The link renders in the Overview header
 * instead; Jobs.tsx links back. Badge hidden entirely at count 0 — no
 * "(0)" suffix (guardrail #22).
 */
export function JobsNavLink() {
  const { data: activeCount } = useJobsCount();

  return (
    <Link
      to="/jobs"
      className="inline-flex items-center gap-1.5 text-sm text-zinc-600 underline-offset-4 hover:underline"
    >
      Jobs
      {typeof activeCount === 'number' && activeCount > 0 && (
        <span
          data-testid="jobs-badge"
          className="rounded-full bg-blue-100 px-1.5 py-0.5 text-xs font-medium text-blue-700"
        >
          {activeCount}
        </span>
      )}
    </Link>
  );
}
```

Create `apps/web/src/routes/Jobs.tsx`:

```tsx
import { Link, useSearchParams } from 'react-router-dom';
import { Button } from '@/components/ui/button';
import { JobRow } from '@/components/jobs/JobRow';
import { useJobsList, type JobsStatusFilter } from '@/lib/queries/useJobsList';

const STATUS_FILTERS: Array<{ key: JobsStatusFilter; label: string }> = [
  { key: 'active', label: 'Active' },
  { key: 'completed', label: 'Completed' },
  { key: 'all', label: 'All' },
];

/**
 * P2.9 — /jobs list page (spec § 3.3). Status filter chips + paginated
 * rows; state lives in the URL (?status=&page=) so refresh/back work.
 */
export default function Jobs() {
  const [searchParams, setSearchParams] = useSearchParams();
  const statusParam = searchParams.get('status');
  const status: JobsStatusFilter =
    statusParam === 'active' || statusParam === 'completed' ? statusParam : 'all';
  const page = Math.max(1, Number(searchParams.get('page') ?? '1') || 1);

  const { data, isLoading, isError, refetch } = useJobsList(status, page);

  const totalPages = data ? Math.max(1, Math.ceil(data.total / data.per_page)) : 1;

  const setStatus = (next: JobsStatusFilter) => {
    setSearchParams({ status: next, page: '1' });
  };
  const setPage = (next: number) => {
    setSearchParams({ status, page: String(next) });
  };

  return (
    <div className="min-h-screen p-8">
      <div className="max-w-3xl mx-auto space-y-4">
        <div className="flex items-center justify-between">
          <div className="flex items-baseline gap-3">
            <h1 className="text-2xl font-semibold">Jobs</h1>
            <Link to="/overview" className="text-sm text-zinc-600 underline-offset-4 hover:underline">
              Back to Overview
            </Link>
          </div>
          <div className="flex gap-1">
            {STATUS_FILTERS.map(({ key, label }) => (
              <Button
                key={key}
                size="sm"
                variant={status === key ? 'default' : 'ghost'}
                onClick={() => setStatus(key)}
              >
                {label}
              </Button>
            ))}
          </div>
        </div>

        {isLoading && <div className="h-24 animate-pulse rounded-md bg-gray-100" />}

        {isError && (
          <div className="rounded-md border border-red-200 bg-red-50 p-4">
            <p className="text-sm text-red-800">Failed to load jobs.</p>
            <button
              onClick={() => refetch()}
              className="mt-2 rounded-md border border-red-200 px-3 py-1 text-sm text-red-800"
            >
              Try again
            </button>
          </div>
        )}

        {data && data.jobs.length === 0 && (
          <p className="text-sm text-zinc-600">
            No jobs yet. Bulk updates you launch from the Overview will appear here.
          </p>
        )}

        {data && data.jobs.length > 0 && (
          <ul className="space-y-2">
            {data.jobs.map((job) => (
              <JobRow key={job.id} job={job} />
            ))}
          </ul>
        )}

        {data && totalPages > 1 && (
          <div className="flex items-center justify-center gap-3 text-sm text-zinc-700">
            <Button size="sm" variant="outline" disabled={page <= 1} onClick={() => setPage(page - 1)}>
              Prev
            </Button>
            <span>
              Page {page} of {totalPages}
            </span>
            <Button size="sm" variant="outline" disabled={page >= totalPages} onClick={() => setPage(page + 1)}>
              Next
            </Button>
          </div>
        )}
      </div>
    </div>
  );
}
```

Create `apps/web/src/routes/JobDetail.tsx`:

```tsx
import { useMemo } from 'react';
import { Link, useParams } from 'react-router-dom';
import { JobHeader } from '@/components/jobs/JobHeader';
import { JobItemsGroup } from '@/components/jobs/JobItemsGroup';
import { useJobDetail } from '@/lib/queries/useJobDetail';
import { useCancelJob } from '@/lib/mutations/useCancelJob';
import { useRetryFailed } from '@/lib/mutations/useRetryFailed';
import { useRetryItem } from '@/lib/mutations/useRetryItem';
import type { JobItem } from '@/types/api';

/**
 * P2.9 — /jobs/:id detail page (spec § 3.4). Adaptive 5s polling via
 * useJobDetail; items grouped per site with the first 3 sites expanded.
 */
export default function JobDetail() {
  const params = useParams();
  const jobId = Number(params.id);

  const { data, isLoading, isError, refetch } = useJobDetail(jobId);
  const cancelJob = useCancelJob();
  const retryItem = useRetryItem();
  const retryFailed = useRetryFailed();

  const groups = useMemo(() => {
    const map = new Map<string, JobItem[]>();
    for (const item of data?.items ?? []) {
      const existing = map.get(item.site_label) ?? [];
      map.set(item.site_label, [...existing, item]);
    }
    return [...map.entries()];
  }, [data?.items]);

  return (
    <div className="min-h-screen p-8">
      <div className="max-w-3xl mx-auto space-y-4">
        <Link to="/jobs" className="text-sm text-zinc-600 underline-offset-4 hover:underline">
          Back to Jobs
        </Link>

        {isLoading && <div className="h-24 animate-pulse rounded-md bg-gray-100" />}

        {isError && (
          <div className="rounded-md border border-red-200 bg-red-50 p-4">
            <p className="text-sm text-red-800">Failed to load the job.</p>
            <button
              onClick={() => refetch()}
              className="mt-2 rounded-md border border-red-200 px-3 py-1 text-sm text-red-800"
            >
              Try again
            </button>
          </div>
        )}

        {data && (
          <>
            <JobHeader
              job={data.job}
              onCancel={() => cancelJob.mutate(jobId)}
              onRetryFailed={() => retryFailed.mutate(jobId)}
              cancelPending={cancelJob.isPending}
              retryFailedPending={retryFailed.isPending}
            />

            <div className="space-y-3">
              {groups.map(([siteLabel, items], index) => (
                <JobItemsGroup
                  key={siteLabel}
                  siteLabel={siteLabel}
                  items={items}
                  defaultExpanded={index < 3}
                  onRetryItem={(itemId) => retryItem.mutate({ jobId, itemId })}
                  retryPending={retryItem.isPending}
                />
              ))}
            </div>
          </>
        )}
      </div>
    </div>
  );
}
```

Modify `apps/web/src/App.tsx`. Old:

```tsx
import SiteDetail from './routes/SiteDetail';
import Activity from './routes/Activity';
```

New:

```tsx
import SiteDetail from './routes/SiteDetail';
import Activity from './routes/Activity';
import Jobs from './routes/Jobs';
import JobDetail from './routes/JobDetail';
```

Old:

```tsx
        <Route path="/sites/:id" element={<SiteDetail />} />
        <Route path="/activity" element={<Activity />} />
```

New:

```tsx
        <Route path="/sites/:id" element={<SiteDetail />} />
        <Route path="/jobs" element={<Jobs />} />
        <Route path="/jobs/:id" element={<JobDetail />} />
        <Route path="/activity" element={<Activity />} />
```

Modify `apps/web/src/routes/Overview.tsx`. Old:

```tsx
import { SyncAllSitesButton } from '@/components/overview/SyncAllSitesButton'
```

New:

```tsx
import { SyncAllSitesButton } from '@/components/overview/SyncAllSitesButton'
import { JobsNavLink } from '@/components/nav/JobsNavLink'
```

Old:

```tsx
      <div className="flex items-start justify-between">
        <h1 className="text-xl font-semibold">Overview</h1>
```

New:

```tsx
      <div className="flex items-start justify-between">
        <div className="flex items-baseline gap-3">
          <h1 className="text-xl font-semibold">Overview</h1>
          <JobsNavLink />
        </div>
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd apps/web && pnpm test -- --run tests/routes/Jobs.test.tsx tests/routes/JobDetail.test.tsx JobsNavLink`

Expected: 9 PASS (3 + 4 + 2).

Then the FULL suite (`pnpm test -- --run`): the Overview tests now render `JobsNavLink` → its `useJobsCount` query hits the default `/jobs` MSW handler; `tests/routes/Overview.test.tsx` already wraps in `MemoryRouter` (verified at lines 3–17) so no wrapper change is needed. Expected: green except the 4 carry-forward failures.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/routes/Jobs.tsx \
        apps/web/src/routes/JobDetail.tsx \
        apps/web/src/components/nav/JobsNavLink.tsx \
        apps/web/src/App.tsx \
        apps/web/src/routes/Overview.tsx \
        apps/web/tests/routes/Jobs.test.tsx \
        apps/web/tests/routes/JobDetail.test.tsx \
        apps/web/tests/components/nav/JobsNavLink.test.tsx
git commit -m "feat(p2-9): /jobs + /jobs/:id routes + JobsNavLink

Jobs.tsx: status filter chips (Active/Completed/All) + paginated JobRow
list; filter + page live in the URL search params. JobDetail.tsx:
JobHeader + per-site JobItemsGroup (first 3 expanded) wired to the
cancel/retry mutations; adaptive 5s polling via useJobDetail.

PLAN-CORRECTION (trap #26): no sidebar exists in this SPA — JobsNavLink
(with active-count badge, hidden at 0 per guardrail #22) renders in the
Overview header next to the h1; Jobs links back to Overview and
JobDetail back to Jobs.

App.tsx gains both routes inside the RequireAuth outlet.

9 tests (list render + filter switch + pagination; detail header +
grouping + action-button states + back link; badge shown/hidden).

Per spec § 3.1–§ 3.4."
```

---

## Task 18 — Navigate-on-success in `BulkUpdatePluginsButton` + `BulkUpdateThemesButton`

**Files:**
- Modify: `apps/web/src/components/overview/BulkUpdatePluginsButton.tsx`
- Modify: `apps/web/src/components/overview/BulkUpdateThemesButton.tsx`
- Modify: `apps/web/tests/components/overview/BulkUpdatePluginsButton.test.tsx` (MemoryRouter wrapper + 1 new test)
- Modify: `apps/web/tests/components/overview/BulkUpdateThemesButton.test.tsx` (MemoryRouter wrapper + 1 new test)

- [ ] **Step 1: Write the failing tests (and fix the wrappers — trap #31)**

Modify `apps/web/tests/components/overview/BulkUpdateThemesButton.test.tsx` — replace the ENTIRE file (existing 2 tests preserved, wrapper gains MemoryRouter + a probe route, 1 new test):

```tsx
import { describe, it, expect } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import { server } from '@/test/setup';
import { BulkUpdateThemesButton } from '@/components/overview/BulkUpdateThemesButton';

function renderBtn(pendingCount: number) {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/overview']}>
        <Routes>
          <Route path="/overview" element={<BulkUpdateThemesButton pendingCount={pendingCount} />} />
          <Route path="/jobs/:id" element={<div>JOB DETAIL PROBE</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('BulkUpdateThemesButton', () => {
  it('hiddenWhenPendingCountIsZero', () => {
    renderBtn(0);
    expect(
      screen.queryByRole('button', { name: /bulk update/i }),
    ).not.toBeInTheDocument();
  });

  it('visibleWithCountWhenPendingCountGreaterThanZero', () => {
    renderBtn(12);
    expect(
      screen.getByRole('button', { name: /bulk update themes.*12/i }),
    ).toBeInTheDocument();
  });

  it('navigatesToJobDetailOnSuccess', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/overview/pending-theme-updates', () =>
        HttpResponse.json({
          pending_updates: [
            { site_id: 1, site_label: 'SmartCoding', slug: 'astra', theme_name: 'Astra', current_version: '4.6.3', target_version: '4.7.0' },
          ],
          generated_at: '2026-06-09 23:00:00',
        }),
      ),
      http.post('*/wp-json/defyn/v1/overview/bulk-update-themes', () =>
        HttpResponse.json(
          {
            job_id: 42,
            scheduled_count: 1,
            skipped_count: 0,
            scheduled_pairs: [{ site_id: 1, slug: 'astra' }],
            skipped_pairs: [],
            scheduled_at: '2026-06-09 23:15:42',
          },
          { status: 202 },
        ),
      ),
    );

    renderBtn(1);
    fireEvent.click(screen.getByRole('button', { name: /bulk update themes.*1/i }));

    await waitFor(() =>
      expect(screen.getByText(/bulk update 1 themes across 1 sites\?/i)).toBeInTheDocument(),
    );
    fireEvent.click(screen.getByRole('button', { name: /bulk update 1 themes/i }));

    // Guardrail #11 — mutation onSuccess navigates to /jobs/{job_id}.
    await waitFor(() => {
      expect(screen.getByText('JOB DETAIL PROBE')).toBeInTheDocument();
    });
  });
});
```

Modify `apps/web/tests/components/overview/BulkUpdatePluginsButton.test.tsx` — same surgery:

1. Add the imports `MemoryRouter, Routes, Route` from `react-router-dom`.
2. Replace the `renderBtn` body so the component renders at `/overview` inside `MemoryRouter` with a `/jobs/:id` probe route (identical shape to the themes file above, substituting `<BulkUpdatePluginsButton pendingCount={pendingCount} />`).
3. Keep the existing 4 tests unchanged (`rendersIdleStateWithDynamicCount`, `hiddenWhenPendingCountZero`, `opensConfirmDialogOnClick`, `showsPendingLabelWhilePostInFlight` — the last one's inline fixture already gained `job_id: 42` in Task 12).
4. Append the new test:

```tsx
  it('navigatesToJobDetailOnSuccess', async () => {
    server.use(
      http.get('*/wp-json/defyn/v1/overview/pending-plugin-updates', () =>
        HttpResponse.json({
          pending_updates: [
            { site_id: 1, site_label: 'SmartCoding', slug: 'akismet', plugin_name: 'Akismet', current_version: '5.3', target_version: '5.3.1' },
          ],
          generated_at: '2026-06-09 23:00:00',
        }),
      ),
      http.post('*/wp-json/defyn/v1/overview/bulk-update-plugins', () =>
        HttpResponse.json(
          {
            job_id: 42,
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

    renderBtn(1);
    fireEvent.click(screen.getByRole('button', { name: /bulk update plugins.*1/i }));

    await waitFor(() =>
      expect(screen.getByText(/bulk update 1 plugins across 1 sites\?/i)).toBeInTheDocument(),
    );
    fireEvent.click(screen.getByRole('button', { name: /bulk update 1 plugins/i }));

    await waitFor(() => {
      expect(screen.getByText('JOB DETAIL PROBE')).toBeInTheDocument();
    });
  });
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd apps/web && pnpm test -- --run BulkUpdatePluginsButton BulkUpdateThemesButton`

Expected: the 2 new `navigatesToJobDetailOnSuccess` tests FAIL (no navigation happens); the pre-existing tests still pass under the new wrappers.

- [ ] **Step 3: Add the navigate**

Modify `apps/web/src/components/overview/BulkUpdateThemesButton.tsx`. Old:

```tsx
import { useState } from 'react';
import { Settings } from 'lucide-react';
```

New:

```tsx
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Settings } from 'lucide-react';
```

Old:

```tsx
export function BulkUpdateThemesButton({ pendingCount }: BulkUpdateThemesButtonProps) {
  const [dialogOpen, setDialogOpen] = useState(false);
  const pending = usePendingThemeUpdates(dialogOpen);
  const mutation = useBulkUpdateThemes();

  if (pendingCount === 0) {
    return null;
  }

  const handleConfirm = (selectedPairs: Array<{ site_id: number; slug: string }>) => {
    setDialogOpen(false);
    if (selectedPairs.length > 0) {
      mutation.mutate({ updates: selectedPairs });
    }
  };
```

New:

```tsx
export function BulkUpdateThemesButton({ pendingCount }: BulkUpdateThemesButtonProps) {
  const [dialogOpen, setDialogOpen] = useState(false);
  const navigate = useNavigate();
  const pending = usePendingThemeUpdates(dialogOpen);
  const mutation = useBulkUpdateThemes();

  if (pendingCount === 0) {
    return null;
  }

  const handleConfirm = (selectedPairs: Array<{ site_id: number; slug: string }>) => {
    setDialogOpen(false);
    if (selectedPairs.length > 0) {
      mutation.mutate(
        { updates: selectedPairs },
        {
          onSuccess: (data) => {
            // Guardrail #11 — jump straight to the tracked job. job_id is
            // null on the all-skipped 200 path: stay on /overview.
            if (data.job_id !== null) {
              navigate(`/jobs/${data.job_id}`);
            }
          },
        },
      );
    }
  };
```

Modify `apps/web/src/components/overview/BulkUpdatePluginsButton.tsx` — the identical two edits (import `useNavigate` after the `useState` import line; add `const navigate = useNavigate();` after the `useState` call; wrap `mutation.mutate({ updates: selectedPairs })` with the same `onSuccess` navigate callback). The current old block is:

```tsx
  const handleConfirm = (selectedPairs: Array<{ site_id: number; slug: string }>) => {
    setDialogOpen(false);
    if (selectedPairs.length > 0) {
      mutation.mutate({ updates: selectedPairs });
    }
  };
```

and becomes:

```tsx
  const handleConfirm = (selectedPairs: Array<{ site_id: number; slug: string }>) => {
    setDialogOpen(false);
    if (selectedPairs.length > 0) {
      mutation.mutate(
        { updates: selectedPairs },
        {
          onSuccess: (data) => {
            // Guardrail #11 — jump straight to the tracked job. job_id is
            // null on the all-skipped 200 path: stay on /overview.
            if (data.job_id !== null) {
              navigate(`/jobs/${data.job_id}`);
            }
          },
        },
      );
    }
  };
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd apps/web && pnpm test -- --run BulkUpdatePluginsButton BulkUpdateThemesButton`

Expected: 8 PASS (5 plugins + 3 themes).

Full suite: `cd apps/web && pnpm test -- --run` — green except the 4 carry-forward failures.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/components/overview/BulkUpdatePluginsButton.tsx \
        apps/web/src/components/overview/BulkUpdateThemesButton.tsx \
        apps/web/tests/components/overview/BulkUpdatePluginsButton.test.tsx \
        apps/web/tests/components/overview/BulkUpdateThemesButton.test.tsx
git commit -m "feat(p2-9): bulk buttons navigate to /jobs/{job_id} on success

Both BulkUpdate*Button handleConfirm callbacks gain an onSuccess
navigate to /jobs/{data.job_id} (guardrail #11). job_id null
(all-skipped 200) stays on /overview — no job was created (guardrail
#12). Dialog components untouched — behavior lives in the BUTTON (spec
§ 3.10).

useNavigate requires Router context (trap #31): both test files now
wrap in MemoryRouter with a /jobs/:id probe route; existing tests
preserved; 1 new navigatesToJobDetailOnSuccess test per button.

Per spec § 3.9."
```

---

# Phase D — Ship

## Task 19 — Build zip v0.9.0 + push + Kinsta install + 10-step smoke matrix

**Files:**
- Build artifact: `dist/defyn-dashboard-0.9.0.zip` (NEW)
- No connector zip (connector unchanged at v0.1.7)

- [ ] **Step 1: Build the dashboard zip (CORRECT exclusion list — trap #17)**

```bash
cd packages/dashboard-plugin
composer install --no-dev --classmap-authoritative
cd ../..

mkdir -p dist
cd packages
zip -r ../dist/defyn-dashboard-0.9.0.zip dashboard-plugin \
  -x 'dashboard-plugin/tests/*' \
  -x 'dashboard-plugin/*wp-tests-config.php' \
  -x 'dashboard-plugin/.phpunit.result.cache' \
  -x 'dashboard-plugin/test-output.log' \
  -x 'dashboard-plugin/phpunit.xml' \
  -x 'dashboard-plugin/composer.lock' \
  -x 'dashboard-plugin/.github/*' \
  -x 'dashboard-plugin/.gitignore'
cd ..

ls -lah dist/defyn-dashboard-0.9.0.zip
```

Expected: ~565KB. **NEVER add `vendor/*` subdirectory exclusions** — after `--no-dev` install, EVERYTHING left in vendor/ is required by prod autoload (the buggy P2.8 list that stripped `vendor/symfony/*` produced a fatal on activation).

Verify the two autoload-required symfony files survived:

```bash
unzip -l dist/defyn-dashboard-0.9.0.zip | grep -E "deprecation-contracts/function\.php|polyfill-php83/bootstrap\.php"
```

Expected: exactly 2 lines. If 0 lines — STOP, the zip is broken; rebuild without vendor exclusions.

Restore dev autoload so local tests still run:

```bash
cd packages/dashboard-plugin && composer install && cd ../..
```

- [ ] **Step 2: Push branch + main**

```bash
git push origin p2-9-bulk-jobs-entity
git checkout main
git merge --ff-only p2-9-bulk-jobs-entity
git push origin main
git checkout p2-9-bulk-jobs-entity
```

Cloudflare Pages auto-deploys the SPA from main (~60–90s).

- [ ] **Step 3: Install on Kinsta + cache clear (trap #18)**

1. WP Admin (`defynwp.defyn.agency/wp-admin`) → Plugins → Add New → Upload Plugin → `dist/defyn-dashboard-0.9.0.zip` → Install Now → **"Replace current with uploaded version"**.
2. MyKinsta → Tools → **Clear cache** (busts OPcache + page cache + Redis — every P2.x carry-forward).
3. Confirm Plugins page shows "Defyn Dashboard" Version 0.9.0.
4. Load any wp-admin page once so `plugins_loaded` self-heal runs the v6→v7 migration, then verify both tables exist (MyKinsta → phpMyAdmin or SSH): `SHOW TABLES LIKE 'wp_defyn_bulk%';` → 2 rows.

- [ ] **Step 4: Verify Cloudflare Pages deploy**

```bash
until [ "$(curl -s -o /dev/null -w '%{http_code}' https://app.defynwp.defyn.agency/overview)" = "200" ]; do sleep 5; done
echo "SPA up"
NEW_JS=$(curl -s "https://app.defynwp.defyn.agency/?cb=$(uuidgen)" | grep -oE 'index-[A-Za-z0-9_-]+\.js' | head -1)
echo "Latest JS: $NEW_JS"
curl -s --compressed "https://app.defynwp.defyn.agency/assets/$NEW_JS" | grep -oE "Back to Jobs|Retry all|jobsCount|retry-failed" | sort -u
```

Expected: all 4 literal strings present.

- [ ] **Step 5: Run the 10-step smoke matrix (spec § 4 verbatim)**

```bash
export DEFYN_TOKEN="<paste-from-prior-session-OR-login-via-curl>"
BASE="https://defynwp.defyn.agency/wp-json/defyn/v1"
```

| # | Step | Command / action | Expected |
|---|---|---|---|
| 1 | Schema v7 live | `SHOW TABLES LIKE 'wp_defyn_bulk%';` (step 3.4 above) | Both tables exist |
| 2 | Bulk POST returns job_id | `curl -sw "\nHTTP=%{http_code}\n" -H "Authorization: Bearer $DEFYN_TOKEN" -H "Content-Type: application/json" -d '{"updates":[{"site_id":1,"slug":"akismet"}]}' "$BASE/overview/bulk-update-plugins"` | 202 + non-null `job_id` (zero-sites carry-forward may force the all-skipped 200 + `"job_id":null` — that itself verifies guardrail #12; fall back to test coverage for the 202 path) |
| 3 | GET /jobs lists it | `curl -s -H "Authorization: Bearer $DEFYN_TOKEN" "$BASE/jobs?_=$(uuidgen)"` | 200 + `jobs[]` includes the new job (cache-bust param per P2.5 Kinsta-edge carry-forward) |
| 4 | GET /jobs/{id} items queued | `curl -s -H "Authorization: Bearer $DEFYN_TOKEN" "$BASE/jobs/<id>?_=$(uuidgen)"` | 200 + items all `queued` initially; re-poll to watch AS workers flip them |
| 5 | Cancel unschedules | `curl -s -X POST -H "Authorization: Bearer $DEFYN_TOKEN" "$BASE/jobs/<id>/cancel"` | 200 + `cancelled_count > 0`; verify via `wp action-scheduler list --hook=defyn_update_site_plugin --status=pending` (fewer rows) |
| 6 | Per-item retry | `curl -s -X POST -H "Authorization: Bearer $DEFYN_TOKEN" "$BASE/jobs/<id>/items/<item_id>/retry"` on a failed item | 202 + item back to `queued` + new AS row (produce a failed item via a deliberately broken upgrade, or fall back to `JobsRetryControllersTest` coverage) |
| 7 | Retry-failed bulk | `curl -s -X POST -H "Authorization: Bearer $DEFYN_TOKEN" "$BASE/jobs/<id>/retry-failed"` | 202 + `retried_count > 0` (same prerequisite as #6) |
| 8 | SPA /jobs + nav badge | Visit `app.defynwp.defyn.agency/overview` → Jobs link | Renders; badge shows active count (visual smoke foreclosed by UI-password-entry prohibition → bundle-string grep from Step 4 is the fallback) |
| 9 | SPA /jobs/:id detail | Click a job row | Header + per-site collapsibles + state chips (same fallback) |
| 10 | Confirm → navigate | Bulk dialog confirm | URL flips to `/jobs/{id}` automatically (same fallback + `navigatesToJobDetailOnSuccess` tests as proof) |

Also verify the error envelopes hold live: `curl -sw "\nHTTP=%{http_code}\n" "$BASE/jobs"` (no auth) → 401; `curl -s -H "Authorization: Bearer $DEFYN_TOKEN" "$BASE/jobs/999999?_=$(uuidgen)"` → 404 `jobs.not_found`.

**Carry-forwards (spec § 4):** prod `wp_defyn_sites` may still be empty for user 1 → happy 202 paths foreclosed, fall back to the test suite as proof. Kinsta Redis stale transients may skew rate-limit counts — assert the 429 + `jobs.rate_limited` envelope fires, not the exact call number. Use `?_=$(uuidgen)` cache-busting on every GET.

- [ ] **Step 6: Commit smoke notes (optional)**

```bash
git add docs/superpowers/smoke/p2-9-smoke-notes.md
git commit -m "docs(p2-9): production smoke notes for v0.9.0 release"
```

(Skip if no notes file was created.)

---

## Task 20 — Tag `p2-9-bulk-jobs-entity-complete` + MEMORY

**Files:**
- Git tag: `p2-9-bulk-jobs-entity-complete`
- MEMORY: `~/.claude/projects/-Users-pradeep-Local-Sites-defynWP/memory/MEMORY.md`

- [ ] **Step 1: Create + push the tag**

```bash
git -C "/Users/pradeep/Local Sites/defynWP" tag -a p2-9-bulk-jobs-entity-complete -m "P2.9 — Bulk-jobs entity (cancel/retry/history)

Dashboard v0.9.0 live in prod. Connector v0.1.7 unchanged. Schema v7
(wp_defyn_bulk_jobs + wp_defyn_bulk_job_items, additive, self-heal
migrated).

Every bulk-update POST now creates a tracked job + per-pair items;
AS actions carry the item id as a 4th arg and mark lifecycle at every
terminal branch (409-as-success counts as succeeded; retries propagate
the id without terminal marks).

5 new endpoints: GET /jobs (30/MIN), GET /jobs/{id} (30/MIN),
POST /jobs/{id}/cancel (5/HR, exact 4-tuple as_unschedule),
POST /jobs/{id}/items/{item_id}/retry (20/HR),
POST /jobs/{id}/retry-failed (5/HR).

SPA: /jobs + /jobs/:id routes, JobsNavLink badge in the Overview
header (plan-correction — no sidebar exists), JobStateChip single
color source, adaptive polling (10s list / 5s detail / 30s count),
cancel + retry mutations, navigate-to-job on bulk confirm.

PHP: ~70 new tests. SPA: ~45 new tests."

git -C "/Users/pradeep/Local Sites/defynWP" push origin p2-9-bulk-jobs-entity-complete
```

- [ ] **Step 2: Update MEMORY index**

Edit `~/.claude/projects/-Users-pradeep-Local-Sites-defynWP/memory/MEMORY.md` — find the trailing `Next: P2.9 (bulk-jobs entity for cancel/resume/history) → P2.10 (filtered drill-in /overview/plugins + /overview/themes routes).` sentence at the end of the project-overview entry and replace it with:

```
**P2.9 (Bulk-jobs entity) COMPLETE 2026-06-XX** — tag `p2-9-bulk-jobs-entity-complete`, dashboard v0.9.0 live in prod (connector unchanged at v0.1.7). Schema v6→v7: `wp_defyn_bulk_jobs` (parent) + `wp_defyn_bulk_job_items` (child, 5-state machine queued/started/succeeded/failed/cancelled) — additive, self-heal migrated. Both bulk POST endpoints now create the job + items BEFORE the AS fan-out and schedule with 4 args `[siteId, slug, 0, jobItemId]`; response gains `job_id` (null when all-skipped). `UpdateSitePlugin`/`UpdateSiteTheme::handle` gained `int $jobItemId = 0` with marks at EVERY terminal branch (theme 409 no_update_available counts as succeeded; 409-busy retries propagate the id, no terminal mark; site/row-missing early returns mark failed). `Plugin::boot` AS registrations bumped 3→4 args. 5 new endpoints: GET `/jobs` 30/MIN + GET `/jobs/{id}` 30/MIN (LEFT-JOIN resource resolution w/ slug + 'Site #N' fallbacks) + POST `/jobs/{id}/cancel` 5/HR (exact-4-tuple as_unschedule_action, started items not cancellable, always 200) + POST `/jobs/{id}/items/{item_id}/retry` 20/HR + POST `/jobs/{id}/retry-failed` 5/HR. 404 `jobs.not_found` for foreign jobs (no existence leak); `jobs.item_not_retryable` 400 unless failed. `BulkJobsRepository::createItems` = 1 multi-row INSERT + 1 read-back (num_queries-asserted); `refreshJobTimestamps` automatic on every mark (retry CLEARS completed_at); `countsByStateForJobs` is ONE grouped query for the list. `BulkJobAggregator` pure-function (+deriveJobStateFromCounts addition). SPA: `/jobs` + `/jobs/:id` routes, `JobsNavLink` badge in Overview header (**plan-correction: spec assumed a sidebar; SPA has none**), `JobStateChip` single color source (9 states), adaptive polling via exported pure helpers `jobsListPollInterval` (10s) / `jobDetailPollInterval` (5s) + `useJobsCount` 30s derived from `/jobs?status=active&per_page=1`; mutations invalidate `['job',id]`+`['jobs']`+`['jobsCount']` NOT `['sites']`; bulk buttons navigate to `/jobs/{job_id}` on success. **Plan-corrections caught during plan-writing:** spec's `useJobsList` path `/overview/jobs` → actual `/jobs`; spec's v4-style `refetchInterval:(data)` → v5 `(query)`; spec's `idx_user_created (user_id, created_at DESC)` → plain index (dbDelta); required `job_id` Zod field rippled into 5 MSW fixtures; MemoryRouter wrappers required for useNavigate in button tests. Next: P2.10 (filtered drill-in `/overview/plugins` + `/overview/themes` routes) → P2.9.1 (auto-prune retention sweep, deferred).
```

(Replace `2026-06-XX` with the actual completion date.)

- [ ] **Step 3: Final verification**

```bash
git -C "/Users/pradeep/Local Sites/defynWP" tag --list "p2-9*"
git -C "/Users/pradeep/Local Sites/defynWP" log --oneline -5
```

Expected: tag exists; branch + main at tip with all P2.9 commits.

---

## Self-review summary

**Spec coverage:**
- § 1 architecture (tables, state machine, controller integration, AS extensions, cancel/retry semantics) → Tasks 1 (tables), 2 (aggregator), 3–4 (repository), 9 (controller integration), 10 (AS jobs + Plugin.php).
- § 2.1–2.5 REST contracts → Tasks 5 (list), 6 (detail + resolution JOIN), 7 (cancel), 8 (retry ×2); all rate-limit buckets, error codes (`jobs.not_found` / `jobs.item_not_found` / `jobs.item_not_retryable` / `jobs.rate_limited`) and status-code rules (200 cancel, 202 retry, 200 no-op) encoded in code + tests.
- § 2.6 modified envelopes (`job_id`) → Task 9 (backend) + Task 12 (Zod + MSW ripple).
- § 2.7 file structure → File structure overview tables; every spec file present (sidebar path plan-corrected to `components/nav/`).
- § 2.8 repository contract → Tasks 3/4/6 implement every method; documented additions: `findItemForJob`, `resetItemForRetry` (implied by § 2.4), `countsByStateForJobs` (N+1 avoidance), `findItemsForJobWithResources` (§ 2.2 resolution).
- § 2.9 aggregator → Task 2 (+`deriveJobStateFromCounts` addition, documented).
- § 2.10 test inventory → distributed across Tasks 1–11 (≈70 PHP tests vs spec's ~30+16+8 estimate — superset).
- § 2.11 version bump + changelog → Task 11 (changelog verbatim).
- § 3.1–3.4 SPA routes/nav/list/detail → Task 17 (+ trap #26 sidebar plan-correction).
- § 3.5 SPA files → Tasks 12–18 cover every row of the spec table.
- § 3.6 hook contracts → Task 13 (corrected paths + v5 signature, traps #27/#28).
- § 3.7 chip colors → Task 15 (single source, guardrail #20).
- § 3.8 button behavior → Task 16 (enable rules, neutral dialogs, copy).
- § 3.9 navigate-on-success → Task 18.
- § 3.10 SPA test inventory → Tasks 13–18 (≈45 tests; JobStateChip ×9 per spec).
- § 4 smoke matrix → Task 19 step 5 (10 steps + carry-forwards verbatim).
- § 5 out-of-scope → untouched (no retention sweep, no cooperative cancel, no per-item activity events, P2.6 not wrapped).
- § 6 guardrails 1–22 → traps 1–22 verbatim + referenced at point-of-use in every task.

**Placeholder scan:** no TBD, no TODO, no "implement later", no "similar to Task N" (cross-file mirrors always spell out the full code or the exact old/new edit strings). Every command has an expected outcome.

**Type consistency:** `createItems` returns `list<{site_id, slug, item_id}>` — consumed exactly that way in Task 9's fan-out (`$pair['item_id']`) and Task 3's tests. `findQueuedItemsForJob` returns `{item_id, site_id, slug}` — consumed by Task 7's cancel 4-tuple. `presentJob(array $row, array $counts)` defined in Task 5, reused in Task 6. `jobsListResponseSchema`/`jobDetailResponseSchema` field lists match the PHP response arrays field-for-field (incl. nullable `started_at`/`completed_at`/`current_version`/`target_version`/`error_message`). `JobsStatusFilter` type exported from `useJobsList` and imported by `Jobs.tsx`. Mutation variable shapes (`number` vs `{jobId, itemId}`) consistent between hooks (Task 14) and callers (Task 17).

**Reality-check consistency:** static `RateLimit` methods returning `true|WP_Error` chaining `RequireAuth::check` (matches `bulkThemeUpdate` verbatim shape); `[RateLimit::class, 'method']` permission callbacks; `TokenService(DEFYN_JWT_SECRET)->issueAccess()` test auth; `seedSite` includes `status`; fixtures seed every NOT NULL column from the real migrations; apiClient paths have NO `/defyn/v1` prefix; TanStack v5 `refetchInterval(query)`; `PRIMARY KEY  (id)` double-space; no `destructive` Button variant used anywhere; `ErrorResponse::create` for all controller errors; MSW `*/wp-json/defyn/v1/...` URL patterns.

**Plan-corrections vs spec (all surfaced as traps + in MEMORY text):** no sidebar (trap #26), `/overview/jobs` path bug (trap #27), v4 polling signature (trap #28), DESC index (trap #29), `job_id` fixture ripple (trap #30), MemoryRouter wrappers (trap #31), count endpoint derived from list (trap #32).

---

**End of plan.** Estimated effort: 20 atomic commits across 4 phases (Schema+domain / REST+integration / SPA / Ship). The riskiest cross-feature surfaces are Task 9 (restructures two live controllers — existing P2.7/P2.8 tests pin the unchanged behavior) and Task 10 (touches both AS job handlers — `$jobItemId = 0` default keeps every existing call path byte-identical).
