<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Notify;

use Defyn\Dashboard\Models\Incident;
use Defyn\Dashboard\Models\Site;
use Throwable;

/**
 * P3.3 — fans out each alert to every inner notifier (email always + Slack).
 * Each call is isolated: one channel throwing never blocks the others
 * (defence in depth; individual notifiers are already best-effort).
 */
final class MultiNotifier implements Notifier
{
    /** @var list<Notifier> */
    private array $notifiers;

    /** @param list<Notifier>|null $notifiers */
    public function __construct(?array $notifiers = null)
    {
        $this->notifiers = $notifiers ?? [new EmailNotifier(), new SlackNotifier()];
    }

    public function notifyDown(Site $site, Incident $incident): void
    {
        $this->each(static fn (Notifier $n) => $n->notifyDown($site, $incident));
    }

    public function notifyRecovered(Site $site, Incident $incident): void
    {
        $this->each(static fn (Notifier $n) => $n->notifyRecovered($site, $incident));
    }

    public function notifySslExpiring(Site $site, string $expiresAtUtc, int $daysLeft): void
    {
        $this->each(static fn (Notifier $n) => $n->notifySslExpiring($site, $expiresAtUtc, $daysLeft));
    }

    private function each(callable $fn): void
    {
        foreach ($this->notifiers as $n) {
            try {
                $fn($n);
            } catch (Throwable $e) {
                error_log('[defyn] MultiNotifier channel failed: ' . $e->getMessage());
            }
        }
    }
}
