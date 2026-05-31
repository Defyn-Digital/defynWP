import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Button } from '@/components/ui/button';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { apiClient, ApiError } from '@/lib/apiClient';
import type { Site } from '@/types/api';

const ACTION_PENDING_MS = 60_000;

interface SiteActionsProps {
  site: Site;
}

export function SiteActions({ site }: SiteActionsProps) {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [syncing, setSyncing] = useState(false);
  const [pinging, setPinging] = useState(false);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const [statusMessage, setStatusMessage] = useState<string | null>(null);

  const syncMutation = useMutation({
    mutationFn: () => apiClient.post<unknown>(`/sites/${site.id}/sync`),
    onSuccess: () => {
      setActionError(null);
      setStatusMessage('Sync scheduled.');
      setSyncing(true);
      queryClient.invalidateQueries({ queryKey: ['site', site.id] });
      setTimeout(() => setSyncing(false), ACTION_PENDING_MS);
    },
    onError: (err: ApiError) => setActionError(err.message || 'Sync failed.'),
  });

  const pingMutation = useMutation({
    mutationFn: () => apiClient.post<unknown>(`/sites/${site.id}/ping`),
    onSuccess: () => {
      setActionError(null);
      setStatusMessage('Ping scheduled.');
      setPinging(true);
      queryClient.invalidateQueries({ queryKey: ['site', site.id] });
      setTimeout(() => setPinging(false), ACTION_PENDING_MS);
    },
    onError: (err: ApiError) => setActionError(err.message || 'Ping failed.'),
  });

  const disconnectMutation = useMutation({
    mutationFn: () => apiClient.delete<unknown>(`/sites/${site.id}`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['sites'] });
      navigate('/sites');
    },
    onError: (err: ApiError) => {
      setConfirmOpen(false);
      setActionError(err.message || 'Disconnect failed.');
    },
  });

  return (
    <div className="space-y-3">
      {statusMessage && (
        <p className="text-sm text-zinc-600" role="status">
          {statusMessage}
        </p>
      )}
      {actionError && (
        <Alert>
          <AlertDescription>{actionError}</AlertDescription>
        </Alert>
      )}

      <div className="flex flex-wrap gap-2">
        <Button onClick={() => syncMutation.mutate()} disabled={syncing || syncMutation.isPending}>
          {syncing ? 'Syncing…' : 'Refresh'}
        </Button>
        <Button
          variant="outline"
          onClick={() => pingMutation.mutate()}
          disabled={pinging || pingMutation.isPending}
        >
          {pinging ? 'Pinging…' : 'Ping'}
        </Button>
        <Button
          variant="outline"
          className="ml-auto border-red-300 text-red-700 hover:bg-red-50"
          onClick={() => {
            setActionError(null);
            setConfirmOpen(true);
          }}
        >
          Disconnect
        </Button>
      </div>

      {confirmOpen && (
        <div
          role="alertdialog"
          aria-modal="true"
          aria-labelledby="disconnect-confirm-title"
          className="mt-3 rounded-md border border-red-200 bg-red-50 p-4"
        >
          <h3 id="disconnect-confirm-title" className="text-sm font-semibold text-red-900">
            Disconnect {site.label || site.url}?
          </h3>
          <p className="mt-1 text-sm text-red-800">
            This will sever the connection to {site.url}. The connector plugin will be reset on
            the managed site, and this row will be removed from your dashboard. You'll need a new
            connection code to reconnect.
          </p>
          <div className="mt-3 flex gap-2 justify-end">
            <Button
              variant="outline"
              onClick={() => setConfirmOpen(false)}
              disabled={disconnectMutation.isPending}
            >
              Cancel
            </Button>
            <Button
              className="bg-red-600 text-white hover:bg-red-700"
              onClick={() => disconnectMutation.mutate()}
              disabled={disconnectMutation.isPending}
            >
              {disconnectMutation.isPending ? 'Disconnecting…' : 'Disconnect'}
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}
