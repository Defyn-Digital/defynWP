<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Services;

/**
 * Report redesign Task 1 — resolves a managed site's own icon URL so the client's
 * logo can appear on their report. Connector-free: reads WordPress's REST index
 * ({siteUrl}/wp-json/), which exposes `site_icon_url` since WP 5.9.
 *
 * Best-effort + cached: the resolved URL (or an empty-string "nothing found"
 * sentinel) is stored in the `defyn_site_logo_{siteId}` transient for a day, so
 * a site without an icon is not re-fetched every report. NEVER throws.
 *
 * Mirrors ReportPdfService's injectable-fetcher pattern: $fetcher returns the
 * raw JSON body string (the default wraps wp_remote_get + wp_remote_retrieve_body).
 */
class SiteLogoResolver
{
    private const TRANSIENT_PREFIX = 'defyn_site_logo_';

    /** @var callable(string):string returns the raw response body for a URL */
    private $fetcher;

    public function __construct(?callable $fetcher = null)
    {
        $this->fetcher = $fetcher ?? [self::class, 'defaultFetcher'];
    }

    /** Default fetcher — wp_remote_get the REST index, return the body string ('' on error). */
    public static function defaultFetcher(string $url): string
    {
        $res = wp_remote_get($url, ['timeout' => 5, 'redirection' => 2]);
        if (is_wp_error($res)) {
            return '';
        }
        return (string) wp_remote_retrieve_body($res);
    }

    /**
     * Resolve the site's icon URL, caching the result for a day.
     * Returns the https icon URL, or null when there is none / on any failure.
     */
    public function resolve(int $siteId, string $siteUrl): ?string
    {
        $key    = self::TRANSIENT_PREFIX . $siteId;
        $cached = get_transient($key);
        if ($cached !== false) {
            return '' === $cached ? null : (string) $cached;
        }

        if (stripos($siteUrl, 'https://') !== 0) {
            set_transient($key, '', DAY_IN_SECONDS);
            return null;
        }

        try {
            $body = (string) ($this->fetcher)(rtrim($siteUrl, '/') . '/wp-json/');
            $data = json_decode($body, true);
            $iconUrl = is_array($data) ? ($data['site_icon_url'] ?? '') : '';
            $resolved = (is_string($iconUrl) && $iconUrl !== '' && stripos($iconUrl, 'https://') === 0)
                ? $iconUrl
                : null;
            set_transient($key, $resolved ?? '', DAY_IN_SECONDS);
            return $resolved;
        } catch (\Throwable $e) {
            set_transient($key, '', DAY_IN_SECONDS);
            return null;
        }
    }
}
