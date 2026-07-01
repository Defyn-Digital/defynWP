import { useParams, Link } from 'react-router-dom'
import { ChevronRight } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { useSite } from '@/lib/queries/useSite'
import { SiteConnectorCard } from '@/components/sites/SiteConnectorCard'
import { ApiError } from '@/lib/apiClient'
import { SiteRuntimeInfo } from '@/components/sites/SiteRuntimeInfo'
import { SiteActions } from '@/components/sites/SiteActions'
import { SiteActivityPanel } from '@/components/sites/SiteActivityPanel'
import { SiteCoreCard } from '@/components/sites/SiteCoreCard'
import { SitePluginsPanel } from '@/components/sites/SitePluginsPanel'
import { SiteMajorUpdatesSettingsRow } from '@/components/sites/SiteMajorUpdatesSettingsRow'
import { SiteMuteAlertsSettingsRow } from '@/components/sites/SiteMuteAlertsSettingsRow'
import { useSiteThemes } from '@/lib/queries/useSiteThemes'
import { SiteThemesPanel } from '@/components/sites/SiteThemesPanel'
import { SiteSecurityPanel } from '@/components/sites/SiteSecurityPanel'
import { SiteBrokenLinksPanel } from '@/components/sites/SiteBrokenLinksPanel'
import { SitePerformancePanel } from '@/components/sites/SitePerformancePanel'
import { SiteAnalyticsPanel } from '@/components/sites/SiteAnalyticsPanel'
import { IncidentHistoryPanel } from '@/components/sites/IncidentHistoryPanel'
import { SiteReportsPanel } from '@/components/reports/SiteReportsPanel'

const STATUS_PILL: Record<string, { dot: string; text: string; label: string }> = {
  active: { dot: 'bg-emerald-500', text: 'text-emerald-700', label: 'Connected' },
  pending: { dot: 'bg-amber-500', text: 'text-amber-700', label: 'Connecting…' },
  error: { dot: 'bg-red-500', text: 'text-red-700', label: 'Error' },
  offline: { dot: 'bg-zinc-400', text: 'text-zinc-600', label: 'Offline' },
}

export default function SiteDetail() {
  const { id } = useParams<{ id: string }>()
  const siteId = Number(id)
  const { data, isLoading, isError, error } = useSite(siteId, { pollWhilePending: 2000 })
  const { data: themesData } = useSiteThemes(siteId)
  const activeTheme = themesData?.themes.find((t) => t.is_active)

  if (isError) {
    const apiErr = error as ApiError
    if (apiErr.code === 'sites.not_found') {
      return (
        <div className="p-4 md:p-6">
          <Card>
            <CardHeader><CardTitle>Site not found</CardTitle></CardHeader>
            <CardContent>
              <Button asChild><Link to="/sites">Back to sites</Link></Button>
            </CardContent>
          </Card>
        </div>
      )
    }
    return (
      <div className="p-4 md:p-6">
        <Alert><AlertDescription>{apiErr.message}</AlertDescription></Alert>
      </div>
    )
  }

  if (isLoading || !data) {
    return (
      <div className="space-y-4 p-4 md:p-6">
        <div className="h-28 animate-pulse rounded-xl bg-muted" />
        <div className="h-48 animate-pulse rounded-xl bg-muted" />
      </div>
    )
  }

  const pill = STATUS_PILL[data.status] ?? STATUS_PILL.offline
  const notPending = data.status !== 'pending'

  return (
    <div className="space-y-6 p-4 md:p-6">
      <nav className="flex items-center gap-1 text-xs text-muted-foreground">
        <Link to="/sites" className="text-primary hover:underline">Sites</Link>
        <ChevronRight className="h-3 w-3" aria-hidden="true" />
        <span className="truncate">{data.label || data.url}</span>
      </nav>

      <div className="rounded-xl border border-border bg-card p-5">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              <h1 className="truncate text-lg font-semibold">{data.label || data.url}</h1>
              <span className={`inline-flex items-center gap-1.5 rounded-full bg-muted px-2.5 py-0.5 text-xs ${pill.text}`}>
                <span className={`h-1.5 w-1.5 rounded-full ${pill.dot}`} aria-hidden="true" />
                {pill.label}
              </span>
              {activeTheme && (
                <span className="rounded-full bg-muted px-2.5 py-0.5 text-xs text-muted-foreground">
                  Theme: {activeTheme.name}
                </span>
              )}
              {data.is_wpengine && (
                <span className="rounded-full bg-indigo-50 px-2.5 py-0.5 text-xs font-medium text-indigo-600">WP Engine</span>
              )}
              {data.connector_version && (
                <span className="rounded-full bg-muted px-2.5 py-0.5 text-xs text-muted-foreground">Connector v{data.connector_version}</span>
              )}
            </div>
            <p className="mt-1 truncate text-sm text-muted-foreground">
              {data.url.replace(/^https?:\/\//, '')}
              {data.wp_version ? ` · WordPress ${data.wp_version}` : ''}
              {data.php_version ? ` · PHP ${data.php_version}` : ''}
            </p>
          </div>
          <Button asChild variant="outline" size="sm">
            <Link to={`/sites/${id}/report`}>Generate report</Link>
          </Button>
        </div>

        {data.status === 'error' && data.last_error && (
          <Alert className="mt-4"><AlertDescription>{data.last_error}</AlertDescription></Alert>
        )}

        {notPending && (
          <div className="mt-4 border-t border-border pt-4">
            <SiteRuntimeInfo site={data} />
          </div>
        )}
      </div>

      {notPending && <SiteActions site={data} />}

      {notPending && <SiteConnectorCard site={data} />}

      {notPending && (
        <div className="space-y-4">
          <SiteCoreCard siteId={siteId} />
          <SitePluginsPanel siteId={siteId} />
          <SiteThemesPanel siteId={siteId} />
        </div>
      )}

      {notPending && (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          <SiteSecurityPanel siteId={siteId} />
          <SiteBrokenLinksPanel siteId={siteId} />
          <SitePerformancePanel siteId={siteId} />
          <SiteAnalyticsPanel siteId={siteId} />
          <IncidentHistoryPanel siteId={siteId} />
          <SiteActivityPanel site={data} />
        </div>
      )}

      {notPending && (
        <SiteReportsPanel
          siteId={siteId}
          clientEmail={data.client_email ?? null}
          autoSendReports={data.auto_send_reports}
        />
      )}

      <div className="rounded-xl border border-border bg-card p-5">
        <h2 className="mb-3 text-sm font-medium">Settings</h2>
        <div className="space-y-3">
          <SiteMajorUpdatesSettingsRow site={data} />
          <SiteMuteAlertsSettingsRow site={data} />
        </div>
      </div>
    </div>
  )
}
