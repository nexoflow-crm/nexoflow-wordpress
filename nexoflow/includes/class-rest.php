<?php

if (!defined('ABSPATH')) {
    exit;
}

class NexoFlow_Rest
{
    const NS = 'nexoflow/v1';

    public function register()
    {
        add_action('rest_api_init', array($this, 'routes'));
    }

    public function routes()
    {
        register_rest_route(
            self::NS,
            '/sync',
            array(
                'methods' => 'POST',
                'callback' => array($this, 'sync'),
                'permission_callback' => array($this, 'can_sync'),
            )
        );
    }

    public function can_sync()
    {
        if (!NexoFlow_Client::has_site_key()) {
            return new WP_Error(
                'nexoflow_rest',
                __('Site is not connected.', 'nexoflow'),
                array('status' => 401)
            );
        }

        $token = NexoFlow_Client::request_bearer_token();
        $key = NexoFlow_Client::site_key();

        if ($token === '' || !hash_equals($key, $token)) {
            return new WP_Error(
                'nexoflow_rest',
                __('Invalid site connect key.', 'nexoflow'),
                array('status' => 401)
            );
        }

        return true;
    }

    public function sync()
    {
        $cron = new NexoFlow_Cron();
        $result = $cron->sync();

        if (is_wp_error($result)) {
            $result->add_data(array('status' => 400));

            return $result;
        }

        return rest_ensure_response($result);
    }
}
