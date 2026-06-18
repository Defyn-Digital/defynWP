import { describe, it, expect } from 'vitest';
import { buildSparkPoints, sparkY } from '@/lib/sparkline';

describe('sparkY', () => {
  it('maps score 100 to the top pad and 0 to the bottom', () => {
    expect(sparkY(100)).toBe(4);   // top
    expect(sparkY(0)).toBe(52);    // bottom (4 + 48)
    expect(sparkY(50)).toBe(28);   // middle (4 + 24)
  });
  it('clamps out-of-range scores', () => {
    expect(sparkY(150)).toBe(4);
    expect(sparkY(-10)).toBe(52);
  });
});

describe('buildSparkPoints', () => {
  it('spaces survivors evenly across x [10,190]', () => {
    // 3 points → x = 10, 100, 190
    expect(buildSparkPoints([100, 50, 0])).toBe('10,4 100,28 190,52');
  });
  it('filters null scores before spacing', () => {
    // nulls dropped → 2 survivors at x = 10, 190
    expect(buildSparkPoints([100, null, 0])).toBe('10,4 190,52');
  });
  it('returns empty string when fewer than 2 non-null points', () => {
    expect(buildSparkPoints([42])).toBe('');
    expect(buildSparkPoints([null, null])).toBe('');
    expect(buildSparkPoints([])).toBe('');
  });
});
