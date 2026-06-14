import { useEffect, useRef } from 'react';
import { Button } from '@/components/ui/button';

interface ConfirmBulkUpdateGateDialogProps {
  open: boolean;
  resourceLabel: 'plugin' | 'theme';
  count: number;
  siteCount: number;
  onCancel: () => void;
  onConfirm: () => void;
}

/**
 * P2.10 — lightweight final-confirm gate for the filtered drill-in pages.
 *
 * NOT a re-listing (plan-bug trap #11) — the page already shows every pair.
 * Shows only the count summary + Cancel/Confirm. One shared component
 * parameterized by resourceLabel; copy swaps plugin ↔ theme.
 *
 * RED-tier confirm via className override (Button has no destructive
 * variant — plan-bug trap #2). Cancel default focus via cancelRef
 * (mirror of P2.6 ConfirmSyncAllDialog).
 *
 * Spec: docs/superpowers/specs/2026-06-14-p2-10-filtered-drill-in-design.md § 3
 */
export function ConfirmBulkUpdateGateDialog({
  open,
  resourceLabel,
  count,
  siteCount,
  onCancel,
  onConfirm,
}: ConfirmBulkUpdateGateDialogProps): JSX.Element | null {
  const cancelRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (open) {
      cancelRef.current?.focus();
    }
  }, [open]);

  if (!open) {
    return null;
  }

  const titleId = 'bulk-update-gate-confirm-title';
  const plural = `${resourceLabel}s`;

  return (
    <div
      role="alertdialog"
      aria-modal="true"
      aria-labelledby={titleId}
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
    >
      <div className="w-full max-w-md rounded-lg border border-zinc-200 bg-white p-6 shadow-xl">
        <h2 id={titleId} className="text-lg font-semibold text-zinc-900">
          Update {count} {plural} across {siteCount} sites?
        </h2>

        <p className="mt-3 text-sm text-zinc-700">
          This runs the {resourceLabel} upgrader on every selected pair. Each
          site briefly enters maintenance mode during its update.
        </p>

        <div className="mt-5 flex items-center justify-end gap-2">
          <Button ref={cancelRef} variant="outline" onClick={onCancel}>
            Cancel
          </Button>
          <Button
            className="bg-red-600 hover:bg-red-700 text-white"
            onClick={onConfirm}
          >
            Update {count} {plural}
          </Button>
        </div>
      </div>
    </div>
  );
}
