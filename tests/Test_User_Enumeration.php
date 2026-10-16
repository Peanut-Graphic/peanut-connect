<?php
/**
 * Regression tests for the unauthenticated user-enumeration block.
 *
 * Stock WordPress leaks the exact login name of any user with content via
 * /wp-json/wp/v2/users and via the ?author=<id> canonical redirect. On a client
 * site that login name is half of a credential pair. These tests pin the
 * REST half, which is the vector that returns the login name directly.
 *
 * The block must be scoped by capability, not by "is this a REST request":
 * removing the collection outright would break the block editor's author
 * picker, so a caller holding list_users must still see every route.
 *
 * @package Peanut_Connect
 */

require_once dirname(__DIR__) . '/includes/class-connect-security.php';

class Test_User_Enumeration extends Peanut_Connect_TestCase {

    /** The route keys WordPress core registers for the user collection. */
    private const USERS_COLLECTION = '/wp/v2/users';
    private const USERS_SINGLE     = '/wp/v2/users/(?P<id>[\d]+)';
    private const USERS_ME         = '/wp/v2/users/me';

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['pp_test_user_caps'] = [];
        $GLOBALS['mock_options'] = [];
    }

    protected function tearDown(): void {
        $GLOBALS['pp_test_user_caps'] = [];
        $GLOBALS['mock_options'] = [];
        parent::tearDown();
    }

    /**
     * A representative slice of the core REST route table.
     */
    private function core_endpoints(): array {
        return [
            self::USERS_COLLECTION => ['callback' => 'core'],
            self::USERS_SINGLE     => ['callback' => 'core'],
            self::USERS_ME         => ['callback' => 'core'],
            '/wp/v2/posts'         => ['callback' => 'core'],
        ];
    }

    /**
     * The leak itself: logged out, the user collection must be gone.
     */
    public function test_user_routes_removed_for_anonymous_caller() {
        $filtered = Peanut_Connect_Security::filter_user_endpoints($this->core_endpoints());

        $this->assertArrayNotHasKey(
            self::USERS_COLLECTION,
            $filtered,
            '/wp/v2/users must not be reachable without list_users -- it returns the login name in `slug`.'
        );
        $this->assertArrayNotHasKey(
            self::USERS_SINGLE,
            $filtered,
            '/wp/v2/users/<id> leaks the same field one record at a time.'
        );
    }

    /**
     * Unrelated routes must survive -- the filter has to be surgical.
     */
    public function test_unrelated_routes_are_untouched() {
        $filtered = Peanut_Connect_Security::filter_user_endpoints($this->core_endpoints());

        $this->assertArrayHasKey('/wp/v2/posts', $filtered);
    }

    /**
     * /users/me only ever returns the caller's own record and the block editor
     * depends on it, so it must survive the block.
     */
    public function test_users_me_is_preserved() {
        $filtered = Peanut_Connect_Security::filter_user_endpoints($this->core_endpoints());

        $this->assertArrayHasKey(
            self::USERS_ME,
            $filtered,
            '/users/me is self-scoped and already 401s when logged out; removing it breaks the editor.'
        );
    }

    /**
     * A caller who may already list users sees the unmodified route table.
     * This is what keeps the block editor and authenticated tooling working.
     */
    public function test_privileged_caller_keeps_every_route() {
        $GLOBALS['pp_test_user_caps'] = ['list_users' => true];

        $endpoints = $this->core_endpoints();
        $filtered  = Peanut_Connect_Security::filter_user_endpoints($endpoints);

        $this->assertSame(
            $endpoints,
            $filtered,
            'list_users holders must get the route table back untouched.'
        );
    }

    /**
     * Guard the capability boundary: being logged in is not enough. A
     * subscriber has no list_users, so the collection stays blocked.
     */
    public function test_logged_in_without_list_users_is_still_blocked() {
        $GLOBALS['pp_test_logged_in'] = true;
        $GLOBALS['pp_test_user_caps'] = ['read' => true];

        $filtered = Peanut_Connect_Security::filter_user_endpoints($this->core_endpoints());

        $this->assertArrayNotHasKey(self::USERS_COLLECTION, $filtered);

        $GLOBALS['pp_test_logged_in'] = false;
    }

    /**
     * The filter runs against whatever core hands it; a non-array must pass
     * through rather than fatal inside REST dispatch.
     */
    public function test_non_array_passes_through() {
        $this->assertNull(Peanut_Connect_Security::filter_user_endpoints(null));
    }

    /**
     * The core users sitemap (wp-sitemap-users-N.xml) enumerates the same
     * accounts by archive URL.
     */
    public function test_users_sitemap_provider_is_dropped() {
        $this->assertFalse(
            Peanut_Connect_Security::remove_users_sitemap(new stdClass(), 'users')
        );
    }

    /**
     * Other sitemap providers must be returned unchanged.
     */
    public function test_other_sitemap_providers_survive() {
        $provider = new stdClass();

        $this->assertSame($provider, Peanut_Connect_Security::remove_users_sitemap($provider, 'posts'));
    }

    /**
     * The block is on unless a site explicitly opts out -- a hardening default
     * that ships off protects nobody.
     */
    public function test_enabled_by_default() {
        $settings = Peanut_Connect_Security::get_settings();

        $this->assertTrue($settings['block_user_enumeration']);
    }

    /**
     * ...and an explicit opt-out is still honoured.
     */
    public function test_can_be_disabled_per_site() {
        $GLOBALS['mock_options']['peanut_connect_block_user_enumeration'] = '0';

        $settings = Peanut_Connect_Security::get_settings();

        $this->assertFalse($settings['block_user_enumeration']);
    }
}
