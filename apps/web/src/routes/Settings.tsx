import { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { useSettings } from '@/lib/queries/useSettings';
import { useSaveSlackWebhook } from '@/lib/mutations/useSaveSlackWebhook';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';

const SLACK_WEBHOOK_REGEX = /^https:\/\/hooks\.slack\.com\//;

function isValidWebhookUrl(value: string): boolean {
  return value === '' || SLACK_WEBHOOK_REGEX.test(value);
}

export function Settings() {
  const { data, isLoading, isError } = useSettings();
  const { mutate, isPending } = useSaveSlackWebhook();

  const [webhookUrl, setWebhookUrl] = useState('');

  // Seed ONCE when data arrives — keyed on the primitive string/null to avoid a render loop.
  useEffect(() => {
    if (data) {
      setWebhookUrl(data.slack_webhook_url ?? '');
    }
  }, [data?.slack_webhook_url]); // eslint-disable-line react-hooks/exhaustive-deps

  const isValid = isValidWebhookUrl(webhookUrl);

  function handleSave() {
    if (!isValid) return;
    mutate(webhookUrl);
  }

  return (
    <div className="mx-auto max-w-2xl px-4 py-6">
      <div className="mb-5 flex items-baseline gap-3">
        <h1 className="text-xl font-semibold">Settings</h1>
        <Link to="/overview" className="text-sm text-zinc-600 underline-offset-4 hover:underline">← Overview</Link>
      </div>

      {isLoading && <p className="text-sm text-zinc-500">Loading…</p>}
      {isError && <p className="text-sm text-red-600">Couldn't load settings.</p>}

      {!isLoading && !isError && (
        <div className="rounded-lg border border-zinc-200 p-5">
          <h2 className="mb-4 text-base font-medium">Notifications</h2>

          <div className="space-y-4">
            <div>
              <label htmlFor="slack-webhook" className="mb-1.5 block text-sm font-medium text-zinc-700">
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
              <p className="mt-2 text-xs text-zinc-500">
                When set, down/recovery/SSL alerts will also post to this Slack channel.
              </p>
            </div>

            <p className="text-xs text-zinc-500">
              ✓ Email alerts always go to your account address.
            </p>

            <Button
              onClick={handleSave}
              disabled={!isValid || isPending}
            >
              {isPending ? 'Saving…' : 'Save'}
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}

export default Settings;
