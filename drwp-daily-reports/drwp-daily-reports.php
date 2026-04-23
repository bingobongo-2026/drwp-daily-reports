<?php
/**
 * Plugin Name: DRWP Daily Reports
 * Description: Daily reports, review workflow, and conversion to WordPress posts with license checks.
 * Version: 1.8.0
 * Author: OpenAI Prototype
 */

if (!defined('ABSPATH')) exit;

define('DRWP_VERSION', '1.8.0');
define('DRWP_PATH', plugin_dir_path(__FILE__));
define('DRWP_URL', plugin_dir_url(__FILE__));

require_once DRWP_PATH . 'includes/class-drwp-db.php';
require_once DRWP_PATH . 'includes/class-drwp-license.php';
require_once DRWP_PATH . 'includes/class-drwp-post-converter.php';
require_once DRWP_PATH . 'includes/class-drwp-admin.php';

register_activation_hook(__FILE__, ['DRWP_DB', 'activate']);

add_action('plugins_loaded', function () {
    DRWP_Admin::init();
});
