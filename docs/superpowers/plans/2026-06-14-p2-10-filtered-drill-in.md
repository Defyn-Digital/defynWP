# P2.10 — Filtered drill-in pages (`/overview/plugins` + `/overview/themes`) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship two new bookmarkable SPA routes — `/overview/plugins` and `/overview/themes` — that render every pending plugin (or theme) update across the operator's whole fleet as a durable, group-by-site, bulk-select-and-update full page (the same flat data the P2.7/P2.8 bulk dialogs already feed on), with a "Skip major bumps" toggle, a sticky RED "Update N selected" footer, a lightweight final-confirm gate, and navigate-on-success to `/jobs/{job_id}`. Re-point the Overview's plugin + theme count cards at these pages. **SPA-only: no backend, no schema, no connector, no version bump.** Dashboard stays v0.9.0, connector v0.1.7, schema v7 — all unchanged.

**Architecture:** Pure SPA. The existing `GET /defyn/v1/overview/pending-plugin-updates` (P2.7) + `GET /defyn/v1/overview/pending-theme-updates` (P2.8) endpoints already return the exact flat list the pages need. The pages reuse the existing query hooks (`usePendingPluginUpdates(true)` / `usePendingThemeUpdates(true)` — the `dialogOpen` param IS the `enabled` flag, so passing `true` fetches on mount), the existing per-site group components (`PendingPluginUpdatesGroup` / `PendingThemeUpdatesGroup`), the existing `isMajorBump` semver predicate, and the existing mutation hooks (`useBulkUpdatePlugins` / `useBulkUpdateThemes`, which return `job_id: number | null`). Two new shared pieces are extracted: `usePendingUpdatesSelection(rows)` (the selection state machine lifted out of `ConfirmBulkUpdatePluginsDialog`) and `ConfirmBulkUpdateGateDialog` (a lightweight final gate parameterized by `resourceLabel`). The existing P2.7/P2.8 dialogs are NOT refactored onto the shared hook (YAGNI — leave working code alone). Two new routes mount inside the existing `RequireAuth` outlet in `App.tsx`. The `PendingUpdatesWidget` plugin + theme cards re-point to the new pages; the core card is unchanged.

**Tech Stack:** React 18 + TypeScript + TanStack Query v5 + Zod + react-router-dom v6 + Tailwind + shadcn/ui (`Button` variants `default`/`outline`/`ghost` — **NO `destructive` variant**, see plan-bug trap #2) + Vitest + React Testing Library + MSW.

**Spec:** [`docs/superpowers/specs/2026-06-14-p2-10-filtered-drill-in-design.md`](../specs/2026-06-14-p2-10-filtered-drill-in-design.md)

---

## Workflow conventions

- **Branch:** already on **`p2-10-filtered-drill-in`** (current tip `12ed5d5` — the just-committed P2.10 spec). Confirm with `git branch --show-current` before starting. Branch was created off `main` (== `375ef0b` after P2.9 ff merge, tag `p2-9-bulk-jobs-entity-complete`).
- **Each Task = one atomic commit.**
- **Test discipline (TDD):** Step 1 writes the failing test. Step 2 runs it and confirms it fails. Step 3 writes the implementation. Step 4 confirms it passes. Step 5 commits.
- **Test runner (SPA only):** `cd apps/web && pnpm test -- --run`. Scope a single file with e.g. `pnpm test -- --run usePendingUpdatesSelection`.
- **Build/typecheck:** `cd apps/web && pnpm build` (runs `tsc` + `vite build`).
- **Commit message format:** `<type>(p2-10): <description>` where `<type>` ∈ {feat, fix, refactor, docs, test, chore}.
- **All edits adhere to** `~/.claude/rules/common/coding-style.md` + `~/.claude/rules/typescript/coding-style.md` — immutability (new `Set`/array on every state update, never mutate in place), KISS, DRY, YAGNI, explicit prop types via named `interface`, no `console.log`, no `any` (use `unknown` + narrow).
- **NO PHP. NO schema. NO connector. NO version bump.** Dashboard stays **v0.9.0**, connector **v0.1.7**, schema **v7**. No `composer` commands, no zip build, no Kinsta reinstall. Ship is Cloudflare Pages auto-deploy from `main` only.

### Plan-bug traps to internalise before writing any code

These are spec §7 guardrails 1–13 (renumbered 1–13 below to match), plus codebase-reality traps 14–22 found while reading the actual files.

1. **Reuse the existing query hooks with `true`** — call `usePendingPluginUpdates(true)` / `usePendingThemeUpdates(true)` from the pages. The `dialogOpen` param IS the `enabled` flag; passing `true` fetches on mount. Do NOT add a new endpoint or a new query hook. (spec §7 #1)
2. **CRITICAL — shadcn `Button` has NO `destructive` variant** in `apps/web/src/components/ui/button.tsx` (only `default`, `outline`, `ghost`). The footer "Update N selected" button + the gate confirm button use `className="bg-red-600 hover:bg-red-700 text-white"`. Test assertions grep the className for `bg-red-600`. (spec §7 #2)
3. **Skip-major toggle default `false` (opt-in)** — `useState(false)`. Carry-forward from P2.7.1. (spec §7 #3)
4. **`allKeys` / `grouped` / `totalCount` / `siteCount` ALL derive from `visibleRows`** (NOT raw `rows`) so the skip-major filter flows through every count, the footer, and the button label. (spec §7 #4)
5. **Re-seed `checkedKeys` on `allKeys` change** via `useEffect([allKeys])` (the hook's seed effect). NO separate `useEffect([skipMajor])` — `allKeys` already depends on `skipMajor` through `visibleRows`, so the single effect re-fires when the toggle flips. Adding a second effect double-re-seeds. (spec §7 #5)
6. **`isMajorBump` returns `false` for null/undefined/unparseable** — defensive; helper unchanged from P2.8 (`apps/web/src/lib/semver.ts`). Do NOT touch it. (spec §7 #6)
7. **Navigate-on-success gated on `job_id !== null`** — `onSuccess: (data) => { if (data.job_id !== null) navigate(\`/jobs/${data.job_id}\`); }`. Identical contract to P2.9 `BulkUpdate{Plugins,Themes}Button`. (spec §7 #7)
8. **Core card link unchanged** — only the plugin + theme cards in `PendingUpdatesWidget` re-point. The core card stays `to="/sites?filter=has-core-update"`. (spec §7 #8)
9. **Routes inside the `RequireAuth` outlet** in `App.tsx` — both new routes are auth-gated, same as `/overview` and `/jobs`. (spec §7 #9)
10. **`useNavigate` requires Router context** — page tests render inside `MemoryRouter` with a sibling `/jobs/:id` probe route (mirror of P2.9 `BulkUpdatePluginsButton.test.tsx`). The hook tests + gate-dialog tests do NOT need Router. (spec §7 #10)
11. **Gate dialog is a final gate, NOT a re-listing** — it shows only the count summary + Cancel/Confirm, never the per-site collapsibles (the page already shows those). One shared component parameterized by `resourceLabel: 'plugin' | 'theme'`. (spec §7 #11)
12. **No PHP, no schema, no connector, no version bump** — SPA-only. Dashboard stays v0.9.0. No Kinsta reinstall; Cloudflare Pages auto-deploy only. (spec §7 #12)
13. **Pre-existing carry-forward SPA failures (TOLERATE):** `tests/SiteDetail.test.tsx` ×2 + `tests/components/sites/SiteCoreCard.test.tsx` ×2. The full suite must show ONLY these 4 + 0 new. (spec §7 #13)
14. **REALITY — group component prop is `siteLabel` (NOT `label`) AND it requires `onToggleGroup`.** The actual `PendingPluginUpdatesGroup` / `PendingThemeUpdatesGroup` props are `{ siteLabel, rows, checkedKeys, onToggleRow, onToggleGroup }` (verified in the real files). The page must pass BOTH `onToggleRow` and `onToggleGroup`, so `usePendingUpdatesSelection` MUST expose a `toggleGroup(rowKeys: string[], allChecked: boolean)` function (matching the group's `onToggleGroup` signature) in addition to `toggleRow(key)`. The spec's §4 hook contract omitted the exact `toggleGroup` arg shape — use `(rowKeys: string[], allChecked: boolean)` to match the real component.
15. **REALITY — query hook callsite path + export names.** `apiClient.get('/overview/pending-plugin-updates')` (the apiClient prepends the base; do NOT prefix `/defyn/v1` or `/wp-json` at the callsite). The Zod schema exports are `pendingPluginUpdatesSchema` / `pendingThemeUpdatesSchema` (NOT `…ResponseSchema`); the parsed response types are `PendingPluginUpdates` / `PendingThemeUpdates`; the row types are `PendingPluginUpdateRow` / `PendingThemeUpdateRow`. Pages read `query.data?.pending_updates ?? []`.
16. **REALITY — plugin query hook has NO `staleTime`; theme hook has `staleTime: 30_000`.** This is a known minor pre-existing inconsistency. Do NOT "fix" it. Both pages call their hook with `true` regardless; the inconsistency is harmless for a page that mounts once.
17. **REALITY — mutation hook variable shape + return.** `useBulkUpdatePlugins()` / `useBulkUpdateThemes()` accept `{ updates: Array<{ site_id: number; slug: string }> }` and resolve a response that DOES include `job_id: number | null` (verified in `bulkUpdatePluginsResponseSchema` / `bulkUpdateThemesResponseSchema`). Call `mutation.mutate({ updates: selectedPairs }, { onSuccess })`. The mutation hooks ALSO invalidate `['overview']` + `['pending{Plugin,Theme}Updates']` internally on success — the page does NOT need to invalidate anything.
18. **REALITY — MSW test base is `*/wp-json/defyn/v1/…` and default pending handlers return EMPTY lists.** `src/test/handlers.ts` registers `http.get('*/wp-json/defyn/v1/overview/pending-plugin-updates', …)` returning `{ pending_updates: [], generated_at: … }`. The page's populated-state tests MUST override via `server.use(http.get('*/wp-json/defyn/v1/overview/pending-plugin-updates', () => HttpResponse.json({ pending_updates: [...], generated_at: '…' })))` BEFORE render (mirror of `BulkUpdatePluginsButton.test.tsx`). The empty-state test relies on the default handler.
19. **REALITY — `server` is imported from `@/test/setup`** (`import { server } from '@/test/setup';`) and `http` + `HttpResponse` from `'msw'`. `onUnhandledRequest: 'error'` is set, so every endpoint the page touches must have a handler (the defaults cover both GET pending + POST bulk).
20. **REALITY — `Button` forwards `ref`** (it is a `React.forwardRef`), so the gate dialog's `cancelRef` default-focus pattern (`<Button ref={cancelRef} …>`) works exactly like `ConfirmSyncAllDialog`.
21. **REALITY — page loading/empty/error pattern to mirror.** `Jobs.tsx` uses `const { data, isLoading, isError, refetch } = useHook()` then renders: `{isLoading && <div className="h-24 animate-pulse rounded-md bg-gray-100" />}`, `{isError && <div className="rounded-md border border-red-200 bg-red-50 p-4">…<button onClick={() => refetch()}>Try again</button></div>}`, `{data && data.X.length === 0 && <p>empty copy</p>}`, `{data && data.X.length > 0 && <list>}`. Page header: `<div className="min-h-screen p-8"><div className="max-w-3xl mx-auto space-y-4">` with an `<h1>` + a `<Link to="/overview">` back-link. Mirror this structure.
22. **REALITY — `selectedPairs` must preserve insertion order + only include visible+checked rows.** Build it as `visibleRows.filter(r => checkedKeys.has(\`${r.site_id}:${r.slug}\`)).map(r => ({ site_id: r.site_id, slug: r.slug }))` so a checked-but-now-hidden major row (after a toggle flip) can never leak into the POST. (The re-seed effect already prevents stale keys, but deriving from `visibleRows` is the belt-and-braces.)

### Pre-existing carry-forward failures (TOLERATE — do NOT count as new regressions)

SPA (4, since P2.4.1):
- `tests/SiteDetail.test.tsx` × 2
- `tests/components/sites/SiteCoreCard.test.tsx > idle update-available renders version diff + Update button`
- `tests/components/sites/SiteCoreCard.test.tsx > failed state renders red banner + Retry button + tooltip on hover`

The full suite at the start of P2.10 is **268 pass + 4 carry-forward** (per MEMORY P2.9 entry). After P2.10 the new count is 268 + the new tests, still + exactly those 4 carry-forward failures and 0 new.

### Existing-code anchors (read these before starting any task)

All paths under `apps/web/`:

- **`src/components/overview/ConfirmBulkUpdatePluginsDialog.tsx`** (P2.7.1) — the state machine `usePendingUpdatesSelection` extracts. Key shape:
  - `const [skipMajor, setSkipMajor] = useState(false)`
  - `const visibleRows = useMemo(() => skipMajor ? rows.filter((r) => !isMajorBump(r.current_version, r.target_version)) : rows, [rows, skipMajor])`
  - `const allKeys = useMemo(() => visibleRows.map((r) => \`${r.site_id}:${r.slug}\`), [visibleRows])`
  - `const [checkedKeys, setCheckedKeys] = useState<Set<string>>(() => new Set(allKeys))`
  - `useEffect(() => { if (open) { setCheckedKeys(new Set(allKeys)); … } }, [open, allKeys])` — the page has no `open` flag, so the hook re-seeds on `[allKeys]` alone.
  - `grouped = useMemo(() => Map<site_label, rows[]> → Array.from(map.entries()), [visibleRows])`
  - `toggleRow(key)` + `toggleGroup(groupKeys, allChecked)` immutable `Set` updates.
- **`src/components/overview/PendingPluginUpdatesGroup.tsx`** (P2.7) — props `{ siteLabel, rows, checkedKeys, onToggleRow, onToggleGroup }`. `rowKeys = rows.map((r) => \`${r.site_id}:${r.slug}\`)`, `allChecked = rowKeys.every((k) => checkedKeys.has(k))`. Row checkbox `aria-label={\`${row.plugin_name} ${row.current_version} to ${row.target_version}\`}`. Group checkbox `aria-label={\`Toggle all plugins on ${siteLabel}\`}`. Reused verbatim by the page.
- **`src/components/overview/PendingThemeUpdatesGroup.tsx`** (P2.8) — identical shape, `theme_name`, `data-testid="theme-group"`, `aria-label={\`Toggle all themes on ${siteLabel}\`}`. Reused verbatim.
- **`src/lib/queries/usePendingPluginUpdates.ts`** (P2.7) — `usePendingPluginUpdates(dialogOpen: boolean)`, queryKey `['pendingPluginUpdates']`, `apiClient.get('/overview/pending-plugin-updates')`, `enabled: dialogOpen`, NO staleTime.
- **`src/lib/queries/usePendingThemeUpdates.ts`** (P2.8) — `usePendingThemeUpdates(dialogOpen: boolean)`, queryKey `['pendingThemeUpdates']`, `apiClient.get('/overview/pending-theme-updates')`, `enabled: dialogOpen`, `staleTime: 30_000`.
- **`src/lib/mutations/useBulkUpdatePlugins.ts`** (P2.7) — `useBulkUpdatePlugins()`, `mutate({ updates })`, returns `BulkUpdatePluginsResponse` (has `job_id`), invalidates `['overview']` + `['pendingPluginUpdates']`.
- **`src/lib/mutations/useBulkUpdateThemes.ts`** (P2.8) — `useBulkUpdateThemes()`, `mutate({ updates })`, returns `BulkUpdateThemesResponse` (has `job_id`), invalidates `['overview']` + `['pendingThemeUpdates']`.
- **`src/components/overview/BulkUpdatePluginsButton.tsx`** (P2.9) — navigate-on-success: `const navigate = useNavigate(); … mutation.mutate({ updates: selectedPairs }, { onSuccess: (data) => { if (data.job_id !== null) navigate(\`/jobs/${data.job_id}\`); } })`. The pages replicate this.
- **`src/components/overview/BulkUpdateThemesButton.tsx`** (P2.9) — theme mirror of the above.
- **`src/components/overview/ConfirmSyncAllDialog.tsx`** (P2.6) — `cancelRef` default-focus: `const cancelRef = useRef<HTMLButtonElement>(null); useEffect(() => { if (open) cancelRef.current?.focus(); }, [open]); … <Button ref={cancelRef} variant="outline" onClick={onCancel}>Cancel</Button>`. The gate dialog mirrors this. Root: `<div role="alertdialog" aria-modal="true" aria-labelledby={titleId} className="…">`.
- **`src/components/overview/PendingUpdatesWidget.tsx`** (P2.5) — three `CountCard` with `to` props. Plugin: `to="/sites?filter=has-plugin-updates"`. Theme: `to="/sites?filter=has-theme-updates"`. Core: `to="/sites?filter=has-core-update"`. Task 5 re-points plugin + theme; core unchanged.
- **`src/App.tsx`** — `<Routes>` with a `<Route element={<RequireAuth />}>` wrapper containing `/`, `/overview`, `/sites`, `/sites/add`, `/sites/:id`, `/jobs`, `/jobs/:id`, `/activity`. Task 5 adds `/overview/plugins` + `/overview/themes` inside the wrapper.
- **`src/types/api.ts`** — existing exports: `pendingPluginUpdateRowSchema`, `PendingPluginUpdateRow`, `pendingPluginUpdatesSchema`, `PendingPluginUpdates`, `pendingThemeUpdateRowSchema`, `PendingThemeUpdateRow`, `pendingThemeUpdatesSchema`, `PendingThemeUpdates`. Both row types share `{ site_id, site_label, slug, current_version, target_version }`; the name field differs (`plugin_name` vs `theme_name`). No new types needed.
- **`src/lib/semver.ts`** — `export function isMajorBump(current, target): boolean`. Used by the shared hook.
- **`src/routes/Jobs.tsx`** (P2.9) — the loading/empty/error/populated page pattern + header + back-link to mirror (anchor #21 above).
- **`tests/routes/Jobs.test.tsx`** (P2.9) — the page test wrapper: `QueryClientProvider` + `MemoryRouter initialEntries` + inner `Routes/Route`, `server.use(http.get('*/wp-json/…'))` overrides. Mirror this.
- **`tests/components/overview/BulkUpdatePluginsButton.test.tsx`** (P2.9) — the `MemoryRouter` + `/jobs/:id` probe route + `server.use` populated-handler + navigate assertion (`expect(screen.getByText('JOB DETAIL PROBE')).toBeInTheDocument()`). The page tests' navigate assertion mirrors this.
- **`tests/components/overview/ConfirmBulkUpdatePluginsDialog.test.tsx`** (P2.7.1) — RTL `render` + `screen.getByRole('checkbox', { name: /…/i })` + `fireEvent.click` patterns + the `bg-red-600` className assertion + the skipMajor toggle test trio. The hook tests + gate-dialog tests mirror these query patterns.
- **`src/test/handlers.ts`** + **`src/test/setup.ts`** — MSW defaults (anchors #18/#19).

---

## File structure overview

### SPA (apps/web) — new files

| Path | Responsibility |
|---|---|
| `src/lib/usePendingUpdatesSelection.ts` | Shared selection state machine hook — `skipMajor`, `visibleRows`, `grouped`, `checkedKeys`, `toggleRow`, `toggleGroup`, derived counts, `selectedPairs`, re-seed `useEffect([allKeys])`. Generic over `{ site_id, site_label, slug, current_version, target_version }`. |
| `src/components/overview/ConfirmBulkUpdateGateDialog.tsx` | Shared lightweight final-confirm dialog — `resourceLabel`-parameterized copy, `cancelRef` default focus, RED confirm, NO listing. |
| `src/routes/OverviewPlugins.tsx` | `/overview/plugins` page. |
| `src/routes/OverviewThemes.tsx` | `/overview/themes` page (theme mirror). |
| `tests/lib/usePendingUpdatesSelection.test.tsx` | Hook unit tests. |
| `tests/components/overview/ConfirmBulkUpdateGateDialog.test.tsx` | Gate dialog tests. |
| `tests/routes/OverviewPlugins.test.tsx` | Plugin page tests (loading/empty/populated/skip-major/uncheck/confirm/navigate). |
| `tests/routes/OverviewThemes.test.tsx` | Theme page tests. |

### SPA (apps/web) — modified files

| Path | What changes |
|---|---|
| `src/App.tsx` | Add `/overview/plugins` + `/overview/themes` routes inside the `RequireAuth` outlet. |
| `src/components/overview/PendingUpdatesWidget.tsx` | Plugin card `to` → `/overview/plugins`; theme card `to` → `/overview/themes`; core card unchanged. |
| `tests/components/overview/PendingUpdatesWidget.test.tsx` | Update the 2 re-pointed card-link assertions + their `Routes` so the new destinations resolve. |

**No backend files. No `defyn-dashboard.php`, `composer.json`, `readme.txt`, schema, connector, or version changes.**

---

## Task 1 — `usePendingUpdatesSelection(rows)` shared selection hook

**Files:**
- Create: `apps/web/src/lib/usePendingUpdatesSelection.ts`
- Test: `apps/web/tests/lib/usePendingUpdatesSelection.test.tsx` (CREATE)

- [ ] **Step 1: Write the failing tests**

Create `apps/web/tests/lib/usePendingUpdatesSelection.test.tsx`:

```tsx
import { describe, it, expect } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { usePendingUpdatesSelection } from '@/lib/usePendingUpdatesSelection';

// Generic rows — the hook only touches the common fields, so this fixture
// stands in for both PendingPluginUpdateRow and PendingThemeUpdateRow.
const ROWS = [
  { site_id: 1, site_label: 'SmartCoding', slug: 'akismet', current_version: '5.3',  target_version: '5.3.1' },
  { site_id: 1, site_label: 'SmartCoding', slug: 'yoast',   current_version: '22.5', target_version: '22.6' },
  { site_id: 2, site_label: 'AcmeBlog',    slug: 'jetpack', current_version: '13.1', target_version: '13.2' },
];

const ROWS_WITH_MAJOR = [
  { site_id: 1, site_label: 'SmartCoding', slug: 'akismet',   current_version: '5.3',    target_version: '5.3.1' },
  { site_id: 1, site_label: 'SmartCoding', slug: 'yoast',     current_version: '22.5',   target_version: '22.6' },
  { site_id: 1, site_label: 'SmartCoding', slug: 'elementor', current_version: '3.18.2', target_version: '4.0.0' }, // MAJOR
  { site_id: 2, site_label: 'AcmeBlog',    slug: 'jetpack',   current_version: '13.1',   target_version: '13.2' },
];

describe('usePendingUpdatesSelection', () => {
  it('seedsAllRowsCheckedOnMount', () => {
    const { result } = renderHook(() => usePendingUpdatesSelection(ROWS));
    expect(result.current.totalCount).toBe(3);
    expect(result.current.siteCount).toBe(2);
    expect(result.current.checkedCount).toBe(3);
    expect(result.current.selectedPairs).toHaveLength(3);
  });

  it('groupsRowsBySiteLabelPreservingServerOrder', () => {
    const { result } = renderHook(() => usePendingUpdatesSelection(ROWS));
    const labels = result.current.grouped.map(([label]) => label);
    expect(labels).toEqual(['SmartCoding', 'AcmeBlog']);
    expect(result.current.grouped[0][1]).toHaveLength(2);
    expect(result.current.grouped[1][1]).toHaveLength(1);
  });

  it('toggleRowUnchecksAndReChecksASingleKey', () => {
    const { result } = renderHook(() => usePendingUpdatesSelection(ROWS));
    act(() => result.current.toggleRow('1:akismet'));
    expect(result.current.checkedCount).toBe(2);
    expect(result.current.checkedKeys.has('1:akismet')).toBe(false);
    expect(result.current.selectedPairs).toEqual([
      { site_id: 1, slug: 'yoast' },
      { site_id: 2, slug: 'jetpack' },
    ]);
    act(() => result.current.toggleRow('1:akismet'));
    expect(result.current.checkedCount).toBe(3);
  });

  it('toggleGroupChecksAndUnchecksAllRowsForASite', () => {
    const { result } = renderHook(() => usePendingUpdatesSelection(ROWS));
    // Uncheck the whole SmartCoding group (2 rows, currently all checked).
    act(() => result.current.toggleGroup(['1:akismet', '1:yoast'], true));
    expect(result.current.checkedCount).toBe(1);
    expect(result.current.checkedKeys.has('2:jetpack')).toBe(true);
    // Re-check it.
    act(() => result.current.toggleGroup(['1:akismet', '1:yoast'], false));
    expect(result.current.checkedCount).toBe(3);
  });

  it('skipMajorDefaultsOffShowingAllRows', () => {
    const { result } = renderHook(() => usePendingUpdatesSelection(ROWS_WITH_MAJOR));
    expect(result.current.skipMajor).toBe(false);
    expect(result.current.totalCount).toBe(4);
    expect(result.current.checkedCount).toBe(4);
  });

  it('skipMajorOnHidesMajorRowsAndReDerivesCounts', () => {
    const { result } = renderHook(() => usePendingUpdatesSelection(ROWS_WITH_MAJOR));
    act(() => result.current.setSkipMajor(true));
    expect(result.current.totalCount).toBe(3); // elementor 3.x → 4.x hidden
    expect(result.current.siteCount).toBe(2);
    expect(result.current.visibleRows.some((r) => r.slug === 'elementor')).toBe(false);
    // selectedPairs never contains the hidden major row.
    expect(result.current.selectedPairs.some((p) => p.slug === 'elementor')).toBe(false);
  });

  it('skipMajorFlipReSeedsCheckedKeysToVisibleRows', () => {
    const { result } = renderHook(() => usePendingUpdatesSelection(ROWS_WITH_MAJOR));
    // Manually uncheck akismet (toggle still OFF): 3 of 4 checked.
    act(() => result.current.toggleRow('1:akismet'));
    expect(result.current.checkedCount).toBe(3);
    expect(result.current.totalCount).toBe(4);
    // Flip skipMajor ON — re-seed to all 3 visible rows (akismet checked again).
    act(() => result.current.setSkipMajor(true));
    expect(result.current.checkedCount).toBe(3);
    expect(result.current.totalCount).toBe(3);
    expect(result.current.checkedKeys.has('1:akismet')).toBe(true);
  });

  it('reSeedsWhenRowsIdentityChanges', () => {
    const { result, rerender } = renderHook(
      ({ rows }) => usePendingUpdatesSelection(rows),
      { initialProps: { rows: ROWS } },
    );
    act(() => result.current.toggleRow('1:akismet'));
    expect(result.current.checkedCount).toBe(2);
    // A fresh fetch yields a new rows array → re-seed to all checked.
    rerender({ rows: [...ROWS] });
    expect(result.current.checkedCount).toBe(3);
  });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd apps/web && pnpm test -- --run usePendingUpdatesSelection`

Expected: 8 FAILED with a module-resolution error (hook file does not exist yet).

- [ ] **Step 3: Create the hook**

Create `apps/web/src/lib/usePendingUpdatesSelection.ts`:

```ts
import { useEffect, useMemo, useState } from 'react';
import { isMajorBump } from '@/lib/semver';

/**
 * P2.10 — shared selection state machine for the filtered drill-in pages
 * (/overview/plugins + /overview/themes).
 *
 * Lifted out of P2.7.1's ConfirmBulkUpdatePluginsDialog so both pages share
 * one implementation. Generic over the common pending-update row shape — it
 * only touches { site_id, site_label, slug, current_version, target_version },
 * so a PendingPluginUpdateRow or PendingThemeUpdateRow both satisfy it (the
 * differing plugin_name / theme_name field is ignored here).
 *
 * State machine (mirror of the dialog, plan-bug traps #3–#5):
 *   - skipMajor (default OFF) gates a `visibleRows` filter via isMajorBump.
 *   - allKeys / grouped / totalCount / siteCount ALL derive from visibleRows.
 *   - checkedKeys re-seeds to all-visible whenever allKeys changes (toggle
 *     flip or a fresh fetch) via a single useEffect([allKeys]). No separate
 *     effect for [skipMajor] — allKeys already depends on skipMajor.
 *
 * Spec: docs/superpowers/specs/2026-06-14-p2-10-filtered-drill-in-design.md § 4
 */
export interface SelectionRow {
  site_id: number;
  site_label: string;
  slug: string;
  current_version: string;
  target_version: string | null;
}

export interface PendingUpdatesSelection {
  skipMajor: boolean;
  setSkipMajor: (v: boolean) => void;
  visibleRows: SelectionRow[];
  grouped: Array<[string, SelectionRow[]]>;
  checkedKeys: Set<string>;
  toggleRow: (key: string) => void;
  toggleGroup: (rowKeys: string[], allChecked: boolean) => void;
  totalCount: number;
  siteCount: number;
  checkedCount: number;
  selectedPairs: Array<{ site_id: number; slug: string }>;
}

export function usePendingUpdatesSelection<R extends SelectionRow>(
  rows: R[],
): PendingUpdatesSelection {
  const [skipMajor, setSkipMajor] = useState(false);

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

  const [checkedKeys, setCheckedKeys] = useState<Set<string>>(() => new Set(allKeys));

  // Re-seed to all-visible whenever the visible key set changes (toggle flip
  // OR a fresh fetch produces a new rows array). Single effect — plan-bug #5.
  useEffect(() => {
    setCheckedKeys(new Set(allKeys));
  }, [allKeys]);

  const grouped = useMemo(() => {
    const map = new Map<string, R[]>();
    for (const row of visibleRows) {
      const list = map.get(row.site_label) ?? [];
      list.push(row);
      map.set(row.site_label, list);
    }
    return Array.from(map.entries());
  }, [visibleRows]);

  const toggleRow = (key: string): void => {
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

  const toggleGroup = (rowKeys: string[], allChecked: boolean): void => {
    setCheckedKeys((prev) => {
      const next = new Set(prev);
      if (allChecked) {
        rowKeys.forEach((k) => next.delete(k));
      } else {
        rowKeys.forEach((k) => next.add(k));
      }
      return next;
    });
  };

  // Derive selectedPairs from visibleRows ∩ checkedKeys so a checked-but-now-
  // hidden major row can never leak into the POST (plan-bug #22).
  const selectedPairs = useMemo(
    () =>
      visibleRows
        .filter((r) => checkedKeys.has(`${r.site_id}:${r.slug}`))
        .map((r) => ({ site_id: r.site_id, slug: r.slug })),
    [visibleRows, checkedKeys],
  );

  return {
    skipMajor,
    setSkipMajor,
    visibleRows,
    grouped,
    checkedKeys,
    toggleRow,
    toggleGroup,
    totalCount: visibleRows.length,
    siteCount: grouped.length,
    checkedCount: checkedKeys.size,
    selectedPairs,
  };
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd apps/web && pnpm test -- --run usePendingUpdatesSelection`

Expected: 8 PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/lib/usePendingUpdatesSelection.ts \
        apps/web/tests/lib/usePendingUpdatesSelection.test.tsx
git commit -m "feat(p2-10): usePendingUpdatesSelection shared selection hook

Extracts the P2.7.1 dialog's selection state machine into a reusable
hook for the /overview/plugins + /overview/themes drill-in pages.
Generic over the common pending-update row shape; exposes skipMajor +
visibleRows + grouped + checkedKeys + toggleRow + toggleGroup + derived
counts + selectedPairs. Re-seeds checkedKeys on allKeys change via a
single useEffect (plan-bug #5). selectedPairs derives from
visibleRows ∩ checkedKeys so hidden-major rows never leak into the POST.

The existing P2.7/P2.8 dialogs are NOT refactored onto it (YAGNI).

8 unit tests: seed-all-checked, group-by-site order, toggleRow,
toggleGroup, skipMajor default OFF, skipMajor ON hides majors +
re-derives counts, skipMajor flip re-seeds, fresh-rows re-seed.

Per spec § 4."
```

---

## Task 2 — `ConfirmBulkUpdateGateDialog` shared final-confirm gate

**Files:**
- Create: `apps/web/src/components/overview/ConfirmBulkUpdateGateDialog.tsx`
- Test: `apps/web/tests/components/overview/ConfirmBulkUpdateGateDialog.test.tsx` (CREATE)

- [ ] **Step 1: Write the failing tests**

Create `apps/web/tests/components/overview/ConfirmBulkUpdateGateDialog.test.tsx`:

```tsx
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { ConfirmBulkUpdateGateDialog } from '@/components/overview/ConfirmBulkUpdateGateDialog';

describe('ConfirmBulkUpdateGateDialog', () => {
  it('rendersNothingWhenClosed', () => {
    const { container } = render(
      <ConfirmBulkUpdateGateDialog
        open={false}
        resourceLabel="plugin"
        count={5}
        siteCount={3}
        onCancel={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );
    expect(container).toBeEmptyDOMElement();
  });

  it('rendersPluginTitleAndConfirmLabelWithCounts', () => {
    render(
      <ConfirmBulkUpdateGateDialog
        open
        resourceLabel="plugin"
        count={5}
        siteCount={3}
        onCancel={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );
    expect(screen.getByText(/update 5 plugins across 3 sites\?/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /^update 5 plugins$/i })).toBeInTheDocument();
  });

  it('rendersThemeCopyWhenResourceLabelIsTheme', () => {
    render(
      <ConfirmBulkUpdateGateDialog
        open
        resourceLabel="theme"
        count={2}
        siteCount={1}
        onCancel={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );
    expect(screen.getByText(/update 2 themes across 1 sites\?/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /^update 2 themes$/i })).toBeInTheDocument();
    // Body mentions the theme upgrader, not plugin.
    expect(screen.getByText(/theme upgrader/i)).toBeInTheDocument();
  });

  it('confirmButtonUsesRedDestructiveStyling', () => {
    render(
      <ConfirmBulkUpdateGateDialog
        open
        resourceLabel="plugin"
        count={5}
        siteCount={3}
        onCancel={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );
    const confirm = screen.getByRole('button', { name: /^update 5 plugins$/i });
    expect(confirm.className).toMatch(/bg-red-600/);
  });

  it('cancelHasDefaultFocus', () => {
    render(
      <ConfirmBulkUpdateGateDialog
        open
        resourceLabel="plugin"
        count={5}
        siteCount={3}
        onCancel={vi.fn()}
        onConfirm={vi.fn()}
      />,
    );
    expect(screen.getByRole('button', { name: /^cancel$/i })).toHaveFocus();
  });

  it('cancelAndConfirmCallTheirHandlers', () => {
    const onCancel = vi.fn();
    const onConfirm = vi.fn();
    render(
      <ConfirmBulkUpdateGateDialog
        open
        resourceLabel="plugin"
        count={5}
        siteCount={3}
        onCancel={onCancel}
        onConfirm={onConfirm}
      />,
    );
    fireEvent.click(screen.getByRole('button', { name: /^cancel$/i }));
    expect(onCancel).toHaveBeenCalledTimes(1);
    fireEvent.click(screen.getByRole('button', { name: /^update 5 plugins$/i }));
    expect(onConfirm).toHaveBeenCalledTimes(1);
  });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd apps/web && pnpm test -- --run ConfirmBulkUpdateGateDialog`

Expected: 6 FAILED with a module-resolution error.

- [ ] **Step 3: Create the gate dialog**

Create `apps/web/src/components/overview/ConfirmBulkUpdateGateDialog.tsx`:

```tsx
import { useEffect, useRef } from 'react';
import { Button } from '@/components/ui/button';

interface ConfirmBulkUpdateGateDialogProps {
  open: boolean;
  resourceLabel: 'plugin' | 'theme';
  count: number;
  siteCount: number;
  onCancel: () => void;
  onConfirm: () => void;
}

/**
 * P2.10 — lightweight final-confirm gate for the filtered drill-in pages.
 *
 * NOT a re-listing (plan-bug trap #11) — the page already shows every pair.
 * Shows only the count summary + Cancel/Confirm. One shared component
 * parameterized by resourceLabel; copy swaps plugin ↔ theme.
 *
 * RED-tier confirm via className override (Button has no destructive
 * variant — plan-bug trap #2). Cancel default focus via cancelRef
 * (mirror of P2.6 ConfirmSyncAllDialog).
 *
 * Spec: docs/superpowers/specs/2026-06-14-p2-10-filtered-drill-in-design.md § 3
 */
export function ConfirmBulkUpdateGateDialog({
  open,
  resourceLabel,
  count,
  siteCount,
  onCancel,
  onConfirm,
}: ConfirmBulkUpdateGateDialogProps): JSX.Element | null {
  const cancelRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (open) {
      cancelRef.current?.focus();
    }
  }, [open]);

  if (!open) {
    return null;
  }

  const titleId = 'bulk-update-gate-confirm-title';
  const plural = `${resourceLabel}s`;

  return (
    <div
      role="alertdialog"
      aria-modal="true"
      aria-labelledby={titleId}
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
    >
      <div className="w-full max-w-md rounded-lg border border-zinc-200 bg-white p-6 shadow-xl">
        <h2 id={titleId} className="text-lg font-semibold text-zinc-900">
          Update {count} {plural} across {siteCount} sites?
        </h2>

        <p className="mt-3 text-sm text-zinc-700">
          This runs the {resourceLabel} upgrader on every selected pair. Each
          site briefly enters maintenance mode during its update.
        </p>

        <div className="mt-5 flex items-center justify-end gap-2">
          <Button ref={cancelRef} variant="outline" onClick={onCancel}>
            Cancel
          </Button>
          <Button
            className="bg-red-600 hover:bg-red-700 text-white"
            onClick={onConfirm}
          >
            Update {count} {plural}
          </Button>
        </div>
      </div>
    </div>
  );
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd apps/web && pnpm test -- --run ConfirmBulkUpdateGateDialog`

Expected: 6 PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/components/overview/ConfirmBulkUpdateGateDialog.tsx \
        apps/web/tests/components/overview/ConfirmBulkUpdateGateDialog.test.tsx
git commit -m "feat(p2-10): ConfirmBulkUpdateGateDialog shared final-confirm gate

Lightweight final gate for the drill-in pages — NOT a re-listing (the
page already shows every pair). resourceLabel-parameterized copy (plugin
↔ theme), RED confirm via className override (Button has no destructive
variant), Cancel default focus via cancelRef (mirror of P2.6
ConfirmSyncAllDialog).

6 tests: closed renders nothing, plugin title + confirm label, theme
copy, red styling, cancel default focus, cancel/confirm handlers.

Per spec § 3 + guardrail #11."
```

---

## Task 3 — `OverviewPlugins.tsx` route

**Files:**
- Create: `apps/web/src/routes/OverviewPlugins.tsx`
- Test: `apps/web/tests/routes/OverviewPlugins.test.tsx` (CREATE)

- [ ] **Step 1: Write the failing tests**

Create `apps/web/tests/routes/OverviewPlugins.test.tsx`:

```tsx
import { describe, it, expect } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import { server } from '@/test/setup';
import OverviewPlugins from '@/routes/OverviewPlugins';

const POPULATED = {
  pending_updates: [
    { site_id: 1, site_label: 'SmartCoding', slug: 'akismet',   plugin_name: 'Akismet',   current_version: '5.3',    target_version: '5.3.1' },
    { site_id: 1, site_label: 'SmartCoding', slug: 'elementor', plugin_name: 'Elementor', current_version: '3.18.2', target_version: '4.0.0' }, // MAJOR
    { site_id: 2, site_label: 'AcmeBlog',    slug: 'jetpack',   plugin_name: 'Jetpack',   current_version: '13.1',   target_version: '13.2' },
  ],
  generated_at: '2026-06-14 10:00:00',
};

function usePopulated() {
  server.use(
    http.get('*/wp-json/defyn/v1/overview/pending-plugin-updates', () =>
      HttpResponse.json(POPULATED),
    ),
  );
}

function renderPage() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/overview/plugins']}>
        <Routes>
          <Route path="/overview/plugins" element={<OverviewPlugins />} />
          <Route path="/overview" element={<div>OVERVIEW PROBE</div>} />
          <Route path="/jobs/:id" element={<div>JOB DETAIL PROBE</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('OverviewPlugins', () => {
  it('rendersEmptyStateFromDefaultHandler', async () => {
    // Default MSW handler returns pending_updates: [].
    renderPage();
    await waitFor(() =>
      expect(screen.getByText(/no pending plugin updates across your fleet/i)).toBeInTheDocument(),
    );
    // No footer button in the empty state.
    expect(screen.queryByRole('button', { name: /update .* selected/i })).not.toBeInTheDocument();
  });

  it('rendersPopulatedListGroupedBySiteWithFooterCounts', async () => {
    usePopulated();
    renderPage();
    await waitFor(() =>
      expect(screen.getByRole('checkbox', { name: /akismet/i })).toBeInTheDocument(),
    );
    expect(screen.getByRole('checkbox', { name: /elementor/i })).toBeInTheDocument();
    expect(screen.getByRole('checkbox', { name: /jetpack/i })).toBeInTheDocument();
    expect(screen.getByText(/3 selected of 3 available/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /update 3 selected/i })).toBeInTheDocument();
  });

  it('backLinkPointsToOverview', async () => {
    usePopulated();
    renderPage();
    await waitFor(() =>
      expect(screen.getByRole('link', { name: /overview/i })).toBeInTheDocument(),
    );
    expect(screen.getByRole('link', { name: /overview/i })).toHaveAttribute('href', '/overview');
  });

  it('uncheckingARowUpdatesTheFooterCounter', async () => {
    usePopulated();
    renderPage();
    await waitFor(() => expect(screen.getByText(/3 selected of 3 available/i)).toBeInTheDocument());
    fireEvent.click(screen.getByRole('checkbox', { name: /akismet/i }));
    expect(screen.getByText(/2 selected of 3 available/i)).toBeInTheDocument();
  });

  it('skipMajorToggleHidesMajorRowsAndReDerivesCounts', async () => {
    usePopulated();
    renderPage();
    await waitFor(() => expect(screen.getByRole('checkbox', { name: /elementor/i })).toBeInTheDocument());
    fireEvent.click(screen.getByRole('checkbox', { name: /skip major bumps/i }));
    expect(screen.queryByRole('checkbox', { name: /elementor/i })).not.toBeInTheDocument();
    expect(screen.getByText(/2 selected of 2 available/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /update 2 selected/i })).toBeInTheDocument();
  });

  it('footerButtonDisabledWhenNothingChecked', async () => {
    usePopulated();
    renderPage();
    await waitFor(() => expect(screen.getByText(/3 selected of 3 available/i)).toBeInTheDocument());
    fireEvent.click(screen.getByRole('checkbox', { name: /akismet/i }));
    fireEvent.click(screen.getByRole('checkbox', { name: /elementor/i }));
    fireEvent.click(screen.getByRole('checkbox', { name: /jetpack/i }));
    const footerBtn = screen.getByRole('button', { name: /update 0 selected/i });
    expect(footerBtn).toBeDisabled();
  });

  it('footerButtonOpensGateThenConfirmNavigatesToJobDetail', async () => {
    usePopulated();
    // POST returns job_id 77 → navigate to /jobs/77.
    server.use(
      http.post('*/wp-json/defyn/v1/overview/bulk-update-plugins', async ({ request }) => {
        const body = (await request.json()) as { updates: Array<{ site_id: number; slug: string }> };
        return HttpResponse.json(
          {
            job_id: 77,
            scheduled_count: body.updates.length,
            skipped_count: 0,
            scheduled_pairs: body.updates,
            skipped_pairs: [],
            scheduled_at: '2026-06-14 10:00:42',
          },
          { status: 202 },
        );
      }),
    );
    renderPage();
    await waitFor(() => expect(screen.getByText(/3 selected of 3 available/i)).toBeInTheDocument());

    // Footer button opens the gate (NOT a re-listing).
    fireEvent.click(screen.getByRole('button', { name: /update 3 selected/i }));
    await waitFor(() =>
      expect(screen.getByText(/update 3 plugins across 2 sites\?/i)).toBeInTheDocument(),
    );

    // Confirm in the gate → POST → navigate.
    fireEvent.click(screen.getByRole('button', { name: /^update 3 plugins$/i }));
    await waitFor(() => expect(screen.getByText('JOB DETAIL PROBE')).toBeInTheDocument());
  });

  it('gateCancelClosesWithoutNavigating', async () => {
    usePopulated();
    renderPage();
    await waitFor(() => expect(screen.getByText(/3 selected of 3 available/i)).toBeInTheDocument());
    fireEvent.click(screen.getByRole('button', { name: /update 3 selected/i }));
    await waitFor(() =>
      expect(screen.getByText(/update 3 plugins across 2 sites\?/i)).toBeInTheDocument(),
    );
    fireEvent.click(screen.getByRole('button', { name: /^cancel$/i }));
    await waitFor(() =>
      expect(screen.queryByText(/update 3 plugins across 2 sites\?/i)).not.toBeInTheDocument(),
    );
    // Still on the page, not navigated.
    expect(screen.queryByText('JOB DETAIL PROBE')).not.toBeInTheDocument();
    expect(screen.getByText(/3 selected of 3 available/i)).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd apps/web && pnpm test -- --run OverviewPlugins`

Expected: 8 FAILED with a module-resolution error.

- [ ] **Step 3: Create the page**

Create `apps/web/src/routes/OverviewPlugins.tsx`:

```tsx
import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Button } from '@/components/ui/button';
import { PendingPluginUpdatesGroup } from '@/components/overview/PendingPluginUpdatesGroup';
import { ConfirmBulkUpdateGateDialog } from '@/components/overview/ConfirmBulkUpdateGateDialog';
import { usePendingPluginUpdates } from '@/lib/queries/usePendingPluginUpdates';
import { useBulkUpdatePlugins } from '@/lib/mutations/useBulkUpdatePlugins';
import { usePendingUpdatesSelection } from '@/lib/usePendingUpdatesSelection';

/**
 * P2.10 — /overview/plugins drill-in page.
 *
 * Durable full-page view of every pending plugin update across the fleet:
 * header + back-link + skip-major toggle + per-site PendingPluginUpdatesGroup
 * list + sticky RED "Update N selected" footer + lightweight gate +
 * navigate-on-success to /jobs/{job_id}. Reuses the P2.7 query hook (called
 * with `true` to fetch on mount), the shared selection hook, the existing
 * group component, and the P2.9 bulk mutation hook.
 *
 * Spec: docs/superpowers/specs/2026-06-14-p2-10-filtered-drill-in-design.md § 2-3
 */
export default function OverviewPlugins() {
  const navigate = useNavigate();
  const { data, isLoading, isError, refetch } = usePendingPluginUpdates(true);
  const mutation = useBulkUpdatePlugins();
  const [gateOpen, setGateOpen] = useState(false);

  const rows = data?.pending_updates ?? [];
  const selection = usePendingUpdatesSelection(rows);

  const handleConfirm = (): void => {
    setGateOpen(false);
    if (selection.selectedPairs.length > 0) {
      mutation.mutate(
        { updates: selection.selectedPairs },
        {
          onSuccess: (res) => {
            // Guardrail #7 — non-null job_id navigates to the tracked job.
            if (res.job_id !== null) {
              navigate(`/jobs/${res.job_id}`);
            }
          },
        },
      );
    }
  };

  return (
    <div className="min-h-screen p-8 pb-24">
      <div className="mx-auto max-w-3xl space-y-4">
        <div className="flex items-baseline justify-between">
          <div className="flex items-baseline gap-3">
            <Link
              to="/overview"
              className="text-sm text-zinc-600 underline-offset-4 hover:underline"
            >
              ← Overview
            </Link>
            <h1 className="text-2xl font-semibold">Plugin updates across your fleet</h1>
          </div>
          {data && rows.length > 0 && (
            <span className="text-sm text-zinc-600">{selection.totalCount} pending</span>
          )}
        </div>

        {isLoading && <div className="h-24 animate-pulse rounded-md bg-gray-100" />}

        {isError && (
          <div className="rounded-md border border-red-200 bg-red-50 p-4">
            <p className="text-sm text-red-800">Failed to load pending plugin updates.</p>
            <button
              onClick={() => refetch()}
              className="mt-2 rounded-md border border-red-200 px-3 py-1 text-sm text-red-800"
            >
              Try again
            </button>
          </div>
        )}

        {data && rows.length === 0 && (
          <p className="text-sm text-zinc-600">
            No pending plugin updates across your fleet.
          </p>
        )}

        {data && rows.length > 0 && (
          <>
            <label className="flex items-center gap-2 text-sm text-zinc-700">
              <input
                type="checkbox"
                checked={selection.skipMajor}
                onChange={(e) => selection.setSkipMajor(e.target.checked)}
              />
              Skip major bumps
              <span className="text-xs text-zinc-500">
                (hide updates where the major version changes, e.g. 1.x → 2.x)
              </span>
            </label>

            <div className="space-y-2">
              {selection.grouped.map(([label, groupRows]) => (
                <PendingPluginUpdatesGroup
                  key={label}
                  siteLabel={label}
                  rows={groupRows}
                  checkedKeys={selection.checkedKeys}
                  onToggleRow={selection.toggleRow}
                  onToggleGroup={selection.toggleGroup}
                />
              ))}
            </div>
          </>
        )}
      </div>

      {data && rows.length > 0 && (
        <div className="fixed inset-x-0 bottom-0 border-t border-zinc-200 bg-white">
          <div className="mx-auto flex max-w-3xl items-center justify-between p-4">
            <span className="text-sm text-zinc-600">
              {selection.checkedCount} selected of {selection.totalCount} available
            </span>
            <Button
              className="bg-red-600 hover:bg-red-700 text-white"
              disabled={selection.checkedCount === 0 || mutation.isPending}
              onClick={() => setGateOpen(true)}
            >
              {mutation.isPending
                ? `Scheduling ${selection.checkedCount} updates…`
                : `Update ${selection.checkedCount} selected`}
            </Button>
          </div>
        </div>
      )}

      <ConfirmBulkUpdateGateDialog
        open={gateOpen}
        resourceLabel="plugin"
        count={selection.checkedCount}
        siteCount={selection.siteCount}
        onCancel={() => setGateOpen(false)}
        onConfirm={handleConfirm}
      />
    </div>
  );
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd apps/web && pnpm test -- --run OverviewPlugins`

Expected: 8 PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/routes/OverviewPlugins.tsx \
        apps/web/tests/routes/OverviewPlugins.test.tsx
git commit -m "feat(p2-10): /overview/plugins drill-in page

Durable full-page view of every pending plugin update across the fleet.
Header + ← Overview back-link + skip-major toggle + per-site
PendingPluginUpdatesGroup list + sticky RED 'Update N selected' footer +
lightweight gate + navigate-on-success to /jobs/{job_id}. Reuses the P2.7
usePendingPluginUpdates(true) query hook, the shared
usePendingUpdatesSelection hook, the existing group component, and the
P2.9 useBulkUpdatePlugins mutation. Loading/empty/error states mirror
Jobs.tsx.

8 page tests: empty state, populated grouped list + footer, back-link,
uncheck updates counter, skip-major hides majors + re-derives counts,
footer disabled at 0, footer → gate → confirm → navigate, gate cancel
closes without navigating.

Per spec § 2-3."
```

---

## Task 4 — `OverviewThemes.tsx` route (theme mirror)

**Files:**
- Create: `apps/web/src/routes/OverviewThemes.tsx`
- Test: `apps/web/tests/routes/OverviewThemes.test.tsx` (CREATE)

- [ ] **Step 1: Write the failing tests**

Create `apps/web/tests/routes/OverviewThemes.test.tsx`:

```tsx
import { describe, it, expect } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import { http, HttpResponse } from 'msw';
import { server } from '@/test/setup';
import OverviewThemes from '@/routes/OverviewThemes';

const POPULATED = {
  pending_updates: [
    { site_id: 1, site_label: 'SmartCoding', slug: 'astra',   theme_name: 'Astra',   current_version: '4.6.3',  target_version: '4.7.0' },
    { site_id: 1, site_label: 'SmartCoding', slug: 'kadence', theme_name: 'Kadence', current_version: '1.1.40', target_version: '2.0.0' }, // MAJOR
    { site_id: 2, site_label: 'AcmeBlog',    slug: 'blocksy', theme_name: 'Blocksy', current_version: '2.0.1',  target_version: '2.0.2' },
  ],
  generated_at: '2026-06-14 10:00:00',
};

function usePopulated() {
  server.use(
    http.get('*/wp-json/defyn/v1/overview/pending-theme-updates', () =>
      HttpResponse.json(POPULATED),
    ),
  );
}

function renderPage() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/overview/themes']}>
        <Routes>
          <Route path="/overview/themes" element={<OverviewThemes />} />
          <Route path="/overview" element={<div>OVERVIEW PROBE</div>} />
          <Route path="/jobs/:id" element={<div>JOB DETAIL PROBE</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('OverviewThemes', () => {
  it('rendersEmptyStateFromDefaultHandler', async () => {
    renderPage();
    await waitFor(() =>
      expect(screen.getByText(/no pending theme updates across your fleet/i)).toBeInTheDocument(),
    );
    expect(screen.queryByRole('button', { name: /update .* selected/i })).not.toBeInTheDocument();
  });

  it('rendersPopulatedListGroupedBySiteWithFooterCounts', async () => {
    usePopulated();
    renderPage();
    await waitFor(() =>
      expect(screen.getByRole('checkbox', { name: /astra/i })).toBeInTheDocument(),
    );
    expect(screen.getByRole('checkbox', { name: /kadence/i })).toBeInTheDocument();
    expect(screen.getByRole('checkbox', { name: /blocksy/i })).toBeInTheDocument();
    expect(screen.getByText(/3 selected of 3 available/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /update 3 selected/i })).toBeInTheDocument();
  });

  it('backLinkPointsToOverview', async () => {
    usePopulated();
    renderPage();
    await waitFor(() =>
      expect(screen.getByRole('link', { name: /overview/i })).toBeInTheDocument(),
    );
    expect(screen.getByRole('link', { name: /overview/i })).toHaveAttribute('href', '/overview');
  });

  it('skipMajorToggleHidesMajorRowsAndReDerivesCounts', async () => {
    usePopulated();
    renderPage();
    await waitFor(() => expect(screen.getByRole('checkbox', { name: /kadence/i })).toBeInTheDocument());
    fireEvent.click(screen.getByRole('checkbox', { name: /skip major bumps/i }));
    expect(screen.queryByRole('checkbox', { name: /kadence/i })).not.toBeInTheDocument();
    expect(screen.getByText(/2 selected of 2 available/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /update 2 selected/i })).toBeInTheDocument();
  });

  it('footerButtonOpensThemeGateThenConfirmNavigatesToJobDetail', async () => {
    usePopulated();
    server.use(
      http.post('*/wp-json/defyn/v1/overview/bulk-update-themes', async ({ request }) => {
        const body = (await request.json()) as { updates: Array<{ site_id: number; slug: string }> };
        return HttpResponse.json(
          {
            job_id: 88,
            scheduled_count: body.updates.length,
            skipped_count: 0,
            scheduled_pairs: body.updates,
            skipped_pairs: [],
            scheduled_at: '2026-06-14 10:00:42',
          },
          { status: 202 },
        );
      }),
    );
    renderPage();
    await waitFor(() => expect(screen.getByText(/3 selected of 3 available/i)).toBeInTheDocument());

    fireEvent.click(screen.getByRole('button', { name: /update 3 selected/i }));
    await waitFor(() =>
      expect(screen.getByText(/update 3 themes across 2 sites\?/i)).toBeInTheDocument(),
    );

    fireEvent.click(screen.getByRole('button', { name: /^update 3 themes$/i }));
    await waitFor(() => expect(screen.getByText('JOB DETAIL PROBE')).toBeInTheDocument());
  });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd apps/web && pnpm test -- --run OverviewThemes`

Expected: 5 FAILED with a module-resolution error.

- [ ] **Step 3: Create the page**

Create `apps/web/src/routes/OverviewThemes.tsx`:

```tsx
import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Button } from '@/components/ui/button';
import { PendingThemeUpdatesGroup } from '@/components/overview/PendingThemeUpdatesGroup';
import { ConfirmBulkUpdateGateDialog } from '@/components/overview/ConfirmBulkUpdateGateDialog';
import { usePendingThemeUpdates } from '@/lib/queries/usePendingThemeUpdates';
import { useBulkUpdateThemes } from '@/lib/mutations/useBulkUpdateThemes';
import { usePendingUpdatesSelection } from '@/lib/usePendingUpdatesSelection';

/**
 * P2.10 — /overview/themes drill-in page. Theme mirror of OverviewPlugins:
 * usePendingThemeUpdates(true) + PendingThemeUpdatesGroup + useBulkUpdateThemes
 * + theme copy. Same structure, gate, and navigate-on-success contract.
 *
 * Spec: docs/superpowers/specs/2026-06-14-p2-10-filtered-drill-in-design.md § 2-3
 */
export default function OverviewThemes() {
  const navigate = useNavigate();
  const { data, isLoading, isError, refetch } = usePendingThemeUpdates(true);
  const mutation = useBulkUpdateThemes();
  const [gateOpen, setGateOpen] = useState(false);

  const rows = data?.pending_updates ?? [];
  const selection = usePendingUpdatesSelection(rows);

  const handleConfirm = (): void => {
    setGateOpen(false);
    if (selection.selectedPairs.length > 0) {
      mutation.mutate(
        { updates: selection.selectedPairs },
        {
          onSuccess: (res) => {
            // Guardrail #7 — non-null job_id navigates to the tracked job.
            if (res.job_id !== null) {
              navigate(`/jobs/${res.job_id}`);
            }
          },
        },
      );
    }
  };

  return (
    <div className="min-h-screen p-8 pb-24">
      <div className="mx-auto max-w-3xl space-y-4">
        <div className="flex items-baseline justify-between">
          <div className="flex items-baseline gap-3">
            <Link
              to="/overview"
              className="text-sm text-zinc-600 underline-offset-4 hover:underline"
            >
              ← Overview
            </Link>
            <h1 className="text-2xl font-semibold">Theme updates across your fleet</h1>
          </div>
          {data && rows.length > 0 && (
            <span className="text-sm text-zinc-600">{selection.totalCount} pending</span>
          )}
        </div>

        {isLoading && <div className="h-24 animate-pulse rounded-md bg-gray-100" />}

        {isError && (
          <div className="rounded-md border border-red-200 bg-red-50 p-4">
            <p className="text-sm text-red-800">Failed to load pending theme updates.</p>
            <button
              onClick={() => refetch()}
              className="mt-2 rounded-md border border-red-200 px-3 py-1 text-sm text-red-800"
            >
              Try again
            </button>
          </div>
        )}

        {data && rows.length === 0 && (
          <p className="text-sm text-zinc-600">
            No pending theme updates across your fleet.
          </p>
        )}

        {data && rows.length > 0 && (
          <>
            <label className="flex items-center gap-2 text-sm text-zinc-700">
              <input
                type="checkbox"
                checked={selection.skipMajor}
                onChange={(e) => selection.setSkipMajor(e.target.checked)}
              />
              Skip major bumps
              <span className="text-xs text-zinc-500">
                (hide updates where the major version changes, e.g. 1.x → 2.x)
              </span>
            </label>

            <div className="space-y-2">
              {selection.grouped.map(([label, groupRows]) => (
                <PendingThemeUpdatesGroup
                  key={label}
                  siteLabel={label}
                  rows={groupRows}
                  checkedKeys={selection.checkedKeys}
                  onToggleRow={selection.toggleRow}
                  onToggleGroup={selection.toggleGroup}
                />
              ))}
            </div>
          </>
        )}
      </div>

      {data && rows.length > 0 && (
        <div className="fixed inset-x-0 bottom-0 border-t border-zinc-200 bg-white">
          <div className="mx-auto flex max-w-3xl items-center justify-between p-4">
            <span className="text-sm text-zinc-600">
              {selection.checkedCount} selected of {selection.totalCount} available
            </span>
            <Button
              className="bg-red-600 hover:bg-red-700 text-white"
              disabled={selection.checkedCount === 0 || mutation.isPending}
              onClick={() => setGateOpen(true)}
            >
              {mutation.isPending
                ? `Scheduling ${selection.checkedCount} updates…`
                : `Update ${selection.checkedCount} selected`}
            </Button>
          </div>
        </div>
      )}

      <ConfirmBulkUpdateGateDialog
        open={gateOpen}
        resourceLabel="theme"
        count={selection.checkedCount}
        siteCount={selection.siteCount}
        onCancel={() => setGateOpen(false)}
        onConfirm={handleConfirm}
      />
    </div>
  );
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd apps/web && pnpm test -- --run OverviewThemes`

Expected: 5 PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/routes/OverviewThemes.tsx \
        apps/web/tests/routes/OverviewThemes.test.tsx
git commit -m "feat(p2-10): /overview/themes drill-in page

Theme mirror of OverviewPlugins — usePendingThemeUpdates(true) +
PendingThemeUpdatesGroup + useBulkUpdateThemes + theme copy. Same
header/back-link/skip-major/sticky-footer/gate/navigate structure.

5 page tests: empty state, populated grouped list + footer, back-link,
skip-major hides majors + re-derives counts, footer → theme gate →
confirm → navigate.

Per spec § 2-3."
```

---

## Task 5 — Router wiring + `PendingUpdatesWidget` card re-pointing

**Files:**
- Modify: `apps/web/src/App.tsx` (2 new routes inside the `RequireAuth` outlet)
- Modify: `apps/web/src/components/overview/PendingUpdatesWidget.tsx` (plugin + theme card `to`)
- Modify: `apps/web/tests/components/overview/PendingUpdatesWidget.test.tsx` (2 re-pointed assertions + Routes destinations)

- [ ] **Step 1: Update the widget test (RED for the 2 re-pointed assertions)**

The existing test (verified) has, among others:
- `it('plugin card links to /sites?filter=has-plugin-updates')` asserting `href` `/sites?filter=has-plugin-updates`
- `it('theme card links to /sites?filter=has-theme-updates')` asserting `href` `/sites?filter=has-theme-updates`

Edit `apps/web/tests/components/overview/PendingUpdatesWidget.test.tsx`. Replace the plugin-card assertion block — change:

```tsx
  it('plugin card links to /sites?filter=has-plugin-updates', () => {
    renderWithRouter()
    const pluginCard = screen.getByRole('link', { name: /plugin updates/i })
    expect(pluginCard).toHaveAttribute('href', '/sites?filter=has-plugin-updates')
  })
```

to:

```tsx
  it('plugin card links to /overview/plugins', () => {
    renderWithRouter()
    const pluginCard = screen.getByRole('link', { name: /plugin updates/i })
    expect(pluginCard).toHaveAttribute('href', '/overview/plugins')
  })
```

Replace the theme-card assertion block — change:

```tsx
  it('theme card links to /sites?filter=has-theme-updates', () => {
    renderWithRouter()
    const themeCard = screen.getByRole('link', { name: /theme updates/i })
    expect(themeCard).toHaveAttribute('href', '/sites?filter=has-theme-updates')
  })
```

to:

```tsx
  it('theme card links to /overview/themes', () => {
    renderWithRouter()
    const themeCard = screen.getByRole('link', { name: /theme updates/i })
    expect(themeCard).toHaveAttribute('href', '/overview/themes')
  })
```

Also add destination routes to the test wrapper so the new hrefs resolve cleanly (the `<Link>` renders the same regardless, but adding routes keeps the wrapper honest). Change the `renderWithRouter` `<Routes>` block — replace:

```tsx
      <Routes>
        <Route path="/overview" element={
          <PendingUpdatesWidget
            counts={{ plugins: 47, themes: 3, cores_minor: 1, cores_major: 0, sites_with_any_update: 9 }}
          />
        } />
        <Route path="/sites" element={<div data-testid="sites-page">sites</div>} />
      </Routes>
```

with:

```tsx
      <Routes>
        <Route path="/overview" element={
          <PendingUpdatesWidget
            counts={{ plugins: 47, themes: 3, cores_minor: 1, cores_major: 0, sites_with_any_update: 9 }}
          />
        } />
        <Route path="/overview/plugins" element={<div data-testid="overview-plugins-page">plugins</div>} />
        <Route path="/overview/themes" element={<div data-testid="overview-themes-page">themes</div>} />
        <Route path="/sites" element={<div data-testid="sites-page">sites</div>} />
      </Routes>
```

The core-card test (`it('core card links to /sites?filter=has-core-update')`) is UNCHANGED — guardrail #8.

- [ ] **Step 2: Run the widget test to confirm the 2 re-pointed assertions fail**

Run: `cd apps/web && pnpm test -- --run PendingUpdatesWidget`

Expected: 2 FAILED (the re-pointed plugin + theme assertions — widget still emits the old `/sites?filter=…` hrefs), core card + numbers tests still PASS.

- [ ] **Step 3: Re-point the widget cards**

Edit `apps/web/src/components/overview/PendingUpdatesWidget.tsx`. Change the plugin card `to` — replace:

```tsx
      <CountCard
        to="/sites?filter=has-plugin-updates"
        label="Plugin updates"
        num={counts.plugins}
        sub={`across ${counts.sites_with_any_update} site${counts.sites_with_any_update === 1 ? '' : 's'}`}
      />
```

with:

```tsx
      <CountCard
        to="/overview/plugins"
        label="Plugin updates"
        num={counts.plugins}
        sub={`across ${counts.sites_with_any_update} site${counts.sites_with_any_update === 1 ? '' : 's'}`}
      />
```

Change the theme card `to` — replace:

```tsx
      <CountCard
        to="/sites?filter=has-theme-updates"
        label="Theme updates"
        num={counts.themes}
        sub="across all sites"
      />
```

with:

```tsx
      <CountCard
        to="/overview/themes"
        label="Theme updates"
        num={counts.themes}
        sub="across all sites"
      />
```

The core card (`to="/sites?filter=has-core-update"`) is UNCHANGED — guardrail #8.

- [ ] **Step 4: Wire the routes in App.tsx**

Edit `apps/web/src/App.tsx`. Add the two imports after the existing route imports — replace:

```tsx
import Jobs from './routes/Jobs';
import JobDetail from './routes/JobDetail';
```

with:

```tsx
import Jobs from './routes/Jobs';
import JobDetail from './routes/JobDetail';
import OverviewPlugins from './routes/OverviewPlugins';
import OverviewThemes from './routes/OverviewThemes';
```

Add the two routes inside the `RequireAuth` outlet, immediately after the `/overview` route — replace:

```tsx
        <Route path="/overview" element={<Overview />} />
        <Route path="/sites" element={<SitesList />} />
```

with:

```tsx
        <Route path="/overview" element={<Overview />} />
        <Route path="/overview/plugins" element={<OverviewPlugins />} />
        <Route path="/overview/themes" element={<OverviewThemes />} />
        <Route path="/sites" element={<SitesList />} />
```

(react-router-dom v6 matches the most specific static path, so `/overview/plugins` does not collide with `/overview`. Order is not significant here, but placing them adjacent keeps the file readable.)

- [ ] **Step 5: Run tests to verify they pass + full suite green**

Run: `cd apps/web && pnpm test -- --run PendingUpdatesWidget`

Expected: all 4 widget tests PASS (2 re-pointed + numbers + core unchanged).

Run the full suite to confirm no new regressions:

Run: `cd apps/web && pnpm test -- --run`

Expected: prior 268 pass + the new tests from Tasks 1–4 (8 + 6 + 8 + 5 = 27) all green = **295 pass + exactly the 4 documented carry-forward failures** (`SiteDetail` ×2 + `SiteCoreCard` ×2). 0 new failures.

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/App.tsx \
        apps/web/src/components/overview/PendingUpdatesWidget.tsx \
        apps/web/tests/components/overview/PendingUpdatesWidget.test.tsx
git commit -m "feat(p2-10): wire /overview/plugins + /overview/themes routes + re-point cards

Adds the two new routes inside the RequireAuth outlet in App.tsx.
Re-points the PendingUpdatesWidget plugin card → /overview/plugins and
theme card → /overview/themes. Core card UNCHANGED
(/sites?filter=has-core-update) — WP core is one update per site, not a
fan-out, so the grouped-by-site view stays its honest destination
(guardrail #8).

Updated the 2 re-pointed widget link assertions + added destination
routes to the test wrapper. Full SPA suite green (295 pass + 4
documented carry-forward).

Per spec § 1 (count-card re-pointing) + guardrails #8, #9."
```

---

## Task 6 — Build, ship, deploy-verify, tag

**Files:** none (build + deploy + tag only). NO zip build, NO Kinsta install — dashboard stays v0.9.0, connector v0.1.7, schema v7.

- [ ] **Step 1: Build + typecheck the SPA**

Run: `cd apps/web && pnpm build`

Expected: exits 0 (tsc clean + vite build emits a fresh `dist/assets/index-*.js` bundle). If tsc errors, fix the type issue and re-run before proceeding. Note the emitted bundle filename (e.g. `index-XXXXXXXX.js`) from the build output for Step 6's grep.

- [ ] **Step 2: Full suite one more time (pre-merge gate)**

Run: `cd apps/web && pnpm test -- --run`

Expected: **295 pass + exactly the 4 documented carry-forward failures** (`tests/SiteDetail.test.tsx` ×2 + `tests/components/sites/SiteCoreCard.test.tsx` ×2). 0 new failures. If anything else is red, STOP and fix before merging.

- [ ] **Step 3: Push the feature branch**

```bash
git push -u origin p2-10-filtered-drill-in
```

- [ ] **Step 4: Fast-forward merge into main + push**

```bash
git checkout main
git pull --ff-only origin main
git merge --ff-only p2-10-filtered-drill-in
git push origin main
```

If the ff-merge is rejected (main advanced), rebase the feature branch onto the new main, re-run the full suite (Step 2), then retry the ff-merge.

- [ ] **Step 5: Wait for Cloudflare Pages auto-deploy**

Cloudflare Pages auto-deploys `app.defynwp.defyn.agency` from `main`. Wait ~1–2 minutes for the build to finish (check the Cloudflare Pages dashboard or just poll the live bundle in Step 6). No Kinsta reinstall — the dashboard plugin is untouched.

- [ ] **Step 6: Deploy-verify — bundle string presence (smoke #4)**

Fetch the deployed `index.html`, extract the hashed JS bundle name, then grep the bundle for the P2.10 literal strings:

```bash
BUNDLE=$(curl -s https://app.defynwp.defyn.agency/ | grep -oE '/assets/index-[A-Za-z0-9_-]+\.js' | head -1)
echo "Deployed bundle: $BUNDLE"
curl -s "https://app.defynwp.defyn.agency${BUNDLE}" > /tmp/p2-10-bundle.js
for s in "across your fleet" "Skip major bumps" "Update" "selected" "No pending plugin updates" "No pending theme updates"; do
  if grep -qF "$s" /tmp/p2-10-bundle.js; then echo "OK: $s"; else echo "MISSING: $s"; fi
done
```

Expected: all 6 strings print `OK`. ("across your fleet" appears in both page headers; "No pending plugin updates" / "No pending theme updates" confirm both new pages shipped.)

- [ ] **Step 7: Deploy-verify — SPA routes serve 200 (smoke #5)**

Client-side routing means the server returns `index.html` (HTTP 200) for any unknown path under the SPA:

```bash
for path in /overview/plugins /overview/themes; do
  code=$(curl -s -o /dev/null -w '%{http_code}' "https://app.defynwp.defyn.agency${path}")
  echo "$path → $code"
done
```

Expected: both return `200`.

- [ ] **Step 8: Tag the release**

```bash
git tag p2-10-filtered-drill-in-complete
git push origin p2-10-filtered-drill-in-complete
```

- [ ] **Step 9: Update MEMORY index**

Append a one-line P2.10 entry to the DefynWP overview topic file (the MEMORY index entry — keep it under ~200 chars per the MEMORY.md size warning; full detail can go in the topic body, not the index line). Suggested index line:

> **P2.10 (Filtered drill-in pages) COMPLETE 2026-06-14** — tag `p2-10-filtered-drill-in-complete`, **SPA-only** (dashboard unchanged v0.9.0, connector v0.1.7, schema v7). New `/overview/plugins` + `/overview/themes` routes render the P2.7/P2.8 flat pending-update lists as durable group-by-site bulk-select pages (header + ← Overview back-link + skip-major toggle + sticky RED "Update N selected" footer + lightweight `ConfirmBulkUpdateGateDialog` + navigate-on-success to `/jobs/{job_id}`). New shared `usePendingUpdatesSelection(rows)` hook extracts the P2.7.1 dialog state machine (skipMajor/visibleRows/grouped/checkedKeys/toggleRow/toggleGroup/selectedPairs, single re-seed useEffect([allKeys])); existing P2.7/P2.8 dialogs NOT refactored onto it (YAGNI). `PendingUpdatesWidget` plugin+theme cards re-point to the new pages; **core card unchanged** (`/sites?filter=has-core-update`). Reuses existing query hooks called with `true`, group components verbatim, bulk mutation hooks (return `job_id`). 27 new tests (8 hook + 6 gate + 8 plugin page + 5 theme page), SPA 295 pass + 4 carry-forward (SiteDetail×2 + SiteCoreCard×2). LAST deferred P2.7-spec §6 item — all four (P2.7.1, P2.8, P2.9, P2.10) now complete. Cloudflare Pages auto-deploy verified: bundle has "across your fleet"/"Skip major bumps"/"No pending plugin updates"/"No pending theme updates"; `/overview/plugins` + `/overview/themes` serve 200. NO zip build, NO Kinsta reinstall.

(No code commit for the MEMORY update — it's an auto-memory file outside the repo. Just write it to MEMORY.)

- [ ] **Step 10: Done — report**

All 6 tasks complete. Final state: SPA-only P2.10 shipped via Cloudflare Pages, dashboard untouched at v0.9.0, tag `p2-10-filtered-drill-in-complete` pushed.

---

## Self-review checklist (run before declaring the plan complete)

- [ ] **Spec coverage:** §1 architecture (reuse query/group/mutation hooks + 2 new shared pieces + 2 routes + card re-pointing) → Tasks 1–5. §2 page layout (header/back-link/skip-major/groups/sticky footer + loading/empty/error states) → Tasks 3–4. §3 confirm + mutation flow (gate dialog + navigate-on-success) → Tasks 2–4. §4 component/file structure → all new/modified files mapped in Tasks 1–5. §5 smoke matrix (build + suite + Cloudflare deploy + bundle strings + route 200) → Task 6. §6 deferred (group-by-resource, per-row update, pagination, /overview/core, dialog refactor, polling) → explicitly NOT built. §7 13 guardrails → traps #1–#13 + reality traps #14–#22.
- [ ] **Placeholder scan:** no TBD / TODO / "similar to Task N" — every code block is complete and runnable.
- [ ] **Type consistency:** `usePendingUpdatesSelection` return shape (`skipMajor`/`setSkipMajor`/`visibleRows`/`grouped`/`checkedKeys`/`toggleRow`/`toggleGroup`/`totalCount`/`siteCount`/`checkedCount`/`selectedPairs`) is consumed exactly by both pages (Tasks 3/4). Gate dialog props (`open`/`resourceLabel`/`count`/`siteCount`/`onCancel`/`onConfirm`) match the pages' usage. `toggleGroup(rowKeys, allChecked)` signature matches the group component's `onToggleGroup`.
- [ ] **Reality-check:** group prop is `siteLabel` (not `label`) + requires `onToggleGroup` (trap #14); query hook called as `usePendingPluginUpdates(true)`, reads `data?.pending_updates` (traps #15–#16); mutation `mutate({ updates })` returns `job_id` (trap #17); MSW base `*/wp-json/defyn/v1/…`, defaults empty, populated via `server.use` (traps #18–#19); `Button` forwards ref for cancelRef (trap #20); loading/empty/error pattern mirrors `Jobs.tsx` (trap #21); `selectedPairs` derives from `visibleRows ∩ checkedKeys` (trap #22). App.tsx route structure + widget Link JSX quoted verbatim from the real files in Task 5.
