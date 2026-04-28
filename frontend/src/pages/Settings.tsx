import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Layout } from '@/components/layout';
import {
  Card,
  CardHeader,
  Button,
  Badge,
  ConfirmModal,
  useToast,
  HelpTooltip,
  InfoPanel,
  SettingsSkeleton,
} from '@/components/common';
import { settingsApi, errorLogApi, securityApi, permissionsApi, trackingApi, updatesApi } from '@/api';
import { Link } from 'react-router-dom';
import {
  RefreshCw,
  Unlink,
  CheckCircle2,
  AlertTriangle,
  Cloud,
  Link2,
  Send,
  EyeOff,
  Power,
  Eye,
  Sparkles,
  Bug,
  ToggleLeft,
  ToggleRight,
  ExternalLink,
  AlertOctagon,
  AlertCircle,
  Shield,
  KeyRound,
  MessageSquareOff,
  Hash,
  LogIn,
  Users,
  Download,
  BarChart3,
  User,
} from 'lucide-react';
import { formatDistanceToNow } from 'date-fns';

export default function Settings() {
  const queryClient = useQueryClient();
  const toast = useToast();

  // Hub settings state
  const [hubUrl, setHubUrl] = useState('https://hub.peanutgraphic.com');
  const [hubApiKey, setHubApiKey] = useState('');
  const [hubConnectMode, setHubConnectMode] = useState<'auto' | 'manual'>('auto');
  const [showHubDisconnectModal, setShowHubDisconnectModal] = useState(false);

  const { data: settings, isLoading, error, refetch } = useQuery({
    queryKey: ['settings'],
    queryFn: settingsApi.get,
  });

  // Hub mutations
  const autoConnectHubMutation = useMutation({
    mutationFn: () => settingsApi.autoConnectToHub(hubUrl),
    onSuccess: (data) => {
      toast.success(data.message || 'Successfully connected to Hub!');
      queryClient.invalidateQueries({ queryKey: ['settings'] });
    },
    onError: (err: Error & { code?: string }) => {
      const errorMessage = err.message || 'Failed to connect to Hub';
      toast.error(errorMessage);
    },
  });

  const manualConnectHubMutation = useMutation({
    mutationFn: () => settingsApi.manualConnectToHub(hubUrl, hubApiKey),
    onSuccess: (data) => {
      toast.success(data.message || 'Successfully connected to Hub!');
      setHubApiKey('');
      queryClient.invalidateQueries({ queryKey: ['settings'] });
    },
    onError: (err: Error) => {
      toast.error(err.message || 'Could not connect with that API key.');
    },
  });

  const testHubConnectionMutation = useMutation({
    mutationFn: settingsApi.testHubConnection,
    onSuccess: (data) => {
      toast.success(data.message || 'Hub connection successful');
    },
    onError: (err) => {
      toast.error((err as Error).message || 'Hub connection failed');
    },
  });

  const disconnectHubMutation = useMutation({
    mutationFn: settingsApi.disconnectHub,
    onSuccess: () => {
      toast.success('Disconnected from Hub');
      queryClient.invalidateQueries({ queryKey: ['settings'] });
      setShowHubDisconnectModal(false);
    },
    onError: (err) => {
      toast.error((err as Error).message || 'Failed to disconnect from Hub');
    },
  });

  const [syncElapsed, setSyncElapsed] = useState(0);
  const [syncResult, setSyncResult] = useState<{ message: string; stats?: Record<string, number>; success: boolean } | null>(null);

  const triggerHubSyncMutation = useMutation({
    mutationFn: settingsApi.triggerHubSync,
    onMutate: () => {
      setSyncResult(null);
      setSyncElapsed(0);
      const interval = setInterval(() => setSyncElapsed((s) => s + 1), 1000);
      return { interval };
    },
    onSuccess: (data, _, context) => {
      clearInterval(context?.interval);
      setSyncResult({ message: data.message, stats: data.stats, success: true });
      queryClient.invalidateQueries({ queryKey: ['settings'] });
    },
    onError: (err, _, context) => {
      clearInterval(context?.interval);
      setSyncResult({ message: (err as Error).message || 'Sync failed', success: false });
    },
  });

  const updateHubModeMutation = useMutation({
    mutationFn: (mode: 'standard' | 'hide_suite' | 'disable_suite') =>
      settingsApi.updateHubMode(mode),
    onSuccess: () => {
      toast.success('Hub mode updated');
      queryClient.invalidateQueries({ queryKey: ['settings'] });
    },
    onError: (err) => {
      toast.error((err as Error).message || 'Failed to update hub mode');
    },
  });

  const updateTrackingMutation = useMutation({
    mutationFn: (enabled: boolean) => settingsApi.updateTracking(enabled),
    onSuccess: (_, enabled) => {
      toast.success(`Visitor tracking ${enabled ? 'enabled' : 'disabled'}`);
      queryClient.invalidateQueries({ queryKey: ['settings'] });
    },
    onError: (err) => {
      toast.error((err as Error).message || 'Failed to update tracking setting');
    },
  });

  // Error log queries
  const { data: errorCounts } = useQuery({
    queryKey: ['errorCounts'],
    queryFn: () => errorLogApi.getCounts(),
  });

  const { data: errorLogData } = useQuery({
    queryKey: ['errorLogStatus'],
    queryFn: () => errorLogApi.get(1, 0),
  });

  const toggleErrorLoggingMutation = useMutation({
    mutationFn: (enabled: boolean) => errorLogApi.updateSettings(enabled),
    onSuccess: (_, enabled) => {
      queryClient.invalidateQueries({ queryKey: ['errorLogStatus'] });
      queryClient.invalidateQueries({ queryKey: ['errorCounts'] });
      toast.success(`Error logging ${enabled ? 'enabled' : 'disabled'}`);
    },
    onError: () => {
      toast.error('Failed to update error logging setting');
    },
  });

  // Security settings queries
  const { data: securitySettings } = useQuery({
    queryKey: ['securitySettings'],
    queryFn: securityApi.get,
  });

  const updateSecurityMutation = useMutation({
    mutationFn: (settings: Parameters<typeof securityApi.update>[0]) =>
      securityApi.update(settings),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['securitySettings'] });
      toast.success('Security setting updated');
    },
    onError: (err) => {
      toast.error((err as Error).message || 'Failed to update security setting');
    },
  });

  // Hub permissions queries
  const { data: permissions } = useQuery({
    queryKey: ['hubPermissions'],
    queryFn: permissionsApi.get,
  });

  const updatePermissionsMutation = useMutation({
    mutationFn: (perms: Parameters<typeof permissionsApi.update>[0]) =>
      permissionsApi.update(perms),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['hubPermissions'] });
      toast.success('Hub permission updated');
    },
    onError: (err) => {
      toast.error((err as Error).message || 'Failed to update hub permission');
    },
  });

  // Track logged-in users mutation
  const updateTrackLoggedInMutation = useMutation({
    mutationFn: (enabled: boolean) => trackingApi.updateTrackLoggedIn(enabled),
    onSuccess: (_, enabled) => {
      queryClient.invalidateQueries({ queryKey: ['settings'] });
      toast.success(`Track logged-in users ${enabled ? 'enabled' : 'disabled'}`);
    },
    onError: (err) => {
      toast.error((err as Error).message || 'Failed to update tracking setting');
    },
  });

  // Login slug state
  const [loginSlug, setLoginSlug] = useState('');

  // Check for updates mutation
  const checkForUpdatesMutation = useMutation({
    mutationFn: updatesApi.checkForUpdates,
    onSuccess: (data) => {
      if (data.data.update_available) {
        toast.success(`Update available: v${data.data.latest_version}. Check the Updates page.`);
      } else {
        toast.success(`You're running the latest version (v${data.data.current_version}).`);
      }
    },
    onError: (err) => {
      toast.error((err as Error).message || 'Failed to check for updates');
    },
  });

  if (isLoading) {
    return (
      <Layout title="Settings" description="Hub connection settings">
        <SettingsSkeleton />
      </Layout>
    );
  }

  if (error) {
    return (
      <Layout title="Settings" description="Hub connection settings">
        <Card>
          <div className="text-center py-8">
            <p className="text-slate-500 mb-4">{(error as Error).message}</p>
            <Button onClick={() => refetch()}>
              <RefreshCw className="w-4 h-4 mr-2" />
              Retry
            </Button>
          </div>
        </Card>
      </Layout>
    );
  }

  return (
    <Layout title="Settings" description="Hub connection settings">
      {/* Hub Connection */}
      <Card className="mb-6">
        <CardHeader
          title={
            <span className="flex items-center gap-2">
              Hub Connection
              <HelpTooltip content="Connect to Peanut Hub to sync health data, analytics, and receive popup deployments from your agency dashboard." />
            </span>
          }
          action={
            settings?.hub?.connected ? (
              <Badge variant="success">Connected</Badge>
            ) : (
              <Badge variant="warning">Not Connected</Badge>
            )
          }
        />
        {settings?.hub?.connected ? (
          <div className="space-y-4">
            <div className="flex items-center gap-3 p-4 bg-green-50 rounded-lg border border-green-200">
              <CheckCircle2 className="w-6 h-6 text-green-600 flex-shrink-0" />
              <div className="flex-1 min-w-0">
                <p className="font-medium text-green-900">Connected to Hub</p>
                <p className="text-sm text-green-700 truncate">
                  {settings.hub.url}
                </p>
              </div>
            </div>
            {settings.hub.last_sync && (
              <p className="text-sm text-slate-500">
                Last sync:{' '}
                {formatDistanceToNow(new Date(settings.hub.last_sync + (settings.hub.last_sync.includes('Z') || settings.hub.last_sync.includes('+') ? '' : 'Z')), {
                  addSuffix: true,
                })}
              </p>
            )}
            <div className="space-y-3">
              <div className="flex flex-wrap gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => triggerHubSyncMutation.mutate()}
                  loading={triggerHubSyncMutation.isPending}
                  icon={<Send className="w-4 h-4" />}
                  disabled={triggerHubSyncMutation.isPending}
                >
                  Sync Now
                </Button>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => testHubConnectionMutation.mutate()}
                  loading={testHubConnectionMutation.isPending}
                  icon={<RefreshCw className="w-4 h-4" />}
                >
                  Test Connection
                </Button>
              </div>

              {/* Sync Progress */}
              {triggerHubSyncMutation.isPending && (
                <div className="p-3 bg-blue-50 rounded-lg border border-blue-200">
                  <div className="flex items-center justify-between mb-2">
                    <span className="text-sm font-medium text-blue-900">Syncing data to Hub...</span>
                    <span className="text-xs text-blue-600">{syncElapsed}s</span>
                  </div>
                  <div className="w-full bg-blue-200 rounded-full h-2 overflow-hidden">
                    <div className="h-full bg-blue-600 rounded-full animate-pulse" style={{ width: '100%', animation: 'syncPulse 1.5s ease-in-out infinite' }} />
                  </div>
                  <p className="text-xs text-blue-600 mt-1.5">
                    {syncElapsed < 5 ? 'Connecting...' : syncElapsed < 15 ? 'Syncing events and visitors...' : syncElapsed < 30 ? 'Processing large dataset...' : 'Still working (high-traffic site)...'}
                  </p>
                  <style>{`@keyframes syncPulse { 0%, 100% { opacity: 0.6; } 50% { opacity: 1; } }`}</style>
                </div>
              )}

              {/* Sync Result */}
              {syncResult && !triggerHubSyncMutation.isPending && (
                <div className={`p-3 rounded-lg border ${syncResult.success ? 'bg-green-50 border-green-200' : 'bg-red-50 border-red-200'}`}>
                  <div className="flex items-start gap-2">
                    {syncResult.success ? (
                      <CheckCircle2 className="w-4 h-4 text-green-600 flex-shrink-0 mt-0.5" />
                    ) : (
                      <AlertTriangle className="w-4 h-4 text-red-600 flex-shrink-0 mt-0.5" />
                    )}
                    <div className="flex-1 min-w-0">
                      <p className={`text-sm font-medium ${syncResult.success ? 'text-green-900' : 'text-red-900'}`}>
                        {syncResult.message}
                      </p>
                      {syncResult.stats && (
                        <div className="flex flex-wrap gap-2 mt-1.5">
                          {Object.entries(syncResult.stats).map(([type, count]) => (
                            <span key={type} className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-white border border-green-300 text-green-700">
                              {count} {type}
                            </span>
                          ))}
                        </div>
                      )}
                      <p className="text-xs text-slate-500 mt-1">Completed in {syncElapsed}s</p>
                    </div>
                  </div>
                </div>
              )}
            </div>

            {/* Hub Mode Setting */}
            <div className="mt-6 pt-6 border-t border-slate-200">
              <div className="flex items-center gap-2 mb-3">
                <Power className="w-4 h-4 text-slate-600" />
                <span className="font-medium text-slate-900">Hub Mode</span>
                <HelpTooltip content="When using Hub as your primary platform, you can hide or disable Peanut Suite on this site to avoid duplicate features." />
              </div>
              <p className="text-sm text-slate-600 mb-4">
                Choose how Peanut Suite behaves when connected to Hub.
              </p>
              <div className="space-y-3">
                <label
                  className={`flex items-start gap-3 p-3 rounded-lg border cursor-pointer transition-colors ${
                    (settings?.hub?.mode || 'standard') === 'standard'
                      ? 'bg-blue-50 border-blue-200'
                      : 'bg-slate-50 border-slate-200 hover:border-slate-300'
                  }`}
                >
                  <input
                    type="radio"
                    name="hubMode"
                    value="standard"
                    checked={(settings?.hub?.mode || 'standard') === 'standard'}
                    onChange={() => updateHubModeMutation.mutate('standard')}
                    className="mt-0.5"
                    disabled={updateHubModeMutation.isPending}
                  />
                  <div className="flex-1">
                    <div className="flex items-center gap-2">
                      <Eye className="w-4 h-4 text-slate-600" />
                      <span className="font-medium text-slate-900">Standard</span>
                    </div>
                    <p className="text-sm text-slate-600 mt-1">
                      Peanut Suite works normally alongside Hub. Use both interfaces.
                    </p>
                  </div>
                </label>

                <label
                  className={`flex items-start gap-3 p-3 rounded-lg border cursor-pointer transition-colors ${
                    settings?.hub?.mode === 'hide_suite'
                      ? 'bg-amber-50 border-amber-200'
                      : 'bg-slate-50 border-slate-200 hover:border-slate-300'
                  }`}
                >
                  <input
                    type="radio"
                    name="hubMode"
                    value="hide_suite"
                    checked={settings?.hub?.mode === 'hide_suite'}
                    onChange={() => updateHubModeMutation.mutate('hide_suite')}
                    className="mt-0.5"
                    disabled={updateHubModeMutation.isPending}
                  />
                  <div className="flex-1">
                    <div className="flex items-center gap-2">
                      <EyeOff className="w-4 h-4 text-amber-600" />
                      <span className="font-medium text-slate-900">Hide Suite Menu</span>
                    </div>
                    <p className="text-sm text-slate-600 mt-1">
                      Hide Peanut Suite from the admin menu. Suite still runs in the background.
                    </p>
                  </div>
                </label>

                <label
                  className={`flex items-start gap-3 p-3 rounded-lg border cursor-pointer transition-colors ${
                    settings?.hub?.mode === 'disable_suite'
                      ? 'bg-red-50 border-red-200'
                      : 'bg-slate-50 border-slate-200 hover:border-slate-300'
                  }`}
                >
                  <input
                    type="radio"
                    name="hubMode"
                    value="disable_suite"
                    checked={settings?.hub?.mode === 'disable_suite'}
                    onChange={() => updateHubModeMutation.mutate('disable_suite')}
                    className="mt-0.5"
                    disabled={updateHubModeMutation.isPending}
                  />
                  <div className="flex-1">
                    <div className="flex items-center gap-2">
                      <Power className="w-4 h-4 text-red-600" />
                      <span className="font-medium text-slate-900">Disable Suite</span>
                    </div>
                    <p className="text-sm text-slate-600 mt-1">
                      Fully disable Peanut Suite. Hub becomes the only interface.
                    </p>
                    <p className="text-xs text-red-600 mt-1">
                      <AlertTriangle className="w-3 h-3 inline mr-1" />
                      Suite features won't run. Only use if Hub has all features you need.
                    </p>
                  </div>
                </label>
              </div>
              {updateHubModeMutation.isPending && (
                <p className="text-sm text-slate-500 mt-2">Updating mode...</p>
              )}
            </div>

            {/* Visitor Tracking */}
            <div className="mt-6 pt-6 border-t border-slate-200">
              <div className="flex items-center justify-between">
                <div className="flex-1">
                  <div className="flex items-center gap-2 mb-1">
                    <Eye className="w-4 h-4 text-slate-600" />
                    <span className="font-medium text-slate-900">Visitor Tracking</span>
                    <HelpTooltip content="Track pageviews, visitor sessions, and traffic sources. Data is synced to Hub for analytics." />
                  </div>
                  <p className="text-sm text-slate-600">
                    Collect anonymous visitor data for Top Pages and Traffic Sources analytics in Hub.
                  </p>
                </div>
                <button
                  onClick={() => updateTrackingMutation.mutate(!settings?.hub?.tracking_enabled)}
                  disabled={updateTrackingMutation.isPending}
                  className={`flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                    settings?.hub?.tracking_enabled
                      ? 'bg-green-100 text-green-700 hover:bg-green-200'
                      : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                  }`}
                >
                  {settings?.hub?.tracking_enabled ? (
                    <>
                      <ToggleRight className="w-5 h-5" />
                      Enabled
                    </>
                  ) : (
                    <>
                      <ToggleLeft className="w-5 h-5" />
                      Disabled
                    </>
                  )}
                </button>
              </div>
              {settings?.hub?.tracking_enabled && (
                <div className="mt-3 p-3 bg-green-50 rounded-lg border border-green-200">
                  <p className="text-sm text-green-700">
                    <CheckCircle2 className="w-4 h-4 inline mr-1" />
                    Tracking active. Pageviews and visitor data will sync to Hub.
                  </p>
                </div>
              )}
            </div>

            {/* Peanut Suite Detection */}
            {settings?.peanut_suite && (
              <div className="mt-6 pt-6 border-t border-slate-200">
                <div className="flex items-center gap-3 p-4 bg-green-50 rounded-lg border border-green-200">
                  <Sparkles className="w-5 h-5 text-green-600 flex-shrink-0" />
                  <div>
                    <p className="font-medium text-green-900">
                      Peanut Suite v{settings.peanut_suite.version}
                    </p>
                    <p className="text-sm text-green-700">
                      Analytics data is synced with Hub
                    </p>
                  </div>
                </div>
                {settings.peanut_suite.modules.length > 0 && (
                  <div className="mt-3">
                    <p className="text-sm font-medium text-slate-700 mb-2">Active Modules</p>
                    <div className="flex flex-wrap gap-2">
                      {settings.peanut_suite.modules.map((module) => (
                        <Badge key={module} variant="primary" size="sm">
                          {module}
                        </Badge>
                      ))}
                    </div>
                  </div>
                )}
              </div>
            )}

            {/* Danger Zone - Disconnect */}
            <div className="mt-6 pt-6 border-t border-red-200">
              <div className="p-4 bg-red-50 rounded-lg border border-red-200">
                <div className="flex items-start gap-3">
                  <Unlink className="w-5 h-5 text-red-600 flex-shrink-0 mt-0.5" />
                  <div className="flex-1">
                    <p className="font-medium text-red-900">Disconnect from Hub</p>
                    <p className="text-sm text-red-700 mt-1">
                      Stop syncing health data with Hub. You'll need to reconnect to resume monitoring.
                    </p>
                    <Button
                      variant="danger"
                      size="sm"
                      className="mt-3"
                      onClick={() => setShowHubDisconnectModal(true)}
                      icon={<Unlink className="w-4 h-4" />}
                    >
                      Disconnect
                    </Button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        ) : (
          <div className="space-y-4">
            <div className="flex items-center gap-3 p-4 bg-slate-50 rounded-lg border border-slate-200">
              <Cloud className="w-6 h-6 text-slate-400 flex-shrink-0" />
              <div>
                <p className="font-medium text-slate-700">Not Connected to Hub</p>
                <p className="text-sm text-slate-500">
                  Connect this site to your Hub to enable health monitoring and sync.
                </p>
              </div>
            </div>
            <InfoPanel variant="guide" title="How to Connect to Hub" collapsible defaultOpen={true}>
              <ol className="mt-2 text-sm space-y-2">
                <li className="flex items-start gap-2">
                  <span className="w-5 h-5 bg-purple-100 text-purple-700 rounded-full flex items-center justify-center text-xs font-medium flex-shrink-0">1</span>
                  <span>Make sure this site exists in your Hub dashboard (your agency needs to add it first)</span>
                </li>
                <li className="flex items-start gap-2">
                  <span className="w-5 h-5 bg-purple-100 text-purple-700 rounded-full flex items-center justify-center text-xs font-medium flex-shrink-0">2</span>
                  <span>
                    <strong>Auto-connect</strong> — enter your Hub URL and Connect generates a fresh API key
                    automatically. Use this for sites that have never been connected before.
                  </span>
                </li>
                <li className="flex items-start gap-2">
                  <span className="w-5 h-5 bg-purple-100 text-purple-700 rounded-full flex items-center justify-center text-xs font-medium flex-shrink-0">3</span>
                  <span>
                    <strong>Use existing API key</strong> — paste both the Hub URL and the API key from{' '}
                    Hub → Sites → your site. Use this for sites that already have an active connection in Hub
                    (auto-connect refuses to overwrite an active key).
                  </span>
                </li>
              </ol>
            </InfoPanel>

            <div className="inline-flex items-center rounded-lg border border-slate-200 bg-slate-50 p-1 mb-2">
              <button
                type="button"
                onClick={() => setHubConnectMode('auto')}
                className={
                  hubConnectMode === 'auto'
                    ? 'px-3 py-1.5 text-sm font-medium rounded bg-white text-slate-900 shadow-sm'
                    : 'px-3 py-1.5 text-sm font-medium rounded text-slate-600 hover:text-slate-900'
                }
              >
                Auto-connect
              </button>
              <button
                type="button"
                onClick={() => setHubConnectMode('manual')}
                className={
                  hubConnectMode === 'manual'
                    ? 'px-3 py-1.5 text-sm font-medium rounded bg-white text-slate-900 shadow-sm'
                    : 'px-3 py-1.5 text-sm font-medium rounded text-slate-600 hover:text-slate-900'
                }
              >
                Use existing API key
              </button>
            </div>

            {hubConnectMode === 'auto' ? (
              <div className="space-y-3">
                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-1">Hub URL</label>
                  <input
                    type="url"
                    value={hubUrl}
                    onChange={(e) => setHubUrl(e.target.value)}
                    placeholder="https://hub.peanutgraphic.com"
                    className="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                  />
                </div>
                <Button
                  onClick={() => autoConnectHubMutation.mutate()}
                  loading={autoConnectHubMutation.isPending}
                  disabled={!hubUrl}
                  icon={<Link2 className="w-4 h-4" />}
                >
                  Connect to Hub
                </Button>
              </div>
            ) : (
              <div className="space-y-3">
                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-1">Hub URL</label>
                  <input
                    type="url"
                    value={hubUrl}
                    onChange={(e) => setHubUrl(e.target.value)}
                    placeholder="https://hub.peanutgraphic.com"
                    className="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-slate-700 mb-1">API key</label>
                  <input
                    type="text"
                    value={hubApiKey}
                    onChange={(e) => setHubApiKey(e.target.value)}
                    placeholder="64-character key from Hub → Sites → your site"
                    autoComplete="off"
                    spellCheck={false}
                    className="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm text-slate-900 font-mono focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                  />
                  <p className="text-xs text-slate-500 mt-1">
                    The key is verified against Hub before saving. Stored as a WordPress option, never sent to
                    the React app on subsequent loads.
                  </p>
                </div>
                <Button
                  onClick={() => manualConnectHubMutation.mutate()}
                  loading={manualConnectHubMutation.isPending}
                  disabled={!hubUrl || !hubApiKey}
                  icon={<Link2 className="w-4 h-4" />}
                >
                  Verify & Connect
                </Button>
              </div>
            )}
          </div>
        )}
      </Card>

      {/* Debug & Logging */}
      <Card className="mb-6">
        <CardHeader
          title={
            <span className="flex items-center gap-2">
              <Bug className="w-5 h-5" />
              Debug & Logging
              <HelpTooltip content="Monitor PHP errors and debug issues on your site. Logs are stored securely and automatically rotated." />
            </span>
          }
          action={
            <button
              onClick={() => toggleErrorLoggingMutation.mutate(!errorLogData?.logging_enabled)}
              disabled={toggleErrorLoggingMutation.isPending}
              className={`flex items-center gap-2 px-3 py-1.5 rounded-lg text-sm font-medium transition-colors ${
                errorLogData?.logging_enabled
                  ? 'bg-green-100 text-green-700 hover:bg-green-200'
                  : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
              }`}
            >
              {errorLogData?.logging_enabled ? (
                <ToggleRight className="w-4 h-4" />
              ) : (
                <ToggleLeft className="w-4 h-4" />
              )}
              {errorLogData?.logging_enabled ? 'Logging On' : 'Logging Off'}
            </button>
          }
        />
        <div className="space-y-4">
          {/* Error Counts Summary */}
          <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div className="bg-red-50 border border-red-200 rounded-lg p-3">
              <div className="flex items-center gap-1.5 text-sm text-red-600">
                <AlertOctagon className="w-4 h-4" />
                Critical
              </div>
              <div className="text-xl font-bold text-red-700 mt-1">
                {errorCounts?.last_24h?.critical ?? 0}
              </div>
              <div className="text-xs text-red-500">last 24h</div>
            </div>
            <div className="bg-orange-50 border border-orange-200 rounded-lg p-3">
              <div className="flex items-center gap-1.5 text-sm text-orange-600">
                <AlertCircle className="w-4 h-4" />
                Errors
              </div>
              <div className="text-xl font-bold text-orange-700 mt-1">
                {errorCounts?.last_24h?.error ?? 0}
              </div>
              <div className="text-xs text-orange-500">last 24h</div>
            </div>
            <div className="bg-yellow-50 border border-yellow-200 rounded-lg p-3">
              <div className="flex items-center gap-1.5 text-sm text-yellow-600">
                <AlertTriangle className="w-4 h-4" />
                Warnings
              </div>
              <div className="text-xl font-bold text-yellow-700 mt-1">
                {errorCounts?.last_24h?.warning ?? 0}
              </div>
              <div className="text-xs text-yellow-500">last 24h</div>
            </div>
            <div className="bg-slate-50 border border-slate-200 rounded-lg p-3">
              <div className="flex items-center gap-1.5 text-sm text-slate-600">
                Total
              </div>
              <div className="text-xl font-bold text-slate-700 mt-1">
                {errorCounts?.all_time?.total ?? 0}
              </div>
              <div className="text-xs text-slate-500">all time</div>
            </div>
          </div>

          {/* Quick Info */}
          <div className="flex items-center justify-between p-3 bg-slate-50 rounded-lg border border-slate-200">
            <div>
              <p className="text-sm font-medium text-slate-700">Error Log</p>
              <p className="text-xs text-slate-500">
                View detailed PHP errors, warnings, and notices
              </p>
            </div>
            <Link
              to="/errors"
              className="flex items-center gap-1.5 text-sm font-medium text-blue-600 hover:text-blue-700"
            >
              View Full Log
              <ExternalLink className="w-4 h-4" />
            </Link>
          </div>

          {/* Log Storage Info */}
          <p className="text-xs text-slate-500">
            Logs are stored in <code className="bg-slate-100 px-1 py-0.5 rounded">wp-content/peanut-logs/</code> and protected from web access. Max 500 entries kept.
          </p>
        </div>
      </Card>

      {/* Security Settings */}
      <Card className="mb-6">
        <CardHeader
          title={
            <span className="flex items-center gap-2">
              <Shield className="w-5 h-5" />
              Security Hardening
              <HelpTooltip content="Enable security features to protect your WordPress site from common attacks and exploits." />
            </span>
          }
        />
        <div className="space-y-4">
          {/* Disable XML-RPC */}
          <div className="flex items-center justify-between p-4 bg-slate-50 rounded-lg border border-slate-200">
            <div className="flex-1">
              <div className="flex items-center gap-2 mb-1">
                <KeyRound className="w-4 h-4 text-slate-600" />
                <span className="font-medium text-slate-900">Disable XML-RPC</span>
              </div>
              <p className="text-sm text-slate-600">
                Disable the XML-RPC protocol to prevent brute force and DDoS attacks.
              </p>
            </div>
            <button
              onClick={() => updateSecurityMutation.mutate({ disable_xmlrpc: !securitySettings?.disable_xmlrpc })}
              disabled={updateSecurityMutation.isPending}
              className={`flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                securitySettings?.disable_xmlrpc
                  ? 'bg-green-100 text-green-700 hover:bg-green-200'
                  : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
              }`}
            >
              {securitySettings?.disable_xmlrpc ? (
                <>
                  <ToggleRight className="w-5 h-5" />
                  On
                </>
              ) : (
                <>
                  <ToggleLeft className="w-5 h-5" />
                  Off
                </>
              )}
            </button>
          </div>

          {/* Remove WordPress Version */}
          <div className="flex items-center justify-between p-4 bg-slate-50 rounded-lg border border-slate-200">
            <div className="flex-1">
              <div className="flex items-center gap-2 mb-1">
                <Hash className="w-4 h-4 text-slate-600" />
                <span className="font-medium text-slate-900">Hide WordPress Version</span>
              </div>
              <p className="text-sm text-slate-600">
                Remove WordPress version from page source and asset URLs.
              </p>
            </div>
            <button
              onClick={() => updateSecurityMutation.mutate({ remove_version: !securitySettings?.remove_version })}
              disabled={updateSecurityMutation.isPending}
              className={`flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                securitySettings?.remove_version
                  ? 'bg-green-100 text-green-700 hover:bg-green-200'
                  : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
              }`}
            >
              {securitySettings?.remove_version ? (
                <>
                  <ToggleRight className="w-5 h-5" />
                  On
                </>
              ) : (
                <>
                  <ToggleLeft className="w-5 h-5" />
                  Off
                </>
              )}
            </button>
          </div>

          {/* Disable Comments */}
          <div className="flex items-center justify-between p-4 bg-slate-50 rounded-lg border border-slate-200">
            <div className="flex-1">
              <div className="flex items-center gap-2 mb-1">
                <MessageSquareOff className="w-4 h-4 text-slate-600" />
                <span className="font-medium text-slate-900">Disable Comments</span>
              </div>
              <p className="text-sm text-slate-600">
                Completely disable the comments system across the entire site.
              </p>
            </div>
            <button
              onClick={() => updateSecurityMutation.mutate({ disable_comments: !securitySettings?.disable_comments?.enabled })}
              disabled={updateSecurityMutation.isPending}
              className={`flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                securitySettings?.disable_comments?.enabled
                  ? 'bg-green-100 text-green-700 hover:bg-green-200'
                  : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
              }`}
            >
              {securitySettings?.disable_comments?.enabled ? (
                <>
                  <ToggleRight className="w-5 h-5" />
                  On
                </>
              ) : (
                <>
                  <ToggleLeft className="w-5 h-5" />
                  Off
                </>
              )}
            </button>
          </div>

          {/* Hide Existing Comments - Only show if comments are disabled */}
          {securitySettings?.disable_comments?.enabled && (
            <div className="flex items-center justify-between p-4 ml-6 bg-slate-50 rounded-lg border border-slate-200">
              <div className="flex-1">
                <span className="font-medium text-slate-900">Hide Existing Comments</span>
                <p className="text-sm text-slate-600">
                  Also hide any existing comments on the site.
                </p>
              </div>
              <button
                onClick={() => updateSecurityMutation.mutate({ hide_existing_comments: !securitySettings?.disable_comments?.hide_existing })}
                disabled={updateSecurityMutation.isPending}
                className={`flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                  securitySettings?.disable_comments?.hide_existing
                    ? 'bg-green-100 text-green-700 hover:bg-green-200'
                    : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                }`}
              >
                {securitySettings?.disable_comments?.hide_existing ? (
                  <>
                    <ToggleRight className="w-5 h-5" />
                    On
                  </>
                ) : (
                  <>
                    <ToggleLeft className="w-5 h-5" />
                    Off
                  </>
                )}
              </button>
            </div>
          )}

          {/* Hide Login */}
          <div className="p-4 bg-slate-50 rounded-lg border border-slate-200">
            <div className="flex items-center justify-between">
              <div className="flex-1">
                <div className="flex items-center gap-2 mb-1">
                  <LogIn className="w-4 h-4 text-slate-600" />
                  <span className="font-medium text-slate-900">Custom Login URL</span>
                </div>
                <p className="text-sm text-slate-600">
                  Hide wp-login.php and use a custom URL for admin login.
                </p>
              </div>
              <button
                onClick={() => updateSecurityMutation.mutate({ hide_login_enabled: !securitySettings?.hide_login?.enabled })}
                disabled={updateSecurityMutation.isPending}
                className={`flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                  securitySettings?.hide_login?.enabled
                    ? 'bg-green-100 text-green-700 hover:bg-green-200'
                    : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                }`}
              >
                {securitySettings?.hide_login?.enabled ? (
                  <>
                    <ToggleRight className="w-5 h-5" />
                    On
                  </>
                ) : (
                  <>
                    <ToggleLeft className="w-5 h-5" />
                    Off
                  </>
                )}
              </button>
            </div>
            {securitySettings?.hide_login?.enabled && (
              <div className="mt-4 flex items-center gap-2">
                <span className="text-sm text-slate-600">{window.location.origin}/</span>
                <input
                  type="text"
                  value={loginSlug || securitySettings?.hide_login?.custom_slug || ''}
                  onChange={(e) => setLoginSlug(e.target.value)}
                  placeholder="my-login"
                  className="flex-1 max-w-[200px] px-3 py-2 border border-slate-300 rounded-lg text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
                <Button
                  size="sm"
                  onClick={() => {
                    if (loginSlug && loginSlug !== securitySettings?.hide_login?.custom_slug) {
                      updateSecurityMutation.mutate({ hide_login_slug: loginSlug });
                    }
                  }}
                  disabled={!loginSlug || loginSlug === securitySettings?.hide_login?.custom_slug || updateSecurityMutation.isPending}
                >
                  Save
                </Button>
              </div>
            )}
            {securitySettings?.hide_login?.enabled && securitySettings?.hide_login?.custom_slug && (
              <p className="mt-2 text-sm text-amber-600">
                <AlertTriangle className="w-4 h-4 inline mr-1" />
                Remember your login URL! Bookmark: {window.location.origin}/{securitySettings.hide_login.custom_slug}
              </p>
            )}
          </div>

          {/* File Editing Status (read-only) */}
          <div className="flex items-center justify-between p-4 bg-slate-50 rounded-lg border border-slate-200">
            <div className="flex-1">
              <span className="font-medium text-slate-900">File Editing</span>
              <p className="text-sm text-slate-600">
                Theme and plugin editor in WordPress admin.
              </p>
            </div>
            <Badge variant={securitySettings?.disable_file_editing ? 'success' : 'warning'}>
              {securitySettings?.disable_file_editing ? 'Disabled' : 'Enabled'}
            </Badge>
          </div>
          <p className="text-xs text-slate-500 -mt-2 ml-4">
            File editing is controlled via wp-config.php (DISALLOW_FILE_EDIT constant).
          </p>
        </div>
      </Card>

      {/* Hub Permissions */}
      {settings?.hub?.connected && (
        <Card className="mb-6">
          <CardHeader
            title={
              <span className="flex items-center gap-2">
                <Users className="w-5 h-5" />
                Hub Permissions
                <HelpTooltip content="Control what your agency's Hub can access and do on this site." />
              </span>
            }
          />
          <div className="space-y-4">
            <p className="text-sm text-slate-600">
              These settings control what Hub can access on this site. Health checks and viewing updates are always allowed.
            </p>

            {/* Perform Updates */}
            <div className="flex items-center justify-between p-4 bg-slate-50 rounded-lg border border-slate-200">
              <div className="flex-1">
                <div className="flex items-center gap-2 mb-1">
                  <Download className="w-4 h-4 text-slate-600" />
                  <span className="font-medium text-slate-900">Remote Updates</span>
                </div>
                <p className="text-sm text-slate-600">
                  Allow Hub to remotely update plugins and themes on this site.
                </p>
              </div>
              <button
                onClick={() => updatePermissionsMutation.mutate({ perform_updates: !permissions?.perform_updates })}
                disabled={updatePermissionsMutation.isPending}
                className={`flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                  permissions?.perform_updates
                    ? 'bg-green-100 text-green-700 hover:bg-green-200'
                    : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                }`}
              >
                {permissions?.perform_updates ? (
                  <>
                    <ToggleRight className="w-5 h-5" />
                    Allowed
                  </>
                ) : (
                  <>
                    <ToggleLeft className="w-5 h-5" />
                    Denied
                  </>
                )}
              </button>
            </div>

            {/* Access Analytics */}
            <div className="flex items-center justify-between p-4 bg-slate-50 rounded-lg border border-slate-200">
              <div className="flex-1">
                <div className="flex items-center gap-2 mb-1">
                  <BarChart3 className="w-4 h-4 text-slate-600" />
                  <span className="font-medium text-slate-900">Analytics Access</span>
                </div>
                <p className="text-sm text-slate-600">
                  Allow Hub to access Peanut Suite analytics data.
                </p>
              </div>
              <button
                onClick={() => updatePermissionsMutation.mutate({ access_analytics: !permissions?.access_analytics })}
                disabled={updatePermissionsMutation.isPending}
                className={`flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                  permissions?.access_analytics
                    ? 'bg-green-100 text-green-700 hover:bg-green-200'
                    : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                }`}
              >
                {permissions?.access_analytics ? (
                  <>
                    <ToggleRight className="w-5 h-5" />
                    Allowed
                  </>
                ) : (
                  <>
                    <ToggleLeft className="w-5 h-5" />
                    Denied
                  </>
                )}
              </button>
            </div>

            {/* API Proxy */}
            <div className="flex items-center justify-between p-4 bg-slate-50 rounded-lg border border-slate-200">
              <div className="flex-1">
                <div className="flex items-center gap-2 mb-1">
                  <Cloud className="w-4 h-4 text-slate-600" />
                  <span className="font-medium text-slate-900">API Proxy</span>
                </div>
                <p className="text-sm text-slate-600">
                  Allow Hub to route external API requests through this site.
                </p>
              </div>
              <button
                onClick={() => updatePermissionsMutation.mutate({ api_proxy: !permissions?.api_proxy })}
                disabled={updatePermissionsMutation.isPending}
                className={`flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                  permissions?.api_proxy
                    ? 'bg-green-100 text-green-700 hover:bg-green-200'
                    : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                }`}
              >
                {permissions?.api_proxy ? (
                  <>
                    <ToggleRight className="w-5 h-5" />
                    Allowed
                  </>
                ) : (
                  <>
                    <ToggleLeft className="w-5 h-5" />
                    Denied
                  </>
                )}
              </button>
            </div>

            {/* Track Logged-In Users */}
            <div className="flex items-center justify-between p-4 bg-slate-50 rounded-lg border border-slate-200">
              <div className="flex-1">
                <div className="flex items-center gap-2 mb-1">
                  <User className="w-4 h-4 text-slate-600" />
                  <span className="font-medium text-slate-900">Track Logged-In Users</span>
                </div>
                <p className="text-sm text-slate-600">
                  Include logged-in users in visitor tracking (by default they are excluded).
                </p>
              </div>
              <button
                onClick={() => updateTrackLoggedInMutation.mutate(!settings?.hub?.track_logged_in)}
                disabled={updateTrackLoggedInMutation.isPending}
                className={`flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                  settings?.hub?.track_logged_in
                    ? 'bg-green-100 text-green-700 hover:bg-green-200'
                    : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                }`}
              >
                {settings?.hub?.track_logged_in ? (
                  <>
                    <ToggleRight className="w-5 h-5" />
                    Tracked
                  </>
                ) : (
                  <>
                    <ToggleLeft className="w-5 h-5" />
                    Excluded
                  </>
                )}
              </button>
            </div>
          </div>
        </Card>
      )}

      {/* Plugin Updates */}
      <Card className="mb-6">
        <CardHeader
          title={
            <span className="flex items-center gap-2">
              <Download className="w-5 h-5" />
              Plugin Updates
              <HelpTooltip content="Force check for Peanut Connect updates. This clears the update cache and checks the server for new versions." />
            </span>
          }
        />
        <div className="space-y-4">
          <p className="text-sm text-slate-600">
            Update checks are cached for 12 hours. Use this button to force an immediate check for new versions.
          </p>
          <div className="flex items-center gap-4">
            <Button
              variant="outline"
              onClick={() => checkForUpdatesMutation.mutate()}
              loading={checkForUpdatesMutation.isPending}
              icon={<RefreshCw className="w-4 h-4" />}
            >
              Check for Updates
            </Button>
            <Link
              to="/updates"
              className="text-sm text-blue-600 hover:text-blue-700 flex items-center gap-1"
            >
              View Updates Page
              <ExternalLink className="w-4 h-4" />
            </Link>
          </div>
        </div>
      </Card>

      {/* Hub Disconnect Modal */}
      <ConfirmModal
        isOpen={showHubDisconnectModal}
        onClose={() => setShowHubDisconnectModal(false)}
        onConfirm={() => disconnectHubMutation.mutate()}
        title="Disconnect from Hub"
        message="Are you sure you want to disconnect from Peanut Hub? Health data will no longer be synced and you'll need to reconnect to resume monitoring."
        confirmText="Disconnect"
        variant="danger"
        loading={disconnectHubMutation.isPending}
      />
    </Layout>
  );
}
