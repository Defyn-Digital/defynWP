<?php
declare(strict_types=1);
namespace Defyn\Dashboard\Models;

/**
 * Read-only DTO for a row of wp_defyn_reports.
 *
 * toJson() intentionally omits file_name — the stored PDF's random
 * filename is server-only and must never reach the SPA wire.
 */
final class Report
{
    public function __construct(
        public readonly int     $id,
        public readonly int     $siteId,
        public readonly string  $title,
        public readonly string  $rangeFrom,
        public readonly string  $rangeTo,
        public readonly string  $status,
        public readonly ?string $fileName,
        public readonly ?int    $fileSize,
        public readonly ?string $recipientEmail,
        public readonly ?string $errorMessage,
        public readonly ?string $generatedAt,
        public readonly ?string $sentAt,
        public readonly string  $createdAt,
    ) {}

    /** @param array<string, mixed> $row wpdb result row (all values come back as strings) */
    public static function fromRow(array $row): self
    {
        return new self(
            id:             (int) $row['id'],
            siteId:         (int) $row['site_id'],
            title:          (string) $row['title'],
            rangeFrom:      (string) $row['range_from'],
            rangeTo:        (string) $row['range_to'],
            status:         (string) $row['status'],
            fileName:       isset($row['file_name'])       && $row['file_name']       !== null ? (string) $row['file_name']       : null,
            fileSize:       isset($row['file_size'])       && $row['file_size']       !== null ? (int)    $row['file_size']       : null,
            recipientEmail: isset($row['recipient_email']) && $row['recipient_email'] !== null ? (string) $row['recipient_email'] : null,
            errorMessage:   isset($row['error_message'])   && $row['error_message']   !== null ? (string) $row['error_message']   : null,
            generatedAt:    isset($row['generated_at'])    && $row['generated_at']    !== null ? (string) $row['generated_at']    : null,
            sentAt:         isset($row['sent_at'])         && $row['sent_at']         !== null ? (string) $row['sent_at']         : null,
            createdAt:      (string) $row['created_at'],
        );
    }

    /**
     * @return array<string, mixed> shape the SPA receives over the wire.
     *
     * file_name is server-only and deliberately omitted.
     */
    public function toJson(): array
    {
        return [
            'id'              => $this->id,
            'site_id'         => $this->siteId,
            'title'           => $this->title,
            'range_from'      => $this->rangeFrom,
            'range_to'        => $this->rangeTo,
            'status'          => $this->status,
            'file_size'       => $this->fileSize,
            'recipient_email' => $this->recipientEmail,
            'generated_at'    => $this->generatedAt,
            'sent_at'         => $this->sentAt,
            'created_at'      => $this->createdAt,
        ];
    }
}
