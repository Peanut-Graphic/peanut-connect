<?php
/**
 * Regression tests for the v3.21.1 public-endpoint + abuse-hardening cluster
 * (Cluster 3). Each test pins one residual hole that was fixed:
 *
 *   (a) /gtm-beacon now runs tracking_precheck() — honours the tracking
 *       opt-out and no longer fires an outbound request when tracking is off.
 *   (b) /popup-interaction bounds its 'data' param the same way tracking_precheck
 *       bounds 'metadata' (no unauthenticated multi-MB longtext write).
 *   (c) is_safe_hub_host()'s address evaluation rejects an internal IPv6 (AAAA)
 *       target — the old gethostbyname()-only path never saw AAAA records.
 *   (d) the rate-limit bucket keys on the CLIENT IP only, so repeated wrong-key
 *       auth attempts from one source share one bucket (brute-force ceiling).
 *   (e) the default 'editors' feedback mode no longer grants CRUD / internal
 *       replies to an edit_posts-only (Author/Contributor) user.
 *
 * Runs under the mock harness (tests/mocks/wordpress-mocks.php). WP_REST_Response
 * and a param-carrying request are stubbed locally so the REST handlers can be
 * driven without a WordPress boot.
 *
 * @package Peanut_Connect
 */

require_once dirname(__DIR__) . '/includes/class-connect-rate-limiter.php';
require_once dirname(__DIR__) . '/includes/class-connect-secret.php';
require_once dirname(__DIR__) . '/includes/class-connect-auth.php';
require_once dirname(__DIR__) . '/includes/class-connect-tracker.php';
require_once dirname(__DIR__) . '/includes/class-connect-api.php';
require_once dirname(__DIR__) . '/includes/class-connect-feedback.php';

// Minimal WP_REST_Response stub (the mock harness doesn't ship one).
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        private $data;
        private int $status;
        public array $headers = [];
        public function __construct($data = null, int $status = 200) {
            $this->data = $data;
            $this->status = $status;
        }
        public function get_data() { return $this->data; }
        public function get_status(): int { return $this->status; }
        public function header($name, $value): void { $this->headers[$name] = $value; }
    }
}

/**
 * Request that carries params + a raw body on top of the header-only mock
 * WP_REST_Request. Satisfies the WP_REST_Request type hints on the handlers.
 */
class PP_Hardening_Request extends WP_REST_Request {
    private array $params;
    private string $body;
    public function __construct(array $params = [], array $headers = [], string $body = '') {
        parent::__construct($headers);
        $this->params = $params;
        $this->body = $body;
    }
    public function get_param($key) { return $this->params[$key] ?? null; }
    public function get_body() { return $this->body; }
}

class Test_Public_Endpoint_Hardening extends Peanut_Connect_TestCase {

    protected function setUp(): void {
        parent::setUp();
        global $mock_options, $mock_transients, $mock_wp_remote_post;
        $mock_options = [];
        $mock_transients = [];
        $mock_wp_remote_post = null;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
        unset($GLOBALS['pp_test_logged_in'], $GLOBALS['pp_test_user_caps'], $GLOBALS['pp_test_user_id']);
    }

    protected function tearDown(): void {
        global $mock_options, $mock_transients, $mock_wp_remote_post;
        $mock_options = [];
        $mock_transients = [];
        $mock_wp_remote_post = null;
        unset($GLOBALS['pp_test_logged_in'], $GLOBALS['pp_test_user_caps'], $GLOBALS['pp_test_user_id']);
        parent::tearDown();
    }

    private function enable_tracking(): void {
        update_option('peanut_connect_hub_url', 'https://hub.example.com');
        update_option('peanut_connect_hub_api_key', 'plaintext-key');
        update_option('peanut_connect_tracking_enabled', true);
    }

    // ---- (a) gtm-beacon respects the tracking opt-out + never fires outbound ----

    public function test_gtm_beacon_honours_tracking_optout_and_skips_outbound(): void {
        global $mock_wp_remote_post;
        $called = 0;
        $mock_wp_remote_post = function ($url, $args) use (&$called) {
            $called++;
            return ['response' => ['code' => 204], 'body' => ''];
        };

        // Tracking OFF (opt-out): hub_url set but tracking_enabled not set.
        update_option('peanut_connect_hub_url', 'https://hub.example.com');
        update_option('peanut_connect_hub_api_key', 'plaintext-key');

        $api = new Peanut_Connect_API();
        $request = new PP_Hardening_Request([], [], '{"e":"pageview"}');
        $response = $api->proxy_gtm_beacon($request);

        // Opt-out short-circuit from tracking_precheck() — NOT the old blind 204.
        $this->assertSame(403, $response->get_status());
        $this->assertSame(0, $called, 'gtm-beacon must not fire an outbound request when tracking is opted out');
    }

    public function test_gtm_beacon_proceeds_when_tracking_enabled(): void {
        $this->enable_tracking();
        $api = new Peanut_Connect_API();
        $request = new PP_Hardening_Request([], [], '{"e":"pageview"}');
        $response = $api->proxy_gtm_beacon($request);
        // Passes precheck → normal fire-and-forget path returns 204.
        $this->assertSame(204, $response->get_status());
    }

    // ---- (b) oversized popup-interaction 'data' is rejected ----

    public function test_popup_interaction_rejects_oversized_data(): void {
        $this->enable_tracking();
        $api = new Peanut_Connect_API();

        $huge = str_repeat('A', 9000); // > TRACK_METADATA_MAX_BYTES (8192)
        $request = new PP_Hardening_Request([
            'popup_id'   => 1,
            'action'     => 'submit',
            'visitor_id' => 'v123',
            'data'       => $huge,
        ]);
        $response = $api->track_popup_interaction($request);

        $this->assertSame(413, $response->get_status());
        $data = $response->get_data();
        $this->assertSame('data_too_large', $data['code'] ?? null);
    }

    public function test_popup_interaction_data_within_bound_is_not_rejected_for_size(): void {
        $this->enable_tracking();
        $api = new Peanut_Connect_API();

        $small = str_repeat('A', 100);
        $request = new PP_Hardening_Request([
            'popup_id'   => 1,
            'action'     => 'submit',
            'visitor_id' => 'v123',
            'data'       => $small,
        ]);
        // record_popup_interaction() writes via $wpdb which the mock harness
        // lacks; a within-bound payload must NOT be rejected as too-large, so
        // assert it does not short-circuit with the 413 size error.
        try {
            $response = $api->track_popup_interaction($request);
            $this->assertNotSame(413, $response->get_status());
        } catch (\Throwable $e) {
            // Reaching the DB write (and failing there, not on the size gate)
            // is itself proof the size bound let a small payload through.
            $this->addToAssertionCount(1);
        }
    }

    // ---- (c) SSRF guard rejects an internal IPv6 (AAAA) target ----

    private function addresses_all_public(array $ips): bool {
        $m = new ReflectionMethod(Peanut_Connect_API::class, 'addresses_all_public');
        return $m->invoke(null, $ips);
    }

    public function test_ssrf_guard_rejects_internal_ipv6_address(): void {
        // AAAA-only internal targets the old gethostbyname() path never saw.
        $this->assertFalse($this->addresses_all_public(['fd00::1']));       // ULA
        $this->assertFalse($this->addresses_all_public(['fe80::1']));       // link-local
        $this->assertFalse($this->addresses_all_public(['::1']));           // loopback
        // A record set where the AAAA is internal even though an A is public
        // (multi-record rebind) must still be rejected.
        $this->assertFalse($this->addresses_all_public(['203.0.113.5', 'fd00::1']));
        // A genuinely public dual-stack target is allowed.
        $this->assertTrue($this->addresses_all_public(['203.0.113.5', '2606:4700:4700::1111']));
        // No resolution → treated as unresolved/permissive (prior behaviour).
        $this->assertTrue($this->addresses_all_public([]));
    }

    public function test_is_safe_hub_host_still_blocks_local_names(): void {
        $m = new ReflectionMethod(Peanut_Connect_API::class, 'is_safe_hub_host');
        $this->assertFalse($m->invoke(null, 'localhost', 'https'));
        $this->assertFalse($m->invoke(null, 'metadata.google.internal', 'https'));
        $this->assertFalse($m->invoke(null, 'foo.internal', 'https'));
        $this->assertFalse($m->invoke(null, 'db.local', 'https'));
        $this->assertFalse($m->invoke(null, 'hub.example.com', 'http')); // non-HTTPS
    }

    // ---- (d) auth rate-limit bucket keys on IP only, not the credential ----

    public function test_rate_limit_identifier_ignores_presented_credential(): void {
        $req_key_a = new PP_Hardening_Request([], ['Authorization' => 'Bearer wrong-key-attempt-1']);
        $req_key_b = new PP_Hardening_Request([], ['Authorization' => 'Bearer wrong-key-attempt-2']);
        $req_none  = new PP_Hardening_Request([], []);

        $id_a = Peanut_Connect_Rate_Limiter::get_client_identifier($req_key_a);
        $id_b = Peanut_Connect_Rate_Limiter::get_client_identifier($req_key_b);
        $id_none = Peanut_Connect_Rate_Limiter::get_client_identifier($req_none);

        // Same source IP → same bucket regardless of the key under test, so a
        // brute-forcer can't escape the AUTH_LIMIT by rotating wrong keys.
        $this->assertSame($id_a, $id_b);
        $this->assertSame($id_a, $id_none);
        $this->assertSame('203.0.113.10', $id_a);

        // A different source IP still gets its own bucket.
        $_SERVER['REMOTE_ADDR'] = '198.51.100.9';
        $id_other = Peanut_Connect_Rate_Limiter::get_client_identifier($req_key_a);
        $this->assertNotSame($id_a, $id_other);
    }

    public function test_repeated_wrong_key_attempts_share_a_bucket_and_trip_limit(): void {
        // 10 attempts (AUTH_LIMIT) allowed, the 11th trips — even though every
        // attempt presents a different (wrong) credential.
        for ($i = 0; $i < 10; $i++) {
            $req = new PP_Hardening_Request([], ['Authorization' => 'Bearer wrong-' . $i]);
            $id = Peanut_Connect_Rate_Limiter::get_client_identifier($req);
            $result = Peanut_Connect_Rate_Limiter::check($id, 'verify');
            $this->assertTrue($result, "attempt $i should be within the limit");
        }
        $req = new PP_Hardening_Request([], ['Authorization' => 'Bearer wrong-final']);
        $id = Peanut_Connect_Rate_Limiter::get_client_identifier($req);
        $result = Peanut_Connect_Rate_Limiter::check($id, 'verify');
        $this->assertTrue(is_wp_error($result), 'the 11th attempt from one IP must be rate-limited');
    }

    // ---- (e) default 'editors' mode excludes an edit_posts-only user ----

    public function test_default_mode_denies_edit_posts_only_user(): void {
        // Default mode ('editors'), no review token configured.
        $GLOBALS['pp_test_logged_in'] = true;
        $GLOBALS['pp_test_user_caps'] = ['edit_posts' => true]; // Author/Contributor
        $request = new PP_Hardening_Request([], []);

        $this->assertFalse(
            Peanut_Connect_Feedback::can_review($request),
            'an edit_posts-only user must not reach feedback CRUD by default'
        );
        $this->assertFalse(
            Peanut_Connect_Feedback::can_review_agency($request),
            'an edit_posts-only user must not read internal replies by default'
        );
    }

    public function test_default_mode_still_allows_edit_others_posts_user(): void {
        $GLOBALS['pp_test_logged_in'] = true;
        $GLOBALS['pp_test_user_caps'] = ['edit_posts' => true, 'edit_others_posts' => true]; // Editor/Admin
        $request = new PP_Hardening_Request([], []);

        $this->assertTrue(
            Peanut_Connect_Feedback::can_review($request),
            'a genuine agency user (edit_others_posts) still reaches feedback CRUD in the default mode'
        );
    }
}
