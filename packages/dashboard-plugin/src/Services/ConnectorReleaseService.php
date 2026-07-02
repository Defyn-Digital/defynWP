<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

/**
 * Resolves the latest connector release from the project's GitHub Releases.
 *
 * CI (`.github/workflows/release-connector.yml`) publishes, on every
 * `connector-v*` tag, a GitHub Release with the asset
 * `defyn-connector-<version>.zip`. This service finds the highest such
 * release, resolves the asset download URL, downloads it once to compute the
 * SHA-256 (GitHub's API does not expose asset hashes), and caches the manifest
 * {version, package_url, sha256} in a transient.
 *
 * The SHA-256 is what makes the connector's /self-update safe: the dashboard
 * pins it inside the Ed25519-signed instruction, and the connector refuses to
 * install a package whose bytes don't match.
 */
final class ConnectorReleaseService
{
    private const CACHE_KEY = 'defyn_connector_latest_release';
    private const CACHE_TTL = 900; // 15 min
    private const TAG_PREFIX = 'connector-v';

    public function repoSlug(): string
    {
        // Overridable for forks / self-hosted mirrors.
        return (string) apply_filters('defyn_connector_repo', 'Defyn-Digital/defynWP');
    }

    /**
     * @return array{version: string, package_url: string, sha256: string}|null
     */
    public function latest(bool $forceRefresh = false): ?array
    {
        if (!$forceRefresh) {
            $cached = get_transient(self::CACHE_KEY);
            if (is_array($cached) && isset($cached['version'], $cached['package_url'], $cached['sha256'])) {
                return $cached;
            }
        }

        $release = $this->findLatestConnectorRelease();
        if ($release === null) {
            return null;
        }

        // Prefer the SHA-256 GitHub already publishes on the asset (asset.digest);
        // only download the zip to hash it if the digest is unavailable. This
        // removes the fragile server-side asset download from the happy path.
        $sha = $release['sha256'] ?? null;
        if ($sha === null) {
            $sha = $this->hashPackage($release['package_url']);
        }
        if ($sha === null) {
            return null;
        }

        $manifest = [
            'version'     => $release['version'],
            'package_url' => $release['package_url'],
            'sha256'      => $sha,
        ];
        set_transient(self::CACHE_KEY, $manifest, self::CACHE_TTL);
        return $manifest;
    }

    /**
     * @return array{version: string, package_url: string, sha256: ?string}|null
     */
    private function findLatestConnectorRelease(): ?array
    {
        $url = sprintf('https://api.github.com/repos/%s/releases?per_page=30', $this->repoSlug());
        $response = wp_remote_get($url, [
            'timeout' => 15,
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'DefynWP-Dashboard',
            ],
        ]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        $releases = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($releases)) {
            return null;
        }

        $best = null; // ['version' => .., 'package_url' => ..]
        foreach ($releases as $rel) {
            if (!is_array($rel) || !empty($rel['draft'])) {
                continue;
            }
            $tag = (string) ($rel['tag_name'] ?? '');
            if (strncmp($tag, self::TAG_PREFIX, strlen(self::TAG_PREFIX)) !== 0) {
                continue;
            }
            $version = substr($tag, strlen(self::TAG_PREFIX));
            if (!preg_match('/^\d+\.\d+\.\d+/', $version)) {
                continue;
            }
            $asset = $this->findZipAsset($rel, $version);
            if ($asset === null) {
                continue;
            }
            if ($best === null || version_compare($version, $best['version'], '>')) {
                $best = ['version' => $version, 'package_url' => $asset['url'], 'sha256' => $asset['sha256']];
            }
        }
        return $best;
    }

    /**
     * @param array<string, mixed> $release
     * @return array{url: string, sha256: ?string}|null
     */
    private function findZipAsset(array $release, string $version): ?array
    {
        $assets = $release['assets'] ?? [];
        if (!is_array($assets)) {
            return null;
        }
        $expected = sprintf('defyn-connector-%s.zip', $version);
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $name = (string) ($asset['name'] ?? '');
            $dl   = (string) ($asset['browser_download_url'] ?? '');
            if ($name === $expected && str_starts_with($dl, 'https://')) {
                // GitHub publishes the asset SHA-256 as e.g. "sha256:abcd...".
                $sha    = null;
                $digest = (string) ($asset['digest'] ?? '');
                if (stripos($digest, 'sha256:') === 0) {
                    $candidate = strtolower(substr($digest, 7));
                    if (preg_match('/^[a-f0-9]{64}$/', $candidate)) {
                        $sha = $candidate;
                    }
                }
                return ['url' => $dl, 'sha256' => $sha];
            }
        }
        return null;
    }

    private function hashPackage(string $packageUrl): ?string
    {
        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $tmp = download_url($packageUrl, 120);
        if (is_wp_error($tmp)) {
            return null;
        }
        $hash = hash_file('sha256', (string) $tmp);
        @unlink((string) $tmp);
        return is_string($hash) ? strtolower($hash) : null;
    }
}
