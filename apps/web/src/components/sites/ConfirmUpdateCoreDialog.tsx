import { useEffect, useRef, useState } from 'react';
import { AlertTriangle } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

interface CompatItem {
  name: string;
  tested_up_to: string | null;
}

interface ConfirmUpdateCoreDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onConfirm: () => void;
  currentVersion: string;
  targetVersion: string;
  isMinorUpdate: boolean;
  isAutoUpdateEnabled: boolean;
  /** Plugins from the site's inventory — used to build the compat list in major variant. */
  plugins?: CompatItem[];
  /** Themes from the site's inventory — used to build the compat list in major variant. */
  themes?: CompatItem[];
}

/**
 * Confirmation modal for kicking off a WordPress core update.
 *
 * Minor variant (isMinorUpdate === true):
 *   - Two warning banners (downtime + downgrade-irreversibility).
 *   - Optional auto-updates paragraph.
 *   - Amber bg-amber-600 confirm button labelled "Yes, update WordPress core".
 *   - Cancel has default focus.
 *
 * Major variant (isMinorUpdate === false):
 *   - Header: 🛑 Run MAJOR WordPress upgrade — {current} → {target}
 *   - Three warning banners (downtime + irreversibility + compat list driven by tested_up_to).
 *   - No auto-updates paragraph.
 *   - Type-the-version input gating the confirm button (EXACT match, no trim — plan-bug trap #8).
 *   - Red bg-red-600 confirm button labelled "Yes, run MAJOR upgrade {current} → {target}".
 *
 * Spec § 5.5, § 5.6.
 */
export function ConfirmUpdateCoreDialog({
  open,
  onOpenChange,
  onConfirm,
  currentVersion,
  targetVersion,
  isMinorUpdate,
  isAutoUpdateEnabled,
  plugins = [],
  themes = [],
}: ConfirmUpdateCoreDialogProps) {
  const cancelRef = useRef<HTMLButtonElement>(null);
  const [typedVersion, setTypedVersion] = useState('');

  useEffect(() => {
    if (open) {
      cancelRef.current?.focus();
      // Reset typed version each time dialog opens.
      setTypedVersion('');
    }
  }, [open]);

  if (!open) {
    return null;
  }

  const isMajor = !isMinorUpdate;
  // EXACT string match — no trim, no normalization (plan-bug trap #8).
  const isConfirmEnabled = !isMajor || typedVersion === targetVersion;

  const titleId = `core-update-confirm-title`;

  // Compat analysis for the major variant.
  const incompatiblePlugins = plugins.filter(
    (p) => p.tested_up_to !== null && p.tested_up_to < targetVersion,
  );
  const unknownPlugins = plugins.filter((p) => p.tested_up_to === null);
  const incompatibleThemes = themes.filter(
    (t) => t.tested_up_to !== null && t.tested_up_to < targetVersion,
  );
  const unknownThemes = themes.filter((t) => t.tested_up_to === null);

  const hasConcerns =
    incompatiblePlugins.length > 0 ||
    unknownPlugins.length > 0 ||
    incompatibleThemes.length > 0 ||
    unknownThemes.length > 0;

  const allCompatible =
    plugins.length + themes.length > 0 && !hasConcerns;

  return (
    <div
      role="alertdialog"
      aria-modal="true"
      aria-labelledby={titleId}
      className="mt-3 rounded-md border border-zinc-200 bg-white p-4 shadow-sm"
    >
      <h3 id={titleId} className="text-sm font-semibold text-zinc-900">
        {isMajor
          ? `🛑 Run MAJOR WordPress upgrade — ${currentVersion} → ${targetVersion}`
          : `Update WordPress ${currentVersion} → ${targetVersion}?`}
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
          If {targetVersion} introduces an incompatibility, restoring
          {' '}{currentVersion} means uploading WP core files manually. There is
          no in-WordPress rollback. Make sure recent backups exist before
          continuing.
        </p>
      </div>

      {/* Warning banner 3 — plugin/theme compat (major variant only) */}
      {isMajor && (
        <div className="mt-3 space-y-2 rounded border-l-2 border-red-500 bg-red-50 p-3 text-sm text-red-900">
          <p className="flex items-start gap-2 font-semibold">
            <AlertTriangle className="mt-0.5 h-4 w-4 flex-shrink-0" aria-hidden="true" />
            Plugin &amp; theme compatibility
          </p>
          {allCompatible && (
            <p>All installed plugins &amp; themes report compatibility with {targetVersion}.</p>
          )}
          {!allCompatible && (
            <>
              {(incompatiblePlugins.length > 0 || unknownPlugins.length > 0) && (
                <div>
                  <p className="font-medium">Plugins that may not be compatible:</p>
                  <ul className="mt-1 list-inside list-disc space-y-0.5">
                    {incompatiblePlugins.map((p) => (
                      <li key={p.name}>
                        {p.name}{' '}
                        <span className="text-red-700">(tested up to {p.tested_up_to})</span>
                      </li>
                    ))}
                    {unknownPlugins.map((p) => (
                      <li key={p.name}>
                        {p.name}{' '}
                        <span className="text-red-700">(compatibility unknown)</span>
                      </li>
                    ))}
                  </ul>
                </div>
              )}
              {(incompatibleThemes.length > 0 || unknownThemes.length > 0) && (
                <div>
                  <p className="font-medium">Themes that may not be compatible:</p>
                  <ul className="mt-1 list-inside list-disc space-y-0.5">
                    {incompatibleThemes.map((t) => (
                      <li key={t.name}>
                        {t.name}{' '}
                        <span className="text-red-700">(tested up to {t.tested_up_to})</span>
                      </li>
                    ))}
                    {unknownThemes.map((t) => (
                      <li key={t.name}>
                        {t.name}{' '}
                        <span className="text-red-700">(compatibility unknown)</span>
                      </li>
                    ))}
                  </ul>
                </div>
              )}
              {plugins.length === 0 && themes.length === 0 && (
                <p>No plugins or themes in inventory. Verify compatibility manually.</p>
              )}
            </>
          )}
        </div>
      )}

      {/* Type-the-version input (major variant only) */}
      {isMajor && (
        <div className="mt-3">
          <p className="mb-1.5 text-sm text-zinc-700">
            Type <span className="font-mono font-semibold">{targetVersion}</span> to confirm:
          </p>
          <Input
            value={typedVersion}
            onChange={(e) => setTypedVersion(e.target.value)}
            placeholder={`e.g. ${targetVersion}`}
            className="max-w-xs"
          />
        </div>
      )}

      {/* Conditional auto-update paragraph (minor variant only) */}
      {!isMajor && isAutoUpdateEnabled && (
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
        {isMajor ? (
          <Button
            onClick={onConfirm}
            disabled={!isConfirmEnabled}
            className="bg-red-600 hover:bg-red-700"
          >
            Yes, run MAJOR upgrade {currentVersion} → {targetVersion}
          </Button>
        ) : (
          <Button
            onClick={onConfirm}
            className="bg-amber-600 hover:bg-amber-700"
          >
            Yes, update WordPress core
          </Button>
        )}
      </div>
    </div>
  );
}
