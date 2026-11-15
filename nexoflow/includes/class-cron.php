<?php

if (!defined('ABSPATH')) {
    exit;
}

class NexoFlow_Cron
{
    const HOOK = 'nexoflow_sync_event';

    /** The event the plugin from NexoFlow's own download (1.0.0 to 1.2.5) scheduled. Nothing answers it any more. */
    const LEGACY_HOOK = 'nexoflow_sync_jobs';

    const MAX_ATTEMPTS = 5;

    /** One sync at a time: the scheduler, the REST call and Sync now never apply the same job twice. */
    const LOCK_OPTION = 'nexoflow_sync_lock';

    const LOCK_SECONDS = 300;

    public function register()
    {
        add_filter('cron_schedules', array($this, 'schedules'));
        add_action(self::HOOK, array($this, 'run'));
        add_action('init', array($this, 'ensure_scheduled'));
    }

    public function schedules($schedules)
    {
        if (!is_array($schedules)) {
            $schedules = array();
        }

        $schedules['nexoflow_minute'] = array(
            'interval' => 60,
            'display' => __('Every minute', 'nexoflow'),
        );

        return $schedules;
    }

    public function activate()
    {
        add_filter('cron_schedules', array($this, 'schedules'));
        $this->ensure_scheduled();
    }

    public function ensure_scheduled()
    {
        // A site updated from NexoFlow's own download still has its old once-a-minute event; it is cleared once.
        if (wp_next_scheduled(self::LEGACY_HOOK)) {
            wp_clear_scheduled_hook(self::LEGACY_HOOK);
        }

        if (wp_next_scheduled(self::HOOK)) {
            return;
        }

        wp_schedule_event(time() + 60, 'nexoflow_minute', self::HOOK);
    }

    public function deactivate()
    {
        $timestamp = wp_next_scheduled(self::HOOK);

        if ($timestamp) {
            wp_unschedule_event($timestamp, self::HOOK);
        }
    }

    public function run()
    {
        return $this->sync();
    }

    public function sync()
    {
        $token = $this->lock();

        if ($token === '') {
            return array(
                'ok' => true,
                'processed' => 0,
                'busy' => true,
            );
        }

        try {
            return $this->sync_now();
        } finally {
            $this->unlock($token);
        }
    }

    /**
     * Takes the sync lock and returns its token, or '' when another run holds it. The lock is "expiry|token", so
     * only the run that took it lets it go, and one left by a run that died runs out.
     */
    private function lock()
    {
        global $wpdb;

        $now = time();
        $token = ($now + self::LOCK_SECONDS) . '|' . wp_generate_password(16, false);

        if (self::database_lock_available()) {
            // One statement each: of two runs at the same moment, only one adds the row or takes over a stale one.
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- An atomic lock on the plugin's own option.
            $added = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK_OPTION, $token));

            if ((int) $added !== 1) {
                $added = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) < %d", $token, self::LOCK_OPTION, $now));
            }
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

            self::forget_cached_lock();

            return (int) $added === 1 ? $token : '';
        }

        if (add_option(self::LOCK_OPTION, $token, '', false)) {
            return $token;
        }

        if ((int) get_option(self::LOCK_OPTION, 0) > $now) {
            return '';
        }

        // A run that died without letting go: its lock has run out.
        delete_option(self::LOCK_OPTION);

        return add_option(self::LOCK_OPTION, $token, '', false) ? $token : '';
    }

    private function unlock($token)
    {
        global $wpdb;

        if (self::database_lock_available()) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Only this run's own lock is removed.
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK_OPTION, $token));
            self::forget_cached_lock();

            return;
        }

        if (get_option(self::LOCK_OPTION, '') === $token) {
            delete_option(self::LOCK_OPTION);
        }
    }

    private static function database_lock_available()
    {
        global $wpdb;

        return isset($wpdb) && is_object($wpdb) && !empty($wpdb->options) && method_exists($wpdb, 'prepare') && method_exists($wpdb, 'query');
    }

    private static function forget_cached_lock()
    {
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete(self::LOCK_OPTION, 'options');
            wp_cache_delete('notoptions', 'options');
        }
    }

    private function sync_now()
    {
        $client = new NexoFlow_Client();
        $result = $client->get_jobs();

        if (is_wp_error($result)) {
            update_option('nexoflow_last_error', $result->get_error_message(), false);
            update_option('nexoflow_connected', 0, false);
            update_option('nexoflow_project', array(), false);

            return $result;
        }

        update_option('nexoflow_last_error', '', false);
        update_option('nexoflow_connected', 1, false);

        // The key the site serves as /<key>.txt for change notices to search engines.
        NexoFlow_IndexNow::keep_key($result);
        // Bing's code for the site, printed as Bing's verification tag on the homepage.
        NexoFlow_Bing::keep_code($result);

        $jobs = isset($result['jobs']) && is_array($result['jobs']) ? $result['jobs'] : array();
        $publisher = new NexoFlow_Publisher();
        $processed = 0;

        foreach ($jobs as $job) {
            if (!is_array($job)) {
                continue;
            }

            $this->handle_job($client, $publisher, $job);
            $processed++;
        }

        update_option('nexoflow_last_sync', time(), false);

        return array(
            'ok' => true,
            'processed' => $processed,
        );
    }

    private function handle_job($client, $publisher, $job)
    {
        $job_id = isset($job['id']) && is_string($job['id']) ? $job['id'] : '';

        if ($job_id === '') {
            return;
        }

        $attempts = get_option('nexoflow_job_attempts', array());

        if (!is_array($attempts)) {
            $attempts = array();
        }

        $count = isset($attempts[$job_id]) ? (int) $attempts[$job_id] : 0;
        $count++;
        $attempts[$job_id] = $count;
        update_option('nexoflow_job_attempts', $attempts, false);

        $outcome = $publisher->process_job($job);

        if (!is_array($outcome)) {
            $outcome = array('ok' => false, 'error' => 'Invalid job payload.');
        }

        // Which copy of the post this was, so an older copy never marks a newer one as done.
        $version = isset($job['version']) && is_string($job['version']) ? trim($job['version']) : '';

        if (!empty($outcome['ok'])) {
            unset($attempts[$job_id]);
            update_option('nexoflow_job_attempts', $attempts, false);

            if ($version !== '') {
                $outcome['version'] = $version;
            }

            $client->ack($job_id, $outcome);

            return;
        }

        if ($count < self::MAX_ATTEMPTS) {
            return;
        }

        unset($attempts[$job_id]);
        update_option('nexoflow_job_attempts', $attempts, false);

        $error = isset($outcome['error']) && is_string($outcome['error']) && $outcome['error'] !== ''
            ? $outcome['error']
            : 'The job failed after several attempts.';

        $failed = array(
            'ok' => false,
            'error' => $error,
        );

        if ($version !== '') {
            $failed['version'] = $version;
        }

        $client->ack($job_id, $failed);
    }
}
