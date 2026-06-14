import type { OpenIncident } from '@/types/api';

interface OpenIncidentsWidgetProps {
  openIncidents: OpenIncident[];
}

export function OpenIncidentsWidget({ openIncidents }: OpenIncidentsWidgetProps) {
  if (openIncidents.length === 0) {
    return null;
  }

  const n = openIncidents.length;
  const heading = `${n} site${n === 1 ? '' : 's'} down`;

  return (
    <div className="rounded-md border border-red-300 bg-red-50 p-4 text-red-800">
      <h3 className="mb-3 text-sm font-semibold">{heading}</h3>
      <ul className="divide-y divide-red-200">
        {openIncidents.map((incident) => (
          <li key={incident.site_id} className="py-2 text-sm">
            <span className="font-medium">{incident.site_label}</span>
            {' — down since '}
            {incident.started_at}
          </li>
        ))}
      </ul>
    </div>
  );
}
