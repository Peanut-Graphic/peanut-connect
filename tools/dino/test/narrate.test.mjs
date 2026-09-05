import './setup.mjs';
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { narrateTool, summarize } from '../src/narrate.mjs';

test('file tools name the file, not the path', () => {
  assert.equal(
    narrateTool('Edit', { file_path: '/a/b/includes/class-connect-auth.php' }).text,
    'Editing class-connect-auth.php',
  );
  assert.equal(narrateTool('Read', { file_path: '/a/b/readme.txt' }).text, 'Reading readme.txt');
});

test('file tools survive a missing path', () => {
  assert.equal(narrateTool('Write', {}).text, 'Writing a file');
});

test('shell commands are matched most-specific-first', () => {
  const say = (command) => narrateTool('Bash', { command }).text;
  assert.equal(say('git commit -m "x"'), 'Committing changes');
  assert.equal(say('git status --short'), 'Checking on the code');
  assert.equal(say('composer run test'), 'Running the tests');
  assert.equal(say('npm run build'), 'Building the project');
  assert.equal(say('rg "foo" includes/'), 'Searching the codebase');
});

test('an unrecognised command falls back to the agent’s own description', () => {
  assert.equal(
    narrateTool('Bash', { command: 'wp-env start', description: 'Start the WordPress sandbox' }).text,
    'Start the WordPress sandbox',
  );
  assert.equal(narrateTool('Bash', { command: 'wp-env start' }).text, 'Running a command');
});

test('a heredoc cannot smuggle a second line into the label', () => {
  const text = narrateTool('Bash', { command: "cat <<'EOF' > f\ngit push --force\nEOF" }).text;
  assert.equal(text, 'Looking through files');
});

test('mcp tools are named by their service', () => {
  assert.equal(narrateTool('mcp__github__create_pull_request', {}).text, 'Working with github');
});

test('summarize keeps the first two sentences', () => {
  const { headline } = summarize(
    'Fixed the token refresh. All 84 tests pass. I also tidied the logger, renamed two helpers, and updated the changelog.',
  );
  assert.equal(headline, 'Fixed the token refresh. All 84 tests pass.');
});

test('summarize strips markdown that reads as noise', () => {
  const { headline } = summarize('## Done\n\nUpdated `class-auth.php` and **all** tests pass.');
  assert.equal(headline, 'Done Updated class-auth.php and all tests pass.');
});

test('a trailing question means you are needed, not that it is finished', () => {
  assert.equal(summarize('Tests pass.\n\nWant me to push this?').question, true);
  assert.equal(summarize('Tests pass. Pushed to main.').question, false);
});

test('a question earlier in the message does not count', () => {
  assert.equal(
    summarize('I wondered whether to bump the version? I did not. Done.').question,
    false,
  );
});

test('an empty closing message still says something honest', () => {
  assert.equal(summarize('').headline, 'Finished, without saying much about it');
  assert.equal(summarize(null).headline, 'Finished, without saying much about it');
});

test('a very long headline is truncated rather than dumped', () => {
  const { headline } = summarize(`${'word '.repeat(200)}.`);
  assert.ok(headline.length <= 240, `got ${headline.length}`);
  assert.ok(headline.endsWith('…'));
});

test('the question it ended on is pulled out, not swallowed by the summary', () => {
  const { headline, ask, question } = summarize(
    'Fixed the token refresh so expired sessions reconnect. All 84 tests pass.\n\nWant me to push this?',
  );
  assert.equal(question, true);
  assert.equal(headline, 'Fixed the token refresh so expired sessions reconnect. All 84 tests pass.');
  assert.equal(ask, 'Want me to push this?');
});

test('a bare question is not repeated as both headline and question', () => {
  const { headline, ask } = summarize('Which database should I use?');
  assert.equal(headline, 'Which database should I use?');
  assert.equal(ask, headline, 'the UI hides the echo when these match');
});

test('a statement has no question to pull out', () => {
  assert.equal(summarize('Pushed to main.').ask, '');
});

test('a question closing a paragraph counts, not just one on its own line', () => {
  const { headline, ask, question } = summarize(
    'Fixed the token refresh. All 84 tests pass. Want me to push this?',
  );
  assert.equal(question, true);
  assert.equal(ask, 'Want me to push this?');
  assert.equal(headline, 'Fixed the token refresh. All 84 tests pass.');
});
