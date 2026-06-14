<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

use Defyn\Dashboard\Models\Incident;
use Defyn\Dashboard\Models\Site;
use Defyn\Dashboard\Notify\MultiNotifier;
use Defyn\Dashboard\Notify\Notifier;
use Throwable;

/**
 * P3.1 Task 6 — confirm-down state machine.
 *
 * Ties together SitesRepository (failure counter), IncidentsRepository
 * (open/close lifecycle), Notifier (email alerts), and ActivityLogger
 * (audit trail). Called from HealthService fail/success branches (Task 7).
 *
 * Guardrail summary:
 *   1  — open incident only on the 2nd consecutive failure (CONFIRM_THRESHOLD = 2)
 *   2  — stamp down_alert_sent_at / up_alert_sent_at exactly once per edge
 *   4  — recordSuccess always resets the counter, regardless of incident state
 *   5  — never open a second incident while one is already open
 *   6  — notifier throws are swallowed in safeNotify (never propagate)
 *   9  — all timestamps via gmdate(); duration_seconds computed at close only
 */
final class IncidentService
{
    private const CONFIRM_THRESHOLD = 2;   // guardrail 1

    public function __construct(
        private readonly ?IncidentsRepository $incidents = null,
        private readonly ?SitesRepository $sites = null,
        private readonly ?Notifier $notifier = null,
        private readonly ?ActivityLogger $logger = null,
    ) {}

    public function recordFailure(Site $site, string $message): void
    {
        $incidents = $this->incidents ?? new IncidentsRepository();
        $sites     = $this->sites     ?? new SitesRepository();
        $logger    = $this->logger    ?? new ActivityLogger();
        $notifier  = $this->notifier  ?? new MultiNotifier();

        $count = $sites->incrementConsecutiveFailures($site->id);
        if ($count < self::CONFIRM_THRESHOLD) {
            return;                                             // guardrail 1
        }
        if ($incidents->findOpenForSite($site->id) !== null) {
            return;                                             // guardrail 5
        }

        $now = gmdate('Y-m-d H:i:s');                           // guardrail 9
        $id  = $incidents->open($site->id, $now, $message);
        $incident = new Incident($id, $site->id, $now, null, null, $message, null, null, $now);

        if (!$site->alertsMuted && $this->safeNotify(static fn () => $notifier->notifyDown($site, $incident))) {  // guardrail 6 + P3.3 mute gate
            $incidents->markDownAlertSent($id, gmdate('Y-m-d H:i:s'));                    // guardrail 2
        }
        $logger->log($site->userId, $site->id, 'site.incident_opened', [
            'incident_id' => $id, 'started_at' => $now, 'error' => $message,
        ]);
    }

    public function recordSuccess(Site $site): void
    {
        $incidents = $this->incidents ?? new IncidentsRepository();
        $sites     = $this->sites     ?? new SitesRepository();
        $logger    = $this->logger    ?? new ActivityLogger();
        $notifier  = $this->notifier  ?? new MultiNotifier();

        $open = $incidents->findOpenForSite($site->id);
        if ($open !== null) {
            $endedAt  = gmdate('Y-m-d H:i:s');                                       // guardrail 9
            $duration = max(0, strtotime($endedAt . ' UTC') - strtotime($open->startedAt . ' UTC'));
            $incidents->close($open->id, $endedAt, $duration);
            $closed = new Incident(
                $open->id,
                $site->id,
                $open->startedAt,
                $endedAt,
                $duration,
                $open->lastError,
                $open->downAlertSentAt,
                null,
                $open->createdAt
            );
            if (!$site->alertsMuted && $this->safeNotify(static fn () => $notifier->notifyRecovered($site, $closed))) {  // guardrail 6 + P3.3 mute gate
                $incidents->markUpAlertSent($open->id, gmdate('Y-m-d H:i:s'));                    // guardrail 2
            }
            $logger->log($site->userId, $site->id, 'site.incident_closed', [
                'incident_id' => $open->id, 'duration_seconds' => $duration,
            ]);
        }

        $sites->resetConsecutiveFailures($site->id);                                // guardrail 4 — always
    }

    private function safeNotify(callable $fn): bool
    {
        try { $fn(); return true; } catch (Throwable $e) { error_log('[defyn] notify failed: ' . $e->getMessage()); return false; }
    }
}
