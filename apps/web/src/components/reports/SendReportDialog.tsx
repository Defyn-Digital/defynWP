import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

interface SendReportDialogProps {
  open: boolean;
  clientEmail: string | null;
  onCancel: () => void;
  onConfirm: (payload: { recipientEmail: string; note: string }) => void;
}

const EMAIL_RE = /^[^@\s]+@[^@\s]+\.[^@\s]+$/;

/**
 * P5.3 — confirm dialog for emailing a ready report to the client.
 *
 * The recipient field is seeded ONCE from the `clientEmail` prop via lazy
 * `useState(() => clientEmail ?? '')` — NOT a useEffect keyed on an object/array
 * ref (P2.10 render-loop guardrail). Client-side email validation blocks the
 * confirm + shows an inline error. Neutral primary (read-side).
 */
export function SendReportDialog({ open, clientEmail, onCancel, onConfirm }: SendReportDialogProps) {
  const cancelRef = useRef<HTMLButtonElement>(null);
  const [recipient, setRecipient] = useState(() => clientEmail ?? '');
  const [note, setNote] = useState('');
  const [error, setError] = useState(false);

  useEffect(() => {
    if (open) {
      cancelRef.current?.focus();
    }
  }, [open]);

  if (!open) {
    return null;
  }

  const titleId = 'send-report-confirm-title';

  const handleConfirm = () => {
    if (!EMAIL_RE.test(recipient.trim())) {
      setError(true);
      return;
    }
    setError(false);
    onConfirm({ recipientEmail: recipient.trim(), note: note.trim() });
  };

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby={titleId}
      className="mt-3 rounded-md border border-zinc-200 bg-white p-4 shadow-sm"
    >
      <h3 id={titleId} className="text-sm font-semibold text-zinc-900">
        Send report
      </h3>

      <div className="mt-3 space-y-3">
        <label className="flex flex-col text-xs text-zinc-500">
          Recipient email
          <Input
            type="email"
            value={recipient}
            onChange={(e) => setRecipient(e.target.value)}
            className="mt-1"
            aria-label="Recipient email"
            aria-invalid={error}
          />
        </label>

        {error && (
          <p className="text-xs text-red-600">Enter a valid email address.</p>
        )}

        <label className="flex flex-col text-xs text-zinc-500">
          Note (optional)
          <textarea
            value={note}
            onChange={(e) => setNote(e.target.value)}
            rows={3}
            aria-label="Note"
            className="mt-1 rounded-md border border-border bg-background px-3 py-2 text-sm text-zinc-800 shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
          />
        </label>
      </div>

      <div className="mt-4 flex items-center justify-end gap-2">
        <Button ref={cancelRef} variant="outline" onClick={onCancel}>
          Cancel
        </Button>
        <Button variant="default" onClick={handleConfirm}>
          Send report
        </Button>
      </div>
    </div>
  );
}
