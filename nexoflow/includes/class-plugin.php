<?php

if (!defined('ABSPATH')) {
    exit;
}

class NexoFlow_Plugin
{
    private static $instance = null;

    private $cron;

    private $settings;

    private $rest;

    private $agent;

    private $site_data;

    private $indexnow;

    private $bing;

    private $seo;

    private $page_cache;

    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        $this->cron = new NexoFlow_Cron();
        $this->settings = new NexoFlow_Settings();
        $this->rest = new NexoFlow_Rest();
        $this->agent = new NexoFlow_Agent();
        $this->site_data = new NexoFlow_Site_Data();
        $this->indexnow = new NexoFlow_IndexNow();
        $this->bing = new NexoFlow_Bing();
        $this->seo = new NexoFlow_SEO();
        $this->page_cache = new NexoFlow_Page_Cache();

        add_action('plugins_loaded', array($this, 'init'));
        register_activation_hook(NEXOFLOW_FILE, array($this, 'activate'));
        register_deactivation_hook(NEXOFLOW_FILE, array($this, 'deactivate'));
    }

    public function init()
    {
        // First: a page it has a copy of is answered from here, with nothing else started.
        $this->page_cache->register();
        $this->cron->register();
        $this->settings->register();
        $this->rest->register();
        $this->agent->register($this->settings);
        $this->site_data->register();
        $this->indexnow->register();
        $this->bing->register();
        $this->seo->register();
    }

    public function activate()
    {
        update_option('nexoflow_api_base', NEXOFLOW_DEFAULT_API_BASE, false);

        $this->cron->activate();
    }

    public function deactivate()
    {
        $this->cron->deactivate();
    }

    public function cron()
    {
        return $this->cron;
    }

    public function agent()
    {
        return $this->agent;
    }

    public function site_data()
    {
        return $this->site_data;
    }
}
