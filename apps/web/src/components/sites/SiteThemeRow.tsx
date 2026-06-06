import { useState } from 'react';
import { AlertCircle, Loader2 } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip';
import { SiteThemeUpdateConfirmDialog } from '@/components/sites/SiteThemeUpdateConfirmDialog';
import { useUpdateSiteTheme } from '@/lib/mutations/useUpdateSiteTheme';
import type { Theme } from '@/types/api/themes';

const MAX_ERROR_LENGTH = 200;

interface Props {
  theme: Theme;
  siteId: number;
}

export function SiteThemeRow({ theme, siteId }: Props) {
  const [confirmOpen, setConfirmOpen] = useState(false);
  const { update, isPolling } = useUpdateSiteTheme(siteId, theme.slug);

  const inFlight =
    theme.update_state === 'queued' ||
    theme.update_state === 'updating' ||
    isPolling;
  const failed = theme.update_state === 'failed';
  const rowClasses = inFlight
    ? 'opacity-70 bg-zinc-50'
    : failed
      ? 'bg-red-50'
      : '';

  const renderActionCell = () => {
    if (!theme.update_available) {
      return <span className="text-zinc-400">—</span>;
    }

    const rawError = theme.last_update_error ?? '';
    const truncatedError =
      rawError.length > MAX_ERROR_LENGTH
        ? `${rawError.slice(0, MAX_ERROR_LENGTH)}…`
        : rawError || 'Update failed.';

    return (
      <div className="flex items-center gap-2">
        <Badge variant="secondary">→ {theme.update_version}</Badge>

        {failed && (
          <TooltipProvider>
            <Tooltip>
              <TooltipTrigger asChild>
                <span
                  aria-label="Update failed"
                  className="text-red-600 cursor-help inline-flex"
                >
                  <AlertCircle className="w-4 h-4" />
                </span>
              </TooltipTrigger>
              <TooltipContent>{truncatedError}</TooltipContent>
            </Tooltip>
          </TooltipProvider>
        )}

        <Button
          size="sm"
          disabled={inFlight}
          onClick={() => setConfirmOpen(true)}
        >
          {inFlight ? (
            <>
              <Loader2 className="w-3 h-3 animate-spin mr-1" />
              Updating…
            </>
          ) : failed ? (
            'Retry'
          ) : (
            'Update'
          )}
        </Button>

        <SiteThemeUpdateConfirmDialog
          theme={theme}
          open={confirmOpen}
          onOpenChange={setConfirmOpen}
          onConfirm={() => {
            setConfirmOpen(false);
            update();
          }}
        />
      </div>
    );
  };

  return (
    <tr className={`border-b last:border-b-0 ${rowClasses}`}>
      <td className="py-2">
        <div className="font-medium flex items-center gap-2">
          {theme.name}
          {theme.is_active && (
            <Badge variant="default" className="bg-green-600 hover:bg-green-700">
              Active
            </Badge>
          )}
          {theme.parent_slug && (
            <Badge variant="outline">Parent: {theme.parent_slug}</Badge>
          )}
        </div>
        <div className="text-xs text-zinc-500">{theme.slug}</div>
      </td>
      <td className="py-2 text-sm text-zinc-700">{theme.version ?? '—'}</td>
      <td className="py-2 text-sm">{renderActionCell()}</td>
    </tr>
  );
}
