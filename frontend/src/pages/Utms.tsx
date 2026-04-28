import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Layout } from '@/components/layout';
import { Card, Button, Alert } from '@/components/common';
import { marketingApi, type Utm } from '@/api';
import { Archive, ArchiveRestore, Trash2, ExternalLink, Copy } from 'lucide-react';

export default function Utms() {
  const [showArchived, setShowArchived] = useState(false);
  const queryClient = useQueryClient();

  const { data, isLoading, error } = useQuery({
    queryKey: ['marketing', 'utms', { archived: showArchived }],
    queryFn: () => marketingApi.listUtms({ archived: showArchived, per_page: 50 }),
  });

  const invalidate = () =>
    queryClient.invalidateQueries({ queryKey: ['marketing', 'utms'] });

  const archive = useMutation({
    mutationFn: (id: number) => marketingApi.archiveUtm(id),
    onSuccess: invalidate,
  });
  const restore = useMutation({
    mutationFn: (id: number) => marketingApi.restoreUtm(id),
    onSuccess: invalidate,
  });
  const remove = useMutation({
    mutationFn: (id: number) => marketingApi.deleteUtm(id),
    onSuccess: invalidate,
  });

  const utms = data?.data ?? [];

  return (
    <Layout
      title="UTMs"
      description="Tagged URLs created for this site. Archive ones you're done with; delete only when you no longer need the click history."
      action={
        <div className="flex items-center gap-2">
          <Button
            variant={showArchived ? 'ghost' : 'primary'}
            size="sm"
            onClick={() => setShowArchived(false)}
          >
            Active
          </Button>
          <Button
            variant={showArchived ? 'primary' : 'ghost'}
            size="sm"
            onClick={() => setShowArchived(true)}
          >
            Archived
          </Button>
        </div>
      }
    >
      {error && (
        <Alert variant="error" className="mb-4">
          {(error as Error).message}
        </Alert>
      )}

      <Card padding="none">
        {isLoading ? (
          <div className="p-8 text-center text-sm text-slate-500">Loading…</div>
        ) : utms.length === 0 ? (
          <div className="p-8 text-center text-sm text-slate-500">
            No {showArchived ? 'archived' : 'active'} UTMs yet.
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                <tr>
                  <th className="text-left px-4 py-2 font-medium">Name</th>
                  <th className="text-left px-4 py-2 font-medium">Source / Medium</th>
                  <th className="text-left px-4 py-2 font-medium">Campaign</th>
                  <th className="text-right px-4 py-2 font-medium">Clicks</th>
                  <th className="text-right px-4 py-2 font-medium">Links</th>
                  <th className="px-4 py-2"></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {utms.map((utm) => (
                  <UtmRow
                    key={utm.id}
                    utm={utm}
                    archived={showArchived}
                    onArchive={() => archive.mutate(utm.id)}
                    onRestore={() => restore.mutate(utm.id)}
                    onDelete={() => {
                      if (confirm(`Delete "${utm.name}"? This also removes its short links and click history.`)) {
                        remove.mutate(utm.id);
                      }
                    }}
                  />
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </Layout>
  );
}

function UtmRow({
  utm,
  archived,
  onArchive,
  onRestore,
  onDelete,
}: {
  utm: Utm;
  archived: boolean;
  onArchive: () => void;
  onRestore: () => void;
  onDelete: () => void;
}) {
  async function copyUrl() {
    try {
      await navigator.clipboard.writeText(utm.full_url);
    } catch {
      // Browser blocked clipboard; user can copy manually.
    }
  }

  return (
    <tr className="hover:bg-slate-50">
      <td className="px-4 py-3">
        <div className="font-medium text-slate-900">{utm.name}</div>
        <div className="text-xs text-slate-500 truncate max-w-md">{utm.base_url}</div>
      </td>
      <td className="px-4 py-3 text-slate-700">
        {utm.utm_source} / {utm.utm_medium}
      </td>
      <td className="px-4 py-3 text-slate-700">{utm.utm_campaign}</td>
      <td className="px-4 py-3 text-right text-slate-700 tabular-nums">{utm.click_count ?? 0}</td>
      <td className="px-4 py-3 text-right text-slate-700 tabular-nums">{utm.links_count ?? 0}</td>
      <td className="px-4 py-3">
        <div className="flex items-center justify-end gap-1">
          <button
            type="button"
            onClick={copyUrl}
            className="p-1.5 rounded hover:bg-slate-100 text-slate-500 hover:text-slate-700"
            aria-label="Copy full URL"
            title="Copy full URL"
          >
            <Copy className="w-4 h-4" />
          </button>
          <a
            href={utm.full_url}
            target="_blank"
            rel="noreferrer noopener"
            className="p-1.5 rounded hover:bg-slate-100 text-slate-500 hover:text-slate-700"
            aria-label="Open URL"
            title="Open URL"
          >
            <ExternalLink className="w-4 h-4" />
          </a>
          {archived ? (
            <button
              type="button"
              onClick={onRestore}
              className="p-1.5 rounded hover:bg-slate-100 text-slate-500 hover:text-slate-700"
              aria-label="Restore from archive"
              title="Restore"
            >
              <ArchiveRestore className="w-4 h-4" />
            </button>
          ) : (
            <button
              type="button"
              onClick={onArchive}
              className="p-1.5 rounded hover:bg-slate-100 text-slate-500 hover:text-slate-700"
              aria-label="Archive UTM"
              title="Archive"
            >
              <Archive className="w-4 h-4" />
            </button>
          )}
          <button
            type="button"
            onClick={onDelete}
            className="p-1.5 rounded hover:bg-red-50 text-slate-400 hover:text-red-600"
            aria-label="Delete UTM"
            title="Delete"
          >
            <Trash2 className="w-4 h-4" />
          </button>
        </div>
      </td>
    </tr>
  );
}
