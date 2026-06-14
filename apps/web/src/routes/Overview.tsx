import { useOverview } from '@/lib/queries/useOverview'
import { PendingUpdatesWidget } from '@/components/overview/PendingUpdatesWidget'
import { SitesNeedingAttentionWidget } from '@/components/overview/SitesNeedingAttentionWidget'
import { RecentActivityWidget } from '@/components/overview/RecentActivityWidget'
import { SyncAllSitesButton } from '@/components/overview/SyncAllSitesButton'
import { JobsNavLink } from '@/components/nav/JobsNavLink'
import { MonitoringNavLink } from '@/components/nav/MonitoringNavLink'
import { BulkUpdatePluginsButton } from '@/components/overview/BulkUpdatePluginsButton'
import { BulkUpdateThemesButton } from '@/components/overview/BulkUpdateThemesButton'
import { formatRelativeTime } from '@/lib/formatRelativeTime'
import { OpenIncidentsWidget } from '@/components/overview/OpenIncidentsWidget'

export default function Overview() {
  const { data, isLoading, isError, refetch } = useOverview()

  if (isLoading) {
    return (
      <div className="space-y-4 p-4">
        <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
          <div className="h-24 animate-pulse rounded-md bg-gray-100" />
          <div className="h-24 animate-pulse rounded-md bg-gray-100" />
          <div className="h-24 animate-pulse rounded-md bg-gray-100" />
        </div>
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          <div className="h-64 animate-pulse rounded-md bg-gray-100" />
          <div className="h-64 animate-pulse rounded-md bg-gray-100" />
        </div>
      </div>
    )
  }

  if (isError || !data) {
    return (
      <div className="p-4">
        <div className="rounded-md border border-red-200 bg-red-50 p-4">
          <p className="text-sm text-red-800">Failed to load the overview.</p>
          <button
            onClick={() => refetch()}
            className="mt-2 rounded-md border border-red-200 px-3 py-1 text-sm text-red-800"
          >
            Try again
          </button>
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-4 p-4">
      <div className="flex items-start justify-between">
        <div className="flex items-baseline gap-3">
          <h1 className="text-xl font-semibold">Overview</h1>
          <JobsNavLink />
          <MonitoringNavLink />
        </div>
        <div className="flex flex-col items-end gap-1">
          <p className="text-xs text-muted-foreground">
            Last refreshed: {formatRelativeTime(data.generated_at)}
          </p>
          <SyncAllSitesButton totalSites={data.total_sites} />
          <BulkUpdatePluginsButton pendingCount={data.pending_updates.plugins} />
          <BulkUpdateThemesButton pendingCount={data.pending_updates.themes} />
        </div>
      </div>

      <OpenIncidentsWidget openIncidents={data.open_incidents} />

      <PendingUpdatesWidget counts={data.pending_updates} />

      <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
        <SitesNeedingAttentionWidget sites={data.sites_needing_attention} />
        <RecentActivityWidget events={data.recent_activity} />
      </div>
    </div>
  )
}
