# Mark It Up Approval Process Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Per-page client approval chips ("Click your initials to approve" → YES/NO with green/red state, timestamped history, NO reasons become Mark It Up notes) in the Mark It Up widget, plus admin-managed approvers and reset controls.

**Architecture:** New `includes/class-connect-approvals.php` owns approver/approval storage (two WP options), pure decision helpers, three REST routes reusing the feedback module's permission gates, and the admin "Approvers" section rendered inside the existing Mark It Up admin page. The widget UI extends the existing shadow-root panel in `assets/js/feedback.js` (+ CSS). NO reasons post as real notes through a new public bridge on the feedback class.

**Tech Stack:** WordPress plugin PHP 8 (PHPUnit 9.6 standalone mocks in `tests/mocks/wordpress-mocks.php`), vanilla-JS single-file widget in a shadow root, no build step.

**Spec:** `docs/superpowers/specs/2026-08-03-mark-it-up-approvals-design.md` — the spec governs on any conflict.

## Global Constraints

- Option names EXACTLY: `peanut_connect_approvers` (array) and `peanut_connect_approvals` (map keyed by normalized path).
- Vote values EXACTLY `'yes'` | `'no'`; anything else coerces to `'yes'` refused — see `record_vote` (unknown input is treated as `'yes'` only after the REST layer has coerced; the REST layer maps `vote !== 'no'` → `'yes'`).
- Timestamps: `gmdate('Y-m-d H:i:s')` (UTC), stored on every vote AND every history entry — **re-approvals must always carry a fresh date/time**.
- History is append-only, capped at the **last 200 entries per page**.
- Permission gates REUSE `Peanut_Connect_Feedback::can_review` (vote/read) and `::can_review_agency` (reset). Access mode `'off'` blocks everything (it already does, inside `can_review`).
- No HUB changes. No schema migrations — options only, `update_option(..., false)` (no autoload).
- No emoji anywhere (UI or code). Inline SVG only if iconography is needed. Text domain `'peanut-connect'`; escape all admin output (`esc_html`, `esc_attr`, `checked()`, `selected()`).
- Widget stays dependency-free vanilla JS; no native dialogs for the new UI; `feedback.js` hard ceiling ~900 lines (currently 607) — if a task would cross it, STOP and split the approvals UI into `assets/js/approvals.js` per spec.
- All changed PHP passes `/opt/homebrew/bin/php -l`.
- Version ships as **3.33.0** (MINOR from 3.32.1). Do the bump ONLY in Task 7.
- Baseline test state (verified in this worktree): `/opt/homebrew/bin/php vendor/bin/phpunit --testsuite Unit` = **180 tests, 738 assertions, 1 skipped, OK**. Never regress it. (Ignore the harmless local `redis.so` startup warning and PHP 8.5 `setAccessible` deprecations.)
- Working copy: the worktree at `.claude/worktrees/mark-it-up-approvals` (branch `worktree-mark-it-up-approvals`; renamed to `feat/mark-it-up-approvals-3.33.0` in Task 7). `vendor/` was installed via `composer install` — do NOT symlink vendor from the main checkout (its classmap loads main-checkout classes and fatals with "Cannot redeclare class").

---

### Task 1: Approvals core — options, pure helpers, state accessors

**Files:**
- Create: `includes/class-connect-approvals.php`
- Test: `tests/Test_Connect_Approvals.php` (create)

**Interfaces:**
- Consumes: nothing from other tasks (pure PHP + `get_option`/`update_option` mocks).
- Produces (later tasks rely on these exact signatures):
  - `const APPROVERS_OPTION = 'peanut_connect_approvers';`
  - `const APPROVALS_OPTION = 'peanut_connect_approvals';`
  - `const HISTORY_CAP = 200;`
  - `public static function sanitize_approvers($raw): array` — rows of `['id'=>string,'name'=>string,'initials'=>string]`
  - `public static function approvers(): array`
  - `public static function normalize_path($raw): string`
  - `public static function normalize_page_state($raw): array` — `['votes'=>[], 'history'=>[]]`
  - `public static function record_vote(array $state, string $approver_id, string $vote, string $reason, string $author_key, string $at, ?int $note_id = null): array`
  - `public static function apply_reset(array $state, ?string $approver_id, string $author_key, string $at): array`
  - `public static function public_votes(array $votes): array` — votes with `author_key` stripped
  - `public static function page_state(string $path): array`
  - `public static function all_pages_state(): array` — `path => normalized page state`
  - `public static function save_page_state(string $path, array $state): void`

- [ ] **Step 1: Write the failing tests**

Create `tests/Test_Connect_Approvals.php`:

```php
<?php
/**
 * Tests for the pure approval-decision seams: approver sanitization, path
 * normalization, vote recording (incl. re-vote timestamping and the history
 * cap), resets, and the public vote projection. Live-WP wrappers are
 * exercised on staging; these pin the decision logic.
 *
 * @package Peanut_Connect
 */

require_once dirname(__DIR__) . '/includes/class-connect-approvals.php';

class Test_Connect_Approvals extends Peanut_Connect_TestCase
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

    // ---- sanitize_approvers ----

    public function test_sanitize_approvers_builds_ids_and_initials(): void
    {
        $rows = Peanut_Connect_Approvals::sanitize_approvers([
            ['name' => 'Natty Hooper'],
            ['name' => 'Bob Hill', 'initials' => 'bh!'],
        ]);
        $this->assertCount(2, $rows);
        $this->assertSame('natty-hooper', $rows[0]['id']);
        $this->assertSame('NH', $rows[0]['initials']);
        $this->assertSame('BH', $rows[1]['initials']); // cleaned + uppercased
    }

    public function test_sanitize_approvers_drops_empty_and_junk_rows(): void
    {
        $this->assertSame([], Peanut_Connect_Approvals::sanitize_approvers('nope'));
        $this->assertSame([], Peanut_Connect_Approvals::sanitize_approvers([['name' => '  '], 'x', 7]));
    }

    public function test_sanitize_approvers_keeps_existing_ids_and_dedupes_new_ones(): void
    {
        $rows = Peanut_Connect_Approvals::sanitize_approvers([
            ['id' => 'nh', 'name' => 'Natty Hooper', 'initials' => 'NH'],
            ['name' => 'Nina Harris'],   // derives NH initials but must get a UNIQUE id
            ['name' => 'Nina Harris'],   // duplicate name -> suffixed id
        ]);
        $ids = array_column($rows, 'id');
        $this->assertSame('nh', $ids[0]);
        $this->assertCount(3, array_unique($ids));
        $this->assertSame('NH', $rows[1]['initials']);
    }

    public function test_sanitize_approvers_caps_initials_at_three_chars(): void
    {
        $rows = Peanut_Connect_Approvals::sanitize_approvers([
            ['name' => 'Alpha Beta Gamma Delta'],
        ]);
        $this->assertSame('ABG', $rows[0]['initials']);
    }

    // ---- normalize_path ----

    public function test_normalize_path_accepts_plain_paths_and_keeps_real_queries(): void
    {
        $this->assertSame('/', Peanut_Connect_Approvals::normalize_path('/'));
        $this->assertSame('/pricing', Peanut_Connect_Approvals::normalize_path('/pricing'));
        $this->assertSame('/p?tab=2', Peanut_Connect_Approvals::normalize_path('/p?tab=2'));
    }

    public function test_normalize_path_strips_tracking_and_review_params(): void
    {
        $this->assertSame(
            '/p?tab=2',
            Peanut_Connect_Approvals::normalize_path('/p?pp_review=abc&tab=2&utm_source=x&pp_note=9&gclid=1')
        );
        $this->assertSame('/p', Peanut_Connect_Approvals::normalize_path('/p?pp_review=abc'));
    }

    public function test_normalize_path_rejects_non_paths(): void
    {
        $this->assertSame('/', Peanut_Connect_Approvals::normalize_path('//evil.example'));
        $this->assertSame('/', Peanut_Connect_Approvals::normalize_path('https://evil.example/x'));
        $this->assertSame('/', Peanut_Connect_Approvals::normalize_path(''));
        $this->assertSame('/', Peanut_Connect_Approvals::normalize_path(['x']));
    }

    // ---- record_vote / history ----

    public function test_record_vote_stores_latest_vote_with_timestamp(): void
    {
        $s = Peanut_Connect_Approvals::record_vote(
            ['votes' => [], 'history' => []],
            'nh', 'yes', '', 'browser-1', '2026-08-03 10:00:00'
        );
        $this->assertSame('yes', $s['votes']['nh']['vote']);
        $this->assertSame('2026-08-03 10:00:00', $s['votes']['nh']['at']);
        $this->assertSame('browser-1', $s['votes']['nh']['author_key']);
        $this->assertNull($s['votes']['nh']['note_id']);
        $this->assertCount(1, $s['history']);
        $this->assertSame('yes', $s['history'][0]['action']);
        $this->assertSame('2026-08-03 10:00:00', $s['history'][0]['at']);
    }

    public function test_revote_replaces_latest_but_history_keeps_both_timestamps(): void
    {
        $s = Peanut_Connect_Approvals::record_vote(['votes' => [], 'history' => []], 'nh', 'no', 'fix header', 'b1', '2026-08-03 10:00:00', 41);
        $s = Peanut_Connect_Approvals::record_vote($s, 'nh', 'yes', '', 'b1', '2026-08-04 09:30:00');
        $this->assertSame('yes', $s['votes']['nh']['vote']);
        $this->assertSame('2026-08-04 09:30:00', $s['votes']['nh']['at']); // re-approval date/time logged
        $this->assertSame('', $s['votes']['nh']['reason']);
        $this->assertCount(2, $s['history']);
        $this->assertSame('no', $s['history'][0]['action']);
        $this->assertSame('fix header', $s['history'][0]['reason']);
        $this->assertSame('yes', $s['history'][1]['action']);
        $this->assertSame('2026-08-04 09:30:00', $s['history'][1]['at']);
    }

    public function test_no_vote_records_reason_and_note_id(): void
    {
        $s = Peanut_Connect_Approvals::record_vote(['votes' => [], 'history' => []], 'nh', 'no', 'wrong logo', 'b1', '2026-08-03 10:00:00', 77);
        $this->assertSame('no', $s['votes']['nh']['vote']);
        $this->assertSame('wrong logo', $s['votes']['nh']['reason']);
        $this->assertSame(77, $s['votes']['nh']['note_id']);
    }

    public function test_history_is_capped_at_200(): void
    {
        $s = ['votes' => [], 'history' => []];
        for ($i = 0; $i < 205; $i++) {
            $s = Peanut_Connect_Approvals::record_vote($s, 'nh', 'yes', '', 'b1', sprintf('2026-08-03 10:%02d:%02d', intdiv($i, 60), $i % 60));
        }
        $this->assertCount(200, $s['history']);
        // Oldest entries were dropped: the first surviving entry is #5.
        $this->assertSame('2026-08-03 10:00:05', $s['history'][0]['at']);
    }

    // ---- apply_reset ----

    public function test_reset_all_clears_votes_and_logs_history(): void
    {
        $s = Peanut_Connect_Approvals::record_vote(['votes' => [], 'history' => []], 'nh', 'yes', '', 'b1', '2026-08-03 10:00:00');
        $s = Peanut_Connect_Approvals::record_vote($s, 'bh', 'no', 'x', 'b2', '2026-08-03 10:01:00');
        $s = Peanut_Connect_Approvals::apply_reset($s, null, 'admin', '2026-08-03 11:00:00');
        $this->assertSame([], $s['votes']);
        $this->assertSame('reset', $s['history'][2]['action']);
        $this->assertSame('2026-08-03 11:00:00', $s['history'][2]['at']);
    }

    public function test_reset_single_approver_leaves_others(): void
    {
        $s = Peanut_Connect_Approvals::record_vote(['votes' => [], 'history' => []], 'nh', 'yes', '', 'b1', '2026-08-03 10:00:00');
        $s = Peanut_Connect_Approvals::record_vote($s, 'bh', 'no', 'x', 'b2', '2026-08-03 10:01:00');
        $s = Peanut_Connect_Approvals::apply_reset($s, 'nh', 'admin', '2026-08-03 11:00:00');
        $this->assertArrayNotHasKey('nh', $s['votes']);
        $this->assertSame('no', $s['votes']['bh']['vote']);
        $this->assertSame('nh', $s['history'][2]['approver_id']);
    }

    // ---- normalization + projection ----

    public function test_normalize_page_state_swallows_junk(): void
    {
        $this->assertSame(['votes' => [], 'history' => []], Peanut_Connect_Approvals::normalize_page_state('garbage'));
        $this->assertSame(['votes' => [], 'history' => []], Peanut_Connect_Approvals::normalize_page_state(['votes' => 'x', 'history' => 9]));
    }

    public function test_public_votes_strips_author_key(): void
    {
        $votes = ['nh' => ['vote' => 'yes', 'at' => '2026-08-03 10:00:00', 'author_key' => 'secret-b1', 'reason' => '', 'note_id' => null]];
        $pub = Peanut_Connect_Approvals::public_votes($votes);
        $this->assertArrayNotHasKey('author_key', $pub['nh']);
        $this->assertSame('yes', $pub['nh']['vote']);
    }

    // ---- option-backed accessors (mock_options) ----

    public function test_page_state_reads_and_saves_through_options(): void
    {
        global $mock_options;
        $this->assertSame(['votes' => [], 'history' => []], Peanut_Connect_Approvals::page_state('/p'));
        $s = Peanut_Connect_Approvals::record_vote(['votes' => [], 'history' => []], 'nh', 'yes', '', 'b1', '2026-08-03 10:00:00');
        Peanut_Connect_Approvals::save_page_state('/p', $s);
        $this->assertSame('yes', Peanut_Connect_Approvals::page_state('/p')['votes']['nh']['vote']);
        $this->assertArrayHasKey('/p', Peanut_Connect_Approvals::all_pages_state());
        $this->assertIsArray($mock_options['peanut_connect_approvals']);
    }

    public function test_approvers_reads_option_and_sanitizes(): void
    {
        global $mock_options;
        $mock_options['peanut_connect_approvers'] = [['name' => 'Natty Hooper'], 'junk'];
        $rows = Peanut_Connect_Approvals::approvers();
        $this->assertCount(1, $rows);
        $this->assertSame('NH', $rows[0]['initials']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit tests/Test_Connect_Approvals.php`
Expected: FAIL — `Failed opening required '…/includes/class-connect-approvals.php'`

- [ ] **Step 3: Write the implementation**

Create `includes/class-connect-approvals.php`:

```php
<?php
/**
 * Mark It Up approval process — per-page approver chips (YES/NO with
 * timestamped history), stored site-locally in WP options. Approvers are
 * admin-defined name+initials rows (honor system — no WP accounts); the
 * acting browser's author_key is recorded on every action for traceability.
 * Payload shapes mirror a future Hub endpoint so a later sync is a relay
 * swap, not a rewrite.
 *
 * @package Peanut_Connect
 */

if (! defined('ABSPATH')) {
    exit;
}

class Peanut_Connect_Approvals {

    /** Option: ordered approver rows [{id, name, initials}]. */
    const APPROVERS_OPTION = 'peanut_connect_approvers';

    /** Option: map of normalized page path => {votes, history}. */
    const APPROVALS_OPTION = 'peanut_connect_approvals';

    /** Append-only history entries kept per page (newest win). */
    const HISTORY_CAP = 200;

    /** Query params that never distinguish a page (mirror pageKey() in feedback.js). */
    const STRIP_PARAMS = ['pp_review', 'pp_note', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid', 'mc_cid', 'mc_eid'];

    /**
     * Coerce an approver list (option value or admin form rows) to clean
     * rows. Empty names drop the row; initials are derived from the name
     * when missing, alnum-only, uppercased, max 3 chars; ids are stable
     * slugs, kept when present and deduped when generated.
     */
    public static function sanitize_approvers($raw): array {
        $out  = [];
        $seen = [];
        foreach ((is_array($raw) ? $raw : []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $initials = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($row['initials'] ?? '')));
            if ($initials === '') {
                $initials = self::derive_initials($name);
            }
            $initials = substr($initials, 0, 3);

            $id = strtolower((string) ($row['id'] ?? ''));
            if ($id === '' || preg_match('/[^a-z0-9\-]/', $id)) {
                $id = self::slugify($name);
            }
            $base = $id;
            for ($n = 2; isset($seen[$id]); $n++) {
                $id = $base . '-' . $n;
            }
            $seen[$id] = true;

            $out[] = ['id' => $id, 'name' => $name, 'initials' => $initials];
        }
        return $out;
    }

    private static function derive_initials(string $name): string {
        $letters = '';
        foreach (preg_split('/\s+/', $name) as $word) {
            $first = preg_replace('/[^A-Za-z0-9]/', '', $word);
            if ($first !== '') {
                $letters .= strtoupper($first[0]);
            }
        }
        return $letters !== '' ? $letters : 'X';
    }

    private static function slugify(string $name): string {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
        $slug = trim($slug, '-');
        return $slug !== '' ? $slug : 'approver';
    }

    /** The configured approver list, always sanitized. */
    public static function approvers(): array {
        return self::sanitize_approvers(get_option(self::APPROVERS_OPTION, []));
    }

    /**
     * Normalize a client-supplied page path to the same key feedback.js
     * pageKey() produces: same-site path only (no scheme, no '//'), with
     * review/tracking params stripped and any remaining query kept.
     */
    public static function normalize_path($raw): string {
        if (! is_string($raw) || $raw === '' || $raw[0] !== '/' || (isset($raw[1]) && $raw[1] === '/')) {
            return '/';
        }
        $raw   = substr($raw, 0, 2000);
        $parts = explode('?', $raw, 2);
        $path  = $parts[0];
        if (! isset($parts[1]) || $parts[1] === '') {
            return $path;
        }
        parse_str($parts[1], $query);
        foreach (self::STRIP_PARAMS as $param) {
            unset($query[$param]);
        }
        $qs = http_build_query($query);
        return $qs === '' ? $path : $path . '?' . $qs;
    }

    /** Missing/corrupt state always normalizes to empty votes + history. */
    public static function normalize_page_state($raw): array {
        $raw = is_array($raw) ? $raw : [];
        return [
            'votes'   => (isset($raw['votes']) && is_array($raw['votes'])) ? $raw['votes'] : [],
            'history' => (isset($raw['history']) && is_array($raw['history'])) ? array_values($raw['history']) : [],
        ];
    }

    /**
     * Pure: apply one vote to a page state. The latest vote per approver is
     * what the chips render; EVERY action (first vote, re-vote, flip) also
     * appends a timestamped history entry, so re-approval date/time is
     * always logged. History is capped at HISTORY_CAP.
     */
    public static function record_vote(array $state, string $approver_id, string $vote, string $reason, string $author_key, string $at, ?int $note_id = null): array {
        $state  = self::normalize_page_state($state);
        $vote   = $vote === 'no' ? 'no' : 'yes';
        $reason = $vote === 'no' ? trim($reason) : '';

        $state['votes'][$approver_id] = [
            'vote'       => $vote,
            'at'         => $at,
            'author_key' => $author_key,
            'reason'     => $reason,
            'note_id'    => $note_id,
        ];

        $entry = ['approver_id' => $approver_id, 'action' => $vote, 'at' => $at, 'author_key' => $author_key];
        if ($reason !== '') {
            $entry['reason'] = $reason;
        }
        $state['history'][] = $entry;
        $state['history']   = array_slice($state['history'], -self::HISTORY_CAP);

        return $state;
    }

    /**
     * Pure: clear votes (all, or one approver's) and log the reset in
     * history. approver_id null means the whole page.
     */
    public static function apply_reset(array $state, ?string $approver_id, string $author_key, string $at): array {
        $state = self::normalize_page_state($state);
        if ($approver_id === null) {
            $state['votes'] = [];
        } else {
            unset($state['votes'][$approver_id]);
        }
        $state['history'][] = ['approver_id' => $approver_id, 'action' => 'reset', 'at' => $at, 'author_key' => $author_key];
        $state['history']   = array_slice($state['history'], -self::HISTORY_CAP);
        return $state;
    }

    /** Client-facing projection of votes: internal author_key stays server-side. */
    public static function public_votes(array $votes): array {
        $out = [];
        foreach ($votes as $id => $vote) {
            if (is_array($vote)) {
                unset($vote['author_key']);
            }
            $out[$id] = $vote;
        }
        return $out;
    }

    private static function all_state(): array {
        $raw = get_option(self::APPROVALS_OPTION, []);
        return is_array($raw) ? $raw : [];
    }

    /** All recorded pages, each normalized. */
    public static function all_pages_state(): array {
        $out = [];
        foreach (self::all_state() as $path => $state) {
            $out[(string) $path] = self::normalize_page_state($state);
        }
        return $out;
    }

    public static function page_state(string $path): array {
        $all = self::all_state();
        return self::normalize_page_state($all[$path] ?? []);
    }

    public static function save_page_state(string $path, array $state): void {
        $all          = self::all_state();
        $all[$path]   = $state;
        update_option(self::APPROVALS_OPTION, $all, false);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit tests/Test_Connect_Approvals.php`
Expected: PASS (16 tests). Also run `/opt/homebrew/bin/php -l includes/class-connect-approvals.php` → "No syntax errors".

- [ ] **Step 5: Run the full Unit suite (no regressions)**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --testsuite Unit`
Expected: 196 tests, all green, 1 skipped (baseline 180 + 16 new).

- [ ] **Step 6: Commit**

```bash
git add includes/class-connect-approvals.php tests/Test_Connect_Approvals.php
git commit -m "feat(approvals): approval-state core - approvers, votes, timestamped history"
```

---

### Task 2: REST routes + the note bridge

**Files:**
- Modify: `includes/class-connect-approvals.php` (add `init`, `register_routes`, `get_state`, `vote`, `reset` at the end of the class)
- Modify: `includes/class-connect-feedback.php` (add `store_note` after `create()` ~line 565)
- Test: `tests/Test_Connect_Approvals.php` (append REST-payload tests)

**Interfaces:**
- Consumes: Task 1 helpers; `Peanut_Connect_Feedback::can_review` / `can_review_agency` (existing, public); `PEANUT_CONNECT_API_NAMESPACE`.
- Produces:
  - `Peanut_Connect_Approvals::init(): void` (Task 3 wires it into the plugin boot)
  - Routes: `GET /peanut-connect/v1/approvals[?path=…]`, `POST /peanut-connect/v1/approvals/vote`, `POST /peanut-connect/v1/approvals/reset`
  - `Peanut_Connect_Feedback::store_note(array $req): ?int` — server-side note create, returns Hub note id or null (best-effort; Task 2's `vote` uses it, nothing else does)
  - Vote response shape Task 4 relies on: `{ success: true, votes: {<id>: {vote, at, reason, note_id}}, note_id }`
  - All-pages response shape Task 5 relies on: `{ approvers: [...], pages: {<path>: {<id>: {vote, at, ...}}} }`

- [ ] **Step 1: Add `store_note` to the feedback class**

In `includes/class-connect-feedback.php`, directly after the `create()` method:

```php
    /**
     * Server-side note create for sibling modules (the approvals "what needs
     * to change" reason). Best-effort: relay/Hub failures return null and
     * must not block the caller — the reason also lives on the approval
     * record itself.
     */
    public static function store_note(array $req): ?int {
        $payload = self::build_store_payload($req, self::is_agency());
        $res     = self::relay('POST', '/feedback', $payload);
        if ($res instanceof \WP_REST_Response) {
            $data = $res->get_data();
            if (is_array($data) && isset($data['feedback']['id'])) {
                return (int) $data['feedback']['id'];
            }
        }
        return null;
    }
```

- [ ] **Step 2: Add routes + handlers to the approvals class**

Append inside `Peanut_Connect_Approvals` (before the closing brace):

```php
    public static function init(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes(): void {
        $ns = PEANUT_CONNECT_API_NAMESPACE;

        register_rest_route($ns, '/approvals', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'get_state'],
            'permission_callback' => ['Peanut_Connect_Feedback', 'can_review'],
        ]);
        register_rest_route($ns, '/approvals/vote', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'vote'],
            'permission_callback' => ['Peanut_Connect_Feedback', 'can_review'],
        ]);
        // Reset wipes other people's recorded sign-offs: agency only.
        register_rest_route($ns, '/approvals/reset', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'reset'],
            'permission_callback' => ['Peanut_Connect_Feedback', 'can_review_agency'],
        ]);
    }

    /**
     * ?path=… -> that page's approvers + votes; no path -> every recorded
     * page (the All-pages rollup). author_key never leaves the server.
     */
    public static function get_state(\WP_REST_Request $request) {
        $approvers = self::approvers();
        $path_raw  = $request->get_param('path');

        if (is_string($path_raw) && $path_raw !== '') {
            $state = self::page_state(self::normalize_path($path_raw));
            return new \WP_REST_Response([
                'approvers' => $approvers,
                'votes'     => self::public_votes($state['votes']),
            ], 200);
        }

        $pages = [];
        foreach (self::all_pages_state() as $path => $state) {
            if ($state['votes'] !== []) {
                $pages[$path] = self::public_votes($state['votes']);
            }
        }
        return new \WP_REST_Response(['approvers' => $approvers, 'pages' => $pages], 200);
    }

    /** Whitelist + coerce a vote request body. Pure — unit-tested. */
    public static function build_vote_input(array $req): array {
        return [
            'path'        => self::normalize_path($req['path'] ?? null),
            'page_title'  => isset($req['page_title']) ? (string) $req['page_title'] : '',
            'approver_id' => strtolower((string) ($req['approver_id'] ?? '')),
            'vote'        => (($req['vote'] ?? '') === 'no') ? 'no' : 'yes',
            'reason'      => trim((string) ($req['reason'] ?? '')),
            'author_key'  => substr((string) ($req['author_key'] ?? ''), 0, 64),
        ];
    }

    public static function vote(\WP_REST_Request $request) {
        $in        = self::build_vote_input($request->get_json_params() ?: []);
        $approvers = self::approvers();

        $approver = null;
        foreach ($approvers as $row) {
            if ($row['id'] === $in['approver_id']) {
                $approver = $row;
                break;
            }
        }
        if ($approver === null) {
            return new \WP_Error('pca_bad_approver', __('Unknown approver.', 'peanut-connect'), ['status' => 400]);
        }

        // A NO with a reason becomes a real Mark It Up note so it flows to
        // Hub with everything else. Failure to post the note never blocks
        // the vote — the reason is stored on the approval record too.
        $note_id = null;
        if ($in['vote'] === 'no' && $in['reason'] !== '') {
            $note_id = Peanut_Connect_Feedback::store_note([
                'page_url'    => $in['path'],
                'page_title'  => $in['page_title'],
                'author_name' => $approver['name'],
                'author_key'  => $in['author_key'],
                'body'        => $approver['name'] . ' — needs changes: ' . $in['reason'],
            ]);
        }

        $state = self::record_vote(
            self::page_state($in['path']),
            $approver['id'],
            $in['vote'],
            $in['reason'],
            $in['author_key'],
            gmdate('Y-m-d H:i:s'),
            $note_id
        );
        self::save_page_state($in['path'], $state);

        return new \WP_REST_Response([
            'success' => true,
            'votes'   => self::public_votes($state['votes']),
            'note_id' => $note_id,
        ], 200);
    }

    /** Agency-only (route gate). path omitted/empty = whole site. */
    public static function reset(\WP_REST_Request $request) {
        $in          = $request->get_json_params() ?: [];
        $approver_id = (isset($in['approver_id']) && is_string($in['approver_id']) && $in['approver_id'] !== '') ? strtolower($in['approver_id']) : null;
        $author_key  = substr((string) ($in['author_key'] ?? ''), 0, 64);
        $at          = gmdate('Y-m-d H:i:s');

        if (empty($in['path'])) {
            $all = [];
            foreach (self::all_pages_state() as $path => $state) {
                $all[$path] = self::apply_reset($state, $approver_id, $author_key, $at);
            }
            update_option(self::APPROVALS_OPTION, $all, false);
            return new \WP_REST_Response(['success' => true], 200);
        }

        $path  = self::normalize_path((string) $in['path']);
        $state = self::apply_reset(self::page_state($path), $approver_id, $author_key, $at);
        self::save_page_state($path, $state);
        return new \WP_REST_Response(['success' => true, 'votes' => self::public_votes($state['votes'])], 200);
    }
```

- [ ] **Step 3: Append the pure-input tests**

Add to `tests/Test_Connect_Approvals.php` (inside the class):

```php
    // ---- build_vote_input (REST payload coercion) ----

    public function test_build_vote_input_coerces_and_whitelists(): void
    {
        $in = Peanut_Connect_Approvals::build_vote_input([
            'path'        => '/p?utm_source=x',
            'page_title'  => 'Pricing',
            'approver_id' => 'NH',
            'vote'        => 'maybe',
            'reason'      => '  needs work  ',
            'author_key'  => str_repeat('k', 90),
            'evil'        => 'dropped',
        ]);
        $this->assertSame('/p', $in['path']);
        $this->assertSame('nh', $in['approver_id']);
        $this->assertSame('yes', $in['vote']); // only literal 'no' is a rejection
        $this->assertSame('needs work', $in['reason']);
        $this->assertSame(64, strlen($in['author_key']));
        $this->assertArrayNotHasKey('evil', $in);
    }

    public function test_build_vote_input_empty_request(): void
    {
        $in = Peanut_Connect_Approvals::build_vote_input([]);
        $this->assertSame('/', $in['path']);
        $this->assertSame('yes', $in['vote']);
        $this->assertSame('', $in['approver_id']);
    }
```

- [ ] **Step 4: Run tests + lint**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit tests/Test_Connect_Approvals.php`
Expected: PASS (18 tests).
Run: `/opt/homebrew/bin/php -l includes/class-connect-approvals.php && /opt/homebrew/bin/php -l includes/class-connect-feedback.php`
Expected: no syntax errors.
Run: `/opt/homebrew/bin/php vendor/bin/phpunit --testsuite Unit`
Expected: all green (198 tests, 1 skipped).

- [ ] **Step 5: Commit**

```bash
git add includes/class-connect-approvals.php includes/class-connect-feedback.php tests/Test_Connect_Approvals.php
git commit -m "feat(approvals): REST routes + NO-reason note bridge"
```

---

### Task 3: Boot wiring + widget config

**Files:**
- Modify: `peanut-connect.php` (require ~line 103, init ~line 195)
- Modify: `includes/class-connect-feedback.php` (`enqueue()` localize array ~line 397)

**Interfaces:**
- Consumes: `Peanut_Connect_Approvals::init()`, `::approvers()` (Tasks 1-2).
- Produces: `window.peanutConnectFeedback.approvers` = `[{id, name, initials}]` — Task 4's widget reads this. Empty array when none configured (widget hides the strip).

- [ ] **Step 1: Require + init the module**

In `peanut-connect.php`, directly after the line `require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-feedback.php';` add:

```php
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-approvals.php';
```

Directly after the line `Peanut_Connect_Feedback::init();` add (same guard context — approvals only exist where Mark It Up exists):

```php
            Peanut_Connect_Approvals::init();
```

- [ ] **Step 2: Ship the approver list to the widget**

In `includes/class-connect-feedback.php` `enqueue()`, extend the `wp_localize_script` array:

```php
        wp_localize_script('peanut-connect-feedback', 'peanutConnectFeedback', [
            'restUrl'     => esc_url_raw(rest_url('peanut-connect/v1/feedback')),
            'nonce'       => wp_create_nonce('wp_rest'),
            'isAgency'    => self::is_agency(),
            'reviewToken' => $token,
            'approvers'   => class_exists('Peanut_Connect_Approvals') ? Peanut_Connect_Approvals::approvers() : [],
        ]);
```

- [ ] **Step 3: Lint + suite**

Run: `/opt/homebrew/bin/php -l peanut-connect.php && /opt/homebrew/bin/php -l includes/class-connect-feedback.php`
Expected: no syntax errors.
Run: `/opt/homebrew/bin/php vendor/bin/phpunit --testsuite Unit`
Expected: all green.

- [ ] **Step 4: Commit**

```bash
git add peanut-connect.php includes/class-connect-feedback.php
git commit -m "feat(approvals): boot module and localize approver list to the widget"
```

---

### Task 4: Widget — approvals strip + vote flow

**Files:**
- Modify: `assets/js/feedback.js` (panel markup ~line 417; new approvals section after the tabs wiring ~line 466; hook `loadApprovals()` at the end of `load()` ~line 596)
- Modify: `assets/css/feedback.css` (append chip/flow styles)

**Interfaces:**
- Consumes: `cfg.approvers` (Task 3), `api()` helper (existing — it strips the `/feedback` suffix, so `api('GET', '/approvals?...')` hits the new routes), `pageKey()`, `authorKey()`, `load()` (existing).
- Produces: `renderApprovals()`, `loadApprovals()`, and `apprVotes` (page vote map) — Task 5 reuses the chip CSS classes `pp-chip`, `pp-chip-yes`, `pp-chip-no`.

- [ ] **Step 1: Add the strip to the panel markup**

In the `panel.innerHTML` string, after the closing `</div>` of the `pp-help` block and BEFORE the `pp-tabs` line, insert:

```js
    '<div class="pp-approve" hidden><div class="pp-approve-label">Click your initials to approve:</div>' +
    '<div class="pp-approve-chips"></div><div class="pp-approve-flow" hidden></div></div>' +
```

- [ ] **Step 2: Add the approvals logic**

After the tabs/site-view block (after the `loadSummary` function), add:

```js
  // ---- approval chips ("Click your initials to approve") ----
  const approvers = Array.isArray(cfg.approvers) ? cfg.approvers : [];
  let apprVotes = {};

  function apprDate(at) {
    try { return new Date(at.replace(' ', 'T') + 'Z').toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }); } catch (e) { return ''; }
  }
  function hideApproveFlow() {
    const flow = panel.querySelector('.pp-approve-flow');
    flow.hidden = true; flow.innerHTML = '';
  }
  function renderApprovals() {
    const box = panel.querySelector('.pp-approve');
    if (!approvers.length) { box.hidden = true; return; }
    box.hidden = false;
    const chips = box.querySelector('.pp-approve-chips');
    chips.innerHTML = '';
    approvers.forEach((ap) => {
      const v = apprVotes[ap.id];
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'pp-chip' + (v ? (v.vote === 'yes' ? ' pp-chip-yes' : ' pp-chip-no') : '');
      b.textContent = ap.initials;
      b.title = ap.name + (v
        ? (v.vote === 'yes' ? ' — Approved · ' : ' — Needs changes · ') + apprDate(v.at)
        : ' — no response yet');
      b.setAttribute('aria-label', b.title);
      b.addEventListener('click', () => askApprove(ap));
      chips.appendChild(b);
    });
  }
  function approveError(flow) {
    let err = flow.querySelector('.pp-approve-err');
    if (!err) { err = document.createElement('div'); err.className = 'pp-approve-err'; flow.appendChild(err); }
    err.textContent = "couldn't save — try again";
  }
  function askApprove(ap) {
    const flow = panel.querySelector('.pp-approve-flow');
    flow.innerHTML = ''; flow.hidden = false;
    const q = document.createElement('div'); q.className = 'pp-approve-q';
    q.textContent = 'Is this approved?  (' + ap.name + ')';
    const yes = document.createElement('button'); yes.type = 'button'; yes.className = 'pp-approve-btn pp-approve-yes'; yes.textContent = 'YES';
    const no = document.createElement('button'); no.type = 'button'; no.className = 'pp-approve-btn pp-approve-no'; no.textContent = 'NO';
    const row = document.createElement('div'); row.className = 'pp-approve-row'; row.append(yes, no);
    flow.append(q, row);
    yes.addEventListener('click', () => sendVote(ap, 'yes', '', flow));
    no.addEventListener('click', () => askReason(ap, flow));
  }
  function askReason(ap, flow) {
    flow.innerHTML = '';
    const q = document.createElement('div'); q.className = 'pp-approve-q';
    q.textContent = 'What needs to change for approval?';
    const ta = document.createElement('textarea'); ta.className = 'pp-approve-ta'; ta.rows = 3;
    const submit = document.createElement('button'); submit.type = 'button'; submit.className = 'pp-approve-btn pp-approve-yes'; submit.textContent = 'submit';
    const edit = document.createElement('button'); edit.type = 'button'; edit.className = 'pp-approve-btn pp-approve-no'; edit.textContent = 'edit';
    edit.title = 'Close and use the mark-it-up tools instead';
    const row = document.createElement('div'); row.className = 'pp-approve-row'; row.append(submit, edit);
    flow.append(q, ta, row);
    ta.focus();
    submit.addEventListener('click', () => sendVote(ap, 'no', ta.value.trim(), flow));
    // "edit" = go mark up the page instead; the vote can be cast after.
    edit.addEventListener('click', () => hideApproveFlow());
  }
  function sendVote(ap, vote, reason, flow) {
    api('POST', '/approvals/vote', {
      path: pageKey(), page_title: document.title,
      approver_id: ap.id, vote: vote, reason: reason, author_key: authorKey(),
    }).then((res) => {
      if (res && res.success) {
        apprVotes = res.votes || {};
        hideApproveFlow(); renderApprovals();
        if (vote === 'no' && reason && res.note_id) load(); // the reason is now a note — refresh the list
      } else { approveError(flow); }
    }).catch(() => approveError(flow));
  }
  function loadApprovals() {
    if (!approvers.length) { renderApprovals(); return; }
    api('GET', '/approvals?path=' + encodeURIComponent(pageKey()))
      .then((res) => { apprVotes = (res && res.votes) || {}; renderApprovals(); })
      .catch(() => { apprVotes = {}; renderApprovals(); });
  }
```

- [ ] **Step 3: Call it on load**

At the end of the file, change:

```js
  load();
```

to:

```js
  load();
  loadApprovals();
```

- [ ] **Step 4: Append the styles**

Append to `assets/css/feedback.css`:

```css
/* ---- approval chips ---- */
.pp-approve { padding: 8px 10px; border-bottom: 1px solid #E5E7EB; }
.pp-approve-label { font-weight: 600; font-size: 12px; margin-bottom: 6px; }
.pp-approve-chips { display: flex; flex-wrap: wrap; gap: 6px; }
.pp-chip {
  min-width: 34px; height: 34px; padding: 0 6px;
  border: 1px solid #1F2937; border-radius: 4px;
  background: #4AA3DF; color: #fff; font-weight: 700; font-size: 13px;
  cursor: pointer;
}
.pp-chip-yes { background: #2FA34F; }
.pp-chip-no { background: #DC2626; }
.pp-approve-flow { margin-top: 8px; }
.pp-approve-q { font-weight: 600; font-size: 12px; margin-bottom: 6px; }
.pp-approve-row { display: flex; gap: 8px; margin-top: 6px; }
.pp-approve-btn {
  border: 1px solid #1F2937; border-radius: 4px; padding: 4px 14px;
  color: #fff; font-weight: 700; font-size: 12px; cursor: pointer;
}
.pp-approve-yes { background: #2FA34F; }
.pp-approve-no { background: #DC2626; }
.pp-approve-ta { width: 100%; box-sizing: border-box; font: inherit; font-size: 12px; padding: 4px 6px; }
.pp-approve-err { color: #DC2626; font-size: 11px; margin-top: 4px; }
```

- [ ] **Step 5: Verify size + sanity**

Run: `wc -l assets/js/feedback.js`
Expected: ~690 (under the 900 ceiling; if over 900, STOP and split per Global Constraints).
Run: `node --check assets/js/feedback.js`
Expected: no output (syntax OK).

- [ ] **Step 6: Manual verification on staging**

On https://staging.cenhudpeakperks.com (deploy the worktree build or use the open-access snippet per prior rounds): configure two test approvers on the admin page (needs Task 6 — if executing tasks in order, defer this step's full pass to Task 8's verification round; still confirm now that with NO approvers configured the panel renders exactly as before, no strip, no console errors).

- [ ] **Step 7: Commit**

```bash
git add assets/js/feedback.js assets/css/feedback.css
git commit -m "feat(approvals): initials chips + YES/NO vote flow in the widget"
```

---

### Task 5: Widget — All-pages rollup + resizable panel

**Files:**
- Modify: `assets/js/feedback.js` (`loadSummary()` ~line 467; panel-state block ~line 407)
- Modify: `assets/css/feedback.css` (append)

**Interfaces:**
- Consumes: `api()`, chip classes from Task 4 (`pp-chip`, `pp-chip-yes`, `pp-chip-no`), `approvers` array (Task 4), all-pages response shape from Task 2 (`{approvers, pages: {path: votes}}`).
- Produces: nothing later tasks consume.

- [ ] **Step 1: Roll approvals into the All-pages view**

Replace the whole `loadSummary()` function with:

```js
  function approvalChipsRow(votes) {
    const row = document.createElement('div');
    row.className = 'pp-sw-chips';
    approvers.forEach((ap) => {
      const v = votes ? votes[ap.id] : null;
      const s = document.createElement('span');
      s.className = 'pp-chip pp-chip-sm' + (v ? (v.vote === 'yes' ? ' pp-chip-yes' : ' pp-chip-no') : '');
      s.textContent = ap.initials;
      s.title = ap.name + (v ? (v.vote === 'yes' ? ' — Approved · ' : ' — Needs changes · ') + apprDate(v.at) : ' — no response yet');
      row.appendChild(s);
    });
    return row;
  }
  function loadSummary() {
    const box = panel.querySelector('.pp-sitewide');
    box.textContent = 'Loading…';
    const notesReq = api('GET', '/feedback/summary').catch(() => null);
    const apprReq = approvers.length ? api('GET', '/approvals').catch(() => null) : Promise.resolve(null);
    Promise.all([notesReq, apprReq]).then(([res, appr]) => {
      const apprPages = (appr && appr.pages) || {};
      if ((!res || !res.pages) && !Object.keys(apprPages).length) { box.textContent = 'Not available yet.'; return; }
      summaryCache = (res && res.pages) || [];
      box.innerHTML = '';
      const seen = {};
      summaryCache.forEach((pg) => {
        if (!pg || typeof pg.page_url !== 'string' || !/^\/(?!\/)/.test(pg.page_url)) return; // defense-in-depth: only same-site paths become hrefs
        seen[pg.page_url] = true;
        const h = document.createElement('div'); h.className = 'pp-sw-page';
        h.textContent = (pg.page_title || pg.page_url) + ' — ' + pg.open_count + ' open, ' + pg.done_count + ' done';
        box.appendChild(h);
        if (approvers.length) box.appendChild(approvalChipsRow(apprPages[pg.page_url]));
        (pg.notes || []).forEach((n) => {
          const a = document.createElement('a');
          a.className = 'pp-sw-note' + (n.status === 'done' ? ' pp-strike' : '');
          a.textContent = (n.author_name ? n.author_name + ': ' : '') + (n.body || '').slice(0, 80);
          a.href = pg.page_url + (pg.page_url.indexOf('?') === -1 ? '?' : '&') + 'pp_note=' + n.id;
          box.appendChild(a);
        });
      });
      // Pages that have approval activity but no notes still show up.
      Object.keys(apprPages).forEach((path) => {
        if (seen[path] || !/^\/(?!\/)/.test(path)) return;
        const h = document.createElement('div'); h.className = 'pp-sw-page';
        h.textContent = path;
        box.appendChild(h);
        box.appendChild(approvalChipsRow(apprPages[path]));
      });
    });
  }
```

- [ ] **Step 2: Make the panel resizable + persist the size**

In the panel-state block, after `applyCollapsed();` (the first call, ~line 447), add:

```js
  // Resizable panel — the approval strip and rollup need room; size persists.
  const SIZE_KEY = 'ppFeedbackPanelSize';
  try {
    const sz = JSON.parse(localStorage.getItem(SIZE_KEY) || 'null');
    if (sz && sz.w > 200 && sz.h > 100) { panel.style.width = sz.w + 'px'; panel.style.height = sz.h + 'px'; }
  } catch (e) {}
  if (window.ResizeObserver) {
    let sizeTimer = null;
    new ResizeObserver(() => {
      if (sizeTimer) clearTimeout(sizeTimer);
      sizeTimer = setTimeout(() => {
        localStorage.setItem(SIZE_KEY, JSON.stringify({ w: panel.offsetWidth, h: panel.offsetHeight }));
      }, 300);
    }).observe(panel);
  }
```

- [ ] **Step 3: CSS for resize + small chips**

Append to `assets/css/feedback.css`:

```css
.pp-panel { resize: both; overflow: auto; min-width: 260px; min-height: 130px; max-width: 92vw; max-height: 85vh; }
.pp-panel.pp-collapsed { resize: none; height: auto !important; }
.pp-sw-chips { display: flex; flex-wrap: wrap; gap: 4px; margin: 2px 0 6px; }
.pp-chip-sm { min-width: 24px; height: 24px; font-size: 10px; line-height: 22px; text-align: center; cursor: default; }
```

NOTE: check `assets/css/feedback.css` for an existing `.pp-panel` rule with a fixed `width` — if present, keep it as the DEFAULT width but ensure it doesn't fight the inline style (inline `style.width` wins, which is the desired behavior; do not add `!important` to any width).

- [ ] **Step 4: Verify**

Run: `node --check assets/js/feedback.js && wc -l assets/js/feedback.js`
Expected: syntax OK, ~730 lines (still under 900).

- [ ] **Step 5: Commit**

```bash
git add assets/js/feedback.js assets/css/feedback.css
git commit -m "feat(approvals): all-pages approval rollup + resizable panel"
```

---

### Task 6: Admin — Approvers section + reset controls

**Files:**
- Modify: `includes/class-connect-approvals.php` (add `render_admin_section` + `handle_admin_post` at the end of the class)
- Modify: `includes/class-connect-feedback.php` (`render_admin_page` — one call before the closing `</div>` ~line 272)

**Interfaces:**
- Consumes: Task 1 helpers (`sanitize_approvers`, `approvers`, `all_pages_state`, `apply_reset`, `normalize_page_state`), `APPROVERS_OPTION`, `APPROVALS_OPTION`.
- Produces: `Peanut_Connect_Approvals::render_admin_section(): void` — called by the feedback admin page.

- [ ] **Step 1: Hook the section into the existing admin page**

In `includes/class-connect-feedback.php` `render_admin_page()`, immediately before the final closing `</div>` of the `.wrap` (before the `<?php` that ends the method), add:

```php
            <?php
            if (class_exists('Peanut_Connect_Approvals')) {
                Peanut_Connect_Approvals::render_admin_section();
            }
            ?>
```

- [ ] **Step 2: Implement the section**

Append inside `Peanut_Connect_Approvals`:

```php
    /**
     * "Approvers" section on the Mark It Up admin page: manage the
     * name+initials rows the widget renders as chips, and reset recorded
     * approvals (per page or site-wide). Admin-only, nonce-gated, own form.
     */
    public static function render_admin_section(): void {
        if (! current_user_can('manage_options')) {
            return;
        }

        $notice = self::handle_admin_post();

        $approvers = self::approvers();
        $pages     = array_keys(self::all_pages_state());
        sort($pages);
        ?>
        <hr />
        <h2><?php esc_html_e('Approvers', 'peanut-connect'); ?></h2>
        <?php if ($notice !== '') : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
        <?php endif; ?>
        <p style="max-width:640px">
            <?php esc_html_e('Approvers appear as initials chips in the Mark It Up panel ("Click your initials to approve"). Anyone with review access can click a chip — this is a lightweight sign-off, not an authenticated signature.', 'peanut-connect'); ?>
        </p>

        <form method="post">
            <?php wp_nonce_field('pca_approvers'); ?>
            <table class="widefat striped" style="max-width:640px">
                <thead><tr>
                    <th><?php esc_html_e('Name', 'peanut-connect'); ?></th>
                    <th style="width:90px"><?php esc_html_e('Initials', 'peanut-connect'); ?></th>
                    <th style="width:150px"><?php esc_html_e('Order / remove', 'peanut-connect'); ?></th>
                </tr></thead>
                <tbody>
                <?php if (empty($approvers)) : ?>
                    <tr><td colspan="3"><?php esc_html_e('No approvers yet — add one below.', 'peanut-connect'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($approvers as $i => $row) : ?>
                    <tr>
                        <td>
                            <input type="hidden" name="pca_id[]" value="<?php echo esc_attr($row['id']); ?>" />
                            <input type="text" name="pca_name[]" value="<?php echo esc_attr($row['name']); ?>" class="regular-text" />
                        </td>
                        <td><input type="text" name="pca_initials[]" value="<?php echo esc_attr($row['initials']); ?>" size="4" maxlength="3" /></td>
                        <td>
                            <button type="submit" name="pca_action" value="up-<?php echo esc_attr((string) $i); ?>" class="button" <?php disabled($i === 0); ?> aria-label="<?php esc_attr_e('Move up', 'peanut-connect'); ?>">&uarr;</button>
                            <button type="submit" name="pca_action" value="down-<?php echo esc_attr((string) $i); ?>" class="button" <?php disabled($i === count($approvers) - 1); ?> aria-label="<?php esc_attr_e('Move down', 'peanut-connect'); ?>">&darr;</button>
                            <button type="submit" name="pca_action" value="remove-<?php echo esc_attr((string) $i); ?>" class="button"><?php esc_html_e('Remove', 'peanut-connect'); ?></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr>
                    <td><input type="text" name="pca_new_name" value="" class="regular-text" placeholder="<?php esc_attr_e('New approver name', 'peanut-connect'); ?>" /></td>
                    <td><input type="text" name="pca_new_initials" value="" size="4" maxlength="3" placeholder="<?php esc_attr_e('NH', 'peanut-connect'); ?>" /></td>
                    <td><button type="submit" name="pca_action" value="add" class="button"><?php esc_html_e('Add', 'peanut-connect'); ?></button></td>
                </tr>
                </tbody>
            </table>
            <p><button type="submit" name="pca_action" value="save" class="button button-primary"><?php esc_html_e('Save approvers', 'peanut-connect'); ?></button></p>

            <h3><?php esc_html_e('Reset approvals', 'peanut-connect'); ?></h3>
            <p style="max-width:640px" class="description">
                <?php esc_html_e('Start a fresh review round after making changes. Resets clear the current chips; the full history (including the reset) stays recorded.', 'peanut-connect'); ?>
            </p>
            <p>
                <select name="pca_reset_path">
                    <option value=""><?php esc_html_e('Whole site (every page)', 'peanut-connect'); ?></option>
                    <?php foreach ($pages as $page_path) : ?>
                        <option value="<?php echo esc_attr($page_path); ?>"><?php echo esc_html($page_path); ?></option>
                    <?php endforeach; ?>
                </select>
                <label style="margin-left:8px">
                    <input type="checkbox" name="pca_reset_confirm" value="1" />
                    <?php esc_html_e('Yes, clear these approvals', 'peanut-connect'); ?>
                </label>
                <button type="submit" name="pca_action" value="reset" class="button"><?php esc_html_e('Reset', 'peanut-connect'); ?></button>
            </p>
        </form>
        <?php
    }

    /**
     * Handle the Approvers form. Returns a success notice string ('' = no
     * post handled). Every branch re-sanitizes through sanitize_approvers.
     */
    private static function handle_admin_post(): string {
        if (empty($_POST['pca_action']) || ! check_admin_referer('pca_approvers')) {
            return '';
        }
        $action = sanitize_text_field(wp_unslash($_POST['pca_action']));

        // Rebuild rows from the posted table (keeps in-flight edits on every action).
        $ids      = array_map('sanitize_text_field', wp_unslash($_POST['pca_id'] ?? []));
        $names    = array_map('sanitize_text_field', wp_unslash($_POST['pca_name'] ?? []));
        $initials = array_map('sanitize_text_field', wp_unslash($_POST['pca_initials'] ?? []));
        $rows     = [];
        foreach ($names as $i => $name) {
            $rows[] = ['id' => $ids[$i] ?? '', 'name' => $name, 'initials' => $initials[$i] ?? ''];
        }

        if ($action === 'add') {
            $rows[] = [
                'name'     => sanitize_text_field(wp_unslash($_POST['pca_new_name'] ?? '')),
                'initials' => sanitize_text_field(wp_unslash($_POST['pca_new_initials'] ?? '')),
            ];
        } elseif (preg_match('/^remove-(\d+)$/', $action, $m)) {
            array_splice($rows, (int) $m[1], 1);
        } elseif (preg_match('/^(up|down)-(\d+)$/', $action, $m)) {
            $i = (int) $m[2];
            $j = $m[1] === 'up' ? $i - 1 : $i + 1;
            if (isset($rows[$i], $rows[$j])) {
                [$rows[$i], $rows[$j]] = [$rows[$j], $rows[$i]];
            }
        } elseif ($action === 'reset') {
            if (empty($_POST['pca_reset_confirm'])) {
                return __('Reset skipped — tick the confirmation box first.', 'peanut-connect');
            }
            $path = sanitize_text_field(wp_unslash($_POST['pca_reset_path'] ?? ''));
            $at   = gmdate('Y-m-d H:i:s');
            $all  = [];
            foreach (self::all_pages_state() as $p => $state) {
                $all[$p] = ($path === '' || $p === $path) ? self::apply_reset($state, null, 'admin', $at) : $state;
            }
            update_option(self::APPROVALS_OPTION, $all, false);
            return $path === ''
                ? __('All approvals reset.', 'peanut-connect')
                : sprintf(__('Approvals reset for %s.', 'peanut-connect'), $path);
        }

        update_option(self::APPROVERS_OPTION, self::sanitize_approvers($rows), false);
        return __('Approvers saved.', 'peanut-connect');
    }
```

- [ ] **Step 3: Lint + suite**

Run: `/opt/homebrew/bin/php -l includes/class-connect-approvals.php && /opt/homebrew/bin/php -l includes/class-connect-feedback.php`
Expected: no syntax errors.
Run: `/opt/homebrew/bin/php vendor/bin/phpunit --testsuite Unit`
Expected: all green.

- [ ] **Step 4: Commit**

```bash
git add includes/class-connect-approvals.php includes/class-connect-feedback.php
git commit -m "feat(approvals): admin approvers management + reset controls"
```

---

### Task 7: Version 3.33.0 + changelog

**Files:**
- Modify: `peanut-connect.php` (line 6 `* Version:` and line 20 `define('PEANUT_CONNECT_VERSION', ...)`)
- Modify: `readme.txt` (line 7 `Stable tag:`)
- Modify: `CHANGELOG.md` (new section at top, below the intro)

**Interfaces:** none.

- [ ] **Step 1: Bump the three version strings to `3.33.0`**

`peanut-connect.php` line 6: `* Version: 3.33.0` — line 20: `define('PEANUT_CONNECT_VERSION', '3.33.0');` — `readme.txt` line 7: `Stable tag: 3.33.0`.

- [ ] **Step 2: Add the changelog entry**

At the top of the version sections in `CHANGELOG.md` (above `## [3.32.1]` or whatever the current top section is — check; the file lists newest first after the intro):

```markdown
## [3.33.0] - 2026-08-03

### Added
- **Mark It Up approval process.** Admin-defined approvers (name + initials, honor system) appear as chips in the widget panel — "Click your initials to approve". YES turns the chip green, NO opens "What needs to change for approval?" (the reason posts as a regular Mark It Up note) and turns the chip red; hovering a chip shows who + when. Every vote, re-vote, and reset is kept in a timestamped per-page history (WP options; `peanut_connect_approvers`, `peanut_connect_approvals`). New same-origin REST routes `GET/POST /approvals*` reuse the existing review-access gates; reset is agency-only.
- Approval rollup chips per page in the widget's All-pages view.
- The Mark It Up panel is now resizable (drag the corner); the size persists per browser.
- "Approvers" section on the Mark It Up admin page: add/remove/reorder approvers and reset approvals per page or site-wide.
```

- [ ] **Step 3: Lint + full suite**

Run: `/opt/homebrew/bin/php -l peanut-connect.php`
Expected: no syntax errors.
Run: `/opt/homebrew/bin/php vendor/bin/phpunit --testsuite Unit`
Expected: all green.

- [ ] **Step 4: Commit**

```bash
git add peanut-connect.php readme.txt CHANGELOG.md
git commit -m "chore(release): peanut-connect 3.33.0 - Mark It Up approval process"
```

---

### Task 8: Full verification + branch + draft PR

**Files:** none (verification + git only).

- [ ] **Step 1: Full test config (not just Unit)**

Run: `/opt/homebrew/bin/php vendor/bin/phpunit --testsuite Unit && /opt/homebrew/bin/php vendor/bin/phpunit --testsuite Property`
Expected: all green. (The `Contract` suite needs a live WP; it runs in CI.)

- [ ] **Step 2: JS + PHP final lint**

Run: `node --check assets/js/feedback.js && for f in includes/class-connect-approvals.php includes/class-connect-feedback.php peanut-connect.php; do /opt/homebrew/bin/php -l $f; done`
Expected: all clean.

- [ ] **Step 3: Manual staging pass (the spec's checklist)**

On https://staging.cenhudpeakperks.com with the built plugin: add two approvers on the admin page; as a token reviewer: chip YES (green + hover date), chip NO with a reason (red + the reason appears as a note in the panel AND on the page), re-vote red→green (hover shows the NEW date/time — re-approval logging), All-pages tab shows chip rollup, resize the panel and reload (size persists), admin reset per page (chips clear, widget reflects it), access mode `off` (widget gone, `/approvals` routes 401/403). Record pass/fail per item in the PR body.

- [ ] **Step 4: Rename branch, push, draft PR**

```bash
git branch -m feat/mark-it-up-approvals-3.33.0
git push -u origin feat/mark-it-up-approvals-3.33.0
gh pr create --draft --title "feat: Mark It Up approval process (3.33.0)" --body "..."
```

PR body: summary of the feature (from the changelog entry), link to the spec file, the staging verification checklist results, and the standard footer. Title starts `feat:` (NOT `fix:` — `fix:` titles trip the require-regression-test gate).

---

## Self-Review Notes

- Spec coverage: chips/YES/NO/green/red/hover-date (Task 4), NO reason → real note (Tasks 2+4), per-page state + history cap + re-approval timestamps (Task 1), All-pages rollup (Task 5), resizable panel (Task 5), admin approvers + reset (Task 6), degradation (no approvers → strip hidden, Task 4; `off` → gates reuse `can_review`, Task 2), WP-now-HUB-later payload shape (Task 1 record shape + Task 2 response shapes), version 3.33.0 (Task 7). No gaps found.
- `record_vote` gained an optional `$note_id` param vs. the spec's signature list — additive, spec intent unchanged.
- The widget note refresh after a NO uses the existing `load()`; no new list plumbing.
