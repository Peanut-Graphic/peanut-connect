<?php
/**
 * Real-WordPress contract tests for site-branded short links.
 *
 * Hub returns short links on its own domain (hub.../go/{slug}). The plugin's
 * 404 handler already serves https://client-site/{slug} by redirecting to that
 * Hub route, so the builder should hand out the branded form — but only when
 * the redirect can actually fire, i.e. no real WordPress content owns the path.
 * Permalink resolution is WordPress behaviour, so this runs against real WP.
 */

namespace Peanut\Connect\Tests\ContractWp;

use Peanut_Connect_Short_Links;
use WP_UnitTestCase;

class ShortLinkBrandingContractTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        $this->set_permalink_structure('/%postname%/');
    }

    public function test_free_slug_brands_to_the_site_home_url(): void
    {
        $this->assertSame(
            home_url('/ptm-october-postcard'),
            Peanut_Connect_Short_Links::branded_url('ptm-october-postcard')
        );
    }

    public function test_slug_owned_by_a_published_page_is_not_branded(): void
    {
        self::factory()->post->create([
            'post_type'   => 'page',
            'post_name'   => 'free-sensi',
            'post_status' => 'publish',
        ]);

        $this->assertNull(
            Peanut_Connect_Short_Links::branded_url('free-sensi'),
            'A real page answers that path, so the 404 redirect would never reach Hub.'
        );
    }

    public function test_slugs_the_redirect_handler_skips_are_not_branded(): void
    {
        foreach (['', 'wp-admin', 'feed', 'promo.pdf', 'two/segments', 'bad slug'] as $slug) {
            $this->assertNull(
                Peanut_Connect_Short_Links::branded_url($slug),
                "maybe_redirect() never serves '{$slug}', so it must not be handed out."
            );
        }
    }

    public function test_campaign_response_gains_branded_url_and_keeps_hub_short_url(): void
    {
        $data = Peanut_Connect_Short_Links::brand_response([
            'success'  => true,
            'campaign' => [
                'link'      => ['slug' => 'ptm-october-postcard'],
                'short_url' => 'https://hub.example.test/go/ptm-october-postcard',
            ],
        ]);

        $this->assertSame(home_url('/ptm-october-postcard'), $data['campaign']['branded_url']);
        $this->assertSame('https://hub.example.test/go/ptm-october-postcard', $data['campaign']['short_url']);
    }

    public function test_paginated_link_rows_gain_branded_url(): void
    {
        self::factory()->post->create([
            'post_type'   => 'page',
            'post_name'   => 'about',
            'post_status' => 'publish',
        ]);

        $data = Peanut_Connect_Short_Links::brand_response([
            'success' => true,
            'data'    => [
                'data'         => [['slug' => 'spring-mailer'], ['slug' => 'about']],
                'current_page' => 1,
            ],
        ]);

        $this->assertSame(home_url('/spring-mailer'), $data['data']['data'][0]['branded_url']);
        $this->assertNull($data['data']['data'][1]['branded_url']);
        $this->assertSame(1, $data['data']['current_page']);
    }
}
