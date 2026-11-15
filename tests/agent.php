<?php

require_once __DIR__ . '/stubs-agent.php';

$nexoflow_includes = dirname(__DIR__) . '/nexoflow/includes/';

require_once $nexoflow_includes . 'class-auth.php';
require_once $nexoflow_includes . 'class-rest.php';
require_once $nexoflow_includes . 'class-seo-markup.php';
require_once $nexoflow_includes . 'class-seo.php';
require_once $nexoflow_includes . 'class-page-cache.php';
require_once $nexoflow_includes . 'agent/class-agent-error.php';
require_once $nexoflow_includes . 'agent/interface-agent-provider.php';
require_once $nexoflow_includes . 'agent/interface-agent-builder.php';
require_once $nexoflow_includes . 'agent/class-agent-actor.php';
require_once $nexoflow_includes . 'agent/class-agent-site.php';
require_once $nexoflow_includes . 'agent/class-agent-validator.php';
require_once $nexoflow_includes . 'agent/class-agent-request-store.php';
require_once $nexoflow_includes . 'agent/class-agent-documents.php';
require_once $nexoflow_includes . 'agent/class-agent-templates.php';
require_once $nexoflow_includes . 'agent/class-agent-abilities.php';
require_once $nexoflow_includes . 'agent/class-agent-capabilities.php';
require_once $nexoflow_includes . 'agent/class-agent-actions.php';
require_once $nexoflow_includes . 'agent/class-agent-dispatcher.php';
require_once $nexoflow_includes . 'agent/providers/class-provider-wordpress.php';
require_once $nexoflow_includes . 'agent/providers/class-provider-gutenberg.php';
require_once $nexoflow_includes . 'agent/providers/class-provider-media.php';
require_once $nexoflow_includes . 'agent/providers/class-provider-elementor.php';
require_once $nexoflow_includes . 'agent/providers/class-provider-seo.php';
require_once $nexoflow_includes . 'agent/class-agent-rest.php';
require_once $nexoflow_includes . 'class-settings.php';
require_once $nexoflow_includes . 'agent/class-agent-admin.php';
require_once $nexoflow_includes . 'agent/class-agent.php';
require_once __DIR__ . '/agent-world.php';

nexoflow_section('Authentication');

$world = new NexoFlow_Test_World();
$rest = new NexoFlow_Rest();
$agent_rest = new NexoFlow_Agent_Rest(new NexoFlow_Agent());

delete_option('nexoflow_site_key');
nexoflow_assert(NexoFlow_Agent_Rest::authenticate()->code() === 'NOT_CONNECTED', 'A site with no connect key refuses agent requests');
nexoflow_assert(NexoFlow_Agent_Rest::authenticate()->status() === 401, 'Not connected answers 401');
update_option('nexoflow_site_key', NEXOFLOW_TEST_KEY);

nexoflow_assert(NexoFlow_Agent_Rest::authenticate()->code() === 'MISSING_CREDENTIALS', 'Missing credentials are refused');

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer not-a-key';
nexoflow_assert(NexoFlow_Agent_Rest::authenticate()->code() === 'INVALID_CREDENTIALS', 'Invalid credentials are refused');

$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode('admin:password');
nexoflow_assert(NexoFlow_Agent_Rest::authenticate()->code() === 'MISSING_CREDENTIALS', 'A WordPress login is not a site connect key');

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . NEXOFLOW_TEST_OTHER_KEY;
nexoflow_assert(NexoFlow_Agent_Rest::authenticate()->code() === 'INVALID_CREDENTIALS', 'The key of a different connected site is refused');
nexoflow_assert(is_wp_error($rest->can_sync()), 'Sync still refuses the key of a different site');

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . NEXOFLOW_TEST_KEY;
nexoflow_assert(NexoFlow_Agent_Rest::authenticate() === null, 'Valid credentials are accepted');
nexoflow_assert($agent_rest->authorize() === true, 'The REST permission check passes with valid credentials');
nexoflow_assert($rest->can_sync() === true, 'Sync still accepts the site connect key');

update_option(NexoFlow_Agent::ENABLED_OPTION, '0');
nexoflow_assert(NexoFlow_Agent_Rest::authenticate()->code() === 'AGENT_DISABLED', 'A switched-off Website Agent refuses a valid key');
nexoflow_assert(NexoFlow_Agent_Rest::authenticate()->status() === 403, 'Switched off answers 403');
nexoflow_assert($rest->can_sync() === true, 'Switching the Website Agent off leaves publishing alone');
update_option(NexoFlow_Agent::ENABLED_OPTION, '1');

$GLOBALS['nexoflow_filters']['nexoflow_agent_enabled'] = function () {
    return false;
};
nexoflow_assert(NexoFlow_Agent_Rest::authenticate()->code() === 'AGENT_DISABLED', 'The nexoflow_agent_enabled filter can switch the agent off');
unset($GLOBALS['nexoflow_filters']['nexoflow_agent_enabled']);

unset($_SERVER['HTTP_AUTHORIZATION']);
$denied = $agent_rest->authorize();
nexoflow_assert(is_wp_error($denied) && $denied->get_error_data()['status'] === 401, 'The REST permission check refuses with a 401');

$route = '/nexoflow/v1/actions/execute';
$enveloped = $agent_rest->envelope($denied, array(), new WP_REST_Request($route));
nexoflow_assert($enveloped instanceof WP_REST_Response && $enveloped->status === 401, 'A refusal keeps its status');
nexoflow_assert($enveloped->data['success'] === false && $enveloped->data['error']['code'] === 'MISSING_CREDENTIALS', 'A refusal uses the structured error shape');
nexoflow_assert($enveloped->headers['Cache-Control'] === 'no-store', 'Agent answers are never cached');

$bad_json = $agent_rest->envelope(new WP_Error('rest_invalid_json', 'Invalid JSON body passed.', array('status' => 400)), array(), new WP_REST_Request($route));
nexoflow_assert($bad_json->data['error']['code'] === 'INVALID_REQUEST', 'A body that is not JSON becomes INVALID_REQUEST');

$other_route = $agent_rest->envelope($denied, array(), new WP_REST_Request('/nexoflow/v1/sync'));
nexoflow_assert($other_route === $denied, 'Errors on other routes are left as they are');

$agent_rest->routes();
nexoflow_assert(isset($GLOBALS['nexoflow_routes']['/nexoflow/v1/site/capabilities']), 'Registers the capabilities route');
nexoflow_assert($GLOBALS['nexoflow_routes']['/nexoflow/v1/actions/execute']['methods'] === 'POST', 'Registers the action route as POST');
nexoflow_assert(is_callable($GLOBALS['nexoflow_routes']['/nexoflow/v1/actions/execute']['permission_callback']), 'Every agent route has a permission check');

$site_id = $world->site->id();
nexoflow_assert(preg_match('/^nfsite_[0-9a-f]{32}$/', $site_id) === 1, 'The site has a stable identifier');
nexoflow_assert($world->site->id() === $site_id, 'The site identifier does not change between requests');
nexoflow_assert(nexoflow_code($world->call('site.read', array(), array('site_id' => $site_id))) === 'OK', 'A request addressed to this site runs');
nexoflow_assert(nexoflow_code($world->call('site.read', array(), array('site_id' => 'nfsite_' . str_repeat('0', 32)))) === 'SITE_MISMATCH', 'A request addressed to another site is refused');
$rows = $world->rows();
nexoflow_assert(count($rows) === 1 && $rows[0]['status'] === 'rejected' && $rows[0]['error_code'] === 'SITE_MISMATCH', 'A request addressed to another site is recorded');

$GLOBALS['nexoflow_home'] = 'https://staging.example.com';
nexoflow_assert($world->site->id() !== $site_id, 'A copy of the site on another address gets its own identifier');
nexoflow_assert(nexoflow_code($world->call('site.read', array(), array('site_id' => $site_id))) === 'SITE_MISMATCH', 'A copied site refuses requests meant for the original');
$GLOBALS['nexoflow_home'] = 'http://EXAMPLE.com';
nexoflow_assert($world->site->id() === $site_id, 'Moving to https or changing letter case keeps the identifier');

nexoflow_section('Capability discovery');

$world = new NexoFlow_Test_World();
$gutenberg = $world->gutenberg->detect();
nexoflow_assert($gutenberg['installed'] && $gutenberg['active'] && $gutenberg['supported'] && $gutenberg['available'], 'Detects the block editor');
nexoflow_assert(in_array('blocks.read', $world->capabilities->usable(), true), 'Reports blocks.read when blocks are available');
nexoflow_assert($world->capabilities->usable() === array('blocks.insert', 'blocks.read', 'blocks.update', 'media.upload', 'pages.create', 'pages.read', 'pages.restore', 'pages.update', 'site.front_page', 'site.read'), 'Reports exactly the capabilities this site has');

$classic = new NexoFlow_Test_World(array(), array('pages_use_blocks' => false, 'classic_editor_plugin' => true));
$detected = $classic->gutenberg->detect();
nexoflow_assert($detected['supported'] && !$detected['active'] && !$detected['available'], 'A site on the classic editor does not have the block editor available');
nexoflow_assert($detected['reason'] === 'BLOCK_EDITOR_DISABLED', 'Says why the block editor is not available');

$elementor = $world->elementor->detect();
nexoflow_assert(!$elementor['installed'] && !$elementor['active'] && !$elementor['available'], 'Elementor is unavailable when it is not installed');
nexoflow_assert($elementor['reason'] === 'ELEMENTOR_NOT_INSTALLED', 'Says Elementor is not installed');
nexoflow_assert($elementor['mcp']['available'] === false, 'Elementor MCP is unavailable without Elementor');
nexoflow_assert(!in_array('elementor.inspect', $world->capabilities->usable(), true), 'Does not report Elementor capabilities without Elementor');
nexoflow_assert($world->capabilities->get('elementor.inspect')['reason'] === 'ELEMENTOR_NOT_INSTALLED', 'The capability carries the reason it is unavailable');

$inactive = new NexoFlow_Test_World(array('installed' => true, 'active' => false, 'version' => '4.4.0'));
$detected = $inactive->elementor->detect();
nexoflow_assert($detected['installed'] && !$detected['active'] && !$detected['supported'], 'Installed but inactive Elementor is not supported');
nexoflow_assert($detected['version'] === '4.4.0' && $detected['reason'] === 'ELEMENTOR_INACTIVE', 'Reports the version and that Elementor is inactive');
nexoflow_assert(!in_array('elementor.inspect', $inactive->capabilities->usable(), true), 'Installed is not the same as available');

$old = new NexoFlow_Test_World(
    array('installed' => true, 'active' => true, 'version' => '3.25.4', 'mcp_adapter' => true, 'abilities' => NEXOFLOW_TEST_ELEMENTOR_ABILITIES)
);
$detected = $old->elementor->detect();
nexoflow_assert($detected['active'] && !$detected['supported'] && !$detected['available'], 'An Elementor version before 4.3.0 is unsupported');
nexoflow_assert($detected['reason'] === 'ELEMENTOR_VERSION_UNSUPPORTED' && $detected['minimum_version'] === '4.3.0', 'Says which Elementor version is needed');
nexoflow_assert($detected['mcp']['abilities'] === array(), 'Abilities that are not from a supported Elementor are not trusted');

$no_api = new NexoFlow_Test_World(array('installed' => true, 'active' => true, 'version' => '4.4.0', 'abilities_api' => false));
nexoflow_assert($no_api->elementor->detect()['reason'] === 'ABILITIES_API_UNAVAILABLE', 'A supported Elementor still needs the WordPress Abilities API');

$mcp_off = new NexoFlow_Test_World(array('installed' => true, 'active' => true, 'version' => '4.4.0', 'mcp_adapter' => true));
$detected = $mcp_off->elementor->detect();
nexoflow_assert($detected['supported'] && !$detected['mcp']['available'] && !$detected['available'], 'Supported Elementor with MCP switched off is not available');
nexoflow_assert($detected['reason'] === 'ELEMENTOR_MCP_DISABLED', 'Says Elementor MCP is switched off');
nexoflow_assert(nexoflow_code($mcp_off->call('elementor.inspect', array('page_id' => 1))) === 'CAPABILITY_UNAVAILABLE', 'Elementor actions are refused while MCP is off');

$no_adapter = new NexoFlow_Test_World(array('installed' => true, 'active' => true, 'version' => '4.4.0'));
nexoflow_assert($no_adapter->elementor->detect()['reason'] === 'ELEMENTOR_MCP_UNAVAILABLE', 'Says when Elementor MCP is not loaded at all');

$ready = new NexoFlow_Test_World(nexoflow_elementor_ready() + array('pro_installed' => true, 'pro_active' => true, 'pro_version' => '4.4.0'));
$detected = $ready->elementor->detect();
nexoflow_assert($detected['supported'] && $detected['available'] && $detected['mcp']['available'], 'Detects a supported Elementor with MCP available');
nexoflow_assert($detected['pro']['active'] && $detected['pro']['version'] === '4.4.0', 'Detects Elementor Pro');
nexoflow_assert($detected['atomic_editor'] === true && $detected['mcp']['adapter'] === true, 'Reports the Atomic Editor and the MCP adapter');
nexoflow_assert(in_array('elementor.inspect', $ready->capabilities->usable(), true), 'Reports elementor.inspect when Elementor offers it');

$partial = new NexoFlow_Test_World(
    array('installed' => true, 'active' => true, 'version' => '4.4.0', 'mcp_adapter' => true, 'abilities' => array('elementor/create-page'))
);
nexoflow_assert($partial->capabilities->get('elementor.inspect')['reason'] === 'ELEMENTOR_ABILITY_MISSING', 'A capability needs the specific ability behind it');

$real = new NexoFlow_Agent_Provider_Elementor(new NexoFlow_Agent_Abilities(), new NexoFlow_Agent_Documents());
nexoflow_assert($real->detect()['reason'] === 'ELEMENTOR_NOT_INSTALLED', 'The real probe finds no Elementor on a site without it');

mkdir(WP_PLUGIN_DIR . '/elementor', 0777, true);
file_put_contents(WP_PLUGIN_DIR . '/elementor/elementor.php', "<?php\n/**\n * Plugin Name: Elementor\n * Version: 4.4.0\n */\n");
$real = new NexoFlow_Agent_Provider_Elementor(new NexoFlow_Agent_Abilities(), new NexoFlow_Agent_Documents());
$detected = $real->detect();
nexoflow_assert($detected['installed'] && !$detected['active'] && $detected['version'] === '4.4.0', 'The real probe reads the version of an installed, inactive Elementor');
nexoflow_assert($real->owns((object) array('ID' => 1)) === false, 'An inactive Elementor owns no pages');
unlink(WP_PLUGIN_DIR . '/elementor/elementor.php');
rmdir(WP_PLUGIN_DIR . '/elementor');
rmdir(WP_PLUGIN_DIR);

$nothing = function () {
    return null;
};
$GLOBALS['nexoflow_abilities']['elementor/get-page-structure'] = new NexoFlow_Test_Ability('elementor/get-page-structure', $nothing);
$GLOBALS['nexoflow_abilities']['other-plugin/export'] = new NexoFlow_Test_Ability('other-plugin/export', $nothing);
$bridge = new NexoFlow_Agent_Abilities();
nexoflow_assert($bridge->names('elementor/') === array('elementor/get-page-structure'), 'Lists only the abilities under a prefix');
$GLOBALS['nexoflow_did_action']['init'] = 0;
nexoflow_assert($bridge->names('elementor/') === array() && !$bridge->has('elementor/get-page-structure'), 'Does not touch the abilities registry before init');
unset($GLOBALS['nexoflow_did_action']);

$world = new NexoFlow_Test_World();
$agent = new NexoFlow_Agent();
$document = $agent->discovery();
$json = json_encode($document);
nexoflow_assert($document['success'] === true && $document['api_version'] === '1', 'Discovery answers with the API version');
nexoflow_assert($document['site']['connected'] === true && $document['site']['id'] === $world->site->id(), 'Discovery identifies the connected site');
nexoflow_assert($document['wordpress']['version'] === '6.9' && $document['wordpress']['php_version'] !== '', 'Discovery reports the WordPress and PHP versions');
nexoflow_assert($document['builders']['gutenberg']['available'] === true, 'Discovery reports the block editor');
nexoflow_assert($document['builders']['elementor']['installed'] === false, 'Discovery reports Elementor as not installed');
nexoflow_assert($document['capabilities'] === array('blocks.insert', 'blocks.read', 'blocks.update', 'content.trash', 'media.describe', 'media.upload', 'pages.create', 'pages.read', 'pages.restore', 'pages.update', 'redirects.manage', 'seo.page', 'seo.read', 'seo.site', 'site.front_page', 'site.read', 'speed.cache'), 'Discovery lists the usable capabilities');
nexoflow_assert($document['capability_details']['elementor.inspect']['usable'] === false, 'Discovery explains the capabilities that are not usable');
nexoflow_assert($document['agent']['default_mode'] === 'draft' && $document['agent']['modes'] === array('draft', 'live'), 'Discovery says changes are drafts unless the request says live');
nexoflow_assert($document['agent']['live_edits'] === array('available' => true, 'reason' => '', 'message' => ''), 'Discovery says whether published pages can be changed');
nexoflow_assert($document['site']['front_page'] === array('show' => 'posts', 'page_id' => null, 'template' => null), 'Discovery says when the homepage is not a page');
nexoflow_assert($document['capability_details']['templates.update']['reason'] === 'BLOCK_THEME_REQUIRED', 'Templates are not offered on a site without a block theme');
nexoflow_assert($document['actor']['id'] === 1 && $document['actor']['permissions']['publish_pages'] === true, 'Discovery reports what the acting account may do');
nexoflow_assert(strpos($json, NEXOFLOW_TEST_KEY) === false, 'Discovery never contains the site connect key');
nexoflow_assert(strpos($json, (string) get_option(NexoFlow_Agent_Site::SECRET_OPTION)) === false, 'Discovery never contains the site secret');
nexoflow_assert(stripos($json, 'api_key') === false && stripos($json, 'openai') === false && stripos($json, 'credit') === false, 'Discovery has no AI provider or credit fields');

$by_name = array();

foreach ($document['actions'] as $action) {
    $by_name[$action['name']] = $action;
}

nexoflow_assert(array_keys($by_name) === array('blocks.insert', 'blocks.read', 'blocks.replace', 'content.restore', 'content.trash', 'elementor.build', 'elementor.create_page', 'elementor.guide', 'elementor.inspect', 'elementor.publish', 'media.missing_alt', 'media.set_alt', 'media.upload', 'pages.create', 'pages.get', 'pages.list', 'pages.restore', 'pages.update', 'redirects.list', 'redirects.remove', 'redirects.set', 'seo.get_page', 'seo.pages', 'seo.status', 'seo.update_page', 'seo.update_site', 'site.read', 'site.set_front_page', 'speed.set_cache', 'templates.insert', 'templates.read', 'templates.replace', 'templates.restore'), 'Discovery lists every action in the allowlist');
nexoflow_assert($by_name['pages.create']['modes'] === array('draft') && $by_name['pages.update']['modes'] === array('draft', 'live') && $by_name['blocks.insert']['modes'] === array('draft', 'live'), 'Each action says which modes it accepts');
nexoflow_assert($by_name['pages.create']['mutates'] === true && $by_name['pages.create']['provider'] === 'wordpress', 'Each action names its provider and whether it changes the site');
nexoflow_assert($by_name['pages.create']['parameters']['title']['required'] === true, 'Each action describes its parameters');
nexoflow_assert($by_name['elementor.inspect']['available'] === false && $by_name['blocks.read']['provider'] === 'gutenberg', 'Gutenberg and Elementor are separate providers');
nexoflow_assert(!isset($by_name['pages.update']['parameters']['expected_revision']['pattern']), 'Server-side patterns are not part of the contract');

$no_store = new NexoFlow_Test_World(array(), array(), false);
nexoflow_assert($no_store->capabilities->usable() === array('blocks.read', 'pages.read', 'site.read'), 'Without request tracking only reading is offered');
nexoflow_assert($no_store->capabilities->get('pages.create')['reason'] === 'STORAGE_UNAVAILABLE', 'Says why changes are not offered');

$GLOBALS['nexoflow_dbdelta_fails'] = true;
$failing = new NexoFlow_Agent_Request_Store();
nexoflow_assert($failing->install() === false && !$failing->ready(), 'A table that could not be created leaves tracking off');
nexoflow_assert(get_transient(NexoFlow_Agent_Request_Store::RETRY_TRANSIENT) === 1, 'A failed table install is not retried on every request');
$GLOBALS['nexoflow_dbdelta_fails'] = false;
delete_transient(NexoFlow_Agent_Request_Store::RETRY_TRANSIENT);
$failing->maybe_install();
nexoflow_assert($failing->ready(), 'The table is installed on the next attempt');
nexoflow_assert(strpos($GLOBALS['nexoflow_dbdelta'], 'UNIQUE KEY claim (claim)') !== false, 'The table keeps one claim per request_id');
nexoflow_assert(strpos($GLOBALS['nexoflow_dbdelta'], 'PRIMARY KEY  (id)') !== false, 'The table definition follows the dbDelta format');

nexoflow_section('Acting account and permissions');

$world = new NexoFlow_Test_World();
delete_option(NexoFlow_Agent_Actor::OPTION);
nexoflow_assert($world->actor->id() === 1, 'A connected site with no recorded account uses its first administrator');
nexoflow_assert((int) get_option(NexoFlow_Agent_Actor::OPTION) === 1, 'The chosen account is remembered');

delete_option(NexoFlow_Agent_Actor::OPTION);
delete_option('nexoflow_site_key');
nexoflow_assert($world->actor->id() === 0, 'A site that is not connected has no acting account');
update_option('nexoflow_site_key', NEXOFLOW_TEST_KEY);

update_option(NexoFlow_Agent_Actor::OPTION, 99);
nexoflow_assert($world->actor->id() === 1, 'A deleted acting account falls back to an administrator');

unset($GLOBALS['nexoflow_users'][1]);
delete_option(NexoFlow_Agent_Actor::OPTION);
$world->capabilities->reset();
nexoflow_assert($world->actor->id() === 0 && $world->actor->describe() === null, 'No administrator means no acting account');
nexoflow_assert($world->capabilities->usable() === array(), 'Nothing is usable without an acting account');
nexoflow_assert($world->capabilities->get('pages.create')['reason'] === 'NO_ACTING_USER', 'Says there is no acting account');
nexoflow_assert(nexoflow_code($world->call('pages.create', array('title' => 'Draft'))) === 'NO_ACTING_USER', 'Actions are refused without an acting account');
nexoflow_assert($world->page_count() === 0, 'Nothing is created without an acting account');

$world = new NexoFlow_Test_World();
$agent = new NexoFlow_Agent();
$agent->register(null);
do_action('nexoflow_site_key_saved', 2);
nexoflow_assert((int) get_option(NexoFlow_Agent_Actor::OPTION) === 2, 'The administrator who connects the site becomes the acting account');
do_action('nexoflow_disconnected');
nexoflow_assert(get_option(NexoFlow_Agent_Actor::OPTION, 'gone') === 'gone', 'Disconnecting forgets the acting account');
nexoflow_assert($world->actor->set(99) === false, 'An account that does not exist cannot be the acting account');

$world = new NexoFlow_Test_World();
$own = $world->page(array('post_author' => 3, 'post_title' => 'Drafter page'));
$foreign = $world->page(array('post_author' => 1, 'post_title' => 'Admin page'));

$world->act_as(2);
nexoflow_assert(nexoflow_code($world->call('pages.get', array('page_id' => $foreign))) === 'OK', 'An account that can edit the page reads it');
nexoflow_assert($world->actor->describe()['permissions']['publish_pages'] === true, 'Reports an account that can publish');

$world->act_as(3);
nexoflow_assert(nexoflow_code($world->call('pages.get', array('page_id' => $own))) === 'OK', 'An account reads the page it is allowed to edit');
nexoflow_assert(nexoflow_code($world->call('pages.get', array('page_id' => $foreign))) === 'INSUFFICIENT_PERMISSION', 'An account that cannot edit the page is refused');
$denied_update = $world->call('pages.update', array('page_id' => $foreign, 'expected_revision' => str_repeat('a', 16), 'title' => 'Taken over'));
nexoflow_assert(nexoflow_code($denied_update) === 'INSUFFICIENT_PERMISSION' && $denied_update['status'] === 403, 'Changing a page the account cannot edit answers 403');
nexoflow_assert($GLOBALS['nexoflow_posts'][$foreign]['post_title'] === 'Admin page', 'A refused change leaves the page alone');
nexoflow_assert($world->actor->describe()['permissions']['publish_pages'] === false, 'Reports an account that cannot publish');
$listed = nexoflow_result($world->call('pages.list'));
nexoflow_assert(count($listed['pages']) === 1 && $listed['pages'][0]['page_id'] === $own, 'Listing only returns pages the account can edit');
nexoflow_assert($GLOBALS['nexoflow_last_query']['author'] === 3, 'Listing counts only the pages of an account that cannot edit others');

$published = $world->page(array('post_author' => 3, 'post_status' => 'publish'));
nexoflow_assert(nexoflow_code($world->call('pages.get', array('page_id' => $published))) === 'INSUFFICIENT_PERMISSION', 'Permissions are checked against the actual page, not the role');

$world->act_as(4);
nexoflow_assert(!in_array('pages.create', $world->capabilities->usable(), true), 'An account without page rights is not offered page capabilities');
nexoflow_assert($world->capabilities->get('pages.create')['available'] && !$world->capabilities->get('pages.create')['permissioned'], 'Available and permissioned are reported separately');
$refused = $world->call('pages.create', array('title' => 'Not allowed'));
nexoflow_assert(nexoflow_code($refused) === 'INSUFFICIENT_PERMISSION', 'An account without page rights cannot create a page');
nexoflow_assert(nexoflow_details($refused)['requires'] === 'edit_pages', 'Says which WordPress capability is missing');
nexoflow_assert($world->page_count() === 2 + 1, 'A refused create adds no page');

foreach (array(2, 3) as $user_id) {
    $world->act_as($user_id);
    $attempt = $world->call('pages.create', array('title' => 'Go live'), array('mode' => 'publish'));
    nexoflow_assert(nexoflow_code($attempt) === 'MODE_NOT_SUPPORTED', 'Publishing is refused for account ' . $user_id . ' whether or not it may publish');
}

$statuses = array();

foreach ($GLOBALS['nexoflow_posts'] as $post) {
    $statuses[] = $post['post_status'];
}

nexoflow_assert(array_count_values($statuses)['publish'] === 1, 'No request published anything');

$world = new NexoFlow_Test_World();
wp_set_current_user(4);
$world->call('pages.create', array('title' => 'Runs as the acting account'));
nexoflow_assert(get_current_user_id() === 4, 'The previous user is restored after an action');
$GLOBALS['nexoflow_insert_throws'] = true;
$world->call('pages.create', array('title' => 'Breaks'));
nexoflow_assert(get_current_user_id() === 4, 'The previous user is restored even when an action breaks');
$GLOBALS['nexoflow_insert_throws'] = false;
wp_set_current_user(0);

nexoflow_section('Actions');

$world = new NexoFlow_Test_World();
$site = $world->call('site.read');
nexoflow_assert(nexoflow_code($site) === 'OK' && $site['status'] === 200, 'A valid action runs');
nexoflow_assert($site['body']['action'] === 'site.read' && $site['body']['request_id'] === $world->last_request_id, 'The answer names the action and the request');
nexoflow_assert(nexoflow_result($site)['theme']['name'] === 'Hello Elementor', 'site.read reports the theme');
nexoflow_assert(array_column(nexoflow_result($site)['post_types'], 'name') === array('post', 'page'), 'site.read reports the public post types');
nexoflow_assert(nexoflow_result($site)['integrations']['woocommerce']['active'] === false, 'site.read reports WooCommerce');

$created = $world->call(
    'pages.create',
    array('title' => 'Garage Door Installation', 'content' => "<!-- wp:paragraph -->\n<p>C:\\doors \"quoted\"</p>\n<!-- /wp:paragraph -->"),
    array('authorization_id' => 'nf_auth_456')
);
$page = nexoflow_result($created);
$stored = $GLOBALS['nexoflow_posts'][$page['page_id']];
nexoflow_assert(nexoflow_code($created) === 'OK', 'pages.create succeeds');
nexoflow_assert($page['status'] === 'draft' && $stored['post_status'] === 'draft', 'A created page is a draft');
nexoflow_assert($stored['post_type'] === 'page' && (int) $stored['post_author'] === 1, 'A created page belongs to the acting account');
nexoflow_assert($page['builder'] === 'gutenberg' && strlen($page['revision']) === 16, 'A created page reports its builder and revision');
nexoflow_assert($created['body']['mode'] === 'draft' && $created['body']['replayed'] === false, 'The answer says the change was a draft');
nexoflow_assert($created['body']['authorization_id'] === 'nf_auth_456', 'The authorization reference is returned');
nexoflow_assert($stored['meta']['_nexoflow_agent_request'] === $world->last_request_id, 'A created page records the request that made it');
nexoflow_assert(strpos($stored['post_content'], 'C:\\doors "quoted"') !== false, 'Backslashes and quotes in content are kept');
nexoflow_assert(strpos($page['edit_url'], 'post=' . $page['page_id']) !== false, 'A created page comes with its edit link');
$plain_link = 'post=' . $page['page_id'] . '&action=edit';
nexoflow_assert(substr($page['edit_url'], -strlen($plain_link)) === $plain_link, 'The edit link is a plain URL');
nexoflow_assert($stored['post_modified_gmt'] === '0000-00-00 00:00:00' && $page['modified_gmt'] === '2026-10-05T10:00:00', 'A draft that was never edited still reports when it was modified');

$unknown = $world->call('pages.destroy');
nexoflow_assert(nexoflow_code($unknown) === 'UNKNOWN_ACTION' && $unknown['status'] === 404, 'An unknown action is refused');

$unavailable = $world->call('elementor.inspect', array('page_id' => $page['page_id']));
nexoflow_assert(nexoflow_code($unavailable) === 'CAPABILITY_UNAVAILABLE' && $unavailable['status'] === 409, 'An action whose capability is unavailable is refused');
nexoflow_assert(nexoflow_details($unavailable)['reason'] === 'ELEMENTOR_NOT_INSTALLED', 'The refusal carries the reason');

$missing = $world->call('pages.create', array('content' => 'No title'));
nexoflow_assert(nexoflow_code($missing) === 'INVALID_PARAMETERS' && $missing['status'] === 422, 'A missing parameter is refused');
nexoflow_assert(nexoflow_details($missing)['fields']['title'] === 'required', 'Says which parameter is missing');
nexoflow_assert(nexoflow_details($world->call('pages.create', array('title' => 42)))['fields']['title'] === 'type', 'A parameter of the wrong type is refused');
nexoflow_assert(nexoflow_details($world->call('pages.create', array('title' => str_repeat('a', 201))))['fields']['title'] === 'too_long', 'A parameter that is too long is refused');
nexoflow_assert(nexoflow_details($world->call('pages.create', array('title' => '<b></b>')))['fields']['title'] === 'empty', 'A title with no text is refused');
nexoflow_assert(nexoflow_details($world->call('pages.list', array('per_page' => 500)))['fields']['per_page'] === 'too_large', 'A number out of range is refused');
nexoflow_assert(nexoflow_details($world->call('pages.list', array('status' => 'trash')))['fields']['status'] === 'not_allowed', 'A value outside the allowed list is refused');
nexoflow_assert(nexoflow_code($world->call('pages.create', array('title' => 'Ok', 'parent_id' => 999))) === 'NOT_FOUND', 'A parent page that does not exist is refused');

nexoflow_assert(nexoflow_code($world->call('pages.get', array('page_id' => 999))) === 'NOT_FOUND', 'A page ID that does not exist is refused');
nexoflow_assert(nexoflow_details($world->call('pages.get', array('page_id' => '12')))['fields']['page_id'] === 'type', 'A page ID must be a number');
nexoflow_assert(nexoflow_details($world->call('pages.get', array('page_id' => 0)))['fields']['page_id'] === 'too_small', 'A page ID must be positive');
$post_id = wp_insert_post(array('post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'An article', 'post_author' => 1));
nexoflow_assert(nexoflow_code($world->call('pages.get', array('page_id' => $post_id))) === 'NOT_FOUND', 'The ID of something that is not a page is refused');
$trashed = $world->page(array('post_status' => 'trash'));
nexoflow_assert(nexoflow_code($world->call('pages.get', array('page_id' => $trashed))) === 'NOT_FOUND', 'A page in the trash is refused');

$live = $world->page(array('post_status' => 'publish', 'post_title' => 'Live page'));
$live_revision = nexoflow_result($world->call('pages.get', array('page_id' => $live)))['revision'];
$on_live = $world->call('pages.update', array('page_id' => $live, 'expected_revision' => $live_revision, 'title' => 'Changed live'));
nexoflow_assert(nexoflow_code($on_live) === 'DRAFT_REQUIRED' && nexoflow_details($on_live)['status'] === 'publish', 'A published page cannot be changed');
nexoflow_assert($GLOBALS['nexoflow_posts'][$live]['post_title'] === 'Live page', 'A published page is left as it was');

$nothing = $world->call('pages.update', array('page_id' => $page['page_id'], 'expected_revision' => $page['revision']));
nexoflow_assert(nexoflow_code($nothing) === 'INVALID_PARAMETERS' && nexoflow_details($nothing)['any_of'] === array('title', 'content', 'excerpt', 'slug'), 'An update that changes nothing is refused');

$dispatcher = $world->dispatcher;
nexoflow_assert(nexoflow_code($dispatcher->dispatch(null)) === 'INVALID_REQUEST', 'A request with no body is refused');
nexoflow_assert(nexoflow_code($dispatcher->dispatch(array('pages.create'))) === 'INVALID_REQUEST', 'A request that is a list is refused');
nexoflow_assert(nexoflow_code($dispatcher->dispatch(array('action' => 'site.read'))) === 'INVALID_REQUEST', 'A request without a request_id is refused');
nexoflow_assert(nexoflow_code($dispatcher->dispatch(array('request_id' => 'short', 'action' => 'site.read'))) === 'INVALID_REQUEST', 'A request_id that is too short is refused');
nexoflow_assert(nexoflow_code($dispatcher->dispatch(array('request_id' => 'nf_req_no_action'))) === 'INVALID_REQUEST', 'A request without an action is refused');
nexoflow_assert(nexoflow_code($world->call('site.read', array(), array('parameters' => array('a', 'b')))) === 'INVALID_REQUEST', 'Parameters that are a list are refused');
nexoflow_assert(nexoflow_code($world->call('site.read', array(), array('mode' => 'everywhere'))) === 'INVALID_REQUEST', 'A mode that does not exist is refused');
nexoflow_assert(nexoflow_code($world->call('pages.create', array('title' => 'Live from the start'), array('mode' => 'live'))) === 'MODE_NOT_SUPPORTED', 'A new page cannot be created live');
nexoflow_assert($dispatcher->dispatch(null)['body']['request_id'] === null && $dispatcher->dispatch(null)['status'] === 400, 'A refused request still answers in the structured shape');

for ($i = 0; $i < 3; $i++) {
    $world->page(array('post_title' => 'Service area ' . $i, 'post_name' => 'service-area-' . $i));
}

$first_page = nexoflow_result($world->call('pages.list', array('per_page' => 2)));
nexoflow_assert(count($first_page['pages']) === 2 && $first_page['total'] === 5 && $first_page['total_pages'] === 3, 'pages.list pages through the results');
nexoflow_assert($first_page['pages'][0]['title'] === 'Service area 2', 'pages.list returns the most recently changed page first');
nexoflow_assert(count(nexoflow_result($world->call('pages.list', array('status' => 'publish')))['pages']) === 1, 'pages.list filters by status');
nexoflow_assert(count(nexoflow_result($world->call('pages.list', array('search' => 'service area')))['pages']) === 3, 'pages.list searches');
nexoflow_assert(!isset($first_page['pages'][0]['content']), 'pages.list does not return content');
$tie_a = wp_insert_post(array('post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Same second A', 'post_author' => 1));
$tie_b = wp_insert_post(array('post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Same second B', 'post_author' => 1));
$tied = nexoflow_result($world->call('pages.list', array('search' => 'Same second')));
nexoflow_assert(array_column($tied['pages'], 'page_id') === array($tie_b, $tie_a), 'Pages changed in the same second are listed in a fixed order');

$full = nexoflow_result($world->call('pages.get', array('page_id' => $page['page_id'])));
nexoflow_assert(strpos($full['content'], 'wp:paragraph') !== false && $full['excerpt'] === '', 'pages.get returns the content');
nexoflow_assert(!isset(nexoflow_result($world->call('pages.get', array('page_id' => $page['page_id'], 'include_content' => false)))['content']), 'pages.get can leave the content out');

$markup = "<!-- wp:heading {\"level\":2} -->\n<h2>Our process</h2>\n<!-- /wp:heading -->\n\n"
    . "<!-- wp:columns -->\n<div class=\"wp-block-columns\">"
    . "<!-- wp:column -->\n<div class=\"wp-block-column\"><!-- wp:paragraph -->\n<p>Step one</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:column -->\n\n"
    . "<!-- wp:column -->\n<div class=\"wp-block-column\"><!-- wp:image {\"id\":7} /--></div>\n<!-- /wp:column -->"
    . "</div>\n<!-- /wp:columns -->";
$block_page = $world->page(array('post_content' => $markup));
$blocks = nexoflow_result($world->call('blocks.read', array('page_id' => $block_page)));
nexoflow_assert($blocks['has_blocks'] === true && $blocks['block_count'] === 6 && $blocks['builder'] === 'gutenberg', 'blocks.read counts the blocks of a page');
nexoflow_assert(array_column($blocks['blocks'], 'name') === array('core/heading', 'core/columns'), 'blocks.read leaves out the whitespace between blocks');
nexoflow_assert($blocks['blocks'][0]['attributes'] === array('level' => 2) && $blocks['blocks'][0]['html'] === '<h2>Our process</h2>', 'blocks.read returns attributes and markup');
nexoflow_assert($blocks['blocks'][1]['inner_blocks'][1]['inner_blocks'][0]['path'] === '1.1.0', 'blocks.read gives every block a path');
nexoflow_assert($blocks['blocks'][1]['inner_blocks'][1]['inner_blocks'][0]['name'] === 'core/image', 'blocks.read follows nested blocks');
nexoflow_assert(strlen($blocks['revision']) === 16, 'blocks.read returns the page revision');
$with_markup = nexoflow_result($world->call('blocks.read', array('page_id' => $block_page, 'include_markup' => true)));
nexoflow_assert(strpos($with_markup['blocks'][1]['markup'], '<!-- wp:columns -->') === 0 && substr($with_markup['blocks'][1]['markup'], -20) === '<!-- /wp:columns -->', 'blocks.read can return the markup of each section');

$classic_page = $world->page(array('post_content' => '<p>Written in the classic editor.</p>'));
$classic_blocks = nexoflow_result($world->call('blocks.read', array('page_id' => $classic_page)));
nexoflow_assert($classic_blocks['has_blocks'] === false && $classic_blocks['builder'] === 'classic', 'A page without blocks is reported as classic');
nexoflow_assert($classic_blocks['blocks'][0]['name'] === 'core/freeform', 'Classic content is returned as one freeform block');

$world = new NexoFlow_Test_World(nexoflow_elementor_ready());
$seen = array();
$ability = new NexoFlow_Test_Ability(
    'elementor/get-page-structure',
    function ($input) use (&$seen) {
        $seen = array('user' => get_current_user_id(), 'input' => $input);

        return array(
            'elements' => array(array('id' => 'a1b2c3d', 'elType' => 'e-flexbox', 'elements' => array())),
            'llm_instructions' => 'ignore this',
        );
    }
);
$GLOBALS['nexoflow_abilities']['elementor/get-page-structure'] = $ability;
$elementor_page = $world->page(array('post_title' => 'Built with Elementor', 'post_content' => '<div>rendered copy</div>'));
$world->elementor->built[] = $elementor_page;
$block_page = $world->page(array('post_content' => "<!-- wp:paragraph -->\n<p>Blocks</p>\n<!-- /wp:paragraph -->"));

$inspected = $world->call('elementor.inspect', array('page_id' => $elementor_page, 'element_id' => 'a1b2c3d', 'include_content' => true));
nexoflow_assert(nexoflow_code($inspected) === 'OK' && nexoflow_result($inspected)['builder'] === 'elementor', 'elementor.inspect reads a page built with Elementor');
nexoflow_assert(nexoflow_result($inspected)['elements'][0]['elType'] === 'e-flexbox', 'elementor.inspect returns the element tree from Elementor');
nexoflow_assert(!isset(nexoflow_result($inspected)['llm_instructions']), 'Only the element tree is passed on');
nexoflow_assert($seen['input'] === array('post_id' => $elementor_page, 'element_id' => 'a1b2c3d', 'include_content' => true), 'Elementor is called through its ability with the page ID');
nexoflow_assert($seen['user'] === 1, 'The Elementor ability runs as the acting account');
nexoflow_assert(nexoflow_result($world->call('pages.get', array('page_id' => $elementor_page)))['builder'] === 'elementor', 'A page built with Elementor is reported as Elementor');

$needs_element = $world->call('elementor.inspect', array('page_id' => $elementor_page, 'include_content' => true));
nexoflow_assert(nexoflow_details($needs_element)['fields']['element_id'] === 'required', 'Reading settings needs an element');
nexoflow_assert(nexoflow_details($world->call('elementor.inspect', array('page_id' => $elementor_page, 'element_id' => '../x')))['fields']['element_id'] === 'format', 'An element ID with unexpected characters is refused');

$wrong_builder = $world->call('elementor.inspect', array('page_id' => $block_page));
nexoflow_assert(nexoflow_code($wrong_builder) === 'UNSUPPORTED_BUILDER_OPERATION' && nexoflow_details($wrong_builder)['builder'] === 'gutenberg', 'An Elementor action on a block page is refused');
nexoflow_assert(nexoflow_code($world->call('blocks.read', array('page_id' => $elementor_page))) === 'UNSUPPORTED_BUILDER_OPERATION', 'A block action on an Elementor page is refused');

$elementor_revision = nexoflow_result($world->call('pages.get', array('page_id' => $elementor_page)))['revision'];
$overwrite = $world->call('pages.update', array('page_id' => $elementor_page, 'expected_revision' => $elementor_revision, 'content' => '<p>Flattened</p>'));
nexoflow_assert(nexoflow_code($overwrite) === 'UNSUPPORTED_BUILDER_OPERATION', 'The content of an Elementor page cannot be overwritten');
nexoflow_assert($GLOBALS['nexoflow_posts'][$elementor_page]['post_content'] === '<div>rendered copy</div>', 'A refused overwrite leaves the Elementor page alone');
$retitle = $world->call('pages.update', array('page_id' => $elementor_page, 'expected_revision' => $elementor_revision, 'title' => 'Garage Door Installation'));
nexoflow_assert(nexoflow_code($retitle) === 'OK', 'The title of an Elementor draft can still be changed');

$ability_errors = array(
    'INSUFFICIENT_PERMISSION' => new WP_Error('rest_cannot_view', 'Sorry, you are not allowed to edit this document.', array('status' => 403)),
    'NOT_FOUND' => new WP_Error('element_not_found', 'Element not found in this document.', array('status' => 404)),
    'INVALID_PARAMETERS' => new WP_Error('ability_invalid_input', 'Invalid input.'),
    'CAPABILITY_UNAVAILABLE' => new WP_Error('elementor_v4_required', 'This site needs the Atomic Editor turned on.', array('status' => 403)),
    'PROVIDER_ERROR' => new WP_Error('db_error', "<b>Fatal</b>\nquery failed", array('status' => 500)),
);

foreach ($ability_errors as $expected => $wp_error) {
    $GLOBALS['nexoflow_abilities']['elementor/get-page-structure'] = new NexoFlow_Test_Ability(
        'elementor/get-page-structure',
        function () use ($wp_error) {
            return $wp_error;
        }
    );
    $failed_call = $world->call('elementor.inspect', array('page_id' => $elementor_page));
    nexoflow_assert(nexoflow_code($failed_call) === $expected, 'An Elementor error becomes ' . $expected);
}

nexoflow_assert(nexoflow_details($failed_call)['provider_code'] === 'db_error' && $failed_call['body']['error']['message'] === 'Fatal query failed', 'An Elementor error is passed on as plain text');

$world->act_as(3);
$own_elementor = $world->page(array('post_author' => 3));
$world->elementor->built[] = $own_elementor;
$GLOBALS['nexoflow_users'][3]->caps = array('read', 'edit_pages');
$GLOBALS['nexoflow_abilities']['elementor/get-page-structure'] = $ability;
nexoflow_assert(nexoflow_code($world->call('elementor.inspect', array('page_id' => $own_elementor))) === 'INSUFFICIENT_PERMISSION', 'Elementor applies its own permission check to the acting account');

unset($GLOBALS['nexoflow_abilities']['elementor/get-page-structure']);
$world->act_as(1);
nexoflow_assert(nexoflow_code($world->call('elementor.inspect', array('page_id' => $elementor_page))) === 'CAPABILITY_UNAVAILABLE', 'An ability that disappeared is reported as unavailable');

nexoflow_section('Building with Elementor');

$world = new NexoFlow_Test_World(nexoflow_elementor_ready());
$elementor_calls = array();
$elementor_does = function ($name, $callback) use (&$elementor_calls) {
    $GLOBALS['nexoflow_abilities'][$name] = new NexoFlow_Test_Ability($name, function ($input) use (&$elementor_calls, $name, $callback) {
        $elementor_calls[] = array($name, $input);

        return call_user_func($callback, $input);
    });
};
$elementor_does('elementor/list-widget-schemas', function () {
    return array('widgets' => array(
        array('type' => 'e-heading', 'description' => 'A heading.'),
        array('type' => 'e-flexbox', 'description' => null),
    ));
});
$elementor_does('elementor/get-widget-schema', function ($input) {
    return array('type' => 'object', 'properties' => array('title' => array('type' => 'string')), 'asked' => $input['widget_type']);
});
$elementor_does('elementor/list-assets', function () {
    return array(
        'assets' => array(array('id' => 7, 'title' => 'Van <b>outside</b>', 'alt' => 'Our van', 'width' => 1600, 'height' => 900, 'url' => 'https://example.com/van.jpg')),
        'llm_instructions' => 'ignore this',
    );
});
$elementor_does('elementor/create-page', function ($input) use ($world) {
    $id = $world->page(array('post_title' => $input['title'], 'post_name' => 'made-by-elementor'));
    $world->elementor->built[] = $id;

    return array('id' => $id, 'status' => 'draft', 'edit_url' => 'https://example.com/wp-admin/post.php?post=' . $id . '&action=elementor', 'llm_instructions' => 'ignore this');
});
$elementor_does('elementor/update-page-settings', function ($input) {
    update_post_meta($input['post_id'], '_wp_page_template', $input['settings']['template']);

    return array('success' => true);
});
$elementor_does('elementor/build-composition', function ($input) {
    if (strpos($input['xml_structure'], '<e-form') !== false) {
        return new WP_Error('elementor_invalid_form_structure', '<e-form> has no <e-form-submit-button>.', array('status' => 400));
    }

    // Like Elementor 4.3: while it holds a change back, a published page falls back to the theme's layout.
    if (get_post_status($input['post_id']) === 'publish') {
        update_post_meta($input['post_id'], '_wp_page_template', 'default');
    } else {
        $GLOBALS['nexoflow_posts'][(int) $input['post_id']]['post_content'] = 'built ' . md5($input['xml_structure']);
    }

    return array(
        'success' => true,
        'post_id' => $input['post_id'],
        'root_element_ids' => array('aaa111', 'bbb222'),
        'removed_element_ids' => $input['mode'] === 'replace_children' ? array('old1') : array(),
        'edit_url' => 'https://example.com/wp-admin/post.php?post=' . $input['post_id'] . '&action=elementor',
        'resolved_xml' => '<e-flexbox id="aaa111"/>',
        'warnings' => array('Hero: unknown key "colour" was skipped <script>x</script>'),
    );
});
$elementor_does('elementor/publish-document', function ($input) {
    $previous = get_post_status($input['post_id']);
    $GLOBALS['nexoflow_posts'][(int) $input['post_id']]['post_status'] = 'publish';

    return array('post_id' => $input['post_id'], 'status' => 'publish', 'previous_status' => $previous, 'llm_instructions' => 'ignore this');
});

nexoflow_assert(array_values(array_intersect(array('elementor.build', 'elementor.inspect', 'elementor.publish', 'site.front_page'), $world->capabilities->usable())) === array('elementor.build', 'elementor.inspect', 'elementor.publish', 'site.front_page'), 'A site where Elementor is ready offers building and publishing with it');

$guide = $world->call('elementor.guide', array('types' => array(array('type' => 'e-heading'))));
$guided = nexoflow_result($guide);
nexoflow_assert(nexoflow_code($guide) === 'OK' && $guided['elements'] === array(array('type' => 'e-heading', 'description' => 'A heading.'), array('type' => 'e-flexbox', 'description' => '')), 'The guide lists what Elementor can build with on this site');
nexoflow_assert(array_keys($guided['settings']) === array('e-heading') && $guided['settings']['e-heading']['asked'] === 'e-heading', 'The guide returns the settings of the element types asked for');
nexoflow_assert($guided['images'] === array(array('id' => 7, 'title' => 'Van outside', 'alt' => 'Our van', 'width' => 1600, 'height' => 900)) && strpos(wp_json_encode($guided), 'ignore this') === false, 'The guide lists the library images by id, cleaned, and nothing else of what Elementor said');
nexoflow_assert(nexoflow_details($world->call('elementor.guide', array('types' => array(array('type' => 'e-rocket')))))['fields']['types'] === 'not_allowed', 'An element type Elementor does not have here is refused');
nexoflow_assert(nexoflow_details($world->call('elementor.guide', array('types' => array(array('type' => '../x')))))['fields']['types.0.type'] === 'format', 'An element type with unexpected characters is refused');

$structure = '<e-flexbox configuration-id="Hero"><e-heading configuration-id="Hero title"></e-heading></e-flexbox>';
$settings = array('Hero title' => array('tag' => 'h1', 'title' => 'Ember & Brew', 'link' => array('destination' => 'https://example.com', 'tag' => 'a')));
$styles = array('Hero' => 'padding: 6rem 2rem; @media(--mobile) { padding: 3rem 1rem; }');

nexoflow_assert(nexoflow_code($world->call('elementor.create_page', array('title' => 'Ember & Brew'), array('mode' => 'live'))) === 'MODE_NOT_SUPPORTED', 'A page made with Elementor is never created live');
$created = $world->call('elementor.create_page', array('title' => 'Ember & Brew', 'layout' => 'canvas'), array('mode' => 'draft'));
$made = nexoflow_result($created);
$el_page = (int) $made['page_id'];
nexoflow_assert(nexoflow_code($created) === 'OK' && $made['status'] === 'draft' && $made['builder'] === 'elementor' && $made['layout'] === 'canvas', 'A page made with Elementor starts as a draft');
nexoflow_assert(get_page_template_slug($el_page) === 'elementor_canvas' && $made['builder_edit_url'] === 'https://example.com/wp-admin/post.php?post=' . $el_page . '&action=elementor' && !isset($made['llm_instructions']), 'It gets the layout asked for and the address of its editor');
nexoflow_assert(nexoflow_details($world->call('elementor.create_page', array(), array('mode' => 'draft')))['fields']['title'] === 'required' && nexoflow_details($world->call('elementor.create_page', array('title' => 'x', 'layout' => 'sideways'), array('mode' => 'draft')))['fields']['layout'] === 'not_allowed', 'A new page needs a title and a layout Elementor has');
$full_width = nexoflow_result($world->call('elementor.create_page', array('title' => 'Default layout'), array('mode' => 'draft')));
nexoflow_assert(get_page_template_slug($full_width['page_id']) === 'elementor_header_footer', 'Without a layout the page is edge to edge inside the theme');

$elementor_calls = array();
$build = array('page_id' => $el_page, 'expected_revision' => $made['revision'], 'structure' => $structure, 'settings' => $settings, 'styles' => $styles, 'replace' => true);
$built = $world->call('elementor.build', $build, array('mode' => 'draft'));
$draft = nexoflow_result($built);
nexoflow_assert(nexoflow_code($built) === 'OK' && $draft['staged'] === false && $draft['built'] === array('elements' => 2, 'removed' => 1) && $draft['root_element_ids'] === array('aaa111', 'bbb222'), 'A draft is built as written');
nexoflow_assert($elementor_calls === array(array('elementor/build-composition', array('post_id' => $el_page, 'xml_structure' => $structure, 'mode' => 'replace_children', 'element_config' => $settings, 'style' => $styles))), 'Elementor is asked through its own ability, with nothing added');
nexoflow_assert($draft['warnings'] === array('Hero: unknown key "colour" was skipped x') && !isset($draft['resolved_xml']), 'What Elementor left out is passed on as plain text');
nexoflow_assert(nexoflow_code($world->call('elementor.build', $build, array('mode' => 'draft'))) === 'CONTENT_CONFLICT', 'Building on a page that changed since it was read is a conflict');
nexoflow_assert(nexoflow_code($world->call('elementor.build', $build, array('mode' => 'live'))) === 'MODE_NOT_SUPPORTED', 'Building is never a live change: Elementor holds it back or the page is a draft');

$fresh = array('page_id' => $el_page, 'expected_revision' => $draft['revision']);
$appended = $world->call('elementor.build', $fresh + array('structure' => $structure, 'parent_id' => 'aaa111'), array('mode' => 'draft'));
$last_call = $elementor_calls[count($elementor_calls) - 1][1];
nexoflow_assert(nexoflow_code($appended) === 'OK' && $last_call['mode'] === 'append' && $last_call['parent_id'] === 'aaa111' && !isset($last_call['element_config']), 'Without replace the new elements are added, inside the element named');
$fresh['expected_revision'] = nexoflow_result($appended)['revision'];

$refused_form = $world->call('elementor.build', $fresh + array('structure' => '<e-form configuration-id="Inquiry"></e-form>'), array('mode' => 'draft'));
nexoflow_assert(nexoflow_code($refused_form) === 'INVALID_PARAMETERS' && $refused_form['body']['error']['message'] === '"e-form" has no "e-form-submit-button".', 'What Elementor refuses is passed on with the elements it names still readable');
nexoflow_assert(nexoflow_details($world->call('elementor.build', $fresh + array('structure' => ''), array('mode' => 'draft')))['fields']['structure'] === 'too_short', 'There has to be something to build');
nexoflow_assert(nexoflow_details($world->call('elementor.build', $fresh + array('structure' => str_repeat('x', 200001)), array('mode' => 'draft')))['fields']['structure'] === 'too_long', 'A page has a size limit');
nexoflow_assert(nexoflow_details($world->call('elementor.build', $fresh + array('structure' => $structure, 'settings' => array('first', 'second')), array('mode' => 'draft')))['fields']['settings'] === 'type', 'Settings are a map from element to its settings');
nexoflow_assert(nexoflow_details($world->call('elementor.build', $fresh + array('structure' => $structure, 'styles' => array('Hero' => array('padding' => '1rem'))), array('mode' => 'draft')))['fields']['styles'] === 'type', 'A style is a CSS string');
$deep = array('a' => array('b' => array('c' => array('d' => array('e' => array('f' => array('g' => array('h' => array('i' => array('j' => array('k' => array('l' => array('m' => 1)))))))))))));
nexoflow_assert(nexoflow_details($world->call('elementor.build', $fresh + array('structure' => $structure, 'settings' => $deep), array('mode' => 'draft')))['fields']['settings'] === 'too_deep', 'Settings nested without end are refused');
nexoflow_assert(nexoflow_details($world->call('elementor.build', $fresh + array('structure' => $structure, 'settings' => array('Hero' => array('text' => str_repeat('x', 400001)))), array('mode' => 'draft')))['fields']['settings'] === 'too_long', 'Settings have a size limit');
nexoflow_assert(nexoflow_details($world->call('elementor.build', $fresh + array('structure' => $structure, 'parent_id' => '../x'), array('mode' => 'draft')))['fields']['parent_id'] === 'format', 'A parent with unexpected characters is refused');

$block_page = $world->page(array('post_content' => "<!-- wp:paragraph -->\n<p>Blocks</p>\n<!-- /wp:paragraph -->"));
$block_revision = nexoflow_result($world->call('pages.get', array('page_id' => $block_page)))['revision'];
nexoflow_assert(nexoflow_code($world->call('elementor.build', array('page_id' => $block_page, 'expected_revision' => $block_revision, 'structure' => $structure), array('mode' => 'draft'))) === 'UNSUPPORTED_BUILDER_OPERATION', 'A page the block editor made is not built on with Elementor');

nexoflow_assert(nexoflow_code($world->call('elementor.publish', $fresh, array('mode' => 'draft'))) === 'MODE_NOT_SUPPORTED', 'Publishing is always a live change');
nexoflow_assert(nexoflow_code($world->call('elementor.publish', array('page_id' => $el_page, 'expected_revision' => 'aaaaaaaaaaaaaaaa'), array('mode' => 'live'))) === 'CONTENT_CONFLICT', 'A page that changed since it was read is not published');
$published = $world->call('elementor.publish', $fresh, array('mode' => 'live'));
$live_page = nexoflow_result($published);
nexoflow_assert(nexoflow_code($published) === 'OK' && $live_page['status'] === 'publish' && $live_page['previous_status'] === 'draft' && !isset($live_page['restore_revision_id']) && !isset($live_page['llm_instructions']), 'A draft is published; there is no earlier version of it to keep');
nexoflow_assert(get_page_template_slug($el_page) === 'elementor_canvas', 'Publishing keeps the layout');

nexoflow_tick();
$staged = $world->call('elementor.build', array('page_id' => $el_page, 'expected_revision' => $live_page['revision'], 'structure' => $structure, 'replace' => true), array('mode' => 'draft'));
$held = nexoflow_result($staged);
nexoflow_assert(nexoflow_code($staged) === 'OK' && $held['staged'] === true && $held['status'] === 'publish', 'A change to a published page is held back, which needs no live mode');
nexoflow_assert(get_page_template_slug($el_page) === 'elementor_canvas', 'While Elementor holds a change back the page keeps its layout, so visitors keep seeing it');

$second = $world->call('elementor.publish', array('page_id' => $el_page, 'expected_revision' => $held['revision']), array('mode' => 'live'));
$second_page = nexoflow_result($second);
nexoflow_assert(nexoflow_code($second) === 'OK' && $second_page['previous_status'] === 'publish' && $second_page['restore_revision_id'] > 0, 'Putting the held change live keeps the version from before, even though the post itself did not change');
nexoflow_assert(wp_get_post_revision($second_page['restore_revision_id'])->post_parent === $el_page, 'That version belongs to the page');

$GLOBALS['nexoflow_revisions_to_keep'] = 0;
$world->capabilities->reset();
$no_restore = $world->call('elementor.publish', array('page_id' => $el_page, 'expected_revision' => $second_page['revision']), array('mode' => 'live'));
nexoflow_assert(in_array(nexoflow_code($no_restore), array('RESTORE_POINT_UNAVAILABLE', 'CAPABILITY_UNAVAILABLE'), true), 'A published page is not changed on a site that keeps no earlier versions');
$GLOBALS['nexoflow_revisions_to_keep'] = -1;
$world->capabilities->reset();

nexoflow_tick();
update_post_meta($el_page, '_wp_page_template', 'elementor_canvas');
$now = nexoflow_result($world->call('pages.get', array('page_id' => $el_page)))['revision'];
$GLOBALS['nexoflow_hooks']['wp_restore_post_revision'] = array();
$undone = $world->call('pages.restore', array('page_id' => $el_page, 'expected_revision' => $now, 'revision_id' => $second_page['restore_revision_id']), array('mode' => 'live'));
nexoflow_assert(nexoflow_code($undone) === 'OK' && nexoflow_result($undone)['restore_revision_id'] > $second_page['restore_revision_id'] && get_page_template_slug($el_page) === 'elementor_canvas', 'A page built with Elementor can be put back, keeps its layout, and the version it had is kept too');

nexoflow_section('Choosing the homepage');

$front = $world->call('site.set_front_page', array('page_id' => $el_page), array('mode' => 'live'));
nexoflow_assert(nexoflow_code($front) === 'OK' && nexoflow_result($front)['front_page']['show'] === 'page' && nexoflow_result($front)['front_page']['page_id'] === $el_page && nexoflow_result($front)['previous'] === array('show' => 'posts', 'page_id' => null), 'A published page becomes the homepage, and the answer says what it was before');
nexoflow_assert(get_option('show_on_front') === 'page' && (int) get_option('page_on_front') === $el_page, 'WordPress has it on record');
nexoflow_assert(nexoflow_code($world->call('site.set_front_page', array('page_id' => $el_page))) === 'MODE_NOT_SUPPORTED', 'The homepage is only changed in live mode');
nexoflow_assert(nexoflow_details($world->call('site.set_front_page', array('page_id' => $block_page), array('mode' => 'live')))['fields']['page_id'] === 'not_published' && (int) get_option('page_on_front') === $el_page, 'A draft cannot be the homepage');
nexoflow_assert(nexoflow_code($world->call('site.set_front_page', array('page_id' => 99999), array('mode' => 'live'))) === 'NOT_FOUND', 'A page that is not there cannot be the homepage');
nexoflow_assert(nexoflow_details($world->call('site.set_front_page', array(), array('mode' => 'live')))['fields']['page_id'] === 'required' && nexoflow_details($world->call('site.set_front_page', array('page_id' => -1), array('mode' => 'live')))['fields']['page_id'] === 'too_small', 'The page has to be named');

$world->block_theme(array('front-page' => "<!-- wp:paragraph -->\n<p>The theme's own homepage</p>\n<!-- /wp:paragraph -->"));
nexoflow_assert(nexoflow_result($world->call('site.read'))['site']['front_page'] === array('show' => 'page', 'page_id' => $el_page, 'template' => null), 'A page Elementor draws from top to bottom is reported as the homepage, not the theme template it replaces');
update_post_meta($el_page, '_wp_page_template', 'default');
$world->capabilities->reset();
nexoflow_assert(is_string(nexoflow_result($world->call('site.read'))['site']['front_page']['template']), 'Inside the theme layout the theme template still draws the homepage, and is reported');
update_post_meta($el_page, '_wp_page_template', 'elementor_canvas');

$world->act_as(2);
nexoflow_assert(nexoflow_code($world->call('site.set_front_page', array('page_id' => 0), array('mode' => 'live'))) === 'INSUFFICIENT_PERMISSION' && (int) get_option('page_on_front') === $el_page, 'An account that cannot manage the site cannot change its homepage');
$world->act_as(1);
$back = $world->call('site.set_front_page', array('page_id' => 0), array('mode' => 'live'));
nexoflow_assert(nexoflow_code($back) === 'OK' && get_option('show_on_front') === 'posts' && nexoflow_result($back)['previous']['page_id'] === $el_page, 'The site can go back to its own homepage');
$GLOBALS['nexoflow_block_theme'] = false;

nexoflow_section('Idempotency');

$world = new NexoFlow_Test_World();
$request = array('request_id' => 'nf_req_create_install_page');
$params = array('title' => 'Garage Door Installation', 'slug' => 'garage-door-installation');
$first = $world->call('pages.create', $params, $request);
nexoflow_assert(nexoflow_code($first) === 'OK' && $first['body']['replayed'] === false, 'The first request runs');
nexoflow_assert($world->page_count() === 1, 'The first request creates one page');

$second = $world->call('pages.create', $params, $request);
nexoflow_assert(nexoflow_code($second) === 'OK' && $second['body']['replayed'] === true, 'A repeated request is answered from the record');
nexoflow_assert(nexoflow_result($second) === nexoflow_result($first), 'A repeated request returns the previous result');
nexoflow_assert($world->page_count() === 1, 'A repeated request does not create a second page');

$reordered = $world->call('pages.create', array('slug' => 'garage-door-installation', 'title' => 'Garage Door Installation'), $request);
nexoflow_assert($reordered['body']['replayed'] === true, 'The order of the parameters does not matter');

$reused = $world->call('pages.create', array('title' => 'A different page'), $request);
nexoflow_assert(nexoflow_code($reused) === 'REQUEST_ID_REUSED' && $reused['status'] === 409, 'A request_id cannot be reused for a different request');
nexoflow_assert($world->page_count() === 1, 'A reused request_id changes nothing');

$rows = $world->rows();
nexoflow_assert(count($rows) === 1 && $rows[0]['status'] === 'completed' && (int) $rows[0]['object_id'] === nexoflow_result($first)['page_id'], 'The request is recorded once, with the page it made');
nexoflow_assert((int) $rows[0]['user_id'] === 1 && strlen($rows[0]['fingerprint']) === 64, 'The record names the acting account');

$page = nexoflow_result($first);
$update = array('page_id' => $page['page_id'], 'expected_revision' => $page['revision'], 'title' => 'Garage Door Installation in Santa Clara');
nexoflow_tick();
$updated = $world->call('pages.update', $update, array('request_id' => 'nf_req_update_title'));
$retried = $world->call('pages.update', $update, array('request_id' => 'nf_req_update_title'));
nexoflow_assert(nexoflow_code($updated) === 'OK' && nexoflow_result($updated)['revision'] !== $page['revision'], 'An update changes the revision');
nexoflow_assert(nexoflow_code($retried) === 'OK' && $retried['body']['replayed'] === true, 'A retried update is not mistaken for a conflict with itself');

$world->act_as(4);
$after_change = $world->call('pages.update', $update, array('request_id' => 'nf_req_update_title'));
nexoflow_assert(nexoflow_code($after_change) === 'OK' && $after_change['body']['replayed'] === true, 'A retry is answered from the record whatever has changed on the site since');
$world->act_as(1);

$world->call('site.read');
$world->call('pages.list');
nexoflow_assert(count($world->rows()) === 2, 'Reading is not recorded');

$bad = $world->call('pages.create', array(), array('request_id' => 'nf_req_fix_and_retry'));
$fixed = $world->call('pages.create', array('title' => 'Fixed'), array('request_id' => 'nf_req_fix_and_retry'));
nexoflow_assert(nexoflow_code($bad) === 'INVALID_PARAMETERS' && nexoflow_code($fixed) === 'OK', 'A refused request does not use up its request_id');

$GLOBALS['nexoflow_insert_fails'] = true;
$not_saved = $world->call('pages.create', array('title' => 'Database hiccup'), array('request_id' => 'nf_req_database_hiccup'));
$GLOBALS['nexoflow_insert_fails'] = false;
nexoflow_assert(nexoflow_code($not_saved) === 'EXECUTION_FAILED' && $not_saved['status'] === 500, 'A change WordPress could not save is reported as failed');
nexoflow_assert(nexoflow_details($not_saved) === array(), 'A change WordPress could not save reports nothing internal');
$again = $world->call('pages.create', array('title' => 'Database hiccup'), array('request_id' => 'nf_req_database_hiccup'));
nexoflow_assert(nexoflow_code($again) === 'OK' && $again['body']['replayed'] === false, 'A request that changed nothing can be sent again');

$before = $world->page_count();
$GLOBALS['nexoflow_insert_throws'] = true;
$broken = $world->call('pages.create', array('title' => 'Breaks part-way'), array('request_id' => 'nf_req_breaks_part_way'));
$GLOBALS['nexoflow_insert_throws'] = false;
nexoflow_assert(nexoflow_code($broken) === 'EXECUTION_FAILED' && nexoflow_details($broken)['changed'] === 'unknown', 'A change that broke part-way says the outcome is unknown');
nexoflow_assert(strpos(json_encode($broken), 'secret') === false && strpos(json_encode($broken), '.php') === false, 'A broken action never exposes internals');
$after_break = $world->call('pages.create', array('title' => 'Breaks part-way'), array('request_id' => 'nf_req_breaks_part_way'));
nexoflow_assert(nexoflow_code($after_break) === 'REQUEST_INCOMPLETE', 'A request that broke part-way is never run a second time');
nexoflow_assert($world->page_count() === $before, 'Retrying a broken request creates nothing');

$record = array('request_id' => 'nf_req_still_running', 'authorization_id' => '', 'action' => 'pages.create', 'user_id' => 1);
$body = array('request_id' => 'nf_req_still_running', 'action' => 'pages.create', 'parameters' => array('title' => 'Slow'), 'mode' => 'draft');
$record['fingerprint'] = NexoFlow_Agent_Dispatcher::fingerprint($body);
nexoflow_assert($world->store->claim($record) === true, 'A request_id can be claimed');
nexoflow_assert($world->store->claim($record) === false, 'A request_id can only be claimed once');
$running = $world->call('pages.create', array('title' => 'Slow'), array('request_id' => 'nf_req_still_running'));
nexoflow_assert(nexoflow_code($running) === 'REQUEST_IN_PROGRESS' && $running['status'] === 409, 'A request that is still running is not started again');

$GLOBALS['wpdb']->query("UPDATE wp_nexoflow_agent_requests SET created_at = '2026-01-01 00:00:00' WHERE claim = 'nf_req_still_running'");
$stale = $world->call('pages.create', array('title' => 'Slow'), array('request_id' => 'nf_req_still_running'));
nexoflow_assert(nexoflow_code($stale) === 'REQUEST_INCOMPLETE', 'A request that never finished is not started again');
nexoflow_assert($world->page_count() === $before, 'Unfinished requests create nothing');

$statuses = array_count_values(array_column($world->rows(), 'status'));
nexoflow_assert($statuses['completed'] === 4 && $statuses['rejected'] === 1 && $statuses['failed'] === 1 && $statuses['abandoned'] === 1, 'Every request that asked for a change is in the record');
$recent = $world->store->recent(3);
nexoflow_assert(count($recent) === 3 && $recent[0]['request_id'] === 'nf_req_still_running', 'The record lists the most recent requests first');

update_option(NexoFlow_Agent_Request_Store::PRUNED_OPTION, 0);
$world->store->prune();
nexoflow_assert(count($world->rows()) === 7, 'Recent requests are kept and one old request is removed');
nexoflow_assert($world->call('pages.create', $params, $request)['body']['replayed'] === true, 'Cleaning up keeps the results that can still be replayed');

$no_store = new NexoFlow_Test_World(array(), array(), false);
update_option(NexoFlow_Agent_Request_Store::VERSION_OPTION, NexoFlow_Agent_Request_Store::DB_VERSION);
$unsafe = $no_store->call('pages.create', array('title' => 'No tracking'));
nexoflow_assert(nexoflow_code($unsafe) === 'STORAGE_UNAVAILABLE' && $unsafe['status'] === 503, 'A change is refused when the request cannot be recorded');
nexoflow_assert($no_store->page_count() === 0, 'Nothing is created when the request cannot be recorded');

nexoflow_section('Concurrency');

$world = new NexoFlow_Test_World();
$page_id = $world->page(array('post_title' => 'Garage Door Repair', 'post_content' => '<p>First version</p>'));
$read = nexoflow_result($world->call('pages.get', array('page_id' => $page_id)));
nexoflow_assert($read['wp_revision_id'] === null, 'A page without stored revisions reports none');

$matching = $world->call('pages.update', array('page_id' => $page_id, 'expected_revision' => $read['revision'], 'content' => '<p>Second version</p>'));
nexoflow_assert(nexoflow_code($matching) === 'OK', 'An update with the matching revision succeeds');
nexoflow_assert(nexoflow_result($matching)['previous_revision'] === $read['revision'], 'An update reports the revision it replaced');
nexoflow_assert($GLOBALS['nexoflow_posts'][$page_id]['post_content'] === '<p>Second version</p>', 'An update saves the new content');
nexoflow_assert($GLOBALS['nexoflow_posts'][$page_id]['post_status'] === 'draft', 'An update keeps the page a draft');

$stale = $world->call('pages.update', array('page_id' => $page_id, 'expected_revision' => $read['revision'], 'content' => '<p>Third version</p>'));
nexoflow_assert(nexoflow_code($stale) === 'CONTENT_CONFLICT' && $stale['status'] === 409, 'An update with a stale revision is a conflict');
nexoflow_assert(nexoflow_details($stale)['current_revision'] === nexoflow_result($matching)['revision'], 'A conflict reports the current revision');
nexoflow_assert($GLOBALS['nexoflow_posts'][$page_id]['post_content'] === '<p>Second version</p>', 'A conflict leaves the newer version in place');

$current = nexoflow_result($world->call('pages.get', array('page_id' => $page_id)));
nexoflow_tick(30);
wp_update_post(array('ID' => $page_id, 'post_content' => '<p>Edited by a person</p>'));
$overwritten = $world->call('pages.update', array('page_id' => $page_id, 'expected_revision' => $current['revision'], 'content' => '<p>Generated earlier</p>'));
nexoflow_assert(nexoflow_code($overwritten) === 'CONTENT_CONFLICT', 'A page edited by a person in the meantime is not overwritten');
nexoflow_assert($GLOBALS['nexoflow_posts'][$page_id]['post_content'] === '<p>Edited by a person</p>', 'The edit made by a person is kept');

$current = nexoflow_result($world->call('pages.get', array('page_id' => $page_id)));
nexoflow_tick(30);
wp_update_post(array('ID' => $page_id));
$resaved = $world->call('pages.update', array('page_id' => $page_id, 'expected_revision' => $current['revision'], 'title' => 'New title'));
nexoflow_assert(nexoflow_code($resaved) === 'CONTENT_CONFLICT', 'Saving a page again changes its revision');

$current = nexoflow_result($world->call('pages.get', array('page_id' => $page_id)));
update_post_meta($page_id, '_edit_lock', time() . ':1');
$locked = $world->call('pages.update', array('page_id' => $page_id, 'expected_revision' => $current['revision'], 'title' => 'New title'));
nexoflow_assert(nexoflow_code($locked) === 'CONTENT_LOCKED' && $locked['status'] === 409, 'A page open in the editor is not changed, even by the same account');
update_post_meta($page_id, '_edit_lock', (time() - 3600) . ':2');
$unlocked = $world->call('pages.update', array('page_id' => $page_id, 'expected_revision' => $current['revision'], 'title' => 'New title'));
nexoflow_assert(nexoflow_code($unlocked) === 'OK', 'An editor session that ended no longer blocks changes');

$GLOBALS['nexoflow_revisions'][$page_id] = 42;
nexoflow_assert(nexoflow_result($world->call('pages.get', array('page_id' => $page_id)))['wp_revision_id'] === 42, 'Reports the latest WordPress revision when there is one');

nexoflow_section('Published pages');

$world = new NexoFlow_Test_World();
update_option('show_on_front', 'page');
update_option('page_on_front', 77);
nexoflow_assert(nexoflow_result($world->call('site.read'))['site']['front_page'] === array('show' => 'page', 'page_id' => 77, 'template' => null), 'site.read says which page is the homepage');

$home = $world->page(array('post_status' => 'publish', 'post_title' => 'Home', 'post_name' => 'home', 'post_content' => '<p>Welcome</p>'));
$read = nexoflow_result($world->call('pages.get', array('page_id' => $home)));
$change = array('page_id' => $home, 'expected_revision' => $read['revision'], 'content' => '<p>Welcome to Summit Garage Doors</p><script>track()</script>');

$default_mode = $world->call('pages.update', $change);
nexoflow_assert(nexoflow_code($default_mode) === 'DRAFT_REQUIRED', 'A published page is not changed unless the request says live');
nexoflow_assert($GLOBALS['nexoflow_posts'][$home]['post_content'] === '<p>Welcome</p>', 'Without live mode the published page is untouched');

$live = $world->call('pages.update', $change, array('mode' => 'live', 'request_id' => 'nf_req_live_change'));
$after = nexoflow_result($live);
nexoflow_assert(nexoflow_code($live) === 'OK' && $live['body']['mode'] === 'live', 'A published page is changed in live mode');
nexoflow_assert($GLOBALS['nexoflow_posts'][$home]['post_content'] === '<p>Welcome to Summit Garage Doors</p>track()', 'A live change is saved, with script tags removed');
nexoflow_assert($GLOBALS['nexoflow_posts'][$home]['post_status'] === 'publish' && $GLOBALS['nexoflow_posts'][$home]['post_name'] === 'home', 'A live change keeps the status and the address');
nexoflow_assert($after['restore_revision_id'] > 0 && wp_get_post_revision($after['restore_revision_id'])->post_content === '<p>Welcome</p>', 'A live change keeps the previous version');
nexoflow_assert($after['previous_revision'] === $read['revision'] && $after['revision'] !== $read['revision'], 'A live change returns the old and the new revision');
nexoflow_assert($world->call('pages.update', $change, array('mode' => 'live', 'request_id' => 'nf_req_live_change'))['body']['replayed'] === true, 'A repeated live change is replayed');

$stale_live = $world->call('pages.update', array('page_id' => $home, 'expected_revision' => $read['revision'], 'title' => 'Stale'), array('mode' => 'live'));
nexoflow_assert(nexoflow_code($stale_live) === 'CONTENT_CONFLICT', 'A live change with a stale revision is a conflict');

$slug_live = $world->call('pages.update', array('page_id' => $home, 'expected_revision' => $after['revision'], 'slug' => 'start'), array('mode' => 'live'));
nexoflow_assert(nexoflow_details($slug_live)['fields']['slug'] === 'published' && $GLOBALS['nexoflow_posts'][$home]['post_name'] === 'home', 'The address of a published page cannot be changed');

update_post_meta($home, '_edit_lock', time() . ':2');
nexoflow_assert(nexoflow_code($world->call('pages.update', array('page_id' => $home, 'expected_revision' => $after['revision'], 'title' => 'Open'), array('mode' => 'live'))) === 'CONTENT_LOCKED', 'A published page open in the editor is not changed');
update_post_meta($home, '_edit_lock', '');

nexoflow_tick();
$second = nexoflow_result($world->call('pages.update', array('page_id' => $home, 'expected_revision' => $after['revision'], 'title' => 'Home page'), array('mode' => 'live')));
nexoflow_assert($second['restore_revision_id'] > $after['restore_revision_id'], 'When the latest revision already matches the page, it is the restore point');
nexoflow_assert(wp_get_post_revision($second['restore_revision_id'])->post_title === 'Home', 'The restore point is the page as it was just before the change');

$draft_restore = $world->call('pages.restore', array('page_id' => $home, 'expected_revision' => $second['revision'], 'revision_id' => $after['restore_revision_id']));
nexoflow_assert(nexoflow_code($draft_restore) === 'DRAFT_REQUIRED', 'Restoring a published page also needs live mode');
nexoflow_tick();
$restored = $world->call('pages.restore', array('page_id' => $home, 'expected_revision' => $second['revision'], 'revision_id' => $after['restore_revision_id']), array('mode' => 'live'));
nexoflow_assert(nexoflow_code($restored) === 'OK' && $GLOBALS['nexoflow_posts'][$home]['post_content'] === '<p>Welcome</p>', 'A live change can be undone');
nexoflow_assert(nexoflow_result($restored)['restored_revision_id'] === $after['restore_revision_id'] && nexoflow_result($restored)['restore_revision_id'] > 0, 'Undoing keeps the version it replaced too');
nexoflow_assert($GLOBALS['nexoflow_posts'][$home]['post_status'] === 'publish', 'Undoing keeps the page published');

$other = $world->page(array('post_status' => 'publish', 'post_title' => 'About'));
$other_read = nexoflow_result($world->call('pages.get', array('page_id' => $other)));
$foreign = $world->call('pages.restore', array('page_id' => $other, 'expected_revision' => $other_read['revision'], 'revision_id' => $after['restore_revision_id']), array('mode' => 'live'));
nexoflow_assert(nexoflow_code($foreign) === 'NOT_FOUND' && $GLOBALS['nexoflow_posts'][$other]['post_title'] === 'About', 'A version of one page cannot be restored onto another');
nexoflow_assert(nexoflow_code($world->call('pages.restore', array('page_id' => $other, 'expected_revision' => $other_read['revision'], 'revision_id' => 999999), array('mode' => 'live'))) === 'NOT_FOUND', 'A version that does not exist cannot be restored');

$GLOBALS['nexoflow_revisions_to_keep'] = 1;
$unsafe = $world->call('pages.update', array('page_id' => $other, 'expected_revision' => $other_read['revision'], 'title' => 'About us'), array('mode' => 'live'));
nexoflow_assert(nexoflow_code($unsafe) === 'RESTORE_POINT_UNAVAILABLE' && $GLOBALS['nexoflow_posts'][$other]['post_title'] === 'About', 'A published page is not changed when its previous version cannot be kept');
$GLOBALS['nexoflow_revisions_to_keep'] = 0;
nexoflow_assert(nexoflow_code($world->call('pages.update', array('page_id' => $other, 'expected_revision' => $other_read['revision'], 'title' => 'About us'), array('mode' => 'live'))) === 'RESTORE_POINT_UNAVAILABLE', 'A site with revisions switched off does not get live changes');
$a_draft = $world->page(array('post_title' => 'Draft while revisions are off'));
$a_draft_read = nexoflow_result($world->call('pages.get', array('page_id' => $a_draft)));
nexoflow_assert(nexoflow_code($world->call('pages.update', array('page_id' => $a_draft, 'expected_revision' => $a_draft_read['revision'], 'title' => 'Still editable'))) === 'OK', 'Drafts can still be changed on a site with revisions switched off');
$GLOBALS['nexoflow_revisions_to_keep'] = -1;

$GLOBALS['nexoflow_no_revisions'] = true;
$world->capabilities->reset();
nexoflow_assert($world->documents->live_edits()['reason'] === 'REVISIONS_DISABLED' && $world->capabilities->get('pages.restore')['reason'] === 'REVISIONS_DISABLED', 'Discovery says when published pages cannot be changed safely');
nexoflow_assert(nexoflow_code($world->call('pages.update', array('page_id' => $other, 'expected_revision' => $other_read['revision'], 'title' => 'About us'), array('mode' => 'live'))) === 'RESTORE_POINT_UNAVAILABLE', 'Pages without revisions are not changed live');
$GLOBALS['nexoflow_no_revisions'] = false;
$world->capabilities->reset();

$GLOBALS['nexoflow_filters']['nexoflow_agent_allow_live_edits'] = function () {
    return false;
};
$switched_off = $world->call('pages.update', array('page_id' => $other, 'expected_revision' => $other_read['revision'], 'title' => 'About us'), array('mode' => 'live'));
nexoflow_assert(nexoflow_code($switched_off) === 'CAPABILITY_UNAVAILABLE' && nexoflow_details($switched_off)['reason'] === 'LIVE_EDITS_DISABLED', 'A site can switch off changes to published pages');
unset($GLOBALS['nexoflow_filters']['nexoflow_agent_allow_live_edits']);

$world->act_as(3);
$mine = $world->page(array('post_author' => 3, 'post_status' => 'publish', 'post_title' => 'Published by the drafter'));
nexoflow_assert(nexoflow_code($world->call('pages.update', array('page_id' => $mine, 'expected_revision' => str_repeat('a', 16), 'title' => 'x'), array('mode' => 'live'))) === 'INSUFFICIENT_PERMISSION', 'Live mode does not get around WordPress permissions');
$world->act_as(1);

$ready = new NexoFlow_Test_World(nexoflow_elementor_ready());
$elementor_live = $ready->page(array('post_status' => 'publish', 'post_title' => 'Elementor home', 'post_content' => '<div>rendered</div>'));
$ready->elementor->built[] = $elementor_live;
$elementor_read = nexoflow_result($ready->call('pages.get', array('page_id' => $elementor_live)));
nexoflow_assert(nexoflow_code($ready->call('pages.update', array('page_id' => $elementor_live, 'expected_revision' => $elementor_read['revision'], 'content' => '<p>Flat</p>'), array('mode' => 'live'))) === 'UNSUPPORTED_BUILDER_OPERATION', 'Live mode does not let content overwrite an Elementor page');
nexoflow_assert(nexoflow_code($ready->call('blocks.insert', array('page_id' => $elementor_live, 'expected_revision' => $elementor_read['revision'], 'content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->'), array('mode' => 'live'))) === 'UNSUPPORTED_BUILDER_OPERATION', 'Blocks are not added to an Elementor page');

nexoflow_section('Adding blocks');

$world = new NexoFlow_Test_World();
$hero = "<!-- wp:cover {\"dimRatio\":50} -->\n<div class=\"wp-block-cover\"><!-- wp:heading {\"level\":1} -->\n<h1 class=\"wp-block-heading\">Garage doors -- done right</h1>\n<!-- /wp:heading --></div>\n<!-- /wp:cover -->";
$about = "<!-- wp:paragraph -->\n<p>Family owned since 1998.</p>\n<!-- /wp:paragraph -->";
$footer = "<!-- wp:html -->\n<iframe src=\"https://maps.example.com/embed\"></iframe><script>initMap()</script>\n<!-- /wp:html -->";
$original = $hero . "\n\n" . $about . "\n\n" . $footer;
nexoflow_assert(serialize_blocks(parse_blocks($original)) === $original, 'Reading and writing blocks back gives the same markup');

$home = $world->page(array('post_status' => 'publish', 'post_title' => 'Home', 'post_content' => $original));
$tree = nexoflow_result($world->call('blocks.read', array('page_id' => $home)));
nexoflow_assert(array_column($tree['blocks'], 'name') === array('core/cover', 'core/paragraph', 'core/html') && $tree['status'] === 'publish', 'blocks.read lists the sections of the homepage');

$section = "<!-- wp:group -->\n<div class=\"wp-block-group\"><!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Why homeowners choose us</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Same-week installation.<script>steal()</script></p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group -->";
$clean_section = str_replace(array('<script>', '</script>'), '', $section);
$insert = array('page_id' => $home, 'expected_revision' => $tree['revision'], 'content' => $section, 'position' => 'after', 'path' => '0');

nexoflow_assert(nexoflow_code($world->call('blocks.insert', $insert)) === 'DRAFT_REQUIRED', 'Blocks are not added to a published page unless the request says live');
nexoflow_assert($GLOBALS['nexoflow_posts'][$home]['post_content'] === $original, 'Without live mode the homepage is untouched');

$inserted = $world->call('blocks.insert', $insert, array('mode' => 'live', 'request_id' => 'nf_req_new_section'));
$page = nexoflow_result($inserted);
$expected = $hero . "\n\n" . $clean_section . "\n\n" . $about . "\n\n" . $footer;
nexoflow_assert(nexoflow_code($inserted) === 'OK' && $page['inserted'] === array('path' => '1', 'count' => 1), 'A section is added after the block it was asked to follow');
nexoflow_assert($GLOBALS['nexoflow_posts'][$home]['post_content'] === $expected, 'The new section is in place and every other block is exactly as it was');
nexoflow_assert(strpos($GLOBALS['nexoflow_posts'][$home]['post_content'], '<script>initMap()</script>') !== false && substr_count($GLOBALS['nexoflow_posts'][$home]['post_content'], '<script>') === 1, 'Only the new blocks are sanitized, the page keeps what it had');
nexoflow_assert(wp_get_post_revision($page['restore_revision_id'])->post_content === $original && $GLOBALS['nexoflow_posts'][$home]['post_status'] === 'publish', 'The homepage as it was is kept, and the page stays published');
nexoflow_assert($world->call('blocks.insert', $insert, array('mode' => 'live', 'request_id' => 'nf_req_new_section'))['body']['replayed'] === true && $GLOBALS['nexoflow_posts'][$home]['post_content'] === $expected, 'A repeated request does not add the section twice');
nexoflow_assert(array_column(nexoflow_result($world->call('blocks.read', array('page_id' => $home)))['blocks'], 'name') === array('core/cover', 'core/group', 'core/paragraph', 'core/html'), 'The new section shows up where it was put');
nexoflow_assert(nexoflow_code($world->call('blocks.insert', $insert, array('mode' => 'live'))) === 'CONTENT_CONFLICT', 'Adding to a page that changed since it was read is a conflict');

nexoflow_tick();
$undo = $world->call('pages.restore', array('page_id' => $home, 'expected_revision' => $page['revision'], 'revision_id' => $page['restore_revision_id']), array('mode' => 'live'));
nexoflow_assert(nexoflow_code($undo) === 'OK' && $GLOBALS['nexoflow_posts'][$home]['post_content'] === $original, 'Adding a section can be undone');

$draft = $world->page(array('post_content' => $about));
$draft_revision = nexoflow_result($world->call('pages.get', array('page_id' => $draft)))['revision'];
$block = "<!-- wp:paragraph -->\n<p>New</p>\n<!-- /wp:paragraph -->";
$positions = array(
    'start' => array(array('position' => 'start'), $block . "\n\n" . $about, '0'),
    'end' => array(array(), $about . "\n\n" . $block, '1'),
    'before' => array(array('position' => 'before', 'path' => '0'), $block . "\n\n" . $about, '0'),
    'after' => array(array('position' => 'after', 'path' => '0'), $about . "\n\n" . $block, '1'),
);

foreach ($positions as $label => $case) {
    $GLOBALS['nexoflow_posts'][$draft]['post_content'] = $about;
    $draft_revision = nexoflow_result($world->call('pages.get', array('page_id' => $draft)))['revision'];
    $placed = $world->call('blocks.insert', $case[0] + array('page_id' => $draft, 'expected_revision' => $draft_revision, 'content' => $block));
    nexoflow_assert(nexoflow_code($placed) === 'OK' && $GLOBALS['nexoflow_posts'][$draft]['post_content'] === $case[1] && nexoflow_result($placed)['inserted']['path'] === $case[2], 'Blocks can be added at position ' . $label . ' of a draft');
    nexoflow_tick();
}

$draft_revision = nexoflow_result($world->call('pages.get', array('page_id' => $draft)))['revision'];
$two = nexoflow_result($world->call('blocks.insert', array('page_id' => $draft, 'expected_revision' => $draft_revision, 'content' => $block . "\n" . $block)));
nexoflow_assert($two['inserted']['count'] === 2 && !isset($two['restore_revision_id']), 'Several blocks can be added at once, and a draft needs no restore point');

$empty = $world->page(array('post_content' => ''));
$empty_revision = nexoflow_result($world->call('pages.get', array('page_id' => $empty)))['revision'];
$world->call('blocks.insert', array('page_id' => $empty, 'expected_revision' => $empty_revision, 'content' => $block));
nexoflow_assert($GLOBALS['nexoflow_posts'][$empty]['post_content'] === $block, 'Blocks can be added to an empty page');

$bad_revision = nexoflow_result($world->call('pages.get', array('page_id' => $draft)))['revision'];
$base = array('page_id' => $draft, 'expected_revision' => $bad_revision);
nexoflow_assert(nexoflow_details($world->call('blocks.insert', $base + array('content' => '<p>Just HTML</p>')))['fields']['content'] === 'not_blocks', 'HTML that is not a block is refused');
nexoflow_assert(nexoflow_details($world->call('blocks.insert', $base + array('content' => "  \n ")))['fields']['content'] === 'no_blocks', 'Content with no blocks is refused');
nexoflow_assert(nexoflow_details($world->call('blocks.insert', $base + array('content' => '<script>x()</script>')))['fields']['content'] === 'not_blocks', 'Content that is only a script is refused');
nexoflow_assert(nexoflow_details($world->call('blocks.insert', $base + array('content' => $block . '<script>x()</script>')))['fields']['content'] === 'not_blocks', 'A script next to a block is refused');
$typo = $world->call('blocks.insert', $base + array('content' => '<!-- wp:group --><div class="wp-block-group"><!-- wp:hero /--></div><!-- /wp:group -->'));
nexoflow_assert(nexoflow_details($typo)['fields']['content'] === 'unknown_block' && nexoflow_details($typo)['block'] === 'core/hero', 'A WordPress block that does not exist is refused, even nested');
nexoflow_assert(nexoflow_code($world->call('blocks.insert', $base + array('content' => '<!-- wp:acme/pricing-table /-->'))) === 'OK', 'A block from another plugin is accepted');
$bad_revision = nexoflow_result($world->call('pages.get', array('page_id' => $draft)))['revision'];
$base = array('page_id' => $draft, 'expected_revision' => $bad_revision, 'content' => $block);
nexoflow_assert(nexoflow_details($world->call('blocks.insert', $base + array('position' => 'after')))['fields']['path'] === 'required', 'Before and after need a block to refer to');
nexoflow_assert(nexoflow_details($world->call('blocks.insert', $base + array('position' => 'after', 'path' => '99')))['fields']['path'] === 'not_found', 'A block that is not on the page cannot be referred to');
nexoflow_assert(nexoflow_details($world->call('blocks.insert', $base + array('position' => 'after', 'path' => '0.1')))['fields']['path'] === 'format', 'Only top-level blocks can be referred to');
nexoflow_assert(nexoflow_details($world->call('blocks.insert', $base + array('position' => 'middle')))['fields']['position'] === 'not_allowed', 'A position that does not exist is refused');

$classic = new NexoFlow_Test_World(array(), array('pages_use_blocks' => false, 'classic_editor_plugin' => true));
nexoflow_assert(!in_array('blocks.insert', $classic->capabilities->usable(), true) && in_array('blocks.read', $classic->capabilities->usable(), true), 'A site on the classic editor can have its blocks read but not added to');
nexoflow_assert(nexoflow_details($classic->call('blocks.insert', array('page_id' => 1, 'expected_revision' => str_repeat('a', 16), 'content' => $block)))['reason'] === 'BLOCK_EDITOR_DISABLED', 'Says why blocks cannot be added');

nexoflow_section('Homepage template');

$world = new NexoFlow_Test_World();
$header = '<!-- wp:template-part {"slug":"header","theme":"summit"} /-->';
$hero = "<!-- wp:cover {\"dimRatio\":40} -->\n<div class=\"wp-block-cover\"><!-- wp:heading {\"level\":1} -->\n<h1 class=\"wp-block-heading\">Garage doors, done right</h1>\n<!-- /wp:heading --></div>\n<!-- /wp:cover -->";
$services = '<!-- wp:pattern {"slug":"summit/services"} /-->';
$footer = '<!-- wp:template-part {"slug":"footer","theme":"summit"} /-->';
$front = $header . "\n\n" . $hero . "\n\n" . $services . "\n\n" . $footer;
$world->block_theme(array('index' => '<!-- wp:query /-->', 'front-page' => $front));

$site = nexoflow_result($world->call('site.read'))['site'];
nexoflow_assert($site['front_page'] === array('show' => 'posts', 'page_id' => null, 'template' => 'summit//front-page'), 'site.read names the template that draws the homepage');
nexoflow_assert(in_array('templates.read', $world->capabilities->usable(), true) && in_array('templates.update', $world->capabilities->usable(), true), 'A block theme site offers template capabilities');

$GLOBALS['nexoflow_theme_templates'] = array('index' => '<!-- wp:query /-->');
nexoflow_assert(nexoflow_result($world->call('site.read'))['site']['front_page']['template'] === 'summit//index', 'Without a front page template the index template draws the homepage');
update_option('show_on_front', 'page');
update_option('page_on_front', 12);
nexoflow_assert(nexoflow_result($world->call('site.read'))['site']['front_page'] === array('show' => 'page', 'page_id' => 12, 'template' => null), 'A static homepage without a front page template is just the page');
$GLOBALS['nexoflow_theme_templates'] = array('index' => '<!-- wp:query /-->', 'front-page' => $front);
nexoflow_assert(nexoflow_result($world->call('site.read'))['site']['front_page']['template'] === 'summit//front-page', 'A front page template takes over from a static homepage');
update_option('show_on_front', 'posts');

$read = $world->call('templates.read', array('template' => 'summit//front-page'));
$template = nexoflow_result($read);
nexoflow_assert(nexoflow_code($read) === 'OK' && array_column($template['blocks'], 'name') === array('core/template-part', 'core/cover', 'core/pattern', 'core/template-part'), 'templates.read lists the sections of the homepage');
nexoflow_assert($template['customized'] === false && $template['post_id'] === null && strlen($template['revision']) === 16, 'A template that comes from the theme is reported as not customized');
nexoflow_assert(!isset($template['blocks'][1]['markup']), 'The block markup is left out unless it is asked for');
$GLOBALS['nexoflow_patterns'] = array('summit/services' => '<!-- wp:heading --><h2 class="wp-block-heading">Our services</h2><!-- /wp:heading -->');
$detailed = nexoflow_result($world->call('templates.read', array('template' => 'summit//front-page', 'include_markup' => true)));
nexoflow_assert($detailed['blocks'][1]['markup'] === $hero && !isset($detailed['blocks'][1]['inner_blocks'][0]['markup']), 'With include_markup each top-level section comes with its exact markup');
nexoflow_assert($detailed['blocks'][2]['markup'] === $services && strpos($detailed['blocks'][2]['pattern_markup'], 'Our services') !== false, 'A pattern section also comes with the markup of the pattern it points to');
nexoflow_assert(!isset($detailed['blocks'][0]['pattern_markup']), 'Only patterns get pattern markup');
$GLOBALS['nexoflow_patterns'] = array();
nexoflow_assert(nexoflow_code($world->call('templates.read', array('template' => 'summit//missing'))) === 'NOT_FOUND', 'A template that does not exist is refused');
nexoflow_assert(nexoflow_details($world->call('templates.read', array('template' => '../../wp-config')))['fields']['template'] === 'format', 'A template ID must have the form theme//slug');

$section = "<!-- wp:group -->\n<div class=\"wp-block-group\"><!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Why homeowners choose us</h2>\n<!-- /wp:heading --></div>\n<!-- /wp:group -->";
$insert = array('template' => 'summit//front-page', 'expected_revision' => $template['revision'], 'content' => $section, 'position' => 'after', 'path' => '1');

nexoflow_assert(nexoflow_code($world->call('templates.insert', $insert)) === 'MODE_NOT_SUPPORTED', 'A template is only changed when the request says live');
nexoflow_assert($world->template_posts() === array(), 'Without live mode nothing is stored');

$world->act_as(2);
$as_editor = $world->call('templates.insert', $insert, array('mode' => 'live'));
nexoflow_assert(nexoflow_code($as_editor) === 'INSUFFICIENT_PERMISSION' && nexoflow_details($as_editor)['requires'] === 'edit_theme_options', 'An account that cannot change the theme cannot change templates');
nexoflow_assert(nexoflow_code($world->call('templates.read', array('template' => 'summit//front-page'))) === 'INSUFFICIENT_PERMISSION', 'It cannot read them through the agent either');
$world->act_as(1);

$added = $world->call('templates.insert', $insert, array('mode' => 'live', 'request_id' => 'nf_req_home_section'));
$after = nexoflow_result($added);
$expected = $header . "\n\n" . $hero . "\n\n" . $section . "\n\n" . $services . "\n\n" . $footer;
$posts = $world->template_posts();
nexoflow_assert(nexoflow_code($added) === 'OK' && $after['inserted'] === array('path' => '2', 'count' => 1), 'A section is added to the homepage template');
nexoflow_assert(count($posts) === 1 && $GLOBALS['nexoflow_posts'][$posts[0]]['post_content'] === $expected, 'The section is in place and the header, hero, pattern and footer are exactly as they were');
nexoflow_assert($GLOBALS['nexoflow_theme_templates']['front-page'] === $front, 'The theme file itself is not changed');
nexoflow_assert($after['customized'] === true && $after['post_id'] === $posts[0] && $GLOBALS['nexoflow_posts'][$posts[0]]['terms']['wp_theme'] === array('summit'), 'The change is stored the way the Site Editor stores it');
nexoflow_assert(wp_get_post_revision($after['restore_revision_id'])->post_content === $front && $after['previous_revision'] === $template['revision'], 'The homepage as the theme drew it is kept');
nexoflow_assert($world->call('templates.insert', $insert, array('mode' => 'live', 'request_id' => 'nf_req_home_section'))['body']['replayed'] === true && count($world->template_posts()) === 1, 'A repeated request does not add the section twice');
nexoflow_assert(nexoflow_code($world->call('templates.insert', $insert, array('mode' => 'live'))) === 'CONTENT_CONFLICT', 'Adding to a template that changed since it was read is a conflict');
nexoflow_assert(array_column(nexoflow_result($world->call('templates.read', array('template' => 'summit//front-page')))['blocks'], 'name') === array('core/template-part', 'core/cover', 'core/group', 'core/pattern', 'core/template-part'), 'The new section shows up between the hero and the services');

nexoflow_tick();
$second = nexoflow_result($world->call('templates.insert', array('template' => 'summit//front-page', 'expected_revision' => $after['revision'], 'content' => $section), array('mode' => 'live')));
nexoflow_assert(count($world->template_posts()) === 1 && $second['inserted']['path'] === '5', 'A second change updates the same stored template');

nexoflow_assert(nexoflow_code($world->call('templates.restore', array('template' => 'summit//front-page', 'expected_revision' => $second['revision'], 'revision_id' => 999999), array('mode' => 'live'))) === 'NOT_FOUND', 'A version that does not exist cannot be restored');
$undone = $world->call('templates.restore', array('template' => 'summit//front-page', 'expected_revision' => $second['revision'], 'revision_id' => $after['restore_revision_id']), array('mode' => 'live'));
nexoflow_assert(nexoflow_code($undone) === 'OK' && $GLOBALS['nexoflow_posts'][$posts[0]]['post_content'] === $front, 'Changes to the homepage template can be undone');
nexoflow_assert(nexoflow_result($undone)['restored_revision_id'] === $after['restore_revision_id'] && nexoflow_result($undone)['restore_revision_id'] > 0, 'Undoing keeps the version it replaced too');

$index = nexoflow_result($world->call('templates.read', array('template' => 'summit//index')));
nexoflow_assert(nexoflow_code($world->call('templates.restore', array('template' => 'summit//index', 'expected_revision' => $index['revision'], 'revision_id' => $after['restore_revision_id']), array('mode' => 'live'))) === 'NOT_FOUND', 'A version of one template cannot be restored onto another');
nexoflow_assert(nexoflow_details($world->call('templates.insert', array('template' => 'summit//index', 'expected_revision' => $index['revision'], 'content' => '<p>Not a block</p>'), array('mode' => 'live')))['fields']['content'] === 'not_blocks', 'HTML that is not a block is refused for templates too');

$GLOBALS['nexoflow_revisions_to_keep'] = 0;
$unsafe = $world->call('templates.insert', array('template' => 'summit//index', 'expected_revision' => $index['revision'], 'content' => $section), array('mode' => 'live'));
nexoflow_assert(nexoflow_code($unsafe) === 'RESTORE_POINT_UNAVAILABLE' && count($world->template_posts()) === 1, 'A template is not changed, or even stored, when its previous version cannot be kept');
$GLOBALS['nexoflow_revisions_to_keep'] = -1;

$GLOBALS['nexoflow_filters']['nexoflow_agent_allow_live_edits'] = function () {
    return false;
};
$late_switch = $world->call('templates.insert', array('template' => 'summit//index', 'expected_revision' => $index['revision'], 'content' => $section), array('mode' => 'live'));
nexoflow_assert(nexoflow_code($late_switch) === 'CAPABILITY_UNAVAILABLE' && nexoflow_details($late_switch)['template'] === 'summit//index', 'The switch is checked again at the moment of the change');
$world->capabilities->reset();
nexoflow_assert(nexoflow_details($world->call('templates.insert', array('template' => 'summit//index', 'expected_revision' => $index['revision'], 'content' => $section), array('mode' => 'live')))['reason'] === 'LIVE_EDITS_DISABLED', 'Switching live changes off covers templates');
nexoflow_assert(in_array('templates.read', $world->capabilities->usable(), true), 'Templates can still be read when live changes are off');
unset($GLOBALS['nexoflow_filters']['nexoflow_agent_allow_live_edits']);
$world->capabilities->reset();

$index_changed = nexoflow_result($world->call('templates.insert', array('template' => 'summit//index', 'expected_revision' => $index['revision'], 'content' => $section), array('mode' => 'live')));
$crossed = $world->call('templates.restore', array('template' => 'summit//index', 'expected_revision' => $index_changed['revision'], 'revision_id' => $after['restore_revision_id']), array('mode' => 'live'));
nexoflow_assert(nexoflow_code($crossed) === 'NOT_FOUND' && strpos($GLOBALS['nexoflow_posts'][$index_changed['post_id']]['post_content'], 'wp:query') !== false, 'A version of the homepage template cannot be restored onto a different customized template');

nexoflow_section('Changing sections');

$world = new NexoFlow_Test_World();
$hero = "<!-- wp:cover {\"dimRatio\":50} -->\n<div class=\"wp-block-cover\"><!-- wp:heading {\"level\":1} -->\n<h1 class=\"wp-block-heading\">Garage doors -- done right</h1>\n<!-- /wp:heading --></div>\n<!-- /wp:cover -->";
$about = "<!-- wp:paragraph -->\n<p>Family owned since 1998.</p>\n<!-- /wp:paragraph -->";
$faq = "<!-- wp:group {\"style\":{\"color\":{\"background\":\"#0b3d91\"}}} -->\n<div class=\"wp-block-group has-background\" style=\"background-color:#0b3d91\"><!-- wp:details -->\n<details class=\"wp-block-details\"><summary>Do you repair springs?</summary></details>\n<!-- /wp:details --></div>\n<!-- /wp:group -->";
$map = "<!-- wp:html -->\n<iframe src=\"https://maps.example.com/embed\"></iframe><script>initMap()</script>\n<!-- /wp:html -->";
$original = $hero . "\n\n" . $about . "\n\n\n" . $faq . "\n\n" . $map;
$home = $world->page(array('post_status' => 'publish', 'post_title' => 'Home', 'post_content' => $original));
$tree = nexoflow_result($world->call('blocks.read', array('page_id' => $home)));
nexoflow_assert(in_array('blocks.update', $world->capabilities->usable(), true), 'A block site offers changing sections');

$white_faq = str_replace('<!-- wp:group {"style":{"color":{"background":"#0b3d91"}}} -->', '<!-- wp:group {"style":{"color":{"background":"#0b3d91","text":"#ffffff"}}} -->', $faq);
$white_faq = str_replace('<summary>Do you repair springs?</summary>', '<summary>Do you repair springs?<script>steal()</script></summary>', $white_faq);
$clean_faq = str_replace(array('<script>steal()</script>'), 'steal()', $white_faq);
$replace = array('page_id' => $home, 'expected_revision' => $tree['revision'], 'replacements' => array(array('path' => '2', 'content' => $white_faq)));

nexoflow_assert(nexoflow_code($world->call('blocks.replace', $replace)) === 'DRAFT_REQUIRED', 'A section of a published page is not changed unless the request says live');
nexoflow_assert($GLOBALS['nexoflow_posts'][$home]['post_content'] === $original, 'Without live mode the page is untouched');

$replaced = $world->call('blocks.replace', $replace, array('mode' => 'live', 'request_id' => 'nf_req_white_faq'));
$page = nexoflow_result($replaced);
$expected = $hero . "\n\n" . $about . "\n\n\n" . $clean_faq . "\n\n" . $map;
nexoflow_assert(nexoflow_code($replaced) === 'OK' && $page['replaced'] === array('sections' => 1, 'blocks' => 1, 'removed' => 0), 'One section is replaced');
nexoflow_assert($GLOBALS['nexoflow_posts'][$home]['post_content'] === $expected, 'The section is changed in place and every other block, and the space between them, is exactly as it was');
nexoflow_assert(substr_count($GLOBALS['nexoflow_posts'][$home]['post_content'], '<script>') === 1, 'Only the new section is sanitized, the page keeps what it had');
nexoflow_assert(wp_get_post_revision($page['restore_revision_id'])->post_content === $original, 'The page as it was is kept');
nexoflow_assert($world->call('blocks.replace', $replace, array('mode' => 'live', 'request_id' => 'nf_req_white_faq'))['body']['replayed'] === true && $GLOBALS['nexoflow_posts'][$home]['post_content'] === $expected, 'A repeated request does not change the page again');
nexoflow_assert(nexoflow_code($world->call('blocks.replace', $replace, array('mode' => 'live'))) === 'CONTENT_CONFLICT', 'Changing a page that changed since it was read is a conflict');

nexoflow_tick();
$undo = $world->call('pages.restore', array('page_id' => $home, 'expected_revision' => $page['revision'], 'revision_id' => $page['restore_revision_id']), array('mode' => 'live'));
nexoflow_assert(nexoflow_code($undo) === 'OK' && $GLOBALS['nexoflow_posts'][$home]['post_content'] === $original, 'Changing a section can be undone');

nexoflow_tick();
$block = "<!-- wp:paragraph -->\n<p>New</p>\n<!-- /wp:paragraph -->";
$heading = "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Questions</h2>\n<!-- /wp:heading -->";
$revision = nexoflow_result($world->call('pages.get', array('page_id' => $home)))['revision'];
$several = $world->call('blocks.replace', array('page_id' => $home, 'expected_revision' => $revision, 'replacements' => array(
    array('path' => '2', 'content' => $heading . "\n" . $faq),
    array('path' => '0', 'content' => $block),
)), array('mode' => 'live'));
nexoflow_assert(nexoflow_code($several) === 'OK' && nexoflow_result($several)['replaced'] === array('sections' => 2, 'blocks' => 3, 'removed' => 0), 'Several sections can be changed in one request, in any order');
nexoflow_assert($GLOBALS['nexoflow_posts'][$home]['post_content'] === $block . "\n\n" . $about . "\n\n\n" . $heading . "\n\n" . $faq . "\n\n" . $map, 'One section can become several blocks, and the rest stays as it was');

nexoflow_tick();
$revision = nexoflow_result($world->call('pages.get', array('page_id' => $home)))['revision'];
$before = $GLOBALS['nexoflow_posts'][$home]['post_content'];
$base = array('page_id' => $home, 'expected_revision' => $revision);
$live = array('mode' => 'live');
nexoflow_assert(nexoflow_details($world->call('blocks.replace', $base + array('replacements' => array(array('path' => '99', 'content' => $block))), $live))['fields']['replacements.0.path'] === 'not_found', 'A section that is not on the page cannot be replaced');
nexoflow_assert(nexoflow_details($world->call('blocks.replace', $base + array('replacements' => array(array('path' => '1', 'content' => $block), array('path' => '1', 'content' => $block))), $live))['fields']['replacements.1.path'] === 'repeated', 'The same section cannot be replaced twice in one request');
nexoflow_assert(nexoflow_details($world->call('blocks.replace', $base + array('replacements' => array(array('path' => '1', 'content' => '<p>Just HTML</p>'))), $live))['fields']['content'] === 'not_blocks', 'A section can only be replaced with blocks');
nexoflow_assert(nexoflow_details($world->call('blocks.replace', $base + array('replacements' => array(array('path' => '1', 'content' => '<!-- wp:hero /-->'))), $live))['fields']['content'] === 'unknown_block', 'A WordPress block that does not exist is refused');
nexoflow_assert(nexoflow_details($world->call('blocks.replace', $base + array('replacements' => array()), $live))['fields']['replacements'] === 'too_few', 'A change needs at least one section');
nexoflow_assert(nexoflow_details($world->call('blocks.replace', $base, $live))['fields']['replacements'] === 'required', 'The sections to change are required');
nexoflow_assert(nexoflow_details($world->call('blocks.replace', $base + array('replacements' => 'all'), $live))['fields']['replacements'] === 'type', 'The sections to change must be a list');
nexoflow_assert(nexoflow_details($world->call('blocks.replace', $base + array('replacements' => array('path' => '1', 'content' => $block)), $live))['fields']['replacements'] === 'type', 'One change on its own is not a list');
nexoflow_assert(nexoflow_details($world->call('blocks.replace', $base + array('replacements' => array('first')), $live))['fields']['replacements.0'] === 'type', 'Each change must be an object');
nexoflow_assert(nexoflow_details($world->call('blocks.replace', $base + array('replacements' => array(array('path' => '1'))), $live))['fields']['replacements.0.content'] === 'required', 'Each change needs its new blocks');
nexoflow_assert(nexoflow_details($world->call('blocks.replace', $base + array('replacements' => array(array('path' => '0.1', 'content' => $block))), $live))['fields']['replacements.0.path'] === 'format', 'Only top-level sections can be replaced');
nexoflow_assert(nexoflow_details($world->call('blocks.replace', $base + array('replacements' => array(array('path' => '1', 'content' => $block, 'status' => 'publish'))), $live))['fields']['replacements.0.status'] === 'unknown', 'A change cannot carry fields that are not part of the contract');
nexoflow_assert(nexoflow_details($world->call('blocks.replace', $base + array('replacements' => array_fill(0, 21, array('path' => '1', 'content' => $block))), $live))['fields']['replacements'] === 'too_many', 'A request cannot carry an unlimited number of changes');
nexoflow_assert($GLOBALS['nexoflow_posts'][$home]['post_content'] === $before, 'A refused change leaves the page as it was');

$world->act_as(4);
nexoflow_assert(nexoflow_code($world->call('blocks.replace', $base + array('replacements' => array(array('path' => '1', 'content' => $block))), $live)) === 'INSUFFICIENT_PERMISSION', 'An account that cannot edit pages cannot change a section');
$world->act_as(1);

$draft = $world->page(array('post_content' => $about . "\n\n" . $faq));
$draft_revision = nexoflow_result($world->call('pages.get', array('page_id' => $draft)))['revision'];
$drafted = $world->call('blocks.replace', array('page_id' => $draft, 'expected_revision' => $draft_revision, 'replacements' => array(array('path' => '0', 'content' => $block))));
nexoflow_assert(nexoflow_code($drafted) === 'OK' && $GLOBALS['nexoflow_posts'][$draft]['post_content'] === $block . "\n\n" . $faq && !isset(nexoflow_result($drafted)['restore_revision_id']), 'A section of a draft is changed without live mode');

// Rebuilding a page: every section is swapped for a new one, and the ones left over are taken away.
$one = "<!-- wp:paragraph -->\n<p>One</p>\n<!-- /wp:paragraph -->";
$two = "<!-- wp:paragraph -->\n<p>Two</p>\n<!-- /wp:paragraph -->";
$three = "<!-- wp:paragraph -->\n<p>Three</p>\n<!-- /wp:paragraph -->";
$long_page = $hero . "\n\n" . $about . "\n\n\n" . $faq . "\n\n" . $map;
$rebuilt = $world->page(array('post_status' => 'publish', 'post_title' => 'Rebuilt', 'post_content' => $long_page));
$rebuilt_revision = nexoflow_result($world->call('pages.get', array('page_id' => $rebuilt)))['revision'];
$rebuild = $world->call('blocks.replace', array('page_id' => $rebuilt, 'expected_revision' => $rebuilt_revision, 'replacements' => array(
    array('path' => '0', 'content' => $one),
    array('path' => '1', 'content' => $two),
    array('path' => '2', 'remove' => true),
    array('path' => '3', 'remove' => true),
)), $live);
$rebuild_result = nexoflow_result($rebuild);
nexoflow_assert(nexoflow_code($rebuild) === 'OK' && $rebuild_result['replaced'] === array('sections' => 2, 'blocks' => 2, 'removed' => 2), 'A page can be rebuilt with fewer sections than it had');
nexoflow_assert($GLOBALS['nexoflow_posts'][$rebuilt]['post_content'] === $one . "\n\n" . $two, 'The sections taken away leave nothing behind, not even a blank line');
nexoflow_assert(wp_get_post_revision($rebuild_result['restore_revision_id'])->post_content === $long_page, 'The page as it was before the rebuild is kept');

nexoflow_tick();
$undo_rebuild = $world->call('pages.restore', array('page_id' => $rebuilt, 'expected_revision' => $rebuild_result['revision'], 'revision_id' => $rebuild_result['restore_revision_id']), $live);
nexoflow_assert(nexoflow_code($undo_rebuild) === 'OK' && $GLOBALS['nexoflow_posts'][$rebuilt]['post_content'] === $long_page, 'A rebuild can be undone');

nexoflow_tick();
$rebuilt_revision = nexoflow_result($world->call('pages.get', array('page_id' => $rebuilt)))['revision'];
$middle = $world->call('blocks.replace', array('page_id' => $rebuilt, 'expected_revision' => $rebuilt_revision, 'replacements' => array(array('path' => '1', 'remove' => true))), $live);
nexoflow_assert(nexoflow_code($middle) === 'OK' && nexoflow_result($middle)['replaced'] === array('sections' => 0, 'blocks' => 0, 'removed' => 1), 'One section can be taken away on its own');
nexoflow_assert($GLOBALS['nexoflow_posts'][$rebuilt]['post_content'] === $hero . "\n\n" . $faq . "\n\n" . $map, 'Taking a section away leaves every other section, and the space between them, as it was');

nexoflow_tick();
$rebuilt_revision = nexoflow_result($world->call('pages.get', array('page_id' => $rebuilt)))['revision'];
$more = $world->call('blocks.replace', array('page_id' => $rebuilt, 'expected_revision' => $rebuilt_revision, 'replacements' => array(
    array('path' => '0', 'content' => $one),
    array('path' => '1', 'content' => $two),
    array('path' => '2', 'content' => $three . "\n\n" . $block),
)), $live);
nexoflow_assert(nexoflow_code($more) === 'OK' && $GLOBALS['nexoflow_posts'][$rebuilt]['post_content'] === $one . "\n\n" . $two . "\n\n" . $three . "\n\n" . $block, 'A page can be rebuilt with more sections than it had');

nexoflow_tick();
$rebuilt_revision = nexoflow_result($world->call('pages.get', array('page_id' => $rebuilt)))['revision'];
$kept = $GLOBALS['nexoflow_posts'][$rebuilt]['post_content'];
$rebase = array('page_id' => $rebuilt, 'expected_revision' => $rebuilt_revision);
$everything = array(array('path' => '0', 'remove' => true), array('path' => '1', 'remove' => true), array('path' => '2', 'remove' => true), array('path' => '3', 'remove' => true));
nexoflow_assert(nexoflow_details($world->call('blocks.replace', $rebase + array('replacements' => $everything), $live))['fields']['replacements'] === 'removes_everything', 'A page is never left with nothing on it');
nexoflow_assert(nexoflow_details($world->call('blocks.replace', $rebase + array('replacements' => array(array('path' => '0', 'remove' => true, 'content' => $one))), $live))['fields']['replacements.0.content'] === 'not_allowed', 'A section is replaced or taken away, not both');
nexoflow_assert(nexoflow_details($world->call('blocks.replace', $rebase + array('replacements' => array(array('path' => '0', 'remove' => false))), $live))['fields']['replacements.0.content'] === 'required', 'A section that stays needs its new blocks');
nexoflow_assert(nexoflow_details($world->call('blocks.replace', $rebase + array('replacements' => array(array('path' => '0', 'remove' => 'yes'))), $live))['fields']['replacements.0.remove'] === 'type', 'Taking a section away is a yes or no');
nexoflow_assert(nexoflow_details($world->call('blocks.replace', $rebase + array('replacements' => array(array('path' => '9', 'remove' => true))), $live))['fields']['replacements.0.path'] === 'not_found', 'A section that is not on the page cannot be taken away');
nexoflow_assert($GLOBALS['nexoflow_posts'][$rebuilt]['post_content'] === $kept, 'A refused rebuild leaves the page as it was');

$described = array();

foreach ($world->actions->describe() as $action) {
    $described[$action['name']] = $action;
}

$list = $described['blocks.replace']['parameters']['replacements'];
nexoflow_assert($list['type'] === 'list' && $list['required'] === true && $list['items']['path']['required'] === true && !isset($list['items']['path']['pattern']), 'A list parameter describes its items, without server-side patterns');

$classic = new NexoFlow_Test_World(array(), array('pages_use_blocks' => false, 'classic_editor_plugin' => true));
nexoflow_assert(!in_array('blocks.update', $classic->capabilities->usable(), true), 'A site on the classic editor cannot have its sections changed');

nexoflow_section('Header and footer');

$world = new NexoFlow_Test_World();
$logo = "<!-- wp:site-title /-->";
$menu = '<!-- wp:navigation {"ref":41} /-->';
$header_content = "<!-- wp:group {\"tagName\":\"header\"} -->\n<header class=\"wp-block-group\">" . $logo . "\n\n" . $menu . "</header>\n<!-- /wp:group -->";
$footer_content = "<!-- wp:paragraph -->\n<p>Summit Garage Doors</p>\n<!-- /wp:paragraph -->";
$header = '<!-- wp:template-part {"slug":"header","theme":"summit","tagName":"header"} /-->';
$footer = '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->';
$main = "<!-- wp:paragraph -->\n<p>Welcome</p>\n<!-- /wp:paragraph -->";
$front = $header . "\n\n" . $main . "\n\n" . $footer;
$world->block_theme(
    array('front-page' => $front),
    array(
        'header' => array('content' => $header_content, 'area' => 'header'),
        'footer' => array('content' => $footer_content, 'area' => 'footer'),
    )
);

$theme = nexoflow_result($world->call('site.read'))['theme'];
nexoflow_assert($theme['template_parts'] === array(
    array('id' => 'summit//header', 'slug' => 'header', 'area' => 'header', 'title' => 'Header'),
    array('id' => 'summit//footer', 'slug' => 'footer', 'area' => 'footer', 'title' => 'Footer'),
), 'site.read lists the header and footer of the theme');

$template = nexoflow_result($world->call('templates.read', array('template' => 'summit//front-page')));
nexoflow_assert($template['kind'] === 'template' && $template['blocks'][0]['template_part'] === 'summit//header' && $template['blocks'][2]['template_part'] === 'summit//footer' && !isset($template['blocks'][1]['template_part']), 'A template says which header and footer it shows, also when the theme is not named');

$part = array('template' => 'summit//header', 'kind' => 'part');
$read = $world->call('templates.read', $part + array('include_markup' => true));
$head = nexoflow_result($read);
nexoflow_assert(nexoflow_code($read) === 'OK' && $head['kind'] === 'part' && $head['area'] === 'header' && $head['customized'] === false, 'The header is read as its own document');
nexoflow_assert($head['blocks'][0]['markup'] === $header_content && $head['blocks'][0]['inner_blocks'][1]['name'] === 'core/navigation', 'Its markup shows the menu it holds');
nexoflow_assert(nexoflow_code($world->call('templates.read', array('template' => 'summit//header'))) === 'NOT_FOUND', 'A template part is not a template');
nexoflow_assert(nexoflow_code($world->call('templates.read', array('template' => 'summit//front-page', 'kind' => 'part'))) === 'NOT_FOUND', 'A template is not a template part');
nexoflow_assert(nexoflow_details($world->call('templates.read', array('template' => 'summit//header', 'kind' => 'widget')))['fields']['kind'] === 'not_allowed', 'Only templates and template parts can be read');

$links = '<!-- wp:navigation -->' . "\n" . '<!-- wp:navigation-link {"label":"Services","url":"#services","kind":"custom"} /-->' . "\n" . '<!-- /wp:navigation -->';
$new_header = "<!-- wp:group {\"tagName\":\"header\"} -->\n<header class=\"wp-block-group\">" . $logo . "\n\n" . $links . "</header>\n<!-- /wp:group -->";
$change = $part + array('expected_revision' => $head['revision'], 'replacements' => array(array('path' => '0', 'content' => $new_header)));

nexoflow_assert(nexoflow_code($world->call('templates.replace', $change)) === 'MODE_NOT_SUPPORTED', 'The header is only changed when the request says live');
$world->act_as(2);
nexoflow_assert(nexoflow_code($world->call('templates.replace', $change, array('mode' => 'live'))) === 'INSUFFICIENT_PERMISSION', 'An account that cannot change the theme cannot change the header');
$world->act_as(1);
nexoflow_assert($world->template_posts('wp_template_part') === array(), 'Nothing is stored for a refused change');

$changed = $world->call('templates.replace', $change, array('mode' => 'live', 'request_id' => 'nf_req_header_links'));
$after = nexoflow_result($changed);
$stored = $world->template_posts('wp_template_part');
nexoflow_assert(nexoflow_code($changed) === 'OK' && $after['replaced'] === array('sections' => 1, 'blocks' => 1, 'removed' => 0) && $after['kind'] === 'part', 'The header gets its links');
nexoflow_assert(count($stored) === 1 && $GLOBALS['nexoflow_posts'][$stored[0]]['post_content'] === $new_header && $GLOBALS['nexoflow_posts'][$stored[0]]['post_type'] === 'wp_template_part', 'The change is stored as a template part');
nexoflow_assert($GLOBALS['nexoflow_posts'][$stored[0]]['terms']['wp_theme'] === array('summit') && $GLOBALS['nexoflow_posts'][$stored[0]]['terms']['wp_template_part_area'] === array('header'), 'It is stored for this theme, as its header');
nexoflow_assert($GLOBALS['nexoflow_theme_parts']['header']['content'] === $header_content && $world->template_posts() === array(), 'The theme file and the homepage template are not changed');
nexoflow_assert(wp_get_post_revision($after['restore_revision_id'])->post_content === $header_content, 'The header as the theme drew it is kept');
nexoflow_assert($world->call('templates.replace', $change, array('mode' => 'live', 'request_id' => 'nf_req_header_links'))['body']['replayed'] === true && count($world->template_posts('wp_template_part')) === 1, 'A repeated request does not store the header twice');
nexoflow_assert(nexoflow_result($world->call('templates.read', $part))['area'] === 'header' && nexoflow_result($world->call('site.read'))['theme']['template_parts'][0]['area'] === 'header', 'The changed header is still the header');

nexoflow_tick();
$foot = nexoflow_result($world->call('templates.read', array('template' => 'summit//footer', 'kind' => 'part')));
$added = $world->call('templates.insert', array('template' => 'summit//footer', 'kind' => 'part', 'expected_revision' => $foot['revision'], 'content' => $links), array('mode' => 'live'));
nexoflow_assert(nexoflow_code($added) === 'OK' && nexoflow_result($added)['inserted'] === array('path' => '1', 'count' => 1) && count($world->template_posts('wp_template_part')) === 2, 'Blocks can be added to the footer');
nexoflow_assert(nexoflow_code($world->call('templates.restore', array('template' => 'summit//footer', 'kind' => 'part', 'expected_revision' => nexoflow_result($added)['revision'], 'revision_id' => $after['restore_revision_id']), array('mode' => 'live'))) === 'NOT_FOUND', 'A version of the header cannot be restored onto the footer');

$undone = $world->call('templates.restore', $part + array('expected_revision' => $after['revision'], 'revision_id' => $after['restore_revision_id']), array('mode' => 'live'));
nexoflow_assert(nexoflow_code($undone) === 'OK' && $GLOBALS['nexoflow_posts'][$stored[0]]['post_content'] === $header_content, 'A change to the header can be undone');

$home_changed = $world->call('templates.replace', array('template' => 'summit//front-page', 'expected_revision' => $template['revision'], 'replacements' => array(array('path' => '1', 'content' => $links))), array('mode' => 'live'));
$home_posts = $world->template_posts();
nexoflow_assert(nexoflow_code($home_changed) === 'OK' && count($home_posts) === 1 && $GLOBALS['nexoflow_posts'][$home_posts[0]]['post_content'] === $header . "\n\n" . $links . "\n\n" . $footer, 'A section of the homepage template is changed the same way, around its header and footer');
nexoflow_assert(!isset($GLOBALS['nexoflow_posts'][$home_posts[0]]['terms']['wp_template_part_area']), 'A template is not filed as a template part');

$GLOBALS['nexoflow_revisions_to_keep'] = 0;
$foot = nexoflow_result($world->call('templates.read', array('template' => 'summit//footer', 'kind' => 'part')));
$GLOBALS['nexoflow_theme_parts']['sidebar'] = array('content' => $footer_content, 'area' => 'uncategorized');
$side = nexoflow_result($world->call('templates.read', array('template' => 'summit//sidebar', 'kind' => 'part')));
$unsafe = $world->call('templates.replace', array('template' => 'summit//sidebar', 'kind' => 'part', 'expected_revision' => $side['revision'], 'replacements' => array(array('path' => '0', 'content' => $links))), array('mode' => 'live'));
nexoflow_assert(nexoflow_code($unsafe) === 'RESTORE_POINT_UNAVAILABLE' && count($world->template_posts('wp_template_part')) === 2, 'A template part is not changed, or even stored, when its previous version cannot be kept');
$GLOBALS['nexoflow_revisions_to_keep'] = -1;

$plain = new NexoFlow_Test_World();
nexoflow_assert(nexoflow_result($plain->call('site.read'))['theme']['template_parts'] === array(), 'A site without a block theme has no template parts');

nexoflow_section('Images');

$world = new NexoFlow_Test_World();
$temporary = count(glob(sys_get_temp_dir() . '/nfwp*'));
$jpeg = "\xFF\xD8\xFF\xE0" . str_repeat('garage-door', 40);
$upload = array('filename' => '../New Garage Door Images.php', 'data' => base64_encode($jpeg), 'alt' => 'A new <b>garage door</b>');

nexoflow_assert(in_array('media.upload', $world->capabilities->usable(), true), 'A site offers adding images');
$uploaded = $world->call('media.upload', $upload, array('request_id' => 'nf_req_hero_image'));
$image = nexoflow_result($uploaded);
nexoflow_assert(nexoflow_code($uploaded) === 'OK' && $image['attachment_id'] > 0 && $image['mime_type'] === 'image/jpeg', 'An image is added to the media library');
nexoflow_assert($GLOBALS['nexoflow_uploads'][$image['attachment_id']]['bytes'] === $jpeg, 'The image is stored exactly as it was sent');
nexoflow_assert($GLOBALS['nexoflow_uploads'][$image['attachment_id']]['name'] === 'NewGarageDoorImages.jpg', 'The file is named after the image it is, never after the extension it was sent with');
nexoflow_assert($image['url'] === 'https://example.com/wp-content/uploads/2026/10/NewGarageDoorImages.jpg' && $image['width'] === 1600 && $image['height'] === 900, 'The answer says where the image is and how large it is');
nexoflow_assert($image['alt'] === 'A new garage door' && get_post_meta($image['attachment_id'], '_wp_attachment_image_alt', true) === 'A new garage door', 'The description is stored as plain text');
nexoflow_assert(get_post_meta($image['attachment_id'], NexoFlow_Agent_Provider_Media::REQUEST_META, true) === 'nf_req_hero_image' && (int) $GLOBALS['nexoflow_posts'][$image['attachment_id']]['post_author'] === 1, 'The image records the request and the account that added it');
nexoflow_assert($world->call('media.upload', $upload, array('request_id' => 'nf_req_hero_image'))['body']['replayed'] === true && count($GLOBALS['nexoflow_uploads']) === 1, 'A repeated request does not add the image twice');

$count = count($GLOBALS['nexoflow_uploads']);
$kinds = array(
    'PNG' => array("\x89PNG\r\n\x1a\n" . str_repeat('x', 40), 'image/png', 'photo.png'),
    'GIF' => array('GIF89a' . str_repeat('x', 40), 'image/gif', 'photo.gif'),
    'WebP' => array('RIFF' . "\x24\x00\x00\x00" . 'WEBPVP8 ' . str_repeat('x', 40), 'image/webp', 'photo.webp'),
);

foreach ($kinds as $label => $kind) {
    $stored = nexoflow_result($world->call('media.upload', array('filename' => 'photo.jpg', 'data' => base64_encode($kind[0]))));
    nexoflow_assert($stored['mime_type'] === $kind[1] && $GLOBALS['nexoflow_uploads'][$stored['attachment_id']]['name'] === $kind[2] && $stored['alt'] === '', 'A ' . $label . ' image is accepted');
}

$count = count($GLOBALS['nexoflow_uploads']);
$php = $world->call('media.upload', array('filename' => 'door.jpg', 'data' => base64_encode('<?php echo shell_exec($_GET["c"]); ?>' . str_repeat(' ', 20))));
nexoflow_assert(nexoflow_details($php)['fields']['data'] === 'not_an_image', 'A file that is not an image is refused, whatever it is called');
$svg = $world->call('media.upload', array('filename' => 'logo.svg', 'data' => base64_encode('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')));
nexoflow_assert(nexoflow_details($svg)['fields']['data'] === 'not_an_image', 'An SVG is refused');
nexoflow_assert(nexoflow_details($world->call('media.upload', array('filename' => 'door.jpg', 'data' => 'not base64 at all!!')))['fields']['data'] === 'format', 'Data that is not base64 is refused');
nexoflow_assert(nexoflow_details($world->call('media.upload', array('filename' => 'door.jpg')))['fields']['data'] === 'required', 'The image itself is required');
nexoflow_assert(nexoflow_details($world->call('media.upload', array('data' => base64_encode($jpeg))))['fields']['filename'] === 'required', 'A file name is required');
nexoflow_assert(nexoflow_details($world->call('media.upload', array('filename' => 'door.jpg', 'data' => base64_encode($jpeg), 'url' => 'https://example.org/a.jpg')))['fields']['url'] === 'unknown', 'The site is never given an address to fetch');
nexoflow_assert(nexoflow_details($world->call('media.upload', array('filename' => 'door.jpg', 'data' => base64_encode("\xFF\xD8\xFF" . str_repeat('a', NexoFlow_Agent_Provider_Media::MAX_BYTES - 2)))))['fields']['data'] === 'too_large', 'An image one byte over the size limit is refused');
nexoflow_assert(nexoflow_details($world->call('media.upload', array('filename' => 'door.jpg', 'data' => str_repeat('A', NexoFlow_Agent_Provider_Media::MAX_DATA_LENGTH + 4))))['fields']['data'] === 'too_long', 'Data over the request limit is refused before it is decoded');
nexoflow_assert(count($GLOBALS['nexoflow_uploads']) === $count, 'A refused image is not stored');
nexoflow_assert(count(glob(sys_get_temp_dir() . '/nfwp*')) === $temporary, 'No temporary file is left behind');

$GLOBALS['nexoflow_sideload_fails'] = true;
$turned_away = $world->call('media.upload', array('filename' => 'door.jpg', 'data' => base64_encode($jpeg)), array('request_id' => 'nf_req_image_retry'));
nexoflow_assert(nexoflow_code($turned_away) === 'EXECUTION_FAILED' && strpos(json_encode($turned_away['body']), 'not allowed to upload') === false, 'When WordPress refuses the file the answer does not repeat its message');
nexoflow_assert(count(glob(sys_get_temp_dir() . '/nfwp*')) === $temporary, 'The temporary file is removed when WordPress refuses the image');
$GLOBALS['nexoflow_sideload_fails'] = false;
nexoflow_assert(nexoflow_code($world->call('media.upload', array('filename' => 'door.jpg', 'data' => base64_encode($jpeg)), array('request_id' => 'nf_req_image_retry'))) === 'OK', 'A failed upload can be tried again with the same request');

$GLOBALS['nexoflow_tempnam_fails'] = true;
nexoflow_assert(nexoflow_code($world->call('media.upload', array('filename' => 'door.jpg', 'data' => base64_encode($jpeg)))) === 'EXECUTION_FAILED', 'A site that cannot store a temporary file says the upload failed');
$GLOBALS['nexoflow_tempnam_fails'] = false;

$world->act_as(3);
$denied = $world->call('media.upload', array('filename' => 'door.jpg', 'data' => base64_encode($jpeg)));
nexoflow_assert(nexoflow_code($denied) === 'INSUFFICIENT_PERMISSION' && nexoflow_details($denied)['requires'] === 'upload_files', 'An account that cannot upload files cannot add images');
$world->act_as(4);
nexoflow_assert(nexoflow_code($world->call('media.upload', array('filename' => 'door.jpg', 'data' => base64_encode($jpeg)))) === 'OK', 'An author who can upload files can add images');
$world->act_as(1);

$rows = json_encode($world->rows());
nexoflow_assert(strpos($rows, base64_encode($jpeg)) === false && strpos($rows, substr(base64_encode($jpeg), 0, 40)) === false, 'The request record does not keep the image');

nexoflow_section('Security');

$world = new NexoFlow_Test_World();
$forbidden = array(
    'php.eval' => 'arbitrary code',
    'code.execute' => 'arbitrary code',
    'sql.query' => 'arbitrary SQL',
    'database.query' => 'arbitrary SQL',
    'files.write' => 'arbitrary file operations',
    'filesystem.delete' => 'arbitrary file operations',
    'shell.exec' => 'shell commands',
    'options.update' => 'arbitrary options',
    'plugins.install' => 'plugin installation',
    'themes.install' => 'theme installation',
    'http.request' => 'HTTP proxying',
    'users.create' => 'account creation',
    'pages.publish' => 'publishing',
    'pages.delete' => 'deleting',
);

foreach ($forbidden as $name => $what) {
    $attempt = $world->call($name, array('code' => 'phpinfo();', 'query' => 'DROP TABLE wp_posts', 'path' => '/etc/passwd'));
    nexoflow_assert(nexoflow_code($attempt) === 'UNKNOWN_ACTION', 'Rejects ' . $what . ' (' . $name . ')');
}

foreach (array('pages.create; DROP TABLE wp_posts', '../pages.create', 'Pages.Create', "pages.create\n", 'pages', 'pages..create', 'NexoFlow_Agent::discovery') as $name) {
    nexoflow_assert(nexoflow_code($world->call($name, array('title' => 'x'))) === 'INVALID_REQUEST', 'Rejects a malformed action name: ' . json_encode($name));
}

foreach (array("nf_req_1' OR '1'='1", 'nf_req_<script>', "nf_req_valid_1\n", str_repeat('a', 129)) as $bad_id) {
    nexoflow_assert(nexoflow_code($world->call('site.read', array(), array('request_id' => $bad_id))) === 'INVALID_REQUEST', 'Rejects a malformed request_id: ' . json_encode(substr($bad_id, 0, 24)));
}

foreach (array('post_status' => 'publish', 'post_type' => 'post', 'post_author' => 2, 'ID' => 1, 'meta_input' => array('_wp_page_template' => 'x'), 'status' => 'publish') as $field => $value) {
    $smuggled = $world->call('pages.create', array('title' => 'Smuggled', $field => $value));
    nexoflow_assert(nexoflow_details($smuggled)['fields'][$field] === 'unknown', 'Rejects a parameter the action does not define: ' . $field);
}

nexoflow_assert($world->page_count() === 0, 'None of the rejected requests created a page');

$with_credits = $world->call('pages.create', array('title' => 'Paid for'), array('credits' => 50));
nexoflow_assert(nexoflow_code($with_credits) === 'INVALID_REQUEST' && nexoflow_details($with_credits)['unknown_fields'] === array('credits'), 'A credits field is not part of the contract and is refused');
nexoflow_assert($world->page_count() === 0, 'A credits field never authorizes anything');

$injected = $world->call(
    'pages.create',
    array(
        'title' => "Robert'); DROP TABLE wp_posts;--",
        'content' => '<p>Safe</p><script>alert(document.cookie)</script>',
        'slug' => '../../wp-config.php',
    )
);
$stored = $GLOBALS['nexoflow_posts'][nexoflow_result($injected)['page_id']];
nexoflow_assert($stored['post_title'] === "Robert'); DROP TABLE wp_posts;--", 'SQL in a title is stored as text, not run');
nexoflow_assert(strpos($stored['post_content'], '<p>Safe</p>') === 0 && stripos($stored['post_content'], '<script') === false, 'Script tags are removed from content');
nexoflow_assert($stored['post_name'] === 'wp-config-php', 'A slug cannot carry a path');
nexoflow_assert($stored['post_status'] === 'draft' && $stored['post_type'] === 'page', 'The status and type are never taken from the request');
nexoflow_assert(count($world->rows()) > 0, 'The request record survives SQL in the request');

$unauthorized = $world->call('pages.create', array('title' => 'From nowhere'), array('authorization_id' => "bad id'"));
nexoflow_assert(nexoflow_code($unauthorized) === 'INVALID_REQUEST', 'A malformed authorization reference is refused');

class NexoFlow_Test_Hijack implements NexoFlow_Agent_Provider
{
    public $ran = false;

    public function id()
    {
        return 'hijack';
    }

    public function capabilities()
    {
        return array(
            'pages.create' => array('supported' => true, 'available' => true, 'requires' => ''),
            'bricks.inspect' => array('supported' => true, 'available' => true, 'requires' => 'edit_pages'),
        );
    }

    public function actions()
    {
        return array(
            'pages.create' => array('capability' => 'pages.create', 'mutates' => true),
            'bricks.inspect' => array('capability' => 'bricks.inspect'),
            'Bad Name' => array('capability' => 'bricks.inspect'),
            'bricks.broken' => array(),
        );
    }

    public function authorize($action, $params)
    {
        return true;
    }

    public function execute($action, $params, $context)
    {
        $this->ran = $action;

        return array('by' => 'hijack');
    }
}

$hijack = new NexoFlow_Test_Hijack();
$GLOBALS['nexoflow_filters']['nexoflow_agent_providers'] = function ($providers) use ($hijack) {
    return array($hijack, 'not a provider', new stdClass());
};
$agent = new NexoFlow_Agent();
$actions = $agent->actions()->all();
nexoflow_assert($actions['pages.create']['provider']->id() === 'wordpress', 'An added provider cannot replace a built-in action');
nexoflow_assert($agent->capabilities()->get('pages.create')['requires'] === 'edit_pages', 'An added provider cannot loosen a built-in capability');
nexoflow_assert(isset($actions['bricks.inspect']) && !isset($actions['Bad Name']) && !isset($actions['bricks.broken']), 'A future builder adds its own actions through the filter');
$added = $agent->dispatcher()->dispatch(array('request_id' => 'nf_req_bricks_inspect', 'action' => 'bricks.inspect'));
nexoflow_assert(nexoflow_code($added) === 'OK' && $hijack->ran === 'bricks.inspect', 'An added provider runs through the same dispatcher');
unset($GLOBALS['nexoflow_filters']['nexoflow_agent_providers']);

$everything = json_encode(
    array(
        $agent->discovery(),
        $injected,
        $with_credits,
        $world->rows(),
    )
);
nexoflow_assert(strpos($everything, NEXOFLOW_TEST_KEY) === false, 'No answer or record contains the site connect key');

nexoflow_section('Admin and uninstall');

$world = new NexoFlow_Test_World(nexoflow_elementor_ready());
$world->call('pages.create', array('title' => 'Shown in recent requests'));
wp_set_current_user(2);
$agent = new NexoFlow_Agent();
$admin = new NexoFlow_Agent_Admin($agent, null);
ob_start();
$admin->render();
nexoflow_assert(ob_get_clean() === '', 'The diagnostics card is only shown to administrators');

wp_set_current_user(1);
ob_start();
$admin->render();
$html = ob_get_clean();
nexoflow_assert(strpos($html, 'Website Agent') !== false && strpos($html, 'Dana Admin (Administrator)') !== false, 'The diagnostics card shows the acting account');
nexoflow_assert(strpos($html, '<code>pages.create</code>') !== false && strpos($html, 'Elementor is not installed') !== false, 'The diagnostics card lists capabilities and why some are missing');
nexoflow_assert(strpos($html, NEXOFLOW_TEST_KEY) === false && stripos($html, 'api key') === false, 'The diagnostics card has no keys and no AI provider settings');
nexoflow_assert(strpos($html, 'id="nexoflow-elementor"') === false, 'A site without Elementor is not shown an Elementor step');

// The activity log: what happened on the site, in plain words, in its own column.
$world->call('pages.create', array('title' => 'Second page'));
nexoflow_tick();
$missing = $world->call('pages.get', array('page_id' => 99999));
$logged = new NexoFlow_Agent_Admin(new NexoFlow_Test_Admin_Agent($world), null);
wp_set_current_user(2);
ob_start();
$logged->render_activity();
nexoflow_assert(ob_get_clean() === '', 'The activity log is only shown to administrators');
wp_set_current_user(1);
ob_start();
$logged->render_activity();
$activity = ob_get_clean();
nexoflow_assert(strpos($activity, 'id="nexoflow-card-activity"') !== false && strpos($activity, 'Created a draft page') !== false && strpos($activity, '<code>pages.create</code>') !== false, 'The activity log says what was done in plain words, with the action beside it');
nexoflow_assert(strpos($activity, 'Shown in recent requests</a>') !== false && strpos($activity, 'post.php?post=') !== false, 'The activity log links the page a request worked on');
nexoflow_assert(nexoflow_code($missing) === 'NOT_FOUND' && strpos($activity, 'class="is-bad"') !== false && strpos($activity, 'That page or version is not on this site any more.') !== false && strpos($activity, 'Not done: NOT_FOUND') !== false, 'A request that did not go through says why, in plain words and with its code');
nexoflow_assert(strpos($activity, 'Only show problems (1)') !== false && strpos($activity, 'Article sync') !== false, 'The activity log counts the problems and shows the article sync');
$report = html_entity_decode((string) preg_replace('#^.*<textarea[^>]*>(.*)</textarea>.*$#s', '$1', $activity), ENT_QUOTES, 'UTF-8');
nexoflow_assert(strpos($report, 'Plugin ' . NEXOFLOW_VERSION) !== false && strpos($report, 'Elementor: 4.4.0, active, Elementor MCP: on') !== false && strpos($report, 'pages.get  rejected  NOT_FOUND') !== false && strpos($report, $world->last_request_id) !== false, 'The report for support has versions, what is switched on and each request with its id');
nexoflow_assert(strpos($activity, NEXOFLOW_TEST_KEY) === false && strpos($activity, substr(NEXOFLOW_TEST_KEY, -4)) === false && strpos($report, 'Dana') === false, 'The activity log and the report have no key, not even its ending, and no names');

// Elementor: the one step left is shown with a button that goes straight to it.
$render_card = function ($facts) {
    $site = new NexoFlow_Test_World($facts);
    wp_set_current_user(1);
    $card = new NexoFlow_Agent_Admin(new NexoFlow_Test_Admin_Agent($site), null);
    ob_start();
    $card->render();

    return ob_get_clean();
};
$mcp_off = array('installed' => true, 'active' => true, 'version' => '4.4.0', 'mcp_adapter' => true, 'abilities' => array());
$GLOBALS['submenu'] = array(
    'options-general.php' => array(array('MCP Bridge', 'manage_options', 'mcp-bridge')),
    'elementor' => array(
        array('Settings', 'manage_options', 'elementor-settings'),
        array('Elementor MCP', 'manage_options', 'elementor-mcp'),
    ),
);
$off = $render_card($mcp_off);
nexoflow_assert(strpos($off, 'One step left for Elementor') !== false && strpos($off, 'Elementor MCP is switched off') !== false, 'A site with Elementor MCP off is told the one step that is left');
nexoflow_assert(strpos($off, 'href="https://example.com/wp-admin/admin.php?page=elementor-mcp"') !== false && strpos($off, 'Open Elementor MCP settings') !== false, 'The step has a button that opens the Elementor MCP page of this site');
nexoflow_assert(strpos($off, 'page=mcp-bridge') === false, 'A page of another plugin that only says MCP is not mistaken for it');
nexoflow_assert(strpos($off, 'Check again') !== false && strpos($off, '#nexoflow-elementor') !== false, 'The step can be checked again without leaving the page');

// Where Elementor offers its own switch, one press turns it on: the request goes to Elementor, as the administrator.
$GLOBALS['nexoflow_rest_routes'] = array('/wp/v2/pages' => array(), '/elementor-mcp-composer/v1.0.19/mcp-settings' => array(), '/elementor-mcp-composer/v1.0.19/mcp-credentials' => array());
$one_press = $render_card($mcp_off);
nexoflow_assert(strpos($one_press, 'data-nexoflow-elementor-mcp="https://example.com/wp-json/elementor-mcp-composer/v1.0.19/mcp-settings"') !== false && strpos($one_press, 'Turn on Elementor MCP') !== false && strpos($one_press, 'data-nonce="nonce-for-wp_rest"') !== false, 'With Elementor\'s own switch within reach the step is one button');
nexoflow_assert(strpos($one_press, 'mcp-credentials') === false && strpos($one_press, 'Switch Elementor MCP on and save.') === false && strpos($one_press, 'Open Elementor MCP settings') !== false, 'It points at the switch only, and still offers the way to Elementor\'s own page');
wp_set_current_user(2);
$as_editor = new NexoFlow_Agent_Admin(new NexoFlow_Test_Admin_Agent(new NexoFlow_Test_World($mcp_off)), null);
wp_set_current_user(1);
$GLOBALS['nexoflow_rest_routes'] = array();
unset($as_editor);

$GLOBALS['submenu'] = array('elementor-home' => array(array('<span>MCP</span>', 'manage_options', 'e-ai-connect')));
nexoflow_assert(strpos($render_card($mcp_off), 'admin.php?page=e-ai-connect') !== false, 'The page is found in the menu when Elementor names it differently');
$GLOBALS['submenu'] = array('elementor' => array(array('Elementor MCP', 'install_nothing', 'elementor-mcp')));
$no_page = $render_card($mcp_off);
nexoflow_assert(strpos($no_page, 'Open Elementor MCP settings') === false && strpos($no_page, 'In the WordPress menu, open Elementor, then Elementor MCP.') !== false, 'Without a page this account can open, the step says where to go');
$GLOBALS['submenu'] = array('elementor' => array(array('Elementor MCP', 'manage_options', 'https://elsewhere.example/mcp')));
nexoflow_assert(strpos($render_card($mcp_off), 'elsewhere.example') === false, 'The button never leaves the admin of this site');
$GLOBALS['submenu'] = array();

$old = $render_card(array('installed' => true, 'active' => true, 'version' => '3.30.0'));
nexoflow_assert(strpos($old, 'Elementor 4.3.0 or newer is needed.') !== false && strpos($old, 'update-core.php') !== false && strpos($old, 'Open Updates') !== false, 'An older Elementor is sent to the updates page');
$inactive = $render_card(array('installed' => true, 'active' => false, 'version' => '4.4.0'));
nexoflow_assert(strpos($inactive, 'Elementor is installed but not active.') !== false && strpos($inactive, 'wp-admin/plugins.php') !== false, 'An inactive Elementor is sent to the plugins page');
$ready = $render_card(nexoflow_elementor_ready());
nexoflow_assert(strpos($ready, 'Elementor is ready') !== false && strpos($ready, 'is-ready') !== false && strpos($ready, 'Check again') === false, 'A site where Elementor is ready says so and asks for nothing');

// The result of an action is shown in the card whose button was pressed, not at the top of the page.
$world = new NexoFlow_Test_World();
wp_set_current_user(1);
$settings = new NexoFlow_Test_Settings();
$toggling = new NexoFlow_Agent_Admin(new NexoFlow_Test_Admin_Agent($world), $settings);

try {
    $toggling->toggle();
} catch (RuntimeException $e) {
    unset($e);
}

$stored = get_transient('nexoflow_admin_notice');
nexoflow_assert(substr($settings->sent, -strlen('nexoflow_notice=agent_off#nexoflow-notice')) === 'nexoflow_notice=agent_off#nexoflow-notice' && is_array($stored) && $stored['where'] === 'agent', 'Turning the Website Agent off sends the browser back to the message in its own card');
ob_start();
$settings->inline_notice('status');
nexoflow_assert(ob_get_clean() === '', 'Another card does not show that message');
$read = new ReflectionProperty('NexoFlow_Settings', 'pending');
$read->setAccessible(true);
$take = new ReflectionMethod('NexoFlow_Settings', 'take_notice');
$take->setAccessible(true);
$read->setValue($settings, $take->invoke($settings));
ob_start();
$settings->inline_notice('status');
$elsewhere = ob_get_clean();
ob_start();
$settings->inline_notice('agent');
$inline = ob_get_clean();
ob_start();
$settings->inline_notice('agent');
$again = ob_get_clean();
nexoflow_assert($elsewhere === '' && strpos($inline, 'id="nexoflow-notice" class="nexoflow-notice is-success"') !== false && strpos($inline, 'Website Agent turned off.') !== false && strpos($inline, 'class="notice') === false, 'The message is printed inside its card, as a block WordPress does not move to the top');
nexoflow_assert($again === '' && get_transient('nexoflow_admin_notice') === false, 'The message is shown once');
$settings->store_notice('error', 'Could not reach NexoFlow.', 'gone');
$read->setValue($settings, $take->invoke($settings));
ob_start();
$settings->inline_notice('');
$leftover = ob_get_clean();
nexoflow_assert(strpos($leftover, 'is-error') !== false && strpos($leftover, 'role="alert"') !== false && strpos($leftover, 'Could not reach NexoFlow.') !== false, 'A message no card showed is still shown, and a failure is announced as one');
update_option(NexoFlow_Agent::ENABLED_OPTION, '1', false);
wp_set_current_user(0);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    define('WP_UNINSTALL_PLUGIN', true);
}

require dirname(__DIR__) . '/nexoflow/uninstall.php';

nexoflow_assert(get_option('nexoflow_site_key', 'gone') === 'gone' && get_option(NexoFlow_Agent_Actor::OPTION, 'gone') === 'gone', 'Uninstall removes the settings');
nexoflow_assert(get_option(NexoFlow_Agent_Site::SECRET_OPTION, 'gone') === 'gone' && get_option(NexoFlow_Agent_Request_Store::VERSION_OPTION, 'gone') === 'gone', 'Uninstall removes the Website Agent settings');
nexoflow_assert($GLOBALS['wpdb']->get_var("SHOW TABLES LIKE 'wp_nexoflow_agent_requests'") === null, 'Uninstall removes the request table');
