<?php
/**
 * Plugin Name: NexoFlow
 * Description: Publish NexoFlow articles to this WordPress site.
 * Version: 1.0.1
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

define('NEXOFLOW_VERSION', '1.0.1');
define('NEXOFLOW_FILE', __FILE__);
define('NEXOFLOW_DIR', plugin_dir_path(__FILE__));
define('NEXOFLOW_URL', plugin_dir_url(__FILE__));
define('NEXOFLOW_DEFAULT_API_BASE', 'https://nexoflow.net');

require_once NEXOFLOW_DIR . 'includes/class-client.php';
require_once NEXOFLOW_DIR . 'includes/class-publisher.php';
require_once NEXOFLOW_DIR . 'includes/class-cron.php';
require_once NEXOFLOW_DIR . 'includes/class-rest.php';
require_once NEXOFLOW_DIR . 'includes/class-settings.php';
require_once NEXOFLOW_DIR . 'includes/class-plugin.php';

function nexoflow()
{
    return NexoFlow_Plugin::instance();
}

nexoflow();
