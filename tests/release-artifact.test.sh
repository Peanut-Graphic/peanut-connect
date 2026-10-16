#!/usr/bin/env bash
# Repository test entry point for the synced, canonical artifact regression.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
bash "$ROOT/.peanut/release/test-plugin-artifact.sh"
