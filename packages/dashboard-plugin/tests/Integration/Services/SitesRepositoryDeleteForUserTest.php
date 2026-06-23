<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * F8 — SitesRepository::deleteForUser.
 *
 * Team-wide row delete used by the Disconnect flow. Any authenticated admin can
 * delete any site in the shared fleet — the SQL now filters on id only.
 * Returns true when exactly one row is removed; false when the id doesn't exist.
 *
 * 2026-06-22 SSO de-scope: user_id predicate removed from DELETE; per-user
 * isolation is now handled entirely at the controller/gate layer via the
 * already-team-wide findByIdForUser.
 *
 * @group integration
 */
final class SitesRepositoryDeleteForUserTest extends AbstractSchemaTestCase
{
    private SitesRepository $repo;

    public function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_sites');
        global $wpdb;
        $wpdb->query('TRUNCATE ' . SitesTable::tableName());
        $this->repo = new SitesRepository();
    }

    public function testOwnerCanDelete(): void
    {
        $id = $this->repo->insertPending(
            userId: 42,
            url: 'https://a.test',
            label: 'A',
            ourPublicKey: base64_encode(random_bytes(32)),
            ourPrivateKeyEncrypted: 'cipher',
        );

        self::assertTrue($this->repo->deleteForUser($id, 42));
        self::assertNull($this->repo->findById($id));
    }

    public function testAnyTeamMemberCanDelete(): void
    {
        // Site created by user 42; user 99 (a different team member) must also be
        // able to delete it in a shared fleet. Previously asserted false —
        // flipped 2026-06-22 SSO de-scope: deleteForUser is now team-wide.
        $id = $this->repo->insertPending(
            userId: 42,
            url: 'https://a.test',
            label: 'A',
            ourPublicKey: base64_encode(random_bytes(32)),
            ourPrivateKeyEncrypted: 'cipher',
        );

        self::assertTrue($this->repo->deleteForUser($id, 99));
        self::assertNull($this->repo->findById($id));
    }

    public function testMissingSiteReturnsFalse(): void
    {
        self::assertFalse($this->repo->deleteForUser(999999, 42));
    }
}
