<?php

declare(strict_types=1);

namespace Defyn\Connector\Rest;

use Defyn\Connector\Rest\Responses\ErrorResponse;
use Defyn\Connector\SiteInfo\NoUpdateAvailableException;
use Defyn\Connector\SiteInfo\PluginUpgraderService;
use Defyn\Connector\SiteInfo\UnknownSlugException;
use Defyn\Connector\SiteInfo\UpgradeFailedException;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /defyn-connector/v1/plugins/{slug}/update — signed.
 *
 * Acquires a per-site transient lock so two concurrent upgrade requests
 * on the same install serialise (second returns 409). The lock is
 * released in a finally block so a thrown exception still releases it.
 *
 * Maps the three exceptions thrown by PluginUpgraderService (Task 2) to
 * spec-shape error envelopes:
 *   - UnknownSlugException        → 404 plugins.unknown_slug
 *   - NoUpdateAvailableException  → 409 plugins.no_update_available
 *   - UpgradeFailedException      → 502 plugins.update_failed
 *
 * Spec: docs/superpowers/specs/2026-06-05-p2-2-plugin-updates-design.md §3.1, §3.2, §4.3
 */
final class PluginUpdateController
{
    private const LOCK_KEY = 'defyn_connector_upgrade_in_flight';
    private const LOCK_TTL = 600; // 10 min

    public function __construct(
        private readonly PluginUpgraderService $service = new PluginUpgraderService()
    ) {
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $slug = (string) $request->get_param('slug');

        $existingLock = get_transient(self::LOCK_KEY);
        if ($existingLock !== false) {
            return ErrorResponse::create(
                409,
                'plugins.update_in_progress',
                sprintf('Another upgrade is in progress (%s).', (string) $existingLock)
            );
        }

        set_transient(self::LOCK_KEY, $slug, self::LOCK_TTL);

        // Buffer + discard anything Plugin_Upgrader (or any of WP's update
        // machinery) echoes to STDOUT. Our CapturingUpgraderSkin silences the
        // skin's own feedback() / error() output, but WP's L10n, deprecation
        // notices, and filesystem helpers (WP_Filesystem) can still emit text
        // directly. Any stray bytes would prepend/append to the JSON response
        // body and break the dashboard's json_decode — we'd see a successful
        // HTTP 200 with body.success == empty and incorrectly mark the row
        // failed even though the upgrade succeeded on disk.
        ob_start();

        // v0.2.5 — last-resort fatal catcher. A PHP fatal inside the upgrade
        // (host kills the request at its time/wall limit, OOM, or a plugin's
        // own code fatals mid-upgrade) aborts before the catch/finally below
        // can run — the dashboard then sees a bare 502 with no reason. This
        // shutdown hook emits a structured envelope instead. The $completed
        // flag (set in finally on every normal/exception path) makes it a
        // no-op unless a genuine fatal occurred.
        $completed = false;
        register_shutdown_function(static function () use (&$completed): void {
            if ($completed) {
                return;
            }
            $err = error_get_last();
            if ($err === null || !in_array($err['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true)) {
                return;
            }
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            if (!headers_sent()) {
                status_header(502);
                header('Content-Type: application/json; charset=utf-8');
                nocache_headers();
            }
            echo wp_json_encode([
                'error' => [
                    'code'    => 'plugins.update_fatal',
                    'message' => sprintf('%s @ %s:%d', (string) $err['message'], (string) $err['file'], (int) $err['line']),
                ],
            ]);
        });

        try {
            $result = $this->service->upgrade($slug);
            return new WP_REST_Response($result, 200);
        } catch (UnknownSlugException $e) {
            return ErrorResponse::create(
                404,
                'plugins.unknown_slug',
                sprintf('Plugin "%s" is not installed.', $e->getMessage())
            );
        } catch (NoUpdateAvailableException $e) {
            return ErrorResponse::create(
                409,
                'plugins.no_update_available',
                sprintf('No update available for "%s".', $e->getMessage())
            );
        } catch (UpgradeFailedException $e) {
            return ErrorResponse::create(502, 'plugins.update_failed', $e->getMessage());
        } catch (\Throwable $e) {
            // Catch-all so an unexpected fatal (e.g. a WP core function that
            // isn't loaded in the REST request context) surfaces as a
            // diagnosable structured 502 instead of escaping to a bare HTTP 500.
            return ErrorResponse::create(
                502,
                'plugins.update_failed',
                'Unexpected error during upgrade: ' . $e->getMessage()
            );
        } finally {
            // Always discard the buffered output, regardless of success or
            // exception — the buffer must NEVER reach the response writer.
            ob_end_clean();
            delete_transient(self::LOCK_KEY);
            $completed = true;
        }
    }
}
