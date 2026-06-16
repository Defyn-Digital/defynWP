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
    <div className="rounded-md border bg-white px-4 py-3">
      <p className="text-xl font-semibold text-zinc-900">{percent}%</p>
      <p className="mt-1 text-xs uppercase tracking-wide text-zinc-500">{label}</p>
    </div>
  );
}

// Uptime headline + per-window cards + incident list.
export function ReportUptime({ uptime }: ReportUptimeProps) {
  return (
    <section className="report-section space-y-3">
      <h2 className="text-lg font-semibold">Uptime</h2>

      <p className="text-3xl font-semibold text-zinc-900">{uptime.range_percent}%</p>

      <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
        <UptimeCard label="Last 24h" percent={uptime.last_24h_percent} />
        <UptimeCard label="Last 7 days" percent={uptime.last_7d_percent} />
        <UptimeCard label="Last 30 days" percent={uptime.last_30d_percent} />
      </div>

      {uptime.incidents.length === 0 ? (
        <p className="text-sm text-zinc-500">No downtime this period.</p>
      ) : (
        <ul className="space-y-2">
          {uptime.incidents.map((incident, i) => (
            <li
              key={`${incident.started_at}|${i}`}
              className="flex flex-wrap items-baseline gap-x-2 border-b py-2 text-sm last:border-b-0"
            >
              <span className="font-medium text-zinc-900">
                {incident.reason ?? 'Downtime'}
              </span>
              <span className="text-zinc-600">{formatDuration(incident.duration_seconds)}</span>
              <span className="text-zinc-500">{incident.started_at}</span>
              {incident.ongoing && (
                <span className="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700">
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
