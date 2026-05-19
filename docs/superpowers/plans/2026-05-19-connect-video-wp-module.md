# Connect Video WP Module Implementation Plan (Plan B of 2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a "Videos" module to the Peanut End-to-End (`peanut-connect`) WordPress plugin so an editor can register a WP-media or external-URL video with HUB, insert it via shortcode/block, and view its analytics — without leaving WordPress.

**Architecture:** A static `Peanut_Connect_Videos` PHP class mirroring `Peanut_Connect_Marketing`: it registers admin-gated WP REST routes under `peanut-connect/v1/videos*` and proxies them to the merged HUB videos API via the same `forward()` Bearer pattern. A React `Videos.tsx` SPA page (sibling of `Links.tsx`) drives registration (WP media picker + paste-URL toggle) and a read-only analytics panel. A `[peanut_video]` shortcode (mirroring `[peanut_form]`) is the canonical renderer; a dynamic Gutenberg block delegates to it.

**Tech Stack:** WordPress plugin PHP (PHPUnit unit harness in `tests/phpunit/`), React + TypeScript + react-query + axios SPA (Vite, Vitest), built to `assets/dist/`.

**Spec:** `peanut-hub:docs/superpowers/specs/2026-05-19-connect-video-module-design.md` Sections 2–4 (user-approved). This is Plan B; Plan A (HUB videos API) shipped via peanut-hub PR #368 (squash `5129ad2ac`).

**Working branch:** `feat/connect-video-wp-module` (already created off `origin/main`).

---

## Merged HUB API contract (Plan B MUST code against this — as-built, not the spec's draft samples)

All under HUB `/api/v1`, site-key Bearer auth, `{success:bool,data:...}` envelope; 422 validation errors are `{success:false,message:'Validation failed',errors:{}}`:

- `POST /api/v1/videos` body `{title, source_url, poster_url?, caption_url?, description?}` → `201 {success,data:{id,title,slug,description,source_url,poster_url,caption_url,status,created_at,embed_url}}`
- `GET /api/v1/videos` → `200 {success,data:[ ...present ]}` (active only; archived excluded)
- `PATCH /api/v1/videos/{id}` partial `{title?,source_url?,poster_url?,caption_url?,description?}` → `200 {success,data:present}`; foreign id → 404
- `DELETE /api/v1/videos/{id}` → `200 {success:true}` (archives, not hard delete); foreign id → 404
- `GET /api/v1/videos/{id}/analytics?days=7|30|90` → `200 {success,data:{daily_views,avg_watch_time,completion_rate,unique_viewers,total_plays,drop_off_all_time,days}}`; foreign id → 404. **Key is `drop_off_all_time`** (all-time bucket map `{"0%":n,...,"100%":n}`), metrics are `days`-windowed.
- `embed_url` is `https://{hub}/video/{slug}/embed` — a standalone HTML5 player page that fires its own analytics beacons.

---

## File Structure

- Create: `includes/class-connect-videos.php` — static class: `register_routes()` + 5 proxy handlers + private `forward()` (mirrors `class-connect-marketing.php`)
- Modify: `peanut-connect.php` — `require_once` the new class (in `load_dependencies()`) + `Peanut_Connect_Videos::register_routes();` (in `register_api_routes()`)
- Create: `tests/phpunit/unit/VideosTest.php` — proxy/permission/route tests (mirrors `tests/phpunit/unit/ApiTest.php`)
- Create: `frontend/src/api/videos.ts` — typed `videosApi` (mirrors `frontend/src/api/marketing.ts`)
- Create: `frontend/src/api/videos.test.ts` — vitest for the api wrapper
- Create: `frontend/src/pages/Videos.tsx` — admin page (mirrors `pages/Links.tsx`)
- Modify: `frontend/src/components/layout/Sidebar.tsx` — nav entry
- Modify: `frontend/src/App.tsx` — route
- Modify: `includes/class-connect-videos.php` — add `[peanut_video]` shortcode + dynamic block registration (later tasks, same file)
- Create: `blocks/peanut-video/block.json` + `frontend/src/blocks/peanut-video.tsx` — Gutenberg editor script (added as a second Vite entry)
- Modify: `frontend/vite.config.ts` — add the block editor entry
- Modify: `peanut-connect.php` — register block (`register_block_type`) + enqueue block editor asset
- Modify: `CHANGELOG.md` + `peanut-connect.php` + `readme.txt` — version bump

---

## Conventions

- **PHP test run (VERIFIED — use verbatim):** from the worktree root:
  `vendor/bin/phpunit --bootstrap tests/phpunit/bootstrap.php --no-configuration tests/phpunit/unit/VideosTest.php`
  Run ONLY `VideosTest.php` (or the unit dir filtered to it). DO NOT use `composer test` — that targets a legacy WP-integration `./tests/` suite that cannot load standalone ("No tests executed"). The pre-existing `tests/phpunit/unit/ApiTest.php` has **baseline red unrelated to Plan B** (31 errors/5 failures on `origin/main`) — never treat ApiTest red as a Plan B regression; always scope PHP runs to `VideosTest.php`.
- **Frontend test run (scoped):** `cd frontend && npm run test:run -- <name>` (vitest, filter to the new file, e.g. `videos` / `VideoAnalyticsPanel`). **Baseline:** the full frontend suite is `~370 passed / ~10 failed` on `origin/main` (2 pre-existing broken files unrelated to Plan B) — always filter to the new test; never treat pre-existing failures as a Plan B regression. **Frontend build:** `cd frontend && npm run build` (tsc + vite → `assets/dist/`).
- PHP REST handlers return `WP_REST_Response`/`WP_Error`; admin gate is `check_admin_permission()` → `current_user_can('manage_options')`. The `forward()` helper already maps not-connected → 412 and Hub errors → 502.
- SPA: axios instance `frontend/src/api/client.ts` already unwraps `{success,data}` (so `res.data` IS the inner data). Pages use `@tanstack/react-query` `useQuery`/`useMutation`, `Layout`, `useToast`, `useConfirm` — copy `pages/Links.tsx` idioms.
- Commit after every task. There is no secret-scanning pre-commit assumed; keep commits scoped to the listed files.

---

### Task 0: Extend the PHPUnit bootstrap with the WP stubs this module needs

The unit harness `tests/phpunit/bootstrap.php` stubs WP functions via the guarded `if (!function_exists(...))` pattern. It currently stubs `get_option`/`update_option` (`$peanut_test_options`), `current_user_can` (`$mock_user_caps`), `wp_remote_get` (`$mock_remote_response`), `wp_remote_retrieve_body`, `WP_Error`, `WP_REST_Request`, `WP_REST_Response`, `register_rest_route`, `__`. It does **NOT** stub `wp_remote_request`, `wp_remote_retrieve_response_code`, `trailingslashit`, `add_query_arg`, `wp_json_encode`, `sanitize_title`, `esc_url`, `esc_attr`, `esc_html`, `shortcode_atts`, `add_shortcode`, or `register_block_type` — all of which `class-connect-videos.php` (Tasks 1/5/7) needs. The Hub-proxy `forward()` path was never unit-tested before, so these never existed. Add them once, here.

**Files:**
- Modify: `tests/phpunit/bootstrap.php`

- [ ] **Step 1: Append these guarded stubs to `tests/phpunit/bootstrap.php`** (at end of file, before any final closing logic — match the file's existing `if (!function_exists())` style; do NOT alter existing stubs):

```php
if (!function_exists('wp_remote_request')) {
    function wp_remote_request(string $url, array $args = []) {
        global $mock_remote_response, $peanut_last_http;
        $peanut_last_http = ['url' => $url, 'args' => $args];
        if (isset($mock_remote_response)) {
            return is_callable($mock_remote_response)
                ? ($mock_remote_response)($url, $args)
                : $mock_remote_response;
        }
        return new WP_Error('http_request_failed', 'Mock: No response configured');
    }
}
if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response): int {
        if (is_array($response) && isset($response['response']['code'])) {
            return (int) $response['response']['code'];
        }
        return 0;
    }
}
if (!function_exists('trailingslashit')) {
    function trailingslashit(string $s): string { return rtrim($s, "/\\") . '/'; }
}
if (!function_exists('add_query_arg')) {
    function add_query_arg(...$a) {
        if (is_array($a[0])) { $args = $a[0]; $url = (string) $a[1]; }
        else { $args = [$a[0] => $a[1]]; $url = (string) $a[2]; }
        $sep = (strpos($url, '?') === false) ? '?' : '&';
        return $url . $sep . http_build_query($args);
    }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, int $options = 0, int $depth = 512) {
        return json_encode($data, $options, $depth);
    }
}
if (!function_exists('sanitize_title')) {
    function sanitize_title(string $t): string {
        $t = strtolower(trim($t));
        $t = preg_replace('/[^a-z0-9_\-]+/', '-', $t);
        return trim((string) $t, '-');
    }
}
if (!function_exists('esc_url')) {
    function esc_url(string $u): string { return htmlspecialchars($u, ENT_QUOTES); }
}
if (!function_exists('esc_attr')) {
    function esc_attr(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
}
if (!function_exists('esc_html')) {
    function esc_html(string $s): string { return htmlspecialchars($s, ENT_QUOTES); }
}
if (!function_exists('shortcode_atts')) {
    function shortcode_atts(array $defaults, $atts, string $shortcode = ''): array {
        $atts = (array) $atts;
        $out = [];
        foreach ($defaults as $k => $d) {
            $out[$k] = array_key_exists($k, $atts) ? $atts[$k] : $d;
        }
        return $out;
    }
}
if (!function_exists('add_shortcode')) {
    function add_shortcode(string $tag, $cb): void {
        global $peanut_test_shortcodes;
        $peanut_test_shortcodes[$tag] = $cb;
    }
}
if (!function_exists('register_block_type')) {
    function register_block_type($name, array $args = []) {
        global $peanut_test_blocks;
        $key = is_string($name) ? $name : 'block';
        $peanut_test_blocks[$key] = $args;
        return true;
    }
}
```

- [ ] **Step 2: Sanity-check the bootstrap still loads.** Run:
  `vendor/bin/phpunit --bootstrap tests/phpunit/bootstrap.php --no-configuration tests/phpunit/unit/ApiTest.php 2>&1 | tail -3`
  Expected: ApiTest still runs (its pre-existing baseline failures are unchanged — you are only confirming the new stubs didn't introduce a parse/redeclare error; the test COUNT/PASS for ApiTest is irrelevant here).

- [ ] **Step 3: Commit.**
```bash
git add tests/phpunit/bootstrap.php
git commit -m "test(videos): add WP function stubs (wp_remote_request, esc_*, shortcode, block) to unit bootstrap"
```

---

### Task 1: PHP videos proxy module + wiring

**Files:**
- Create: `includes/class-connect-videos.php`
- Modify: `peanut-connect.php`
- Test: `tests/phpunit/unit/VideosTest.php`

- [ ] **Step 0: Use the verified PHP test command** (from Conventions): `vendor/bin/phpunit --bootstrap tests/phpunit/bootstrap.php --no-configuration tests/phpunit/unit/VideosTest.php`. Task 0 (bootstrap stubs) MUST be committed before this task. Never use `composer test`.

- [ ] **Step 1: Write the failing test** — `tests/phpunit/unit/VideosTest.php`:

```php
<?php
namespace Peanut_Connect\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Peanut_Connect_Videos;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class VideosTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        global $peanut_test_options, $mock_user_caps, $mock_remote_response, $peanut_last_http;
        $peanut_test_options = [];
        $mock_user_caps = [];
        $mock_remote_response = null;
        $peanut_last_http = null;
    }

    public function test_admin_permission_blocks_non_admins(): void {
        global $mock_user_caps;
        $mock_user_caps['manage_options'] = false;
        $this->assertFalse(Peanut_Connect_Videos::check_admin_permission());
    }

    public function test_admin_permission_allows_admins(): void {
        global $mock_user_caps;
        $mock_user_caps['manage_options'] = true;
        $this->assertTrue(Peanut_Connect_Videos::check_admin_permission());
    }

    public function test_list_returns_412_when_not_connected(): void {
        // No hub url/key options set => not connected
        $req = new WP_REST_Request('GET', '/videos');
        $res = Peanut_Connect_Videos::list_videos($req);
        $this->assertInstanceOf(WP_Error::class, $res);
        $this->assertSame(412, $res->get_error_data()['status']);
    }
}
```

- [ ] **Step 2: Run it, expect failure** — class does not exist.
Run the Step 0 PHP test command filtered to `VideosTest`.
Expected: error/fail — `Class "Peanut_Connect_Videos" not found`.

- [ ] **Step 3: Create `includes/class-connect-videos.php`** (mirrors `class-connect-marketing.php` `forward()` verbatim, scoped to `/videos`):

```php
<?php
/**
 * Videos module — proxies the Connect plugin's video endpoints to the Hub
 * videos API (site-key Bearer). Mirrors Peanut_Connect_Marketing.
 *
 * @package Peanut_Connect
 */

if (!defined('ABSPATH')) {
    exit;
}

class Peanut_Connect_Videos {

    public static function register_routes(): void {
        $ns = 'peanut-connect/v1';
        $perms = [self::class, 'check_admin_permission'];

        register_rest_route($ns, '/videos', [
            ['methods' => 'GET',  'callback' => [self::class, 'list_videos'],   'permission_callback' => $perms],
            ['methods' => 'POST', 'callback' => [self::class, 'create_video'],  'permission_callback' => $perms],
        ]);
        register_rest_route($ns, '/videos/(?P<id>\d+)', [
            ['methods' => 'PATCH',  'callback' => [self::class, 'update_video'],  'permission_callback' => $perms],
            ['methods' => 'DELETE', 'callback' => [self::class, 'delete_video'],  'permission_callback' => $perms],
        ]);
        register_rest_route($ns, '/videos/(?P<id>\d+)/analytics', [
            ['methods' => 'GET', 'callback' => [self::class, 'video_analytics'], 'permission_callback' => $perms],
        ]);
    }

    public static function check_admin_permission(): bool {
        return current_user_can('manage_options');
    }

    public static function list_videos(WP_REST_Request $request) {
        return self::forward('GET', '/videos', null, $request->get_query_params());
    }

    public static function create_video(WP_REST_Request $request) {
        return self::forward('POST', '/videos', $request->get_json_params());
    }

    public static function update_video(WP_REST_Request $request) {
        $id = (int) $request['id'];
        return self::forward('PATCH', '/videos/' . $id, $request->get_json_params());
    }

    public static function delete_video(WP_REST_Request $request) {
        $id = (int) $request['id'];
        return self::forward('DELETE', '/videos/' . $id);
    }

    public static function video_analytics(WP_REST_Request $request) {
        $id = (int) $request['id'];
        return self::forward('GET', '/videos/' . $id . '/analytics', null, $request->get_query_params());
    }

    private static function forward(string $method, string $path, ?array $body = null, ?array $query = null) {
        $hub_url = (string) get_option('peanut_connect_hub_url', '');
        $api_key = (string) get_option('peanut_connect_hub_api_key', '');

        if ($hub_url === '' || $api_key === '') {
            return new WP_Error(
                'peanut_connect_not_connected',
                __('This site is not connected to a Hub install yet.', 'peanut-connect'),
                ['status' => 412]
            );
        }

        $url = trailingslashit($hub_url) . 'api/v1' . $path;
        if (!empty($query)) {
            $url = add_query_arg($query, $url);
        }

        $args = [
            'method'  => $method,
            'timeout' => 20,
            'headers' => [
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
        ];

        if ($body !== null && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return new WP_Error(
                'peanut_connect_hub_unreachable',
                $response->get_error_message(),
                ['status' => 502]
            );
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw    = (string) wp_remote_retrieve_body($response);
        $data   = json_decode($raw, true);

        if (!is_array($data)) {
            $data = ['raw' => $raw];
        }

        // cPanel/ImunifyAV sometimes rewrites a 200 to 4xx while body says success.
        if (isset($data['success']) && $data['success'] === true && $status >= 400) {
            $status = 200;
        }

        return new WP_REST_Response($data, $status > 0 ? $status : 502);
    }
}
```

- [ ] **Step 4: Wire it into the plugin.** In `peanut-connect.php`, in the `load_dependencies()` method, add after the `class-connect-marketing.php` require line:

```php
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-videos.php';
```

In the `register_api_routes()` method, add after `Peanut_Connect_Marketing::register_routes();`:

```php
        Peanut_Connect_Videos::register_routes();
```

- [ ] **Step 5: Run the test, expect pass.** Run the Step 0 PHP test command filtered to `VideosTest`. Expected: 3 passed.

- [ ] **Step 6: Commit.**
```bash
git add includes/class-connect-videos.php peanut-connect.php tests/phpunit/unit/VideosTest.php
git commit -m "feat(videos): proxy module for Hub videos API (list/create/update/delete/analytics)"
```

---

### Task 2: Proxy passthrough tests (verify forward shapes Hub calls correctly)

**Files:**
- Test: `tests/phpunit/unit/VideosTest.php`

**HTTP mock mechanism (VERIFIED):** the Task 0 `wp_remote_request` stub honors `global $mock_remote_response` (a value OR a `function($url,$args)` callable) and records the outbound call into `global $peanut_last_http = ['url'=>..., 'args'=>...]`. `wp_remote_retrieve_response_code` reads `$response['response']['code']`; `wp_remote_retrieve_body` reads `$response['body']`. Use exactly these globals — do NOT invent `$GLOBALS['peanut_mock_http']`.

- [ ] **Step 1: Add failing tests** to `VideosTest.php`:

```php
    public function test_create_video_forwards_post_to_hub_and_passes_envelope(): void {
        global $peanut_test_options, $mock_remote_response, $peanut_last_http;
        $peanut_test_options['peanut_connect_hub_url'] = 'https://hub.example.com';
        $peanut_test_options['peanut_connect_hub_api_key'] = 'k';
        $peanut_last_http = null;
        $mock_remote_response = [
            'response' => ['code' => 201],
            'body' => json_encode(['success' => true, 'data' => [
                'id' => 9, 'slug' => 'promo-abc', 'title' => 'Promo',
                'embed_url' => 'https://hub.example.com/video/promo-abc/embed',
            ]]),
        ];

        $req = new WP_REST_Request('POST', '/videos');
        $req->set_body(json_encode(['title' => 'Promo', 'source_url' => 'https://wp.example.com/v.mp4']));
        $req->set_header('Content-Type', 'application/json');
        $res = Peanut_Connect_Videos::create_video($req);

        $this->assertInstanceOf(WP_REST_Response::class, $res);
        $this->assertSame(201, $res->get_status());
        $this->assertTrue($res->get_data()['success']);
        $this->assertSame('https://hub.example.com/api/v1/videos', $peanut_last_http['url']);
        $this->assertSame('POST', $peanut_last_http['args']['method']);
        $this->assertSame('Bearer k', $peanut_last_http['args']['headers']['Authorization']);
        $this->assertStringContainsString('"title":"Promo"', $peanut_last_http['args']['body']);
    }

    public function test_analytics_forwards_days_query(): void {
        global $peanut_test_options, $mock_remote_response, $peanut_last_http;
        $peanut_test_options['peanut_connect_hub_url'] = 'https://hub.example.com';
        $peanut_test_options['peanut_connect_hub_api_key'] = 'k';
        $peanut_last_http = null;
        $mock_remote_response = [
            'response' => ['code' => 200],
            'body' => json_encode(['success' => true, 'data' => ['total_plays' => 0, 'drop_off_all_time' => []]]),
        ];
        $req = new WP_REST_Request('GET', '/videos/9/analytics');
        $req->set_query_params(['days' => '30']);
        $req['id'] = 9;
        $res = Peanut_Connect_Videos::video_analytics($req);
        $this->assertSame(200, $res->get_status());
        $this->assertStringContainsString('/api/v1/videos/9/analytics', $peanut_last_http['url']);
        $this->assertStringContainsString('days=30', $peanut_last_http['url']);
    }
```

(Note: the Task 1 not-connected test must `unset($GLOBALS['mock_remote_response'])` or it won't matter — when hub url/key are absent, `forward()` returns 412 before any HTTP call. Add `global $mock_remote_response; $mock_remote_response = null;` to `setUp()` in Task 1's test file so cases don't leak responses into each other.)

- [ ] **Step 2: Run, expect pass** (Task 0 stubs make `wp_remote_request` available). If `wp_remote_request` is somehow still undefined, Task 0 was not committed first — STOP and report BLOCKED. Do not change `class-connect-videos.php`; this task only validates Task 1's `forward()` via the verified mock globals.

- [ ] **Step 3: Run, expect pass** — 2 new tests green (no production code change; this validates Task 1's `forward()`).

- [ ] **Step 4: Commit.**
```bash
git add tests/phpunit/unit/VideosTest.php
git commit -m "test(videos): assert proxy forwards method/url/auth/query to Hub"
```

---

### Task 3: Frontend API wrapper `videos.ts`

**Files:**
- Create: `frontend/src/api/videos.ts`
- Test: `frontend/src/api/videos.test.ts`

- [ ] **Step 1: Write the failing test** — `frontend/src/api/videos.test.ts` (mirror any existing `frontend/src/api/*.test.ts`; if none, this establishes the pattern — mock the shared `./client` axios instance):

```ts
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { videosApi } from './videos';
import api from './client';

vi.mock('./client');

describe('videosApi', () => {
  beforeEach(() => vi.resetAllMocks());

  it('list() GETs /videos and returns the unwrapped array', async () => {
    (api.get as any).mockResolvedValue({ data: [{ id: 1, slug: 's', title: 'T' }] });
    const out = await videosApi.list();
    expect(api.get).toHaveBeenCalledWith('/videos');
    expect(out).toEqual([{ id: 1, slug: 's', title: 'T' }]);
  });

  it('create() POSTs /videos with the payload', async () => {
    (api.post as any).mockResolvedValue({ data: { id: 2, slug: 'p-x', embed_url: 'u' } });
    const out = await videosApi.create({ title: 'P', source_url: 'https://e/v.mp4' });
    expect(api.post).toHaveBeenCalledWith('/videos', { title: 'P', source_url: 'https://e/v.mp4' });
    expect(out.slug).toBe('p-x');
  });

  it('analytics() GETs /videos/{id}/analytics with days param', async () => {
    (api.get as any).mockResolvedValue({ data: { total_plays: 3, drop_off_all_time: { '0%': 3 }, days: 30 } });
    const out = await videosApi.analytics(7, 30);
    expect(api.get).toHaveBeenCalledWith('/videos/7/analytics', { params: { days: 30 } });
    expect(out.drop_off_all_time['0%']).toBe(3);
  });

  it('remove() DELETEs /videos/{id}', async () => {
    (api.delete as any).mockResolvedValue({ data: { success: true } });
    await videosApi.remove(5);
    expect(api.delete).toHaveBeenCalledWith('/videos/5');
  });
});
```

- [ ] **Step 2: Run, expect fail** — `cd frontend && npm run test:run -- videos` → module `./videos` not found.

- [ ] **Step 3: Create `frontend/src/api/videos.ts`** (the shared interceptor already unwraps `{success,data}`, so `res.data` is the inner payload — same as `marketing.ts`):

```ts
import api from './client';

export interface Video {
  id: number;
  title: string;
  slug: string;
  description: string | null;
  source_url: string | null;
  poster_url: string | null;
  caption_url: string | null;
  status: string;
  created_at: string | null;
  embed_url: string;
}

export interface VideoInput {
  title: string;
  source_url: string;
  poster_url?: string;
  caption_url?: string;
  description?: string;
}

export interface VideoAnalytics {
  daily_views: Record<string, number>;
  avg_watch_time: number;
  completion_rate: number;
  unique_viewers: number;
  total_plays: number;
  drop_off_all_time: Record<string, number>;
  days: number;
}

export const videosApi = {
  list: async (): Promise<Video[]> => {
    const res = await api.get('/videos');
    return (res.data as Video[]) ?? [];
  },
  create: async (input: VideoInput): Promise<Video> => {
    const res = await api.post('/videos', input);
    return res.data as Video;
  },
  update: async (id: number, input: Partial<VideoInput>): Promise<Video> => {
    const res = await api.patch(`/videos/${id}`, input);
    return res.data as Video;
  },
  remove: async (id: number): Promise<void> => {
    await api.delete(`/videos/${id}`);
  },
  analytics: async (id: number, days: 7 | 30 | 90 = 30): Promise<VideoAnalytics> => {
    const res = await api.get(`/videos/${id}/analytics`, { params: { days } });
    return res.data as VideoAnalytics;
  },
};
```

- [ ] **Step 4: Run, expect pass** — `cd frontend && npm run test:run -- videos` → 4 passed.

- [ ] **Step 5: Commit.**
```bash
git add frontend/src/api/videos.ts frontend/src/api/videos.test.ts
git commit -m "feat(videos): typed frontend API wrapper + tests"
```

---

### Task 4: Videos admin page + nav + route

**Files:**
- Create: `frontend/src/pages/Videos.tsx`
- Modify: `frontend/src/components/layout/Sidebar.tsx`
- Modify: `frontend/src/App.tsx`

- [ ] **Step 1: Read `frontend/src/pages/Links.tsx` in full** and reuse its exact imports/idioms (`Layout`, `Card`, `useToast`, `useConfirm`, react-query `useQuery`/`useMutation`, the table markup classes). The page must:
  - List videos (`videosApi.list`) in a table: title, slug, status, a "Copy embed" button copying `[peanut_video slug="<slug>"]`, an "Analytics" expander, a delete (archive) action with `useConfirm`.
  - "Add video" opens the WP media frame (`window.wp.media`) to pick an `.mp4` (required) → fills `source_url`; optional poster image → `poster_url`; optional `.vtt` (filter by `subtype`/url ending) → `caption_url`. Each of the three has a "paste external URL" text input toggle (the future-CDN seam).
  - Submit → `videosApi.create` → invalidate the list query → toast.

- [ ] **Step 2: Create `frontend/src/pages/Videos.tsx`:**

```tsx
import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import Layout from '../components/layout/Layout';
import Card from '../components/ui/Card';
import { useToast } from '../components/ui/useToast';
import { useConfirm } from '../components/ui/useConfirm';
import { videosApi, type Video, type VideoInput } from '../api/videos';
import { VideoAnalyticsPanel } from '../components/videos/VideoAnalyticsPanel';

declare global {
  interface Window { wp?: { media?: any } }
}

function pickFromMedia(opts: { title: string; type?: string }): Promise<string | null> {
  return new Promise((resolve) => {
    const wp = window.wp;
    if (!wp || !wp.media) { resolve(null); return; }
    const frame = wp.media({ title: opts.title, multiple: false, library: opts.type ? { type: opts.type } : undefined });
    frame.on('select', () => {
      const a = frame.state().get('selection').first().toJSON();
      resolve(a?.url ?? null);
    });
    frame.on('close', () => setTimeout(() => resolve(null), 0));
    frame.open();
  });
}

export default function Videos() {
  const qc = useQueryClient();
  const toast = useToast();
  const { confirm, dialog } = useConfirm();
  const [form, setForm] = useState<VideoInput>({ title: '', source_url: '' });
  const [poster, setPoster] = useState('');
  const [caption, setCaption] = useState('');
  const [expanded, setExpanded] = useState<number | null>(null);

  const { data, isLoading, error } = useQuery({
    queryKey: ['videos'],
    queryFn: () => videosApi.list(),
  });

  const create = useMutation({
    mutationFn: () => videosApi.create({
      ...form,
      poster_url: poster || undefined,
      caption_url: caption || undefined,
    }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['videos'] });
      setForm({ title: '', source_url: '' }); setPoster(''); setCaption('');
      toast.success('Video registered.');
    },
    onError: (e: Error) => toast.error(`Could not register video: ${e.message}`),
  });

  const remove = useMutation({
    mutationFn: (id: number) => videosApi.remove(id),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['videos'] }); toast.success('Video removed.'); },
    onError: (e: Error) => toast.error(`Could not remove video: ${e.message}`),
  });

  const videos: Video[] = data ?? [];

  return (
    <Layout title="Videos" description="Register a video with Hub, insert it anywhere, and track engagement.">
      {dialog}
      <Card>
        <h3 className="text-sm font-semibold mb-3">Add a video</h3>
        <div className="grid gap-3 sm:grid-cols-2">
          <input className="border rounded px-3 py-2 text-sm" placeholder="Title"
            value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} />
          <div className="flex gap-2">
            <input className="border rounded px-3 py-2 text-sm flex-1" placeholder="Video URL (.mp4) or pick"
              value={form.source_url} onChange={(e) => setForm({ ...form, source_url: e.target.value })} />
            <button type="button" className="text-xs px-2 py-1 border rounded"
              onClick={async () => { const u = await pickFromMedia({ title: 'Select video', type: 'video' }); if (u) setForm((f) => ({ ...f, source_url: u })); }}>
              Media
            </button>
          </div>
          <div className="flex gap-2">
            <input className="border rounded px-3 py-2 text-sm flex-1" placeholder="Poster image URL (optional)"
              value={poster} onChange={(e) => setPoster(e.target.value)} />
            <button type="button" className="text-xs px-2 py-1 border rounded"
              onClick={async () => { const u = await pickFromMedia({ title: 'Select poster', type: 'image' }); if (u) setPoster(u); }}>
              Media
            </button>
          </div>
          <div className="flex gap-2">
            <input className="border rounded px-3 py-2 text-sm flex-1" placeholder="Captions .vtt URL (optional)"
              value={caption} onChange={(e) => setCaption(e.target.value)} />
            <button type="button" className="text-xs px-2 py-1 border rounded"
              onClick={async () => { const u = await pickFromMedia({ title: 'Select captions' }); if (u) setCaption(u); }}>
              Media
            </button>
          </div>
        </div>
        <button className="mt-3 bg-black text-white text-sm px-4 py-2 rounded disabled:opacity-50"
          disabled={!form.title || !form.source_url || create.isPending}
          onClick={() => create.mutate()}>
          {create.isPending ? 'Registering…' : 'Register video'}
        </button>
      </Card>

      <Card padding="none" className="mt-4">
        {isLoading && <div className="p-4 text-sm text-gray-500">Loading…</div>}
        {error && <div className="p-4 text-sm text-red-600">{(error as Error).message}</div>}
        {!isLoading && !error && videos.length === 0 && (
          <div className="p-4 text-sm text-gray-500">No videos yet.</div>
        )}
        {videos.map((v) => (
          <div key={v.id} className="border-b last:border-0">
            <div className="flex items-center justify-between p-3">
              <div>
                <div className="text-sm font-medium">{v.title}</div>
                <code className="text-xs text-gray-500">[peanut_video slug="{v.slug}"]</code>
              </div>
              <div className="flex items-center gap-2">
                <button className="text-xs px-2 py-1 border rounded"
                  onClick={() => { navigator.clipboard.writeText(`[peanut_video slug="${v.slug}"]`); toast.success('Shortcode copied.'); }}>
                  Copy
                </button>
                <button className="text-xs px-2 py-1 border rounded"
                  onClick={() => setExpanded(expanded === v.id ? null : v.id)}>
                  {expanded === v.id ? 'Hide analytics' : 'Analytics'}
                </button>
                <button className="text-xs px-2 py-1 border rounded text-red-600"
                  onClick={async () => { if (await confirm({ title: 'Remove video?', message: 'It will stop rendering and disappear from this list.' })) remove.mutate(v.id); }}>
                  Remove
                </button>
              </div>
            </div>
            {expanded === v.id && <div className="p-3 bg-gray-50"><VideoAnalyticsPanel videoId={v.id} hubEmbedUrl={v.embed_url} /></div>}
          </div>
        ))}
      </Card>
    </Layout>
  );
}
```

(`VideoAnalyticsPanel` is built in Task 6; create a temporary stub now so the page compiles: see Step 3.)

- [ ] **Step 3: Create a stub** `frontend/src/components/videos/VideoAnalyticsPanel.tsx` so the page compiles this task (real impl in Task 6):

```tsx
export function VideoAnalyticsPanel(_props: { videoId: number; hubEmbedUrl: string }) {
  return <div className="text-xs text-gray-500">Analytics loading…</div>;
}
```

- [ ] **Step 4: Add nav entry.** In `frontend/src/components/layout/Sidebar.tsx`, add to the `navigation` array after the `Links` entry (import an icon already imported from `lucide-react`; reuse `Film` — add `Film` to the existing lucide import line):

```ts
  { name: 'Videos', href: '/videos', icon: Film },
```

- [ ] **Step 5: Add route.** In `frontend/src/App.tsx`, add the import `import Videos from './pages/Videos';` with the other page imports and a route with the others:

```tsx
        <Route path="/videos" element={<Videos />} />
```

- [ ] **Step 6: Typecheck + build.** Run `cd frontend && npm run build`. Expected: `tsc --noEmit` passes and Vite emits to `../assets/dist`. Fix any type error before continuing.

- [ ] **Step 7: Commit.**
```bash
git add frontend/src/pages/Videos.tsx frontend/src/components/videos/VideoAnalyticsPanel.tsx frontend/src/components/layout/Sidebar.tsx frontend/src/App.tsx
git commit -m "feat(videos): wp-admin Videos page (media picker + paste-URL) + nav/route"
```

---

### Task 5: `[peanut_video]` shortcode

**Files:**
- Modify: `includes/class-connect-videos.php`
- Test: `tests/phpunit/unit/VideosTest.php`

- [ ] **Step 1: Add failing tests** to `VideosTest.php`:

```php
    public function test_shortcode_without_slug_renders_comment(): void {
        $out = Peanut_Connect_Videos::shortcode([]);
        $this->assertStringContainsString('Peanut Video: No slug', $out);
    }

    public function test_shortcode_renders_responsive_hub_iframe(): void {
        global $peanut_test_options;
        $peanut_test_options['peanut_connect_hub_url'] = 'https://hub.example.com/';
        $out = Peanut_Connect_Videos::shortcode(['slug' => 'promo-abc']);
        $this->assertStringContainsString('https://hub.example.com/video/promo-abc/embed', $out);
        $this->assertStringContainsString('<iframe', $out);
        $this->assertStringContainsString('padding-top:56.25%', $out); // 16:9 responsive wrapper
    }

    public function test_shortcode_not_connected_renders_nothing_visible(): void {
        $out = Peanut_Connect_Videos::shortcode(['slug' => 'x']);
        $this->assertStringContainsString('not connected', strtolower($out));
        $this->assertStringNotContainsString('<iframe', $out);
    }
```

- [ ] **Step 2: Run, expect fail** — no `shortcode` method.

- [ ] **Step 3: Add the shortcode** to `class-connect-videos.php`. Add registration in a new static `init()` and call it from the plugin (Step 4), and the handler:

```php
    public static function init(): void {
        add_shortcode('peanut_video', [self::class, 'shortcode']);
    }

    public static function shortcode($atts): string {
        $atts = shortcode_atts(['slug' => '', 'max_width' => '', 'autoplay' => ''], $atts, 'peanut_video');
        $slug = sanitize_title((string) $atts['slug']);
        if ($slug === '') {
            return '<!-- Peanut Video: No slug specified -->';
        }

        $hub_url = (string) get_option('peanut_connect_hub_url', '');
        if ($hub_url === '') {
            $msg = '<!-- Peanut Video: site not connected to a Hub install -->';
            if (current_user_can('manage_options')) {
                $msg .= '<p style="font-size:12px;color:#a00">Peanut Video: this site is not connected to a Hub install.</p>';
            }
            return $msg;
        }

        $src = trailingslashit($hub_url) . 'video/' . rawurlencode($slug) . '/embed';
        if ($atts['autoplay'] !== '') {
            $src = add_query_arg('autoplay', '1', $src);
        }

        $style_wrap = 'position:relative;width:100%;padding-top:56.25%;';
        if ($atts['max_width'] !== '') {
            $mw = preg_replace('/[^0-9]/', '', (string) $atts['max_width']);
            if ($mw !== '') {
                $style_wrap = 'max-width:' . $mw . 'px;margin:0 auto;' . $style_wrap;
            }
        }

        return sprintf(
            '<div class="peanut-video" style="%s"><iframe src="%s" title="Video" all=\"fullscreen; encrypted-media\" loading="lazy" style="position:absolute;inset:0;width:100%%;height:100%%;border:0" allowfullscreen></iframe></div>',
            esc_attr($style_wrap),
            esc_url($src)
        );
    }
```

- [ ] **Step 4: Register the shortcode.** In `peanut-connect.php` `register_api_routes()` is REST-only; instead add to the plugin's main `init` hook area. Find where `Peanut_Connect_Forms` registers its shortcode (it self-registers via its own `add_shortcode` at class-load or an init). Mirror that: in `peanut-connect.php`, where other modules' non-REST init runs (search for `add_action('init'` or the `is_hub_connected()` block), add:

```php
        Peanut_Connect_Videos::init();
```

If `class-connect-forms.php` registers its shortcode unconditionally at file scope or via a static `init()` called from `peanut-connect.php`, match that exact placement so `[peanut_video]` is always registered (shortcodes must render on the public front end, not gated to admin).

- [ ] **Step 5: Run, expect pass** — 3 new tests green.

- [ ] **Step 6: Commit.**
```bash
git add includes/class-connect-videos.php peanut-connect.php tests/phpunit/unit/VideosTest.php
git commit -m "feat(videos): [peanut_video] shortcode (responsive Hub embed, graceful when unconnected)"
```

---

### Task 6: wp-admin analytics panel (incl. drop-off curve)

**Files:**
- Modify: `frontend/src/components/videos/VideoAnalyticsPanel.tsx` (replace the Task 4 stub)
- Test: `frontend/src/components/videos/VideoAnalyticsPanel.test.tsx`

- [ ] **Step 1: Write the failing test** — `frontend/src/components/videos/VideoAnalyticsPanel.test.tsx`:

```tsx
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { describe, it, expect, vi } from 'vitest';
import { VideoAnalyticsPanel } from './VideoAnalyticsPanel';
import { videosApi } from '../../api/videos';

vi.mock('../../api/videos');

function wrap(ui: React.ReactNode) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return <QueryClientProvider client={qc}>{ui}</QueryClientProvider>;
}

describe('VideoAnalyticsPanel', () => {
  it('renders metrics and a drop-off bar per bucket', async () => {
    (videosApi.analytics as any).mockResolvedValue({
      daily_views: { '2026-05-19': 4 }, avg_watch_time: 12.5, completion_rate: 50,
      unique_viewers: 3, total_plays: 4,
      drop_off_all_time: { '0%': 4, '50%': 2, '100%': 1 }, days: 30,
    });
    render(wrap(<VideoAnalyticsPanel videoId={7} hubEmbedUrl="https://hub/v/x/embed" />));
    await waitFor(() => expect(screen.getByText(/Total plays/i)).toBeInTheDocument());
    expect(screen.getByText('4')).toBeInTheDocument();
    expect(screen.getByText(/50%/)).toBeInTheDocument();
    expect(screen.getAllByTestId('dropoff-bar').length).toBe(3);
  });
});
```

- [ ] **Step 2: Run, expect fail** — stub has no metrics/bars.

- [ ] **Step 3: Replace `VideoAnalyticsPanel.tsx`:**

```tsx
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { videosApi } from '../../api/videos';

export function VideoAnalyticsPanel({ videoId, hubEmbedUrl }: { videoId: number; hubEmbedUrl: string }) {
  const [days, setDays] = useState<7 | 30 | 90>(30);
  const { data, isLoading, error } = useQuery({
    queryKey: ['video-analytics', videoId, days],
    queryFn: () => videosApi.analytics(videoId, days),
  });

  if (isLoading) return <div className="text-xs text-gray-500">Loading analytics…</div>;
  if (error) return <div className="text-xs text-red-600">{(error as Error).message}</div>;
  if (!data) return null;

  const buckets = Object.entries(data.drop_off_all_time);
  const max = Math.max(1, ...buckets.map(([, n]) => n));

  return (
    <div className="space-y-3">
      <div className="flex gap-2 text-xs">
        {[7, 30, 90].map((d) => (
          <button key={d} onClick={() => setDays(d as 7 | 30 | 90)}
            className={`px-2 py-1 border rounded ${days === d ? 'bg-black text-white' : ''}`}>{d}d</button>
        ))}
      </div>
      <div className="grid grid-cols-2 sm:grid-cols-5 gap-2 text-xs">
        <Metric label="Total plays" value={data.total_plays} />
        <Metric label="Unique viewers" value={data.unique_viewers} />
        <Metric label="Avg watch (s)" value={data.avg_watch_time} />
        <Metric label="Completion" value={`${data.completion_rate}%`} />
        <Metric label="Window" value={`${data.days}d`} />
      </div>
      <div>
        <div className="text-xs font-medium mb-1">Drop-off (all-time)</div>
        <div className="flex items-end gap-1 h-24">
          {buckets.map(([pct, n]) => (
            <div key={pct} className="flex-1 flex flex-col items-center justify-end">
              <div data-testid="dropoff-bar" className="w-full bg-amber-500 rounded-t"
                style={{ height: `${(n / max) * 100}%` }} title={`${pct}: ${n}`} />
              <span className="mt-1 text-[10px] text-gray-500">{pct}</span>
            </div>
          ))}
        </div>
      </div>
      <a href={hubEmbedUrl.replace(/\/video\/.*$/, '')} target="_blank" rel="noreferrer"
        className="text-xs text-blue-600 underline">Open full analytics in Hub →</a>
    </div>
  );
}

function Metric({ label, value }: { label: string; value: number | string }) {
  return (
    <div className="bg-white border rounded p-2">
      <div className="text-gray-500">{label}</div>
      <div className="text-sm font-semibold">{value}</div>
    </div>
  );
}
```

- [ ] **Step 4: Run, expect pass** — `cd frontend && npm run test:run -- VideoAnalyticsPanel` → green. Then `npm run build` to confirm types.

- [ ] **Step 5: Commit.**
```bash
git add frontend/src/components/videos/VideoAnalyticsPanel.tsx frontend/src/components/videos/VideoAnalyticsPanel.test.tsx
git commit -m "feat(videos): wp-admin analytics panel with drop-off curve + day window"
```

---

### Task 7: Dynamic Gutenberg block delegating to the shortcode

**Files:**
- Create: `blocks/peanut-video/block.json`
- Create: `frontend/src/blocks/peanut-video.tsx`
- Modify: `frontend/vite.config.ts` (add a second entry for the block editor script)
- Modify: `includes/class-connect-videos.php` (register the dynamic block; render via the shortcode)
- Modify: `peanut-connect.php` (call block registration)
- Test: `tests/phpunit/unit/VideosTest.php`

- [ ] **Step 1: Add a failing test** to `VideosTest.php` proving the block render callback reuses the shortcode:

```php
    public function test_block_render_callback_delegates_to_shortcode(): void {
        global $peanut_test_options;
        $peanut_test_options['peanut_connect_hub_url'] = 'https://hub.example.com';
        $html = Peanut_Connect_Videos::render_block(['slug' => 'promo-abc'], '');
        $this->assertStringContainsString('https://hub.example.com/video/promo-abc/embed', $html);
        $this->assertStringContainsString('<iframe', $html);
    }
```

- [ ] **Step 2: Run, expect fail** — no `render_block`.

- [ ] **Step 3: Add `render_block` + `register_block`** to `class-connect-videos.php`:

```php
    public static function register_block(): void {
        if (!function_exists('register_block_type')) {
            return;
        }
        register_block_type(PEANUT_CONNECT_PLUGIN_DIR . 'blocks/peanut-video', [
            'render_callback' => [self::class, 'render_block'],
        ]);
    }

    public static function render_block($attributes, $content): string {
        $slug = isset($attributes['slug']) ? (string) $attributes['slug'] : '';
        return self::shortcode(['slug' => $slug]);
    }
```

- [ ] **Step 4: Create `blocks/peanut-video/block.json`:**

```json
{
  "$schema": "https://schemas.wp.org/trunk/block.json",
  "apiVersion": 3,
  "name": "peanut-connect/video",
  "title": "Peanut Video",
  "category": "embed",
  "icon": "video-alt3",
  "description": "Insert a Hub-tracked video by slug.",
  "attributes": { "slug": { "type": "string", "default": "" } },
  "editorScript": "file:../../assets/dist/js/peanut-video-block.js",
  "supports": { "html": false }
}
```

- [ ] **Step 5: Create the editor script** `frontend/src/blocks/peanut-video.tsx` (uses the global `wp` block APIs; no extra npm deps):

```tsx
declare const wp: any;

const { registerBlockType } = wp.blocks;
const { useBlockProps, InspectorControls } = wp.blockEditor;
const { PanelBody, TextControl } = wp.components;
const { createElement: el, Fragment } = wp.element;

registerBlockType('peanut-connect/video', {
  edit: ({ attributes, setAttributes }: any) => {
    const props = useBlockProps();
    return el(Fragment, {},
      el(InspectorControls, {},
        el(PanelBody, { title: 'Video' },
          el(TextControl, {
            label: 'Video slug',
            help: 'From the Connect → Videos screen (the value in [peanut_video slug="…"]).',
            value: attributes.slug || '',
            onChange: (slug: string) => setAttributes({ slug }),
          })
        )
      ),
      el('div', props,
        attributes.slug
          ? el('p', {}, `Peanut Video: ${attributes.slug} (renders on the front end)`)
          : el('p', {}, 'Peanut Video — set a slug in the block sidebar.')
      )
    );
  },
  save: () => null, // dynamic block: PHP render_callback
});
```

- [ ] **Step 6: Add the block entry to Vite.** In `frontend/vite.config.ts`, change `build.rollupOptions` to include a second input. Replace the existing `rollupOptions` block with:

```ts
    rollupOptions: {
      input: {
        main: 'src/main.tsx',
        'peanut-video-block': 'src/blocks/peanut-video.tsx',
      },
      output: {
        entryFileNames: 'js/[name].js',
        chunkFileNames: 'js/[name]-[hash].js',
        assetFileNames: (assetInfo) => {
          if (assetInfo.name?.endsWith('.css')) {
            return 'css/[name][extname]';
          }
          return 'assets/[name]-[hash][extname]';
        },
      },
    },
```

(Confirm `src/main.tsx` is the existing SPA entry by reading `frontend/index.html` / current `vite.config.ts`; if the existing entry differs, keep its real name and only ADD the `peanut-video-block` input.)

- [ ] **Step 7: Register the block in the plugin.** In `peanut-connect.php`, next to where `Peanut_Connect_Videos::init();` was added (Task 5 Step 4), add on the `init` hook:

```php
        add_action('init', ['Peanut_Connect_Videos', 'register_block']);
```

- [ ] **Step 8: Build + test.** Run `cd frontend && npm run build` (emits `assets/dist/js/peanut-video-block.js`). Run the PHP test command filtered to `VideosTest` → the new `test_block_render_callback_delegates_to_shortcode` plus all prior pass.

- [ ] **Step 9: Commit.**
```bash
git add blocks/peanut-video/block.json frontend/src/blocks/peanut-video.tsx frontend/vite.config.ts includes/class-connect-videos.php peanut-connect.php tests/phpunit/unit/VideosTest.php
git commit -m "feat(videos): dynamic Gutenberg block delegating to [peanut_video] shortcode"
```

---

### Task 8: Version bump + changelog + full verification

**Files:**
- Modify: `peanut-connect.php` (header `Version:` + `PEANUT_CONNECT_VERSION`)
- Modify: `readme.txt` (Stable tag + changelog section, if present)
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Read current version.** `grep -nE "Version:|PEANUT_CONNECT_VERSION|Stable tag" peanut-connect.php readme.txt`. Bump the PATCH→MINOR per the project's rule (new feature = MINOR). Record old/new.

- [ ] **Step 2: Update the three version locations** to the new MINOR version (e.g. if current is `3.7.31`, new is `3.8.0`): the `* Version:` plugin header, `define('PEANUT_CONNECT_VERSION', '…')`, and `readme.txt` `Stable tag:`.

- [ ] **Step 3: Add a CHANGELOG.md entry** at the top (match the existing "Keep a Changelog" format) under the new version + today's date:

```markdown
## [<new-version>] - 2026-05-19

### Added
- **Videos module.** New Connect → Videos screen registers a WP-media or external-URL video with Hub (poster + captions optional), inserts it via the `[peanut_video slug="…"]` shortcode or the "Peanut Video" block, and shows engagement analytics (plays, unique viewers, avg watch, completion, drop-off curve) without leaving WordPress. Proxies the Hub videos API; media stays WP/CDN-hosted.
```

Mirror the same entry into `readme.txt`'s changelog section if it maintains one.

- [ ] **Step 4: Full verification.**
  - PHP: run the full unit suite (the Step 0 command without a filter) → all green incl. `VideosTest`.
  - Frontend: `cd frontend && npm run test:run` → all green incl. `videos`/`VideoAnalyticsPanel`.
  - Build: `cd frontend && npm run build` → tsc clean, emits `assets/dist/js/main.js` + `assets/dist/js/peanut-video-block.js`.

- [ ] **Step 5: Commit.**
```bash
git add peanut-connect.php readme.txt CHANGELOG.md
git commit -m "chore(release): bump to <new-version> — Videos module"
```

---

## Self-Review (completed by plan author)

**Spec coverage (spec §2–4):**
- §2a `class-connect-videos.php` proxy mirroring marketing → Task 1 ✓
- §2a REST routes under `peanut-connect/v1`, admin-gated, `{success,data}` passthrough, not-connected handled → Tasks 1–2 ✓
- §2b `Videos.tsx` media-picker page + paste-URL seam (future CDN) → Task 4 ✓
- §2c capability gating (`check_admin_permission`) + not-connected degradation (412 from `forward`, shortcode notice) → Tasks 1, 5 ✓
- §3a `[peanut_video]` responsive shortcode from `peanut_connect_hub_url` → Task 5 ✓
- §3b/3c dynamic Gutenberg block, picker-by-slug, no register-on-insert (block only selects; PHP renders) → Task 7 ✓
- §3d graceful failure (no slug / not connected → comment + admin notice, no broken iframe) → Task 5 ✓
- §4 analytics panel: 6 metrics + drop-off + 7/30/90 window + deep link → Task 6 ✓
- nav/route integration → Task 4 ✓; release hygiene → Task 8 ✓

**Placeholder scan:** none — every code step has complete code. Two explicit *verification* steps (Task 2 Step 2 mock mechanism, Task 7 Step 6 entry name) instruct reading a real file and adapting one line; these are environment confirmations, not placeholders, and have explicit BLOCKED fallbacks.

**Type/contract consistency:** `videosApi` (list/create/update/remove/analytics) defined Task 3, consumed Tasks 4 & 6 with matching signatures. `VideoAnalytics.drop_off_all_time` (Task 3) matches the panel (Task 6) and the merged HUB key. Shortcode `slug` attr (Task 5) reused by the block render callback (Task 7). `forward()` envelope passthrough (Task 1) consistent with the SPA interceptor that unwraps `{success,data}`.

**Notes for executor:** Plan B subagents work in `/Users/nattyb/Documents/Peanut/PEANUT-CONNECT/.claude/worktrees/connect-video-wp` on branch `feat/connect-video-wp-module`. Two tasks (2, 7) require reading a real harness/config file and adapting ONE line to the actual mechanism — follow the explicit instruction there; do not guess silently (BLOCKED fallbacks given). The HUB videos API is live on `hub.peanutgraphic.com` (Plan A, merged) — integration is verifiable end-to-end against a connected site if desired.
