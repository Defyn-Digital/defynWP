import {
  LayoutDashboard, Globe, Activity, ShieldCheck, BarChart3, FileText, ListChecks, History, Settings,
  type LucideIcon,
} from 'lucide-react';

export interface NavItemDef {
  label: string;
  to: string;
  icon: LucideIcon;
  /** `true` → only active on an exact path match (e.g. Dashboard at /overview). */
  end?: boolean;
}

export interface NavGroup {
  heading: string;
  items: NavItemDef[];
}

export const NAV_GROUPS: NavGroup[] = [
  {
    heading: 'Main',
    items: [
      { label: 'Dashboard', to: '/overview', icon: LayoutDashboard, end: true },
      { label: 'Sites', to: '/sites', icon: Globe },
    ],
  },
  {
    heading: 'Fleet',
    items: [
      { label: 'Monitoring', to: '/monitoring', icon: Activity },
      { label: 'Security', to: '/security', icon: ShieldCheck },
      { label: 'Insights', to: '/insights', icon: BarChart3 },
      { label: 'Reports', to: '/reports', icon: FileText },
    ],
  },
  {
    heading: 'Operations',
    items: [
      { label: 'Jobs', to: '/jobs', icon: ListChecks },
      { label: 'Activity', to: '/activity', icon: History },
    ],
  },
  {
    heading: 'Account',
    items: [{ label: 'Settings', to: '/settings', icon: Settings }],
  },
];
