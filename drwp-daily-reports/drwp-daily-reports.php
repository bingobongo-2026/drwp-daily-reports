<?php
/**
 * Plugin Name: DRWP Daily Reports
 * Description: Daily reports, review workflow, and conversion to WordPress posts with license checks.
 * Version: 1.12.0
 * Author: DRWP Prototype
 * Text Domain: drwp-daily-reports
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;

define('DRWP_VERSION', '1.18.0');
define('DRWP_PATH', plugin_dir_path(__FILE__));
define('DRWP_URL', plugin_dir_url(__FILE__));

require_once DRWP_PATH . 'includes/class-drwp-labels.php';
require_once DRWP_PATH . 'includes/class-drwp-db.php';
require_once DRWP_PATH . 'includes/class-drwp-license.php';
require_once DRWP_PATH . 'includes/class-drwp-license-admin.php';
require_once DRWP_PATH . 'includes/class-drwp-customer.php';
require_once DRWP_PATH . 'includes/class-drwp-project.php';
require_once DRWP_PATH . 'includes/class-drwp-audit.php';
require_once DRWP_PATH . 'includes/class-drwp-audit-admin.php';
require_once DRWP_PATH . 'includes/class-drwp-comment.php';
require_once DRWP_PATH . 'includes/class-drwp-review.php';require_once DRWP_PATH . 'includes/class-drwp-media.php';
require_once DRWP_PATH . 'includes/class-drwp-dashboard.php';
require_once DRWP_PATH . 'includes/class-drwp-rest.php';
require_once DRWP_PATH . 'includes/class-drwp-notifications.php';
require_once DRWP_PATH . 'includes/class-drwp-notifications-admin.php';
require_once DRWP_PATH . 'includes/class-drwp-cpt.php';
require_once DRWP_PATH . 'includes/class-drwp-output.php';
require_once DRWP_PATH . 'includes/class-drwp-output-admin.php';
require_once DRWP_PATH . 'includes/class-drwp-post-converter.php';
require_once DRWP_PATH . 'includes/class-drwp-admin.php';
require_once DRWP_PATH . 'includes/class-drwp-report-form.php';
require_once DRWP_PATH . 'includes/class-drwp-report-archive.php';
require_once DRWP_PATH . 'includes/class-drwp-login.php';
require_once DRWP_PATH . 'includes/class-drwp-print.php';
require_once DRWP_PATH . 'includes/class-drwp-ai-backend.php';
require_once DRWP_PATH . 'includes/class-drwp-ai-backend-ollama.php';
require_once DRWP_PATH . 'includes/class-drwp-ai-backend-openai.php';
require_once DRWP_PATH . 'includes/class-drwp-ai-backend-anthropic.php';
require_once DRWP_PATH . 'includes/class-drwp-ai.php';
require_once DRWP_PATH . 'includes/class-drwp-ai-admin.php';

if (defined('WP_CLI') && WP_CLI) {
    require_once DRWP_PATH . 'includes/class-drwp-cli.php';
}

register_activation_hook(__FILE__, ['DRWP_DB', 'activate']);
register_activation_hook(__FILE__, ['DRWP_License', 'schedule_cron']);
register_deactivation_hook(__FILE__, ['DRWP_License', 'clear_cron']);

add_action('plugins_loaded', function () {
    load_plugin_textdomain('drwp-daily-reports', false, dirname(plugin_basename(__FILE__)) . '/languages');
    DRWP_DB::maybe_upgrade();
    DRWP_CPT::init();
    DRWP_Admin::init();
    DRWP_License_Admin::init();
    DRWP_Customer::init();
    DRWP_Project::init();
    DRWP_Review::init();
    DRWP_Audit_Admin::init();
    DRWP_Dashboard::init();
    DRWP_REST::init();
    DRWP_Notifications::init();
    DRWP_Notifications_Admin::init();
    DRWP_Output_Admin::init();
    DRWP_Report_Form::init();
    DRWP_Report_Archive::init();
    DRWP_Login::init();
    DRWP_AI_Admin::init();

    add_action(DRWP_License::CRON_HOOK, ['DRWP_License', 'check_now']);
    DRWP_License::schedule_cron();
});
