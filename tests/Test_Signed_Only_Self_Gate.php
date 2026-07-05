<?php
/**
 * Regression tests for the residual hardening cluster:
 *
 *  (P1) Legacy-unsigned-Bearer hole. verify_hub_request() used to accept a
 *       plain Bearer token forever, because the "require signed" gate defaulted
 *       false and was written NOWHERE. A leaked key alone (no signature/nonce/
 *       timestamp) could authenticate replayable privileged commands. The fix
 *       self-gates: the FIRST fully-HMAC-verified inbound request flips
 *       peanut_connect_require_signed_requests on, after which an unsigned
 *       Bearer request is rejected.
 *
 *  (P2) Secret plaintext-degrade. When encryption was unavailable, encrypt()
 *       returned the plaintext unchanged and set_hub_api_key() persisted the
 *       cleartext Hub key in wp_options permanently. The fix fails closed:
 *       set_hub_api_key() stores nothing and raises the admin notice.
 *
 * @package Peanut_Connect
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/class-connect-secret.php';
require_once dirname(__DIR__) . '/includes/class-connect-rate-limiter.php';
require_once dirname(__DIR__) . '/includes/class-connect-auth.php';

// add_option is not part of the shared mock bootstrap; provide it here with
// real WP INSERT semantics (returns false when the option already exists) so
// the single-use-nonce claim in verify_signed_hub_request() behaves correctly.
if (!function_exists('add_option')) {
    function add_option($option, $value = '', $deprecated = '', $autoload = 'yes') {
        global $mock_options;
        if (is_array($mock_options) && array_key_exists($option, $mock_options)) {
            return false;
        }
        $mock_options[$option] = $value;
        return true;
    }
}

// The rate limiter (called at the top of verify_hub_request) runs the client
// IP through apply_filters; the shared mock bootstrap doesn't define it. Passthrough.
if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value, ...$args) {
        return $value;
    }
}

/**
 * Request stub adding the method/route/body accessors that
 * verify_signed_hub_request() needs on top of the mock's header bag.
 */
class PC_Signed_Test_Request extends WP_REST_Request {
    private string $pc_method;
    private string $pc_route;
    private string $pc_body;

    public function __construct(array $headers = [], string $method = 'POST', string $route = '/peanut-connect/v1/health', string $body = '') {
        parent::__construct($headers);
        $this->pc_method = $method;
        $this->pc_route = $route;
        $this->pc_body = $body;
    }

    public function get_method() {
        return $this->pc_method;
    }

    public function get_route() {
        return $this->pc_route;
    }

    public function get_body() {
        return $this->pc_body;
    }
}

class Test_Signed_Only_Self_Gate extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        global $mock_options, $mock_transients;
        $mock_options = [];
        $mock_transients = [];
        $GLOBALS['mock_wp_salt'] = 'fixed-salt-for-tests';
    }

    protected function tearDown(): void {
        unset($GLOBALS['mock_wp_salt']);
        parent::tearDown();
    }

    private function signed_request(string $key, string $method, string $route, string $body): PC_Signed_Test_Request {
        $ts = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $sig = Peanut_Connect_Auth::compute_request_signature($key, $method, $route, $ts, $nonce, $body);
        return new PC_Signed_Test_Request([
            'X-Peanut-Signature' => $sig,
            'X-Peanut-Timestamp' => $ts,
            'X-Peanut-Nonce'     => $nonce,
        ], $method, $route, $body);
    }

    /**
     * P1: after one fully-verified signed request, the site self-gates to
     * signed-only, and a subsequent UNSIGNED Bearer request is rejected.
     */
    public function test_first_verified_signed_request_self_gates_signed_only(): void {
        $key = 'shared-hub-key-abc123';
        Peanut_Connect_Auth::set_hub_api_key($key);

        // Precondition: the gate is not set (default-false, written nowhere).
        $this->assertFalse((bool) get_option('peanut_connect_require_signed_requests', false));

        // A leaked-key-only Bearer request is accepted BEFORE the gate flips
        // (this is the legacy hole the fix closes only after a signed request).
        $legacy_before = new PC_Signed_Test_Request(['Authorization' => 'Bearer ' . $key], 'POST', '/peanut-connect/v1/health', '');
        $this->assertTrue(Peanut_Connect_Auth::verify_hub_request($legacy_before));

        // Now a FULLY signed request verifies...
        $signed = $this->signed_request($key, 'POST', '/peanut-connect/v1/health', '');
        $this->assertTrue(Peanut_Connect_Auth::verify_hub_request($signed));

        // ...and self-flips the require-signed gate.
        $this->assertTrue((bool) get_option('peanut_connect_require_signed_requests', false));

        // A subsequent UNSIGNED Bearer request (leaked key, no signature) is now
        // rejected with signature_required — the leaked bearer is useless.
        $unsigned = new PC_Signed_Test_Request(['Authorization' => 'Bearer ' . $key], 'POST', '/peanut-connect/v1/health', '');
        $result = Peanut_Connect_Auth::verify_hub_request($unsigned);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('signature_required', $result->get_error_code());
    }

    /**
     * The gate must NOT flip on a request that FAILS signature verification —
     * otherwise a bad actor could lock a site into signed-only.
     */
    public function test_bad_signature_does_not_self_gate(): void {
        $key = 'shared-hub-key-abc123';
        Peanut_Connect_Auth::set_hub_api_key($key);

        $ts = (string) time();
        $bad = new PC_Signed_Test_Request([
            'X-Peanut-Signature' => str_repeat('0', 64),
            'X-Peanut-Timestamp' => $ts,
            'X-Peanut-Nonce'     => bin2hex(random_bytes(16)),
        ], 'POST', '/peanut-connect/v1/health', '');

        $result = Peanut_Connect_Auth::verify_hub_request($bad);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertFalse((bool) get_option('peanut_connect_require_signed_requests', false));
    }

    /**
     * P2: with encryption unavailable, set_hub_api_key() must FAIL CLOSED —
     * store no cleartext and raise the admin notice.
     */
    public function test_set_hub_api_key_fails_closed_when_encryption_unavailable(): void {
        global $mock_options;
        // Empty salt => derive_key() returns null => encryption unavailable.
        $GLOBALS['mock_wp_salt'] = '';

        $ok = Peanut_Connect_Auth::set_hub_api_key('super-secret-hub-key');

        $this->assertFalse($ok, 'set must report failure when it cannot encrypt');
        // The cleartext key must NOT be persisted anywhere.
        $this->assertArrayNotHasKey('peanut_connect_hub_api_key', $mock_options);
        $this->assertSame('', (string) get_option('peanut_connect_hub_api_key', ''));
        // The degraded state must be surfaced via the admin-notice option.
        $this->assertNotEmpty(get_option('peanut_connect_hub_key_undecryptable'));
    }

    /**
     * encrypt() itself returns null (never plaintext) when encryption is
     * unavailable — pins the fail-closed primitive the write path relies on.
     */
    public function test_encrypt_returns_null_when_salt_unavailable(): void {
        $GLOBALS['mock_wp_salt'] = '';
        $this->assertNull(Peanut_Connect_Secret::encrypt('anything'));
    }
}
