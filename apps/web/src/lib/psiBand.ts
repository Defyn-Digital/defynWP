export type PsiBand = 'good' | 'needs-improvement' | 'poor' | 'unknown';

/**
 * P6.3 — map a PageSpeed performance score (0–100) to its Google band:
 * 90–100 good, 50–89 needs-improvement, 0–49 poor. null → unknown.
 */
export function psiBand(score: number | null): PsiBand {
  if (score === null) return 'unknown';
  if (score >= 90) return 'good';
  if (score >= 50) return 'needs-improvement';
  return 'poor';
}
