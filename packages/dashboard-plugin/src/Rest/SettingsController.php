<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Rest;

use Defyn\Dashboard\Rest\Responses\ErrorResponse;
use Defyn\Dashboard\Services\ActivityLogger;
use WP_REST_Request;
use WP_REST_Response;

/**
 * P3.3 — per-operator notification settings.
 *
 * The Slack webhook is stored in user_meta (defyn_slack_webhook_url) — NEVER
 * logged (only {cleared: bool} is written to the activity log); writes are
 * host-allowlisted to https://hooks.slack.com/ (SSRF guard, since the webhook
 * is later POSTed to by SlackNotifier).
 *
 * GET  /defyn/v1/settings              → {slack_webhook_url: string|null}
 * POST /defyn/v1/settings/slack-webhook → {slack_webhook_url: string|null}
 */
final class SettingsController
{
    private const META_KEY = 'defyn_slack_webhook_url';

    public function handleGet(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
        $url = (string) get_user_meta($userId, self::META_KEY, true);
        return new WP_REST_Response(['slack_webhook_url' => $url === '' ? null : $url], 200);
    }

    public function handleSet(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('_authenticated_user_id');
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
            delete_user_meta($userId, self::META_KEY);
        } else {
            update_user_meta($userId, self::META_KEY, $url);
        }

        // SECURITY: never log the URL — only record whether it was cleared.
        (new ActivityLogger())->log($userId, null, 'settings.slack_webhook_updated', ['cleared' => $url === '']);

        return new WP_REST_Response(['slack_webhook_url' => $url === '' ? null : $url], 200);
    }
}
