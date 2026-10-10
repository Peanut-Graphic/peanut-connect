<?php
if (!defined('ABSPATH')) { exit; }

/**
 * D-12 edge key rotation, with lockout recovery.
 *
 * HUB promotes a proposed key on its FIRST authenticated use (ValidateSiteApiKey),
 * which retires the old key at that instant. So once the confirm request has
 * reached HUB, the new key may be the only key HUB accepts — whether or not the
 * plugin ever sees the reply. Discarding the new key on a timeout / dropped
 * connection / 5xx-after-commit would lock the site out of HUB permanently.
 *
 * Recovery model:
 *  1. The new key is persisted (encrypted, like the active key) as "pending"
 *     BEFORE it is proposed. Never logged, never stored in plaintext.
 *  2. A failed confirm is retried a bounded number of times with backoff,
 *     within a wall-clock budget.
 *  3. Still unconfirmed → probe a cheap authenticated read (GET popups/active)
 *     with the NEW key, then the OLD key:
 *       - new key authenticates          → adopt it;
 *       - new key rejected (401) and old
 *         key authenticates              → keep old, discard pending;
 *       - anything else (network, 5xx)   → keep pending, raise the admin
 *         notice, retry on the next heartbeat cron cycle.
 *  4. Any later 401 on the active key tries the pending key once before the
 *     connection is declared broken (heal_after_unauthorized()).
 *
 * The pending key is only discarded on PROOF that it is dead (HUB answered 401
 * for it while accepting the old key) or on unpair/re-pair. Its expiry stamp
 * mirrors HUB's 15-minute pending window but does not, by itself, discard it:
 * if HUB promoted it, it is the live key for good.
 */
class Peanut_Connect_Key_Rotation {
    /** Encrypted pending key + metadata (autoload off). */
    const PENDING_OPTION = 'peanut_connect_hub_pending_key';

    /** Timestamp set while a rotation could not be resolved; drives the admin notice. */
    const UNRESOLVED_OPTION = 'peanut_connect_key_rotation_unresolved';

    /** Atomic (add_option) mutex so rotate / cron resolve / 401 heal never interleave. */
    const LOCK_OPTION = 'peanut_connect_key_rotation_lock';

    /** Seconds after which a lock left by a crashed request is considered stale. */
    const LOCK_TTL = 300;

    /** Matches HUB's pending window (Site::proposeKeyRotation(..., 15)). */
    const PENDING_TTL = 900;

    /** Total confirm attempts (first try + retries). */
    const CONFIRM_ATTEMPTS = 3;

    /** Sleep before retry N (seconds). */
    const CONFIRM_BACKOFF = [1, 2];

    /** Wall-clock budget for one rotate() run, so admin/cron requests stay bounded. */
    const BUDGET_SECONDS = 45;

    /** Cheap authenticated read the plugin already uses (Peanut_Connect_Hub_Sync::fetch_popups). */
    const PROBE_PATH = 'api/v1/popups/active';

    const PROBE_OK       = 'ok';
    const PROBE_REJECTED = 'rejected';
    const PROBE_UNKNOWN  = 'unknown';

    /**
     * Test seam for the retry backoff. null = real sleep().
     *
     * @var callable|null
     */
    public static $sleeper = null;

    /** Generate a fresh site key. */
    public static function generate_key(): string {
        return wp_generate_password(64, false, false);
    }

    /**
     * Build Bearer + D-11 signature + D-10 protocol headers for a request signed
     * with an EXPLICIT key (not the stored one) — needed because the confirm
     * call must be signed with the NEW key before it is stored. Testable.
     *
     * @return array<string,string>
     */
    public static function signed_headers(string $key, string $method, string $url, string $body): array {
        $route = (string) (wp_parse_url($url, PHP_URL_PATH) ?: '/');
        $ts = (string) time();
        $nonce = bin2hex(random_bytes(16));
        return [
            'Authorization'      => 'Bearer ' . $key,
            'Content-Type'       => 'application/json',
            'Accept'             => 'application/json',
            'X-Peanut-Protocol'  => '1',
            'X-Peanut-Timestamp' => $ts,
            'X-Peanut-Nonce'     => $nonce,
            'X-Peanut-Signature' => Peanut_Connect_Auth::compute_request_signature($key, $method, $route, $ts, $nonce, $body),
        ];
    }

    /**
     * Two-phase rotate with lockout recovery. Returns ['success'=>bool,'message'=>string].
     * Messages never contain key material.
     */
    public static function rotate(): array {
        $hub_url = (string) get_option('peanut_connect_hub_url');
        $old_key = Peanut_Connect_Auth::get_hub_api_key();
        if ($hub_url === '' || $old_key === '') {
            return ['success' => false, 'message' => 'Not paired'];
        }
        if (!self::acquire_lock()) {
            return ['success' => false, 'message' => 'A key rotation is already in progress'];
        }

        try {
            // Never stack a second proposal on an unresolved one: the earlier
            // key may already be the one HUB holds.
            if (self::has_pending()) {
                $outcome = self::resolve_pending_locked();
                if ($outcome === 'unresolved') {
                    return ['success' => false, 'message' => 'A previous key rotation is still unresolved; it will be retried automatically'];
                }
                if ($outcome === 'adopted') {
                    // The earlier rotation just completed: the key is fresh.
                    return ['success' => true, 'message' => 'Rotated (completed the previous rotation)'];
                }
                $old_key = Peanut_Connect_Auth::get_hub_api_key();
                if ($old_key === '') {
                    return ['success' => false, 'message' => 'Not paired'];
                }
            }

            $started = microtime(true);
            $new_key = self::generate_key();

            // 1. Persist BEFORE proposing. If it cannot be stored encrypted,
            //    abort: an unstored key is exactly the lockout we are avoiding.
            if (!self::store_pending($new_key)) {
                return ['success' => false, 'message' => 'Cannot store the new key securely; rotation aborted, staying on current key'];
            }

            $propose = self::post($hub_url, 'api/v1/sites/rotate', $old_key, ['new_key_hash' => hash('sha256', $new_key)], 15);
            if (!$propose['ok']) {
                // HUB only promotes on a request made WITH the new key, and none
                // has been sent: the old key is still authoritative.
                self::clear_pending();
                return ['success' => false, 'message' => 'Propose failed: ' . $propose['message']];
            }

            // 2. Confirm, with bounded retries inside the time budget.
            for ($attempt = 1; $attempt <= self::CONFIRM_ATTEMPTS; $attempt++) {
                if ($attempt > 1) {
                    $delay = self::CONFIRM_BACKOFF[$attempt - 2] ?? self::CONFIRM_BACKOFF[count(self::CONFIRM_BACKOFF) - 1];
                    if (self::remaining($started) <= $delay + 1) {
                        break;
                    }
                    self::sleep_for($delay);
                }
                $timeout = $attempt === 1 ? 15 : 10;
                $timeout = (int) max(1, min($timeout, floor(self::remaining($started))));
                $confirm = self::post($hub_url, 'api/v1/sites/rotate/confirm', $new_key, [], $timeout);
                if ($confirm['ok']) {
                    if (self::adopt($new_key, 'confirm')) {
                        return ['success' => true, 'message' => 'Rotated'];
                    }
                    return ['success' => false, 'message' => 'Rotation confirmed but the new key could not be stored; it is kept pending and will be retried'];
                }
            }

            // 3. Confirm unacknowledged: find out which key HUB now accepts.
            $outcome = self::resolve_pending_locked();
            if ($outcome === 'adopted') {
                return ['success' => true, 'message' => 'Rotated (confirmed by probe after the confirm reply was lost)'];
            }
            if ($outcome === 'kept_old') {
                return ['success' => false, 'message' => 'Confirm failed; HUB still accepts the current key, staying on it'];
            }
            return ['success' => false, 'message' => 'Rotation could not be confirmed; the new key is kept pending and will be retried automatically'];
        } finally {
            self::release_lock();
        }
    }

    /** True when a rotation's new key is stored and not yet resolved. */
    public static function has_pending(): bool {
        $raw = get_option(self::PENDING_OPTION, null);
        return is_array($raw) && !empty($raw['ciphertext']);
    }

    /**
     * Cron entry point (heartbeat): resolve an outstanding rotation if any.
     *
     * @return string none | locked | adopted | kept_old | unresolved
     */
    public static function resolve_pending(): string {
        if (!self::has_pending()) {
            return 'none';
        }
        if (!self::acquire_lock()) {
            return 'locked';
        }
        try {
            return self::resolve_pending_locked();
        } finally {
            self::release_lock();
        }
    }

    /**
     * Self-heal after a 401 on the active key: try the pending key once.
     * Returns true when the pending key authenticated and is now the active key.
     */
    public static function heal_after_unauthorized(): bool {
        if (!self::has_pending()) {
            return false;
        }
        $hub_url = (string) get_option('peanut_connect_hub_url');
        if ($hub_url === '' || !self::acquire_lock()) {
            return false;
        }
        try {
            $pending = self::get_pending_key();
            if ($pending === null) {
                return false;
            }
            if (self::probe($hub_url, $pending) === self::PROBE_OK) {
                return self::adopt($pending, 'heal_401');
            }
            return false;
        } finally {
            self::release_lock();
        }
    }

    /** Drop any pending key and the unresolved flag (unpair / re-pair). */
    public static function clear_pending(): void {
        delete_option(self::PENDING_OPTION);
        delete_option(self::UNRESOLVED_OPTION);
    }

    /**
     * admin_notices callback: warn that a rotation is awaiting confirmation.
     */
    public static function render_unresolved_notice(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (!get_option(self::UNRESOLVED_OPTION) || !self::has_pending()) {
            return;
        }
        $url = admin_url('admin.php?page=peanut-connect-app');
        printf(
            '<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
            esc_html__('Peanut End to End: a Hub key rotation could not be confirmed because Hub was unreachable. The new key is kept safely and the plugin will retry automatically on the next sync. If this notice persists for more than a day, re-pair with Hub.', 'peanut-connect'),
            esc_url($url),
            esc_html__('Open Peanut End to End', 'peanut-connect')
        );
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Decide which key HUB accepts. Caller holds the lock.
     *
     * @return string none | adopted | kept_old | unresolved
     */
    private static function resolve_pending_locked(): string {
        $hub_url = (string) get_option('peanut_connect_hub_url');
        if ($hub_url === '' || !self::has_pending()) {
            return 'none';
        }
        $pending = self::get_pending_key();
        if ($pending === null) {
            // Undecryptable (WP salts changed): the active key is unreadable
            // too and the existing re-pair notice already covers it.
            self::clear_pending();
            self::log('hub_key_rotation_pending_unreadable', 'error', 'Pending Hub key could not be decrypted; re-pair required');
            return 'none';
        }

        // Probe the NEW key first. While HUB's pending window is open this
        // probe itself completes the promotion, which is the desired outcome.
        $new = self::probe($hub_url, $pending);
        if ($new === self::PROBE_OK) {
            return self::adopt($pending, 'probe') ? 'adopted' : 'unresolved';
        }

        $old_key = Peanut_Connect_Auth::get_hub_api_key();
        $old = $old_key !== '' ? self::probe($hub_url, $old_key) : self::PROBE_UNKNOWN;

        // Discard the new key only on proof it is dead. A network failure on
        // the new-key probe may itself have promoted it at HUB.
        if ($new === self::PROBE_REJECTED && $old === self::PROBE_OK) {
            self::clear_pending();
            self::log('hub_key_rotation_abandoned', 'warning', 'Hub key rotation was not committed by Hub; staying on the current key');
            return 'kept_old';
        }

        $first = !get_option(self::UNRESOLVED_OPTION);
        update_option(self::UNRESOLVED_OPTION, time(), false);
        if ($first) {
            self::log('hub_key_rotation_unresolved', 'warning', 'Hub key rotation could not be confirmed; new key kept pending, will retry on next sync', [
                'new_key_probe' => $new,
                'old_key_probe' => $old,
            ]);
        }
        return 'unresolved';
    }

    /** Store $key as the active key and clear rotation state. */
    private static function adopt(string $key, string $via): bool {
        if (!Peanut_Connect_Auth::set_hub_api_key($key)) {
            // Encryption unavailable: leave the pending key in place rather than lose it.
            update_option(self::UNRESOLVED_OPTION, time(), false);
            return false;
        }
        self::clear_pending();
        update_option('peanut_connect_auth_fail_count', 0);
        self::log('hub_key_rotated', 'success', 'Hub key rotated', ['via' => $via]);
        return true;
    }

    private static function store_pending(string $key): bool {
        $ciphertext = Peanut_Connect_Secret::encrypt($key);
        if ($ciphertext === null) {
            return false;
        }
        $now = time();
        update_option(self::PENDING_OPTION, [
            'ciphertext' => $ciphertext,
            'created_at' => $now,
            'expires_at' => $now + self::PENDING_TTL, // HUB's window; informational.
        ], false);
        delete_option(self::UNRESOLVED_OPTION);
        return self::has_pending();
    }

    private static function get_pending_key(): ?string {
        $raw = get_option(self::PENDING_OPTION, null);
        if (!is_array($raw) || empty($raw['ciphertext'])) {
            return null;
        }
        $plain = Peanut_Connect_Secret::decrypt((string) $raw['ciphertext']);
        return ($plain === null || $plain === '') ? null : $plain;
    }

    /**
     * Authenticated read with an explicit key.
     *
     * @return string PROBE_OK (2xx) | PROBE_REJECTED (401) | PROBE_UNKNOWN (anything else)
     */
    private static function probe(string $hub_url, string $key): string {
        $url  = trailingslashit($hub_url) . self::PROBE_PATH;
        $resp = wp_remote_get($url, [
            'headers'   => self::signed_headers($key, 'GET', $url, ''),
            'timeout'   => 10,
            'sslverify' => true,
        ]);
        if (is_wp_error($resp)) {
            return self::PROBE_UNKNOWN;
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        if ($code >= 200 && $code < 300) {
            return self::PROBE_OK;
        }
        if ($code === 401) {
            return self::PROBE_REJECTED;
        }
        return self::PROBE_UNKNOWN;
    }

    /** POST JSON to Hub signed with $key. @return array{ok:bool,message:string} */
    private static function post(string $hub_url, string $path, string $key, array $payload, int $timeout): array {
        $url  = trailingslashit($hub_url) . $path;
        $body = wp_json_encode($payload);
        $resp = wp_remote_post($url, ['headers' => self::signed_headers($key, 'POST', $url, $body), 'body' => $body, 'timeout' => $timeout]);
        if (is_wp_error($resp)) {
            return ['ok' => false, 'message' => $resp->get_error_message()];
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        return ['ok' => $code >= 200 && $code < 300, 'message' => "HTTP $code"];
    }

    private static function acquire_lock(): bool {
        if (add_option(self::LOCK_OPTION, (string) (time() + self::LOCK_TTL), '', 'no')) {
            return true;
        }
        $until = (int) get_option(self::LOCK_OPTION, 0);
        if ($until > 0 && $until < time()) {
            // Stale lock from a request that died mid-rotation.
            delete_option(self::LOCK_OPTION);
            return (bool) add_option(self::LOCK_OPTION, (string) (time() + self::LOCK_TTL), '', 'no');
        }
        return false;
    }

    private static function release_lock(): void {
        delete_option(self::LOCK_OPTION);
    }

    private static function remaining(float $started): float {
        return self::BUDGET_SECONDS - (microtime(true) - $started);
    }

    private static function sleep_for(int $seconds): void {
        if (is_callable(self::$sleeper)) {
            (self::$sleeper)($seconds);
            return;
        }
        sleep($seconds);
    }

    /** Activity-log wrapper. Callers must never pass key material. */
    private static function log(string $type, string $status, string $message, array $meta = []): void {
        if (class_exists('Peanut_Connect_Activity_Log')) {
            Peanut_Connect_Activity_Log::log($type, $status, $message, $meta);
        }
    }
}
