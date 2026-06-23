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
}
