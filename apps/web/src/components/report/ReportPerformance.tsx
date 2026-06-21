import { Gauge } from 'lucide-react';
import { rateCwv } from '@/lib/coreWebVitals';
import type { CwvRating } from '@/lib/coreWebVitals';
import type { ReportPerformanceData } from '@/types/api';
import { TrendSparkline } from '@/components/report/TrendSparkline';

interface ReportPerformanceProps {
  performance: ReportPerformanceData;
}

// Rating → human label + colour class, mirroring ReportSecurity's severity styling.
const RATING_LABEL: Record<CwvRating, string> = {
  good: 'Good',
  'needs-improvement': 'Needs work',
  poor: 'Poor',
  unknown: '—',
};

const RATING_CLASS: Record<CwvRating, string> = {
  good: 'text-success font-semibold',
  'needs-improvement': 'text-warning font-semibold',
  poor: 'text-destructive font-semibold',
  unknown: 'text-muted-foreground font-semibold',
};

type Metric = 'lcp' | 'cls' | 'inp';

interface MetricRow {
  metric: Metric;
  label: string;
  value: number | null;
  display: string;
}

function metricRows(mobile: {
  lcp_ms: number | null;
  cls: number | null;
  inp_ms: number | null;
}): MetricRow[] {
  return [
    {
      metric: 'lcp',
      label: 'LCP',
      value: mobile.lcp_ms,
      display: mobile.lcp_ms === null ? '—' : `${(mobile.lcp_ms / 1000).toFixed(2)} s`,
    },
    {
      metric: 'cls',
      label: 'CLS',
      value: mobile.cls,
      display: mobile.cls === null ? '—' : mobile.cls.toFixed(2),
    },
    {
      metric: 'inp',
      label: 'INP',
      value: mobile.inp_ms,
      display: mobile.inp_ms === null ? '—' : `${mobile.inp_ms} ms`,
    },
  ];
}

function ScoreBlock({ label, score }: { label: string; score: number | null }) {
  return (
    <div className="rounded-md bg-muted/50 p-3 text-center">
      <p className="text-xs uppercase tracking-wide text-muted-foreground">{label}</p>
      <p className="mt-1 text-2xl font-semibold text-foreground">{score === null ? '—' : score}</p>
    </div>
  );
}

// PageSpeed scores (mobile/desktop) + mobile Core Web Vitals ratings + a trend list.
export function ReportPerformance({ performance }: ReportPerformanceProps) {
  const { latest, history } = performance;

  return (
    <section className="report-section rounded-lg border border-border bg-card p-5">
      <div className="mb-4 flex items-center gap-2">
        <Gauge className="h-4 w-4 text-primary" />
        <h2 className="text-base font-semibold text-foreground">Performance</h2>
      </div>

      {latest === null ? (
        <p className="text-sm text-muted-foreground">Not yet measured.</p>
      ) : (
        <div className="space-y-4">
          <p className="text-sm text-muted-foreground">Last measured: {latest.fetched_at}</p>

          <div className="grid grid-cols-2 gap-3">
            <ScoreBlock label="Mobile score" score={latest.mobile.score} />
            <ScoreBlock label="Desktop score" score={latest.desktop.score} />
          </div>

          <div className="space-y-2">
            <h3 className="text-sm font-semibold text-foreground">Core Web Vitals (mobile)</h3>
            <table className="w-full border-collapse text-sm">
              <thead>
                <tr className="border-b border-border text-left text-xs uppercase tracking-wide text-muted-foreground">
                  <th className="py-2 pr-3 font-medium">Metric</th>
                  <th className="py-2 pr-3 font-medium">Value</th>
                  <th className="py-2 font-medium">Rating</th>
                </tr>
              </thead>
              <tbody>
                {metricRows(latest.mobile).map((row) => {
                  const rating = rateCwv(row.metric, row.value);
                  return (
                    <tr key={row.metric} className="border-b border-border last:border-0">
                      <td className="py-2 pr-3 text-foreground">{row.label}</td>
                      <td className="py-2 pr-3 text-foreground">{row.display}</td>
                      <td className={`py-2 ${RATING_CLASS[rating]}`}>{RATING_LABEL[rating]}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>

          <TrendSparkline history={history} />

          {history.length > 0 && (
            <div className="space-y-2">
              <h3 className="text-sm font-semibold text-foreground">Trend</h3>
              <table className="w-full border-collapse text-sm">
                <thead>
                  <tr className="border-b border-border text-left text-xs uppercase tracking-wide text-muted-foreground">
                    <th className="py-2 pr-3 font-medium">Date</th>
                    <th className="py-2 pr-3 font-medium">Mobile</th>
                    <th className="py-2 font-medium">Desktop</th>
                  </tr>
                </thead>
                <tbody>
                  {history.map((entry, i) => (
                    <tr key={`${entry.fetched_at}|${i}`} className="border-b border-border last:border-0">
                      <td className="py-2 pr-3 text-foreground">{entry.fetched_at}</td>
                      <td className="py-2 pr-3 text-foreground">
                        {entry.mobile_score === null ? '—' : entry.mobile_score}
                      </td>
                      <td className="py-2 text-foreground">
                        {entry.desktop_score === null ? '—' : entry.desktop_score}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}
    </section>
  );
}
