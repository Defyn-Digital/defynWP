=== DefynWP Connector ===
Contributors: defyn
Tags: management, monitoring, dashboard, sync, multisite-management
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.2.4
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

= 0.3.0 =
* Connector self-update: new signed POST /self-update endpoint upgrades the connector to a dashboard-authorised release (downloads the package, verifies its SHA-256 against the value in the signed instruction, then overwrite-installs over itself). Reports connector_version + is_wpengine in /status so the dashboard knows which sites need updating. Ends per-site manual connector installs from 0.3.0 onward.

= 0.2.7 =
* Fix: large/slow plugin, theme and core updates no longer stall or time out. The update endpoints now raise the PHP execution limit (set_time_limit 600) and set ignore_user_abort so a slow upgrade runs to completion even if the dashboard's request disconnects (mirroring ManageWP). Previously a large plugin (e.g. Burst Statistics) could be cut off by the default execution limit mid-download.

= 0.2.6 =
* Add: WP Engine update support. WP Engine rejects filesystem-modifying upgrade requests that arrive without a WordPress session (our signed REST request carries none), so plugin/theme/core updates failed with a fast bare 502 and nothing in the PHP log, even though the filesystem is writable. The connector now exposes a signed, read-only `GET /wpe-auth` endpoint that, on WP Engine, mints short-lived WordPress admin auth cookies plus WP Engine's `wpe-auth` token (mirroring the ManageWP Worker); the dashboard attaches these as a Cookie header on the update request so WP Engine permits the in-process upgrade. Off WP Engine no cookies are issued or sent. A `rest_authentication_errors` guard prevents those cookies from tripping the REST cookie-nonce check on our signature-gated routes.

= 0.2.5 =
* Fix: revert the v0.2.4 "force direct filesystem" change. Forcing WordPress's filesystem method to `direct` made locked-down hosts (e.g. WP Engine) WORSE — the forced write grinds past the host's ~60s request cap and the process is killed, returning a bare 502 with no diagnosable body. The connector now lets WordPress resolve the filesystem method exactly as wp-admin / WP-CLI / ManageWP do, while keeping the post-upgrade version-advanced guard that catches a silent no-op.
* Add: read-only `GET /upgrade-diagnostics` endpoint (signed) reporting the host's resolved filesystem method, `WP_PLUGIN_DIR` writability, relevant constants (`FS_METHOD`, `DISALLOW_FILE_MODS`, `AUTOMATIC_UPDATER_DISABLED`), WP Engine detection, and PHP limits — so the cause of a failed update can be inspected without running (and being killed by) a real upgrade.
* Add: a `register_shutdown_function` fatal-catcher on the plugin/theme/core update endpoints. A PHP fatal that aborts mid-upgrade (host time/wall limit, OOM, a plugin's own fatal) now returns a structured `{error:{code,message}}` envelope instead of a bare 502.

= 0.2.4 =
* Fix: force direct filesystem + refresh update list before upgrading; verify the version actually changed and fail with diagnostics instead of reporting a false success. On some hosts WordPress's filesystem-method ownership probe yields a degraded handle whose writes silently no-op, so `Plugin_Upgrader`/`Theme_Upgrader` returned success without writing the new files (e.g. reported `3.5.0 → 3.5.0`). The connector now forces the in-process "direct" filesystem with relaxed credentials (as ManageWP/MainWP/WP-CLI do) and refreshes the update transient before each upgrade, then verifies the on-disk version advanced — if it did not, the upgrade fails with a diagnostic message (`fs_method`, dir-writable, upgrader errors) instead of recording a false success. Core keeps the filesystem fix but skips the version-advanced check (the in-process `$wp_version` global never refreshes mid-request).

= 0.2.3 =
* Fix: refresh plugin/theme caches after an upgrade so the reported version is accurate immediately. After `Plugin_Upgrader`/`Theme_Upgrader` rewrites the files, the connector now flushes WordPress's plugin/theme cache (`wp_clean_plugins_cache`/`wp_clean_themes_cache`), PHP's stat cache, and opcache for the affected file before re-reading the header — previously it read the stale OLD version (e.g. reported `3.5.0 → 3.5.0` after a real 3.5.0→3.5.1 update) and the site kept showing "update available" for a while.

= 0.2.2 =
* Fix: plugin/theme/core updates no longer fail with a bare HTTP 500 on some sites. The upgrader services now load `wp-admin/includes/file.php` (and `misc.php`) so `WP_Filesystem()` is defined in the REST request context — without it `WP_Upgrader::run()` fatally errored on `Call to undefined function WP_Filesystem()`.
* Harden: the plugin/theme/core update controllers now catch `\Throwable` and return a structured 502 (`*.update_failed`) with the real error message instead of letting an unexpected fatal escape as an undiagnosable 500.

= 0.1.7 =
* Add per-request `allow_major` opt-in to /core/update for major version upgrades.
* Add `is_major_update_available` field to /status core block.
* PluginListCollector + ThemeListCollector now emit `tested_up_to` from plugin/theme headers.

= 0.1.6 =
* Feature: WP core minor updates. GET /status now includes a `core` sub-object (update_available, update_version, is_minor_update, is_auto_update_enabled). POST /core/refresh forces a fresh wp_version_check() poll. POST /core/update runs Core_Upgrader on the install — only for minor bumps; major bumps return 409 core.major_update_blocked (deferred to P2.4.1). Shared `defyn_connector_upgrade_in_flight` transient lock now covers all 3 × 3 plugin/theme/core resource collisions on the same install (P2.4).

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
