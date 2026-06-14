<?php
declare(strict_types=1);

namespace Defyn\Dashboard\Models;

final class Incident
{
    public function __construct(
        public readonly int $id,
        public readonly int $siteId,
        public readonly string $startedAt,
        public readonly ?string $endedAt,
        public readonly ?int $durationSeconds,
        public readonly ?string $lastError,
        public readonly ?string $downAlertSentAt,
        public readonly ?string $upAlertSentAt,
        public readonly string $createdAt,
    ) {}

    /** @param array<string,mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            siteId: (int) $row['site_id'],
            startedAt: (string) $row['started_at'],
            endedAt: isset($row['ended_at']) ? (string) $row['ended_at'] : null,
            durationSeconds: isset($row['duration_seconds']) ? (int) $row['duration_seconds'] : null,
            lastError: isset($row['last_error']) ? (string) $row['last_error'] : null,
            downAlertSentAt: isset($row['down_alert_sent_at']) ? (string) $row['down_alert_sent_at'] : null,
            upAlertSentAt: isset($row['up_alert_sent_at']) ? (string) $row['up_alert_sent_at'] : null,
            createdAt: (string) $row['created_at'],
        );
    }

    /** @return array<string,mixed> */
    public function toJson(): array
    {
        return [
            'id' => $this->id,
            'site_id' => $this->siteId,
            'started_at' => $this->startedAt,
            'ended_at' => $this->endedAt,
            'duration_seconds' => $this->durationSeconds,
            'last_error' => $this->lastError,
            'created_at' => $this->createdAt,
        ];
    }
}
