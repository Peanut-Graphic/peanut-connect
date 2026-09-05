/**
 * narrate.mjs — turn raw agent activity into a sentence a human would say.
 *
 * Everything the terminal shows is accurate and unreadable. This module is the
 * opposite trade: it drops detail on purpose so a glance is enough. Nothing in
 * here is load-bearing for the agent — if a narration is wrong, the worst case
 * is a slightly-off label, and the raw event is still in the details drawer.
 */

import path from 'node:path';

/** Tools whose first argument is a file we can name. */
const FILE_TOOLS = {
  Read: 'Reading',
  Edit: 'Editing',
  MultiEdit: 'Editing',
  Write: 'Writing',
  NotebookEdit: 'Editing',
};

/**
 * Shell commands, most specific first. The first pattern that matches wins, so
 * `git commit` must be tested before the bare `git` fallback.
 */
const BASH_PATTERNS = [
  [/\bgit\s+commit\b/, 'Committing changes'],
  [/\bgit\s+push\b/, 'Pushing to GitHub'],
  [/\bgit\s+(pull|fetch)\b/, 'Getting the latest code'],
  [/\bgit\s+(status|diff|log|show|branch)\b/, 'Checking on the code'],
  [/\b(npm|npx|yarn|pnpm)\s+(run\s+)?test\b|\bjest\b|\bvitest\b/, 'Running the tests'],
  [/\bcomposer\s+(run\s+)?test\b/, 'Running the tests'],
  [/\bphpunit\b|\bpest\b/, 'Running the tests'],
  [/\bpytest\b|\bpython\s+-m\s+unittest\b/, 'Running the tests'],
  [/\bgo\s+test\b|\bcargo\s+test\b/, 'Running the tests'],
  [/\b(npm|yarn|pnpm)\s+(run\s+)?build\b|\bvite\s+build\b/, 'Building the project'],
  [/\b(npm|yarn|pnpm)\s+(ci|install)\b|\bcomposer\s+(install|update)\b|\bpip\s+install\b/, 'Installing dependencies'],
  [/\b(eslint|prettier|phpcs|ruff|black|gofmt)\b|\blint\b/, 'Checking code style'],
  [/\b(grep|rg|ag|find|fd)\b/, 'Searching the codebase'],
  [/\b(cat|head|tail|less|sed\s+-n|ls)\b/, 'Looking through files'],
  [/\bmkdir\b|\bcp\b|\bmv\b|\btouch\b/, 'Moving files around'],
  [/\brm\b/, 'Cleaning up files'],
  [/\bdocker\b/, 'Working with Docker'],
  [/\bcurl\b|\bwget\b/, 'Fetching something over the network'],
];

/** Tools with no interesting argument — a fixed phrase is the whole story. */
const SIMPLE_TOOLS = {
  Grep: 'Searching the codebase',
  Glob: 'Looking for files',
  WebFetch: 'Reading a page on the web',
  WebSearch: 'Searching the web',
  Task: 'Sending a helper off to dig into something',
  Agent: 'Sending a helper off to dig into something',
  TodoWrite: 'Updating its plan',
  ExitPlanMode: 'Finishing up a plan',
};

/** Short, human name for a file path: `foo/bar/baz.php` -> `baz.php`. */
function fileLabel(p) {
  if (typeof p !== 'string' || p.length === 0) return 'a file';
  return path.basename(p) || p;
}

/** Collapse a shell command to one line so a heredoc can't blow up the UI. */
function firstLine(cmd) {
  return String(cmd ?? '').split('\n')[0].trim();
}

/**
 * Describe one tool call.
 * @returns {{text: string, kind: string}} kind groups events for the detail drawer.
 */
export function narrateTool(toolName, input = {}) {
  const verb = FILE_TOOLS[toolName];
  if (verb) {
    const target = input.file_path || input.path || input.notebook_path;
    return { text: `${verb} ${fileLabel(target)}`, kind: verb === 'Reading' ? 'read' : 'edit' };
  }

  if (toolName === 'Bash') {
    const cmd = firstLine(input.command);
    for (const [pattern, phrase] of BASH_PATTERNS) {
      if (pattern.test(cmd)) return { text: phrase, kind: 'run' };
    }
    // Fall back to the agent's own description of the command, which reads far
    // better than the command itself, before giving up on a generic phrase.
    if (input.description) return { text: String(input.description), kind: 'run' };
    return { text: 'Running a command', kind: 'run' };
  }

  const simple = SIMPLE_TOOLS[toolName];
  if (simple) return { text: simple, kind: 'look' };

  if (typeof toolName === 'string' && toolName.startsWith('mcp__')) {
    // mcp__github__create_pull_request -> "Working with github"
    const service = toolName.split('__')[1] || 'a connected service';
    return { text: `Working with ${service}`, kind: 'run' };
  }

  return { text: 'Working', kind: 'run' };
}

/**
 * Did a tool call work?
 *
 * Best-effort and deliberately conservative. Hook payloads carry the tool's
 * response, but its shape varies by tool and is not a contract, so this checks
 * several plausible failure signals and answers 'unknown' rather than guessing.
 * A wrong "failed" is worse than no answer: it would make the creature look
 * worried about nothing.
 *
 * @returns {'ok'|'failed'|'unknown'}
 */
export function outcomeOf(toolResponse) {
  if (toolResponse === undefined || toolResponse === null) return 'unknown';

  if (typeof toolResponse === 'object') {
    // The explicit flags, in the shapes they actually turn up in.
    if (toolResponse.is_error === true || toolResponse.isError === true) return 'failed';
    if (toolResponse.success === false) return 'failed';
    if (toolResponse.interrupted === true) return 'failed';
    if (typeof toolResponse.exit_code === 'number') {
      return toolResponse.exit_code === 0 ? 'ok' : 'failed';
    }
    if (typeof toolResponse.exitCode === 'number') {
      return toolResponse.exitCode === 0 ? 'ok' : 'failed';
    }
    if (toolResponse.error) return 'failed';
    if (toolResponse.success === true) return 'ok';

    // Some tools hand back their output as a string field. Fall through to the
    // text check below rather than declaring success on a shape we don't know.
    const text = toolResponse.stderr || toolResponse.output || toolResponse.stdout;
    if (typeof text === 'string') return outcomeOf(text);

    return 'unknown';
  }

  if (typeof toolResponse === 'string') {
    // Only patterns that are unambiguous about failure. Plenty of successful
    // output contains the word "error" in passing, so this looks for the
    // shapes a runner actually reports a failure in.
    const failed = /\b(\d+ failed|FAILED|FAILURES!|Tests failed|command not found|No such file or directory)\b/
      .test(toolResponse)
      || /^\s*(error|fatal):/im.test(toolResponse)
      || /\berror TS\d+\b/.test(toolResponse);
    return failed ? 'failed' : 'unknown';
  }

  return 'unknown';
}

/**
 * Commands where finishing is news in its own right. Everything else is only
 * worth a line when it goes wrong — otherwise the trail says everything twice.
 */
const NEWSWORTHY = [
  [/\bcomposer\s+(run\s+)?test\b|\b(npm|npx|yarn|pnpm)\s+(run\s+)?test\b|\bjest\b|\bvitest\b|\bphpunit\b|\bpest\b|\bpytest\b|\bgo\s+test\b|\bcargo\s+test\b/,
    { ok: 'Tests passed', failed: 'Tests failed' }],
  [/\bgit\s+push\b/,
    { ok: 'Pushed to GitHub', failed: 'Push rejected' }],
  [/\b(npm|yarn|pnpm)\s+(run\s+)?build\b|\bvite\s+build\b/,
    { ok: 'Build finished', failed: 'The build broke' }],
  [/\b(npm|yarn|pnpm)\s+(ci|install)\b|\bcomposer\s+(install|update)\b|\bpip\s+install\b/,
    { ok: 'Dependencies installed', failed: 'Installing dependencies failed' }],
  [/\b(eslint|prettier|phpcs|ruff|black|gofmt)\b|\blint\b/,
    { ok: 'Code style is clean', failed: 'Code style check failed' }],
  [/\bgit\s+commit\b/,
    { ok: 'Committed', failed: 'The commit was rejected' }],
];

/**
 * Describe how a tool call turned out — or return null when saying anything
 * would only repeat what the "about to" line already said.
 *
 * @returns {{text: string, kind: string}|null}
 */
export function narrateOutcome(toolName, input = {}, outcome = 'unknown') {
  if (outcome === 'unknown') return null;

  if (toolName === 'Bash') {
    const cmd = firstLine(input.command);
    for (const [pattern, phrases] of NEWSWORTHY) {
      if (pattern.test(cmd)) return { text: phrases[outcome], kind: outcome };
    }
  }

  // Anything else: silence when it worked, a plain note when it did not.
  if (outcome === 'ok') return null;
  return { text: `${narrateTool(toolName, input).text} — that failed`, kind: 'failed' };
}

/** Strip the markdown that reads as noise once it isn't being rendered. */
function plainText(markdown) {
  return String(markdown ?? '')
    .replace(/```[\s\S]*?```/g, ' ')       // fenced code blocks
    .replace(/`([^`]*)`/g, '$1')           // inline code
    .replace(/!?\[([^\]]*)\]\([^)]*\)/g, '$1') // links and images
    .replace(/^\s{0,3}#{1,6}\s+/gm, '')    // headings
    .replace(/^\s*[-*+]\s+/gm, '')         // bullets
    .replace(/\*\*([^*]*)\*\*/g, '$1')     // bold
    .replace(/(^|\s)\*([^*]+)\*/g, '$1$2') // italics
    .replace(/\s+/g, ' ')
    .trim();
}

const MAX_HEADLINE = 240;

function clamp(text) {
  if (text.length <= MAX_HEADLINE) return text;
  return `${text.slice(0, MAX_HEADLINE - 1).trimEnd()}…`;
}

/**
 * Reduce the agent's closing message to a headline, and pull out the question
 * if it ended on one — a question means "you are needed", not "this is done",
 * and it is the single most important thing on the card when it is there.
 *
 * @param {string} assistantText the final assistant message of the turn
 * @returns {{headline: string, question: boolean, ask: string}}
 */
export function summarize(assistantText) {
  const flat = plainText(assistantText);

  if (!flat) {
    return { headline: 'Finished, without saying much about it', question: false, ask: '' };
  }

  // A sentence ends at a terminator followed by space — anything else is a file
  // name like `class-auth.php`.
  const sentences = flat.split(/(?<=[.!?])\s+/).filter(Boolean);

  // Only a question in the *last* sentence means it is waiting on an answer.
  // "I wondered whether to bump the version? I did not." is not a question to
  // you. Working sentence-wise rather than line-wise matters: agents just as
  // often end a paragraph with "…tests pass. Want me to push?" as they do put
  // the question on its own line.
  const closing = sentences[sentences.length - 1];
  const question = closing.endsWith('?');
  const ask = question ? clamp(closing) : '';

  // When it asked something, that is the point and the rest is background.
  const body = question ? sentences.slice(0, -1) : sentences;

  // Two sentences is usually the whole story; the rest belongs in the drawer.
  const headline = body.length > 0 ? clamp(body.slice(0, 2).join(' ')) : ask;

  return { headline, question, ask };
}

/**
 * The one line at the top of the box. This is what the whole tool exists to say.
 */
export function statusLine(session) {
  switch (session.state) {
    case 'waiting':
      return session.summary?.headline || 'Waiting on you';
    case 'asking':
      return session.summary?.headline || 'It asked you something';
    case 'blocked':
      return session.summary?.headline || 'It needs your permission to continue';
    case 'working':
      return session.lastActivity?.text || 'Working';
    case 'done':
      return session.summary?.headline || 'Done';
    default:
      return 'Nothing going on';
  }
}
