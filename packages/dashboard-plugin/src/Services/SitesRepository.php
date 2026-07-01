<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom plugin tables: direct queries are required and results are request-scoped.

use Defyn\Dashboard\Models\Site;
use Defyn\Dashboard\Schema\SiteBrokenLinksTable;
use Defyn\Dashboard\Schema\SiteVulnerabilitiesTable;
use Defyn\Dashboard\Schema\SitesTable;

/**
 * Thin wrapper over wpdb for wp_defyn_sites — the only class that issues
 * raw SQL for that table. Controllers + AS jobs call this; tests assert
 * persistence through it. Other classes never touch wpdb for sites directly.
 */
final class SitesRepository
{
    private \wpdb  $wpdb;
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = SitesTable::tableName();
    }

    public function insertPending(
        int    $userId,
        string $url,
        string $label,
        string $ourPublicKey,
        string $ourPrivateKeyEncrypted,
    ): int {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $result = $wpdb->insert(
            SitesTable::tableName(),
            [
                'user_id'         => $userId,
                'url'             => $url,
                'label'           => $label,
                'status'          => 'pending',
                'our_public_key'  => $ourPublicKey,
                'our_private_key' => $ourPrivateKeyEncrypted,
                'created_at'      => $now,
                'updated_at'      => $now,
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s'],
        );
        if ($result === false || (int) $wpdb->insert_id === 0) {
            // Don't swallow MySQL errors silently — the caller turns this into a
            // 500 with the actual driver message so production misconfigurations
            // surface instead of returning {site_id: 0} forever.
            throw new \RuntimeException(
                esc_html(
                    $wpdb->last_error !== ''
                        ? 'wp_defyn_sites insert failed: ' . $wpdb->last_error
                        : 'wp_defyn_sites insert failed without a MySQL error message.'
                )
            );
        }
        return (int) $wpdb->insert_id;
    }

    public function findById(int $id): ?Site
    {
        global $wpdb;
        $table = SitesTable::tableName();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);
        return $row ? Site::fromRow($row) : null;
    }

    public function findByIdForUser(int $id, int $userId): ?Site
    {
        // Team-shared fleet: per-user filter intentionally removed (2026-06-22 SSO spec).
        return $this->findById($id);
    }

    /**
     * Team-wide delete. Returns true if a row was deleted, false if not found.
     * Caller must NOT echo "deleted" on false — use the same 404 envelope as a
     * not-found lookup. The findByIdForUser gate (already team-wide) is the only
     * ownership check; this method just removes the row unconditionally.
     *
     * Team-shared fleet: per-user filter intentionally removed (2026-06-22 SSO spec).
     */
    public function deleteForUser(int $id, int $userId): bool
    {
        global $wpdb;
        $affected = $wpdb->delete(
            SitesTable::tableName(),
            ['id' => $id],
            ['%d'],
        );
        return (int) $affected === 1;
    }

    /** @return list<Site> */
    public function findAllForUser(int $userId, ?string $filter = null): array
    {
        global $wpdb;

        // Team-shared fleet: per-user filter intentionally removed (2026-06-22 SSO spec).
        if ($filter === 'has-plugin-updates') {
            $pluginsTable = $wpdb->prefix . 'defyn_site_plugins';
            $rows = $wpdb->get_results(
                "SELECT s.* FROM {$this->table} s
                 WHERE EXISTS (SELECT 1 FROM {$pluginsTable} sp WHERE sp.site_id = s.id AND sp.update_available = 1)
                 ORDER BY s.id ASC",
                ARRAY_A
            );
        } elseif ($filter === 'has-theme-updates') {
            $themesTable = $wpdb->prefix . 'defyn_site_themes';
            $rows = $wpdb->get_results(
                "SELECT s.* FROM {$this->table} s
                 WHERE EXISTS (SELECT 1 FROM {$themesTable} st WHERE st.site_id = s.id AND st.update_available = 1)
                 ORDER BY s.id ASC",
                ARRAY_A
            );
        } elseif ($filter === 'has-core-update') {
            $rows = $wpdb->get_results(
                "SELECT * FROM {$this->table}
                 WHERE core_update_available = 1
                 ORDER BY id ASC",
                ARRAY_A
            );
        } else {
            // Preserve exact original unfiltered behavior.
            $table = SitesTable::tableName();
            $rows = $wpdb->get_results(
                "SELECT * FROM {$table} ORDER BY id ASC",
                ARRAY_A,
            );
        }

        return array_map([Site::class, 'fromRow'], $rows ?: []);
    }

    /**
     * Site IDs eligible for background sync/ping. Active + offline + error (all
     * have a completed handshake and a private key on file; even error sites
     * might recover). Excludes pending (handshake not yet complete).
     *
     * TODO (F10+): paginate when sites > 500 — current naive LIMIT keeps the
     * fan-out within Kinsta's 300s PHP budget.
     *
     * @return list<int>
     */
    public function findAllSchedulable(int $limit = 500): array
    {
        global $wpdb;
        $table = SitesTable::tableName();
        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE status IN ('active', 'offline', 'error') ORDER BY id ASC LIMIT %d",
                $limit,
            ),
        );
        return array_map('intval', $rows ?: []);
    }

    /**
     * Team-wide duplicate-URL check. Returns true if ANY site in the shared fleet
     * matches $url (case-insensitive), regardless of who created it.
     *
     * Team-shared fleet: per-user filter intentionally removed (2026-06-22 SSO spec).
     */
    public function existsForUser(int $userId, string $url): bool
    {
        global $wpdb;
        $table = SitesTable::tableName();
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE LOWER(url) = %s",
                strtolower($url),
            ),
        );
        return $count > 0;
    }

    public function markActive(int $id, string $sitePublicKey): void
    {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->update(
            SitesTable::tableName(),
            [
                'status'          => 'active',
                'site_public_key' => $sitePublicKey,
                'last_contact_at' => $now,
                'updated_at'      => $now,
            ],
            ['id' => $id],
            ['%s', '%s', '%s', '%s'],
            ['%d'],
        );
    }

    public function markError(int $id, string $message): void
    {
        global $wpdb;
        $wpdb->update(
            SitesTable::tableName(),
            [
                'status'     => 'error',
                'last_error' => $message,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['id' => $id],
            ['%s', '%s', '%s'],
            ['%d'],
        );
    }

    /**
     * Persist runtime info from a successful /status pull (spec § 5.1).
     * JSON-encodes the structured fields and bumps last_sync_at + last_contact_at.
     *
     * P2.3 v4 migration: active_theme moved to wp_defyn_site_themes table;
     * still accepted in $info for backward compatibility but no longer persisted here.
     *
     * P2.4: Propagates core sub-object from connector /status payload + performs
     * day-1 single-row heal when incoming says "no update available" but row is
     * stuck in `failed` state.
     *
     * @param array{
     *   wp_version: string,
     *   php_version: string,
     *   active_theme?: array<string, mixed>,
     *   plugin_counts: array<string, int>,
     *   theme_counts: array<string, int>,
     *   ssl_status: string,
     *   ssl_expires_at: ?string,
     *   server_time?: int,
     *   core?: array<string, mixed>
     * } $info
     */
    public function markSynced(int $id, array $info): void
    {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');

        $updates = [
            // A successful /status pull proves the site is alive and the
            // dashboard ↔ connector trust still works, so clear any prior
            // error/offline state. Without this the SPA would show stale
            // `status=error` + `last_error` forever after a single bad
            // sync (e.g. transient 404/401 while WP.com Batcache warmed).
            'status'          => 'active',
            'last_error'      => '',
            'wp_version'      => $info['wp_version'],
            'php_version'     => $info['php_version'],
            'plugin_counts'   => (string) wp_json_encode($info['plugin_counts']),
            'theme_counts'    => (string) wp_json_encode($info['theme_counts']),
            'ssl_status'      => $info['ssl_status'],
            'ssl_expires_at'  => $info['ssl_expires_at'],
            'last_sync_at'    => $now,
            'last_contact_at' => $now,
            'connector_version' => $info['connector_version'] ?? null,
            'is_wpengine'       => !empty($info['is_wpengine']) ? 1 : 0,
            'updated_at'      => $now,
        ];

        // P2.4 — propagate the core sub-object from the connector /status payload.
        $coreInfo = $info['core'] ?? null;
        if (is_array($coreInfo)) {
            $updates['core_update_available'] = !empty($coreInfo['update_available']) ? 1 : 0;
            $updates['core_update_version']   = $coreInfo['update_version'] ?? null;

            // Day-1 single-row heal — if incoming says "no update available"
            // but the existing row is stuck in `failed`, reset to idle + clear
            // the stale error. Ships from day 1 (not retrofitted like P2.2.1).
            $existing = $this->findById($id);
            if (
                $existing !== null
                && $existing->coreUpdateState === 'failed'
                && empty($coreInfo['update_available'])
            ) {
                $updates['core_update_state']      = 'idle';
                $updates['last_core_update_error'] = null;
            }
        }

        $wpdb->update(SitesTable::tableName(), $updates, ['id' => $id]);
    }

    /**
     * P2.4 — operator pressed "Update WordPress core". Flip the row to queued
     * + clear any prior error. Called from SitesCoreUpdateController.
     */
    public function markCoreUpdateRequested(int $siteId, string $now): void
    {
        global $wpdb;
        $wpdb->update(
            SitesTable::tableName(),
            [
                'core_update_state'           => 'queued',
                'last_core_update_error'      => null,
                'last_core_update_attempt_at' => $now,
                'updated_at'                  => $now,
            ],
            ['id' => $siteId],
            ['%s', '%s', '%s', '%s'],
            ['%d'],
        );
    }

    /**
     * P2.4 — AS job started executing the upgrade. Called from UpdateSiteCore.
     */
    public function markCoreUpdating(int $siteId, string $now): void
    {
        global $wpdb;
        $wpdb->update(
            SitesTable::tableName(),
            [
                'core_update_state' => 'updating',
                'updated_at'        => $now,
            ],
            ['id' => $siteId],
            ['%s', '%s'],
            ['%d'],
        );
    }

    /**
     * P2.4 — upgrade succeeded. Bump wp_version + clear the update-available
     * badge.
     */
    public function markCoreUpdateSucceeded(int $siteId, string $newVersion, string $now): void
    {
        global $wpdb;
        $wpdb->update(
            SitesTable::tableName(),
            [
                'wp_version'             => $newVersion,
                'core_update_state'      => 'idle',
                'core_update_available'  => 0,
                'core_update_version'    => null,
                'last_core_update_error' => null,
                'updated_at'             => $now,
            ],
            ['id' => $siteId],
            ['%s', '%s', '%d', '%s', '%s', '%s'],
            ['%d'],
        );
    }

    /**
     * P2.4 — upgrade failed (terminal). Truncates the error to 1000 chars to
     * match the VARCHAR(1000) column.
     */
    public function markCoreUpdateFailed(int $siteId, string $errorMessage, string $now): void
    {
        global $wpdb;
        $wpdb->update(
            SitesTable::tableName(),
            [
                'core_update_state'           => 'failed',
                'last_core_update_error'      => substr($errorMessage, 0, 1000),
                'last_core_update_attempt_at' => $now,
                'updated_at'                  => $now,
            ],
            ['id' => $siteId],
            ['%s', '%s', '%s', '%s'],
            ['%d'],
        );
    }

    /**
     * Mark the site as offline after a failed health check (Task 14).
     * 'offline' is a new status value alongside F1's pending/active/error;
     * it fits the existing VARCHAR(20) so no schema bump is needed.
     */
    public function markOffline(int $id, string $message): void
    {
        global $wpdb;
        $wpdb->update(
            SitesTable::tableName(),
            [
                'status'     => 'offline',
                'last_error' => $message,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['id' => $id],
            ['%s', '%s', '%s'],
            ['%d'],
        );
    }

    /**
     * Happy-path heartbeat tick: bump last_contact_at + updated_at only.
     * Does NOT touch status — caller has already confirmed the site is healthy
     * and not transitioning out of 'offline' (use markRecovered for that).
     */
    public function markContactAt(int $id): void
    {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->update(
            SitesTable::tableName(),
            [
                'last_contact_at' => $now,
                'updated_at'      => $now,
            ],
            ['id' => $id],
            ['%s', '%s'],
            ['%d'],
        );
    }

    /**
     * Recovery transition: flips a previously 'offline' site back to 'active',
     * clears the stale last_error (important for SPA UX — no ghost error after
     * recovery), and bumps last_contact_at + updated_at.
     */
    public function markRecovered(int $id): void
    {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->update(
            SitesTable::tableName(),
            [
                'status'          => 'active',
                'last_error'      => '',
                'last_contact_at' => $now,
                'updated_at'      => $now,
            ],
            ['id' => $id],
            ['%s', '%s', '%s', '%s'],
            ['%d'],
        );
    }

    /**
     * P2.4.1 — set the core_allow_major flag for a site (allow/block major version updates).
     */
    public function setCoreAllowMajor(int $siteId, bool $allow): void
    {
        global $wpdb;
        $wpdb->update(
            SitesTable::tableName(),
            ['core_allow_major' => $allow ? 1 : 0],
            ['id' => $siteId],
            ['%d'],
            ['%d'],
        );
    }

    /**
     * P5.3 — set the per-site default report recipient. Pass null to clear it.
     * The controller validates the address (or empty-to-clear) before calling.
     */
    public function setClientEmail(int $siteId, ?string $email): void
    {
        global $wpdb;
        $wpdb->update(SitesTable::tableName(), ['client_email' => $email], ['id' => $siteId]);
    }

    /**
     * P6.2 — set the GA4 property ID for a site. Pass null to clear it.
     */
    public function setGa4PropertyId(int $siteId, ?string $propertyId): void
    {
        global $wpdb;
        $wpdb->update(SitesTable::tableName(), ['ga4_property_id' => $propertyId], ['id' => $siteId]);
    }

    /**
     * P5.4 — toggle the per-site auto-send-reports opt-in flag.
     */
    public function setAutoSendReports(int $siteId, bool $on): void
    {
        global $wpdb;
        $wpdb->update(SitesTable::tableName(), ['auto_send_reports' => (int) $on], ['id' => $siteId]);
    }

    /**
     * P2.5 — count of pending plugin updates across all sites owned by $userId.
     */
    public function countPendingPlugins(int $userId): int
    {
        global $wpdb;
        $sitesTable   = SitesTable::tableName();
        $pluginsTable = $wpdb->prefix . 'defyn_site_plugins';

        // Team-shared fleet: per-user filter intentionally removed (2026-06-22 SSO spec).
        // phpcs:ignore WordPress.DB.PreparedSQL
        return (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$pluginsTable} sp
             INNER JOIN {$sitesTable} s ON s.id = sp.site_id
             WHERE sp.update_available = 1"
        );
    }

    /**
     * P2.5 — count of pending theme updates across all sites owned by $userId.
     */
    public function countPendingThemes(int $userId): int
    {
        global $wpdb;
        $sitesTable  = SitesTable::tableName();
        $themesTable = $wpdb->prefix . 'defyn_site_themes';

        // Team-shared fleet: per-user filter intentionally removed (2026-06-22 SSO spec).
        // phpcs:ignore WordPress.DB.PreparedSQL
        return (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$themesTable} st
             INNER JOIN {$sitesTable} s ON s.id = st.site_id
             WHERE st.update_available = 1"
        );
    }

    /**
     * P2.5 — count of pending MINOR core updates (major.minor segments match).
     * A bump is "minor" when wp_version major.minor === core_update_version major.minor.
     */
    public function countPendingCoresMinor(int $userId): int
    {
        global $wpdb;
        $sitesTable = SitesTable::tableName();

        // Team-shared fleet: per-user filter intentionally removed (2026-06-22 SSO spec).
        // phpcs:ignore WordPress.DB.PreparedSQL
        return (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$sitesTable}
             WHERE core_update_available = 1
               AND core_update_version IS NOT NULL
               AND SUBSTRING_INDEX(wp_version, '.', 2) = SUBSTRING_INDEX(core_update_version, '.', 2)"
        );
    }

    /**
     * P2.5 — count of pending MAJOR core updates (major or minor segments differ).
     */
    public function countPendingCoresMajor(int $userId): int
    {
        global $wpdb;
        $sitesTable = SitesTable::tableName();

        // Team-shared fleet: per-user filter intentionally removed (2026-06-22 SSO spec).
        // phpcs:ignore WordPress.DB.PreparedSQL
        return (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$sitesTable}
             WHERE core_update_available = 1
               AND core_update_version IS NOT NULL
               AND SUBSTRING_INDEX(wp_version, '.', 2) != SUBSTRING_INDEX(core_update_version, '.', 2)"
        );
    }

    /**
     * P2.5 — count of sites owned by $userId that have ANY pending update
     * (plugin OR theme OR core). Uses UNION to deduplicate sites that
     * have multiple kinds of pending updates.
     */
    public function countSitesWithAnyUpdate(int $userId): int
    {
        global $wpdb;
        $sitesTable   = SitesTable::tableName();
        $pluginsTable = $wpdb->prefix . 'defyn_site_plugins';
        $themesTable  = $wpdb->prefix . 'defyn_site_themes';

        // Team-shared fleet: per-user filter intentionally removed (2026-06-22 SSO spec).
        // phpcs:ignore WordPress.DB.PreparedSQL
        return (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT site_id) FROM (
                SELECT sp.site_id FROM {$pluginsTable} sp
                  INNER JOIN {$sitesTable} s ON s.id = sp.site_id
                  WHERE sp.update_available = 1
                UNION
                SELECT st.site_id FROM {$themesTable} st
                  INNER JOIN {$sitesTable} s ON s.id = st.site_id
                  WHERE st.update_available = 1
                UNION
                SELECT id FROM {$sitesTable}
                  WHERE core_update_available = 1
             ) AS combined"
        );
    }

    /**
     * P2.6 — count of sites owned by $userId. Used by OverviewService to
     * emit `total_sites` on the /overview response so the SPA's "Sync all
     * N sites" button can display the dynamic count.
     *
     * Implementation note: COUNT(*) on the sites table directly — DO NOT
     * use count(findAllForUser($userId)), which materializes the full row
     * set just to count it. Mirrors the existing countPendingPlugins
     * pattern at line ~445.
     */
    public function countAllForUser(int $userId): int
    {
        global $wpdb;
        $sitesTable = SitesTable::tableName();

        // Team-shared fleet: per-user filter intentionally removed (2026-06-22 SSO spec).
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$sitesTable}");
    }

    /**
     * P3.2 — persist the last measured heartbeat round-trip. NULL on a failed
     * ping so a down site never shows a stale latency. Dedicated method:
     * the status-flip methods (markContactAt/markRecovered/markOffline) are
     * intentionally left untouched.
     */
    public function recordResponseTime(int $id, ?int $ms): void
    {
        global $wpdb;
        $table = SitesTable::tableName();
        $now   = gmdate('Y-m-d H:i:s');

        if ($ms === null) {
            // phpcs:ignore WordPress.DB.PreparedSQL — table name is a constant.
            $wpdb->query($wpdb->prepare(
                "UPDATE `{$table}` SET last_response_time_ms = NULL, updated_at = %s WHERE id = %d",
                $now,
                $id
            ));
            return;
        }

        $wpdb->update(
            $table,
            ['last_response_time_ms' => $ms, 'updated_at' => $now],
            ['id' => $id],
            ['%d', '%s'],
            ['%d'],
        );
    }

    /**
     * P3.1 — Atomically increment consecutive_failures for a site and return
     * the new value. Called by HealthService after each failed ping attempt.
     */
    public function incrementConsecutiveFailures(int $siteId): int
    {
        global $wpdb;
        $t = SitesTable::tableName();
        // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->query($wpdb->prepare("UPDATE `{$t}` SET consecutive_failures = consecutive_failures + 1 WHERE id = %d", $siteId));
        return (int) $wpdb->get_var($wpdb->prepare("SELECT consecutive_failures FROM `{$t}` WHERE id = %d", $siteId));
    }

    /**
     * P3.1 — Reset consecutive_failures to 0 after a successful ping (recovery).
     */
    public function resetConsecutiveFailures(int $siteId): void
    {
        global $wpdb;
        $t = SitesTable::tableName();
        $wpdb->update($t, ['consecutive_failures' => 0], ['id' => $siteId], ['%d'], ['%d']);
    }

    /**
     * P3.3 — Toggle the alerts_muted flag for a site. When muted, alerting
     * services skip emitting notifications for that site.
     */
    public function setAlertsMuted(int $siteId, bool $muted): void
    {
        global $wpdb;
        $wpdb->update(SitesTable::tableName(), ['alerts_muted' => $muted ? 1 : 0], ['id' => $siteId], ['%d'], ['%d']);
    }

    /**
     * P3.3 — Record the UTC timestamp at which an SSL-expiry alert was last sent
     * for a site, so the alerter can suppress re-sends within the cooldown window.
     */
    public function markSslAlertSent(int $siteId, string $nowUtc): void
    {
        global $wpdb;
        $wpdb->update(SitesTable::tableName(), ['ssl_alert_sent_at' => $nowUtc], ['id' => $siteId], ['%s'], ['%d']);
    }

    /**
     * P3.3 — Clear the ssl_alert_sent_at stamp (e.g. after SSL renews or when
     * the cooldown is manually reset). Uses an explicit prepared query because
     * $wpdb->update() does not reliably emit SQL NULL.
     */
    public function clearSslAlertSent(int $siteId): void
    {
        global $wpdb;
        $table = SitesTable::tableName();
        // phpcs:ignore WordPress.DB.PreparedSQL — table name is a constant.
        $wpdb->query($wpdb->prepare("UPDATE `{$table}` SET ssl_alert_sent_at = NULL WHERE id = %d", $siteId));
    }

    /**
     * P4.1 — Record the UTC timestamp at which the security scan last completed
     * for a site. Called by the security-scan AS job on successful scan.
     */
    public function markSecurityScannedAt(int $siteId, string $now): void
    {
        global $wpdb;
        $wpdb->update(
            SitesTable::tableName(),
            ['last_security_scan_at' => $now],
            ['id' => $siteId],
            ['%s'],
            ['%d'],
        );
    }

    /**
     * P7.1 — Record the UTC timestamp at which the broken-link scan last completed
     * (or last attempted) for a site. Called by BrokenLinkScanService on every scan
     * attempt, including failure — so the operator can tell "scanned, failed" from
     * "never scanned".
     */
    public function markLinkScannedAt(int $siteId, string $now): void
    {
        global $wpdb;
        $wpdb->update(
            SitesTable::tableName(),
            ['last_link_scan_at' => $now],
            ['id' => $siteId],
            ['%s'],
            ['%d'],
        );
    }

    /**
     * P2.5 — sites owned by $userId that have at least one attention reason.
     * Capped at 50 rows. Hardcoded thresholds per spec § 3.4.
     *
     * @return list<array{site_id:int,url:string,label:string,reasons:list<string>,last_contact_at:?string,ssl_expires_at:?string}>
     */
    public function findSitesNeedingAttention(int $userId): array
    {
        $sitesTable        = $this->table;
        $pluginsTable      = $this->wpdb->prefix . 'defyn_site_plugins';
        $themesTable       = $this->wpdb->prefix . 'defyn_site_themes';
        $vulnTable         = SiteVulnerabilitiesTable::tableName();
        $brokenLinksTable  = SiteBrokenLinksTable::tableName();

        // Team-shared fleet: per-user filter intentionally removed (2026-06-22 SSO spec).
        // phpcs:ignore WordPress.DB.PreparedSQL
        $rows = $this->wpdb->get_results(
            "SELECT
                s.id,
                s.url,
                s.label,
                s.last_contact_at,
                s.ssl_expires_at,
                CASE WHEN s.last_contact_at IS NOT NULL AND s.last_contact_at < (UTC_TIMESTAMP() - INTERVAL 15 MINUTE) THEN 1 ELSE 0 END AS is_offline,
                CASE WHEN s.ssl_expires_at IS NOT NULL AND s.ssl_expires_at < (UTC_TIMESTAMP() + INTERVAL 30 DAY) THEN 1 ELSE 0 END AS is_ssl_expiring,
                CASE WHEN s.last_sync_at IS NOT NULL AND s.last_sync_at < (UTC_TIMESTAMP() - INTERVAL 24 HOUR) THEN 1 ELSE 0 END AS is_sync_stale,
                CASE WHEN s.core_update_state = 'failed'
                     OR EXISTS (SELECT 1 FROM {$pluginsTable} sp WHERE sp.site_id = s.id AND sp.update_state = 'failed')
                     OR EXISTS (SELECT 1 FROM {$themesTable} st WHERE st.site_id = s.id AND st.update_state = 'failed')
                     THEN 1 ELSE 0 END AS has_failed_update,
                CASE WHEN EXISTS (SELECT 1 FROM {$vulnTable} sv WHERE sv.site_id = s.id) THEN 1 ELSE 0 END AS has_vulnerabilities,
                CASE WHEN EXISTS (SELECT 1 FROM {$brokenLinksTable} bl WHERE bl.site_id = s.id AND bl.severity = 'broken') THEN 1 ELSE 0 END AS has_broken_links
             FROM {$sitesTable} s
             HAVING is_offline = 1 OR is_ssl_expiring = 1 OR is_sync_stale = 1 OR has_failed_update = 1 OR has_vulnerabilities = 1 OR has_broken_links = 1
             ORDER BY s.last_contact_at ASC
             LIMIT 50",
            ARRAY_A
        );

        $out = [];
        foreach ($rows ?? [] as $row) {
            $reasons = [];
            if ((int) $row['is_offline'] === 1) {
                $reasons[] = 'offline';
            }
            if ((int) $row['has_failed_update'] === 1) {
                $reasons[] = 'failed_update';
            }
            if ((int) $row['is_ssl_expiring'] === 1) {
                $reasons[] = 'ssl_expiring';
            }
            if ((int) $row['is_sync_stale'] === 1) {
                $reasons[] = 'sync_stale';
            }
            if ((int) $row['has_vulnerabilities'] === 1) {
                $reasons[] = 'has_vulnerabilities';
            }
            if ((int) $row['has_broken_links'] === 1) {
                $reasons[] = 'has_broken_links';
            }
            $out[] = [
                'site_id'         => (int) $row['id'],
                'url'             => (string) $row['url'],
                'label'           => (string) $row['label'],
                'reasons'         => $reasons,
                'last_contact_at' => $row['last_contact_at'] ?? null,
                'ssl_expires_at'  => $row['ssl_expires_at'] ?? null,
            ];
        }
        return $out;
    }
    /**
     * @return list<array{id: int, connector_version: ?string}> active sites for the connector-update fan-out.
     */
    public function activeConnectorVersions(): array
    {
        global $wpdb;
        $table = SitesTable::tableName();
        // phpcs:ignore WordPress.DB.PreparedSQL
        $rows = $wpdb->get_results("SELECT id, connector_version FROM `{$table}` WHERE status = 'active'", ARRAY_A);
        $out = [];
        foreach ((array) $rows as $r) {
            $out[] = ['id' => (int) $r['id'], 'connector_version' => $r['connector_version'] !== null ? (string) $r['connector_version'] : null];
        }
        return $out;
    }

    public function updateConnectorVersion(int $siteId, string $version): void
    {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->update(
            SitesTable::tableName(),
            ['connector_version' => $version, 'updated_at' => $now],
            ['id' => $siteId],
            ['%s', '%s'],
            ['%d'],
        );
    }
}
