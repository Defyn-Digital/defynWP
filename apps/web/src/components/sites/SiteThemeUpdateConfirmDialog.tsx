import type { Theme } from '@/types/api/themes';

interface Props {
  theme: Theme;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onConfirm: () => void;
}

// Stub — full implementation arrives in Task 26.
export function SiteThemeUpdateConfirmDialog({ open }: Props) {
  if (!open) return null;
  return <div role="alertdialog" />;
}
