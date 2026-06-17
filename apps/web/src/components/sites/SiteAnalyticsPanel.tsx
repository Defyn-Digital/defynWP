import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useSiteAnalytics } from '@/lib/queries/useSiteAnalytics';
import { useSetGa4Property } from '@/lib/mutations/useSetGa4Property';
import { useRefreshAnalytics } from '@/lib/mutations/useRefreshAnalytics';

// --- types ---

interface Props {
  siteId: number;
}

const PROPERTY_INPUT_ID = 'ga4-property-id';
const PROPERTY_LABEL = 'GA4 Property ID (numeric — not the G- measurement ID)';

// --- main component ---

export function SiteAnalyticsPanel({ siteId }: Props) {
  const { data, isLoading, isError } = useSiteAnalytics(siteId);
  const setProperty = useSetGa4Property(siteId);
  const { refresh, isPending, isPolling } = useRefreshAnalytics(siteId);

  const propertyId = data?.ga4_property_id ?? null;
  const isConnected = propertyId !== null;
  // lazy useState — seed from the connected property, no useEffect on a fresh value.
  const [draft, setDraft] = useState(() => propertyId ?? '');

  const latest = data?.latest ?? null;
  const isRefreshing = isPending || isPolling;

  return (
    <section className="space-y-3 border-t pt-4">
      <header>
        <h3 className="text-lg font-semibold">Analytics</h3>
        <p className="text-xs text-zinc-500">
          {isConnected ? `Property ${propertyId}` : 'GA4 not linked'}
        </p>
      </header>

      {isLoading && <p className="text-sm text-muted-foreground">Loading…</p>}

      {isError && <p className="text-sm text-red-600">Failed to load analytics.</p>}

      {!isLoading && !isError && (
        <div className="space-y-3">
          <div className="space-y-1">
            <Label htmlFor={PROPERTY_INPUT_ID}>{PROPERTY_LABEL}</Label>
            <div className="flex gap-2">
              <Input
                id={PROPERTY_INPUT_ID}
                value={draft}
                inputMode="numeric"
                placeholder="123456789"
                onChange={(e) => setDraft(e.target.value)}
              />
              <Button
                variant="outline"
                size="sm"
                onClick={() => setProperty.mutate(draft.trim())}
                disabled={setProperty.isPending}
              >
                Save
              </Button>
            </div>
          </div>

          {isConnected && (
            <div className="space-y-1">
              {latest === null ? (
                <p className="text-sm text-zinc-600">No data synced yet</p>
              ) : (
                <p className="text-sm text-zinc-700">
                  <span className="font-medium tabular-nums">
                    {latest.sessions ?? '—'} sessions · {latest.total_users ?? '—'} users
                  </span>{' '}
                  for {latest.period_start}
                  <span className="ml-1 text-zinc-500">· Last synced {latest.fetched_at}</span>
                </p>
              )}
              <Button variant="outline" size="sm" onClick={() => refresh()} disabled={isRefreshing}>
                {isRefreshing ? 'Refreshing…' : 'Refresh analytics now'}
              </Button>
            </div>
          )}
        </div>
      )}
    </section>
  );
}
