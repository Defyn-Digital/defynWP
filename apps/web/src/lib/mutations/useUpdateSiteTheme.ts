import { useEffect, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { useSiteThemes } from '@/lib/queries/useSiteThemes';
import { updateSiteThemeResponseSchema } from '@/types/api/themes';

const POLL_INTERVAL_MS = 2_000;
const HARD_CAP_MS = 5 * 60 * 1000;

/**
 * Triggers a theme update on the dashboard and polls the themes list
 * every 30s until the row settles on `idle` or `failed`. Hard 5min cap.
 *
 * Mirrors useRefreshSiteThemes: both rely on useSiteThemes
 * as the single source of truth; TanStack Query dedupes by queryKey so
 * the visible list and this hook share one query instance.
 */
export function useUpdateSiteTheme(siteId: number, slug: string) {
  const queryClient = useQueryClient();
  const [isPolling, setIsPolling] = useState(false);

  // While polling, refetch the themes list every 30s.
  const query = useSiteThemes(siteId, {
    refetchInterval: isPolling ? POLL_INTERVAL_MS : undefined,
  });

  const rowState = query.data?.themes.find((t) => t.slug === slug)?.update_state;

  // Stop polling once the row settles on idle or failed.
  useEffect(() => {
    if (!isPolling) return;
    if (rowState === 'idle' || rowState === 'failed') {
      setIsPolling(false);
    }
  }, [rowState, isPolling]);

  // Hard timeout at 5min.
  useEffect(() => {
    if (!isPolling) return;
    const timeoutId = window.setTimeout(() => setIsPolling(false), HARD_CAP_MS);
    return () => window.clearTimeout(timeoutId);
  }, [isPolling]);

  const mutation = useMutation({
    mutationFn: async () => {
      const data = await apiClient.post<unknown>(`/sites/${siteId}/themes/${slug}/update`);
      return updateSiteThemeResponseSchema.parse(data);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['sites', siteId, 'themes'] });
      setIsPolling(true);
    },
  });

  return {
    update: () => mutation.mutate(),
    isPending: mutation.isPending,
    isPolling,
    error: mutation.error,
  };
}
