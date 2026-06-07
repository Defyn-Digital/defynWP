import { useEffect, useRef } from 'react';
import { AlertTriangle } from 'lucide-react';
import { Button } from '@/components/ui/button';
import type { Site } from '@/types/api';

interface ConfirmUpdateCoreDialogProps {
  site: Site;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onConfirm: () => void;
}

/**
 * Confirmation modal for kicking off a WordPress core update.
 *
 * Stronger than P2.3's active-theme variant because:
 *   - Downtime is guaranteed (every core upgrade enters maintenance mode)
 *   - Irreversibility is total (no in-WP rollback path)
 *   - File system changes are broader
 *
 * Renders TWO warning banners (downtime + downgrade-irreversibility) and
 * a conditional "Auto-updates ON" paragraph that only shows when
 * is_auto_update_enabled === true. Primary button is amber (matches the
 * P2.3 active-theme severity tier) with the label
 * "Yes, update WordPress core" (explicit + harder to fat-finger).
 * Cancel is the default focus.
 *
 * Spec § 6.4.
 */
export function ConfirmUpdateCoreDialog({
  site,
  open,
  onOpenChange,
  onConfirm,
}: ConfirmUpdateCoreDialogProps) {
  const cancelRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (open) {
      cancelRef.current?.focus();
    }
  }, [open]);

  if (!open) {
    return null;
  }

  const titleId = `core-update-confirm-${site.id}`;
  const isAutoUpdateEnabled = site.is_auto_update_enabled === true;

  return (
    <div
      role="alertdialog"
      aria-modal="true"
      aria-labelledby={titleId}
      className="mt-3 rounded-md border border-zinc-200 bg-white p-4 shadow-sm"
    >
      <h3 id={titleId} className="text-sm font-semibold text-zinc-900">
        Update WordPress {site.wp_version} {'->'} {site.core_update_version}?
      </h3>

      {/* Warning banner 1 — downtime */}
      <div className="mt-3 space-y-2 rounded border-l-2 border-amber-500 bg-amber-50 p-3 text-sm text-amber-900">
        <p className="flex items-start gap-2 font-semibold">
          <AlertTriangle className="mt-0.5 h-4 w-4 flex-shrink-0" aria-hidden="true" />
          Site goes briefly offline during the upgrade
        </p>
        <p>
          The frontend serves a "Briefly unavailable for scheduled maintenance"
          message for 30-90 seconds. Logged-in users see wp-admin become
          unavailable.
        </p>
      </div>

      {/* Warning banner 2 — downgrade irreversibility */}
      <div className="mt-3 space-y-2 rounded border-l-2 border-amber-500 bg-amber-50 p-3 text-sm text-amber-900">
        <p className="flex items-start gap-2 font-semibold">
          <AlertTriangle className="mt-0.5 h-4 w-4 flex-shrink-0" aria-hidden="true" />
          Downgrades require SFTP
        </p>
        <p>
          If {site.core_update_version} introduces an incompatibility, restoring
          {' '}{site.wp_version} means uploading WP core files manually. There is
          no in-WordPress rollback. Make sure recent backups exist before
          continuing.
        </p>
      </div>

      {/* Conditional auto-update paragraph */}
      {isAutoUpdateEnabled && (
        <p className="mt-3 text-sm text-zinc-700">
          <span className="font-semibold">Auto-updates ON:</span> WordPress will
          install this update automatically within ~24 hours regardless. Updating
          now just does it sooner.
        </p>
      )}

      <div className="mt-3 flex justify-end gap-2">
        <Button
          ref={cancelRef}
          variant="outline"
          onClick={() => onOpenChange(false)}
        >
          Cancel
        </Button>
        <Button
          onClick={onConfirm}
          className="bg-amber-600 hover:bg-amber-700"
        >
          Yes, update WordPress core
        </Button>
      </div>
    </div>
  );
}
