<?php
/**
 * Hub admin REST contract tests.
 *
 * @package Peanut_Connect
 */

namespace Peanut_Connect\Tests\Unit;

use Peanut_Connect_API;
use Peanut_Connect_Auth;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

class ApiTest extends TestCase {

    private Peanut_Connect_API $api;

    protected function setUp(): void {
        parent::setUp();
        peanut_reset_test_state();
        $this->api = new Peanut_Connect_API();
    }

    public function test_admin_permission_check_allows_admins(): void {
        $this->assertTrue($this->api->admin_permission_check());
    }

    public function test_admin_permission_check_denies_non_admins(): void {
        $GLOBALS['mock_user_caps']['manage_options'] = false;

        $this->assertFalse($this->api->admin_permission_check());
    }

    public function test_get_settings_returns_current_hub_shape(): void {
        $response = $this->api->get_settings(new WP_REST_Request('GET', '/settings'));

        $this->assertResponse($response);
        $this->assertTrue($response->data['success']);
        $this->assertArrayHasKey('hub', $response->data['data']);
        $this->assertArrayHasKey('peanut_suite', $response->data['data']);
        $this->assertArrayNotHasKey('connection', $response->data['data']);
    }

    public function test_get_settings_is_disconnected_without_hub_credentials(): void {
        $response = $this->api->get_settings(new WP_REST_Request('GET', '/settings'));

        $this->assertFalse($response->data['data']['hub']['connected']);
        $this->assertSame('', $response->data['data']['hub']['url']);
        $this->assertFalse($response->data['data']['hub']['api_key_set']);
    }

    public function test_get_settings_is_connected_only_with_url_and_key(): void {
        update_option('peanut_connect_hub_url', 'https://hub.example.test');
        Peanut_Connect_Auth::set_hub_api_key('hub-key');

        $response = $this->api->get_settings(new WP_REST_Request('GET', '/settings'));

        $this->assertTrue($response->data['data']['hub']['connected']);
        $this->assertSame('https://hub.example.test', $response->data['data']['hub']['url']);
        $this->assertTrue($response->data['data']['hub']['api_key_set']);
    }

    public function test_get_settings_normalizes_mode_and_tracking_flags(): void {
        update_option('peanut_connect_hub_mode', 'hide_suite');
        update_option('peanut_connect_tracking_enabled', 1);
        update_option('peanut_connect_track_logged_in', 0);

        $hub = $this->api->get_settings(new WP_REST_Request('GET', '/settings'))->data['data']['hub'];

        $this->assertSame('hide_suite', $hub['mode']);
        $this->assertTrue($hub['tracking_enabled']);
        $this->assertFalse($hub['track_logged_in']);
    }

    public function test_manual_connect_requires_url_and_api_key(): void {
        $response = $this->api->manual_connect_to_hub(new WP_REST_Request('POST', '/settings/hub/manual-connect'));

        $this->assertSame(400, $response->status);
        $this->assertFalse($response->data['success']);
    }

    public function test_manual_connect_rejects_plain_http_before_dispatch(): void {
        $response = $this->api->manual_connect_to_hub($this->manualConnectRequest('http://hub.example.test'));

        $this->assertSame(400, $response->status);
        $this->assertSame('peanut_connect_hub_url_invalid', $response->data['code']);
        $this->assertNull($GLOBALS['peanut_last_http']);
    }

    public function test_manual_connect_rejects_localhost_before_dispatch(): void {
        $response = $this->api->manual_connect_to_hub($this->manualConnectRequest('https://localhost'));

        $this->assertSame(400, $response->status);
        $this->assertSame('peanut_connect_hub_url_invalid', $response->data['code']);
        $this->assertNull($GLOBALS['peanut_last_http']);
    }

    public function test_manual_connect_reports_network_failure(): void {
        $GLOBALS['mock_remote_response'] = new WP_Error('http_request_failed', 'offline');

        $response = $this->api->manual_connect_to_hub($this->manualConnectRequest());

        $this->assertSame(502, $response->status);
        $this->assertStringContainsString('offline', $response->data['message']);
    }

    public function test_manual_connect_reports_rejected_key(): void {
        $GLOBALS['mock_remote_response'] = $this->hubResponse(401, ['success' => false]);

        $response = $this->api->manual_connect_to_hub($this->manualConnectRequest());

        $this->assertSame(401, $response->status);
        $this->assertFalse($response->data['success']);
    }

    public function test_manual_connect_propagates_hub_error_message(): void {
        $GLOBALS['mock_remote_response'] = $this->hubResponse(500, [
            'success' => false,
            'error' => ['message' => 'Hub unavailable'],
        ]);

        $response = $this->api->manual_connect_to_hub($this->manualConnectRequest());

        $this->assertSame(502, $response->status);
        $this->assertSame('Hub unavailable', $response->data['message']);
        $this->assertSame(500, $response->data['hub_status']);
    }

    public function test_manual_connect_trusts_success_body_and_encrypts_key(): void {
        $GLOBALS['mock_remote_response'] = $this->hubResponse(406, [
            'success' => true,
            'site' => ['id' => 42],
        ]);

        $response = $this->api->manual_connect_to_hub($this->manualConnectRequest());

        $this->assertSame(200, $response->status);
        $this->assertTrue($response->data['success']);
        $this->assertSame('https://hub.example.test', get_option('peanut_connect_hub_url'));
        $this->assertSame('hub-secret', Peanut_Connect_Auth::get_hub_api_key());
        $this->assertStringStartsWith('enc:v1:', get_option('peanut_connect_hub_api_key'));
        $this->assertSame('https://hub.example.test/api/v1/sites/verify', $GLOBALS['peanut_last_http']['url']);
        $this->assertSame('Bearer hub-secret', $GLOBALS['peanut_last_http']['args']['headers']['Authorization']);
    }

    // ---------------------------------------------------------------------
    // Auto-connect with a Hub pairing token (peanut-hub#1756)
    // ---------------------------------------------------------------------

    private const PAIRING_TOKEN = 'hubpair_AbCdEfGhIjKlMnOpQrStUvWxYz0123456789AbCdEfGhIjKlMnOpQrSt';

    public function test_auto_connect_forwards_pairing_token_to_hub(): void {
        $calls = $this->recordHub(200, $this->hubConnectSuccessBody());

        $response = $this->api->auto_connect_to_hub($this->autoConnectRequest(self::PAIRING_TOKEN));

        $this->assertSame(200, $response->status);
        $this->assertTrue($response->data['success']);
        $connect = $this->connectCall($calls);
        $this->assertNotNull($connect, 'connect endpoint was never called');
        $body = json_decode($connect['args']['body'], true);
        $this->assertSame(self::PAIRING_TOKEN, $body['pairing_token']);
        $this->assertSame(64, strlen($body['api_key']));
        // The key Hub accepted is the key saved locally.
        $this->assertSame($body['api_key'], Peanut_Connect_Auth::get_hub_api_key());
    }

    public function test_auto_connect_trims_a_pasted_pairing_token(): void {
        $calls = $this->recordHub(200, $this->hubConnectSuccessBody());

        $this->api->auto_connect_to_hub($this->autoConnectRequest("  " . self::PAIRING_TOKEN . "\n"));

        $body = json_decode($this->connectCall($calls)['args']['body'], true);
        $this->assertSame(self::PAIRING_TOKEN, $body['pairing_token']);
    }

    public function test_auto_connect_omits_pairing_token_when_none_given(): void {
        $calls = $this->recordHub(200, $this->hubConnectSuccessBody());

        $response = $this->api->auto_connect_to_hub($this->autoConnectRequest());

        $this->assertTrue($response->data['success']);
        $body = json_decode($this->connectCall($calls)['args']['body'], true);
        $this->assertArrayNotHasKey('pairing_token', $body);
    }

    /**
     * @dataProvider malformedPairingTokens
     */
    public function test_auto_connect_rejects_malformed_pairing_token_before_dispatch(string $token): void {
        $response = $this->api->auto_connect_to_hub($this->autoConnectRequest($token));

        $this->assertSame(400, $response->status);
        $this->assertFalse($response->data['success']);
        $this->assertSame('peanut_connect_pairing_token_malformed', $response->data['code']);
        $this->assertNull($GLOBALS['peanut_last_http']);
        $this->assertStringNotContainsString($token, json_encode($response->data));
    }

    public static function malformedPairingTokens(): array {
        return [
            'wrong prefix' => ['hubpaix_' . str_repeat('a', 56)],
            'too short' => ['hubpair_' . str_repeat('a', 55)],
            'too long' => ['hubpair_' . str_repeat('a', 57)],
            'bad character' => ['hubpair_' . str_repeat('a', 55) . '-'],
            'api key pasted instead' => [str_repeat('a', 64)],
        ];
    }

    public function test_auto_connect_never_persists_or_logs_the_pairing_token(): void {
        $errorLog = tempnam(sys_get_temp_dir(), 'pc-errlog');
        $previous = ini_set('error_log', $errorLog);
        try {
            $this->recordHub(200, $this->hubConnectSuccessBody());
            $success = $this->api->auto_connect_to_hub($this->autoConnectRequest(self::PAIRING_TOKEN));

            $this->recordHub(403, [
                'success' => false,
                'code' => 'PAIRING_TOKEN_INVALID',
                'message' => 'The pairing token is invalid, expired, or already used.',
            ]);
            $failure = $this->api->auto_connect_to_hub($this->autoConnectRequest(self::PAIRING_TOKEN));
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $this->assertTrue($success->data['success']);
        $this->assertFalse($failure->data['success']);
        $haystacks = [
            'options' => serialize($GLOBALS['peanut_test_options']),
            'transients' => serialize($GLOBALS['peanut_test_transients']),
            'activity log' => json_encode(\Peanut_Connect_Activity_Log::get_entries()),
            'error_log' => (string) file_get_contents($errorLog),
            'success response' => json_encode($success->data),
            'failure response' => json_encode($failure->data),
        ];
        @unlink($errorLog);
        foreach ($haystacks as $where => $haystack) {
            $this->assertStringNotContainsString(self::PAIRING_TOKEN, $haystack, "pairing token leaked into $where");
        }
    }

    /**
     * @dataProvider hubConnectErrors
     */
    public function test_auto_connect_maps_hub_error_codes(int $status, string $code, string $expected): void {
        $this->recordHub($status, [
            'success' => false,
            'code' => $code,
            'message' => 'Raw Hub message',
        ]);

        $response = $this->api->auto_connect_to_hub($this->autoConnectRequest(self::PAIRING_TOKEN));

        $this->assertSame($status, $response->status);
        $this->assertFalse($response->data['success']);
        $this->assertSame($code, $response->data['code']);
        $this->assertSame($expected, $response->data['message']);
        // A refused pairing saves nothing.
        $this->assertFalse(get_option('peanut_connect_hub_url'));
        $this->assertSame('', Peanut_Connect_Auth::get_hub_api_key());
    }

    public static function hubConnectErrors(): array {
        return [
            'token required' => [
                403,
                'PAIRING_TOKEN_REQUIRED',
                'Open this site in Hub and click Pair site, then paste the token.',
            ],
            'token invalid' => [
                403,
                'PAIRING_TOKEN_INVALID',
                'That pairing token is invalid or expired. Create a new one in Hub.',
            ],
            'already connected' => [
                409,
                'ALREADY_CONNECTED',
                'This site is already connected to Hub with a different key. Open this site in Hub and click Pair site, then paste the token here to reconnect.',
            ],
        ];
    }

    public function test_auto_connect_accepts_legacy_hub_response_with_names(): void {
        // Pre-#1756 HUB: ignores pairing_token and returns client/agency names.
        $this->recordHub(200, [
            'success' => true,
            'message' => 'Site connected successfully',
            'site' => ['id' => 7, 'name' => 'Example'],
            'client' => ['id' => 3, 'name' => 'Client Co'],
            'agency' => ['id' => 1, 'name' => 'Agency Co'],
        ]);

        $response = $this->api->auto_connect_to_hub($this->autoConnectRequest(self::PAIRING_TOKEN));

        $this->assertSame(200, $response->status);
        $this->assertTrue($response->data['success']);
        $this->assertSame('Client Co', $response->data['data']['client']['name']);
        $this->assertSame('Agency Co', $response->data['data']['agency']['name']);
        $this->assertSame('https://hub.example.test', get_option('peanut_connect_hub_url'));
    }

    public function test_auto_connect_succeeds_without_client_or_agency_in_response(): void {
        $this->recordHub(200, [
            'success' => true,
            'message' => 'Site connected successfully',
            'site' => ['id' => 7, 'name' => 'Example'],
        ]);

        $response = $this->api->auto_connect_to_hub($this->autoConnectRequest(self::PAIRING_TOKEN));

        $this->assertSame(200, $response->status);
        $this->assertTrue($response->data['success']);
        $this->assertSame([], $response->data['data']['client']);
        $this->assertSame([], $response->data['data']['agency']);
        $this->assertNotSame('', Peanut_Connect_Auth::get_hub_api_key());
    }

    public function test_disconnect_hub_succeeds_when_already_disconnected(): void {
        $response = $this->api->disconnect_hub(new WP_REST_Request('POST', '/settings/hub/disconnect'));

        $this->assertResponse($response);
        $this->assertTrue($response->data['success']);
        $this->assertNull($GLOBALS['peanut_last_http']);
    }

    public function test_disconnect_hub_notifies_hub_and_clears_local_state(): void {
        update_option('peanut_connect_hub_url', 'https://hub.example.test');
        update_option('peanut_connect_last_hub_sync', '2026-08-25 12:00:00');
        Peanut_Connect_Auth::set_hub_api_key('hub-secret');
        $GLOBALS['mock_remote_response'] = $this->hubResponse(200, ['success' => true]);

        $response = $this->api->disconnect_hub(new WP_REST_Request('POST', '/settings/hub/disconnect'));

        $this->assertTrue($response->data['success']);
        $this->assertSame('https://hub.example.test/api/v1/sites/disconnect', $GLOBALS['peanut_last_http']['url']);
        $this->assertFalse(get_option('peanut_connect_hub_url'));
        $this->assertSame('', Peanut_Connect_Auth::get_hub_api_key());
        $this->assertFalse(get_option('peanut_connect_last_hub_sync'));
    }

    public function test_update_hub_mode_rejects_invalid_mode(): void {
        $request = new WP_REST_Request('POST', '/settings/hub/mode');
        $request->set_param('mode', 'manager');

        $response = $this->api->update_hub_mode($request);

        $this->assertSame(400, $response->status);
        $this->assertFalse($response->data['success']);
    }

    public function test_update_hub_mode_persists_valid_mode(): void {
        $request = new WP_REST_Request('POST', '/settings/hub/mode');
        $request->set_param('mode', 'disable_suite');

        $response = $this->api->update_hub_mode($request);

        $this->assertResponse($response);
        $this->assertSame('disable_suite', get_option('peanut_connect_hub_mode'));
    }

    public function test_get_permissions_returns_safe_defaults(): void {
        $response = $this->api->get_permissions(new WP_REST_Request('GET', '/permissions'));

        $this->assertResponse($response);
        $this->assertFalse($response->data['perform_updates']);
        $this->assertTrue($response->data['access_analytics']);
        $this->assertFalse($response->data['publish_content']);
        $this->assertFalse($response->data['backup_restore']);
        $this->assertFalse($response->data['api_proxy']);
    }

    public function test_get_permissions_merges_stored_values_with_defaults(): void {
        update_option('peanut_connect_permissions', ['perform_updates' => true]);

        $response = $this->api->get_permissions(new WP_REST_Request('GET', '/permissions'));

        $this->assertTrue($response->data['perform_updates']);
        $this->assertTrue($response->data['access_analytics']);
        $this->assertFalse($response->data['publish_content']);
    }

    public function test_update_permissions_changes_only_supplied_flags(): void {
        update_option('peanut_connect_permissions', [
            'perform_updates' => true,
            'access_analytics' => true,
            'publish_content' => true,
        ]);
        $request = new WP_REST_Request('POST', '/permissions');
        $request->set_param('perform_updates', false);
        $request->set_param('api_proxy', true);

        $response = $this->api->update_permissions($request);
        $stored = get_option('peanut_connect_permissions');

        $this->assertResponse($response);
        $this->assertFalse($stored['perform_updates']);
        $this->assertTrue($stored['api_proxy']);
        $this->assertTrue($stored['access_analytics']);
        $this->assertTrue($stored['publish_content']);
    }

    public function test_perform_update_rejects_invalid_type(): void {
        $request = new WP_REST_Request('POST', '/update');
        $request->set_param('type', 'invalid');
        $request->set_param('slug', 'test');

        $response = $this->api->perform_update($request);

        $this->assertSame(400, $response->status);
        $this->assertFalse($response->data['success']);
        $this->assertSame('invalid_type', $response->data['code']);
    }

    public function test_get_admin_health_wraps_health_data(): void {
        $response = $this->api->get_admin_health(new WP_REST_Request('GET', '/admin/health'));

        $this->assertResponse($response);
        $this->assertTrue($response->data['success']);
        $this->assertArrayHasKey('wp_version', $response->data['data']);
        $this->assertArrayHasKey('php_version', $response->data['data']);
    }

    public function test_get_admin_updates_wraps_update_data(): void {
        $response = $this->api->get_admin_updates(new WP_REST_Request('GET', '/admin/updates'));

        $this->assertResponse($response);
        $this->assertTrue($response->data['success']);
        $this->assertArrayHasKey('plugins', $response->data['data']);
        $this->assertArrayHasKey('themes', $response->data['data']);
        $this->assertArrayHasKey('core', $response->data['data']);
    }

    public function test_get_activity_log_returns_current_shape(): void {
        $response = $this->api->get_activity_log(new WP_REST_Request('GET', '/activity'));

        $this->assertResponse($response);
        $this->assertTrue($response->data['success']);
        $this->assertArrayHasKey('entries', $response->data['data']);
        $this->assertArrayHasKey('counts', $response->data['data']);
    }

    public function test_get_activity_counts_returns_current_shape(): void {
        $response = $this->api->get_activity_counts(new WP_REST_Request('GET', '/activity/counts'));

        $this->assertResponse($response);
        $this->assertTrue($response->data['success']);
        $this->assertArrayHasKey('by_type', $response->data['data']);
        $this->assertArrayHasKey('last_24h', $response->data['data']);
    }

    private function manualConnectRequest(
        string $hubUrl = 'https://hub.example.test',
        string $apiKey = 'hub-secret'
    ): WP_REST_Request {
        $request = new WP_REST_Request('POST', '/settings/hub/manual-connect');
        $request->set_param('hub_url', $hubUrl);
        $request->set_param('api_key', $apiKey);
        return $request;
    }

    private function autoConnectRequest(?string $pairingToken = null): WP_REST_Request {
        $request = new WP_REST_Request('POST', '/settings/hub/connect');
        $request->set_param('hub_url', 'https://hub.example.test');
        if ($pairingToken !== null) {
            $request->set_param('pairing_token', $pairingToken);
        }
        return $request;
    }

    private function hubConnectSuccessBody(): array {
        // Post-#1756 HUB success shape.
        return [
            'success' => true,
            'message' => 'Site connected successfully',
            'site' => ['id' => 7, 'name' => 'Example', 'status' => 'active'],
            'client' => ['id' => 3, 'name' => 'Client Co'],
            'agency' => ['id' => 1, 'name' => 'Agency Co'],
        ];
    }

    /**
     * Answer every outbound POST with $status/$body and record each call
     * (the success path also sends a heartbeat, which would otherwise
     * overwrite $peanut_last_http).
     */
    private function recordHub(int $status, array $body): \ArrayObject {
        $calls = new \ArrayObject();
        $response = $this->hubResponse($status, $body);
        $GLOBALS['mock_remote_response'] = static function (string $url, array $args) use ($calls, $response) {
            $calls[] = ['url' => $url, 'args' => $args];
            return $response;
        };
        return $calls;
    }

    private function connectCall(\ArrayObject $calls): ?array {
        foreach ($calls as $call) {
            if ($call['url'] === 'https://hub.example.test/api/v1/sites/connect') {
                return $call;
            }
        }
        return null;
    }

    private function hubResponse(int $status, array $body): array {
        return [
            'response' => ['code' => $status],
            'body' => json_encode($body, JSON_THROW_ON_ERROR),
        ];
    }

    private function assertResponse(WP_REST_Response $response): void {
        $this->assertSame(200, $response->status);
        $this->assertInstanceOf(WP_REST_Response::class, $response);
    }
}
