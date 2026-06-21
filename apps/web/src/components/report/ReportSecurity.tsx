import { ShieldCheck } from 'lucide-react';
import type { SiteReport, Vulnerability } from '@/types/api';

interface ReportSecurityProps {
  security: SiteReport['security'];
}

// Mirror of SiteSecurityPanel's severity ordering + label colour classes so the
// report reads consistently with the live site panel.
const SEVERITY_ORDER = ['critical', 'high', 'medium', 'low', 'unknown'] as const;
type Severity = (typeof SEVERITY_ORDER)[number];

const SEVERITY_LABEL_CLASS: Record<Severity, string> = {
  critical: 'text-destructive font-semibold',
  high: 'text-destructive font-semibold',
  medium: 'text-warning font-semibold',
  low: 'text-muted-foreground font-semibold',
  unknown: 'text-muted-foreground font-semibold',
};

function groupBySeverity(vulns: Vulnerability[]): Record<Severity, Vulnerability[]> {
  const groups: Record<Severity, Vulnerability[]> = {
    critical: [],
    high: [],
    medium: [],
    low: [],
    unknown: [],
  };
  for (const v of vulns) groups[v.severity].push(v);
  return groups;
}

function SeverityGroup({ severity, vulns }: { severity: Severity; vulns: Vulnerability[] }) {
  if (vulns.length === 0) return null;
  return (
    <div className="mb-3">
      <p className={`mb-1 text-xs uppercase tracking-wide ${SEVERITY_LABEL_CLASS[severity]}`}>
        {severity}
      </p>
      <ul className="w-full">
        {vulns.map((v) => (
          <li
            key={`${v.type}|${v.slug}|${v.source_id}`}
            className="flex flex-wrap items-baseline gap-x-2 border-b border-border py-2 text-sm text-foreground last:border-0"
          >
            <span className="font-medium">{v.component_name}</span>
            <span className="text-xs text-muted-foreground">({v.type})</span>
            <span className="text-muted-foreground">{v.installed_version}</span>
            {v.fixed_in && <span className="text-xs text-muted-foreground">→ fix {v.fixed_in}</span>}
            {v.cve && <span className="font-mono text-xs text-muted-foreground">{v.cve}</span>}
          </li>
        ))}
      </ul>
    </div>
  );
}

// Last-scan line + open findings grouped by severity + scan-history mini table.
export function ReportSecurity({ security }: ReportSecurityProps) {
  const grouped = groupBySeverity(security.open_findings);

  const hasFindings = security.open_findings.length > 0;

  return (
    <section className="report-section rounded-lg border border-border bg-card p-5">
      <div className="mb-4 flex items-center gap-2">
        <ShieldCheck className="h-4 w-4 text-primary" />
        <h2 className="text-base font-semibold text-foreground">Security</h2>
        <span
          className={`ml-auto inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${
            hasFindings ? 'bg-destructive/10 text-destructive' : 'bg-success/10 text-success'
          }`}
        >
          {hasFindings ? `${security.open_findings.length} open` : 'Clear'}
        </span>
      </div>

      <p className="mb-3 text-sm text-muted-foreground">
        {security.last_scan_at === null
          ? 'Never scanned'
          : `Last scan: ${security.last_scan_at}`}
      </p>

      {security.open_findings.length === 0 ? (
        <p className="text-sm text-muted-foreground">No open findings.</p>
      ) : (
        <div>
          {SEVERITY_ORDER.map((sev) => (
            <SeverityGroup key={sev} severity={sev} vulns={grouped[sev]} />
          ))}
        </div>
      )}

      {security.scans.length > 0 && (
        <div className="mt-4 space-y-2">
          <h3 className="text-sm font-semibold text-foreground">Scan history</h3>
          <table className="w-full border-collapse text-sm">
            <thead>
              <tr className="border-b border-border text-left text-xs uppercase tracking-wide text-muted-foreground">
                <th className="py-2 pr-3 font-medium">Date</th>
                <th className="py-2 pr-3 font-medium">Total</th>
                <th className="py-2 pr-3 font-medium">Critical</th>
                <th className="py-2 pr-3 font-medium">High</th>
                <th className="py-2 pr-3 font-medium">Medium</th>
                <th className="py-2 font-medium">Low</th>
              </tr>
            </thead>
            <tbody>
              {security.scans.map((scan, i) => (
                <tr key={`${scan.scanned_at}|${i}`} className="border-b border-border last:border-0">
                  <td className="py-2 pr-3 text-foreground">{scan.scanned_at}</td>
                  <td className="py-2 pr-3 text-foreground">{scan.total}</td>
                  <td className="py-2 pr-3 text-foreground">{scan.critical}</td>
                  <td className="py-2 pr-3 text-foreground">{scan.high}</td>
                  <td className="py-2 pr-3 text-foreground">{scan.medium}</td>
                  <td className="py-2 text-foreground">{scan.low}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}
