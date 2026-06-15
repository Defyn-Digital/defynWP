import { useMemo } from 'react';
import { Button } from '@/components/ui/button';
import { useSiteVulnerabilities } from '@/lib/queries/useSiteVulnerabilities';
import { useScanSiteSecurity } from '@/lib/mutations/useScanSiteSecurity';
import { useDismissVulnerability } from '@/lib/mutations/useDismissVulnerability';
import type { Vulnerability } from '@/types/api';
import { parseUtc } from '@/lib/monitoring';

// --- helpers ---

// Stable per-finding fingerprint. Includes source_id so two advisories for the
// same component (same slug+type) don't collide on the React key.
function vulnKey(v: Vulnerability): string {
  return `${v.type}|${v.slug}|${v.source_id}`;
}

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

function VulnerabilityRow({ vuln, onDismiss }: { vuln: Vulnerability; onDismiss: () => void }) {
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
        <button
          type="button"
          className="ml-auto text-zinc-500 hover:text-zinc-700 text-xs"
          onClick={onDismiss}
          aria-label={`Dismiss ${vuln.component_name}`}
        >
          Dismiss
        </button>
      </div>
    </li>
  );
}

function SeverityGroup({
  severity,
  vulns,
  onDismiss,
}: {
  severity: Severity;
  vulns: Vulnerability[];
  onDismiss: (vuln: Vulnerability) => void;
}) {
  if (vulns.length === 0) return null;
  return (
    <div className="mb-3">
      <p className={`text-xs uppercase tracking-wide mb-1 ${SEVERITY_LABEL_CLASS[severity]}`}>
        {severity}
      </p>
      <ul className="w-full">
        {vulns.map((v) => (
          <VulnerabilityRow key={vulnKey(v)} vuln={v} onDismiss={() => onDismiss(v)} />
        ))}
      </ul>
    </div>
  );
}

// --- main component ---

export function SiteSecurityPanel({ siteId }: Props) {
  const { data, isLoading, isError } = useSiteVulnerabilities(siteId);
  const { scan, isPending, isPolling } = useScanSiteSecurity(siteId);
  const { dismiss } = useDismissVulnerability(siteId);

  const isScanning = isPending || isPolling;
  const scannedAt = data?.scanned_at ?? null;
  const vulnerabilities = data?.vulnerabilities ?? [];

  // Split into active (shown in severity groups + counted) and dismissed
  // (shown in the muted section below). `vulnerabilities` is a stable ref from
  // the query — it only changes on refetch — so keying these memos on it is safe.
  const active = useMemo(() => vulnerabilities.filter((v) => !v.dismissed), [vulnerabilities]);
  const dismissed = useMemo(() => vulnerabilities.filter((v) => v.dismissed), [vulnerabilities]);
  const grouped = useMemo(() => groupBySeverity(active), [active]);

  const metaLine = useMemo(() => {
    if (scannedAt === null) return 'Not yet scanned';
    const count = active.length;
    const base = `${count} ${count === 1 ? 'vulnerability' : 'vulnerabilities'} · scanned ${relativeTime(scannedAt)}`;
    return dismissed.length > 0 ? `${base} · ${dismissed.length} dismissed` : base;
  }, [scannedAt, active.length, dismissed.length]);

  const isNotScanned = scannedAt === null;
  const isClean = scannedAt !== null && active.length === 0;
  const hasFindings = scannedAt !== null && active.length > 0;

  const handleDismiss = (vuln: Vulnerability) =>
    dismiss({ type: vuln.type, slug: vuln.slug, source_id: vuln.source_id, dismissed: true });

  const handleRestore = (vuln: Vulnerability) =>
    dismiss({ type: vuln.type, slug: vuln.slug, source_id: vuln.source_id, dismissed: false });

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
            <SeverityGroup
              key={sev}
              severity={sev}
              vulns={grouped[sev]}
              onDismiss={handleDismiss}
            />
          ))}
        </div>
      )}

      {!isLoading && !isError && !isNotScanned && dismissed.length > 0 && (
        <div className="mt-4 pt-3 border-t">
          <p className="text-xs text-zinc-500 mb-2">Dismissed ({dismissed.length})</p>
          <ul className="w-full">
            {dismissed.map((v) => (
              <li
                key={vulnKey(v)}
                className="py-2 text-sm flex items-baseline gap-2 text-zinc-400"
              >
                <span className="line-through">{v.component_name}</span>
                <span className="text-xs">({v.type})</span>
                <span>{v.installed_version}</span>
                <button
                  type="button"
                  className="ml-auto text-blue-600 hover:text-blue-700 text-xs"
                  onClick={() => handleRestore(v)}
                  aria-label={`Restore ${v.component_name}`}
                >
                  Restore
                </button>
              </li>
            ))}
          </ul>
        </div>
      )}
    </section>
  );
}
