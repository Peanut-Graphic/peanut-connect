import './setup.mjs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ui = readFileSync(path.join(HERE, '..', 'ui', 'index.html'), 'utf8');

/**
 * Guards on the one rule the whole design rests on: the creature does not move.
 *
 * These read the stylesheet rather than a rendered page, because the package
 * has no dependencies and adding a browser to the test suite to check a
 * layout rule would be a poor trade. That makes them narrower than the real
 * property — they catch the specific mistakes that have actually been made
 * here, not every possible way to break it.
 */

function ruleFor(selector) {
  const match = ui.match(new RegExp(`\\n  \\${selector}\\s*\\{([^}]*)\\}`));
  assert.ok(match, `no rule found for ${selector}`);
  return match[1];
}

test('the stage is not vertically centred', () => {
  // Centring made the scene slide whenever the caption changed height — a
  // wrapping title, a tab with buttons, a question line appearing. Free space
  // belongs underneath the scene, never above and below it.
  const stage = ruleFor('.stage');
  assert.doesNotMatch(stage, /margin:\s*auto\s+0/, 'auto vertical margins re-centre the scene');
  assert.match(stage, /margin:[^;]*\bauto\b\s*;/, 'the bottom margin should still absorb the slack');
});

test('the scene keeps a fixed aspect, so its height never depends on content', () => {
  assert.match(ruleFor('.scene'), /aspect-ratio:\s*16\s*\/\s*10/);
});

test('the caption sits below the scene and cannot push it', () => {
  // `.caption` is a sibling *after* `.scene` in the markup. If it ever moves
  // above, everything below the toolbar shifts with the text again.
  const scene = ui.indexOf('class="scene"');
  const caption = ui.indexOf('class="caption"');
  assert.ok(scene !== -1 && caption !== -1);
  assert.ok(scene < caption, 'the scene must render before the caption');
});

test('the creature scales from a variable, and its face scales with it', () => {
  // Fixed-pixel eyes drift off the head as he grows on what he eats.
  const dino = ruleFor('.dino');
  assert.match(dino, /width:\s*var\(--dino-size/);
  const eyes = ui.match(/\.dino::before,\s*\n\s*\.dino::after\s*\{([^}]*)\}/);
  assert.ok(eyes, 'no eye rule found');
  assert.match(eyes[1], /top:\s*\d+%/, 'eye position must be proportional');
  assert.match(eyes[1], /width:\s*\d+%/, 'eye size must be proportional');
});
