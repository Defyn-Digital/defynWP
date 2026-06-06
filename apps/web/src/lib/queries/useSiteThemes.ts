import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { siteThemesListResponseSchema, type SiteThemesListResponse } from '@/types/api/themes';

const STALE_TIME_MS = 5 * 60 * 1000;
const HOT_POLL_MS = 30_000;

interface UseSiteThemesOptions {
  refetchInterval?: number | false;
}

/**
 * Returns the per-site themes list. Polls every 30s automatically while any
 * row is in flight (queued or updating); settles to a 5-minute stale window
 * once all rows are idle or failed.
 *
 * Callers can override `refetchInterval` (e.g. mutation hooks pinning the
 * cadence during the optimistic transition window).
 */
export function useSiteThemes(siteId: number, opts: UseSiteThemesOptions = {}) {
  return useQuery<SiteThemesListResponse>({
    queryKey: ['sites', siteId, 'themes'],
    queryFn: async () => {
      const data = await apiClient.get<unknown>(`/sites/${siteId}/themes`);
      return siteThemesListResponseSchema.parse(data);
    },
    staleTime: STALE_TIME_MS,
    refetchInterval: (query) => {
      if (opts.refetchInterval !== undefined) {
        return opts.refetchInterval;
      }
      const themes = query.state.data?.themes ?? [];
      const inFlight = themes.some(
        (t) => t.update_state === 'queued' || t.update_state === 'updating',
      );
      return inFlight ? HOT_POLL_MS : false;
    },
  });
}
