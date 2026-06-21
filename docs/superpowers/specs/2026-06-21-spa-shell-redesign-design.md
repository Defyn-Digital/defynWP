# DefynWP SPA Redesign — Slice 1: App Shell + Design System

**Date:** 2026-06-21
**Status:** Approved (brainstorm), ready for writing-plans
**Scope:** `apps/web` (React 18 + TS + TanStack Query + Tailwind + shadcn/ui) — **SPA-only.** No backend, connector, schema, or version changes. Ships via the normal Cloudflare Pages auto-deploy from `main`.

## Goal

Replace the fragmented, shell-less SPA chrome with a single cohesive **left-sidebar app shell** and an expanded, on-brand **design system**, so every page instantly looks professional and consistent (ManageWP-calibre) and gains real navigation — *without* yet bespoke-redesigning any page's internals. This is the foundation the later page-redesign slices build on.

## Why this is Slice 1

Today there is **no shared layout** — `RequireAuth` renders each route's bare `<Outlet/>`, and every page imports its own header nav links (`components/nav/{Jobs,Monitoring,Security,Insights,Settings}NavLink.tsx`). There is **no "Sites" link anywhere**. The shadcn primitive set is minimal (alert, badge, button, card, input, label, switch, tooltip) and the theme tokens are bare (`background, foreground, primary, border, ring`). Because the shell wraps *every* route, shipping it + the expanded tokens restyles the whole app at once — the highest-leverage first move.

## Decisions (locked in brainstorm)

| Decision | Choice |
|---|---|
| Layout | **Left sidebar** + topbar (not top-nav) |
| Aesthetic | **Branded Navy** — navy `#26215C` sidebar, light content, indigo accent |
| Component strategy | **Extend the existing shadcn/ui** (add missing primitives; do not swap libraries) |
| Theme | **Light only** (dark mode deferred — YAGNI) |
| Slicing | Shell + design system first; then Overview → Sites list → Site detail (tabbed) → the rest |
| Reports nav | **No top-level item** — reports remain per-site (status quo) |

## 1. Design tokens (`apps/web/src/index.css`)

`tailwind.config.ts` already maps Tailwind colours to `hsl(var(--token))`. Expand `:root` in `src/index.css` from the current 5 vars to the full shadcn token set, anchored to Branded Navy. Values below are the **hex anchors**; the implementer converts each to the `H S% L%` triple the CSS vars use (e.g. `--primary: 243 75% 59%;`). Also extend `tailwind.config.ts` `theme.extend.colors` to expose the new tokens (card, muted, secondary, accent, destructive, popover, input, sidebar, success, warning, info, and their `-foreground` pairs).

Content surface:
- `--background` `#F8FAFC` · `--foreground` `#0F172A`
- `--card` `#FFFFFF` · `--card-foreground` `#0F172A`
- `--muted` `#F1F5F9` · `--muted-foreground` `#64748B`
- `--border` `#E2E8F0` · `--input` `#E2E8F0` · `--ring` `#6366F1`
- `--popover` `#FFFFFF` · `--popover-foreground` `#0F172A`

Brand / accent:
- `--primary` `#6366F1` (indigo) · `--primary-foreground` `#FFFFFF`
- `--secondary` `#EEF2FF` · `--secondary-foreground` `#3730A3`
- `--accent` `#EEF2FF` · `--accent-foreground` `#3730A3`

Sidebar (the navy rail — its own scope so the rest stays light):
- `--sidebar` `#26215C` · `--sidebar-foreground` `#C7C4E8`
- `--sidebar-accent` `#6366F1` (active item bg) · `--sidebar-accent-foreground` `#FFFFFF`
- `--sidebar-border` `#352F6B` · `--sidebar-muted` `#9A95C9` (inactive icon/label)

Semantic status (used everywhere — health dots, badges, alerts):
- `--success` `#16A34A` / `-foreground` `#FFFFFF`
- `--warning` `#D97706` / `-foreground` `#FFFFFF`
- `--destructive` `#DC2626` / `-foreground` `#FFFFFF`
- `--info` `#2563EB` / `-foreground` `#FFFFFF`

`--radius` stays (currently set). Keep the existing `Button`/`Badge` variant APIs working — only the resolved colours change.

## 2. shadcn/ui primitives to add

Add these in `apps/web/src/components/ui/` in the **same hand-authored shadcn style** as the existing primitives (CVA + `cn()` from the existing util), pulling the matching Radix package as a dependency:

| Primitive | Radix dep | Used by |
|---|---|---|
| `tabs.tsx` | `@radix-ui/react-tabs` | Site detail (later slice), settings |
| `dropdown-menu.tsx` | `@radix-ui/react-dropdown-menu` | Account menu, row actions |
| `dialog.tsx` | `@radix-ui/react-dialog` | Replaces hand-rolled confirm dialogs (later) |
| `sheet.tsx` | `@radix-ui/react-dialog` | Mobile off-canvas sidebar |
| `avatar.tsx` | `@radix-ui/react-avatar` | Topbar account |
| `separator.tsx` | `@radix-ui/react-separator` | Sidebar groups, layout |
| `skeleton.tsx` | — (CSS only) | Loading states across pages |
| `scroll-area.tsx` | `@radix-ui/react-scroll-area` | Sidebar overflow |

**Scope note:** this slice only *adds* the primitives and uses `sheet/avatar/dropdown-menu/separator/scroll-area/skeleton` in the shell. Migrating existing hand-rolled dialogs to `dialog.tsx`, and adding tabs to Site detail, happen in the page slices. Each new primitive ships with a minimal render/interaction test under `apps/web/tests/`.

## 3. App shell architecture

New components under `apps/web/src/components/layout/`:

- **`AppShell.tsx`** — the frame: a CSS grid/flex with a fixed-width `Sidebar` (desktop) + a `<div>` column holding `Topbar` and a scrollable `<main>` that renders `children` (the routed page). Owns the mobile sidebar open/closed state (a `useState` boolean; reset on route change via `useLocation`).
- **`Sidebar.tsx`** — the navy rail: brand logo/wordmark at top, `SidebarNav`, and `SidebarAccount` pinned to the bottom. Uses the `--sidebar*` tokens. On desktop it's always visible (`hidden md:flex`); on mobile it renders inside a `Sheet` driven by `AppShell`'s state.
- **`SidebarNav.tsx`** + **`NavItem.tsx`** — the grouped nav (below). `NavItem` is a `react-router-dom` `NavLink` styled with active state from NavLink's `isActive` (active = `--sidebar-accent` bg). Each item: lucide icon + label.
- **`Topbar.tsx`** — left: a hamburger (`md:hidden`) that toggles the mobile sheet; right: a disabled-looking **global search** placeholder (`input`, non-functional this slice) and the `AccountMenu`. The **page title lives in the in-content `PageHeader`, not the Topbar** — so there is no route→title map to maintain.
- **`AccountMenu.tsx`** — `Avatar` + `DropdownMenu` with the user's name/email and **Sign out** (wires to the existing logout flow used by `clearAccessToken` / auth route).
- **`PageHeader.tsx`** — a reusable in-content header (title + optional subtitle + optional right-aligned actions slot). **In scope:** pages render their heading via `PageHeader` (this is where the page title lives), and existing per-page header actions (e.g. `SyncAllSitesButton`, the `Scan all` button) move into its actions slot.

**Wiring (`apps/web/src/App.tsx`):** introduce a layout route so the shell wraps every authed page exactly once:
```
<Route element={<RequireAuth />}>
  <Route element={<AppShell><Outlet/></AppShell>}>   // new layout route
    ...all existing authed routes unchanged...
  </Route>
</Route>
```
`RequireAuth` keeps doing auth-gating only. `Login` stays outside the shell.

## 4. Navigation information architecture

Sidebar groups (lucide icons in parentheses), each a `NavItem` to an existing route:

- **Main:** Dashboard `/overview` (`LayoutDashboard`) · **Sites** `/sites` (`Globe`)  ← the previously-missing link
- **Fleet:** Monitoring `/monitoring` (`Activity`) · Security `/security` (`ShieldCheck`) · Insights `/insights` (`BarChart3`)
- **Operations:** Jobs `/jobs` (`ListChecks`) · Activity `/activity` (`History`)
- **Settings** `/settings` (`Settings`) — pinned near the account area

Groups separated by `Separator` + a small uppercase `--sidebar-muted` label. Reports = per-site only (no nav item). `/overview/plugins`, `/overview/themes`, `/sites/:id*`, `/jobs/:id` are sub-routes, not top-level items (their active state still highlights the nearest parent).

## 5. Per-page integration & migration

- Pages keep all their content and logic; they now render inside `AppShell` and inherit the new tokens → instantly cohesive. No bespoke page redesign in this slice.
- **Remove** the five `components/nav/{Jobs,Monitoring,Security,Insights,Settings}NavLink.tsx` and their per-page usages/imports (in `routes/Overview.tsx` etc.) — the sidebar owns navigation now. **Delete their tests** (`tests/components/nav/{Settings,Monitoring,Jobs}NavLink.test.tsx`) — they assert components that no longer exist.
- Page-level headers that duplicated nav/back-links are simplified to a `PageHeader` (title + actions). The `SyncAllSitesButton`, `Scan all` button, etc. move into their page's `PageHeader` actions slot (no behaviour change).
- Verify no page test asserts a removed nav link; update any that do.

## 6. Testing

`apps/web/tests/` (flat dir convention):
- `AppShell.test.tsx` — renders the sidebar with **all** nav items incl. a `Sites` link; renders `children`; topbar shows the account menu.
- `SidebarNav.test.tsx` (or within AppShell test) — the active route's `NavItem` carries the active class/`aria-current`.
- Mobile: the hamburger toggles the `Sheet` open/closed.
- A lightweight route smoke: each top-level route still mounts inside the shell without error.
- New primitives each get a minimal test.
- Full suite stays green except the 4 known carry-forwards (`SiteDetail` ×2, `SiteCoreCard` ×2). `pnpm build` (tsc) clean.

## 7. Out of scope (later slices)

- Bespoke redesign of any page's internals (Overview dashboard, Sites list/grid, Site-detail tabs, fleet/reports/jobs/settings) — separate slices.
- Dark mode. Functional global search. Migrating existing hand-rolled dialogs to `dialog.tsx` (the primitive ships now; adoption is incremental).
- Any backend/connector/schema/version change.

## 8. Suggested build order (for the plan)

1. Tokens: expand `src/index.css` + `tailwind.config.ts` colours (Branded Navy).
2. Add primitives: `separator, scroll-area, avatar, dropdown-menu, sheet, skeleton` (+ `tabs`, `dialog` scaffolded for later) with tests.
3. `NavItem` + `SidebarNav` (nav IA).
4. `Sidebar` + `AccountMenu` (+ logout wiring).
5. `Topbar` + `PageHeader`.
6. `AppShell` + the `App.tsx` layout-route wiring.
7. Remove the five `*NavLink` components + their tests + per-page usages; move page header actions into `PageHeader`.
8. Shell + route-smoke tests; full suite + `pnpm build`; ship (Cloudflare).

## Success criteria

A persistent navy sidebar (with a working **Sites** link) + topbar wraps every page; the app reads as one cohesive, on-brand product; mobile collapses the sidebar to a sheet; the old fragmented per-page nav is gone; suite green (bar the 4 carry-forwards); deployed to Cloudflare.
