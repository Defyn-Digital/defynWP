// P6.4 — pure SVG-coordinate helpers for the performance trend sparkline.
// viewBox 200x56: x in [10,190], y in [4,52] (higher score = higher on chart).
const VIEW_W = 200;
const VIEW_H = 56;
const PAD_X = 10;
const PAD_Y = 4;

/**
 * Value 0..max → y coordinate (clamped, floored at 0). Used for points and dots.
 * `max` defaults to 100 (the P6.4 PageSpeed-score scale); analytics passes the
 * series maximum for a relative scale.
 */
export function sparkY(value: number, max: number = 100, height: number = VIEW_H, pad: number = PAD_Y): number {
  const safeMax = max <= 0 ? 1 : max;
  const clamped = Math.max(0, Math.min(safeMax, value));
  return Math.round((pad + ((safeMax - clamped) / safeMax) * (height - 2 * pad)) * 10) / 10;
}

/**
 * SVG `points` attribute for ONE series. Nulls are filtered out, surviving
 * points are spaced evenly across the width. `max` defaults to 100. Returns ''
 * when fewer than 2 non-null points (a single point is not a trend line).
 */
export function buildSparkPoints(
  scores: (number | null)[],
  max: number = 100,
  width: number = VIEW_W,
  height: number = VIEW_H,
  padX: number = PAD_X,
): string {
  const vals = scores.filter((s): s is number => s !== null);
  if (vals.length < 2) {
    return '';
  }
  return vals
    .map((v, i) => {
      const x = Math.round((padX + (i / (vals.length - 1)) * (width - 2 * padX)) * 10) / 10;
      return `${x},${sparkY(v, max, height)}`;
    })
    .join(' ');
}
