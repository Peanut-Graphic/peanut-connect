<?php
/**
 * Peanut Connect Forms
 *
 * Handles Hub forms sync, shortcode rendering, and FormFlow integration.
 *
 * @package Peanut_Connect
 * @since 3.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Forms class for Hub integration and FormFlow bridge
 */
class Peanut_Connect_Forms {

    /**
     * Initialize forms functionality
     */
    public static function init(): void {
        // Register shortcode
        add_shortcode('peanut_form', [__CLASS__, 'shortcode_handler']);

        // Hook into FormFlow submissions if available
        add_action('isf_submission_completed', [__CLASS__, 'handle_formflow_submission'], 10, 2);
        add_action('formflow_submission_completed', [__CLASS__, 'handle_formflow_submission'], 10, 2);

        // Register REST endpoints
        add_action('rest_api_init', [__CLASS__, 'register_endpoints']);
    }

    /**
     * Check if FormFlow plugin is active
     */
    public static function is_formflow_active(): bool {
        return class_exists('ISF\\Plugin') || class_exists('FormFlow') || class_exists('FormFlow_Lite');
    }

    /**
     * Register REST API endpoints
     */
    public static function register_endpoints(): void {
        register_rest_route('peanut-connect/v1', '/forms/sync', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [__CLASS__, 'handle_sync_request'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ]);

        // Public form submission. The browser posts here (same-origin, nonce +
        // rate-limited) and the edge forwards server-side to Hub WITH the site
        // key — so the Hub credential never reaches the page or the visitor.
        // (Previously the key was localized into every page carrying a form,
        // exposing it to anyone who viewed source.)
        register_rest_route('peanut-connect/v1', '/forms/submit', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [__CLASS__, 'handle_public_submit'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Public form submission proxy: validate the same-origin nonce, rate-limit,
     * then forward the submission to Hub server-side with the site key. The key
     * is read from options here and never leaves the server.
     */
    public static function handle_public_submit(WP_REST_Request $request): WP_REST_Response {
        // Same-origin nonce (best-effort CSRF guard for an anonymous form).
        $nonce = $request->get_header('X-WP-Nonce');
        if (empty($nonce) || !wp_verify_nonce($nonce, 'wp_rest')) {
            return new WP_REST_Response(['success' => false, 'message' => __('Invalid or missing security token.', 'peanut-connect')], 403);
        }

        // Rate-limit abusive submission floods (public endpoint).
        if (class_exists('Peanut_Connect_Rate_Limiter')) {
            $client_id = Peanut_Connect_Rate_Limiter::get_client_identifier($request);
            $limited = Peanut_Connect_Rate_Limiter::check($client_id, 'default');
            if (is_wp_error($limited)) {
                return new WP_REST_Response(['success' => false, 'message' => $limited->get_error_message()], 429);
            }
        }

        $hub_url = get_option('peanut_connect_hub_url');
        $api_key = Peanut_Connect_Auth::get_hub_api_key();
        if (empty($hub_url) || empty($api_key)) {
            return new WP_REST_Response(['success' => false, 'message' => __('Form submission is not available right now.', 'peanut-connect')], 503);
        }

        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            $payload = $request->get_params();
        }

        $response = wp_remote_post(trailingslashit($hub_url) . 'api/v1/forms/submit', [
            'headers' => [
                'X-Site-Api-Key' => $api_key,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($payload),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return new WP_REST_Response(['success' => false, 'message' => __('Could not submit the form. Please try again.', 'peanut-connect')], 502);
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        return new WP_REST_Response(
            is_array($body) ? $body : ['success' => $code >= 200 && $code < 300],
            ($code >= 200 && $code < 600) ? $code : 502
        );
    }

    /**
     * Handle manual form sync request
     */
    public static function handle_sync_request(WP_REST_Request $request): WP_REST_Response {
        $result = self::sync_from_hub();

        if ($result['success']) {
            return new WP_REST_Response([
                'success' => true,
                'message' => sprintf(
                    /* translators: %d: number of forms synced */
                    _n('Synced %d form from Hub', 'Synced %d forms from Hub', $result['count'], 'peanut-connect'),
                    $result['count']
                ),
                'count' => $result['count'],
            ], 200);
        }

        return new WP_REST_Response([
            'success' => false,
            'message' => $result['error'] ?? __('Unknown error', 'peanut-connect'),
        ], 500);
    }

    /**
     * Sync forms from Hub
     */
    public static function sync_from_hub(): array {
        $hub_url = get_option('peanut_connect_hub_url');
        $api_key = Peanut_Connect_Auth::get_hub_api_key();

        if (empty($hub_url) || empty($api_key)) {
            return ['success' => false, 'error' => __('Hub not configured', 'peanut-connect')];
        }

        $response = wp_remote_get(trailingslashit($hub_url) . 'api/v1/forms/active', [
            'headers' => [
                'X-Site-Api-Key' => $api_key,
                'Accept' => 'application/json',
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'error' => $response->get_error_message()];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!isset($body['success']) || !$body['success']) {
            return ['success' => false, 'error' => $body['message'] ?? 'Invalid response'];
        }

        if (!isset($body['forms']) || !is_array($body['forms'])) {
            return ['success' => false, 'error' => 'No forms in response'];
        }

        // Update local cache
        self::update_forms_cache($body['forms']);

        return ['success' => true, 'count' => count($body['forms'])];
    }

    /**
     * Update local forms cache
     */
    protected static function update_forms_cache(array $forms): void {
        global $wpdb;
        $table = Peanut_Connect_Database::table('hub_forms');

        // Mark all forms as stale (to detect removed forms)
        $wpdb->query("UPDATE $table SET status = 'stale'"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        foreach ($forms as $form) {
            $existing = $wpdb->get_row(
                $wpdb->prepare("SELECT id FROM $table WHERE hub_form_id = %d", $form['id']) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            );

            $data = [
                'hub_form_id' => $form['id'],
                'slug' => $form['slug'],
                'name' => $form['name'],
                'form_type' => $form['form_type'] ?? 'contact',
                'fields' => wp_json_encode($form['fields']),
                'steps' => !empty($form['steps']) ? wp_json_encode($form['steps']) : null,
                'settings' => !empty($form['settings']) ? wp_json_encode($form['settings']) : null,
                'status' => 'active',
                'version' => $form['version'] ?? 1,
                'synced_at' => current_time('mysql', true),
            ];

            if ($existing) {
                $wpdb->update($table, $data, ['id' => $existing->id]);
            } else {
                $wpdb->insert($table, $data);
            }
        }

        // Remove stale forms
        $wpdb->delete($table, ['status' => 'stale']);
    }

    /**
     * Get form by slug (checks Hub forms first, then FormFlow)
     */
    public static function get_form(string $slug): ?array {
        global $wpdb;

        // Check Hub forms first
        $table = Peanut_Connect_Database::table('hub_forms');
        $form = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table WHERE slug = %s AND status = 'active'", $slug), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            ARRAY_A
        );

        if ($form) {
            $form['fields'] = json_decode($form['fields'], true);
            $form['steps'] = !empty($form['steps']) ? json_decode($form['steps'], true) : null;
            $form['settings'] = !empty($form['settings']) ? json_decode($form['settings'], true) : null;
            $form['source'] = 'hub';
            return $form;
        }

        // Fall back to FormFlow if active
        if (self::is_formflow_active()) {
            return self::get_formflow_form($slug);
        }

        return null;
    }

    /**
     * Get FormFlow form by slug
     */
    protected static function get_formflow_form(string $slug): ?array {
        // Check for FormFlow Lite
        if (class_exists('FormFlow_Lite')) {
            global $wpdb;
            $table = $wpdb->prefix . 'fffl_instances';
            $instance = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM $table WHERE slug = %s AND status = 'active'", $slug), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                ARRAY_A
            );

            if ($instance) {
                return [
                    'source' => 'formflow',
                    'id' => $instance['id'],
                    'slug' => $instance['slug'],
                    'name' => $instance['name'],
                    'fields' => json_decode($instance['form_config'] ?? '[]', true),
                    'settings' => json_decode($instance['settings'] ?? '{}', true),
                ];
            }
        }

        // Check for FormFlow Pro
        if (class_exists('ISF\\Database\\Database')) {
            try {
                $db = \ISF\Database\Database::get_instance();
                $instances = $db->get_instances(['status' => 'active']);

                foreach ($instances as $instance) {
                    if ($instance['slug'] === $slug) {
                        return [
                            'source' => 'formflow',
                            'id' => $instance['id'],
                            'slug' => $instance['slug'],
                            'name' => $instance['name'],
                            'fields' => json_decode($instance['form_config'] ?? '[]', true),
                            'settings' => json_decode($instance['settings'] ?? '{}', true),
                        ];
                    }
                }
            } catch (Exception $e) {
                // FormFlow Pro not available
            }
        }

        return null;
    }

    /**
     * Render form shortcode
     */
    public static function shortcode_handler($atts): string {
        $atts = shortcode_atts([
            'slug' => '',
            'id' => '',
            'theme' => 'default',
        ], $atts);

        $slug = $atts['slug'] ?: $atts['id'];
        if (empty($slug)) {
            return '<!-- Peanut Form: No slug specified -->';
        }

        $form = self::get_form($slug);
        if (!$form) {
            return '<!-- Peanut Form: Form not found -->';
        }

        return self::render_form($form, $atts);
    }

    /**
     * Render form HTML
     */
    protected static function render_form(array $form, array $options = []): string {
        // If it's a Hub form, render via Hub's form script
        if ($form['source'] === 'hub') {
            return self::render_hub_form($form, $options);
        }

        // If it's a FormFlow form, use FormFlow's shortcode.
        // form['id'] is validated as numeric before passing to do_shortcode;
        // esc_attr() would entity-encode quotes and break shortcode parsing.
        if ($form['source'] === 'formflow') {
            $form_id = absint($form['id'] ?? 0);
            if ($form_id <= 0) {
                return '<!-- Peanut Form: invalid FormFlow id -->';
            }
            return do_shortcode('[formflow id="' . $form_id . '"]');
        }

        return '<!-- Peanut Form: Unknown form source -->';
    }

    /**
     * Render Hub form
     */
    protected static function render_hub_form(array $form, array $options = []): string {
        $form_id = 'peanut-form-' . esc_attr($form['slug']);
        $visitor_id = Peanut_Connect_Tracker::get_visitor_id();
        $session_id = wp_generate_uuid4();

        // Enqueue form assets
        self::enqueue_form_assets();

        $settings = $form['settings'] ?? [];
        $styling = $settings['styling'] ?? [];

        $style = '';
        if (!empty($styling['primary_color'])) {
            $style .= '--peanut-form-primary: ' . esc_attr($styling['primary_color']) . ';';
        }

        ob_start();
        ?>
        <div id="<?php echo esc_attr($form_id); ?>"
             class="peanut-form-container peanut-form-theme-<?php echo esc_attr($options['theme'] ?? 'default'); ?>"
             data-form-slug="<?php echo esc_attr($form['slug']); ?>"
             data-visitor-id="<?php echo esc_attr($visitor_id); ?>"
             data-session-id="<?php echo esc_attr($session_id); ?>"
             style="<?php echo esc_attr($style); ?>">
            <noscript>
                <p><?php esc_html_e('Please enable JavaScript to use this form.', 'peanut-connect'); ?></p>
            </noscript>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Enqueue form assets
     */
    protected static function enqueue_form_assets(): void {
        $hub_url = get_option('peanut_connect_hub_url');

        wp_enqueue_script(
            'peanut-forms',
            trailingslashit($hub_url) . 'js/peanut-forms.min.js',
            [],
            PEANUT_CONNECT_VERSION,
            true
        );

        wp_enqueue_style(
            'peanut-forms',
            trailingslashit($hub_url) . 'css/peanut-forms.min.css',
            [],
            PEANUT_CONNECT_VERSION
        );

        // NOTE: the Hub site key is deliberately NOT exposed here. The form
        // submits to the local edge endpoint (submitUrl) which forwards to Hub
        // with the key server-side. Emitting the key (or the Hub URL) into the
        // page leaked the credential to every visitor and breached Hub-blind.
        wp_localize_script('peanut-forms', 'PeanutFormsConfig', [
            'submitUrl' => rest_url('peanut-connect/v1/forms/submit'),
            'nonce' => wp_create_nonce('wp_rest'),
            'i18n' => [
                'submitting' => __('Submitting...', 'peanut-connect'),
                'error' => __('Something went wrong. Please try again.', 'peanut-connect'),
                'required' => __('This field is required', 'peanut-connect'),
                'invalidEmail' => __('Please enter a valid email address', 'peanut-connect'),
                'invalidPhone' => __('Please enter a valid phone number', 'peanut-connect'),
            ],
        ]);
    }

    /**
     * Handle FormFlow submission - sync to Hub
     */
    public static function handle_formflow_submission($submission_id, $instance_id): void {
        // Get submission data from FormFlow
        $submission_data = self::get_formflow_submission_data($submission_id, $instance_id);
        if (!$submission_data) {
            return;
        }

        // Record in Connect's unified submissions table
        self::record_submission([
            'source' => 'formflow',
            'formflow_instance_id' => $instance_id,
            'visitor_id' => Peanut_Connect_Tracker::get_visitor_id(),
            'form_name' => $submission_data['form_name'],
            'data' => $submission_data['data'],
            'metadata' => [
                'ip' => $submission_data['ip'] ?? null,
                'user_agent' => $submission_data['user_agent'] ?? null,
                'formflow_submission_id' => $submission_id,
            ],
        ]);
    }

    /**
     * Get FormFlow submission data
     */
    protected static function get_formflow_submission_data($submission_id, $instance_id): ?array {
        // Try FormFlow Pro
        if (class_exists('ISF\\Database\\Database')) {
            try {
                $db = \ISF\Database\Database::get_instance();
                $submission = $db->get_submission($submission_id);
                $instance = $db->get_instance($instance_id);

                if ($submission && $instance) {
                    return [
                        'form_name' => $instance['name'] ?? 'FormFlow Form',
                        'data' => json_decode($submission['form_data'] ?? '{}', true),
                        'ip' => $submission['ip_address'] ?? null,
                        'user_agent' => $submission['user_agent'] ?? null,
                    ];
                }
            } catch (Exception $e) {
                // FormFlow Pro error
            }
        }

        // Try FormFlow Lite
        if (class_exists('FormFlow_Lite')) {
            global $wpdb;
            $submissions_table = $wpdb->prefix . 'fffl_submissions';
            $instances_table = $wpdb->prefix . 'fffl_instances';

            $submission = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM $submissions_table WHERE id = %d", $submission_id), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                ARRAY_A
            );

            $instance = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM $instances_table WHERE id = %d", $instance_id), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                ARRAY_A
            );

            if ($submission && $instance) {
                return [
                    'form_name' => $instance['name'] ?? 'FormFlow Lite Form',
                    'data' => json_decode($submission['form_data'] ?? '{}', true),
                    'ip' => $submission['ip_address'] ?? null,
                    'user_agent' => $submission['user_agent'] ?? null,
                ];
            }
        }

        return null;
    }

    /**
     * Record submission in local database
     */
    public static function record_submission(array $params): string {
        global $wpdb;

        $submission_uuid = wp_generate_uuid4();
        $table = Peanut_Connect_Database::table('form_submissions');

        $wpdb->insert($table, [
            'source' => $params['source'] ?? 'hub',
            'form_id' => $params['form_id'] ?? null,
            'hub_form_id' => $params['hub_form_id'] ?? null,
            'formflow_instance_id' => $params['formflow_instance_id'] ?? null,
            'visitor_id' => $params['visitor_id'] ?? null,
            'submission_uuid' => $submission_uuid,
            'form_name' => $params['form_name'] ?? null,
            'data' => wp_json_encode($params['data']),
            'metadata' => wp_json_encode($params['metadata'] ?? []),
            'status' => 'submitted',
            'submitted_at' => current_time('mysql', true),
            'synced' => 0,
        ]);

        return $submission_uuid;
    }

    /**
     * Get unsynced form submissions
     */
    public static function get_unsynced_submissions(int $limit = 100): array {
        global $wpdb;
        $table = Peanut_Connect_Database::table('form_submissions');

        $submissions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE synced = 0 ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $limit
            ),
            ARRAY_A
        );

        // Parse JSON fields
        foreach ($submissions as &$sub) {
            $sub['data'] = json_decode($sub['data'], true);
            $sub['metadata'] = json_decode($sub['metadata'], true);
        }

        return $submissions;
    }

    /**
     * Mark submissions as synced
     */
    public static function mark_submissions_synced(array $ids): void {
        global $wpdb;
        $table = Peanut_Connect_Database::table('form_submissions');

        if (empty($ids)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE $table SET synced = 1, synced_at = %s WHERE id IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                array_merge([current_time('mysql', true)], $ids)
            )
        );
    }
}
