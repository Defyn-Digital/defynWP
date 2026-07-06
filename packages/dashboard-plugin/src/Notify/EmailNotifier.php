<?php
declare(strict_types=1);

namespace Defyn\Dashboard\Notify;

use Defyn\Dashboard\Models\Incident;
use Defyn\Dashboard\Models\Site;
use Throwable;

/**
 * P3.1 — emails the team-wide alert address on incident open/close. Best-effort:
 * a wp_mail failure is swallowed (logged) and never propagates into the HealthService
 * ping loop. Recipient is the shared `defyn_alert_email` option; falls back to
 * `admin_email` (WP built-in) when the option is empty.
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

    public function notifySslExpiring(Site $site, string $expiresAtUtc, int $daysLeft): void
    {
        $this->send(
            $site,
            '⚠️ ' . $site->label . ' SSL expires in ' . $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's'),
            "The SSL certificate for {$site->label} ({$site->url}) expires on {$expiresAtUtc} UTC "
            . "({$daysLeft} day" . ($daysLeft === 1 ? '' : 's') . " from now).\n\nRenew it before it lapses.\n"
        );
    }

    public function notifyNewVulnerabilities(Site $site, array $newVulnerabilities, array $severityCounts): void
    {
        $count   = count($newVulnerabilities);
        $noun    = $count === 1 ? 'vulnerability' : 'vulnerabilities';
        $subject = '🔒 ' . $count . ' new ' . $noun . ' on ' . $site->label;

        $summary = [];
        foreach (['critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'] as $k => $label) {
            if (($severityCounts[$k] ?? 0) > 0) {
                $summary[] = $label . ': ' . (int) $severityCounts[$k];
            }
        }

        $body = $count . ' new security ' . ($count === 1 ? 'finding' : 'findings')
              . " on {$site->label} ({$site->url}).\n\n";
        if ($summary !== []) {
            $body .= implode(' · ', $summary) . "\n\n";
        }
        foreach ($newVulnerabilities as $v) {
            $line = '[' . $v['severity'] . '] ' . $v['component_name'] . ' (' . $v['type'] . ') ' . $v['installed_version'];
            if (!empty($v['fixed_in'])) {
                $line .= ' → fix ' . $v['fixed_in'];
            }
            if (!empty($v['cve'])) {
                $line .= ' · ' . $v['cve'];
            }
            $body .= $line . "\n";
        }

        $this->send($site, $subject, $body);
    }

    public function notifyPerformanceRegression(Site $site, array $drops): void
    {
        $worst = 0;
        foreach ($drops as $d) {
            $worst = max($worst, (int) $d['previous'] - (int) $d['new']);
        }
        $subject = 'Performance dropped ' . $worst . ' point' . ($worst === 1 ? '' : 's') . ' on ' . $site->label;

        $body = "The PageSpeed performance score for {$site->label} ({$site->url}) dropped versus the previous scan:\n\n";
        foreach ($drops as $d) {
            $body .= ucfirst((string) $d['strategy']) . ': ' . (int) $d['previous'] . ' → ' . (int) $d['new']
                  . ' (−' . ((int) $d['previous'] - (int) $d['new']) . ")\n";
        }
        $body .= "\nWorth a look — a slower site can hurt conversions and search ranking.\n";

        $this->send($site, $subject, $body);
    }

    private function send(Site $site, string $subject, string $body): void
    {
        $to = $this->alertEmail();
        if ($to === '') {
            return;
        }
        try {
            wp_mail($to, $subject, $body);
        } catch (Throwable $e) {
            error_log('[defyn] EmailNotifier failed: ' . $e->getMessage());
        }
    }

    /**
     * Resolves the team-wide monitoring alert recipient.
     * Reads the shared `defyn_alert_email` option; falls back to the WP
     * `admin_email` option (the site admin — a sane team default) when unset.
     */
    private function alertEmail(): string
    {
        $email = (string) get_option('defyn_alert_email', '');
        if ($email === '') {
            $email = (string) get_option('admin_email', '');
        }
        return is_email($email) ? $email : '';
    }

    private function humanDuration(int $seconds): string
    {
        if ($seconds < 60) return $seconds . 's';
        if ($seconds < 3600) return floor($seconds / 60) . 'm';
        return floor($seconds / 3600) . 'h ' . floor(($seconds % 3600) / 60) . 'm';
    }
}
