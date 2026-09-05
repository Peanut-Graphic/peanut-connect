/**
 * hook.mjs — the shim Claude Code runs on every event.
 *
 * The governing rule here is fail open. This process sits between the agent and
 * the user's terminal; if the daemon is stopped, slow, or broken, the correct
 * behaviour is to exit silently and let the agent carry on as if the window did
 * not exist. Nothing in this file should ever be able to wedge a session.
 */

import { DEFAULT_PORT } from './server.mjs';

/** Fire-and-forget events: the agent is mid-flight and must not wait on us. */
const QUICK_TIMEOUT_MS = 1500;

/** `Stop` parks the turn on purpose, so it gets the daemon's full gate window. */
const GATE_TIMEOUT_MS = 11 * 60 * 1000;

/**
 * Only the top-level turn parks. Deliberately not `SubagentStop`: you should
 * not have to answer for every helper the agent spawns, and a subagent that
 * shares its parent's session id would cancel the gate the real turn is
 * waiting on.
 */
const GATING_EVENTS = new Set(['Stop']);

function port() {
  return Number(process.env.DINO_PORT) || DEFAULT_PORT;
}

async function readStdin() {
  const chunks = [];
  for await (const chunk of process.stdin) chunks.push(chunk);
  const raw = Buffer.concat(chunks).toString('utf8').trim();
  if (!raw) return {};
  try {
    return JSON.parse(raw);
  } catch {
    return {};
  }
}

/**
 * Run one hook event. Prints the agent's control response on stdout when the
 * user asked to keep going, and nothing at all otherwise.
 *
 * @param {string} eventName the hook name, passed on the command line
 */
export async function runHook(eventName) {
  const payload = await readStdin();
  const event = { ...payload, hook_event_name: payload.hook_event_name || eventName };

  const gating = GATING_EVENTS.has(event.hook_event_name);
  const controller = new AbortController();
  const timer = setTimeout(
    () => controller.abort(),
    gating ? GATE_TIMEOUT_MS : QUICK_TIMEOUT_MS,
  );

  try {
    const res = await fetch(`http://127.0.0.1:${port()}/api/event`, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify(event),
      signal: controller.signal,
    });

    if (!gating || !res.ok) return;

    const decision = await res.json();
    // Only a `block` means anything to the agent. Everything else — including
    // the empty object we send when the window timed out — lets the turn end.
    if (decision?.decision === 'block' && decision.reason) {
      process.stdout.write(JSON.stringify({
        decision: 'block',
        reason: String(decision.reason),
      }));
    }
  } catch {
    // Daemon down, unreachable, or slow. Silence is the whole contract.
  } finally {
    clearTimeout(timer);
  }
}
