import { useEffect, useRef } from 'react';
import { Button } from '@/components/ui/button';

interface DeleteReportDialogProps {
  open: boolean;
  onCancel: () => void;
  onConfirm: () => void;
}

/**
 * P5.3 — confirm dialog for deleting a stored report record.
 *
 * Neutral primary (NOT red) per the project's confirm-dialog convention —
 * Cancel takes default focus (mirror of P2.6 ConfirmSyncAllDialog).
 */
export function DeleteReportDialog({ open, onCancel, onConfirm }: DeleteReportDialogProps) {
  const cancelRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (open) {
      cancelRef.current?.focus();
    }
  }, [open]);

  if (!open) {
    return null;
  }

  const titleId = 'delete-report-confirm-title';

  return (
    <div
      role="alertdialog"
      aria-modal="true"
      aria-labelledby={titleId}
      className="mt-3 rounded-md border border-zinc-200 bg-white p-4 shadow-sm"
    >
      <h3 id={titleId} className="text-sm font-semibold text-zinc-900">
        Delete this report?
      </h3>

      <p className="mt-3 text-sm text-zinc-700">
        The stored PDF and its queue record are removed. This can&apos;t be undone.
      </p>

      <div className="mt-4 flex items-center justify-end gap-2">
        <Button ref={cancelRef} variant="outline" onClick={onCancel}>
          Cancel
        </Button>
        <Button variant="default" onClick={onConfirm}>
          Delete report
        </Button>
      </div>
    </div>
  );
}
