import { useEffect, useRef, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/lib/apiClient';
import { useSitePerformance } from '@/lib/queries/useSitePerformance';

export function useMeasurePerformance(siteId: number) {
  const queryClient = useQueryClient();
  const preScanRef = useRef<string | null>(null);
  const [isPolling, setIsPolling] = useState(false);

  const query = useSitePerformance(siteId, { refetchInterval: isPolling ? 5_000 : false });

  // Stop polling once fetched_at changes from the pre-scan value.
  // Dep array uses the primitive string — never an object/array ref — to avoid infinite render loops.
  useEffect(() => {
    if (!isPolling) return;
    const latest = query.data?.latest?.fetched_at ?? null;
    if (latest !== preScanRef.current) {
      setIsPolling(false);
    }
  }, [query.data?.latest?.fetched_at, isPolling]);

  // Hard timeout at 60s to prevent polling forever if the scan never completes.
  useEffect(() => {
    if (!isPolling) return;
    const timeoutId = window.setTimeout(() => setIsPolling(false), 60_000);
    return () => window.clearTimeout(timeoutId);
  }, [isPolling]);

  const mutation = useMutation({
    mutationFn: async () => {
      // Capture the current fetched_at BEFORE the POST so we can detect when it changes.
      preScanRef.current = query.data?.latest?.fetched_at ?? null;
      return apiClient.post<{ scheduled: boolean; site_id: number }>(
        `/sites/${siteId}/performance/scan`,
      );
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['sitePerformance', siteId] });
      setIsPolling(true);
    },
  });

  return {
    measure: () => mutation.mutate(),
    isPending: mutation.isPending,
    isPolling,
    error: mutation.error,
  };
}
