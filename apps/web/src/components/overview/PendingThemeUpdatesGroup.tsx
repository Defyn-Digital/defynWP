import type { PendingThemeUpdateRow } from '@/types/api';

interface PendingThemeUpdatesGroupProps {
  siteLabel: string;
  rows: PendingThemeUpdateRow[];
  checkedKeys: Set<string>;
  onToggleRow: (key: string) => void;
  onToggleGroup: (rowKeys: string[], allChecked: boolean) => void;
}

/**
 * P2.8 — per-site collapsible group with grouped checkbox + child rows.
 * Used inside ConfirmBulkUpdateThemesDialog. Each row has a stable key
 * `${site_id}:${slug}` for the controlled state map.
 *
 * Mirror of P2.7's PendingPluginUpdatesGroup with theme swap.
 */
export function PendingThemeUpdatesGroup({
  siteLabel,
  rows,
  checkedKeys,
  onToggleRow,
  onToggleGroup,
}: PendingThemeUpdatesGroupProps) {
  const rowKeys = rows.map((r) => `${r.site_id}:${r.slug}`);
  const allChecked = rowKeys.every((k) => checkedKeys.has(k));

  return (
    <div data-testid="theme-group" className="rounded border border-zinc-200 p-3">
      <label className="flex items-center gap-2 text-sm font-semibold text-zinc-900">
        <input
          type="checkbox"
          checked={allChecked}
          onChange={() => onToggleGroup(rowKeys, allChecked)}
          aria-label={`Toggle all themes on ${siteLabel}`}
        />
        {siteLabel} — {rows.length} theme{rows.length === 1 ? '' : 's'}
      </label>
      <ul className="mt-2 space-y-1">
        {rows.map((row) => {
          const key = `${row.site_id}:${row.slug}`;
          return (
            <li key={key} className="flex items-center gap-2 text-sm text-zinc-700">
              <input
                type="checkbox"
                checked={checkedKeys.has(key)}
                onChange={() => onToggleRow(key)}
                aria-label={`${row.theme_name} ${row.current_version} to ${row.target_version}`}
              />
              <span className="flex-1">{row.theme_name}</span>
              <span className="font-mono text-xs text-zinc-500">
                {row.current_version} → {row.target_version ?? '?'}
              </span>
            </li>
          );
        })}
      </ul>
    </div>
  );
}
