/**
 * P2.7.1 — npm-style major bump detection for plugin updates.
 *
 * Returns true when the leftmost numeric segment differs between
 * current and target (e.g. 1.x → 2.x). Returns false for:
 *   - null/empty target (defensive — don't auto-hide unknown bumps)
 *   - same major (1.5.0 → 1.6.0, 1.5.0 → 1.5.1)
 *   - unparseable major (treat as not major — match conservative default)
 *
 * Distinct from P2.4.1's SiteCoreCard `isMinorBump` which uses WP-core
 * convention (major.minor both must match). For plugins we use npm
 * convention (major segment only).
 *
 * Spec: docs/superpowers/specs/2026-06-09-p2-7-1-minor-only-filter-design.md § 2
 */
export function isPluginMajorBump(
  current: string | null | undefined,
  target: string | null | undefined,
): boolean {
  if (!current || !target) return false;
  const cMaj = parseInt(current.split('.')[0] ?? '', 10);
  const tMaj = parseInt(target.split('.')[0] ?? '', 10);
  if (Number.isNaN(cMaj) || Number.isNaN(tMaj)) return false;
  return cMaj !== tMaj;
}
