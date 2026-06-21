import type { ReportPerformanceData } from '@/types/api';
import { buildSparkPoints, sparkY } from '@/lib/sparkline';

interface TrendSparklineProps {
  history: ReportPerformanceData['history'];
}

const MOBILE = '#d97706';
const DESKTOP = '#16a34a';

function latestNonNull(scores: (number | null)[]): number | null {
  for (let i = scores.length - 1; i >= 0; i -= 1) {
    if (scores[i] !== null) {
      return scores[i];
    }
  }
  return null;
}

function endDot(points: string): { cx: number; cy: number } | null {
  if (points === '') {
    return null;
  }
  const parts = points.split(' ');
  const [cx, cy] = parts[parts.length - 1].split(',').map(Number);
  return { cx, cy };
}

// P6.4 — inline-SVG trend line of the weekly mobile+desktop scores. Renders
// nothing unless at least one series has >=2 non-null points.
export function TrendSparkline({ history }: TrendSparklineProps) {
  const mobile = history.map((h) => h.mobile_score);
  const desktop = history.map((h) => h.desktop_score);
  const mPoints = buildSparkPoints(mobile);
  const dPoints = buildSparkPoints(desktop);
  if (mPoints === '' && dPoints === '') {
    return null;
  }

  const mLast = latestNonNull(mobile);
  const dLast = latestNonNull(desktop);
  const mDot = endDot(mPoints);
  const dDot = endDot(dPoints);
  const first = history[0]?.fetched_at ?? '';
  const last = history[history.length - 1]?.fetched_at ?? '';

  return (
    <div className="space-y-2">
      <h3 className="text-sm font-semibold text-zinc-700">Score trend</h3>
      <div className="flex items-center gap-4">
        <svg
          viewBox="0 0 200 56"
          width={320}
          height={90}
          className="rounded border border-zinc-200 bg-zinc-50"
          role="img"
          aria-label="Performance score trend"
        >
          <line x1={6} y1={sparkY(50)} x2={194} y2={sparkY(50)} stroke="#e5e7eb" strokeWidth={1} />
          <line x1={6} y1={sparkY(90)} x2={194} y2={sparkY(90)} stroke="#e5e7eb" strokeWidth={1} />
          {mPoints !== '' && <polyline fill="none" stroke={MOBILE} strokeWidth={2} points={mPoints} />}
          {dPoints !== '' && <polyline fill="none" stroke={DESKTOP} strokeWidth={2} points={dPoints} />}
          {mDot && <circle cx={mDot.cx} cy={mDot.cy} r={2.6} fill={MOBILE} />}
          {dDot && <circle cx={dDot.cx} cy={dDot.cy} r={2.6} fill={DESKTOP} />}
        </svg>
        <div className="text-xs leading-relaxed text-muted-foreground">
          <div>
            <span className="inline-block h-0.5 w-3 align-middle" style={{ background: MOBILE }} /> Mobile{' '}
            <b className="text-foreground">{mLast === null ? '—' : mLast}</b>
          </div>
          <div>
            <span className="inline-block h-0.5 w-3 align-middle" style={{ background: DESKTOP }} /> Desktop{' '}
            <b className="text-foreground">{dLast === null ? '—' : dLast}</b>
          </div>
          <div className="mt-1 text-muted-foreground">
            {first} → {last}
          </div>
        </div>
      </div>
    </div>
  );
}
