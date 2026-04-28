import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Layout } from '@/components/layout';
import { Card, CardHeader, Button, Input, Alert } from '@/components/common';
import { marketingApi, type CampaignBuildInput, type CampaignResult } from '@/api';
import { Copy, Check, ExternalLink } from 'lucide-react';

const initialInput: CampaignBuildInput = {
  name: '',
  base_url: '',
  utm_source: '',
  utm_medium: '',
  utm_campaign: '',
  utm_content: '',
  utm_term: '',
  custom_slug: '',
};

export default function Campaigns() {
  const [input, setInput] = useState<CampaignBuildInput>(initialInput);
  const [result, setResult] = useState<CampaignResult | null>(null);
  const [error, setError] = useState<string | null>(null);
  const queryClient = useQueryClient();

  const buildMutation = useMutation({
    mutationFn: (payload: CampaignBuildInput) => marketingApi.buildCampaign(payload),
    onSuccess: (campaign) => {
      setResult(campaign);
      setError(null);
      queryClient.invalidateQueries({ queryKey: ['marketing', 'utms'] });
      queryClient.invalidateQueries({ queryKey: ['marketing', 'links'] });
    },
    onError: (err: Error) => {
      setError(err.message || 'Could not build campaign.');
      setResult(null);
    },
  });

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    const payload: CampaignBuildInput = {
      name: input.name.trim(),
      base_url: input.base_url.trim(),
      utm_source: input.utm_source.trim().toLowerCase(),
      utm_medium: input.utm_medium.trim().toLowerCase(),
      utm_campaign: input.utm_campaign.trim(),
    };
    if (input.utm_content?.trim()) payload.utm_content = input.utm_content.trim();
    if (input.utm_term?.trim()) payload.utm_term = input.utm_term.trim();
    if (input.custom_slug?.trim()) payload.custom_slug = input.custom_slug.trim();

    buildMutation.mutate(payload);
  }

  function reset() {
    setInput(initialInput);
    setResult(null);
    setError(null);
  }

  function update<K extends keyof CampaignBuildInput>(key: K, value: CampaignBuildInput[K]) {
    setInput((prev) => ({ ...prev, [key]: value }));
  }

  return (
    <Layout
      title="Campaigns"
      description="Build a tracked campaign — UTM, short link, and QR code in one step."
    >
      <div className="grid gap-6 lg:grid-cols-5">
        <div className="lg:col-span-3">
          <Card>
            <CardHeader
              title="Build a Campaign"
              description="Fill in where the link will appear and what you're promoting."
            />
            <form onSubmit={handleSubmit} className="space-y-4 mt-2">
              <Input
                label="Campaign name"
                placeholder="e.g. PTR Postcard 2024"
                required
                value={input.name}
                onChange={(e) => update('name', e.target.value)}
                hint="Human-readable label for reports."
              />
              <Input
                label="Destination URL"
                placeholder="https://example.com/landing"
                type="url"
                required
                value={input.base_url}
                onChange={(e) => update('base_url', e.target.value)}
                hint="Where the link should send people."
              />
              <div className="grid gap-4 sm:grid-cols-3">
                <Input
                  label="Source"
                  placeholder="mail"
                  required
                  value={input.utm_source}
                  onChange={(e) => update('utm_source', e.target.value)}
                  hint="Where it appears."
                />
                <Input
                  label="Medium"
                  placeholder="postcard"
                  required
                  value={input.utm_medium}
                  onChange={(e) => update('utm_medium', e.target.value)}
                  hint="Channel type."
                />
                <Input
                  label="Campaign"
                  placeholder="ptr_postcard_2024"
                  required
                  value={input.utm_campaign}
                  onChange={(e) => update('utm_campaign', e.target.value)}
                  hint="Identifier."
                />
              </div>
              <div className="grid gap-4 sm:grid-cols-3">
                <Input
                  label="Content (optional)"
                  placeholder="blue_design"
                  value={input.utm_content ?? ''}
                  onChange={(e) => update('utm_content', e.target.value)}
                  hint="Variant or creative."
                />
                <Input
                  label="Term (optional)"
                  placeholder="agency tools"
                  value={input.utm_term ?? ''}
                  onChange={(e) => update('utm_term', e.target.value)}
                  hint="Keyword, if any."
                />
                <Input
                  label="Custom slug (optional)"
                  placeholder="ptr24"
                  value={input.custom_slug ?? ''}
                  onChange={(e) => update('custom_slug', e.target.value)}
                  hint="Memorable short-link path."
                />
              </div>

              {error && <Alert variant="error">{error}</Alert>}

              <div className="flex items-center gap-3 pt-2">
                <Button type="submit" disabled={buildMutation.isPending}>
                  {buildMutation.isPending ? 'Building…' : 'Create Campaign'}
                </Button>
                <Button type="button" variant="ghost" onClick={reset}>
                  Reset
                </Button>
              </div>
            </form>
          </Card>
        </div>

        <div className="lg:col-span-2">
          <ResultPanel result={result} />
        </div>
      </div>
    </Layout>
  );
}

function ResultPanel({ result }: { result: CampaignResult | null }) {
  if (!result) {
    return (
      <Card>
        <CardHeader
          title="Result"
          description="Your short link, full UTM URL, and QR will appear here after you create the campaign."
        />
        <p className="text-sm text-slate-500 mt-2">
          Use consistent lowercase names with underscores (e.g. <code>ptr_email_spring</code>) so reports filter cleanly.
        </p>
      </Card>
    );
  }

  return (
    <Card>
      <CardHeader title={result.name} description="Campaign created." />
      <div className="space-y-4 mt-2">
        <CopyableField label="Short link" value={result.short_url} openInNewTab />
        <CopyableField label="Full UTM URL" value={result.full_url} openInNewTab />
        <p className="text-xs text-slate-500">
          Slug <code>{result.link.slug}</code> · Source <code>{result.utm.utm_source}</code> · Medium{' '}
          <code>{result.utm.utm_medium}</code>
        </p>
      </div>
    </Card>
  );
}

function CopyableField({
  label,
  value,
  openInNewTab,
}: {
  label: string;
  value: string;
  openInNewTab?: boolean;
}) {
  const [copied, setCopied] = useState(false);

  async function copy() {
    try {
      await navigator.clipboard.writeText(value);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 1500);
    } catch {
      // Clipboard write blocked; user can select manually.
    }
  }

  return (
    <div>
      <div className="text-xs font-medium text-slate-500 mb-1">{label}</div>
      <div className="flex items-stretch gap-2">
        <input
          readOnly
          value={value}
          className="flex-1 min-w-0 rounded border border-slate-300 px-2 py-1.5 text-sm font-mono text-slate-800 bg-slate-50"
        />
        <button
          type="button"
          onClick={copy}
          className="inline-flex items-center gap-1 rounded border border-slate-300 px-2 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-100"
          aria-label={`Copy ${label}`}
        >
          {copied ? <Check className="w-3.5 h-3.5" /> : <Copy className="w-3.5 h-3.5" />}
          {copied ? 'Copied' : 'Copy'}
        </button>
        {openInNewTab && (
          <a
            href={value}
            target="_blank"
            rel="noreferrer noopener"
            className="inline-flex items-center gap-1 rounded border border-slate-300 px-2 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-100"
            aria-label={`Open ${label} in new tab`}
          >
            <ExternalLink className="w-3.5 h-3.5" />
            Open
          </a>
        )}
      </div>
    </div>
  );
}
