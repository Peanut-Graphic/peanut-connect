import api from './client';
import type {
  Settings,
  HealthData,
  AvailableUpdates,
  UpdateResult,
  ErrorLogData,
  ErrorCountsData,
  ErrorLevel,
  SecuritySettings,
  HubPermissions,
} from '@/types';

// Settings API (Hub-focused)
export const settingsApi = {
  // Get current settings
  get: async (): Promise<Settings> => {
    const response = await api.get('/settings');
    return response.data;
  },

  // Hub settings - auto-connect (generates key locally and sends to Hub)
  autoConnectToHub: async (hubUrl: string): Promise<{
    success: boolean;
    message: string;
    code?: string;
    data?: {
      site: Record<string, unknown>;
      client: Record<string, unknown>;
      agency: Record<string, unknown>;
    };
  }> => {
    const response = await api.post('/settings/hub/connect', { hub_url: hubUrl });
    return response.data;
  },

  // Hub settings - manual-connect (paste an existing API key from Hub)
  manualConnectToHub: async (hubUrl: string, apiKey: string): Promise<{
    success: boolean;
    message: string;
    hub_url?: string;
    site?: Record<string, unknown> | null;
  }> => {
    const response = await api.post('/settings/hub/manual-connect', {
      hub_url: hubUrl,
      api_key: apiKey,
    });
    return response.data;
  },

  // Hub settings - test connection
  testHubConnection: async (): Promise<{
    success: boolean;
    message: string;
  }> => {
    const response = await api.post('/settings/hub/test');
    return response.data;
  },

  // Hub settings - disconnect
  disconnectHub: async (): Promise<{ success: boolean; message: string }> => {
    const response = await api.post('/settings/hub/disconnect');
    return response.data;
  },

  // Hub settings - trigger sync
  triggerHubSync: async (): Promise<{ success: boolean; message: string; stats?: Record<string, number> }> => {
    const response = await api.post('/settings/hub/sync');
    return response.data;
  },

  // Hub settings - update hub mode
  updateHubMode: async (mode: 'standard' | 'hide_suite' | 'disable_suite'): Promise<{
    success: boolean;
    message: string;
  }> => {
    const response = await api.post('/settings/hub/mode', { mode });
    return response.data;
  },

  // Update tracking enabled
  updateTracking: async (enabled: boolean): Promise<{
    success: boolean;
    message: string;
  }> => {
    const response = await api.post('/hub/settings', { tracking_enabled: enabled });
    return response.data;
  },
};

// Health API
export const healthApi = {
  // Get full health data (admin endpoint)
  get: async (): Promise<HealthData> => {
    const response = await api.get('/admin/health');
    return response.data;
  },
};

// Updates API
export const updatesApi = {
  // Get available updates (admin endpoint)
  get: async (): Promise<AvailableUpdates> => {
    const response = await api.get('/admin/updates');
    return response.data;
  },

  // Perform an update (admin endpoint)
  update: async (type: 'plugin' | 'theme' | 'core', slug: string): Promise<UpdateResult> => {
    const response = await api.post('/admin/update', { type, slug });
    return response.data;
  },

  // Force check for updates (clears cache)
  checkForUpdates: async (): Promise<{
    success: boolean;
    message: string;
    data: {
      current_version: string;
      latest_version: string;
      update_available: boolean;
    };
  }> => {
    const response = await api.post('/admin/check-updates');
    return response.data;
  },
};

// Dashboard API (combined data for dashboard)
export const dashboardApi = {
  // Get dashboard data
  get: async (): Promise<{
    hub: {
      connected: boolean;
      url: string;
      last_sync: string | null;
    };
    health_summary: {
      status: 'healthy' | 'warning' | 'critical';
      issues: string[];
    };
    updates: {
      plugins: number;
      themes: number;
      core: boolean;
    };
    peanut_suite: {
      installed: boolean;
      version: string | null;
    } | null;
  }> => {
    const response = await api.get('/dashboard');
    return response.data;
  },
};

// Error Log API
export const errorLogApi = {
  // Get error log entries
  get: async (limit: number = 50, offset: number = 0, level?: ErrorLevel): Promise<ErrorLogData> => {
    const params: Record<string, string | number> = { limit, offset };
    if (level) params.level = level;
    const response = await api.get('/errors', { params });
    return response.data;
  },

  // Get error counts
  getCounts: async (): Promise<ErrorCountsData> => {
    const response = await api.get('/errors/counts');
    return response.data;
  },

  // Clear error log
  clear: async (): Promise<{ success: boolean; message: string }> => {
    const response = await api.post('/errors/clear');
    return response.data;
  },

  // Export error log as CSV
  export: async (): Promise<{ csv: string; filename: string }> => {
    const response = await api.get('/errors/export');
    return response.data;
  },

  // Update error logging settings
  updateSettings: async (enabled: boolean): Promise<{ logging_enabled: boolean }> => {
    const response = await api.put('/errors/settings', { enabled });
    return response.data;
  },
};

// Security Settings API
export const securityApi = {
  // Get security settings
  get: async (): Promise<SecuritySettings> => {
    const response = await api.get('/security');
    return response.data;
  },

  // Update security settings
  update: async (settings: Partial<{
    hide_login_enabled: boolean;
    hide_login_slug: string;
    disable_comments: boolean;
    hide_existing_comments: boolean;
    disable_xmlrpc: boolean;
    remove_version: boolean;
  }>): Promise<{ success: boolean; message: string }> => {
    const response = await api.post('/security', settings);
    return response.data;
  },
};

// Hub Permissions API
export const permissionsApi = {
  // Get hub permissions
  get: async (): Promise<HubPermissions> => {
    const response = await api.get('/permissions');
    return response.data;
  },

  // Update hub permissions
  update: async (permissions: Partial<HubPermissions>): Promise<{
    success: boolean;
    message: string;
  }> => {
    const response = await api.post('/permissions', permissions);
    return response.data;
  },
};

// Tracking Settings API
export const trackingApi = {
  // Update track logged-in users setting
  updateTrackLoggedIn: async (enabled: boolean): Promise<{
    success: boolean;
    message: string;
  }> => {
    const response = await api.post('/hub/settings', { track_logged_in: enabled });
    return response.data;
  },
};
