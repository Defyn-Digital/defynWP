import { describe, it, expect } from 'vitest';
import { formatRelativeTime } from '@/lib/formatRelativeTime';

const NOW = new Date('2026-06-07T12:00:00Z');

describe('formatRelativeTime', () => {
  it('returns "just now" for timestamps within 30 seconds', () => {
    expect(formatRelativeTime('2026-06-07 11:59:50', NOW)).toBe('just now');
    expect(formatRelativeTime('2026-06-07 11:59:31', NOW)).toBe('just now');
  });

  it('returns "X seconds ago" between 30 and 60 seconds', () => {
    expect(formatRelativeTime('2026-06-07 11:59:15', NOW)).toBe('45 seconds ago');
  });

  it('returns "X minute(s) ago" for sub-hour gaps', () => {
    expect(formatRelativeTime('2026-06-07 11:59:00', NOW)).toBe('1 minute ago');
    expect(formatRelativeTime('2026-06-07 11:58:00', NOW)).toBe('2 minutes ago');
    expect(formatRelativeTime('2026-06-07 11:01:00', NOW)).toBe('59 minutes ago');
  });

  it('returns "X hour(s) ago" for sub-day gaps', () => {
    expect(formatRelativeTime('2026-06-07 10:59:00', NOW)).toBe('1 hour ago');
    expect(formatRelativeTime('2026-06-07 09:00:00', NOW)).toBe('3 hours ago');
  });

  it('returns "X day(s) ago" for multi-day gaps', () => {
    expect(formatRelativeTime('2026-06-06 12:00:00', NOW)).toBe('1 day ago');
    expect(formatRelativeTime('2026-06-04 12:00:00', NOW)).toBe('3 days ago');
  });

  it('returns "just now" for future timestamps (clock skew)', () => {
    expect(formatRelativeTime('2026-06-07 12:30:00', NOW)).toBe('just now');
  });

  it('returns the original string for empty or unparseable input', () => {
    expect(formatRelativeTime('', NOW)).toBe('');
    expect(formatRelativeTime('not a date', NOW)).toBe('not a date');
  });
});
