import { useState } from 'react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useSetReportBranding } from '@/lib/mutations/useSetReportBranding'
import type { Site } from '@/types/api'

interface Props {
  site: Site
}

/**
 * Per-site white-label report branding. Overrides the global "Prepared by"
 * name, accent colour and logo for this client's reports. Blank = use the
 * team default. For agencies that deliver under different brands per client.
 */
export function SiteReportBrandingCard({ site }: Props) {
  const [agency, setAgency] = useState(site.report_agency_name ?? '')
  const [accent, setAccent] = useState(site.report_accent_color ?? '')
  const [logo, setLogo] = useState(site.report_logo_url ?? '')
  const { mutate, isPending, isSuccess, error } = useSetReportBranding(site.id)

  const save = () =>
    mutate({ agency_name: agency.trim(), accent_color: accent.trim(), logo_url: logo.trim() })

  return (
    <div className="rounded-xl border border-border bg-card p-5">
      <h2 className="text-sm font-semibold">Report branding (white-label)</h2>
      <p className="mt-1 text-sm text-muted-foreground">
        Overrides the "Prepared by" name, accent colour and logo on this client's reports. Leave blank to use your default.
      </p>

      <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div className="space-y-1.5">
          <Label htmlFor="rb-agency">Prepared by</Label>
          <Input id="rb-agency" value={agency} placeholder="e.g. uberbrand" onChange={(e) => setAgency(e.target.value)} />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="rb-accent">Accent colour</Label>
          <Input id="rb-accent" value={accent} placeholder="#0F766E" onChange={(e) => setAccent(e.target.value)} />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="rb-logo">Logo URL</Label>
          <Input id="rb-logo" value={logo} placeholder="https://…/logo.png" onChange={(e) => setLogo(e.target.value)} />
        </div>
      </div>

      <div className="mt-4 flex items-center gap-3">
        <Button size="sm" onClick={save} disabled={isPending}>
          {isPending ? 'Saving…' : 'Save branding'}
        </Button>
        {isSuccess && !isPending && <span className="text-sm text-emerald-600">Saved</span>}
        {error && <span className="text-sm text-red-600">{error.message}</span>}
      </div>
    </div>
  )
}
