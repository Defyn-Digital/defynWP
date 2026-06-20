import type { ReportAnalyticsData } from '@/types/api';
import { buildSparkPoints } from '@/lib/sparkline';

interface AnalyticsTrendSparklineProps {
  history: ReportAnalyticsData['history'];
}

const SESSIONS = '#2563eb';

function endDot(points: string): { cx: number; cy: number } | null {
  if (points === '') {
    return null;
  }
  const parts = points.split(' ');
  const [cx, cy] = parts[parts.length - 1].split(',').map(Number);
  return { cx, cy };
}

// P6.5 — relative-scaled (floor 0 → series max) monthly sessions trend line.
// Renders nothing unless at least 2 non-null sessions and max > 0.
export function AnalyticsTrendSparkline({ history }: AnalyticsTrendSparklineProps) {
  const sessions = history.map((h) => h.sessions);
  const nonNull = sessions.filter((s): s is number => s !== null);
  if (nonNull.length < 2) {
    return null;
  }
  const max = Math.max(...nonNull);
  if (max <= 0) {
    return null;
  }

  const points = buildSparkPoints(sessions, max);
  if (points === '') {
    return null;
  }
  const dot = endDot(points);
  const latest = nonNull[nonNull.length - 1];
  const first = history[0]?.period_start ?? '';
  const last = history[history.length - 1]?.period_start ?? '';

  return (
    <div className="space-y-2">
      <h3 className="text-sm font-semibold text-zinc-700">Sessions trend</h3>
      <div className="flex items-center gap-4">
        <svg
          viewBox="0 0 200 56"
          width={320}
          height={90}
          className="rounded border border-zinc-200 bg-zinc-50"
          role="img"
          aria-label="Monthly sessions trend"
        >
          <polyline fill="none" stroke={SESSIONS} strokeWidth={2} points={points} />
          {dot && <circle cx={dot.cx} cy={dot.cy} r={2.6} fill={SESSIONS} />}
        </svg>
        <div className="text-xs leading-relaxed text-zinc-600">
          <div>
            <span className="inline-block h-0.5 w-3 align-middle" style={{ background: SESSIONS }} /> Sessions{' '}
            <b>{latest.toLocaleString()}</b>
          </div>
          <div className="mt-1 text-zinc-400">
            {history.length} months · {first.slice(0, 7)} → {last.slice(0, 7)}
          </div>
        </div>
      </div>
    </div>
  );
}
