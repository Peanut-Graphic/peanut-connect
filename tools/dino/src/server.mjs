/**
 * server.mjs — the local daemon behind the window.
 *
 * Three jobs:
 *   1. take hook events from running agents and narrate them into state
 *   2. serve the UI and stream state to it
 *   3. hold a finished turn open until someone clicks, then answer the hook
 *
 * Deliberately dependency-free and bound to loopback. It sees transcript paths
 * and file names, so it is not something to expose on a network.
 */

import http from 'node:http';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import * as state from './state.mjs';
import * as projects from './projects.mjs';
import { narrateTool, summarize } from './narrate.mjs';
import { lastAssistantMessage } from './transcript.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const UI_FILE = path.join(HERE, '..', 'ui', 'index.html');

export const DEFAULT_PORT = 4317;

/** How long a finished turn waits for a click before it just ends. */
const DEFAULT_GATE_MS = 10 * 60 * 1000;

/** Refuse absurd request bodies rather than buffering them. */
const MAX_BODY_BYTES = 1024 * 1024;

function json(res, status, body) {
  const payload = JSON.stringify(body);
  res.writeHead(status, {
    'content-type': 'application/json',
    'content-length': Buffer.byteLength(payload),
    'cache-control': 'no-store',
  });
  res.end(payload);
}

async function readBody(req) {
  const chunks = [];
  let size = 0;
  for await (const chunk of req) {
    size += chunk.length;
    if (size > MAX_BODY_BYTES) throw new Error('request body too large');
    chunks.push(chunk);
  }
  if (size === 0) return {};
  return JSON.parse(Buffer.concat(chunks).toString('utf8'));
}

/**
 * Fold one hook event into session state.
 *
 * Returns a decision object for events that gate the agent (currently only
 * `Stop`), or null for the fire-and-forget ones.
 */
async function handleEvent(event, gateMs) {
  const id = event.session_id || 'unknown';
  const cwd = event.cwd || '';
  const kind = event.hook_event_name || event.event;

  switch (kind) {
    case 'SessionStart':
      state.update(id, { cwd, state: 'idle', summary: null, events: [] });
      return null;

    case 'UserPromptSubmit': {
      const session = state.ensure(id, cwd);
      // Seed the title from what you asked for — a name you can recognise
      // beats "untitled", and it stays yours to change in the window.
      if (!session.title && typeof event.prompt === 'string') {
        state.retitle(id, event.prompt.replace(/\s+/g, ' ').trim().slice(0, 80));
      }
      state.update(id, { cwd, state: 'working', summary: null });
      state.record(id, { text: 'Picking up your message', kind: 'look' });
      return null;
    }

    case 'PreToolUse': {
      state.update(id, { cwd, state: 'working' });
      state.record(id, narrateTool(event.tool_name, event.tool_input || {}));
      return null;
    }

    case 'Notification': {
      // Claude Code sends these when it wants permission or has gone quiet.
      // Either way the honest headline is "it can't move without you".
      const text = String(event.message || 'It needs you');
      state.update(id, {
        cwd,
        state: 'blocked',
        summary: { headline: text, question: true },
      });
      return null;
    }

    case 'SubagentStop':
      // A helper finishing is something to *report*, never something to ask
      // about: you should not have to click "keep going" once per subagent the
      // agent spawns. It must not gate for a second reason too — openGate()
      // supersedes any open gate on the same session, so if subagents share
      // their parent's session id, a helper finishing would silently cancel
      // the gate the real turn is parked on and the click would do nothing.
      state.record(id, { text: 'A helper finished its digging', kind: 'look' });
      return null;

    case 'Stop': {
      // Merged into another agent: let every turn end quietly from here on,
      // rather than asking again about work you already folded away.
      if (state.isDismissed(id)) return {};

      // `demo_closing` lets `dino demo` stand in for a transcript. It is only
      // ever a display string, and the daemon is loopback-only.
      const closing = event.demo_closing
        || (await lastAssistantMessage(event.transcript_path));
      const summary = summarize(closing);
      state.update(id, {
        cwd,
        summary,
        state: summary.question ? 'asking' : 'waiting',
      });

      const decision = await state.openGate(id, gateMs);

      // Fed to the dino while the turn was parked. The session is gone and
      // must stay gone, so let the turn end without touching state.
      if (decision?.action === 'archived') return {};

      if (!decision || decision.action === 'stop') {
        // Nobody answered, or they said it's finished. Let the turn end.
        state.update(id, { state: 'done' });
        return {};
      }

      state.update(id, { state: 'working', summary: null });
      state.record(id, { text: 'You said: keep going', kind: 'you' });
      return { decision: 'block', reason: decision.reason };
    }

    case 'SessionEnd':
      state.update(id, { cwd, state: 'done' });
      return null;

    default:
      return null;
  }
}

function streamState(res) {
  res.writeHead(200, {
    'content-type': 'text/event-stream',
    'cache-control': 'no-store',
    connection: 'keep-alive',
  });

  const send = () => res.write(`data: ${JSON.stringify({
    sessions: state.list(),
    archived: state.archiveList(),
    projects: projects.list(),
    palette: projects.PALETTE,
  })}\n\n`);
  send();

  state.bus.on('change', send);
  // Proxies and sleeping laptops drop idle connections; a comment frame is the
  // cheapest way to notice and let the browser reconnect.
  const heartbeat = setInterval(() => res.write(': ping\n\n'), 20_000);

  const cleanup = () => {
    clearInterval(heartbeat);
    state.bus.off('change', send);
  };
  res.on('close', cleanup);
  res.on('error', cleanup);
}

/**
 * @param {{gateMs?: number}} options
 */
export function createServer({ gateMs = DEFAULT_GATE_MS } = {}) {
  return http.createServer(async (req, res) => {
    const url = new URL(req.url, 'http://localhost');

    try {
      if (req.method === 'GET' && (url.pathname === '/' || url.pathname === '/index.html')) {
        const html = await readFile(UI_FILE);
        res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' });
        return res.end(html);
      }

      if (req.method === 'GET' && url.pathname === '/api/state') {
        return json(res, 200, {
          sessions: state.list(),
          archived: state.archiveList(),
          projects: projects.list(),
          palette: projects.PALETTE,
        });
      }

      if (req.method === 'POST' && url.pathname === '/api/project') {
        const { id: projectId, name, color } = await readBody(req);
        return json(res, 200, { project: projects.update(projectId, { name, color }) });
      }

      if (req.method === 'POST' && url.pathname === '/api/title') {
        const { session_id: sessionId, title } = await readBody(req);
        const session = state.retitle(sessionId, title);
        return json(res, session ? 200 : 404, { session });
      }

      if (req.method === 'POST' && url.pathname === '/api/merge') {
        const { keep_id: keepId, absorb_ids: absorbIds } = await readBody(req);
        const result = state.merge(keepId, absorbIds);
        return json(res, result ? 200 : 404, result || {});
      }

      if (req.method === 'POST' && url.pathname === '/api/archive') {
        const { session_id: sessionId } = await readBody(req);
        const entry = state.archive(sessionId);
        return json(res, entry ? 200 : 404, { archived: entry });
      }

      if (req.method === 'GET' && url.pathname === '/api/stream') {
        return streamState(res);
      }

      if (req.method === 'GET' && url.pathname === '/api/health') {
        return json(res, 200, { ok: true, pid: process.pid });
      }

      if (req.method === 'POST' && url.pathname === '/api/event') {
        const event = await readBody(req);
        const decision = await handleEvent(event, gateMs);
        return json(res, 200, decision ?? {});
      }

      if (req.method === 'POST' && url.pathname === '/api/decide') {
        const { session_id: sessionId, action, reason } = await readBody(req);
        const answered = state.closeGate(sessionId, {
          action,
          reason: reason || 'Keep going.',
        });
        // Whatever you decided, any queued note has now had its chance.
        if (answered) state.clearNote(sessionId);
        return json(res, answered ? 200 : 409, { answered });
      }

      return json(res, 404, { error: 'not found' });
    } catch (error) {
      // A broken request must never take the daemon down: an agent is blocked
      // on it, and a dead daemon means a frozen terminal.
      return json(res, 400, { error: String(error?.message || error) });
    }
  });
}

export function start({ port = DEFAULT_PORT, gateMs } = {}) {
  const server = createServer({ gateMs });
  return new Promise((resolve, reject) => {
    server.once('error', reject);
    server.listen(port, '127.0.0.1', () => resolve(server));
  });
}
