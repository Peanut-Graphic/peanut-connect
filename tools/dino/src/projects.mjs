/**
 * projects.mjs — the colour and name you gave each project.
 *
 * A project is a working directory. Colour is deliberately per-project rather
 * than per-agent: the point is to recognise past work at a glance, and that
 * only works if green means the same repo today as it did last week. An agent
 * inherits its project's colour; it does not get one of its own.
 */

import path from 'node:path';
import { existsSync, realpathSync } from 'node:fs';

import { load, save, flush } from './store.mjs';

const FILE = 'projects.json';

/**
 * Eight hues that stay distinguishable on the dark ground and against each
 * other. Kept small on purpose — a palette you can hold in your head is what
 * makes the colour mean something.
 */
export const PALETTE = [
  { id: 'green', hex: '#4ade80' },
  { id: 'violet', hex: '#a78bfa' },
  { id: 'amber', hex: '#fbbf24' },
  { id: 'cyan', hex: '#22d3ee' },
  { id: 'rose', hex: '#fb7185' },
  { id: 'lime', hex: '#a3e635' },
  { id: 'orange', hex: '#fb923c' },
  { id: 'blue', hex: '#60a5fa' },
];

const DEFAULT_COLOR = PALETTE[0].id;

/** Work with no directory at all has to live somewhere nameable. */
export const UNASSIGNED = 'unassigned';

/** Resolving a directory means walking it, so remember the answers. */
const roots = new Map();

/**
 * The project a directory belongs to: the nearest ancestor holding a `.git`,
 * or the directory itself if there is none.
 *
 * Without this, an agent started in `repo/includes` would register as a
 * different project from one started in `repo` — same code, two colours, two
 * rows in the panel — which defeats the point of colour meaning project.
 *
 * `.git` is checked with existsSync rather than isDirectory because in a
 * worktree or a submodule it is a *file* pointing elsewhere, and those are
 * still the same project.
 *
 * Symlinks are resolved first. `~/Documents/Peanut` pointing at `~/peanut` is
 * an ordinary way to arrange a machine, and without this an agent started
 * through one spelling would be a different project from one started through
 * the other — same directory, two names, two colours.
 */
export function rootFor(cwd) {
  if (!cwd) return UNASSIGNED;

  const cached = roots.get(cwd);
  if (cached) return cached;

  // realpath throws for a path that does not exist, which is fine and expected
  // in tests and for a directory that has since been removed; the lexical path
  // is the right answer then.
  let dir;
  try {
    dir = realpathSync(path.resolve(cwd));
  } catch {
    dir = path.resolve(cwd);
  }

  let found = dir;
  // Bounded so a pathological path cannot spin, and stop at the filesystem root.
  for (let i = 0; i < 64; i += 1) {
    if (existsSync(path.join(dir, '.git'))) {
      found = dir;
      break;
    }
    const parent = path.dirname(dir);
    if (parent === dir) break;
    dir = parent;
  }

  roots.set(cwd, found);
  return found;
}

/** @type {Record<string, {id: string, name: string, color: string}>} */
let projects = load(FILE, {});

function persist() {
  save(FILE, () => projects);
}

/**
 * Spread directories across the palette without asking. A stable hash means a
 * project keeps its colour across restarts even before anyone picks one, so
 * the default is already useful and the modal is for correcting it.
 */
function autoColor(id) {
  let hash = 0;
  for (let i = 0; i < id.length; i += 1) {
    hash = (hash * 31 + id.charCodeAt(i)) >>> 0;
  }
  return PALETTE[hash % PALETTE.length].id;
}

/**
 * The project for a working directory, registering it on first sight.
 * Any directory inside a repo resolves to that repo.
 */
export function ensure(cwd) {
  const id = rootFor(cwd);
  if (!projects[id]) {
    projects[id] = {
      id,
      name: id === UNASSIGNED ? 'Unassigned' : (path.basename(id) || id),
      color: autoColor(id),
    };
    persist();
  }
  return projects[id];
}

/** Rename or recolour a project. Unknown colours fall back rather than throw. */
export function update(id, { name, color }) {
  const project = ensure(id);
  if (typeof name === 'string' && name.trim()) project.name = name.trim().slice(0, 60);
  if (color && PALETTE.some((c) => c.id === color)) project.color = color;
  persist();
  return project;
}

export function list() {
  return Object.values(projects).sort((a, b) => a.name.localeCompare(b.name));
}

export function hex(colorId) {
  return (PALETTE.find((c) => c.id === colorId) || PALETTE[0]).hex;
}

/** Test seam. */
export function reset() {
  projects = {};
  roots.clear();
  flush(FILE, () => projects);
}
