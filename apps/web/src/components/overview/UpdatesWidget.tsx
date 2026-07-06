import { Link } from 'react-router-dom'
import { Puzzle, Palette, RefreshCw } from 'lucide-react'
import { BulkUpdatePluginsButton } from './BulkUpdatePluginsButton'
import { BulkUpdateThemesButton } from './BulkUpdateThemesButton'
import { UpdateAllConnectorsButton } from './UpdateAllConnectorsButton'

interface Props {
  plugins: number
  themes: number
  cores: number
}

/** ManageWP-style Updates column: big counts per type + bulk actions. */
export function UpdatesWidget({ plugins, themes, cores }: Props) {
  return (
    <div className="rounded-xl border border-border bg-card">
      <div className="border-b border-border px-5 py-3">
        <h2 className="text-base font-medium">Updates</h2>
      </div>

      <div className="divide-y divide-border">
        <div className="flex items-center gap-3 px-5 py-4">
          <Puzzle className="h-5 w-5 shrink-0 text-muted-foreground" aria-hidden="true" />
          <Link to="/overview/plugins" className="flex items-baseline gap-2 hover:underline">
            <span className="text-2xl font-semibold tabular-nums">{plugins}</span>
            <span className="text-sm text-muted-foreground">Plugins</span>
          </Link>
          <div className="ml-auto">
            <BulkUpdatePluginsButton pendingCount={plugins} />
          </div>
        </div>

        <div className="flex items-center gap-3 px-5 py-4">
          <Palette className="h-5 w-5 shrink-0 text-muted-foreground" aria-hidden="true" />
          <Link to="/overview/themes" className="flex items-baseline gap-2 hover:underline">
            <span className="text-2xl font-semibold tabular-nums">{themes}</span>
            <span className="text-sm text-muted-foreground">Themes</span>
          </Link>
          <div className="ml-auto">
            <BulkUpdateThemesButton pendingCount={themes} />
          </div>
        </div>

        <div className="flex items-center gap-3 px-5 py-4">
          <RefreshCw className="h-5 w-5 shrink-0 text-muted-foreground" aria-hidden="true" />
          <div className="flex items-baseline gap-2">
            <span className="text-2xl font-semibold tabular-nums">{cores}</span>
            <span className="text-sm text-muted-foreground">WordPress</span>
          </div>
        </div>
      </div>

      <div className="border-t border-border px-5 py-3">
        <UpdateAllConnectorsButton />
      </div>
    </div>
  )
}
