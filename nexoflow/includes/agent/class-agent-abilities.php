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
     * Abilities validate their own input and check the current user themselves. They run as if the acting account
     * could not post raw HTML, so whatever they save goes through WordPress's own cleaning, whatever that account
     * may otherwise do.
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

        add_filter('user_has_cap', array(__CLASS__, 'without_unfiltered_html'), 99);
        add_filter('map_meta_cap', array(__CLASS__, 'never_unfiltered_html'), 99, 2);
        self::reset_kses();

        try {
            return $ability->execute($input);
        } finally {
            remove_filter('user_has_cap', array(__CLASS__, 'without_unfiltered_html'), 99);
            remove_filter('map_meta_cap', array(__CLASS__, 'never_unfiltered_html'), 99);
            self::reset_kses();
        }
    }

    /** map_meta_cap: a network super admin skips user_has_cap, but not a capability mapped to do_not_allow. */
    public static function never_unfiltered_html($caps, $cap = '')
    {
        return $cap === 'unfiltered_html' ? array('do_not_allow') : $caps;
    }

    /** user_has_cap: no raw HTML while an ability runs. */
    public static function without_unfiltered_html($allcaps)
    {
        if (is_array($allcaps)) {
            $allcaps['unfiltered_html'] = false;
        }

        return $allcaps;
    }

    // WordPress sets its save-time cleaning when the user is set; it is set again for what the user may now do.
    private static function reset_kses()
    {
        if (function_exists('kses_init')) {
            kses_init();
        }
    }

    // The registry refuses to load before init.
    private function loaded()
    {
        return $this->supported() && did_action('init') > 0;
    }
}
