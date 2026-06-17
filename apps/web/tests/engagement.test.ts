import { describe, it, expect } from 'vitest';
import { formatEngagement } from '@/lib/engagement';

describe('formatEngagement', () => {
  it('formats seconds as Xm Ys', () => {
    expect(formatEngagement(108)).toBe('1m 48s');
    expect(formatEngagement(42)).toBe('0m 42s');
    expect(formatEngagement(120)).toBe('2m 0s');
    expect(formatEngagement(0)).toBe('0m 0s');
  });
});
