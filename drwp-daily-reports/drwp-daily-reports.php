<?php
/**
 * Plugin Name: DRWP Daily Reports
 * Description: Daily reports, review workflow, and conversion to WordPress posts with license checks.
 * Version: 1.8.0
 * Author: DRWP Prototype
 * Text Domain: drwp-daily-reports
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;

define('DRWP_VERSION', '1.8.0');
define('DRWP_PATH', plugin_dir_path(__FILE__));
define('DRWP_URL', plugin_dir_url(__FILE__));

require_once DRWP_PATH . 'includes/class-drwp-db.php';
require_once DRWP_PATH . 'includes/class-drwp-license.php';
require_once DRWP_PATH . 'includes/class-drwp-license-admin.php';
require_once DRWP_PATH . 'includes/class-drwp-project.php';
require_once DRWP_PATH . 'includes/class-drwp-audit.php';
require_once DRWP_PATH . 'includes/class-drwp-audit-admin.php';
require_once DRWP_PATH . 'includes/class-drwp-comment.php';
require_once DRWP_PATH . 'includes/class-drwp-review.php';
require_once DRWP_PATH . 'includes/class-drwp-media.php';
require_once DRWP_PATH . 'includes/class-drwp-dashboard.php';
require_once DRWP_PATH . 'includes/class-drwp-csv-import.php';
require_once DRWP_PATH . 'includes/class-drwp-rest.php';
require_once DRWP_PATH . 'includes/class-drwp-notifications.php';
require_once DRWP_PATH . 'includes/class-drwp-notifications-admin.php';
require_once DRWP_PATH . 'includes/class-drwp-cpt.php';
require_once DRWP_PATH . 'includes/class-drwp-output.php';
require_once DRWP_PATH . 'includes/class-drwp-output-admin.php';
require_once DRWP_PATH . 'includes/class-drwp-post-converter.php';
require_once DRWP_PATH . 'includes/class-drwp-admin.php';

if (defined('WP_CLI') && WP_CLI) {
    require_once DRWP_PATH . 'includes/class-drwp-cli.php';
}

register_activation_hook(__FILE__, ['DRWP_DB', 'activate']);

add_action('plugins_loaded', function () {
    load_plugin_textdomain('drwp-daily-reports', false, dirname(plugin_basename(__FILE__)) . '/languages');
    DRWP_DB::maybe_upgrade();
    DRWP_CPT::init();
    DRWP_Admin::init();
    DRWP_License_Admin::init();
    DRWP_Project::init();
    DRWP_Review::init();
    DRWP_Audit_Admin::init();
    DRWP_Dashboard::init();
    DRWP_CSV_Import::init();
    DRWP_REST::init();
    DRWP_Notifications::init();
    DRWP_Notifications_Admin::init();
    DRWP_Output_Admin::init();
});
