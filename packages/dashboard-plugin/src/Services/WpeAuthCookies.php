<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Http\SignedHttpClient;

/**
 * v0.2.6 — fetches the short-lived cookies a WP-Engine-hosted connector needs
 * on its update request so WP Engine permits the filesystem-modifying upgrade.
 *
 * Calls the connector's signed, read-only GET /defyn-connector/v1/wpe-auth.
 * Off WP Engine the connector returns is_wpengine=false and no cookies, and
 * this returns [] — so update jobs behave exactly as before on every non-WPE
 * site. Any failure (unreachable, older connector that 404s the route, bad
 * shape) is non-fatal: we return [] and let the update proceed cookieless
 * (which is correct everywhere except WP Engine).
 */
final class WpeAuthCookies
{
    private const CANONICAL_PATH = '/defyn-connector/v1/wpe-auth';

    public function __construct(
        private readonly SignedHttpClient $http = new SignedHttpClient(),
    ) {
    }

    /**
     * @return array<string, string> cookie name => value, or [] when not WP Engine / on any error
     */
    public function fetch(string $siteUrl, string $privateKeyBase64): array
    {
        $url      = rtrim($siteUrl, '/') . '/wp-json' . self::CANONICAL_PATH;
        $response = $this->http->signedGet($url, $privateKeyBase64, self::CANONICAL_PATH, 15);

        if (($response['status'] ?? 0) !== 200) {
            return [];
        }

        $body = $response['body'] ?? [];
        if (empty($body['is_wpengine']) || empty($body['cookies']) || !is_array($body['cookies'])) {
            return [];
        }

        // Coerce to string=>string; drop anything malformed.
        $cookies = [];
        foreach ($body['cookies'] as $name => $value) {
            if (is_string($name) && is_scalar($value) && $name !== '') {
                $cookies[$name] = (string) $value;
            }
        }
        return $cookies;
    }
}
