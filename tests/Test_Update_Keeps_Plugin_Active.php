<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string { return $text; }
}

// Minimal stand-ins for the two WordPress functions this seam depends on.
if (!function_exists('is_plugin_active')) {
    function is_plugin_active(string $plugin): bool {
        return in_array($plugin, $GLOBALS['mock_active_plugins'] ?? [], true);
    }
}
if (!function_exists('activate_plugin')) {
    function activate_plugin(string $plugin) {
        $GLOBALS['mock_activate_calls'][] = $plugin;

        if (!empty($GLOBALS['mock_activate_error'])) {
            return new WP_Error('activation_failed', $GLOBALS['mock_activate_error']);
        }

        $GLOBALS['mock_active_plugins'][] = $plugin;

        return null;
    }
}
if (!class_exists('WP_Error')) {
    class WP_Error {
        public function __construct(private string $code = '', private string $message = '') {}

        public function get_error_code(): string { return $this->code; }

        public function get_error_message(): string { return $this->message; }
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing): bool { return $thing instanceof WP_Error; }
}

/**
 * A Hub-driven update must leave the plugin RUNNING.
 *
 * WordPress deactivates a plugin before swapping its files
 * (deactivate_plugin_before_upgrade, silently) and does not reliably bring it
 * back when the upgrade is driven outside wp-admin. On 2026-08-24 a fleet-wide
 * push left Peanut Connect INACTIVE on seven client sites: files correct and
 * current, homepages fine, and every Hub capability gone — no health, no
 * backups, no remote control — with nothing reporting a problem, because the
 * update itself had returned "Plugin updated to version 3.37.1."
 *
 * Four of those sites were recovered with a single `plugin activate`; three
 * needed someone with wp-admin because the API required to fix them is the
 * one that had been switched off.
 */
final class Test_Update_Keeps_Plugin_Active extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['mock_active_plugins'] = [];
        $GLOBALS['mock_activate_calls'] = [];
        $GLOBALS['mock_activate_error'] = null;

        require_once dirname(__DIR__) . '/includes/class-connect-updates.php';
    }

    public function test_a_plugin_that_was_running_is_running_again_afterwards(): void {
        $plugin = 'peanut-connect/peanut-connect.php';

        // The state right after WordPress swapped the files: gone from active.
        $GLOBALS['mock_active_plugins'] = [];

        $report = Peanut_Connect_Updates::ensure_still_active($plugin, true);

        $this->assertSame([$plugin], $GLOBALS['mock_activate_calls'], 'The update left the plugin deactivated and never switched it back on.');
        $this->assertTrue($report['active']);
        $this->assertTrue($report['reactivated']);
    }

    public function test_a_plugin_that_was_already_off_is_left_off(): void {
        $plugin = 'some-other/plugin.php';

        $report = Peanut_Connect_Updates::ensure_still_active($plugin, false);

        $this->assertSame([], $GLOBALS['mock_activate_calls'], 'Updating a deliberately deactivated plugin switched it on.');
        $this->assertFalse($report['active']);
        $this->assertFalse($report['reactivated']);
    }

    public function test_a_plugin_still_active_is_not_activated_twice(): void {
        $plugin = 'peanut-connect/peanut-connect.php';
        $GLOBALS['mock_active_plugins'] = [$plugin];

        $report = Peanut_Connect_Updates::ensure_still_active($plugin, true);

        $this->assertSame([], $GLOBALS['mock_activate_calls']);
        $this->assertTrue($report['active']);
        $this->assertFalse($report['reactivated']);
    }

    public function test_a_failed_reactivation_is_reported_not_swallowed(): void {
        // Reporting success while the plugin is off is the exact shape that
        // hid this for a day.
        $plugin = 'peanut-connect/peanut-connect.php';
        $GLOBALS['mock_activate_error'] = 'fatal on activation';

        $report = Peanut_Connect_Updates::ensure_still_active($plugin, true);

        $this->assertFalse($report['active']);
        $this->assertNotEmpty($report['error']);
    }
}
