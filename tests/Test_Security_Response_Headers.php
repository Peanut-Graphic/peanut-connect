<?php
require_once dirname(__DIR__) . '/includes/class-connect-security.php';

class Test_Security_Response_Headers extends Peanut_Connect_TestCase {
    public function test_existing_header_names_are_preserved_case_insensitively(): void {
        $this->assertSame(
            ['X-Content-Type-Options' => 'nosniff'],
            Peanut_Connect_Security::build_security_headers(
                ['Referrer-Policy' => 'strict-origin-when-cross-origin', 'X-Content-Type-Options' => 'nosniff'],
                ['rEfErReR-pOlIcY: no-referrer']
            )
        );
    }

    public function test_filter_output_cannot_produce_duplicate_or_malformed_header_lines(): void {
        $this->assertSame(
            ['Referrer-Policy' => 'same-origin', 'X-Zero' => '0'],
            Peanut_Connect_Security::build_security_headers([
                'Referrer-Policy' => 'same-origin',
                'referrer-policy' => 'no-referrer',
                "X-Bad\nName" => 'bad',
                'X-Bad-Value' => "ok\r\nInjected: yes",
                'X-Null' => "bad\0value",
                'X-Array' => [],
                'X-Empty' => '',
                'X-Zero' => '0',
            ], [])
        );
    }

    public function test_header_feature_remains_off_without_explicit_opt_in(): void {
        $GLOBALS['mock_options'] = [];
        $this->assertFalse(Peanut_Connect_Security::get_settings()['security_headers']);
        $GLOBALS['mock_options']['peanut_connect_security_headers'] = '1';
        $this->assertTrue(Peanut_Connect_Security::get_settings()['security_headers']);
        $GLOBALS['mock_options'] = [];
    }
}
