import { Link } from 'react-router-dom';
import type { FleetSiteSecurity } from '@/types/api';
import { parseUtc } from '@/lib/monitoring';

// --- helpers ---

function relativeTime(utcString: string, now: Date = new Date()): string {
  const ms = now.getTime() - parseUtc(utcString).getTime();
  const totalSeconds = Math.floor(ms / 1000);
  if (totalSeconds < 60) return 'just now';
  const minutes = Math.floor(totalSeconds / 60);
  if (minutes < 60) return `${minutes}m ago`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `${hours}h ago`;
  const days = Math.floor(hours / 24);
  return `${days}d ago`;
}

// --- chip sub-components ---

interface ChipProps {
  children: React.ReactNode;
  className: string;
}

function Chip({ children, className }: ChipProps) {
  return (
    <span className={`inline-flex items-center rounded px-1.5 py-0.5 text-xs font-medium ${className}`}>
      {children}
    </span>
  );
}

function FindingsCell({ site }: { site: FleetSiteSecurity }) {
  const { counts, last_security_scan_at } = site;

  if (last_security_scan_at === null) {
    return <span className="text-xs text-zinc-400">Not yet scanned</span>;
  }

  if (counts.total === 0) {
    return (
      <Chip className="bg-green-50 text-green-700">
        ✓ No known vulnerabilities
      </Chip>
    );
  }

  // total > 0 here. If every named-severity bucket is 0, the findings are all
  // `unknown`-severity — render a neutral count chip so the Findings cell is never
  // empty beside a non-zero Total.
  const namedCount = counts.critical + counts.high + counts.medium + counts.low;

  return (
    <span className="flex flex-wrap gap-1">
      {counts.critical > 0 && (
        <Chip className="bg-red-50 text-red-700">{counts.critical} crit</Chip>
      )}
      {counts.high > 0 && (
        <Chip className="bg-red-50 text-red-600">{counts.high} high</Chip>
      )}
      {counts.medium > 0 && (
        <Chip className="bg-amber-50 text-amber-600">{counts.medium} med</Chip>
      )}
      {counts.low > 0 && (
        <Chip className="bg-slate-50 text-slate-500">{counts.low} low</Chip>
      )}
      {namedCount === 0 && (
        <Chip className="bg-slate-50 text-slate-500">{counts.total} unrated</Chip>
      )}
    </span>
  );
}

// --- row ---

function SecurityFleetRow({ site }: { site: FleetSiteSecurity }) {
  const scannedCell = site.last_security_scan_at
    ? relativeTime(site.last_security_scan_at)
    : '—';

  return (
    <tr className="border-b border-zinc-100">
      <td className="py-2 pr-3">
        <Link to={`/sites/${site.site_id}`} className="text-zinc-900 hover:underline font-medium">
          {site.label}
        </Link>
        <div className="text-xs text-zinc-400">{site.url}</div>
      </td>
      <td className="py-2 pr-3">
        <FindingsCell site={site} />
      </td>
      <td className="py-2 pr-3 tabular-nums text-zinc-700">{site.counts.total}</td>
      <td className="py-2 pr-1 text-zinc-500 text-sm">{scannedCell}</td>
    </tr>
  );
}

// --- main component ---

export function SecurityFleetTable({ sites }: { sites: FleetSiteSecurity[] }) {
  return (
    <table className="w-full text-sm">
      <thead>
        <tr className="border-b border-zinc-200 text-left text-xs uppercase tracking-wide text-zinc-500">
          <th className="py-2 pl-1 pr-3 font-medium">Site</th>
          <th className="py-2 pr-3 font-medium">Findings</th>
          <th className="py-2 pr-3 font-medium">Total</th>
          <th className="py-2 pr-1 font-medium">Scanned</th>
        </tr>
      </thead>
      <tbody>
        {sites.map((s) => (
          <SecurityFleetRow key={s.site_id} site={s} />
        ))}
      </tbody>
    </table>
  );
}
