# Mark It Up — Approval Process Design

**Date:** 2026-08-03
**Target version:** 3.33.0 (MINOR)
**Source:** Nat's mockup PDF ("State 1 (alive)") + brainstorming session decisions.

## Summary

Add a client approval workflow to the Mark It Up widget. Each page gets a row of
approver initials chips ("Click your initials to approve:"). Clicking your chip asks
"Is this approved?" YES/NO. YES turns the chip green; NO opens a "What needs to
change for approval?" box whose text becomes a regular Mark It Up note, and the chip
turns red. Hovering a voted chip shows the approver name and the date/time of the
latest action. All votes, re-votes, and resets are recorded per page and can be
pulled up in the widget's All-pages view and on the admin screen.

## Decisions (locked during brainstorming)

| Question | Decision |
|---|---|
| Approver identity | **Honor system.** Admin-defined list of `{name, initials}`; no WP accounts. Anyone with review access can click any chip; the acting browser's `author_key` is recorded for traceability. |
| Unit of approval | **Per page** (normalized path, same keying as notes), rolled up in the All-pages view. |
| Storage | **WP options now, HUB later.** Site-local via the plugin's own REST routes; payload shapes designed so a later HUB sync is a relay swap, not a rewrite. |
| NO reason text | **Becomes a real Mark It Up note** on that page (prefixed with the approver's name), so it flows to HUB with other notes; also linked from the approval record. |
| Vote lifecycle | **Re-vote + admin reset.** Clicking your chip again re-asks YES/NO; agency/admin can reset a page or the whole site. Every action is a timestamped history entry — **re-approval date/time is always logged**. |
| Code layout | **New module** `includes/class-connect-approvals.php` (storage + REST + admin section). Widget UI extends the existing shadow-root panel. |

## Architecture

### New module: `includes/class-connect-approvals.php`

Owns everything server-side. Boots only when the feedback module boots (site is
Hub-connected). Follows the feedback class's patterns: constants for option names,
pure static helpers for decision/sanitization logic (unit-testable via the
standalone mock bootstrap), thin wrappers that touch live WP state.

**Options:**

- `peanut_connect_approvers` — ordered array of approvers:
  `[{ id: string, name: string, initials: string }]`. `id` is a stable slug
  generated at creation (not the initials — initials can be edited or collide).
- `peanut_connect_approvals` — map keyed by normalized page path:

```
path => {
  votes: {                    // latest state per approver — what the chips render
    <approver_id>: {
      vote: 'yes' | 'no',
      at: '2026-08-03 14:12:09',   // UTC, gmdate('Y-m-d H:i:s')
      author_key: string,          // acting browser
      reason: string,              // '' unless vote = 'no'
      note_id: int|null            // the Mark It Up note created from a NO reason
    }
  },
  history: [                  // append-only, newest last, capped at 200/page
    { approver_id, action: 'yes'|'no'|'reset', at, author_key, reason? }
  ]
}
```

Missing/corrupt option data always normalizes to empty state. Path normalization
reuses the same rules the widget uses for notes (so approvals and notes agree on
what "a page" is).

**Pure helpers (exact seams the tests target):**

- `sanitize_approvers($raw): array` — validates/normalizes the admin-submitted list.
- `record_vote(array $state, string $approver_id, string $vote, string $reason, string $author_key, string $at): array` — returns new state; appends history; caps history at 200.
- `apply_reset(array $state, ?string $approver_id, string $author_key, string $at): array` — clears votes (all, or one approver's), appends a `reset` history entry.
- `normalize_path($raw): string`.

### REST routes (same namespace/permission model as feedback)

- `GET  /peanut-connect/v1/approvals?path=…` — state for one page (approvers + votes).
- `GET  /peanut-connect/v1/approvals` — all recorded pages (for All-pages rollup).
- `POST /peanut-connect/v1/approvals/vote` — `{ path, approver_id, vote, reason?, author_key }`. Requires the same review access the widget already enforces (`can_review`); honors access modes; `off` blocks everything.
- `POST /peanut-connect/v1/approvals/reset` — `{ path? , approver_id? }` (omit path = whole site). **Agency-only** (`can_review_agency` semantics).

When a NO vote carries a reason, the server posts the reason as a regular Mark It
Up note through the existing feedback relay (`"NH — needs changes: …"` body,
same author fields the widget would send) and stores the resulting `note_id`
on the vote. If the note relay fails, the vote still records (reason kept on the
record) — a note is a courtesy copy, not a dependency.

### Widget UI (in the existing shadow root)

- **Approvals strip** at the top of the panel: "Click your initials to approve:" +
  one chip per approver. Blue = no vote, green = yes, red = no. Hover (tap on
  touch) shows `Name — Approved/Needs changes · <local date/time>` from `at`.
- **Click flow:** chip → inline "Is this approved?" YES / NO (re-click re-asks;
  flipping red→green after fixes is one tap). NO → "What needs to change for
  approval?" textarea with **submit** (posts vote + note) and **edit** (cancels
  back so they can use the drawing/note tools instead and vote later).
- **All-pages view:** each listed page gains its chip row, so site-wide sign-off
  state is visible at a glance.
- **Resizable panel:** a drag handle lets the panel be enlarged; size persists in
  `localStorage` (`ppFeedbackPanelSize`).
- Failed saves use the widget's existing error styling and leave the chip in its
  prior state.
- If `assets/js/feedback.js` would cross its ~900-line ceiling, the approvals UI
  ships as `assets/js/approvals.js` loaded into the same shadow root; otherwise it
  stays in the single file.

### Admin page (existing Mark It Up screen)

New **Approvers** section:

- Add / remove / reorder approvers (name + initials; initials auto-suggested from
  the name, editable).
- **Reset approvals** control: per page (dropdown of recorded pages) or whole site,
  with confirmation.
- Same form conventions as the rest of the page: nonce-gated, `manage_options`,
  `esc_html`/`esc_attr`/`checked()` on all output, text domain `peanut-connect`,
  no emoji (inline SVG only if iconography is needed).

## Constraints

- No HUB changes this round. No schema migrations — options only.
- Widget stays dependency-free vanilla JS in the shadow root; no native dialogs.
- Never regress the green unit suite; new pure helpers get standalone-mock tests.
- All PHP passes `php -l`; release packaged only via `scripts/package.sh` on a clean tree.
- Branch: `feat/mark-it-up-approvals-3.33.0` off `main`.

## Degradation

- No approvers configured → the approvals strip does not render at all.
- Access mode `off` → no widget, no approvals (existing behavior).
- REST failure → error toast, no state change client-side.

## Testing

- Unit (standalone mocks): `sanitize_approvers`, `record_vote` (yes/no/re-vote,
  history append + timestamping, 200-entry cap), `apply_reset` (single approver,
  whole page, history entry), `normalize_path`, corrupt-option normalization.
- Manual on https://staging.cenhudpeakperks.com: chip flow (yes, no + note, re-vote),
  hover dates, All-pages rollup, resize persistence, admin add/remove/reset,
  token-only access, `off` mode.
