<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * @group integration
 */
final class SitesRepositoryTest extends AbstractSchemaTestCase
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

    public function testInsertPendingReturnsRowIdAndPersistsAllFields(): void
    {
        $id = $this->repo->insertPending(
            userId: 7,
            url: 'https://example.test',
            label: 'Test',
            ourPublicKey: 'OURPUB==',
            ourPrivateKeyEncrypted: 'ENC==',
        );

        self::assertGreaterThan(0, $id);

        $site = $this->repo->findById($id);
        self::assertNotNull($site);
        self::assertSame(7, $site->userId);
        self::assertSame('https://example.test', $site->url);
        self::assertSame('Test', $site->label);
        self::assertSame('pending', $site->status);
        self::assertSame('OURPUB==', $site->ourPublicKey);
        self::assertNull($site->sitePublicKey);
    }

    public function testFindByIdForUserReturnsSiteForOwner(): void
    {
        $id = $this->repo->insertPending(7, 'https://owner.test', '', 'P', 'E');

        $hit     = $this->repo->findByIdForUser($id, 7);
        // Team-wide: any userId can find any site.
        $alsoHit = $this->repo->findByIdForUser($id, 999);

        self::assertNotNull($hit);
        self::assertSame($id, $hit->id);
        self::assertNotNull($alsoHit);
    }

    public function testFindAllForUserReturnsAllSitesTeamWide(): void
    {
        $this->repo->insertPending(7, 'https://a.test', '', 'P', 'E');
        $this->repo->insertPending(7, 'https://b.test', '', 'P', 'E');
        $this->repo->insertPending(8, 'https://c.test', '', 'P', 'E');

        // Team-wide: all 3 sites visible regardless of which userId is passed.
        $sites = $this->repo->findAllForUser(7);
        self::assertCount(3, $sites);

        $sites2 = $this->repo->findAllForUser(8);
        self::assertCount(3, $sites2);
    }

    public function testFleetIsTeamWideAcrossUsers(): void
    {
        $siteId = $this->repo->insertPending(11, 'https://userA.test', 'A', 'PUB', 'ENC');
        // User 22 can find user 11's site
        $this->assertNotNull($this->repo->findByIdForUser($siteId, 22));
        // User 22's findAll returns it
        $sites = $this->repo->findAllForUser(22);
        $this->assertCount(1, $sites);
        $this->assertSame($siteId, $sites[0]->id);
        // countAllForUser also returns it
        $this->assertSame(1, $this->repo->countAllForUser(22));
    }

    public function testExistsForUserCheckIsCaseInsensitiveAndTeamWide(): void
    {
        // Seeded under user A (user 7).
        $this->repo->insertPending(7, 'https://Foo.Example', '', 'P', 'E');

        // Case-insensitive match still works.
        self::assertTrue($this->repo->existsForUser(7, 'https://foo.example'));
        // Team-wide: a different user (user 8) now ALSO sees the URL as taken.
        // (Previously assertFalse for user 8 — flipped 2026-06-22 SSO de-scope.)
        self::assertTrue($this->repo->existsForUser(8, 'https://foo.example'));
        // A completely different URL is not found by anyone.
        self::assertFalse($this->repo->existsForUser(7, 'https://other.test'));
    }

    public function testDeleteForUserIsTeamWide(): void
    {
        // Seed under user A (user 1).
        $idA = $this->repo->insertPending(1, 'https://del-a.test', '', 'P', 'E');
        // Seed under user B (user 2).
        $idB = $this->repo->insertPending(2, 'https://del-b.test', '', 'P', 'E');

        // User B can delete user A's site (team-wide — any admin can delete any site).
        self::assertTrue($this->repo->deleteForUser($idA, 2));
        self::assertNull($this->repo->findById($idA));

        // User A can delete user B's site too.
        self::assertTrue($this->repo->deleteForUser($idB, 1));
        self::assertNull($this->repo->findById($idB));
    }

    public function testDeleteForUserReturnsFalseForMissingSite(): void
    {
        self::assertFalse($this->repo->deleteForUser(99999, 1));
    }

    public function testMarkActiveUpdatesStatusAndKeysAndContactTimestamp(): void
    {
        $id = $this->repo->insertPending(7, 'https://example.test', '', 'OURPUB', 'OURENC');

        $this->repo->markActive($id, 'SITEPUB==');

        $site = $this->repo->findById($id);
        self::assertSame('active', $site->status);
        self::assertSame('SITEPUB==', $site->sitePublicKey);
        self::assertNotNull($site->lastContactAt);
    }

    public function testMarkErrorUpdatesStatusAndLastError(): void
    {
        $id = $this->repo->insertPending(7, 'https://example.test', '', 'P', 'E');

        $this->repo->markError($id, 'Connector unreachable');

        $site = $this->repo->findById($id);
        self::assertSame('error', $site->status);
        self::assertSame('Connector unreachable', $site->lastError);
    }

    public function testRecordResponseTimeSetsAndNullsValue(): void
    {
        $repo = new SitesRepository();
        $id = $repo->insertPending(
            userId: 1, url: 'https://rt.test', label: 'RT',
            ourPublicKey: 'pk', ourPrivateKeyEncrypted: 'enc',
        );

        $repo->recordResponseTime($id, 247);
        self::assertSame(247, $repo->findById($id)->lastResponseTimeMs);

        $repo->recordResponseTime($id, null);
        self::assertNull($repo->findById($id)->lastResponseTimeMs);
    }

    public function testSetAlertsMutedAndSslStampHelpers(): void
    {
        $repo = new SitesRepository();
        $id = $repo->insertPending(userId: 1, url: 'https://m.test', label: 'M', ourPublicKey: 'pk', ourPrivateKeyEncrypted: 'enc');

        $repo->setAlertsMuted($id, true);
        self::assertTrue($repo->findById($id)->alertsMuted);
        $repo->setAlertsMuted($id, false);
        self::assertFalse($repo->findById($id)->alertsMuted);

        $repo->markSslAlertSent($id, '2026-06-14 02:00:00');
        self::assertSame('2026-06-14 02:00:00', $repo->findById($id)->sslAlertSentAt);
        $repo->clearSslAlertSent($id);
        self::assertNull($repo->findById($id)->sslAlertSentAt);
    }
}
