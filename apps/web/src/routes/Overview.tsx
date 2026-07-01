import { Puzzle, Palette, RefreshCw, AlertTriangle, Globe } from 'lucide-react'
import { useOverview } from '@/lib/queries/useOverview'
import { KpiCard } from '@/components/overview/KpiCard'
import { SitesNeedingAttentionWidget } from '@/components/overview/SitesNeedingAttentionWidget'
import { RecentActivityWidget } from '@/components/overview/RecentActivityWidget'
import { SyncAllSitesButton } from '@/components/overview/SyncAllSitesButton'
import { PageHeader } from '@/components/layout/PageHeader'
import { BulkUpdatePluginsButton } from '@/components/overview/BulkUpdatePluginsButton'
import { BulkUpdateThemesButton } from '@/components/overview/BulkUpdateThemesButton'
import { formatRelativeTime } from '@/lib/formatRelativeTime'

export default function Overview() {
  const { data, isLoading, isError, refetch } = useOverview()

  if (isLoading) {
    return (
      <div className="space-y-6 p-4 md:p-6">
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
          {Array.from({ length: 5 }).map((_, i) => (
            <div key={i} className="h-24 animate-pulse rounded-xl bg-muted" />
          ))}
        </div>
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          <div className="h-64 animate-pulse rounded-xl bg-muted" />
          <div className="h-64 animate-pulse rounded-xl bg-muted" />
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
  const attention = data.sites_needing_attention.length + data.open_incidents.length

  return (
    <div className="space-y-6 p-4 md:p-6">
      <PageHeader
        title="Overview"
        subtitle={`${data.total_sites} sites · updated ${formatRelativeTime(data.generated_at)}`}
        actions={
          <>
            <SyncAllSitesButton totalSites={data.total_sites} />
            <BulkUpdatePluginsButton pendingCount={data.pending_updates.plugins} />
            <BulkUpdateThemesButton pendingCount={data.pending_updates.themes} />
          </>
        }
      />

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        <KpiCard label="Plugin updates" value={data.pending_updates.plugins} icon={Puzzle} to="/overview/plugins" tone={data.pending_updates.plugins > 0 ? 'warning' : 'default'} />
        <KpiCard label="Theme updates" value={data.pending_updates.themes} icon={Palette} to="/overview/themes" tone={data.pending_updates.themes > 0 ? 'warning' : 'default'} />
        <KpiCard label="Core updates" value={cores} icon={RefreshCw} tone={cores > 0 ? 'warning' : 'default'} />
        <KpiCard label="Needs attention" value={attention} icon={AlertTriangle} tone={attention > 0 ? 'warning' : 'default'} />
        <KpiCard label="Sites" value={data.total_sites} icon={Globe} to="/sites" />
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <SitesNeedingAttentionWidget sites={data.sites_needing_attention} />
        <RecentActivityWidget events={data.recent_activity} />
      </div>
    </div>
  )
}
