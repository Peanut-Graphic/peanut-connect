import { type ReactNode, useEffect, useRef, useState } from 'react';
import Sidebar from './Sidebar';
import { useLocation } from 'react-router-dom';
import { getAppMode } from '@/config/appMode';

interface LayoutProps {
  children: ReactNode;
  title: string;
  description?: string;
  action?: ReactNode;
}

const COLLAPSE_KEY = 'pc-sidebar-collapsed';
// Pages remount Layout; carry only the requested destination across that remount.
let pendingMobileNavigationFocus: string | null = null;

export default function Layout({ children, title, description, action }: LayoutProps) {
  const { pathname } = useLocation();
  const [mobileOpen, setMobileOpen] = useState(false);
  const mobileToggle = useRef<HTMLButtonElement>(null);
  const closeMobileNavigation = (destination: string) => {
    if (!mobileOpen) return;
    pendingMobileNavigationFocus = destination === pathname ? null : destination;
    setMobileOpen(false);
    mobileToggle.current?.focus();
  };
  useEffect(() => {
    if (pendingMobileNavigationFocus !== pathname) return;
    pendingMobileNavigationFocus = null;
    mobileToggle.current?.focus();
  }, [pathname]);
  const [collapsed, setCollapsed] = useState<boolean>(() => {
    try {
      return localStorage.getItem(COLLAPSE_KEY) === '1';
    } catch {
      return false;
    }
  });

  useEffect(() => {
    try {
      localStorage.setItem(COLLAPSE_KEY, collapsed ? '1' : '0');
    } catch {
      /* ignore storage failures */
    }
  }, [collapsed]);

  useEffect(() => {
    if (!mobileOpen) return;
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key !== 'Escape') return;
      event.preventDefault();
      setMobileOpen(false);
      mobileToggle.current?.focus();
    };
    document.addEventListener('keydown', closeOnEscape);
    return () => document.removeEventListener('keydown', closeOnEscape);
  }, [mobileOpen]);

  return (
    <div className="min-h-[100dvh] bg-slate-50 flex flex-col md:flex-row">
      {getAppMode() !== 'builder' && (
        <>
          <div className="pc-mobile-navigation-bar bg-white border-b border-slate-200 p-2">
            <button
              id="pc-mobile-navigation-toggle"
              ref={mobileToggle}
              type="button"
              aria-expanded={mobileOpen}
              aria-controls="pc-navigation"
              onClick={() => setMobileOpen((open) => !open)}
              className="min-h-11 px-4 rounded-lg text-primary-700 font-medium focus-visible:outline-2 focus-visible:outline-offset-2"
            >
              {mobileOpen ? 'Close navigation' : 'Open navigation'}
            </button>
          </div>
          <Sidebar
            collapsed={collapsed}
            onToggle={() => setCollapsed((c) => !c)}
            mobileOpen={mobileOpen}
            onNavigate={closeMobileNavigation}
          />
        </>
      )}

      <div className="flex-1 min-w-0 flex flex-col">
        <header className="bg-white border-b border-slate-200">
          <div className="px-4 py-4 sm:px-6">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <h1 className="text-xl font-semibold text-slate-900">{title}</h1>
                {description && <p className="text-sm text-slate-500 mt-0.5">{description}</p>}
              </div>
              {action && <div className="sm:flex-shrink-0">{action}</div>}
            </div>
          </div>
        </header>

        <main id="main-content" tabIndex={-1} className="p-4 sm:p-6 overflow-x-hidden">
          {children}
        </main>
      </div>
    </div>
  );
}
