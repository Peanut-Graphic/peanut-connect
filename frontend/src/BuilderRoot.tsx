import { HashRouter } from 'react-router-dom';
import { ToastProvider, ErrorBoundary } from './components/common';
import { ThemeProvider } from './contexts';
import Campaigns from './pages/Campaigns';

/**
 * Builder-only app for the scoped "UTM Builder" role: just the Campaigns
 * wizard (UTM + short link + QR), rendered inside a sidebar-less Layout
 * (Layout hides its sidebar in builder mode). No other routes exist here —
 * the server-side permission gate is the real boundary.
 *
 * Mirrors the full app's provider chain (Theme → Toast → ErrorBoundary); the
 * QueryClientProvider is supplied by the entry point (main.tsx), shared with
 * the full app.
 */
export default function BuilderRoot() {
  return (
    <ThemeProvider>
      <ToastProvider>
        <ErrorBoundary>
          <HashRouter>
            <Campaigns />
          </HashRouter>
        </ErrorBoundary>
      </ToastProvider>
    </ThemeProvider>
  );
}
