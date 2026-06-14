<?php
if (!defined('ABSPATH')) { exit; }

class Peanut_Connect_Key_Rotation {
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
     * Two-phase rotate. Returns ['success'=>bool,'message'=>string]. The stored
     * key is swapped ONLY after the confirm call with the new key succeeds, so a
     * failure anywhere leaves the site on the old key (no lockout).
     */
    public static function rotate(): array {
        $hub_url = get_option('peanut_connect_hub_url');
        $old_key = Peanut_Connect_Auth::get_hub_api_key();
        if (empty($hub_url) || $old_key === '') {
            return ['success' => false, 'message' => 'Not paired'];
        }
        $new_key  = self::generate_key();
        $new_hash = hash('sha256', $new_key);

        $propose = self::post($hub_url, 'api/v1/sites/rotate', $old_key, ['new_key_hash' => $new_hash]);
        if (! $propose['ok']) {
            return ['success' => false, 'message' => 'Propose failed: ' . $propose['message']];
        }
        $confirm = self::post($hub_url, 'api/v1/sites/rotate/confirm', $new_key, []);
        if (! $confirm['ok']) {
            return ['success' => false, 'message' => 'Confirm failed; staying on current key'];
        }
        Peanut_Connect_Auth::set_hub_api_key($new_key);
        if (class_exists('Peanut_Connect_Activity_Log')) {
            Peanut_Connect_Activity_Log::log('hub_key_rotated', 'success', __('Hub key rotated', 'peanut-connect'), []);
        }
        return ['success' => true, 'message' => 'Rotated'];
    }

    /** POST JSON to Hub signed with $key. @return array{ok:bool,message:string} */
    private static function post(string $hub_url, string $path, string $key, array $payload): array {
        $url  = trailingslashit($hub_url) . $path;
        $body = wp_json_encode($payload);
        $resp = wp_remote_post($url, ['headers' => self::signed_headers($key, 'POST', $url, $body), 'body' => $body, 'timeout' => 15]);
        if (is_wp_error($resp)) { return ['ok' => false, 'message' => $resp->get_error_message()]; }
        $code = (int) wp_remote_retrieve_response_code($resp);
        return ['ok' => $code >= 200 && $code < 300, 'message' => "HTTP $code"];
    }
}
