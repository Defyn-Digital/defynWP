<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\GoogleConfig;
use Defyn\Dashboard\Rest\AuthConfigController;
use WP_REST_Request;
use WP_UnitTestCase;

final class AuthConfigControllerTest extends WP_UnitTestCase
{
    public function tearDown(): void
    {
        delete_option(GoogleConfig::OPTION_KEY);
        parent::tearDown();
    }

    public function testReturnsResolvedClientIdAndNullErrorAt200(): void
    {
        // When the env constant is absent the option is the resolved value. If the
        // constant is already defined process-wide, clientId() returns IT — so we
        // assert against GoogleConfig::clientId() (the same resolver the SPA gets),
        // not the raw option, to stay deterministic across shared-process runs.
        update_option(GoogleConfig::OPTION_KEY, '999-xyz.apps.googleusercontent.com');

        $req  = new WP_REST_Request('GET', '/defyn/v1/auth/config');
        $resp = (new AuthConfigController())->handle($req);

        $this->assertSame(200, $resp->get_status());
        $data = $resp->get_data();
        $this->assertArrayHasKey('google_client_id', $data);
        $this->assertSame(GoogleConfig::clientId(), $data['google_client_id']);
        $this->assertArrayHasKey('error', $data);
        $this->assertNull($data['error']);
    }

    public function testReturnsEmptyClientIdWhenUnconfigured(): void
    {
        if (defined('DEFYN_GOOGLE_CLIENT_ID') && (string) constant('DEFYN_GOOGLE_CLIENT_ID') !== '') {
            $this->markTestSkipped('DEFYN_GOOGLE_CLIENT_ID is already defined in this process; unconfigured path is non-deterministic.');
        }

        delete_option(GoogleConfig::OPTION_KEY);

        $req  = new WP_REST_Request('GET', '/defyn/v1/auth/config');
        $resp = (new AuthConfigController())->handle($req);

        $this->assertSame(200, $resp->get_status());
        $data = $resp->get_data();
        $this->assertSame('', $data['google_client_id']);
        $this->assertNull($data['error']);
    }
}
