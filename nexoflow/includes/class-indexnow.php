<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The key file for change notices to search engines (IndexNow, which Bing
 * reads). When NexoFlow publishes or changes a page on this site it tells
 * search engines, so they read the page again soon; they confirm a notice
 * comes from this site by reading /<key>.txt at its root, which must hold the
 * key and nothing else.
 *
 * NexoFlow sends the key with the site's jobs, on the plugin's existing
 * schedule. The plugin keeps it in an option and answers /<key>.txt with it
 * before WordPress looks for a page there. Nothing else answers that address:
 * the name is the site's own key. Disconnecting the site removes it.
 */
class NexoFlow_IndexNow
{
    const OPTION_KEY = 'nexoflow_indexnow_key';

    public function register()
    {
        // Before WordPress decides the address is a missing page.
        add_action('init', array($this, 'maybe_serve_key'), 0);
        add_action('nexoflow_disconnected', array(__CLASS__, 'forget'));
    }

    /** A key search engines take: 8 to 128 letters, digits and dashes. */
    public static function is_key($value)
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9-]{8,128}$/', $value) === 1;
    }

    /** The key NexoFlow gave this site, or empty. */
    public static function stored_key()
    {
        $key = get_option(self::OPTION_KEY, '');

        return self::is_key($key) ? $key : '';
    }

    /** Keep the key NexoFlow sends with the site's jobs. A missing or malformed one changes nothing. */
    public static function keep_key($response)
    {
        if (!is_array($response) || !isset($response['indexNowKey']) || !self::is_key($response['indexNowKey'])) {
            return;
        }

        if ($response['indexNowKey'] !== self::stored_key()) {
            update_option(self::OPTION_KEY, $response['indexNowKey'], false);
        }
    }

    public static function forget()
    {
        delete_option(self::OPTION_KEY);
    }

    /** Whether the address asked for is this site's key file: /<key>.txt at its root. */
    public static function is_key_request($request_uri, $home_path, $key)
    {
        if (!is_string($request_uri) || $request_uri === '' || !self::is_key($key)) {
            return false;
        }

        $path = preg_replace('/[?#].*$/', '', $request_uri);
        $home_path = is_string($home_path) ? rtrim($home_path, '/') : '';

        return $path === $home_path . '/' . $key . '.txt';
    }

    public function maybe_serve_key()
    {
        $key = self::stored_key();

        if ($key === '' || !isset($_SERVER['REQUEST_URI']) || !is_string($_SERVER['REQUEST_URI'])) {
            return;
        }

        $method = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD'])
            ? strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])))
            : 'GET';

        if ($method !== 'GET' && $method !== 'HEAD') {
            return;
        }

        $home_path = wp_parse_url(home_url('/'), PHP_URL_PATH);

        if (!self::is_key_request(esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])), is_string($home_path) ? $home_path : '', $key)) {
            return;
        }

        status_header(200);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=3600');

        if ($method !== 'HEAD') {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Letters, digits and dashes only: stored_key() checked it.
            echo $key;
        }

        exit;
    }
}
