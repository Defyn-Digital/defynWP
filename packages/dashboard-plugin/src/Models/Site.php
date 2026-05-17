<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Models;

/**
 * Read-only DTO for a row of wp_defyn_sites. Does not expose user_id,
 * our_public_key, our_private_key, or any of the cached info columns
 * (wp_version, plugin_counts, etc.) in toJson — F5 doesn't need them
 * over the wire; F6+ will add them as the sync layer fills the columns.
 */
final class Site
{
    public function __construct(
        public readonly int     $id,
        public readonly int     $userId,
        public readonly string  $url,
        public readonly string  $label,
        public readonly string  $status,
        public readonly ?string $sitePublicKey,
        public readonly ?string $ourPublicKey,
        public readonly ?string $lastContactAt,
        public readonly ?string $lastError,
        public readonly string  $createdAt,
    ) {}

    /** @param array<string, mixed> $row wpdb result row (all values come back as strings) */
    public static function fromRow(array $row): self
    {
        return new self(
            id:             (int) $row['id'],
            userId:         (int) $row['user_id'],
            url:            (string) $row['url'],
            label:          (string) $row['label'],
            status:         (string) $row['status'],
            sitePublicKey:  isset($row['site_public_key']) ? (string) $row['site_public_key'] : null,
            ourPublicKey:   isset($row['our_public_key'])  ? (string) $row['our_public_key']  : null,
            lastContactAt:  isset($row['last_contact_at']) ? (string) $row['last_contact_at'] : null,
            lastError:      isset($row['last_error'])      ? (string) $row['last_error']      : null,
            createdAt:      (string) $row['created_at'],
        );
    }

    /** @return array<string, mixed> shape the SPA receives over the wire */
    public function toJson(): array
    {
        return [
            'id'              => $this->id,
            'url'             => $this->url,
            'label'           => $this->label,
            'status'          => $this->status,
            'last_contact_at' => $this->lastContactAt,
            'last_error'      => $this->lastError,
            'created_at'      => $this->createdAt,
        ];
    }
}
