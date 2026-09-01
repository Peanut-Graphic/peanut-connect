<?php
/**
 * Real-WordPress contract tests for the user-enumeration hardening.
 *
 * These tests prove the plugin's boot path registers the protection against
 * WordPress's real REST route table, author query state, and sitemap provider
 * filter. The mock-backed unit suite separately pins the callback edge cases.
 */

namespace Peanut\Connect\Tests\ContractWp;

use WP_UnitTestCase;

class UserEnumerationContractTest extends WP_UnitTestCase
{
    private const USERS_COLLECTION = '/wp/v2/users';

    public function tear_down(): void
    {
        wp_set_current_user(0);

        if (false === has_action('template_redirect', 'redirect_canonical')) {
            add_action('template_redirect', 'redirect_canonical', 10);
        }

        parent::tear_down();
    }

    public function test_anonymous_rest_route_table_hides_user_collection(): void
    {
        wp_set_current_user(0);

        $this->assertArrayNotHasKey(
            self::USERS_COLLECTION,
            $this->fresh_rest_routes(),
            'Anonymous callers must not receive the public users collection from real WordPress.'
        );
    }

    public function test_administrator_rest_route_table_keeps_user_collection(): void
    {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $this->assertArrayHasKey(
            self::USERS_COLLECTION,
            $this->fresh_rest_routes(),
            'A list_users caller must retain the real WordPress users collection.'
        );
    }

    public function test_anonymous_author_query_becomes_noncanonical_404(): void
    {
        $author_id = self::factory()->user->create(['role' => 'author']);
        self::factory()->post->create([
            'post_author' => $author_id,
            'post_status' => 'publish',
        ]);

        wp_set_current_user(0);
        $this->go_to('/?author=' . $author_id);

        $this->assertTrue(is_author(), 'Test precondition: WordPress must resolve the author query.');

        do_action('template_redirect');

        $this->assertTrue(is_404(), 'Anonymous author queries must be converted to 404 responses.');
        $this->assertFalse(
            has_action('template_redirect', 'redirect_canonical'),
            'The slug-revealing canonical redirect must be removed for the blocked request.'
        );
    }

    public function test_real_sitemap_filter_drops_users_provider(): void
    {
        $provider = new \stdClass();

        $this->assertFalse(
            apply_filters('wp_sitemaps_add_provider', $provider, 'users'),
            'The registered real-WordPress filter must drop the users sitemap provider.'
        );
        $this->assertSame(
            $provider,
            apply_filters('wp_sitemaps_add_provider', $provider, 'posts'),
            'The enumeration hardening must leave unrelated sitemap providers untouched.'
        );
    }

    private function fresh_rest_routes(): array
    {
        global $wp_rest_server;

        $wp_rest_server = null;
        do_action('rest_api_init');

        return rest_get_server()->get_routes();
    }
}
