# Tomorrow morning — pickup notes

**Last session ended:** 2026-06-07 23:30 (you went to sleep right after this).

## What shipped overnight (no action needed from you)

**SPA UX polish:** `Overview` page now shows **"Last refreshed: 2 minutes ago"** instead of the raw ISO timestamp. Commit `1332816`, pushed to `main`, Cloudflare auto-deploys.

- New helper: `apps/web/src/lib/formatRelativeTime.ts` (zero dependencies, ~50 lines)
- Used by: `apps/web/src/routes/Overview.tsx:46`
- Tested by: `apps/web/tests/lib/formatRelativeTime.test.ts` (7 unit tests, all green)
- No dashboard reinstall needed.

Just hard-refresh `https://app.defynwp.defyn.agency/overview` to see it.

## What's left for P2.6 (the real work, your call to schedule)

P2.5 spec § 7 deferred these to P2.6 — pick whichever bites first:

1. **"Sync all sites now" button on the overview** (smallest scope)
   - 1 new POST endpoint `/defyn/v1/overview/sync-all`
   - Loops `findAllForUser`, fan-outs the existing `SyncSite` AS job per site
   - 1 new mutation hook + 1 button on `Overview.tsx`
   - 10/hour rate limit bucket
   - ~6-8 TDD tasks total

2. **"Update all minor plugins across fleet" button** (medium-large scope)
   - Reuses existing per-site `PluginUpdateController`
   - Needs: progress UI (which sites done, which failed), partial-failure handling, per-site rate-limit awareness, optional "exclude these plugins" preflight
   - ~12-15 TDD tasks
   - Risky in production — operator needs confirmation dialog with a "X plugins on Y sites" summary first

3. **Filtered drill-in views** (UX polish, low risk)
   - Replace `/sites?filter=has-plugin-updates` with proper `/overview/plugins` route that's a real list view (rather than reusing SitesList with a filter)
   - Each row shows: site label + plugin name + current → target version + per-row Update button
   - ~8 TDD tasks

4. **Per-user configurable attention thresholds** (low scope, settings UI)
   - SSL grace days, offline threshold minutes, sync-staleness hours
   - Schema v6 → v7 with `wp_defyn_user_settings` table OR a single `defyn_user_overview_thresholds_{userId}` WP option
   - Tiny settings panel below the overview
   - ~5-7 TDD tasks

**My recommendation when you're back:** start with **#1 (Sync all sites now)** — small, clean, big UX win, sets up the bulk-action infrastructure that #2 will need.

## Plan-bug carry-forward to address whenever convenient

- `SchemaVersionMigrationV4Test::testSchemaVersionConstantIsFour` fails
- `SchemaVersionMigrationV5Test::testSchemaVersionConstantIsFive` fails
- `UninstallTest::testUninstallDropsAllTables` fails

All three pre-existing since P2.4/P2.4.1, NOT introduced by P2.5. Delete or skip the stale `testSchemaVersionConstantIs{N}` tests when we hit a stabilization window.

## Branches + tags state

- **`main`** at `1332816` (P2.5 + the relative-time polish)
- **`p2-5-overview-dashboard`** at same commit (parent of any P2.6 branch)
- **Tags pushed:** `p2-5-overview-dashboard-complete` (on `d601333`, the pre-polish commit — the polish is a quality-of-life add-on, doesn't change the P2.5 phase semantics)
- **Connector** unchanged at `v0.1.7`
- **Dashboard** at `v0.7.0` (no need to bump for the SPA-only polish)

Sleep well 💤. Reply tomorrow with which P2.6 path to start, or just `do #1` and I'll spec + plan + ship.
