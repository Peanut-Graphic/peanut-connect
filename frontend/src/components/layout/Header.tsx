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

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    // Navigate based on search query
    const query = searchQuery.toLowerCase();
    if (query.includes('health') || query.includes('status')) {
      navigate('/health');
    } else if (query.includes('update') || query.includes('plugin') || query.includes('theme')) {
      navigate('/updates');
    } else if (query.includes('setting') || query.includes('connect') || query.includes('key')) {
      navigate('/settings');
    } else if (query.includes('activity') || query.includes('log') || query.includes('history')) {
      navigate('/activity');
    } else {
      navigate('/');
    }
    setShowSearch(false);
    setSearchQuery('');
  };

  return (
    <>
      {/* Top bar */}
      <header className="h-14 bg-white border-b border-slate-200 flex items-center justify-end px-6">
        <div className="flex items-center gap-1">
          {/* Search */}
          {showSearch ? (
            <form onSubmit={handleSearch} className="flex items-center gap-2">
              <input
                type="text"
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                placeholder="Search pages..."
                className="w-48 px-3 py-1.5 text-sm text-slate-900 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                autoFocus
              />
              <button
                type="button"
                onClick={() => setShowSearch(false)}
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
