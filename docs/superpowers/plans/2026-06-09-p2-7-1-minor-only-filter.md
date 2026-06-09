# P2.7.1 — Minor-only filter on bulk plugin update dialog Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `Skip major bumps` toggle to the existing P2.7 `ConfirmBulkUpdatePluginsDialog`. Toggle defaults to OFF. When ON, rows where `current_version → target_version` changes the leftmost numeric segment (npm convention) are hidden entirely from the dialog. Title + footer counter + primary button label all reflect the filtered pool.

**Architecture:** ONE new pure-function helper at `apps/web/src/lib/semver.ts` (`isPluginMajorBump`) + ONE checkbox toggle in the existing dialog + a `useMemo`-derived `visibleRows` that flows through the existing `allKeys` / `grouped` / `totalCount` derived state. The existing `useEffect([open, allKeys])` re-fires when the toggle changes because `allKeys` is derived from `visibleRows` — no separate effect needed.

**Tech Stack:** Pure SPA change. React 18 + TypeScript + Vitest + React Testing Library. Dashboard plugin stays at v0.8.0 (no PHP, no schema, no connector touch).

**Spec:** [`docs/superpowers/specs/2026-06-09-p2-7-1-minor-only-filter-design.md`](../specs/2026-06-09-p2-7-1-minor-only-filter-design.md)

---

## Workflow conventions

- **Branch:** already on **`p2-7-1-minor-only-filter`** (current tip `a76c11a` — the just-committed P2.7.1 spec). Confirm with `git branch --show-current` before starting. Branch was created off `main` (== `642a2db`).
- **Each Task = one atomic commit.**
- **Test discipline (TDD):** Step 1 writes the failing test. Step 2 runs it and confirms it fails. Step 3 writes the implementation. Step 4 confirms it passes. Step 5 commits.
- **Test runner:** `cd apps/web && pnpm test -- --run`.
- **Commit message format:** `<type>(p2-7-1): <description>` where `<type>` ∈ {feat, fix, refactor, docs, test, chore}.
- **All edits adhere to** `~/.claude/rules/typescript/coding-style.md` — no `any`, no `console.log`, immutability preferred.
- **No PHP touch.** Dashboard plugin stays at **v0.8.0**. NO version bump on `defyn-dashboard.php` / `composer.json` / `readme.txt`.
- **No connector touch.** Stays at **v0.1.7**.
- **No schema touch.** Stays at **v6**.

### Plan-bug traps to internalise before writing any code

1. **Test method names MUST be EXACT.** The 3 new dialog tests:
   - `skipMajorToggleOffShowsAllRows`
   - `skipMajorToggleOnHidesMajorRowsAndUpdatesCounts`
   - `skipMajorToggleResetsCheckedKeysWhenFlipped`

   The 8 semver tests use natural-language `it()` strings — those are DESCRIPTIVE LABELS for human readability, NOT method names. Match them verbatim too (the spec test file is the contract).

2. **Default state of `skipMajor` is `false`** — opt-in. Matches the Overview header counter on dialog open.

3. **DO NOT MODIFY the existing test fixture `ROWS`** in `ConfirmBulkUpdatePluginsDialog.test.tsx`. It has 3 rows (akismet 5.3 → 5.3.1, yoast 22.5 → 22.6, jetpack 13.1 → 13.2) — all are minor/patch by npm convention. The existing 4 tests rely on `ROWS` and must keep passing unchanged. For the 3 new tests, define a SEPARATE `ROWS_WITH_MAJOR` constant that adds at least one major bump (e.g., `elementor 3.18.2 → 4.0.0`).

4. **Toggle JSX placement:** BETWEEN the body explanatory `<div className="mt-3 space-y-2 text-sm text-zinc-700">` (containing the 2 `<p>` tags) AND the per-site groups `<div className="mt-3 space-y-2">` (containing the `visibleGroups.map`). NOT in the footer. NOT in the title block. The toggle visually separates "what this dialog does" from "what rows you'll be modifying."

5. **`allKeys`, `grouped`, `totalCount` ALL derive from `visibleRows`** — NOT from `rows`. The filter must flow through all three. Without this, the title still shows the unfiltered total and the dialog gets visually confusing.

6. **Re-seed behavior — use the EXISTING `useEffect([open, allKeys])` indirectly.** Because `allKeys` is now derived from `visibleRows`, flipping the toggle changes `allKeys`, which fires the existing effect. DO NOT add a separate `useEffect([skipMajor])`. The indirect dependency is the cleanest model.

7. **Manual unchecks are reset when toggle flips** — this is the same trade-off the existing dialog already makes on re-open. The simpler mental model wins ("toggle is a fresh slate"). Test 3 (`skipMajorToggleResetsCheckedKeysWhenFlipped`) asserts this.

8. **`isPluginMajorBump` returns `false` for null/undefined/unparseable inputs** — defensive default. If the backend ever omits `target_version` or sends an oddball string, the row stays visible (the operator can manually uncheck it).

9. **NO dashboard plugin rebuild.** NO version bump anywhere. Connector stays at v0.1.7, schema at v6, dashboard at v0.8.0.

10. **Smoke matrix is § 5.2 of the spec — 4 visual steps.** Tag `p2-7-1-minor-only-filter-complete` ONLY after all 4 pass. Prereq: at least one pending plugin update exists in prod for `user_id=1` (carry-forward from P2.6 + P2.7 — prod sites table is empty for user 1; either re-register SmartCoding via Sites → Add, or skip steps 2-4 and tag based on test coverage alone).

### Pre-existing carry-forward failures (TOLERATE — do NOT count as new regressions)

SPA (4, since P2.4.1):
- `tests/SiteDetail.test.tsx` × 2
- `tests/components/sites/SiteCoreCard.test.tsx > idle update-available renders version diff + Update button`
- `tests/components/sites/SiteCoreCard.test.tsx > failed state renders red banner + Retry button + tooltip on hover`

### Existing-code anchors (read these before starting any task)

- `apps/web/src/components/overview/ConfirmBulkUpdatePluginsDialog.tsx` — 165 lines. Key line numbers:
  - Line 1: `import { useEffect, useMemo, useRef, useState } from 'react';` — already imports everything needed.
  - Line 36: `const [showAll, setShowAll] = useState(false);` — append `const [skipMajor, setSkipMajor] = useState(false);` BELOW this line (Task 2).
  - Line 38-41: `allKeys` useMemo (currently keyed on `[rows]`) — change to derive from `visibleRows` instead.
  - Line 42: `const [checkedKeys, setCheckedKeys] = useState<Set<string>>(() => new Set(allKeys));` — unchanged.
  - Lines 45-51: `useEffect(() => { if (open) { setCheckedKeys(new Set(allKeys)); setShowAll(false); cancelRef.current?.focus(); } }, [open, allKeys]);` — unchanged. The indirect dependency on `visibleRows` flows through `allKeys`.
  - Line 54: `grouped` useMemo iterates `rows` — change to `visibleRows`.
  - Line 65: `const totalCount = rows.length;` — change to `const totalCount = visibleRows.length;`.
  - Line 116: title `🛑 Bulk update {totalCount} plugins across {grouped.length} sites?` — unchanged.
  - Line 125: `{visibleGroups.map(([label, groupRows]) => (` — unchanged.

- `apps/web/tests/components/overview/ConfirmBulkUpdatePluginsDialog.test.tsx` — existing 4 tests use `const ROWS` at lines 5-9. The 4 tests are at descriptive `it()` strings: `cancelHasDefaultFocus`, `primaryButtonUsesDestructiveVariant`, `primaryButtonDisabledWhenZeroSelected`, `footerCounterUpdatesLive`. APPEND `ROWS_WITH_MAJOR` constant + 3 new tests AT THE END of the existing describe block.

- `apps/web/src/lib/` — existing files: `apiClient.ts`, `cn.ts`, `formatRelativeTime.ts`, `queryClient.ts`. Add `semver.ts` to this directory.

- `apps/web/tests/lib/` — existing: `formatRelativeTime.test.ts` (style template — pure function tests with parameterized cases). Add `semver.test.ts` to this directory.

- `apps/web/src/components/sites/SiteCoreCard.tsx` — lines 28-35 have the existing `isMinorBump` helper for WP CORE versions. DO NOT modify it. P2.7.1's plugin helper has DIFFERENT semantics (npm convention vs WP-core convention) — they will co-exist in different modules until a P2.8 refactor.

---

## File structure overview

### SPA — new files

| Path | Responsibility |
|---|---|
| `apps/web/src/lib/semver.ts` | Single export `isPluginMajorBump(current, target): boolean`. ~15 lines. Pure function. No React, no I/O. |
| `apps/web/tests/lib/semver.test.ts` | 8 parameterized tests covering major/minor/patch/same/null/undefined/pre-release/unparseable. |

### SPA — modified files

| Path | What changes |
|---|---|
| `apps/web/src/components/overview/ConfirmBulkUpdatePluginsDialog.tsx` | Add `skipMajor` state + toggle JSX + `visibleRows` useMemo + re-derive `allKeys`/`grouped`/`totalCount` from `visibleRows`. Import `isPluginMajorBump` from `@/lib/semver`. |
| `apps/web/tests/components/overview/ConfirmBulkUpdatePluginsDialog.test.tsx` | Append `ROWS_WITH_MAJOR` constant + 3 new tests. Existing 4 tests + existing `ROWS` constant unchanged. |

---

## Task 1 — `semver.ts` helper + 8 parameterized tests

**Files:**
- Create: `apps/web/src/lib/semver.ts`
- Test: `apps/web/tests/lib/semver.test.ts` (CREATE)

### Step 1: Write the failing test

Create `apps/web/tests/lib/semver.test.ts`:

```ts
import { describe, it, expect } from 'vitest';
import { isPluginMajorBump } from '@/lib/semver';

describe('isPluginMajorBump', () => {
  it('returns true for major version change 1.0.0 → 2.0.0', () => {
    expect(isPluginMajorBump('1.0.0', '2.0.0')).toBe(true);
  });

  it('returns false for minor version change 1.0.0 → 1.5.0', () => {
    expect(isPluginMajorBump('1.0.0', '1.5.0')).toBe(false);
  });

  it('returns false for patch version change 1.0.0 → 1.0.5', () => {
    expect(isPluginMajorBump('1.0.0', '1.0.5')).toBe(false);
  });

  it('returns false for same version 1.0.0 → 1.0.0', () => {
    expect(isPluginMajorBump('1.0.0', '1.0.0')).toBe(false);
  });

  it('returns false when target is null (defensive)', () => {
    expect(isPluginMajorBump('1.0.0', null)).toBe(false);
  });

  it('returns false when current is undefined (defensive)', () => {
    expect(isPluginMajorBump(undefined, '2.0.0')).toBe(false);
  });

  it('returns true for pre-release suffix 1.0-beta → 2.0', () => {
    expect(isPluginMajorBump('1.0-beta', '2.0')).toBe(true);
  });

  it('returns false when major segment is unparseable (conservative)', () => {
    expect(isPluginMajorBump('v2', '3')).toBe(false);
  });
});
```

Note: the `it()` strings above are the EXACT descriptive labels. They are NOT method names. Match them verbatim — they serve as documentation for the parameterized cases.

### Step 2: Run the test to verify it fails

```bash
cd apps/web && pnpm test -- --run semver
```

Expected: FAIL — `Failed to resolve import "@/lib/semver"`.

### Step 3: Create the helper

Create `apps/web/src/lib/semver.ts`:

```ts
/**
 * P2.7.1 — npm-style major bump detection for plugin updates.
 *
 * Returns true when the leftmost numeric segment differs between
 * current and target (e.g. 1.x → 2.x). Returns false for:
 *   - null/empty target (defensive — don't auto-hide unknown bumps)
 *   - same major (1.5.0 → 1.6.0, 1.5.0 → 1.5.1)
 *   - unparseable major (treat as not major — match conservative default)
 *
 * Distinct from P2.4.1's SiteCoreCard `isMinorBump` which uses WP-core
 * convention (major.minor both must match). For plugins we use npm
 * convention (major segment only).
 *
 * Spec: docs/superpowers/specs/2026-06-09-p2-7-1-minor-only-filter-design.md § 2
 */
export function isPluginMajorBump(
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

### Step 4: Run the test to verify it passes

```bash
cd apps/web && pnpm test -- --run semver
```

Expected: PASS — 8/8 green.

### Step 5: Commit

```bash
git -C "/Users/pradeep/Local Sites/defynWP" add apps/web/src/lib/semver.ts \
        apps/web/tests/lib/semver.test.ts
git -C "/Users/pradeep/Local Sites/defynWP" commit -m "feat(p2-7-1): isPluginMajorBump helper + 8 parameterized tests

Pure function — npm convention (leftmost segment differs = major).
Returns false for null/undefined/unparseable inputs (defensive default).
Distinct from P2.4.1's WP-core isMinorBump (major.minor convention).
Per spec § 2."
```

---

## Task 2 — Dialog extension (toggle + filter) + 3 new tests

**Files:**
- Modify: `apps/web/src/components/overview/ConfirmBulkUpdatePluginsDialog.tsx`
- Modify: `apps/web/tests/components/overview/ConfirmBulkUpdatePluginsDialog.test.tsx`

### Step 1: Write the failing tests

In `apps/web/tests/components/overview/ConfirmBulkUpdatePluginsDialog.test.tsx`, the existing file structure is:

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
  // 4 existing tests …
});
```

DO NOT modify the existing `ROWS` constant. APPEND a new `ROWS_WITH_MAJOR` constant ABOVE the `describe` block:

```tsx
// P2.7.1 — fixture for skipMajor toggle tests. 4 rows: 3 minor/patch + 1 major.
const ROWS_WITH_MAJOR = [
  { site_id: 1, site_label: 'SmartCoding', slug: 'akismet',   plugin_name: 'Akismet',   current_version: '5.3',    target_version: '5.3.1' },
  { site_id: 1, site_label: 'SmartCoding', slug: 'yoast',     plugin_name: 'Yoast',     current_version: '22.5',   target_version: '22.6' },
  { site_id: 1, site_label: 'SmartCoding', slug: 'elementor', plugin_name: 'Elementor', current_version: '3.18.2', target_version: '4.0.0' }, // MAJOR
  { site_id: 2, site_label: 'AcmeBlog',    slug: 'jetpack',   plugin_name: 'Jetpack',   current_version: '13.1',   target_version: '13.2' },
];
```

APPEND these 3 new test methods INSIDE the existing `describe('ConfirmBulkUpdatePluginsDialog', () => { ... })` block, AFTER the 4 existing tests:

```tsx
it('skipMajorToggleOffShowsAllRows', () => {
  render(
    <ConfirmBulkUpdatePluginsDialog
      open
      rows={ROWS_WITH_MAJOR}
      onCancel={vi.fn()}
      onConfirm={vi.fn()}
    />,
  );
  // Toggle defaults to OFF — all 4 rows visible including Elementor 3.18.2 → 4.0.0.
  expect(screen.getByRole('checkbox', { name: /akismet/i })).toBeInTheDocument();
  expect(screen.getByRole('checkbox', { name: /yoast/i })).toBeInTheDocument();
  expect(screen.getByRole('checkbox', { name: /elementor/i })).toBeInTheDocument();
  expect(screen.getByRole('checkbox', { name: /jetpack/i })).toBeInTheDocument();
  expect(screen.getByText(/4 selected of 4 available/i)).toBeInTheDocument();
});

it('skipMajorToggleOnHidesMajorRowsAndUpdatesCounts', () => {
  render(
    <ConfirmBulkUpdatePluginsDialog
      open
      rows={ROWS_WITH_MAJOR}
      onCancel={vi.fn()}
      onConfirm={vi.fn()}
    />,
  );

  // Flip the toggle ON.
  fireEvent.click(screen.getByRole('checkbox', { name: /skip major bumps/i }));

  // Elementor (3.18.2 → 4.0.0) is hidden; the other 3 stay.
  expect(screen.queryByRole('checkbox', { name: /elementor/i })).not.toBeInTheDocument();
  expect(screen.getByRole('checkbox', { name: /akismet/i })).toBeInTheDocument();
  expect(screen.getByRole('checkbox', { name: /yoast/i })).toBeInTheDocument();
  expect(screen.getByRole('checkbox', { name: /jetpack/i })).toBeInTheDocument();

  // Title, footer counter, and primary button label all reflect 3 (not 4).
  expect(screen.getByText(/bulk update 3 plugins across 2 sites\?/i)).toBeInTheDocument();
  expect(screen.getByText(/3 selected of 3 available/i)).toBeInTheDocument();
  expect(screen.getByRole('button', { name: /bulk update 3 plugins/i })).toBeInTheDocument();
});

it('skipMajorToggleResetsCheckedKeysWhenFlipped', () => {
  render(
    <ConfirmBulkUpdatePluginsDialog
      open
      rows={ROWS_WITH_MAJOR}
      onCancel={vi.fn()}
      onConfirm={vi.fn()}
    />,
  );

  // Manually uncheck akismet first (toggle still OFF).
  fireEvent.click(screen.getByRole('checkbox', { name: /akismet/i }));
  expect(screen.getByText(/3 selected of 4 available/i)).toBeInTheDocument();

  // Flip the toggle ON — checkedKeys re-seeds to all 3 visible rows
  // (akismet is back to checked because the re-seed includes it).
  fireEvent.click(screen.getByRole('checkbox', { name: /skip major bumps/i }));
  expect(screen.getByText(/3 selected of 3 available/i)).toBeInTheDocument();
});
```

Test method names MUST be EXACTLY:
- `skipMajorToggleOffShowsAllRows`
- `skipMajorToggleOnHidesMajorRowsAndUpdatesCounts`
- `skipMajorToggleResetsCheckedKeysWhenFlipped`

### Step 2: Run the tests to verify they fail

```bash
cd apps/web && pnpm test -- --run ConfirmBulkUpdatePluginsDialog
```

Expected: 3 new tests FAIL with `Unable to find an accessible element with the role "checkbox" and name /skip major bumps/i` (the toggle doesn't exist yet). The 4 existing tests still PASS.

### Step 3: Wire up the dialog

In `apps/web/src/components/overview/ConfirmBulkUpdatePluginsDialog.tsx`, make THESE edits:

**Edit 3.1** — at the top of the file, add the new import (after the existing imports):

```tsx
import { useEffect, useMemo, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { PendingPluginUpdatesGroup } from '@/components/overview/PendingPluginUpdatesGroup';
import { isPluginMajorBump } from '@/lib/semver';  // ← NEW
import type { PendingPluginUpdateRow } from '@/types/api';
```

**Edit 3.2** — after `const [showAll, setShowAll] = useState(false);` (around line 36), ADD the new state:

```tsx
const [showAll, setShowAll] = useState(false);
const [skipMajor, setSkipMajor] = useState(false);  // ← NEW

// ← NEW: derived visible rows
const visibleRows = useMemo(
  () => skipMajor
    ? rows.filter((r) => !isPluginMajorBump(r.current_version, r.target_version))
    : rows,
  [rows, skipMajor],
);
```

**Edit 3.3** — change the `allKeys` useMemo (around line 38) to derive from `visibleRows`:

```tsx
const allKeys = useMemo(
  () => visibleRows.map((r) => `${r.site_id}:${r.slug}`),  // ← was rows.map
  [visibleRows],                                            // ← was [rows]
);
```

**Edit 3.4** — change the `grouped` useMemo (around line 54) to iterate `visibleRows`:

```tsx
const grouped = useMemo(() => {
  const map = new Map<string, PendingPluginUpdateRow[]>();
  for (const row of visibleRows) {                          // ← was for (const row of rows)
    const list = map.get(row.site_label) ?? [];
    list.push(row);
    map.set(row.site_label, list);
  }
  return Array.from(map.entries());
}, [visibleRows]);                                          // ← was [rows]
```

**Edit 3.5** — change `totalCount` (around line 65) to use `visibleRows.length`:

```tsx
const totalCount = visibleRows.length;  // ← was rows.length
```

**Edit 3.6** — insert the toggle JSX between the body explanatory `<div>` and the per-site groups `<div>`. Find the section in the return statement that looks like:

```tsx
<div className="mt-3 space-y-2 text-sm text-zinc-700">
  <p>This will run the plugin upgrader on every checked pair below. Each site briefly enters maintenance mode during its update.</p>
  <p>Uncheck any pair you want to skip — server fans out exactly what's checked. Already-updated rows are silently no-op'd.</p>
</div>

<div className="mt-3 space-y-2">
  {visibleGroups.map(([label, groupRows]) => (
    <PendingPluginUpdatesGroup ... />
  ))}
  ...
</div>
```

INSERT the toggle JSX BETWEEN those two `<div>`s:

```tsx
<div className="mt-3 space-y-2 text-sm text-zinc-700">
  <p>This will run the plugin upgrader on every checked pair below. Each site briefly enters maintenance mode during its update.</p>
  <p>Uncheck any pair you want to skip — server fans out exactly what's checked. Already-updated rows are silently no-op'd.</p>
</div>

{/* P2.7.1 — Skip major bumps toggle */}
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
    <PendingPluginUpdatesGroup ... />
  ))}
  ...
</div>
```

### Step 4: Run the tests to verify they pass

```bash
cd apps/web && pnpm test -- --run ConfirmBulkUpdatePluginsDialog
```

Expected: 7/7 green (4 existing + 3 new).

Run the broader SPA suite to confirm no regression:

```bash
cd apps/web && pnpm test -- --run
```

Expected: PASS modulo the 4 documented carry-forward failures (`SiteDetail.test.tsx` × 2 + `SiteCoreCard.test.tsx` × 2). NOTHING ELSE NEW.

Lint:

```bash
cd apps/web && pnpm lint
```

Expected: clean.

### Step 5: Commit

```bash
git -C "/Users/pradeep/Local Sites/defynWP" add apps/web/src/components/overview/ConfirmBulkUpdatePluginsDialog.tsx \
        apps/web/tests/components/overview/ConfirmBulkUpdatePluginsDialog.test.tsx
git -C "/Users/pradeep/Local Sites/defynWP" commit -m "feat(p2-7-1): Skip major bumps toggle in bulk plugin update dialog

Adds skipMajor useState (default false) + toggle JSX between body
text and per-site groups + visibleRows useMemo filter (when ON,
rows with isPluginMajorBump are hidden). allKeys, grouped, totalCount
all re-derive from visibleRows so title + footer counter + primary
button label all reflect filtered counts. Existing useEffect on
[open, allKeys] re-fires when toggle flips (allKeys changes) and
re-seeds checkedKeys to all visible rows. Per spec § 3 + plan-bug
traps #4-#7."
```

---

## Task 3 — Build SPA + push + 4-step smoke + tag + MEMORY

**Files:** none (build + ship + smoke playbook + git tag + memory update).

ONLY run this task after Tasks 1 + 2 are committed cleanly.

- [ ] **Step 1: Confirm all SPA tests + lint green**

```bash
cd /Users/pradeep/Local\ Sites/defynWP/apps/web && pnpm test -- --run
cd /Users/pradeep/Local\ Sites/defynWP/apps/web && pnpm lint
```

Expected: 4 documented carry-forward failures only (`SiteDetail.test.tsx` × 2 + `SiteCoreCard.test.tsx` × 2). NOTHING ELSE NEW. Lint clean.

- [ ] **Step 2: Build SPA**

```bash
cd /Users/pradeep/Local\ Sites/defynWP/apps/web
pnpm build 2>&1 | tail -15
ls -lah dist/index.html dist/assets/*.js | head -3
```

Expected: fresh `dist/` directory. JS bundle should be ~437-440K (carry-forward from P2.7's 437K + ~1KB for the new helper + toggle).

- [ ] **Step 3: Push branch + ff-merge main + push main**

```bash
cd /Users/pradeep/Local\ Sites/defynWP
git push origin p2-7-1-minor-only-filter
git checkout main
git merge --ff-only p2-7-1-minor-only-filter
git push origin main
git checkout p2-7-1-minor-only-filter
```

Cloudflare auto-deploys SPA (1-3 min).

- [ ] **Step 4: Run the 4-step smoke matrix from spec § 5.2**

Prereq: at least one pending plugin update exists in prod for `user_id=1`. If `/sites` returns `{"sites":[]}`, re-register SmartCoding (or some site with `update_available = 1` plugins) via Sites → Add before running this step.

Document each step's outcome inline (PASS/FAIL).

| # | Action | Expected |
|---|---|---|
| 1 | Visit `https://app.defynwp.defyn.agency/overview` | Page renders cleanly. If `pending_updates.plugins === 0`, BulkUpdatePluginsButton is HIDDEN (carry-forward from P2.7). If > 0, the button is visible. |
| 2 | Click "Bulk update plugins (N)" → dialog opens | Dialog shows full row set (toggle OFF default). Title shows total `N`. New toggle "Skip major bumps" visible between body text and per-site groups. |
| 3 | Flip "Skip major bumps" ON | Major-bump rows disappear. Title updates to filtered count. Footer counter and primary button label both reflect new count. |
| 4 | Flip "Skip major bumps" OFF | All rows return. Title + footer + primary button restore to original counts. |

If steps 2-4 are unreachable due to zero-sites state, document that and proceed to tag based on test coverage alone (the 11 unit/component tests prove the contract).

- [ ] **Step 5: Tag + push**

```bash
cd /Users/pradeep/Local\ Sites/defynWP
git tag -a p2-7-1-minor-only-filter-complete -m "P2.7.1 — minor-only filter on bulk plugin update dialog

- New apps/web/src/lib/semver.ts with isPluginMajorBump(current, target)
  pure function. npm convention (leftmost segment differs = major).
  Returns false for null/undefined/unparseable inputs (defensive).
- Skip major bumps toggle in ConfirmBulkUpdatePluginsDialog (default OFF,
  between body text and per-site groups). When ON, major-bump rows
  hidden entirely; title + footer counter + primary button label all
  reflect filtered pool.
- 11 new tests (8 semver parameterized + 3 dialog filter behavior).
  Existing 4 dialog tests unchanged.
- No dashboard plugin rebuild (stays at v0.8.0). No schema change
  (stays at v6). No connector change (stays at v0.1.7).
- Spec: docs/superpowers/specs/2026-06-09-p2-7-1-minor-only-filter-design.md
"
git push origin p2-7-1-minor-only-filter-complete
```

- [ ] **Step 6: Update MEMORY**

Append to `~/.claude/projects/-Users-pradeep-Local-Sites-defynWP/memory/MEMORY.md`'s main bullet (the long line at the top), adding this sentence at the end:

> "**P2.7.1 (minor-only filter on bulk plugin update dialog) COMPLETE 2026-06-09** — tag `p2-7-1-minor-only-filter-complete`, SPA-only change (no dashboard rebuild — stays at v0.8.0). New `apps/web/src/lib/semver.ts` with `isPluginMajorBump(current, target)` pure function (npm convention — leftmost segment differs = major; returns false for null/undefined/unparseable). New 'Skip major bumps' toggle in `ConfirmBulkUpdatePluginsDialog` (default OFF, between body text and per-site groups). When ON: major-bump rows hidden entirely via `visibleRows = useMemo(rows.filter(!isPluginMajorBump))`; `allKeys`, `grouped`, `totalCount` re-derive from `visibleRows` so title + footer counter + primary button label all reflect filtered counts. Existing `useEffect([open, allKeys])` re-fires on toggle (allKeys derived from visibleRows) so checkedKeys re-seeds to all visible rows on every flip — manual unchecks reset on toggle flip (same trade-off as existing 're-seed on open' pattern). 11 new tests green (8 semver parameterized + 3 dialog). Existing 4 dialog tests preserved. Distinct from P2.4.1's `SiteCoreCard.tsx::isMinorBump` (WP-core convention major.minor — kept separate for now; share-extract deferred to P2.8). Next: P2.8 (bulk theme updates)."

Any new plan-bug lessons surfaced during execution go into MEMORY.md.

---

## Self-review — coverage against spec

Walking the spec sections to confirm every requirement maps to a task:

- **Spec § 1 architecture** — covered collectively across Tasks 1-2.
- **Spec § 2 helper logic + edge cases** — Task 1 (helper + 8 tests covering all 8 spec edge cases).
- **Spec § 3.1 new `skipMajor` state** — Task 2 Edit 3.2.
- **Spec § 3.2 `visibleRows` useMemo + `allKeys`/`grouped`/`totalCount` re-derive** — Task 2 Edits 3.2, 3.3, 3.4, 3.5.
- **Spec § 3.3 re-seed behavior via existing useEffect** — Task 2 — NO code change to the useEffect; the indirect dependency on `visibleRows` flows through `allKeys`. Test 3 (`skipMajorToggleResetsCheckedKeysWhenFlipped`) verifies.
- **Spec § 3.4 toggle JSX placement** — Task 2 Edit 3.6.
- **Spec § 3.5 title + footer + primary button automatically reflect filtered counts** — derived from `totalCount` and `selectedCount` which are now driven by the filter. Test 2 (`skipMajorToggleOnHidesMajorRowsAndUpdatesCounts`) verifies all three.
- **Spec § 4.1 8 semver tests** — Task 1 Step 1.
- **Spec § 4.2 3 dialog tests + ROWS_WITH_MAJOR** — Task 2 Step 1.
- **Spec § 5 manual smoke flow** — Task 3.
- **Spec § 6 out of scope** — N/A (informational).
- **Spec § 7 plan-author notes (10 plan-bug traps)** — all 10 surfaced in workflow conventions block at top.
- **Spec § 8 acceptance criteria** — Task 3 (build + smoke + tag + MEMORY).

All sections covered. ✅

## Self-review — placeholder scan

Searched for `TBD`, `TODO`, `implement later`, `fill in`, `similar to`, "add appropriate" — none present in concrete code blocks. ✅

## Self-review — type consistency

- `isPluginMajorBump(current: string | null | undefined, target: string | null | undefined): boolean` signature consistent across Task 1 helper, Task 1 test imports, Task 2 dialog import + call site.
- `skipMajor: boolean` state consistent across Task 2 Edit 3.2 (useState), Edit 3.6 (checkbox), and Test 2 (`fireEvent.click(... /skip major bumps/i)`).
- `visibleRows: PendingPluginUpdateRow[]` consistent across Task 2 Edits 3.2 (useMemo type), 3.3 (allKeys derives from), 3.4 (grouped iterates).
- `ROWS_WITH_MAJOR` fixture shape matches the existing `ROWS` shape (same 6 fields) — consistent across all 3 new tests.

No drift. ✅

---

## Execution handoff

**Plan complete and saved to `docs/superpowers/plans/2026-06-09-p2-7-1-minor-only-filter.md`. Two execution options:**

**1. Subagent-Driven (recommended)** — Fresh subagent per task, two-stage review (spec compliance + code quality) between tasks. What every prior P2.x phase used.

**2. Inline Execution** — Execute tasks in this session via the executing-plans skill, batch with checkpoints.

**Which approach?**
