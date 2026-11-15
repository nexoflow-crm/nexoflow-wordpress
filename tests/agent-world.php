<?php

const NEXOFLOW_TEST_KEY = 'nfwp_7c3a91e0b2d84f16a0c5e9d1b7f3082a4e6c1d9b0a8f3e25';

const NEXOFLOW_TEST_OTHER_KEY = 'nfwp_000000000000000000000000000000000000000000000000';

const NEXOFLOW_TEST_ELEMENTOR_ABILITIES = array(
    'elementor/build-composition',
    'elementor/create-page',
    'elementor/get-page-structure',
    'elementor/get-widget-schema',
    'elementor/list-assets',
    'elementor/list-widget-schemas',
    'elementor/manage-elements',
    'elementor/publish-document',
    'elementor/update-page-settings',
);

class NexoFlow_Test_Gutenberg extends NexoFlow_Agent_Provider_Gutenberg
{
    public $facts = array();

    protected function probe()
    {
        return $this->facts + array(
            'parser' => true,
            'serializer' => true,
            'pages_use_blocks' => true,
            'classic_editor_plugin' => false,
            'gutenberg_plugin' => '',
            'block_theme' => false,
            'block_types' => 90,
        );
    }
}

class NexoFlow_Test_Media extends NexoFlow_Agent_Provider_Media
{
    // The admin files WordPress loads here are stubbed for the tests.
    protected function load_media_functions()
    {
    }
}

class NexoFlow_Test_Elementor extends NexoFlow_Agent_Provider_Elementor
{
    public $facts = array();

    public $built = array();

    protected function probe()
    {
        return $this->facts + array(
            'installed' => false,
            'active' => false,
            'version' => '',
            'pro_installed' => false,
            'pro_active' => false,
            'pro_version' => '',
            'atomic_editor' => null,
            'abilities_api' => true,
            'mcp_adapter' => false,
            'abilities' => array(),
        );
    }

    protected function built_with_elementor($post)
    {
        return in_array((int) $post->ID, $this->built, true);
    }
}

function nexoflow_elementor_ready()
{
    return array(
        'installed' => true,
        'active' => true,
        'version' => '4.4.0',
        'atomic_editor' => true,
        'mcp_adapter' => true,
        'abilities' => NEXOFLOW_TEST_ELEMENTOR_ABILITIES,
    );
}

/** What the settings page asks of the plugin, answered by a test world. */
class NexoFlow_Test_Admin_Agent
{
    private $world;

    public function __construct($world)
    {
        $this->world = $world;
    }

    public function actor()
    {
        return $this->world->actor;
    }

    public function store()
    {
        return $this->world->store;
    }

    public function capabilities()
    {
        return $this->world->capabilities;
    }

    public function builders()
    {
        return array(
            'gutenberg' => $this->world->gutenberg->detect(),
            'elementor' => $this->world->elementor->detect(),
        );
    }
}

/** Remembers what a settings action said and where it sent the browser, instead of redirecting. */
class NexoFlow_Test_Settings extends NexoFlow_Settings
{
    public $sent = '';

    public function guard($action)
    {
    }

    public function redirect($notice, $where = '')
    {
        $this->sent = $this->return_url($notice, $where);

        throw new RuntimeException('redirected');
    }
}

/**
 * A connected site with an administrator (1), an editor (2), an account that may
 * only draft its own pages (3) and an author who cannot touch pages (4).
 */
class NexoFlow_Test_World
{
    public $documents;

    public $site;

    public $actor;

    public $store;

    public $templates;

    public $parts;

    public $wordpress;

    public $media;

    public $gutenberg;

    public $elementor;

    public $capabilities;

    public $actions;

    public $dispatcher;

    public $last_request_id = '';

    private $counter = 0;

    public function __construct($elementor_facts = array(), $gutenberg_facts = array(), $install_store = true)
    {
        nexoflow_reset_store();

        $GLOBALS['nexoflow_users'] = array();
        $GLOBALS['nexoflow_current_user'] = 0;
        $GLOBALS['nexoflow_filters'] = array();
        $GLOBALS['nexoflow_abilities'] = array();
        $GLOBALS['nexoflow_transients'] = array();
        $GLOBALS['nexoflow_revisions'] = array();
        $GLOBALS['nexoflow_revision_posts'] = array();
        $GLOBALS['nexoflow_next_revision_id'] = 5000;
        $GLOBALS['nexoflow_revisions_to_keep'] = -1;
        $GLOBALS['nexoflow_no_revisions'] = false;
        $GLOBALS['nexoflow_restore_fails'] = false;
        $GLOBALS['nexoflow_block_theme'] = false;
        $GLOBALS['nexoflow_theme_templates'] = array();
        $GLOBALS['nexoflow_theme_parts'] = array();
        $GLOBALS['nexoflow_uploads'] = array();
        $GLOBALS['nexoflow_deleted_files'] = array();
        $GLOBALS['nexoflow_sideload_fails'] = false;
        $GLOBALS['nexoflow_tempnam_fails'] = false;
        $GLOBALS['nexoflow_clock'] = '2026-10-05 10:00:00';
        $GLOBALS['nexoflow_home'] = 'https://example.com';
        $GLOBALS['nexoflow_insert_fails'] = false;
        $GLOBALS['nexoflow_insert_throws'] = false;
        $GLOBALS['nexoflow_dbdelta_fails'] = false;
        $GLOBALS['wpdb'] = new NexoFlow_Test_Wpdb();

        unset($_SERVER['HTTP_AUTHORIZATION']);

        nexoflow_test_user(1, 'administrator', NEXOFLOW_TEST_ADMIN_CAPS, 'Dana Admin');
        nexoflow_test_user(2, 'editor', NEXOFLOW_TEST_EDITOR_CAPS, 'Eli Editor');
        nexoflow_test_user(3, 'page_drafter', array('read', 'edit_posts', 'edit_pages'), 'Drew Drafter');
        nexoflow_test_user(4, 'author', array('read', 'edit_posts', 'publish_posts', 'upload_files'), 'Ari Author');

        update_option('nexoflow_site_key', NEXOFLOW_TEST_KEY);
        update_option(NexoFlow_Agent_Actor::OPTION, 1);

        $this->store = new NexoFlow_Agent_Request_Store();

        if ($install_store) {
            $this->store->install();
        }

        $this->documents = new NexoFlow_Agent_Documents();
        $this->site = new NexoFlow_Agent_Site();
        $this->actor = new NexoFlow_Agent_Actor();
        $this->templates = new NexoFlow_Agent_Templates($this->documents);
        $this->parts = new NexoFlow_Agent_Templates($this->documents, NexoFlow_Agent_Templates::PART_TYPE);
        $this->wordpress = new NexoFlow_Agent_Provider_WordPress($this->documents, $this->site, $this->templates, $this->parts);
        $this->gutenberg = new NexoFlow_Test_Gutenberg($this->documents, $this->templates, $this->parts);
        $this->media = new NexoFlow_Test_Media();
        $this->gutenberg->facts = $gutenberg_facts;
        $this->elementor = new NexoFlow_Test_Elementor(new NexoFlow_Agent_Abilities(), $this->documents);
        $this->elementor->facts = $elementor_facts;

        $this->documents->set_builders(array($this->elementor, $this->gutenberg));

        $providers = array($this->wordpress, $this->gutenberg, $this->media, $this->elementor);

        $this->capabilities = new NexoFlow_Agent_Capability_Registry($providers, $this->actor, $this->store);
        $this->actions = new NexoFlow_Agent_Action_Registry($providers);
        $this->dispatcher = new NexoFlow_Agent_Dispatcher(
            $this->actions,
            $this->capabilities,
            new NexoFlow_Agent_Validator(),
            $this->actor,
            $this->site,
            $this->store
        );
    }

    public function act_as($user_id)
    {
        update_option(NexoFlow_Agent_Actor::OPTION, $user_id);
        $this->capabilities->reset();
    }

    public function call($action, $parameters = array(), $extra = array())
    {
        $this->counter++;
        $body = $extra + array(
            'request_id' => 'nf_req_' . str_pad((string) $this->counter, 6, '0', STR_PAD_LEFT),
            'action' => $action,
            'parameters' => $parameters,
        );
        $this->last_request_id = isset($body['request_id']) && is_string($body['request_id']) ? $body['request_id'] : '';

        return $this->dispatcher->dispatch($body);
    }

    public function page($fields = array())
    {
        $id = wp_insert_post(
            $fields + array(
                'post_type' => 'page',
                'post_status' => 'draft',
                'post_title' => 'Garage Door Repair',
                'post_name' => 'garage-door-repair',
                'post_content' => '',
                'post_author' => 1,
            )
        );
        nexoflow_tick();

        return $id;
    }

    // Switches the site to a block theme whose homepage comes from a template file.
    public function block_theme($templates, $parts = array())
    {
        $GLOBALS['nexoflow_block_theme'] = true;
        $GLOBALS['nexoflow_theme_templates'] = $templates;
        $GLOBALS['nexoflow_theme_parts'] = $parts;
        $this->capabilities->reset();
    }

    public function template_posts($type = 'wp_template')
    {
        $ids = array();

        foreach ($GLOBALS['nexoflow_posts'] as $id => $post) {
            if (isset($post['post_type']) && $post['post_type'] === $type) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public function page_count()
    {
        $count = 0;

        foreach ($GLOBALS['nexoflow_posts'] as $post) {
            if (isset($post['post_type']) && $post['post_type'] === 'page') {
                $count++;
            }
        }

        return $count;
    }

    public function rows()
    {
        return $GLOBALS['wpdb']->get_results('SELECT * FROM wp_nexoflow_agent_requests ORDER BY id ASC', ARRAY_A);
    }
}

function nexoflow_code($result)
{
    if (!is_array($result) || !isset($result['body'])) {
        return 'NO_RESULT';
    }

    if (!empty($result['body']['success'])) {
        return 'OK';
    }

    return isset($result['body']['error']['code']) ? $result['body']['error']['code'] : 'NO_CODE';
}

function nexoflow_result($result)
{
    return isset($result['body']['result']) && is_array($result['body']['result']) ? $result['body']['result'] : array();
}

function nexoflow_details($result)
{
    return isset($result['body']['error']['details']) ? $result['body']['error']['details'] : array();
}

function nexoflow_section($title)
{
    echo "\n-- {$title}\n";
}
