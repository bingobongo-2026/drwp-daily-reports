<?php
if (!defined('ABSPATH')) exit;

class DRWP_Notifications_Admin {
    public static function init() {
        add_action('admin_post_drwp_save_notifications', [__CLASS__, 'save']);
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) wp_die('forbidden');
        $settings = DRWP_Notifications::settings();
        include DRWP_PATH . 'admin/views/notifications-page.php';
    }

    public static function save() {
        if (!current_user_can('manage_options')) wp_die('forbidden');
        check_admin_referer('drwp_save_notifications');
        DRWP_Notifications::save_settings([
            'enabled'    => isset($_POST['enabled']),
            'on_pending' => isset($_POST['on_pending']),
            'on_review'  => isset($_POST['on_review']),
            'on_comment' => isset($_POST['on_comment']),
            'from_email' => wp_unslash($_POST['from_email'] ?? ''),
        ]);
        wp_safe_redirect(admin_url('admin.php?page=drwp_notifications&saved=1'));
        exit;
    }
}
