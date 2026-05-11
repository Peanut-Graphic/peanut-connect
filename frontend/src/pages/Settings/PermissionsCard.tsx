import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  Users,
  Download,
  BarChart3,
  Cloud,
  User,
  ToggleLeft,
  ToggleRight,
} from 'lucide-react';
import { Card, CardHeader, HelpTooltip } from '@/components/common';
import { useToast } from '@/components/common';
import { permissionsApi } from '@/api/endpoints';

/**
 * Hub Permissions card — extracted from Settings.tsx in 3.7.22.
 *
 * Owns its own permissions query + mutation. The "track logged-in users"
 * toggle reads `trackLoggedIn` from the parent (it lives on the parent's
 * `settings.hub` payload) and emits via `onTrackLoggedInChange`.
 */
interface Props {
  trackLoggedIn: boolean;
  trackLoggedInPending: boolean;
  onTrackLoggedInChange: (next: boolean) => void;
}

export default function PermissionsCard({
  trackLoggedIn,
  trackLoggedInPending,
  onTrackLoggedInChange,
}: Props) {
  const queryClient = useQueryClient();
  const toast = useToast();

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

  const toggleClass = (on: boolean) =>
    `flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
      on
        ? 'bg-green-100 text-green-700 hover:bg-green-200'
        : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
    }`;

  return (
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
            onClick={() =>
              updatePermissionsMutation.mutate({ perform_updates: !permissions?.perform_updates })
            }
            disabled={updatePermissionsMutation.isPending}
            className={toggleClass(!!permissions?.perform_updates)}
          >
            {permissions?.perform_updates ? <ToggleRight className="w-5 h-5" /> : <ToggleLeft className="w-5 h-5" />}
            {permissions?.perform_updates ? 'Allowed' : 'Denied'}
          </button>
        </div>

        {/* Access Analytics */}
        <div className="flex items-center justify-between p-4 bg-slate-50 rounded-lg border border-slate-200">
          <div className="flex-1">
            <div className="flex items-center gap-2 mb-1">
              <BarChart3 className="w-4 h-4 text-slate-600" />
              <span className="font-medium text-slate-900">Analytics Access</span>
            </div>
            <p className="text-sm text-slate-600">Allow Hub to access Peanut Suite analytics data.</p>
          </div>
          <button
            onClick={() =>
              updatePermissionsMutation.mutate({ access_analytics: !permissions?.access_analytics })
            }
            disabled={updatePermissionsMutation.isPending}
            className={toggleClass(!!permissions?.access_analytics)}
          >
            {permissions?.access_analytics ? <ToggleRight className="w-5 h-5" /> : <ToggleLeft className="w-5 h-5" />}
            {permissions?.access_analytics ? 'Allowed' : 'Denied'}
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
            onClick={() =>
              updatePermissionsMutation.mutate({ api_proxy: !permissions?.api_proxy })
            }
            disabled={updatePermissionsMutation.isPending}
            className={toggleClass(!!permissions?.api_proxy)}
          >
            {permissions?.api_proxy ? <ToggleRight className="w-5 h-5" /> : <ToggleLeft className="w-5 h-5" />}
            {permissions?.api_proxy ? 'Allowed' : 'Denied'}
          </button>
        </div>

        {/* Track Logged-In Users (lives on parent's settings.hub payload) */}
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
            onClick={() => onTrackLoggedInChange(!trackLoggedIn)}
            disabled={trackLoggedInPending}
            className={toggleClass(trackLoggedIn)}
          >
            {trackLoggedIn ? <ToggleRight className="w-5 h-5" /> : <ToggleLeft className="w-5 h-5" />}
            {trackLoggedIn ? 'Tracked' : 'Excluded'}
          </button>
        </div>
      </div>
    </Card>
  );
}
