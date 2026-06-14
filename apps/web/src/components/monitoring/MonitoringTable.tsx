import type { MonitoringSite } from '@/types/api';
import { MonitoringRow } from './MonitoringRow';

export function MonitoringTable({ sites }: { sites: MonitoringSite[] }) {
  return (
    <table className="w-full text-sm">
      <thead>
        <tr className="border-b border-zinc-200 text-left text-xs uppercase tracking-wide text-zinc-500">
          <th className="py-2 pl-1 pr-3 font-medium">Status</th>
          <th className="py-2 pr-3 font-medium">Site</th>
          <th className="py-2 pr-3 font-medium">Latency</th>
          <th className="py-2 pr-3 font-medium">Uptime 7d</th>
          <th className="py-2 pr-3 font-medium">Uptime 30d</th>
          <th className="py-2 pr-1 font-medium">Last check</th>
        </tr>
      </thead>
      <tbody>
        {sites.map((s) => <MonitoringRow key={s.site_id} site={s} />)}
      </tbody>
    </table>
  );
}
