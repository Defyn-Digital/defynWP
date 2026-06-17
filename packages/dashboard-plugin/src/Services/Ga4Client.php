<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

use Firebase\JWT\JWT;

/**
 * P6.2 — GA4 Data API client. Service-account JWT-bearer auth (RS256 via
 * firebase/php-jwt — already a prod dep), then one batchRunReports call for the
 * three report requests. Best-effort: returns null on any failure (no SA env,
 * token error, non-200, malformed body) so the weekly fan-out and the report
 * never break. NOT final — tests subclass / inject the HTTP + SA-JSON seams.
 */
class Ga4Client
{
    private const SCOPE     = 'https://www.googleapis.com/auth/analytics.readonly';
    private const DATA_API  = 'https://analyticsdata.googleapis.com/v1beta';

    /** @var callable(string,array):mixed */
    private $http;
    private ?string $serviceAccountJson;

    public function __construct(?callable $http = null, ?string $serviceAccountJson = null)
    {
        $this->http = $http ?? static fn (string $url, array $args) => wp_remote_post($url, $args);
        $this->serviceAccountJson = $serviceAccountJson
            ?? (defined('DEFYN_GA4_SERVICE_ACCOUNT_JSON') && DEFYN_GA4_SERVICE_ACCOUNT_JSON !== ''
                ? (string) DEFYN_GA4_SERVICE_ACCOUNT_JSON : null);
    }

    /**
     * @return array{sessions:int,users:int,pageviews:int,avg_engagement:float,top_pages:list<array{path:string,title:string,views:int}>,channels:list<array{channel:string,sessions:int}>}|null
     */
    public function fetchReport(string $propertyId, string $fromDate, string $toDate): ?array
    {
        $token = $this->accessToken();
        if ($token === null) {
            return null;
        }
        $endpoint = self::DATA_API . '/properties/' . rawurlencode($propertyId) . ':batchRunReports';
        $payload  = [
            'requests' => [
                ['dateRanges' => [['startDate' => $fromDate, 'endDate' => $toDate]],
                 'metrics' => [['name' => 'sessions'], ['name' => 'totalUsers'], ['name' => 'screenPageViews'], ['name' => 'averageSessionDuration']]],
                ['dateRanges' => [['startDate' => $fromDate, 'endDate' => $toDate]],
                 'dimensions' => [['name' => 'pagePath'], ['name' => 'pageTitle']],
                 'metrics' => [['name' => 'screenPageViews']],
                 'orderBys' => [['metric' => ['metricName' => 'screenPageViews'], 'desc' => true]],
                 'limit' => 10],
                ['dateRanges' => [['startDate' => $fromDate, 'endDate' => $toDate]],
                 'dimensions' => [['name' => 'sessionDefaultChannelGroup']],
                 'metrics' => [['name' => 'sessions']],
                 'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
                 'limit' => 10],
            ],
        ];
        $res = ($this->http)($endpoint, [
            'timeout'     => 30,
            'redirection' => 0,
            'headers'     => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'body'        => json_encode($payload),
        ]);
        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
            return null;
        }
        $data = json_decode((string) wp_remote_retrieve_body($res), true);
        $reports = $data['reports'] ?? null;
        if (!is_array($reports) || count($reports) < 3) {
            return null;
        }

        $tot = $reports[0]['rows'][0]['metricValues'] ?? [];
        $topPages = [];
        foreach ($reports[1]['rows'] ?? [] as $r) {
            $topPages[] = [
                'path'  => (string) ($r['dimensionValues'][0]['value'] ?? ''),
                'title' => (string) ($r['dimensionValues'][1]['value'] ?? ''),
                'views' => (int) ($r['metricValues'][0]['value'] ?? 0),
            ];
        }
        $channels = [];
        foreach ($reports[2]['rows'] ?? [] as $r) {
            $channels[] = [
                'channel'  => (string) ($r['dimensionValues'][0]['value'] ?? ''),
                'sessions' => (int) ($r['metricValues'][0]['value'] ?? 0),
            ];
        }

        return [
            'sessions'       => (int) ($tot[0]['value'] ?? 0),
            'users'          => (int) ($tot[1]['value'] ?? 0),
            'pageviews'      => (int) ($tot[2]['value'] ?? 0),
            'avg_engagement' => (float) ($tot[3]['value'] ?? 0),
            'top_pages'      => $topPages,
            'channels'       => $channels,
        ];
    }

    private function accessToken(): ?string
    {
        $sa = $this->serviceAccount();
        if ($sa === null) {
            return null;
        }
        $now = time();
        try {
            $assertion = JWT::encode([
                'iss'   => $sa['client_email'],
                'scope' => self::SCOPE,
                'aud'   => $sa['token_uri'],
                'iat'   => $now,
                'exp'   => $now + 3600,
            ], $sa['private_key'], 'RS256');
        } catch (\Throwable $e) {
            return null;
        }
        $res = ($this->http)($sa['token_uri'], [
            'timeout'     => 30,
            'redirection' => 0,
            'body'        => ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion],
        ]);
        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
            return null;
        }
        $body = json_decode((string) wp_remote_retrieve_body($res), true);
        $token = $body['access_token'] ?? null;
        return is_string($token) && $token !== '' ? $token : null;
    }

    /** @return array{client_email:string,private_key:string,token_uri:string}|null */
    private function serviceAccount(): ?array
    {
        if ($this->serviceAccountJson === null) {
            return null;
        }
        $sa = json_decode($this->serviceAccountJson, true);
        if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) {
            return null;
        }
        return [
            'client_email' => (string) $sa['client_email'],
            'private_key'  => (string) $sa['private_key'],
            'token_uri'    => isset($sa['token_uri']) && $sa['token_uri'] !== '' ? (string) $sa['token_uri'] : 'https://oauth2.googleapis.com/token',
        ];
    }
}
