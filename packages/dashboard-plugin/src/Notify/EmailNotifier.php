<?php
declare(strict_types=1);

namespace Defyn\Dashboard\Notify;

use Defyn\Dashboard\Models\Incident;
use Defyn\Dashboard\Models\Site;
use Throwable;

/**
 * P3.1 — emails the site owner on incident open/close. Best-effort: a wp_mail
 * failure is swallowed (logged) and never propagates into the HealthService
 * ping loop (guardrail 6). Recipient is the OWNER's user email (guardrail 7).
 */
final class EmailNotifier implements Notifier
{
    public function notifyDown(Site $site, Incident $incident): void
    {
        $this->send(
            $site,
            '🔴 ' . $site->label . ' is down',
            "Your site {$site->label} ({$site->url}) appears to be down.\n\n"
            . "Down since: {$incident->startedAt} UTC\n"
            . "Last error: " . ($incident->lastError ?? 'unknown') . "\n"
        );
    }

    public function notifyRecovered(Site $site, Incident $incident): void
    {
        $dur = $incident->durationSeconds !== null ? $this->humanDuration($incident->durationSeconds) : 'unknown';
        $this->send(
            $site,
            '✅ ' . $site->label . ' recovered — down ' . $dur,
            "Your site {$site->label} ({$site->url}) has recovered.\n\n"
            . "Down from {$incident->startedAt} to " . ($incident->endedAt ?? '?') . " UTC ({$dur}).\n"
        );
    }

    private function send(Site $site, string $subject, string $body): void
    {
        $to = $this->ownerEmail($site->userId);
        if ($to === '') {
            return;
        }
        try {
            wp_mail($to, $subject, $body);
        } catch (Throwable $e) {
            error_log('[defyn] EmailNotifier failed: ' . $e->getMessage());
        }
    }

    private function ownerEmail(int $userId): string
    {
        $user = get_userdata($userId);
        return ($user && is_email($user->user_email)) ? (string) $user->user_email : '';
    }

    private function humanDuration(int $seconds): string
    {
        if ($seconds < 60) return $seconds . 's';
        if ($seconds < 3600) return floor($seconds / 60) . 'm';
        return floor($seconds / 3600) . 'h ' . floor(($seconds % 3600) / 60) . 'm';
    }
}
