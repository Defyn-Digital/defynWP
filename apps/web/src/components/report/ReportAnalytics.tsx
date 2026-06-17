import { formatEngagement } from '@/lib/engagement';
import type { ReportAnalyticsData } from '@/types/api';

interface ReportAnalyticsProps {
  analytics: ReportAnalyticsData;
}

function KpiBlock({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-md border px-4 py-3 text-center">
      <p className="text-xs uppercase tracking-wide text-zinc-500">{label}</p>
      <p className="text-2xl font-semibold text-zinc-900">{value}</p>
    </div>
  );
}

function kpiValue(value: number | null): string {
  return value === null ? '—' : value.toLocaleString();
}

// GA4 traffic summary: KPI strip (sessions/users/pageviews/avg engaged) + top
// pages and acquisition-channel tables. Three states: not_connected, pending,
// ready — mirroring ReportPerformance's section shell/styling.
export function ReportAnalytics({ analytics }: ReportAnalyticsProps) {
  const { state, totals, top_pages, channels } = analytics;

  return (
    <section className="report-section space-y-3">
      <h2 className="text-lg font-semibold">Analytics</h2>

      {state === 'not_connected' && (
        <p className="text-sm text-zinc-500">Analytics not connected.</p>
      )}

      {state === 'pending' && (
        <p className="text-sm text-zinc-500">Analytics not yet available for this period.</p>
      )}

      {state === 'ready' && (
        <div className="space-y-4">
          <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
            <KpiBlock label="Sessions" value={kpiValue(totals?.sessions ?? null)} />
            <KpiBlock label="Users" value={kpiValue(totals?.users ?? null)} />
            <KpiBlock label="Pageviews" value={kpiValue(totals?.pageviews ?? null)} />
            <KpiBlock label="Avg engaged" value={formatEngagement(totals?.avg_engagement_seconds ?? 0)} />
          </div>

          <div className="space-y-2">
            <h3 className="text-sm font-semibold text-zinc-700">Top pages</h3>
            {top_pages.length === 0 ? (
              <p className="text-sm text-zinc-500">No page data for this period.</p>
            ) : (
              <table className="w-full border-collapse text-sm">
                <thead>
                  <tr className="border-b text-left text-xs uppercase tracking-wide text-zinc-500">
                    <th className="py-2 pr-3 font-medium">Page</th>
                    <th className="py-2 pr-3 font-medium">Path</th>
                    <th className="py-2 font-medium">Views</th>
                  </tr>
                </thead>
                <tbody>
                  {top_pages.map((page, i) => (
                    <tr key={`${page.path}|${i}`} className="border-b last:border-b-0">
                      <td className="py-2 pr-3 text-zinc-700">{page.title}</td>
                      <td className="py-2 pr-3 text-zinc-500">{page.path}</td>
                      <td className="py-2 text-zinc-700">{page.views.toLocaleString()}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>

          <div className="space-y-2">
            <h3 className="text-sm font-semibold text-zinc-700">Channels</h3>
            {channels.length === 0 ? (
              <p className="text-sm text-zinc-500">No channel data for this period.</p>
            ) : (
              <table className="w-full border-collapse text-sm">
                <thead>
                  <tr className="border-b text-left text-xs uppercase tracking-wide text-zinc-500">
                    <th className="py-2 pr-3 font-medium">Channel</th>
                    <th className="py-2 font-medium">Sessions</th>
                  </tr>
                </thead>
                <tbody>
                  {channels.map((channel, i) => (
                    <tr key={`${channel.channel}|${i}`} className="border-b last:border-b-0">
                      <td className="py-2 pr-3 text-zinc-700">{channel.channel}</td>
                      <td className="py-2 text-zinc-700">{channel.sessions.toLocaleString()}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        </div>
      )}
    </section>
  );
}
