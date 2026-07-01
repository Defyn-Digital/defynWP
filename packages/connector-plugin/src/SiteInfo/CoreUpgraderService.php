<?php

declare(strict_types=1);

namespace Defyn\Connector\SiteInfo;

/**
 * P2.4 — runs WordPress's Core_Upgrader on the active install.
 *
 * Single-resource: no slug. Reads the `update_core` site transient and
 * dispatches the upgrade through the constructor-injected factory.
 * Caller should have refreshed the transient via wp_version_check() first —
 * never trust the cached transient on a destructive code path.
 *
 * Exception -> controller envelope (see CoreUpdateController):
 *   NoCoreUpdateAvailableException -> 409 core.no_update_available
 *   MajorUpdateBlockedException    -> 409 core.major_update_blocked
 *   CoreUpgradeFailedException     -> 502 core.update_failed
 */
final class CoreUpgraderService
{
    /** @var callable(CapturingUpgraderSkin): object */
    private $upgraderFactory;

    /** @var callable(): void */
    private $transientRefresher;

    /**
     * @param callable(CapturingUpgraderSkin): object|null $upgraderFactory
     * @param callable(): void|null                        $transientRefresher
     */
    public function __construct(
        ?callable $upgraderFactory = null,
        ?callable $transientRefresher = null
    ) {
        $this->upgraderFactory    = $upgraderFactory ?? self::defaultUpgraderFactory();
        $this->transientRefresher = $transientRefresher ?? self::defaultTransientRefresher();
    }

    /**
     * @return array{success: true, previous_version: string, new_version: string, server_time: int}
     */
    public function upgrade(bool $allowMajor = false): array
    {
        if (!function_exists('get_core_updates')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }

        // Refresh the update_core transient BEFORE reading it so the package URL
        // we hand Core_Upgrader is current — never trust the cached transient on
        // a destructive code path. Injectable so tests keep their seeded transient.
        ($this->transientRefresher)();

        $current = (string) get_bloginfo('version');
        $updates = get_core_updates(['available' => true, 'dismissed' => false]);

        $matching = null;
        foreach ((array) $updates as $u) {
            if (isset($u->response) && $u->response === 'upgrade') {
                $matching = $u;
                break;
            }
        }
        if ($matching === null) {
            throw new NoCoreUpdateAvailableException(
                'WordPress reports no core update available.'
            );
        }

        $target = (string) ($matching->current ?? $matching->version ?? '');
        if ($target === '') {
            throw new NoCoreUpdateAvailableException(
                'WordPress upgrade response is missing the target version.'
            );
        }

        if (!self::isMinorUpgrade($current, $target) && !$allowMajor) {
            throw new MajorUpdateBlockedException(esc_html($current), esc_html($target));
        }

        // Do NOT force WordPress's filesystem method. Let WP resolve it exactly
        // as wp-admin / WP-CLI / ManageWP do. Forcing 'direct' (v0.2.4) made WP
        // Engine WORSE: the forced write grinds past the host's ~60s request cap
        // and the process is killed (bare 502). The version-advanced guard below
        // still catches a silent no-op without forcing anything; use
        // GET /upgrade-diagnostics to inspect the host filesystem state.
        $skin     = new CapturingUpgraderSkin();
        $upgrader = ($this->upgraderFactory)($skin);
        $result   = $upgrader->upgrade($matching);

        if ($result === false) {
            $message = $skin->lastErrorMessage() ?? 'Core_Upgrader returned false without a message.';
            throw new CoreUpgradeFailedException(esc_html($message));
        }
        if (is_wp_error($result)) {
            throw new CoreUpgradeFailedException(esc_html((string) $result->get_error_message()));
        }

        // Unlike plugins/themes, core does NOT re-read a file header here — the
        // post-upgrade version comes from the $wp_version global (and get_bloginfo
        // as a fallback). Core_Upgrader has just rewritten wp-includes/version.php
        // on disk, but the running PHP process keeps the OLD $wp_version in memory;
        // wp_version_check() polls wordpress.org and wouldn't change the in-process
        // value either. So there's no stale-header re-read to fix the way there is
        // for plugins/themes.
        //
        // CRITICAL: this is also why core OMITS the "version did not change → throw"
        // guard that plugins/themes use. Because $wp_version stays at the OLD value
        // for the rest of this request even after a genuinely successful core update,
        // an equality guard ($newVersion === $current) would FALSE-POSITIVE on EVERY
        // successful core update and report failure. The force-direct filesystem fix
        // above is the part that actually matters for core's silent-no-op problem.
        //
        // We clear PHP's stat cache anyway (guarded, no-op under test) so any
        // subsequent stat-based read on this request sees fresh disk.
        clearstatcache();

        global $wp_version;
        $newVersion = (string) ($wp_version ?? get_bloginfo('version'));
        if ($newVersion === '') {
            $newVersion = $current;
        }

        return [
            'success'          => true,
            'previous_version' => $current,
            'new_version'      => $newVersion,
            'server_time'      => time(),
        ];
    }

    private static function isMinorUpgrade(string $current, string $target): bool
    {
        [$cMaj, $cMin] = array_pad(array_slice(explode('.', $current), 0, 2), 2, '0');
        [$tMaj, $tMin] = array_pad(array_slice(explode('.', $target), 0, 2), 2, '0');
        return $cMaj === $tMaj && $cMin === $tMin;
    }

    /** @return callable(CapturingUpgraderSkin): object */
    private static function defaultUpgraderFactory(): callable
    {
        return static function (CapturingUpgraderSkin $skin): object {
            // WP_Upgrader::run() -> fs_connect() calls WP_Filesystem(), which is
            // defined in wp-admin/includes/file.php — NOT autoloaded in the REST
            // request context. Without it the upgrade fatals with
            // "Call to undefined function WP_Filesystem()" and the controller
            // returns a bare HTTP 500. Load it (and misc.php for show_message())
            // before constructing the upgrader, exactly like wp-admin does.
            if (!function_exists('WP_Filesystem')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            if (!function_exists('show_message')) {
                require_once ABSPATH . 'wp-admin/includes/misc.php';
            }
            if (!class_exists(\Core_Upgrader::class)) {
                require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
                require_once ABSPATH . 'wp-admin/includes/class-core-upgrader.php';
            }
            return new \Core_Upgrader($skin);
        };
    }

    /** @return callable(): void */
    private static function defaultTransientRefresher(): callable
    {
        return static function (): void {
            if (!function_exists('wp_version_check')) {
                if (defined('ABSPATH')) {
                    require_once ABSPATH . 'wp-admin/includes/update.php';
                }
            }
            if (function_exists('wp_version_check')) {
                wp_version_check([], true);
            }
        };
    }
}
