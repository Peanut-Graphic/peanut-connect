# Mobile Audit Report: Connect navigation

Date:2026-10-16. Scope: the WordPress admin app wrapper and shared navigation, with Overview and Settings rendered in an isolated WordPress7.1/PHP8.2 fixture. This is a focused regression audit, not a claim that every product screen has been audited.

## Findings and changes

| Finding | Severity | Change |
|---|---|---|
| Document exceeded every tested viewport by20px | Medium | Remove the negative right margin; match WordPress's narrower phone padding |
| Expanded desktop sidebar squeezed phone content | High | Use a full-width, initially closed navigation disclosure below768px |
| Destination navigation remounts the layout and loses focus | Medium | Restore focus after the router reaches the chosen destination; Escape also closes and restores focus |
| Small navigation controls | Medium | Navigation links and collapse/menu buttons have44px minimum targets |

The phone menu does not change the persisted desktop collapse preference. Desktop navigation remains an in-content sticky column, outside the WordPress admin menu. Reduced-motion users do not receive the sidebar transition.

## Validation

On Node22.22.2/npm10.9.7, all515frontendtests pass, including three new behavioral regressions. The first two fail on the original implementation; the page-remount regression also caught a focus bug during iteration. Type checking, production build and accessibility lint pass. A targeted lockfile patch updates only DOMPurify, brace-expansion, js-yaml and source-map-js; the full npm audit reports zero vulnerabilities.

Chromium browser acceptance verifies no document overflow or negative app position at375/390/430/768/1024/1440px. Phone checks verify closed/open navigation,44px link targets, Escape dismissal, destination dismissal and focus return. Desktop collapse/expand and preservation of its preference during phone navigation are checked separately. Screenshot/evidence files are retained in this task's release output.

Safari, Firefox, Windows rendering and unrelated screens were not rendered in this focused pass. Existing broader mobile priorities, such as form input typography and field-specific layouts, remain separate work.

Publication and deployment require full exact-head PR/main CI, exact signed-package verification and guarded production canary checks.
