<?php
if (!defined('ABSPATH')) exit;

class DRWP_Admin_Menu {
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
    }

    public static function menu() {
        add_menu_page('日報管理', '日報管理', 'read', 'drwp-reports', [__CLASS__, 'reports_list'], 'dashicons-media-spreadsheet', 25);
        add_submenu_page('drwp-reports', '日報一覧', '日報一覧', 'read', 'drwp-reports', [__CLASS__, 'reports_list']);
        add_submenu_page('drwp-reports', '日報作成', '日報作成', 'read', 'drwp-report-edit', [__CLASS__, 'report_edit']);
        add_submenu_page('drwp-reports', '現場一覧', '現場一覧', 'manage_options', 'drwp-projects', [__CLASS__, 'projects_list']);
        add_submenu_page('drwp-reports', 'ライセンス', 'ライセンス', 'manage_options', 'drwp-license', [__CLASS__, 'license_page']);
    }

    public static function reports_list() { include DRWP_PLUGIN_DIR . 'admin/views/reports-list.php'; }
    public static function report_edit() { include DRWP_PLUGIN_DIR . 'admin/views/report-edit.php'; }
    public static function projects_list() { include DRWP_PLUGIN_DIR . 'admin/views/projects-list.php'; }
    public static function license_page() { include DRWP_PLUGIN_DIR . 'admin/views/license-page.php'; }
}
