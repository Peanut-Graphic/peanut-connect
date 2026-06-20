## Summary

<!-- What changed and why. -->

## Type

- [ ] **Bugfix** (`fix:` / `security:` / `hotfix:`) — a regression test is **required** (see below)
- [ ] Feature
- [ ] Chore / docs / refactor

## For bugfixes — red-then-green evidence (GAP-03)

> Every bugfix must ship with a test that **fails on the unfixed code** and passes after the fix, so it can't silently regress.

- **Fixes:** <!-- BUG-### (triage tracker) and/or "closes #issue" -->
- [ ] Added/updated a regression test that **fails without this fix**
- **Red-then-green proof:** <!-- paste the failing run output, or link the CI run on the test against the unfixed code -->

<!-- If this fix truly cannot have a test (config-only, infra, doc), apply the
     `no-regression-test` label and justify it here. The regression-gate check
     will then pass. -->

## Checklist

- [ ] Tests pass locally
- [ ] No secret committed (the `secret-scan` gate will also verify)
