import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Lock, RefreshCw, Globe } from 'lucide-react'
import type { Site, SiteStatus } from '@/types/api'

const STATUS_DOT: Record<SiteStatus, string> = {
  active: 'bg-emerald-500',
  pending: 'bg-amber-500',
  error: 'bg-red-500',
  offline: 'bg-zinc-400',
}

const sslOn = (s: Site) => s.ssl_status === 'valid' || s.ssl_status === 'enabled'

/** Free WordPress.com screenshot service — no per-site setup, cached by wp.com. */
function shot(url: string): string {
  return `https://s0.wp.com/mshots/v1/${encodeURIComponent(url)}?w=800&h=600`
}

/** ManageWP-style website card: live screenshot thumbnail + status + badges. */
export function SiteCard({ site }: { site: Site }) {
  const [imgError, setImgError] = useState(false)
  const host = site.url.replace(/^https?:\/\//, '').replace(/\/$/, '')
  const updates =
    (site.plugin_updates ?? 0) + (site.theme_updates ?? 0) + (site.core_update_available ? 1 : 0)

  return (
    <Link
      to={`/sites/${site.id}`}
      className="group flex flex-col overflow-hidden rounded-xl border border-border bg-card transition-shadow hover:shadow-md"
    >
      <div className="relative aspect-[4/3] overflow-hidden bg-muted">
        {!imgError ? (
          <img
            src={shot(site.url)}
            alt=""
            loading="lazy"
            onError={() => setImgError(true)}
            className="h-full w-full object-cover object-top transition-transform duration-300 group-hover:scale-[1.03]"
          />
        ) : (
          <div className="flex h-full w-full items-center justify-center text-muted-foreground">
            <Globe className="h-10 w-10" aria-hidden="true" />
          </div>
        )}

        {updates > 0 && (
          <span className="absolute right-2 top-2 inline-flex items-center rounded-full bg-amber-500 px-2 py-0.5 text-xs font-medium text-white shadow">
            {updates} update{updates === 1 ? '' : 's'}
          </span>
        )}
        {site.is_wpengine && (
          <span className="absolute left-2 top-2 rounded bg-black/70 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-white">
            WP Engine
          </span>
        )}
      </div>

      <div className="p-3">
        <div className="flex items-center gap-2">
          <span
            className={`h-2 w-2 shrink-0 rounded-full ${STATUS_DOT[site.status]}`}
            aria-label={site.status}
          />
          <p className="truncate text-sm font-medium">{site.label || host}</p>
          {sslOn(site) && (
            <Lock className="ml-auto h-3.5 w-3.5 shrink-0 text-emerald-600" aria-label="SSL enabled" />
          )}
        </div>
        <p className="mt-0.5 truncate text-xs text-muted-foreground">
          {host}
          {site.wp_version ? ` · WP ${site.wp_version}` : ''}
        </p>
        {site.core_update_available && (
          <span className="mt-2 inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] text-amber-700">
            <RefreshCw className="h-3 w-3" aria-hidden="true" /> Core {site.core_update_version}
          </span>
        )}
      </div>
    </Link>
  )
}
