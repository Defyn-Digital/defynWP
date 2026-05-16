<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Http;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Outbound HTTPS client for talking to connector plugins.
 *
 * F5: plain JSON POST with no signing. The interface is fixed now so the
 * callers (Connection service, Action Scheduler jobs in later phases)
 * never have to change when F6 adds X-Defyn-Timestamp / X-Defyn-Nonce /
 * X-Defyn-Signature headers per spec § 5.2.
 *
 * Transport errors (DNS failure, connection refused, TLS handshake fail,
 * timeout) return ['status' => 0, 'body' => [], 'error' => '<msg>'] rather
 * than throwing — the caller (handshake AS job) needs to write 'error'
 * status into wp_defyn_sites either way, so flatter is simpler.
 */
final class SignedHttpClient
{
    public function __construct(
        private readonly ?HttpClientInterface $httpClient = null,
    ) {}

    /**
     * @param array<string, mixed> $body
     * @return array{status: int, body: array<string, mixed>, error: string}
     */
    public function postJson(string $url, array $body): array
    {
        $client = $this->httpClient ?? HttpClient::create([
            'timeout'      => 10,   // socket idle timeout
            'max_duration' => 30,   // overall request budget
        ]);

        try {
            $response = $client->request('POST', $url, [
                'json' => $body,
            ]);
            $status  = $response->getStatusCode();
            $raw     = $response->getContent(throw: false);
            $decoded = $raw === '' ? [] : (json_decode($raw, true) ?? []);
            return ['status' => $status, 'body' => $decoded, 'error' => ''];
        } catch (Throwable $e) {
            return ['status' => 0, 'body' => [], 'error' => $e->getMessage()];
        }
    }
}
