import { describe, it, expect } from 'vitest';
import { presetRange } from '@/lib/reportRange';

describe('presetRange', () => {
  const now = new Date('2026-06-15T10:00:00Z');
  it('last30 → trailing 30 days', () => {
    expect(presetRange('last30', now)).toEqual({ from: '2026-05-16', to: '2026-06-15' });
  });
  it('thisMonth → 1st to today', () => {
    expect(presetRange('thisMonth', now)).toEqual({ from: '2026-06-01', to: '2026-06-15' });
  });
  it('lastMonth → full previous month', () => {
    expect(presetRange('lastMonth', now)).toEqual({ from: '2026-05-01', to: '2026-05-31' });
  });
});
