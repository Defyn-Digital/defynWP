import { useMemo, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Plus } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { useSites } from '@/lib/queries/useSites'
import { SitesFilterSidebar, matchesFilter, type FilterKey } from '@/components/sites/SitesListFilters'
import { SiteCard } from '@/components/sites/SiteCard'
import { PageHeader } from '@/components/layout/PageHeader'

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
  const [active, setActive] = useState<FilterKey>('all')
  const [query, setQuery] = useState('')

  const sites = data?.sites ?? []

  const filtered = useMemo(() => {
    const lower = query.trim().toLowerCase()
    return sites.filter(
      (s) =>
        matchesFilter(s, active) &&
        (lower === '' ||
          s.url.toLowerCase().includes(lower) ||
          s.label.toLowerCase().includes(lower)),
    )
  }, [sites, active, query])

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
            Generate a connection code on a WordPress site running the connector, then paste it into Add
            site.
          </p>
        </div>
      )}

      {sites.length > 0 && (
        <div className="flex flex-col gap-6 md:flex-row">
          <SitesFilterSidebar
            sites={sites}
            active={active}
            setActive={setActive}
            query={query}
            setQuery={setQuery}
          />
          <div className="min-w-0 flex-1">
            {filtered.length === 0 ? (
              <p className="text-sm text-muted-foreground">No sites match your filters.</p>
            ) : (
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5">
                {filtered.map((s) => (
                  <SiteCard key={s.id} site={s} />
                ))}
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  )
}
