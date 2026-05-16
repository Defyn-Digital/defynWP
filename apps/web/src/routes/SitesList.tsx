import { Link } from 'react-router-dom';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useSites } from '@/lib/queries/useSites';

export default function SitesList() {
  const { data, isLoading, isError, error } = useSites();

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

        {data?.sites.length === 0 && (
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

        <div className="space-y-2">
          {data?.sites.map((site) => (
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
