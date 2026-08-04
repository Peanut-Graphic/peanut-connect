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

    public function test_digest_lines_empty_when_no_approvers(): void
    {
        $this->assertSame([], Peanut_Connect_Approvals::build_digest_lines(['/p'], ['/p' => []], []));
    }

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

    // ---- validate_approver_id ----

    public function test_validate_approver_id_accepts_only_configured_ids(): void
    {
        $approvers = [['id' => 'nh', 'name' => 'N', 'initials' => 'NH']];
        $this->assertSame('nh', Peanut_Connect_Approvals::validate_approver_id('NH', $approvers));
        $this->assertSame('nh', Peanut_Connect_Approvals::validate_approver_id('nh', $approvers));
        $this->assertSame('', Peanut_Connect_Approvals::validate_approver_id('xx', $approvers));
        $this->assertSame('', Peanut_Connect_Approvals::validate_approver_id('', $approvers));
        $this->assertSame('', Peanut_Connect_Approvals::validate_approver_id(['nh'], $approvers));
    }

    // ---- build_ready_input ----

    public function test_build_ready_input_coerces(): void
    {
        $in = Peanut_Connect_Approvals::build_ready_input(['path' => '/p?utm_source=x', 'ready' => '1', 'evil' => 'x']);
        $this->assertSame(['path' => '/p', 'ready' => true], $in);
        $this->assertSame(['path' => '/', 'ready' => false], Peanut_Connect_Approvals::build_ready_input([]));
    }

    // ---- required vs optional approvers (3.35.0) ----

    public function test_sanitize_approvers_defaults_required_true_and_keeps_flag(): void
    {
        $rows = Peanut_Connect_Approvals::sanitize_approvers([
            ['name' => 'Natty Hooper'],                        // legacy row, no key -> required
            ['name' => 'Views Only', 'required' => false],
            ['name' => 'Gate Keeper', 'required' => '1'],
        ]);
        $this->assertTrue($rows[0]['required']);
        $this->assertFalse($rows[1]['required']);
        $this->assertTrue($rows[2]['required']);
    }

    public function test_all_green_ignores_optional_reviewers(): void
    {
        $approvers = [
            ['id' => 'nh', 'name' => 'N', 'initials' => 'NH', 'required' => true],
            ['id' => 'vo', 'name' => 'V', 'initials' => 'VO', 'required' => false],
        ];
        $green = ['vote' => 'yes', 'stale' => false];
        // Optional reviewer silent -> still fully approved.
        $this->assertTrue(Peanut_Connect_Approvals::compute_all_green($approvers, ['nh' => $green]));
        // Optional reviewer says NO -> never blocks.
        $this->assertTrue(Peanut_Connect_Approvals::compute_all_green($approvers, ['nh' => $green, 'vo' => ['vote' => 'no', 'stale' => false]]));
        // Required approver missing -> not approved even with an optional yes.
        $this->assertFalse(Peanut_Connect_Approvals::compute_all_green($approvers, ['vo' => $green]));
    }

    public function test_all_optional_is_never_all_green(): void
    {
        $approvers = [['id' => 'vo', 'name' => 'V', 'initials' => 'VO', 'required' => false]];
        $this->assertFalse(Peanut_Connect_Approvals::compute_all_green($approvers, ['vo' => ['vote' => 'yes', 'stale' => false]]));
    }

    public function test_digest_awaits_only_required_approvers(): void
    {
        $approvers = [
            ['id' => 'nh', 'name' => 'N', 'initials' => 'NH', 'required' => true],
            ['id' => 'vo', 'name' => 'V', 'initials' => 'VO', 'required' => false],
        ];
        $this->assertSame(
            ['/p — awaiting: NH'],
            Peanut_Connect_Approvals::build_digest_lines(['/p'], ['/p' => []], $approvers)
        );
    }

    // ---- front-page snapshot resolution (3.35.0 staleness fix) ----

    public function test_front_page_snapshot_resolves_via_page_on_front(): void
    {
        global $mock_options;
        $mock_options['show_on_front'] = 'page';
        $mock_options['page_on_front'] = 5;
        $this->assertSame(5, Peanut_Connect_Approvals::page_snapshot('/')['post_id']);
        $this->assertSame(5, Peanut_Connect_Approvals::page_snapshot('/?tab=2')['post_id']);
        $mock_options['show_on_front'] = 'posts';
        $this->assertSame(0, Peanut_Connect_Approvals::page_snapshot('/')['post_id']);
    }
}
