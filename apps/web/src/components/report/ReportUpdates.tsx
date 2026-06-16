import type { SiteReport } from '@/types/api';

interface ReportUpdatesProps {
  updates: SiteReport['updates'];
}

// Capitalise the component type for display (plugin → Plugin).
function typeLabel(type: string): string {
  return type.charAt(0).toUpperCase() + type.slice(1);
}

// Table of every update applied in the period.
export function ReportUpdates({ updates }: ReportUpdatesProps) {
  return (
    <section className="report-section space-y-3">
      <h2 className="text-lg font-semibold">Updates applied ({updates.length})</h2>

      {updates.length === 0 ? (
        <p className="text-sm text-zinc-500">No updates applied this period.</p>
      ) : (
        <table className="w-full border-collapse text-sm">
          <thead>
            <tr className="border-b text-left text-xs uppercase tracking-wide text-zinc-500">
              <th className="py-2 pr-3 font-medium">Component</th>
              <th className="py-2 pr-3 font-medium">Type</th>
              <th className="py-2 pr-3 font-medium">Version</th>
              <th className="py-2 font-medium">Applied</th>
            </tr>
          </thead>
          <tbody>
            {updates.map((u) => (
              <tr key={`${u.type}|${u.slug}|${u.applied_at}`} className="border-b last:border-b-0">
                <td className="py-2 pr-3 font-medium text-zinc-900">{u.component_name}</td>
                <td className="py-2 pr-3 text-zinc-600">{typeLabel(u.type)}</td>
                <td className="py-2 pr-3 text-zinc-700">
                  <span>{u.previous_version}</span>
                  <span className="text-zinc-400"> → </span>
                  <span className="font-medium">{u.new_version}</span>
                </td>
                <td className="py-2 text-zinc-600">{u.applied_at}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}
