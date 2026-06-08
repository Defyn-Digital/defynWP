import { useEffect, useRef } from 'react';
import { Button } from '@/components/ui/button';

interface ConfirmSyncAllDialogProps {
  open: boolean;
  totalSites: number;
  onCancel: () => void;
  onConfirm: () => void;
}

/**
 * P2.6 — confirm modal for "Sync all sites now".
 *
 * Read-side action — primary button uses the neutral shadcn `Button`
 * default variant (NOT red/amber). Plan-bug trap #9.
 *
 * Cancel button has default focus per Plan-bug trap #10 — mirror of
 * P2.4 ConfirmUpdateCoreDialog cancelRef pattern.
 *
 * Spec: docs/superpowers/specs/2026-06-08-p2-6-sync-all-sites-design.md § 3.4
 */
export function ConfirmSyncAllDialog({
  open,
  totalSites,
  onCancel,
  onConfirm,
}: ConfirmSyncAllDialogProps) {
  const cancelRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (open) {
      cancelRef.current?.focus();
    }
  }, [open]);

  if (!open) {
    return null;
  }

  const titleId = 'sync-all-sites-confirm-title';

  return (
    <div
      role="alertdialog"
      aria-modal="true"
      aria-labelledby={titleId}
      className="mt-3 rounded-md border border-zinc-200 bg-white p-4 shadow-sm"
    >
      <h3 id={titleId} className="text-sm font-semibold text-zinc-900">
        Sync all {totalSites} sites now?
      </h3>

      <div className="mt-3 space-y-2 text-sm text-zinc-700">
        <p>
          This will queue a fresh sync to every connected site.
        </p>
        <p>
          Offline sites are included — their sync will fail fast and
          surface as a fresh <code className="rounded bg-zinc-100 px-1">sync.failed</code> event in
          the activity feed.
        </p>
      </div>

      <div className="mt-4 flex items-center justify-end gap-2">
        <Button ref={cancelRef} variant="outline" onClick={onCancel}>
          Cancel
        </Button>
        <Button variant="default" onClick={onConfirm}>
          Sync all {totalSites} sites
        </Button>
      </div>
    </div>
  );
}
