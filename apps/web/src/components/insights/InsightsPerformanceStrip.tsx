import type { Insights } from '@/types/api';

interface Props {
  summary: Insights['performance']['summary'];
}

export function InsightsPerformanceStrip({ summary }: Props) {
  const tiles = [
    { label: 'Avg mobile', value: summary.avg_mobile === null ? '—' : String(summary.avg_mobile), tone: 'text-zinc-900', testid: 'kpi-avg-mobile' },
    { label: 'Avg desktop', value: summary.avg_desktop === null ? '—' : String(summary.avg_desktop), tone: 'text-zinc-900', testid: 'kpi-avg-desktop' },
    { label: 'Slow sites', value: String(summary.slow_sites), tone: summary.slow_sites > 0 ? 'text-red-600' : 'text-zinc-900', testid: 'kpi-slow-sites' },
    { label: 'Measured', value: `${summary.measured}/${summary.total_sites}`, tone: 'text-zinc-900', testid: 'kpi-measured' },
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
