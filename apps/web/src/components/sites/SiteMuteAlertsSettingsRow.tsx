import { Switch } from '@/components/ui/switch';
import { useToggleMuteAlerts } from '@/lib/mutations/useToggleMuteAlerts';
import type { Site } from '@/types/api';

interface SiteMuteAlertsSettingsRowProps {
  site: Site;
}

/**
 * Settings row for muting/unmuting alert notifications for a site.
 * Always rendered on SiteDetail — incidents & SSL are still tracked;
 * no notifications are sent while muted.
 *
 * The switch is bound to site.alerts_muted and calls the
 * useToggleMuteAlerts mutation when toggled.
 */
export function SiteMuteAlertsSettingsRow({ site }: SiteMuteAlertsSettingsRowProps) {
  const toggle = useToggleMuteAlerts(site.id);

  return (
    <div
      id="mute-alerts-settings"
      className="flex items-start gap-4 rounded-md border p-4"
    >
      <Switch
        checked={site.alerts_muted}
        disabled={toggle.isPending}
        onCheckedChange={(checked) => toggle.mutate(checked)}
        aria-label="Mute alerts for this site"
      />
      <div className="flex-1">
        <p className="font-medium">Mute alerts for this site</p>
        <p className="text-sm text-muted-foreground">
          Incidents &amp; SSL are still tracked — no notifications are sent.
        </p>
      </div>
    </div>
  );
}
