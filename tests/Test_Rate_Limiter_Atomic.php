<?php
/**
 * The rate limiter's atomic (object-cache) path must enforce the limit using
 * wp_cache_incr — closing the read-then-write TOCTOU race of the transient path.
 *
 * @package Peanut_Connect
 */

use PHPUnit\Framework\TestCase;

// Minimal in-memory object cache that models the atomicity contract: incr is a
// single operation on a shared store (what Redis/Memcached provide server-side).
if (!function_exists('wp_using_ext_object_cache')) {
    function wp_using_ext_object_cache() {
        return $GLOBALS['pp_ext_cache'] ?? false;
    }
}
if (!function_exists('wp_cache_add')) {
    function wp_cache_add($key, $value, $group = '', $ttl = 0) {
        $k = "$group:$key";
        if (isset($GLOBALS['pp_cache'][$k])) {
            return false; // no-op when present — the seeding contract
        }
        $GLOBALS['pp_cache'][$k] = (int) $value;
        return true;
    }
}
if (!function_exists('wp_cache_incr')) {
    function wp_cache_incr($key, $offset = 1, $group = '') {
        $k = "$group:$key";
        if (!isset($GLOBALS['pp_cache'][$k])) {
            return false;
        }
        $GLOBALS['pp_cache'][$k] += $offset;
        return $GLOBALS['pp_cache'][$k];
    }
}

require_once dirname(__DIR__) . '/includes/class-connect-rate-limiter.php';

class Test_Rate_Limiter_Atomic extends TestCase {
    protected function setUp(): void {
        $GLOBALS['pp_ext_cache'] = true;   // force the atomic path
        $GLOBALS['pp_cache'] = [];
    }

    protected function tearDown(): void {
        $GLOBALS['pp_ext_cache'] = false;  // restore for other suites (transient path)
        $GLOBALS['pp_cache'] = [];
    }

    public function test_atomic_path_allows_up_to_the_limit_then_blocks(): void {
        // 'verify' endpoint = limit 10 / 60s (a strict, easy-to-count config).
        $allowed = 0;
        $blocked = null;
        for ($i = 0; $i < 15; $i++) {
            $r = Peanut_Connect_Rate_Limiter::check('1.2.3.4', 'verify');
            if ($r === true) {
                $allowed++;
            } else {
                $blocked = $r;
                break;
            }
        }
        $this->assertSame(10, $allowed, 'exactly the limit is allowed');
        $this->assertInstanceOf(WP_Error::class, $blocked);
        $this->assertSame(429, $blocked->get_error_data()['status']);
    }

    public function test_atomic_counter_is_a_single_shared_increment(): void {
        // Two identifiers do not share a bucket (independent counters).
        Peanut_Connect_Rate_Limiter::check('a', 'verify');
        Peanut_Connect_Rate_Limiter::check('a', 'verify');
        $this->assertTrue(Peanut_Connect_Rate_Limiter::check('b', 'verify'));
        // The shared store reflects each identifier's own count, incremented in place.
        $keys = array_keys($GLOBALS['pp_cache']);
        $this->assertCount(2, $keys, 'one bucket per identifier');
    }
}
