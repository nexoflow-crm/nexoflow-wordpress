<?php

if (!defined('ABSPATH')) {
    exit;
}

class NexoFlow_Publisher
{
    const IMAGE_EXTENSIONS = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'avif');

    public function process_job($job)
    {
        if (!is_array($job)) {
            return array('ok' => false, 'error' => 'Invalid job payload.');
        }

        $action = isset($job['action']) && is_string($job['action']) ? $job['action'] : 'upsert';

        if ($action === 'unpublish') {
            return $this->unpublish($job);
        }

        if ($action !== 'upsert') {
            return array('ok' => false, 'error' => 'Invalid job payload.');
        }

        return $this->upsert($job);
    }

    public function upsert($job)
    {
        if (!isset($job['post']) || !is_array($job['post'])) {
            return array('ok' => false, 'error' => 'Invalid job payload.');
        }

        $data = $job['post'];
        $idempotency = $this->text_field(isset($job['idempotencyKey']) ? $job['idempotencyKey'] : '');
        $slug = $this->slug_field(isset($data['slug']) ? $data['slug'] : '');
        $post_id = $this->find_existing($idempotency, $slug);
        $postarr = $this->post_array($data, $slug);

        if ($post_id > 0) {
            $postarr['ID'] = $post_id;
        }

        $result = wp_insert_post($postarr, true);

        if (is_wp_error($result) || !$result) {
            return array('ok' => false, 'error' => 'Could not create the post.');
        }

        $post_id = (int) $result;
        $job_id = $this->text_field(isset($job['id']) ? $job['id'] : '');

        update_post_meta($post_id, '_nexoflow_idempotency', $idempotency);
        update_post_meta($post_id, '_nexoflow_job_id', $job_id);
        update_post_meta($post_id, '_nexoflow_slug', $slug);

        $this->assign_terms($post_id, isset($data['categories']) ? $data['categories'] : array(), 'category');
        $this->assign_terms($post_id, isset($data['tags']) ? $data['tags'] : array(), 'post_tag');
        $this->write_seo($post_id, isset($data['seo']) && is_array($data['seo']) ? $data['seo'] : array());

        $warning = '';
        $image = isset($data['featuredImage']) && is_array($data['featuredImage']) ? $data['featuredImage'] : array();

        if (isset($image['url']) && is_string($image['url']) && $image['url'] !== '') {
            if (!$this->attach_featured_image($post_id, $image)) {
                $warning = 'Post published without featured image.';
            }
        }

        $ack = array(
            'ok' => true,
            'wordpressPostId' => $post_id,
            'url' => (string) get_permalink($post_id),
        );

        if ($warning !== '') {
            $ack['warning'] = $warning;
        }

        return $ack;
    }

    public function unpublish($job)
    {
        $idempotency = $this->text_field(isset($job['idempotencyKey']) ? $job['idempotencyKey'] : '');
        $slug = '';

        if (isset($job['post']) && is_array($job['post']) && isset($job['post']['slug'])) {
            $slug = $this->slug_field($job['post']['slug']);
        }

        $post_id = $this->find_existing($idempotency, $slug);

        if ($post_id <= 0) {
            return array('ok' => false, 'error' => 'The post was not found.');
        }

        $result = wp_update_post(
            array(
                'ID' => $post_id,
                'post_status' => 'draft',
            ),
            true
        );

        if (is_wp_error($result) || !$result) {
            return array('ok' => false, 'error' => 'Could not update the post.');
        }

        if (isset($job['id'])) {
            update_post_meta($post_id, '_nexoflow_job_id', $this->text_field($job['id']));
        }

        return array(
            'ok' => true,
            'wordpressPostId' => $post_id,
            'url' => (string) get_permalink($post_id),
        );
    }

    public function find_existing($idempotency, $slug)
    {
        if ($idempotency !== '') {
            $found = $this->find_by_meta('_nexoflow_idempotency', $idempotency);

            if ($found > 0) {
                return $found;
            }
        }

        if ($slug !== '') {
            return $this->find_by_meta('_nexoflow_slug', $slug);
        }

        return 0;
    }

    public static function seo_meta_map($seo)
    {
        if (!is_array($seo)) {
            $seo = array();
        }

        $title = self::plain_text(isset($seo['metaTitle']) ? $seo['metaTitle'] : '');
        $description = self::plain_text(isset($seo['metaDescription']) ? $seo['metaDescription'] : '');
        $keyword = self::plain_text(isset($seo['focusKeyword']) ? $seo['focusKeyword'] : '');
        $canonical = self::plain_text(isset($seo['canonicalUrl']) ? $seo['canonicalUrl'] : '');
        $noindex = !empty($seo['noIndex']);
        $nofollow = !empty($seo['noFollow']);
        $robots = array($noindex ? 'noindex' : 'index', $nofollow ? 'nofollow' : 'follow');

        return array(
            'yoast_wpseo_title' => $title,
            'yoast_wpseo_metadesc' => $description,
            'yoast_wpseo_focuskw' => $keyword,
            'yoast_wpseo_canonical' => $canonical,
            'yoast_wpseo_meta-robots-noindex' => $noindex ? '1' : '0',
            'yoast_wpseo_meta-robots-nofollow' => $nofollow ? '1' : '0',
            'rank_math_title' => $title,
            'rank_math_description' => $description,
            'rank_math_focus_keyword' => $keyword,
            'rank_math_canonical_url' => $canonical,
            'rank_math_robots' => $robots,
            '_aioseo_title' => $title,
            '_aioseo_description' => $description,
            '_aioseo_keywords' => $keyword,
        );
    }

    public static function is_safe_image_url($url)
    {
        if (!is_string($url) || $url === '') {
            return false;
        }

        $parts = wp_parse_url($url);

        if (!is_array($parts) || !isset($parts['scheme']) || strtolower($parts['scheme']) !== 'https') {
            return false;
        }

        if (!isset($parts['host']) || !is_string($parts['host']) || $parts['host'] === '') {
            return false;
        }

        $host = strtolower($parts['host']);

        if ($host === 'localhost' || $host === 'localhost.' || substr($host, -10) === '.localhost') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::is_public_ip($host);
        }

        $resolved = gethostbyname($host);

        if ($resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP) && !self::is_public_ip($resolved)) {
            return false;
        }

        return true;
    }

    public static function is_public_ip($ip)
    {
        if (!is_string($ip) || $ip === '') {
            return false;
        }

        if ($ip === '127.0.0.1' || $ip === '0.0.0.0' || $ip === '::1') {
            return false;
        }

        if (strpos($ip, '10.') === 0 || strpos($ip, '192.168.') === 0 || strpos($ip, '169.254.') === 0) {
            return false;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    public function assign_terms($post_id, $names, $taxonomy)
    {
        $post_id = (int) $post_id;
        $ids = array();

        if (!is_array($names)) {
            return $ids;
        }

        foreach ($names as $name) {
            if (!is_string($name)) {
                continue;
            }

            $name = trim($name);

            if ($name === '') {
                continue;
            }

            $term_id = $this->term_id_from_name($name, $taxonomy);

            if ($term_id > 0) {
                $ids[] = $term_id;
            }
        }

        $ids = array_values(array_unique($ids));

        if (!empty($ids)) {
            wp_set_post_terms($post_id, $ids, $taxonomy, false);
        }

        return $ids;
    }

    public function term_id_from_name($name, $taxonomy)
    {
        $existing = get_term_by('name', $name, $taxonomy);

        if ($existing && !is_wp_error($existing) && isset($existing->term_id)) {
            return (int) $existing->term_id;
        }

        $created = wp_insert_term($name, $taxonomy);

        if (is_wp_error($created) || !isset($created['term_id'])) {
            return 0;
        }

        return (int) $created['term_id'];
    }

    private function write_seo($post_id, $seo)
    {
        $map = self::seo_meta_map($seo);

        foreach ($map as $key => $value) {
            update_post_meta($post_id, $key, $value);
        }
    }

    private function attach_featured_image($post_id, $image)
    {
        $url = isset($image['url']) ? $image['url'] : '';

        if (!self::is_safe_image_url($url)) {
            return false;
        }

        if (!function_exists('download_url') || !function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $filename = $this->image_filename($image, $url);
        $tmp = download_url($url, NexoFlow_Client::TIMEOUT);

        if (is_wp_error($tmp)) {
            return false;
        }

        $file_array = array(
            'name' => $filename,
            'tmp_name' => $tmp,
        );

        $attachment_id = media_handle_sideload($file_array, $post_id);

        if (is_wp_error($attachment_id)) {
            if (is_string($tmp) && file_exists($tmp)) {
                wp_delete_file($tmp);
            }

            return false;
        }

        set_post_thumbnail($post_id, (int) $attachment_id);

        if (isset($image['alt']) && is_string($image['alt']) && $image['alt'] !== '') {
            update_post_meta((int) $attachment_id, '_wp_attachment_image_alt', $this->text_field($image['alt']));
        }

        return true;
    }

    private function image_filename($image, $url)
    {
        $filename = '';

        if (isset($image['filename']) && is_string($image['filename'])) {
            $filename = $image['filename'];
        }

        if ($filename === '') {
            $path = wp_parse_url($url, PHP_URL_PATH);
            $filename = is_string($path) ? basename($path) : '';
        }

        $filename = sanitize_file_name($filename);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if ($filename === '' || !in_array($ext, self::IMAGE_EXTENSIONS, true)) {
            return 'image.webp';
        }

        return $filename;
    }

    private function post_array($data, $slug)
    {
        $status = isset($data['status']) && is_string($data['status']) ? $data['status'] : 'draft';
        $allowed = array('draft', 'publish', 'private', 'future');

        if (!in_array($status, $allowed, true)) {
            $status = 'draft';
        }

        $postarr = array(
            'post_type' => 'post',
            'post_title' => $this->text_field(isset($data['title']) ? $data['title'] : ''),
            'post_name' => $slug,
            'post_content' => isset($data['content']) ? wp_kses_post($data['content']) : '',
            'post_excerpt' => $this->text_field(isset($data['excerpt']) ? $data['excerpt'] : ''),
            'post_status' => $status,
        );

        $timestamp = 0;

        if (isset($data['dateGmt']) && is_string($data['dateGmt']) && $data['dateGmt'] !== '') {
            $parsed = strtotime($data['dateGmt']);

            if ($parsed !== false) {
                $timestamp = $parsed;
            }
        }

        if ($timestamp > 0) {
            $gmt = gmdate('Y-m-d H:i:s', $timestamp);
            $postarr['post_date_gmt'] = $gmt;
            $postarr['post_date'] = function_exists('get_date_from_gmt') ? get_date_from_gmt($gmt) : $gmt;
        }

        if ($status === 'future') {
            if ($timestamp > time()) {
                $postarr['post_status'] = 'future';
            } else {
                $postarr['post_status'] = 'publish';
            }
        }

        if (isset($data['authorLogin']) && is_string($data['authorLogin']) && $data['authorLogin'] !== '') {
            $user = get_user_by('login', $data['authorLogin']);

            if ($user && isset($user->ID)) {
                $postarr['post_author'] = (int) $user->ID;
            }
        }

        return $postarr;
    }

    private function find_by_meta($key, $value)
    {
        // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One post, unique NexoFlow key.
        $found = get_posts(
            array(
                'post_type' => 'post',
                'post_status' => 'any',
                'posts_per_page' => 1,
                'fields' => 'ids',
                'no_found_rows' => true,
                'meta_query' => array(
                    array(
                        'key' => $key,
                        'value' => $value,
                        'compare' => '=',
                    ),
                ),
            )
        );
        // phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query

        if (empty($found)) {
            return 0;
        }

        return (int) $found[0];
    }

    private function text_field($value)
    {
        if (!is_string($value)) {
            return '';
        }

        return sanitize_text_field($value);
    }

    private function slug_field($value)
    {
        if (!is_string($value)) {
            return '';
        }

        return sanitize_title($value);
    }

    private static function plain_text($value)
    {
        if (!is_string($value)) {
            return '';
        }

        if (function_exists('sanitize_text_field')) {
            return sanitize_text_field($value);
        }

        return trim(wp_strip_all_tags($value));
    }
}
