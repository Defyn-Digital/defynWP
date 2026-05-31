<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Models;

/**
 * Immutable readonly DTO for one row of wp_defyn_activity_log (F9 Task 1).
 *
 * `details` is the decoded JSON array (or null when the column was null,
 * empty, or contained malformed JSON — same defensive treatment as
 * Site::decodeJsonColumn). `ipAddress` is captured from $_SERVER for
 * REST-originated events; AS-originated events leave it null because
 * background jobs have no request context.
 *
 * toJson() hides user_id AND ip_address — both are operator-only and must
 * never reach the SPA wire.
 */
final class ActivityEvent
{
    /** @param array<string, mixed>|null $details decoded JSON from details column */
    public function __construct(
        public readonly int     $id,
        public readonly ?int    $userId,
        public readonly ?int    $siteId,
        public readonly string  $eventType,
        public readonly ?array  $details,
        public readonly ?string $ipAddress,
        public readonly string  $createdAt,
    ) {}

    /** @param array<string, mixed> $row wpdb result row (all values come back as strings) */
    public static function fromRow(array $row): self
    {
        $details = null;
        if (isset($row['details']) && $row['details'] !== '' && $row['details'] !== null) {
            $decoded = json_decode((string) $row['details'], true);
            $details = is_array($decoded) ? $decoded : null;
        }

        return new self(
            id:        (int) $row['id'],
            userId:    isset($row['user_id']) && $row['user_id'] !== null ? (int) $row['user_id'] : null,
            siteId:    isset($row['site_id']) && $row['site_id'] !== null ? (int) $row['site_id'] : null,
            eventType: (string) $row['event_type'],
            details:   $details,
            ipAddress: isset($row['ip_address']) && $row['ip_address'] !== '' && $row['ip_address'] !== null
                ? (string) $row['ip_address']
                : null,
            createdAt: (string) $row['created_at'],
        );
    }

    /** @return array<string, mixed> shape the SPA receives over the wire */
    public function toJson(): array
    {
        return [
            'id'         => $this->id,
            'site_id'    => $this->siteId,
            'event_type' => $this->eventType,
            'details'    => $this->details,
            'created_at' => $this->createdAt,
            // user_id + ip_address intentionally hidden from SPA (operator-only).
        ];
    }
}
