import { useEffect, useRef } from 'react';
import { Button } from '@/components/ui/button';

interface RetryFailedDialogProps {
  open: boolean;
  failedCount: number;
  onClose: () => void;
  onConfirm: () => void;
}

/**
 * P2.9 — neutral confirm for bulk retry-failed (spec § 3.8). Default
 * shadcn primary, not red (guardrail #1).
 */
export function RetryFailedDialog({ open, failedCount, onClose, onConfirm }: RetryFailedDialogProps) {
  const backRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (open) {
      backRef.current?.focus();
    }
  }, [open]);

  if (!open) {
    return null;
  }

  const titleId = 'retry-failed-confirm-title';

  return (
    <div
      role="alertdialog"
      aria-modal="true"
      aria-labelledby={titleId}
      className="mt-3 rounded-md border border-zinc-200 bg-white p-4 shadow-sm"
    >
      <h3 id={titleId} className="text-sm font-semibold text-zinc-900">
        Retry {failedCount} failed items?
      </h3>

      <p className="mt-3 text-sm text-zinc-700">
        Each item is re-queued and re-attempted from scratch.
      </p>

      <div className="mt-4 flex items-center justify-end gap-2">
        <Button ref={backRef} variant="outline" onClick={onClose}>
          Back
        </Button>
        <Button variant="default" onClick={onConfirm}>
          Retry {failedCount} items
        </Button>
      </div>
    </div>
  );
}
