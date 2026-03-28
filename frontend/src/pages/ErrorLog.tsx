import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  AlertTriangle,
  AlertCircle,
  AlertOctagon,
  Info,
  Trash2,
  Download,
  RefreshCw,
  Filter,
  ToggleLeft,
  ToggleRight,
  FileCode,
  Clock,
  User,
  Globe,
} from 'lucide-react';
import { errorLogApi } from '@/api/endpoints';
import type { ErrorLevel, ErrorLogEntry } from '@/types';
import { useToast } from '@/components/common';
import { Layout } from '@/components/layout';

const levelConfig: Record<ErrorLevel, { icon: typeof AlertTriangle; color: string; bg: string }> = {
  critical: { icon: AlertOctagon, color: 'text-red-600', bg: 'bg-red-50' },
  error: { icon: AlertCircle, color: 'text-orange-600', bg: 'bg-orange-50' },
  warning: { icon: AlertTriangle, color: 'text-yellow-600', bg: 'bg-yellow-50' },
  notice: { icon: Info, color: 'text-blue-600', bg: 'bg-blue-50' },
};

export default function ErrorLog() {
  const [levelFilter, setLevelFilter] = useState<ErrorLevel | ''>('');
  const [page, setPage] = useState(0);
  const limit = 25;
  const queryClient = useQueryClient();
  const toast = useToast();

  // Fetch error log
  const { data, isLoading, refetch } = useQuery({
    queryKey: ['errorLog', levelFilter, page],
    queryFn: () => errorLogApi.get(limit, page * limit, levelFilter || undefined),
  });

  // Fetch counts
  const { data: countsData } = useQuery({
    queryKey: ['errorCounts'],
    queryFn: () => errorLogApi.getCounts(),
  });

  // Clear log mutation
  const clearMutation = useMutation({
    mutationFn: () => errorLogApi.clear(),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['errorLog'] });
      queryClient.invalidateQueries({ queryKey: ['errorCounts'] });
      toast.success('Error log cleared successfully');
    },
    onError: () => {
      toast.error('Failed to clear error log');
    },
  });

  // Toggle logging mutation
  const toggleMutation = useMutation({
    mutationFn: (enabled: boolean) => errorLogApi.updateSettings(enabled),
    onSuccess: (_, enabled) => {
      queryClient.invalidateQueries({ queryKey: ['errorLog'] });
      queryClient.invalidateQueries({ queryKey: ['errorCounts'] });
      toast.success(`Error logging ${enabled ? 'enabled' : 'disabled'}`);
    },
  });

  // Export handler
  const handleExport = async () => {
    try {
      const result = await errorLogApi.export();
      const blob = new Blob([result.csv], { type: 'text/csv' });
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = result.filename;
      a.click();
      window.URL.revokeObjectURL(url);
      toast.success('Error log exported');
    } catch {
      toast.error('Failed to export error log');
    }
  };

  const loggingEnabled = data?.logging_enabled ?? true;
  const entries = data?.entries ?? [];
  const counts = countsData?.last_24h;
  const allTimeCounts = countsData?.all_time;

  const actionButtons = (
    <div className="flex items-center gap-2">
      <button
        onClick={() => toggleMutation.mutate(!loggingEnabled)}
        className={`flex items-center gap-2 px-3 py-1.5 rounded-lg text-sm font-medium ${
          loggingEnabled
            ? 'bg-green-100 text-green-700'
            : 'bg-gray-100 text-gray-600'
        }`}
      >
        {loggingEnabled ? (
          <ToggleRight className="w-4 h-4" />
        ) : (
          <ToggleLeft className="w-4 h-4" />
        )}
        {loggingEnabled ? 'Enabled' : 'Disabled'}
      </button>
      <button
        onClick={() => refetch()}
        className="p-2 text-gray-600 hover:text-gray-900 hover:bg-gray-100 rounded-lg"
        title="Refresh"
      >
        <RefreshCw className="w-5 h-5" />
      </button>
      <button
        onClick={handleExport}
        className="p-2 text-gray-600 hover:text-gray-900 hover:bg-gray-100 rounded-lg"
        title="Export CSV"
      >
        <Download className="w-5 h-5" />
      </button>
      <button
        onClick={() => {
          if (confirm('Are you sure you want to clear the error log?')) {
            clearMutation.mutate();
          }
        }}
        className="p-2 text-red-600 hover:text-red-700 hover:bg-red-50 rounded-lg"
        title="Clear Log"
      >
        <Trash2 className="w-5 h-5" />
      </button>
    </div>
  );

  return (
    <Layout
      title="Error Log"
      description="PHP errors, warnings, and notices captured from your site"
      action={actionButtons}
    >
      {/* Stats Cards */}
      <div className="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
        <div className="bg-white rounded-lg border border-gray-200 p-4">
          <div className="text-sm text-gray-500">Last 24h</div>
          <div className="text-2xl font-bold text-gray-900">{counts?.total ?? 0}</div>
        </div>
        <div className="bg-white rounded-lg border border-red-200 p-4">
          <div className="flex items-center gap-1 text-sm text-red-600">
            <AlertOctagon className="w-4 h-4" />
            Critical
          </div>
          <div className="text-2xl font-bold text-red-600">{counts?.critical ?? 0}</div>
        </div>
        <div className="bg-white rounded-lg border border-orange-200 p-4">
          <div className="flex items-center gap-1 text-sm text-orange-600">
            <AlertCircle className="w-4 h-4" />
            Errors
          </div>
          <div className="text-2xl font-bold text-orange-600">{counts?.error ?? 0}</div>
        </div>
        <div className="bg-white rounded-lg border border-yellow-200 p-4">
          <div className="flex items-center gap-1 text-sm text-yellow-600">
            <AlertTriangle className="w-4 h-4" />
            Warnings
          </div>
          <div className="text-2xl font-bold text-yellow-600">{counts?.warning ?? 0}</div>
        </div>
        <div className="bg-white rounded-lg border border-blue-200 p-4">
          <div className="flex items-center gap-1 text-sm text-blue-600">
            <Info className="w-4 h-4" />
            Notices
          </div>
          <div className="text-2xl font-bold text-blue-600">{counts?.notice ?? 0}</div>
        </div>
      </div>

      {/* Filter Bar */}
      <div className="flex items-center justify-between mb-4">
        <div className="flex items-center gap-2">
          <Filter className="w-4 h-4 text-gray-400" />
          <select
            value={levelFilter}
            onChange={(e) => {
              setLevelFilter(e.target.value as ErrorLevel | '');
              setPage(0);
            }}
            className="border border-gray-300 rounded-lg px-3 py-1.5 text-sm text-slate-900 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
          >
            <option value="">All Levels</option>
            <option value="critical">Critical</option>
            <option value="error">Error</option>
            <option value="warning">Warning</option>
            <option value="notice">Notice</option>
          </select>
        </div>
        <div className="text-sm text-gray-500">
          {allTimeCounts?.total ?? 0} total entries
        </div>
      </div>

      {/* Error Log Table */}
      <div className="bg-white rounded-lg border border-gray-200 overflow-hidden">
        {isLoading ? (
          <div className="p-8 text-center text-gray-500">Loading...</div>
        ) : entries.length === 0 ? (
          <div className="p-8 text-center text-gray-500">
            <AlertCircle className="w-12 h-12 mx-auto mb-4 text-gray-300" />
            <p className="text-lg font-medium">No errors logged</p>
            <p className="text-sm">Your site is running smoothly!</p>
          </div>
        ) : (
          <div className="divide-y divide-gray-200">
            {entries.map((entry, index) => (
              <ErrorRow key={index} entry={entry} />
            ))}
          </div>
        )}
      </div>

      {/* Pagination */}
      {entries.length > 0 && (
        <div className="flex items-center justify-between mt-4">
          <button
            onClick={() => setPage((p) => Math.max(0, p - 1))}
            disabled={page === 0}
            className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
          >
            Previous
          </button>
          <span className="text-sm text-gray-500">Page {page + 1}</span>
          <button
            onClick={() => setPage((p) => p + 1)}
            disabled={entries.length < limit}
            className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
          >
            Next
          </button>
        </div>
      )}
    </Layout>
  );
}

function ErrorRow({ entry }: { entry: ErrorLogEntry }) {
  const [expanded, setExpanded] = useState(false);
  const config = levelConfig[entry.level] || levelConfig.notice;
  const Icon = config.icon;

  return (
    <div className={`${expanded ? config.bg : ''}`}>
      <div
        className="p-4 cursor-pointer hover:bg-gray-50"
        onClick={() => setExpanded(!expanded)}
      >
        <div className="flex items-start gap-3">
          <div className={`p-1.5 rounded ${config.bg}`}>
            <Icon className={`w-4 h-4 ${config.color}`} />
          </div>
          <div className="flex-1 min-w-0">
            <div className="flex items-center gap-2 mb-1">
              <span className={`text-xs font-medium px-2 py-0.5 rounded ${config.bg} ${config.color}`}>
                {entry.type}
              </span>
              <span className="text-xs text-gray-500">{entry.timestamp}</span>
            </div>
            <p className="text-sm text-gray-900 break-words">{entry.message}</p>
            <div className="flex items-center gap-4 mt-2 text-xs text-gray-500">
              <span className="flex items-center gap-1">
                <FileCode className="w-3 h-3" />
                {entry.file}:{entry.line}
              </span>
            </div>
          </div>
        </div>
      </div>

      {expanded && (
        <div className="px-4 pb-4 ml-10 space-y-2">
          <div className="grid grid-cols-2 md:grid-cols-4 gap-4 text-xs">
            <div className="flex items-center gap-1.5 text-gray-600">
              <Clock className="w-3.5 h-3.5" />
              <span>{entry.timestamp}</span>
            </div>
            <div className="flex items-center gap-1.5 text-gray-600">
              <Globe className="w-3.5 h-3.5" />
              <span className="truncate">{entry.url}</span>
            </div>
            <div className="flex items-center gap-1.5 text-gray-600">
              <User className="w-3.5 h-3.5" />
              <span>User ID: {entry.user_id || 'Guest'}</span>
            </div>
            <div className="flex items-center gap-1.5 text-gray-600">
              <FileCode className="w-3.5 h-3.5" />
              <span>{entry.file}:{entry.line}</span>
            </div>
          </div>
          {entry.php_version && (
            <div className="text-xs text-gray-500">
              PHP {entry.php_version}
              {entry.memory_usage && ` | Memory: ${(entry.memory_usage / 1024 / 1024).toFixed(1)} MB`}
            </div>
          )}
          <div className="bg-gray-900 text-gray-100 p-3 rounded-lg text-xs font-mono overflow-x-auto">
            {entry.message}
          </div>
        </div>
      )}
    </div>
  );
}
