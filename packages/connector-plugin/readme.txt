=== DefynWP Connector ===
Contributors: defyn
Tags: management, monitoring, dashboard, sync, multisite-management
Requires at least: 5.5
Tested up to: 6.5
Requires PHP: 8.1
Stable tag: 0.1.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turns a managed WordPress site into a DefynWP-managed agent. Pairs with the central DefynWP Dashboard for sync + health monitoring.

== Description ==

DefynWP Connector is the agent half of the DefynWP platform. Install and activate it on any WordPress site you want to monitor from a central DefynWP Dashboard.

* Generates an Ed25519 keypair on activation (stored in `wp_options`)
* Adds a **Settings → DefynWP Connector** admin page
* Lets a WordPress administrator generate a 12-character connection code (15-minute expiry) to pair with the dashboard
* Exposes signed REST endpoints (`/status`, `/heartbeat`, `/disconnect`) once paired

All cross-site traffic is authenticated with Ed25519 request signatures and replay-protected with a nonce store + ±300-second timestamp window.

== Installation ==

1. Upload the plugin zip via **Plugins → Add New → Upload Plugin**, or extract `defyn-connector/` into `wp-content/plugins/`.
2. Activate **DefynWP Connector** from the Plugins screen.
3. Visit **Settings → DefynWP Connector** and click **Generate Connection Code**.
4. Paste the 12-character code into the central DefynWP Dashboard's "Add Site" form.

== Frequently Asked Questions ==

= Does this plugin send data to any third-party server? =

No. It only responds to signed requests from the specific DefynWP Dashboard instance you paired it with. Pairing is operator-driven and consent-based.

= What happens on uninstall? =

The plugin's stored state (including the Ed25519 keypair) is removed from `wp_options` via `uninstall.php`.

== Changelog ==

= 0.1.5 =
* Feature: new themes endpoints — GET /themes returns the installed-theme inventory (slug, name, version, parent_slug, is_active, update_available, update_version); POST /themes/refresh forces a fresh wp_update_themes() poll; POST /themes/{slug}/update runs Theme_Upgrader on the requested stylesheet. Reuses the existing defyn_connector_upgrade_in_flight transient lock with PluginUpdateController, so concurrent plugin/theme upgrades on the same install serialise (P2.3).

= 0.1.4 =
* Feature: new POST /plugins/{slug}/update signed endpoint runs Plugin_Upgrader for the requested plugin and returns the new version. Per-site transient lock prevents concurrent upgrades on the same install (P2.2).

= 0.1.3 =
* Feature: new `/plugins` (GET) and `/plugins/refresh` (POST) signed endpoints expose the site's plugin inventory + update-available flags. Lays the read foundation for dashboard-driven plugin management (P2.1).

= 0.1.2 =
* Fix: send `Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private` (plus Pragma/Expires) on every `defyn-connector/v1` REST response. Prevents upstream caches (WP.com Batcache, Kinsta, Cloudflare, WP-Rocket, LiteSpeed, NGINX micro-cache) from replaying stale handshake/sync responses to the dashboard.

= 0.1.1 =
* Fix: explicit int cast on countdown output (`Admin\SettingsPage`) to satisfy Plugin Check.
* Fix: switch `Signer` argument-validation exception message to `sprintf()` to satisfy Plugin Check.
* Update: switch plugin header license from "Proprietary" to "GPL v2 or later" and add `readme.txt`.

= 0.1.0 =
* Foundation release. Ed25519 keypair generation, connection-code handshake, signed `/status`/`/heartbeat`/`/disconnect` endpoints.

== Upgrade Notice ==

= 0.1.1 =
Plugin Check compliance pass; no behaviour changes. Safe in-place upgrade.
