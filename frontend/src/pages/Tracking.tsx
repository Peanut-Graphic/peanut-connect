import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Layout } from '@/components/layout';
import { Card, CardHeader, Alert } from '@/components/common';
import { marketingApi } from '@/api';
import {
  buildTrackerSnippet,
  buildFormTrackSnippet,
  buildConversionSnippet,
} from '@/utils/trackingSnippets';
import { Copy, Check } from 'lucide-react';

export default function Tracking() {
  const { data, isLoading, error } = useQuery({
    queryKey: ['marketing', 'tracking-setup'],
    queryFn: () => marketingApi.trackingSetup(),
  });

  const trackerSnippet = useMemo(
    () => buildTrackerSnippet(data?.hub_url, data?.site_key),
    [data]
  );
  const formTrackSnippet = buildFormTrackSnippet();
  const conversionSnippet = buildConversionSnippet();

  return (
    <Layout
      title="Tracking Setup"
      description="Drop these two GTM tags onto your landing page and your conversion page. Cross-domain journeys stitch together via the click_id parameter the short links add for you."
    >
      {error && (
        <Alert variant="error" className="mb-4">
          {(error as Error).message}
        </Alert>
      )}

      {!isLoading && data && !data.connected && (
        <Alert variant="warning" className="mb-4">
          This site isn't connected to a Hub install yet. Configure the Hub URL and API key in Settings before
          using these snippets.
        </Alert>
      )}

      <div className="space-y-6">
        <Card>
          <CardHeader
            title="Site identity"
            description="The Hub install this WP site reports to and the masked API key."
          />
          <dl className="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
            <Field label="Hub URL" value={data?.hub_url || '—'} mono />
            <Field label="Site Key" value={data?.site_key_masked || '—'} mono />
            <Field label="Tracker script" value={data?.tracker_js || '—'} mono />
          </dl>
        </Card>

        <Card>
          <CardHeader
            title="Tag 1 — Load the tracker"
            description="GTM tag, fires on All Pages, priority 100. Replace the placeholder Site Key with the one from Hub > Sites > Your Site > Tracking."
          />
          <Snippet code={trackerSnippet} />
        </Card>

        <Card>
          <CardHeader
            title="Tag 2 — Track the enrollment form"
            description="GTM tag, fires on the enrollment page (DOM Ready). Auto-captures form start / field / abandon so you can see which step people drop out on. Tighten the 'form' selector to your form's id if the page has more than one form."
          />
          <Snippet code={formTrackSnippet} />
        </Card>

        <Card>
          <CardHeader
            title="Tag 3 — Fire enrollment + identity"
            description="GTM tag, fires on form submission or thank-you page load. enroll() records the conversion AND identifies the visitor by email (this is what powers per-account timelines). Wire {{Form Email}} / {{Form Name}} to GTM variables mapped to your form fields — without a real email the visitor stays anonymous."
          />
          <Snippet code={conversionSnippet} />
        </Card>

        <Card>
          <CardHeader title="Troubleshooting" />
          <ul className="text-sm text-slate-700 space-y-2 list-disc pl-5">
            <li>
              <strong>Conversion not appearing:</strong> open GTM Preview, confirm the conversion tag fires, and
              run <code>typeof phub</code> in the browser console — it should return <code>"function"</code>.
            </li>
            <li>
              <strong>Conversion fires but isn't linked to a campaign:</strong> verify <code>click_id</code> and
              the <code>utm_*</code> params are still on the URL when the page loads, and confirm the tracker tag
              has priority 100 so it loads before the conversion tag.
            </li>
            <li>
              <strong>Enrollment platform strips query params:</strong> ask the platform to whitelist the{' '}
              <code>click_id</code> parameter. The first-party <code>_pnut_click_id</code> cookie covers shared
              parent domains automatically.
            </li>
          </ul>
        </Card>
      </div>
    </Layout>
  );
}

function Field({ label, value, mono }: { label: string; value: string; mono?: boolean }) {
  return (
    <div>
      <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</dt>
      <dd className={mono ? 'mt-1 text-sm text-slate-900 font-mono break-all' : 'mt-1 text-sm text-slate-900'}>
        {value}
      </dd>
    </div>
  );
}

function Snippet({ code }: { code: string }) {
  const [copied, setCopied] = useState(false);

  async function copy() {
    try {
      await navigator.clipboard.writeText(code);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 1500);
    } catch {
      // Browser blocked clipboard.
    }
  }

  return (
    <div className="relative">
      <pre className="rounded-lg bg-slate-900 text-slate-100 p-4 text-xs overflow-x-auto">
        <code>{code}</code>
      </pre>
      <button
        type="button"
        onClick={copy}
        className="absolute top-2 right-2 inline-flex items-center gap-1 rounded bg-slate-800 hover:bg-slate-700 text-slate-100 px-2 py-1 text-xs"
        aria-label="Copy snippet"
      >
        {copied ? <Check className="w-3.5 h-3.5" /> : <Copy className="w-3.5 h-3.5" />}
        {copied ? 'Copied' : 'Copy'}
      </button>
    </div>
  );
}
