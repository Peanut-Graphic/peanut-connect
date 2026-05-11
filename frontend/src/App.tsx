import { Routes, Route } from 'react-router-dom';
import { ToastProvider, ErrorBoundary } from './components/common';
import { ThemeProvider } from './contexts';
import Dashboard from './pages/Dashboard';
import Health from './pages/Health';
import Updates from './pages/Updates';
import Activity from './pages/Activity';
import ErrorLog from './pages/ErrorLog';
import Campaigns from './pages/Campaigns';
import Utms from './pages/Utms';
import Links from './pages/Links';
import Analytics from './pages/Analytics';
import Tracking from './pages/Tracking';
import Settings from './pages/Settings';

/**
 * Main Application Component
 *
 * Wraps all routes with:
 * - ThemeProvider: For dark/light mode support
 * - ToastProvider: For toast notifications
 * - ErrorBoundary: For graceful error handling
 *
 * NOTE: 3.7.22 attempted route-level React.lazy code-splitting, but the
 * lazy chunks 404'd in production because Vite's default `base: '/'`
 * resolves chunk URLs against the page URL (wp-admin/admin.php/...)
 * instead of the plugin's assets dir. Reverted to eager imports in
 * 3.7.23 pending a proper Vite `base` + WP runtime-path solution.
 */
export default function App() {
  return (
    <ThemeProvider>
      <ToastProvider>
        <ErrorBoundary>
          <a
            href="#main-content"
            className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:px-4 focus:py-2 focus:bg-white focus:text-blue-600 focus:border focus:border-blue-600 focus:rounded focus:shadow-lg focus:outline-none"
          >
            Skip to main content
          </a>
          <Routes>
            <Route path="/" element={<Dashboard />} />
            <Route path="/health" element={<Health />} />
            <Route path="/updates" element={<Updates />} />
            <Route path="/activity" element={<Activity />} />
            <Route path="/errors" element={<ErrorLog />} />
            <Route path="/campaigns" element={<Campaigns />} />
            <Route path="/utms" element={<Utms />} />
            <Route path="/links" element={<Links />} />
            <Route path="/analytics" element={<Analytics />} />
            <Route path="/tracking" element={<Tracking />} />
            <Route path="/settings" element={<Settings />} />
          </Routes>
        </ErrorBoundary>
      </ToastProvider>
    </ThemeProvider>
  );
}
