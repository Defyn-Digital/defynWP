import { Button } from '@/components/ui/button';
import { useSitePerformance } from '@/lib/queries/useSitePerformance';
import { useMeasurePerformance } from '@/lib/mutations/useMeasurePerformance';

// --- types ---

interface Props {
  siteId: number;
}

// --- sub-components ---

function ScoreColumn({ label, score }: { label: string; score: number | null }) {
  return (
    <div className="flex flex-col items-center">
      <span className="text-2xl font-semibold tabular-nums text-zinc-900">
        {score === null ? '—' : score}
      </span>
      <span className="text-xs uppercase tracking-wide text-zinc-500">{label}</span>
    </div>
  );
}

// --- main component ---

export function SitePerformancePanel({ siteId }: Props) {
  const { data, isLoading, isError } = useSitePerformance(siteId);
  const { measure, isPending, isPolling } = useMeasurePerformance(siteId);

  const isMeasuring = isPending || isPolling;
  const latest = data?.latest ?? null;

  return (
    <section className="space-y-3 border-t pt-4">
      <header className="flex items-center justify-between">
        <div>
          <h3 className="text-lg font-semibold">Performance</h3>
          <p className="text-xs text-zinc-500">
            {latest === null ? 'Not yet measured' : `Last measured ${latest.fetched_at}`}
          </p>
        </div>
        <Button variant="outline" size="sm" onClick={() => measure()} disabled={isMeasuring}>
          {isMeasuring ? 'Measuring…' : 'Measure now'}
        </Button>
      </header>

      {isLoading && <p className="text-sm text-muted-foreground">Loading…</p>}

      {isError && (
        <p className="text-sm text-red-600">Failed to load performance.</p>
      )}

      {!isLoading && !isError && latest === null && (
        <p className="text-sm text-zinc-600">Not yet measured</p>
      )}

      {!isLoading && !isError && latest !== null && (
        <div className="flex gap-8">
          <ScoreColumn label="Mobile" score={latest.mobile_score} />
          <ScoreColumn label="Desktop" score={latest.desktop_score} />
        </div>
      )}
    </section>
  );
}
