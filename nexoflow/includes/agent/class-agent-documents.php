<?php

if (!defined('ABSPATH')) {
    exit;
}

class NexoFlow_Agent_Documents
{
    const POST_TYPE = 'page';

    const DRAFT_STATUSES = array('draft', 'auto-draft', 'pending');

    const BUILDER_CLASSIC = 'classic';

    const LOCK_WINDOW = 150;

    const REVISION_PATTERN = '/^[0-9a-f]{16}$/D';

    private $builders = array();

    public function set_builders($builders)
    {
        $this->builders = $builders;
    }

    /**
     * @return WP_Post|NexoFlow_Agent_Error
     */
    public function page($page_id)
    {
        $post = get_post((int) $page_id);

        if (!$post || $post->post_type !== self::POST_TYPE || $post->post_status === 'trash') {
            return new NexoFlow_Agent_Error(
                NexoFlow_Agent_Error::NOT_FOUND,
                __('That page does not exist on this site.', 'nexoflow'),
                array('page_id' => (int) $page_id)
            );
        }

        return $post;
    }

    /**
     * @return true|NexoFlow_Agent_Error
     */
    public function authorize_edit($page_id)
    {
        $post = $this->page($page_id);

        if ($post instanceof NexoFlow_Agent_Error) {
            return $post;
        }

        if (!current_user_can('edit_post', $post->ID)) {
            return new NexoFlow_Agent_Error(
                NexoFlow_Agent_Error::INSUFFICIENT_PERMISSION,
                __('The acting WordPress account cannot edit this page.', 'nexoflow'),
                array('page_id' => (int) $post->ID)
            );
        }

        return true;
    }

    public function is_draft($post)
    {
        return in_array($post->post_status, self::DRAFT_STATUSES, true);
    }

    public function builder_provider($post)
    {
        foreach ($this->builders as $builder) {
            if ($builder->owns($post)) {
                return $builder;
            }
        }

        return null;
    }

    public function builder($post)
    {
        $builder = $this->builder_provider($post);

        return $builder ? $builder->id() : self::BUILDER_CLASSIC;
    }

    /**
     * Changes whenever the page does. Sent back as expected_revision so a write
     * never lands on a page someone changed in the meantime.
     */
    public function revision($post)
    {
        $builder = $this->builder_provider($post);
        $parts = array(
            (int) $post->ID,
            (string) $post->post_modified,
            (string) $post->post_status,
            (string) $post->post_title,
            (string) $post->post_name,
            (string) $post->post_excerpt,
            (int) $post->post_parent,
            md5((string) $post->post_content),
            $builder ? (string) $builder->document_version($post) : '',
        );

        return substr(hash('sha256', wp_json_encode($parts)), 0, 16);
    }

    public function wp_revision_id($post)
    {
        if (!function_exists('wp_get_latest_revision_id_and_total_count')) {
            return null;
        }

        $latest = wp_get_latest_revision_id_and_total_count($post->ID);

        if (is_wp_error($latest) || !is_array($latest) || empty($latest['latest_id'])) {
            return null;
        }

        return (int) $latest['latest_id'];
    }

    /**
     * The account that has this page open in the editor, including the acting account itself.
     */
    public function locked_by($post)
    {
        $lock = get_post_meta($post->ID, '_edit_lock', true);

        if (!is_string($lock) || $lock === '') {
            return 0;
        }

        $parts = explode(':', $lock);
        $time = (int) $parts[0];
        $user_id = isset($parts[1]) ? (int) $parts[1] : 0;
        /** This filter is documented in wp-admin/includes/ajax-actions.php */
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter, read so a lock ends here exactly when it ends in the editor.
        $window = (int) apply_filters('wp_check_post_lock_window', self::LOCK_WINDOW);

        if ($time <= 0 || $user_id <= 0 || $time < time() - $window) {
            return 0;
        }

        return $user_id;
    }

    // WordPress leaves the GMT time empty on a draft that has never been edited.
    public function modified_gmt($post)
    {
        $modified = (string) $post->post_modified_gmt;

        if ($modified === '' || $modified === '0000-00-00 00:00:00') {
            $local = (string) $post->post_modified;

            if ($local === '' || $local === '0000-00-00 00:00:00') {
                return null;
            }

            $modified = get_gmt_from_date($local);
        }

        return mysql_to_rfc3339($modified);
    }

    /**
     * Whether this site keeps earlier versions of pages, which a change to a published page needs.
     */
    public function keeps_revisions()
    {
        if (!post_type_supports(self::POST_TYPE, 'revisions')) {
            return false;
        }

        if (!defined('WP_POST_REVISIONS') || WP_POST_REVISIONS === true) {
            return true;
        }

        return (int) WP_POST_REVISIONS < 0 || (int) WP_POST_REVISIONS >= 2;
    }

    public function live_edits()
    {
        if (!(bool) apply_filters('nexoflow_agent_allow_live_edits', true)) {
            return array(
                'available' => false,
                'reason' => 'LIVE_EDITS_DISABLED',
                'message' => __('Changes to published pages are switched off on this site.', 'nexoflow'),
            );
        }

        if (!$this->keeps_revisions()) {
            return array(
                'available' => false,
                'reason' => 'REVISIONS_DISABLED',
                'message' => __('This site does not keep earlier versions of pages, so a published page cannot be changed safely.', 'nexoflow'),
            );
        }

        return array(
            'available' => true,
            'reason' => '',
            'message' => '',
        );
    }

    /**
     * Everything that must hold before a page is changed. A page visitors can see
     * is only ever changed when the request says mode live.
     *
     * @return true|NexoFlow_Agent_Error
     */
    public function check_write($post, $expected_revision, $mode)
    {
        if (!$this->is_draft($post)) {
            if ($mode !== NexoFlow_Agent_Validator::MODE_LIVE) {
                return new NexoFlow_Agent_Error(
                    NexoFlow_Agent_Error::DRAFT_REQUIRED,
                    __('This page is not a draft. Send mode live to change a published page.', 'nexoflow'),
                    array('page_id' => (int) $post->ID, 'status' => (string) $post->post_status)
                );
            }

            $live = $this->live_edits();

            if (!$live['available']) {
                return new NexoFlow_Agent_Error(
                    $live['reason'] === 'REVISIONS_DISABLED'
                        ? NexoFlow_Agent_Error::RESTORE_POINT_UNAVAILABLE
                        : NexoFlow_Agent_Error::CAPABILITY_UNAVAILABLE,
                    $live['message'],
                    array('page_id' => (int) $post->ID, 'reason' => $live['reason'])
                );
            }
        }

        $current = $this->revision($post);

        if (!hash_equals($current, (string) $expected_revision)) {
            return new NexoFlow_Agent_Error(
                NexoFlow_Agent_Error::CONTENT_CONFLICT,
                __('This page changed after it was read. Read it again before changing it.', 'nexoflow'),
                array('page_id' => (int) $post->ID, 'current_revision' => $current)
            );
        }

        if ($this->locked_by($post) > 0) {
            return new NexoFlow_Agent_Error(
                NexoFlow_Agent_Error::CONTENT_LOCKED,
                __('Someone has this page open in the editor. Try again once they are done.', 'nexoflow'),
                array('page_id' => (int) $post->ID)
            );
        }

        return true;
    }

    /**
     * What must hold before a change that visitors do not see yet: a draft, or a
     * change a page builder holds back until it is published. The page must be as
     * it was read and nobody may have it open; live mode is not asked for.
     *
     * @return true|NexoFlow_Agent_Error
     */
    public function check_staged($post, $expected_revision)
    {
        $current = $this->revision($post);

        if (!hash_equals($current, (string) $expected_revision)) {
            return new NexoFlow_Agent_Error(
                NexoFlow_Agent_Error::CONTENT_CONFLICT,
                __('This page changed after it was read. Read it again before changing it.', 'nexoflow'),
                array('page_id' => (int) $post->ID, 'current_revision' => $current)
            );
        }

        if ($this->locked_by($post) > 0) {
            return new NexoFlow_Agent_Error(
                NexoFlow_Agent_Error::CONTENT_LOCKED,
                __('Someone has this page open in the editor. Try again once they are done.', 'nexoflow'),
                array('page_id' => (int) $post->ID)
            );
        }

        return true;
    }

    /**
     * Keeps the page as it is now as a WordPress revision and returns its ID, or 0
     * when WordPress would not keep it.
     */
    public function restore_point($post)
    {
        if (!function_exists('wp_save_post_revision') || !function_exists('wp_revisions_to_keep')) {
            return 0;
        }

        if (!post_type_supports($post->post_type, 'revisions')) {
            return 0;
        }

        // With fewer than two kept, WordPress drops this one as soon as the change is saved. Autosaves count toward
        // the number kept but are never the ones dropped, so each one takes a place from the real revisions.
        $keep = (int) wp_revisions_to_keep($post);

        if ($keep === 0 || $keep === 1 || ($keep > 0 && $keep < 2 + $this->autosave_count($post))) {
            return 0;
        }

        // A page builder keeps the layout outside the post content, so WordPress may see nothing
        // new to keep. For those pages a revision is always taken; the builder copies its own
        // data into it.
        $builder = $this->builder_provider($post);
        $always = $builder && !$builder->allows_content_edit();

        if ($always) {
            add_filter('wp_save_post_revision_check_for_changes', '__return_false', 99);
        }

        $saved = wp_save_post_revision($post->ID);

        if ($always) {
            remove_filter('wp_save_post_revision_check_for_changes', '__return_false', 99);
        }

        if ($saved && !is_wp_error($saved)) {
            return (int) $saved;
        }

        // Nothing was saved because the latest revision already matches the page. That is the latest saved revision,
        // never an autosave: an autosave holds someone's unsaved edits, not the page as it is.
        $latest = $this->latest_saved_revision($post);

        return $latest ? $latest : 0;
    }

    /** The newest revision of a post that is not an autosave, or 0. */
    private function latest_saved_revision($post)
    {
        if (!function_exists('wp_get_post_revisions')) {
            return (int) $this->wp_revision_id($post);
        }

        foreach (wp_get_post_revisions($post->ID, array('check_enabled' => false)) as $revision) {
            if (!wp_is_post_autosave($revision)) {
                return (int) $revision->ID;
            }
        }

        return 0;
    }

    private function autosave_count($post)
    {
        if (!function_exists('wp_get_post_revisions')) {
            return 0;
        }

        $count = 0;

        foreach (wp_get_post_revisions($post->ID, array('check_enabled' => false)) as $revision) {
            if (wp_is_post_autosave($revision)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Saves fields to a page that already passed check_write(). A page that is not
     * a draft is never changed without a restore point.
     *
     * @return array|NexoFlow_Agent_Error
     */
    public function write($post, $fields)
    {
        $before = $this->revision($post);
        $restore = 0;

        if (!$this->is_draft($post)) {
            $restore = $this->restore_point($post);

            if ($restore <= 0) {
                return new NexoFlow_Agent_Error(
                    NexoFlow_Agent_Error::RESTORE_POINT_UNAVAILABLE,
                    __('WordPress could not keep the current version of this page, so it was not changed.', 'nexoflow'),
                    array('page_id' => (int) $post->ID)
                );
            }
        }

        $fields['ID'] = (int) $post->ID;
        $result = wp_update_post(wp_slash($fields), true);
        $updated = is_wp_error($result) || !$result ? null : get_post((int) $post->ID);

        if (!$updated) {
            return new NexoFlow_Agent_Error(
                NexoFlow_Agent_Error::EXECUTION_FAILED,
                __('WordPress could not save the page.', 'nexoflow')
            );
        }

        $page = $this->summary($updated);
        $page['previous_revision'] = $before;

        if ($restore > 0) {
            $page['restore_revision_id'] = $restore;
        }

        return $page;
    }

    public function summary($post)
    {
        $edit_url = get_edit_post_link($post->ID, 'raw');

        return array(
            'page_id' => (int) $post->ID,
            'title' => (string) $post->post_title,
            'slug' => (string) $post->post_name,
            'status' => (string) $post->post_status,
            'parent_id' => (int) $post->post_parent,
            'template' => (string) get_page_template_slug($post->ID),
            'url' => (string) get_permalink($post->ID),
            'edit_url' => is_string($edit_url) ? $edit_url : '',
            'modified_gmt' => $this->modified_gmt($post),
            'builder' => $this->builder($post),
            'revision' => $this->revision($post),
            'wp_revision_id' => $this->wp_revision_id($post),
        );
    }
}
