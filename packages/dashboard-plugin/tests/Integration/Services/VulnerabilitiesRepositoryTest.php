<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Services\VulnerabilitiesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class VulnerabilitiesRepositoryTest extends AbstractSchemaTestCase
{
    private VulnerabilitiesRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        Activation::ensureSchema();
        $this->repo = new VulnerabilitiesRepository();
    }

    public function testUpsertInsertsRowsAndFindByTypeAndSlugReturnsThem(): void
    {
        $now = '2026-06-15 00:00:00';
        $this->repo->upsertForSource('src-1', [
            ['type' => 'plugin', 'slug' => 'elementor', 'title' => 'XSS', 'severity' => 'high',
             'cvss_score' => 7.5, 'cve' => 'CVE-1', 'from_version' => null, 'from_inclusive' => true,
             'to_version' => '3.18.3', 'to_inclusive' => false, 'fixed_in' => '3.18.3', 'updated_at' => $now],
        ]);
        $rows = $this->repo->findByTypeAndSlug('plugin', 'elementor');
        self::assertCount(1, $rows);
        self::assertSame('CVE-1', $rows[0]['cve']);
        self::assertSame(1, $this->repo->countAll());
    }

    public function testUpsertForSourceIsIdempotentReplacingPriorRows(): void
    {
        $now = '2026-06-15 00:00:00';
        $payload = [
            ['type' => 'plugin', 'slug' => 'elementor', 'title' => 'XSS', 'severity' => 'high',
             'cvss_score' => 7.5, 'cve' => 'CVE-1', 'from_version' => null, 'from_inclusive' => true,
             'to_version' => '3.18.3', 'to_inclusive' => false, 'fixed_in' => '3.18.3', 'updated_at' => $now],
        ];
        $this->repo->upsertForSource('src-1', $payload);
        $this->repo->upsertForSource('src-1', $payload); // re-ingest same source
        self::assertSame(1, $this->repo->countAll(), 're-ingesting a source must not duplicate');
    }
}
