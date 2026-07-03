<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\ActivityLogger;
use Defyn\Dashboard\Services\BrandingService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P3.3 — team-wide notification settings.
 *
 * The Slack webhook is stored in the shared site option `defyn_slack_webhook_url`
 * (team-wide, not per-user) — NEVER logged (only {cleared: bool} is written to the
 * activity log); writes are host-allowlisted to https://hooks.slack.com/ (SSRF guard,
 * since the webhook is later POSTed to by SlackNotifier).
 *
 * GET  /defyn/v1/settings              → {slack_webhook_url: string|null}
 * POST /defyn/v1/settings/slack-webhook → {slack_webhook_url: string|null}
 */
final class SettingsController
{
    private const OPTION_KEY = 'defyn_slack_webhook_url';

    public function handleGet(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id'); // vestigial — branding is team-wide too
        $url = (string) get_option(self::OPTION_KEY, '');
        return new WP_REST_Response([
            'slack_webhook_url' => $url === '' ? null : $url,
            'report_branding'   => (new BrandingService())->get($userId),
            'connector_release' => $this->getConnectorRelease(),
        ], 200);
    }

    public function handleSet(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id'); // vestigial — webhook is now team-wide
        $body = $request->get_json_params() ?: [];
        $url  = isset($body['webhook_url']) ? trim((string) $body['webhook_url']) : '';

        if ($url !== '' && !preg_match('#^https://hooks\.slack\.com/#', $url)) {
            return ErrorResponse::create(
                400,
                'settings.invalid_webhook',
                'Webhook must be an https://hooks.slack.com/ URL or empty.'
            );
        }

        if ($url === '') {
            delete_option(self::OPTION_KEY);
        } else {
            update_option(self::OPTION_KEY, $url);
        }

        // SECURITY: never log the URL — only record whether it was cleared.
        (new ActivityLogger())->log($userId, null, 'settings.slack_webhook_updated', ['cleared' => $url === '']);

        return new WP_REST_Response(['slack_webhook_url' => $url === '' ? null : $url], 200);
    }

    /**
     * P5.2 — POST /defyn/v1/settings/report-branding.
     *
     * White-label config for branded PDF reports. Unlike the Slack webhook these
     * values are NOT secrets, so they are echoed back and surfaced in GET. The
     * activity log records only {cleared_logo: bool} — never the branding values.
     *
     * Only keys present in the body are written; '' resets that field to default.
     */
    public function handleSetBranding(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $body   = $request->get_json_params() ?: [];

        $partial = [];
        if (array_key_exists('agency_name', $body)) {
            $name = sanitize_text_field((string) $body['agency_name']);
            if (mb_strlen($name) > 100) {
                return ErrorResponse::create(
                    400,
                    'settings.invalid_branding',
                    'agency_name must be 100 characters or fewer.'
                );
            }
            $partial['agency_name'] = $name;
        }
        if (array_key_exists('accent_color', $body)) {
            $accent = trim((string) $body['accent_color']);
            if ($accent !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', $accent) !== 1) {
                return ErrorResponse::create(
                    400,
                    'settings.invalid_branding',
                    'accent_color must be a #RRGGBB hex colour.'
                );
            }
            $partial['accent_color'] = $accent;
        }
        if (array_key_exists('logo_url', $body)) {
            $logo = trim((string) $body['logo_url']);
            if ($logo !== '' && (stripos($logo, 'https://') !== 0 || mb_strlen($logo) > 500)) {
                return ErrorResponse::create(
                    400,
                    'settings.invalid_branding',
                    'logo_url must be an https URL of 500 characters or fewer.'
                );
            }
            $partial['logo_url'] = $logo;
        }

        $svc = new BrandingService();
        $svc->set($userId, $partial);

        // SECURITY: branding values are not secret, but keep the log minimal —
        // record only whether the logo was cleared.
        (new ActivityLogger())->log(
            $userId,
            null,
            'settings.report_branding_updated',
            ['cleared_logo' => array_key_exists('logo_url', $partial) && $partial['logo_url'] === '']
        );

        return new WP_REST_Response(['report_branding' => $svc->get($userId)], 200);
    }

    /** @return array{version:string,package_url:string,sha256:string}|null */
    private function getConnectorRelease(): ?array
    {
        $m = get_option('defyn_connector_release', null);
        if (is_array($m) && isset($m['version'], $m['package_url'], $m['sha256'])) {
            return [
                'version'     => (string) $m['version'],
                'package_url' => (string) $m['package_url'],
                'sha256'      => (string) $m['sha256'],
            ];
        }
        return null;
    }

    /**
     * POST /defyn/v1/settings/connector-release — set (or clear) the connector
     * release the dashboard offers via "Update connector". Sidesteps the
     * server-side GitHub lookup. All-empty body clears it.
     */
    public function handleSetConnectorRelease(WP_REST_Request $request): WP_REST_Response
    {
        $userId  = (int) $request->get_param('_authenticated_user_id');
        $body    = $request->get_json_params() ?: [];
        $version = isset($body['version']) ? trim((string) $body['version']) : '';
        $url     = isset($body['package_url']) ? trim((string) $body['package_url']) : '';
        $sha     = isset($body['sha256']) ? strtolower(trim((string) $body['sha256'])) : '';

        if ($version === '' && $url === '' && $sha === '') {
            delete_option('defyn_connector_release');
            (new ActivityLogger())->log($userId, null, 'settings.connector_release_updated', ['cleared' => true]);
            return new WP_REST_Response(['connector_release' => null], 200);
        }
        if (preg_match('/^\d+\.\d+\.\d+/', $version) !== 1) {
            return ErrorResponse::create(400, 'settings.invalid_connector_release', 'Version must look like 0.3.2.');
        }
        if (stripos($url, 'https://') !== 0) {
            return ErrorResponse::create(400, 'settings.invalid_connector_release', 'Package URL must be an https URL.');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $sha) !== 1) {
            return ErrorResponse::create(400, 'settings.invalid_connector_release', 'SHA-256 must be 64 hex characters.');
        }

        $manifest = ['version' => $version, 'package_url' => $url, 'sha256' => $sha];
        update_option('defyn_connector_release', $manifest);
        (new ActivityLogger())->log($userId, null, 'settings.connector_release_updated', ['version' => $version]);
        return new WP_REST_Response(['connector_release' => $manifest], 200);
    }
}
