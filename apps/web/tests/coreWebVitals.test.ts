import { describe, it, expect } from 'vitest';
import { rateCwv } from '@/lib/coreWebVitals';

describe('rateCwv', () => {
  it('rates by threshold', () => {
    expect(rateCwv('lcp', 2000)).toBe('good');
    expect(rateCwv('lcp', 3000)).toBe('needs-improvement');
    expect(rateCwv('lcp', 5000)).toBe('poor');
    expect(rateCwv('cls', 0.05)).toBe('good');
    expect(rateCwv('inp', 600)).toBe('poor');
    expect(rateCwv('lcp', null)).toBe('unknown');
  });
});
