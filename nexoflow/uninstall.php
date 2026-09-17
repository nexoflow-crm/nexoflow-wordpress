<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$nexoflow_options = array(
    'nexoflow_site_key',
    'nexoflow_api_base',
    'nexoflow_connected',
    'nexoflow_last_error',
    'nexoflow_last_sync',
    'nexoflow_project',
    'nexoflow_job_attempts',
);

foreach ($nexoflow_options as $nexoflow_option) {
    delete_option($nexoflow_option);
}

delete_transient('nexoflow_admin_notice');
