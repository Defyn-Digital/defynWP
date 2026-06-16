<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Services;

// Not final — subclassed in PerformanceScanService's test to stub fetch().
class PageSpeedClient
{
    private const ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    /** @var callable(string,array):mixed */
    private $http;

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
        if (defined('DEFYN_PAGESPEED_API_KEY') && DEFYN_PAGESPEED_API_KEY !== '') {
            $query['key'] = DEFYN_PAGESPEED_API_KEY;
        }
        $endpoint = self::ENDPOINT . '?' . http_build_query($query);

        $res = ($this->http)($endpoint, ['timeout' => 60, 'redirection' => 0]);
        if (is_wp_error($res)) {
            return null;
        }
        if ((int) wp_remote_retrieve_response_code($res) !== 200) {
            return null;
        }
        $data = json_decode((string) wp_remote_retrieve_body($res), true);
        if (!is_array($data) || !isset($data['lighthouseResult'])) {
            return null;
        }
        $lh    = $data['lighthouseResult'];
        $score = $lh['categories']['performance']['score'] ?? null;
        if ($score === null) {
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
