<?php

if (!defined('ABSPATH')) {
    exit;
}

class NexoFlow_Agent
{
    const API_VERSION = '1';

    const ENABLED_OPTION = 'nexoflow_agent_enabled';

    private $actor;

    private $site;

    private $store;

    private $documents;

    private $providers = null;

    private $builders = array();

    private $wordpress = null;

    private $capabilities = null;

    private $actions = null;

    private $dispatcher = null;

    public function __construct()
    {
        $this->actor = new NexoFlow_Agent_Actor();
        $this->site = new NexoFlow_Agent_Site();
        $this->store = new NexoFlow_Agent_Request_Store();
        $this->documents = new NexoFlow_Agent_Documents();
    }

    public function register($settings)
    {
        add_action('init', array($this->store, 'maybe_install'));
        add_action('nexoflow_site_key_saved', array($this->actor, 'set'));
        add_action('nexoflow_disconnected', array($this->actor, 'clear'));

        $rest = new NexoFlow_Agent_Rest($this);
        $rest->register();

        $admin = new NexoFlow_Agent_Admin($this, $settings);
        $admin->register();
    }

    public static function switched_on()
    {
        return (string) get_option(self::ENABLED_OPTION, '1') !== '0';
    }

    public static function enabled()
    {
        return self::switched_on() && (bool) apply_filters('nexoflow_agent_enabled', true);
    }

    public function actor()
    {
        return $this->actor;
    }

    public function site()
    {
        return $this->site;
    }

    public function store()
    {
        return $this->store;
    }

    public function providers()
    {
        if ($this->providers !== null) {
            return $this->providers;
        }

        $templates = new NexoFlow_Agent_Templates($this->documents);
        $parts = new NexoFlow_Agent_Templates($this->documents, NexoFlow_Agent_Templates::PART_TYPE);
        $this->wordpress = new NexoFlow_Agent_Provider_WordPress($this->documents, $this->site, $templates, $parts);
        $elementor = new NexoFlow_Agent_Provider_Elementor(new NexoFlow_Agent_Abilities(), $this->documents);
        $gutenberg = new NexoFlow_Agent_Provider_Gutenberg($this->documents, $templates, $parts);
        $media = new NexoFlow_Agent_Provider_Media();
        $seo = new NexoFlow_Agent_Provider_SEO($this->documents);
        $added = array();

        foreach ((array) apply_filters('nexoflow_agent_providers', array(), $this->documents) as $provider) {
            if ($provider instanceof NexoFlow_Agent_Provider) {
                $added[] = $provider;
            }
        }

        // Blocks are the fallback, so every other builder is asked first whether it owns a page.
        $builders = array($elementor);

        foreach ($added as $provider) {
            if ($provider instanceof NexoFlow_Agent_Builder) {
                $builders[] = $provider;
            }
        }

        $builders[] = $gutenberg;

        $this->builders = $builders;
        $this->documents->set_builders($builders);

        // Built-in providers come first, so an added provider cannot replace their actions.
        $this->providers = array_merge(array($this->wordpress, $gutenberg, $media, $elementor, $seo), $added);

        return $this->providers;
    }

    public function capabilities()
    {
        if ($this->capabilities === null) {
            $this->capabilities = new NexoFlow_Agent_Capability_Registry($this->providers(), $this->actor, $this->store);
        }

        return $this->capabilities;
    }

    public function actions()
    {
        if ($this->actions === null) {
            $this->actions = new NexoFlow_Agent_Action_Registry($this->providers());
        }

        return $this->actions;
    }

    public function dispatcher()
    {
        if ($this->dispatcher === null) {
            $this->dispatcher = new NexoFlow_Agent_Dispatcher(
                $this->actions(),
                $this->capabilities(),
                new NexoFlow_Agent_Validator(),
                $this->actor,
                $this->site,
                $this->store
            );
        }

        return $this->dispatcher;
    }

    public function builders()
    {
        $detected = array();

        foreach ($this->providers() as $provider) {
            if ($provider instanceof NexoFlow_Agent_Builder) {
                $detected[$provider->id()] = $provider->detect();
            }
        }

        return $detected;
    }

    /**
     * The answer to "what can this website do?".
     */
    public function discovery()
    {
        $this->providers();

        $capabilities = $this->capabilities();
        $context = $this->wordpress->site_context();
        $context['site']['connected'] = true;
        $actions = array();

        foreach ($this->actions()->describe() as $action) {
            $capability = $capabilities->get($action['capability']);
            $action['available'] = $capability !== null && $capability['usable'];
            $actions[] = $action;
        }

        return array(
            'success' => true,
            'api_version' => self::API_VERSION,
            'plugin' => array(
                'version' => NEXOFLOW_VERSION,
            ),
            'site' => $context['site'],
            'wordpress' => $context['wordpress'],
            'theme' => $context['theme'],
            'post_types' => $context['post_types'],
            'integrations' => $context['integrations'],
            'agent' => array(
                'enabled' => true,
                'default_mode' => NexoFlow_Agent_Validator::MODE_DRAFT,
                'modes' => array(NexoFlow_Agent_Validator::MODE_DRAFT, NexoFlow_Agent_Validator::MODE_LIVE),
                'live_edits' => $this->documents->live_edits(),
                'request_tracking' => $this->store->ready(),
                'replay_days' => NexoFlow_Agent_Request_Store::RETENTION_DAYS,
            ),
            'actor' => $this->actor->describe(),
            'builders' => $this->builders(),
            'capabilities' => $capabilities->usable(),
            'capability_details' => $capabilities->all(),
            'actions' => $actions,
        );
    }
}
