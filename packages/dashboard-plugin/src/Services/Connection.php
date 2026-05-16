<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Models\Site;

/**
 * Runs the F5 connection handshake against one connector.
 *
 * Lives outside the AS job so tests can construct it with mocks and bypass
 * Action Scheduler entirely. The CompleteConnection AS handler is a thin
 * wrapper that builds one of these and calls ::complete().
 */
final class Connection
{
    public function __construct(
        private readonly SignedHttpClient $httpClient,
        private readonly SitesRepository  $repo,
        private readonly ActivityLogger   $logger,
        private readonly string           $dashboardPublicKey,  // base64 K_dash pub
    ) {}

    public function complete(int $siteId, string $code, string $siteUrl): void
    {
        $site = $this->repo->findById($siteId);
        if ($site === null) {
            // Site disappeared between schedule and execute (e.g. user deleted it).
            return;
        }

        $challenge = base64_encode(random_bytes(32));
        $endpoint  = rtrim($siteUrl, '/') . '/wp-json/defyn-connector/v1/connect';

        $response = $this->httpClient->postJson($endpoint, [
            'code'                 => $code,
            'dashboard_public_key' => $this->dashboardPublicKey,
            'callback_challenge'   => $challenge,
        ]);

        // Transport error (DNS, connection refused, timeout).
        if ($response['status'] === 0) {
            $this->fail($site, $response['error'], 'site.error');
            return;
        }

        // Connector returned a non-2xx envelope.
        if ($response['status'] >= 400) {
            $message = $response['body']['error']['message'] ?? "Connector returned HTTP {$response['status']}.";
            $this->fail($site, (string) $message, 'site.error');
            return;
        }

        // 2xx — verify the challenge signature.
        $body = $response['body'];
        $sitePubBase64 = (string) ($body['site_public_key'] ?? '');
        $sigBase64     = (string) ($body['challenge_signature'] ?? '');

        $sitePubRaw = base64_decode($sitePubBase64, true);
        $sigRaw     = base64_decode($sigBase64, true);

        if ($sitePubRaw === false || $sigRaw === false
            || strlen($sitePubRaw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || strlen($sigRaw) !== SODIUM_CRYPTO_SIGN_BYTES) {
            $this->fail($site, 'Challenge signature invalid', 'site.connection_rejected');
            return;
        }

        // F5 canonical-string-to-sign: just $challenge (NOT challenge + site_nonce).
        // The dashboard doesn't know the connector's site_nonce; verifying possession
        // of K_site against the challenge alone is sufficient for F5's threat model.
        // See plan Task 8 IMPORTANT note.
        if (!sodium_crypto_sign_verify_detached($sigRaw, $challenge, $sitePubRaw)) {
            $this->fail($site, 'Challenge signature invalid', 'site.connection_rejected');
            return;
        }

        // All good — flip to active and log it.
        $this->repo->markActive($siteId, $sitePubBase64);
        $this->logger->log($site->userId, $siteId, 'site.connected', ['url' => $siteUrl]);
    }

    private function fail(Site $site, string $message, string $eventType): void
    {
        $this->repo->markError($site->id, $message);
        $this->logger->log($site->userId, $site->id, $eventType, ['url' => $site->url, 'message' => $message]);
    }
}
