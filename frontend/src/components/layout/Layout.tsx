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
} from 'lucide-react';

interface LayoutProps {
  children: ReactNode;
  title: string;
  description?: string;
  action?: ReactNode;
}

const navigation = [
  { name: 'Dashboard', href: '/', icon: LayoutDashboard },
  { name: 'Health', href: '/health', icon: Activity },
  { name: 'Updates', href: '/updates', icon: Download },
  { name: 'Activity', href: '/activity', icon: History },
  { name: 'Errors', href: '/errors', icon: AlertTriangle },
  { name: 'Settings', href: '/settings', icon: Settings },
];

export default function Layout({ children, title, description, action }: LayoutProps) {
  return (
    <div className="min-h-screen bg-slate-50">
      {/* Top Navigation */}
      <header className="bg-white border-b border-slate-200">
        <div className="px-6 py-4">
          <div className="flex items-center justify-between">
            <div>
              <h1 className="text-xl font-semibold text-slate-900">{title}</h1>
              {description && (
                <p className="text-sm text-slate-500 mt-0.5">{description}</p>
              )}
            </div>
            {action && <div>{action}</div>}
          </div>
        </div>
        {/* Tab Navigation */}
        <nav className="px-6 flex gap-1 overflow-x-auto">
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
      <main id="main-content" tabIndex={-1} className="p-6 overflow-x-hidden">
        {children}
      </main>
    </div>
  );
}
