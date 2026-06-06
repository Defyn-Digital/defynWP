import { useEffect, useRef, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { useSiteThemes } from '@/lib/queries/useSiteThemes';

const POLL_INTERVAL_MS = 2_000;
const HARD_CAP_MS = 60_000;

export function useRefreshSiteThemes(siteId: number) {
  const queryClient = useQueryClient();
  const triggerAtRef = useRef<string | null>(null);
  const [isPolling, setIsPolling] = useState(false);

  // While polling, refetch the themes list every 30s. Same hook used by
  // the panel for the visible list — TanStack dedupes by queryKey so we
  // don't end up with two query instances.
  const query = useSiteThemes(siteId, {
    refetchInterval: isPolling ? POLL_INTERVAL_MS : undefined,
  });

  // Stop polling once last_synced_at advances past the click timestamp.
  useEffect(() => {
    if (!isPolling) return;
    const latest = query.data?.last_synced_at;
    const trigger = triggerAtRef.current;
    if (latest && trigger && latest > trigger) {
      setIsPolling(false);
    }
  }, [query.data?.last_synced_at, isPolling]);

  // Hard timeout at 60s
  useEffect(() => {
    if (!isPolling) return;
    const timeoutId = window.setTimeout(() => setIsPolling(false), HARD_CAP_MS);
    return () => window.clearTimeout(timeoutId);
  }, [isPolling]);

  const mutation = useMutation({
    mutationFn: async () => {
      triggerAtRef.current = new Date().toISOString();
      return apiClient.post<{ scheduled: boolean; site_id: number }>(
        `/sites/${siteId}/themes/refresh`,
      );
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['sites', siteId, 'themes'] });
      setIsPolling(true);
    },
  });

  return {
    refresh: () => mutation.mutate(),
    isPending: mutation.isPending,
    isPolling,
    error: mutation.error,
  };
}
