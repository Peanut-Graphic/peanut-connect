<?php
/**
 * Tests for wire-protocol negotiation (X-Peanut-Protocol). A missing header is
 * legacy v1 (accepted); an unknown declared version is rejected so a future
 * breaking change fails loudly instead of as an opaque signature mismatch.
 *
 * @package Peanut_Connect
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/class-connect-auth.php';

class Test_Protocol_Version extends TestCase {

    public function test_missing_header_is_accepted_as_v1(): void {
        $this->assertTrue(Peanut_Connect_Auth::is_supported_protocol(''));
    }

    public function test_current_version_accepted(): void {
        $this->assertTrue(Peanut_Connect_Auth::is_supported_protocol('1'));
    }

    public function test_unknown_versions_rejected(): void {
        foreach (['2', '0', '1.0', 'v1', 'x', '11'] as $bad) {
            $this->assertFalse(Peanut_Connect_Auth::is_supported_protocol($bad), $bad);
        }
    }
}
