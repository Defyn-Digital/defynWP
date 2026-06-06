import { useEffect, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { useSitePlugins } from '@/lib/queries/useSitePlugins';
import { updateSitePluginResponseSchema } from '@/types/api/plugins';

const POLL_INTERVAL_MS = 2_000;
const HARD_CAP_MS = 5 * 60 * 1000;

/**
 * Triggers a plugin update on the dashboard and polls the plugin list
 * every 2s until the row settles on `idle` or `failed`. Hard 5min cap.
 *
 * Mirrors useRefreshSitePlugins (Task 15): both rely on useSitePlugins
 * as the single source of truth; TanStack Query dedupes by queryKey so
 * the visible list and this hook share one query instance.
 */
export function useUpdateSitePlugin(siteId: number, slug: string) {
  const queryClient = useQueryClient();
  const [isPolling, setIsPolling] = useState(false);

  // While polling, refetch the plugins list every 2s.
  const query = useSitePlugins(siteId, {
    refetchInterval: isPolling ? POLL_INTERVAL_MS : false,
  });

  const rowState = query.data?.plugins.find((p) => p.slug === slug)?.update_state;

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
      const data = await apiClient.post<unknown>(`/sites/${siteId}/plugins/${slug}/update`);
      return updateSitePluginResponseSchema.parse(data);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['sites', siteId, 'plugins'] });
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
