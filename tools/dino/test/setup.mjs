/**
 * Point the store at a throwaway directory before anything imports it.
 *
 * projects.mjs and state.mjs read from disk at module-evaluation time, so this
 * has to be the *first* import in every test file — otherwise a test run
 * rewrites the colours and archive of whoever is running it.
 */

import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';

process.env.DINO_HOME = mkdtempSync(path.join(tmpdir(), 'dino-test-'));
