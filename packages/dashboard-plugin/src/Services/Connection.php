<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Jobs\SyncSite;
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

        // Already-active site → AS retry must NOT re-POST to the connector
        // (would hit connector.code_consumed and corrupt our own status to 'error').
        if ($site->status === 'active') {
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
            $this->fail($site, $this->capMessage($response['error']), 'site.error');
            return;
        }

        // Connector returned a non-2xx envelope.
        if ($response['status'] >= 400) {
            $message = $response['body']['error']['message'] ?? "Connector returned HTTP {$response['status']}.";
            $this->fail($site, $this->capMessage((string) $message), 'site.error');
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

        // F7 UX: schedule a one-shot sync_site immediately so the freshly-connected
        // site shows runtime info (wp_version, php_version, ssl_status) within seconds
        // instead of waiting up to 30 min for the next sync_all fan-out.
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time(), SyncSite::HOOK, [$siteId], 'defyn');
        }

        $this->logger->log($site->userId, $siteId, 'site.connected', ['url' => $siteUrl]);
    }

    private function fail(Site $site, string $message, string $eventType): void
    {
        $this->repo->markError($site->id, $message);
        $this->logger->log($site->userId, $site->id, $eventType, ['url' => $site->url, 'message' => $message]);
    }

    /**
     * Cap untrusted error messages at 500 chars before surfacing them via Site::lastError.
     * Connector-supplied strings (envelope error.message OR Throwable from transport)
     * are not trusted — a compromised/misconfigured peer could otherwise stuff arbitrary
     * content into the dashboard UI or bloat the database.
     */
    private function capMessage(string $message): string
    {
        return mb_substr($message, 0, 500);
    }
}
