<?php

if (!defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/');
}

if (!defined('NEXOFLOW_VERSION')) {
    define('NEXOFLOW_VERSION', '1.0.0');
}

if (!defined('NEXOFLOW_DEFAULT_API_BASE')) {
    define('NEXOFLOW_DEFAULT_API_BASE', 'https://nexoflow.net');
}

$GLOBALS['nexoflow_options'] = array();

function get_option($name, $default = false)
{
    if (array_key_exists($name, $GLOBALS['nexoflow_options'])) {
        return $GLOBALS['nexoflow_options'][$name];
    }

    return $default;
}

function update_option($name, $value, $autoload = true)
{
    $GLOBALS['nexoflow_options'][$name] = $value;

    return true;
}

$GLOBALS['nexoflow_posts'] = array();
$GLOBALS['nexoflow_terms'] = array(
    'category' => array(),
    'post_tag' => array(),
);
$GLOBALS['nexoflow_next_post_id'] = 1;
$GLOBALS['nexoflow_next_term_id'] = 1;

class WP_Error
{
    private $code;
    private $message;

    public function __construct($code = '', $message = '')
    {
        $this->code = $code;
        $this->message = $message;
    }

    public function get_error_message()
    {
        return $this->message;
    }

    public function get_error_code()
    {
        return $this->code;
    }
}

function is_wp_error($thing)
{
    return $thing instanceof WP_Error;
}

function sanitize_text_field($value)
{
    $value = is_string($value) ? $value : '';
    $value = strip_tags($value);
    $value = preg_replace('/[\r\n\t]+/', ' ', $value);

    return trim($value);
}

function sanitize_title($value)
{
    $value = is_string($value) ? strtolower($value) : '';
    $value = preg_replace('/[^a-z0-9]+/', '-', $value);

    return trim($value, '-');
}

function sanitize_file_name($filename)
{
    $filename = is_string($filename) ? $filename : '';
    $filename = preg_replace('/[^A-Za-z0-9._-]/', '', $filename);

    return $filename;
}

function wp_kses_post($content)
{
    return is_string($content) ? $content : '';
}

function wp_parse_url($url, $component = -1)
{
    if ($component === -1) {
        return parse_url($url);
    }

    return parse_url($url, $component);
}

function get_posts($args)
{
    $key = isset($args['meta_key']) ? $args['meta_key'] : '';
    $value = isset($args['meta_value']) ? $args['meta_value'] : '';

    if ($key === '' && isset($args['meta_query'][0]) && is_array($args['meta_query'][0])) {
        $key = isset($args['meta_query'][0]['key']) ? $args['meta_query'][0]['key'] : '';
        $value = isset($args['meta_query'][0]['value']) ? $args['meta_query'][0]['value'] : '';
    }

    $ids = array();

    foreach ($GLOBALS['nexoflow_posts'] as $id => $post) {
        if ($key !== '' && (!isset($post['meta'][$key]) || $post['meta'][$key] !== $value)) {
            continue;
        }

        $ids[] = $id;
    }

    return $ids;
}

function wp_insert_post($postarr, $wp_error = false)
{
    $id = isset($postarr['ID']) ? (int) $postarr['ID'] : 0;

    if ($id > 0 && isset($GLOBALS['nexoflow_posts'][$id])) {
        $GLOBALS['nexoflow_posts'][$id] = array_merge($GLOBALS['nexoflow_posts'][$id], $postarr);

        return $id;
    }

    $id = $GLOBALS['nexoflow_next_post_id'];
    $GLOBALS['nexoflow_next_post_id']++;
    $postarr['ID'] = $id;

    if (!isset($postarr['meta'])) {
        $postarr['meta'] = array();
    }

    $GLOBALS['nexoflow_posts'][$id] = $postarr;

    return $id;
}

function wp_update_post($postarr, $wp_error = false)
{
    return wp_insert_post($postarr, $wp_error);
}

function update_post_meta($post_id, $key, $value)
{
    $post_id = (int) $post_id;

    if (!isset($GLOBALS['nexoflow_posts'][$post_id])) {
        return false;
    }

    $GLOBALS['nexoflow_posts'][$post_id]['meta'][$key] = $value;

    return true;
}

function get_post_meta($post_id, $key, $single = false)
{
    $post_id = (int) $post_id;

    if (!isset($GLOBALS['nexoflow_posts'][$post_id]['meta'][$key])) {
        return $single ? '' : array();
    }

    $value = $GLOBALS['nexoflow_posts'][$post_id]['meta'][$key];

    return $single ? $value : array($value);
}

function get_permalink($post_id)
{
    $post_id = (int) $post_id;
    $slug = isset($GLOBALS['nexoflow_posts'][$post_id]['post_name'])
        ? $GLOBALS['nexoflow_posts'][$post_id]['post_name']
        : (string) $post_id;

    return 'https://example.com/' . $slug . '/';
}

function get_term_by($field, $value, $taxonomy)
{
    if ($field !== 'name' || !isset($GLOBALS['nexoflow_terms'][$taxonomy])) {
        return false;
    }

    foreach ($GLOBALS['nexoflow_terms'][$taxonomy] as $term) {
        if ($term->name === $value) {
            return $term;
        }
    }

    return false;
}

function wp_insert_term($name, $taxonomy)
{
    if (!isset($GLOBALS['nexoflow_terms'][$taxonomy])) {
        $GLOBALS['nexoflow_terms'][$taxonomy] = array();
    }

    $existing = get_term_by('name', $name, $taxonomy);

    if ($existing) {
        return array('term_id' => (int) $existing->term_id);
    }

    $id = $GLOBALS['nexoflow_next_term_id'];
    $GLOBALS['nexoflow_next_term_id']++;
    $term = (object) array(
        'term_id' => $id,
        'name' => $name,
        'taxonomy' => $taxonomy,
    );
    $GLOBALS['nexoflow_terms'][$taxonomy][$id] = $term;

    return array('term_id' => $id);
}

function wp_set_post_terms($post_id, $terms, $taxonomy, $append = false)
{
    $post_id = (int) $post_id;

    if (!isset($GLOBALS['nexoflow_posts'][$post_id])) {
        return false;
    }

    $GLOBALS['nexoflow_posts'][$post_id]['terms'][$taxonomy] = $terms;

    return $terms;
}

function get_user_by($field, $value)
{
    return false;
}

function get_date_from_gmt($gmt)
{
    return $gmt;
}

function apply_filters($hook, $value)
{
    return $value;
}

function untrailingslashit($value)
{
    return rtrim((string) $value, '/');
}

function __($text, $domain = null)
{
    return $text;
}

function nexoflow_reset_store()
{
    $GLOBALS['nexoflow_posts'] = array();
    $GLOBALS['nexoflow_terms'] = array(
        'category' => array(),
        'post_tag' => array(),
    );
    $GLOBALS['nexoflow_next_post_id'] = 1;
    $GLOBALS['nexoflow_next_term_id'] = 1;
    $GLOBALS['nexoflow_options'] = array();
}
