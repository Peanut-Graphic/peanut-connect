<?php
/**
 * Per-visitor and per-reviewer secrets must not be printed into cacheable
 * HTML, and public tracking input must not rewrite someone else's identity.
 *
 * Before this fix, with any page cache in front of the site:
 *  - the tracker config (peanutConnectTracker) carried visitorId and clickId,
 *    and tracker.js trusted them over the browser's cookie, then wrote them
 *    INTO the cookie: every visitor after the first was attached to the first
 *    visitor's journey;
 *  - the popups config carried visitorId (same effect for popup events);
 *  - the feedback widget config carried reviewToken, copied out of the
 *    HttpOnly pp_review cookie: a cached review page handed the review token
 *    to every later visitor;
 *  - POST /identify (and /conversion) overwrote a visitor's email and name,
 *    so a leaked visitor id let anyone relabel that visitor;
 *  - POST /track stored any click_id string and shipped it to Hub;
 *  - POST /approvals/vote took approver_id from the body on trust, so anyone
 *    with the shared review link could sign off as any approver.
 *
 * @package Peanut_Connect
 */

// ---- minimal WordPress stand-ins this test needs (guarded) -----------------
if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script(...$args) { $GLOBALS['pcs_enqueued'][] = $args[0] ?? ''; }
}
if (!function_exists('wp_enqueue_style')) {
    function wp_enqueue_style(...$args) {}
}
if (!function_exists('wp_localize_script')) {
    function wp_localize_script($handle, $name, $data) { $GLOBALS['pcs_localized'][$name] = $data; return true; }
}
if (!function_exists('wp_add_inline_script')) {
    function wp_add_inline_script(...$args) { return true; }
}
if (!function_exists('plugins_url')) {
    function plugins_url($path = '', $plugin = '') { return 'https://site.example/wp-content/plugins/peanut-connect/' . ltrim($path, '/'); }
}
if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path($file) { return rtrim(dirname($file), '/') . '/'; }
}
if (!function_exists('admin_url')) {
    function admin_url($path = '') { return 'https://site.example/wp-admin/' . ltrim($path, '/'); }
}
if (!function_exists('rest_url')) {
    function rest_url($path = '') { return 'https://site.example/wp-json/' . ltrim($path, '/'); }
}
if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url) { return (string) $url; }
}
if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($action = -1) { return 'nonce-' . md5((string) $action); }
}
if (!function_exists('is_ssl')) {
    function is_ssl() { return true; }
}
if (!function_exists('nocache_headers')) {
    // Same recording contract as Test_Security_404_Deferral's stand-in
    // (whichever file loads first defines it for the whole run).
    function nocache_headers(): void { $GLOBALS['peanut_test_nocache'] = true; }
}
if (!defined('PEANUT_CONNECT_VERSION')) {
    define('PEANUT_CONNECT_VERSION', 'test');
}
if (!defined('PEANUT_CONNECT_API_NAMESPACE')) {
    define('PEANUT_CONNECT_API_NAMESPACE', 'peanut-connect/v1');
}

/** $wpdb stand-in: a visitors table in memory, plus an event insert log. */
class Peanut_Test_Identity_Wpdb {
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    /** @var array<string, array{email: ?string, name: ?string}> */
    public array $visitors = [];
    public array $inserts = [];

    public function prepare($sql, ...$args) { return [$sql, $args]; }

    public function query($prepared) {
        [$sql, $args] = $prepared;
        if (str_contains($sql, 'SET email = %s')) {
            [$email, $vid] = $args;
            if (isset($this->visitors[$vid]) && in_array($this->visitors[$vid]['email'], [null, ''], true)) {
                $this->visitors[$vid]['email'] = $email;
                return 1;
            }
            return 0;
        }
        if (str_contains($sql, 'SET name = %s')) {
            [$name, $vid, $email] = $args;
            $row = $this->visitors[$vid] ?? null;
            if ($row && $row['email'] === $email && in_array($row['name'], [null, ''], true)) {
                $this->visitors[$vid]['name'] = $name;
                return 1;
            }
            return 0;
        }
        return 0;
    }

    public function update($table, $data, $where, $f = null, $wf = null) {
        // The pre-fix identify_visitor() overwrote with update().
        $vid = $where['visitor_id'] ?? null;
        if ($vid !== null && isset($this->visitors[$vid])) {
            $this->visitors[$vid] = array_merge($this->visitors[$vid], array_intersect_key($data, ['email' => 1, 'name' => 1]));
            return 1;
        }
        return 0;
    }

    public function insert($table, $data, $format = null) {
        $this->inserts[] = ['table' => $table, 'data' => $data];
        $this->insert_id = count($this->inserts);
        return 1;
    }
}

class Test_Cache_Safe_Identity extends Peanut_Connect_TestCase {

    private const CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    private const REVIEW_TOKEN = 'review-token-0123456789abcdef';

    private array $cookieBackup = [];
    private array $getBackup = [];

    protected function setUp(): void {
        parent::setUp();
        global $mock_options;
        $mock_options = [];
        $GLOBALS['pcs_localized'] = [];
        $this->cookieBackup = $_COOKIE;
        $this->getBackup = $_GET;
        $_SERVER['HTTP_USER_AGENT'] = self::CHROME;
        $GLOBALS['wpdb'] = new Peanut_Test_Identity_Wpdb();
        $this->resetTrackerStatics();
        unset($GLOBALS['pp_test_logged_in'], $GLOBALS['pp_test_user_caps']);
    }

    protected function tearDown(): void {
        $_COOKIE = $this->cookieBackup;
        $_GET = $this->getBackup;
        unset($GLOBALS['wpdb'], $_SERVER['HTTP_USER_AGENT'], $GLOBALS['pp_test_logged_in'], $GLOBALS['pp_test_user_caps']);
        $this->resetTrackerStatics();
        global $mock_options;
        $mock_options = [];
        parent::tearDown();
    }

    private function resetTrackerStatics(): void {
        foreach (['visitor_id', 'click_id'] as $prop) {
            $p = new ReflectionProperty('Peanut_Connect_Tracker', $prop);
            if (PHP_VERSION_ID < 80100) {
                $p->setAccessible(true);
            }
            $p->setValue(null, null);
        }
    }

    // ---- nothing per-visitor in cacheable page config ----------------------

    public function test_tracker_config_carries_no_visitor_or_click_id(): void {
        $_COOKIE['peanut_vid'] = str_repeat('a', 32);
        $_GET['click_id'] = '11111111-1111-4111-8111-111111111111';

        Peanut_Connect_Tracker::enqueue_tracking_script();

        $config = $GLOBALS['pcs_localized']['peanutConnectTracker'];
        $this->assertArrayNotHasKey('visitorId', $config);
        $this->assertArrayNotHasKey('clickId', $config);
        $printed = (string) json_encode($config);
        $this->assertStringNotContainsString(str_repeat('a', 32), $printed);
        $this->assertStringNotContainsString('11111111-1111-4111-8111-111111111111', $printed);
        $this->assertSame('peanut_vid', $config['cookieName'], 'site-wide cookie name stays');
    }

    public function test_popup_config_carries_no_visitor_id(): void {
        $ref = new ReflectionClass('Peanut_Connect_Popup_Display');
        $src = (string) file_get_contents((string) $ref->getFileName());

        // The localize call must not print a visitor id.
        $this->assertDoesNotMatchRegularExpression("/'visitorId'\s*=>/", $src);
        $this->assertStringNotContainsString('get_visitor_id()', $src);
    }

    public function test_feedback_config_never_carries_the_review_token(): void {
        update_option('peanut_connect_feedback_review_token', self::REVIEW_TOKEN);
        update_option(Peanut_Connect_Feedback::ACCESS_OPTION, 'token');

        // Cookie-borne reviewer (the cached-page case) ...
        $_COOKIE[Peanut_Connect_Feedback::REVIEW_COOKIE] = self::REVIEW_TOKEN;
        Peanut_Connect_Feedback::enqueue();
        $this->assertArrayHasKey('peanutConnectFeedback', $GLOBALS['pcs_localized'], 'widget still boots for the reviewer');
        $this->assertStringNotContainsString(self::REVIEW_TOKEN, (string) json_encode($GLOBALS['pcs_localized']['peanutConnectFeedback']));

        // ... and URL-borne reviewer.
        $GLOBALS['pcs_localized'] = [];
        unset($_COOKIE[Peanut_Connect_Feedback::REVIEW_COOKIE]);
        $_GET['pp_review'] = self::REVIEW_TOKEN;
        $_GET['pp_as'] = 'pat';
        Peanut_Connect_Feedback::enqueue();
        $printed = (string) json_encode($GLOBALS['pcs_localized']['peanutConnectFeedback']);
        $this->assertStringNotContainsString(self::REVIEW_TOKEN, $printed);
        $this->assertArrayNotHasKey('youApproverId', $GLOBALS['pcs_localized']['peanutConnectFeedback']);
    }

    public function test_review_pages_are_marked_uncacheable(): void {
        update_option('peanut_connect_feedback_review_token', self::REVIEW_TOKEN);
        update_option(Peanut_Connect_Feedback::ACCESS_OPTION, 'token');
        $_COOKIE[Peanut_Connect_Feedback::REVIEW_COOKIE] = self::REVIEW_TOKEN;

        $this->assertTrue(method_exists('Peanut_Connect_Feedback', 'disable_page_cache_in_review'));
        Peanut_Connect_Feedback::disable_page_cache_in_review();

        $this->assertTrue(defined('DONOTCACHEPAGE') && DONOTCACHEPAGE);
    }

    // ---- the REST gate reads the HttpOnly cookie itself ---------------------

    public function test_cookie_reviewer_is_accepted_only_with_the_widget_header(): void {
        update_option('peanut_connect_feedback_review_token', self::REVIEW_TOKEN);
        update_option(Peanut_Connect_Feedback::ACCESS_OPTION, 'token');
        $_COOKIE[Peanut_Connect_Feedback::REVIEW_COOKIE] = self::REVIEW_TOKEN;

        $this->assertTrue(Peanut_Connect_Feedback::can_review(new WP_REST_Request(['X-Peanut-Review' => '1'])));
        $this->assertFalse(
            Peanut_Connect_Feedback::can_review(new WP_REST_Request([])),
            'without the custom header (e.g. a cross-site form post) the cookie is not honored'
        );

        $_COOKIE[Peanut_Connect_Feedback::REVIEW_COOKIE] = 'wrong-token';
        $this->assertFalse(Peanut_Connect_Feedback::can_review(new WP_REST_Request(['X-Peanut-Review' => '1'])));
    }

    // ---- /identify is fill-only -----------------------------------------------

    public function test_identify_never_overwrites_an_existing_identity(): void {
        $db = $GLOBALS['wpdb'];
        $db->visitors['victim'] = ['email' => 'victim@example.com', 'name' => 'Victim'];

        Peanut_Connect_Tracker::identify_visitor('victim', 'attacker@example.com', 'Attacker');

        $this->assertSame('victim@example.com', $db->visitors['victim']['email']);
        $this->assertSame('Victim', $db->visitors['victim']['name']);
    }

    public function test_identify_fills_an_anonymous_visitor(): void {
        $db = $GLOBALS['wpdb'];
        $db->visitors['anon'] = ['email' => null, 'name' => null];

        Peanut_Connect_Tracker::identify_visitor('anon', 'new@example.com', 'New Person');

        $this->assertSame('new@example.com', $db->visitors['anon']['email']);
        $this->assertSame('New Person', $db->visitors['anon']['name']);
    }

    public function test_identify_does_not_attach_a_second_identitys_name(): void {
        $db = $GLOBALS['wpdb'];
        $db->visitors['v'] = ['email' => 'first@example.com', 'name' => null];

        Peanut_Connect_Tracker::identify_visitor('v', 'second@example.com', 'Second');

        $this->assertNull($db->visitors['v']['name']);
    }

    // ---- /track click_id must be a UUID --------------------------------------

    /** @dataProvider badClickIds */
    public function test_record_event_drops_a_malformed_click_id(string $bad): void {
        Peanut_Connect_Tracker::record_event(str_repeat('c', 32), 'pageview', ['click_id' => $bad, 'page_url' => 'https://site.example/']);

        $row = $GLOBALS['wpdb']->inserts[0]['data'] ?? null;
        $this->assertNotNull($row);
        $this->assertNull($row['click_id'], 'stored click_id for input ' . json_encode($bad));
    }

    public static function badClickIds(): array {
        return [
            'dashes only' => ['------------------------------------'],
            'hex no dashes' => [str_repeat('a', 36)],
            'script' => ['<script>alert(1)</script>'],
            'long junk' => [str_repeat('x', 5000)],
            'uuid-ish wrong groups' => ['1111111-11111-4111-8111-111111111111'],
        ];
    }

    public function test_record_event_keeps_a_valid_click_id(): void {
        $uuid = '3F2B8C1E-4D5A-4B6C-8D7E-9F0A1B2C3D4E';
        Peanut_Connect_Tracker::record_event(str_repeat('c', 32), 'pageview', ['click_id' => $uuid, 'page_url' => 'https://site.example/']);

        $this->assertSame(strtolower($uuid), $GLOBALS['wpdb']->inserts[0]['data']['click_id']);
    }

    // ---- approvals: approver identity is bound to a personal key -------------

    private function voteRequest(array $body, array $headers = []): WP_REST_Request {
        return new class($body, $headers) extends WP_REST_Request {
            private array $body;
            public function __construct(array $body, array $headers) { parent::__construct($headers); $this->body = $body; }
            public function get_json_params() { return $this->body; }
        };
    }

    private function configureApprovers(): void {
        update_option('peanut_connect_feedback_review_token', self::REVIEW_TOKEN);
        update_option(Peanut_Connect_Feedback::ACCESS_OPTION, 'token');
        update_option(Peanut_Connect_Approvals::APPROVERS_OPTION, [
            ['id' => 'pat', 'name' => 'Pat Owner', 'initials' => 'PO', 'required' => true],
            ['id' => 'sam', 'name' => 'Sam Legal', 'initials' => 'SL', 'required' => true],
        ]);
    }

    public function test_shared_review_token_cannot_vote_as_an_approver(): void {
        $this->configureApprovers();
        $request = $this->voteRequest(
            ['path' => '/pricing/', 'approver_id' => 'pat', 'vote' => 'yes'],
            ['X-Peanut-Review-Token' => self::REVIEW_TOKEN]
        );

        $result = Peanut_Connect_Approvals::vote($request);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('pca_not_your_approver', $result->get_error_code());
        $this->assertSame([], Peanut_Connect_Approvals::page_state('/pricing/')['votes']);
    }

    public function test_one_approvers_key_cannot_vote_as_another(): void {
        $this->configureApprovers();
        $samKey = Peanut_Connect_Approvals::approver_key('sam');
        $request = $this->voteRequest(
            ['path' => '/pricing/', 'approver_id' => 'pat', 'vote' => 'yes'],
            ['X-Peanut-Review-Token' => self::REVIEW_TOKEN, 'X-Peanut-Approver-Key' => $samKey]
        );

        $this->assertInstanceOf(WP_Error::class, Peanut_Connect_Approvals::vote($request));
    }

    public function test_approver_key_is_not_derivable_from_the_review_token_and_rotates_with_it(): void {
        $this->configureApprovers();
        $key = Peanut_Connect_Approvals::approver_key('pat');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $key);
        $this->assertNotSame(Peanut_Connect_Approvals::approver_key('sam'), $key);

        update_option('peanut_connect_feedback_review_token', 'rotated-token-0123456789abcdef');
        $this->assertNotSame($key, Peanut_Connect_Approvals::approver_key('pat'), 'rotating the review token revokes approver links');
        $this->assertFalse(Peanut_Connect_Approvals::approver_key_matches('pat', $key));
    }

    public function test_approvers_own_key_can_vote_and_agency_can_record_anyone(): void {
        $this->configureApprovers();
        $this->assertTrue(Peanut_Connect_Approvals::may_vote_as(
            new WP_REST_Request(['X-Peanut-Review-Token' => self::REVIEW_TOKEN, 'X-Peanut-Approver-Key' => Peanut_Connect_Approvals::approver_key('pat')]),
            'pat'
        ));

        $GLOBALS['pp_test_logged_in'] = true;
        $GLOBALS['pp_test_user_caps'] = ['edit_posts' => true, 'edit_others_posts' => true];
        $this->assertTrue(Peanut_Connect_Approvals::may_vote_as(
            new WP_REST_Request(['X-Peanut-Review-Token' => self::REVIEW_TOKEN]),
            'sam'
        ));
    }

    public function test_personal_links_carry_the_approver_key_and_it_is_stripped_from_page_keys(): void {
        $src = (string) file_get_contents(dirname(__DIR__) . '/includes/class-connect-approvals.php');
        $this->assertStringContainsString("'pp_ak' => self::approver_key(\$row['id'])", $src);
        $this->assertContains('pp_ak', Peanut_Connect_Approvals::STRIP_PARAMS);
        $this->assertSame('/pricing/', Peanut_Connect_Approvals::normalize_path('/pricing/?pp_as=pat&pp_ak=abc'));
    }
}
