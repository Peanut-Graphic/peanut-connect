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
