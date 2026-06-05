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

    private static function clientIp(): string
    {
        // Trust REMOTE_ADDR only — never headers an attacker can spoof. F4+ may add
        // a proxy-aware variant if behind Kinsta's edge, but Kinsta strips and
        // re-emits X-Forwarded-For so trusting it requires explicit whitelisting.
        return is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
    }
}
