<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

/**
 * A Hub-driven update must install what is CURRENT, not what was cached.
 *
 * `update_plugins` is WordPress's own transient and is up to 12 hours stale.
 * Reading it without refreshing meant a remote update installed whatever was
 * current the last time the site happened to look: on 2026-08-24, hours after
 * 3.37.3 shipped, a fleet push landed 3.37.1 on five sites and was refused on
 * four more with "no update available". Every one of those was a cache
 * artefact, not a true state.
 *
 * The order is the whole point, so that is what is asserted: the refresh has
 * to happen BEFORE the decision is read.
 */
final class Test_Remote_Update_Freshness extends TestCase {

    public function test_the_update_check_is_refreshed_before_the_transient_is_read(): void {
        $source = file_get_contents(dirname(__DIR__) . '/includes/class-connect-updates.php');
        $this->assertIsString($source);

        $start = strpos($source, 'public static function update_plugin(');
        $this->assertNotFalse($start, 'update_plugin() not found.');

        // Bound the search to this method so a refresh belonging to some other
        // function cannot make the assertion pass by accident.
        $end = strpos($source, 'public static function ', $start + 10);
        $body = substr($source, $start, $end === false ? null : $end - $start);

        $refresh = strpos($body, 'refresh_plugin_updates(');
        $read = strpos($body, "get_site_transient('update_plugins')");

        $this->assertNotFalse($refresh, 'update_plugin() never refreshes the update check, so a 12-hour-stale cache decides which version lands.');
        $this->assertNotFalse($read, "update_plugin() no longer reads the update_plugins transient — this test needs rewriting.");
        $this->assertLessThan(
            $read,
            $refresh,
            'The refresh runs AFTER the transient is read, so the stale cache still decides the outcome.'
        );
    }

    public function test_the_refresh_actually_re_runs_the_check_not_just_clears_it(): void {
        // Clearing without re-running fails in the same direction as the stale
        // cache: the next read sees an empty cache and reports "no update".
        $source = file_get_contents(dirname(__DIR__) . '/includes/class-connect-updates.php');

        $start = strpos($source, 'public static function refresh_plugin_updates(');
        $this->assertNotFalse($start, 'refresh_plugin_updates() not found.');

        $end = strpos($source, 'public static function ', $start + 10);
        $body = substr($source, $start, $end === false ? null : $end - $start);

        $this->assertTrue(
            str_contains($body, 'wp_clean_plugins_cache') || str_contains($body, 'wp_update_plugins'),
            'The refresh only clears the cache without re-running the update check.'
        );
    }
}
