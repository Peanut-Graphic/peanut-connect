<?php
/**
 * Tests for backup-restore integrity verification (the /restore RCE gate).
 *
 * restore_backup() executes the archive's SQL and copies its files over
 * wp-content, so it must only ever restore an archive THIS site created.
 * These tests pin the allowlist predicate that enforces that.
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

class Test_Backup_Integrity extends TestCase {

    protected function setUp(): void {
        // Reset the mock option store between tests.
        global $mock_options;
        $mock_options = [];
    }

    /** Record a backup's hash the way create_backup() does. */
    private function record(string $file): void {
        $m = new ReflectionMethod(Peanut_Connect_Backup::class, 'register_known_backup');
        $m->invoke(null, hash_file('sha256', $file), basename($file));
    }

    public function test_unrecorded_archive_is_rejected(): void {
        // The core RCE gate: an archive we never created must NOT verify.
        $evil = tempnam(sys_get_temp_dir(), 'pc-evil');
        file_put_contents($evil, "PK\x03\x04 malicious backup with a webshell");
        $this->assertFalse(
            Peanut_Connect_Backup::is_known_backup($evil),
            'An archive this site never created must be rejected.'
        );
        unlink($evil);
    }

    public function test_recorded_archive_verifies(): void {
        $good = tempnam(sys_get_temp_dir(), 'pc-good');
        file_put_contents($good, "PK\x03\x04 legitimate site backup bytes");
        $this->record($good);
        $this->assertTrue(
            Peanut_Connect_Backup::is_known_backup($good),
            'A backup this site created and recorded must verify.'
        );
        unlink($good);
    }

    public function test_tampering_after_record_breaks_verification(): void {
        $f = tempnam(sys_get_temp_dir(), 'pc-tamper');
        file_put_contents($f, 'original backup bytes');
        $this->record($f);
        $this->assertTrue(Peanut_Connect_Backup::is_known_backup($f));

        // A single flipped byte must invalidate it (hash mismatch).
        file_put_contents($f, 'original backup bytes + injected payload');
        $this->assertFalse(
            Peanut_Connect_Backup::is_known_backup($f),
            'Any modification to a recorded backup must fail verification.'
        );
        unlink($f);
    }

    public function test_missing_file_is_not_known(): void {
        $this->assertFalse(Peanut_Connect_Backup::is_known_backup('/no/such/backup.zip'));
        $this->assertFalse(Peanut_Connect_Backup::is_known_backup(''));
    }

    public function test_empty_allowlist_rejects_everything(): void {
        $f = tempnam(sys_get_temp_dir(), 'pc-none');
        file_put_contents($f, 'bytes');
        // No backups recorded at all -> nothing can be restored.
        $this->assertFalse(Peanut_Connect_Backup::is_known_backup($f));
        unlink($f);
    }
}
