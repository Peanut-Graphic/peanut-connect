/**
 * The load-bearing test: a click in the window has to come back out of the
 * hook as an instruction the agent will act on. Everything else is cosmetics.
 */

import './setup.mjs';
import { test, after } from 'node:test';
import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { start } from '../src/server.mjs';
import * as state from '../src/state.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const CLI = path.join(HERE, '..', 'bin', 'dino.mjs');

const PORT = 4399;
const base = `http://127.0.0.1:${PORT}`;
const server = await start({ port: PORT, gateMs: 3000 });
after(() => server.close());

/** Run the real hook shim the way Claude Code runs it: JSON in, JSON out. */
function runHookProcess(event, payload) {
  return new Promise((resolve, reject) => {
    const child = execFile(
      process.execPath,
      [CLI, 'hook', event],
      { env: { ...process.env, DINO_PORT: String(PORT) } },
      (error, stdout) => (error ? reject(error) : resolve(stdout)),
    );
    child.stdin.end(JSON.stringify(payload));
  });
}

const post = (route, body) =>
  fetch(base + route, {
    method: 'POST',
    headers: { 'content-type': 'application/json' },
    body: JSON.stringify(body),
  }).then((r) => r.json());

const currentState = async () =>
  (await fetch(`${base}/api/state`).then((r) => r.json())).sessions;

test('activity arrives as English, not tool names', async () => {
  await post('/api/event', {
    session_id: 's1',
    hook_event_name: 'PreToolUse',
    cwd: '/home/you/peanut-connect',
    tool_name: 'Bash',
    tool_input: { command: 'composer run test' },
  });

  const [session] = await currentState();
  // The label composes the project with this piece of work's own title.
  assert.equal(session.project.name, 'peanut-connect');
  assert.equal(session.label, 'peanut-connect: untitled');
  assert.equal(session.state, 'working');
  assert.equal(session.lastActivity.text, 'Running the tests');
});

test('"keep going" comes back out of the hook as a block', async () => {
  const hook = runHookProcess('Stop', { session_id: 's1', demo_closing: 'Tests pass.' });

  // Wait for the turn to actually park before answering it.
  await waitFor(() => state.isWaiting('s1'));
  const [session] = await currentState();
  assert.equal(session.state, 'waiting');
  assert.equal(session.waiting, true);
  assert.equal(session.summary.headline, 'Tests pass.');

  await post('/api/decide', { session_id: 's1', action: 'go', reason: 'Keep going.' });

  const stdout = await hook;
  assert.deepEqual(JSON.parse(stdout), { decision: 'block', reason: 'Keep going.' });
});

test('a typed reply is what the agent gets told', async () => {
  const hook = runHookProcess('Stop', { session_id: 's2', demo_closing: 'Want me to push?' });
  await waitFor(() => state.isWaiting('s2'));

  await post('/api/decide', { session_id: 's2', action: 'go', reason: 'Push it, then open a PR.' });

  assert.deepEqual(JSON.parse(await hook), {
    decision: 'block',
    reason: 'Push it, then open a PR.',
  });
});

test('"all good" lets the turn end, printing nothing', async () => {
  const hook = runHookProcess('Stop', { session_id: 's3', demo_closing: 'Shipped.' });
  await waitFor(() => state.isWaiting('s3'));

  await post('/api/decide', { session_id: 's3', action: 'stop' });

  assert.equal(await hook, '');
});

test('an unanswered turn ends on its own rather than hanging', async () => {
  const hook = runHookProcess('Stop', { session_id: 's4', demo_closing: 'Done.' });
  assert.equal(await hook, ''); // gateMs is 3s for this server
  const session = (await currentState()).find((s) => s.id === 's4');
  assert.equal(session.state, 'done');
});

test('a question is flagged as needing you, not as finished', async () => {
  const hook = runHookProcess('Stop', {
    session_id: 's5',
    demo_closing: 'Tests pass.\n\nShould I bump the version?',
  });
  await waitFor(() => state.isWaiting('s5'));

  const session = (await currentState()).find((s) => s.id === 's5');
  assert.equal(session.state, 'asking');

  await post('/api/decide', { session_id: 's5', action: 'stop' });
  await hook;
});

test('sessions needing you sort above the ones that do not', async () => {
  const hook = runHookProcess('Stop', { session_id: 's6', demo_closing: 'Waiting.' });
  await waitFor(() => state.isWaiting('s6'));

  await post('/api/event', {
    session_id: 's7',
    hook_event_name: 'PreToolUse',
    tool_name: 'Read',
    tool_input: { file_path: '/x/y.php' },
  });

  const sessions = await currentState();
  assert.equal(sessions[0].id, 's6', 'the one holding a turn open should be first');

  await post('/api/decide', { session_id: 's6', action: 'stop' });
  await hook;
});

test('the hook stays silent when the daemon is not running', async () => {
  const stdout = await new Promise((resolve, reject) => {
    const child = execFile(
      process.execPath,
      [CLI, 'hook', 'Stop'],
      { env: { ...process.env, DINO_PORT: '4098' } }, // nothing is listening
      (error, out) => (error ? reject(error) : resolve(out)),
    );
    child.stdin.end(JSON.stringify({ session_id: 'nope' }));
  });

  assert.equal(stdout, '', 'a stopped daemon must not change how the agent behaves');
});

/** Poll until `predicate` holds, so tests never race the gate opening. */
async function waitFor(predicate, timeoutMs = 2000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    if (predicate()) return;
    await new Promise((r) => setTimeout(r, 20));
  }
  throw new Error('timed out waiting for condition');
}

test('a subagent finishing never parks the turn', async () => {
  const stdout = await runHookProcess('SubagentStop', {
    session_id: 's8',
    demo_closing: 'Found it in three files.',
  });

  assert.equal(stdout, '', 'a helper finishing must not ask the user anything');
  assert.equal(state.isWaiting('s8'), false, 'and must not open a gate');

  const session = (await currentState()).find((s) => s.id === 's8');
  assert.equal(session.lastActivity.text, 'A helper finished its digging');
});

test('a helper finishing cannot cancel the gate the real turn waits on', async () => {
  // The failure this guards: openGate() supersedes an open gate on the same
  // session, so a gating SubagentStop sharing its parent's session id would
  // end the parent's turn silently and make the click do nothing.
  const parent = runHookProcess('Stop', { session_id: 's9', demo_closing: 'Done the main work.' });
  await waitFor(() => state.isWaiting('s9'));

  await runHookProcess('SubagentStop', { session_id: 's9', demo_closing: 'Helper done.' });
  assert.equal(state.isWaiting('s9'), true, 'the parent should still be parked');

  await post('/api/decide', { session_id: 's9', action: 'go', reason: 'Keep going.' });
  assert.deepEqual(JSON.parse(await parent), { decision: 'block', reason: 'Keep going.' });
});

test('an event without a cwd does not detach a session from its project', async () => {
  await post('/api/event', {
    session_id: 's10',
    hook_event_name: 'SessionStart',
    cwd: '/home/you/peanut-connect',
  });

  // Stop carries no cwd. Before the fix this blanked it, and the session lost
  // its project — so its name and colour changed the moment it finished.
  const hook = runHookProcess('Stop', { session_id: 's10', demo_closing: 'Done.' });
  await waitFor(() => state.isWaiting('s10'));

  const session = (await currentState()).find((s) => s.id === 's10');
  assert.equal(session.project.name, 'peanut-connect');
  assert.notEqual(session.project.name, 'unknown');

  await post('/api/decide', { session_id: 's10', action: 'stop' });
  await hook;
});

test('feeding a session archives it and releases any parked turn', async () => {
  const hook = runHookProcess('Stop', {
    session_id: 's11',
    demo_closing: 'Shipped the thing.',
  });
  await waitFor(() => state.isWaiting('s11'));

  await post('/api/archive', { session_id: 's11' });

  // Archiving is an unambiguous "I am done with this", so the agent must not
  // be left blocked on a window that no longer shows it.
  assert.equal(await hook, '');
  const live = await currentState();
  assert.equal(live.some((s) => s.id === 's11'), false, 'gone from the live list');

  const archived = await fetch(`${base}/api/state`).then((r) => r.json());
  const entry = archived.archived.find((a) => a.id === 's11');
  assert.ok(entry, 'and present in the archive');
  assert.equal(entry.headline, 'Shipped the thing.');
});

test('merging folds duplicate agents into one and tells it what it took over', async () => {
  // Two agents in the same repo, which is how the same work gets done twice.
  for (const [id, title] of [['m1', 'fix the auth refresh'], ['m2', 'also fixing auth']]) {
    await post('/api/event', {
      session_id: id, hook_event_name: 'SessionStart', cwd: '/home/you/dupe-project',
    });
    await post('/api/title', { session_id: id, title });
  }

  const merged = await post('/api/merge', { keep_id: 'm1', absorb_ids: ['m2'] });
  assert.deepEqual(merged.absorbed, ['also fixing auth']);

  const live = await currentState();
  assert.equal(live.some((s) => s.id === 'm2'), false, 'the absorbed one is gone');

  const keeper = live.find((s) => s.id === 'm1');
  assert.match(keeper.note, /also fixing auth/);
  assert.match(keeper.note, /do not redo it/);
});

test('a merged-away agent is not asked to keep going again', async () => {
  // It cannot be interrupted mid-turn, but its next parked turn must end
  // rather than putting the same question back in front of you.
  const stdout = await runHookProcess('Stop', {
    session_id: 'm2',
    demo_closing: 'Finished the auth work.',
  });

  assert.equal(stdout, '', 'the turn ends instead of asking');
  assert.equal(state.isWaiting('m2'), false, 'and it never parks');
  const live = await currentState();
  assert.equal(live.some((s) => s.id === 'm2'), false, 'and it does not come back');
});

test('the merge note is what the kept agent actually gets told', async () => {
  const hook = runHookProcess('Stop', { session_id: 'm1', demo_closing: 'Where next?' });
  await waitFor(() => state.isWaiting('m1'));

  const parked = (await currentState()).find((s) => s.id === 'm1');
  // The window sends the note as the reason; this is that request.
  await post('/api/decide', { session_id: 'm1', action: 'go', reason: parked.note });

  const decision = JSON.parse(await hook);
  assert.match(decision.reason, /also fixing auth/);

  const after = (await currentState()).find((s) => s.id === 'm1');
  assert.equal(after.note, '', 'and it is not delivered twice');
});

test('an agent in a subdirectory counts toward the same project', async () => {
  // Two agents started at different depths of one repo must show up as two
  // agents in one project, or the merge prompt would never appear.
  await post('/api/event', {
    session_id: 'd1', hook_event_name: 'SessionStart', cwd: process.cwd(),
  });
  await post('/api/event', {
    session_id: 'd2', hook_event_name: 'SessionStart', cwd: `${process.cwd()}/tools/dino/src`,
  });

  const live = await currentState();
  const a = live.find((s) => s.id === 'd1');
  const b = live.find((s) => s.id === 'd2');
  assert.equal(a.project.id, b.project.id);
  assert.equal(a.project.color, b.project.color);
});
