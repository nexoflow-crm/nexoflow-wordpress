<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A plain page cache for visitors who are not signed in, off until it is asked
 * for. It stands aside for any other caching plugin. Copies live for an hour at
 * most, so a form's nonce on a cached page is still valid, and any change to the
 * site makes every copy old.
 *
 * A copy is only ever made from a plain request for the page's own address, so
 * no visitor can decide what other visitors are shown.
 */
class NexoFlow_Page_Cache
{
    const OPTION = 'nexoflow_page_cache';

    const GENERATION = 'nexoflow_page_cache_generation';

    /** How many copies the current generation holds: "generation:count". */
    const COUNT = 'nexoflow_page_cache_count';

    const PURGE_HOOK = 'nexoflow_page_cache_purge';

    const TTL = 3600;

    const MAX_BYTES = 1572864;

    const MIN_BYTES = 512;

    /** At most this many copies at a time; past it pages are drawn as usual until the next change. */
    const MAX_ENTRIES = 1000;

    const PREFIX = 'nf_pc_';

    /** A cookie that means the visitor may see something of their own. */
    const PRIVATE_COOKIES = array(
        'wordpress_logged_in_',
        'wp-postpass_',
        'comment_author_',
        'woocommerce_items_in_cart',
        'woocommerce_cart_hash',
        'wp_woocommerce_session_',
        'woocommerce_recently_viewed',
        'edd_items_in_cart',
        'PHPSESSID',
    );

    /** Query parameters that only say where a visitor came from. Such a request is served a copy, never makes one. */
    const CAMPAIGN_PARAMETERS = array('gclid', 'fbclid', 'msclkid', 'dclid', 'ttclid', 'mc_cid', 'mc_eid', '_ga', 'ref');

    /** Headers a page sets that a copy carries too. */
    const KEPT_HEADERS = array(
        'content-security-policy',
        'content-security-policy-report-only',
        'x-frame-options',
        'x-content-type-options',
        'referrer-policy',
        'permissions-policy',
        'strict-transport-security',
        'cross-origin-opener-policy',
        'cross-origin-embedder-policy',
        'cross-origin-resource-policy',
        'x-robots-tag',
        'link',
    );

    public function register()
    {
        foreach (array('save_post', 'deleted_post', 'trashed_post', 'untrashed_post', 'transition_comment_status', 'edit_comment', 'edited_terms', 'created_term', 'delete_term', 'wp_update_nav_menu', 'switch_theme', 'customize_save_after', 'activated_plugin', 'deactivated_plugin', 'upgrader_process_complete', 'profile_update', 'deleted_user', 'nexoflow_seo_changed', 'elementor/core/files/clear_cache', 'elementor/document/after_save') as $hook) {
            add_action($hook, array(__CLASS__, 'clear'));
        }

        // A comment only changes the page once it is approved; a held or spam one does not.
        add_action('comment_post', array(__CLASS__, 'comment_posted'), 10, 2);
        add_action('updated_option', array(__CLASS__, 'option_updated'));
        add_action(self::PURGE_HOOK, array(__CLASS__, 'purge_old'));

        foreach (array('blogname', 'blogdescription', 'sidebars_widgets', 'show_on_front', 'page_on_front', 'page_for_posts', 'permalink_structure', 'blog_public', NexoFlow_SEO::SETTINGS, 'nexoflow_structured_data') as $option) {
            add_action('update_option_' . $option, array(__CLASS__, 'clear'));
        }

        if (!self::enabled() || !self::cacheable_request()) {
            return;
        }

        $this->serve();

        if (self::storable_request()) {
            add_action('template_redirect', array($this, 'start'), 99);
        }
    }

    public static function enabled()
    {
        $stored = get_option(self::OPTION, array());

        return is_array($stored) && !empty($stored['enabled']) && self::other_cache() === '';
    }

    /** The name of another page cache on this site, or an empty string. */
    public static function other_cache()
    {
        $constants = array(
            'LSCWP_V' => 'litespeed_cache',
            'WP_ROCKET_VERSION' => 'wp_rocket',
            'W3TC' => 'w3_total_cache',
            'WPCACHEHOME' => 'wp_super_cache',
            'WPFC_MAIN_PATH' => 'wp_fastest_cache',
            'BREEZE_VERSION' => 'breeze',
            'COMET_CACHE_PLUGIN_FILE' => 'comet_cache',
            'NITROPACK_VERSION' => 'nitropack',
            'SWCFPC_PLUGIN_PATH' => 'cloudflare_page_cache',
        );

        foreach ($constants as $constant => $name) {
            if (defined($constant)) {
                return $name;
            }
        }

        if (class_exists('SiteGround_Optimizer\\Loader\\Loader')) {
            return 'siteground_optimizer';
        }

        if (defined('WP_CACHE') && WP_CACHE && defined('WP_CONTENT_DIR') && file_exists(WP_CONTENT_DIR . '/advanced-cache.php')) {
            return 'server_cache';
        }

        return '';
    }

    public static function status()
    {
        $other = self::other_cache();

        return array(
            'enabled' => self::enabled(),
            'other_cache' => $other !== '' ? $other : null,
            'available' => $other === '',
            'minutes' => (int) (self::TTL / 60),
        );
    }

    public static function set_enabled($enabled)
    {
        update_option(self::OPTION, array('enabled' => (bool) $enabled));
        self::clear();
    }

    /** Every copy made so far becomes old, and the old ones are taken away soon after. */
    public static function clear()
    {
        update_option(self::GENERATION, (int) get_option(self::GENERATION, 0) + 1);

        if (function_exists('wp_next_scheduled') && function_exists('wp_schedule_single_event') && !wp_next_scheduled(self::PURGE_HOOK)) {
            wp_schedule_single_event(time() + 300, self::PURGE_HOOK);
        }
    }

    /** comment_post: only an approved comment changes what visitors see. */
    public static function comment_posted($comment_id, $approved = 0)
    {
        if ($approved === 1 || $approved === '1') {
            self::clear();
        }
    }

    /** updated_option: a widget's settings show on every page. */
    public static function option_updated($option)
    {
        if (is_string($option) && strpos($option, 'widget_') === 0) {
            self::clear();
        }
    }

    /** Removes the copies of earlier generations from the database. With an object cache they expire on their own. */
    public static function purge_old()
    {
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb) || (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache())) {
            return;
        }

        $current = (int) get_option(self::GENERATION, 0);
        $like = $wpdb->esc_like('_transient_' . self::PREFIX) . '%';
        $like_timeout = $wpdb->esc_like('_transient_timeout_' . self::PREFIX) . '%';
        $keep = $wpdb->esc_like('_transient_' . self::PREFIX . $current . '_') . '%';
        $keep_timeout = $wpdb->esc_like('_transient_timeout_' . self::PREFIX . $current . '_') . '%';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One cleanup of this plugin's own rows.
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE (option_name LIKE %s OR option_name LIKE %s) AND option_name NOT LIKE %s AND option_name NOT LIKE %s",
                $like,
                $like_timeout,
                $keep,
                $keep_timeout
            )
        );
    }

    /**
     * Whether a copy may be served for this request.
     *
     * @param array $request method, uri, cookies, and host when known
     */
    public static function request_can_be_cached($request)
    {
        if (!in_array($request['method'], array('GET', 'HEAD'), true)) {
            return false;
        }

        $uri = (string) $request['uri'];

        // A fragment or a second slash at the start is read one way here and another way by WordPress.
        if ($uri === '' || $uri[0] !== '/' || strpos($uri, '//') === 0 || strpos($uri, '#') !== false) {
            return false;
        }

        if (isset($request['host']) && !self::is_host((string) $request['host'])) {
            return false;
        }

        $path = self::path_of($uri);
        $query = self::query_of($uri);

        if ($path === '' || preg_match('#/(wp-admin|wp-json|wp-content|wp-includes|feed)(/|$)|/wp-[a-z-]+\.php$|xmlrpc\.php$#i', $path)) {
            return false;
        }

        // A file, such as llms.txt or a sitemap. Only pages are kept.
        if (preg_match('#\.[a-z0-9]{1,8}$#i', $path) && !preg_match('#\.html?$#i', $path)) {
            return false;
        }

        if ($query !== '') {
            parse_str($query, $parameters);

            foreach (array_keys($parameters) as $name) {
                if (strpos((string) $name, 'utm_') !== 0 && !in_array((string) $name, self::CAMPAIGN_PARAMETERS, true)) {
                    return false;
                }
            }
        }

        foreach (array_keys($request['cookies']) as $cookie) {
            foreach (self::PRIVATE_COOKIES as $prefix) {
                if (strpos((string) $cookie, $prefix) === 0) {
                    return false;
                }
            }
        }

        return true;
    }

    /** Whether this request may also make the copy: a plain request, with nothing after the address. */
    public static function request_can_be_stored($request)
    {
        return self::request_can_be_cached($request) && self::query_of((string) $request['uri']) === '';
    }

    /** A host name, optionally with a port: letters, digits, dots and dashes only. */
    public static function is_host($host)
    {
        return (bool) preg_match('/^(?=.{1,253}(:\d{1,5})?$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*\.?(?::\d{1,5})?$/i', $host);
    }

    public static function key($host, $path, $https, $mobile)
    {
        return md5(strtolower((string) $host) . '|' . rtrim((string) $path, '/') . '|' . ($https ? 's' : 'p') . '|' . ($mobile ? 'm' : 'd'));
    }

    /** The path of a request address, exactly as sent: everything before the first "?". */
    private static function path_of($uri)
    {
        $at = strpos($uri, '?');

        return $at === false ? $uri : substr($uri, 0, $at);
    }

    private static function query_of($uri)
    {
        $at = strpos($uri, '?');

        return $at === false ? '' : (string) substr($uri, $at + 1);
    }

    private static function request()
    {
        if ((defined('WP_CLI') && WP_CLI) || (defined('DOING_CRON') && DOING_CRON) || is_admin()) {
            return null;
        }

        if (!isset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $_SERVER['HTTP_HOST'])) {
            return null;
        }

        return array(
            'method' => sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])),
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Checked character by character, and only compared and hashed.
            'uri' => (string) wp_unslash($_SERVER['REQUEST_URI']),
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Checked to be a host name before use.
            'host' => (string) wp_unslash($_SERVER['HTTP_HOST']),
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only the cookie names are read.
            'cookies' => is_array($_COOKIE) ? $_COOKIE : array(),
        );
    }

    private static function cacheable_request()
    {
        $request = self::request();

        return $request !== null && self::request_can_be_cached($request);
    }

    private static function storable_request()
    {
        $request = self::request();

        return $request !== null && self::request_can_be_stored($request);
    }

    /** The name this page's copy is kept under, or an empty string when the request does not say which page it is. */
    private static function transient()
    {
        if (!isset($_SERVER['REQUEST_URI'], $_SERVER['HTTP_HOST'])) {
            return '';
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Checked to be a host name here.
        $host = (string) wp_unslash($_SERVER['HTTP_HOST']);

        if (!self::is_host($host)) {
            return '';
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only hashed.
        $uri = (string) wp_unslash($_SERVER['REQUEST_URI']);
        $path = self::plain_path(self::path_of($uri));

        if ($path === null) {
            return '';
        }

        $key = self::key($host, $path, is_ssl(), wp_is_mobile());

        return self::PREFIX . (int) get_option(self::GENERATION, 0) . '_' . $key;
    }

    private function serve()
    {
        $name = self::transient();
        $copy = $name !== '' ? get_transient($name) : false;

        // A copy is the page and the headers it was sent with; a copy from 2.0.0 is the page alone.
        $page = is_array($copy) && isset($copy['html']) ? $copy['html'] : $copy;
        $headers = is_array($copy) && isset($copy['headers']) && is_array($copy['headers']) ? $copy['headers'] : array();

        if (!is_string($page) || $page === '') {
            return;
        }

        header('Content-Type: text/html; charset=' . get_bloginfo('charset'));

        foreach ($headers as $header) {
            if (is_string($header) && strpos($header, "\n") === false && strpos($header, "\r") === false) {
                header($header, false);
            }
        }

        header('X-NexoFlow-Cache: HIT');

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The page as WordPress drew it for an earlier visitor.
        echo $page;
        exit;
    }

    public function start()
    {
        if (is_user_logged_in() || is_404() || is_search() || is_feed() || is_preview() || is_customize_preview() || is_trackback() || is_robots() || is_embed()) {
            return;
        }

        if (is_singular() && post_password_required()) {
            return;
        }

        // Only the page's own address makes its copy: another spelling of it (other letter case, extra characters)
        // is drawn as usual and kept nowhere.
        if (!self::is_own_address()) {
            return;
        }

        header('X-NexoFlow-Cache: MISS');
        ob_start(array($this, 'store'));
    }

    /** Whether the request is for the address WordPress itself gives what it is showing. */
    private static function is_own_address()
    {
        $request = self::request();

        if ($request === null) {
            return false;
        }

        // Compared as sent (escapes in one case), the same way the copy is keyed: every spelling that makes a copy
        // is the page's own address, so no visitor can fill the cache with copies of one page.
        $path = self::plain_path(self::path_of($request['uri']));

        if ($path === null) {
            return false;
        }

        $path = rtrim($path, '/');
        $own = '';
        $paged = max((int) get_query_var('paged'), (int) get_query_var('page'));

        if (is_front_page() || is_home()) {
            $blog = (int) get_option('page_for_posts');
            $own = !is_front_page() && $blog > 0 ? get_permalink($blog) : home_url('/');

            if ($paged > 1 && is_string($own)) {
                $own = trailingslashit($own) . 'page/' . $paged;
            }
        } elseif (is_singular()) {
            $own = get_permalink(get_queried_object_id());

            if ($paged > 1 && is_string($own)) {
                $own = trailingslashit($own) . $paged;
            }
        } elseif (is_category() || is_tag() || is_tax()) {
            $term = get_queried_object();
            $own = is_object($term) ? get_term_link($term) : '';

            if ($paged > 1 && is_string($own)) {
                $own = trailingslashit($own) . 'page/' . $paged;
            }
        } else {
            // Other lists (dates, authors) are kept only under a plain lower-case address.
            return $path === strtolower($path) && preg_match('#^[a-z0-9/_.%-]*$#', $path) && strpos($path, '--') === false;
        }

        if (!is_string($own) || $own === '' || is_wp_error($own)) {
            return false;
        }

        $own_path = self::plain_path((string) wp_parse_url($own, PHP_URL_PATH));

        return $own_path !== null && rtrim($own_path, '/') === $path && strpos($own, '?') === false;
    }

    public function store($html)
    {
        $name = self::transient();
        $headers = headers_list();
        $session = function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE;

        if ($name !== '' && !$session && self::worth_keeping($html, http_response_code(), $headers, defined('DONOTCACHEPAGE') && DONOTCACHEPAGE) && self::take_place()) {
            set_transient($name, array('html' => $html, 'headers' => self::kept_headers($headers)), self::TTL);
        }

        return $html;
    }

    /**
     * Counts a new copy against the limit; false when it is full. The count starts again with each generation and
     * each hour, since copies expire after an hour, so a quiet site keeps storing pages.
     */
    private static function take_place()
    {
        $generation = (int) get_option(self::GENERATION, 0);
        $window = (int) floor(time() / self::TTL);
        $stored = explode(':', (string) get_option(self::COUNT, ''));
        $count = count($stored) === 3 && (int) $stored[0] === $generation && (int) $stored[1] === $window ? (int) $stored[2] : 0;

        if ($count >= self::MAX_ENTRIES) {
            return false;
        }

        update_option(self::COUNT, $generation . ':' . $window . ':' . ($count + 1), false);

        return true;
    }

    /**
     * One spelling of a request path: percent escapes in lower case. Null when the path spells out with an escape a
     * character that needs none (such as %61 for "a"), which only a hand-made request does.
     */
    public static function plain_path($path)
    {
        $path = (string) $path;

        if (preg_match_all('/%([0-9a-f]{2})/i', $path, $escapes)) {
            foreach ($escapes[1] as $hex) {
                if (preg_match('/^[A-Za-z0-9._~-]$/', chr(hexdec($hex)))) {
                    return null;
                }
            }
        }

        return (string) preg_replace_callback(
            '/%[0-9a-f]{2}/i',
            function ($match) {
                return strtolower($match[0]);
            },
            $path
        );
    }

    public static function kept_headers($headers)
    {
        $kept = array();

        foreach ((array) $headers as $header) {
            $name = strtolower(trim((string) strtok((string) $header, ':')));

            if (in_array($name, self::KEPT_HEADERS, true)) {
                $kept[] = (string) $header;
            }
        }

        return $kept;
    }

    public static function worth_keeping($html, $status, $headers, $refused)
    {
        if ($refused || (int) $status !== 200 || !is_string($html)) {
            return false;
        }

        $bytes = strlen($html);

        if ($bytes < self::MIN_BYTES || $bytes > self::MAX_BYTES || stripos($html, '</html>') === false) {
            return false;
        }

        foreach ($headers as $header) {
            $header = strtolower((string) $header);

            // Someone's own cookie on the page, or a page that is not HTML.
            if (strpos($header, 'set-cookie:') === 0 || (strpos($header, 'content-type:') === 0 && strpos($header, 'text/html') === false)) {
                return false;
            }

            // The page says it is for this visitor only.
            if (strpos($header, 'cache-control:') === 0 && preg_match('/\b(private|no-store|no-cache)\b/', $header)) {
                return false;
            }

            if (strpos($header, 'pragma:') === 0 && strpos($header, 'no-cache') !== false) {
                return false;
            }

            // A page that differs by something other than compression would need one copy per variant.
            if (strpos($header, 'vary:') === 0) {
                $varies = array_filter(array_map('trim', explode(',', substr($header, 5))));

                if (array_diff($varies, array('accept-encoding')) !== array()) {
                    return false;
                }
            }
        }

        return true;
    }
}
