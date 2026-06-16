export type CwvRating = 'good' | 'needs-improvement' | 'poor' | 'unknown';

// Core Web Vitals "good"/"needs-improvement" boundary thresholds (web.dev defaults).
// Each entry is [good-ceiling, needs-improvement-ceiling]; above the second value is "poor".
const THRESHOLDS: Record<string, [number, number]> = {
  lcp: [2500, 4000],
  cls: [0.1, 0.25],
  inp: [200, 500],
};

export function rateCwv(metric: 'lcp' | 'cls' | 'inp', value: number | null): CwvRating {
  if (value === null || !(metric in THRESHOLDS)) return 'unknown';
  const [good, ni] = THRESHOLDS[metric];
  if (value <= good) return 'good';
  return value <= ni ? 'needs-improvement' : 'poor';
}
