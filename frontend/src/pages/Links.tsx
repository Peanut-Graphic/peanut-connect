import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Layout } from '@/components/layout';
import { Card, Alert } from '@/components/common';
import { marketingApi, type Link as ShortLink } from '@/api';
import { Power, PowerOff, Trash2, ExternalLink, Copy, Link2 } from 'lucide-react';

export default function Links() {
  const queryClient = useQueryClient();

  const { data, isLoading, error } = useQuery({
    queryKey: ['marketing', 'links'],
    queryFn: () => marketingApi.listLinks({ per_page: 50 }),
  });

  const invalidate = () =>
    queryClient.invalidateQueries({ queryKey: ['marketing', 'links'] });

  const toggle = useMutation({
    mutationFn: (id: number) => marketingApi.toggleLink(id),
    onSuccess: invalidate,
  });
  const remove = useMutation({
    mutationFn: (id: number) => marketingApi.deleteLink(id),
    onSuccess: invalidate,
  });

  const links = data?.data ?? [];

  return (
    <Layout
      title="Short Links"
      description="Trackable short links for this site. Toggle off to make a link stop redirecting without losing click history."
    >
      {error && (
        <Alert variant="error" className="mb-4">
          {(error as Error).message}
        </Alert>
      )}

      <Card padding="none">
        {isLoading ? (
          <div className="p-8 text-center text-sm text-slate-500">Loading…</div>
        ) : links.length === 0 ? (
          <div className="p-8 text-center text-sm text-slate-500">
            No short links yet — create one from the Campaigns page.
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                <tr>
                  <th className="text-left px-4 py-2 font-medium">Slug / Title</th>
                  <th className="text-left px-4 py-2 font-medium">Destination</th>
                  <th className="text-left px-4 py-2 font-medium">Campaign</th>
                  <th className="text-right px-4 py-2 font-medium">Clicks</th>
                  <th className="text-left px-4 py-2 font-medium">Status</th>
                  <th className="px-4 py-2"></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {links.map((link) => (
                  <LinkRow
                    key={link.id}
                    link={link}
                    onToggle={() => toggle.mutate(link.id)}
                    onDelete={() => {
                      if (confirm(`Delete "/${link.slug}"? Click history is removed too.`)) {
                        remove.mutate(link.id);
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

function LinkRow({
  link,
  onToggle,
  onDelete,
}: {
  link: ShortLink;
  onToggle: () => void;
  onDelete: () => void;
}) {
  const shortUrl = link.short_url ?? `/${link.slug}`;

  async function copyShort() {
    try {
      await navigator.clipboard.writeText(shortUrl);
    } catch {
      // Browser blocked clipboard.
    }
  }

  async function copyFull() {
    try {
      await navigator.clipboard.writeText(link.destination_url);
    } catch {
      // Browser blocked clipboard.
    }
  }

  return (
    <tr className="hover:bg-slate-50">
      <td className="px-4 py-3">
        <div className="font-mono text-slate-900">/{link.slug}</div>
        {link.title && <div className="text-xs text-slate-500">{link.title}</div>}
      </td>
      <td className="px-4 py-3">
        <div className="text-slate-700 truncate max-w-md">{link.destination_url}</div>
      </td>
      <td className="px-4 py-3 text-slate-700">
        {link.utm ? link.utm.utm_campaign : <span className="text-slate-400">—</span>}
      </td>
      <td className="px-4 py-3 text-right text-slate-700 tabular-nums">{link.click_count}</td>
      <td className="px-4 py-3">
        <span
          className={
            link.is_active
              ? 'inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700'
              : 'inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600'
          }
        >
          {link.is_active ? 'Active' : 'Inactive'}
        </span>
      </td>
      <td className="px-4 py-3">
        <div className="flex items-center justify-end gap-1">
          <button
            type="button"
            onClick={copyShort}
            className="p-1.5 rounded hover:bg-slate-100 text-slate-500 hover:text-slate-700"
            aria-label="Copy short link"
            title="Copy short link"
          >
            <Copy className="w-4 h-4" />
          </button>
          <button
            type="button"
            onClick={copyFull}
            className="p-1.5 rounded hover:bg-slate-100 text-slate-500 hover:text-slate-700"
            aria-label="Copy full UTM URL"
            title="Copy full UTM URL"
          >
            <Link2 className="w-4 h-4" />
          </button>
          {link.short_url && (
            <a
              href={link.short_url}
              target="_blank"
              rel="noreferrer noopener"
              className="p-1.5 rounded hover:bg-slate-100 text-slate-500 hover:text-slate-700"
              aria-label="Open short link"
              title="Open short link"
            >
              <ExternalLink className="w-4 h-4" />
            </a>
          )}
          <button
            type="button"
            onClick={onToggle}
            className="p-1.5 rounded hover:bg-slate-100 text-slate-500 hover:text-slate-700"
            aria-label={link.is_active ? 'Deactivate link' : 'Activate link'}
            title={link.is_active ? 'Deactivate' : 'Activate'}
          >
            {link.is_active ? <PowerOff className="w-4 h-4" /> : <Power className="w-4 h-4" />}
          </button>
          <button
            type="button"
            onClick={onDelete}
            className="p-1.5 rounded hover:bg-red-50 text-slate-400 hover:text-red-600"
            aria-label="Delete link"
            title="Delete"
          >
            <Trash2 className="w-4 h-4" />
          </button>
        </div>
      </td>
    </tr>
  );
}
