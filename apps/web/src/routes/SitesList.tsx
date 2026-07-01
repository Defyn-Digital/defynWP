import { useMemo, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { ChevronRight, Lock, RefreshCw, Plus } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { useSites } from '@/lib/queries/useSites'
import { SitesListFilters, type StatusKey } from '@/components/sites/SitesListFilters'
import { PageHeader } from '@/components/layout/PageHeader'
import { formatRelativeTime } from '@/lib/formatRelativeTime'
import type { SiteStatus } from '@/types/api'

const STATUS_DOT: Record<SiteStatus, string> = {
  active: 'bg-emerald-500',
  pending: 'bg-amber-500',
  error: 'bg-red-500',
  offline: 'bg-zinc-400',
}

export default function SitesList() {
  const [searchParams] = useSearchParams()
  const filterParam = searchParams.get('filter')
  const filter =
    filterParam === 'has-plugin-updates' ||
    filterParam === 'has-theme-updates' ||
    filterParam === 'has-core-update'
      ? filterParam
      : undefined
  const { data, isLoading, isError, error } = useSites({ filter })
  const [statusFilter, setStatusFilter] = useState<StatusKey>('all')
  const [query, setQuery] = useState('')

  const sites = data?.sites ?? []

  const filtered = useMemo(() => {
    const lower = query.trim().toLowerCase()
    return sites.filter((s) => {
      const matchesStatus = statusFilter === 'all' || s.status === statusFilter
      const matchesQuery =
        lower === '' ||
        s.url.toLowerCase().includes(lower) ||
        s.label.toLowerCase().includes(lower)
      return matchesStatus && matchesQuery
    })
  }, [sites, statusFilter, query])

  return (
    <div className="space-y-6 p-4 md:p-6">
      <PageHeader
        title="Sites"
        subtitle={`${sites.length} site${sites.length === 1 ? '' : 's'}`}
        actions={
          <Button asChild>
            <Link to="/sites/add" className="inline-flex items-center gap-1.5">
              <Plus className="h-4 w-4" aria-hidden="true" />
              Add site
            </Link>
          </Button>
        }
      />

      {isLoading && <p className="text-sm text-muted-foreground">Loading…</p>}
      {isError && (
        <p className="text-sm text-red-600">
          Could not load sites. {(error as { message?: string }).message}
        </p>
      )}

      {sites.length === 0 && !isLoading && !isError && (
        <div className="rounded-xl border border-border bg-card p-6">
          <p className="font-medium">No sites yet</p>
          <p className="mt-1 text-sm text-muted-foreground">
            Generate a connection code on a WordPress site running the connector, then paste it into Add site.
          </p>
        </div>
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
        <p className="text-sm text-muted-foreground">No sites match your filters.</p>
      )}

      {filtered.length > 0 && (
        <div className="overflow-hidden rounded-xl border border-border bg-card">
          {filtered.map((site, i) => (
            <Link
              key={site.id}
              to={`/sites/${site.id}`}
              className={`flex items-center gap-3 px-4 py-3 transition-colors hover:bg-muted/50 ${
                i > 0 ? 'border-t border-border' : ''
              }`}
            >
              <span
                className={`h-2 w-2 shrink-0 rounded-full ${STATUS_DOT[site.status]}`}
                aria-label={site.status}
              />
              <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium">{site.label || site.url}</p>
                <p className="truncate text-xs text-muted-foreground">
                  {site.url.replace(/^https?:\/\//, '')}
                  {site.wp_version ? ` · WP ${site.wp_version}` : ''}
                </p>
              </div>
              {(site.plugin_updates ?? 0) + (site.theme_updates ?? 0) > 0 && (
                <span className="hidden shrink-0 items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs text-amber-700 sm:inline-flex">
                  {(site.plugin_updates ?? 0) + (site.theme_updates ?? 0)} update{(site.plugin_updates ?? 0) + (site.theme_updates ?? 0) === 1 ? '' : 's'}
                </span>
              )}
              {site.core_update_available && (
                <span className="hidden shrink-0 items-center gap-1 rounded-full bg-amber-100 px-2.5 py-0.5 text-xs text-amber-700 sm:inline-flex">
                  <RefreshCw className="h-3 w-3" aria-hidden="true" />
                  Core {site.core_update_version}
                </span>
              )}
              {site.ssl_status === 'valid' && (
                <Lock className="hidden h-4 w-4 shrink-0 text-emerald-600 sm:block" aria-label="SSL valid" />
              )}
              <span className="hidden w-28 shrink-0 text-right text-xs text-muted-foreground md:block">
                {site.last_contact_at ? formatRelativeTime(site.last_contact_at) : '—'}
              </span>
              <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
            </Link>
          ))}
        </div>
      )}
    </div>
  )
}
