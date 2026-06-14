=== DefynWP Dashboard ===
Contributors: defyn
Tags: management, monitoring, dashboard, sync, multisite-management
Requires at least: 5.5
Tested up to: 6.5
Requires PHP: 8.1
Stable tag: 0.10.0
License: Proprietary
License URI: https://defyn.dev/license

Central dashboard for managing multiple WordPress sites — the backend brain of the DefynWP platform. Pairs with the DefynWP Connector installed on each managed site.

== Description ==

DefynWP Dashboard is the operator-facing half of the DefynWP platform. Install and activate it on a single central WordPress instance to manage many remote sites that run the companion DefynWP Connector plugin.

* Exposes a REST API consumed by the DefynWP SPA (Vite + React).
* Manages per-site Ed25519 keypairs encrypted at rest via libsodium secretbox.
* Schedules background sync, health, and plugin-update jobs via Action Scheduler.
* Audits every meaningful event to `wp_defyn_activity_log`.

All cross-site traffic is authenticated with Ed25519 request signatures and replay-protected with a nonce store + ±300-second timestamp window.

== Installation ==

1. Upload the plugin zip via **Plugins → Add New → Upload Plugin**, or extract `defyn-dashboard/` into `wp-content/plugins/`.
2. Define `DEFYN_JWT_SECRET`, `DEFYN_VAULT_KEY`, and `DEFYN_SPA_ORIGIN` in your environment or `wp-config.php`.
3. Activate **DefynWP Dashboard** from the Plugins screen.
4. Log in via the SPA and add your first managed site using a connection code from a connector-installed site.

== Frequently Asked Questions ==

= Does this plugin send data to any third-party server? =

No. It only signs and sends requests to the specific managed sites you connect to it. There is no telemetry.

= What happens on uninstall? =

The plugin's tables (`wp_defyn_sites`, `wp_defyn_connection_codes`, `wp_defyn_refresh_tokens`, `wp_defyn_activity_log`, `wp_defyn_site_plugins`) and stored options are removed via `uninstall.php`.

== Changelog ==

= 0.10.0 =
* Site monitoring (P3.1): schema v7 → v8 adds wp_defyn_site_incidents table (incident tracking with type, severity, opened_at/resolved_at).
* New IncidentService detects and auto-resolves incidents during background syncs.
* GET /defyn/v1/overview gains additive incidents field (open count per severity).
* Minor version bump: new domain entity + schema change is a meaningful surface addition.

= 0.9.0 =
* Bulk-jobs entity: every POST /overview/bulk-update-plugins and POST /overview/bulk-update-themes now creates a tracked job in wp_defyn_bulk_jobs + N child rows in wp_defyn_bulk_job_items. Response envelope adds job_id.
* New GET /defyn/v1/jobs (30/MIN) + GET /defyn/v1/jobs/{id} (30/MIN) feed the new SPA /jobs route and per-job detail view.
* New POST /defyn/v1/jobs/{id}/cancel (5/HR) cancels all queued items via as_unschedule_action. Items already started can't be cancelled.
* New POST /defyn/v1/jobs/{id}/items/{item_id}/retry (20/HR) + POST /defyn/v1/jobs/{id}/retry-failed (5/HR) re-schedule failed items.
* Schema v6 → v7 (additive: 2 new tables, no destructive ALTERs). Self-heal handles upgrade transparently.
* Minor version bump because the new domain entity is a meaningful surface change, not a patch.

= 0.8.1 =
* Bulk theme updates across fleet: POST /defyn/v1/overview/bulk-update-themes fan-outs the existing P2.3 UpdateSiteTheme AS job per confirmed (site, theme) pair. 5/hour rate limit. Single overview.bulk_theme_update_requested activity event captures the fleet-scoped intent.
* New GET /defyn/v1/overview/pending-theme-updates returns a flat list of eligible (site, theme) pairs for the SPA's confirmation dialog. 30/minute rate limit.
* SPA confirmation dialog ships with the "Skip major bumps" toggle baked in from day 1 (mirrors P2.7.1 for plugins). Semver helper isPluginMajorBump renamed to isMajorBump in apps/web/src/lib/semver.ts (resource-agnostic).
* Patch bump because endpoints + event type are additive on top of the v0.8.0 destructive-bulk shape.

= 0.8.0 =
* Bulk plugin updates across fleet: POST /defyn/v1/overview/bulk-update-plugins fan-outs the existing P2.2 UpdateSitePlugin AS job per confirmed (site, plugin) pair. 5/hour rate limit. Single overview.bulk_plugin_update_requested activity event captures the fleet-scoped intent.
* New GET /defyn/v1/overview/pending-plugin-updates returns a flat list of eligible (site, plugin) pairs for the SPA's confirmation dialog. 30/minute rate limit.
* Minor version bump because the destructive bulk operation crosses a meaningful threshold relative to v0.7.1's read-side sync-all.

= 0.7.1 =
* Bulk action on /overview: POST /defyn/v1/overview/sync-all fan-outs the existing SyncSite job for every site the operator owns. 10/hour rate limit. Single overview.sync_all_requested activity event captures the fleet-scoped intent.
* /overview response gains total_sites field for the bulk-action UI counter.

= 0.7.0 =
* Operator overview dashboard via GET /defyn/v1/overview — pending updates summary, sites needing attention, recent activity feed.
* SitesList gains ?filter=has-plugin-updates|has-theme-updates|has-core-update query-string filter for drill-in from overview count cards.

= 0.6.0 =
* Per-site opt-in for major WordPress version upgrades via /sites/{id}/core/allow-major.
* Schema v6: core_allow_major on sites table + tested_up_to on plugins/themes tables.
* UpdateSiteCore job threads allow_major flag to connector on upgrade requests.

= 0.5.0 =
* Feature: operator can update WordPress core (minor versions only) on managed sites from the DefynWP dashboard. New POST /defyn/v1/sites/{id}/core/refresh forces a fresh wp_version_check() poll on the connector. POST /defyn/v1/sites/{id}/core/update schedules an AS job that calls the connector's signed /core/update endpoint with a 300s HTTP timeout. The new SiteCoreCard renders above the SiteSummaryCard with four visual states (up to date / update available / updating / failed) and an amber confirmation dialog. Schema bumps v4 -> v5 — adds 5 new core_update_* columns to wp_defyn_sites + an idx_core_update_available index. Day-1 single-row heal in SitesRepository::markSynced resets stuck failed states when the connector reports no update available. Activity log emits core_update.requested -> core_update.started -> core_update.succeeded|failed triplet. Tighter 3/hour rate limit on the update endpoint (vs themes/plugins at 6/hour). Major bumps (7.0 -> 7.1+) are explicitly blocked at both connector + dashboard with a core.major_update_blocked envelope; deferred to P2.4.1. Auto-runs via plugins_loaded self-heal — no manual deact/react required.

= 0.4.0 =
* Feature: operator can view + update themes on managed sites from the DefynWP dashboard. New GET /defyn/v1/sites/{id}/themes returns the inventory (slug, name, version, parent_slug, is_active, update_available, update_version). POST /defyn/v1/sites/{id}/themes/refresh schedules a fresh inventory pull. POST /defyn/v1/sites/{id}/themes/{slug}/update schedules an AS job that calls the connector's /themes/{slug}/update endpoint with a 120s HTTP timeout. Schema bumps v3 → v4 — adds wp_defyn_site_themes table + drops the now-redundant wp_defyn_sites.active_theme column (the new themes table's is_active=1 row is the source of truth). Auto-runs via plugins_loaded self-heal — no manual deact/react required (P2.3).

= 0.3.1 =
* Fix: schema self-heal on `plugins_loaded` automatically re-installs missing tables (no more manual deact + react after every release).
* Fix: SyncPluginsService auto-clears stuck `update_state=failed` rows whose upgrade actually succeeded out-of-band.
* Fix: SitesRepository surfaces MySQL errors on POST /sites instead of silently 202'ing `{site_id: 0}`.
* Fix: Plugin model serializes the schema v3 fields (`update_state`, `last_update_error`, `last_update_attempt_at`) to GET /sites/{id}/plugins — SPA polling state machine finally observes transitions.
* Fix: SyncPluginsService normalizes incoming plugin slug to folder-only (`akismet` instead of `akismet/akismet.php`) so the P2.2 update route regex matches stored rows.
* Together: eliminates the entire dance of plan-bugs caught during the P2.2 production smoke (P2.2.1).

= 0.3.0 =
* Feature: operator can update individual plugins on managed sites from the DefynWP dashboard. New POST /defyn/v1/sites/{id}/plugins/{slug}/update schedules an AS job that calls the connector's new /plugins/{slug}/update endpoint with a 120 s HTTP timeout, branches on success/409/failure, and writes the result back to wp_defyn_site_plugins. Schema bump v2 → v3 adds update_state, last_update_error, last_update_attempt_at columns (P2.2).

= 0.2.0 =
* Feature: per-site plugin inventory surfaces every installed plugin with update_available + update_version flags. Background sync extension picks up the inventory automatically; new POST /defyn/v1/sites/{id}/plugins/refresh forces an immediate refresh (P2.1).

= 0.1.0 =
* Foundation release. JWT auth (login/refresh/logout/me), per-site Ed25519 handshake + vault, signed `/status` + `/heartbeat` + `/disconnect` outbound to managed sites, recurring Action Scheduler fan-out (sync, health, code cleanup), per-site + global activity log endpoints.

== Upgrade Notice ==

= 0.3.0 =
Adds per-plugin update capability and schema v3 columns. The Activation::SCHEMA_VERSION bump runs automatically on upgrade; if the plugin was upgraded via "Replace current with uploaded version" without firing the activation hook, deactivate and reactivate the plugin once so the new columns land.

= 0.2.0 =
Introduces the per-site plugin inventory table. Safe in-place upgrade; first scheduled `defyn_sync_site` after upgrade populates the new table.
