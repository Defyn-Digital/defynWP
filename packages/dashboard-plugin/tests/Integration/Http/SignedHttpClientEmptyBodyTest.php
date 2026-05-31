<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Http;

use Defyn\Dashboard\Crypto\InMemoryNonceStore;
use Defyn\Dashboard\Crypto\Signer;
use Defyn\Dashboard\Crypto\VerificationResult;
use Defyn\Dashboard\Http\SignedHttpClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * F10 Task 1 — empty-body signed POST byte-parity regression test.
 *
 * Background (F8/F9 carry-forward):
 *   The dashboard's signedPostJson( $url, [], ... ) flow used to json_encode([])
 *   → "[]" (2 bytes) and sign over those 2 bytes. But the canonical
 *   "empty body" representation HTTP and WP REST agree on is "" (zero bytes):
 *   - HTTP servers may treat a 2-byte "[]" with Content-Type: application/json
 *     inconsistently across reverse proxies / mod_security / FastCGI buffering.
 *   - WP REST's body params parsing path can re-encode or drop the body before
 *     get_body() is called by downstream middleware.
 *   - The connector's existing DisconnectTest (Connector\Tests\Integration\Rest)
 *     signs over "" and verifies fine via rest_do_request — proving the
 *     connector-side contract is "" for an empty POST.
 *
 *   Result: in the F8 soft-disconnect smoke the dashboard signed "[]" but the
 *   connector verified against "" → INVALID_SIGNATURE → 401 → connector state
 *   never flipped to 'unconfigured' (the failure was hidden by DisconnectService's
 *   intentional silent catch).
 *
 * The fix:
 *   signedPostJson with an empty array now signs over "" and sends NO body
 *   (no 'body' option to Symfony → no entity body on the wire). This makes the
 *   dashboard-side signed bytes byte-for-byte identical to what the connector's
 *   WP_REST_Request::get_body() will return for the same request.
 *
 * The contract this test pins:
 *   - For signedPostJson(url, [], ...):
 *       * the wire body bytes MUST equal ""  (universal HTTP "no body")
 *       * the SHA-256 in the canonical signed string MUST be sha256("")
 *
 * Both plugins ship byte-for-byte identical canonical()/verifyRequest()
 * implementations (spec § 5.2), so using the dashboard's Signer here to
 * simulate the connector-side verifier is faithful — drift between the two
 * implementations is covered by Connector\Tests SignerVerifyTest.
 */
final class SignedHttpClientEmptyBodyTest extends TestCase
{
    public function testEmptyBodySignedPostSendsEmptyWireBody(): void
    {
        $kp      = sodium_crypto_sign_keypair();
        $privB64 = base64_encode(sodium_crypto_sign_secretkey($kp));

        $capturedBody = 'SENTINEL';  // distinguish unset vs. empty-string vs. "[]"
        $mock = new MockHttpClient(function ($method, $url, $options) use (&$capturedBody) {
            $capturedBody = $options['body'] ?? '__UNSET__';
            return new MockResponse('', ['http_code' => 204]);
        });

        (new SignedHttpClient($mock))->signedPostJson(
            'https://x.test/wp-json/defyn-connector/v1/disconnect',
            [],
            $privB64,
            '/defyn-connector/v1/disconnect'
        );

        // Contract: empty array input MUST NOT put "[]" on the wire. Either:
        //   - the 'body' option is omitted (no entity body), OR
        //   - the 'body' option is "" (zero bytes)
        // Both are equivalent for downstream verifiers (get_body() returns "").
        // The pre-fix behaviour put "[]" on the wire — this assertion catches it.
        $this->assertNotSame(
            '[]',
            $capturedBody,
            'Regression: signedPostJson($url, [], ...) is putting "[]" (2 bytes) on the wire. ' .
            'The connector reads "" via WP_REST_Request::get_body() and computes a different ' .
            'sha256, breaking signature verification. Empty input must produce an empty wire body.'
        );

        $this->assertTrue(
            $capturedBody === '__UNSET__' || $capturedBody === '',
            sprintf(
                'Expected empty wire body (unset or ""), got: %s',
                var_export($capturedBody, true)
            )
        );
    }

    public function testEmptyBodySignedPostVerifiesAgainstEmptyBody(): void
    {
        // This is the end-to-end byte-parity check: the connector verifies its
        // received body bytes against the dashboard's signature. After the fix,
        // both sides must agree on "" for an empty input.
        $kp      = sodium_crypto_sign_keypair();
        $privB64 = base64_encode(sodium_crypto_sign_secretkey($kp));
        $pubB64  = base64_encode(sodium_crypto_sign_publickey($kp));

        $capturedHeaders = [];
        $mock = new MockHttpClient(function ($method, $url, $options) use (&$capturedHeaders) {
            $capturedHeaders = $options['headers'] ?? [];
            return new MockResponse('', ['http_code' => 204]);
        });

        (new SignedHttpClient($mock))->signedPostJson(
            'https://x.test/wp-json/defyn-connector/v1/disconnect',
            [],
            $privB64,
            '/defyn-connector/v1/disconnect'
        );

        $flat = [];
        foreach ((array) $capturedHeaders as $h) {
            if (is_string($h) && str_contains($h, ': ')) {
                [$name, $value] = explode(': ', $h, 2);
                $flat[$name] = $value;
            }
        }

        // Simulate the connector: it reads "" from WP_REST_Request::get_body()
        // for an empty POST body. The signature must verify against "".
        $result = Signer::verifyRequest(
            $pubB64,
            'POST',
            '/defyn-connector/v1/disconnect',
            '',  // <-- what the connector actually sees on the receiving end
            $flat,
            new InMemoryNonceStore()
        );

        $this->assertSame(
            VerificationResult::VALID,
            $result,
            'Dashboard signed over different bytes than "" — connector will reject with 401.'
        );
    }
}
