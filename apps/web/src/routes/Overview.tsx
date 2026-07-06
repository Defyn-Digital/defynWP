import { useOverview } from '@/lib/queries/useOverview'
import { UpdatesWidget } from '@/components/overview/UpdatesWidget'
import { ServicesWidget } from '@/components/overview/ServicesWidget'
import { SitesNeedingAttentionWidget } from '@/components/overview/SitesNeedingAttentionWidget'
import { RecentActivityWidget } from '@/components/overview/RecentActivityWidget'
import { SyncAllSitesButton } from '@/components/overview/SyncAllSitesButton'
import { PageHeader } from '@/components/layout/PageHeader'
import { formatRelativeTime } from '@/lib/formatRelativeTime'

export default function Overview() {
  const { data, isLoading, isError, refetch } = useOverview()

  if (isLoading) {
    return (
      <div className="space-y-6 p-4 md:p-6">
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
          {Array.from({ length: 3 }).map((_, i) => (
            <div key={i} className="h-72 animate-pulse rounded-xl bg-muted" />
          ))}
        </div>
      </div>
    )
  }

  if (isError || !data) {
    return (
      <div className="p-4 md:p-6">
        <div className="rounded-xl border border-red-200 bg-red-50 p-4">
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

  const cores = data.pending_updates.cores_minor + data.pending_updates.cores_major

  return (
    <div className="space-y-6 p-4 md:p-6">
      <PageHeader
        title="Overview"
        subtitle={`${data.total_sites} site${data.total_sites === 1 ? '' : 's'} · updated ${formatRelativeTime(data.generated_at)}`}
        actions={<SyncAllSitesButton totalSites={data.total_sites} />}
      />

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <div className="space-y-4">
          <UpdatesWidget plugins={data.pending_updates.plugins} themes={data.pending_updates.themes} cores={cores} />
        </div>

        <div className="space-y-4">
          <SitesNeedingAttentionWidget sites={data.sites_needing_attention} />
          <RecentActivityWidget events={data.recent_activity} />
        </div>

        <div className="space-y-4">
          <ServicesWidget openIncidents={data.open_incidents.length} />
        </div>
      </div>
    </div>
  )
}
