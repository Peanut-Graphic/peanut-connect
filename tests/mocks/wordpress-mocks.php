<?php
/**
 * WordPress function mocks for standalone testing.
 *
 * These mocks allow testing of plugin logic without a full WordPress installation.
 * For integration tests, use the WordPress test suite.
 *
 * @package Peanut_Suite
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

if (!defined('WPINC')) {
    define('WPINC', 'wp-includes');
}

if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
}

if (!defined('WP_PLUGIN_DIR')) {
    define('WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins');
}

if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

// Mock common WordPress functions.

if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_url')) {
    function esc_url($url) {
        return filter_var($url, FILTER_SANITIZE_URL);
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return htmlspecialchars(strip_tags(trim($str)), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('sanitize_email')) {
    function sanitize_email($email) {
        return filter_var($email, FILTER_SANITIZE_EMAIL);
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value) {
        return stripslashes_deep($value);
    }
}

if (!function_exists('stripslashes_deep')) {
    function stripslashes_deep($value) {
        if (is_array($value)) {
            return array_map('stripslashes_deep', $value);
        }
        return stripslashes($value);
    }
}

if (!function_exists('absint')) {
    function absint($maybeint) {
        return abs((int) $maybeint);
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) {
        return json_encode($data, $options, $depth);
    }
}

if (!function_exists('__')) {
    function __($text, $domain = 'default') {
        return $text;
    }
}

if (!function_exists('_e')) {
    function _e($text, $domain = 'default') {
        echo $text;
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = 'default') {
        return esc_html($text);
    }
}

if (!function_exists('esc_html_e')) {
    function esc_html_e($text, $domain = 'default') {
        echo esc_html($text);
    }
}

if (!function_exists('wp_hash')) {
    function wp_hash($data, $scheme = 'auth') {
        return hash('sha256', $data . 'test_salt');
    }
}

if (!function_exists('wp_salt')) {
    function wp_salt($scheme = 'auth') {
        // Tests can override via $GLOBALS to simulate salt rotation.
        return $GLOBALS['mock_wp_salt'] ?? ('peanut-connect-test-salt-' . $scheme);
    }
}

if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0) {
        if ($type === 'timestamp' || $type === 'U') {
            return time();
        }
        if ($type === 'mysql') {
            return date('Y-m-d H:i:s');
        }
        return date($type);
    }
}

if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        global $mock_options;
        return $mock_options[$option] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value, $autoload = null) {
        global $mock_options;
        $mock_options[$option] = $value;
        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option($option) {
        global $mock_options;
        unset($mock_options[$option]);
        return true;
    }
}

if (!function_exists('get_transient')) {
    function get_transient($transient) {
        global $mock_transients;
        $data = $mock_transients[$transient] ?? null;
        if ($data && isset($data['expiration']) && $data['expiration'] < time()) {
            unset($mock_transients[$transient]);
            return false;
        }
        return $data['value'] ?? false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient($transient, $value, $expiration = 0) {
        global $mock_transients;
        $mock_transients[$transient] = [
            'value' => $value,
            'expiration' => $expiration > 0 ? time() + $expiration : 0,
        ];
        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient($transient) {
        global $mock_transients;
        unset($mock_transients[$transient]);
        return true;
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return $thing instanceof WP_Error;
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error {
        public $errors = [];
        public $error_data = [];

        public function __construct($code = '', $message = '', $data = '') {
            if (!empty($code)) {
                $this->errors[$code][] = $message;
                if (!empty($data)) {
                    $this->error_data[$code] = $data;
                }
            }
        }

        public function get_error_code() {
            $codes = array_keys($this->errors);
            return $codes[0] ?? '';
        }

        public function get_error_message($code = '') {
            if (empty($code)) {
                $code = $this->get_error_code();
            }
            return $this->errors[$code][0] ?? '';
        }

        public function get_error_data($code = '') {
            if (empty($code)) {
                $code = $this->get_error_code();
            }
            return $this->error_data[$code] ?? null;
        }

        public function add($code, $message, $data = '') {
            $this->errors[$code][] = $message;
            if (!empty($data)) {
                $this->error_data[$code] = $data;
            }
        }
    }
}

if (!function_exists('apply_filters')) {
    // Standalone stub: no filters are registered in the mock suite, so return
    // the value unchanged. Enough for code that reads a filterable default
    // (e.g. trusted-proxy list, feedback agency capability).
    function apply_filters($hook, $value, ...$args) {
        return $value;
    }
}

if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1) {
        return parse_url($url, $component);
    }
}

if (!function_exists('wp_generate_password')) {
    function wp_generate_password(int $length = 12, bool $special_chars = true, bool $extra_special_chars = false): string {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $password;
    }
}

if (!function_exists('trailingslashit')) {
    function trailingslashit(string $string): string {
        return rtrim($string, '/\\') . '/';
    }
}

if (!function_exists('wp_remote_post')) {
    function wp_remote_post(string $url, array $args = []): array|WP_Error {
        global $mock_wp_remote_post;
        if (is_callable($mock_wp_remote_post ?? null)) {
            return ($mock_wp_remote_post)($url, $args);
        }
        return ['response' => ['code' => 200, 'message' => 'OK'], 'body' => '{}'];
    }
}

if (!function_exists('wp_remote_get')) {
    function wp_remote_get(string $url, array $args = []): array|WP_Error {
        global $mock_wp_remote_get, $mock_wp_remote_get_calls;
        $mock_wp_remote_get_calls = ($mock_wp_remote_get_calls ?? 0) + 1;
        if (is_callable($mock_wp_remote_get ?? null)) {
            return ($mock_wp_remote_get)($url, $args);
        }
        return ['response' => ['code' => 200, 'message' => 'OK'], 'body' => '{}'];
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body(array|WP_Error $response): string {
        if (is_wp_error($response)) {
            return '';
        }
        return $response['body'] ?? '';
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code(array|WP_Error $response): int|string {
        if (is_wp_error($response)) {
            return '';
        }
        return $response['response']['code'] ?? '';
    }
}

if (!function_exists('dbDelta')) {
    function dbDelta($queries = '', $execute = true) {
        global $wpdb;
        // In standalone tests the fake $wpdb records the CREATE TABLE so
        // drift/self-heal assertions can observe that a migration ran.
        if (isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'query')) {
            foreach ((array) $queries as $q) {
                $wpdb->query($q);
            }
        }
        return [];
    }
}

// Provide a stub for the WordPress upgrade include that create_tables()
// require_once's, so standalone tests can exercise the migration path.
if (defined('ABSPATH')) {
    $upgrade_stub = ABSPATH . 'wp-admin/includes/upgrade.php';
    if (!file_exists($upgrade_stub)) {
        @mkdir(dirname($upgrade_stub), 0777, true);
        @file_put_contents($upgrade_stub, "<?php\n// Test stub: dbDelta() is defined in wordpress-mocks.php.\n");
    }
}

if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in() {
        return $GLOBALS['pp_test_logged_in'] ?? false;
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can($capability, ...$args) {
        return $GLOBALS['pp_test_user_caps'][$capability] ?? false;
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id() {
        return $GLOBALS['pp_test_user_id'] ?? 0;
    }
}

// Minimal stub covering only what Peanut_Connect_Feedback::can_review() needs
// (a header bag). Tests that need query/body params should extend this or
// use tests/phpunit/bootstrap.php's richer stub instead.
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request {
        private array $headers;

        public function __construct(array $headers = []) {
            $this->headers = $headers;
        }

        public function get_header($name) {
            return $this->headers[$name] ?? '';
        }
    }
}

// Initialize mock storage.
global $mock_options, $mock_transients;
$mock_options = [];
$mock_transients = [];

// --- Background-job primitives (Peanut_Connect_Backup_Job) ------------------
// Recorded rather than executed, so a test can assert that queueing SCHEDULES
// work instead of doing it — which is the whole point of the async backup path.

if (!function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4() {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

if (!function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event($timestamp, $hook, $args = []) {
        global $mock_scheduled_events;
        $mock_scheduled_events[] = ['timestamp' => $timestamp, 'hook' => $hook, 'args' => $args];
        return true;
    }
}

if (!function_exists('spawn_cron')) {
    function spawn_cron($gmt_time = 0) {
        global $mock_spawned_cron;
        $mock_spawned_cron = (int) ($mock_spawned_cron ?? 0) + 1;
        return true;
    }
}

if (!function_exists('sanitize_title')) {
    function sanitize_title($title) {
        return strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', (string) $title), '-'));
    }
}
