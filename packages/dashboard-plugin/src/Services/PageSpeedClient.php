<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Services;

// Not final — subclassed in PerformanceScanService's test to stub fetch().
class PageSpeedClient
{
    private const ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    /** @var callable(string,array):mixed */
    private $http;

    /** Human-readable reason the last fetch() returned null (for diagnostics). */
    public ?string $lastError = null;

    public function __construct(?callable $http = null)
    {
        $this->http = $http ?? static fn (string $url, array $args) => wp_remote_get($url, $args);
    }

    /** @return array{score:int,lcp_ms:?int,cls:?float,inp_ms:?int}|null */
    public function fetch(string $url, string $strategy): ?array
    {
        $query = [
            'url'      => $url,
            'strategy' => $strategy === 'desktop' ? 'desktop' : 'mobile',
            'category' => 'performance',
        ];
        $apiKey = (defined('DEFYN_PAGESPEED_API_KEY') && DEFYN_PAGESPEED_API_KEY !== '')
            ? (string) DEFYN_PAGESPEED_API_KEY
            : (function_exists('get_option') ? (string) get_option('defyn_pagespeed_api_key', '') : '');
        if ($apiKey !== '') {
            $query['key'] = $apiKey;
        }
        $endpoint = self::ENDPOINT . '?' . http_build_query($query);

        $this->lastError = null;
        $res = ($this->http)($endpoint, ['timeout' => 60, 'redirection' => 0]);
        if (is_wp_error($res)) {
            $this->lastError = 'request failed: ' . (string) $res->get_error_message();
            return null;
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code !== 200) {
            $hint = ($code === 429 || $code === 403)
                ? ' — a PageSpeed Insights API key is required (unauthenticated quota is exhausted). Set it in Settings.'
                : '';
            $this->lastError = 'PageSpeed API HTTP ' . $code . $hint;
            return null;
        }
        $data = json_decode((string) wp_remote_retrieve_body($res), true);
        if (!is_array($data) || !isset($data['lighthouseResult'])) {
            $this->lastError = 'PageSpeed API returned an unexpected response.';
            return null;
        }
        $lh    = $data['lighthouseResult'];
        $score = $lh['categories']['performance']['score'] ?? null;
        if ($score === null) {
            $this->lastError = 'PageSpeed API response had no performance score.';
            return null;
        }
        $audits = $lh['audits'] ?? [];
        $num = static function (array $audits, string $id): ?int {
            return isset($audits[$id]['numericValue']) ? (int) round((float) $audits[$id]['numericValue']) : null;
        };
        $cls = isset($audits['cumulative-layout-shift']['numericValue'])
            ? round((float) $audits['cumulative-layout-shift']['numericValue'], 3) : null;
        $inp = $num($audits, 'interaction-to-next-paint') ?? $num($audits, 'experimental-interaction-to-next-paint');

        return [
            'score'  => (int) round((float) $score * 100),
            'lcp_ms' => $num($audits, 'largest-contentful-paint'),
            'cls'    => $cls,
            'inp_ms' => $inp,
        ];
    }
}
