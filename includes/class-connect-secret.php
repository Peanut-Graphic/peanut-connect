<?php
/**
 * At-rest encryption for the Hub API key.
 *
 * The key is encrypted with libsodium secretbox under a key DERIVED from WP's
 * wp-config salts (hash_hkdf over wp_salt('secure_auth')). The salts live in
 * wp-config.php on the filesystem, not the database, so a database-only
 * compromise cannot recover a usable Hub key. Stored form: "enc:v1:" followed
 * by base64(nonce . ciphertext).
 *
 * @package Peanut_Connect
 */

if (!defined('ABSPATH')) {
    exit;
}

class Peanut_Connect_Secret {

    private const PREFIX = 'enc:v1:';
    private const HKDF_INFO = 'peanut-connect-hub-key-v1';

    /** Does the stored value carry our ciphertext marker? */
    public static function is_ciphertext(string $stored): bool {
        return str_starts_with($stored, self::PREFIX);
    }

    /**
     * Encrypt plaintext for storage. Degrades to returning the plaintext
     * unchanged (with a logged warning) if libsodium or the salt is
     * unavailable — encryption is on-by-default but never fatal.
     */
    public static function encrypt(string $plaintext): string {
        $key = self::derive_key();
        if ($key === null || !function_exists('sodium_crypto_secretbox')) {
            error_log('Peanut Connect: encryption unavailable; storing Hub key unencrypted.');
            return $plaintext;
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);
        if (extension_loaded('sodium')) { sodium_memzero($key); }
        return self::PREFIX . base64_encode($nonce . $cipher);
    }

    /**
     * Decrypt a stored value. Returns null on ANY failure (not ciphertext,
     * wrong key after salt rotation, corruption, truncation).
     */
    public static function decrypt(string $stored): ?string {
        if (!self::is_ciphertext($stored) || !function_exists('sodium_crypto_secretbox_open')) {
            return null;
        }
        $key = self::derive_key();
        if ($key === null) {
            return null;
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            if (extension_loaded('sodium')) { sodium_memzero($key); }
            return null;
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
        if (extension_loaded('sodium')) { sodium_memzero($key); }
        return $plain === false ? null : $plain;
    }

    /**
     * Derive the 32-byte encryption key from WP's secure-auth salt. Returns
     * null if no salt is available (hash_hkdf rejects empty IKM).
     */
    private static function derive_key(): ?string {
        $salt = function_exists('wp_salt') ? (string) wp_salt('secure_auth') : '';
        if ($salt === '') {
            return null;
        }
        return hash_hkdf('sha256', $salt, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, self::HKDF_INFO);
    }
}
