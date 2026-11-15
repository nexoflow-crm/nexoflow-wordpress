<?php

if (!defined('ABSPATH')) {
    exit;
}

class NexoFlow_Settings
{
    const PAGE = 'nexoflow';

    const MAX_KEY_LENGTH = 53;

    private $pending = null;

    public function register()
    {
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_enqueue_scripts', array($this, 'menu_assets'));
        add_action('admin_enqueue_scripts', array($this, 'assets'));
        add_action('admin_post_nexoflow_save_settings', array($this, 'save'));
        add_action('admin_post_nexoflow_test_connection', array($this, 'test_connection'));
        add_action('admin_post_nexoflow_sync_now', array($this, 'sync_now'));
        add_action('admin_post_nexoflow_disconnect', array($this, 'disconnect'));
    }

    public function menu()
    {
        add_menu_page(
            __('NexoFlow', 'nexoflow'),
            __('NexoFlow', 'nexoflow'),
            'manage_options',
            self::PAGE,
            array($this, 'render'),
            NEXOFLOW_URL . 'assets/nexoflow-menu-icon.png?ver=' . self::asset_version('assets/nexoflow-menu-icon.png'),
            58
        );
    }

    /**
     * The plugin version plus the file's own change time, so a browser never keeps showing an older copy of a
     * file that changed under the same plugin version (a zip uploaded again, a site updated in place).
     */
    public static function asset_version($relative_path)
    {
        $path = NEXOFLOW_DIR . $relative_path;
        $changed = is_readable($path) ? (int) filemtime($path) : 0;

        return $changed > 0 ? NEXOFLOW_VERSION . '.' . $changed : NEXOFLOW_VERSION;
    }

    public function menu_assets()
    {
        wp_enqueue_style(
            'nexoflow-admin-menu',
            NEXOFLOW_URL . 'assets/admin-menu.css',
            array(),
            self::asset_version('assets/admin-menu.css')
        );
    }

    public function assets($hook)
    {
        if ($hook !== 'toplevel_page_nexoflow') {
            return;
        }

        wp_enqueue_style('dashicons');
        wp_enqueue_style(
            'nexoflow-admin',
            NEXOFLOW_URL . 'assets/admin.css',
            array('dashicons', 'buttons'),
            self::asset_version('assets/admin.css')
        );

        wp_enqueue_script(
            'nexoflow-admin',
            NEXOFLOW_URL . 'assets/admin.js',
            array(),
            self::asset_version('assets/admin.js'),
            true
        );
    }

    public function render()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $has_key = NexoFlow_Client::has_site_key();
        $suffix = NexoFlow_Client::site_key_suffix();
        $connected = (int) get_option('nexoflow_connected', 0) === 1;
        $project = $connected ? NexoFlow_Client::stored_project() : array();
        $last_sync = (int) get_option('nexoflow_last_sync', 0);
        $last_error = (string) get_option('nexoflow_last_error', '');
        $site_data = NexoFlow_Site_Data::summary();
        $save_url = admin_url('admin-post.php');
        $has_side = has_action('nexoflow_settings_side') !== false;
        $this->pending = $this->take_notice();
        ?>
        <div class="wrap nexoflow-wrap<?php echo $has_side ? ' has-side' : ''; ?>">
            <h1><?php echo esc_html__('NexoFlow', 'nexoflow'); ?></h1>
            <p class="nexoflow-lead"><?php echo esc_html__('Publish NexoFlow articles to this WordPress site.', 'nexoflow'); ?></p>

            <div class="nexoflow-layout">
            <div class="nexoflow-main">
            <div class="nexoflow-card" id="nexoflow-card-connection">
                <h2 class="nexoflow-card-title">
                    <span class="dashicons dashicons-admin-links" aria-hidden="true"></span>
                    <?php echo esc_html__('Connection', 'nexoflow'); ?>
                </h2>
                <form method="post" action="<?php echo esc_url($save_url); ?>" class="nexoflow-form">
                    <?php wp_nonce_field('nexoflow_save_settings', 'nexoflow_nonce'); ?>
                    <input type="hidden" name="action" value="nexoflow_save_settings" />
                    <p class="nexoflow-field">
                        <label for="nexoflow_site_key"><?php echo esc_html__('Site connect key', 'nexoflow'); ?></label>
                        <span class="nexoflow-key-row">
                            <input
                                type="password"
                                class="regular-text nexoflow-key-input"
                                id="nexoflow_site_key"
                                name="nexoflow_site_key"
                                value=""
                                autocomplete="new-password"
                                maxlength="<?php echo esc_attr((string) self::MAX_KEY_LENGTH); ?>"
                                placeholder="<?php echo esc_attr__('Paste your site connect key', 'nexoflow'); ?>"
                            />
                            <button type="submit" class="button button-primary nexoflow-button-icon">
                                <span class="dashicons dashicons-saved" aria-hidden="true"></span>
                                <?php echo esc_html__('Save', 'nexoflow'); ?>
                            </button>
                            <?php if ($has_key) : ?>
                                <?php // Sits beside Save, but belongs to the disconnect form below. ?>
                                <button type="submit" form="nexoflow-disconnect-form" class="button button-secondary nexoflow-button-icon">
                                    <span class="dashicons dashicons-no" aria-hidden="true"></span>
                                    <?php echo esc_html__('Disconnect', 'nexoflow'); ?>
                                </button>
                            <?php endif; ?>
                        </span>
                    </p>
                    <?php if ($has_key && $suffix !== '') : ?>
                        <p class="nexoflow-key-hint"><?php
                            /* translators: %s: last four characters of the saved site connect key */
                            echo esc_html(sprintf(__('Saved key ending in %s', 'nexoflow'), $suffix));
                        ?></p>
                    <?php endif; ?>
                </form>
                <?php $this->inline_notice('connection'); ?>
                <?php if ($has_key) : ?>
                    <form method="post" action="<?php echo esc_url($save_url); ?>" id="nexoflow-disconnect-form" class="nexoflow-disconnect" hidden>
                        <?php wp_nonce_field('nexoflow_disconnect', 'nexoflow_nonce'); ?>
                        <input type="hidden" name="action" value="nexoflow_disconnect" />
                    </form>
                <?php endif; ?>
            </div>

            <div class="nexoflow-card" id="nexoflow-card-status">
                <h2 class="nexoflow-card-title">
                    <span class="dashicons dashicons-info" aria-hidden="true"></span>
                    <?php echo esc_html__('Status', 'nexoflow'); ?>
                </h2>
                <dl class="nexoflow-status">
                    <div>
                        <dt><?php echo esc_html__('Connected', 'nexoflow'); ?></dt>
                        <dd>
                            <span class="nexoflow-pill <?php echo $connected ? 'is-yes' : 'is-no'; ?>">
                                <?php echo $connected ? esc_html__('Yes', 'nexoflow') : esc_html__('No', 'nexoflow'); ?>
                            </span>
                        </dd>
                    </div>
                    <?php
                    echo wp_kses(
                        $this->project_status_rows($project),
                        array(
                            'div' => array(),
                            'dt' => array(),
                            'dd' => array(),
                            'a' => array(
                                'href' => true,
                                'target' => true,
                                'rel' => true,
                            ),
                        )
                    );
                    ?>
                    <div>
                        <dt><?php echo esc_html__('Last sync', 'nexoflow'); ?></dt>
                        <dd><?php echo esc_html($this->format_sync_time($last_sync)); ?></dd>
                    </div>
                    <?php if ($connected) : ?>
                        <div>
                            <dt><?php echo esc_html__('Structured data', 'nexoflow'); ?></dt>
                            <dd><?php echo esc_html($this->structured_data_status($site_data)); ?></dd>
                        </div>
                        <div>
                            <dt><?php echo esc_html__('llms.txt', 'nexoflow'); ?></dt>
                            <dd><?php
                            if ($site_data['llms']) {
                                echo '<a href="' . esc_url(home_url('/llms.txt')) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Served at /llms.txt', 'nexoflow') . '</a>';
                            } else {
                                echo esc_html__('Not on this site yet', 'nexoflow');
                            }
                            ?></dd>
                        </div>
                    <?php endif; ?>
                    <div>
                        <dt><?php echo esc_html__('Last error', 'nexoflow'); ?></dt>
                        <dd><?php echo $last_error !== '' ? esc_html($last_error) : esc_html__('None', 'nexoflow'); ?></dd>
                    </div>
                </dl>
                <?php $this->inline_notice('status'); ?>
                <div class="nexoflow-actions">
                    <form method="post" action="<?php echo esc_url($save_url); ?>">
                        <?php wp_nonce_field('nexoflow_test_connection', 'nexoflow_nonce'); ?>
                        <input type="hidden" name="action" value="nexoflow_test_connection" />
                        <button type="submit" class="button button-secondary nexoflow-button-icon" <?php disabled(!$has_key); ?>>
                            <span class="dashicons dashicons-plugins-checked" aria-hidden="true"></span>
                            <?php echo esc_html__('Test connection', 'nexoflow'); ?>
                        </button>
                    </form>
                    <form method="post" action="<?php echo esc_url($save_url); ?>">
                        <?php wp_nonce_field('nexoflow_sync_now', 'nexoflow_nonce'); ?>
                        <input type="hidden" name="action" value="nexoflow_sync_now" />
                        <button type="submit" class="button button-secondary nexoflow-button-icon" <?php disabled(!$has_key); ?>>
                            <span class="dashicons dashicons-update" aria-hidden="true"></span>
                            <?php echo esc_html__('Sync now', 'nexoflow'); ?>
                        </button>
                    </form>
                </div>
            </div>

            <?php do_action('nexoflow_settings_cards'); ?>

            <?php $this->inline_notice(''); ?>
            <p class="nexoflow-help"><?php echo esc_html__('Create a WordPress project in NexoFlow, copy the site connect key, paste it here. WordPress pulls new jobs every minute. Sync now is only needed if you want to pull immediately.', 'nexoflow'); ?></p>
            </div>
            <?php if ($has_side) : ?>
                <div class="nexoflow-side">
                    <?php do_action('nexoflow_settings_side'); ?>
                </div>
            <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Prints the message from the last action inside the card it belongs to, next
     * to the button that was pressed. With an empty name it prints a message no
     * card has shown yet, so one is never lost.
     */
    public function inline_notice($where)
    {
        $notice = $this->pending;

        if (!is_array($notice) || ($where !== '' && $notice['where'] !== $where)) {
            return;
        }

        $this->pending = null;
        $error = $notice['type'] === 'error';

        echo '<div id="nexoflow-notice" class="nexoflow-notice ' . ($error ? 'is-error' : 'is-success') . '" role="' . ($error ? 'alert' : 'status') . '" tabindex="-1">'
            . '<span class="dashicons ' . ($error ? 'dashicons-warning' : 'dashicons-yes-alt') . '" aria-hidden="true"></span>'
            . '<p>' . esc_html($notice['message']) . '</p></div>';
    }

    private function take_notice()
    {
        $notice = get_transient('nexoflow_admin_notice');

        if (!is_array($notice) || !isset($notice['message']) || !is_string($notice['message']) || $notice['message'] === '') {
            return null;
        }

        delete_transient('nexoflow_admin_notice');

        return array(
            'type' => isset($notice['type']) && $notice['type'] === 'error' ? 'error' : 'success',
            'message' => $notice['message'],
            'where' => isset($notice['where']) && is_string($notice['where']) ? sanitize_key($notice['where']) : '',
        );
    }

    public function save()
    {
        $this->guard('nexoflow_save_settings');

        $raw = '';
        $posted = filter_input(INPUT_POST, 'nexoflow_site_key', FILTER_UNSAFE_RAW);

        if (is_string($posted)) {
            $raw = sanitize_text_field(wp_unslash($posted));
        }

        if ($raw !== '') {
            if (!NexoFlow_Client::is_valid_site_key($raw)) {
                $message = NexoFlow_Client::site_key_error($raw);
                $this->store_notice('error', $message, 'connection');

                if (!NexoFlow_Client::has_site_key()) {
                    update_option('nexoflow_connected', 0, false);
                    update_option('nexoflow_last_error', $message, false);
                    update_option('nexoflow_project', array(), false);
                }

                $this->redirect('invalid_key', 'connection');
            }

            update_option('nexoflow_site_key', $raw, false);
            do_action('nexoflow_site_key_saved', get_current_user_id());
        }

        if (!NexoFlow_Client::has_site_key()) {
            $this->store_notice('error', NexoFlow_Client::site_key_error(''), 'connection');
            $this->redirect('missing_key', 'connection');
        }

        $this->probe_connection('saved_ok', 'saved_fail', 'connection');
    }

    public function test_connection()
    {
        $this->guard('nexoflow_test_connection');
        $this->probe_connection('tested_ok', 'tested_fail', 'status');
    }

    public function disconnect()
    {
        $this->guard('nexoflow_disconnect');

        delete_option('nexoflow_site_key');
        delete_option(NexoFlow_Client::LEGACY_KEY_OPTION);
        delete_option('nexoflow_connected');
        delete_option('nexoflow_last_error');
        delete_option('nexoflow_last_sync');
        delete_option('nexoflow_project');
        delete_option('nexoflow_job_attempts');
        do_action('nexoflow_disconnected');

        $this->store_notice('success', __('Disconnected. Posts already on this site were left in place.', 'nexoflow'), 'connection');
        $this->redirect('disconnected', 'connection');
    }

    public function sync_now()
    {
        $this->guard('nexoflow_sync_now');

        $cron = new NexoFlow_Cron();
        $result = $cron->sync();

        if (is_wp_error($result)) {
            $this->store_notice('error', $result->get_error_message(), 'status');
            $this->redirect('sync_fail', 'status');
        }

        // Asked for by hand: the structured data and llms.txt are fetched now too.
        $site_data = new NexoFlow_Site_Data();
        $site_data->refresh(true);

        $this->store_notice('success', __('Sync finished.', 'nexoflow'), 'status');
        $this->redirect('synced', 'status');
    }

    private function probe_connection($ok_notice, $fail_notice, $where)
    {
        $client = new NexoFlow_Client();
        $result = $client->health();

        if (is_wp_error($result) || !is_array($result) || empty($result['ok'])) {
            $message = is_wp_error($result)
                ? $result->get_error_message()
                : __('Could not reach NexoFlow.', 'nexoflow');
            update_option('nexoflow_connected', 0, false);
            update_option('nexoflow_last_error', $message, false);
            update_option('nexoflow_project', array(), false);
            $this->store_notice('error', $message, $where);
            $this->redirect($fail_notice, $where);
        }

        update_option('nexoflow_connected', 1, false);
        update_option('nexoflow_last_error', '', false);
        update_option('nexoflow_project', NexoFlow_Client::project_from_health($result), false);
        $this->store_notice('success', __('Connected', 'nexoflow'), $where);
        $this->redirect($ok_notice, $where);
    }

    private function project_status_rows($project)
    {
        if (!is_array($project) || $project === array()) {
            return '';
        }

        $html = '';
        $name = isset($project['name']) ? $project['name'] : '';
        $website = isset($project['websiteUrl']) ? $project['websiteUrl'] : '';
        $wordpress = isset($project['wordpressUrl']) ? $project['wordpressUrl'] : '';

        if ($name !== '') {
            $html .= $this->status_row(__('Project', 'nexoflow'), esc_html($name));
        }

        if ($website !== '') {
            $html .= $this->status_row(__('Website', 'nexoflow'), $this->project_link($website));
        }

        if ($wordpress !== '' && $wordpress !== $website) {
            $html .= $this->status_row(__('WordPress', 'nexoflow'), $this->project_link($wordpress));
        }

        return $html;
    }

    private function structured_data_status($site_data)
    {
        $pages = isset($site_data['pages']) ? (int) $site_data['pages'] : 0;

        if ($pages <= 0) {
            return __('Not on this site yet', 'nexoflow');
        }

        /* translators: %d: number of pages */
        return sprintf(_n('On %d page', 'On %d pages', $pages, 'nexoflow'), $pages);
    }

    private function status_row($label, $value_html)
    {
        return '<div><dt>' . esc_html($label) . '</dt><dd>' . $value_html . '</dd></div>';
    }

    private function project_link($url)
    {
        $host = wp_parse_url($url, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            $host = $url;
        }

        return '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($host) . '</a>';
    }

    public function guard($action)
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to change these settings.', 'nexoflow'));
        }

        check_admin_referer($action, 'nexoflow_nonce');
    }

    /**
     * Keeps the outcome of an action for the next page load. `$where` names the
     * card it is shown in: connection, status or agent.
     */
    public function store_notice($type, $message, $where = '')
    {
        set_transient(
            'nexoflow_admin_notice',
            array(
                'type' => $type,
                'message' => $message,
                'where' => sanitize_key($where),
            ),
            60
        );
    }

    /** Back to the settings page, at the message the action left in its card. */
    public function redirect($notice, $where = '')
    {
        wp_safe_redirect($this->return_url($notice, $where));
        exit;
    }

    /** Where an action sends the browser afterwards: this page, scrolled to the message in its card. */
    public function return_url($notice, $where = '')
    {
        $url = add_query_arg(
            array(
                'page' => self::PAGE,
                'nexoflow_notice' => sanitize_key($notice),
            ),
            admin_url('admin.php')
        );

        return sanitize_key($where) !== '' ? $url . '#nexoflow-notice' : $url;
    }

    private function format_sync_time($timestamp)
    {
        if ($timestamp <= 0) {
            return __('Never', 'nexoflow');
        }

        if (function_exists('wp_date')) {
            $format = get_option('date_format') . ' ' . get_option('time_format');

            return wp_date($format, $timestamp);
        }

        return (string) $timestamp;
    }
}
