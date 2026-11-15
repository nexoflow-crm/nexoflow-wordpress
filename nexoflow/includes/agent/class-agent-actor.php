<?php

if (!defined('ABSPATH')) {
    exit;
}

class NexoFlow_Agent_Actor
{
    const OPTION = 'nexoflow_agent_user';

    const PERMISSIONS = array(
        'edit_pages',
        'edit_others_pages',
        'edit_published_pages',
        'publish_pages',
        'delete_pages',
        'edit_posts',
        'publish_posts',
        'upload_files',
        'edit_theme_options',
        'manage_options',
    );

    public function id()
    {
        $stored = (int) get_option(self::OPTION, 0);

        if ($stored > 0 && get_userdata($stored)) {
            return $stored;
        }

        if (!NexoFlow_Client::has_site_key()) {
            return 0;
        }

        // Sites connected before the Website Agent existed have no recorded account.
        $fallback = $this->first_administrator();

        if ($fallback > 0) {
            update_option(self::OPTION, $fallback, false);
        }

        return $fallback;
    }

    public function set($user_id)
    {
        $user_id = (int) $user_id;

        if ($user_id <= 0 || !get_userdata($user_id)) {
            return false;
        }

        update_option(self::OPTION, $user_id, false);

        return true;
    }

    public function clear()
    {
        delete_option(self::OPTION);
    }

    public function can($capability)
    {
        $user_id = $this->id();

        return $user_id > 0 && user_can($user_id, $capability);
    }

    public function describe()
    {
        $user_id = $this->id();
        $user = $user_id > 0 ? get_userdata($user_id) : false;

        if (!$user) {
            return null;
        }

        $permissions = array();

        foreach (self::PERMISSIONS as $capability) {
            $permissions[$capability] = (bool) user_can($user_id, $capability);
        }

        return array(
            'id' => $user_id,
            'roles' => isset($user->roles) && is_array($user->roles) ? array_values($user->roles) : array(),
            'permissions' => $permissions,
        );
    }

    public function run_as($user_id, $callback)
    {
        $previous = (int) get_current_user_id();

        wp_set_current_user((int) $user_id);

        try {
            return call_user_func($callback);
        } finally {
            wp_set_current_user($previous);
        }
    }

    private function first_administrator()
    {
        $ids = get_users(
            array(
                'role' => 'administrator',
                'orderby' => 'ID',
                'order' => 'ASC',
                'number' => 1,
                'fields' => 'ID',
            )
        );

        if (!is_array($ids) || empty($ids)) {
            return 0;
        }

        return (int) $ids[0];
    }
}
