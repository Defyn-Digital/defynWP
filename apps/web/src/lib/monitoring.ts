export const LATENCY_GOOD_MS = 300;
export const LATENCY_WARN_MS = 800;

export type LatencyTone = 'good' | 'warn' | 'bad';

export function latencyTone(ms: number | null): LatencyTone {
  if (ms === null) return 'bad';
  if (ms < LATENCY_GOOD_MS) return 'good';
  if (ms < LATENCY_WARN_MS) return 'warn';
  return 'bad';
}

export function formatUptime(pct: number): string {
  return `${pct.toFixed(2)}%`;
}

/** Backend timestamps are UTC "YYYY-MM-DD HH:MM:SS" (no zone). Force UTC. */
export function parseUtc(s: string): Date {
  return new Date(s.replace(' ', 'T') + 'Z');
}

export function minutesSince(s: string, now: Date = new Date()): number {
  return Math.max(0, Math.floor((now.getTime() - parseUtc(s).getTime()) / 60000));
}
