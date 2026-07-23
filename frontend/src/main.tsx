import React from 'react';
import ReactDOM from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { HashRouter } from 'react-router-dom';
import App from './App';
import BuilderRoot from './BuilderRoot';
import { getAppMode } from './config/appMode';
import './index.css';

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 5 * 60 * 1000, // 5 minutes
      retry: 1,
      refetchOnWindowFocus: false,
    },
  },
});

// Mount point for WordPress admin
const rootElement = document.getElementById('peanut-connect-app');

if (rootElement) {
  ReactDOM.createRoot(rootElement).render(
    <React.StrictMode>
      <QueryClientProvider client={queryClient}>
        {getAppMode() === 'builder' ? (
          <BuilderRoot />
        ) : (
          <HashRouter>
            <App />
          </HashRouter>
        )}
      </QueryClientProvider>
    </React.StrictMode>
  );
}
