<?php

if (!defined('ABSPATH')) {
    exit;
}

class NexoFlow_Agent_Provider_WordPress implements NexoFlow_Agent_Provider
{
    const ID = 'wordpress';

    const REQUEST_META = '_nexoflow_agent_request';

    const LIST_STATUSES = array('draft', 'pending', 'publish', 'future', 'private');

    const MAX_CONTENT_LENGTH = 1000000;

    private $documents;

    private $site;

    private $templates;

    private $parts;

    public function __construct($documents, $site, $templates, $parts)
    {
        $this->documents = $documents;
        $this->site = $site;
        $this->templates = $templates;
        $this->parts = $parts;
    }

    public function id()
    {
        return self::ID;
    }

    public function capabilities()
    {
        $type = get_post_type_object(NexoFlow_Agent_Documents::POST_TYPE);
        $pages = is_object($type) && isset($type->cap);
        $edit = $pages && isset($type->cap->edit_posts) ? (string) $type->cap->edit_posts : 'edit_pages';
        $create = $pages && isset($type->cap->create_posts) ? (string) $type->cap->create_posts : $edit;
        $missing = array(
            'reason' => 'PAGES_UNAVAILABLE',
            'message' => __('Pages are not available on this site.', 'nexoflow'),
        );

        return array(
            'site.read' => array(
                'supported' => true,
                'available' => true,
                'requires' => 'read',
            ),
            'pages.read' => array(
                'supported' => $pages,
                'available' => $pages,
                'requires' => $edit,
            ) + $missing,
            'pages.create' => array(
                'supported' => $pages,
                'available' => $pages,
                'requires' => $create,
                'writes' => true,
            ) + $missing,
            'pages.update' => array(
                'supported' => $pages,
                'available' => $pages,
                'requires' => $edit,
                'writes' => true,
            ) + $missing,
            'pages.restore' => array(
                'supported' => $pages,
                'available' => $pages && $this->documents->keeps_revisions(),
                'requires' => $edit,
                'writes' => true,
                'reason' => $pages ? 'REVISIONS_DISABLED' : $missing['reason'],
                'message' => $pages
                    ? __('This site does not keep earlier versions of pages.', 'nexoflow')
                    : $missing['message'],
            ),
            // Which page is the homepage is a setting of the whole site, so it asks for more than editing pages.
            'site.front_page' => array(
                'supported' => $pages,
                'available' => $pages,
                'requires' => 'manage_options',
                'writes' => true,
            ) + $missing,
        );
    }

    public function actions()
    {
        $fields = array(
            'title' => array(
                'type' => 'string',
                'min_length' => 1,
                'max_length' => 200,
                'description' => 'Page title, plain text.',
            ),
            'content' => array(
                'type' => 'string',
                'max_length' => self::MAX_CONTENT_LENGTH,
                'description' => 'Page content as block markup or HTML. Scripts and unsafe markup are removed.',
            ),
            'excerpt' => array(
                'type' => 'string',
                'max_length' => 1000,
                'description' => 'Short summary, plain text.',
            ),
            'slug' => array(
                'type' => 'string',
                'min_length' => 1,
                'max_length' => 200,
                'description' => 'URL slug.',
            ),
        );
        $page_id = array(
            'type' => 'integer',
            'required' => true,
            'min' => 1,
            'description' => 'WordPress page ID.',
        );
        $expected_revision = array(
            'type' => 'string',
            'required' => true,
            'pattern' => NexoFlow_Agent_Documents::REVISION_PATTERN,
            'description' => 'The revision returned when the page was last read.',
        );

        return array(
            'site.read' => array(
                'capability' => 'site.read',
                'description' => 'Read the site, its theme, its post types and the plugins NexoFlow works with.',
            ),
            'pages.list' => array(
                'capability' => 'pages.read',
                'description' => 'List pages the acting account can edit, newest change first.',
                'parameters' => array(
                    'status' => array(
                        'type' => 'string',
                        'enum' => array_merge(array('any'), self::LIST_STATUSES),
                        'default' => 'any',
                        'description' => 'Only pages with this status.',
                    ),
                    'search' => array(
                        'type' => 'string',
                        'max_length' => 200,
                        'description' => 'Words to look for in the title and content.',
                    ),
                    'page' => array(
                        'type' => 'integer',
                        'min' => 1,
                        'default' => 1,
                    ),
                    'per_page' => array(
                        'type' => 'integer',
                        'min' => 1,
                        'max' => 100,
                        'default' => 20,
                    ),
                ),
            ),
            'pages.get' => array(
                'capability' => 'pages.read',
                'description' => 'Read one page, with its builder and the revision to send back when changing it.',
                'parameters' => array(
                    'page_id' => $page_id,
                    'include_content' => array(
                        'type' => 'boolean',
                        'default' => true,
                        'description' => 'Include the stored content.',
                    ),
                ),
            ),
            'pages.create' => array(
                'capability' => 'pages.create',
                'mutates' => true,
                'description' => 'Create a page as a draft. Nothing is published.',
                'parameters' => array(
                    'title' => array('required' => true) + $fields['title'],
                    'content' => $fields['content'],
                    'excerpt' => $fields['excerpt'],
                    'slug' => $fields['slug'],
                    'parent_id' => array(
                        'type' => 'integer',
                        'min' => 1,
                        'description' => 'ID of the parent page.',
                    ),
                ),
            ),
            'pages.update' => array(
                'capability' => 'pages.update',
                'mutates' => true,
                'modes' => array(NexoFlow_Agent_Validator::MODE_DRAFT, NexoFlow_Agent_Validator::MODE_LIVE),
                'description' => 'Change a page. A draft is changed in mode draft. A published page needs mode live: visitors see the change at once, the previous version is kept and can be restored, and its slug cannot be changed. Refused when the page has changed since it was read.',
                'any_of' => array('title', 'content', 'excerpt', 'slug'),
                'parameters' => array(
                    'page_id' => $page_id,
                    'expected_revision' => $expected_revision,
                ) + $fields,
            ),
            'pages.restore' => array(
                'capability' => 'pages.restore',
                'mutates' => true,
                'modes' => array(NexoFlow_Agent_Validator::MODE_DRAFT, NexoFlow_Agent_Validator::MODE_LIVE),
                'description' => 'Put a page back to an earlier version, for example the restore_revision_id returned by a change. A published page needs mode live.',
                'parameters' => array(
                    'page_id' => $page_id,
                    'expected_revision' => $expected_revision,
                    'revision_id' => array(
                        'type' => 'integer',
                        'required' => true,
                        'min' => 1,
                        'description' => 'ID of the WordPress revision to restore.',
                    ),
                ),
            ),
            'site.set_front_page' => array(
                'capability' => 'site.front_page',
                'mutates' => true,
                'modes' => array(NexoFlow_Agent_Validator::MODE_LIVE),
                'description' => 'Choose the page visitors see as the homepage, or go back to showing the latest posts. Visitors see it at once. The answer says what the homepage was before, so it can be put back.',
                'parameters' => array(
                    'page_id' => array(
                        'type' => 'integer',
                        'required' => true,
                        'min' => 0,
                        'description' => 'A published page to show as the homepage, or 0 to show the latest posts.',
                    ),
                ),
            ),
        );
    }

    public function authorize($action, $params)
    {
        switch ($action) {
            case 'site.set_front_page':
                return (int) $params['page_id'] > 0 ? $this->documents->authorize_edit($params['page_id']) : true;

            case 'site.read':
            case 'pages.list':
                return true;

            case 'pages.get':
            case 'pages.update':
            case 'pages.restore':
                return $this->documents->authorize_edit($params['page_id']);

            case 'pages.create':
                if (isset($params['parent_id'])) {
                    $parent = $this->documents->page($params['parent_id']);

                    if ($parent instanceof NexoFlow_Agent_Error) {
                        return $parent;
                    }
                }

                return true;
        }

        return $this->refused();
    }

    public function execute($action, $params, $context)
    {
        switch ($action) {
            case 'site.read':
                return $this->site_context();

            case 'pages.list':
                return $this->list_pages($params);

            case 'pages.get':
                return $this->get_page($params);

            case 'pages.create':
                return $this->create_page($params, $context);

            case 'pages.update':
                return $this->update_page($params, $context);

            case 'pages.restore':
                return $this->restore_page($params, $context);

            case 'site.set_front_page':
                // What every visitor lands on: the site's switch for live changes covers it too.
                $live = $this->documents->live_edits();

                if (!$live['available'] && $live['reason'] === 'LIVE_EDITS_DISABLED') {
                    return new NexoFlow_Agent_Error(
                        NexoFlow_Agent_Error::CAPABILITY_UNAVAILABLE,
                        $live['message'],
                        array('reason' => 'LIVE_EDITS_DISABLED')
                    );
                }

                return $this->set_front_page($params);
        }

        return $this->refused();
    }

    private function set_front_page($params)
    {
        $page_id = (int) $params['page_id'];
        $previous = $this->front_page();

        if ($page_id > 0) {
            $post = $this->documents->page($page_id);

            if ($post instanceof NexoFlow_Agent_Error) {
                return $post;
            }

            // A draft as the homepage would show visitors an empty site.
            if ($post->post_status !== 'publish') {
                return new NexoFlow_Agent_Error(
                    NexoFlow_Agent_Error::INVALID_PARAMETERS,
                    __('Only a published page can be the homepage.', 'nexoflow'),
                    array('fields' => array('page_id' => 'not_published'), 'page_id' => $page_id)
                );
            }

            update_option('show_on_front', 'page');
            update_option('page_on_front', $page_id);
        } else {
            update_option('show_on_front', 'posts');
            update_option('page_on_front', 0);
        }

        return array(
            'front_page' => $this->front_page(),
            'previous' => array(
                'show' => $previous['show'],
                'page_id' => $previous['page_id'],
            ),
            'url' => home_url('/'),
        );
    }

    public function site_context()
    {
        $theme = wp_get_theme();
        $types = array();

        foreach (get_post_types(array('public' => true), 'objects') as $type) {
            if (!is_object($type) || $type->name === 'attachment') {
                continue;
            }

            $types[] = array(
                'name' => (string) $type->name,
                'label' => (string) $type->label,
                'hierarchical' => !empty($type->hierarchical),
                'editor' => post_type_supports($type->name, 'editor'),
                'revisions' => post_type_supports($type->name, 'revisions'),
            );
        }

        $home = home_url('/');

        return array(
            'site' => array(
                'id' => $this->site->id(),
                'name' => (string) get_bloginfo('name'),
                'url' => $home,
                'https' => strpos($home, 'https://') === 0,
                'language' => (string) get_bloginfo('language'),
                'timezone' => function_exists('wp_timezone_string') ? wp_timezone_string() : '',
                'multisite' => is_multisite(),
                'front_page' => $this->front_page(),
            ),
            'wordpress' => array(
                'version' => (string) get_bloginfo('version'),
                'php_version' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION,
            ),
            'theme' => array(
                'name' => (string) $theme->get('Name'),
                'version' => (string) $theme->get('Version'),
                'stylesheet' => (string) $theme->get_stylesheet(),
                'template' => (string) $theme->get_template(),
                'block_theme' => function_exists('wp_is_block_theme') ? (bool) wp_is_block_theme() : false,
                // The header, footer and other parts every page of a block theme shares.
                'template_parts' => $this->parts->listed(),
            ),
            'post_types' => $types,
            'integrations' => array(
                'seo' => NexoFlow_SEO::other_plugins(),
                'woocommerce' => array(
                    'active' => class_exists('WooCommerce'),
                    'version' => defined('WC_VERSION') ? (string) WC_VERSION : null,
                ),
            ),
        );
    }

    // A block theme can draw the homepage from a template instead of, or around, a page.
    private function front_page()
    {
        $page_id = get_option('show_on_front') === 'page' ? (int) get_option('page_on_front') : 0;
        $template = $this->templates->front();

        // A page its builder draws from top to bottom is the homepage itself: the theme's
        // homepage template is not what visitors see.
        if ($page_id > 0 && $template !== '') {
            $post = get_post($page_id);
            $builder = $post ? $this->documents->builder_provider($post) : null;

            if ($builder && method_exists($builder, 'draws_whole_page') && $builder->draws_whole_page($post)) {
                $template = '';
            }
        }

        return array(
            'show' => $page_id > 0 ? 'page' : 'posts',
            'page_id' => $page_id > 0 ? $page_id : null,
            'template' => $template !== '' ? $template : null,
        );
    }

    private function list_pages($params)
    {
        $args = array(
            'post_type' => NexoFlow_Agent_Documents::POST_TYPE,
            'post_status' => $params['status'] === 'any' ? self::LIST_STATUSES : array($params['status']),
            'posts_per_page' => (int) $params['per_page'],
            'paged' => (int) $params['page'],
            // The ID settles pages changed in the same second, so paging is stable.
            'orderby' => array(
                'modified' => 'DESC',
                'ID' => 'DESC',
            ),
            'ignore_sticky_posts' => true,
        );

        if (isset($params['search']) && trim($params['search']) !== '') {
            $args['s'] = sanitize_text_field($params['search']);
        }

        if (!current_user_can('edit_others_pages')) {
            $args['author'] = (int) get_current_user_id();
        }

        $query = new WP_Query($args);
        $pages = array();

        foreach ($query->posts as $post) {
            if (is_object($post) && current_user_can('edit_post', $post->ID)) {
                $pages[] = $this->documents->summary($post);
            }
        }

        return array(
            'pages' => $pages,
            'total' => (int) $query->found_posts,
            'total_pages' => (int) $query->max_num_pages,
            'page' => (int) $params['page'],
            'per_page' => (int) $params['per_page'],
        );
    }

    private function get_page($params)
    {
        $post = $this->documents->page($params['page_id']);

        if ($post instanceof NexoFlow_Agent_Error) {
            return $post;
        }

        $page = $this->documents->summary($post);
        $page['excerpt'] = (string) $post->post_excerpt;

        if (!empty($params['include_content'])) {
            $page['content'] = (string) $post->post_content;
        }

        return $page;
    }

    private function create_page($params, $context)
    {
        $postarr = $this->post_fields($params);

        if ($postarr instanceof NexoFlow_Agent_Error) {
            return $postarr;
        }

        $postarr['post_type'] = NexoFlow_Agent_Documents::POST_TYPE;
        $postarr['post_status'] = 'draft';

        if (isset($params['parent_id'])) {
            $postarr['post_parent'] = (int) $params['parent_id'];
        }

        $post_id = wp_insert_post(wp_slash($postarr), true);

        if (is_wp_error($post_id) || !$post_id) {
            return $this->failed(__('WordPress could not create the page.', 'nexoflow'));
        }

        if (isset($context['request_id'])) {
            update_post_meta((int) $post_id, self::REQUEST_META, $context['request_id']);
        }

        $post = get_post((int) $post_id);

        if (!$post) {
            return $this->failed(__('WordPress could not create the page.', 'nexoflow'));
        }

        return $this->documents->summary($post);
    }

    private function update_page($params, $context)
    {
        $post = $this->documents->page($params['page_id']);

        if ($post instanceof NexoFlow_Agent_Error) {
            return $post;
        }

        $allowed = $this->documents->check_write($post, $params['expected_revision'], $context['mode']);

        if ($allowed !== true) {
            return $allowed;
        }

        // WordPress does not redirect the old address of a page, so a new slug would break its links.
        if (isset($params['slug']) && !$this->documents->is_draft($post)) {
            return new NexoFlow_Agent_Error(
                NexoFlow_Agent_Error::INVALID_PARAMETERS,
                __('The slug of a published page cannot be changed.', 'nexoflow'),
                array('fields' => array('slug' => 'published'))
            );
        }

        if (isset($params['content'])) {
            $builder = $this->documents->builder_provider($post);

            if ($builder && !$builder->allows_content_edit()) {
                return new NexoFlow_Agent_Error(
                    NexoFlow_Agent_Error::UNSUPPORTED_BUILDER_OPERATION,
                    __('This page is laid out by a page builder. Its content can only be changed through that builder.', 'nexoflow'),
                    array('page_id' => (int) $post->ID, 'builder' => $builder->id())
                );
            }
        }

        $postarr = $this->post_fields($params);

        if ($postarr instanceof NexoFlow_Agent_Error) {
            return $postarr;
        }

        return $this->documents->write($post, $postarr);
    }

    private function restore_page($params, $context)
    {
        $post = $this->documents->page($params['page_id']);

        if ($post instanceof NexoFlow_Agent_Error) {
            return $post;
        }

        $allowed = $this->documents->check_write($post, $params['expected_revision'], $context['mode']);

        if ($allowed !== true) {
            return $allowed;
        }

        $revision_id = (int) $params['revision_id'];
        $revision = wp_get_post_revision($revision_id);

        if (!$revision || (int) $revision->post_parent !== (int) $post->ID || wp_is_post_autosave($revision)) {
            return new NexoFlow_Agent_Error(
                NexoFlow_Agent_Error::NOT_FOUND,
                __('That version does not exist for this page.', 'nexoflow'),
                array('page_id' => (int) $post->ID, 'revision_id' => $revision_id)
            );
        }

        $before = $this->documents->revision($post);
        $builder = $this->documents->builder_provider($post);
        // What a builder that keeps its own data wants kept across the restore.
        $kept = $builder && method_exists($builder, 'before_restore') ? $builder->before_restore($post) : array();
        $restore = $this->documents->restore_point($post);

        if ($restore <= 0 && !$this->documents->is_draft($post)) {
            return new NexoFlow_Agent_Error(
                NexoFlow_Agent_Error::RESTORE_POINT_UNAVAILABLE,
                __('WordPress could not keep the current version of this page, so it was not changed.', 'nexoflow'),
                array('page_id' => (int) $post->ID)
            );
        }

        $restored = wp_restore_post_revision($revision_id);
        $updated = $restored && !is_wp_error($restored) ? get_post((int) $post->ID) : null;

        if (!$updated) {
            return $this->failed(__('WordPress could not restore that version.', 'nexoflow'));
        }

        // A builder that keeps its own data may have a rendered copy of the page to drop.
        if ($builder && method_exists($builder, 'after_restore')) {
            $builder->after_restore($updated, $kept);
        }

        $page = $this->documents->summary($updated);
        $page['previous_revision'] = $before;
        $page['restored_revision_id'] = $revision_id;

        if ($restore > 0) {
            $page['restore_revision_id'] = $restore;
        }

        return $page;
    }

    // Everything the request can set on a post. Status, type, author, dates and meta are never taken from it.
    private function post_fields($params)
    {
        $postarr = array();
        $empty = array();

        if (isset($params['title'])) {
            $postarr['post_title'] = sanitize_text_field($params['title']);

            if ($postarr['post_title'] === '') {
                $empty['title'] = 'empty';
            }
        }

        if (isset($params['slug'])) {
            $postarr['post_name'] = sanitize_title($params['slug']);

            if ($postarr['post_name'] === '') {
                $empty['slug'] = 'empty';
            }
        }

        if (isset($params['content'])) {
            $postarr['post_content'] = wp_kses_post($params['content']);
        }

        if (isset($params['excerpt'])) {
            $postarr['post_excerpt'] = sanitize_textarea_field($params['excerpt']);
        }

        if (!empty($empty)) {
            return new NexoFlow_Agent_Error(
                NexoFlow_Agent_Error::INVALID_PARAMETERS,
                __('One or more parameters are missing or not valid.', 'nexoflow'),
                array('fields' => $empty)
            );
        }

        return $postarr;
    }

    private function failed($message)
    {
        return new NexoFlow_Agent_Error(NexoFlow_Agent_Error::EXECUTION_FAILED, $message);
    }

    private function refused()
    {
        return new NexoFlow_Agent_Error(
            NexoFlow_Agent_Error::UNKNOWN_ACTION,
            __('That action is not available.', 'nexoflow')
        );
    }
}
