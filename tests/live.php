<?php
/**
 * Runs the Website Agent inside a real WordPress, through the real REST server
 * and database. The unit tests in run.php use stand-ins; this is the check that
 * WordPress itself behaves the way the plugin expects.
 *
 * Only for a disposable site. It refuses to run on a site that is already
 * connected, creates pages, two users and a role, and removes them again.
 *
 *   NEXOFLOW_LIVE_TEST=1 wp eval-file tests/live.php
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

$GLOBALS['nexoflow_live'] = array(
    'ok' => 0,
    'fail' => 0,
    'count' => 0,
    'run' => 'nf_live_' . bin2hex(random_bytes(4)),
    'notices' => array(),
);

set_error_handler(
    function ($severity, $message, $file, $line) {
        if (strpos($file, DIRECTORY_SEPARATOR . 'nexoflow' . DIRECTORY_SEPARATOR) !== false && basename($file) !== 'live.php') {
            $GLOBALS['nexoflow_live']['notices'][] = basename($file) . ':' . $line . ' ' . $message;
        }

        return false;
    }
);

function nexoflow_live_ok($condition, $label)
{
    $GLOBALS['nexoflow_live'][$condition ? 'ok' : 'fail']++;
    echo ($condition ? 'OK    ' : 'FAIL  ') . $label . "\n";
}

function nexoflow_live_rest($method, $route, $body = null, $raw = null)
{
    $request = new WP_REST_Request($method, $route);

    if ($body !== null || $raw !== null) {
        $request->set_header('Content-Type', 'application/json');
        $request->set_body($raw !== null ? $raw : wp_json_encode($body));
    }

    $response = rest_do_request($request);

    return array(
        'status' => $response->get_status(),
        'body' => $response->get_data(),
        'headers' => $response->get_headers(),
    );
}

function nexoflow_live_act($action, $parameters = array(), $extra = array())
{
    $GLOBALS['nexoflow_live']['count']++;

    return nexoflow_live_rest(
        'POST',
        '/nexoflow/v1/actions/execute',
        $extra + array(
            'request_id' => $GLOBALS['nexoflow_live']['run'] . '_' . $GLOBALS['nexoflow_live']['count'],
            'action' => $action,
            'parameters' => $parameters,
        )
    );
}

function nexoflow_live_code($result)
{
    if (!empty($result['body']['success'])) {
        return 'OK';
    }

    return isset($result['body']['error']['code']) ? $result['body']['error']['code'] : 'NO_CODE';
}

function nexoflow_live_act_as($user_id)
{
    update_option(NexoFlow_Agent_Actor::OPTION, $user_id, false);
    NexoFlow_Plugin::instance()->agent()->capabilities()->reset();
}

global $wpdb;

$run = $GLOBALS['nexoflow_live']['run'];
$key = 'nfwp_' . bin2hex(random_bytes(24));
$table = NexoFlow_Agent_Request_Store::table();
$agent = NexoFlow_Plugin::instance()->agent();
$store = $agent->store();
$sync = new NexoFlow_Rest();
$admin_id = (int) current(get_users(array('role' => 'administrator', 'orderby' => 'ID', 'order' => 'ASC', 'number' => 1, 'fields' => 'ID')));
$made_pages = array();
$execute = '/nexoflow/v1/actions/execute';
$capabilities = '/nexoflow/v1/site/capabilities';

update_option('nexoflow_site_key', $key, false);
delete_option(NexoFlow_Agent_Actor::OPTION);
$store->maybe_install();

echo "\n-- Install\n";
nexoflow_live_ok($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table, 'The request table exists');
$unique = array();

foreach ($wpdb->get_results("SHOW INDEX FROM {$table}", ARRAY_A) as $index) {
    if ((int) $index['Non_unique'] === 0) {
        $unique[] = $index['Column_name'];
    }
}

sort($unique);
nexoflow_live_ok($unique === array('claim', 'id'), 'claim is unique and id is the primary key');
nexoflow_live_ok($store->ready(), 'The table version is recorded');

echo "\n-- Authentication\n";
unset($_SERVER['HTTP_AUTHORIZATION']);
$none = nexoflow_live_rest('GET', $capabilities);
nexoflow_live_ok($none['status'] === 401 && nexoflow_live_code($none) === 'MISSING_CREDENTIALS', 'No key answers 401 in the structured shape');
nexoflow_live_ok(isset($none['headers']['Cache-Control']) && $none['headers']['Cache-Control'] === 'no-store', 'Refusals are sent with no-store');

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer nfwp_' . str_repeat('0', 48);
$wrong = nexoflow_live_rest('POST', $execute, array('request_id' => $run . '_wrong_key', 'action' => 'site.read'));
nexoflow_live_ok($wrong['status'] === 401 && nexoflow_live_code($wrong) === 'INVALID_CREDENTIALS', 'Another key answers 401');

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $key;
$broken = nexoflow_live_rest('POST', $execute, null, '{not json');
nexoflow_live_ok($broken['status'] === 400 && nexoflow_live_code($broken) === 'INVALID_REQUEST', 'Broken JSON answers 400 in the structured shape');
nexoflow_live_ok($sync->can_sync() === true, 'The sync route still accepts the key');

echo "\n-- Discovery\n";
$discovery = nexoflow_live_rest('GET', $capabilities);
$document = $discovery['body'];
$elementor = $document['builders']['elementor'];
nexoflow_live_ok($discovery['status'] === 200 && $document['success'] === true, 'Discovery answers 200');
nexoflow_live_ok($document['wordpress']['version'] === get_bloginfo('version') && $document['plugin']['version'] === NEXOFLOW_VERSION, 'WordPress ' . $document['wordpress']['version'] . ', plugin ' . $document['plugin']['version']);
nexoflow_live_ok($document['actor']['id'] === $admin_id, 'The first administrator is the acting account');
nexoflow_live_ok(in_array('pages.create', $document['capabilities'], true) && in_array('blocks.read', $document['capabilities'], true), 'Capabilities: ' . implode(', ', $document['capabilities']));
nexoflow_live_ok(strpos(wp_json_encode($document), $key) === false, 'Discovery does not contain the key');
echo '      Block editor: ' . ($document['builders']['gutenberg']['available'] ? 'available' : $document['builders']['gutenberg']['reason']) . "\n";
echo '      Elementor: ' . ($elementor['installed'] ? 'version ' . $elementor['version'] : 'not installed')
    . ', MCP ' . ($elementor['mcp']['available'] ? 'available (' . count($elementor['mcp']['abilities']) . ' abilities)' : 'unavailable')
    . ($elementor['reason'] !== '' ? ', ' . $elementor['reason'] : '') . "\n";

echo "\n-- Create and replay\n";
$drafts_before = (int) wp_count_posts('page')->draft;
$published_before = (int) wp_count_posts('page')->publish;
$content = "<!-- wp:heading {\"level\":2} -->\n<h2>Our process</h2>\n<!-- /wp:heading -->\n\n"
    . "<!-- wp:paragraph -->\n<p>C:\\doors \"quoted\"</p>\n<!-- /wp:paragraph --><script>alert(1)</script><p onclick=\"evil()\">x</p>";
$create = array('title' => 'Garage Door <b>Installation</b>', 'content' => $content, 'slug' => 'Garage Door Installation ' . $run);
$first = nexoflow_live_act('pages.create', $create, array('request_id' => $run . '_create', 'authorization_id' => $run . '_auth'));
$page = isset($first['body']['result']) ? $first['body']['result'] : array('page_id' => 0, 'revision' => '', 'builder' => '', 'edit_url' => '', 'modified_gmt' => null);
$post = get_post($page['page_id']);
$made_pages[] = $page['page_id'];
nexoflow_live_ok(nexoflow_live_code($first) === 'OK' && $post instanceof WP_Post, 'pages.create runs');

if ($post instanceof WP_Post) {
    nexoflow_live_ok($post->post_type === 'page' && $post->post_status === 'draft' && (int) $post->post_author === $admin_id, 'A draft page owned by the acting account');
    nexoflow_live_ok($post->post_title === 'Garage Door Installation', 'The title is stored as plain text');
    nexoflow_live_ok(strpos($post->post_content, '<script') === false && strpos($post->post_content, 'onclick') === false, 'Scripts are removed although the acting account is an administrator');
    nexoflow_live_ok(strpos($post->post_content, 'C:\\doors "quoted"') !== false && strpos($post->post_content, '<!-- wp:heading {"level":2} -->') !== false, 'Backslashes, quotes and block markup survive the save');
    nexoflow_live_ok(get_post_meta($post->ID, NexoFlow_Agent_Provider_WordPress::REQUEST_META, true) === $run . '_create', 'The page records the request that made it');
    nexoflow_live_ok($page['builder'] !== '' && strlen($page['revision']) === 16 && $page['edit_url'] !== '', 'The answer has the builder, revision and edit link');
    nexoflow_live_ok(strpos($page['edit_url'], '&action=edit') !== false && strpos($page['edit_url'], '&amp;') === false, 'The edit link is a plain URL');
    nexoflow_live_ok(is_string($page['modified_gmt']) && $page['modified_gmt'] !== '', 'A draft that was never edited still reports when it was modified');
}

nexoflow_live_ok(get_current_user_id() === 0, 'The current user is restored after the action');

$second = nexoflow_live_act('pages.create', $create, array('request_id' => $run . '_create', 'authorization_id' => $run . '_auth'));
nexoflow_live_ok(nexoflow_live_code($second) === 'OK' && $second['body']['replayed'] === true && $second['body']['result']['page_id'] === $page['page_id'], 'The same request again is replayed');
nexoflow_live_ok((int) wp_count_posts('page')->draft === $drafts_before + 1, 'Exactly one page was added');
nexoflow_live_ok(nexoflow_live_code(nexoflow_live_act('pages.create', array('title' => 'Other'), array('request_id' => $run . '_create'))) === 'REQUEST_ID_REUSED', 'A reused request_id is refused');

echo "\n-- Read\n";
$read = nexoflow_live_act('pages.get', array('page_id' => $page['page_id']));
nexoflow_live_ok(nexoflow_live_code($read) === 'OK' && $read['body']['result']['revision'] === $page['revision'], 'pages.get returns the same revision');
$blocks = nexoflow_live_act('blocks.read', array('page_id' => $page['page_id']));
$names = nexoflow_live_code($blocks) === 'OK' ? wp_list_pluck($blocks['body']['result']['blocks'], 'name') : array();
nexoflow_live_ok(array_slice($names, 0, 2) === array('core/heading', 'core/paragraph'), 'blocks.read: ' . implode(', ', $names));
$list = nexoflow_live_act('pages.list', array('status' => 'draft'));
nexoflow_live_ok(nexoflow_live_code($list) === 'OK' && $list['body']['result']['pages'][0]['page_id'] === $page['page_id'], 'pages.list returns the newest page first');

echo "\n-- Change, conflict, lock\n";
sleep(1);
$change = array('page_id' => $page['page_id'], 'expected_revision' => $page['revision'], 'title' => 'Garage Door Installation in Santa Clara');
$update = nexoflow_live_act('pages.update', $change, array('request_id' => $run . '_update'));
$current = nexoflow_live_code($update) === 'OK' ? $update['body']['result']['revision'] : '';
nexoflow_live_ok($current !== '' && $current !== $page['revision'], 'A change with the matching revision succeeds');
nexoflow_live_ok(get_post_status($page['page_id']) === 'draft', 'The page is still a draft');
$retry = nexoflow_live_act('pages.update', $change, array('request_id' => $run . '_update'));
nexoflow_live_ok(nexoflow_live_code($retry) === 'OK' && $retry['body']['replayed'] === true, 'A retried change is replayed, not a conflict');
nexoflow_live_ok(nexoflow_live_code(nexoflow_live_act('pages.update', array('title' => 'Stale') + $change)) === 'CONTENT_CONFLICT', 'A stale revision is a conflict');

sleep(1);
wp_update_post(array('ID' => $page['page_id'], 'post_excerpt' => 'Edited by a person'));
nexoflow_live_ok(nexoflow_live_code(nexoflow_live_act('pages.update', array('page_id' => $page['page_id'], 'expected_revision' => $current, 'title' => 'Overwrite'))) === 'CONTENT_CONFLICT', 'An edit made in WordPress causes a conflict');

$current = nexoflow_live_act('pages.get', array('page_id' => $page['page_id']))['body']['result']['revision'];
update_post_meta($page['page_id'], '_edit_lock', time() . ':' . $admin_id);
nexoflow_live_ok(nexoflow_live_code(nexoflow_live_act('pages.update', array('page_id' => $page['page_id'], 'expected_revision' => $current, 'title' => 'While open'))) === 'CONTENT_LOCKED', 'A page open in the editor is not changed');
delete_post_meta($page['page_id'], '_edit_lock');

wp_publish_post($page['page_id']);
$current = nexoflow_live_act('pages.get', array('page_id' => $page['page_id']))['body']['result']['revision'];
$live = nexoflow_live_act('pages.update', array('page_id' => $page['page_id'], 'expected_revision' => $current, 'title' => 'Changed live'));
nexoflow_live_ok(nexoflow_live_code($live) === 'DRAFT_REQUIRED' && get_the_title($page['page_id']) === 'Garage Door Installation in Santa Clara', 'A published page is not changed');
$publish = nexoflow_live_act('pages.create', array('title' => 'Go live'), array('mode' => 'publish'));
nexoflow_live_ok(nexoflow_live_code($publish) === 'MODE_NOT_SUPPORTED' && (int) wp_count_posts('page')->publish === $published_before + 1, 'Publishing through the agent is refused');

echo "\n-- Published page\n";
$current = nexoflow_live_act('pages.get', array('page_id' => $page['page_id']))['body']['result']['revision'];
$retitle = nexoflow_live_act('pages.update', array('page_id' => $page['page_id'], 'expected_revision' => $current, 'title' => 'Garage Door Installation, Santa Clara'), array('mode' => 'live'));
$retitled = nexoflow_live_code($retitle) === 'OK' ? $retitle['body']['result'] : array('revision' => '', 'restore_revision_id' => 0);
$kept = wp_get_post_revision($retitled['restore_revision_id']);
nexoflow_live_ok(nexoflow_live_code($retitle) === 'OK' && get_the_title($page['page_id']) === 'Garage Door Installation, Santa Clara' && get_post_status($page['page_id']) === 'publish', 'A published page is changed in live mode and stays published');
nexoflow_live_ok($kept && (int) $kept->post_parent === $page['page_id'] && $kept->post_title === 'Garage Door Installation in Santa Clara', 'WordPress kept the version from before the change');
$slug = nexoflow_live_act('pages.update', array('page_id' => $page['page_id'], 'expected_revision' => $retitled['revision'], 'slug' => 'moved'), array('mode' => 'live'));
nexoflow_live_ok(nexoflow_live_code($slug) === 'INVALID_PARAMETERS' && get_post_field('post_name', $page['page_id']) !== 'moved', 'The address of a published page cannot be changed');

$tree = nexoflow_live_act('blocks.read', array('page_id' => $page['page_id']));
$before_insert = (string) get_post_field('post_content', $page['page_id'], 'raw');
$section = "<!-- wp:group {\"layout\":{\"type\":\"constrained\"}} -->\n<div class=\"wp-block-group\"><!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Why homeowners choose us</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Same-week installation.<script>steal()</script></p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group -->";
// WordPress 7.2 removes what is inside a script tag too; earlier versions keep its text.
$clean_section = str_replace('<script>steal()</script>', wp_kses_post('<script>steal()</script>'), $section);
$insert = array('page_id' => $page['page_id'], 'expected_revision' => $tree['body']['result']['revision'], 'content' => $section, 'position' => 'after', 'path' => '0');
nexoflow_live_ok(nexoflow_live_code(nexoflow_live_act('blocks.insert', $insert)) === 'DRAFT_REQUIRED', 'Blocks are not added to a published page without live mode');
$added = nexoflow_live_act('blocks.insert', $insert, array('mode' => 'live', 'request_id' => $run . '_section'));
$added_page = nexoflow_live_code($added) === 'OK' ? $added['body']['result'] : array('revision' => '', 'restore_revision_id' => 0, 'inserted' => array());
$after_insert = (string) get_post_field('post_content', $page['page_id'], 'raw');
$expected = preg_replace('/<!-- \/wp:heading -->\n\n/', "<!-- /wp:heading -->\n\n" . addcslashes($clean_section, '\\$') . "\n\n", $before_insert, 1);
nexoflow_live_ok(nexoflow_live_code($added) === 'OK' && $added_page['inserted'] === array('path' => '1', 'count' => 1), 'A section is added to the published page');
nexoflow_live_ok($after_insert === $expected, 'The section is where it was asked for and every other block is byte for byte as it was');
nexoflow_live_ok(stripos($after_insert, '<script') === false, 'Script tags in the new section are removed');
$again = nexoflow_live_act('blocks.insert', $insert, array('mode' => 'live', 'request_id' => $run . '_section'));
nexoflow_live_ok(nexoflow_live_code($again) === 'OK' && $again['body']['replayed'] === true && get_post_field('post_content', $page['page_id'], 'raw') === $after_insert, 'A repeated request does not add the section twice');
nexoflow_live_ok(nexoflow_live_code(nexoflow_live_act('blocks.insert', array('content' => '<p>Just HTML</p>', 'expected_revision' => $added_page['revision']) + $insert, array('mode' => 'live'))) === 'INVALID_PARAMETERS', 'HTML that is not a block is refused');
nexoflow_live_ok(nexoflow_live_code(nexoflow_live_act('blocks.insert', array('content' => '<!-- wp:hero /-->', 'expected_revision' => $added_page['revision']) + $insert, array('mode' => 'live'))) === 'INVALID_PARAMETERS', 'A WordPress block that does not exist is refused');

sleep(1);
$undo = nexoflow_live_act('pages.restore', array('page_id' => $page['page_id'], 'expected_revision' => $added_page['revision'], 'revision_id' => $added_page['restore_revision_id']), array('mode' => 'live'));
nexoflow_live_ok(nexoflow_live_code($undo) === 'OK' && get_post_field('post_content', $page['page_id'], 'raw') === $before_insert && get_post_status($page['page_id']) === 'publish', 'Adding the section can be undone, and the page stays published');
echo "\n-- Changing a section\n";
sleep(1);
$sections = nexoflow_live_act('blocks.read', array('page_id' => $page['page_id'], 'include_markup' => true));
$section_list = nexoflow_live_code($sections) === 'OK' ? $sections['body']['result'] : array('revision' => '', 'blocks' => array(array('markup' => '')));
$last = count($section_list['blocks']) - 1;
$old_markup = (string) $section_list['blocks'][$last]['markup'];
$new_markup = "<!-- wp:group {\"style\":{\"color\":{\"background\":\"#0b3d91\",\"text\":\"#ffffff\"}}} -->\n<div class=\"wp-block-group has-text-color has-background\" style=\"color:#ffffff;background-color:#0b3d91\"><!-- wp:details -->\n<details class=\"wp-block-details\"><summary>Do you repair springs?</summary><!-- wp:paragraph -->\n<p>Yes, the same day.</p>\n<!-- /wp:paragraph --></details>\n<!-- /wp:details --></div>\n<!-- /wp:group -->";
$swap = array('page_id' => $page['page_id'], 'expected_revision' => $section_list['revision'], 'replacements' => array(array('path' => (string) $last, 'content' => $new_markup)));
nexoflow_live_ok(nexoflow_live_code(nexoflow_live_act('blocks.replace', $swap)) === 'DRAFT_REQUIRED', 'A section of a published page is not changed without live mode');
$swapped = nexoflow_live_act('blocks.replace', $swap, array('mode' => 'live', 'request_id' => $run . '_swap'));
$swapped_page = nexoflow_live_code($swapped) === 'OK' ? $swapped['body']['result'] : array('revision' => '', 'restore_revision_id' => 0, 'replaced' => array());
$after_swap = (string) get_post_field('post_content', $page['page_id'], 'raw');
nexoflow_live_ok(nexoflow_live_code($swapped) === 'OK' && $swapped_page['replaced'] === array('sections' => 1, 'blocks' => 1, 'removed' => 0), 'A section of the published page is replaced (' . nexoflow_live_code($swapped) . ')');
nexoflow_live_ok($old_markup !== '' && $after_swap === str_replace($old_markup, $new_markup, $before_insert) && substr_count($before_insert, $old_markup) === 1, 'The new section is in its place and every other block is byte for byte as it was');
nexoflow_live_ok(serialize_blocks(parse_blocks($new_markup)) === $new_markup, 'WordPress reads the new section back exactly as it was written');
$swap_again = nexoflow_live_act('blocks.replace', $swap, array('mode' => 'live', 'request_id' => $run . '_swap'));
nexoflow_live_ok(nexoflow_live_code($swap_again) === 'OK' && $swap_again['body']['replayed'] === true && get_post_field('post_content', $page['page_id'], 'raw') === $after_swap, 'A repeated request does not change the page again');
$bad_swap = nexoflow_live_act('blocks.replace', array('page_id' => $page['page_id'], 'expected_revision' => $swapped_page['revision'], 'replacements' => array(array('path' => '99', 'content' => $new_markup))), array('mode' => 'live'));
nexoflow_live_ok(nexoflow_live_code($bad_swap) === 'INVALID_PARAMETERS' && $bad_swap['body']['error']['details']['fields']['replacements.0.path'] === 'not_found', 'A section that is not on the page cannot be replaced');
$not_a_list = nexoflow_live_act('blocks.replace', array('page_id' => $page['page_id'], 'expected_revision' => $swapped_page['revision'], 'replacements' => array('path' => '0', 'content' => $new_markup)), array('mode' => 'live'));
nexoflow_live_ok(nexoflow_live_code($not_a_list) === 'INVALID_PARAMETERS' && $not_a_list['body']['error']['details']['fields']['replacements'] === 'type', 'Through real JSON, one change on its own is not a list');
sleep(1);
$unswap = nexoflow_live_act('pages.restore', array('page_id' => $page['page_id'], 'expected_revision' => $swapped_page['revision'], 'revision_id' => $swapped_page['restore_revision_id']), array('mode' => 'live'));
nexoflow_live_ok(nexoflow_live_code($unswap) === 'OK' && get_post_field('post_content', $page['page_id'], 'raw') === $before_insert, 'Changing the section can be undone');

echo "\n-- Rebuilding a page\n";
sleep(1);
$whole = nexoflow_live_act('blocks.read', array('page_id' => $page['page_id'], 'include_markup' => true));
$whole_list = nexoflow_live_code($whole) === 'OK' ? $whole['body']['result'] : array('revision' => '', 'blocks' => array());
$had = count($whole_list['blocks']);
$fresh_one = "<!-- wp:paragraph -->\n<p>Rebuilt one</p>\n<!-- /wp:paragraph -->";
$fresh_two = "<!-- wp:paragraph -->\n<p>Rebuilt two</p>\n<!-- /wp:paragraph -->";
$rebuild_list = array();

for ($index = 0; $index < $had; $index++) {
    $rebuild_list[] = $index === 0
        ? array('path' => '0', 'content' => $had === 1 ? $fresh_one . "\n\n" . $fresh_two : $fresh_one)
        : ($index === 1 ? array('path' => '1', 'content' => $fresh_two) : array('path' => (string) $index, 'remove' => true));
}

$rebuilt = nexoflow_live_act('blocks.replace', array('page_id' => $page['page_id'], 'expected_revision' => $whole_list['revision'], 'replacements' => $rebuild_list), array('mode' => 'live', 'request_id' => $run . '_rebuild'));
$rebuilt_page = nexoflow_live_code($rebuilt) === 'OK' ? $rebuilt['body']['result'] : array('revision' => '', 'restore_revision_id' => 0, 'replaced' => array());
$after_rebuild = (string) get_post_field('post_content', $page['page_id'], 'raw');
$left = array_values(array_filter(parse_blocks($after_rebuild), function ($block) {
    return !empty($block['blockName']);
}));
nexoflow_live_ok($had >= 2 && nexoflow_live_code($rebuilt) === 'OK' && $rebuilt_page['replaced'] === array('sections' => min($had, 2), 'blocks' => 2, 'removed' => max(0, $had - 2)), 'A published page of ' . $had . ' sections is rebuilt as 2 (' . nexoflow_live_code($rebuilt) . ' ' . wp_json_encode($rebuilt_page['replaced']) . ')');
nexoflow_live_ok(count($left) === 2 && strpos($after_rebuild, $fresh_one) === 0 && substr($after_rebuild, -strlen($fresh_two)) === $fresh_two, 'Only the new sections are on the page, with nothing of the old page before, between or after them');
nexoflow_live_ok(get_post_status($page['page_id']) === 'publish' && wp_get_post_revision($rebuilt_page['restore_revision_id'])->post_content === $before_insert, 'The page stays published and the version from before the rebuild is kept');
$wipe = array();

foreach ($left as $index => $unused) {
    $wipe[] = array('path' => (string) $index, 'remove' => true);
}

$wiped = nexoflow_live_act('blocks.replace', array('page_id' => $page['page_id'], 'expected_revision' => $rebuilt_page['revision'], 'replacements' => $wipe), array('mode' => 'live'));
nexoflow_live_ok(nexoflow_live_code($wiped) === 'INVALID_PARAMETERS' && $wiped['body']['error']['details']['fields']['replacements'] === 'removes_everything' && get_post_field('post_content', $page['page_id'], 'raw') === $after_rebuild, 'A page is never left with nothing on it');
$both = nexoflow_live_act('blocks.replace', array('page_id' => $page['page_id'], 'expected_revision' => $rebuilt_page['revision'], 'replacements' => array(array('path' => '0', 'remove' => true, 'content' => $fresh_one))), array('mode' => 'live'));
nexoflow_live_ok(nexoflow_live_code($both) === 'INVALID_PARAMETERS' && $both['body']['error']['details']['fields']['replacements.0.content'] === 'not_allowed', 'Through real JSON, a section is replaced or taken away, not both');
sleep(1);
$unbuild = nexoflow_live_act('pages.restore', array('page_id' => $page['page_id'], 'expected_revision' => $rebuilt_page['revision'], 'revision_id' => $rebuilt_page['restore_revision_id']), array('mode' => 'live'));
nexoflow_live_ok(nexoflow_live_code($unbuild) === 'OK' && get_post_field('post_content', $page['page_id'], 'raw') === $before_insert, 'The rebuild can be undone, and the page is exactly as it was');

$front = nexoflow_live_act('site.read');
nexoflow_live_ok(nexoflow_live_code($front) === 'OK' && isset($front['body']['result']['site']['front_page']['show']), 'site.read says how the homepage is set up: ' . wp_json_encode($front['body']['result']['site']['front_page']));

echo "\n-- Homepage template\n";
$front_template = isset($front['body']['result']['site']['front_page']['template']) ? $front['body']['result']['site']['front_page']['template'] : null;

if (!is_string($front_template)) {
    echo "      Skipped: the homepage of this site is not drawn from a block theme template.\n";
} else {
    $before_template = get_block_template($front_template, 'wp_template');
    $original = (string) $before_template->content;
    $file = get_theme_file_path('templates/' . $before_template->slug . '.html');
    $file_before = is_readable($file) ? md5_file($file) : '';
    $had_post = (int) $before_template->wp_id;
    $read_template = nexoflow_live_act('templates.read', array('template' => $front_template));
    $shape = nexoflow_live_code($read_template) === 'OK' ? $read_template['body']['result'] : array('revision' => '', 'blocks' => array(), 'customized' => null);
    nexoflow_live_ok(nexoflow_live_code($read_template) === 'OK' && count($shape['blocks']) > 0, 'templates.read lists the homepage sections of ' . $front_template . ': ' . implode(', ', wp_list_pluck($shape['blocks'], 'name')));
    nexoflow_live_ok($shape['customized'] === ($had_post > 0), 'It reports whether the template was customized');

    $new_section = "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Added by the live test " . $run . "</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Second block of the new section.</p>\n<!-- /wp:paragraph -->";
    $add = array('template' => $front_template, 'expected_revision' => $shape['revision'], 'content' => $new_section, 'position' => 'after', 'path' => '0');
    nexoflow_live_ok(nexoflow_live_code(nexoflow_live_act('templates.insert', $add)) === 'MODE_NOT_SUPPORTED', 'A template is not changed without live mode');
    $changed = nexoflow_live_act('templates.insert', $add, array('mode' => 'live', 'request_id' => $run . '_template'));
    $changed_result = nexoflow_live_code($changed) === 'OK' ? $changed['body']['result'] : array('revision' => '', 'restore_revision_id' => 0, 'post_id' => 0, 'inserted' => array());
    $after_template = get_block_template($front_template, 'wp_template');
    $kept_template = wp_get_post_revision($changed_result['restore_revision_id']);
    nexoflow_live_ok(nexoflow_live_code($changed) === 'OK' && $changed_result['inserted'] === array('path' => '1', 'count' => 2), 'A section is added to the homepage template (' . nexoflow_live_code($changed) . ')');
    nexoflow_live_ok($after_template->source === 'custom' && (int) $after_template->wp_id === (int) $changed_result['post_id'], 'The change is stored as a template post, like the Site Editor does');
    nexoflow_live_ok(strpos((string) $after_template->content, $new_section) !== false && str_replace("\n\n" . $new_section, '', (string) $after_template->content) === $original, 'The section is in place and the rest of the template is byte for byte as it was');
    nexoflow_live_ok($file_before === '' || md5_file($file) === $file_before, 'The theme file is untouched');
    nexoflow_live_ok($kept_template && $kept_template->post_content === $original, 'WordPress kept the template as it was before');
    nexoflow_live_ok(strpos(do_blocks((string) $after_template->content), 'Added by the live test ' . $run) !== false, 'The new section renders on the homepage');
    $replayed = nexoflow_live_act('templates.insert', $add, array('mode' => 'live', 'request_id' => $run . '_template'));
    nexoflow_live_ok(nexoflow_live_code($replayed) === 'OK' && $replayed['body']['replayed'] === true && get_block_template($front_template, 'wp_template')->content === $after_template->content, 'A repeated request does not add the section twice');

    $undo_template = nexoflow_live_act('templates.restore', array('template' => $front_template, 'expected_revision' => $changed_result['revision'], 'revision_id' => $changed_result['restore_revision_id']), array('mode' => 'live'));
    nexoflow_live_ok(nexoflow_live_code($undo_template) === 'OK' && get_block_template($front_template, 'wp_template')->content === $original, 'The change to the homepage template can be undone');

    $current = nexoflow_live_act('templates.read', array('template' => $front_template, 'include_markup' => true));
    $current_shape = nexoflow_live_code($current) === 'OK' ? $current['body']['result'] : array('revision' => '', 'blocks' => array());
    $middle = (int) floor(count($current_shape['blocks']) / 2);
    $middle_markup = isset($current_shape['blocks'][$middle]['markup']) ? (string) $current_shape['blocks'][$middle]['markup'] : '';
    $swap_template = nexoflow_live_act('templates.replace', array('template' => $front_template, 'expected_revision' => $current_shape['revision'], 'replacements' => array(array('path' => (string) $middle, 'content' => $new_section))), array('mode' => 'live'));
    $swap_result = nexoflow_live_code($swap_template) === 'OK' ? $swap_template['body']['result'] : array('revision' => '', 'restore_revision_id' => 0, 'replaced' => array());
    nexoflow_live_ok(nexoflow_live_code($swap_template) === 'OK' && $swap_result['replaced'] === array('sections' => 1, 'blocks' => 2, 'removed' => 0), 'A section of the homepage template is replaced (' . nexoflow_live_code($swap_template) . ')');
    nexoflow_live_ok($middle_markup !== '' && substr_count($original, $middle_markup) === 1 && get_block_template($front_template, 'wp_template')->content === str_replace($middle_markup, $new_section, $original), 'The new section is in its place and the rest of the template is byte for byte as it was');
    $unswap_template = nexoflow_live_act('templates.restore', array('template' => $front_template, 'expected_revision' => $swap_result['revision'], 'revision_id' => $swap_result['restore_revision_id']), array('mode' => 'live'));
    nexoflow_live_ok(nexoflow_live_code($unswap_template) === 'OK' && get_block_template($front_template, 'wp_template')->content === $original, 'Replacing a section of the template can be undone');

    if ($had_post === 0 && !empty($changed_result['post_id'])) {
        wp_delete_post((int) $changed_result['post_id'], true);
        nexoflow_live_ok(get_block_template($front_template, 'wp_template')->source === 'theme', 'Cleaned up: the homepage comes from the theme file again');
    }
}

echo "\n-- Header and footer\n";
$listed_parts = isset($front['body']['result']['theme']['template_parts']) ? $front['body']['result']['theme']['template_parts'] : array();
$header_part = null;

foreach ($listed_parts as $listed_part) {
    if ($listed_part['area'] === 'header' && $header_part === null) {
        $header_part = $listed_part['id'];
    }
}

if (!is_string($header_part)) {
    echo "      Skipped: this theme has no header template part.\n";
} else {
    nexoflow_live_ok(true, 'site.read lists the template parts: ' . implode(', ', array_map(function ($part) {
        return $part['id'] . ' (' . $part['area'] . ')';
    }, $listed_parts)));
    $part_before = get_block_template($header_part, 'wp_template_part');
    $part_original = (string) $part_before->content;
    $part_file = get_theme_file_path('parts/' . $part_before->slug . '.html');
    $part_file_before = is_readable($part_file) ? md5_file($part_file) : '';
    $part_had_post = (int) $part_before->wp_id;
    $part_ref = array('template' => $header_part, 'kind' => 'part');
    $read_part = nexoflow_live_act('templates.read', $part_ref + array('include_markup' => true));
    $part_shape = nexoflow_live_code($read_part) === 'OK' ? $read_part['body']['result'] : array('revision' => '', 'blocks' => array(), 'area' => '', 'kind' => '');
    nexoflow_live_ok(nexoflow_live_code($read_part) === 'OK' && $part_shape['kind'] === 'part' && $part_shape['area'] === 'header' && count($part_shape['blocks']) > 0, 'The header is read as its own document: ' . implode(', ', wp_list_pluck($part_shape['blocks'], 'name')));
    nexoflow_live_ok(nexoflow_live_code(nexoflow_live_act('templates.read', array('template' => $header_part))) === 'NOT_FOUND', 'A template part is not found as a template');

    if (is_string($front_template)) {
        $with_parts = nexoflow_live_act('templates.read', array('template' => $front_template));
        $named = array();

        foreach (nexoflow_live_code($with_parts) === 'OK' ? $with_parts['body']['result']['blocks'] : array() as $shown) {
            if (isset($shown['template_part'])) {
                $named[] = $shown['template_part'];
            }
        }

        nexoflow_live_ok(in_array($header_part, $named, true), 'The homepage template says which header it shows: ' . implode(', ', $named));
    }

    $menu = "<!-- wp:group {\"tagName\":\"header\",\"layout\":{\"type\":\"flex\",\"justifyContent\":\"space-between\"}} -->\n<header class=\"wp-block-group\"><!-- wp:site-title /-->\n\n<!-- wp:navigation -->\n<!-- wp:navigation-link {\"label\":\"Services " . $run . "\",\"url\":\"#services\",\"kind\":\"custom\",\"isTopLevelLink\":true} /-->\n\n<!-- wp:navigation-link {\"label\":\"Questions\",\"url\":\"#faq\",\"kind\":\"custom\",\"isTopLevelLink\":true} /-->\n<!-- /wp:navigation --></header>\n<!-- /wp:group -->";
    $relink = $part_ref + array('expected_revision' => $part_shape['revision'], 'replacements' => array(array('path' => '0', 'content' => $menu)));
    nexoflow_live_ok(nexoflow_live_code(nexoflow_live_act('templates.replace', $relink)) === 'MODE_NOT_SUPPORTED', 'The header is not changed without live mode');
    $relinked = nexoflow_live_act('templates.replace', $relink, array('mode' => 'live', 'request_id' => $run . '_header'));
    $relinked_result = nexoflow_live_code($relinked) === 'OK' ? $relinked['body']['result'] : array('revision' => '', 'restore_revision_id' => 0, 'post_id' => 0, 'replaced' => array());
    $part_after = get_block_template($header_part, 'wp_template_part');
    nexoflow_live_ok(nexoflow_live_code($relinked) === 'OK' && $relinked_result['replaced']['sections'] === 1, 'The header gets a menu with links (' . nexoflow_live_code($relinked) . ')');
    nexoflow_live_ok($part_after->source === 'custom' && (int) $part_after->wp_id === (int) $relinked_result['post_id'] && get_post_type((int) $part_after->wp_id) === 'wp_template_part', 'The change is stored as a template part post, like the Site Editor does');
    nexoflow_live_ok($part_after->area === 'header' && has_term('header', 'wp_template_part_area', (int) $part_after->wp_id) && has_term(get_stylesheet(), 'wp_theme', (int) $part_after->wp_id), 'WordPress still knows it as the header of this theme');
    nexoflow_live_ok($part_file_before === '' || md5_file($part_file) === $part_file_before, 'The theme file is untouched');
    $kept_part = wp_get_post_revision($relinked_result['restore_revision_id']);
    nexoflow_live_ok($kept_part && $kept_part->post_content === $part_original, 'WordPress kept the header as it was before');
    $drawn = do_blocks('<!-- wp:template-part {"slug":"' . $part_before->slug . '","theme":"' . get_stylesheet() . '","tagName":"header"} /-->');
    nexoflow_live_ok(strpos($drawn, 'Services ' . $run) !== false && strpos($drawn, 'href="#services"') !== false && strpos($drawn, 'href="#faq"') !== false, 'The site now draws the header with the new links');
    $relink_again = nexoflow_live_act('templates.replace', $relink, array('mode' => 'live', 'request_id' => $run . '_header'));
    nexoflow_live_ok(nexoflow_live_code($relink_again) === 'OK' && $relink_again['body']['replayed'] === true, 'A repeated request does not change the header again');
    $unlink = nexoflow_live_act('templates.restore', $part_ref + array('expected_revision' => $relinked_result['revision'], 'revision_id' => $relinked_result['restore_revision_id']), array('mode' => 'live'));
    nexoflow_live_ok(nexoflow_live_code($unlink) === 'OK' && get_block_template($header_part, 'wp_template_part')->content === $part_original, 'The change to the header can be undone');

    if ($part_had_post === 0 && !empty($relinked_result['post_id'])) {
        wp_delete_post((int) $relinked_result['post_id'], true);
        nexoflow_live_ok(get_block_template($header_part, 'wp_template_part')->source === 'theme', 'Cleaned up: the header comes from the theme file again');
    }
}

echo "\n-- Images\n";
$picture = '';

if (function_exists('imagecreatetruecolor')) {
    $canvas = imagecreatetruecolor(640, 360);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 11, 61, 145));
    ob_start();
    imagejpeg($canvas, null, 85);
    $picture = (string) ob_get_clean();
    imagedestroy($canvas);
}

if ($picture === '') {
    // A one pixel PNG, for a PHP without GD.
    $picture = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
}

$is_jpeg = strncmp($picture, "\xFF\xD8\xFF", 3) === 0;
$send = array('filename' => 'Hero ' . $run . '.php', 'data' => base64_encode($picture), 'alt' => 'A blue garage door');
$sent = nexoflow_live_act('media.upload', $send, array('request_id' => $run . '_image'));
$stored_image = nexoflow_live_code($sent) === 'OK' ? $sent['body']['result'] : array('attachment_id' => 0, 'url' => '', 'mime_type' => '', 'width' => 0, 'height' => 0, 'filesize' => 0, 'alt' => '');
$stored_file = $stored_image['attachment_id'] ? (string) get_attached_file($stored_image['attachment_id']) : '';
nexoflow_live_ok(nexoflow_live_code($sent) === 'OK' && get_post_type($stored_image['attachment_id']) === 'attachment', 'An image is added to the media library (' . nexoflow_live_code($sent) . ')');
nexoflow_live_ok($stored_file !== '' && file_exists($stored_file) && file_get_contents($stored_file) === $picture && $stored_image['filesize'] === strlen($picture), 'The file on disk is the image that was sent');
nexoflow_live_ok(preg_match('/\.(jpg|png)$/', $stored_file) === 1 && strpos(basename($stored_file), '.php') === false && $stored_image['mime_type'] === ($is_jpeg ? 'image/jpeg' : 'image/png'), 'It is stored as the image it is, not under the extension it was sent with: ' . basename($stored_file));
nexoflow_live_ok(strpos($stored_image['url'], content_url('/uploads/')) === 0 && $stored_image['url'] === wp_get_attachment_url($stored_image['attachment_id']), 'The answer has the address of the image');
nexoflow_live_ok($stored_image['width'] === ($is_jpeg ? 640 : 1) && $stored_image['height'] === ($is_jpeg ? 360 : 1), 'The answer has its size');
nexoflow_live_ok($stored_image['alt'] === 'A blue garage door' && get_post_meta($stored_image['attachment_id'], '_wp_attachment_image_alt', true) === 'A blue garage door', 'The description is stored with it');
nexoflow_live_ok((int) get_post_field('post_author', $stored_image['attachment_id']) === $admin_id, 'It belongs to the acting account');
$attachments = (int) wp_count_posts('attachment')->inherit;
$sent_again = nexoflow_live_act('media.upload', $send, array('request_id' => $run . '_image'));
nexoflow_live_ok(nexoflow_live_code($sent_again) === 'OK' && $sent_again['body']['replayed'] === true && (int) wp_count_posts('attachment')->inherit === $attachments, 'A repeated request does not add the image twice');
$script = nexoflow_live_act('media.upload', array('filename' => 'door.jpg', 'data' => base64_encode('<?php echo "x"; ?>' . str_repeat(' ', 30))));
nexoflow_live_ok(nexoflow_live_code($script) === 'INVALID_PARAMETERS' && $script['body']['error']['details']['fields']['data'] === 'not_an_image', 'A script named like an image is refused');
$vector = nexoflow_live_act('media.upload', array('filename' => 'logo.svg', 'data' => base64_encode('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')));
nexoflow_live_ok(nexoflow_live_code($vector) === 'INVALID_PARAMETERS', 'An SVG is refused');
nexoflow_live_ok((int) wp_count_posts('attachment')->inherit === $attachments, 'Refused files are not stored');
$record_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE request_id = %s", $run . '_image'), ARRAY_A);
nexoflow_live_ok(is_array($record_row) && strpos(wp_json_encode($record_row), substr(base64_encode($picture), 0, 60)) === false && (int) $record_row['object_id'] >= 0, 'The request record does not keep the image');

if ($stored_image['attachment_id']) {
    wp_delete_attachment($stored_image['attachment_id'], true);
    nexoflow_live_ok(!file_exists($stored_file), 'Cleaned up: the test image is gone');
}

echo "\n-- Elementor\n";
$document_now = nexoflow_live_rest('GET', $capabilities);
$usable_now = nexoflow_live_code($document_now) === 'OK' && isset($document_now['body']['capabilities']) ? (array) $document_now['body']['capabilities'] : array();

if (!in_array('elementor.build', $usable_now, true) || !in_array('elementor.publish', $usable_now, true)) {
    echo "      Skipped: Elementor is not on this site, or its MCP is switched off.\n";
} else {
    $el_structure = function ($headline) {
        return array(
            'structure' => '<e-flexbox configuration-id="Hero"><e-heading configuration-id="Hero title"></e-heading></e-flexbox>',
            'settings' => array('Hero title' => array('tag' => 'h1', 'title' => $headline)),
            'styles' => array('Hero' => 'padding: 4rem 2rem;'),
            'replace' => true,
        );
    };
    $el_shown = function ($page_id) {
        // What a visitor would be served for this page, read the way Elementor renders it.
        clean_post_cache($page_id);
        \Elementor\Plugin::$instance->documents->get($page_id, false);

        return wp_strip_all_tags((string) \Elementor\Plugin::$instance->frontend->get_builder_content_for_display($page_id));
    };

    $el_guide = nexoflow_live_act('elementor.guide', array('types' => array(array('type' => 'e-heading'))));
    nexoflow_live_ok(nexoflow_live_code($el_guide) === 'OK' && count($el_guide['body']['result']['elements']) > 5 && isset($el_guide['body']['result']['settings']['e-heading']), 'Elementor says what it can build with, and how a heading is set');

    $el_made = nexoflow_live_act('elementor.create_page', array('title' => 'NexoFlow live test ' . $run, 'layout' => 'canvas'), array('mode' => 'draft'));
    $el_page = nexoflow_live_code($el_made) === 'OK' ? $el_made['body']['result'] : array('page_id' => 0, 'revision' => '');
    $el_id = (int) $el_page['page_id'];
    nexoflow_live_ok($el_id > 0 && get_post_status($el_id) === 'draft' && $el_page['builder'] === 'elementor' && get_page_template_slug($el_id) === 'elementor_canvas', 'A page is made with Elementor as a draft, with the layout asked for');

    $el_built = nexoflow_live_act('elementor.build', array('page_id' => $el_id, 'expected_revision' => $el_page['revision']) + $el_structure('First live headline'), array('mode' => 'draft'));
    $el_draft = nexoflow_live_code($el_built) === 'OK' ? $el_built['body']['result'] : array('revision' => '', 'staged' => null, 'built' => array());
    nexoflow_live_ok(nexoflow_live_code($el_built) === 'OK' && $el_draft['staged'] === false && $el_draft['built']['elements'] === 1 && get_post_status($el_id) === 'draft', 'The draft is built by Elementor itself (' . nexoflow_live_code($el_built) . ')');

    $el_bad = nexoflow_live_act('elementor.build', array('page_id' => $el_id, 'expected_revision' => $el_draft['revision'], 'structure' => '<e-flexbox configuration-id="Open">'), array('mode' => 'draft'));
    nexoflow_live_ok(nexoflow_live_code($el_bad) === 'INVALID_PARAMETERS' && $el_bad['body']['error']['message'] !== '', 'What Elementor refuses comes back with what it said: ' . $el_bad['body']['error']['message']);

    $el_live = nexoflow_live_act('elementor.publish', array('page_id' => $el_id, 'expected_revision' => $el_draft['revision']), array('mode' => 'live'));
    $el_first = nexoflow_live_code($el_live) === 'OK' ? $el_live['body']['result'] : array('revision' => '');
    nexoflow_live_ok(nexoflow_live_code($el_live) === 'OK' && get_post_status($el_id) === 'publish' && strpos($el_shown($el_id), 'First live headline') !== false, 'The page is published and shows what was built');

    sleep(1);
    $el_staged = nexoflow_live_act('elementor.build', array('page_id' => $el_id, 'expected_revision' => $el_first['revision']) + $el_structure('Second live headline'), array('mode' => 'draft'));
    $el_held = nexoflow_live_code($el_staged) === 'OK' ? $el_staged['body']['result'] : array('revision' => '', 'staged' => null);
    nexoflow_live_ok(nexoflow_live_code($el_staged) === 'OK' && $el_held['staged'] === true && strpos($el_shown($el_id), 'First live headline') !== false && strpos($el_shown($el_id), 'Second live headline') === false, 'A change to the published page is held back: visitors still get the first version');
    nexoflow_live_ok(get_page_template_slug($el_id) === 'elementor_canvas', 'While the change is held back the page keeps its layout');

    $el_second = nexoflow_live_act('elementor.publish', array('page_id' => $el_id, 'expected_revision' => $el_held['revision']), array('mode' => 'live'));
    $el_now = nexoflow_live_code($el_second) === 'OK' ? $el_second['body']['result'] : array('revision' => '', 'restore_revision_id' => 0);
    // Read before the page is rendered again below, which makes Elementor keep a new copy.
    $el_copy_dropped = get_post_meta($el_id, '_elementor_element_cache', true) === '';
    nexoflow_live_ok(nexoflow_live_code($el_second) === 'OK' && !empty($el_now['restore_revision_id']) && $el_copy_dropped, 'Publishing keeps the version from before and drops the rendered copy Elementor kept of the old one');
    nexoflow_live_ok(strpos($el_shown($el_id), 'Second live headline') !== false && strpos($el_shown($el_id), 'First live headline') === false, 'Visitors now get the second version');

    $el_front = nexoflow_live_act('site.set_front_page', array('page_id' => $el_id), array('mode' => 'live'));
    $el_was = nexoflow_live_code($el_front) === 'OK' ? $el_front['body']['result']['previous'] : array('show' => 'posts', 'page_id' => null);
    nexoflow_live_ok(nexoflow_live_code($el_front) === 'OK' && (int) get_option('page_on_front') === $el_id && $el_front['body']['result']['front_page']['template'] === null, 'The page becomes the homepage, and is reported as the homepage itself');

    sleep(1);
    $el_undo = nexoflow_live_act('pages.restore', array('page_id' => $el_id, 'expected_revision' => $el_now['revision'], 'revision_id' => (int) $el_now['restore_revision_id']), array('mode' => 'live'));
    nexoflow_live_ok(nexoflow_live_code($el_undo) === 'OK' && strpos($el_shown($el_id), 'First live headline') !== false && get_page_template_slug($el_id) === 'elementor_canvas', 'The change is undone: the first version is back, with its layout');

    $el_back = nexoflow_live_act('site.set_front_page', array('page_id' => (int) $el_was['page_id']), array('mode' => 'live'));
    nexoflow_live_ok(nexoflow_live_code($el_back) === 'OK' && (int) get_option('page_on_front') === (int) $el_was['page_id'], 'The earlier homepage is put back');

    if ($el_id > 0) {
        wp_delete_post($el_id, true);
    }
}

echo "\n-- Roles\n";
add_role($run . '_drafter', 'Live test drafter', array('read' => true, 'edit_pages' => true));
$author = wp_insert_user(array('user_login' => $run . '_author', 'user_pass' => wp_generate_password(24), 'user_email' => $run . '_author@example.test', 'role' => 'author'));
$drafter = wp_insert_user(array('user_login' => $run . '_drafter', 'user_pass' => wp_generate_password(24), 'user_email' => $run . '_drafter@example.test', 'role' => $run . '_drafter'));
$admin_draft = nexoflow_live_act('pages.create', array('title' => 'Administrator draft'));
$admin_draft_id = nexoflow_live_code($admin_draft) === 'OK' ? $admin_draft['body']['result']['page_id'] : 0;
$made_pages[] = $admin_draft_id;

nexoflow_live_act_as($author);
$as_author = nexoflow_live_act('pages.create', array('title' => 'By an author'));
nexoflow_live_ok($as_author['status'] === 403 && nexoflow_live_code($as_author) === 'INSUFFICIENT_PERMISSION', 'An Author cannot create pages');
nexoflow_live_ok(!in_array('pages.create', nexoflow_live_rest('GET', $capabilities)['body']['capabilities'], true), 'Discovery stops offering what the account may not do');

nexoflow_live_act_as($drafter);
nexoflow_live_ok(nexoflow_live_code(nexoflow_live_act('pages.get', array('page_id' => $admin_draft_id))) === 'INSUFFICIENT_PERMISSION', 'Permission is checked against the actual page');
$own = nexoflow_live_act('pages.create', array('title' => 'Drafter page'));
$own_id = nexoflow_live_code($own) === 'OK' ? $own['body']['result']['page_id'] : 0;
$made_pages[] = $own_id;
nexoflow_live_ok($own_id > 0 && (int) get_post_field('post_author', $own_id) === $drafter, 'An account creates and owns its own draft');
nexoflow_live_ok(count(nexoflow_live_act('pages.list')['body']['result']['pages']) === 1, 'Listing only returns pages the account may edit');
nexoflow_live_act_as($admin_id);

echo "\n-- Refusals\n";
$siteurl = get_option('siteurl');
nexoflow_live_ok(nexoflow_live_code(nexoflow_live_act('options.update', array('name' => 'siteurl', 'value' => 'https://example.invalid'))) === 'UNKNOWN_ACTION' && get_option('siteurl') === $siteurl, 'There is no action for options');
nexoflow_live_ok(nexoflow_live_code(nexoflow_live_act('pages.create', array('title' => 'x', 'post_status' => 'publish'))) === 'INVALID_PARAMETERS', 'post_status cannot be passed in');
nexoflow_live_ok(nexoflow_live_code(nexoflow_live_act('pages.create', array('title' => 'x'), array('credits' => 50))) === 'INVALID_REQUEST', 'A credits field is refused');
nexoflow_live_ok(nexoflow_live_code(nexoflow_live_act('pages.get', array('page_id' => 999999999))) === 'NOT_FOUND', 'A page that does not exist is NOT_FOUND');

echo "\n-- Request record\n";
$record = array('request_id' => $run . '_claim', 'authorization_id' => '', 'action' => 'pages.create', 'fingerprint' => str_repeat('a', 64), 'user_id' => $admin_id);
nexoflow_live_ok($store->claim($record) === true && $store->claim($record) === false, 'A request_id can be claimed once only');
$store->fail($run . '_claim', 'EXECUTION_FAILED');
nexoflow_live_ok($store->find_claim($run . '_claim') === null && $store->claim($record) === true, 'A failed request frees its request_id');
$store->abandon($run . '_claim', 'EXECUTION_FAILED');
nexoflow_live_ok($store->claim($record) === false, 'An abandoned request keeps its request_id');
nexoflow_live_ok((int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE claim IS NULL") > 1, 'Refused requests are recorded side by side');
nexoflow_live_ok(count($store->recent(5)) === 5, 'recent() returns the latest requests');

echo "\n-- Switch and diagnostics\n";
update_option(NexoFlow_Agent::ENABLED_OPTION, '0', false);
$off = nexoflow_live_rest('GET', $capabilities);
nexoflow_live_ok($off['status'] === 403 && nexoflow_live_code($off) === 'AGENT_DISABLED' && $sync->can_sync() === true, 'Switched off: the agent refuses, sync is unaffected');
delete_option(NexoFlow_Agent::ENABLED_OPTION);

wp_set_current_user($admin_id);
ob_start();
do_action('nexoflow_settings_cards');
$card = ob_get_clean();
wp_set_current_user(0);
nexoflow_live_ok(strpos($card, '<code>pages.create</code>') !== false && strpos($card, $key) === false, 'The diagnostics card renders and does not print the key');
nexoflow_live_ok(empty($GLOBALS['nexoflow_live']['notices']), 'No PHP notices from the plugin' . (empty($GLOBALS['nexoflow_live']['notices']) ? '' : ': ' . implode(' | ', array_slice($GLOBALS['nexoflow_live']['notices'], 0, 5))));

foreach (array_filter($made_pages) as $made_page) {
    wp_delete_post($made_page, true);
}

require_once ABSPATH . 'wp-admin/includes/user.php';

foreach (array($author, $drafter) as $made_user) {
    if (is_int($made_user)) {
        wp_delete_user($made_user);
    }
}

remove_role($run . '_drafter');
$wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE request_id LIKE %s", $wpdb->esc_like($run) . '%'));
delete_option('nexoflow_site_key');
delete_option(NexoFlow_Agent_Actor::OPTION);
restore_error_handler();

echo "\n" . $GLOBALS['nexoflow_live']['ok'] . ' passed, ' . $GLOBALS['nexoflow_live']['fail'] . " failed\n";

if ($GLOBALS['nexoflow_live']['fail'] > 0) {
    exit(1);
}
