import { Button } from '@/components/ui/button';
import type { Plugin } from '@/types/api/plugins';

interface Props {
  plugin: Plugin;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onConfirm: () => void;
}

/**
 * Confirmation modal for kicking off a plugin update. Mirrors the inline
 * `role="alertdialog"` pattern used by `SiteActions` for Disconnect so we
 * don't pull in a new Radix primitive just for this surface.
 */
export function SitePluginUpdateConfirmDialog({
  plugin,
  open,
  onOpenChange,
  onConfirm,
}: Props) {
  if (!open) {
    return null;
  }

  const titleId = `plugin-update-confirm-${plugin.slug}`;

  return (
    <div
      role="alertdialog"
      aria-modal="true"
      aria-labelledby={titleId}
      className="mt-3 rounded-md border border-zinc-200 bg-white p-4 shadow-sm"
    >
      <h3 id={titleId} className="text-sm font-semibold text-zinc-900">
        Update {plugin.name}
      </h3>
      <p className="mt-0.5 font-mono text-xs text-zinc-500">{plugin.slug}</p>

      <div className="my-3 flex items-center gap-2 text-sm">
        <code className="rounded bg-zinc-100 px-1.5 py-0.5">{plugin.version}</code>
        <span aria-hidden="true" className="text-zinc-400">
          →
        </span>
        <code className="rounded bg-blue-100 px-1.5 py-0.5 font-semibold">
          {plugin.update_version}
        </code>
      </div>

      <div className="my-3 space-y-1 border-l-2 border-amber-500 bg-amber-50 p-2 text-xs text-amber-900">
        <p>The site goes into maintenance mode for the duration (~1–2 min).</p>
        <p>If the upgrade fails to download or install, the existing version stays in place.</p>
      </div>

      <div className="mt-3 flex justify-end gap-2">
        <Button variant="outline" onClick={() => onOpenChange(false)}>
          Cancel
        </Button>
        <Button onClick={onConfirm}>Update</Button>
      </div>
    </div>
  );
}
