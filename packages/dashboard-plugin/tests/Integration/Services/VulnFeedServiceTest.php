<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Services\VulnFeedService;
use Defyn\Dashboard\Services\VulnerabilitiesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class VulnFeedServiceTest extends AbstractSchemaTestCase
{
    /** @var list<string> temp fixture files to clean up */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        // VulnerabilitiesRepository::upsertForSource() issues an explicit
        // START TRANSACTION/COMMIT, which escapes WP_UnitTestCase's per-test
        // rollback and leaks committed rows across tests. Mirror the
        // BulkJobsRepositoryTest idiom (guardrail #15): freshlyActivate +
        // autocommit + explicit purge to guarantee a clean slate.
        $this->freshlyActivate('defyn_vulnerabilities');

        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL
        $wpdb->query('SET autocommit = 1');
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_vulnerabilities");
        // phpcs:enable WordPress.DB.PreparedSQL

        Activation::ensureSchema();
        delete_option('defyn_vuln_feed_synced_at');
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        $this->tmpFiles = [];
        parent::tearDown();
    }

    public function testEmptyKeyNoOps(): void
    {
        $svc = new VulnFeedService(keyProvider: static fn (): string => '');
        $svc->refreshIfStale();
        self::assertSame(0, (new VulnerabilitiesRepository())->countAll());
        self::assertFalse(get_option('defyn_vuln_feed_synced_at'));
    }

    public function testMapsSamplePayloadIntoRows(): void
    {
        $path = $this->fixtureFile($this->sampleFeed());
        $svc  = new VulnFeedService(
            keyProvider: static fn (): string => 'test-key',
            downloader:  static fn (): ?string => $path,
        );
        $svc->refreshIfStale();

        $repo = new VulnerabilitiesRepository();
        self::assertGreaterThan(0, $repo->countAll());
        $rows = $repo->findByTypeAndSlug('plugin', 'elementor');
        self::assertNotEmpty($rows);
        self::assertSame('CVE-2024-5678', $rows[0]['cve']);
        self::assertNull($rows[0]['from_version'], 'literal "*" lower bound maps to null');
        self::assertSame('3.18.3', $rows[0]['fixed_in'], 'fixed_in from patched_versions[0]');
        self::assertNotFalse(get_option('defyn_vuln_feed_synced_at'));
    }

    public function testDownloadFailureLeavesPriorRowsAndDoesNotThrow(): void
    {
        (new VulnerabilitiesRepository())->upsertForSource('prior', [[
            'type'=>'plugin','slug'=>'akismet','title'=>'x','severity'=>'low','cvss_score'=>null,
            'cve'=>null,'from_version'=>null,'from_inclusive'=>true,'to_version'=>'1.0','to_inclusive'=>false,
            'fixed_in'=>'1.0','updated_at'=>'2026-06-15 00:00:00',
        ]]);

        $svc = new VulnFeedService(
            keyProvider: static fn (): string => 'test-key',
            downloader:  static fn (): ?string => null, // transport/non-2xx failure
        );
        $svc->refreshIfStale(); // must not throw
        self::assertSame(1, (new VulnerabilitiesRepository())->countAll(), 'prior rows preserved');
        self::assertFalse(get_option('defyn_vuln_feed_synced_at'), 'synced stamp not set on failed download');
    }

    public function testStalenessSkipWhenRecentlySynced(): void
    {
        update_option('defyn_vuln_feed_synced_at', gmdate('Y-m-d H:i:s'));
        $svc = new VulnFeedService(
            keyProvider: static fn (): string => 'test-key',
            downloader:  static function (): ?string {
                throw new \RuntimeException('download should not be called when fresh');
            },
        );
        $svc->refreshIfStale(); // returns without downloading
        self::assertTrue(true);
    }

    /** Writes the feed to a temp JSON file and returns its path (json-machine parses from file). */
    private function fixtureFile(array $feed): string
    {
        $path = tempnam(sys_get_temp_dir(), 'defyn-vuln-fixture');
        file_put_contents($path, (string) wp_json_encode($feed));
        $this->tmpFiles[] = $path;
        return $path;
    }

    /** @return array<string,mixed> Wordfence v3 record map keyed by UUID (per Task 1 note 053f8f0). */
    private function sampleFeed(): array
    {
        return [
            'uuid-1' => [
                'id' => 'uuid-1',
                'title' => 'Elementor <= 3.18.2 - XSS',
                'cve' => 'CVE-2024-5678',
                'cvss' => ['score' => '9.8', 'rating' => 'Critical'], // score is a STRING in the real feed
                'software' => [[
                    'type' => 'plugin',
                    'slug' => 'elementor',
                    'name' => 'Elementor',
                    'affected_versions' => [ // dict keyed by range-label, NOT an array
                        '* - 3.18.2' => [
                            'from_version' => '*', 'from_inclusive' => true,
                            'to_version' => '3.18.2', 'to_inclusive' => true,
                        ],
                    ],
                    'patched_versions' => ['3.18.3'],
                ]],
            ],
        ];
    }
}
