/**
 * state.mjs — what every watched agent session is currently doing.
 *
 * Live sessions are in memory only: the daemon is a companion window, not a
 * record of anything, and the next hook event repopulates it after a restart.
 * The archive is the exception — work you fed to the dino is meant to still be
 * there tomorrow, so it goes through the store.
 */

import { EventEmitter } from 'node:events';
import path from 'node:path';

import * as projects from './projects.mjs';
import { load, save } from './store.mjs';

/** Ring-buffer size for the "what actually happened" drawer. */
const MAX_EVENTS = 200;

/** Sessions with no traffic for this long are dropped from the list. */
const STALE_AFTER_MS = 6 * 60 * 60 * 1000;

const ARCHIVE_FILE = 'archive.json';

/** Newest first. Bounded so the file cannot grow without limit. */
const MAX_ARCHIVED = 200;

/** @type {Map<string, Session>} */
const sessions = new Map();

/** @type {Array<object>} */
let archived = load(ARCHIVE_FILE, []);

/**
 * Recently archived ids, so a straggling event cannot resurrect work you just
 * fed to the dino. The window is short on purpose: archiving means "done with
 * this", not "ignore this session forever", so genuinely new activity later is
 * allowed to start a fresh card.
 *
 * @type {Map<string, number>}
 */
const tombstones = new Map();
const TOMBSTONE_MS = 8000;

/**
 * Sessions merged into another one. Stronger and longer-lived than a
 * tombstone: archiving means "I have filed this", but merging means "this
 * agent should stop duplicating work", so every turn it parks from now on is
 * released immediately instead of asking you again.
 *
 * @type {Set<string>}
 */
const dismissed = new Set();

export function isDismissed(id) {
  return dismissed.has(id);
}

function buried(id) {
  const at = tombstones.get(id);
  if (at === undefined) return false;
  if (Date.now() - at > TOMBSTONE_MS) {
    tombstones.delete(id);
    return false;
  }
  return true;
}

/** Emits 'change' whenever the UI would render differently. */
export const bus = new EventEmitter();
bus.setMaxListeners(0);

/**
 * @typedef {object} Session
 * @property {string} id
 * @property {string} label       friendly name — the project directory
 * @property {string} cwd
 * @property {'idle'|'working'|'waiting'|'asking'|'blocked'|'done'} state
 * @property {Array<{at: number, text: string, kind: string}>} events
 * @property {{headline: string, question: boolean}|null} summary
 * @property {number} updatedAt
 * @property {Gate|null} gate     an in-flight turn being held open for a click
 */

/**
 * @typedef {object} Gate
 * @property {(decision: object) => void} resolve
 * @property {NodeJS.Timeout} timer
 * @property {number} openedAt
 */

function blank(id, cwd = '') {
  return {
    id,
    cwd,
    // The title names this piece of work; the project names where it happens.
    // Together they read as "peanut-connect: auth token refresh". The title is
    // seeded from your first message and stays yours to change.
    title: '',
    state: 'idle',
    events: [],
    summary: null,
    lastActivity: null,
    startedAt: Date.now(),
    updatedAt: Date.now(),
    gate: null,
    // Something to tell this agent the next time it comes up for air —
    // currently only set by a merge.
    note: '',
  };
}

/** Attach the project's current name and colour for rendering. */
function decorate(session) {
  const project = projects.ensure(session.cwd);
  return {
    ...session,
    project: { id: project.id, name: project.name, color: project.color },
    title: session.title || 'untitled',
    label: `${project.name}: ${session.title || 'untitled'}`,
  };
}

function changed() {
  bus.emit('change');
}

/** Get a session, creating it on first sight. */
export function ensure(id, cwd) {
  let s = sessions.get(id);
  if (!s) {
    s = blank(id, cwd);
    sessions.set(id, s);
  }
  if (cwd && s.cwd !== cwd) {
    s.cwd = cwd;
    projects.ensure(cwd);
  }
  return s;
}

/** Drop a queued note once it has been delivered. */
export function clearNote(id) {
  const s = sessions.get(id);
  if (!s || !s.note) return;
  s.note = '';
  changed();
}

/**
 * Fold several agents into one, so they stop doing the same work twice.
 *
 * What this can and cannot do is worth being precise about. dino only ever
 * speaks to an agent when its turn is parked at the Stop gate — there is no
 * way to interrupt one mid-turn. So merging does two things it *can* do:
 * every absorbed agent is dismissed, meaning its next parked turn ends rather
 * than asking you whether to continue; and the one you keep gets a note
 * delivered the next time it parks, telling it what it is taking over.
 *
 * An absorbed agent still finishes whatever turn it is in the middle of. It
 * just will not be invited to start another.
 */
export function merge(keepId, absorbIds = []) {
  const keeper = sessions.get(keepId);
  if (!keeper) return null;

  const taken = [];
  for (const id of absorbIds) {
    if (id === keepId) continue;
    const s = sessions.get(id);
    if (!s) continue;
    taken.push(s.title || 'untitled');
    dismissed.add(id);
    archive(id, { mergedInto: keepId });
  }

  if (taken.length === 0) return { kept: decorate(keeper), absorbed: [] };

  const list = taken.map((t) => `"${t}"`).join(', ');
  keeper.note = `You are also taking over work that was running separately on `
    + `this project: ${list}. Check for overlap with what you have already done `
    + `before continuing, and do not redo it.`;
  keeper.updatedAt = Date.now();
  changed();

  return { kept: decorate(keeper), absorbed: taken };
}

/** Name this piece of work. Empty input clears it back to the default. */
export function retitle(id, title) {
  const s = sessions.get(id);
  if (!s) return null;
  s.title = String(title ?? '').trim().slice(0, 80);
  s.updatedAt = Date.now();
  changed();
  return decorate(s);
}

/**
 * Apply a patch and notify listeners.
 *
 * An absent or empty `cwd` never overwrites a directory we already know. Not
 * every hook event carries one — `Stop` in particular — and letting a blank
 * through would detach a live session from its project, so it would lose its
 * name and colour halfway through the work.
 */
export function update(id, patch) {
  if (buried(id)) return null;
  const s = ensure(id, patch.cwd);
  const { cwd, ...rest } = patch;
  Object.assign(s, rest, { updatedAt: Date.now() });
  if (cwd) s.cwd = cwd;
  changed();
  return s;
}

/** Append a narrated activity line. */
export function record(id, entry) {
  if (buried(id)) return null;
  const s = ensure(id);
  s.events.push({ at: Date.now(), ...entry });
  if (s.events.length > MAX_EVENTS) s.events.splice(0, s.events.length - MAX_EVENTS);
  s.lastActivity = entry;
  s.updatedAt = Date.now();
  changed();
  return s;
}

/**
 * Hold a finished turn open until someone clicks, or until `timeoutMs` passes.
 * Resolving with `null` means "nobody answered" — the caller lets the turn end
 * normally, which is the only safe default: a hung hook freezes the terminal.
 */
export function openGate(id, timeoutMs) {
  const s = ensure(id);
  closeGate(id, null); // a new turn supersedes any stale one

  return new Promise((resolve) => {
    const timer = setTimeout(() => closeGate(id, null), timeoutMs);
    // The daemon should not be held alive purely by a gate nobody is watching.
    if (typeof timer.unref === 'function') timer.unref();
    s.gate = { resolve, timer, openedAt: Date.now() };
    changed();
  });
}

/** Resolve an open gate with the user's decision (or null on timeout). */
export function closeGate(id, decision) {
  const s = sessions.get(id);
  if (!s?.gate) return false;
  const { resolve, timer } = s.gate;
  clearTimeout(timer);
  s.gate = null;
  resolve(decision);
  changed();
  return true;
}

/** True when a turn is currently parked waiting for a click. */
export function isWaiting(id) {
  return Boolean(sessions.get(id)?.gate);
}

function isStale(s) {
  return !s.gate && Date.now() - s.updatedAt > STALE_AFTER_MS;
}

/**
 * Feed a finished session to the dino: it leaves the live list and joins the
 * archive. Any turn still parked is released first — archiving something is a
 * clear "I am done with this", so leaving its agent blocked would be wrong.
 */
export function archive(id, extra = {}) {
  const s = sessions.get(id);
  if (!s) return null;

  // Tell the parked turn *why* it is being released, so its handler returns
  // without writing state — otherwise its state.update() would call ensure()
  // and resurrect the session we are archiving, straight back into Review.
  closeGate(id, { action: 'archived' });
  sessions.delete(id);
  tombstones.set(id, Date.now());

  const project = projects.ensure(s.cwd);
  const entry = {
    id: s.id,
    cwd: s.cwd,
    title: s.title || 'untitled',
    projectId: project.id,
    headline: s.summary?.headline || s.lastActivity?.text || '',
    steps: s.events.length,
    startedAt: s.startedAt,
    archivedAt: Date.now(),
    ...extra,
  };

  archived.unshift(entry);
  if (archived.length > MAX_ARCHIVED) archived.length = MAX_ARCHIVED;
  save(ARCHIVE_FILE, () => archived);

  changed();
  return entry;
}

/** The archive, newest first, decorated with each project's current colour. */
export function archiveList() {
  return archived.map((entry) => {
    const project = projects.ensure(entry.projectId || entry.cwd);
    return {
      ...entry,
      project: { id: project.id, name: project.name, color: project.color },
      label: `${project.name}: ${entry.title}`,
    };
  });
}

/**
 * The view the UI renders: anything needing a human first, then most recent.
 * Gates are stripped — they hold a live promise and must not be serialised.
 */
export function list() {
  for (const [id, s] of sessions) {
    if (isStale(s)) sessions.delete(id);
  }

  const needsYou = (s) => (s.gate ? 0 : 1);

  return [...sessions.values()]
    .sort((a, b) => needsYou(a) - needsYou(b) || b.updatedAt - a.updatedAt)
    .map((s) => {
      const { gate, ...rest } = decorate(s);
      return { ...rest, waiting: Boolean(s.gate) };
    });
}

/** Test seam — drop everything, disk included. */
export function reset() {
  for (const id of sessions.keys()) closeGate(id, null);
  sessions.clear();
  dismissed.clear();
  tombstones.clear();
  archived = [];
  save(ARCHIVE_FILE, () => archived);
}
