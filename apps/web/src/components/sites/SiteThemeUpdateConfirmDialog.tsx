import { useRef, useEffect } from 'react';
import { AlertTriangle } from 'lucide-react';
import { Button } from '@/components/ui/button';
import type { Theme } from '@/types/api/themes';

interface Props {
  theme: Theme;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onConfirm: () => void;
}

/**
 * Confirmation modal for kicking off a theme update. Divergent copy based
 * on `theme.is_active`: the active-theme variant carries the amber warning
 * banner + amber confirm button + the "Yes, update active theme" label,
 * because a failed active-theme upgrade can take the front-end down until
 * fixed via SFTP / WP-CLI (spec § 6.4).
 *
 * Backend treats active + inactive uniformly; the safety lives here.
 */
export function SiteThemeUpdateConfirmDialog({
  theme,
  open,
  onOpenChange,
  onConfirm,
}: Props) {
  const cancelRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (open) {
      cancelRef.current?.focus();
    }
  }, [open]);

  if (!open) {
    return null;
  }

  const titleId = `theme-update-confirm-${theme.slug}`;
  const isActive = theme.is_active;

  return (
    <div
      role="alertdialog"
      aria-modal="true"
      aria-labelledby={titleId}
      className="mt-3 rounded-md border border-zinc-200 bg-white p-4 shadow-sm"
    >
      <h3 id={titleId} className="text-sm font-semibold text-zinc-900">
        Update {theme.name}?
      </h3>
      <p className="mt-0.5 font-mono text-xs text-zinc-500">{theme.slug}</p>

      {isActive ? (
        <div className="mt-3 space-y-2 rounded border-l-2 border-amber-500 bg-amber-50 p-3 text-sm text-amber-900">
          <p className="flex items-start gap-2 font-semibold">
            <AlertTriangle className="mt-0.5 h-4 w-4 flex-shrink-0" aria-hidden="true" />
            This is the active theme.
          </p>
          <p>
            A failed upgrade can take the front-end down until you fix it manually
            via SFTP or WP-CLI. Make sure you have a recent backup before continuing.
          </p>
          <p className="font-medium">
            Upgrade from <code className="rounded bg-amber-100 px-1">{theme.version}</code>
            {' '}to{' '}
            <code className="rounded bg-amber-100 px-1 font-semibold">{theme.update_version}</code>?
          </p>
        </div>
      ) : (
        <>
          <div className="my-3 flex items-center gap-2 text-sm">
            <code className="rounded bg-zinc-100 px-1.5 py-0.5">{theme.version}</code>
            <span aria-hidden="true" className="text-zinc-400">→</span>
            <code className="rounded bg-blue-100 px-1.5 py-0.5 font-semibold">{theme.update_version}</code>
          </div>
          <p className="my-3 text-xs text-zinc-600">
            The update typically takes 30–60 seconds.
          </p>
        </>
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
          className={isActive ? 'bg-amber-600 hover:bg-amber-700' : undefined}
        >
          {isActive ? 'Yes, update active theme' : 'Update theme'}
        </Button>
      </div>
    </div>
  );
}
