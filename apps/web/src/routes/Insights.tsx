import { Link } from 'react-router-dom';
import { useInsights } from '@/lib/queries/useInsights';
import { InsightsPerformanceStrip } from '@/components/insights/InsightsPerformanceStrip';
import { InsightsPerformanceTable } from '@/components/insights/InsightsPerformanceTable';
import { InsightsAnalyticsStrip } from '@/components/insights/InsightsAnalyticsStrip';
import { InsightsAnalyticsTable } from '@/components/insights/InsightsAnalyticsTable';

export function Insights() {
  const { data, isLoading, isError } = useInsights();

  return (
    <div className="mx-auto max-w-5xl px-4 py-6">
      <div className="mb-5 flex items-baseline gap-3">
        <h1 className="text-xl font-semibold">Insights</h1>
        <Link to="/overview" className="text-sm text-zinc-600 underline-offset-4 hover:underline">← Overview</Link>
      </div>

      {isLoading && <p className="text-sm text-zinc-500">Loading…</p>}
      {isError && <p className="text-sm text-red-600">Couldn't load insights.</p>}

      {data && (
        data.performance.summary.total_sites === 0 ? (
          <p className="text-sm text-zinc-500">No sites yet</p>
        ) : (
          <div className="space-y-8">
            <section className="space-y-4">
              <h2 className="text-sm font-semibold uppercase tracking-wide text-zinc-500">Performance</h2>
              <InsightsPerformanceStrip summary={data.performance.summary} />
              <div className="rounded-lg border border-zinc-200 p-2">
                <InsightsPerformanceTable sites={data.performance.sites} />
              </div>
            </section>
            <section className="space-y-4">
              <h2 className="text-sm font-semibold uppercase tracking-wide text-zinc-500">Analytics</h2>
              <InsightsAnalyticsStrip summary={data.analytics.summary} />
              <div className="rounded-lg border border-zinc-200 p-2">
                <InsightsAnalyticsTable sites={data.analytics.sites} />
              </div>
            </section>
          </div>
        )
      )}
    </div>
  );
}

export default Insights;
