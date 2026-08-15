<?php
/**
 * Tests for Peanut_Connect_API class
 *
 * @package Peanut_Connect
 */

namespace Peanut_Connect\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Peanut_Connect_API;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class ApiTest extends TestCase {

    private Peanut_Connect_API $api;

    protected function setUp(): void {
        parent::setUp();

        $this->api = new Peanut_Connect_API();

        // Reset global state
        global $peanut_test_options, $peanut_test_transients;
        $peanut_test_options = [];
        $peanut_test_transients = [];

        global $mock_user_caps;
        $mock_user_caps = ['manage_options' => true];
    }

    /**
     * Test admin_permission_check allows admins
     */
    public function test_admin_permission_check_allows_admins(): void {
        global $mock_user_caps;
        $mock_user_caps['manage_options'] = true;

        $result = $this->api->admin_permission_check();

        $this->assertTrue($result);
    }

    /**
     * Test admin_permission_check denies non-admins
     */
    public function test_admin_permission_check_denies_non_admins(): void {
        global $mock_user_caps;
        $mock_user_caps['manage_options'] = false;

        $result = $this->api->admin_permission_check();

        $this->assertFalse($result);
    }

    /**
     * Test get_settings returns expected structure
     */
    public function test_get_settings_returns_expected_structure(): void {
        $request = new WP_REST_Request('GET', '/settings');

        $response = $this->api->get_settings($request);

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $this->assertEquals(200, $response->status);
        $this->assertTrue($response->data['success']);
        $this->assertArrayHasKey('data', $response->data);
        $this->assertArrayHasKey('connection', $response->data['data']);
        $this->assertArrayHasKey('permissions', $response->data['data']);
    }

    /**
     * Test get_settings shows not connected when no site key
     */
    public function test_get_settings_shows_not_connected_without_site_key(): void {
        $request = new WP_REST_Request('GET', '/settings');

        $response = $this->api->get_settings($request);

        $this->assertFalse($response->data['data']['connection']['connected']);
    }

    /**
     * Test get_settings shows connected when site key and manager URL exist
     */
    public function test_get_settings_shows_connected_with_site_key_and_manager(): void {
        global $peanut_test_options;
        $peanut_test_options['peanut_connect_site_key'] = 'test_key_123';
        $peanut_test_options['peanut_connect_manager_url'] = 'https://manager.example.com';

        $request = new WP_REST_Request('GET', '/settings');

        $response = $this->api->get_settings($request);

        $this->assertTrue($response->data['data']['connection']['connected']);
        $this->assertEquals('https://manager.example.com', $response->data['data']['connection']['manager_url']);
    }

    /**
     * Test generate_site_key creates new key
     */
    public function test_generate_site_key_creates_new_key(): void {
        $request = new WP_REST_Request('POST', '/settings/generate-key');

        $response = $this->api->generate_site_key($request);

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $this->assertEquals(200, $response->status);
        $this->assertTrue($response->data['success']);
        $this->assertArrayHasKey('site_key', $response->data['data']);
        $this->assertNotEmpty($response->data['data']['site_key']);
    }

    /**
     * Test generate_site_key fails when key already exists
     */
    public function test_generate_site_key_fails_when_key_exists(): void {
        global $peanut_test_options;
        $peanut_test_options['peanut_connect_site_key'] = 'existing_key';

        $request = new WP_REST_Request('POST', '/settings/generate-key');

        $response = $this->api->generate_site_key($request);

        $this->assertEquals(400, $response->status);
        $this->assertFalse($response->data['success']);
    }

    /**
     * Test regenerate_site_key creates new key
     */
    public function test_regenerate_site_key_creates_new_key(): void {
        global $peanut_test_options;
        $peanut_test_options['peanut_connect_site_key'] = 'old_key';
        $peanut_test_options['peanut_connect_manager_url'] = 'https://old-manager.com';

        $request = new WP_REST_Request('POST', '/settings/regenerate-key');

        $response = $this->api->regenerate_site_key($request);

        $this->assertEquals(200, $response->status);
        $this->assertTrue($response->data['success']);
        $this->assertNotEquals('old_key', $response->data['data']['site_key']);
    }

    /**
     * Test regenerate_site_key clears manager connection
     */
    public function test_regenerate_site_key_clears_manager_connection(): void {
        global $peanut_test_options;
        $peanut_test_options['peanut_connect_site_key'] = 'old_key';
        $peanut_test_options['peanut_connect_manager_url'] = 'https://old-manager.com';
        $peanut_test_options['peanut_connect_last_sync'] = '2024-01-01 00:00:00';

        $request = new WP_REST_Request('POST', '/settings/regenerate-key');

        $this->api->regenerate_site_key($request);

        $this->assertArrayNotHasKey('peanut_connect_manager_url', $peanut_test_options);
        $this->assertArrayNotHasKey('peanut_connect_last_sync', $peanut_test_options);
    }

    /**
     * Test update_permissions updates allowed permissions
     */
    public function test_update_permissions_updates_allowed_permissions(): void {
        $request = new WP_REST_Request('POST', '/settings/permissions');
        $request->set_param('perform_updates', false);
        $request->set_param('access_analytics', false);

        // Mock get_json_params
        $reflection = new \ReflectionClass($request);
        $property = $reflection->getProperty('params');
        $property->setAccessible(true);
        $property->setValue($request, [
            'perform_updates' => false,
            'access_analytics' => false,
        ]);

        $response = $this->api->update_permissions($request);

        $this->assertEquals(200, $response->status);
        $this->assertTrue($response->data['success']);
        $this->assertFalse($response->data['data']['perform_updates']);
        $this->assertFalse($response->data['data']['access_analytics']);
    }

    /**
     * Test update_permissions keeps health_check always enabled
     */
    public function test_update_permissions_keeps_health_check_enabled(): void {
        $request = new WP_REST_Request('POST', '/settings/permissions');

        $response = $this->api->update_permissions($request);

        $this->assertTrue($response->data['data']['health_check']);
        $this->assertTrue($response->data['data']['list_updates']);
    }

    /**
     * Test admin_disconnect clears all connection data
     */
    public function test_admin_disconnect_clears_connection_data(): void {
        global $peanut_test_options;
        $peanut_test_options['peanut_connect_site_key'] = 'test_key';
        $peanut_test_options['peanut_connect_manager_url'] = 'https://manager.com';
        $peanut_test_options['peanut_connect_last_sync'] = '2024-01-01';

        $request = new WP_REST_Request('POST', '/settings/disconnect');

        $response = $this->api->admin_disconnect($request);

        $this->assertEquals(200, $response->status);
        $this->assertTrue($response->data['success']);
        $this->assertArrayNotHasKey('peanut_connect_site_key', $peanut_test_options);
        $this->assertArrayNotHasKey('peanut_connect_manager_url', $peanut_test_options);
    }

    /**
     * Test verify endpoint returns expected structure
     */
    public function test_verify_returns_expected_structure(): void {
        $request = new WP_REST_Request('GET', '/verify');

        $response = $this->api->verify($request);

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $this->assertEquals(200, $response->status);
        $this->assertTrue($response->data['success']);
        $this->assertArrayHasKey('site_name', $response->data);
        $this->assertArrayHasKey('site_url', $response->data);
        $this->assertArrayHasKey('wp_version', $response->data);
        $this->assertArrayHasKey('permissions', $response->data);
    }

    /**
     * Test get_health returns health data
     */
    public function test_get_health_returns_health_data(): void {
        $request = new WP_REST_Request('GET', '/health');

        $response = $this->api->get_health($request);

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $this->assertEquals(200, $response->status);
        $this->assertArrayHasKey('wp_version', $response->data);
        $this->assertArrayHasKey('php_version', $response->data);
        $this->assertArrayHasKey('ssl', $response->data);
    }

    /**
     * Test get_updates returns update data
     */
    public function test_get_updates_returns_update_data(): void {
        $request = new WP_REST_Request('GET', '/updates');

        $response = $this->api->get_updates($request);

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $this->assertEquals(200, $response->status);
        $this->assertArrayHasKey('plugins', $response->data);
        $this->assertArrayHasKey('themes', $response->data);
        $this->assertArrayHasKey('core', $response->data);
    }

    /**
     * Test perform_update returns error for invalid type
     */
    public function test_perform_update_returns_error_for_invalid_type(): void {
        $request = new WP_REST_Request('POST', '/update');
        $request->set_param('type', 'invalid');
        $request->set_param('slug', 'test');

        $response = $this->api->perform_update($request);

        $this->assertEquals(400, $response->status);
        $this->assertFalse($response->data['success']);
        $this->assertEquals('invalid_type', $response->data['code']);
    }

    /**
     * Test perform_update returns error for non-existent plugin
     */
    public function test_perform_update_returns_error_for_nonexistent_plugin(): void {
        $request = new WP_REST_Request('POST', '/update');
        $request->set_param('type', 'plugin');
        $request->set_param('slug', 'nonexistent-plugin');

        $response = $this->api->perform_update($request);

        $this->assertEquals(400, $response->status);
        $this->assertFalse($response->data['success']);
    }

    /**
     * Test get_analytics returns error when Peanut Suite not installed
     */
    public function test_get_analytics_returns_error_without_peanut_suite(): void {
        $request = new WP_REST_Request('GET', '/analytics');

        $response = $this->api->get_analytics($request);

        $this->assertEquals(404, $response->status);
        $this->assertFalse($response->data['success']);
        $this->assertEquals('peanut_suite_not_installed', $response->data['code']);
    }

    /**
     * Test handle_disconnect clears manager connection
     */
    public function test_handle_disconnect_clears_manager_connection(): void {
        global $peanut_test_options;
        $peanut_test_options['peanut_connect_manager_url'] = 'https://manager.com';
        $peanut_test_options['peanut_connect_last_sync'] = '2024-01-01';

        $request = new WP_REST_Request('POST', '/disconnect');

        $response = $this->api->handle_disconnect($request);

        $this->assertEquals(200, $response->status);
        $this->assertTrue($response->data['success']);
        $this->assertArrayNotHasKey('peanut_connect_manager_url', $peanut_test_options);
    }

    /**
     * Test get_admin_health wraps health data correctly
     */
    public function test_get_admin_health_wraps_health_data(): void {
        $request = new WP_REST_Request('GET', '/admin/health');

        $response = $this->api->get_admin_health($request);

        $this->assertEquals(200, $response->status);
        $this->assertTrue($response->data['success']);
        $this->assertArrayHasKey('data', $response->data);
    }

    /**
     * Test get_admin_updates wraps update data correctly
     */
    public function test_get_admin_updates_wraps_update_data(): void {
        $request = new WP_REST_Request('GET', '/admin/updates');

        $response = $this->api->get_admin_updates($request);

        $this->assertEquals(200, $response->status);
        $this->assertTrue($response->data['success']);
        $this->assertArrayHasKey('data', $response->data);
    }

    /**
     * Test activity log endpoint returns expected structure
     */
    public function test_get_activity_log_returns_expected_structure(): void {
        $request = new WP_REST_Request('GET', '/activity');

        $response = $this->api->get_activity_log($request);

        $this->assertEquals(200, $response->status);
        $this->assertTrue($response->data['success']);
        $this->assertArrayHasKey('data', $response->data);
        $this->assertArrayHasKey('entries', $response->data['data']);
        $this->assertArrayHasKey('counts', $response->data['data']);
    }

    /**
     * Test activity counts endpoint returns expected structure
     */
    public function test_get_activity_counts_returns_expected_structure(): void {
        $request = new WP_REST_Request('GET', '/activity/counts');

        $response = $this->api->get_activity_counts($request);

        $this->assertEquals(200, $response->status);
        $this->assertTrue($response->data['success']);
        $this->assertArrayHasKey('data', $response->data);
        $this->assertArrayHasKey('by_type', $response->data['data']);
        $this->assertArrayHasKey('last_24h', $response->data['data']);
    }

    /**
     * Test clear activity log returns success
     */
    public function test_clear_activity_log_returns_success(): void {
        $request = new WP_REST_Request('POST', '/activity/clear');

        $response = $this->api->clear_activity_log($request);

        $this->assertEquals(200, $response->status);
        $this->assertTrue($response->data['success']);
    }

    /**
     * Test export activity log returns CSV data
     */
    public function test_export_activity_log_returns_csv_data(): void {
        $request = new WP_REST_Request('GET', '/activity/export');

        $response = $this->api->export_activity_log($request);

        $this->assertEquals(200, $response->status);
        $this->assertTrue($response->data['success']);
        $this->assertArrayHasKey('csv', $response->data['data']);
        $this->assertArrayHasKey('filename', $response->data['data']);
        $this->assertStringContainsString('.csv', $response->data['data']['filename']);
    }
}
