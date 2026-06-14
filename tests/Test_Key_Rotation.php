<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__) . '/includes/class-connect-secret.php';
require_once dirname(__DIR__) . '/includes/class-connect-auth.php';
require_once dirname(__DIR__) . '/includes/class-connect-key-rotation.php';

class Test_Key_Rotation extends TestCase {
    protected function setUp(): void { parent::setUp(); global $mock_options; $mock_options=[]; $GLOBALS['mock_wp_salt']='fixed-salt'; }
    protected function tearDown(): void { unset($GLOBALS['mock_wp_salt']); parent::tearDown(); }

    public function test_generate_key_format(): void {
        $k = Peanut_Connect_Key_Rotation::generate_key();
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{64}$/', $k);
    }
    public function test_signed_headers_verify_with_path_route(): void {
        $url='https://hub.example/api/v1/sites/rotate'; $body='{"new_key_hash":"x"}';
        $h = Peanut_Connect_Key_Rotation::signed_headers('the-key','POST',$url,$body);
        $this->assertSame('Bearer the-key',$h['Authorization']);
        $this->assertSame('1',$h['X-Peanut-Protocol']);
        $ok = Peanut_Connect_Auth::verify_signature('the-key','POST','/api/v1/sites/rotate',$h['X-Peanut-Timestamp'],$h['X-Peanut-Nonce'],$body,$h['X-Peanut-Signature']);
        $this->assertTrue($ok);
    }
    public function test_rotate_noops_when_unpaired(): void {
        $r = Peanut_Connect_Key_Rotation::rotate();
        $this->assertFalse($r['success']);
        $this->assertSame('Not paired', $r['message']);
    }
}
