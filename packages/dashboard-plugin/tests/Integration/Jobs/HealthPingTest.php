<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Jobs;

use Defyn\Dashboard\Crypto\Vault;
use Defyn\Dashboard\Jobs\HealthPing;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * @group integration
 *
 * HealthPing is a delegation shim; HealthService has its own behavior coverage
 * (HealthServiceTest). Here we prove:
 *   - the HOOK constant matches the spec ('defyn_health_ping')
 *   - Plugin::boot() registered the AS hook
 *   - handle() is invocable without a fatal (smoke test against a non-existent
 *     site id — HealthService::ping returns early on findById === null, so no
 *     HTTP / vault work occurs)
 *
 * We can't use createMock(HealthService::class) because HealthService is
 * `final`. Mirrors F5's CompleteConnectionTest pattern.
 */
final class HealthPingTest extends AbstractSchemaTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_sites');
        global $wpdb;
        $wpdb->query('TRUNCATE ' . SitesTable::tableName());
    }

    public function testHookNameIsDefynHealthPing(): void
    {
        $this->assertSame('defyn_health_ping', HealthPing::HOOK);
    }

    public function testActionSchedulerHookIsRegistered(): void
    {
        // Plugin::boot() should have registered this on plugin load.
        self::assertNotFalse(has_action(HealthPing::HOOK));
    }

    public function testHandleIsInvocableWithoutFatal(): void
    {
        // HealthService's behavior is covered by HealthServiceTest. Smoke test
        // against site_id 999999 (doesn't exist → findById returns null and
        // HealthService::ping exits before any HTTP / vault work).
        if (!defined('DEFYN_VAULT_KEY')) {
            define('DEFYN_VAULT_KEY', Vault::generateKey());
        }
        (new HealthPing())->handle(999999);
        self::assertTrue(true);  // didn't throw
    }
}
