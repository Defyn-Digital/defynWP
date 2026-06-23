<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Services;

/**
 * Team-wide notification settings (SSO / google-sso-team-access).
 *
 * Migrates legacy per-user notification meta from the original operator (user 1)
 * into shared site options so the whole team's sites emit alerts correctly,
 * regardless of which team member created a site.
 *
 * Fix A — Slack webhook  : `defyn_slack_webhook_url` site option
 *           (read by SlackNotifier::post() and SettingsController)
 * Fix B — Alert email    : `defyn_alert_email` site option
 *           (read by EmailNotifier::alertEmail())
 */
final class NotificationSettingsService
{
    /** One-time: copy legacy per-user notification meta into shared site options. */
    public static function migrateLegacyToShared(): void
    {
        if (get_option('defyn_notify_migrated')) {
            return;
        }

        // Fix A — Slack webhook.
        $legacyWebhook = (string) get_user_meta(1, 'defyn_slack_webhook_url', true);
        if ($legacyWebhook !== '' && get_option('defyn_slack_webhook_url', '') === '') {
            update_option('defyn_slack_webhook_url', $legacyWebhook);
        }

        // Fix B — Alert email: seed from user 1's email (the original operator).
        if (get_option('defyn_alert_email', '') === '') {
            $user = get_userdata(1);
            if ($user && is_email($user->user_email)) {
                update_option('defyn_alert_email', (string) $user->user_email);
            }
        }

        update_option('defyn_notify_migrated', '1');
    }
}
