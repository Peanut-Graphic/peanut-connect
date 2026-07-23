import {
  LayoutDashboard,
  BarChart3,
  Megaphone,
  Footprints,
  Filter,
  Film,
  Tag,
  LinkIcon,
  Code2,
  Activity,
  ShieldCheck,
  AlertTriangle,
  History,
  Download,
  Settings,
} from 'lucide-react';

export interface NavItem {
  label: string;
  href: string;
  icon: typeof LayoutDashboard;
}

export interface NavGroup {
  group: string;
  items: NavItem[];
}

// The ONE nav definition. Both Sidebar and any route-in-nav logic import this.
export const NAV: NavGroup[] = [
  {
    group: 'Overview',
    items: [{ label: 'Overview', href: '/', icon: LayoutDashboard }],
  },
  {
    group: 'Performance',
    items: [
      { label: 'Analytics', href: '/analytics', icon: BarChart3 },
      { label: 'Campaigns', href: '/campaigns', icon: Megaphone },
      { label: 'Journeys', href: '/analytics/journeys', icon: Footprints },
      { label: 'Dominion Funnel', href: '/analytics/dominion-funnel', icon: Filter },
      { label: 'Videos', href: '/videos', icon: Film },
    ],
  },
  {
    group: 'Tracking setup',
    items: [
      { label: 'UTM Builder', href: '/utms', icon: Tag },
      { label: 'Short Links', href: '/links', icon: LinkIcon },
      { label: 'Tracking Code', href: '/tracking', icon: Code2 },
    ],
  },
  {
    group: 'Health',
    items: [
      { label: 'Health', href: '/health', icon: Activity },
      { label: 'Tracking Health', href: '/analytics/gtm-coverage', icon: ShieldCheck },
      { label: 'Errors', href: '/errors', icon: AlertTriangle },
      { label: 'Activity', href: '/activity', icon: History },
    ],
  },
  {
    group: 'System',
    items: [
      { label: 'Updates', href: '/updates', icon: Download },
      { label: 'Settings', href: '/settings', icon: Settings },
    ],
  },
];
