import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { sitePluginsListResponseSchema, type SitePluginsListResponse } from '@/types/api/plugins';

const STALE_TIME_MS = 60_000;
const HOT_POLL_MS = 5_000;

interface UseSitePluginsOptions {
  refetchInterval?: number | false;
}

/**
 * Per-site plugins list. Self-heals: polls every 5s automatically while any
 * row is in flight (queued or updating), then stops once all rows settle to
 * idle/failed. This means a completed update reconciles on its own even if the
 * operator navigated away and back — the row won't sit on a stale "Updating…".
 *
 * Callers may override `refetchInterval` (e.g. useUpdateSitePlugin pins 2s
 * during its own optimistic-poll window); an explicit value wins.
 */
export function useSitePlugins(siteId: number, opts: UseSitePluginsOptions = {}) {
  return useQuery<SitePluginsListResponse>({
    queryKey: ['sites', siteId, 'plugins'],
    queryFn: async () => {
      const data = await apiClient.get<unknown>(`/sites/${siteId}/plugins`);
      return sitePluginsListResponseSchema.parse(data);
    },
    staleTime: STALE_TIME_MS,
    refetchInterval: (query) => {
      if (opts.refetchInterval !== undefined) {
        return opts.refetchInterval;
      }
      const plugins = query.state.data?.plugins ?? [];
      const inFlight = plugins.some(
        (p) => p.update_state === 'queued' || p.update_state === 'updating',
      );
      return inFlight ? HOT_POLL_MS : false;
    },
  });
}
