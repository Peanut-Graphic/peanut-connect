<?php
/**
 * Peanut Connect API Proxy
 *
 * Allows Hub to route external API requests through this WordPress site.
 * Only whitelisted domains are allowed to prevent abuse.
 *
 * @package Peanut_Connect
 * @since 3.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Peanut_Connect_API_Proxy {

    /**
     * Whitelisted domains that can be proxied.
     */
    private const ALLOWED_DOMAINS = [
        'ph.powerportal.com',
    ];

    /**
     * Maximum allowed timeout in seconds.
     */
    private const MAX_TIMEOUT = 60;

    /**
     * Default timeout in seconds.
     */
    private const DEFAULT_TIMEOUT = 30;

    /**
     * Cap on the proxied response body we will buffer + re-encode. A compromised
     * or hostile allowlisted endpoint returning a multi-MB body would otherwise
     * be JSON-encoded into our response, doubling peak memory.
     */
    private const MAX_RESPONSE_BYTES = 2097152; // 2 MB

    /**
     * Handle an API proxy request from Hub.
     *
     * @param WP_REST_Request $request The incoming request.
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_request(WP_REST_Request $request) {
        $url     = $request->get_param('url');
        $method  = strtoupper($request->get_param('method') ?? 'GET');
        $params  = $request->get_param('params') ?? [];
        $timeout = min((int) ($request->get_param('timeout') ?? self::DEFAULT_TIMEOUT), self::MAX_TIMEOUT);

        // Validate URL
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return new WP_Error(
                'invalid_url',
                __('A valid URL is required.', 'peanut-connect'),
                ['status' => 400]
            );
        }

        // Validate method
        if (!in_array($method, ['GET', 'POST'], true)) {
            return new WP_Error(
                'invalid_method',
                __('Only GET and POST methods are supported.', 'peanut-connect'),
                ['status' => 400]
            );
        }

        // Validate domain against whitelist
        $parsed = wp_parse_url($url);
        $host   = $parsed['host'] ?? '';

        if (!in_array($host, self::ALLOWED_DOMAINS, true)) {
            return new WP_Error(
                'domain_not_allowed',
                sprintf(
                    /* translators: %s: the requested domain */
                    __('Domain "%s" is not in the proxy whitelist.', 'peanut-connect'),
                    $host
                ),
                ['status' => 403]
            );
        }

        // Ensure HTTPS
        if (($parsed['scheme'] ?? '') !== 'https') {
            return new WP_Error(
                'https_required',
                __('Only HTTPS URLs are allowed.', 'peanut-connect'),
                ['status' => 400]
            );
        }

        $start_time = microtime(true);

        // Forward the request. redirection => 0 is the SSRF backstop: the host
        // allowlist is checked against the URL above, but WordPress will follow
        // redirects by default and the redirect target is NOT re-validated — an
        // allowlisted endpoint redirecting to 169.254.169.254 or an RFC1918
        // address would otherwise be fetched. We refuse to chase redirects.
        $args = [
            'timeout'     => $timeout,
            'sslverify'   => true,
            'redirection' => 0,
        ];

        if ($method === 'GET') {
            if (!empty($params)) {
                $url = add_query_arg($params, $url);
            }
            $response = wp_remote_get($url, $args);
        } else {
            $args['body'] = $params;
            $response = wp_remote_post($url, $args);
        }

        $elapsed_ms = round((microtime(true) - $start_time) * 1000);

        if (is_wp_error($response)) {
            return new WP_REST_Response([
                'success'      => false,
                'status_code'  => 0,
                'body'         => $response->get_error_message(),
                'content_type' => null,
                'elapsed_ms'   => $elapsed_ms,
            ], 502);
        }

        $status_code  = wp_remote_retrieve_response_code($response);
        $body         = wp_remote_retrieve_body($response);
        $content_type = wp_remote_retrieve_header($response, 'content-type');

        if (strlen($body) > self::MAX_RESPONSE_BYTES) {
            return new WP_REST_Response([
                'success'      => false,
                'status_code'  => $status_code,
                'body'         => '',
                'content_type' => $content_type,
                'elapsed_ms'   => $elapsed_ms,
                'error'        => 'response_too_large',
            ], 502);
        }

        return new WP_REST_Response([
            'success'      => $status_code >= 200 && $status_code < 400,
            'status_code'  => $status_code,
            'body'         => $body,
            'content_type' => $content_type,
            'elapsed_ms'   => $elapsed_ms,
        ], 200);
    }
}
