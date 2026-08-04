# Mark It Up Approvals Round 2 Implementation Plan (3.34.0)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Email notifications (NO + fully-approved + optional digest), stale-approval detection, per-approver links, an agency ready-for-review queue, and a printable sign-off record on top of the 3.33.0 approval process.

**Architecture:** New `includes/class-connect-approvals-notify.php` owns all email, driven by a `do_action('peanut_connect_approvals_vote', …)` hook fired from the vote handler. Staleness (post-modified snapshots), the ready list, and `pp_as` support extend `includes/class-connect-approvals.php` and the widget. Export is a print-friendly admin view. All decision logic lives in pure static helpers (mock-bootstrap tested); WP calls (`url_to_postid`, `get_post`, `wp_mail`, cron) live only in thin wrappers that unit tests never execute — the standalone mocks do not define those functions.

**Tech Stack:** WordPress plugin PHP 8 (PHPUnit 9.6 standalone mocks), vanilla-JS shadow-root widget, WP-Cron, `wp_mail` plain text.

**Spec:** `docs/superpowers/specs/2026-08-03-mark-it-up-approvals-round2-design.md` — governs on any conflict.

## Global Constraints

- Additive only: every 3.33.0 REST field keeps its name/shape; this round only ADDS fields (`stale`, `modified_at`, `ready`) and routes (`/approvals/ready`).
- New option names EXACTLY: `peanut_connect_approvals_ready` (array of normalized paths) and `peanut_connect_approvals_notify` (`['email' => string, 'digest' => bool]`).
- Missing snapshot fields on 3.33.0-era votes ⇒ never stale. Non-post URLs ⇒ never stale.
- All-green means: every configured approver has `vote === 'yes'` AND not stale; empty approver list is never all-green.
- Mail: plain text only, `wp_mail`, failures `error_log`-and-continue — mail must NEVER block or fail a vote. No HTML mail. No emoji anywhere.
- Cron hook name EXACTLY `peanut_connect_approvals_digest`; scheduled only while digest is enabled.
- `pp_as` joins BOTH strip lists (JS `pageKey()` and PHP `STRIP_PARAMS`).
- Widget: dependency-free vanilla JS, no native dialogs, `assets/js/feedback.js` hard ceiling ~900 lines (currently 735) — if a task would cross it, STOP and split the approvals UI into `assets/js/approvals.js` per the base spec.
- Text domain `'peanut-connect'`; escape all admin output; `update_option(..., false)`; timestamps `gmdate('Y-m-d H:i:s')`.
- Version ships as **3.34.0** (bump ONLY in Task 7).
- Baseline (branch head 100fa39): `/opt/homebrew/bin/php vendor/bin/phpunit --testsuite Unit` = **202 tests, 1 skipped, green** (ignore redis.so warnings + PHP 8.5 setAccessible deprecations). Never regress.
- Working copy: worktree `.claude/worktrees/mark-it-up-approvals`, branch `feat/mark-it-up-approvals-round2-3.34.0` (stacked on `feat/mark-it-up-approvals-3.33.0`, PR #98). Do NOT symlink vendor from the main checkout (classmap fatals); vendor is already installed here.
- Stacked-PR mechanics: the round-2 PR targets `feat/mark-it-up-approvals-3.33.0`, merges AFTER #98, and gets retargeted by GitHub; expect partial CI until then.

---

### Task 1: Pure decision helpers — staleness, all-green, settings, ready list, digest lines

**Files:**
- Modify: `includes/class-connect-approvals.php` (constants after `HISTORY_CAP` ~line 26; helpers after `public_votes` ~line 192)
- Test: `tests/Test_Connect_Approvals_Round2.php` (create)

**Interfaces:**
- Consumes: existing `normalize_path`, approver row shape `['id','name','initials']`, vote shape from `record_vote`.
- Produces (later tasks rely on these exact signatures):
  - `const READY_OPTION = 'peanut_connect_approvals_ready';`
  - `const NOTIFY_OPTION = 'peanut_connect_approvals_notify';`
  - `public static function compute_stale(array $vote, string $current_modified): bool`
  - `public static function apply_stale(array $votes, string $current_modified): array`
  - `public static function compute_all_green(array $approvers, array $votes): bool`
  - `public static function sanitize_notify_settings($raw): array`
  - `public static function sanitize_ready_list($raw): array`
  - `public static function build_digest_lines(array $ready_paths, array $pages_votes, array $approvers): array`

- [ ] **Step 1: Write the failing tests**

Create `tests/Test_Connect_Approvals_Round2.php`:

```php
<?php
/**
 * Round-2 pure seams: staleness, the all-green rule, notify settings,
 * the ready list, and digest lines. WP-touching wrappers (url_to_postid,
 * wp_mail, cron) are exercised on staging; these pin the decisions.
 *
 * @package Peanut_Connect
 */

require_once dirname(__DIR__) . '/includes/class-connect-approvals.php';

class Test_Connect_Approvals_Round2 extends Peanut_Connect_TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $mock_options;
        $mock_options = [];
    }

    protected function tearDown(): void
    {
        global $mock_options;
        $mock_options = [];
        parent::tearDown();
    }

    private function vote(array $extra = []): array
    {
        return array_merge(
            ['vote' => 'yes', 'at' => '2026-08-03 10:00:00', 'reason' => '', 'note_id' => null],
            $extra
        );
    }

    // ---- compute_stale / apply_stale ----

    public function test_vote_without_snapshot_is_never_stale(): void
    {
        $this->assertFalse(Peanut_Connect_Approvals::compute_stale($this->vote(), '2026-08-04 09:00:00'));
        $this->assertFalse(Peanut_Connect_Approvals::compute_stale($this->vote(['post_modified' => '']), '2026-08-04 09:00:00'));
    }

    public function test_vote_is_stale_only_when_modified_time_moved(): void
    {
        $v = $this->vote(['post_modified' => '2026-08-01 08:00:00']);
        $this->assertFalse(Peanut_Connect_Approvals::compute_stale($v, '2026-08-01 08:00:00'));
        $this->assertTrue(Peanut_Connect_Approvals::compute_stale($v, '2026-08-02 12:00:00'));
        $this->assertFalse(Peanut_Connect_Approvals::compute_stale($v, '')); // page no longer resolves: not stale
    }

    public function test_apply_stale_annotates_votes(): void
    {
        $votes = [
            'nh' => $this->vote(['post_modified' => '2026-08-01 08:00:00']),
            'bh' => $this->vote(),
        ];
        $out = Peanut_Connect_Approvals::apply_stale($votes, '2026-08-02 12:00:00');
        $this->assertTrue($out['nh']['stale']);
        $this->assertSame('2026-08-02 12:00:00', $out['nh']['modified_at']);
        $this->assertFalse($out['bh']['stale']);
        $this->assertArrayNotHasKey('modified_at', $out['bh']);
    }

    // ---- compute_all_green ----

    private function approvers(): array
    {
        return [
            ['id' => 'nh', 'name' => 'Natty Hooper', 'initials' => 'NH'],
            ['id' => 'bh', 'name' => 'Bob Hill', 'initials' => 'BH'],
        ];
    }

    public function test_all_green_requires_every_approver_fresh_yes(): void
    {
        $green = ['vote' => 'yes', 'stale' => false];
        $this->assertTrue(Peanut_Connect_Approvals::compute_all_green($this->approvers(), ['nh' => $green, 'bh' => $green]));
        $this->assertFalse(Peanut_Connect_Approvals::compute_all_green($this->approvers(), ['nh' => $green])); // missing bh
        $this->assertFalse(Peanut_Connect_Approvals::compute_all_green($this->approvers(), ['nh' => $green, 'bh' => ['vote' => 'no', 'stale' => false]]));
        $this->assertFalse(Peanut_Connect_Approvals::compute_all_green($this->approvers(), ['nh' => $green, 'bh' => ['vote' => 'yes', 'stale' => true]])); // stale yes
    }

    public function test_no_approvers_is_never_all_green(): void
    {
        $this->assertFalse(Peanut_Connect_Approvals::compute_all_green([], []));
    }

    // ---- sanitize_notify_settings ----

    public function test_notify_settings_defaults_and_coercion(): void
    {
        $this->assertSame(['email' => '', 'digest' => false], Peanut_Connect_Approvals::sanitize_notify_settings(false));
        $this->assertSame(['email' => '', 'digest' => false], Peanut_Connect_Approvals::sanitize_notify_settings('junk'));
        $s = Peanut_Connect_Approvals::sanitize_notify_settings(['email' => 'nat@peanutgraphic.com', 'digest' => '1', 'evil' => 'x']);
        $this->assertSame('nat@peanutgraphic.com', $s['email']);
        $this->assertTrue($s['digest']);
        $this->assertArrayNotHasKey('evil', $s);
    }

    // ---- sanitize_ready_list ----

    public function test_ready_list_normalizes_and_dedupes(): void
    {
        $out = Peanut_Connect_Approvals::sanitize_ready_list(['/p?utm_source=x', '/p', '/q', 7, '//evil']);
        $this->assertSame(['/p', '/q', '/'], $out); // junk rows collapse to '/', deduped
    }

    public function test_ready_list_swallows_non_arrays(): void
    {
        $this->assertSame([], Peanut_Connect_Approvals::sanitize_ready_list('nope'));
    }

    // ---- build_digest_lines ----

    public function test_digest_lines_list_awaiting_initials_and_skip_all_green(): void
    {
        $green = ['vote' => 'yes', 'stale' => false];
        $pages = [
            '/done'    => ['nh' => $green, 'bh' => $green],
            '/half'    => ['nh' => $green],
            '/stale'   => ['nh' => $green, 'bh' => ['vote' => 'yes', 'stale' => true]],
            '/nothing' => [],
        ];
        $lines = Peanut_Connect_Approvals::build_digest_lines(array_keys($pages), $pages, $this->approvers());
        $this->assertSame([
            '/half — awaiting: BH',
            '/stale — awaiting: BH',
            '/nothing — awaiting: NH, BH',
        ], $lines);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit tests/Test_Connect_Approvals_Round2.php`
Expected: FAIL — undefined method `compute_stale`.

- [ ] **Step 3: Implement**

In `includes/class-connect-approvals.php`, after the `STRIP_PARAMS` constant add:

```php
    /** Option: normalized paths currently flagged ready for review. */
    const READY_OPTION = 'peanut_connect_approvals_ready';

    /** Option: notification settings ['email' => string, 'digest' => bool]. */
    const NOTIFY_OPTION = 'peanut_connect_approvals_notify';
```

After the `public_votes` method add:

```php
    /**
     * A vote is stale when the page's post was edited after the vote. Votes
     * without a snapshot (3.33.0-era, or URLs that don't resolve to a post)
     * are never stale, and a page that no longer resolves can't invalidate
     * old votes.
     */
    public static function compute_stale(array $vote, string $current_modified): bool {
        $snapshot = isset($vote['post_modified']) ? (string) $vote['post_modified'] : '';
        return $snapshot !== '' && $current_modified !== '' && $snapshot !== $current_modified;
    }

    /** Annotate a public votes map with stale flags (and the new modified time when stale). */
    public static function apply_stale(array $votes, string $current_modified): array {
        foreach ($votes as $id => $vote) {
            if (! is_array($vote)) {
                continue;
            }
            $votes[$id]['stale'] = self::compute_stale($vote, $current_modified);
            if ($votes[$id]['stale']) {
                $votes[$id]['modified_at'] = $current_modified;
            }
        }
        return $votes;
    }

    /**
     * Fully approved = every configured approver holds a fresh YES.
     * $votes is the public projection with 'stale' already applied.
     * No approvers configured means never all-green.
     */
    public static function compute_all_green(array $approvers, array $votes): bool {
        if ($approvers === []) {
            return false;
        }
        foreach ($approvers as $row) {
            $vote = $votes[$row['id']] ?? null;
            if (! is_array($vote) || ($vote['vote'] ?? '') !== 'yes' || ! empty($vote['stale'])) {
                return false;
            }
        }
        return true;
    }

    /** Coerce notification settings to their exact shape. */
    public static function sanitize_notify_settings($raw): array {
        $raw = is_array($raw) ? $raw : [];
        return [
            'email'  => sanitize_email((string) ($raw['email'] ?? '')),
            'digest' => ! empty($raw['digest']),
        ];
    }

    /** Normalized, deduped ready-for-review path list. */
    public static function sanitize_ready_list($raw): array {
        $out = [];
        foreach ((is_array($raw) ? $raw : []) as $path) {
            $out[] = self::normalize_path($path);
        }
        return array_values(array_unique($out));
    }

    /**
     * Digest body lines: one per ready page still awaiting sign-off,
     * naming the approvers (by initials) whose fresh YES is missing.
     * Pages already fully approved produce no line.
     */
    public static function build_digest_lines(array $ready_paths, array $pages_votes, array $approvers): array {
        $lines = [];
        foreach ($ready_paths as $path) {
            $votes = $pages_votes[$path] ?? [];
            if (self::compute_all_green($approvers, $votes)) {
                continue;
            }
            $awaiting = [];
            foreach ($approvers as $row) {
                $vote = $votes[$row['id']] ?? null;
                if (! is_array($vote) || ($vote['vote'] ?? '') !== 'yes' || ! empty($vote['stale'])) {
                    $awaiting[] = $row['initials'];
                }
            }
            $lines[] = $path . ' — awaiting: ' . implode(', ', $awaiting);
        }
        return $lines;
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit tests/Test_Connect_Approvals_Round2.php`
Expected: PASS (10 tests). Then `/opt/homebrew/bin/php -l includes/class-connect-approvals.php` → clean, and `/opt/homebrew/bin/php vendor/bin/phpunit --testsuite Unit` → 212 tests, 1 skipped, green.

- [ ] **Step 5: Commit**

```bash
git add includes/class-connect-approvals.php tests/Test_Connect_Approvals_Round2.php
git commit -m "feat(approvals): round-2 pure helpers - staleness, all-green, notify settings, ready list, digest lines"
```

---

### Task 2: Vote snapshots, stale/ready in REST, the vote hook, ready auto-drop

**Files:**
- Modify: `includes/class-connect-approvals.php` (`record_vote` ~line 138; `get_state` ~line 243; `vote` ~line 276; new wrappers after `save_page_state` ~line 208)
- Test: `tests/Test_Connect_Approvals_Round2.php` (append)

**Interfaces:**
- Consumes: Task 1 helpers.
- Produces:
  - `record_vote(..., ?int $note_id = null, array $snapshot = [])` — snapshot keys `post_id` (int) and `post_modified` (string) stored on the vote.
  - `public static function ready_list(): array` / `public static function set_ready(string $path, bool $ready): array` (returns the new list)
  - REST additions Task 4/5 widgets rely on: `GET /approvals?path=…` → `{approvers, votes(with stale), ready: [paths], you: ''}` (you filled in Task 4); `GET /approvals` → `{approvers, pages(with stale), ready}`; vote response → `{success, votes(with stale), note_id, ready}`.
  - Action hook Task 3 consumes, EXACT signature:
    `do_action('peanut_connect_approvals_vote', string $path, array $approver, string $vote, string $reason, array $votes_with_stale, bool $became_all_green)`

- [ ] **Step 1: Append the failing tests**

Add inside `Test_Connect_Approvals_Round2`:

```php
    // ---- record_vote snapshot + ready option round-trip ----

    public function test_record_vote_stores_snapshot_fields(): void
    {
        $s = Peanut_Connect_Approvals::record_vote(
            ['votes' => [], 'history' => []],
            'nh', 'yes', '', 'b1', '2026-08-03 10:00:00', null,
            ['post_id' => 42, 'post_modified' => '2026-08-01 08:00:00']
        );
        $this->assertSame(42, $s['votes']['nh']['post_id']);
        $this->assertSame('2026-08-01 08:00:00', $s['votes']['nh']['post_modified']);
    }

    public function test_record_vote_without_snapshot_stores_empty_fields(): void
    {
        $s = Peanut_Connect_Approvals::record_vote(['votes' => [], 'history' => []], 'nh', 'yes', '', 'b1', '2026-08-03 10:00:00');
        $this->assertSame(0, $s['votes']['nh']['post_id']);
        $this->assertSame('', $s['votes']['nh']['post_modified']);
    }

    public function test_ready_list_round_trips_through_option(): void
    {
        $this->assertSame([], Peanut_Connect_Approvals::ready_list());
        $list = Peanut_Connect_Approvals::set_ready('/p?utm_source=x', true);
        $this->assertSame(['/p'], $list);
        $this->assertSame(['/p'], Peanut_Connect_Approvals::ready_list());
        $this->assertSame([], Peanut_Connect_Approvals::set_ready('/p', false));
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit tests/Test_Connect_Approvals_Round2.php`
Expected: FAIL — record_vote takes 7 args / undefined `ready_list`.

- [ ] **Step 3: Implement**

(a) `record_vote`: change the signature to
`public static function record_vote(array $state, string $approver_id, string $vote, string $reason, string $author_key, string $at, ?int $note_id = null, array $snapshot = []): array`
and extend the vote array literal with two lines:

```php
            'post_id'       => (int) ($snapshot['post_id'] ?? 0),
            'post_modified' => (string) ($snapshot['post_modified'] ?? ''),
```

(b) After `save_page_state` add the WP-touching wrappers (never called by unit tests) and ready accessors:

```php
    /**
     * Resolve a page path to its post + modified time (GMT). URLs that
     * don't map to a post return the empty snapshot — such pages simply
     * never go stale. Query strings never distinguish posts, so they're
     * dropped before resolving.
     */
    public static function page_snapshot(string $path): array {
        $bare = strtok($path, '?');
        $post_id = function_exists('url_to_postid') ? (int) url_to_postid(home_url($bare)) : 0;
        $modified = '';
        if ($post_id > 0) {
            $post = get_post($post_id);
            if ($post && ! empty($post->post_modified_gmt)) {
                $modified = (string) $post->post_modified_gmt;
            }
        }
        return ['post_id' => $post_id, 'post_modified' => $modified];
    }

    /** The page's CURRENT modified time, for staleness comparison on reads. */
    public static function current_modified(string $path): string {
        return self::page_snapshot($path)['post_modified'];
    }

    public static function ready_list(): array {
        return self::sanitize_ready_list(get_option(self::READY_OPTION, []));
    }

    /** Flag/unflag a page ready-for-review; returns the new list. */
    public static function set_ready(string $path, bool $ready): array {
        $path = self::normalize_path($path);
        $list = self::ready_list();
        $list = array_values(array_diff($list, [$path]));
        if ($ready) {
            $list[] = $path;
        }
        update_option(self::READY_OPTION, $list, false);
        return $list;
    }
```

(c) `get_state`: single-page branch becomes

```php
        if (is_string($path_raw) && $path_raw !== '') {
            $path  = self::normalize_path($path_raw);
            $state = self::page_state($path);
            return new \WP_REST_Response([
                'approvers' => $approvers,
                'votes'     => self::apply_stale(self::public_votes($state['votes']), self::current_modified($path)),
                'ready'     => self::ready_list(),
            ], 200);
        }
```

and the all-pages branch annotates each page the same way and adds ready:

```php
        $pages = [];
        foreach (self::all_pages_state() as $path => $state) {
            if ($state['votes'] !== []) {
                $pages[$path] = self::apply_stale(self::public_votes($state['votes']), self::current_modified($path));
            }
        }
        return new \WP_REST_Response(['approvers' => $approvers, 'pages' => $pages, 'ready' => self::ready_list()], 200);
```

(d) `vote()`: after validating the approver and before recording, capture `$was_green`; after saving, compute the new projection, fire the hook, auto-drop the ready flag. Replace the tail of the handler (from `$state = self::record_vote(` through the return) with:

```php
        $current_modified = self::current_modified($in['path']);
        $before = self::apply_stale(self::public_votes(self::page_state($in['path'])['votes']), $current_modified);
        $was_green = self::compute_all_green($approvers, $before);

        $state = self::record_vote(
            self::page_state($in['path']),
            $approver['id'],
            $in['vote'],
            $in['reason'],
            $in['author_key'],
            gmdate('Y-m-d H:i:s'),
            $note_id,
            self::page_snapshot($in['path'])
        );
        self::save_page_state($in['path'], $state);

        $votes = self::apply_stale(self::public_votes($state['votes']), $current_modified);
        $is_green = self::compute_all_green($approvers, $votes);
        $became_green = $is_green && ! $was_green;
        if ($is_green) {
            self::set_ready($in['path'], false); // sign-off complete: leave the queue
        }
        do_action('peanut_connect_approvals_vote', $in['path'], $approver, $in['vote'], $in['reason'], $votes, $became_green);

        return new \WP_REST_Response([
            'success' => true,
            'votes'   => $votes,
            'note_id' => $note_id,
            'ready'   => self::ready_list(),
        ], 200);
```

- [ ] **Step 4: Verify**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit tests/Test_Connect_Approvals_Round2.php` → PASS (13 tests).
`/opt/homebrew/bin/php -l includes/class-connect-approvals.php` → clean.
`/opt/homebrew/bin/php vendor/bin/phpunit --testsuite Unit` → 215 tests, 1 skipped, green.

- [ ] **Step 5: Commit**

```bash
git add includes/class-connect-approvals.php tests/Test_Connect_Approvals_Round2.php
git commit -m "feat(approvals): vote snapshots, stale+ready REST fields, vote hook, ready auto-drop"
```

---

### Task 3: Notifications module + admin settings + cron

**Files:**
- Create: `includes/class-connect-approvals-notify.php`
- Modify: `peanut-connect.php` (require after the approvals require ~line 104; `Peanut_Connect_Approvals_Notify::init();` after `Peanut_Connect_Approvals::init();` ~line 197)
- Modify: `includes/class-connect-approvals.php` (`render_admin_section` — settings fields; `handle_admin_post` — save + schedule)
- Test: `tests/Test_Connect_Approvals_Notify.php` (create)

**Interfaces:**
- Consumes: the Task 2 hook (exact signature above); Task 1 `sanitize_notify_settings`, `build_digest_lines`, `NOTIFY_OPTION`; `Peanut_Connect_Approvals::approvers()`, `all_pages_state()`, `ready_list()`, `apply_stale`, `public_votes`, `current_modified`.
- Produces:
  - `class Peanut_Connect_Approvals_Notify` with `init(): void`, `settings(): array`, `recipient(): string`, `schedule(bool $enable): void`, `unschedule(): void`, `on_vote($path, $approver, $vote, $reason, $votes, $became_all_green): void`, `send_digest(): void`
  - Pure mail builders (tested): `build_no_mail(string $site, string $path, string $name, string $reason, string $link): array{subject: string, body: string}`, `build_green_mail(string $site, string $path, string $link): array`, `build_digest_mail(string $site, array $lines): array`
  - `const CRON_HOOK = 'peanut_connect_approvals_digest';`

- [ ] **Step 1: Write the failing tests**

Create `tests/Test_Connect_Approvals_Notify.php`:

```php
<?php
/**
 * Pure mail-builder seams for approval notifications. Sending (wp_mail),
 * hook wiring, and cron scheduling are WP-runtime concerns verified on
 * staging; these tests pin the subject/body content.
 *
 * @package Peanut_Connect
 */

require_once dirname(__DIR__) . '/includes/class-connect-approvals.php';
require_once dirname(__DIR__) . '/includes/class-connect-approvals-notify.php';

class Test_Connect_Approvals_Notify extends Peanut_Connect_TestCase
{
    public function test_no_mail_carries_reason_and_link(): void
    {
        $mail = Peanut_Connect_Approvals_Notify::build_no_mail(
            'Peak Perks', '/pricing', 'Natty Hooper', 'Logo is wrong', 'https://x.test/pricing'
        );
        $this->assertSame('[Peak Perks] Changes requested on /pricing by Natty Hooper', $mail['subject']);
        $this->assertStringContainsString('Logo is wrong', $mail['body']);
        $this->assertStringContainsString('https://x.test/pricing', $mail['body']);
    }

    public function test_no_mail_without_reason_still_reads_sensibly(): void
    {
        $mail = Peanut_Connect_Approvals_Notify::build_no_mail('S', '/p', 'NH', '', 'https://x.test/p');
        $this->assertStringContainsString('No reason was given.', $mail['body']);
    }

    public function test_green_mail_names_the_page(): void
    {
        $mail = Peanut_Connect_Approvals_Notify::build_green_mail('Peak Perks', '/pricing', 'https://x.test/pricing');
        $this->assertSame('[Peak Perks] /pricing fully approved', $mail['subject']);
        $this->assertStringContainsString('https://x.test/pricing', $mail['body']);
    }

    public function test_digest_mail_lists_lines(): void
    {
        $mail = Peanut_Connect_Approvals_Notify::build_digest_mail('Peak Perks', ['/a — awaiting: NH', '/b — awaiting: NH, BH']);
        $this->assertSame('[Peak Perks] Pages awaiting approval: 2', $mail['subject']);
        $this->assertStringContainsString('/a — awaiting: NH', $mail['body']);
        $this->assertStringContainsString('/b — awaiting: NH, BH', $mail['body']);
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit tests/Test_Connect_Approvals_Notify.php`
Expected: FAIL — file `class-connect-approvals-notify.php` not found.

- [ ] **Step 3: Implement the notify class**

Create `includes/class-connect-approvals-notify.php`:

```php
<?php
/**
 * Approval notifications — plain-text email on client rejections and on a
 * page reaching full (fresh) approval, plus an optional daily digest of
 * pages still awaiting sign-off. Driven entirely by the
 * peanut_connect_approvals_vote action; mail failures never block a vote.
 *
 * @package Peanut_Connect
 */

if (! defined('ABSPATH')) {
    exit;
}

class Peanut_Connect_Approvals_Notify {

    /** Daily digest cron hook. Scheduled only while the digest is enabled. */
    const CRON_HOOK = 'peanut_connect_approvals_digest';

    public static function init(): void {
        add_action('peanut_connect_approvals_vote', [self::class, 'on_vote'], 10, 6);
        add_action(self::CRON_HOOK, [self::class, 'send_digest']);
    }

    public static function settings(): array {
        return Peanut_Connect_Approvals::sanitize_notify_settings(
            get_option(Peanut_Connect_Approvals::NOTIFY_OPTION, [])
        );
    }

    /** Configured address, falling back to the site admin email. */
    public static function recipient(): string {
        $settings = self::settings();
        return $settings['email'] !== '' ? $settings['email'] : (string) get_option('admin_email', '');
    }

    public static function schedule(bool $enable): void {
        if ($enable) {
            if (! wp_next_scheduled(self::CRON_HOOK)) {
                wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', self::CRON_HOOK);
            }
        } else {
            self::unschedule();
        }
    }

    public static function unschedule(): void {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    // ---- pure mail builders (unit-tested) ----

    public static function build_no_mail(string $site, string $path, string $name, string $reason, string $link): array {
        $body = $name . " requested changes on " . $path . ".\n\n"
            . ($reason !== '' ? "What needs to change:\n" . $reason . "\n\n" : "No reason was given.\n\n")
            . "Review the page:\n" . $link . "\n\n"
            . "The reason was also posted as a Mark It Up note on the page.";
        return [
            'subject' => '[' . $site . '] Changes requested on ' . $path . ' by ' . $name,
            'body'    => $body,
        ];
    }

    public static function build_green_mail(string $site, string $path, string $link): array {
        return [
            'subject' => '[' . $site . '] ' . $path . ' fully approved',
            'body'    => "Every approver has signed off on " . $path . " (all approvals current).\n\n"
                . "View the page:\n" . $link,
        ];
    }

    public static function build_digest_mail(string $site, array $lines): array {
        return [
            'subject' => '[' . $site . '] Pages awaiting approval: ' . count($lines),
            'body'    => "These pages are flagged ready for review and still awaiting sign-off:\n\n"
                . implode("\n", $lines)
                . "\n\nThis digest is sent daily while enabled on the Mark It Up admin page.",
        ];
    }

    // ---- runtime (staging-verified, not unit-tested) ----

    public static function on_vote($path, $approver, $vote, $reason, $votes, $became_all_green): void {
        $site = (string) get_bloginfo('name');
        $link = home_url((string) $path);
        if ($vote === 'no') {
            $mail = self::build_no_mail($site, (string) $path, (string) $approver['name'], (string) $reason, $link);
            self::send($mail);
        }
        if ($became_all_green) {
            self::send(self::build_green_mail($site, (string) $path, $link));
        }
    }

    public static function send_digest(): void {
        if (! self::settings()['digest']) {
            return;
        }
        $approvers = Peanut_Connect_Approvals::approvers();
        $pages = [];
        foreach (Peanut_Connect_Approvals::all_pages_state() as $path => $state) {
            $pages[$path] = Peanut_Connect_Approvals::apply_stale(
                Peanut_Connect_Approvals::public_votes($state['votes']),
                Peanut_Connect_Approvals::current_modified((string) $path)
            );
        }
        $lines = Peanut_Connect_Approvals::build_digest_lines(
            Peanut_Connect_Approvals::ready_list(),
            $pages,
            $approvers
        );
        if ($lines === []) {
            return;
        }
        self::send(self::build_digest_mail((string) get_bloginfo('name'), $lines));
    }

    private static function send(array $mail): void {
        $to = self::recipient();
        if ($to === '') {
            return;
        }
        if (! wp_mail($to, $mail['subject'], $mail['body'])) {
            error_log('peanut-connect approvals: notification mail failed (' . $mail['subject'] . ')');
        }
    }
}
```

- [ ] **Step 4: Wire the boot**

In `peanut-connect.php`: after the `class-connect-approvals.php` require add
`require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-approvals-notify.php';`
and after `Peanut_Connect_Approvals::init();` add
`Peanut_Connect_Approvals_Notify::init();`
Also grep `register_deactivation_hook` in `peanut-connect.php`: if a deactivation callback exists, add `Peanut_Connect_Approvals_Notify::unschedule();` inside it; if none exists, add after the activation/require block:

```php
register_deactivation_hook(__FILE__, ['Peanut_Connect_Approvals_Notify', 'unschedule']);
```

- [ ] **Step 5: Admin settings fields**

In `render_admin_section()` (class-connect-approvals.php), inside the existing form after the "Reset approvals" block's closing `</p>`, add:

```php
            <h3><?php esc_html_e('Notifications', 'peanut-connect'); ?></h3>
            <?php $notify = self::sanitize_notify_settings(get_option(self::NOTIFY_OPTION, [])); ?>
            <p>
                <label for="pca_notify_email"><?php esc_html_e('Send approval emails to', 'peanut-connect'); ?></label><br />
                <input type="email" id="pca_notify_email" name="pca_notify_email" class="regular-text" value="<?php echo esc_attr($notify['email']); ?>" placeholder="<?php echo esc_attr(get_option('admin_email', '')); ?>" />
            </p>
            <p class="description" style="max-width:640px"><?php esc_html_e('Immediate email when an approver requests changes, and when a page becomes fully approved. Blank uses the site admin email.', 'peanut-connect'); ?></p>
            <p>
                <label>
                    <input type="checkbox" name="pca_notify_digest" value="1" <?php checked($notify['digest']); ?> />
                    <?php esc_html_e('Also send a daily digest of pages still awaiting approval', 'peanut-connect'); ?>
                </label>
            </p>
```

In `handle_admin_post()`, in the fall-through save path (just before `update_option(self::APPROVERS_OPTION, ...)`), add:

```php
        $notify = self::sanitize_notify_settings([
            'email'  => sanitize_text_field(wp_unslash($_POST['pca_notify_email'] ?? '')),
            'digest' => ! empty($_POST['pca_notify_digest']),
        ]);
        update_option(self::NOTIFY_OPTION, $notify, false);
        if (class_exists('Peanut_Connect_Approvals_Notify')) {
            Peanut_Connect_Approvals_Notify::schedule($notify['digest']);
        }
```

- [ ] **Step 6: Verify**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit tests/Test_Connect_Approvals_Notify.php` → PASS (4 tests).
Lint all three changed PHP files with `/opt/homebrew/bin/php -l`.
Run: `/opt/homebrew/bin/php vendor/bin/phpunit --testsuite Unit` → 219 tests, 1 skipped, green.

- [ ] **Step 7: Commit**

```bash
git add includes/class-connect-approvals-notify.php includes/class-connect-approvals.php peanut-connect.php tests/Test_Connect_Approvals_Notify.php
git commit -m "feat(approvals): email notifications - NO votes, fully-approved, optional daily digest"
```

---

### Task 4: Per-approver links (pp_as)

**Files:**
- Modify: `includes/class-connect-approvals.php` (`STRIP_PARAMS` line 29; new `you_approver_id()` helper near `approvers()`; admin row link input in `render_admin_section`)
- Modify: `includes/class-connect-feedback.php` (`enqueue()` localize array — add `youApproverId`)
- Modify: `assets/js/feedback.js` (`pageKey()` strip list line 66; approvals block — you-ring + confirm step)
- Modify: `assets/css/feedback.css` (append `.pp-chip-you`)
- Test: `tests/Test_Connect_Approvals_Round2.php` (append)

**Interfaces:**
- Consumes: `approvers()` (existing), `cfg.youApproverId` (new localize key).
- Produces: `public static function you_approver_id(): string` — sanitized, validated against configured approvers, '' otherwise.

- [ ] **Step 1: Append the failing test**

`you_approver_id()` reads `$_GET`; test it via the mock-friendly pure part — add a pure validator and test THAT:

```php
    public function test_validate_approver_id_accepts_only_configured_ids(): void
    {
        $approvers = [['id' => 'nh', 'name' => 'N', 'initials' => 'NH']];
        $this->assertSame('nh', Peanut_Connect_Approvals::validate_approver_id('NH', $approvers));
        $this->assertSame('nh', Peanut_Connect_Approvals::validate_approver_id('nh', $approvers));
        $this->assertSame('', Peanut_Connect_Approvals::validate_approver_id('xx', $approvers));
        $this->assertSame('', Peanut_Connect_Approvals::validate_approver_id('', $approvers));
        $this->assertSame('', Peanut_Connect_Approvals::validate_approver_id(['nh'], $approvers));
    }
```

Run: `/opt/homebrew/bin/php vendor/bin/phpunit tests/Test_Connect_Approvals_Round2.php` — FAIL (undefined method).

- [ ] **Step 2: PHP implementation**

(a) `STRIP_PARAMS`: add `'pp_as'` as the third element: `['pp_review', 'pp_note', 'pp_as', 'utm_source', ...]`.

(b) After `approvers()` add:

```php
    /** Pure: an approver id is valid only when configured. Case-insensitive in, lowercase out. */
    public static function validate_approver_id($raw, array $approvers): string {
        if (! is_string($raw) || $raw === '') {
            return '';
        }
        $raw = strtolower($raw);
        foreach ($approvers as $row) {
            if ($row['id'] === $raw) {
                return $raw;
            }
        }
        return '';
    }

    /** The ?pp_as identity on this request, '' when absent or unknown. */
    public static function you_approver_id(): string {
        $raw = isset($_GET['pp_as']) ? sanitize_key(wp_unslash($_GET['pp_as'])) : '';
        return self::validate_approver_id($raw, self::approvers());
    }
```

(c) `class-connect-feedback.php` `enqueue()` localize array — add:

```php
            'youApproverId' => class_exists('Peanut_Connect_Approvals') ? Peanut_Connect_Approvals::you_approver_id() : '',
```

(d) Admin per-approver links — in `render_admin_section()`, the approver row's first `<td>` gains, after the name input (only when a token exists):

```php
                            <?php $review_token = (string) get_option('peanut_connect_feedback_review_token', ''); ?>
                            <?php if ($review_token !== '') : ?>
                                <br /><input type="text" class="large-text code" readonly onclick="this.select()"
                                    value="<?php echo esc_attr(add_query_arg(['pp_review' => $review_token, 'pp_as' => $row['id']], home_url('/'))); ?>"
                                    aria-label="<?php esc_attr_e('Personal review link', 'peanut-connect'); ?>" />
                            <?php endif; ?>
```

(Move the `$review_token` lookup ABOVE the `foreach` loop so it isn't re-fetched per row.)

- [ ] **Step 3: Widget implementation**

(a) `pageKey()` strip list (assets/js/feedback.js line 66): add `'pp_as'` after `'pp_note'`.

(b) In the approvals block: after `const approvers = ...` add `const youId = typeof cfg.youApproverId === 'string' ? cfg.youApproverId : '';`

(c) `renderApprovals()`: when building each chip add

```js
      if (youId && ap.id === youId) b.classList.add('pp-chip-you');
```

(d) `askApprove(ap)`: prepend an identity check — replace the function's first line so it becomes:

```js
  function askApprove(ap) {
    if (youId && ap.id !== youId) { confirmIdentity(ap); return; }
    askApproveFlow(ap);
  }
  function askApproveFlow(ap) {
    /* ...existing askApprove body unchanged... */
  }
  function confirmIdentity(ap) {
    const flow = panel.querySelector('.pp-approve-flow');
    flow.innerHTML = ''; flow.hidden = false;
    const q = document.createElement('div'); q.className = 'pp-approve-q';
    q.textContent = "You're voting as " + ap.name + ' — continue?';
    const go = document.createElement('button'); go.type = 'button'; go.className = 'pp-approve-btn pp-approve-yes'; go.textContent = 'Continue';
    const stop = document.createElement('button'); stop.type = 'button'; stop.className = 'pp-approve-btn pp-approve-no'; stop.textContent = 'Cancel';
    const row = document.createElement('div'); row.className = 'pp-approve-row'; row.append(go, stop);
    flow.append(q, row);
    go.addEventListener('click', () => askApproveFlow(ap));
    stop.addEventListener('click', () => hideApproveFlow());
  }
```

(e) CSS append:

```css
.pp-chip-you { outline: 2px solid #1F2937; outline-offset: 2px; }
```

- [ ] **Step 4: Verify**

`/opt/homebrew/bin/php vendor/bin/phpunit tests/Test_Connect_Approvals_Round2.php` → PASS (14 tests).
`/opt/homebrew/bin/php -l` both PHP files; `node --check assets/js/feedback.js`; `wc -l assets/js/feedback.js` (~765, under 900).
`/opt/homebrew/bin/php vendor/bin/phpunit --testsuite Unit` → 220 tests, 1 skipped, green.

- [ ] **Step 5: Commit**

```bash
git add includes/class-connect-approvals.php includes/class-connect-feedback.php assets/js/feedback.js assets/css/feedback.css tests/Test_Connect_Approvals_Round2.php
git commit -m "feat(approvals): per-approver links - pp_as identity, you-chip, wrong-chip confirm"
```

---

### Task 5: Ready-for-review — route, widget toggle, sign-off queue, admin list

**Files:**
- Modify: `includes/class-connect-approvals.php` (`register_routes` — add `/approvals/ready`; new `ready()` handler after `reset()`; admin ready list in `render_admin_section` + `handle_admin_post`)
- Modify: `assets/js/feedback.js` (approvals block + `loadSummary`)
- Modify: `assets/css/feedback.css` (append)
- Test: `tests/Test_Connect_Approvals_Round2.php` (append)

**Interfaces:**
- Consumes: `set_ready`/`ready_list` (Task 2), `ready` field in GET/vote responses (Task 2), `youId` (Task 4), `compute_all_green` semantics.
- Produces: `POST /peanut-connect/v1/approvals/ready` `{path, ready:bool}` → `{success:true, ready:[paths]}`, permission `can_review_agency`.

- [ ] **Step 1: Append the failing test (route input coercion — pure)**

```php
    public function test_build_ready_input_coerces(): void
    {
        $in = Peanut_Connect_Approvals::build_ready_input(['path' => '/p?utm_source=x', 'ready' => '1', 'evil' => 'x']);
        $this->assertSame(['path' => '/p', 'ready' => true], $in);
        $this->assertSame(['path' => '/', 'ready' => false], Peanut_Connect_Approvals::build_ready_input([]));
    }
```

Run — FAIL (undefined method).

- [ ] **Step 2: PHP implementation**

(a) In `register_routes()` add:

```php
        // Flagging a page ready-for-review is an agency action.
        register_rest_route($ns, '/approvals/ready', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'ready'],
            'permission_callback' => ['Peanut_Connect_Feedback', 'can_review_agency'],
        ]);
```

(b) After `reset()` add:

```php
    /** Whitelist + coerce a ready request body. Pure — unit-tested. */
    public static function build_ready_input(array $req): array {
        return [
            'path'  => self::normalize_path($req['path'] ?? null),
            'ready' => ! empty($req['ready']),
        ];
    }

    public static function ready(\WP_REST_Request $request) {
        $in = self::build_ready_input($request->get_json_params() ?: []);
        return new \WP_REST_Response([
            'success' => true,
            'ready'   => self::set_ready($in['path'], $in['ready']),
        ], 200);
    }
```

(c) Admin — in `render_admin_section()` after the Notifications block add:

```php
            <h3><?php esc_html_e('Ready for review', 'peanut-connect'); ?></h3>
            <?php $ready_paths = self::ready_list(); ?>
            <?php if (empty($ready_paths)) : ?>
                <p class="description"><?php esc_html_e('No pages are currently flagged. Agency users flag pages from the widget ("Request approval").', 'peanut-connect'); ?></p>
            <?php else : ?>
                <ul>
                    <?php foreach ($ready_paths as $i => $ready_path) : ?>
                        <li>
                            <code><?php echo esc_html($ready_path); ?></code>
                            <button type="submit" name="pca_action" value="unready-<?php echo esc_attr((string) $i); ?>" class="button button-small"><?php esc_html_e('Unflag', 'peanut-connect'); ?></button>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
```

In `handle_admin_post()` add a branch (alongside `remove-`/`up-`/`down-`):

```php
        } elseif (preg_match('/^unready-(\d+)$/', $action, $m)) {
            $ready_paths = self::ready_list();
            if (isset($ready_paths[(int) $m[1]])) {
                self::set_ready($ready_paths[(int) $m[1]], false);
            }
            return __('Page unflagged.', 'peanut-connect');
```

(NOTE: this branch `return`s before the approvers `update_option` — intentional, an unflag click must not also rewrite the approver rows from the same POST since they're included; actually the rows ARE included and unchanged, but returning early keeps the action single-purpose. Keep the return.)

- [ ] **Step 3: Widget implementation**

(a) In the approvals block state: `let readyList = [];` — and in `loadApprovals()` and `sendVote()` success handlers set `readyList = (res && res.ready) || [];` after setting votes. In `renderApprovals()`, when `cfg.isAgency` is truthy, append after the chips row:

```js
    if (cfg.isAgency) {
      const isReady = readyList.indexOf(pageKey()) !== -1;
      const rb = document.createElement('button');
      rb.type = 'button';
      rb.className = 'pp-ready-btn' + (isReady ? ' pp-on' : '');
      rb.textContent = isReady ? 'Requested — undo' : 'Request approval';
      rb.addEventListener('click', () => {
        api('POST', '/approvals/ready', { path: pageKey(), ready: !isReady })
          .then((res) => { if (res && res.success) { readyList = res.ready || []; renderApprovals(); } });
      });
      box.appendChild(rb);
    }
```

(The strip is hidden when no approvers are configured, so the toggle is too — the ready queue is meaningless without approvers; this matches the spec's degradation table.)

(b) `loadSummary()` — before rendering `notePages`, insert a "Needs your sign-off" section. After `box.innerHTML = '';` add:

```js
      const ready = (appr && appr.ready) || [];
      const needs = ready.filter((p) => {
        if (!/^\/(?!\/)/.test(p)) return false;
        const votes = apprPages[p] || {};
        if (youId) {
          const v = votes[youId];
          return !v || v.vote !== 'yes' || v.stale;
        }
        return !approvers.length || !approvers.every((ap) => { const v = votes[ap.id]; return v && v.vote === 'yes' && !v.stale; });
      });
      if (needs.length) {
        const head = document.createElement('div'); head.className = 'pp-sw-page pp-needs-head';
        head.textContent = youId ? 'Needs your sign-off' : 'Awaiting approval';
        box.appendChild(head);
        needs.forEach((p) => {
          const a = document.createElement('a'); a.className = 'pp-sw-note'; a.textContent = p; a.href = p;
          box.appendChild(a);
        });
      }
```

(c) Stale chips — in `approvalChipsRow()` and `renderApprovals()`, extend the class/tooltip logic: a vote with `v.stale` renders class `pp-chip-stale` (instead of yes/no color) when `v.vote === 'yes'`, and the title gains `' · page changed ' + apprDate(v.modified_at)`. Exact change in both places:

```js
      const cls = v ? (v.vote === 'yes' ? (v.stale ? ' pp-chip-stale' : ' pp-chip-yes') : ' pp-chip-no') : '';
      // title:
      // v.vote==='yes' && v.stale -> ap.name + ' — Approved · ' + apprDate(v.at) + ' · page changed ' + apprDate(v.modified_at)
```

(d) CSS append:

```css
.pp-chip-stale { background: #D97706; }
.pp-ready-btn { margin-top: 6px; border: 1px solid #1F2937; border-radius: 4px; background: #fff; color: #1F2937; font-weight: 600; font-size: 11px; padding: 3px 10px; cursor: pointer; }
.pp-ready-btn.pp-on { background: #1F2937; color: #fff; }
.pp-needs-head { font-weight: 700; }
```

- [ ] **Step 4: Verify**

`/opt/homebrew/bin/php vendor/bin/phpunit tests/Test_Connect_Approvals_Round2.php` → PASS (15 tests).
`node --check assets/js/feedback.js`; `wc -l assets/js/feedback.js` (~815 — if over 900 STOP per Global Constraints).
`/opt/homebrew/bin/php -l includes/class-connect-approvals.php`.
Full Unit suite → 221 tests, 1 skipped, green.

- [ ] **Step 5: Commit**

```bash
git add includes/class-connect-approvals.php assets/js/feedback.js assets/css/feedback.css tests/Test_Connect_Approvals_Round2.php
git commit -m "feat(approvals): ready-for-review flag, sign-off queue, stale chip rendering"
```

---

### Task 6: Sign-off record view + walkthrough line

**Files:**
- Modify: `includes/class-connect-approvals.php` (new `render_record_view()`; link in `render_admin_section`)
- Modify: `includes/class-connect-feedback.php` (`render_admin_page` — early branch to the record view)
- Modify: `assets/js/feedback.js` (help `<ol>` — one `<li>`)

**Interfaces:**
- Consumes: `all_pages_state`, `approvers`, `apply_stale`, `public_votes`, `current_modified`, `ready_list`, `compute_all_green`.
- Produces: `public static function render_record_view(): void`.

- [ ] **Step 1: Route the view**

In `class-connect-feedback.php` `render_admin_page()`, immediately after the `current_user_can('manage_options')` guard add:

```php
        if (isset($_GET['pca_view']) && $_GET['pca_view'] === 'record' && class_exists('Peanut_Connect_Approvals')) {
            Peanut_Connect_Approvals::render_record_view();
            return;
        }
```

- [ ] **Step 2: Implement the record view**

Add to `class-connect-approvals.php`:

```php
    /**
     * Printable sign-off record: per page, the approver grid and full
     * history. Read-only, manage_options (guarded by the caller too).
     * Print CSS + window.print() = the PDF export.
     */
    public static function render_record_view(): void {
        if (! current_user_can('manage_options')) {
            return;
        }
        $approvers = self::approvers();
        $ready     = self::ready_list();
        $pages     = self::all_pages_state();
        ksort($pages);
        ?>
        <div class="wrap pca-record">
            <style>
                .pca-record table { border-collapse: collapse; margin: 8px 0 20px; }
                .pca-record th, .pca-record td { border: 1px solid #C3C4C7; padding: 4px 10px; text-align: left; font-size: 12px; }
                .pca-record h2 { margin-top: 28px; }
                .pca-record .pca-stale { color: #B45309; }
                @media print {
                    #adminmenumain, #wpadminbar, #wpfooter, .pca-noprint { display: none !important; }
                    #wpcontent, #wpbody-content { margin: 0 !important; padding: 0 !important; }
                }
            </style>
            <h1><?php echo esc_html(sprintf(__('%s — Mark It Up sign-off record', 'peanut-connect'), get_bloginfo('name'))); ?></h1>
            <p><?php echo esc_html(sprintf(__('Generated %s (UTC)', 'peanut-connect'), gmdate('Y-m-d H:i'))); ?></p>
            <p class="pca-noprint">
                <button type="button" class="button button-primary" onclick="window.print()"><?php esc_html_e('Print / save as PDF', 'peanut-connect'); ?></button>
                <a class="button" href="<?php echo esc_url(remove_query_arg('pca_view')); ?>"><?php esc_html_e('Back to Mark It Up', 'peanut-connect'); ?></a>
            </p>
            <?php if (empty($pages)) : ?>
                <p><?php esc_html_e('No approval activity recorded yet.', 'peanut-connect'); ?></p>
            <?php endif; ?>
            <?php foreach ($pages as $path => $state) : ?>
                <?php
                $votes  = self::apply_stale(self::public_votes($state['votes']), self::current_modified((string) $path));
                $status = self::compute_all_green($approvers, $votes)
                    ? __('Fully approved', 'peanut-connect')
                    : (in_array($path, $ready, true) ? __('Awaiting approval', 'peanut-connect') : __('In review', 'peanut-connect'));
                ?>
                <h2><?php echo esc_html((string) $path); ?></h2>
                <p><strong><?php echo esc_html($status); ?></strong></p>
                <table>
                    <thead><tr>
                        <th><?php esc_html_e('Approver', 'peanut-connect'); ?></th>
                        <th><?php esc_html_e('Decision', 'peanut-connect'); ?></th>
                        <th><?php esc_html_e('Date (UTC)', 'peanut-connect'); ?></th>
                        <th><?php esc_html_e('Notes', 'peanut-connect'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($approvers as $row) : ?>
                        <?php $vote = $votes[$row['id']] ?? null; ?>
                        <tr>
                            <td><?php echo esc_html($row['name'] . ' (' . $row['initials'] . ')'); ?></td>
                            <td><?php echo esc_html($vote ? (($vote['vote'] ?? '') === 'yes' ? __('Approved', 'peanut-connect') : __('Changes requested', 'peanut-connect')) : __('No response', 'peanut-connect')); ?></td>
                            <td><?php echo esc_html($vote['at'] ?? ''); ?></td>
                            <td>
                                <?php if ($vote && ! empty($vote['stale'])) : ?>
                                    <span class="pca-stale"><?php echo esc_html(sprintf(__('Page changed after this decision (%s)', 'peanut-connect'), (string) ($vote['modified_at'] ?? ''))); ?></span>
                                <?php endif; ?>
                                <?php echo esc_html((string) ($vote['reason'] ?? '')); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (! empty($state['history'])) : ?>
                <table>
                    <thead><tr>
                        <th><?php esc_html_e('Time (UTC)', 'peanut-connect'); ?></th>
                        <th><?php esc_html_e('Approver', 'peanut-connect'); ?></th>
                        <th><?php esc_html_e('Action', 'peanut-connect'); ?></th>
                        <th><?php esc_html_e('Reason', 'peanut-connect'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($state['history'] as $entry) : ?>
                        <tr>
                            <td><?php echo esc_html((string) ($entry['at'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($entry['approver_id'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($entry['action'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($entry['reason'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php
    }
```

- [ ] **Step 3: Link it + walkthrough**

In `render_admin_section()`, right after the `<h2>Approvers</h2>` notice block add:

```php
        <p><a class="button" href="<?php echo esc_url(add_query_arg('pca_view', 'record')); ?>"><?php esc_html_e('View sign-off record', 'peanut-connect'); ?></a></p>
```

In `assets/js/feedback.js`, in the help `<ol>` (panel.innerHTML), add before the closing `</ol>`:

```js
    '<li>If you are an <strong>approver</strong>, click your initials at the top to approve the page — or tell us what needs to change.</li>' +
```

- [ ] **Step 4: Verify**

`/opt/homebrew/bin/php -l` both PHP files; `node --check assets/js/feedback.js`; full Unit suite → 221 tests, 1 skipped, green.

- [ ] **Step 5: Commit**

```bash
git add includes/class-connect-approvals.php includes/class-connect-feedback.php assets/js/feedback.js
git commit -m "feat(approvals): printable sign-off record + walkthrough approve step"
```

---

### Task 7: Version 3.34.0 + changelog

**Files:**
- Modify: `peanut-connect.php` (line 6 `* Version:`, line 20 define), `readme.txt` (line 7 `Stable tag:`), `CHANGELOG.md` (new top section above `## [3.33.0]`)

- [ ] **Step 1: Bump all three version strings to `3.34.0`.**

- [ ] **Step 2: Changelog entry** (directly above `## [3.33.0]`):

```markdown
## [3.34.0] - 2026-08-03

### Added
- **Approval notifications.** Plain-text email (configurable address, default: site admin) the moment an approver requests changes, and when a page reaches full approval; optional daily digest of pages still awaiting sign-off (`peanut_connect_approvals_notify`, WP-Cron `peanut_connect_approvals_digest`).
- **Stale-approval detection.** Votes snapshot the page's modified time; editing the page afterwards turns that approval amber ("page changed after this decision") in the widget, the All-pages rollup, and the sign-off record. Fully-approved status requires fresh approvals.
- **Per-approver links.** Each approver gets a personal review link (`&pp_as=<id>`, shown on the admin page): their chip is highlighted as "you" and voting as someone else asks for confirmation first.
- **Ready for review.** Agency users flag a page "Request approval" from the widget; approvers see a "Needs your sign-off" queue in All-pages; the flag clears automatically when the page is fully approved (`peanut_connect_approvals_ready`). Admin page lists and unflags.
- **Printable sign-off record.** "View sign-off record" on the Mark It Up admin page renders every page's approver grid and full history with staleness annotations; print to PDF.
- The widget walkthrough now explains the approve step.
```

- [ ] **Step 3: Verify** — `/opt/homebrew/bin/php -l peanut-connect.php`; full Unit suite green.

- [ ] **Step 4: Commit**

```bash
git add peanut-connect.php readme.txt CHANGELOG.md
git commit -m "chore(release): peanut-connect 3.34.0 - approvals round 2"
```

---

### Task 8: Full verification + stacked draft PR

**Files:** none.

- [ ] **Step 1:** `/opt/homebrew/bin/php vendor/bin/phpunit --testsuite Unit && /opt/homebrew/bin/php vendor/bin/phpunit --testsuite Property` → green.
- [ ] **Step 2:** `node --check assets/js/feedback.js`; `php -l` on all changed PHP; `wc -l assets/js/feedback.js` (< 900).
- [ ] **Step 3:** Manual staging checklist (record pass/fail in the PR body; full pass happens together with the 3.33.0 staging pass): NO vote → email with reason arrives; second YES completing the set → one "fully approved" email; edit the page → chip goes amber, all-green email does NOT re-fire until a fresh YES; pp_as link highlights "you" + confirm on other chips; ready toggle → "Needs your sign-off" queue → auto-drop on full approval; sign-off record prints cleanly; digest (enable + `wp cron event run peanut_connect_approvals_digest`) lists awaiting pages.
- [ ] **Step 4:** Push + stacked draft PR:

```bash
git push -u origin feat/mark-it-up-approvals-round2-3.34.0
gh pr create --draft --base feat/mark-it-up-approvals-3.33.0 --title "feat: Mark It Up approvals round 2 - notifications, staleness, ready queue (3.34.0)" --body "..."
```

PR body: changelog summary, spec link, staging checklist, note that this PR is STACKED on #98 (merges after it; GitHub retargets; `branches: [main]` workflows won't fire until retarget — per standing stacked-PR mechanics). Title starts `feat:`.

---

## Self-Review Notes

- Spec coverage: notifications incl. digest + settings + cron lifecycle (Task 3), staleness snapshot/read/render incl. record view (Tasks 2, 5, 6), pp_as links + strip lists + admin links + confirm step (Task 4), ready flag route/toggle/queue/auto-drop/admin (Tasks 2, 5), sign-off record + print (Task 6), walkthrough (Task 6), version + changelog (Task 7), stacked-PR handling (Task 8). Deferred per spec: HUB standup feed.
- Type consistency: `record_vote` snapshot param shape matches `page_snapshot()` return; hook signature identical between Task 2 (producer) and Task 3 (consumer); `ready` REST field name consistent across GET/vote/ready responses and both widget consumers.
- The Task 5 admin `unready-` branch early-returns by design (noted inline).
