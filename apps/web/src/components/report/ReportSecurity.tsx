import type { Report, Vulnerability } from '@/types/api';

interface ReportSecurityProps {
  security: Report['security'];
}

// Mirror of SiteSecurityPanel's severity ordering + label colour classes so the
// report reads consistently with the live site panel.
const SEVERITY_ORDER = ['critical', 'high', 'medium', 'low', 'unknown'] as const;
type Severity = (typeof SEVERITY_ORDER)[number];

const SEVERITY_LABEL_CLASS: Record<Severity, string> = {
  critical: 'text-red-700 font-semibold',
  high: 'text-red-600 font-semibold',
  medium: 'text-amber-600 font-semibold',
  low: 'text-slate-500 font-semibold',
  unknown: 'text-slate-400 font-semibold',
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
            className="flex flex-wrap items-baseline gap-x-2 border-b py-2 text-sm text-zinc-800 last:border-b-0"
          >
            <span className="font-medium">{v.component_name}</span>
            <span className="text-xs text-zinc-500">({v.type})</span>
            <span className="text-zinc-600">{v.installed_version}</span>
            {v.fixed_in && <span className="text-xs text-zinc-500">→ fix {v.fixed_in}</span>}
            {v.cve && <span className="font-mono text-xs text-zinc-400">{v.cve}</span>}
          </li>
        ))}
      </ul>
    </div>
  );
}

// Last-scan line + open findings grouped by severity + scan-history mini table.
export function ReportSecurity({ security }: ReportSecurityProps) {
  const grouped = groupBySeverity(security.open_findings);

  return (
    <section className="report-section space-y-3">
      <h2 className="text-lg font-semibold">Security</h2>

      <p className="text-sm text-zinc-600">
        {security.last_scan_at === null
          ? 'Never scanned'
          : `Last scan: ${security.last_scan_at}`}
      </p>

      {security.open_findings.length === 0 ? (
        <p className="text-sm text-zinc-500">No open findings.</p>
      ) : (
        <div>
          {SEVERITY_ORDER.map((sev) => (
            <SeverityGroup key={sev} severity={sev} vulns={grouped[sev]} />
          ))}
        </div>
      )}

      {security.scans.length > 0 && (
        <div className="space-y-2">
          <h3 className="text-sm font-semibold text-zinc-700">Scan history</h3>
          <table className="w-full border-collapse text-sm">
            <thead>
              <tr className="border-b text-left text-xs uppercase tracking-wide text-zinc-500">
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
                <tr key={`${scan.scanned_at}|${i}`} className="border-b last:border-b-0">
                  <td className="py-2 pr-3 text-zinc-700">{scan.scanned_at}</td>
                  <td className="py-2 pr-3 text-zinc-700">{scan.total}</td>
                  <td className="py-2 pr-3 text-zinc-700">{scan.critical}</td>
                  <td className="py-2 pr-3 text-zinc-700">{scan.high}</td>
                  <td className="py-2 pr-3 text-zinc-700">{scan.medium}</td>
                  <td className="py-2 text-zinc-700">{scan.low}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}
