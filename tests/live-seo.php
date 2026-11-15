<?php
/**
 * The search layer inside a real WordPress, over real HTTP: what a page prints,
 * what its sitemap offers, where a gone address leads, what the page cache does.
 *
 * Only for a disposable site served at its own address (wp server). It refuses a
 * site that is already connected, and removes what it made.
 *
 *   NEXOFLOW_LIVE_TEST=1 wp eval-file tests/live-seo.php
 */

if (!defined('ABSPATH') || !class_exists('NexoFlow_Plugin')) {
    echo "Run this with WP-CLI on a site where the NexoFlow plugin is active.\n";

    return;
}

if (getenv('NEXOFLOW_LIVE_TEST') !== '1') {
    echo "Set NEXOFLOW_LIVE_TEST=1 to confirm this is a disposable site.\n";

    return;
}

if (NexoFlow_Client::has_site_key()) {
    echo "This site is connected to NexoFlow. The live test only runs on a site with no connect key.\n";

    return;
}

$GLOBALS['nf'] = array('ok' => 0, 'fail' => 0, 'count' => 0, 'run' => 'nfseo' . bin2hex(random_bytes(3)), 'made' => array());

function nf_ok($condition, $label)
{
    $GLOBALS['nf'][$condition ? 'ok' : 'fail']++;
    echo ($condition ? 'OK    ' : 'FAIL  ') . $label . "\n";
}

function nf_section($title)
{
    echo "\n== {$title}\n";
}

function nf_do($action, $parameters = array(), $mode = 'live')
{
    $GLOBALS['nf']['count']++;

    // Reading changes nothing, so it runs in the default mode.
    if (in_array($action, array('seo.status', 'seo.pages', 'seo.get_page', 'redirects.list', 'media.missing_alt'), true)) {
        $mode = 'draft';
    }

    return nexoflow()->agent()->dispatcher()->dispatch(
        array(
            'request_id' => $GLOBALS['nf']['run'] . '-' . $GLOBALS['nf']['count'],
            'action' => $action,
            'parameters' => $parameters,
            'mode' => $mode,
        )
    );
}

function nf_result($answer)
{
    return isset($answer['body']['result']) ? (array) $answer['body']['result'] : array();
}

function nf_code($answer)
{
    return isset($answer['body']['error']['code']) ? $answer['body']['error']['code'] : '';
}

function nf_get($url, $args = array())
{
    $response = wp_remote_get($url, $args + array('redirection' => 0, 'timeout' => 30, 'headers' => array('Cache-Control' => 'no-cache')));

    if (is_wp_error($response)) {
        return array('status' => 0, 'body' => '', 'headers' => array(), 'error' => $response->get_error_message());
    }

    return array(
        'status' => (int) wp_remote_retrieve_response_code($response),
        'body' => (string) wp_remote_retrieve_body($response),
        'location' => (string) wp_remote_retrieve_header($response, 'location'),
        'cache' => (string) wp_remote_retrieve_header($response, 'x-nexoflow-cache'),
    );
}

function nf_page($title, $content, $type = 'page', $extra = array())
{
    $id = wp_insert_post(
        $extra + array(
            'post_type' => $type,
            'post_status' => 'publish',
            'post_title' => $title,
            'post_name' => sanitize_title($GLOBALS['nf']['run'] . '-' . $title),
            'post_content' => $content,
            'post_author' => 1,
        )
    );
    $GLOBALS['nf']['made'][] = (int) $id;

    return (int) $id;
}

function nf_count($needle, $html)
{
    return substr_count($html, $needle);
}

function nf_tag($html, $pattern)
{
    return preg_match($pattern, $html, $match) ? html_entity_decode($match[1], ENT_QUOTES, 'UTF-8') : null;
}

function nf_description($html)
{
    return nf_tag($html, '#<meta name="description" content="([^"]*)"#');
}

function nf_title($html)
{
    return nf_tag($html, '#<title[^>]*>([^<]*)</title>#');
}

function nf_h1s($html)
{
    return preg_match_all('#<h1[\s>]#i', $html);
}

$other = NexoFlow_SEO::other_plugins();
$home = home_url('/');
$key = 'nfwp_' . bin2hex(random_bytes(24));

nf_section('Not connected: nothing is printed');
$long_text = str_repeat('Our team repairs broken garage door springs across Sudbury and nearby towns every single day of the week. ', 3);
$plain = nf_page('Plain Page', '<p>' . $long_text . '</p>');
$before = nf_get(get_permalink($plain));
nf_ok($before['status'] === 200, 'The page opens');

if (empty($other)) {
    nf_ok(nf_description($before['body']) === null, 'No description from the plugin before it is connected');
    nf_ok(nf_count('property="og:', $before['body']) === 0, 'No link preview tags before it is connected');
}


update_option('nexoflow_site_key', $key);
nexoflow()->agent()->actor()->set(1);
nexoflow()->agent()->store()->maybe_install();

if (!empty($other)) {
    nf_section('Another SEO plugin is in charge: ' . implode(', ', $other));
    $page = get_post($plain);
    $html = nf_get(get_permalink($plain))['body'];
    nf_ok(strpos($html, 'class="nexoflow-page-data"') === false, 'NexoFlow prints no structured data of its own');
    nf_ok(strpos($html, 'NexoFlow') === false || nf_count('name="description"', $html) <= 1, 'Never two descriptions');
    $written = 'Written through the agent and shown by the other plugin in the listing here.';
    $answer = nf_do('seo.update_page', array('page_id' => $plain, 'title' => 'Written Title For The Page', 'description' => $written));
    nf_ok($answer['status'] === 200, 'The change is accepted');
    $html = nf_get(get_permalink($plain))['body'];
    nf_ok(nf_count('name="description"', $html) === 1, 'One description on the page');
    nf_ok(strpos($html, $written) !== false, 'The other plugin prints what was written');
    nf_ok(strpos((string) nf_title($html), 'Written Title For The Page') !== false, 'The other plugin prints the title that was written');
    $status = nf_result(nf_do('seo.status'));
    nf_ok($status['tags_by'] === $other[0], 'The status says who prints the tags');
    nf_ok($status['other_seo_plugins'] === $other, 'The status names the other plugin');
} else {
    nf_section('Connected: every page gets its tags with nothing written yet');
    $html = nf_get(get_permalink($plain))['body'];
    nf_ok(nf_count('name="description"', $html) === 1, 'One description');
    nf_ok(strpos((string) nf_description($html), 'Our team repairs broken garage door springs') === 0, 'The description comes from the page\'s own words');
    nf_ok(strlen((string) nf_description($html)) <= 165, 'The description is the length search engines show');
    nf_ok(nf_tag($html, '#property="og:title" content="([^"]*)"#') === nf_title($html), 'The link preview uses the page\'s title');
    nf_ok(nf_tag($html, '#property="og:type" content="([^"]*)"#') === 'website', 'A page is a website in the preview');
    nf_ok(nf_tag($html, '#property="og:url" content="([^"]*)"#') === get_permalink($plain), 'The preview has the page\'s address');
    nf_ok(nf_count('name="twitter:card"', $html) === 1, 'One X card');
    nf_ok(nf_count('rel="canonical"', $html) === 1, 'One canonical address');
    nf_ok(strpos($html, 'class="nexoflow-page-data"') !== false, 'Structured data is printed for a page with none kept');
    $data = json_decode((string) nf_tag($html, '#class="nexoflow-page-data">(.*?)</script>#s'), true);
    nf_ok(is_array($data) && $data['@context'] === 'https://schema.org', 'The structured data is valid JSON-LD');
    nf_ok(is_array($data) && $data['@graph'][1]['@type'] === 'WebPage', 'It says what the page is');

    $post = nf_page('A Plain Article', '<p>' . $long_text . '</p>', 'post');
    $html = nf_get(get_permalink($post))['body'];
    nf_ok(nf_tag($html, '#property="og:type" content="([^"]*)"#') === 'article', 'A post is an article in the preview');
    nf_ok(nf_count('article:published_time', $html) === 1, 'It has its date');
    nf_ok(strpos($html, '"@type":"Article"') !== false, 'Its structured data says it is an article');

    nf_section('A page\'s listing');
    $draft_mode = nf_do('seo.update_page', array('page_id' => $plain, 'title' => 'Nope'), 'draft');
    nf_ok(nf_code($draft_mode) === 'DRAFT_REQUIRED', 'A published page is only changed in mode live');
    $empty = nf_do('seo.update_page', array('page_id' => $plain));
    nf_ok(nf_code($empty) === 'INVALID_PARAMETERS', 'A change with nothing in it is refused');
    $missing = nf_do('seo.update_page', array('page_id' => 999999, 'title' => 'Nope'));
    nf_ok(nf_code($missing) === 'NOT_FOUND', 'A page that is not there is refused');

    $description = 'Spring repair in Sudbury, MA. Call us any time, day or night, for fast and honest garage door service.';
    $answer = nf_do('seo.update_page', array('page_id' => $plain, 'title' => 'Garage Door Spring Repair in Sudbury, MA', 'description' => $description));
    $result = nf_result($answer);
    nf_ok($answer['status'] === 200, 'The change is accepted');
    nf_ok($result['previous']['title'] === '' && $result['previous']['description'] === '', 'The answer says what was there before');
    $html = nf_get(get_permalink($plain))['body'];
    nf_ok(nf_title($html) === 'Garage Door Spring Repair in Sudbury, MA', 'The title shows on the page as written');
    nf_ok(nf_description($html) === $description, 'The description shows on the page as written');
    nf_ok(nf_tag($html, '#property="og:description" content="([^"]*)"#') === $description, 'The link preview follows it');

    $by_address = nf_result(nf_do('seo.get_page', array('url' => get_permalink($plain))));
    nf_ok((int) $by_address['page_id'] === $plain, 'A page can be found by its address');
    nf_ok($by_address['seo']['title'] === 'Garage Door Spring Repair in Sudbury, MA', 'Reading it gives what was written');
    $by_path = nf_result(nf_do('seo.get_page', array('url' => wp_parse_url(get_permalink($plain), PHP_URL_PATH))));
    nf_ok((int) $by_path['page_id'] === $plain, 'A page can be found by its path');
    nf_ok(nf_code(nf_do('seo.get_page', array('url' => 'https://example.org/somewhere'))) === 'NOT_FOUND', 'An address on another site is not found');

    nf_do('seo.update_page', array('page_id' => $plain, 'title' => '', 'description' => ''));
    $html = nf_get(get_permalink($plain))['body'];
    nf_ok(strpos((string) nf_title($html), 'Plain Page') === 0, 'Clearing the title brings the old one back');
    nf_ok(strpos((string) nf_description($html), 'Our team repairs') === 0, 'Clearing the description brings the automatic one back');

    $evil = nf_do('seo.update_page', array('page_id' => $plain, 'title' => '<script>alert(1)</script>Safe', 'description' => '"><script>alert(2)</script>'));
    nf_ok($evil['status'] === 200, 'Markup in a title is accepted and cleaned');
    $html = nf_get(get_permalink($plain))['body'];
    nf_ok(strpos($html, 'alert(1)') === false && strpos($html, '<script>alert(2)') === false, 'No markup reaches the page');
    nf_do('seo.update_page', array('page_id' => $plain, 'title' => '', 'description' => ''));

    $canonical = nf_do('seo.update_page', array('page_id' => $plain, 'canonical' => $home . 'the-real-one/'));
    nf_ok($canonical['status'] === 200, 'A canonical address can be set');
    $html = nf_get(get_permalink($plain))['body'];
    nf_ok(strpos($html, 'rel="canonical" href="' . $home . 'the-real-one/"') !== false, 'The page says it');
    nf_do('seo.update_page', array('page_id' => $plain, 'canonical' => ''));

    nf_section('Hiding a page from search');
    nf_do('seo.update_page', array('page_id' => $plain, 'noindex' => true));
    $html = nf_get(get_permalink($plain))['body'];
    nf_ok((bool) preg_match('#<meta name=[\'"]robots[\'"][^>]*noindex#', $html), 'The page tells search engines to leave it out');
    $sitemap = nf_get($home . 'wp-sitemap-posts-page-1.xml')['body'];
    nf_ok(strpos($sitemap, get_permalink($plain)) === false, 'It leaves the sitemap');
    nf_do('seo.update_page', array('page_id' => $plain, 'noindex' => false));
    $html = nf_get(get_permalink($plain))['body'];
    nf_ok(!preg_match('#<meta name=[\'"]robots[\'"][^>]*noindex#', $html), 'Turning it off works');
    $sitemap = nf_get($home . 'wp-sitemap-posts-page-1.xml')['body'];
    nf_ok(strpos($sitemap, get_permalink($plain)) !== false, 'It comes back to the sitemap');
    nf_ok(strpos($sitemap, '<lastmod>') !== false, 'The sitemap says when pages changed');

    nf_section('Archives');
    $author = get_author_posts_url(1);
    $html = nf_get($author)['body'];
    nf_ok(strpos($html, 'rel="canonical" href="' . $author . '"') !== false, 'An author page says its canonical address');
    nf_ok(!preg_match('#<meta name=[\'"]robots[\'"][^>]*noindex#', $html), 'An author page is not hidden until asked');
    nf_ok(strpos(nf_get($home . 'wp-sitemap.xml')['body'], 'wp-sitemap-users-1.xml') !== false, 'The sitemap lists authors until asked');
    $site = nf_do('seo.update_site', array('noindex_author' => true, 'noindex_term_ids' => array(array('term_id' => 1))));
    nf_ok($site['status'] === 200, 'Author and single category pages can be hidden');
    nf_ok(nf_result($site)['previous']['noindex_author'] === false, 'The answer says how it was');
    nf_ok((bool) preg_match('#<meta name=[\'"]robots[\'"][^>]*noindex#', nf_get($author)['body']), 'The author page is hidden');
    $category = (string) get_term_link(1, 'category');
    nf_ok((bool) preg_match('#<meta name=[\'"]robots[\'"][^>]*noindex#', nf_get($category)['body']), 'The default category is hidden');
    nf_ok(strpos(nf_get($home . 'wp-sitemap.xml')['body'], 'wp-sitemap-users-1.xml') === false, 'Authors leave the sitemap');
    nf_ok(strpos(nf_get($home . 'wp-sitemap-taxonomies-category-1.xml')['body'], $category) === false, 'The default category leaves the sitemap');
    nf_do('seo.update_site', array('noindex_author' => false, 'noindex_term_ids' => array()));
    nf_ok(!preg_match('#<meta name=[\'"]robots[\'"][^>]*noindex#', nf_get($author)['body']), 'It can be put back');

    nf_section('Search visibility of the whole site');
    update_option('blog_public', '0');
    nf_ok(nf_result(nf_do('seo.status'))['search_visible'] === false, 'A site hidden from search engines is reported');
    $visible = nf_do('seo.update_site', array('search_visible' => true));
    nf_ok($visible['status'] === 200 && (string) get_option('blog_public') === '1', 'It can be opened to search engines');
    nf_ok(nf_result($visible)['previous']['search_visible'] === false, 'The answer says how it was');
}

nf_section('Leftover pages and the Trash');
$status = nf_result(nf_do('seo.status'));
$sample = wp_insert_post(array('post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Hello world!', 'post_name' => 'hello-world', 'post_content' => '<p>Welcome to WordPress. This is your first post.</p>', 'post_author' => 1));
$existing_hello = get_page_by_path('hello-world', OBJECT, 'post');
$status = nf_result(nf_do('seo.status'));
$named = wp_list_pluck($status['leftovers']['pages'], 'page_id');
nf_ok(in_array((int) $existing_hello->ID, array_map('intval', $named), true), 'The sample post is found');
nf_ok($status['leftovers']['default_category']['name'] === 'Uncategorized', 'The default category is found');
$kept_url = get_permalink($sample);
$trash = nf_do('content.trash', array('page_id' => $sample), 'draft');
nf_ok(nf_code($trash) === 'DRAFT_REQUIRED', 'A published post only goes to the Trash in mode live');
$trash = nf_do('content.trash', array('page_id' => $sample, 'redirect_to' => 'https://example.org/'));
nf_ok(nf_code($trash) === 'INVALID_PARAMETERS', 'A redirect to another site is refused, and nothing moves');
nf_ok(get_post_status($sample) === 'publish', 'The post is still there');
$trash = nf_do('content.trash', array('page_id' => $sample, 'redirect_to' => '/'));
nf_ok($trash['status'] === 200 && get_post_status($sample) === 'trash', 'The post goes to the Trash, not away');
nf_ok(nf_result($trash)['previous_status'] === 'publish', 'The answer says what it was');
$gone = nf_get($kept_url);
nf_ok($gone['status'] === 301 && rtrim($gone['location'], '/') === rtrim($home, '/'), 'Its old address leads to the new one');
$back = nf_do('content.restore', array('page_id' => $sample));
nf_ok($back['status'] === 200 && get_post_status($sample) === 'publish', 'It comes back from the Trash as it was');
nf_ok(nf_get($kept_url)['status'] === 200, 'Its address works again');
nf_ok(empty(NexoFlow_SEO::redirects()), 'The redirect made for it is gone');
$trash = nf_do('content.trash', array('page_id' => $sample));
nf_ok(nf_get($kept_url)['status'] === 404, 'With no new address it is simply gone');
nf_do('content.restore', array('page_id' => $sample));
wp_delete_post($sample, true);

$front = nf_page('Front Page', '<p>Hello</p>');
update_option('show_on_front', 'page');
update_option('page_on_front', $front);
nf_ok(nf_code(nf_do('content.trash', array('page_id' => $front))) === 'INVALID_PARAMETERS', 'The homepage cannot go to the Trash');
nf_ok(get_post_status($front) === 'publish', 'It stays');
$by_home = nf_result(nf_do('seo.get_page', array('url' => $home)));
nf_ok((int) $by_home['page_id'] === $front, 'The homepage\'s address finds the homepage');
update_option('show_on_front', 'posts');
update_option('page_on_front', 0);
$parent = nf_page('Parent', '<p>x</p>');
nf_page('Child', '<p>x</p>', 'page', array('post_parent' => $parent));
nf_ok(nf_code(nf_do('content.trash', array('page_id' => $parent))) === 'INVALID_PARAMETERS', 'A page with pages under it cannot go to the Trash');
nf_ok(nf_code(nf_do('content.trash', array('page_id' => 999999))) === 'NOT_FOUND', 'A page that is not there is refused');

nf_section('Addresses that are gone');
$target = nf_page('Target', '<p>x</p>');
$old = '/' . $GLOBALS['nf']['run'] . '-old-page/';
nf_ok(nf_get(home_url($old))['status'] === 404, 'The address is missing to begin with');
$set = nf_do('redirects.set', array('from' => $old, 'to' => get_permalink($target)));
nf_ok($set['status'] === 200, 'A redirect can be set');
$followed = nf_get(home_url($old));
nf_ok($followed['status'] === 301 && $followed['location'] === get_permalink($target), 'The old address leads to the new one, permanently');
$query = nf_get(home_url(rtrim($old, '/') . '?x=1'));
nf_ok($query['status'] === 301, 'With or without a slash or a query');
nf_ok(nf_code(nf_do('redirects.set', array('from' => $old, 'to' => 'https://example.org/'))) === 'INVALID_PARAMETERS', 'A redirect to another site is refused');
nf_ok(nf_code(nf_do('redirects.set', array('from' => get_permalink($target), 'to' => $old))) === 'INVALID_PARAMETERS', 'A redirect in a circle is refused');
nf_ok(nf_code(nf_do('redirects.set', array('from' => $old, 'to' => $old))) === 'INVALID_PARAMETERS', 'A redirect to itself is refused');
nf_ok(nf_code(nf_do('redirects.set', array('from' => '/', 'to' => $old))) === 'INVALID_PARAMETERS', 'The homepage cannot be redirected');
$live_page = nf_do('redirects.set', array('from' => get_permalink($plain), 'to' => get_permalink($target)));
nf_ok($live_page['status'] === 200 && nf_get(get_permalink($plain))['status'] === 200, 'A page that exists keeps working even with a redirect set');
nf_do('redirects.remove', array('from' => get_permalink($plain)));
nf_ok(count(nf_result(nf_do('redirects.list'))['redirects']) === 1, 'The list shows what is set');
$removed = nf_do('redirects.remove', array('from' => $old));
nf_ok(nf_result($removed)['previous'] === get_permalink($target), 'Removing it says where it led');
nf_ok(nf_get(home_url($old))['status'] === 404, 'The address is missing again');
nf_ok(nf_code(nf_do('redirects.remove', array('from' => $old))) === 'NOT_FOUND', 'Removing it twice is refused');

nf_section('Descriptions of pictures');
$image = wp_insert_attachment(array('post_title' => 'IMG_1234', 'post_mime_type' => 'image/png', 'post_status' => 'inherit'), 'nf-test.png');
$GLOBALS['nf']['made'][] = (int) $image;
$list = nf_result(nf_do('media.missing_alt'));
nf_ok(in_array((int) $image, array_map('intval', wp_list_pluck($list['images'], 'image_id')), true), 'An image with no description is listed');
$alt = nf_do('media.set_alt', array('image_id' => $image, 'alt' => 'Technician replacing a broken garage door spring'));
nf_ok($alt['status'] === 200 && nf_result($alt)['previous'] === '', 'A description can be written, and the answer has the old one');
nf_ok(get_post_meta($image, '_wp_attachment_image_alt', true) === 'Technician replacing a broken garage door spring', 'It is stored where WordPress looks');
$list = nf_result(nf_do('media.missing_alt'));
nf_ok(!in_array((int) $image, array_map('intval', wp_list_pluck($list['images'], 'image_id')), true), 'It leaves the list');
nf_ok(nf_code(nf_do('media.set_alt', array('image_id' => $plain, 'alt' => 'x'))) === 'NOT_FOUND', 'A page is not an image');
$tag_page = nf_page('Image Page', '<img class="wp-image-' . $image . '" src="' . $home . 'nf-test.png" alt="" width="10" height="10" />');
$html = nf_get(get_permalink($tag_page))['body'];
nf_ok(strpos($html, 'alt="Technician replacing a broken garage door spring"') !== false, 'A picture placed with an empty description gets the written one');

nf_section('One main heading');
$no_h1 = nf_page('No Main', '<h2>The Real Headline</h2><p>x</p><h3>Sub</h3>');
wp_update_post(array('ID' => $no_h1));
$theme_h1 = nf_h1s(nf_get(get_permalink($no_h1))['body']);
$heading = nf_do('seo.update_page', array('page_id' => $no_h1, 'fix_headings' => true));
nf_ok($heading['status'] === 200, 'A page can ask for the fix');
$html = nf_get(get_permalink($no_h1))['body'];
nf_ok(nf_h1s($html) === max(1, $theme_h1), 'The page has its main heading (theme had ' . $theme_h1 . ')');
if ($theme_h1 === 0) {
    nf_ok(strpos($html, '<h1 class="wp-block-heading">The Real Headline</h1>') !== false || strpos($html, '>The Real Headline</h1>') !== false, 'The first h2 became it, with the same words');
}
$off = nf_do('seo.update_page', array('page_id' => $no_h1, 'fix_headings' => false));
nf_ok($off['status'] === 200 && nf_h1s(nf_get(get_permalink($no_h1))['body']) === $theme_h1, 'Turning it off puts the page back');
$many = nf_page('Many Main', '<h1>One</h1><h1>Two</h1><h1>Three</h1>');
nf_do('seo.update_page', array('page_id' => $many, 'fix_headings' => true));
nf_ok(nf_h1s(nf_get(get_permalink($many))['body']) === 1, 'A page with several ends up with one');

nf_section('Comments');
$with_comment = nf_page('With Comment', '<p>x</p>', 'post', array('comment_status' => 'open'));
wp_insert_comment(array('comment_post_ID' => $with_comment, 'comment_content' => 'A comment', 'comment_author' => 'Someone', 'comment_approved' => 1));
update_option('thread_comments', '1');
$html = nf_get(get_permalink($with_comment))['body'];
nf_ok(strpos($html, 'comment-reply-link') === false || strpos($html, 'replytocom') === false, 'A reply link does not make a second address for the page');

if (empty($other)) {
    nf_section('The page cache');
    $cached = nf_page('Cached Page', '<p>' . $long_text . '</p>');
    $url = get_permalink($cached);
    nf_ok(nf_get($url)['cache'] === '', 'It is off until asked for');
    $on = nf_do('speed.set_cache', array('enabled' => true));
    nf_ok($on['status'] === 200 && nf_result($on)['previous'] === false, 'It can be switched on');
    nf_ok(nf_get($url)['cache'] === 'MISS', 'The first visitor makes the copy');
    $hit = nf_get($url);
    nf_ok($hit['cache'] === 'HIT', 'The next visitor gets the copy');
    nf_ok(strpos($hit['body'], 'Our team repairs') !== false && strpos($hit['body'], '</html>') !== false, 'The copy is the whole page');
    nf_ok(nf_get($url . '?utm_source=mail')['cache'] === 'HIT', 'A campaign link gets the same copy');
    nf_ok(nf_get($url . '?s=1&x=2')['cache'] !== 'HIT', 'An address with anything else in it does not');
    $cookie = new WP_Http_Cookie(array('name' => 'wordpress_logged_in_abc', 'value' => 'someone'));
    nf_ok(nf_get($url, array('cookies' => array($cookie)))['cache'] !== 'HIT', 'Someone signed in never gets a copy');
    nf_ok(nf_get(home_url('/' . $GLOBALS['nf']['run'] . '-nothing/'))['cache'] !== 'HIT', 'A missing page is not kept');
    nf_ok(nf_get($home . 'wp-sitemap.xml')['cache'] === '', 'A sitemap is not kept');
    wp_update_post(array('ID' => $cached, 'post_content' => '<p>Changed words on the page, long enough to be a real description of this page here.</p>'));
    $fresh = nf_get($url);
    nf_ok($fresh['cache'] === 'MISS' && strpos($fresh['body'], 'Changed words') !== false, 'Changing a page shows at once');
    nf_ok(nf_get($url)['cache'] === 'HIT', 'And the new copy is kept');
    nf_do('seo.update_page', array('page_id' => $cached, 'title' => 'New Search Title For This Page'));
    $fresh = nf_get($url);
    nf_ok(nf_title($fresh['body']) === 'New Search Title For This Page', 'A listing change shows at once');
    $off = nf_do('speed.set_cache', array('enabled' => false));
    nf_ok($off['status'] === 200 && nf_result($off)['previous'] === true, 'It can be switched off');
    nf_ok(nf_get($url)['cache'] === '', 'And it is off');
}

nf_section('Disconnected again');
delete_option('nexoflow_site_key');
$html = nf_get(get_permalink($plain))['body'];
nf_ok(nf_count('property="og:', $html) === 0 || !empty($other), 'The tags stop when the site is disconnected');

nf_section('Cleaning up');
foreach ($GLOBALS['nf']['made'] as $made) {
    wp_delete_post($made, true);
}

foreach (array(NexoFlow_SEO::SETTINGS, NexoFlow_SEO::REDIRECTS, NexoFlow_Page_Cache::OPTION) as $option) {
    delete_option($option);
}

echo "\n" . $GLOBALS['nf']['ok'] . ' passed, ' . $GLOBALS['nf']['fail'] . " failed\n";

if ($GLOBALS['nf']['fail'] > 0) {
    exit(1);
}
