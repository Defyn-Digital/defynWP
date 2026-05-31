import { Button } from '@/components/ui/button';

export type EventFilter = 'all' | 'site' | 'sync' | 'health' | 'auth';

interface ActivityFiltersProps {
  filter: EventFilter;
  setFilter: (next: EventFilter) => void;
}

const FILTERS: ReadonlyArray<{ key: EventFilter; label: string }> = [
  { key: 'all',    label: 'All' },
  { key: 'site',   label: 'Connections' },
  { key: 'sync',   label: 'Syncs' },
  { key: 'health', label: 'Health' },
  { key: 'auth',   label: 'Auth' },
];

export function ActivityFilters({ filter, setFilter }: ActivityFiltersProps) {
  return (
    <div className="flex flex-wrap gap-2 mb-4">
      {FILTERS.map(({ key, label }) => (
        <Button
          key={key}
          variant={filter === key ? 'default' : 'outline'}
          size="sm"
          onClick={() => setFilter(key)}
        >
          {label}
        </Button>
      ))}
    </div>
  );
}
