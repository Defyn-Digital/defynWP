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

    private static function clientIp(): string
    {
        // Trust REMOTE_ADDR only — never headers an attacker can spoof. F4+ may add
        // a proxy-aware variant if behind Kinsta's edge, but Kinsta strips and
        // re-emits X-Forwarded-For so trusting it requires explicit whitelisting.
        return is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
    }
}
