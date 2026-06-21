import { useMemo } from 'react';
import { Button } from '@/components/ui/button';
import { useSiteBrokenLinks } from '@/lib/queries/useSiteBrokenLinks';
import { useScanSiteLinks } from '@/lib/mutations/useScanSiteLinks';
import type { SiteBrokenLinks } from '@/types/api';
import { parseUtc } from '@/lib/monitoring';

type BrokenLinkRow = SiteBrokenLinks['links'][number];

// --- types ---

interface Props {
  siteId: number;
}

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

function truncateUrl(url: string, maxLen = 60): string {
  if (url.length <= maxLen) return url;
  return url.slice(0, maxLen - 1) + '…';
}

// --- sub-components ---

function SeverityPill({ severity }: { severity: 'broken' | 'warning' }) {
  if (severity === 'broken') {
    return (
      <span className="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-destructive/10 text-destructive">
        broken
      </span>
    );
  }
  return (
    <span className="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-amber-100 text-amber-700">
      warning
    </span>
  );
}

function LinkTypeChip({ linkType }: { linkType: 'internal' | 'external' }) {
  return (
    <span className="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-muted text-muted-foreground">
      {linkType}
    </span>
  );
}

function BrokenLinkItem({ link }: { link: BrokenLinkRow }) {
  return (
    <li className="py-2 text-sm border-b last:border-b-0 border-border">
      <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
        <SeverityPill severity={link.severity} />
        <span
          className="font-mono text-xs text-foreground max-w-xs"
          title={link.url}
        >
          {truncateUrl(link.url)}
        </span>
        <span className="text-xs text-muted-foreground">
          {link.status_code !== null ? link.status_code : '—'}
        </span>
        <LinkTypeChip linkType={link.link_type} />
        {link.anchor_text && (
          <span className="text-xs text-muted-foreground italic">
            &ldquo;{link.anchor_text}&rdquo;
          </span>
        )}
      </div>
    </li>
  );
}

function SourceGroup({ sourceUrl, links }: { sourceUrl: string; links: BrokenLinkRow[] }) {
  return (
    <div className="mb-4">
      <p
        className="text-xs font-medium text-muted-foreground mb-1 truncate"
        title={sourceUrl}
      >
        {sourceUrl}
      </p>
      <ul className="w-full">
        {links.map((link, i) => (
          <BrokenLinkItem key={`${link.url}|${link.status_code ?? 'null'}|${i}`} link={link} />
        ))}
      </ul>
    </div>
  );
}

// --- main component ---

export function SiteBrokenLinksPanel({ siteId }: Props) {
  const { data, isLoading, isError } = useSiteBrokenLinks(siteId);
  const { scan, isPending, isPolling } = useScanSiteLinks(siteId);

  const isScanning = isPending || isPolling;
  const lastScanAt = data?.last_link_scan_at ?? null;

  const subLine = useMemo(() => {
    if (lastScanAt === null) return 'Not checked yet';
    return `Last checked ${relativeTime(lastScanAt)}`;
  }, [lastScanAt]);

  // Group links by source_url, preserving insertion order (sorted by the API).
  const groupedLinks = useMemo(() => {
    if (!data?.links?.length) return new Map<string, BrokenLinkRow[]>();
    const map = new Map<string, BrokenLinkRow[]>();
    for (const link of data.links) {
      const bucket = map.get(link.source_url);
      if (bucket) {
        bucket.push(link);
      } else {
        map.set(link.source_url, [link]);
      }
    }
    return map;
  }, [data?.links]);

  const isNotChecked = lastScanAt === null;
  const isClean = lastScanAt !== null && (data?.counts.total ?? 0) === 0;
  const hasIssues = lastScanAt !== null && (data?.counts.total ?? 0) > 0;

  return (
    <section className="space-y-3 border-t pt-4">
      <header className="flex items-center justify-between">
        <div>
          <h3 className="text-lg font-semibold">Broken links</h3>
          <p className="text-xs text-zinc-500">{subLine}</p>
        </div>
        <Button
          variant="outline"
          size="sm"
          onClick={() => scan()}
          disabled={isScanning}
        >
          {isScanning ? 'Checking…' : 'Check links now'}
        </Button>
      </header>

      {isLoading && (
        <p className="text-sm text-muted-foreground">Loading…</p>
      )}

      {isError && (
        <p className="text-sm text-red-600 text-muted-foreground">
          Failed to load broken links.
        </p>
      )}

      {!isLoading && !isError && isNotChecked && (
        <p className="text-sm text-zinc-600">Not checked yet</p>
      )}

      {!isLoading && !isError && isClean && (
        <p className="text-sm text-zinc-600">No broken links found 🎉</p>
      )}

      {!isLoading && !isError && hasIssues && (
        <div>
          <p className="text-sm text-muted-foreground mb-3">
            <span className="text-destructive font-medium">{data!.counts.broken} broken</span>
            {' · '}
            <span className="text-amber-700 font-medium">{data!.counts.warning} warnings</span>
          </p>
          {Array.from(groupedLinks.entries()).map(([sourceUrl, links]) => (
            <SourceGroup key={sourceUrl} sourceUrl={sourceUrl} links={links} />
          ))}
        </div>
      )}
    </section>
  );
}
