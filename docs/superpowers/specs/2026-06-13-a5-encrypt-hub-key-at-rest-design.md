# A5 — Encrypt the Hub API key at rest

> **Status:** Approved design (brainstorm complete) · **Target version:** 3.13.0
> **Source:** Deferred item A5 from `docs/audits/2026-06-11-hub-consumer-microscope-remediation.md`
> **Repo:** PEANUT-CONNECT (`peanut-connect`)

## Problem

The Hub site key is stored in `wp_options` as `peanut_connect_hub_api_key` in **plaintext**. Anyone who can read the database — SQL injection elsewhere on the site, a compromised plugin, phpMyAdmin/cPanel on shared hosting, or a stolen DB backup — obtains the live, long-lived credential that authenticates all Hub↔edge traffic.

The key **cannot be hashed**: it is the HMAC shared secret (`hash_hmac('sha256', $canonical, $key)` in `Peanut_Connect_Auth::compute_request_signature()`), so it must be recoverable to verify a signature. The fix is therefore **encryption at rest** with decrypt-on-use, using a key that does **not** live in the database — so a DB-only compromise no longer yields a usable Hub key.

## Goal / success criteria

- A database-only read (no filesystem access) cannot recover a usable Hub key.
- HMAC signing and all existing Hub↔edge auth continue to work unchanged.
- Existing paired sites migrate transparently with **zero downtime** and no admin action.
- No new fatal-error surface: any failure degrades to "behaves as un-paired," never a 500 or broken request path.

## Non-goals

- A separate "verification token distinct from the signing secret" (full credential redesign) — unnecessary; encryption-at-rest with decrypt-on-use meets the goal.
- A8b (defaulting `require_signed_requests` on) — a separate operational track.
- Re-keying / scheme rotation tooling beyond the version tag that makes future rotation possible.

## Decisions (locked during brainstorming)

| Decision | Choice | Rationale |
|---|---|---|
| Encryption-key source | Derive from WP's wp-config salts via `hash_hkdf` | Lives in `wp-config.php` (filesystem), not the DB; zero admin setup; works on every install automatically. |
| Behavior on decrypt failure (salt rotated / corrupted) | Behave as un-paired (accessor returns `''`) **+** a dismissible admin notice prompting re-pair | No broken auth, no fatal errors; clear recovery path. |
| Rollout shape | **Two PRs** — accessor refactor first (no behavior change), then encryption | The invasive 24-site change and the crypto change land separately; cleanest bisect for a credential path that has no WP-boot integration harness. |

## Architecture

### Components

**`Peanut_Connect_Secret`** — new, small crypto unit (one job, independently testable):

- `encrypt(string $plaintext): string` — returns the stored form (see scheme below).
- `decrypt(string $stored): ?string` — returns the plaintext, or `null` on any failure (wrong key, tampered, corrupt).
- `is_ciphertext(string $stored): bool` — true if the value carries the `enc:v1:` marker.
- `derive_key(): string` (private) — the HKDF key derivation.

**Accessor trio on `Peanut_Connect_Auth`** — the *only* code that touches the `peanut_connect_hub_api_key` option:

- `get_hub_api_key(): string`
- `set_hub_api_key(string $key): bool`
- `clear_hub_api_key(): void`

Every other site in the codebase calls the accessor; no direct `get_option('peanut_connect_hub_api_key')` remains.

### Crypto scheme

- **Key derivation:** `hash_hkdf('sha256', wp_salt('secure_auth'), 32, 'peanut-connect-hub-key-v1')` → 32-byte key. The HKDF `info` string namespaces the subkey so we never use the raw salt directly.
- **Cipher:** `sodium_crypto_secretbox` (authenticated; available in core PHP 8.0, the plugin's floor) with a fresh random 24-byte nonce (`SODIUM_CRYPTO_SECRETBOX_NONCEBYTES`) per encryption.
- **Stored form:** `enc:v1:` + `base64( nonce . ciphertext )`. The `v1` tag distinguishes ciphertext from legacy plaintext and allows a future scheme bump.
- **Graceful degrade:** if `sodium_crypto_secretbox` is somehow unavailable, `encrypt()` returns the plaintext unchanged and logs a one-time warning. Encryption is on-by-default but never fatal.

### Accessor behavior + migration (zero-downtime, self-migrating)

`get_hub_api_key()`:
1. Read the raw option.
2. If empty → return `''`.
3. If `Peanut_Connect_Secret::is_ciphertext()` → `decrypt()`:
   - success → return plaintext;
   - failure → set the `peanut_connect_hub_key_undecryptable` flag option and return `''`.
4. Else (legacy plaintext) → return it as-is **and** opportunistically `set_hub_api_key($value)` so it is re-stored encrypted on first read.

`set_hub_api_key($key)`: `encrypt()` then `update_option()`. Deletes the undecryptable flag.

`clear_hub_api_key()`: `delete_option()` + delete the undecryptable flag.

Existing `!empty($api_key)` existence checks elsewhere continue to work unchanged, because the accessor returns the usable value (or `''`).

### The refactor (PR-A — no behavior change)

Replace every direct access to the option with the accessor. In PR-A the accessor is a **thin passthrough** (no crypto yet) so the diff is purely mechanical and green-verifiable.

Call sites (from `grep`, to be re-confirmed at implementation time):

- **Reads (~21):** `class-connect-auth.php` (verify path), `class-connect-hub-sync.php` ×3, `class-connect-forms.php` ×2, `class-connect-marketing.php` ×2, `class-connect-tracker.php`, `class-connect-videos.php`, `class-connect-popup-display.php`, `class-connect-short-links.php`, `class-connect-event-banner.php`, `class-connect-api.php` ×6, `peanut-connect.php`.
- **Writes (3):** `class-connect-api.php:1063`, `:1159`, `:2088`.
- **Delete (1):** `class-connect-api.php:1256`.

### Admin notice

A dismissible `admin_notices` notice rendered when `peanut_connect_hub_key_undecryptable` is set: *"Your Hub connection needs to be re-paired after a security-key change,"* linking to the Connect settings page. Cleared automatically on the next successful `set_hub_api_key()` / `clear_hub_api_key()`.

## Testing

Pure, runnable in the existing standalone (mock-backed) suite:

- `encrypt()` → `decrypt()` round-trip returns the original.
- `is_ciphertext()` correctly identifies `enc:v1:` vs legacy plaintext.
- Tampered/truncated ciphertext → `decrypt()` returns `null`.
- Legacy plaintext passes through `get_hub_api_key()` unchanged (and triggers re-encrypt).
- Key derivation is deterministic for a fixed salt and changes with the salt.

Add a `wp_salt()` mock to `tests/mocks/wordpress-mocks.php` (options/transients already mocked).

**Integration-test gap (explicit):** this repo has no WordPress-boot harness, so the accessor's WP wiring is covered by (a) the pure crypto core being fully unit-tested and (b) the existing suite staying green after the refactor. PR-A's passthrough-only nature is what makes this acceptable — the mechanical refactor lands and is verified before any crypto behavior changes.

## Phasing

- **PR-A** — branch `fix/connect-key-accessor`: accessor trio (passthrough) + call-site refactor. Ship, CI green. Purely mechanical; trivially reviewable.
- **PR-B** — branch `fix/connect-key-encrypt` (stacked on A): `Peanut_Connect_Secret` + wire into the accessor + migration + admin notice + tests + CHANGELOG/version bump → 3.13.0.

## Risks & mitigations

- **A missed call site** keeps a plaintext read/write outside the accessor → caught by the PR-A grep audit (zero remaining direct `get_option`/`update_option`/`delete_option` of the key) and by review of a no-logic diff.
- **Salt rotation loses the key** → by design: behave as un-paired + admin notice → re-pair. Documented for operators.
- **sodium unavailable** → graceful degrade to plaintext + warning; never fatal.
- **Backup/restore** carries the (now-encrypted) option; a backup restored onto a host with different salts will surface the re-pair notice — acceptable and consistent with the salt-rotation behavior.
