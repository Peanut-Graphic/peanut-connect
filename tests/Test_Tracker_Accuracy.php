<?php
/**
 * Guards for three tracker defects that made the recorded numbers wrong.
 *
 * Measured on the fleet before the fix, on nattybumpercar.com:
 *   - 1,368,120 visitor rows, every single one with total_visits = 1 (max 1)
 *   - 690,977 of them (~50%) with browser, OS *and* device all unresolved --
 *     the signature of a user-agent that matched nothing, which is_bot()
 *     waved through because an empty string contains none of its patterns
 *   - 0 rows carrying an email or name, on that site and on molliesperduto
 *
 * @package Peanut_Connect
 */

class Test_Tracker_Accuracy extends PHPUnit\Framework\TestCase {

    protected function setUp(): void {
        parent::setUp();
        unset($_SERVER['HTTP_USER_AGENT']);
    }

    protected function tearDown(): void {
        unset($_SERVER['HTTP_USER_AGENT']);
        parent::tearDown();
    }

    private function isBotFor(?string $ua): bool {
        if ($ua === null) {
            unset($_SERVER['HTTP_USER_AGENT']);
        } else {
            $_SERVER['HTTP_USER_AGENT'] = $ua;
        }
        return Peanut_Connect_Tracker::is_bot();
    }

    /**
     * The hole that produced half the table: no user-agent at all sailed
     * through, and get_device_info() then defaulted it to a desktop visitor.
     */
    public function test_missing_or_empty_user_agent_is_a_bot(): void {
        $this->assertTrue($this->isBotFor(null), 'absent user-agent must not be tracked');
        $this->assertTrue($this->isBotFor(''), 'empty user-agent must not be tracked');
        $this->assertTrue($this->isBotFor('   '), 'whitespace-only user-agent must not be tracked');
    }

    /**
     * @dataProvider botAgents
     */
    public function test_known_non_browser_agents_are_bots(string $ua): void {
        $this->assertTrue($this->isBotFor($ua), "should be treated as a bot: $ua");
    }

    public static function botAgents(): array {
        return [
            'googlebot'   => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'],
            'bingbot'     => ['Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)'],
            'ahrefs'      => ['Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)'],
            'curl'        => ['curl/8.4.0'],
            'wget'        => ['Wget/1.21.3'],
            'python'      => ['python-requests/2.31.0'],
            'go'          => ['Go-http-client/1.1'],
            'java'        => ['Java/17.0.1'],
            'okhttp'      => ['okhttp/4.9.3'],
            'headless'    => ['Mozilla/5.0 HeadlessChrome/120.0.0.0'],
            'uptimerobot' => ['Mozilla/5.0+(compatible; UptimeRobot/2.0; http://www.uptimerobot.com/)'],
        ];
    }

    /**
     * @dataProvider humanAgents
     */
    public function test_real_browsers_are_still_tracked(string $ua): void {
        $this->assertFalse($this->isBotFor($ua), "must still be tracked: $ua");
    }

    public static function humanAgents(): array {
        return [
            'chrome'  => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'],
            'safari'  => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Safari/605.1.15'],
            'firefox' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0'],
            'iphone'  => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1'],
            'edge'    => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0'],
        ];
    }

    /**
     * The Mozilla/ requirement is a check on the shape of the UA, not on
     * whether we can name the browser. A niche or brand-new browser reports
     * as "Unknown" in get_device_info() and must still be counted -- otherwise
     * the fix for over-counting bots would start under-counting people.
     */
    public function test_unrecognised_but_browser_shaped_agents_are_not_bots(): void {
        $this->assertFalse(
            $this->isBotFor('Mozilla/5.0 (X11; Linux x86_64) SomeNewEngine/3.2'),
            'a browser we cannot name is still a browser'
        );
        $this->assertFalse(
            $this->isBotFor('Mozilla/5.0 (Unknown; rv:1.0) MinimalBrowser/1.0'),
            'unparseable-but-browser-shaped must still be tracked'
        );
    }

    /**
     * total_visits must actually be a session count now.
     */
    public function test_session_gap_is_the_conventional_thirty_minutes(): void {
        $this->assertSame(1800, (int) constant('Peanut_Connect_Tracker::SESSION_GAP'));
    }

    /**
     * The update path must not blank identification, and must count a return
     * visit. Asserted against the source because update_visitor() needs a live
     * $wpdb and a real schema to exercise end to end.
     */
    /**
     * Only the UPDATE branch is at issue. On INSERT there is no prior value,
     * so writing null for an unknown email is correct and stays.
     */
    private function updateBranch(): string {
        $src = file_get_contents(dirname(__DIR__) . '/includes/class-connect-tracker.php');
        $this->assertNotFalse($src);

        $start = strpos($src, 'public static function update_visitor');
        $this->assertNotFalse($start, 'update_visitor() not found');

        $else = strpos($src, '} else {', $start);
        $this->assertNotFalse($else, 'could not find the insert branch that ends the update branch');

        $branch = substr($src, $start, $else - $start);

        // Strip `//` comments: the fix's own comment quotes the old line
        // verbatim to explain it, and that should not read as the bug.
        return preg_replace('~^\s*//.*$~m', '', $branch);
    }

    public function test_update_path_never_nulls_identification(): void {
        $branch = $this->updateBranch();

        $this->assertStringNotContainsString(
            "'email' => \$data['email'] ?? null",
            $branch,
            'an anonymous pageview must not overwrite a captured email with NULL'
        );
        $this->assertStringNotContainsString(
            "'name' => \$data['name'] ?? null",
            $branch,
            'an anonymous pageview must not overwrite a captured name with NULL'
        );
        // And it must only write them when there is something to write.
        $this->assertStringContainsString("!empty(\$data['email'])", $branch);
        $this->assertStringContainsString("!empty(\$data['name'])", $branch);
    }

    /**
     * The filter belongs on the write path, not on the HTTP entry points.
     * is_bot() was called only from track_pageview(), so every public REST
     * tracking route wrote unfiltered. Guarding record_event() and
     * update_visitor() covers all of them and — unlike guarding the REST
     * precheck — does not preempt payload validation, which would turn an
     * oversized request's 413 into a silent 200.
     */
    public function test_both_write_paths_filter_bots(): void {
        $src = file_get_contents(dirname(__DIR__) . '/includes/class-connect-tracker.php');
        $this->assertNotFalse($src);

        foreach (['record_event', 'update_visitor'] as $fn) {
            $start = strpos($src, "public static function $fn(");
            $this->assertNotFalse($start, "$fn() not found");

            // The guard must be near the top, before any writing happens.
            $head = substr($src, $start, 1200);
            $this->assertStringContainsString(
                'self::is_bot()',
                $head,
                "$fn() must drop bot traffic before it writes a row"
            );
        }
    }

    public function test_update_path_increments_total_visits(): void {
        $src = file_get_contents(dirname(__DIR__) . '/includes/class-connect-tracker.php');
        $this->assertNotFalse($src);

        $this->assertMatchesRegularExpression(
            '/total_visits.{0,80}total_visits/s',
            $src,
            'total_visits must be derived from its previous value, not left at the insert-time 1'
        );
        $this->assertStringContainsString(
            'SESSION_GAP',
            $src,
            'a return visit should be decided by the session gap'
        );
    }
}
