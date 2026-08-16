<?php
/**
 * Asynchronous site-backup jobs.
 *
 * Why this exists
 * ---------------
 * `POST /backup` used to build the archive inside the request: zip the whole
 * database and wp-content, then answer. On anything larger than a toy site that
 * outlives the caller's socket. Hub's two recorded backup attempts both died
 * exactly there — cURL 28 after 120s — and because the work was abandoned
 * mid-flight there was nothing to show for them but a failed row.
 *
 * A backup that only succeeds on sites small enough to finish in two minutes is
 * not a backup product. So the request no longer does the work: it records a
 * job, hands back a job id, and returns. The archive is built on a background
 * request, and Hub polls for the outcome.
 *
 * Deliberate choices
 * ------------------
 * - WP-Cron, not Action Scheduler. Connect ships to hosts we do not control and
 *   must not require another plugin. spawn_cron() is called on queue so the work
 *   starts immediately rather than waiting for the next visitor.
 * - One backup at a time. A second request while one is running returns the
 *   running job rather than starting a competing zip of the same disk.
 * - Bounded history. Unbounded growth in an option is how sites get slow.
 * - No payload in the job record: status, timing and the archive name only.
 *
 * @since 3.37.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Peanut_Connect_Backup_Job {

    /** Option holding recent job records. Not autoloaded. */
    const OPTION = 'peanut_connect_backup_jobs';

    /** Cron hook that performs the queued work. */
    const HOOK = 'peanut_connect_run_backup_job';

    /** How many job records to retain. */
    const RETAIN = 10;

    /**
     * A job stuck in `running` for longer than this is treated as dead.
     *
     * Fatal errors, OOM kills and host request limits all end the worker
     * without ever reaching the failure path, so without this a single killed
     * run would block every future backup forever.
     */
    const STALE_AFTER = 1800;

    /**
     * Register the background handler.
     *
     * @return void
     */
    public static function boot(): void {
        add_action(self::HOOK, [self::class, 'run'], 10, 1);
    }

    /**
     * Queue a backup and return its job record.
     *
     * @param array $params Parameters forwarded to the backup builder.
     * @return array The job record, including `job_id` and `status`.
     */
    public static function queue(array $params = []): array {
        $running = self::running_job();
        if ($running !== null) {
            return $running;
        }

        $job = [
            'job_id' => wp_generate_uuid4(),
            'status' => 'queued',
            'type' => isset($params['type']) ? (string) $params['type'] : 'full',
            'storage_driver' => isset($params['storage_driver']) ? (string) $params['storage_driver'] : 'local',
            'queued_at' => time(),
            'started_at' => null,
            'finished_at' => null,
            'backup_name' => null,
            'size' => null,
            'error_code' => null,
            'error_message' => null,
        ];

        self::put($job);

        wp_schedule_single_event(time(), self::HOOK, [$job['job_id']]);

        // WP-Cron only fires on a request. Without this the job waits for the
        // next visitor, which on a quiet client site can be hours.
        if (function_exists('spawn_cron')) {
            spawn_cron();
        }

        return $job;
    }

    /**
     * Build the archive for a queued job.
     *
     * @param string $job_id Job identifier.
     * @return void
     */
    public static function run(string $job_id): void {
        $job = self::get($job_id);
        if ($job === null || $job['status'] !== 'queued') {
            return;
        }

        $job['status'] = 'running';
        $job['started_at'] = time();
        self::put($job);

        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-backup.php';

        $result = Peanut_Connect_Backup::create_backup([
            'type' => $job['type'],
            'storage_driver' => $job['storage_driver'],
        ]);

        $job['finished_at'] = time();

        if (is_wp_error($result)) {
            $job['status'] = 'failed';
            $job['error_code'] = (string) $result->get_error_code();
            $job['error_message'] = (string) $result->get_error_message();
            self::put($job);

            Peanut_Connect_Activity_Log::log(
                'backup_created',
                'error',
                __('Backup failed', 'peanut-connect'),
                ['job_id' => $job['job_id'], 'code' => $job['error_code']]
            );

            return;
        }

        $job['status'] = 'complete';
        $job['backup_name'] = isset($result['backup_name']) ? (string) $result['backup_name'] : null;
        $job['size'] = isset($result['size']) ? $result['size'] : null;
        self::put($job);

        Peanut_Connect_Activity_Log::log(
            'backup_created',
            'success',
            __('Backup created', 'peanut-connect'),
            is_array($result) ? $result : []
        );
    }

    /**
     * Fetch a job record.
     *
     * @param string $job_id Job identifier.
     * @return array|null The record, or null when unknown.
     */
    public static function get(string $job_id): ?array {
        foreach (self::all() as $job) {
            if (isset($job['job_id']) && $job['job_id'] === $job_id) {
                return $job;
            }
        }

        return null;
    }

    /**
     * The most recent job record, if any.
     *
     * @return array|null
     */
    public static function latest(): ?array {
        $jobs = self::all();

        return $jobs === [] ? null : $jobs[0];
    }

    /**
     * All retained job records, newest first.
     *
     * @return array<int, array>
     */
    public static function all(): array {
        $jobs = get_option(self::OPTION, []);

        return is_array($jobs) ? array_values($jobs) : [];
    }

    /**
     * The job currently occupying the site, if one is genuinely alive.
     *
     * A `running` record older than STALE_AFTER is reported as failed rather
     * than believed: the worker that owned it is gone and will never write a
     * result, so continuing to trust it would wedge backups permanently.
     *
     * @return array|null
     */
    public static function running_job(): ?array {
        foreach (self::all() as $job) {
            $status = $job['status'] ?? '';

            if ($status === 'queued') {
                return $job;
            }

            if ($status !== 'running') {
                continue;
            }

            $started = (int) ($job['started_at'] ?? 0);
            if ($started > 0 && (time() - $started) < self::STALE_AFTER) {
                return $job;
            }

            $job['status'] = 'failed';
            $job['finished_at'] = time();
            $job['error_code'] = 'backup_worker_vanished';
            $job['error_message'] = __('The backup process stopped without reporting a result.', 'peanut-connect');
            self::put($job);
        }

        return null;
    }

    /**
     * Insert or replace a job record, newest first, bounded.
     *
     * @param array $job Job record.
     * @return void
     */
    private static function put(array $job): void {
        $jobs = self::all();
        $out = [$job];

        foreach ($jobs as $existing) {
            if (($existing['job_id'] ?? null) === $job['job_id']) {
                continue;
            }
            $out[] = $existing;
        }

        update_option(self::OPTION, array_slice($out, 0, self::RETAIN), false);
    }
}
