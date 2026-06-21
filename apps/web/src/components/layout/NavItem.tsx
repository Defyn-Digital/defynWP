import { NavLink } from 'react-router-dom';
import { cn } from '@/lib/cn';
import type { NavItemDef } from './navItems';

interface NavItemProps extends NavItemDef {
  onNavigate?: () => void;
}

export function NavItem({ label, to, icon: Icon, end, onNavigate }: NavItemProps) {
  return (
    <NavLink
      to={to}
      end={end}
      onClick={onNavigate}
      className={({ isActive }) =>
        cn(
          'flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors',
          isActive
            ? 'bg-sidebar-accent text-sidebar-accent-foreground'
            : 'text-sidebar-foreground hover:bg-white/5 hover:text-white',
        )
      }
    >
      <Icon className="h-4 w-4 shrink-0" aria-hidden="true" />
      <span>{label}</span>
    </NavLink>
  );
}
