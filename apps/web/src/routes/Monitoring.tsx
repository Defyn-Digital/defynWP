import { useMonitoring } from '@/lib/queries/useMonitoring'
import { MonitoringSummaryStrip } from '@/components/monitoring/MonitoringSummaryStrip'
import { MonitoringTable } from '@/components/monitoring/MonitoringTable'
import { PageHeader } from '@/components/layout/PageHeader'

export function Monitoring() {
  const { data, isLoading, isError } = useMonitoring()

  return (
    <div className="space-y-6 p-4 md:p-6">
      <PageHeader title="Monitoring" subtitle="Uptime and incidents across your fleet" />

      {isLoading && <p className="text-sm text-muted-foreground">Loading…</p>}
      {isError && <p className="text-sm text-red-600">Couldn't load monitoring data.</p>}

      {data &&
        (data.sites.length === 0 ? (
          <p className="text-sm text-muted-foreground">No sites yet.</p>
        ) : (
          <div className="space-y-5">
            <MonitoringSummaryStrip summary={data.summary} />
            <div className="overflow-hidden rounded-xl border border-border bg-card">
              <MonitoringTable sites={data.sites} />
            </div>
          </div>
        ))}
    </div>
  )
}

export default Monitoring
