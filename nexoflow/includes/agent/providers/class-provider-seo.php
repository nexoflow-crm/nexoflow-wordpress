<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What a site audit asks to fix and a page builder cannot: each page's search
 * listing, what search engines may show, leftover pages, addresses that are gone,
 * descriptions of pictures and the speed of the site. Every change answers with
 * how things were, so it can be put back.
 */
class NexoFlow_Agent_Provider_SEO implements NexoFlow_Agent_Provider
{
    const ID = 'seo';

    const MAX_TITLE = 120;

    const MAX_DESCRIPTION = 320;

    const SAMPLE_PAGES = array(
        array('type' => 'post', 'slug' => 'hello-world', 'words' => 'Welcome to WordPress'),
        array('type' => 'page', 'slug' => 'sample-page', 'words' => 'This is an example page'),
    );

    private $documents;

    public function __construct($documents)
    {
        $this->documents = $documents;
    }

    public function id()
    {
        return self::ID;
    }

    public function capabilities()
    {
        $always = array('supported' => true, 'available' => true);
        $cache = NexoFlow_Page_Cache::status();

        return array(
            'seo.read' => array('requires' => 'edit_posts') + $always,
            'seo.page' => array('requires' => 'edit_posts', 'writes' => true) + $always,
            'seo.site' => array('requires' => 'manage_options', 'writes' => true) + $always,
            'content.trash' => array('requires' => 'edit_posts', 'writes' => true) + $always,
            'redirects.manage' => array('requires' => 'manage_options', 'writes' => true) + $always,
            'media.describe' => array('requires' => 'upload_files', 'writes' => true) + $always,
            'speed.cache' => array(
                'supported' => true,
                'available' => $cache['available'],
                'requires' => 'manage_options',
                'writes' => true,
                'reason' => 'OTHER_CACHE_ACTIVE',
                'message' => __('Another caching plugin already looks after this site.', 'nexoflow'),
            ),
        );
    }

    public function actions()
    {
        $live = array(NexoFlow_Agent_Validator::MODE_LIVE);
        $either = array(NexoFlow_Agent_Validator::MODE_DRAFT, NexoFlow_Agent_Validator::MODE_LIVE);
        $page_id = array('type' => 'integer', 'min' => 1, 'description' => 'WordPress ID of the page or post.');
        $address = array('type' => 'string', 'min_length' => 1, 'max_length' => 2000, 'description' => 'The address of the page or post on this site.');

        return array(
            'seo.status' => array(
                'capability' => 'seo.read',
                'description' => 'Read how this site stands for search: who prints its tags, whether search engines are allowed in, its settings, leftover sample pages and whether a page cache is on.',
            ),
            'seo.pages' => array(
                'capability' => 'seo.read',
                'description' => 'List published pages and posts with their search title, description and settings, newest change first.',
                'parameters' => array(
                    'post_type' => array('type' => 'string', 'enum' => array('any', 'page', 'post'), 'default' => 'any', 'description' => 'Only this kind.'),
                    'page' => array('type' => 'integer', 'min' => 1, 'default' => 1),
                    'per_page' => array('type' => 'integer', 'min' => 1, 'max' => 100, 'default' => 50),
                ),
            ),
            'seo.get_page' => array(
                'capability' => 'seo.read',
                'description' => 'Read one page or post by ID or address: its search title, description and settings, and what would be shown for it now.',
                'any_of' => array('page_id', 'url'),
                'parameters' => array('page_id' => $page_id, 'url' => $address),
            ),
            'seo.update_page' => array(
                'capability' => 'seo.page',
                'mutates' => true,
                'modes' => $either,
                'description' => 'Change a page or post\'s search title, description, canonical address, search visibility, shared picture or main heading fix. An empty text or false clears a field. A published page needs mode live. The answer has the fields as they were, so they can be put back.',
                'any_of' => array('title', 'description', 'canonical', 'noindex', 'nofollow', 'image_id', 'fix_headings', 'page_title'),
                'parameters' => array(
                    'page_id' => array('required' => true) + $page_id,
                    'title' => array('type' => 'string', 'max_length' => self::MAX_TITLE, 'description' => 'What the search result\'s title should say.'),
                    'description' => array('type' => 'string', 'max_length' => self::MAX_DESCRIPTION, 'description' => 'The text under the title in a search result.'),
                    'canonical' => array('type' => 'string', 'max_length' => 2000, 'description' => 'The address search engines should treat as this page.'),
                    'noindex' => array('type' => 'boolean', 'description' => 'Hide this page from search results and the sitemap.'),
                    'nofollow' => array('type' => 'boolean', 'description' => 'Ask search engines not to follow links on this page.'),
                    'image_id' => array('type' => 'integer', 'min' => 0, 'description' => 'Media library ID of the picture a shared link shows. 0 clears it.'),
                    'fix_headings' => array('type' => 'boolean', 'description' => 'Give this page exactly one main heading as it is shown: the first h2 becomes it when there is none, and extra ones become h2.'),
                    'page_title' => array('type' => 'string', 'min_length' => 1, 'max_length' => 200, 'description' => 'The page\'s own title in WordPress.'),
                ),
            ),
            'seo.update_site' => array(
                'capability' => 'seo.site',
                'mutates' => true,
                'modes' => $live,
                'description' => 'Change settings for the whole site: whether search engines may index it, which archive pages are hidden from search, the default shared picture and the homepage listing when the homepage is the latest posts. The answer has the settings as they were.',
                'any_of' => array('search_visible', 'printing', 'noindex_author', 'noindex_date', 'noindex_tag', 'noindex_category', 'noindex_term_ids', 'default_image_id', 'home_title', 'home_description'),
                'parameters' => array(
                    'search_visible' => array('type' => 'boolean', 'description' => 'Whether search engines may index this site.'),
                    'printing' => array('type' => 'boolean', 'description' => 'Whether NexoFlow prints this site\'s search tags.'),
                    'noindex_author' => array('type' => 'boolean', 'description' => 'Hide author pages from search and the sitemap.'),
                    'noindex_date' => array('type' => 'boolean', 'description' => 'Hide date archives from search.'),
                    'noindex_tag' => array('type' => 'boolean', 'description' => 'Hide tag pages from search and the sitemap.'),
                    'noindex_category' => array('type' => 'boolean', 'description' => 'Hide every category page from search and the sitemap.'),
                    'noindex_term_ids' => array(
                        'type' => 'list',
                        'max_items' => 50,
                        'items' => array(
                            'term_id' => array('type' => 'integer', 'required' => true, 'min' => 1, 'description' => 'ID of the category or tag.'),
                        ),
                        'description' => 'Single categories or tags to hide, such as the default one. Replaces the list.',
                    ),
                    'default_image_id' => array('type' => 'integer', 'min' => 0, 'description' => 'Media library ID of the picture shared links show when a page has none.'),
                    'home_title' => array('type' => 'string', 'max_length' => self::MAX_TITLE, 'description' => 'Search title of the homepage when it is the latest posts.'),
                    'home_description' => array('type' => 'string', 'max_length' => self::MAX_DESCRIPTION, 'description' => 'Search description of the homepage when it is the latest posts.'),
                ),
            ),
            'content.trash' => array(
                'capability' => 'content.trash',
                'mutates' => true,
                'modes' => $either,
                'description' => 'Move a page or post to the Trash, where it stays until someone empties it. The homepage, the blog page, the privacy page and a page with pages under it are refused. A published page needs mode live. Name an address to send visitors of the old address there.',
                'parameters' => array(
                    'page_id' => array('required' => true) + $page_id,
                    'redirect_to' => array('type' => 'string', 'max_length' => 2000, 'description' => 'Where the old address should now lead, on this site.'),
                ),
            ),
            'content.restore' => array(
                'capability' => 'content.trash',
                'mutates' => true,
                'modes' => $either,
                'description' => 'Bring a page or post back from the Trash with the status it had, and remove the redirect made when it was trashed.',
                'parameters' => array('page_id' => array('required' => true) + $page_id),
            ),
            'redirects.list' => array(
                'capability' => 'redirects.manage',
                'description' => 'List the addresses this site sends on, and where.',
            ),
            'redirects.set' => array(
                'capability' => 'redirects.manage',
                'mutates' => true,
                'modes' => $live,
                'description' => 'Send an address that no longer exists on to another address on this site, permanently. A page that exists is never redirected. The answer says where it led before, if anywhere.',
                'parameters' => array(
                    'from' => array('required' => true) + $address,
                    'to' => array('required' => true) + $address,
                ),
            ),
            'redirects.remove' => array(
                'capability' => 'redirects.manage',
                'mutates' => true,
                'modes' => $live,
                'description' => 'Stop sending an address on.',
                'parameters' => array('from' => array('required' => true) + $address),
            ),
            'media.missing_alt' => array(
                'capability' => 'media.describe',
                'description' => 'List images in the media library with no description, newest first.',
                'parameters' => array(
                    'page' => array('type' => 'integer', 'min' => 1, 'default' => 1),
                    'per_page' => array('type' => 'integer', 'min' => 1, 'max' => 100, 'default' => 50),
                ),
            ),
            'media.set_alt' => array(
                'capability' => 'media.describe',
                'mutates' => true,
                'modes' => $either,
                'description' => 'Write what an image shows, for search engines and people who cannot see it. The answer has the description as it was.',
                'parameters' => array(
                    'image_id' => array('type' => 'integer', 'required' => true, 'min' => 1, 'description' => 'Media library ID.'),
                    'alt' => array('type' => 'string', 'required' => true, 'max_length' => 300, 'description' => 'What the image shows, plain text.'),
                ),
            ),
            'speed.set_cache' => array(
                'capability' => 'speed.cache',
                'mutates' => true,
                'modes' => $live,
                'description' => 'Switch the page cache for visitors who are not signed in on or off. Refused when another caching plugin looks after the site.',
                'parameters' => array('enabled' => array('type' => 'boolean', 'required' => true, 'description' => 'On or off.')),
            ),
        );
    }

    public function authorize($action, $params)
    {
        switch ($action) {
            case 'seo.update_page':
            case 'seo.get_page':
            case 'content.trash':
            case 'content.restore':
                $post = $this->target($params, $action === 'content.restore');

                if ($post instanceof NexoFlow_Agent_Error) {
                    return $post;
                }

                $capability = $action === 'content.trash' || $action === 'content.restore' ? 'delete_post' : 'edit_post';

                if (!current_user_can($capability, $post->ID)) {
                    return new NexoFlow_Agent_Error(
                        NexoFlow_Agent_Error::INSUFFICIENT_PERMISSION,
                        __('The acting WordPress account is not allowed to do this.', 'nexoflow'),
                        array('page_id' => (int) $post->ID)
                    );
                }

                return true;

            case 'media.set_alt':
                $image = get_post((int) $params['image_id']);

                if (!$image || $image->post_type !== 'attachment') {
                    return new NexoFlow_Agent_Error(
                        NexoFlow_Agent_Error::NOT_FOUND,
                        __('That image is not in the media library.', 'nexoflow'),
                        array('image_id' => (int) $params['image_id'])
                    );
                }

                return current_user_can('edit_post', $image->ID) ? true : new NexoFlow_Agent_Error(
                    NexoFlow_Agent_Error::INSUFFICIENT_PERMISSION,
                    __('The acting WordPress account is not allowed to do this.', 'nexoflow'),
                    array('image_id' => (int) $image->ID)
                );
        }

        return true;
    }

    public function execute($action, $params, $context)
    {
        switch ($action) {
            case 'seo.status':
                return $this->status();
            case 'seo.pages':
                return $this->pages($params);
            case 'seo.get_page':
                return $this->read_page($this->target($params));
            case 'seo.update_page':
                return $this->update_page($params, $context);
            case 'seo.update_site':
                return $this->update_site($params);
            case 'content.trash':
                return $this->trash($params, $context);
            case 'content.restore':
                return $this->restore($params);
            case 'redirects.list':
                return $this->list_redirects();
            case 'redirects.set':
                return $this->set_redirect($params);
            case 'redirects.remove':
                return $this->remove_redirect($params);
            case 'media.missing_alt':
                return $this->missing_alt($params);
            case 'media.set_alt':
                return $this->set_alt($params);
            case 'speed.set_cache':
                return $this->set_cache($params);
        }

        return new NexoFlow_Agent_Error(NexoFlow_Agent_Error::UNKNOWN_ACTION, __('That action is not available.', 'nexoflow'));
    }

    /**
     * The page or post named by ID or address.
     *
     * @return WP_Post|NexoFlow_Agent_Error
     */
    private function target($params, $in_trash = false)
    {
        $post_id = isset($params['page_id']) ? (int) $params['page_id'] : 0;

        if ($post_id <= 0 && isset($params['url'])) {
            $post_id = $this->id_from_address((string) $params['url']);
        }

        $post = $post_id > 0 ? get_post($post_id) : null;
        $missing = new NexoFlow_Agent_Error(
            NexoFlow_Agent_Error::NOT_FOUND,
            __('That page does not exist on this site.', 'nexoflow'),
            array('page_id' => $post_id)
        );

        if (!$post || !is_post_type_viewable($post->post_type) || $post->post_type === 'attachment') {
            return $missing;
        }

        if (($post->post_status === 'trash') !== (bool) $in_trash) {
            return $missing;
        }

        return $post;
    }

    private function id_from_address($address)
    {
        $key = NexoFlow_SEO::redirect_key($address);

        if ($key === '') {
            return 0;
        }

        $home = NexoFlow_SEO::redirect_key(home_url('/'));

        if ($key === $home || $key === '/') {
            return get_option('show_on_front') === 'page' ? (int) get_option('page_on_front') : 0;
        }

        $absolute = strpos($address, '/') === 0 ? home_url($address) : $address;

        return (int) url_to_postid($absolute);
    }

    private function describe($post)
    {
        if ($post instanceof NexoFlow_Agent_Error) {
            return $post;
        }

        $values = NexoFlow_SEO::page_values($post->ID);
        $site = (string) get_bloginfo('name');
        $own_title = (string) get_the_title($post);
        $text = (string) $post->post_excerpt !== '' ? (string) $post->post_excerpt : strip_shortcodes((string) $post->post_content);

        return array(
            'page_id' => (int) $post->ID,
            'type' => (string) $post->post_type,
            'status' => (string) $post->post_status,
            'url' => (string) get_permalink($post),
            'page_title' => $own_title,
            'seo' => $values,
            // What a search engine would be given now, with nothing else changed.
            'shown' => array(
                'title' => $values['title'] !== '' ? $values['title'] : ((int) $post->ID === (int) get_option('page_on_front') ? $site : $own_title . ' – ' . $site),
                'description' => $values['description'] !== '' ? $values['description'] : NexoFlow_SEO_Markup::describe($text),
            ),
            'builder' => $this->documents->builder($post),
            'is_front_page' => (int) $post->ID === (int) get_option('page_on_front'),
            'modified_gmt' => $this->documents->modified_gmt($post),
        );
    }

    private function read_page($post)
    {
        if ($post instanceof NexoFlow_Agent_Error) {
            return $post;
        }

        return $this->describe($post) + array('content' => (string) $post->post_content);
    }

    private function status()
    {
        $settings = NexoFlow_SEO::settings();
        $sitemap = function_exists('get_sitemap_url') ? get_sitemap_url('index') : '';

        return array(
            'tags_by' => NexoFlow_SEO::printing() ? 'nexoflow' : (empty(NexoFlow_SEO::other_plugins()) ? 'none' : NexoFlow_SEO::other_plugins()[0]),
            'other_seo_plugins' => NexoFlow_SEO::other_plugins(),
            'search_visible' => (string) get_option('blog_public') !== '0',
            'https' => strpos(home_url('/'), 'https://') === 0,
            'pretty_addresses' => (string) get_option('permalink_structure') !== '',
            'sitemap_url' => is_string($sitemap) ? $sitemap : '',
            'settings' => $settings,
            'leftovers' => $this->leftovers(),
            'page_cache' => NexoFlow_Page_Cache::status(),
            'redirects' => count(NexoFlow_SEO::redirects()),
        );
    }

    /** What a new WordPress comes with: nobody wrote these for this business. */
    private function leftovers()
    {
        $found = array();

        foreach (self::SAMPLE_PAGES as $sample) {
            $post = get_page_by_path($sample['slug'], OBJECT, $sample['type']);

            if ($post && $post->post_status === 'publish' && strpos((string) $post->post_content, $sample['words']) !== false) {
                $found[] = array('page_id' => (int) $post->ID, 'type' => $sample['type'], 'title' => (string) $post->post_title, 'url' => (string) get_permalink($post));
            }
        }

        $default = (int) get_option('default_category');
        $term = $default > 0 ? get_term($default, 'category') : null;

        return array(
            'pages' => $found,
            'default_category' => is_object($term) && !is_wp_error($term) && (string) $term->slug === 'uncategorized'
                ? array('term_id' => (int) $term->term_id, 'name' => (string) $term->name, 'url' => (string) get_term_link($term))
                : null,
            'authors' => count(get_users(array('has_published_posts' => array('post'), 'fields' => 'ID', 'number' => 3))),
        );
    }

    private function pages($params)
    {
        $query = new WP_Query(
            array(
                'post_type' => $params['post_type'] === 'any' ? array('page', 'post') : array($params['post_type']),
                'post_status' => 'publish',
                'posts_per_page' => (int) $params['per_page'],
                'paged' => (int) $params['page'],
                'orderby' => array('modified' => 'DESC', 'ID' => 'DESC'),
                'ignore_sticky_posts' => true,
            )
        );
        $pages = array();

        foreach ($query->posts as $post) {
            if (is_object($post) && current_user_can('edit_post', $post->ID)) {
                $pages[] = $this->describe($post);
            }
        }

        return array(
            'pages' => $pages,
            'total' => (int) $query->found_posts,
            'total_pages' => (int) $query->max_num_pages,
            'page' => (int) $params['page'],
        );
    }

    private function live_required($post, $context)
    {
        if ($this->documents->is_draft($post) || $context['mode'] === NexoFlow_Agent_Validator::MODE_LIVE) {
            return true;
        }

        return new NexoFlow_Agent_Error(
            NexoFlow_Agent_Error::DRAFT_REQUIRED,
            __('This page is not a draft. Send mode live to change a published page.', 'nexoflow'),
            array('page_id' => (int) $post->ID, 'status' => (string) $post->post_status)
        );
    }

    private function update_page($params, $context)
    {
        $post = $this->target($params);

        if ($post instanceof NexoFlow_Agent_Error) {
            return $post;
        }

        $allowed = $this->live_required($post, $context);

        if ($allowed !== true) {
            return $allowed;
        }

        $fields = array();

        if (isset($params['title'])) {
            $fields['title'] = sanitize_text_field($params['title']);
        }

        if (isset($params['description'])) {
            $fields['description'] = sanitize_textarea_field($params['description']);
        }

        if (isset($params['canonical'])) {
            $fields['canonical'] = trim($params['canonical']) === '' ? '' : esc_url_raw(trim($params['canonical']), array('http', 'https'));

            if (trim($params['canonical']) !== '' && $fields['canonical'] === '') {
                return new NexoFlow_Agent_Error(
                    NexoFlow_Agent_Error::INVALID_PARAMETERS,
                    __('The canonical address is not a web address.', 'nexoflow'),
                    array('fields' => array('canonical' => 'format'))
                );
            }
        }

        foreach (array('noindex', 'nofollow', 'fix_headings') as $flag) {
            if (isset($params[$flag])) {
                $fields[$flag] = (bool) $params[$flag];
            }
        }

        if (isset($params['image_id'])) {
            if ((int) $params['image_id'] > 0 && !wp_attachment_is_image((int) $params['image_id'])) {
                return new NexoFlow_Agent_Error(
                    NexoFlow_Agent_Error::INVALID_PARAMETERS,
                    __('That image is not in the media library.', 'nexoflow'),
                    array('fields' => array('image_id' => 'not_found'))
                );
            }

            $fields['image_id'] = (int) $params['image_id'];
        }

        $previous = array();

        if (isset($params['page_title'])) {
            $title = sanitize_text_field($params['page_title']);

            if ($title === '') {
                return new NexoFlow_Agent_Error(
                    NexoFlow_Agent_Error::INVALID_PARAMETERS,
                    __('One or more parameters are missing or not valid.', 'nexoflow'),
                    array('fields' => array('page_title' => 'empty'))
                );
            }

            $previous['page_title'] = (string) $post->post_title;
            $restore = $this->documents->is_draft($post) ? 0 : $this->documents->restore_point($post);
            $result = wp_update_post(wp_slash(array('ID' => (int) $post->ID, 'post_title' => $title)), true);

            if (is_wp_error($result) || !$result) {
                return new NexoFlow_Agent_Error(NexoFlow_Agent_Error::EXECUTION_FAILED, __('WordPress could not save the page.', 'nexoflow'));
            }

            if ($restore > 0) {
                $previous['restore_revision_id'] = $restore;
            }
        }

        $previous += NexoFlow_SEO::save_page($post->ID, $fields);

        $answer = $this->describe(get_post((int) $post->ID));
        $answer['previous'] = $previous;

        return $answer;
    }

    private function update_site($params)
    {
        $previous = array();

        if (isset($params['search_visible'])) {
            $previous['search_visible'] = (string) get_option('blog_public') !== '0';
            update_option('blog_public', $params['search_visible'] ? '1' : '0');
        }

        $changes = array();

        foreach (array('printing', 'noindex_author', 'noindex_date', 'noindex_tag', 'noindex_category') as $flag) {
            if (isset($params[$flag])) {
                $changes[$flag] = (bool) $params[$flag];
            }
        }

        if (isset($params['noindex_term_ids'])) {
            $changes['noindex_term_ids'] = array_values(array_unique(array_map('intval', array_column((array) $params['noindex_term_ids'], 'term_id'))));
        }

        if (isset($params['default_image_id'])) {
            if ((int) $params['default_image_id'] > 0 && !wp_attachment_is_image((int) $params['default_image_id'])) {
                return new NexoFlow_Agent_Error(
                    NexoFlow_Agent_Error::INVALID_PARAMETERS,
                    __('That image is not in the media library.', 'nexoflow'),
                    array('fields' => array('default_image_id' => 'not_found'))
                );
            }

            $changes['default_image_id'] = (int) $params['default_image_id'];
        }

        if (isset($params['home_title'])) {
            $changes['home_title'] = sanitize_text_field($params['home_title']);
        }

        if (isset($params['home_description'])) {
            $changes['home_description'] = sanitize_textarea_field($params['home_description']);
        }

        if (!empty($changes)) {
            $previous += array_intersect_key(NexoFlow_SEO::save_settings($changes), $changes);
        }

        do_action('nexoflow_seo_changed', 0);

        return array('previous' => $previous, 'status' => $this->status());
    }

    private function refusal_to_trash($post)
    {
        $id = (int) $post->ID;
        $kept = array(
            (int) get_option('page_on_front') => __('This page is the homepage, so it cannot be moved to the Trash.', 'nexoflow'),
            (int) get_option('page_for_posts') => __('This page shows the blog, so it cannot be moved to the Trash.', 'nexoflow'),
            (int) get_option('wp_page_for_privacy_policy') => __('This page is the privacy policy, so it cannot be moved to the Trash.', 'nexoflow'),
        );

        if ($id > 0 && isset($kept[$id])) {
            return $kept[$id];
        }

        if ($post->post_type === 'page' && !empty(get_children(array('post_parent' => $id, 'post_type' => 'page', 'numberposts' => 1, 'fields' => 'ids')))) {
            return __('This page has pages under it, so it cannot be moved to the Trash.', 'nexoflow');
        }

        return '';
    }

    private function trash($params, $context)
    {
        $post = $this->target($params);

        if ($post instanceof NexoFlow_Agent_Error) {
            return $post;
        }

        $allowed = $this->live_required($post, $context);

        if ($allowed !== true) {
            return $allowed;
        }

        $refused = $this->refusal_to_trash($post);

        if ($refused !== '') {
            return new NexoFlow_Agent_Error(NexoFlow_Agent_Error::INVALID_PARAMETERS, $refused, array('page_id' => (int) $post->ID));
        }

        $target = '';

        if (isset($params['redirect_to']) && trim($params['redirect_to']) !== '') {
            $target = $this->same_site_address($params['redirect_to']);

            if ($target === '') {
                return new NexoFlow_Agent_Error(
                    NexoFlow_Agent_Error::INVALID_PARAMETERS,
                    __('The new address must be on this site.', 'nexoflow'),
                    array('fields' => array('redirect_to' => 'not_on_this_site'))
                );
            }
        }

        $status = (string) $post->post_status;
        $url = (string) get_permalink($post);
        $trashed = wp_trash_post((int) $post->ID);

        if (!$trashed) {
            return new NexoFlow_Agent_Error(NexoFlow_Agent_Error::EXECUTION_FAILED, __('WordPress could not move this to the Trash.', 'nexoflow'));
        }

        $answer = array(
            'page_id' => (int) $post->ID,
            'title' => (string) $post->post_title,
            'url' => $url,
            'previous_status' => $status,
            'status' => 'trash',
        );

        if ($target !== '') {
            $key = NexoFlow_SEO::redirect_key($url);
            $redirects = NexoFlow_SEO::redirects();
            $redirects[$key] = array('to' => $target, 'trashed_id' => (int) $post->ID, 'created' => gmdate('c'));
            NexoFlow_SEO::save_redirects($redirects);
            $answer['redirect_to'] = $target;
        }

        return $answer;
    }

    private function restore($params)
    {
        $post = $this->target($params, true);

        if ($post instanceof NexoFlow_Agent_Error) {
            return $post;
        }

        // WordPress would bring it back as a draft.
        add_filter('wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10, 3);
        $restored = wp_untrash_post((int) $post->ID);
        remove_filter('wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10);

        if (!$restored) {
            return new NexoFlow_Agent_Error(NexoFlow_Agent_Error::EXECUTION_FAILED, __('WordPress could not bring this back from the Trash.', 'nexoflow'));
        }

        $redirects = NexoFlow_SEO::redirects();
        $kept = array();

        foreach ($redirects as $from => $redirect) {
            if (!isset($redirect['trashed_id']) || (int) $redirect['trashed_id'] !== (int) $post->ID) {
                $kept[$from] = $redirect;
            }
        }

        if (count($kept) !== count($redirects)) {
            NexoFlow_SEO::save_redirects($kept);
        }

        $updated = get_post((int) $post->ID);

        return array(
            'page_id' => (int) $post->ID,
            'title' => (string) $updated->post_title,
            'status' => (string) $updated->post_status,
            'url' => (string) get_permalink($updated),
        );
    }

    /** The address as a full one on this site, or an empty string when it is elsewhere. */
    private function same_site_address($address)
    {
        $address = trim((string) $address);

        if (NexoFlow_SEO::redirect_key($address) === '') {
            return '';
        }

        return strpos($address, '/') === 0 && strpos($address, '//') !== 0 ? home_url($address) : esc_url_raw($address, array('http', 'https'));
    }

    private function list_redirects()
    {
        $list = array();

        foreach (NexoFlow_SEO::redirects() as $from => $redirect) {
            $list[] = array('from' => (string) $from, 'to' => isset($redirect['to']) ? (string) $redirect['to'] : '');
        }

        return array('redirects' => $list, 'limit' => NexoFlow_SEO::MAX_REDIRECTS);
    }

    private function set_redirect($params)
    {
        $from = NexoFlow_SEO::redirect_key($params['from']);
        $to = $this->same_site_address($params['to']);

        if ($from === '' || $to === '' || $from === '/') {
            return new NexoFlow_Agent_Error(
                NexoFlow_Agent_Error::INVALID_PARAMETERS,
                __('Both addresses must be on this site, and the homepage cannot be sent elsewhere.', 'nexoflow'),
                array('fields' => array('from' => $from === '' || $from === '/' ? 'not_allowed' : 'ok', 'to' => $to === '' ? 'not_on_this_site' : 'ok'))
            );
        }

        $redirects = NexoFlow_SEO::redirects();
        $to_key = NexoFlow_SEO::redirect_key($to);

        // Following the chain from the new target must not lead back here.
        $seen = array($from => true);
        $next = $to_key;

        while (isset($redirects[$next]['to']) && !isset($seen[$next])) {
            $seen[$next] = true;
            $next = NexoFlow_SEO::redirect_key((string) $redirects[$next]['to']);
        }

        if (isset($seen[$next]) || $to_key === $from) {
            return new NexoFlow_Agent_Error(
                NexoFlow_Agent_Error::INVALID_PARAMETERS,
                __('That redirect would send visitors in a circle.', 'nexoflow'),
                array('fields' => array('to' => 'loop'))
            );
        }

        if (!isset($redirects[$from]) && count($redirects) >= NexoFlow_SEO::MAX_REDIRECTS) {
            return new NexoFlow_Agent_Error(
                NexoFlow_Agent_Error::INVALID_PARAMETERS,
                __('This site has as many redirects as NexoFlow keeps.', 'nexoflow'),
                array('limit' => NexoFlow_SEO::MAX_REDIRECTS)
            );
        }

        $previous = isset($redirects[$from]['to']) ? (string) $redirects[$from]['to'] : null;
        $redirects[$from] = array('to' => $to, 'created' => gmdate('c'));
        NexoFlow_SEO::save_redirects($redirects);

        return array('from' => $from, 'to' => $to, 'previous' => $previous);
    }

    private function remove_redirect($params)
    {
        $from = NexoFlow_SEO::redirect_key($params['from']);
        $redirects = NexoFlow_SEO::redirects();

        if ($from === '' || !isset($redirects[$from])) {
            return new NexoFlow_Agent_Error(
                NexoFlow_Agent_Error::NOT_FOUND,
                __('That address is not being sent anywhere.', 'nexoflow'),
                array('from' => (string) $params['from'])
            );
        }

        $previous = (string) $redirects[$from]['to'];
        unset($redirects[$from]);
        NexoFlow_SEO::save_redirects($redirects);

        return array('from' => $from, 'previous' => $previous);
    }

    private function missing_alt($params)
    {
        // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Read on request by NexoFlow, a page at a time.
        $query = new WP_Query(
            array(
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'post_mime_type' => 'image',
                'posts_per_page' => (int) $params['per_page'],
                'paged' => (int) $params['page'],
                'orderby' => array('date' => 'DESC', 'ID' => 'DESC'),
                'meta_query' => array(
                    'relation' => 'OR',
                    array('key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS'),
                    array('key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '='),
                ),
            )
        );
        // phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query
        $images = array();

        foreach ($query->posts as $image) {
            if (is_object($image)) {
                $images[] = array(
                    'image_id' => (int) $image->ID,
                    'title' => (string) $image->post_title,
                    'url' => (string) wp_get_attachment_url($image->ID),
                    'used_on' => (int) $image->post_parent > 0 ? (string) get_permalink((int) $image->post_parent) : null,
                );
            }
        }

        return array('images' => $images, 'total' => (int) $query->found_posts, 'page' => (int) $params['page']);
    }

    private function set_alt($params)
    {
        $id = (int) $params['image_id'];
        $previous = (string) get_post_meta($id, '_wp_attachment_image_alt', true);
        $alt = sanitize_text_field($params['alt']);

        if ($alt === '') {
            delete_post_meta($id, '_wp_attachment_image_alt');
        } else {
            update_post_meta($id, '_wp_attachment_image_alt', $alt);
        }

        do_action('nexoflow_seo_changed', $id);

        return array('image_id' => $id, 'alt' => $alt, 'previous' => $previous);
    }

    private function set_cache($params)
    {
        $before = NexoFlow_Page_Cache::status();
        NexoFlow_Page_Cache::set_enabled((bool) $params['enabled']);

        return array('page_cache' => NexoFlow_Page_Cache::status(), 'previous' => $before['enabled']);
    }
}
