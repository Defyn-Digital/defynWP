<?php

declare(strict_types=1);

namespace Defyn\Dashboard;

use Defyn\Dashboard\Jobs\CleanupExpiredCodes;
use Defyn\Dashboard\Jobs\CompleteConnection;
use Defyn\Dashboard\Jobs\HealthPing;
use Defyn\Dashboard\Jobs\HealthPingAll;
use Defyn\Dashboard\Jobs\Scheduler;
use Defyn\Dashboard\Jobs\SyncAllSites;
use Defyn\Dashboard\Jobs\SyncSite;
use Defyn\Dashboard\Rest\RestRouter;

/**
 * Singleton bootstrap. Wires up activation hooks now;
 * additional services (REST controllers, Action Scheduler jobs, etc.) added in later F-phases.
 */
final class Plugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function boot(): void
    {
        register_activation_hook(DEFYN_DASHBOARD_FILE, [Activation::class, 'activate']);
        register_deactivation_hook(DEFYN_DASHBOARD_FILE, [Scheduler::class, 'uninstallRecurringSchedules']);

        add_action('rest_api_init', static function (): void {
            (new RestRouter())->register();
        });

        add_action('defyn_complete_connection', [CompleteConnection::class, 'handle'], 10, 3);

        add_action(SyncSite::HOOK, static function (int $siteId): void {
            (new SyncSite())->handle($siteId);
        }, 10, 1);

        add_action(HealthPing::HOOK, static function (int $siteId): void {
            (new HealthPing())->handle($siteId);
        }, 10, 1);

        // F7 — fan-out + cleanup master jobs. Master jobs take no args
        // (accepted_args=0); they enumerate sites/codes internally and
        // dispatch per-row leaf jobs (which use accepted_args=1 above).
        add_action(SyncAllSites::HOOK, static function (): void {
            (new SyncAllSites())->handle();
        }, 10, 0);

        add_action(HealthPingAll::HOOK, static function (): void {
            (new HealthPingAll())->handle();
        }, 10, 0);

        add_action(CleanupExpiredCodes::HOOK, static function (): void {
            (new CleanupExpiredCodes())->handle();
        }, 10, 0);
    }
}
