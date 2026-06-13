import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Settings } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useBulkUpdatePlugins } from '@/lib/mutations/useBulkUpdatePlugins';
import { usePendingPluginUpdates } from '@/lib/queries/usePendingPluginUpdates';
import { ConfirmBulkUpdatePluginsDialog } from '@/components/overview/ConfirmBulkUpdatePluginsDialog';

interface BulkUpdatePluginsButtonProps {
  pendingCount: number;
}

/**
 * P2.7 — header button + confirm dialog + mutation invocation.
 *
 * Idle:     [⚙ Bulk update plugins (47)]
 * Pending:  [⏳ Scheduling 47 updates…] (disabled)
 *
 * Hidden entirely when pendingCount === 0 (NOT just disabled — per spec § 3.1).
 * Different from P2.6's "disabled at totalSites=0" because zero pending updates
 * means there's nothing to bulk-update; a disabled button would add noise.
 *
 * Spec: docs/superpowers/specs/2026-06-09-p2-7-bulk-plugin-updates-design.md § 3.2
 */
export function BulkUpdatePluginsButton({ pendingCount }: BulkUpdatePluginsButtonProps) {
  const [dialogOpen, setDialogOpen] = useState(false);
  const navigate = useNavigate();
  const pending = usePendingPluginUpdates(dialogOpen);
  const mutation = useBulkUpdatePlugins();

  if (pendingCount === 0) {
    return null;
  }

  const handleConfirm = (selectedPairs: Array<{ site_id: number; slug: string }>) => {
    setDialogOpen(false);
    if (selectedPairs.length > 0) {
      mutation.mutate(
        { updates: selectedPairs },
        {
          onSuccess: (data) => {
            // Guardrail #11 — jump straight to the tracked job. job_id is
            // null on the all-skipped 200 path: stay on /overview.
            if (data.job_id !== null) {
              navigate(`/jobs/${data.job_id}`);
            }
          },
        },
      );
    }
  };

  if (mutation.isPending) {
    return (
      <Button variant="outline" size="sm" disabled>
        <Settings className="mr-1.5 h-3.5 w-3.5 animate-spin" aria-hidden="true" />
        Scheduling {pendingCount} updates…
      </Button>
    );
  }

  return (
    <>
      <Button
        variant="outline"
        size="sm"
        onClick={() => setDialogOpen(true)}
      >
        <Settings className="mr-1.5 h-3.5 w-3.5" aria-hidden="true" />
        Bulk update plugins ({pendingCount})
      </Button>
      <ConfirmBulkUpdatePluginsDialog
        open={dialogOpen}
        rows={pending.data?.pending_updates ?? []}
        onCancel={() => setDialogOpen(false)}
        onConfirm={handleConfirm}
      />
    </>
  );
}
