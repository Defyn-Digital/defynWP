import { useState } from 'react';
import { AlertCircle, Loader2, RefreshCw } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip';
import { ConfirmUpdateCoreDialog } from '@/components/sites/ConfirmUpdateCoreDialog';
import { useRefreshSiteCore } from '@/lib/mutations/useRefreshSiteCore';
import { useSite } from '@/lib/queries/useSite';
import { useUpdateSiteCore } from '@/lib/mutations/useUpdateSiteCore';

const MAX_ERROR_LENGTH = 200;

interface SiteCoreCardProps {
  siteId: number;
}

export function SiteCoreCard({ siteId }: SiteCoreCardProps) {
  const [confirmOpen, setConfirmOpen] = useState(false);
  const { data: site } = useSite(siteId);
  const { refresh, isPending: refreshPending } = useRefreshSiteCore(siteId);
  const { update, isPolling } = useUpdateSiteCore(siteId);

  if (!site) {
    return null;
  }

  const state = site.core_update_state;
  const updating = state === 'queued' || state === 'updating' || isPolling;
  const failed = state === 'failed';
  const showUpdateButton = site.core_update_available && !updating;

  if (updating) {
    return (
      <Card className="border-amber-200 bg-amber-50">
        <CardContent className="flex items-center gap-3 p-4 text-amber-900">
          <Loader2 className="h-5 w-5 animate-spin" aria-hidden="true" />
          <div className="text-sm">
            <p className="font-semibold">
              Upgrading WordPress {site.wp_version} {'->'} {site.core_update_version ?? ''}
            </p>
            <p>Approximately 30-90 seconds. Site may briefly show a maintenance message.</p>
          </div>
        </CardContent>
      </Card>
    );
  }

  const renderFailedBanner = () => {
    if (!failed) return null;
    const rawError = site.last_core_update_error ?? '';
    const truncated =
      rawError.length > MAX_ERROR_LENGTH
        ? `${rawError.slice(0, MAX_ERROR_LENGTH)}...`
        : rawError || 'Update failed.';
    return (
      <div className="mb-3 rounded border-l-2 border-red-500 bg-red-50 p-2 text-sm text-red-900">
        <span className="font-semibold">Last update attempt failed: </span>
        {truncated}
        <TooltipProvider>
          <Tooltip>
            <TooltipTrigger asChild>
              <span
                aria-label="Update failed details"
                className="ml-1 inline-flex cursor-help text-red-600"
              >
                <AlertCircle className="h-4 w-4" />
              </span>
            </TooltipTrigger>
            <TooltipContent>{truncated}</TooltipContent>
          </Tooltip>
        </TooltipProvider>
      </div>
    );
  };

  return (
    <>
      <Card>
        <CardContent className="space-y-3 p-4">
          {renderFailedBanner()}

          <div className="flex items-center justify-between">
            <div>
              <p className="font-semibold">WordPress {site.wp_version ?? '—'}</p>
              <p className="text-xs text-zinc-500">
                PHP {site.php_version ?? '—'}
                {site.is_auto_update_enabled === true ? ' · Auto-updates ON' : ''}
              </p>
            </div>
            <div className="flex items-center gap-2">
              <Button
                variant="outline"
                size="sm"
                onClick={() => refresh()}
                disabled={refreshPending}
                aria-label="Refresh WordPress core"
              >
                <RefreshCw className={refreshPending ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
              </Button>
              {showUpdateButton && (
                <Button
                  size="sm"
                  onClick={() => setConfirmOpen(true)}
                  className="bg-amber-600 hover:bg-amber-700"
                >
                  {failed ? 'Retry update' : `Update to ${site.core_update_version ?? ''}`}
                </Button>
              )}
            </div>
          </div>

          {site.core_update_available && (
            <p className="text-sm text-zinc-700">
              Update available: <span className="font-medium">{site.core_update_version}</span>
              {' '}(security & maintenance)
            </p>
          )}
        </CardContent>
      </Card>

      <ConfirmUpdateCoreDialog
        site={site}
        open={confirmOpen}
        onOpenChange={setConfirmOpen}
        onConfirm={() => {
          setConfirmOpen(false);
          update();
        }}
      />
    </>
  );
}
