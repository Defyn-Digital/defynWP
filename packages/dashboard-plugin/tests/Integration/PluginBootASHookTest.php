<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration;

use Defyn\Dashboard\Jobs\UpdateSitePlugin;
use Defyn\Dashboard\Plugin;
use WP_UnitTestCase;

/**
 * Verifies that Plugin::boot() registers the P2.2 update AS hook so the
 * Action Scheduler runtime can dispatch UpdateSitePlugin::handle when
 * SitesPluginsUpdateController schedules `defyn_update_site_plugin`.
 */
final class PluginBootASHookTest extends WP_UnitTestCase
{
    public function testUpdateSitePluginHookRegistered(): void
    {
        Plugin::instance()->boot();
        $this->assertNotFalse(has_action(UpdateSitePlugin::HOOK));
    }
}
