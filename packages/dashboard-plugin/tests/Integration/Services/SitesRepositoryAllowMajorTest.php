<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Services;

use Defyn\Dashboard\Services\SitesRepository;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

final class SitesRepositoryAllowMajorTest extends AbstractSchemaTestCase
{
    public function testSetCoreAllowMajorPersistsTrue(): void
    {
        $siteId = $this->seedSite();
        $repo   = new SitesRepository();

        $repo->setCoreAllowMajor($siteId, true);

        $site = $repo->findById($siteId);
        $this->assertNotNull($site);
        $this->assertTrue($site->coreAllowMajor);
    }

    public function testSetCoreAllowMajorPersistsFalse(): void
    {
        $siteId = $this->seedSite();
        $repo   = new SitesRepository();

        $repo->setCoreAllowMajor($siteId, true);
        $repo->setCoreAllowMajor($siteId, false);

        $site = $repo->findById($siteId);
        $this->assertFalse($site->coreAllowMajor);
    }

    public function testFindByIdReturnsCoreAllowMajor(): void
    {
        global $wpdb;
        $siteId = $this->seedSite();
        $wpdb->update(
            $wpdb->prefix . 'defyn_sites',
            ['core_allow_major' => 1],
            ['id' => $siteId],
            ['%d'],
            ['%d']
        );

        $site = (new SitesRepository())->findById($siteId);
        $this->assertTrue($site->coreAllowMajor);
    }

    private function seedSite(): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'defyn_sites', [
            'user_id'    => 1,
            'url'        => 'https://example.com',
            'label'      => 'Example',
            'status'     => 'connected',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $wpdb->insert_id;
    }
}
