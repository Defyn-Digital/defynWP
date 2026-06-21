import { NAV_GROUPS } from './navItems';
import { NavItem } from './NavItem';

interface SidebarNavProps {
  onNavigate?: () => void;
}

export function SidebarNav({ onNavigate }: SidebarNavProps) {
  return (
    <nav className="flex flex-col gap-6">
      {NAV_GROUPS.map((group) => (
        <div key={group.heading} className="flex flex-col gap-1">
          <p className="px-3 text-[0.65rem] font-semibold uppercase tracking-wider text-sidebar-muted">
            {group.heading}
          </p>
          {group.items.map((item) => (
            <NavItem key={item.to} {...item} onNavigate={onNavigate} />
          ))}
        </div>
      ))}
    </nav>
  );
}
