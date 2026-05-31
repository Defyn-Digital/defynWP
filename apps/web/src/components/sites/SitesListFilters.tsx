import type { Site, SiteStatus } from '@/types/api';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

export type StatusKey = SiteStatus | 'all';

interface SitesListFiltersProps {
  sites: Site[];
  statusFilter: StatusKey;
  setStatusFilter: (s: StatusKey) => void;
  query: string;
  setQuery: (q: string) => void;
}

const STATUS_CHIPS: ReadonlyArray<{ key: StatusKey; label: string }> = [
  { key: 'all', label: 'All' },
  { key: 'active', label: 'Active' },
  { key: 'offline', label: 'Offline' },
  { key: 'error', label: 'Error' },
  { key: 'pending', label: 'Pending' },
];

function computeCounts(sites: Site[]): Record<StatusKey, number> {
  return {
    all: sites.length,
    active: sites.filter((s) => s.status === 'active').length,
    offline: sites.filter((s) => s.status === 'offline').length,
    error: sites.filter((s) => s.status === 'error').length,
    pending: sites.filter((s) => s.status === 'pending').length,
  };
}

export function SitesListFilters({
  sites,
  statusFilter,
  setStatusFilter,
  query,
  setQuery,
}: SitesListFiltersProps) {
  const counts = computeCounts(sites);

  return (
    <div className="flex flex-wrap items-center gap-2">
      {STATUS_CHIPS.map(({ key, label }) => (
        <Button
          key={key}
          type="button"
          variant={statusFilter === key ? 'default' : 'outline'}
          size="sm"
          onClick={() => setStatusFilter(key)}
        >
          {label} ({counts[key]})
        </Button>
      ))}
      <div className="ml-auto w-64">
        <Input
          type="search"
          placeholder="Search URL or label…"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          aria-label="Search sites"
        />
      </div>
    </div>
  );
}
