import { useEffect, useState } from 'react'
import { useSettings } from '@/lib/queries/useSettings'
import { useSaveConnectorRelease } from '@/lib/mutations/useSaveConnectorRelease'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'

/**
 * Connector release the dashboard offers to sites via "Update connector".
 * Set once per release (version + package URL + SHA-256). This is what makes
 * "Update all connectors" work without the dashboard needing to reach GitHub.
 */
export function ConnectorReleaseCard() {
  const { data } = useSettings()
  const { mutate, isPending, isSuccess, error } = useSaveConnectorRelease()

  const [version, setVersion] = useState('')
  const [packageUrl, setPackageUrl] = useState('')
  const [sha256, setSha256] = useState('')

  useEffect(() => {
    const r = data?.connector_release
    setVersion(r?.version ?? '')
    setPackageUrl(r?.package_url ?? '')
    setSha256(r?.sha256 ?? '')
  }, [data?.connector_release])

  const save = () =>
    mutate({ version: version.trim(), package_url: packageUrl.trim(), sha256: sha256.trim() })

  return (
    <div className="rounded-xl border border-border bg-card p-5">
      <h2 className="mb-1 text-base font-medium">Connector release</h2>
      <p className="mb-4 text-xs text-muted-foreground">
        The connector version the dashboard offers via "Update connector". Set these three values once per
        release; clear all three to fall back to auto-detection. This is what powers one-click "Update all
        connectors".
      </p>
      <div className="space-y-4">
        <div>
          <label htmlFor="cr-version" className="mb-1.5 block text-sm font-medium">Version</label>
          <Input id="cr-version" placeholder="0.3.2" value={version} onChange={(e) => setVersion(e.target.value)} />
        </div>
        <div>
          <label htmlFor="cr-url" className="mb-1.5 block text-sm font-medium">Package URL (.zip)</label>
          <Input id="cr-url" placeholder="https://github.com/…/defyn-connector-0.3.2.zip" value={packageUrl} onChange={(e) => setPackageUrl(e.target.value)} />
        </div>
        <div>
          <label htmlFor="cr-sha" className="mb-1.5 block text-sm font-medium">SHA-256</label>
          <Input id="cr-sha" placeholder="64 hex characters" value={sha256} onChange={(e) => setSha256(e.target.value)} />
        </div>
        <div className="flex items-center gap-3">
          <Button onClick={save} disabled={isPending}>{isPending ? 'Saving…' : 'Save'}</Button>
          {isSuccess && !isPending && <span className="text-sm text-emerald-600">Saved</span>}
          {error && <span className="text-sm text-red-600">{error.message}</span>}
        </div>
      </div>
    </div>
  )
}
