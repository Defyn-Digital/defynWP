import type { Security } from '@/types/api';

interface Props {
  summary: Security['summary'];
}

export function SecuritySummaryStrip({ summary }: Props) {
  const tiles = [
    {
      label: 'Sites at risk',
      value: String(summary.sites_at_risk),
      tone: summary.sites_at_risk > 0 ? 'text-red-600' : 'text-zinc-900',
      testid: 'kpi-at-risk',
    },
    {
      label: 'Critical',
      value: String(summary.critical),
      tone: summary.critical > 0 ? 'text-red-600' : 'text-zinc-900',
      testid: 'kpi-critical',
    },
    {
      label: 'High',
      value: String(summary.high),
      tone: summary.high > 0 ? 'text-red-600' : 'text-zinc-900',
      testid: 'kpi-high',
    },
    {
      label: 'Scanned',
      value: `${summary.scanned_sites}/${summary.total_sites}`,
      tone: 'text-zinc-900',
      testid: 'kpi-scanned',
    },
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
