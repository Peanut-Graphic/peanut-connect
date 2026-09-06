<?php
/**
 * Peanut Connect Security
 *
 * Handles security hardening features like hide login, disable comments, etc.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Peanut_Connect_Security {

    /**
     * Initialize security features based on settings
     */
    public static function init(): void {
        // Disable XML-RPC
        if (get_option('peanut_connect_disable_xmlrpc', '0') === '1') {
            add_filter('xmlrpc_enabled', '__return_false');
            add_filter('wp_xmlrpc_server_class', '__return_false');
        }

        // Remove WordPress version from head
        if (get_option('peanut_connect_remove_version', '0') === '1') {
            remove_action('wp_head', 'wp_generator');
            add_filter('the_generator', '__return_empty_string');
            add_filter('style_loader_src', [__CLASS__, 'remove_version_from_assets'], 10, 2);
            add_filter('script_loader_src', [__CLASS__, 'remove_version_from_assets'], 10, 2);
        }

        // Disable comments
        if (get_option('peanut_connect_disable_comments', '0') === '1') {
            self::disable_comments();
        }

        // Hide login
        if (get_option('peanut_connect_hide_login', '0') === '1') {
            $slug = get_option('peanut_connect_login_slug', '');
            if (!empty($slug)) {
                self::hide_login($slug);
            }
        }

        // Block user enumeration
        if (get_option('peanut_connect_block_user_enumeration', '1') === '1') {
            self::block_user_enumeration();
        }
    }

    /**
     * Get all security settings
     */
    public static function get_settings(): array {
        return [
            'hide_login' => [
                'enabled' => get_option('peanut_connect_hide_login', '0') === '1',
                'custom_slug' => get_option('peanut_connect_login_slug', ''),
                'available' => true,
            ],
            'disable_comments' => [
                'enabled' => get_option('peanut_connect_disable_comments', '0') === '1',
                'hide_existing' => get_option('peanut_connect_hide_existing_comments', '0') === '1',
            ],
            'disable_xmlrpc' => get_option('peanut_connect_disable_xmlrpc', '0') === '1',
            'disable_file_editing' => defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT,
            'remove_version' => get_option('peanut_connect_remove_version', '0') === '1',
            'block_user_enumeration' => get_option('peanut_connect_block_user_enumeration', '1') === '1',
        ];
    }

    /**
     * Remove version query string from scripts and styles
     */
    public static function remove_version_from_assets(string $src, string $handle): string {
        if (strpos($src, 'ver=')) {
            $src = remove_query_arg('ver', $src);
        }
        return $src;
    }

    /**
     * Disable comments site-wide
     */
    private static function disable_comments(): void {
        // Disable support for comments and trackbacks in post types
        add_action('admin_init', function () {
            $post_types = get_post_types();
            foreach ($post_types as $post_type) {
                if (post_type_supports($post_type, 'comments')) {
                    remove_post_type_support($post_type, 'comments');
                    remove_post_type_support($post_type, 'trackbacks');
                }
            }
        });

        // Close comments on the front-end
        add_filter('comments_open', '__return_false', 20, 2);
        add_filter('pings_open', '__return_false', 20, 2);

        // Hide existing comments
        if (get_option('peanut_connect_hide_existing_comments', '0') === '1') {
            add_filter('comments_array', '__return_empty_array', 10, 2);
        }

        // Remove comments page from admin menu
        add_action('admin_menu', function () {
            remove_menu_page('edit-comments.php');
        });

        // Remove comments links from admin bar
        add_action('admin_bar_menu', function ($wp_admin_bar) {
            $wp_admin_bar->remove_node('comments');
        }, 999);

        // Redirect any user trying to access comments page
        add_action('admin_init', function () {
            global $pagenow;
            if ($pagenow === 'edit-comments.php') {
                wp_safe_redirect(admin_url());
                exit;
            }
        });

        // Remove comments metabox from dashboard
        add_action('admin_init', function () {
            remove_meta_box('dashboard_recent_comments', 'dashboard', 'normal');
        });

        // Remove comments column from posts list
        add_filter('manage_posts_columns', function ($columns) {
            unset($columns['comments']);
            return $columns;
        });

        add_filter('manage_pages_columns', function ($columns) {
            unset($columns['comments']);
            return $columns;
        });

        // Disable comments REST API endpoints
        add_filter('rest_endpoints', function ($endpoints) {
            if (isset($endpoints['/wp/v2/comments'])) {
                unset($endpoints['/wp/v2/comments']);
            }
            if (isset($endpoints['/wp/v2/comments/(?P<id>[\d]+)'])) {
                unset($endpoints['/wp/v2/comments/(?P<id>[\d]+)']);
            }
            return $endpoints;
        });
    }

    /**
     * Block unauthenticated user (author) enumeration.
     *
     * Stock WordPress publishes the exact login name of any user with content
     * through three unauthenticated vectors:
     *
     *   1. GET /wp-json/wp/v2/users     -> JSON including `slug` (the login name)
     *   2. GET /?author=<id>            -> 301 to /author/<login-name>/
     *   3. GET /wp-sitemap-users-1.xml  -> author archive URLs
     *
     * On a client site the admin login name is half of a credential pair, so
     * all three are closed. The block is scoped to callers that cannot already
     * list users, leaving the block editor, the REST author picker and any
     * authenticated tooling untouched.
     *
     * Hook timing: init() runs on `init` priority 0. Every hook used here fires
     * after `init` -- `rest_endpoints` during REST dispatch, `template_redirect`
     * and `wp_sitemaps_add_provider` during the main query -- so none of them is
     * registered too late to take effect.
     */
    private static function block_user_enumeration(): void {
        add_filter('rest_endpoints', [__CLASS__, 'filter_user_endpoints']);
        // Priority 0: must beat redirect_canonical() (priority 10), which is
        // what turns ?author=<id> into the slug-revealing 301.
        add_action('template_redirect', [__CLASS__, 'block_author_archive'], 0);
        add_filter('wp_sitemaps_add_provider', [__CLASS__, 'remove_users_sitemap'], 10, 2);
    }

    /**
     * Whether the current caller is already entitled to see the user list.
     */
    private static function can_list_users(): bool {
        return function_exists('current_user_can') && current_user_can('list_users');
    }

    /**
     * Remove the public user collection from the REST API.
     *
     * `/wp/v2/users/me` is deliberately preserved: it only ever returns the
     * caller's own record, already 401s when logged out, and the block editor
     * depends on it.
     */
    public static function filter_user_endpoints($endpoints) {
        if (!is_array($endpoints) || self::can_list_users()) {
            return $endpoints;
        }

        unset($endpoints['/wp/v2/users']);
        unset($endpoints['/wp/v2/users/(?P<id>[\d]+)']);

        return $endpoints;
    }

    /**
     * Turn author archives into a 404 for callers who cannot list users.
     */
    public static function block_author_archive(): void {
        if (self::can_list_users() || !is_author()) {
            return;
        }

        global $wp_query;
        if (isset($wp_query) && is_object($wp_query) && method_exists($wp_query, 'set_404')) {
            $wp_query->set_404();
        }

        // redirect_canonical() would otherwise still 301 ?author=<id> to the
        // named archive, leaking the login name we just hid.
        remove_action('template_redirect', 'redirect_canonical');

        status_header(404);
        nocache_headers();
    }

    /**
     * Drop the users provider from core sitemaps (wp-sitemap-users-N.xml).
     */
    public static function remove_users_sitemap($provider, $name) {
        if ($name === 'users') {
            return false;
        }

        return $provider;
    }

    /**
     * Hide WordPress login page with custom slug
     */
    private static function hide_login(string $custom_slug): void {
        // Intercept login page requests
        add_action('init', function () use ($custom_slug) {
            $request_uri = $_SERVER['REQUEST_URI'] ?? '';
            $request_path = parse_url($request_uri, PHP_URL_PATH);
            $trimmed_path = trim($request_path, '/');

            // Serve wp-login.php when accessing custom slug
            if ($trimmed_path === $custom_slug) {
                // Pass query string through (for ?action=logout, etc.)
                $_SERVER['REQUEST_URI'] = '/wp-login.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
                require_once ABSPATH . 'wp-login.php';
                exit;
            }

            // Block direct access to wp-login.php
            if (strpos($request_path, 'wp-login.php') !== false) {
                // Allow POST requests (actual login attempts) with valid referrer.
                // v3.7.21: Referer is attacker-controlled — substring match alone
                // is bypassable with "Referer: https://evil.com/<slug>". Now we
                // verify the Referer host equals this site's host AND the path
                // begins with the custom slug.
                if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                    $referer = $_SERVER['HTTP_REFERER'] ?? '';
                    $ref_host = strtolower((string) wp_parse_url($referer, PHP_URL_HOST));
                    $ref_path = (string) wp_parse_url($referer, PHP_URL_PATH);
                    $site_host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
                    if (
                        $ref_host !== ''
                        && $ref_host === $site_host
                        && strpos(ltrim($ref_path, '/'), $custom_slug) === 0
                    ) {
                        return;
                    }
                }

                // Allow password reset and activation links
                $action = $_GET['action'] ?? '';
                if (in_array($action, ['rp', 'resetpass', 'confirmaction'])) {
                    return;
                }

                // Block access - show 404
                self::show_404();
            }

            // Block access to wp-admin for non-logged-in users
            if (strpos($request_path, 'wp-admin') !== false && !is_user_logged_in()) {
                // Allow admin-ajax.php
                if (strpos($request_path, 'admin-ajax.php') !== false) {
                    return;
                }
                // Allow admin-post.php
                if (strpos($request_path, 'admin-post.php') !== false) {
                    return;
                }

                self::show_404();
            }
        }, 1);

        // Modify login URL in password reset emails, etc.
        add_filter('login_url', function ($login_url, $redirect, $force_reauth) use ($custom_slug) {
            $login_url = str_replace('wp-login.php', $custom_slug, $login_url);
            return $login_url;
        }, 10, 3);

        // Modify logout URL to redirect properly
        add_filter('logout_url', function ($logout_url, $redirect) use ($custom_slug) {
            return add_query_arg('_wpnonce', wp_create_nonce('log-out'), home_url($custom_slug . '?action=logout'));
        }, 10, 2);

        // Fix lostpassword URL
        add_filter('lostpassword_url', function ($lostpassword_url, $redirect) use ($custom_slug) {
            $url = home_url($custom_slug);
            $url = add_query_arg('action', 'lostpassword', $url);
            if (!empty($redirect)) {
                $url = add_query_arg('redirect_to', urlencode($redirect), $url);
            }
            return $url;
        }, 10, 2);

        // Fix register URL
        add_filter('register_url', function ($register_url) use ($custom_slug) {
            return add_query_arg('action', 'register', home_url($custom_slug));
        });

        // Ensure login form points to correct URL
        add_filter('site_url', function ($url, $path, $scheme, $blog_id) use ($custom_slug) {
            if (strpos($path, 'wp-login.php') !== false && $scheme === 'login_post') {
                $url = str_replace('wp-login.php', $custom_slug, $url);
            }
            return $url;
        }, 10, 4);
    }

    /**
     * Show 404 page
     */
    private static function show_404(): void {
        // Decide early, render late.
        //
        // The block itself is detected on `init` priority 1 so we get in front
        // of anything that might leak the custom login slug. The *render* can
        // not happen there: themes register their template classes during
        // `init` itself -- Enfold loads class-font-manager.php on `init`
        // priority 5 -- so including 404.php at priority 1 fatals on
        // `avia_font_manager` before the theme has had a chance to define it.
        //
        // `wp_loaded` fires after the whole of `init` has run, and it fires in
        // every entry point we block: wp-login.php and wp-admin/ both reach it
        // while bootstrapping, before either authenticates or redirects. So the
        // slug stays hidden and the theme is fully loaded by the time we render.
        add_action('wp_loaded', [__CLASS__, 'render_404'], 0);
    }

    /**
     * Render the 404 deferred from show_404(). Public only so it can be used
     * as a hook callback; treat it as internal.
     */
    public static function render_404(): void {
        global $wp_query;

        status_header(404);
        nocache_headers();

        if ($wp_query instanceof \WP_Query) {
            $wp_query->set_404();
        }

        // Try to load theme's 404 template
        $template = get_404_template();
        if ($template && is_readable($template)) {
            include($template);
            exit;
        }

        // Fallback
        wp_die(
            __('Page not found.', 'peanut-connect'),
            __('404 Not Found', 'peanut-connect'),
            ['response' => 404]
        );
    }

    /**
     * Flush rewrite rules when hide login slug changes
     */
    public static function flush_rewrite_rules(): void {
        flush_rewrite_rules();
    }
}
