import type { Site } from '@/types/api'
import { Input } from '@/components/ui/input'

export type FilterKey = 'all' | 'active' | 'offline' | 'error' | 'updates' | 'ssl-off' | 'wpengine'

const sslOn = (s: Site) => s.ssl_status === 'valid' || s.ssl_status === 'enabled'
const hasUpdates = (s: Site) =>
  (s.plugin_updates ?? 0) + (s.theme_updates ?? 0) > 0 || s.core_update_available

export function matchesFilter(s: Site, k: FilterKey): boolean {
  switch (k) {
    case 'all':
      return true
    case 'active':
      return s.status === 'active'
    case 'offline':
      return s.status === 'offline'
    case 'error':
      return s.status === 'error'
    case 'updates':
      return hasUpdates(s)
    case 'ssl-off':
      return !sslOn(s)
    case 'wpengine':
      return Boolean(s.is_wpengine)
  }
}

const GROUPS: ReadonlyArray<{ title: string; items: ReadonlyArray<{ key: FilterKey; label: string }> }> = [
  {
    title: 'Status',
    items: [
      { key: 'all', label: 'All sites' },
      { key: 'active', label: 'Active' },
      { key: 'offline', label: 'Offline' },
      { key: 'error', label: 'Error' },
    ],
  },
  {
    title: 'Needs attention',
    items: [
      { key: 'updates', label: 'Updates available' },
      { key: 'ssl-off', label: 'SSL not enabled' },
    ],
  },
  {
    title: 'Hosting',
    items: [{ key: 'wpengine', label: 'WP Engine' }],
  },
]

interface Props {
  sites: Site[]
  active: FilterKey
  setActive: (k: FilterKey) => void
  query: string
  setQuery: (q: string) => void
}

/** ManageWP-style left filter rail: search + grouped status filters with counts. */
export function SitesFilterSidebar({ sites, active, setActive, query, setQuery }: Props) {
  const count = (k: FilterKey) => sites.filter((s) => matchesFilter(s, k)).length

  return (
    <aside className="w-full shrink-0 space-y-5 md:w-56">
      <Input
        type="search"
        placeholder="Search sites…"
        value={query}
        onChange={(e) => setQuery(e.target.value)}
        aria-label="Search sites"
      />
      {GROUPS.map((g) => (
        <div key={g.title}>
          <p className="mb-1 px-2 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
            {g.title}
          </p>
          <ul className="space-y-0.5">
            {g.items.map((it) => {
              const n = count(it.key)
              const on = active === it.key
              return (
                <li key={it.key}>
                  <button
                    type="button"
                    onClick={() => setActive(it.key)}
                    className={`flex w-full items-center justify-between rounded-md px-2 py-1.5 text-sm transition-colors ${
                      on ? 'bg-primary/10 font-medium text-primary' : 'text-foreground hover:bg-muted'
                    }`}
                  >
                    <span>{it.label}</span>
                    <span className={`text-xs ${on ? 'text-primary' : 'text-muted-foreground'}`}>{n}</span>
                  </button>
                </li>
              )
            })}
          </ul>
        </div>
      ))}
    </aside>
  )
}
