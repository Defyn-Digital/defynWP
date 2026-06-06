import type { Site } from '@/types/api';

interface SiteRuntimeInfoProps {
  site: Site;
}

export function SiteRuntimeInfo({ site }: SiteRuntimeInfoProps) {
  if (!site.wp_version) {
    return (
      <p className="text-sm text-muted-foreground">
        Not yet synced — runtime info will appear after the first successful sync.
      </p>
    );
  }

  return (
    <dl className="grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
      <dt className="text-muted-foreground">WordPress</dt>
      <dd>{site.wp_version}</dd>

      {site.php_version && (
        <>
          <dt className="text-muted-foreground">PHP</dt>
          <dd>{site.php_version}</dd>
        </>
      )}

      {site.plugin_counts && (
        <>
          <dt className="text-muted-foreground">Plugins</dt>
          <dd>
            {site.plugin_counts.installed} installed, {site.plugin_counts.active} active
          </dd>
        </>
      )}

      {site.theme_counts && (
        <>
          <dt className="text-muted-foreground">Themes</dt>
          <dd>
            {site.theme_counts.installed} installed, {site.theme_counts.active} active
          </dd>
        </>
      )}

      {site.ssl_status && (
        <>
          <dt className="text-muted-foreground">SSL</dt>
          <dd>
            {site.ssl_status}
            {site.ssl_expires_at ? ` (expires ${site.ssl_expires_at})` : null}
          </dd>
        </>
      )}

      <dt className="text-muted-foreground">Last sync</dt>
      <dd>{site.last_sync_at ?? 'never'}</dd>

      <dt className="text-muted-foreground">Last contact</dt>
      <dd>{site.last_contact_at ?? 'never'}</dd>
    </dl>
  );
}
