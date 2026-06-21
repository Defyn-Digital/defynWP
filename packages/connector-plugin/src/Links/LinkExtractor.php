<?php

declare(strict_types=1);

namespace Defyn\Connector\Links;

/**
 * P7.1 — pure extraction of http(s) link occurrences from a post's HTML,
 * resolved to absolute URLs against the site home URL. No WP calls; unit-tested.
 */
final class LinkExtractor
{
    /** @return list<array{url:string, anchor_text:string}> */
    public function extract(string $postContent, string $homeUrl): array
    {
        if (trim($postContent) === '') {
            return [];
        }
        $dom  = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div>' . $postContent . '</div>', LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $out  = [];
        $seen = [];
        foreach ($dom->getElementsByTagName('a') as $a) {
            $href = trim((string) $a->getAttribute('href'));
            if ($href === '') {
                continue;
            }
            $abs = $this->resolve($href, $homeUrl);
            if ($abs === null || isset($seen[$abs])) {
                continue;
            }
            $seen[$abs] = true;
            $text = trim((string) $a->textContent);
            $out[] = [
                'url'         => $abs,
                'anchor_text' => strlen($text) <= 255 ? $text : substr($text, 0, 255),
            ];
        }
        return $out;
    }

    private function resolve(string $href, string $homeUrl): ?string
    {
        if ($href[0] === '#') {
            return null;
        }
        $lower = strtolower($href);
        foreach (['mailto:', 'tel:', 'javascript:', 'data:'] as $bad) {
            if (str_starts_with($lower, $bad)) {
                return null;
            }
        }
        if (str_starts_with($href, '//')) {
            $scheme = parse_url($homeUrl, PHP_URL_SCHEME) ?: 'https'; // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure helper, unit-tested without a WP runtime; wp_parse_url is unavailable here and parse_url is safe on PHP 8.1.
            $href   = $scheme . ':' . $href;
        }
        $parts = parse_url($href); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure helper, unit-tested without a WP runtime; wp_parse_url is unavailable here and parse_url is safe on PHP 8.1.
        if ($parts === false) {
            return null;
        }
        if (isset($parts['scheme'])) {
            $scheme = strtolower($parts['scheme']);
            return ($scheme === 'http' || $scheme === 'https') ? $this->stripFragment($href) : null;
        }
        $home = parse_url($homeUrl); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure helper, unit-tested without a WP runtime; wp_parse_url is unavailable here and parse_url is safe on PHP 8.1.
        if ($home === false || !isset($home['scheme'], $home['host'])) {
            return null;
        }
        $base = $home['scheme'] . '://' . $home['host'] . (isset($home['port']) ? ':' . $home['port'] : '');
        if (str_starts_with($href, '/')) {
            return $this->stripFragment($base . $href);
        }
        $homePath = $home['path'] ?? '/';
        $slash    = strrpos($homePath, '/');
        $dir      = $slash === false ? '/' : substr($homePath, 0, $slash + 1);
        return $this->stripFragment($base . $dir . $href);
    }

    private function stripFragment(string $url): string
    {
        $pos = strpos($url, '#');
        return $pos === false ? $url : substr($url, 0, $pos);
    }
}
