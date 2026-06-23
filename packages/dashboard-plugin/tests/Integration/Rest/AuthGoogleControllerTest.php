<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Rest\AuthGoogleController;
use Defyn\Dashboard\Auth\GoogleIdTokenVerifier;
use Defyn\Dashboard\Auth\Exceptions\GoogleAuthException;
use WP_UnitTestCase;
use WP_REST_Request;

final class AuthGoogleControllerTest extends WP_UnitTestCase
{
    private function controllerReturning(array $claims): AuthGoogleController
    {
        $verifier = new class('cid', $claims) extends GoogleIdTokenVerifier {
            public function __construct(string $c, private array $claims) { parent::__construct($c); }
            public function verify(string $idToken): array { return $this->claims; }
        };
        return new AuthGoogleController($verifier);
    }

    public function testSuccessIssuesAccessTokenAndSetsRefreshCookie(): void
    {
        if (!defined('DEFYN_GOOGLE_CLIENT_ID')) define('DEFYN_GOOGLE_CLIENT_ID', 'cid');
        if (!defined('DEFYN_JWT_SECRET')) define('DEFYN_JWT_SECRET', str_repeat('a', 40));
        $req = new WP_REST_Request('POST', '/defyn/v1/auth/google');
        $req->set_body(json_encode(['credential' => 'fake.jwt']));
        $req->set_header('Content-Type', 'application/json');
        $resp = $this->controllerReturning([
            'email' => 'devs@defyn.com.au', 'name' => 'Devs', 'sub' => 's1',
        ])->handle($req);
        $data = $resp->get_data();
        $this->assertArrayHasKey('access_token', $data);
        $cookie = $resp->get_headers()['Set-Cookie'] ?? '';
        $this->assertStringContainsString('defyn_refresh=', is_array($cookie) ? implode(';', $cookie) : $cookie);
    }

    public function testDomainRejectionReturns403(): void
    {
        if (!defined('DEFYN_GOOGLE_CLIENT_ID')) define('DEFYN_GOOGLE_CLIENT_ID', 'cid');
        $verifier = new class('cid') extends GoogleIdTokenVerifier {
            public function verify(string $idToken): array {
                throw new GoogleAuthException('auth.google_domain', 403, 'nope');
            }
        };
        $req = new WP_REST_Request('POST', '/defyn/v1/auth/google');
        $req->set_body(json_encode(['credential' => 'fake'])); $req->set_header('Content-Type', 'application/json');
        $resp = (new AuthGoogleController($verifier))->handle($req);
        $this->assertSame(403, $resp->get_status());
        $this->assertSame('auth.google_domain', $resp->get_data()['error']['code']);
    }

    public function testMissingCredentialReturns400(): void
    {
        if (!defined('DEFYN_GOOGLE_CLIENT_ID')) define('DEFYN_GOOGLE_CLIENT_ID', 'cid');
        $req = new WP_REST_Request('POST', '/defyn/v1/auth/google');
        $req->set_body(json_encode([])); $req->set_header('Content-Type', 'application/json');
        $resp = $this->controllerReturning([])->handle($req);
        $this->assertSame(400, $resp->get_status());
    }
}
