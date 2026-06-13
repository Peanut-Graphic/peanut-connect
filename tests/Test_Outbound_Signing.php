<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__) . '/includes/class-connect-secret.php';
require_once dirname(__DIR__) . '/includes/class-connect-auth.php';
class Test_Outbound_Signing extends TestCase {
    protected function setUp(): void { parent::setUp(); global $mock_options; $mock_options = []; $GLOBALS['mock_wp_salt']='fixed-salt'; }
    protected function tearDown(): void { unset($GLOBALS['mock_wp_salt']); parent::tearDown(); }
    public function test_empty_when_unpaired(): void {
        $this->assertSame([], Peanut_Connect_Auth::outbound_signature_headers('POST','https://hub/api/v1/x','{}'));
    }
    public function test_headers_present_and_self_verifying(): void {
        Peanut_Connect_Auth::set_hub_api_key('the-key');
        $url='https://hub.example/api/v1/forms/submit'; $body='{"a":1}';
        $h = Peanut_Connect_Auth::outbound_signature_headers('POST',$url,$body);
        $this->assertSame('1',$h['X-Peanut-Protocol']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/',$h['X-Peanut-Signature']);
        // The signature verifies under the SAME route(=path) + body the Hub will recompute.
        $ok = Peanut_Connect_Auth::verify_signature('the-key','POST','/api/v1/forms/submit',$h['X-Peanut-Timestamp'],$h['X-Peanut-Nonce'],$body,$h['X-Peanut-Signature']);
        $this->assertTrue($ok);
    }
    public function test_get_with_empty_body(): void {
        Peanut_Connect_Auth::set_hub_api_key('k');
        $h = Peanut_Connect_Auth::outbound_signature_headers('GET','https://hub/api/v1/forms/active','');
        $this->assertTrue(Peanut_Connect_Auth::verify_signature('k','GET','/api/v1/forms/active',$h['X-Peanut-Timestamp'],$h['X-Peanut-Nonce'],'',$h['X-Peanut-Signature']));
    }
}
