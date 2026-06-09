# P2.8 — Bulk theme updates across fleet (Design Spec)

**Date:** 2026-06-09
**Status:** Approved (brainstorming complete §1→§7)
**Predecessor:** P2.7.1 — Minor-only filter on bulk plugin dialog, tag `p2-7-1-minor-only-filter-complete` (commit `c48e5fd`). Dashboard v0.8.0 live in prod.
**Successor candidates:** P2.9 (bulk-jobs entity for cancel/resume/history), P2.10 (filtered drill-in `/overview/plugins`+`/overview/themes` routes)
**Spec scope:** Add a "Bulk update themes (N)" button to the Operator Overview at `/overview`. Structural twin of P2.7 — operator reviews + selectively unchecks (site, theme) pairs in a destructive-tier confirmation dialog (with day-1 "Skip major bumps" toggle baked in), then launches a fan-out of the existing P2.3 `defyn_update_site_theme` AS job per confirmed pair. Two new REST endpoints, one new dashboard release (v0.8.1), no connector changes, no schema changes.

---

## §1. Architecture overview

**Goal:** ship a single "Bulk update themes" button on the Overview header that lets the operator confirm + fan-out theme updates across every site they own, in a single action. Operator reviews the full list of `(site, theme, current → target)` triples in a confirmation dialog, can uncheck any pair before launching, and can flip a "Skip major bumps" toggle to hide majors entirely (mirrors P2.7.1's plugin dialog).

**Two new REST endpoints:**

1. **`GET /defyn/v1/overview/pending-theme-updates`** — enumerates eligible pairs for the dialog. Returns a flat list of `{site_id, site_label, stylesheet, theme_name, current_version, target_version}` rows. One INNER JOIN against `defyn_sites` + `defyn_site_themes` filtered by `user_id` + `update_available = 1`. Rate limit **30/MINUTE** per user (matches P2.7's `/overview/pending-plugin-updates`).
2. **`POST /defyn/v1/overview/bulk-update-themes`** — body `{updates: [{site_id, stylesheet}, ...]}`. Server validates ownership + `update_available = 1` for each pair (silently skips invalid pairs, reports them in the response), fan-outs `as_schedule_single_action('defyn_update_site_theme', [$siteId, $stylesheet, 0], 'defyn')` per valid pair, emits ONE fleet-scoped `overview.bulk_theme_update_requested` activity event with `site_id = null` and `details: {scheduled_count, skipped_count, pairs: [{site_id, stylesheet}, ...]}`. Rate limit **5/HOUR** per user (`RateLimit::bulkThemeUpdate`). Bypasses the underlying per-(user, site, stylesheet) `themesUpdate` 6/HOUR bucket — operator's explicit dialog confirmation IS the safety.

**Persistence model:** no new schema, no job entity. Aggregate progress visibility comes from the existing Recent Activity widget on Overview (polling at 60s), same as P2.6 + P2.7. The per-pair `theme_update.requested|started|succeeded|failed` triplet continues to fire from each fanned-out `UpdateSiteTheme` AS job (P2.3 plumbing — unchanged).

**Inventory freshness:** trust the existing inventory. If a pair was already updated externally between dialog-open and confirm, the per-pair `UpdateSiteTheme` AS job's existing `no_update_available` 409 handler emits `theme_update.no_update_available` and exits cleanly. No preflight refresh.

**SPA side:**
- ONE new `BulkUpdateThemesButton` component on `Overview.tsx` header (alongside the existing `SyncAllSitesButton` + `BulkUpdatePluginsButton`).
- ONE new `ConfirmBulkUpdateThemesDialog` — RED-tier destructive primary button (`className="bg-red-600 hover:bg-red-700 text-white"` since shadcn `Button` has NO `destructive` variant — carry-forward from P2.7 plan-bug #1), Cancel default focus, per-site collapsible groups with per-row checkboxes, all pre-checked by default, long lists collapse behind a "show all N sites ▾" disclosure, **plus the "Skip major bumps" toggle baked in from day 1** (mirrors P2.7.1 — toggle default OFF, opt-in).
- ONE new `PendingThemeUpdatesGroup` sub-component — per-site group with grouped checkbox + child rows.
- ONE new TanStack query hook `usePendingThemeUpdates(dialogOpen)` — enabled-only-on-dialog-open (NOT polling).
- ONE new TanStack mutation hook `useBulkUpdateThemes()` — invalidates `['overview']` + `['pendingThemeUpdates']` on success, NOT `['sites']`.

**Semver helper rename:** `isPluginMajorBump` in `apps/web/src/lib/semver.ts` becomes `isMajorBump` (resource-agnostic). Updated callsites: 1 in `ConfirmBulkUpdatePluginsDialog.tsx`, 1 in `ConfirmBulkUpdateThemesDialog.tsx` (new), plus the 8 test names in `apps/web/tests/lib/semver.test.ts` and the 3 dialog tests in `ConfirmBulkUpdatePluginsDialog.test.tsx`.

**Conditional rendering:** the bulk-themes button is **hidden entirely** (not just disabled) when `pending_updates.themes === 0`, matching P2.7's plugins button. Header button order: `[Sync all sites] [Bulk update plugins (N)] [Bulk update themes (M)]` left-to-right; each Bulk button independently hides when its count is 0.

**Schema:** stays at **v6**. No new tables, no new columns.

**Connector:** no changes. Stays at **v0.1.7**. The existing `UpdateSiteTheme` AS job from P2.3 handles each fanned-out pair without modification.

**Dashboard:** **v0.8.0 → v0.8.1** (patch bump — additive endpoints + new event type, same destructive-bulk shape as v0.8.0 introduced).

---

## §2. Dashboard REST contract

### 2.1 GET `/defyn/v1/overview/pending-theme-updates`

| Field | Value |
|---|---|
| Auth | Bearer JWT |
| Rate limit | `RateLimit::overviewPendingThemeUpdates` — **30/MINUTE per user** (`OVERVIEW_PENDING_THEME_UPDATES_LIMIT = 30`, window `MINUTE_IN_SECONDS`) |
| Cache headers | `Cache-Control: no-store` (inherited from `RestRouter::applyNoCacheHeaders`) |
| Body | empty / not required |

**Response (200):**
```json
{
  "pending_updates": [
    {
      "site_id": 1,
      "site_label": "SmartCoding",
      "stylesheet": "astra",
      "theme_name": "Astra",
      "current_version": "4.6.3",
      "target_version": "4.7.0"
    }
  ],
  "generated_at": "2026-06-09 23:45:00"
}
```

`generated_at` is `gmdate('Y-m-d H:i:s')` — same UTC format as `/overview`'s `generated_at`.

**SQL (in new `SiteThemesRepository::findAllPendingUpdatesForUser`):**

```sql
SELECT s.id AS site_id, s.label AS site_label,
       st.stylesheet, st.name AS theme_name,
       st.version AS current_version, st.update_version AS target_version
FROM {sites} s
INNER JOIN {site_themes} st ON st.site_id = s.id
WHERE s.user_id = %d
  AND st.update_available = 1
ORDER BY s.label, st.name
```

### 2.2 POST `/defyn/v1/overview/bulk-update-themes`

| Field | Value |
|---|---|
| Auth | Bearer JWT |
| Rate limit | `RateLimit::bulkThemeUpdate` — **5/HOUR per user** (`BULK_THEME_UPDATE_LIMIT = 5`, window `HOUR_IN_SECONDS`) |
| Body | `{updates: [{site_id: int, stylesheet: string}, ...]}` (must be non-empty array) |
| Cache headers | `Cache-Control: no-store` |

**Response (202 — success, scheduled_count > 0):**
```json
{
  "scheduled_count": 12,
  "skipped_count": 1,
  "scheduled_pairs": [{"site_id": 1, "stylesheet": "astra"}],
  "skipped_pairs": [
    {"site_id": 1, "stylesheet": "twentytwentyfour", "reason": "no_update_available"},
    {"site_id": 5, "stylesheet": "blocksy", "reason": "site_not_owned"},
    {"site_id": 8, "stylesheet": "missing-theme", "reason": "theme_not_found"}
  ],
  "scheduled_at": "2026-06-09 23:45:42"
}
```

**Response (200 — all pairs skipped, no jobs scheduled):** same envelope shape with `scheduled_count: 0`, `scheduled_pairs: []`, full `skipped_pairs` array. NO activity event emitted (matches P2.6 + P2.7 guardrail #4 pattern).

**Response (400 — empty / malformed body):** `{error: {code: "bulk.empty_updates", message: "updates array must be non-empty"}}`

**Response (429 — over rate limit):** `{error: {code: "bulk.rate_limited", message: "Too many bulk update requests. Try again in an hour."}}`

**Skip reasons (exact strings):**

| Reason | Trigger |
|---|---|
| `site_not_owned` | `SitesRepository::findByIdForUser($siteId, $userId)` returns null |
| `theme_not_found` | `SiteThemesRepository::findRowForSiteAndStylesheet($siteId, $stylesheet)` returns null |
| `no_update_available` | row exists but `update_available = 0` |

All skip cases are silent — the operator sees them in the response but no 4xx is returned (the bulk is a "best-effort fan-out").

### 2.3 Controller flow (PHP pseudocode)

```php
final class OverviewBulkUpdateThemesController
{
    public function __construct(
        private readonly SitesRepository $sites = new SitesRepository(),
        private readonly SiteThemesRepository $themes = new SiteThemesRepository(),
        private readonly ActivityLogger $logger = new ActivityLogger(),
    ) {}

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        // Defensive STDOUT guard — carry-forward from P2.2 plan-bug #4.
        ob_start();
        try {
            $userId = (int) $request->get_param('_authenticated_user_id');
            $body   = $request->get_json_params();
            $updates = $body['updates'] ?? [];

            if (!is_array($updates) || count($updates) === 0) {
                return ErrorResponse::create(400, 'bulk.empty_updates', 'updates array must be non-empty');
            }

            $scheduled = [];
            $skipped   = [];

            foreach ($updates as $pair) {
                $siteId     = (int) ($pair['site_id'] ?? 0);
                $stylesheet = (string) ($pair['stylesheet'] ?? '');

                if ($this->sites->findByIdForUser($siteId, $userId) === null) {
                    $skipped[] = ['site_id' => $siteId, 'stylesheet' => $stylesheet, 'reason' => 'site_not_owned'];
                    continue;
                }
                $row = $this->themes->findRowForSiteAndStylesheet($siteId, $stylesheet);
                if ($row === null) {
                    $skipped[] = ['site_id' => $siteId, 'stylesheet' => $stylesheet, 'reason' => 'theme_not_found'];
                    continue;
                }
                if ((int) ($row['update_available'] ?? 0) !== 1) {
                    $skipped[] = ['site_id' => $siteId, 'stylesheet' => $stylesheet, 'reason' => 'no_update_available'];
                    continue;
                }

                as_schedule_single_action(time(), 'defyn_update_site_theme', [$siteId, $stylesheet, 0], 'defyn');
                $scheduled[] = ['site_id' => $siteId, 'stylesheet' => $stylesheet];
            }

            if (count($scheduled) > 0) {
                $this->logger->log(
                    $userId,
                    null,                                              // fleet-scoped — plan-bug trap #4
                    'overview.bulk_theme_update_requested',            // exact string — plan-bug trap #3
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

### 2.4 File structure (Dashboard)

| File | Responsibility |
|---|---|
| `src/Rest/OverviewPendingThemeUpdatesController.php` (new) | GET endpoint — flat list of eligible pairs |
| `src/Rest/OverviewBulkUpdateThemesController.php` (new) | POST endpoint — validate + fan-out + fleet activity event |
| `src/Rest/Middleware/RateLimit.php` (extend) | Add `OVERVIEW_PENDING_THEME_UPDATES_LIMIT/WINDOW` + `BULK_THEME_UPDATE_LIMIT/WINDOW` constants + 2 new permission methods |
| `src/Rest/RestRouter.php` (extend) | Register the 2 new routes (immediately after `/overview/bulk-update-plugins`, BEFORE `/activity`) |
| `src/Services/SiteThemesRepository.php` (extend) | Add `findAllPendingUpdatesForUser(int $userId): array` for the GET endpoint |

### 2.5 Activity event contract

| Field | Value |
|---|---|
| `event_type` | `overview.bulk_theme_update_requested` (exact match — distinct from P2.7's `overview.bulk_plugin_update_requested` per Q3) |
| `user_id` | the authenticated operator |
| `site_id` | `null` (fleet-scoped, mirror of P2.7) |
| `details` | `{scheduled_count: int, skipped_count: int, pairs: [{site_id: int, stylesheet: string}, ...]}` |

This is the ONLY new event type P2.8 introduces. Per-pair `theme_update.requested|started|succeeded|failed|no_update_available` continue to fire from `UpdateSiteTheme` exactly as today.

### 2.6 Tests (~13 PHP)

**`OverviewPendingThemeUpdatesControllerTest`:**
- `testAuthRequiredReturns401WhenNoBearerToken`
- `testHappyPath200WithFlatList`
- `testHappyPath200EmptyListWhenNoThemesPending`
- `testRateLimit429AfterThirtyFirstCall` (30/MINUTE — mirrors P2.7)
- `testOwnershipScopingExcludesOtherUsersSites`

**`OverviewBulkUpdateThemesControllerTest`:**
- `testAuthRequiredReturns401WhenNoBearerToken`
- `testHappyPath202WithScheduledPairs` — seeds 3 valid pairs, asserts 202 + scheduled_pairs matches
- `testEmptyUpdatesReturns400`
- `testRateLimit429AfterSixthCall` ← **critical: NOT seventh, NOT eleventh, NOT thirty-first**
- `testSkipsPairsNotOwnedOrWithoutUpdate` — seeds 3 invalid pairs (one per skip reason), asserts each maps to the correct `reason` string
- `testFanOutSchedulesPerPair` — uses `as_get_scheduled_actions(['hook' => 'defyn_update_site_theme'])` to assert N pending actions
- `testActivityEventEmittedWithCorrectDetails` — asserts `overview.bulk_theme_update_requested` row with `site_id = null` and correct `details` JSON
- `testZeroValidPairsReturns200AndNoActivityEvent` — all 3 skip reasons fire, no log row written

**`SiteThemesRepositoryPendingUpdatesTest` (new file):**
- `testFindAllPendingUpdatesForUserReturnsCorrectRowsAcrossSites`
- `testFindAllPendingUpdatesForUserExcludesOtherUsers`
- `testFindAllPendingUpdatesForUserExcludesRowsWithoutAvailableUpdate`

### 2.7 Version bump

- `packages/dashboard-plugin/defyn-dashboard.php`: `Version: 0.8.0` → `Version: 0.8.1` (also bump `DEFYN_DASHBOARD_VERSION` constant if defined).
- `packages/dashboard-plugin/composer.json`: `"version": "0.8.1"`.
- `packages/dashboard-plugin/readme.txt`: `Stable tag: 0.8.1` + changelog:

```
= 0.8.1 =
* Bulk theme updates across fleet: POST /defyn/v1/overview/bulk-update-themes fan-outs the existing P2.3 UpdateSiteTheme AS job per confirmed (site, theme) pair. 5/hour rate limit. Single overview.bulk_theme_update_requested activity event captures the fleet-scoped intent.
* New GET /defyn/v1/overview/pending-theme-updates returns a flat list of eligible (site, theme) pairs for the SPA's confirmation dialog. 30/minute rate limit.
* SPA confirmation dialog ships with the "Skip major bumps" toggle baked in from day 1 (mirrors P2.7.1 for plugins). Semver helper isPluginMajorBump renamed to isMajorBump in apps/web/src/lib/semver.ts (resource-agnostic).
* Patch bump because endpoints + event type are additive on top of the v0.8.0 destructive-bulk shape.
```

---

## §3. SPA UI — Bulk update themes button + dialog

### 3.1 Placement

Overview header gains a third bulk-action button alongside `SyncAllSitesButton` + `BulkUpdatePluginsButton`:

```
┌───────────────────────────────────────────────────────────────────────────────────┐
│ Overview          Last refreshed: 2 minutes ago                                   │
│                   [↻ Sync all sites]  [⚙ Bulk update plugins (47)]                │
│                                       [⚙ Bulk update themes (12)]                 │
├───────────────────────────────────────────────────────────────────────────────────┤
│ [Plugin updates: 47] [Theme updates: 12] [WP core updates: 1/0]                   │
│ [Sites needing attention]     [Recent activity]                                   │
└───────────────────────────────────────────────────────────────────────────────────┘
```

The right column becomes a stacked group: timestamp + **up to three buttons** (`Sync all` + `Bulk update plugins` + `Bulk update themes`). Each Bulk button shows the **dynamic count** in its label so the operator sees scope without opening the dialog. On narrower screens (< md breakpoint) the buttons wrap to the next line.

**Conditional rendering:**
- `SyncAllSitesButton` — always visible, disabled when `total_sites === 0` (existing P2.6 behavior, unchanged).
- `BulkUpdatePluginsButton` — **hidden entirely** when `pending_updates.plugins === 0` (existing P2.7 behavior, unchanged).
- `BulkUpdateThemesButton` — **hidden entirely** when `pending_updates.themes === 0` (per Q2 — matches plugins).

### 3.2 ConfirmBulkUpdateThemesDialog — copy

Mirrors P2.7's `ConfirmBulkUpdatePluginsDialog` with theme-substituted copy (per Q4 — minimal swap, no extra appearance-impact warning):

| Element | Copy |
|---|---|
| Title | `Bulk update {visibleCount} themes across {siteCount} sites?` |
| Body line 1 | `This will run the theme upgrader on every checked pair below. Each site briefly enters maintenance mode during its update.` |
| Body line 2 | `Uncheck any pair you want to skip — server fans out exactly what's checked. Already-updated rows are silently no-op'd.` |
| Toggle label | `Skip major bumps` |
| Toggle help | `(hide updates where the major version changes, e.g. 1.x → 2.x)` |
| Footer counter | `{checkedCount} selected of {visibleCount} available` |
| Cancel button | `Cancel` (default focus) |
| Primary button | `Bulk update {checkedCount} themes` (`className="bg-red-600 hover:bg-red-700 text-white"`) |

When `checkedCount === 0` the primary is disabled. When `visibleCount === 0` (toggle ON + every pair is major) the dialog body shows "No minor updates available" instead of the per-site groups, and the primary is hidden.

### 3.3 Component file structure (SPA)

| File | Responsibility |
|---|---|
| `apps/web/src/components/overview/BulkUpdateThemesButton.tsx` (new) | Header button — hidden when `pendingCount === 0`, opens dialog |
| `apps/web/src/components/overview/ConfirmBulkUpdateThemesDialog.tsx` (new) | Per-site collapsible dialog with `skipMajor` toggle baked in |
| `apps/web/src/components/overview/PendingThemeUpdatesGroup.tsx` (new) | Sub-component — per-site grouped checkbox + child rows |
| `apps/web/src/lib/queries/usePendingThemeUpdates.ts` (new) | TanStack query hook — `enabled: dialogOpen` (NOT polling) |
| `apps/web/src/lib/mutations/useBulkUpdateThemes.ts` (new) | TanStack mutation hook — invalidates `['overview']` + `['pendingThemeUpdates']` on success |
| `apps/web/src/lib/semver.ts` (modify) | Rename `isPluginMajorBump` → `isMajorBump` |
| `apps/web/src/types/api.ts` (extend) | Add `PendingThemeUpdateRowSchema`, `OverviewPendingThemeUpdatesResponseSchema`, `OverviewBulkUpdateThemesRequestSchema`, `OverviewBulkUpdateThemesResponseSchema` |
| `apps/web/src/lib/handlers.ts` (extend) | MSW handlers for both new endpoints (mirror P2.7 plugins handlers) |
| `apps/web/src/components/overview/ConfirmBulkUpdatePluginsDialog.tsx` (modify) | Update 1 import: `isPluginMajorBump` → `isMajorBump` |
| `apps/web/src/components/overview/OverviewHeader.tsx` (modify) | Add `<BulkUpdateThemesButton>` after `<BulkUpdatePluginsButton>` |

### 3.4 Dialog state machine (mirror P2.7.1)

```ts
const [open, setOpen]               = useState(false);
const [showAll, setShowAll]         = useState(false);
const [skipMajor, setSkipMajor]     = useState(false);     // default OFF — opt-in
const [checkedKeys, setCheckedKeys] = useState<Set<string>>(new Set());

const visibleRows = useMemo(
  () => skipMajor
    ? rows.filter((r) => !isMajorBump(r.current_version, r.target_version))
    : rows,
  [rows, skipMajor],
);

const allKeys  = useMemo(() => visibleRows.map((r) => `${r.site_id}:${r.stylesheet}`), [visibleRows]);
const grouped  = useMemo(/* group visibleRows by site_label */, [visibleRows]);
const totalCount   = visibleRows.length;
const checkedCount = checkedKeys.size;

// Re-seed checkedKeys on open OR when allKeys changes (toggle flip).
// NO separate useEffect([skipMajor]) — existing dep on allKeys handles it indirectly.
useEffect(() => {
  if (open) {
    setCheckedKeys(new Set(allKeys));
    setShowAll(false);
    cancelRef.current?.focus();
  }
}, [open, allKeys]);
```

### 3.5 SPA tests (~9 new)

**`apps/web/tests/components/overview/ConfirmBulkUpdateThemesDialog.test.tsx`:**
- `opensWithAllRowsPreChecked` — assert all 4 rows visible + "4 selected of 4 available"
- `manualUncheckUpdatesFooterCounter` — uncheck astra, assert "3 selected of 4 available"
- `allUncheckedDisablesPrimary` — uncheck all, assert primary disabled
- `cancelCallsOnCancel` — click Cancel, assert spy called
- `skipMajorToggleOffShowsAllRows` ← **exact name from P2.7.1**
- `skipMajorToggleOnHidesMajorRowsAndUpdatesCounts` ← **exact name from P2.7.1**
- `skipMajorToggleResetsCheckedKeysWhenFlipped` ← **exact name from P2.7.1**

**`apps/web/tests/components/overview/BulkUpdateThemesButton.test.tsx`:**
- `hiddenWhenPendingCountIsZero` — render with `pendingCount={0}`, assert button NOT in document
- `visibleWithCountWhenPendingCountGreaterThanZero` — render with `pendingCount={12}`, assert button visible + label includes "12"

**`apps/web/tests/lib/semver.test.ts`** (modify, NOT add): rename existing 8 `it()` strings + import from `isPluginMajorBump` to `isMajorBump`. Tests themselves don't change.

**`apps/web/tests/components/overview/ConfirmBulkUpdatePluginsDialog.test.tsx`** (modify): rename single import line `isPluginMajorBump` → `isMajorBump` (the rename is transparent — no test logic changes).

**Test fixtures:**

```tsx
const ROWS = [
  { site_id: 1, site_label: 'SmartCoding', stylesheet: 'astra',            theme_name: 'Astra',           current_version: '4.6.3',  target_version: '4.7.0' },
  { site_id: 1, site_label: 'SmartCoding', stylesheet: 'twentytwentyfour', theme_name: 'Twenty TwentyFour', current_version: '1.2',    target_version: '1.3' },
  { site_id: 2, site_label: 'AcmeBlog',    stylesheet: 'blocksy',          theme_name: 'Blocksy',         current_version: '2.0.1',  target_version: '2.0.2' },
];

const ROWS_WITH_MAJOR = [
  { site_id: 1, site_label: 'SmartCoding', stylesheet: 'astra',            theme_name: 'Astra',           current_version: '4.6.3',  target_version: '4.7.0' },
  { site_id: 1, site_label: 'SmartCoding', stylesheet: 'twentytwentyfour', theme_name: 'Twenty TwentyFour', current_version: '1.2',    target_version: '1.3' },
  { site_id: 1, site_label: 'SmartCoding', stylesheet: 'kadence',          theme_name: 'Kadence',         current_version: '1.1.40', target_version: '2.0.0' }, // MAJOR
  { site_id: 2, site_label: 'AcmeBlog',    stylesheet: 'blocksy',          theme_name: 'Blocksy',         current_version: '2.0.1',  target_version: '2.0.2' },
];
```

---

## §4. SPA — TanStack hooks

### 4.1 `usePendingThemeUpdates(dialogOpen: boolean)`

```ts
export function usePendingThemeUpdates(dialogOpen: boolean) {
  return useQuery({
    queryKey: ['pendingThemeUpdates'],
    queryFn: async () => {
      const res = await apiClient.get('/defyn/v1/overview/pending-theme-updates');
      return OverviewPendingThemeUpdatesResponseSchema.parse(res.data);
    },
    enabled: dialogOpen,
    staleTime: 30_000,
  });
}
```

Gated on `dialogOpen` — fetches when dialog opens, stays cached for 30s. Does NOT poll.

### 4.2 `useBulkUpdateThemes()`

```ts
export function useBulkUpdateThemes() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (body: OverviewBulkUpdateThemesRequest) => {
      const res = await apiClient.post('/defyn/v1/overview/bulk-update-themes', body);
      return OverviewBulkUpdateThemesResponseSchema.parse(res.data);
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['overview'] });
      qc.invalidateQueries({ queryKey: ['pendingThemeUpdates'] });
      // NOT ['sites'] — per-site state hasn't changed yet, only AS jobs queued.
    },
  });
}
```

Identical structure to P2.7's `useBulkUpdatePlugins`.

---

## §5. Smoke matrix (8 steps)

| # | Step | Expected | Notes |
|---|---|---|---|
| 1 | `GET /pending-theme-updates` with no auth | 401 | Auth gate |
| 2 | `GET /pending-theme-updates` with valid Bearer | 200, shape `{pending_updates: [...], generated_at}` | May be empty list if prod sites table empty (carry-forward from P2.7) |
| 3 | `POST /bulk-update-themes` with `{updates: []}` | 400 `bulk.empty_updates` | |
| 4 | `POST /bulk-update-themes` with valid pairs | 202 + scheduled_pairs (or 200 if all skipped per zero-sites state) | Carry-forward: prod `wp_defyn_sites` empty for user 1 since P2.6 — happy 202 path may be unreachable |
| 5 | `POST /bulk-update-themes` with all-invalid pairs | 200 + skipped_pairs with 3 distinct reasons | + assert 0 fleet activity log rows (guardrail #4) |
| 6 | `POST /bulk-update-themes` 6× | 6th call returns 429 `bulk.rate_limited` | Carry-forward: Kinsta Redis transient cache may trigger earlier than 6 due to stale buckets (continues from P2.4.1 + P2.5 + P2.6 + P2.7) |
| 7 | SPA Overview header — visit `/overview` post-login | `BulkUpdateThemesButton` visible iff `pending_updates.themes > 0`; HIDDEN otherwise (NOT disabled) | Visual check |
| 8 | SPA dialog — open `ConfirmBulkUpdateThemesDialog` | Skip-major toggle present + default OFF; flipping ON hides major rows + updates title/footer/primary | Visual check (foreclosed if zero-sites state — fall back to SPA test coverage) |

---

## §6. Out of scope / deferred

- **P2.9 (Bulk-jobs entity for cancel/resume/history)** — operator-facing list of past + in-flight bulk operations, with per-row cancel/retry/inspect. Requires schema v7 (`wp_defyn_bulk_jobs` + `wp_defyn_bulk_job_items`).
- **P2.10 (Filtered drill-in `/overview/plugins` + `/overview/themes` routes)** — dedicated pages for plugin + theme update listings, replacing the Overview's count cards.
- **Mass settings toggle (e.g., set `core_allow_major` across all sites)** — extends the bulk pattern to non-destructive settings.
- **Unified bulk dropdown menu** — explicitly rejected per Q2 (side-by-side conditional buttons preferred). Re-evaluate when 4+ bulk actions accumulate on the header.
- **Generic `BulkUpdateDialog<TRow>` abstraction** — explicitly deferred (YAGNI). Re-evaluate when a 3rd bulk dialog arrives (P2.9 cancel-job-set or P2.10 mass-settings).
- **Theme-specific appearance-impact warning copy** — explicitly rejected per Q4 (minimal swap preferred — operators understand the upgrader pattern from plugins).

---

## §7. Plan-bug guardrails (encoded for writing-plans)

1. **shadcn `Button` has NO `destructive` variant.** Primary uses `className="bg-red-600 hover:bg-red-700 text-white"`. Plan-bug carry-forward from P2.7 §7.1.
2. **Activity event ONLY fires when `scheduled_count > 0`.** All-skipped responses are 200 with empty `scheduled_pairs` and NO log row. Test `testZeroValidPairsReturns200AndNoActivityEvent` enforces.
3. **Activity event type is `overview.bulk_theme_update_requested`** — exact string, distinct from P2.7's `overview.bulk_plugin_update_requested`. Per Q3.
4. **Fleet-scoped event** — `site_id = null` (NOT a real site id). Mirrors P2.6 + P2.7. `ActivityLogger::log` must accept null.
5. **Bulk endpoint BYPASSES per-resource `themesUpdate` 6/HR bucket** — operator dialog confirmation IS the safety. Bulk endpoint has its OWN 5/HR bucket distinct from the per-site one.
6. **Mutation invalidates `['overview']` + `['pendingThemeUpdates']`, NOT `['sites']`.** Per-site theme state hasn't changed yet, only AS jobs queued.
7. **`usePendingThemeUpdates` gated on `dialogOpen` flag, NOT polling.** Saves bandwidth + avoids stale modal data.
8. **Bulk button HIDDEN entirely when count=0** (not just disabled). Carry-forward from P2.7 — different from P2.6's SyncAllSitesButton which renders disabled.
9. **Skip-major toggle default `false` (opt-in).** Carry-forward from P2.7.1 guardrail #2.
10. **`ROWS_WITH_MAJOR` test fixture is SEPARATE from base `ROWS`** — DO NOT modify the base fixture. Carry-forward from P2.7.1 guardrail #3.
11. **Toggle JSX placement: BETWEEN body explanatory `<div>` and per-site groups `<div>`** — NOT in footer/title. Carry-forward from P2.7.1 guardrail #4.
12. **`allKeys`, `grouped`, `totalCount` ALL derive from `visibleRows`** — NOT `rows`. Carry-forward from P2.7.1 guardrail #5.
13. **NO new `useEffect([skipMajor])`** — existing `useEffect([open, allKeys])` re-fires indirectly because `allKeys` is derived from `visibleRows`. Carry-forward from P2.7.1 guardrail #6.
14. **`isMajorBump` (renamed from `isPluginMajorBump`) returns `false` for null/undefined/unparseable inputs** — defensive default. Helper itself is unchanged from P2.7.1.
15. **Test isolation:** any new test class extending `AbstractSchemaTestCase` that seeds `defyn_site_themes` MUST call `freshlyActivate('defyn_site_themes')` + explicit `DELETE FROM` in `setUp()`. Carry-forward from P2.7 plan-bug #2 (schema bugs in test helpers).
16. **Test fixtures must match column NOT NULL constraints.** `defyn_site_themes` requires `last_seen_at DATETIME NOT NULL` + `created_at DATETIME NOT NULL`. `defyn_sites` requires `updated_at DATETIME NOT NULL`. Carry-forward from P2.7 plan-bug #2.
17. **Zip-build exclusions must include `*wp-tests-config.php` + `.phpunit.result.cache`** + dev vendors. Carry-forward from P2.6 plan-bug #2.
18. **Kinsta cache discipline:** after every WP-Admin "Replace current" install on Kinsta, click MyKinsta → Tools → Clear cache (busts OPcache + page cache + Redis). Carry-forward from P2.4.1 plan-bug #3.

---

**Spec status:** ready for writing-plans skill. Estimated ~12 TDD tasks (3 backend + 5 SPA + semver rename + version bump + zips/smoke/tag/MEMORY).
