<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Http\SignedHttpClient;
use Throwable;

/**
 * Soft disconnect: sign POST /disconnect to the connector, then DELETE the
 * dashboard wp_defyn_sites row. The row is deleted regardless of connector
 * outcome — an offline/broken connector must not strand the operator.
 *
 * User-scoped: returns false (without touching anything) if the caller is
 * not the owner of $siteId. Returns true on a successful row delete.
 *
 * Failure tolerance is intentional. If the managed site is offline, the
 * connector plugin is deactivated, or the stored key material is corrupted,
 * we still want the dashboard row gone so the operator can re-add the site
 * with a fresh code. The connector's stale `dashboard_public_key` (if any)
 * will be overwritten on the next handshake.
 */
final class DisconnectService
{
    public function __construct(
        private readonly SignedHttpClient $httpClient = new SignedHttpClient(),
        private readonly ?SitesRepository $repo = null,
    ) {}

    public function disconnect(int $siteId, int $userId): bool
    {
        $repo = $this->repo ?? new SitesRepository();
        $site = $repo->findByIdForUser($siteId, $userId);
        if ($site === null) {
            // 404 envelope at the controller layer. No connector call, no delete.
            return false;
        }

        // Best-effort connector tear-down. Failures are NOT fatal — the row
        // gets deleted either way (decrypt failure, transport error, 4xx/5xx
        // all flow through unchanged to the deleteForUser call below).
        try {
            $privateKey = (new Vault(DEFYN_VAULT_KEY))->decrypt((string) $site->ourPrivateKey);
            $this->httpClient->signedPostJson(
                rtrim($site->url, '/') . '/wp-json/defyn-connector/v1/disconnect',
                [],
                $privateKey,
                '/defyn-connector/v1/disconnect',
            );
        } catch (Throwable $e) {
            // Swallow — soft disconnect proceeds regardless. Intentional silent
            // catch (no log/echo) to avoid polluting test output and to preserve
            // the "always delete the row" contract.
        }

        return $repo->deleteForUser($siteId, $userId);
    }
}
