# Peanut End to End project starting point

Run WordPress campaign experiences and site monitoring coordinated through Peanut HUB.

## Direction

Documented intent: Run WordPress campaign experiences and site monitoring coordinated through Peanut HUB.

Owner confirmation needed: ultimate scope, launch or completion acceptance criteria, and the next priority. This brief does not mark any feature or project complete.

## Source baseline

- Inspected: 2026-10-09
- Local branch: `codex/reactivate-legacy-unit-suite`
- Local HEAD: `592164ebe25c1bae657a7daf9d8cad6fa0a07ac2`
- Lifecycle registry: `active`; classification: `distributed-plugin` (registry reviewed 2026-08-29; current lifecycle not independently confirmed).
- Working tree at inspection: contains local changes.

## What it is built with

- package.json: Node tooling; engines {"node": "22.22.2", "npm": "10.9.7"}
- frontend/package.json: react ^18.3.1; tailwindcss ^4.0.0; typescript ^5.7.2; vite ^6.0.3; vitest ^4.1.1; engines {"node": "22.22.2", "npm": "10.9.7"}
- composer.json: php >=8.0
- peanut-connect.php: WordPress plugin End-to-End; WordPress 6.0; PHP 8.0

## Build and verification entry points

These are source-declared entry points, not commands executed by this audit. Install dependencies and configure a local environment according to the linked setup documentation before running them. Release/hosting actions remain subject to the project’s deployment rules.

- package.json: `npm run dev`
- package.json: `npm run build`
- package.json: `npm run runtime:verify`
- frontend/package.json: `npm run dev`
- frontend/package.json: `npm run build`
- frontend/package.json: `npm test`
- frontend/package.json: `npm run typecheck`
- frontend/package.json: `npm run runtime:verify`
- composer.json: `composer test`

## Structure and relationships

- `tests/` is present in this checkout.
- Cross-project integration details belong in the linked architecture and API documents; folder similarity alone does not establish a dependency.

## Gaps to resolve

- Working tree has uncommitted changes; this baseline includes local declarations beyond the recorded HEAD.
- Owner acceptance criteria and current priority order are not yet recorded in this brief.

## Source documents

- [README.md](README.md)
- [docs/](docs/)

## Keeping this current

Update this starting point when product direction, architecture, dependencies, or build/release entry points change. Recheck source facts against the current checkout. Date stamps describe inspection, not live production verification.

<!-- dino-source-audit {"date":"2026-10-09","head":"592164ebe25c1bae657a7daf9d8cad6fa0a07ac2","sources":{"README.md":"31acab98e61b311e7a955ac3a4c33e8d0c5699d9961b2a3adfcddf2bf3f5b0a7","package.json":"10bd2fe227e3bbcb427f91a4cc7d095f4650cfb818ea6473d856494c32802f0c","composer.json":"b286012e7b474fd8b6dc82aae55ef5612e755a28249742d93e8596ef68b1ec21",".nvmrc":"4c42fb8d6334c5cdcac68b93f96c581fb83b1f58cda898cff115e5e941ef717d","frontend/package.json":"823404b8e1ed4910d5a631552e13efdf2b3ba5a3108ee80cd695c24462dea03c","peanut-connect.php":"38c38400d027d94e955f78c9764805bd0f2f986f7cbe5be2771bf81b0fd796db"}} -->
