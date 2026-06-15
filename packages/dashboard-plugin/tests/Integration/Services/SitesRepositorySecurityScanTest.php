<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SitesRepositorySecurityScanTest extends AbstractSchemaTestCase
{
    public function testMarkSecurityScannedAtStampsAndSurfacesOnModel(): void
    {
        Activation::ensureSchema();
        $repo = new SitesRepository();
        $id   = $this->seedSite();
        $repo->markSecurityScannedAt($id, '2026-06-15 03:00:00');

        $site = $repo->findById($id);
        self::assertNotNull($site);
        self::assertSame('2026-06-15 03:00:00', $site->lastSecurityScanAt);
    }

    private function seedSite(): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'    => 1,
            'url'        => 'https://security-scan.test',
            'label'      => 'Security Scan Test',
            'status'     => 'active',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $wpdb->insert_id;
    }
}
