// P6.4 — pure SVG-coordinate helpers for the performance trend sparkline.
// viewBox 200x56: x in [10,190], y in [4,52] (higher score = higher on chart).
const VIEW_W = 200;
const VIEW_H = 56;
const PAD_X = 10;
const PAD_Y = 4;

/** Score 0..100 → y coordinate (clamped). Used for points, gridlines, and dots. */
export function sparkY(score: number, height: number = VIEW_H, pad: number = PAD_Y): number {
  const clamped = Math.max(0, Math.min(100, score));
  return Math.round((pad + ((100 - clamped) / 100) * (height - 2 * pad)) * 10) / 10;
}

/**
 * SVG `points` attribute for ONE series. Nulls are filtered out, surviving
 * points are spaced evenly across the width. Returns '' when fewer than 2
 * non-null points (a single point is not a trend line).
 */
export function buildSparkPoints(
  scores: (number | null)[],
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
      return `${x},${sparkY(v, height)}`;
    })
    .join(' ');
}
