import { useEffect, useMemo, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { PendingThemeUpdatesGroup } from '@/components/overview/PendingThemeUpdatesGroup';
import { isMajorBump } from '@/lib/semver';
import type { PendingThemeUpdateRow } from '@/types/api';

interface ConfirmBulkUpdateThemesDialogProps {
  open: boolean;
  rows: PendingThemeUpdateRow[];
  onCancel: () => void;
  onConfirm: (selectedPairs: Array<{ site_id: number; slug: string }>) => void;
}

const VISIBLE_GROUP_LIMIT = 3;

/**
 * P2.8 — destructive bulk update confirm dialog for themes.
 *
 * Mirror of P2.7.1's ConfirmBulkUpdatePluginsDialog with theme swap.
 *
 * Per spec § 3.4:
 *   - All checkboxes pre-checked on open
 *   - Per-site group checkbox toggles all children
 *   - Footer counter "X selected of Y available" via useMemo
 *   - Primary button RED via className override (Button has no destructive
 *     variant — plan-bug trap #1)
 *   - Cancel default focus (cancelRef + useEffect, mirror of P2.4)
 *   - Long lists collapse: first 3 groups expanded, rest behind disclosure
 *   - Day-1 Skip major bumps toggle (default OFF, opt-in) between body text
 *     and per-site groups. When ON, rows where isMajorBump(current, target)
 *     is true are filtered out via visibleRows useMemo.
 *
 * Spec: docs/superpowers/specs/2026-06-09-p2-8-bulk-theme-updates-design.md § 3.4
 */
export function ConfirmBulkUpdateThemesDialog({
  open,
  rows,
  onCancel,
  onConfirm,
}: ConfirmBulkUpdateThemesDialogProps) {
  const cancelRef = useRef<HTMLButtonElement>(null);
  const [showAll, setShowAll] = useState(false);
  const [skipMajor, setSkipMajor] = useState(false);

  // P2.8 — when skipMajor is ON, hide rows where current → target crosses a major boundary.
  const visibleRows = useMemo(
    () => skipMajor
      ? rows.filter((r) => !isMajorBump(r.current_version, r.target_version))
      : rows,
    [rows, skipMajor],
  );

  const allKeys = useMemo(
    () => visibleRows.map((r) => `${r.site_id}:${r.slug}`),
    [visibleRows],
  );
  const [checkedKeys, setCheckedKeys] = useState<Set<string>>(() => new Set(allKeys));

  // Re-seed checkedKeys when the dialog opens / rows change.
  useEffect(() => {
    if (open) {
      setCheckedKeys(new Set(allKeys));
      setShowAll(false);
      cancelRef.current?.focus();
    }
  }, [open, allKeys]);

  // Group rows by site_label, preserving the server's order.
  const grouped = useMemo(() => {
    const map = new Map<string, PendingThemeUpdateRow[]>();
    for (const row of visibleRows) {
      const list = map.get(row.site_label) ?? [];
      list.push(row);
      map.set(row.site_label, list);
    }
    return Array.from(map.entries()); // [[label, rows], ...]
  }, [visibleRows]);

  const selectedCount = checkedKeys.size;
  const totalCount = visibleRows.length;

  if (!open) {
    return null;
  }

  const toggleRow = (key: string) => {
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

  const toggleGroup = (groupKeys: string[], allChecked: boolean) => {
    setCheckedKeys((prev) => {
      const next = new Set(prev);
      if (allChecked) {
        groupKeys.forEach((k) => next.delete(k));
      } else {
        groupKeys.forEach((k) => next.add(k));
      }
      return next;
    });
  };

  const visibleGroups = showAll ? grouped : grouped.slice(0, VISIBLE_GROUP_LIMIT);
  const hiddenCount = grouped.length - VISIBLE_GROUP_LIMIT;

  const handleConfirm = () => {
    const pairs = Array.from(checkedKeys).map((key) => {
      const [siteIdStr, slug] = key.split(':');
      return { site_id: Number(siteIdStr), slug };
    });
    onConfirm(pairs);
  };

  const titleId = 'bulk-update-themes-confirm-title';

  return (
    <div
      role="alertdialog"
      aria-modal="true"
      aria-labelledby={titleId}
      className="mt-3 rounded-md border border-zinc-200 bg-white p-4 shadow-sm"
    >
      <h3 id={titleId} className="text-sm font-semibold text-zinc-900">
        🛑 Bulk update {totalCount} themes across {grouped.length} sites?
      </h3>

      <div className="mt-3 space-y-2 text-sm text-zinc-700">
        <p>This will run the theme upgrader on every checked pair below. Each site briefly enters maintenance mode during its update.</p>
        <p>Uncheck any pair you want to skip — server fans out exactly what's checked. Already-updated rows are silently no-op'd.</p>
      </div>

      {/* P2.8 — Skip major bumps toggle */}
      <label className="mt-3 flex items-center gap-2 text-sm text-zinc-700">
        <input
          type="checkbox"
          checked={skipMajor}
          onChange={(e) => setSkipMajor(e.target.checked)}
        />
        Skip major bumps
        <span className="text-xs text-zinc-500">
          (hide updates where the major version changes, e.g. 1.x → 2.x)
        </span>
      </label>

      <div className="mt-3 space-y-2">
        {visibleGroups.map(([label, groupRows]) => (
          <PendingThemeUpdatesGroup
            key={label}
            siteLabel={label}
            rows={groupRows}
            checkedKeys={checkedKeys}
            onToggleRow={toggleRow}
            onToggleGroup={toggleGroup}
          />
        ))}
        {!showAll && hiddenCount > 0 && (
          <button
            type="button"
            onClick={() => setShowAll(true)}
            className="text-xs text-zinc-600 underline"
          >
            show all {grouped.length} sites ▾
          </button>
        )}
      </div>

      <div className="mt-3 flex items-center justify-between border-t border-zinc-100 pt-3">
        <p className="text-xs text-zinc-600">
          {selectedCount} selected of {totalCount} available
        </p>
        <div className="flex gap-2">
          <Button ref={cancelRef} variant="outline" onClick={onCancel}>
            Cancel
          </Button>
          <Button
            className="bg-red-600 hover:bg-red-700 text-white"
            disabled={selectedCount === 0}
            onClick={handleConfirm}
          >
            🛑 Bulk update {selectedCount} themes
          </Button>
        </div>
      </div>
    </div>
  );
}
