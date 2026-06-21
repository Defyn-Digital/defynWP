import { Activity } from 'lucide-react';
import type { SiteReport } from '@/types/api';

interface ReportUptimeProps {
  uptime: SiteReport['uptime'];
}

// Human-readable downtime length from a raw second count.
function formatDuration(seconds: number | null): string {
  if (seconds === null || seconds <= 0) return '—';
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  const s = seconds % 60;
  const parts: string[] = [];
  if (h > 0) parts.push(`${h}h`);
  if (m > 0) parts.push(`${m}m`);
  if (h === 0 && s > 0) parts.push(`${s}s`);
  return parts.length > 0 ? parts.join(' ') : '0s';
}

interface CardProps {
  label: string;
  percent: number;
}

function UptimeCard({ label, percent }: CardProps) {
  return (
    <div className="rounded-md bg-muted/50 p-3">
      <p className="text-xs uppercase tracking-wide text-muted-foreground">{label}</p>
      <p className="mt-1 text-xl font-semibold text-foreground">{percent}%</p>
    </div>
  );
}

// Uptime headline + per-window cards + incident list.
export function ReportUptime({ uptime }: ReportUptimeProps) {
  const hasIncidents = uptime.incidents.length > 0;

  return (
    <section className="report-section rounded-lg border border-border bg-card p-5">
      <div className="mb-4 flex items-center gap-2">
        <Activity className="h-4 w-4 text-primary" />
        <h2 className="text-base font-semibold text-foreground">Uptime &amp; availability</h2>
        <span
          className={`ml-auto inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${
            hasIncidents ? 'bg-warning/10 text-warning' : 'bg-success/10 text-success'
          }`}
        >
          {hasIncidents ? 'Incidents' : 'Healthy'}
        </span>
      </div>

      <p className="mb-3 text-3xl font-semibold text-foreground">{uptime.range_percent}%</p>

      <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
        <UptimeCard label="Last 24h" percent={uptime.last_24h_percent} />
        <UptimeCard label="Last 7 days" percent={uptime.last_7d_percent} />
        <UptimeCard label="Last 30 days" percent={uptime.last_30d_percent} />
      </div>

      {uptime.incidents.length === 0 ? (
        <p className="mt-3 text-sm text-muted-foreground">No downtime this period.</p>
      ) : (
        <ul className="mt-3">
          {uptime.incidents.map((incident, i) => (
            <li
              key={`${incident.started_at}|${i}`}
              className="flex flex-wrap items-baseline gap-x-2 border-b border-border py-2 text-sm last:border-0"
            >
              <span className="font-medium text-foreground">
                {incident.reason ?? 'Downtime'}
              </span>
              <span className="text-foreground">{formatDuration(incident.duration_seconds)}</span>
              <span className="text-muted-foreground">{incident.started_at}</span>
              {incident.ongoing && (
                <span className="rounded-full bg-warning/10 px-2 py-0.5 text-xs font-medium text-warning">
                  ongoing
                </span>
              )}
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
