<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Jobs;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Jobs\SyncSite;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * @group integration
 *
 * SyncSite is a delegation shim; SyncService has its own behavior coverage
 * (SyncServiceTest). Here we prove:
 *   - the HOOK constant matches the spec ('defyn_sync_site')
 *   - Plugin::boot() registered the AS hook
 *   - handle() is invocable without a fatal (smoke test against a non-existent
 *     site id — SyncService::sync returns early on findById === null, so no
 *     HTTP / vault work occurs)
 *
 * We can't use createMock(SyncService::class) because SyncService is `final`.
 * Mirrors F5's CompleteConnectionTest::testHandleStaticMethodIsInvocableWithoutFatal
 * approach.
 */
final class SyncSiteTest extends AbstractSchemaTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_sites');
        global $wpdb;
        $wpdb->query('TRUNCATE ' . SitesTable::tableName());
    }

    public function testHookNameIsDefynSyncSite(): void
    {
        $this->assertSame('defyn_sync_site', SyncSite::HOOK);
    }

    public function testActionSchedulerHookIsRegistered(): void
    {
        // Plugin::boot() should have registered this on plugin load.
        self::assertNotFalse(has_action(SyncSite::HOOK));
    }

    public function testHandleIsInvocableWithoutFatal(): void
    {
        // SyncService's behavior is covered by SyncServiceTest. Here we just
        // ensure the wrapper constructs everything correctly: no TypeError,
        // no missing-class, no missing-const. It's OK that the call no-ops
        // because site_id 999999 doesn't exist (findById returns null and
        // SyncService::sync exits before any HTTP / vault work).
        if (!defined('DEFYN_VAULT_KEY')) {
            define('DEFYN_VAULT_KEY', Vault::generateKey());
        }
        (new SyncSite())->handle(999999);
        self::assertTrue(true);  // didn't throw
    }
}
