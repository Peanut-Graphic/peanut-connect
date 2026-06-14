# D-12 — Key Rotation & Revocation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Rotate a Hub↔edge site key without re-pairing (proactive, scheduled, or on-demand from either side), with a two-phase confirmed swap that can never lock a site out, plus a hard revocation path for offboarding/incidents.

**Architecture:** A rotation state machine on the Hub `Site` (ACTIVE → PENDING(new) → confirm-promotes / expire-reverts). The edge proposes a new key signed with the OLD key, then confirms with a signed call using the NEW key; the Hub promotes only on that confirmation, so the old key stays valid until the new one is proven working. Revocation drops the key with no overlap; the edge detects the resulting 401 and re-pairs.

**Tech Stack:** peanut-hub (Laravel 12, PHPUnit 11) + peanut-connect (WP plugin PHP 8, PHPUnit 9.6). Reuses D-11 signing (`PeanutConnectSigner` / `Peanut_Connect_Auth::compute_request_signature`) + D-10 protocol header + A5 at-rest encryption.

**Spec:** `docs/superpowers/specs/2026-06-13-d12-key-rotation-revocation-design.md`

**Phasing & deploy order:** Phase 1 (Hub) ships first and is inert until an edge calls it. Phase 2 (edge) uses it. Phase 3 enables the default rotation policy. Each phase = its own PR(s).

---

## File structure

**Hub (peanut-hub):**
- `database/migrations/XXXX_add_key_rotation_to_sites.php` — new columns.
- `app/Models/Site.php` — rotation/revocation methods.
- `app/Http/Controllers/Api/SiteRotationController.php` — `/sites/rotate` + `/sites/rotate/confirm`.
- `app/Http/Middleware/ValidateSiteApiKey.php` — recognize the pending key (so the confirm call authenticates) — minimal touch.
- `routes/api.php` — the two routes.
- `app/Console/Commands/PurgeExpiredKeyRotations.php` + scheduler entry — GC.
- admin: the existing site-detail controller/Blade — "Rotate"/"Revoke" actions.
- `tests/Unit/SiteRotationTest.php`, `tests/Feature/SiteRotationApiTest.php`.

**Edge (peanut-connect):**
- `includes/class-connect-key-rotation.php` — the rotation client (new, one responsibility).
- `includes/class-connect-hub-sync.php` — heartbeat: act on `rotate`; detect revocation 401.
- `includes/class-connect-api.php` — `POST /settings/hub/rotate-key` (admin-triggered).
- `frontend/src/pages/Settings.tsx` + `src/api/endpoints.ts` — "Rotate key" button.
- `tests/Test_Key_Rotation.php`.

---

# PHASE 1 — Hub (peanut-hub) · branch `feat/d12-hub-rotation`

### Task 1: Migration — rotation columns on `sites`

**Files:** Create `database/migrations/2026_06_13_000000_add_key_rotation_to_sites.php`

- [ ] **Step 1: Write the migration**
```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('pending_api_key_hash', 64)->nullable()->after('api_key_hash');
            $table->timestamp('pending_key_expires_at')->nullable()->after('pending_api_key_hash');
            $table->timestamp('key_rotated_at')->nullable()->after('pending_key_expires_at');
            $table->unsignedSmallInteger('key_rotation_interval_days')->nullable()->after('key_rotated_at');
        });
    }
    public function down(): void {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['pending_api_key_hash', 'pending_key_expires_at', 'key_rotated_at', 'key_rotation_interval_days']);
        });
    }
};
```
- [ ] **Step 2: Run it** — `php artisan migrate` → expect the four columns added. Then add them to the `$fillable`/`$casts` in `app/Models/Site.php` (`pending_key_expires_at`, `key_rotated_at` as `datetime`; `key_rotation_interval_days` as `integer`).
- [ ] **Step 3: Commit** — `git add database/migrations app/Models/Site.php && git commit -m "feat(d12): sites key-rotation columns"`

### Task 2: `Site` rotation/revocation methods (TDD)

**Files:** `app/Models/Site.php`, `tests/Unit/SiteRotationTest.php`

- [ ] **Step 1: Failing test** (`tests/Unit/SiteRotationTest.php`, `extends Tests\TestCase`, `use RefreshDatabase`):
```php
<?php
namespace Tests\Unit;
use App\Models\Agency; use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SiteRotationTest extends TestCase {
    use RefreshDatabase;

    private function site(): Site {
        $agency = Agency::factory()->create();
        $s = Site::factory()->for($agency)->create();
        $s->setApiKey('old-key-123'); // existing helper that sets api_key + api_key_hash
        return $s->fresh();
    }

    #[Test] public function propose_records_pending_without_touching_active(): void {
        $s = $this->site();
        $s->proposeKeyRotation(hash('sha256', 'new-key-456'), 15);
        $this->assertSame(hash('sha256','old-key-123'), $s->api_key_hash, 'active unchanged');
        $this->assertSame(hash('sha256','new-key-456'), $s->pending_api_key_hash);
        $this->assertTrue($s->pending_key_expires_at->isFuture());
    }

    #[Test] public function promote_swaps_active_to_pending_and_clears_it(): void {
        $s = $this->site();
        $s->proposeKeyRotation(hash('sha256','new-key-456'), 15);
        $s->promotePendingKey();
        $this->assertSame(hash('sha256','new-key-456'), $s->api_key_hash);
        $this->assertNull($s->pending_api_key_hash);
        $this->assertNotNull($s->key_rotated_at);
    }

    #[Test] public function pending_is_valid_only_before_expiry(): void {
        $s = $this->site();
        $s->proposeKeyRotation(hash('sha256','new-key-456'), 15);
        $this->assertTrue($s->hasValidPendingKey(hash('sha256','new-key-456')));
        $s->pending_key_expires_at = now()->subMinute(); $s->save();
        $this->assertFalse($s->hasValidPendingKey(hash('sha256','new-key-456')));
    }

    #[Test] public function revoke_clears_active_and_pending_and_disconnects(): void {
        $s = $this->site();
        $s->proposeKeyRotation(hash('sha256','new-key-456'), 15);
        $s->revokeKey();
        $this->assertNull($s->pending_api_key_hash);
        $this->assertEmpty($s->api_key_hash);
        $this->assertSame('disconnected', $s->status);
    }
}
```
- [ ] **Step 2: Run → fail** — `php artisan test --filter SiteRotationTest` → methods undefined.
- [ ] **Step 3: Implement** in `app/Models/Site.php` (use the existing key-hash convention seen at Site.php:620–655):
```php
    public function proposeKeyRotation(string $newKeyHash, int $ttlMinutes = 15): void {
        $this->forceFill([
            'pending_api_key_hash'   => $newKeyHash,
            'pending_key_expires_at' => now()->addMinutes($ttlMinutes),
        ])->save();
    }

    public function hasValidPendingKey(string $providedHash): bool {
        return ! empty($this->pending_api_key_hash)
            && hash_equals($this->pending_api_key_hash, $providedHash)
            && $this->pending_key_expires_at !== null
            && $this->pending_key_expires_at->isFuture();
    }

    public function promotePendingKey(): void {
        if (empty($this->pending_api_key_hash)) {
            return;
        }
        $this->forceFill([
            'api_key_hash'           => $this->pending_api_key_hash,
            'pending_api_key_hash'   => null,
            'pending_key_expires_at' => null,
            'key_rotated_at'         => now(),
            'status'                 => 'connected',
        ])->save();
    }

    public function revokeKey(): void {
        $this->forceFill([
            'api_key'                => null,
            'api_key_hash'           => '',
            'pending_api_key_hash'   => null,
            'pending_key_expires_at' => null,
            'status'                 => 'disconnected',
        ])->save();
    }
```
- [ ] **Step 4: Run → pass.** **Step 5: Commit** — `feat(d12): Site rotation/revocation state methods`.

### Task 3: Recognize the pending key in `ValidateSiteApiKey` (TDD)

The confirm call authenticates with the NEW (pending) key. The middleware must resolve the site by **either** the active hash **or** a valid pending hash, and (when it matched the pending hash) **promote** before proceeding.

**Files:** `app/Http/Middleware/ValidateSiteApiKey.php`, `tests/Feature/SiteRotationApiTest.php`

- [ ] **Step 1: Failing feature test** asserting a request bearing the pending key authenticates AND flips the active key (promotion-on-use). (Write it against a real route guarded by the middleware, e.g. `/api/v1/sites/rotate/confirm` from Task 4 — order Task 4 and 3 together; commit after both pass.)
- [ ] **Step 2: Implement** — in `handle()`, after the existing active-key lookup fails to find a site, try the pending hash:
```php
        $keyHash = hash('sha256', $token);
        $site = Site::where('api_key_hash', $keyHash)->first();

        // D-12: a request may authenticate with the PENDING (new) key during a
        // rotation. Accept it only while the pending window is open, and promote
        // on first valid use (the confirmation that proves the new key works).
        if (! $site) {
            $pending = Site::where('pending_api_key_hash', $keyHash)
                ->whereNotNull('pending_key_expires_at')
                ->where('pending_key_expires_at', '>', now())
                ->first();
            if ($pending) {
                $pending->promotePendingKey();
                $site = $pending->fresh();
            }
        }
```
Place this immediately after the existing `$site = Site::where('api_key_hash', $keyHash)->first();` line, before the `if (! $site) { 401 }` block. Leave all other logic intact. (The D-11 `peanut_connect_signed` block stays as-is.)
- [ ] **Step 3: Run → pass. Step 4: Commit** with Task 4.

### Task 4: `/sites/rotate` + `/sites/rotate/confirm` endpoints (TDD)

**Files:** Create `app/Http/Controllers/Api/SiteRotationController.php`; `routes/api.php`; `tests/Feature/SiteRotationApiTest.php`

- [ ] **Step 1: Failing feature tests:** (a) `POST /api/v1/sites/rotate` with `{new_key_hash}` authenticated by the current key records a pending key and does NOT change the active key (active key still authenticates afterward); (b) `POST /api/v1/sites/rotate/confirm` authenticated by the NEW key promotes it (old key stops working, new key works); (c) confirm with an expired/unknown pending key → 401.
- [ ] **Step 2: Controller:**
```php
<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteRotationController extends Controller {
    /** Edge proposes a new key (authenticated with the OLD key). Records pending; active unchanged. */
    public function propose(Request $request): JsonResponse {
        $site = $request->attributes->get('site');
        $newHash = (string) $request->input('new_key_hash');
        if (! preg_match('/^[0-9a-f]{64}$/', $newHash)) {
            return response()->json(['success' => false, 'message' => 'new_key_hash must be a sha256 hex digest'], 422);
        }
        $site->proposeKeyRotation($newHash, 15);
        return response()->json(['success' => true, 'pending_expires_at' => $site->pending_key_expires_at->toIso8601String()]);
    }

    /** Edge confirms with the NEW key. ValidateSiteApiKey already promoted on auth; just acknowledge. */
    public function confirm(Request $request): JsonResponse {
        $site = $request->attributes->get('site');
        return response()->json(['success' => true, 'rotated_at' => optional($site->key_rotated_at)->toIso8601String()]);
    }
}
```
- [ ] **Step 3: Routes** — in `routes/api.php`, inside the `ValidateSiteApiKey` group:
```php
    Route::post('/sites/rotate', [SiteRotationController::class, 'propose']);
    Route::post('/sites/rotate/confirm', [SiteRotationController::class, 'confirm']);
```
- [ ] **Step 4: Run → pass** (`php artisan test --filter SiteRotation`). **Step 5: Commit** Tasks 3+4 — `feat(d12): rotate/confirm endpoints + pending-key promotion`.

### Task 5: Heartbeat advertises `rotate` when due (TDD)

**Files:** the heartbeat controller (`SyncController::heartbeat` or equivalent), its test.

- [ ] **Step 1: Failing test** — a site whose `key_rotation_interval_days` is set and `key_rotated_at` (or pairing date) is older than the interval gets `rotate: true` in the heartbeat JSON; a site not due gets `rotate: false`.
- [ ] **Step 2: Implement** — add to the heartbeat response payload:
```php
            'rotate' => $site->isKeyRotationDue(),
```
and on `Site`:
```php
    public function isKeyRotationDue(): bool {
        if (empty($this->key_rotation_interval_days)) {
            return (bool) $this->rotate_key_requested; // operator on-demand flag (Task 6)
        }
        $base = $this->key_rotated_at ?? $this->created_at;
        return $base->addDays($this->key_rotation_interval_days)->isPast() || (bool) $this->rotate_key_requested;
    }
```
- [ ] **Step 3: Run → pass. Step 4: Commit** — `feat(d12): heartbeat signals rotation when due`.

### Task 6: Admin Rotate/Revoke actions + audit + on-demand flag (TDD)

**Files:** the site-detail controller + Blade (follow existing patterns), a `rotate_key_requested` boolean column (add to the Task 1 migration or a small follow-up migration), an audit write (use the Hub's existing audit/log mechanism), tests.

- [ ] **Step 1: Failing test** — `POST` to the admin rotate action sets `rotate_key_requested = true` (picked up by the next heartbeat) and writes an audit row; the revoke action calls `revokeKey()` and writes an audit row.
- [ ] **Step 2: Implement** the two controller actions (authorize: agency owner/admin of the site), call `$site->update(['rotate_key_requested' => true])` / `$site->revokeKey()`, write audit rows via the existing audit helper, add the two buttons to the site-detail Blade following the existing button/action pattern there. Clear `rotate_key_requested` in `promotePendingKey()`.
- [ ] **Step 3: Run → pass. Step 4: Commit** — `feat(d12): admin rotate/revoke actions + audit`.

### Task 7: GC for expired pendings (TDD)

**Files:** `app/Console/Commands/PurgeExpiredKeyRotations.php`, scheduler registration, test.

- [ ] **Step 1: Failing test** — a site with `pending_key_expires_at` in the past has its pending columns cleared by the command; active key untouched.
- [ ] **Step 2: Implement** the command: `Site::whereNotNull('pending_api_key_hash')->where('pending_key_expires_at','<',now())->update(['pending_api_key_hash'=>null,'pending_key_expires_at'=>null]);` and register it hourly in the scheduler.
- [ ] **Step 3: Run → pass. Step 4: Commit** — `feat(d12): GC expired pending key rotations`.

### Task 8: Ship Phase 1
- [ ] Full Hub suite green (`php artisan test`); push `feat/d12-hub-rotation`; open PR titled "feat(d12): Hub key rotation + revocation"; merge on green. (Inert until the edge uses it.)

---

# PHASE 2 — Edge (peanut-connect) · branch `feat/d12-edge-rotation`

> Branch off `origin/main` after Phase 1 merges. Run tests with `./vendor/bin/phpunit --configuration phpunit.xml`.

### Task 9: Rotation client (TDD)

**Files:** Create `includes/class-connect-key-rotation.php`; `tests/Test_Key_Rotation.php`. Loaded via `require_once` in `peanut-connect.php` `load_dependencies()` (after `class-connect-auth.php`).

The client implements the two-phase swap. It must keep the OLD key as the stored/active key until the confirm call with the NEW key succeeds, then swap.

- [ ] **Step 1: Failing test** for the pure pieces (the orchestration is WP-coupled; test the helpers): a `rotate()` that, given injectable HTTP results, (a) generates a 64-char key, (b) proposes with the old key, (c) only calls `set_hub_api_key($new)` after a successful confirm, (d) on confirm failure leaves the stored key unchanged. Structure `rotate()` to call small testable helpers (`generate_key()`, and accept the two HTTP calls through `Peanut_Connect_Hub_Sync` signed-request methods so they can be asserted). Test `generate_key()` returns `^[A-Za-z0-9]{64}$` and that `new_key_hash()` = `hash('sha256',$key)`.
- [ ] **Step 2: Implement:**
```php
<?php
if (!defined('ABSPATH')) { exit; }

class Peanut_Connect_Key_Rotation {
    /** Generate a fresh site key. */
    public static function generate_key(): string {
        return wp_generate_password(64, false, false);
    }

    /**
     * Two-phase rotate: propose(new, signed-with-old) → confirm(signed-with-new) →
     * adopt locally. The stored key is swapped ONLY after the confirm succeeds,
     * so a failure anywhere leaves the site on the old key (no lockout).
     *
     * @return array{success:bool,message:string}
     */
    public static function rotate(): array {
        $hub_url = get_option('peanut_connect_hub_url');
        $old_key = Peanut_Connect_Auth::get_hub_api_key();
        if (empty($hub_url) || $old_key === '') {
            return ['success' => false, 'message' => 'Not paired'];
        }
        $new_key  = self::generate_key();
        $new_hash = hash('sha256', $new_key);

        // Phase 1: propose, signed/authed with the OLD key.
        $propose = self::signed_post($hub_url, 'api/v1/sites/rotate', $old_key, ['new_key_hash' => $new_hash]);
        if (! $propose['ok']) {
            return ['success' => false, 'message' => 'Propose failed: ' . $propose['message']];
        }

        // Phase 2: confirm, signed/authed with the NEW key (Hub promotes on this call).
        $confirm = self::signed_post($hub_url, 'api/v1/sites/rotate/confirm', $new_key, []);
        if (! $confirm['ok']) {
            // Old key is still active on the Hub (pending expires); stay on it.
            return ['success' => false, 'message' => 'Confirm failed; staying on current key'];
        }

        // Adopt only now.
        Peanut_Connect_Auth::set_hub_api_key($new_key);
        if (class_exists('Peanut_Connect_Activity_Log')) {
            Peanut_Connect_Activity_Log::log('hub_key_rotated', 'success', __('Hub key rotated', 'peanut-connect'), []);
        }
        return ['success' => true, 'message' => 'Rotated'];
    }

    /** POST to Hub with a specific key: Bearer + D-11 signature + D-10 protocol. */
    private static function signed_post(string $hub_url, string $path, string $key, array $payload): array {
        $url  = trailingslashit($hub_url) . $path;
        $body = wp_json_encode($payload);
        $route = (string) (wp_parse_url($url, PHP_URL_PATH) ?: '/');
        $ts = (string) time(); $nonce = bin2hex(random_bytes(16));
        $headers = [
            'Authorization'      => 'Bearer ' . $key,
            'Content-Type'       => 'application/json',
            'Accept'             => 'application/json',
            'X-Peanut-Protocol'  => '1',
            'X-Peanut-Timestamp' => $ts,
            'X-Peanut-Nonce'     => $nonce,
            'X-Peanut-Signature' => Peanut_Connect_Auth::compute_request_signature($key, 'POST', $route, $ts, $nonce, $body),
        ];
        $resp = wp_remote_post($url, ['headers' => $headers, 'body' => $body, 'timeout' => 15]);
        if (is_wp_error($resp)) { return ['ok' => false, 'message' => $resp->get_error_message()]; }
        $code = (int) wp_remote_retrieve_response_code($resp);
        return ['ok' => $code >= 200 && $code < 300, 'message' => "HTTP $code"];
    }
}
```
(Note: this signs with an arbitrary key, which is why it doesn't reuse `outbound_signature_headers` — that helper reads the *stored* key, but here we must sign the confirm with the new key before it's stored.)
- [ ] **Step 3: Run → pass. Step 4: Commit** — `feat(d12): edge two-phase key rotation client`.

### Task 10: Heartbeat acts on `rotate` + revocation 401 detection (TDD where possible)

**Files:** `includes/class-connect-hub-sync.php`

- [ ] **Step 1:** In `send_heartbeat()`, after a successful response, if `($body['rotate'] ?? false)` is true → `Peanut_Connect_Key_Rotation::rotate()`.
- [ ] **Step 2:** Revocation detection: when a signed outbound heartbeat returns HTTP 401, increment a transient counter `peanut_connect_auth_fail_count`; on reaching 2 consecutive, call `Peanut_Connect_Auth::clear_hub_api_key()` and `update_option('peanut_connect_hub_key_undecryptable', 1)` (reuse the A5 re-pair notice surface), then reset the counter. Reset the counter to 0 on any 2xx. (Two strikes avoids a transient blip clearing a live pairing.)
- [ ] **Step 3:** `php -l` + full suite green. **Step 4: Commit** — `feat(d12): act on heartbeat rotate signal + detect revocation`.

### Task 11: Admin "Rotate key" action (TDD-lite)

**Files:** `includes/class-connect-api.php` (route `POST /settings/hub/rotate-key`, `admin_permission_check`), `frontend/src/api/endpoints.ts`, `frontend/src/pages/Settings.tsx`.

- [ ] **Step 1:** Register `POST /settings/hub/rotate-key` → handler calls `Peanut_Connect_Key_Rotation::rotate()` and returns its result. (admin-only.)
- [ ] **Step 2:** Add `settingsApi.rotateHubKey()` + a "Rotate key" button in the Hub section of Settings.tsx with a confirm + toast (follow the existing disconnect-button pattern). `npm run build` (rebuild dist).
- [ ] **Step 3:** `php -l`, full PHP suite, `npm run build` clean. **Step 4: Commit** — `feat(d12): admin Rotate-key action + Settings button`.

### Task 12: Ship Phase 2
- [ ] Full suite green; push `feat/d12-edge-rotation`; PR "feat(d12): edge key rotation + revocation detection"; verify cross-repo (an edge propose+confirm against a Hub test double, or a manual staging round-trip); merge on green.

---

# PHASE 3 — Enable (after both deployed)

### Task 13: Turn on a default rotation interval
- [ ] Set the Hub default `key_rotation_interval_days` policy (e.g. 90) for sites — via a small data migration or an agency-level default — only once Phase 1+2 are deployed fleet-wide. Document it. This is the only enforcement "flip"; stage it like A8b. Commit/PR `chore(d12): enable 90-day default key rotation`.

---

## Self-review

**Spec coverage:** rotation-first ✔ (Tasks 1–9, default interval Task 13); either-side trigger ✔ (heartbeat Task 5 + admin Task 6 Hub, Settings Task 11 edge); two-phase confirmed swap ✔ (Task 9 propose-old → confirm-new → adopt; promotion-on-pending-use Task 3); no-lockout ✔ (adopt only after confirm; old valid until promote; GC reverts Task 7); revocation ✔ (Task 2 `revokeKey` + admin Task 6 + edge 401-detect Task 10); audit ✔ (Task 6); GC ✔ (Task 7); cross-repo signing reuse ✔ (Task 9 uses compute_request_signature + protocol header). Deploy order ✔ (phases).

**Placeholder scan:** Task 6 references "the existing audit helper" / "existing button pattern" — the implementer must read the Hub's current site-detail controller/Blade + audit mechanism and follow them (this is a follow-existing-pattern instruction, not an invented API). Task 5/6 reference a `rotate_key_requested` column — add it in the Task 1 migration (amend Task 1 to include `$table->boolean('rotate_key_requested')->default(false);`).

**Type/name consistency:** `proposeKeyRotation` / `hasValidPendingKey` / `promotePendingKey` / `revokeKey` / `isKeyRotationDue` (Hub Site); `Peanut_Connect_Key_Rotation::rotate/generate_key/signed_post` (edge); columns `pending_api_key_hash`/`pending_key_expires_at`/`key_rotated_at`/`key_rotation_interval_days`/`rotate_key_requested`; routes `/api/v1/sites/rotate` + `/confirm`; edge route `/settings/hub/rotate-key` — used consistently across tasks.

**Amendment to Task 1:** also add `$table->boolean('rotate_key_requested')->default(false)->after('key_rotation_interval_days');` and cast it boolean.
