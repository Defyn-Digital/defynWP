import { useParams, Link } from 'react-router-dom';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { useSite } from '@/lib/queries/useSite';
import { ApiError } from '@/lib/apiClient';
import { SiteRuntimeInfo } from '@/components/sites/SiteRuntimeInfo';
import { SiteActions } from '@/components/sites/SiteActions';
import { SiteActivityPanel } from '@/components/sites/SiteActivityPanel';

export default function SiteDetail() {
  const { id } = useParams<{ id: string }>();
  const siteId = Number(id);
  const { data, isLoading, isError, error } = useSite(siteId, { pollWhilePending: 2000 });

  if (isError) {
    const apiErr = error as ApiError;
    if (apiErr.code === 'sites.not_found') {
      return (
        <div className="min-h-screen p-8 max-w-xl mx-auto">
          <Card>
            <CardHeader>
              <CardTitle>Site not found</CardTitle>
            </CardHeader>
            <CardContent>
              <Button asChild>
                <Link to="/sites">Back to sites</Link>
              </Button>
            </CardContent>
          </Card>
        </div>
      );
    }
    return (
      <div className="min-h-screen p-8 max-w-xl mx-auto">
        <Alert><AlertDescription>{apiErr.message}</AlertDescription></Alert>
      </div>
    );
  }

  if (isLoading || !data) {
    return (
      <div className="min-h-screen p-8 max-w-xl mx-auto">
        <p className="text-sm text-zinc-500">Loading…</p>
      </div>
    );
  }

  return (
    <div className="min-h-screen p-8 max-w-xl mx-auto">
      <Card>
        <CardHeader>
          <CardTitle>{data.label || data.url}</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <p className="text-sm text-zinc-600">{data.url}</p>

          {data.status === 'pending' && (
            <p className="text-sm">Connecting to the site…</p>
          )}
          {data.status === 'active' && (
            <p className="text-sm text-green-700">
              Connected. Last contact: {data.last_contact_at}.
            </p>
          )}
          {data.status === 'error' && data.last_error && (
            <Alert><AlertDescription>{data.last_error}</AlertDescription></Alert>
          )}

          {data.status !== 'pending' && <SiteRuntimeInfo site={data} />}

          {data.status !== 'pending' && <SiteActions site={data} />}

          {data.status !== 'pending' && <SiteActivityPanel site={data} />}

          <Button asChild variant="outline">
            <Link to="/sites">Back to sites</Link>
          </Button>
        </CardContent>
      </Card>
    </div>
  );
}
