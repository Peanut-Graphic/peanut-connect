/**
 * In-app step-by-step guide for the Campaign Link Builder. Shared between the
 * Help page (full admin) and the builder wizard's help panel (the scoped
 * "UTM Builder" role only ever sees the wizard, so the guide must live there
 * too). Content mirrors the printable guide sent to campaign partners.
 */

const FIELDS: { field: string; code?: string; meaning: string }[] = [
  { field: 'Campaign name', meaning: 'A human-readable label that shows up in reports. Example: “PTR Postcard 2024”.' },
  { field: 'Destination URL', meaning: 'Where the link should send people — usually the enrollment or landing page.' },
  { field: 'Source', code: 'utm_source', meaning: 'Where the traffic came from. Example: mail, facebook, postcard.' },
  { field: 'Medium', code: 'utm_medium', meaning: 'The kind of channel. Example: postcard, email, social, cpc.' },
  { field: 'Campaign', code: 'utm_campaign', meaning: 'Short identifier that groups this campaign together. Example: ptr_postcard_2024.' },
  { field: 'Content', code: 'utm_content', meaning: 'Optional. Which version or creative — tells two of the same link apart (A/B, two designs).' },
  { field: 'Term', code: 'utm_term', meaning: 'Optional. A keyword, mostly for paid search.' },
  { field: 'Reach', meaning: 'Optional. Email list size or ad impressions — powers the conversion funnel.' },
  { field: 'Cost', meaning: 'Optional. Total spend — powers cost-per-enrollment.' },
];

function Step({ n, title, children }: { n: number; title: string; children: React.ReactNode }) {
  return (
    <li className="flex gap-3">
      <span className="flex-shrink-0 w-6 h-6 rounded-full bg-slate-700 text-white text-xs font-bold flex items-center justify-center">
        {n}
      </span>
      <div className="text-sm text-slate-600">
        <span className="font-semibold text-slate-800">{title}</span>
        <div className="mt-0.5">{children}</div>
      </div>
    </li>
  );
}

function H({ children }: { children: React.ReactNode }) {
  return <h3 className="text-sm font-semibold text-slate-900 mt-5 mb-2">{children}</h3>;
}

export default function CampaignBuilderGuide({ showLogin = true }: { showLogin?: boolean }) {
  return (
    <div className="text-sm text-slate-600 leading-relaxed">
      <p>
        The Campaign Link Builder turns any campaign into a <b>short link</b> and a <b>QR code</b> that
        report every click and scan — in about two minutes. Here's how, start to finish.
      </p>

      {showLogin && (
        <>
          <H>Logging in</H>
          <ol className="space-y-2.5">
            <Step n={1} title="Open the sign-in page">
              Go to <span className="font-semibold text-slate-800">www.dominionenergyptr.com/itron-login</span> and
              sign in with the username and password you were given.
            </Step>
            <Step n={2} title="Open the builder">
              In the left-hand menu, click <b>End-to-End</b>. The <b>Build a Campaign</b> wizard opens — that's the
              only screen you'll need.
            </Step>
          </ol>
        </>
      )}

      <H>Step 1 — Describe the campaign</H>
      <p>
        Fill in the fields. <b>Three are required</b> — Source, Medium, and Campaign — the rest are optional but make
        your reports richer. Keep names consistent (same spelling every time) so a campaign groups together cleanly.
      </p>
      <div className="mt-3 overflow-x-auto rounded-lg border border-slate-200">
        <table className="w-full text-xs">
          <tbody className="divide-y divide-slate-100">
            {FIELDS.map((f) => (
              <tr key={f.field} className="align-top">
                <td className="w-40 bg-slate-50 px-3 py-2 font-semibold text-slate-800 whitespace-nowrap">
                  {f.field}
                  {f.code && <span className="block font-mono font-normal text-slate-400">{f.code}</span>}
                </td>
                <td className="px-3 py-2 text-slate-600">{f.meaning}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <p className="mt-2 rounded-md bg-slate-50 border border-slate-200 px-3 py-2 text-xs">
        <b className="text-slate-800">Shortcut:</b> already have a full UTM link? Paste it into the box at the top of
        Step 1 and every field fills in automatically. Then click <b>Continue</b>.
      </p>

      <H>Making a click-to-call link?</H>
      <p>
        If your campaign should <b>dial a phone number</b> instead of opening a web page, check{' '}
        <b>“This is a click-to-call link”</b> in Step 1. The <b>Destination URL</b> field becomes a{' '}
        <b>Phone number</b> field — enter the number with its country code (e.g. <span className="font-mono text-xs">+1 555 123 4567</span>).
      </p>
      <ul className="mt-1.5 list-disc pl-5 space-y-1 text-slate-600">
        <li>You still get a short link and QR code — tapping or scanning it dials the number.</li>
        <li><b>Taps and scans are still counted</b>, so you'll see the click totals in reporting.</li>
        <li>
          There's no on-site journey (a phone call has nothing to land on), and Step 3 (Tracking) has nothing to
          install for a call link.
        </li>
      </ul>

      <H>Step 2 — Get your short link</H>
      <p>
        The builder <b>automatically creates a short link</b> (a random 6-character code). Prefer a memorable one?
        Type it into <b>Custom slug</b>:
      </p>
      <ul className="mt-1.5 list-disc pl-5 space-y-1 text-slate-600">
        <li>Leave <b>Custom slug</b> empty to auto-generate.</li>
        <li>
          Or type your own — <span className="font-mono text-xs">ptr24</span> becomes{' '}
          <span className="font-mono text-xs">dominionenergyptr.com/ptr24</span>. Keep it lowercase and short; it's
          going on a postcard.
        </li>
      </ul>
      <p className="mt-1.5">Click <b>Continue</b>.</p>

      <H>Step 3 — Tracking (nothing to do)</H>
      <p>
        This step shows tracking code for the website. <b>It's already installed</b> (and a click-to-call link has
        nothing to install), so you don't need to touch anything here — just click <b>Create campaign</b> to save.
      </p>

      <H>Step 4 — Grab your link and QR code</H>
      <ol className="space-y-2.5">
        <Step n={1} title="Copy the short link">
          Under <b>Short link (use this on print / QR / email)</b>, click the copy button.
        </Step>
        <Step n={2} title="Download the QR code">
          In the <b>QR code</b> box, choose <b>Download PNG</b> (best for slides and email) or <b>Download SVG</b>{' '}
          (best for print — it scales cleanly at any size).
        </Step>
        <Step n={3} title="Test it once">
          Open the short link in a private / incognito window — it should redirect to your destination. Scan the QR
          code with your phone to confirm it goes to the same place.
        </Step>
      </ol>

      <H>Put it to work</H>
      <p>
        Drop the <b>short link</b> into your email, ad, or social post, and place the <b>QR code</b> on your printed
        materials. From that point on, every click and scan is counted, and the journeys they produce show up in
        reporting automatically. To build another, click <b>Build another</b> and repeat.
      </p>
      <p className="mt-2 rounded-md bg-slate-50 border border-slate-200 px-3 py-2 text-xs">
        <b className="text-slate-800">Why tag everything:</b> only links made here carry the tracking that follows a
        customer from the click all the way to a completed enrollment. The more of your campaigns use these short
        links and QR codes, the more of the picture your reports will show.
      </p>
    </div>
  );
}
