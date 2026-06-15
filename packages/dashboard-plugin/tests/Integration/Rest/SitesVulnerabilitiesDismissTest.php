<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Rest;

use Defyn\Dashboard\Auth\TokenService;
use Defyn\Dashboard\Schema\ActivityLogTable;
use Defyn\Dashboard\Schema\DismissedVulnerabilitiesTable;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Schema\SiteVulnerabilitiesTable;
use Defyn\Dashboard\Services\SiteVulnerabilitiesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;
use WP_REST_Request;

/**
 * P4.3b — POST /defyn/v1/sites/{id}/vulnerabilities/dismiss.
 *
 * Toggles a per-site dismissal of a single finding identified by its fingerprint
 * (type, slug, source_id). dismissed=true inserts (validated against the current
 * snapshot); dismissed=false restores. Ownership-gated (404 on non-owned).
 *
 * Note: SiteVulnerabilitiesRepository::replaceForSite() issues an explicit
 * START TRANSACTION/COMMIT which escapes WP_UnitTestCase's per-test rollback, so
 * setUp() runs with autocommit on and purges every table this test touches
 * (guardrail #15) before re-seeding.
 *
 * @group integration
 */
final class SitesVulnerabilitiesDismissTest extends AbstractSchemaTestCase
{
    private int $userId;
    private string $token;
    private int $siteId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_site_vulnerabilities');

        global $wpdb;
        $wpdb->query('SET autocommit=1');
        $wpdb->query('DELETE FROM ' . SiteVulnerabilitiesTable::tableName());
        $wpdb->query('DELETE FROM ' . SitesTable::tableName());
        $wpdb->query('DELETE FROM ' . DismissedVulnerabilitiesTable::tableName());
        $wpdb->query('DELETE FROM ' . ActivityLogTable::tableName());
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_defyn_rl_%' OR option_name LIKE '_transient_timeout_defyn_rl_%'");

        if (!defined('DEFYN_JWT_SECRET')) {
            define('DEFYN_JWT_SECRET', 'test-secret-32-chars-padding-padding');
        }
        do_action('rest_api_init');

        $this->userId = self::factory()->user->create();
        $this->token  = (new TokenService(DEFYN_JWT_SECRET))->issueAccess($this->userId);

        $this->siteId = $this->seedSite($this->userId);

        // Seed one finding into the current scan snapshot.
        (new SiteVulnerabilitiesRepository())->replaceForSite($this->siteId, [
            [
                'type'              => 'plugin',
                'slug'              => 'elementor',
                'component_name'    => 'Elementor',
                'installed_version' => '3.18.0',
                'severity'          => 'high',
                'cvss_score'        => 7.5,
                'cve'               => 'CVE-2024-9999',
                'fixed_in'          => '3.18.1',
                'title'             => 'Stored XSS',
                'source_id'         => 'src-ele',
            ],
        ], '2026-06-15 10:00:00');
    }

    // -------------------------------------------------------------------------
    // 1. dismissed:true for a finding present in the snapshot -> 200 + row + activity
    // -------------------------------------------------------------------------

    public function testDismissInsertsAndReturns200(): void
    {
        global $wpdb;

        $response = rest_do_request($this->buildRequest($this->siteId, [
            'type'      => 'plugin',
            'slug'      => 'elementor',
            'source_id' => 'src-ele',
            'dismissed' => true,
        ]));

        self::assertSame(200, $response->get_status());
        $body = $response->get_data();
        self::assertNull($body['error']);
        self::assertTrue($body['data']['dismissed']);

        $count = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . DismissedVulnerabilitiesTable::tableName()
            . ' WHERE site_id = %d AND type = %s AND slug = %s AND source_id = %s',
            $this->siteId,
            'plugin',
            'elementor',
            'src-ele'
        ));
        self::assertSame(1, $count);

        $event = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . ActivityLogTable::tableName()
            . ' WHERE site_id = %d AND event_type = %s ORDER BY id DESC LIMIT 1',
            $this->siteId,
            'site.vulnerability_dismissed'
        ), ARRAY_A);
        self::assertNotNull($event);
    }

    // -------------------------------------------------------------------------
    // 2. dismissed:false after a dismiss -> 200 + row gone + restore activity
    // -------------------------------------------------------------------------

    public function testRestoreDeletesAndReturns200(): void
    {
        global $wpdb;

        // First dismiss it.
        rest_do_request($this->buildRequest($this->siteId, [
            'type'      => 'plugin',
            'slug'      => 'elementor',
            'source_id' => 'src-ele',
            'dismissed' => true,
        ]));

        // Then restore.
        $response = rest_do_request($this->buildRequest($this->siteId, [
            'type'      => 'plugin',
            'slug'      => 'elementor',
            'source_id' => 'src-ele',
            'dismissed' => false,
        ]));

        self::assertSame(200, $response->get_status());
        $body = $response->get_data();
        self::assertNull($body['error']);
        self::assertFalse($body['data']['dismissed']);

        $count = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . DismissedVulnerabilitiesTable::tableName()
            . ' WHERE site_id = %d AND type = %s AND slug = %s AND source_id = %s',
            $this->siteId,
            'plugin',
            'elementor',
            'src-ele'
        ));
        self::assertSame(0, $count);

        $event = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . ActivityLogTable::tableName()
            . ' WHERE site_id = %d AND event_type = %s ORDER BY id DESC LIMIT 1',
            $this->siteId,
            'site.vulnerability_restored'
        ), ARRAY_A);
        self::assertNotNull($event);
    }

    // -------------------------------------------------------------------------
    // 3. dismissed:true for a fingerprint NOT in the snapshot -> 400 unknown_finding
    // -------------------------------------------------------------------------

    public function testDismissUnknownFingerprintReturns400(): void
    {
        $response = rest_do_request($this->buildRequest($this->siteId, [
            'type'      => 'plugin',
            'slug'      => 'akismet',
            'source_id' => 'src-nope',
            'dismissed' => true,
        ]));

        self::assertSame(400, $response->get_status());
        self::assertSame('vulnerabilities.unknown_finding', $response->get_data()['error']['code']);
    }

    // -------------------------------------------------------------------------
    // 4. site owned by a different user -> 404 sites.not_found
    // -------------------------------------------------------------------------

    public function testNonOwnedSiteReturns404(): void
    {
        $otherUserId = self::factory()->user->create();
        $otherSiteId = $this->seedSite($otherUserId);

        $response = rest_do_request($this->buildRequest($otherSiteId, [
            'type'      => 'plugin',
            'slug'      => 'elementor',
            'source_id' => 'src-ele',
            'dismissed' => true,
        ]));

        self::assertSame(404, $response->get_status());
        self::assertSame('sites.not_found', $response->get_data()['error']['code']);
    }

    // -------------------------------------------------------------------------
    // 5. missing / non-bool dismissed -> 400 invalid_payload
    // -------------------------------------------------------------------------

    public function testInvalidPayloadReturns400(): void
    {
        // Missing dismissed.
        $missing = rest_do_request($this->buildRequest($this->siteId, [
            'type'      => 'plugin',
            'slug'      => 'elementor',
            'source_id' => 'src-ele',
        ]));
        self::assertSame(400, $missing->get_status());
        self::assertSame('vulnerabilities.invalid_payload', $missing->get_data()['error']['code']);

        // Non-bool dismissed.
        $nonBool = rest_do_request($this->buildRequest($this->siteId, [
            'type'      => 'plugin',
            'slug'      => 'elementor',
            'source_id' => 'src-ele',
            'dismissed' => 'yes',
        ]));
        self::assertSame(400, $nonBool->get_status());
        self::assertSame('vulnerabilities.invalid_payload', $nonBool->get_data()['error']['code']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function seedSite(int $userId): int
    {
        global $wpdb;
        $wpdb->insert(SitesTable::tableName(), [
            'user_id'    => $userId,
            'url'        => 'https://smartcoding.test',
            'label'      => 'Smart',
            'status'     => 'active',
            'created_at' => '2026-06-15 00:00:00',
            'updated_at' => '2026-06-15 00:00:00',
        ]);
        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function buildRequest(int $siteId, array $body): WP_REST_Request
    {
        $req = new WP_REST_Request('POST', '/defyn/v1/sites/' . $siteId . '/vulnerabilities/dismiss');
        $req->set_header('Authorization', 'Bearer ' . $this->token);
        $req->set_header('Content-Type', 'application/json');
        $req->set_body((string) json_encode($body));
        return $req;
    }
}
