<?php
/**
 * Plugin Name: End-to-End
 * Plugin URI: https://peanutgraphic.com/peanut-connect
 * Description: End-to-end campaign and site platform for WordPress — runs campaigns, UTM links, popups, forms, and on-site tracking, plus health monitoring, updates, and backups, all wired to a central Peanut Hub.
 * Version: 3.33.0
 * Author: Peanut Graphic
 * Author URI: https://peanutgraphic.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: peanut-connect
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('PEANUT_CONNECT_VERSION', '3.33.0');
define('PEANUT_CONNECT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PEANUT_CONNECT_API_NAMESPACE', 'peanut-connect/v1');

// Composer autoload — bundles peanut/formflow-core, which carries the shared
// signed-update verifier this plugin's self-updater delegates to. Guarded so a
// package built without `composer install --no-dev` surfaces an admin notice
// instead of fataling.
if (file_exists(PEANUT_CONNECT_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once PEANUT_CONNECT_PLUGIN_DIR . 'vendor/autoload.php';
} else {
    add_action('admin_notices', function () {
        if (!current_user_can('update_plugins')) {
            return;
        }
        echo '<div class="notice notice-error"><p><strong>Peanut Connect:</strong> '
            . esc_html__('vendor/ is missing, so update signature verification cannot run. Reinstall from an official release package.', 'peanut-connect')
            . '</p></div>';
    });
}

/**
 * Early Hub Mode filter registration
 * Must happen at file load time (before Suite checks the filter)
 */
$peanut_connect_hub_mode = get_option('peanut_connect_hub_mode', 'standard');
if ($peanut_connect_hub_mode === 'disable_suite') {
    add_filter('peanut_suite_disabled', '__return_true');
}
unset($peanut_connect_hub_mode);

/**
 * Main Peanut Connect class
 */
final class Peanut_Connect {

    /**
     * @var Peanut_Connect
     */
    private static ?Peanut_Connect $instance = null;

    /**
     * Get singleton instance
     */
    public static function get_instance(): Peanut_Connect {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    /**
     * Load required files
     */
    private function load_dependencies(): void {
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-rate-limiter.php';
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-activity-log.php';
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-secret.php';
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-auth.php';
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-key-rotation.php';
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/helpers/transcript-block.php';
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-health.php';
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-updates.php';
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-error-log.php';
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-api.php';
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-self-updater.php';
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-roles.php';

        // Hub tracking and sync classes (v2.3.0+)
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-database.php';
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-tracker.php';
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-hub-sync.php';
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-popup-display.php';

        // Visual feedback widget — review-mode pin/comment overlay (v3.8.0+)
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-feedback.php';
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-approvals.php';
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-approvals-notify.php';

        // Security hardening (v2.5.0+)
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-security.php';

        // API proxy for Hub (v3.3.0+)
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-api-proxy.php';

        // Event banner for Hub (v3.3.0+)
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-event-banner.php';

        // Forms integration for Hub (v3.3.0+)
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-forms.php';

        // ML anomaly detection (v3.7.1+)
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-ml-anomaly.php';

        // Marketing proxy (campaign builder, UTMs, links, analytics) for Hub (v3.7.1+)
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-marketing.php';
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-videos.php';

        // Short-link redirect handler — turns 404 hits on /<slug> into a 302 to Hub's /go/<slug> (v3.7.24+)
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-short-links.php';
        Peanut_Connect_Short_Links::init();

        // Initialize logging early
        Peanut_Connect_Activity_Log::init();
        Peanut_Connect_Error_Log::init();

        // Migration runner — checks DB_VERSION on every plugins_loaded and
        // runs create_tables() (which is dbDelta-based, so idempotent) when
        // the constant has bumped. v3.7.24: previously the schema only
        // migrated on plugin reactivation.
        Peanut_Connect_Database::init();

        // Initialize security features
        Peanut_Connect_Security::init();

        // Initialize event banner
        Peanut_Connect_Event_Banner::init();

        // Initialize forms integration
        Peanut_Connect_Forms::init();

        // Initialize videos integration ([peanut_video] shortcode)
        Peanut_Connect_Videos::init();

        // Initialize the self-updater early so its update-check filter is
        // registered — but ONLY once the site is actually paired with a Hub.
        // The updater phones home to the license/update server; doing that on
        // an unpaired site leaks the Hub relationship's existence (detectable
        // in firewall/packet logs), violating Rule 3 of the Hub↔Edge contract
        // and the named Hub-blind requirement for sensitive clients (Itron).
        // A deployment can force the behaviour either way with the
        // PEANUT_CONNECT_SELF_UPDATE constant (true = always, false = never).
        $self_update = defined('PEANUT_CONNECT_SELF_UPDATE')
            ? (bool) PEANUT_CONNECT_SELF_UPDATE
            : $this->is_hub_connected();
        if ($self_update) {
            new Peanut_Connect_Self_Updater();
        }
    }

    /**
     * Initialize hooks
     */
    private function init_hooks(): void {
        add_action('rest_api_init', [$this, 'register_api_routes']);
        // Register the Peanut Video Gutenberg block (delegates render to the
        // [peanut_video] shortcode). register_block_type() must run on init;
        // init_hooks() itself runs from the priority-0 init bootstrap, so this
        // default-priority handler fires after the plugin has loaded.
        add_action('init', ['Peanut_Connect_Videos', 'register_block']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        Peanut_Connect_Roles::boot(); // scoped UTM Builder role: cap filter + upgrade-safe install
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('admin_head', [$this, 'hide_admin_notices_on_react_page']);
        add_action('admin_notices', [$this, 'maybe_show_rekey_notice']);

        // Add settings link to plugins page
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_settings_link']);

        // Initialize Hub tracking and sync (v2.3.0+)
        if ($this->is_hub_connected()) {
            // Initialize frontend tracking
            Peanut_Connect_Tracker::init();

            // Initialize popup display
            Peanut_Connect_Popup_Display::init();

            // Initialize visual feedback widget (review-mode gate)
            Peanut_Connect_Feedback::init();

            // Initialize approvals module (Mark It Up)
            Peanut_Connect_Approvals::init();
            Peanut_Connect_Approvals_Notify::init();

            // Schedule sync cron
            add_action('peanut_connect_hub_sync', [Peanut_Connect_Hub_Sync::class, 'run_sync']);
            add_action('peanut_connect_hub_heartbeat', [Peanut_Connect_Hub_Sync::class, 'send_heartbeat']);
            // Hub-requested immediate sync (scheduled as a single event from heartbeat
            // response when sync_now=true; v3.7.24: this handler used to live in
            // Hub_Sync::init() which was dead code, so sync_now silently no-op'd).
            add_action('peanut_connect_sync_requested', [Peanut_Connect_Hub_Sync::class, 'run_sync']);

            if (!wp_next_scheduled('peanut_connect_hub_sync')) {
                wp_schedule_event(time(), 'fifteen_minutes', 'peanut_connect_hub_sync');
            }
            if (!wp_next_scheduled('peanut_connect_hub_heartbeat')) {
                wp_schedule_event(time(), 'hourly', 'peanut_connect_hub_heartbeat');
            }
        }

        // Schedule daily cleanup of old synced records (v2.6.3+)
        add_action('peanut_connect_cleanup', [Peanut_Connect_Database::class, 'cleanup_old_records']);
        add_action('peanut_connect_cleanup', [Peanut_Connect_Auth::class, 'purge_expired_nonces']);
        if (!wp_next_scheduled('peanut_connect_cleanup')) {
            wp_schedule_event(time(), 'daily', 'peanut_connect_cleanup');
        }

        // Schedule weekly ML model retraining (v3.7.1+)
        add_action('peanut_ml_connect_train', [$this, 'run_ml_training']);
        if (!wp_next_scheduled('peanut_ml_connect_train')) {
            wp_schedule_event(time(), 'weekly', 'peanut_ml_connect_train');
        }

        // Register custom cron schedule
        add_filter('cron_schedules', [$this, 'add_cron_schedules']);

        // Hub Mode: Hide/disable Suite when connected to Hub (v2.6.0+)
        if ($this->is_hub_connected()) {
            $this->init_hub_mode();
        }
    }

    /**
     * Initialize Hub Mode features
     * When connected to Hub, Suite becomes optional/hidden
     */
    private function init_hub_mode(): void {
        $hub_mode = get_option('peanut_connect_hub_mode', 'standard');

        // Provide filter for other plugins to check Hub Mode status
        add_filter('peanut_connect_hub_mode_active', '__return_true');
        add_filter('peanut_connect_hub_mode', fn() => $hub_mode);

        // Hide Suite admin menu
        if ($hub_mode === 'hide_suite' || $hub_mode === 'disable_suite') {
            add_action('admin_menu', [$this, 'hide_suite_menu'], 999);
            add_filter('peanut_suite_admin_menu_hidden', '__return_true');
        }

        // Disable Suite entirely (prevents loading modules)
        if ($hub_mode === 'disable_suite') {
            add_filter('peanut_suite_disabled', '__return_true');
            // Prevent Suite from initializing
            add_action('plugins_loaded', [$this, 'disable_suite_loading'], 1);
        }
    }

    /**
     * Hide Peanut Suite admin menu
     */
    public function hide_suite_menu(): void {
        remove_menu_page('peanut-app');
    }

    /**
     * Prevent Suite from initializing when in disable mode.
     *
     * v3.7.24: the prior implementation called remove_all_actions('plugins_loaded', 10)
     * which nuked EVERY plugin's priority-10 plugins_loaded handler, not just
     * Suite's — collateral damage across the WP install. Now we surgically
     * remove only Suite's known initializer. The peanut_suite_disabled filter
     * (set in init_hub_mode) is the canonical contract; Suite is expected to
     * check it and bail early. This is the fallback in case Suite's
     * initializer doesn't honor the filter.
     */
    public function disable_suite_loading(): void {
        if (!class_exists('Peanut_Suite')) {
            return;
        }
        // Suite's bootstrap function (defined in peanut-suite.php)
        if (function_exists('peanut_run')) {
            remove_action('plugins_loaded', 'peanut_run', 10);
        }
        // Defensive: also try the class-static initializer if present.
        if (method_exists('Peanut_Suite', 'init')) {
            remove_action('plugins_loaded', ['Peanut_Suite', 'init'], 10);
        }
    }

    /**
     * Check if hub is connected
     */
    public function is_hub_connected(): bool {
        $hub_url = get_option('peanut_connect_hub_url');
        $api_key = Peanut_Connect_Auth::get_hub_api_key();
        return !empty($hub_url) && !empty($api_key);
    }

    /**
     * Add custom cron schedules
     */
    public function add_cron_schedules(array $schedules): array {
        $schedules['fifteen_minutes'] = [
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display' => __('Every 15 Minutes', 'peanut-connect'),
        ];
        return $schedules;
    }

    /**
     * Hide admin notices on React page
     */
    public function hide_admin_notices_on_react_page(): void {
        $screen = get_current_screen();
        if ($screen && strpos($screen->id, 'peanut-connect-app') !== false) {
            remove_all_actions('admin_notices');
            remove_all_actions('all_admin_notices');
        }
    }

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
        $url = admin_url('admin.php?page=peanut-connect-app');
        printf(
            '<div class="notice notice-warning is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
            esc_html__('Peanut End to End: your Hub connection needs to be re-paired after a security-key change.', 'peanut-connect'),
            esc_url($url),
            esc_html__('Re-pair now', 'peanut-connect')
        );
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets(string $hook): void {
        // Check if this is the React SPA page
        if ($hook === 'toplevel_page_peanut-connect-app') {
            $this->enqueue_react_assets();
            return;
        }

        // Legacy settings page CSS
        if ($hook === 'settings_page_peanut-connect') {
            wp_enqueue_style(
                'peanut-connect-admin',
                plugins_url('admin/css/admin.css', __FILE__),
                [],
                PEANUT_CONNECT_VERSION
            );
        }
    }

    /**
     * Enqueue React SPA assets
     */
    private function enqueue_react_assets(): void {
        $dist_path = PEANUT_CONNECT_PLUGIN_DIR . 'assets/dist/';
        $dist_url = plugins_url('assets/dist/', __FILE__);

        // Check if built assets exist
        if (!file_exists($dist_path . 'js/main.js')) {
            return;
        }

        // Enqueue the React app
        wp_enqueue_script(
            'peanut-connect-react',
            $dist_url . 'js/main.js',
            [],
            PEANUT_CONNECT_VERSION,
            true
        );

        // Add module type
        add_filter('script_loader_tag', function($tag, $handle) {
            if ($handle === 'peanut-connect-react') {
                $tag = str_replace('<script ', '<script type="module" ', $tag);
            }
            return $tag;
        }, 10, 2);

        // Enqueue CSS
        if (file_exists($dist_path . 'css/main.css')) {
            wp_enqueue_style(
                'peanut-connect-react-styles',
                $dist_url . 'css/main.css',
                [],
                PEANUT_CONNECT_VERSION
            );
        }

        // Pass config to JavaScript
        wp_localize_script('peanut-connect-react', 'peanutConnect', [
            'apiUrl' => rest_url('peanut-connect/v1'),
            'nonce' => wp_create_nonce('wp_rest'),
            'version' => PEANUT_CONNECT_VERSION,
            // 'full' for admins; 'builder' for the scoped UTM Builder role.
            'mode' => current_user_can('manage_options') ? 'full' : 'builder',
        ]);
    }

    /**
     * Register REST API routes
     */
    public function register_api_routes(): void {
        $api = new Peanut_Connect_API();
        $api->register_routes();

        Peanut_Connect_Marketing::register_routes();
        Peanut_Connect_Videos::register_routes();
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu(): void {
        // Add top-level menu for React SPA
        add_menu_page(
            __('End-to-End', 'peanut-connect'),
            __('End-to-End', 'peanut-connect'),
            Peanut_Connect_Roles::BUILDER_CAP, // admins have it via runtime filter; UTM Builders via the role
            'peanut-connect-app',
            [$this, 'render_react_app'],
            'dashicons-admin-links',
            80
        );

        // Legacy options-general.php settings page removed in v3.7.24 — it
        // referenced pre-Hub option names (peanut_connect_manager_url /
        // peanut_connect_site_key) and falsely reported "Not connected" on
        // working Hub installs. The React SPA at the top-level menu is the
        // canonical configuration surface now.
    }

    /**
     * Render React SPA
     */
    public function render_react_app(): void {
        // App container within WordPress admin
        echo '<div id="peanut-connect-app" class="wrap peanut-connect-wrap"></div>';

        // Style to fit within WP admin layout
        echo '<style>
            .peanut-connect-wrap {
                margin: 0 !important;
                padding: 0 !important;
                margin-left: -20px !important;
                margin-right: -20px !important;
                margin-top: -10px !important;
                min-height: calc(100vh - 32px);
                background: #f8fafc;
            }
            #wpbody-content {
                padding-bottom: 0 !important;
            }
        </style>';
    }

    /**
     * Register settings
     */
    public function register_settings(): void {
        register_setting('peanut_connect', 'peanut_connect_permissions', [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_permissions'],
            'default' => Peanut_Connect_Auth::DEFAULT_PERMISSIONS,
        ]);

        // Hub Mode setting (v2.6.0+)
        register_setting('peanut_connect', 'peanut_connect_hub_mode', [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitize_hub_mode'],
            'default' => 'standard',
        ]);
    }

    /**
     * Sanitize hub mode setting
     */
    public function sanitize_hub_mode(string $input): string {
        $valid = ['standard', 'hide_suite', 'disable_suite'];
        return in_array($input, $valid, true) ? $input : 'standard';
    }

    /**
     * Sanitize permissions
     */
    public function sanitize_permissions(array $input): array {
        return [
            'health_check' => true, // Always allowed
            'list_updates' => true, // Always allowed
            'perform_updates' => !empty($input['perform_updates']),
            'access_analytics' => !empty($input['access_analytics']),
            'publish_content' => !empty($input['publish_content']),
            'backup_restore' => !empty($input['backup_restore']),
            'api_proxy' => !empty($input['api_proxy']),
        ];
    }

    /**
     * Render settings page
     */
    public function render_settings_page(): void {
        $site_key = Peanut_Connect_Auth::get_site_key();
        $manager_url = get_option('peanut_connect_manager_url');
        $permissions = Peanut_Connect_Auth::get_permissions();
        $last_sync = get_option('peanut_connect_last_sync');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('End-to-End', 'peanut-connect'); ?></h1>

            <div class="card">
                <h2><?php echo esc_html__('Connection Status', 'peanut-connect'); ?></h2>
                <?php if ($site_key && $manager_url): ?>
                    <p class="peanut-connect-status-connected">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <?php echo esc_html__('Connected to:', 'peanut-connect'); ?>
                        <strong><?php echo esc_html($manager_url); ?></strong>
                    </p>
                    <?php if ($last_sync): ?>
                        <p>
                            <?php echo esc_html__('Last sync:', 'peanut-connect'); ?>
                            <?php echo esc_html(human_time_diff(strtotime($last_sync), time())); ?>
                            <?php echo esc_html__('ago', 'peanut-connect'); ?>
                        </p>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="peanut-connect-status-disconnected">
                        <span class="dashicons dashicons-warning"></span>
                        <?php echo esc_html__('Not connected to any manager site.', 'peanut-connect'); ?>
                    </p>
                <?php endif; ?>
            </div>

            <div class="card">
                <h2><?php echo esc_html__('Site Key', 'peanut-connect'); ?></h2>
                <?php if (!$site_key): ?>
                    <p><?php echo esc_html__('Generate a site key to connect this site to your Peanut Monitor dashboard.', 'peanut-connect'); ?></p>
                    <form method="post" action="">
                        <?php wp_nonce_field('peanut_connect_generate_key'); ?>
                        <button type="submit" name="peanut_connect_generate_key" class="button button-primary">
                            <?php echo esc_html__('Generate Site Key', 'peanut-connect'); ?>
                        </button>
                    </form>
                <?php else: ?>
                    <p><?php echo esc_html__('Copy this key and paste it in your Peanut Monitor dashboard to connect this site.', 'peanut-connect'); ?></p>
                    <p>
                        <code class="peanut-connect-key-display">
                            <?php echo esc_html($site_key); ?>
                        </code>
                    </p>
                    <form method="post" action="" class="peanut-connect-form-spaced">
                        <?php wp_nonce_field('peanut_connect_regenerate_key'); ?>
                        <button type="submit" name="peanut_connect_regenerate_key" class="button">
                            <?php echo esc_html__('Regenerate Key', 'peanut-connect'); ?>
                        </button>
                        <button type="submit" name="peanut_connect_disconnect" class="button peanut-connect-btn-disconnect">
                            <?php echo esc_html__('Disconnect', 'peanut-connect'); ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <div class="card">
                <h2><?php echo esc_html__('Permissions', 'peanut-connect'); ?></h2>
                <p><?php echo esc_html__('Control what the manager site can do on this site.', 'peanut-connect'); ?></p>
                <form method="post" action="options.php">
                    <?php settings_fields('peanut_connect'); ?>
                    <table class="form-table">
                        <tr>
                            <th><?php echo esc_html__('Health Checks', 'peanut-connect'); ?></th>
                            <td>
                                <input type="checkbox" checked disabled>
                                <span class="description"><?php echo esc_html__('Always allowed - view site health status', 'peanut-connect'); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <th><?php echo esc_html__('List Updates', 'peanut-connect'); ?></th>
                            <td>
                                <input type="checkbox" checked disabled>
                                <span class="description"><?php echo esc_html__('Always allowed - view available updates', 'peanut-connect'); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <th><?php echo esc_html__('Perform Updates', 'peanut-connect'); ?></th>
                            <td>
                                <input type="checkbox" name="peanut_connect_permissions[perform_updates]" value="1" <?php checked($permissions['perform_updates'] ?? false); ?>>
                                <span class="description"><?php echo esc_html__('Allow manager to install plugin/theme updates', 'peanut-connect'); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <th><?php echo esc_html__('Access Analytics', 'peanut-connect'); ?></th>
                            <td>
                                <input type="checkbox" name="peanut_connect_permissions[access_analytics]" value="1" <?php checked($permissions['access_analytics'] ?? false); ?>>
                                <span class="description"><?php echo esc_html__('Share Peanut Suite analytics data with manager', 'peanut-connect'); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <th><?php echo esc_html__('Publish Content', 'peanut-connect'); ?></th>
                            <td>
                                <input type="checkbox" name="peanut_connect_permissions[publish_content]" value="1" <?php checked($permissions['publish_content'] ?? false); ?>>
                                <span class="description"><?php echo esc_html__('Allow manager to publish and update content (e.g. podcast episodes) on this site', 'peanut-connect'); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <th><?php echo esc_html__('Backup &amp; Restore', 'peanut-connect'); ?></th>
                            <td>
                                <input type="checkbox" name="peanut_connect_permissions[backup_restore]" value="1" <?php checked($permissions['backup_restore'] ?? false); ?>>
                                <span class="description"><?php echo esc_html__('Allow manager to remotely restore a backup (overwrites this site\'s database and files)', 'peanut-connect'); ?></span>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button(__('Save Permissions', 'peanut-connect')); ?>
                </form>
            </div>

            <?php if ($this->has_peanut_suite()): ?>
            <div class="card">
                <h2><?php echo esc_html__('Peanut Suite Integration', 'peanut-connect'); ?></h2>
                <p class="peanut-connect-status-connected">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <?php echo esc_html__('Peanut Suite detected. Analytics data will be synced with your manager site.', 'peanut-connect'); ?>
                </p>
            </div>
            <?php endif; ?>
        </div>
        <?php

        // Handle form submissions
        $this->handle_form_submissions();
    }

    /**
     * Handle form submissions
     */
    private function handle_form_submissions(): void {
        if (isset($_POST['peanut_connect_generate_key']) && wp_verify_nonce($_POST['_wpnonce'], 'peanut_connect_generate_key')) {
            $this->generate_site_key();
            wp_safe_redirect(admin_url('options-general.php?page=peanut-connect&generated=1'));
            exit;
        }

        if (isset($_POST['peanut_connect_regenerate_key']) && wp_verify_nonce($_POST['_wpnonce'], 'peanut_connect_regenerate_key')) {
            $this->generate_site_key();
            wp_safe_redirect(admin_url('options-general.php?page=peanut-connect&regenerated=1'));
            exit;
        }

        if (isset($_POST['peanut_connect_disconnect']) && wp_verify_nonce($_POST['_wpnonce'], 'peanut_connect_regenerate_key')) {
            $this->disconnect();
            wp_safe_redirect(admin_url('options-general.php?page=peanut-connect&disconnected=1'));
            exit;
        }
    }

    /**
     * Generate a new site key
     */
    public function generate_site_key(): string {
        $site_key = wp_generate_password(64, false);
        Peanut_Connect_Auth::set_site_key($site_key);
        return $site_key;
    }

    /**
     * Disconnect from manager
     */
    public function disconnect(): void {
        delete_option('peanut_connect_site_key');
        delete_option('peanut_connect_site_key_undecryptable');
        delete_option('peanut_connect_manager_url');
        delete_option('peanut_connect_last_sync');
    }

    /**
     * Check if Peanut Suite is installed
     */
    public function has_peanut_suite(): bool {
        return function_exists('peanut_is_module_active');
    }

    /**
     * Get Peanut Suite data
     */
    public function get_peanut_suite_data(): ?array {
        if (!$this->has_peanut_suite()) {
            return null;
        }

        return [
            'installed' => true,
            'version' => defined('PEANUT_VERSION') ? PEANUT_VERSION : 'unknown',
            'modules' => function_exists('peanut_get_active_modules') ? peanut_get_active_modules() : [],
        ];
    }

    /**
     * Add settings link to plugins page
     */
    public function add_settings_link(array $links): array {
        $settings_link = '<a href="' . admin_url('admin.php?page=peanut-connect-app') . '">' . __('Settings', 'peanut-connect') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Run ML model retraining
     *
     * Executed weekly via cron job. Trains the ML anomaly detection model
     * with current health metrics.
     *
     * @since 3.7.1
     */
    public function run_ml_training(): void {
        if (!class_exists('Peanut_Connect_ML_Anomaly')) {
            return;
        }

        $ml = new Peanut_Connect_ML_Anomaly();
        if ($ml->is_available()) {
            $ml->train_model();
        }
    }
}

/**
 * Initialize plugin
 *
 * Uses 'init' hook instead of 'plugins_loaded' to ensure translations
 * are properly loaded before any translation functions are called.
 * WordPress 6.7+ enforces strict timing on textdomain loading.
 */
function peanut_connect_init(): Peanut_Connect {
    // Load textdomain first
    load_plugin_textdomain(
        'peanut-connect',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );

    return Peanut_Connect::get_instance();
}
add_action('init', 'peanut_connect_init', 0);

/**
 * One-time cleanup of stale cron schedules from earlier plugin versions.
 *
 * v3.7.24: peanut_connect_sync_to_hub was a legacy schedule registered by
 * an older Peanut_Connect_Hub_Sync::init() entry point. The real cron is
 * peanut_connect_hub_sync (registered in Peanut_Connect::init_hooks()).
 * This runs once per install via an option flag.
 */
add_action('init', function() {
    if (get_option('peanut_connect_stale_crons_cleared_v3_7_17')) {
        return;
    }
    wp_clear_scheduled_hook('peanut_connect_sync_to_hub');
    update_option('peanut_connect_stale_crons_cleared_v3_7_17', 1, false);
}, 5);

/**
 * Activation hook
 */
register_activation_hook(__FILE__, function() {
    // Generate site key on activation if not exists
    if (!get_option('peanut_connect_site_key')) {
        $key = wp_generate_password(64, false);
        Peanut_Connect_Auth::set_site_key($key);
    }

    // Set default permissions from the single canonical source of truth so the
    // seeded set never drifts from has_permission()/get_permissions() again.
    // High-impact capabilities (perform_updates, publish_content, api_proxy)
    // seed to FALSE — the owner opts in deliberately.
    if (!get_option('peanut_connect_permissions')) {
        require_once plugin_dir_path(__FILE__) . 'includes/class-connect-auth.php';
        update_option('peanut_connect_permissions', Peanut_Connect_Auth::DEFAULT_PERMISSIONS);
    }

    // Create Hub tracking database tables (v2.3.0+)
    require_once plugin_dir_path(__FILE__) . 'includes/class-connect-database.php';
    Peanut_Connect_Database::create_tables();

    // Create the scoped "UTM Builder" role.
    require_once plugin_dir_path(__FILE__) . 'includes/class-connect-roles.php';
    Peanut_Connect_Roles::install();
});

/**
 * Deactivation hook
 */
register_deactivation_hook(__FILE__, function() {
    // Optionally notify manager of disconnection

    // Clear Hub sync cron jobs (v2.3.0+)
    wp_clear_scheduled_hook('peanut_connect_hub_sync');
    wp_clear_scheduled_hook('peanut_connect_hub_heartbeat');
    wp_clear_scheduled_hook('peanut_connect_cleanup');

    // Clear legacy sync cron hook (v3.7.24+: superseded by peanut_connect_hub_sync)
    wp_clear_scheduled_hook('peanut_connect_sync_to_hub');

    // Clear ML training cron job (v3.7.1+)
    wp_clear_scheduled_hook('peanut_ml_connect_train');

    // Clear approval notifications digest cron (v3.34.0+)
    if (class_exists('Peanut_Connect_Approvals_Notify')) {
        Peanut_Connect_Approvals_Notify::unschedule();
    }
});
