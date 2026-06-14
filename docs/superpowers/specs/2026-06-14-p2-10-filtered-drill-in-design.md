# P2.10 — Filtered drill-in pages (`/overview/plugins` + `/overview/themes`) (Design Spec)

**Date:** 2026-06-14
**Status:** Approved (brainstorming complete — 4 design decisions locked, user approved §1 then authorized autonomous completion)
**Predecessor:** P2.9 — Bulk-jobs entity, tag `p2-9-bulk-jobs-entity-complete` (commit `375ef0b`). Dashboard v0.9.0 live in prod.
**Successor candidates:** none queued — this is the LAST deferred phase from P2.7 spec § 6. After P2.10 ships, all four P2.7-deferred items are complete (P2.7.1 ✅, P2.8 ✅, P2.9 ✅, P2.10).
**Spec scope:** Two new SPA routes — `/overview/plugins` + `/overview/themes` — rendering the cross-fleet pending-update lists (already served by the P2.7 + P2.8 endpoints) as durable full-page, group-by-site, bulk-select-and-update views. SPA-only: **no backend, no schema, no connector, no version bump.** Dashboard stays v0.9.0, connector v0.1.7, schema v7 — all unchanged. Ships via Cloudflare Pages auto-deploy from main; **no Kinsta plugin reinstall.**

---

## §1. Architecture overview

**Goal:** today the operator sees "Plugin updates: 47" / "Theme updates: 12" count cards on the Overview (`/overview`, P2.5). Clicking a card navigates to `/sites?filter=has-plugin-updates` — a *grouped-by-site* list of sites that have pending updates. To actually act on those updates the operator opens the Overview's bulk-update button → a modal dialog. P2.10 gives the operator a dedicated, bookmarkable full-page view of *every pending plugin (or theme) update across the whole fleet* — the same flat data the bulk dialog feeds on — with the same bulk-select + skip-major + update actions, but as a durable page instead of a modal.

**SPA-only.** The existing `GET /defyn/v1/overview/pending-plugin-updates` (P2.7) and `GET /defyn/v1/overview/pending-theme-updates` (P2.8) endpoints already return the exact flat list these pages need: `[{site_id, site_label, slug, plugin_name|theme_name, current_version, target_version}]`. No new endpoints, no aggregation, no pagination — fleets are small (≤100 sites) and the bulk dialog already renders the same full list without pagination.

**Two new routes:**
- `/overview/plugins` → `OverviewPlugins.tsx`
- `/overview/themes` → `OverviewThemes.tsx`

Both mount inside the existing `RequireAuth` outlet in `App.tsx` (same auth gate as `/overview`, `/jobs`, `/sites`).

**Reuse, not rebuild.** The page is the body of `ConfirmBulkUpdate{Plugins,Themes}Dialog` (P2.7/P2.8/P2.7.1) lifted into a full-page layout, minus the modal chrome, plus a lightweight final-confirm gate:
- `PendingPluginUpdatesGroup` / `PendingThemeUpdatesGroup` (P2.7/P2.8) — per-site collapsible group with grouped checkbox + per-row checkbox. Reused verbatim (props `{siteLabel, rows, checkedKeys, onToggleRow, onToggleGroup}`).
- `isMajorBump` (P2.8 `apps/web/src/lib/semver.ts`) — the skip-major filter predicate. Reused.
- `useBulkUpdatePlugins` / `useBulkUpdateThemes` (P2.7/P2.8 mutation hooks) — POST the selected pairs to the bulk endpoint → server creates a P2.9 bulk job + returns `job_id` → page navigates to `/jobs/{job_id}`.
- `usePendingPluginUpdates` / `usePendingThemeUpdates` (P2.7/P2.8 query hooks) — called with `true` (always-enabled) instead of the dialog's `dialogOpen` flag. The hook signature is `usePendingPluginUpdates(dialogOpen: boolean)` with `enabled: dialogOpen`, so passing `true` makes the page fetch on mount. **Zero hook change.**
- The existing P2.7/P2.8 Zod schemas (`pendingPluginUpdatesSchema`, `pendingThemeUpdatesSchema`, `bulkUpdate{Plugins,Themes}ResponseSchema` with `job_id`).

**New shared logic (extracted, not duplicated):**
- `usePendingUpdatesSelection(rows)` — a SPA hook holding the page's selection state machine (`checkedKeys` Set, `skipMajor` toggle, derived `visibleRows`/`allKeys`/`grouped`/`totalCount`/`siteCount`/`checkedCount`), identical in shape to the internal state of the P2.7.1 dialog but extracted as a reusable hook so both new pages share it. The existing dialogs are NOT refactored onto it (YAGNI — leave working code alone; a future phase can DRY them if desired).
- `ConfirmBulkUpdateGateDialog` — one shared lightweight confirm dialog (parameterized by resource label), NO re-listing.

**Count-card re-pointing:** `PendingUpdatesWidget` (P2.5) plugin card `to` → `/overview/plugins`, theme card `to` → `/overview/themes`. **Core card unchanged** (→ `/sites?filter=has-core-update`) — WP core is one update per site (not a cross-site fan-out), so there is no flat-list endpoint or `/overview/core` page; the grouped-by-site view remains the honest destination for core.

**Version:** dashboard **v0.9.0 unchanged**. Connector v0.1.7, schema v7 — unchanged. No PHP touched.

---

## §2. Page layout

Both pages share the same structure (plugins shown; themes is the mirror with theme copy):

```
┌──────────────────────────────────────────────────────────────────┐
│ ← Overview                                                        │
│                                                                   │
│ Plugin updates across your fleet                    47 pending    │
│                                                                   │
│ ☑ Skip major bumps  (hide updates where the major version        │
│                       changes, e.g. 1.x → 2.x)                    │
├──────────────────────────────────────────────────────────────────┤
│ SmartCoding (5)                                          ▾        │ ← per-site collapsible
│   ☑ Akismet Anti-Spam     5.3 → 5.3.1                             │
│   ☑ Yoast SEO             22.5 → 22.6                             │
│   ☑ Elementor             3.18 → 4.0                              │
│   ☑ WPML                  4.6 → 4.7                               │
│   ☑ Jetpack               13.1 → 13.2                            │
│                                                                   │
│ AcmeBlog (3)                                             ▾        │
│   ☑ Akismet               5.3 → 5.3.1                            │
│   …                                                              │
├──────────────────────────────────────────────────────────────────┤
│ 42 selected of 47 available          [ Update 42 selected ]      │ ← sticky footer, RED button
└──────────────────────────────────────────────────────────────────┘
```

**States:**
- **Loading:** while the query is fetching, show a centered spinner/skeleton (reuse whatever loading affordance `/overview` or `/sites` uses).
- **Empty:** when the list is empty (no pending updates), show a friendly empty state ("No pending plugin updates across your fleet.") with a back-to-Overview link. No footer button.
- **Error:** if the query errors, show an error message with a retry affordance (reuse the codebase's existing query-error pattern).
- **Populated:** header + skip-major toggle + per-site groups + sticky footer.

**Skip-major toggle:** default OFF (opt-in), identical to P2.7.1. When ON, rows where `isMajorBump(current_version, target_version)` is true are hidden; `allKeys`/`grouped`/`totalCount`/`siteCount` all re-derive from `visibleRows`; the footer counter + button label reflect filtered counts. Manual unchecks reset when the toggle flips (same trade-off as the dialog).

**Checkbox seeding:** on page load all rows pre-checked. When the toggle flips (so `allKeys` changes), re-seed `checkedKeys` to all currently-visible rows (same `useEffect`-on-`allKeys` mechanism as the dialog).

**Footer:** sticky at the bottom. Shows "{checkedCount} selected of {totalCount} available" + a RED-tier primary button "Update {checkedCount} selected" (`className="bg-red-600 hover:bg-red-700 text-white"` — shadcn `Button` has no `destructive` variant). Disabled when `checkedCount === 0`.

**Header back-link:** "← Overview" navigates to `/overview`.

---

## §3. Confirm + mutation flow

Clicking "Update {N} selected" opens **`ConfirmBulkUpdateGateDialog`** — a lightweight final gate, NOT a re-listing (the page already shows every pair):

| Element | Copy (plugins; themes swaps "plugin"→"theme") |
|---|---|
| Title | `Update {count} plugins across {siteCount} sites?` |
| Body | `This runs the plugin upgrader on every selected pair. Each site briefly enters maintenance mode during its update.` |
| Cancel | `Cancel` (default focus) |
| Confirm | `Update {count} plugins` (RED — `className="bg-red-600 hover:bg-red-700 text-white"`) |

**On confirm:** the page calls the bulk mutation with the selected pairs:

```ts
mutation.mutate(
  { updates: selectedPairs },   // [{site_id, slug}, ...]
  {
    onSuccess: (data) => {
      setConfirmOpen(false);
      if (data.job_id !== null) {
        navigate(`/jobs/${data.job_id}`);
      }
      // job_id null (all-skipped) is unreachable here — the page only
      // surfaces rows with update_available=1; but guard anyway.
    },
  },
);
```

This is the identical navigate-on-success contract as the P2.9 `BulkUpdate{Plugins,Themes}Button`: a non-null `job_id` navigates to the new job's detail page where the operator watches per-item progress. The server (P2.9) creates the bulk job + items + fleet activity event + fans out the AS jobs — the page changes nothing about that path; it's just another caller of the existing bulk endpoint.

**`ConfirmBulkUpdateGateDialog` is shared** between the two pages via a `resourceLabel: 'plugin' | 'theme'` prop. Single component (~40 lines), parameterized copy. Cancel default focus via `cancelRef` (mirror of P2.6 `ConfirmSyncAllDialog`).

---

## §4. Component & file structure (SPA)

**New files:**

| Path | Responsibility |
|---|---|
| `apps/web/src/lib/usePendingUpdatesSelection.ts` | Shared selection state machine hook (checkedKeys, skipMajor, visibleRows, grouped, counts) |
| `apps/web/src/components/overview/ConfirmBulkUpdateGateDialog.tsx` | Shared lightweight final-confirm dialog (resourceLabel-parameterized) |
| `apps/web/src/routes/OverviewPlugins.tsx` | `/overview/plugins` page — header + skip-major toggle + per-site groups + sticky footer + gate + mutation |
| `apps/web/src/routes/OverviewThemes.tsx` | `/overview/themes` page — theme mirror |
| `apps/web/tests/lib/usePendingUpdatesSelection.test.tsx` | Hook unit tests |
| `apps/web/tests/components/overview/ConfirmBulkUpdateGateDialog.test.tsx` | Gate dialog tests |
| `apps/web/tests/routes/OverviewPlugins.test.tsx` | Page tests (loading/empty/populated/skip-major/select/confirm/navigate) |
| `apps/web/tests/routes/OverviewThemes.test.tsx` | Theme page tests |

**Modified files:**

| Path | What changes |
|---|---|
| `apps/web/src/App.tsx` | Add `/overview/plugins` + `/overview/themes` routes inside the `RequireAuth` outlet |
| `apps/web/src/components/overview/PendingUpdatesWidget.tsx` | Plugin card `to` → `/overview/plugins`; theme card `to` → `/overview/themes`; core card unchanged |
| `apps/web/tests/components/overview/PendingUpdatesWidget.test.tsx` (if exists) | Update the 2 re-pointed card-link assertions |

**`usePendingUpdatesSelection(rows)` contract:**

```ts
interface PendingUpdatesSelection {
  skipMajor: boolean;
  setSkipMajor: (v: boolean) => void;
  visibleRows: PendingRow[];          // skipMajor ? rows.filter(!isMajorBump) : rows
  grouped: Array<[string, PendingRow[]]>;  // [siteLabel, rows] entries from visibleRows
  checkedKeys: Set<string>;            // `${site_id}:${slug}`
  toggleRow: (key: string) => void;
  toggleGroup: (rowKeys: string[], allChecked: boolean) => void;
  totalCount: number;                  // visibleRows.length
  siteCount: number;                   // grouped.length
  checkedCount: number;                // checkedKeys.size
  selectedPairs: Array<{ site_id: number; slug: string }>;  // visibleRows ∩ checkedKeys
}
```

`PendingRow` is a structural union of `PendingPluginUpdateRow` | `PendingThemeUpdateRow` — both have `{site_id, site_label, slug, current_version, target_version}` (the name field differs: `plugin_name` vs `theme_name`). The hook only touches the common fields, so it's generic over `{site_id, site_label, slug, current_version, target_version}`.

---

## §5. Smoke matrix (SPA-only — 5 checks)

| # | Check | Expected | Notes |
|---|---|---|---|
| 1 | SPA build + typecheck | `pnpm build` exits 0 | tsc + vite clean |
| 2 | Full SPA test suite | new tests green + only the 4 documented carry-forward failures | SiteDetail ×2 + SiteCoreCard ×2 |
| 3 | Cloudflare Pages deploy | new bundle live on `app.defynwp.defyn.agency` | auto-deploy from main |
| 4 | Deployed bundle contains P2.10 strings | grep bundle for `across your fleet`, `Skip major bumps`, `Update`, `selected` | literal-string presence |
| 5 | SPA route serves | `GET /overview/plugins` + `/overview/themes` return 200 (client-side routing → index.html) | curl HTTP status |

**Foreclosed (carry-forward):**
- Visual SPA smoke (clicking a card → page → select → confirm → navigate to /jobs) is foreclosed by the UI-password-entry prohibition (the operator logs in themselves). Covered indirectly by the test suite + deployed-bundle string presence.
- A live populated page is foreclosed by the zero-sites prod state (no pending updates exist for user 1 since P2.6+ carry-forward) — the page renders its empty state in prod, which is correct.

---

## §6. Out of scope / deferred

- **Group-by-resource view** ("Akismet — 5 sites on 5.3 → 5.3.1") — a genuinely new lens, but needs client-side aggregation or a backend group-by endpoint. Deferred; the group-by-site view ships first (matches the bulk dialog the operator already knows).
- **Per-row individual "Update" button** — single-site update via the P2.2/P2.3 per-site endpoints. Rejected: it wouldn't create a P2.9 bulk job (inconsistent job-tracking) and adds per-row mutation wiring. Bulk-select is the consistent path.
- **Pagination / sorting / server-side filtering** — fleets are small; the full flat list renders fine (the bulk dialog already does this). Add only if a real large-fleet need emerges.
- **`/overview/core` flat page** — WP core is one update per site, not a fan-out list. No flat endpoint exists; the core card keeps its grouped-by-site `/sites?filter=has-core-update` destination.
- **Refactoring the existing P2.7/P2.8 dialogs onto `usePendingUpdatesSelection`** — leave working code alone. A future cleanup phase can DRY them onto the shared hook.
- **Polling the page** — the mutation navigates away to `/jobs/{id}` on success, so the page doesn't need live refresh. Operator navigates back to re-fetch.

---

## §7. Plan-bug guardrails (encoded for writing-plans)

1. **Reuse the existing query hooks with `true`** — `usePendingPluginUpdates(true)` / `usePendingThemeUpdates(true)`. The `dialogOpen` param IS the `enabled` flag; passing `true` makes the page fetch on mount. Do NOT add a new endpoint or a new hook.
2. **shadcn `Button` has NO `destructive` variant** — footer button + gate confirm use `className="bg-red-600 hover:bg-red-700 text-white"`. Carry-forward from P2.7/P2.8.
3. **Skip-major toggle default `false` (opt-in)** — carry-forward from P2.7.1.
4. **`allKeys`/`grouped`/`totalCount`/`siteCount` ALL derive from `visibleRows`** (NOT raw `rows`) so the skip-major filter flows through the counts, footer, and button label. Carry-forward from P2.7.1.
5. **Re-seed `checkedKeys` on `allKeys` change** (toggle flip) via `useEffect([allKeys])` — NO separate `useEffect([skipMajor])`. Carry-forward from P2.7.1.
6. **`isMajorBump` returns `false` for null/undefined/unparseable** — defensive; helper unchanged from P2.8.
7. **Navigate-on-success gated on `job_id !== null`** — same contract as P2.9 `BulkUpdate{Plugins,Themes}Button`. A non-null `job_id` navigates to `/jobs/{job_id}`.
8. **Core card link unchanged** — only the plugin + theme cards in `PendingUpdatesWidget` re-point. Core stays `/sites?filter=has-core-update`.
9. **Routes inside the `RequireAuth` outlet** in `App.tsx` — `/overview/plugins` + `/overview/themes` are auth-gated, same as `/overview` and `/jobs`.
10. **`useNavigate` requires Router context** — page tests render inside `MemoryRouter` (carry-forward from P2.9 Task 18). Gate dialog tests + hook tests don't need Router.
11. **Gate dialog is a final gate, NOT a re-listing** — it shows only the count summary + confirm/cancel, not the per-site collapsibles (the page already showed those). Single shared component parameterized by `resourceLabel`.
12. **No PHP, no schema, no connector, no version bump** — SPA-only. Dashboard stays v0.9.0. No Kinsta reinstall; Cloudflare Pages auto-deploy only.
13. **Pre-existing carry-forward SPA failures (TOLERATE):** `tests/SiteDetail.test.tsx` ×2 + `tests/components/sites/SiteCoreCard.test.tsx` ×2. Full suite must show ONLY these 4 + 0 new.

---

**Spec status:** ready for writing-plans skill. Estimated ~6 TDD tasks (1 shared hook + 1 gate dialog + 2 page routes + 1 router/widget wiring + 1 ship). SPA-only, no backend.
