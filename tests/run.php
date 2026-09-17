<?php

require_once __DIR__ . '/stubs.php';
require_once dirname(__DIR__) . '/nexoflow/includes/class-client.php';
require_once dirname(__DIR__) . '/nexoflow/includes/class-publisher.php';

$failed = 0;

function nexoflow_assert($condition, $message)
{
    global $failed;

    if ($condition) {
        echo "OK  {$message}\n";

        return;
    }

    $failed++;
    echo "FAIL  {$message}\n";
}

$map = NexoFlow_Publisher::seo_meta_map(
    array(
        'metaTitle' => 'Why the opener failed',
        'metaDescription' => 'Short listing excerpt',
        'focusKeyword' => 'garage door opener',
        'canonicalUrl' => '',
        'noIndex' => false,
        'noFollow' => true,
    )
);

nexoflow_assert($map['yoast_wpseo_title'] === 'Why the opener failed', 'SEO map writes title');
nexoflow_assert($map['yoast_wpseo_metadesc'] === 'Short listing excerpt', 'SEO map writes description');
nexoflow_assert($map['yoast_wpseo_focuskw'] === 'garage door opener', 'SEO map writes focus keyword');
nexoflow_assert($map['yoast_wpseo_meta-robots-noindex'] === '0', 'SEO map writes noindex off');
nexoflow_assert($map['yoast_wpseo_meta-robots-nofollow'] === '1', 'SEO map writes nofollow on');
nexoflow_assert($map['rank_math_title'] === 'Why the opener failed', 'SEO map writes rank math title');
nexoflow_assert($map['rank_math_robots'] === array('index', 'nofollow'), 'SEO map writes rank math robots');
nexoflow_assert($map['_aioseo_title'] === 'Why the opener failed', 'SEO map writes AIO title');
nexoflow_assert($map['_aioseo_keywords'] === 'garage door opener', 'SEO map writes AIO keywords');

$publisher = new NexoFlow_Publisher();
nexoflow_reset_store();
$term_ids = $publisher->assign_terms(0, array('Garage Door Repair'), 'category');
nexoflow_assert(count($term_ids) === 1, 'Category name creates one term');
$again = $publisher->assign_terms(0, array('Garage Door Repair'), 'category');
nexoflow_assert($again === $term_ids, 'Category name reuses the same term');
nexoflow_assert(count($GLOBALS['nexoflow_terms']['category']) === 1, 'Category name does not duplicate terms');

nexoflow_assert(NexoFlow_Publisher::is_safe_image_url('http://example.com/image.webp') === false, 'Rejects http image URL');
nexoflow_assert(NexoFlow_Publisher::is_safe_image_url('https://127.0.0.1/image.webp') === false, 'Rejects loopback image URL');
nexoflow_assert(NexoFlow_Publisher::is_safe_image_url('https://10.1.2.3/image.webp') === false, 'Rejects 10.x image URL');
nexoflow_assert(NexoFlow_Publisher::is_safe_image_url('https://192.168.1.10/image.webp') === false, 'Rejects 192.168 image URL');
nexoflow_assert(NexoFlow_Publisher::is_safe_image_url('https://169.254.1.1/image.webp') === false, 'Rejects link-local image URL');
nexoflow_assert(NexoFlow_Publisher::is_safe_image_url('https://localhost/image.webp') === false, 'Rejects localhost image URL');
nexoflow_assert(NexoFlow_Publisher::is_safe_image_url('https://example.com/image.webp') === true, 'Accepts public https image URL');

$valid_key = 'nfwp_7c3a91e0b2d84f16a0c5e9d1b7f3082a4e6c1d9b0a8f3e25';
nexoflow_assert(NexoFlow_Client::is_valid_site_key($valid_key) === true, 'Accepts nfwp_ plus 48 hex chars');
nexoflow_assert(NexoFlow_Client::is_valid_site_key('a') === false, 'Rejects a one-letter key');
nexoflow_assert(NexoFlow_Client::is_valid_site_key('') === false, 'Rejects an empty key');
nexoflow_assert(NexoFlow_Client::is_valid_site_key('nfwp_7c3a91e0b2d84f16a0c5e9d1b7f3082a4e6c1d9b0a8f3e2') === false, 'Rejects a short key');
nexoflow_assert(NexoFlow_Client::is_valid_site_key('pk_live_not_a_plugin_key_xxxxxxxxxxxx') === false, 'Rejects a Content API key');
nexoflow_assert(NexoFlow_Client::is_valid_site_key('nfwp_7c3a91e0b2d84f16a0c5e9d1b7f3082a4e6c1d9b0a8f3e2 ') === false, 'Rejects a key with a space');
nexoflow_assert(NexoFlow_Client::is_valid_site_key('nfwp_7c3a91e0b2d84f16a0c5e9d1b7f3082a4e6c1d9b0a8f3ez5') === false, 'Rejects a non-hex key');
nexoflow_assert(NexoFlow_Client::sanitize_api_base('') === 'https://nexoflow.net', 'Empty API base uses production');
nexoflow_assert(NexoFlow_Client::sanitize_api_base('https://app.nexoflow.net') === 'https://nexoflow.net', 'Legacy API base migrates to production');
nexoflow_assert(NexoFlow_Client::sanitize_api_base('https://abc.ngrok.io/') === 'https://abc.ngrok.io', 'Tunnel API base is kept');
nexoflow_assert(NexoFlow_Client::sanitize_api_base('http://example.com') === '', 'Rejects http API base');
nexoflow_assert(NexoFlow_Client::api_base() === 'https://nexoflow.net', 'Default API base is production');

$health = array(
    'ok' => true,
    'project' => array(
        'name' => 'Garage',
        'websiteUrl' => 'https://theknollgaragedoor.com',
        'wordpressUrl' => 'https://theknollgaragedoor.com',
        'email' => 'hidden@example.com',
        'billing' => 'do-not-store',
    ),
);
$card = NexoFlow_Client::project_from_health($health);
nexoflow_assert($card['name'] === 'Garage', 'Health card keeps the project name');
nexoflow_assert($card['websiteUrl'] === 'https://theknollgaragedoor.com', 'Health card keeps the website URL');
nexoflow_assert($card['wordpressUrl'] === 'https://theknollgaragedoor.com', 'Health card keeps the WordPress URL');
nexoflow_assert(!isset($card['email']) && !isset($card['billing']), 'Health card drops unsafe fields');
nexoflow_assert(NexoFlow_Client::project_from_health(array('ok' => false, 'error' => 'Invalid site connect key.')) === array(), 'Rejected health has no project card');
$split = NexoFlow_Client::project_from_health(
    array(
        'ok' => true,
        'project' => array(
            'name' => 'Garage',
            'websiteUrl' => 'https://theknollgaragedoor.com',
            'wordpressUrl' => 'https://wp.theknollgaragedoor.com',
        ),
    )
);
nexoflow_assert($split['websiteUrl'] !== $split['wordpressUrl'], 'Different site URLs stay separate');

nexoflow_reset_store();
$job = array(
    'id' => 'job_01HZX',
    'action' => 'upsert',
    'idempotencyKey' => 'proj_xxx:slug-of-post',
    'post' => array(
        'title' => 'Why the opener failed',
        'slug' => 'why-the-opener-failed',
        'content' => '<p>HTML or Gutenberg-safe HTML</p>',
        'excerpt' => 'Short listing excerpt',
        'status' => 'publish',
        'dateGmt' => '2026-09-17T18:00:00Z',
        'categories' => array('Garage Door Repair'),
        'tags' => array('opener', 'troubleshooting'),
        'authorLogin' => '',
        'seo' => array(
            'metaTitle' => 'Why the opener failed',
            'metaDescription' => 'Short listing excerpt',
            'focusKeyword' => 'opener',
            'canonicalUrl' => '',
            'noIndex' => false,
            'noFollow' => false,
        ),
    ),
);

$first = $publisher->process_job($job);
nexoflow_assert(!empty($first['ok']), 'First upsert succeeds');
nexoflow_assert((int) $first['wordpressPostId'] === 1, 'First upsert creates post 1');
nexoflow_assert(count($GLOBALS['nexoflow_posts']) === 1, 'First upsert stores one post');
nexoflow_assert($GLOBALS['nexoflow_posts'][1]['meta']['_nexoflow_idempotency'] === 'proj_xxx:slug-of-post', 'Stores idempotency meta');
nexoflow_assert($GLOBALS['nexoflow_posts'][1]['meta']['yoast_wpseo_title'] === 'Why the opener failed', 'Writes Yoast-shaped title');
nexoflow_assert(isset($GLOBALS['nexoflow_posts'][1]['terms']['category']), 'Assigns category terms');

$job['post']['title'] = 'Why the opener failed (updated)';
$second = $publisher->process_job($job);
nexoflow_assert(!empty($second['ok']), 'Second upsert succeeds');
nexoflow_assert((int) $second['wordpressPostId'] === 1, 'Second upsert updates the same post');
nexoflow_assert(count($GLOBALS['nexoflow_posts']) === 1, 'Second upsert does not duplicate the post');
nexoflow_assert($GLOBALS['nexoflow_posts'][1]['post_title'] === 'Why the opener failed (updated)', 'Second upsert changes the title');

if ($failed > 0) {
    echo "\n{$failed} failed\n";
    exit(1);
}

echo "\nAll tests passed\n";
exit(0);
