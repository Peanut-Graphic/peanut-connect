<?php
/**
 * Tests for the self-updater supply-chain guards: package-host pinning and
 * version sanitisation. A poisoned or spoofed update-server response must not
 * be able to point WordPress's installer at an attacker-controlled host, and a
 * version string we can't parse must not be acted on.
 *
 * @package Peanut_Connect
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/class-connect-self-updater.php';

class Test_Self_Updater_Trust extends TestCase {

    private function call_private_static(string $method, ...$args) {
        $ref = new ReflectionMethod(Peanut_Connect_Self_Updater::class, $method);
        $ref->setAccessible(true);
        return $ref->invoke(null, ...$args);
    }

    public function test_trusted_package_hosts_pass(): void {
        foreach ([
            'https://www.peanutgraphic.com/downloads/peanut-connect.zip',
            'https://peanutgraphic.com/x.zip',
            'https://github.com/Peanut-Graphic/peanut-connect/releases/download/v1/x.zip',
            'https://codeload.github.com/x/y/zip/refs/tags/v1',
            'https://objects.githubusercontent.com/abc/x.zip',
        ] as $url) {
            $this->assertTrue($this->call_private_static('is_trusted_package_url', $url), $url);
        }
    }

    public function test_untrusted_or_insecure_package_hosts_fail(): void {
        foreach ([
            'https://evil.test/shell.zip',                         // wrong host
            'http://www.peanutgraphic.com/x.zip',                  // not https
            'https://peanutgraphic.com.evil.test/x.zip',           // suffix trick
            'https://evilpeanutgraphic.com/x.zip',                 // prefix trick
            'ftp://www.peanutgraphic.com/x.zip',                   // wrong scheme
            'not-a-url',
            '',
        ] as $url) {
            $this->assertFalse($this->call_private_static('is_trusted_package_url', $url), $url);
        }
    }

    public function test_version_sanitisation_accepts_dotted_numeric(): void {
        $this->assertSame('3.11.5', $this->call_private_static('sanitize_version', '3.11.5'));
        $this->assertSame('1', $this->call_private_static('sanitize_version', '1'));
        $this->assertSame('3.11.5', $this->call_private_static('sanitize_version', '  3.11.5  '));
    }

    public function test_version_sanitisation_rejects_junk(): void {
        foreach (['9999<script>', '1.0.0-beta; rm -rf', 'latest', '', '..', '1.2.3.4.5'] as $bad) {
            $this->assertSame('', $this->call_private_static('sanitize_version', $bad), $bad);
        }
    }
}
