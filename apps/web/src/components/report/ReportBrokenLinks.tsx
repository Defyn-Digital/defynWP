import { Unlink } from 'lucide-react';
import type { ReportBrokenLinks } from '@/types/api';

interface ReportBrokenLinksProps {
  broken_links: ReportBrokenLinks;
}

// Mirror of SiteSecurityPanel / ReportSecurity styling approach.
const SEVERITY_CLASS: Record<'broken' | 'warning', string> = {
  broken: 'text-destructive font-semibold',
  warning: 'text-amber-600 font-semibold',
};

// Badge variant helpers — mirrors ReportSecurity's inline badge pattern.
function StatusBadge({
  state,
  counts,
}: {
  state: ReportBrokenLinks['state'];
  counts: ReportBrokenLinks['counts'];
}) {
  if (state === 'issues') {
    return (
      <span className="ml-auto inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-destructive/10 text-destructive">
        {counts.broken} broken · {counts.warning} warnings
      </span>
    );
  }
  if (state === 'clean') {
    return (
      <span className="ml-auto inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-success/10 text-success">
        Clear
      </span>
    );
  }
  // not_checked
  return (
    <span className="ml-auto inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-muted text-muted-foreground">
      Not checked
    </span>
  );
}

// Read-only table of broken-link items (no dismiss/restore controls).
function LinksTable({ items }: { items: ReportBrokenLinks['items'] }) {
  return (
    <table className="w-full border-collapse text-sm">
      <thead>
        <tr className="border-b border-border text-left text-xs uppercase tracking-wide text-muted-foreground">
          <th className="py-2 pr-3 font-medium">Severity</th>
          <th className="py-2 pr-3 font-medium">Status</th>
          <th className="py-2 pr-3 font-medium">Link</th>
          <th className="py-2 font-medium">On page</th>
        </tr>
      </thead>
      <tbody>
        {items.map((item, i) => (
          <tr
            key={`${item.url}|${item.source_url}|${i}`}
            className="border-b border-border last:border-0"
          >
            <td className={`py-2 pr-3 ${SEVERITY_CLASS[item.severity]}`}>
              {item.severity}
            </td>
            <td className="py-2 pr-3 text-muted-foreground">
              {item.status_code !== null ? item.status_code : '—'}
            </td>
            <td className="py-2 pr-3 text-foreground font-mono text-xs break-all">
              {item.url}
            </td>
            <td className="py-2 text-muted-foreground font-mono text-xs break-all">
              {item.source_url}
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}

// Broken-link section of the client maintenance report.
export function ReportBrokenLinks({ broken_links }: ReportBrokenLinksProps) {
  const { state, counts, items } = broken_links;

  return (
    <section className="report-section rounded-lg border border-border bg-card p-5">
      <div className="mb-4 flex items-center gap-2">
        <Unlink className="h-4 w-4 text-primary" />
        <h2 className="text-base font-semibold text-foreground">Broken links</h2>
        <StatusBadge state={state} counts={counts} />
      </div>

      {state === 'not_checked' && (
        <p className="text-sm text-muted-foreground">Not checked yet</p>
      )}

      {state === 'clean' && (
        <p className="text-sm text-muted-foreground">No broken links found.</p>
      )}

      {state === 'issues' && (
        <div className="space-y-4">
          <p className="text-sm text-muted-foreground">
            <span className="text-destructive font-medium">{counts.broken} broken</span>
            {' · '}
            <span className="text-amber-600 font-medium">{counts.warning} warnings</span>
            {' · '}
            {counts.total} total
          </p>
          <LinksTable items={items} />
        </div>
      )}
    </section>
  );
}
