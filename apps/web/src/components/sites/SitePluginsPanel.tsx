import { useMemo, useState } from 'react';
import { RefreshCw } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { useSitePlugins } from '@/lib/queries/useSitePlugins';
import { useRefreshSitePlugins } from '@/lib/mutations/useRefreshSitePlugins';
import { SitePluginsRow } from '@/components/sites/SitePluginsRow';

interface Props {
  siteId: number;
}

export function SitePluginsPanel({ siteId }: Props) {
  const { data, isLoading } = useSitePlugins(siteId);
  const { refresh, isPending, isPolling } = useRefreshSitePlugins(siteId);
  const [updatesOnly, setUpdatesOnly] = useState(false);

  const filtered = useMemo(() => {
    const list = data?.plugins ?? [];
    return updatesOnly ? list.filter((p) => p.update_available) : list;
  }, [data?.plugins, updatesOnly]);

  const updatesCount = useMemo(
    () => (data?.plugins ?? []).filter((p) => p.update_available).length,
    [data?.plugins],
  );

  const isRefreshing = isPending || isPolling;

  return (
    <section className="space-y-3 border-t pt-4">
      <header className="flex items-center justify-between">
        <h3 className="text-lg font-semibold">Plugins</h3>
        <div className="flex items-center gap-3">
          <label className="text-sm flex items-center gap-2">
            <Switch
              checked={updatesOnly}
              onCheckedChange={setUpdatesOnly}
              aria-label="Updates only"
            />
            Updates only
          </label>
          <Button
            variant="outline"
            size="sm"
            onClick={() => refresh()}
            disabled={isRefreshing}
            aria-label="Refresh"
          >
            <RefreshCw className={isRefreshing ? 'animate-spin' : ''} size={14} />
          </Button>
        </div>
      </header>

      {!isLoading && data && (
        <p className="text-xs text-zinc-500">
          {data.total} installed · {updatesCount} updates available
          {data.last_synced_at ? <> · Last synced {data.last_synced_at}</> : null}
        </p>
      )}

      {!isLoading && data && data.total === 0 && data.last_synced_at === null && (
        <p className="text-sm text-zinc-600">
          Plugin inventory not yet captured. The first background sync runs within 30 minutes — or hit refresh to fetch now.
        </p>
      )}

      {!isLoading && data && data.total === 0 && data.last_synced_at !== null && (
        <p className="text-sm text-zinc-600">No plugins installed on this site.</p>
      )}

      {!isLoading && data && data.total > 0 && (
        <table className={`w-full text-sm ${isRefreshing ? 'opacity-50' : ''}`}>
          <thead>
            <tr className="border-b text-xs uppercase tracking-wide text-zinc-500">
              <th className="text-left py-2">Plugin</th>
              <th className="text-left py-2">Version</th>
              <th className="text-left py-2">Update</th>
            </tr>
          </thead>
          <tbody>
            {filtered.map((p) => (
              <SitePluginsRow key={p.slug} plugin={p} />
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}
