<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$nexoflow_options = array(
    'nexoflow_site_key',
    'nexoflow_site_connect_key',
    'nexoflow_api_base',
    'nexoflow_connected',
    'nexoflow_last_error',
    'nexoflow_last_sync',
    'nexoflow_project',
    'nexoflow_job_attempts',
    'nexoflow_structured_data',
    'nexoflow_llms_txt',
    'nexoflow_site_data_checked',
    'nexoflow_agent_enabled',
    'nexoflow_agent_user',
    'nexoflow_agent_site_secret',
    'nexoflow_agent_db_version',
    'nexoflow_agent_pruned_at',
    'nexoflow_seo_settings',
    'nexoflow_seo_redirects',
    'nexoflow_page_cache',
    'nexoflow_page_cache_generation',
    'nexoflow_page_cache_count',
    'nexoflow_indexnow_key',
    'nexoflow_bing_site_verification',
    'nexoflow_revoked_key',
    'nexoflow_sync_lock',
    'nexoflow_agent_user_admin',
    'nexoflow_author_id',
);

foreach ($nexoflow_options as $nexoflow_option) {
    delete_option($nexoflow_option);
}

wp_clear_scheduled_hook('nexoflow_page_cache_purge');
delete_transient('nexoflow_admin_notice');
delete_transient('nexoflow_agent_db_retry');

global $wpdb;

$nexoflow_agent_table = esc_sql($wpdb->prefix . 'nexoflow_agent_requests');

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Removes the plugin's own table on uninstall. Its name is the site prefix plus a constant, escaped with esc_sql().
$wpdb->query("DROP TABLE IF EXISTS {$nexoflow_agent_table}");

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Removes the plugin's own search fields on uninstall.
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like('_nexoflow_seo_') . '%'));

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Removes the plugin's own page copies on uninstall.
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like('_transient_nf_pc_') . '%', $wpdb->esc_like('_transient_timeout_nf_pc_') . '%'));

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Removes each administrator's last settings message on uninstall.
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like('_transient_nexoflow_admin_notice_') . '%', $wpdb->esc_like('_transient_timeout_nexoflow_admin_notice_') . '%'));
