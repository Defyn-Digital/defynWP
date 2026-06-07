import { useOverview } from '@/lib/queries/useOverview'
import { PendingUpdatesWidget } from '@/components/overview/PendingUpdatesWidget'
import { SitesNeedingAttentionWidget } from '@/components/overview/SitesNeedingAttentionWidget'
import { RecentActivityWidget } from '@/components/overview/RecentActivityWidget'
import { formatRelativeTime } from '@/lib/formatRelativeTime'

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
      <div className="flex items-baseline justify-between">
        <h1 className="text-xl font-semibold">Overview</h1>
        <p className="text-xs text-muted-foreground">
          Last refreshed: {formatRelativeTime(data.generated_at)}
        </p>
      </div>

      <PendingUpdatesWidget counts={data.pending_updates} />

      <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
        <SitesNeedingAttentionWidget sites={data.sites_needing_attention} />
        <RecentActivityWidget events={data.recent_activity} />
      </div>
    </div>
  )
}
