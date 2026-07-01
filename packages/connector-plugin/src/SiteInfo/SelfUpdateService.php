<?php

declare(strict_types=1);

namespace Defyn\Connector\SiteInfo;

/**
 * Upgrades the connector plugin itself to a dashboard-authorised release.
 *
 * Trust model: the caller (SelfUpdateController) only runs after the request's
 * Ed25519 signature is verified, so the {target_version, package_url,
 * package_sha256} instruction is known to come from the paired dashboard. This
 * service additionally pins the package by SHA-256 — it downloads package_url
 * over HTTPS and refuses to install unless the bytes hash to package_sha256.
 * So even a compromised package host cannot get an unexpected zip installed.
 *
 * Install uses Plugin_Upgrader with overwrite_package=true against the
 * connector's OWN plugin folder. The current request finishes on the already
 * loaded (old) code; the next request loads the new code. Pairing keys live in
 * wp_options / DB and are untouched by a file overwrite.
 *
 * Collaborators are injected so tests can exercise the version-gate, hash
 * mismatch and success paths without touching disk or the network.
 */
final class SelfUpdateService
{
    /** @var callable(string $url): (string|\WP_Error) — download to a temp path */
    private $downloader;

    /** @var callable(string $path): string — sha256 of a file */
    private $hasher;

    /** @var callable(string $packagePath): (true|\WP_Error) — overwrite-install the package */
    private $installer;

    /** @var callable(): string — read the connector version currently on disk */
    private $versionReader;

    public function __construct(
        ?callable $downloader = null,
        ?callable $hasher = null,
        ?callable $installer = null,
        ?callable $versionReader = null,
    ) {
        $this->downloader    = $downloader ?? self::defaultDownloader();
        $this->hasher        = $hasher ?? static fn (string $p): string => (string) hash_file('sha256', $p);
        $this->installer     = $installer ?? self::defaultInstaller();
        $this->versionReader = $versionReader ?? static fn (): string => defined('DEFYN_CONNECTOR_VERSION') ? (string) DEFYN_CONNECTOR_VERSION : '';
    }

    /**
     * @return array{success: true, previous_version: string, new_version: string, server_time: int, updated: bool}
     */
    public function update(string $targetVersion, string $packageUrl, string $packageSha256): array
    {
        $current = ($this->versionReader)();

        // Already at or past the target → no-op (idempotent; a re-fired job or a
        // race with another dashboard is harmless).
        if ($current !== '' && $targetVersion !== '' && version_compare($current, $targetVersion, '>=')) {
            return [
                'success'          => true,
                'previous_version' => $current,
                'new_version'      => $current,
                'server_time'      => time(),
                'updated'          => false,
            ];
        }

        if (!preg_match('#^https://#i', $packageUrl)) {
            throw new UpgradeFailedException(esc_html('Package URL must be https.'));
        }
        $expectedHash = strtolower(trim($packageSha256));
        if (!preg_match('/^[a-f0-9]{64}$/', $expectedHash)) {
            throw new UpgradeFailedException(esc_html('Missing or malformed package_sha256.'));
        }

        $tmp = ($this->downloader)($packageUrl);
        if (is_wp_error($tmp)) {
            throw new UpgradeFailedException(esc_html('Download failed: ' . (string) $tmp->get_error_message()));
        }
        $tmp = (string) $tmp;

        try {
            $actualHash = strtolower((string) ($this->hasher)($tmp));
            if (!hash_equals($expectedHash, $actualHash)) {
                throw new UpgradeFailedException(esc_html(sprintf(
                    'Package SHA-256 mismatch: expected %s, got %s. Refusing to install.',
                    $expectedHash,
                    $actualHash
                )));
            }

            $result = ($this->installer)($tmp);
            if (is_wp_error($result)) {
                throw new UpgradeFailedException(esc_html('Install failed: ' . (string) $result->get_error_message()));
            }
            if ($result !== true) {
                throw new UpgradeFailedException(esc_html('Installer returned an unexpected result.'));
            }
        } finally {
            if (is_string($tmp) && $tmp !== '' && file_exists($tmp)) {
                @unlink($tmp);
            }
        }

        $newVersion = ($this->versionReader)();

        // The overwrite reported success but the version header on disk did not
        // advance — files were not actually replaced. Fail loudly rather than
        // report a false success the dashboard would trust.
        if ($newVersion === $current || ($newVersion !== '' && version_compare($newVersion, $current, '<='))) {
            throw new UpgradeFailedException(esc_html(sprintf(
                'Install reported success but connector version did not change (stayed %s).',
                $current
            )));
        }

        return [
            'success'          => true,
            'previous_version' => $current,
            'new_version'      => $newVersion,
            'server_time'      => time(),
            'updated'          => true,
        ];
    }

    /** @return callable(string): (string|\WP_Error) */
    private static function defaultDownloader(): callable
    {
        return static function (string $url) {
            if (!function_exists('download_url')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            // 300s: match the connector's long-upgrade budget for big packages.
            return download_url($url, 300);
        };
    }

    /** @return callable(string): (true|\WP_Error) */
    private static function defaultInstaller(): callable
    {
        return static function (string $packagePath) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/misc.php';
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

            $skin     = new \Automatic_Upgrader_Skin();
            $upgrader = new \Plugin_Upgrader($skin);

            // overwrite_package=true replaces the existing (our own) plugin
            // folder from a LOCAL zip path — the same code path wp-admin uses
            // for "upload a plugin that already exists → replace".
            $result = $upgrader->install($packagePath, ['overwrite_package' => true]);

            if (is_wp_error($result)) {
                return $result;
            }
            if ($result === false || $result === null) {
                $errors = $skin->get_errors();
                if (is_wp_error($errors) && $errors->has_errors()) {
                    return $errors;
                }
                return new \WP_Error('defyn_self_update_install_failed', 'Plugin_Upgrader::install returned false.');
            }
            return true;
        };
    }
}
