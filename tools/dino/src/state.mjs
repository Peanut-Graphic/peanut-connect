/**
 * state.mjs — what every watched agent session is currently doing.
 *
 * Deliberately in-memory only. The daemon is a companion window, not a record
 * of anything; if it restarts, the next hook event repopulates it. Persisting
 * this would mean owning stale sessions forever for no benefit.
 */

import { EventEmitter } from 'node:events';
import path from 'node:path';

/** Ring-buffer size for the "what actually happened" drawer. */
const MAX_EVENTS = 200;

/** Sessions with no traffic for this long are dropped from the list. */
const STALE_AFTER_MS = 6 * 60 * 60 * 1000;

/** @type {Map<string, Session>} */
const sessions = new Map();

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
    label: cwd ? path.basename(cwd) : 'session',
    cwd,
    state: 'idle',
    events: [],
    summary: null,
    lastActivity: null,
    updatedAt: Date.now(),
    gate: null,
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
    s.label = path.basename(cwd) || s.label;
  }
  return s;
}

/** Apply a patch and notify listeners. */
export function update(id, patch) {
  const s = ensure(id, patch.cwd);
  Object.assign(s, patch, { updatedAt: Date.now() });
  changed();
  return s;
}

/** Append a narrated activity line. */
export function record(id, entry) {
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
    .map(({ gate, ...rest }) => ({ ...rest, waiting: Boolean(gate) }));
}

/** Test seam — drop everything. */
export function reset() {
  for (const id of sessions.keys()) closeGate(id, null);
  sessions.clear();
}
