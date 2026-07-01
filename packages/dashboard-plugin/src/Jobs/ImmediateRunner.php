<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Jobs;

/**
 * Force Action Scheduler to process its queue on THIS request's shutdown,
 * instead of waiting for the next WP-Cron / system-cron tick or Action
 * Scheduler's async-loopback runner (which self-throttles behind a 60s lock).
 *
 * WHY THIS EXISTS
 * ----------------
 * User-triggered updates/refreshes are enqueued with as_enqueue_async_action.
 * Measured on the live fleet (Kinsta), the connector-side install is only
 * ~3-5s, but the gap between `*.requested` and `*.started` — i.e. the time the
 * action sat in the queue before a runner picked it up — was 60-400s. That
 * queue-pickup latency (not the install) was the entire perceived slowness.
 *
 * WHAT IT DOES
 * ------------
 * On `shutdown` (after the REST response is already composed) we:
 *   1. fastcgi_finish_request() — flush the 202 to the browser and close the
 *      connection, so the queue run below is invisible to the caller and never
 *      blocks it. Only proceed if this exists (PHP-FPM, e.g. Kinsta); on any
 *      other SAPI we skip and fall back to the async-enqueue + cron path that
 *      is already in place (no regression, and crucially no blocking of the
 *      client request).
 *   2. do_action('action_scheduler_run_queue', ...) — run one AS batch now,
 *      in this same worker, so the just-enqueued action starts within ~1s.
 *
 * SAFETY
 * ------
 * Action Scheduler claims each action atomically in its store before running
 * it, so running a batch here cannot double-execute a job even if the recurring
 * cron runner fires concurrently — the loser of the claim simply skips it. This
 * is the same execution model AS's own async loopback uses; we are only making
 * the dispatch happen immediately and without the 60s convenience lock.
 */
final class ImmediateRunner
{
    private static bool $registered = false;

    /**
     * Idempotent per request: registers the shutdown kick at most once even if
     * several actions are enqueued in the same request (e.g. a bulk update).
     */
    public static function kickOnShutdown(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        if (!function_exists('add_action')) {
            return;
        }

        \add_action('shutdown', static function (): void {
            // No safe way to run the queue without blocking the client unless
            // we can flush + close the connection first. If we can't, do
            // nothing and let the async-enqueue + cron fallback handle it.
            if (!function_exists('fastcgi_finish_request')) {
                return;
            }
            @\fastcgi_finish_request();

            if (!function_exists('as_has_scheduled_action') && !\did_action('action_scheduler_init')) {
                return;
            }

            // Run one Action Scheduler batch now. QueueRunner::run() claims
            // actions atomically, so this is safe against the concurrent
            // recurring runner (each action executes exactly once).
            \do_action('action_scheduler_run_queue', 'Defyn immediate');
        }, 0);
    }
}
