import { Badge } from '@/components/ui/badge';
import type { Plugin } from '@/types/api/plugins';

interface Props {
  plugin: Plugin;
}

export function SitePluginsRow({ plugin }: Props) {
  return (
    <tr className="border-b last:border-b-0">
      <td className="py-2">
        <div className="font-medium">{plugin.name}</div>
        <div className="text-xs text-zinc-500">{plugin.slug}</div>
      </td>
      <td className="py-2 text-sm text-zinc-700">{plugin.version ?? '—'}</td>
      <td className="py-2 text-sm">
        {plugin.update_available && plugin.update_version ? (
          <Badge variant="secondary">→ {plugin.update_version}</Badge>
        ) : (
          <span className="text-zinc-400">—</span>
        )}
      </td>
    </tr>
  );
}
