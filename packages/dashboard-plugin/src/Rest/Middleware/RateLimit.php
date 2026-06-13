<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest\Middleware;

use WP_Error;
use WP_REST_Request;

/**
 * Per-IP transient-backed rate limiter.
 *
 * Use as a permission_callback wrapper. Returns true when under the limit so
 * the route's real callback runs. Returns WP_Error (with status:429) when over.
 *
 * WP REST permission_callback only short-circuits on WP_Error or strict
 * false/null — never on WP_REST_Response. The RestRouter has a
 * rest_request_after_callbacks filter that rewraps WP_Error bodies into the
 * spec envelope {error: {code, message}} so the SPA wire format stays consistent.
 */
final class RateLimit
{
    public const LOGIN_LIMIT  = 5;     // requests
    public const LOGIN_WINDOW = 60;    // seconds

    // P2.1 — refresh button on SitePluginsPanel. Operator-triggered, so the
    // bucket is per (userId, siteId) — not per IP. 6/min is generous enough
    // that intentional spam-click hits the limit but a frustrated user
    // double-clicking through a slow render does not.
    public const PLUGINS_REFRESH_LIMIT  = 6;
    public const PLUGINS_REFRESH_WINDOW = 60;

    // P2.2 — per-plugin update button on SitePluginsPanel. Bucket is per
    // (userId, siteId, slug) because a single user might legitimately fan out
    // updates across many plugins on one site in quick succession; bucketing by
    // (userId, siteId) alone would let a 6-plugin batch lock out the 7th. The
    // hour-long window matches the update workflow's natural cadence — heavier
    // than a refresh (real download + write + activation hook), one-shot per
    // plugin rather than a poll loop.
    public const PLUGINS_UPDATE_LIMIT  = 6;
    public const PLUGINS_UPDATE_WINDOW = HOUR_IN_SECONDS;

    // P2.3 — refresh button on SiteThemesPanel. Separate bucket from pluginsRefresh
    // per spec § 5.2 — 6 plugin refreshes per hour don't block a 7th theme refresh.
    public const THEMES_REFRESH_LIMIT  = 6;
    public const THEMES_REFRESH_WINDOW = HOUR_IN_SECONDS;

    // P2.3 — per-theme update button on SiteThemesPanel. Separate bucket from pluginsUpdate
    // per spec § 5.3 — 6 plugin updates per hour must not block a 7th theme update.
    // Per-(user, site) bucket, NOT per-(user, site, slug) like pluginsUpdate, because
    // theme upgrades have higher operator-attention demand (active-theme risk).
    public const THEMES_UPDATE_LIMIT  = 6;
    public const THEMES_UPDATE_WINDOW = HOUR_IN_SECONDS;

    // P2.4 — refresh button on SiteCoreCard. Separate bucket from pluginsRefresh and
    // sitesThemesRefresh per spec § 9 — 6 plugin/theme refreshes per hour don't block
    // a 7th core refresh. Bucket is per-(user, site).
    public const CORE_REFRESH_LIMIT  = 6;
    public const CORE_REFRESH_WINDOW = HOUR_IN_SECONDS;

    // P2.4 — per-core-update button on SiteCoreCard. Bucket is per-(user, site) —
    // core updates are heavyweight (WordPress entire system), so tighter than
    // plugins/themes (3/hour instead of 6/hour). Separate bucket from all other
    // update types per spec § 9.3.
    public const CORE_UPDATE_LIMIT  = 3;
    public const CORE_UPDATE_WINDOW = HOUR_IN_SECONDS;

    // P2.4.1 — toggle for per-site allow_major flag. Separate bucket from
    // sitesCoreRefresh/coreUpdate per spec § 4.8 — toggling is a cheap
    // metadata write, not an upgrade, so the limit is looser (10/hour vs.
    // 3/hour for actual upgrades). Bucket is per-(user, site).
    public const CORE_ALLOW_MAJOR_LIMIT  = 10;
    public const CORE_ALLOW_MAJOR_WINDOW = HOUR_IN_SECONDS;

    // P2.5 — overview dashboard polling endpoint. FIRST per-MINUTE bucket
    // in the project (all prior buckets are per-hour). The SPA polls every
    // 60s while the tab is active = 60/hr from one tab; we cap at 30/min
    // to allow multiple tabs / rapid manual refresh without DoSing the DB.
    public const OVERVIEW_LIMIT  = 30;
    public const OVERVIEW_WINDOW = MINUTE_IN_SECONDS;

    // P2.6 — bulk fan-out endpoint POST /overview/sync-all. Same shape as
    // coreAllowMajor from P2.4.1: per-user, 10/HOUR. Tighter than the
    // /overview read-only poll (which is 30/MINUTE) because each call
    // schedules N AS jobs — runaway bursts would back-pressure the queue.
    public const OVERVIEW_SYNC_ALL_LIMIT  = 10;
    public const OVERVIEW_SYNC_ALL_WINDOW = HOUR_IN_SECONDS;

    // P2.7 — GET /overview/pending-plugin-updates. Per-MINUTE bucket — same shape
    // as P2.5's overview poll because the SPA fetches this on dialog open. NOT
    // HOUR_IN_SECONDS (plan-bug trap #2 — common copy-paste from the bulk endpoint).
    public const OVERVIEW_PENDING_PLUGIN_UPDATES_LIMIT  = 30;
    public const OVERVIEW_PENDING_PLUGIN_UPDATES_WINDOW = MINUTE_IN_SECONDS;

    // P2.7 — POST /overview/bulk-update-plugins. Per-user, 5/HOUR — distinct
    // from P2.6's overviewSyncAll (10/HOUR) and tighter to reflect destructive
    // nature (each call fan-outs N writes). Plan-bug trap #1: window is HOUR_IN_SECONDS.
    public const BULK_PLUGIN_UPDATE_LIMIT  = 5;
    public const BULK_PLUGIN_UPDATE_WINDOW = HOUR_IN_SECONDS;

    // P2.8 — GET /overview/pending-theme-updates. Per-MINUTE bucket — mirror of
    // P2.7's OVERVIEW_PENDING_PLUGIN_UPDATES because the SPA fetches this on
    // dialog open. NOT HOUR_IN_SECONDS (plan-bug trap #1 carry-forward from P2.7).
    public const OVERVIEW_PENDING_THEME_UPDATES_LIMIT  = 30;
    public const OVERVIEW_PENDING_THEME_UPDATES_WINDOW = MINUTE_IN_SECONDS;

    // P2.8 — POST /overview/bulk-update-themes. Per-user, 5/HOUR — mirror of
    // P2.7's BULK_PLUGIN_UPDATE because both fan-out destructive writes.
    // Plan-bug trap #1 carry-forward: window is HOUR_IN_SECONDS, NOT MINUTE.
    public const BULK_THEME_UPDATE_LIMIT  = 5;
    public const BULK_THEME_UPDATE_WINDOW = HOUR_IN_SECONDS;

    // P2.9 — GET /jobs list. Per-MINUTE bucket — the SPA polls every 10s
    // while any job is active (mirror of P2.5's overview() 30/MIN shape).
    public const JOBS_LIST_LIMIT  = 30;
    public const JOBS_LIST_WINDOW = MINUTE_IN_SECONDS;

    /** @return true|WP_Error */
    public static function login(WP_REST_Request $request)
    {
        $ip = self::clientIp();
        $key = 'defyn_rl_login_' . $ip;
        $count = (int) (get_transient($key) ?: 0);

        if ($count >= self::LOGIN_LIMIT) {
            return new WP_Error(
                'auth.rate_limited',
                'Too many login attempts. Try again in a minute.',
                ['status' => 429]
            );
        }

        set_transient($key, $count + 1, self::LOGIN_WINDOW);
        return true;
    }

    /**
     * Permission callback for POST /sites/{id}/plugins/refresh.
     *
     * Chains RequireAuth::check first so the (userId, siteId) bucket key has a
     * real userId to key on — without auth we'd be rate-limiting anonymous
     * traffic by site_id alone, which is a trivial DoS vector against legit
     * operators of that site.
     *
     * Returns the same WP_Error shape as RequireAuth on auth failure (401) so
     * RestRouter::normalizeErrorEnvelope rewraps it consistently; otherwise
     * either true (under limit, controller runs) or a 429 WP_Error.
     *
     * @return true|WP_Error
     */
    public static function pluginsRefresh(WP_REST_Request $request)
    {
        $authResult = RequireAuth::check($request);
        if (is_wp_error($authResult)) {
            return $authResult;
        }

        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request['id'];

        $key   = sprintf('defyn_rl_plugins_refresh_%d_%d', $userId, $siteId);
        $count = (int) (get_transient($key) ?: 0);

        if ($count >= self::PLUGINS_REFRESH_LIMIT) {
            return new WP_Error(
                'plugins.rate_limited',
                'Refresh requested too often. Wait a minute and try again.',
                ['status' => 429]
            );
        }

        set_transient($key, $count + 1, self::PLUGINS_REFRESH_WINDOW);
        return true;
    }

    /**
     * Permission callback for POST /sites/{id}/themes/refresh.
     *
     * Separate transient-bucket from pluginsRefresh — operator clicking
     * "Refresh themes" must not be locked out by prior plugin refreshes.
     * Same auth-chain pattern as pluginsRefresh.
     *
     * @return true|WP_Error
     */
    public static function sitesThemesRefresh(WP_REST_Request $request)
    {
        $authResult = RequireAuth::check($request);
        if (is_wp_error($authResult)) {
            return $authResult;
        }

        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request['id'];

        $key   = sprintf('defyn_rl_themes_refresh_%d_%d', $userId, $siteId);
        $count = (int) (get_transient($key) ?: 0);

        if ($count >= self::THEMES_REFRESH_LIMIT) {
            return new WP_Error(
                'themes.rate_limited',
                'Refresh requested too often. Wait an hour and try again.',
                ['status' => 429]
            );
        }

        set_transient($key, $count + 1, self::THEMES_REFRESH_WINDOW);
        return true;
    }

    /**
     * Permission callback for POST /sites/{id}/plugins/{slug}/update.
     *
     * Same auth-chain pattern as pluginsRefresh — RequireAuth::check first so
     * the bucket key has a real userId. Bucket adds the plugin slug (hashed —
     * slugs can contain characters that are unfriendly to transient option
     * names) so concurrent updates of different plugins on the same site don't
     * starve each other within the hour window.
     *
     * On rate-limit, returns plugins.rate_limited (same code as the refresh
     * limiter — both surface as the same toast in the SPA) with status 429.
     *
     * @return true|WP_Error
     */
    public static function pluginsUpdate(WP_REST_Request $request)
    {
        $authResult = RequireAuth::check($request);
        if (is_wp_error($authResult)) {
            return $authResult;
        }

        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request['id'];
        $slug   = (string) $request['slug'];

        $key   = sprintf('defyn_rl_pluginsUpdate_%d_%d_%s', $userId, $siteId, md5($slug));
        $count = (int) (get_transient($key) ?: 0);

        if ($count >= self::PLUGINS_UPDATE_LIMIT) {
            return new WP_Error(
                'plugins.rate_limited',
                'Too many update requests for this plugin. Try again in an hour.',
                ['status' => 429]
            );
        }

        set_transient($key, $count + 1, self::PLUGINS_UPDATE_WINDOW);
        return true;
    }

    /**
     * Permission callback for POST /sites/{id}/themes/{slug}/update.
     *
     * Separate transient-bucket from pluginsUpdate so a 6-plugin batch in one
     * hour doesn't lock out theme updates. NOTE: per spec § 5.3 this bucket is
     * scoped per-(user, site) — NOT per-(user, site, slug) like pluginsUpdate.
     * Theme upgrades have higher operator-attention demand (active-theme risk)
     * so a coarser bucket is the deliberate safety net.
     *
     * @return true|WP_Error
     */
    public static function themesUpdate(WP_REST_Request $request)
    {
        $authResult = RequireAuth::check($request);
        if (is_wp_error($authResult)) {
            return $authResult;
        }

        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request['id'];

        $key   = sprintf('defyn_rl_themesUpdate_%d_%d', $userId, $siteId);
        $count = (int) (get_transient($key) ?: 0);

        if ($count >= self::THEMES_UPDATE_LIMIT) {
            return new WP_Error(
                'themes.rate_limited',
                'Too many update requests for this site. Try again in an hour.',
                ['status' => 429]
            );
        }

        set_transient($key, $count + 1, self::THEMES_UPDATE_WINDOW);
        return true;
    }

    /**
     * Permission callback for POST /sites/{id}/core/refresh.
     *
     * Separate transient-bucket from pluginsRefresh and sitesThemesRefresh per
     * spec § 9 — a burst of plugin/theme refreshes must not block the core
     * refresh button. Same auth-chain pattern as pluginsRefresh.
     *
     * @return true|WP_Error
     */
    public static function sitesCoreRefresh(WP_REST_Request $request)
    {
        $authResult = RequireAuth::check($request);
        if (is_wp_error($authResult)) {
            return $authResult;
        }

        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request['id'];

        $key   = sprintf('defyn_rl_core_refresh_%d_%d', $userId, $siteId);
        $count = (int) (get_transient($key) ?: 0);

        if ($count >= self::CORE_REFRESH_LIMIT) {
            return new WP_Error(
                'core.rate_limited',
                'Refresh requested too often. Wait an hour and try again.',
                ['status' => 429]
            );
        }

        set_transient($key, $count + 1, self::CORE_REFRESH_WINDOW);
        return true;
    }

    /**
     * Permission callback for POST /sites/{id}/core/update.
     *
     * Separate transient-bucket from sitesCoreRefresh per spec § 9.3 — core
     * updates are heavyweight (full WordPress system), so tighter limit (3/hour
     * vs. 6/hour refresh). Same auth-chain pattern as pluginsUpdate and themesUpdate.
     *
     * @return true|WP_Error
     */
    public static function coreUpdate(WP_REST_Request $request)
    {
        $authResult = RequireAuth::check($request);
        if (is_wp_error($authResult)) {
            return $authResult;
        }

        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request['id'];

        $key   = sprintf('defyn_rl_coreUpdate_%d_%d', $userId, $siteId);
        $count = (int) (get_transient($key) ?: 0);

        if ($count >= self::CORE_UPDATE_LIMIT) {
            return new WP_Error(
                'core.rate_limited',
                'Too many core update requests for this site. Try again in an hour.',
                ['status' => 429]
            );
        }

        set_transient($key, $count + 1, self::CORE_UPDATE_WINDOW);
        return true;
    }

    /**
     * Permission callback for POST /sites/{id}/core/allow-major.
     *
     * Separate transient-bucket from coreUpdate per spec § 4.8 — toggling
     * is cheap (a single column write) so the limit is looser (10/hour vs.
     * 3/hour for actual upgrades). Same auth-chain pattern as coreUpdate.
     *
     * @return true|WP_Error
     */
    public static function coreAllowMajor(WP_REST_Request $request)
    {
        $authResult = RequireAuth::check($request);
        if (is_wp_error($authResult)) {
            return $authResult;
        }

        $userId = (int) $request->get_param('_authenticated_user_id');
        $siteId = (int) $request['id'];

        $key   = sprintf('defyn_rl_coreAllowMajor_%d_%d', $userId, $siteId);
        $count = (int) (get_transient($key) ?: 0);

        if ($count >= self::CORE_ALLOW_MAJOR_LIMIT) {
            return new WP_Error(
                'core.rate_limited',
                'Too many setting changes. Try again in an hour.',
                ['status' => 429]
            );
        }

        set_transient($key, $count + 1, self::CORE_ALLOW_MAJOR_WINDOW);
        return true;
    }

    /**
     * Permission callback for GET /overview.
     *
     * Per-MINUTE bucket (NOT per-hour like every other RateLimit method).
     * Plan-bug trap #1 — copy-paste from coreUpdate's HOUR_IN_SECONDS is wrong.
     *
     * @return true|WP_Error
     */
    public static function overview(WP_REST_Request $request)
    {
        $authResult = RequireAuth::check($request);
        if (is_wp_error($authResult)) {
            return $authResult;
        }

        $userId = (int) $request->get_param('_authenticated_user_id');

        $key   = sprintf('defyn_rl_overview_%d', $userId);
        $count = (int) (get_transient($key) ?: 0);

        if ($count >= self::OVERVIEW_LIMIT) {
            return new \WP_Error(
                'overview.rate_limited',
                'Too many requests. The overview polls every minute — try again shortly.',
                ['status' => 429]
            );
        }

        set_transient($key, $count + 1, self::OVERVIEW_WINDOW);
        return true;
    }

    /**
     * Permission callback for POST /overview/sync-all.
     *
     * Per-user, 10/HOUR — same shape as coreAllowMajor from P2.4.1. The
     * bucket key DOES NOT collide with the /overview read poll's bucket
     * (`defyn_rl_overview_%d`) because this method uses a different prefix.
     * Plan-bug trap #1 — DO NOT copy MINUTE_IN_SECONDS from `overview()`.
     *
     * @return true|WP_Error
     */
    public static function overviewSyncAll(WP_REST_Request $request)
    {
        $authResult = RequireAuth::check($request);
        if (is_wp_error($authResult)) {
            return $authResult;
        }

        $userId = (int) $request->get_param('_authenticated_user_id');

        $key   = sprintf('defyn_rl_overviewSyncAll_%d', $userId);
        $count = (int) (get_transient($key) ?: 0);

        if ($count >= self::OVERVIEW_SYNC_ALL_LIMIT) {
            return new \WP_Error(
                'overview.rate_limited',
                'Too many bulk sync requests. Try again in an hour.',
                ['status' => 429]
            );
        }

        set_transient($key, $count + 1, self::OVERVIEW_SYNC_ALL_WINDOW);
        return true;
    }

    /**
     * Permission callback for GET /overview/pending-plugin-updates.
     *
     * Per-MINUTE bucket — mirrors P2.5's overview() pattern because the SPA
     * fetches this on dialog open. Distinct prefix from defyn_rl_overview_%d.
     *
     * @return true|WP_Error
     */
    public static function overviewPendingPluginUpdates(WP_REST_Request $request)
    {
        $authResult = RequireAuth::check($request);
        if (is_wp_error($authResult)) {
            return $authResult;
        }

        $userId = (int) $request->get_param('_authenticated_user_id');

        $key   = sprintf('defyn_rl_overviewPendingPluginUpdates_%d', $userId);
        $count = (int) (get_transient($key) ?: 0);

        if ($count >= self::OVERVIEW_PENDING_PLUGIN_UPDATES_LIMIT) {
            return new \WP_Error(
                'overview.rate_limited',
                'Too many requests. Try again in a moment.',
                ['status' => 429]
            );
        }

        set_transient($key, $count + 1, self::OVERVIEW_PENDING_PLUGIN_UPDATES_WINDOW);
        return true;
    }

    /**
     * Permission callback for POST /overview/bulk-update-plugins.
     *
     * Per-user, 5/HOUR. Distinct prefix `defyn_rl_bulkPluginUpdate_%d`.
     * Plan-bug trap #1: tighter than overviewSyncAll's 10/HOUR because this
     * fan-outs destructive writes.
     *
     * @return true|WP_Error
     */
    public static function bulkPluginUpdate(WP_REST_Request $request)
    {
        $authResult = RequireAuth::check($request);
        if (is_wp_error($authResult)) {
            return $authResult;
        }

        $userId = (int) $request->get_param('_authenticated_user_id');

        $key   = sprintf('defyn_rl_bulkPluginUpdate_%d', $userId);
        $count = (int) (get_transient($key) ?: 0);

        if ($count >= self::BULK_PLUGIN_UPDATE_LIMIT) {
            return new \WP_Error(
                'bulk.rate_limited',
                'Too many bulk update requests. Try again in an hour.',
                ['status' => 429]
            );
        }

        set_transient($key, $count + 1, self::BULK_PLUGIN_UPDATE_WINDOW);
        return true;
    }

    /**
     * Permission callback for GET /overview/pending-theme-updates.
     *
     * Per-MINUTE bucket — mirror of P2.7's overviewPendingPluginUpdates because
     * the SPA fetches this on dialog open. Distinct prefix from defyn_rl_overview_%d
     * and defyn_rl_overviewPendingPluginUpdates_%d.
     *
     * @return true|WP_Error
     */
    public static function overviewPendingThemeUpdates(WP_REST_Request $request)
    {
        $authResult = RequireAuth::check($request);
        if (is_wp_error($authResult)) {
            return $authResult;
        }

        $userId = (int) $request->get_param('_authenticated_user_id');

        $key   = sprintf('defyn_rl_overviewPendingThemeUpdates_%d', $userId);
        $count = (int) (get_transient($key) ?: 0);

        if ($count >= self::OVERVIEW_PENDING_THEME_UPDATES_LIMIT) {
            return new \WP_Error(
                'overview.rate_limited',
                'Too many requests. Try again in a moment.',
                ['status' => 429]
            );
        }

        set_transient($key, $count + 1, self::OVERVIEW_PENDING_THEME_UPDATES_WINDOW);
        return true;
    }

    /**
     * Permission callback for POST /overview/bulk-update-themes.
     *
     * Per-user, 5/HOUR. Distinct prefix `defyn_rl_bulkThemeUpdate_%d` so it
     * does NOT collide with P2.7's bulkPluginUpdate bucket. Mirror of
     * bulkPluginUpdate — tighter than overviewSyncAll's 10/HOUR because this
     * fan-outs destructive writes. Plan-bug trap #1 carry-forward: window is
     * HOUR_IN_SECONDS, NOT MINUTE.
     *
     * @return true|WP_Error
     */
    public static function bulkThemeUpdate(WP_REST_Request $request)
    {
        $authResult = RequireAuth::check($request);
        if (is_wp_error($authResult)) {
            return $authResult;
        }

        $userId = (int) $request->get_param('_authenticated_user_id');

        $key   = sprintf('defyn_rl_bulkThemeUpdate_%d', $userId);
        $count = (int) (get_transient($key) ?: 0);

        if ($count >= self::BULK_THEME_UPDATE_LIMIT) {
            return new \WP_Error(
                'bulk.rate_limited',
                'Too many bulk update requests. Try again in an hour.',
                ['status' => 429]
            );
        }

        set_transient($key, $count + 1, self::BULK_THEME_UPDATE_WINDOW);
        return true;
    }

    /**
     * Permission callback for GET /jobs.
     *
     * Per-user, 30/MINUTE. Chains RequireAuth::check first (same pattern as
     * every post-P2.1 bucket). Distinct prefix `defyn_rl_jobsList_%d`.
     *
     * @return true|WP_Error
     */
    public static function jobsList(WP_REST_Request $request)
    {
        $authResult = RequireAuth::check($request);
        if (is_wp_error($authResult)) {
            return $authResult;
        }

        $userId = (int) $request->get_param('_authenticated_user_id');

        $key   = sprintf('defyn_rl_jobsList_%d', $userId);
        $count = (int) (get_transient($key) ?: 0);

        if ($count >= self::JOBS_LIST_LIMIT) {
            return new \WP_Error(
                'jobs.rate_limited',
                'Too many requests. The jobs list polls every few seconds — try again shortly.',
                ['status' => 429]
            );
        }

        set_transient($key, $count + 1, self::JOBS_LIST_WINDOW);
        return true;
    }

    private static function clientIp(): string
    {
        // Trust REMOTE_ADDR only — never headers an attacker can spoof. F4+ may add
        // a proxy-aware variant if behind Kinsta's edge, but Kinsta strips and
        // re-emits X-Forwarded-For so trusting it requires explicit whitelisting.
        return is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
    }
}
