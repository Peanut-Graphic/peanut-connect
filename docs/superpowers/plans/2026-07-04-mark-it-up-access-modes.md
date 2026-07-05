# Mark It Up Per-Site Access Modes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a per-site access toggle to the Mark It Up widget: off / review-link only / specific users + link / everyone with edit access + link (today's behavior, the default).

**Architecture:** All gating lives in `includes/class-connect-feedback.php`. Two new options store the mode and the allowed-user list. Decision logic goes in two PURE static methods (`normalize_access_mode`, `compute_user_grant`) so they're unit-testable with the repo's standalone mock bootstrap (same pattern as `build_store_payload`); thin private wrappers gather WP state. The three existing gates (`review_active`, `can_review`, `can_review_agency`) and the cookie-setter route through them. The admin page gains a radio group + conditional user checklist. One guarded line changes in the widget JS.

**Tech Stack:** WordPress plugin PHP 8 (PHPUnit 10 standalone mocks), vanilla-JS widget.

**Spec:** `docs/superpowers/specs/2026-07-04-mark-it-up-access-modes-design.md` — the spec governs on any conflict.

## Global Constraints

- Option names EXACTLY: `peanut_connect_feedback_access` (string) and `peanut_connect_feedback_allowed_users` (array of ints).
- Mode values EXACTLY: `'editors'` \| `'users'` \| `'token'` \| `'off'`. Unknown/missing ⇒ `'editors'`. Default = `'editors'` (today's behavior; a fleet update must change nothing until someone touches the setting).
- `'off'` beats everything: valid token, injected cookie, agency login. The wp-admin settings page itself stays reachable.
- The token/cookie path works in every mode except `'off'` — the mode governs AUTOMATIC grants only.
- Replies stay agency-only: `can_review_agency()` = `is_agency() && can_review($request)`.
- No HUB changes. No new REST routes. No emoji anywhere. Text domain `'peanut-connect'`; escape all admin output (`esc_html`, `esc_attr`, `checked()`).
- Version ships as **3.21.0** (MINOR bump from 3.20.0).
- Work on branch `feat/mark-it-up-access-3.21.0` off current `main`. Push with `-u` on first push.
- Baseline test state: `vendor/bin/phpunit --testsuite Unit` = 122 tests, 605 assertions, 1 skipped, green. Never regress it.

---

### Task 1: Access-mode core — pure helpers + rewired gates

**Files:**
- Modify: `includes/class-connect-feedback.php` (constants near line 22; new methods after `build_store_payload`; edits to `review_active` ~line 160, `maybe_persist_review_cookie` ~line 189, `can_review` ~line 302, `can_review_agency` ~line 318)
- Test: `tests/test-connect-feedback-access.php` (create)

**Interfaces:**
- Consumes: existing `self::is_agency()` (private, `is_user_logged_in() && current_user_can('edit_posts')`), option constants below.
- Produces (Task 2 relies on these exact signatures):
  - `const ACCESS_OPTION = 'peanut_connect_feedback_access';`
  - `const ALLOWED_USERS_OPTION = 'peanut_connect_feedback_allowed_users';`
  - `public static function normalize_access_mode($raw): string`
  - `public static function sanitize_allowed_user_ids($raw): array`
  - `public static function compute_user_grant(string $mode, bool $is_agency, bool $logged_in, int $user_id, array $allowed_ids): bool`
  - `private static function access_mode(): string`

- [ ] **Step 1: Create the branch**

```bash
cd /Users/nattyb/Documents/Peanut/PEANUT-CONNECT
git fetch origin main && git checkout main && git pull --ff-only
git checkout -b feat/mark-it-up-access-3.21.0
```

- [ ] **Step 2: Write the failing tests**

Create `tests/test-connect-feedback-access.php` with EXACTLY:

```php
<?php
/**
 * Tests for the pure access-mode decision seams: mode normalization,
 * allowed-user sanitization, and the automatic-grant matrix. The wrappers
 * that read live WP state (access_mode()/user_grant()) are exercised on
 * staging; these tests pin the decision logic itself.
 *
 * @package Peanut_Connect
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/class-connect-feedback.php';

final class ConnectFeedbackAccessTest extends TestCase
{
    public function test_normalize_access_mode_accepts_known_modes(): void
    {
        $this->assertSame('editors', Peanut_Connect_Feedback::normalize_access_mode('editors'));
        $this->assertSame('users', Peanut_Connect_Feedback::normalize_access_mode('users'));
        $this->assertSame('token', Peanut_Connect_Feedback::normalize_access_mode('token'));
        $this->assertSame('off', Peanut_Connect_Feedback::normalize_access_mode('off'));
    }

    public function test_normalize_access_mode_defaults_everything_else_to_editors(): void
    {
        $this->assertSame('editors', Peanut_Connect_Feedback::normalize_access_mode(''));
        $this->assertSame('editors', Peanut_Connect_Feedback::normalize_access_mode(false));   // get_option miss
        $this->assertSame('editors', Peanut_Connect_Feedback::normalize_access_mode(null));
        $this->assertSame('editors', Peanut_Connect_Feedback::normalize_access_mode('OFF'));   // strict match only
        $this->assertSame('editors', Peanut_Connect_Feedback::normalize_access_mode(['off']));
        $this->assertSame('editors', Peanut_Connect_Feedback::normalize_access_mode('everyone'));
    }

    public function test_sanitize_allowed_user_ids_casts_dedupes_and_drops_junk(): void
    {
        $this->assertSame([7, 3], Peanut_Connect_Feedback::sanitize_allowed_user_ids(['7', 3, '7', 0, -2, 'abc']));
        $this->assertSame([], Peanut_Connect_Feedback::sanitize_allowed_user_ids([]));
        $this->assertSame([], Peanut_Connect_Feedback::sanitize_allowed_user_ids('not-an-array'));
    }

    public function test_off_and_token_modes_never_grant_automatically(): void
    {
        // Even a logged-in agency user gets no automatic grant.
        $this->assertFalse(Peanut_Connect_Feedback::compute_user_grant('off', true, true, 1, [1]));
        $this->assertFalse(Peanut_Connect_Feedback::compute_user_grant('token', true, true, 1, [1]));
    }

    public function test_users_mode_grants_only_listed_logged_in_users(): void
    {
        $this->assertTrue(Peanut_Connect_Feedback::compute_user_grant('users', false, true, 7, [3, 7]));
        $this->assertFalse(Peanut_Connect_Feedback::compute_user_grant('users', false, true, 8, [3, 7]));
        $this->assertFalse(Peanut_Connect_Feedback::compute_user_grant('users', false, false, 7, [3, 7]));
        // Agency status does not bypass the list in users mode.
        $this->assertFalse(Peanut_Connect_Feedback::compute_user_grant('users', true, true, 8, [3, 7]));
        // Option may hold numeric strings (older serialized saves) — still matches.
        $this->assertTrue(Peanut_Connect_Feedback::compute_user_grant('users', false, true, 7, ['7']));
        $this->assertFalse(Peanut_Connect_Feedback::compute_user_grant('users', false, true, 7, []));
    }

    public function test_editors_mode_grants_exactly_agency(): void
    {
        $this->assertTrue(Peanut_Connect_Feedback::compute_user_grant('editors', true, true, 1, []));
        $this->assertFalse(Peanut_Connect_Feedback::compute_user_grant('editors', false, true, 5, [5]));
    }
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `cd /Users/nattyb/Documents/Peanut/PEANUT-CONNECT && vendor/bin/phpunit tests/test-connect-feedback-access.php`
Expected: FAIL — `Call to undefined method Peanut_Connect_Feedback::normalize_access_mode()`

- [ ] **Step 4: Add constants and pure helpers**

In `includes/class-connect-feedback.php`, directly below the existing `const REVIEW_COOKIE = 'pp_review';` (line 22), add:

```php
    /** Option: who gets Mark It Up automatically. 'editors'|'users'|'token'|'off'. */
    const ACCESS_OPTION = 'peanut_connect_feedback_access';

    /** Option: WP user IDs granted access when ACCESS_OPTION is 'users'. */
    const ALLOWED_USERS_OPTION = 'peanut_connect_feedback_allowed_users';
```

Directly after the closing brace of `build_store_payload()` (line 43), add:

```php
    /**
     * Normalize a raw option value to a known access mode. Anything
     * unrecognized — including a missing option (false) on sites that
     * updated from <=3.20.0 — means 'editors', today's behavior, so a
     * fleet update changes nothing until someone touches the setting.
     */
    public static function normalize_access_mode($raw): string {
        return in_array($raw, ['users', 'token', 'off'], true) ? $raw : 'editors';
    }

    /**
     * Coerce a posted/stored allowed-user list to unique positive ints.
     */
    public static function sanitize_allowed_user_ids($raw): array {
        $ids = array_map('intval', is_array($raw) ? $raw : []);
        $ids = array_filter($ids, static function ($id) {
            return $id > 0;
        });
        return array_values(array_unique($ids));
    }

    /**
     * The AUTOMATIC access grant for the current visitor — pure so it can
     * be unit-tested without WP state. The token/cookie path is separate
     * and additive (handled by the callers); this decides only what a
     * login gets you by itself. 'off' short-circuits in the callers too,
     * where it must also defeat the token path.
     */
    public static function compute_user_grant(string $mode, bool $is_agency, bool $logged_in, int $user_id, array $allowed_ids): bool {
        switch ($mode) {
            case 'off':
            case 'token':
                return false;
            case 'users':
                return $logged_in && in_array($user_id, array_map('intval', $allowed_ids), true);
            default: // 'editors'
                return $is_agency;
        }
    }

    private static function access_mode(): string {
        return self::normalize_access_mode(get_option(self::ACCESS_OPTION, 'editors'));
    }

    private static function user_grant(string $mode): bool {
        return self::compute_user_grant(
            $mode,
            self::is_agency(),
            is_user_logged_in(),
            (int) get_current_user_id(),
            (array) get_option(self::ALLOWED_USERS_OPTION, [])
        );
    }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/test-connect-feedback-access.php`
Expected: PASS (7 tests)

- [ ] **Step 6: Rewire the four gates**

In `review_active()` (~line 160), replace the opening agency check:

```php
        if (is_user_logged_in() && current_user_can('edit_posts')) {
            return true;
        }
```

with:

```php
        $mode = self::access_mode();
        if ($mode === 'off') {
            return false; // off beats everything, including a valid token or cookie
        }
        if (self::user_grant($mode)) {
            return true;
        }
```

(The token/cookie block below it stays byte-for-byte unchanged.)

In `maybe_persist_review_cookie()` (~line 189), add as the FIRST statement of the function body:

```php
        if (self::access_mode() === 'off') {
            return;
        }
```

Replace the body of `can_review()` (~line 302) with:

```php
        $mode = self::access_mode();
        if ($mode === 'off') {
            return false;
        }
        if (self::user_grant($mode)) {
            return true;
        }

        $token = (string) $request->get_header('X-Peanut-Review-Token');
        $expected = (string) get_option('peanut_connect_feedback_review_token', '');

        return $expected !== '' && $token !== '' && hash_equals($expected, $token);
```

And update its docblock to:

```php
    /**
     * Pin-level gate. 'off' rejects everyone. Otherwise: the mode's
     * automatic grant (see compute_user_grant()) OR a valid
     * X-Peanut-Review-Token header. In 'users' mode an allowed
     * non-editor authenticates via the normal wp_rest cookie nonce.
     */
```

Replace the body of `can_review_agency()` (~line 318) with:

```php
        return self::is_agency() && self::can_review($request);
```

And update its docblock to:

```php
    /**
     * Replies-level gate: agency only, AND the caller must hold pin access
     * under the current mode — a non-listed editor in 'users' mode, or an
     * agency user without the token in 'token' mode, can't reach replies
     * either. Review-token-only clients still never qualify (not agency):
     * Hub's reply index can return is_internal replies.
     */
```

Also update the file-level docblock's third paragraph (lines 7-10) to:

```php
 * Pin endpoints accept callers per the site's access mode (option
 * peanut_connect_feedback_access): the mode's automatic grant or a valid
 * review token; 'off' rejects everyone. The replies endpoints additionally
 * require an agency user (edit_posts) — Hub's reply index can include
 * is_internal replies, and review-token clients must never reach those.
```

- [ ] **Step 7: Lint and run the full suite**

Run: `php -l includes/class-connect-feedback.php && vendor/bin/phpunit --testsuite Unit`
Expected: `No syntax errors detected`; 129 tests green (122 baseline + 7 new), 1 skipped.

- [ ] **Step 8: Commit**

```bash
git add includes/class-connect-feedback.php tests/test-connect-feedback-access.php
git commit -m "feat(feedback): per-site access modes — gate logic + tests"
git push -u origin feat/mark-it-up-access-3.21.0
```

---

### Task 2: Admin UI — access-mode radio group + user checklist

**Files:**
- Modify: `includes/class-connect-feedback.php` — `render_admin_page()` only (~lines 72-140)

**Interfaces:**
- Consumes (from Task 1, exact): `self::ACCESS_OPTION`, `self::ALLOWED_USERS_OPTION`, `self::normalize_access_mode($raw): string`, `self::sanitize_allowed_user_ids($raw): array`, `self::access_mode(): string` (private — callable here, same class).
- Produces: form fields `pcf_access_mode` (radio) and `pcf_allowed_users[]` (checkboxes), saved by the page's existing `pcf_review_token`-nonced POST handler.

- [ ] **Step 1: Extend the POST handler**

In `render_admin_page()`, inside the existing `if (! empty($_POST['pcf_action']) && check_admin_referer('pcf_review_token'))` block, add AFTER the `update_option('peanut_connect_feedback_review_token', $token);` line (keep the heartbeat push and `$notice` logic below it unchanged):

```php
            // Access mode + allowed users save on the same nonce/submit.
            $mode_in = sanitize_key(wp_unslash($_POST['pcf_access_mode'] ?? 'editors'));
            update_option(self::ACCESS_OPTION, self::normalize_access_mode($mode_in));
            update_option(
                self::ALLOWED_USERS_OPTION,
                self::sanitize_allowed_user_ids(wp_unslash($_POST['pcf_allowed_users'] ?? []))
            );
```

- [ ] **Step 2: Load render state**

Directly after the existing `$token = (string) get_option('peanut_connect_feedback_review_token', '');` line, add:

```php
        $mode        = self::access_mode();
        $allowed_ids = self::sanitize_allowed_user_ids(get_option(self::ALLOWED_USERS_OPTION, []));
        // Everyone who could review as a logged-in user. 'capability' needs
        // WP 5.9+ (fleet floor is 6.0). Capped at 100 — these are agency and
        // client editor accounts, not open-registration user bases.
        $reviewers   = get_users([
            'capability' => 'edit_posts',
            'number'     => 100,
            'orderby'    => 'display_name',
            'order'      => 'ASC',
        ]);
```

- [ ] **Step 3: Render the radio group + checklist**

Inside the `<form method="post">`, directly after the `<?php wp_nonce_field('pcf_review_token'); ?>` line and BEFORE the existing `<table class="form-table" ...>`, add:

```php
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Who can mark up this site', 'peanut-connect'); ?></th>
                        <td>
                            <fieldset>
                                <label><input type="radio" name="pcf_access_mode" value="editors" <?php checked($mode, 'editors'); ?> />
                                    <?php esc_html_e('Everyone with edit access + review link', 'peanut-connect'); ?></label>
                                <p class="description"><?php esc_html_e('Logged-in users who can edit posts see the widget automatically. Default.', 'peanut-connect'); ?></p>

                                <label><input type="radio" name="pcf_access_mode" value="users" <?php checked($mode, 'users'); ?> />
                                    <?php esc_html_e('Specific users + review link', 'peanut-connect'); ?></label>
                                <p class="description"><?php esc_html_e('Only the users checked below see the widget automatically.', 'peanut-connect'); ?></p>

                                <div id="pcf-user-checklist" style="margin:4px 0 12px 24px; max-height:220px; overflow-y:auto; <?php echo $mode === 'users' ? '' : 'display:none;'; ?>">
                                    <?php if (empty($reviewers)) : ?>
                                        <p class="description"><?php esc_html_e('No users with edit access found.', 'peanut-connect'); ?></p>
                                    <?php else : ?>
                                        <?php foreach ($reviewers as $reviewer) : ?>
                                            <label style="display:block; margin:2px 0;">
                                                <input type="checkbox" name="pcf_allowed_users[]" value="<?php echo esc_attr((string) $reviewer->ID); ?>" <?php checked(in_array((int) $reviewer->ID, $allowed_ids, true)); ?> />
                                                <?php echo esc_html($reviewer->display_name); ?>
                                                <span class="description">(<?php echo esc_html($reviewer->user_login); ?>)</span>
                                            </label>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>

                                <label><input type="radio" name="pcf_access_mode" value="token" <?php checked($mode, 'token'); ?> />
                                    <?php esc_html_e('Review link only', 'peanut-connect'); ?></label>
                                <p class="description"><?php esc_html_e('Nobody sees the widget automatically — only visitors who open the review link below.', 'peanut-connect'); ?></p>

                                <label><input type="radio" name="pcf_access_mode" value="off" <?php checked($mode, 'off'); ?> />
                                    <?php esc_html_e('Off', 'peanut-connect'); ?></label>
                                <p class="description"><?php esc_html_e('Mark It Up is disabled on this site. The review link stops working too.', 'peanut-connect'); ?></p>
                            </fieldset>
                            <script>
                            (function () {
                                var list = document.getElementById('pcf-user-checklist');
                                var radios = document.querySelectorAll('input[name="pcf_access_mode"]');
                                function sync() {
                                    var checked = document.querySelector('input[name="pcf_access_mode"]:checked');
                                    list.style.display = (checked && checked.value === 'users') ? '' : 'none';
                                }
                                for (var i = 0; i < radios.length; i++) {
                                    radios[i].addEventListener('change', sync);
                                }
                            })();
                            </script>
                        </td>
                    </tr>
                </table>
```

Note: the checklist stays in the DOM when hidden, so `pcf_allowed_users[]` selections persist across a save made in another mode. The save button label ("Save token") now saves mode too — change both submit buttons' surrounding `<p>` to:

```php
                <p>
                    <button type="submit" name="pcf_action" value="save" class="button button-primary"><?php esc_html_e('Save settings', 'peanut-connect'); ?></button>
                    <button type="submit" name="pcf_action" value="generate" class="button"><?php esc_html_e('Generate a new token', 'peanut-connect'); ?></button>
                </p>
```

And update the two notice strings in the POST handler to match:

```php
            $notice = $token === ''
                ? __('Settings saved. Review token is blank — client review links are disabled.', 'peanut-connect')
                : __('Settings saved.', 'peanut-connect');
```

- [ ] **Step 4: Lint and run the full suite**

Run: `php -l includes/class-connect-feedback.php && vendor/bin/phpunit --testsuite Unit`
Expected: `No syntax errors detected`; 129 tests green, 1 skipped (admin page is render-only — no new unit surface; the sanitizers it calls were tested in Task 1).

- [ ] **Step 5: Commit**

```bash
git add includes/class-connect-feedback.php
git commit -m "feat(feedback): admin access-mode radio group + user checklist"
git push
```

---

### Task 3: Widget nonce seam, version bump, changelog, package

**Files:**
- Modify: `assets/js/feedback.js:50`
- Modify: `peanut-connect.php:6` and `peanut-connect.php:20`
- Modify: `readme.txt` (Stable tag line 7 + new changelog block above `= 3.20.0 =` at line 82)
- Modify: `CHANGELOG.md` (new block above `## [3.20.0]` at line 8)

**Interfaces:**
- Consumes: `window.peanutConnectFeedback` localize payload — `cfg.nonce`, `cfg.isAgency`, `cfg.reviewToken` (all already localized; no PHP change needed).
- Produces: release-ready 3.21.0 tree; `dist/peanut-connect-3.21.0.zip`.

- [ ] **Step 1: Fix the nonce seam in the widget**

In `assets/js/feedback.js` line 50, replace:

```js
    if (cfg.isAgency && cfg.nonce) headers['X-WP-Nonce'] = cfg.nonce;
```

with:

```js
    // Send the WP auth nonce for agency users (identity + attribution) and
    // for logged-in reviewers with no token — the 'users'-mode path, where
    // the REST gate authenticates them via the wp_rest cookie nonce. Token
    // reviewers keep the token-only header: on page-cached sites a stale
    // baked-in nonce would 403 an otherwise-valid token request.
    if (cfg.nonce && (cfg.isAgency || !cfg.reviewToken)) headers['X-WP-Nonce'] = cfg.nonce;
```

- [ ] **Step 2: Syntax-check the widget**

Run: `node --check assets/js/feedback.js`
Expected: no output (clean).

- [ ] **Step 3: Bump version to 3.21.0**

`peanut-connect.php` line 6: ` * Version: 3.20.0` → ` * Version: 3.21.0`
`peanut-connect.php` line 20: `define('PEANUT_CONNECT_VERSION', '3.20.0');` → `define('PEANUT_CONNECT_VERSION', '3.21.0');`
`readme.txt` line 7: `Stable tag: 3.20.0` → `Stable tag: 3.21.0`

- [ ] **Step 4: Changelog entries**

`readme.txt` — insert above the `= 3.20.0 =` block:

```
= 3.21.0 =
* New: Mark It Up per-site access modes — Everyone with edit access (default), Specific users, Review link only, or Off.
* New: user checklist on the Mark It Up settings page for the Specific users mode.
* Fix: logged-in reviewers without edit access (Specific users mode) now authenticate their widget requests correctly.
```

`CHANGELOG.md` — insert above the `## [3.20.0]` block:

```markdown
## [3.21.0] - 2026-07-04

### Added
- Mark It Up per-site access modes (`peanut_connect_feedback_access`): `editors` (default, today's behavior), `users` (checklist of WP accounts in `peanut_connect_feedback_allowed_users`), `token` (review link only), `off` (widget and review link fully disabled).
- Access-mode radio group and specific-users checklist on the Mark It Up admin page, saved with the existing token form.

### Changed
- Replies endpoints now also require pin access under the current mode (still agency-only on top).
- Widget sends the REST auth nonce for any logged-in reviewer without a review token, so `users`-mode reviewers without edit access authenticate correctly.
```

- [ ] **Step 5: Full verification + commit**

Run: `php -l peanut-connect.php && php -l includes/class-connect-feedback.php && node --check assets/js/feedback.js && vendor/bin/phpunit --testsuite Unit`
Expected: all clean; 129 tests green, 1 skipped.

```bash
git add assets/js/feedback.js peanut-connect.php readme.txt CHANGELOG.md
git commit -m "feat(feedback): widget nonce seam for users mode; bump to 3.21.0"
git push
```

- [ ] **Step 6: Build the release zip**

Run: `./scripts/package.sh`
Expected: `dist/peanut-connect-3.21.0.zip` created (script refuses a dirty tree — commit first; `dist/` is git-ignored, nothing to commit after).

```bash
git status --short   # expect empty output
```

---

## Post-plan (NOT part of the task cycle)

Staging validation on staging.cenhudpeakperks.com per the spec's matrix — cycle all four modes, check widget presence for (a) logged-in admin, (b) anonymous with the snippet-injected cookie, (c) anonymous clean, plus REST create allow/deny per mode; `off` must kill (a) and (b). Then merge to main and run the standard fleet release pipeline (tag `v3.21.0`, GitHub release with the dist zip, `ssh peanutgraphic "cd public_html && wp option update peanut_peanut-connect_version 3.21.0"`).
