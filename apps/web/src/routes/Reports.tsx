import { Link } from 'react-router-dom';
import { FileText } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { useSites } from '@/lib/queries/useSites';
import { PageHeader } from '@/components/layout/PageHeader';

export default function Reports() {
  const { data, isLoading, isError } = useSites();
  const sites = data?.sites ?? [];

  return (
    <div className="p-4 md:p-6">
      <div className="mx-auto max-w-4xl space-y-5">
        <PageHeader
          title="Reports"
          subtitle="Open a site's report to view its metrics, download a branded PDF, or email it to the client."
        />

        {isLoading && (
          <div className="space-y-2">
            {Array.from({ length: 4 }).map((_, i) => (
              <Skeleton key={i} className="h-16 w-full" />
            ))}
          </div>
        )}

        {isError && (
          <div className="rounded-md border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive">
            Could not load your sites.
          </div>
        )}

        {!isLoading && !isError && sites.length === 0 && (
          <Card>
            <CardContent className="p-6 text-sm text-muted-foreground">
              No sites yet. Connect a site first — its report will then be available here.
            </CardContent>
          </Card>
        )}

        {sites.length > 0 && (
          <div className="space-y-2">
            {sites.map((site) => (
              <Card key={site.id}>
                <CardContent className="flex items-center justify-between gap-4 p-4">
                  <div className="flex min-w-0 items-center gap-3">
                    <FileText className="h-5 w-5 shrink-0 text-muted-foreground" aria-hidden="true" />
                    <div className="min-w-0">
                      <p className="truncate font-medium">{site.label || site.url}</p>
                      <p className="truncate text-xs text-muted-foreground">{site.url}</p>
                    </div>
                  </div>
                  <Button asChild variant="outline">
                    <Link to={`/sites/${site.id}/report`}>View report</Link>
                  </Button>
                </CardContent>
              </Card>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
