<?php

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

if (!defined('WP_PLUGIN_DIR')) {
    define('WP_PLUGIN_DIR', sys_get_temp_dir() . '/nexoflow-test-plugins-' . getmypid());
}

$GLOBALS['nexoflow_users'] = array();
$GLOBALS['nexoflow_current_user'] = 0;
$GLOBALS['nexoflow_filters'] = array();
$GLOBALS['nexoflow_abilities'] = array();
$GLOBALS['nexoflow_transients'] = array();
$GLOBALS['nexoflow_hooks'] = array();
$GLOBALS['nexoflow_routes'] = array();
$GLOBALS['nexoflow_revisions'] = array();
$GLOBALS['nexoflow_revision_posts'] = array();
$GLOBALS['nexoflow_next_revision_id'] = 5000;
$GLOBALS['nexoflow_revisions_to_keep'] = -1;
$GLOBALS['nexoflow_block_theme'] = false;
$GLOBALS['nexoflow_theme_templates'] = array();
$GLOBALS['nexoflow_patterns'] = array();
$GLOBALS['nexoflow_clock'] = '2026-10-05 10:00:00';
$GLOBALS['nexoflow_home'] = 'https://example.com';

const NEXOFLOW_TEST_ADMIN_CAPS = array(
    'read', 'edit_posts', 'publish_posts', 'upload_files', 'edit_pages', 'edit_others_pages',
    'edit_published_pages', 'publish_pages', 'delete_pages', 'edit_theme_options', 'manage_options',
    'activate_plugins', 'update_plugins',
);

const NEXOFLOW_TEST_EDITOR_CAPS = array(
    'read', 'edit_posts', 'publish_posts', 'upload_files', 'edit_pages', 'edit_others_pages',
    'edit_published_pages', 'publish_pages', 'delete_pages',
);

function nexoflow_test_user($id, $role, $caps, $name)
{
    $GLOBALS['nexoflow_users'][$id] = (object) array(
        'ID' => $id,
        'roles' => array($role),
        'caps' => $caps,
        'display_name' => $name,
    );
}

function nexoflow_tick($seconds = 1)
{
    $GLOBALS['nexoflow_clock'] = gmdate('Y-m-d H:i:s', strtotime($GLOBALS['nexoflow_clock'] . ' UTC') + $seconds);
}

function get_userdata($id)
{
    return isset($GLOBALS['nexoflow_users'][(int) $id]) ? $GLOBALS['nexoflow_users'][(int) $id] : false;
}

function get_users($args = array())
{
    $ids = array();

    foreach ($GLOBALS['nexoflow_users'] as $id => $user) {
        if (isset($args['role']) && !in_array($args['role'], $user->roles, true)) {
            continue;
        }

        // Like WordPress, a search on display_name matches the whole name.
        if (isset($args['search']) && strcasecmp((string) $user->display_name, (string) $args['search']) !== 0) {
            continue;
        }

        $ids[] = $id;
    }

    sort($ids);

    return isset($args['number']) ? array_slice($ids, 0, (int) $args['number']) : $ids;
}

function get_current_user_id()
{
    return (int) $GLOBALS['nexoflow_current_user'];
}

function wp_set_current_user($id)
{
    $GLOBALS['nexoflow_current_user'] = (int) $id;
}

function user_can($user, $capability, $object_id = 0)
{
    $data = get_userdata(is_object($user) ? $user->ID : $user);

    if (!$data) {
        return false;
    }

    if ($capability !== 'edit_post') {
        return in_array($capability, $data->caps, true);
    }

    // The page rules WordPress applies when it maps edit_post.
    $post = get_post($object_id);

    if (!$post || !in_array('edit_pages', $data->caps, true)) {
        return false;
    }

    if ((int) $post->post_author !== (int) $data->ID && !in_array('edit_others_pages', $data->caps, true)) {
        return false;
    }

    if (in_array($post->post_status, array('publish', 'future', 'private'), true)
        && !in_array('edit_published_pages', $data->caps, true)
    ) {
        return false;
    }

    return true;
}

function current_user_can($capability, $object_id = 0)
{
    return user_can(get_current_user_id(), $capability, $object_id);
}

function get_post($id)
{
    $id = is_object($id) ? (int) $id->ID : (int) $id;

    if (!isset($GLOBALS['nexoflow_posts'][$id])) {
        return null;
    }

    $post = $GLOBALS['nexoflow_posts'][$id] + array(
        'post_type' => 'post',
        'post_status' => 'draft',
        'post_title' => '',
        'post_name' => '',
        'post_content' => '',
        'post_excerpt' => '',
        'post_parent' => 0,
        'post_author' => 0,
        'post_modified' => '2026-10-05 10:00:00',
        'post_modified_gmt' => '0000-00-00 00:00:00',
    );

    return (object) $post;
}

function get_edit_post_link($id, $context = 'display')
{
    return current_user_can('edit_post', $id) ? 'https://example.com/wp-admin/post.php?post=' . (int) $id . '&action=edit' : null;
}

// Like WordPress: the page template on record, and nothing for the theme's default.
function get_page_template_slug($id)
{
    $template = get_post_meta((int) $id, '_wp_page_template', true);

    return is_string($template) && $template !== 'default' ? $template : '';
}

function get_post_status($id)
{
    return isset($GLOBALS['nexoflow_posts'][(int) $id]['post_status']) ? (string) $GLOBALS['nexoflow_posts'][(int) $id]['post_status'] : false;
}

function remove_filter($hook, $callback, $priority = 10)
{
    foreach (isset($GLOBALS['nexoflow_hooks'][$hook]) ? $GLOBALS['nexoflow_hooks'][$hook] : array() as $index => $registered) {
        if ($registered === $callback) {
            unset($GLOBALS['nexoflow_hooks'][$hook][$index]);
        }
    }

    return true;
}

function __return_false()
{
    return false;
}

function esc_url_raw($url)
{
    return (string) $url;
}

function mysql_to_rfc3339($date)
{
    return str_replace(' ', 'T', $date);
}

function get_gmt_from_date($date)
{
    return $date;
}

function wp_revisions_to_keep($post)
{
    return isset($GLOBALS['nexoflow_revisions_to_keep']) ? $GLOBALS['nexoflow_revisions_to_keep'] : -1;
}

// Like WordPress: nothing is saved when the latest revision already matches the page.
function wp_save_post_revision($post_id)
{
    $post = get_post($post_id);

    if (!$post || wp_revisions_to_keep($post) === 0 || !post_type_supports($post->post_type, 'revisions')) {
        return null;
    }

    $latest_id = isset($GLOBALS['nexoflow_revisions'][(int) $post_id]) ? $GLOBALS['nexoflow_revisions'][(int) $post_id] : 0;
    $latest = wp_get_post_revision($latest_id);

    // Like WordPress: a plugin can ask for a revision even when the post itself did not change.
    $hooked = isset($GLOBALS['nexoflow_hooks']['wp_save_post_revision_check_for_changes']) ? $GLOBALS['nexoflow_hooks']['wp_save_post_revision_check_for_changes'] : array();
    $check = !in_array('__return_false', $hooked, true);

    if ($check
        && $latest
        && $latest->post_title === $post->post_title
        && $latest->post_content === $post->post_content
        && $latest->post_excerpt === $post->post_excerpt
    ) {
        return null;
    }

    $id = $GLOBALS['nexoflow_next_revision_id'];
    $GLOBALS['nexoflow_next_revision_id']++;
    $GLOBALS['nexoflow_revision_posts'][$id] = (object) array(
        'ID' => $id,
        'post_parent' => (int) $post_id,
        'post_name' => (int) $post_id . '-revision-v1',
        'post_title' => $post->post_title,
        'post_content' => $post->post_content,
        'post_excerpt' => $post->post_excerpt,
    );
    $GLOBALS['nexoflow_revisions'][(int) $post_id] = $id;

    return $id;
}

function wp_get_post_revision($id)
{
    $id = is_object($id) ? (int) $id->ID : (int) $id;

    return isset($GLOBALS['nexoflow_revision_posts'][$id]) ? $GLOBALS['nexoflow_revision_posts'][$id] : null;
}

if (!function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook($hook)
    {
        $GLOBALS['nexoflow_cleared_hooks'][] = $hook;

        return 0;
    }
}

// Like WordPress: a post's revisions, autosaves included, newest first.
function wp_get_post_revisions($post_id, $args = array())
{
    $found = array();

    foreach ($GLOBALS['nexoflow_revision_posts'] as $id => $revision) {
        if ((int) $revision->post_parent === (int) (is_object($post_id) ? $post_id->ID : $post_id)) {
            $found[$id] = $revision;
        }
    }

    krsort($found);

    return $found;
}

function wp_is_post_autosave($post)
{
    $post = wp_get_post_revision($post);

    return $post && strpos($post->post_name, '-autosave') !== false ? (int) $post->post_parent : false;
}

function wp_restore_post_revision($id)
{
    $revision = wp_get_post_revision($id);

    if (!$revision || !empty($GLOBALS['nexoflow_restore_fails'])) {
        return null;
    }

    return wp_update_post(
        wp_slash(
            array(
                'ID' => $revision->post_parent,
                'post_title' => $revision->post_title,
                'post_content' => $revision->post_content,
                'post_excerpt' => $revision->post_excerpt,
            )
        )
    );
}

function wp_get_latest_revision_id_and_total_count($id)
{
    if (!isset($GLOBALS['nexoflow_revisions'][(int) $id])) {
        return new WP_Error('revisions_not_enabled', 'Revisions not enabled.');
    }

    return array(
        'latest_id' => $GLOBALS['nexoflow_revisions'][(int) $id],
        'count' => 1,
    );
}

function get_post_type_object($type)
{
    if ($type !== 'page' && $type !== 'post') {
        return null;
    }

    return (object) array(
        'name' => $type,
        'label' => $type === 'page' ? 'Pages' : 'Posts',
        'hierarchical' => $type === 'page',
        'show_in_rest' => true,
        'cap' => (object) array(
            'edit_posts' => $type === 'page' ? 'edit_pages' : 'edit_posts',
            'create_posts' => $type === 'page' ? 'edit_pages' : 'edit_posts',
        ),
    );
}

function get_post_types($args = array(), $output = 'names')
{
    return array(
        'post' => get_post_type_object('post'),
        'page' => get_post_type_object('page'),
        'attachment' => (object) array('name' => 'attachment', 'label' => 'Media', 'hierarchical' => false),
    );
}

function post_type_supports($type, $feature)
{
    if ($feature === 'revisions' && !empty($GLOBALS['nexoflow_no_revisions'])) {
        return false;
    }

    return $type === 'page' || $type === 'post' || (in_array($type, array('wp_template', 'wp_template_part'), true) && $feature === 'revisions');
}

function use_block_editor_for_post_type($type)
{
    return true;
}

class WP_Query
{
    public $posts = array();
    public $found_posts = 0;
    public $max_num_pages = 0;

    public function __construct($args)
    {
        $GLOBALS['nexoflow_last_query'] = $args;
        $matched = array();

        foreach (array_keys($GLOBALS['nexoflow_posts']) as $id) {
            $post = get_post($id);

            if ($post->post_type !== $args['post_type'] || !in_array($post->post_status, (array) $args['post_status'], true)) {
                continue;
            }

            if (isset($args['author']) && (int) $post->post_author !== (int) $args['author']) {
                continue;
            }

            if (isset($args['s']) && stripos($post->post_title . ' ' . $post->post_content, $args['s']) === false) {
                continue;
            }

            $matched[] = $post;
        }

        // Without the ID in orderby, WordPress returns same-second pages in no fixed order.
        $by_id = is_array($args['orderby']) && isset($args['orderby']['ID']);

        usort(
            $matched,
            function ($a, $b) use ($by_id) {
                $order = strcmp($b->post_modified, $a->post_modified);

                if ($order !== 0) {
                    return $order;
                }

                return $by_id ? $b->ID - $a->ID : $a->ID - $b->ID;
            }
        );

        $per_page = (int) $args['posts_per_page'];
        $this->found_posts = count($matched);
        $this->max_num_pages = (int) ceil($this->found_posts / $per_page);
        $this->posts = array_slice($matched, ((int) $args['paged'] - 1) * $per_page, $per_page);
    }
}

function has_blocks($content)
{
    return is_string($content) && strpos($content, '<!-- wp:') !== false;
}

// A small stand-in for the WordPress block parser and serializer. Like WordPress,
// innerContent holds the markup of a block with null where an inner block sits.
function parse_blocks($content)
{
    $offset = 0;
    $parsed = nexoflow_test_parse_blocks((string) $content, $offset, true);

    return $parsed[0];
}

function nexoflow_test_parse_blocks($content, &$offset, $top)
{
    $pattern = '/<!--\s+(\/)?wp:([a-z][a-z0-9_-]*\/)?([a-z][a-z0-9_-]*)\s+(\{.*?\}\s+)?(\/)?-->/s';
    $blocks = array();
    $chunks = array();

    while (preg_match($pattern, $content, $match, PREG_OFFSET_CAPTURE, $offset)) {
        $text = substr($content, $offset, $match[0][1] - $offset);

        if ($text !== '') {
            if ($top) {
                $blocks[] = nexoflow_test_block(null, array(), array(), array($text));
            } else {
                $chunks[] = $text;
            }
        }

        $offset = $match[0][1] + strlen($match[0][0]);

        if ($match[1][0] === '/') {
            return array($blocks, $chunks);
        }

        $name = ($match[2][0] !== '' ? $match[2][0] : 'core/') . $match[3][0];
        $attrs = isset($match[4]) && $match[4][0] !== '' ? json_decode(trim($match[4][0]), true) : array();

        if (isset($match[5]) && $match[5][0] === '/') {
            $blocks[] = nexoflow_test_block($name, $attrs, array(), array());
        } else {
            $inner = nexoflow_test_parse_blocks($content, $offset, false);
            $blocks[] = nexoflow_test_block($name, $attrs, $inner[0], $inner[1]);
        }

        if (!$top) {
            $chunks[] = null;
        }
    }

    $rest = substr($content, $offset);
    $offset = strlen($content);

    if ($rest !== '') {
        if ($top) {
            $blocks[] = nexoflow_test_block(null, array(), array(), array($rest));
        } else {
            $chunks[] = $rest;
        }
    }

    return array($blocks, $chunks);
}

function nexoflow_test_block($name, $attrs, $inner, $chunks)
{
    return array(
        'blockName' => $name,
        'attrs' => is_array($attrs) ? $attrs : array(),
        'innerBlocks' => $inner,
        'innerHTML' => implode('', array_filter($chunks, 'is_string')),
        'innerContent' => $chunks,
    );
}

function serialize_blocks($blocks)
{
    return implode('', array_map('serialize_block', $blocks));
}

function serialize_block($block)
{
    $content = '';
    $index = 0;

    foreach ($block['innerContent'] as $chunk) {
        $content .= is_string($chunk) ? $chunk : serialize_block($block['innerBlocks'][$index++]);
    }

    if ($block['blockName'] === null) {
        return $content;
    }

    $name = strpos($block['blockName'], 'core/') === 0 ? substr($block['blockName'], 5) : $block['blockName'];
    $attrs = '';

    if (!empty($block['attrs'])) {
        $attrs = str_replace(
            array('--', '<', '>', '&', '\\"'),
            array('\\u002d\\u002d', '\\u003c', '\\u003e', '\\u0026', '\\u0022'),
            json_encode($block['attrs'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        ) . ' ';
    }

    if ($content === '') {
        return sprintf('<!-- wp:%s %s/-->', $name, $attrs);
    }

    return sprintf('<!-- wp:%s %s-->%s<!-- /wp:%s -->', $name, $attrs, $content, $name);
}

class WP_Block_Patterns_Registry
{
    public static function get_instance()
    {
        return new self();
    }

    public function is_registered($slug)
    {
        return isset($GLOBALS['nexoflow_patterns'][$slug]);
    }

    public function get_registered($slug)
    {
        return array('name' => $slug, 'content' => $GLOBALS['nexoflow_patterns'][$slug]);
    }
}

class WP_Block_Type_Registry
{
    const CORE = array(
        'core/paragraph', 'core/heading', 'core/list', 'core/list-item', 'core/image', 'core/columns', 'core/column',
        'core/group', 'core/buttons', 'core/button', 'core/details', 'core/cover', 'core/separator', 'core/html',
        'core/site-title', 'core/navigation', 'core/navigation-link', 'core/template-part',
    );

    public static function get_instance()
    {
        return new self();
    }

    public function is_registered($name)
    {
        return in_array($name, self::CORE, true);
    }

    public function get_all_registered()
    {
        return array_fill_keys(self::CORE, true);
    }
}

function add_query_arg($args, $url)
{
    return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($args);
}

class NexoFlow_Test_Rest_Server
{
    public function get_routes()
    {
        return isset($GLOBALS['nexoflow_rest_routes']) ? $GLOBALS['nexoflow_rest_routes'] : array();
    }
}

function rest_get_server()
{
    return new NexoFlow_Test_Rest_Server();
}

function rest_url($path = '')
{
    return 'https://example.com/wp-json/' . ltrim((string) $path, '/');
}

function wp_create_nonce($action = -1)
{
    return 'nonce-for-' . $action;
}

function human_time_diff($from, $to = 0)
{
    $seconds = abs((int) ($to ?: time()) - (int) $from);

    return $seconds < 3600 ? max(1, (int) round($seconds / 60)) . ' mins' : (int) round($seconds / 3600) . ' hours';
}

function get_the_title($id)
{
    return isset($GLOBALS['nexoflow_posts'][(int) $id]['post_title']) ? (string) $GLOBALS['nexoflow_posts'][(int) $id]['post_title'] : '';
}

function esc_textarea($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function esc_attr__($text, $domain = null)
{
    return esc_attr($text);
}

function wp_strip_all_tags($text)
{
    return trim(strip_tags((string) $text));
}

// Like WordPress: tags that are not allowed are removed, their text is kept.
function wp_kses($content, $allowed)
{
    return strip_tags((string) $content, '<' . implode('><', array_keys((array) $allowed)) . '>');
}

function home_url($path = '')
{
    return $GLOBALS['nexoflow_home'] . $path;
}

function admin_url($path = '')
{
    return 'https://example.com/wp-admin/' . $path;
}

function get_bloginfo($show = '')
{
    $values = array(
        'name' => 'Summit Garage Doors',
        'version' => '6.9',
        'language' => 'en-US',
    );

    return isset($values[$show]) ? $values[$show] : '';
}

function is_multisite()
{
    return false;
}

function wp_timezone_string()
{
    return 'America/Los_Angeles';
}

function wp_is_block_theme()
{
    return !empty($GLOBALS['nexoflow_block_theme']);
}

// The theme "summit" ships the templates in $GLOBALS['nexoflow_theme_templates'] as files, and the
// template parts in $GLOBALS['nexoflow_theme_parts'] (slug => array(content, area)).
// Like WordPress, a stored post with the same slug takes over from the file.
function get_block_template($id, $type = 'wp_template')
{
    $parts = explode('//', (string) $id, 2);

    if (count($parts) !== 2) {
        return null;
    }

    $is_part = $type === 'wp_template_part';

    foreach ($GLOBALS['nexoflow_posts'] as $post_id => $post) {
        if (isset($post['post_type'], $post['post_name'], $post['terms']['wp_theme'])
            && $post['post_type'] === $type
            && $post['post_name'] === $parts[1]
            && in_array($parts[0], (array) $post['terms']['wp_theme'], true)
        ) {
            $found = array(
                'id' => $id,
                'theme' => $parts[0],
                'slug' => $parts[1],
                'title' => $post['post_title'],
                'description' => '',
                'content' => $post['post_content'],
                'source' => 'custom',
                'wp_id' => $post_id,
                'status' => $post['post_status'],
            );

            if ($is_part) {
                $areas = isset($post['terms']['wp_template_part_area']) ? (array) $post['terms']['wp_template_part_area'] : array();
                $found['area'] = empty($areas) ? 'uncategorized' : (string) $areas[0];
            }

            return (object) $found;
        }
    }

    $files = $is_part
        ? (isset($GLOBALS['nexoflow_theme_parts']) ? $GLOBALS['nexoflow_theme_parts'] : array())
        : $GLOBALS['nexoflow_theme_templates'];

    if ($parts[0] !== 'summit' || !isset($files[$parts[1]])) {
        return null;
    }

    $found = array(
        'id' => $id,
        'theme' => $parts[0],
        'slug' => $parts[1],
        'title' => ucfirst($parts[1]),
        'description' => '',
        'content' => $is_part ? $files[$parts[1]]['content'] : $files[$parts[1]],
        'source' => 'theme',
        'wp_id' => null,
        'status' => 'publish',
    );

    if ($is_part) {
        $found['area'] = $files[$parts[1]]['area'];
    }

    return (object) $found;
}

function get_block_templates($query = array(), $type = 'wp_template')
{
    $templates = array();
    $files = $type === 'wp_template_part'
        ? (isset($GLOBALS['nexoflow_theme_parts']) ? $GLOBALS['nexoflow_theme_parts'] : array())
        : $GLOBALS['nexoflow_theme_templates'];

    foreach (array_keys($files) as $slug) {
        if (isset($query['slug__in']) && !in_array($slug, $query['slug__in'], true)) {
            continue;
        }

        $templates[] = get_block_template('summit//' . $slug, $type);
    }

    return $templates;
}

function get_stylesheet()
{
    return 'summit';
}

// What WordPress reads from the first bytes of a file; a name or a claimed type is never trusted.
function wp_get_image_mime($file)
{
    $head = (string) file_get_contents($file, false, null, 0, 12);

    if (strncmp($head, "\xFF\xD8\xFF", 3) === 0) {
        return 'image/jpeg';
    }

    if (strncmp($head, "\x89PNG", 4) === 0) {
        return 'image/png';
    }

    if (strncmp($head, 'GIF8', 4) === 0) {
        return 'image/gif';
    }

    if (strncmp($head, 'RIFF', 4) === 0 && substr($head, 8, 4) === 'WEBP') {
        return 'image/webp';
    }

    return false;
}

function wp_tempnam($filename = '')
{
    return !empty($GLOBALS['nexoflow_tempnam_fails']) ? false : tempnam(sys_get_temp_dir(), 'nfwp');
}

function wp_delete_file($file)
{
    if (is_string($file) && file_exists($file)) {
        unlink($file);
    }

    $GLOBALS['nexoflow_deleted_files'][] = $file;
}

// Stores the file as an attachment the way the media library does, and takes the temporary file with it.
function media_handle_sideload($file_array, $post_id = 0)
{
    if (!empty($GLOBALS['nexoflow_sideload_fails'])) {
        return new WP_Error('upload_error', 'Sorry, you are not allowed to upload this file type.');
    }

    $bytes = (string) file_get_contents($file_array['tmp_name']);
    unlink($file_array['tmp_name']);

    $id = wp_insert_post(
        array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_title' => pathinfo($file_array['name'], PATHINFO_FILENAME),
            'post_parent' => (int) $post_id,
            'post_author' => get_current_user_id(),
        )
    );

    $GLOBALS['nexoflow_uploads'][$id] = array('name' => $file_array['name'], 'bytes' => $bytes);

    return $id;
}

function wp_get_attachment_metadata($id)
{
    return isset($GLOBALS['nexoflow_uploads'][(int) $id]) ? array('width' => 1600, 'height' => 900) : false;
}

function get_attached_file($id)
{
    return '';
}

function wp_get_attachment_url($id)
{
    return isset($GLOBALS['nexoflow_uploads'][(int) $id])
        ? 'https://example.com/wp-content/uploads/2026/10/' . $GLOBALS['nexoflow_uploads'][(int) $id]['name']
        : false;
}

function wp_delete_post($id, $force = false)
{
    unset($GLOBALS['nexoflow_posts'][(int) $id]);

    return true;
}

class NexoFlow_Test_Theme
{
    public function get($field)
    {
        return $field === 'Name' ? 'Hello Elementor' : '3.4.4';
    }

    public function get_stylesheet()
    {
        return 'hello-elementor';
    }

    public function get_template()
    {
        return 'hello-elementor';
    }
}

function wp_get_theme()
{
    return new NexoFlow_Test_Theme();
}

function wp_json_encode($value, $flags = 0)
{
    return json_encode($value, $flags);
}

function wp_generate_password($length = 12, $special = true)
{
    return substr(bin2hex(random_bytes($length)), 0, $length);
}

function wp_slash($value)
{
    if (is_array($value)) {
        return array_map('wp_slash', $value);
    }

    return is_string($value) ? addslashes($value) : $value;
}

function sanitize_key($key)
{
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key));
}

function sanitize_textarea_field($value)
{
    return trim(strip_tags(is_string($value) ? $value : ''));
}

function get_file_data($file, $headers)
{
    $source = (string) file_get_contents($file);
    $data = array();

    foreach ($headers as $key => $label) {
        $data[$key] = preg_match('/^[ \t\/*#@]*' . preg_quote($label, '/') . ':(.*)$/mi', $source, $match) ? trim($match[1]) : '';
    }

    return $data;
}

function did_action($hook)
{
    return isset($GLOBALS['nexoflow_did_action'][$hook]) ? (int) $GLOBALS['nexoflow_did_action'][$hook] : 1;
}

function add_action($hook, $callback, $priority = 10, $args = 1)
{
    $GLOBALS['nexoflow_hooks'][$hook][] = $callback;
}

function add_filter($hook, $callback, $priority = 10, $args = 1)
{
    $GLOBALS['nexoflow_hooks'][$hook][] = $callback;
}

function do_action($hook)
{
    $args = array_slice(func_get_args(), 1);

    if (!isset($GLOBALS['nexoflow_hooks'][$hook])) {
        return;
    }

    foreach ($GLOBALS['nexoflow_hooks'][$hook] as $callback) {
        call_user_func_array($callback, $args);
    }
}

function get_transient($name)
{
    return isset($GLOBALS['nexoflow_transients'][$name]) ? $GLOBALS['nexoflow_transients'][$name] : false;
}

function set_transient($name, $value, $expiration = 0)
{
    $GLOBALS['nexoflow_transients'][$name] = $value;

    return true;
}

function delete_transient($name)
{
    unset($GLOBALS['nexoflow_transients'][$name]);

    return true;
}

function register_rest_route($namespace, $route, $args)
{
    $GLOBALS['nexoflow_routes']['/' . $namespace . $route] = $args;

    return true;
}

class WP_REST_Response
{
    public $data;
    public $status;
    public $headers = array();

    public function __construct($data = null, $status = 200)
    {
        $this->data = $data;
        $this->status = $status;
    }

    public function header($key, $value)
    {
        $this->headers[$key] = $value;
    }
}

class WP_REST_Request
{
    private $route;
    private $json;

    public function __construct($route, $json = null)
    {
        $this->route = $route;
        $this->json = $json;
    }

    public function get_route()
    {
        return $this->route;
    }

    public function get_json_params()
    {
        return $this->json;
    }
}

function wp_has_ability($name)
{
    return isset($GLOBALS['nexoflow_abilities'][$name]);
}

function wp_get_ability($name)
{
    return isset($GLOBALS['nexoflow_abilities'][$name]) ? $GLOBALS['nexoflow_abilities'][$name] : null;
}

function wp_get_abilities($args = array())
{
    return $GLOBALS['nexoflow_abilities'];
}

// Behaves like WP_Ability::execute(): the ability checks the current user itself.
class NexoFlow_Test_Ability
{
    public $calls = array();
    private $name;
    private $callback;

    public function __construct($name, $callback)
    {
        $this->name = $name;
        $this->callback = $callback;
    }

    public function get_name()
    {
        return $this->name;
    }

    public function execute($input = null)
    {
        $this->calls[] = $input;

        if (!current_user_can('edit_posts')) {
            return new WP_Error('ability_invalid_permissions', 'Ability "' . $this->name . '" does not have necessary permission.');
        }

        return call_user_func($this->callback, $input);
    }
}

function esc_html($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES);
}

function esc_attr($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES);
}

function esc_url($url)
{
    return (string) $url;
}

function esc_html__($text, $domain = null)
{
    return esc_html($text);
}

function wp_nonce_field($action, $name)
{
    echo '<input type="hidden" name="' . esc_attr($name) . '" value="nonce" />';
}

class NexoFlow_Test_Roles
{
    public function get_names()
    {
        return array(
            'administrator' => 'Administrator',
            'editor' => 'Editor',
        );
    }
}

function wp_roles()
{
    return new NexoFlow_Test_Roles();
}

function translate_user_role($name)
{
    return $name;
}

function dbDelta($sql)
{
    $GLOBALS['nexoflow_dbdelta'] = $sql;

    if (empty($GLOBALS['nexoflow_dbdelta_fails'])) {
        $GLOBALS['wpdb']->create_requests_table();
    }

    return array();
}

/**
 * Runs the plugin's SQL against an in-memory SQLite database, so the queries
 * and the unique claim are exercised for real.
 */
class NexoFlow_Test_Wpdb
{
    public $prefix = 'wp_';
    public $last_error = '';
    public $queries = array();
    private $db;

    public function __construct()
    {
        $this->db = new SQLite3(':memory:');
        $this->db->enableExceptions(true);
    }

    public function create_requests_table()
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS ' . $this->prefix . "nexoflow_agent_requests (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                request_id TEXT NOT NULL DEFAULT '',
                claim TEXT DEFAULT NULL UNIQUE,
                authorization_id TEXT NOT NULL DEFAULT '',
                action TEXT NOT NULL DEFAULT '',
                fingerprint TEXT NOT NULL DEFAULT '',
                status TEXT NOT NULL DEFAULT '',
                error_code TEXT NOT NULL DEFAULT '',
                object_id INTEGER NOT NULL DEFAULT 0,
                user_id INTEGER NOT NULL DEFAULT 0,
                response TEXT,
                created_at TEXT NOT NULL,
                completed_at TEXT DEFAULT NULL
            )"
        );
    }

    public function get_charset_collate()
    {
        return 'DEFAULT CHARACTER SET utf8mb4';
    }

    public function esc_like($text)
    {
        return addcslashes($text, '_%\\');
    }

    public function suppress_errors($suppress = true)
    {
        return false;
    }

    public function prepare($query)
    {
        $args = array_slice(func_get_args(), 1);
        $index = 0;

        return preg_replace_callback(
            '/%[sd]/',
            function ($match) use (&$index, $args) {
                $value = $args[$index];
                $index++;

                return $match[0] === '%d' ? (string) (int) $value : "'" . SQLite3::escapeString((string) $value) . "'";
            },
            $query
        );
    }

    public function query($sql)
    {
        $this->queries[] = $sql;

        try {
            $this->db->exec($sql);
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();

            return false;
        }

        return $this->db->changes();
    }

    public function get_results($sql, $output = 'OBJECT')
    {
        $this->queries[] = $sql;

        if (stripos($sql, 'SHOW TABLES LIKE') === 0) {
            $sql = "SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE" . substr($sql, 16) . " ESCAPE '\\'";
        }

        $rows = array();

        try {
            $result = $this->db->query($sql);
        } catch (Exception $e) {
            $this->last_error = $e->getMessage();

            return $rows;
        }

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }

        return $rows;
    }

    public function get_row($sql, $output = 'OBJECT')
    {
        $rows = $this->get_results($sql, $output);

        return empty($rows) ? null : $rows[0];
    }

    public function get_var($sql)
    {
        $row = $this->get_row($sql);

        return $row === null ? null : reset($row);
    }

    public function insert($table, $data)
    {
        $columns = implode(', ', array_keys($data));
        $values = implode(', ', array_map(array($this, 'literal'), array_values($data)));

        return $this->query("INSERT INTO {$table} ({$columns}) VALUES ({$values})") === false ? false : 1;
    }

    public function update($table, $data, $where)
    {
        $set = array();
        $conditions = array();

        foreach ($data as $column => $value) {
            $set[] = $column . ' = ' . $this->literal($value);
        }

        foreach ($where as $column => $value) {
            $conditions[] = $column . ' = ' . $this->literal($value);
        }

        return $this->query("UPDATE {$table} SET " . implode(', ', $set) . ' WHERE ' . implode(' AND ', $conditions));
    }

    public function literal($value)
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        return "'" . SQLite3::escapeString((string) $value) . "'";
    }
}
