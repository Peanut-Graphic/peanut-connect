<?php
/**
 * Key-rotation lockout recovery.
 *
 * HUB promotes a proposed key on its first authenticated use (the confirm
 * call). If HUB commits that promotion but the plugin never sees a 2xx — a
 * timeout, a dropped connection, a 5xx after commit — the old key is already
 * retired at HUB. Discarding the new key at that point locks the site out of
 * HUB for good. These tests drive rotate(), the cron retry and the 401
 * self-heal against a fake HUB that models ValidateSiteApiKey's real
 * promote-on-first-use semantics.
 *
 * @package Peanut_Connect
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/class-connect-secret.php';
require_once dirname(__DIR__) . '/includes/class-connect-auth.php';

// Peanut_Connect_Activity_Log is the real class (composer classmap autoload);
// it writes JSON lines under WP_CONTENT_DIR/peanut-logs, which the leak test
// scans directly.
if (!class_exists('Peanut_Connect_Database')) {
    class Peanut_Connect_Database {
        public static function table(string $name): string { return $name; }
    }
}
// Real WP INSERT semantics: false when the option already exists. Same shape
// as the guarded stub in Test_Signed_Only_Self_Gate.php.
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
if (!function_exists('get_bloginfo')) {
    function get_bloginfo($show = '', $filter = 'raw') {
        return 'test';
    }
}
if (!function_exists('admin_url')) {
    function admin_url($path = '') {
        return 'https://example.com/wp-admin/' . ltrim($path, '/');
    }
}
if (!defined('PEANUT_CONNECT_VERSION')) {
    define('PEANUT_CONNECT_VERSION', 'test');
}

require_once dirname(__DIR__) . '/includes/class-connect-hub-sync.php';
require_once dirname(__DIR__) . '/includes/class-connect-key-rotation.php';

/**
 * In-memory HUB that mirrors ValidateSiteApiKey + SiteRotationController:
 * a request authenticates with the active key, or with an unexpired pending
 * key — which promotes it (retiring the old key) on that first use.
 */
final class Rotation_Fake_Hub {
    public string $active_hash;
    public ?string $pending_hash = null;
    public int $pending_expires = 0;

    /** normal | timeout_after_commit | error_500_no_commit | down */
    public string $confirm_mode = 'normal';
    /** How many confirm calls misbehave per $confirm_mode before behaving normally. */
    public int $confirm_failures = PHP_INT_MAX;
    /** When true HUB records no pending key (proposal lost / window closed). */
    public bool $drop_pending_after_propose = false;
    /** Every probe GET fails at the network layer while true. */
    public bool $gets_down = false;
    /** The next N GETs fail at the network layer. */
    public int $fail_next_gets = 0;

    /** @var array<int,array{method:string,path:string,code:int|string}> */
    public array $calls = [];

    public function __construct(string $active_key) {
        $this->active_hash = hash('sha256', $active_key);
    }

    public function holds_active_key(string $key): bool {
        return $key !== '' && hash_equals($this->active_hash, hash('sha256', $key));
    }

    private function authenticate(array $args): bool {
        $auth  = (string) ($args['headers']['Authorization'] ?? '');
        $token = str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : '';
        $hash  = hash('sha256', $token);
        if ($token !== '' && hash_equals($this->active_hash, $hash)) {
            return true;
        }
        if ($token !== '' && $this->pending_hash !== null && hash_equals($this->pending_hash, $hash) && $this->pending_expires > time()) {
            $this->active_hash     = $this->pending_hash; // promotePendingKey()
            $this->pending_hash    = null;
            $this->pending_expires = 0;
            return true;
        }
        return false;
    }

    private function record(string $method, string $url, $code): void {
        $this->calls[] = [
            'method' => $method,
            'path'   => (string) parse_url($url, PHP_URL_PATH),
            'code'   => $code,
        ];
    }

    private static function json(int $code, array $body): array {
        return ['response' => ['code' => $code, 'message' => ''], 'body' => json_encode($body)];
    }

    private static function timeout(): WP_Error {
        return new WP_Error('http_request_failed', 'cURL error 28: Operation timed out after 15001 milliseconds');
    }

    public function post(string $url, array $args) {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if ($path === '/api/v1/sites/rotate') {
            if (!$this->authenticate($args)) {
                $this->record('POST', $url, 401);
                return self::json(401, ['success' => false, 'message' => 'Invalid API key']);
            }
            $payload = json_decode((string) $args['body'], true);
            if (!$this->drop_pending_after_propose) {
                $this->pending_hash    = (string) $payload['new_key_hash'];
                $this->pending_expires = time() + 900;
            }
            $this->record('POST', $url, 200);
            return self::json(200, ['success' => true]);
        }

        if ($path === '/api/v1/sites/rotate/confirm') {
            if ($this->confirm_mode !== 'normal' && $this->confirm_failures > 0) {
                $this->confirm_failures--;
                if ($this->confirm_mode === 'timeout_after_commit') {
                    $this->authenticate($args); // HUB commits the promotion...
                    $this->record('POST', $url, 'timeout');
                    return self::timeout();      // ...but the reply never arrives.
                }
                if ($this->confirm_mode === 'error_500_no_commit') {
                    $this->record('POST', $url, 500);
                    return self::json(500, ['success' => false, 'message' => 'Server Error']);
                }
                $this->record('POST', $url, 'down');
                return self::timeout();
            }
            $ok = $this->authenticate($args);
            $this->record('POST', $url, $ok ? 200 : 401);
            return $ok ? self::json(200, ['success' => true]) : self::json(401, ['success' => false, 'message' => 'Invalid API key']);
        }

        if ($path === '/api/v1/sync/heartbeat') {
            $ok = $this->authenticate($args);
            $this->record('POST', $url, $ok ? 200 : 401);
            return $ok ? self::json(200, ['success' => true]) : self::json(401, ['success' => false, 'message' => 'Invalid API key']);
        }

        $this->record('POST', $url, 404);
        return self::json(404, ['success' => false]);
    }

    public function get(string $url, array $args) {
        if ($this->gets_down || $this->fail_next_gets > 0) {
            $this->fail_next_gets = max(0, $this->fail_next_gets - 1);
            $this->record('GET', $url, 'down');
            return self::timeout();
        }
        $ok = $this->authenticate($args);
        $this->record('GET', $url, $ok ? 200 : 401);
        return $ok ? self::json(200, ['success' => true, 'popups' => []]) : self::json(401, ['success' => false, 'message' => 'Invalid API key']);
    }

    public function count_calls(string $method, string $path): int {
        return count(array_filter($this->calls, fn($c) => $c['method'] === $method && $c['path'] === $path));
    }
}

class Test_Key_Rotation_Recovery extends TestCase {
    private const OLD_KEY = 'OLDKEYoldkeyOLDKEYoldkeyOLDKEYoldkeyOLDKEYoldkeyOLDKEYoldkey0001';

    private Rotation_Fake_Hub $hub;
    private string $error_log_file;
    private $previous_error_log;
    /** @var array<int,int> */
    private array $sleeps = [];

    protected function setUp(): void {
        parent::setUp();
        global $mock_options, $mock_transients, $mock_wp_remote_post, $mock_wp_remote_get;
        $mock_options = [];
        $mock_transients = [];
        @unlink(self::activity_log_file());
        $GLOBALS['mock_wp_salt'] = 'rotation-recovery-salt';

        update_option('peanut_connect_hub_url', 'https://hub.example');
        // Heartbeat builds its payload from the (real, classmap-autoloaded)
        // Peanut_Connect_Health, which serves this cached snapshot.
        set_transient('peanut_connect_health', ['cached' => true], 3600);
        Peanut_Connect_Auth::set_hub_api_key(self::OLD_KEY);

        $this->hub = new Rotation_Fake_Hub(self::OLD_KEY);
        $hub = $this->hub;
        $mock_wp_remote_post = fn($url, $args) => $hub->post($url, $args);
        $mock_wp_remote_get  = fn($url, $args) => $hub->get($url, $args);

        $this->sleeps = [];
        $sleeps = &$this->sleeps;
        if (property_exists('Peanut_Connect_Key_Rotation', 'sleeper')) {
            Peanut_Connect_Key_Rotation::$sleeper = function (int $seconds) use (&$sleeps): void {
                $sleeps[] = $seconds;
            };
        }

        $this->error_log_file = (string) tempnam(sys_get_temp_dir(), 'pc-rotation-log');
        $this->previous_error_log = ini_set('error_log', $this->error_log_file);
    }

    protected function tearDown(): void {
        global $mock_wp_remote_post, $mock_wp_remote_get;
        $mock_wp_remote_post = null;
        $mock_wp_remote_get = null;
        if (property_exists('Peanut_Connect_Key_Rotation', 'sleeper')) {
            Peanut_Connect_Key_Rotation::$sleeper = null;
        }
        ini_set('error_log', (string) $this->previous_error_log);
        @unlink($this->error_log_file);
        unset($GLOBALS['mock_wp_salt']);
        parent::tearDown();
    }

    private static function activity_log_file(): string {
        return WP_CONTENT_DIR . '/peanut-logs/activity-log.json';
    }

    private function stored_key(): string {
        return Peanut_Connect_Auth::get_hub_api_key();
    }

    /** The plugin's stored key is the one HUB will accept: no lockout. */
    private function assertInSyncWithHub(): void {
        $this->assertTrue(
            $this->hub->holds_active_key($this->stored_key()),
            'Stored key must be the key HUB accepts (otherwise the site is locked out of HUB).'
        );
    }

    private function pending_raw() {
        return get_option('peanut_connect_hub_pending_key', null);
    }

    private function has_pending(): bool {
        return method_exists('Peanut_Connect_Key_Rotation', 'has_pending') && Peanut_Connect_Key_Rotation::has_pending();
    }

    // ------------------------------------------------------------------
    // Happy path stays happy.
    // ------------------------------------------------------------------

    public function test_clean_rotation_adopts_new_key_and_leaves_no_pending(): void {
        $r = Peanut_Connect_Key_Rotation::rotate();

        $this->assertTrue($r['success']);
        $this->assertNotSame(self::OLD_KEY, $this->stored_key());
        $this->assertInSyncWithHub();
        $this->assertNull($this->pending_raw());
        $this->assertSame(1, $this->hub->count_calls('POST', '/api/v1/sites/rotate/confirm'));
    }

    // ------------------------------------------------------------------
    // Confirm timed out AFTER HUB committed the promotion.
    // ------------------------------------------------------------------

    public function test_confirm_timeout_after_commit_adopts_new_key_via_probe(): void {
        $this->hub->confirm_mode = 'timeout_after_commit';

        $r = Peanut_Connect_Key_Rotation::rotate();

        $this->assertInSyncWithHub();
        $this->assertTrue($r['success'], 'HUB committed the new key; the plugin must adopt it.');
        $this->assertNotSame(self::OLD_KEY, $this->stored_key());
        $this->assertNull($this->pending_raw(), 'Pending slot is cleared once the new key is adopted.');
        $this->assertSame(3, $this->hub->count_calls('POST', '/api/v1/sites/rotate/confirm'), 'Confirm is retried a bounded number of times.');
        $this->assertSame([1, 2], $this->sleeps, 'Retries back off 1s then 2s.');
        $this->assertGreaterThanOrEqual(1, $this->hub->count_calls('GET', '/api/v1/popups/active'), 'Adopted via an authenticated read probe.');
    }

    public function test_confirm_retry_succeeds_without_probe(): void {
        $this->hub->confirm_mode = 'down';
        $this->hub->confirm_failures = 1;

        $r = Peanut_Connect_Key_Rotation::rotate();

        $this->assertInSyncWithHub();
        $this->assertTrue($r['success']);
        $this->assertSame(2, $this->hub->count_calls('POST', '/api/v1/sites/rotate/confirm'));
        $this->assertSame(0, $this->hub->count_calls('GET', '/api/v1/popups/active'));
        $this->assertNull($this->pending_raw());
    }

    // ------------------------------------------------------------------
    // Confirm 5xx and HUB did NOT commit.
    // ------------------------------------------------------------------

    public function test_confirm_5xx_without_commit_keeps_old_key_and_clears_pending(): void {
        $this->hub->confirm_mode = 'error_500_no_commit';
        $this->hub->drop_pending_after_propose = true; // HUB never holds the new key.

        $r = Peanut_Connect_Key_Rotation::rotate();

        $this->assertFalse($r['success']);
        $this->assertSame(self::OLD_KEY, $this->stored_key());
        $this->assertInSyncWithHub();
        $this->assertNull($this->pending_raw(), 'Old key proven live and new key proven dead: pending is discarded.');
        $this->assertEmpty(get_option('peanut_connect_key_rotation_unresolved'));
        $this->assertGreaterThanOrEqual(1, $this->hub->count_calls('GET', '/api/v1/popups/active'), 'Outcome decided by probing, not by assumption.');
    }

    public function test_confirm_5xx_with_open_pending_window_converges_on_new_key(): void {
        // Real HUB promotes the pending key on ANY first authenticated use,
        // so the new-key probe itself completes the rotation.
        $this->hub->confirm_mode = 'error_500_no_commit';

        $r = Peanut_Connect_Key_Rotation::rotate();

        $this->assertInSyncWithHub();
        $this->assertTrue($r['success']);
        $this->assertNull($this->pending_raw());
    }

    public function test_propose_failure_keeps_old_key_and_sends_no_confirm(): void {
        $this->hub->active_hash = hash('sha256', 'something-else'); // old key rejected at propose

        $r = Peanut_Connect_Key_Rotation::rotate();

        $this->assertFalse($r['success']);
        $this->assertSame(self::OLD_KEY, $this->stored_key());
        $this->assertNull($this->pending_raw());
        $this->assertSame(0, $this->hub->count_calls('POST', '/api/v1/sites/rotate/confirm'));
    }

    // ------------------------------------------------------------------
    // Both confirm and probes fail: keep pending, notice, retry next cycle.
    // ------------------------------------------------------------------

    public function test_unreachable_after_commit_keeps_pending_flags_notice_and_heals_next_cycle(): void {
        $this->hub->confirm_mode = 'timeout_after_commit';
        $this->hub->gets_down = true;

        $r = Peanut_Connect_Key_Rotation::rotate();

        $this->assertFalse($r['success']);
        $this->assertNotNull($this->pending_raw(), 'The possibly-live new key must not be discarded.');
        $this->assertNotEmpty(get_option('peanut_connect_key_rotation_unresolved'), 'Admin notice flag is raised.');
        $this->assertTrue($this->has_pending());

        // HUB is reachable again; the next heartbeat cron cycle resolves it.
        $this->hub->gets_down = false;
        $hb = Peanut_Connect_Hub_Sync::send_heartbeat();

        $this->assertTrue($hb['success']);
        $this->assertNotSame(self::OLD_KEY, $this->stored_key());
        $this->assertInSyncWithHub();
        $this->assertNull($this->pending_raw());
        $this->assertEmpty(get_option('peanut_connect_key_rotation_unresolved'), 'Notice clears once resolved.');
    }

    public function test_unresolved_rotation_blocks_a_second_rotation(): void {
        $this->hub->confirm_mode = 'timeout_after_commit';
        $this->hub->gets_down = true;
        Peanut_Connect_Key_Rotation::rotate();
        $proposals = $this->hub->count_calls('POST', '/api/v1/sites/rotate');

        $r = Peanut_Connect_Key_Rotation::rotate();

        $this->assertFalse($r['success']);
        $this->assertSame($proposals, $this->hub->count_calls('POST', '/api/v1/sites/rotate'), 'No new proposal while one is unresolved.');
        $this->assertNotNull($this->pending_raw());
    }

    public function test_rotate_completes_an_earlier_unresolved_rotation_instead_of_stacking(): void {
        $this->hub->confirm_mode = 'timeout_after_commit';
        $this->hub->gets_down = true;
        Peanut_Connect_Key_Rotation::rotate(); // HUB holds the new key; plugin unresolved.
        $proposals = $this->hub->count_calls('POST', '/api/v1/sites/rotate');
        $this->hub->gets_down = false;

        $r = Peanut_Connect_Key_Rotation::rotate();

        $this->assertTrue($r['success']);
        $this->assertInSyncWithHub();
        $this->assertSame($proposals, $this->hub->count_calls('POST', '/api/v1/sites/rotate'));
        $this->assertNull($this->pending_raw());
    }

    public function test_admin_notice_renders_for_unresolved_rotation(): void {
        $this->assertTrue(method_exists('Peanut_Connect_Key_Rotation', 'render_unresolved_notice'));
        $this->hub->confirm_mode = 'timeout_after_commit';
        $this->hub->gets_down = true;
        Peanut_Connect_Key_Rotation::rotate(); // unresolved
        $GLOBALS['pp_test_user_caps']['manage_options'] = true;

        ob_start();
        Peanut_Connect_Key_Rotation::render_unresolved_notice();
        $html = (string) ob_get_clean();
        unset($GLOBALS['pp_test_user_caps']['manage_options']);

        $this->assertStringContainsString('notice-warning', $html);
        $this->assertStringContainsString('key rotation', $html);

        // Not shown to users who cannot manage options.
        ob_start();
        Peanut_Connect_Key_Rotation::render_unresolved_notice();
        $this->assertSame('', (string) ob_get_clean());
    }

    // ------------------------------------------------------------------
    // 401 self-heal with the pending key.
    // ------------------------------------------------------------------

    public function test_heartbeat_401_self_heals_with_pending_key(): void {
        $this->hub->confirm_mode = 'timeout_after_commit';
        $this->hub->gets_down = true;
        Peanut_Connect_Key_Rotation::rotate(); // HUB now holds the new key.
        $this->assertTrue($this->has_pending(), 'Precondition: the new key was kept as pending.');
        $this->assertSame(self::OLD_KEY, $this->stored_key());

        // Next cycle: the cron resolve probes fail (2 GETs), the heartbeat
        // itself 401s on the retired old key, and the 401 self-heal works.
        $this->hub->gets_down = false;
        $this->hub->fail_next_gets = 2;

        $hb = Peanut_Connect_Hub_Sync::send_heartbeat();

        $this->assertTrue($hb['success'], 'Heartbeat is retried with the healed key.');
        $this->assertInSyncWithHub();
        $this->assertSame(0, (int) get_option('peanut_connect_auth_fail_count', 0), 'A healed 401 is not a revocation strike.');
        $this->assertNull($this->pending_raw());
    }

    public function test_heal_after_unauthorized_adopts_pending_key(): void {
        $this->hub->confirm_mode = 'timeout_after_commit';
        $this->hub->gets_down = true;
        Peanut_Connect_Key_Rotation::rotate();
        $this->hub->gets_down = false;

        $this->assertTrue(method_exists('Peanut_Connect_Key_Rotation', 'heal_after_unauthorized'));
        $this->assertTrue(Peanut_Connect_Key_Rotation::heal_after_unauthorized());
        $this->assertInSyncWithHub();
        $this->assertFalse($this->has_pending());
    }

    public function test_heal_after_unauthorized_is_noop_without_pending(): void {
        $this->assertTrue(method_exists('Peanut_Connect_Key_Rotation', 'heal_after_unauthorized'));
        $this->assertFalse(Peanut_Connect_Key_Rotation::heal_after_unauthorized());
        $this->assertSame(0, count($this->hub->calls));
    }

    public function test_unpair_clears_pending_key(): void {
        $this->hub->confirm_mode = 'timeout_after_commit';
        $this->hub->gets_down = true;
        Peanut_Connect_Key_Rotation::rotate();
        $this->assertTrue($this->has_pending(), 'Precondition: a pending key exists.');

        Peanut_Connect_Auth::clear_hub_api_key();

        $this->assertNull($this->pending_raw());
        $this->assertEmpty(get_option('peanut_connect_key_rotation_unresolved'));
    }

    // ------------------------------------------------------------------
    // No key material anywhere it could leak.
    // ------------------------------------------------------------------

    public function test_no_key_material_in_logs_options_or_messages(): void {
        $results = [];

        $this->hub->confirm_mode = 'timeout_after_commit';
        $this->hub->gets_down = true;
        $results[] = Peanut_Connect_Key_Rotation::rotate();     // unresolved
        $this->assertTrue($this->has_pending(), 'Precondition: a pending key is persisted mid-flight.');
        $mid_flight_options = json_encode($GLOBALS['mock_options']);
        $this->hub->gets_down = false;
        $results[] = Peanut_Connect_Hub_Sync::send_heartbeat();  // resolved
        $new_key = $this->stored_key();
        $this->assertNotSame(self::OLD_KEY, $new_key);
        $this->hub->confirm_mode = 'normal';
        $results[] = Peanut_Connect_Key_Rotation::rotate();     // a clean second rotation
        $newest_key = $this->stored_key();
        $this->assertNotSame($new_key, $newest_key);

        $haystacks = [
            'activity log'    => (string) @file_get_contents(self::activity_log_file()),
            'php error log'   => (string) file_get_contents($this->error_log_file),
            'options (mid)'   => $mid_flight_options,
            'options (final)' => json_encode($GLOBALS['mock_options']),
            'return messages' => json_encode($results),
        ];
        $this->assertStringContainsString('hub_key_rotation_unresolved', $haystacks['activity log'], 'Rotation outcomes are logged (without keys).');
        $this->assertStringContainsString('hub_key_rotated', $haystacks['activity log']);
        foreach ($haystacks as $where => $text) {
            foreach (['old' => self::OLD_KEY, 'new' => $new_key, 'newest' => $newest_key] as $label => $key) {
                $this->assertStringNotContainsString($key, (string) $text, "$label key leaked into $where");
            }
        }
    }
}
