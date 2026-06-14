import { useEffect, useMemo, useState } from 'react';
import { isMajorBump } from '@/lib/semver';

/**
 * P2.10 — shared selection state machine for the filtered drill-in pages
 * (/overview/plugins + /overview/themes).
 *
 * Lifted out of P2.7.1's ConfirmBulkUpdatePluginsDialog so both pages share
 * one implementation. Generic over the common pending-update row shape — it
 * only touches { site_id, site_label, slug, current_version, target_version },
 * so a PendingPluginUpdateRow or PendingThemeUpdateRow both satisfy it (the
 * differing plugin_name / theme_name field is ignored here).
 *
 * State machine (mirror of the dialog, plan-bug traps #3–#5):
 *   - skipMajor (default OFF) gates a `visibleRows` filter via isMajorBump.
 *   - allKeys / grouped / totalCount / siteCount ALL derive from visibleRows.
 *   - checkedKeys re-seeds to all-visible whenever allKeys changes (toggle
 *     flip or a fresh fetch) via a single useEffect([allKeys]). No separate
 *     effect for [skipMajor] — allKeys already depends on skipMajor.
 *
 * Spec: docs/superpowers/specs/2026-06-14-p2-10-filtered-drill-in-design.md § 4
 */
export interface SelectionRow {
  site_id: number;
  site_label: string;
  slug: string;
  current_version: string;
  target_version: string | null;
}

export interface PendingUpdatesSelection {
  skipMajor: boolean;
  setSkipMajor: (v: boolean) => void;
  visibleRows: SelectionRow[];
  grouped: Array<[string, SelectionRow[]]>;
  checkedKeys: Set<string>;
  toggleRow: (key: string) => void;
  toggleGroup: (rowKeys: string[], allChecked: boolean) => void;
  totalCount: number;
  siteCount: number;
  checkedCount: number;
  selectedPairs: Array<{ site_id: number; slug: string }>;
}

export function usePendingUpdatesSelection<R extends SelectionRow>(
  rows: R[],
): PendingUpdatesSelection {
  const [skipMajor, setSkipMajor] = useState(false);

  const visibleRows = useMemo(
    () =>
      skipMajor
        ? rows.filter((r) => !isMajorBump(r.current_version, r.target_version))
        : rows,
    [rows, skipMajor],
  );

  const allKeys = useMemo(
    () => visibleRows.map((r) => `${r.site_id}:${r.slug}`),
    [visibleRows],
  );

  const [checkedKeys, setCheckedKeys] = useState<Set<string>>(() => new Set(allKeys));

  // Re-seed to all-visible whenever the visible key set changes (toggle flip
  // OR a fresh fetch produces a new rows array). Single effect — plan-bug #5.
  useEffect(() => {
    setCheckedKeys(new Set(allKeys));
  }, [allKeys]);

  const grouped = useMemo(() => {
    const map = new Map<string, R[]>();
    for (const row of visibleRows) {
      const list = map.get(row.site_label) ?? [];
      list.push(row);
      map.set(row.site_label, list);
    }
    return Array.from(map.entries());
  }, [visibleRows]);

  const toggleRow = (key: string): void => {
    setCheckedKeys((prev) => {
      const next = new Set(prev);
      if (next.has(key)) {
        next.delete(key);
      } else {
        next.add(key);
      }
      return next;
    });
  };

  const toggleGroup = (rowKeys: string[], allChecked: boolean): void => {
    setCheckedKeys((prev) => {
      const next = new Set(prev);
      if (allChecked) {
        rowKeys.forEach((k) => next.delete(k));
      } else {
        rowKeys.forEach((k) => next.add(k));
      }
      return next;
    });
  };

  // Derive selectedPairs from visibleRows ∩ checkedKeys so a checked-but-now-
  // hidden major row can never leak into the POST (plan-bug #22).
  const selectedPairs = useMemo(
    () =>
      visibleRows
        .filter((r) => checkedKeys.has(`${r.site_id}:${r.slug}`))
        .map((r) => ({ site_id: r.site_id, slug: r.slug })),
    [visibleRows, checkedKeys],
  );

  return {
    skipMajor,
    setSkipMajor,
    visibleRows,
    grouped,
    checkedKeys,
    toggleRow,
    toggleGroup,
    totalCount: visibleRows.length,
    siteCount: grouped.length,
    checkedCount: checkedKeys.size,
    selectedPairs,
  };
}
