import { describe, it, expect } from 'vitest';
import { latencyTone, formatUptime, parseUtc, minutesSince } from '@/lib/monitoring';

describe('latencyTone', () => {
  it.each([
    [null, 'bad'], [120, 'good'], [299, 'good'], [300, 'warn'], [799, 'warn'], [800, 'bad'], [2000, 'bad'],
  ])('latencyTone(%s) = %s', (ms, tone) => {
    expect(latencyTone(ms as number | null)).toBe(tone);
  });
});

describe('formatUptime', () => {
  it('renders 2dp percent', () => {
    expect(formatUptime(99.7)).toBe('99.70%');
    expect(formatUptime(100)).toBe('100.00%');
  });
});

describe('parseUtc + minutesSince', () => {
  it('parses a space-separated UTC string', () => {
    expect(parseUtc('2026-06-14 03:00:00').getTime()).toBe(Date.UTC(2026, 5, 14, 3, 0, 0));
  });
  it('computes whole minutes since', () => {
    const now = new Date(Date.UTC(2026, 5, 14, 3, 12, 0));
    expect(minutesSince('2026-06-14 03:00:00', now)).toBe(12);
  });
});
