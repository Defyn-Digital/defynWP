<?php

declare(strict_types=1);

namespace Defyn\Dashboard;

use Defyn\Dashboard\Jobs\CleanupExpiredCodes;
use Defyn\Dashboard\Jobs\CompleteConnection;
use Defyn\Dashboard\Jobs\HealthPing;
use Defyn\Dashboard\Jobs\HealthPingAll;
use Defyn\Dashboard\Jobs\RefreshSitePlugins;
use Defyn\Dashboard\Jobs\RefreshSiteThemes;
use Defyn\Dashboard\Jobs\Scheduler;
use Defyn\Dashboard\Jobs\SyncAllSites;
use Defyn\Dashboard\Jobs\SyncSite;
use Defyn\Dashboard\Jobs\UpdateSitePlugin;
use Defyn\Dashboard\Jobs\UpdateSiteTheme;
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

        // P2.2.1 — schema self-heal on every request, throttled to once per hour.
        // Recovers transparently when "Replace current with uploaded version" upgrades
        // accidentally fire the Uninstaller or fail to re-fire register_activation_hook.
        // Eliminates the manual deact+react step from the upgrade runbook.
        add_action('plugins_loaded', [Activation::class, 'maybeRunSelfHeal']);

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

        // P2.1 — operator-triggered plugin inventory refresh. Scheduled by
        // SitesPluginsRefreshController; handler hits connector /plugins/refresh
        // then delta-syncs via SyncPluginsService.
        add_action('defyn_refresh_site_plugins', static function (int $siteId): void {
            (new RefreshSitePlugins())->handle($siteId);
        }, 10, 1);

        // P2.2 — operator-triggered plugin update. Scheduled by
        // SitesPluginsUpdateController; handler calls connector /plugins/{slug}/update
        // with a 120s HTTP timeout, branches on success / 409 retry / failure.
        add_action(UpdateSitePlugin::HOOK, static function (int $siteId, string $slug, int $attempt = 0): void {
            (new UpdateSitePlugin())->handle($siteId, $slug, $attempt);
        }, 10, 3);

        // P2.3 — operator-triggered theme inventory refresh. Scheduled by
        // SitesThemesRefreshController; handler hits connector /themes/refresh
        // then delta-syncs via SyncThemesService.
        add_action(RefreshSiteThemes::HOOK, static function (int $siteId): void {
            (new RefreshSiteThemes())->handle($siteId);
        }, 10, 1);

        // P2.3 — operator-triggered theme update.
        add_action(UpdateSiteTheme::HOOK, static function (int $siteId, string $slug, int $attempt = 0): void {
            (new UpdateSiteTheme())->handle($siteId, $slug, $attempt);
        }, 10, 3);

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

        add_action('admin_menu', static function (): void {
            // Hide Action Scheduler's auto-registered Tools → Scheduled Actions submenu.
            // The AS admin UI exposes pending/failed job arguments (site_id, code, url)
            // which is an unnecessary info-leak surface in DefynWP context. Foundation
            // policy: hide entirely. Post-foundation, a dedicated operator role can
            // selectively re-enable it.
            remove_submenu_page('tools.php', 'action-scheduler');
        }, 999);
    }
}
