import { Link } from 'react-router-dom';
import type { MonitoringSite } from '@/types/api';
import { latencyTone, formatUptime, minutesSince } from '@/lib/monitoring';

const DOT: Record<string, string> = {
  active: 'bg-green-500',
  offline: 'bg-red-500',
  pending: 'bg-zinc-300',
  error: 'bg-amber-500',
};

const LAT_CLASS = { good: 'text-green-600', warn: 'text-amber-600', bad: 'text-red-600' };

export function MonitoringRow({ site }: { site: MonitoringSite }) {
  const lastCheck = site.open_incident_started_at
    ? `down ${minutesSince(site.open_incident_started_at)}m`
    : site.last_contact_at
      ? `${minutesSince(site.last_contact_at)}m ago`
      : '—';

  return (
    <tr className="border-b border-zinc-100">
      <td className="py-2 pl-1 pr-3">
        <span className={`inline-block h-2 w-2 rounded-full ${DOT[site.status] ?? 'bg-zinc-300'}`} />
      </td>
      <td className="py-2 pr-3">
        <Link to={`/sites/${site.site_id}`} className="text-zinc-900 hover:underline">{site.label}</Link>
      </td>
      <td className={`py-2 pr-3 tabular-nums ${LAT_CLASS[latencyTone(site.last_response_time_ms)]}`}>
        {site.last_response_time_ms === null ? '—' : `${site.last_response_time_ms}ms`}
      </td>
      <td className="py-2 pr-3 tabular-nums text-zinc-700">{formatUptime(site.uptime_7d)}</td>
      <td className="py-2 pr-3 tabular-nums text-zinc-700">{formatUptime(site.uptime_30d)}</td>
      <td className="py-2 pr-1 text-zinc-500">{lastCheck}</td>
    </tr>
  );
}
