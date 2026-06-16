<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * P5.3 Task 16 — POST /defyn/v1/sites/{id}/client-email.
 *
 * Tests call SitesClientEmailController::handle() directly (routes are
 * registered in Task 17). Mirrors the setUp + seedSite from
 * ReportsRepositoryTest.
 *
 * @group integration
 */
final class SitesClientEmailTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Activation::ensureSchema();
        global $wpdb;
        $wpdb->query('SET autocommit = 1');
        foreach (['defyn_reports', 'defyn_sites'] as $t) {
            $wpdb->query('DELETE FROM ' . $wpdb->prefix . $t);
        }
    }

    /** Insert a minimal site row and return its id. Columns mirror the real defyn_sites schema. */
    private function seedSite(int $id = 1): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'id'         => $id,
            'user_id'    => 1,
            'url'        => 'https://acme.test',
            'label'      => 'Acme',
            'status'     => 'active',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
        return $id;
    }

    public function testNonOwnedReturns404(): void
    {
        $res = (new \Defyn\Dashboard\Rest\SitesClientEmailController())->handle($this->req(1, 999999, ['client_email' => 'c@acme.test']));
        self::assertSame(404, $res->get_status());
    }
    public function testInvalidEmailReturns400(): void
    {
        $siteId = $this->seedSite();
        $res = (new \Defyn\Dashboard\Rest\SitesClientEmailController())->handle($this->req(1, $siteId, ['client_email' => 'nope']));
        self::assertSame(400, $res->get_status());
        self::assertSame('sites.invalid_client_email', $res->get_data()['error']['code']);
    }
    public function testSetValidThenClear(): void
    {
        $siteId = $this->seedSite();
        $res = (new \Defyn\Dashboard\Rest\SitesClientEmailController())->handle($this->req(1, $siteId, ['client_email' => 'c@acme.test']));
        self::assertSame(200, $res->get_status());
        self::assertSame('c@acme.test', $res->get_data()['data']['client_email']);
        self::assertSame('c@acme.test', (new \Defyn\Dashboard\Services\SitesRepository())->findByIdForUser($siteId, 1)->clientEmail);
        // clear
        $res2 = (new \Defyn\Dashboard\Rest\SitesClientEmailController())->handle($this->req(1, $siteId, ['client_email' => '']));
        self::assertSame(200, $res2->get_status());
        self::assertNull($res2->get_data()['data']['client_email']);
        self::assertNull((new \Defyn\Dashboard\Services\SitesRepository())->findByIdForUser($siteId, 1)->clientEmail);
    }
    private function req(int $uid, int $siteId, array $body): \WP_REST_Request
    {
        $r = new \WP_REST_Request('POST', '/x');
        $r->set_param('_authenticated_user_id', $uid); $r->set_param('id', $siteId);
        $r->set_body(json_encode($body)); $r->set_header('Content-Type', 'application/json');
        return $r;
    }
}
