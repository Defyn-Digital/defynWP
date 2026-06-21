import { ScrollArea } from '@/components/ui/scroll-area';
import { SidebarNav } from './SidebarNav';
import { AccountMenu } from './AccountMenu';

interface SidebarUser {
  id: number;
  email: string;
  display_name: string;
}

interface SidebarProps {
  user: SidebarUser;
  onSignOut: () => void;
  /** Called when a nav link is followed — used to close the mobile sheet. */
  onNavigate?: () => void;
}

export function Sidebar({ user, onSignOut, onNavigate }: SidebarProps) {
  return (
    <div className="flex h-full w-72 flex-col bg-sidebar text-sidebar-foreground">
      <div className="flex items-center gap-2 px-5 py-5">
        <span className="text-base font-bold text-white">◆ DefynWP</span>
      </div>
      <ScrollArea className="flex-1 px-3">
        <SidebarNav onNavigate={onNavigate} />
      </ScrollArea>
      <div className="border-t border-sidebar-border p-3">
        <AccountMenu user={user} onSignOut={onSignOut} />
      </div>
    </div>
  );
}
