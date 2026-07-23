<?php
/**
 * The Dominion funnel proxy route must delegate to forward() and, when the
 * site is not connected to a Hub, surface forward()'s 412 short-circuit
 * WITHOUT making any HTTP call. This proves the callback is wired to the
 * shared signed proxy exactly like gtm_coverage / campaign_story.
 *
 * @package Peanut_Connect
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/class-connect-auth.php';
require_once dirname(__DIR__) . '/includes/class-connect-marketing.php';

class Test_Dominion_Funnel extends TestCase {

    protected function setUp(): void {
        global $mock_options;
        // Disconnected: no Hub URL and no API key -> forward() returns a 412
        // WP_Error before touching wp_remote_request().
        $mock_options = [];
    }

    public function test_dominion_funnel_delegates_to_forward_and_reports_not_connected(): void {
        // The test-suite WP_REST_Request mock omits get_query_params(); supply it
        // via a subclass so the proxy method receives an empty query bag.
        $request = new class extends WP_REST_Request {
            public function get_query_params(): array {
                return [];
            }
        };

        $result = Peanut_Connect_Marketing::dominion_funnel($request);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('peanut_connect_not_connected', $result->get_error_code());
        $data = $result->get_error_data();
        $this->assertIsArray($data);
        $this->assertSame(412, $data['status']);
    }
}
