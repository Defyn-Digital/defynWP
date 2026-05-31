<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * F8 — SitesRepository::deleteForUser.
 *
 * User-scoped row delete used by the Disconnect flow. The SQL filters on BOTH
 * id AND user_id so an attacker who knows a site ID cannot delete another
 * user's site. Returns true only when exactly one row is removed; false for
 * both "not found" and "not owned" so callers can't enumerate site IDs they
 * don't own.
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

    public function testNonOwnerCannotDelete(): void
    {
        $id = $this->repo->insertPending(
            userId: 42,
            url: 'https://a.test',
            label: 'A',
            ourPublicKey: base64_encode(random_bytes(32)),
            ourPrivateKeyEncrypted: 'cipher',
        );

        // Different user id — must NOT delete
        self::assertFalse($this->repo->deleteForUser($id, 99));
        self::assertNotNull($this->repo->findById($id));
    }

    public function testMissingSiteReturnsFalse(): void
    {
        self::assertFalse($this->repo->deleteForUser(999999, 42));
    }
}
