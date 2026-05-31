import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useSites } from '@/lib/queries/useSites';
import { SitesListFilters, type StatusKey } from '@/components/sites/SitesListFilters';

export default function SitesList() {
  const { data, isLoading, isError, error } = useSites();
  const [statusFilter, setStatusFilter] = useState<StatusKey>('all');
  const [query, setQuery] = useState('');

  const sites = data?.sites ?? [];

  const filtered = useMemo(() => {
    const lower = query.trim().toLowerCase();
    return sites.filter((s) => {
      const matchesStatus = statusFilter === 'all' || s.status === statusFilter;
      const matchesQuery =
        lower === '' ||
        s.url.toLowerCase().includes(lower) ||
        s.label.toLowerCase().includes(lower);
      return matchesStatus && matchesQuery;
    });
  }, [sites, statusFilter, query]);

  return (
    <div className="min-h-screen p-8">
      <div className="max-w-3xl mx-auto space-y-6">
        <div className="flex items-center justify-between">
          <h1 className="text-2xl font-semibold">Sites</h1>
          <Button asChild>
            <Link to="/sites/add">Add Site</Link>
          </Button>
        </div>

        {isLoading && <p className="text-sm text-zinc-500">Loading…</p>}
        {isError && (
          <p className="text-sm text-red-600">
            Could not load sites. {(error as { message?: string }).message}
          </p>
        )}

        {sites.length === 0 && !isLoading && !isError && (
          <Card>
            <CardHeader>
              <CardTitle>No sites yet</CardTitle>
            </CardHeader>
            <CardContent>
              <p className="text-sm text-zinc-600">
                Generate a connection code on a WordPress site that has the connector plugin installed,
                then paste it into the Add Site form.
              </p>
            </CardContent>
          </Card>
        )}

        {sites.length > 0 && (
          <SitesListFilters
            sites={sites}
            statusFilter={statusFilter}
            setStatusFilter={setStatusFilter}
            query={query}
            setQuery={setQuery}
          />
        )}

        {sites.length > 0 && filtered.length === 0 && (
          <p className="text-sm text-zinc-500">No sites match your filters.</p>
        )}

        <div className="space-y-2">
          {filtered.map((site) => (
            <Link key={site.id} to={`/sites/${site.id}`} className="block">
              <Card className="hover:bg-zinc-50">
                <CardContent className="flex items-center justify-between p-4">
                  <div>
                    <p className="font-medium">{site.label || site.url}</p>
                    <p className="text-xs text-zinc-500">{site.url}</p>
                  </div>
                  <span
                    className={
                      'rounded px-2 py-1 text-xs uppercase ' +
                      (site.status === 'active'
                        ? 'bg-green-100 text-green-800'
                        : site.status === 'pending'
                        ? 'bg-yellow-100 text-yellow-800'
                        : site.status === 'offline'
                        ? 'bg-zinc-200 text-zinc-700'
                        : 'bg-red-100 text-red-800')
                    }
                  >
                    {site.status}
                  </span>
                </CardContent>
              </Card>
            </Link>
          ))}
        </div>
      </div>
    </div>
  );
}
