# P2.7.1 — "Minor only" filter on the bulk plugin update dialog (Design Spec)

**Date:** 2026-06-09
**Status:** Approved (brainstorming complete §1→§5)
**Predecessor:** P2.7 — Bulk plugin updates across fleet, tag `p2-7-bulk-plugin-updates-complete` (commit `642a2db`). Dashboard v0.8.0 live in prod.
**Successor candidates:** P2.8 (bulk theme updates — would reuse the semver helper for themes).
**Spec scope:** Add a `Skip major bumps` toggle to the existing `ConfirmBulkUpdatePluginsDialog`. When ON, rows where `current_version → target_version` is a MAJOR bump (npm convention — leftmost segment changes) are hidden entirely from the dialog. Title + footer counter + primary button label all reflect the filtered pool. Pure SPA change — no backend, no schema, no connector.

---

## §1. Architecture overview

**Goal:** let operators say "skip plugins where the major version changes" in one click. The existing P2.7 bulk dialog already supports manual per-pair unchecking; this adds a bulk filter for the most common safety preference.

**Tech stack:** Pure SPA change. Dashboard stays at **v0.8.0**. Connector stays at **v0.1.7**. Schema stays at **v6**.

**Components:**

| File | Responsibility |
|---|---|
| `apps/web/src/lib/semver.ts` (new) | Single export `isPluginMajorBump(current, target): boolean` — npm-style semver compare (leftmost segment only). ~15 lines. |
| `apps/web/tests/lib/semver.test.ts` (new) | 8 parameterized tests covering `1.0.0→2.0.0` (major), `1.0.0→1.1.0` (minor), `1.0.0→1.0.1` (patch), same-version, null/undefined inputs, and edge cases (`v2`, `1.0-beta`, missing segments). |
| `apps/web/src/components/overview/ConfirmBulkUpdatePluginsDialog.tsx` (modify) | Add `skipMajor` `useState` (default `false`), toggle JSX between body text and per-site groups, filter `rows` via `useMemo` based on `skipMajor`, re-seed `checkedKeys` on visible-row change. |
| `apps/web/tests/components/overview/ConfirmBulkUpdatePluginsDialog.test.tsx` (extend) | Add 3 new tests: toggle OFF preserves current behavior, toggle ON hides major rows, primary button label reflects filtered selected count. |

**No PHP changes. No connector changes. No schema changes.** Dashboard plugin stays at v0.8.0 (no backend wire change). The SPA build will pick up the new helper + toggle on next push to main.

---

## §2. Helper logic — `apps/web/src/lib/semver.ts`

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

**Edge case decisions:**

| Input | Output | Rationale |
|---|---|---|
| `('1.0.0', '2.0.0')` | `true` | Major version changed — npm convention. |
| `('1.0.0', '1.5.0')` | `false` | Minor bump. |
| `('1.0.0', '1.0.5')` | `false` | Patch bump. |
| `('1.0.0', '1.0.0')` | `false` | Same version. |
| `('1.0.0', null)` | `false` | Defensive — if backend omits target, don't auto-hide. |
| `(null, '2.0.0')` | `false` | Defensive — if backend omits current, don't auto-hide. |
| `('1.0-beta', '2.0')` | `true` | `parseInt('1', 10) === 1`, `parseInt('2', 10) === 2` — different majors. |
| `('v2', '3')` | `false` | `parseInt('v2', 10) === NaN` — conservative fallback returns `false`. |

**`v2 → 3` edge-case rationale:** the helper returns `false` when EITHER major is unparseable. This is the conservative default: we don't auto-hide when version strings can't be parsed. If we wanted to handle the `v` prefix specifically, we'd need a sanitization pass (`current.replace(/^v/, '')`), which we don't (YAGNI — plugin version strings from WordPress.org universally lack the `v` prefix).

---

## §3. Dialog change — `ConfirmBulkUpdatePluginsDialog.tsx`

### 3.1 New state

```tsx
const [skipMajor, setSkipMajor] = useState(false);
```

Default `false` — toggle is opt-in. Matches the Overview header count when dialog opens.

### 3.2 Filter rows via useMemo

```tsx
const visibleRows = useMemo(
  () => skipMajor
    ? rows.filter((r) => !isPluginMajorBump(r.current_version, r.target_version))
    : rows,
  [rows, skipMajor],
);
```

The existing `allKeys` and `grouped` `useMemo` blocks are updated to derive from `visibleRows` instead of `rows`:

```tsx
const allKeys = useMemo(
  () => visibleRows.map((r) => `${r.site_id}:${r.slug}`),
  [visibleRows],
);

const grouped = useMemo(() => {
  const map = new Map<string, PendingPluginUpdateRow[]>();
  for (const row of visibleRows) {
    const list = map.get(row.site_label) ?? [];
    list.push(row);
    map.set(row.site_label, list);
  }
  return Array.from(map.entries());
}, [visibleRows]);

const totalCount = visibleRows.length; // was rows.length
```

### 3.3 Re-seed `checkedKeys` on visible-row change

The existing `useEffect` keyed on `[open, allKeys]` already re-seeds when `allKeys` changes. Since `allKeys` is now derived from `visibleRows`, flipping the toggle triggers the same re-seed behavior — `checkedKeys` resets to all currently visible rows. The dialog opening also still triggers re-seed (open transitioning false → true).

```tsx
// Unchanged from P2.7 — but now `allKeys` reflects `visibleRows`
useEffect(() => {
  if (open) {
    setCheckedKeys(new Set(allKeys));
    setShowAll(false);
    cancelRef.current?.focus();
  }
}, [open, allKeys]);
```

**Behavior trade-off:** flipping the toggle resets any manual unchecks the operator had made before the toggle flip. This is the same trade-off the existing dialog already makes when re-opening. The simpler mental model wins: "toggle is a fresh slate."

### 3.4 Toggle JSX placement

Between the body explanatory text and the per-site groups:

```tsx
<div className="mt-3 space-y-2 text-sm text-zinc-700">
  <p>This will run the plugin upgrader on every checked pair below. Each site briefly enters maintenance mode during its update.</p>
  <p>Uncheck any pair you want to skip — server fans out exactly what's checked. Already-updated rows are silently no-op'd.</p>
</div>

{/* NEW — P2.7.1 */}
<label className="mt-3 flex items-center gap-2 text-sm text-zinc-700">
  <input
    type="checkbox"
    checked={skipMajor}
    onChange={(e) => setSkipMajor(e.target.checked)}
  />
  Skip major bumps
  <span className="text-xs text-zinc-500">(hide updates where the major version changes, e.g. 1.x → 2.x)</span>
</label>

<div className="mt-3 space-y-2">
  {visibleGroups.map(([label, groupRows]) => (
    <PendingPluginUpdatesGroup .../>
  ))}
</div>
```

### 3.5 Title + footer + primary button — automatically reflect filtered counts

These all derive from `totalCount` (`= visibleRows.length`) and `selectedCount` (`= checkedKeys.size`) which are now driven by the filter:

```tsx
<h3>🛑 Bulk update {totalCount} plugins across {grouped.length} sites?</h3>
// ↑ Title shows filtered total (e.g. 27 if 20 majors hidden)

<p>{selectedCount} selected of {totalCount} available</p>
// ↑ Footer counter shows filtered counts

<Button>🛑 Bulk update {selectedCount} plugins</Button>
// ↑ Primary button shows filtered selected count
```

No additional changes — the existing render logic flows through.

---

## §4. Testing strategy

Total: **~11 new tests** (8 semver + 3 dialog).

### 4.1 `apps/web/tests/lib/semver.test.ts` (NEW — 8 tests)

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

### 4.2 `apps/web/tests/components/overview/ConfirmBulkUpdatePluginsDialog.test.tsx` (EXTEND — 3 new tests)

Existing 4 tests (`cancelHasDefaultFocus`, `primaryButtonUsesDestructiveVariant`, `primaryButtonDisabledWhenZeroSelected`, `footerCounterUpdatesLive`) MUST still pass. Add:

```ts
it('skipMajorToggleOffShowsAllRows', () => {
  // Default — toggle is off, all rows visible (mix of major + minor)
});

it('skipMajorToggleOnHidesMajorRowsAndUpdatesCounts', () => {
  // Flip toggle on — major-bump row disappears, title shows reduced count,
  // footer shows reduced "X selected of Y available", primary button label
  // shows reduced count.
});

it('skipMajorToggleResetsCheckedKeysWhenFlipped', () => {
  // Manually uncheck a minor row, flip toggle on — checkedKeys is re-seeded
  // to all visible (post-filter) rows. Confirms the existing P2.7 re-seed
  // pattern carries through.
});
```

Existing test fixture `ROWS` (3 rows: akismet 5.3 → 5.3.1, yoast 22.5 → 22.6, jetpack 13.1 → 13.2) has NO major bumps — all are minor/patch. The new tests need an extended fixture that includes at least one major bump (e.g., `elementor 3.18.2 → 4.0.0`). Define a new local `ROWS_WITH_MAJOR` constant in the test file for the 3 new tests; leave `ROWS` as-is for the existing 4 tests.

**Test method names MUST be EXACTLY:**
- `skipMajorToggleOffShowsAllRows`
- `skipMajorToggleOnHidesMajorRowsAndUpdatesCounts`
- `skipMajorToggleResetsCheckedKeysWhenFlipped`

---

## §5. Manual smoke flow

### 5.1 Pre-smoke setup

Build SPA only (no dashboard rebuild needed):

```bash
cd "/Users/pradeep/Local Sites/defynWP/apps/web"
pnpm build
ls -lah dist/index.html dist/assets/*.js | head -3
```

Target dist size: similar to v0.8.0 + ~1KB for the new helper + toggle.

Push branch + main → Cloudflare auto-deploys (1-3 min).

### 5.2 Smoke matrix — 4 steps

Since this is SPA-only with no destructive backend wire, all smoke steps are visual.

| # | Action | Expected |
|---|---|---|
| 1 | Visit `https://app.defynwp.defyn.agency/overview` | Page renders cleanly. If `pending_updates.plugins === 0`, BulkUpdatePluginsButton is HIDDEN (carry-forward from P2.7). If > 0, the button is visible. (Prereq for steps 2-4: at least one pending plugin update exists in prod for `user_id=1`.) |
| 2 | Click "Bulk update plugins (N)" → dialog opens | Dialog shows full row set (toggle OFF default). Title shows total `N`. New toggle "Skip major bumps" visible between body text and per-site groups. |
| 3 | Flip "Skip major bumps" ON | Major-bump rows disappear. Title updates to filtered count (e.g., from `Bulk update 47 plugins` → `Bulk update 27 plugins` if 20 were major). Footer counter and primary button label both reflect new count. |
| 4 | Flip "Skip major bumps" OFF | All rows return. Title + footer + primary button restore to original counts. |

### 5.3 Cleanup

None. SPA-only change.

### 5.4 Tag + push

```bash
git tag -a p2-7-1-minor-only-filter-complete -m "P2.7.1 — minor-only filter on bulk plugin update dialog"
git push origin p2-7-1-minor-only-filter-complete
```

Push only after the 4 smoke steps green.

---

## §6. Out of scope (deferred)

| Deferred | What |
|---|---|
| **Future** | Per-pair "this is a major bump" visual badge when toggle is OFF. Operator sees the full list with majors visible but tagged — currently no tagging. |
| **Future** | "Skip pre-releases" toggle (separate filter for `1.0-beta`, `1.0-rc.1`, etc.). |
| **Future** | Compatibility check ("Is plugin XYZ compatible with WordPress 6.5?") — P2.4.1 has plumbing for `tested_up_to` but the plugin dialog doesn't surface it. |
| **Future** | Persist toggle state in operator's local storage so they don't have to flip it on every dialog open. Currently resets to OFF each open. |
| **Future** | Refactor P2.4.1's `SiteCoreCard.tsx::isMinorBump` to use the new shared `semver.ts` module. P2.4.1 uses WP-core convention (major.minor must match); plugins use npm convention (major only). The two functions could co-exist in `semver.ts` with distinct names (`isPluginMajorBump`, `isWpCoreMinorBump`). Not in scope for P2.7.1; only do this when P2.8 (bulk themes) needs to share the infrastructure. |

---

## §7. Plan-author notes (carry-overs for writing-plans)

**Branch off `p2-7-bulk-plugin-updates`** (current tip `642a2db` — and `main` now since just ff'd). Branch name: `p2-7-1-minor-only-filter`.

**Plan-bug traps to internalise:**

1. **Test method names MUST be EXACT.** The 3 new dialog tests are `skipMajorToggleOffShowsAllRows`, `skipMajorToggleOnHidesMajorRowsAndUpdatesCounts`, `skipMajorToggleResetsCheckedKeysWhenFlipped`. The existing 4 tests must still pass unchanged.

2. **Default state of `skipMajor` is `false`** — opt-in. Matches the Overview header counter on dialog open.

3. **Existing test fixture `ROWS` has NO major bumps** — all are minor/patch (akismet 5.3 → 5.3.1, yoast 22.5 → 22.6, jetpack 13.1 → 13.2). Define a separate `ROWS_WITH_MAJOR` for the 3 new tests that include at least one major (e.g., `elementor 3.18.2 → 4.0.0`). DO NOT modify the existing `ROWS` — that would force-update 4 existing tests for no reason.

4. **Toggle JSX placement:** between the body explanatory `<div>` and the per-site groups `<div>`. NOT in the footer. NOT in the title.

5. **`allKeys`, `grouped`, `totalCount` all derive from `visibleRows`** — NOT from `rows`. The filter must flow through all derived state.

6. **Re-seed behavior:** the existing `useEffect([open, allKeys])` will re-fire when the toggle changes (because `allKeys` is derived from `visibleRows`). DO NOT add a separate effect for `[skipMajor]` — the indirect dependency already works.

7. **Manual unchecks are reset when toggle flips** — this is the same trade-off the existing dialog already makes on re-open. The simpler mental model wins ("toggle is a fresh slate"). Test 3 (`skipMajorToggleResetsCheckedKeysWhenFlipped`) asserts this.

8. **`isPluginMajorBump` returns `false` for null/undefined/unparseable** — defensive. Tests cover all three.

9. **No dashboard plugin rebuild needed.** No version bump on `defyn-dashboard.php` / `composer.json` / `readme.txt`. SPA-only change.

10. **Smoke matrix is § 5.2 of this spec — 4 visual steps.** Tag only after all 4 pass. Prereq: at least one pending plugin update exists in prod for `user_id=1` (P2.7 smoke documented zero-sites state; same workaround applies — re-register a site with `update_available = 1` plugins before smoke).

**Estimated plan size: ~3 TDD tasks** across 3 phases:

- **Phase A — Helper (1 task):**
  - Task 1: `apps/web/src/lib/semver.ts` + 8 parameterized tests
- **Phase B — Dialog wiring (1 task):**
  - Task 2: `ConfirmBulkUpdatePluginsDialog` extension (toggle + filter + 3 new tests). Confirm existing 4 dialog tests still pass.
- **Phase C — Ship (1 task):**
  - Task 3: Build SPA + push + 4-step smoke + tag `p2-7-1-minor-only-filter-complete` + MEMORY entry.

---

## §8. Acceptance criteria

P2.7.1 is shipped when:

- [ ] ~11 new tests green in CI (8 semver + 3 dialog)
- [ ] Existing 4 dialog tests still green (no regression)
- [ ] SPA built via `pnpm build` + pushed to main → Cloudflare auto-deploys
- [ ] Smoke matrix § 5.2 steps 1-4 all green
- [ ] Tag `p2-7-1-minor-only-filter-complete` pushed
- [ ] MEMORY.md updated with any plan-bug lessons surfaced during execution
