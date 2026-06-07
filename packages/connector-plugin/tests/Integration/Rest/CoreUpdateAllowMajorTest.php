<?php

declare(strict_types=1);

namespace Defyn\Connector\Tests\Integration\Rest;

use Defyn\Connector\Rest\CoreUpdateController;
use Defyn\Connector\SiteInfo\CapturingUpgraderSkin;
use Defyn\Connector\SiteInfo\CoreUpgraderService;
use WP_REST_Request;
use WP_UnitTestCase;

final class CoreUpdateAllowMajorTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Stub: current 7.4, target 8.0 (major bump).
        delete_site_transient('update_core');
        $update = new \stdClass();
        $update->updates = [(object) [
            'response' => 'upgrade',
            'current'  => '8.0',
            'version'  => '8.0',
            'package'  => 'https://example.test/wp.zip',
            'locale'   => 'en_US',
        ]];
        $update->version_checked = '7.4';
        set_site_transient('update_core', $update);

        delete_transient('defyn_connector_upgrade_in_flight');
    }

    private function buildController(): CoreUpdateController
    {
        $factory = static fn(CapturingUpgraderSkin $skin): object => new class {
            public function upgrade(\stdClass $update): bool
            {
                return true;
            }
        };
        return new CoreUpdateController(new CoreUpgraderService($factory));
    }

    public function testAllowMajorBodyParamPassesThroughToServiceAndSucceeds(): void
    {
        $request = new WP_REST_Request('POST', '/defyn-connector/v1/core/update');
        $request->set_body(json_encode(['allow_major' => true]));
        $request->set_header('Content-Type', 'application/json');

        $response = $this->buildController()->handle($request);

        $this->assertSame(200, $response->get_status());
        $this->assertTrue($response->get_data()['success']);
    }

    public function testMissingAllowMajorFieldStillBlocksMajor(): void
    {
        $request = new WP_REST_Request('POST', '/defyn-connector/v1/core/update');
        $request->set_body(json_encode(['some_other_field' => 'value']));
        $request->set_header('Content-Type', 'application/json');

        $response = $this->buildController()->handle($request);

        $this->assertSame(409, $response->get_status());
        $this->assertSame('core.major_update_blocked', $response->get_data()['error']['code']);
    }

    public function testAllowMajorAsStringTrueStillBlocksMajor(): void
    {
        // Defends against the strict === true check requirement.
        $request = new WP_REST_Request('POST', '/defyn-connector/v1/core/update');
        $request->set_body(json_encode(['allow_major' => 'true']));
        $request->set_header('Content-Type', 'application/json');

        $response = $this->buildController()->handle($request);

        $this->assertSame(409, $response->get_status());
        $this->assertSame('core.major_update_blocked', $response->get_data()['error']['code']);
    }

    public function testAllowMajorAsIntegerOneStillBlocksMajor(): void
    {
        $request = new WP_REST_Request('POST', '/defyn-connector/v1/core/update');
        $request->set_body(json_encode(['allow_major' => 1]));
        $request->set_header('Content-Type', 'application/json');

        $response = $this->buildController()->handle($request);

        $this->assertSame(409, $response->get_status());
        $this->assertSame('core.major_update_blocked', $response->get_data()['error']['code']);
    }
}
