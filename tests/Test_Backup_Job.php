<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}
if (!defined('PEANUT_CONNECT_PLUGIN_DIR')) {
    define('PEANUT_CONNECT_PLUGIN_DIR', dirname(__DIR__) . '/');
}

// The archive builder is deliberately NOT loaded. If queue() ever calls it,
// the test fails with a missing class — which is the assertion we want.
require_once dirname(__DIR__) . '/includes/class-connect-backup-job.php';

/**
 * The backup job runner.
 *
 * The behaviour under test is the reason this class exists: POST /backup must
 * not build the archive inside the request. Hub's only two real backup attempts
 * both died at cURL 28 after 120s doing exactly that.
 */
final class Test_Backup_Job extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['mock_options'] = [];
        $GLOBALS['mock_scheduled_events'] = [];
        $GLOBALS['mock_spawned_cron'] = 0;
    }

    public function test_queue_records_a_job_without_building_the_archive(): void {
        $job = Peanut_Connect_Backup_Job::queue(['type' => 'full']);

        $this->assertSame('queued', $job['status']);
        $this->assertNotEmpty($job['job_id']);
        // The whole point: queueing must not touch the archive builder. This is
        // enforced structurally rather than by a counter — class-connect-backup.php
        // is deliberately never required by this test file, so any inline build
        // fatals on a missing class. Verified by reintroducing the inline call:
        // 6 of these 7 tests fail.
    }

    public function test_queue_schedules_the_worker_and_spawns_cron(): void {
        $job = Peanut_Connect_Backup_Job::queue();

        $this->assertCount(1, $GLOBALS['mock_scheduled_events']);
        $this->assertSame(Peanut_Connect_Backup_Job::HOOK, $GLOBALS['mock_scheduled_events'][0]['hook']);
        $this->assertSame([$job['job_id']], $GLOBALS['mock_scheduled_events'][0]['args']);
        // Without spawn_cron a quiet client site waits hours for a visitor.
        $this->assertSame(1, $GLOBALS['mock_spawned_cron']);
    }

    public function test_a_second_request_returns_the_running_job_rather_than_competing(): void {
        $first = Peanut_Connect_Backup_Job::queue();
        $second = Peanut_Connect_Backup_Job::queue();

        $this->assertSame($first['job_id'], $second['job_id']);
        $this->assertCount(1, $GLOBALS['mock_scheduled_events'], 'A competing zip of the same disk was scheduled.');
    }

    public function test_a_vanished_worker_does_not_wedge_backups_forever(): void {
        $job = Peanut_Connect_Backup_Job::queue();

        // Simulate a worker killed mid-run: status stuck at running, long ago.
        $stored = get_option(Peanut_Connect_Backup_Job::OPTION, []);
        $stored[0]['status'] = 'running';
        $stored[0]['started_at'] = time() - (Peanut_Connect_Backup_Job::STALE_AFTER + 60);
        update_option(Peanut_Connect_Backup_Job::OPTION, $stored, false);

        $next = Peanut_Connect_Backup_Job::queue();

        $this->assertNotSame($job['job_id'], $next['job_id'], 'A dead worker permanently blocked new backups.');
        $this->assertSame('failed', Peanut_Connect_Backup_Job::get($job['job_id'])['status']);
        $this->assertSame('backup_worker_vanished', Peanut_Connect_Backup_Job::get($job['job_id'])['error_code']);
    }

    public function test_a_live_worker_is_still_respected(): void {
        $job = Peanut_Connect_Backup_Job::queue();

        $stored = get_option(Peanut_Connect_Backup_Job::OPTION, []);
        $stored[0]['status'] = 'running';
        $stored[0]['started_at'] = time() - 5;
        update_option(Peanut_Connect_Backup_Job::OPTION, $stored, false);

        $this->assertSame($job['job_id'], Peanut_Connect_Backup_Job::queue()['job_id']);
    }

    public function test_history_is_bounded(): void {
        for ($i = 0; $i < Peanut_Connect_Backup_Job::RETAIN + 5; $i++) {
            $job = Peanut_Connect_Backup_Job::queue();
            $stored = get_option(Peanut_Connect_Backup_Job::OPTION, []);
            $stored[0]['status'] = 'complete';
            update_option(Peanut_Connect_Backup_Job::OPTION, $stored, false);
        }

        $this->assertCount(Peanut_Connect_Backup_Job::RETAIN, Peanut_Connect_Backup_Job::all());
    }

    public function test_unknown_job_is_null_rather_than_an_invented_record(): void {
        $this->assertNull(Peanut_Connect_Backup_Job::get('no-such-job'));
    }
}
