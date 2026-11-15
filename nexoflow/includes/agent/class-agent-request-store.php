<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One row per action request: the audit trail, and the record that stops a
 * retried request from running twice.
 *
 * claim holds the request_id while a request is running and after it succeeds.
 * It is unique, so only one attempt can hold it. Failed and refused requests
 * keep their row with claim cleared, which frees the request_id for a retry.
 */
class NexoFlow_Agent_Request_Store
{
    const DB_VERSION = '1';

    const VERSION_OPTION = 'nexoflow_agent_db_version';

    const PRUNED_OPTION = 'nexoflow_agent_pruned_at';

    const RETRY_TRANSIENT = 'nexoflow_agent_db_retry';

    const RETENTION_DAYS = 30;

    const IN_PROGRESS = 'in_progress';

    const COMPLETED = 'completed';

    const FAILED = 'failed';

    const ABANDONED = 'abandoned';

    const REJECTED = 'rejected';

    public static function table()
    {
        global $wpdb;

        return $wpdb->prefix . 'nexoflow_agent_requests';
    }

    public function ready()
    {
        return (string) get_option(self::VERSION_OPTION, '') === self::DB_VERSION;
    }

    public function maybe_install()
    {
        if ($this->ready() || get_transient(self::RETRY_TRANSIENT)) {
            return;
        }

        $this->install();
    }

    public function install()
    {
        global $wpdb;

        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $table = self::table();
        $charset = $wpdb->get_charset_collate();

        dbDelta(
            "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  request_id varchar(128) NOT NULL DEFAULT '',
  claim varchar(128) DEFAULT NULL,
  authorization_id varchar(128) NOT NULL DEFAULT '',
  action varchar(64) NOT NULL DEFAULT '',
  fingerprint varchar(64) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT '',
  error_code varchar(64) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  response longtext,
  created_at datetime NOT NULL,
  completed_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY claim (claim),
  KEY request_id (request_id),
  KEY created_at (created_at)
) {$charset};"
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Confirms the table exists after dbDelta.
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));

        if (is_string($found) && strtolower($found) === strtolower($table)) {
            update_option(self::VERSION_OPTION, self::DB_VERSION, true);

            return true;
        }

        set_transient(self::RETRY_TRANSIENT, 1, HOUR_IN_SECONDS);

        return false;
    }

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- The only name written into a query is the plugin's own table, the site prefix plus a constant, escaped with esc_sql(); every value goes through $wpdb->prepare(). Rows must never be read from cache.

    public function find_claim($request_id)
    {
        global $wpdb;

        // WordPress has no placeholder for a table name before 6.2, so the name is escaped where it is used.
        $table = esc_sql(self::table());
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE claim = %s", $request_id), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /**
     * @return bool False when another attempt already holds this request_id.
     */
    public function claim($record)
    {
        global $wpdb;

        $suppress = $wpdb->suppress_errors(true);
        $inserted = $wpdb->insert(
            self::table(),
            array(
                'request_id' => $record['request_id'],
                'claim' => $record['request_id'],
                'authorization_id' => $record['authorization_id'],
                'action' => $record['action'],
                'fingerprint' => $record['fingerprint'],
                'status' => self::IN_PROGRESS,
                'user_id' => (int) $record['user_id'],
                'created_at' => gmdate('Y-m-d H:i:s'),
            )
        );
        $wpdb->suppress_errors($suppress);

        return $inserted === 1;
    }

    public function complete($request_id, $response, $object_id)
    {
        global $wpdb;

        $wpdb->update(
            self::table(),
            array(
                'status' => self::COMPLETED,
                'response' => wp_json_encode($response),
                'object_id' => (int) $object_id,
                'completed_at' => gmdate('Y-m-d H:i:s'),
            ),
            array('claim' => $request_id)
        );
    }

    public function fail($request_id, $error_code)
    {
        global $wpdb;

        $wpdb->update(
            self::table(),
            array(
                'status' => self::FAILED,
                'claim' => null,
                'error_code' => (string) $error_code,
                'completed_at' => gmdate('Y-m-d H:i:s'),
            ),
            array('claim' => $request_id)
        );
    }

    // Keeps the claim, so the request_id can never run again.
    public function abandon($request_id, $error_code)
    {
        global $wpdb;

        $wpdb->update(
            self::table(),
            array(
                'status' => self::ABANDONED,
                'error_code' => (string) $error_code,
                'completed_at' => gmdate('Y-m-d H:i:s'),
            ),
            array('claim' => $request_id)
        );
    }

    public function reject($record, $error_code)
    {
        global $wpdb;

        if (!$this->ready()) {
            return;
        }

        $now = gmdate('Y-m-d H:i:s');
        $suppress = $wpdb->suppress_errors(true);
        $wpdb->insert(
            self::table(),
            array(
                'request_id' => $record['request_id'],
                'authorization_id' => $record['authorization_id'],
                'action' => $record['action'],
                'fingerprint' => $record['fingerprint'],
                'status' => self::REJECTED,
                'error_code' => (string) $error_code,
                'user_id' => (int) $record['user_id'],
                'created_at' => $now,
                'completed_at' => $now,
            )
        );
        $wpdb->suppress_errors($suppress);
    }

    public function recent($limit)
    {
        global $wpdb;

        if (!$this->ready()) {
            return array();
        }

        $table = esc_sql(self::table());
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT request_id, action, status, error_code, object_id, created_at, completed_at FROM {$table} ORDER BY id DESC LIMIT %d",
                max(1, (int) $limit)
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : array();
    }

    public function prune()
    {
        global $wpdb;

        if (!$this->ready() || time() - (int) get_option(self::PRUNED_OPTION, 0) < DAY_IN_SECONDS) {
            return;
        }

        update_option(self::PRUNED_OPTION, time(), false);

        $table = esc_sql(self::table());
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS);

        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE created_at < %s", $cutoff));
    }

    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
}
