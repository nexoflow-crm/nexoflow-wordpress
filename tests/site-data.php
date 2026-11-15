<?php

/**
 * The structured data the site prints and the llms.txt it serves
 * (includes/class-site-data.php): what is kept from NexoFlow's answer, which
 * page gets which markup, and that a failed or unchanged fetch changes nothing.
 */

require_once dirname(__DIR__) . '/nexoflow/includes/class-cron.php';
require_once dirname(__DIR__) . '/nexoflow/includes/class-site-data.php';

class NexoFlow_Test_Site_Data_Client
{
    public $markup;

    public $llms;

    public $asked = array();

    public function get_structured_data($held_version = '')
    {
        $this->asked[] = array('structured-data', $held_version);

        return $this->markup;
    }

    public function get_llms_txt($held_version = '')
    {
        $this->asked[] = array('llms-txt', $held_version);

        return $this->llms;
    }
}

$nexoflow_origin = 'https://summit.example';
$nexoflow_home_markup = array(
    '@context' => 'https://schema.org',
    '@graph' => array(
        array('@type' => 'Plumber', '@id' => $nexoflow_origin . '/#localbusiness', 'name' => 'Summit </script><script>alert(1)</script> & Sons'),
    ),
);
$nexoflow_service_markup = array(
    '@context' => 'https://schema.org',
    '@graph' => array(array('@type' => 'Service', 'name' => 'Drain Cleaning')),
);
$nexoflow_llms_text = "# Summit Plumbing\r\n\r\n> Summit Plumbing is a plumber serving Denver.\r\n\r\n- Website: https://summit.example/\n\n## Services\n\n- [Drain Cleaning](https://summit.example/drain-cleaning)\n";

// ── The page address both sides compare ────────────────────────────────────
nexoflow_assert(NexoFlow_Site_Data::page_key($nexoflow_origin, '/') === 'https://summit.example/', 'The home page keeps its slash');
nexoflow_assert(NexoFlow_Site_Data::page_key($nexoflow_origin . '/', '/drain-cleaning/') === 'https://summit.example/drain-cleaning', 'A page address has no trailing slash');
nexoflow_assert(NexoFlow_Site_Data::page_key($nexoflow_origin, '/drain-cleaning/?utm_source=x#top') === 'https://summit.example/drain-cleaning', 'The query and the fragment are not part of the address');
nexoflow_assert(NexoFlow_Site_Data::page_key($nexoflow_origin, '/caf%c3%a9/') === 'https://summit.example/caf%C3%A9', 'Percent escapes are compared in upper case');
nexoflow_assert(NexoFlow_Site_Data::page_key('', '/about') === '' && NexoFlow_Site_Data::page_key($nexoflow_origin, 'about') === '', 'No site address or no path: no key');

// ── What is kept from the structured data answer ───────────────────────────
$nexoflow_answer = array(
    'ok' => true,
    'version' => 'a1b2c3d4e5f60718',
    'origin' => $nexoflow_origin,
    'pages' => array(
        $nexoflow_origin . '/' => $nexoflow_home_markup,
        $nexoflow_origin . '/drain-cleaning' => $nexoflow_service_markup,
        'https://elsewhere.example/' => $nexoflow_home_markup,
        $nexoflow_origin . '/not-markup' => array('title' => 'no context'),
        $nexoflow_origin . '/a-string' => '<script>alert(1)</script>',
        $nexoflow_origin . '/other-vocabulary' => array('@context' => 'https://example.com/terms'),
    ),
);
$nexoflow_clean = NexoFlow_Site_Data::clean_markup($nexoflow_answer);
nexoflow_assert(is_array($nexoflow_clean) && $nexoflow_clean['version'] === 'a1b2c3d4e5f60718' && $nexoflow_clean['origin'] === $nexoflow_origin, 'The version and the site address are kept');
nexoflow_assert(array_keys($nexoflow_clean['pages']) === array($nexoflow_origin . '/', $nexoflow_origin . '/drain-cleaning'), 'Only this site\'s pages with schema.org markup are kept');
nexoflow_assert(NexoFlow_Site_Data::clean_markup(array('ok' => false, 'error' => 'no')) === null, 'A refusal is not markup');
nexoflow_assert(NexoFlow_Site_Data::clean_markup(array('ok' => true, 'version' => 'bad version!', 'pages' => array())) === null, 'A version that is not one is refused');
nexoflow_assert(NexoFlow_Site_Data::clean_markup(array('ok' => true, 'version' => 'v1', 'origin' => 'javascript:alert(1)', 'pages' => array())) === null, 'A site address that is not a web address is refused');
$nexoflow_empty = NexoFlow_Site_Data::clean_markup(array('ok' => true, 'version' => 'e3b0c44298fc1c14', 'origin' => null, 'pages' => array()));
nexoflow_assert(is_array($nexoflow_empty) && $nexoflow_empty['pages'] === array(), 'Switched off in NexoFlow: an empty set is kept, so the site stops printing');

$nexoflow_many = array('ok' => true, 'version' => 'v2', 'origin' => $nexoflow_origin, 'pages' => array());
for ($nexoflow_i = 0; $nexoflow_i < 260; $nexoflow_i++) {
    $nexoflow_many['pages'][$nexoflow_origin . '/page-' . $nexoflow_i] = $nexoflow_service_markup;
}
$nexoflow_many['pages'][$nexoflow_origin . '/huge'] = array('@context' => 'https://schema.org', 'description' => str_repeat('x', 70000));
$nexoflow_capped = NexoFlow_Site_Data::clean_markup($nexoflow_many);
nexoflow_assert(count($nexoflow_capped['pages']) === NexoFlow_Site_Data::MAX_PAGES, 'At most 200 pages are kept');

// ── Fetching: stored, unchanged, failed ────────────────────────────────────
nexoflow_reset_store();
$nexoflow_client = new NexoFlow_Test_Site_Data_Client();
$nexoflow_client->markup = $nexoflow_answer;
$nexoflow_client->llms = array('ok' => true, 'version' => '0f1e2d3c4b5a6978', 'text' => $nexoflow_llms_text);
$nexoflow_site_data = new NexoFlow_Site_Data();

nexoflow_assert($nexoflow_site_data->refresh(true, $nexoflow_client) === false && $nexoflow_client->asked === array(), 'A site that is not connected asks NexoFlow for nothing');

update_option('nexoflow_site_key', 'nfwp_0123456789abcdef0123456789abcdef0123456789abcd25');
nexoflow_assert($nexoflow_site_data->refresh(false, $nexoflow_client) === true, 'A connected site fetches both the first time');
nexoflow_assert($nexoflow_client->asked === array(array('structured-data', ''), array('llms-txt', '')), 'The first fetch holds no version yet');
$nexoflow_summary = NexoFlow_Site_Data::summary();
nexoflow_assert($nexoflow_summary['pages'] === 2 && $nexoflow_summary['llms'] === true, 'Both are stored on the site');

$nexoflow_client->asked = array();
nexoflow_assert($nexoflow_site_data->refresh(false, $nexoflow_client) === false && $nexoflow_client->asked === array(), 'Within the hour NexoFlow is not asked again');

$nexoflow_client->markup = array('ok' => true, 'version' => 'a1b2c3d4e5f60718', 'unchanged' => true);
$nexoflow_client->llms = array('ok' => true, 'version' => '0f1e2d3c4b5a6978', 'unchanged' => true);
nexoflow_assert($nexoflow_site_data->refresh(true, $nexoflow_client) === true, 'Sync now asks again');
nexoflow_assert($nexoflow_client->asked === array(array('structured-data', 'a1b2c3d4e5f60718'), array('llms-txt', '0f1e2d3c4b5a6978')), 'The site sends the versions it holds');
nexoflow_assert(NexoFlow_Site_Data::summary()['pages'] === 2 && NexoFlow_Site_Data::stored_llms()['text'] !== '', 'Unchanged: what the site has stays');

$nexoflow_client->markup = new WP_Error('nexoflow_http', 'Could not reach NexoFlow.');
$nexoflow_client->llms = array('ok' => false, 'error' => 'The file is not available right now.');
$nexoflow_site_data->refresh(true, $nexoflow_client);
nexoflow_assert(NexoFlow_Site_Data::summary()['pages'] === 2 && NexoFlow_Site_Data::stored_llms()['text'] !== '', 'A failed fetch changes nothing on the site');

$nexoflow_client->markup = array('ok' => true, 'version' => 'a1b2c3d4e5f60718', 'unchanged' => true);
$nexoflow_client->llms = array('ok' => true, 'version' => '0f1e2d3c4b5a6978', 'text' => "<html><body>not the file</body></html>");
$nexoflow_site_data->refresh(true, $nexoflow_client);
nexoflow_assert(strpos(NexoFlow_Site_Data::stored_llms()['text'], '# Summit Plumbing') === 0, 'Text that is not an llms.txt never replaces the file');

// ── What a page prints ─────────────────────────────────────────────────────
$nexoflow_tag = NexoFlow_Site_Data::script_tag_for('/?utm_source=newsletter');
nexoflow_assert(strpos($nexoflow_tag, '<script type="application/ld+json" class="nexoflow-structured-data">') === 0, 'The home page prints its script tag');
nexoflow_assert(substr_count($nexoflow_tag, '<script') === 1 && substr_count($nexoflow_tag, '</script>') === 1, 'Nothing in the data can close the tag or open another');
preg_match('#<script[^>]*>(.*)</script>#s', $nexoflow_tag, $nexoflow_match);
nexoflow_assert(json_decode($nexoflow_match[1], true) === $nexoflow_home_markup, 'The printed JSON is the markup NexoFlow sent, unchanged');
nexoflow_assert(strpos($nexoflow_tag, 'https://schema.org') !== false, 'Addresses are printed as they are, without escaped slashes');
nexoflow_assert(strpos(NexoFlow_Site_Data::script_tag_for('/drain-cleaning/'), '"Drain Cleaning"') !== false, 'A service page prints its own markup');
nexoflow_assert(NexoFlow_Site_Data::script_tag_for('/blog/page/2/') === '', 'A page NexoFlow has no markup for prints nothing');

// ── llms.txt ───────────────────────────────────────────────────────────────
$nexoflow_stored_llms = NexoFlow_Site_Data::stored_llms();
nexoflow_assert(strpos($nexoflow_stored_llms['text'], "\r") === false && substr($nexoflow_stored_llms['text'], -1) === "\n", 'The file is stored with plain line breaks and ends with one');
nexoflow_assert(strpos($nexoflow_stored_llms['text'], '- [Drain Cleaning](https://summit.example/drain-cleaning)') !== false, 'Its links are kept as they are');
nexoflow_assert(strpos($nexoflow_stored_llms['text'], "\n> Summit Plumbing is a plumber serving Denver.\n") !== false, 'Its summary line keeps the mark it starts with');
nexoflow_assert(NexoFlow_Site_Data::clean_llms_text("# Title\n\n<script>alert(1)</script>\x00\x07 text") === "# Title\n\nscript>alert(1)/script> text\n", 'Nothing that opens a tag, and no control character, reaches the file');
nexoflow_assert(NexoFlow_Site_Data::clean_llms_text('no title line') === '' && NexoFlow_Site_Data::clean_llms_text(str_repeat('# a', 40000)) === '', 'Text without a title line, or too long, is not a file');
nexoflow_assert(NexoFlow_Site_Data::is_llms_request('/llms.txt', '/') === true && NexoFlow_Site_Data::is_llms_request('/llms.txt?x=1', '') === true, 'The site\'s own /llms.txt is recognised');
nexoflow_assert(NexoFlow_Site_Data::is_llms_request('/shop/llms.txt', '/shop/') === true, 'A site in a folder serves it from that folder');
nexoflow_assert(NexoFlow_Site_Data::is_llms_request('/blog/llms.txt', '/') === false && NexoFlow_Site_Data::is_llms_request('/llms.txt/', '/') === false && NexoFlow_Site_Data::is_llms_request('/robots.txt', '/') === false, 'No other address is');

// NexoFlow has no file for the site any more: none is served.
$nexoflow_client->markup = array('ok' => true, 'version' => 'e3b0c44298fc1c14', 'origin' => null, 'pages' => array());
$nexoflow_client->llms = array('ok' => true, 'version' => 'none', 'text' => null);
$nexoflow_site_data->refresh(true, $nexoflow_client);
nexoflow_assert(NexoFlow_Site_Data::summary()['pages'] === 0 && NexoFlow_Site_Data::summary()['llms'] === false, 'Switched off in NexoFlow: the site stops printing and serving');
nexoflow_assert(NexoFlow_Site_Data::script_tag_for('/') === '', 'And the home page prints nothing');

// Disconnecting removes both.
$nexoflow_client->markup = $nexoflow_answer;
$nexoflow_client->llms = array('ok' => true, 'version' => '0f1e2d3c4b5a6978', 'text' => $nexoflow_llms_text);
$nexoflow_site_data->refresh(true, $nexoflow_client);
nexoflow_assert(NexoFlow_Site_Data::summary()['pages'] === 2, 'Fetched again');
NexoFlow_Site_Data::forget();
nexoflow_assert(NexoFlow_Site_Data::summary() === array('pages' => 0, 'markup_fetched' => 0, 'llms' => false, 'llms_fetched' => 0) && get_option(NexoFlow_Site_Data::OPTION_CHECKED, 0) === 0, 'Disconnecting removes the structured data, the file and the fetch time');

nexoflow_assert(method_exists('NexoFlow_Client', 'get_structured_data') && method_exists('NexoFlow_Client', 'get_llms_txt'), 'The client can ask NexoFlow for both');
