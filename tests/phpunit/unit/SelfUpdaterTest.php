<?php
/**
 * Tests for Peanut_Connect_Self_Updater
 *
 * @package Peanut_Connect
 */

use PHPUnit\Framework\TestCase;

class SelfUpdaterTest extends TestCase {

    /**
     * Self updater instance
     */
    private Peanut_Connect_Self_Updater $updater;

    /**
     * Set up test environment
     */
    protected function setUp(): void {
        peanut_reset_test_state();

        // Reset global mocks
        global $mock_remote_response, $mock_plugin_data;
        $mock_remote_response = null;
        $mock_plugin_data = null;

        $this->updater = new Peanut_Connect_Self_Updater();
    }

    /**
     * Clean up after tests
     */
    protected function tearDown(): void {
        global $mock_remote_response, $mock_plugin_data;
        $mock_remote_response = null;
        $mock_plugin_data = null;
    }

    // =========================================
    // Check For Update Tests
    // =========================================

    /**
     * Test check_for_update returns transient unchanged when checked is empty
     */
    public function test_check_for_update_returns_early_when_checked_empty(): void {
        $transient = (object) [
            'checked' => [],
        ];

        $result = $this->updater->check_for_update($transient);

        $this->assertEquals($transient, $result);
    }

    /**
     * Test check_for_update detects new version available
     */
    public function test_check_for_update_detects_new_version(): void {
        global $mock_remote_response;

        // Mock a response with a newer version
        $mock_remote_response = [
            'response' => ['code' => 200],
            'body' => json_encode([
                'plugin_info' => (object) [
                    'version' => '3.0.0',
                    'download_url' => 'https://github.com/peanutgraphic/peanut-connect/releases/download/v3.0.0/peanut-connect.zip',
                    'homepage' => 'https://peanutgraphic.com',
                    'tested' => '6.4',
                    'requires_php' => '8.0',
                    'requires' => '6.0',
                ],
            ]),
        ];

        $transient = (object) [
            'checked' => [
                'peanut-connect/peanut-connect.php' => '2.1.0',
            ],
        ];

        $result = $this->updater->check_for_update($transient);

        $this->assertObjectHasProperty('response', $result);
        $this->assertArrayHasKey('peanut-connect/peanut-connect.php', $result->response);

        $update = $result->response['peanut-connect/peanut-connect.php'];
        $this->assertEquals('3.0.0', $update->new_version);
        $this->assertEquals('https://github.com/peanutgraphic/peanut-connect/releases/download/v3.0.0/peanut-connect.zip', $update->package);
    }

    /**
     * Test check_for_update sets no_update when current version is latest
     */
    public function test_check_for_update_sets_no_update_when_current(): void {
        global $mock_remote_response;

        // Mock a response with same version
        $mock_remote_response = [
            'response' => ['code' => 200],
            'body' => json_encode([
                'plugin_info' => (object) [
                    'version' => '2.1.3',
                    'homepage' => 'https://peanutgraphic.com',
                ],
            ]),
        ];

        $transient = (object) [
            'checked' => [
                'peanut-connect/peanut-connect.php' => '2.1.3',
            ],
        ];

        $result = $this->updater->check_for_update($transient);

        $this->assertObjectHasProperty('no_update', $result);
        $this->assertArrayHasKey('peanut-connect/peanut-connect.php', $result->no_update);
    }

    /**
     * Test check_for_update handles API error gracefully
     */
    public function test_check_for_update_handles_api_error(): void {
        global $mock_remote_response;

        // Mock an error response
        $mock_remote_response = new WP_Error('http_error', 'Connection failed');

        $transient = (object) [
            'checked' => [
                'peanut-connect/peanut-connect.php' => '2.1.0',
            ],
        ];

        $result = $this->updater->check_for_update($transient);

        // Should return original transient without modifications
        $this->assertObjectNotHasProperty('response', $result);
    }

    /**
     * Test check_for_update handles invalid JSON gracefully
     */
    public function test_check_for_update_handles_invalid_json(): void {
        global $mock_remote_response;

        // Mock an invalid response
        $mock_remote_response = [
            'response' => ['code' => 200],
            'body' => 'not valid json',
        ];

        $transient = (object) [
            'checked' => [
                'peanut-connect/peanut-connect.php' => '2.1.0',
            ],
        ];

        $result = $this->updater->check_for_update($transient);

        // Should return original transient without modifications
        $this->assertObjectNotHasProperty('response', $result);
    }

    // =========================================
    // Plugin Info Tests
    // =========================================

    /**
     * Test plugin_info returns null for wrong action
     */
    public function test_plugin_info_returns_null_for_wrong_action(): void {
        $result = $this->updater->plugin_info(null, 'other_action', (object) ['slug' => 'peanut-connect']);

        $this->assertNull($result);
    }

    /**
     * Test plugin_info returns null for wrong slug
     */
    public function test_plugin_info_returns_null_for_wrong_slug(): void {
        $result = $this->updater->plugin_info(null, 'plugin_information', (object) ['slug' => 'other-plugin']);

        $this->assertNull($result);
    }

    /**
     * Test plugin_info returns plugin data for correct slug
     */
    public function test_plugin_info_returns_data_for_correct_slug(): void {
        global $mock_remote_response;

        $mock_remote_response = [
            'response' => ['code' => 200],
            'body' => json_encode([
                'plugin_info' => (object) [
                    'name' => 'Peanut Connect',
                    'version' => '2.1.3',
                    'author' => '<a href="https://peanutgraphic.com">Peanut Graphic</a>',
                    'homepage' => 'https://peanutgraphic.com/peanut-connect',
                    'download_url' => 'https://github.com/peanutgraphic/peanut-connect/releases/download/v2.1.3/peanut-connect.zip',
                    'requires' => '6.0',
                    'tested' => '6.4',
                    'requires_php' => '8.0',
                ],
            ]),
        ];

        $result = $this->updater->plugin_info(null, 'plugin_information', (object) ['slug' => 'peanut-connect']);

        $this->assertIsObject($result);
        $this->assertEquals('Peanut Connect', $result->name);
        $this->assertEquals('peanut-connect', $result->slug);
        $this->assertEquals('2.1.3', $result->version);
        $this->assertArrayHasKey('description', $result->sections);
        $this->assertArrayHasKey('installation', $result->sections);
        $this->assertArrayHasKey('changelog', $result->sections);
    }

    /**
     * Test plugin_info returns null when API fails
     */
    public function test_plugin_info_returns_null_when_api_fails(): void {
        global $mock_remote_response;

        $mock_remote_response = new WP_Error('http_error', 'Connection failed');

        $result = $this->updater->plugin_info(null, 'plugin_information', (object) ['slug' => 'peanut-connect']);

        $this->assertNull($result);
    }

    // =========================================
    // Cache Tests
    // =========================================

    /**
     * Test clear_update_cache removes transients
     */
    public function test_clear_update_cache_deletes_site_transient(): void {
        global $mock_transients;

        $mock_transients['update_plugins'] = ['some' => 'data'];

        $this->updater->clear_update_cache();

        $this->assertArrayNotHasKey('update_plugins', $mock_transients);
    }

    /**
     * Test cached response is used on second call
     */
    public function test_cached_response_is_used(): void {
        global $mock_remote_response;

        // First call sets cache
        $mock_remote_response = [
            'response' => ['code' => 200],
            'body' => json_encode([
                'plugin_info' => (object) [
                    'version' => '3.0.0',
                ],
            ]),
        ];

        $transient = (object) [
            'checked' => [
                'peanut-connect/peanut-connect.php' => '2.1.0',
            ],
        ];

        $this->updater->check_for_update($transient);

        // Change mock response - but cache should be used
        $mock_remote_response = [
            'response' => ['code' => 200],
            'body' => json_encode([
                'plugin_info' => (object) [
                    'version' => '4.0.0',
                ],
            ]),
        ];

        $result = $this->updater->check_for_update($transient);

        // Should still show 3.0.0 from cache
        $update = $result->response['peanut-connect/peanut-connect.php'];
        $this->assertEquals('3.0.0', $update->new_version);
    }

    // =========================================
    // Force Update Check Tests
    // =========================================

    /**
     * Test force_update_check clears cache and returns info
     */
    public function test_force_update_check_returns_update_info(): void {
        global $mock_remote_response, $mock_plugin_data;

        $mock_plugin_data = [
            'Version' => '2.1.0',
            'Name' => 'Peanut Connect',
        ];

        $mock_remote_response = [
            'response' => ['code' => 200],
            'body' => json_encode([
                'plugin_info' => (object) [
                    'version' => '3.0.0',
                    'download_url' => 'https://github.com/peanutgraphic/peanut-connect/releases/download/v3.0.0/peanut-connect.zip',
                ],
            ]),
        ];

        $result = $this->updater->force_update_check();

        $this->assertIsObject($result);
        $this->assertEquals('3.0.0', $result->version);
    }

    /**
     * Test force_update_check returns null on error
     */
    public function test_force_update_check_returns_null_on_error(): void {
        global $mock_remote_response, $mock_plugin_data;

        $mock_plugin_data = [
            'Version' => '2.1.0',
            'Name' => 'Peanut Connect',
        ];

        $mock_remote_response = new WP_Error('http_error', 'Failed');

        $result = $this->updater->force_update_check();

        $this->assertNull($result);
    }

    // =========================================
    // Version Comparison Tests
    // =========================================

    /**
     * Test version comparison correctly identifies update needed
     *
     * @dataProvider versionComparisonProvider
     */
    public function test_version_comparison(string $current, string $remote, bool $update_expected): void {
        global $mock_remote_response;

        $mock_remote_response = [
            'response' => ['code' => 200],
            'body' => json_encode([
                'plugin_info' => (object) [
                    'version' => $remote,
                    'download_url' => 'https://github.com/peanutgraphic/peanut-connect/releases/download/test/plugin.zip',
                ],
            ]),
        ];

        $transient = (object) [
            'checked' => [
                'peanut-connect/peanut-connect.php' => $current,
            ],
        ];

        $result = $this->updater->check_for_update($transient);

        if ($update_expected) {
            $this->assertArrayHasKey('peanut-connect/peanut-connect.php', $result->response ?? []);
        } else {
            $this->assertArrayHasKey('peanut-connect/peanut-connect.php', $result->no_update ?? []);
        }
    }

    public static function versionComparisonProvider(): array {
        return [
            'major update' => ['1.0.0', '2.0.0', true],
            'minor update' => ['2.0.0', '2.1.0', true],
            'patch update' => ['2.1.0', '2.1.1', true],
            'same version' => ['2.1.3', '2.1.3', false],
            'older version' => ['2.2.0', '2.1.0', false],
        ];
    }

    // =========================================
    // Update Data Structure Tests
    // =========================================

    /**
     * Test update response includes all required fields
     */
    public function test_update_response_includes_required_fields(): void {
        global $mock_remote_response;

        $mock_remote_response = [
            'response' => ['code' => 200],
            'body' => json_encode([
                'plugin_info' => (object) [
                    'version' => '3.0.0',
                    'download_url' => 'https://github.com/peanutgraphic/peanut-connect/releases/download/v3.0.0/peanut-connect.zip',
                    'homepage' => 'https://peanutgraphic.com/peanut-connect',
                    'tested' => '6.4',
                    'requires_php' => '8.0',
                    'requires' => '6.0',
                ],
            ]),
        ];

        $transient = (object) [
            'checked' => [
                'peanut-connect/peanut-connect.php' => '2.1.0',
            ],
        ];

        $result = $this->updater->check_for_update($transient);
        $update = $result->response['peanut-connect/peanut-connect.php'];

        $this->assertEquals('peanut-connect', $update->slug);
        $this->assertEquals('peanut-connect/peanut-connect.php', $update->plugin);
        $this->assertEquals('3.0.0', $update->new_version);
        $this->assertEquals('https://github.com/peanutgraphic/peanut-connect/releases/download/v3.0.0/peanut-connect.zip', $update->package);
        $this->assertEquals('https://peanutgraphic.com/peanut-connect', $update->url);
        $this->assertEquals('6.4', $update->tested);
        $this->assertEquals('8.0', $update->requires_php);
        $this->assertEquals('6.0', $update->requires);
    }
}
