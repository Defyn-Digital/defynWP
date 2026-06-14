<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Services;

use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;

final class VulnFeedService
{
    private const FEED_URL = 'https://www.wordfence.com/api/intelligence/v3/vulnerabilities/production';
    private const STALE_SECONDS = 86400;
    private const OPTION = 'defyn_vuln_feed_synced_at';

    /** @var callable(): string */
    private $keyProvider;
    /** @var callable(string,string): ?string returns a path to the downloaded feed file, or null on failure */
    private $downloader;

    public function __construct(
        ?callable $keyProvider = null,
        private readonly ?VulnerabilitiesRepository $repo = null,
        ?callable $downloader = null,
    ) {
        $this->keyProvider = $keyProvider ?? static function (): string {
            return defined('DEFYN_WORDFENCE_API_KEY') ? (string) constant('DEFYN_WORDFENCE_API_KEY') : '';
        };
        $this->downloader = $downloader ?? fn (string $url, string $key): ?string => $this->download($url, $key);
    }

    public function refreshIfStale(): void
    {
        $key = ($this->keyProvider)();
        if ($key === '') {
            error_log('[defyn] vuln feed: DEFYN_WORDFENCE_API_KEY not set; skipping refresh.');
            return;
        }

        $syncedAt = get_option(self::OPTION);
        if (is_string($syncedAt) && $syncedAt !== '') {
            $age = time() - (int) strtotime($syncedAt . ' UTC');
            if ($age >= 0 && $age < self::STALE_SECONDS) {
                return; // still fresh
            }
        }

        $path = ($this->downloader)(self::FEED_URL, $key);
        if ($path === null) {
            return; // transport/non-2xx (already logged in download()); last-good rows intact
        }

        $repo = $this->repo ?? new VulnerabilitiesRepository();
        $now  = gmdate('Y-m-d H:i:s');
        try {
            // Stream-parse the ~117MB feed lazily: top-level object keyed by UUID -> record.
            // ExtJsonDecoder(true) decodes nested structures into associative arrays for mapRecord().
            foreach (Items::fromFile($path, ['decoder' => new ExtJsonDecoder(true)]) as $sourceId => $record) {
                if (!is_array($record)) {
                    continue;
                }
                $rows = $this->mapRecord((string) $sourceId, $record, $now);
                if ($rows !== []) {
                    $repo->upsertForSource((string) $sourceId, $rows);
                }
            }
            update_option(self::OPTION, $now);
        } catch (\Throwable $e) {
            error_log('[defyn] vuln feed: parse error: ' . $e->getMessage()); // never logs the key
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /** Stream the feed body to a temp file (never holds 117MB in memory). Returns the path, or null on failure. */
    private function download(string $url, string $key): ?string
    {
        $tmp = wp_tempnam('defyn-vuln-feed');
        if (!$tmp) {
            error_log('[defyn] vuln feed: could not create temp file.');
            return null;
        }
        $response = wp_remote_get($url, [
            'timeout'  => 120,
            'stream'   => true,
            'filename' => $tmp,
            'headers'  => ['Authorization' => 'Bearer ' . $key],
        ]);
        if (is_wp_error($response)) {
            @unlink($tmp);
            error_log('[defyn] vuln feed: transport error: ' . $response->get_error_message());
            return null;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            @unlink($tmp);
            error_log('[defyn] vuln feed: non-2xx response: ' . $code); // never logs the key
            return null;
        }
        return $tmp;
    }

    /**
     * Map one Wordfence record into 0+ normalized range rows (one per affected-version range per software entry).
     * Defensive: skips entries missing type/slug; tolerates absent fields.
     *
     * @param array<string,mixed> $record
     * @return list<array<string,mixed>>
     */
    private function mapRecord(string $sourceId, array $record, string $now): array
    {
        $title    = isset($record['title']) ? (string) $record['title'] : null;
        $cve      = isset($record['cve']) && $record['cve'] !== '' ? (string) $record['cve'] : null;
        $cvss     = is_array($record['cvss'] ?? null) ? $record['cvss'] : [];
        $score    = isset($cvss['score']) ? (float) $cvss['score'] : null;
        $severity = isset($cvss['rating']) && $cvss['rating'] !== '' ? strtolower((string) $cvss['rating']) : 'unknown';

        $software = is_array($record['software'] ?? null) ? $record['software'] : [];
        $rows = [];
        foreach ($software as $sw) {
            if (!is_array($sw) || empty($sw['type']) || empty($sw['slug'])) {
                continue;
            }
            $type = (string) $sw['type'];
            if ($type === 'plugin' || $type === 'theme' || $type === 'core') {
                // core software slug in the feed may be 'wordpress'/'core' — normalize to 'wordpress'.
                $slug    = $type === 'core' ? 'wordpress' : (string) $sw['slug'];
                $fixedIn = isset($sw['patched_versions'][0]) ? (string) $sw['patched_versions'][0] : null;
                $ranges  = is_array($sw['affected_versions'] ?? null) ? $sw['affected_versions'] : [];
                foreach ($ranges as $range) {
                    if (!is_array($range)) {
                        continue;
                    }
                    $from = isset($range['from_version']) && $range['from_version'] !== '*' && $range['from_version'] !== ''
                        ? (string) $range['from_version'] : null;
                    $to = isset($range['to_version']) && $range['to_version'] !== '*' && $range['to_version'] !== ''
                        ? (string) $range['to_version'] : null;
                    $rows[] = [
                        'type'           => $type,
                        'slug'           => $slug,
                        'title'          => $title,
                        'severity'       => $severity,
                        'cvss_score'     => $score,
                        'cve'            => $cve,
                        'from_version'   => $from,
                        'from_inclusive' => (bool) ($range['from_inclusive'] ?? true),
                        'to_version'     => $to,
                        'to_inclusive'   => (bool) ($range['to_inclusive'] ?? false),
                        'fixed_in'       => $fixedIn,
                        'updated_at'     => $now,
                    ];
                }
            }
        }
        return $rows;
    }
}
