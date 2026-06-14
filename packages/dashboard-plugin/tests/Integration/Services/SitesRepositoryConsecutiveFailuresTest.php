<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * P3.1 Task 4 — SitesRepository consecutive_failures counter integration tests.
 *
 * Guardrail: explicit DELETE of sites rows in setUp so no state leaks across
 * test runs. Uses the same makeSite/direct-wpdb-insert pattern as
 * IncidentsRepositoryTest (P3.1 Task 3).
 *
 * @group integration
 */
final class SitesRepositoryConsecutiveFailuresTest extends AbstractSchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL
        $wpdb->query('SET autocommit = 1');
        $wpdb->query("DELETE FROM {$wpdb->prefix}defyn_sites");
        // phpcs:enable WordPress.DB.PreparedSQL
    }

    /**
     * Insert a site row and return its id. Mirrors the makeSite helper from
     * IncidentsRepositoryTest — direct $wpdb->insert, no SitesRepository.
     */
    private function makeSite(int $userId, string $label): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'    => $userId,
            'url'        => 'https://example-' . microtime(true) . '.test',
            'label'      => $label,
            'status'     => 'active',
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $wpdb->insert_id;
    }

    public function test_increment_then_reset_consecutive_failures(): void
    {
        $siteId = $this->makeSite(1, 'AcmeBlog');
        $repo = new SitesRepository();
        $this->assertSame(1, $repo->incrementConsecutiveFailures($siteId));
        $this->assertSame(2, $repo->incrementConsecutiveFailures($siteId));
        $repo->resetConsecutiveFailures($siteId);
        $this->assertSame(0, $repo->findById($siteId)->consecutiveFailures);
    }
}
