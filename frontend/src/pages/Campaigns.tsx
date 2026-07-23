import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import QRCode from 'qrcode';
import { Layout } from '@/components/layout';
import { Card, CardHeader, Button, Input, Alert, InfoPanel } from '@/components/common';
import { useToast } from '@/components/common/Toast';
import {
  marketingApi,
  type CampaignBuildInput,
  type CampaignResult,
  type TrackingSetup,
} from '@/api';
import {
  buildTrackerSnippet,
  buildFormTrackSnippet,
  buildConversionSnippet,
} from '@/utils/trackingSnippets';
import { Copy, Check, ExternalLink, ArrowLeft, ArrowRight, RotateCcw, Download } from 'lucide-react';

type WizardStep = 0 | 1 | 2 | 3;

const STEPS: { label: string; description: string }[] = [
  { label: 'Campaign', description: 'What is it and where does it send people?' },
  { label: 'Short link', description: 'Memorable slug for your printed / QR / email link.' },
  { label: 'Tracking', description: 'Snippets to drop into GTM on the landing page.' },
  { label: 'Done', description: 'Your URLs and next steps.' },
];

interface WizardState {
  name: string;
  base_url: string;
  utm_source: string;
  utm_medium: string;
  utm_campaign: string;
  utm_content: string;
  utm_term: string;
  custom_slug: string;
  send_count: string;
  campaign_cost: string;
}

const INITIAL_STATE: WizardState = {
  name: '',
  base_url: '',
  utm_source: '',
  utm_medium: '',
  utm_campaign: '',
  utm_content: '',
  utm_term: '',
  custom_slug: '',
  send_count: '',
  campaign_cost: '',
};

const DRAFT_KEY = 'peanut-connect:campaign-wizard-draft';

interface DraftPayload {
  step: WizardStep;
  state: WizardState;
  savedAt?: number;
}

function loadDraft(): DraftPayload | null {
  try {
    const raw = window.localStorage.getItem(DRAFT_KEY);
    if (!raw) return null;
    const parsed = JSON.parse(raw) as Partial<DraftPayload>;
    if (
      parsed &&
      typeof parsed.step === 'number' &&
      parsed.state &&
      typeof parsed.state === 'object'
    ) {
      return {
        step: Math.min(parsed.step, 2) as WizardStep,
        state: { ...INITIAL_STATE, ...parsed.state },
        savedAt: typeof parsed.savedAt === 'number' ? parsed.savedAt : undefined,
      };
    }
  } catch {
    // ignore — bad JSON or storage disabled
  }
  return null;
}

function saveDraft(payload: DraftPayload) {
  try {
    window.localStorage.setItem(
      DRAFT_KEY,
      JSON.stringify({ ...payload, savedAt: payload.savedAt ?? Date.now() }),
    );
  } catch {
    // ignore — storage might be full or disabled
  }
}

function clearDraft() {
  try {
    window.localStorage.removeItem(DRAFT_KEY);
  } catch {
    // ignore
  }
}

function isDraftDirty(state: WizardState): boolean {
  return Object.values(state).some((v) => String(v).trim() !== '');
}

function timeSince(ts: number): string {
  const s = Math.max(0, Math.round((Date.now() - ts) / 1000));
  if (s < 5) return 'just now';
  if (s < 60) return `${s}s ago`;
  const m = Math.round(s / 60);
  if (m < 60) return `${m}m ago`;
  const h = Math.round(m / 60);
  return `${h}h ago`;
}

export default function Campaigns() {
  const initial = useMemo(() => loadDraft(), []);
  const [step, setStep] = useState<WizardStep>(initial?.step ?? 0);
  const [state, setState] = useState<WizardState>(initial?.state ?? INITIAL_STATE);
  const [result, setResult] = useState<CampaignResult | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [draftSavedAt, setDraftSavedAt] = useState<number | null>(
    initial?.savedAt ?? null,
  );
  const [draftRestoredBanner, setDraftRestoredBanner] = useState<boolean>(!!initial);
  const queryClient = useQueryClient();
  const toast = useToast();

  const { data: tracking } = useQuery({
    queryKey: ['marketing', 'tracking-setup'],
    queryFn: () => marketingApi.trackingSetup(),
  });

  const build = useMutation({
    mutationFn: (payload: CampaignBuildInput) => marketingApi.buildCampaign(payload),
    onSuccess: (campaign) => {
      setResult(campaign);
      setError(null);
      setStep(3);
      clearDraft();
      setDraftSavedAt(null);
      queryClient.invalidateQueries({ queryKey: ['marketing', 'utms'] });
      queryClient.invalidateQueries({ queryKey: ['marketing', 'links'] });
    },
    onError: (err: Error) => {
      setError(err.message || 'Could not build campaign.');
    },
  });

  // Auto-save draft as user types — but only while in steps 0–2 (the editable
  // ones) and only if there's any dirty data.
  useEffect(() => {
    if (step >= 3) return;
    if (!isDraftDirty(state)) {
      clearDraft();
      setDraftSavedAt(null);
      return;
    }
    const t = setTimeout(() => {
      saveDraft({ step, state });
      setDraftSavedAt(Date.now());
    }, 400);
    return () => clearTimeout(t);
  }, [state, step]);

  function update<K extends keyof WizardState>(key: K, value: WizardState[K]) {
    setState((prev) => ({ ...prev, [key]: value }));
    setDraftRestoredBanner(false);
  }

  function handleSubmit() {
    const payload: CampaignBuildInput = {
      name: state.name.trim(),
      base_url: state.base_url.trim(),
      utm_source: state.utm_source.trim().toLowerCase(),
      utm_medium: state.utm_medium.trim().toLowerCase(),
      utm_campaign: state.utm_campaign.trim(),
    };
    if (state.utm_content.trim()) payload.utm_content = state.utm_content.trim();
    if (state.utm_term.trim()) payload.utm_term = state.utm_term.trim();
    if (state.custom_slug.trim()) payload.custom_slug = state.custom_slug.trim();
    if (state.send_count.trim()) payload.send_count = Number(state.send_count);
    if (state.campaign_cost.trim()) payload.campaign_cost = Number(state.campaign_cost);

    build.mutate(payload);
  }

  function reset() {
    setState(INITIAL_STATE);
    setResult(null);
    setError(null);
    setStep(0);
    clearDraft();
    setDraftSavedAt(null);
    setDraftRestoredBanner(false);
  }

  function discardDraft() {
    if (!confirm('Discard this draft? You will lose anything you typed.')) return;
    reset();
  }

  function saveDraftToLocal() {
    if (isDraftDirty(state)) {
      saveDraft({ step, state });
      setDraftSavedAt(Date.now());
      toast.info(
        "Draft saved locally. Your form will restore next time. The campaign isn't submitted yet — finish all 4 steps to create it.",
        6000,
      );
    }
  }

  const step0Valid =
    state.name.trim() !== '' &&
    state.base_url.trim() !== '' &&
    state.utm_source.trim() !== '' &&
    state.utm_medium.trim() !== '' &&
    state.utm_campaign.trim() !== '';

  const stepReachable = (target: WizardStep): boolean => {
    if (target <= step) return true;
    // Forward navigation: prerequisites must hold for every step before target.
    if (target >= 1 && !step0Valid) return false;
    return true;
  };

  function gotoStep(target: WizardStep) {
    if (step === 3) return; // final state, use "Build another" to reset
    if (!stepReachable(target)) return;
    setStep(target);
  }

  return (
    <Layout
      title="Build a Campaign"
      description="UTM, short link, and GTM tracking — in four steps."
      action={
        step === 3 ? (
          <Button variant="ghost" icon={<RotateCcw className="w-4 h-4" />} onClick={reset}>
            Build another
          </Button>
        ) : (
          <div className="flex items-center gap-3">
            {draftSavedAt && (
              <span className="text-xs text-slate-500">
                Draft saved {timeSince(draftSavedAt)}
              </span>
            )}
            {isDraftDirty(state) && (
              <>
                <Button variant="ghost" size="sm" onClick={saveDraftToLocal}>
                  Save draft
                </Button>
                <button
                  type="button"
                  onClick={discardDraft}
                  className="text-xs text-slate-500 hover:text-red-600 underline-offset-2 hover:underline"
                >
                  Discard
                </button>
              </>
            )}
          </div>
        )
      }
    >
      <Stepper current={step} canReach={stepReachable} onStepClick={gotoStep} />

      {draftRestoredBanner && (
        <Alert variant="info" className="mb-4">
          Picked up where you left off.{' '}
          <button
            type="button"
            onClick={discardDraft}
            className="underline underline-offset-2 hover:no-underline ml-1"
          >
            Start fresh
          </button>
        </Alert>
      )}

      {error && (
        <Alert variant="error" className="mb-4">
          {error}
        </Alert>
      )}

      <div className="grid gap-6 lg:grid-cols-5">
        <div className="lg:col-span-3">
          {step === 0 && (
            <BasicsStep
              state={state}
              update={update}
              onNext={() => setStep(1)}
              valid={step0Valid}
            />
          )}
          {step === 1 && (
            <ShortLinkStep
              state={state}
              update={update}
              onBack={() => setStep(0)}
              onNext={() => setStep(2)}
            />
          )}
          {step === 2 && (
            <TrackingStep
              tracking={tracking}
              onBack={() => setStep(1)}
              onSubmit={handleSubmit}
              submitting={build.isPending}
            />
          )}
          {step === 3 && result && (
            <DoneStep result={result} tracking={tracking} onReset={reset} />
          )}
          {step === 3 && !result && (
            <Card>
              <CardHeader
                title="Campaign saved, but no payload returned"
                description="Hub didn't return the campaign object after creation. The link may still exist — check the Links tab to confirm, then build another."
              />
              <div className="flex items-center gap-3 pt-1">
                <Button variant="ghost" onClick={reset} icon={<RotateCcw className="w-4 h-4" />}>
                  Build another
                </Button>
              </div>
            </Card>
          )}
        </div>

        <aside className="lg:col-span-2">
          <SummaryCard state={state} step={step} result={result} />
        </aside>
      </div>
    </Layout>
  );
}

function Stepper({
  current,
  canReach,
  onStepClick,
}: {
  current: WizardStep;
  canReach: (target: WizardStep) => boolean;
  onStepClick: (target: WizardStep) => void;
}) {
  return (
    <ol className="flex items-center gap-2 mb-6 text-sm" role="list">
      {STEPS.map((s, idx) => {
        const target = idx as WizardStep;
        const isCurrent = idx === current;
        const isDone = idx < current;
        const reachable = canReach(target);
        const clickable = reachable && idx !== current;

        const circle = isDone
          ? 'bg-primary-600 text-white'
          : isCurrent
          ? 'bg-primary-100 text-primary-700 ring-2 ring-primary-500'
          : reachable
          ? 'bg-slate-100 text-slate-600'
          : 'bg-slate-100 text-slate-400';

        const labelCls = isCurrent
          ? 'font-medium text-slate-900 truncate'
          : reachable
          ? 'text-slate-600 truncate'
          : 'text-slate-400 truncate';

        return (
          <li key={s.label} className="flex items-center gap-2 flex-1 min-w-0">
            <button
              type="button"
              onClick={() => clickable && onStepClick(target)}
              disabled={!clickable}
              className={
                'flex items-center gap-2 min-w-0 rounded transition ' +
                (clickable
                  ? 'cursor-pointer hover:bg-slate-50 px-1 py-0.5 -mx-1 -my-0.5'
                  : 'cursor-default')
              }
              aria-current={isCurrent ? 'step' : undefined}
              aria-label={`${s.label}${reachable ? '' : ' (complete prior steps to unlock)'}`}
              title={reachable ? '' : 'Complete prior steps to unlock'}
            >
              <span
                className={`flex items-center justify-center w-7 h-7 rounded-full font-medium flex-shrink-0 ${circle}`}
              >
                {isDone ? <Check className="w-3.5 h-3.5" /> : idx + 1}
              </span>
              <span className={labelCls}>{s.label}</span>
            </button>
            {idx < STEPS.length - 1 && (
              <span className="flex-1 h-px bg-slate-200 mx-1" aria-hidden />
            )}
          </li>
        );
      })}
    </ol>
  );
}

function BasicsStep({
  state,
  update,
  onNext,
  valid,
}: {
  state: WizardState;
  update: <K extends keyof WizardState>(key: K, value: WizardState[K]) => void;
  onNext: () => void;
  valid: boolean;
}) {
  const [pastedUrl, setPastedUrl] = useState('');
  const [pasteError, setPasteError] = useState<string | null>(null);

  const handlePasteUrl = (value: string) => {
    setPastedUrl(value);
    const trimmed = value.trim();
    if (!trimmed) {
      setPasteError(null);
      return;
    }
    try {
      const url = new URL(trimmed);
      const params = url.searchParams;
      update('base_url', `${url.origin}${url.pathname}`);
      const source = params.get('utm_source');
      const medium = params.get('utm_medium');
      const campaign = params.get('utm_campaign');
      const content = params.get('utm_content');
      const term = params.get('utm_term');
      if (source !== null) update('utm_source', source);
      if (medium !== null) update('utm_medium', medium);
      if (campaign !== null) update('utm_campaign', campaign);
      if (content !== null) update('utm_content', content);
      if (term !== null) update('utm_term', term);
      if (campaign && state.name.trim() === '') update('name', campaign);
      setPasteError(null);
    } catch {
      setPasteError("That doesn't look like a valid URL.");
    }
  };

  return (
    <Card>
      <CardHeader title="Step 1 — Campaign basics" description={STEPS[0].description} />

      <InfoPanel variant="guide" title="New here? How this works" collapsible defaultOpen={false} className="mb-4">
        <p className="mb-2">
          You're building a <b>tracked link</b> for a campaign. Fill in the fields below, and the
          builder gives you a short link and a QR code to use on print, email, or social — every
          click and scan is counted in reporting.
        </p>
        <ul className="list-disc pl-5 space-y-1 text-sm">
          <li><b>Three fields are required:</b> Source, Medium, and Campaign. The rest are optional but make reports richer.</li>
          <li>Already have a full UTM link? Paste it in the first box and everything auto-fills.</li>
          <li>Hover the small hint under each field for what it means. Keep names consistent (same spelling every time).</li>
          <li>You'll get the short link and QR code on the last step ("Done").</li>
        </ul>
      </InfoPanel>

      <form
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault();
          if (valid) onNext();
        }}
      >
        <Input
          label="Already have a UTM URL? Paste it here"
          placeholder="https://example.com/page?utm_source=...&utm_medium=...&utm_campaign=..."
          type="text"
          value={pastedUrl}
          onChange={(e) => handlePasteUrl(e.target.value)}
          hint="Auto-fills the destination URL and all UTM fields below."
        />
        {pasteError && (
          <p className="text-sm text-red-600 -mt-2">{pasteError}</p>
        )}
        <Input
          label="Campaign name"
          placeholder="e.g. PTR Postcard 2024"
          required
          value={state.name}
          onChange={(e) => update('name', e.target.value)}
          hint="Human-readable label that shows up in reports."
        />
        <Input
          label="Destination URL"
          placeholder="https://example.com/landing"
          type="url"
          required
          value={state.base_url}
          onChange={(e) => update('base_url', e.target.value)}
          hint="Where the link should send people."
        />
        <div className="grid gap-4 sm:grid-cols-3">
          <Input
            label="Source"
            placeholder="mail"
            required
            value={state.utm_source}
            onChange={(e) => update('utm_source', e.target.value)}
            hint="Where the traffic came from — e.g. mail, facebook, postcard. Becomes utm_source."
          />
          <Input
            label="Medium"
            placeholder="postcard"
            required
            value={state.utm_medium}
            onChange={(e) => update('utm_medium', e.target.value)}
            hint="The kind of channel — e.g. postcard, email, social, cpc. Becomes utm_medium."
          />
          <Input
            label="Campaign"
            placeholder="ptr_postcard_2024"
            required
            value={state.utm_campaign}
            onChange={(e) => update('utm_campaign', e.target.value)}
            hint="Short identifier that groups this campaign together — e.g. ptr_postcard_2024. Becomes utm_campaign."
          />
        </div>
        <div className="grid gap-4 sm:grid-cols-2">
          <Input
            label="Content (optional)"
            placeholder="blue_design"
            value={state.utm_content}
            onChange={(e) => update('utm_content', e.target.value)}
            hint="Which version/creative, to tell two of the same link apart (A/B, different postcards). Becomes utm_content."
          />
          <Input
            label="Term (optional)"
            placeholder="agency tools"
            value={state.utm_term}
            onChange={(e) => update('utm_term', e.target.value)}
            hint="A keyword, mostly for paid search. Becomes utm_term."
          />
        </div>
        <div className="grid gap-4 sm:grid-cols-2 pt-2 border-t border-slate-100">
          <Input
            label="Reach (optional)"
            type="number"
            min={0}
            placeholder="5000"
            value={state.send_count}
            onChange={(e) => update('send_count', e.target.value)}
            hint="Email list size, ad impressions, etc. Powers the funnel."
          />
          <Input
            label="Cost (optional, USD)"
            type="number"
            min={0}
            step="0.01"
            placeholder="75.00"
            value={state.campaign_cost}
            onChange={(e) => update('campaign_cost', e.target.value)}
            hint="Total spend. Powers cost-per-acquisition."
          />
        </div>
        <div className="flex justify-end pt-2">
          <Button type="submit" disabled={!valid} icon={<ArrowRight className="w-4 h-4" />} iconPosition="right">
            Continue
          </Button>
        </div>
      </form>
    </Card>
  );
}

function ShortLinkStep({
  state,
  update,
  onBack,
  onNext,
}: {
  state: WizardState;
  update: <K extends keyof WizardState>(key: K, value: WizardState[K]) => void;
  onBack: () => void;
  onNext: () => void;
}) {
  return (
    <Card>
      <CardHeader title="Step 2 — Short link" description={STEPS[1].description} />
      <div className="space-y-4">
        <p className="text-sm text-slate-600">
          The short link is what goes on the postcard, QR code, or email — and it's what generates the per-click{' '}
          <code>click_id</code> token that stitches a visitor's journey across domains. Recommended for any printed
          or pasted-into-creative use.
        </p>
        <Input
          label="Custom slug (optional)"
          placeholder="ptr24"
          value={state.custom_slug}
          onChange={(e) => update('custom_slug', e.target.value)}
          hint="Memorable path. Leave empty to auto-generate a 6-character random slug."
        />
        <div className="rounded-md bg-slate-50 border border-slate-200 p-3 text-xs text-slate-600">
          <strong className="text-slate-800">Tip:</strong> a custom slug like <code>ptr24</code> turns into a link
          like <code>{`yourdomain.com/ptr24`}</code>. Keep it lowercase and short — it's going on a postcard.
        </div>

        <div className="flex justify-between pt-2">
          <Button variant="ghost" onClick={onBack} icon={<ArrowLeft className="w-4 h-4" />}>
            Back
          </Button>
          <Button onClick={onNext} icon={<ArrowRight className="w-4 h-4" />} iconPosition="right">
            Continue
          </Button>
        </div>
      </div>
    </Card>
  );
}

function TrackingStep({
  tracking,
  onBack,
  onSubmit,
  submitting,
}: {
  tracking: TrackingSetup | undefined;
  onBack: () => void;
  onSubmit: () => void;
  submitting: boolean;
}) {
  const trackerSnippet = useMemo(
    () => buildTrackerSnippet(tracking?.hub_url, tracking?.site_key),
    [tracking]
  );
  const formTrackSnippet = buildFormTrackSnippet();
  const conversionSnippet = buildConversionSnippet();

  return (
    <Card>
      <CardHeader title="Step 3 — Tracking" description={STEPS[2].description} />
      <div className="space-y-5">
        {tracking && !tracking.connected && (
          <Alert variant="warning">
            This site isn't connected to a Hub install yet. The campaign will still be created, but the tracker
            snippets below need a real Site Key — configure one in Settings before going live.
          </Alert>
        )}

        <div>
          <div className="text-sm font-medium text-slate-700 mb-2">
            Tag 1 — Tracker loader (every page, priority 100)
          </div>
          <Snippet code={trackerSnippet} />
        </div>

        <div>
          <div className="text-sm font-medium text-slate-700 mb-2">
            Tag 2 — Track the enrollment form (enrollment page, DOM Ready)
          </div>
          <Snippet code={formTrackSnippet} />
          <p className="text-xs text-slate-500 mt-1">
            Captures form start / field / abandon so you can see which step
            people drop out on. Tighten the <code>'form'</code> selector to
            your form's id if the page has more than one form.
          </p>
        </div>

        <div>
          <div className="text-sm font-medium text-slate-700 mb-2">
            Tag 3 — Enrollment + identity (thank-you page or form submit)
          </div>
          <Snippet code={conversionSnippet} />
          <p className="text-xs text-slate-500 mt-1">
            <code>enroll()</code> records the conversion <em>and</em> identifies
            the visitor by email — this is what powers per-person timelines.
            Wire <code>{'{{Form Email}}'}</code> / <code>{'{{Form Name}}'}</code>{' '}
            to GTM variables mapped to your form fields; without a real email
            the enrollee stays anonymous.
          </p>
        </div>

        <p className="text-xs text-slate-500">
          Already have these in GTM from a previous campaign on this site? You're done — they keep working for new
          campaigns automatically. The snippets only need to be installed once per landing-page domain.
        </p>

        <div className="flex justify-between pt-2">
          <Button variant="ghost" onClick={onBack} icon={<ArrowLeft className="w-4 h-4" />}>
            Back
          </Button>
          <Button onClick={onSubmit} disabled={submitting}>
            {submitting ? 'Building…' : 'Create campaign'}
          </Button>
        </div>
      </div>
    </Card>
  );
}

function DoneStep({
  result,
  tracking,
  onReset,
}: {
  result: CampaignResult;
  tracking: TrackingSetup | undefined;
  onReset: () => void;
}) {
  return (
    <Card>
      <CardHeader
        title={
          <span className="flex items-center gap-2">
            <Check className="w-5 h-5 text-emerald-500" />
            {result.name}
          </span>
        }
        description="Campaign created. Here's everything you need."
      />
      <div className="space-y-5">
        <CopyableField label="Short link (use this on print / QR / email)" value={result.short_url} openInNewTab />
        <CopyableField label="Full UTM URL (raw, in case you need it)" value={result.full_url} openInNewTab />

        <QrPanel url={result.short_url} filenameBase={result.link.slug} />

        <div className="rounded-md bg-slate-50 border border-slate-200 p-4 text-sm text-slate-700">
          <div className="font-medium text-slate-900 mb-2">Next steps</div>
          <ol className="list-decimal pl-5 space-y-1.5">
            <li>
              Click the short link in an incognito window — should redirect through to the destination with UTMs
              intact.
            </li>
            <li>
              Confirm the GTM tags are installed on the landing page
              {tracking?.hub_url ? ` (and reporting to ${new URL(tracking.hub_url).host})` : ''}.
              {' '}
              <Link
                to="/tracking"
                className="underline underline-offset-2 hover:no-underline text-amber-700"
              >
                Open Tracking tab
              </Link>
              {' '}for the copyable snippets.
            </li>
            <li>
              Watch for the journey to appear under <strong>Analytics</strong> within ~1 minute of the test click.
            </li>
            <li>Use the short link on the postcard / email / QR.</li>
          </ol>
        </div>

        <div className="flex items-center gap-3 pt-1">
          <Button variant="ghost" onClick={onReset} icon={<RotateCcw className="w-4 h-4" />}>
            Build another
          </Button>
        </div>
      </div>
    </Card>
  );
}

function QrPanel({ url, filenameBase }: { url: string; filenameBase: string }) {
  const [svg, setSvg] = useState<string | null>(null);
  const [pngDataUrl, setPngDataUrl] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    const opts = { errorCorrectionLevel: 'M' as const, margin: 1, width: 480 };

    Promise.all([
      QRCode.toString(url, { ...opts, type: 'svg' as const }),
      QRCode.toDataURL(url, { ...opts, type: 'image/png' as const }),
    ])
      .then(([svgString, dataUrl]) => {
        if (cancelled) return;
        setSvg(svgString);
        setPngDataUrl(dataUrl);
      })
      .catch((err: Error) => {
        if (cancelled) return;
        setError(err.message || 'Could not render QR code.');
      });

    return () => {
      cancelled = true;
    };
  }, [url]);

  function downloadSvg() {
    if (!svg) return;
    const blob = new Blob([svg], { type: 'image/svg+xml;charset=utf-8' });
    triggerDownload(URL.createObjectURL(blob), `${filenameBase}.svg`);
  }

  function downloadPng() {
    if (!pngDataUrl) return;
    triggerDownload(pngDataUrl, `${filenameBase}.png`);
  }

  if (error) {
    return (
      <div className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
        QR generation failed: {error}
      </div>
    );
  }

  return (
    <div className="rounded-md border border-slate-200 bg-white p-4">
      <div className="text-xs font-medium text-slate-500 mb-3 uppercase tracking-wide">QR code</div>
      <div className="flex flex-col sm:flex-row items-start gap-4">
        <div
          className="w-40 h-40 flex items-center justify-center border border-slate-200 rounded"
          aria-label={`QR code linking to ${url}`}
        >
          {svg ? (
            <span dangerouslySetInnerHTML={{ __html: svg }} className="block w-full h-full [&>svg]:w-full [&>svg]:h-full" />
          ) : (
            <span className="text-xs text-slate-400">Rendering…</span>
          )}
        </div>
        <div className="flex-1 space-y-2">
          <p className="text-sm text-slate-700">
            Encodes the short link. Print, paste into a postcard, or drop in a deck — readers scan and land on
            the tracked destination.
          </p>
          <div className="flex flex-wrap gap-2">
            <Button
              variant="outline"
              size="sm"
              onClick={downloadSvg}
              disabled={!svg}
              icon={<Download className="w-4 h-4" />}
            >
              Download SVG
            </Button>
            <Button
              variant="outline"
              size="sm"
              onClick={downloadPng}
              disabled={!pngDataUrl}
              icon={<Download className="w-4 h-4" />}
            >
              Download PNG
            </Button>
          </div>
          <p className="text-xs text-slate-500">SVG scales cleanly on print. PNG is friendlier for slide decks and email.</p>
        </div>
      </div>
    </div>
  );
}

function triggerDownload(href: string, filename: string) {
  const a = document.createElement('a');
  a.href = href;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  if (href.startsWith('blob:')) {
    setTimeout(() => URL.revokeObjectURL(href), 1000);
  }
}

function SummaryCard({
  state,
  step,
  result,
}: {
  state: WizardState;
  step: WizardStep;
  result: CampaignResult | null;
}) {
  if (step === 3 && result) {
    return (
      <Card>
        <CardHeader title="Campaign summary" />
        <dl className="text-sm space-y-2">
          <SummaryRow label="Name" value={result.name} />
          <SummaryRow label="Source" value={result.utm.utm_source} mono />
          <SummaryRow label="Medium" value={result.utm.utm_medium} mono />
          <SummaryRow label="Campaign" value={result.utm.utm_campaign} mono />
          {result.utm.utm_content && <SummaryRow label="Content" value={result.utm.utm_content} mono />}
          <SummaryRow label="Slug" value={`/${result.link.slug}`} mono />
        </dl>
      </Card>
    );
  }

  const filled = state.name || state.base_url || state.utm_source;
  const previewDescription =
    step === 3
      ? 'Campaign details (no result payload returned by Hub).'
      : step === 2
        ? 'Last review before submit.'
        : step === 1
          ? 'Short link + QR details on the next step.'
          : 'Updates as you fill in Step 1.';
  return (
    <Card>
      <CardHeader title="Preview" description={previewDescription} />
      {filled ? (
        <dl className="text-sm space-y-2">
          {state.name && <SummaryRow label="Name" value={state.name} />}
          {state.base_url && <SummaryRow label="Destination" value={state.base_url} mono />}
          {state.utm_source && <SummaryRow label="Source" value={state.utm_source} mono />}
          {state.utm_medium && <SummaryRow label="Medium" value={state.utm_medium} mono />}
          {state.utm_campaign && <SummaryRow label="Campaign" value={state.utm_campaign} mono />}
          {state.utm_content && <SummaryRow label="Content" value={state.utm_content} mono />}
          {state.utm_term && <SummaryRow label="Term" value={state.utm_term} mono />}
          {state.custom_slug && <SummaryRow label="Slug" value={`/${state.custom_slug}`} mono />}
        </dl>
      ) : (
        <p className="text-sm text-slate-500">
          Tip: keep names lowercase with underscores (<code>ptr_postcard_2024</code>) so reports filter cleanly.
        </p>
      )}
    </Card>
  );
}

function SummaryRow({ label, value, mono }: { label: string; value: string; mono?: boolean }) {
  return (
    <div className="flex items-baseline gap-3">
      <dt className="text-xs uppercase tracking-wide text-slate-500 w-24 flex-shrink-0">{label}</dt>
      <dd className={mono ? 'text-slate-900 font-mono break-all' : 'text-slate-900 break-words'}>{value}</dd>
    </div>
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

  useEffect(() => {
    if (!copied) return;
    const t = window.setTimeout(() => setCopied(false), 1500);
    return () => window.clearTimeout(t);
  }, [copied]);

  async function copy() {
    try {
      await navigator.clipboard.writeText(value);
      setCopied(true);
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

function Snippet({ code }: { code: string }) {
  const [copied, setCopied] = useState(false);

  useEffect(() => {
    if (!copied) return;
    const t = window.setTimeout(() => setCopied(false), 1500);
    return () => window.clearTimeout(t);
  }, [copied]);

  async function copy() {
    try {
      await navigator.clipboard.writeText(code);
      setCopied(true);
    } catch {
      // Clipboard write blocked.
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
