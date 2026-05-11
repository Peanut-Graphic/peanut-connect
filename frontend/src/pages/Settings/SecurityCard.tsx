import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  Shield,
  KeyRound,
  Hash,
  MessageSquareOff,
  LogIn,
  AlertTriangle,
  ToggleLeft,
  ToggleRight,
} from 'lucide-react';
import { Card, CardHeader, Badge, Button, HelpTooltip } from '@/components/common';
import { useToast } from '@/components/common';
import { securityApi } from '@/api/endpoints';

/**
 * Security Hardening card — extracted from Settings.tsx in 3.7.22.
 *
 * Fully self-contained: owns its own queries + mutations. Parent renders
 * <SecurityCard /> with no props.
 */
export default function SecurityCard() {
  const queryClient = useQueryClient();
  const toast = useToast();
  const [loginSlug, setLoginSlug] = useState('');

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
            onClick={() =>
              updateSecurityMutation.mutate({ disable_xmlrpc: !securitySettings?.disable_xmlrpc })
            }
            disabled={updateSecurityMutation.isPending}
            className={toggleClass(!!securitySettings?.disable_xmlrpc)}
          >
            {securitySettings?.disable_xmlrpc ? <ToggleRight className="w-5 h-5" /> : <ToggleLeft className="w-5 h-5" />}
            {securitySettings?.disable_xmlrpc ? 'On' : 'Off'}
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
            onClick={() =>
              updateSecurityMutation.mutate({ remove_version: !securitySettings?.remove_version })
            }
            disabled={updateSecurityMutation.isPending}
            className={toggleClass(!!securitySettings?.remove_version)}
          >
            {securitySettings?.remove_version ? <ToggleRight className="w-5 h-5" /> : <ToggleLeft className="w-5 h-5" />}
            {securitySettings?.remove_version ? 'On' : 'Off'}
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
            onClick={() =>
              updateSecurityMutation.mutate({
                disable_comments: !securitySettings?.disable_comments?.enabled,
              })
            }
            disabled={updateSecurityMutation.isPending}
            className={toggleClass(!!securitySettings?.disable_comments?.enabled)}
          >
            {securitySettings?.disable_comments?.enabled ? <ToggleRight className="w-5 h-5" /> : <ToggleLeft className="w-5 h-5" />}
            {securitySettings?.disable_comments?.enabled ? 'On' : 'Off'}
          </button>
        </div>

        {/* Hide Existing Comments — only shown if comments are disabled */}
        {securitySettings?.disable_comments?.enabled && (
          <div className="flex items-center justify-between p-4 ml-6 bg-slate-50 rounded-lg border border-slate-200">
            <div className="flex-1">
              <span className="font-medium text-slate-900">Hide Existing Comments</span>
              <p className="text-sm text-slate-600">Also hide any existing comments on the site.</p>
            </div>
            <button
              onClick={() =>
                updateSecurityMutation.mutate({
                  hide_existing_comments: !securitySettings?.disable_comments?.hide_existing,
                })
              }
              disabled={updateSecurityMutation.isPending}
              className={toggleClass(!!securitySettings?.disable_comments?.hide_existing)}
            >
              {securitySettings?.disable_comments?.hide_existing ? <ToggleRight className="w-5 h-5" /> : <ToggleLeft className="w-5 h-5" />}
              {securitySettings?.disable_comments?.hide_existing ? 'On' : 'Off'}
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
              onClick={() =>
                updateSecurityMutation.mutate({
                  hide_login_enabled: !securitySettings?.hide_login?.enabled,
                })
              }
              disabled={updateSecurityMutation.isPending}
              className={toggleClass(!!securitySettings?.hide_login?.enabled)}
            >
              {securitySettings?.hide_login?.enabled ? <ToggleRight className="w-5 h-5" /> : <ToggleLeft className="w-5 h-5" />}
              {securitySettings?.hide_login?.enabled ? 'On' : 'Off'}
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
                disabled={
                  !loginSlug ||
                  loginSlug === securitySettings?.hide_login?.custom_slug ||
                  updateSecurityMutation.isPending
                }
              >
                Save
              </Button>
            </div>
          )}
          {securitySettings?.hide_login?.enabled && securitySettings?.hide_login?.custom_slug && (
            <p className="mt-2 text-sm text-amber-600">
              <AlertTriangle className="w-4 h-4 inline mr-1" />
              Remember your login URL! Bookmark: {window.location.origin}/
              {securitySettings.hide_login.custom_slug}
            </p>
          )}
        </div>

        {/* File Editing Status (read-only) */}
        <div className="flex items-center justify-between p-4 bg-slate-50 rounded-lg border border-slate-200">
          <div className="flex-1">
            <span className="font-medium text-slate-900">File Editing</span>
            <p className="text-sm text-slate-600">Theme and plugin editor in WordPress admin.</p>
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
  );
}
