import { type ReactNode, useState } from 'react';
import { useTheme } from '@/contexts';
import { Sun, Moon, Monitor, Search, Bell, X } from 'lucide-react';
import { useNavigate } from 'react-router-dom';

interface HeaderProps {
  title: string;
  description?: string;
  action?: ReactNode;
}

export default function Header({ title, description, action }: HeaderProps) {
  const { theme, setTheme, resolvedTheme } = useTheme();
  const [showSearch, setShowSearch] = useState(false);
  const [searchQuery, setSearchQuery] = useState('');
  const navigate = useNavigate();

  const cycleTheme = () => {
    const themes: Array<'light' | 'dark' | 'system'> = ['light', 'dark', 'system'];
    const currentIndex = themes.indexOf(theme);
    const nextTheme = themes[(currentIndex + 1) % themes.length];
    setTheme(nextTheme);
  };

  const getThemeIcon = () => {
    if (theme === 'system') return <Monitor className="w-5 h-5" />;
    if (resolvedTheme === 'dark') return <Moon className="w-5 h-5" />;
    return <Sun className="w-5 h-5" />;
  };

  // Keyword → route lookup. Order matters: earlier matches win. If nothing
  // matches we DON'T silently redirect to Dashboard — we set an error so
  // the user knows their query didn't resolve to a page.
  const SEARCH_ROUTES: Array<{ keywords: string[]; path: string }> = [
    { keywords: ['dashboard', 'home', 'overview'], path: '/' },
    { keywords: ['campaign', 'utm builder', 'build', 'wizard'], path: '/campaigns' },
    { keywords: ['utm', 'source', 'medium'], path: '/utms' },
    { keywords: ['link', 'short link', 'slug', 'qr'], path: '/links' },
    { keywords: ['tracking', 'snippet', 'gtm', 'tag', 'pixel'], path: '/tracking' },
    { keywords: ['analytic', 'journey', 'conversion', 'funnel', 'report'], path: '/analytics' },
    { keywords: ['health', 'status', 'check'], path: '/health' },
    { keywords: ['update', 'plugin', 'theme', 'core'], path: '/updates' },
    { keywords: ['activity', 'history', 'audit'], path: '/activity' },
    { keywords: ['error', 'log', 'fatal', 'warning'], path: '/errors' },
    { keywords: ['setting', 'connect', 'hub', 'key', 'config'], path: '/settings' },
  ];

  const [searchError, setSearchError] = useState<string | null>(null);

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    const query = searchQuery.toLowerCase().trim();
    if (!query) return;
    const match = SEARCH_ROUTES.find((r) => r.keywords.some((k) => query.includes(k)));
    if (!match) {
      setSearchError(`No page matches "${searchQuery}".`);
      return;
    }
    navigate(match.path);
    setShowSearch(false);
    setSearchQuery('');
    setSearchError(null);
  };

  return (
    <>
      {/* Top bar */}
      <header className="h-14 bg-white border-b border-slate-200 flex items-center justify-end px-6">
        <div className="flex items-center gap-1">
          {/* Search */}
          {showSearch ? (
            <form onSubmit={handleSearch} className="flex items-center gap-2">
              <div className="flex flex-col">
                <input
                  type="text"
                  value={searchQuery}
                  onChange={(e) => {
                    setSearchQuery(e.target.value);
                    if (searchError) setSearchError(null);
                  }}
                  placeholder="Search pages..."
                  className="w-48 px-3 py-1.5 text-sm text-slate-900 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                  autoFocus
                />
                {searchError && (
                  <span className="text-xs text-red-600 mt-1">{searchError}</span>
                )}
              </div>
              <button
                type="button"
                onClick={() => {
                  setShowSearch(false);
                  setSearchError(null);
                }}
                className="p-2 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100 transition-colors"
              >
                <X className="w-4 h-4" />
              </button>
            </form>
          ) : (
            <button
              onClick={() => setShowSearch(true)}
              className="p-2 text-slate-500 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition-colors"
              title="Search"
            >
              <Search className="w-5 h-5" />
            </button>
          )}

          {/* Notifications/Activity */}
          <button
            onClick={() => navigate('/activity')}
            className="p-2 text-slate-500 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition-colors relative"
            title="View Activity Log"
          >
            <Bell className="w-5 h-5" />
          </button>

          {/* Divider */}
          <div className="w-px h-6 bg-slate-200 mx-2" />

          {/* Theme toggle */}
          <button
            onClick={cycleTheme}
            className="p-2 text-slate-500 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition-colors"
            title={`Theme: ${theme === 'system' ? 'System' : theme === 'dark' ? 'Dark' : 'Light'}`}
          >
            {getThemeIcon()}
          </button>
        </div>
      </header>

      {/* Page title */}
      <div className="px-6 pt-3 pb-2 bg-slate-50">
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold text-slate-900">{title}</h1>
            {description && (
              <p className="text-sm text-slate-500 mt-0.5">{description}</p>
            )}
          </div>
          {action && <div>{action}</div>}
        </div>
      </div>
    </>
  );
}
