<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Schema\DismissedVulnerabilitiesTable;
use Defyn\Dashboard\Services\DismissedVulnerabilitiesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class DismissedVulnerabilitiesRepositoryTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Activation::ensureSchema();
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->query('SET autocommit = 1');
        // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->query('DELETE FROM ' . DismissedVulnerabilitiesTable::tableName());
    }

    public function testDismissInsertsAndFindFingerprintsReturnsIt(): void
    {
        $repo = new DismissedVulnerabilitiesRepository();
        $repo->dismiss(7, 'plugin', 'elementor', 'src-ele', 1, '2026-06-15 00:00:00');

        $fps = $repo->findFingerprintsForSite(7);
        self::assertArrayHasKey('plugin|elementor|src-ele', $fps);
        self::assertTrue($fps['plugin|elementor|src-ele']);
    }

    public function testDismissIsIdempotent(): void
    {
        $repo = new DismissedVulnerabilitiesRepository();
        $repo->dismiss(7, 'plugin', 'elementor', 'src-ele', 1, '2026-06-15 00:00:00');
        $repo->dismiss(7, 'plugin', 'elementor', 'src-ele', 2, '2026-06-15 01:00:00');

        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL
        $count = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . DismissedVulnerabilitiesTable::tableName());
        self::assertSame(1, $count, 'repeat dismiss of the same fingerprint must not duplicate');
    }

    public function testRestoreDeletes(): void
    {
        $repo = new DismissedVulnerabilitiesRepository();
        $repo->dismiss(7, 'plugin', 'elementor', 'src-ele', 1, '2026-06-15 00:00:00');
        $repo->restore(7, 'plugin', 'elementor', 'src-ele');

        self::assertSame([], $repo->findFingerprintsForSite(7));
    }

    public function testRestoreOfMissingRowIsNoop(): void
    {
        $repo = new DismissedVulnerabilitiesRepository();
        $repo->restore(7, 'plugin', 'nonexistent', 'src-x'); // must not throw
        self::assertSame([], $repo->findFingerprintsForSite(7));
    }

    public function testFingerprintsAreScopedPerSite(): void
    {
        $repo = new DismissedVulnerabilitiesRepository();
        $repo->dismiss(7, 'plugin', 'elementor', 'src-ele', 1, '2026-06-15 00:00:00');
        $repo->dismiss(8, 'plugin', 'akismet', 'src-ak', 1, '2026-06-15 00:00:00');

        self::assertSame(['plugin|elementor|src-ele' => true], $repo->findFingerprintsForSite(7));
        self::assertSame(['plugin|akismet|src-ak' => true], $repo->findFingerprintsForSite(8));
    }
}
