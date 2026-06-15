import { useEffect, useRef } from 'react';
import { Button } from '@/components/ui/button';

interface ConfirmScanAllSecurityDialogProps {
  open: boolean;
  totalSites: number;
  onCancel: () => void;
  onConfirm: () => void;
}

/**
 * P4.2 — confirm modal for "Scan all sites now".
 *
 * Read-side action (vulnerability scan is non-destructive) — primary button
 * uses the neutral shadcn `Button` default variant (NOT red/amber).
 *
 * Cancel button has default focus — mirror of P2.6 ConfirmSyncAllDialog
 * cancelRef pattern.
 */
export function ConfirmScanAllSecurityDialog({
  open,
  totalSites,
  onCancel,
  onConfirm,
}: ConfirmScanAllSecurityDialogProps) {
  const cancelRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (open) {
      cancelRef.current?.focus();
    }
  }, [open]);

  if (!open) {
    return null;
  }

  const titleId = 'scan-all-security-confirm-title';

  return (
    <div
      role="alertdialog"
      aria-modal="true"
      aria-labelledby={titleId}
      className="mt-3 rounded-md border border-zinc-200 bg-white p-4 shadow-sm"
    >
      <h3 id={titleId} className="text-sm font-semibold text-zinc-900">
        Scan all {totalSites} sites now?
      </h3>

      <div className="mt-3 space-y-2 text-sm text-zinc-700">
        <p>
          This queues a fresh vulnerability scan for every connected site (it refreshes the
          Wordfence feed first). Results appear as each site finishes.
        </p>
      </div>

      <div className="mt-4 flex items-center justify-end gap-2">
        <Button ref={cancelRef} variant="outline" onClick={onCancel}>
          Cancel
        </Button>
        <Button variant="default" onClick={onConfirm}>
          Scan all {totalSites} sites
        </Button>
      </div>
    </div>
  );
}
