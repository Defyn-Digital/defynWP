import { useMemo } from 'react';
import { RefreshCw } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useSiteThemes } from '@/lib/queries/useSiteThemes';
import { useRefreshSiteThemes } from '@/lib/mutations/useRefreshSiteThemes';
import { SiteThemeRow } from '@/components/sites/SiteThemeRow';

interface Props {
  siteId: number;
}

export function SiteThemesPanel({ siteId }: Props) {
  const { data, isLoading } = useSiteThemes(siteId);
  const { refresh, isPending, isPolling } = useRefreshSiteThemes(siteId);

  const updatesCount = useMemo(
    () => (data?.themes ?? []).filter((t) => t.update_available).length,
    [data?.themes],
  );

  const totalCount = data?.themes.length ?? 0;
  const isRefreshing = isPending || isPolling;

  return (
    <section className="space-y-3 border-t pt-4">
      <header className="flex items-center justify-between">
        <h3 className="text-lg font-semibold">Themes</h3>
        <Button
          variant="outline"
          size="sm"
          onClick={() => refresh()}
          disabled={isRefreshing}
          aria-label="Refresh themes"
        >
          <RefreshCw className={isRefreshing ? 'animate-spin' : ''} size={14} />
        </Button>
      </header>

      {!isLoading && data && (
        <p className="text-xs text-zinc-500">
          {totalCount} installed · {updatesCount} updates available
          {data.last_synced_at ? <> · Last synced {data.last_synced_at}</> : null}
        </p>
      )}

      {!isLoading && data && totalCount === 0 && data.last_synced_at === null && (
        <p className="text-sm text-zinc-600">
          Theme inventory not yet captured. The first background sync runs within 30 minutes — or hit refresh to fetch now.
        </p>
      )}

      {!isLoading && data && totalCount === 0 && data.last_synced_at !== null && (
        <p className="text-sm text-zinc-600">No themes installed on this site.</p>
      )}

      {!isLoading && data && totalCount > 0 && (
        <table className={`w-full text-sm ${isRefreshing ? 'opacity-50' : ''}`}>
          <thead>
            <tr className="border-b text-xs uppercase tracking-wide text-zinc-500">
              <th className="text-left py-2">Theme</th>
              <th className="text-left py-2">Version</th>
              <th className="text-left py-2">Update</th>
            </tr>
          </thead>
          <tbody>
            {data.themes.map((t) => (
              <SiteThemeRow key={t.slug} theme={t} siteId={siteId} />
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}
