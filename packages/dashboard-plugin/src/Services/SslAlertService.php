<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Notify\MultiNotifier;
use Defyn\Dashboard\Notify\Notifier;

/**
 * P3.3 — proactive SSL-expiry alert. Fires ONCE when a cert first drops under
 * THRESHOLD_DAYS (ssl_alert_sent_at guard); clears the stamp on renewal so the
 * next expiry can re-alert. Muted sites still get the stamp but skip the send.
 * Called per-site by the Jobs\SslCheck AS job.
 */
final class SslAlertService
{
    public const THRESHOLD_DAYS = 14;

    public function __construct(
        private readonly ?Notifier $notifier = null,
        private readonly ?SitesRepository $sites = null,
        private readonly ?ActivityLogger $logger = null,
    ) {}

    public function evaluate(int $siteId): void
    {
        $sites = $this->sites ?? new SitesRepository();
        $site  = $sites->findById($siteId);
        if ($site === null) {
            return;
        }

        $now = time();
        $expires = $site->sslExpiresAt !== null ? strtotime($site->sslExpiresAt . ' UTC') : null;
        $withinThreshold = $expires !== null && $expires <= $now + self::THRESHOLD_DAYS * DAY_IN_SECONDS;

        if (!$withinThreshold) {
            // Renewed or no cert — reset the de-dup stamp so a future expiry re-alerts.
            if ($site->sslAlertSentAt !== null) {
                $sites->clearSslAlertSent($siteId);
            }
            return;
        }

        if ($site->sslAlertSentAt !== null) {
            return; // already alerted this expiry episode (idempotent)
        }

        $daysLeft = max(0, (int) ceil(($expires - $now) / DAY_IN_SECONDS));

        if (!$site->alertsMuted) {
            ($this->notifier ?? new MultiNotifier())->notifySslExpiring($site, $site->sslExpiresAt, $daysLeft);
        }

        $sites->markSslAlertSent($siteId, gmdate('Y-m-d H:i:s', $now));
        ($this->logger ?? new ActivityLogger())->log($site->userId, $siteId, 'site.ssl_expiring', [
            'expires_at' => $site->sslExpiresAt, 'days_left' => $daysLeft,
        ]);
    }
}
