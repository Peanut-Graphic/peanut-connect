<?php
/**
 * Regression tests for the slug→plugin-file resolver and the protected-plugin
 * guard — the "update/activate confused-deputy" cluster.
 *
 * A Hub caller holding the `perform_updates` cap sends a bare slug; the child
 * resolves it to an installed plugin file and then updates/activates it. Two
 * residual hardening bugs are pinned here:
 *
 *   (a) find_plugin_file() used a loose stripos() prefix fallback, so slug
 *       "seo" could resolve to an unrelated "seo-pack" (or an arbitrary one of
 *       several prefix matches). The fix prefers an EXACT match and only falls
 *       back to an UNAMBIGUOUS canonical (version-suffix-stripped) match.
 *
 *   (b) is_protected_plugin() keyed on the exact folder slug, so a security
 *       plugin re-installed in a version-suffixed folder ("wordfence-7.11.0/…")
 *       slipped past the teardown guard. The fix also matches the canonical
 *       slug, mirroring (a).
 *
 * @package Peanut_Connect
 */

use PHPUnit\Framework\TestCase;

// Minimal stubs for the two WordPress lookups find_plugin_file() performs.
// Guarded so a real WP harness (which defines these) is never overridden.
if (!function_exists('get_plugins')) {
    function get_plugins() {
        return $GLOBALS['__test_all_plugins'] ?? [];
    }
}
if (!function_exists('get_site_transient')) {
    function get_site_transient($key) {
        return $GLOBALS['__test_site_transients'][$key] ?? false;
    }
}

require_once dirname(__DIR__) . '/includes/class-connect-updates.php';

class Test_Update_Resolution_ExactMatch extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['__test_all_plugins'] = [];
        $GLOBALS['__test_site_transients'] = [];
    }

    /** Install the given plugin files (values are irrelevant to resolution). */
    private function install(array $files): void {
        $GLOBALS['__test_all_plugins'] = array_fill_keys($files, ['Name' => 'x', 'Version' => '1.0']);
    }

    private function resolve(string $slug): ?string {
        $m = new ReflectionMethod(Peanut_Connect_Updates::class, 'find_plugin_file');
        return $m->invoke(null, $slug);
    }

    // ---- (a) resolver -----------------------------------------------------

    public function test_exact_slug_wins_over_prefix_sibling(): void {
        // "seo" and "seo-pack" both installed: the exact folder must win.
        $this->install(['seo/seo.php', 'seo-pack/seo-pack.php']);
        $this->assertSame('seo/seo.php', $this->resolve('seo'));
    }

    public function test_loose_prefix_no_longer_resolves_to_wrong_plugin(): void {
        // Only "seo-pack" installed. The old stripos("seo-") fallback wrongly
        // resolved slug "seo" → "seo-pack/seo-pack.php". Now it must be rejected.
        $this->install(['seo-pack/seo-pack.php']);
        $this->assertNull(
            $this->resolve('seo'),
            'loose prefix must not resolve slug "seo" to unrelated "seo-pack"'
        );
    }

    public function test_versioned_folder_resolves_when_unambiguous(): void {
        // Legit case the resolver must still support: a version-suffixed folder.
        $this->install(['peanut-connect-3.3.9/peanut-connect.php']);
        $this->assertSame(
            'peanut-connect-3.3.9/peanut-connect.php',
            $this->resolve('peanut-connect')
        );
    }

    public function test_ambiguous_versioned_folders_are_rejected(): void {
        // Two folders canonicalise to the same slug — refuse to guess. (Main
        // file named distinctly from the slug so the exact-basename path in
        // pass 1 doesn't short-circuit; this exercises the pass-2 bail.)
        $this->install(['acme-1.0/acme-plugin.php', 'acme-2.0/acme-plugin.php']);
        $this->assertNull(
            $this->resolve('acme'),
            'ambiguous canonical match must be rejected, not guessed'
        );
    }

    // ---- (b) protected-plugin guard --------------------------------------

    public function test_protected_plugin_in_versioned_folder_still_guarded(): void {
        $this->assertTrue(
            Peanut_Connect_Updates::is_protected_plugin('wordfence-7.11.0/wordfence.php'),
            'a security plugin in a version-suffixed folder must stay protected'
        );
        $this->assertTrue(
            Peanut_Connect_Updates::is_protected_plugin('Peanut-Connect-3.21.0/peanut-connect.php'),
            'connect itself in a version-suffixed folder must stay protected'
        );
    }

    public function test_guard_does_not_over_match_lookalike_slugs(): void {
        // The version-suffix normalisation must not turn a lookalike into a
        // protected slug (no over-matching regression).
        $this->assertFalse(
            Peanut_Connect_Updates::is_protected_plugin('wordfence-assistant/x.php')
        );
        $this->assertFalse(
            Peanut_Connect_Updates::is_protected_plugin('my-peanut-connect-addon/addon.php')
        );
    }
}
