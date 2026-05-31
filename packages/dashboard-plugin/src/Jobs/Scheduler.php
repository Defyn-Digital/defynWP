<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Jobs;

/**
 * Install / uninstall the recurring AS schedules for F7 fan-out + cleanup
 * jobs. Single source of truth for cadences — spec § 6.3.
 *
 * Activation hook calls install; deactivation hook calls uninstall.
 * install is idempotent: existing schedules are unscheduled first to prevent
 * duplicate recurring rows on repeated activation.
 */
final class Scheduler
{
    private const SCHEDULES = [
        SyncAllSites::HOOK         => 1800,  // 30 minutes
        HealthPingAll::HOOK        => 300,   // 5 minutes
        CleanupExpiredCodes::HOOK  => 3600,  // 1 hour
    ];

    public static function installRecurringSchedules(): void
    {
        if (!function_exists('as_schedule_recurring_action')) {
            return;
        }
        self::uninstallRecurringSchedules();  // idempotency
        foreach (self::SCHEDULES as $hook => $intervalSeconds) {
            as_schedule_recurring_action(time(), $intervalSeconds, $hook, [], 'defyn');
        }
    }

    public static function uninstallRecurringSchedules(): void
    {
        if (!function_exists('as_unschedule_all_actions')) {
            return;
        }
        foreach (array_keys(self::SCHEDULES) as $hook) {
            as_unschedule_all_actions($hook, null, 'defyn');
        }
    }
}
