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
// Host lookups are answered here, so the tests never use the network.
$GLOBALS['nexoflow_filters']['nexoflow_image_host_addresses'] = function () {
    return $GLOBALS['nexoflow_test_host_addresses'];
};
$GLOBALS['nexoflow_test_host_addresses'] = array('93.184.216.34');
nexoflow_assert(NexoFlow_Publisher::is_safe_image_url('https://example.com/image.webp') === true, 'Accepts public https image URL');
$GLOBALS['nexoflow_test_host_addresses'] = array('93.184.216.34', '10.0.0.5');
nexoflow_assert(NexoFlow_Publisher::is_safe_image_url('https://example.com/image.webp') === false, 'Rejects a host with any private address');
$GLOBALS['nexoflow_test_host_addresses'] = array('2606:4700::1111', 'fd00::1');
nexoflow_assert(NexoFlow_Publisher::is_safe_image_url('https://example.com/image.webp') === false, 'Rejects a host with a unique-local IPv6 address');
$GLOBALS['nexoflow_test_host_addresses'] = array();
nexoflow_assert(NexoFlow_Publisher::is_safe_image_url('https://example.com/image.webp') === false, 'Rejects a host that does not resolve');
$GLOBALS['nexoflow_test_host_addresses'] = array('93.184.216.34');
nexoflow_assert(NexoFlow_Publisher::is_safe_image_url('https://example.com:8443/image.webp') === false, 'Rejects an image on another port');
nexoflow_assert(NexoFlow_Publisher::is_safe_image_url('https://user:pass@example.com/image.webp') === false, 'Rejects an image address with a login');
nexoflow_assert(NexoFlow_Publisher::is_safe_image_url('https://[::1]/image.webp') === false, 'Rejects an IPv6 loopback image URL');

$valid_key = 'nfwp_0123456789abcdef0123456789abcdef0123456789abcd25';
nexoflow_assert(NexoFlow_Client::is_valid_site_key($valid_key) === true, 'Accepts nfwp_ plus 48 hex chars');
nexoflow_assert(NexoFlow_Client::is_valid_site_key('a') === false, 'Rejects a one-letter key');
nexoflow_assert(NexoFlow_Client::is_valid_site_key('') === false, 'Rejects an empty key');
nexoflow_assert(NexoFlow_Client::is_valid_site_key('nfwp_0123456789abcdef0123456789abcdef0123456789abcd2') === false, 'Rejects a short key');
nexoflow_assert(NexoFlow_Client::is_valid_site_key('pk_live_not_a_plugin_key_xxxxxxxxxxxx') === false, 'Rejects a Content API key');
nexoflow_assert(NexoFlow_Client::is_valid_site_key('nfwp_0123456789abcdef0123456789abcdef0123456789abcd2 ') === false, 'Rejects a key with a space');
nexoflow_assert(NexoFlow_Client::is_valid_site_key('nfwp_0123456789abcdef0123456789abcdef0123456789abcdz5') === false, 'Rejects a non-hex key');
nexoflow_assert(NexoFlow_Client::sanitize_api_base('') === 'https://nexoflow.net', 'Empty API base uses production');
nexoflow_assert(NexoFlow_Client::sanitize_api_base('https://abc.ngrok.io/') === 'https://abc.ngrok.io', 'Tunnel API base is kept');
nexoflow_assert(NexoFlow_Client::sanitize_api_base('http://example.com') === '', 'Rejects http API base');
nexoflow_assert(NexoFlow_Client::api_base() === 'https://nexoflow.net', 'Default API base is production');

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $valid_key;
nexoflow_assert(NexoFlow_Client::request_bearer_token() === $valid_key, 'Reads a Bearer token');
$_SERVER['HTTP_AUTHORIZATION'] = 'Basic nope';
nexoflow_assert(NexoFlow_Client::request_bearer_token() === '', 'Rejects a non-Bearer header');
unset($_SERVER['HTTP_AUTHORIZATION']);
$_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = "  Bearer " . $valid_key . " \n";
nexoflow_assert(NexoFlow_Client::request_bearer_token() === $valid_key, 'Reads a Bearer token a server passed on under its other name, cleaned of stray whitespace');
unset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer <script>alert(1)</script>';
nexoflow_assert(!NexoFlow_Client::is_valid_site_key(NexoFlow_Client::request_bearer_token()) && strpos(NexoFlow_Client::request_bearer_token(), '<') === false, 'A header carrying markup is cleaned and is never a key');
$_SERVER['HTTP_AUTHORIZATION'] = addslashes("Bearer it's\\not");
nexoflow_assert(NexoFlow_Client::request_bearer_token() === "it's\\not", 'The header is read without the slashes WordPress adds to it');
unset($_SERVER['HTTP_AUTHORIZATION']);

$health = array(
    'ok' => true,
    'project' => array(
        'name' => 'Garage',
        'websiteUrl' => 'https://summit.example',
        'wordpressUrl' => 'https://summit.example',
        'email' => 'hidden@example.com',
        'billing' => 'do-not-store',
    ),
);
$card = NexoFlow_Client::project_from_health($health);
nexoflow_assert($card['name'] === 'Garage', 'Health card keeps the project name');
nexoflow_assert($card['websiteUrl'] === 'https://summit.example', 'Health card keeps the website URL');
nexoflow_assert($card['wordpressUrl'] === 'https://summit.example', 'Health card keeps the WordPress URL');
nexoflow_assert(!isset($card['email']) && !isset($card['billing']), 'Health card drops unsafe fields');
nexoflow_assert(NexoFlow_Client::project_from_health(array('ok' => false, 'error' => 'Invalid site connect key.')) === array(), 'Rejected health has no project card');
$split = NexoFlow_Client::project_from_health(
    array(
        'ok' => true,
        'project' => array(
            'name' => 'Garage',
            'websiteUrl' => 'https://summit.example',
            'wordpressUrl' => 'https://wp.summit.example',
        ),
    )
);
nexoflow_assert($split['websiteUrl'] !== $split['wordpressUrl'], 'Different site URLs stay separate');

// A site updated from NexoFlow's own download keeps its key under the old option: it is moved across on first read.
$saved_options = $GLOBALS['nexoflow_options'];
$GLOBALS['nexoflow_options'] = array('nexoflow_site_connect_key' => ' ' . $valid_key . ' ');
nexoflow_assert(NexoFlow_Client::site_key() === $valid_key, 'A key from the old download is read after the update');
nexoflow_assert(($GLOBALS['nexoflow_options']['nexoflow_site_key'] ?? '') === $valid_key, 'It is saved under the current option');
nexoflow_assert(!array_key_exists('nexoflow_site_connect_key', $GLOBALS['nexoflow_options']), 'The old option is removed once moved');
nexoflow_assert(NexoFlow_Client::has_site_key() === true, 'The site counts as connected');

$GLOBALS['nexoflow_options'] = array('nexoflow_site_connect_key' => 'not-a-key');
nexoflow_assert(NexoFlow_Client::site_key() === '', 'A malformed old key is not moved');
nexoflow_assert(!array_key_exists('nexoflow_site_key', $GLOBALS['nexoflow_options']), 'Nothing is saved for it');

$other_key = 'nfwp_' . str_repeat('ab', 24);
$GLOBALS['nexoflow_options'] = array('nexoflow_site_key' => $other_key, 'nexoflow_site_connect_key' => $valid_key);
nexoflow_assert(NexoFlow_Client::site_key() === $other_key, 'A key saved in this version wins over the old one');

$GLOBALS['nexoflow_options'] = array();
nexoflow_assert(NexoFlow_Client::site_key() === '', 'With neither, there is no key');
$GLOBALS['nexoflow_options'] = $saved_options;

require __DIR__ . '/agent.php';
require __DIR__ . '/site-data.php';
require __DIR__ . '/indexnow.php';
require __DIR__ . '/bing.php';
require __DIR__ . '/seo.php';
require __DIR__ . '/publisher.php';

if ($failed > 0) {
    echo "\n{$failed} failed\n";
    exit(1);
}

echo "\nAll tests passed\n";
exit(0);
