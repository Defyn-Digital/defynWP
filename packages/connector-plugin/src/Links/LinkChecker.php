<?php
declare(strict_types=1);

namespace Defyn\Connector\Links;

/** P7.1 — best-effort HTTP check of a single URL. Never throws. */
class LinkChecker
{
    // 4s was too aggressive — slow-but-alive external sites were reported as
    // "unreachable". 10s gives them room while staying inside the scan budget.
    private const TIMEOUT = 10;

    // A real browser User-Agent. The old "DefynWP-LinkChecker/1.0" bot UA was
    // 403/blocked by Cloudflare/bot-protection on many external sites, producing
    // false "blocked" warnings for links that are actually fine.
    private const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';

    /** @return array{status:?int, transport_error:bool} */
    public function check(string $url): array
    {
        $args = [
            'timeout'     => self::TIMEOUT,
            'redirection' => 5,
            'user-agent'  => self::UA,
            'sslverify'   => true,
            'headers'     => [
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
            ],
        ];

        // HEAD first (cheap — no body). Fall back to a browser-style GET when the
        // server errors, refuses HEAD, or blocks the request: many hosts reject
        // HEAD or bot-like requests but answer a normal GET, so retrying avoids
        // false "broken"/"blocked" findings. A genuinely dead/forbidden link
        // still returns the same failing status on GET and is reported correctly.
        $res  = wp_remote_head($url, $args);
        $code = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);

        if (is_wp_error($res) || in_array($code, [0, 401, 403, 405, 406, 429, 500, 502, 503], true)) {
            $res = wp_remote_get($url, $args);
            if (is_wp_error($res)) {
                return ['status' => null, 'transport_error' => true];
            }
            $code = (int) wp_remote_retrieve_response_code($res);
        }

        return $code === 0
            ? ['status' => null, 'transport_error' => true]
            : ['status' => $code, 'transport_error' => false];
    }
}
