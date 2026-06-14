<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Notify;

use Defyn\Dashboard\Models\Incident;
use Defyn\Dashboard\Models\Site;
use Throwable;

/**
 * P3.3 — posts monitoring alerts to the site OWNER's Slack incoming webhook
 * (per-operator user_meta `defyn_slack_webhook_url`). No-op when unset.
 * Best-effort: transport / non-2xx failures are logged, never thrown.
 * Mirrors EmailNotifier's owner-resolution + best-effort shape.
 */
final class SlackNotifier implements Notifier
{
    public function notifyDown(Site $site, Incident $incident): void
    {
        $this->post($site, '🔴 *' . $site->label . '* is down — ' . $site->url
            . "\nDown since {$incident->startedAt} UTC. Error: " . ($incident->lastError ?? 'unknown'));
    }

    public function notifyRecovered(Site $site, Incident $incident): void
    {
        $this->post($site, '✅ *' . $site->label . '* recovered — ' . $site->url
            . "\nDown from {$incident->startedAt} to " . ($incident->endedAt ?? '?') . ' UTC.');
    }

    public function notifySslExpiring(Site $site, string $expiresAtUtc, int $daysLeft): void
    {
        $this->post($site, '⚠️ *' . $site->label . "* SSL expires in {$daysLeft} day"
            . ($daysLeft === 1 ? '' : 's') . ' — ' . $site->url . "\nExpires {$expiresAtUtc} UTC.");
    }

    private function post(Site $site, string $text): void
    {
        $webhook = (string) get_user_meta($site->userId, 'defyn_slack_webhook_url', true);
        if ($webhook === '') {
            return;
        }
        try {
            $res = wp_remote_post($webhook, [
                'timeout' => 5,
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => wp_json_encode(['text' => $text]),
            ]);
            if (is_wp_error($res)) {
                error_log('[defyn] SlackNotifier failed: ' . $res->get_error_message());
                return;
            }
            $code = (int) wp_remote_retrieve_response_code($res);
            if ($code < 200 || $code >= 300) {
                error_log('[defyn] SlackNotifier non-2xx: ' . $code);
            }
        } catch (Throwable $e) {
            error_log('[defyn] SlackNotifier threw: ' . $e->getMessage());
        }
    }
}
