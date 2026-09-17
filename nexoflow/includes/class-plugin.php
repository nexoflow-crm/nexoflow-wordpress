<?php

if (!defined('ABSPATH')) {
    exit;
}

class NexoFlow_Plugin
{
    private static $instance = null;

    private $cron;

    private $settings;

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

        add_action('plugins_loaded', array($this, 'init'));
        register_activation_hook(NEXOFLOW_FILE, array($this, 'activate'));
        register_deactivation_hook(NEXOFLOW_FILE, array($this, 'deactivate'));
    }

    public function init()
    {
        $this->cron->register();
        $this->settings->register();
    }

    public function activate()
    {
        $current_base = get_option('nexoflow_api_base', '');

        if ($current_base === '' || $current_base === NexoFlow_Client::LEGACY_API_BASE) {
            update_option('nexoflow_api_base', NEXOFLOW_DEFAULT_API_BASE, false);
        }

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
}
