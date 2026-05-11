import { useState, useEffect } from 'react';
import { Layout } from '@/components/layout';
import {
  Card,
  CardHeader,
  Button,
  Badge,
  CollapsibleBanner,
  HelpTooltip,
} from '@/components/common';
import { useConfirm } from '@/hooks/useConfirm';
import { getActivityLog, clearActivityLog, activityLogger } from '@/services/activityLog';
import type { ActivityLogEntry, ActivityStatus, ActivityType } from '@/types';
import {
  Activity,
  CheckCircle2,
  AlertTriangle,
  XCircle,
  Info,
  Trash2,
  RefreshCw,
  Download,
  Link2,
  Settings,
  Heart,
  ClipboardList,
} from 'lucide-react';
import { formatDistanceToNow, format } from 'date-fns';

const statusConfig: Record<ActivityStatus, { icon: typeof CheckCircle2; color: string; bgColor: string }> = {
  success: {
    icon: CheckCircle2,
    color: 'text-green-600',
    bgColor: 'bg-green-50',
  },
  warning: {
    icon: AlertTriangle,
    color: 'text-amber-600',
    bgColor: 'bg-amber-50',
  },
  error: {
    icon: XCircle,
    color: 'text-red-600',
    bgColor: 'bg-red-50',
  },
  info: {
    icon: Info,
    color: 'text-blue-600',
    bgColor: 'bg-blue-50',
  },
};

const typeConfig: Record<ActivityType, { icon: typeof Activity; label: string }> = {
  health_check: { icon: Heart, label: 'Health Check' },
  update_installed: { icon: Download, label: 'Update' },
  update_failed: { icon: XCircle, label: 'Update Failed' },
  hub_connected: { icon: Link2, label: 'Hub Connected' },
  hub_disconnected: { icon: Link2, label: 'Hub Disconnected' },
  settings_changed: { icon: Settings, label: 'Settings' },
};

export default function ActivityPage() {
  const [entries, setEntries] = useState<ActivityLogEntry[]>([]);
  const [filter, setFilter] = useState<'all' | ActivityStatus>('all');
  const { confirm, dialog: confirmDialog } = useConfirm();

  useEffect(() => {
    loadEntries();
  }, []);

  const loadEntries = () => {
    setEntries(getActivityLog());
  };

  const handleClearLog = async () => {
    const ok = await confirm({
      title: 'Clear activity log?',
      message: 'This deletes all stored entries and cannot be undone.',
      confirmText: 'Clear log',
      variant: 'danger',
    });
    if (ok) {
      clearActivityLog();
      setEntries([]);
    }
  };

  const handleAddTestEntry = () => {
    // Add a test health check entry for demo
    activityLogger.healthCheck('healthy');
    loadEntries();
  };

  const filteredEntries = filter === 'all'
    ? entries
    : entries.filter(e => e.status === filter);

  const groupedEntries = filteredEntries.reduce<Record<string, ActivityLogEntry[]>>((acc, entry) => {
    const date = format(new Date(entry.timestamp), 'yyyy-MM-dd');
    if (!acc[date]) acc[date] = [];
    acc[date].push(entry);
    return acc;
  }, {});

  const formatDate = (dateStr: string) => {
    const date = new Date(dateStr);
    const today = new Date();
    const yesterday = new Date(today);
    yesterday.setDate(yesterday.getDate() - 1);

    if (date.toDateString() === today.toDateString()) {
      return 'Today';
    } else if (date.toDateString() === yesterday.toDateString()) {
      return 'Yesterday';
    }
    return format(date, 'EEEE, MMMM d, yyyy');
  };

  return (
    <Layout
      title="Activity Log"
      description="Recent events and system activity"
      action={
        <div className="flex items-center gap-2">
          <Button variant="outline" size="sm" onClick={loadEntries}>
            <RefreshCw className="w-4 h-4 mr-1" />
            Refresh
          </Button>
          {entries.length > 0 && (
            <Button variant="outline" size="sm" onClick={handleClearLog}>
              <Trash2 className="w-4 h-4 mr-1" />
              Clear Log
            </Button>
          )}
        </div>
      }
    >
      <div className="space-y-6">
        {/* Info Banner */}
        <CollapsibleBanner
          variant="amber"
          title="About Activity Log"
          icon={<ClipboardList className="w-4 h-4" />}
          defaultOpen={entries.length === 0}
          dismissible
        >
          <p className="mb-2">
            The activity log tracks important events on your site, including:
          </p>
          <ul className="list-disc list-inside space-y-1">
            <li>Health check results and system status changes</li>
            <li>Plugin, theme, and core updates (success and failures)</li>
            <li>Connection events with your manager site</li>
            <li>Permission and settings changes</li>
          </ul>
          <p className="mt-2 opacity-75">
            Activity is stored locally in your browser and limited to the last 100 entries.
          </p>
        </CollapsibleBanner>

        {/* Filters */}
        <Card>
          <div className="flex items-center justify-between flex-wrap gap-3">
            <div className="flex items-center gap-2">
              <span className="text-sm font-medium text-slate-700">Filter:</span>
              <div className="flex gap-1">
                {(['all', 'success', 'warning', 'error', 'info'] as const).map((status) => (
                  <button
                    key={status}
                    onClick={() => setFilter(status)}
                    className={`px-3 py-1.5 text-sm font-medium rounded-lg transition-colors ${
                      filter === status
                        ? 'bg-primary-100 text-primary-700'
                        : 'text-slate-600 hover:bg-slate-100'
                    }`}
                  >
                    {status.charAt(0).toUpperCase() + status.slice(1)}
                  </button>
                ))}
              </div>
            </div>
            <div className="flex items-center gap-2 text-sm text-slate-500">
              <Activity className="w-4 h-4" />
              {filteredEntries.length} {filteredEntries.length === 1 ? 'entry' : 'entries'}
              <HelpTooltip content="Activity entries are stored locally in your browser for convenience. They persist across page reloads but are cleared when you clear browser data." />
            </div>
          </div>
        </Card>

        {/* Activity Timeline */}
        {entries.length === 0 ? (
          <Card>
            <div className="text-center py-12">
              <Activity className="w-12 h-12 text-slate-300 mx-auto mb-4" />
              <h3 className="text-lg font-semibold text-slate-900 mb-2">No Activity Yet</h3>
              <p className="text-slate-500 mb-4">
                Activity will appear here as you use the plugin.
              </p>
              <Button variant="outline" onClick={handleAddTestEntry}>
                <Heart className="w-4 h-4 mr-2" />
                Log Test Health Check
              </Button>
            </div>
          </Card>
        ) : filteredEntries.length === 0 ? (
          <Card>
            <div className="text-center py-8">
              <Info className="w-10 h-10 text-slate-300 mx-auto mb-3" />
              <p className="text-slate-500">No entries match the selected filter.</p>
            </div>
          </Card>
        ) : (
          <div className="space-y-6">
            {Object.entries(groupedEntries).map(([date, dayEntries]) => (
              <div key={date}>
                <h3 className="text-sm font-semibold text-slate-500 mb-3 px-1">
                  {formatDate(date)}
                </h3>
                <Card padding="none">
                  <div className="divide-y divide-slate-100">
                    {dayEntries.map((entry) => {
                      const statusConf = statusConfig[entry.status];
                      const typeConf = typeConfig[entry.type];
                      const StatusIcon = statusConf.icon;
                      const TypeIcon = typeConf.icon;

                      return (
                        <div key={entry.id} className="flex items-start gap-4 p-4 hover:bg-slate-50 transition-colors">
                          <div className={`flex-shrink-0 w-10 h-10 rounded-full ${statusConf.bgColor} flex items-center justify-center`}>
                            <StatusIcon className={`w-5 h-5 ${statusConf.color}`} />
                          </div>
                          <div className="flex-1 min-w-0">
                            <div className="flex items-center gap-2 mb-1">
                              <span className="font-medium text-slate-900">{entry.title}</span>
                              <Badge variant="default" className="text-xs">
                                <TypeIcon className="w-3 h-3 mr-1" />
                                {typeConf.label}
                              </Badge>
                            </div>
                            <p className="text-sm text-slate-600">{entry.description}</p>
                            <p className="text-xs text-slate-400 mt-1">
                              {formatDistanceToNow(new Date(entry.timestamp), { addSuffix: true })}
                              {' · '}
                              {format(new Date(entry.timestamp), 'h:mm a')}
                            </p>
                          </div>
                        </div>
                      );
                    })}
                  </div>
                </Card>
              </div>
            ))}
          </div>
        )}

        {/* Summary Stats */}
        {entries.length > 0 && (
          <Card>
            <CardHeader
              title={
                <span className="flex items-center gap-2">
                  Activity Summary
                  <HelpTooltip content="A quick overview of recent activity by type." />
                </span>
              }
            />
            <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
              {(['success', 'warning', 'error', 'info'] as const).map((status) => {
                const count = entries.filter(e => e.status === status).length;
                const conf = statusConfig[status];
                const Icon = conf.icon;
                return (
                  <div
                    key={status}
                    className={`p-3 rounded-lg ${conf.bgColor} cursor-pointer hover:opacity-80 transition-opacity`}
                    onClick={() => setFilter(status)}
                  >
                    <div className="flex items-center gap-2">
                      <Icon className={`w-5 h-5 ${conf.color}`} />
                      <span className="text-2xl font-bold text-slate-900">{count}</span>
                    </div>
                    <p className="text-sm text-slate-600 mt-1 capitalize">{status}</p>
                  </div>
                );
              })}
            </div>
          </Card>
        )}
      </div>
      {confirmDialog}
    </Layout>
  );
}
