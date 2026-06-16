<?php
/**
 * Test_Popup_Render_NonBlocking — pins Fix B (2026-06-16 reliability audit).
 *
 * The popup render path (wp_footer / wp_enqueue_scripts → get_active_popups)
 * must NEVER make a synchronous blocking Hub fetch, and must negative-cache
 * an empty result so a Hub outage can't turn every pageview into a 10s hang.
 */

class Test_Popup_Render_NonBlocking extends Peanut_Connect_TestCase {

    protected function setUp(): void {
        parent::setUp();
        global $mock_options, $mock_transients, $mock_wp_remote_get, $mock_wp_remote_get_calls;
        $mock_options = [];
        $mock_transients = [];
        $mock_wp_remote_get_calls = 0;
        // Any outbound GET from the render path is a failure; make it loud.
        $mock_wp_remote_get = function () {
            throw new RuntimeException('render path must not call wp_remote_get');
        };

        require_once dirname(__DIR__) . '/includes/class-connect-popup-display.php';
    }

    protected function tearDown(): void {
        global $mock_wp_remote_get;
        $mock_wp_remote_get = null;
        parent::tearDown();
    }

    /**
     * With no cached popups, the render path returns [] WITHOUT making a
     * blocking outbound request.
     */
    public function test_empty_option_does_not_fetch_from_hub(): void {
        global $mock_wp_remote_get_calls;
        $result = Peanut_Connect_Popup_Display::get_active_popups();
        $this->assertSame([], $result);
        $this->assertSame(0, $mock_wp_remote_get_calls, 'Render path must not call the Hub synchronously.');
    }

    /**
     * An empty result must be negative-cached so repeated pageviews don't
     * even re-read/re-filter — and certainly never re-attempt a fetch.
     */
    public function test_empty_result_is_negative_cached(): void {
        Peanut_Connect_Popup_Display::get_active_popups();
        $this->assertNotEmpty(
            get_transient(Peanut_Connect_Popup_Display::EMPTY_POPUPS_TRANSIENT),
            'Empty popups must be negative-cached.'
        );

        // Second call short-circuits on the transient.
        global $mock_wp_remote_get_calls;
        $this->assertSame([], Peanut_Connect_Popup_Display::get_active_popups());
        $this->assertSame(0, $mock_wp_remote_get_calls);
    }

    /**
     * Cached popups (populated by the heartbeat) are returned and filtered
     * without any outbound request.
     */
    public function test_cached_popups_are_returned_without_fetch(): void {
        global $mock_wp_remote_get_calls;
        update_option('peanut_connect_hub_popups', [
            ['id' => 1, 'targeting' => []], // no targeting => matches
        ]);
        $result = Peanut_Connect_Popup_Display::get_active_popups();
        $this->assertCount(1, $result);
        $this->assertSame(0, $mock_wp_remote_get_calls);
    }

    /**
     * Source guard: get_active_popups must not reference fetch_popups()
     * (the blocking synchronous Hub GET) at all.
     */
    public function test_source_does_not_call_fetch_popups_in_render_path(): void {
        $src = file_get_contents(dirname(__DIR__) . '/includes/class-connect-popup-display.php');
        $matches = [];
        $found = preg_match(
            '/function get_active_popups\(\):\s*array\s*\{(.+?)\n    \}/s',
            $src,
            $matches
        );
        $this->assertSame(1, $found, 'Could not locate get_active_popups().');
        $this->assertStringNotContainsString(
            'fetch_popups',
            $matches[1],
            'get_active_popups() must not synchronously fetch from the Hub.'
        );
    }
}
