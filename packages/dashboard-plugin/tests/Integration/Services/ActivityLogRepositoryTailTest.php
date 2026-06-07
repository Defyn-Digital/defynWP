<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\ActivityLogRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class ActivityLogRepositoryTailTest extends AbstractSchemaTestCase
{
    public function testTailForUserReturnsTwentyFiveOrderedByCreatedAtDesc(): void
    {
        global $wpdb;
        $siteA = $this->seedSite(1);
        $siteB = $this->seedSite(2); // different user — must NOT appear in user 1's tail

        $logger = new ActivityLogger();
        for ($i = 0; $i < 30; $i++) {
            $logger->log(1, $siteA, 'site.health_ok', ['seq' => $i]);
        }
        for ($i = 0; $i < 5; $i++) {
            $logger->log(2, $siteB, 'site.health_ok', ['seq' => $i]);
        }

        $tail = (new ActivityLogRepository())->tailForUser(1, 25);

        $this->assertCount(25, $tail);
        $first = json_decode($tail[0]['details'] ?? '{}', true);
        $last  = json_decode($tail[24]['details'] ?? '{}', true);
        $this->assertSame(29, $first['seq']);
        $this->assertSame(5, $last['seq']);
    }

    public function testTailForUserExcludesOtherUsersEvents(): void
    {
        $siteA = $this->seedSite(1);
        $siteB = $this->seedSite(2);

        (new ActivityLogger())->log(1, $siteA, 'plugin_update.succeeded', ['marker' => 'user1']);
        (new ActivityLogger())->log(2, $siteB, 'plugin_update.succeeded', ['marker' => 'user2']);

        $tail = (new ActivityLogRepository())->tailForUser(1, 25);
        foreach ($tail as $row) {
            $details = json_decode($row['details'] ?? '{}', true);
            $this->assertSame('user1', $details['marker'] ?? null);
        }
    }

    public function testTailForUserIncludesSiteLabelJoin(): void
    {
        $siteA = $this->seedSite(1);
        (new ActivityLogger())->log(1, $siteA, 'plugin_update.succeeded', null);

        $tail = (new ActivityLogRepository())->tailForUser(1, 25);
        $this->assertNotEmpty($tail);
        $this->assertArrayHasKey('site_label', $tail[0]);
        $this->assertSame('Example', $tail[0]['site_label']);
    }

    private function seedSite(int $userId): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'    => $userId,
            'url'        => 'https://ex' . microtime(true) . '.com',
            'label'      => 'Example',
            'status'     => 'active',
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $wpdb->insert_id;
    }
}
