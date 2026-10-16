<?php
/**
 * Hub forms: auth header contract + public submit proxy scope.
 *
 *   (a) forms sync and the public submit proxy authenticate to Hub with
 *       `Authorization: Bearer <key>` (+ the D-11 signature headers), like
 *       every other outbound Hub call. They sent `X-Site-Api-Key`, which Hub's
 *       ValidateSiteApiKey does not read, so both got 401 and Hub forms were
 *       broken end to end.
 *   (b) the anonymous submit proxy forwards only for a form slug synced to
 *       THIS site (the local hub_forms table). Once the header works, the proxy
 *       would otherwise relay any slug the site key can reach.
 *   (c) the proxy forwards only Hub's submit contract keys and keeps its
 *       rate limit.
 *   (d) no per-visitor value is printed into the form markup (page caches
 *       replay it to every visitor), and the proxy takes the visitor id from
 *       the request's own peanut_vid cookie, never from the body.
 *
 * @package Peanut_Connect
 */

require_once dirname(__DIR__) . '/includes/class-connect-rate-limiter.php';
require_once dirname(__DIR__) . '/includes/class-connect-secret.php';
require_once dirname(__DIR__) . '/includes/class-connect-auth.php';
require_once dirname(__DIR__) . '/includes/class-connect-database.php';
require_once dirname(__DIR__) . '/includes/class-connect-forms.php';

if (!function_exists('trailingslashit')) {
    function trailingslashit($s) {
        return rtrim((string) $s, '/') . '/';
    }
}
if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce($nonce, $action = -1) {
        return ($GLOBALS['pp_forms_nonce_ok'] ?? true) ? 1 : false;
    }
}
// Stand-ins for render_hub_form()'s asset enqueue (guarded).
if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script(...$args) {}
}
if (!function_exists('wp_enqueue_style')) {
    function wp_enqueue_style(...$args) {}
}
if (!function_exists('wp_localize_script')) {
    function wp_localize_script(...$args) { return true; }
}
if (!function_exists('rest_url')) {
    function rest_url($path = '') { return 'https://site.example/wp-json/' . ltrim((string) $path, '/'); }
}
if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce($action = -1) { return 'nonce'; }
}
if (!defined('PEANUT_CONNECT_VERSION')) {
    define('PEANUT_CONNECT_VERSION', 'test');
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        public $data;
        public $status;
        public function __construct($data = null, $status = 200) {
            $this->data = $data;
            $this->status = $status;
        }
        public function get_data() { return $this->data; }
        public function get_status(): int { return (int) $this->status; }
    }
}

/**
 * $wpdb stand-in over an in-memory hub_forms table keyed by slug.
 */
class PP_Forms_Wpdb {
    public string $prefix = 'wp_';
    /** @var array<string,array<string,mixed>> slug => row */
    public array $hub_forms = [];
    /** @var array<int,array{0:string,1:array}> */
    public array $inserts = [];

    public function prepare($sql, ...$args) {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        foreach ($args as $a) {
            $sql = preg_replace('/%[sd]/', is_int($a) ? (string) $a : "'" . addslashes((string) $a) . "'", $sql, 1);
        }
        return $sql;
    }

    private function slug_from(string $sql): ?string {
        return preg_match("/slug = '([^']*)'/", $sql, $m) ? stripslashes($m[1]) : null;
    }

    private function match(string $sql): ?array {
        $slug = $this->slug_from($sql);
        if ($slug === null || !isset($this->hub_forms[$slug])) {
            return null;
        }
        $row = $this->hub_forms[$slug];
        if (str_contains($sql, "status = 'active'") && ($row['status'] ?? '') !== 'active') {
            return null;
        }
        return $row;
    }

    public function get_var($sql) {
        $row = $this->match($sql);
        return $row === null ? null : ($row['id'] ?? 1);
    }

    public function get_row($sql, $output = 'OBJECT') {
        if (str_contains($sql, 'hub_form_id =')) {
            return null; // sync path: treat every form as new
        }
        $row = $this->match($sql);
        if ($row === null) {
            return null;
        }
        return $output === 'ARRAY_A' ? $row : (object) $row;
    }

    public function query($sql) { return 0; }
    public function insert($table, $data) { $this->inserts[] = [$table, $data]; return 1; }
    public function update($table, $data, $where) { return 1; }
    public function delete($table, $where) { return 0; }
}

class PP_Forms_Request extends WP_REST_Request {
    private array $json;
    public function __construct(array $json = [], array $headers = []) {
        parent::__construct($headers);
        $this->json = $json;
    }
    public function get_json_params() { return $this->json; }
    public function get_params() { return $this->json; }
    public function get_param($key) { return $this->json[$key] ?? null; }
}

class Test_Hub_Forms_Auth extends Peanut_Connect_TestCase {

    private PP_Forms_Wpdb $db;
    /** @var array<int,array{url:string,args:array}> */
    private array $posts = [];
    /** @var array<int,array{url:string,args:array}> */
    private array $gets = [];

    protected function setUp(): void {
        parent::setUp();
        global $mock_options, $mock_transients, $mock_wp_remote_post, $mock_wp_remote_get;
        $mock_options = [];
        $mock_transients = [];
        $GLOBALS['mock_wp_salt'] = 'fixed-salt-for-tests';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.77';
        $GLOBALS['pp_forms_nonce_ok'] = true;

        update_option('peanut_connect_hub_url', 'https://hub.example.test');
        Peanut_Connect_Auth::set_hub_api_key('site-key-123');

        $this->db = new PP_Forms_Wpdb();
        $this->db->hub_forms['contact-us'] = ['id' => 1, 'hub_form_id' => 10, 'slug' => 'contact-us', 'status' => 'active'];
        $this->db->hub_forms['retired'] = ['id' => 2, 'hub_form_id' => 11, 'slug' => 'retired', 'status' => 'stale'];
        $GLOBALS['wpdb'] = $this->db;

        $this->posts = [];
        $this->gets = [];
        $mock_wp_remote_post = function (string $url, array $args) {
            $this->posts[] = ['url' => $url, 'args' => $args];
            return ['response' => ['code' => 200], 'body' => wp_json_encode(['success' => true, 'submission_uuid' => 'u-1'])];
        };
        $mock_wp_remote_get = function (string $url, array $args) {
            $this->gets[] = ['url' => $url, 'args' => $args];
            return ['response' => ['code' => 200], 'body' => wp_json_encode(['success' => true, 'forms' => []])];
        };
    }

    protected function tearDown(): void {
        global $mock_options, $mock_transients, $mock_wp_remote_post, $mock_wp_remote_get;
        $mock_options = [];
        $mock_transients = [];
        $mock_wp_remote_post = null;
        $mock_wp_remote_get = null;
        unset($GLOBALS['wpdb'], $GLOBALS['mock_wp_salt'], $GLOBALS['pp_forms_nonce_ok']);
        parent::tearDown();
    }

    private function status(WP_REST_Response $r): int {
        return method_exists($r, 'get_status') ? $r->get_status() : (int) $r->status;
    }

    private function submit(array $payload): WP_REST_Response {
        return Peanut_Connect_Forms::handle_public_submit(
            new PP_Forms_Request($payload, ['X-WP-Nonce' => 'nonce'])
        );
    }

    private function assertHubAuth(array $headers): void {
        $this->assertSame('Bearer site-key-123', $headers['Authorization'] ?? null);
        $this->assertArrayNotHasKey('X-Site-Api-Key', $headers, 'Hub ignores X-Site-Api-Key (401)');
        $this->assertArrayHasKey('X-Peanut-Signature', $headers);
    }

    public function test_renderer_embeds_public_schema_without_private_settings_or_executable_html(): void {
        $render = new ReflectionMethod('Peanut_Connect_Forms', 'render_hub_form');
        if (PHP_VERSION_ID < 80100) { $render->setAccessible(true); }
        $html = $render->invoke(null, [
            'slug' => 'contact-us',
            'fields' => [['type' => 'text', 'name' => 'name', 'label' => '</script><script>alert(1)</script>']],
            'settings' => [
                'general' => ['submit_button_text' => 'Send'],
                'notifications' => ['admin_email' => ['recipients' => ['private@example.test']]],
                'api' => ['secret' => 'secret-value'],
            ],
        ]);
        $this->assertStringContainsString('peanut-form-schema', $html);
        $this->assertStringNotContainsString('private@example.test', $html);
        $this->assertStringNotContainsString('secret-value', $html);
        $this->assertStringNotContainsString('</script><script>', $html);
        preg_match('/class="peanut-form-schema">(.*?)<\/script>/s', $html, $matches);
        $schema = json_decode($matches[1], true);
        $this->assertSame('Send', $schema['button']);
        $this->assertSame('name', $schema['fields'][0]['name']);
    }

    public function test_sync_from_hub_authenticates_with_bearer(): void {
        $result = Peanut_Connect_Forms::sync_from_hub();

        $this->assertTrue($result['success']);
        $this->assertCount(1, $this->gets);
        $this->assertSame('https://hub.example.test/api/v1/forms/active', $this->gets[0]['url']);
        $this->assertHubAuth($this->gets[0]['args']['headers']);
    }

    public function test_public_submit_forwards_with_bearer_for_a_synced_form(): void {
        $res = $this->submit([
            'form_slug' => 'contact-us',
            'data' => ['email' => 'jane@example.com'],
            'visitor_id' => 'v-1',
            'session_id' => 's-1',
            'metadata' => ['form_load_time' => 1, 'form_submit_time' => 9],
        ]);

        $this->assertSame(200, $this->status($res));
        $this->assertCount(1, $this->posts);
        $this->assertSame('https://hub.example.test/api/v1/forms/submit', $this->posts[0]['url']);
        $this->assertHubAuth($this->posts[0]['args']['headers']);
    }

    public function test_public_submit_refuses_a_slug_not_synced_to_this_site(): void {
        $res = $this->submit(['form_slug' => 'some-other-sites-form', 'data' => ['x' => 'y']]);

        $this->assertSame(404, $this->status($res));
        $this->assertCount(0, $this->posts, 'must not relay to Hub');
    }

    public function test_public_submit_refuses_a_stale_or_missing_slug(): void {
        $this->assertSame(404, $this->status($this->submit(['form_slug' => 'retired', 'data' => ['x' => 'y']])));
        $this->assertSame(404, $this->status($this->submit(['data' => ['x' => 'y']])));
        $this->assertSame(404, $this->status($this->submit(['form_slug' => ['contact-us'], 'data' => ['x' => 'y']])));
        $this->assertCount(0, $this->posts);
    }

    public function test_public_submit_forwards_only_the_hub_contract_keys(): void {
        $this->submit([
            'form_slug' => 'contact-us',
            'data' => ['email' => 'jane@example.com'],
            'session_id' => 's-1',
            'site_id' => 999,
            'agency_id' => 5,
        ]);

        $this->assertCount(1, $this->posts);
        $sent = json_decode($this->posts[0]['args']['body'], true);
        $this->assertSame('contact-us', $sent['form_slug']);
        $this->assertSame(['email' => 'jane@example.com'], $sent['data']);
        $this->assertSame('s-1', $sent['session_id']);
        $this->assertArrayNotHasKey('site_id', $sent);
        $this->assertArrayNotHasKey('agency_id', $sent);
    }

    public function test_public_submit_still_requires_the_nonce(): void {
        $GLOBALS['pp_forms_nonce_ok'] = false;
        $res = $this->submit(['form_slug' => 'contact-us', 'data' => ['x' => 'y']]);

        $this->assertSame(403, $this->status($res));
        $this->assertCount(0, $this->posts);
    }

    public function test_form_markup_carries_no_per_visitor_ids(): void {
        $_COOKIE['peanut_vid'] = str_repeat('a', 32);
        $render = new ReflectionMethod('Peanut_Connect_Forms', 'render_hub_form');
        if (PHP_VERSION_ID < 80100) {
            $render->setAccessible(true);
        }

        $first = (string) $render->invoke(null, ['slug' => 'contact-us', 'settings' => []]);
        $second = (string) $render->invoke(null, ['slug' => 'contact-us', 'settings' => []]);
        unset($_COOKIE['peanut_vid']);

        $this->assertStringNotContainsString(str_repeat('a', 32), $first, 'the visitor id must not be printed');
        $this->assertStringNotContainsString('data-visitor-id', $first);
        $this->assertStringNotContainsString('data-session-id', $first);
        $this->assertSame($first, $second, 'markup must be identical for every render (cache-safe)');
        $this->assertStringContainsString('data-form-slug="contact-us"', $first);
    }

    public function test_public_submit_takes_the_visitor_from_the_request_cookie(): void {
        $_COOKIE['peanut_vid'] = str_repeat('b', 32);
        $this->submit([
            'form_slug' => 'contact-us',
            'data' => ['email' => 'jane@example.com'],
            // What a cached page would have handed this browser.
            'visitor_id' => str_repeat('a', 32),
        ]);
        unset($_COOKIE['peanut_vid']);

        $sent = json_decode($this->posts[0]['args']['body'], true);
        $this->assertSame(str_repeat('b', 32), $sent['visitor_id']);
    }

    public function test_public_submit_sends_no_visitor_without_a_valid_cookie(): void {
        $_COOKIE['peanut_vid'] = '<script>';
        $this->submit(['form_slug' => 'contact-us', 'data' => ['x' => 'y'], 'visitor_id' => str_repeat('a', 32)]);
        unset($_COOKIE['peanut_vid']);

        $sent = json_decode($this->posts[0]['args']['body'], true);
        $this->assertArrayNotHasKey('visitor_id', $sent);
    }

    public function test_public_submit_keeps_its_rate_limit(): void {
        $statuses = [];
        for ($i = 0; $i < 65; $i++) {
            $statuses[] = $this->status($this->submit(['form_slug' => 'contact-us', 'data' => ['i' => $i]]));
        }

        $this->assertContains(429, $statuses);
        $this->assertLessThanOrEqual(60, count($this->posts));
    }
}
