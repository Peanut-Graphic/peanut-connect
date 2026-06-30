<?php
/**
 * Tests for the pure feedback payload-builder seam: field whitelisting and
 * server-forced author_is_agency flag (a client can never claim agency).
 *
 * @package Peanut_Connect
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/class-connect-feedback.php';

final class ConnectFeedbackPayloadTest extends TestCase
{
    public function test_build_store_payload_whitelists_fields_and_sets_agency_flag(): void
    {
        $raw = [
            'page_url' => '/sensi/', 'anchor_x' => 0.4, 'anchor_y' => 0.6,
            'author_name' => 'Greg', 'body' => 'note', 'evil' => 'DROP TABLE', 'author_is_agency' => true,
        ];
        $out = Peanut_Connect_Feedback::build_store_payload($raw, true);

        $this->assertArrayNotHasKey('evil', $out);
        $this->assertTrue($out['author_is_agency']);          // forced server-side
        $this->assertSame('/sensi/', $out['page_url']);
        $this->assertSame('Greg', $out['author_name']);
    }

    public function test_build_store_payload_forces_agency_false_for_anonymous(): void
    {
        $out = Peanut_Connect_Feedback::build_store_payload(
            ['author_is_agency' => true, 'page_url' => '/x/', 'anchor_x' => 0, 'anchor_y' => 0, 'author_name' => 'Client', 'body' => 'b'],
            false
        );
        $this->assertFalse($out['author_is_agency']);          // client can't claim agency
    }
}
