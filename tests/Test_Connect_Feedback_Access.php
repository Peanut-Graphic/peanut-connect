<?php
/**
 * Tests for the pure access-mode decision seams: mode normalization,
 * allowed-user sanitization, and the automatic-grant matrix. The wrappers
 * that read live WP state (access_mode()/user_grant()) are exercised on
 * staging; these tests pin the decision logic itself.
 *
 * @package Peanut_Connect
 */

require_once dirname(__DIR__) . '/includes/class-connect-feedback.php';

class Test_Connect_Feedback_Access extends Peanut_Connect_TestCase
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
