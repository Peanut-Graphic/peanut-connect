# A5 — Encrypt the Hub API Key at Rest — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop storing the Hub site key (`peanut_connect_hub_api_key`) in plaintext — encrypt it at rest with a key derived from WP's wp-config salts, so a database-only compromise cannot recover a usable Hub key, while HMAC signing and all existing auth keep working.

**Architecture:** A small `Peanut_Connect_Secret` crypto unit (libsodium secretbox, key derived via `hash_hkdf` from `wp_salt('secure_auth')`) sits behind a centralized accessor trio (`Peanut_Connect_Auth::get_hub_api_key()` / `set_hub_api_key()` / `clear_hub_api_key()`). Every read/write of the option goes through the accessor. Legacy plaintext keys migrate transparently on first read; a key that can no longer be decrypted (salt rotation) degrades to "un-paired" plus a dismissible admin notice. Shipped in two phases: a no-behavior-change accessor refactor (PR-A), then the encryption itself (PR-B).

**Tech Stack:** PHP 8.0+ (WordPress plugin), libsodium (`sodium_crypto_secretbox`, core in PHP 8), `hash_hkdf`, PHPUnit 9.6 with the repo's standalone WP mocks (`tests/mocks/wordpress-mocks.php`), conventional-commit message hook (allowed types include `feat`, `fix`, `refactor`, `test`, `security`, `chore`, `docs`).

**Spec:** `docs/superpowers/specs/2026-06-13-a5-encrypt-hub-key-at-rest-design.md`

---

## Preconditions

- Work from a clean checkout of `origin/main` (currently v3.12.0).
- `composer install` has been run (the repo needs `vendor/`); run tests with `./vendor/bin/phpunit --configuration phpunit.xml`.
- The commit-msg hook rejects non-conventional types (e.g. `a11y` is invalid). Use the types shown in each commit step verbatim.

## File Structure

| File | PR | Responsibility |
|---|---|---|
| `includes/class-connect-secret.php` | B (create) | Crypto unit: encrypt/decrypt/is_ciphertext + key derivation. |
| `includes/class-connect-auth.php` | A (modify), B (modify) | Hosts the accessor trio. PR-A: passthrough. PR-B: encrypt/decrypt + migration + undecryptable flag. |
| `peanut-connect.php` | A (modify writes), B (modify) | Loads the Secret class; registers the admin notice; one read call site. Version bump in B. |
| `includes/class-connect-*.php` (10 files) | A (modify) | Replace direct option reads with the accessor. |
| `tests/Test_Secret.php` | B (create) | Pure crypto unit tests. |
| `tests/Test_Hub_Key_Accessor.php` | B (create) | Accessor round-trip / migration / decrypt-failure tests. |
| `tests/mocks/wordpress-mocks.php` | B (modify) | Add a `wp_salt()` mock. |
| `CHANGELOG.md`, `readme.txt` | B (modify) | 3.13.0 entry + stable tag. |

---

# PHASE A — Accessor refactor (no behavior change) → branch `fix/connect-key-accessor`

Goal of Phase A: introduce the accessor as a **thin passthrough** and route every direct option access through it. The returned value is still the raw stored string, so behavior is identical and CI must stay green. This isolates the invasive 24-site change from the crypto change.

### Task A1: Create branch

- [ ] **Step 1: Branch off origin/main**

```bash
cd <repo-root>
git fetch origin
git checkout -b fix/connect-key-accessor origin/main
```

- [ ] **Step 2: Confirm green baseline**

Run: `./vendor/bin/phpunit --configuration phpunit.xml`
Expected: `OK` (e.g. `Tests: 82, ... Skipped: 1`) — note the exact count to compare against after the refactor.

---

### Task A2: Add the passthrough accessor trio to `Peanut_Connect_Auth`

**Files:**
- Modify: `includes/class-connect-auth.php` (add three static methods; place them right after the existing `get_permissions()` method, before `permission_callback()`).

- [ ] **Step 1: Add the accessor methods**

Insert into the `Peanut_Connect_Auth` class:

```php
    /**
     * Centralized accessor for the Hub API key option. ALL reads/writes of
     * peanut_connect_hub_api_key go through these three methods so the at-rest
     * representation (Phase B: encrypted) lives in exactly one place.
     *
     * Phase A: thin passthrough — no behavior change.
     */
    public static function get_hub_api_key(): string {
        return (string) get_option('peanut_connect_hub_api_key', '');
    }

    public static function set_hub_api_key(string $key): bool {
        return (bool) update_option('peanut_connect_hub_api_key', $key);
    }

    public static function clear_hub_api_key(): void {
        delete_option('peanut_connect_hub_api_key');
    }
```

- [ ] **Step 2: Lint**

Run: `php -l includes/class-connect-auth.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add includes/class-connect-auth.php
git commit -m "refactor: add centralized Hub-API-key accessor (passthrough)

Introduces Peanut_Connect_Auth::get_hub_api_key()/set_hub_api_key()/
clear_hub_api_key() as the single point of access for the
peanut_connect_hub_api_key option. Passthrough only in this phase; Phase B
makes it encrypt/decrypt at rest. Refs A5."
```

---

### Task A3: Route the WRITE + DELETE sites through the accessor

**Files:**
- Modify: `includes/class-connect-api.php` (lines ~1063, ~1159, ~2088 writes; ~1256 delete — re-confirm by grep, line numbers drift).

- [ ] **Step 1: Find the exact sites**

Run:
```bash
grep -n "update_option('peanut_connect_hub_api_key'\|delete_option('peanut_connect_hub_api_key'" includes/class-connect-api.php
```
Expected: three `update_option` lines and one `delete_option` line.

- [ ] **Step 2: Replace each write**

Transform (apply to all three update_option sites, preserving the surrounding expression):

```php
// BEFORE: $key_saved = update_option('peanut_connect_hub_api_key', $api_key);
$key_saved = Peanut_Connect_Auth::set_hub_api_key($api_key);

// BEFORE: update_option('peanut_connect_hub_api_key', $api_key);
Peanut_Connect_Auth::set_hub_api_key($api_key);

// BEFORE: update_option('peanut_connect_hub_api_key', sanitize_text_field($api_key));
Peanut_Connect_Auth::set_hub_api_key(sanitize_text_field($api_key));
```

Replace the delete:

```php
// BEFORE: delete_option('peanut_connect_hub_api_key');
Peanut_Connect_Auth::clear_hub_api_key();
```

- [ ] **Step 3: Verify no direct write/delete remains**

Run:
```bash
grep -n "update_option('peanut_connect_hub_api_key'\|delete_option('peanut_connect_hub_api_key'" includes/ peanut-connect.php uninstall.php
```
Expected: NO matches (uninstall.php deletes by `peanut_connect_%` prefix, not by exact name — that is fine and unchanged).

- [ ] **Step 4: Lint + test**

Run: `php -l includes/class-connect-api.php && ./vendor/bin/phpunit --configuration phpunit.xml`
Expected: `No syntax errors detected`, and the same `OK` count as Task A1 Step 2.

- [ ] **Step 5: Commit**

```bash
git add includes/class-connect-api.php
git commit -m "refactor: route Hub-key writes/delete through the accessor

Refs A5 (Phase A)."
```

---

### Task A4: Route all READ sites through the accessor

**Files (re-confirm with grep — these are the known reads):**
- `includes/class-connect-auth.php` (the verify path, ~line 266)
- `includes/class-connect-hub-sync.php` (×3)
- `includes/class-connect-forms.php` (×2)
- `includes/class-connect-marketing.php` (×2)
- `includes/class-connect-tracker.php`
- `includes/class-connect-videos.php`
- `includes/class-connect-popup-display.php`
- `includes/class-connect-short-links.php`
- `includes/class-connect-event-banner.php`
- `includes/class-connect-api.php` (×6 reads — NOT the writes already done)
- `peanut-connect.php` (×1)

- [ ] **Step 1: List every remaining read**

Run:
```bash
grep -rn "get_option('peanut_connect_hub_api_key'" includes/ peanut-connect.php
```
Expected: ~21 lines.

- [ ] **Step 2: Replace each read**

Replace each occurrence, dropping any `(string)` cast and default (the accessor already returns a string):

```php
// BEFORE: $api_key = get_option('peanut_connect_hub_api_key');
// BEFORE: $api_key = (string) get_option('peanut_connect_hub_api_key', '');
$api_key = Peanut_Connect_Auth::get_hub_api_key();

// Inline existence-check form, BEFORE:
//   $connected = $hub_url !== '' && get_option('peanut_connect_hub_api_key', '') !== '';
// AFTER:
$connected = $hub_url !== '' && Peanut_Connect_Auth::get_hub_api_key() !== '';

// Inside Peanut_Connect_Auth itself (class-connect-auth.php), use self:::
//   BEFORE: $stored_key = (string) get_option('peanut_connect_hub_api_key', '');
$stored_key = self::get_hub_api_key();
```

- [ ] **Step 3: Verify no call site relied on the `false` sentinel**

Run:
```bash
grep -rn "peanut_connect_hub_api_key" includes/ peanut-connect.php | grep -iE "=== false|!== false"
```
Expected: NO matches. (If any appear, that site compared against `get_option`'s `false` default; rewrite it to `=== ''` / `!== ''`.)

- [ ] **Step 4: Verify ZERO direct accesses remain anywhere**

Run:
```bash
grep -rn "_option('peanut_connect_hub_api_key'\|_option(\"peanut_connect_hub_api_key\"" includes/ peanut-connect.php
```
Expected: matches ONLY inside the three accessor methods in `includes/class-connect-auth.php`. No other file may touch the option directly.

- [ ] **Step 5: Lint changed files + full test**

Run:
```bash
for f in includes/class-connect-auth.php includes/class-connect-hub-sync.php includes/class-connect-forms.php includes/class-connect-marketing.php includes/class-connect-tracker.php includes/class-connect-videos.php includes/class-connect-popup-display.php includes/class-connect-short-links.php includes/class-connect-event-banner.php includes/class-connect-api.php peanut-connect.php; do php -l "$f"; done
./vendor/bin/phpunit --configuration phpunit.xml
```
Expected: every file `No syntax errors detected`; tests `OK` with the same count as Task A1 Step 2.

- [ ] **Step 6: Commit**

```bash
git add includes/ peanut-connect.php
git commit -m "refactor: route all Hub-key reads through the accessor

Every read of peanut_connect_hub_api_key now goes through
Peanut_Connect_Auth::get_hub_api_key(); no file touches the option
directly except the accessor. No behavior change. Refs A5 (Phase A)."
```

- [ ] **Step 7: Push + open PR-A**

```bash
git push -u origin fix/connect-key-accessor
gh pr create --repo Peanut-Graphic/peanut-connect --base main \
  --title "refactor: centralize Hub-API-key access (A5 phase A)" \
  --body "No-behavior-change refactor: all reads/writes/delete of peanut_connect_hub_api_key now go through Peanut_Connect_Auth::get/set/clear_hub_api_key(). Foundation for at-rest encryption (PR-B). Refs spec docs/superpowers/specs/2026-06-13-a5-encrypt-hub-key-at-rest-design.md."
```

- [ ] **Step 8: Wait for CI green, then merge PR-A before starting Phase B**

Run: `gh pr checks <PR-A-number> --repo Peanut-Graphic/peanut-connect`
Expected: all checks pass. Merge with `gh pr merge <PR-A-number> --repo Peanut-Graphic/peanut-connect --merge --delete-branch`.

---

# PHASE B — Encryption + migration + notice → branch `fix/connect-key-encrypt`

### Task B1: Create branch off the merged main

- [ ] **Step 1: Branch**

```bash
git fetch origin
git checkout -b fix/connect-key-encrypt origin/main
```

---

### Task B2: Add the `wp_salt()` mock

**Files:**
- Modify: `tests/mocks/wordpress-mocks.php` (add near the existing `wp_parse_url` mock, before the `// Initialize mock storage.` line).

- [ ] **Step 1: Add the mock**

```php
if (!function_exists('wp_salt')) {
    function wp_salt($scheme = 'auth') {
        // Tests can override via $GLOBALS to simulate salt rotation.
        return $GLOBALS['mock_wp_salt'] ?? ('peanut-connect-test-salt-' . $scheme);
    }
}
```

- [ ] **Step 2: Lint**

Run: `php -l tests/mocks/wordpress-mocks.php`
Expected: `No syntax errors detected`

---

### Task B3: Create `Peanut_Connect_Secret` (TDD)

**Files:**
- Create: `tests/Test_Secret.php`
- Create: `includes/class-connect-secret.php`

- [ ] **Step 1: Write the failing test**

`tests/Test_Secret.php`:

```php
<?php
/**
 * Tests for at-rest encryption of the Hub key (A5).
 *
 * @package Peanut_Connect
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/class-connect-secret.php';

class Test_Secret extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['mock_wp_salt'] = 'fixed-salt-for-tests';
    }

    protected function tearDown(): void {
        unset($GLOBALS['mock_wp_salt']);
        parent::tearDown();
    }

    public function test_round_trip(): void {
        $plain = 'hub-key-abc123-XYZ';
        $stored = Peanut_Connect_Secret::encrypt($plain);
        $this->assertStringStartsWith('enc:v1:', $stored);
        $this->assertNotSame($plain, $stored);
        $this->assertSame($plain, Peanut_Connect_Secret::decrypt($stored));
    }

    public function test_is_ciphertext(): void {
        $stored = Peanut_Connect_Secret::encrypt('k');
        $this->assertTrue(Peanut_Connect_Secret::is_ciphertext($stored));
        $this->assertFalse(Peanut_Connect_Secret::is_ciphertext('plain-legacy-key'));
        $this->assertFalse(Peanut_Connect_Secret::is_ciphertext(''));
    }

    public function test_decrypt_of_plaintext_returns_null(): void {
        $this->assertNull(Peanut_Connect_Secret::decrypt('not-ciphertext'));
    }

    public function test_decrypt_of_tampered_returns_null(): void {
        $stored = Peanut_Connect_Secret::encrypt('secret');
        $tampered = substr($stored, 0, -4) . 'AAAA';
        $this->assertNull(Peanut_Connect_Secret::decrypt($tampered));
    }

    public function test_different_salt_cannot_decrypt(): void {
        $stored = Peanut_Connect_Secret::encrypt('secret');
        $GLOBALS['mock_wp_salt'] = 'a-different-salt-after-rotation';
        $this->assertNull(Peanut_Connect_Secret::decrypt($stored));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/phpunit --configuration phpunit.xml --filter Test_Secret`
Expected: FAIL — `Class "Peanut_Connect_Secret" not found`.

- [ ] **Step 3: Implement `Peanut_Connect_Secret`**

`includes/class-connect-secret.php`:

```php
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
        sodium_memzero($key);
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
            sodium_memzero($key);
            return null;
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
        sodium_memzero($key);
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
```

- [ ] **Step 4: Run to verify it passes**

Run: `./vendor/bin/phpunit --configuration phpunit.xml --filter Test_Secret`
Expected: `OK (5 tests, ...)`

- [ ] **Step 5: Commit**

```bash
git add includes/class-connect-secret.php tests/Test_Secret.php tests/mocks/wordpress-mocks.php
git commit -m "security: add Peanut_Connect_Secret for at-rest key encryption

libsodium secretbox under a key derived (hash_hkdf) from wp_salt, stored
as enc:v1:base64(nonce.ciphertext). Decrypt returns null on any failure;
degrades to plaintext if sodium/salt unavailable. Refs A5 (Phase B)."
```

---

### Task B4: Load the Secret class

**Files:**
- Modify: `peanut-connect.php` (in `load_dependencies()`, add the require right before the `class-connect-auth.php` require).

- [ ] **Step 1: Add the require**

```php
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-secret.php';
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-auth.php';
```

(The first line is new; the second already exists — add the new line immediately above it.)

- [ ] **Step 2: Lint**

Run: `php -l peanut-connect.php`
Expected: `No syntax errors detected`

---

### Task B5: Wire encryption + migration + undecryptable flag into the accessor (TDD)

**Files:**
- Create: `tests/Test_Hub_Key_Accessor.php`
- Modify: `includes/class-connect-auth.php` (replace the three passthrough accessor bodies from Phase A).

- [ ] **Step 1: Write the failing test**

`tests/Test_Hub_Key_Accessor.php`:

```php
<?php
/**
 * Tests for the encrypting Hub-key accessor: round-trip, transparent
 * migration of legacy plaintext, and decrypt-failure handling (A5).
 *
 * @package Peanut_Connect
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/class-connect-secret.php';
require_once dirname(__DIR__) . '/includes/class-connect-auth.php';

class Test_Hub_Key_Accessor extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        global $mock_options;
        $mock_options = [];
        $GLOBALS['mock_wp_salt'] = 'fixed-salt-for-tests';
    }

    protected function tearDown(): void {
        unset($GLOBALS['mock_wp_salt']);
        parent::tearDown();
    }

    public function test_set_then_get_round_trips(): void {
        Peanut_Connect_Auth::set_hub_api_key('my-hub-key');
        $this->assertSame('my-hub-key', Peanut_Connect_Auth::get_hub_api_key());
    }

    public function test_value_is_stored_encrypted_not_plaintext(): void {
        Peanut_Connect_Auth::set_hub_api_key('my-hub-key');
        $raw = get_option('peanut_connect_hub_api_key');
        $this->assertStringStartsWith('enc:v1:', (string) $raw);
        $this->assertStringNotContainsString('my-hub-key', (string) $raw);
    }

    public function test_legacy_plaintext_is_returned_and_migrated_on_read(): void {
        // Simulate an existing install: raw plaintext stored directly.
        update_option('peanut_connect_hub_api_key', 'legacy-plain-key');
        $this->assertSame('legacy-plain-key', Peanut_Connect_Auth::get_hub_api_key());
        // After the read, it must be re-stored encrypted.
        $this->assertStringStartsWith('enc:v1:', (string) get_option('peanut_connect_hub_api_key'));
    }

    public function test_empty_when_unset(): void {
        $this->assertSame('', Peanut_Connect_Auth::get_hub_api_key());
    }

    public function test_undecryptable_returns_empty_and_sets_flag(): void {
        Peanut_Connect_Auth::set_hub_api_key('my-hub-key');
        // Salt rotates — the stored ciphertext can no longer be decrypted.
        $GLOBALS['mock_wp_salt'] = 'rotated-salt';
        $this->assertSame('', Peanut_Connect_Auth::get_hub_api_key());
        $this->assertNotEmpty(get_option('peanut_connect_hub_key_undecryptable'));
    }

    public function test_set_clears_undecryptable_flag(): void {
        update_option('peanut_connect_hub_key_undecryptable', 1);
        Peanut_Connect_Auth::set_hub_api_key('fresh-key');
        $this->assertFalse((bool) get_option('peanut_connect_hub_key_undecryptable', false));
    }

    public function test_clear_removes_key_and_flag(): void {
        Peanut_Connect_Auth::set_hub_api_key('my-hub-key');
        update_option('peanut_connect_hub_key_undecryptable', 1);
        Peanut_Connect_Auth::clear_hub_api_key();
        $this->assertSame('', Peanut_Connect_Auth::get_hub_api_key());
        $this->assertFalse((bool) get_option('peanut_connect_hub_key_undecryptable', false));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/phpunit --configuration phpunit.xml --filter Test_Hub_Key_Accessor`
Expected: FAIL — `test_value_is_stored_encrypted_not_plaintext` fails (Phase A passthrough stores plaintext), `test_undecryptable_*` fails (no flag set).

- [ ] **Step 3: Replace the accessor bodies**

In `includes/class-connect-auth.php`, replace the three passthrough methods from Phase A with:

```php
    public static function get_hub_api_key(): string {
        $stored = (string) get_option('peanut_connect_hub_api_key', '');
        if ($stored === '') {
            return '';
        }
        if (Peanut_Connect_Secret::is_ciphertext($stored)) {
            $plain = Peanut_Connect_Secret::decrypt($stored);
            if ($plain === null) {
                // Salt rotated or ciphertext corrupt — behave as un-paired and
                // flag for the admin re-pair notice. Never fatal.
                update_option('peanut_connect_hub_key_undecryptable', 1);
                return '';
            }
            return $plain;
        }
        // Legacy plaintext: return it, and migrate to encrypted on this read.
        self::set_hub_api_key($stored);
        return $stored;
    }

    public static function set_hub_api_key(string $key): bool {
        $ok = (bool) update_option('peanut_connect_hub_api_key', Peanut_Connect_Secret::encrypt($key));
        delete_option('peanut_connect_hub_key_undecryptable');
        return $ok;
    }

    public static function clear_hub_api_key(): void {
        delete_option('peanut_connect_hub_api_key');
        delete_option('peanut_connect_hub_key_undecryptable');
    }
```

- [ ] **Step 4: Run to verify it passes**

Run: `./vendor/bin/phpunit --configuration phpunit.xml --filter Test_Hub_Key_Accessor`
Expected: `OK (7 tests, ...)`

- [ ] **Step 5: Full suite (no regressions)**

Run: `./vendor/bin/phpunit --configuration phpunit.xml`
Expected: `OK` — count = Phase A count + 12 (5 Secret + 7 accessor).

- [ ] **Step 6: Commit**

```bash
git add includes/class-connect-auth.php tests/Test_Hub_Key_Accessor.php
git commit -m "security: encrypt Hub key at rest via the accessor + migrate on read

get_hub_api_key() now decrypts (flagging un-decryptable values for an
admin re-pair notice), set_ encrypts, and legacy plaintext migrates
transparently on first read. Refs A5 (Phase B)."
```

---

### Task B6: Add the re-pair admin notice

**Files:**
- Modify: `peanut-connect.php` (register the hook in `init_hooks()`; add the handler method).

- [ ] **Step 1: Find the settings page slug**

Run: `grep -n "add_menu_page\|add_submenu_page\|add_options_page" peanut-connect.php`
Expected: one call whose menu-slug argument is the settings page slug (referred to below as `<SETTINGS_SLUG>` — use the literal value found, e.g. `peanut-connect`).

- [ ] **Step 2: Register the hook**

In `init_hooks()`, after the existing `add_action('admin_notices', ...)` (or near the other `admin_*` hooks), add:

```php
        add_action('admin_notices', [$this, 'maybe_show_rekey_notice']);
```

- [ ] **Step 3: Add the handler**

Add this method to the `Peanut_Connect` class (use the slug from Step 1 in `$url`):

```php
    /**
     * Warn admins that the stored Hub key can no longer be decrypted (e.g.
     * after WP security-key/salt rotation) and must be re-paired. The flag is
     * set by Peanut_Connect_Auth::get_hub_api_key() on decrypt failure and
     * cleared on the next successful set/clear.
     */
    public function maybe_show_rekey_notice(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (!get_option('peanut_connect_hub_key_undecryptable')) {
            return;
        }
        $url = admin_url('admin.php?page=<SETTINGS_SLUG>');
        printf(
            '<div class="notice notice-warning is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
            esc_html__('Peanut End to End: your Hub connection needs to be re-paired after a security-key change.', 'peanut-connect'),
            esc_url($url),
            esc_html__('Re-pair now', 'peanut-connect')
        );
    }
```

- [ ] **Step 4: Lint + full suite**

Run: `php -l peanut-connect.php && ./vendor/bin/phpunit --configuration phpunit.xml`
Expected: `No syntax errors detected`; tests `OK` (same count as B5 Step 5).

- [ ] **Step 5: Commit**

```bash
git add peanut-connect.php
git commit -m "feat: admin notice prompting Hub re-pair after key becomes undecryptable

Refs A5 (Phase B)."
```

---

### Task B7: Version bump + changelog

**Files:**
- Modify: `peanut-connect.php` (header `Version:` and `define('PEANUT_CONNECT_VERSION', ...)`)
- Modify: `readme.txt` (`Stable tag:`)
- Modify: `CHANGELOG.md` (new top entry)

- [ ] **Step 1: Bump version 3.12.0 → 3.13.0**

```bash
sed -i '' "s/ \* Version: 3.12.0/ * Version: 3.13.0/" peanut-connect.php
sed -i '' "s/define('PEANUT_CONNECT_VERSION', '3.12.0');/define('PEANUT_CONNECT_VERSION', '3.13.0');/" peanut-connect.php
sed -i '' "s/^Stable tag: 3.12.0/Stable tag: 3.13.0/" readme.txt
```
(If the current version is not 3.12.0, substitute the actual current value.)

- [ ] **Step 2: Add the CHANGELOG entry**

Insert below the `## [Semantic Versioning]...` intro, above the most recent entry:

```markdown
## [3.13.0] - 2026-06-13

### Security
- **Hub API key is now encrypted at rest** (A5). `peanut_connect_hub_api_key` was stored in plaintext; it is now encrypted with libsodium secretbox under a key derived from WP's wp-config salts (`hash_hkdf` over `wp_salt`), so a database-only compromise can no longer recover a usable Hub key. The key remains usable as the HMAC signing secret via decrypt-on-use. All access is funnelled through a single `Peanut_Connect_Auth::get/set/clear_hub_api_key()` accessor. Existing plaintext keys migrate transparently on first read. If the key can no longer be decrypted (e.g. after a WP security-key/salt rotation) the site behaves as un-paired and shows a dismissible admin notice prompting re-pair — never a fatal error.
```

- [ ] **Step 3: Lint + full suite**

Run: `php -l peanut-connect.php && ./vendor/bin/phpunit --configuration phpunit.xml`
Expected: `No syntax errors detected`; tests `OK`.

- [ ] **Step 4: Commit**

```bash
git add peanut-connect.php readme.txt CHANGELOG.md
git commit -m "chore(release): 3.13.0 — Hub key encrypted at rest (A5)"
```

---

### Task B8: Push, open PR-B, verify green

- [ ] **Step 1: Push + PR**

```bash
git push -u origin fix/connect-key-encrypt
gh pr create --repo Peanut-Graphic/peanut-connect --base main \
  --title "security: encrypt the Hub API key at rest (A5 phase B) — 3.13.0" \
  --body "Wires Peanut_Connect_Secret into the accessor from PR-A: the Hub key is now encrypted at rest (libsodium + salt-derived key), legacy plaintext migrates on read, and an undecryptable key (salt rotation) degrades to un-paired + an admin re-pair notice. Tests: Test_Secret + Test_Hub_Key_Accessor. Spec: docs/superpowers/specs/2026-06-13-a5-encrypt-hub-key-at-rest-design.md. Closes the A5 deferral."
```

- [ ] **Step 2: Confirm CI green**

Run: `gh pr checks <PR-B-number> --repo Peanut-Graphic/peanut-connect`
Expected: all checks pass (php-tests, frontend-tests, wp-contract, static-and-security, Accessibility Tests).

- [ ] **Step 3: Update the remediation doc**

In `docs/audits/2026-06-11-hub-consumer-microscope-remediation.md`, move A5 from "Still deferred" to done (note it shipped in 3.13.0 via this plan). Commit:

```bash
git add docs/audits/2026-06-11-hub-consumer-microscope-remediation.md
git commit -m "docs: mark A5 (encrypt key at rest) shipped in 3.13.0"
git push
```

---

## Self-Review (completed by plan author)

**Spec coverage:**
- Encryption-key from wp-config salts → Task B3 `derive_key()` (`hash_hkdf` over `wp_salt('secure_auth')`). ✔
- `sodium_crypto_secretbox`, `enc:v1:` + base64(nonce.cipher), graceful degrade → Task B3. ✔
- Accessor trio, only code touching the option → Tasks A2/A4/A4-step4 (grep proves zero direct access). ✔
- Decrypt-failure → un-paired + flag + admin notice → Tasks B5 + B6. ✔
- Legacy plaintext migrate-on-read → Task B5 `get_hub_api_key()`. ✔
- All call sites refactored (~21 reads / 3 writes / 1 delete) → Tasks A3/A4. ✔
- Tests (round-trip, marker, tamper, legacy passthrough, salt-rotation) → Tasks B3/B5; `wp_salt` mock → B2. ✔
- Two-PR phasing → Phase A (merge before B), Phase B stacked on merged main. ✔
- Version bump 3.13.0 + changelog → Task B7. ✔

**Placeholder scan:** `<SETTINGS_SLUG>` in Task B6 is resolved by Step 1 (grep) before use — a lookup instruction, not an unfilled blank. No TBD/TODO. ✔

**Type/name consistency:** `get_hub_api_key`/`set_hub_api_key`/`clear_hub_api_key`, `Peanut_Connect_Secret::encrypt/decrypt/is_ciphertext`, option `peanut_connect_hub_key_undecryptable`, marker `enc:v1:` — used identically across all tasks. ✔
