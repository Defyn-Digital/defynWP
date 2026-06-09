# P2.9 — Bulk-jobs entity (cancel/resume/history) (Design Spec)

**Date:** 2026-06-09
**Status:** Approved (brainstorming complete §1→§4 + §7)
**Predecessor:** P2.8 — Bulk theme updates across fleet, tag `p2-8-bulk-theme-updates-complete` (commit `7ab9d38`). Dashboard v0.8.1 live in prod.
**Successor candidates:** P2.10 (filtered drill-in `/overview/plugins` + `/overview/themes` routes), P2.9.1 (auto-prune retention sweep)
**Spec scope:** Introduce a new `BulkJob` domain entity wrapping the existing P2.7 + P2.8 destructive bulk operations. Each bulk request becomes a parent row in `wp_defyn_bulk_jobs` plus N child rows in `wp_defyn_bulk_job_items` (one per scheduled pair). Operator gets per-pair lifecycle visibility via a new `/jobs` route + per-row cancel-queued + retry-failed actions. Schema v6 → v7. Dashboard v0.8.1 → v0.9.0 (minor bump for new domain entity). Connector v0.1.7 unchanged.

---

## §1. Architecture overview

**Goal:** today P2.6/P2.7/P2.8 bulk endpoints fire AS jobs + emit a fleet-scoped activity event + forget. Operators can't see per-pair progress, can't cancel a 50-site bulk launched by mistake, can't retry the 3 sites that failed without re-clicking the original button + re-checking which 3 to skip. P2.9 fixes this by recording every destructive bulk operation as a tracked job with per-item rows.

**Scope:** wraps **P2.7 bulk-plugin-updates + P2.8 bulk-theme-updates only**. P2.6 sync-all stays fire-and-forget (refresh is idempotent + cheap; no cancel/retry value). Future bulks (settings, P2.10 mass-actions) can opt into the BulkJob entity with a new `kind` enum value.

**Schema v6 → v7 — two new tables:**

1. **`wp_defyn_bulk_jobs`** (parent — one row per bulk request):
   - `id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
   - `user_id BIGINT UNSIGNED NOT NULL` (operator who created the job; FK semantics, no DB constraint per WP convention)
   - `kind VARCHAR(20) NOT NULL` (`'plugin_update'` | `'theme_update'`)
   - `scheduled_count INT UNSIGNED NOT NULL DEFAULT 0` (total items at creation)
   - `skipped_count INT UNSIGNED NOT NULL DEFAULT 0` (validation-skipped pairs, not in items table)
   - `started_at DATETIME NULL` (set when first item transitions to `started`)
   - `completed_at DATETIME NULL` (set when last item reaches a terminal state)
   - `created_at DATETIME NOT NULL`
   - INDEX `idx_user_created (user_id, created_at DESC)` — supports `findAllForUser` list query.

2. **`wp_defyn_bulk_job_items`** (child — one row per scheduled `(site_id, resource_slug)` pair):
   - `id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`
   - `job_id BIGINT UNSIGNED NOT NULL` (FK semantics; relied on by `ON DELETE CASCADE` if/when retention sweep added)
   - `site_id BIGINT UNSIGNED NOT NULL`
   - `resource_slug VARCHAR(80) NOT NULL` (works for both plugins + themes since both use `slug`)
   - `state VARCHAR(20) NOT NULL DEFAULT 'queued'` (`'queued'` | `'started'` | `'succeeded'` | `'failed'` | `'cancelled'`)
   - `error_message VARCHAR(1000) NULL` (populated on `failed`)
   - `started_at DATETIME NULL`
   - `completed_at DATETIME NULL`
   - `created_at DATETIME NOT NULL`
   - INDEX `idx_job_state (job_id, state)` — supports state-grouped queries for the detail view.
   - INDEX `idx_state_completed (state, completed_at)` — supports future retention sweep.

**Item state machine:**

```
   ┌────────┐
   │ queued │ ── operator cancels ──> cancelled (terminal)
   └────┬───┘
        │ AS worker dequeues
        ▼
   ┌─────────┐
   │ started │
   └────┬────┘
        │
        ├── upgrade succeeds ──> succeeded (terminal)
        └── upgrade fails ────> failed (terminal)
                                 │
                                 └── operator retries ──> queued (loop)
```

`succeeded`, `cancelled` are terminal-permanent. `failed` is terminal-retryable.

**Job-level derived state** (computed server-side by `BulkJobAggregator`):

- `queued` — all items in `queued` (operator hasn't seen AS workers pick up anything yet).
- `in_progress` — at least one item in `started`, OR at least one terminal alongside any non-terminal.
- `completed` — all items in `succeeded` (clean win).
- `partial` — all items terminal but at least one in `failed` or `cancelled`.

**Integration with P2.7/P2.8 controllers:**

`OverviewBulkUpdatePluginsController::handle` + `OverviewBulkUpdateThemesController::handle` extended:

1. After validation but BEFORE the AS-scheduling loop, INSERT a parent row in `wp_defyn_bulk_jobs` (`user_id`, `kind`, `scheduled_count=count($scheduled)`, `skipped_count=count($skipped)`, `created_at=$now`). Capture the new `$jobId`.
2. INSERT N child rows in `wp_defyn_bulk_job_items` (one per scheduled pair, `state='queued'`). Capture the slug-to-itemId map.
3. Schedule each AS job with 4 args: `[$siteId, $slug, 0, $jobItemId]` (the 4th arg is NEW — the item ID for lifecycle marking).
4. Response envelope extended: existing `scheduled_count`/`skipped_count`/`scheduled_pairs`/`skipped_pairs`/`scheduled_at` fields stay (backwards-compat) PLUS new `job_id` field (the parent row ID).
5. Existing fleet-scoped activity events (`overview.bulk_plugin_update_requested`, `overview.bulk_theme_update_requested`) KEEP firing alongside — they're independent audit-log entries.

**AS job extensions:**

`UpdateSitePlugin::handle(int $siteId, string $slug, int $attempt = 0, int $jobItemId = 0): void` + `UpdateSiteTheme::handle` — added optional 4th parameter `$jobItemId`. When `$jobItemId > 0`:
- At handle entry: call `BulkJobsRepository::markItemStarted($jobItemId, $now)` → state `queued → started` + sets `started_at`.
- On success: `markItemSucceeded($jobItemId, $now)` → state `started → succeeded` + sets `completed_at`.
- On failure: `markItemFailed($jobItemId, $now, $errorMessage)` → state `started → failed` + sets `completed_at` + populates `error_message`.

`Plugin.php` updates: change the `add_action(UpdateSitePlugin::HOOK, ...)` registration to accept 4 args (currently 3). Backwards-compat — older AS-scheduled jobs (from P2.2/P2.3 per-site update endpoints OR pre-v0.9.0 in-flight AS rows) that lack the 4th arg get `$jobItemId = 0` via the default.

**Per-site (P2.2/P2.3) update endpoints unchanged.** They continue scheduling AS jobs with 3 args. `$jobItemId = 0` means "no bulk-job tracking" — the lifecycle marks are skipped. Same for any AS rows queued before v0.9.0 deployed.

**Cancel:** `POST /defyn/v1/jobs/{id}/cancel`. Finds all items in `state='queued'`, calls `as_unschedule_action('defyn_update_site_plugin', [$siteId, $slug, 0, $jobItemId])` (or theme equivalent) for each, marks them `cancelled`. Items in `started` state are NOT cancellable (AS workers can't be interrupted mid-upgrade safely). UI shows them as in-flight; operator must wait.

**Retry per-item:** `POST /defyn/v1/jobs/{id}/items/{item_id}/retry`. Only allowed when item state is `failed`. Re-schedules the AS job with the same args, resets item to `queued`, clears `error_message`, nulls `started_at` + `completed_at`.

**Retry-failed bulk:** `POST /defyn/v1/jobs/{id}/retry-failed`. Same as per-item retry but for ALL failed items in the job. One request, N AS-schedules.

**Connector:** unchanged at **v0.1.7**.

**Dashboard:** **v0.8.1 → v0.9.0** (minor bump — new domain entity is a meaningful surface change, not a patch).

---

## §2. Dashboard REST contract

### 2.1 GET `/defyn/v1/jobs`

| Field | Value |
|---|---|
| Auth | Bearer JWT |
| Query params | `?page=1&per_page=20&status=active\|completed\|all` (defaults: page=1, per_page=20, status=all) |
| Rate limit | `RateLimit::jobsList` — **30/MINUTE per user** |
| Cache headers | `Cache-Control: no-store` |

**Status filter semantics:**
- `active` — job-level `state` IN (`queued`, `in_progress`).
- `completed` — job-level `state` IN (`completed`, `partial`).
- `all` — no filter.

**Response (200):**

```json
{
  "jobs": [
    {
      "id": 42,
      "kind": "plugin_update",
      "scheduled_count": 12,
      "skipped_count": 0,
      "succeeded_count": 9,
      "failed_count": 2,
      "cancelled_count": 1,
      "queued_count": 0,
      "started_count": 0,
      "state": "partial",
      "started_at": "2026-06-09 21:00:00",
      "completed_at": "2026-06-09 21:08:42",
      "created_at": "2026-06-09 20:59:15"
    }
  ],
  "total": 47,
  "page": 1,
  "per_page": 20,
  "generated_at": "2026-06-09 21:30:00"
}
```

### 2.2 GET `/defyn/v1/jobs/{id}`

| Field | Value |
|---|---|
| Auth | Bearer JWT |
| Rate limit | `RateLimit::jobsDetail` — **30/MINUTE per user** |
| Cache headers | `Cache-Control: no-store` |

**Response (200):**

```json
{
  "job": {
    "id": 42,
    "kind": "plugin_update",
    "scheduled_count": 12,
    "skipped_count": 0,
    "succeeded_count": 9,
    "failed_count": 2,
    "cancelled_count": 1,
    "queued_count": 0,
    "started_count": 0,
    "state": "partial",
    "started_at": "2026-06-09 21:00:00",
    "completed_at": "2026-06-09 21:08:42",
    "created_at": "2026-06-09 20:59:15"
  },
  "items": [
    {
      "id": 201,
      "site_id": 1,
      "site_label": "SmartCoding",
      "resource_slug": "akismet",
      "resource_name": "Akismet Anti-Spam",
      "current_version": "5.3",
      "target_version": "5.3.1",
      "state": "succeeded",
      "error_message": null,
      "started_at": "2026-06-09 21:00:02",
      "completed_at": "2026-06-09 21:00:11",
      "created_at": "2026-06-09 20:59:15"
    }
  ],
  "generated_at": "2026-06-09 21:30:00"
}
```

`resource_name` + `current_version` + `target_version` are resolved at response time by joining against `wp_defyn_site_plugins` (or `wp_defyn_site_themes`) on `(site_id, slug)`. If the row no longer exists (deleted by operator), `resource_name` falls back to `resource_slug` and version fields are null.

**Response (404):** `{error: {code: 'jobs.not_found', message: 'Job not found.'}}` when job doesn't exist OR is owned by another user (ownership scope returns 404, not 403, to avoid info leak).

### 2.3 POST `/defyn/v1/jobs/{id}/cancel`

| Field | Value |
|---|---|
| Auth | Bearer JWT |
| Body | empty |
| Rate limit | `RateLimit::jobsCancel` — **5/HOUR per user** |
| Cache headers | `Cache-Control: no-store` |

**Response (200):**

```json
{
  "cancelled_count": 8,
  "still_running_count": 2,
  "cancelled_at": "2026-06-09 21:30:42"
}
```

`still_running_count` is items in `started` state at the time of cancel — operator UX hint.

**Response (404):** `jobs.not_found` for foreign or missing job.

**Response (200, no-op):** `{cancelled_count: 0, still_running_count: N}` when no items are still queued. Idempotent — calling cancel on a completed job is fine.

### 2.4 POST `/defyn/v1/jobs/{id}/items/{item_id}/retry`

| Field | Value |
|---|---|
| Auth | Bearer JWT |
| Body | empty |
| Rate limit | `RateLimit::jobsRetryItem` — **20/HOUR per user** |
| Cache headers | `Cache-Control: no-store` |

**Response (202):**

```json
{
  "item_id": 203,
  "scheduled_at": "2026-06-09 21:35:00"
}
```

**Response (404):** `jobs.not_found` for foreign job, `jobs.item_not_found` for missing item.

**Response (400):** `jobs.item_not_retryable` when item state is NOT `failed`.

### 2.5 POST `/defyn/v1/jobs/{id}/retry-failed`

| Field | Value |
|---|---|
| Auth | Bearer JWT |
| Body | empty |
| Rate limit | `RateLimit::jobsRetryFailed` — **5/HOUR per user** |
| Cache headers | `Cache-Control: no-store` |

**Response (202):**

```json
{
  "retried_count": 3,
  "retried_item_ids": [201, 205, 207],
  "scheduled_at": "2026-06-09 21:40:00"
}
```

**Response (200, no-op):** `{retried_count: 0, retried_item_ids: []}` when job has no failed items.

### 2.6 Modified P2.7/P2.8 endpoint responses

**`POST /defyn/v1/overview/bulk-update-plugins`** + **`POST /defyn/v1/overview/bulk-update-themes`** — response envelope gains one new field `job_id` (parent row ID). Old shape stays:

```json
{
  "job_id": 42,
  "scheduled_count": 12,
  "skipped_count": 0,
  "scheduled_pairs": [{"site_id": 1, "slug": "akismet"}],
  "skipped_pairs": [],
  "scheduled_at": "2026-06-09 20:59:15"
}
```

`job_id` is `null` when no pairs were scheduled (all-skipped 200 response — no job row was created).

### 2.7 File structure (Dashboard)

**New files:**

| Path | Responsibility |
|---|---|
| `src/Schema/BulkJobsTable.php` | CREATE TABLE for parent |
| `src/Schema/BulkJobItemsTable.php` | CREATE TABLE for child |
| `src/Services/BulkJobsRepository.php` | CRUD + lifecycle marks |
| `src/Services/BulkJobAggregator.php` | Pure-function derived state + counts |
| `src/Rest/JobsListController.php` | GET /jobs |
| `src/Rest/JobsDetailController.php` | GET /jobs/{id} |
| `src/Rest/JobsCancelController.php` | POST /jobs/{id}/cancel |
| `src/Rest/JobsRetryItemController.php` | POST /jobs/{id}/items/{item_id}/retry |
| `src/Rest/JobsRetryFailedController.php` | POST /jobs/{id}/retry-failed |

**Modified files:**

| Path | What changes |
|---|---|
| `src/Activation.php` | Add `BulkJobsTable::class` + `BulkJobItemsTable::class` to TABLES const; bump `SCHEMA_VERSION = 7` |
| `src/Rest/Middleware/RateLimit.php` | 5 new bucket constants + 5 new static methods |
| `src/Rest/RestRouter.php` | Register 5 new routes (after existing P2.8 themes routes, before `/activity`) |
| `src/Rest/OverviewBulkUpdatePluginsController.php` | Create job + items + return `job_id` |
| `src/Rest/OverviewBulkUpdateThemesController.php` | Same for themes |
| `src/Jobs/UpdateSitePlugin.php` | Add `$jobItemId = 0` param + lifecycle marks |
| `src/Jobs/UpdateSiteTheme.php` | Same |
| `src/Plugin.php` | `add_action(UpdateSitePlugin::HOOK, ...)` accepts 4 args; same for `UpdateSiteTheme::HOOK` |
| `defyn-dashboard.php` | Version 0.8.1 → 0.9.0 |
| `readme.txt` | Stable tag + v0.9.0 changelog |
| `composer.json` | Version 0.9.0 |

### 2.8 BulkJobsRepository contract

```php
final class BulkJobsRepository
{
    public function createJob(int $userId, string $kind, int $scheduledCount, int $skippedCount, string $now): int;

    /**
     * @param list<array{site_id: int, slug: string}> $pairs
     * @return list<array{site_id: int, slug: string, item_id: int}>  // pairs enriched with item_id
     */
    public function createItems(int $jobId, array $pairs, string $now): array;

    public function findByIdForUser(int $jobId, int $userId): ?array;

    /** @return list<array<string, mixed>> */
    public function findItemsForJob(int $jobId): array;

    public function markItemStarted(int $itemId, string $now): void;
    public function markItemSucceeded(int $itemId, string $now): void;
    public function markItemFailed(int $itemId, string $now, string $errorMessage): void;
    public function markItemCancelled(int $itemId, string $now): void;

    /**
     * @return list<array<string, mixed>>
     */
    public function findAllForUser(int $userId, ?string $statusFilter, int $limit, int $offset): array;

    public function countAllForUser(int $userId, ?string $statusFilter): int;

    /**
     * Used by Cancel controller — returns the queued items' AS-args
     * so the controller can call as_unschedule_action with the exact tuple.
     *
     * @return list<array{item_id: int, site_id: int, slug: string}>
     */
    public function findQueuedItemsForJob(int $jobId): array;

    public function countItemsByStateForJob(int $jobId, string $state): int;

    /**
     * Maybe-touch job-level started_at / completed_at when an item transitions.
     * Called automatically after every markItem* — repository decides if any
     * job-level timestamp needs updating based on current item-state counts.
     */
    public function refreshJobTimestamps(int $jobId, string $now): void;
}
```

**Performance:** `createItems` MUST use a single multi-row `INSERT INTO ... VALUES (...), (...), (...)` — a 50-pair fan-out shouldn't take 50 round trips. After insert, query back the just-inserted IDs (auto-increment range) to build the slug-to-itemId map.

### 2.9 BulkJobAggregator contract

Pure-function helper — no I/O, no DB. Operates on an array of item rows.

```php
final class BulkJobAggregator
{
    /**
     * @param list<array{state: string, ...}> $items
     * @return array{queued: int, started: int, succeeded: int, failed: int, cancelled: int}
     */
    public static function countsByState(array $items): array;

    /**
     * @param list<array{state: string, ...}> $items
     * @return 'queued' | 'in_progress' | 'completed' | 'partial'
     */
    public static function deriveJobState(array $items): string;
}
```

Tested in isolation — 6 cases covering each derived state + count rollups.

### 2.10 Tests (~30 PHP)

**Schema tests:**
- `BulkJobsTableTest::testCreateSqlProducesExpectedColumns`
- `BulkJobItemsTableTest::testCreateSqlProducesExpectedColumns`
- `SchemaVersionMigrationV7Test::testSchemaVersionConstantIsSeven`
- `SchemaVersionMigrationV7Test::testActivationCreatesBulkJobsAndItemsTables`

**`BulkJobsRepositoryTest` (~12):**
- `testCreateJobReturnsInsertedId`
- `testCreateItemsInsertsAllPairs`
- `testCreateItemsReturnsPairsEnrichedWithItemIds`
- `testCreateItemsUsesSingleInsertStatement` (peek at `$wpdb->num_queries` delta to assert one query)
- `testFindByIdForUserReturnsRow`
- `testFindByIdForUserReturnsNullForForeignUser`
- `testMarkItemStartedTransitionsState`
- `testMarkItemSucceededAndFailedTransitionState`
- `testMarkItemCancelledOnlyAllowedFromQueued`
- `testFindAllForUserWithStatusFilterActive`
- `testFindAllForUserWithStatusFilterCompleted`
- `testCountAllForUserMatchesFindAllForUserTotal`
- `testRefreshJobTimestampsSetsStartedAtOnFirstItemStarted`
- `testRefreshJobTimestampsSetsCompletedAtWhenAllTerminal`

**`BulkJobAggregatorTest` (~6):**
- `testCountsByStateRollup`
- `testDeriveJobStateQueuedWhenAllQueued`
- `testDeriveJobStateInProgressWhenAnyStarted`
- `testDeriveJobStateInProgressWhenMixedTerminalAndNonTerminal`
- `testDeriveJobStateCompletedWhenAllSucceeded`
- `testDeriveJobStatePartialWhenAllTerminalButSomeFailed`

**Endpoint tests** (~16): controller-level integration tests for the 5 new endpoints + the 2 modified P2.7/P2.8 controllers (assert response now includes `job_id` + a job row was created). Plus 5 CORS regression tests for the new routes.

**AS job tests** (modified):
- `UpdateSitePluginTest::testItemStateMarkedStartedWhenJobItemIdProvided`
- `UpdateSitePluginTest::testItemStateMarkedSucceededOnSuccess`
- `UpdateSitePluginTest::testItemStateMarkedFailedOnException`
- `UpdateSitePluginTest::testNoItemStateChangeWhenJobItemIdIsZero` (backwards-compat — pre-v0.9.0 AS rows still work).
- Same 4 for `UpdateSiteThemeTest`.

### 2.11 Version bump

- `defyn-dashboard.php`: `Version: 0.8.1` → `Version: 0.9.0` + `DEFYN_DASHBOARD_VERSION` constant.
- `composer.json`: `"version": "0.9.0"`.
- `readme.txt`: `Stable tag: 0.9.0` + changelog:

```
= 0.9.0 =
* Bulk-jobs entity: every POST /overview/bulk-update-plugins and POST /overview/bulk-update-themes now creates a tracked job in wp_defyn_bulk_jobs + N child rows in wp_defyn_bulk_job_items. Response envelope adds job_id.
* New GET /defyn/v1/jobs (30/MIN) + GET /defyn/v1/jobs/{id} (30/MIN) feed the new SPA /jobs route and per-job detail view.
* New POST /defyn/v1/jobs/{id}/cancel (5/HR) cancels all queued items via as_unschedule_action. Items already started can't be cancelled.
* New POST /defyn/v1/jobs/{id}/items/{item_id}/retry (20/HR) + POST /defyn/v1/jobs/{id}/retry-failed (5/HR) re-schedule failed items.
* Schema v6 → v7 (additive: 2 new tables, no destructive ALTERs). Self-heal handles upgrade transparently.
* Minor version bump because the new domain entity is a meaningful surface change, not a patch.
```

---

## §3. SPA UI

### 3.1 Routes

`App.tsx` router config gains:

- `/jobs` — list view (`Jobs.tsx`)
- `/jobs/:id` — detail view (`JobDetail.tsx`)

Existing routes (`/overview`, `/sites`, `/sites/:id`) unchanged.

### 3.2 Sidebar nav

The existing left sidebar gains a "Jobs" link below "Overview" + "Sites". Badge count shows total active jobs (`queued` + `in_progress` states). Polled every 30s via `useJobsCount`. Badge hidden when count is 0.

```
┌────────────────┐
│ DefynWP        │
│                │
│ ▸ Overview     │
│ ▸ Sites        │
│ ▸ Jobs (3)     │ ← new, badge shows active count
└────────────────┘
```

### 3.3 Jobs list view

```
┌──────────────────────────────────────────────────────────────────┐
│ Jobs                              [Active] Completed  All        │ ← status filter chips
├──────────────────────────────────────────────────────────────────┤
│ ┌──────────────────────────────────────────────────────────────┐ │
│ │ plugin_update     12 scheduled                               │ │
│ │ 9 succeeded   2 failed   1 cancelled   5 mins ago            │ │
│ │ [State chip: partial]                                        │ │
│ └──────────────────────────────────────────────────────────────┘ │
│ ┌──────────────────────────────────────────────────────────────┐ │
│ │ theme_update      4 scheduled                                │ │
│ │ 2 queued  2 started   30 secs ago                            │ │
│ │ [State chip: in_progress]                                    │ │
│ └──────────────────────────────────────────────────────────────┘ │
├──────────────────────────────────────────────────────────────────┤
│        Prev  Page 1 of 3  Next                                   │
└──────────────────────────────────────────────────────────────────┘
```

Row click → `/jobs/:id`. Pagination uses `?page=N` query param.

### 3.4 Job detail view

```
┌──────────────────────────────────────────────────────────────────┐
│ Back to Jobs                                                     │
│                                                                  │
│ plugin_update  Job #42                     [Cancel] [Retry all]  │ ← Cancel enabled if any queued; Retry-all if failed_count > 0
│ 12 scheduled   9 succeeded  2 failed  1 cancelled   5 mins ago   │
│ [State chip: partial]                                            │
├──────────────────────────────────────────────────────────────────┤
│ SmartCoding (5 items)                                            │ ← per-site collapsible
│   Akismet Anti-Spam 5.3 to 5.3.1     succeeded                   │
│   Yoast SEO 22.5 to 22.6              succeeded                  │
│   Elementor 3.18 to 4.0  site failed [Retry]                     │ ← per-row Retry only when failed
│   WPML 4.6 to 4.7                     cancelled                  │
│   Jetpack 13.1 to 13.2                succeeded                  │
│                                                                  │
│ AcmeBlog (7 items)                                               │
│   Akismet 5.3 to 5.3.1                succeeded                  │
│   Astra theme 4.6 to 4.7              succeeded                  │
│   Beaver Builder 3.0 to 3.1  timeout [Retry]                     │
│   ...                                                            │
└──────────────────────────────────────────────────────────────────┘
```

Polling: `useJobDetail` adaptive — polls every 5s when any item is in `queued` or `started`; stops polling when all items terminal.

### 3.5 Component file structure (SPA)

**New files:**

| Path | Responsibility |
|---|---|
| `src/routes/Jobs.tsx` | List page — status filter chips + paginated row list |
| `src/routes/JobDetail.tsx` | Detail page — header + items grouped by site + Cancel/Retry buttons |
| `src/components/jobs/JobRow.tsx` | Single list-row card (kind badge + counts + state chip + timestamps) |
| `src/components/jobs/JobHeader.tsx` | Detail-view header (kind + counts + state + action buttons) |
| `src/components/jobs/JobItemsGroup.tsx` | Per-site collapsible group of items |
| `src/components/jobs/JobItemRow.tsx` | Single item row (resource + version diff + state chip + per-row Retry) |
| `src/components/jobs/JobStateChip.tsx` | Reusable state chip (5 item states + 4 job states, color-coded) |
| `src/components/jobs/CancelJobDialog.tsx` | Neutral confirm dialog for cancel-queued |
| `src/components/jobs/RetryFailedDialog.tsx` | Neutral confirm dialog for bulk-retry-failed |
| `src/components/sidebar/JobsNavLink.tsx` | Sidebar entry with active-count badge |
| `src/lib/queries/useJobsList.ts` | TanStack query — adaptive polling when active jobs |
| `src/lib/queries/useJobDetail.ts` | TanStack query — adaptive polling when items active |
| `src/lib/queries/useJobsCount.ts` | TanStack query — 30s polling for sidebar badge |
| `src/lib/mutations/useCancelJob.ts` | TanStack mutation — invalidates job + list + count |
| `src/lib/mutations/useRetryItem.ts` | TanStack mutation — invalidates job + list + count |
| `src/lib/mutations/useRetryFailed.ts` | TanStack mutation — invalidates job + list + count |

**Modified files:**

| Path | What changes |
|---|---|
| `src/App.tsx` (or routes config) | Add 2 new routes |
| `src/components/Sidebar.tsx` (or equivalent) | Render `<JobsNavLink />` |
| `src/types/api.ts` | Add 7 new Zod schemas (job, jobItem, jobsListResponse, jobDetailResponse, cancelJobResponse, retryItemResponse, retryFailedResponse, jobsCountResponse) |
| `src/test/handlers.ts` | Add 6 MSW handlers (5 new endpoints + jobs-count endpoint if separate, or derive from list) |
| `src/components/overview/BulkUpdatePluginsButton.tsx` | `onConfirm` mutation's `onSuccess` callback navigates to `/jobs/{response.job_id}` |
| `src/components/overview/BulkUpdateThemesButton.tsx` | Same |

### 3.6 Hook contracts

**`useJobsList(status, page)`:**

```ts
export function useJobsList(status: 'active' | 'completed' | 'all', page: number) {
  return useQuery({
    queryKey: ['jobs', status, page],
    queryFn: async () => {
      const res = await apiClient.get<unknown>(
        `/overview/jobs?status=${status}&page=${page}&per_page=20`,
      );
      return jobsListResponseSchema.parse(res);
    },
    refetchInterval: (data) =>
      data?.jobs?.some((j) => j.state === 'queued' || j.state === 'in_progress') ? 10_000 : false,
    staleTime: 5_000,
  });
}
```

**`useJobDetail(id)`:**

```ts
export function useJobDetail(id: number) {
  return useQuery({
    queryKey: ['job', id],
    queryFn: async () => {
      const res = await apiClient.get<unknown>(`/jobs/${id}`);
      return jobDetailResponseSchema.parse(res);
    },
    refetchInterval: (data) =>
      data?.items?.some((i) => i.state === 'queued' || i.state === 'started') ? 5_000 : false,
    staleTime: 2_000,
  });
}
```

Adaptive polling stops automatically once all items terminal — UI freezes at the final state until operator navigates away.

### 3.7 State chip colors

| State | Color (Tailwind class) | Treatment |
|---|---|---|
| `queued` | `text-zinc-600 bg-zinc-100` | small clock icon |
| `started` | `text-blue-700 bg-blue-100` | small spinner |
| `succeeded` | `text-green-700 bg-green-100` | checkmark |
| `failed` | `text-red-700 bg-red-100` | X mark |
| `cancelled` | `text-zinc-500 bg-zinc-100 line-through` | dash |

Job-level states use the same color logic mapped to the dominant item state.

### 3.8 Cancel + Retry button behavior

**Cancel button** (in `JobHeader`):
- Enabled when `job.queued_count > 0`.
- Disabled with tooltip "All items already started or terminal" when `queued_count === 0`.
- Click → `CancelJobDialog` (neutral default-styled primary, NOT red — cancel-queued is non-destructive).
- Confirm copy: "Cancel N queued items? Items already in progress can't be cancelled and will continue running."

**Retry per-item button** (in `JobItemRow`):
- Visible only when `item.state === 'failed'`.
- One-click — no confirmation dialog (single item retry is low-risk).
- Click → `useRetryItem` mutation.

**Retry-failed bulk button** (in `JobHeader`):
- Enabled when `job.failed_count > 0`.
- Click → `RetryFailedDialog` (neutral default-styled primary).
- Confirm copy: "Retry N failed items?"

### 3.9 Integration with P2.7/P2.8 dialogs

`BulkUpdatePluginsButton.tsx` + `BulkUpdateThemesButton.tsx` — the `handleConfirm` callback currently calls `mutation.mutate(...)`. P2.9 adds a `navigate` call inside the success callback:

```tsx
const navigate = useNavigate();

const handleConfirm = (pairs: Array<{site_id: number, slug: string}>): void => {
  mutation.mutate(
    { updates: pairs },
    {
      onSuccess: (data) => {
        setDialogOpen(false);
        if (data.job_id !== null) {
          navigate(`/jobs/${data.job_id}`);
        }
      },
    },
  );
};
```

If `job_id` is null (all-skipped 200 response), stay on `/overview` — no job was created. Otherwise navigate.

### 3.10 SPA tests (~18 Vitest)

**Hooks (~10):**
- `useJobsList.test.tsx` ×3: fetch shape, adaptive polling on active, no polling when all terminal.
- `useJobDetail.test.tsx` ×3: fetch shape, adaptive polling on active items, no polling when all terminal.
- `useJobsCount.test.tsx` ×2: returns count, 30s polling.
- `useCancelJob.test.tsx` ×2: mutate + invalidates `['job', id]` + `['jobs', *]` + `['jobsCount']`.
- `useRetryItem.test.tsx` + `useRetryFailed.test.tsx` ×2 each: same pattern.

**Components (~10):**
- `JobStateChip.test.tsx` ×9: one per state value (5 item + 4 job states).
- `JobRow.test.tsx` ×3: renders kind badge + counts + state chip.
- `JobItemRow.test.tsx` ×6: 5 state chips render correctly + Retry button conditional on failed state.
- `Jobs.test.tsx` ×3: list renders + status filter switches query + pagination.
- `JobDetail.test.tsx` ×4: renders header + items + Cancel button enabled state + Retry-all button enabled state.

**Existing test updates:**
- `ConfirmBulkUpdatePluginsDialog.test.tsx` + `ConfirmBulkUpdateThemesDialog.test.tsx`: existing tests stay unchanged. New behavior (navigate on success) is in the BUTTON component, not the dialog.
- `BulkUpdatePluginsButton.test.tsx` + `BulkUpdateThemesButton.test.tsx`: add 1 test each — `navigatesToJobDetailOnSuccess` (asserts `navigate('/jobs/42')` was called when mutation resolves with `job_id: 42`).

---

## §4. Smoke matrix (10 steps)

| # | Step | Expected | Notes |
|---|---|---|---|
| 1 | Activate dashboard v0.9.0 + verify schema | Both `wp_defyn_bulk_jobs` + `wp_defyn_bulk_job_items` tables exist | SHOW TABLES via SSH or wp-admin DB check |
| 2 | POST `/overview/bulk-update-plugins` with valid pairs | 202 + `job_id` non-null in response | Carry-forward: zero-sites prod state may foreclose 202 — fall back to seeded smoke |
| 3 | GET `/jobs` returns the new job | 200 + jobs array includes the just-created job | |
| 4 | GET `/jobs/{id}` returns items with state=`queued` initially | 200 + items array with all `queued` | Re-poll after AS workers start processing |
| 5 | POST `/jobs/{id}/cancel` | 200 + `cancelled_count > 0` + AS jobs unscheduled | Verify via `as_get_scheduled_actions(['hook' => 'defyn_update_site_plugin'])` returns fewer than before |
| 6 | POST `/jobs/{id}/items/{item_id}/retry` (on a previously-failed item) | 202 + item state resets to `queued` + new AS scheduled | Requires a failed item — produce via deliberate broken plugin upgrade |
| 7 | POST `/jobs/{id}/retry-failed` | 202 + `retried_count > 0` | Same prerequisite as #6 |
| 8 | SPA visual: `/jobs` route + sidebar Jobs link with badge | Renders + badge shows active count | Visual smoke foreclosed by UI-password-entry prohibition; fallback to bundle-string grep |
| 9 | SPA visual: `/jobs/{id}` detail | Renders header + per-site collapsibles + state chips | Same |
| 10 | SPA visual: ConfirmBulkUpdatePluginsDialog confirm → navigates to `/jobs/{id}` | URL changes to `/jobs/{id}` automatically | Same |

**Carry-forward (per prior phases):**
- Prod `wp_defyn_sites` may be empty for user 1 (P2.6+P2.7+P2.8 carry-forward) — happy 202 paths foreclosed.
- Visual SPA smoke (steps 8-10) foreclosed by UI-password-entry prohibition — verify via deployed bundle string grep (search for `/jobs`, `Cancel`, `Retry all`, etc.).
- Kinsta Redis stale-transient cache may make rate-limit counts off — assert mechanism triggers + error envelope is right (carry-forward from P2.4.1+P2.5+P2.6+P2.7+P2.8).

---

## §5. Out of scope / deferred

- **P2.9.1 (Auto-prune retention sweep)** — daily AS cleanup job deleting `bulk_jobs` rows + cascading `bulk_job_items` older than X days. Defer to P2.9.1 if storage becomes a real concern. YAGNI now.
- **Cancel currently-running items** — requires cooperative-cancel hooks in plugin/theme upgrader (mid-stream flag checks). Out of scope; cancel-queued only. UI honest about it.
- **Per-item activity events** — would create noise. Operator audits per-pair lifecycle by visiting `/jobs/{id}` instead. Fleet-scoped P2.7/P2.8 events stay as the audit trail.
- **Wrap P2.6 sync-all in BulkJob entity** — explicitly rejected (refresh is idempotent + cheap, cancel/retry have marginal value). Future P2.9.2 if operator feedback says otherwise.
- **Wrap per-site P2.2/P2.3 updates** — explicitly rejected (single-site updates already have their own per-site lifecycle visibility via `SitePluginsRow` / `SiteThemeRow`). Adding a BulkJob row per single-site update would clutter the jobs list.
- **Real-time push via WebSocket** — adaptive HTTP polling (5-10s cadence) is sufficient for the bulk-update workload (typical fan-out is 5-50 items, completes in seconds-to-minutes). WebSocket complexity defer to a multi-tenant agency build.
- **Multi-user permission expansion** — `findByIdForUser` + `findAllForUser` scope jobs to the creating operator. Future agency build can add a "see all jobs for accounts I manage" view.

---

## §6. Plan-bug guardrails (encoded for writing-plans)

1. **shadcn `Button` has NO `destructive` variant.** Cancel + Retry buttons use default-styled primary (non-destructive — operator confirms IS the safety). Carry-forward from P2.7+P2.8.
2. **Schema v6 → v7 migration** is additive (2 new CREATE TABLEs, no destructive ALTERs). Schema self-heal on `plugins_loaded` handles "Replace current" gracefully (carry-forward from P2.2.1).
3. **AS hook arg count change:** `Plugin::boot` registration for `UpdateSitePlugin::HOOK` + `UpdateSiteTheme::HOOK` MUST be bumped from 3 args to 4 args. Pre-v0.9.0 AS rows in flight have 3 args — registration must accept BOTH (use default param `$jobItemId = 0` in `handle` signature so old rows don't fatal).
4. **`as_unschedule_action` requires EXACT args match.** Cancel controller MUST pass the same 4-tuple used at schedule time (siteId, slug, 0, jobItemId).
5. **`BulkJobsRepository::createItems` MUST use single multi-row INSERT.** Test `testCreateItemsUsesSingleInsertStatement` peeks at `$wpdb->num_queries` to assert ONE INSERT (not N).
6. **Item state machine enforcement:** `markItemCancelled` must only allow transition from `queued`. Calling cancel on a `started`/`succeeded`/`failed` item is a silent no-op.
7. **`findByIdForUser` returns null for foreign jobs.** REST controllers return 404 (not 403) to avoid info-leak about job existence.
8. **`refreshJobTimestamps` is automatic.** Every `markItem*` call MUST trigger a `refreshJobTimestamps` to keep `bulk_jobs.started_at` + `completed_at` accurate without extra controller-side bookkeeping.
9. **SPA adaptive polling cadences are CRITICAL:**
   - `useJobsList` polls every **10s** when any job has `state in (queued, in_progress)`; otherwise no polling.
   - `useJobDetail` polls every **5s** when any item has `state in (queued, started)`; otherwise no polling.
   - `useJobsCount` polls every **30s** unconditionally.
10. **Mutation hooks invalidate `['job', id]` + `['jobs', *]` + `['jobsCount']` on success.** NOT `['sites']` (per-site state hasn't changed). Same reasoning as P2.6/P2.7/P2.8 carry-forward.
11. **Navigate-on-success pattern in BulkUpdate{Plugins,Themes}Button.** `mutation.mutate(..., {onSuccess: (data) => { setDialogOpen(false); if (data.job_id) navigate('/jobs/'+data.job_id); }})`. Behavior tested in the BUTTON component, not the dialog.
12. **`job_id` is null in response when scheduled_count = 0.** Don't insert a parent job row when nothing was scheduled (matches the activity-event guardrail #2 from P2.7+P2.8).
13. **Cancel response uses 200 OK (not 202)** — the cancellation is synchronous + complete by the time response returns. Distinct from Retry which is 202 (re-queues for async processing).
14. **404 `jobs.not_found` for missing OR foreign jobs.** Item-level errors use `jobs.item_not_found` (404) + `jobs.item_not_retryable` (400 when state is not failed).
15. **Test isolation:** any test class extending `AbstractSchemaTestCase` that seeds `bulk_jobs` MUST call `freshlyActivate('defyn_bulk_jobs')` + `freshlyActivate('defyn_bulk_job_items')` + explicit `DELETE FROM` in `setUp()`. Standard carry-forward from P2.6+P2.7+P2.8.
16. **Test fixtures must seed NOT NULL columns.** `defyn_bulk_jobs` requires `user_id`, `kind`, `created_at`. `defyn_bulk_job_items` requires `job_id`, `site_id`, `resource_slug`, `state`, `created_at`. Plan-author must read `Schema/BulkJobsTable.php` + `Schema/BulkJobItemsTable.php` migrations before writing test fixtures.
17. **Zip-build exclusion list per P2.8 MEMORY entry — NEVER strip `vendor/symfony/*`.** After `composer install --no-dev --classmap-authoritative`, ALL remaining vendor/ contents are required by prod autoload. Correct exclusion list: `tests/*`, `*wp-tests-config.php`, `.phpunit.result.cache`, `test-output.log`, `phpunit.xml`, `composer.lock`, `.github/*`, `.gitignore`.
18. **Kinsta cache discipline:** after every WP-Admin "Replace current" install on Kinsta, click MyKinsta → Tools → Clear cache.
19. **`BulkJobAggregator` is pure-function** — no I/O, no DB, no globals. Tested in isolation. Used by both the list controller (per-job state) AND the detail controller (job-level state).
20. **State chip colors are defined in `JobStateChip.tsx`, not inline** — DRY. Used by both list-row + detail-row + header.
21. **Per-row Retry button is one-click (no confirmation).** Single-item retry is low-risk. Bulk Retry-all uses neutral default dialog.
22. **Sidebar badge hides when active count = 0.** No "(0)" suffix in the link text.

---

**Spec status:** ready for writing-plans skill. Estimated ~20 TDD tasks across 4 phases (schema + repository + aggregator → REST endpoints → AS-job integration → SPA route + components + hooks + tests + ship).
