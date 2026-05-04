import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Layout } from '@/components/layout';
import { Card, Button, Alert } from '@/components/common';
import { marketingApi, type Utm, type UtmUpdateInput } from '@/api';
import {
  Archive,
  ArchiveRestore,
  Trash2,
  ExternalLink,
  Copy,
  Pencil,
} from 'lucide-react';

export default function Utms() {
  const [showArchived, setShowArchived] = useState(false);
  const [editing, setEditing] = useState<Utm | null>(null);
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
  const update = useMutation({
    mutationFn: ({ id, input }: { id: number; input: UtmUpdateInput }) =>
      marketingApi.updateUtm(id, input),
    onSuccess: () => {
      invalidate();
      setEditing(null);
    },
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
                  <th className="text-right px-4 py-2 font-medium">Reach</th>
                  <th className="text-right px-4 py-2 font-medium">Cost</th>
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
                    onEdit={() => setEditing(utm)}
                    onArchive={() => archive.mutate(utm.id)}
                    onRestore={() => restore.mutate(utm.id)}
                    onDelete={() => {
                      if (
                        confirm(
                          `Delete "${utm.name}"? This also removes its short links and click history.`,
                        )
                      ) {
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

      {editing && (
        <EditUtmModal
          utm={editing}
          isLive={(editing.click_count ?? 0) > 0}
          onClose={() => setEditing(null)}
          onSave={(input) => update.mutate({ id: editing.id, input })}
          saving={update.isPending}
          error={update.error ? (update.error as Error).message : null}
        />
      )}
    </Layout>
  );
}

function UtmRow({
  utm,
  archived,
  onEdit,
  onArchive,
  onRestore,
  onDelete,
}: {
  utm: Utm;
  archived: boolean;
  onEdit: () => void;
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

  const cost = utm.campaign_cost == null ? null : Number(utm.campaign_cost);

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
      <td className="px-4 py-3 text-right text-slate-700 tabular-nums">
        {utm.send_count ? utm.send_count.toLocaleString() : '—'}
      </td>
      <td className="px-4 py-3 text-right text-slate-700 tabular-nums">
        {cost != null && cost > 0 ? `$${cost.toFixed(2)}` : '—'}
      </td>
      <td className="px-4 py-3 text-right text-slate-700 tabular-nums">
        {utm.click_count ?? 0}
      </td>
      <td className="px-4 py-3 text-right text-slate-700 tabular-nums">{utm.links_count ?? 0}</td>
      <td className="px-4 py-3">
        <div className="flex items-center justify-end gap-1">
          <button
            type="button"
            onClick={onEdit}
            className="p-1.5 rounded hover:bg-slate-100 text-slate-500 hover:text-slate-700"
            aria-label="Edit UTM"
            title="Edit"
          >
            <Pencil className="w-4 h-4" />
          </button>
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

function EditUtmModal({
  utm,
  isLive,
  onClose,
  onSave,
  saving,
  error,
}: {
  utm: Utm;
  isLive: boolean;
  onClose: () => void;
  onSave: (input: UtmUpdateInput) => void;
  saving: boolean;
  error: string | null;
}) {
  const [name, setName] = useState(utm.name);
  const [sendCount, setSendCount] = useState<string>(
    utm.send_count != null ? String(utm.send_count) : '',
  );
  const [cost, setCost] = useState<string>(
    utm.campaign_cost != null ? String(Number(utm.campaign_cost).toFixed(2)) : '',
  );
  const [notes, setNotes] = useState(utm.notes ?? '');
  const [showAdvanced, setShowAdvanced] = useState(false);
  const [source, setSource] = useState(utm.utm_source);
  const [medium, setMedium] = useState(utm.utm_medium);
  const [campaign, setCampaign] = useState(utm.utm_campaign);
  const [baseUrl, setBaseUrl] = useState(utm.base_url);

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    const input: UtmUpdateInput = {
      name,
      send_count: sendCount === '' ? 0 : Number(sendCount),
      campaign_cost: cost === '' ? 0 : Number(cost),
      notes: notes || null,
    };
    if (showAdvanced) {
      input.utm_source = source;
      input.utm_medium = medium;
      input.utm_campaign = campaign;
      input.base_url = baseUrl;
    }
    onSave(input);
  }

  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center bg-slate-900/40 p-4 overflow-y-auto">
      <form
        onSubmit={handleSubmit}
        className="bg-white rounded-lg shadow-xl w-full max-w-lg my-8"
      >
        <div className="px-5 py-4 border-b border-slate-100">
          <h2 className="text-base font-semibold text-slate-900">Edit campaign</h2>
          <p className="text-xs text-slate-500 mt-0.5">{utm.name}</p>
        </div>

        <div className="px-5 py-4 space-y-4">
          {error && <Alert variant="error">{error}</Alert>}

          <Field label="Campaign name">
            <input
              type="text"
              value={name}
              onChange={(e) => setName(e.target.value)}
              className="w-full px-3 py-2 border border-slate-300 rounded text-sm"
              required
            />
          </Field>

          <div className="grid grid-cols-2 gap-3">
            <Field
              label="Reach (sent / impressions)"
              hint="People you sent this campaign to."
            >
              <input
                type="number"
                min={0}
                value={sendCount}
                onChange={(e) => setSendCount(e.target.value)}
                className="w-full px-3 py-2 border border-slate-300 rounded text-sm"
                placeholder="e.g. 5000"
              />
            </Field>
            <Field label="Cost (USD)" hint="Total spend for this campaign.">
              <input
                type="number"
                min={0}
                step="0.01"
                value={cost}
                onChange={(e) => setCost(e.target.value)}
                className="w-full px-3 py-2 border border-slate-300 rounded text-sm"
                placeholder="e.g. 75.00"
              />
            </Field>
          </div>

          <Field label="Notes" hint="Internal — not shown anywhere public.">
            <textarea
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              rows={2}
              className="w-full px-3 py-2 border border-slate-300 rounded text-sm"
            />
          </Field>

          <div className="border-t border-slate-100 pt-4">
            <button
              type="button"
              onClick={() => setShowAdvanced((v) => !v)}
              className="text-sm text-slate-600 hover:text-slate-900 underline-offset-2 hover:underline"
            >
              {showAdvanced ? 'Hide' : 'Show'} advanced (campaign details)
            </button>

            {showAdvanced && (
              <div className="mt-3 space-y-3">
                {isLive && (
                  <Alert variant="warning" className="text-xs">
                    This campaign already has clicks. Changing source / medium /
                    campaign will break attribution for clicks already in the system —
                    they'll keep their old tags but the URL on this row will change.
                    Only edit if you know what you're doing.
                  </Alert>
                )}

                <Field label="Destination URL">
                  <input
                    type="url"
                    value={baseUrl}
                    onChange={(e) => setBaseUrl(e.target.value)}
                    className="w-full px-3 py-2 border border-slate-300 rounded text-sm"
                  />
                </Field>
                <div className="grid grid-cols-3 gap-3">
                  <Field label="utm_source">
                    <input
                      type="text"
                      value={source}
                      onChange={(e) => setSource(e.target.value)}
                      className="w-full px-3 py-2 border border-slate-300 rounded text-sm"
                    />
                  </Field>
                  <Field label="utm_medium">
                    <input
                      type="text"
                      value={medium}
                      onChange={(e) => setMedium(e.target.value)}
                      className="w-full px-3 py-2 border border-slate-300 rounded text-sm"
                    />
                  </Field>
                  <Field label="utm_campaign">
                    <input
                      type="text"
                      value={campaign}
                      onChange={(e) => setCampaign(e.target.value)}
                      className="w-full px-3 py-2 border border-slate-300 rounded text-sm"
                    />
                  </Field>
                </div>
              </div>
            )}
          </div>
        </div>

        <div className="px-5 py-3 border-t border-slate-100 flex items-center justify-end gap-2">
          <Button variant="ghost" size="sm" type="button" onClick={onClose}>
            Cancel
          </Button>
          <Button variant="primary" size="sm" type="submit" disabled={saving}>
            {saving ? 'Saving…' : 'Save'}
          </Button>
        </div>
      </form>
    </div>
  );
}

function Field({
  label,
  hint,
  children,
}: {
  label: string;
  hint?: string;
  children: React.ReactNode;
}) {
  return (
    <label className="block">
      <span className="block text-xs font-medium text-slate-700 mb-1">{label}</span>
      {children}
      {hint && <span className="block text-xs text-slate-500 mt-1">{hint}</span>}
    </label>
  );
}
