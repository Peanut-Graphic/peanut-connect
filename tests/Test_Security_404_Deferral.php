<?php
/**
 * Regression guard for the hide-login 404 render.
 *
 * The block on wp-login.php / wp-admin is detected on `init` priority 1, which
 * is deliberately early -- early enough to get in front of anything that would
 * leak the custom login slug. Rendering the theme's 404 template there was not
 * deliberate, and it was a live bug: themes register their own template classes
 * during `init` itself (Enfold loads class-font-manager.php at `init` priority
 * 5), so including 404.php at priority 1 fataled on a class that did not exist
 * yet. Visitors got "There has been a critical error on this website" plus a
 * stack trace with absolute server paths instead of a 404.
 *
 * These tests pin the shape of the fix: decide early, render on `wp_loaded`.
 *
 * @package Peanut_Connect
 */

// The root test universe has no hook or template functions of its own (a probe
// found add_action, status_header, nocache_headers, get_404_template and wp_die
// all undefined here), so define recording stand-ins. Guarded so that a richer
// harness always wins.
if (!function_exists('add_action')) {
    function add_action(string $hook, $callback, int $priority = 10, int $accepted_args = 1): bool {
        $GLOBALS['peanut_test_actions'][] = [
            'hook' => $hook,
            'callback' => $callback,
            'priority' => $priority,
        ];
        return true;
    }
}
if (!function_exists('status_header')) {
    function status_header(int $code): void {
        $GLOBALS['peanut_test_status'] = $code;
    }
}
if (!function_exists('nocache_headers')) {
    function nocache_headers(): void {
        $GLOBALS['peanut_test_nocache'] = true;
    }
}
if (!function_exists('get_404_template')) {
    function get_404_template(): string {
        $GLOBALS['peanut_test_template_asked'] = true;
        // Deliberately not a readable path: render_404() then takes the wp_die
        // fallback instead of include()+exit, which would kill the test run.
        return '';
    }
}
if (!function_exists('wp_die')) {
    function wp_die($message = '', $title = '', $args = []) {
        $code = (is_array($args) && isset($args['response'])) ? $args['response'] : '';
        throw new RuntimeException('wp_die:' . $code);
    }
}

class Test_Security_404_Deferral extends PHPUnit\Framework\TestCase {

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['peanut_test_actions'] = [];
        $GLOBALS['pp_actions'] = [];
        $GLOBALS['peanut_test_status'] = null;
        $GLOBALS['peanut_test_nocache'] = false;
        $GLOBALS['peanut_test_template_asked'] = false;
    }

    private function callShow404(): void {
        $m = new ReflectionMethod('Peanut_Connect_Security', 'show_404');
        // Required below 8.1 to reach a private method; from 8.1 it is a no-op
        // that emits a deprecation on 8.5, which would land inside the output
        // buffer below and masquerade as inline rendering. So call it only
        // where it still does something.
        if (PHP_VERSION_ID < 80100) {
            $m->setAccessible(true);
        }
        $m->invoke(null);
    }

    /**
     * Hooks registered during the call, whichever add_action stand-in won the
     * definition race for this run. Test_Roles.php also defines one (recording
     * into pp_actions, keyed by hook and without a priority), and file load
     * order decides which is in play -- so read both rather than depend on it.
     *
     * @return array<int, array{hook: string, callback: mixed, priority: ?int}>
     */
    private function registeredActions(): array {
        if (!empty($GLOBALS['peanut_test_actions'])) {
            return $GLOBALS['peanut_test_actions'];
        }

        $out = [];
        foreach (($GLOBALS['pp_actions'] ?? []) as $hook => $callback) {
            $out[] = ['hook' => (string) $hook, 'callback' => $callback, 'priority' => null];
        }
        return $out;
    }

    /**
     * show_404() must not render anything itself. If it does, it is rendering
     * during `init` priority 1 again and the theme is not loaded yet.
     */
    public function test_show_404_renders_nothing_inline(): void {
        ob_start();
        $this->callShow404();
        $output = ob_get_clean();

        $this->assertSame('', $output, 'show_404() rendered inline; it must defer the render.');
        $this->assertFalse(
            $GLOBALS['peanut_test_template_asked'],
            'show_404() asked for the theme 404 template during init -- that is the fatal.'
        );
    }

    /**
     * The render must be deferred to `wp_loaded`, which fires after the whole
     * of `init` (so the theme's classes exist) and in every entry point we
     * block -- wp-login.php and wp-admin both reach it while bootstrapping.
     */
    public function test_show_404_defers_render_to_wp_loaded(): void {
        $this->callShow404();

        $actions = $this->registeredActions();
        $hooks = array_column($actions, 'hook');
        $this->assertContains('wp_loaded', $hooks, 'the 404 render was not deferred to wp_loaded.');
        $this->assertNotContains('init', $hooks, 'the render must not be re-registered on init.');

        $deferred = null;
        foreach ($actions as $a) {
            if ($a['hook'] === 'wp_loaded') {
                $deferred = $a;
                break;
            }
        }
        $this->assertNotNull($deferred);
        $this->assertSame(
            ['Peanut_Connect_Security', 'render_404'],
            $deferred['callback'],
            'wp_loaded should be handed the public render entry point.'
        );
        if ($deferred['priority'] !== null) {
            $this->assertSame(0, $deferred['priority'], 'render should run first on wp_loaded.');
        }
    }

    /**
     * The deferred render still has to produce a real 404: correct status,
     * no-cache headers, and the theme's own template consulted.
     */
    public function test_deferred_render_sends_a_real_404(): void {
        try {
            Peanut_Connect_Security::render_404();
            $this->fail('render_404() should have terminated via the wp_die fallback.');
        } catch (RuntimeException $e) {
            $this->assertSame('wp_die:404', $e->getMessage(), 'fallback must still answer 404.');
        }

        $this->assertSame(404, $GLOBALS['peanut_test_status'], 'a blocked request must answer 404.');
        $this->assertTrue($GLOBALS['peanut_test_nocache'], 'the 404 must not be cached.');
        $this->assertTrue($GLOBALS['peanut_test_template_asked'], 'the theme 404 template should be used.');
    }
}
