<?php

if (!defined('ABSPATH')) {
    exit;
}

class NexoFlow_Settings
{
    const PAGE = 'nexoflow';

    const MAX_KEY_LENGTH = 53;

    public function register()
    {
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_enqueue_scripts', array($this, 'menu_assets'));
        add_action('admin_enqueue_scripts', array($this, 'assets'));
        add_action('admin_post_nexoflow_save_settings', array($this, 'save'));
        add_action('admin_post_nexoflow_test_connection', array($this, 'test_connection'));
        add_action('admin_post_nexoflow_sync_now', array($this, 'sync_now'));
        add_action('admin_notices', array($this, 'notices'));
    }

    public function menu()
    {
        add_menu_page(
            __('NexoFlow', 'nexoflow'),
            __('NexoFlow', 'nexoflow'),
            'manage_options',
            self::PAGE,
            array($this, 'render'),
            NEXOFLOW_URL . 'assets/nexoflow-menu-icon.png',
            58
        );
    }

    public function menu_assets()
    {
        wp_enqueue_style(
            'nexoflow-admin-menu',
            NEXOFLOW_URL . 'assets/admin-menu.css',
            array(),
            NEXOFLOW_VERSION
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
            NEXOFLOW_VERSION
        );

        wp_enqueue_script(
            'nexoflow-admin',
            NEXOFLOW_URL . 'assets/admin.js',
            array(),
            NEXOFLOW_VERSION,
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
        $save_url = admin_url('admin-post.php');
        ?>
        <div class="wrap nexoflow-wrap">
            <h1><?php echo esc_html__('NexoFlow', 'nexoflow'); ?></h1>
            <p class="nexoflow-lead"><?php echo esc_html__('Publish NexoFlow articles to this WordPress site.', 'nexoflow'); ?></p>

            <div class="nexoflow-card">
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
                        </span>
                    </p>
                    <?php if ($has_key && $suffix !== '') : ?>
                        <p class="nexoflow-key-hint"><?php
                            /* translators: %s: last four characters of the saved site connect key */
                            echo esc_html(sprintf(__('Saved key ending in %s', 'nexoflow'), $suffix));
                        ?></p>
                    <?php endif; ?>
                </form>
            </div>

            <div class="nexoflow-card">
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
                    <div>
                        <dt><?php echo esc_html__('Last error', 'nexoflow'); ?></dt>
                        <dd><?php echo $last_error !== '' ? esc_html($last_error) : esc_html__('None', 'nexoflow'); ?></dd>
                    </div>
                </dl>
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

            <p class="nexoflow-help"><?php echo esc_html__('Create a WordPress project in NexoFlow, copy the site connect key, paste it here.', 'nexoflow'); ?></p>
        </div>
        <?php
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
                $this->store_notice('error', $message);

                if (!NexoFlow_Client::has_site_key()) {
                    update_option('nexoflow_connected', 0, false);
                    update_option('nexoflow_last_error', $message, false);
                    update_option('nexoflow_project', array(), false);
                }

                $this->redirect('invalid_key');
            }

            update_option('nexoflow_site_key', $raw, false);
        }

        if (!NexoFlow_Client::has_site_key()) {
            $this->store_notice('error', NexoFlow_Client::site_key_error(''));
            $this->redirect('missing_key');
        }

        $this->probe_connection('saved_ok', 'saved_fail');
    }

    public function test_connection()
    {
        $this->guard('nexoflow_test_connection');
        $this->probe_connection('tested_ok', 'tested_fail');
    }

    public function sync_now()
    {
        $this->guard('nexoflow_sync_now');

        $cron = new NexoFlow_Cron();
        $result = $cron->sync();

        if (is_wp_error($result)) {
            $this->store_notice('error', $result->get_error_message());
            $this->redirect('sync_fail');
        }

        $this->store_notice('success', __('Sync finished.', 'nexoflow'));
        $this->redirect('synced');
    }

    public function notices()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if (!$screen || $screen->id !== 'toplevel_page_nexoflow') {
            return;
        }

        $notice = get_transient('nexoflow_admin_notice');
        delete_transient('nexoflow_admin_notice');

        if (!is_array($notice) || !isset($notice['message']) || !is_string($notice['message'])) {
            return;
        }

        $type = isset($notice['type']) && $notice['type'] === 'error' ? 'error' : 'success';
        $class = $type === 'error' ? 'notice-error' : 'notice-success';

        echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($notice['message']) . '</p></div>';
    }

    private function probe_connection($ok_notice, $fail_notice)
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
            $this->store_notice('error', $message);
            $this->redirect($fail_notice);
        }

        update_option('nexoflow_connected', 1, false);
        update_option('nexoflow_last_error', '', false);
        update_option('nexoflow_project', NexoFlow_Client::project_from_health($result), false);
        $this->store_notice('success', __('Connected', 'nexoflow'));
        $this->redirect($ok_notice);
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

    private function guard($action)
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to change these settings.', 'nexoflow'));
        }

        check_admin_referer($action, 'nexoflow_nonce');
    }

    private function store_notice($type, $message)
    {
        set_transient(
            'nexoflow_admin_notice',
            array(
                'type' => $type,
                'message' => $message,
            ),
            60
        );
    }

    private function redirect($notice)
    {
        wp_safe_redirect(
            add_query_arg(
                array(
                    'page' => self::PAGE,
                    'nexoflow_notice' => sanitize_key($notice),
                ),
                admin_url('admin.php')
            )
        );
        exit;
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
