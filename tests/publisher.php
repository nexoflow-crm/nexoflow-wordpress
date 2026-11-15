<?php

// Publish jobs as NexoFlow sends them, the sync that applies them, and the key NexoFlow no longer accepts.

require_once dirname(__DIR__) . '/nexoflow/includes/class-auth.php';
require_once dirname(__DIR__) . '/nexoflow/includes/class-cron.php';
require_once dirname(__DIR__) . '/nexoflow/includes/class-indexnow.php';
require_once dirname(__DIR__) . '/nexoflow/includes/class-bing.php';

if (!function_exists('get_page_by_path')) {
    // Like WordPress: images are searched too, and one is returned when no post of the type has that name.
    function get_page_by_path($path, $output = OBJECT, $post_type = 'page')
    {
        $found = null;

        foreach ($GLOBALS['nexoflow_posts'] as $id => $post) {
            if (($post['post_name'] ?? '') !== $path) {
                continue;
            }

            $type = $post['post_type'] ?? 'post';

            if ($type === $post_type) {
                return get_post($id);
            }

            if ($type === 'attachment' && $found === null) {
                $found = get_post($id);
            }
        }

        return $found;
    }
}

if (!function_exists('wp_generate_attachment_metadata')) {
    function wp_generate_attachment_metadata($attachment_id, $file)
    {
        return array();
    }
}

if (!function_exists('delete_post_meta')) {
    function delete_post_meta($post_id, $key)
    {
        unset($GLOBALS['nexoflow_posts'][(int) $post_id]['meta'][$key]);

        return true;
    }
}

if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}

if (!function_exists('has_post_thumbnail')) {
    function has_post_thumbnail($post_id)
    {
        return !empty($GLOBALS['nexoflow_posts'][(int) $post_id]['meta']['_thumbnail_id']);
    }
}

if (!function_exists('set_post_thumbnail')) {
    function set_post_thumbnail($post_id, $attachment_id)
    {
        return update_post_meta($post_id, '_thumbnail_id', (int) $attachment_id);
    }
}

// Image downloads: each address answers from $GLOBALS['nexoflow_http_answers'], and every request is recorded.
if (!function_exists('wp_safe_remote_get')) {
    function wp_safe_remote_get($url, $args = array())
    {
        $GLOBALS['nexoflow_http_requests'][] = array('url' => $url, 'args' => $args);
        $answer = isset($GLOBALS['nexoflow_http_answers'][$url]) ? $GLOBALS['nexoflow_http_answers'][$url] : array('code' => 404);

        if (isset($answer['body']) && !empty($args['filename'])) {
            $body = $answer['body'];

            if (!empty($args['limit_response_size'])) {
                $body = substr($body, 0, (int) $args['limit_response_size']);
            }

            file_put_contents($args['filename'], $body);
        }

        return array(
            'response' => array('code' => $answer['code']),
            'headers' => isset($answer['headers']) ? $answer['headers'] : array(),
            'body' => '',
        );
    }
}

// Calls to NexoFlow: answered in turn from $GLOBALS['nexoflow_api_answers'], every request recorded.
if (!function_exists('wp_remote_request')) {
    function wp_remote_request($url, $args = array())
    {
        $GLOBALS['nexoflow_api_requests'][] = array('url' => $url, 'args' => $args);
        $answer = array_shift($GLOBALS['nexoflow_api_answers']);

        if ($answer === null) {
            $answer = array('code' => 200, 'body' => '{"ok":true}');
        }

        return array(
            'response' => array('code' => $answer['code']),
            'headers' => isset($answer['headers']) ? $answer['headers'] : array(),
            'body' => $answer['body'],
        );
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response)
    {
        return is_array($response) ? $response['response']['code'] : '';
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response)
    {
        return is_array($response) ? $response['body'] : '';
    }
}

if (!function_exists('wp_remote_retrieve_header')) {
    function wp_remote_retrieve_header($response, $header)
    {
        return is_array($response) && isset($response['headers'][$header]) ? $response['headers'][$header] : '';
    }
}

$GLOBALS['nexoflow_filters']['nexoflow_image_host_addresses'] = function () {
    return $GLOBALS['nexoflow_test_host_addresses'];
};
$GLOBALS['nexoflow_test_host_addresses'] = array('93.184.216.34');

function nexoflow_publisher_world()
{
    nexoflow_reset_store();
    $GLOBALS['nexoflow_users'] = array();
    $GLOBALS['nexoflow_http_requests'] = array();
    $GLOBALS['nexoflow_http_answers'] = array();
    $GLOBALS['nexoflow_api_requests'] = array();
    $GLOBALS['nexoflow_api_answers'] = array();
    $GLOBALS['nexoflow_uploads'] = array();
    nexoflow_test_user(1, 'administrator', array('publish_posts', 'manage_options'), 'Site Owner');
    nexoflow_test_user(2, 'editor', array('publish_posts'), 'Jamie Writer');
    nexoflow_test_user(3, 'subscriber', array('read'), 'Mark Reader');
}

// A job exactly as NexoFlow sends it: the post's fields are flat.
function nexoflow_app_job($overrides = array())
{
    return array(
        'id' => 'job_1',
        'action' => 'upsert',
        'idempotencyKey' => 'upsert:why-the-opener-failed',
        'version' => '2026-11-15T10:00:00.000Z',
        'post' => array_merge(
            array(
                'slug' => 'why-the-opener-failed',
                'title' => 'Why the opener failed',
                'content' => '<p>Body</p><script>alert(1)</script>',
                'excerpt' => "Short listing excerpt\nsecond line",
                'status' => 'publish',
                'date' => '2026-09-17T18:00:00.000Z',
                'featuredImageUrl' => null,
                'categories' => array('Garage Door Repair'),
                'tags' => array('opener'),
                'author' => 'Jamie Writer',
                'metaTitle' => 'Opener failed? Here is why',
                'metaDescription' => 'What makes a garage door opener stop.',
                'focusKeyword' => 'garage door opener',
                'canonicalUrl' => '',
                'noIndex' => false,
                'noFollow' => false,
            ),
            $overrides
        ),
    );
}

$publisher = new NexoFlow_Publisher();

// ── The fields NexoFlow sends ─────────────────────────────────────────────────
nexoflow_publisher_world();
$done = $publisher->process_job(nexoflow_app_job());
$post = $GLOBALS['nexoflow_posts'][$done['wordpressPostId']] ?? array();
nexoflow_assert(!empty($done['ok']) && count($GLOBALS['nexoflow_posts']) === 1, 'A job as NexoFlow sends it makes one post');
nexoflow_assert($done['liveUrl'] === 'https://example.com/why-the-opener-failed/', 'The answer carries the live address NexoFlow reads');
nexoflow_assert(strpos($post['post_content'], '<script') === false && strpos($post['post_content'], '<p>Body</p>') !== false, 'The content is cleaned');
nexoflow_assert($post['post_excerpt'] === "Short listing excerpt\nsecond line", 'The excerpt keeps its line break');
nexoflow_assert($post['post_date_gmt'] === '2026-09-17 18:00:00' && !empty($post['edit_date']), 'The date NexoFlow sends is the post date');
nexoflow_assert((int) $post['post_author'] === 2, 'A named author who may publish writes the post');
nexoflow_assert(($post['meta']['_nexoflow_seo_title'] ?? '') === 'Opener failed? Here is why', 'The search title is kept');
nexoflow_assert(($post['meta']['_nexoflow_seo_description'] ?? '') === 'What makes a garage door opener stop.', 'The search description is kept');
nexoflow_assert(($post['meta']['_yoast_wpseo_focuskw'] ?? '') === 'garage door opener', 'The focus keyword goes to the field SEO plugins read');
nexoflow_assert(!isset($post['meta']['yoast_wpseo_title']) && !isset($post['meta']['_nexoflow_seo_noindex']), 'Nothing is written under the wrong names, and no noindex is set');

// An update with empty search fields keeps what is there, and never changes the author.
$again = nexoflow_app_job(array('title' => 'Why the opener failed (updated)', 'metaTitle' => '', 'metaDescription' => '', 'author' => 'Site Owner'));
$second = $publisher->process_job($again);
$post = $GLOBALS['nexoflow_posts'][$done['wordpressPostId']];
nexoflow_assert($second['wordpressPostId'] === $done['wordpressPostId'] && count($GLOBALS['nexoflow_posts']) === 1, 'A re-sent job updates the same post');
nexoflow_assert($post['post_title'] === 'Why the opener failed (updated)', 'The update changes the title');
nexoflow_assert($post['meta']['_nexoflow_seo_title'] === 'Opener failed? Here is why', 'An empty search title does not clear the one written');
nexoflow_assert((int) $post['post_author'] === 2, 'An update keeps the author');

// A post NexoFlow knows by its id is updated even under a new address.
$moved = $publisher->process_job(nexoflow_app_job(array('slug' => 'opener-not-working', 'wordpressPostId' => $done['wordpressPostId'])));
nexoflow_assert($moved['wordpressPostId'] === $done['wordpressPostId'] && count($GLOBALS['nexoflow_posts']) === 1, 'A new address updates the same post when NexoFlow sends its id');

// A post published before this plugin (no NexoFlow fields on it) is found by its address, not copied.
nexoflow_publisher_world();
$old = wp_insert_post(array('post_type' => 'post', 'post_status' => 'publish', 'post_name' => 'why-the-opener-failed', 'post_title' => 'Old'));
$found = $publisher->process_job(nexoflow_app_job());
nexoflow_assert($found['wordpressPostId'] === $old && count($GLOBALS['nexoflow_posts']) === 1, 'A post from the earlier plugin is updated, not duplicated');

// An image with the same name is never taken for the post.
nexoflow_publisher_world();
$image_id = wp_insert_post(array('post_type' => 'attachment', 'post_status' => 'inherit', 'post_name' => 'why-the-opener-failed', 'post_title' => 'Photo'));
$beside = $publisher->process_job(nexoflow_app_job());
nexoflow_assert($beside['wordpressPostId'] !== $image_id && $GLOBALS['nexoflow_posts'][$image_id]['post_type'] === 'attachment' && $GLOBALS['nexoflow_posts'][$image_id]['post_status'] === 'inherit', 'An image with the same name stays an image');
wp_insert_post(array('post_type' => 'attachment', 'post_status' => 'inherit', 'post_name' => 'only-an-image'));
$down = $publisher->process_job(array('id' => 'job_9', 'action' => 'unpublish', 'idempotencyKey' => 'unpublish:only-an-image', 'post' => array('slug' => 'only-an-image')));
nexoflow_assert($down['wordpressPostId'] === null, 'Taking a post down never touches an image with its name');

// noindex: on and off as the job says, but off never undoes a setting made on the site.
nexoflow_publisher_world();
$hidden = $publisher->process_job(nexoflow_app_job(array('noIndex' => true)));
$hid = $hidden['wordpressPostId'];
nexoflow_assert(($GLOBALS['nexoflow_posts'][$hid]['meta']['_nexoflow_seo_noindex'] ?? '') === '1', 'A job can keep a post out of search');
$publisher->process_job(nexoflow_app_job(array('noIndex' => false)));
nexoflow_assert(!isset($GLOBALS['nexoflow_posts'][$hid]['meta']['_nexoflow_seo_noindex']), 'And bring it back');
update_post_meta($hid, '_nexoflow_seo_noindex', '1');
$publisher->process_job(nexoflow_app_job(array('noIndex' => false)));
nexoflow_assert(($GLOBALS['nexoflow_posts'][$hid]['meta']['_nexoflow_seo_noindex'] ?? '') === '1', 'A noindex set on the site is left alone');

// A backslash in the post survives.
nexoflow_publisher_world();
$slashed = $publisher->process_job(nexoflow_app_job(array('content' => '<p>C:\\Program Files\\App</p>')));
nexoflow_assert(strpos($GLOBALS['nexoflow_posts'][$slashed['wordpressPostId']]['post_content'], 'C:\\Program Files\\App') !== false, 'Backslashes in the content are kept');

// The author: someone who may not publish is never used; then the account NexoFlow acts as.
nexoflow_publisher_world();
$GLOBALS['nexoflow_options']['nexoflow_agent_user'] = 1;
$by_reader = $publisher->process_job(nexoflow_app_job(array('author' => 'Mark Reader')));
nexoflow_assert((int) $GLOBALS['nexoflow_posts'][$by_reader['wordpressPostId']]['post_author'] === 1, 'A named subscriber is not made the author');

// The 2.0.0 nested shape still works.
nexoflow_publisher_world();
$nested = $publisher->process_job(
    array(
        'id' => 'job_2',
        'action' => 'upsert',
        'idempotencyKey' => 'proj:nested',
        'post' => array(
            'title' => 'Nested',
            'slug' => 'nested',
            'content' => '<p>x</p>',
            'status' => 'draft',
            'dateGmt' => '2026-09-17T18:00:00Z',
            'seo' => array('metaTitle' => 'Nested title', 'noIndex' => true),
        ),
    )
);
$post = $GLOBALS['nexoflow_posts'][$nested['wordpressPostId']];
nexoflow_assert($post['meta']['_nexoflow_seo_title'] === 'Nested title' && $post['meta']['_nexoflow_seo_noindex'] === '1', 'The nested search fields are read too');

// Taking down a post that is already gone finishes the job.
$gone = $publisher->process_job(array('id' => 'job_3', 'action' => 'unpublish', 'idempotencyKey' => 'unpublish:gone', 'post' => array('slug' => 'gone')));
nexoflow_assert(!empty($gone['ok']) && $gone['wordpressPostId'] === null, 'Unpublishing a post that is not there is done, not failed');

// A job with no address is refused.
$no_slug = $publisher->process_job(array('id' => 'job_4', 'action' => 'upsert', 'post' => array('title' => 'x', 'content' => 'y')));
nexoflow_assert(empty($no_slug['ok']), 'A job without an address is refused');

// ── The featured image ────────────────────────────────────────────────────────
nexoflow_publisher_world();
$image = 'https://cdn.example.com/proj/door.webp';
$GLOBALS['nexoflow_http_answers'][$image] = array('code' => 200, 'body' => 'RIFF-image-bytes');
$with_image = $publisher->process_job(nexoflow_app_job(array('featuredImageUrl' => $image)));
$post = $GLOBALS['nexoflow_posts'][$with_image['wordpressPostId']];
nexoflow_assert(!empty($post['meta']['_thumbnail_id']) && !isset($with_image['warning']), 'featuredImageUrl becomes the featured image');
nexoflow_assert(($post['meta']['_nexoflow_featured_image_url'] ?? '') === $image, 'The image address is kept with the post');
nexoflow_assert($GLOBALS['nexoflow_http_requests'][0]['args']['redirection'] === 0 && $GLOBALS['nexoflow_http_requests'][0]['args']['limit_response_size'] === NexoFlow_Publisher::MAX_IMAGE_BYTES, 'The download follows no redirect by itself and has a size limit');
$publisher->process_job(nexoflow_app_job(array('featuredImageUrl' => $image)));
nexoflow_assert(count($GLOBALS['nexoflow_http_requests']) === 1 && count($GLOBALS['nexoflow_uploads']) === 1, 'The same image is not downloaded or stored twice');

// A redirect to an address inside the network is never fetched.
nexoflow_publisher_world();
$GLOBALS['nexoflow_http_answers'][$image] = array('code' => 302, 'headers' => array('location' => 'http://169.254.169.254/latest/meta-data'));
$blocked = $publisher->process_job(nexoflow_app_job(array('featuredImageUrl' => $image)));
nexoflow_assert(count($GLOBALS['nexoflow_http_requests']) === 1 && $blocked['warning'] === 'Post published without featured image.', 'A redirect to a link-local address is not followed');

nexoflow_publisher_world();
$GLOBALS['nexoflow_http_answers'][$image] = array('code' => 301, 'headers' => array('location' => 'https://cdn2.example.com/door.webp'));
$GLOBALS['nexoflow_http_answers']['https://cdn2.example.com/door.webp'] = array('code' => 200, 'body' => 'RIFF-image-bytes');
$followed = $publisher->process_job(nexoflow_app_job(array('featuredImageUrl' => $image)));
nexoflow_assert(count($GLOBALS['nexoflow_http_requests']) === 2 && !isset($followed['warning']), 'A redirect to another public https address is checked and followed');

nexoflow_publisher_world();
$GLOBALS['nexoflow_http_answers'][$image] = array('code' => 200, 'body' => str_repeat('x', NexoFlow_Publisher::MAX_IMAGE_BYTES + 10));
$too_big = $publisher->process_job(nexoflow_app_job(array('featuredImageUrl' => $image)));
nexoflow_assert(isset($too_big['warning']) && empty($GLOBALS['nexoflow_uploads']), 'An image over the size limit is not kept');

// ── The sync: one at a time, the change-notice key, and the ack ───────────────
nexoflow_publisher_world();
$valid = 'nfwp_' . str_repeat('ab', 24);
$GLOBALS['nexoflow_options']['nexoflow_site_key'] = $valid;
$GLOBALS['nexoflow_api_answers'] = array(
    array('code' => 200, 'body' => json_encode(array('ok' => true, 'jobs' => array(nexoflow_app_job()), 'indexNowKey' => str_repeat('a', 32)))),
);
$cron = new NexoFlow_Cron();
$synced = $cron->sync();
$requests = $GLOBALS['nexoflow_api_requests'];
$ack = json_decode($requests[1]['args']['body'] ?? '{}', true);
nexoflow_assert(!empty($synced['ok']) && $synced['processed'] === 1, 'The sync applies the job');
nexoflow_assert($requests[0]['args']['redirection'] === 0, 'Calls carrying the key follow no redirect');
nexoflow_assert(($ack['version'] ?? '') === '2026-11-15T10:00:00.000Z' && ($ack['liveUrl'] ?? '') !== '', 'The ack names the copy it applied and its live address');
nexoflow_assert(NexoFlow_IndexNow::stored_key() === str_repeat('a', 32), 'The change-notice key that comes with the jobs is kept');
nexoflow_assert(!array_key_exists(NexoFlow_Cron::LOCK_OPTION, $GLOBALS['nexoflow_options']), 'The sync lets go of its lock');

$GLOBALS['nexoflow_options'][NexoFlow_Cron::LOCK_OPTION] = time() + 100;
$GLOBALS['nexoflow_api_requests'] = array();
$busy = $cron->sync();
nexoflow_assert(!empty($busy['busy']) && empty($GLOBALS['nexoflow_api_requests']), 'A second sync while one runs does nothing');
$GLOBALS['nexoflow_options'][NexoFlow_Cron::LOCK_OPTION] = time() - 1;
$stale = $cron->sync();
nexoflow_assert(empty($stale['busy']), 'A lock left by a run that died runs out');

// ── A key NexoFlow no longer accepts ──────────────────────────────────────────
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $valid;
nexoflow_assert(NexoFlow_Auth::check() === NexoFlow_Auth::OK, 'The key is accepted while NexoFlow accepts it');
$GLOBALS['nexoflow_api_answers'] = array(array('code' => 401, 'body' => '{"ok":false,"error":"Invalid site connect key."}'));
$cron->sync();
nexoflow_assert(NexoFlow_Auth::check() === NexoFlow_Auth::INVALID, 'Once NexoFlow answers 401 for it, the site refuses the key too');
$GLOBALS['nexoflow_api_answers'] = array(array('code' => 200, 'body' => '{"ok":true,"jobs":[]}'));
$cron->sync();
nexoflow_assert(NexoFlow_Auth::check() === NexoFlow_Auth::OK, 'A call with the key that works again lifts that');
$GLOBALS['nexoflow_api_answers'] = array(array('code' => 401, 'body' => '<html>proxy</html>'));
$cron->sync();
nexoflow_assert(NexoFlow_Auth::check() === NexoFlow_Auth::OK, 'A 401 that is not NexoFlow\'s own answer does not revoke the key');
$GLOBALS['nexoflow_api_answers'] = array(array('code' => 302, 'headers' => array('location' => 'http://elsewhere.example/'), 'body' => ''));
$redirected = $cron->sync();
nexoflow_assert(is_wp_error($redirected) && count($GLOBALS['nexoflow_api_requests']) > 0, 'A redirect from the API is an error, not followed');
unset($_SERVER['HTTP_AUTHORIZATION']);
