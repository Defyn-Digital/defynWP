import type { Report } from '@/types/api';

interface ReportOverviewProps {
  overview: Report['overview'];
}

interface StatProps {
  label: string;
  value: string | number;
}

function Stat({ label, value }: StatProps) {
  return (
    <div className="rounded-md border bg-white px-4 py-3">
      <p className="text-2xl font-semibold text-zinc-900">{value}</p>
      <p className="mt-1 text-xs uppercase tracking-wide text-zinc-500">{label}</p>
    </div>
  );
}

// Four headline stat cards summarising the period at a glance.
export function ReportOverview({ overview }: ReportOverviewProps) {
  return (
    <section className="report-section space-y-3">
      <h2 className="text-lg font-semibold">Overview</h2>
      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <Stat label="Updates applied" value={overview.updates_applied} />
        <Stat label="Uptime" value={`${overview.uptime_range_percent}%`} />
        <Stat label="Open findings" value={overview.open_findings} />
        <Stat label="WordPress" value={overview.wp_version} />
      </div>
    </section>
  );
}
