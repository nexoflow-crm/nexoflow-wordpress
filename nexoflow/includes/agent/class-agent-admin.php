<?php

if (!defined('ABSPATH')) {
    exit;
}

class NexoFlow_Agent_Admin
{
    /** How many of the latest requests the activity log shows. */
    const RECENT = 25;

    /** Plain words for each action, as a site owner would say it. */
    const ACTION_LABELS = array(
        'site.read' => 'Read the site',
        'pages.list' => 'Listed the pages',
        'pages.get' => 'Looked at a page',
        'pages.read' => 'Read a page',
        'pages.create' => 'Created a draft page',
        'pages.update' => 'Changed a page',
        'pages.restore' => 'Put a page back',
        'blocks.read' => 'Read the sections of a page',
        'blocks.insert' => 'Added sections to a page',
        'blocks.replace' => 'Changed sections of a page',
        'blocks.update' => 'Changed sections of a page',
        'templates.read' => 'Read the homepage, header or footer',
        'templates.insert' => 'Added sections to the homepage',
        'templates.replace' => 'Changed the homepage, header or footer',
        'templates.update' => 'Changed the homepage, header or footer',
        'templates.restore' => 'Put the homepage, header or footer back',
        'media.upload' => 'Added an image',
        'media.missing_alt' => 'Looked for images with no description',
        'media.set_alt' => 'Described an image',
        'seo.status' => 'Read how the site stands for search',
        'seo.pages' => 'Read the search listings of the pages',
        'seo.get_page' => 'Read a page\'s search listing',
        'seo.update_page' => 'Changed a page\'s search listing',
        'seo.update_site' => 'Changed the site\'s search settings',
        'content.trash' => 'Moved a page to the Trash',
        'content.restore' => 'Brought a page back from the Trash',
        'redirects.list' => 'Read the redirects',
        'redirects.set' => 'Added a redirect',
        'redirects.remove' => 'Removed a redirect',
        'speed.set_cache' => 'Changed the page cache',
        'elementor.inspect' => 'Read a page built with Elementor',
    );

    private $agent;

    private $settings;

    public function __construct($agent, $settings)
    {
        $this->agent = $agent;
        $this->settings = $settings;
    }

    public function register()
    {
        add_action('nexoflow_settings_cards', array($this, 'render'));
        add_action('nexoflow_settings_side', array($this, 'render_activity'));
        add_action('admin_post_nexoflow_agent_toggle', array($this, 'toggle'));
        add_action('admin_post_nexoflow_agent_use_account', array($this, 'use_account'));
    }

    public function toggle()
    {
        $this->settings->guard('nexoflow_agent_toggle');

        if (NexoFlow_Agent::switched_on()) {
            update_option(NexoFlow_Agent::ENABLED_OPTION, '0', false);
            $this->settings->store_notice('success', __('Website Agent turned off. NexoFlow can still publish articles.', 'nexoflow'), 'agent');
            $this->settings->redirect('agent_off', 'agent');
        }

        update_option(NexoFlow_Agent::ENABLED_OPTION, '1', false);
        $this->settings->store_notice('success', __('Website Agent turned on.', 'nexoflow'), 'agent');
        $this->settings->redirect('agent_on', 'agent');
    }

    public function use_account()
    {
        $this->settings->guard('nexoflow_agent_use_account');

        $this->agent->actor()->set(get_current_user_id());
        $this->settings->store_notice('success', __('NexoFlow now works on this site as your account.', 'nexoflow'), 'agent');
        $this->settings->redirect('agent_account', 'agent');
    }

    public function render()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $on = NexoFlow_Agent::enabled();
        $actor_id = $this->agent->actor()->id();
        $builders = $this->agent->builders();
        $gutenberg = isset($builders['gutenberg']) ? $builders['gutenberg'] : array();
        $elementor = isset($builders['elementor']) ? $builders['elementor'] : array();
        $capabilities = $this->agent->capabilities()->all();
        $post_url = admin_url('admin-post.php');
        ?>
        <div class="nexoflow-card" id="nexoflow-card-agent">
            <h2 class="nexoflow-card-title">
                <span class="dashicons dashicons-admin-tools" aria-hidden="true"></span>
                <?php echo esc_html__('Website Agent', 'nexoflow'); ?>
            </h2>
            <p class="nexoflow-card-lead"><?php echo esc_html__('What NexoFlow can do on this site. New pages are saved as drafts. A change to a published page always keeps the previous version, so it can be put back.', 'nexoflow'); ?></p>
            <?php $this->elementor_setup($elementor); ?>
            <dl class="nexoflow-status">
                <div>
                    <dt><?php echo esc_html__('Website Agent', 'nexoflow'); ?></dt>
                    <dd><?php $this->pill($on, __('On', 'nexoflow'), __('Off', 'nexoflow')); ?></dd>
                </div>
                <div>
                    <dt><?php echo esc_html__('Works as', 'nexoflow'); ?></dt>
                    <dd><?php echo esc_html($this->account_label($actor_id)); ?></dd>
                </div>
                <div>
                    <dt><?php echo esc_html__('WordPress', 'nexoflow'); ?></dt>
                    <dd><?php
                        /* translators: 1: WordPress version, 2: PHP version */
                        echo esc_html(sprintf(__('%1$s, PHP %2$s', 'nexoflow'), get_bloginfo('version'), PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION));
                    ?></dd>
                </div>
                <div>
                    <dt><?php echo esc_html__('Block editor', 'nexoflow'); ?></dt>
                    <dd><?php $this->state(!empty($gutenberg['available']), $gutenberg); ?></dd>
                </div>
                <div>
                    <dt><?php echo esc_html__('Elementor', 'nexoflow'); ?></dt>
                    <dd><?php $this->state(!empty($elementor['active']), $elementor, !empty($elementor['version']) ? $elementor['version'] : ''); ?></dd>
                </div>
                <div>
                    <dt><?php echo esc_html__('Elementor MCP', 'nexoflow'); ?></dt>
                    <dd><?php
                        $this->pill(
                            !empty($elementor['mcp']['available']),
                            __('Available', 'nexoflow'),
                            __('Unavailable', 'nexoflow')
                        );
                    ?></dd>
                </div>
            </dl>

            <h3 class="nexoflow-card-subtitle"><?php echo esc_html__('Capabilities', 'nexoflow'); ?></h3>
            <ul class="nexoflow-capabilities">
                <?php foreach ($capabilities as $name => $capability) : ?>
                    <li class="<?php echo $capability['usable'] ? 'is-yes' : 'is-no'; ?>">
                        <code><?php echo esc_html($name); ?></code>
                        <?php if (!$capability['usable'] && $capability['message'] !== '') : ?>
                            <span><?php echo esc_html($capability['message']); ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php
            if ($this->settings) {
                $this->settings->inline_notice('agent');
            }
            ?>
            <div class="nexoflow-actions">
                <form method="post" action="<?php echo esc_url($post_url); ?>">
                    <?php wp_nonce_field('nexoflow_agent_toggle', 'nexoflow_nonce'); ?>
                    <input type="hidden" name="action" value="nexoflow_agent_toggle" />
                    <button type="submit" class="button button-secondary">
                        <?php echo NexoFlow_Agent::switched_on() ? esc_html__('Turn off Website Agent', 'nexoflow') : esc_html__('Turn on Website Agent', 'nexoflow'); ?>
                    </button>
                </form>
                <?php if ($actor_id !== (int) get_current_user_id()) : ?>
                    <form method="post" action="<?php echo esc_url($post_url); ?>">
                        <?php wp_nonce_field('nexoflow_agent_use_account', 'nexoflow_nonce'); ?>
                        <input type="hidden" name="action" value="nexoflow_agent_use_account" />
                        <button type="submit" class="button button-secondary">
                            <?php echo esc_html__('Work as my account', 'nexoflow'); ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * The one thing left to do before NexoFlow can work with Elementor, with a
     * button that goes straight there. Nothing is shown on a site without
     * Elementor.
     */
    private function elementor_setup($elementor)
    {
        if (empty($elementor['installed'])) {
            return;
        }

        $reason = isset($elementor['reason']) ? (string) $elementor['reason'] : '';
        $ready = !empty($elementor['available']);
        $title = __('Elementor is ready', 'nexoflow');
        $text = __('NexoFlow can build pages with Elementor on this site and read the ones built with it. A new page is a draft, and a change to a published page waits until it is published.', 'nexoflow');
        $steps = array();
        $button = '';
        $url = '';
        $switch = '';

        if (!$ready) {
            $title = __('One step left for Elementor', 'nexoflow');
            $text = isset($elementor['message']) ? (string) $elementor['message'] : '';

            if ($reason === 'ELEMENTOR_MCP_DISABLED' || $reason === 'ELEMENTOR_MCP_UNAVAILABLE') {
                $text = __('Elementor MCP is switched off. NexoFlow needs it on to work with pages built with Elementor.', 'nexoflow');
                $url = $this->elementor_mcp_url();
                $switch = $this->elementor_mcp_switch();
                $button = __('Open Elementor MCP settings', 'nexoflow');
                // With Elementor's own switch within reach it is one press; otherwise the way there is spelled out.
                $steps = $switch !== '' ? array() : array(
                    $url !== ''
                        ? __('Press the button below. It opens the Elementor MCP page of this site.', 'nexoflow')
                        : __('In the WordPress menu, open Elementor, then Elementor MCP.', 'nexoflow'),
                    __('Switch Elementor MCP on and save.', 'nexoflow'),
                    __('Come back to this page. This box turns green by itself.', 'nexoflow'),
                );
            } elseif ($reason === 'ELEMENTOR_INACTIVE') {
                $url = current_user_can('activate_plugins') ? admin_url('plugins.php') : '';
                $button = __('Open Plugins', 'nexoflow');
            } elseif ($reason === 'ELEMENTOR_VERSION_UNSUPPORTED' || $reason === 'ABILITIES_API_UNAVAILABLE') {
                $url = current_user_can('update_plugins') ? admin_url('update-core.php') : '';
                $button = __('Open Updates', 'nexoflow');
            }
        }
        ?>
        <div class="nexoflow-setup <?php echo $ready ? 'is-ready' : 'is-todo'; ?>" id="nexoflow-elementor">
            <h3 class="nexoflow-setup-title">
                <span class="dashicons <?php echo $ready ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>" aria-hidden="true"></span>
                <?php echo esc_html($title); ?>
            </h3>
            <?php if ($text !== '') : ?>
                <p><?php echo esc_html($text); ?></p>
            <?php endif; ?>
            <?php if (!empty($steps)) : ?>
                <ol>
                    <?php foreach ($steps as $step) : ?>
                        <li><?php echo esc_html($step); ?></li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
            <?php if (!$ready) : ?>
                <p class="nexoflow-setup-actions">
                    <?php if ($switch !== '') : ?>
                        <button
                            type="button"
                            class="button button-primary"
                            data-nexoflow-elementor-mcp="<?php echo esc_url($switch); ?>"
                            data-nonce="<?php echo esc_attr(wp_create_nonce('wp_rest')); ?>"
                            data-working="<?php echo esc_attr__('Turning it on', 'nexoflow'); ?>"
                            data-failed="<?php echo esc_attr__('Elementor did not switch it on. Open its settings and switch it on there.', 'nexoflow'); ?>"
                        ><?php echo esc_html__('Turn on Elementor MCP', 'nexoflow'); ?></button>
                    <?php endif; ?>
                    <?php if ($url !== '') : ?>
                        <a class="button <?php echo $switch !== '' ? 'button-secondary' : 'button-primary'; ?>" href="<?php echo esc_url($url); ?>"><?php echo esc_html($button); ?></a>
                    <?php endif; ?>
                    <a class="button button-secondary" href="<?php echo esc_url(admin_url('admin.php?page=nexoflow') . '#nexoflow-elementor'); ?>"><?php echo esc_html__('Check again', 'nexoflow'); ?></a>
                </p>
                <p class="nexoflow-setup-problem" data-nexoflow-elementor-problem hidden></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * The address of Elementor's own switch for its MCP, the one its settings page
     * presses. The button here sends the administrator's own request to it, signed
     * in as they are, so Elementor decides and Elementor saves: nothing of
     * Elementor's is written by this plugin. Empty when Elementor has no such
     * switch or this account may not use it.
     */
    private function elementor_mcp_switch()
    {
        if (!current_user_can('manage_options') || !function_exists('rest_get_server') || !function_exists('rest_url')) {
            return '';
        }

        $server = rest_get_server();

        foreach (is_object($server) && method_exists($server, 'get_routes') ? array_keys((array) $server->get_routes()) : array() as $route) {
            if (is_string($route) && preg_match('#^/elementor-mcp-composer/[A-Za-z0-9.\-]+/mcp-settings$#D', $route)) {
                return rest_url(ltrim($route, '/'));
            }
        }

        return '';
    }

    /**
     * The address of Elementor's own MCP settings page on this site, found in
     * the admin menu rather than assumed, so it stays right when Elementor moves
     * or renames it. Empty when the menu has no such page.
     */
    private function elementor_mcp_url()
    {
        $menus = isset($GLOBALS['submenu']) && is_array($GLOBALS['submenu']) ? $GLOBALS['submenu'] : array();
        $fallback = '';

        foreach ($menus as $parent => $items) {
            $under_elementor = stripos((string) $parent, 'elementor') !== false;

            foreach (is_array($items) ? $items : array() as $item) {
                $title = isset($item[0]) ? wp_strip_all_tags((string) $item[0]) : '';
                $capability = isset($item[1]) ? (string) $item[1] : '';
                $slug = isset($item[2]) ? (string) $item[2] : '';
                $about_mcp = stripos($slug, 'mcp') !== false || stripos($title, 'mcp') !== false;
                $about_elementor = $under_elementor || stripos($slug, 'elementor') !== false || stripos($title, 'elementor') !== false;

                if ($slug === '' || !$about_mcp || !$about_elementor || ($capability !== '' && !current_user_can($capability))) {
                    continue;
                }

                $url = $this->menu_url($slug);

                if ($url === '') {
                    continue;
                }

                // A page that names both is the one; a page under Elementor that only says MCP is the next best.
                if (stripos($slug . ' ' . $title, 'elementor') !== false) {
                    return $url;
                }

                $fallback = $fallback === '' ? $url : $fallback;
            }
        }

        return $fallback;
    }

    private function menu_url($slug)
    {
        if (preg_match('#^https?://#i', $slug)) {
            // Only ever a page of this site's own admin.
            return strpos($slug, admin_url()) === 0 ? $slug : '';
        }

        if (strpos($slug, '.php') !== false) {
            return admin_url($slug);
        }

        return admin_url('admin.php?page=' . rawurlencode($slug));
    }

    /**
     * The right-hand column: what NexoFlow did on this site, newest first, with
     * what went wrong said in plain words, and a report to copy for support.
     */
    public function render_activity()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $rows = $this->agent->store()->recent(self::RECENT);
        $problems = 0;

        foreach ($rows as $row) {
            $problems += $this->row_state($row) === 'is-bad' ? 1 : 0;
        }

        $last_sync = (int) get_option('nexoflow_last_sync', 0);
        $last_error = (string) get_option('nexoflow_last_error', '');
        ?>
        <div class="nexoflow-card nexoflow-activity" id="nexoflow-card-activity">
            <h2 class="nexoflow-card-title">
                <span class="dashicons dashicons-list-view" aria-hidden="true"></span>
                <?php echo esc_html__('Activity', 'nexoflow'); ?>
            </h2>
            <p class="nexoflow-card-lead"><?php echo esc_html__('What NexoFlow did on this site, newest first. Kept for 30 days.', 'nexoflow'); ?></p>

            <ul class="nexoflow-log" data-nexoflow-log>
                <li class="<?php echo $last_error !== '' ? 'is-bad' : 'is-ok'; ?>">
                    <span class="nexoflow-log-dot" aria-hidden="true"></span>
                    <div class="nexoflow-log-body">
                        <div class="nexoflow-log-title"><strong><?php echo esc_html__('Article sync', 'nexoflow'); ?></strong></div>
                        <div class="nexoflow-log-meta"><?php
                            echo esc_html($last_sync > 0
                                /* translators: %s: how long ago, for example "5 mins" */
                                ? sprintf(__('Last ran %s ago', 'nexoflow'), human_time_diff($last_sync, time()))
                                : __('Has not run yet', 'nexoflow'));
                        ?></div>
                        <?php if ($last_error !== '') : ?>
                            <div class="nexoflow-log-problem"><?php echo esc_html($last_error); ?></div>
                        <?php endif; ?>
                    </div>
                </li>
                <?php foreach ($rows as $row) : ?>
                    <?php
                    $state = $this->row_state($row);
                    $when = strtotime((string) $row['created_at'] . ' UTC');
                    $took = $this->duration($row);
                    ?>
                    <li class="<?php echo esc_attr($state); ?>">
                        <span class="nexoflow-log-dot" aria-hidden="true"></span>
                        <div class="nexoflow-log-body">
                            <div class="nexoflow-log-title">
                                <strong><?php echo esc_html($this->action_label($row['action'])); ?></strong>
                                <?php echo wp_kses($this->target_html($row), array('a' => array('href' => true), 'span' => array('class' => true))); ?>
                            </div>
                            <div class="nexoflow-log-meta" title="<?php echo esc_attr($this->format_time($row['created_at'])); ?>">
                                <?php
                                echo esc_html(implode(' · ', array_filter(array(
                                    $this->outcome_label($row),
                                    /* translators: %s: how long ago, for example "5 mins" */
                                    $when !== false ? sprintf(__('%s ago', 'nexoflow'), human_time_diff($when, time())) : '',
                                    $took,
                                ))));
                                ?>
                                <code><?php echo esc_html($row['action']); ?></code>
                            </div>
                            <?php if ($state === 'is-bad') : ?>
                                <div class="nexoflow-log-problem"><?php echo esc_html($this->reason_text($row['error_code'])); ?></div>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if (empty($rows)) : ?>
                <p class="nexoflow-muted"><?php echo esc_html__('Nothing from the Website Agent yet. What NexoFlow reads and changes on this site shows here.', 'nexoflow'); ?></p>
            <?php endif; ?>

            <div class="nexoflow-activity-footer">
                <?php if (!empty($rows)) : ?>
                    <label class="nexoflow-log-filter">
                        <input type="checkbox" data-nexoflow-problems />
                        <?php
                        /* translators: %d: number of requests that did not go through */
                        echo esc_html(sprintf(__('Only show problems (%d)', 'nexoflow'), $problems));
                        ?>
                    </label>
                <?php endif; ?>
                <button type="button" class="button button-secondary" data-nexoflow-copy="nexoflow-report" data-copied="<?php echo esc_attr__('Copied', 'nexoflow'); ?>">
                    <?php echo esc_html__('Copy report for support', 'nexoflow'); ?>
                </button>
            </div>
            <p class="nexoflow-muted nexoflow-report-note"><?php echo esc_html__('The report has versions, settings and the list above. It never has your site connect key.', 'nexoflow'); ?></p>
            <textarea id="nexoflow-report" class="nexoflow-report" readonly rows="6" aria-label="<?php echo esc_attr__('Report for support', 'nexoflow'); ?>"><?php echo esc_textarea($this->support_report($rows)); ?></textarea>
        </div>
        <?php
    }

    /** Everything support needs to see what happened on this site, as plain text. No key, no names. */
    public function support_report($rows)
    {
        $builders = $this->agent->builders();
        $elementor = isset($builders['elementor']) ? $builders['elementor'] : array();
        $gutenberg = isset($builders['gutenberg']) ? $builders['gutenberg'] : array();
        $theme = function_exists('wp_get_theme') ? wp_get_theme() : null;
        $block_theme = function_exists('wp_is_block_theme') && wp_is_block_theme();
        $last_sync = (int) get_option('nexoflow_last_sync', 0);
        $last_error = (string) get_option('nexoflow_last_error', '');
        $lines = array(
            'NexoFlow report, ' . gmdate('Y-m-d H:i') . ' UTC',
            'Site: ' . home_url(),
            'Plugin ' . NEXOFLOW_VERSION . ', WordPress ' . get_bloginfo('version') . ', PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'Theme: ' . (is_object($theme) ? $theme->get('Name') . ' ' . $theme->get('Version') : 'unknown') . ($block_theme ? ' (block theme)' : ' (classic theme)'),
            'Connected: ' . ((int) get_option('nexoflow_connected', 0) === 1 ? 'yes' : 'no')
                . ', last sync: ' . ($last_sync > 0 ? gmdate('Y-m-d H:i', $last_sync) . ' UTC' : 'never')
                . ', last error: ' . ($last_error !== '' ? $last_error : 'none'),
            'Website Agent: ' . (NexoFlow_Agent::enabled() ? 'on' : 'off') . ', acting account: ' . ($this->agent->actor()->id() > 0 ? 'set' : 'not set'),
            'Block editor: ' . (!empty($gutenberg['available']) ? 'yes' : 'no'),
            'Elementor: ' . (empty($elementor['installed'])
                ? 'not installed'
                : (!empty($elementor['version']) ? $elementor['version'] : 'unknown version') . (!empty($elementor['active']) ? ', active' : ', not active'))
                . ', Elementor MCP: ' . (!empty($elementor['mcp']['available']) ? 'on' : 'off')
                . (!empty($elementor['reason']) ? ' (' . $elementor['reason'] . ')' : ''),
        );
        $missing = array();

        foreach ($this->agent->capabilities()->all() as $name => $capability) {
            if (empty($capability['usable'])) {
                $missing[] = $name . (!empty($capability['reason']) ? ' (' . $capability['reason'] . ')' : '');
            }
        }

        $lines[] = 'Not available: ' . (empty($missing) ? 'nothing' : implode(', ', $missing));
        $lines[] = '';
        $lines[] = 'Latest requests (UTC):';

        foreach ($rows as $row) {
            $lines[] = implode('  ', array_filter(array(
                (string) $row['created_at'],
                (string) $row['action'],
                (string) $row['status'],
                (string) $row['error_code'],
                (int) $row['object_id'] > 0 ? 'object ' . (int) $row['object_id'] : '',
                $this->duration($row),
                (string) $row['request_id'],
            ), 'strlen'));
        }

        if (empty($rows)) {
            $lines[] = 'none';
        }

        return implode("\n", $lines);
    }

    private function row_state($row)
    {
        if ($row['status'] === NexoFlow_Agent_Request_Store::COMPLETED) {
            return 'is-ok';
        }

        return $row['status'] === NexoFlow_Agent_Request_Store::IN_PROGRESS ? 'is-run' : 'is-bad';
    }

    private function action_label($action)
    {
        $labels = self::ACTION_LABELS;

        // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- The labels are the fixed list above.
        return isset($labels[$action]) ? __($labels[$action], 'nexoflow') : (string) $action;
    }

    /** What went wrong, for the person who owns the site. */
    private function reason_text($code)
    {
        switch ((string) $code) {
            case NexoFlow_Agent_Error::CONTENT_CONFLICT:
                return __('The page had changed since NexoFlow last read it, so nothing was changed. NexoFlow reads it again and retries.', 'nexoflow');
            case NexoFlow_Agent_Error::CONTENT_LOCKED:
                return __('Someone was editing that page in WordPress, so it was left alone.', 'nexoflow');
            case NexoFlow_Agent_Error::DRAFT_REQUIRED:
                return __('The page is published and the request did not ask for a live change, so it was left alone.', 'nexoflow');
            case NexoFlow_Agent_Error::INVALID_PARAMETERS:
                return __('The request was not written correctly, so it was refused. Nothing on the site changed.', 'nexoflow');
            case NexoFlow_Agent_Error::INSUFFICIENT_PERMISSION:
            case NexoFlow_Agent_Error::NO_ACTING_USER:
                return __('The account NexoFlow works as is not allowed to do this. Press "Work as my account" on the left.', 'nexoflow');
            case NexoFlow_Agent_Error::CAPABILITY_UNAVAILABLE:
            case NexoFlow_Agent_Error::UNSUPPORTED_BUILDER_OPERATION:
                return __('This site cannot do that yet. See what is missing under Capabilities on the left.', 'nexoflow');
            case NexoFlow_Agent_Error::AGENT_DISABLED:
                return __('The Website Agent is turned off on this site.', 'nexoflow');
            case NexoFlow_Agent_Error::NOT_FOUND:
                return __('That page or version is not on this site any more.', 'nexoflow');
            case NexoFlow_Agent_Error::RESTORE_POINT_UNAVAILABLE:
                return __('WordPress could not keep the previous version, so the change was not made.', 'nexoflow');
            case NexoFlow_Agent_Error::PROVIDER_ERROR:
                return __('Elementor could not complete this.', 'nexoflow');
            case NexoFlow_Agent_Error::STORAGE_UNAVAILABLE:
                return __('The database of this site did not answer.', 'nexoflow');
        }

        return __('It did not go through. Nothing on the site changed.', 'nexoflow');
    }

    private function target_html($row)
    {
        $id = isset($row['object_id']) ? (int) $row['object_id'] : 0;

        if ($id <= 0 || !function_exists('get_post') || !get_post($id)) {
            return '';
        }

        $title = get_the_title($id);
        $title = is_string($title) && $title !== '' ? $title : '#' . $id;
        $link = function_exists('get_edit_post_link') ? get_edit_post_link($id, 'raw') : '';

        return is_string($link) && $link !== ''
            ? '<a href="' . esc_url($link) . '">' . esc_html($title) . '</a>'
            : '<span class="nexoflow-log-target">' . esc_html($title) . '</span>';
    }

    private function duration($row)
    {
        $start = strtotime((string) $row['created_at'] . ' UTC');
        $end = !empty($row['completed_at']) ? strtotime((string) $row['completed_at'] . ' UTC') : false;

        if ($start === false || $end === false || $end < $start) {
            return '';
        }

        $seconds = $end - $start;

        /* translators: %d: number of seconds */
        return $seconds < 1 ? __('under 1s', 'nexoflow') : sprintf(__('%ds', 'nexoflow'), $seconds);
    }

    private function pill($yes, $yes_label, $no_label)
    {
        echo '<span class="nexoflow-pill ' . ($yes ? 'is-yes' : 'is-no') . '">' . esc_html($yes ? $yes_label : $no_label) . '</span>';
    }

    private function state($yes, $detected, $detail = '')
    {
        $this->pill($yes, __('Yes', 'nexoflow'), __('No', 'nexoflow'));

        $note = $detail;

        if (!empty($detected['message'])) {
            $note = $note === '' ? $detected['message'] : $note . '. ' . $detected['message'];
        }

        if ($note !== '') {
            echo ' <span class="nexoflow-muted">' . esc_html($note) . '</span>';
        }
    }

    private function account_label($user_id)
    {
        $user = $user_id > 0 ? get_userdata($user_id) : false;

        if (!$user) {
            return NexoFlow_Client::has_site_key()
                ? __('No administrator account found', 'nexoflow')
                : __('Set when the site is connected', 'nexoflow');
        }

        $role = isset($user->roles) && is_array($user->roles) && !empty($user->roles) ? reset($user->roles) : '';
        $names = wp_roles()->get_names();
        $role_name = $role !== '' && isset($names[$role]) ? translate_user_role($names[$role]) : '';

        if ($role_name === '') {
            return (string) $user->display_name;
        }

        /* translators: 1: user display name, 2: user role */
        return sprintf(__('%1$s (%2$s)', 'nexoflow'), $user->display_name, $role_name);
    }

    private function outcome_label($row)
    {
        if ($row['status'] === NexoFlow_Agent_Request_Store::COMPLETED) {
            return __('Done', 'nexoflow');
        }

        if ($row['status'] === NexoFlow_Agent_Request_Store::IN_PROGRESS) {
            return __('Running', 'nexoflow');
        }

        if ($row['error_code'] !== '') {
            /* translators: %s: reason code */
            return sprintf(__('Not done: %s', 'nexoflow'), $row['error_code']);
        }

        return __('Not done', 'nexoflow');
    }

    private function format_time($mysql_gmt)
    {
        $timestamp = strtotime((string) $mysql_gmt . ' UTC');

        if ($timestamp === false) {
            return '';
        }

        if (function_exists('wp_date')) {
            return (string) wp_date(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
        }

        return gmdate('Y-m-d H:i', $timestamp);
    }
}
