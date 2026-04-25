<?php
if (!defined('ABSPATH')) exit;

class DRWP_License_Admin {
    public static function init() {
        add_action('admin_post_drwp_save_license', [__CLASS__, 'save']);
        add_action('admin_post_drwp_check_license', [__CLASS__, 'check']);
        add_action('admin_post_drwp_fetch_public_key', [__CLASS__, 'fetch_public_key']);
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        $license = DRWP_License::state();
        include DRWP_PATH . 'admin/views/license-page.php';
    }

    public static function save() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        check_admin_referer('drwp_save_license');
        DRWP_License::save_settings(
            wp_unslash($_POST['api_url'] ?? ''),
            wp_unslash($_POST['license_key'] ?? '')
        );
        wp_safe_redirect(admin_url('admin.php?page=drwp_license&saved=1'));
        exit;
    }

    public static function check() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        check_admin_referer('drwp_check_license');
        $result = DRWP_License::check_now();
        $flag = is_wp_error($result) ? 'error' : 'checked';
        wp_safe_redirect(admin_url('admin.php?page=drwp_license&' . $flag . '=1'));
        exit;
    }

    public static function fetch_public_key() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        check_admin_referer('drwp_fetch_public_key');
        $result = DRWP_License::fetch_public_key();
        $flag = is_wp_error($result) ? 'error' : 'key_fetched';
        wp_safe_redirect(admin_url('admin.php?page=drwp_license&' . $flag . '=1'));
        exit;
    }
}
