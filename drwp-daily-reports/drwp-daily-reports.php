<?php
/**
 * Plugin Name: 日報マン
 * Plugin URI: https://nippoman.example.com/
 * Description: 現場日報のレビュー・写真添付・公開記事化を一体化したライセンス制プラグイン。ライセンスサーバと連動して書込・記事化を有効化します。
 * Version: 1.54.0
 * Author: 日報マン
 * Author URI: https://nippoman.example.com/
 * Text Domain: drwp-daily-reports
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Copyright (c) 2026 日報マン
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2,
 * as published by the Free Software Foundation. See LICENSE.
 *
 * Internal slugs / class prefixes (DRWP_*, drwp_*, drwp-daily-reports
 * text domain, REST namespace drwp/v1, DB prefix drwp_*) are kept as
 * stable identifiers across the 日報マン rename to avoid breaking
 * options / DB rows / saved settings on existing installs.
 */

if (!defined('ABSPATH')) exit;

define('DRWP_VERSION', '1.54.0');
define('DRWP_PATH', plugin_dir_path(__FILE__));
define('DRWP_URL', plugin_dir_url(__FILE__));

require_once DRWP_PATH . 'includes/class-drwp-labels.php';
require_once DRWP_PATH . 'includes/class-drwp-db.php';
require_once DRWP_PATH . 'includes/class-drwp-license.php';
require_once DRWP_PATH . 'includes/class-drwp-license-admin.php';
require_once DRWP_PATH . 'includes/class-drwp-customer.php';
require_once DRWP_PATH . 'includes/class-drwp-customer-group.php';
require_once DRWP_PATH . 'includes/class-drwp-project.php';
require_once DRWP_PATH . 'includes/class-drwp-project-group.php';
require_once DRWP_PATH . 'includes/class-drwp-groups-admin.php';
require_once DRWP_PATH . 'includes/class-drwp-plan.php';
require_once DRWP_PATH . 'includes/class-drwp-user.php';
require_once DRWP_PATH . 'includes/class-drwp-audit.php';
require_once DRWP_PATH . 'includes/class-drwp-audit-admin.php';
require_once DRWP_PATH . 'includes/class-drwp-comment.php';
require_once DRWP_PATH . 'includes/class-drwp-reports.php';
require_once DRWP_PATH . 'includes/class-drwp-review.php';require_once DRWP_PATH . 'includes/class-drwp-media.php';
require_once DRWP_PATH . 'includes/class-drwp-customer-media.php';
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
require_once DRWP_PATH . 'includes/class-drwp-page-template.php';
require_once DRWP_PATH . 'includes/class-drwp-login.php';
require_once DRWP_PATH . 'includes/class-drwp-print.php';
require_once DRWP_PATH . 'includes/class-drwp-ai-backend.php';
require_once DRWP_PATH . 'includes/class-drwp-ai-backend-openai.php';
require_once DRWP_PATH . 'includes/class-drwp-ai-backend-anthropic.php';
require_once DRWP_PATH . 'includes/class-drwp-ai-backend-managed.php';
require_once DRWP_PATH . 'includes/class-drwp-ai.php';
require_once DRWP_PATH . 'includes/class-drwp-ai-admin.php';
require_once DRWP_PATH . 'includes/class-drwp-help.php';
require_once DRWP_PATH . 'includes/class-drwp-seed.php';

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
    DRWP_Customer_Group::init();
    DRWP_Project::init();
    DRWP_Project_Group::init();
    DRWP_Plan::init();
    DRWP_User::init();
    DRWP_Review::init();
    DRWP_Audit::init();
    DRWP_Audit_Admin::init();
    DRWP_Dashboard::init();
    DRWP_REST::init();
    DRWP_Notifications::init();
    DRWP_Notifications_Admin::init();
    DRWP_Output_Admin::init();
    DRWP_Report_Form::init();
    DRWP_Report_Archive::init();
    DRWP_Page_Template::init();
    DRWP_Login::init();
    DRWP_AI_Admin::init();
    DRWP_Help::init();
    DRWP_Seed::init();

    add_action(DRWP_License::CRON_HOOK, ['DRWP_License', 'check_now']);
    DRWP_License::schedule_cron();
});
