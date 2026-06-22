<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Http\SignedHttpClient;
use Defyn\Dashboard\Models\Site;
use Throwable;

/**
 * P7.1 — per-site broken-link scan orchestrator.
 *
 * POSTs to the connector's POST /defyn-connector/v1/links/scan, classifies each
 * returned finding via LinkClassifier, persists via BrokenLinksRepository,
 * prunes fixed links, advances last_link_scan_at, and logs ONE activity event.
 *
 * Best-effort (guardrail #2): a missing site, a connector error, or an unexpected
 * response shape is a quiet no-op. last_link_scan_at is ALWAYS advanced (even on
 * failure) so the operator can tell "scanned, failed" apart from "never scanned".
 * This service NEVER throws.
 *
 * The $caller seam lets tests inject a fake connector response without HTTP.
 */
final class BrokenLinkScanService
{
    /** @var (callable(Site):array)|null */
    private $caller;

    public function __construct(
        private readonly ?BrokenLinksRepository $repo = null,
        private readonly ?SitesRepository $sites = null,
        ?callable $caller = null,
    ) {
        $this->caller = $caller;
    }

    /** Best-effort. Never throws. */
    public function scan(int $siteId): void
    {
        try {
            $this->doScan($siteId);
        } catch (Throwable) {
            // Swallow any unexpected exception — best-effort guardrail.
        }
    }

    private function doScan(int $siteId): void
    {
        $sites = $this->sites ?? new SitesRepository();
        $site  = $sites->findById($siteId);
        if ($site === null) {
            return;
        }

        $now = gmdate('Y-m-d H:i:s');

        $resp = ($this->caller ?? fn (Site $s): array => $this->callConnector($s))($site);

        // Always advance "last checked", even on failure.
        $sites->markLinkScannedAt($siteId, $now);

        $err    = (string) ($resp['error'] ?? '');
        $status = (int) ($resp['status'] ?? 0);
        if ($err !== '' || $status !== 200 || !is_array($resp['body']['links'] ?? null)) {
            (new ActivityLogger())->log($site->userId, $siteId, 'links.scan_failed', [
                'status' => $status,
                'error'  => substr($err !== '' ? $err : 'unexpected response shape', 0, 500),
            ]);
            return;
        }

        $repo = $this->repo ?? new BrokenLinksRepository();
        foreach ($resp['body']['links'] as $f) {
            $status = isset($f['status']) ? (int) $f['status'] : null;
            $cls    = LinkClassifier::classify($status, (bool) ($f['transport_error'] ?? false));
            $repo->upsertForSite($siteId, [
                'url'          => (string) ($f['url'] ?? ''),
                'source_url'   => (string) ($f['source_url'] ?? ''),
                'source_title' => isset($f['source_title']) ? (string) $f['source_title'] : null,
                'anchor_text'  => isset($f['anchor_text']) ? (string) $f['anchor_text'] : null,
                'status_code'  => $status,
                'severity'     => $cls['severity'],
                'reason'       => $cls['reason'],
                'link_type'    => (string) ($f['link_type'] ?? 'external'),
            ], $now);
        }
        $repo->pruneStaleForSite($siteId, $now);
        $counts = $repo->countsForSite($siteId);
        (new ActivityLogger())->log($site->userId, $siteId, 'links.scan_completed', [
            'broken'    => $counts['broken'],
            'warning'   => $counts['warning'],
            'truncated' => (bool) ($resp['body']['truncated'] ?? false),
        ]);
    }

    /**
     * Build and fire the signed POST to /defyn-connector/v1/links/scan.
     *
     * Mirrors RefreshSiteCore exactly:
     *   1. Bail if ourPrivateKey is missing/empty.
     *   2. Decrypt via Vault(DEFYN_VAULT_KEY)->decrypt($site->ourPrivateKey).
     *   3. Build $url  = rtrim($site->url, '/') . '/wp-json/defyn-connector/v1/links/scan'.
     *   4. Build $canonicalPath = '/defyn-connector/v1/links/scan'.
     *   5. Call SignedHttpClient::signedPostJson($url, [], $privateKey, $canonicalPath, 120).
     *
     * Returns ['status'=>int,'body'=>array,'error'=>string] — never throws.
     *
     * @return array{status: int, body: array<string, mixed>, error: string}
     */
    private function callConnector(Site $site): array
    {
        if ($site->ourPrivateKey === null || $site->ourPrivateKey === '') {
            return ['status' => 0, 'body' => [], 'error' => 'Site is missing its encrypted private key.'];
        }

        $vault = new Vault(DEFYN_VAULT_KEY);
        try {
            $privateKey = $vault->decrypt($site->ourPrivateKey);
        } catch (Throwable $e) {
            return ['status' => 0, 'body' => [], 'error' => 'Failed to decrypt site keypair: ' . $e->getMessage()];
        }

        $url           = rtrim($site->url, '/') . '/wp-json/defyn-connector/v1/links/scan';
        $canonicalPath = '/defyn-connector/v1/links/scan';

        return (new SignedHttpClient())->signedPostJson($url, [], $privateKey, $canonicalPath, 120);
    }
}
