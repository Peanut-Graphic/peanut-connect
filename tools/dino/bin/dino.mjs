#!/usr/bin/env node
/**
 * dino — a calmer window onto whatever your terminal agents are doing.
 *
 *   dino              start the window
 *   dino demo         start it and drive it with a scripted session
 *   dino install      wire the hooks into Claude Code (--project for this repo)
 *   dino uninstall    take them back out
 *   dino hook <Event> internal: what the hooks themselves run
 */

import { spawn } from 'node:child_process';
import { start, DEFAULT_PORT } from '../src/server.mjs';
import { runHook } from '../src/hook.mjs';
import { install, uninstall } from '../src/install.mjs';
import { runDemo } from '../src/demo.mjs';

const [command = 'start', ...rest] = process.argv.slice(2);
const port = Number(process.env.DINO_PORT) || DEFAULT_PORT;
const scope = rest.includes('--project') ? 'project' : 'user';

/** Open the window in the default browser, quietly giving up if we can't. */
function openBrowser(url) {
  const opener = process.platform === 'darwin' ? 'open'
    : process.platform === 'win32' ? 'start'
    : 'xdg-open';
  try {
    spawn(opener, [url], { stdio: 'ignore', detached: true, shell: process.platform === 'win32' })
      .on('error', () => {})
      .unref();
  } catch {
    // No browser to open is not an error; the URL is printed either way.
  }
}

async function serve({ open = true } = {}) {
  try {
    await start({ port });
  } catch (error) {
    if (error.code === 'EADDRINUSE') {
      console.error(`dino is already running on port ${port}.`);
      console.error(`  → http://127.0.0.1:${port}`);
      process.exit(1);
    }
    throw error;
  }

  const url = `http://127.0.0.1:${port}`;
  console.log(`\n  dino is watching → ${url}\n`);
  if (open) openBrowser(url);
  return url;
}

switch (command) {
  case 'hook': {
    // Never allowed to fail: a crash here is a crashed hook, and a crashed
    // hook is noise in the terminal this tool exists to quieten.
    await runHook(rest[0]).catch(() => {});
    break;
  }

  case 'start': {
    await serve();
    break;
  }

  case 'demo': {
    await serve();
    console.log('  Running a scripted session. Watch the window.\n');
    await runDemo(port);
    process.exit(0);
    break;
  }

  case 'install': {
    const { file, backup } = await install(scope);
    console.log(`\n  Hooks installed in ${file}`);
    if (backup) console.log(`  (previous version saved as ${backup})`);
    console.log('\n  Start a new agent session for them to take effect.');
    console.log(`  Then run \`dino\` and leave the window open.\n`);
    break;
  }

  case 'uninstall': {
    const { file } = await uninstall(scope);
    console.log(`\n  Hooks removed from ${file}\n`);
    break;
  }

  default: {
    console.error(`Unknown command: ${command}`);
    console.error('Try: dino | dino demo | dino install | dino uninstall');
    process.exit(1);
  }
}
