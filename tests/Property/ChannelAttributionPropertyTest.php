<?php
/**
 * Property-based tests (Net 6) for channel attribution.
 *
 * Target: Peanut_Connect_Tracker::determine_channel() — a PURE classifier
 * (no WordPress calls, no I/O, deterministic) that maps a UTM source/medium
 * plus a referrer URL onto a marketing channel label. Channel attribution
 * drives every downstream analytics number, so its invariants are load-bearing.
 *
 * Seam: the method is `public static` and reads only its three arguments, so it
 * is exercised directly with no WordPress bootstrap. We only need ABSPATH defined
 * (the test bootstrap does that) so the guarded class file can be autoloaded.
 *
 * Approach: a hand-rolled, SEEDED property runner (PHP has no fast-check). The
 * fixed seed makes every run reproducible; a failing case prints the exact
 * counter-example so it can be reproduced and treated as a real bug — never
 * weakened away.
 *
 * @package Peanut_Connect
 */

use PHPUnit\Framework\TestCase;

class ChannelAttributionPropertyTest extends TestCase {

    /** Every label determine_channel is allowed to return. */
    private const VALID_CHANNELS = [
        'direct', 'paid', 'email', 'social', 'organic', 'referral', 'other',
    ];

    private const PAID_MEDIUMS = ['cpc', 'ppc', 'paid', 'paidsocial', 'paid_social'];

    private const SOCIAL_DOMAINS = [
        'facebook.com', 'twitter.com', 'linkedin.com', 'instagram.com',
        'pinterest.com', 'tiktok.com', 't.co', 'fb.me',
    ];

    private const NUM_CASES = 2000;

    /** Deterministic PRNG, re-seeded per test for reproducibility. */
    private function seed(): void {
        mt_srand(1337);
    }

    /** Pick a random element from a list. */
    private function pick(array $list): string {
        return $list[mt_rand(0, count($list) - 1)];
    }

    /** Generate an arbitrary source/medium token (incl. empty + odd values). */
    private function arbToken(): string {
        $pool = [
            '', 'email', 'social', 'organic', 'referral', 'cpc', 'ppc', 'paid',
            'paidsocial', 'paid_social', 'newsletter', 'google', 'facebook',
            'PAID', 'Email', 'unknown-source', 'x', '123', 'a b',
        ];
        return $this->pick($pool);
    }

    /** Generate an arbitrary referrer URL (incl. empty + social/search hosts). */
    private function arbReferrer(): string {
        $hosts = [
            '', 'https://facebook.com/x', 'http://t.co/abc', 'https://fb.me/y',
            'https://google.com/search?q=z', 'https://www.bing.com/',
            'https://yandex.ru/', 'https://example.com/page',
            'https://news.ycombinator.com/', 'https://LINKEDIN.COM/in/a',
        ];
        return $this->pick($hosts);
    }

    /**
     * INVARIANT 1 — totality: every input maps to a known channel label.
     * A classifier that can return an out-of-domain value is a real bug.
     */
    public function test_always_returns_a_valid_channel(): void {
        $this->seed();
        for ($i = 0; $i < self::NUM_CASES; $i++) {
            $source   = $this->arbToken();
            $medium   = $this->arbToken();
            $referrer = $this->arbReferrer();

            $channel = Peanut_Connect_Tracker::determine_channel($source, $medium, $referrer);

            $this->assertContains(
                $channel,
                self::VALID_CHANNELS,
                sprintf(
                    'Out-of-domain channel %s for source=%s medium=%s referrer=%s',
                    var_export($channel, true), var_export($source, true),
                    var_export($medium, true), var_export($referrer, true)
                )
            );
        }
    }

    /**
     * INVARIANT 2 — determinism: identical inputs yield identical output.
     */
    public function test_is_deterministic(): void {
        $this->seed();
        for ($i = 0; $i < self::NUM_CASES; $i++) {
            $source   = $this->arbToken();
            $medium   = $this->arbToken();
            $referrer = $this->arbReferrer();

            $a = Peanut_Connect_Tracker::determine_channel($source, $medium, $referrer);
            $b = Peanut_Connect_Tracker::determine_channel($source, $medium, $referrer);

            $this->assertSame(
                $a, $b,
                sprintf('Non-deterministic for source=%s medium=%s referrer=%s',
                    var_export($source, true), var_export($medium, true),
                    var_export($referrer, true))
            );
        }
    }

    /**
     * INVARIANT 3 — direct: with no source AND no referrer the visit is direct,
     * regardless of medium. This is the documented base case.
     */
    public function test_no_source_and_no_referrer_is_direct(): void {
        $this->seed();
        for ($i = 0; $i < self::NUM_CASES; $i++) {
            $medium = $this->arbToken();
            $this->assertSame(
                'direct',
                Peanut_Connect_Tracker::determine_channel('', $medium, ''),
                sprintf('Expected direct for empty source+referrer, medium=%s',
                    var_export($medium, true))
            );
        }
    }

    /**
     * INVARIANT 4 — paid wins: a paid medium always classifies as paid, even
     * when a referrer is present (paid is checked before social/organic/referral).
     * We keep source non-empty so the direct base case can't pre-empt it.
     */
    public function test_paid_medium_is_always_paid(): void {
        $this->seed();
        for ($i = 0; $i < self::NUM_CASES; $i++) {
            $medium   = $this->pick(self::PAID_MEDIUMS);
            $referrer = $this->arbReferrer();
            $source   = 'campaign'; // non-empty so it's never "direct"

            $this->assertSame(
                'paid',
                Peanut_Connect_Tracker::determine_channel($source, $medium, $referrer),
                sprintf('Expected paid for medium=%s referrer=%s',
                    var_export($medium, true), var_export($referrer, true))
            );
        }
    }

    /**
     * INVARIANT 5 — social referrer: a referrer from a known social domain, with
     * a medium that does not pre-empt it (not paid/email/organic) and a non-empty
     * source, classifies as social. Matches the case-insensitive host scan.
     */
    public function test_social_referrer_is_social(): void {
        $this->seed();
        for ($i = 0; $i < self::NUM_CASES; $i++) {
            $domain   = $this->pick(self::SOCIAL_DOMAINS);
            $referrer = 'https://' . $domain . '/some/path';
            // Medium that does not short-circuit to paid/email/organic.
            $medium   = $this->pick(['', 'referral', 'social', 'cpx', 'unknown']);
            $source   = 'somewhere'; // non-empty so not "direct"

            $channel = Peanut_Connect_Tracker::determine_channel($source, $medium, $referrer);

            $this->assertSame(
                'social',
                $channel,
                sprintf('Expected social for medium=%s referrer=%s, got %s',
                    var_export($medium, true), var_export($referrer, true),
                    var_export($channel, true))
            );
        }
    }
}
