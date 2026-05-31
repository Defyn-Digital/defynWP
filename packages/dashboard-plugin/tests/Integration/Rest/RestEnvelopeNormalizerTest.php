<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Activation;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class RestEnvelopeNormalizerTest extends WP_UnitTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        Activation::activate();
        do_action('rest_api_init');
    }

    /**
     * Dispatch a request and run the `rest_post_dispatch` filter chain the same
     * way WP_REST_Server::serve_request() does. We can't use rest_do_request()
     * directly because it short-circuits to $server->dispatch() and never fires
     * rest_post_dispatch — yet that's exactly the filter we're verifying.
     *
     * See wp-includes/rest-api/class-wp-rest-server.php::serve_request() line
     * ~462 for the production filter call this mirrors.
     */
    private function dispatchAndPostFilter(WP_REST_Request $request)
    {
        $server = rest_get_server();
        $response = $server->dispatch($request);
        return apply_filters('rest_post_dispatch', rest_ensure_response($response), $server, $request);
    }

    public function test404OnUnknownDefynRouteUsesSpecEnvelope(): void
    {
        $response = $this->dispatchAndPostFilter(
            new WP_REST_Request('GET', '/defyn/v1/this-route-does-not-exist')
        );
        $this->assertSame(404, $response->get_status());
        $data = $response->get_data();
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('rest.route_not_found', $data['error']['code']);
        $this->assertArrayHasKey('message', $data['error']);
    }

    public function testWrongMethodOnKnownDefynRouteUsesSpecEnvelope(): void
    {
        // WP REST does NOT distinguish path-mismatch from method-mismatch — both
        // return rest_no_route (404). So PUT against /sites (a real route, wrong
        // method) returns the same 404 envelope as a typo'd path. Either way,
        // the SPA sees the spec envelope rather than WP's native shape.
        $response = $this->dispatchAndPostFilter(
            new WP_REST_Request('PUT', '/defyn/v1/sites')
        );
        $this->assertSame(404, $response->get_status());
        $data = $response->get_data();
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('rest.route_not_found', $data['error']['code']);
    }

    public function testNonDefynRoutesAreUnaffected(): void
    {
        // A WP-core route returning a non-envelope shape should NOT be touched.
        $response = $this->dispatchAndPostFilter(
            new WP_REST_Request('GET', '/wp/v2/this-also-does-not-exist')
        );
        $this->assertSame(404, $response->get_status());
        $data = $response->get_data();
        // The native WP shape uses 'code' at top level, not 'error.code'
        $this->assertArrayHasKey('code', $data);
        $this->assertSame('rest_no_route', $data['code']);
        $this->assertArrayNotHasKey('error', $data);
    }
}
