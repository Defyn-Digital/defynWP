import { LayoutDashboard } from 'lucide-react';
import type { SiteReport } from '@/types/api';

interface ReportOverviewProps {
  overview: SiteReport['overview'];
}

interface StatProps {
  label: string;
  value: string | number;
  valueClass?: string;
}

function Stat({ label, value, valueClass }: StatProps) {
  return (
    <div className="rounded-md bg-muted/50 p-3">
      <p className="text-xs uppercase tracking-wide text-muted-foreground">{label}</p>
      <p className={`mt-1 text-2xl font-semibold ${valueClass ?? 'text-foreground'}`}>{value}</p>
    </div>
  );
}

// Health colour for the uptime headline: 99.9%+ healthy, 95%+ warning, below poor.
function uptimeClass(percent: number): string {
  if (percent >= 99.9) return 'text-success';
  if (percent >= 95) return 'text-warning';
  return 'text-destructive';
}

// Open findings: 0 is healthy, anything above is a destructive alert.
function findingsClass(count: number): string {
  return count === 0 ? 'text-success' : 'text-destructive';
}

// Four headline stat cards summarising the period at a glance.
export function ReportOverview({ overview }: ReportOverviewProps) {
  return (
    <section className="report-section rounded-lg border border-border bg-card p-5">
      <div className="mb-4 flex items-center gap-2">
        <LayoutDashboard className="h-4 w-4 text-primary" />
        <h2 className="text-base font-semibold text-foreground">Overview</h2>
      </div>
      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <Stat label="Updates applied" value={overview.updates_applied} />
        <Stat
          label="Uptime"
          value={`${overview.uptime_range_percent}%`}
          valueClass={uptimeClass(overview.uptime_range_percent)}
        />
        <Stat
          label="Open findings"
          value={overview.open_findings}
          valueClass={findingsClass(overview.open_findings)}
        />
        <Stat label="WordPress" value={overview.wp_version} />
      </div>
    </section>
  );
}
