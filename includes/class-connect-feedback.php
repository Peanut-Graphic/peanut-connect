<?php
/**
 * Visual feedback module — relays the same-origin WP REST endpoints used by
 * the feedback widget (built in a later task) to Hub's
 * /api/v1/connect/feedback* API, signed with the site's existing Hub key.
 *
 * Pin endpoints (list/create/update) accept either a logged-in agency user
 * (current_user_can('edit_posts')) or a valid review token. The replies
 * endpoints are agency-only in v1 — Hub's reply index can include
 * is_internal replies, and review-token clients must never reach those.
 *
 * @package Peanut_Connect
 */

if (! defined('ABSPATH')) {
    exit;
}

class Peanut_Connect_Feedback {

    /** Cookie that carries a matched review token across the site for a reviewer. */
    const REVIEW_COOKIE = 'pp_review';

    /**
     * Whitelist + coerce the store payload; the agency flag is decided by
     * the SERVER based on the authenticated caller, never trusted from the
     * client-supplied request body.
     */
    public static function build_store_payload(array $req, bool $is_agency): array {
        return [
            'page_url'         => (string) ($req['page_url'] ?? ''),
            'page_title'       => isset($req['page_title']) ? (string) $req['page_title'] : null,
            'anchor_selector'  => isset($req['anchor_selector']) ? (string) $req['anchor_selector'] : null,
            'anchor_x'         => (float) ($req['anchor_x'] ?? 0),
            'anchor_y'         => (float) ($req['anchor_y'] ?? 0),
            'viewport_width'   => isset($req['viewport_width']) ? (int) $req['viewport_width'] : null,
            'author_name'      => (string) ($req['author_name'] ?? ''),
            'author_is_agency' => $is_agency, // forced server-side; never trust the request body.
            'body'             => (string) ($req['body'] ?? ''),
        ];
    }

    public static function init(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_action('admin_menu', [self::class, 'admin_menu']);
        add_action('init', [self::class, 'maybe_persist_review_cookie']);
        self::boot_frontend();
    }

    /**
     * Add a "Feedback Review" subpage under the End-to-End menu where an
     * agency admin can set/generate the per-site review token and copy the
     * client review link (the ?pp_review=… surface). Only registered when
     * the feedback module boots (i.e. the site is Hub-connected).
     */
    public static function admin_menu(): void {
        add_submenu_page(
            'peanut-connect-app',
            __('Mark It Up', 'peanut-connect'),
            __('Mark It Up', 'peanut-connect'),
            'manage_options',
            'peanut-connect-feedback-review',
            [self::class, 'render_admin_page']
        );
    }

    /**
     * Render + handle the review-token settings page. Admin-only, nonce-gated.
     */
    public static function render_admin_page(): void {
        if (! current_user_can('manage_options')) {
            return;
        }

        $notice = '';
        if (! empty($_POST['pcf_action']) && check_admin_referer('pcf_review_token')) {
            if ($_POST['pcf_action'] === 'generate') {
                $token = bin2hex(random_bytes(20)); // 40-char hex secret
            } else {
                $token = sanitize_text_field(wp_unslash($_POST['pcf_review_token'] ?? ''));
            }
            update_option('peanut_connect_feedback_review_token', $token);
            $notice = $token === ''
                ? __('Review token cleared — client review links are now disabled.', 'peanut-connect')
                : __('Review token saved.', 'peanut-connect');
        }

        $token   = (string) get_option('peanut_connect_feedback_review_token', '');
        $example = $token !== '' ? esc_url(add_query_arg('pp_review', $token, home_url('/'))) : '';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Mark It Up — Client Review Link', 'peanut-connect'); ?></h1>
            <?php if ($notice !== '') : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
            <?php endif; ?>

            <p style="max-width:640px">
                <?php esc_html_e('Set a review token to let clients leave on-page feedback without a WordPress login. Logged-in editors already see the feedback widget and do NOT need this token. Share the link below with a reviewer — anyone with it can drop pinned notes on the site (their replies stay agency-only).', 'peanut-connect'); ?>
            </p>

            <form method="post">
                <?php wp_nonce_field('pcf_review_token'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="pcf_review_token"><?php esc_html_e('Review token', 'peanut-connect'); ?></label></th>
                        <td>
                            <input name="pcf_review_token" id="pcf_review_token" type="text" class="regular-text code" value="<?php echo esc_attr($token); ?>" autocomplete="off" />
                            <p class="description"><?php esc_html_e('A shared secret. Leave blank and save to disable client review links.', 'peanut-connect'); ?></p>
                        </td>
                    </tr>
                    <?php if ($example !== '') : ?>
                    <tr>
                        <th scope="row"><?php esc_html_e('Share link', 'peanut-connect'); ?></th>
                        <td>
                            <input type="text" class="large-text code" readonly onclick="this.select()" value="<?php echo esc_attr($example); ?>" />
                            <p class="description"><?php esc_html_e('Send a reviewer this link. Once they open it, review mode follows them across the whole site (for 30 days) — no need to re-add the token to every page. You can also append ?pp_review=… to any specific page URL.', 'peanut-connect'); ?></p>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>
                <p>
                    <button type="submit" name="pcf_action" value="save" class="button button-primary"><?php esc_html_e('Save token', 'peanut-connect'); ?></button>
                    <button type="submit" name="pcf_action" value="generate" class="button"><?php esc_html_e('Generate a new token', 'peanut-connect'); ?></button>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Hook the frontend widget enqueue + footer container behind the
     * review-mode gate. Split out from init() so it can be unit-tested
     * (or re-bootstrapped) independently of the REST route registration.
     */
    public static function boot_frontend(): void {
        add_action('wp_enqueue_scripts', [self::class, 'enqueue']);
        add_action('wp_footer', [self::class, 'render_root']);
    }

    /**
     * Review mode is active for a logged-in agency user (can edit_posts),
     * or for any visitor carrying a ?pp_review=<token> query arg matching
     * the site's configured review token. hash_equals() is used to avoid
     * timing attacks, and an empty configured token never matches (an
     * empty request token is also rejected) so a freshly-installed site
     * with no token set never accidentally opens review mode to the public.
     */
    private static function review_active(): bool {
        if (is_user_logged_in() && current_user_can('edit_posts')) {
            return true;
        }

        $expected = (string) get_option('peanut_connect_feedback_review_token', '');
        if ($expected === '') {
            return false;
        }

        // Token can arrive on the URL (?pp_review=…) or, once a reviewer has
        // followed one tokenized link, from the cookie we set for them — so
        // they can browse the whole site without re-appending the token to
        // every internal link.
        $url_token    = isset($_GET['pp_review']) ? sanitize_text_field(wp_unslash($_GET['pp_review'])) : '';
        $cookie_token = isset($_COOKIE[self::REVIEW_COOKIE]) ? sanitize_text_field(wp_unslash($_COOKIE[self::REVIEW_COOKIE])) : '';

        return ($url_token !== '' && hash_equals($expected, $url_token))
            || ($cookie_token !== '' && hash_equals($expected, $cookie_token));
    }

    /**
     * When a reviewer arrives on a tokenized URL (?pp_review=<token>) that
     * matches the site's review token, drop a cookie so review mode follows
     * them across the whole site (plain internal links don't carry the query
     * arg). Runs on `init` — before headers are sent — so setcookie() works.
     * The cookie only ever holds the same token the reviewer already had in
     * the link, so it grants no access they didn't already have.
     */
    public static function maybe_persist_review_cookie(): void {
        if (empty($_GET['pp_review'])) {
            return;
        }
        $expected = (string) get_option('peanut_connect_feedback_review_token', '');
        $token    = sanitize_text_field(wp_unslash($_GET['pp_review']));
        if ($expected === '' || $token === '' || ! hash_equals($expected, $token)) {
            return;
        }
        if (isset($_COOKIE[self::REVIEW_COOKIE]) && hash_equals($token, (string) $_COOKIE[self::REVIEW_COOKIE])) {
            return; // already set to this token
        }
        setcookie(self::REVIEW_COOKIE, $token, [
            'expires'  => time() + 30 * DAY_IN_SECONDS,
            'path'     => defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/',
            'domain'   => defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::REVIEW_COOKIE] = $token; // honor it on this same request
    }

    /**
     * Enqueue the feedback widget script (review mode only). The widget
     * renders into a Shadow DOM, so the page-level stylesheet can't pierce
     * it — instead the CSS source is injected as a JS string the widget
     * attaches inside the shadow root.
     */
    public static function enqueue(): void {
        if (! self::review_active()) {
            return;
        }

        wp_enqueue_script(
            'peanut-connect-feedback',
            plugins_url('assets/js/feedback.js', dirname(__FILE__)),
            [],
            // Version the asset by the plugin version so each release busts the
            // browser cache — a hardcoded constant would serve stale widget JS
            // forever after an update (the inline CSS updates, the JS wouldn't).
            defined('PEANUT_CONNECT_VERSION') ? PEANUT_CONNECT_VERSION : '1.0.0',
            true
        );

        $css = (string) @file_get_contents(plugin_dir_path(dirname(__FILE__)) . 'assets/css/feedback.css');
        wp_add_inline_script(
            'peanut-connect-feedback',
            'window.__ppFeedbackCss = ' . wp_json_encode($css) . ';',
            'before'
        );

        $token = isset($_GET['pp_review']) ? sanitize_text_field(wp_unslash($_GET['pp_review'])) : '';
        wp_localize_script('peanut-connect-feedback', 'peanutConnectFeedback', [
            'restUrl'     => esc_url_raw(rest_url('peanut-connect/v1/feedback')),
            'nonce'       => wp_create_nonce('wp_rest'),
            'isAgency'    => self::is_agency(),
            'reviewToken' => $token,
        ]);
    }

    /**
     * Print the widget's mount point just before </body> (review mode only).
     */
    public static function render_root(): void {
        if (! self::review_active()) {
            return;
        }

        echo '<div id="peanut-connect-feedback-root"></div>';
    }

    public static function register_routes(): void {
        $ns = PEANUT_CONNECT_API_NAMESPACE;

        register_rest_route($ns, '/feedback', [
            ['methods' => 'GET',  'callback' => [self::class, 'list_feedback'], 'permission_callback' => [self::class, 'can_review']],
            ['methods' => 'POST', 'callback' => [self::class, 'create'],        'permission_callback' => [self::class, 'can_review']],
        ]);
        register_rest_route($ns, '/feedback/(?P<id>\d+)', [
            'methods'             => 'PATCH',
            'callback'            => [self::class, 'update'],
            'permission_callback' => [self::class, 'can_review'],
        ]);
        register_rest_route($ns, '/feedback/(?P<id>\d+)/replies', [
            ['methods' => 'GET',  'callback' => [self::class, 'list_replies'], 'permission_callback' => [self::class, 'can_review_agency']],
            ['methods' => 'POST', 'callback' => [self::class, 'create_reply'], 'permission_callback' => [self::class, 'can_review_agency']],
        ]);
    }

    /**
     * Pin-level gate: a logged-in agency user (can edit_posts) OR a request
     * bearing a valid review token. Clients reviewing via a shared link use
     * the token; they never get a WP login.
     */
    public static function can_review(\WP_REST_Request $request): bool {
        if (self::is_agency()) {
            return true;
        }

        $token = (string) $request->get_header('X-Peanut-Review-Token');
        $expected = (string) get_option('peanut_connect_feedback_review_token', '');

        return $expected !== '' && $token !== '' && hash_equals($expected, $token);
    }

    /**
     * Replies-level gate: agency only, in v1. Deliberately does NOT accept
     * the review token — Hub's reply index can return is_internal replies,
     * and a review-token client must never see those.
     */
    public static function can_review_agency(\WP_REST_Request $request): bool {
        return self::is_agency();
    }

    private static function is_agency(): bool {
        return is_user_logged_in() && current_user_can('edit_posts');
    }

    /**
     * Relay a request to Hub's connect-feedback API, signed with the site's
     * Hub key, and forward Hub's JSON body + status code back to the caller.
     */
    private static function relay(string $method, string $path, ?array $body = null) {
        $hub_url = (string) get_option('peanut_connect_hub_url', '');
        $api_key = Peanut_Connect_Auth::get_hub_api_key();

        if ($hub_url === '' || $api_key === '') {
            return new \WP_Error(
                'not_connected',
                __('This site is not connected to a Hub install yet.', 'peanut-connect'),
                ['status' => 503]
            );
        }

        $endpoint = rtrim($hub_url, '/') . '/api/v1/connect' . $path;
        $encoded  = $body === null ? '' : (string) wp_json_encode($body);

        $headers = [
            'Authorization' => 'Bearer ' . $api_key,
            'Accept'        => 'application/json',
        ];
        // Only send Content-Type when there is a body. A bodyless GET carrying
        // Content-Type: application/json is routed by Hub to the POST/store
        // handler (verified end-to-end locally), which 422s the list/replies
        // GETs and breaks pin loading. GETs must go out without it.
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        $args = [
            'method'  => $method,
            'timeout' => 15,
            'headers' => array_merge(
                $headers,
                Peanut_Connect_Auth::outbound_signature_headers($method, $endpoint, $encoded)
            ),
        ];
        if ($body !== null) {
            $args['body'] = $encoded;
        }

        $response = wp_remote_request($endpoint, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw    = (string) wp_remote_retrieve_body($response);
        $data   = json_decode($raw, true);

        if (! is_array($data)) {
            $data = ['raw' => $raw];
        }

        return new \WP_REST_Response($data, $status > 0 ? $status : 502);
    }

    public static function list_feedback(\WP_REST_Request $request) {
        $page = rawurlencode((string) $request->get_param('page_url'));
        return self::relay('GET', '/feedback?page_url=' . $page);
    }

    public static function create(\WP_REST_Request $request) {
        $payload = self::build_store_payload($request->get_json_params() ?: [], self::is_agency());
        return self::relay('POST', '/feedback', $payload);
    }

    public static function update(\WP_REST_Request $request) {
        $id = (int) $request['id'];
        $in = $request->get_json_params() ?: [];
        // Token clients (review-link visitors) may only check a note off (status);
        // only an authenticated agency caller may rewrite the note body itself.
        $allowed = self::is_agency() ? ['status', 'body'] : ['status'];
        $body = array_intersect_key($in, array_flip($allowed));
        return self::relay('PATCH', "/feedback/{$id}", $body);
    }

    public static function list_replies(\WP_REST_Request $request) {
        $id = (int) $request['id'];
        return self::relay('GET', "/feedback/{$id}/replies");
    }

    public static function create_reply(\WP_REST_Request $request) {
        $id = (int) $request['id'];
        $in = $request->get_json_params() ?: [];
        $body = [
            'author_name' => (string) ($in['author_name'] ?? ''),
            'body'        => (string) ($in['body'] ?? ''),
            // Only an agency caller (the only caller who can reach this
            // endpoint in v1) may mark a reply internal.
            'is_internal' => self::is_agency() && ! empty($in['is_internal']),
        ];
        return self::relay('POST', "/feedback/{$id}/replies", $body);
    }
}
