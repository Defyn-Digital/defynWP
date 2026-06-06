import type { Plugin } from '@/types/api/plugins';

interface Props {
  plugin: Plugin;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onConfirm: () => void;
}

/**
 * Placeholder stub. Task 20 replaces the body with a Radix Dialog that
 * surfaces "Update <name> from <current> to <new>? Cancel / Update" and
 * invokes `onConfirm` on confirm. The prop shape is locked here so
 * SitePluginsRow can wire to its real callsite today.
 */
// eslint-disable-next-line @typescript-eslint/no-unused-vars
export function SitePluginUpdateConfirmDialog(_props: Props) {
  return null;
}
