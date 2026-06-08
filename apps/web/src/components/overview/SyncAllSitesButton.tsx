import { useState } from 'react';
import { RefreshCw } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useSyncAllSites } from '@/lib/mutations/useSyncAllSites';
import { ConfirmSyncAllDialog } from '@/components/overview/ConfirmSyncAllDialog';

interface SyncAllSitesButtonProps {
  totalSites: number;
}

/**
 * P2.6 — header button + confirm dialog + mutation invocation.
 *
 * Idle:     [↻ Sync all sites]
 * Pending:  [⏳ Syncing N sites…] (disabled)
 *
 * Click → confirm dialog → confirm → POST /overview/sync-all → spinner
 * for the brief mutation in-flight window, then revert. The mutation's
 * onSuccess invalidates ['overview'] so the activity widget surfaces
 * the new fleet event immediately on the next poll/refetch.
 *
 * Spec: docs/superpowers/specs/2026-06-08-p2-6-sync-all-sites-design.md § 3
 */
export function SyncAllSitesButton({ totalSites }: SyncAllSitesButtonProps) {
  const [confirmOpen, setConfirmOpen] = useState(false);
  const mutation = useSyncAllSites();

  const handleConfirm = () => {
    setConfirmOpen(false);
    mutation.mutate();
  };

  if (mutation.isPending) {
    return (
      <Button variant="outline" size="sm" disabled>
        <RefreshCw className="mr-1.5 h-3.5 w-3.5 animate-spin" aria-hidden="true" />
        Syncing {totalSites} sites…
      </Button>
    );
  }

  return (
    <>
      <Button
        variant="outline"
        size="sm"
        onClick={() => setConfirmOpen(true)}
        disabled={totalSites === 0}
      >
        <RefreshCw className="mr-1.5 h-3.5 w-3.5" aria-hidden="true" />
        Sync all sites
      </Button>
      <ConfirmSyncAllDialog
        open={confirmOpen}
        totalSites={totalSites}
        onCancel={() => setConfirmOpen(false)}
        onConfirm={handleConfirm}
      />
    </>
  );
}
