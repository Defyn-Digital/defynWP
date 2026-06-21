import { RefreshCw } from 'lucide-react';
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
    <section className="report-section rounded-lg border border-border bg-card p-5">
      <div className="mb-4 flex items-center gap-2">
        <RefreshCw className="h-4 w-4 text-primary" />
        <h2 className="text-base font-semibold text-foreground">Updates applied ({updates.length})</h2>
      </div>

      {updates.length === 0 ? (
        <p className="text-sm text-muted-foreground">No updates applied this period.</p>
      ) : (
        <table className="w-full border-collapse text-sm">
          <thead>
            <tr className="border-b border-border text-left text-xs uppercase tracking-wide text-muted-foreground">
              <th className="py-2 pr-3 font-medium">Component</th>
              <th className="py-2 pr-3 font-medium">Type</th>
              <th className="py-2 pr-3 font-medium">Version</th>
              <th className="py-2 font-medium">Applied</th>
            </tr>
          </thead>
          <tbody>
            {updates.map((u) => (
              <tr key={`${u.type}|${u.slug}|${u.applied_at}`} className="border-b border-border last:border-0">
                <td className="py-2 pr-3 font-medium text-foreground">{u.component_name}</td>
                <td className="py-2 pr-3 text-muted-foreground">{typeLabel(u.type)}</td>
                <td className="py-2 pr-3 text-foreground">
                  <span>{u.previous_version}</span>
                  <span className="text-muted-foreground"> → </span>
                  <span className="font-medium">{u.new_version}</span>
                </td>
                <td className="py-2 text-muted-foreground">{u.applied_at}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}
