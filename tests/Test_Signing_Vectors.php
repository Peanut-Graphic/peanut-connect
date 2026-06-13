<?php
/**
 * Cross-repo request-signing CONTRACT test.
 *
 * The Hub (App\Support\PeanutConnectSigner) and this plugin
 * (Peanut_Connect_Auth) independently implement the same HMAC canonicalization.
 * Nothing but human review currently guarantees they agree — a drift on either
 * side (method casing, route shape, body hashing, field order, separators)
 * surfaces only as "invalid signature" on the wire, with both repos' unit
 * tests still green.
 *
 * This test pins the canonicalization to a shared fixture of authoritative
 * vectors (docs/protocol/hub-signing-vectors.json). The HUB repo must run the
 * SAME fixture against its signer. If either side drifts, its CI fails here —
 * the drift can no longer reach production silently.
 *
 * @package Peanut_Connect
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/class-connect-auth.php';

class Test_Signing_Vectors extends TestCase {

    /** @return array<string,array<int,mixed>> */
    public static function vectorProvider(): array {
        $path = dirname(__DIR__) . '/docs/protocol/hub-signing-vectors.json';
        $data = json_decode((string) file_get_contents($path), true);
        $cases = [];
        foreach ($data['vectors'] as $v) {
            $cases[$v['name']] = [$v];
        }
        return $cases;
    }

    /**
     * @dataProvider vectorProvider
     * @param array<string,string> $v
     */
    public function test_signature_matches_vector(array $v): void {
        $actual = Peanut_Connect_Auth::compute_request_signature(
            $v['key'], $v['method'], $v['route'], $v['timestamp'], $v['nonce'], $v['body']
        );
        $this->assertSame(
            $v['expected_signature'],
            $actual,
            "Signing canonicalization drifted on vector '{$v['name']}'. If this change is intentional, regenerate docs/protocol/hub-signing-vectors.json AND update the Hub signer (App\\Support\\PeanutConnectSigner) to match — both repos must agree."
        );
    }

    public function test_fixture_is_versioned_and_nonempty(): void {
        $data = json_decode((string) file_get_contents(dirname(__DIR__) . '/docs/protocol/hub-signing-vectors.json'), true);
        $this->assertArrayHasKey('version', $data);
        $this->assertNotEmpty($data['vectors']);
    }
}
