<?php
/**
 * Tests for the remote-deactivation/deletion protection allowlist. The Hub must
 * not be able to remotely tear down the site's security perimeter (or sever its
 * own link), and the check must be exact set-membership on the folder slug —
 * not a substring search that both over- and under-matches.
 *
 * @package Peanut_Connect
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/class-connect-updates.php';

class Test_Protected_Plugins extends TestCase {

    public function test_security_plugins_are_protected(): void {
        foreach ([
            'wordfence/wordfence.php',
            'better-wp-security/better-wp-security.php',
            'sucuri-scanner/sucuri.php',
            'peanut-connect/peanut-connect.php',
            'Wordfence/wordfence.php', // case-insensitive slug
        ] as $file) {
            $this->assertTrue(Peanut_Connect_Updates::is_protected_plugin($file), $file);
        }
    }

    public function test_ordinary_plugins_are_not_protected(): void {
        foreach ([
            'akismet/akismet.php',
            'woocommerce/woocommerce.php',
            'hello.php',
        ] as $file) {
            $this->assertFalse(Peanut_Connect_Updates::is_protected_plugin($file), $file);
        }
    }

    public function test_substring_tricks_do_not_over_or_under_match(): void {
        // Old strpos('peanut-connect') guard would have wrongly blocked a
        // third-party plugin whose folder merely *contains* the string...
        $this->assertFalse(
            Peanut_Connect_Updates::is_protected_plugin('my-peanut-connect-addon/addon.php'),
            'substring should not falsely protect an unrelated plugin'
        );
        // ...and a slug that only resembles a protected one is not protected.
        $this->assertFalse(
            Peanut_Connect_Updates::is_protected_plugin('wordfence-assistant/x.php'),
            'wordfence-assistant is a different slug'
        );
    }
}
