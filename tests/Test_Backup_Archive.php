<?php
/**
 * Tests for the backup archive endpoint's name resolution.
 *
 * The GET route serves the site's entire database and wp-content, so what it
 * will and will not resolve IS the security boundary. These tests pin it.
 *
 * @package Peanut_Connect
 */

use PHPUnit\Framework\TestCase;

if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p($dir) {
        return is_dir($dir) || mkdir($dir, 0777, true);
    }
}

require_once dirname(__DIR__) . '/includes/class-connect-backup.php';

class Test_Backup_Archive extends TestCase {

    private string $dir;

    protected function setUp(): void {
        global $mock_options;
        $mock_options = [];

        $this->dir = WP_CONTENT_DIR . '/peanut-backups';
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0777, true);
        }
    }

    protected function tearDown(): void {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }

    /** Create an archive on disk and register it the way create_backup() does. */
    private function makeArchive(string $name, string $body = 'PK archive bytes'): string {
        $path = $this->dir . '/' . $name . '.zip';
        file_put_contents($path, $body);

        // PHP 8.0 requires setAccessible(); it is a no-op on newer runtimes.
        $method = new ReflectionMethod(Peanut_Connect_Backup::class, 'register_known_backup');
        $method->setAccessible(true);
        $method->invoke(null, hash_file('sha256', $path), $name);

        return $path;
    }

    public function test_a_registered_archive_resolves(): void {
        $path = $this->makeArchive('acme-2026-08-09-120000-tok');

        $resolved = Peanut_Connect_Backup::resolve_known_archive('acme-2026-08-09-120000-tok');

        $this->assertFalse(is_wp_error($resolved));
        $this->assertSame(realpath($path), $resolved);
    }

    public function test_an_unregistered_name_is_refused(): void {
        // Even when the file EXISTS. Presence on disk is not authorisation —
        // the registry is written only by this site's own create_backup runs,
        // so anything absent from it is not ours to serve.
        $path = $this->dir . '/planted-by-someone-else.zip';
        file_put_contents($path, 'PK not ours');

        $result = Peanut_Connect_Backup::resolve_known_archive('planted-by-someone-else');

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('backup_not_found', $result->get_error_code());
    }

    public function test_a_traversal_attempt_is_refused(): void {
        // Registry validation blocks this before path handling ever runs, and
        // the realpath containment check blocks it again if a future bug ever
        // let a hostile name into the registry.
        foreach (['../../wp-config', '../../../etc/passwd', 'a/../../../wp-config'] as $evil) {
            $result = Peanut_Connect_Backup::resolve_known_archive($evil);
            $this->assertTrue(is_wp_error($result), "Traversal '{$evil}' must be refused.");
            $this->assertSame('backup_not_found', $result->get_error_code());
        }
    }

    public function test_an_empty_name_is_refused(): void {
        $result = Peanut_Connect_Backup::resolve_known_archive('');

        $this->assertTrue(is_wp_error($result));
    }

    public function test_a_registered_name_whose_file_is_gone_is_refused(): void {
        $path = $this->makeArchive('deleted-since-registration');
        unlink($path);

        $result = Peanut_Connect_Backup::resolve_known_archive('deleted-since-registration');

        $this->assertTrue(is_wp_error($result));
    }

    public function test_an_unknown_name_is_indistinguishable_from_a_missing_file(): void {
        // The endpoint must not be an oracle for which archives exist: both
        // cases return the same code, so a caller learns nothing by probing.
        $this->makeArchive('real-one');
        unlink($this->dir . '/real-one.zip');

        $missing = Peanut_Connect_Backup::resolve_known_archive('real-one');
        $unknown = Peanut_Connect_Backup::resolve_known_archive('never-existed');

        $this->assertSame($missing->get_error_code(), $unknown->get_error_code());
    }

    public function test_delete_removes_a_registered_archive(): void {
        $path = $this->makeArchive('to-be-removed');

        $result = Peanut_Connect_Backup::delete_known_archive('to-be-removed');

        $this->assertTrue($result);
        $this->assertFileDoesNotExist($path);
    }

    public function test_delete_refuses_an_unregistered_name_and_leaves_the_file(): void {
        // Otherwise the delete route is an arbitrary-file-unlink primitive for
        // anyone holding a Hub credential.
        $path = $this->dir . '/not-ours.zip';
        file_put_contents($path, 'PK not ours');

        $result = Peanut_Connect_Backup::delete_known_archive('not-ours');

        $this->assertTrue(is_wp_error($result));
        $this->assertFileExists($path);
    }
}
