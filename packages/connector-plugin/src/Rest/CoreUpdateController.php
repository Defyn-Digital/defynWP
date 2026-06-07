<?php

declare(strict_types=1);

namespace Defyn\Connector\Rest;

use Defyn\Connector\Rest\Responses\ErrorResponse;
use Defyn\Connector\SiteInfo\CoreUpgradeFailedException;
use Defyn\Connector\SiteInfo\CoreUpgraderService;
use Defyn\Connector\SiteInfo\MajorUpdateBlockedException;
use Defyn\Connector\SiteInfo\NoCoreUpdateAvailableException;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /defyn-connector/v1/core/update — signed.
 *
 * Single resource — no slug. Acquires the SHARED
 * defyn_connector_upgrade_in_flight transient lock (same key as plugin +
 * theme update endpoints) so concurrent plugin/theme/core upgrade requests
 * on the same install serialise. Second hitter returns 409
 * connector.upgrade_in_progress.
 *
 * STDOUT discipline (P2.2.1 carry-over from day 1): wrap the service call
 * in ob_start/ob_end_clean inside try/finally.
 *
 * Spec: docs/superpowers/specs/2026-06-07-p2-4-core-updates-design.md §3.3, §3.4
 */
final class CoreUpdateController
{
    /** Shared with PluginUpdateController + ThemeUpdateController. */
    private const LOCK_KEY = 'defyn_connector_upgrade_in_flight';
    private const LOCK_TTL = 600; // 10 min

    public function __construct(
        private readonly CoreUpgraderService $service = new CoreUpgraderService()
    ) {
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $existingLock = get_transient(self::LOCK_KEY);
        if ($existingLock !== false) {
            return ErrorResponse::create(
                409,
                'connector.upgrade_in_progress',
                sprintf('Another upgrade is in progress (%s).', (string) $existingLock)
            );
        }

        set_transient(self::LOCK_KEY, 'core', self::LOCK_TTL);

        ob_start();

        try {
            $body       = $request->get_json_params() ?: [];
            $allowMajor = isset($body['allow_major']) && $body['allow_major'] === true;
            $result     = $this->service->upgrade($allowMajor);
            return new WP_REST_Response($result, 200);
        } catch (NoCoreUpdateAvailableException $e) {
            return ErrorResponse::create(
                409,
                'core.no_update_available',
                $e->getMessage(),
            );
        } catch (MajorUpdateBlockedException $e) {
            return ErrorResponse::create(
                409,
                'core.major_update_blocked',
                $e->getMessage(),
            );
        } catch (CoreUpgradeFailedException $e) {
            return ErrorResponse::create(502, 'core.update_failed', $e->getMessage());
        } finally {
            ob_end_clean();
            delete_transient(self::LOCK_KEY);
        }
    }
}
