/**
 * transcript.mjs — pull the agent's closing words out of a session transcript.
 *
 * Claude Code writes each session as JSONL and hands hooks the path. We only
 * ever want the last assistant message, so we read the tail rather than the
 * file: transcripts run to megabytes on a long session and this is on the path
 * of a hook the terminal is blocked on.
 */

import { open, stat } from 'node:fs/promises';

/** Plenty for the last few messages, small enough to read instantly. */
const TAIL_BYTES = 256 * 1024;

async function readTail(filePath, bytes = TAIL_BYTES) {
  const { size } = await stat(filePath);
  const start = Math.max(0, size - bytes);
  const length = size - start;
  const handle = await open(filePath, 'r');
  try {
    const buffer = Buffer.alloc(length);
    await handle.read(buffer, 0, length, start);
    return { text: buffer.toString('utf8'), truncated: start > 0 };
  } finally {
    await handle.close();
  }
}

/** Concatenate the text blocks of an assistant message, ignoring tool calls. */
function textOf(entry) {
  const content = entry?.message?.content;
  if (typeof content === 'string') return content;
  if (!Array.isArray(content)) return '';
  return content
    .filter((block) => block?.type === 'text' && typeof block.text === 'string')
    .map((block) => block.text)
    .join('\n')
    .trim();
}

/**
 * The final thing the agent said in plain prose.
 *
 * Returns '' rather than throwing for every failure mode — a missing,
 * unreadable, or unfamiliar transcript should cost us a nice headline, never
 * the user's turn.
 *
 * @param {string} transcriptPath
 * @returns {Promise<string>}
 */
export async function lastAssistantMessage(transcriptPath) {
  if (!transcriptPath) return '';

  let tail;
  try {
    tail = await readTail(transcriptPath);
  } catch {
    return '';
  }

  const lines = tail.text.split('\n');
  // The first line is probably a fragment of a record split by the tail cut.
  if (tail.truncated) lines.shift();

  for (let i = lines.length - 1; i >= 0; i -= 1) {
    const line = lines[i].trim();
    if (!line) continue;

    let entry;
    try {
      entry = JSON.parse(line);
    } catch {
      continue;
    }

    if (entry?.type !== 'assistant') continue;
    const text = textOf(entry);
    if (text) return text;
  }

  return '';
}
