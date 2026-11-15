<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What a site needs from an SEO plugin, so it does not need one: each page's
 * search title, description, canonical address and robots setting, link
 * previews, structured data where NexoFlow has none for a page, a clean sitemap
 * and redirects for addresses that are gone.
 *
 * Connected sites only. With another SEO plugin active that plugin keeps printing
 * the tags, and what NexoFlow writes goes into that plugin's own fields.
 */
class NexoFlow_SEO
{
    const META_TITLE = '_nexoflow_seo_title';

    const META_DESCRIPTION = '_nexoflow_seo_description';

    const META_CANONICAL = '_nexoflow_seo_canonical';

    const META_NOINDEX = '_nexoflow_seo_noindex';

    const META_NOFOLLOW = '_nexoflow_seo_nofollow';

    const META_IMAGE = '_nexoflow_seo_image';

    const META_HEADINGS = '_nexoflow_seo_headings';

    const SETTINGS = 'nexoflow_seo_settings';

    const REDIRECTS = 'nexoflow_seo_redirects';

    const MAX_REDIRECTS = 500;

    const DEFAULTS = array(
        'printing' => true,
        'noindex_author' => false,
        'noindex_date' => false,
        'noindex_tag' => false,
        'noindex_category' => false,
        'noindex_term_ids' => array(),
        'default_image_id' => 0,
        'home_title' => '',
        'home_description' => '',
    );

    /** Where each SEO plugin keeps a page's fields, so a change shows through it. */
    const OTHER_PLUGIN_FIELDS = array(
        'yoast' => array(
            'title' => '_yoast_wpseo_title',
            'description' => '_yoast_wpseo_metadesc',
            'canonical' => '_yoast_wpseo_canonical',
            'noindex' => '_yoast_wpseo_meta-robots-noindex',
        ),
        'rank_math' => array(
            'title' => 'rank_math_title',
            'description' => 'rank_math_description',
            'canonical' => 'rank_math_canonical_url',
            'noindex' => 'rank_math_robots',
        ),
        'seopress' => array(
            'title' => '_seopress_titles_title',
            'description' => '_seopress_titles_desc',
            'canonical' => '_seopress_robots_canonical',
            'noindex' => '_seopress_robots_index',
        ),
        'the_seo_framework' => array(
            'title' => '_genesis_title',
            'description' => '_genesis_description',
            'canonical' => '_genesis_canonical_uri',
            'noindex' => '_genesis_noindex',
        ),
    );

    private static $settings = null;

    public function register()
    {
        add_filter('pre_get_document_title', array($this, 'document_title'), 20);
        add_filter('wp_title', array($this, 'document_title'), 20);
        add_action('wp_head', array($this, 'print_tags'), 3);
        add_action('wp_head', array($this, 'print_archive_canonical'), 10);
        add_action('wp_head', array($this, 'print_fallback_schema'), 21);
        add_filter('get_canonical_url', array($this, 'canonical_url'), 10, 2);
        add_filter('wp_robots', array($this, 'robots'), 20);

        add_filter('wp_sitemaps_add_provider', array($this, 'sitemap_provider'), 10, 2);
        add_filter('wp_sitemaps_taxonomies', array($this, 'sitemap_taxonomies'));
        add_filter('wp_sitemaps_taxonomies_query_args', array($this, 'sitemap_terms'));
        add_filter('wp_sitemaps_posts_query_args', array($this, 'sitemap_posts'));
        add_filter('wp_sitemaps_posts_entry', array($this, 'sitemap_entry'), 10, 2);

        add_action('template_redirect', array($this, 'maybe_redirect'), 1);
        add_action('template_redirect', array($this, 'start_buffer'), 100);
        add_filter('wp_content_img_tag', array($this, 'image_alt'), 10, 3);
        add_filter('comment_reply_link', array($this, 'reply_link'));

        // This plugin keeps its fields in a table of its own and reads them through these.
        add_filter('aioseo_title', array($this, 'aioseo_title'));
        add_filter('aioseo_description', array($this, 'aioseo_description'));
        add_filter('aioseo_canonical_url', array($this, 'aioseo_canonical'));
        add_filter('aioseo_robots_meta', array($this, 'aioseo_robots'));
    }

    // ── What is on this site ────────────────────────────────────────────────────

    public static function other_plugins()
    {
        $found = array();
        $known = array(
            'yoast' => 'WPSEO_VERSION',
            'rank_math' => 'RANK_MATH_VERSION',
            'aioseo' => 'AIOSEO_VERSION',
            'seopress' => 'SEOPRESS_VERSION',
            'the_seo_framework' => 'THE_SEO_FRAMEWORK_VERSION',
            'smartcrawl' => 'SMARTCRAWL_VERSION',
            'squirrly' => 'SQ_VERSION',
            'slim_seo' => 'SLIM_SEO_VER',
        );

        foreach ($known as $name => $constant) {
            if (defined($constant) && !self::plugin_is_idle($name)) {
                $found[] = $name;
            }
        }

        return $found;
    }

    /** Rank Math prints nothing until its setup is finished, so it is not looking after the site yet. */
    private static function plugin_is_idle($name)
    {
        return $name === 'rank_math'
            && is_callable(array('RankMath\\Helper', 'is_invalid_registration'))
            && call_user_func(array('RankMath\\Helper', 'is_invalid_registration'));
    }

    /** Whether this plugin prints the tags: connected, no other SEO plugin, and not switched off. */
    public static function printing()
    {
        if (!NexoFlow_Client::has_site_key() || !empty(self::other_plugins())) {
            return false;
        }

        $settings = self::settings();

        return !empty($settings['printing']) && (bool) apply_filters('nexoflow_seo_print', true);
    }

    public static function settings()
    {
        if (self::$settings === null) {
            $stored = get_option(self::SETTINGS, array());
            self::$settings = array_merge(self::DEFAULTS, is_array($stored) ? $stored : array());
        }

        return self::$settings;
    }

    /** @return array the settings as they were */
    public static function save_settings($changes)
    {
        $before = self::settings();
        $after = array_merge($before, array_intersect_key($changes, self::DEFAULTS));
        update_option(self::SETTINGS, $after);
        self::$settings = $after;

        return $before;
    }

    public static function forget_settings()
    {
        self::$settings = null;
    }

    // ── One page's fields ───────────────────────────────────────────────────────

    /** What is set on a page, read from the other SEO plugin when one is in charge. */
    /**
     * A page's written search title or description. With no SEO plugin active, a post the earlier NexoFlow plugin
     * (1.0.0 to 1.2.5) published keeps the one it stored in Yoast's field and printed itself. A value with Yoast's
     * own variables in it is left out: only Yoast can fill those in.
     */
    public static function written($post_id, $field)
    {
        $meta = $field === 'title' ? self::META_TITLE : self::META_DESCRIPTION;
        $value = (string) get_post_meta((int) $post_id, $meta, true);

        if ($value !== '' || !empty(self::other_plugins())) {
            return $value;
        }

        $legacy = (string) get_post_meta((int) $post_id, self::OTHER_PLUGIN_FIELDS['yoast'][$field], true);

        return strpos($legacy, '%%') === false ? $legacy : '';
    }

    public static function page_values($post_id)
    {
        $post_id = (int) $post_id;
        $values = array(
            'title' => self::written($post_id, 'title'),
            'description' => self::written($post_id, 'description'),
            'canonical' => (string) get_post_meta($post_id, self::META_CANONICAL, true),
            'noindex' => (string) get_post_meta($post_id, self::META_NOINDEX, true) === '1',
            'nofollow' => (string) get_post_meta($post_id, self::META_NOFOLLOW, true) === '1',
            'image_id' => (int) get_post_meta($post_id, self::META_IMAGE, true),
            'fix_headings' => (string) get_post_meta($post_id, self::META_HEADINGS, true) === '1',
        );

        foreach (self::other_plugin_fields() as $field => $key) {
            if ($field === 'noindex') {
                $values['noindex'] = $values['noindex'] || self::other_noindex(get_post_meta($post_id, $key, true));
            } elseif ($values[$field] === '') {
                $value = get_post_meta($post_id, $key, true);
                $values[$field] = is_string($value) ? $value : '';
            }
        }

        return $values;
    }

    /**
     * Saves the fields named in $fields. An empty text or a false clears the field.
     *
     * @return array the same fields as they were, so the change can be put back
     */
    public static function save_page($post_id, $fields)
    {
        $post_id = (int) $post_id;
        $before = self::page_values($post_id);
        $previous = array_intersect_key($before, $fields);
        $meta = array(
            'title' => self::META_TITLE,
            'description' => self::META_DESCRIPTION,
            'canonical' => self::META_CANONICAL,
            'noindex' => self::META_NOINDEX,
            'nofollow' => self::META_NOFOLLOW,
            'image_id' => self::META_IMAGE,
            'fix_headings' => self::META_HEADINGS,
        );
        $other = self::other_plugin_fields();

        foreach ($fields as $field => $value) {
            if (!isset($meta[$field])) {
                continue;
            }

            if (($field === 'title' || $field === 'description') && is_string($value)) {
                $value = self::without_plugin_variables($value);
            }

            if ($value === '' || $value === false || $value === 0) {
                delete_post_meta($post_id, $meta[$field]);
            } else {
                update_post_meta($post_id, $meta[$field], is_bool($value) ? '1' : $value);
            }

            if (isset($other[$field])) {
                self::write_other_plugin($post_id, $other[$field], $field, $value);
            }
        }

        // Yoast serves what it indexed from the post, so it has to see the post saved.
        if (defined('WPSEO_VERSION') && !empty(array_intersect_key($fields, $other))) {
            wp_update_post(array('ID' => $post_id));
        }

        do_action('nexoflow_seo_changed', $post_id);

        return $previous;
    }

    /**
     * SEO plugins fill in variables such as %%cf_field%% or %customfield(field)% when they print a title, which
     * could show a page's hidden fields. Text NexoFlow writes is plain, so those are taken out.
     */
    public static function without_plugin_variables($text)
    {
        $text = (string) preg_replace('/%%[^%\s]{1,64}%%|%[a-z_]{2,40}\([^)%]{0,128}\)%/i', '', (string) $text);

        return trim((string) preg_replace('/\s{2,}/', ' ', $text));
    }

    private static function other_plugin_fields()
    {
        foreach (self::other_plugins() as $name) {
            if (isset(self::OTHER_PLUGIN_FIELDS[$name])) {
                return self::OTHER_PLUGIN_FIELDS[$name];
            }
        }

        return array();
    }

    private static function write_other_plugin($post_id, $key, $field, $value)
    {
        if ($field !== 'noindex') {
            if ($value === '') {
                delete_post_meta($post_id, $key);
            } else {
                update_post_meta($post_id, $key, $value);
            }

            return;
        }

        $on = (bool) $value;

        switch ($key) {
            case '_yoast_wpseo_meta-robots-noindex':
                update_post_meta($post_id, $key, $on ? '1' : '2');
                break;
            case 'rank_math_robots':
                update_post_meta($post_id, $key, array($on ? 'noindex' : 'index'));
                break;
            case '_seopress_robots_index':
                update_post_meta($post_id, $key, $on ? 'yes' : '');
                break;
            default:
                update_post_meta($post_id, $key, $on ? '1' : '0');
        }
    }

    private static function other_noindex($value)
    {
        if (is_array($value)) {
            return in_array('noindex', $value, true);
        }

        return $value === '1' || $value === 'yes';
    }

    private static function current_id()
    {
        if (is_singular() || (is_home() && !is_front_page())) {
            return (int) get_queried_object_id();
        }

        return 0;
    }

    // ── Printing ────────────────────────────────────────────────────────────────

    private function custom_title()
    {
        $post_id = self::current_id();
        $title = $post_id > 0 ? self::written($post_id, 'title') : '';

        if ($title === '' && is_front_page()) {
            $settings = self::settings();
            $title = (string) $settings['home_title'];
        }

        return $title;
    }

    public function document_title($title)
    {
        if (is_admin() || is_feed() || !self::printing()) {
            return $title;
        }

        $custom = $this->custom_title();

        // WordPress prints this title as it is, so it is escaped here, whatever was stored.
        return $custom !== '' ? esc_html($custom) : $title;
    }

    /** The description for the page being shown: written for it, else taken from its own words. */
    public function current_description()
    {
        $post_id = self::current_id();

        if ($post_id > 0) {
            $written = self::written($post_id, 'description');

            if ($written !== '') {
                return $written;
            }
        }

        if (is_front_page()) {
            $settings = self::settings();

            if ((string) $settings['home_description'] !== '') {
                return (string) $settings['home_description'];
            }
        }

        if ($post_id > 0 && !post_password_required($post_id)) {
            $post = get_post($post_id);

            if ($post) {
                $source = (string) $post->post_excerpt !== '' ? $post->post_excerpt : $post->post_content;
                $text = NexoFlow_SEO_Markup::describe(strip_shortcodes((string) $source));

                if ($text !== '') {
                    return $text;
                }
            }
        }

        if (is_front_page()) {
            return NexoFlow_SEO_Markup::describe((string) get_bloginfo('description'), 155, 20);
        }

        if (is_category() || is_tag() || is_tax()) {
            return NexoFlow_SEO_Markup::describe((string) term_description(), 155, 20);
        }

        return '';
    }

    private function current_url()
    {
        if (is_singular()) {
            return (string) wp_get_canonical_url();
        }

        return $this->archive_url();
    }

    private function archive_url()
    {
        $paged = max(1, (int) get_query_var('paged'));
        $url = '';

        if (is_front_page()) {
            $url = home_url('/');
        } elseif (is_home()) {
            $url = (string) get_permalink((int) get_option('page_for_posts'));
        } elseif (is_category() || is_tag() || is_tax()) {
            $link = get_term_link(get_queried_object());
            $url = is_string($link) ? $link : '';
        } elseif (is_author()) {
            $url = (string) get_author_posts_url((int) get_queried_object_id());
        } elseif (is_post_type_archive()) {
            $link = get_post_type_archive_link((string) get_query_var('post_type'));
            $url = is_string($link) ? $link : '';
        } elseif (is_year() || is_month() || is_day()) {
            $url = (string) get_pagenum_link(1, false);
        }

        if ($url === '') {
            return '';
        }

        return $paged > 1 ? (string) get_pagenum_link($paged, false) : $url;
    }

    /** WordPress only says the canonical address on single pages. */
    public function print_archive_canonical()
    {
        if (is_singular() || is_404() || is_search() || !self::printing()) {
            return;
        }

        $url = $this->archive_url();

        if ($url !== '') {
            echo '<link rel="canonical" href="' . esc_url($url) . '" />' . "\n";
        }
    }

    public function canonical_url($url, $post)
    {
        if (!self::printing() || !is_object($post)) {
            return $url;
        }

        $custom = (string) get_post_meta((int) $post->ID, self::META_CANONICAL, true);

        return $custom !== '' ? $custom : $url;
    }

    public function robots($robots)
    {
        if (!self::printing()) {
            return $robots;
        }

        $post_id = self::current_id();
        $settings = self::settings();
        $hide = $post_id > 0 && (string) get_post_meta($post_id, self::META_NOINDEX, true) === '1';

        if (is_author() && !empty($settings['noindex_author'])) {
            $hide = true;
        } elseif ((is_year() || is_month() || is_day()) && !empty($settings['noindex_date'])) {
            $hide = true;
        } elseif (is_tag() && !empty($settings['noindex_tag'])) {
            $hide = true;
        } elseif (is_category() && !empty($settings['noindex_category'])) {
            $hide = true;
        } elseif ((is_category() || is_tag() || is_tax()) && in_array((int) get_queried_object_id(), array_map('intval', (array) $settings['noindex_term_ids']), true)) {
            $hide = true;
        }

        if ($hide) {
            $robots['noindex'] = true;
        }

        if ($post_id > 0 && (string) get_post_meta($post_id, self::META_NOFOLLOW, true) === '1') {
            $robots['nofollow'] = true;
        }

        return $robots;
    }

    public function print_tags()
    {
        if (is_404() || is_search() || !self::printing()) {
            return;
        }

        $title = wp_get_document_title();
        $description = $this->current_description();
        $url = $this->current_url();
        $image = $this->current_image();
        $article = is_singular('post');
        $lines = array();

        if ($description !== '') {
            $lines[] = '<meta name="description" content="' . esc_attr($description) . '" />';
        }

        $lines[] = self::property('og:locale', get_locale());
        $lines[] = self::property('og:type', $article ? 'article' : 'website');
        $lines[] = self::property('og:title', $title);
        $lines[] = self::property('og:site_name', get_bloginfo('name'));

        if ($description !== '') {
            $lines[] = self::property('og:description', $description);
        }

        if ($url !== '') {
            $lines[] = '<meta property="og:url" content="' . esc_url($url) . '" />';
        }

        if ($image !== null) {
            $lines[] = '<meta property="og:image" content="' . esc_url($image['url']) . '" />';

            if (!empty($image['width']) && !empty($image['height'])) {
                $lines[] = self::property('og:image:width', (string) (int) $image['width']);
                $lines[] = self::property('og:image:height', (string) (int) $image['height']);
            }

            if (!empty($image['alt'])) {
                $lines[] = self::property('og:image:alt', $image['alt']);
            }
        }

        if ($article) {
            $post_id = self::current_id();
            $lines[] = self::property('article:published_time', (string) get_post_time('c', true, $post_id));
            $lines[] = self::property('article:modified_time', (string) get_post_modified_time('c', true, $post_id));
        }

        $lines[] = '<meta name="twitter:card" content="' . ($image !== null ? 'summary_large_image' : 'summary') . '" />';
        $lines[] = '<meta name="twitter:title" content="' . esc_attr($title) . '" />';

        if ($description !== '') {
            $lines[] = '<meta name="twitter:description" content="' . esc_attr($description) . '" />';
        }

        if ($image !== null) {
            $lines[] = '<meta name="twitter:image" content="' . esc_url($image['url']) . '" />';
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each line above is built from escaped values.
        echo implode("\n", $lines) . "\n";
    }

    private static function property($name, $value)
    {
        return '<meta property="' . esc_attr($name) . '" content="' . esc_attr((string) $value) . '" />';
    }

    /** The picture a shared link shows: chosen for the page, its featured image, its first image, the site's. */
    private function current_image()
    {
        $post_id = self::current_id();

        if ($post_id > 0) {
            $chosen = (int) get_post_meta($post_id, self::META_IMAGE, true);

            foreach (array($chosen, (int) get_post_thumbnail_id($post_id)) as $attachment) {
                $image = $attachment > 0 ? self::attachment_image($attachment) : null;

                if ($image !== null) {
                    return $image;
                }
            }

            $post = get_post($post_id);
            $first = $post ? NexoFlow_SEO_Markup::first_image((string) $post->post_content) : '';

            // Checked by its form only: looking up the host on every view would slow the page down.
            $parts = $first !== '' ? wp_parse_url($first) : false;

            if (is_array($parts) && isset($parts['scheme'], $parts['host']) && in_array(strtolower($parts['scheme']), array('http', 'https'), true) && $parts['host'] !== '') {
                return array('url' => $first, 'width' => 0, 'height' => 0, 'alt' => '');
            }
        }

        $settings = self::settings();

        foreach (array((int) $settings['default_image_id'], (int) get_theme_mod('custom_logo'), (int) get_option('site_icon')) as $attachment) {
            $image = $attachment > 0 ? self::attachment_image($attachment) : null;

            if ($image !== null) {
                return $image;
            }
        }

        return null;
    }

    private static function attachment_image($attachment_id)
    {
        $source = wp_get_attachment_image_src((int) $attachment_id, 'large');

        if (!is_array($source) || empty($source[0])) {
            return null;
        }

        return array(
            'url' => (string) $source[0],
            'width' => isset($source[1]) ? (int) $source[1] : 0,
            'height' => isset($source[2]) ? (int) $source[2] : 0,
            'alt' => (string) get_post_meta((int) $attachment_id, '_wp_attachment_image_alt', true),
        );
    }

    /** What is true of any page, for the pages NexoFlow has kept no structured data for. */
    public function print_fallback_schema()
    {
        if (is_404() || is_search() || !self::printing() || !isset($_SERVER['REQUEST_URI'])) {
            return;
        }

        if (!apply_filters('nexoflow_print_structured_data', true)) {
            return;
        }

        if (NexoFlow_Site_Data::script_tag_for(esc_url_raw(wp_unslash($_SERVER['REQUEST_URI']))) !== '') {
            return;
        }

        $graph = $this->fallback_graph();

        if (empty($graph)) {
            return;
        }

        $json = wp_json_encode(array('@context' => 'https://schema.org', '@graph' => $graph), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);

        if (is_string($json) && $json !== '') {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-LD encoded with JSON_HEX_TAG, so it cannot close the script tag.
            echo '<script type="application/ld+json" class="nexoflow-page-data">' . str_replace('</', '<\\/', $json) . '</script>' . "\n";
        }
    }

    private function fallback_graph()
    {
        $home = home_url('/');
        $url = $this->current_url();

        if ($url === '') {
            return array();
        }

        $site_id = $home . '#website';
        $page_id = $url . '#webpage';
        $description = $this->current_description();
        $site = array('@type' => 'WebSite', '@id' => $site_id, 'url' => $home, 'name' => (string) get_bloginfo('name'));
        $page = array(
            '@type' => is_singular() ? 'WebPage' : 'CollectionPage',
            '@id' => $page_id,
            'url' => $url,
            'name' => wp_get_document_title(),
            'isPartOf' => array('@id' => $site_id),
            'inLanguage' => (string) get_bloginfo('language'),
        );

        if ($description !== '') {
            $page['description'] = $description;
        }

        $graph = array($site, $page);
        $post = is_singular() ? get_queried_object() : null;

        if (is_object($post)) {
            $page['datePublished'] = (string) get_post_time('c', true, $post);
            $page['dateModified'] = (string) get_post_modified_time('c', true, $post);
            $graph[1] = $page;
            $crumbs = $this->breadcrumbs($post, $home);

            if (count($crumbs) > 1) {
                $graph[1]['breadcrumb'] = array('@id' => $url . '#breadcrumb');
                $graph[] = array('@type' => 'BreadcrumbList', '@id' => $url . '#breadcrumb', 'itemListElement' => $crumbs);
            }

            if ($post->post_type === 'post') {
                $article = array(
                    '@type' => 'Article',
                    '@id' => $url . '#article',
                    'headline' => (string) get_the_title($post),
                    'mainEntityOfPage' => array('@id' => $page_id),
                    'datePublished' => $page['datePublished'],
                    'dateModified' => $page['dateModified'],
                );
                $image = $this->current_image();

                if ($image !== null) {
                    $article['image'] = $image['url'];
                }

                $author = (string) get_the_author_meta('display_name', (int) $post->post_author);

                if ($author !== '') {
                    $article['author'] = array('@type' => 'Person', 'name' => $author);
                }

                $graph[] = $article;
            }
        }

        return $graph;
    }

    private function breadcrumbs($post, $home)
    {
        if ((int) $post->ID === (int) get_option('page_on_front')) {
            return array();
        }

        $items = array(array('name' => __('Home', 'nexoflow'), 'item' => $home));

        foreach (array_reverse(get_post_ancestors($post)) as $ancestor) {
            $items[] = array('name' => (string) get_the_title($ancestor), 'item' => (string) get_permalink($ancestor));
        }

        $items[] = array('name' => (string) get_the_title($post), 'item' => (string) get_permalink($post));
        $list = array();

        foreach ($items as $index => $item) {
            $list[] = array('@type' => 'ListItem', 'position' => $index + 1) + $item;
        }

        return $list;
    }

    // ── The sitemap ─────────────────────────────────────────────────────────────

    public function sitemap_provider($provider, $name)
    {
        $settings = self::settings();

        return $name === 'users' && self::printing() && !empty($settings['noindex_author']) ? false : $provider;
    }

    public function sitemap_taxonomies($taxonomies)
    {
        if (!self::printing()) {
            return $taxonomies;
        }

        $settings = self::settings();

        if (!empty($settings['noindex_category'])) {
            unset($taxonomies['category']);
        }

        if (!empty($settings['noindex_tag'])) {
            unset($taxonomies['post_tag']);
        }

        return $taxonomies;
    }

    public function sitemap_terms($args)
    {
        $settings = self::settings();

        if (self::printing() && !empty($settings['noindex_term_ids'])) {
            // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- A few term ids on a term query, for a sitemap page that is built rarely. WordPress offers no other way to leave a term out.
            $args['exclude'] = array_map('intval', (array) $settings['noindex_term_ids']);
        }

        return $args;
    }

    /** A page hidden from search is not offered to search engines either. */
    public function sitemap_posts($args)
    {
        if (self::printing()) {
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Sitemap pages are built rarely and cached by search engines.
            $args['meta_query'] = array(
                array(
                    'key' => self::META_NOINDEX,
                    'compare' => 'NOT EXISTS',
                ),
            );
        }

        return $args;
    }

    public function sitemap_entry($entry, $post)
    {
        if (self::printing() && is_object($post)) {
            $modified = get_post_modified_time('c', true, $post);

            if (is_string($modified) && $modified !== '') {
                $entry['lastmod'] = $modified;
            }
        }

        return $entry;
    }

    // ── Addresses that are gone ─────────────────────────────────────────────────

    /** One spelling for an address on this site: its path, no query, no trailing slash. Empty when it is not one. */
    public static function redirect_key($address)
    {
        if (!is_string($address) || trim($address) === '') {
            return '';
        }

        $parts = wp_parse_url(trim($address));

        if (!is_array($parts)) {
            return '';
        }

        if (isset($parts['host'])) {
            $home = wp_parse_url(home_url('/'), PHP_URL_HOST);
            $host = strtolower((string) preg_replace('/^www\./i', '', (string) $parts['host']));

            if ($host !== strtolower((string) preg_replace('/^www\./i', '', (string) $home))) {
                return '';
            }
        }

        $path = isset($parts['path']) ? rawurldecode((string) $parts['path']) : '';

        if ($path === '' || $path[0] !== '/') {
            return '';
        }

        $path = rtrim($path, '/');

        return $path === '' ? '/' : strtolower($path);
    }

    public static function redirects()
    {
        $stored = get_option(self::REDIRECTS, array());

        return is_array($stored) ? $stored : array();
    }

    public static function save_redirects($redirects)
    {
        if (empty($redirects)) {
            delete_option(self::REDIRECTS);
        } else {
            update_option(self::REDIRECTS, $redirects, false);
        }

        do_action('nexoflow_seo_changed', 0);
    }

    /**
     * Only an address that would have been a missing page is sent on, so a
     * redirect can never take down a page that is live.
     */
    public function maybe_redirect()
    {
        if (!is_404() || !NexoFlow_Client::has_site_key() || !isset($_SERVER['REQUEST_URI'])) {
            return;
        }

        $key = self::redirect_key(esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])));
        $redirects = self::redirects();

        if ($key === '' || !isset($redirects[$key]['to'])) {
            return;
        }

        $target = (string) $redirects[$key]['to'];

        if (self::redirect_key($target) === $key) {
            return;
        }

        // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- The target was checked to be on this site when it was saved.
        wp_safe_redirect($target, 301, 'NexoFlow');
        exit;
    }

    // ── The finished page ───────────────────────────────────────────────────────

    public function start_buffer()
    {
        if (!NexoFlow_Client::has_site_key() || is_feed() || is_robots() || is_trackback() || is_embed() || is_customize_preview()) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only reads whether a page builder is drawing this page in its editor.
        if (isset($_GET['elementor-preview']) || isset($_GET['preview'])) {
            return;
        }

        ob_start(array($this, 'finish_page'));
    }

    public function finish_page($html)
    {
        if (!is_string($html) || $html === '') {
            return $html;
        }

        foreach (headers_list() as $header) {
            if (stripos($header, 'content-type:') === 0 && stripos($header, 'text/html') === false) {
                return $html;
            }
        }

        $html = NexoFlow_SEO_Markup::ensure_viewport($html);
        $html = NexoFlow_SEO_Markup::ensure_language($html, (string) get_bloginfo('language'));
        $html = NexoFlow_SEO_Markup::ensure_title($html, (string) wp_get_document_title());
        $post_id = self::current_id();

        if ($post_id > 0 && (string) get_post_meta($post_id, self::META_HEADINGS, true) === '1') {
            $html = NexoFlow_SEO_Markup::fix_headings($html);
        }

        return $html;
    }

    /** A picture placed before its description was written still gets the description. */
    public function image_alt($tag, $context, $attachment_id)
    {
        if ((int) $attachment_id <= 0 || !is_string($tag) || preg_match('/\balt\s*=\s*["\'][^"\']+["\']/i', $tag)) {
            return $tag;
        }

        $alt = trim((string) get_post_meta((int) $attachment_id, '_wp_attachment_image_alt', true));

        if ($alt === '') {
            return $tag;
        }

        $tag = preg_replace('/\salt\s*=\s*(""|\'\')/i', '', $tag);

        return preg_replace('/^<img\b/i', '<img alt="' . esc_attr($alt) . '"', (string) $tag, 1);
    }

    /** The reply script opens the form in place, so the link needs no second address for the same page. */
    public function reply_link($link)
    {
        if (!NexoFlow_Client::has_site_key() || !is_string($link)) {
            return $link;
        }

        return preg_replace('#href=([\'"])[^\'"]*\?replytocom=\d+(?:\#[^\'"]*)?\1#', 'href=$1#respond$1', $link);
    }

    // ── AIOSEO ──────────────────────────────────────────────────────────────────

    public function aioseo_title($title)
    {
        $post_id = self::current_id();
        $custom = $post_id > 0 ? (string) get_post_meta($post_id, self::META_TITLE, true) : '';

        return $custom !== '' ? $custom : $title;
    }

    public function aioseo_description($description)
    {
        $post_id = self::current_id();
        $custom = $post_id > 0 ? (string) get_post_meta($post_id, self::META_DESCRIPTION, true) : '';

        return $custom !== '' ? $custom : $description;
    }

    public function aioseo_canonical($url)
    {
        $post_id = self::current_id();
        $custom = $post_id > 0 ? (string) get_post_meta($post_id, self::META_CANONICAL, true) : '';

        return $custom !== '' ? $custom : $url;
    }

    public function aioseo_robots($robots)
    {
        $post_id = self::current_id();

        if ($post_id > 0 && is_array($robots) && (string) get_post_meta($post_id, self::META_NOINDEX, true) === '1') {
            unset($robots['index']);
            $robots['noindex'] = 'noindex';
        }

        return $robots;
    }
}
