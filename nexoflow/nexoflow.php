<?php
/**
 * Plugin Name: NexoFlow
 * Description: Connects this WordPress site to its NexoFlow SEO team: articles, pages, search listings, link previews, structured data, redirects and speed.
 * Version: 2.0.0
 * Author: fl0rentg
 * Author URI: https://nexoflow.net
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: nexoflow
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('NEXOFLOW_VERSION', '2.0.0');
define('NEXOFLOW_FILE', __FILE__);
define('NEXOFLOW_DIR', plugin_dir_path(__FILE__));
define('NEXOFLOW_URL', plugin_dir_url(__FILE__));
define('NEXOFLOW_DEFAULT_API_BASE', 'https://nexoflow.net');

require_once NEXOFLOW_DIR . 'includes/class-client.php';
require_once NEXOFLOW_DIR . 'includes/class-auth.php';
require_once NEXOFLOW_DIR . 'includes/class-publisher.php';
require_once NEXOFLOW_DIR . 'includes/class-cron.php';
require_once NEXOFLOW_DIR . 'includes/class-site-data.php';
require_once NEXOFLOW_DIR . 'includes/class-indexnow.php';
require_once NEXOFLOW_DIR . 'includes/class-bing.php';
require_once NEXOFLOW_DIR . 'includes/class-seo-markup.php';
require_once NEXOFLOW_DIR . 'includes/class-seo.php';
require_once NEXOFLOW_DIR . 'includes/class-page-cache.php';
require_once NEXOFLOW_DIR . 'includes/class-rest.php';
require_once NEXOFLOW_DIR . 'includes/class-settings.php';
require_once NEXOFLOW_DIR . 'includes/agent/class-agent-error.php';
require_once NEXOFLOW_DIR . 'includes/agent/interface-agent-provider.php';
require_once NEXOFLOW_DIR . 'includes/agent/interface-agent-builder.php';
require_once NEXOFLOW_DIR . 'includes/agent/class-agent-actor.php';
require_once NEXOFLOW_DIR . 'includes/agent/class-agent-site.php';
require_once NEXOFLOW_DIR . 'includes/agent/class-agent-validator.php';
require_once NEXOFLOW_DIR . 'includes/agent/class-agent-request-store.php';
require_once NEXOFLOW_DIR . 'includes/agent/class-agent-documents.php';
require_once NEXOFLOW_DIR . 'includes/agent/class-agent-templates.php';
require_once NEXOFLOW_DIR . 'includes/agent/class-agent-abilities.php';
require_once NEXOFLOW_DIR . 'includes/agent/class-agent-capabilities.php';
require_once NEXOFLOW_DIR . 'includes/agent/class-agent-actions.php';
require_once NEXOFLOW_DIR . 'includes/agent/class-agent-dispatcher.php';
require_once NEXOFLOW_DIR . 'includes/agent/providers/class-provider-wordpress.php';
require_once NEXOFLOW_DIR . 'includes/agent/providers/class-provider-gutenberg.php';
require_once NEXOFLOW_DIR . 'includes/agent/providers/class-provider-media.php';
require_once NEXOFLOW_DIR . 'includes/agent/providers/class-provider-elementor.php';
require_once NEXOFLOW_DIR . 'includes/agent/providers/class-provider-seo.php';
require_once NEXOFLOW_DIR . 'includes/agent/class-agent-rest.php';
require_once NEXOFLOW_DIR . 'includes/agent/class-agent-admin.php';
require_once NEXOFLOW_DIR . 'includes/agent/class-agent.php';
require_once NEXOFLOW_DIR . 'includes/class-plugin.php';

function nexoflow()
{
    return NexoFlow_Plugin::instance();
}

nexoflow();
