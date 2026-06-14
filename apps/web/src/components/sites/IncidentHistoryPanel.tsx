import { useSiteIncidents } from '@/lib/queries/useSiteIncidents';
import type { Incident } from '@/types/api';

function humanizeDuration(seconds: number | null): string {
  if (seconds === null) return '—';
  if (seconds < 60) return `${seconds}s`;
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m`;
  return `${Math.floor(seconds / 3600)}h ${Math.floor((seconds % 3600) / 60)}m`;
}

interface Props {
  siteId: number;
}

function IncidentRow({ incident }: { incident: Incident }) {
  if (incident.ended_at === null) {
    return (
      <li className="py-2 flex items-start gap-2 border-b last:border-b-0">
        <span className="text-red-700 font-medium text-sm">
          Ongoing — started {incident.started_at}
        </span>
      </li>
    );
  }

  return (
    <li className="py-2 text-sm border-b last:border-b-0 text-zinc-700">
      {incident.started_at} → {incident.ended_at}{' '}
      <span className="text-zinc-500">· {humanizeDuration(incident.duration_seconds)}</span>
    </li>
  );
}

export function IncidentHistoryPanel({ siteId }: Props) {
  const { data, isLoading } = useSiteIncidents(siteId);

  return (
    <section className="space-y-3 border-t pt-4">
      <header>
        <h3 className="text-lg font-semibold">Incident history</h3>
      </header>

      {isLoading && (
        <p className="text-sm text-muted-foreground">Loading…</p>
      )}

      {!isLoading && data && data.length === 0 && (
        <p className="text-sm text-zinc-600">No incidents recorded.</p>
      )}

      {!isLoading && data && data.length > 0 && (
        <ul className="w-full">
          {data.map((incident) => (
            <IncidentRow key={incident.id} incident={incident} />
          ))}
        </ul>
      )}
    </section>
  );
}
