<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A plain page cache for visitors who are not signed in, off until it is asked
 * for. It stands aside for any other caching plugin. Copies live for an hour at
 * most, so a form's nonce on a cached page is still valid, and any change to the
 * site makes every copy old.
 */
class NexoFlow_Page_Cache
{
    const OPTION = 'nexoflow_page_cache';

    const GENERATION = 'nexoflow_page_cache_generation';

    const TTL = 3600;

    const MAX_BYTES = 1572864;

    const MIN_BYTES = 512;

    const PREFIX = 'nf_pc_';

    /** A cookie that means the visitor may see something of their own. */
    const PRIVATE_COOKIES = array(
        'wordpress_logged_in_',
        'wp-postpass_',
        'comment_author_',
        'woocommerce_items_in_cart',
        'woocommerce_cart_hash',
        'wp_woocommerce_session_',
        'edd_items_in_cart',
    );

    /** Query parameters that only say where a visitor came from. The page is the same. */
    const CAMPAIGN_PARAMETERS = array('gclid', 'fbclid', 'msclkid', 'dclid', 'ttclid', 'mc_cid', 'mc_eid', '_ga', 'ref');

    public function register()
    {
        foreach (array('save_post', 'deleted_post', 'trashed_post', 'untrashed_post', 'comment_post', 'transition_comment_status', 'edited_terms', 'created_term', 'delete_term', 'wp_update_nav_menu', 'switch_theme', 'customize_save_after', 'activated_plugin', 'deactivated_plugin', 'upgrader_process_complete', 'nexoflow_seo_changed', 'elementor/core/files/clear_cache', 'elementor/document/after_save') as $hook) {
            add_action($hook, array(__CLASS__, 'clear'));
        }

        foreach (array('blogname', 'blogdescription', 'sidebars_widgets', 'show_on_front', 'page_on_front', 'page_for_posts', 'permalink_structure', 'blog_public', NexoFlow_SEO::SETTINGS) as $option) {
            add_action('update_option_' . $option, array(__CLASS__, 'clear'));
        }

        if (!self::enabled() || !self::cacheable_request()) {
            return;
        }

        $this->serve();
        add_action('template_redirect', array($this, 'start'), 99);
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

    /** Every copy made so far becomes old. WordPress removes the old ones when they expire. */
    public static function clear()
    {
        update_option(self::GENERATION, (int) get_option(self::GENERATION, 0) + 1);
    }

    /**
     * @param array $request method, uri, cookies, https
     */
    public static function request_can_be_cached($request)
    {
        if (!in_array($request['method'], array('GET', 'HEAD'), true)) {
            return false;
        }

        $uri = (string) $request['uri'];
        $path = (string) wp_parse_url($uri, PHP_URL_PATH);
        $query = (string) wp_parse_url($uri, PHP_URL_QUERY);

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

    public static function key($host, $path, $https, $mobile)
    {
        return md5(strtolower((string) $host) . '|' . rtrim((string) $path, '/') . '|' . ($https ? 's' : 'p') . '|' . ($mobile ? 'm' : 'd'));
    }

    private static function cacheable_request()
    {
        if ((defined('WP_CLI') && WP_CLI) || (defined('DOING_CRON') && DOING_CRON) || is_admin()) {
            return false;
        }

        if (!isset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $_SERVER['HTTP_HOST'])) {
            return false;
        }

        return self::request_can_be_cached(
            array(
                'method' => sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])),
                'uri' => esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])),
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only the cookie names are read.
                'cookies' => is_array($_COOKIE) ? $_COOKIE : array(),
            )
        );
    }

    /** The name this page's copy is kept under, or an empty string when the request does not say which page it is. */
    private static function transient()
    {
        if (!isset($_SERVER['REQUEST_URI'], $_SERVER['HTTP_HOST'])) {
            return '';
        }

        $uri = esc_url_raw(wp_unslash($_SERVER['REQUEST_URI']));
        $key = self::key(
            sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])),
            (string) wp_parse_url($uri, PHP_URL_PATH),
            is_ssl(),
            wp_is_mobile()
        );

        return self::PREFIX . (int) get_option(self::GENERATION, 0) . '_' . $key;
    }

    private function serve()
    {
        $name = self::transient();
        $page = $name !== '' ? get_transient($name) : false;

        if (!is_string($page) || $page === '') {
            return;
        }

        header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
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

        header('X-NexoFlow-Cache: MISS');
        ob_start(array($this, 'store'));
    }

    public function store($html)
    {
        $name = self::transient();

        if ($name !== '' && self::worth_keeping($html, http_response_code(), headers_list(), defined('DONOTCACHEPAGE') && DONOTCACHEPAGE)) {
            set_transient($name, $html, self::TTL);
        }

        return $html;
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
        }

        return true;
    }
}
