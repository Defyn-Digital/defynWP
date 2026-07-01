<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Jobs;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Services\WpeAuthCookies;

/**
 * Action Scheduler handler for `defyn_update_site_connector($siteId, $targetVersion, $packageUrl, $packageSha256)`.
 *
 * Calls the connector's signed POST /self-update with the dashboard-resolved
 * release manifest. Reuses the exact mechanism proven to pass WP Engine's gate
 * for plugin updates: Ed25519-signed request + wpe-auth cookies on WPE sites.
 * The connector verifies the package SHA-256 before installing.
 *
 * See 2026-07-01-connector-self-update-design.md.
 */
final class UpdateSiteConnector
{
    public const HOOK = 'defyn_update_site_connector';

    /** Download + install of the connector zip; generous like core updates. */
    public const TIMEOUT_SECONDS = 300;

    public function __construct(
        private readonly SitesRepository $sites = new SitesRepository(),
        private readonly SignedHttpClient $http = new SignedHttpClient(),
        private readonly ActivityLogger $log = new ActivityLogger(),
        private readonly ?Vault $vault = null,
    ) {
    }

    public function handle(int $siteId, string $targetVersion, string $packageUrl, string $packageSha256): void
    {
        $site = $this->sites->findById($siteId);
        if ($site === null || $site->ourPrivateKey === null || $site->ourPrivateKey === '') {
            return;
        }

        $this->log->log(null, $siteId, 'connector_update.started', [
            'target_version' => $targetVersion,
        ]);

        $vault      = $this->vault ?? new Vault(DEFYN_VAULT_KEY);
        $privateKey = $vault->decrypt((string) $site->ourPrivateKey);

        $url           = rtrim($site->url, '/') . '/wp-json/defyn-connector/v1/self-update';
        $canonicalPath = '/defyn-connector/v1/self-update';

        $cookies = (new WpeAuthCookies($this->http))->fetch($site->url, $privateKey);

        $response = $this->http->signedPostJson(
            $url,
            [
                'target_version' => $targetVersion,
                'package_url'    => $packageUrl,
                'package_sha256' => $packageSha256,
            ],
            $privateKey,
            $canonicalPath,
            timeoutSeconds: self::TIMEOUT_SECONDS,
            cookies: $cookies,
        );

        if ($response['status'] === 200 && !empty($response['body']['success'])) {
            $newVersion = (string) ($response['body']['new_version'] ?? $targetVersion);
            $this->sites->updateConnectorVersion($siteId, $newVersion);
            $this->log->log(null, $siteId, 'connector_update.succeeded', [
                'previous_version' => (string) ($response['body']['previous_version'] ?? ''),
                'new_version'      => $newVersion,
                'updated'          => !empty($response['body']['updated']),
            ]);
            return;
        }

        $errorMessage = $response['body']['error']['message']
            ?? ($response['error'] !== '' ? $response['error'] : sprintf('Connector returned HTTP %d.', $response['status']));

        $this->log->log(null, $siteId, 'connector_update.failed', [
            'target_version' => $targetVersion,
            'error_message'  => $errorMessage,
        ]);
    }
}
