import { Switch } from '@/components/ui/switch';
import { useToggleCoreAllowMajor } from '@/lib/mutations/useToggleCoreAllowMajor';
import type { Site } from '@/types/api';

interface SiteMajorUpdatesSettingsRowProps {
  site: Site;
}

/**
 * Settings row for allowing/blocking major WordPress version upgrades.
 * Always rendered on SiteDetail — NOT conditional on update availability (plan-bug trap #9).
 *
 * The switch is bound to site.core_allow_major and calls the
 * useToggleCoreAllowMajor mutation when toggled. Clicking "Manage settings"
 * in the blocked-major-available card state scrolls to this element via
 * its id="major-updates-settings" anchor.
 *
 * Spec § 5.3.
 */
export function SiteMajorUpdatesSettingsRow({ site }: SiteMajorUpdatesSettingsRowProps) {
  const toggle = useToggleCoreAllowMajor(site.id);

  return (
    <div
      id="major-updates-settings"
      className="flex items-start gap-4 rounded-md border p-4"
    >
      <Switch
        checked={site.core_allow_major}
        disabled={toggle.isPending}
        onCheckedChange={(checked) => toggle.mutate(checked)}
        aria-label="Allow major WordPress upgrades"
      />
      <div className="flex-1">
        <p className="font-medium">Allow major WordPress upgrades for this site</p>
        <p className="text-sm text-muted-foreground">
          When off (default), only minor updates are eligible. When on, you can
          install major versions but compatibility is your responsibility.
        </p>
      </div>
    </div>
  );
}
