<?php

require_once dirname(__DIR__) . '/nexoflow/includes/class-seo-markup.php';
require_once dirname(__DIR__) . '/nexoflow/includes/class-page-cache.php';

if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1)
    {
        return parse_url($url, $component);
    }
}

nexoflow_section('Search: the finished page');

$page = function ($body, $head = '<title>T</title><meta name="viewport" content="width=device-width">') {
    return '<!doctype html><html lang="en"><head>' . $head . '</head><body>' . $body . '</body></html>';
};
$h1s = function ($html) {
    return preg_match_all('#<h1[\s>]#i', $html);
};

$fixed = NexoFlow_SEO_Markup::fix_headings($page('<h2 class="a b">The Real Headline</h2><p>x</p><h2>Other</h2>'));
nexoflow_assert($h1s($fixed) === 1, 'A page with no main heading gets one');
nexoflow_assert(strpos($fixed, '<h1 class="a b">The Real Headline</h1>') !== false, 'The first h2 becomes it, with its attributes and words');
nexoflow_assert(strpos($fixed, '<h2>Other</h2>') !== false, 'The other headings stay');
nexoflow_assert(strpos($fixed, 'createElement("h2")') !== false, 'It keeps the look of what it was');

$same = $page('<h1>Only</h1><h2>Sub</h2>');
nexoflow_assert(NexoFlow_SEO_Markup::fix_headings($same) === $same, 'A page with one main heading is left exactly as it was');

$fixed = NexoFlow_SEO_Markup::fix_headings($page('<h1>One</h1><h1 id="two">Two</h1><h1>Three</h1>'));
nexoflow_assert($h1s($fixed) === 1, 'A page with several ends up with one');
nexoflow_assert(strpos($fixed, '<h1>One</h1>') !== false, 'The first one stays');
nexoflow_assert(strpos($fixed, '<h2 id="two">Two</h2>') !== false, 'The others become h2');

$fixed = NexoFlow_SEO_Markup::fix_headings($page('<header><h2>Site Name</h2></header><main><h3>Label</h3><h2>Page Topic</h2></main><footer><h2>Links</h2></footer>'));
nexoflow_assert(strpos($fixed, '<h1>Page Topic</h1>') !== false, 'The heading comes from the page\'s own content, not its header');
nexoflow_assert(strpos($fixed, '<h2>Site Name</h2>') !== false && strpos($fixed, '<h2>Links</h2>') !== false, 'The header and footer stay as they were');

$fixed = NexoFlow_SEO_Markup::fix_headings($page('<footer><h2>Links</h2></footer>'));
nexoflow_assert($h1s($fixed) === 0, 'A footer heading is never made the main one');

$fixed = NexoFlow_SEO_Markup::fix_headings($page('<script>var a="<h1>not a heading</h1>";</script><!-- <h1>nor this</h1> --><h2>Real</h2>'));
nexoflow_assert(strpos($fixed, '<h1>Real</h1>') !== false, 'Headings inside scripts and comments do not count');
nexoflow_assert(strpos($fixed, 'var a="<h1>not a heading</h1>"') !== false, 'Scripts come back untouched');

$fixed = NexoFlow_SEO_Markup::fix_headings($page('<h2>   </h2><h2>Words</h2>'));
nexoflow_assert(strpos($fixed, '<h1>Words</h1>') !== false, 'An empty heading is passed over');

nexoflow_assert(NexoFlow_SEO_Markup::fix_headings('{"not":"html"}') === '{"not":"html"}', 'Something that is not a page is left alone');
nexoflow_assert(NexoFlow_SEO_Markup::fix_headings('') === '', 'An empty answer stays empty');

$no_viewport = '<html lang="en"><head><title>T</title></head><body></body></html>';
nexoflow_assert(strpos(NexoFlow_SEO_Markup::ensure_viewport($no_viewport), 'name="viewport"') !== false, 'A page with no viewport gets one');
$has = $page('');
nexoflow_assert(NexoFlow_SEO_Markup::ensure_viewport($has) === $has, 'A page with one keeps it');
nexoflow_assert(strpos(NexoFlow_SEO_Markup::ensure_language('<html><head></head></html>', 'en-US'), '<html lang="en-US"') === 0, 'A page with no language gets one');
nexoflow_assert(NexoFlow_SEO_Markup::ensure_language($has, 'de') === $has, 'A page with a language keeps it');
nexoflow_assert(strpos(NexoFlow_SEO_Markup::ensure_language('<html><head></head></html>', '"><x'), '"><x') === false, 'A language is never written as markup');
nexoflow_assert(strpos(NexoFlow_SEO_Markup::ensure_title('<html><head></head></html>', 'A & B'), '<title>A &amp; B</title>') !== false, 'A page with no title gets one, escaped');
nexoflow_assert(NexoFlow_SEO_Markup::ensure_title($has, 'Other') === $has, 'A page with a title keeps it');

nexoflow_section('Search: descriptions from a page\'s own words');

$text = NexoFlow_SEO_Markup::describe('<h2>Spring Repair</h2><p>We fix broken springs fast across Sudbury and nearby towns every day.</p>');
nexoflow_assert($text === 'Spring Repair. We fix broken springs fast across Sudbury and nearby towns every day.', 'A heading ends before the sentence after it');
nexoflow_assert(NexoFlow_SEO_Markup::describe('<h2>Need help?</h2><p>We fix broken springs fast across Sudbury and nearby towns.</p>') === 'Need help? We fix broken springs fast across Sudbury and nearby towns.', 'A heading with its own ending keeps it');
nexoflow_assert(NexoFlow_SEO_Markup::describe('Too short') === '', 'Too little text gives no description');
nexoflow_assert(NexoFlow_SEO_Markup::describe('<!-- wp:paragraph --><script>x()</script><p>Words</p>') === '', 'Block comments and scripts are not words');
$long = NexoFlow_SEO_Markup::describe(str_repeat('We repair doors. ', 30));
nexoflow_assert(NexoFlow_SEO_Markup::length($long) <= 155 && substr($long, -1) === '.', 'A long page is cut at the end of a sentence');
$long = NexoFlow_SEO_Markup::describe(str_repeat('word ', 80));
nexoflow_assert(NexoFlow_SEO_Markup::length($long) <= 156 && substr($long, -3) === '…', 'With no sentence end it is cut at a word');
nexoflow_assert(NexoFlow_SEO_Markup::describe('Caf&eacute; &amp; doors repaired across Sudbury and all of the nearby towns.') === 'Café & doors repaired across Sudbury and all of the nearby towns.', 'Entities become the characters they stand for');
nexoflow_assert(NexoFlow_SEO_Markup::first_image('<p>x</p><img alt="a" src="https://e.example/a.png">') === 'https://e.example/a.png', 'The first picture is found');
nexoflow_assert(NexoFlow_SEO_Markup::first_image('<p>none</p>') === '', 'No picture gives nothing');

nexoflow_section('Search: the page cache');

$request = function ($uri, $method = 'GET', $cookies = array()) {
    return NexoFlow_Page_Cache::request_can_be_cached(array('method' => $method, 'uri' => $uri, 'cookies' => $cookies));
};
nexoflow_assert($request('/services/') === true, 'A page can be kept');
nexoflow_assert($request('/') === true, 'The homepage can be kept');
nexoflow_assert($request('/services/?utm_source=mail&gclid=1') === true, 'A campaign link is the same page');
nexoflow_assert($request('/services/?s=doors') === false, 'A search is not kept');
nexoflow_assert($request('/services/?utm_source=mail&add-to-cart=1') === false, 'One other parameter is enough to skip it');
nexoflow_assert($request('/services/', 'POST') === false, 'A form post is not kept');
nexoflow_assert($request('/wp-json/nexoflow/v1/site/capabilities') === false, 'The REST API is not kept');
nexoflow_assert($request('/wp-admin/') === false, 'Admin is not kept');
nexoflow_assert($request('/wp-login.php') === false, 'The login page is not kept');
nexoflow_assert($request('/feed/') === false, 'A feed is not kept');
nexoflow_assert($request('/llms.txt') === false && $request('/wp-sitemap.xml') === false && $request('/robots.txt') === false, 'Files are not kept');
nexoflow_assert($request('/page.html') === true, 'An address ending in .html is a page');
nexoflow_assert($request('/', 'GET', array('wordpress_logged_in_abc' => 'x')) === false, 'Someone signed in is never served a copy');
nexoflow_assert($request('/', 'GET', array('woocommerce_items_in_cart' => '1')) === false, 'Someone with a cart is never served a copy');
nexoflow_assert($request('/', 'GET', array('_ga' => '1')) === true, 'A visitor with a tracking cookie is');

$body = '<html>' . str_repeat('x', 600) . '</html>';
nexoflow_assert(NexoFlow_Page_Cache::worth_keeping($body, 200, array('Content-Type: text/html'), false) === true, 'A finished page is kept');
nexoflow_assert(NexoFlow_Page_Cache::worth_keeping($body, 500, array(), false) === false, 'A failed page is not');
nexoflow_assert(NexoFlow_Page_Cache::worth_keeping($body, 200, array('Set-Cookie: a=b'), false) === false, 'A page that sets a cookie is not');
nexoflow_assert(NexoFlow_Page_Cache::worth_keeping($body, 200, array('Content-Type: application/json'), false) === false, 'Something that is not a page is not');
nexoflow_assert(NexoFlow_Page_Cache::worth_keeping($body, 200, array(), true) === false, 'A page that says not to keep it is not');
nexoflow_assert(NexoFlow_Page_Cache::worth_keeping('<html>short</html>', 200, array(), false) === false, 'An almost empty page is not');
nexoflow_assert(NexoFlow_Page_Cache::worth_keeping(str_repeat('x', 900), 200, array(), false) === false, 'A page cut off before its end is not');
nexoflow_assert(NexoFlow_Page_Cache::key('E.example', '/a/', true, false) === NexoFlow_Page_Cache::key('e.example', '/a', true, false), 'One page has one key');
nexoflow_assert(NexoFlow_Page_Cache::key('e.example', '/a', true, true) !== NexoFlow_Page_Cache::key('e.example', '/a', true, false), 'Phones get their own copy');

if (!function_exists('is_ssl')) {
    function is_ssl()
    {
        return true;
    }
}

if (!function_exists('wp_is_mobile')) {
    function wp_is_mobile()
    {
        return false;
    }
}

// A request that does not say which page it is has no copy to read or write.
$transient = Closure::bind(
    function () {
        return self::transient();
    },
    null,
    'NexoFlow_Page_Cache'
);
$server = $_SERVER;
unset($_SERVER['REQUEST_URI'], $_SERVER['HTTP_HOST']);
nexoflow_assert($transient() === '', 'A request with no address has no copy');
$_SERVER['REQUEST_URI'] = '/a';
nexoflow_assert($transient() === '', 'Nor does one with no host');
$_SERVER['HTTP_HOST'] = 'e.example';
nexoflow_assert(strpos($transient(), NexoFlow_Page_Cache::PREFIX) === 0, 'One with both does');
$_SERVER = $server;
