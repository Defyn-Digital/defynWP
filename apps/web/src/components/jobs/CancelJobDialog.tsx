import { useEffect, useRef } from 'react';
import { Button } from '@/components/ui/button';

interface CancelJobDialogProps {
  open: boolean;
  queuedCount: number;
  onClose: () => void;
  onConfirm: () => void;
}

/**
 * P2.9 — neutral confirm for cancel-queued (spec § 3.8). NOT red —
 * cancel-queued is non-destructive (guardrail #1; nothing is deleted,
 * queued work is simply not performed). Cancel-the-dialog button is
 * labelled "Back" to avoid two "Cancel" buttons. cancelRef focus-on-open
 * mirrors ConfirmSyncAllDialog (P2.6).
 */
export function CancelJobDialog({ open, queuedCount, onClose, onConfirm }: CancelJobDialogProps) {
  const backRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (open) {
      backRef.current?.focus();
    }
  }, [open]);

  if (!open) {
    return null;
  }

  const titleId = 'cancel-job-confirm-title';

  return (
    <div
      role="alertdialog"
      aria-modal="true"
      aria-labelledby={titleId}
      className="mt-3 rounded-md border border-zinc-200 bg-white p-4 shadow-sm"
    >
      <h3 id={titleId} className="text-sm font-semibold text-zinc-900">
        Cancel {queuedCount} queued items?
      </h3>

      <p className="mt-3 text-sm text-zinc-700">
        Items already in progress can't be cancelled and will continue running.
      </p>

      <div className="mt-4 flex items-center justify-end gap-2">
        <Button ref={backRef} variant="outline" onClick={onClose}>
          Back
        </Button>
        <Button variant="default" onClick={onConfirm}>
          Cancel {queuedCount} queued items
        </Button>
      </div>
    </div>
  );
}
