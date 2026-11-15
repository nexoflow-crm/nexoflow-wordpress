<?php

if (!defined('ABSPATH')) {
    exit;
}

class NexoFlow_Client
{
    const TIMEOUT = 20;

    const REDIRECTION = 3;

    const KEY_PREFIX = 'nfwp_';

    const KEY_HEX_LENGTH = 48;

    const KEY_LENGTH = 53;

    /**
     * Where the plugin served by NexoFlow's own download (1.0.0 to 1.2.5, the same "nexoflow" folder) kept the key.
     */
    const LEGACY_KEY_OPTION = 'nexoflow_site_connect_key';

    public static function site_key()
    {
        $key = get_option('nexoflow_site_key', '');

        if (!is_string($key) || $key === '') {
            $key = self::adopt_legacy_site_key();
        }

        return is_string($key) ? $key : '';
    }

    /**
     * A site updated from NexoFlow's own download still has its key under the old option. The first read after the
     * update moves a well-formed one across, so the site stays connected with nothing to paste again. The old option
     * is removed once moved, so a later Disconnect is never undone by it.
     */
    public static function adopt_legacy_site_key()
    {
        $legacy = get_option(self::LEGACY_KEY_OPTION, '');
        $legacy = is_string($legacy) ? trim($legacy) : '';

        if (!self::is_valid_site_key($legacy)) {
            return '';
        }

        update_option('nexoflow_site_key', $legacy, false);
        delete_option(self::LEGACY_KEY_OPTION);

        return $legacy;
    }

    public static function request_bearer_token()
    {
        $header = '';

        // The header is unslashed and cleaned like any other input before it is read.
        if (isset($_SERVER['HTTP_AUTHORIZATION']) && is_string($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = sanitize_text_field(wp_unslash($_SERVER['HTTP_AUTHORIZATION']));
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) && is_string($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $header = sanitize_text_field(wp_unslash($_SERVER['REDIRECT_HTTP_AUTHORIZATION']));
        } elseif (function_exists('getallheaders')) {
            $headers = getallheaders();

            if (is_array($headers)) {
                foreach ($headers as $name => $value) {
                    if (is_string($name) && strtolower($name) === 'authorization' && is_string($value)) {
                        $header = sanitize_text_field($value);
                        break;
                    }
                }
            }
        }

        if (!preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $matches)) {
            return '';
        }

        return $matches[1];
    }

    public static function is_valid_site_key($key)
    {
        if (!is_string($key) || $key === '' || strpos($key, ' ') !== false) {
            return false;
        }

        if (strlen($key) !== self::KEY_LENGTH) {
            return false;
        }

        if (strpos($key, self::KEY_PREFIX) !== 0) {
            return false;
        }

        return (bool) preg_match('/^[0-9a-fA-F]{' . self::KEY_HEX_LENGTH . '}$/', substr($key, strlen(self::KEY_PREFIX)));
    }

    public static function site_key_error($key)
    {
        $key = is_string($key) ? trim($key) : '';

        if ($key === '') {
            return __('Add a site connect key before connecting.', 'nexoflow');
        }

        if (strpos($key, 'pk_live_') === 0) {
            return __('That is a Content API key. Paste the site connect key from your WordPress project.', 'nexoflow');
        }

        return __('That is not a valid site connect key.', 'nexoflow');
    }

    public static function has_site_key()
    {
        return self::is_valid_site_key(self::site_key());
    }

    public static function sanitize_api_base($raw)
    {
        $raw = is_string($raw) ? trim($raw) : '';

        if ($raw === '') {
            return NEXOFLOW_DEFAULT_API_BASE;
        }

        $raw = untrailingslashit($raw);

        if (strpos($raw, 'https://') !== 0 || strpos($raw, ' ') !== false) {
            return '';
        }

        return $raw;
    }

    public static function site_key_suffix()
    {
        $key = self::site_key();
        $length = strlen($key);

        if ($length === 0) {
            return '';
        }

        if ($length < 4) {
            return $key;
        }

        return substr($key, -4);
    }

    public static function api_base()
    {
        $base = apply_filters('nexoflow_api_base', NEXOFLOW_DEFAULT_API_BASE);
        $base = self::sanitize_api_base(is_string($base) ? $base : '');

        if ($base === '') {
            return NEXOFLOW_DEFAULT_API_BASE;
        }

        return $base;
    }

    public static function project_from_health($result)
    {
        if (!is_array($result) || empty($result['ok']) || !isset($result['project']) || !is_array($result['project'])) {
            return array();
        }

        return self::sanitize_project($result['project']);
    }

    public static function sanitize_project($project)
    {
        if (!is_array($project)) {
            return array();
        }

        $name = isset($project['name']) ? sanitize_text_field($project['name']) : '';

        if (strlen($name) > 120) {
            $name = substr($name, 0, 120);
        }

        $website = self::safe_public_url(isset($project['websiteUrl']) ? $project['websiteUrl'] : '');
        $wordpress = self::safe_public_url(isset($project['wordpressUrl']) ? $project['wordpressUrl'] : '');

        if ($name === '' && $website === '' && $wordpress === '') {
            return array();
        }

        return array(
            'name' => $name,
            'websiteUrl' => $website,
            'wordpressUrl' => $wordpress,
        );
    }

    public static function stored_project()
    {
        return self::sanitize_project(get_option('nexoflow_project', array()));
    }

    private static function safe_public_url($url)
    {
        if (!is_string($url)) {
            return '';
        }

        $url = trim($url);

        if ($url === '' || strpos($url, 'https://') !== 0 || strpos($url, ' ') !== false) {
            return '';
        }

        $host = wp_parse_url($url, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            return '';
        }

        return untrailingslashit($url);
    }

    public function health()
    {
        return $this->request('GET', '/api/v1/wordpress-plugin/health');
    }

    public function get_jobs()
    {
        $result = $this->request('GET', '/api/v1/wordpress-plugin/jobs');

        if (is_wp_error($result)) {
            return $result;
        }

        $jobs = array();

        if (isset($result['jobs']) && is_array($result['jobs'])) {
            $jobs = $result['jobs'];
        }

        return array('jobs' => $jobs);
    }

    /**
     * The JSON-LD for the site's pages. With the version the site already
     * holds, NexoFlow answers "unchanged" without sending the pages again.
     */
    public function get_structured_data($held_version = '')
    {
        return $this->request('GET', '/api/v1/wordpress-plugin/structured-data' . self::version_query($held_version));
    }

    /** The site's llms.txt, the same way. */
    public function get_llms_txt($held_version = '')
    {
        return $this->request('GET', '/api/v1/wordpress-plugin/llms-txt' . self::version_query($held_version));
    }

    private static function version_query($version)
    {
        $version = is_string($version) ? $version : '';

        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $version) ? '?version=' . rawurlencode($version) : '';
    }

    public function ack($job_id, $body)
    {
        $job_id = is_string($job_id) ? $job_id : '';

        if ($job_id === '') {
            return new WP_Error('nexoflow_ack', __('Missing job identifier.', 'nexoflow'));
        }

        if (!is_array($body)) {
            $body = array('ok' => false, 'error' => __('Invalid job payload.', 'nexoflow'));
        }

        return $this->request(
            'POST',
            '/api/v1/wordpress-plugin/jobs/' . rawurlencode($job_id) . '/ack',
            $body
        );
    }

    private function request($method, $path, $body = null)
    {
        $key = self::site_key();

        if (!self::is_valid_site_key($key)) {
            return new WP_Error('nexoflow_key', self::site_key_error($key));
        }

        $url = self::api_base() . $path;
        $args = array(
            'method' => $method,
            'timeout' => self::TIMEOUT,
            'redirection' => self::REDIRECTION,
            'headers' => array(
                'Authorization' => 'Bearer ' . $key,
                'Accept' => 'application/json',
            ),
            'user-agent' => 'NexoFlow-WordPress/' . NEXOFLOW_VERSION,
        );

        if ($body !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return new WP_Error('nexoflow_http', __('Could not reach NexoFlow.', 'nexoflow'));
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $decoded = json_decode($raw, true);

        if ($code === 401 || $code === 403) {
            return new WP_Error('nexoflow_auth', $this->error_from_body($decoded, __('Invalid site connect key.', 'nexoflow')));
        }

        if ($code === 404) {
            return new WP_Error('nexoflow_http', __('Could not reach NexoFlow.', 'nexoflow'));
        }

        if ($code < 200 || $code >= 300) {
            return new WP_Error('nexoflow_http', $this->error_from_body($decoded, __('Could not reach NexoFlow.', 'nexoflow')));
        }

        if ($raw !== '' && !is_array($decoded)) {
            return new WP_Error('nexoflow_json', __('NexoFlow returned an unexpected response.', 'nexoflow'));
        }

        if (!is_array($decoded)) {
            $decoded = array();
        }

        return $decoded;
    }

    private function error_from_body($decoded, $fallback)
    {
        if (!is_array($decoded)) {
            return $fallback;
        }

        foreach (array('error', 'message') as $field) {
            if (!isset($decoded[$field]) || !is_string($decoded[$field])) {
                continue;
            }

            $text = trim($decoded[$field]);

            if ($text !== '' && strlen($text) <= 300) {
                return $text;
            }
        }

        return $fallback;
    }
}
