import { Link } from 'react-router-dom';
import type { AnalyticsFleetRow } from '@/types/api';
import { formatEngagement } from '@/lib/engagement';

function num(n: number | null): string {
  return n === null ? '—' : n.toLocaleString();
}

function Row({ site }: { site: AnalyticsFleetRow }) {
  const connected = site.ga4_property_id !== null && site.ga4_property_id !== '';
  const hasData = site.fetched_at !== null;
  return (
    <tr className="border-b border-zinc-100">
      <td className="py-2 pr-3">
        <Link to={`/sites/${site.site_id}`} className="font-medium text-zinc-900 hover:underline">{site.label}</Link>
        <div className="text-xs text-zinc-400">{site.url}</div>
      </td>
      {connected && hasData ? (
        <>
          <td className="py-2 pr-3 tabular-nums">{num(site.sessions)}</td>
          <td className="py-2 pr-3 tabular-nums">{num(site.total_users)}</td>
          <td className="py-2 pr-3 tabular-nums">{num(site.screen_page_views)}</td>
          <td className="py-2 pr-3 tabular-nums">{site.avg_session_duration === null ? '—' : formatEngagement(site.avg_session_duration)}</td>
          <td className="py-2 pr-1 text-sm text-zinc-500">{site.period_start?.slice(0, 7) ?? '—'}</td>
        </>
      ) : (
        <>
          <td className="py-2 pr-3 text-sm italic text-zinc-400" colSpan={4}>
            {connected ? 'Connected — no data yet' : 'Not connected — add a GA4 Property ID'}
          </td>
          <td className="py-2 pr-1 text-zinc-400">—</td>
        </>
      )}
    </tr>
  );
}

export function InsightsAnalyticsTable({ sites }: { sites: AnalyticsFleetRow[] }) {
  return (
    <table className="w-full text-sm">
      <thead>
        <tr className="border-b border-zinc-200 text-left text-xs uppercase tracking-wide text-zinc-500">
          <th className="py-2 pl-1 pr-3 font-medium">Site</th>
          <th className="py-2 pr-3 font-medium">Sessions</th>
          <th className="py-2 pr-3 font-medium">Users</th>
          <th className="py-2 pr-3 font-medium">Views</th>
          <th className="py-2 pr-3 font-medium">Avg engmt</th>
          <th className="py-2 pr-1 font-medium">Period</th>
        </tr>
      </thead>
      <tbody>
        {sites.map((s) => <Row key={s.site_id} site={s} />)}
      </tbody>
    </table>
  );
}
