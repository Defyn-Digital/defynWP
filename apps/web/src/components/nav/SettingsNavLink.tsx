import { Link } from 'react-router-dom';

/**
 * P3.3 — nav link to /settings (no badge — settings has no active-count concept).
 * Rendered in the Overview header beside <MonitoringNavLink />.
 */
export function SettingsNavLink() {
  return (
    <Link
      to="/settings"
      className="inline-flex items-center gap-1.5 text-sm text-zinc-600 underline-offset-4 hover:underline"
    >
      Settings
    </Link>
  );
}
