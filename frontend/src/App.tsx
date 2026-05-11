import { lazy, Suspense } from 'react';
import { Routes, Route } from 'react-router-dom';
import { ToastProvider, ErrorBoundary } from './components/common';
import { ThemeProvider } from './contexts';

// Dashboard is the default landing page — keep it eagerly imported so the
// initial paint doesn't need a chunk roundtrip.
import Dashboard from './pages/Dashboard';

// Every other route is code-split so the initial bundle is small. Each
// route is loaded on demand; QRCode + chart libs etc. only download when
// their owning page is first visited.
const Health = lazy(() => import('./pages/Health'));
const Updates = lazy(() => import('./pages/Updates'));
const Activity = lazy(() => import('./pages/Activity'));
const ErrorLog = lazy(() => import('./pages/ErrorLog'));
const Campaigns = lazy(() => import('./pages/Campaigns'));
const Utms = lazy(() => import('./pages/Utms'));
const Links = lazy(() => import('./pages/Links'));
const Analytics = lazy(() => import('./pages/Analytics'));
const Tracking = lazy(() => import('./pages/Tracking'));
const Settings = lazy(() => import('./pages/Settings'));

function RouteFallback() {
  return (
    <div className="flex items-center justify-center py-16">
      <div
        className="h-8 w-8 animate-spin rounded-full border-2 border-slate-200 border-t-primary-600"
        role="status"
        aria-label="Loading page"
      />
    </div>
  );
}

/**
 * Main Application Component
 *
 * Wraps all routes with:
 * - ThemeProvider: For dark/light mode support
 * - ToastProvider: For toast notifications
 * - ErrorBoundary: For graceful error handling
 * - Suspense: Per-route lazy-load fallback (v3.7.22+ code-split)
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
          <Suspense fallback={<RouteFallback />}>
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
          </Suspense>
        </ErrorBoundary>
      </ToastProvider>
    </ThemeProvider>
  );
}
