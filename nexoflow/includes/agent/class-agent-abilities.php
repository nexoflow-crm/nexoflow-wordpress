<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The WordPress Abilities API (WordPress 6.9+). This is the supported way to call
 * what another plugin exposes, including what Elementor exposes to its MCP server.
 */
class NexoFlow_Agent_Abilities
{
    public function supported()
    {
        return function_exists('wp_has_ability') && function_exists('wp_get_ability') && function_exists('wp_get_abilities');
    }

    public function names($prefix)
    {
        if (!$this->loaded()) {
            return array();
        }

        $names = array();

        foreach (wp_get_abilities() as $name => $ability) {
            if (is_string($name) && strpos($name, $prefix) === 0) {
                $names[] = $name;
            }
        }

        sort($names);

        return $names;
    }

    public function has($name)
    {
        return $this->loaded() && wp_has_ability($name);
    }

    /**
     * Abilities validate their own input and check the current user themselves.
     *
     * @return mixed|WP_Error
     */
    public function execute($name, $input)
    {
        if (!$this->has($name)) {
            return new WP_Error('nexoflow_ability_missing', __('This ability is not available on this site.', 'nexoflow'));
        }

        $ability = wp_get_ability($name);

        if (!is_object($ability) || !method_exists($ability, 'execute')) {
            return new WP_Error('nexoflow_ability_missing', __('This ability is not available on this site.', 'nexoflow'));
        }

        return $ability->execute($input);
    }

    // The registry refuses to load before init.
    private function loaded()
    {
        return $this->supported() && did_action('init') > 0;
    }
}
