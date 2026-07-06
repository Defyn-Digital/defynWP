import { useState, useEffect } from 'react'
import { useSettings } from '@/lib/queries/useSettings'
import { useSaveSlackWebhook } from '@/lib/mutations/useSaveSlackWebhook'
import { ReportBrandingCard } from '@/components/settings/ReportBrandingCard'
import { ConnectorReleaseCard } from '@/components/settings/ConnectorReleaseCard'
import { PageSpeedKeyCard } from '@/components/settings/PageSpeedKeyCard'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { PageHeader } from '@/components/layout/PageHeader'

const SLACK_WEBHOOK_REGEX = /^https:\/\/hooks\.slack\.com\//

function isValidWebhookUrl(value: string): boolean {
  return value === '' || SLACK_WEBHOOK_REGEX.test(value)
}

export function Settings() {
  const { data, isLoading, isError } = useSettings()
  const { mutate, isPending } = useSaveSlackWebhook()

  const [webhookUrl, setWebhookUrl] = useState('')

  useEffect(() => {
    if (data) {
      setWebhookUrl(data.slack_webhook_url ?? '')
    }
  }, [data?.slack_webhook_url]) // eslint-disable-line react-hooks/exhaustive-deps

  const isValid = isValidWebhookUrl(webhookUrl)

  function handleSave() {
    if (!isValid) return
    mutate(webhookUrl)
  }

  return (
    <div className="space-y-6 p-4 md:p-6">
      <PageHeader title="Settings" subtitle="Notifications and report branding" />

      {isLoading && <p className="text-sm text-muted-foreground">Loading…</p>}
      {isError && <p className="text-sm text-red-600">Couldn't load settings.</p>}

      {!isLoading && !isError && (
        <div className="max-w-2xl space-y-5">
          <div className="rounded-xl border border-border bg-card p-5">
            <h2 className="mb-4 text-base font-medium">Notifications</h2>
            <div className="space-y-4">
              <div>
                <label htmlFor="slack-webhook" className="mb-1.5 block text-sm font-medium">
                  Slack webhook URL
                </label>
                <Input
                  id="slack-webhook"
                  type="url"
                  placeholder="https://hooks.slack.com/services/…"
                  value={webhookUrl}
                  onChange={(e) => setWebhookUrl(e.target.value)}
                  aria-describedby={!isValid ? 'webhook-error' : undefined}
                />
                {!isValid && (
                  <p id="webhook-error" className="mt-1.5 text-sm text-red-600">
                    Must start with https://hooks.slack.com/
                  </p>
                )}
                <p className="mt-2 text-xs text-muted-foreground">
                  When set, down/recovery/SSL alerts will also post to this Slack channel.
                </p>
              </div>

              <p className="text-xs text-muted-foreground">
                Email alerts always go to your account address.
              </p>

              <Button onClick={handleSave} disabled={!isValid || isPending}>
                {isPending ? 'Saving…' : 'Save'}
              </Button>
            </div>
          </div>

          <ReportBrandingCard />

          <ConnectorReleaseCard />

          <PageSpeedKeyCard />
        </div>
      )}
    </div>
  )
}

export default Settings
