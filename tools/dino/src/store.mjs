/**
 * store.mjs — the small amount of disk this tool is allowed to touch.
 *
 * Everything about a *running* session stays in memory: it is rebuilt from the
 * next hook event and persisting it would mean owning stale sessions forever.
 * Two things genuinely outlive the daemon, though, and are the whole point of
 * the projects toolbar:
 *
 *   projects.json — the colour and name you assigned to each project
 *   archive.json  — the work you fed to the dino
 *
 * A colour that reset on restart would be useless for recognising past work,
 * and an archive that emptied would not be an archive.
 */

import { readFileSync, writeFileSync, mkdirSync, renameSync } from 'node:fs';
import path from 'node:path';
import os from 'node:os';

/** Overridable so tests never touch the real one. */
export function home() {
  return process.env.DINO_HOME || path.join(os.homedir(), '.dino');
}

/**
 * Read a JSON file, falling back to `fallback` for anything that goes wrong.
 * A corrupt or unreadable store should cost you your colours, never the window.
 */
export function load(name, fallback) {
  try {
    return JSON.parse(readFileSync(path.join(home(), name), 'utf8'));
  } catch {
    return fallback;
  }
}

/** Pending writes, keyed by file, so a burst of edits costs one write. */
const timers = new Map();
const WRITE_DELAY_MS = 250;

function writeNow(name, getValue) {
  try {
    const dir = home();
    mkdirSync(dir, { recursive: true });
    // Write-then-rename so a crash mid-write cannot leave a truncated file
    // where a valid one used to be.
    const target = path.join(dir, name);
    const temp = `${target}.tmp`;
    writeFileSync(temp, `${JSON.stringify(getValue(), null, 2)}\n`);
    renameSync(temp, target);
  } catch {
    // Losing a colour assignment is not worth taking the daemon down for.
  }
}

/**
 * Queue a debounced write. `getValue` is called at write time, so the value
 * saved is always the newest one rather than whichever edit started the timer.
 */
export function save(name, getValue) {
  clearTimeout(timers.get(name));
  const timer = setTimeout(() => {
    timers.delete(name);
    writeNow(name, getValue);
  }, WRITE_DELAY_MS);
  if (typeof timer.unref === 'function') timer.unref();
  timers.set(name, timer);
}

/** Force any queued writes to disk — for tests and for a clean shutdown. */
export function flush(name, getValue) {
  clearTimeout(timers.get(name));
  timers.delete(name);
  writeNow(name, getValue);
}
