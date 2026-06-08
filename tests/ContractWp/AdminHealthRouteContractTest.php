<?php
/**
 * Real-WordPress REST contract test (net 7) for the admin health route.
 *
 * Pins the REAL `GET /peanut-connect/v1/admin/health` route registered by
 * \Peanut_Connect_API::register_routes() (wired on `rest_api_init` by the
 * plugin's own boot). Its `permission_callback` is
 * \Peanut_Connect_API::admin_permission_check(), which is
 * `current_user_can('manage_options')` — so this route has a real AUTH
 * contract, not a public one.
 *
 * Documented behaviour (see Peanut_Connect_API::get_admin_health):
 *   - Unauthenticated request => HTTP 401 (WordPress rest_forbidden).
 *   - manage_options user     => HTTP 200, body
 *       [ 'success' => true, 'data' => array<health...> ].
 *
 * This boots a real WordPress and dispatches through the real REST server —
 * NO mocks. If the route, its auth gate, or its shape regresses, this fails.
 */

namespace Peanut\Connect\Tests\ContractWp;

use WP_UnitTestCase;
use WP_REST_Request;

class AdminHealthRouteContractTest extends WP_UnitTestCase {

    private const ROUTE = '/peanut-connect/v1/admin/health';

    public function set_up(): void {
        parent::set_up();

        // The plugin registers its REST routes on `rest_api_init` via its own
        // `init` (priority 0) boot, which has already run by the time the test
        // case is set up. Rebuild the REST server so the routes are live for
        // this test's dispatch.
        global $wp_rest_server;
        $wp_rest_server = null;
        do_action('rest_api_init');
    }

    public function test_admin_health_route_is_registered(): void {
        $routes = rest_get_server()->get_routes();

        $this->assertArrayHasKey(
            self::ROUTE,
            $routes,
            'Admin health route must be registered on a real WordPress.'
        );
    }

    public function test_admin_health_requires_authentication(): void {
        // No current user => the manage_options permission_callback fails.
        wp_set_current_user(0);

        $request  = new WP_REST_Request('GET', self::ROUTE);
        $response = rest_get_server()->dispatch($request);

        $this->assertSame(
            401,
            $response->get_status(),
            'Unauthenticated admin health request must be rejected with HTTP 401.'
        );
    }

    public function test_admin_health_returns_documented_contract_for_admin(): void {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $request  = new WP_REST_Request('GET', self::ROUTE);
        $response = rest_get_server()->dispatch($request);

        $this->assertSame(
            200,
            $response->get_status(),
            'Admin health request from a manage_options user must return HTTP 200.'
        );

        $data = $response->get_data();

        // Documented response-shape keys.
        $this->assertIsArray($data);
        $this->assertArrayHasKey('success', $data);
        $this->assertArrayHasKey('data', $data);
        $this->assertTrue($data['success']);
        $this->assertIsArray($data['data']);
    }
}
