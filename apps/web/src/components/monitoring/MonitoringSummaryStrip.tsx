import type { Monitoring } from '@/types/api';
import { formatUptime } from '@/lib/monitoring';

interface Props {
  summary: Monitoring['summary'];
}

export function MonitoringSummaryStrip({ summary }: Props) {
  const tiles = [
    { label: 'Up', value: String(summary.up), tone: 'text-zinc-900', testid: 'kpi-up' },
    { label: 'Down', value: String(summary.down), tone: summary.down > 0 ? 'text-red-600' : 'text-zinc-900', testid: 'kpi-down' },
    { label: 'Fleet 30d', value: summary.fleet_uptime_30d === null ? '—' : formatUptime(summary.fleet_uptime_30d), tone: 'text-zinc-900', testid: 'kpi-fleet' },
    { label: 'Slowest', value: summary.slowest_ms === null ? '—' : `${summary.slowest_ms}ms`, tone: 'text-zinc-900', testid: 'kpi-slowest' },
  ];

  return (
    <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
      {tiles.map((t) => (
        <div key={t.label} className="rounded-lg border border-zinc-200 p-4">
          <div data-testid={t.testid} className={`text-2xl font-semibold ${t.tone}`}>{t.value}</div>
          <div className="mt-1 text-xs uppercase tracking-wide text-zinc-500">{t.label}</div>
        </div>
      ))}
    </div>
  );
}
