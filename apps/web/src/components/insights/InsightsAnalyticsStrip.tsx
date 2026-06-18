import type { Insights } from '@/types/api';

interface Props {
  summary: Insights['analytics']['summary'];
}

export function InsightsAnalyticsStrip({ summary }: Props) {
  const tiles = [
    { label: 'Total sessions', value: summary.total_sessions.toLocaleString(), testid: 'kpi-total-sessions' },
    { label: 'Total users', value: summary.total_users.toLocaleString(), testid: 'kpi-total-users' },
    { label: 'Connected', value: `${summary.connected}/${summary.total_sites}`, testid: 'kpi-connected' },
  ];
  return (
    <div className="grid grid-cols-2 gap-3 md:grid-cols-3">
      {tiles.map((t) => (
        <div key={t.label} className="rounded-lg border border-zinc-200 p-4">
          <div data-testid={t.testid} className="text-2xl font-semibold text-zinc-900">{t.value}</div>
          <div className="mt-1 text-xs uppercase tracking-wide text-zinc-500">{t.label}</div>
        </div>
      ))}
    </div>
  );
}
