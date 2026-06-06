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
import { SitePluginUpdateConfirmDialog } from '@/components/sites/SitePluginUpdateConfirmDialog';
import { useUpdateSitePlugin } from '@/lib/mutations/useUpdateSitePlugin';
import type { Plugin } from '@/types/api/plugins';

const MAX_ERROR_LENGTH = 200;

interface Props {
  plugin: Plugin;
  siteId: number;
}

export function SitePluginsRow({ plugin, siteId }: Props) {
  const [confirmOpen, setConfirmOpen] = useState(false);
  const { update, isPolling } = useUpdateSitePlugin(siteId, plugin.slug);

  const inFlight =
    plugin.update_state === 'queued' ||
    plugin.update_state === 'updating' ||
    isPolling;
  const failed = plugin.update_state === 'failed';
  const rowClasses = inFlight
    ? 'opacity-70 bg-zinc-50'
    : failed
      ? 'bg-red-50'
      : '';

  const renderActionCell = () => {
    if (!plugin.update_available) {
      return <span className="text-zinc-400">—</span>;
    }

    const rawError = plugin.last_update_error ?? '';
    const truncatedError =
      rawError.length > MAX_ERROR_LENGTH
        ? `${rawError.slice(0, MAX_ERROR_LENGTH)}…`
        : rawError || 'Update failed.';

    return (
      <div className="flex items-center gap-2">
        <Badge variant="secondary">→ {plugin.update_version}</Badge>

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

        <SitePluginUpdateConfirmDialog
          plugin={plugin}
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
        <div className="font-medium">{plugin.name}</div>
        <div className="text-xs text-zinc-500">{plugin.slug}</div>
      </td>
      <td className="py-2 text-sm text-zinc-700">{plugin.version ?? '—'}</td>
      <td className="py-2 text-sm">{renderActionCell()}</td>
    </tr>
  );
}
