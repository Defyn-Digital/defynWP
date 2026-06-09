import { describe, it, expect } from 'vitest';
import { isPluginMajorBump } from '@/lib/semver';

describe('isPluginMajorBump', () => {
  it('returns true for major version change 1.0.0 → 2.0.0', () => {
    expect(isPluginMajorBump('1.0.0', '2.0.0')).toBe(true);
  });

  it('returns false for minor version change 1.0.0 → 1.5.0', () => {
    expect(isPluginMajorBump('1.0.0', '1.5.0')).toBe(false);
  });

  it('returns false for patch version change 1.0.0 → 1.0.5', () => {
    expect(isPluginMajorBump('1.0.0', '1.0.5')).toBe(false);
  });

  it('returns false for same version 1.0.0 → 1.0.0', () => {
    expect(isPluginMajorBump('1.0.0', '1.0.0')).toBe(false);
  });

  it('returns false when target is null (defensive)', () => {
    expect(isPluginMajorBump('1.0.0', null)).toBe(false);
  });

  it('returns false when current is undefined (defensive)', () => {
    expect(isPluginMajorBump(undefined, '2.0.0')).toBe(false);
  });

  it('returns true for pre-release suffix 1.0-beta → 2.0', () => {
    expect(isPluginMajorBump('1.0-beta', '2.0')).toBe(true);
  });

  it('returns false when major segment is unparseable (conservative)', () => {
    expect(isPluginMajorBump('v2', '3')).toBe(false);
  });
});
