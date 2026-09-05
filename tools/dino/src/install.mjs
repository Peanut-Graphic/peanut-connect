/**
 * install.mjs — wire the hooks into a Claude Code settings file.
 *
 * Written to be safe to run twice: our entries are recognisable by their
 * command string, so an install strips the old ones before adding the new. The
 * file is backed up first, because it is the user's own config and may hold
 * hooks that have nothing to do with this tool.
 */

import { readFile, writeFile, copyFile, mkdir } from 'node:fs/promises';
import path from 'node:path';
import os from 'node:os';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const CLI = path.resolve(HERE, '..', 'bin', 'dino.mjs');

/** Marker that identifies an entry as ours, for idempotent re-installs. */
const SIGNATURE = 'dino.mjs hook';

/**
 * Hook timeouts, in seconds. The quick events must never delay the agent
 * noticeably; `Stop` is long on purpose — that is the window being held open
 * for a click, and it needs to outlast the daemon's own gate.
 */
const EVENTS = [
  ['SessionStart', 5],
  ['UserPromptSubmit', 5],
  ['PreToolUse', 5],
  ['PostToolUse', 5],
  ['Notification', 5],
  ['SessionEnd', 5],
  ['Stop', 720],
];

// Deliberately absent: `SubagentStop`. It must never gate (see hook.mjs), and
// until it is confirmed whether Claude Code gives a subagent its own
// session_id or reuses its parent's, installing it risks a phantom session
// appearing in the window for every helper. The daemon handles the event
// defensively if you add it anyway — it records a line and returns.


function entryFor(event, timeout) {
  return {
    matcher: '*',
    hooks: [{ type: 'command', command: `node ${CLI} hook ${event}`, timeout }],
  };
}

/** Drop previously-installed dino entries, leaving every other hook untouched. */
function withoutOurs(existing = []) {
  return existing
    .map((group) => ({
      ...group,
      hooks: (group.hooks || []).filter(
        (h) => !String(h?.command || '').includes(SIGNATURE),
      ),
    }))
    .filter((group) => (group.hooks || []).length > 0);
}

export function settingsPath(scope) {
  return scope === 'project'
    ? path.resolve('.claude', 'settings.json')
    : path.join(os.homedir(), '.claude', 'settings.json');
}

/**
 * @param {'user'|'project'} scope
 * @returns {Promise<{file: string, backup: string|null}>}
 */
export async function install(scope = 'user') {
  const file = settingsPath(scope);
  await mkdir(path.dirname(file), { recursive: true });

  let settings = {};
  let backup = null;
  try {
    const raw = await readFile(file, 'utf8');
    settings = JSON.parse(raw);
    backup = `${file}.dino-backup`;
    await copyFile(file, backup);
  } catch (error) {
    // A missing file is the normal first-run case. A malformed one is not
    // something to silently overwrite — the user has config worth keeping.
    if (error.code !== 'ENOENT') {
      throw new Error(`Could not read ${file}: ${error.message}`);
    }
  }

  settings.hooks ||= {};
  for (const [event, timeout] of EVENTS) {
    settings.hooks[event] = [...withoutOurs(settings.hooks[event]), entryFor(event, timeout)];
  }

  await writeFile(file, `${JSON.stringify(settings, null, 2)}\n`);
  return { file, backup };
}

/** Remove every dino hook, leaving the rest of the file as it was. */
export async function uninstall(scope = 'user') {
  const file = settingsPath(scope);

  let settings;
  try {
    settings = JSON.parse(await readFile(file, 'utf8'));
  } catch (error) {
    if (error.code === 'ENOENT') return { file, removed: false };
    throw error;
  }

  if (!settings.hooks) return { file, removed: false };
  for (const event of Object.keys(settings.hooks)) {
    const kept = withoutOurs(settings.hooks[event]);
    if (kept.length > 0) settings.hooks[event] = kept;
    else delete settings.hooks[event];
  }

  await writeFile(file, `${JSON.stringify(settings, null, 2)}\n`);
  return { file, removed: true };
}
