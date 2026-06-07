<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Models;

/**
 * Immutable theme value object — mapped from wp_defyn_site_themes row.
 *
 * Two columns diverge from Plugin: parentSlug (child themes name their
 * parent's stylesheet directory; NULL for standalone) and isActive
 * (exactly one row per site has this true — the current stylesheet).
 */
final class Theme
{
    public function __construct(
        public readonly int     $id,
        public readonly int     $siteId,
        public readonly string  $slug,
        public readonly string  $name,
        public readonly ?string $version,
        public readonly ?string $parentSlug,
        public readonly bool    $isActive,
        public readonly bool    $updateAvailable,
        public readonly ?string $updateVersion,
        public readonly string  $updateState,
        public readonly ?string $lastUpdateError,
        public readonly ?string $lastUpdateAttemptAt,
        public readonly string  $lastSeenAt,
        public readonly string  $createdAt,
        public readonly string  $updatedAt,
        public readonly ?string $testedUpTo = null,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id:                  (int) $row['id'],
            siteId:              (int) $row['site_id'],
            slug:                (string) $row['slug'],
            name:                (string) $row['name'],
            version:             isset($row['version']) ? (string) $row['version'] : null,
            parentSlug:          isset($row['parent_slug']) ? (string) $row['parent_slug'] : null,
            isActive:            (bool) (int) ($row['is_active'] ?? 0),
            updateAvailable:     (bool) (int) ($row['update_available'] ?? 0),
            updateVersion:       isset($row['update_version']) ? (string) $row['update_version'] : null,
            updateState:         (string) ($row['update_state'] ?? 'idle'),
            lastUpdateError:     isset($row['last_update_error']) ? (string) $row['last_update_error'] : null,
            lastUpdateAttemptAt: isset($row['last_update_attempt_at']) ? (string) $row['last_update_attempt_at'] : null,
            lastSeenAt:          (string) $row['last_seen_at'],
            createdAt:           (string) $row['created_at'],
            updatedAt:           (string) $row['updated_at'],
            testedUpTo:          isset($row['tested_up_to']) ? (string) $row['tested_up_to'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toJson(): array
    {
        return [
            'id'                     => $this->id,
            'site_id'                => $this->siteId,
            'slug'                   => $this->slug,
            'name'                   => $this->name,
            'version'                => $this->version,
            'parent_slug'            => $this->parentSlug,
            'is_active'              => $this->isActive,
            'update_available'       => $this->updateAvailable,
            'update_version'         => $this->updateVersion,
            'update_state'           => $this->updateState,
            'last_update_error'      => $this->lastUpdateError,
            'last_update_attempt_at' => $this->lastUpdateAttemptAt,
            'last_seen_at'           => $this->lastSeenAt,
            'tested_up_to'           => $this->testedUpTo,
        ];
    }
}
