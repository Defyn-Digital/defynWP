<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Models;

/**
 * Read-only DTO for a row of wp_defyn_sites.
 *
 * F5 surfaced only the connection-shape columns (id/url/label/status/keys).
 * F6 expands the model to expose the runtime-info columns populated by
 * SyncService::sync(): wp/php version, active theme JSON, plugin/theme
 * counts JSON, SSL status, last-sync timestamp. These power the SPA
 * dashboard surfacing site health, and SyncService itself reads
 * $ourPrivateKey to decrypt the per-site keypair.
 *
 * F8 expands toJson() so the SPA detail view sees the F6/F7 runtime
 * fields (wp_version, php_version, active_theme, plugin/theme counts,
 * ssl_status, ssl_expires_at, last_sync_at).
 *
 * toJson() intentionally still hides user_id, our_public_key,
 * our_private_key, and site_public_key — those are internal to the
 * dashboard and must never reach the SPA wire.
 */
final class Site
{
    /**
     * @param array<string, mixed>|null $activeTheme   decoded JSON from active_theme column
     * @param array<string, int>|null   $pluginCounts  decoded JSON from plugin_counts column
     * @param array<string, int>|null   $themeCounts   decoded JSON from theme_counts column
     */
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
        // F6 additions — runtime info from /status pull + encrypted keypair.
        public readonly ?string $ourPrivateKey = null,
        public readonly ?string $wpVersion = null,
        public readonly ?string $phpVersion = null,
        public readonly ?array  $activeTheme = null,
        public readonly ?array  $pluginCounts = null,
        public readonly ?array  $themeCounts = null,
        public readonly ?string $sslStatus = null,
        public readonly ?string $sslExpiresAt = null,
        public readonly ?string $lastSyncAt = null,
        // P2.4 additions — core update fields.
        public readonly bool    $coreUpdateAvailable = false,
        public readonly ?string $coreUpdateVersion = null,
        public readonly string  $coreUpdateState = 'idle',
        public readonly ?string $lastCoreUpdateError = null,
        public readonly ?string $lastCoreUpdateAttemptAt = null,
        // P2.4.1 additions — major version policy flag.
        public readonly bool    $coreAllowMajor = false,
        // P3.1 additions — consecutive health-check failure counter (internal; not surfaced to SPA).
        public readonly int     $consecutiveFailures = 0,
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
            ourPrivateKey:  isset($row['our_private_key']) ? (string) $row['our_private_key'] : null,
            wpVersion:      isset($row['wp_version'])      ? (string) $row['wp_version']      : null,
            phpVersion:     isset($row['php_version'])     ? (string) $row['php_version']     : null,
            activeTheme:    self::decodeJsonColumn($row['active_theme']  ?? null),
            pluginCounts:   self::decodeJsonColumn($row['plugin_counts'] ?? null),
            themeCounts:    self::decodeJsonColumn($row['theme_counts']  ?? null),
            sslStatus:      isset($row['ssl_status'])      ? (string) $row['ssl_status']      : null,
            sslExpiresAt:   isset($row['ssl_expires_at'])  ? (string) $row['ssl_expires_at']  : null,
            lastSyncAt:     isset($row['last_sync_at'])    ? (string) $row['last_sync_at']    : null,
            coreUpdateAvailable:     (bool) (int) ($row['core_update_available'] ?? 0),
            coreUpdateVersion:       isset($row['core_update_version']) ? (string) $row['core_update_version'] : null,
            coreUpdateState:         (string) ($row['core_update_state'] ?? 'idle'),
            lastCoreUpdateError:     isset($row['last_core_update_error']) ? (string) $row['last_core_update_error'] : null,
            lastCoreUpdateAttemptAt: isset($row['last_core_update_attempt_at']) ? (string) $row['last_core_update_attempt_at'] : null,
            coreAllowMajor:          (bool) (int) ($row['core_allow_major'] ?? 0),
            consecutiveFailures:     isset($row['consecutive_failures']) ? (int) $row['consecutive_failures'] : 0,
        );
    }

    /**
     * Decode a LONGTEXT JSON column. Returns null when the column is null,
     * empty, or contains malformed JSON — callers treat missing/invalid as
     * "not yet synced," same as a true NULL.
     *
     * @return array<string, mixed>|null
     */
    private static function decodeJsonColumn(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : null;
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
            'last_sync_at'    => $this->lastSyncAt,
            'last_error'      => $this->lastError,
            'created_at'      => $this->createdAt,
            // F8: expose F6/F7 runtime info to the SPA. Null for sites
            // that haven't successfully synced yet.
            'wp_version'      => $this->wpVersion,
            'php_version'     => $this->phpVersion,
            'active_theme'    => $this->activeTheme,
            'plugin_counts'   => $this->pluginCounts,
            'theme_counts'    => $this->themeCounts,
            'ssl_status'      => $this->sslStatus,
            'ssl_expires_at'  => $this->sslExpiresAt,
            // P2.4: expose core update fields to the SPA.
            'core_update_available'       => $this->coreUpdateAvailable,
            'core_update_version'         => $this->coreUpdateVersion,
            'core_update_state'           => $this->coreUpdateState,
            'last_core_update_error'      => $this->lastCoreUpdateError,
            'last_core_update_attempt_at' => $this->lastCoreUpdateAttemptAt,
            'core_allow_major'            => $this->coreAllowMajor,
        ];
    }
}
