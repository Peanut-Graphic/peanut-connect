'use strict';

const { execFileSync } = require('node:child_process');
const { readFileSync } = require('node:fs');
const { resolve } = require('node:path');

const repositoryRoot = resolve(__dirname, '..');
const expected = Object.freeze({ node: '22.22.2', npm: '10.9.7' });
const declarationsOnly = process.argv.includes('--declarations-only');

function read(path) {
  return readFileSync(resolve(repositoryRoot, path), 'utf8');
}

function readJson(path) {
  return JSON.parse(read(path));
}

function assertEqual(actual, wanted, label) {
  if (actual !== wanted) {
    throw new Error(`${label}: expected ${wanted}, received ${actual}`);
  }
}

for (const prefix of ['', 'frontend/']) {
  const packageJson = readJson(`${prefix}package.json`);
  const packageLock = readJson(`${prefix}package-lock.json`);
  const label = prefix || 'root ';

  assertEqual(packageJson.engines?.node, expected.node, `${label}package.json engines.node`);
  assertEqual(packageJson.engines?.npm, expected.npm, `${label}package.json engines.npm`);
  assertEqual(packageJson.packageManager, `npm@${expected.npm}`, `${label}package.json packageManager`);
  assertEqual(packageLock.packages?.['']?.engines?.node, expected.node, `${label}package-lock.json engines.node`);
  assertEqual(packageLock.packages?.['']?.engines?.npm, expected.npm, `${label}package-lock.json engines.npm`);
}

assertEqual(read('.nvmrc').trim(), expected.node, '.nvmrc');

const workflowPaths = ['.github/workflows/accessibility.yml', '.github/workflows/tests.yml'];
const workflows = workflowPaths.map(read);
const nodePins = workflows.flatMap((workflow) =>
  [...workflow.matchAll(/node-version:\s*['"]?([^'"\s]+)/g)].map((match) => match[1]),
);
assertEqual(nodePins.length, 2, 'workflow Node declaration count');
nodePins.forEach((pin, index) => assertEqual(pin, expected.node, `workflow node-version ${index + 1}`));
assertEqual(
  workflows.reduce((count, workflow) => count + (workflow.match(/npm --version/g)?.length ?? 0), 0),
  2,
  'workflow npm assertion count',
);

if (!declarationsOnly) {
  assertEqual(process.versions.node, expected.node, 'active Node runtime');
  const npmVersion = execFileSync('npm', ['--version'], { encoding: 'utf8' }).trim();
  assertEqual(npmVersion, expected.npm, 'active npm runtime');
}

console.log(
  declarationsOnly
    ? `Runtime declarations are pinned to Node ${expected.node} and npm ${expected.npm}.`
    : `Runtime contract verified on Node ${expected.node} and npm ${expected.npm}.`,
);
