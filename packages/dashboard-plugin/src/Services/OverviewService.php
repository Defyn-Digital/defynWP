<?php

declare(strict_types=1);

namespace Defyn\Dashboard\Services;

/**
 * P2.5 — composes the GET /defyn/v1/overview response.
 *
 * Read-only aggregation. Delegates all DB work to SitesRepository +
 * ActivityLogRepository. Lives in Services/ alongside the existing
 * SyncService, HealthService etc.
 *
 * Spec: docs/superpowers/specs/2026-06-07-p2-5-overview-dashboard-design.md § 3
 */
final class OverviewService
{
    public function __construct(
        private readonly SitesRepository $sites = new SitesRepository(),
        private readonly ActivityLogRepository $activity = new ActivityLogRepository(),
        private readonly IncidentsRepository $incidents = new IncidentsRepository(),
    ) {
    }

    /**
     * @return array{
     *   pending_updates: array{
     *     plugins:int, themes:int, cores_minor:int, cores_major:int, sites_with_any_update:int
     *   },
     *   sites_needing_attention: list<array{
     *     site_id:int, url:string, label:string, reasons:list<string>,
     *     last_contact_at:?string, ssl_expires_at:?string
     *   }>,
     *   open_incidents: list<array{site_id:int, site_label:string, started_at:string}>,
     *   recent_activity: list<array{
     *     id:int, site_id:?int, site_label:?string, event_type:string,
     *     details:array<string,mixed>|null, created_at:string
     *   }>,
     *   total_sites: int,
     *   generated_at: string
     * }
     */
    public function compose(int $userId): array
    {
        $activity = array_map(static function (array $row): array {
            $details = isset($row['details'])
                ? json_decode((string) $row['details'], true)
                : null;
            return [
                'id'         => (int) $row['id'],
                'site_id'    => isset($row['site_id']) ? (int) $row['site_id'] : null,
                'site_label' => isset($row['site_label']) ? (string) $row['site_label'] : null,
                'event_type' => (string) $row['event_type'],
                'details'    => is_array($details) ? $details : null,
                'created_at' => (string) $row['created_at'],
            ];
        }, $this->activity->tailForUser($userId, 25));

        return [
            'pending_updates' => [
                'plugins'               => $this->sites->countPendingPlugins($userId),
                'themes'                => $this->sites->countPendingThemes($userId),
                'cores_minor'           => $this->sites->countPendingCoresMinor($userId),
                'cores_major'           => $this->sites->countPendingCoresMajor($userId),
                'sites_with_any_update' => $this->sites->countSitesWithAnyUpdate($userId),
            ],
            'sites_needing_attention' => $this->sites->findSitesNeedingAttention($userId),
            'open_incidents'          => $this->incidents->findOpenForUser($userId),
            'recent_activity'         => $activity,
            'total_sites'             => $this->sites->countAllForUser($userId),
            'generated_at'            => gmdate('Y-m-d H:i:s'),
        ];
    }
}
