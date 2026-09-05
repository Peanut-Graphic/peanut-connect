import './setup.mjs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';

import * as projects from '../src/projects.mjs';

/** A throwaway repo with a nested directory, to stand in for a real checkout. */
function fakeRepo() {
  const root = mkdtempSync(path.join(tmpdir(), 'dino-repo-'));
  mkdirSync(path.join(root, '.git'));
  mkdirSync(path.join(root, 'includes', 'deep'), { recursive: true });
  return root;
}

test('a subdirectory belongs to the repo above it, not to itself', () => {
  const root = fakeRepo();

  assert.equal(projects.rootFor(path.join(root, 'includes')), root);
  assert.equal(projects.rootFor(path.join(root, 'includes', 'deep')), root);
  assert.equal(projects.rootFor(root), root);

  // The thing that matters: one project, one colour, however deep you started.
  const a = projects.ensure(root);
  const b = projects.ensure(path.join(root, 'includes', 'deep'));
  assert.equal(a.id, b.id);
  assert.equal(a.color, b.color);
});

test('a worktree or submodule counts as the same project', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'dino-wt-'));
  // In a worktree, .git is a file pointing elsewhere rather than a directory.
  writeFileSync(path.join(root, '.git'), 'gitdir: /somewhere/else\n');
  mkdirSync(path.join(root, 'src'));

  assert.equal(projects.rootFor(path.join(root, 'src')), root);
});

test('a directory outside any repo is its own project', () => {
  const loose = mkdtempSync(path.join(tmpdir(), 'dino-loose-'));
  assert.equal(projects.rootFor(loose), loose);
  assert.equal(projects.ensure(loose).name, path.basename(loose));
});

test('work with no directory at all lands somewhere nameable', () => {
  assert.equal(projects.rootFor(''), projects.UNASSIGNED);
  assert.equal(projects.rootFor(undefined), projects.UNASSIGNED);
  assert.equal(projects.ensure('').name, 'Unassigned');
});

test('renaming and recolouring survive, and a bad colour is refused', () => {
  const root = fakeRepo();
  projects.ensure(root);

  projects.update(root, { name: 'Peanut Connect', color: 'violet' });
  assert.equal(projects.ensure(root).name, 'Peanut Connect');
  assert.equal(projects.ensure(root).color, 'violet');

  projects.update(root, { color: 'chartreuse' });
  assert.equal(projects.ensure(root).color, 'violet', 'an unknown colour is ignored');

  // A rename reached from a subdirectory must land on the same project.
  projects.update(path.join(root, 'includes'), { name: 'Renamed From Inside' });
  assert.equal(projects.ensure(root).name, 'Renamed From Inside');
});

test('the same directory always gets the same auto colour', () => {
  const root = fakeRepo();
  const first = projects.ensure(root).color;
  projects.reset();
  assert.equal(projects.ensure(root).color, first, 'colour is stable across restarts');
});
