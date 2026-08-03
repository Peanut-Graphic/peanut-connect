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
