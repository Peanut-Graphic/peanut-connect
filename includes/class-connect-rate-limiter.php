<?php
/**
 * Peanut Connect Rate Limiter
 *
 * Implements rate limiting for API endpoints to prevent abuse.
 * Uses WordPress transients for storage, making it work across
 * different hosting environments without external dependencies.
 *
 * @package Peanut_Connect
 * @since 2.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Peanut_Connect_Rate_Limiter {

    /**
     * Default rate limit: requests per window
     */
    private const DEFAULT_LIMIT = 60;

    /**
     * Default window size in seconds (1 minute)
     */
    private const DEFAULT_WINDOW = 60;

    /**
     * Stricter limit for authentication endpoints
     */
    private const AUTH_LIMIT = 10;

    /**
     * Window for auth endpoints (1 minute)
     */
    private const AUTH_WINDOW = 60;

    /**
     * Check if request is rate limited
     *
     * @param string $identifier Unique identifier (IP address or API key hash)
     * @param string $endpoint   The endpoint being accessed
     * @return bool|WP_Error True if allowed, WP_Error if rate limited
     */
    public static function check(string $identifier, string $endpoint = 'default'): bool|WP_Error {
        $config = self::get_endpoint_config($endpoint);
        $key = self::get_cache_key($identifier, $endpoint);

        // Atomic fast-path: with an external object cache (Redis/Memcached),
        // wp_cache_incr is atomic, closing the read-then-write (TOCTOU) race
        // where parallel requests all read the same count and slip past the
        // limit. This is the only backstop on the unauthenticated write surface,
        // so the race matters. Falls back to the transient path below only when
        // no external cache is present — never weaker than before.
        if (
            function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()
            && function_exists('wp_cache_incr') && function_exists('wp_cache_add')
        ) {
            $group = 'peanut_connect_ratelimit';
            // Seed the counter atomically (no-op if the key already exists), with
            // the window as TTL — a clean fixed window: when the key expires the
            // next request re-seeds it.
            wp_cache_add($key, 0, $group, $config['window']);
            $count = wp_cache_incr($key, 1, $group);
            if ($count === false) {
                // Key expired between add and incr — re-seed and count this one.
                wp_cache_add($key, 1, $group, $config['window']);
                $count = 1;
            }
            if ($count > $config['limit']) {
                return new WP_Error(
                    'rate_limit_exceeded',
                    sprintf(
                        /* translators: %d: seconds until rate limit resets */
                        __('Rate limit exceeded. Please try again in %d seconds.', 'peanut-connect'),
                        $config['window']
                    ),
                    ['status' => 429, 'retry_after' => $config['window']]
                );
            }
            return true;
        }

        $data = get_transient($key);

        if ($data === false) {
            // First request in this window
            $data = [
                'count' => 1,
                'window_start' => time(),
            ];
            set_transient($key, $data, $config['window']);
            return true;
        }

        // Check if we're still in the same window
        $window_elapsed = time() - $data['window_start'];

        if ($window_elapsed >= $config['window']) {
            // Window has expired, start fresh
            $data = [
                'count' => 1,
                'window_start' => time(),
            ];
            set_transient($key, $data, $config['window']);
            return true;
        }

        // Increment counter
        $data['count']++;

        if ($data['count'] > $config['limit']) {
            $retry_after = $config['window'] - $window_elapsed;

            return new WP_Error(
                'rate_limit_exceeded',
                sprintf(
                    /* translators: %d: seconds until rate limit resets */
                    __('Rate limit exceeded. Please try again in %d seconds.', 'peanut-connect'),
                    $retry_after
                ),
                [
                    'status' => 429,
                    'retry_after' => $retry_after,
                ]
            );
        }

        // Update counter
        set_transient($key, $data, $config['window']);
        return true;
    }

    /**
     * Get rate limit headers for response
     *
     * @param string $identifier Unique identifier
     * @param string $endpoint   The endpoint being accessed
     * @return array Headers to add to response
     */
    public static function get_headers(string $identifier, string $endpoint = 'default'): array {
        $config = self::get_endpoint_config($endpoint);
        $key = self::get_cache_key($identifier, $endpoint);
        $data = get_transient($key);

        $remaining = $config['limit'];
        $reset = time() + $config['window'];

        if ($data !== false) {
            $remaining = max(0, $config['limit'] - $data['count']);
            $reset = $data['window_start'] + $config['window'];
        }

        return [
            'X-RateLimit-Limit' => $config['limit'],
            'X-RateLimit-Remaining' => $remaining,
            'X-RateLimit-Reset' => $reset,
        ];
    }

    /**
     * Get client identifier from request
     *
     * Keys the rate-limit bucket on the (trusted-proxy resolved) CLIENT IP
     * ONLY — never on the credential under test.
     *
     * SECURITY (v3.21.1): earlier revisions folded a hash of the presented
     * Bearer credential into the identifier "for more granular limiting". On
     * the auth endpoints (verify/disconnect/hub) that DEFEATED the brute-force
     * ceiling: every wrong key an attacker tried produced a different
     * identifier, hence a fresh bucket, so the AUTH_LIMIT was never reached no
     * matter how many keys were guessed from one source. The credential must
     * never be part of the rate-limit key — repeated attempts from one source
     * have to share a single bucket. When the IP can't be determined we fall
     * back to a shared sentinel so those requests still share one bucket rather
     * than each escaping into its own.
     *
     * @param WP_REST_Request $request The incoming request
     * @return string Unique client identifier
     */
    public static function get_client_identifier(WP_REST_Request $request): string {
        $ip = self::get_client_ip();
        return ($ip !== null && $ip !== '') ? $ip : 'unknown';
    }

    /**
     * Get client IP address
     *
     * Attempts to get the real client IP, accounting for proxies and load balancers.
     *
     * @return string|null Client IP address or null if not determinable
     */
    private static function get_client_ip(): ?string {
        $remote = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';

        // Forwarded headers (CF-Connecting-IP / X-Forwarded-For / X-Real-IP) are
        // attacker-controllable and must NOT be trusted unless the direct TCP
        // peer is a known proxy/CDN — otherwise a client rotates them per request
        // to defeat the auth-endpoint rate limit and pollute per-IP buckets.
        // Default: use REMOTE_ADDR (the real peer). Sites genuinely behind
        // Cloudflare/an LB opt in via `peanut_connect_trusted_proxies` (a list of
        // IPs or CIDRs); only then are the forwarded headers honoured.
        $trusted = apply_filters('peanut_connect_trusted_proxies', []);
        if ($remote !== '' && is_array($trusted) && self::ip_matches_any($remote, $trusted)) {
            foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $header) {
                if (empty($_SERVER[$header])) {
                    continue;
                }
                // X-Forwarded-For may be a list; the left-most is the client.
                $ip = trim(explode(',', (string) $_SERVER[$header])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return ($remote !== '' && filter_var($remote, FILTER_VALIDATE_IP)) ? $remote : null;
    }

    /**
     * Whether an IP matches any entry in a list of plain IPs or CIDR ranges.
     */
    private static function ip_matches_any(string $ip, array $list): bool {
        foreach ($list as $entry) {
            $entry = trim((string) $entry);
            if ($entry === '') {
                continue;
            }
            if (strpos($entry, '/') === false) {
                if ($ip === $entry) {
                    return true;
                }
                continue;
            }
            if (self::ip_in_cidr($ip, $entry)) {
                return true;
            }
        }
        return false;
    }

    /**
     * IPv4/IPv6 CIDR containment check (binary, family-aware).
     */
    private static function ip_in_cidr(string $ip, string $cidr): bool {
        $parts = explode('/', $cidr, 2);
        if (count($parts) !== 2) {
            return false;
        }
        [$subnet, $bits] = $parts;
        $bits = (int) $bits;
        $ip_bin = @inet_pton($ip);
        $subnet_bin = @inet_pton($subnet);
        if ($ip_bin === false || $subnet_bin === false
            || strlen($ip_bin) !== strlen($subnet_bin)
            || $bits < 0 || $bits > strlen($ip_bin) * 8) {
            return false;
        }
        $whole = intdiv($bits, 8);
        $rem = $bits % 8;
        if ($whole > 0 && strncmp($ip_bin, $subnet_bin, $whole) !== 0) {
            return false;
        }
        if ($rem === 0) {
            return true;
        }
        $mask = chr((0xff << (8 - $rem)) & 0xff);
        return (ord($ip_bin[$whole]) & ord($mask)) === (ord($subnet_bin[$whole]) & ord($mask));
    }

    /**
     * Get configuration for specific endpoint
     *
     * Different endpoints can have different rate limits based on their
     * sensitivity and expected usage patterns.
     *
     * @param string $endpoint Endpoint identifier
     * @return array Configuration with 'limit' and 'window' keys
     */
    private static function get_endpoint_config(string $endpoint): array {
        $configs = [
            // Authentication-related endpoints (stricter)
            'verify' => [
                'limit' => self::AUTH_LIMIT,
                'window' => self::AUTH_WINDOW,
            ],
            'disconnect' => [
                'limit' => self::AUTH_LIMIT,
                'window' => self::AUTH_WINDOW,
            ],

            // Tracking endpoints (higher limits for frontend JS)
            'track' => [
                'limit' => 120,  // 2 events per second max
                'window' => 60,
            ],
            'identify' => [
                'limit' => 30,
                'window' => 60,
            ],
            'conversion' => [
                'limit' => 30,
                'window' => 60,
            ],
            'popup_interaction' => [
                'limit' => 60,
                'window' => 60,
            ],
            // GTM beacon proxy — high-frequency browser beacon, same class as
            // /track. Given its own bucket so it neither starves nor is starved
            // by the /track limit.
            'gtm_beacon' => [
                'limit' => 120,
                'window' => 60,
            ],

            // API proxy (v3.3.0+)
            'api_proxy' => [
                'limit' => 30,
                'window' => 60,
            ],

            // Standard endpoints
            'health' => [
                'limit' => 30,
                'window' => 60,
            ],
            'updates' => [
                'limit' => 30,
                'window' => 60,
            ],
            'update' => [
                'limit' => 10,  // Performing updates should be less frequent
                'window' => 60,
            ],
            'analytics' => [
                'limit' => 30,
                'window' => 60,
            ],

            // Default fallback
            'default' => [
                'limit' => self::DEFAULT_LIMIT,
                'window' => self::DEFAULT_WINDOW,
            ],
        ];

        return $configs[$endpoint] ?? $configs['default'];
    }

    /**
     * Generate cache key for rate limit data
     *
     * @param string $identifier Client identifier
     * @param string $endpoint   Endpoint identifier
     * @return string Cache key
     */
    private static function get_cache_key(string $identifier, string $endpoint): string {
        // Create a short, unique key
        $hash = substr(md5($identifier . $endpoint), 0, 12);
        return 'peanut_rl_' . $hash;
    }

    /**
     * Clear rate limit for a specific client
     *
     * Useful for administrative purposes or after successful authentication.
     *
     * @param string $identifier Client identifier
     * @param string $endpoint   Endpoint identifier (or 'all' for all endpoints)
     */
    public static function clear(string $identifier, string $endpoint = 'all'): void {
        if ($endpoint === 'all') {
            $endpoints = ['verify', 'disconnect', 'health', 'updates', 'update', 'analytics', 'default'];
            foreach ($endpoints as $ep) {
                delete_transient(self::get_cache_key($identifier, $ep));
            }
        } else {
            delete_transient(self::get_cache_key($identifier, $endpoint));
        }
    }
}
