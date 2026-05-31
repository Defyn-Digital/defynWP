<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Http;

use Defyn\Dashboard\Crypto\Signer;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Outbound HTTPS client for talking to connector plugins.
 *
 * Two flavours of request live here:
 *   - postJson()  — plain JSON POST, no signing. Used ONLY by the F5
 *                   handshake (callback_challenge), where the dashboard does
 *                   not yet hold the site's keypair so transport-layer
 *                   signing is impossible — that step signs at the
 *                   application layer via the challenge payload itself.
 *   - signedGet() / signedPostJson() — F6 spec § 5.2 signed transport.
 *                   Caller supplies the per-site Ed25519 private key
 *                   (base64) and the canonical path; we attach the three
 *                   X-Defyn-Timestamp / X-Defyn-Nonce / X-Defyn-Signature
 *                   headers via {@see Signer::signRequest()}.
 *
 * Transport errors (DNS failure, connection refused, TLS handshake fail,
 * timeout) return ['status' => 0, 'body' => [], 'error' => '<msg>'] rather
 * than throwing — callers need to write 'error' status into wp_defyn_sites
 * either way, so flatter is simpler.
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

    /**
     * Signed GET. Caller supplies the per-site Ed25519 private key (base64) and
     * the canonical path (the part after host, e.g. /defyn-connector/v1/status).
     *
     * @return array{status: int, body: array<string, mixed>, error: string}
     */
    public function signedGet(string $url, string $privateKeyBase64, string $canonicalPath): array
    {
        $signer  = new Signer($privateKeyBase64);
        $headers = $signer->signRequest('GET', $canonicalPath, '');
        return $this->sendSigned('GET', $url, null, $headers);
    }

    /**
     * Signed POST with a JSON body. Body is serialized once and signed over the
     * exact bytes that go on the wire (otherwise the connector recomputes a
     * different hash and rejects).
     *
     * @param array<string, mixed> $body
     * @return array{status: int, body: array<string, mixed>, error: string}
     */
    public function signedPostJson(string $url, array $body, string $privateKeyBase64, string $canonicalPath): array
    {
        $serialized = json_encode($body, JSON_UNESCAPED_SLASHES);
        if ($serialized === false) {
            return ['status' => 0, 'body' => [], 'error' => 'Failed to serialize body'];
        }

        $signer  = new Signer($privateKeyBase64);
        $headers = array_merge(
            ['Content-Type' => 'application/json'],
            $signer->signRequest('POST', $canonicalPath, $serialized)
        );
        return $this->sendSigned('POST', $url, $serialized, $headers);
    }

    /**
     * @param string|null $body raw body bytes (already-serialized JSON for POSTs)
     * @param array<string, string> $headers
     * @return array{status: int, body: array<string, mixed>, error: string}
     */
    private function sendSigned(string $method, string $url, ?string $body, array $headers): array
    {
        $client = $this->httpClient ?? HttpClient::create([
            'timeout'      => 10,
            'max_duration' => 30,
        ]);

        $options = ['headers' => $headers];
        if ($body !== null) {
            $options['body'] = $body;
        }

        try {
            $response = $client->request($method, $url, $options);
            $status   = $response->getStatusCode();
            $raw      = $response->getContent(throw: false);
            $decoded  = $raw === '' ? [] : (json_decode($raw, true) ?? []);
            return ['status' => $status, 'body' => $decoded, 'error' => ''];
        } catch (Throwable $e) {
            return ['status' => 0, 'body' => [], 'error' => $e->getMessage()];
        }
    }
}
