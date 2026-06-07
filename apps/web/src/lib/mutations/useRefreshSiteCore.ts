import { useEffect, useRef, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { useSite } from '@/lib/queries/useSite';

const POLL_INTERVAL_MS = 2_000;
const HARD_CAP_MS = 60_000;

export function useRefreshSiteCore(siteId: number) {
  const queryClient = useQueryClient();
  const triggerAtRef = useRef<string | null>(null);
  const [isPolling, setIsPolling] = useState(false);

  // While polling, refetch the site every 2s. Same hook used by the panel for the visible site —
  // TanStack dedupes by queryKey so we don't end up with two query instances.
  const query = useSite(siteId, {
    pollWhilePending: isPolling ? POLL_INTERVAL_MS : 0,
  });

  // Stop polling once last_sync_at advances past the click timestamp.
  useEffect(() => {
    if (!isPolling) return;
    const latest = query.data?.last_sync_at;
    const trigger = triggerAtRef.current;
    if (latest && trigger && latest > trigger) {
      setIsPolling(false);
    }
  }, [query.data?.last_sync_at, isPolling]);

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
        `/sites/${siteId}/core/refresh`,
      );
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['sites', siteId] });
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
