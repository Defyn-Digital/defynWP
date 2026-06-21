import { useState, useMemo } from 'react';
import { useActivity } from '@/lib/queries/useActivity';
import { ActivityFilters, type EventFilter } from '@/components/activity/ActivityFilters';
import { ActivityRow } from '@/components/activity/ActivityRow';
import { PageHeader } from '@/components/layout/PageHeader';
import { Skeleton } from '@/components/ui/skeleton';

export default function Activity() {
  const [filter, setFilter] = useState<EventFilter>('all');
  const { data, isLoading, error } = useActivity({ page: 1, perPage: 100 });

  const filtered = useMemo(() => {
    if (!data) return [];
    if (filter === 'all') return data.events;
    return data.events.filter((e) => {
      if (filter === 'site') {
        return e.event_type.startsWith('site.connect') || e.event_type === 'site.disconnected';
      }
      if (filter === 'sync') return e.event_type.startsWith('site.sync');
      if (filter === 'health') {
        return e.event_type.startsWith('site.health') || e.event_type === 'site.recovered';
      }
      if (filter === 'auth') return e.event_type.startsWith('auth.');
      return true;
    });
  }, [data, filter]);

  return (
    <div className="p-4 md:p-6">
      <div className="mx-auto max-w-4xl space-y-5">
        <PageHeader title="Activity" subtitle="Recent events across your fleet" />

        {isLoading ? (
          <div className="space-y-2">
            {Array.from({ length: 6 }).map((_, i) => (
              <Skeleton key={i} className="h-12 w-full" />
            ))}
          </div>
        ) : error ? (
          <div className="rounded-md border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive">
            Failed to load activity.
          </div>
        ) : (
          <>
            <ActivityFilters filter={filter} setFilter={setFilter} />
            {filtered.length === 0 ? (
              <p className="rounded-md border border-border bg-muted/30 p-6 text-center text-sm text-muted-foreground">
                No events match your filters.
              </p>
            ) : (
              <div className="overflow-hidden rounded-md border border-border bg-card">
                {filtered.map((e) => (
                  <ActivityRow key={e.id} event={e} />
                ))}
              </div>
            )}
          </>
        )}
      </div>
    </div>
  );
}
