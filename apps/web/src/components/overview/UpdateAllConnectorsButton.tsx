import { useState } from 'react'
import { ArrowUpCircle, RefreshCw } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { useUpdateAllConnectors } from '@/lib/mutations/useUpdateAllConnectors'

/**
 * Fleet action: update every active site's connector to the latest release.
 * POST /overview/update-connectors — the server skips sites already current.
 */
export function UpdateAllConnectorsButton() {
  const { mutate, isPending } = useUpdateAllConnectors()
  const [count, setCount] = useState<number | null>(null)

  if (count !== null && !isPending) {
    return (
      <Button variant="outline" size="sm" disabled>
        {count === 0 ? 'Connectors up to date' : `Updating ${count} connector${count === 1 ? '' : 's'}…`}
      </Button>
    )
  }

  return (
    <Button
      variant="outline"
      size="sm"
      disabled={isPending}
      onClick={() => mutate(undefined, { onSuccess: (r) => setCount(r.scheduled_count) })}
    >
      {isPending ? (
        <RefreshCw className="mr-1.5 h-3.5 w-3.5 animate-spin" aria-hidden="true" />
      ) : (
        <ArrowUpCircle className="mr-1.5 h-3.5 w-3.5" aria-hidden="true" />
      )}
      Update all connectors
    </Button>
  )
}
