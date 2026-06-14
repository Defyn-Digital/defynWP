import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Button } from '@/components/ui/button';
import { PendingPluginUpdatesGroup } from '@/components/overview/PendingPluginUpdatesGroup';
import { ConfirmBulkUpdateGateDialog } from '@/components/overview/ConfirmBulkUpdateGateDialog';
import { usePendingPluginUpdates } from '@/lib/queries/usePendingPluginUpdates';
import { useBulkUpdatePlugins } from '@/lib/mutations/useBulkUpdatePlugins';
import { usePendingUpdatesSelection } from '@/lib/usePendingUpdatesSelection';
import type { PendingPluginUpdateRow } from '@/types/api';

/**
 * P2.10 — /overview/plugins drill-in page.
 *
 * Durable full-page view of every pending plugin update across the fleet:
 * header + back-link + skip-major toggle + per-site PendingPluginUpdatesGroup
 * list + sticky RED "Update N selected" footer + lightweight gate +
 * navigate-on-success to /jobs/{job_id}. Reuses the P2.7 query hook (called
 * with `true` to fetch on mount), the shared selection hook, the existing
 * group component, and the P2.9 bulk mutation hook.
 *
 * Spec: docs/superpowers/specs/2026-06-14-p2-10-filtered-drill-in-design.md § 2-3
 */
export default function OverviewPlugins() {
  const navigate = useNavigate();
  const { data, isLoading, isError, refetch } = usePendingPluginUpdates(true);
  const mutation = useBulkUpdatePlugins();
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
            <h1 className="text-2xl font-semibold">Plugin updates across your fleet</h1>
          </div>
          {data && rows.length > 0 && (
            <span className="text-sm text-zinc-600">{selection.totalCount} pending</span>
          )}
        </div>

        {isLoading && <div className="h-24 animate-pulse rounded-md bg-gray-100" />}

        {isError && (
          <div className="rounded-md border border-red-200 bg-red-50 p-4">
            <p className="text-sm text-red-800">Failed to load pending plugin updates.</p>
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
            No pending plugin updates across your fleet.
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
                <PendingPluginUpdatesGroup
                  key={label}
                  siteLabel={label}
                  // The shared selection hook's interface widens rows to the
                  // base SelectionRow shape; at runtime these are the original
                  // PendingPluginUpdateRow objects (plugin_name preserved).
                  rows={groupRows as PendingPluginUpdateRow[]}
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
        resourceLabel="plugin"
        count={selection.checkedCount}
        siteCount={selection.siteCount}
        onCancel={() => setGateOpen(false)}
        onConfirm={handleConfirm}
      />
    </div>
  );
}
