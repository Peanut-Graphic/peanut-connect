<?php
/**
 * Peanut shared bootstrap for REAL-WordPress test suites (net 7 REST contract tests).
 *
 * Unlike the per-plugin mock bootstraps, this REQUIRES the WordPress test library and
 * loads a real WordPress — so `register_rest_route` actually registers routes and
 * contract tests can hit real `/wp-json/<ns>/v1/*` responses. If the test library is
 * absent it FAILS LOUDLY (no silent mock fallback) — a contract suite that "passes"
 * against mocks is exactly the dishonest state we're removing.
 *
 * Per-plugin usage: copy to tests/Contract/bootstrap.php (or point phpunit.contract.xml's
 * bootstrap at the vendored copy) and set PLUGIN_MAIN_FILE to the plugin's entry file.
 */

$_tests_dir = getenv('WP_TESTS_DIR') ?: '/tmp/wordpress-tests-lib';

if (! file_exists("{$_tests_dir}/includes/functions.php")) {
    fwrite(STDERR, "\n[wp-harness] WordPress test library not found at {$_tests_dir}.\n");
    fwrite(STDERR, "Run testing-standard/wp-harness/install-wp-tests.sh first (CI does this via the wp-integration template).\n");
    fwrite(STDERR, "This is the CONTRACT suite — it must boot real WordPress, not mocks.\n\n");
    exit(1);
}

// WordPress' bootstrap reads the polyfills path as a CONSTANT. Under a standalone
// phpunit phar (no Composer autoloader active) the env var alone fails with
// "PHPUnit Polyfills library is a requirement" — so set the constant from env or vendor.
if (! defined('WP_TESTS_PHPUNIT_POLYFILLS_PATH')) {
    $_polyfills = getenv('WP_TESTS_PHPUNIT_POLYFILLS_PATH');
    if (! $_polyfills && is_dir(__DIR__ . '/../../vendor/yoast/phpunit-polyfills')) {
        $_polyfills = realpath(__DIR__ . '/../../vendor/yoast/phpunit-polyfills');
    }
    if ($_polyfills) {
        define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_polyfills);
    }
}

require_once "{$_tests_dir}/includes/functions.php";

// Each plugin defines PLUGIN_MAIN_FILE before requiring this bootstrap.
if (! defined('PLUGIN_MAIN_FILE')) {
    fwrite(STDERR, "[wp-harness] define('PLUGIN_MAIN_FILE', __DIR__.'/../../<plugin>.php') before requiring bootstrap-wp.php\n");
    exit(1);
}

tests_add_filter('muplugins_loaded', static function () {
    require_once __DIR__ . '/lifecycle-hook-guard.php';
    Peanut_Lifecycle_Hook_Guard::instrument(['plugins_loaded', 'init']);
}, -1000);

tests_add_filter('muplugins_loaded', static function () {
    require PLUGIN_MAIN_FILE;
});

require "{$_tests_dir}/includes/bootstrap.php";

Peanut_Lifecycle_Hook_Guard::assert_clean();
