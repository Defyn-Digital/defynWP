<?php
declare(strict_types=1);

namespace Defyn\Connector\Links;

/** P7.1 — best-effort HTTP check of a single URL. Never throws. */
final class LinkChecker
{
    private const TIMEOUT = 5;
    private const UA      = 'DefynWP-LinkChecker/1.0 (+https://defyn.dev)';

    /** @return array{status:?int, transport_error:bool} */
    public function check(string $url): array
    {
        $args = ['timeout' => self::TIMEOUT, 'redirection' => 5, 'user-agent' => self::UA, 'sslverify' => true];

        $res = wp_remote_head($url, $args);
        if (is_wp_error($res)) {
            $res = wp_remote_get($url, $args);
            if (is_wp_error($res)) {
                return ['status' => null, 'transport_error' => true];
            }
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code === 405 || $code === 501 || $code === 0) {
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
