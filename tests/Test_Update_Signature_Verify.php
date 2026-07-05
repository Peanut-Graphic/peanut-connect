<?php
/**
 * Tests for the update-package signature-verification core.
 *
 * The self-updater refuses to install its own release package unless the bytes
 * match BOTH the sha256 and the detached Ed25519 signature published in the
 * release's sidecar manifest (Peanut-meta scripts/publish-plugin.sh signs every
 * release). A live download is impractical to unit-test, so we pin the pure
 * verification core — Peanut_Connect_Self_Updater::verify_bytes() — which is
 * exactly what verify_package_signature() calls once it has the bytes + manifest.
 *
 * verify_bytes() is fail-closed: hash mismatch, tampered bytes, wrong/missing
 * signature, or a missing manifest field all return false. A correctly-signed
 * payload (signed here with a throwaway test keypair — we can't hold the private
 * half of the production key) returns true.
 *
 * @package Peanut_Connect
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/class-connect-self-updater.php';

class Test_Update_Signature_Verify extends TestCase {

    /** @var string base64 test signing public key */
    private string $pub_b64;

    /** @var string raw test signing secret key */
    private string $secret;

    protected function setUp(): void {
        parent::setUp();
        if (!function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('libsodium not available');
        }
        $keypair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($keypair);
        $this->pub_b64 = base64_encode(sodium_crypto_sign_publickey($keypair));
    }

    /**
     * Build a manifest matching a payload, signed with the test keypair.
     *
     * @return array{sha256:string,signature:string}
     */
    private function manifest_for(string $bytes): array {
        return [
            'sha256'    => hash('sha256', $bytes),
            'signature' => base64_encode(sodium_crypto_sign_detached($bytes, $this->secret)),
        ];
    }

    /** (a) A correctly-signed payload with a matching hash verifies true. */
    public function test_correctly_signed_payload_verifies(): void {
        $bytes = random_bytes(2048);
        $manifest = $this->manifest_for($bytes);
        $this->assertTrue(
            Peanut_Connect_Self_Updater::verify_bytes($bytes, $manifest, $this->pub_b64)
        );
    }

    /** (b1) Tampered bytes (signature no longer matches the payload) fail. */
    public function test_tampered_payload_fails(): void {
        $bytes = random_bytes(2048);
        $manifest = $this->manifest_for($bytes);
        // Flip a byte AND fix the sha256 so only the signature check can reject
        // it — this proves the Ed25519 check is load-bearing, not just the hash.
        $tampered = $bytes;
        $tampered[0] = $tampered[0] === "\x00" ? "\x01" : "\x00";
        $manifest['sha256'] = hash('sha256', $tampered);
        $this->assertFalse(
            Peanut_Connect_Self_Updater::verify_bytes($tampered, $manifest, $this->pub_b64)
        );
    }

    /** (b2) A wrong sha256 (hash mismatch) fails even with a valid signature. */
    public function test_wrong_sha256_fails(): void {
        $bytes = random_bytes(2048);
        $manifest = $this->manifest_for($bytes);
        $manifest['sha256'] = hash('sha256', 'something-else');
        $this->assertFalse(
            Peanut_Connect_Self_Updater::verify_bytes($bytes, $manifest, $this->pub_b64)
        );
    }

    /** (c) A missing signature field fails (refuse an unsigned package). */
    public function test_missing_signature_fails(): void {
        $bytes = random_bytes(2048);
        $manifest = $this->manifest_for($bytes);
        unset($manifest['signature']);
        $this->assertFalse(
            Peanut_Connect_Self_Updater::verify_bytes($bytes, $manifest, $this->pub_b64)
        );
    }

    /** A missing sha256 field fails too. */
    public function test_missing_sha256_fails(): void {
        $bytes = random_bytes(2048);
        $manifest = $this->manifest_for($bytes);
        unset($manifest['sha256']);
        $this->assertFalse(
            Peanut_Connect_Self_Updater::verify_bytes($bytes, $manifest, $this->pub_b64)
        );
    }

    /** A signature from the WRONG key fails against the expected pubkey. */
    public function test_signature_from_wrong_key_fails(): void {
        $bytes = random_bytes(2048);
        $other = sodium_crypto_sign_keypair();
        $manifest = [
            'sha256'    => hash('sha256', $bytes),
            'signature' => base64_encode(
                sodium_crypto_sign_detached($bytes, sodium_crypto_sign_secretkey($other))
            ),
        ];
        // Verify against OUR test pubkey, not the one that signed it.
        $this->assertFalse(
            Peanut_Connect_Self_Updater::verify_bytes($bytes, $manifest, $this->pub_b64)
        );
    }

    /** Garbage (non-base64 / wrong-length) signature is rejected, not fatal. */
    public function test_malformed_signature_fails_gracefully(): void {
        $bytes = random_bytes(2048);
        $manifest = [
            'sha256'    => hash('sha256', $bytes),
            'signature' => base64_encode('too-short'),
        ];
        $this->assertFalse(
            Peanut_Connect_Self_Updater::verify_bytes($bytes, $manifest, $this->pub_b64)
        );
    }

    /**
     * The production default key is a real, well-formed Ed25519 public key
     * (32 bytes) — a garbled constant would silently fail every real update.
     */
    public function test_embedded_production_pubkey_is_wellformed(): void {
        $ref = new ReflectionClass(Peanut_Connect_Self_Updater::class);
        $pub_b64 = $ref->getConstant('PEANUT_SIGNING_PUBKEY');
        $raw = base64_decode((string) $pub_b64, true);
        $this->assertNotFalse($raw, 'pubkey must be valid base64');
        $this->assertSame(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, strlen($raw));
    }
}
