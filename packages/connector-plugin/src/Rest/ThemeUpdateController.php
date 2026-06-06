<?php

declare(strict_types=1);

namespace Defyn\Connector\Rest;

use Defyn\Connector\Rest\Responses\ErrorResponse;
use Defyn\Connector\SiteInfo\NoThemeUpdateAvailableException;
use Defyn\Connector\SiteInfo\ThemeUpgraderService;
use Defyn\Connector\SiteInfo\ThemeUpgradeFailedException;
use Defyn\Connector\SiteInfo\UnknownThemeSlugException;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /defyn-connector/v1/themes/{slug}/update — signed.
 *
 * Acquires the SHARED defyn_connector_upgrade_in_flight transient lock —
 * the same key the plugin update endpoint uses — so concurrent
 * theme/plugin or theme/theme upgrade requests on the same install
 * serialise. Second hitter returns 409 connector.upgrade_in_progress.
 * WP's WP_Upgrader (and therefore Theme_Upgrader) acquires the same
 * .maintenance lock regardless of resource type, so a coarse-grained
 * application-level lock prevents the failure modes that would otherwise
 * surface as opaque filesystem errors.
 *
 * STDOUT discipline: wrap the service call in ob_start/ob_end_clean
 * inside try/finally. Theme_Upgrader (and any WP filesystem helper it
 * delegates to) can echo HTML directly because it normally runs inside
 * wp-admin. Without the buffer, stray bytes would prepend/append to the
 * JSON response body and break json_decode on the dashboard side — the
 * exact P2.2.1 fix (`7a05d48`), retyped here from day 1.
 *
 * Maps the three exceptions thrown by ThemeUpgraderService to
 * spec-shape error envelopes:
 *   - UnknownThemeSlugException        → 404 themes.unknown_slug
 *   - NoThemeUpdateAvailableException  → 409 themes.no_update_available
 *   - ThemeUpgradeFailedException      → 502 themes.update_failed
 *
 * Spec: docs/superpowers/specs/2026-06-06-p2-3-themes-design.md §3.3, §3.4
 */
final class ThemeUpdateController
{
    /** Shared with PluginUpdateController — covers cross-resource collisions. */
    private const LOCK_KEY = 'defyn_connector_upgrade_in_flight';
    private const LOCK_TTL = 600; // 10 min

    public function __construct(
        private readonly ThemeUpgraderService $service = new ThemeUpgraderService()
    ) {
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $slug = (string) $request->get_param('slug');

        $existingLock = get_transient(self::LOCK_KEY);
        if ($existingLock !== false) {
            return ErrorResponse::create(
                409,
                'connector.upgrade_in_progress',
                sprintf('Another upgrade is in progress (%s).', (string) $existingLock)
            );
        }

        set_transient(self::LOCK_KEY, $slug, self::LOCK_TTL);

        ob_start();

        try {
            $result = $this->service->upgrade($slug);
            return new WP_REST_Response($result, 200);
        } catch (UnknownThemeSlugException $e) {
            return ErrorResponse::create(
                404,
                'themes.unknown_slug',
                sprintf('Theme "%s" is not installed.', $e->getMessage())
            );
        } catch (NoThemeUpdateAvailableException $e) {
            return ErrorResponse::create(
                409,
                'themes.no_update_available',
                sprintf('No update available for "%s".', $e->getMessage())
            );
        } catch (ThemeUpgradeFailedException $e) {
            return ErrorResponse::create(502, 'themes.update_failed', $e->getMessage());
        } finally {
            ob_end_clean();
            delete_transient(self::LOCK_KEY);
        }
    }
}
