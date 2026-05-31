<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

/**
 * F8 — DELETE /defyn/v1/sites/{id}.
 *
 * Soft-disconnect REST endpoint. Delegates to DisconnectService which signs a
 * POST /disconnect to the connector and deletes the dashboard row. In this
 * test env the connector host is unreachable, so the connector call fails
 * silently — the row is still deleted per the soft-disconnect contract.
 *
 * @group integration
 */
final class SitesDeleteTest extends AbstractSchemaTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_sites');
        global $wpdb;
        $wpdb->query('TRUNCATE ' . SitesTable::tableName());
        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
        do_action('rest_api_init');
    }

    /** Insert an active site owned by $userId with a real Vault-encrypted private key. */
    private function insertActiveSite(int $userId): int
    {
        $repo  = new SitesRepository();
        $vault = new Vault(DEFYN_VAULT_KEY);
        $encPriv = $vault->encrypt(base64_encode(random_bytes(64)));
        $siteId = $repo->insertPending(
            $userId,
            'https://defyn.test',
            'Site',
            base64_encode(random_bytes(32)),
            $encPriv,
        );
        $repo->markActive($siteId, base64_encode(random_bytes(32)));
        return $siteId;
    }

    public function testOwnerCanDeleteOwnSite(): void
    {
        $userId = self::factory()->user->create();
        $token  = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($userId);
        $siteId = $this->insertActiveSite($userId);

        $req = new WP_REST_Request('DELETE', '/defyn/v1/sites/' . $siteId);
        $req->set_header('Authorization', 'Bearer ' . $token);
        $r = rest_do_request($req);

        self::assertSame(204, $r->get_status());
        // Soft disconnect: connector unreachable in test env, but row still deleted.
        self::assertNull((new SitesRepository())->findById($siteId));
    }

    public function testNonOwnerGets404(): void
    {
        $ownerId  = self::factory()->user->create();
        $stranger = self::factory()->user->create();
        $token    = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($stranger);
        $siteId   = $this->insertActiveSite($ownerId);

        $req = new WP_REST_Request('DELETE', '/defyn/v1/sites/' . $siteId);
        $req->set_header('Authorization', 'Bearer ' . $token);
        $r = rest_do_request($req);

        self::assertSame(404, $r->get_status());
        self::assertSame('sites.not_found', $r->get_data()['error']['code']);

        // Row must STILL be present — non-owner cannot enumerate or destroy.
        self::assertNotNull((new SitesRepository())->findById($siteId));
    }

    public function testUnauthenticatedReturns401(): void
    {
        $req = new WP_REST_Request('DELETE', '/defyn/v1/sites/1');
        $r = rest_do_request($req);

        self::assertSame(401, $r->get_status());
    }
}
