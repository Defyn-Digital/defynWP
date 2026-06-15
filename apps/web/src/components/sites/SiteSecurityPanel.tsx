import { useMemo } from 'react';
import { Button } from '@/components/ui/button';
import { useSiteVulnerabilities } from '@/lib/queries/useSiteVulnerabilities';
import { useScanSiteSecurity } from '@/lib/mutations/useScanSiteSecurity';
import type { Vulnerability } from '@/types/api';
import { parseUtc } from '@/lib/monitoring';

// --- types ---

interface Props {
  siteId: number;
}

// --- constants ---

const SEVERITY_ORDER = ['critical', 'high', 'medium', 'low', 'unknown'] as const;
type Severity = (typeof SEVERITY_ORDER)[number];

const SEVERITY_LABEL_CLASS: Record<Severity, string> = {
  critical: 'text-red-700 font-semibold',
  high: 'text-red-600 font-semibold',
  medium: 'text-amber-600 font-semibold',
  low: 'text-slate-500 font-semibold',
  unknown: 'text-slate-400 font-semibold',
};

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

function groupBySeverity(vulns: Vulnerability[]): Record<Severity, Vulnerability[]> {
  const groups: Record<Severity, Vulnerability[]> = {
    critical: [],
    high: [],
    medium: [],
    low: [],
    unknown: [],
  };
  for (const v of vulns) {
    groups[v.severity].push(v);
  }
  return groups;
}

// --- sub-components ---

function VulnerabilityRow({ vuln }: { vuln: Vulnerability }) {
  return (
    <li className="py-2 text-sm border-b last:border-b-0 text-zinc-800">
      <div className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
        <span className="font-medium">{vuln.component_name}</span>
        <span className="text-zinc-500 text-xs">({vuln.type})</span>
        <span className="text-zinc-600">{vuln.installed_version}</span>
        {vuln.fixed_in && (
          <span className="text-zinc-500 text-xs">→ fix {vuln.fixed_in}</span>
        )}
        {vuln.cve && (
          <span className="text-zinc-400 font-mono text-xs">{vuln.cve}</span>
        )}
      </div>
    </li>
  );
}

function SeverityGroup({ severity, vulns }: { severity: Severity; vulns: Vulnerability[] }) {
  if (vulns.length === 0) return null;
  return (
    <div className="mb-3">
      <p className={`text-xs uppercase tracking-wide mb-1 ${SEVERITY_LABEL_CLASS[severity]}`}>
        {severity}
      </p>
      <ul className="w-full">
        {vulns.map((v) => (
          <VulnerabilityRow key={`${v.slug}-${v.type}`} vuln={v} />
        ))}
      </ul>
    </div>
  );
}

// --- main component ---

export function SiteSecurityPanel({ siteId }: Props) {
  const { data, isLoading, isError } = useSiteVulnerabilities(siteId);
  const { scan, isPending, isPolling } = useScanSiteSecurity(siteId);

  const isScanning = isPending || isPolling;
  const scannedAt = data?.scanned_at ?? null;
  const vulnerabilities = data?.vulnerabilities ?? [];

  const grouped = useMemo(() => groupBySeverity(vulnerabilities), [vulnerabilities]);

  const metaLine = useMemo(() => {
    if (scannedAt === null) return 'Not yet scanned';
    const count = vulnerabilities.length;
    return `${count} ${count === 1 ? 'vulnerability' : 'vulnerabilities'} · scanned ${relativeTime(scannedAt)}`;
  }, [scannedAt, vulnerabilities.length]);

  const isNotScanned = scannedAt === null;
  const isClean = scannedAt !== null && vulnerabilities.length === 0;
  const hasFindings = scannedAt !== null && vulnerabilities.length > 0;

  return (
    <section className="space-y-3 border-t pt-4">
      <header className="flex items-center justify-between">
        <div>
          <h3 className="text-lg font-semibold">Security</h3>
          <p className="text-xs text-zinc-500">{metaLine}</p>
        </div>
        <Button
          variant="outline"
          size="sm"
          onClick={() => scan()}
          disabled={isScanning}
        >
          {isScanning ? 'Scanning…' : 'Scan now'}
        </Button>
      </header>

      {isLoading && (
        <p className="text-sm text-muted-foreground">Loading…</p>
      )}

      {isError && (
        <p className="text-sm text-red-600 text-muted-foreground">
          Failed to load vulnerabilities.
        </p>
      )}

      {!isLoading && !isError && isNotScanned && (
        <p className="text-sm text-zinc-600">Not yet scanned</p>
      )}

      {!isLoading && !isError && isClean && (
        <p className="text-sm text-zinc-600">✓ No known vulnerabilities</p>
      )}

      {!isLoading && !isError && hasFindings && (
        <div>
          {SEVERITY_ORDER.map((sev) => (
            <SeverityGroup key={sev} severity={sev} vulns={grouped[sev]} />
          ))}
        </div>
      )}
    </section>
  );
}
