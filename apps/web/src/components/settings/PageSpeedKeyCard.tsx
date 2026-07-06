import { useState } from 'react'
import { useSettings } from '@/lib/queries/useSettings'
import { useSavePagespeedKey } from '@/lib/mutations/useSavePagespeedKey'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'

/**
 * Google PageSpeed Insights API key — powers the weekly + on-demand performance
 * scans. Without a key, PSI's unauthenticated quota is exhausted and every
 * measurement returns "Not yet measured". The key is stored team-wide and is
 * never displayed back; we only show whether one is configured.
 */
export function PageSpeedKeyCard() {
  const { data } = useSettings()
  const { mutate, isPending, isSuccess, error } = useSavePagespeedKey()
  const [apiKey, setApiKey] = useState('')
  const configured = data?.pagespeed_configured ?? false

  const save = () => mutate({ api_key: apiKey.trim() }, { onSuccess: () => setApiKey('') })

  return (
    <div className="rounded-xl border border-border bg-card p-5">
      <h2 className="mb-1 text-base font-medium">Performance (PageSpeed) API key</h2>
      <p className="mb-4 text-xs text-muted-foreground">
        Powers the weekly and on-demand performance scores. Create a free key in Google Cloud Console
        (enable the “PageSpeed Insights API”, then create an API key) and paste it here. Without a key,
        performance stays “Not yet measured”.
      </p>
      <div className="space-y-4">
        <div>
          <label htmlFor="ps-key" className="mb-1.5 block text-sm font-medium">
            API key {configured && <span className="text-emerald-600">· configured</span>}
          </label>
          <Input
            id="ps-key"
            type="password"
            placeholder={configured ? '•••••••••• (set — paste to replace, or leave blank & Save to clear)' : 'AIza…'}
            value={apiKey}
            onChange={(e) => setApiKey(e.target.value)}
          />
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
