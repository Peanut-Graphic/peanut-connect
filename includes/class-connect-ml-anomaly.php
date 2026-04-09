<?php
/**
 * Peanut Connect ML Anomaly Detection
 *
 * Integrates with the Peanut ML microservice for anomaly detection on health metrics.
 *
 * @since 3.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Peanut_Connect_ML_Anomaly
 *
 * Handles anomaly detection requests to the ML microservice.
 * Provides non-blocking integration with health data collection.
 */
class Peanut_Connect_ML_Anomaly {

    /**
     * ML service URL
     *
     * @var string
     */
    private string $service_url = '';

    /**
     * ML service API key
     *
     * @var string
     */
    private string $api_key = '';

    /**
     * Request timeout in seconds
     *
     * @var int
     */
    private const REQUEST_TIMEOUT = 30;

    /**
     * Anomaly result cache TTL in seconds (10 minutes)
     *
     * @var int
     */
    private const ANOMALY_CACHE_TTL = 600;

    /**
     * ML service availability cache TTL in seconds (2 minutes)
     *
     * @var int
     */
    private const AVAILABILITY_CACHE_TTL = 120;

    /**
     * Constructor
     *
     * Reads ML service configuration from shared peanut_ml_settings option.
     */
    public function __construct() {
        $settings = get_option('peanut_ml_settings', []);

        $this->service_url = isset($settings['ml_service_url'])
            ? rtrim($settings['ml_service_url'], '/')
            : 'http://127.0.0.1:8100';

        $this->api_key = isset($settings['ml_api_key'])
            ? $settings['ml_api_key']
            : '';
    }

    /**
     * Check if ML service is available
     *
     * Performs a health check with 2-minute transient caching.
     *
     * @return bool True if service is reachable and configured
     */
    public function is_available(): bool {
        if (empty($this->api_key)) {
            return false;
        }

        $cache_key = 'peanut_ml_connect_available';
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return (bool) $cached;
        }

        $response = $this->make_request('GET', '/health', []);
        $available = !is_null($response);

        // Cache the result
        set_transient($cache_key, $available ? 1 : 0, self::AVAILABILITY_CACHE_TTL);

        return $available;
    }

    /**
     * Detect anomalies in health data
     *
     * Sends health metrics to the ML microservice for anomaly detection.
     * Results are cached for 10 minutes.
     *
     * @param array $health_data Health data from Peanut_Connect_Health
     *
     * @return ?array Anomaly detection results with scores and flagged metrics, or null on failure
     */
    public function detect_anomalies(array $health_data): ?array {
        if (!$this->is_available()) {
            return null;
        }

        $cache_key = 'peanut_ml_anomaly_results';
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        $response = $this->make_request('POST', '/connect/detect-anomalies', [
            'site_id' => get_current_blog_id(),
            'lookback_days' => 7,
        ]);

        if (!is_null($response)) {
            // Cache the results
            set_transient($cache_key, $response, self::ANOMALY_CACHE_TTL);
        }

        return $response;
    }

    /**
     * Train the ML model with current health data
     *
     * Sends a training request to update the model with recent observations.
     *
     * @return bool True if training request succeeded
     */
    public function train_model(): bool {
        if (!$this->is_available()) {
            return false;
        }

        $response = $this->make_request('POST', '/connect/train-baseline', [
            'site_id' => get_current_blog_id(),
            'training_days' => 30,
        ]);

        return !is_null($response);
    }

    /**
     * Make HTTP request to ML service
     *
     * Handles both GET and POST requests with proper authentication headers.
     * Logs errors but does not block execution on failure.
     *
     * @param string $method   HTTP method (GET or POST)
     * @param string $endpoint API endpoint path (e.g., '/connect/detect')
     * @param array  $data     Request body data (for POST)
     *
     * @return ?array Decoded response body, or null on failure
     */
    private function make_request(string $method, string $endpoint, array $data = []): ?array {
        $url = $this->service_url . $endpoint;

        $args = [
            'method' => $method,
            'timeout' => self::REQUEST_TIMEOUT,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-ML-API-Key' => $this->api_key,
            ],
            'sslverify' => true,
        ];

        if ($method === 'POST' && !empty($data)) {
            $args['body'] = wp_json_encode($data);
        }

        $response = wp_remote_request($url, $args);

        // Check for errors
        if (is_wp_error($response)) {
            error_log(
                sprintf(
                    '[Peanut Connect ML] Request failed: %s %s - %s',
                    $method,
                    $endpoint,
                    $response->get_error_message()
                )
            );
            return null;
        }

        $status_code = wp_remote_retrieve_response_code($response);

        // Check HTTP status
        if ($status_code < 200 || $status_code >= 300) {
            error_log(
                sprintf(
                    '[Peanut Connect ML] HTTP %d: %s %s',
                    $status_code,
                    $method,
                    $endpoint
                )
            );
            return null;
        }

        $body = wp_remote_retrieve_body($response);

        if (empty($body)) {
            return null;
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            error_log(
                sprintf(
                    '[Peanut Connect ML] Invalid JSON response from %s %s',
                    $method,
                    $endpoint
                )
            );
            return null;
        }

        return $decoded;
    }
}
