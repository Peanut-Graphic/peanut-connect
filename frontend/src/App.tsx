import { Routes, Route } from 'react-router-dom';
import Dashboard from './pages/Dashboard';
import Settings from './pages/Settings';
import Health from './pages/Health';
import Updates from './pages/Updates';
import Activity from './pages/Activity';
import ErrorLog from './pages/ErrorLog';
import { ToastProvider, ErrorBoundary } from './components/common';
import { ThemeProvider } from './contexts';

/**
 * Main Application Component
 *
 * Wraps all routes with:
 * - ThemeProvider: For dark/light mode support
 * - ToastProvider: For toast notifications
 * - ErrorBoundary: For graceful error handling
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
            <Route path="/settings" element={<Settings />} />
          </Routes>
        </ErrorBoundary>
      </ToastProvider>
    </ThemeProvider>
  );
}
