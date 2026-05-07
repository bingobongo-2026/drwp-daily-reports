<?php
if (!defined('ABSPATH')) exit;

class DRWP_License_Admin {
    public static function init() {
        add_action('admin_post_drwp_save_license', [__CLASS__, 'save']);
        add_action('admin_post_drwp_check_license', [__CLASS__, 'check']);
        add_action('admin_post_drwp_fetch_public_key', [__CLASS__, 'fetch_public_key']);
        add_action('admin_post_drwp_rotate_license_key', [__CLASS__, 'rotate']);
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        $license = DRWP_License::state();
        include DRWP_PATH . 'admin/views/license-page.php';
    }

    public static function save() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        check_admin_referer('drwp_save_license');

        // Only update the token when the field is present in the POST body.
        // The license-page renders a placeholder ('•••') when a token is
        // already stored or supplied via constant — we don't want that
        // placeholder to overwrite the real value. The view sends an
        // explicit empty `admin_token_set=1` flag to mean "clear it".
        $admin_token = null;
        if (DRWP_License::admin_token_source() !== 'constant' && isset($_POST['admin_token_present'])) {
            $admin_token = (string) wp_unslash($_POST['admin_token'] ?? '');
        }

        DRWP_License::save_settings(
            wp_unslash($_POST['api_url'] ?? ''),
            wp_unslash($_POST['license_key'] ?? ''),
            $admin_token
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

    public static function rotate() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('forbidden', 'drwp-daily-reports'));
        check_admin_referer('drwp_rotate_license_key');
        $result = DRWP_License::rotate_key();
        if (is_wp_error($result)) {
            // Surface the error message via the existing 'message' option
            // so the license page renders it next to the "error" notice.
            update_option(DRWP_License::OPT_LAST_MESSAGE, $result->get_error_message());
            wp_safe_redirect(admin_url('admin.php?page=drwp_license&error=1'));
            exit;
        }
        DRWP_Audit::log('license_key_rotated', 'ライセンス署名鍵をローテートしました', null, [
            'public_key_prefix' => substr((string) $result, 0, 16),
        ]);
        wp_safe_redirect(admin_url('admin.php?page=drwp_license&rotated=1'));
        exit;
    }
}
