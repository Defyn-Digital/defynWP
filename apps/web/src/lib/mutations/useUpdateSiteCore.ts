import { useEffect, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import { apiClient } from '@/lib/apiClient';
import { useSite } from '@/lib/queries/useSite';

const POLL_INTERVAL_MS = 2_000;
const HARD_CAP_MS = 5 * 60 * 1000;

const updateSiteCoreResponseSchema = z.object({
  scheduled: z.literal(true),
  site_id: z.number(),
  core_update_state: z.enum(['idle', 'queued', 'updating', 'failed']),
});

/**
 * Triggers a core update on the dashboard and polls the site data
 * every 2s until the state settles on `idle` or `failed`. Hard 5min cap.
 *
 * Mirrors useUpdateSiteTheme: both rely on useSite as the single source of truth;
 * TanStack Query dedupes by queryKey so the visible site and this hook share
 * one query instance.
 */
export function useUpdateSiteCore(siteId: number) {
  const queryClient = useQueryClient();
  const [isPolling, setIsPolling] = useState(false);

  // While polling, refetch the site every 2s.
  const query = useSite(siteId, {
    pollWhilePending: isPolling ? POLL_INTERVAL_MS : 0,
  });

  const state = query.data?.core_update_state;

  // Stop polling once the state settles on idle or failed.
  useEffect(() => {
    if (!isPolling) return;
    if (state === 'idle' || state === 'failed') {
      setIsPolling(false);
    }
  }, [state, isPolling]);

  // Hard timeout at 5min.
  useEffect(() => {
    if (!isPolling) return;
    const timeoutId = window.setTimeout(() => setIsPolling(false), HARD_CAP_MS);
    return () => window.clearTimeout(timeoutId);
  }, [isPolling]);

  const mutation = useMutation({
    mutationFn: async () => {
      const data = await apiClient.post<unknown>(`/sites/${siteId}/core/update`);
      return updateSiteCoreResponseSchema.parse(data);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['sites', siteId] });
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
