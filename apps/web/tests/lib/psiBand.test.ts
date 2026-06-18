import { describe, it, expect } from 'vitest';
import { psiBand } from '@/lib/psiBand';

describe('psiBand', () => {
  it.each([
    [90, 'good'],
    [100, 'good'],
    [89, 'needs-improvement'],
    [50, 'needs-improvement'],
    [49, 'poor'],
    [0, 'poor'],
  ])('maps %i to %s', (score, expected) => {
    expect(psiBand(score)).toBe(expected);
  });

  it('maps null to unknown', () => {
    expect(psiBand(null)).toBe('unknown');
  });
});
