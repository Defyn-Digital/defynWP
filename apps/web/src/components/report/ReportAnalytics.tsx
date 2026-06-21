import { BarChart3 } from 'lucide-react';
import { formatEngagement } from '@/lib/engagement';
import type { ReportAnalyticsData } from '@/types/api';
import { AnalyticsTrendSparkline } from '@/components/report/AnalyticsTrendSparkline';

interface ReportAnalyticsProps {
  analytics: ReportAnalyticsData;
}

function KpiBlock({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-md bg-muted/50 p-3 text-center">
      <p className="text-xs uppercase tracking-wide text-muted-foreground">{label}</p>
      <p className="mt-1 text-2xl font-semibold text-foreground">{value}</p>
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
  const { state, totals, top_pages, channels, history } = analytics;

  return (
    <section className="report-section rounded-lg border border-border bg-card p-5">
      <div className="mb-4 flex items-center gap-2">
        <BarChart3 className="h-4 w-4 text-primary" />
        <h2 className="text-base font-semibold text-foreground">Analytics</h2>
      </div>

      {state === 'not_connected' && (
        <p className="text-sm text-muted-foreground">Analytics not connected.</p>
      )}

      {state === 'pending' && (
        <p className="text-sm text-muted-foreground">Analytics not yet available for this period.</p>
      )}

      {state === 'ready' && (
        <div className="space-y-4">
          <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
            <KpiBlock label="Sessions" value={kpiValue(totals?.sessions ?? null)} />
            <KpiBlock label="Users" value={kpiValue(totals?.users ?? null)} />
            <KpiBlock label="Pageviews" value={kpiValue(totals?.pageviews ?? null)} />
            <KpiBlock label="Avg engaged" value={formatEngagement(totals?.avg_engagement_seconds ?? 0)} />
          </div>

          <AnalyticsTrendSparkline history={history} />

          <div className="space-y-2">
            <h3 className="text-sm font-semibold text-foreground">Top pages</h3>
            {top_pages.length === 0 ? (
              <p className="text-sm text-muted-foreground">No page data for this period.</p>
            ) : (
              <table className="w-full border-collapse text-sm">
                <thead>
                  <tr className="border-b border-border text-left text-xs uppercase tracking-wide text-muted-foreground">
                    <th className="py-2 pr-3 font-medium">Page</th>
                    <th className="py-2 pr-3 font-medium">Path</th>
                    <th className="py-2 font-medium">Views</th>
                  </tr>
                </thead>
                <tbody>
                  {top_pages.map((page, i) => (
                    <tr key={`${page.path}|${i}`} className="border-b border-border last:border-0">
                      <td className="py-2 pr-3 text-foreground">{page.title}</td>
                      <td className="py-2 pr-3 text-muted-foreground">{page.path}</td>
                      <td className="py-2 text-foreground">{page.views.toLocaleString()}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>

          <div className="space-y-2">
            <h3 className="text-sm font-semibold text-foreground">Channels</h3>
            {channels.length === 0 ? (
              <p className="text-sm text-muted-foreground">No channel data for this period.</p>
            ) : (
              <table className="w-full border-collapse text-sm">
                <thead>
                  <tr className="border-b border-border text-left text-xs uppercase tracking-wide text-muted-foreground">
                    <th className="py-2 pr-3 font-medium">Channel</th>
                    <th className="py-2 font-medium">Sessions</th>
                  </tr>
                </thead>
                <tbody>
                  {channels.map((channel, i) => (
                    <tr key={`${channel.channel}|${i}`} className="border-b border-border last:border-0">
                      <td className="py-2 pr-3 text-foreground">{channel.channel}</td>
                      <td className="py-2 text-foreground">{channel.sessions.toLocaleString()}</td>
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
