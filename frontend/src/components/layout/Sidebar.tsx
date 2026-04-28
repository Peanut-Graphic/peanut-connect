import { NavLink } from 'react-router-dom';
import { clsx } from 'clsx';
import {
  LayoutDashboard,
  Activity,
  Download,
  Settings,
  ChevronLeft,
  ChevronRight,
  Link2,
  History,
  AlertTriangle,
  Megaphone,
  Tag,
  BarChart3,
  Code2,
} from 'lucide-react';
import { getVersion } from '@/api';

interface SidebarProps {
  collapsed: boolean;
  onToggle: () => void;
}

interface NavItem {
  name: string;
  href: string;
  icon: typeof LayoutDashboard;
}

const navigation: NavItem[] = [
  { name: 'Dashboard', href: '/', icon: LayoutDashboard },
  { name: 'Campaigns', href: '/campaigns', icon: Megaphone },
  { name: 'UTMs', href: '/utms', icon: Tag },
  { name: 'Links', href: '/links', icon: Link2 },
  { name: 'Analytics', href: '/analytics', icon: BarChart3 },
  { name: 'Tracking', href: '/tracking', icon: Code2 },
  { name: 'Health', href: '/health', icon: Activity },
  { name: 'Updates', href: '/updates', icon: Download },
  { name: 'Activity', href: '/activity', icon: History },
  { name: 'Error Log', href: '/errors', icon: AlertTriangle },
  { name: 'Settings', href: '/settings', icon: Settings },
];

export default function Sidebar({ collapsed, onToggle }: SidebarProps) {
  return (
    <aside
      className={clsx(
        'fixed left-0 top-0 h-screen bg-white border-r border-slate-200 transition-all duration-300 z-50',
        collapsed ? 'w-16' : 'w-56'
      )}
    >
      {/* Logo */}
      <div className="h-14 flex items-center justify-between px-4 border-b border-slate-200">
        {!collapsed && (
          <div className="flex items-center gap-2">
            <Link2 className="w-5 h-5 text-primary-600" />
            <span className="text-lg font-bold text-primary-600">Connect</span>
          </div>
        )}
        {collapsed && (
          <Link2 className="w-5 h-5 text-primary-600 mx-auto" />
        )}
        <button
          onClick={onToggle}
          className={clsx(
            'p-1.5 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition-colors',
            collapsed && 'mx-auto'
          )}
        >
          {collapsed ? (
            <ChevronRight className="w-5 h-5" />
          ) : (
            <ChevronLeft className="w-5 h-5" />
          )}
        </button>
      </div>

      {/* Navigation */}
      <nav className="p-3 space-y-1">
        {navigation.map((item) => (
          <NavLink
            key={item.name}
            to={item.href}
            className={({ isActive }) =>
              clsx(
                'flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors',
                isActive
                  ? 'bg-primary-50 text-primary-700'
                  : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900'
              )
            }
          >
            <item.icon className="w-5 h-5 flex-shrink-0 text-slate-500" />
            {!collapsed && <span className="flex-1">{item.name}</span>}
          </NavLink>
        ))}
      </nav>

      {/* Version */}
      {!collapsed && (
        <div className="absolute bottom-0 left-0 right-0 p-4 border-t border-slate-200">
          <span className="text-xs text-slate-400">Peanut Connect v{getVersion()}</span>
        </div>
      )}
    </aside>
  );
}
