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
            'progress_at' => null,
            'files_added' => 0,
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
        $job['progress_at'] = time();
        self::put($job);

        // WP-Cron runs over HTTP, so without these the build inherits the web
        // SAPI's execution limit and is killed mid-zip — and a killed request
        // never reaches the failure path below, which is how a job stayed
        // `running` forever. Neither call is guaranteed on shared hosting
        // (disable_functions, hard server-side request timeouts), which is
        // exactly why the heartbeat below has to exist as well.
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        @ignore_user_abort(true);

        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-backup.php';

        $job_id_for_progress = $job['job_id'];

        $result = Peanut_Connect_Backup::create_backup([
            'type' => $job['type'],
            'storage_driver' => $job['storage_driver'],
            'progress' => static function (int $files_added) use ($job_id_for_progress): void {
                self::heartbeat($job_id_for_progress, $files_added);
            },
        ]);

        // Re-read: the heartbeats above have been writing to this record for
        // the whole build, so the local copy is stale and writing it back
        // would erase the progress trail the failure path may need.
        $job = self::get($job['job_id']) ?? $job;
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
        // create_backup() returns `size_bytes`; reading `size` here meant the
        // job record reported a null size for every backup ever built.
        $job['size'] = isset($result['size_bytes']) ? (int) $result['size_bytes'] : null;
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
                // Reaped on READ, not only when a new backup is requested.
                // This is the only record Hub ever sees; leaving a dead
                // worker reported as `running` here is what made Hub poll
                // to its own ceiling and then guess at the reason.
                return self::reap($job);
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

        return $jobs === [] ? null : self::reap($jobs[0]);
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

            $job = self::reap($job);

            if (($job['status'] ?? '') === 'running') {
                return $job;
            }
        }

        return null;
    }

    /**
     * Apply the vanished-worker rule to one record.
     *
     * Staleness is measured from the last HEARTBEAT, not from the start. A
     * large site legitimately zips for far longer than STALE_AFTER, and
     * timing a healthy build out is not a smaller mistake than trusting a
     * dead one — Hub recorded five healthy builds as failures on 2026-08-23
     * doing exactly that with a start-relative ceiling. Silence is the only
     * evidence that the worker is gone.
     *
     * Returns the record unchanged unless it is a running job that has gone
     * quiet, in which case the failure is persisted and returned.
     *
     * @param array $job Job record.
     * @return array The record as it should now be reported.
     */
    private static function reap(array $job): array {
        if (($job['status'] ?? '') !== 'running') {
            return $job;
        }

        // Fall back to started_at for records written before heartbeats
        // existed, so an in-flight upgrade cannot wedge a site.
        $lastSign = (int) ($job['progress_at'] ?? 0) ?: (int) ($job['started_at'] ?? 0);

        if ($lastSign > 0 && (time() - $lastSign) < self::STALE_AFTER) {
            return $job;
        }

        $job['status'] = 'failed';
        $job['finished_at'] = time();
        $job['error_code'] = 'backup_worker_vanished';
        $job['error_message'] = __('The backup process stopped without reporting a result.', 'peanut-connect');
        self::put($job);

        return $job;
    }

    /**
     * Record that the build is still alive, and how far it has got.
     *
     * Called from the archive builder often enough that a healthy build never
     * looks silent, and cheaply enough that it does not become the cost.
     *
     * @param string $job_id      Job identifier.
     * @param int    $files_added Files written to the archive so far.
     * @return void
     */
    public static function heartbeat(string $job_id, int $files_added = 0): void {
        foreach (self::all() as $job) {
            if (($job['job_id'] ?? null) !== $job_id) {
                continue;
            }

            $job['progress_at'] = time();
            $job['files_added'] = $files_added;
            self::put($job);

            return;
        }
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
