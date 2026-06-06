<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Tests\Integration;

use Defyn\Dashboard\Activation;
use Defyn\Dashboard\Schema\SchemaVersion;
use Defyn\Dashboard\Schema\SitesTable;
use WP_UnitTestCase;

/**
 * P2.2.1 — verifies the `plugins_loaded` self-heal hook re-installs schema
 * when SchemaVersion is behind (someone bumped the constant without
 * reactivating) or when the canonical sites table is missing (Uninstaller
 * fired accidentally during "Replace current with uploaded version").
 *
 * Without this, the operator had to manually deactivate + reactivate the
 * plugin after every release. Caught during P2.2 production smoke when
 * `wp_defyn_sites` vanished and POST /sites silently 202'd `{site_id: 0}`.
 *
 * Note: WP_UnitTestCase wraps tests in a transaction + intercepts CREATE/DROP
 * TABLE via the `_create_temporary_tables` filter, so we can't reliably
 * simulate the "table missing" trigger inside a test. The
 * SchemaVersion-behind trigger is functionally equivalent (same dbDelta
 * call) and works cleanly in the test fixture; production observes both.
 */
final class SchemaSelfHealTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Activation::activate(); // start from a clean, fully-installed state
        delete_transient('defyn_dashboard_schema_check');
    }

    public function testSelfHealRunsWhenSchemaVersionIsBehind(): void
    {
        global $wpdb;
        $table = SitesTable::tableName();
        SchemaVersion::set(1); // simulate an older install pre-P2.1

        Activation::maybeRunSelfHeal();

        $this->assertGreaterThanOrEqual(
            Activation::SCHEMA_VERSION,
            SchemaVersion::current(),
            'Self-heal should bump SchemaVersion forward when behind.'
        );
        $this->assertSame(
            $table,
            $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)),
            'Self-heal should leave the canonical sites table in place.'
        );
    }

    public function testSelfHealSkipsWhenAlreadyUpToDate(): void
    {
        // SchemaVersion is at current; throttle freshly cleared. maybeRunSelfHeal
        // should set the throttle but make no schema changes (ensureSchema is
        // safe to call but the if-condition gates it).
        $before = SchemaVersion::current();

        Activation::maybeRunSelfHeal();

        $this->assertSame($before, SchemaVersion::current());
        $this->assertNotFalse(
            get_transient('defyn_dashboard_schema_check'),
            'Throttle transient must be set even on no-op runs to prevent stampede.'
        );
    }

    public function testThrottleSuppressesSubsequentCalls(): void
    {
        // First call sets the throttle.
        Activation::maybeRunSelfHeal();
        $this->assertNotFalse(get_transient('defyn_dashboard_schema_check'));

        // Now set SchemaVersion behind — a fresh maybeRunSelfHeal would
        // normally bump it. With throttle held, it should NOT bump.
        SchemaVersion::set(1);
        Activation::maybeRunSelfHeal();

        $this->assertSame(
            1,
            SchemaVersion::current(),
            'Throttle should short-circuit and skip ensureSchema entirely.'
        );
    }

    public function testEnsureSchemaIsIdempotent(): void
    {
        // Calling twice in a row should not throw or corrupt state.
        Activation::ensureSchema();
        Activation::ensureSchema();
        $this->assertSame(Activation::SCHEMA_VERSION, SchemaVersion::current());
    }

    public function testPluginBootRegistersPluginsLoadedHook(): void
    {
        \Defyn\Dashboard\Plugin::instance()->boot();
        $this->assertNotFalse(
            has_action('plugins_loaded', [Activation::class, 'maybeRunSelfHeal']),
            'Plugin::boot() must register the self-heal hook on plugins_loaded.'
        );
    }
}
