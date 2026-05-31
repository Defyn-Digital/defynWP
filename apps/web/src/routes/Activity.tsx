import { useState, useMemo } from 'react';
import { useActivity } from '@/lib/queries/useActivity';
import { ActivityFilters, type EventFilter } from '@/components/activity/ActivityFilters';
import { ActivityRow } from '@/components/activity/ActivityRow';

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

  if (isLoading) return <p>Loading…</p>;
  if (error) return <p>Failed to load activity.</p>;

  return (
    <div>
      <h1 className="text-xl font-semibold mb-4">Activity</h1>
      <ActivityFilters filter={filter} setFilter={setFilter} />
      {filtered.length === 0 ? (
        <p className="text-sm text-muted-foreground">No events match your filters.</p>
      ) : (
        <div className="border-t">
          {filtered.map((e) => (
            <ActivityRow key={e.id} event={e} />
          ))}
        </div>
      )}
    </div>
  );
}
