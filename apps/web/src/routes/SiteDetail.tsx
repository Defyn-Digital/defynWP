import { useParams, Link } from 'react-router-dom';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { useSite } from '@/lib/queries/useSite';
import { ApiError } from '@/lib/apiClient';
import { SiteRuntimeInfo } from '@/components/sites/SiteRuntimeInfo';
import { SiteActions } from '@/components/sites/SiteActions';
import { SiteActivityPanel } from '@/components/sites/SiteActivityPanel';
import { SitePluginsPanel } from '@/components/sites/SitePluginsPanel';
import { useSiteThemes } from '@/lib/queries/useSiteThemes';
import { SiteThemesPanel } from '@/components/sites/SiteThemesPanel';

export default function SiteDetail() {
  const { id } = useParams<{ id: string }>();
  const siteId = Number(id);
  const { data, isLoading, isError, error } = useSite(siteId, { pollWhilePending: 2000 });
  const { data: themesData, isLoading: themesLoading } = useSiteThemes(siteId);
  const activeTheme = themesData?.themes.find((t) => t.is_active);

  const headerChip = (
    <span
      aria-label="Active theme"
      className="ml-2 inline-flex items-center rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-700"
    >
      Active theme:{' '}
      {themesLoading ? (
        <span className="ml-1 inline-block h-3 w-16 animate-pulse rounded bg-zinc-300" aria-hidden="true" />
      ) : activeTheme ? (
        <span className="ml-1 font-medium">{activeTheme.name}</span>
      ) : (
        <span className="ml-1">—</span>
      )}
    </span>
  );

  if (isError) {
    const apiErr = error as ApiError;
    if (apiErr.code === 'sites.not_found') {
      return (
        <div className="min-h-screen p-8 max-w-3xl mx-auto">
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
      <div className="min-h-screen p-8 max-w-3xl mx-auto">
        <Alert><AlertDescription>{apiErr.message}</AlertDescription></Alert>
      </div>
    );
  }

  if (isLoading || !data) {
    return (
      <div className="min-h-screen p-8 max-w-3xl mx-auto">
        <p className="text-sm text-zinc-500">Loading…</p>
      </div>
    );
  }

  return (
    <div className="min-h-screen p-8 max-w-3xl mx-auto">
      <Card>
        <CardHeader>
          <div className="flex items-center">
            <CardTitle>{data.label || data.url}</CardTitle>
            {headerChip}
          </div>
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

          {data.status !== 'pending' && <SitePluginsPanel siteId={siteId} />}

          {data.status !== 'pending' && <SiteThemesPanel siteId={siteId} />}

          <Button asChild variant="outline">
            <Link to="/sites">Back to sites</Link>
          </Button>
        </CardContent>
      </Card>
    </div>
  );
}
