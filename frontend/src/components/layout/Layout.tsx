import { type ReactNode } from 'react';
import { NavLink } from 'react-router-dom';
import { clsx } from 'clsx';
import {
  LayoutDashboard,
  Activity,
  Download,
  History,
  AlertTriangle,
  Settings,
  Megaphone,
  Tag,
  LinkIcon,
  BarChart3,
  Code2,
  Film,
  Footprints,
} from 'lucide-react';

interface LayoutProps {
  children: ReactNode;
  title: string;
  description?: string;
  action?: ReactNode;
}

const navigation = [
  { name: 'Dashboard', href: '/', icon: LayoutDashboard },
  { name: 'Campaigns', href: '/campaigns', icon: Megaphone },
  { name: 'UTMs', href: '/utms', icon: Tag },
  { name: 'Links', href: '/links', icon: LinkIcon },
  { name: 'Videos', href: '/videos', icon: Film },
  { name: 'Tracking', href: '/tracking', icon: Code2 },
  { name: 'Analytics', href: '/analytics', icon: BarChart3 },
  { name: 'Journeys', href: '/journeys', icon: Footprints },
  { name: 'Health', href: '/health', icon: Activity },
  { name: 'Updates', href: '/updates', icon: Download },
  { name: 'Activity', href: '/activity', icon: History },
  { name: 'Errors', href: '/errors', icon: AlertTriangle },
  { name: 'Settings', href: '/settings', icon: Settings },
];

export default function Layout({ children, title, description, action }: LayoutProps) {
  return (
    <div className="min-h-[100dvh] bg-slate-50">
      {/* Top Navigation */}
      <header className="bg-white border-b border-slate-200">
        <div className="px-4 py-4 sm:px-6">
          <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <h1 className="text-xl font-semibold text-slate-900">{title}</h1>
              {description && (
                <p className="text-sm text-slate-500 mt-0.5">{description}</p>
              )}
            </div>
            {action && <div className="sm:flex-shrink-0">{action}</div>}
          </div>
        </div>
        {/* Tab Navigation */}
        <nav className="px-3 sm:px-6 flex gap-1 overflow-x-auto pb-1 [scrollbar-width:none] [-ms-overflow-style:none] [&::-webkit-scrollbar]:hidden">
          {navigation.map((item) => (
            <NavLink
              key={item.name}
              to={item.href}
              className={({ isActive }) =>
                clsx(
                  'flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 -mb-px whitespace-nowrap transition-colors',
                  isActive
                    ? 'border-primary-600 text-primary-600'
                    : 'border-transparent text-slate-600 hover:text-slate-900 hover:border-slate-300'
                )
              }
            >
              <item.icon className="w-4 h-4" />
              {item.name}
            </NavLink>
          ))}
        </nav>
      </header>

      {/* Main Content */}
      <main id="main-content" tabIndex={-1} className="p-4 sm:p-6 overflow-x-hidden">
        {children}
      </main>
    </div>
  );
}
