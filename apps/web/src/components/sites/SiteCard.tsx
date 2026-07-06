import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Lock, Globe, AlertTriangle } from 'lucide-react'
import type { Site } from '@/types/api'

const sslOn = (s: Site) => s.ssl_status === 'valid' || s.ssl_status === 'enabled'

/** Free WordPress.com screenshot service — no per-site setup, cached by wp.com. */
function shot(url: string): string {
  return `https://s0.wp.com/mshots/v1/${encodeURIComponent(url)}?w=800&h=600`
}
function favicon(host: string): string {
  return `https://www.google.com/s2/favicons?sz=64&domain=${host}`
}

/** ManageWP-style website card: live screenshot thumbnail + favicon + badges. */
export function SiteCard({ site }: { site: Site }) {
  const [imgError, setImgError] = useState(false)
  const [imgLoaded, setImgLoaded] = useState(false)
  const [favError, setFavError] = useState(false)
  const host = site.url.replace(/^https?:\/\//, '').replace(/\/$/, '')
  const updates =
    (site.plugin_updates ?? 0) + (site.theme_updates ?? 0) + (site.core_update_available ? 1 : 0)
  const problem = site.status === 'offline' || site.status === 'error'

  return (
    <Link
      to={`/sites/${site.id}`}
      className="group flex flex-col overflow-hidden rounded-xl border border-border bg-card transition-shadow hover:shadow-md"
    >
      <div className="relative aspect-[4/3] overflow-hidden bg-muted">
        {!imgError && (
          <img
            src={shot(site.url)}
            alt=""
            loading="lazy"
            onLoad={() => setImgLoaded(true)}
            onError={() => setImgError(true)}
            className={`h-full w-full object-cover object-top transition-all duration-300 group-hover:scale-[1.03] ${
              imgLoaded ? 'opacity-100' : 'opacity-0'
            }`}
          />
        )}
        {!imgLoaded && !imgError && <div className="absolute inset-0 animate-pulse bg-muted" aria-hidden="true" />}
        {imgError && (
          <div className="absolute inset-0 flex items-center justify-center text-muted-foreground">
            <Globe className="h-10 w-10" aria-hidden="true" />
          </div>
        )}

        {updates > 0 && (
          <span className="absolute right-2 top-2 inline-flex items-center rounded-full bg-amber-500 px-2 py-0.5 text-xs font-medium text-white shadow-sm">
            {updates} update{updates === 1 ? '' : 's'}
          </span>
        )}
        {site.is_wpengine && (
          <span className="absolute left-2 top-2 rounded bg-black/70 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-white">
            WP Engine
          </span>
        )}
        {problem && (
          <span className="absolute bottom-2 left-2 inline-flex items-center gap-1 rounded-full bg-red-600/90 px-2 py-0.5 text-[11px] font-medium text-white shadow-sm">
            <AlertTriangle className="h-3 w-3" aria-hidden="true" />
            {site.status === 'offline' ? 'Offline' : 'Error'}
          </span>
        )}
      </div>

      <div className="flex items-center gap-2 p-3">
        {!favError ? (
          <img
            src={favicon(host)}
            alt=""
            width={16}
            height={16}
            loading="lazy"
            onError={() => setFavError(true)}
            className="h-4 w-4 shrink-0 rounded-sm"
          />
        ) : (
          <Globe className="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
        )}
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-1.5">
            <p className="truncate text-sm font-medium">{site.label || host}</p>
            {sslOn(site) && (
              <Lock className="ml-auto h-3.5 w-3.5 shrink-0 text-emerald-600" aria-label="SSL enabled" />
            )}
          </div>
          <p className="truncate text-xs text-muted-foreground">
            {host}
            {site.wp_version ? ` · WP ${site.wp_version}` : ''}
          </p>
        </div>
      </div>
    </Link>
  )
}
