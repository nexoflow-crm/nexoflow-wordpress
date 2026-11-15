<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Applies the publish jobs NexoFlow sends: one post each, created or updated in place.
 *
 * A job's post carries its fields flat (title, content, featuredImageUrl, metaTitle, wordpressPostId and so on), the
 * way NexoFlow sends them. The nested shape of 2.0.0 (featuredImage.url, seo.metaTitle, dateGmt, authorLogin) is still
 * read where the flat field is missing.
 */
class NexoFlow_Publisher
{
    const IMAGE_EXTENSIONS = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'avif');

    /** The address of the image a post's featured image came from, so a re-sent post does not add another copy. */
    const IMAGE_META = '_nexoflow_featured_image_url';

    /** Where NexoFlow's own download (1.0.0 to 1.2.5) kept the author for new posts. */
    const LEGACY_AUTHOR_OPTION = 'nexoflow_author_id';

    /** A featured image larger than this is not kept. */
    const MAX_IMAGE_BYTES = 15728640;

    /** Each address an image download is sent on to is checked again; this many at most. */
    const MAX_IMAGE_HOPS = 3;

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

        if ($slug === '') {
            return array('ok' => false, 'error' => 'This job is missing a slug.');
        }

        $post_id = $this->find_existing($idempotency, $slug, $this->stored_post_id($data));
        $postarr = $this->post_array($data, $slug);

        if ($post_id > 0) {
            $postarr['ID'] = $post_id;
        } else {
            // Only a new post gets an author: an update never changes who wrote the post.
            $author = $this->author_id($this->author_name($data));

            if ($author > 0) {
                $postarr['post_author'] = $author;
            }
        }

        // WordPress takes the fields slashed and stores them unslashed, so a backslash in the post survives.
        $result = $post_id > 0 ? wp_update_post(wp_slash($postarr), true) : wp_insert_post(wp_slash($postarr), true);

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
        $this->write_seo($post_id, self::seo_fields($data));

        $warning = '';
        $image = self::image_from($data);

        if ($image['url'] !== '' && !$this->attach_featured_image($post_id, $image)) {
            $warning = 'Post published without featured image.';
        }

        $url = (string) get_permalink($post_id);
        $ack = array(
            'ok' => true,
            'wordpressPostId' => $post_id,
            'liveUrl' => $url,
            'url' => $url,
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
        $stored = 0;

        if (isset($job['post']) && is_array($job['post'])) {
            $slug = isset($job['post']['slug']) ? $this->slug_field($job['post']['slug']) : '';
            $stored = $this->stored_post_id($job['post']);
        }

        $post_id = $this->find_existing($idempotency, $slug, $stored);

        // Nothing to take down: the post is already gone from the site, so the job is done.
        if ($post_id <= 0) {
            return array(
                'ok' => true,
                'wordpressPostId' => null,
                'liveUrl' => null,
            );
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

        $url = (string) get_permalink($post_id);

        return array(
            'ok' => true,
            'wordpressPostId' => $post_id,
            'liveUrl' => $url,
            'url' => $url,
        );
    }

    /**
     * The post a job is about: the id NexoFlow holds for it, then the post an earlier job made, then a post with the
     * same address. The last one finds posts published before this plugin, so an update never makes a second copy.
     */
    public function find_existing($idempotency, $slug, $stored_id = 0)
    {
        $stored_id = (int) $stored_id;

        if ($stored_id > 0) {
            $post = get_post($stored_id);

            if ($post && isset($post->post_type) && $post->post_type === 'post' && $post->post_status !== 'trash') {
                return (int) $post->ID;
            }
        }

        if ($idempotency !== '') {
            $found = $this->find_by_meta('_nexoflow_idempotency', $idempotency);

            if ($found > 0) {
                return $found;
            }
        }

        if ($slug === '') {
            return 0;
        }

        $found = $this->find_by_meta('_nexoflow_slug', $slug);

        if ($found > 0) {
            return $found;
        }

        // WordPress also searches images here, so only a post counts: an image with the same name is never touched.
        if (function_exists('get_page_by_path')) {
            $post = get_page_by_path($slug, OBJECT, 'post');

            if ($post && isset($post->ID, $post->post_type) && $post->post_type === 'post' && $post->post_status !== 'trash') {
                return (int) $post->ID;
            }
        }

        return 0;
    }

    /**
     * The search fields of a job's post, read flat as NexoFlow sends them, else from the nested "seo" shape.
     *
     * @return array title, description, canonical, focus_keyword, noindex, nofollow
     */
    public static function seo_fields($data)
    {
        $data = is_array($data) ? $data : array();
        $nested = isset($data['seo']) && is_array($data['seo']) ? $data['seo'] : array();
        $read = function ($field) use ($data, $nested) {
            if (array_key_exists($field, $data)) {
                return $data[$field];
            }

            return array_key_exists($field, $nested) ? $nested[$field] : null;
        };
        $canonical = self::plain_text($read('canonicalUrl'));

        return array(
            'title' => self::plain_text($read('metaTitle')),
            'description' => self::plain_text($read('metaDescription')),
            'canonical' => $canonical !== '' && function_exists('esc_url_raw') ? esc_url_raw($canonical) : $canonical,
            'focus_keyword' => self::plain_text($read('focusKeyword')),
            // true, false, or null when the job does not say.
            'noindex' => is_bool($read('noIndex')) ? $read('noIndex') : null,
            'nofollow' => is_bool($read('noFollow')) ? $read('noFollow') : null,
        );
    }

    /** The featured image of a job's post: featuredImageUrl as NexoFlow sends it, else the nested featuredImage. */
    public static function image_from($data)
    {
        $image = array('url' => '', 'alt' => '', 'filename' => '');

        if (!is_array($data)) {
            return $image;
        }

        if (isset($data['featuredImage']) && is_array($data['featuredImage'])) {
            foreach (array('url', 'alt', 'filename') as $field) {
                if (isset($data['featuredImage'][$field]) && is_string($data['featuredImage'][$field])) {
                    $image[$field] = trim($data['featuredImage'][$field]);
                }
            }
        }

        if (isset($data['featuredImageUrl']) && is_string($data['featuredImageUrl']) && trim($data['featuredImageUrl']) !== '') {
            $image['url'] = trim($data['featuredImageUrl']);
        }

        return $image;
    }

    /**
     * Whether an image address may be fetched: https only, and every address its host resolves to is public. A host
     * that does not resolve is refused.
     */
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

        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            return false;
        }

        $host = strtolower(trim($parts['host'], '[]'));

        if ($host === 'localhost' || $host === 'localhost.' || substr($host, -10) === '.localhost' || substr($host, -11) === '.localhost.') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::is_public_ip($host);
        }

        $addresses = apply_filters('nexoflow_image_host_addresses', null, $host);

        if (!is_array($addresses)) {
            $addresses = self::host_addresses($host);
        }

        if (empty($addresses)) {
            return false;
        }

        foreach ($addresses as $address) {
            if (!self::is_public_ip($address)) {
                return false;
            }
        }

        return true;
    }

    /** Every IPv4 and IPv6 address a host name resolves to. */
    private static function host_addresses($host)
    {
        $addresses = array();
        $v4 = gethostbynamel($host);

        if (is_array($v4)) {
            $addresses = $v4;
        }

        if (function_exists('dns_get_record')) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failed lookup only means no IPv6 address.
            $v6 = @dns_get_record($host, DNS_AAAA);

            if (is_array($v6)) {
                foreach ($v6 as $record) {
                    if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                        $addresses[] = $record['ipv6'];
                    }
                }
            }
        }

        return array_values(array_unique($addresses));
    }

    public static function is_public_ip($ip)
    {
        if (!is_string($ip) || $ip === '') {
            return false;
        }

        if ($ip === '127.0.0.1' || $ip === '0.0.0.0' || $ip === '::1' || $ip === '::') {
            return false;
        }

        if (strpos($ip, '10.') === 0 || strpos($ip, '192.168.') === 0 || strpos($ip, '169.254.') === 0) {
            return false;
        }

        // IPv6 link-local, unique-local and IPv4-mapped addresses.
        $lower = strtolower($ip);

        if (strpos($lower, 'fe80:') === 0 || strpos($lower, 'fc') === 0 || strpos($lower, 'fd') === 0 || strpos($lower, '::ffff:') === 0) {
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

        if (!$existing || is_wp_error($existing)) {
            $existing = get_term_by('slug', sanitize_title($name), $taxonomy);
        }

        if ($existing && !is_wp_error($existing) && isset($existing->term_id)) {
            return (int) $existing->term_id;
        }

        $created = wp_insert_term($name, $taxonomy);

        if (is_wp_error($created) || !isset($created['term_id'])) {
            return 0;
        }

        return (int) $created['term_id'];
    }

    /**
     * Writes the search fields through the plugin's own SEO fields, which also fill the active SEO plugin's. Only the
     * fields the job carries are written, so an empty one never clears what someone wrote on the site.
     */
    private function write_seo($post_id, $seo)
    {
        $fields = array();

        foreach (array('title', 'description', 'canonical') as $field) {
            if ($seo[$field] !== '') {
                $fields[$field] = $seo[$field];
            }
        }

        // On or off as the job says. Off only takes back what a job switched on, never a setting made on the site.
        foreach (array('noindex', 'nofollow') as $field) {
            $marker = '_nexoflow_job_' . $field;

            if ($seo[$field] === true) {
                $fields[$field] = true;
                update_post_meta($post_id, $marker, '1');
            } elseif ($seo[$field] === false && (string) get_post_meta($post_id, $marker, true) === '1') {
                $fields[$field] = false;
                delete_post_meta($post_id, $marker);
            }
        }

        if (!empty($fields)) {
            if (class_exists('NexoFlow_SEO')) {
                NexoFlow_SEO::save_page($post_id, $fields);
            } else {
                $meta = array(
                    'title' => '_nexoflow_seo_title',
                    'description' => '_nexoflow_seo_description',
                    'canonical' => '_nexoflow_seo_canonical',
                    'noindex' => '_nexoflow_seo_noindex',
                    'nofollow' => '_nexoflow_seo_nofollow',
                );

                foreach ($fields as $field => $value) {
                    if ($value === false) {
                        delete_post_meta($post_id, $meta[$field]);
                    } else {
                        update_post_meta($post_id, $meta[$field], $value === true ? '1' : $value);
                    }
                }
            }
        }

        if ($seo['focus_keyword'] !== '') {
            update_post_meta($post_id, '_yoast_wpseo_focuskw', $seo['focus_keyword']);
            update_post_meta($post_id, 'rank_math_focus_keyword', $seo['focus_keyword']);
        }
    }

    private function attach_featured_image($post_id, $image)
    {
        $url = $image['url'];

        // The same image is already the post's featured image.
        if ((string) get_post_meta($post_id, self::IMAGE_META, true) === $url
            && function_exists('has_post_thumbnail') && has_post_thumbnail($post_id)) {
            return true;
        }

        // Jobs run from the scheduler, where the media functions are not loaded yet. Each file is loaded when its
        // own function is missing, since another plugin may have loaded only some of them.
        $needed = array(
            'wp_tempnam' => 'file.php',
            'media_handle_sideload' => 'media.php',
            'wp_generate_attachment_metadata' => 'image.php',
        );

        foreach ($needed as $function => $file) {
            if (!function_exists($function)) {
                require_once ABSPATH . 'wp-admin/includes/' . $file;
            }
        }

        $tmp = $this->download_image($url);

        if ($tmp === '') {
            return false;
        }

        $file_array = array(
            'name' => $this->image_filename($image, $url),
            'tmp_name' => $tmp,
        );

        $attachment_id = media_handle_sideload($file_array, $post_id);

        if (is_wp_error($attachment_id)) {
            if (file_exists($tmp)) {
                wp_delete_file($tmp);
            }

            return false;
        }

        set_post_thumbnail($post_id, (int) $attachment_id);
        update_post_meta($post_id, self::IMAGE_META, $url);

        if ($image['alt'] !== '') {
            update_post_meta((int) $attachment_id, '_wp_attachment_image_alt', $this->text_field($image['alt']));
        }

        return true;
    }

    /**
     * Downloads an image to a temporary file. Redirects are followed one at a time, and every address is checked
     * with is_safe_image_url before it is fetched. Returns the file's path, or '' when the image cannot be kept.
     */
    private function download_image($url)
    {
        $tmp = wp_tempnam($url);

        if (!is_string($tmp) || $tmp === '') {
            return '';
        }

        for ($hop = 0; $hop <= self::MAX_IMAGE_HOPS; $hop++) {
            if (!self::is_safe_image_url($url)) {
                break;
            }

            $response = wp_safe_remote_get(
                $url,
                array(
                    'timeout' => NexoFlow_Client::TIMEOUT,
                    'redirection' => 0,
                    'stream' => true,
                    'filename' => $tmp,
                    'limit_response_size' => self::MAX_IMAGE_BYTES,
                )
            );

            if (is_wp_error($response)) {
                break;
            }

            $code = (int) wp_remote_retrieve_response_code($response);

            if ($code >= 300 && $code < 400) {
                $location = wp_remote_retrieve_header($response, 'location');

                if (!is_string($location) || $location === '') {
                    break;
                }

                $url = class_exists('WP_Http') ? WP_Http::make_absolute_url($location, $url) : $location;
                continue;
            }

            clearstatcache(true, $tmp);
            $size = file_exists($tmp) ? (int) filesize($tmp) : 0;

            // The download stops at the limit without an error, so a file of that size was cut short.
            if ($code === 200 && $size > 0 && $size < self::MAX_IMAGE_BYTES) {
                return $tmp;
            }

            break;
        }

        if (file_exists($tmp)) {
            wp_delete_file($tmp);
        }

        return '';
    }

    private function image_filename($image, $url)
    {
        $filename = $image['filename'];

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
            'post_content' => isset($data['content']) && is_string($data['content']) ? wp_kses_post($data['content']) : '',
            'post_excerpt' => isset($data['excerpt']) && is_string($data['excerpt']) ? sanitize_textarea_field($data['excerpt']) : '',
            'post_status' => $status,
        );

        $timestamp = 0;

        foreach (array('date', 'dateGmt') as $field) {
            if ($timestamp === 0 && isset($data[$field]) && is_string($data[$field]) && $data[$field] !== '') {
                $parsed = strtotime($data[$field]);

                if ($parsed !== false) {
                    $timestamp = $parsed;
                }
            }
        }

        if ($timestamp > 0) {
            $gmt = gmdate('Y-m-d H:i:s', $timestamp);
            $postarr['post_date_gmt'] = $gmt;
            $postarr['post_date'] = function_exists('get_date_from_gmt') ? get_date_from_gmt($gmt) : $gmt;
            $postarr['edit_date'] = true;
        }

        if ($status === 'future') {
            $postarr['post_status'] = $timestamp > time() ? 'future' : 'publish';
        }

        return $postarr;
    }

    private function stored_post_id($data)
    {
        if (!is_array($data) || !isset($data['wordpressPostId']) || !is_numeric($data['wordpressPostId'])) {
            return 0;
        }

        return max(0, (int) $data['wordpressPostId']);
    }

    private function author_name($data)
    {
        foreach (array('author', 'authorLogin') as $field) {
            if (isset($data[$field]) && is_string($data[$field]) && trim($data[$field]) !== '') {
                return trim($data[$field]);
            }
        }

        return '';
    }

    /**
     * The author of a new post: the named user if they may publish posts, else the account NexoFlow acts as on
     * this site, else the author the earlier NexoFlow download kept, else the first administrator.
     */
    private function author_id($name)
    {
        if ($name !== '') {
            foreach (array('login', 'slug', 'email') as $field) {
                $user = get_user_by($field, $name);

                if ($user && isset($user->ID) && $this->may_author((int) $user->ID)) {
                    return (int) $user->ID;
                }
            }

            $named = get_users(
                array(
                    'search' => $name,
                    'search_columns' => array('display_name'),
                    'number' => 5,
                    'fields' => 'ID',
                )
            );

            if (is_array($named)) {
                foreach ($named as $id) {
                    if ($this->may_author((int) $id)) {
                        return (int) $id;
                    }
                }
            }
        }

        foreach (array('nexoflow_agent_user', self::LEGACY_AUTHOR_OPTION) as $option) {
            $saved = (int) get_option($option, 0);

            if ($saved > 0 && $this->may_author($saved)) {
                return $saved;
            }
        }

        $admins = get_users(
            array(
                'role' => 'administrator',
                'orderby' => 'ID',
                'order' => 'ASC',
                'number' => 1,
                'fields' => 'ID',
            )
        );

        return is_array($admins) && !empty($admins) ? (int) $admins[0] : 0;
    }

    private function may_author($user_id)
    {
        return $user_id > 0 && get_userdata($user_id) && user_can($user_id, 'publish_posts');
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
