<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Block theme templates, and the template parts they share (header, footer).
 * A block theme can draw the homepage from a template instead of a page. A
 * template or part every visitor sees is always live content.
 *
 * One that was never customized only exists as a file in the theme. Changing
 * it works the way the Site Editor does: the theme's own content is stored as
 * a post first, and the change is made to that post. The theme file itself is
 * never written.
 */
class NexoFlow_Agent_Templates
{
    const POST_TYPE = 'wp_template';

    const PART_TYPE = 'wp_template_part';

    const AREA_TAXONOMY = 'wp_template_part_area';

    const ID_PATTERN = '/^[A-Za-z0-9_\-]+\/\/[A-Za-z0-9_\-]+$/D';

    const FRONT_SLUGS = array('front-page', 'home', 'index');

    const MAX_LISTED = 50;

    private $documents;

    private $type;

    public function __construct($documents, $type = self::POST_TYPE)
    {
        $this->documents = $documents;
        $this->type = $type === self::PART_TYPE ? self::PART_TYPE : self::POST_TYPE;
    }

    public function is_parts()
    {
        return $this->type === self::PART_TYPE;
    }

    public function supported()
    {
        return function_exists('get_block_template')
            && function_exists('get_block_templates')
            && function_exists('wp_is_block_theme')
            && wp_is_block_theme();
    }

    /**
     * The template that draws the homepage, or '' when the homepage is a page's own content.
     */
    public function front()
    {
        if (!$this->supported() || $this->is_parts()) {
            return '';
        }

        // With a static front page only a front-page template takes over from the page.
        $static = get_option('show_on_front') === 'page' && (int) get_option('page_on_front') > 0;
        $slugs = $static ? array('front-page') : self::FRONT_SLUGS;
        $found = array();

        foreach ((array) get_block_templates(array('slug__in' => $slugs), self::POST_TYPE) as $template) {
            if (is_object($template) && isset($template->slug, $template->id)) {
                $found[$template->slug] = (string) $template->id;
            }
        }

        foreach ($slugs as $slug) {
            if (isset($found[$slug])) {
                return $found[$slug];
            }
        }

        return '';
    }

    /**
     * The header, footer and other parts of the active theme, for the site context.
     */
    public function listed()
    {
        $listed = array();

        if (!$this->supported()) {
            return $listed;
        }

        foreach ((array) get_block_templates(array(), $this->type) as $template) {
            if (!is_object($template) || empty($template->id) || count($listed) >= self::MAX_LISTED) {
                continue;
            }

            $listed[] = array(
                'id' => (string) $template->id,
                'slug' => (string) $template->slug,
                'area' => $this->area($template),
                'title' => isset($template->title) ? (string) $template->title : '',
            );
        }

        return $listed;
    }

    /**
     * @return WP_Block_Template|NexoFlow_Agent_Error
     */
    public function find($id)
    {
        $template = $this->supported() ? get_block_template((string) $id, $this->type) : null;

        if (!is_object($template) || empty($template->id) || (isset($template->status) && $template->status === 'trash')) {
            return new NexoFlow_Agent_Error(
                NexoFlow_Agent_Error::NOT_FOUND,
                $this->is_parts()
                    ? __('That template part does not exist on this site.', 'nexoflow')
                    : __('That template does not exist on this site.', 'nexoflow'),
                array('template' => (string) $id)
            );
        }

        return $template;
    }

    public function revision($template)
    {
        $parts = array(
            (string) $template->id,
            isset($template->wp_id) ? (int) $template->wp_id : 0,
            md5((string) $template->content),
        );

        return substr(hash('sha256', wp_json_encode($parts)), 0, 16);
    }

    public function summary($template)
    {
        $post_id = isset($template->wp_id) ? (int) $template->wp_id : 0;

        $summary = array(
            'template' => (string) $template->id,
            'kind' => $this->is_parts() ? 'part' : 'template',
            'slug' => (string) $template->slug,
            'theme' => (string) $template->theme,
            'title' => isset($template->title) ? (string) $template->title : '',
            'customized' => $post_id > 0,
            'post_id' => $post_id > 0 ? $post_id : null,
            'revision' => $this->revision($template),
        );

        if ($this->is_parts()) {
            $summary['area'] = $this->area($template);
        }

        return $summary;
    }

    private function area($template)
    {
        return isset($template->area) && is_string($template->area) && $template->area !== ''
            ? $template->area
            : 'uncategorized';
    }

    public function live_edits()
    {
        $live = $this->documents->live_edits();

        if ($live['available'] && !post_type_supports($this->type, 'revisions')) {
            return array(
                'available' => false,
                'reason' => 'REVISIONS_DISABLED',
                'message' => __('This site does not keep earlier versions of templates, so a template cannot be changed safely.', 'nexoflow'),
            );
        }

        return $live;
    }

    /**
     * @return true|NexoFlow_Agent_Error
     */
    public function check_write($template, $expected_revision)
    {
        $live = $this->live_edits();

        if (!$live['available']) {
            return new NexoFlow_Agent_Error(
                $live['reason'] === 'REVISIONS_DISABLED'
                    ? NexoFlow_Agent_Error::RESTORE_POINT_UNAVAILABLE
                    : NexoFlow_Agent_Error::CAPABILITY_UNAVAILABLE,
                $live['message'],
                array('template' => (string) $template->id, 'reason' => $live['reason'])
            );
        }

        $current = $this->revision($template);

        if (!hash_equals($current, (string) $expected_revision)) {
            return new NexoFlow_Agent_Error(
                NexoFlow_Agent_Error::CONTENT_CONFLICT,
                __('This template changed after it was read. Read it again before changing it.', 'nexoflow'),
                array('template' => (string) $template->id, 'current_revision' => $current)
            );
        }

        return true;
    }

    /**
     * Saves new content to a template that already passed check_write().
     * A template is never changed without a restore point.
     *
     * @return array|NexoFlow_Agent_Error
     */
    public function write($template, $content)
    {
        $before = $this->revision($template);
        $created = empty($template->wp_id);
        $post = $created ? $this->create_post($template) : get_post((int) $template->wp_id);

        if (!$post || $post->post_type !== $this->type) {
            return $this->failed();
        }

        $restore = $this->documents->restore_point($post);

        if ($restore <= 0) {
            // Leave no trace: the site keeps drawing this template from the theme.
            if ($created) {
                wp_delete_post((int) $post->ID, true);
            }

            return new NexoFlow_Agent_Error(
                NexoFlow_Agent_Error::RESTORE_POINT_UNAVAILABLE,
                __('WordPress could not keep the current version of this template, so it was not changed.', 'nexoflow'),
                array('template' => (string) $template->id)
            );
        }

        $result = wp_update_post(
            wp_slash(
                array(
                    'ID' => (int) $post->ID,
                    'post_content' => $content,
                )
            ),
            true
        );

        if (is_wp_error($result) || !$result) {
            return $this->failed();
        }

        return $this->after($template, $before, $restore);
    }

    /**
     * @return array|NexoFlow_Agent_Error
     */
    public function restore($template, $revision_id)
    {
        $post = empty($template->wp_id) ? null : get_post((int) $template->wp_id);
        $revision = wp_get_post_revision($revision_id);

        if (!$post || !$revision || (int) $revision->post_parent !== (int) $post->ID || wp_is_post_autosave($revision)) {
            return new NexoFlow_Agent_Error(
                NexoFlow_Agent_Error::NOT_FOUND,
                __('That version does not exist for this template.', 'nexoflow'),
                array('template' => (string) $template->id, 'revision_id' => (int) $revision_id)
            );
        }

        $before = $this->revision($template);
        $restore = $this->documents->restore_point($post);

        if ($restore <= 0) {
            return new NexoFlow_Agent_Error(
                NexoFlow_Agent_Error::RESTORE_POINT_UNAVAILABLE,
                __('WordPress could not keep the current version of this template, so it was not changed.', 'nexoflow'),
                array('template' => (string) $template->id)
            );
        }

        $restored = wp_restore_post_revision((int) $revision_id);

        if (!$restored || is_wp_error($restored)) {
            return $this->failed();
        }

        $page = $this->after($template, $before, $restore);

        if (is_array($page)) {
            $page['restored_revision_id'] = (int) $revision_id;
        }

        return $page;
    }

    private function after($template, $before, $restore)
    {
        $updated = $this->find($template->id);

        if ($updated instanceof NexoFlow_Agent_Error) {
            return $this->failed();
        }

        $summary = $this->summary($updated);
        $summary['previous_revision'] = $before;
        $summary['restore_revision_id'] = (int) $restore;

        return $summary;
    }

    // The same fields the Site Editor stores the first time a theme template is saved.
    private function create_post($template)
    {
        $post_id = wp_insert_post(
            wp_slash(
                array(
                    'post_type' => $this->type,
                    'post_status' => 'publish',
                    'post_name' => (string) $template->slug,
                    'post_title' => isset($template->title) ? (string) $template->title : (string) $template->slug,
                    'post_excerpt' => isset($template->description) ? (string) $template->description : '',
                    'post_content' => (string) $template->content,
                    'tax_input' => array('wp_theme' => (string) $template->theme),
                    'meta_input' => array('origin' => isset($template->source) ? (string) $template->source : 'theme'),
                )
            ),
            true
        );

        if (is_wp_error($post_id) || !$post_id) {
            return null;
        }

        wp_set_post_terms((int) $post_id, array((string) $template->theme), 'wp_theme');

        // WordPress finds a stored part's place in the page (header, footer) by this term.
        if ($this->is_parts()) {
            wp_set_post_terms((int) $post_id, array($this->area($template)), self::AREA_TAXONOMY);
        }

        return get_post((int) $post_id);
    }

    private function failed()
    {
        return new NexoFlow_Agent_Error(
            NexoFlow_Agent_Error::EXECUTION_FAILED,
            __('WordPress could not save the template.', 'nexoflow')
        );
    }
}
