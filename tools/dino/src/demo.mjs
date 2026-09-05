/**
 * demo.mjs — drive the window with a scripted session.
 *
 * Exists so the skin can be looked at and argued about without wiring hooks
 * into a real agent first. It talks to the daemon over the same HTTP endpoint
 * the real hooks use, so if the demo looks right, the real thing will too.
 */

const script = [
  [0, { hook_event_name: 'SessionStart', cwd: '/Users/you/projects/peanut-connect' }],
  [600, { hook_event_name: 'UserPromptSubmit' }],
  [1400, { hook_event_name: 'PreToolUse', tool_name: 'Grep', tool_input: {} }],
  [1200, { hook_event_name: 'PreToolUse', tool_name: 'Read', tool_input: { file_path: '/p/includes/class-connect-auth.php' } }],
  [1600, { hook_event_name: 'PreToolUse', tool_name: 'Edit', tool_input: { file_path: '/p/includes/class-connect-auth.php' } }],
  [1500, { hook_event_name: 'PreToolUse', tool_name: 'Bash', tool_input: { command: 'composer run test' } }],
  [2200, { hook_event_name: 'PreToolUse', tool_name: 'Bash', tool_input: { command: 'git commit -m "fix auth"' } }],
];

/** Stands in for the transcript the real Stop hook would point at. */
const CLOSING = 'Fixed the token refresh so expired sessions reconnect instead '
  + 'of failing silently. All 84 tests pass. Want me to push this?';

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

export async function runDemo(port) {
  const sessionId = `demo-${Date.now()}`;
  const url = `http://127.0.0.1:${port}/api/event`;

  const send = (body) =>
    fetch(url, {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({ session_id: sessionId, ...body }),
    });

  for (const [delay, event] of script) {
    await sleep(delay);
    await send(event);
  }

  await sleep(900);

  // The real Stop hook blocks here until someone clicks; so does this one.
  // `demo_closing` is honoured by the daemon only in demo mode.
  const res = await send({ hook_event_name: 'Stop', demo_closing: CLOSING });
  const decision = await res.json();

  if (decision?.decision === 'block') {
    console.log(`\n  You clicked: "${decision.reason}"`);
    console.log('  A real agent would now carry on with that as its next instruction.\n');
  } else {
    console.log('\n  You ended the turn. A real agent would stop here.\n');
  }
}
