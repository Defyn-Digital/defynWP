=== DefynWP Dashboard ===
Contributors: defyn
Tags: management, monitoring, dashboard, sync, multisite-management
Requires at least: 5.5
Tested up to: 6.5
Requires PHP: 8.1
Stable tag: 0.3.0
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
