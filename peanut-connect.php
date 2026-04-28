<?php
/**
 * Plugin Name: Peanut Connect
 * Plugin URI: https://peanutgraphic.com/peanut-connect
 * Description: Connector plugin for Peanut Hub. Enables centralized site health monitoring and management from your agency dashboard.
 * Version: 3.4.2
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

define('PEANUT_CONNECT_VERSION', '3.4.2');
define('PEANUT_CONNECT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PEANUT_CONNECT_API_NAMESPACE', 'peanut-connect/v1');

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
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-auth.php';
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-health.php';
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-updates.php';
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-error-log.php';
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-api.php';
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-self-updater.php';

        // Hub tracking and sync classes (v2.3.0+)
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-database.php';
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-tracker.php';
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-hub-sync.php';
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-popup-display.php';

        // Security hardening (v2.5.0+)
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-security.php';

        // API proxy for Hub (v3.3.0+)
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-api-proxy.php';

        // Event banner for Hub (v3.3.0+)
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-event-banner.php';

        // Forms integration for Hub (v3.3.0+)
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-forms.php';

        // ML anomaly detection (v3.5.0+)
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-ml-anomaly.php';

        // Marketing proxy (campaign builder, UTMs, links, analytics) for Hub (v3.5.0+)
        require_once PEANUT_CONNECT_PLUGIN_DIR . 'includes/class-connect-marketing.php';

        // Initialize logging early
        Peanut_Connect_Activity_Log::init();
        Peanut_Connect_Error_Log::init();

        // Initialize security features
        Peanut_Connect_Security::init();

        // Initialize event banner
        Peanut_Connect_Event_Banner::init();

        // Initialize forms integration
        Peanut_Connect_Forms::init();

        // Initialize self-updater early so update check filter is registered
        new Peanut_Connect_Self_Updater();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks(): void {
        add_action('rest_api_init', [$this, 'register_api_routes']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('admin_head', [$this, 'hide_admin_notices_on_react_page']);

        // Add settings link to plugins page
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_settings_link']);

        // Initialize Hub tracking and sync (v2.3.0+)
        if ($this->is_hub_connected()) {
            // Initialize frontend tracking
            Peanut_Connect_Tracker::init();

            // Initialize popup display
            Peanut_Connect_Popup_Display::init();

            // Schedule sync cron
            add_action('peanut_connect_hub_sync', [Peanut_Connect_Hub_Sync::class, 'run_sync']);
            add_action('peanut_connect_hub_heartbeat', [Peanut_Connect_Hub_Sync::class, 'send_heartbeat']);

            if (!wp_next_scheduled('peanut_connect_hub_sync')) {
                wp_schedule_event(time(), 'fifteen_minutes', 'peanut_connect_hub_sync');
            }
            if (!wp_next_scheduled('peanut_connect_hub_heartbeat')) {
                wp_schedule_event(time(), 'hourly', 'peanut_connect_hub_heartbeat');
            }
        }

        // Schedule daily cleanup of old synced records (v2.6.3+)
        add_action('peanut_connect_cleanup', [Peanut_Connect_Database::class, 'cleanup_old_records']);
        if (!wp_next_scheduled('peanut_connect_cleanup')) {
            wp_schedule_event(time(), 'daily', 'peanut_connect_cleanup');
        }

        // Schedule weekly ML model retraining (v3.5.0+)
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
     * Prevent Suite from loading when in disable mode
     */
    public function disable_suite_loading(): void {
        // Remove Suite's main initialization hooks
        // Suite should check the 'peanut_suite_disabled' filter before initializing
        if (class_exists('Peanut_Suite')) {
            remove_all_actions('plugins_loaded', 10);
        }
    }

    /**
     * Check if hub is connected
     */
    public function is_hub_connected(): bool {
        $hub_url = get_option('peanut_connect_hub_url');
        $api_key = get_option('peanut_connect_hub_api_key');
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
        ]);
    }

    /**
     * Register REST API routes
     */
    public function register_api_routes(): void {
        $api = new Peanut_Connect_API();
        $api->register_routes();

        Peanut_Connect_Marketing::register_routes();
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu(): void {
        // Add top-level menu for React SPA
        add_menu_page(
            __('Peanut Connect', 'peanut-connect'),
            __('Connect', 'peanut-connect'),
            'manage_options',
            'peanut-connect-app',
            [$this, 'render_react_app'],
            'dashicons-admin-links',
            80
        );

        // Keep legacy settings page as a fallback
        add_options_page(
            __('Peanut Connect (Legacy)', 'peanut-connect'),
            __('Peanut Connect', 'peanut-connect'),
            'manage_options',
            'peanut-connect',
            [$this, 'render_settings_page']
        );
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
            'default' => [
                'health_check' => true,
                'list_updates' => true,
                'perform_updates' => true,
                'access_analytics' => true,
            ],
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
            'api_proxy' => !empty($input['api_proxy']),
        ];
    }

    /**
     * Render settings page
     */
    public function render_settings_page(): void {
        $site_key = get_option('peanut_connect_site_key');
        $manager_url = get_option('peanut_connect_manager_url');
        $permissions = get_option('peanut_connect_permissions', [
            'health_check' => true,
            'list_updates' => true,
            'perform_updates' => true,
            'access_analytics' => true,
        ]);
        $last_sync = get_option('peanut_connect_last_sync');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Peanut Connect', 'peanut-connect'); ?></h1>

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
        update_option('peanut_connect_site_key', $site_key);
        return $site_key;
    }

    /**
     * Disconnect from manager
     */
    public function disconnect(): void {
        delete_option('peanut_connect_site_key');
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
     * @since 3.5.0
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
 * Activation hook
 */
register_activation_hook(__FILE__, function() {
    // Generate site key on activation if not exists
    if (!get_option('peanut_connect_site_key')) {
        $key = wp_generate_password(64, false);
        update_option('peanut_connect_site_key', $key);
    }

    // Set default permissions
    if (!get_option('peanut_connect_permissions')) {
        update_option('peanut_connect_permissions', [
            'health_check' => true,
            'list_updates' => true,
            'perform_updates' => true,
            'access_analytics' => true,
        ]);
    }

    // Create Hub tracking database tables (v2.3.0+)
    require_once plugin_dir_path(__FILE__) . 'includes/class-connect-database.php';
    Peanut_Connect_Database::create_tables();
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

    // Clear ML training cron job (v3.5.0+)
    wp_clear_scheduled_hook('peanut_ml_connect_train');
});
