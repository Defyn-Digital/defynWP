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

        $hit  = $this->repo->findByIdForUser($id, 7);
        $miss = $this->repo->findByIdForUser($id, 999);

        self::assertNotNull($hit);
        self::assertSame($id, $hit->id);
        self::assertNull($miss);
    }

    public function testFindAllForUserReturnsOnlyThatUsersSites(): void
    {
        $this->repo->insertPending(7, 'https://a.test', '', 'P', 'E');
        $this->repo->insertPending(7, 'https://b.test', '', 'P', 'E');
        $this->repo->insertPending(8, 'https://c.test', '', 'P', 'E');

        $sites = $this->repo->findAllForUser(7);

        self::assertCount(2, $sites);
        self::assertSame(['https://a.test', 'https://b.test'], array_map(fn ($s) => $s->url, $sites));
    }

    public function testExistsForUserCheckIsCaseInsensitiveAndUserScoped(): void
    {
        $this->repo->insertPending(7, 'https://Foo.Example', '', 'P', 'E');

        self::assertTrue($this->repo->existsForUser(7, 'https://foo.example'));   // case-insensitive
        self::assertFalse($this->repo->existsForUser(8, 'https://foo.example'));  // user-scoped
        self::assertFalse($this->repo->existsForUser(7, 'https://other.test'));
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
