import { useInsights } from '@/lib/queries/useInsights'
import { InsightsPerformanceStrip } from '@/components/insights/InsightsPerformanceStrip'
import { InsightsPerformanceTable } from '@/components/insights/InsightsPerformanceTable'
import { InsightsAnalyticsStrip } from '@/components/insights/InsightsAnalyticsStrip'
import { InsightsAnalyticsTable } from '@/components/insights/InsightsAnalyticsTable'
import { PageHeader } from '@/components/layout/PageHeader'

export function Insights() {
  const { data, isLoading, isError } = useInsights()

  return (
    <div className="space-y-6 p-4 md:p-6">
      <PageHeader title="Insights" subtitle="Performance and analytics across your fleet" />

      {isLoading && <p className="text-sm text-muted-foreground">Loading…</p>}
      {isError && <p className="text-sm text-red-600">Couldn't load insights.</p>}

      {data &&
        (data.performance.summary.total_sites === 0 ? (
          <p className="text-sm text-muted-foreground">No sites yet.</p>
        ) : (
          <div className="space-y-8">
            <section className="space-y-4">
              <h2 className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Performance</h2>
              <InsightsPerformanceStrip summary={data.performance.summary} />
              <div className="overflow-hidden rounded-xl border border-border bg-card">
                <InsightsPerformanceTable sites={data.performance.sites} />
              </div>
            </section>
            <section className="space-y-4">
              <h2 className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Analytics</h2>
              <InsightsAnalyticsStrip summary={data.analytics.summary} />
              <div className="overflow-hidden rounded-xl border border-border bg-card">
                <InsightsAnalyticsTable sites={data.analytics.sites} />
              </div>
            </section>
          </div>
        ))}
    </div>
  )
}

export default Insights
