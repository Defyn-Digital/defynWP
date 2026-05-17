<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration\Jobs;

use Defyn\Dashboard\Jobs\CompleteConnection;
use Defyn\Dashboard\Schema\ActivityLogTable;
use Defyn\Dashboard\Schema\SitesTable;
use Defyn\Dashboard\Tests\Integration\AbstractSchemaTestCase;

/**
 * @group integration
 */
final class CompleteConnectionTest extends AbstractSchemaTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->freshlyActivate('defyn_sites');
        $this->freshlyActivate('defyn_activity_log');
        global $wpdb;
        $wpdb->query('TRUNCATE ' . SitesTable::tableName());
        $wpdb->query('TRUNCATE ' . ActivityLogTable::tableName());
        do_action('rest_api_init');  // ensures Plugin::boot() registered the hook
    }

    public function testActionSchedulerHookIsRegistered(): void
    {
        // Plugin::boot() should have registered this on plugin load.
        self::assertNotFalse(has_action('defyn_complete_connection'));
    }

    public function testHandleStaticMethodIsInvocableWithoutFatal(): void
    {
        // Smoke test — Connection's behavior is covered by ConnectionTest.
        // Here we just ensure the wrapper constructs everything correctly:
        // no TypeError, no missing-class, no missing-const.
        // It's OK if the call ultimately marks site=error because the site_id
        // doesn't exist (no row, so findById returns null and complete() exits).
        if (!defined('DEFYN_VAULT_KEY')) {
            define('DEFYN_VAULT_KEY', \Defyn\Dashboard\Crypto\Vault::generateKey());
        }
        CompleteConnection::handle(999999, 'STUB', 'https://nowhere.test');
        self::assertTrue(true);  // didn't throw
    }
}
