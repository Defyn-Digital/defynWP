<?php

declare(strict_types=1);

namespace Defyn\Connector\Rest;

use Defyn\Connector\Rest\Responses\ErrorResponse;
use Defyn\Connector\SiteInfo\SelfUpdateService;
use Defyn\Connector\SiteInfo\UpgradeFailedException;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /defyn-connector/v1/self-update — signed.
 *
 * Upgrades the connector plugin itself to a dashboard-authorised release.
 * Body: { target_version, package_url, package_sha256 }.
 *
 * Same hardening as PluginUpdateController: a per-site transient lock so two
 * concurrent self-updates serialise (409), an output buffer so nothing WP's
 * upgrader echoes corrupts the JSON body, a register_shutdown_function fatal
 * catcher so a hard PHP kill still returns a structured envelope, and raised
 * execution limits for a slow download+install.
 *
 * See 2026-07-01-connector-self-update-design.md.
 */
final class SelfUpdateController
{
    private const LOCK_KEY = 'defyn_connector_selfupdate_in_flight';
    private const LOCK_TTL = 600; // 10 min

    public function __construct(
        private readonly SelfUpdateService $service = new SelfUpdateService()
    ) {
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(600);
        }
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }

        $targetVersion = (string) $request->get_param('target_version');
        $packageUrl    = (string) $request->get_param('package_url');
        $packageSha256 = (string) $request->get_param('package_sha256');

        if ($targetVersion === '' || $packageUrl === '' || $packageSha256 === '') {
            return ErrorResponse::create(
                400,
                'connector.self_update_bad_request',
                'target_version, package_url and package_sha256 are all required.'
            );
        }

        $existingLock = get_transient(self::LOCK_KEY);
        if ($existingLock !== false) {
            return ErrorResponse::create(
                409,
                'connector.self_update_in_progress',
                sprintf('A self-update is already in progress (target %s).', (string) $existingLock)
            );
        }
        set_transient(self::LOCK_KEY, $targetVersion, self::LOCK_TTL);

        ob_start();

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
                    'code'    => 'connector.self_update_fatal',
                    'message' => sprintf('%s @ %s:%d', (string) $err['message'], (string) $err['file'], (int) $err['line']),
                ],
            ]);
        });

        try {
            $result = $this->service->update($targetVersion, $packageUrl, $packageSha256);
            return new WP_REST_Response($result, 200);
        } catch (UpgradeFailedException $e) {
            return ErrorResponse::create(502, 'connector.self_update_failed', $e->getMessage());
        } catch (\Throwable $e) {
            return ErrorResponse::create(
                502,
                'connector.self_update_failed',
                'Unexpected error during self-update: ' . $e->getMessage()
            );
        } finally {
            ob_end_clean();
            delete_transient(self::LOCK_KEY);
            $completed = true;
        }
    }
}
