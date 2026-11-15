<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What NexoFlow keeps current on the site itself:
 *
 *  - the structured data (JSON-LD) printed in the head of each page NexoFlow
 *    has markup for, so search engines can tell who the business is, where it
 *    works and what it offers;
 *  - the llms.txt served at the site's root: a short plain-text file that
 *    tells AI assistants the same.
 *
 * Both are fetched from NexoFlow about once an hour, on the plugin's existing
 * schedule, and kept in the site's options: a page view never waits on
 * NexoFlow, and a fetch that fails leaves what the site already has in place.
 * Disconnecting the site removes both.
 */
class NexoFlow_Site_Data
{
    const OPTION_MARKUP = 'nexoflow_structured_data';

    const OPTION_LLMS = 'nexoflow_llms_txt';

    const OPTION_CHECKED = 'nexoflow_site_data_checked';

    const REFRESH_SECONDS = 3600;

    const MAX_PAGES = 200;

    const MAX_PAGE_BYTES = 60000;

    const MAX_LLMS_BYTES = 65536;

    public function register()
    {
        // Before WordPress decides the address is a missing page.
        add_action('init', array($this, 'maybe_serve_llms_txt'), 0);
        add_action('wp_head', array($this, 'print_structured_data'), 20);
        // After the job pull on the same tick: no schedule of its own.
        add_action(NexoFlow_Cron::HOOK, array($this, 'maybe_refresh'), 20);
        add_action('nexoflow_disconnected', array(__CLASS__, 'forget'));
    }

    // ── Reading what NexoFlow sent ──────────────────────────────────────────

    /**
     * A page address in the one form both sides compare: the site's address
     * as NexoFlow has it, the path without a trailing slash (the root keeps
     * its own), no query, and percent escapes in upper case.
     */
    public static function page_key($origin, $request_uri)
    {
        $origin = is_string($origin) ? untrailingslashit(trim($origin)) : '';
        $request_uri = is_string($request_uri) ? $request_uri : '';

        if ($origin === '' || $request_uri === '' || $request_uri[0] !== '/') {
            return '';
        }

        $path = preg_replace('/[?#].*$/', '', $request_uri);
        $path = strlen($path) > 1 ? rtrim($path, '/') : '/';

        if ($path === '') {
            $path = '/';
        }

        $path = preg_replace_callback(
            '/%[0-9a-fA-F]{2}/',
            function ($escape) {
                return strtoupper($escape[0]);
            },
            $path
        );

        return $origin . $path;
    }

    /**
     * The fetched markup, checked: a version, the site's address, and at
     * most MAX_PAGES pages of JSON-LD. Null when the answer is not that.
     */
    public static function clean_markup($result)
    {
        if (!is_array($result) || empty($result['ok']) || !isset($result['version']) || !is_string($result['version'])) {
            return null;
        }

        $version = self::clean_version($result['version']);

        if ($version === '') {
            return null;
        }

        $origin = isset($result['origin']) && is_string($result['origin']) ? untrailingslashit(trim($result['origin'])) : '';

        if ($origin !== '' && !preg_match('#^https?://[^/\s]+$#i', $origin)) {
            return null;
        }

        $pages = array();

        if (isset($result['pages']) && is_array($result['pages'])) {
            foreach ($result['pages'] as $key => $jsonld) {
                if (count($pages) >= self::MAX_PAGES) {
                    break;
                }

                // Only this site's own pages, and only what reads as JSON-LD.
                if (!is_string($key) || $origin === '' || strpos($key, $origin . '/') !== 0 || strlen($key) > 2000) {
                    continue;
                }

                if (!is_array($jsonld) || !isset($jsonld['@context']) || !is_string($jsonld['@context']) || strpos($jsonld['@context'], 'schema.org') === false) {
                    continue;
                }

                $encoded = self::encode_markup($jsonld);

                if ($encoded === '' || strlen($encoded) > self::MAX_PAGE_BYTES) {
                    continue;
                }

                // Kept as text: it is printed as it is, and a page view decodes nothing.
                $pages[$key] = $encoded;
            }
        }

        return array(
            'version' => $version,
            'origin' => $origin,
            'pages' => $pages,
            'fetched' => time(),
        );
    }

    /**
     * JSON for a script tag. Angle brackets and ampersands are written as
     * escapes, so nothing in the data can close the tag or start another.
     */
    public static function encode_markup($jsonld)
    {
        if (!is_array($jsonld) || $jsonld === array()) {
            return '';
        }

        $encoded = wp_json_encode($jsonld, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? $encoded : '';
    }

    /**
     * The fetched llms.txt, checked: plain text that starts with its title
     * line. An empty string when the answer is not that.
     */
    public static function clean_llms_text($text)
    {
        if (!is_string($text) || $text === '' || strlen($text) > self::MAX_LLMS_BYTES) {
            return '';
        }

        if (function_exists('wp_check_invalid_utf8')) {
            $text = wp_check_invalid_utf8($text);
        }

        $text = str_replace(array("\r\n", "\r"), "\n", $text);
        // No control characters but the line break and the tab, and nothing that can open a tag.
        // The closing bracket stays: it starts the file's summary line ("> ...").
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        $text = is_string($text) ? str_replace('<', '', $text) : '';
        $text = trim($text);

        if ($text === '' || strpos($text, '# ') !== 0) {
            return '';
        }

        return $text . "\n";
    }

    private static function clean_version($version)
    {
        $version = is_string($version) ? trim($version) : '';

        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $version) ? $version : '';
    }

    public static function stored_markup()
    {
        $stored = get_option(self::OPTION_MARKUP, array());

        if (!is_array($stored) || !isset($stored['version'], $stored['pages']) || !is_array($stored['pages'])) {
            return array('version' => '', 'origin' => '', 'pages' => array(), 'fetched' => 0);
        }

        return array(
            'version' => self::clean_version($stored['version']),
            'origin' => isset($stored['origin']) && is_string($stored['origin']) ? $stored['origin'] : '',
            'pages' => $stored['pages'],
            'fetched' => isset($stored['fetched']) ? (int) $stored['fetched'] : 0,
        );
    }

    public static function stored_llms()
    {
        $stored = get_option(self::OPTION_LLMS, array());

        if (!is_array($stored) || !isset($stored['version'])) {
            return array('version' => '', 'text' => '', 'fetched' => 0);
        }

        return array(
            'version' => self::clean_version($stored['version']),
            'text' => isset($stored['text']) && is_string($stored['text']) ? $stored['text'] : '',
            'fetched' => isset($stored['fetched']) ? (int) $stored['fetched'] : 0,
        );
    }

    // ── Keeping it current ──────────────────────────────────────────────────

    public function maybe_refresh()
    {
        return $this->refresh(false);
    }

    /**
     * Ask NexoFlow for both, at most once an hour unless forced. A fetch
     * that fails changes nothing: the site keeps what it has.
     *
     * @return bool Whether NexoFlow was asked.
     */
    public function refresh($force = false, $client = null)
    {
        if (!NexoFlow_Client::has_site_key()) {
            return false;
        }

        $now = time();
        $checked = (int) get_option(self::OPTION_CHECKED, 0);

        if (!$force && $checked > 0 && $now - $checked < self::REFRESH_SECONDS) {
            return false;
        }

        // Written first: a NexoFlow that is slow to answer is not asked again every minute.
        update_option(self::OPTION_CHECKED, $now, false);

        if ($client === null) {
            $client = new NexoFlow_Client();
        }

        $markup = self::stored_markup();
        $result = $client->get_structured_data($markup['version']);

        if (!is_wp_error($result) && is_array($result) && !empty($result['ok']) && empty($result['unchanged'])) {
            $clean = self::clean_markup($result);

            if ($clean !== null) {
                update_option(self::OPTION_MARKUP, $clean, false);
            }
        }

        $llms = self::stored_llms();
        $result = $client->get_llms_txt($llms['version']);

        if (!is_wp_error($result) && is_array($result) && !empty($result['ok']) && empty($result['unchanged'])) {
            $version = isset($result['version']) ? self::clean_version($result['version']) : '';
            $has_text = isset($result['text']) && is_string($result['text']);
            $text = $has_text ? self::clean_llms_text($result['text']) : '';

            // No text: NexoFlow has no file for this site (or it was switched off), so none is served.
            if ($version !== '' && (!$has_text || $text !== '')) {
                update_option(self::OPTION_LLMS, array('version' => $version, 'text' => $text, 'fetched' => $now), false);
            }
        }

        return true;
    }

    /** Disconnected: the site stops printing and serving what NexoFlow sent. */
    public static function forget()
    {
        delete_option(self::OPTION_MARKUP);
        delete_option(self::OPTION_LLMS);
        delete_option(self::OPTION_CHECKED);
    }

    // ── On the site ─────────────────────────────────────────────────────────

    /** The script tag for the page being viewed, or an empty string when NexoFlow has no markup for it. */
    public static function script_tag_for($request_uri)
    {
        $markup = self::stored_markup();

        if ($markup['pages'] === array()) {
            return '';
        }

        $key = self::page_key($markup['origin'], $request_uri);

        if ($key === '' || !isset($markup['pages'][$key]) || !is_string($markup['pages'][$key])) {
            return '';
        }

        // Decoded and encoded again on the way out: only well-formed JSON is ever printed.
        $encoded = self::encode_markup(json_decode($markup['pages'][$key], true));

        if ($encoded === '') {
            return '';
        }

        return '<script type="application/ld+json" class="nexoflow-structured-data">' . $encoded . '</script>' . "\n";
    }

    public function print_structured_data()
    {
        if (!isset($_SERVER['REQUEST_URI']) || !is_string($_SERVER['REQUEST_URI'])) {
            return;
        }

        if ((function_exists('is_404') && is_404()) || (function_exists('is_search') && is_search())) {
            return;
        }

        if (!apply_filters('nexoflow_print_structured_data', true)) {
            return;
        }

        $tag = self::script_tag_for(esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])));

        if ($tag !== '') {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A JSON-LD script tag built by script_tag_for(): the data is JSON-encoded with angle brackets and ampersands written as escapes.
            echo $tag;
        }
    }

    /** Whether the address asked for is the site's own /llms.txt. */
    public static function is_llms_request($request_uri, $home_path)
    {
        if (!is_string($request_uri) || $request_uri === '') {
            return false;
        }

        $path = preg_replace('/[?#].*$/', '', $request_uri);
        $home_path = is_string($home_path) ? rtrim($home_path, '/') : '';

        return $path === $home_path . '/llms.txt';
    }

    public function maybe_serve_llms_txt()
    {
        if (!isset($_SERVER['REQUEST_URI']) || !is_string($_SERVER['REQUEST_URI'])) {
            return;
        }

        $method = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD'])
            ? strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])))
            : 'GET';

        if ($method !== 'GET' && $method !== 'HEAD') {
            return;
        }

        $home_path = wp_parse_url(home_url('/'), PHP_URL_PATH);

        if (!self::is_llms_request(esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])), is_string($home_path) ? $home_path : '')) {
            return;
        }

        $llms = self::stored_llms();

        // Nothing to serve, or the site chose not to: WordPress answers as it would without the plugin.
        if ($llms['text'] === '' || !apply_filters('nexoflow_serve_llms_txt', true)) {
            return;
        }

        status_header(200);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=3600');

        if ($method !== 'HEAD') {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A plain-text file sent as text/plain with nosniff; clean_llms_text() removed control characters and every opening angle bracket when it was stored.
            echo $llms['text'];
        }

        exit;
    }

    // ── For the settings page ───────────────────────────────────────────────

    /** What the site carries now, in numbers: for the status list. */
    public static function summary()
    {
        $markup = self::stored_markup();
        $llms = self::stored_llms();

        return array(
            'pages' => count($markup['pages']),
            'markup_fetched' => $markup['fetched'],
            'llms' => $llms['text'] !== '',
            'llms_fetched' => $llms['fetched'],
        );
    }
}
