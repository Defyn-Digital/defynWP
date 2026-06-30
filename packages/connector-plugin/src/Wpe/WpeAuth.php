<?php

declare(strict_types=1);

namespace Defyn\Connector\Wpe;

/**
 * WP Engine update support (v0.2.6).
 *
 * WP Engine's platform rejects plugin/theme/core upgrade requests that arrive
 * unauthenticated at the HTTP layer — our signed REST request (Ed25519 at the
 * app layer) carries no WordPress session, so WP Engine bounces it before PHP
 * runs (observed as a ~2s bare 502 with nothing in the site's PHP log, while a
 * logged-in admin's manual upload writes fine).
 *
 * ManageWP solves this (verified against the Worker 4.9.34 installed on the
 * affected site, src/MMB/Stats.php::get_auth_cookies): on WP Engine it mints
 * real WordPress admin auth cookies plus WP Engine's own `wpe-auth` trusted-
 * request token and rides them on every request to the site. We mirror that —
 * the connector mints these (short-lived) and the dashboard attaches them as a
 * Cookie header on the signed update request to WP-Engine-hosted sites only.
 *
 * On non-WP-Engine hosts this returns no cookies (none are needed — proven by
 * a successful connector update on a wordpress.com site).
 */
final class WpeAuth
{
    /** Match ManageWP's salt; verified current in Worker 4.9.34. */
    private const WPE_SALT = 'wpe_auth_salty_dog';

    /** Short cookie lifetime — just long enough to cover one upgrade round-trip. */
    public const COOKIE_TTL = 300;

    public static function isWpEngine(): bool
    {
        if (defined('WPE_APIKEY')) {
            return true;
        }
        if (function_exists('is_wpe') && is_wpe()) {
            return true;
        }
        return function_exists('is_wpe_snapshot') && is_wpe_snapshot();
    }

    /**
     * Mint the cookies WP Engine needs to permit a filesystem-modifying request:
     * a WordPress admin auth cookie + logged-in cookie, and the `wpe-auth` token.
     * Mirrors ManageWP Worker get_auth_cookies(), but with a short TTL and for
     * the lowest-id administrator.
     *
     * @return array<string, string> cookie name => value (empty off WP Engine)
     */
    public static function cookies(int $ttlSeconds = self::COOKIE_TTL): array
    {
        if (!self::isWpEngine()) {
            return [];
        }

        if (!function_exists('wp_generate_auth_cookie')) {
            require_once ABSPATH . 'wp-includes/pluggable.php';
        }

        $cookies = [];

        $userId = self::administratorId();
        if ($userId > 0 && function_exists('wp_generate_auth_cookie')) {
            $expiration = time() + $ttlSeconds;
            $secure     = function_exists('is_ssl') ? is_ssl() : false;
            if (function_exists('apply_filters')) {
                $secure = (bool) apply_filters('secure_auth_cookie', $secure, $userId);
            }

            if ($secure && defined('SECURE_AUTH_COOKIE')) {
                $cookies[SECURE_AUTH_COOKIE] = wp_generate_auth_cookie($userId, $expiration, 'secure_auth');
            } elseif (defined('AUTH_COOKIE')) {
                $cookies[AUTH_COOKIE] = wp_generate_auth_cookie($userId, $expiration, 'auth');
            }

            if (defined('LOGGED_IN_COOKIE')) {
                $cookies[LOGGED_IN_COOKIE] = wp_generate_auth_cookie($userId, $expiration, 'logged_in');
            }
        }

        if (defined('WPE_APIKEY')) {
            $cookies['wpe-auth'] = md5(self::WPE_SALT . '|' . WPE_APIKEY);
        }

        return $cookies;
    }

    private static function administratorId(): int
    {
        if (!function_exists('get_users')) {
            return 0;
        }
        $ids = get_users([
            'role'    => 'administrator',
            'number'  => 1,
            'fields'  => 'ID',
            'orderby' => 'ID',
            'order'   => 'ASC',
        ]);
        return !empty($ids) ? (int) $ids[0] : 0;
    }
}
