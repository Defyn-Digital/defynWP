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
  good: 'text-green-600 font-semibold',
  'needs-improvement': 'text-amber-600 font-semibold',
  poor: 'text-red-600 font-semibold',
  unknown: 'text-slate-400 font-semibold',
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
    <div className="rounded-md border px-4 py-3 text-center">
      <p className="text-xs uppercase tracking-wide text-zinc-500">{label}</p>
      <p className="text-2xl font-semibold text-zinc-900">{score === null ? '—' : score}</p>
    </div>
  );
}

// PageSpeed scores (mobile/desktop) + mobile Core Web Vitals ratings + a trend list.
export function ReportPerformance({ performance }: ReportPerformanceProps) {
  const { latest, history } = performance;

  return (
    <section className="report-section space-y-3">
      <h2 className="text-lg font-semibold">Performance</h2>

      {latest === null ? (
        <p className="text-sm text-zinc-500">Not yet measured.</p>
      ) : (
        <div className="space-y-4">
          <p className="text-sm text-zinc-600">Last measured: {latest.fetched_at}</p>

          <div className="grid grid-cols-2 gap-3">
            <ScoreBlock label="Mobile score" score={latest.mobile.score} />
            <ScoreBlock label="Desktop score" score={latest.desktop.score} />
          </div>

          <div className="space-y-2">
            <h3 className="text-sm font-semibold text-zinc-700">Core Web Vitals (mobile)</h3>
            <table className="w-full border-collapse text-sm">
              <thead>
                <tr className="border-b text-left text-xs uppercase tracking-wide text-zinc-500">
                  <th className="py-2 pr-3 font-medium">Metric</th>
                  <th className="py-2 pr-3 font-medium">Value</th>
                  <th className="py-2 font-medium">Rating</th>
                </tr>
              </thead>
              <tbody>
                {metricRows(latest.mobile).map((row) => {
                  const rating = rateCwv(row.metric, row.value);
                  return (
                    <tr key={row.metric} className="border-b last:border-b-0">
                      <td className="py-2 pr-3 text-zinc-700">{row.label}</td>
                      <td className="py-2 pr-3 text-zinc-700">{row.display}</td>
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
              <h3 className="text-sm font-semibold text-zinc-700">Trend</h3>
              <table className="w-full border-collapse text-sm">
                <thead>
                  <tr className="border-b text-left text-xs uppercase tracking-wide text-zinc-500">
                    <th className="py-2 pr-3 font-medium">Date</th>
                    <th className="py-2 pr-3 font-medium">Mobile</th>
                    <th className="py-2 font-medium">Desktop</th>
                  </tr>
                </thead>
                <tbody>
                  {history.map((entry, i) => (
                    <tr key={`${entry.fetched_at}|${i}`} className="border-b last:border-b-0">
                      <td className="py-2 pr-3 text-zinc-700">{entry.fetched_at}</td>
                      <td className="py-2 pr-3 text-zinc-700">
                        {entry.mobile_score === null ? '—' : entry.mobile_score}
                      </td>
                      <td className="py-2 text-zinc-700">
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
