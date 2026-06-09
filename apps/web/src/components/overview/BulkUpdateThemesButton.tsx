import { useState } from 'react';
import { Settings } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useBulkUpdateThemes } from '@/lib/mutations/useBulkUpdateThemes';
import { usePendingThemeUpdates } from '@/lib/queries/usePendingThemeUpdates';
import { ConfirmBulkUpdateThemesDialog } from '@/components/overview/ConfirmBulkUpdateThemesDialog';

interface BulkUpdateThemesButtonProps {
  pendingCount: number;
}

/**
 * P2.8 — Overview header button + dialog orchestration for bulk theme updates.
 *
 * HIDDEN entirely (returns null) when pendingCount === 0. Different from
 * P2.6's SyncAllSitesButton which renders disabled — here, the absence of
 * pending updates means there's nothing to bulk-update; surfacing a
 * disabled button would add visual noise.
 *
 * Mirror of P2.7's BulkUpdatePluginsButton with theme swap.
 */
export function BulkUpdateThemesButton({ pendingCount }: BulkUpdateThemesButtonProps) {
  const [dialogOpen, setDialogOpen] = useState(false);
  const pending = usePendingThemeUpdates(dialogOpen);
  const mutation = useBulkUpdateThemes();

  if (pendingCount === 0) {
    return null;
  }

  const handleConfirm = (selectedPairs: Array<{ site_id: number; slug: string }>) => {
    setDialogOpen(false);
    if (selectedPairs.length > 0) {
      mutation.mutate({ updates: selectedPairs });
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
        Bulk update themes ({pendingCount})
      </Button>
      <ConfirmBulkUpdateThemesDialog
        open={dialogOpen}
        rows={pending.data?.pending_updates ?? []}
        onCancel={() => setDialogOpen(false)}
        onConfirm={handleConfirm}
      />
    </>
  );
}
