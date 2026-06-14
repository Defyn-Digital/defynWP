import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Button } from '@/components/ui/button';
import { PendingThemeUpdatesGroup } from '@/components/overview/PendingThemeUpdatesGroup';
import { ConfirmBulkUpdateGateDialog } from '@/components/overview/ConfirmBulkUpdateGateDialog';
import { usePendingThemeUpdates } from '@/lib/queries/usePendingThemeUpdates';
import { useBulkUpdateThemes } from '@/lib/mutations/useBulkUpdateThemes';
import { usePendingUpdatesSelection } from '@/lib/usePendingUpdatesSelection';
import type { PendingThemeUpdateRow } from '@/types/api';

/**
 * P2.10 — /overview/themes drill-in page. Theme mirror of OverviewPlugins:
 * usePendingThemeUpdates(true) + PendingThemeUpdatesGroup + useBulkUpdateThemes
 * + theme copy. Same structure, gate, and navigate-on-success contract.
 *
 * Spec: docs/superpowers/specs/2026-06-14-p2-10-filtered-drill-in-design.md § 2-3
 */
export default function OverviewThemes() {
  const navigate = useNavigate();
  const { data, isLoading, isError, refetch } = usePendingThemeUpdates(true);
  const mutation = useBulkUpdateThemes();
  const [gateOpen, setGateOpen] = useState(false);

  const rows = data?.pending_updates ?? [];
  const selection = usePendingUpdatesSelection(rows);

  const handleConfirm = (): void => {
    setGateOpen(false);
    if (selection.selectedPairs.length > 0) {
      mutation.mutate(
        { updates: selection.selectedPairs },
        {
          onSuccess: (res) => {
            // Guardrail #7 — non-null job_id navigates to the tracked job.
            if (res.job_id !== null) {
              navigate(`/jobs/${res.job_id}`);
            }
          },
        },
      );
    }
  };

  return (
    <div className="min-h-screen p-8 pb-24">
      <div className="mx-auto max-w-3xl space-y-4">
        <div className="flex items-baseline justify-between">
          <div className="flex items-baseline gap-3">
            <Link
              to="/overview"
              className="text-sm text-zinc-600 underline-offset-4 hover:underline"
            >
              ← Overview
            </Link>
            <h1 className="text-2xl font-semibold">Theme updates across your fleet</h1>
          </div>
          {data && rows.length > 0 && (
            <span className="text-sm text-zinc-600">{selection.totalCount} pending</span>
          )}
        </div>

        {isLoading && <div className="h-24 animate-pulse rounded-md bg-gray-100" />}

        {isError && (
          <div className="rounded-md border border-red-200 bg-red-50 p-4">
            <p className="text-sm text-red-800">Failed to load pending theme updates.</p>
            <button
              onClick={() => refetch()}
              className="mt-2 rounded-md border border-red-200 px-3 py-1 text-sm text-red-800"
            >
              Try again
            </button>
          </div>
        )}

        {data && rows.length === 0 && (
          <p className="text-sm text-zinc-600">
            No pending theme updates across your fleet.
          </p>
        )}

        {data && rows.length > 0 && (
          <>
            <label className="flex items-center gap-2 text-sm text-zinc-700">
              <input
                type="checkbox"
                checked={selection.skipMajor}
                onChange={(e) => selection.setSkipMajor(e.target.checked)}
              />
              Skip major bumps
              <span className="text-xs text-zinc-500">
                (hide updates where the major version changes, e.g. 1.x → 2.x)
              </span>
            </label>

            <div className="space-y-2">
              {selection.grouped.map(([label, groupRows]) => (
                <PendingThemeUpdatesGroup
                  key={label}
                  siteLabel={label}
                  // The shared selection hook's interface widens rows to the
                  // base SelectionRow shape; at runtime these are the original
                  // PendingThemeUpdateRow objects (theme_name preserved).
                  rows={groupRows as PendingThemeUpdateRow[]}
                  checkedKeys={selection.checkedKeys}
                  onToggleRow={selection.toggleRow}
                  onToggleGroup={selection.toggleGroup}
                />
              ))}
            </div>
          </>
        )}
      </div>

      {data && rows.length > 0 && (
        <div className="fixed inset-x-0 bottom-0 border-t border-zinc-200 bg-white">
          <div className="mx-auto flex max-w-3xl items-center justify-between p-4">
            <span className="text-sm text-zinc-600">
              {selection.checkedCount} selected of {selection.totalCount} available
            </span>
            <Button
              className="bg-red-600 hover:bg-red-700 text-white"
              disabled={selection.checkedCount === 0 || mutation.isPending}
              onClick={() => setGateOpen(true)}
            >
              {mutation.isPending
                ? `Scheduling ${selection.checkedCount} updates…`
                : `Update ${selection.checkedCount} selected`}
            </Button>
          </div>
        </div>
      )}

      <ConfirmBulkUpdateGateDialog
        open={gateOpen}
        resourceLabel="theme"
        count={selection.checkedCount}
        siteCount={selection.siteCount}
        onCancel={() => setGateOpen(false)}
        onConfirm={handleConfirm}
      />
    </div>
  );
}
