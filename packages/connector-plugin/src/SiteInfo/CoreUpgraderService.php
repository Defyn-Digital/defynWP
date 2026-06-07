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

    /**
     * @param callable(CapturingUpgraderSkin): object|null $upgraderFactory
     */
    public function __construct(?callable $upgraderFactory = null)
    {
        $this->upgraderFactory = $upgraderFactory ?? self::defaultUpgraderFactory();
    }

    /**
     * @return array{success: true, previous_version: string, new_version: string, server_time: int}
     */
    public function upgrade(): array
    {
        if (!function_exists('get_core_updates')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }

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

        if (!self::isMinorUpgrade($current, $target)) {
            throw new MajorUpdateBlockedException($current, $target);
        }

        $skin     = new CapturingUpgraderSkin();
        $upgrader = ($this->upgraderFactory)($skin);
        $result   = $upgrader->upgrade($matching);

        if ($result === false) {
            $message = $skin->lastErrorMessage() ?? 'Core_Upgrader returned false without a message.';
            throw new CoreUpgradeFailedException($message);
        }
        if (is_wp_error($result)) {
            throw new CoreUpgradeFailedException((string) $result->get_error_message());
        }

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
            if (!class_exists(\Core_Upgrader::class)) {
                require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
                require_once ABSPATH . 'wp-admin/includes/class-core-upgrader.php';
            }
            return new \Core_Upgrader($skin);
        };
    }
}
