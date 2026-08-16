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
