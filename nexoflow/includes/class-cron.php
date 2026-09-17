<?php

if (!defined('ABSPATH')) {
    exit;
}

class NexoFlow_Cron
{
    const HOOK = 'nexoflow_sync_event';

    const MAX_ATTEMPTS = 5;

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

        if (!empty($outcome['ok'])) {
            unset($attempts[$job_id]);
            update_option('nexoflow_job_attempts', $attempts, false);
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

        $client->ack(
            $job_id,
            array(
                'ok' => false,
                'error' => $error,
            )
        );
    }
}
