import { NavLink } from 'react-router-dom';
import { clsx } from 'clsx';
import { ChevronLeft, ChevronRight, Link2 } from 'lucide-react';
import { NAV } from '@/config/nav';
import { getVersion } from '@/api';

interface SidebarProps {
  collapsed: boolean;
  onToggle: () => void;
}

export default function Sidebar({ collapsed, onToggle }: SidebarProps) {
  return (
    <aside
      className={clsx(
        // In-content column (sticky), NOT viewport-fixed — must not overlap wp-admin's menu.
        'sticky top-0 self-start h-[100dvh] overflow-y-auto flex-none bg-white border-r border-slate-200 transition-all duration-300',
        collapsed ? 'w-16' : 'w-56',
      )}
    >
      {/* Header / collapse toggle */}
      <div className="h-14 flex items-center justify-between px-4 border-b border-slate-200">
        {!collapsed ? (
          <div className="flex items-center gap-2">
            <Link2 className="w-5 h-5 text-primary-600" />
            <span className="text-lg font-bold text-primary-600 leading-tight">End to End</span>
          </div>
        ) : (
          <Link2 className="w-5 h-5 text-primary-600 mx-auto" />
        )}
        <button
          type="button"
          onClick={onToggle}
          aria-label={collapsed ? 'Expand navigation' : 'Collapse navigation'}
          className={clsx(
            'p-1.5 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition-colors',
            collapsed && 'mx-auto',
          )}
        >
          {collapsed ? <ChevronRight className="w-5 h-5" /> : <ChevronLeft className="w-5 h-5" />}
        </button>
      </div>

      {/* Grouped navigation */}
      <nav className="p-3 space-y-4">
        {NAV.map((group) => (
          <div key={group.group} className="space-y-1">
            {!collapsed && (
              <h3 className="px-3 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                {group.group}
              </h3>
            )}
            {group.items.map((item) => (
              <NavLink
                key={item.href}
                to={item.href}
                end={item.href === '/'}
                aria-label={item.label}
                title={collapsed ? item.label : undefined}
                className={({ isActive }) =>
                  clsx(
                    'flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition-colors',
                    isActive
                      ? 'bg-primary-50 text-primary-700'
                      : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900',
                  )
                }
              >
                <item.icon className="w-5 h-5 flex-shrink-0 text-slate-500" />
                {!collapsed && <span className="flex-1">{item.label}</span>}
              </NavLink>
            ))}
          </div>
        ))}
      </nav>

      {!collapsed && (
        <div className="p-4 border-t border-slate-200">
          <span className="text-xs text-slate-400">End-to-End v{getVersion()}</span>
        </div>
      )}
    </aside>
  );
}
