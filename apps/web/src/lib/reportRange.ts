export type RangePreset = 'last30' | 'thisMonth' | 'lastMonth';

function iso(d: Date): string {
  return d.toISOString().slice(0, 10);
}

export function presetRange(
  preset: RangePreset,
  now: Date = new Date(),
): { from: string; to: string } {
  const y = now.getUTCFullYear();
  const m = now.getUTCMonth();

  if (preset === 'thisMonth') {
    return { from: iso(new Date(Date.UTC(y, m, 1))), to: iso(now) };
  }

  if (preset === 'lastMonth') {
    const first = new Date(Date.UTC(y, m - 1, 1));
    const last = new Date(Date.UTC(y, m, 0)); // day 0 of this month = last day of prev month
    return { from: iso(first), to: iso(last) };
  }

  // last30: trailing 30 days inclusive
  const from = new Date(now.getTime() - 30 * 86400_000);
  return { from: iso(from), to: iso(now) };
}
