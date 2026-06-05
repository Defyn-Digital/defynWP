<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Schema\SitePluginsTable;
use Defyn\Dashboard\Services\SitePluginsRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * @group integration
 */
final class SitePluginsRepositoryTest extends AbstractSchemaTestCase
{
    private SitePluginsRepository $repo;

    public function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_site_plugins');
        global $wpdb;
        $wpdb->query('TRUNCATE ' . SitePluginsTable::tableName());
        $this->repo = new SitePluginsRepository();
    }

    public function testFindAllForSiteReturnsEmptyArrayWhenNoRows(): void
    {
        self::assertSame([], $this->repo->findAllForSite(99));
    }

    public function testReplaceForSiteInsertsRows(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->repo->replaceForSite(1, [
            ['slug' => 'akismet/akismet.php',    'name' => 'Akismet',     'version' => '5.3.1',  'update_available' => true,  'update_version' => '5.3.5'],
            ['slug' => 'rank-math/rank-math.php','name' => 'Rank Math',   'version' => '1.0.234','update_available' => false, 'update_version' => null],
        ], $now);

        $rows = $this->repo->findAllForSite(1);
        self::assertCount(2, $rows);
        self::assertSame('akismet/akismet.php', $rows[0]->slug);
        self::assertSame('Akismet',             $rows[0]->name);
        self::assertSame('5.3.1',               $rows[0]->version);
        self::assertTrue($rows[0]->updateAvailable);
        self::assertSame('5.3.5',               $rows[0]->updateVersion);
    }

    public function testReplaceForSiteDeletesMissingRows(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->repo->replaceForSite(1, [
            ['slug' => 'a.php', 'name' => 'A', 'version' => '1.0', 'update_available' => false, 'update_version' => null],
            ['slug' => 'b.php', 'name' => 'B', 'version' => '2.0', 'update_available' => false, 'update_version' => null],
        ], $now);
        $this->repo->replaceForSite(1, [
            ['slug' => 'a.php', 'name' => 'A', 'version' => '1.0', 'update_available' => false, 'update_version' => null],
        ], $now);

        $slugs = array_map(static fn($p) => $p->slug, $this->repo->findAllForSite(1));
        self::assertSame(['a.php'], $slugs);
    }

    public function testReplaceForSiteUpdatesChangedRows(): void
    {
        $t1 = gmdate('Y-m-d H:i:s', time() - 60);
        $t2 = gmdate('Y-m-d H:i:s');

        $this->repo->replaceForSite(1, [
            ['slug' => 'a.php', 'name' => 'A', 'version' => '1.0', 'update_available' => false, 'update_version' => null],
        ], $t1);

        $this->repo->replaceForSite(1, [
            ['slug' => 'a.php', 'name' => 'A', 'version' => '1.1', 'update_available' => true, 'update_version' => '1.2'],
        ], $t2);

        $rows = $this->repo->findAllForSite(1);
        self::assertSame('1.1',  $rows[0]->version);
        self::assertTrue($rows[0]->updateAvailable);
        self::assertSame('1.2',  $rows[0]->updateVersion);
    }

    public function testLastSyncedAtForSiteReturnsMaxLastSeenAt(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->repo->replaceForSite(1, [
            ['slug' => 'a.php', 'name' => 'A', 'version' => '1.0', 'update_available' => false, 'update_version' => null],
        ], $now);
        self::assertSame($now, $this->repo->lastSyncedAtForSite(1));
        self::assertNull($this->repo->lastSyncedAtForSite(99));
    }
}
